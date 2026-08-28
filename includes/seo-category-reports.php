<?php
/**
 * Informes Google por categoria de producto.
 *
 * Reutiliza la conexion centralizada de Google Search Console / Analytics
 * definida por el plugin. No guarda ni expone credenciales.
 */

defined('ABSPATH') || exit;

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
        $end_timestamp = current_time('timestamp');
        $start_timestamp = strtotime('-' . max(0, $days - 1) . ' days', $end_timestamp);

        $request = [
            'startDate' => wp_date('Y-m-d', $start_timestamp),
            'endDate'   => wp_date('Y-m-d', $end_timestamp),
            'rowLimit'  => min(25000, max(1, absint($row_limit))),
            'dataState' => 'all',
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
            'dateRanges' => [[
                'startDate' => max(1, $days - 1) . 'daysAgo',
                'endDate'   => 'today',
            ]],
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
            $page_slug = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'category-seo-admin';
        }

        $args = [
            'page' => sanitize_key($page_slug),
            'tab'  => 'informes',
            'days' => seo_category_reports_days($days),
        ];

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
        $cache_key = 'seo_category_google_catalog_v1_' . get_current_blog_id() . '_' . $days;

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

        $end_timestamp = current_time('timestamp');
        $start_timestamp = strtotime('-' . max(0, $days - 1) . ' days', $end_timestamp);

        if (!empty($google['search_console']) && function_exists('seo_google_search_console_query')) {
            $gsc = seo_google_search_console_query([
                'startDate'  => wp_date('Y-m-d', $start_timestamp),
                'endDate'    => wp_date('Y-m-d', $end_timestamp),
                'dimensions' => ['page'],
                'rowLimit'   => 25000,
                'dataState'  => 'all',
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
                'dateRanges' => [[
                    'startDate' => max(1, $days - 1) . 'daysAgo',
                    'endDate'   => 'today',
                ]],
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

if (!function_exists('seo_category_reports_render_category')) {
    function seo_category_reports_render_category($term_id, $page_slug = 'category-seo-admin') {
        $term_id = absint($term_id);
        $days = seo_category_reports_days($_GET['days'] ?? 28);
        $force = false;

        if (isset($_GET['refresh']) && '1' === (string) $_GET['refresh']) {
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
        $days = seo_category_reports_days($_GET['days'] ?? 28);
        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $score_order = isset($_GET['score_order']) && 'asc' === strtolower((string) $_GET['score_order']) ? 'asc' : 'desc';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 40;

        $force_catalog = false;
        if (isset($_GET['refresh_catalog']) && '1' === (string) $_GET['refresh_catalog']) {
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

        echo '<form method="get" class="seo-category-report-filter">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '">';
        echo '<input type="hidden" name="tab" value="informes">';
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
                'tab' => 'informes',
                'q' => $search,
                'days' => $days,
                'score_order' => $score_order,
                'paged' => '%#%',
            ];
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

        $term_id = isset($_GET['report_category_id']) ? absint($_GET['report_category_id']) : 0;

        echo '<style>
        .seo-category-report-intro,.seo-category-report-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:0 0 18px}
        .seo-category-report-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:12px}
        .seo-category-report-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .seo-category-report-source-row{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 18px}
        .seo-category-report-summary{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;border-left:4px solid #dba617;background:#fff8e5;padding:10px 12px;margin:0 0 18px}
        .seo-category-report-summary.is-ok{border-left-color:#00a32a;background:#edfaef}.seo-category-report-summary span{color:#50575e}
        .seo-category-report-badge{display:inline-block;padding:5px 9px;border-radius:999px;font-size:12px;background:#f0f0f1}
        .seo-category-report-badge.is-ok{background:#edfaef;color:#1e4620}.seo-category-report-badge.is-pending{background:#fff8e5;color:#6d4f00}
        .seo-category-report-period{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;margin:0 0 18px}
        .seo-category-report-period select{min-width:120px}
        .seo-category-report-title h3{margin:0 0 4px;font-size:16px}.seo-category-report-title p{margin:0 0 14px;color:#646970}
        .seo-category-report-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:12px 0 18px}
        .seo-category-report-metric{border:1px solid #dcdcde;border-radius:7px;padding:13px;background:#fbfbfc;min-width:0}
        .seo-category-report-metric-label,.seo-category-report-metric-detail{display:block;color:#646970;font-size:12px}
        .seo-category-report-metric strong{display:block;font-size:23px;line-height:1.2;margin:4px 0;overflow-wrap:anywhere}
        .seo-category-report-bars{height:180px;display:flex;align-items:flex-end;gap:3px;border-left:1px solid #dcdcde;border-bottom:1px solid #dcdcde;padding:8px 8px 22px;overflow-x:auto}
        .seo-category-report-bar-col{height:100%;min-width:18px;flex:1;display:flex;align-items:flex-end;position:relative}.seo-category-report-bar{width:100%;min-height:2px;background:#2271b1;border-radius:2px 2px 0 0}
        .seo-category-report-bar-col span{position:absolute;bottom:-19px;left:50%;transform:translateX(-50%);font-size:9px;color:#646970;white-space:nowrap}
        .seo-category-report-filter{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:18px 0;display:grid;grid-template-columns:2fr 1fr auto;gap:12px;align-items:end}
        .seo-category-report-filter label{display:block;font-weight:600;margin-bottom:5px}.seo-category-report-filter input,.seo-category-report-filter select{width:100%}.seo-category-report-filter-actions{display:flex;gap:6px}
        .seo-category-report-catalog-status{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:10px 12px;margin:0 0 12px}
        .seo-category-report-table-wrap{overflow:auto}.seo-category-report-table th,.seo-category-report-table td{vertical-align:middle}
        .seo-category-report-score{display:inline-block;min-width:58px;text-align:center;padding:5px 8px;border-radius:999px;font-weight:700;background:#f0f0f1;color:#50575e}
        .seo-category-report-score.is-medium{background:#fff8e5;color:#996800}.seo-category-report-score.is-high{background:#edfaef;color:#008a20}
        @media(max-width:1000px){.seo-category-report-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:700px){.seo-category-report-filter,.seo-category-report-metrics{grid-template-columns:1fr}.seo-category-report-metric strong{font-size:20px}}
        </style>';

        if ($term_id > 0) {
            echo '<p><a class="button" href="' . esc_url(seo_category_reports_admin_url(0, seo_category_reports_days($_GET['days'] ?? 28), [], $page_slug)) . '">&larr; Volver a categorias</a></p>';
            seo_category_reports_render_category($term_id, $page_slug);
        } else {
            seo_category_reports_render_selector($page_slug);
        }
    }
}
