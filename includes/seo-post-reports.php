<?php
/**
 * Informes Google por entradas (posts).
 *
 * Reutiliza la conexion centralizada de Google Search Console / Analytics
 * definida en el subsistema Google del plugin. No guarda credenciales.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_post_reports_days')) {
    function seo_post_reports_days($value) {
        $value = absint($value);
        return in_array($value, [7, 28, 90], true) ? $value : 28;
    }
}

if (!function_exists('seo_post_reports_path_key')) {
    function seo_post_reports_path_key($url_or_path) {
        $path = (string) wp_parse_url((string) $url_or_path, PHP_URL_PATH);
        if ('' === $path) {
            $path = (string) $url_or_path;
        }
        $path = '/' . ltrim($path, '/');
        return untrailingslashit($path) ?: '/';
    }
}

if (!function_exists('seo_post_reports_google_state')) {
    function seo_post_reports_google_state() {
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

if (!function_exists('seo_post_reports_gsc_request')) {
    function seo_post_reports_gsc_request($url, $days, array $dimensions = [], $row_limit = 1000) {
        if (!function_exists('seo_google_search_console_query')) {
            return new WP_Error('seo_post_reports_gsc_unavailable', 'Search Console no esta disponible en la conexion Google actual.');
        }

        $days = seo_post_reports_days($days);
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
        ];

        $request['dimensions'] = !empty($dimensions) ? array_values($dimensions) : [];
        return seo_google_search_console_query($request);
    }
}

if (!function_exists('seo_post_reports_gsc_summary')) {
    function seo_post_reports_gsc_summary($url, $days) {
        $empty = [
            'available'   => false,
            'error'       => '',
            'clicks'      => 0,
            'impressions' => 0,
            'ctr'         => 0.0,
            'position'    => 0.0,
        ];

        $report = seo_post_reports_gsc_request($url, $days, [], 1);
        if (is_wp_error($report)) {
            $empty['error'] = $report->get_error_message();
            return $empty;
        }

        $empty['available'] = true;
        $row = !empty($report['rows'][0]) && is_array($report['rows'][0]) ? $report['rows'][0] : [];
        $empty['clicks'] = (int) ($row['clicks'] ?? 0);
        $empty['impressions'] = (int) ($row['impressions'] ?? 0);
        $empty['ctr'] = (float) ($row['ctr'] ?? 0);
        $empty['position'] = (float) ($row['position'] ?? 0);
        return $empty;
    }
}

if (!function_exists('seo_post_reports_gsc_queries')) {
    function seo_post_reports_gsc_queries($url, $days) {
        $result = ['available' => false, 'error' => '', 'rows' => []];
        $report = seo_post_reports_gsc_request($url, $days, ['query'], 100);

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

if (!function_exists('seo_post_reports_gsc_daily')) {
    function seo_post_reports_gsc_daily($url, $days) {
        $result = ['available' => false, 'error' => '', 'rows' => []];
        $report = seo_post_reports_gsc_request($url, $days, ['date'], 500);

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

if (!function_exists('seo_post_reports_ga_page')) {
    function seo_post_reports_ga_page($url, $days) {
        $empty = [
            'available' => false,
            'error'     => '',
            'sessions'  => 0,
            'users'     => 0,
            'pageviews' => 0,
        ];

        if (!function_exists('seo_google_analytics_run_report')) {
            $empty['error'] = 'Analytics Data API no esta disponible en la conexion Google actual.';
            return $empty;
        }

        $days = seo_post_reports_days($days);
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
            $empty['error'] = $report->get_error_message();
            return $empty;
        }

        $empty['available'] = true;
        foreach ((array) ($report['rows'] ?? []) as $row) {
            $empty['sessions'] += (int) ($row['metricValues'][0]['value'] ?? 0);
            $empty['users'] += (int) ($row['metricValues'][1]['value'] ?? 0);
            $empty['pageviews'] += (int) ($row['metricValues'][2]['value'] ?? 0);
        }
        return $empty;
    }
}

if (!function_exists('seo_post_reports_collect')) {
    function seo_post_reports_collect($post_id, $days = 28, $force = false) {
        $post_id = absint($post_id);
        $days = seo_post_reports_days($days);
        $post = $post_id > 0 ? get_post($post_id) : null;

        if (!$post || 'post' !== $post->post_type) {
            return new WP_Error('seo_post_reports_invalid_post', 'La entrada solicitada no existe.');
        }

        $url = get_permalink($post_id);
        if (!$url) {
            return new WP_Error('seo_post_reports_no_permalink', 'No se pudo resolver la URL publica de la entrada.');
        }

        $cache_key = 'seo_post_google_report_v1_' . get_current_blog_id() . '_' . $post_id . '_' . $days;
        if ($force) {
            delete_transient($cache_key);
        } else {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = [
            'post_id'   => $post_id,
            'days'      => $days,
            'url'       => $url,
            'generated' => current_time('timestamp'),
            'google'    => seo_post_reports_google_state(),
            'gsc'       => seo_post_reports_gsc_summary($url, $days),
            'queries'   => seo_post_reports_gsc_queries($url, $days),
            'daily'     => seo_post_reports_gsc_daily($url, $days),
            'ga4'       => seo_post_reports_ga_page($url, $days),
        ];

        set_transient($cache_key, $data, 15 * MINUTE_IN_SECONDS);
        return $data;
    }
}

if (!function_exists('seo_post_reports_percent')) {
    function seo_post_reports_percent($ratio, $decimals = 1) {
        return number_format_i18n((float) $ratio * 100, absint($decimals)) . '%';
    }
}

if (!function_exists('seo_post_reports_admin_url')) {
    function seo_post_reports_admin_url($post_id = 0, $days = 28, array $extra = []) {
        $args = [
            'page' => 'seo-post-reports',
            'days' => seo_post_reports_days($days),
        ];
        if (absint($post_id) > 0) {
            $args['post_id'] = absint($post_id);
        }
        return add_query_arg(array_merge($args, $extra), admin_url('edit.php'));
    }
}

if (!function_exists('seo_post_reports_editor_url')) {
    function seo_post_reports_editor_url($post_id = 0) {
        $args = ['page' => 'seo-post-editor'];
        if (absint($post_id) > 0) {
            $args['post_id'] = absint($post_id);
        }
        return add_query_arg($args, admin_url('edit.php'));
    }
}

if (!function_exists('seo_post_reports_success_label')) {
    function seo_post_reports_success_label(array $report) {
        $clicks = (int) ($report['gsc']['clicks'] ?? 0);
        $impressions = (int) ($report['gsc']['impressions'] ?? 0);
        $pageviews = (int) ($report['ga4']['pageviews'] ?? 0);
        $ctr = (float) ($report['gsc']['ctr'] ?? 0);

        if ($clicks > 0 && $ctr >= 0.03) {
            return ['label' => 'Buen trafico organico', 'detail' => 'La entrada recibe clics desde Google y mantiene un CTR de al menos el 3% en el periodo.', 'class' => 'is-ok'];
        }
        if ($clicks > 0) {
            return ['label' => 'Recibe trafico organico', 'detail' => 'Google Search genera clics hacia esta entrada.', 'class' => 'is-ok'];
        }
        if ($impressions > 0) {
            return ['label' => 'Visible sin clics', 'detail' => 'La entrada aparece en Google, pero no ha recibido clics en el periodo.', 'class' => 'is-pending'];
        }
        if ($pageviews > 0) {
            return ['label' => 'Tiene visitas', 'detail' => 'GA4 registra vistas, aunque Search Console no atribuye clics organicos en el periodo.', 'class' => 'is-pending'];
        }
        return ['label' => 'Sin senales', 'detail' => 'No hay impresiones, clics ni visitas registradas para este periodo.', 'class' => 'is-pending'];
    }
}

if (!function_exists('seo_post_reports_summary_meta_key')) {
    function seo_post_reports_summary_meta_key($field, $days = 28) {
        $allowed = ['score', 'impressions', 'clicks', 'pageviews', 'updated'];
        $field = sanitize_key((string) $field);
        if (!in_array($field, $allowed, true)) {
            $field = 'score';
        }
        return '_seo_post_google_' . $field . '_' . seo_post_reports_days($days);
    }
}

if (!function_exists('seo_post_reports_get_summary')) {
    function seo_post_reports_get_summary($post_id, $days = 28) {
        $post_id = absint($post_id);
        $days = seo_post_reports_days($days);
        $score_key = seo_post_reports_summary_meta_key('score', $days);
        $has_snapshot = $post_id > 0 && metadata_exists('post', $post_id, $score_key);

        return [
            'has_snapshot' => $has_snapshot,
            'score'        => $has_snapshot ? max(0, min(100, absint(get_post_meta($post_id, $score_key, true)))) : 0,
            'impressions'  => max(0, (int) get_post_meta($post_id, seo_post_reports_summary_meta_key('impressions', $days), true)),
            'clicks'       => max(0, (int) get_post_meta($post_id, seo_post_reports_summary_meta_key('clicks', $days), true)),
            'pageviews'    => max(0, (int) get_post_meta($post_id, seo_post_reports_summary_meta_key('pageviews', $days), true)),
            'updated'      => max(0, (int) get_post_meta($post_id, seo_post_reports_summary_meta_key('updated', $days), true)),
        ];
    }
}

if (!function_exists('seo_post_reports_resolve_post_url')) {
    function seo_post_reports_resolve_post_url($url_or_path) {
        static $cache = [];

        $raw = trim((string) $url_or_path);
        if ('' === $raw) {
            return 0;
        }

        $path = seo_post_reports_path_key($raw);
        if (isset($cache[$path])) {
            return $cache[$path];
        }

        $url = preg_match('#^https?://#i', $raw) ? $raw : home_url($path);
        $post_id = absint(url_to_postid($url));

        if ($post_id > 0 && 'post' !== get_post_type($post_id)) {
            $post_id = 0;
        }

        if ($post_id <= 0) {
            $slug = sanitize_title(basename(untrailingslashit($path)));
            if ('' !== $slug) {
                $post = get_page_by_path($slug, OBJECT, 'post');
                if ($post instanceof WP_Post) {
                    $post_id = absint($post->ID);
                }
            }
        }

        $cache[$path] = $post_id;
        return $post_id;
    }
}

if (!function_exists('seo_post_reports_catalog_snapshot')) {
    /**
     * Snapshot agregado de entradas con dos consultas Google:
     * Search Console por pagina y GA4 por pagePath.
     *
     * La puntuacion es comparativa dentro del sitio y periodo seleccionado.
     */
    function seo_post_reports_catalog_snapshot($days = 28, $force = false) {
        global $wpdb;

        $days = seo_post_reports_days($days);
        $cache_key = 'seo_post_google_catalog_v1_' . get_current_blog_id() . '_' . $days;

        if ($force) {
            delete_transient($cache_key);
        } else {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $google = seo_post_reports_google_state();
        $snapshot = [
            'available'     => false,
            'generated'     => current_time('timestamp'),
            'days'          => $days,
            'matched_posts' => 0,
            'scored_posts'  => 0,
            'errors'        => [],
            'sources'       => [
                'gsc' => false,
                'ga4' => false,
            ],
        ];

        $summaries = [];
        $blank = static function () {
            return [
                'impressions' => 0,
                'clicks'      => 0,
                'pageviews'   => 0,
            ];
        };
        $ensure = static function ($post_id) use (&$summaries, $blank) {
            $post_id = absint($post_id);
            if ($post_id <= 0) {
                return 0;
            }
            if (!isset($summaries[$post_id])) {
                $summaries[$post_id] = $blank();
            }
            return $post_id;
        };

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
                    $post_id = seo_post_reports_resolve_post_url((string) ($row['keys'][0] ?? ''));
                    $post_id = $ensure($post_id);
                    if ($post_id <= 0) {
                        continue;
                    }
                    $summaries[$post_id]['impressions'] += max(0, (int) ($row['impressions'] ?? 0));
                    $summaries[$post_id]['clicks'] += max(0, (int) ($row['clicks'] ?? 0));
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
                    $post_id = seo_post_reports_resolve_post_url((string) ($row['dimensionValues'][0]['value'] ?? ''));
                    $post_id = $ensure($post_id);
                    if ($post_id <= 0) {
                        continue;
                    }
                    $summaries[$post_id]['pageviews'] += max(0, (int) ($row['metricValues'][0]['value'] ?? 0));
                }
            }
        }

        $snapshot['matched_posts'] = count($summaries);

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

            // Para contenido editorial, el clic organico pesa mas que la mera impresion.
            $weights = [
                'impressions' => 20,
                'clicks'      => 45,
                'pageviews'   => 35,
            ];
            $active_weight = 0;
            foreach ($weights as $metric => $weight) {
                if ($maxima[$metric] > 0) {
                    $active_weight += $weight;
                }
            }

            $meta_fields = ['score', 'impressions', 'clicks', 'pageviews', 'updated'];
            foreach ($meta_fields as $field) {
                $meta_key = seo_post_reports_summary_meta_key($field, $days);
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'post' AND pm.meta_key = %s",
                        $meta_key
                    )
                );
            }

            foreach ($summaries as $post_id => $summary) {
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

                update_post_meta($post_id, seo_post_reports_summary_meta_key('score', $days), max(0, min(100, $score)));
                update_post_meta($post_id, seo_post_reports_summary_meta_key('impressions', $days), (int) $summary['impressions']);
                update_post_meta($post_id, seo_post_reports_summary_meta_key('clicks', $days), (int) $summary['clicks']);
                update_post_meta($post_id, seo_post_reports_summary_meta_key('pageviews', $days), (int) $summary['pageviews']);
                update_post_meta($post_id, seo_post_reports_summary_meta_key('updated', $days), (int) $snapshot['generated']);

                if ($score > 0) {
                    $snapshot['scored_posts']++;
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

if (!function_exists('seo_post_reports_score_posts_clauses')) {
    /** Ordena una WP_Query de entradas por la puntuacion derivada, con LEFT JOIN. */
    function seo_post_reports_score_posts_clauses($clauses, $query) {
        global $wpdb;

        $days = absint($query->get('seo_post_reports_score_days'));
        if (!in_array($days, [7, 28, 90], true)) {
            return $clauses;
        }

        $direction = 'asc' === strtolower((string) $query->get('seo_post_reports_score_order')) ? 'ASC' : 'DESC';
        $meta_key = seo_post_reports_summary_meta_key('score', $days);

        if (false === strpos($clauses['join'], 'seo_post_score_pm')) {
            $clauses['join'] .= $wpdb->prepare(
                " LEFT JOIN (SELECT post_id, MAX(CAST(meta_value AS DECIMAL(10,2))) AS score_value FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY post_id) AS seo_post_score_pm ON seo_post_score_pm.post_id = {$wpdb->posts}.ID ",
                $meta_key
            );
        }

        $clauses['orderby'] = 'COALESCE(seo_post_score_pm.score_value, 0) ' . $direction . ', ' . $wpdb->posts . '.post_modified DESC';
        return $clauses;
    }
}

if (!function_exists('seo_post_reports_relation_ids_for_product_cat')) {
    function seo_post_reports_relation_ids_for_product_cat($filter) {
        global $wpdb;

        $table = $wpdb->prefix . 'seo_relations';
        if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            return [];
        }

        if ('none' === $filter) {
            return array_values(array_unique(array_filter(array_map(
                'absint',
                (array) $wpdb->get_col(
                    "SELECT p.ID
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$table} r
                       ON r.source_id = p.ID
                      AND r.source_type = 'post'
                      AND r.target_type = 'product_cat'
                      AND r.relation_type = 'post_to_category'
                     WHERE p.post_type = 'post'
                       AND r.source_id IS NULL"
                )
            ))));
        }

        $term_id = absint($filter);
        if ($term_id <= 0) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT source_id
                     FROM {$table}
                     WHERE source_type = 'post'
                       AND target_type = 'product_cat'
                       AND relation_type = 'post_to_category'
                       AND target_id = %d",
                    $term_id
                )
            )
        ))));
    }
}

if (!function_exists('seo_post_reports_render_metric')) {
    function seo_post_reports_render_metric($label, $value, $detail = '') {
        echo '<div class="seo-post-report-metric">';
        echo '<span class="seo-post-report-metric-label">' . esc_html($label) . '</span>';
        echo '<strong>' . wp_kses_post((string) $value) . '</strong>';
        if ('' !== $detail) {
            echo '<span class="seo-post-report-metric-detail">' . esc_html($detail) . '</span>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_post_reports_render_status')) {
    function seo_post_reports_render_status(array $google) {
        echo '<div class="seo-post-report-source-row">';
        $sources = [
            'Search Console'    => !empty($google['search_console']),
            'Analytics Data API'=> !empty($google['analytics']),
            'GA4 frontend'      => !empty($google['tracking_enabled']) && !empty($google['measurement_id']),
        ];
        foreach ($sources as $label => $ok) {
            echo '<span class="seo-post-report-badge ' . esc_attr($ok ? 'is-ok' : 'is-pending') . '"><strong>' . esc_html($label) . ':</strong> ' . esc_html($ok ? 'Disponible' : 'Pendiente') . '</span>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_post_reports_render_daily_chart')) {
    function seo_post_reports_render_daily_chart(array $rows) {
        if (empty($rows)) {
            echo '<p class="description">Search Console no ha devuelto actividad diaria para esta entrada en el periodo seleccionado.</p>';
            return;
        }

        $max_impressions = 1;
        foreach ($rows as $row) {
            $max_impressions = max($max_impressions, (int) ($row['impressions'] ?? 0));
        }

        echo '<div class="seo-post-report-bars" role="img" aria-label="Evolucion diaria de impresiones y clics">';
        foreach ($rows as $row) {
            $impressions = (int) ($row['impressions'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);
            $height = max(2, min(100, ($impressions / $max_impressions) * 100));
            $date = (string) ($row['date'] ?? '');
            $title = sprintf('%s · %d impresiones · %d clics', $date, $impressions, $clicks);
            echo '<div class="seo-post-report-bar-col" title="' . esc_attr($title) . '">';
            echo '<div class="seo-post-report-bar" style="height:' . esc_attr((string) $height) . '%"></div>';
            echo '<span>' . esc_html(substr($date, 5)) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_post_reports_render_post')) {
    function seo_post_reports_render_post($post_id) {
        $post_id = absint($post_id);
        $days = seo_post_reports_days($_GET['days'] ?? 28);
        $force = false;

        if (isset($_GET['refresh']) && '1' === (string) $_GET['refresh']) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            $force = wp_verify_nonce($nonce, 'seo_post_report_refresh_' . $post_id);
        }

        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post || 'post' !== $post->post_type) {
            echo '<div class="notice notice-error"><p>La entrada solicitada no existe.</p></div>';
            return;
        }

        // Mantiene disponible la puntuacion del periodo incluso al entrar directamente al informe.
        seo_post_reports_catalog_snapshot($days, false);

        $report = seo_post_reports_collect($post_id, $days, $force);
        if (is_wp_error($report)) {
            echo '<div class="notice notice-error"><p>' . esc_html($report->get_error_message()) . '</p></div>';
            return;
        }

        $summary = seo_post_reports_get_summary($post_id, $days);
        $score = max(0, min(100, absint($summary['score'] ?? 0)));
        $score_class = $score >= 70 ? 'is-high' : ($score >= 40 ? 'is-medium' : 'is-low');
        $refresh_url = wp_nonce_url(
            seo_post_reports_admin_url($post_id, $days, ['refresh' => 1]),
            'seo_post_report_refresh_' . $post_id
        );

        echo '<div class="seo-post-report-head">';
        echo '<div>';
        echo '<h2 style="margin:0 0 5px;">' . esc_html($post->post_title ?: '(Sin titulo)') . '</h2>';
        echo '<p style="margin:0;color:#646970;">ID ' . absint($post_id) . ' · <a href="' . esc_url($report['url']) . '" target="_blank" rel="noopener noreferrer">Ver entrada</a></p>';
        echo '</div>';
        echo '<div class="seo-post-report-actions"><span class="seo-post-report-score ' . esc_attr($score_class) . '">' . esc_html($score . '/100') . '</span><a class="button" href="' . esc_url(seo_post_reports_editor_url($post_id)) . '">Editar entrada</a><a class="button" href="' . esc_url($refresh_url) . '">Actualizar datos</a></div>';
        echo '</div>';

        seo_post_reports_render_status((array) $report['google']);

        $success = seo_post_reports_success_label($report);
        echo '<div class="seo-post-report-summary ' . esc_attr($success['class']) . '"><strong>' . esc_html($success['label']) . '</strong><span>' . esc_html($success['detail']) . '</span></div>';

        echo '<form method="get" class="seo-post-report-period">';
        echo '<input type="hidden" name="page" value="seo-post-reports">';
        echo '<input type="hidden" name="post_id" value="' . absint($post_id) . '">';
        echo '<label><strong>Periodo</strong> <select name="days">';
        foreach ([7 => '7 dias', 28 => '28 dias', 90 => '90 dias'] as $value => $label) {
            echo '<option value="' . absint($value) . '" ' . selected($days, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> <button class="button" type="submit">Aplicar</button>';
        echo '<span class="description">Datos del informe cacheados 15 minutos. La puntuacion es comparativa entre entradas del mismo sitio y periodo.</span>';
        echo '</form>';

        echo '<section class="seo-post-report-card">';
        echo '<div class="seo-post-report-title"><div><h3>Visibilidad en Google</h3><p>Search Console para la URL exacta de esta entrada.</p></div></div>';
        if (!empty($report['gsc']['error'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($report['gsc']['error']) . '</p></div>';
        }
        echo '<div class="seo-post-report-metrics">';
        seo_post_reports_render_metric('Impresiones', number_format_i18n((int) $report['gsc']['impressions']));
        seo_post_reports_render_metric('Clics desde Google', number_format_i18n((int) $report['gsc']['clicks']));
        seo_post_reports_render_metric('CTR', seo_post_reports_percent((float) $report['gsc']['ctr']));
        seo_post_reports_render_metric('Posicion media', number_format_i18n((float) $report['gsc']['position'], 1));
        echo '</div>';
        echo '<h4>Evolucion diaria</h4>';
        seo_post_reports_render_daily_chart((array) ($report['daily']['rows'] ?? []));
        echo '</section>';

        echo '<section class="seo-post-report-card">';
        echo '<div class="seo-post-report-title"><div><h3>Visitas a la entrada</h3><p>Actividad de la pagina medida por Google Analytics 4.</p></div></div>';
        if (!empty($report['ga4']['error'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($report['ga4']['error']) . '</p></div>';
        }
        echo '<div class="seo-post-report-metrics seo-post-report-metrics-three">';
        seo_post_reports_render_metric('Sesiones', number_format_i18n((int) $report['ga4']['sessions']));
        seo_post_reports_render_metric('Usuarios activos', number_format_i18n((int) $report['ga4']['users']));
        seo_post_reports_render_metric('Vistas de pagina', number_format_i18n((int) $report['ga4']['pageviews']));
        echo '</div>';
        echo '</section>';

        echo '<section class="seo-post-report-card">';
        echo '<div class="seo-post-report-title"><div><h3>Consultas que encuentran esta entrada</h3><p>Terminos de busqueda de Search Console para la URL del post.</p></div></div>';
        if (!empty($report['queries']['error'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($report['queries']['error']) . '</p></div>';
        }
        $query_rows = (array) ($report['queries']['rows'] ?? []);
        if (empty($query_rows)) {
            echo '<p class="description">No hay consultas disponibles para esta entrada en el periodo seleccionado.</p>';
        } else {
            echo '<div style="overflow:auto;"><table class="widefat striped"><thead><tr><th>Consulta</th><th>Clics</th><th>Impresiones</th><th>CTR</th><th>Posicion</th></tr></thead><tbody>';
            foreach ($query_rows as $row) {
                echo '<tr>';
                echo '<td><strong>' . esc_html((string) $row['query']) . '</strong></td>';
                echo '<td>' . esc_html(number_format_i18n((int) $row['clicks'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) $row['impressions'])) . '</td>';
                echo '<td>' . esc_html(seo_post_reports_percent((float) $row['ctr'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $row['position'], 1)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';

        $last_update = wp_date('Y-m-d H:i:s', absint($report['generated']));
        echo '<p class="description">Informe generado: ' . esc_html($last_update) . '. Search Console puede aplicar retrasos de procesamiento y limites de filas.</p>';
    }
}

if (!function_exists('seo_post_reports_category_option_tree')) {
    function seo_post_reports_category_option_tree($terms, $selected = '', $parent = 0, $depth = 0, $grouped = null) {
        if ($grouped === null) {
            $grouped = [];
            foreach ((array) $terms as $term) {
                if (!$term instanceof WP_Term) {
                    continue;
                }
                $term_parent = absint($term->parent);
                if (!isset($grouped[$term_parent])) {
                    $grouped[$term_parent] = [];
                }
                $grouped[$term_parent][] = $term;
            }
            foreach ($grouped as &$children) {
                usort($children, static function ($a, $b) {
                    return strcasecmp((string) $a->name, (string) $b->name);
                });
            }
            unset($children);
        }

        if (empty($grouped[$parent])) {
            return;
        }

        foreach ($grouped[$parent] as $term) {
            $label = str_repeat('— ', $depth) . $term->name;
            echo '<option value="' . esc_attr($term->term_id) . '" ' . selected((string) $selected, (string) $term->term_id, false) . '>' . esc_html($label) . '</option>';
            seo_post_reports_category_option_tree($terms, $selected, $term->term_id, $depth + 1, $grouped);
        }
    }
}

if (!function_exists('seo_post_reports_render_selector')) {
    function seo_post_reports_render_selector() {
        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $category_filter = isset($_GET['product_cat']) ? sanitize_text_field(wp_unslash($_GET['product_cat'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $days = seo_post_reports_days($_GET['days'] ?? 28);
        $score_order = isset($_GET['score_order']) && 'asc' === strtolower((string) $_GET['score_order']) ? 'asc' : 'desc';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 40;

        $allowed_statuses = ['publish', 'draft', 'pending', 'private'];
        if ($status !== '' && !in_array($status, $allowed_statuses, true)) {
            $status = '';
        }
        if ($category_filter !== '' && 'none' !== $category_filter && absint($category_filter) <= 0) {
            $category_filter = '';
        }

        $force_snapshot = false;
        if (isset($_GET['refresh_catalog']) && '1' === (string) $_GET['refresh_catalog']) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            $force_snapshot = wp_verify_nonce($nonce, 'seo_post_reports_refresh_catalog_' . $days);
        }
        $snapshot = seo_post_reports_catalog_snapshot($days, $force_snapshot);

        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        if (is_wp_error($categories)) {
            $categories = [];
        }

        $args = [
            'post_type'      => 'post',
            'post_status'    => $status !== '' ? [$status] : $allowed_statuses,
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'seo_post_reports_score_days'  => $days,
            'seo_post_reports_score_order' => $score_order,
        ];

        if ($search !== '') {
            if (ctype_digit($search) && 'post' === get_post_type(absint($search))) {
                $args['p'] = absint($search);
            } else {
                $args['s'] = $search;
            }
        }

        if ($category_filter !== '') {
            $relation_ids = seo_post_reports_relation_ids_for_product_cat($category_filter);
            $args['post__in'] = !empty($relation_ids) ? $relation_ids : [0];
        }

        add_filter('posts_clauses', 'seo_post_reports_score_posts_clauses', 20, 2);
        $query = new WP_Query($args);
        remove_filter('posts_clauses', 'seo_post_reports_score_posts_clauses', 20);

        $google = seo_post_reports_google_state();

        echo '<div class="seo-post-report-intro">';
        echo '<h2 style="margin:0 0 6px;">Informes Google por entrada</h2>';
        echo '<p style="margin:0;">Vista comparativa de posts. La puntuacion 0-100 combina impresiones, clics y visitas disponibles para ordenar el contenido por rendimiento. Es una puntuacion relativa al resto de entradas del sitio en el mismo periodo.</p>';
        seo_post_reports_render_status($google);
        echo '</div>';

        if (empty($google['service_loaded'])) {
            echo '<div class="notice notice-error inline"><p>El servicio Google del plugin no esta cargado. Comprueba el subsistema Importar/Exportar.</p></div>';
        } elseif (empty($google['search_console']) && empty($google['analytics'])) {
            echo '<div class="notice notice-warning inline"><p>La conexion Google aun no tiene Search Console o Analytics configurados con una cuenta de servicio.</p></div>';
        }

        if (!empty($snapshot['errors'])) {
            echo '<div class="notice notice-warning inline"><p><strong>Snapshot parcial:</strong> ' . esc_html(implode(' | ', (array) $snapshot['errors'])) . '</p></div>';
        }

        $refresh_url = wp_nonce_url(
            seo_post_reports_admin_url(0, $days, [
                'q'               => $search,
                'product_cat'     => $category_filter,
                'status'          => $status,
                'score_order'     => $score_order,
                'refresh_catalog' => 1,
            ]),
            'seo_post_reports_refresh_catalog_' . $days
        );

        echo '<div class="seo-post-report-catalog-status">';
        if (!empty($snapshot['available'])) {
            $generated = wp_date('Y-m-d H:i:s', absint($snapshot['generated'] ?? 0));
            echo '<span><strong>Estadisticas:</strong> ' . esc_html($days . ' dias') . ' · ' . esc_html(number_format_i18n((int) ($snapshot['matched_posts'] ?? 0))) . ' entradas con senales · actualizado ' . esc_html($generated) . '</span>';
        } else {
            echo '<span>No hay un snapshot agregado disponible todavia.</span>';
        }
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;"><a class="button" href="' . esc_url(seo_post_reports_editor_url()) . '">Editor de posts</a><a class="button" href="' . esc_url($refresh_url) . '">Actualizar estadisticas</a></div>';
        echo '</div>';

        echo '<form method="get" class="seo-post-report-filter">';
        echo '<input type="hidden" name="page" value="seo-post-reports">';
        echo '<input type="hidden" name="score_order" value="' . esc_attr($score_order) . '">';
        echo '<div><label>Buscar</label><input type="text" name="q" value="' . esc_attr($search) . '" placeholder="Titulo, contenido o ID"></div>';
        echo '<div><label>Categoria de producto</label><select name="product_cat"><option value="">Todas</option><option value="none" ' . selected($category_filter, 'none', false) . '>Sin relacion</option>';
        seo_post_reports_category_option_tree((array) $categories, $category_filter);
        echo '</select></div>';
        echo '<div><label>Estado</label><select name="status"><option value="">Todos</option>';
        foreach (['publish' => 'Publicado', 'draft' => 'Borrador', 'pending' => 'Pendiente', 'private' => 'Privado'] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($status, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div><label>Periodo</label><select name="days">';
        foreach ([7 => '7 dias', 28 => '28 dias', 90 => '90 dias'] as $value => $label) {
            echo '<option value="' . absint($value) . '" ' . selected($days, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="seo-post-report-filter-actions"><button class="button button-primary" type="submit">Filtrar</button><a class="button" href="' . esc_url(seo_post_reports_admin_url(0, $days)) . '">Limpiar</a></div>';
        echo '</form>';

        $toggle_score_order = 'desc' === $score_order ? 'asc' : 'desc';
        $score_sort_url = seo_post_reports_admin_url(0, $days, [
            'q'           => $search,
            'product_cat' => $category_filter,
            'status'      => $status,
            'score_order' => $toggle_score_order,
            'paged'       => 1,
        ]);
        $score_arrow = 'desc' === $score_order ? '↓' : '↑';

        echo '<div class="seo-post-report-table-wrap"><table class="widefat striped seo-post-report-table"><thead><tr>';
        echo '<th style="width:70px;">ID</th>';
        echo '<th>Entrada</th>';
        echo '<th style="width:130px;"><a href="' . esc_url($score_sort_url) . '" title="Cambiar orden por puntuacion">Puntuacion ' . esc_html($score_arrow) . '</a><br><small>' . esc_html($days . ' dias') . '</small></th>';
        echo '<th style="width:105px;">Impresiones</th>';
        echo '<th style="width:85px;">Clics</th>';
        echo '<th style="width:85px;">Visitas</th>';
        echo '<th style="width:115px;">Estado</th>';
        echo '<th style="width:170px;">Acciones</th>';
        echo '</tr></thead><tbody>';

        if (!$query->have_posts()) {
            echo '<tr><td colspan="8">No se han encontrado entradas con estos filtros.</td></tr>';
        } else {
            foreach ($query->posts as $post) {
                $summary = seo_post_reports_get_summary($post->ID, $days);
                $score = max(0, min(100, absint($summary['score'] ?? 0)));
                $score_class = $score >= 70 ? 'is-high' : ($score >= 40 ? 'is-medium' : 'is-low');
                $score_text = !empty($summary['has_snapshot']) ? $score . '/100' : '—';

                echo '<tr>';
                echo '<td>' . absint($post->ID) . '</td>';
                echo '<td><strong>' . esc_html($post->post_title ?: '(Sin titulo)') . '</strong>' . ($post->post_name ? '<div style="color:#646970;font-size:12px;margin-top:3px;"><code>' . esc_html($post->post_name) . '</code></div>' : '') . '</td>';
                echo '<td><span class="seo-post-report-score ' . esc_attr($score_class) . '">' . esc_html($score_text) . '</span></td>';
                echo '<td>' . esc_html(number_format_i18n((int) ($summary['impressions'] ?? 0))) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) ($summary['clicks'] ?? 0))) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) ($summary['pageviews'] ?? 0))) . '</td>';
                echo '<td>' . esc_html($post->post_status) . '</td>';
                echo '<td style="display:flex;gap:5px;flex-wrap:wrap;"><a class="button button-small" href="' . esc_url(seo_post_reports_editor_url($post->ID)) . '">Editar</a><a class="button button-small" href="' . esc_url(seo_post_reports_admin_url($post->ID, $days)) . '">Ver informe</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';

        if ($query->max_num_pages > 1) {
            $base_args = [
                'page'        => 'seo-post-reports',
                'q'           => $search,
                'product_cat' => $category_filter,
                'status'      => $status,
                'days'        => $days,
                'score_order' => $score_order,
                'paged'       => '%#%',
            ];
            echo '<div style="margin-top:18px;">';
            echo wp_kses_post(paginate_links([
                'base'      => esc_url_raw(add_query_arg($base_args, admin_url('edit.php'))),
                'format'    => '',
                'current'   => $paged,
                'total'     => max(1, (int) $query->max_num_pages),
                'type'      => 'list',
                'prev_text' => '«',
                'next_text' => '»',
            ]));
            echo '</div>';
        }

        wp_reset_postdata();
    }
}

if (!function_exists('seo_post_reports_page')) {
    function seo_post_reports_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;

        echo '<div style="padding:10px 0 30px;max-width:1280px;">';
        echo '<style>
        .seo-post-report-intro,.seo-post-report-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:0 0 18px}
        .seo-post-report-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:12px}
        .seo-post-report-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .seo-post-report-source-row{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 18px}
        .seo-post-report-summary{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;border-left:4px solid #dba617;background:#fff8e5;padding:10px 12px;margin:0 0 18px}
        .seo-post-report-summary.is-ok{border-left-color:#00a32a;background:#edfaef}.seo-post-report-summary span{color:#50575e}
        .seo-post-report-badge{display:inline-block;padding:5px 9px;border-radius:999px;font-size:12px;background:#f0f0f1}
        .seo-post-report-badge.is-ok{background:#edfaef;color:#1e4620}.seo-post-report-badge.is-pending{background:#fff8e5;color:#6d4f00}
        .seo-post-report-period{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;margin:0 0 18px}
        .seo-post-report-period select{min-width:120px}
        .seo-post-report-title h3{margin:0 0 4px;font-size:16px}.seo-post-report-title p{margin:0 0 14px;color:#646970}
        .seo-post-report-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:12px 0 18px}.seo-post-report-metrics-three{grid-template-columns:repeat(3,minmax(0,1fr))}
        .seo-post-report-metric{border:1px solid #dcdcde;border-radius:7px;padding:13px;background:#fbfbfc;min-width:0}
        .seo-post-report-metric-label,.seo-post-report-metric-detail{display:block;color:#646970;font-size:12px}.seo-post-report-metric strong{display:block;font-size:23px;line-height:1.2;margin:4px 0;overflow-wrap:anywhere}
        .seo-post-report-bars{height:180px;display:flex;align-items:flex-end;gap:3px;border-left:1px solid #dcdcde;border-bottom:1px solid #dcdcde;padding:8px 8px 22px;overflow-x:auto}
        .seo-post-report-bar-col{height:100%;min-width:18px;flex:1;display:flex;align-items:flex-end;position:relative}.seo-post-report-bar{width:100%;min-height:2px;background:#2271b1;border-radius:2px 2px 0 0}.seo-post-report-bar-col span{position:absolute;bottom:-19px;left:50%;transform:translateX(-50%);font-size:9px;color:#646970;white-space:nowrap}
        .seo-post-report-filter{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:18px 0;display:grid;grid-template-columns:2fr 1.3fr 1fr .8fr auto;gap:12px;align-items:end}
        .seo-post-report-catalog-status{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;margin:0 0 18px}
        .seo-post-report-table-wrap{overflow:auto}.seo-post-report-table{min-width:980px}.seo-post-report-table th small{font-weight:400;color:#646970}
        .seo-post-report-score{display:inline-block;min-width:58px;text-align:center;padding:5px 8px;border-radius:999px;font-weight:700;background:#f0f0f1;color:#50575e}.seo-post-report-score.is-medium{background:#fff8e5;color:#996800}.seo-post-report-score.is-high{background:#edfaef;color:#008a20}
        .seo-post-report-filter label{display:block;font-weight:600;margin-bottom:5px}.seo-post-report-filter input,.seo-post-report-filter select{width:100%}.seo-post-report-filter-actions{display:flex;gap:6px}
        @media(max-width:900px){.seo-post-report-metrics,.seo-post-report-metrics-three{grid-template-columns:repeat(2,minmax(0,1fr))}.seo-post-report-filter{grid-template-columns:1fr 1fr}}
        @media(max-width:600px){.seo-post-report-metrics,.seo-post-report-metrics-three,.seo-post-report-filter{grid-template-columns:1fr}.seo-post-report-metric strong{font-size:20px}}
        </style>';

        if ($post_id > 0) {
            echo '<p><a class="button" href="' . esc_url(seo_post_reports_admin_url()) . '">← Volver a entradas</a></p>';
            seo_post_reports_render_post($post_id);
        } else {
            seo_post_reports_render_selector();
        }
        echo '</div>';
    }
}

if (!function_exists('seo_post_reports_register_admin_page')) {
    function seo_post_reports_register_admin_page() {
        add_submenu_page(
            'edit.php',
            'Informes Google de posts',
            'Informes Google',
            'manage_options',
            'seo-post-reports',
            'seo_post_reports_page'
        );
    }
}
add_action('admin_menu', 'seo_post_reports_register_admin_page');
