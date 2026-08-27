<?php
/**
 * SEO Images - auditor externo de carga de imágenes.
 *
 * Usa el inventario persistente de URLs del sitemap como fuente de páginas y
 * encadena automáticamente lotes adaptativos mediante GitHub Actions. El tamaño
 * de cada lote se regula con el tiempo real y la latencia observada.
 *
 * Version: 2026-08-26
 * Build: 004
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SEO_IMAGES_SCAN_SCHEMA_VERSION')) {
    define('SEO_IMAGES_SCAN_SCHEMA_VERSION', '2');
}
if (!defined('SEO_IMAGES_SCAN_PAGE_LIMIT')) {
    // Límite duro. El objetivo real de cada lote lo decide el regulador adaptativo.
    define('SEO_IMAGES_SCAN_PAGE_LIMIT', 500);
}
if (!defined('SEO_IMAGES_SCAN_INITIAL_PAGE_LIMIT')) {
    define('SEO_IMAGES_SCAN_INITIAL_PAGE_LIMIT', 200);
}
if (!defined('SEO_IMAGES_SCAN_INITIAL_IMAGE_LIMIT')) {
    define('SEO_IMAGES_SCAN_INITIAL_IMAGE_LIMIT', 1200);
}

if (!function_exists('seo_images_scan_tables')) {
    function seo_images_scan_tables() {
        global $wpdb;
        return array(
            'runs'    => $wpdb->prefix . 'seo_image_scan_runs',
            'pages'   => $wpdb->prefix . 'seo_image_scan_pages',
            'items'   => $wpdb->prefix . 'seo_image_scan_items',
            'sitemap' => $wpdb->prefix . 'seo_sitemap_inventory',
        );
    }
}

if (!function_exists('seo_images_scan_table_exists')) {
    function seo_images_scan_table_exists($table) {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like((string) $table))
        ) === (string) $table;
    }
}

if (!function_exists('seo_images_scan_install_schema')) {
    function seo_images_scan_install_schema() {
        global $wpdb;
        $tables = seo_images_scan_tables();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_runs = "CREATE TABLE {$tables['runs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_uuid char(36) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            mode varchar(20) NOT NULL DEFAULT 'coverage',
            total_pages int(10) unsigned NOT NULL DEFAULT 0,
            processed_pages int(10) unsigned NOT NULL DEFAULT 0,
            total_images int(10) unsigned NOT NULL DEFAULT 0,
            ok_images int(10) unsigned NOT NULL DEFAULT 0,
            warning_images int(10) unsigned NOT NULL DEFAULT 0,
            error_images int(10) unsigned NOT NULL DEFAULT 0,
            page_errors int(10) unsigned NOT NULL DEFAULT 0,
            target_images int(10) unsigned NOT NULL DEFAULT 0,
            duration_ms int(10) unsigned NOT NULL DEFAULT 0,
            avg_request_ms int(10) unsigned NOT NULL DEFAULT 0,
            p95_request_ms int(10) unsigned NOT NULL DEFAULT 0,
            pressure_events int(10) unsigned NOT NULL DEFAULT 0,
            request_count int(10) unsigned NOT NULL DEFAULT 0,
            adaptive_reason varchar(255) NOT NULL DEFAULT '',
            callback_token_hash char(64) NOT NULL DEFAULT '',
            error_message text NULL,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scan_uuid (scan_uuid),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_pages = "CREATE TABLE {$tables['pages']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sitemap_inventory_id bigint(20) unsigned NOT NULL DEFAULT 0,
            page_url_hash char(32) NOT NULL,
            page_url text NOT NULL,
            status_bucket varchar(20) NOT NULL DEFAULT 'pending',
            page_http_status smallint(5) unsigned NULL,
            response_ms int(10) unsigned NOT NULL DEFAULT 0,
            total_images int(10) unsigned NOT NULL DEFAULT 0,
            ok_images int(10) unsigned NOT NULL DEFAULT 0,
            warning_images int(10) unsigned NOT NULL DEFAULT 0,
            error_images int(10) unsigned NOT NULL DEFAULT 0,
            error_type varchar(64) NOT NULL DEFAULT '',
            error_message text NULL,
            last_scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
            queued_scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
            queued_at datetime NULL,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            last_checked_at datetime NULL,
            last_ok_at datetime NULL,
            last_error_at datetime NULL,
            consecutive_errors int(10) unsigned NOT NULL DEFAULT 0,
            checks_total int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY page_url_hash (page_url_hash),
            KEY status_bucket (status_bucket),
            KEY last_checked_at (last_checked_at),
            KEY last_scan_id (last_scan_id),
            KEY queued_scan_id (queued_scan_id),
            KEY sitemap_inventory_id (sitemap_inventory_id)
        ) {$charset_collate};";

        $sql_items = "CREATE TABLE {$tables['items']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            page_id bigint(20) unsigned NOT NULL,
            page_url_hash char(32) NOT NULL,
            image_url_hash char(32) NOT NULL,
            image_url text NOT NULL,
            source_tag varchar(20) NOT NULL DEFAULT 'img',
            source_attr varchar(32) NOT NULL DEFAULT 'src',
            active_on_page tinyint(1) unsigned NOT NULL DEFAULT 1,
            status_bucket varchar(20) NOT NULL DEFAULT 'pending',
            http_status smallint(5) unsigned NULL,
            final_status smallint(5) unsigned NULL,
            final_url text NULL,
            redirect_count smallint(5) unsigned NOT NULL DEFAULT 0,
            content_type varchar(120) NOT NULL DEFAULT '',
            response_ms int(10) unsigned NOT NULL DEFAULT 0,
            error_type varchar(64) NOT NULL DEFAULT '',
            error_message text NULL,
            last_scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            last_checked_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY page_image (page_url_hash,image_url_hash),
            KEY page_id (page_id),
            KEY status_bucket (status_bucket),
            KEY image_url_hash (image_url_hash),
            KEY active_on_page (active_on_page),
            KEY last_scan_id (last_scan_id)
        ) {$charset_collate};";

        dbDelta($sql_runs);
        dbDelta($sql_pages);
        dbDelta($sql_items);
        update_option('seo_images_scan_schema_version', SEO_IMAGES_SCAN_SCHEMA_VERSION, false);
    }
}

if (!function_exists('seo_images_scan_maybe_upgrade')) {
    function seo_images_scan_maybe_upgrade() {
        if (!current_user_can('manage_options')) {
            return;
        }
        if ((string) get_option('seo_images_scan_schema_version', '') !== SEO_IMAGES_SCAN_SCHEMA_VERSION) {
            seo_images_scan_install_schema();
        }
    }
}
add_action('admin_init', 'seo_images_scan_maybe_upgrade', 20);

if (!function_exists('seo_images_scan_runner_config')) {
    function seo_images_scan_runner_config() {
        if (!function_exists('seo_github_python_runner_settings')) {
            $runner_file = __DIR__ . '/import-export/suppliers/github-python-runner.php';
            if (is_readable($runner_file)) {
                require_once $runner_file;
            }
        }

        $settings = function_exists('seo_github_python_runner_settings')
            ? seo_github_python_runner_settings()
            : array();

        return array(
            'available'       => function_exists('seo_github_python_runner_settings') && function_exists('seo_github_python_runner_api_request'),
            'enabled'         => !empty($settings['enabled']),
            'owner'           => trim((string) ($settings['owner'] ?? '')),
            'repo'            => trim((string) ($settings['repo'] ?? '')),
            'ref'             => trim((string) ($settings['ref'] ?? 'main')) ?: 'main',
            'token'           => trim((string) ($settings['token'] ?? '')),
            'workflow'        => 'image-scan.yml',
            'batch_endpoint'  => rest_url('seo-system/v1/image-scan/batch'),
            'callback_url'    => rest_url('seo-system/v1/image-scan/results'),
        );
    }
}

if (!function_exists('seo_images_scan_config_errors')) {
    function seo_images_scan_config_errors($config) {
        $errors = array();
        if (empty($config['available'])) {
            return array('github_runner');
        }
        if (empty($config['enabled'])) {
            $errors[] = 'conexion_desactivada';
        }
        foreach (array('owner', 'repo', 'ref', 'token') as $key) {
            if (empty($config[$key])) {
                $errors[] = $key;
            }
        }
        return $errors;
    }
}


if (!function_exists('seo_images_scan_validate_workflow')) {
    /**
     * Comprueba el workflow antes de reservar un lote. GitHub solo permite
     * workflow_dispatch si el workflow existe en el repositorio (y debe estar
     * publicado en la rama predeterminada para poder ser despachado por API).
     */
    function seo_images_scan_validate_workflow($config) {
        if (empty($config['available'])) {
            return new WP_Error('seo_images_scan_runner_missing', 'La conexión GitHub Python Runner no está disponible.');
        }

        $endpoint = sprintf(
            'https://api.github.com/repos/%1$s/%2$s/actions/workflows/%3$s',
            rawurlencode($config['owner']),
            rawurlencode($config['repo']),
            rawurlencode($config['workflow'])
        );

        $response = seo_github_python_runner_api_request('GET', $endpoint);
        if (is_wp_error($response)) {
            $data = $response->get_error_data();
            $status = is_array($data) && isset($data['status']) ? absint($data['status']) : 0;
            if (404 === $status) {
                return new WP_Error(
                    'seo_images_scan_workflow_missing',
                    'GitHub no encuentra .github/workflows/image-scan.yml en el repositorio configurado. Sube ese archivo con ese nombre exacto a la rama predeterminada del repositorio y vuelve a iniciar el lote.'
                );
            }
            return $response;
        }

        if (is_array($response) && isset($response['state']) && 'active' !== (string) $response['state']) {
            return new WP_Error(
                'seo_images_scan_workflow_inactive',
                'El workflow image-scan.yml existe en GitHub, pero no está activo. Actívalo en Actions antes de iniciar el lote.'
            );
        }

        return true;
    }
}

if (!function_exists('seo_images_scan_admin_url')) {
    function seo_images_scan_admin_url($args = array()) {
        $base = function_exists('seo_images_admin_url')
            ? seo_images_admin_url(array('tab' => 'scan'))
            : add_query_arg(array('page' => 'seo-pictures-admin', 'tab' => 'scan'), admin_url('admin.php'));
        return add_query_arg(is_array($args) ? $args : array(), $base);
    }
}

if (!function_exists('seo_images_scan_redirect')) {
    function seo_images_scan_redirect($message, $extra = array()) {
        wp_safe_redirect(seo_images_scan_admin_url(array_merge(
            array('scan_msg' => sanitize_key($message)),
            is_array($extra) ? $extra : array()
        )));
        exit;
    }
}

if (!function_exists('seo_images_scan_mark_stale_runs')) {
    function seo_images_scan_mark_stale_runs() {
        global $wpdb;
        $tables = seo_images_scan_tables();
        if (!seo_images_scan_table_exists($tables['runs'])) {
            return;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS);
        $stale_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$tables['runs']} WHERE status IN ('queued','running') AND updated_at < %s",
                $cutoff
            )
        );
        if (empty($stale_ids)) {
            return;
        }
        $now = current_time('mysql', true);
        foreach ($stale_ids as $run_id) {
            $run_id = absint($run_id);
            $wpdb->update(
                $tables['runs'],
                array(
                    'status' => 'failed',
                    'error_message' => 'Ejecución caducada sin callback final.',
                    'callback_token_hash' => '',
                    'completed_at' => $now,
                    'updated_at' => $now,
                ),
                array('id' => $run_id),
                array('%s','%s','%s','%s','%s'),
                array('%d')
            );
            $wpdb->update(
                $tables['pages'],
                array('queued_scan_id' => 0, 'queued_at' => null),
                array('queued_scan_id' => $run_id),
                array('%d','%s'),
                array('%d')
            );
        }
        $state = seo_images_scan_process_state();
        if (!empty($state['enabled'])) {
            $state['enabled'] = 0;
            $state['status'] = 'error';
            $state['last_error'] = 'El lote activo caducó sin callback final.';
            $state['adaptive_reason'] = 'cadena detenida por lote caducado';
            seo_images_scan_store_process_state($state);
            seo_images_scan_unschedule_continue();
        }
    }
}

if (!function_exists('seo_images_scan_active_run')) {
    function seo_images_scan_active_run() {
        global $wpdb;
        $tables = seo_images_scan_tables();
        seo_images_scan_mark_stale_runs();
        if (!seo_images_scan_table_exists($tables['runs'])) {
            return null;
        }
        return $wpdb->get_row(
            "SELECT * FROM {$tables['runs']} WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );
    }
}

if (!function_exists('seo_images_scan_adaptive_config')) {
    /**
     * Regulador de tamaño de lote. Sigue la misma filosofía del importador:
     * observar el coste real del lote anterior y aproximar el siguiente a un
     * tiempo objetivo, acelerando solo cuando la web responde con holgura.
     */
    function seo_images_scan_adaptive_config() {
        $config = array(
            'min_pages'          => 25,
            'initial_pages'      => SEO_IMAGES_SCAN_INITIAL_PAGE_LIMIT,
            'max_pages'          => SEO_IMAGES_SCAN_PAGE_LIMIT,
            'min_images'         => 300,
            'initial_images'     => SEO_IMAGES_SCAN_INITIAL_IMAGE_LIMIT,
            'max_images'         => 4000,
            'target_seconds'     => 90.0,
            'hard_seconds'       => 180.0,
            'growth_factor'      => 1.50,
            'slow_p95_ms'        => 1600,
            'very_slow_p95_ms'   => 2600,
            'fast_p95_ms'        => 850,
            'heavy_delay'        => 8,
            'critical_delay'     => 20,
        );
        $filtered = apply_filters('seo_images_scan_adaptive_config', $config);
        if (is_array($filtered)) {
            $config = array_merge($config, $filtered);
        }
        $config['min_pages']      = max(1, absint($config['min_pages']));
        $config['initial_pages']  = max($config['min_pages'], absint($config['initial_pages']));
        $config['max_pages']      = max($config['initial_pages'], min(SEO_IMAGES_SCAN_PAGE_LIMIT, absint($config['max_pages'])));
        $config['min_images']     = max(50, absint($config['min_images']));
        $config['initial_images'] = max($config['min_images'], absint($config['initial_images']));
        $config['max_images']     = max($config['initial_images'], absint($config['max_images']));
        $config['target_seconds'] = max(20.0, (float) $config['target_seconds']);
        $config['hard_seconds']   = max($config['target_seconds'] + 20.0, (float) $config['hard_seconds']);
        $config['growth_factor']  = min(2.0, max(1.10, (float) $config['growth_factor']));
        return $config;
    }
}

if (!function_exists('seo_images_scan_process_state')) {
    function seo_images_scan_process_state() {
        $config = seo_images_scan_adaptive_config();
        $state = get_option('seo_images_scan_process_state', array());
        $state = is_array($state) ? $state : array();
        return wp_parse_args($state, array(
            'enabled'             => 0,
            'status'              => 'stopped',
            'current_page_limit'  => $config['initial_pages'],
            'current_image_limit' => $config['initial_images'],
            'next_delay'          => 0,
            'adaptive_pressure'   => 'baja',
            'adaptive_reason'     => 'arranque conservador',
            'last_duration_ms'    => 0,
            'last_avg_request_ms' => 0,
            'last_p95_request_ms' => 0,
            'last_pressure_events'=> 0,
            'last_processed_pages'=> 0,
            'last_checked_images' => 0,
            'batches'             => 0,
            'started_at'          => 0,
            'updated_at'          => 0,
            'last_error'          => '',
        ));
    }
}

if (!function_exists('seo_images_scan_store_process_state')) {
    function seo_images_scan_store_process_state($state) {
        $state = is_array($state) ? $state : array();
        $state['updated_at'] = time();
        update_option('seo_images_scan_process_state', $state, false);
    }
}

if (!function_exists('seo_images_scan_pending_count')) {
    function seo_images_scan_pending_count() {
        global $wpdb;
        $tables = seo_images_scan_tables();
        if (!seo_images_scan_table_exists($tables['sitemap'])) {
            return 0;
        }
        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$tables['sitemap']} s
             LEFT JOIN {$tables['pages']} p ON p.sitemap_inventory_id=s.id
             WHERE s.active_in_sitemap=1
               AND (p.id IS NULL OR p.last_checked_at IS NULL)"
        );
    }
}

if (!function_exists('seo_images_scan_adaptive_plan')) {
    function seo_images_scan_adaptive_plan($state, $summary) {
        $config = seo_images_scan_adaptive_config();
        $state = is_array($state) ? $state : array();
        $summary = is_array($summary) ? $summary : array();

        $previous_pages  = max($config['min_pages'], min($config['max_pages'], absint($state['current_page_limit'] ?? $config['initial_pages'])));
        $previous_images = max($config['min_images'], min($config['max_images'], absint($state['current_image_limit'] ?? $config['initial_images'])));
        $processed       = absint($summary['processed_pages'] ?? 0);
        $selected        = absint($summary['selected_pages'] ?? $previous_pages);
        $images          = absint($summary['checked_images'] ?? 0);
        $duration_ms     = absint($summary['duration_ms'] ?? 0);
        $duration        = $duration_ms / 1000.0;
        $avg_ms          = absint($summary['avg_request_ms'] ?? 0);
        $p95_ms          = absint($summary['p95_request_ms'] ?? 0);
        $pressure_events = absint($summary['pressure_events'] ?? 0);
        $request_count   = max(1, absint($summary['request_count'] ?? 0));
        $pressure_ratio  = $pressure_events / $request_count;
        $image_limited   = $selected > $processed && $images >= (int) floor($previous_images * 0.75);

        $next_pages  = $previous_pages;
        $next_images = $previous_images;
        $delay       = 1;
        $pressure    = 'baja';
        $reason      = 'ritmo estable';

        $ideal_pages = $previous_pages;
        $ideal_images = $previous_images;
        if ($duration > 0.0 && $processed > 0) {
            $ideal_pages = (int) floor($processed * ($config['target_seconds'] / max(1.0, $duration)));
            $ideal_pages = max($config['min_pages'], min($config['max_pages'], $ideal_pages));
        }
        if ($duration > 0.0 && $images > 0) {
            $ideal_images = (int) floor($images * ($config['target_seconds'] / max(1.0, $duration)));
            $ideal_images = max($config['min_images'], min($config['max_images'], $ideal_images));
        }

        if (
            $pressure_ratio >= 0.03
            || $pressure_events >= 4
            || $p95_ms >= $config['very_slow_p95_ms']
            || ($avg_ms > 0 && $avg_ms >= 1900)
            || $duration >= $config['hard_seconds']
        ) {
            $next_pages  = max($config['min_pages'], min($ideal_pages, (int) floor($previous_pages * 0.60)));
            $next_images = max($config['min_images'], min($ideal_images, (int) floor($previous_images * 0.60)));
            $delay       = $config['critical_delay'];
            $pressure    = 'alta';
            $reason      = 'respuesta lenta o presión HTTP: se reduce el siguiente lote';
        } elseif (
            $pressure_events > 0
            || $p95_ms >= $config['slow_p95_ms']
            || ($avg_ms > 0 && $avg_ms >= 1200)
            || $duration > ($config['target_seconds'] * 1.35)
        ) {
            $next_pages  = max($config['min_pages'], min($previous_pages, $ideal_pages, (int) floor($previous_pages * 0.82)));
            $next_images = max($config['min_images'], min($previous_images, $ideal_images, (int) floor($previous_images * 0.82)));
            $delay       = $config['heavy_delay'];
            $pressure    = 'media';
            $reason      = 'latencia elevada: se ajusta el lote a la baja';
        } elseif (
            $duration > 0.0
            && $duration < ($config['target_seconds'] * 1.10)
            && ($p95_ms === 0 || $p95_ms < $config['fast_p95_ms'])
            && ($avg_ms === 0 || $avg_ms < 800)
            && 0 === $pressure_events
        ) {
            $page_growth_cap  = max($previous_pages + 25, (int) ceil($previous_pages * $config['growth_factor']));
            $image_growth_cap = max($previous_images + 200, (int) ceil($previous_images * $config['growth_factor']));

            if (!$image_limited) {
                $next_pages = min($config['max_pages'], max($previous_pages, $ideal_pages), $page_growth_cap);
            }
            $next_images = min($config['max_images'], max($previous_images, $ideal_images), $image_growth_cap);
            $delay       = 1;
            $reason      = $image_limited
                ? 'servidor rápido: se amplía el presupuesto de imágenes'
                : 'servidor rápido: se amplía automáticamente el siguiente lote';
        } else {
            if ($ideal_pages < $previous_pages) {
                $next_pages = max($config['min_pages'], $ideal_pages);
            }
            if ($ideal_images < $previous_images) {
                $next_images = max($config['min_images'], $ideal_images);
            }
            $reason = 'ajuste al tiempo real observado';
        }

        return array(
            'page_limit'   => max($config['min_pages'], min($config['max_pages'], absint($next_pages))),
            'image_limit'  => max($config['min_images'], min($config['max_images'], absint($next_images))),
            'delay'        => max(1, absint($delay)),
            'pressure'     => $pressure,
            'reason'       => $reason,
        );
    }
}

if (!function_exists('seo_images_scan_unschedule_continue')) {
    function seo_images_scan_unschedule_continue() {
        $hook = 'seo_images_scan_auto_continue';
        $group = 'seo-system-image-scan';
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook, array(), $group);
        }
        wp_clear_scheduled_hook($hook);
    }
}

if (!function_exists('seo_images_scan_schedule_continue')) {
    function seo_images_scan_schedule_continue($delay = 1) {
        $delay = max(1, absint($delay));
        $hook = 'seo_images_scan_auto_continue';
        $group = 'seo-system-image-scan';
        $scheduled = false;

        if ($delay <= 1 && function_exists('as_enqueue_async_action')) {
            $action_id = as_enqueue_async_action($hook, array(), $group, true, 10);
            $scheduled = 0 < absint($action_id);
        } elseif (function_exists('as_schedule_single_action')) {
            $action_id = as_schedule_single_action(time() + $delay, $hook, array(), $group, true, 10);
            $scheduled = 0 < absint($action_id);
        }

        if (false === wp_next_scheduled($hook)) {
            $cron = wp_schedule_single_event(time() + max(2, $delay), $hook, array(), true);
            if (!is_wp_error($cron) && true === $cron) {
                $scheduled = true;
            }
        }
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
        return $scheduled;
    }
}

if (!function_exists('seo_images_scan_choose_batch')) {
    function seo_images_scan_choose_batch($limit = SEO_IMAGES_SCAN_PAGE_LIMIT) {
        global $wpdb;
        $tables = seo_images_scan_tables();
        $limit = max(1, min(SEO_IMAGES_SCAN_PAGE_LIMIT, absint($limit)));

        if (!seo_images_scan_table_exists($tables['sitemap'])) {
            return new WP_Error(
                'seo_images_scan_no_sitemap_inventory',
                'No existe el inventario de URLs del sitemap. Actualízalo primero en SEO Marketing → Escaneo.'
            );
        }

        // La fuente de verdad son TODAS las URLs activas del sitemap. El auditor de
        // imágenes debe recorrerlas una a una, no depender de que exista previamente
        // una fila en seo_image_scan_pages.
        $wpdb->last_error = '';
        $active_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$tables['sitemap']} WHERE active_in_sitemap=1"
        );
        if ($wpdb->last_error) {
            return new WP_Error(
                'seo_images_scan_source_db',
                'No se pudo leer el inventario de URLs: ' . $wpdb->last_error
            );
        }
        if ($active_count < 1) {
            return new WP_Error(
                'seo_images_scan_source_empty',
                'El inventario del sitemap existe, pero no contiene URLs activas. Actualiza el inventario en SEO Marketing → Escaneo.'
            );
        }

        // Primera cobertura: páginas nunca comprobadas. La relación se hace por el
        // ID numérico del inventario para evitar problemas de collation o diferencias
        // de normalización en hashes de versiones anteriores.
        $wpdb->last_error = '';
        $pending = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id, s.url, s.url_hash
                 FROM {$tables['sitemap']} s
                 LEFT JOIN {$tables['pages']} p ON p.sitemap_inventory_id = s.id
                 WHERE s.active_in_sitemap = 1
                   AND (p.id IS NULL OR p.last_checked_at IS NULL)
                 ORDER BY s.id ASC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        if ($wpdb->last_error) {
            return new WP_Error(
                'seo_images_scan_pending_db',
                'No se pudieron seleccionar páginas pendientes: ' . $wpdb->last_error
            );
        }
        if (!empty($pending)) {
            return array('mode' => 'coverage', 'items' => $pending);
        }

        // Cobertura terminada: incidencias primero y después las páginas que llevan
        // más tiempo sin revisarse. No restringimos por estados concretos para que
        // una fila antigua/legacy nunca deje al auditor sin páginas seleccionables.
        $selected = array();

        $wpdb->last_error = '';
        $error_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id, s.url, s.url_hash
                 FROM {$tables['sitemap']} s
                 INNER JOIN {$tables['pages']} p ON p.sitemap_inventory_id = s.id
                 WHERE s.active_in_sitemap = 1
                   AND p.last_checked_at IS NOT NULL
                   AND p.status_bucket IN ('error','page_error','partial')
                 ORDER BY p.last_checked_at ASC, s.id ASC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        if ($wpdb->last_error) {
            return new WP_Error(
                'seo_images_scan_errors_db',
                'No se pudieron seleccionar páginas con incidencias: ' . $wpdb->last_error
            );
        }
        foreach ($error_rows as $row) {
            $selected[(int) $row['id']] = $row;
        }

        $remaining = $limit - count($selected);
        if ($remaining > 0) {
            $wpdb->last_error = '';
            $oldest = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT s.id, s.url, s.url_hash
                     FROM {$tables['sitemap']} s
                     INNER JOIN {$tables['pages']} p ON p.sitemap_inventory_id = s.id
                     WHERE s.active_in_sitemap = 1
                       AND p.last_checked_at IS NOT NULL
                     ORDER BY
                       CASE
                         WHEN p.status_bucket IN ('error','page_error','partial') THEN 0
                         WHEN p.status_bucket='warning' THEN 1
                         ELSE 2
                       END ASC,
                       p.last_checked_at ASC,
                       s.id ASC
                     LIMIT %d",
                    max($remaining * 3, $remaining)
                ),
                ARRAY_A
            );
            if ($wpdb->last_error) {
                return new WP_Error(
                    'seo_images_scan_oldest_db',
                    'No se pudieron seleccionar páginas para mantenimiento: ' . $wpdb->last_error
                );
            }
            foreach ($oldest as $row) {
                $key = (int) $row['id'];
                if (!isset($selected[$key])) {
                    $selected[$key] = $row;
                }
                if (count($selected) >= $limit) {
                    break;
                }
            }
        }

        // Salvaguarda final: si hay URLs activas, nunca devolvemos un lote vacío por
        // datos legacy en seo_image_scan_pages. Esto fuerza una rotación válida.
        $remaining = $limit - count($selected);
        if ($remaining > 0) {
            $wpdb->last_error = '';
            $fallback = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT s.id, s.url, s.url_hash
                     FROM {$tables['sitemap']} s
                     LEFT JOIN {$tables['pages']} p ON p.sitemap_inventory_id = s.id
                     WHERE s.active_in_sitemap = 1
                     ORDER BY p.last_checked_at IS NULL DESC, p.last_checked_at ASC, s.id ASC
                     LIMIT %d",
                    max($remaining * 3, $remaining)
                ),
                ARRAY_A
            );
            if ($wpdb->last_error) {
                return new WP_Error(
                    'seo_images_scan_fallback_db',
                    'No se pudo completar la selección de páginas: ' . $wpdb->last_error
                );
            }
            foreach ($fallback as $row) {
                $key = (int) $row['id'];
                if (!isset($selected[$key])) {
                    $selected[$key] = $row;
                }
                if (count($selected) >= $limit) {
                    break;
                }
            }
        }

        if (empty($selected)) {
            return new WP_Error(
                'seo_images_scan_selection_empty',
                sprintf('Hay %d URLs activas en el sitemap, pero MySQL no pudo seleccionar ninguna para el lote.', $active_count)
            );
        }

        return array('mode' => 'maintenance', 'items' => array_values($selected));
    }
}

if (!function_exists('seo_images_scan_launch_next_batch')) {
    /**
     * Reserva y despacha un único lote. La continuidad se controla aparte con
     * seo_images_scan_process_state, por lo que esta función también puede ser
     * llamada desde Action Scheduler sin una sesión de administrador abierta.
     */
    function seo_images_scan_launch_next_batch($validate_workflow = false) {
        global $wpdb;
        $tables = seo_images_scan_tables();

        if (seo_images_scan_active_run()) {
            return new WP_Error('seo_images_scan_active', 'Ya existe un lote de imágenes activo.');
        }

        $state = seo_images_scan_process_state();
        if (empty($state['enabled'])) {
            return new WP_Error('seo_images_scan_stopped', 'El proceso automático está detenido.');
        }

        $config = seo_images_scan_runner_config();
        $missing = seo_images_scan_config_errors($config);
        if (!empty($missing)) {
            return new WP_Error('seo_images_scan_config', 'Conexión GitHub incompleta: ' . implode(', ', $missing));
        }

        if ($validate_workflow) {
            $workflow_check = seo_images_scan_validate_workflow($config);
            if (is_wp_error($workflow_check)) {
                return $workflow_check;
            }
        }

        $adaptive = seo_images_scan_adaptive_config();
        $page_limit = max(
            $adaptive['min_pages'],
            min($adaptive['max_pages'], absint($state['current_page_limit'] ?? $adaptive['initial_pages']))
        );
        $image_limit = max(
            $adaptive['min_images'],
            min($adaptive['max_images'], absint($state['current_image_limit'] ?? $adaptive['initial_images']))
        );

        $batch = seo_images_scan_choose_batch($page_limit);
        if (is_wp_error($batch)) {
            return $batch;
        }
        if (empty($batch['items'])) {
            return new WP_Error('seo_images_scan_empty', 'No se pudo formar un lote de páginas.');
        }

        $scan_uuid = wp_generate_uuid4();
        $callback_token = bin2hex(random_bytes(32));
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            $tables['runs'],
            array(
                'scan_uuid' => $scan_uuid,
                'status' => 'queued',
                'mode' => sanitize_key($batch['mode']),
                'total_pages' => count($batch['items']),
                'target_images' => $image_limit,
                'adaptive_reason' => substr(sanitize_text_field((string) ($state['adaptive_reason'] ?? '')), 0, 255),
                'callback_token_hash' => hash('sha256', $callback_token),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s','%s','%s','%d','%d','%s','%s','%s','%s')
        );
        if ($inserted === false) {
            return new WP_Error('seo_images_scan_db_error', $wpdb->last_error ?: 'No se pudo crear el lote en la base de datos.');
        }
        $run_id = (int) $wpdb->insert_id;

        foreach ($batch['items'] as $item) {
            $page_url = esc_url_raw((string) $item['url']);
            $hash = isset($item['url_hash']) && preg_match('/^[a-f0-9]{32}$/i', (string) $item['url_hash'])
                ? strtolower((string) $item['url_hash'])
                : md5($page_url);
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$tables['pages']}
                     (sitemap_inventory_id,page_url_hash,page_url,status_bucket,queued_scan_id,queued_at,first_seen_at,last_seen_at)
                     VALUES (%d,%s,%s,'pending',%d,%s,%s,%s)
                     ON DUPLICATE KEY UPDATE
                        sitemap_inventory_id=VALUES(sitemap_inventory_id),
                        page_url=VALUES(page_url),
                        queued_scan_id=VALUES(queued_scan_id),
                        queued_at=VALUES(queued_at),
                        last_seen_at=VALUES(last_seen_at)",
                    absint($item['id']),
                    $hash,
                    $page_url,
                    $run_id,
                    $now,
                    $now,
                    $now
                )
            );
        }

        $batch_url = add_query_arg('scan_id', rawurlencode($scan_uuid), $config['batch_endpoint']);
        $endpoint = sprintf(
            'https://api.github.com/repos/%1$s/%2$s/actions/workflows/%3$s/dispatches',
            rawurlencode($config['owner']),
            rawurlencode($config['repo']),
            rawurlencode($config['workflow'])
        );
        $payload = array(
            'ref' => $config['ref'],
            'inputs' => array(
                'scan_id' => $scan_uuid,
                'batch_url' => $batch_url,
                'callback_url' => $config['callback_url'],
                'callback_token' => $callback_token,
            ),
        );

        $response = function_exists('seo_github_python_runner_api_request')
            ? seo_github_python_runner_api_request('POST', $endpoint, $payload)
            : new WP_Error('seo_images_scan_runner_missing', 'La conexión GitHub Python Runner no está disponible.');

        if (is_wp_error($response)) {
            $wpdb->update(
                $tables['runs'],
                array(
                    'status' => 'failed',
                    'error_message' => substr($response->get_error_message(), 0, 4000),
                    'callback_token_hash' => '',
                    'completed_at' => $now,
                    'updated_at' => $now,
                ),
                array('id' => $run_id),
                array('%s','%s','%s','%s','%s'),
                array('%d')
            );
            $wpdb->update(
                $tables['pages'],
                array('queued_scan_id' => 0, 'queued_at' => null),
                array('queued_scan_id' => $run_id),
                array('%d','%s'),
                array('%d')
            );
            return new WP_Error('seo_images_scan_github_dispatch', $response->get_error_message());
        }

        $state['status'] = 'running';
        $state['batches'] = absint($state['batches'] ?? 0) + 1;
        $state['last_error'] = '';
        seo_images_scan_store_process_state($state);

        return array(
            'run_id' => $run_id,
            'scan_uuid' => $scan_uuid,
            'pages' => count($batch['items']),
            'images' => $image_limit,
            'mode' => sanitize_key($batch['mode']),
        );
    }
}

if (!function_exists('seo_images_scan_handle_start')) {
    function seo_images_scan_handle_start() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        check_admin_referer('seo_images_scan_start');
        if ((string) get_option('seo_images_scan_schema_version', '') !== SEO_IMAGES_SCAN_SCHEMA_VERSION) {
            seo_images_scan_install_schema();
        }

        $state = seo_images_scan_process_state();
        if (!empty($state['enabled']) || seo_images_scan_active_run()) {
            seo_images_scan_redirect('active');
        }

        $adaptive = seo_images_scan_adaptive_config();
        $state['enabled'] = 1;
        $state['status'] = 'starting';
        $state['started_at'] = time();
        $state['last_error'] = '';
        $state['next_delay'] = 0;
        // Conserva el ritmo aprendido si ya existía; en una instalación nueva
        // comienza con 200 páginas y unas 1.200 imágenes por lote.
        $state['current_page_limit'] = max($adaptive['min_pages'], min($adaptive['max_pages'], absint($state['current_page_limit'] ?? $adaptive['initial_pages'])));
        $state['current_image_limit'] = max($adaptive['min_images'], min($adaptive['max_images'], absint($state['current_image_limit'] ?? $adaptive['initial_images'])));
        seo_images_scan_store_process_state($state);

        $result = seo_images_scan_launch_next_batch(true);
        if (is_wp_error($result)) {
            $state = seo_images_scan_process_state();
            $state['enabled'] = 0;
            $state['status'] = 'error';
            $state['last_error'] = $result->get_error_message();
            seo_images_scan_store_process_state($state);

            $code = $result->get_error_code();
            if (in_array($code, array('seo_images_scan_workflow_missing','seo_images_scan_workflow_inactive'), true)) {
                seo_images_scan_redirect('workflow_missing', array('detail' => rawurlencode($result->get_error_message())));
            }
            if (in_array($code, array('seo_images_scan_config','seo_images_scan_runner_missing'), true)) {
                seo_images_scan_redirect('config', array('detail' => rawurlencode($result->get_error_message())));
            }
            if ('seo_images_scan_github_dispatch' === $code) {
                seo_images_scan_redirect('github_error', array('detail' => rawurlencode($result->get_error_message())));
            }
            seo_images_scan_redirect('inventory', array('detail' => rawurlencode($result->get_error_message())));
        }

        seo_images_scan_redirect('started', array('count' => absint($result['pages'])));
    }
}
add_action('admin_post_seo_images_scan_start', 'seo_images_scan_handle_start');

if (!function_exists('seo_images_scan_handle_stop')) {
    function seo_images_scan_handle_stop() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        check_admin_referer('seo_images_scan_stop');
        global $wpdb;
        $tables = seo_images_scan_tables();

        $state = seo_images_scan_process_state();
        $was_enabled = !empty($state['enabled']);
        $state['enabled'] = 0;
        $state['status'] = 'stopped';
        $state['next_delay'] = 0;
        $state['adaptive_reason'] = 'detenido manualmente';
        seo_images_scan_store_process_state($state);
        seo_images_scan_unschedule_continue();

        $run_id = isset($_POST['run_id']) ? absint($_POST['run_id']) : 0;
        $run = null;
        if ($run_id > 0) {
            $run = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['runs']} WHERE id=%d", $run_id), ARRAY_A);
        }
        if (!$run) {
            $run = seo_images_scan_active_run();
        }

        if ($run && in_array($run['status'], array('queued','running'), true)) {
            $run_id = absint($run['id']);
            $now = current_time('mysql', true);
            $wpdb->update(
                $tables['runs'],
                array('status' => 'cancelled', 'callback_token_hash' => '', 'completed_at' => $now, 'updated_at' => $now),
                array('id' => $run_id),
                array('%s','%s','%s','%s'),
                array('%d')
            );
            $wpdb->update(
                $tables['pages'],
                array('queued_scan_id' => 0, 'queued_at' => null),
                array('queued_scan_id' => $run_id),
                array('%d','%s'),
                array('%d')
            );
            seo_images_scan_redirect('cancelled');
        }

        if ($was_enabled) {
            seo_images_scan_redirect('cancelled');
        }
        seo_images_scan_redirect('cancel_not_running');
    }
}
add_action('admin_post_seo_images_scan_stop', 'seo_images_scan_handle_stop');

if (!function_exists('seo_images_scan_auto_continue')) {
    function seo_images_scan_auto_continue() {
        $state = seo_images_scan_process_state();
        if (empty($state['enabled'])) {
            return;
        }
        if (seo_images_scan_active_run()) {
            return;
        }
        if (seo_images_scan_pending_count() < 1) {
            $state['enabled'] = 0;
            $state['status'] = 'complete';
            $state['next_delay'] = 0;
            $state['adaptive_reason'] = 'cobertura inicial completada al 100%';
            seo_images_scan_store_process_state($state);
            seo_images_scan_unschedule_continue();
            return;
        }

        $result = seo_images_scan_launch_next_batch(false);
        if (is_wp_error($result)) {
            $state = seo_images_scan_process_state();
            $state['enabled'] = 0;
            $state['status'] = 'error';
            $state['last_error'] = $result->get_error_message();
            $state['adaptive_reason'] = 'la cadena automática se detuvo por un error';
            seo_images_scan_store_process_state($state);
            seo_images_scan_unschedule_continue();
        }
    }
}
add_action('seo_images_scan_auto_continue', 'seo_images_scan_auto_continue');

if (!function_exists('seo_images_scan_auth_run')) {
    function seo_images_scan_auth_run($request) {
        global $wpdb;
        $tables = seo_images_scan_tables();
        $scan_uuid = sanitize_text_field((string) $request->get_param('scan_id'));
        if ($scan_uuid === '') {
            $json = $request->get_json_params();
            if (is_array($json) && !empty($json['scan_id'])) {
                $scan_uuid = sanitize_text_field((string) $json['scan_id']);
            }
        }
        if ($scan_uuid === '') {
            return new WP_Error('seo_images_scan_missing_id', 'Falta scan_id.', array('status' => 400));
        }
        $run = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['runs']} WHERE scan_uuid=%s LIMIT 1", $scan_uuid),
            ARRAY_A
        );
        if (!$run) {
            return new WP_Error('seo_images_scan_unknown', 'Ejecución desconocida.', array('status' => 404));
        }
        if (!in_array($run['status'], array('queued','running'), true)) {
            return new WP_Error('seo_images_scan_inactive', 'La ejecución ya no está activa.', array('status' => 409));
        }
        $auth = trim((string) $request->get_header('authorization'));
        $token = preg_replace('/^Bearer\s+/i', '', $auth);
        $stored = trim((string) $run['callback_token_hash']);
        if ($token === '' || $stored === '' || !hash_equals($stored, hash('sha256', $token))) {
            return new WP_Error('seo_images_scan_auth', 'Token no válido.', array('status' => 403));
        }
        return $run;
    }
}

if (!function_exists('seo_images_scan_rest_batch')) {
    function seo_images_scan_rest_batch($request) {
        global $wpdb;
        $tables = seo_images_scan_tables();
        $run = seo_images_scan_auth_run($request);
        if (is_wp_error($run)) {
            return $run;
        }
        $run_page_limit = max(1, min(SEO_IMAGES_SCAN_PAGE_LIMIT, absint($run['total_pages'] ?? SEO_IMAGES_SCAN_INITIAL_PAGE_LIMIT)));
        $run_image_limit = max(50, absint($run['target_images'] ?? SEO_IMAGES_SCAN_INITIAL_IMAGE_LIMIT));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT page_url FROM {$tables['pages']} WHERE queued_scan_id=%d ORDER BY id ASC LIMIT %d",
                absint($run['id']),
                $run_page_limit
            ),
            ARRAY_A
        );
        $now = current_time('mysql', true);
        $update = array('status' => 'running', 'updated_at' => $now);
        $formats = array('%s','%s');
        if (empty($run['started_at'])) {
            $update['started_at'] = $now;
            $formats[] = '%s';
        }
        $wpdb->update($tables['runs'], $update, array('id' => absint($run['id'])), $formats, array('%d'));
        return rest_ensure_response(array(
            'scan_id' => $run['scan_uuid'],
            'items' => array_map(static function($row) {
                return array('page_url' => esc_url_raw((string) $row['page_url']));
            }, $rows),
            'limits' => array(
                'pages' => $run_page_limit,
                'images_soft' => $run_image_limit,
            ),
        ));
    }
}

if (!function_exists('seo_images_scan_clean_status')) {
    function seo_images_scan_clean_status($status) {
        $status = sanitize_key((string) $status);
        $allowed = array('ok','warning','error','page_error','partial','redirect','404','403','429','4xx','5xx','timeout','network','ssl','not_image','other');
        return in_array($status, $allowed, true) ? $status : 'other';
    }
}

if (!function_exists('seo_images_scan_process_page_result')) {
    function seo_images_scan_process_page_result($run, $page_result) {
        global $wpdb;
        $tables = seo_images_scan_tables();
        if (!is_array($page_result)) {
            return;
        }
        $page_url = esc_url_raw((string) ($page_result['page_url'] ?? ''));
        if ($page_url === '') {
            return;
        }
        $hash = md5($page_url);
        $page = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['pages']} WHERE page_url_hash=%s LIMIT 1", $hash),
            ARRAY_A
        );
        if (!$page || (int) $page['queued_scan_id'] !== (int) $run['id']) {
            return;
        }
        $page_id = absint($page['id']);
        $checked_at = current_time('mysql', true);
        if (!empty($page_result['checked_at'])) {
            $ts = strtotime((string) $page_result['checked_at']);
            if ($ts) {
                $checked_at = gmdate('Y-m-d H:i:s', $ts);
            }
        }

        $wpdb->update(
            $tables['items'],
            array('active_on_page' => 0),
            array('page_id' => $page_id),
            array('%d'),
            array('%d')
        );

        $images = isset($page_result['images']) && is_array($page_result['images']) ? $page_result['images'] : array();
        $total = 0;
        $ok = 0;
        $warnings = 0;
        $errors = 0;
        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $image_url = esc_url_raw((string) ($image['image_url'] ?? ''));
            if ($image_url === '') {
                continue;
            }
            $total++;
            $bucket = seo_images_scan_clean_status($image['status_bucket'] ?? 'other');
            if ($bucket === 'ok') {
                $ok++;
            } elseif ($bucket === 'redirect') {
                $warnings++;
            } else {
                $errors++;
            }
            $image_hash = md5($image_url);
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$tables['items']}
                    (page_id,page_url_hash,image_url_hash,image_url,source_tag,source_attr,active_on_page,status_bucket,http_status,final_status,final_url,redirect_count,content_type,response_ms,error_type,error_message,last_scan_id,first_seen_at,last_seen_at,last_checked_at)
                    VALUES (%d,%s,%s,%s,%s,%s,1,%s,%d,%d,%s,%d,%s,%d,%s,%s,%d,%s,%s,%s)
                    ON DUPLICATE KEY UPDATE
                        page_id=VALUES(page_id),image_url=VALUES(image_url),source_tag=VALUES(source_tag),source_attr=VALUES(source_attr),active_on_page=1,
                        status_bucket=VALUES(status_bucket),http_status=VALUES(http_status),final_status=VALUES(final_status),final_url=VALUES(final_url),redirect_count=VALUES(redirect_count),
                        content_type=VALUES(content_type),response_ms=VALUES(response_ms),error_type=VALUES(error_type),error_message=VALUES(error_message),last_scan_id=VALUES(last_scan_id),
                        last_seen_at=VALUES(last_seen_at),last_checked_at=VALUES(last_checked_at)",
                    $page_id,
                    $hash,
                    $image_hash,
                    $image_url,
                    sanitize_key((string) ($image['source_tag'] ?? 'img')),
                    sanitize_key((string) ($image['source_attr'] ?? 'src')),
                    $bucket,
                    absint($image['http_status'] ?? 0),
                    absint($image['final_status'] ?? 0),
                    esc_url_raw((string) ($image['final_url'] ?? '')),
                    absint($image['redirect_count'] ?? 0),
                    sanitize_text_field((string) ($image['content_type'] ?? '')),
                    absint($image['response_ms'] ?? 0),
                    sanitize_key((string) ($image['error_type'] ?? '')),
                    substr(sanitize_text_field((string) ($image['error_message'] ?? '')), 0, 1000),
                    absint($run['id']),
                    $checked_at,
                    $checked_at,
                    $checked_at
                )
            );
        }

        $page_status = seo_images_scan_clean_status($page_result['status_bucket'] ?? 'ok');
        if (!in_array($page_status, array('ok','warning','error','page_error','partial'), true)) {
            $page_status = $errors > 0 ? 'error' : ($warnings > 0 ? 'warning' : 'ok');
        }
        $same_run = (int) $page['last_scan_id'] === (int) $run['id'];
        $consecutive = (int) $page['consecutive_errors'];
        $checks_total = (int) $page['checks_total'];
        if (!$same_run) {
            $checks_total++;
            if (in_array($page_status, array('error','page_error','partial'), true)) {
                $consecutive++;
            } else {
                $consecutive = 0;
            }
        }

        $data = array(
            'page_url' => $page_url,
            'status_bucket' => $page_status,
            'page_http_status' => absint($page_result['page_http_status'] ?? 0),
            'response_ms' => absint($page_result['response_ms'] ?? 0),
            'total_images' => $total,
            'ok_images' => $ok,
            'warning_images' => $warnings,
            'error_images' => $errors,
            'error_type' => sanitize_key((string) ($page_result['error_type'] ?? '')),
            'error_message' => substr(sanitize_text_field((string) ($page_result['error_message'] ?? '')), 0, 2000),
            'last_scan_id' => absint($run['id']),
            'queued_scan_id' => 0,
            'queued_at' => null,
            'last_seen_at' => $checked_at,
            'last_checked_at' => $checked_at,
            'consecutive_errors' => $consecutive,
            'checks_total' => $checks_total,
        );
        $formats = array('%s','%s','%d','%d','%d','%d','%d','%d','%s','%s','%d','%d','%s','%s','%s','%d','%d');
        if ($page_status === 'ok' || $page_status === 'warning') {
            $data['last_ok_at'] = $checked_at;
            $formats[] = '%s';
        }
        if (in_array($page_status, array('error','page_error','partial'), true)) {
            $data['last_error_at'] = $checked_at;
            $formats[] = '%s';
        }
        $wpdb->update($tables['pages'], $data, array('id' => $page_id), $formats, array('%d'));
    }
}

if (!function_exists('seo_images_scan_refresh_run_stats')) {
    function seo_images_scan_refresh_run_stats($run_id) {
        global $wpdb;
        $tables = seo_images_scan_tables();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS processed_pages,
                        COALESCE(SUM(total_images),0) AS total_images,
                        COALESCE(SUM(ok_images),0) AS ok_images,
                        COALESCE(SUM(warning_images),0) AS warning_images,
                        COALESCE(SUM(error_images),0) AS error_images,
                        COALESCE(SUM(CASE WHEN status_bucket='page_error' THEN 1 ELSE 0 END),0) AS page_errors
                 FROM {$tables['pages']}
                 WHERE last_scan_id=%d",
                absint($run_id)
            ),
            ARRAY_A
        );
        if (!$row) {
            return;
        }
        $wpdb->update(
            $tables['runs'],
            array(
                'processed_pages' => absint($row['processed_pages']),
                'total_images' => absint($row['total_images']),
                'ok_images' => absint($row['ok_images']),
                'warning_images' => absint($row['warning_images']),
                'error_images' => absint($row['error_images']),
                'page_errors' => absint($row['page_errors']),
                'updated_at' => current_time('mysql', true),
            ),
            array('id' => absint($run_id)),
            array('%d','%d','%d','%d','%d','%d','%s'),
            array('%d')
        );
    }
}

if (!function_exists('seo_images_scan_rest_results')) {
    function seo_images_scan_rest_results($request) {
        global $wpdb;
        $tables = seo_images_scan_tables();
        $run = seo_images_scan_auth_run($request);
        if (is_wp_error($run)) {
            return $run;
        }
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('seo_images_scan_payload', 'Payload JSON no válido.', array('status' => 400));
        }
        $event = sanitize_key((string) ($payload['event'] ?? 'batch'));
        $now = current_time('mysql', true);

        if ($event === 'start') {
            $wpdb->update(
                $tables['runs'],
                array('status' => 'running', 'started_at' => $run['started_at'] ?: $now, 'updated_at' => $now),
                array('id' => absint($run['id'])),
                array('%s','%s','%s'),
                array('%d')
            );
            return rest_ensure_response(array('ok' => true));
        }

        if ($event === 'batch') {
            $pages = isset($payload['pages']) && is_array($payload['pages']) ? array_slice($payload['pages'], 0, SEO_IMAGES_SCAN_PAGE_LIMIT) : array();
            foreach ($pages as $page_result) {
                seo_images_scan_process_page_result($run, $page_result);
            }
            seo_images_scan_refresh_run_stats(absint($run['id']));
            $wpdb->update($tables['runs'], array('status' => 'running', 'updated_at' => $now), array('id' => absint($run['id'])), array('%s','%s'), array('%d'));
            return rest_ensure_response(array('ok' => true, 'pages' => count($pages)));
        }

        if ($event === 'complete') {
            $summary = isset($payload['summary']) && is_array($payload['summary']) ? $payload['summary'] : array();
            seo_images_scan_refresh_run_stats(absint($run['id']));

            $duration_ms     = absint($summary['duration_ms'] ?? 0);
            $avg_request_ms  = absint($summary['avg_request_ms'] ?? 0);
            $p95_request_ms  = absint($summary['p95_request_ms'] ?? 0);
            $pressure_events = absint($summary['pressure_events'] ?? 0);
            $request_count   = absint($summary['request_count'] ?? 0);

            $wpdb->update(
                $tables['runs'],
                array(
                    'status' => 'completed',
                    'duration_ms' => $duration_ms,
                    'avg_request_ms' => $avg_request_ms,
                    'p95_request_ms' => $p95_request_ms,
                    'pressure_events' => $pressure_events,
                    'request_count' => $request_count,
                    'callback_token_hash' => '',
                    'completed_at' => $now,
                    'updated_at' => $now,
                ),
                array('id' => absint($run['id'])),
                array('%s','%d','%d','%d','%d','%d','%s','%s','%s'),
                array('%d')
            );
            // Las páginas seleccionadas que el worker no llegó a abrir por el
            // presupuesto de imágenes vuelven a quedar libres para el siguiente lote.
            $wpdb->update(
                $tables['pages'],
                array('queued_scan_id' => 0, 'queued_at' => null),
                array('queued_scan_id' => absint($run['id'])),
                array('%d','%s'),
                array('%d')
            );

            $state = seo_images_scan_process_state();
            $next = null;
            if (!empty($state['enabled'])) {
                $plan = seo_images_scan_adaptive_plan($state, $summary);
                $state['current_page_limit']   = absint($plan['page_limit']);
                $state['current_image_limit']  = absint($plan['image_limit']);
                $state['next_delay']           = absint($plan['delay']);
                $state['adaptive_pressure']    = sanitize_key($plan['pressure']);
                $state['adaptive_reason']      = sanitize_text_field($plan['reason']);
                $state['last_duration_ms']     = $duration_ms;
                $state['last_avg_request_ms']  = $avg_request_ms;
                $state['last_p95_request_ms']  = $p95_request_ms;
                $state['last_pressure_events'] = $pressure_events;
                $state['last_processed_pages'] = absint($summary['processed_pages'] ?? 0);
                $state['last_checked_images']  = absint($summary['checked_images'] ?? 0);
                $state['status'] = 'waiting';

                $pending = seo_images_scan_pending_count();
                if ($pending < 1) {
                    $state['enabled'] = 0;
                    $state['status'] = 'complete';
                    $state['next_delay'] = 0;
                    $state['adaptive_reason'] = 'cobertura inicial completada al 100%';
                    seo_images_scan_store_process_state($state);
                    seo_images_scan_unschedule_continue();
                    $next = array('scheduled' => false, 'complete' => true);
                } else {
                    seo_images_scan_store_process_state($state);
                    $scheduled = seo_images_scan_schedule_continue($plan['delay']);
                    if (!$scheduled) {
                        $state = seo_images_scan_process_state();
                        $state['enabled'] = 0;
                        $state['status'] = 'error';
                        $state['last_error'] = 'No se pudo programar automáticamente el siguiente lote.';
                        seo_images_scan_store_process_state($state);
                    }
                    $next = array(
                        'scheduled' => (bool) $scheduled,
                        'delay' => absint($plan['delay']),
                        'page_limit' => absint($plan['page_limit']),
                        'image_limit' => absint($plan['image_limit']),
                        'pending_pages' => $pending,
                    );
                }
            }

            return rest_ensure_response(array('ok' => true, 'next' => $next));
        }

        if ($event === 'failed') {
            $message = substr(sanitize_text_field((string) ($payload['error_message'] ?? 'Fallo del worker.')), 0, 4000);
            $wpdb->update(
                $tables['runs'],
                array('status' => 'failed', 'error_message' => $message, 'callback_token_hash' => '', 'completed_at' => $now, 'updated_at' => $now),
                array('id' => absint($run['id'])),
                array('%s','%s','%s','%s','%s'),
                array('%d')
            );
            $wpdb->update(
                $tables['pages'],
                array('queued_scan_id' => 0, 'queued_at' => null),
                array('queued_scan_id' => absint($run['id'])),
                array('%d','%s'),
                array('%d')
            );
            $state = seo_images_scan_process_state();
            if (!empty($state['enabled'])) {
                $state['enabled'] = 0;
                $state['status'] = 'error';
                $state['last_error'] = $message;
                $state['adaptive_reason'] = 'worker fallido: la cadena automática se ha detenido';
                seo_images_scan_store_process_state($state);
                seo_images_scan_unschedule_continue();
            }
            return rest_ensure_response(array('ok' => true));
        }

        return new WP_Error('seo_images_scan_event', 'Evento no reconocido.', array('status' => 400));
    }
}

if (!function_exists('seo_images_scan_register_rest_routes')) {
    function seo_images_scan_register_rest_routes() {
        // Los callbacks de GitHub no pasan por admin_init. Asegura la migración
        // antes de recibir un resultado si acabamos de actualizar el módulo.
        if ((string) get_option('seo_images_scan_schema_version', '') !== SEO_IMAGES_SCAN_SCHEMA_VERSION) {
            seo_images_scan_install_schema();
        }
        register_rest_route('seo-system/v1', '/image-scan/batch', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'seo_images_scan_rest_batch',
            'permission_callback' => '__return_true',
        ));
        register_rest_route('seo-system/v1', '/image-scan/results', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'seo_images_scan_rest_results',
            'permission_callback' => '__return_true',
        ));
    }
}
add_action('rest_api_init', 'seo_images_scan_register_rest_routes');

if (!function_exists('seo_images_scan_admin_notice')) {
    function seo_images_scan_admin_notice() {
        $message = isset($_GET['scan_msg']) ? sanitize_key(wp_unslash($_GET['scan_msg'])) : '';
        if ($message === '') {
            return;
        }
        $count = isset($_GET['count']) ? absint($_GET['count']) : 0;
        $detail = isset($_GET['detail']) ? sanitize_text_field(rawurldecode((string) $_GET['detail'])) : '';
        $map = array(
            'started' => array('success', sprintf('Escaneo automático iniciado. Primer lote: hasta %d páginas.', $count)),
            'active' => array('warning', 'Ya hay un proceso automático de imágenes activo. Puedes detenerlo con el botón Parar proceso.'),
            'config' => array('error', 'La conexión GitHub Python Runner está incompleta.'),
            'inventory' => array('error', 'No se puede seleccionar el lote de páginas.'),
            'empty' => array('warning', 'No se pudo formar un lote de páginas. El auditor usa todas las URLs activas del inventario del sitemap.'),
            'github_error' => array('error', 'GitHub no pudo aceptar el workflow de imágenes.'),
            'workflow_missing' => array('error', 'El workflow de imágenes no está disponible en GitHub.'),
            'db_error' => array('error', 'No se pudo crear el lote de imágenes en la base de datos.'),
            'cancelled' => array('success', 'Proceso automático de imágenes detenido. No se lanzarán más lotes y el lote activo ha quedado invalidado.'),
            'cancel_missing' => array('error', 'No se pudo identificar el proceso a detener.'),
            'cancel_not_running' => array('warning', 'Ese proceso ya no estaba activo.'),
        );
        if (!isset($map[$message])) {
            return;
        }
        $text = $map[$message][1];
        if ($detail !== '') {
            $text .= ' ' . $detail;
        }
        echo '<div class="notice notice-' . esc_attr($map[$message][0]) . ' is-dismissible"><p><strong>' . esc_html($text) . '</strong></p></div>';
    }
}

if (!function_exists('seo_images_scan_summary')) {
    function seo_images_scan_summary() {
        global $wpdb;
        $tables = seo_images_scan_tables();
        $summary = array(
            'total_pages' => 0, 'checked_pages' => 0, 'pending_pages' => 0,
            'error_pages' => 0, 'warning_pages' => 0, 'ok_pages' => 0,
            'active_images' => 0, 'broken_images' => 0, 'unique_broken_images' => 0,
        );
        if (!seo_images_scan_table_exists($tables['sitemap'])) {
            return $summary;
        }
        $summary['total_pages'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['sitemap']} WHERE active_in_sitemap=1");
        if (!seo_images_scan_table_exists($tables['pages'])) {
            $summary['pending_pages'] = $summary['total_pages'];
            return $summary;
        }
        $row = $wpdb->get_row(
            "SELECT COUNT(CASE WHEN p.last_checked_at IS NOT NULL THEN 1 END) AS checked_pages,
                    COALESCE(SUM(CASE WHEN p.status_bucket='ok' THEN 1 ELSE 0 END),0) AS ok_pages,
                    COALESCE(SUM(CASE WHEN p.status_bucket='warning' THEN 1 ELSE 0 END),0) AS warning_pages,
                    COALESCE(SUM(CASE WHEN p.status_bucket IN ('error','page_error','partial') THEN 1 ELSE 0 END),0) AS error_pages
             FROM {$tables['sitemap']} s
             LEFT JOIN {$tables['pages']} p ON p.sitemap_inventory_id=s.id
             WHERE s.active_in_sitemap=1",
            ARRAY_A
        );
        if ($row) {
            $summary['checked_pages'] = absint($row['checked_pages']);
            $summary['ok_pages'] = absint($row['ok_pages']);
            $summary['warning_pages'] = absint($row['warning_pages']);
            $summary['error_pages'] = absint($row['error_pages']);
        }
        $summary['pending_pages'] = max(0, $summary['total_pages'] - $summary['checked_pages']);
        if (seo_images_scan_table_exists($tables['items'])) {
            $images = $wpdb->get_row(
                "SELECT COUNT(*) AS active_images,
                        COALESCE(SUM(CASE WHEN i.status_bucket NOT IN ('ok','redirect') THEN 1 ELSE 0 END),0) AS broken_images,
                        COUNT(DISTINCT CASE WHEN i.status_bucket NOT IN ('ok','redirect') THEN i.image_url_hash END) AS unique_broken_images
                 FROM {$tables['items']} i
                 INNER JOIN {$tables['pages']} p ON p.id=i.page_id
                 INNER JOIN {$tables['sitemap']} s ON s.id=p.sitemap_inventory_id AND s.active_in_sitemap=1
                 WHERE i.active_on_page=1",
                ARRAY_A
            );
            if ($images) {
                $summary['active_images'] = absint($images['active_images']);
                $summary['broken_images'] = absint($images['broken_images']);
                $summary['unique_broken_images'] = absint($images['unique_broken_images']);
            }
        }
        return $summary;
    }
}

if (!function_exists('seo_images_scan_status_label')) {
    function seo_images_scan_status_label($status) {
        $labels = array(
            'ok' => 'OK', 'warning' => 'Avisos', 'error' => 'Errores', 'page_error' => 'Página no accesible',
            'partial' => 'Parcial', 'redirect' => 'Redirección', '404' => '404/410', '403' => '403', '429' => '429',
            '4xx' => 'Otros 4xx', '5xx' => '5xx', 'timeout' => 'Timeout', 'network' => 'Red', 'ssl' => 'SSL',
            'not_image' => 'No es imagen', 'other' => 'Otro', 'pending' => 'Pendiente',
        );
        return $labels[$status] ?? ucfirst((string) $status);
    }
}

if (!function_exists('seo_images_scan_render_tab')) {
    function seo_images_scan_render_tab() {
        global $wpdb;
        if ((string) get_option('seo_images_scan_schema_version', '') !== SEO_IMAGES_SCAN_SCHEMA_VERSION) {
            seo_images_scan_install_schema();
        }
        $tables = seo_images_scan_tables();
        $summary = seo_images_scan_summary();
        $active = seo_images_scan_active_run();
        $process = seo_images_scan_process_state();
        $process_enabled = !empty($process['enabled']);
        $latest = $wpdb->get_row("SELECT * FROM {$tables['runs']} ORDER BY id DESC LIMIT 1", ARRAY_A);
        $config = seo_images_scan_runner_config();
        $missing = seo_images_scan_config_errors($config);
        $coverage = $summary['total_pages'] > 0 ? round(100 * $summary['checked_pages'] / $summary['total_pages'], 1) : 0;

        echo '<style>
        .seo-imgscan-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin:16px 0}.seo-imgscan-kpi{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px}.seo-imgscan-kpi strong{display:block;font-size:25px;line-height:1.1}.seo-imgscan-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px;margin:14px 0}.seo-imgscan-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.seo-imgscan-stop{border-color:#d63638!important;color:#b32d2e!important}.seo-imgscan-stop:disabled{border-color:#c3c4c7!important;color:#a7aaad!important}.seo-imgscan-badge{display:inline-block;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:700}.seo-imgscan-badge.ok{background:#edfaef;color:#006b1b}.seo-imgscan-badge.warning{background:#fff8e5;color:#7a4d00}.seo-imgscan-badge.error,.seo-imgscan-badge.page_error,.seo-imgscan-badge.partial{background:#fcf0f1;color:#b32d2e}.seo-imgscan-badge.pending{background:#f0f0f1;color:#50575e}.seo-imgscan-progress{height:12px;background:#f0f0f1;border-radius:6px;overflow:hidden}.seo-imgscan-progress span{display:block;height:100%;background:#2271b1}.seo-imgscan-filters{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0}.seo-imgscan-url{word-break:break-all}.seo-imgscan-error-row{background:#fff7f7}.seo-imgscan-warning-row{background:#fffdf5}@media(max-width:782px){.seo-imgscan-table th:nth-child(3),.seo-imgscan-table td:nth-child(3),.seo-imgscan-table th:nth-child(4),.seo-imgscan-table td:nth-child(4){display:none}}
        </style>';

        echo '<section class="seo-imgscan-card"><h2 style="margin-top:0">Auditoría externa de carga de imágenes</h2>';
        echo '<p>El proceso recorre automáticamente todas las páginas pendientes. Al terminar un lote, WordPress recibe sus métricas y lanza el siguiente sin intervención manual. El regulador aumenta o reduce páginas e imágenes según duración, latencia p95 y presión HTTP.</p>';
        echo '<div class="seo-imgscan-actions">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_images_scan_start">';
        wp_nonce_field('seo_images_scan_start');
        echo '<button class="button button-primary"' . ($active || $process_enabled || !empty($missing) || $summary['total_pages'] < 1 ? ' disabled' : '') . '>Iniciar escaneo automático</button></form>';
        if ($active || $process_enabled) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_images_scan_stop"><input type="hidden" name="run_id" value="' . esc_attr((string) ($active['id'] ?? 0)) . '">';
            wp_nonce_field('seo_images_scan_stop');
            echo '<button class="button seo-imgscan-stop">Parar proceso</button></form>';
            $state_label = $active ? 'lote en ejecución' : 'esperando el siguiente lote';
            echo '<span class="description"><strong>Estado:</strong> ' . esc_html($state_label) . '.</span>';
        } else {
            echo '<button type="button" class="button seo-imgscan-stop" disabled>Parar proceso</button>';
            $state_label = ('complete' === ($process['status'] ?? '')) ? 'cobertura completada' : 'detenido';
            echo '<span class="description"><strong>Estado:</strong> ' . esc_html($state_label) . '.</span>';
        }
        echo '</div>';
        echo '<p><strong>Próximo objetivo adaptativo:</strong> ' . esc_html(number_format_i18n(absint($process['current_page_limit'] ?? SEO_IMAGES_SCAN_INITIAL_PAGE_LIMIT))) . ' páginas · ' . esc_html(number_format_i18n(absint($process['current_image_limit'] ?? SEO_IMAGES_SCAN_INITIAL_IMAGE_LIMIT))) . ' imágenes';
        if (!empty($process['next_delay'])) {
            echo ' · pausa ' . esc_html(number_format_i18n(absint($process['next_delay']))) . ' s';
        }
        echo '. <strong>Presión:</strong> ' . esc_html((string) ($process['adaptive_pressure'] ?? 'baja')) . '. ' . esc_html((string) ($process['adaptive_reason'] ?? '')) . '</p>';
        if (!empty($process['last_duration_ms'])) {
            echo '<p class="description">Último lote medido: ' . esc_html(number_format_i18n(absint($process['last_processed_pages'] ?? 0))) . ' páginas · ' . esc_html(number_format_i18n(absint($process['last_checked_images'] ?? 0))) . ' imágenes · ' . esc_html(number_format_i18n(round(absint($process['last_duration_ms']) / 1000, 1), 1)) . ' s · respuesta media ' . esc_html(number_format_i18n(absint($process['last_avg_request_ms'] ?? 0))) . ' ms · p95 ' . esc_html(number_format_i18n(absint($process['last_p95_request_ms'] ?? 0))) . ' ms.</p>';
        }
        if (!empty($missing)) {
            echo '<p style="color:#b32d2e"><strong>GitHub incompleto:</strong> ' . esc_html(implode(', ', $missing)) . '.</p>';
        } else {
            echo '<p class="description">Runner: <code>' . esc_html($config['owner'] . '/' . $config['repo']) . '</code> · workflow <code>.github/workflows/image-scan.yml</code> · ref <code>' . esc_html($config['ref']) . '</code>.</p>';
        }
        echo '</section>';
        if ($active || $process_enabled) {
            echo '<script>window.setTimeout(function(){ if (!document.hidden) { window.location.reload(); } }, 15000);</script>';
        }

        echo '<div class="seo-imgscan-grid">';
        $cards = array(
            array($summary['total_pages'], 'Páginas activas'),
            array($summary['checked_pages'], 'Páginas comprobadas'),
            array($summary['pending_pages'], 'Pendientes'),
            array($summary['error_pages'], 'Páginas con errores'),
            array($summary['active_images'], 'Usos de imagen activos'),
            array($summary['broken_images'], 'Usos rotos'),
            array($summary['unique_broken_images'], 'Imágenes rotas únicas'),
        );
        foreach ($cards as $card) {
            echo '<div class="seo-imgscan-kpi"><strong>' . esc_html(number_format_i18n($card[0])) . '</strong><span>' . esc_html($card[1]) . '</span></div>';
        }
        echo '</div>';
        echo '<section class="seo-imgscan-card"><h2 style="margin-top:0">Cobertura: ' . esc_html(number_format_i18n($coverage, 1)) . '%</h2><div class="seo-imgscan-progress"><span style="width:' . esc_attr((string) min(100, $coverage)) . '%"></span></div>';
        echo '<p>' . esc_html(number_format_i18n($summary['checked_pages'])) . ' / ' . esc_html(number_format_i18n($summary['total_pages'])) . ' páginas han pasado al menos una auditoría de imágenes.</p></section>';

        if ($latest) {
            $status_map = array('queued'=>'En cola','running'=>'En curso','completed'=>'Completado','failed'=>'Fallido','cancelled'=>'Cancelado');
            echo '<section class="seo-imgscan-card"><h2 style="margin-top:0">Último lote</h2><p><strong>' . esc_html($status_map[$latest['status']] ?? $latest['status']) . '</strong> · Modo: ' . esc_html($latest['mode'] === 'coverage' ? 'Cobertura' : 'Mantenimiento') . ' · Páginas ' . esc_html(number_format_i18n($latest['processed_pages'])) . '/' . esc_html(number_format_i18n($latest['total_pages'])) . ' · Imágenes ' . esc_html(number_format_i18n($latest['total_images'])) . '/' . esc_html(number_format_i18n(absint($latest['target_images'] ?? 0))) . ' objetivo · Rotas ' . esc_html(number_format_i18n($latest['error_images'])) . ' · Avisos ' . esc_html(number_format_i18n($latest['warning_images'])) . '.</p>';
            if (!empty($latest['duration_ms'])) {
                echo '<p class="description">Duración ' . esc_html(number_format_i18n(round(absint($latest['duration_ms']) / 1000, 1), 1)) . ' s · media ' . esc_html(number_format_i18n(absint($latest['avg_request_ms'] ?? 0))) . ' ms · p95 ' . esc_html(number_format_i18n(absint($latest['p95_request_ms'] ?? 0))) . ' ms · eventos de presión ' . esc_html(number_format_i18n(absint($latest['pressure_events'] ?? 0))) . '.</p>';
            }
            if (!empty($latest['error_message'])) {
                echo '<p style="color:#b32d2e"><code>' . esc_html($latest['error_message']) . '</code></p>';
            }
            echo '</section>';
        }

        $filter = isset($_GET['scan_filter']) ? sanitize_key(wp_unslash($_GET['scan_filter'])) : 'all';
        $search = isset($_GET['scan_s']) ? sanitize_text_field(wp_unslash($_GET['scan_s'])) : '';
        $page_no = isset($_GET['scan_paged']) ? max(1, absint($_GET['scan_paged'])) : 1;
        $per_page = 50;
        $where = array('s.active_in_sitemap=1');
        $params = array();
        if ($filter === 'pending') {
            $where[] = 'p.last_checked_at IS NULL';
        } elseif ($filter === 'ok') {
            $where[] = "p.status_bucket='ok'";
        } elseif ($filter === 'warning') {
            $where[] = "p.status_bucket='warning'";
        } elseif ($filter === 'error') {
            $where[] = "p.status_bucket IN ('error','page_error','partial')";
        }
        if ($search !== '') {
            $where[] = 's.url LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }
        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$tables['sitemap']} s LEFT JOIN {$tables['pages']} p ON p.sitemap_inventory_id=s.id WHERE {$where_sql}";
        $total_rows = $params ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);
        $offset = ($page_no - 1) * $per_page;
        $rows_sql = "SELECT s.url, s.url_hash, p.* FROM {$tables['sitemap']} s LEFT JOIN {$tables['pages']} p ON p.sitemap_inventory_id=s.id WHERE {$where_sql} ORDER BY CASE WHEN p.status_bucket IN ('error','page_error','partial') THEN 0 WHEN p.status_bucket='warning' THEN 1 WHEN p.last_checked_at IS NULL THEN 2 ELSE 3 END, p.last_checked_at ASC, s.id ASC LIMIT %d OFFSET %d";
        $row_params = array_merge($params, array($per_page, $offset));
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, $row_params), ARRAY_A);

        echo '<section class="seo-imgscan-card"><h2 style="margin-top:0">Estado por página</h2>';
        echo '<div class="seo-imgscan-filters">';
        foreach (array('all'=>'Todas','error'=>'Con errores','warning'=>'Avisos','ok'=>'Correctas','pending'=>'Pendientes') as $key=>$label) {
            $url = seo_images_scan_admin_url(array('scan_filter'=>$key));
            echo '<a class="button' . ($filter === $key ? ' button-primary' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</div><form method="get" style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0"><input type="hidden" name="page" value="seo-pictures-admin"><input type="hidden" name="tab" value="scan"><input type="hidden" name="scan_filter" value="' . esc_attr($filter) . '"><input type="search" name="scan_s" value="' . esc_attr($search) . '" placeholder="Buscar URL" class="regular-text"><button class="button">Buscar</button></form>';
        echo '<table class="widefat striped seo-imgscan-table"><thead><tr><th>Página</th><th>Estado</th><th>Imágenes</th><th>OK / Avisos / Error</th><th>HTTP</th><th>Último chequeo</th><th></th></tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="7">No hay resultados para este filtro.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $status = !empty($row['last_checked_at']) ? (string) ($row['status_bucket'] ?: 'other') : 'pending';
                $row_class = in_array($status, array('error','page_error','partial'), true) ? 'seo-imgscan-error-row' : ($status === 'warning' ? 'seo-imgscan-warning-row' : '');
                echo '<tr class="' . esc_attr($row_class) . '"><td class="seo-imgscan-url"><a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener">' . esc_html($row['url']) . '</a></td>';
                echo '<td><span class="seo-imgscan-badge ' . esc_attr($status) . '">' . esc_html(seo_images_scan_status_label($status)) . '</span></td>';
                echo '<td>' . esc_html(number_format_i18n(absint($row['total_images'] ?? 0))) . '</td>';
                echo '<td>' . esc_html(number_format_i18n(absint($row['ok_images'] ?? 0))) . ' / ' . esc_html(number_format_i18n(absint($row['warning_images'] ?? 0))) . ' / ' . esc_html(number_format_i18n(absint($row['error_images'] ?? 0))) . '</td>';
                echo '<td>' . (!empty($row['page_http_status']) ? esc_html((string) absint($row['page_http_status'])) : '—') . '</td><td>' . (!empty($row['last_checked_at']) ? esc_html($row['last_checked_at']) : 'Nunca') . '</td>';
                echo '<td>' . (!empty($row['id']) ? '<a class="button button-small" href="' . esc_url(seo_images_scan_admin_url(array('image_page'=>absint($row['id']),'scan_filter'=>$filter,'scan_s'=>$search))) . '">Detalle</a>' : '') . '</td></tr>';
            }
        }
        echo '</tbody></table>';
        $pages_count = max(1, (int) ceil($total_rows / $per_page));
        if ($pages_count > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links(array(
                'base' => add_query_arg(array('tab'=>'scan','scan_filter'=>$filter,'scan_s'=>$search,'scan_paged'=>'%#%'), seo_images_admin_url()),
                'format' => '', 'current' => $page_no, 'total' => $pages_count, 'prev_text'=>'‹', 'next_text'=>'›'
            ))) . '</div></div>';
        }
        echo '</section>';

        $issue_filter = isset($_GET['issue_filter']) ? sanitize_key(wp_unslash($_GET['issue_filter'])) : 'broken';
        $issue_search = isset($_GET['issue_s']) ? sanitize_text_field(wp_unslash($_GET['issue_s'])) : '';
        $issue_page = isset($_GET['issue_paged']) ? max(1, absint($_GET['issue_paged'])) : 1;
        $issue_per_page = 100;
        $issue_where = array('i.active_on_page=1', 's.active_in_sitemap=1');
        if ($issue_filter === 'broken') {
            $issue_where[] = "i.status_bucket NOT IN ('ok','redirect')";
        } elseif ($issue_filter === 'all') {
            $issue_where[] = "i.status_bucket<>'ok'";
        } elseif (in_array($issue_filter, array('404','403','429','4xx','5xx','timeout','network','ssl','not_image','redirect','other'), true)) {
            $issue_where[] = $wpdb->prepare('i.status_bucket=%s', $issue_filter);
        } else {
            $issue_filter = 'broken';
            $issue_where[] = "i.status_bucket NOT IN ('ok','redirect')";
        }
        if ($issue_search !== '') {
            $like = '%' . $wpdb->esc_like($issue_search) . '%';
            $issue_where[] = $wpdb->prepare('(i.image_url LIKE %s OR p.page_url LIKE %s)', $like, $like);
        }
        $issue_where_sql = implode(' AND ', $issue_where);
        $issue_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['items']} i INNER JOIN {$tables['pages']} p ON p.id=i.page_id INNER JOIN {$tables['sitemap']} s ON s.id=p.sitemap_inventory_id WHERE {$issue_where_sql}");
        $issue_offset = ($issue_page - 1) * $issue_per_page;
        $issue_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT i.*, p.page_url FROM {$tables['items']} i INNER JOIN {$tables['pages']} p ON p.id=i.page_id INNER JOIN {$tables['sitemap']} s ON s.id=p.sitemap_inventory_id WHERE {$issue_where_sql} ORDER BY i.last_checked_at DESC, i.id DESC LIMIT %d OFFSET %d",
                $issue_per_page,
                $issue_offset
            ),
            ARRAY_A
        );
        echo '<section class="seo-imgscan-card"><h2 style="margin-top:0">Incidencias de imagen</h2><p>Vista transversal de imágenes problemáticas. La tabla anterior sigue siendo la vista principal agrupada por página.</p><div class="seo-imgscan-filters">';
        foreach (array('broken'=>'Rotas','404'=>'404/410','403'=>'403','429'=>'429','4xx'=>'Otros 4xx','5xx'=>'5xx','timeout'=>'Timeout','network'=>'Red','ssl'=>'SSL','not_image'=>'No es imagen','redirect'=>'Redirecciones','all'=>'Todas incidencias') as $key=>$label) {
            echo '<a class="button' . ($issue_filter === $key ? ' button-primary' : '') . '" href="' . esc_url(seo_images_scan_admin_url(array('issue_filter'=>$key))) . '">' . esc_html($label) . '</a>';
        }
        echo '</div><form method="get" style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0"><input type="hidden" name="page" value="seo-pictures-admin"><input type="hidden" name="tab" value="scan"><input type="hidden" name="issue_filter" value="' . esc_attr($issue_filter) . '"><input type="search" name="issue_s" value="' . esc_attr($issue_search) . '" placeholder="Buscar página o imagen" class="regular-text"><button class="button">Buscar</button></form>';
        echo '<p><strong>' . esc_html(number_format_i18n($issue_total)) . '</strong> relaciones encontradas.</p><table class="widefat striped"><thead><tr><th>Imagen</th><th>Página</th><th>Estado</th><th>HTTP</th><th>ms</th></tr></thead><tbody>';
        if (empty($issue_rows)) {
            echo '<tr><td colspan="5">No hay incidencias para este filtro.</td></tr>';
        } else {
            foreach ($issue_rows as $item) {
                echo '<tr><td class="seo-imgscan-url"><a href="' . esc_url($item['image_url']) . '" target="_blank" rel="noopener">' . esc_html($item['image_url']) . '</a>';
                if (!empty($item['error_message'])) echo '<br><small style="color:#b32d2e">' . esc_html($item['error_message']) . '</small>';
                echo '</td><td class="seo-imgscan-url"><a href="' . esc_url($item['page_url']) . '" target="_blank" rel="noopener">' . esc_html($item['page_url']) . '</a></td>';
                echo '<td><span class="seo-imgscan-badge ' . esc_attr($item['status_bucket'] === 'redirect' ? 'warning' : 'error') . '">' . esc_html(seo_images_scan_status_label($item['status_bucket'])) . '</span></td>';
                echo '<td>' . esc_html((string) absint($item['http_status'])) . ($item['redirect_count'] ? ' → ' . esc_html((string) absint($item['final_status'])) : '') . '</td><td>' . esc_html(number_format_i18n(absint($item['response_ms']))) . '</td></tr>';
            }
        }
        echo '</tbody></table>';
        $issue_pages = max(1, (int) ceil($issue_total / $issue_per_page));
        if ($issue_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links(array(
                'base' => add_query_arg(array('tab'=>'scan','issue_filter'=>$issue_filter,'issue_s'=>$issue_search,'issue_paged'=>'%#%'), seo_images_admin_url()),
                'format'=>'','current'=>$issue_page,'total'=>$issue_pages,'prev_text'=>'‹','next_text'=>'›'
            ))) . '</div></div>';
        }
        echo '</section>';

        $detail_id = isset($_GET['image_page']) ? absint($_GET['image_page']) : 0;
        if ($detail_id > 0) {
            $detail = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tables['pages']} WHERE id=%d", $detail_id), ARRAY_A);
            if ($detail) {
                $items = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tables['items']} WHERE page_id=%d AND active_on_page=1 ORDER BY CASE WHEN status_bucket NOT IN ('ok','redirect') THEN 0 WHEN status_bucket='redirect' THEN 1 ELSE 2 END, id ASC LIMIT 500", $detail_id), ARRAY_A);
                echo '<section class="seo-imgscan-card"><h2 style="margin-top:0">Detalle de imágenes</h2><p class="seo-imgscan-url"><strong>Página:</strong> <a href="' . esc_url($detail['page_url']) . '" target="_blank" rel="noopener">' . esc_html($detail['page_url']) . '</a></p>';
                if (!empty($detail['error_message'])) {
                    echo '<p style="color:#b32d2e">' . esc_html($detail['error_message']) . '</p>';
                }
                echo '<table class="widefat striped"><thead><tr><th>Imagen</th><th>Estado</th><th>HTTP</th><th>Tipo</th><th>ms</th><th>Origen</th></tr></thead><tbody>';
                if (empty($items)) {
                    echo '<tr><td colspan="6">No se detectaron imágenes activas en esta página o la página no pudo cargarse.</td></tr>';
                } else {
                    foreach ($items as $item) {
                        echo '<tr><td class="seo-imgscan-url"><a href="' . esc_url($item['image_url']) . '" target="_blank" rel="noopener">' . esc_html($item['image_url']) . '</a>';
                        if (!empty($item['error_message'])) echo '<br><small style="color:#b32d2e">' . esc_html($item['error_message']) . '</small>';
                        echo '</td><td><span class="seo-imgscan-badge ' . esc_attr($item['status_bucket'] === 'redirect' ? 'warning' : ($item['status_bucket'] === 'ok' ? 'ok' : 'error')) . '">' . esc_html(seo_images_scan_status_label($item['status_bucket'])) . '</span></td>';
                        echo '<td>' . esc_html((string) absint($item['http_status'])) . ($item['redirect_count'] ? ' → ' . esc_html((string) absint($item['final_status'])) : '') . '</td><td>' . esc_html($item['content_type'] ?: '—') . '</td><td>' . esc_html(number_format_i18n(absint($item['response_ms']))) . '</td><td><code>' . esc_html($item['source_tag'] . ':' . $item['source_attr']) . '</code></td></tr>';
                    }
                }
                echo '</tbody></table></section>';
            }
        }

        echo '<details class="seo-imgscan-card"><summary style="cursor:pointer;font-weight:600">Archivos necesarios en GitHub</summary><div style="padding-top:10px"><p>En el mismo repositorio del Python Runner deben existir exactamente:</p><ol><li><code>.github/workflows/image-scan.yml</code></li><li><code>scripts/image_scan.py</code></li></ol><p><strong>Importante:</strong> <code>image-scan.yml</code> debe estar en la rama predeterminada del repositorio. El nombre no puede ser <code>image-scan-v1.yml</code>, <code>image_scan.yml</code> ni otro distinto.</p><p>Este escáner es independiente de <code>sitemap-scan.yml</code> y <code>sitemap_scan.py</code>.</p></div></details>';
    }
}
