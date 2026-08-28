<?php
/**
 * Informes Google por producto.
 *
 * Reutiliza la conexion centralizada de Google Search Console / Analytics
 * definida en includes/import-export/suppliers/google-search.php. Este modulo
 * no guarda ni conoce credenciales.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_product_reports_days')) {
    function seo_product_reports_days($value) {
        $value = absint($value);
        return in_array($value, [7, 28, 90], true) ? $value : 28;
    }
}

if (!function_exists('seo_product_reports_path_key')) {
    function seo_product_reports_path_key($url_or_path) {
        $path = (string) wp_parse_url((string) $url_or_path, PHP_URL_PATH);
        if ('' === $path) {
            $path = (string) $url_or_path;
        }

        $path = '/' . ltrim($path, '/');
        return untrailingslashit($path) ?: '/';
    }
}

if (!function_exists('seo_product_reports_google_state')) {
    function seo_product_reports_google_state() {
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

if (!function_exists('seo_product_reports_gsc_request')) {
    function seo_product_reports_gsc_request($url, $days, array $dimensions = [], $row_limit = 1000) {
        if (!function_exists('seo_google_search_console_query')) {
            return new WP_Error('seo_product_reports_gsc_unavailable', 'Search Console no esta disponible en la conexion Google actual.');
        }

        $days = seo_product_reports_days($days);
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

        if (!empty($dimensions)) {
            $request['dimensions'] = array_values($dimensions);
        } else {
            $request['dimensions'] = [];
        }

        return seo_google_search_console_query($request);
    }
}

if (!function_exists('seo_product_reports_gsc_summary')) {
    function seo_product_reports_gsc_summary($url, $days) {
        $empty = [
            'available'   => false,
            'error'       => '',
            'clicks'      => 0,
            'impressions' => 0,
            'ctr'         => 0.0,
            'position'    => 0.0,
        ];

        $report = seo_product_reports_gsc_request($url, $days, [], 1);
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

if (!function_exists('seo_product_reports_gsc_queries')) {
    function seo_product_reports_gsc_queries($url, $days) {
        $result = [
            'available' => false,
            'error'     => '',
            'rows'      => [],
        ];

        $report = seo_product_reports_gsc_request($url, $days, ['query'], 100);
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

if (!function_exists('seo_product_reports_gsc_daily')) {
    function seo_product_reports_gsc_daily($url, $days) {
        $result = [
            'available' => false,
            'error'     => '',
            'rows'      => [],
        ];

        $report = seo_product_reports_gsc_request($url, $days, ['date'], 500);
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

if (!function_exists('seo_product_reports_ga_page')) {
    function seo_product_reports_ga_page($url, $days) {
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

        $days = seo_product_reports_days($days);
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        if ('' === $path) {
            $path = '/';
        } else {
            $path = '/' . ltrim($path, '/');
        }
        $report = seo_google_analytics_run_report([
            'dateRanges' => [
                [
                    'startDate' => max(1, $days - 1) . 'daysAgo',
                    'endDate'   => 'today',
                ],
            ],
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

if (!function_exists('seo_product_reports_ga_ecommerce')) {
    function seo_product_reports_ga_ecommerce($product, $days) {
        $empty = [
            'available'     => false,
            'error'         => '',
            'matched_by'    => '',
            'items_viewed'  => 0,
            'added_to_cart' => 0,
            'purchased'     => 0,
            'revenue'       => 0.0,
        ];

        if (!function_exists('seo_google_analytics_run_report') || !$product) {
            $empty['error'] = 'Analytics Data API no esta disponible para las metricas ecommerce.';
            return $empty;
        }

        $days = seo_product_reports_days($days);
        $product_id = absint($product->get_id());
        $sku = trim((string) $product->get_sku('edit'));
        $name = trim((string) $product->get_name('edit'));

        $expressions = [];
        if ('' !== $sku) {
            $expressions[] = [
                'filter' => [
                    'fieldName' => 'itemId',
                    'stringFilter' => [
                        'matchType' => 'EXACT',
                        'value' => $sku,
                        'caseSensitive' => false,
                    ],
                ],
            ];
        }
        if ($product_id > 0) {
            $expressions[] = [
                'filter' => [
                    'fieldName' => 'itemId',
                    'stringFilter' => [
                        'matchType' => 'EXACT',
                        'value' => (string) $product_id,
                        'caseSensitive' => false,
                    ],
                ],
            ];
        }
        if ('' !== $name) {
            $expressions[] = [
                'filter' => [
                    'fieldName' => 'itemName',
                    'stringFilter' => [
                        'matchType' => 'EXACT',
                        'value' => $name,
                        'caseSensitive' => false,
                    ],
                ],
            ];
        }

        if (empty($expressions)) {
            $empty['error'] = 'El producto no tiene identificadores suficientes para cruzarlo con ecommerce de GA4.';
            return $empty;
        }

        $dimension_filter = count($expressions) === 1
            ? $expressions[0]
            : ['orGroup' => ['expressions' => $expressions]];

        $report = seo_google_analytics_run_report([
            'dateRanges' => [
                [
                    'startDate' => max(1, $days - 1) . 'daysAgo',
                    'endDate'   => 'today',
                ],
            ],
            'dimensions' => [
                ['name' => 'itemId'],
                ['name' => 'itemName'],
            ],
            'metrics' => [
                ['name' => 'itemsViewed'],
                ['name' => 'itemsAddedToCart'],
                ['name' => 'itemsPurchased'],
                ['name' => 'itemRevenue'],
            ],
            'dimensionFilter' => $dimension_filter,
            'limit' => 100,
        ]);

        if (is_wp_error($report)) {
            $empty['error'] = $report->get_error_message();
            return $empty;
        }

        $empty['available'] = true;
        foreach ((array) ($report['rows'] ?? []) as $row) {
            $item_id = (string) ($row['dimensionValues'][0]['value'] ?? '');
            $item_name = (string) ($row['dimensionValues'][1]['value'] ?? '');

            if ('' === $empty['matched_by']) {
                if ('' !== $sku && 0 === strcasecmp($item_id, $sku)) {
                    $empty['matched_by'] = 'SKU';
                } elseif ($product_id > 0 && $item_id === (string) $product_id) {
                    $empty['matched_by'] = 'ID de producto';
                } elseif ('' !== $name && 0 === strcasecmp($item_name, $name)) {
                    $empty['matched_by'] = 'nombre del producto';
                }
            }

            $empty['items_viewed'] += (int) ($row['metricValues'][0]['value'] ?? 0);
            $empty['added_to_cart'] += (int) ($row['metricValues'][1]['value'] ?? 0);
            $empty['purchased'] += (int) ($row['metricValues'][2]['value'] ?? 0);
            $empty['revenue'] += (float) ($row['metricValues'][3]['value'] ?? 0);
        }

        return $empty;
    }
}

if (!function_exists('seo_product_reports_collect')) {
    function seo_product_reports_collect($product_id, $days = 28, $force = false) {
        $product_id = absint($product_id);
        $days = seo_product_reports_days($days);
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;

        if (!$product) {
            return new WP_Error('seo_product_reports_invalid_product', 'El producto solicitado no existe.');
        }

        $url = get_permalink($product_id);
        if (!$url) {
            return new WP_Error('seo_product_reports_no_permalink', 'No se pudo resolver la URL publica del producto.');
        }

        $cache_key = 'seo_product_google_report_v1_' . get_current_blog_id() . '_' . $product_id . '_' . $days;
        if ($force) {
            delete_transient($cache_key);
        } else {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = [
            'product_id' => $product_id,
            'days'       => $days,
            'url'        => $url,
            'generated'  => current_time('timestamp'),
            'google'     => seo_product_reports_google_state(),
            'gsc'        => seo_product_reports_gsc_summary($url, $days),
            'queries'    => seo_product_reports_gsc_queries($url, $days),
            'daily'      => seo_product_reports_gsc_daily($url, $days),
            'ga4'        => seo_product_reports_ga_page($url, $days),
            'ecommerce'  => seo_product_reports_ga_ecommerce($product, $days),
        ];

        set_transient($cache_key, $data, 15 * MINUTE_IN_SECONDS);
        return $data;
    }
}

if (!function_exists('seo_product_reports_percent')) {
    function seo_product_reports_percent($ratio, $decimals = 1) {
        return number_format_i18n((float) $ratio * 100, absint($decimals)) . '%';
    }
}

if (!function_exists('seo_product_reports_money')) {
    function seo_product_reports_money($amount) {
        $amount = (float) $amount;
        if (function_exists('wc_price')) {
            return wp_kses_post(wc_price($amount));
        }
        return esc_html(number_format_i18n($amount, 2));
    }
}

if (!function_exists('seo_product_reports_admin_url')) {
    function seo_product_reports_admin_url($product_id = 0, $days = 28, array $extra = []) {
        $args = [
            'page' => 'product-page-admin',
            'tab'  => 'informes',
        ];
        if (absint($product_id) > 0) {
            $args['product_id'] = absint($product_id);
            $args['days'] = seo_product_reports_days($days);
        }
        return add_query_arg(array_merge($args, $extra), admin_url('admin.php'));
    }
}

if (!function_exists('seo_product_reports_success_label')) {
    function seo_product_reports_success_label(array $report) {
        $purchased = (int) ($report['ecommerce']['purchased'] ?? 0);
        $cart = (int) ($report['ecommerce']['added_to_cart'] ?? 0);
        $clicks = (int) ($report['gsc']['clicks'] ?? 0);
        $impressions = (int) ($report['gsc']['impressions'] ?? 0);
        $pageviews = (int) ($report['ga4']['pageviews'] ?? 0);

        if ($purchased > 0) {
            return ['label' => 'Convierte', 'detail' => 'GA4 registra compras de este articulo en el periodo.', 'class' => 'is-ok'];
        }
        if ($cart > 0) {
            return ['label' => 'Interes comercial', 'detail' => 'Hay usuarios que anaden el articulo al carrito, pero no se registran compras.', 'class' => 'is-ok'];
        }
        if ($clicks > 0) {
            return ['label' => 'Recibe trafico organico', 'detail' => 'Google Search genera clics hacia esta ficha de producto.', 'class' => 'is-ok'];
        }
        if ($impressions > 0) {
            return ['label' => 'Visible sin clics', 'detail' => 'El producto aparece en Google, pero no ha recibido clics en el periodo.', 'class' => 'is-pending'];
        }
        if ($pageviews > 0) {
            return ['label' => 'Tiene visitas', 'detail' => 'GA4 registra vistas, aunque Search Console no atribuye clics organicos en el periodo.', 'class' => 'is-pending'];
        }
        return ['label' => 'Sin senales', 'detail' => 'No hay impresiones, clics ni visitas registradas para este periodo.', 'class' => 'is-pending'];
    }
}

if (!function_exists('seo_product_reports_render_metric')) {
    function seo_product_reports_render_metric($label, $value, $detail = '') {
        echo '<div class="seo-product-report-metric">';
        echo '<span class="seo-product-report-metric-label">' . esc_html($label) . '</span>';
        echo '<strong>' . wp_kses_post((string) $value) . '</strong>';
        if ('' !== $detail) {
            echo '<span class="seo-product-report-metric-detail">' . esc_html($detail) . '</span>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_product_reports_render_status')) {
    function seo_product_reports_render_status(array $google) {
        echo '<div class="seo-product-report-source-row">';

        $sources = [
            'Search Console' => !empty($google['search_console']),
            'Analytics Data API' => !empty($google['analytics']),
            'GA4 frontend' => !empty($google['tracking_enabled']) && !empty($google['measurement_id']),
        ];

        foreach ($sources as $label => $ok) {
            $class = $ok ? 'is-ok' : 'is-pending';
            $text = $ok ? 'Disponible' : 'Pendiente';
            echo '<span class="seo-product-report-badge ' . esc_attr($class) . '"><strong>' . esc_html($label) . ':</strong> ' . esc_html($text) . '</span>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_product_reports_render_daily_chart')) {
    function seo_product_reports_render_daily_chart(array $rows) {
        if (empty($rows)) {
            echo '<p class="description">Search Console no ha devuelto actividad diaria para este producto en el periodo seleccionado.</p>';
            return;
        }

        $max_impressions = 1;
        foreach ($rows as $row) {
            $max_impressions = max($max_impressions, (int) ($row['impressions'] ?? 0));
        }

        echo '<div class="seo-product-report-bars" role="img" aria-label="Evolucion diaria de impresiones y clics">';
        foreach ($rows as $row) {
            $impressions = (int) ($row['impressions'] ?? 0);
            $clicks = (int) ($row['clicks'] ?? 0);
            $height = max(2, min(100, ($impressions / $max_impressions) * 100));
            $date = (string) ($row['date'] ?? '');
            $title = sprintf('%s · %d impresiones · %d clics', $date, $impressions, $clicks);
            echo '<div class="seo-product-report-bar-col" title="' . esc_attr($title) . '">';
            echo '<div class="seo-product-report-bar" style="height:' . esc_attr((string) $height) . '%"></div>';
            echo '<span>' . esc_html(substr($date, 5)) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_product_reports_render_product')) {
    function seo_product_reports_render_product($product_id) {
        $product_id = absint($product_id);
        $days = seo_product_reports_days($_GET['days'] ?? 28);
        $force = false;

        if (isset($_GET['refresh']) && '1' === (string) $_GET['refresh']) {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            $force = wp_verify_nonce($nonce, 'seo_product_report_refresh_' . $product_id);
        }

        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) {
            echo '<div class="notice notice-error"><p>El producto solicitado no existe.</p></div>';
            return;
        }

        $report = seo_product_reports_collect($product_id, $days, $force);
        if (is_wp_error($report)) {
            echo '<div class="notice notice-error"><p>' . esc_html($report->get_error_message()) . '</p></div>';
            return;
        }

        $sku = (string) $product->get_sku('edit');
        $edit_url = add_query_arg([
            'page' => 'product-page-admin',
            'tab' => 'editar',
            'product_id' => $product_id,
        ], admin_url('admin.php'));
        $refresh_url = wp_nonce_url(
            seo_product_reports_admin_url($product_id, $days, ['refresh' => 1]),
            'seo_product_report_refresh_' . $product_id
        );

        echo '<div class="seo-product-report-head">';
        echo '<div>';
        echo '<h2 style="margin:0 0 5px;">' . esc_html($product->get_name('edit')) . '</h2>';
        echo '<p style="margin:0;color:#646970;">ID ' . absint($product_id) . ($sku !== '' ? ' · SKU ' . esc_html($sku) : '') . ' · <a href="' . esc_url($report['url']) . '" target="_blank" rel="noopener noreferrer">Ver producto</a></p>';
        echo '</div>';
        echo '<div class="seo-product-report-actions"><a class="button" href="' . esc_url($edit_url) . '">Editar producto</a><a class="button" href="' . esc_url($refresh_url) . '">Actualizar datos</a></div>';
        echo '</div>';

        seo_product_reports_render_status((array) $report['google']);

        $success = seo_product_reports_success_label($report);
        echo '<div class="seo-product-report-summary ' . esc_attr($success['class']) . '"><strong>' . esc_html($success['label']) . '</strong><span>' . esc_html($success['detail']) . '</span></div>';

        echo '<form method="get" class="seo-product-report-period">';
        echo '<input type="hidden" name="page" value="product-page-admin">';
        echo '<input type="hidden" name="tab" value="informes">';
        echo '<input type="hidden" name="product_id" value="' . absint($product_id) . '">';
        echo '<label><strong>Periodo</strong> <select name="days">';
        foreach ([7 => '7 dias', 28 => '28 dias', 90 => '90 dias'] as $value => $label) {
            echo '<option value="' . absint($value) . '" ' . selected($days, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label> <button class="button" type="submit">Aplicar</button>';
        echo '<span class="description">Datos cacheados 15 minutos. "Actualizar datos" fuerza una nueva consulta.</span>';
        echo '</form>';

        echo '<section class="seo-product-report-card">';
        echo '<div class="seo-product-report-title"><div><h3>Visibilidad en Google</h3><p>Search Console para la URL exacta del producto.</p></div></div>';
        if (!empty($report['gsc']['error'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($report['gsc']['error']) . '</p></div>';
        }
        echo '<div class="seo-product-report-metrics">';
        seo_product_reports_render_metric('Impresiones', number_format_i18n((int) $report['gsc']['impressions']));
        seo_product_reports_render_metric('Clics desde Google', number_format_i18n((int) $report['gsc']['clicks']));
        seo_product_reports_render_metric('CTR', seo_product_reports_percent((float) $report['gsc']['ctr']));
        seo_product_reports_render_metric('Posicion media', number_format_i18n((float) $report['gsc']['position'], 1));
        echo '</div>';
        echo '<h4>Evolucion diaria</h4>';
        seo_product_reports_render_daily_chart((array) ($report['daily']['rows'] ?? []));
        echo '</section>';

        echo '<section class="seo-product-report-card">';
        echo '<div class="seo-product-report-title"><div><h3>Visitas al producto</h3><p>Actividad de la ficha medida por Google Analytics 4.</p></div></div>';
        if (!empty($report['ga4']['error'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($report['ga4']['error']) . '</p></div>';
        }
        echo '<div class="seo-product-report-metrics">';
        seo_product_reports_render_metric('Sesiones', number_format_i18n((int) $report['ga4']['sessions']));
        seo_product_reports_render_metric('Usuarios activos', number_format_i18n((int) $report['ga4']['users']));
        seo_product_reports_render_metric('Vistas de pagina', number_format_i18n((int) $report['ga4']['pageviews']));
        echo '</div>';
        echo '</section>';

        echo '<section class="seo-product-report-card">';
        echo '<div class="seo-product-report-title"><div><h3>Rendimiento ecommerce</h3><p>Se muestra cuando GA4 recibe eventos ecommerce con item_id / item_name.</p></div></div>';
        if (!empty($report['ecommerce']['error'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($report['ecommerce']['error']) . '</p></div>';
        }
        echo '<div class="seo-product-report-metrics">';
        seo_product_reports_render_metric('Vistas de articulo', number_format_i18n((int) $report['ecommerce']['items_viewed']));
        seo_product_reports_render_metric('Anadidos al carrito', number_format_i18n((int) $report['ecommerce']['added_to_cart']));
        seo_product_reports_render_metric('Articulos comprados', number_format_i18n((int) $report['ecommerce']['purchased']));
        seo_product_reports_render_metric('Ingresos del articulo', seo_product_reports_money((float) $report['ecommerce']['revenue']), !empty($report['ecommerce']['matched_by']) ? 'Cruce por ' . $report['ecommerce']['matched_by'] : 'Sin coincidencias ecommerce');
        echo '</div>';
        echo '</section>';

        echo '<section class="seo-product-report-card">';
        echo '<div class="seo-product-report-title"><div><h3>Consultas que encuentran este producto</h3><p>Terminos de busqueda de Search Console, ordenados por los datos que devuelve Google.</p></div></div>';
        if (!empty($report['queries']['error'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($report['queries']['error']) . '</p></div>';
        }

        $query_rows = (array) ($report['queries']['rows'] ?? []);
        if (empty($query_rows)) {
            echo '<p class="description">No hay consultas disponibles para este producto en el periodo seleccionado.</p>';
        } else {
            echo '<div style="overflow:auto;"><table class="widefat striped"><thead><tr><th>Consulta</th><th>Clics</th><th>Impresiones</th><th>CTR</th><th>Posicion</th></tr></thead><tbody>';
            foreach ($query_rows as $row) {
                echo '<tr>';
                echo '<td><strong>' . esc_html((string) $row['query']) . '</strong></td>';
                echo '<td>' . esc_html(number_format_i18n((int) $row['clicks'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) $row['impressions'])) . '</td>';
                echo '<td>' . esc_html(seo_product_reports_percent((float) $row['ctr'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) $row['position'], 1)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</section>';

        $last_update = wp_date('Y-m-d H:i:s', absint($report['generated']));
        echo '<p class="description">Informe generado: ' . esc_html($last_update) . '. Search Console puede aplicar sus propios limites de filas y retrasos de procesamiento.</p>';
    }
}

if (!function_exists('seo_product_reports_render_selector')) {
    function seo_product_reports_render_selector() {
        global $wpdb;

        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $category_id = isset($_GET['cat']) ? absint($_GET['cat']) : 0;
        $provider = isset($_GET['provider']) ? sanitize_text_field(wp_unslash($_GET['provider'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 40;

        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);
        if (is_wp_error($categories)) {
            $categories = [];
        }

        $providers = function_exists('seo_product_get_provider_suggestions')
            ? seo_product_get_provider_suggestions()
            : [];

        $args = [
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'orderby' => 'modified',
            'order' => 'DESC',
        ];

        if ($category_id > 0) {
            $args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => [$category_id],
            ]];
        }

        if ('' !== $provider) {
            $args['meta_query'] = [[
                'key' => '_seo_proveedor',
                'value' => $provider,
            ]];
        }

        if ('' !== $search) {
            $forced_ids = [];
            if (ctype_digit($search) && 'product' === get_post_type(absint($search))) {
                $forced_ids[] = absint($search);
            }
            if (function_exists('wc_get_product_id_by_sku')) {
                $sku_id = absint(wc_get_product_id_by_sku($search));
                if ($sku_id > 0) {
                    $forced_ids[] = $sku_id;
                }
            }

            $title_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title LIKE %s ORDER BY post_modified DESC LIMIT 100",
                    '%' . $wpdb->esc_like($search) . '%'
                )
            );
            $sku_like_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s LIMIT 100",
                    '%' . $wpdb->esc_like($search) . '%'
                )
            );
            $forced_ids = array_values(array_unique(array_filter(array_map('absint', array_merge($forced_ids, $title_ids, $sku_like_ids)))));
            $args['post__in'] = !empty($forced_ids) ? $forced_ids : [0];
        }

        $query = new WP_Query($args);
        $google = seo_product_reports_google_state();

        echo '<div class="seo-product-report-intro">';
        echo '<h2 style="margin:0 0 6px;">Informes Google por producto</h2>';
        echo '<p style="margin:0;">Selecciona un producto para ver impresiones, clics, consultas, visitas de GA4 y, cuando exista medicion ecommerce, carrito, compras e ingresos.</p>';
        seo_product_reports_render_status($google);
        echo '</div>';

        if (empty($google['service_loaded'])) {
            echo '<div class="notice notice-error inline"><p>El servicio Google del plugin no esta cargado. Comprueba el subsistema Importar/Exportar.</p></div>';
        } elseif (empty($google['search_console']) && empty($google['analytics'])) {
            echo '<div class="notice notice-warning inline"><p>La conexion Google aun no tiene Search Console o Analytics configurados con una cuenta de servicio. Configurala en Importar / Exportar &gt; Conexiones.</p></div>';
        }

        echo '<form method="get" class="seo-product-report-filter">';
        echo '<input type="hidden" name="page" value="product-page-admin">';
        echo '<input type="hidden" name="tab" value="informes">';
        echo '<div><label>Buscar</label><input type="text" name="q" value="' . esc_attr($search) . '" placeholder="Titulo, SKU o ID"></div>';
        echo '<div><label>Categoria</label><select name="cat"><option value="">Todas</option>';
        if (function_exists('seo_product_category_option_tree')) {
            seo_product_category_option_tree((array) $categories, 0, $category_id ? [$category_id] : []);
        }
        echo '</select></div>';
        echo '<div><label>Proveedor</label><select name="provider"><option value="">Todos</option>';
        foreach ($providers as $provider_option) {
            echo '<option value="' . esc_attr($provider_option) . '" ' . selected($provider, $provider_option, false) . '>' . esc_html($provider_option) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="seo-product-report-filter-actions"><button class="button button-primary" type="submit">Filtrar</button><a class="button" href="' . esc_url(seo_product_reports_admin_url()) . '">Limpiar</a></div>';
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr><th style="width:70px;">ID</th><th style="width:160px;">SKU</th><th>Producto</th><th style="width:180px;">Proveedor</th><th style="width:115px;">Estado</th><th style="width:120px;">Accion</th></tr></thead><tbody>';
        if (!$query->have_posts()) {
            echo '<tr><td colspan="6">No se han encontrado productos con estos filtros.</td></tr>';
        } else {
            foreach ($query->posts as $post) {
                $wc_product = wc_get_product($post->ID);
                $sku = $wc_product ? (string) $wc_product->get_sku('edit') : '';
                $row_provider = (string) get_post_meta($post->ID, '_seo_proveedor', true);
                echo '<tr>';
                echo '<td>' . absint($post->ID) . '</td>';
                echo '<td><code>' . esc_html($sku ?: '—') . '</code></td>';
                echo '<td><strong>' . esc_html($post->post_title) . '</strong></td>';
                echo '<td>' . esc_html($row_provider ?: '—') . '</td>';
                echo '<td>' . esc_html($post->post_status) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url(seo_product_reports_admin_url($post->ID, 28)) . '">Ver informe</a></td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';

        if ($query->max_num_pages > 1) {
            $base_args = [
                'page' => 'product-page-admin',
                'tab' => 'informes',
                'q' => $search,
                'cat' => $category_id,
                'provider' => $provider,
                'paged' => '%#%',
            ];
            echo '<div style="margin-top:18px;">';
            echo wp_kses_post(paginate_links([
                'base' => esc_url_raw(add_query_arg($base_args, admin_url('admin.php'))),
                'format' => '',
                'current' => $paged,
                'total' => max(1, (int) $query->max_num_pages),
                'type' => 'list',
                'prev_text' => '«',
                'next_text' => '»',
            ]));
            echo '</div>';
        }

        wp_reset_postdata();
    }
}

if (!function_exists('seo_product_reports_page')) {
    function seo_product_reports_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;

        echo '<style>
        .seo-product-report-intro,.seo-product-report-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:0 0 18px}
        .seo-product-report-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:12px}
        .seo-product-report-actions{display:flex;gap:8px;flex-wrap:wrap}
        .seo-product-report-source-row{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 18px}
        .seo-product-report-summary{display:flex;gap:8px;align-items:baseline;flex-wrap:wrap;border-left:4px solid #dba617;background:#fff8e5;padding:10px 12px;margin:0 0 18px}
        .seo-product-report-summary.is-ok{border-left-color:#00a32a;background:#edfaef}.seo-product-report-summary span{color:#50575e}
        .seo-product-report-badge{display:inline-block;padding:5px 9px;border-radius:999px;font-size:12px;background:#f0f0f1}
        .seo-product-report-badge.is-ok{background:#edfaef;color:#1e4620}
        .seo-product-report-badge.is-pending{background:#fff8e5;color:#6d4f00}
        .seo-product-report-period{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px;margin:0 0 18px}
        .seo-product-report-period select{min-width:120px}
        .seo-product-report-title h3{margin:0 0 4px;font-size:16px}.seo-product-report-title p{margin:0 0 14px;color:#646970}
        .seo-product-report-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:12px 0 18px}
        .seo-product-report-metric{border:1px solid #dcdcde;border-radius:7px;padding:13px;background:#fbfbfc;min-width:0}
        .seo-product-report-metric-label,.seo-product-report-metric-detail{display:block;color:#646970;font-size:12px}
        .seo-product-report-metric strong{display:block;font-size:23px;line-height:1.2;margin:4px 0;overflow-wrap:anywhere}
        .seo-product-report-bars{height:180px;display:flex;align-items:flex-end;gap:3px;border-left:1px solid #dcdcde;border-bottom:1px solid #dcdcde;padding:8px 8px 22px;overflow-x:auto}
        .seo-product-report-bar-col{height:100%;min-width:18px;flex:1;display:flex;align-items:flex-end;position:relative}
        .seo-product-report-bar{width:100%;min-height:2px;background:#2271b1;border-radius:2px 2px 0 0}
        .seo-product-report-bar-col span{position:absolute;bottom:-19px;left:50%;transform:translateX(-50%);font-size:9px;color:#646970;white-space:nowrap}
        .seo-product-report-filter{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:18px 0;display:grid;grid-template-columns:2fr 1.2fr 1.2fr auto;gap:12px;align-items:end}
        .seo-product-report-filter label{display:block;font-weight:600;margin-bottom:5px}.seo-product-report-filter input,.seo-product-report-filter select{width:100%}
        .seo-product-report-filter-actions{display:flex;gap:6px}
        @media(max-width:900px){.seo-product-report-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.seo-product-report-filter{grid-template-columns:1fr 1fr}}
        @media(max-width:600px){.seo-product-report-metrics,.seo-product-report-filter{grid-template-columns:1fr}.seo-product-report-metric strong{font-size:20px}}
        </style>';

        if ($product_id > 0) {
            echo '<p><a class="button" href="' . esc_url(seo_product_reports_admin_url()) . '">← Volver a productos</a></p>';
            seo_product_reports_render_product($product_id);
        } else {
            seo_product_reports_render_selector();
        }
    }
}
