<?php
/**
 * SEO System - auditor externo por tipo de recurso.
 *
 * Separa tres inventarios: paginas WordPress, posts e imagenes activas.
 * Cada pestaña dispone de su propio escaneo y de un test de carga seguro.
 * Los workers se ejecutan en GitHub Actions y devuelven resultados por REST.
 *
 * Version: 2026-09-03
 * Build: 001
 */

defined('ABSPATH') || exit;

if (!defined('SEO_HEALTH_SCAN_SCHEMA_VERSION')) {
    define('SEO_HEALTH_SCAN_SCHEMA_VERSION', '1');
}
if (!defined('SEO_HEALTH_PAGE_BATCH')) {
    define('SEO_HEALTH_PAGE_BATCH', 250);
}
if (!defined('SEO_HEALTH_POST_BATCH')) {
    define('SEO_HEALTH_POST_BATCH', 250);
}
if (!defined('SEO_HEALTH_IMAGE_BATCH')) {
    define('SEO_HEALTH_IMAGE_BATCH', 500);
}

if (!function_exists('seo_health_scan_tables')) {
    function seo_health_scan_tables() {
        global $wpdb;
        return array(
            'runs'  => $wpdb->prefix . 'seo_health_runs',
            'items' => $wpdb->prefix . 'seo_health_items',
        );
    }
}

if (!function_exists('seo_health_scan_table_exists')) {
    function seo_health_scan_table_exists($table) {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like((string) $table))
        ) === (string) $table;
    }
}

if (!function_exists('seo_health_scan_install_schema')) {
    function seo_health_scan_install_schema() {
        global $wpdb;
        $tables = seo_health_scan_tables();
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_runs = "CREATE TABLE {$tables['runs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scan_uuid char(36) NOT NULL,
            scope varchar(16) NOT NULL,
            mode varchar(20) NOT NULL DEFAULT 'coverage',
            status varchar(20) NOT NULL DEFAULT 'queued',
            total_items int(10) unsigned NOT NULL DEFAULT 0,
            processed_items int(10) unsigned NOT NULL DEFAULT 0,
            ok_items int(10) unsigned NOT NULL DEFAULT 0,
            warning_items int(10) unsigned NOT NULL DEFAULT 0,
            error_items int(10) unsigned NOT NULL DEFAULT 0,
            status_429 int(10) unsigned NOT NULL DEFAULT 0,
            status_5xx int(10) unsigned NOT NULL DEFAULT 0,
            duration_ms int(10) unsigned NOT NULL DEFAULT 0,
            avg_response_ms int(10) unsigned NOT NULL DEFAULT 0,
            p95_response_ms int(10) unsigned NOT NULL DEFAULT 0,
            pressure_events int(10) unsigned NOT NULL DEFAULT 0,
            callback_token_hash char(64) NOT NULL DEFAULT '',
            error_message text NULL,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY scan_uuid (scan_uuid),
            KEY scope_status (scope,status),
            KEY scope_created (scope,created_at)
        ) {$charset_collate};";

        $sql_items = "CREATE TABLE {$tables['items']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            scope varchar(16) NOT NULL,
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            url_hash char(32) NOT NULL,
            url text NOT NULL,
            label text NULL,
            source varchar(32) NOT NULL DEFAULT 'wordpress',
            active tinyint(1) unsigned NOT NULL DEFAULT 1,
            sync_token char(36) NOT NULL DEFAULT '',
            status_bucket varchar(20) NOT NULL DEFAULT 'pending',
            http_status smallint(5) unsigned NULL,
            final_status smallint(5) unsigned NULL,
            final_url text NULL,
            response_ms int(10) unsigned NOT NULL DEFAULT 0,
            ttfb_ms int(10) unsigned NOT NULL DEFAULT 0,
            content_type varchar(160) NOT NULL DEFAULT '',
            content_length bigint(20) unsigned NOT NULL DEFAULT 0,
            error_type varchar(64) NOT NULL DEFAULT '',
            error_message text NULL,
            warnings longtext NULL,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            last_checked_at datetime NULL,
            last_ok_at datetime NULL,
            last_error_at datetime NULL,
            consecutive_errors int(10) unsigned NOT NULL DEFAULT 0,
            checks_total int(10) unsigned NOT NULL DEFAULT 0,
            queued_scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
            queued_at datetime NULL,
            last_scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY scope_url (scope,url_hash),
            KEY scope_active_status (scope,active,status_bucket),
            KEY scope_checked (scope,active,last_checked_at),
            KEY queued_scan_id (queued_scan_id),
            KEY object_lookup (scope,object_id),
            KEY error_type (error_type)
        ) {$charset_collate};";

        dbDelta($sql_runs);
        dbDelta($sql_items);
        update_option('seo_health_scan_schema_version', SEO_HEALTH_SCAN_SCHEMA_VERSION, false);
    }
}

if (!function_exists('seo_health_scan_maybe_upgrade')) {
    function seo_health_scan_maybe_upgrade() {
        if ((string) get_option('seo_health_scan_schema_version', '') !== SEO_HEALTH_SCAN_SCHEMA_VERSION) {
            seo_health_scan_install_schema();
        }
    }
}
add_action('admin_init', 'seo_health_scan_maybe_upgrade', 18);

if (!function_exists('seo_health_scan_scope_config')) {
    function seo_health_scan_scope_config($scope) {
        $scope = sanitize_key((string) $scope);
        $map = array(
            'page' => array(
                'label' => 'Páginas',
                'singular' => 'página',
                'workflow' => 'page-health.yml',
                'batch' => SEO_HEALTH_PAGE_BATCH,
                'load_batch' => 60,
                'admin' => array('page' => 'seo-page-admin', 'tab' => 'errores'),
            ),
            'post' => array(
                'label' => 'Posts',
                'singular' => 'post',
                'workflow' => 'post-health.yml',
                'batch' => SEO_HEALTH_POST_BATCH,
                'load_batch' => 60,
                'admin' => array('page' => 'seo-post-editor', 'tab' => 'errors'),
            ),
            'image' => array(
                'label' => 'Imágenes',
                'singular' => 'imagen',
                'workflow' => 'image-health.yml',
                'batch' => SEO_HEALTH_IMAGE_BATCH,
                'load_batch' => 300,
                'admin' => array('page' => 'seo-pictures-admin', 'tab' => 'errors'),
            ),
        );
        $config = isset($map[$scope]) ? $map[$scope] : null;
        if (is_array($config)) {
            $config = apply_filters('seo_health_scan_scope_config', $config, $scope);
        }
        return $config;
    }
}

if (!function_exists('seo_health_scan_admin_url')) {
    function seo_health_scan_admin_url($scope, $extra = array()) {
        $config = seo_health_scan_scope_config($scope);
        if (!$config) {
            return admin_url('admin.php');
        }
        return add_query_arg(array_merge($config['admin'], (array) $extra), admin_url('admin.php'));
    }
}

if (!function_exists('seo_health_scan_runner_config')) {
    function seo_health_scan_runner_config($scope) {
        $scope_config = seo_health_scan_scope_config($scope);
        if (!$scope_config) {
            return array();
        }
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
            'available' => function_exists('seo_github_python_runner_settings') && function_exists('seo_github_python_runner_api_request'),
            'enabled' => !empty($settings['enabled']),
            'owner' => trim((string) ($settings['owner'] ?? '')),
            'repo' => trim((string) ($settings['repo'] ?? '')),
            'ref' => trim((string) ($settings['ref'] ?? 'main')) ?: 'main',
            'token' => trim((string) ($settings['token'] ?? '')),
            'workflow' => $scope_config['workflow'],
            'batch_endpoint' => rest_url('seo-system/v1/health-scan/batch'),
            'callback_url' => rest_url('seo-system/v1/health-scan/results'),
        );
    }
}

if (!function_exists('seo_health_scan_config_errors')) {
    function seo_health_scan_config_errors($config) {
        $errors = array();
        if (empty($config['available'])) {
            return array('github_runner');
        }
        if (empty($config['enabled'])) {
            $errors[] = 'conexion_desactivada';
        }
        foreach (array('owner','repo','ref','token') as $key) {
            if (empty($config[$key])) {
                $errors[] = $key;
            }
        }
        return $errors;
    }
}

if (!function_exists('seo_health_scan_upsert_rows')) {
    function seo_health_scan_upsert_rows($scope, array $rows, $sync_token) {
        global $wpdb;
        $table = seo_health_scan_tables()['items'];
        $now = current_time('mysql', true);
        $written = 0;

        foreach (array_chunk($rows, 250) as $chunk) {
            $values = array();
            $params = array();
            foreach ($chunk as $row) {
                $url = esc_url_raw((string) ($row['url'] ?? ''), array('http','https'));
                if ($url === '') {
                    continue;
                }
                $values[] = '(%s,%d,%s,%s,%s,%s,1,%s,%s,%s)';
                $params[] = $scope;
                $params[] = absint($row['object_id'] ?? 0);
                $params[] = md5($url);
                $params[] = $url;
                $params[] = sanitize_text_field((string) ($row['label'] ?? ''));
                $params[] = sanitize_key((string) ($row['source'] ?? 'wordpress'));
                $params[] = $sync_token;
                $params[] = $now;
                $params[] = $now;
            }
            if (empty($values)) {
                continue;
            }
            $sql = "INSERT INTO {$table}
                (scope,object_id,url_hash,url,label,source,active,sync_token,first_seen_at,last_seen_at)
                VALUES " . implode(',', $values) . "
                ON DUPLICATE KEY UPDATE
                    object_id=VALUES(object_id),
                    url=VALUES(url),
                    label=VALUES(label),
                    source=VALUES(source),
                    active=1,
                    sync_token=VALUES(sync_token),
                    last_seen_at=VALUES(last_seen_at)";
            $result = $wpdb->query($wpdb->prepare($sql, $params));
            if ($result === false) {
                return new WP_Error('seo_health_inventory_write', $wpdb->last_error ?: 'No se pudo actualizar el inventario.');
            }
            $written += count($values);
        }
        return $written;
    }
}

if (!function_exists('seo_health_scan_collect_posts')) {
    function seo_health_scan_collect_posts($post_type) {
        global $wpdb;
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type=%s AND post_status='publish' AND post_password=''
                 ORDER BY ID ASC",
                $post_type
            )
        );
        $rows = array();
        foreach ((array) $ids as $id) {
            $id = absint($id);
            $url = get_permalink($id);
            if (!$url) {
                continue;
            }
            $rows[] = array(
                'object_id' => $id,
                'url' => $url,
                'label' => get_the_title($id),
                'source' => 'wordpress',
            );
        }
        return $rows;
    }
}

if (!function_exists('seo_health_scan_collect_images')) {
    function seo_health_scan_collect_images() {
        global $wpdb;
        $rows_by_hash = array();
        $scan_items = $wpdb->prefix . 'seo_image_scan_items';
        $media_usages = $wpdb->prefix . 'seo_media_usos';

        // Reutiliza URLs realmente observadas en páginas por el escáner anterior.
        if (seo_health_scan_table_exists($scan_items)) {
            $observed = $wpdb->get_results(
                "SELECT image_url, MAX(page_id) AS page_id
                 FROM {$scan_items}
                 WHERE active_on_page=1 AND image_url<>''
                 GROUP BY image_url_hash, image_url",
                ARRAY_A
            );
            foreach ((array) $observed as $row) {
                $url = esc_url_raw((string) $row['image_url'], array('http','https'));
                if ($url === '') {
                    continue;
                }
                $rows_by_hash[md5($url)] = array(
                    'object_id' => 0,
                    'url' => $url,
                    'label' => wp_basename((string) wp_parse_url($url, PHP_URL_PATH)),
                    'source' => 'rendered',
                );
            }
        }

        // Añade media local actualmente referenciada por seo_media_usos sin abrir páginas HTML.
        if (seo_health_scan_table_exists($media_usages)) {
            $local = $wpdb->get_results(
                "SELECT DISTINCT u.attachment_id, p.post_title, p.guid,
                        MAX(CASE WHEN pm.meta_key='_wp_attached_file' THEN pm.meta_value ELSE '' END) AS attached_file
                 FROM {$media_usages} u
                 INNER JOIN {$wpdb->posts} p ON p.ID=u.attachment_id
                 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_wp_attached_file'
                 WHERE p.post_type='attachment' AND p.post_mime_type LIKE 'image/%'
                 GROUP BY u.attachment_id,p.post_title,p.guid",
                ARRAY_A
            );
            $upload = wp_upload_dir(null, false);
            $baseurl = !empty($upload['baseurl']) ? trailingslashit($upload['baseurl']) : '';
            foreach ((array) $local as $row) {
                $file = ltrim((string) ($row['attached_file'] ?? ''), '/');
                $url = $file !== '' && $baseurl !== '' ? $baseurl . $file : (string) ($row['guid'] ?? '');
                $url = esc_url_raw($url, array('http','https'));
                if ($url === '') {
                    continue;
                }
                $rows_by_hash[md5($url)] = array(
                    'object_id' => absint($row['attachment_id']),
                    'url' => $url,
                    'label' => (string) ($row['post_title'] ?: wp_basename($file)),
                    'source' => 'media_usage',
                );
            }
        }

        return array_values($rows_by_hash);
    }
}

if (!function_exists('seo_health_scan_sync_inventory')) {
    function seo_health_scan_sync_inventory($scope) {
        $scope = sanitize_key((string) $scope);
        if (!seo_health_scan_scope_config($scope)) {
            return new WP_Error('seo_health_scope', 'Ámbito de escaneo no válido.');
        }
        seo_health_scan_maybe_upgrade();
        if ($scope === 'page') {
            $rows = seo_health_scan_collect_posts('page');
        } elseif ($scope === 'post') {
            $rows = seo_health_scan_collect_posts('post');
        } else {
            $rows = seo_health_scan_collect_images();
        }
        if (empty($rows)) {
            return new WP_Error('seo_health_inventory_empty', 'No se encontraron elementos activos para este ámbito.');
        }

        $token = wp_generate_uuid4();
        $written = seo_health_scan_upsert_rows($scope, $rows, $token);
        if (is_wp_error($written)) {
            return $written;
        }
        global $wpdb;
        $table = seo_health_scan_tables()['items'];
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET active=0, queued_scan_id=0, queued_at=NULL
                 WHERE scope=%s AND active=1 AND sync_token<>%s",
                $scope,
                $token
            )
        );
        return array('count' => count($rows), 'written' => $written);
    }
}

if (!function_exists('seo_health_scan_summary')) {
    function seo_health_scan_summary($scope) {
        global $wpdb;
        $table = seo_health_scan_tables()['items'];
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(active=1) total,
                    SUM(active=1 AND last_checked_at IS NOT NULL) checked,
                    SUM(active=1 AND last_checked_at IS NULL) pending,
                    SUM(active=1 AND status_bucket='ok') ok_count,
                    SUM(active=1 AND status_bucket='warning') warning_count,
                    SUM(active=1 AND status_bucket='error') error_count
                 FROM {$table} WHERE scope=%s",
                $scope
            ),
            ARRAY_A
        );
        $defaults = array('total'=>0,'checked'=>0,'pending'=>0,'ok_count'=>0,'warning_count'=>0,'error_count'=>0);
        $row = is_array($row) ? array_merge($defaults, $row) : $defaults;
        return array_map('intval', $row);
    }
}

if (!function_exists('seo_health_scan_expire_stale')) {
    function seo_health_scan_expire_stale() {
        global $wpdb;
        $tables = seo_health_scan_tables();
        $cutoff = gmdate('Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS);
        $now = current_time('mysql', true);
        $stale_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$tables['runs']} WHERE status IN ('queued','running') AND updated_at<%s",
                $cutoff
            )
        );
        if (!empty($stale_ids)) {
            $ids = implode(',', array_map('absint', $stale_ids));
            $wpdb->query("UPDATE {$tables['runs']} SET status='failed',error_message='Ejecución caducada sin callback final.',callback_token_hash='',completed_at='{$now}',updated_at='{$now}' WHERE id IN ({$ids})");
            $wpdb->query("UPDATE {$tables['items']} SET queued_scan_id=0,queued_at=NULL WHERE queued_scan_id IN ({$ids})");
        }
    }
}

if (!function_exists('seo_health_scan_active_run')) {
    function seo_health_scan_active_run($scope) {
        seo_health_scan_expire_stale();
        global $wpdb;
        $table = seo_health_scan_tables()['runs'];
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE scope=%s AND status IN ('queued','running') ORDER BY id DESC LIMIT 1",
                $scope
            ),
            ARRAY_A
        );
    }
}

if (!function_exists('seo_health_scan_latest_run')) {
    function seo_health_scan_latest_run($scope) {
        global $wpdb;
        $table = seo_health_scan_tables()['runs'];
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE scope=%s ORDER BY id DESC LIMIT 1", $scope),
            ARRAY_A
        );
    }
}

if (!function_exists('seo_health_scan_select_batch')) {
    function seo_health_scan_select_batch($scope, $mode, $limit) {
        global $wpdb;
        $table = seo_health_scan_tables()['items'];
        $limit = max(1, min(1000, absint($limit)));
        if ($mode === 'load_test') {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,object_id,url,label,source FROM {$table}
                     WHERE scope=%s AND active=1 AND queued_scan_id=0
                     ORDER BY RAND() LIMIT %d",
                    $scope,
                    $limit
                ),
                ARRAY_A
            );
        }

        $pending = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,object_id,url,label,source FROM {$table}
                 WHERE scope=%s AND active=1 AND queued_scan_id=0 AND last_checked_at IS NULL
                 ORDER BY id ASC LIMIT %d",
                $scope,
                $limit
            ),
            ARRAY_A
        );
        if (!empty($pending)) {
            return $pending;
        }

        $selected = array();
        $issues = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,object_id,url,label,source FROM {$table}
                 WHERE scope=%s AND active=1 AND queued_scan_id=0 AND status_bucket IN ('error','warning')
                 ORDER BY CASE status_bucket WHEN 'error' THEN 0 ELSE 1 END,last_checked_at ASC,id ASC LIMIT %d",
                $scope,
                $limit
            ),
            ARRAY_A
        );
        foreach ((array) $issues as $row) {
            $selected[(int) $row['id']] = $row;
        }
        $remaining = $limit - count($selected);
        if ($remaining > 0) {
            $oldest = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,object_id,url,label,source FROM {$table}
                     WHERE scope=%s AND active=1 AND queued_scan_id=0 AND status_bucket='ok'
                     ORDER BY last_checked_at ASC,id ASC LIMIT %d",
                    $scope,
                    $remaining
                ),
                ARRAY_A
            );
            foreach ((array) $oldest as $row) {
                $selected[(int) $row['id']] = $row;
            }
        }
        return array_values($selected);
    }
}

if (!function_exists('seo_health_scan_launch')) {
    function seo_health_scan_launch($scope, $mode = 'coverage') {
        global $wpdb;
        $scope_config = seo_health_scan_scope_config($scope);
        if (!$scope_config) {
            return new WP_Error('seo_health_scope', 'Ámbito no válido.');
        }
        if (seo_health_scan_active_run($scope)) {
            return new WP_Error('seo_health_busy', 'Ya hay un escaneo activo en esta pestaña.');
        }
        $runner = seo_health_scan_runner_config($scope);
        $missing = seo_health_scan_config_errors($runner);
        if (!empty($missing)) {
            return new WP_Error('seo_health_config', 'Conexión GitHub incompleta: ' . implode(', ', $missing));
        }
        $sync = seo_health_scan_sync_inventory($scope);
        if (is_wp_error($sync)) {
            return $sync;
        }

        $mode = $mode === 'load_test' ? 'load_test' : 'coverage';
        $limit = $mode === 'load_test' ? $scope_config['load_batch'] : $scope_config['batch'];
        $batch = seo_health_scan_select_batch($scope, $mode, $limit);
        if (empty($batch)) {
            return new WP_Error('seo_health_empty', 'No hay elementos disponibles para escanear.');
        }

        $tables = seo_health_scan_tables();
        $scan_uuid = wp_generate_uuid4();
        $callback_token = bin2hex(random_bytes(32));
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            $tables['runs'],
            array(
                'scan_uuid' => $scan_uuid,
                'scope' => $scope,
                'mode' => $mode,
                'status' => 'queued',
                'total_items' => count($batch),
                'callback_token_hash' => hash('sha256', $callback_token),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s','%s','%s','%s','%d','%s','%s','%s')
        );
        if ($inserted === false) {
            return new WP_Error('seo_health_db', $wpdb->last_error ?: 'No se pudo crear el escaneo.');
        }
        $run_id = (int) $wpdb->insert_id;
        $ids = array_map('absint', wp_list_pluck($batch, 'id'));
        if (!empty($ids)) {
            $wpdb->query(
                "UPDATE {$tables['items']} SET queued_scan_id={$run_id},queued_at='" . esc_sql($now) . "' WHERE id IN (" . implode(',', $ids) . ')'
            );
        }

        $batch_url = add_query_arg('scan_id', rawurlencode($scan_uuid), $runner['batch_endpoint']);
        $endpoint = sprintf(
            'https://api.github.com/repos/%1$s/%2$s/actions/workflows/%3$s/dispatches',
            rawurlencode($runner['owner']),
            rawurlencode($runner['repo']),
            rawurlencode($runner['workflow'])
        );
        $payload = array(
            'ref' => $runner['ref'],
            'inputs' => array(
                'scan_id' => $scan_uuid,
                'batch_url' => $batch_url,
                'callback_url' => $runner['callback_url'],
                'callback_token' => $callback_token,
            ),
        );
        $response = seo_github_python_runner_api_request('POST', $endpoint, $payload);
        if (is_wp_error($response)) {
            $wpdb->update(
                $tables['runs'],
                array('status'=>'failed','error_message'=>substr($response->get_error_message(),0,4000),'callback_token_hash'=>'','completed_at'=>$now,'updated_at'=>$now),
                array('id'=>$run_id),
                array('%s','%s','%s','%s','%s'),
                array('%d')
            );
            $wpdb->query("UPDATE {$tables['items']} SET queued_scan_id=0,queued_at=NULL WHERE queued_scan_id={$run_id}");
            return new WP_Error('seo_health_dispatch', $response->get_error_message());
        }
        return array('run_id'=>$run_id,'scan_uuid'=>$scan_uuid,'count'=>count($batch),'mode'=>$mode);
    }
}

if (!function_exists('seo_health_scan_handle_action')) {
    function seo_health_scan_handle_action() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        check_admin_referer('seo_health_scan_action');
        $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : '';
        $task = isset($_POST['task']) ? sanitize_key(wp_unslash($_POST['task'])) : '';
        if (!seo_health_scan_scope_config($scope)) {
            wp_die('Ámbito no válido.');
        }
        if ($task === 'cancel') {
            global $wpdb;
            $tables = seo_health_scan_tables();
            $active = seo_health_scan_active_run($scope);
            if ($active) {
                $now = current_time('mysql', true);
                $wpdb->update(
                    $tables['runs'],
                    array('status'=>'cancelled','callback_token_hash'=>'','error_message'=>'Cancelado manualmente.','completed_at'=>$now,'updated_at'=>$now),
                    array('id'=>absint($active['id'])),
                    array('%s','%s','%s','%s','%s'),
                    array('%d')
                );
                $wpdb->query("UPDATE {$tables['items']} SET queued_scan_id=0,queued_at=NULL WHERE queued_scan_id=" . absint($active['id']));
                $msg = 'synced';
                $detail = 'Escaneo cancelado y lote liberado.';
            } else {
                $msg = 'sync_error';
                $detail = 'No hay un escaneo activo que cancelar.';
            }
        } elseif ($task === 'sync') {
            $result = seo_health_scan_sync_inventory($scope);
            $msg = is_wp_error($result) ? 'sync_error' : 'synced';
            $detail = is_wp_error($result) ? $result->get_error_message() : ('Inventario actualizado: ' . absint($result['count']) . ' elementos.');
        } elseif ($task === 'load_test') {
            $result = seo_health_scan_launch($scope, 'load_test');
            $msg = is_wp_error($result) ? 'start_error' : 'started';
            $detail = is_wp_error($result) ? $result->get_error_message() : ('Test de carga lanzado: ' . absint($result['count']) . ' elementos.');
        } else {
            $result = seo_health_scan_launch($scope, 'coverage');
            $msg = is_wp_error($result) ? 'start_error' : 'started';
            $detail = is_wp_error($result) ? $result->get_error_message() : ('Escaneo lanzado: ' . absint($result['count']) . ' elementos.');
        }
        wp_safe_redirect(seo_health_scan_admin_url($scope, array('health_msg'=>$msg,'health_detail'=>rawurlencode($detail))));
        exit;
    }
}
add_action('admin_post_seo_health_scan_action', 'seo_health_scan_handle_action');

if (!function_exists('seo_health_scan_auth_run')) {
    function seo_health_scan_auth_run(WP_REST_Request $request) {
        $scan_uuid = sanitize_text_field((string) $request->get_param('scan_id'));
        if ($scan_uuid === '') {
            return new WP_Error('seo_health_scan_id', 'Falta scan_id.', array('status'=>400));
        }
        $authorization = trim((string) $request->get_header('authorization'));
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
            return new WP_Error('seo_health_auth', 'Falta autenticación.', array('status'=>401));
        }
        global $wpdb;
        $run = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . seo_health_scan_tables()['runs'] . " WHERE scan_uuid=%s LIMIT 1", $scan_uuid),
            ARRAY_A
        );
        if (!$run) {
            return new WP_Error('seo_health_unknown', 'Ejecución no encontrada.', array('status'=>404));
        }
        $stored = (string) ($run['callback_token_hash'] ?? '');
        $token = trim((string) $match[1]);
        if ($stored === '' || !hash_equals($stored, hash('sha256', $token))) {
            return new WP_Error('seo_health_token', 'Token no válido.', array('status'=>403));
        }
        return $run;
    }
}

if (!function_exists('seo_health_scan_rest_batch')) {
    function seo_health_scan_rest_batch(WP_REST_Request $request) {
        seo_health_scan_maybe_upgrade();
        $run = seo_health_scan_auth_run($request);
        if (is_wp_error($run)) {
            return $run;
        }
        global $wpdb;
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,object_id,url,label,source FROM " . seo_health_scan_tables()['items'] . " WHERE queued_scan_id=%d ORDER BY id ASC",
                absint($run['id'])
            ),
            ARRAY_A
        );
        $control = function_exists('seo_processes_health_runner_control')
            ? seo_processes_health_runner_control($run['scope'])
            : array();
        return rest_ensure_response(array(
            'scan_id' => $run['scan_uuid'],
            'scope' => $run['scope'],
            'mode' => $run['mode'],
            'count' => count((array) $items),
            'items' => (array) $items,
            'control' => $control,
        ));
    }
}

if (!function_exists('seo_health_scan_clean_result')) {
    function seo_health_scan_clean_result($raw) {
        $raw = is_array($raw) ? $raw : array();
        $url = esc_url_raw((string) ($raw['url'] ?? ''), array('http','https'));
        if ($url === '') {
            return null;
        }
        $warnings = isset($raw['warnings']) && is_array($raw['warnings'])
            ? array_values(array_slice(array_map('sanitize_text_field', $raw['warnings']), 0, 20))
            : array();
        return array(
            'url' => $url,
            'url_hash' => md5($url),
            'http_status' => min(999, absint($raw['http_status'] ?? 0)),
            'final_status' => min(999, absint($raw['final_status'] ?? 0)),
            'final_url' => esc_url_raw((string) ($raw['final_url'] ?? ''), array('http','https')),
            'response_ms' => min(3600000, absint($raw['response_ms'] ?? 0)),
            'ttfb_ms' => min(3600000, absint($raw['ttfb_ms'] ?? 0)),
            'content_type' => substr(sanitize_text_field((string) ($raw['content_type'] ?? '')), 0, 160),
            'content_length' => max(0, (int) ($raw['content_length'] ?? 0)),
            'error_type' => substr(sanitize_key((string) ($raw['error_type'] ?? '')), 0, 64),
            'error_message' => substr(sanitize_textarea_field((string) ($raw['error_message'] ?? '')), 0, 4000),
            'warnings' => $warnings,
            'checked_at' => !empty($raw['checked_at']) && strtotime((string) $raw['checked_at'])
                ? gmdate('Y-m-d H:i:s', strtotime((string) $raw['checked_at']))
                : current_time('mysql', true),
        );
    }
}

if (!function_exists('seo_health_scan_store_results')) {
    function seo_health_scan_store_results($run, array $results) {
        global $wpdb;
        $table = seo_health_scan_tables()['items'];
        foreach ($results as $raw) {
            $row = seo_health_scan_clean_result($raw);
            if (!$row) {
                continue;
            }
            $item = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE scope=%s AND url_hash=%s AND queued_scan_id=%d LIMIT 1",
                    $run['scope'],
                    $row['url_hash'],
                    absint($run['id'])
                ),
                ARRAY_A
            );
            if (!$item) {
                continue;
            }
            $final = (int) ($row['final_status'] ?: $row['http_status']);
            $is_error = $row['error_type'] !== '' || $final < 200 || $final >= 400;
            $bucket = $is_error ? 'error' : (!empty($row['warnings']) ? 'warning' : 'ok');
            $data = array(
                'status_bucket' => $bucket,
                'http_status' => $row['http_status'],
                'final_status' => $row['final_status'],
                'final_url' => $row['final_url'],
                'response_ms' => $row['response_ms'],
                'ttfb_ms' => $row['ttfb_ms'],
                'content_type' => $row['content_type'],
                'content_length' => $row['content_length'],
                'error_type' => $row['error_type'],
                'error_message' => $row['error_message'],
                'warnings' => wp_json_encode($row['warnings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'last_checked_at' => $row['checked_at'],
                'consecutive_errors' => $bucket === 'error' ? 1 : 0,
                'checks_total' => 1,
                'queued_scan_id' => 0,
                'queued_at' => null,
                'last_scan_id' => absint($run['id']),
            );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET
                        status_bucket=%s,http_status=%d,final_status=%d,final_url=%s,response_ms=%d,ttfb_ms=%d,
                        content_type=%s,content_length=%d,error_type=%s,error_message=%s,warnings=%s,last_checked_at=%s,
                        last_ok_at=IF(%s='ok',%s,last_ok_at),
                        last_error_at=IF(%s='error',%s,last_error_at),
                        consecutive_errors=IF(%s='error',consecutive_errors+1,0),
                        checks_total=checks_total+1,queued_scan_id=0,queued_at=NULL,last_scan_id=%d
                     WHERE id=%d",
                    $bucket,$row['http_status'],$row['final_status'],$row['final_url'],$row['response_ms'],$row['ttfb_ms'],
                    $row['content_type'],$row['content_length'],$row['error_type'],$row['error_message'],$data['warnings'],$row['checked_at'],
                    $bucket,$row['checked_at'],$bucket,$row['checked_at'],$bucket,absint($run['id']),absint($item['id'])
                )
            );
        }
    }
}

if (!function_exists('seo_health_scan_refresh_run')) {
    function seo_health_scan_refresh_run($run_id) {
        global $wpdb;
        $tables = seo_health_scan_tables();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) processed,
                    SUM(status_bucket='ok') ok_count,
                    SUM(status_bucket='warning') warning_count,
                    SUM(status_bucket='error') error_count,
                    SUM(final_status=429 OR http_status=429) status_429,
                    SUM((final_status BETWEEN 500 AND 599) OR (http_status BETWEEN 500 AND 599)) status_5xx
                 FROM {$tables['items']} WHERE last_scan_id=%d",
                absint($run_id)
            ),
            ARRAY_A
        );
        $wpdb->update(
            $tables['runs'],
            array(
                'processed_items' => absint($row['processed'] ?? 0),
                'ok_items' => absint($row['ok_count'] ?? 0),
                'warning_items' => absint($row['warning_count'] ?? 0),
                'error_items' => absint($row['error_count'] ?? 0),
                'status_429' => absint($row['status_429'] ?? 0),
                'status_5xx' => absint($row['status_5xx'] ?? 0),
                'updated_at' => current_time('mysql', true),
            ),
            array('id'=>absint($run_id)),
            array('%d','%d','%d','%d','%d','%d','%s'),
            array('%d')
        );
    }
}

if (!function_exists('seo_health_scan_rest_results')) {
    function seo_health_scan_rest_results(WP_REST_Request $request) {
        $run = seo_health_scan_auth_run($request);
        if (is_wp_error($run)) {
            return $run;
        }
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_Error('seo_health_payload', 'Payload JSON no válido.', array('status'=>400));
        }
        $event = sanitize_key((string) ($payload['event'] ?? 'batch'));
        $now = current_time('mysql', true);
        global $wpdb;
        $tables = seo_health_scan_tables();

        if ($event === 'start') {
            $wpdb->update($tables['runs'], array('status'=>'running','started_at'=>$now,'updated_at'=>$now), array('id'=>absint($run['id'])), array('%s','%s','%s'), array('%d'));
            return rest_ensure_response(array('ok'=>true));
        }
        if ($event === 'batch') {
            $results = isset($payload['results']) && is_array($payload['results']) ? array_slice($payload['results'], 0, 1000) : array();
            seo_health_scan_store_results($run, $results);
            seo_health_scan_refresh_run(absint($run['id']));
            $wpdb->update($tables['runs'], array('status'=>'running','updated_at'=>$now), array('id'=>absint($run['id'])), array('%s','%s'), array('%d'));
            return rest_ensure_response(array('ok'=>true,'results'=>count($results)));
        }
        if ($event === 'complete') {
            $summary = isset($payload['summary']) && is_array($payload['summary']) ? $payload['summary'] : array();
            seo_health_scan_refresh_run(absint($run['id']));
            $wpdb->update(
                $tables['runs'],
                array(
                    'status'=>'completed',
                    'duration_ms'=>absint($summary['duration_ms'] ?? 0),
                    'avg_response_ms'=>absint($summary['avg_response_ms'] ?? 0),
                    'p95_response_ms'=>absint($summary['p95_response_ms'] ?? 0),
                    'pressure_events'=>absint($summary['pressure_events'] ?? 0),
                    'callback_token_hash'=>'',
                    'completed_at'=>$now,
                    'updated_at'=>$now,
                ),
                array('id'=>absint($run['id'])),
                array('%s','%d','%d','%d','%d','%s','%s','%s'),
                array('%d')
            );
            $wpdb->query("UPDATE {$tables['items']} SET queued_scan_id=0,queued_at=NULL WHERE queued_scan_id=" . absint($run['id']));
            return rest_ensure_response(array('ok'=>true));
        }
        if ($event === 'failed') {
            $message = substr(sanitize_text_field((string) ($payload['error_message'] ?? 'Fallo del worker.')), 0, 4000);
            $wpdb->update($tables['runs'], array('status'=>'failed','error_message'=>$message,'callback_token_hash'=>'','completed_at'=>$now,'updated_at'=>$now), array('id'=>absint($run['id'])), array('%s','%s','%s','%s','%s'), array('%d'));
            $wpdb->query("UPDATE {$tables['items']} SET queued_scan_id=0,queued_at=NULL WHERE queued_scan_id=" . absint($run['id']));
            return rest_ensure_response(array('ok'=>true));
        }
        return new WP_Error('seo_health_event', 'Evento no reconocido.', array('status'=>400));
    }
}

if (!function_exists('seo_health_scan_register_rest')) {
    function seo_health_scan_register_rest() {
        seo_health_scan_maybe_upgrade();
        register_rest_route('seo-system/v1', '/health-scan/batch', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => 'seo_health_scan_rest_batch',
            'permission_callback' => '__return_true',
        ));
        register_rest_route('seo-system/v1', '/health-scan/results', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => 'seo_health_scan_rest_results',
            'permission_callback' => '__return_true',
        ));
    }
}
add_action('rest_api_init', 'seo_health_scan_register_rest');

if (!function_exists('seo_health_scan_notice')) {
    function seo_health_scan_notice() {
        $msg = isset($_GET['health_msg']) ? sanitize_key(wp_unslash($_GET['health_msg'])) : '';
        $detail = isset($_GET['health_detail']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['health_detail']))) : '';
        if ($msg === '') {
            return;
        }
        $type = in_array($msg, array('synced','started'), true) ? 'success' : 'error';
        echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($detail ?: $msg) . '</p></div>';
    }
}

if (!function_exists('seo_health_scan_status_label')) {
    function seo_health_scan_status_label($status) {
        $map = array('ok'=>'Correcto','warning'=>'Aviso','error'=>'Error','pending'=>'Pendiente');
        return $map[$status] ?? ucfirst((string) $status);
    }
}

if (!function_exists('seo_health_render_scope_tab')) {
    function seo_health_render_scope_tab($scope) {
        $scope_config = seo_health_scan_scope_config($scope);
        if (!$scope_config) {
            echo '<div class="notice notice-error inline"><p>Ámbito de auditoría no válido.</p></div>';
            return;
        }
        seo_health_scan_maybe_upgrade();
        $summary = seo_health_scan_summary($scope);
        $active = seo_health_scan_active_run($scope);
        $latest = seo_health_scan_latest_run($scope);
        $runner = seo_health_scan_runner_config($scope);
        $missing = seo_health_scan_config_errors($runner);
        $coverage = $summary['total'] ? round(($summary['checked'] / $summary['total']) * 100, 1) : 0;

        echo '<style>
        .seo-health-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:14px 0}.seo-health-actions form{margin:0}
        .seo-health-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin:14px 0}.seo-health-kpi,.seo-health-card{background:#fff;border:1px solid #dcdcde;border-radius:5px;padding:14px}.seo-health-kpi strong{font-size:24px;display:block}.seo-health-kpi span{color:#50575e}
        .seo-health-progress{height:10px;background:#e5e5e5;border-radius:8px;overflow:hidden}.seo-health-progress span{display:block;height:100%;background:#2271b1}
        .seo-health-badge{display:inline-block;padding:2px 7px;border-radius:12px;font-size:12px;font-weight:600}.seo-health-badge.ok{background:#edfaef;color:#116329}.seo-health-badge.warning{background:#fff8e5;color:#8a5800}.seo-health-badge.error{background:#fcf0f1;color:#8a2424}.seo-health-badge.pending{background:#f0f0f1;color:#50575e}.seo-health-url{max-width:620px;word-break:break-word}.seo-health-error-row{background:#fff5f5!important}.seo-health-warning-row{background:#fffaf0!important}
        </style>';

        seo_health_scan_notice();
        echo '<div class="seo-health-card">';
        echo '<h2 style="margin-top:0">Errores y disponibilidad de ' . esc_html(strtolower($scope_config['label'])) . '</h2>';
        if ($scope === 'image') {
            echo '<p>Este escaneo comprueba directamente las URLs de imagen conocidas y activas. No vuelve a recorrer las 14.000+ URLs del sitemap para cada pasada, por lo que reduce mucho la carga y evita que el proceso se rompa por una cadena larga de páginas.</p>';
        } else {
            echo '<p>El inventario se construye solo con <strong>' . esc_html(strtolower($scope_config['label'])) . ' publicadas</strong>. Productos, categorías, imágenes y otros tipos no entran en este escaneo.</p>';
        }
        echo '<div class="seo-health-actions">';
        foreach (array('sync'=>'Actualizar inventario','scan'=>'Escanear siguiente lote','load_test'=>'Test de carga seguro') as $task=>$label) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="seo_health_scan_action"><input type="hidden" name="scope" value="' . esc_attr($scope) . '"><input type="hidden" name="task" value="' . esc_attr($task) . '">';
            wp_nonce_field('seo_health_scan_action');
            $disabled = ($task !== 'sync' && (!empty($active) || !empty($missing))) ? ' disabled' : '';
            $class = $task === 'scan' ? 'button button-primary' : 'button';
            echo '<button class="' . esc_attr($class) . '"' . $disabled . '>' . esc_html($label) . '</button></form>';
        }
        if ($active) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'¿Cancelar este lote? Los elementos no recibidos volverán a quedar disponibles.\');">';
            echo '<input type="hidden" name="action" value="seo_health_scan_action"><input type="hidden" name="scope" value="' . esc_attr($scope) . '"><input type="hidden" name="task" value="cancel">';
            wp_nonce_field('seo_health_scan_action');
            echo '<button class="button" style="color:#b32d2e">Cancelar lote</button></form>';
            echo '<span class="description"><strong>En curso:</strong> ' . esc_html($active['mode'] === 'load_test' ? 'test de carga' : 'escaneo') . ' · ' . esc_html(number_format_i18n($active['processed_items'])) . '/' . esc_html(number_format_i18n($active['total_items'])) . '</span>';
        }
        echo '</div>';
        if (!empty($missing)) {
            echo '<p style="color:#b32d2e"><strong>GitHub incompleto:</strong> ' . esc_html(implode(', ', $missing)) . '.</p>';
        } else {
            echo '<p class="description">Runner: <code>' . esc_html($runner['owner'] . '/' . $runner['repo']) . '</code> · workflow <code>' . esc_html($runner['workflow']) . '</code> · ref <code>' . esc_html($runner['ref']) . '</code>.</p>';
        }
        echo '</div>';

        echo '<div class="seo-health-grid">';
        foreach (array(
            array($summary['total'],'Activas'),array($summary['checked'],'Comprobadas'),array($summary['pending'],'Pendientes'),array($summary['ok_count'],'Correctas'),array($summary['warning_count'],'Avisos'),array($summary['error_count'],'Errores')
        ) as $card) {
            echo '<div class="seo-health-kpi"><strong>' . esc_html(number_format_i18n($card[0])) . '</strong><span>' . esc_html($card[1]) . '</span></div>';
        }
        echo '</div>';
        echo '<div class="seo-health-card"><h3 style="margin-top:0">Cobertura: ' . esc_html(number_format_i18n($coverage,1)) . '%</h3><div class="seo-health-progress"><span style="width:' . esc_attr((string) min(100,$coverage)) . '%"></span></div></div>';

        if ($latest) {
            echo '<div class="seo-health-card" style="margin-top:12px"><h3 style="margin-top:0">Última ejecución</h3>';
            echo '<p><strong>' . esc_html($latest['mode'] === 'load_test' ? 'Test de carga' : 'Escaneo') . '</strong> · estado ' . esc_html($latest['status']) . ' · ' . esc_html(number_format_i18n($latest['processed_items'])) . '/' . esc_html(number_format_i18n($latest['total_items'])) . ' · OK ' . esc_html(number_format_i18n($latest['ok_items'])) . ' · avisos ' . esc_html(number_format_i18n($latest['warning_items'])) . ' · errores ' . esc_html(number_format_i18n($latest['error_items'])) . '.</p>';
            if (!empty($latest['duration_ms'])) {
                echo '<p class="description">Duración ' . esc_html(number_format_i18n(round($latest['duration_ms']/1000,1),1)) . ' s · media ' . esc_html(number_format_i18n($latest['avg_response_ms'])) . ' ms · p95 ' . esc_html(number_format_i18n($latest['p95_response_ms'])) . ' ms · 429 ' . esc_html(number_format_i18n($latest['status_429'])) . ' · 5xx ' . esc_html(number_format_i18n($latest['status_5xx'])) . '.</p>';
            }
            if (!empty($latest['error_message'])) {
                echo '<p style="color:#b32d2e"><code>' . esc_html($latest['error_message']) . '</code></p>';
            }
            echo '</div>';
        }

        global $wpdb;
        $table = seo_health_scan_tables()['items'];
        $filter = isset($_GET['health_filter']) ? sanitize_key(wp_unslash($_GET['health_filter'])) : 'error';
        if (!in_array($filter, array('error','warning','pending','ok','all'), true)) {
            $filter = 'error';
        }
        $search = isset($_GET['health_s']) ? sanitize_text_field(wp_unslash($_GET['health_s'])) : '';
        $paged = isset($_GET['health_paged']) ? max(1, absint($_GET['health_paged'])) : 1;
        $per_page = 50;
        $where = array('scope=%s','active=1');
        $params = array($scope);
        if ($filter === 'pending') {
            $where[] = 'last_checked_at IS NULL';
        } elseif ($filter !== 'all') {
            $where[] = 'status_bucket=%s';
            $params[] = $filter;
        }
        if ($search !== '') {
            $where[] = '(url LIKE %s OR label LIKE %s OR error_message LIKE %s)';
            $needle = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $needle; $params[] = $needle; $params[] = $needle;
        }
        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total_rows = (int) $wpdb->get_var($wpdb->prepare($count_sql, $params));
        $offset = ($paged - 1) * $per_page;
        $rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY CASE status_bucket WHEN 'error' THEN 0 WHEN 'warning' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END,last_checked_at ASC,id ASC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, array_merge($params,array($per_page,$offset))), ARRAY_A);

        echo '<div class="seo-health-card" style="margin-top:12px"><h3 style="margin-top:0">Estado por elemento</h3><div style="display:flex;gap:6px;flex-wrap:wrap">';
        foreach (array('error'=>'Errores','warning'=>'Avisos','pending'=>'Pendientes','ok'=>'Correctas','all'=>'Todas') as $key=>$label) {
            echo '<a class="button' . ($filter===$key?' button-primary':'') . '" href="' . esc_url(seo_health_scan_admin_url($scope,array('health_filter'=>$key))) . '">' . esc_html($label) . '</a>';
        }
        echo '</div><form method="get" style="display:flex;gap:6px;margin:10px 0;flex-wrap:wrap">';
        foreach ($scope_config['admin'] as $name=>$value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        echo '<input type="hidden" name="health_filter" value="' . esc_attr($filter) . '"><input type="search" name="health_s" value="' . esc_attr($search) . '" placeholder="Buscar URL o error" class="regular-text"><button class="button">Buscar</button></form>';
        echo '<table class="widefat striped"><thead><tr><th>Elemento</th><th>Estado</th><th>HTTP</th><th>Tiempo</th><th>Detalle</th><th>Último chequeo</th></tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="6">No hay resultados para este filtro.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $status = $row['last_checked_at'] ? (string) $row['status_bucket'] : 'pending';
                $class = $status === 'error' ? 'seo-health-error-row' : ($status === 'warning' ? 'seo-health-warning-row' : '');
                $warnings = json_decode((string) $row['warnings'], true);
                $details = array();
                if (!empty($row['error_type'])) $details[] = $row['error_type'];
                if (!empty($row['error_message'])) $details[] = $row['error_message'];
                if (is_array($warnings)) $details = array_merge($details, array_slice($warnings,0,3));
                echo '<tr class="' . esc_attr($class) . '"><td class="seo-health-url"><strong>' . esc_html($row['label'] ?: wp_basename((string) wp_parse_url($row['url'],PHP_URL_PATH))) . '</strong><br><a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener">' . esc_html($row['url']) . '</a></td>';
                echo '<td><span class="seo-health-badge ' . esc_attr($status) . '">' . esc_html(seo_health_scan_status_label($status)) . '</span></td>';
                $http = absint($row['final_status'] ?: $row['http_status']);
                echo '<td>' . ($http ? esc_html((string) $http) : '—') . '</td><td>' . ($row['last_checked_at'] ? esc_html(number_format_i18n($row['response_ms'])) . ' ms' : '—') . '</td>';
                echo '<td>' . (empty($details) ? '—' : esc_html(implode(' · ', $details))) . '</td><td>' . esc_html($row['last_checked_at'] ?: '—') . '</td></tr>';
            }
        }
        echo '</tbody></table>';
        $total_pages = max(1, (int) ceil($total_rows / $per_page));
        if ($total_pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">' . wp_kses_post(paginate_links(array(
                'base'=>add_query_arg(array('health_filter'=>$filter,'health_s'=>$search,'health_paged'=>'%#%'),seo_health_scan_admin_url($scope)),
                'format'=>'','current'=>$paged,'total'=>$total_pages,'prev_text'=>'‹','next_text'=>'›'
            ))) . '</div></div>';
        }
        echo '</div>';

        if ($active) {
            echo '<script>window.setTimeout(function(){if(!document.hidden){window.location.reload();}},15000);</script>';
        }
    }
}
