<?php

defined('ABSPATH') || exit;

/**
 * Reinicio controlado del conocimiento operativo de Dependiente.
 *
 * El reset esta pensado para staging y para ciclos de entrenamiento. Elimina
 * todo lo generado durante pruebas/aprendizaje, pero conserva las reglas seed
 * del motor y las fuentes maestras del catalogo (productos, etiquetas,
 * vocabulario y atributos SEO).
 */
final class SEO_Dependiente_Reset {
    const LOCK_KEY = 'seo_dependiente_knowledge_reset_lock';
    const LOCK_TTL = 120;

    public static function is_locked() {
        return (bool) get_transient(self::LOCK_KEY);
    }

    public static function preview() {
        global $wpdb;

        $tables = self::tables();
        $counts = array(
            'index'             => self::count_rows($tables['index']),
            'search_log'        => self::count_rows($tables['search_log']),
            'trainer_questions' => self::count_rows($tables['trainer_questions']),
            'trainer_runs'      => self::count_rows($tables['trainer_runs']),
            'trainer_lessons'   => self::count_rows($tables['trainer_lessons']),
            'semantic_seed'     => 0,
            'semantic_reset'    => 0,
        );

        if (self::table_exists($tables['semantics'])) {
            $counts['semantic_seed'] = absint($wpdb->get_var(
                "SELECT COUNT(*) FROM `" . esc_sql($tables['semantics']) . "` WHERE source = 'seed'"
            ));
            $counts['semantic_reset'] = absint($wpdb->get_var(
                "SELECT COUNT(*) FROM `" . esc_sql($tables['semantics']) . "` WHERE source <> 'seed' OR source IS NULL"
            ));
        }

        return $counts;
    }

    public static function reset() {
        global $wpdb;

        if (self::is_locked()) {
            return new WP_Error('seo_dependiente_reset_locked', 'Ya hay un reinicio de conocimiento en curso. Espera unos segundos y vuelve a intentarlo.');
        }

        set_transient(self::LOCK_KEY, 1, self::LOCK_TTL);
        $before = self::preview();
        $tables = self::tables();
        $transaction_started = false;

        try {
            // Evita que un indice en segundo plano vuelva a poblar la tabla justo
            // despues del reset. La reindexacion se hara manualmente cuando toque.
            wp_clear_scheduled_hook('seo_dependiente_background_index');
            if (class_exists('SEO_Dependiente_Entrenador')) {
                SEO_Dependiente_Entrenador::reset_automation_state();
            }
            delete_option('seo_dependiente_background_page');
            delete_option('seo_dependiente_last_full_index');
            delete_option('seo_dependiente_knowledge_snapshot');

            if (false === $wpdb->query('START TRANSACTION')) {
                throw new RuntimeException('No se pudo iniciar la transaccion de reinicio.');
            }
            $transaction_started = true;

            self::delete_all($tables['trainer_runs']);
            self::delete_all($tables['trainer_questions']);
            self::delete_all($tables['trainer_lessons']);
            self::delete_all($tables['search_log']);

            // Las reglas seed son el baseline versionado del motor. Todo lo
            // manual, aprendido, candidato o rechazado se elimina.
            if (self::table_exists($tables['semantics'])) {
                $sql = "DELETE FROM `" . esc_sql($tables['semantics']) . "` WHERE source <> 'seed' OR source IS NULL";
                if (false === $wpdb->query($sql)) {
                    throw new RuntimeException('No se pudo limpiar la semantica aprendida.');
                }
            }

            // El indice es una copia derivada del catalogo. Se vacia para que la
            // siguiente reindexacion nazca de las etiquetas/vocabulario actuales.
            self::delete_all($tables['index']);

            if (false === $wpdb->query('COMMIT')) {
                throw new RuntimeException('No se pudo confirmar el reinicio.');
            }
            $transaction_started = false;

            // Fuerza a que la capa semantica vuelva a validar el baseline en la
            // siguiente consulta, sin tocar la version ni recrear reglas aprendidas.
            if (class_exists('SEO_Dependiente_Semantics')) {
                SEO_Dependiente_Semantics::ensure_ready();
            }

            $after = self::preview();
            return array(
                'before' => $before,
                'after'  => $after,
                'message'=> 'Conocimiento reiniciado. Se han conservado las reglas base (seed) y las fuentes maestras del catalogo. La Academia vuelve a la Leccion 1 tras reindexar.',
            );
        } catch (Throwable $error) {
            if ($transaction_started) {
                $wpdb->query('ROLLBACK');
            }
            return new WP_Error('seo_dependiente_reset_failed', $error->getMessage());
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    private static function tables() {
        global $wpdb;

        return array(
            'index'             => class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::table() : $wpdb->prefix . 'seo_dependiente_index',
            'semantics'         => class_exists('SEO_Dependiente_Semantics') ? SEO_Dependiente_Semantics::table() : $wpdb->prefix . 'seo_dependiente_semantics',
            'search_log'        => class_exists('SEO_Dependiente_Search_Log') ? SEO_Dependiente_Search_Log::table() : $wpdb->prefix . 'seo_dependiente_search_log',
            'trainer_questions' => class_exists('SEO_Dependiente_Entrenador') ? SEO_Dependiente_Entrenador::questions_table() : $wpdb->prefix . 'seo_dependiente_trainer_questions',
            'trainer_runs'      => class_exists('SEO_Dependiente_Entrenador') ? SEO_Dependiente_Entrenador::runs_table() : $wpdb->prefix . 'seo_dependiente_trainer_runs',
            'trainer_lessons'   => class_exists('SEO_Dependiente_Entrenador') ? SEO_Dependiente_Entrenador::lessons_table() : $wpdb->prefix . 'seo_dependiente_trainer_lessons',
        );
    }

    private static function count_rows($table) {
        global $wpdb;
        if (!self::table_exists($table)) {
            return 0;
        }
        return absint($wpdb->get_var('SELECT COUNT(*) FROM `' . esc_sql($table) . '`'));
    }

    private static function delete_all($table) {
        global $wpdb;
        if (!self::table_exists($table)) {
            return true;
        }
        if (false === $wpdb->query('DELETE FROM `' . esc_sql($table) . '`')) {
            throw new RuntimeException('No se pudo vaciar la tabla ' . $table . '.');
        }
        return true;
    }

    private static function table_exists($table) {
        global $wpdb;
        $table = (string) $table;
        if (!$table) {
            return false;
        }
        return $table === (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        );
    }
}
