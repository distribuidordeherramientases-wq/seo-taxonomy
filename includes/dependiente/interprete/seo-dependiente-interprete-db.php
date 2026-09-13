<?php

defined('ABSPATH') || exit;

/**
 * Memoria linguistica del Interprete.
 *
 * Esta tabla no sustituye seo_vocabulary. El vocabulario sigue describiendo
 * conceptos canonicos del catalogo; el lexico del Interprete describe como
 * puede expresarlos un cliente (nombre, verbo, sinonimo o frase natural).
 */
final class SEO_Dependiente_Interprete_DB {
    const SCHEMA_VERSION = '0.2.0';
    const SCHEMA_OPTION  = 'seo_dependiente_interprete_schema_version';

    private static $rows_cache = null;
    private static $table_exists = null;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_interprete_lexicon';
    }

    public static function table_exists() {
        global $wpdb;

        if (null !== self::$table_exists) {
            return self::$table_exists;
        }

        $table = self::table();
        self::$table_exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;

        return self::$table_exists;
    }

    /**
     * Crea/reconcilia la tabla y carga la primera leccion linguistica.
     * Es una migracion de esquema; no inicia ningun proceso de entrenamiento.
     */
    public static function install() {
        global $wpdb;

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            expression VARCHAR(191) NOT NULL,
            normalized_expression VARCHAR(191) NOT NULL,
            canonical_term VARCHAR(191) NOT NULL,
            target_search VARCHAR(255) NOT NULL,
            relation_type VARCHAR(40) NOT NULL DEFAULT 'synonym',
            semantic_group VARCHAR(100) NULL,
            vocabulary_id BIGINT UNSIGNED NULL,
            context_terms LONGTEXT NULL,
            context_required TINYINT(1) NOT NULL DEFAULT 0,
            confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
            priority TINYINT UNSIGNED NOT NULL DEFAULT 5,
            language VARCHAR(10) NOT NULL DEFAULT 'es',
            source VARCHAR(80) NOT NULL DEFAULT 'interpreter',
            lesson_key VARCHAR(80) NOT NULL DEFAULT '',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY expression_target (normalized_expression, canonical_term, relation_type),
            KEY canonical_term (canonical_term),
            KEY vocabulary_id (vocabulary_id),
            KEY lesson_active (lesson_key, active),
            KEY language_active (language, active),
            KEY priority_active (priority, active)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        self::$table_exists = true;

        self::seed_i1_subject_action();
        self::resolve_vocabulary_ids();
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        self::$rows_cache = null;
    }

    /**
     * Primera leccion: sujeto <-> verbo <-> sinonimo.
     * Solo incluye equivalencias conservadoras y de alta confianza.
     */
    private static function seed_i1_subject_action() {
        $rows = array(
            // Extractores: los verbos amplios exigen un contexto mecanico conocido.
            array('extractor', 'extractor', 'extractor', 'noun', '', array(), 0, 1.00, 1),
            array('extractores', 'extractor', 'extractor', 'noun', '', array(), 0, 1.00, 1),
            array('extraer', 'extractor', 'extractor', 'verb_to_tool', '', array('rodamiento','rodamientos','cojinete','cojinetes','polea','poleas','engranaje','engranajes','rotula','rotulas'), 1, 0.98, 2),
            array('sacar', 'extractor', 'extractor', 'verb_to_tool', '', array('rodamiento','rodamientos','cojinete','cojinetes','polea','poleas','engranaje','engranajes','rotula','rotulas'), 1, 0.96, 3),
            array('retirar', 'extractor', 'extractor', 'verb_to_tool', '', array('rodamiento','rodamientos','cojinete','cojinetes','polea','poleas','engranaje','engranajes','rotula','rotulas'), 1, 0.95, 3),

            // Taladro: distintas formas de expresar la accion principal.
            array('taladro', 'taladro', 'taladro', 'noun', '', array(), 0, 1.00, 1),
            array('taladros', 'taladro', 'taladro', 'noun', '', array(), 0, 1.00, 1),
            array('taladrar', 'taladro', 'taladro', 'verb_to_tool', '', array(), 0, 0.99, 2),
            array('perforar', 'taladro', 'taladro', 'verb_to_tool', '', array(), 0, 0.96, 3),
            array('agujerear', 'taladro', 'taladro', 'verb_to_tool', '', array(), 0, 0.98, 2),
            array('hacer un agujero', 'taladro', 'taladro', 'phrase_to_tool', '', array(), 0, 0.97, 2),
            array('hacer agujeros', 'taladro', 'taladro', 'phrase_to_tool', '', array(), 0, 0.97, 2),

            // Otras relaciones accion -> herramienta con baja ambiguedad.
            array('remachar', 'remachadora', 'remachadora', 'verb_to_tool', '', array(), 0, 0.99, 2),
            array('soldar', 'soldadora', 'soldadora', 'verb_to_tool', '', array(), 0, 0.96, 3),
            array('lijar', 'lijadora', 'lijadora', 'verb_to_tool', '', array(), 0, 0.98, 2),
            array('grapar', 'grapadora', 'grapadora', 'verb_to_tool', '', array(), 0, 0.98, 2),
            array('desbrozar', 'desbrozadora', 'desbrozadora', 'verb_to_tool', '', array(), 0, 0.99, 2),
        );

        foreach ($rows as $row) {
            self::upsert_row(array(
                'expression'       => $row[0],
                'canonical_term'   => $row[1],
                'target_search'    => $row[2],
                'relation_type'    => $row[3],
                'semantic_group'   => $row[4],
                'context_terms'    => $row[5],
                'context_required' => $row[6],
                'confidence'       => $row[7],
                'priority'         => $row[8],
                'source'           => 'interpreter_seed',
                'lesson_key'       => 'i1_subject_action',
                'active'           => 1,
            ));
        }
    }

    private static function upsert_row($row) {
        global $wpdb;

        if (!self::table_exists()) {
            return false;
        }

        $expression = self::clean_text($row['expression'] ?? '');
        $normalized = self::normalize($expression);
        $canonical = self::clean_text($row['canonical_term'] ?? '');
        $target = self::clean_text($row['target_search'] ?? $canonical);
        $relation = sanitize_key((string) ($row['relation_type'] ?? 'synonym'));

        if ('' === $normalized || '' === $canonical || '' === $target || '' === $relation) {
            return false;
        }

        $context_terms = array_values(array_unique(array_filter(array_map(
            array(__CLASS__, 'normalize'),
            (array) ($row['context_terms'] ?? array())
        ))));

        $data = array(
            'expression'            => $expression,
            'normalized_expression' => $normalized,
            'canonical_term'        => $canonical,
            'target_search'         => $target,
            'relation_type'         => $relation,
            'semantic_group'        => sanitize_key((string) ($row['semantic_group'] ?? '')),
            'vocabulary_id'         => !empty($row['vocabulary_id']) ? absint($row['vocabulary_id']) : null,
            'context_terms'         => wp_json_encode($context_terms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'context_required'      => !empty($row['context_required']) ? 1 : 0,
            'confidence'            => min(1, max(0, (float) ($row['confidence'] ?? 1))),
            'priority'              => min(255, max(1, absint($row['priority'] ?? 5))),
            'language'              => sanitize_key((string) ($row['language'] ?? 'es')) ?: 'es',
            'source'                => sanitize_key((string) ($row['source'] ?? 'interpreter')) ?: 'interpreter',
            'lesson_key'            => sanitize_key((string) ($row['lesson_key'] ?? '')),
            'active'                => array_key_exists('active', $row) && empty($row['active']) ? 0 : 1,
        );

        $table = self::table();
        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE normalized_expression = %s AND canonical_term = %s AND relation_type = %s LIMIT 1",
            $normalized,
            $canonical,
            $relation
        ));

        if ($existing_id) {
            $wpdb->update($table, $data, array('id' => $existing_id));
        } else {
            $wpdb->insert($table, $data);
        }

        self::$rows_cache = null;
        return true;
    }

    /**
     * Vincula conceptos al vocabulario canonico cuando existe una coincidencia
     * exacta por slug/label. La ausencia de enlace no invalida el lexico.
     */
    private static function resolve_vocabulary_ids() {
        global $wpdb;

        if (!self::table_exists()) {
            return;
        }

        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($vocabulary))
        ) === $vocabulary;
        if (!$exists) {
            return;
        }

        $table = self::table();
        $rows = $wpdb->get_results(
            "SELECT id, canonical_term FROM {$table} WHERE active = 1 AND (vocabulary_id IS NULL OR vocabulary_id = 0)",
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $canonical = self::clean_text($row['canonical_term'] ?? '');
            if ('' === $canonical) {
                continue;
            }
            $slug = sanitize_title($canonical);
            $match = $wpdb->get_row($wpdb->prepare(
                "SELECT id, semantic_group FROM {$vocabulary} WHERE active = 1 AND (slug = %s OR LOWER(label) = LOWER(%s)) ORDER BY id ASC LIMIT 1",
                $slug,
                $canonical
            ), ARRAY_A);
            if (!$match) {
                continue;
            }
            $wpdb->update(
                $table,
                array(
                    'vocabulary_id' => absint($match['id']),
                    'semantic_group' => sanitize_key((string) ($match['semantic_group'] ?? '')),
                ),
                array('id' => absint($row['id']))
            );
        }

        self::$rows_cache = null;
    }

    /**
     * Devuelve reglas activas que aparecen literalmente en la consulta
     * normalizada, ordenadas desde la expresion mas especifica.
     */
    public static function matching_rows($normalized_query) {
        $normalized_query = self::normalize($normalized_query);
        if ('' === $normalized_query || !self::table_exists()) {
            return array();
        }

        $matches = array();
        foreach (self::active_rows() as $row) {
            $expression = (string) ($row['normalized_expression'] ?? '');
            if ('' === $expression) {
                continue;
            }
            if (false === strpos(' ' . $normalized_query . ' ', ' ' . $expression . ' ')) {
                continue;
            }

            $context_terms = json_decode((string) ($row['context_terms'] ?? ''), true);
            $context_terms = is_array($context_terms) ? array_values(array_filter(array_map(array(__CLASS__, 'normalize'), $context_terms))) : array();
            $matched_context = array();
            foreach ($context_terms as $context_term) {
                if (false !== strpos(' ' . $normalized_query . ' ', ' ' . $context_term . ' ')) {
                    $matched_context[] = $context_term;
                }
            }

            if (!empty($row['context_required']) && !$matched_context) {
                continue;
            }

            $row['matched_context'] = $matched_context;
            $matches[] = $row;
        }

        usort($matches, static function ($a, $b) {
            $len_cmp = strlen((string) ($b['normalized_expression'] ?? '')) <=> strlen((string) ($a['normalized_expression'] ?? ''));
            if (0 !== $len_cmp) {
                return $len_cmp;
            }
            $priority_cmp = (int) ($a['priority'] ?? 5) <=> (int) ($b['priority'] ?? 5);
            if (0 !== $priority_cmp) {
                return $priority_cmp;
            }
            return (float) ($b['confidence'] ?? 0) <=> (float) ($a['confidence'] ?? 0);
        });

        return $matches;
    }

    private static function active_rows() {
        global $wpdb;

        if (is_array(self::$rows_cache)) {
            return self::$rows_cache;
        }
        if (!self::table_exists()) {
            self::$rows_cache = array();
            return self::$rows_cache;
        }

        self::$rows_cache = (array) $wpdb->get_results(
            "SELECT * FROM " . self::table() . " WHERE active = 1 AND language = 'es' ORDER BY priority ASC, id ASC",
            ARRAY_A
        );
        return self::$rows_cache;
    }

    public static function stats() {
        global $wpdb;

        $stats = array(
            'ready' => self::table_exists(),
            'active' => 0,
            'lesson_i1' => 0,
            'linked_vocabulary' => 0,
        );
        if (!$stats['ready']) {
            return $stats;
        }

        $table = self::table();
        $stats['active'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active = 1");
        $stats['lesson_i1'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active = 1 AND lesson_key = 'i1_subject_action'");
        $stats['linked_vocabulary'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active = 1 AND vocabulary_id IS NOT NULL AND vocabulary_id > 0");
        return $stats;
    }

    private static function clean_text($value) {
        $value = sanitize_text_field((string) $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private static function normalize($value) {
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::normalize((string) $value);
        }
        $value = function_exists('remove_accents') ? remove_accents((string) $value) : (string) $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
