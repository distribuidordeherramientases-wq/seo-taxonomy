<?php

defined('ABSPATH') || exit;

/**
 * Interprete 3.0.
 * Lee directamente la memoria linguistica y semantica existente, pero no
 * reutiliza ninguna funcion del runtime historico.
 */
final class SEO_Dependiente_V3_Interpreter {
    private static $stopwords = array(
        'a','al','algo','algun','alguna','algunas','algunos','ante','bajo','cada','como','con','contra','cual','cuando',
        'de','del','desde','donde','e','el','ella','ellas','ellos','en','entre','era','es','esa','esas','ese','eso','esos',
        'esta','estas','este','esto','estos','ha','hacia','hasta','hay','la','las','le','les','lo','los','me','mi','mis',
        'muy','nos','o','para','pero','por','porque','que','se','sin','sobre','su','sus','te','tu','tus','un','una','unas',
        'uno','unos','y','ya','yo','quiero','queremos','necesito','necesitamos','busco','buscar','dame','mostrar','muestra',
    );

    public static function interpret($raw_query) {
        $raw_query = sanitize_text_field((string) $raw_query);
        $normalized = SEO_Dependiente_V3_DB::normalize($raw_query);
        $ngrams = SEO_Dependiente_V3_DB::ngrams($normalized, 5);

        $result = array(
            'raw'             => $raw_query,
            'normalized'      => $normalized,
            'filtered'        => '',
            'groups'          => array(),
            'concepts'        => array(),
            'actions'         => array(),
            'vocabulary_ids'  => array(),
            'related_search'  => array(),
            'routes'          => array(),
            'ignored'         => array(),
            'semantic_hits'   => array(),
            'lexicon_hits'    => array(),
            'vocabulary_hits' => array(),
        );

        if ('' === $normalized) {
            return $result;
        }

        $consumed = array();
        self::apply_semantic_memory($result, $ngrams, $consumed);
        self::apply_lexicon_memory($result, $ngrams, $consumed);
        self::apply_vocabulary_memory($result, $ngrams);

        $words = array_values(array_filter(explode(' ', $normalized)));
        foreach ($words as $word) {
            if (isset($consumed[$word]) || self::is_stopword($word)) {
                if (self::is_stopword($word)) {
                    $result['ignored'][] = $word;
                }
                continue;
            }
            if (strlen($word) < 2 && !ctype_digit($word)) {
                continue;
            }
            self::add_group($result, $word, SEO_Dependiente_V3_DB::token_variants($word), 'term', 0, 'literal');
        }

        $result['ignored'] = array_values(array_unique(array_filter($result['ignored'])));
        $result['actions'] = array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_V3_DB', 'normalize'), $result['actions']))));
        $result['related_search'] = array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_V3_DB', 'normalize'), $result['related_search']))));
        $result['vocabulary_ids'] = array_values(array_unique(array_filter(array_map('absint', $result['vocabulary_ids']))));

        foreach ($result['concepts'] as $role => $values) {
            $result['concepts'][$role] = array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_V3_DB', 'normalize'), (array) $values))));
        }

        $result['groups'] = self::consolidate_groups($result['groups']);
        $result['groups'] = array_values($result['groups']);
        foreach ($result['groups'] as &$group) {
            $group['variants'] = array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_V3_DB', 'normalize'), (array) $group['variants']))));
        }
        unset($group);

        $filtered_parts = array();
        foreach ($result['groups'] as $group) {
            if (!empty($group['canonical'])) {
                $filtered_parts[] = (string) $group['canonical'];
            }
        }
        $result['filtered'] = trim(implode(' ', array_values(array_unique($filtered_parts))));
        if ('' === $result['filtered']) {
            $result['filtered'] = implode(' ', array_values(array_filter($words, static function ($word) {
                return !self::is_stopword($word);
            })));
        }

        $result['routes'] = self::resolve_routes($result);
        return $result;
    }

    private static function apply_semantic_memory(&$result, $ngrams, &$consumed) {
        global $wpdb;
        if (!$ngrams || !SEO_Dependiente_V3_DB::exists('seo_dependiente_semantics')) {
            return;
        }
        $table = SEO_Dependiente_V3_DB::table('seo_dependiente_semantics');
        $in = SEO_Dependiente_V3_DB::placeholders(count($ngrams));
        $sql = "SELECT id,rule_type,normalized_expression,canonical_expression,match_type,semantic_role,
                       source_vocabulary_id,source_group,source_slug,context_vocabulary_id,context_group,context_slug,
                       target_vocabulary_id,target_group,target_slug,relation_type,result_role,weight,priority,confidence,source
                  FROM {$table}
                 WHERE active=1 AND language='es' AND rule_type<>'route' AND normalized_expression IN ({$in})
                 ORDER BY LENGTH(normalized_expression) DESC, priority ASC, weight DESC, id ASC
                 LIMIT 250";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $ngrams), ARRAY_A);

        foreach ($rows as $row) {
            $expr = SEO_Dependiente_V3_DB::normalize($row['normalized_expression'] ?? '');
            if ('' === $expr || !self::contains_phrase($result['normalized'], $expr)) {
                continue;
            }
            $type = sanitize_key((string) ($row['rule_type'] ?? ''));
            $role = sanitize_key((string) ($row['semantic_role'] ?? 'term')) ?: 'term';
            $canonical = SEO_Dependiente_V3_DB::normalize($row['canonical_expression'] ?? $expr);
            if ('' === $canonical) {
                $canonical = $expr;
            }

            $result['semantic_hits'][] = array(
                'id' => absint($row['id'] ?? 0), 'expression' => $expr, 'canonical' => $canonical,
                'rule_type' => $type, 'role' => $role, 'source' => (string) ($row['source'] ?? ''),
            );

            if (in_array($type, array('ignore','operator'), true)) {
                foreach (explode(' ', $expr) as $word) {
                    $consumed[$word] = true;
                    $result['ignored'][] = $word;
                }
                continue;
            }

            if (in_array($role, array('intent','action','estado','state'), true)) {
                $result['actions'][] = $canonical;
                $result['concepts']['intent'][] = $canonical;
                foreach (explode(' ', $expr) as $word) {
                    $consumed[$word] = true;
                }
                continue;
            }

            $result['concepts'][$role][] = $canonical;
            $variants = array_merge(SEO_Dependiente_V3_DB::token_variants($expr), SEO_Dependiente_V3_DB::token_variants($canonical));
            self::add_group($result, $canonical, $variants, $role, absint($row['source_vocabulary_id'] ?? 0), 'semantic');
            foreach (explode(' ', $expr) as $word) {
                $consumed[$word] = true;
            }
            if (!empty($row['source_vocabulary_id'])) {
                $result['vocabulary_ids'][] = absint($row['source_vocabulary_id']);
            }
        }
    }

    private static function apply_lexicon_memory(&$result, $ngrams, &$consumed) {
        global $wpdb;
        if (!$ngrams || !SEO_Dependiente_V3_DB::exists('seo_interprete_lexicon')) {
            return;
        }
        $table = SEO_Dependiente_V3_DB::table('seo_interprete_lexicon');
        $in = SEO_Dependiente_V3_DB::placeholders(count($ngrams));
        $sql = "SELECT id,expression,normalized_expression,canonical_term,target_search,relation_type,semantic_group,
                       vocabulary_id,context_terms,context_required,confidence,priority,source,lesson_key,evidence_count,validated
                  FROM {$table}
                 WHERE active=1 AND language='es' AND normalized_expression IN ({$in})
                 ORDER BY LENGTH(normalized_expression) DESC, validated DESC, context_required DESC,
                          confidence DESC, evidence_count DESC, priority ASC, id ASC
                 LIMIT 300";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $ngrams), ARRAY_A);
        if (!$rows) {
            return;
        }

        $by_expression = array();
        foreach ($rows as $row) {
            $expr = SEO_Dependiente_V3_DB::normalize($row['normalized_expression'] ?? '');
            if ('' === $expr || !self::contains_phrase($result['normalized'], $expr)) {
                continue;
            }
            $context = SEO_Dependiente_V3_DB::json_array($row['context_terms'] ?? '');
            $context_hits = 0;
            foreach ($context as $term) {
                if (self::contains_phrase($result['normalized'], SEO_Dependiente_V3_DB::normalize($term))) {
                    $context_hits++;
                }
            }
            if (!empty($row['context_required']) && 0 === $context_hits) {
                continue;
            }
            $row['_context_hits'] = $context_hits;
            $by_expression[$expr][] = $row;
        }

        foreach ($by_expression as $expr => $matches) {
            $selected = self::select_lexicon_rows($matches);
            if (!$selected) {
                continue;
            }

            $group_variants = SEO_Dependiente_V3_DB::token_variants($expr);
            $group_role = 'term';
            $group_vocab_ids = array();
            $handled_as_action = false;
            $handled_as_noise = false;

            foreach ($selected as $row) {
                $relation = sanitize_key((string) ($row['relation_type'] ?? 'synonym'));
                $canonical = SEO_Dependiente_V3_DB::normalize($row['canonical_term'] ?? $expr);
                $target = SEO_Dependiente_V3_DB::normalize($row['target_search'] ?? $canonical);
                $semantic_group = sanitize_key((string) ($row['semantic_group'] ?? ''));

                $result['lexicon_hits'][] = array(
                    'id' => absint($row['id'] ?? 0), 'expression' => $expr, 'canonical' => $canonical,
                    'target' => $target, 'relation' => $relation, 'confidence' => (float) ($row['confidence'] ?? 0),
                    'validated' => !empty($row['validated']), 'context_hits' => absint($row['_context_hits'] ?? 0),
                    'source' => (string) ($row['source'] ?? ''),
                );

                if (in_array($relation, array('verb_to_tool','phrase_to_tool'), true)) {
                    $handled_as_action = true;
                    $result['actions'][] = $expr;
                    $result['concepts']['intent'][] = $expr;
                    if ($target) {
                        $result['related_search'][] = $target;
                    }
                    continue;
                }

                if (in_array($relation, array('stopword','ignore','noise'), true)) {
                    $handled_as_noise = true;
                    continue;
                }

                if ($semantic_group) {
                    $group_role = $semantic_group;
                }
                $group_variants = array_merge(
                    $group_variants,
                    SEO_Dependiente_V3_DB::token_variants($canonical),
                    SEO_Dependiente_V3_DB::token_variants($target)
                );
                if (!empty($row['vocabulary_id'])) {
                    $group_vocab_ids[] = absint($row['vocabulary_id']);
                    $result['vocabulary_ids'][] = absint($row['vocabulary_id']);
                }
            }

            foreach (explode(' ', $expr) as $word) {
                $consumed[$word] = true;
                if ($handled_as_noise) {
                    $result['ignored'][] = $word;
                }
            }

            if (!$handled_as_action && !$handled_as_noise) {
                // Una expresión del cliente es un solo concepto. Sus sinónimos y
                // variantes amplían ese concepto; nunca crean requisitos extra.
                $result['concepts'][$group_role][] = $expr;
                self::add_group($result, $expr, $group_variants, $group_role, 0, 'lexicon');
                if ($group_vocab_ids) {
                    $key = SEO_Dependiente_V3_DB::normalize($expr);
                    if (isset($result['groups'][$key])) {
                        $result['groups'][$key]['vocabulary_ids'] = array_values(array_unique(array_merge(
                            (array) $result['groups'][$key]['vocabulary_ids'],
                            $group_vocab_ids
                        )));
                    }
                }
            }
        }
    }

    private static function select_lexicon_rows($matches) {
        if (count($matches) <= 1) {
            return $matches;
        }
        $contextual = array_values(array_filter($matches, static function ($row) {
            return absint($row['_context_hits'] ?? 0) > 0;
        }));
        if ($contextual) {
            usort($contextual, array(__CLASS__, 'compare_lexicon_rows'));
            return array_slice($contextual, 0, 2);
        }

        $safe = array_values(array_filter($matches, static function ($row) {
            $relation = sanitize_key((string) ($row['relation_type'] ?? ''));
            return !in_array($relation, array('verb_to_tool','phrase_to_tool'), true);
        }));
        if ($safe) {
            usort($safe, array(__CLASS__, 'compare_lexicon_rows'));
            return array_slice($safe, 0, 3);
        }

        usort($matches, array(__CLASS__, 'compare_lexicon_rows'));
        $first = $matches[0];
        $second = $matches[1] ?? null;
        $margin = $second ? ((float) ($first['confidence'] ?? 0) - (float) ($second['confidence'] ?? 0)) : 1.0;
        if (!empty($first['validated']) && ($margin >= 0.08 || absint($first['evidence_count'] ?? 0) > absint($second['evidence_count'] ?? 0) * 2)) {
            return array($first);
        }
        return array();
    }

    private static function compare_lexicon_rows($a, $b) {
        $cmp = absint($b['_context_hits'] ?? 0) <=> absint($a['_context_hits'] ?? 0);
        if (0 !== $cmp) return $cmp;
        $cmp = absint($b['validated'] ?? 0) <=> absint($a['validated'] ?? 0);
        if (0 !== $cmp) return $cmp;
        $cmp = (float) ($b['confidence'] ?? 0) <=> (float) ($a['confidence'] ?? 0);
        if (0 !== $cmp) return $cmp;
        $cmp = absint($b['evidence_count'] ?? 0) <=> absint($a['evidence_count'] ?? 0);
        if (0 !== $cmp) return $cmp;
        return absint($a['priority'] ?? 255) <=> absint($b['priority'] ?? 255);
    }

    private static function apply_vocabulary_memory(&$result, $ngrams) {
        global $wpdb;
        if (!$ngrams || !SEO_Dependiente_V3_DB::exists('seo_vocabulary')) {
            return;
        }
        $slug_candidates = array();
        foreach ($ngrams as $phrase) {
            $slug_candidates[] = str_replace('-', '_', sanitize_title($phrase));
            $slug_candidates[] = sanitize_title($phrase);
        }
        $slug_candidates = array_values(array_unique(array_filter($slug_candidates)));
        if (!$slug_candidates) {
            return;
        }
        $table = SEO_Dependiente_V3_DB::table('seo_vocabulary');
        $in = SEO_Dependiente_V3_DB::placeholders(count($slug_candidates));
        $sql = "SELECT id,semantic_group,slug,label,parent_id,source
                  FROM {$table}
                 WHERE active=1 AND slug IN ({$in})
                 ORDER BY semantic_group ASC, id ASC LIMIT 150";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $slug_candidates), ARRAY_A);
        foreach ($rows as $row) {
            $role = sanitize_key((string) ($row['semantic_group'] ?? '')) ?: 'term';
            $label = SEO_Dependiente_V3_DB::normalize($row['label'] ?? '');
            $slug = SEO_Dependiente_V3_DB::normalize(str_replace(array('_','-'), ' ', (string) ($row['slug'] ?? '')));
            $result['vocabulary_ids'][] = absint($row['id'] ?? 0);
            if ($label) {
                $result['concepts'][$role][] = $label;
            }
            $result['vocabulary_hits'][] = array(
                'id' => absint($row['id'] ?? 0), 'group' => $role, 'slug' => (string) ($row['slug'] ?? ''),
                'label' => (string) ($row['label'] ?? ''), 'source' => (string) ($row['source'] ?? ''),
            );
            self::merge_variants_into_matching_groups($result, array_filter(array($label, $slug)), absint($row['id'] ?? 0), $role);
        }
    }

    private static function resolve_routes($result) {
        global $wpdb;
        if (empty($result['actions']) || !SEO_Dependiente_V3_DB::exists('seo_dependiente_semantics')) {
            return array();
        }
        $table = SEO_Dependiente_V3_DB::table('seo_dependiente_semantics');
        $rows = (array) $wpdb->get_results(
            "SELECT id,source_vocabulary_id,source_group,source_slug,context_vocabulary_id,context_group,context_slug,
                    target_vocabulary_id,target_group,target_slug,relation_type,result_role,weight,priority,confidence,source
               FROM {$table}
              WHERE active=1 AND language='es' AND rule_type='route'
              ORDER BY priority ASC, weight DESC, id ASC
              LIMIT 1500",
            ARRAY_A
        );
        if (!$rows) {
            return array();
        }

        $concepts = array();
        foreach ((array) $result['concepts'] as $role => $values) {
            foreach ((array) $values as $value) {
                $concepts[sanitize_key($role)][SEO_Dependiente_V3_DB::normalize($value)] = true;
            }
        }
        foreach ((array) $result['groups'] as $group) {
            foreach ((array) $group['variants'] as $value) {
                $concepts['term'][SEO_Dependiente_V3_DB::normalize($value)] = true;
            }
        }

        $routes = array();
        foreach ($rows as $row) {
            $source_group = sanitize_key((string) ($row['source_group'] ?? ''));
            $source_slug = SEO_Dependiente_V3_DB::normalize(str_replace(array('_','-'), ' ', (string) ($row['source_slug'] ?? '')));
            $context_group = sanitize_key((string) ($row['context_group'] ?? ''));
            $context_slug = SEO_Dependiente_V3_DB::normalize(str_replace(array('_','-'), ' ', (string) ($row['context_slug'] ?? '')));

            if ($source_slug && !self::concept_exists($concepts, $source_group, $source_slug)) {
                continue;
            }
            if ($context_slug && !self::concept_exists($concepts, $context_group, $context_slug)) {
                continue;
            }

            $target_slug = SEO_Dependiente_V3_DB::normalize(str_replace(array('_','-'), ' ', (string) ($row['target_slug'] ?? '')));
            $target_id = absint($row['target_vocabulary_id'] ?? 0);
            if (!$target_id && !$target_slug) {
                continue;
            }
            $routes[] = array(
                'id' => absint($row['id'] ?? 0),
                'target_vocabulary_id' => $target_id,
                'target_group' => sanitize_key((string) ($row['target_group'] ?? '')),
                'target_slug' => $target_slug,
                'result_role' => sanitize_key((string) ($row['result_role'] ?? '')),
                'relation_type' => sanitize_key((string) ($row['relation_type'] ?? '')),
                'weight' => max(0, (int) ($row['weight'] ?? 0)),
                'priority' => absint($row['priority'] ?? 0),
                'source' => (string) ($row['source'] ?? ''),
            );
        }
        return array_slice($routes, 0, 40);
    }

    private static function concept_exists($concepts, $group, $slug) {
        $group = sanitize_key($group);
        if ($group && !empty($concepts[$group][$slug])) {
            return true;
        }
        if (!empty($concepts['term'][$slug])) {
            return true;
        }
        foreach ($concepts as $values) {
            if (!empty($values[$slug])) {
                return true;
            }
        }
        return false;
    }

    private static function merge_variants_into_matching_groups(&$result, $variants, $vocabulary_id, $role) {
        $variants = array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_V3_DB', 'normalize'), (array) $variants))));
        foreach ($result['groups'] as &$group) {
            $overlap = false;
            foreach ($variants as $variant) {
                foreach ((array) $group['variants'] as $existing) {
                    if ($variant === $existing || SEO_Dependiente_V3_DB::contains_term($variant, $existing) || SEO_Dependiente_V3_DB::contains_term($existing, $variant)) {
                        $overlap = true;
                        break 2;
                    }
                }
            }
            if ($overlap) {
                $group['variants'] = array_values(array_unique(array_merge((array) $group['variants'], $variants)));
                if ($vocabulary_id) {
                    $group['vocabulary_ids'][] = $vocabulary_id;
                    $group['vocabulary_ids'] = array_values(array_unique(array_filter(array_map('absint', $group['vocabulary_ids']))));
                }
                if ('term' === $group['role'] && $role) {
                    $group['role'] = $role;
                }
            }
        }
        unset($group);
    }

    private static function add_group(&$result, $canonical, $variants, $role, $vocabulary_id, $source) {
        $canonical = SEO_Dependiente_V3_DB::normalize($canonical);
        if ('' === $canonical) {
            return;
        }
        $variants = array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_V3_DB', 'normalize'), (array) $variants))));
        $key = $canonical;
        if (isset($result['groups'][$key])) {
            $result['groups'][$key]['variants'] = array_values(array_unique(array_merge($result['groups'][$key]['variants'], $variants)));
            if ($vocabulary_id) {
                $result['groups'][$key]['vocabulary_ids'][] = absint($vocabulary_id);
            }
            return;
        }
        $result['groups'][$key] = array(
            'canonical' => $canonical,
            'variants' => $variants ?: array($canonical),
            'role' => sanitize_key($role) ?: 'term',
            'vocabulary_ids' => $vocabulary_id ? array(absint($vocabulary_id)) : array(),
            'source' => $source,
        );
    }

    private static function contains_phrase($normalized, $phrase) {
        $normalized = SEO_Dependiente_V3_DB::normalize($normalized);
        $phrase = SEO_Dependiente_V3_DB::normalize($phrase);
        return $phrase && false !== strpos(' ' . $normalized . ' ', ' ' . $phrase . ' ');
    }

    private static function consolidate_groups($groups) {
        $merged = array();
        foreach ((array) $groups as $group) {
            $variants = array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_V3_DB', 'normalize'), (array) ($group['variants'] ?? array())))));
            $target_key = null;
            foreach ($merged as $key => $existing) {
                if (array_intersect($variants, (array) ($existing['variants'] ?? array()))) {
                    $target_key = $key;
                    break;
                }
            }
            if (null === $target_key) {
                $key = SEO_Dependiente_V3_DB::normalize($group['canonical'] ?? '') ?: 'g' . count($merged);
                $group['variants'] = $variants;
                $merged[$key] = $group;
                continue;
            }
            $merged[$target_key]['variants'] = array_values(array_unique(array_merge((array) $merged[$target_key]['variants'], $variants)));
            $merged[$target_key]['vocabulary_ids'] = array_values(array_unique(array_merge(
                array_map('absint', (array) ($merged[$target_key]['vocabulary_ids'] ?? array())),
                array_map('absint', (array) ($group['vocabulary_ids'] ?? array()))
            )));
            if ('term' === ($merged[$target_key]['role'] ?? 'term') && !empty($group['role']) && 'term' !== $group['role']) {
                $merged[$target_key]['role'] = $group['role'];
            }
        }
        return $merged;
    }

    private static function is_stopword($word) {
        return in_array(SEO_Dependiente_V3_DB::normalize($word), self::$stopwords, true);
    }
}
