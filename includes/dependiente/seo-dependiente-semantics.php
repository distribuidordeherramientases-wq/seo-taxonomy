<?php

defined('ABSPATH') || exit;

/**
 * Capa semantica de Dependiente.
 *
 * Responsabilidades:
 * - normalizar distintas formas de expresar una misma intencion/objeto;
 * - gestionar palabras de ruido y operadores sin tocar seo_vocabulary;
 * - inferir intenciones desde estados/frases (p.ej. "roto" -> "reparar");
 * - activar rutas intencion + objeto hacia conceptos de producto/vocabulario;
 * - resolver, cuando existe, el vocabulario canonico de wp_seo_vocabulary.
 */
final class SEO_Dependiente_Semantics {
    const SEED_VERSION = '2026-08-31.1';
    const SCHEMA_VERSION = '2026-08-31.2';

    private static $rules = null;
    private static $ready_checked = false;
    private static $vocabulary_cache = array();

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_semantics';
    }

    /**
     * Autorrepara la capa semantica cuando se usan archivos delta sobre una
     * instalacion anterior: completa columnas, siembra reglas y resuelve IDs.
     */
    public static function ensure_ready() {
        global $wpdb;

        if (self::$ready_checked) {
            return self::active_rule_count();
        }
        self::$ready_checked = true;

        if (!self::table_exists() || !self::schema_is_current()) {
            self::install();
        } else {
            $active = self::active_rule_count();
            $seed_version = (string) get_option('seo_dependiente_semantic_seed_version', '');
            if (0 === $active || self::SEED_VERSION !== $seed_version) {
                self::seed_defaults();
                self::resolve_vocabulary_ids();
            }
        }

        // Si el CSV falta o fallo una importacion anterior, garantizar al
        // menos el nucleo minimo para que Dependiente no vuelva al OR ciego.
        if (0 === self::active_rule_count()) {
            self::seed_critical_defaults();
            self::resolve_vocabulary_ids();
        }

        self::$rules = null;
        update_option('seo_dependiente_semantic_schema_version', self::SCHEMA_VERSION, false);
        return self::active_rule_count();
    }

    public static function active_rule_count() {
        global $wpdb;
        if (!self::table_exists()) {
            return 0;
        }
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql(self::table()) . '` WHERE active = 1');
    }

    private static function schema_is_current() {
        global $wpdb;
        if (!self::table_exists()) {
            return false;
        }
        $columns = (array) $wpdb->get_col('SHOW COLUMNS FROM `' . esc_sql(self::table()) . '`', 0);
        $required = array(
            'rule_key','rule_type','expression','normalized_expression','canonical_expression',
            'match_type','semantic_role','source_group','source_slug','context_group','context_slug',
            'target_group','target_slug','relation_type','result_role','weight','priority','language','active'
        );
        return !array_diff($required, $columns);
    }

    public static function install() {
        global $wpdb;

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_key VARCHAR(191) NULL,
            rule_type VARCHAR(30) NOT NULL,
            expression VARCHAR(255) NULL,
            normalized_expression VARCHAR(255) NULL,
            canonical_expression VARCHAR(255) NULL,
            match_type VARCHAR(20) NOT NULL DEFAULT 'token',
            semantic_role VARCHAR(40) NULL,
            source_vocabulary_id BIGINT UNSIGNED NULL,
            source_group VARCHAR(100) NULL,
            source_slug VARCHAR(191) NULL,
            context_vocabulary_id BIGINT UNSIGNED NULL,
            context_group VARCHAR(100) NULL,
            context_slug VARCHAR(191) NULL,
            target_vocabulary_id BIGINT UNSIGNED NULL,
            target_group VARCHAR(100) NULL,
            target_slug VARCHAR(191) NULL,
            relation_type VARCHAR(40) NULL,
            result_role VARCHAR(40) NULL,
            weight SMALLINT UNSIGNED NOT NULL DEFAULT 100,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
            confidence DECIMAL(5,4) NULL,
            language VARCHAR(10) NOT NULL DEFAULT 'es',
            source VARCHAR(80) NOT NULL DEFAULT 'manual',
            metadata LONGTEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY rule_key (rule_key),
            KEY idx_expression_lookup (normalized_expression(160), language, active),
            KEY idx_rule_active (rule_type, active),
            KEY idx_semantic_role (semantic_role, active),
            KEY idx_source_vocabulary (source_vocabulary_id, active),
            KEY idx_context_vocabulary (context_vocabulary_id, active),
            KEY idx_target_vocabulary (target_vocabulary_id, active),
            KEY idx_semantic_route (source_group, source_slug(80), context_group, context_slug(80), active),
            KEY idx_priority (priority, active)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        update_option('seo_dependiente_semantic_schema_version', self::SCHEMA_VERSION, false);

        self::seed_defaults();
        self::resolve_vocabulary_ids();
        self::$rules = null;
    }

    public static function table_exists() {
        return SEO_Dependiente_Index::table_exists(self::table());
    }

    public static function seed_defaults() {
        global $wpdb;

        if (!self::table_exists()) {
            return 0;
        }

        $seed_file = SEO_DEPENDIENTE_PATH . 'data/semantic-seed-es.csv';
        if (!is_readable($seed_file)) {
            return 0;
        }

        $handle = fopen($seed_file, 'rb');
        if (!$handle) {
            return 0;
        }

        $headers = fgetcsv($handle, 0, ',', '"', '');
        if (!$headers) {
            fclose($handle);
            return 0;
        }
        // El CSV se distribuye como UTF-8 con o sin BOM.
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map('trim', $headers);

        $allowed = array(
            'rule_key','rule_type','expression','canonical_expression','match_type','semantic_role',
            'source_group','source_slug','context_group','context_slug','target_group','target_slug',
            'relation_type','result_role','weight','priority','confidence','language','source','active'
        );
        if (array_diff($allowed, $headers)) {
            fclose($handle);
            return 0;
        }

        $inserted = 0;
        $table = self::table();
        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($values) !== count($headers)) {
                continue;
            }
            $row = array_combine($headers, $values);
            $rule_key = sanitize_key((string) ($row['rule_key'] ?? ''));
            if (!$rule_key) {
                continue;
            }

            $existing_source = $wpdb->get_var(
                $wpdb->prepare("SELECT source FROM {$table} WHERE rule_key = %s LIMIT 1", $rule_key)
            );

            $data = array(
                'rule_key'             => $rule_key,
                'rule_type'            => sanitize_key((string) ($row['rule_type'] ?? 'alias')),
                'expression'           => trim((string) ($row['expression'] ?? '')) ?: null,
                'normalized_expression'=> self::normalize((string) ($row['expression'] ?? '')) ?: null,
                'canonical_expression' => self::normalize((string) ($row['canonical_expression'] ?? '')) ?: null,
                'match_type'           => sanitize_key((string) ($row['match_type'] ?? 'token')),
                'semantic_role'        => sanitize_key((string) ($row['semantic_role'] ?? '')) ?: null,
                'source_group'         => sanitize_key((string) ($row['source_group'] ?? '')) ?: null,
                'source_slug'          => self::normalize((string) ($row['source_slug'] ?? '')) ?: null,
                'context_group'        => sanitize_key((string) ($row['context_group'] ?? '')) ?: null,
                'context_slug'         => self::normalize((string) ($row['context_slug'] ?? '')) ?: null,
                'target_group'         => sanitize_key((string) ($row['target_group'] ?? '')) ?: null,
                'target_slug'          => self::normalize((string) ($row['target_slug'] ?? '')) ?: null,
                'relation_type'        => sanitize_key((string) ($row['relation_type'] ?? '')) ?: null,
                'result_role'          => sanitize_key((string) ($row['result_role'] ?? '')) ?: null,
                'weight'               => min(1000, max(0, absint($row['weight'] ?? 100))),
                'priority'             => min(100, max(0, absint($row['priority'] ?? 5))),
                'confidence'           => '' === trim((string) ($row['confidence'] ?? '')) ? null : min(1, max(0, (float) $row['confidence'])),
                'language'             => sanitize_key((string) ($row['language'] ?? 'es')) ?: 'es',
                'source'               => sanitize_key((string) ($row['source'] ?? 'seed')) ?: 'seed',
                'active'               => empty($row['active']) ? 0 : 1,
                'updated_at'           => current_time('mysql'),
            );

            if (null === $existing_source) {
                $data['created_at'] = current_time('mysql');
                if (false !== $wpdb->insert($table, $data)) {
                    $inserted++;
                }
                continue;
            }

            // Las reglas que un administrador convierta a origen manual no se pisan.
            if ('seed' === (string) $existing_source) {
                $wpdb->update($table, $data, array('rule_key' => $rule_key));
            }
        }
        fclose($handle);

        update_option('seo_dependiente_semantic_seed_version', self::SEED_VERSION, false);
        self::$rules = null;
        return $inserted;
    }

    /**
     * Resuelve IDs de vocabulario por grupo+slug sin modificar las tablas canonicas.
     */
    public static function resolve_vocabulary_ids() {
        global $wpdb;

        if (!self::table_exists()) {
            return;
        }
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Dependiente_Index::table_exists($vocabulary)) {
            return;
        }

        $rows = (array) $wpdb->get_results(
            'SELECT id, source_group, source_slug, context_group, context_slug, target_group, target_slug '
            . 'FROM `' . esc_sql(self::table()) . '` WHERE active = 1',
            ARRAY_A
        );

        $allowed_groups = array('rol','tipo','aplicacion','plataforma','subtipo');
        foreach ($rows as $row) {
            $updates = array();
            foreach (array('source','context','target') as $prefix) {
                $group = sanitize_key((string) ($row[$prefix . '_group'] ?? ''));
                $slug = self::normalize((string) ($row[$prefix . '_slug'] ?? ''));
                if (!$group || !$slug || !in_array($group, $allowed_groups, true)) {
                    continue;
                }
                $id = self::vocabulary_id($group, $slug);
                if ($id) {
                    $updates[$prefix . '_vocabulary_id'] = $id;
                }
            }
            if ($updates) {
                $wpdb->update(self::table(), $updates, array('id' => absint($row['id'])));
            }
        }
        self::$rules = null;
    }


    private static function seed_critical_defaults() {
        global $wpdb;
        if (!self::table_exists()) {
            return 0;
        }
        $rows = array(
            array('ignore_quiero','ignore','quiero','', 'token','noise','','','','','','','','',0,10),
            array('ignore_un','ignore','un','', 'token','noise','','','','','','','','',0,10),
            array('ignore_una','ignore','una','', 'token','noise','','','','','','','','',0,10),
            array('ignore_el','ignore','el','', 'token','noise','','','','','','','','',0,10),
            array('ignore_la','ignore','la','', 'token','noise','','','','','','','','',0,10),
            array('intent_reparar','alias','reparar','reparar','token','intent','','','','','','','canonical','',100,9),
            array('intent_arreglar','alias','arreglar','reparar','token','intent','','','','','','','synonym','',95,9),
            array('intent_roto','implication','roto','reparar','token','intent','','','','','','','implies','',95,9),
            array('intent_averiado','implication','averiado','reparar','token','intent','','','','','','','implies','',95,9),
            array('intent_sustituir','alias','sustituir','sustituir','token','intent','','','','','','','canonical','',100,9),
            array('intent_cambiar','alias','cambiar','sustituir','token','intent','','','','','','','synonym','',95,9),
            array('intent_instalar','alias','instalar','instalar','token','intent','','','','','','','canonical','',100,9),
            array('intent_comprar','alias','comprar','comprar','token','intent','','','','','','','canonical','',100,9),
            array('object_grifo','alias','grifo','grifo','token','object','','','','','','','canonical','',100,9),
            array('object_grifos','alias','grifos','grifo','token','object','','','','','','','variant','',95,9),
            array('object_griferia','alias','griferia','grifo','token','object','','','','','','','related','',90,8),
        );
        $inserted = 0;
        foreach ($rows as $r) {
            list($key,$type,$expr,$canonical,$match,$role,$sg,$ss,$cg,$cs,$tg,$ts,$relation,$result,$weight,$priority) = $r;
            $data = array(
                'rule_key'=>sanitize_key($key),'rule_type'=>sanitize_key($type),'expression'=>$expr ?: null,
                'normalized_expression'=>self::normalize($expr) ?: null,'canonical_expression'=>self::normalize($canonical) ?: null,
                'match_type'=>sanitize_key($match),'semantic_role'=>sanitize_key($role) ?: null,
                'source_group'=>$sg ?: null,'source_slug'=>$ss ?: null,'context_group'=>$cg ?: null,'context_slug'=>$cs ?: null,
                'target_group'=>$tg ?: null,'target_slug'=>$ts ?: null,'relation_type'=>$relation ?: null,'result_role'=>$result ?: null,
                'weight'=>(int)$weight,'priority'=>(int)$priority,'confidence'=>1.0,'language'=>'es','source'=>'critical','active'=>1,
                'updated_at'=>current_time('mysql')
            );
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM `' . esc_sql(self::table()) . '` WHERE rule_key = %s LIMIT 1', $data['rule_key']));
            if ($exists) {
                $wpdb->update(self::table(), $data, array('id'=>(int)$exists));
            } else {
                $data['created_at'] = current_time('mysql');
                if (false !== $wpdb->insert(self::table(), $data)) { $inserted++; }
            }
        }
        self::$rules = null;
        return $inserted;
    }

    public static function analyze($query) {
        $normalized = self::normalize($query);
        $result = array(
            'normalized' => $normalized,
            'groups'     => array(),
            'ignored'    => array(),
            'operators'  => array(),
            'matches'    => array(),
            'concepts'   => array(),
            'routes'     => array(),
        );
        if (!$normalized) {
            return $result;
        }

        $rules = self::rules();
        if (!$rules) {
            $result['groups'] = self::fallback_groups($normalized);
            return $result;
        }

        $token_rules = array();
        $phrase_rules = array();
        $aliases = array();
        $route_rules = array();

        foreach ($rules as $rule) {
            if ('route' === $rule['rule_type']) {
                $route_rules[] = $rule;
                continue;
            }
            $expr = (string) $rule['normalized_expression'];
            if (!$expr) {
                continue;
            }
            if ('phrase' === $rule['match_type'] || false !== strpos($expr, ' ')) {
                $phrase_rules[] = $rule;
            } else {
                if (!isset($token_rules[$expr])) {
                    $token_rules[$expr] = array();
                }
                $token_rules[$expr][] = $rule;
            }
            $canonical = self::normalize((string) $rule['canonical_expression']);
            $role = sanitize_key((string) $rule['semantic_role']);
            if ($canonical && $role && in_array($rule['rule_type'], array('alias','variant','implication'), true)) {
                $key = $role . '|' . $canonical;
                if (!isset($aliases[$key])) {
                    $aliases[$key] = array();
                }
                $aliases[$key][] = $expr;
                $aliases[$key][] = $canonical;
            }
        }

        usort($phrase_rules, static function ($a, $b) {
            $length = strlen((string) $b['normalized_expression']) <=> strlen((string) $a['normalized_expression']);
            return 0 !== $length ? $length : ((int) $b['priority'] <=> (int) $a['priority']);
        });

        $consumed = array();
        foreach ($phrase_rules as $rule) {
            $expr = (string) $rule['normalized_expression'];
            if (!self::contains_phrase($normalized, $expr)) {
                continue;
            }
            self::apply_rule($result, $rule, $aliases);
            foreach (explode(' ', $expr) as $part) {
                if ($part) {
                    $consumed[$part] = true;
                }
            }
        }

        foreach (array_values(array_filter(explode(' ', $normalized))) as $word) {
            if (isset($consumed[$word])) {
                continue;
            }
            if (isset($token_rules[$word])) {
                $handled = false;
                foreach ($token_rules[$word] as $rule) {
                    self::apply_rule($result, $rule, $aliases);
                    $handled = true;
                    // Una palabra puede tener varias reglas, pero ignore/operator dominan.
                    if (in_array($rule['rule_type'], array('ignore','operator'), true)) {
                        break;
                    }
                }
                if ($handled) {
                    continue;
                }
            }
            if (strlen($word) < 2 && !ctype_digit($word) && !in_array($word, array('v','w','m'), true)) {
                continue;
            }
            self::add_group($result, self::basic_variants($word), $word, 'term', 70, $word);
        }

        // Activa rutas usando los conceptos ya interpretados.
        $concept_map = array();
        foreach ((array) $result['concepts'] as $role => $values) {
            $concept_map[$role] = array_fill_keys(array_values(array_unique($values)), true);
        }
        foreach ($route_rules as $route) {
            $source_group = sanitize_key((string) $route['source_group']);
            $source_slug = self::normalize((string) $route['source_slug']);
            $context_group = sanitize_key((string) $route['context_group']);
            $context_slug = self::normalize((string) $route['context_slug']);

            if ($source_group && $source_slug && empty($concept_map[$source_group][$source_slug])) {
                continue;
            }
            if ($context_group && $context_slug && empty($concept_map[$context_group][$context_slug])) {
                continue;
            }

            $target = array(
                'id'                   => absint($route['id']),
                'target_vocabulary_id' => absint($route['target_vocabulary_id']),
                'target_group'         => sanitize_key((string) $route['target_group']),
                'target_slug'          => self::normalize((string) $route['target_slug']),
                'relation_type'        => sanitize_key((string) $route['relation_type']),
                'result_role'          => sanitize_key((string) $route['result_role']),
                'weight'               => (int) $route['weight'],
                'priority'             => (int) $route['priority'],
            );
            if (!$target['target_vocabulary_id'] && in_array($target['target_group'], array('rol','tipo','aplicacion','plataforma','subtipo'), true)) {
                $target['target_vocabulary_id'] = self::vocabulary_id($target['target_group'], $target['target_slug']);
            }
            if ($target['target_slug'] || $target['target_vocabulary_id']) {
                $result['routes'][] = $target;
            }
        }

        $result['groups'] = array_values($result['groups']);
        foreach ($result['concepts'] as $role => $values) {
            $result['concepts'][$role] = array_values(array_unique($values));
        }
        return $result;
    }

    public static function group_variants($analysis, $roles = array()) {
        $out = array();
        foreach ((array) ($analysis['groups'] ?? array()) as $group) {
            $role = sanitize_key((string) ($group['role'] ?? 'term'));
            if ($roles && !in_array($role, $roles, true)) {
                continue;
            }
            $variants = array_values(array_unique(array_filter((array) ($group['variants'] ?? array()))));
            if ($variants) {
                $out[] = $variants;
            }
        }
        return $out;
    }

    public static function route_search_groups($analysis) {
        $groups = array();
        foreach ((array) ($analysis['routes'] ?? array()) as $route) {
            $slug = self::normalize((string) ($route['target_slug'] ?? ''));
            if (!$slug) {
                continue;
            }
            if ('search' === ($route['target_group'] ?? '')) {
                $groups[] = array($slug);
            } elseif (in_array(($route['target_group'] ?? ''), array('rol','tipo','aplicacion','plataforma','subtipo'), true)) {
                $groups[] = array($slug);
            }
        }
        return array_slice($groups, 0, 30);
    }

    public static function vocabulary_candidate_product_ids($analysis, $limit = 800) {
        global $wpdb;

        $ids = array();
        foreach ((array) ($analysis['routes'] ?? array()) as $route) {
            $id = absint($route['target_vocabulary_id'] ?? 0);
            if ($id) {
                $ids[$id] = max((int) ($route['weight'] ?? 0), $ids[$id] ?? 0);
            }
        }
        if (!$ids) {
            return array();
        }

        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        if (!SEO_Dependiente_Index::table_exists($objects)) {
            return array();
        }

        $vocabulary_ids = array_keys($ids);
        $placeholders = implode(',', array_fill(0, count($vocabulary_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT object_id, vocabulary_id FROM {$objects}
             WHERE object_type = 'product' AND status = 1
               AND vocabulary_id IN ({$placeholders})
             LIMIT %d",
            array_merge($vocabulary_ids, array(min(2000, max(1, absint($limit) * 3))))
        );
        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        $scores = array();
        foreach ($rows as $row) {
            $product_id = absint($row['object_id']);
            $vocabulary_id = absint($row['vocabulary_id']);
            if (!$product_id || !isset($ids[$vocabulary_id])) {
                continue;
            }
            $scores[$product_id] = ($scores[$product_id] ?? 0) + $ids[$vocabulary_id];
        }
        arsort($scores, SORT_NUMERIC);
        return array_slice(array_keys($scores), 0, min(1000, max(1, absint($limit))));
    }

    /**
     * Bonus/eligibilidad semantica sobre un documento ya indexado.
     */
    public static function score_document($document, $analysis) {
        $bonus = 0.0;
        $reasons = array();
        $route_hits = 0;
        $object_hits = 0;

        $title = self::normalize((string) ($document['normalized_title'] ?? $document['title'] ?? ''));
        $categories = self::normalize(self::labels((array) ($document['categories'] ?? array()), 'name'));
        $tags = self::normalize(self::labels((array) ($document['tags'] ?? array()), 'name'));
        $vocabulary_text = self::normalize(self::all_vocabulary_text((array) ($document['vocabulary'] ?? array())));
        $search_text = (string) ($document['search_text'] ?? '');

        foreach ((array) ($analysis['groups'] ?? array()) as $group) {
            if ('object' !== ($group['role'] ?? '')) {
                continue;
            }
            $hit = false;
            foreach ((array) ($group['variants'] ?? array()) as $variant) {
                if ($variant && (
                    false !== strpos($title, $variant)
                    || false !== strpos($categories, $variant)
                    || false !== strpos($tags, $variant)
                    || false !== strpos($vocabulary_text, $variant)
                )) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                $object_hits++;
                $bonus += 85;
            }
        }

        foreach ((array) ($analysis['routes'] ?? array()) as $route) {
            $hit = false;
            $target_id = absint($route['target_vocabulary_id'] ?? 0);
            $target_group = sanitize_key((string) ($route['target_group'] ?? ''));
            $target_slug = self::normalize((string) ($route['target_slug'] ?? ''));

            if ($target_id && self::document_has_vocabulary_id($document, $target_id)) {
                $hit = true;
            } elseif ($target_slug) {
                if ('search' === $target_group) {
                    $hit = false !== strpos($search_text, $target_slug);
                } elseif (in_array($target_group, array('rol','tipo','aplicacion','plataforma','subtipo'), true)) {
                    $hit = self::document_has_vocabulary_slug($document, $target_group, $target_slug)
                        || false !== strpos($vocabulary_text, $target_slug);
                }
            }

            if (!$hit) {
                continue;
            }
            $route_hits++;
            $bonus += max(0, (int) ($route['weight'] ?? 0));
            $reason = self::result_role_reason((string) ($route['result_role'] ?? ''));
            if ($reason) {
                $reasons[] = $reason;
            }
        }

        return array(
            'bonus'       => $bonus,
            'reasons'     => array_values(array_unique($reasons)),
            'route_hits'  => $route_hits,
            'object_hits' => $object_hits,
        );
    }

    public static function public_analysis($analysis) {
        $groups = array();
        foreach ((array) ($analysis['groups'] ?? array()) as $group) {
            $groups[] = array(
                'canonical' => (string) ($group['canonical'] ?? ''),
                'role'      => (string) ($group['role'] ?? ''),
                'variants'  => array_slice((array) ($group['variants'] ?? array()), 0, 12),
            );
        }
        $routes = array();
        foreach ((array) ($analysis['routes'] ?? array()) as $route) {
            $routes[] = array(
                'target_group' => (string) ($route['target_group'] ?? ''),
                'target_slug'  => (string) ($route['target_slug'] ?? ''),
                'result_role'  => (string) ($route['result_role'] ?? ''),
                'weight'       => (int) ($route['weight'] ?? 0),
            );
        }
        return array(
            'normalized' => (string) ($analysis['normalized'] ?? ''),
            'concepts'   => (array) ($analysis['concepts'] ?? array()),
            'ignored'    => array_values(array_unique((array) ($analysis['ignored'] ?? array()))),
            'operators'  => array_values(array_unique((array) ($analysis['operators'] ?? array()))),
            'groups'     => $groups,
            'routes'     => $routes,
        );
    }

    private static function rules() {
        global $wpdb;
        if (null !== self::$rules) {
            return self::$rules;
        }
        self::$rules = array();
        if (!self::table_exists()) {
            return self::$rules;
        }
        self::$rules = (array) $wpdb->get_results(
            'SELECT * FROM `' . esc_sql(self::table()) . "` WHERE active = 1 AND language = 'es' ORDER BY priority DESC, id ASC",
            ARRAY_A
        );
        return self::$rules;
    }

    private static function apply_rule(&$result, $rule, $aliases) {
        $type = sanitize_key((string) ($rule['rule_type'] ?? ''));
        $expr = self::normalize((string) ($rule['normalized_expression'] ?? $rule['expression'] ?? ''));
        if (!$expr) {
            return;
        }
        if ('ignore' === $type) {
            $result['ignored'][] = $expr;
            return;
        }
        if ('operator' === $type) {
            $result['operators'][] = $expr;
            return;
        }
        if (!in_array($type, array('alias','variant','implication'), true)) {
            return;
        }

        $canonical = self::normalize((string) ($rule['canonical_expression'] ?? '')) ?: $expr;
        $role = sanitize_key((string) ($rule['semantic_role'] ?? 'term')) ?: 'term';
        $key = $role . '|' . $canonical;
        $variants = array_merge(array($expr, $canonical), (array) ($aliases[$key] ?? array()));
        $variants = array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize'), $variants))));
        $variants = array_slice($variants, 0, 18);

        self::add_group($result, $variants, $canonical, $role, (int) ($rule['weight'] ?? 100), $expr);
        if (!isset($result['concepts'][$role])) {
            $result['concepts'][$role] = array();
        }
        $result['concepts'][$role][] = $canonical;
        $result['matches'][] = array(
            'expression' => $expr,
            'canonical'  => $canonical,
            'role'       => $role,
            'rule_type'  => $type,
        );
    }

    private static function add_group(&$result, $variants, $canonical, $role, $weight, $source_expression) {
        $canonical = self::normalize($canonical);
        $role = sanitize_key((string) $role) ?: 'term';
        $variants = array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize'), (array) $variants))));
        if (!$canonical || !$variants) {
            return;
        }
        $key = $role . '|' . $canonical;
        if (!isset($result['groups'][$key])) {
            $result['groups'][$key] = array(
                'canonical'         => $canonical,
                'role'              => $role,
                'weight'            => (int) $weight,
                'source_expression' => $source_expression,
                'variants'          => $variants,
            );
            return;
        }
        $result['groups'][$key]['variants'] = array_values(array_unique(array_merge($result['groups'][$key]['variants'], $variants)));
        $result['groups'][$key]['weight'] = max((int) $result['groups'][$key]['weight'], (int) $weight);
    }

    private static function fallback_groups($normalized) {
        $groups = array();
        foreach (array_values(array_filter(explode(' ', $normalized))) as $word) {
            if (strlen($word) < 2 && !ctype_digit($word)) {
                continue;
            }
            $groups[] = array(
                'canonical' => $word,
                'role'      => 'term',
                'weight'    => 70,
                'variants'  => self::basic_variants($word),
            );
        }
        return $groups;
    }

    private static function basic_variants($token) {
        $token = self::normalize($token);
        if (!$token) {
            return array();
        }
        $variants = array($token);

        if (function_exists('seo_search_parse_synonyms') && function_exists('seo_search_get_option')) {
            static $custom = null;
            if (null === $custom) {
                $custom = (array) seo_search_parse_synonyms((string) seo_search_get_option('synonyms', ''));
            }
            if (isset($custom[$token])) {
                $variants = array_merge($variants, (array) $custom[$token]);
            }
        }

        // Evita el error legacy "cambiar" -> "cambiars": no pluralizar infinitivos.
        if (strlen($token) > 4 && !preg_match('/(?:ar|er|ir)$/', $token)) {
            if ('s' === substr($token, -1)) {
                $variants[] = substr($token, 0, -1);
            } elseif ('z' === substr($token, -1)) {
                $variants[] = substr($token, 0, -1) . 'ces';
            } elseif (preg_match('/[aeiou]$/', $token)) {
                $variants[] = $token . 's';
            } else {
                $variants[] = $token . 'es';
            }
        }
        return array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize'), $variants))));
    }

    private static function vocabulary_id($group, $slug) {
        global $wpdb;
        $group = sanitize_key((string) $group);
        $slug = self::normalize($slug);
        if (!$group || !$slug) {
            return 0;
        }
        $cache_key = $group . '|' . $slug;
        if (isset(self::$vocabulary_cache[$cache_key])) {
            return self::$vocabulary_cache[$cache_key];
        }
        $table = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Dependiente_Index::table_exists($table)) {
            self::$vocabulary_cache[$cache_key] = 0;
            return 0;
        }
        $id = absint($wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE semantic_group = %s AND active = 1
                   AND (slug = %s OR LOWER(label) = %s)
                 ORDER BY id ASC LIMIT 1",
                $group,
                sanitize_title($slug),
                $slug
            )
        ));
        self::$vocabulary_cache[$cache_key] = $id;
        return $id;
    }

    private static function document_has_vocabulary_id($document, $target_id) {
        foreach ((array) ($document['vocabulary'] ?? array()) as $items) {
            foreach ((array) $items as $item) {
                if (absint($item['id'] ?? 0) === $target_id) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function document_has_vocabulary_slug($document, $group, $slug) {
        foreach ((array) ($document['vocabulary'][$group] ?? array()) as $item) {
            $item_slug = self::normalize((string) ($item['slug'] ?? $item['label'] ?? ''));
            if ($item_slug === $slug || false !== strpos($item_slug, $slug)) {
                return true;
            }
        }
        return false;
    }

    private static function result_role_reason($role) {
        $map = array(
            'primary_product' => 'Producto principal para la necesidad',
            'replacement'     => 'Repuesto relacionado con la reparación',
            'tool'            => 'Herramienta adecuada para la tarea',
            'accessory'       => 'Accesorio relacionado con la tarea',
            'consumable'      => 'Consumible útil para la tarea',
            'context'         => 'Encaja con el contexto de uso',
        );
        return $map[$role] ?? '';
    }

    private static function labels($items, $field) {
        $values = array();
        foreach ((array) $items as $item) {
            if (is_array($item) && !empty($item[$field])) {
                $values[] = (string) $item[$field];
            }
        }
        return implode(' ', $values);
    }

    private static function all_vocabulary_text($vocabulary) {
        $values = array();
        foreach ((array) $vocabulary as $items) {
            foreach ((array) $items as $item) {
                if (!empty($item['label'])) {
                    $values[] = (string) $item['label'];
                }
                if (!empty($item['slug'])) {
                    $values[] = (string) $item['slug'];
                }
            }
        }
        return implode(' ', $values);
    }

    private static function contains_phrase($haystack, $needle) {
        return false !== strpos(' ' . $haystack . ' ', ' ' . $needle . ' ');
    }

    public static function normalize($value) {
        return SEO_Dependiente_Index::normalize((string) $value);
    }
}
