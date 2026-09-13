<?php
/**
 * Cola persistente y adaptativa del Clasificador.
 *
 * Objetivos:
 * - nunca recorrer el catálogo completo dentro de una petición administrativa;
 * - procesar anomalías por lotes pequeños y adaptativos;
 * - persistir propuestas para que la matriz de Asignación solo lea resultados;
 * - permitir pausar, reanudar y cancelar trabajos;
 * - reutilizar caché por producto/grupo cuando el contexto no ha cambiado.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_jobs_schema_version')) {
    function seo_classifier_jobs_schema_version() {
        return '1.1.0';
    }
}

if (!function_exists('seo_classifier_jobs_tables')) {
    function seo_classifier_jobs_tables() {
        global $wpdb;
        return [
            'jobs' => $wpdb->prefix . 'seo_classifier_jobs',
            'items' => $wpdb->prefix . 'seo_classifier_job_items',
            'proposals' => $wpdb->prefix . 'seo_classifier_proposals',
        ];
    }
}

if (!function_exists('seo_classifier_jobs_install_schema')) {
    function seo_classifier_jobs_install_schema() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $tables = seo_classifier_jobs_tables();
        $charset = $wpdb->get_charset_collate();

        $sql_jobs = "CREATE TABLE {$tables['jobs']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_uuid CHAR(36) NOT NULL,
            job_type VARCHAR(24) NOT NULL DEFAULT 'classify',
            mode VARCHAR(24) NOT NULL DEFAULT 'fast',
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            filters_json LONGTEXT NULL,
            force_refresh TINYINT(1) NOT NULL DEFAULT 0,
            total_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
            processed_items BIGINT UNSIGNED NOT NULL DEFAULT 0,
            cache_hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            safe_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            review_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            new_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            unresolved_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            applied_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            skipped_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            error_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            batch_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_batch_rows INT UNSIGNED NOT NULL DEFAULT 0,
            last_batch_duration DECIMAL(12,4) NOT NULL DEFAULT 0,
            last_batch_seconds_per_row DECIMAL(12,4) NOT NULL DEFAULT 0,
            last_batch_memory_ratio DECIMAL(8,4) NOT NULL DEFAULT 0,
            last_batch_query_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_cpu_percent DECIMAL(8,2) NULL,
            last_cpu_cores INT UNSIGNED NOT NULL DEFAULT 0,
            last_batch_time_cutoff TINYINT(1) NOT NULL DEFAULT 0,
            last_batch_memory_cutoff TINYINT(1) NOT NULL DEFAULT 0,
            adaptive_next_batch_size INT UNSIGNED NOT NULL DEFAULT 5,
            adaptive_next_delay INT UNSIGNED NOT NULL DEFAULT 3,
            adaptive_pressure VARCHAR(24) NOT NULL DEFAULT 'baja',
            worker_heartbeat_at DATETIME NULL,
            worker_heartbeat_ts BIGINT UNSIGNED NOT NULL DEFAULT 0,
            worker_runs BIGINT UNSIGNED NOT NULL DEFAULT 0,
            worker_source VARCHAR(32) NOT NULL DEFAULT '',
            last_error TEXT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_uuid (job_uuid),
            KEY status_type (status, job_type),
            KEY created_by (created_by),
            KEY updated_at (updated_at)
        ) {$charset};";

        $sql_items = "CREATE TABLE {$tables['items']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            group_mask INT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            context_hash CHAR(64) NOT NULL DEFAULT '',
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_product (job_id, product_id),
            KEY job_status (job_id, status, id),
            KEY product_id (product_id),
            KEY updated_at (updated_at)
        ) {$charset};";

        $sql_proposals = "CREATE TABLE {$tables['proposals']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            semantic_group VARCHAR(40) NOT NULL,
            state VARCHAR(24) NOT NULL DEFAULT 'unresolved',
            vocabulary_id BIGINT UNSIGNED NULL,
            label VARCHAR(255) NOT NULL DEFAULT '',
            confidence DECIMAL(8,4) NOT NULL DEFAULT 0,
            payload_json LONGTEXT NULL,
            context_hash CHAR(64) NOT NULL DEFAULT '',
            classifier_version VARCHAR(40) NOT NULL DEFAULT '',
            source_job_id BIGINT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_group (product_id, semantic_group),
            KEY state_active (state, active),
            KEY product_active (product_id, active),
            KEY source_job (source_job_id)
        ) {$charset};";

        dbDelta($sql_jobs);
        dbDelta($sql_items);
        dbDelta($sql_proposals);
        update_option('seo_classifier_jobs_schema_version', seo_classifier_jobs_schema_version(), false);
        return true;
    }
}

if (!function_exists('seo_classifier_jobs_maybe_install_schema')) {
    function seo_classifier_jobs_maybe_install_schema() {
        $installed = (string) get_option('seo_classifier_jobs_schema_version', '0');
        if (version_compare($installed, seo_classifier_jobs_schema_version(), '<')) {
            seo_classifier_jobs_install_schema();
        }
    }
    add_action('admin_init', 'seo_classifier_jobs_maybe_install_schema', 2);
}

if (!function_exists('seo_classifier_group_bits')) {
    function seo_classifier_group_bits() {
        return [
            'tipo' => 1,
            'aplicacion' => 2,
            'plataforma' => 4,
            'subtipo' => 8,
            'rol' => 16,
        ];
    }
}

if (!function_exists('seo_classifier_groups_from_mask')) {
    function seo_classifier_groups_from_mask($mask) {
        $mask = absint($mask);
        $groups = [];
        foreach (seo_classifier_group_bits() as $group => $bit) {
            if (($mask & $bit) === $bit) $groups[] = $group;
        }
        return $groups;
    }
}

if (!function_exists('seo_classifier_job_sanitize_filters')) {
    function seo_classifier_job_sanitize_filters(array $filters) {
        $coverage_allowed = ['missing_any','without_any','without_type','without_role','without_application','without_platform','without_subtype'];
        $priority_allowed = ['all','p1','p2','p3','p4','p5'];
        $coverage = sanitize_key((string)($filters['coverage'] ?? 'missing_any'));
        $priority = sanitize_key((string)($filters['priority'] ?? 'all'));
        return [
            'search' => sanitize_text_field((string)($filters['search'] ?? '')),
            'category_id' => absint($filters['category_id'] ?? 0),
            'coverage' => in_array($coverage, $coverage_allowed, true) ? $coverage : 'missing_any',
            'priority' => in_array($priority, $priority_allowed, true) ? $priority : 'all',
        ];
    }
}

if (!function_exists('seo_classifier_job_target_mask')) {
    function seo_classifier_job_target_mask(array $filters) {
        $bits = seo_classifier_group_bits();
        $coverage_map = [
            'without_type' => $bits['tipo'],
            'without_role' => $bits['rol'],
            'without_application' => $bits['aplicacion'],
            'without_platform' => $bits['plataforma'],
            'without_subtype' => $bits['subtipo'],
        ];
        $priority_map = [
            'p1' => $bits['tipo'],
            'p2' => $bits['rol'],
            'p3' => $bits['aplicacion'],
            'p4' => $bits['subtipo'],
            'p5' => $bits['plataforma'],
        ];
        $coverage = (string)($filters['coverage'] ?? 'missing_any');
        $priority = (string)($filters['priority'] ?? 'all');
        if (isset($priority_map[$priority])) return $priority_map[$priority];
        if (isset($coverage_map[$coverage])) return $coverage_map[$coverage];
        return array_sum($bits);
    }
}

if (!function_exists('seo_classifier_job_where_sql')) {
    /**
     * Devuelve SQL/args para localizar anomalías de etiquetas de producto.
     * No permite el estado "completo": esta cola es exclusivamente correctiva.
     */
    function seo_classifier_job_where_sql(array $filters) {
        global $wpdb;
        $filters = seo_classifier_job_sanitize_filters($filters);
        $v = $wpdb->prefix . 'seo_vocabulary';
        $o = $wpdb->prefix . 'seo_object_vocabulary';
        $where = ["p.post_type='product'", "p.post_status='publish'"];
        $args = [];
        $search = trim((string)$filters['search']);
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $clause = "(p.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_sku' AND pm.meta_value LIKE %s)";
            $args[] = $like;
            $args[] = $like;
            if (ctype_digit($search)) {
                $clause .= ' OR p.ID=%d';
                $args[] = (int)$search;
            }
            $where[] = $clause . ')';
        }
        if ($filters['category_id'] > 0) {
            $where[] = "EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat' WHERE tr.object_id=p.ID AND tt.term_id=%d)";
            $args[] = (int)$filters['category_id'];
        }

        $exists = [];
        foreach (['tipo','rol','aplicacion','plataforma','subtipo'] as $group) {
            $exists[$group] = "EXISTS (SELECT 1 FROM {$o} ovq JOIN {$v} vvq ON vvq.id=ovq.vocabulary_id AND vvq.active=1 WHERE ovq.object_type='product' AND ovq.object_id=p.ID AND ovq.status=1 AND vvq.semantic_group='" . esc_sql($group) . "')";
        }
        $all_complete = '(' . implode(' AND ', array_values($exists)) . ')';
        $any_present = '(' . implode(' OR ', array_values($exists)) . ')';
        $group_map = [
            'without_type' => 'tipo',
            'without_role' => 'rol',
            'without_application' => 'aplicacion',
            'without_platform' => 'plataforma',
            'without_subtype' => 'subtipo',
        ];
        $coverage = $filters['coverage'];
        if ($coverage === 'without_any') $where[] = 'NOT ' . $any_present;
        elseif (isset($group_map[$coverage])) $where[] = 'NOT (' . $exists[$group_map[$coverage]] . ')';
        else $where[] = 'NOT ' . $all_complete;

        $priority = $filters['priority'];
        if ($priority === 'p1') $where[] = 'NOT (' . $exists['tipo'] . ')';
        elseif ($priority === 'p2') $where[] = $exists['tipo'] . ' AND NOT (' . $exists['rol'] . ')';
        elseif ($priority === 'p3') $where[] = $exists['tipo'] . ' AND ' . $exists['rol'] . ' AND NOT (' . $exists['aplicacion'] . ')';
        elseif ($priority === 'p4') $where[] = $exists['tipo'] . ' AND ' . $exists['rol'] . ' AND ' . $exists['aplicacion'] . ' AND NOT (' . $exists['subtipo'] . ')';
        elseif ($priority === 'p5') $where[] = $exists['tipo'] . ' AND ' . $exists['rol'] . ' AND ' . $exists['aplicacion'] . ' AND ' . $exists['subtipo'] . ' AND NOT (' . $exists['plataforma'] . ')';

        $missing_mask = '(CASE WHEN NOT (' . $exists['tipo'] . ') THEN 1 ELSE 0 END'
            . ' + CASE WHEN NOT (' . $exists['aplicacion'] . ') THEN 2 ELSE 0 END'
            . ' + CASE WHEN NOT (' . $exists['plataforma'] . ') THEN 4 ELSE 0 END'
            . ' + CASE WHEN NOT (' . $exists['subtipo'] . ') THEN 8 ELSE 0 END'
            . ' + CASE WHEN NOT (' . $exists['rol'] . ') THEN 16 ELSE 0 END)';

        return [
            'where' => $where,
            'args' => $args,
            'exists' => $exists,
            'missing_mask' => $missing_mask,
            'target_mask' => seo_classifier_job_target_mask($filters),
            'filters' => $filters,
        ];
    }
}

if (!function_exists('seo_classifier_job_create')) {
    function seo_classifier_job_create(array $args = []) {
        global $wpdb;
        seo_classifier_jobs_maybe_install_schema();
        $tables = seo_classifier_jobs_tables();
        $job_type = sanitize_key((string)($args['job_type'] ?? 'classify'));
        if (!in_array($job_type, ['classify','apply'], true)) $job_type = 'classify';
        $mode = sanitize_key((string)($args['mode'] ?? 'fast'));
        if (!in_array($mode, ['fast','deep'], true)) $mode = 'fast';
        if ($job_type === 'apply') $mode = 'fast';
        $filters = seo_classifier_job_sanitize_filters((array)($args['filters'] ?? []));
        $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : strtolower(wp_generate_password(32, false, false));
        $initial = $mode === 'deep' ? 1 : 3;
        $inserted = $wpdb->insert($tables['jobs'], [
            'job_uuid' => $uuid,
            'job_type' => $job_type,
            'mode' => $mode,
            'status' => 'pending',
            'filters_json' => wp_json_encode($filters),
            // El modo profundo nunca debe reutilizar una propuesta calculada en modo rápido.
            // Forzamos el recálculo aunque el formulario no envíe explícitamente force_refresh.
            'force_refresh' => ($mode === 'deep' || !empty($args['force_refresh'])) ? 1 : 0,
            'adaptive_next_batch_size' => $initial,
            'adaptive_next_delay' => 2,
            'created_by' => absint($args['created_by'] ?? get_current_user_id()),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['%s','%s','%s','%s','%s','%d','%d','%d','%d','%s','%s']);
        if (!$inserted) return new WP_Error('seo_classifier_job_create_failed', $wpdb->last_error ?: 'No se pudo crear el trabajo del Clasificador.');
        $job_id = (int)$wpdb->insert_id;
        $seed = seo_classifier_job_seed($job_id, $filters);
        if (is_wp_error($seed)) return $seed;
        if ((int)$seed < 1) {
            $wpdb->update($tables['jobs'], [
                'status' => 'completed',
                'completed_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], ['id' => $job_id]);
            return $job_id;
        }
        seo_classifier_job_schedule($job_id, 1);
        return $job_id;
    }
}

if (!function_exists('seo_classifier_job_seed')) {
    function seo_classifier_job_seed($job_id, array $filters = []) {
        global $wpdb;
        $job_id = absint($job_id);
        if ($job_id < 1) return new WP_Error('seo_classifier_job_invalid', 'Trabajo inválido.');
        $tables = seo_classifier_jobs_tables();
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['jobs']} WHERE id=%d", $job_id), ARRAY_A);
        if (!$job) return new WP_Error('seo_classifier_job_missing', 'No existe el trabajo.');
        $parts = seo_classifier_job_where_sql($filters);
        $where = $parts['where'];
        $args = $parts['args'];
        $target_mask = max(1, absint($parts['target_mask']));
        $mask_sql = '((' . $parts['missing_mask'] . ') & ' . $target_mask . ')';
        $where[] = $mask_sql . ' > 0';
        if ((string)$job['job_type'] === 'apply') {
            $where[] = "EXISTS (SELECT 1 FROM {$tables['proposals']} cp WHERE cp.product_id=p.ID AND cp.active=1 AND cp.state='safe')";
        }
        $where_sql = implode(' AND ', $where);
        $sql = "INSERT IGNORE INTO {$tables['items']} (job_id,product_id,group_mask,status,created_at,updated_at)
                SELECT %d,p.ID,{$mask_sql},'pending',NOW(),NOW()
                FROM {$wpdb->posts} p WHERE {$where_sql}";
        $seed_args = array_merge([$job_id], $args);
        $prepared = $wpdb->prepare($sql, $seed_args);
        $result = $wpdb->query($prepared);
        if ($result === false) return new WP_Error('seo_classifier_job_seed_failed', $wpdb->last_error ?: 'No se pudo crear la cola.');
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['items']} WHERE job_id=%d", $job_id));
        $wpdb->update($tables['jobs'], ['total_items'=>$total,'updated_at'=>current_time('mysql')], ['id'=>$job_id], ['%d','%s'], ['%d']);
        return $total;
    }
}

if (!function_exists('seo_classifier_job_get')) {
    function seo_classifier_job_get($job_id) {
        global $wpdb;
        $tables = seo_classifier_jobs_tables();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['jobs']} WHERE id=%d", absint($job_id)), ARRAY_A);
        if (!$row) return null;
        $row['filters'] = json_decode((string)($row['filters_json'] ?? ''), true);
        if (!is_array($row['filters'])) $row['filters'] = [];
        return $row;
    }
}

if (!function_exists('seo_classifier_job_latest')) {
    function seo_classifier_job_latest($created_by = 0) {
        global $wpdb;
        $tables = seo_classifier_jobs_tables();
        $created_by = absint($created_by);
        if ($created_by > 0) {
            $id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['jobs']} WHERE created_by=%d ORDER BY id DESC LIMIT 1", $created_by));
        } else {
            $id = (int)$wpdb->get_var("SELECT id FROM {$tables['jobs']} ORDER BY id DESC LIMIT 1");
        }
        return $id > 0 ? seo_classifier_job_get($id) : null;
    }
}

if (!function_exists('seo_classifier_job_cron_fallback_seconds')) {
    function seo_classifier_job_cron_fallback_seconds() {
        return max(10, (int) apply_filters('seo_classifier_job_cron_fallback_seconds', 15));
    }
}

if (!function_exists('seo_classifier_job_watchdog_seconds')) {
    function seo_classifier_job_watchdog_seconds() {
        return max(20, (int) apply_filters('seo_classifier_job_watchdog_seconds', 45));
    }
}

if (!function_exists('seo_classifier_job_maybe_spawn_cron')) {
    /**
     * Pide a WordPress que despierte WP-Cron sin bloquear la petición.
     * Se limita por job para que el polling de la pantalla no genere una llamada
     * a spawn_cron() cada cinco segundos.
     */
    function seo_classifier_job_maybe_spawn_cron($job_id, $force = false) {
        $job_id = absint($job_id);
        if ($job_id < 1 || (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) || !function_exists('spawn_cron')) return false;
        $key = 'seo_cl_cron_kick_' . $job_id;
        if (!$force && get_transient($key)) return false;
        set_transient($key, 1, 20);
        spawn_cron(time());
        return true;
    }
}

if (!function_exists('seo_classifier_job_manager_due_key')) {
    function seo_classifier_job_manager_due_key($job_id) {
        return 'seo_classifier_manager_due_' . absint($job_id);
    }
}

if (!function_exists('seo_classifier_job_use_process_manager')) {
    /**
     * El Clasificador solo entra en el gestor cuando existe un job que el
     * administrador ha iniciado o reanudado. El gestor nunca crea jobs nuevos.
     */
    function seo_classifier_job_use_process_manager() {
        if (!function_exists('seo_process_supervisor_settings')) return false;
        $settings = (array) seo_process_supervisor_settings();
        return !empty($settings['enabled']) && !empty($settings['classifier']);
    }
}

if (!function_exists('seo_classifier_job_schedule')) {
    /**
     * Programa el worker por dos vías independientes.
     *
     * Action Scheduler es la vía principal, pero no hacemos return temprano si
     * ya hay una acción pendiente: algunos hostings dejan acciones dormidas.
     * Siempre dejamos también un WP-Cron de respaldo y, en peticiones web,
     * intentamos despertarlo de forma no bloqueante.
     */
    function seo_classifier_job_schedule($job_id, $delay = 0, $force = false) {
        $job_id = absint($job_id);
        if ($job_id < 1) return false;
        $delay = max(0, absint($delay));
        $hook = 'seo_classifier_process_job';
        $args = [$job_id];
        $group = 'seo-taxonomy-classifier';
        $scheduled = false;

        // Si el gestor propio está activo, el job ya fue iniciado manualmente y
        // solo pedimos continuidad al gestor. No entregamos el ritmo a
        // Action Scheduler ni a WP-Cron/WooCommerce.
        if (seo_classifier_job_use_process_manager()) {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook, $args, $group);
            }
            wp_clear_scheduled_hook($hook, $args);
            $due = time() + $delay;
            set_transient(seo_classifier_job_manager_due_key($job_id), $due, max(300, $delay + 300));
            if (function_exists('seo_process_supervisor_nudge')) {
                seo_process_supervisor_nudge($delay, 'classifier');
            }
            if (function_exists('seo_process_supervisor_schedule_backup')) {
                seo_process_supervisor_schedule_backup();
            }
            return true;
        }

        $has_action = false;
        if (function_exists('as_has_scheduled_action')) {
            $has_action = (bool) as_has_scheduled_action($hook, $args, $group);
        }

        if (!$has_action || $force) {
            $action_id = 0;
            if ($delay < 2 && function_exists('as_enqueue_async_action')) {
                $action_id = as_enqueue_async_action($hook, $args, $group, false, 10);
            } elseif (function_exists('as_schedule_single_action')) {
                // Dentro del worker force=true permite encadenar el siguiente lote
                // aunque la acción actual siga figurando como "in progress".
                $action_id = as_schedule_single_action(time() + max(1, $delay), $hook, $args, $group, false, 10);
            }
            if (absint($action_id) > 0) $scheduled = true;
        } else {
            $scheduled = true;
        }

        // Respaldo independiente: no dependemos de que Action Scheduler despierte.
        $cron_delay = max(seo_classifier_job_cron_fallback_seconds(), $delay + 5);
        $cron_next = wp_next_scheduled($hook, $args);
        if (!$cron_next) {
            $cron = wp_schedule_single_event(time() + $cron_delay, $hook, $args, true);
            if (!is_wp_error($cron) && $cron) $scheduled = true;
        } else {
            $scheduled = true;
        }

        seo_classifier_job_maybe_spawn_cron($job_id, false);
        return $scheduled;
    }
}

if (!function_exists('seo_classifier_job_scheduler_status')) {
    function seo_classifier_job_scheduler_status($job_id) {
        $job_id = absint($job_id);
        $hook = 'seo_classifier_process_job';
        $args = [$job_id];
        $group = 'seo-taxonomy-classifier';
        $as_available = function_exists('as_schedule_single_action') || function_exists('as_enqueue_async_action');
        $as_pending = false;
        if (function_exists('as_has_scheduled_action')) {
            $as_pending = (bool) as_has_scheduled_action($hook, $args, $group);
        }
        $cron_next = wp_next_scheduled($hook, $args);
        $manager_due = absint(get_transient(seo_classifier_job_manager_due_key($job_id)));
        return [
            'action_scheduler_available'=>$as_available,
            'action_scheduler_pending'=>$as_pending,
            'wp_cron_next'=>$cron_next ? absint($cron_next) : 0,
            'wp_cron_disabled'=>defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'process_manager'=>seo_classifier_job_use_process_manager(),
            'manager_due'=>$manager_due,
        ];
    }
}

if (!function_exists('seo_classifier_job_unschedule')) {
    function seo_classifier_job_unschedule($job_id) {
        $job_id = absint($job_id);
        $hook = 'seo_classifier_process_job';
        $args = [$job_id];
        if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions($hook, $args, 'seo-taxonomy-classifier');
        wp_clear_scheduled_hook($hook, $args);
        delete_transient(seo_classifier_job_manager_due_key($job_id));
    }
}

if (!function_exists('seo_classifier_job_adaptive_config')) {
    function seo_classifier_job_adaptive_config($mode = 'fast') {
        $mode = $mode === 'deep' ? 'deep' : 'fast';
        $config = $mode === 'deep' ? [
            'min_rows'=>1,'initial_rows'=>1,'max_rows'=>2,'target_seconds'=>5.0,'hard_seconds'=>10.0,
            'memory_soft_ratio'=>0.50,'memory_hard_ratio'=>0.65,'cpu_soft_percent'=>65.0,'cpu_hard_percent'=>80.0,
            'growth_factor'=>1.45,'slowdown_factor'=>0.75,'critical_factor'=>0.45,
            'normal_delay'=>10,'heavy_delay'=>40,'critical_delay'=>120,
        ] : [
            'min_rows'=>2,'initial_rows'=>3,'max_rows'=>25,'target_seconds'=>5.0,'hard_seconds'=>10.0,
            'memory_soft_ratio'=>0.52,'memory_hard_ratio'=>0.68,'cpu_soft_percent'=>65.0,'cpu_hard_percent'=>80.0,
            'growth_factor'=>1.45,'slowdown_factor'=>0.75,'critical_factor'=>0.45,
            'normal_delay'=>5,'heavy_delay'=>30,'critical_delay'=>90,
        ];
        return apply_filters('seo_classifier_job_adaptive_config', $config, $mode);
    }
}

if (!function_exists('seo_classifier_job_memory_limit_bytes')) {
    function seo_classifier_job_memory_limit_bytes() {
        $value = trim((string)ini_get('memory_limit'));
        if ($value === '' || $value === '-1') return 0;
        $last = strtolower(substr($value, -1));
        $number = (float)$value;
        if ($last === 'g') $number *= 1024;
        if ($last === 'm' || $last === 'g') $number *= 1024;
        if ($last === 'k' || $last === 'm' || $last === 'g') $number *= 1024;
        return max(0, (int)$number);
    }
}

if (!function_exists('seo_classifier_job_memory_ratio')) {
    function seo_classifier_job_memory_ratio($peak = false) {
        $limit = seo_classifier_job_memory_limit_bytes();
        if ($limit < 1) return 0.0;
        $usage = $peak && function_exists('memory_get_peak_usage') ? memory_get_peak_usage(true) : memory_get_usage(true);
        return max(0.0, min(1.5, $usage / $limit));
    }
}

if (!function_exists('seo_classifier_job_runtime_cpu_metrics')) {
    /**
     * Estima la presión global de CPU con la misma fuente del panel Estado del servidor.
     * Es deliberadamente conservador: si la métrica no está disponible, el job sigue
     * regulándose por tiempo/memoria/consultas sin inventar un porcentaje.
     */
    function seo_classifier_job_runtime_cpu_metrics() {
        $result = ['available'=>false,'percent'=>null,'cores'=>0,'load'=>[]];
        if (function_exists('seo_server_status_runtime_resource_metrics')) {
            $metrics = (array) seo_server_status_runtime_resource_metrics();
            $cpu = (array) ($metrics['cpu'] ?? []);
            if (!empty($cpu['available']) && isset($cpu['percent']) && is_numeric($cpu['percent'])) {
                $result['available'] = true;
                $result['percent'] = max(0.0, (float) $cpu['percent']);
                $result['cores'] = absint($cpu['cores'] ?? 0);
                $result['load'] = array_values((array) ($cpu['load'] ?? []));
            }
        } elseif (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            $cores = function_exists('seo_server_status_detect_cpu_cores') ? absint(seo_server_status_detect_cpu_cores()) : 0;
            if (is_array($load) && isset($load[0]) && $cores > 0) {
                $result['available'] = true;
                $result['percent'] = max(0.0, ((float) $load[0] / max(1, $cores)) * 100.0);
                $result['cores'] = $cores;
                $result['load'] = array_values(array_slice($load, 0, 3));
            }
        }
        return apply_filters('seo_classifier_job_runtime_cpu_metrics', $result);
    }
}

if (!function_exists('seo_classifier_job_adaptive_plan')) {
    function seo_classifier_job_adaptive_plan(array $job) {
        $mode = (string)($job['mode'] ?? 'fast');
        $config = seo_classifier_job_adaptive_config($mode);
        $previous = max(0, absint($job['last_batch_rows'] ?? 0));
        $duration = max(0.0, (float)($job['last_batch_duration'] ?? 0));
        $memory = max(0.0, (float)($job['last_batch_memory_ratio'] ?? 0));
        $time_cutoff = !empty($job['last_batch_time_cutoff']);
        $memory_cutoff = !empty($job['last_batch_memory_cutoff']);
        $cpu = isset($job['last_cpu_percent']) && $job['last_cpu_percent'] !== null ? max(0.0, (float)$job['last_cpu_percent']) : null;
        $next = max((int)$config['min_rows'], absint($job['adaptive_next_batch_size'] ?? $config['initial_rows']));
        $pressure = 'baja';
        $reason = 'arranque conservador';

        if ($previous < 1 || $duration <= 0) {
            $next = (int)$config['initial_rows'];
        } else {
            $seconds_per_row = $duration / max(1, $previous);
            $ideal = (int)floor($config['target_seconds'] / max(0.05, $seconds_per_row));
            $ideal = max((int)$config['min_rows'], min((int)$config['max_rows'], $ideal));
            if ($memory_cutoff || $memory >= $config['memory_hard_ratio'] || $duration >= $config['hard_seconds']) {
                $next = max((int)$config['min_rows'], (int)floor(max(1, $previous) * (float)$config['critical_factor']));
                $pressure = 'critica';
                $reason = 'corte por tiempo/memoria';
            } elseif ($time_cutoff || $memory >= $config['memory_soft_ratio'] || $duration > ($config['target_seconds'] * 1.35)) {
                $next = max((int)$config['min_rows'], min($ideal, (int)floor(max(1, $previous) * (float)$config['slowdown_factor'])));
                $pressure = 'alta';
                $reason = 'lote pesado';
            } else {
                $growth_cap = max($previous + 2, (int)ceil($previous * (float)$config['growth_factor']));
                $next = min($ideal, $growth_cap);
                $pressure = $duration > $config['target_seconds'] ? 'media' : 'baja';
                $reason = 'coste estable';
            }
        }
        $next = max((int)$config['min_rows'], min((int)$config['max_rows'], $next));
        $delay = (int)$config['normal_delay'];
        if ($pressure === 'alta') $delay = (int)$config['heavy_delay'];
        elseif ($pressure === 'critica') $delay = (int)$config['critical_delay'];

        // La presión global del servidor siempre manda sobre el crecimiento calculado
        // a partir del coste interno del Clasificador.
        if ($cpu !== null && $cpu >= (float)$config['cpu_hard_percent']) {
            $next = 1;
            $delay = max($delay, (int)$config['critical_delay']);
            $pressure = 'cpu_critica';
            $reason = 'CPU global por encima del umbral duro';
        } elseif ($cpu !== null && $cpu >= (float)$config['cpu_soft_percent']) {
            $next = min($next, $mode === 'deep' ? 1 : 2);
            $delay = max($delay, (int)$config['heavy_delay']);
            $pressure = 'cpu_alta';
            $reason = 'CPU global elevada';
        }
        return ['batch_size'=>$next,'delay'=>$delay,'pressure'=>$pressure,'reason'=>$reason,'config'=>$config];
    }
}

if (!function_exists('seo_classifier_heavy_workload_active')) {
    function seo_classifier_heavy_workload_active() {
        // Ningún proceso del plugin tiene prioridad implícita sobre otro. Si el
        // Clasificador fue iniciado manualmente, su propio regulador decide la
        // carga por tiempo, memoria y CPU. Un módulo externo aún puede forzar
        // una pausa explícita mediante este filtro.
        return (bool) apply_filters('seo_classifier_heavy_workload_active', false);
    }
}

if (!function_exists('seo_classifier_job_db_lock_name')) {
    function seo_classifier_job_db_lock_name() {
        global $wpdb;
        $seed = (defined('DB_NAME') ? DB_NAME : '') . '|' . (string)$wpdb->prefix . '|classifier';
        return 'seo_classifier_' . substr(hash('sha256', $seed), 0, 40);
    }
}

if (!function_exists('seo_classifier_job_acquire_global_lock')) {
    /**
     * Lock global del worker. Preferimos GET_LOCK de MySQL/MariaDB porque es
     * atómico y se libera automáticamente si muere la conexión PHP.
     * Conservamos el transient como respaldo para instalaciones que no admitan
     * advisory locks.
     */
    function seo_classifier_job_acquire_global_lock($job_id) {
        global $wpdb;
        $name = seo_classifier_job_db_lock_name();
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $name));
        if ((string)$got === '1') return 'db:' . $name;
        // 0 significa que otro worker posee el lock: no debemos saltarnos la
        // exclusión mutua usando el mecanismo de respaldo.
        if ((string)$got === '0') return '';

        $key = 'seo_classifier_worker_lock';
        if (get_transient($key)) return '';
        $token = absint($job_id) . ':' . wp_generate_password(18, false, false);
        set_transient($key, $token, 2 * MINUTE_IN_SECONDS);
        return 'transient:' . $token;
    }
}

if (!function_exists('seo_classifier_job_release_global_lock')) {
    function seo_classifier_job_release_global_lock($token) {
        global $wpdb;
        $token = (string)$token;
        if (strpos($token, 'db:') === 0) {
            $name = substr($token, 3);
            if ($name !== '') $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
            return;
        }
        if (strpos($token, 'transient:') === 0) {
            $value = substr($token, 10);
            if ($value !== '' && get_transient('seo_classifier_worker_lock') === $value) delete_transient('seo_classifier_worker_lock');
        }
    }
}

if (!function_exists('seo_classifier_job_recover_stale_items')) {
    function seo_classifier_job_recover_stale_items($job_id) {
        global $wpdb;
        $tables = seo_classifier_jobs_tables();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$tables['items']} SET status='pending',updated_at=NOW(),last_error=CONCAT(IFNULL(last_error,''),' [recuperado tras bloqueo]') WHERE job_id=%d AND status='processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
            absint($job_id)
        ));
    }
}

if (!function_exists('seo_classifier_job_claim_batch')) {
    function seo_classifier_job_claim_batch($job_id, $limit) {
        global $wpdb;
        $tables = seo_classifier_jobs_tables();
        $job_id = absint($job_id);
        $limit = max(1, min(100, absint($limit)));
        seo_classifier_job_recover_stale_items($job_id);
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$tables['items']} WHERE job_id=%d AND status='pending' ORDER BY id ASC LIMIT %d", $job_id, $limit));
        $ids = array_values(array_filter(array_map('absint', (array)$ids)));
        if (!$ids) return [];
        $id_sql = implode(',', $ids);
        $wpdb->query("UPDATE {$tables['items']} SET status='processing',attempts=attempts+1,started_at=COALESCE(started_at,NOW()),updated_at=NOW() WHERE id IN ({$id_sql}) AND status='pending'");
        return (array)$wpdb->get_results("SELECT * FROM {$tables['items']} WHERE id IN ({$id_sql}) AND status='processing' ORDER BY id ASC", ARRAY_A);
    }
}

if (!function_exists('seo_classifier_product_signature')) {
    /** Huella barata: permite saltarse la clasificación completa antes de construir contexto. */
    function seo_classifier_product_signature($product_id) {
        global $wpdb;
        $product_id = absint($product_id);
        if ($product_id < 1) return '';
        $tables = seo_classifier_jobs_tables();
        $v = $wpdb->prefix . 'seo_vocabulary';
        $o = $wpdb->prefix . 'seo_object_vocabulary';
        $post = $wpdb->get_row($wpdb->prepare("SELECT post_modified_gmt,post_status FROM {$wpdb->posts} WHERE ID=%d", $product_id), ARRAY_A);
        if (!$post) return '';
        $labels = (string)$wpdb->get_var($wpdb->prepare(
            "SELECT GROUP_CONCAT(CONCAT(vv.semantic_group,':',vv.id) ORDER BY vv.semantic_group,vv.id SEPARATOR ',') FROM {$o} ov JOIN {$v} vv ON vv.id=ov.vocabulary_id WHERE ov.object_type='product' AND ov.object_id=%d AND ov.status=1 AND vv.active=1",
            $product_id
        ));
        $terms = (string)$wpdb->get_var($wpdb->prepare(
            "SELECT GROUP_CONCAT(tt.term_taxonomy_id ORDER BY tt.term_taxonomy_id SEPARATOR ',') FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=%d",
            $product_id
        ));
        $meta = (string)$wpdb->get_var($wpdb->prepare(
            "SELECT GROUP_CONCAT(CONCAT(meta_key,'=',LEFT(meta_value,240)) ORDER BY meta_key SEPARATOR '|') FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ('_sku','_seo_fabricante','_seo_proveedor','_seo_marca_proveedor','_seo_proveedor_url_origen','_seo_proveedor_url_canonica','_seo_fabricante_url','_seo_url_fabricante','_product_url')",
            $product_id
        ));
        $supplier = '';
        $provider_table = $wpdb->prefix . 'seo_proveedores_productos';
        if (function_exists('seo_classifier_table_exists') && seo_classifier_table_exists($provider_table)) {
            $supplier = (string)$wpdb->get_var($wpdb->prepare("SELECT CONCAT(IFNULL(hash_producto,''),'|',IFNULL(actualizado,'')) FROM {$provider_table} WHERE object_id=%d ORDER BY actualizado DESC,id DESC LIMIT 1", $product_id));
        }
        $version = function_exists('seo_classifier_version') ? seo_classifier_version() : 'unknown';
        return hash('sha256', implode('|', [$product_id,(string)($post['post_modified_gmt'] ?? ''),$labels,$terms,$meta,$supplier,$version]));
    }
}

if (!function_exists('seo_classifier_proposals_for_products')) {
    function seo_classifier_proposals_for_products(array $product_ids, $active_only = true) {
        global $wpdb;
        $tables = seo_classifier_jobs_tables();
        $ids = array_values(array_unique(array_filter(array_map('absint', $product_ids))));
        if (!$ids) return [];
        $sql = "SELECT * FROM {$tables['proposals']} WHERE product_id IN (" . implode(',', $ids) . ')';
        if ($active_only) $sql .= ' AND active=1';
        $sql .= ' ORDER BY product_id,semantic_group';
        $rows = (array)$wpdb->get_results($sql, ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $pid = (int)$row['product_id'];
            $group = sanitize_key((string)$row['semantic_group']);
            $row['payload'] = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($row['payload'])) $row['payload'] = [];
            $out[$pid][$group] = $row;
        }
        return $out;
    }
}

if (!function_exists('seo_classifier_proposals_for_product')) {
    function seo_classifier_proposals_for_product($product_id, $active_only = true) {
        $map = seo_classifier_proposals_for_products([absint($product_id)], $active_only);
        return (array)($map[absint($product_id)] ?? []);
    }
}

if (!function_exists('seo_classifier_proposal_cache_fresh')) {
    function seo_classifier_proposal_cache_fresh($product_id, array $groups, $context_hash) {
        $rows = seo_classifier_proposals_for_product($product_id, true);
        $version = function_exists('seo_classifier_version') ? seo_classifier_version() : '';
        foreach ($groups as $group) {
            $group = sanitize_key((string)$group);
            if (!isset($rows[$group])) return false;
            if ((string)$rows[$group]['context_hash'] !== (string)$context_hash) return false;
            if ((string)$rows[$group]['classifier_version'] !== (string)$version) return false;
        }
        return true;
    }
}

if (!function_exists('seo_classifier_proposal_upsert')) {
    function seo_classifier_proposal_upsert($product_id, $group, array $proposal, $context_hash, $job_id = 0) {
        global $wpdb;
        $tables = seo_classifier_jobs_tables();
        $product_id = absint($product_id);
        $group = sanitize_key((string)$group);
        $state = sanitize_key((string)($proposal['status'] ?? $proposal['state'] ?? 'unresolved'));
        if (!in_array($state, ['safe','review','new','unresolved','derived','current'], true)) $state = 'unresolved';
        if ($state === 'derived') $state = 'safe';
        $safe = array_values((array)($proposal['safe'] ?? []));
        $review = array_values((array)($proposal['review'] ?? []));
        $new_rows = array_values((array)($proposal['new'] ?? []));
        $label = '';
        if ($safe) $label = (string)$safe[0];
        elseif ($review) $label = (string)$review[0];
        elseif ($new_rows) $label = (string)($new_rows[0]['label'] ?? '');
        $vocabulary_id = 0;
        foreach ((array)($proposal['candidates'] ?? []) as $candidate) {
            if ($label !== '' && (string)($candidate['label'] ?? '') === $label) {
                $vocabulary_id = absint($candidate['id'] ?? 0);
                break;
            }
        }
        $data = [
            'product_id'=>$product_id,
            'semantic_group'=>$group,
            'state'=>$state,
            'vocabulary_id'=>$vocabulary_id ?: null,
            'label'=>$label,
            'confidence'=>max(0, min(1, (float)($proposal['confidence'] ?? 0))),
            'payload_json'=>wp_json_encode($proposal),
            'context_hash'=>(string)$context_hash,
            'classifier_version'=>function_exists('seo_classifier_version') ? seo_classifier_version() : '',
            'source_job_id'=>absint($job_id) ?: null,
            'active'=>in_array($state, ['current'], true) ? 0 : 1,
            'updated_at'=>current_time('mysql'),
        ];
        $existing = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$tables['proposals']} WHERE product_id=%d AND semantic_group=%s", $product_id, $group));
        if ($existing > 0) {
            $wpdb->update($tables['proposals'], $data, ['id'=>$existing]);
            return $existing;
        }
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($tables['proposals'], $data);
        return (int)$wpdb->insert_id;
    }
}

if (!function_exists('seo_classifier_proposal_invalidate_product')) {
    function seo_classifier_proposal_invalidate_product($product_id, $groups = []) {
        global $wpdb;
        $tables = seo_classifier_jobs_tables();
        $product_id = absint($product_id);
        if ($product_id < 1) return;
        if (!$groups) {
            $wpdb->update($tables['proposals'], ['active'=>0,'updated_at'=>current_time('mysql')], ['product_id'=>$product_id], ['%d','%s'], ['%d']);
            return;
        }
        $groups = array_values(array_unique(array_filter(array_map('sanitize_key', (array)$groups))));
        if (!$groups) return;
        $quoted = array_map(static function($g){ return "'" . esc_sql($g) . "'"; }, $groups);
        $wpdb->query($wpdb->prepare("UPDATE {$tables['proposals']} SET active=0,updated_at=NOW() WHERE product_id=%d AND semantic_group IN (" . implode(',', $quoted) . ')', $product_id));
    }
}

if (!function_exists('seo_classifier_cached_product_label_proposal')) {
    /** Adapter para Asignación: jamás ejecuta el Clasificador, solo lee propuestas persistidas. */
    function seo_classifier_cached_product_label_proposal($product_id, array $current = [], array $prefetched = []) {
        $product_id = absint($product_id);
        $rows = $prefetched ?: seo_classifier_proposals_for_product($product_id, true);
        $values = [];
        $review = [];
        $new_terms = [];
        $confidence = [];
        $evidence = [];
        $groups = [];
        $pending = [];
        $unresolved = [];
        foreach (['tipo','aplicacion','plataforma','subtipo','rol'] as $group) {
            $current_labels = function_exists('seo_classifier_label_map') ? seo_classifier_label_map([$group=>(array)($current[$group] ?? [])]) : [];
            if (!empty($current[$group])) {
                $groups[$group] = ['status'=>'current','current'=>(array)($current_labels[$group] ?? []),'proposal'=>[],'safe'=>[],'review'=>[],'new'=>[],'confidence'=>1.0,'candidates'=>[]];
                continue;
            }
            if (!isset($rows[$group])) {
                $pending[] = $group;
                $groups[$group] = ['status'=>'pending','current'=>[],'proposal'=>[],'safe'=>[],'review'=>[],'new'=>[],'confidence'=>0.0,'candidates'=>[]];
                continue;
            }
            $row = $rows[$group];
            $payload = (array)($row['payload'] ?? []);
            $state = sanitize_key((string)($row['state'] ?? 'unresolved'));
            $payload['status'] = $state;
            $groups[$group] = $payload;
            $confidence[$group] = (float)($row['confidence'] ?? 0);
            $evidence[$group] = ['state'=>$state,'margin'=>0.0,'top'=>(array)($payload['candidates'] ?? []),'selected_safe'=>[],'selected_review'=>[]];
            if ($state === 'safe') {
                $safe = array_values((array)($payload['safe'] ?? []));
                if ($safe) $values[$group] = $safe;
            } elseif ($state === 'review') {
                $rev = array_values((array)($payload['review'] ?? []));
                if ($rev) $review[$group] = $rev;
            } elseif ($state === 'new') {
                $new = array_values((array)($payload['new'] ?? []));
                if ($new) $new_terms[$group] = $new;
            } else {
                $unresolved[] = $group;
            }
        }
        return [
            'object_type'=>'product','object_id'=>$product_id,'engine'=>'classifier_jobs_cache_v1',
            'values'=>$values,'review'=>$review,'new_terms'=>$new_terms,'has_new_terms'=>!empty($new_terms),
            'confidence'=>$confidence,'evidence'=>$evidence,'groups'=>$groups,
            'pending_groups'=>$pending,'unresolved_groups'=>$unresolved,'viable'=>!empty($values),
        ];
    }
}

if (!function_exists('seo_classifier_job_item_mark')) {
    function seo_classifier_job_item_mark($item_id, $status, $context_hash = '', $error = '') {
        global $wpdb;
        $tables = seo_classifier_jobs_tables();
        $wpdb->update($tables['items'], [
            'status'=>sanitize_key((string)$status),
            'context_hash'=>(string)$context_hash,
            'last_error'=>(string)$error,
            'finished_at'=>in_array($status, ['done','error','skipped'], true) ? current_time('mysql') : null,
            'updated_at'=>current_time('mysql'),
        ], ['id'=>absint($item_id)]);
    }
}

if (!function_exists('seo_classifier_job_process_classify_item')) {
    function seo_classifier_job_process_classify_item(array $job, array $item) {
        $product_id = absint($item['product_id'] ?? 0);
        $groups = seo_classifier_groups_from_mask($item['group_mask'] ?? 0);
        if ($product_id < 1 || !$groups) return ['status'=>'skipped','counts'=>['skipped_count'=>1],'hash'=>''];
        $signature = seo_classifier_product_signature($product_id);
        $deep = (string)($job['mode'] ?? 'fast') === 'deep';
        $force_refresh = $deep || !empty($job['force_refresh']);
        if (!$force_refresh && $signature !== '' && seo_classifier_proposal_cache_fresh($product_id, $groups, $signature)) {
            return ['status'=>'done','counts'=>['cache_hits'=>1],'hash'=>$signature];
        }
        $current = function_exists('seo_classifier_current_object_labels') ? seo_classifier_current_object_labels('product', $product_id) : [];
        $missing_groups = [];
        foreach ($groups as $group) {
            if (empty($current[$group])) $missing_groups[] = $group;
        }
        if (!$missing_groups) {
            seo_classifier_proposal_invalidate_product($product_id, $groups);
            return ['status'=>'skipped','counts'=>['skipped_count'=>1],'hash'=>$signature];
        }
        if ($deep && function_exists('seo_classifier_refresh_external_context')) {
            // Deep significa recálculo real: refresca las fuentes externas conocidas
            // antes de construir el contexto, sin reutilizar el contexto estático.
            seo_classifier_refresh_external_context($product_id);
        }
        $context = function_exists('seo_classifier_build_product_context')
            ? seo_classifier_build_product_context($product_id, [
                'queue_external'=>false,
                'refresh_context'=>$deep,
            ])
            : [];
        $result = seo_classifier_classify_product_labels($product_id, $current, [
            'groups'=>$missing_groups,
            'context'=>$context,
            'queue_external'=>false,
        ]);
        $counts = [];
        foreach ($missing_groups as $group) {
            $info = (array)(($result['groups'][$group] ?? []));
            if (!$info) $info = ['status'=>'unresolved','safe'=>[],'review'=>[],'new'=>[],'confidence'=>0.0,'candidates'=>[]];
            seo_classifier_proposal_upsert($product_id, $group, $info, $signature, (int)$job['id']);
            $state = sanitize_key((string)($info['status'] ?? 'unresolved'));
            if ($state === 'derived') $state = 'safe';
            if ($state === 'safe') $counts['safe_count'] = ($counts['safe_count'] ?? 0) + 1;
            elseif ($state === 'review') $counts['review_count'] = ($counts['review_count'] ?? 0) + 1;
            elseif ($state === 'new') $counts['new_count'] = ($counts['new_count'] ?? 0) + 1;
            else $counts['unresolved_count'] = ($counts['unresolved_count'] ?? 0) + 1;
        }
        return ['status'=>'done','counts'=>$counts,'hash'=>$signature];
    }
}

if (!function_exists('seo_classifier_job_process_apply_item')) {
    function seo_classifier_job_process_apply_item(array $job, array $item) {
        $product_id = absint($item['product_id'] ?? 0);
        $groups = seo_classifier_groups_from_mask($item['group_mask'] ?? 0);
        if ($product_id < 1 || !$groups) return ['status'=>'skipped','counts'=>['skipped_count'=>1],'hash'=>''];
        $rows = seo_classifier_proposals_for_product($product_id, true);
        $payload = [];
        foreach ($groups as $group) {
            if (empty($rows[$group]) || (string)$rows[$group]['state'] !== 'safe') continue;
            $p = (array)($rows[$group]['payload'] ?? []);
            $safe = array_values((array)($p['safe'] ?? []));
            if (!$safe) continue;
            if ($group === 'rol') {
                // ROL no se escribe directamente: el servicio canónico lo sincroniza
                // desde TIPO. Reaplicamos el TIPO actual para materializar el ROL.
                $current = function_exists('seo_classifier_current_object_labels') ? seo_classifier_current_object_labels('product', $product_id) : [];
                $type_labels = function_exists('seo_classifier_label_map') ? seo_classifier_label_map(['tipo'=>(array)($current['tipo'] ?? [])]) : [];
                if (!empty($type_labels['tipo'])) $payload['tipo'] = (array)$type_labels['tipo'];
                continue;
            }
            $payload[$group] = $safe;
        }
        if (!$payload) return ['status'=>'skipped','counts'=>['skipped_count'=>1],'hash'=>''];
        if (!function_exists('seo_assignment_apply_product_labels_json')) {
            throw new RuntimeException('No está disponible la escritura de Asignación para aplicar propuestas.');
        }
        seo_assignment_apply_product_labels_json($product_id, wp_json_encode($payload));
        seo_classifier_proposal_invalidate_product($product_id, array_values(array_unique(array_merge($groups, array_keys($payload)))));
        return ['status'=>'done','counts'=>['applied_count'=>1],'hash'=>seo_classifier_product_signature($product_id)];
    }
}

if (!function_exists('seo_classifier_process_job')) {
    function seo_classifier_process_job($job_id, $source = 'scheduler') {
        global $wpdb;
        $job_id = absint($job_id);
        $job = seo_classifier_job_get($job_id);
        if (!$job || in_array((string)$job['status'], ['paused','cancelled','completed','failed'], true)) return;
        $lock = seo_classifier_job_acquire_global_lock($job_id);
        if ($lock === '') {
            seo_classifier_job_schedule($job_id, 20, false);
            return;
        }
        $tables = seo_classifier_jobs_tables();
        $source = sanitize_key((string)$source);
        if ($source === '') $source = 'scheduler';
        $now_mysql = current_time('mysql');
        $wpdb->update($tables['jobs'], [
            'worker_heartbeat_at'=>$now_mysql,
            'worker_heartbeat_ts'=>time(),
            'worker_runs'=>absint($job['worker_runs'] ?? 0) + 1,
            'worker_source'=>$source,
            'updated_at'=>$now_mysql,
        ], ['id'=>$job_id]);
        $job = seo_classifier_job_get($job_id) ?: $job;
        try {
            if (seo_classifier_heavy_workload_active()) {
                $wpdb->update($tables['jobs'], [
                    'status'=>'running','adaptive_next_delay'=>60,'adaptive_pressure'=>'externa','updated_at'=>current_time('mysql')
                ], ['id'=>$job_id]);
                seo_classifier_job_schedule($job_id, 60, true);
                return;
            }
            $cpu_before = seo_classifier_job_runtime_cpu_metrics();
            $config_before = seo_classifier_job_adaptive_config((string)($job['mode'] ?? 'fast'));
            if (!empty($cpu_before['available']) && (float)$cpu_before['percent'] >= (float)$config_before['cpu_hard_percent']) {
                $wpdb->update($tables['jobs'], [
                    'status'=>'running',
                    'last_cpu_percent'=>round((float)$cpu_before['percent'],2),
                    'last_cpu_cores'=>absint($cpu_before['cores'] ?? 0),
                    'adaptive_next_batch_size'=>1,
                    'adaptive_next_delay'=>(int)$config_before['critical_delay'],
                    'adaptive_pressure'=>'cpu_critica',
                    'updated_at'=>current_time('mysql'),
                ], ['id'=>$job_id]);
                seo_classifier_job_schedule($job_id, (int)$config_before['critical_delay'], true);
                return;
            }

            if (!empty($cpu_before['available'])) {
                $job['last_cpu_percent'] = (float)$cpu_before['percent'];
                $job['last_cpu_cores'] = absint($cpu_before['cores'] ?? 0);
            }
            $plan = seo_classifier_job_adaptive_plan($job);
            $batch_size = max(1, (int)$plan['batch_size']);
            $items = seo_classifier_job_claim_batch($job_id, $batch_size);
            if (!$items) {
                $pending = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['items']} WHERE job_id=%d AND status IN ('pending','processing')", $job_id));
                if ($pending < 1) {
                    $wpdb->update($tables['jobs'], [
                        'status'=>'completed',
                        'completed_at'=>current_time('mysql'),
                        'worker_heartbeat_at'=>current_time('mysql'),
                        'worker_heartbeat_ts'=>time(),
                        'updated_at'=>current_time('mysql'),
                    ], ['id'=>$job_id]);
                    seo_classifier_job_unschedule($job_id);
                } else {
                    seo_classifier_job_schedule($job_id, 20, true);
                }
                return;
            }
            $started = microtime(true);
            $queries_before = function_exists('get_num_queries') ? get_num_queries() : 0;
            $config = $plan['config'];
            $batch_rows = 0;
            $time_cutoff = false;
            $memory_cutoff = false;
            $increments = ['processed_items'=>0,'cache_hits'=>0,'safe_count'=>0,'review_count'=>0,'new_count'=>0,'unresolved_count'=>0,'applied_count'=>0,'skipped_count'=>0,'error_count'=>0];
            foreach ($items as $index => $item) {
                $elapsed = microtime(true) - $started;
                if ($batch_rows >= $config['min_rows'] && $elapsed >= $config['hard_seconds']) {
                    $time_cutoff = true;
                    // Lo que no se ha procesado vuelve a pending.
                    for ($j=$index; $j<count($items); $j++) {
                        $wpdb->update($tables['items'], ['status'=>'pending','updated_at'=>current_time('mysql')], ['id'=>absint($items[$j]['id'])]);
                    }
                    break;
                }
                if ($batch_rows >= $config['min_rows'] && seo_classifier_job_memory_ratio() >= $config['memory_hard_ratio']) {
                    $memory_cutoff = true;
                    for ($j=$index; $j<count($items); $j++) {
                        $wpdb->update($tables['items'], ['status'=>'pending','updated_at'=>current_time('mysql')], ['id'=>absint($items[$j]['id'])]);
                    }
                    break;
                }
                try {
                    $result = (string)$job['job_type'] === 'apply'
                        ? seo_classifier_job_process_apply_item($job, $item)
                        : seo_classifier_job_process_classify_item($job, $item);
                    seo_classifier_job_item_mark($item['id'], $result['status'], (string)($result['hash'] ?? ''), '');
                    foreach ((array)($result['counts'] ?? []) as $key => $value) {
                        if (array_key_exists($key, $increments)) $increments[$key] += absint($value);
                    }
                } catch (Throwable $e) {
                    seo_classifier_job_item_mark($item['id'], 'error', '', $e->getMessage());
                    $increments['error_count']++;
                }
                $increments['processed_items']++;
                $batch_rows++;
            }
            if ($increments['applied_count'] > 0) {
                if (function_exists('seo_classifier_bump_profiles_generation')) seo_classifier_bump_profiles_generation();
                if (function_exists('seo_assignment_invalidate_summary')) seo_assignment_invalidate_summary();
            }
            $duration = max(0.0001, microtime(true) - $started);
            $memory_ratio = seo_classifier_job_memory_ratio(true);
            $query_count = function_exists('get_num_queries') ? max(0, get_num_queries() - $queries_before) : 0;
            $cpu_after = seo_classifier_job_runtime_cpu_metrics();
            $job = seo_classifier_job_get($job_id) ?: $job;
            $job['last_batch_rows'] = $batch_rows;
            $job['last_batch_duration'] = $duration;
            $job['last_batch_memory_ratio'] = $memory_ratio;
            $job['last_batch_time_cutoff'] = $time_cutoff ? 1 : 0;
            $job['last_batch_memory_cutoff'] = $memory_cutoff ? 1 : 0;
            if (!empty($cpu_after['available'])) {
                $job['last_cpu_percent'] = (float)$cpu_after['percent'];
                $job['last_cpu_cores'] = absint($cpu_after['cores'] ?? 0);
            }
            $next = seo_classifier_job_adaptive_plan($job);

            $sets = [
                'status'=>'running','started_at'=>$job['started_at'] ?: current_time('mysql'),
                'batch_number'=>absint($job['batch_number'] ?? 0) + 1,
                'last_batch_rows'=>$batch_rows,
                'last_batch_duration'=>round($duration,4),
                'last_batch_seconds_per_row'=>round($duration / max(1,$batch_rows),4),
                'last_batch_memory_ratio'=>round($memory_ratio,4),
                'last_batch_query_count'=>$query_count,
                'last_cpu_percent'=>!empty($cpu_after['available']) ? round((float)$cpu_after['percent'],2) : null,
                'last_cpu_cores'=>!empty($cpu_after['available']) ? absint($cpu_after['cores'] ?? 0) : 0,
                'last_batch_time_cutoff'=>$time_cutoff ? 1 : 0,
                'last_batch_memory_cutoff'=>$memory_cutoff ? 1 : 0,
                'adaptive_next_batch_size'=>(int)$next['batch_size'],
                'adaptive_next_delay'=>(int)$next['delay'],
                'adaptive_pressure'=>(string)$next['pressure'],
                'worker_heartbeat_at'=>current_time('mysql'),
                'worker_heartbeat_ts'=>time(),
                'updated_at'=>current_time('mysql'),
            ];
            foreach ($increments as $key => $value) {
                if ($value > 0) $sets[$key] = absint($job[$key] ?? 0) + $value;
            }
            $wpdb->update($tables['jobs'], $sets, ['id'=>$job_id]);
            $remaining = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tables['items']} WHERE job_id=%d AND status IN ('pending','processing')", $job_id));
            if ($remaining < 1) {
                $wpdb->update($tables['jobs'], [
                    'status'=>'completed',
                    'completed_at'=>current_time('mysql'),
                    'worker_heartbeat_at'=>current_time('mysql'),
                    'worker_heartbeat_ts'=>time(),
                    'updated_at'=>current_time('mysql'),
                ], ['id'=>$job_id]);
                seo_classifier_job_unschedule($job_id);
            } else {
                seo_classifier_job_schedule($job_id, (int)$next['delay'], true);
            }
        } catch (Throwable $e) {
            $wpdb->update($tables['jobs'], [
                'status'=>'failed',
                'last_error'=>$e->getMessage(),
                'worker_heartbeat_at'=>current_time('mysql'),
                'worker_heartbeat_ts'=>time(),
                'updated_at'=>current_time('mysql'),
            ], ['id'=>$job_id]);
            seo_classifier_job_unschedule($job_id);
        } finally {
            seo_classifier_job_release_global_lock($lock);
        }
    }
    add_action('seo_classifier_process_job', 'seo_classifier_process_job', 10, 1);
}

if (!function_exists('seo_classifier_job_manager_slice')) {
    /**
     * Ejecuta el Clasificador durante una ventana del gestor. Solo procesa jobs
     * que ya estén en pending/running; nunca crea ni reactiva un job parado.
     */
    function seo_classifier_job_manager_slice($job_id, $seconds = 15, $source = 'manager_cron') {
        $job_id = absint($job_id);
        $seconds = max(5, min(55, absint($seconds)));
        $source = sanitize_key((string)$source);
        if ($source === '') $source = 'manager_cron';
        $started = microtime(true);
        $did_work = false;

        while ((microtime(true) - $started) < $seconds) {
            $job = seo_classifier_job_get($job_id);
            if (!$job || !in_array((string)($job['status'] ?? ''), ['pending','running'], true)) break;

            $due = absint(get_transient(seo_classifier_job_manager_due_key($job_id)));
            $now = time();
            if ($due > $now) {
                $wait = min($due - $now, max(0, (int)floor($seconds - (microtime(true) - $started))));
                if ($wait < 1) break;
                sleep(min(5, $wait));
                continue;
            }

            $runs_before = absint($job['worker_runs'] ?? 0);
            seo_classifier_process_job($job_id, $source);
            $after = seo_classifier_job_get($job_id);
            if (!$after) break;
            if (absint($after['worker_runs'] ?? 0) > $runs_before || absint($after['processed_items'] ?? 0) > absint($job['processed_items'] ?? 0)) {
                $did_work = true;
            }
            if (!in_array((string)($after['status'] ?? ''), ['pending','running'], true)) break;

            $delay = absint($after['adaptive_next_delay'] ?? 0);
            if ($delay > 0) {
                set_transient(seo_classifier_job_manager_due_key($job_id), time() + $delay, max(300, $delay + 300));
                if ((microtime(true) - $started) + $delay >= $seconds) break;
                sleep(min(5, $delay));
            }
        }
        return $did_work;
    }
}

if (!function_exists('seo_classifier_job_control')) {
    function seo_classifier_job_control($job_id, $action) {
        global $wpdb;
        $tables = seo_classifier_jobs_tables();
        $job = seo_classifier_job_get($job_id);
        if (!$job) return new WP_Error('seo_classifier_job_missing', 'No existe el trabajo.');
        $action = sanitize_key((string)$action);
        if ($action === 'pause') {
            $wpdb->update($tables['jobs'], ['status'=>'paused','updated_at'=>current_time('mysql')], ['id'=>absint($job_id)]);
            seo_classifier_job_unschedule($job_id);
        } elseif ($action === 'resume') {
            $wpdb->update($tables['jobs'], [
                'status'=>'running',
                'worker_heartbeat_at'=>null,
                'worker_heartbeat_ts'=>0,
                'worker_source'=>'',
                'updated_at'=>current_time('mysql'),
            ], ['id'=>absint($job_id)]);
            seo_classifier_job_schedule($job_id, 0, true);
        } elseif ($action === 'cancel') {
            $wpdb->update($tables['jobs'], ['status'=>'cancelled','completed_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')], ['id'=>absint($job_id)]);
            $wpdb->update($tables['items'], ['status'=>'skipped','finished_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')], ['job_id'=>absint($job_id),'status'=>'pending']);
            seo_classifier_job_unschedule($job_id);
        } else {
            return new WP_Error('seo_classifier_job_action_invalid', 'Acción no válida.');
        }
        return seo_classifier_job_get($job_id);
    }
}

if (!function_exists('seo_classifier_job_maybe_watchdog')) {
    /**
     * Tercera vía de ejecución: mientras la pantalla del job esté abierta, el
     * polling actúa como watchdog. Si los schedulers del hosting no han arrancado
     * el job o el heartbeat se queda obsoleto, esta petición ejecuta un lote.
     * El lock global evita duplicar trabajo si Action Scheduler despierta a la vez.
     */
    function seo_classifier_job_maybe_watchdog($job_id) {
        $job_id = absint($job_id);
        $job = seo_classifier_job_get($job_id);
        if (!$job || !in_array((string)($job['status'] ?? ''), ['pending','running'], true)) return false;

        // Mantener preparado el motor correspondiente. Si el gestor propio está
        // activo, la pantalla solo lo despierta: no ejecuta trabajo pesado aquí.
        seo_classifier_job_schedule($job_id, 0, false);
        if (seo_classifier_job_use_process_manager()) {
            return false;
        }

        $runs = absint($job['worker_runs'] ?? 0);
        $heartbeat = absint($job['worker_heartbeat_ts'] ?? 0);
        $next_delay = absint($job['adaptive_next_delay'] ?? 0);
        $allowed_gap = max(seo_classifier_job_watchdog_seconds(), $next_delay + 15);
        $stale = $runs < 1 || $heartbeat < 1 || (time() - $heartbeat) >= $allowed_gap;
        if (!$stale) return false;

        $guard = 'seo_cl_watchdog_' . $job_id;
        if (get_transient($guard)) return false;
        set_transient($guard, 1, 12);
        seo_classifier_process_job($job_id, 'browser_watchdog');
        return true;
    }
}

if (!function_exists('seo_classifier_job_status_payload')) {
    function seo_classifier_job_status_payload($job_id) {
        $job = seo_classifier_job_get($job_id);
        if (!$job) return [];
        $total = max(0, absint($job['total_items'] ?? 0));
        $processed = min($total ?: PHP_INT_MAX, absint($job['processed_items'] ?? 0));
        $heartbeat = absint($job['worker_heartbeat_ts'] ?? 0);
        $worker_age = $heartbeat > 0 ? max(0, time() - $heartbeat) : null;
        $worker_runs = absint($job['worker_runs'] ?? 0);
        $next_delay = absint($job['adaptive_next_delay'] ?? 0);
        $watchdog_gap = max(seo_classifier_job_watchdog_seconds(), $next_delay + 15);
        $status = (string)($job['status'] ?? '');
        if (in_array($status, ['completed','cancelled','failed','paused'], true)) {
            $worker_state = $status;
        } elseif ($worker_runs < 1 || $heartbeat < 1) {
            $worker_state = 'esperando_arranque';
        } elseif ($worker_age !== null && $worker_age <= 20) {
            $worker_state = 'activo';
        } elseif ($worker_age !== null && $worker_age < $watchdog_gap) {
            $worker_state = 'espera_programada';
        } else {
            $worker_state = 'heartbeat_obsoleto';
        }
        $scheduler = seo_classifier_job_scheduler_status(absint($job['id']));
        return [
            'id'=>absint($job['id']),'uuid'=>(string)$job['job_uuid'],'job_type'=>(string)$job['job_type'],'mode'=>(string)$job['mode'],'status'=>$status,
            'force_refresh'=>!empty($job['force_refresh']),'deep_recalculation'=>((string)$job['mode'] === 'deep'),
            'total'=>$total,'processed'=>$processed,'progress'=>$total > 0 ? round(($processed/$total)*100,2) : 100,
            'cache_hits'=>absint($job['cache_hits'] ?? 0),'safe'=>absint($job['safe_count'] ?? 0),'review'=>absint($job['review_count'] ?? 0),'new'=>absint($job['new_count'] ?? 0),'unresolved'=>absint($job['unresolved_count'] ?? 0),
            'applied'=>absint($job['applied_count'] ?? 0),'skipped'=>absint($job['skipped_count'] ?? 0),'errors'=>absint($job['error_count'] ?? 0),
            'batch_number'=>absint($job['batch_number'] ?? 0),'last_batch_rows'=>absint($job['last_batch_rows'] ?? 0),'last_batch_duration'=>(float)($job['last_batch_duration'] ?? 0),
            'seconds_per_row'=>(float)($job['last_batch_seconds_per_row'] ?? 0),'memory_ratio'=>(float)($job['last_batch_memory_ratio'] ?? 0),'queries'=>absint($job['last_batch_query_count'] ?? 0),
            'cpu_percent'=>isset($job['last_cpu_percent']) && $job['last_cpu_percent'] !== null ? (float)$job['last_cpu_percent'] : null,'cpu_cores'=>absint($job['last_cpu_cores'] ?? 0),
            'next_batch_rows'=>absint($job['adaptive_next_batch_size'] ?? 0),'next_delay'=>$next_delay,'pressure'=>(string)($job['adaptive_pressure'] ?? ''),'last_error'=>(string)($job['last_error'] ?? ''),
            'worker_runs'=>$worker_runs,'worker_source'=>(string)($job['worker_source'] ?? ''),'worker_heartbeat_at'=>(string)($job['worker_heartbeat_at'] ?? ''),'worker_age_seconds'=>$worker_age,'worker_state'=>$worker_state,
            'scheduler'=>$scheduler,
            'updated_at'=>(string)($job['updated_at'] ?? ''),'completed_at'=>(string)($job['completed_at'] ?? ''),
        ];
    }
}

if (!function_exists('seo_classifier_ajax_job_status')) {
    function seo_classifier_ajax_job_status() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Sin permisos.'], 403);
        check_ajax_referer('seo_classifier_jobs', 'nonce');
        $job_id = absint($_POST['job_id'] ?? 0);
        seo_classifier_job_maybe_watchdog($job_id);
        $payload = seo_classifier_job_status_payload($job_id);
        if (!$payload) wp_send_json_error(['message'=>'Trabajo no encontrado.'], 404);
        wp_send_json_success($payload);
    }
    add_action('wp_ajax_seo_classifier_job_status', 'seo_classifier_ajax_job_status');
}

if (!function_exists('seo_classifier_ajax_job_control')) {
    function seo_classifier_ajax_job_control() {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Sin permisos.'], 403);
        check_ajax_referer('seo_classifier_jobs', 'nonce');
        $result = seo_classifier_job_control(absint($_POST['job_id'] ?? 0), sanitize_key((string)($_POST['job_action'] ?? '')));
        if (is_wp_error($result)) wp_send_json_error(['message'=>$result->get_error_message()], 400);
        wp_send_json_success(seo_classifier_job_status_payload((int)$result['id']));
    }
    add_action('wp_ajax_seo_classifier_job_control', 'seo_classifier_ajax_job_control');
}
