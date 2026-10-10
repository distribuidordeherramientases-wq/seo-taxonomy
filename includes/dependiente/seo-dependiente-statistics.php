<?php

defined('ABSPATH') || exit;

/**
 * Estadísticas de negocio de Dependiente.
 *
 * Separa este panel del diagnóstico técnico existente:
 * - GA4 responde cuánto tráfico real recibe la página pública.
 * - Search Console responde cómo llega desde Google orgánico.
 * - El log interno resume qué hacen los clientes dentro de Dependiente.
 */
final class SEO_Dependiente_Statistics {
    public static function allowed_periods() {
        return array(7, 30, 90);
    }

    public static function normalize_days($days) {
        $days = absint($days);
        return in_array($days, self::allowed_periods(), true) ? $days : 30;
    }

    public static function render_tab() {
        $days = self::normalize_days($_GET['days'] ?? 28);
        $report = self::collect($days);
        $ga4 = (array) ($report['ga4'] ?? array());
        $gsc = (array) ($report['gsc'] ?? array());
        $internal = (array) ($report['internal'] ?? array());

        echo '<div class="seo-dependiente-admin__toolbar-row">';
        echo '<div class="seo-dependiente-admin__periods" aria-label="Periodo de estadísticas">';
        foreach (self::allowed_periods() as $period) {
            $url = add_query_arg(array(
                'page' => 'seo-dependiente',
                'tab'  => 'statistics',
                'days' => $period,
            ), admin_url('admin.php'));
            echo '<a class="button ' . ($period === $days ? 'button-primary' : '') . '" href="' . esc_url($url) . '">' . esc_html($period) . ' días</a> ';
        }
        echo '</div>';
        echo '<div><strong>Página:</strong> <a href="' . esc_url((string) ($report['url'] ?? '')) . '" target="_blank" rel="noopener">' . esc_html((string) ($report['url'] ?? '')) . '</a></div>';
        echo '</div>';

        echo '<div class="seo-dependiente-admin__metrics">';
        self::metric('Usuarios GA4', $ga4['users'] ?? 0, !empty($ga4['available']) ? '' : 'warning');
        self::metric('Sesiones GA4', $ga4['sessions'] ?? 0, !empty($ga4['available']) ? '' : 'warning');
        self::metric('Vistas GA4', $ga4['pageviews'] ?? 0, !empty($ga4['available']) ? '' : 'warning');
        self::metric('Clics Google', $gsc['clicks'] ?? 0, !empty($gsc['available']) ? '' : 'warning');
        self::metric('Impresiones Google', $gsc['impressions'] ?? 0, !empty($gsc['available']) ? '' : 'warning');
        self::metric('CTR Google', self::percent($gsc['ctr'] ?? 0), '');
        self::metric('Posición media', !empty($gsc['position']) ? number_format_i18n((float) $gsc['position'], 1) : '—', '');
        self::metric('Consultas Dependiente', $internal['searches'] ?? 0, '');
        self::metric('Sin resultado', $internal['zero_results'] ?? 0, !empty($internal['zero_results']) ? 'warning' : '');
        self::metric('Clics en producto', $internal['product_clicks'] ?? 0, '');
        echo '</div>';

        if (empty($ga4['available']) && !empty($ga4['error'])) {
            echo '<div class="notice notice-warning inline"><p><strong>Google Analytics:</strong> ' . esc_html((string) $ga4['error']) . '</p></div>';
        }
        if (empty($gsc['available']) && !empty($gsc['error'])) {
            echo '<div class="notice notice-warning inline"><p><strong>Search Console:</strong> ' . esc_html((string) $gsc['error']) . '</p></div>';
        }

        echo '<div class="seo-dependiente-admin__diagnostic-grid">';
        self::render_list_box(
            'De dónde llegan las visitas',
            (array) ($ga4['sources'] ?? array()),
            'Google Analytics · fuente/medio de las sesiones que vieron la página de Dependiente.'
        );
        self::render_list_box(
            'Cómo encuentran Dependiente en Google',
            (array) ($gsc['queries'] ?? array()),
            'Search Console · consultas que generaron impresiones o clics hacia la página de Dependiente.'
        );
        self::render_list_box(
            'Qué preguntan dentro de Dependiente',
            (array) ($internal['top_queries'] ?? array()),
            'Log interno · consultas escritas por los clientes después de entrar.'
        );
        self::render_list_box(
            'Qué necesitan',
            (array) ($internal['top_intents'] ?? array()),
            'Intenciones detectadas por el Intérprete.'
        );
        self::render_list_box(
            'Sobre qué buscan',
            (array) ($internal['top_objects'] ?? array()),
            'Objetos o familias detectadas en las consultas.'
        );
        echo '</div>';

        echo '<section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">';
        echo '<h2 class="seo-dependiente-admin__box-title">Lectura del embudo</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Etapa</th><th>Métrica</th><th>Qué significa</th></tr></thead><tbody>';
        self::funnel_row('Google', number_format_i18n(absint($gsc['impressions'] ?? 0)) . ' impresiones', 'Veces que la URL de Dependiente apareció en resultados orgánicos.');
        self::funnel_row('Entrada orgánica', number_format_i18n(absint($gsc['clicks'] ?? 0)) . ' clics', 'Visitas procedentes de Search Console; no incluye tráfico directo, redes, email u otras fuentes.');
        self::funnel_row('Página', number_format_i18n(absint($ga4['sessions'] ?? 0)) . ' sesiones · ' . number_format_i18n(absint($ga4['users'] ?? 0)) . ' usuarios', 'Tráfico real de la página según GA4, independientemente de la fuente.');
        self::funnel_row('Uso', number_format_i18n(absint($internal['searches'] ?? 0)) . ' consultas', 'Búsquedas escritas realmente en Dependiente.');
        self::funnel_row('Interés en producto', number_format_i18n(absint($internal['product_clicks'] ?? 0)) . ' clics', 'Consultas que acabaron en clic sobre un producto registrado por el log.');
        echo '</tbody></table>';
        echo '<p class="description">Search Console mide visibilidad y clics desde Google. GA4 mide usuarios, sesiones y vistas de la página. El log interno mide el uso del asistente. No son métricas equivalentes y se muestran separadas deliberadamente.</p>';
        echo '</section>';

        echo '<section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">';
        echo '<h2 class="seo-dependiente-admin__box-title">Qué información ha ofrecido Dependiente</h2>';
        self::render_products_table((array) ($internal['offered_products'] ?? array()));
        echo '<p class="description">Productos que Dependiente ha mostrado con mayor frecuencia en las consultas del periodo. Sirve para detectar qué parte del catálogo está absorbiendo la demanda.</p>';
        echo '</section>';
    }

    public static function collect($days = 30) {
        $days = self::normalize_days($days);
        $page_id = absint(get_option('seo_dependiente_page_id', 0));
        $url = $page_id ? get_permalink($page_id) : home_url('/dependiente/');
        if (!$url) {
            $url = home_url('/dependiente/');
        }

        self::ensure_google_reporting();

        return array(
            'days' => $days,
            'url' => $url,
            'ga4' => self::ga4_page($url, $days),
            'gsc' => self::gsc_page($url, $days),
            'internal' => self::internal_usage($days),
        );
    }

    private static function ga4_page($url, $days) {
        $out = array(
            'available' => false,
            'error' => '',
            'sessions' => 0,
            'users' => 0,
            'pageviews' => 0,
            'sources' => array(),
        );

        if (!function_exists('seo_google_analytics_run_report')) {
            $out['error'] = 'Analytics Data API no está disponible en la conexión Google actual.';
            return $out;
        }

        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        $path = '' === $path ? '/' : '/' . ltrim($path, '/');
        $dates = self::date_range($days);

        $report = seo_google_analytics_run_report(array(
            'dateRanges' => array($dates),
            'dimensions' => array(array('name' => 'pagePath')),
            'metrics' => array(
                array('name' => 'sessions'),
                array('name' => 'activeUsers'),
                array('name' => 'screenPageViews'),
            ),
            'dimensionFilter' => array(
                'filter' => array(
                    'fieldName' => 'pagePath',
                    'stringFilter' => array(
                        'matchType' => 'EXACT',
                        'value' => $path,
                        'caseSensitive' => false,
                    ),
                ),
            ),
            'limit' => 10,
        ));

        if (is_wp_error($report)) {
            $out['error'] = $report->get_error_message();
            return $out;
        }

        $out['available'] = true;
        foreach ((array) ($report['rows'] ?? array()) as $row) {
            $out['sessions'] += absint($row['metricValues'][0]['value'] ?? 0);
            $out['users'] += absint($row['metricValues'][1]['value'] ?? 0);
            $out['pageviews'] += absint($row['metricValues'][2]['value'] ?? 0);
        }

        $sources = seo_google_analytics_run_report(array(
            'dateRanges' => array($dates),
            'dimensions' => array(
                array('name' => 'sessionSourceMedium'),
                array('name' => 'pagePath'),
            ),
            'metrics' => array(
                array('name' => 'sessions'),
                array('name' => 'activeUsers'),
            ),
            'dimensionFilter' => array(
                'filter' => array(
                    'fieldName' => 'pagePath',
                    'stringFilter' => array(
                        'matchType' => 'EXACT',
                        'value' => $path,
                        'caseSensitive' => false,
                    ),
                ),
            ),
            'orderBys' => array(array(
                'metric' => array('metricName' => 'sessions'),
                'desc' => true,
            )),
            'limit' => 12,
        ));
        if (!is_wp_error($sources)) {
            foreach ((array)($sources['rows'] ?? array()) as $row) {
                $label = sanitize_text_field((string)($row['dimensionValues'][0]['value'] ?? ''));
                if ($label === '') continue;
                $out['sources'][] = array(
                    'label' => $label,
                    'count' => absint($row['metricValues'][0]['value'] ?? 0),
                    'detail' => number_format_i18n(absint($row['metricValues'][1]['value'] ?? 0)) . ' usuarios',
                );
            }
        }
        return $out;
    }

    private static function gsc_page($url, $days) {
        $out = array(
            'available' => false,
            'error' => '',
            'clicks' => 0,
            'impressions' => 0,
            'ctr' => 0,
            'position' => 0,
            'queries' => array(),
        );

        if (!function_exists('seo_google_search_console_query')) {
            $out['error'] = 'Search Console no está disponible en la conexión Google actual.';
            return $out;
        }

        $dates = self::date_range($days);
        $filter = array(
            'groupType' => 'and',
            'filters' => array(array(
                'dimension' => 'page',
                'operator' => 'equals',
                'expression' => (string) $url,
            )),
        );

        $summary = seo_google_search_console_query(array(
            'startDate' => $dates['startDate'],
            'endDate' => $dates['endDate'],
            'dimensions' => array(),
            'dimensionFilterGroups' => array($filter),
            'rowLimit' => 10,
        ));
        if (is_wp_error($summary)) {
            $out['error'] = $summary->get_error_message();
            return $out;
        }

        $out['available'] = true;
        foreach ((array) ($summary['rows'] ?? array()) as $row) {
            $out['clicks'] += absint($row['clicks'] ?? 0);
            $out['impressions'] += absint($row['impressions'] ?? 0);
            $out['ctr'] = (float) ($row['ctr'] ?? $out['ctr']);
            $out['position'] = (float) ($row['position'] ?? $out['position']);
        }

        $queries = seo_google_search_console_query(array(
            'startDate' => $dates['startDate'],
            'endDate' => $dates['endDate'],
            'dimensions' => array('query'),
            'dimensionFilterGroups' => array($filter),
            'rowLimit' => 20,
        ));
        if (!is_wp_error($queries)) {
            foreach ((array) ($queries['rows'] ?? array()) as $row) {
                $query = sanitize_text_field((string) ($row['keys'][0] ?? ''));
                if ($query === '') continue;
                $out['queries'][] = array(
                    'label' => $query,
                    'count' => absint($row['clicks'] ?? 0),
                    'detail' => number_format_i18n(absint($row['impressions'] ?? 0)) . ' impresiones · CTR ' . self::percent($row['ctr'] ?? 0),
                );
            }
        }

        return $out;
    }

    private static function internal_usage($days) {
        $out = array(
            'searches' => 0,
            'zero_results' => 0,
            'product_clicks' => 0,
            'top_queries' => array(),
            'top_intents' => array(),
            'top_objects' => array(),
            'offered_products' => array(),
        );

        if (!class_exists('SEO_Dependiente_Insights')) {
            return $out;
        }

        $summary = (array) SEO_Dependiente_Insights::summary($days);
        $out['searches'] = absint($summary['primary_searches'] ?? 0);
        $out['zero_results'] = absint($summary['zero_results'] ?? 0);
        $out['product_clicks'] = absint($summary['product_clicks'] ?? 0);
        $out['top_queries'] = self::normalise_insight_rows(SEO_Dependiente_Insights::top_queries($days, 12));
        $out['top_intents'] = self::normalise_insight_rows(SEO_Dependiente_Insights::top_column('detected_intent', $days, 12));
        $out['top_objects'] = self::normalise_insight_rows(SEO_Dependiente_Insights::top_column('detected_object', $days, 12));
        $out['offered_products'] = SEO_Dependiente_Insights::top_offered_products($days, 20);
        return $out;
    }

    private static function normalise_insight_rows($rows) {
        $out = array();
        foreach ((array) $rows as $row) {
            $label = sanitize_text_field((string) ($row['value'] ?? $row['example'] ?? ''));
            if ($label === '') continue;
            $out[] = array(
                'label' => $label,
                'count' => absint($row['count'] ?? 0),
                'detail' => '',
            );
        }
        return $out;
    }

    private static function date_range($days) {
        if (function_exists('seo_google_reporting_dates')) {
            $dates = (array) seo_google_reporting_dates($days);
            if (!empty($dates['startDate']) && !empty($dates['endDate'])) {
                return array(
                    'startDate' => sanitize_text_field((string)$dates['startDate']),
                    'endDate' => sanitize_text_field((string)$dates['endDate']),
                );
            }
        }

        $end = current_time('timestamp') - DAY_IN_SECONDS;
        $start = $end - (max(1, absint($days)) - 1) * DAY_IN_SECONDS;
        return array(
            'startDate' => wp_date('Y-m-d', $start),
            'endDate' => wp_date('Y-m-d', $end),
        );
    }

    private static function ensure_google_reporting() {
        if (function_exists('seo_google_analytics_run_report') && function_exists('seo_google_search_console_query')) {
            return;
        }
        if (!defined('SEO_SYSTEM_PATH')) {
            return;
        }
        $file = rtrim(SEO_SYSTEM_PATH, '/\\') . '/includes/import-export/suppliers/google-search.php';
        if (is_readable($file)) {
            require_once $file;
        }
    }

    private static function metric($label, $value, $state = '') {
        echo '<div class="seo-dependiente-admin__metric ' . ($state ? 'is-' . esc_attr($state) : '') . '">';
        echo '<span>' . esc_html($label) . '</span><strong>' . esc_html(is_numeric($value) ? number_format_i18n($value) : (string) $value) . '</strong>';
        echo '</div>';
    }

    private static function render_list_box($title, $rows, $description = '') {
        echo '<section class="postbox seo-dependiente-admin__box">';
        echo '<h2 class="seo-dependiente-admin__box-title">' . esc_html($title) . '</h2>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        if (!$rows) {
            echo '<p>Sin datos en este periodo.</p></section>';
            return;
        }
        echo '<ol>';
        foreach (array_slice((array) $rows, 0, 12) as $row) {
            echo '<li><strong>' . esc_html((string) ($row['label'] ?? '')) . '</strong>';
            if (isset($row['count'])) {
                echo ' · ' . esc_html(number_format_i18n(absint($row['count'])));
            }
            if (!empty($row['detail'])) {
                echo '<br><span class="description">' . esc_html((string) $row['detail']) . '</span>';
            }
            echo '</li>';
        }
        echo '</ol></section>';
    }

    private static function render_products_table($rows) {
        if (!$rows) {
            echo '<p>Sin productos ofrecidos registrados en este periodo.</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Producto</th><th>Veces mostrado</th><th>Posición media</th><th>Clics</th></tr></thead><tbody>';
        foreach (array_slice((array) $rows, 0, 20) as $row) {
            $id = absint($row['id'] ?? 0);
            $title = sanitize_text_field((string) ($row['title'] ?? ''));
            $url = $id ? get_permalink($id) : '';
            echo '<tr><td>';
            if ($url) {
                echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($title ?: ('Producto #' . $id)) . '</a>';
            } else {
                echo esc_html($title ?: ('Producto #' . $id));
            }
            echo '</td><td>' . esc_html(number_format_i18n(absint($row['appearances'] ?? 0))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) ($row['average_position'] ?? 0), 1)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(absint($row['clicks'] ?? 0))) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function funnel_row($stage, $metric, $meaning) {
        echo '<tr><td><strong>' . esc_html($stage) . '</strong></td><td>' . esc_html($metric) . '</td><td>' . esc_html($meaning) . '</td></tr>';
    }

    private static function percent($ratio) {
        return number_format_i18n(max(0, (float) $ratio) * 100, 1) . '%';
    }
}
