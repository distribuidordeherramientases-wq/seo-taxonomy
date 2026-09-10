<?php
/**
 * Fuentes complementarias del Analista: GA4, busqueda interna, proveedores y
 * datos competitivos importados desde SEMrush u otra herramienta equivalente.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_ANALISTA_OPTION_SEMRUSH')) {
    define('SEO_ANALISTA_OPTION_SEMRUSH', 'seo_analista_semrush_snapshot');
}
if (!defined('SEO_ANALISTA_OPTION_SEMRUSH_HISTORY')) {
    define('SEO_ANALISTA_OPTION_SEMRUSH_HISTORY', 'seo_analista_semrush_history');
}

if (!function_exists('seo_analista_table_exists')) {
    function seo_analista_table_exists($table) {
        global $wpdb;
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $table);
        if ($table === '') return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }
}

if (!function_exists('seo_analista_ga4_snapshot')) {
    function seo_analista_ga4_snapshot($days = 28) {
        $days = seo_analista_days($days);
        $empty = array('available'=>false,'sessions'=>0,'users'=>0,'pageviews'=>0,'purchases'=>0,'revenue'=>0.0,'error'=>'');
        if (!function_exists('seo_google_analytics_run_report')) return $empty;

        $cache_key = 'seo_analista_ga4_' . get_current_blog_id() . '_' . $days;
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        $report = seo_google_analytics_run_report(array(
            'dateRanges' => array(array('startDate' => $days . 'daysAgo', 'endDate' => 'today')),
            'metrics' => array(
                array('name'=>'sessions'),
                array('name'=>'activeUsers'),
                array('name'=>'screenPageViews'),
                array('name'=>'ecommercePurchases'),
                array('name'=>'purchaseRevenue'),
            ),
            'limit' => 1,
        ));
        if (is_wp_error($report)) {
            $empty['error'] = $report->get_error_message();
            set_transient($cache_key, $empty, 5 * MINUTE_IN_SECONDS);
            return $empty;
        }
        $values = (array) ($report['rows'][0]['metricValues'] ?? array());
        $value = static function($index) use ($values) {
            return isset($values[$index]['value']) ? (float) $values[$index]['value'] : 0.0;
        };
        $out = array(
            'available'=>true,
            'sessions'=>(int) $value(0),
            'users'=>(int) $value(1),
            'pageviews'=>(int) $value(2),
            'purchases'=>(int) $value(3),
            'revenue'=>(float) $value(4),
            'error'=>'',
        );
        set_transient($cache_key, $out, 15 * MINUTE_IN_SECONDS);
        return $out;
    }
}

if (!function_exists('seo_analista_internal_search_snapshot')) {
    function seo_analista_internal_search_snapshot($days = 28, $limit = 20) {
        global $wpdb;
        $days = seo_analista_days($days);
        $table = $wpdb->prefix . 'seo_search_log';
        $out = array('available'=>false,'total'=>0,'unique'=>0,'zero_results'=>0,'top'=>array(),'gaps'=>array());
        if (!seo_analista_table_exists($table)) return $out;

        $since = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
        $summary = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) total, COUNT(DISTINCT normalized_term) unique_terms, SUM(CASE WHEN results_count=0 THEN 1 ELSE 0 END) zero_results FROM {$table} WHERE searched_at >= %s",
            $since
        ), ARRAY_A);
        $out['available'] = true;
        $out['total'] = (int) ($summary['total'] ?? 0);
        $out['unique'] = (int) ($summary['unique_terms'] ?? 0);
        $out['zero_results'] = (int) ($summary['zero_results'] ?? 0);

        $sql = "SELECT MAX(search_term) search_term, normalized_term, COUNT(*) searches, AVG(results_count) avg_results, SUM(CASE WHEN results_count=0 THEN 1 ELSE 0 END) zero_count, MAX(searched_at) last_search FROM {$table} WHERE searched_at >= %s GROUP BY normalized_term ORDER BY searches DESC, zero_count DESC LIMIT %d";
        $out['top'] = (array) $wpdb->get_results($wpdb->prepare($sql, $since, max(5, min(100, absint($limit)))), ARRAY_A);
        foreach ($out['top'] as $row) {
            if ((int) ($row['zero_count'] ?? 0) > 0 || (float) ($row['avg_results'] ?? 0) < 2.0) $out['gaps'][] = $row;
        }
        return $out;
    }
}

if (!function_exists('seo_analista_supplier_snapshot')) {
    function seo_analista_supplier_snapshot($limit = 20) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_proveedores_productos';
        $out = array('available'=>false,'total'=>0,'providers'=>0,'published_links'=>0,'rows'=>array(),'issues'=>array());
        if (!seo_analista_table_exists($table)) return $out;

        $out['available'] = true;
        $out['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $out['providers'] = (int) $wpdb->get_var("SELECT COUNT(DISTINCT proveedor) FROM {$table}");
        $out['published_links'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE object_id IS NOT NULL AND object_id > 0");

        $sql = "SELECT proveedor,
                    COUNT(*) total,
                    SUM(CASE WHEN object_id IS NOT NULL AND object_id>0 THEN 1 ELSE 0 END) linked,
                    SUM(CASE WHEN estado_seleccion='descartado' THEN 1 ELSE 0 END) discarded,
                    SUM(CASE WHEN estado_seleccion='pendiente' THEN 1 ELSE 0 END) pending,
                    SUM(CASE WHEN ultimo_error_sync IS NOT NULL AND ultimo_error_sync<>'' THEN 1 ELSE 0 END) errors,
                    SUM(CASE WHEN stock_estado IN ('instock','in_stock','disponible') OR stock_cantidad>0 THEN 1 ELSE 0 END) with_stock,
                    MAX(ultima_importacion) last_import,
                    MAX(ultima_sincronizacion) last_sync
                FROM {$table}
                GROUP BY proveedor
                ORDER BY total DESC
                LIMIT %d";
        $out['rows'] = (array) $wpdb->get_results($wpdb->prepare($sql, max(5, min(100, absint($limit)))), ARRAY_A);
        $now = current_time('timestamp');
        foreach ($out['rows'] as $row) {
            $problems = array();
            if ((int) ($row['errors'] ?? 0) > 0) $problems[] = number_format_i18n((int)$row['errors']) . ' errores de sincronización';
            $last = !empty($row['last_import']) ? strtotime((string)$row['last_import']) : 0;
            if ($last && ($now - $last) > 7 * DAY_IN_SECONDS) $problems[] = 'importación sin actualizar > 7 días';
            $total = max(1, (int) ($row['total'] ?? 0));
            if (((int)($row['pending'] ?? 0) / $total) > 0.50) $problems[] = 'más del 50% pendiente de revisar';
            if ($problems) {
                $out['issues'][] = array('provider'=>(string)$row['proveedor'],'problems'=>$problems,'row'=>$row);
            }
        }
        return $out;
    }
}

if (!function_exists('seo_analista_semrush_snapshot')) {
    function seo_analista_semrush_snapshot() {
        $data = get_option(SEO_ANALISTA_OPTION_SEMRUSH, array());
        return is_array($data) ? $data : array();
    }
}

if (!function_exists('seo_analista_semrush_history')) {
    function seo_analista_semrush_history() {
        $history = get_option(SEO_ANALISTA_OPTION_SEMRUSH_HISTORY, array());
        return is_array($history) ? $history : array();
    }
}

if (!function_exists('seo_analista_store_competition_history')) {
    function seo_analista_store_competition_history() {
        if (!function_exists('seo_analista_competition_snapshot')) return;
        $competition = seo_analista_competition_snapshot(20);
        if (empty($competition['available'])) return;

        $entry = array(
            'imported_at' => (string) ($competition['imported_at'] ?? current_time('mysql')),
            'row_count' => (int) ($competition['row_count'] ?? 0),
            'own_domain' => (string) ($competition['own_domain'] ?? ''),
            'domains' => array_values((array) ($competition['domains'] ?? array())),
        );
        $history = seo_analista_semrush_history();
        $history[] = $entry;

        $unique = array();
        foreach (array_reverse($history) as $item) {
            $key = (string) ($item['imported_at'] ?? '');
            if ($key === '' || isset($unique[$key])) continue;
            $unique[$key] = $item;
            if (count($unique) >= 24) break;
        }
        update_option(SEO_ANALISTA_OPTION_SEMRUSH_HISTORY, array_reverse(array_values($unique)), false);
    }
}

if (!function_exists('seo_analista_csv_delimiter')) {
    function seo_analista_csv_delimiter($line) {
        $counts = array(','=>substr_count($line, ','), ';'=>substr_count($line, ';'), "\t"=>substr_count($line, "\t"));
        arsort($counts);
        return (string) key($counts);
    }
}

if (!function_exists('seo_analista_parse_number')) {
    function seo_analista_parse_number($value) {
        $value = trim((string) $value);
        if ($value === '' || $value === '-' || strtolower($value) === 'n/d') return 0.0;
        $value = str_replace(array('%',' '), '', $value);
        if (substr_count($value, ',') === 1 && substr_count($value, '.') === 0) $value = str_replace(',', '.', $value);
        else $value = str_replace(',', '', $value);
        return is_numeric($value) ? (float) $value : 0.0;
    }
}

if (!function_exists('seo_analista_import_semrush_csv')) {
    function seo_analista_import_semrush_csv($path) {
        $handle = @fopen($path, 'rb');
        if (!$handle) return new WP_Error('seo_analista_semrush_open', 'No se pudo abrir el CSV.');
        $first = fgets($handle);
        if ($first === false) { fclose($handle); return new WP_Error('seo_analista_semrush_empty', 'El CSV está vacío.'); }
        $delimiter = seo_analista_csv_delimiter($first);
        rewind($handle);
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!$headers) { fclose($handle); return new WP_Error('seo_analista_semrush_headers', 'No se pudo leer la cabecera del CSV.'); }
        $headers = array_map(static function($h){ return trim(remove_accents(mb_strtolower((string)$h, 'UTF-8'))); }, $headers);

        $keyword_idx = null; $volume_idx = null; $kd_idx = null; $url_idx = null; $domain_idx = null; $position_idx = null;
        $domain_columns = array();
        foreach ($headers as $i=>$h) {
            if ($keyword_idx === null && in_array($h, array('palabra clave','keyword','query','consulta'), true)) $keyword_idx = $i;
            if ($volume_idx === null && preg_match('/^(volumen|volume)$/', $h)) $volume_idx = $i;
            if ($kd_idx === null && (strpos($h,'kd') === 0 || strpos($h,'dificultad') !== false)) $kd_idx = $i;
            if ($url_idx === null && in_array($h, array('url','pagina','page'), true)) $url_idx = $i;
            if ($domain_idx === null && in_array($h, array('dominio','domain'), true)) $domain_idx = $i;
            if ($position_idx === null && (in_array($h,array('posicion','position','pos.'),true) || strpos($h,'posicion')===0)) $position_idx = $i;
            $candidate = preg_replace('#^https?://#','',$h);
            $candidate = preg_replace('#/.*$#','',$candidate);
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $candidate)) $domain_columns[$i] = preg_replace('/^www\./','',$candidate);
        }
        if ($keyword_idx === null) { fclose($handle); return new WP_Error('seo_analista_semrush_keyword', 'No encuentro la columna Palabra clave / Keyword.'); }

        $own = preg_replace('/^www\./','',strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)));
        $rows = array(); $read = 0;
        while (($cols = fgetcsv($handle, 0, $delimiter)) !== false && $read < 3000) {
            $read++;
            $keyword = sanitize_text_field((string)($cols[$keyword_idx] ?? ''));
            if ($keyword === '') continue;
            $volume = $volume_idx !== null ? seo_analista_parse_number($cols[$volume_idx] ?? 0) : 0;
            $kd = $kd_idx !== null ? seo_analista_parse_number($cols[$kd_idx] ?? 0) : 0;
            if ($domain_columns) {
                foreach ($domain_columns as $i=>$domain) {
                    $pos = seo_analista_parse_number($cols[$i] ?? 0);
                    if ($pos <= 0) continue;
                    $rows[] = array('keyword'=>$keyword,'domain'=>$domain,'position'=>$pos,'volume'=>$volume,'difficulty'=>$kd,'url'=>'','source'=>'semrush_csv');
                }
            } elseif ($position_idx !== null) {
                $domain = $domain_idx !== null ? trim((string)($cols[$domain_idx] ?? '')) : $own;
                if ($domain === '' && $url_idx !== null) $domain = (string) wp_parse_url((string)($cols[$url_idx] ?? ''), PHP_URL_HOST);
                $domain = preg_replace('/^www\./','',strtolower($domain));
                $pos = seo_analista_parse_number($cols[$position_idx] ?? 0);
                if ($pos > 0) $rows[] = array('keyword'=>$keyword,'domain'=>$domain,'position'=>$pos,'volume'=>$volume,'difficulty'=>$kd,'url'=>$url_idx!==null?esc_url_raw((string)($cols[$url_idx] ?? '')):'','source'=>'semrush_csv');
            }
            if (count($rows) >= 6000) break;
        }
        fclose($handle);
        $snapshot = array('imported_at'=>current_time('mysql'),'rows'=>$rows,'row_count'=>count($rows),'own_domain'=>$own);
        update_option(SEO_ANALISTA_OPTION_SEMRUSH, $snapshot, false);
        if (function_exists('seo_analista_store_competition_history')) {
            seo_analista_store_competition_history();
        }
        return $snapshot;
    }
}

if (!function_exists('seo_analista_semrush_import_handler')) {
    function seo_analista_semrush_import_handler() {
        if (!current_user_can('manage_options')) wp_die('Sin permisos.');
        check_admin_referer('seo_analista_semrush_import','seo_analista_semrush_nonce');
        if (empty($_FILES['semrush_csv']['tmp_name'])) {
            wp_safe_redirect(seo_analista_admin_url(array('analista_view'=>'comparacion','analista_notice'=>'semrush_missing'))); exit;
        }
        $result = seo_analista_import_semrush_csv($_FILES['semrush_csv']['tmp_name']);
        $notice = is_wp_error($result) ? 'semrush_error' : 'semrush_ok';
        if (is_wp_error($result)) set_transient('seo_analista_semrush_error_' . get_current_user_id(), $result->get_error_message(), 60);
        wp_safe_redirect(seo_analista_admin_url(array('analista_view'=>'comparacion','analista_notice'=>$notice))); exit;
    }
}
