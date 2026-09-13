<?php
/**
 * Analista 3.1 - informes parciales bajo demanda.
 *
 * La pantalla principal no ejecuta calculos SEO pesados. Cada bloque se
 * genera exclusivamente cuando el administrador pulsa su boton y llega una
 * peticion AJAX autenticada.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_lazy_reports')) {
    function seo_analista_lazy_reports() {
        return array(
            'resumen' => array(
                'label' => 'Resumen',
                'description' => 'Visibilidad, evolución, GA4, búsqueda interna y arquitectura general.',
            ),
            'search_console' => array(
                'label' => 'Search Console',
                'description' => 'Consultas, páginas, posiciones, distribución y movimiento orgánico.',
            ),
            'contenido' => array(
                'label' => 'Contenido',
                'description' => 'Posts, páginas y productos que conviene mejorar o impulsar.',
            ),
            'estructura' => array(
                'label' => 'Estructura',
                'description' => 'Categorías, hubs y clusters que necesitan literatura, relaciones o impulso.',
            ),
            'catalogo' => array(
                'label' => 'Catálogo',
                'description' => 'Huecos de surtido, familias y oportunidades detectadas contra la demanda.',
            ),
            'tendencias' => array(
                'label' => 'Tendencias',
                'description' => 'Google Trends y aceleraciones de Search Console, solo cuando lo solicitas.',
            ),
            'trabajo' => array(
                'label' => 'Guion de trabajo',
                'description' => 'Prioridades ejecutivas combinadas para decidir qué hacer primero.',
            ),
            'competencia' => array(
                'label' => 'Competencia',
                'description' => 'Seguimiento propio y comparación externa cuando exista fuente conectada.',
            ),
            'proveedores' => array(
                'label' => 'Proveedores',
                'description' => 'Estado de feeds, sincronización y avisos que pueden afectar al catálogo.',
            ),
            'fuentes' => array(
                'label' => 'Fuentes',
                'description' => 'Diagnóstico de disponibilidad y calidad de las fuentes de datos.',
            ),
        );
    }
}

if (!function_exists('seo_analista_lazy_render_shell')) {
    function seo_analista_lazy_render_shell($days = 28) {
        $days = seo_analista_days($days);
        $reports = seo_analista_lazy_reports();
        $nonce = wp_create_nonce('seo_analista_partial_report');
        $ajax_url = admin_url('admin-ajax.php');

        echo '<div class="seo-analista-lazy-intro">';
        echo '<div><strong>Analista ' . esc_html(defined('SEO_ANALISTA_VERSION') ? SEO_ANALISTA_VERSION : '') . '</strong> · carga bajo demanda</div>';
        echo '<p>Al abrir esta página no se ejecuta ningún informe pesado. Elige un bloque y Analista calculará únicamente ese informe.</p>';
        echo '</div>';

        echo '<div class="seo-analista-lazy-controls">';
        foreach ($reports as $key => $report) {
            echo '<button type="button" class="button seo-analista-lazy-button" data-report="' . esc_attr($key) . '">';
            echo '<strong>' . esc_html($report['label']) . '</strong>';
            echo '<span>' . esc_html($report['description']) . '</span>';
            echo '</button>';
        }
        echo '</div>';

        echo '<div id="seo-analista-lazy-status" class="seo-analista-lazy-status" aria-live="polite"></div>';
        echo '<div id="seo-analista-lazy-result" class="seo-analista-lazy-result">';
        echo '<div class="seo-analista-lazy-empty"><strong>Ningún informe cargado.</strong><p>Pulsa uno de los botones anteriores para ejecutar solo ese análisis.</p></div>';
        echo '</div>';

        ?>
        <script>
        jQuery(function($) {
            var running = false;
            var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            var nonce = <?php echo wp_json_encode($nonce); ?>;
            var days = <?php echo absint($days); ?>;

            $('.seo-analista-lazy-button').on('click', function() {
                if (running) {
                    return;
                }

                var button = $(this);
                var report = button.data('report');
                var label = button.find('strong').first().text();

                running = true;
                $('.seo-analista-lazy-button').prop('disabled', true).removeClass('is-running');
                button.addClass('is-running');
                $('#seo-analista-lazy-result').empty();
                $('#seo-analista-lazy-status').html('<span class="spinner is-active"></span><strong>Generando ' + $('<div>').text(label).html() + '…</strong> Solo se está ejecutando este bloque.');

                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    dataType: 'json',
                    timeout: 240000,
                    data: {
                        action: 'seo_analista_partial_report',
                        nonce: nonce,
                        report: report,
                        days: days
                    }
                }).done(function(response) {
                    if (response && response.success) {
                        var seconds = response.data && response.data.seconds ? response.data.seconds : 0;
                        var version = response.data && response.data.version ? response.data.version : '';
                        $('#seo-analista-lazy-status').html('<strong>' + $('<div>').text(label).html() + ' completado.</strong> ' + seconds + ' s' + (version ? ' · Analista ' + $('<div>').text(version).html() : ''));
                        $('#seo-analista-lazy-result').html(response.data.html || '');
                    } else {
                        var message = response && response.data && response.data.message ? response.data.message : 'No se pudo generar el informe.';
                        $('#seo-analista-lazy-status').html('<div class="notice notice-error inline"><p>' + $('<div>').text(message).html() + '</p></div>');
                    }
                }).fail(function(xhr, textStatus) {
                    var message = 'Error AJAX al generar el informe.';
                    if (textStatus === 'timeout') {
                        message = 'El informe superó 240 segundos. Este bloque necesita dividirse todavía más.';
                    } else if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        message = xhr.responseJSON.data.message;
                    }
                    $('#seo-analista-lazy-status').html('<div class="notice notice-error inline"><p>' + $('<div>').text(message).html() + '</p></div>');
                }).always(function() {
                    running = false;
                    $('.seo-analista-lazy-button').prop('disabled', false).removeClass('is-running');
                });
            });
        });
        </script>
        <?php
    }
}

if (!function_exists('seo_analista_lazy_render_search_console')) {
    function seo_analista_lazy_render_search_console($days) {
        $data = seo_analista_get_data($days);
        $evolution = seo_analista_evolution_snapshot($days);
        $current = (array) ($data['current'] ?? array());
        $previous = (array) ($data['previous'] ?? array());

        if (empty($data['ready'])) {
            echo '<div class="notice notice-warning inline"><p>Search Console no tiene datos utilizables para este periodo.</p></div>';
            return;
        }

        echo '<div class="seo-analista-grid">';
        seo_analista_render_metric_card('Impresiones', number_format_i18n((float) ($current['impressions'] ?? 0), 0), seo_analista_metric_delta($current['impressions'] ?? 0, $previous['impressions'] ?? 0));
        seo_analista_render_metric_card('Clics', number_format_i18n((float) ($current['clicks'] ?? 0), 0), seo_analista_metric_delta($current['clicks'] ?? 0, $previous['clicks'] ?? 0));
        seo_analista_render_metric_card('Posición media', number_format_i18n((float) ($current['position'] ?? 0), 1), seo_analista_metric_delta($current['position'] ?? 0, $previous['position'] ?? 0, true));
        seo_analista_render_metric_card('Consultas', number_format_i18n((int) ($current['queries'] ?? 0)), seo_analista_metric_delta($current['queries'] ?? 0, $previous['queries'] ?? 0));
        seo_analista_render_metric_card('Páginas', number_format_i18n((int) ($current['pages'] ?? 0)), seo_analista_metric_delta($current['pages'] ?? 0, $previous['pages'] ?? 0));
        seo_analista_render_metric_card('CTR', number_format_i18n(((float) ($current['ctr'] ?? 0)) * 100, 2) . '%', seo_analista_metric_delta($current['ctr'] ?? 0, $previous['ctr'] ?? 0));
        echo '</div>';

        seo_analista_render_distribution((array) ($data['distribution'] ?? array()), (array) ($data['previous_distribution'] ?? array()));
        seo_analista_render_movement((array) ($evolution['movement'] ?? array()));
        seo_analista_render_page_types((array) ($data['page_types'] ?? array()));

        $queries = array_slice((array) ($data['queries'] ?? array()), 0, 40);
        if ($queries) {
            echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Consultas principales</h2><p>Vista parcial de Search Console. No ejecuta Contenido, Catálogo, Trends ni Competencia.</p></div></div>';
            echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Consulta</th><th>Impresiones</th><th>Clics</th><th>Posición</th><th>Intento</th></tr></thead><tbody>';
            foreach ($queries as $row) {
                $query = seo_analista_clean_query($row['query_text'] ?? $row['query'] ?? '');
                if ($query === '') continue;
                echo '<tr><td><strong>' . esc_html($query) . '</strong></td><td>' . esc_html(number_format_i18n((float) ($row['impressions'] ?? 0), 0)) . '</td><td>' . esc_html(number_format_i18n((float) ($row['clicks'] ?? 0), 0)) . '</td><td>' . esc_html(number_format_i18n((float) ($row['position'] ?? 0), 1)) . '</td><td>' . esc_html(seo_analista_intent($query)) . '</td></tr>';
            }
            echo '</tbody></table></div></section>';
        }
    }
}

if (!function_exists('seo_analista_lazy_render_summary')) {
    function seo_analista_lazy_render_summary($days) {
        $data = seo_analista_get_data($days);
        $google = seo_analista_google_snapshot($days, false);
        $evolution = seo_analista_evolution_snapshot($days);
        $search = seo_analista_internal_search_snapshot($days, 40);
        $catalog_structure = seo_analista_catalog_structure_snapshot((array) ($data['pages'] ?? array()));
        seo_analista_render_where_we_are($data, $google, $evolution, $search, $catalog_structure);
    }
}

if (!function_exists('seo_analista_lazy_render_catalog')) {
    function seo_analista_lazy_render_catalog($days) {
        $guidance = seo_analista_catalog_guidance($days, 30);
        $items = (array) ($guidance['items'] ?? array());

        echo '<div class="seo-analista-grid">';
        seo_analista_render_metric_card('Oportunidades', number_format_i18n(count($items)), '', 'Solo se ha ejecutado el análisis de catálogo/demanda.');
        echo '</div>';

        if (!$items) {
            echo '<div class="notice notice-info inline"><p>No hay directrices de catálogo disponibles para este periodo.</p></div>';
            return;
        }

        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Catálogo y demanda</h2><p>Familias, categorías o huecos de surtido detectados. Este bloque no ejecuta los informes de contenido, estructura ni competencia.</p></div></div>';
        echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Prioridad</th><th>Tipo</th><th>Oportunidad</th><th>Decisión</th><th>Impresiones</th><th>Posición</th><th>Productos</th><th>Lectura</th></tr></thead><tbody>';
        foreach ($items as $row) {
            echo '<tr>';
            echo '<td>' . wp_kses_post(seo_analista_score_badge((int) ($row['score'] ?? 0))) . '</td>';
            echo '<td>' . esc_html((string) ($row['kind'] ?? '')) . '</td>';
            echo '<td><strong>' . esc_html((string) ($row['label'] ?? '')) . '</strong></td>';
            echo '<td>' . esc_html((string) ($row['decision'] ?? '')) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) ($row['impressions'] ?? 0), 0)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) ($row['position'] ?? 0), 1)) . '</td>';
            echo '<td>' . esc_html(isset($row['products']) ? number_format_i18n((int) $row['products']) : '—') . '</td>';
            echo '<td>' . esc_html((string) ($row['note'] ?? '')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></section>';
    }
}

if (!function_exists('seo_analista_lazy_render_competition')) {
    function seo_analista_lazy_render_competition($days) {
        $data = seo_analista_get_data($days);
        $competition = seo_analista_competition_snapshot(100);
        $market = seo_analista_market_signals(30);
        seo_analista_render_comparison($competition, $market, $data);
    }
}

if (!function_exists('seo_analista_lazy_render_suppliers')) {
    function seo_analista_lazy_render_suppliers($days) {
        unset($days);
        $suppliers = seo_analista_supplier_snapshot(100);
        echo '<div class="seo-analista-grid">';
        seo_analista_render_metric_card('Proveedores', number_format_i18n((int) ($suppliers['providers'] ?? 0)));
        seo_analista_render_metric_card('Registros', number_format_i18n((int) ($suppliers['total'] ?? 0)));
        seo_analista_render_metric_card('Enlaces publicados', number_format_i18n((int) ($suppliers['published_links'] ?? 0)));
        seo_analista_render_metric_card('Con aviso', number_format_i18n(count((array) ($suppliers['issues'] ?? array()))));
        echo '</div>';

        $rows = (array) ($suppliers['rows'] ?? array());
        if (!$rows) {
            echo '<div class="notice notice-info inline"><p>No hay datos de proveedores disponibles.</p></div>';
            return;
        }

        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Estado de proveedores</h2><p>Este informe consulta únicamente la fuente local de proveedores.</p></div></div>';
        echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Proveedor</th><th>Total</th><th>Vinculados</th><th>Pendientes</th><th>Errores</th><th>Última importación</th><th>Última sincronización</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td><strong>' . esc_html((string) ($row['proveedor'] ?? '')) . '</strong></td><td>' . esc_html(number_format_i18n((int) ($row['total'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['linked'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['pending'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['errors'] ?? 0))) . '</td><td>' . esc_html((string) ($row['last_import'] ?? '—')) . '</td><td>' . esc_html((string) ($row['last_sync'] ?? '—')) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
}

if (!function_exists('seo_analista_lazy_render_work')) {
    function seo_analista_lazy_render_work($days) {
        $plan = seo_analista_decision_plan($days, 40);
        $summary = seo_analista_plan_summary($plan);
        $suppliers = seo_analista_supplier_snapshot(40);
        $search = seo_analista_internal_search_snapshot($days, 40);
        seo_analista_render_roadmap($plan, $summary, $suppliers, $search);
    }
}

if (!function_exists('seo_analista_lazy_render_sources')) {
    function seo_analista_lazy_render_sources($days) {
        $health = seo_analista_google_source_health($days);
        $competition = seo_analista_competition_snapshot(20);
        $suppliers = seo_analista_supplier_snapshot(20);
        seo_analista_render_sources($health, $competition, $suppliers);
    }
}

if (!function_exists('seo_analista_lazy_render_partial')) {
    function seo_analista_lazy_render_partial($report, $days) {
        switch ($report) {
            case 'resumen':
                seo_analista_lazy_render_summary($days);
                break;
            case 'search_console':
                seo_analista_lazy_render_search_console($days);
                break;
            case 'contenido':
                seo_analista_render_content_view($days);
                break;
            case 'estructura':
                seo_analista_render_structure_view($days);
                break;
            case 'catalogo':
                seo_analista_lazy_render_catalog($days);
                break;
            case 'tendencias':
                seo_analista_render_trends_view($days);
                break;
            case 'trabajo':
                seo_analista_lazy_render_work($days);
                break;
            case 'competencia':
                seo_analista_lazy_render_competition($days);
                break;
            case 'proveedores':
                seo_analista_lazy_render_suppliers($days);
                break;
            case 'fuentes':
                seo_analista_lazy_render_sources($days);
                break;
        }
    }
}

if (!function_exists('seo_analista_ajax_partial_report')) {
    function seo_analista_ajax_partial_report() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'No tienes permisos suficientes.'), 403);
        }

        check_ajax_referer('seo_analista_partial_report', 'nonce');

        $report = isset($_POST['report']) ? sanitize_key(wp_unslash($_POST['report'])) : '';
        $days = isset($_POST['days']) ? seo_analista_days(wp_unslash($_POST['days'])) : 28;
        $reports = seo_analista_lazy_reports();

        if (!isset($reports[$report])) {
            wp_send_json_error(array('message' => 'Informe parcial no reconocido.'), 400);
        }

        $started = microtime(true);
        ob_start();

        try {
            echo '<div class="seo-analista-partial" data-report="' . esc_attr($report) . '">';
            echo '<div class="seo-analista-partial-head"><h2>' . esc_html($reports[$report]['label']) . '</h2><p>' . esc_html($reports[$report]['description']) . '</p></div>';
            seo_analista_lazy_render_partial($report, $days);
            echo '</div>';
            $html = ob_get_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            wp_send_json_error(array('message' => 'Error en ' . $reports[$report]['label'] . ': ' . $e->getMessage()), 500);
        }

        wp_send_json_success(array(
            'report' => $report,
            'html' => $html,
            'seconds' => round(microtime(true) - $started, 2),
            'version' => defined('SEO_ANALISTA_VERSION') ? SEO_ANALISTA_VERSION : '',
        ));
    }
}
