<?php

defined('ABSPATH') || exit;

/**
 * Academia V3 · Lección 9
 *
 * Aprende, de forma dinámica y genérica, cómo puede pedir un cliente cada
 * producto a partir de evidencias reales del catálogo: TIPO, ROL, aplicación,
 * categoría, etiquetas, atributos, marca, título y fragmentos descriptivos.
 *
 * No contiene reglas específicas de herramientas, zapatos, pinceles, etc.
 */
final class SEO_Dependiente_V3_Lesson9 {
    const VERSION = '2026-09-16.1';
    const OPTION_STATE = 'seo_dependiente_v3_l9_state';
    const BATCH_SIZE = 80;

    public static function signals_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_l9_signals';
    }

    public static function exercises_table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_l9_exercises';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $signals = self::signals_table();
        $sql_signals = "CREATE TABLE {$signals} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            signal_type VARCHAR(40) NOT NULL,
            signal_text VARCHAR(255) NOT NULL,
            normalized_signal VARCHAR(255) NOT NULL,
            semantic_group VARCHAR(40) NULL,
            vocabulary_id BIGINT UNSIGNED NULL,
            weight SMALLINT UNSIGNED NOT NULL DEFAULT 50,
            source_hash CHAR(64) NOT NULL,
            metadata LONGTEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_signal (product_id, source_hash),
            KEY idx_signal (normalized_signal(160), active),
            KEY idx_product (product_id, active),
            KEY idx_type (signal_type, active)
        ) {$charset};";
        dbDelta($sql_signals);

        $exercises = self::exercises_table();
        $sql_exercises = "CREATE TABLE {$exercises} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            question LONGTEXT NOT NULL,
            normalized_question LONGTEXT NOT NULL,
            exercise_type VARCHAR(40) NOT NULL,
            expected_mode VARCHAR(20) NOT NULL DEFAULT 'product',
            evidence_json LONGTEXT NULL,
            question_hash CHAR(64) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY question_hash (question_hash),
            KEY idx_product (product_id, active),
            KEY idx_type (exercise_type, active)
        ) {$charset};";
        dbDelta($sql_exercises);
    }

    public static function reset() {
        global $wpdb;
        self::install();
        $wpdb->query('TRUNCATE TABLE `' . esc_sql(self::signals_table()) . '`');
        $wpdb->query('TRUNCATE TABLE `' . esc_sql(self::exercises_table()) . '`');
        delete_option(self::OPTION_STATE);
        return self::status();
    }

    public static function status() {
        global $wpdb;
        self::install();
        $index = SEO_Dependiente_V3_DB::table('seo_dependiente_index');
        $total = SEO_Dependiente_V3_DB::exists('seo_dependiente_index')
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$index}")
            : 0;
        $state = get_option(self::OPTION_STATE, array());
        if (!is_array($state)) {
            $state = array();
        }
        $signals = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql(self::signals_table()) . '` WHERE active=1');
        $exercises = (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql(self::exercises_table()) . '` WHERE active=1');
        $processed = absint($state['processed'] ?? 0);
        return array(
            'version' => self::VERSION,
            'total_products' => $total,
            'processed_products' => min($total, $processed),
            'last_product_id' => absint($state['last_product_id'] ?? 0),
            'signals' => $signals,
            'exercises' => $exercises,
            'complete' => !empty($state['complete']) && $total > 0,
            'started_at' => (string) ($state['started_at'] ?? ''),
            'completed_at' => (string) ($state['completed_at'] ?? ''),
            'percent' => $total > 0 ? round(min(100, ($processed / $total) * 100), 1) : 0,
        );
    }

    public static function prepare_batch($limit = self::BATCH_SIZE) {
        global $wpdb;
        self::install();
        if (!SEO_Dependiente_V3_DB::exists('seo_dependiente_index')) {
            return new WP_Error('l9_no_index', 'No existe seo_dependiente_index. Reindexa el catálogo antes de iniciar la Lección 9.');
        }

        $limit = min(200, max(10, absint($limit)));
        $state = get_option(self::OPTION_STATE, array());
        if (!is_array($state)) {
            $state = array();
        }
        if (empty($state['started_at'])) {
            $state['started_at'] = current_time('mysql');
        }
        $last = absint($state['last_product_id'] ?? 0);
        $index = SEO_Dependiente_V3_DB::table('seo_dependiente_index');
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id,title,excerpt,brand_name,categories_json,tags_json,vocabulary_json,attributes_json,search_text
                   FROM {$index}
                  WHERE product_id > %d
                  ORDER BY product_id ASC
                  LIMIT %d",
                $last,
                $limit
            ),
            ARRAY_A
        );

        if (!$rows) {
            $state['complete'] = 1;
            $state['completed_at'] = current_time('mysql');
            update_option(self::OPTION_STATE, $state, false);
            return self::status();
        }

        $processed_now = 0;
        foreach ($rows as $row) {
            $product_id = absint($row['product_id'] ?? 0);
            if (!$product_id) {
                continue;
            }
            $knowledge = self::extract_product_knowledge($row);
            self::store_signals($product_id, $knowledge['signals']);
            self::store_exercises($product_id, self::build_questions($row, $knowledge));
            $state['last_product_id'] = $product_id;
            $processed_now++;
        }

        $state['processed'] = absint($state['processed'] ?? 0) + $processed_now;
        $state['complete'] = 0;
        update_option(self::OPTION_STATE, $state, false);

        if (count($rows) < $limit) {
            $state['complete'] = 1;
            $state['completed_at'] = current_time('mysql');
            update_option(self::OPTION_STATE, $state, false);
        }

        return self::status();
    }

    private static function extract_product_knowledge($row) {
        $signals = array();
        $title = trim((string) ($row['title'] ?? ''));
        $brand = trim((string) ($row['brand_name'] ?? ''));

        self::add_signal($signals, 'title', $title, 220, 'title');
        self::add_signal($signals, 'brand', $brand, 55, 'brand');

        $vocabulary = SEO_Dependiente_V3_DB::json_array($row['vocabulary_json'] ?? '');
        $groups = array('tipo' => array(), 'rol' => array(), 'aplicacion' => array(), 'subtipo' => array(), 'plataforma' => array());
        foreach ((array) $vocabulary as $item) {
            if (!is_array($item)) {
                continue;
            }
            $group = sanitize_key((string) ($item['semantic_group'] ?? $item['group'] ?? ''));
            $label = trim((string) ($item['label'] ?? $item['name'] ?? $item['slug'] ?? ''));
            if (!$label || !isset($groups[$group])) {
                continue;
            }
            $groups[$group][] = $label;
            $weights = array('tipo' => 155, 'aplicacion' => 145, 'subtipo' => 135, 'plataforma' => 105, 'rol' => 50);
            self::add_signal(
                $signals,
                $group,
                $label,
                $weights[$group],
                $group,
                absint($item['id'] ?? $item['vocabulary_id'] ?? 0)
            );
        }

        $categories = self::named_values(SEO_Dependiente_V3_DB::json_array($row['categories_json'] ?? ''));
        foreach (array_slice($categories, 0, 5) as $value) {
            self::add_signal($signals, 'category', $value, 95, 'category');
        }

        $tags = self::named_values(SEO_Dependiente_V3_DB::json_array($row['tags_json'] ?? ''));
        foreach (array_slice($tags, 0, 12) as $value) {
            self::add_signal($signals, 'tag', $value, 105, 'tag');
        }

        $attributes = self::attribute_phrases(SEO_Dependiente_V3_DB::json_array($row['attributes_json'] ?? ''));
        foreach (array_slice($attributes, 0, 12) as $value) {
            self::add_signal($signals, 'attribute', $value, 100, 'attribute');
        }

        foreach (self::description_phrases((string) ($row['excerpt'] ?? '')) as $phrase) {
            self::add_signal($signals, 'description', $phrase, 42, 'description');
        }

        foreach ($groups as $key => $values) {
            $groups[$key] = array_values(array_unique(array_filter($values)));
        }

        return array(
            'signals' => array_values($signals),
            'groups' => $groups,
            'categories' => $categories,
            'tags' => $tags,
            'attributes' => $attributes,
            'title' => $title,
            'brand' => $brand,
        );
    }

    private static function add_signal(&$signals, $type, $text, $weight, $semantic_group = '', $vocabulary_id = 0) {
        $text = trim(wp_strip_all_tags((string) $text));
        $normalized = SEO_Dependiente_V3_DB::normalize($text);
        if (!$normalized || strlen($normalized) < 2) {
            return;
        }
        foreach (self::signal_variants($normalized) as $variant) {
            $key = sanitize_key($type) . '|' . $variant;
            $candidate = array(
                'signal_type' => sanitize_key($type),
                'signal_text' => $text,
                'normalized_signal' => $variant,
                'semantic_group' => sanitize_key($semantic_group),
                'vocabulary_id' => absint($vocabulary_id),
                'weight' => absint($weight),
            );
            if (!isset($signals[$key]) || $candidate['weight'] > $signals[$key]['weight']) {
                $signals[$key] = $candidate;
            }
        }
    }

    private static function signal_variants($normalized) {
        $words = array_values(array_filter(explode(' ', SEO_Dependiente_V3_DB::normalize($normalized))));
        $count = count($words);
        if (!$count) {
            return array();
        }
        $variants = array();
        if ($count <= 5) {
            $variants[] = implode(' ', $words);
        } else {
            $variants[] = implode(' ', array_slice($words, 0, 5));
            $variants[] = implode(' ', array_slice($words, -5));
        }
        if ($count >= 3) {
            for ($size = min(4, $count); $size >= 2; $size--) {
                for ($i = 0; $i <= $count - $size && $i < 4; $i++) {
                    $variants[] = implode(' ', array_slice($words, $i, $size));
                }
            }
        }
        return array_values(array_unique(array_filter($variants, static function ($value) {
            return strlen($value) >= 3;
        })));
    }

    private static function named_values($items) {
        $out = array();
        foreach ((array) $items as $item) {
            if (is_string($item) || is_numeric($item)) {
                $value = trim((string) $item);
                if ($value) {
                    $out[] = $value;
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            foreach (array('name','label','value','slug') as $key) {
                if (!empty($item[$key]) && is_scalar($item[$key])) {
                    $out[] = trim((string) $item[$key]);
                    break;
                }
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    private static function attribute_phrases($attributes) {
        $out = array();
        foreach ((array) $attributes as $key => $item) {
            if (is_scalar($item)) {
                $label = is_string($key) ? trim((string) $key) : '';
                $value = trim((string) $item);
                if ($value) {
                    $out[] = trim($label . ' ' . $value);
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['name'] ?? $item['label'] ?? $item['key'] ?? ''));
            $value = $item['value'] ?? $item['option'] ?? $item['options'] ?? '';
            if (is_array($value)) {
                $value = implode(' ', array_map('strval', $value));
            }
            $value = trim((string) $value);
            if ($value) {
                $out[] = trim($label . ' ' . $value);
                $out[] = $value;
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    private static function description_phrases($excerpt) {
        $text = html_entity_decode(wp_strip_all_tags((string) $excerpt), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
        $normalized = SEO_Dependiente_V3_DB::normalize($text);
        if (!$normalized) {
            return array();
        }
        $blocked = array(
            'envio','pago','seguro','devolucion','garantia','iva','incluido','comprar','producto','referencia',
            'precio','oferta','anadir','carrito','posventa','fabricante','distribuidor','atencion','castellano',
            'comprobar','revisar','antes','decidir','condiciones','instalacion','cuando','sean','relevantes'
        );
        $tokens = array_values(array_filter(explode(' ', $normalized), static function ($word) use ($blocked) {
            return strlen($word) >= 4 && !in_array($word, $blocked, true) && !ctype_digit($word);
        }));
        if (count($tokens) < 3) {
            return array();
        }
        $phrases = array();
        for ($i = 0; $i + 2 < count($tokens) && count($phrases) < 6; $i += 2) {
            $size = min(4, count($tokens) - $i);
            if ($size >= 3) {
                $phrases[] = implode(' ', array_slice($tokens, $i, $size));
            }
        }
        return array_values(array_unique($phrases));
    }

    private static function build_questions($row, $knowledge) {
        $questions = array();
        $title = $knowledge['title'];
        $brand = $knowledge['brand'];
        $tipo = (string) ($knowledge['groups']['tipo'][0] ?? '');
        $rol = (string) ($knowledge['groups']['rol'][0] ?? '');
        $aplicacion = (string) ($knowledge['groups']['aplicacion'][0] ?? '');
        $subtipo = (string) ($knowledge['groups']['subtipo'][0] ?? '');
        $category = (string) ($knowledge['categories'][0] ?? '');
        $tag = (string) ($knowledge['tags'][0] ?? '');
        $attribute = (string) ($knowledge['attributes'][0] ?? '');

        self::add_question($questions, 'identidad', 'Busco ' . $title, array($title), 'product');
        if ($tipo && $brand) {
            self::add_question($questions, 'tipo_marca', 'Busco ' . $tipo . ' de la marca ' . $brand, array($tipo, $brand), 'product');
        }
        if ($tipo && $aplicacion) {
            self::add_question($questions, 'tipo_aplicacion', 'Necesito ' . $tipo . ' para ' . $aplicacion, array($tipo, $aplicacion), 'family');
        }
        if ($rol && $aplicacion) {
            self::add_question($questions, 'rol_aplicacion', 'Busco ' . $rol . ' para ' . $aplicacion, array($rol, $aplicacion), 'family');
        }
        if ($category && $tag) {
            self::add_question($questions, 'categoria_etiqueta', 'Necesito algo de ' . $category . ' relacionado con ' . $tag, array($category, $tag), 'family');
        }
        if ($tipo && $attribute) {
            self::add_question($questions, 'tipo_atributo', 'Busco ' . $tipo . ' con ' . $attribute, array($tipo, $attribute), 'product');
        } elseif ($title && $attribute) {
            self::add_question($questions, 'producto_atributo', 'Busco un producto como ' . $title . ' con ' . $attribute, array($title, $attribute), 'product');
        }
        if ($subtipo && $aplicacion) {
            self::add_question($questions, 'subtipo_aplicacion', 'Necesito ' . $subtipo . ' para ' . $aplicacion, array($subtipo, $aplicacion), 'family');
        }
        if ($brand && $attribute && $aplicacion) {
            self::add_question($questions, 'combinada', 'Busco algo de ' . $brand . ' para ' . $aplicacion . ' con ' . $attribute, array($brand, $aplicacion, $attribute), 'product');
        }
        if ($tipo && $tag && $attribute) {
            self::add_question($questions, 'compleja', 'Necesito ' . $tipo . ' relacionado con ' . $tag . ' y con ' . $attribute, array($tipo, $tag, $attribute), 'product');
        }

        return array_slice(array_values($questions), 0, 10);
    }

    private static function add_question(&$questions, $type, $question, $evidence, $mode) {
        $question = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $question)));
        $normalized = SEO_Dependiente_V3_DB::normalize($question);
        if (!$normalized || strlen($normalized) < 6) {
            return;
        }
        $hash = hash('sha256', $normalized . '|' . sanitize_key($type));
        $questions[$hash] = array(
            'question' => $question,
            'normalized_question' => $normalized,
            'exercise_type' => sanitize_key($type),
            'expected_mode' => ('product' === $mode) ? 'product' : 'family',
            'evidence' => array_values(array_unique(array_filter(array_map('strval', (array) $evidence)))),
            'question_hash' => $hash,
        );
    }

    private static function store_signals($product_id, $signals) {
        global $wpdb;
        $table = self::signals_table();
        foreach ((array) $signals as $signal) {
            $normalized = SEO_Dependiente_V3_DB::normalize($signal['normalized_signal'] ?? '');
            if (!$normalized) {
                continue;
            }
            $source_hash = hash('sha256', implode('|', array(
                $product_id,
                sanitize_key((string) ($signal['signal_type'] ?? '')),
                $normalized,
                sanitize_key((string) ($signal['semantic_group'] ?? '')),
                absint($signal['vocabulary_id'] ?? 0),
            )));
            $wpdb->replace($table, array(
                'product_id' => absint($product_id),
                'signal_type' => sanitize_key((string) ($signal['signal_type'] ?? 'term')),
                'signal_text' => substr((string) ($signal['signal_text'] ?? $normalized), 0, 255),
                'normalized_signal' => substr($normalized, 0, 255),
                'semantic_group' => sanitize_key((string) ($signal['semantic_group'] ?? '')),
                'vocabulary_id' => absint($signal['vocabulary_id'] ?? 0) ?: null,
                'weight' => min(500, max(1, absint($signal['weight'] ?? 50))),
                'source_hash' => $source_hash,
                'metadata' => wp_json_encode(array('lesson' => 'v3_l9_product_language', 'version' => self::VERSION)),
                'active' => 1,
                'updated_at' => current_time('mysql'),
            ), array('%d','%s','%s','%s','%s','%d','%d','%s','%s','%d','%s'));
        }
    }

    private static function store_exercises($product_id, $questions) {
        global $wpdb;
        $table = self::exercises_table();
        foreach ((array) $questions as $question) {
            $hash = hash('sha256', absint($product_id) . '|' . (string) ($question['question_hash'] ?? ''));
            $wpdb->replace($table, array(
                'product_id' => absint($product_id),
                'question' => (string) ($question['question'] ?? ''),
                'normalized_question' => (string) ($question['normalized_question'] ?? ''),
                'exercise_type' => sanitize_key((string) ($question['exercise_type'] ?? 'general')),
                'expected_mode' => ('family' === ($question['expected_mode'] ?? '')) ? 'family' : 'product',
                'evidence_json' => wp_json_encode((array) ($question['evidence'] ?? array())),
                'question_hash' => $hash,
                'active' => 1,
                'updated_at' => current_time('mysql'),
            ), array('%d','%s','%s','%s','%s','%s','%s','%d','%s'));
        }
    }

    public static function preview($limit = 20) {
        global $wpdb;
        self::install();
        $limit = min(50, max(1, absint($limit)));
        $table = self::exercises_table();
        return (array) $wpdb->get_results(
            "SELECT e.product_id,e.question,e.exercise_type,e.expected_mode,p.post_title
               FROM {$table} e
               LEFT JOIN {$wpdb->posts} p ON p.ID=e.product_id
              WHERE e.active=1
              ORDER BY e.id DESC LIMIT {$limit}",
            ARRAY_A
        );
    }

    /**
     * Coincidencias L9 para uso del runtime V3.
     * Una señal genérica aislada no recupera un producto: se exige evidencia
     * acumulada para evitar que "herramienta", una marca o una categoría amplia
     * dominen la búsqueda.
     */
    public static function match_query($normalized_query, $limit = 300) {
        global $wpdb;
        self::install();
        $normalized_query = SEO_Dependiente_V3_DB::normalize($normalized_query);
        if (!$normalized_query) {
            return array();
        }
        $ngrams = SEO_Dependiente_V3_DB::ngrams($normalized_query, 5);
        $ngrams = array_values(array_unique(array_filter($ngrams, static function ($value) {
            return strlen((string) $value) >= 3;
        })));
        if (!$ngrams) {
            return array();
        }
        $table = self::signals_table();
        $placeholders = SEO_Dependiente_V3_DB::placeholders(count($ngrams));
        $sql = "SELECT product_id,signal_type,normalized_signal,weight
                  FROM {$table}
                 WHERE active=1 AND normalized_signal IN ({$placeholders})
                 ORDER BY weight DESC
                 LIMIT 5000";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $ngrams), ARRAY_A);
        if (!$rows) {
            return array();
        }

        $products = array();
        $strong_types = array('title','tipo','aplicacion','subtipo','tag','attribute','category','description');
        foreach ($rows as $row) {
            $id = absint($row['product_id'] ?? 0);
            if (!$id) {
                continue;
            }
            if (!isset($products[$id])) {
                $products[$id] = array(
                    'product_id' => $id,
                    'score' => 0,
                    'matched_signals' => array(),
                    'signal_types' => array(),
                    'strong_hits' => 0,
                );
            }
            $signal = (string) ($row['normalized_signal'] ?? '');
            $type = sanitize_key((string) ($row['signal_type'] ?? ''));
            $weight = absint($row['weight'] ?? 0);
            $dedupe = $type . '|' . $signal;
            if (isset($products[$id]['matched_signals'][$dedupe])) {
                continue;
            }
            $products[$id]['matched_signals'][$dedupe] = array('type' => $type, 'signal' => $signal, 'weight' => $weight);
            $products[$id]['signal_types'][$type] = true;
            $products[$id]['score'] += $weight;
            if (in_array($type, $strong_types, true) && $weight >= 90) {
                $products[$id]['strong_hits']++;
            }
        }

        foreach ($products as $id => &$item) {
            $item['matched_count'] = count($item['matched_signals']);
            $item['type_count'] = count($item['signal_types']);
            $item['matched_signals'] = array_values($item['matched_signals']);
            unset($item['signal_types']);
            if ($item['matched_count'] >= 2) {
                $item['score'] += min(240, 60 * ($item['matched_count'] - 1));
            }
        }
        unset($item);

        $products = array_filter($products, static function ($item) {
            if (($item['strong_hits'] ?? 0) >= 2) {
                return true;
            }
            if (($item['strong_hits'] ?? 0) >= 1 && ($item['matched_count'] ?? 0) >= 2 && ($item['score'] ?? 0) >= 180) {
                return true;
            }
            return ($item['score'] ?? 0) >= 300;
        });

        uasort($products, static function ($a, $b) {
            $cmp = (int) ($b['strong_hits'] ?? 0) <=> (int) ($a['strong_hits'] ?? 0);
            if (0 !== $cmp) return $cmp;
            $cmp = (int) ($b['type_count'] ?? 0) <=> (int) ($a['type_count'] ?? 0);
            if (0 !== $cmp) return $cmp;
            return (int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0);
        });

        return array_slice($products, 0, min(500, max(1, absint($limit))), true);
    }
}
