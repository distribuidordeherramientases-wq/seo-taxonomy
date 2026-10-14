<?php
/**
 * Informes Google por categoria de producto.
 *
 * Reutiliza la conexion centralizada de Google Search Console / Analytics
 * definida por el plugin. No guarda ni expone credenciales.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_category_reports_query_arg')) {
    function seo_category_reports_query_arg($key, $default = '') {
        $key = sanitize_key((string) $key);
        if ('' === $key) return $default;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filtros y navegación GET de solo lectura.
        if (!isset($_GET[$key])) return $default;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filtros y navegación GET de solo lectura.
        return wp_unslash($_GET[$key]);
    }
}


if (!function_exists('seo_category_reports_enqueue_assets')) {
    function seo_category_reports_enqueue_assets() {
        $page = sanitize_key((string) seo_category_reports_query_arg('page', ''));
        if ('category-seo-admin' !== $page) {
            return;
        }

        wp_enqueue_style(
            'taxocommerce-seo-category-reports',
            SEO_SYSTEM_URL . 'includes/assets/seo-category-reports.css',
            array(),
            defined('SEO_SYSTEM_VERSION') ? SEO_SYSTEM_VERSION : null
        );
    }
}
add_action('admin_enqueue_scripts', 'seo_category_reports_enqueue_assets');


if (!function_exists('seo_category_reports_days')) {
    function seo_category_reports_days($value) {
        $value = absint($value);
        return in_array($value, [7, 28, 90], true) ? $value : 28;
    }
}

if (!function_exists('seo_category_reports_path_key')) {
    function seo_category_reports_path_key($url_or_path) {
        $path = (string) wp_parse_url((string) $url_or_path, PHP_URL_PATH);
        if ('' === $path) {
            $path = (string) $url_or_path;
        }

        $path = rawurldecode($path);
        $path = '/' . ltrim($path, '/');
        return untrailingslashit($path) ?: '/';
    }
}

if (!function_exists('seo_category_reports_google_state')) {
    function seo_category_reports_google_state() {
        $state = [
            'service_loaded'     => function_exists('seo_google_search_settings'),
            'search_console'     => false,
            'analytics'          => false,
            'service_account'    => false,
            'tracking_enabled'   => false,
            'measurement_id'     => '',
            'analytics_property' => '',
            'search_console_url' => '',
        ];

        if (!$state['service_loaded']) {
            return $state;
        }

        $settings = seo_google_search_settings();
        $state['search_console_url'] = trim((string) ($settings['search_console_site_url'] ?? ''));
        $state['analytics_property'] = preg_replace('/\D+/', '', (string) ($settings['analytics_property_id'] ?? ''));
        $state['measurement_id'] = function_exists('seo_google_search_measurement_id')
            ? (string) seo_google_search_measurement_id()
            : '';
        $state['tracking_enabled'] = !empty($settings['tracking_enabled']);
        $state['service_account'] = '' !== trim((string) ($settings['service_account_json'] ?? ''));
        $state['search_console'] = '' !== $state['search_console_url'] && $state['service_account'];
        $state['analytics'] = '' !== $state['analytics_property'] && $state['service_account'];

        return $state;
    }
}

if (!function_exists('seo_category_reports_gsc_request')) {
    function seo_category_reports_gsc_request($url, $days, array $dimensions = [], $row_limit = 1000) {
        if (!function_exists('seo_google_search_console_query')) {
            return new WP_Error('seo_category_reports_gsc_unavailable', 'Search Console no esta disponible en la conexion Google actual.');
        }

        $days = seo_category_reports_days($days);
        $period = seo_google_reporting_dates($days);

        $request = [
            'startDate' => $period['startDate'],
            'endDate'   => $period['endDate'],
            'rowLimit'  => min(25000, max(1, absint($row_limit))),
            'dataState' => 'final',
            'aggregationType' => 'byPage',
            'dimensionFilterGroups' => [
                [
                    'groupType' => 'and',
                    'filters'   => [
                        [
                            'dimension'  => 'page',
                            'operator'   => 'equals',
                            'expression' => (string) $url,
                        ],
                    ],
                ],
            ],
            'dimensions' => array_values($dimensions),
        ];

        return seo_google_search_console_query($request);
    }
}

if (!function_exists('seo_category_reports_gsc_summary')) {
    function seo_category_reports_gsc_summary($url, $days) {
        $result = [
            'available'   => false,
            'error'       => '',
            'clicks'      => 0,
            'impressions' => 0,
            'ctr'         => 0.0,
            'position'    => 0.0,
        ];

        $report = seo_category_reports_gsc_request($url, $days, [], 1);
        if (is_wp_error($report)) {
            $result['error'] = $report->get_error_message();
            return $result;
        }

        $result['available'] = true;
        $row = !empty($report['rows'][0]) && is_array($report['rows'][0]) ? $report['rows'][0] : [];
        $result['clicks'] = (int) ($row['clicks'] ?? 0);
        $result['impressions'] = (int) ($row['impressions'] ?? 0);
        $result['ctr'] = (float) ($row['ctr'] ?? 0);
        $result['position'] = (float) ($row['position'] ?? 0);

        return $result;
    }
}

if (!function_exists('seo_category_reports_gsc_queries')) {
    function seo_category_reports_gsc_queries($url, $days) {
        $result = [
            'available' => false,
            'error'     => '',
            'rows'      => [],
        ];

        $report = seo_category_reports_gsc_request($url, $days, ['query'], 100);
        if (is_wp_error($report)) {
            $result['error'] = $report->get_error_message();
            return $result;
        }

        $result['available'] = true;
        foreach ((array) ($report['rows'] ?? []) as $row) {
            $query = sanitize_text_field((string) ($row['keys'][0] ?? ''));
            if ('' === $query) {
                continue;
            }

            $result['rows'][] = [
                'query'       => $query,
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr'         => (float) ($row['ctr'] ?? 0),
                'position'    => (float) ($row['position'] ?? 0),
            ];
        }

        return $result;
    }
}

if (!function_exists('seo_category_reports_gsc_daily')) {
    function seo_category_reports_gsc_daily($url, $days) {
        $result = [
            'available' => false,
            'error'     => '',
            'rows'      => [],
        ];

        $report = seo_category_reports_gsc_request($url, $days, ['date'], 500);
        if (is_wp_error($report)) {
            $result['error'] = $report->get_error_message();
            return $result;
        }

        $result['available'] = true;
        foreach ((array) ($report['rows'] ?? []) as $row) {
            $date = sanitize_text_field((string) ($row['keys'][0] ?? ''));
            if ('' === $date) {
                continue;
            }

            $result['rows'][$date] = [
                'date'        => $date,
                'clicks'      => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr'         => (float) ($row['ctr'] ?? 0),
                'position'    => (float) ($row['position'] ?? 0),
            ];
        }

        ksort($result['rows']);
        $result['rows'] = array_values($result['rows']);
        return $result;
    }
}

if (!function_exists('seo_category_reports_ga_page')) {
    function seo_category_reports_ga_page($url, $days) {
        $result = [
            'available' => false,
            'error'     => '',
            'sessions'  => 0,
            'users'     => 0,
            'pageviews' => 0,
        ];

        if (!function_exists('seo_google_analytics_run_report')) {
            $result['error'] = 'Analytics Data API no esta disponible en la conexion Google actual.';
            return $result;
        }

        $days = seo_category_reports_days($days);
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        $path = '' === $path ? '/' : '/' . ltrim($path, '/');

        $report = seo_google_analytics_run_report([
            'dateRanges' => [seo_google_reporting_dates($days)],
            'dimensions' => [
                ['name' => 'pagePath'],
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'activeUsers'],
                ['name' => 'screenPageViews'],
            ],
            'dimensionFilter' => [
                'filter' => [
                    'fieldName' => 'pagePath',
                    'stringFilter' => [
                        'matchType'     => 'EXACT',
                        'value'         => $path,
                        'caseSensitive' => false,
                    ],
                ],
            ],
            'limit' => 10,
        ]);

        if (is_wp_error($report)) {
            $result['error'] = $report->get_error_message();
            return $result;
        }

        $result['available'] = true;
        foreach ((array) ($report['rows'] ?? []) as $row) {
            $result['sessions'] += (int) ($row['metricValues'][0]['value'] ?? 0);
            $result['users'] += (int) ($row['metricValues'][1]['value'] ?? 0);
            $result['pageviews'] += (int) ($row['metricValues'][2]['value'] ?? 0);
        }

        return $result;
    }
}

if (!function_exists('seo_category_reports_collect')) {
    function seo_category_reports_collect($term_id, $days = 28, $force = false) {
        $term_id = absint($term_id);
        $days = seo_category_reports_days($days);
        $term = get_term($term_id, 'product_cat');

        if (!$term || is_wp_error($term)) {
            return new WP_Error('seo_category_reports_invalid_category', 'La categoria solicitada no existe.');
        }

        $url = get_term_link($term);
        if (is_wp_error($url) || !$url) {
            return new WP_Error('seo_category_reports_no_permalink', 'No se pudo resolver la URL publica de la categoria.');
        }

        $cache_key = 'seo_category_google_report_v1_' . get_current_blog_id() . '_' . $term_id . '_' . $days;
        if ($force) {
            delete_transient($cache_key);
        } else {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = [
            'term_id'    => $term_id,
            'days'       => $days,
            'url'        => $url,
            'generated'  => current_time('timestamp'),
            'google'     => seo_category_reports_google_state(),
            'gsc'        => seo_category_reports_gsc_summary($url, $days),
            'queries'    => seo_category_reports_gsc_queries($url, $days),
            'daily'      => seo_category_reports_gsc_daily($url, $days),
            'ga4'        => seo_category_reports_ga_page($url, $days),
        ];

        set_transient($cache_key, $data, 15 * MINUTE_IN_SECONDS);
        return $data;
    }
}

if (!function_exists('seo_category_reports_percent')) {
    function seo_category_reports_percent($ratio, $decimals = 1) {
        return number_format_i18n((float) $ratio * 100, absint($decimals)) . '%';
    }
}

if (!function_exists('seo_category_reports_admin_url')) {
    function seo_category_reports_admin_url($term_id = 0, $days = 28, array $extra = [], $page_slug = '') {
        if ('' === $page_slug) {
            $page_slug = sanitize_key((string) seo_category_reports_query_arg('page', 'category-seo-admin'));
        }

        $central = sanitize_key($page_slug) === 'seo-solucionador';
        $args = [
            'page' => sanitize_key($page_slug),
            'tab'  => $central ? 'diagnostics' : 'informes',
            'days' => seo_category_reports_days($days),
        ];
        if ($central) {
            $args['diag_scope'] = 'categories';
            $args['diag_view'] = 'google';
        }

        if (absint($term_id) > 0) {
            $args['report_category_id'] = absint($term_id);
        }

        return add_query_arg(array_merge($args, $extra), admin_url('admin.php'));
    }
}

if (!function_exists('seo_category_reports_success_label')) {
    function seo_category_reports_success_label(array $report) {
        $clicks = (int) ($report['gsc']['clicks'] ?? 0);
        $impressions = (int) ($report['gsc']['impressions'] ?? 0);
        $pageviews = (int) ($report['ga4']['pageviews'] ?? 0);
        $ctr = (float) ($report['gsc']['ctr'] ?? 0);

        if ($clicks > 0 && $pageviews > 0) {
            return ['label' => 'Genera trafico organico', 'detail' => 'Google Search envia usuarios a esta categoria y GA4 registra actividad en la pagina.', 'class' => 'is-ok'];
        }
        if ($clicks > 0) {
            return ['label' => 'Recibe clics desde Google', 'detail' => 'Search Console registra clics organicos hacia esta categoria.', 'class' => 'is-ok'];
        }
        if ($impressions > 0 && $ctr <= 0.0) {
            return ['label' => 'Visible sin clics', 'detail' => 'La categoria aparece en Google, pero no recibe clics en el periodo.', 'class' => 'is-pending'];
        }
        if ($impressions > 0) {
            return ['label' => 'Tiene visibilidad', 'detail' => 'Search Console registra impresiones para esta categoria.', 'class' => 'is-pending'];
        }
        if ($pageviews > 0) {
            return ['label' => 'Tiene visitas', 'detail' => 'GA4 registra visitas, aunque Search Console no atribuye visibilidad organica en el periodo.', 'class' => 'is-pending'];
        }
        return ['label' => 'Sin senales', 'detail' => 'No hay impresiones, clics ni visitas registradas para esta categoria en el periodo.', 'class' => 'is-pending'];
    }
}

if (!function_exists('seo_category_reports_summary_meta_key')) {
    function seo_category_reports_summary_meta_key($field, $days = 28) {
        $allowed = ['score', 'impressions', 'clicks', 'ctr', 'position', 'pageviews', 'sessions', 'updated'];
        $field = sanitize_key((string) $field);
        if (!in_array($field, $allowed, true)) {
            $field = 'score';
        }

        return '_seo_google_category_' . $field . '_' . seo_category_reports_days($days);
    }
}

if (!function_exists('seo_category_reports_get_summary')) {
    function seo_category_reports_get_summary($term_id, $days = 28) {
        $term_id = absint($term_id);
        $days = seo_category_reports_days($days);
        $score_key = seo_category_reports_summary_meta_key('score', $days);
        $has_snapshot = $term_id > 0 && metadata_exists('term', $term_id, $score_key);

        return [
            'has_snapshot' => $has_snapshot,
            'score'        => $has_snapshot ? max(0, min(100, absint(get_term_meta($term_id, $score_key, true)))) : 0,
            'impressions'  => max(0, (int) get_term_meta($term_id, seo_category_reports_summary_meta_key('impressions', $days), true)),
            'clicks'       => max(0, (int) get_term_meta($term_id, seo_category_reports_summary_meta_key('clicks', $days), true)),
            'ctr'          => max(0.0, (float) get_term_meta($term_id, seo_category_reports_summary_meta_key('ctr', $days), true)),
            'position'     => max(0.0, (float) get_term_meta($term_id, seo_category_reports_summary_meta_key('position', $days), true)),
            'pageviews'    => max(0, (int) get_term_meta($term_id, seo_category_reports_summary_meta_key('pageviews', $days), true)),
            'sessions'     => max(0, (int) get_term_meta($term_id, seo_category_reports_summary_meta_key('sessions', $days), true)),
            'updated'      => max(0, (int) get_term_meta($term_id, seo_category_reports_summary_meta_key('updated', $days), true)),
        ];
    }
}

if (!function_exists('seo_category_reports_category_url_map')) {
    function seo_category_reports_category_url_map() {
        static $map = null;

        if (is_array($map)) {
            return $map;
        }

        $map = [];
        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);

        if (is_wp_error($terms)) {
            return $map;
        }

        foreach ($terms as $term) {
            $url = get_term_link($term);
            if (is_wp_error($url) || !$url) {
                continue;
            }
            $map[seo_category_reports_path_key($url)] = absint($term->term_id);
        }

        return $map;
    }
}

if (!function_exists('seo_category_reports_resolve_category_url')) {
    function seo_category_reports_resolve_category_url($url_or_path) {
        $path = seo_category_reports_path_key($url_or_path);
        $map = seo_category_reports_category_url_map();
        return isset($map[$path]) ? absint($map[$path]) : 0;
    }
}

if (!function_exists('seo_category_reports_catalog_snapshot')) {
    /**
     * Snapshot agregado para todas las categorias. Hace una consulta a Search
     * Console por pagina y una consulta GA4 por pagePath. Despues cruza solo
     * las URLs que pertenecen a product_cat y guarda metadatos derivados.
     */
    function seo_category_reports_catalog_snapshot($days = 28, $force = false) {
        global $wpdb;

        $days = seo_category_reports_days($days);
        $cache_key = 'seo_category_google_catalog_v2_' . get_current_blog_id() . '_' . $days;

        if ($force) {
            delete_transient($cache_key);
        } else {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $google = seo_category_reports_google_state();
        $snapshot = [
            'available'          => false,
            'generated'          => current_time('timestamp'),
            'days'               => $days,
            'matched_categories' => 0,
            'scored_categories'  => 0,
            'errors'             => [],
            'sources'            => [
                'gsc' => false,
                'ga4' => false,
            ],
            'daily'              => [],
        ];

        $summaries = [];
        $blank = static function () {
            return [
                'impressions'      => 0,
                'clicks'           => 0,
                'pageviews'        => 0,
                'sessions'         => 0,
                'position_weight'  => 0.0,
                'position_impr'    => 0,
            ];
        };
        $ensure = static function ($term_id) use (&$summaries, $blank) {
            $term_id = absint($term_id);
            if ($term_id <= 0) {
                return 0;
            }
            if (!isset($summaries[$term_id])) {
                $summaries[$term_id] = $blank();
            }
            return $term_id;
        };

        // Build the URL map once before processing external rows.
        seo_category_reports_category_url_map();

        $period = seo_google_reporting_dates($days);

        if (!empty($google['search_console']) && function_exists('seo_google_search_console_query')) {
            $gsc = seo_google_search_console_query([
                'startDate'  => $period['startDate'],
                'endDate'    => $period['endDate'],
                'dimensions' => ['page'],
                'aggregationType' => 'byPage',
                'rowLimit'   => 25000,
                'dataState'  => 'final',
            ]);

            if (is_wp_error($gsc)) {
                $snapshot['errors'][] = 'Search Console: ' . $gsc->get_error_message();
            } else {
                $snapshot['available'] = true;
                $snapshot['sources']['gsc'] = true;

                foreach ((array) ($gsc['rows'] ?? []) as $row) {
                    $term_id = seo_category_reports_resolve_category_url((string) ($row['keys'][0] ?? ''));
                    $term_id = $ensure($term_id);
                    if ($term_id <= 0) {
                        continue;
                    }

                    $impressions = max(0, (int) ($row['impressions'] ?? 0));
                    $clicks = max(0, (int) ($row['clicks'] ?? 0));
                    $position = max(0.0, (float) ($row['position'] ?? 0));

                    $summaries[$term_id]['impressions'] += $impressions;
                    $summaries[$term_id]['clicks'] += $clicks;
                    if ($impressions > 0 && $position > 0) {
                        $summaries[$term_id]['position_weight'] += $position * $impressions;
                        $summaries[$term_id]['position_impr'] += $impressions;
                    }
                }
            }
        }

        if (!empty($google['analytics']) && function_exists('seo_google_analytics_run_report')) {
            $ga = seo_google_analytics_run_report([
                'dateRanges' => [seo_google_reporting_dates($days)],
                'dimensions' => [
                    ['name' => 'pagePath'],
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'screenPageViews'],
                ],
                'limit' => 100000,
            ]);

            if (is_wp_error($ga)) {
                $snapshot['errors'][] = 'Analytics: ' . $ga->get_error_message();
            } else {
                $snapshot['available'] = true;
                $snapshot['sources']['ga4'] = true;

                foreach ((array) ($ga['rows'] ?? []) as $row) {
                    $term_id = seo_category_reports_resolve_category_url((string) ($row['dimensionValues'][0]['value'] ?? ''));
                    $term_id = $ensure($term_id);
                    if ($term_id <= 0) {
                        continue;
                    }

                    $summaries[$term_id]['sessions'] += max(0, (int) ($row['metricValues'][0]['value'] ?? 0));
                    $summaries[$term_id]['pageviews'] += max(0, (int) ($row['metricValues'][1]['value'] ?? 0));
                }
            }
        }

        // Serie diaria separada: mantiene intacta la consulta agregada anterior
        // aunque el periodo tenga muchas combinaciones pagina x fecha.
        if (!empty($snapshot['sources']['ga4']) && function_exists('seo_google_analytics_run_report')) {
            $ga_daily = seo_google_analytics_run_report([
                'dateRanges' => [seo_google_reporting_dates($days)],
                'dimensions' => [
                    ['name' => 'pagePath'],
                    ['name' => 'date'],
                ],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'screenPageViews'],
                ],
                'limit' => 100000,
            ]);

            if (is_wp_error($ga_daily)) {
                $snapshot['errors'][] = 'Analytics diario: ' . $ga_daily->get_error_message();
            } else {
                foreach ((array) ($ga_daily['rows'] ?? []) as $row) {
                    $term_id = seo_category_reports_resolve_category_url((string) ($row['dimensionValues'][0]['value'] ?? ''));
                    if ($term_id <= 0) {
                        continue;
                    }

                    $raw_date = preg_replace('/\D+/', '', (string) ($row['dimensionValues'][1]['value'] ?? ''));
                    if (8 !== strlen($raw_date)) {
                        continue;
                    }

                    $date = substr($raw_date, 0, 4) . '-' . substr($raw_date, 4, 2) . '-' . substr($raw_date, 6, 2);
                    if (!isset($snapshot['daily'][$date])) {
                        $snapshot['daily'][$date] = [
                            'date'      => $date,
                            'sessions'  => 0,
                            'pageviews' => 0,
                        ];
                    }
                    $snapshot['daily'][$date]['sessions'] += max(0, (int) ($row['metricValues'][0]['value'] ?? 0));
                    $snapshot['daily'][$date]['pageviews'] += max(0, (int) ($row['metricValues'][1]['value'] ?? 0));
                }
            }
        }

        if (!empty($snapshot['sources']['ga4'])) {
            $daily = [];
            for ($offset = 0; $offset < $days; $offset++) {
                $date = gmdate('Y-m-d', strtotime('+' . $offset . ' days', strtotime($period['startDate'])));
                $daily[] = isset($snapshot['daily'][$date])
                    ? $snapshot['daily'][$date]
                    : [
                        'date'      => $date,
                        'sessions'  => 0,
                        'pageviews' => 0,
                    ];
            }
            $snapshot['daily'] = $daily;
        } else {
            $snapshot['daily'] = [];
        }

        $snapshot['matched_categories'] = count($summaries);

        if ($snapshot['available']) {
            $maxima = [
                'impressions' => 0,
                'clicks'      => 0,
                'pageviews'   => 0,
            ];

            foreach ($summaries as $summary) {
                foreach ($maxima as $metric => $unused) {
                    $maxima[$metric] = max($maxima[$metric], (float) ($summary[$metric] ?? 0));
                }
            }

            // Clicks carry the greatest weight because the report is intended
            // to rank real organic success, not only visibility.
            $weights = [
                'impressions' => 20,
                'clicks'      => 50,
                'pageviews'   => 30,
            ];
            $active_weight = 0;
            foreach ($weights as $metric => $weight) {
                if ($maxima[$metric] > 0) {
                    $active_weight += $weight;
                }
            }

            $meta_fields = ['score', 'impressions', 'clicks', 'ctr', 'position', 'pageviews', 'sessions', 'updated'];
            foreach ($meta_fields as $field) {
                $meta_key = seo_category_reports_summary_meta_key($field, $days);
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE tm FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tt.taxonomy = 'product_cat' AND tm.meta_key = %s",
                        $meta_key
                    )
                );
            }

            foreach ($summaries as $term_id => $summary) {
                $score = 0;
                if ($active_weight > 0) {
                    $points = 0.0;
                    foreach ($weights as $metric => $weight) {
                        $max_value = (float) $maxima[$metric];
                        if ($max_value <= 0) {
                            continue;
                        }
                        $value = max(0.0, (float) ($summary[$metric] ?? 0));
                        $normalized = log(1 + $value) / log(1 + $max_value);
                        $points += $weight * max(0.0, min(1.0, $normalized));
                    }
                    $score = (int) round(($points / $active_weight) * 100);
                }

                $impressions = (int) $summary['impressions'];
                $clicks = (int) $summary['clicks'];
                $ctr = $impressions > 0 ? $clicks / $impressions : 0.0;
                $position = (int) $summary['position_impr'] > 0
                    ? (float) $summary['position_weight'] / (int) $summary['position_impr']
                    : 0.0;

                update_term_meta($term_id, seo_category_reports_summary_meta_key('score', $days), max(0, min(100, $score)));
                update_term_meta($term_id, seo_category_reports_summary_meta_key('impressions', $days), $impressions);
                update_term_meta($term_id, seo_category_reports_summary_meta_key('clicks', $days), $clicks);
                update_term_meta($term_id, seo_category_reports_summary_meta_key('ctr', $days), $ctr);
                update_term_meta($term_id, seo_category_reports_summary_meta_key('position', $days), $position);
                update_term_meta($term_id, seo_category_reports_summary_meta_key('pageviews', $days), (int) $summary['pageviews']);
                update_term_meta($term_id, seo_category_reports_summary_meta_key('sessions', $days), (int) $summary['sessions']);
                update_term_meta($term_id, seo_category_reports_summary_meta_key('updated', $days), (int) $snapshot['generated']);

                if ($score > 0) {
                    $snapshot['scored_categories']++;
                }
            }
        }

        set_transient(
            $cache_key,
            $snapshot,
            $snapshot['available'] ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS
        );

        return $snapshot;
    }
}

if (!function_exists('seo_category_reports_render_metric')) {
    function seo_category_reports_render_metric($label, $value, $detail = '') {
        echo '<div class="seo-category-report-metric">';
        echo '<span class="seo-category-report-metric-label">' . esc_html($label) . '</span>';
        echo '<strong>' . wp_kses_post((string) $value) . '</strong>';
        if ('' !== $detail) {
            echo '<span class="seo-category-report-metric-detail">' . esc_html($detail) . '</span>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_category_reports_render_status')) {
    function seo_category_reports_render_status(array $google) {
        echo '<div class="seo-category-report-source-row">';

        $sources = [
            'Search Console' => !empty($google['search_console']),
            'Analytics Data API' => !empty($google['analytics']),
            'GA4 frontend' => !empty($google['tracking_enabled']) && !empty($google['measurement_id']),
        ];

        foreach ($sources as $label => $ok) {
            $class = $ok ? 'is-ok' : 'is-pending';
            $text = $ok ? 'Disponible' : 'Pendiente';
            echo '<span class="seo-category-report-badge ' . esc_attr($class) . '"><strong>' . esc_html($label) . ':</strong> ' . esc_html($text) . '</span>';
        }

        echo '</div>';
    }
}

if (!function_exists('seo_category_reports_render_daily_chart')) {
    function seo_category_reports_render_daily_chart(array $rows) {
        if (empty($rows)) {
            echo '<p class="description">Search Console no ha devuelto actividad diaria para esta categoria en el periodo seleccionado.</p>';
            return;
        }

        $max_impressions = 1;
        foreach ($rows as $row) {
            $max_impressions = max($max_impressions, (int) ($row['impressions'] ?? 0));
        }

        echo '<div class="seo-category-report-bars" role="img" aria-label="Evolucion diaria de impresiones y clics">';
        foreach ($rows as $row) {
            $impressions = (int) ($row['impressions'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);
            $height = max(2, min(100, ($impressions / $max_impressions) * 100));
            $date = (string) ($row['date'] ?? '');
            $title = sprintf('%s - %d impresiones - %d clics', $date, $impressions, $clicks);
            echo '<div class="seo-category-report-bar-col" title="' . esc_attr($title) . '">';
            echo '<div class="seo-category-report-bar" style="height:' . esc_attr((string) $height) . '%"></div>';
            echo '<span>' . esc_html(substr($date, 5)) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }
}


if (!function_exists('seo_category_reports_dashboard_data')) {
    /**
     * Resume el rendimiento de todas las categorias usando exclusivamente el
     * snapshot agregado ya guardado en termmeta. No dispara llamadas extra a
     * Google al pintar el dashboard.
     */
    function seo_category_reports_dashboard_data(array $terms, $days, array $snapshot) {
        $days = seo_category_reports_days($days);
        $data = [
            'pageviews'             => 0,
            'sessions'              => 0,
            'clicks'                => 0,
            'impressions'           => 0,
            'ctr'                   => 0.0,
            'average_score'         => 0,
            'categories_total'      => count($terms),
            'categories_signals'    => 0,
            'categories_with_visits'=> 0,
            'categories_high'       => 0,
            'distribution'          => [
                'high'    => 0,
                'medium'  => 0,
                'low'     => 0,
                'no_data' => 0,
            ],
            'top'                   => [],
            'daily'                 => !empty($snapshot['daily']) && is_array($snapshot['daily']) ? array_values($snapshot['daily']) : [],
        ];

        $score_sum = 0;
        foreach ($terms as $term) {
            if (!is_object($term) || empty($term->term_id)) {
                continue;
            }

            $summary = seo_category_reports_get_summary($term->term_id, $days);
            $score = max(0, min(100, absint($summary['score'] ?? 0)));
            $impressions = max(0, (int) ($summary['impressions'] ?? 0));
            $clicks = max(0, (int) ($summary['clicks'] ?? 0));
            $pageviews = max(0, (int) ($summary['pageviews'] ?? 0));
            $sessions = max(0, (int) ($summary['sessions'] ?? 0));

            $data['impressions'] += $impressions;
            $data['clicks'] += $clicks;
            $data['pageviews'] += $pageviews;
            $data['sessions'] += $sessions;

            if ($pageviews > 0) {
                $data['categories_with_visits']++;
            }

            if (!empty($summary['has_snapshot'])) {
                $data['categories_signals']++;
                $score_sum += $score;
                if ($score >= 70) {
                    $data['categories_high']++;
                    $data['distribution']['high']++;
                } elseif ($score >= 40) {
                    $data['distribution']['medium']++;
                } else {
                    $data['distribution']['low']++;
                }
            } else {
                $data['distribution']['no_data']++;
            }

            if ($pageviews > 0 || $clicks > 0 || $impressions > 0) {
                $data['top'][] = [
                    'term_id'     => absint($term->term_id),
                    'name'        => (string) $term->name,
                    'pageviews'   => $pageviews,
                    'sessions'    => $sessions,
                    'clicks'      => $clicks,
                    'impressions' => $impressions,
                    'score'       => $score,
                    'has_snapshot'=> !empty($summary['has_snapshot']),
                ];
            }
        }

        $data['ctr'] = $data['impressions'] > 0 ? $data['clicks'] / $data['impressions'] : 0.0;
        $data['average_score'] = $data['categories_signals'] > 0
            ? (int) round($score_sum / $data['categories_signals'])
            : 0;

        usort($data['top'], static function ($left, $right) {
            $pageviews_cmp = ((int) $right['pageviews']) <=> ((int) $left['pageviews']);
            if (0 !== $pageviews_cmp) {
                return $pageviews_cmp;
            }
            $score_cmp = ((int) $right['score']) <=> ((int) $left['score']);
            if (0 !== $score_cmp) {
                return $score_cmp;
            }
            return strcasecmp((string) $left['name'], (string) $right['name']);
        });
        $data['top'] = array_slice($data['top'], 0, 10);

        return $data;
    }
}

if (!function_exists('seo_category_reports_render_overview_line_chart')) {
    function seo_category_reports_render_overview_line_chart(array $rows) {
        if (empty($rows)) {
            echo '<p class="description">Analytics no ha devuelto una serie diaria para las paginas de categoria.</p>';
            return;
        }

        $values = [];
        foreach ($rows as $row) {
            $values[] = max(0, (int) ($row['pageviews'] ?? 0));
        }
        $max_value = max($values ?: [0]);
        if ($max_value <= 0) {
            echo '<div class="seo-category-overview-empty">No hay vistas de paginas de categoria registradas en GA4 durante este periodo.</div>';
            return;
        }

        $width = 920;
        $height = 245;
        $left = 58;
        $right = 18;
        $top = 18;
        $bottom = 38;
        $plot_width = $width - $left - $right;
        $plot_height = $height - $top - $bottom;
        $count = count($rows);
        $x_step = $count > 1 ? $plot_width / ($count - 1) : 0;
        $points = [];

        foreach ($rows as $index => $row) {
            $value = max(0, (int) ($row['pageviews'] ?? 0));
            $x = $left + ($index * $x_step);
            $y = $top + $plot_height - (($value / $max_value) * $plot_height);
            $points[] = round($x, 2) . ',' . round($y, 2);
        }

        echo '<div class="seo-category-overview-line-wrap">';
        echo '<svg class="seo-category-overview-line" viewBox="0 0 ' . esc_attr((string) $width) . ' ' . esc_attr((string) $height) . '" role="img" aria-label="Evolucion diaria de las vistas de todas las categorias">';

        for ($grid = 0; $grid <= 4; $grid++) {
            $ratio = $grid / 4;
            $y = $top + ($ratio * $plot_height);
            $label = (int) round($max_value * (1 - $ratio));
            echo '<line x1="' . esc_attr((string) $left) . '" y1="' . esc_attr((string) round($y, 2)) . '" x2="' . esc_attr((string) ($width - $right)) . '" y2="' . esc_attr((string) round($y, 2)) . '" class="seo-category-overview-grid"></line>';
            echo '<text x="' . esc_attr((string) ($left - 9)) . '" y="' . esc_attr((string) round($y + 4, 2)) . '" text-anchor="end" class="seo-category-overview-axis">' . esc_html(number_format_i18n($label)) . '</text>';
        }

        echo '<polyline points="' . esc_attr(implode(' ', $points)) . '" class="seo-category-overview-polyline"></polyline>';

        $tick_count = min(6, $count);
        $tick_indexes = [];
        for ($tick = 0; $tick < $tick_count; $tick++) {
            $index = $tick_count > 1 ? (int) round(($tick / ($tick_count - 1)) * ($count - 1)) : 0;
            $tick_indexes[$index] = true;
        }

        foreach ($rows as $index => $row) {
            $value = max(0, (int) ($row['pageviews'] ?? 0));
            $x = $left + ($index * $x_step);
            $y = $top + $plot_height - (($value / $max_value) * $plot_height);
            $date = sanitize_text_field((string) ($row['date'] ?? ''));
            $date_label = $date;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $timestamp = strtotime($date . ' 12:00:00');
                if ($timestamp) {
                    $date_label = wp_date('d/m', $timestamp);
                }
            }

            echo '<circle cx="' . esc_attr((string) round($x, 2)) . '" cy="' . esc_attr((string) round($y, 2)) . '" r="' . esc_attr($count <= 31 ? '3' : '2') . '" class="seo-category-overview-dot"><title>' . esc_html($date_label . ': ' . number_format_i18n($value) . ' vistas') . '</title></circle>';
            if (isset($tick_indexes[$index])) {
                echo '<text x="' . esc_attr((string) round($x, 2)) . '" y="' . esc_attr((string) ($height - 12)) . '" text-anchor="middle" class="seo-category-overview-axis">' . esc_html($date_label) . '</text>';
            }
        }

        echo '</svg>';
        echo '</div>';
    }
}

if (!function_exists('seo_category_reports_render_overview')) {
    function seo_category_reports_render_overview(array $terms, $days, array $snapshot, $page_slug = 'category-seo-admin') {
        $data = seo_category_reports_dashboard_data($terms, $days, $snapshot);
        $has_ga4 = !empty($snapshot['sources']['ga4']);
        $has_gsc = !empty($snapshot['sources']['gsc']);

        echo '<section class="seo-category-overview">';
        echo '<div class="seo-category-overview-heading"><div><h3>Rendimiento general de las categorias</h3><p>Resumen de todas las paginas de categoria durante los ultimos ' . esc_html((string) $days) . ' dias. Las vistas y sesiones proceden de GA4; clics, impresiones y CTR proceden de Search Console.</p></div>';
        if (!empty($snapshot['available'])) {
            echo '<span class="seo-category-overview-period">' . esc_html((string) $days) . ' dias</span>';
        }
        echo '</div>';

        echo '<div class="seo-category-overview-kpis">';
        seo_category_reports_render_metric('Vistas de categorias', $has_ga4 ? number_format_i18n((int) $data['pageviews']) : '-', 'Vistas de pagina en GA4');
        seo_category_reports_render_metric('Sesiones', $has_ga4 ? number_format_i18n((int) $data['sessions']) : '-', 'Sesiones que tocaron paginas de categoria');
        seo_category_reports_render_metric('Clics organicos', $has_gsc ? number_format_i18n((int) $data['clicks']) : '-', $has_gsc ? number_format_i18n((int) $data['impressions']) . ' impresiones' : 'Search Console pendiente');
        seo_category_reports_render_metric('CTR organico', $has_gsc ? seo_category_reports_percent((float) $data['ctr']) : '-', 'Clics / impresiones');
        seo_category_reports_render_metric('Exito medio', !empty($snapshot['available']) && $data['categories_signals'] > 0 ? number_format_i18n((int) $data['average_score']) . '/100' : '-', number_format_i18n((int) $data['categories_signals']) . ' categorias con senales');
        seo_category_reports_render_metric('Categorias con visitas', $has_ga4 ? number_format_i18n((int) $data['categories_with_visits']) : '-', 'De ' . number_format_i18n((int) $data['categories_total']) . ' categorias totales');
        echo '</div>';

        echo '<div class="seo-category-overview-grid-layout">';
        echo '<div class="seo-category-overview-panel seo-category-overview-panel-wide">';
        echo '<div class="seo-category-overview-panel-head"><div><h4>Visitas generales por dia</h4><p>Suma diaria de las vistas GA4 de todas las URLs de categoria.</p></div><strong>' . ($has_ga4 ? esc_html(number_format_i18n((int) $data['pageviews'])) : '-') . '</strong></div>';
        if ($has_ga4) {
            seo_category_reports_render_overview_line_chart((array) $data['daily']);
        } else {
            echo '<div class="seo-category-overview-empty">Conecta Analytics Data API para ver la evolucion diaria de visitas.</div>';
        }
        echo '</div>';

        echo '<div class="seo-category-overview-panel">';
        echo '<div class="seo-category-overview-panel-head"><div><h4>Exito de las categorias</h4><p>Distribucion segun la puntuacion comparativa del periodo.</p></div></div>';
        $dist_total = max(1, (int) $data['categories_total']);
        $segments = [
            ['key' => 'high', 'label' => 'Alto (70-100)', 'class' => 'is-high'],
            ['key' => 'medium', 'label' => 'Medio (40-69)', 'class' => 'is-medium'],
            ['key' => 'low', 'label' => 'Bajo (0-39)', 'class' => 'is-low'],
            ['key' => 'no_data', 'label' => 'Sin senales', 'class' => 'is-none'],
        ];
        echo '<div class="seo-category-success-stack" aria-label="Distribucion del exito de las categorias">';
        foreach ($segments as $segment) {
            $count = (int) ($data['distribution'][$segment['key']] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $width = max(0, min(100, ($count / $dist_total) * 100));
            echo '<span class="' . esc_attr($segment['class']) . '" style="width:' . esc_attr(number_format($width, 3, '.', '')) . '%" title="' . esc_attr($segment['label'] . ': ' . number_format_i18n($count)) . '"></span>';
        }
        echo '</div>';
        echo '<div class="seo-category-success-legend">';
        foreach ($segments as $segment) {
            $count = (int) ($data['distribution'][$segment['key']] ?? 0);
            $percent = $data['categories_total'] > 0 ? ($count / $data['categories_total']) * 100 : 0;
            echo '<div><span class="seo-category-success-dot ' . esc_attr($segment['class']) . '"></span><span>' . esc_html($segment['label']) . '</span><strong>' . esc_html(number_format_i18n($count)) . '</strong><small>' . esc_html(number_format_i18n($percent, 1)) . '%</small></div>';
        }
        echo '</div>';
        echo '<p class="description">La puntuacion pondera clics, visitas e impresiones respecto al mejor resultado del mismo periodo. Es comparativa: 70+ identifica las categorias que destacan dentro de tu propio catalogo.</p>';
        echo '</div>';
        echo '</div>';

        echo '<div class="seo-category-overview-panel seo-category-overview-top-panel">';
        echo '<div class="seo-category-overview-panel-head"><div><h4>Top categorias por visitas</h4><p>Ranking de vistas GA4 con la puntuacion de exito como contexto.</p></div></div>';
        if (!$has_ga4) {
            echo '<div class="seo-category-overview-empty">Conecta Analytics Data API para generar este ranking.</div>';
        } elseif (empty($data['top']) || (int) ($data['top'][0]['pageviews'] ?? 0) <= 0) {
            echo '<div class="seo-category-overview-empty">No hay categorias con visitas GA4 en el periodo seleccionado.</div>';
        } else {
            $max_visits = max(1, (int) ($data['top'][0]['pageviews'] ?? 1));
            echo '<div class="seo-category-top-list">';
            foreach ($data['top'] as $row) {
                if ((int) $row['pageviews'] <= 0) {
                    continue;
                }
                $bar_width = max(2, min(100, ((int) $row['pageviews'] / $max_visits) * 100));
                $score = max(0, min(100, (int) $row['score']));
                $score_class = $score >= 70 ? 'is-high' : ($score >= 40 ? 'is-medium' : 'is-low');
                $report_url = seo_category_reports_admin_url((int) $row['term_id'], $days, [], $page_slug);
                echo '<div class="seo-category-top-row">';
                echo '<div class="seo-category-top-title"><a href="' . esc_url($report_url) . '">' . esc_html((string) $row['name']) . '</a><span class="seo-category-report-score ' . esc_attr($score_class) . '">' . esc_html($score . '/100') . '</span></div>';
                echo '<div class="seo-category-top-track"><span style="width:' . esc_attr(number_format($bar_width, 2, '.', '')) . '%"></span></div>';
                echo '<strong>' . esc_html(number_format_i18n((int) $row['pageviews'])) . ' vistas</strong>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '</section>';
    }
}

if (!function_exists('seo_category_reports_render_category')) {
    function seo_category_reports_render_category($term_id, $page_slug = 'category-seo-admin') {
        $term_id = absint($term_id);
        $days = seo_category_reports_days(seo_category_reports_query_arg('days', 28));
        $force = false;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Refresh is explicitly protected by the nonce verified inside this block.
        if (isset($_GET['refresh']) && '1' === (string) wp_unslash($_GET['refresh'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce value itself.
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            $force = wp_verify_nonce($nonce, 'seo_category_report_refresh_' . $term_id);
        }

        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            echo '<div class="notice notice-error"><p>La categoria solicitada no existe.</p></div>';
            return;
        }

        $report = seo_category_reports_collect($term_id, $days, $force);
        if (is_wp_error($report)) {
            echo '<div class="notice notice-error"><p>' . esc_html($report->get_error_message()) . '</p></div>';
            return;
        }

        // Ensure the comparative score exists without doing per-category API calls.
        $snapshot = seo_category_reports_catalog_snapshot($days, false);
        $summary = seo_category_reports_get_summary($term_id, $days);
        $score = max(0, min(100, absint($summary['score'] ?? 0)));
        $score_text = !empty($snapshot['available']) ? $score . '/100' : '-';

        $edit_url = function_exists('seo_get_category_editor_url')
            ? seo_get_category_editor_url($term_id, $page_slug)
            : add_query_arg([
                'page' => sanitize_key($page_slug),
                'tab' => 'categorias',
                'edit_category_id' => $term_id,
            ], admin_url('admin.php'));

        $refresh_url = wp_nonce_url(
            seo_category_reports_admin_url($term_id, $days, ['refresh' => 1], $page_slug),
            'seo_category_report_refresh_' . $term_id
        );

        echo '<div class="seo-category-report-head">';
        echo '<div>';
        echo '<h2 style="margin:0 0 5px;">' . esc_html($term->name) . '</h2>';
        echo '<p style="margin:0;color:#646970;">ID ' . absint($term_id) . ' - slug <code>' . esc_html($term->slug) . '</code> - ' . esc_html(number_format_i18n((int) $term->count)) . ' productos - <a href="' . esc_url($report['url']) . '" target="_blank" rel="noopener noreferrer">Ver categoria</a></p>';
        echo '</div>';
        echo '<div class="seo-category-report-actions"><span class="seo-category-report-score is-high">' . esc_html($score_text) . '</span><a class="button" href="' . esc_url($edit_url) . '">Editar categoria</a><a class="button" href="' . esc_url($refresh_url) . '">Actualizar datos</a></div>';
        echo '</div>';

        seo_category_reports_render_status((array) $report['google']);

        $success = seo_category_reports_success_label($report);
        echo '<div class="seo-category-report-summary ' . esc_attr($success['class']) . '"><strong>' . esc_html($success['label']) . '</strong><span>' . esc_html($success['detail']) . '</span></div>';

        echo '<form method="get" class="seo-category-report-period">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '">';
        echo '<input type="hidden" name="tab" value="informes">';
        echo '<input type="hidden" name="report_category_id" value="' . absint($term_id) . '">';
        echo '<label><strong>Periodo</strong> <select name="days">';
        foreach ([7 => '7 dias', 28 => '28 dias', 90 => '90 dias'] as $value => $label) {
            echo '<option value="' . absint($value) . '" ' . selected($days, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> <button class="button" type="submit">Aplicar</button>';
        echo '<span class="description">Detalle cacheado 15 minutos. La puntuacion se compara con el resto de categorias del mismo periodo.</span>';
        echo '</form>';

        echo '<section class="seo-category-report-card">';
        echo '<div class="seo-category-report-title"><div><h3>Visibilidad en Google</h3><p>Search Console para la URL exacta de esta categoria.</p></div></div>';
        if (!empty($report['gsc']['error'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($report['gsc']['error']) . '</p></div>';
        }
        echo '<div class="seo-category-report-metrics">';
        seo_category_reports_render_metric('Puntuacion', $score_text, 'Comparativa entre categorias');
        seo_category_reports_render_metric('Impresiones', number_format_i18n((int) $report['gsc']['impressions']));
        seo_category_reports_render_metric('Clics desde Google', number_format_i18n((int) $report['gsc']['clicks']));
        seo_category_reports_render_metric('CTR', seo_category_reports_percent((float) $report['gsc']['ctr']));
        seo_category_reports_render_metric('Posicion media', number_format_i18n((float) $report['gsc']['position'], 1));
        echo '</div>';
        echo '<h4>Evolucion diaria</h4>';
        seo_category_reports_render_daily_chart((array) ($report['daily']['rows'] ?? []));
        echo '</section>';

        echo '<section class="seo-category-report-card">';
        echo '<div class="seo-category-report-title"><div><h3>Visitas a la categoria</h3><p>Actividad de la pagina de categoria medida por Google Analytics 4.</p></div></div>';
        if (!empty($report['ga4']['error'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($report['ga4']['error']) . '</p></div>';
        }
        echo '<div class="seo-category-report-metrics">';
        seo_category_reports_render_metric('Sesiones', number_format_i18n((int) $report['ga4']['sessions']));
        seo_category_reports_render_metric('Usuarios activos', number_format_i18n((int) $report['ga4']['users']));
        seo_category_reports_render_metric('Vistas de pagina', number_format_i18n((int) $report['ga4']['pageviews']));
        seo_category_reports_render_metric('Productos', number_format_i18n((int) $term->count), 'Productos asociados actualmente');
        echo '</div>';
        echo '</section>';

        echo '<section class="seo-category-report-card">';
        echo '<div class="seo-category-report-title"><div><h3>Consultas que encuentran esta categoria</h3><p>Terminos de busqueda que Search Console atribuye a la URL de la categoria.</p></div></div>';
        if (!empty($report['queries']['error'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($report['queries']['error']) . '</p></div>';
        }

        $query_rows = (array) ($report['queries']['rows'] ?? []);
        if (empty($query_rows)) {
            echo '<p class="description">No hay consultas disponibles para esta categoria en el periodo seleccionado.</p>';
        } else {
            echo '<div style="overflow:auto;"><table class="widefat striped"><thead><tr><th>Consulta</th><th>Clics</th><th>Impresiones</th><th>CTR</th><th>Posicion</th></tr></thead><tbody>';
            foreach ($query_rows as $row) {
                echo '<tr>';
                echo '<td><strong>' . esc_html((string) $row['query']) . '</strong></td>';
                echo '<td>' . esc_html(number_format_i18n((int) $row['clicks'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) $row['impressions'])) . '</td>';
                echo '<td>' . esc_html(seo_category_reports_percent((float) $row['ctr'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $row['position'], 1)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';

        echo '<p class="description">Este informe mide la URL de la categoria. No suma automaticamente las impresiones de las fichas de producto incluidas en ella.</p>';
    }
}

if (!function_exists('seo_category_reports_render_selector')) {
    function seo_category_reports_render_selector($page_slug = 'category-seo-admin') {
        $days = seo_category_reports_days(seo_category_reports_query_arg('days', 28));
        $search = sanitize_text_field((string) seo_category_reports_query_arg('q', ''));
        $score_order = 'asc' === strtolower(sanitize_key((string) seo_category_reports_query_arg('score_order', 'desc'))) ? 'asc' : 'desc';
        $paged = max(1, absint(seo_category_reports_query_arg('paged', 1)));
        $per_page = 40;

        $force_catalog = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Refresh is explicitly protected by the nonce verified inside this block.
        if (isset($_GET['refresh_catalog']) && '1' === (string) wp_unslash($_GET['refresh_catalog'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce value itself.
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            $force_catalog = wp_verify_nonce($nonce, 'seo_category_reports_refresh_catalog_' . $days);
        }

        $snapshot = seo_category_reports_catalog_snapshot($days, $force_catalog);
        $google = seo_category_reports_google_state();

        $terms = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms)) {
            $terms = [];
        }
        $overview_terms = $terms;

        if ('' !== $search) {
            $needle = strtolower($search);
            $terms = array_values(array_filter($terms, static function ($term) use ($needle, $search) {
                if (ctype_digit($search) && absint($search) === absint($term->term_id)) {
                    return true;
                }
                return false !== strpos(strtolower((string) $term->name), $needle)
                    || false !== strpos(strtolower((string) $term->slug), $needle);
            }));
        }

        usort($terms, static function ($left, $right) use ($days, $score_order) {
            $left_summary = seo_category_reports_get_summary($left->term_id, $days);
            $right_summary = seo_category_reports_get_summary($right->term_id, $days);
            $left_score = (int) ($left_summary['score'] ?? 0);
            $right_score = (int) ($right_summary['score'] ?? 0);

            if ($left_score !== $right_score) {
                if ('asc' === $score_order) {
                    return $left_score <=> $right_score;
                }
                return $right_score <=> $left_score;
            }

            return strcasecmp((string) $left->name, (string) $right->name);
        });

        $total_terms = count($terms);
        $total_pages = max(1, (int) ceil($total_terms / $per_page));
        if ($paged > $total_pages) {
            $paged = $total_pages;
        }
        $visible_terms = array_slice($terms, ($paged - 1) * $per_page, $per_page);

        echo '<div class="seo-category-report-intro">';
        echo '<h2 style="margin:0 0 6px;">Informes Google por categoria</h2>';
        echo '<p style="margin:0;">Compara las paginas de categoria y ordenalas por una puntuacion 0-100 basada en las senales disponibles de impresiones, clics y visitas.</p>';
        seo_category_reports_render_status($google);
        echo '</div>';

        if (empty($google['service_loaded'])) {
            echo '<div class="notice notice-error inline"><p>El servicio Google del plugin no esta cargado. Comprueba el subsistema Importar / Exportar.</p></div>';
        } elseif (empty($google['search_console']) && empty($google['analytics'])) {
            echo '<div class="notice notice-warning inline"><p>La conexion Google aun no tiene Search Console o Analytics configurados con una cuenta de servicio.</p></div>';
        }

        if (!empty($snapshot['errors'])) {
            echo '<div class="notice notice-warning inline"><p><strong>Snapshot parcial:</strong> ' . esc_html(implode(' | ', (array) $snapshot['errors'])) . '</p></div>';
        }

        $refresh_url = wp_nonce_url(
            seo_category_reports_admin_url(0, $days, [
                'q' => $search,
                'score_order' => $score_order,
                'refresh_catalog' => 1,
            ], $page_slug),
            'seo_category_reports_refresh_catalog_' . $days
        );

        echo '<div class="seo-category-report-catalog-status">';
        if (!empty($snapshot['available'])) {
            $generated = wp_date('Y-m-d H:i:s', absint($snapshot['generated'] ?? 0));
            echo '<span><strong>Estadisticas:</strong> ' . esc_html($days . ' dias') . ' - ' . esc_html(number_format_i18n((int) ($snapshot['matched_categories'] ?? 0))) . ' categorias con senales - actualizado ' . esc_html($generated) . '</span>';
        } else {
            echo '<span>No hay un snapshot agregado disponible todavia.</span>';
        }
        echo '<a class="button" href="' . esc_url($refresh_url) . '">Actualizar estadisticas</a>';
        echo '</div>';

        seo_category_reports_render_overview((array) $overview_terms, $days, (array) $snapshot, $page_slug);

        echo '<form method="get" class="seo-category-report-filter">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '">';
        if (sanitize_key($page_slug) === 'seo-solucionador') {
            echo '<input type="hidden" name="tab" value="diagnostics">';
            echo '<input type="hidden" name="diag_scope" value="categories">';
            echo '<input type="hidden" name="diag_view" value="google">';
        } else {
            echo '<input type="hidden" name="tab" value="informes">';
        }
        echo '<input type="hidden" name="score_order" value="' . esc_attr($score_order) . '">';
        echo '<div><label>Buscar</label><input type="text" name="q" value="' . esc_attr($search) . '" placeholder="Nombre, slug o ID"></div>';
        echo '<div><label>Periodo</label><select name="days">';
        foreach ([7 => '7 dias', 28 => '28 dias', 90 => '90 dias'] as $value => $label) {
            echo '<option value="' . absint($value) . '" ' . selected($days, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="seo-category-report-filter-actions"><button class="button button-primary" type="submit">Filtrar</button><a class="button" href="' . esc_url(seo_category_reports_admin_url(0, $days, [], $page_slug)) . '">Limpiar</a></div>';
        echo '</form>';

        $toggle_score_order = 'desc' === $score_order ? 'asc' : 'desc';
        $score_sort_url = seo_category_reports_admin_url(0, $days, [
            'q' => $search,
            'score_order' => $toggle_score_order,
            'paged' => 1,
        ], $page_slug);
        $score_arrow = 'desc' === $score_order ? 'down' : 'up';

        echo '<div class="seo-category-report-table-wrap"><table class="widefat striped seo-category-report-table"><thead><tr>';
        echo '<th style="width:70px;">ID</th>';
        echo '<th>Categoria</th>';
        echo '<th style="width:135px;"><a href="' . esc_url($score_sort_url) . '" title="Cambiar orden por puntuacion">Puntuacion (' . esc_html($score_arrow) . ')</a><br><small>' . esc_html($days . ' dias') . '</small></th>';
        echo '<th style="width:105px;">Impresiones</th>';
        echo '<th style="width:80px;">Clics</th>';
        echo '<th style="width:80px;">CTR</th>';
        echo '<th style="width:85px;">Posicion</th>';
        echo '<th style="width:85px;">Visitas</th>';
        echo '<th style="width:90px;">Productos</th>';
        echo '<th style="width:120px;">Accion</th>';
        echo '</tr></thead><tbody>';

        if (empty($visible_terms)) {
            echo '<tr><td colspan="10">No se han encontrado categorias con estos filtros.</td></tr>';
        } else {
            foreach ($visible_terms as $term) {
                $summary = seo_category_reports_get_summary($term->term_id, $days);
                $score = max(0, min(100, absint($summary['score'] ?? 0)));
                $score_class = $score >= 70 ? 'is-high' : ($score >= 40 ? 'is-medium' : 'is-low');
                $score_text = !empty($snapshot['available']) ? $score . '/100' : '-';
                $parent_name = '';
                if (absint($term->parent) > 0) {
                    $parent = get_term($term->parent, 'product_cat');
                    if ($parent && !is_wp_error($parent)) {
                        $parent_name = (string) $parent->name;
                    }
                }

                echo '<tr>';
                echo '<td>' . absint($term->term_id) . '</td>';
                echo '<td><strong>' . esc_html($term->name) . '</strong><br><code>/' . esc_html($term->slug) . '</code>' . ('' !== $parent_name ? '<br><small>Padre: ' . esc_html($parent_name) . '</small>' : '') . '</td>';
                echo '<td><span class="seo-category-report-score ' . esc_attr($score_class) . '">' . esc_html($score_text) . '</span></td>';
                echo '<td>' . esc_html(number_format_i18n((int) ($summary['impressions'] ?? 0))) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) ($summary['clicks'] ?? 0))) . '</td>';
                echo '<td>' . esc_html(seo_category_reports_percent((float) ($summary['ctr'] ?? 0))) . '</td>';
                echo '<td>' . esc_html((float) ($summary['position'] ?? 0) > 0 ? number_format_i18n((float) $summary['position'], 1) : '-') . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) ($summary['pageviews'] ?? 0))) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) $term->count)) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url(seo_category_reports_admin_url($term->term_id, $days, [], $page_slug)) . '">Ver informe</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';

        if ($total_pages > 1) {
            $base_args = [
                'page' => sanitize_key($page_slug),
                'tab' => sanitize_key($page_slug) === 'seo-solucionador' ? 'diagnostics' : 'informes',
                'q' => $search,
                'days' => $days,
                'score_order' => $score_order,
                'paged' => '%#%',
            ];
            if (sanitize_key($page_slug) === 'seo-solucionador') {
                $base_args['diag_scope'] = 'categories';
                $base_args['diag_view'] = 'google';
            }
            echo '<div style="margin-top:18px;">';
            echo wp_kses_post(paginate_links([
                'base' => esc_url_raw(add_query_arg($base_args, admin_url('admin.php'))),
                'format' => '',
                'current' => $paged,
                'total' => $total_pages,
                'type' => 'list',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]));
            echo '</div>';
        }
    }
}

if (!function_exists('seo_category_reports_page')) {
    function seo_category_reports_page($page_slug = 'category-seo-admin') {
        if (!current_user_can('manage_options')) {
            return;
        }

        $term_id = absint(seo_category_reports_query_arg('report_category_id', 0));
if ($term_id > 0) {
            echo '<p><a class="button" href="' . esc_url(seo_category_reports_admin_url(0, seo_category_reports_days(seo_category_reports_query_arg('days', 28)), [], $page_slug)) . '">&larr; Volver a categorias</a></p>';
            seo_category_reports_render_category($term_id, $page_slug);
        } else {
            seo_category_reports_render_selector($page_slug);
        }
    }
}
