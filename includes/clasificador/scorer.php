<?php
/**
 * Ranking contextual y fusión de señales del vocabulario semántico.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_longest_concept_run')) {
    function seo_classifier_longest_concept_run(array $term, array $haystack) {
        $best = 0;
        $term_count = count($term);
        $hay_count = count($haystack);
        for ($i = 0; $i < $term_count; $i++) {
            for ($j = 0; $j < $hay_count; $j++) {
                $k = 0;
                while (($i + $k) < $term_count && ($j + $k) < $hay_count && $term[$i + $k] === $haystack[$j + $k]) $k++;
                if ($k > $best) $best = $k;
            }
        }
        return $best;
    }
}

if (!function_exists('seo_classifier_vocab_stats')) {
    function seo_classifier_vocab_stats($group) {
        static $cache = [];
        $group = sanitize_key((string) $group);
        if (isset($cache[$group])) return $cache[$group];

        $rows = (array) (seo_classifier_vocabulary_index()[$group] ?? []);
        $df = [];
        foreach ($rows as $row) {
            $seen = [];
            foreach (array_unique((array) ($row['concepts'] ?? [])) as $token) $seen[$token] = true;
            foreach (array_keys($seen) as $token) $df[$token] = (int) ($df[$token] ?? 0) + 1;
        }
        $n = max(1, count($rows));
        $idf = [];
        foreach ($df as $token => $count) $idf[$token] = log(($n + 1) / ($count + 1)) + 1.0;
        return $cache[$group] = ['n'=>$n, 'idf'=>$idf];
    }
}

if (!function_exists('seo_classifier_label_metric')) {
    function seo_classifier_label_metric(array $term, array $context, $group) {
        $term_seq = (array) ($term['concepts'] ?? seo_classifier_concept_sequence((string) ($term['label'] ?? '')));
        if (!$term_seq) return ['score'=>0.0,'matched'=>0,'title_matched'=>0,'run'=>0,'reasons'=>[]];

        $segments = function_exists('seo_classifier_context_source_segments')
            ? seo_classifier_context_source_segments($context)
            : ['title'=>(string)($context['title']??''),'identity'=>(string)($context['identity']??''),'local'=>(string)($context['full']??'')];
        $segment_weights = [
            'title'=>1.00,
            'identity'=>1.12,
            'local'=>0.48,
            'supplier'=>0.62,
            'external_structured'=>0.66,
            'external_meta'=>0.46,
            'external_body'=>0.22,
        ];
        $external_relevance = max(0.0, min(1.0, (float)($context['external']['relevance'] ?? 0.0)));
        if ($external_relevance > 0.0) {
            $factor = max(0.35, $external_relevance);
            $segment_weights['external_structured'] *= $factor;
            $segment_weights['external_meta'] *= $factor;
            $segment_weights['external_body'] *= $factor;
        }
        $sets = [];
        $sequences = [];
        foreach ($segments as $name=>$text) {
            $sequence = seo_classifier_concept_sequence((string)$text);
            $sequences[$name] = $sequence;
            $sets[$name] = array_fill_keys($sequence, true);
        }
        $category_seq = seo_classifier_concept_sequence((string)($context['categories'] ?? ''));
        $tag_seq = seo_classifier_concept_sequence((string)($context['tags'] ?? ''));
        $brand_seq = seo_classifier_concept_sequence((string)($context['brands'] ?? ''));
        $categories = array_fill_keys($category_seq, true);
        $tags = array_fill_keys($tag_seq, true);
        $brands = array_fill_keys($brand_seq, true);
        $stats = seo_classifier_vocab_stats($group);
        $idf = (array)($stats['idf'] ?? []);

        $denom = 0.0;
        $weighted_match = 0.0;
        $title_weight = 0.0;
        $identity_weight = 0.0;
        $category_weight = 0.0;
        $tag_weight = 0.0;
        $brand_weight = 0.0;
        $matched = 0;
        $title_matched = 0;
        $matched_labels = [];
        $source_hits = [];

        foreach (array_values(array_unique($term_seq)) as $token) {
            $weight = (float)($idf[$token] ?? 1.0);
            $denom += $weight;
            $best_source_weight = 0.0;
            $best_source = '';
            foreach ($segment_weights as $source=>$source_weight) {
                if (isset($sets[$source][$token]) && $source_weight > $best_source_weight) {
                    $best_source_weight = $source_weight;
                    $best_source = $source;
                }
            }
            if ($best_source_weight > 0) {
                $weighted_match += $weight * $best_source_weight;
                $matched++;
                $matched_labels[] = $token;
                $source_hits[$best_source] = (int)($source_hits[$best_source] ?? 0) + 1;
            }
            if (isset($sets['title'][$token])) {
                $title_weight += $weight;
                $title_matched++;
            }
            if (isset($sets['identity'][$token])) $identity_weight += $weight;
            if (isset($categories[$token])) $category_weight += $weight;
            if (isset($tags[$token])) $tag_weight += $weight;
            if (isset($brands[$token])) $brand_weight += $weight;
        }
        if ($matched < 1 || $denom <= 0) return ['score'=>0.0,'matched'=>0,'title_matched'=>0,'run'=>0,'reasons'=>[]];

        $coverage = min(1.0, $weighted_match / $denom);
        $title_coverage = $title_weight / $denom;
        $identity_coverage = $identity_weight / $denom;
        $category_coverage = $category_weight / $denom;
        $tag_coverage = $tag_weight / $denom;
        $brand_coverage = $brand_weight / $denom;
        $run = seo_classifier_longest_concept_run($term_seq, (array)($sequences['title'] ?? []));
        $bonus = 0.0;
        $reasons = [];

        $term_norm = seo_classifier_normalize((string)($term['label'] ?? ''));
        $exact_sources = [
            'title'=>'frase exacta en título',
            'local'=>'frase exacta en ficha local',
            'supplier'=>'frase exacta en catálogo de proveedor',
            'external_structured'=>'frase exacta en datos estructurados externos',
            'external_meta'=>'frase exacta en metadatos externos',
        ];
        foreach ($exact_sources as $source=>$reason) {
            $text = (string)($segments[$source] ?? '');
            if ($term_norm !== '' && strpos(' ' . $text . ' ', ' ' . $term_norm . ' ') !== false) {
                $bonus += $source === 'title' ? 0.16 : ($source === 'external_structured' || $source === 'supplier' ? 0.12 : 0.08);
                $reasons[] = $reason;
                break;
            }
        }
        if ($run >= 3) {
            $bonus += 0.12;
            $reasons[] = 'secuencia de ' . $run . ' conceptos';
        } elseif ($run === 2) {
            $bonus += 0.08;
            $reasons[] = 'secuencia de 2 conceptos';
        }
        if ($title_matched >= 3) $bonus += 0.06;
        elseif ($title_matched === 2) $bonus += 0.035;
        if ($category_coverage >= 0.50) $reasons[] = 'apoyo de categoría';
        if ($tag_coverage >= 0.50) $reasons[] = 'apoyo de product_tag';
        if ($brand_coverage >= 0.50) $reasons[] = 'apoyo de marca';
        if (!empty($source_hits['supplier'])) $reasons[] = 'apoyo del catálogo de proveedor';
        if (!empty($source_hits['external_structured']) || !empty($source_hits['external_meta'])) $reasons[] = 'apoyo de fuente externa estructurada';
        elseif (!empty($source_hits['external_body'])) $reasons[] = 'apoyo débil de texto externo';
        if ($matched_labels) $reasons[] = 'coincide: ' . implode(', ', array_slice(array_unique($matched_labels), 0, 5));

        $score = 0.035
            + (0.46 * $coverage)
            + (0.18 * $title_coverage)
            + (0.17 * $identity_coverage)
            + (0.10 * $category_coverage)
            + (0.05 * $tag_coverage)
            + (0.03 * $brand_coverage)
            + $bonus;

        $metric = [
            'score'=>round(max(0.0, min(1.0, $score)), 4),
            'matched'=>$matched,
            'title_matched'=>$title_matched,
            'identity_matched'=>(int)round($identity_coverage * count(array_unique($term_seq))),
            'run'=>$run,
            'source_hits'=>$source_hits,
            'reasons'=>array_values(array_unique($reasons)),
        ];
        return seo_classifier_adjust_label_metric($group, $term, $context, $metric);
    }
}

if (!function_exists('seo_classifier_rank_label_group')) {
    function seo_classifier_rank_label_group($group, array $context, $limit = 5) {
        $index = seo_classifier_vocabulary_index();
        $ranked = [];
        foreach ((array)($index[$group] ?? []) as $term) {
            $metric = seo_classifier_label_metric($term, $context, $group);
            if ((float)($metric['score'] ?? 0) <= 0) continue;
            $ranked[] = ['term'=>$term] + $metric;
        }
        usort($ranked, static function($a, $b) {
            $as = (float)($a['score'] ?? 0);
            $bs = (float)($b['score'] ?? 0);
            if ($as !== $bs) return $as > $bs ? -1 : 1;
            $am = (int)($a['matched'] ?? 0);
            $bm = (int)($b['matched'] ?? 0);
            if ($am !== $bm) return $am > $bm ? -1 : 1;
            return strcmp((string)($a['term']['label'] ?? ''), (string)($b['term']['label'] ?? ''));
        });
        return array_slice($ranked, 0, max(1, (int)$limit));
    }
}

if (!function_exists('seo_classifier_rank_product_label_group')) {
    function seo_classifier_rank_product_label_group($group, array $context, array $current, array $effective_type_ids = [], array $options = [], $limit = 5) {
        $lexical = seo_classifier_rank_label_group($group, $context, max(10, (int)$limit * 3));
        $profiles = function_exists('seo_classifier_profile_candidates')
            ? seo_classifier_profile_candidates($group, $context, $current, $effective_type_ids, $options)
            : [];
        if (function_exists('seo_classifier_fuse_label_candidates')) {
            return seo_classifier_fuse_label_candidates($group, $lexical, $profiles, $limit);
        }
        return array_slice($lexical, 0, max(1, (int)$limit));
    }
}

if (!function_exists('seo_classifier_candidate_state')) {
    function seo_classifier_candidate_state($group, array $ranked) {
        if (!$ranked) return ['state'=>'unresolved','candidate'=>null,'margin'=>0.0];
        $top = $ranked[0];
        $score = (float)($top['score'] ?? 0);
        $matched = (int)($top['matched'] ?? 0);
        $second = (float)($ranked[1]['score'] ?? 0);
        $margin = max(0.0, $score - $second);
        $t = seo_classifier_group_thresholds($group);
        $strong_profile = !empty($top['profile_safe']);
        $review_profile = !empty($top['profile_review']);
        $enough_evidence = $strong_profile || $matched >= (int)$t['min_matched'];

        $safe = $enough_evidence
            && $score >= (float)$t['safe']
            && ($margin >= (float)$t['margin'] || $score >= 0.92 || ($strong_profile && $margin >= 0.05));
        $review = ($review_profile || $matched >= 1)
            && $score >= (float)$t['review'];

        return [
            'state'=>$safe ? 'safe' : ($review ? 'review' : 'unresolved'),
            'candidate'=>$top,
            'margin'=>round($margin, 4),
        ];
    }
}

if (!function_exists('seo_classifier_group_allows_multiple')) {
    function seo_classifier_group_allows_multiple($group) {
        $multiple = in_array(sanitize_key((string)$group), ['aplicacion','plataforma'], true);
        return (bool)apply_filters('seo_classifier_group_allows_multiple', $multiple, $group);
    }
}

if (!function_exists('seo_classifier_select_label_candidates')) {
    /**
     * Selecciona uno o varios candidatos según la cardinalidad semántica.
     * TIPO y SUBTIPO son exclusivos; APLICACIÓN/PLATAFORMA pueden contener
     * varias facetas cuando cada una dispone de evidencia independiente suficiente.
     */
    function seo_classifier_select_label_candidates($group, array $ranked) {
        if (!$ranked) {
            return ['state'=>'unresolved','safe'=>[],'review'=>[],'candidate'=>null,'margin'=>0.0];
        }
        if (!seo_classifier_group_allows_multiple($group)) {
            $decision = seo_classifier_candidate_state($group, $ranked);
            return [
                'state'=>$decision['state'],
                'safe'=>$decision['state'] === 'safe' && $decision['candidate'] ? [$decision['candidate']] : [],
                'review'=>$decision['state'] === 'review' && $decision['candidate'] ? [$decision['candidate']] : [],
                'candidate'=>$decision['candidate'],
                'margin'=>$decision['margin'],
            ];
        }

        $thresholds = seo_classifier_group_thresholds($group);
        $top_score = (float)($ranked[0]['score'] ?? 0.0);
        $second_score = (float)($ranked[1]['score'] ?? 0.0);
        $margin = max(0.0, $top_score - $second_score);
        $safe = [];
        $review = [];
        $max_safe = $group === 'plataforma' ? 2 : 3;
        $max_review = 3;

        foreach ($ranked as $row) {
            $score = (float)($row['score'] ?? 0.0);
            $matched = (int)($row['matched'] ?? 0);
            $strong_profile = !empty($row['profile_safe']);
            $review_profile = !empty($row['profile_review']);
            $enough_evidence = $strong_profile || $matched >= (int)$thresholds['min_matched'];
            if ($enough_evidence && $score >= (float)$thresholds['safe'] && $score >= ($top_score - 0.18)) {
                $safe[] = $row;
                if (count($safe) >= $max_safe) break;
            }
        }

        foreach ($ranked as $row) {
            $term_id = (int)($row['term']['id'] ?? 0);
            $already_safe = false;
            foreach ($safe as $safe_row) {
                if ((int)($safe_row['term']['id'] ?? 0) === $term_id) {
                    $already_safe = true;
                    break;
                }
            }
            if ($already_safe) continue;
            $score = (float)($row['score'] ?? 0.0);
            $matched = (int)($row['matched'] ?? 0);
            $review_profile = !empty($row['profile_review']);
            if (($review_profile || $matched >= 1) && $score >= (float)$thresholds['review'] && $score >= ($top_score - 0.14)) {
                $review[] = $row;
                if (count($review) >= $max_review) break;
            }
        }

        $state = $safe ? 'safe' : ($review ? 'review' : 'unresolved');
        return [
            'state'=>$state,
            'safe'=>$safe,
            'review'=>$review,
            'candidate'=>$safe[0] ?? $review[0] ?? $ranked[0],
            'margin'=>round($margin, 4),
        ];
    }
}
