<?php

defined('ABSPATH') || exit;

/**
 * Academia · Lección 9 — Necesidades y productos.
 *
 * Genera el temario desde el índice real de la instalación. No contiene
 * preguntas, categorías ni productos codificados para una tienda concreta.
 *
 * La memoria se prepara en estado inactivo (aula). Durante L9 solo la petición
 * de Academia puede verla. Si la lección supera el quality gate, la memoria se
 * activa y pasa a formar parte del runtime público de Dependiente V3.
 */
final class SEO_Dependiente_V3_Lesson9 {
    const VERSION = '2026-09-16.2';
    const LESSON_KEY = 'v2_l9_product_language';
    const MAX_SIGNALS_PER_PRODUCT = 28;

    private static $classroom_lesson = '';
    private static $initialized = false;
    private static $installed = false;

    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        self::install();

        // Mantiene la memoria L9 alineada con el índice después de completar la
        // lección, sin convertir Academia en un requisito para cada alta futura.
        add_action('woocommerce_new_product', array(__CLASS__, 'refresh_product_memory'), 120, 1);
        add_action('woocommerce_update_product', array(__CLASS__, 'refresh_product_memory'), 120, 1);
        add_action('save_post_product', array(__CLASS__, 'late_refresh_product_memory'), 1001, 3);
        add_action('before_delete_post', array(__CLASS__, 'delete_product_memory'), 120, 1);
        add_action('transition_post_status', array(__CLASS__, 'sync_product_status'), 120, 3);
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_dependiente_l9_signals';
    }

    public static function install() {
        if (self::$installed) {
            return;
        }
        self::$installed = true;
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lesson_key VARCHAR(60) NOT NULL DEFAULT 'v2_l9_product_language',
            product_id BIGINT UNSIGNED NOT NULL,
            signal_type VARCHAR(40) NOT NULL,
            signal_text VARCHAR(255) NOT NULL,
            normalized_signal VARCHAR(255) NOT NULL,
            semantic_group VARCHAR(40) NULL,
            vocabulary_id BIGINT UNSIGNED NULL,
            weight SMALLINT UNSIGNED NOT NULL DEFAULT 50,
            source_hash CHAR(64) NOT NULL,
            metadata LONGTEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_signal (lesson_key, product_id, source_hash),
            KEY idx_signal (normalized_signal(160), active),
            KEY idx_product (product_id, active),
            KEY idx_lesson_active (lesson_key, active),
            KEY idx_type (signal_type, active)
        ) {$charset};";
        dbDelta($sql);
    }

    public static function source_total() {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Index') || !SEO_Dependiente_Index::table_exists()) {
            return 0;
        }
        return absint($wpdb->get_var('SELECT COUNT(*) FROM ' . SEO_Dependiente_Index::table()));
    }

    /**
     * Devuelve un ejercicio por producto. La pregunta no tiene por qué nombrar
     * el SKU: intenta combinar TIPO/APLICACIÓN/SUBTIPO/tag/atributo. Solo usa el
     * título exacto como red de seguridad cuando el producto carece de señales
     * estructuradas suficientes.
     */
    public static function source_batch($offset, $limit) {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Index') || !SEO_Dependiente_Index::table_exists()) {
            return array();
        }
        $offset = max(0, absint($offset));
        $limit = min(250, max(1, absint($limit)));
        $table = SEO_Dependiente_Index::table();
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT product_id,title,excerpt,brand_name,categories_json,tags_json,vocabulary_json,attributes_json,search_text
               FROM {$table}
              ORDER BY product_id ASC
              LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);

        $items = array();
        foreach ($rows as $raw) {
            $row = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::decode_row($raw) : $raw;
            $item = self::build_curriculum_item($row);
            if ($item) {
                $items[] = $item;
            }
        }
        return $items;
    }

    private static function build_curriculum_item($row) {
        $product_id = absint($row['product_id'] ?? 0);
        $title = trim(wp_strip_all_tags((string) ($row['title'] ?? '')));
        if (!$product_id || !$title) {
            return array();
        }

        $signals = self::product_signals($row);
        $tipo = self::first_vocabulary_feature($row, 'tipo');
        $aplicacion = self::first_vocabulary_feature($row, 'aplicacion');
        $subtipo = self::first_vocabulary_feature($row, 'subtipo');
        $plataforma = self::first_vocabulary_feature($row, 'plataforma');
        $rol = self::first_vocabulary_feature($row, 'rol');
        $tag = self::first_tag_feature($row);
        $attribute = self::first_attribute_feature($row);

        $question = '';
        $features = array();
        $question_type = 'need_product_language';

        if ($tipo && $aplicacion && $attribute) {
            $question = 'Necesito ' . $tipo['label'] . ' para ' . $aplicacion['label'] . ' y que tenga ' . self::feature_text($attribute) . '.';
            $features = array($tipo, $aplicacion, $attribute);
            $question_type = 'need_type_application_attribute';
        } elseif ($tipo && $aplicacion && $tag) {
            $question = 'Busco ' . $tipo['label'] . ' para ' . $aplicacion['label'] . ', relacionado con ' . $tag['label'] . '.';
            $features = array($tipo, $aplicacion, $tag);
            $question_type = 'need_type_application_tag';
        } elseif ($tipo && $tag && $attribute) {
            $question = 'Necesito ' . $tipo['label'] . ' relacionado con ' . $tag['label'] . ' y con ' . self::feature_text($attribute) . '.';
            $features = array($tipo, $tag, $attribute);
            $question_type = 'need_type_tag_attribute';
        } elseif ($tipo && $aplicacion) {
            $question = 'Necesito ' . $tipo['label'] . ' para ' . $aplicacion['label'] . '.';
            $features = array($tipo, $aplicacion);
            $question_type = 'need_type_application';
        } elseif ($subtipo && $aplicacion) {
            $question = 'Busco ' . $subtipo['label'] . ' para ' . $aplicacion['label'] . '.';
            $features = array($subtipo, $aplicacion);
            $question_type = 'need_subtype_application';
        } elseif ($tipo && $attribute) {
            $question = 'Busco ' . $tipo['label'] . ' con ' . self::feature_text($attribute) . '.';
            $features = array($tipo, $attribute);
            $question_type = 'need_type_attribute';
        } elseif ($aplicacion && $tag) {
            $question = 'Busco una solución para ' . $aplicacion['label'] . ' relacionada con ' . $tag['label'] . '.';
            $features = array($aplicacion, $tag);
            $question_type = 'need_application_tag';
        } elseif ($rol && $aplicacion) {
            $question = 'Necesito ' . $rol['label'] . ' para ' . $aplicacion['label'] . '.';
            $features = array($rol, $aplicacion);
            $question_type = 'need_role_application';
        } elseif ($tipo && $tag) {
            $question = 'Busco ' . $tipo['label'] . ' relacionado con ' . $tag['label'] . '.';
            $features = array($tipo, $tag);
            $question_type = 'need_type_tag';
        } elseif ($tipo && $plataforma) {
            $question = 'Necesito ' . $tipo['label'] . ' compatible con ' . $plataforma['label'] . '.';
            $features = array($tipo, $plataforma);
            $question_type = 'need_type_platform';
        } elseif ($tipo) {
            $question = 'Busco un producto del tipo ' . $tipo['label'] . '.';
            $features = array($tipo);
            $question_type = 'need_type';
        }

        if ($features) {
            $expected = array(
                'kind' => 'features',
                'features' => array_values($features),
                'source_product_id' => $product_id,
                'academy' => array('lesson' => self::LESSON_KEY, 'memory' => 'product_language'),
            );
        } else {
            // Productos pobres en clasificación siguen estando representados, pero
            // no se inventa ninguna necesidad que no esté respaldada por datos.
            $question = 'Busco ' . $title . '.';
            $expected = array(
                'kind' => 'product',
                'product_id' => $product_id,
                'academy' => array('lesson' => self::LESSON_KEY, 'fallback' => 'title'),
            );
            $question_type = 'product_identity_fallback';
        }

        return array(
            'source_type' => 'product_language',
            'source_id' => $product_id,
            'source_key' => 'l9-product:' . $product_id,
            'question_type' => $question_type,
            'mode' => 'need',
            'question' => self::shorten($question, 490),
            'expected' => $expected,
            'rules' => array(array(
                'kind' => 'lesson9_product_memory',
                'product_id' => $product_id,
                'signals' => $signals,
            )),
        );
    }

    private static function product_signals($row) {
        $signals = array();
        $title = trim((string) ($row['title'] ?? ''));
        $brand = trim((string) ($row['brand_name'] ?? ''));
        self::add_signal($signals, 'title', $title, 230, 'title', 0, false);
        self::add_signal($signals, 'brand', $brand, 55, 'brand', 0, false);

        $vocabulary = is_array($row['vocabulary'] ?? null) ? $row['vocabulary'] : self::decode_json($row['vocabulary_json'] ?? '');
        $weights = array('tipo'=>185, 'aplicacion'=>175, 'subtipo'=>155, 'plataforma'=>125, 'rol'=>45);
        foreach ($weights as $group => $weight) {
            foreach (array_slice((array) ($vocabulary[$group] ?? array()), 0, 2) as $term) {
                if (!is_array($term)) continue;
                $label = trim((string) ($term['label'] ?? $term['name'] ?? ''));
                if (!$label) continue;
                self::add_signal($signals, $group, $label, $weight, $group, absint($term['id'] ?? 0), true);
            }
        }

        $categories = is_array($row['categories'] ?? null) ? $row['categories'] : self::decode_json($row['categories_json'] ?? '');
        foreach (array_slice((array) $categories, 0, 3) as $term) {
            $label = is_array($term) ? trim((string) ($term['name'] ?? $term['label'] ?? '')) : trim((string) $term);
            self::add_signal($signals, 'category', $label, 100, 'category', 0, true);
        }

        $tags = is_array($row['tags'] ?? null) ? $row['tags'] : self::decode_json($row['tags_json'] ?? '');
        $tag_count = 0;
        foreach ((array) $tags as $term) {
            $label = is_array($term) ? trim((string) ($term['name'] ?? $term['label'] ?? '')) : trim((string) $term);
            if (!self::useful_text($label)) continue;
            self::add_signal($signals, 'tag', $label, 135, 'tag', 0, true);
            $tag_count++;
            if ($tag_count >= 5) break;
        }

        $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : self::decode_json($row['attributes_json'] ?? '');
        $attr_count = 0;
        foreach ((array) $attributes as $attribute) {
            if (!is_array($attribute)) continue;
            $label = trim((string) ($attribute['label'] ?? $attribute['name'] ?? $attribute['key'] ?? ''));
            foreach (array_slice((array) ($attribute['values'] ?? array()), 0, 2) as $value) {
                $value = trim((string) $value);
                if (!$value) continue;
                $text = trim($label . ' ' . $value);
                self::add_signal($signals, 'attribute', $text, 145, 'attribute', 0, true);
                $attr_count++;
                if ($attr_count >= 4) break 2;
            }
        }

        foreach (self::description_phrases((string) ($row['excerpt'] ?? '')) as $phrase) {
            self::add_signal($signals, 'description', $phrase, 125, 'description', 0, true);
        }

        uasort($signals, static function ($a, $b) {
            return absint($b['weight'] ?? 0) <=> absint($a['weight'] ?? 0);
        });
        return array_slice(array_values($signals), 0, self::MAX_SIGNALS_PER_PRODUCT);
    }

    private static function add_signal(&$signals, $type, $text, $weight, $semantic_group = '', $vocabulary_id = 0, $tokenize = true) {
        $text = trim(wp_strip_all_tags((string) $text));
        $normalized = self::normalize($text);
        if (!$normalized || strlen($normalized) < 2) {
            return;
        }
        self::put_signal($signals, $type, $text, $normalized, $semantic_group, $vocabulary_id, $weight);

        $compact = self::without_stopwords($normalized);
        if ($compact && $compact !== $normalized) {
            self::put_signal($signals, $type, $text, $compact, $semantic_group, $vocabulary_id, (int) round($weight * 0.90));
        }

        if ($tokenize) {
            $tokens = array_values(array_filter(explode(' ', $compact ?: $normalized), static function ($token) {
                return strlen((string) $token) >= 3;
            }));
            foreach (array_slice($tokens, 0, 4) as $token) {
                $variants = class_exists('SEO_Dependiente_V3_DB') ? SEO_Dependiente_V3_DB::token_variants($token) : array($token);
                foreach (array_slice(array_values(array_unique((array) $variants)), 0, 3) as $variant) {
                    $variant = self::normalize($variant);
                    if (!$variant || strlen($variant) < 3) continue;
                    self::put_signal($signals, $type, $text, $variant, $semantic_group, $vocabulary_id, max(25, (int) round($weight * 0.45)));
                }
            }
        }
    }

    private static function put_signal(&$signals, $type, $text, $normalized, $semantic_group, $vocabulary_id, $weight) {
        $key = sanitize_key((string) $type) . '|' . $normalized;
        $candidate = array(
            'signal_type' => sanitize_key((string) $type),
            'signal_text' => self::shorten($text, 250),
            'normalized_signal' => self::shorten($normalized, 250),
            'semantic_group' => sanitize_key((string) $semantic_group),
            'vocabulary_id' => absint($vocabulary_id),
            'weight' => min(500, max(1, absint($weight))),
        );
        if (!isset($signals[$key]) || $candidate['weight'] > absint($signals[$key]['weight'] ?? 0)) {
            $signals[$key] = $candidate;
        }
    }

    public static function stage_product_memory($lesson_key, $rule) {
        global $wpdb;
        self::install();
        $lesson_key = sanitize_key((string) $lesson_key);
        $product_id = absint($rule['product_id'] ?? 0);
        if (!$lesson_key || !$product_id) {
            return 0;
        }
        $inserted = 0;
        foreach ((array) ($rule['signals'] ?? array()) as $signal) {
            $normalized = self::normalize($signal['normalized_signal'] ?? '');
            if (!$normalized) continue;
            $hash = hash('sha256', implode('|', array(
                $lesson_key,
                $product_id,
                sanitize_key((string) ($signal['signal_type'] ?? '')),
                $normalized,
                sanitize_key((string) ($signal['semantic_group'] ?? '')),
                absint($signal['vocabulary_id'] ?? 0),
            )));
            $ok = $wpdb->replace(self::table(), array(
                'lesson_key' => $lesson_key,
                'product_id' => $product_id,
                'signal_type' => sanitize_key((string) ($signal['signal_type'] ?? 'term')),
                'signal_text' => self::shorten((string) ($signal['signal_text'] ?? $normalized), 250),
                'normalized_signal' => self::shorten($normalized, 250),
                'semantic_group' => sanitize_key((string) ($signal['semantic_group'] ?? '')),
                'vocabulary_id' => absint($signal['vocabulary_id'] ?? 0) ?: null,
                'weight' => min(500, max(1, absint($signal['weight'] ?? 50))),
                'source_hash' => $hash,
                'metadata' => wp_json_encode(array('lesson'=>self::LESSON_KEY,'version'=>self::VERSION), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'active' => 0,
                'updated_at' => current_time('mysql'),
            ), array('%s','%d','%s','%s','%s','%s','%d','%d','%s','%s','%d','%s'));
            if (false !== $ok) $inserted++;
        }
        return $inserted;
    }

    public static function clear_stage($lesson_key) {
        global $wpdb;
        self::install();
        $lesson_key = sanitize_key((string) $lesson_key);
        if (!$lesson_key) return 0;
        return max(0, (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::table() . ' WHERE lesson_key=%s AND active=0',
            $lesson_key
        )));
    }

    public static function activate_stage($lesson_key) {
        global $wpdb;
        self::install();
        $lesson_key = sanitize_key((string) $lesson_key);
        if (!$lesson_key) return 0;
        return max(0, (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET active=1, updated_at=%s WHERE lesson_key=%s AND active=0',
            current_time('mysql'),
            $lesson_key
        )));
    }

    public static function set_classroom_lesson($lesson_key = '') {
        self::$classroom_lesson = sanitize_key((string) $lesson_key);
    }

    /**
     * Devuelve candidatos de memoria para V3. Una coincidencia aislada con
     * "herramienta", "grifo", una marca o una categoría no es suficiente:
     * hacen falta dos señales lexicalmente distintas, salvo una identidad de
     * título/frase fuerte.
     */
    public static function match_query($query, $limit = 320) {
        global $wpdb;
        self::install();
        $normalized = self::normalize($query);
        if (!$normalized) return array();

        $terms = self::query_candidates($normalized);
        if (!$terms) return array();
        $placeholders = implode(',', array_fill(0, count($terms), '%s'));
        $params = $terms;
        $where = 'active=1';
        if (self::$classroom_lesson) {
            $where = '(active=1 OR (active=0 AND lesson_key=%s))';
            array_unshift($params, self::$classroom_lesson);
        }
        $sql = 'SELECT product_id,signal_type,signal_text,normalized_signal,semantic_group,weight FROM ' . self::table()
             . " WHERE {$where} AND normalized_signal IN ({$placeholders}) ORDER BY weight DESC LIMIT 7000";
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (!$rows) return array();

        $products = array();
        foreach ($rows as $row) {
            $id = absint($row['product_id'] ?? 0);
            $signal = self::normalize($row['normalized_signal'] ?? '');
            if (!$id || !$signal) continue;
            if (!isset($products[$id])) {
                $products[$id] = array(
                    'product_id'=>$id,
                    'score'=>0,
                    'matched_signals'=>array(),
                    'signal_types'=>array(),
                    'distinct'=>array(),
                    'concept_scores'=>array(),
                    'strong_distinct'=>array(),
                    'phrase_distinct'=>array(),
                );
            }
            $type = sanitize_key((string) ($row['signal_type'] ?? ''));
            $weight = absint($row['weight'] ?? 0);
            $origin = self::normalize((string) ($row['signal_text'] ?? '')) ?: $signal;
            $semantic_group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            $concept_key = ($semantic_group ?: $type) . '|' . $origin;
            $dedupe = $type . '|' . $signal;
            if (isset($products[$id]['matched_signals'][$dedupe])) continue;
            $products[$id]['matched_signals'][$dedupe] = array('type'=>$type,'signal'=>$signal,'origin'=>$origin,'weight'=>$weight);
            $products[$id]['signal_types'][$type] = true;
            $products[$id]['distinct'][$concept_key] = true;
            $previous_weight = absint($products[$id]['concept_scores'][$concept_key] ?? 0);
            if ($weight > $previous_weight) {
                $products[$id]['score'] += ($weight - $previous_weight);
                $products[$id]['concept_scores'][$concept_key] = $weight;
            }
            if ($weight >= 90) $products[$id]['strong_distinct'][$concept_key] = true;
            if (false !== strpos($signal, ' ')) $products[$id]['phrase_distinct'][$concept_key] = true;
        }

        foreach ($products as &$item) {
            $item['matched_count'] = count($item['distinct']);
            $item['type_count'] = count($item['signal_types']);
            $item['strong_hits'] = count($item['strong_distinct']);
            $item['phrase_hits'] = count($item['phrase_distinct']);
            if ($item['matched_count'] >= 2) {
                $item['score'] += min(300, 70 * ($item['matched_count'] - 1));
            }
            $item['matched_signals'] = array_values($item['matched_signals']);
            unset($item['signal_types'], $item['distinct'], $item['concept_scores'], $item['strong_distinct'], $item['phrase_distinct']);
        }
        unset($item);

        $products = array_filter($products, static function ($item) {
            $distinct = absint($item['matched_count'] ?? 0);
            $score = absint($item['score'] ?? 0);
            // Una frase fuerte exacta (normalmente título/TIPO compuesto) puede
            // identificar por sí misma; el resto exige dos evidencias distintas.
            if ($distinct >= 1 && absint($item['phrase_hits'] ?? 0) >= 1 && $score >= 220) {
                return true;
            }
            return $distinct >= 2 && $score >= 180;
        });

        uasort($products, static function ($a, $b) {
            $cmp = absint($b['matched_count'] ?? 0) <=> absint($a['matched_count'] ?? 0);
            if ($cmp) return $cmp;
            $cmp = absint($b['type_count'] ?? 0) <=> absint($a['type_count'] ?? 0);
            if ($cmp) return $cmp;
            return absint($b['score'] ?? 0) <=> absint($a['score'] ?? 0);
        });
        return array_slice($products, 0, min(500, max(1, absint($limit))), true);
    }

    private static function query_candidates($normalized) {
        $out = array();
        if (class_exists('SEO_Dependiente_V3_DB')) {
            $out = array_merge($out, SEO_Dependiente_V3_DB::ngrams($normalized, 5));
        } else {
            $out[] = $normalized;
        }
        $compact = self::without_stopwords($normalized);
        if ($compact) {
            $out[] = $compact;
            if (class_exists('SEO_Dependiente_V3_DB')) {
                $out = array_merge($out, SEO_Dependiente_V3_DB::ngrams($compact, 5));
            }
        }
        foreach (array_filter(explode(' ', $compact ?: $normalized)) as $token) {
            if (strlen($token) < 3) continue;
            $variants = class_exists('SEO_Dependiente_V3_DB') ? SEO_Dependiente_V3_DB::token_variants($token) : array($token);
            $out = array_merge($out, (array) $variants);
        }
        $out = array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize'), $out), static function ($value) {
            return strlen((string) $value) >= 3;
        })));
        return array_slice($out, 0, 120);
    }

    public static function export_memory() {
        global $wpdb;
        self::install();
        $rows = (array) $wpdb->get_results(
            'SELECT lesson_key,product_id,signal_type,signal_text,normalized_signal,semantic_group,vocabulary_id,weight,source_hash,metadata,active '
            . 'FROM ' . self::table() . ' WHERE active=1 ORDER BY product_id,id',
            ARRAY_A
        );
        return array_values($rows);
    }

    public static function import_memory($rows) {
        global $wpdb;
        self::install();
        $result = array('inserted'=>0,'identical'=>0,'ignored'=>0);
        foreach ((array) $rows as $row) {
            if (!is_array($row)) { $result['ignored']++; continue; }
            $product_id = absint($row['product_id'] ?? 0);
            $signal = self::normalize($row['normalized_signal'] ?? '');
            if (!$product_id || !$signal) { $result['ignored']++; continue; }
            $lesson_key = sanitize_key((string) ($row['lesson_key'] ?? self::LESSON_KEY)) ?: self::LESSON_KEY;
            $hash = sanitize_text_field((string) ($row['source_hash'] ?? ''));
            if (!preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                $hash = hash('sha256', $lesson_key . '|' . $product_id . '|' . sanitize_key((string) ($row['signal_type'] ?? '')) . '|' . $signal);
            }
            $existing = absint($wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::table() . ' WHERE lesson_key=%s AND product_id=%d AND source_hash=%s LIMIT 1',
                $lesson_key, $product_id, $hash
            )));
            if ($existing) { $result['identical']++; continue; }
            $ok = $wpdb->insert(self::table(), array(
                'lesson_key'=>$lesson_key,
                'product_id'=>$product_id,
                'signal_type'=>sanitize_key((string) ($row['signal_type'] ?? 'term')),
                'signal_text'=>self::shorten((string) ($row['signal_text'] ?? $signal),250),
                'normalized_signal'=>self::shorten($signal,250),
                'semantic_group'=>sanitize_key((string) ($row['semantic_group'] ?? '')),
                'vocabulary_id'=>absint($row['vocabulary_id'] ?? 0) ?: null,
                'weight'=>min(500,max(1,absint($row['weight'] ?? 50))),
                'source_hash'=>$hash,
                'metadata'=>is_string($row['metadata'] ?? null) ? (string) $row['metadata'] : wp_json_encode((array) ($row['metadata'] ?? array())),
                'active'=>1,
                'updated_at'=>current_time('mysql'),
            ));
            if (false === $ok) { $result['ignored']++; } else { $result['inserted']++; }
        }
        return $result;
    }

    public static function active_count() {
        global $wpdb;
        self::install();
        return absint($wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . ' WHERE active=1'));
    }

    public static function refresh_product_memory($product_id) {
        $product_id = absint($product_id);
        if (!$product_id || !self::lesson_completed()) return;
        self::refresh_product_from_index($product_id);
    }

    public static function late_refresh_product_memory($post_id, $post = null, $update = false) {
        if (wp_is_post_revision($post_id)) return;
        self::refresh_product_memory($post_id);
    }

    public static function delete_product_memory($product_id) {
        global $wpdb;
        $product_id = absint($product_id);
        if (!$product_id) return;
        self::install();
        $wpdb->delete(self::table(), array('product_id'=>$product_id));
    }

    public static function sync_product_status($new_status, $old_status, $post) {
        if (!$post instanceof WP_Post || 'product' !== $post->post_type) return;
        if ('publish' !== $new_status) {
            self::delete_product_memory($post->ID);
        } elseif ('publish' !== $old_status) {
            self::refresh_product_memory($post->ID);
        }
    }

    private static function refresh_product_from_index($product_id) {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Index') || !SEO_Dependiente_Index::table_exists()) return;
        $raw = $wpdb->get_row($wpdb->prepare(
            'SELECT product_id,title,excerpt,brand_name,categories_json,tags_json,vocabulary_json,attributes_json,search_text FROM ' . SEO_Dependiente_Index::table() . ' WHERE product_id=%d LIMIT 1',
            $product_id
        ), ARRAY_A);
        if (!$raw) return;
        $row = SEO_Dependiente_Index::decode_row($raw);
        $item = self::build_curriculum_item($row);
        if (!$item) return;
        $rules = (array) ($item['rules'] ?? array());
        if (empty($rules[0])) return;
        // Reemplaza solo la memoria de este producto y la activa directamente:
        // L9 ya fue superada, por tanto estas señales son mantenimiento del aula.
        $wpdb->delete(self::table(), array('lesson_key'=>self::LESSON_KEY,'product_id'=>$product_id));
        self::stage_product_memory(self::LESSON_KEY, $rules[0]);
        $wpdb->update(self::table(), array('active'=>1,'updated_at'=>current_time('mysql')), array('lesson_key'=>self::LESSON_KEY,'product_id'=>$product_id));
    }

    private static function lesson_completed() {
        global $wpdb;
        if (!class_exists('SEO_Dependiente_Entrenador') || !SEO_Dependiente_Entrenador::ensure_ready()) return false;
        $table = SEO_Dependiente_Entrenador::lessons_table();
        return 'completed' === (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE lesson_key=%s LIMIT 1",
            self::LESSON_KEY
        ));
    }

    private static function first_vocabulary_feature($row, $group) {
        $vocabulary = is_array($row['vocabulary'] ?? null) ? $row['vocabulary'] : self::decode_json($row['vocabulary_json'] ?? '');
        foreach ((array) ($vocabulary[$group] ?? array()) as $term) {
            if (!is_array($term)) continue;
            $label = trim((string) ($term['label'] ?? $term['name'] ?? ''));
            $slug = sanitize_title((string) ($term['slug'] ?? $label));
            if ($label && $slug) {
                return array('kind'=>'vocabulary','group'=>sanitize_key($group),'slug'=>$slug,'label'=>$label);
            }
        }
        return array();
    }

    private static function first_tag_feature($row) {
        $tags = is_array($row['tags'] ?? null) ? $row['tags'] : self::decode_json($row['tags_json'] ?? '');
        foreach ((array) $tags as $tag) {
            $label = is_array($tag) ? trim((string) ($tag['name'] ?? $tag['label'] ?? '')) : trim((string) $tag);
            $slug = is_array($tag) ? sanitize_title((string) ($tag['slug'] ?? $label)) : sanitize_title($label);
            if ($label && $slug && self::useful_text($label)) {
                return array('kind'=>'tag','slug'=>$slug,'label'=>$label);
            }
        }
        return array();
    }

    private static function first_attribute_feature($row) {
        $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : self::decode_json($row['attributes_json'] ?? '');
        foreach ((array) $attributes as $attribute) {
            if (!is_array($attribute)) continue;
            $label = trim((string) ($attribute['label'] ?? $attribute['name'] ?? $attribute['key'] ?? ''));
            $key = self::normalize((string) ($attribute['key'] ?? $label));
            foreach ((array) ($attribute['values'] ?? array()) as $value) {
                $value = trim((string) $value);
                if ($label && $value) {
                    return array('kind'=>'attribute','key'=>$key,'label'=>$label,'value'=>$value);
                }
            }
        }
        return array();
    }

    private static function feature_text($feature) {
        if ('attribute' === sanitize_key((string) ($feature['kind'] ?? ''))) {
            return trim((string) ($feature['label'] ?? '') . ' ' . (string) ($feature['value'] ?? ''));
        }
        return trim((string) ($feature['label'] ?? ''));
    }

    private static function description_phrases($excerpt) {
        $normalized = self::normalize(html_entity_decode(wp_strip_all_tags((string) $excerpt), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8'));
        if (!$normalized) return array();
        $blocked = array('envio','pago','seguro','devolucion','garantia','iva','incluido','comprar','producto','referencia','precio','oferta','anadir','carrito','posventa','fabricante','distribuidor','atencion','castellano','comprobar','revisar','antes','decidir','condiciones','instalacion','relevantes');
        $tokens = array_values(array_filter(explode(' ', $normalized), static function ($word) use ($blocked) {
            return strlen((string) $word) >= 4 && !in_array($word, $blocked, true) && !ctype_digit((string) $word);
        }));
        $phrases = array();
        for ($i=0; $i+2<count($tokens) && count($phrases)<2; $i+=3) {
            $phrases[] = implode(' ', array_slice($tokens, $i, min(4, count($tokens)-$i)));
        }
        return array_values(array_unique(array_filter($phrases)));
    }

    private static function useful_text($text) {
        $normalized = self::normalize($text);
        if (!$normalized || strlen($normalized) < 3 || is_numeric(str_replace(array('.','-',' '), '', $normalized))) return false;
        return !in_array($normalized, array('oferta','producto','productos','nuevo','nueva','herramienta','herramientas'), true);
    }

    private static function without_stopwords($text) {
        $stop = array('a','al','de','del','el','la','las','los','un','una','unos','unas','y','o','para','por','con','sin','sobre','que','quiero','necesito','busco','buscar','algo','producto','productos');
        $words = array_values(array_filter(explode(' ', self::normalize($text)), static function ($word) use ($stop) {
            return !in_array($word, $stop, true);
        }));
        return implode(' ', $words);
    }

    private static function normalize($value) {
        if (class_exists('SEO_Dependiente_V3_DB')) {
            return SEO_Dependiente_V3_DB::normalize((string) $value);
        }
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::normalize((string) $value);
        }
        return sanitize_title((string) $value);
    }

    private static function decode_json($value) {
        if (is_array($value)) return $value;
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function shorten($value, $length) {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        if (function_exists('mb_substr')) return mb_substr($value, 0, absint($length), 'UTF-8');
        return substr($value, 0, absint($length));
    }
}
