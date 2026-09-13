<?php
/**
 * Bing Webmaster adapter for Analista.
 *
 * Bing is a secondary source: it complements Google Search Console with
 * Bing traffic, query/page visibility and crawl/index health. It does not
 * submit URLs because IndexNow is already handled by Cloudflare.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_ANALISTA_BING_OPTION')) {
    define('SEO_ANALISTA_BING_OPTION', 'seo_analista_bing_settings');
}

if (!function_exists('seo_analista_bing_get_settings')) {
    function seo_analista_bing_get_settings() {
        $defaults = array(
            'enabled' => true,
            'site_url' => home_url('/'),
            'api_key' => '',
        );
        $settings = get_option(SEO_ANALISTA_BING_OPTION, array());
        $settings = wp_parse_args(is_array($settings) ? $settings : array(), $defaults);
        $settings['enabled'] = !empty($settings['enabled']);
        $settings['site_url'] = esc_url_raw((string) ($settings['site_url'] ?? home_url('/')));
        if ($settings['site_url'] === '') $settings['site_url'] = home_url('/');
        $settings['api_key'] = trim((string) ($settings['api_key'] ?? ''));
        return $settings;
    }
}

if (!function_exists('seo_analista_bing_api_key')) {
    function seo_analista_bing_api_key() {
        if (defined('SEO_BING_WEBMASTER_API_KEY') && trim((string) SEO_BING_WEBMASTER_API_KEY) !== '') {
            return trim((string) SEO_BING_WEBMASTER_API_KEY);
        }
        $settings = seo_analista_bing_get_settings();
        return trim((string) apply_filters('seo_analista_bing_api_key', $settings['api_key'] ?? ''));
    }
}

if (!function_exists('seo_analista_bing_parse_date')) {
    function seo_analista_bing_parse_date($value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        if (preg_match('#/Date\\((\\d+)(?:[+-]\\d+)?\\)/#', $value, $m)) {
            $seconds = (int) floor(((float) $m[1]) / 1000);
            return gmdate('Y-m-d', $seconds);
        }
        $timestamp = strtotime($value);
        return $timestamp ? gmdate('Y-m-d', $timestamp) : '';
    }
}

if (!function_exists('seo_analista_bing_request')) {
    function seo_analista_bing_request($method, array $params = array(), $ttl = 900) {
        $allowed = array('GetRankAndTrafficStats', 'GetCrawlStats', 'GetQueryStats', 'GetPageStats');
        if (!in_array($method, $allowed, true)) {
            return new WP_Error('bing_method', 'Metodo de Bing no permitido.');
        }

        $settings = seo_analista_bing_get_settings();
        if (empty($settings['enabled'])) return new WP_Error('bing_disabled', 'Bing esta desactivado.');
        $api_key = seo_analista_bing_api_key();
        if ($api_key === '') return new WP_Error('bing_not_configured', 'Falta configurar la API key de Bing Webmaster.');

        $params['siteUrl'] = (string) ($params['siteUrl'] ?? $settings['site_url']);
        $params['apikey'] = $api_key;
        $cache_key = 'seo_an_bing_' . md5($method . '|' . wp_json_encode($params));
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        $url = add_query_arg($params, 'https://ssl.bing.com/webmaster/api.svc/json/' . rawurlencode($method));
        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'redirection' => 2,
            'headers' => array('Accept' => 'application/json'),
            'user-agent' => 'DHT-SEO-Analista/' . (defined('SEO_ANALISTA_VERSION') ? SEO_ANALISTA_VERSION : '1.0'),
        ));
        if (is_wp_error($response)) return $response;

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('bing_http', 'Bing Webmaster respondio HTTP ' . $code . '.');
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) return new WP_Error('bing_json', 'Bing Webmaster devolvio JSON no valido.');

        $rows = isset($decoded['d']) && is_array($decoded['d']) ? $decoded['d'] : array();
        set_transient($cache_key, $rows, max(300, absint($ttl)));
        return $rows;
    }
}

if (!function_exists('seo_analista_bing_rows_in_period')) {
    function seo_analista_bing_rows_in_period(array $rows, $days) {
        $days = max(1, absint($days));
        $cutoff = gmdate('Y-m-d', time() - (($days - 1) * DAY_IN_SECONDS));
        $out = array();
        foreach ($rows as $row) {
            $row = (array) $row;
            $date = seo_analista_bing_parse_date($row['Date'] ?? '');
            if ($date === '' || $date < $cutoff) continue;
            $row['_date'] = $date;
            $out[] = $row;
        }
        usort($out, static function($a, $b) {
            return strcmp((string) ($a['_date'] ?? ''), (string) ($b['_date'] ?? ''));
        });
        return $out;
    }
}

if (!function_exists('seo_analista_bing_aggregate_stats')) {
    function seo_analista_bing_aggregate_stats(array $rows, $key_name, $limit = 50) {
        $groups = array();
        foreach ($rows as $row) {
            $key = trim((string) ($row[$key_name] ?? ''));
            if ($key === '') continue;
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'value' => $key,
                    'clicks' => 0.0,
                    'impressions' => 0.0,
                    'weighted_position' => 0.0,
                    'position_weight' => 0.0,
                    'latest_date' => '',
                );
            }
            $clicks = (float) ($row['Clicks'] ?? 0);
            $impressions = (float) ($row['Impressions'] ?? 0);
            $position = (float) ($row['AvgImpressionPosition'] ?? 0);
            $groups[$key]['clicks'] += $clicks;
            $groups[$key]['impressions'] += $impressions;
            if ($position > 0 && $impressions > 0) {
                $groups[$key]['weighted_position'] += $position * $impressions;
                $groups[$key]['position_weight'] += $impressions;
            }
            $date = (string) ($row['_date'] ?? seo_analista_bing_parse_date($row['Date'] ?? ''));
            if ($date > $groups[$key]['latest_date']) $groups[$key]['latest_date'] = $date;
        }

        $out = array();
        foreach ($groups as $group) {
            $impressions = (float) $group['impressions'];
            $out[] = array(
                'value' => (string) $group['value'],
                'clicks' => (float) $group['clicks'],
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? ((float) $group['clicks'] / $impressions) : 0.0,
                'position' => $group['position_weight'] > 0 ? ($group['weighted_position'] / $group['position_weight']) : 0.0,
                'latest_date' => (string) $group['latest_date'],
            );
        }
        usort($out, static function($a, $b) {
            $cmp = (float) ($b['impressions'] ?? 0) <=> (float) ($a['impressions'] ?? 0);
            if ($cmp !== 0) return $cmp;
            return (float) ($b['clicks'] ?? 0) <=> (float) ($a['clicks'] ?? 0);
        });
        return array_slice($out, 0, max(5, min(200, absint($limit))));
    }
}

if (!function_exists('seo_analista_bing_snapshot')) {
    function seo_analista_bing_snapshot($days = 28, $limit = 50) {
        $days = function_exists('seo_analista_days') ? seo_analista_days($days) : max(1, absint($days));
        $settings = seo_analista_bing_get_settings();
        $base = array(
            'available' => false,
            'connected' => false,
            'configured' => seo_analista_bing_api_key() !== '',
            'enabled' => !empty($settings['enabled']),
            'days' => $days,
            'latest_date' => '',
            'traffic' => array('clicks'=>0.0,'impressions'=>0.0,'ctr'=>0.0),
            'crawl' => array('crawled_pages'=>0,'code2xx'=>0,'code4xx'=>0,'code5xx'=>0,'blocked_robots'=>0,'crawl_errors'=>0,'in_index'=>0,'in_links'=>0),
            'top_queries' => array(),
            'top_pages' => array(),
            'errors' => array(),
        );
        if (empty($base['enabled']) || empty($base['configured'])) return $base;

        $traffic_raw = seo_analista_bing_request('GetRankAndTrafficStats', array(), 15 * MINUTE_IN_SECONDS);
        $crawl_raw = seo_analista_bing_request('GetCrawlStats', array(), 15 * MINUTE_IN_SECONDS);
        $queries_raw = seo_analista_bing_request('GetQueryStats', array(), 30 * MINUTE_IN_SECONDS);
        $pages_raw = seo_analista_bing_request('GetPageStats', array(), 30 * MINUTE_IN_SECONDS);

        $results = array('traffic'=>$traffic_raw,'crawl'=>$crawl_raw,'queries'=>$queries_raw,'pages'=>$pages_raw);
        foreach ($results as $name => $result) {
            if (is_wp_error($result)) $base['errors'][$name] = $result->get_error_message();
        }
        if (count($base['errors']) === count($results)) return $base;
        $base['connected'] = true;

        $traffic = is_wp_error($traffic_raw) ? array() : seo_analista_bing_rows_in_period((array) $traffic_raw, $days);
        foreach ($traffic as $row) {
            $base['traffic']['clicks'] += (float) ($row['Clicks'] ?? 0);
            $base['traffic']['impressions'] += (float) ($row['Impressions'] ?? 0);
            if (($row['_date'] ?? '') > $base['latest_date']) $base['latest_date'] = (string) $row['_date'];
        }
        if ($base['traffic']['impressions'] > 0) {
            $base['traffic']['ctr'] = $base['traffic']['clicks'] / $base['traffic']['impressions'];
        }

        $crawl = is_wp_error($crawl_raw) ? array() : seo_analista_bing_rows_in_period((array) $crawl_raw, $days);
        $latest_crawl = array();
        foreach ($crawl as $row) {
            $base['crawl']['crawled_pages'] += (int) ($row['CrawledPages'] ?? 0);
            $base['crawl']['code2xx'] += (int) ($row['Code2xx'] ?? 0);
            $base['crawl']['code4xx'] += (int) ($row['Code4xx'] ?? 0);
            $base['crawl']['code5xx'] += (int) ($row['Code5xx'] ?? 0);
            $base['crawl']['blocked_robots'] += (int) ($row['BlockedByRobotsTxt'] ?? 0);
            $base['crawl']['crawl_errors'] += (int) ($row['CrawlErrors'] ?? 0);
            $latest_crawl = $row;
            if (($row['_date'] ?? '') > $base['latest_date']) $base['latest_date'] = (string) $row['_date'];
        }
        if ($latest_crawl) {
            $base['crawl']['in_index'] = (int) ($latest_crawl['InIndex'] ?? 0);
            $base['crawl']['in_links'] = (int) ($latest_crawl['InLinks'] ?? 0);
        }

        $queries = is_wp_error($queries_raw) ? array() : seo_analista_bing_rows_in_period((array) $queries_raw, $days);
        $pages = is_wp_error($pages_raw) ? array() : seo_analista_bing_rows_in_period((array) $pages_raw, $days);
        $base['top_queries'] = seo_analista_bing_aggregate_stats($queries, 'Query', $limit);
        $base['top_pages'] = seo_analista_bing_aggregate_stats($pages, 'Query', $limit);

        foreach ($base['top_queries'] as &$row) $row['query'] = $row['value'];
        unset($row);
        foreach ($base['top_pages'] as &$row) $row['url'] = esc_url_raw($row['value']);
        unset($row);

        $base['available'] = !empty($traffic) || !empty($crawl) || !empty($base['top_queries']) || !empty($base['top_pages']);
        return $base;
    }
}

if (!function_exists('seo_analista_bing_source_health')) {
    function seo_analista_bing_source_health($days = 28) {
        $snapshot = seo_analista_bing_snapshot($days, 20);
        if (empty($snapshot['enabled'])) {
            return array('label'=>'Bing Webmaster','state'=>'pending','connected'=>false,'detail'=>'Fuente desactivada.');
        }
        if (empty($snapshot['configured'])) {
            return array('label'=>'Bing Webmaster','state'=>'pending','connected'=>false,'detail'=>'Falta configurar la API key.');
        }
        if (empty($snapshot['connected'])) {
            return array('label'=>'Bing Webmaster','state'=>'partial','connected'=>false,'detail'=>'No se pudo conectar con Bing Webmaster.');
        }
        $crawl = (array) ($snapshot['crawl'] ?? array());
        $detail = number_format_i18n((int) ($crawl['in_index'] ?? 0)) . ' URLs en indice Bing; ' . number_format_i18n((int) ($crawl['crawled_pages'] ?? 0)) . ' rastreadas en el periodo.';
        if (!empty($snapshot['errors'])) $detail .= ' Algunas metricas no respondieron.';
        return array(
            'label' => 'Bing Webmaster',
            'state' => !empty($snapshot['errors']) ? 'partial' : 'ok',
            'connected' => true,
            'detail' => $detail,
        );
    }
}

if (!function_exists('seo_analista_bing_google_query_compare')) {
    function seo_analista_bing_google_query_compare(array $google_rows, array $bing_rows, $limit = 30) {
        $map = array();
        foreach ($google_rows as $row) {
            $query = trim((string) ($row['query_text'] ?? $row['query'] ?? ''));
            if ($query === '') continue;
            $key = function_exists('seo_analista_normalize_text') ? seo_analista_normalize_text($query) : strtolower($query);
            if ($key === '') continue;
            $map[$key] = array(
                'query' => $query,
                'google_impressions' => (float) ($row['impressions'] ?? 0),
                'google_clicks' => (float) ($row['clicks'] ?? 0),
                'google_position' => (float) ($row['position'] ?? 0),
                'bing_impressions' => 0.0,
                'bing_clicks' => 0.0,
                'bing_position' => 0.0,
            );
        }
        foreach ($bing_rows as $row) {
            $query = trim((string) ($row['query'] ?? $row['value'] ?? ''));
            if ($query === '') continue;
            $key = function_exists('seo_analista_normalize_text') ? seo_analista_normalize_text($query) : strtolower($query);
            if ($key === '') continue;
            if (!isset($map[$key])) {
                $map[$key] = array(
                    'query'=>$query,'google_impressions'=>0.0,'google_clicks'=>0.0,'google_position'=>0.0,
                    'bing_impressions'=>0.0,'bing_clicks'=>0.0,'bing_position'=>0.0,
                );
            }
            $map[$key]['bing_impressions'] = (float) ($row['impressions'] ?? 0);
            $map[$key]['bing_clicks'] = (float) ($row['clicks'] ?? 0);
            $map[$key]['bing_position'] = (float) ($row['position'] ?? 0);
        }
        $out = array_values($map);
        foreach ($out as &$row) {
            $g = (float) $row['google_impressions'];
            $b = (float) $row['bing_impressions'];
            $row['state'] = $g > 0 && $b > 0 ? 'ambos' : ($b > 0 ? 'solo_bing' : 'solo_google');
            $row['combined_impressions'] = $g + $b;
        }
        unset($row);
        usort($out, static function($a, $b) {
            return (float) ($b['combined_impressions'] ?? 0) <=> (float) ($a['combined_impressions'] ?? 0);
        });
        return array_slice($out, 0, max(5, min(100, absint($limit))));
    }
}

if (!function_exists('seo_analista_bing_save_settings_handler')) {
    function seo_analista_bing_save_settings_handler() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos para configurar Bing.');
        check_admin_referer('seo_analista_save_bing_settings', 'seo_analista_bing_nonce');

        $old = seo_analista_bing_get_settings();
        $site_url = isset($_POST['bing_site_url']) ? esc_url_raw((string) wp_unslash($_POST['bing_site_url'])) : home_url('/');
        if ($site_url === '') $site_url = home_url('/');
        $api_key = isset($_POST['bing_api_key']) ? trim((string) wp_unslash($_POST['bing_api_key'])) : '';
        if ($api_key === '') $api_key = (string) ($old['api_key'] ?? '');
        if (!empty($_POST['bing_clear_api_key'])) $api_key = '';

        update_option(SEO_ANALISTA_BING_OPTION, array(
            'enabled' => !empty($_POST['bing_enabled']),
            'site_url' => $site_url,
            'api_key' => sanitize_text_field($api_key),
        ), false);

        wp_safe_redirect(add_query_arg(array(
            'page'=>'seo-reports','tab'=>'analista','analista_view'=>'donde_estamos','analista_notice'=>'bing_saved'
        ), admin_url('admin.php')));
        exit;
    }
}

if (!function_exists('seo_analista_render_bing_settings_form')) {
    function seo_analista_render_bing_settings_form() {
        if (!current_user_can('manage_options')) return;
        $settings = seo_analista_bing_get_settings();
        $configured = seo_analista_bing_api_key() !== '';
        echo '<details class="seo-analista-sources"><summary><strong>Bing Webmaster</strong> · conexion complementaria</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:14px;max-width:780px">';
        echo '<input type="hidden" name="action" value="seo_analista_save_bing_settings">';
        wp_nonce_field('seo_analista_save_bing_settings', 'seo_analista_bing_nonce');
        echo '<p><label><input type="checkbox" name="bing_enabled" value="1" ' . checked(!empty($settings['enabled']), true, false) . '> Activar Bing como fuente secundaria del Analista</label></p>';
        echo '<p><strong>URL verificada en Bing Webmaster</strong><br><input class="regular-text code" type="url" name="bing_site_url" value="' . esc_attr((string) ($settings['site_url'] ?? home_url('/'))) . '"></p>';
        echo '<p><strong>API key de Bing Webmaster</strong><br><input class="regular-text" type="password" autocomplete="new-password" name="bing_api_key" value="" placeholder="' . esc_attr($configured ? 'Clave ya configurada; dejala vacia para conservarla' : 'Pega aqui la API key') . '"></p>';
        if ($configured && !defined('SEO_BING_WEBMASTER_API_KEY')) echo '<p><label><input type="checkbox" name="bing_clear_api_key" value="1"> Eliminar la API key guardada</label></p>';
        if (defined('SEO_BING_WEBMASTER_API_KEY')) echo '<p class="description">La clave se esta leyendo desde SEO_BING_WEBMASTER_API_KEY y no se guarda en Analista.</p>';
        echo '<p class="description">IndexNow no se duplica: Cloudflare sigue siendo el responsable de los envios. Bing se usa aqui solo para lectura y comparacion.</p>';
        submit_button('Guardar Bing', 'secondary', 'submit', false);
        echo '</form></details>';
    }
}
