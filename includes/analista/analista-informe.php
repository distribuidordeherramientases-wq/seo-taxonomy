<?php
/**
 * Vista ejecutiva de Informes SEO > Analista.
 *
 * Analista 3.6: resumen, literatura, estructura, tendencias y plan accionable por nivel de intervención.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_metric_delta')) {
    function seo_analista_metric_delta($current, $previous, $inverse = false) {
        $change = seo_analista_percent_change($current, $previous);
        if (null === $change) return '<span class="seo-analista-neutral">nuevo</span>';
        $good = $inverse ? $change < 0 : $change > 0;
        $bad = $inverse ? $change > 0 : $change < 0;
        $class = $good ? 'seo-analista-good' : ($bad ? 'seo-analista-bad' : 'seo-analista-neutral');
        $arrow = $change > 0 ? '↑' : ($change < 0 ? '↓' : '→');
        return '<span class="' . esc_attr($class) . '">' . esc_html($arrow . ' ' . number_format_i18n(abs($change), 1) . '%') . '</span>';
    }
}

if (!function_exists('seo_analista_score_badge')) {
    function seo_analista_score_badge($score) {
        $score = absint($score);
        $class = $score >= 75 ? 'high' : ($score >= 55 ? 'medium' : 'low');
        return '<span class="seo-analista-score ' . esc_attr($class) . '">' . esc_html($score . '/100') . '</span>';
    }
}

if (!function_exists('seo_analista_render_metric_card')) {
    function seo_analista_render_metric_card($label, $value, $delta_html = '', $detail = '') {
        echo '<div class="seo-analista-card">';
        echo '<div class="seo-analista-label">' . esc_html($label) . '</div>';
        echo '<div class="seo-analista-number">' . esc_html($value) . '</div>';
        if ($delta_html !== '') echo '<div class="seo-analista-delta">' . wp_kses_post($delta_html) . '</div>';
        if ($detail !== '') echo '<div class="seo-analista-detail">' . esc_html($detail) . '</div>';
        echo '</div>';
    }
}

if (!function_exists('seo_analista_render_subnav')) {
    function seo_analista_render_subnav($active, $days) {
        $views = array(
            'donde_estamos' => 'Resumen',
            'contenido' => 'Contenido',
            'estructura' => 'Estructura',
            'tendencias' => 'Tendencias',
            'comparacion' => 'Comparación',
            'hacia_donde_vamos' => 'Guion de trabajo',
        );
        echo '<nav class="seo-analista-subnav" aria-label="Secciones de Analista">';
        foreach ($views as $key => $label) {
            $url = seo_analista_admin_url(array('analista_view' => $key, 'analista_days' => $days));
            echo '<a class="' . ($key === $active ? 'is-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }
}

if (!function_exists('seo_analista_render_line_chart')) {
    function seo_analista_render_line_chart(array $rows, $metric, $title, $description = '', $inverse = false) {
        $values = array();
        foreach ($rows as $row) {
            $value = isset($row[$metric]) ? (float) $row[$metric] : null;
            if (null === $value) continue;
            $values[] = $value;
        }
        if (count($values) < 2) return;

        // Reduce series muy largas sin cargar JavaScript adicional.
        if (count($values) > 100) {
            $step = max(1, (int) floor(count($values) / 90));
            $sampled = array();
            foreach ($values as $i => $value) if ($i % $step === 0 || $i === count($values) - 1) $sampled[] = $value;
            $values = $sampled;
        }

        $min = min($values);
        $max = max($values);
        $range = max(0.0001, $max - $min);
        $width = 640;
        $height = 180;
        $pad = 18;
        $plot_w = $width - ($pad * 2);
        $plot_h = $height - ($pad * 2);
        $points = array();
        $last = max(1, count($values) - 1);
        foreach ($values as $i => $value) {
            $x = $pad + (($i / $last) * $plot_w);
            $ratio = ($value - $min) / $range;
            if ($inverse) $ratio = 1 - $ratio;
            $y = $pad + ((1 - $ratio) * $plot_h);
            $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
        }

        echo '<div class="seo-analista-chart">';
        echo '<div class="seo-analista-chart-head"><div><strong>' . esc_html($title) . '</strong>';
        if ($description !== '') echo '<small>' . esc_html($description) . '</small>';
        echo '</div><span>' . esc_html(number_format_i18n(end($values), 'position' === $metric ? 1 : 0)) . '</span></div>';
        echo '<svg viewBox="0 0 ' . absint($width) . ' ' . absint($height) . '" role="img" aria-label="' . esc_attr($title) . '">';
        echo '<line x1="18" y1="162" x2="622" y2="162" class="axis"></line>';
        echo '<polyline points="' . esc_attr(implode(' ', $points)) . '" class="line"></polyline>';
        echo '</svg>';
        echo '</div>';
    }
}

if (!function_exists('seo_analista_render_distribution')) {
    function seo_analista_render_distribution(array $current, array $previous) {
        $labels = array('top3' => 'Top 3', 'top10' => '4–10', 'top20' => '11–20', 'top50' => '21–50', 'top100' => '51–100', 'outside' => '>100');
        $max_value = max(1, max(array_merge(array_values($current), array_values($previous))));
        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Cómo avanzan nuestras posiciones</h2><p>No importa solo el Top 10: aquí vemos si las consultas están subiendo desde las posiciones bajas hacia los primeros resultados.</p></div></div>';
        echo '<div class="seo-analista-legend"><span><i class="current"></i>Actual</span><span><i class="previous"></i>Periodo anterior</span></div><div class="seo-analista-bars">';
        foreach ($labels as $key => $label) {
            $value = (int) ($current[$key] ?? 0);
            $prev = (int) ($previous[$key] ?? 0);
            $w1 = min(100, ($value / $max_value) * 100);
            $w2 = min(100, ($prev / $max_value) * 100);
            echo '<div class="seo-analista-bar-row"><div class="seo-analista-bar-meta"><strong>' . esc_html($label) . '</strong><span>' . esc_html(number_format_i18n($value)) . ' ahora · ' . esc_html(number_format_i18n($prev)) . ' antes</span></div>';
            echo '<div class="seo-analista-bar-track"><span class="previous" style="width:' . esc_attr(number_format($w2, 2, '.', '')) . '%"></span><span class="current" style="width:' . esc_attr(number_format($w1, 2, '.', '')) . '%"></span></div></div>';
        }
        echo '</div></section>';
    }
}

if (!function_exists('seo_analista_render_movement')) {
    function seo_analista_render_movement(array $movement) {
        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Movimiento de keywords</h2><p>Comparación consulta a consulta contra el periodo anterior. Una mejora de al menos una posición cuenta como subida.</p></div></div><div class="seo-analista-grid compact">';
        seo_analista_render_metric_card('Subieron', number_format_i18n((int) ($movement['improved'] ?? 0)), '', 'Consultas que ganaron posiciones.');
        seo_analista_render_metric_card('Estables', number_format_i18n((int) ($movement['stable'] ?? 0)), '', 'Variación inferior a 1 posición.');
        seo_analista_render_metric_card('Bajaron', number_format_i18n((int) ($movement['declined'] ?? 0)), '', 'Consultas que perdieron posiciones.');
        seo_analista_render_metric_card('Nuevas', number_format_i18n((int) ($movement['new'] ?? 0)), '', 'No aparecían en el periodo anterior.');
        seo_analista_render_metric_card('Entraron Top 50', sprintf('%+d', (int) ($movement['net_top50'] ?? 0)), '', 'Saldo neto de consultas en Top 50.');
        seo_analista_render_metric_card('Entraron Top 10', sprintf('%+d', (int) ($movement['net_top10'] ?? 0)), '', 'Saldo neto de consultas en Top 10.');
        echo '</div></section>';
    }
}

if (!function_exists('seo_analista_render_page_types')) {
    function seo_analista_render_page_types(array $types) {
        if (!$types) return;
        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Cómo se posiciona el catálogo</h2><p>Qué parte de la visibilidad está llegando a categorías, productos, landings y contenidos.</p></div></div><div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Tipo de URL</th><th>Páginas</th><th>Impresiones</th><th>Clics</th><th>Consultas</th></tr></thead><tbody>';
        foreach ($types as $label => $row) {
            echo '<tr><td><strong>' . esc_html($label) . '</strong></td><td>' . esc_html(number_format_i18n((int) ($row['pages'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((float) ($row['impressions'] ?? 0), 0)) . '</td><td>' . esc_html(number_format_i18n((float) ($row['clicks'] ?? 0), 0)) . '</td><td>' . esc_html(number_format_i18n((int) ($row['queries'] ?? 0))) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
}

if (!function_exists('seo_analista_render_where_we_are')) {
    function seo_analista_render_where_we_are(array $data, array $google, array $evolution, array $search, array $catalog_structure) {
        $current = (array) ($data['current'] ?? array());
        $previous = (array) ($data['previous'] ?? array());
        $ga4 = (array) ($google['ga4'] ?? array());
        $period = (array) ($data['period'] ?? array());
        $days = (int) ($period['days'] ?? 28);
        if ($days <= 0) $days = 28;
        $bing = function_exists('seo_analista_bing_snapshot') ? (array) seo_analista_bing_snapshot($days, 30) : array();

        if (function_exists('seo_google_metrics_coverage_notice')) {
            $current_notice = seo_google_metrics_coverage_notice($current);
            $previous_notice = seo_google_metrics_coverage_notice($previous);
            if ($current_notice || $previous_notice) {
                echo '<div class="notice notice-warning inline"><p>' . esc_html($current_notice ?: $previous_notice) . ' La comparación entre periodos puede no ser fiable hasta completar ambos.' . '</p></div>';
            }
        }

        echo '<div class="seo-analista-grid">';
        seo_analista_render_metric_card('Índice de visibilidad', number_format_i18n((float) ($data['visibility_index'] ?? 0), 1), seo_analista_metric_delta($data['visibility_index'] ?? 0, $data['previous_visibility_index'] ?? 0), 'Índice propio basado en distribución de posiciones.');
        seo_analista_render_metric_card('Impresiones', number_format_i18n((float) ($current['impressions'] ?? 0), 0), seo_analista_metric_delta($current['impressions'] ?? 0, $previous['impressions'] ?? 0), 'Veces que Google mostró una URL del sitio.');
        seo_analista_render_metric_card('Posición media', number_format_i18n((float) ($current['position'] ?? 0), 1), seo_analista_metric_delta($current['position'] ?? 0, $previous['position'] ?? 0, true), 'Menos es mejor.');
        seo_analista_render_metric_card('Keywords visibles', number_format_i18n((int) ($current['queries'] ?? 0)), seo_analista_metric_delta($current['queries'] ?? 0, $previous['queries'] ?? 0), 'Consultas distintas del periodo.');
        seo_analista_render_metric_card('Páginas visibles', number_format_i18n((int) ($current['pages'] ?? 0)), seo_analista_metric_delta($current['pages'] ?? 0, $previous['pages'] ?? 0), 'URLs con impresiones en Search Console.');
        seo_analista_render_metric_card('CTR', number_format_i18n(((float) ($current['ctr'] ?? 0)) * 100, 2) . '%', seo_analista_metric_delta($current['ctr'] ?? 0, $previous['ctr'] ?? 0), 'Clics sobre impresiones.');
        seo_analista_render_metric_card('Sesiones GA4', !empty($ga4['available']) ? number_format_i18n((int) ($ga4['sessions'] ?? 0)) : '—', '', !empty($ga4['available']) ? 'Comportamiento medido por Analytics.' : 'GA4 no disponible.');
        seo_analista_render_metric_card('Usuarios GA4', !empty($ga4['available']) ? number_format_i18n((int) ($ga4['users'] ?? 0)) : '—', '', !empty($ga4['available']) ? 'Usuarios activos del periodo.' : 'GA4 no disponible.');
        seo_analista_render_metric_card('Vistas producto', !empty($ga4['available']) ? number_format_i18n((int) (($ga4['funnel']['view_item'] ?? 0))) : '—', '', 'Evento view_item; mide interacción con fichas de producto.');
        seo_analista_render_metric_card('Añadir al carrito', !empty($ga4['available']) ? number_format_i18n((int) (($ga4['funnel']['add_to_cart'] ?? 0))) : '—', '', 'Evento add_to_cart; no equivale a usuarios únicos.');
        seo_analista_render_metric_card('Checkouts iniciados', !empty($ga4['available']) ? number_format_i18n((int) (($ga4['funnel']['begin_checkout'] ?? 0))) : '—', '', 'Evento begin_checkout.');
        seo_analista_render_metric_card('Compras GA4', !empty($ga4['available']) ? number_format_i18n((int) ($ga4['purchases'] ?? 0)) : '—', '', 'Medición Analytics; validar siempre contra WooCommerce.');
        seo_analista_render_metric_card('España', !empty($ga4['available']) ? number_format_i18n((int) (($ga4['target_market']['users'] ?? 0))) : '—', '', !empty($ga4['available']) && null !== ($ga4['target_market']['user_share_pct'] ?? null) ? number_format_i18n((float) $ga4['target_market']['user_share_pct'], 1) . '% de usuarios activos.' : 'Mercado objetivo no medido.');
        seo_analista_render_metric_card('404 vistas', !empty($ga4['available']) ? number_format_i18n((int) (($ga4['not_found']['views'] ?? 0))) : '—', '', 'Vistas en páginas no encontradas detectadas por GA4.');
        $search_total = (int) ($search['total'] ?? 0);
        $search_zero = (int) ($search['zero_results'] ?? 0);
        $search_zero_pct = $search_total > 0 ? ($search_zero / $search_total) * 100 : null;
        seo_analista_render_metric_card(
            'Búsquedas internas',
            !empty($search['available']) ? number_format_i18n($search_total) : '—',
            '',
            !empty($search['available'])
                ? number_format_i18n($search_zero) . ' sin resultado' . (null !== $search_zero_pct ? ' · ' . number_format_i18n($search_zero_pct, 1) . '%' : '') . '.'
                : 'Registro no disponible.'
        );
        if (!empty($bing['connected'])) {
            seo_analista_render_metric_card('Bing impresiones', number_format_i18n((int) round((float) ($bing['traffic']['impressions'] ?? 0))), '', 'Bing Webmaster en el mismo horizonte solicitado.');
            seo_analista_render_metric_card('Bing clics', number_format_i18n((int) round((float) ($bing['traffic']['clicks'] ?? 0))), '', 'Clics reportados por Bing Webmaster.');
        }
        echo '</div>';

        $position_change = (float) ($previous['position'] ?? 0) - (float) ($current['position'] ?? 0);
        $impressions_change = seo_analista_percent_change($current['impressions'] ?? 0, $previous['impressions'] ?? 0);
        echo '<section class="seo-analista-section seo-analista-reading"><div class="seo-analista-section-head"><div><h2>Lectura rápida</h2><p>Lo importante del periodo sin recorrer todos los informes.</p></div></div><div class="seo-analista-actions">';
        echo '<div class="seo-analista-action"><strong>Cobertura</strong><p>' . esc_html(null === $impressions_change ? 'Google empieza a mostrar nuevas URLs y consultas.' : ('Las impresiones cambian ' . number_format_i18n($impressions_change, 1) . '% frente al periodo anterior.')) . '</p></div>';
        echo '<div class="seo-analista-action"><strong>Ranking</strong><p>' . esc_html($position_change > 0 ? ('La posición media mejora ' . number_format_i18n($position_change, 1) . ' puestos.') : ($position_change < 0 ? ('La posición media retrocede ' . number_format_i18n(abs($position_change), 1) . ' puestos.') : 'La posición media permanece estable.')) . '</p></div>';
        $movement = (array) ($evolution['movement'] ?? array());
        echo '<div class="seo-analista-action"><strong>Movimiento</strong><p>' . esc_html(number_format_i18n((int) ($movement['improved'] ?? 0)) . ' keywords suben y ' . number_format_i18n((int) ($movement['declined'] ?? 0)) . ' bajan respecto al periodo anterior.') . '</p></div>';

        if (!empty($ga4['available'])) {
            $funnel = (array) ($ga4['funnel'] ?? array());
            $traffic = (array) ($ga4['traffic_quality'] ?? array());
            $target = (array) ($ga4['target_market'] ?? array());
            $not_found = (array) ($ga4['not_found'] ?? array());

            $commercial = number_format_i18n((int) ($funnel['view_item'] ?? 0)) . ' vistas de producto → '
                . number_format_i18n((int) ($funnel['add_to_cart'] ?? 0)) . ' carritos → '
                . number_format_i18n((int) ($funnel['begin_checkout'] ?? 0)) . ' checkout → '
                . number_format_i18n((int) ($ga4['purchases'] ?? 0)) . ' compras medidas.';
            echo '<div class="seo-analista-action"><strong>Embudo comercial</strong><p>' . esc_html($commercial) . '</p></div>';

            $direct_share = $traffic['direct_share_pct'] ?? null;
            $traffic_text = number_format_i18n((int) ($traffic['organic_sessions'] ?? 0)) . ' sesiones orgánicas · '
                . number_format_i18n((int) ($traffic['ai_sessions'] ?? 0)) . ' desde asistentes IA';
            if (null !== $direct_share) $traffic_text .= ' · ' . number_format_i18n((float) $direct_share, 1) . '% Direct.';
            echo '<div class="seo-analista-action"><strong>Adquisición</strong><p>' . esc_html($traffic_text) . '</p></div>';

            $market_text = number_format_i18n((int) ($target['users'] ?? 0)) . ' usuarios activos de España';
            if (null !== ($target['user_share_pct'] ?? null)) {
                $market_text .= ' (' . number_format_i18n((float) $target['user_share_pct'], 1) . '% del total)';
            }
            $market_text .= '.';
            echo '<div class="seo-analista-action"><strong>Mercado objetivo</strong><p>' . esc_html($market_text) . '</p></div>';

            if ((int) ($not_found['views'] ?? 0) > 0) {
                echo '<div class="seo-analista-action"><strong>404</strong><p>' . esc_html(number_format_i18n((int) $not_found['views']) . ' vistas han llegado a páginas no encontradas. Conviene revisar las rutas que las generan.') . '</p></div>';
            }
        }

        if (!empty($search['available']) && $search_total > 0) {
            $search_text = number_format_i18n($search_zero) . ' de ' . number_format_i18n($search_total) . ' búsquedas internas no devolvieron resultados';
            if (null !== $search_zero_pct) $search_text .= ' (' . number_format_i18n($search_zero_pct, 1) . '%)';
            $search_text .= '.';
            echo '<div class="seo-analista-action"><strong>Búsqueda interna</strong><p>' . esc_html($search_text) . '</p></div>';
        }

        $freshness = array();
        if (!empty($period['date_to'])) $freshness[] = 'Search Console hasta ' . (string) $period['date_to'];
        if (!empty($ga4['period']['end'])) $freshness[] = 'GA4 consultado hasta ' . (string) $ga4['period']['end'];
        if (!empty($bing['connected']) && !empty($bing['latest_date'])) $freshness[] = 'Bing hasta ' . (string) $bing['latest_date'];
        if ($freshness) {
            echo '<div class="seo-analista-action"><strong>Actualización de fuentes</strong><p>' . esc_html(implode(' · ', $freshness) . '. Las fechas pueden diferir por el retraso propio de cada plataforma.') . '</p></div>';
        }

        if (!empty($ga4['available'])) {
            $gsc_clicks = (int) round((float) ($current['clicks'] ?? 0));
            $google_sessions = 0;
            foreach ((array) ($ga4['sources'] ?? array()) as $source_row) {
                $source_medium = strtolower((string) ($source_row['source_medium'] ?? ''));
                if (strpos($source_medium, 'google') !== false && strpos($source_medium, 'organic') !== false) {
                    $google_sessions += (int) ($source_row['sessions'] ?? 0);
                }
            }
            if ($gsc_clicks > 0 || $google_sessions > 0) {
                echo '<div class="seo-analista-action"><strong>Coherencia de medición</strong><p>' . esc_html('Search Console registra ' . number_format_i18n($gsc_clicks) . ' clics y GA4 atribuye ' . number_format_i18n($google_sessions) . ' sesiones a Google orgánico. No son la misma métrica; una diferencia amplia debe revisarse como señal de medición/atribución, no corregirse automáticamente.') . '</p></div>';
            }
        }
        echo '</div></section>';

        if (!empty($ga4['available'])) {
            $funnel = (array) ($ga4['funnel'] ?? array());
            echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Tráfico y conversión</h2><p>Analytics complementa Search Console: separa adquisición, mercado objetivo y pasos del embudo. Los eventos no representan necesariamente usuarios únicos.</p></div></div>';

            echo '<div class="seo-analista-grid compact">';
            foreach (array(
                'view_item' => 'Ver producto',
                'add_to_cart' => 'Añadir al carrito',
                'view_cart' => 'Ver carrito',
                'begin_checkout' => 'Iniciar checkout',
                'purchase' => 'Compra'
            ) as $key => $label) {
                seo_analista_render_metric_card($label, number_format_i18n((int) ($funnel[$key] ?? 0)));
            }
            echo '</div>';

            if (!empty($ga4['sources'])) {
                echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Fuente / medio</th><th>Canal</th><th>Sesiones</th><th>Usuarios</th></tr></thead><tbody>';
                foreach (array_slice((array) $ga4['sources'], 0, 12) as $row) {
                    echo '<tr><td><strong>' . esc_html((string) ($row['source_medium'] ?? '')) . '</strong></td><td>' . esc_html((string) ($row['channel'] ?? '')) . '</td><td>' . esc_html(number_format_i18n((int) ($row['sessions'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['users'] ?? 0))) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }

            if (!empty($ga4['not_found']['rows'])) {
                echo '<h3 style="margin-top:18px;">Rutas 404 vistas en Analytics</h3>';
                echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Ruta</th><th>Vistas</th><th>Usuarios</th></tr></thead><tbody>';
                foreach (array_slice((array) $ga4['not_found']['rows'], 0, 15) as $row) {
                    echo '<tr><td><code>' . esc_html((string) ($row['path'] ?? '')) . '</code></td><td>' . esc_html(number_format_i18n((int) ($row['views'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['users'] ?? 0))) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }

            $period = (array) ($ga4['period'] ?? array());
            echo '<p class="description" style="margin-top:12px;">GA4 solicitado: ' . esc_html((string) ($period['start'] ?? '')) . ' → ' . esc_html((string) ($period['end'] ?? '')) . (!empty($period['latest_date']) ? ' · último día con sesiones: ' . esc_html((string) $period['latest_date']) : '') . '. No comparar clics de Search Console/Bing con sesiones GA4 como si fueran la misma métrica.</p>';
            echo '</section>';
        }

        $trend = (array) ($evolution['trend'] ?? array());
        if ($trend) {
            echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Evolución en Google</h2><p>Serie histórica para ver si el sitio está avanzando antes de que ese progreso se convierta en clics.</p></div></div><div class="seo-analista-charts">';
            seo_analista_render_line_chart($trend, 'position', 'Posición media', 'La línea sube cuando mejora el ranking.', true);
            seo_analista_render_line_chart($trend, 'impressions', 'Impresiones', 'Cuántas veces aparecemos en resultados.', false);
            seo_analista_render_line_chart($trend, 'clicks', 'Clics', 'Tráfico orgánico capturado desde Google.', false);
            echo '</div></section>';
        }

        seo_analista_render_distribution((array) ($data['distribution'] ?? array()), (array) ($data['previous_distribution'] ?? array()));
        seo_analista_render_movement((array) ($evolution['movement'] ?? array()));
        seo_analista_render_page_types((array) ($data['page_types'] ?? array()));

        if (!empty($catalog_structure['available'])) {
            echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Arquitectura que Analista tiene en cuenta</h2><p>La lectura no se limita a keywords: conoce la estructura cluster → hub primario → hub secundario → categoría y el catálogo publicado.</p></div></div><div class="seo-analista-grid compact">';
            seo_analista_render_metric_card('Clusters', number_format_i18n((int) ($catalog_structure['clusters'] ?? 0)));
            seo_analista_render_metric_card('Hubs primarios', number_format_i18n((int) ($catalog_structure['hub_primary'] ?? 0)));
            seo_analista_render_metric_card('Hubs secundarios', number_format_i18n((int) ($catalog_structure['hub_secondary'] ?? 0)));
            seo_analista_render_metric_card('Categorías', number_format_i18n((int) ($catalog_structure['categories'] ?? 0)));
            seo_analista_render_metric_card('Productos', number_format_i18n((int) ($catalog_structure['products'] ?? 0)));
            echo '</div></section>';
        }
    }
}

if (!function_exists('seo_analista_render_competition_history')) {
    function seo_analista_render_competition_history(array $competition) {
        $history = (array) ($competition['history'] ?? array());
        if (count($history) < 2) return;
        $own = (string) ($competition['own_domain'] ?? '');
        $rows = array();
        foreach ($history as $entry) {
            $value = null;
            foreach ((array) ($entry['domains'] ?? array()) as $domain) {
                if ((string) ($domain['domain'] ?? '') === $own) {
                    $value = (float) ($domain['visibility_index'] ?? 0);
                    break;
                }
            }
            if (null !== $value) $rows[] = array('value' => $value);
        }
        if (count($rows) >= 2) seo_analista_render_line_chart($rows, 'value', 'Evolución competitiva propia', 'Índice relativo calculado sobre cada importación de competencia.', false);
    }
}

if (!function_exists('seo_analista_render_tracked_keywords')) {
    function seo_analista_render_tracked_keywords(array $data) {
        $rows = seo_analista_tracked_keyword_snapshot(
            (array) ($data['queries'] ?? array()),
            (array) ($data['previous_queries'] ?? array())
        );
        if (!$rows) {
            echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Palabras clave vigiladas</h2><p>Añade las palabras que quieres seguir para ver nuestra posición, evolución y distancia respecto a los competidores.</p></div></div><p class="description">Todavía no has definido palabras clave de seguimiento.</p></section>';
            return;
        }

        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Palabras clave vigiladas</h2><p>Seguimiento directo de las palabras que hemos decidido controlar. La proyección solo aparece cuando existe ritmo propio suficiente y no asume que el competidor permanezca inmóvil.</p></div></div>';
        echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Palabra clave</th><th>Nosotros</th><th>Antes</th><th>Movimiento</th><th>Mejor competidor</th><th>Distancia</th><th>Lectura</th><th>Proyección</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $best = (array) ($row['best_competitor'] ?? array());
            $own = (float) ($row['own_position'] ?? 0);
            $previous = (float) ($row['previous_own_position'] ?? 0);
            $change = $row['own_change'] ?? null;
            $gap = $row['gap'] ?? null;
            $projection = !empty($row['periods_to_overtake'])
                ? '~' . absint($row['periods_to_overtake']) . ' periodos si mantenemos el ritmo'
                : '—';
            echo '<tr><td><strong>' . esc_html((string) ($row['keyword'] ?? '')) . '</strong></td>';
            echo '<td>' . esc_html($own > 0 ? number_format_i18n($own, 1) : 'No visible') . '</td>';
            echo '<td>' . esc_html($previous > 0 ? number_format_i18n($previous, 1) : '—') . '</td>';
            echo '<td>' . esc_html(null === $change ? '—' : (($change >= 0 ? '↑ ' : '↓ ') . number_format_i18n(abs((float) $change), 1))) . '</td>';
            echo '<td>' . esc_html($best ? ((string) ($best['domain'] ?? '') . ' · ' . number_format_i18n((float) ($best['position'] ?? 0), 1)) : 'Sin dato externo') . '</td>';
            echo '<td>' . esc_html(null === $gap ? '—' : number_format_i18n((float) $gap, 1)) . '</td>';
            echo '<td>' . esc_html((string) ($row['state'] ?? '')) . '</td>';
            echo '<td>' . esc_html($projection) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
}

if (!function_exists('seo_analista_render_comparison')) {
    function seo_analista_render_comparison(array $competition, array $market, array $data) {
        seo_analista_render_tracked_keywords($data);

        if (empty($competition['available'])) {
            echo '<div class="notice notice-info inline"><p><strong>La comparación externa todavía no tiene posiciones disponibles.</strong> Search Console solo informa de nuestro sitio. Analista seguirá nuestra evolución y las palabras clave vigiladas; cuando el adaptador automático de rankings disponga de datos, completará la comparación con los competidores.</p></div>';
        } else {
            $own = (array) ($competition['own'] ?? array());
            $domains = (array) ($competition['domains'] ?? array());
            $best_competitor = array();
            foreach ($domains as $domain) {
                if ((string) ($domain['domain'] ?? '') === (string) ($competition['own_domain'] ?? '')) continue;
                $best_competitor = $domain;
                break;
            }
            echo '<div class="seo-analista-grid">';
            seo_analista_render_metric_card('Visibilidad competitiva', number_format_i18n((float) ($own['visibility_index'] ?? 0), 1), isset($own['visibility_change']) && null !== $own['visibility_change'] ? (($own['visibility_change'] >= 0 ? '↑ ' : '↓ ') . number_format_i18n(abs((float) $own['visibility_change']), 1) . ' pts') : '', 'Índice relativo del conjunto comparado.');
            seo_analista_render_metric_card('Keywords comparadas', number_format_i18n((int) ($own['keywords'] ?? 0)), '', 'Palabras clave propias dentro del conjunto competitivo.');
            seo_analista_render_metric_card('Top 10 propios', number_format_i18n((int) ($own['top10'] ?? 0)), '', 'Keywords propias en las diez primeras posiciones.');
            seo_analista_render_metric_card('Brechas prioritarias', number_format_i18n(count((array) ($competition['keyword_gaps'] ?? array()))), '', 'Competidores por delante o palabras donde no aparecemos.');
            seo_analista_render_metric_card('Competidor líder', $best_competitor ? (string) ($best_competitor['domain'] ?? '—') : '—', '', $best_competitor ? ('Índice ' . number_format_i18n((float) ($best_competitor['visibility_index'] ?? 0), 1)) : 'Sin competidor comparable.');
            seo_analista_render_metric_card('Última comparación', (string) ($competition['imported_at'] ?? '—'), '', 'Fecha del último conjunto competitivo disponible.');
            echo '</div>';

            echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Nosotros frente a competidores</h2><p>Cuántas palabras controla cada dominio y en qué tramos de posicionamiento.</p></div></div><div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Dominio</th><th>Visibilidad</th><th>Keywords</th><th>Top 3</th><th>Top 10</th><th>Top 20</th><th>Top 50</th><th>Evolución</th></tr></thead><tbody>';
            foreach (array_slice($domains, 0, 10) as $row) {
                $is_own = (string) ($row['domain'] ?? '') === (string) ($competition['own_domain'] ?? '');
                $delta = $row['visibility_change'] ?? null;
                echo '<tr' . ($is_own ? ' class="seo-analista-own"' : '') . '><td><strong>' . esc_html((string) ($row['domain'] ?? '')) . ($is_own ? ' · nosotros' : '') . '</strong></td><td>' . esc_html(number_format_i18n((float) ($row['visibility_index'] ?? 0), 1)) . '</td><td>' . esc_html(number_format_i18n((int) ($row['keywords'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['top3'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['top10'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['top20'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((int) ($row['top50'] ?? 0))) . '</td><td>' . esc_html(null === $delta ? '—' : sprintf('%+.1f', (float) $delta)) . '</td></tr>';
            }
            echo '</tbody></table></div></section>';

            echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Palabras clave que debemos disputar</h2><p>Brechas con más valor. Si ya aparecemos, muestra cuánto nos separa del mejor competidor.</p></div></div><div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Prioridad</th><th>Palabra clave</th><th>Nosotros</th><th>Mejor competidor</th><th>Volumen</th><th>Dificultad</th><th>Brecha</th></tr></thead><tbody>';
            foreach (array_slice((array) ($competition['keyword_gaps'] ?? array()), 0, 30) as $gap) {
                $best = (array) ($gap['best_competitor'] ?? array());
                echo '<tr><td>' . wp_kses_post(seo_analista_score_badge($gap['priority'] ?? 0)) . '</td><td><strong>' . esc_html((string) ($gap['keyword'] ?? '')) . '</strong></td><td>' . esc_html((float) ($gap['own_position'] ?? 0) > 0 ? number_format_i18n((float) $gap['own_position'], 0) : 'No visible') . '</td><td>' . esc_html((string) ($best['domain'] ?? '')) . ' · ' . esc_html(number_format_i18n((float) ($best['position'] ?? 0), 0)) . '</td><td>' . esc_html(number_format_i18n((float) ($gap['volume'] ?? 0), 0)) . '</td><td>' . esc_html(number_format_i18n((float) ($gap['difficulty'] ?? 0), 0)) . '</td><td>' . esc_html(number_format_i18n((float) ($gap['gap'] ?? 0), 0)) . '</td></tr>';
            }
            echo '</tbody></table></div></section>';
            seo_analista_render_competition_history($competition);
        }

        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Demanda exterior · Google Trends</h2><p>Qué interesa fuera de nuestra web para no limitar las decisiones a lo que ya estamos posicionando.</p></div></div>';
        if ($market) {
            echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Tema</th><th>Score</th><th>Crecimiento</th><th>Tipo</th></tr></thead><tbody>';
            foreach (array_slice($market, 0, 15) as $row) {
                echo '<tr><td><strong>' . esc_html(seo_analista_clean_query($row['query'] ?? '')) . '</strong></td><td>' . esc_html(number_format_i18n((float) ($row['score'] ?? 0), 1)) . '</td><td>' . esc_html(number_format_i18n((float) ($row['max_growth'] ?? $row['interest_change_pct'] ?? 0), 1) . '%') . '</td><td>' . esc_html((string) ($row['signal_kind'] ?? 'mercado')) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p class="description">No hay señales de Trends suficientemente relevantes almacenadas en este momento.</p>';
        }
        echo '</section>';

        $settings = (array) ($data['settings'] ?? seo_analista_get_settings());
        echo '<section class="seo-analista-section seo-analista-forms"><div class="seo-analista-section-head"><div><h2>Configurar comparación</h2><p>Define a quién queremos superar y qué palabras queremos vigilar. Analista usará estos objetivos en cuanto la fuente automática de rankings disponga de posiciones externas.</p></div></div>';
        echo '<details open><summary><strong>Competidores y palabras vigiladas</strong></summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_analista_save_settings">';
        wp_nonce_field('seo_analista_save_settings', 'seo_analista_nonce');
        echo '<p><strong>Competidores</strong><br><span class="description">Un dominio por línea.</span></p>';
        echo '<textarea name="competitors" rows="6" class="large-text code">' . esc_textarea(implode("\n", (array) ($settings['competitors'] ?? array()))) . '</textarea>';
        echo '<p><strong>Palabras clave vigiladas</strong><br><span class="description">Una palabra o intención por línea. Analista seguirá nuestra posición y, cuando haya posiciones externas automáticas, la distancia con los competidores.</span></p>';
        echo '<textarea name="tracked_keywords" rows="8" class="large-text code">' . esc_textarea(implode("\n", (array) ($settings['tracked_keywords'] ?? array()))) . '</textarea>';
        $validation_settings = (array) ($settings['validation'] ?? array());
        echo '<hr><h3>Umbrales de evidencia · Analista 3.8.1</h3>';
        echo '<p class="description">Son parámetros internos configurables, no reglas SEO universales. Se usan para decidir cuándo una muestra es demasiado pequeña y cuándo un match puede ejecutarse.</p>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="validation_small_sample_max">Muestra mínima / baja</label></th><td><input id="validation_small_sample_max" name="validation_small_sample_max" type="number" min="1" max="20" value="' . esc_attr((string) absint($validation_settings['small_sample_max'] ?? 4)) . '"> impresiones o menos → evidencia baja.</td></tr>';
        echo '<tr><th><label for="validation_reliable_impressions">Impresiones fiables</label></th><td><input id="validation_reliable_impressions" name="validation_reliable_impressions" type="number" min="5" max="100" value="' . esc_attr((string) absint($validation_settings['reliable_impressions'] ?? 20)) . '"></td></tr>';
        echo '<tr><th><label for="validation_high_confidence_impressions">Volumen alto</label></th><td><input id="validation_high_confidence_impressions" name="validation_high_confidence_impressions" type="number" min="10" max="500" value="' . esc_attr((string) absint($validation_settings['high_confidence_impressions'] ?? 50)) . '"></td></tr>';
        echo '<tr><th><label for="validation_minimum_previous_base">Base previa mínima</label></th><td><input id="validation_minimum_previous_base" name="validation_minimum_previous_base" type="number" min="1" max="50" value="' . esc_attr((string) absint($validation_settings['minimum_previous_base'] ?? 5)) . '"> para confiar en porcentajes de crecimiento.</td></tr>';
        echo '<tr><th><label for="validation_partial_match_min">Match parcial mínimo</label></th><td><input id="validation_partial_match_min" name="validation_partial_match_min" type="number" min="40" max="90" value="' . esc_attr((string) absint($validation_settings['partial_match_min'] ?? 58)) . '"> / 100</td></tr>';
        echo '<tr><th><label for="validation_exact_match_min">Match exacto mínimo</label></th><td><input id="validation_exact_match_min" name="validation_exact_match_min" type="number" min="60" max="99" value="' . esc_attr((string) absint($validation_settings['exact_match_min'] ?? 82)) . '"> / 100</td></tr>';
        echo '</tbody></table>';
        submit_button('Guardar comparación y umbrales', 'secondary', 'submit', false);
        echo '</form></details></section>';

        if (function_exists('seo_analista_render_competitive_deep_panel')) {
            $competitive_days = absint($data['period']['days'] ?? 28);
            seo_analista_render_competitive_deep_panel($competitive_days);
        }
    }
}

if (!function_exists('seo_analista_render_directive_details')) {
    function seo_analista_render_directive_details(array $row) {
        $issues = array_values(array_filter((array) ($row['issues'] ?? array())));
        $changes = array_values(array_filter((array) ($row['recommended_changes'] ?? array())));
        $keywords = array_values(array_filter((array) ($row['keywords'] ?? $row['evidence'] ?? array())));
        $entity = (array) ($row['entity'] ?? array());
        $target = (array) ($row['target'] ?? array());

        $query = (string) ($row['query'] ?? '');
        $match_type = (string) ($row['match_type'] ?? '');
        $match_confidence = absint($row['match_confidence'] ?? 0);
        $confidence_level = (string) ($row['confidence_level'] ?? '');
        $evidence = (array) ($row['evidence_volume'] ?? array());
        $commercial = (array) ($row['commercial_readiness'] ?? array());
        $priority_breakdown = (array) ($row['priority_breakdown'] ?? array());
        $dependencies = array_values(array_filter((array) ($row['dependencies'] ?? array())));

        echo '<div class="seo-analista-directive-block seo-analista-validation"><strong>Validación antes de ejecutar</strong><ul>';
        if ($query !== '') echo '<li><strong>Consulta:</strong> ' . esc_html($query) . '</li>';
        echo '<li><strong>Destino:</strong> ' . (!empty($row['target_resolved']) ? '<a target="_blank" rel="noopener" href="' . esc_url((string) ($row['target_url'] ?? '')) . '">' . esc_html((string) ($row['target_url'] ?? '')) . '</a>' : '<span>destino sin resolver</span>') . '</li>';
        if ($match_type !== '') echo '<li><strong>Match:</strong> ' . esc_html(strtoupper($match_type)) . ' · ' . esc_html((string) $match_confidence) . '/100' . (!empty($row['match_reason']) ? ' · ' . esc_html((string) $row['match_reason']) : '') . '</li>';
        if ($confidence_level !== '') echo '<li><strong>Confianza:</strong> ' . esc_html(strtoupper($confidence_level)) . ' · ' . esc_html((string) absint($row['confidence'] ?? 0)) . '/100</li>';
        echo '<li><strong>Evidencia GSC/SEO:</strong> ' . esc_html(number_format_i18n(absint($evidence['impressions'] ?? 0))) . ' impresiones · ' . esc_html(number_format_i18n(absint($evidence['clicks'] ?? 0))) . ' clics · ' . esc_html(number_format_i18n(absint($evidence['queries'] ?? 0))) . ' consultas · delta ' . esc_html(number_format_i18n((float) ($evidence['absolute_delta'] ?? 0), 0)) . '</li>';
        if (!empty($evidence['sources'])) echo '<li><strong>Fuentes:</strong> ' . esc_html(implode(' · ', array_map('strval', (array) $evidence['sources']))) . '</li>';
        if (!empty($commercial['label'])) echo '<li><strong>Preparación comercial:</strong> ' . esc_html((string) $commercial['label']) . '</li>';
        $ga4 = (array) ($commercial['ga4_signals'] ?? array());
        if (!empty($ga4['available'])) {
            $ga4_parts = array();
            if ($ga4['sessions'] !== null) $ga4_parts[] = 'sesiones ' . number_format_i18n((float) $ga4['sessions'], 0);
            if ($ga4['pageviews'] !== null) $ga4_parts[] = 'vistas ' . number_format_i18n((float) $ga4['pageviews'], 0);
            if ($ga4['conversions'] !== null) $ga4_parts[] = 'conversiones ' . number_format_i18n((float) $ga4['conversions'], 0);
            if ($ga4['revenue'] !== null) $ga4_parts[] = 'ingresos ' . number_format_i18n((float) $ga4['revenue'], 2);
            if ($ga4_parts) echo '<li><strong>Señales GA4 (separadas de GSC):</strong> ' . esc_html(implode(' · ', $ga4_parts)) . '</li>';
        }
        if (!empty($row['recommended_owner'])) echo '<li><strong>Responsable recomendado:</strong> ' . esc_html((string) $row['recommended_owner']) . '</li>';
        if ($priority_breakdown) echo '<li><strong>Prioridad:</strong> ' . esc_html((string) absint($priority_breakdown['pre_validation_score'] ?? 0)) . ' → ' . esc_html((string) absint($priority_breakdown['final_score'] ?? 0)) . ' tras validación.</li>';
        echo '</ul></div>';

        if ($dependencies) {
            echo '<div class="seo-analista-directive-block"><strong>Dependencias antes de ejecutar</strong><ul>';
            foreach ($dependencies as $dependency) echo '<li>' . esc_html((string) $dependency) . '</li>';
            echo '</ul></div>';
        }

        if ($issues) {
            echo '<div class="seo-analista-directive-block"><strong>Qué revisar</strong><ul>';
            foreach (array_slice($issues, 0, 8) as $issue) echo '<li>' . esc_html((string) $issue) . '</li>';
            echo '</ul></div>';
        }
        if ($changes) {
            echo '<div class="seo-analista-directive-block"><strong>Qué hacer</strong><ol>';
            foreach (array_slice($changes, 0, 10) as $change) echo '<li>' . esc_html((string) $change) . '</li>';
            echo '</ol></div>';
        }
        if ($keywords) {
            echo '<div class="seo-analista-directive-block"><strong>Consultas / términos a cubrir</strong><p>' . esc_html(implode(' · ', array_slice($keywords, 0, 10))) . '</p></div>';
        }
        $measurement = array_values(array_filter((array) ($row['measurement'] ?? array())));
        if ($measurement) {
            echo '<div class="seo-analista-directive-block"><strong>Cómo medir si funcionó</strong><ul>';
            foreach (array_slice($measurement, 0, 6) as $item) echo '<li>' . esc_html((string) $item) . '</li>';
            echo '</ul></div>';
        }

        $edit_url = (string) ($entity['edit_url'] ?? '');
        $public_url = (string) ($entity['url'] ?? $target['url'] ?? '');
        if ($edit_url !== '' || $public_url !== '') {
            echo '<div class="seo-analista-links">';
            if ($edit_url !== '') echo '<a class="button button-small" href="' . esc_url($edit_url) . '">Editar ' . esc_html(strtolower((string) ($entity['type_label'] ?? 'entidad'))) . '</a> ';
            if ($public_url !== '') echo '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url($public_url) . '">Ver URL</a>';
            echo '</div>';
        }
    }
}

if (!function_exists('seo_analista_render_directive_rows')) {
    function seo_analista_render_directive_rows(array $rows, $title, $description, $limit = 40) {
        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p></div></div>';
        if (!$rows) {
            echo '<p class="description">No hay directrices suficientes para este bloque con los datos actuales.</p></section>';
            return;
        }
        echo '<div class="seo-analista-plan">';
        foreach (array_slice($rows, 0, max(1, absint($limit))) as $index => $row) {
            $catalog = (array) ($row['catalog'] ?? array());
            $entity = (array) ($row['entity'] ?? array());
            echo '<article class="seo-analista-plan-row"><div class="seo-analista-plan-priority"><small>#' . esc_html($index + 1) . '</small>' . wp_kses_post(seo_analista_score_badge($row['priority'] ?? 0)) . '</div><div class="seo-analista-plan-body">';
            echo '<div class="seo-analista-plan-head"><strong>' . esc_html((string) ($row['topic'] ?? '')) . '</strong><span>' . esc_html((string) ($row['action_label'] ?? '')) . '</span>';
            if (!empty($row['work_bucket'])) echo '<em>' . esc_html(str_replace('_', ' ', (string) $row['work_bucket'])) . '</em>';
            if (!empty($row['objective']['primary_label'])) echo '<em>Objetivo: ' . esc_html((string) $row['objective']['primary_label']) . '</em>';
            if (!empty($row['intervention']['label'])) echo '<em>Intervención: ' . esc_html((string) $row['intervention']['label']) . '</em>';
            if (!empty($entity['type_label'])) echo '<em>' . esc_html((string) $entity['type_label']) . '</em>';
            echo '</div>';
            echo '<p>' . esc_html((string) ($row['reason'] ?? '')) . '</p>';
            $meta = array();
            if (!empty($row['source'])) $meta[] = 'Fuentes: ' . (string) $row['source'];
            if (!empty($catalog['category'])) $meta[] = 'Categoría: ' . (string) $catalog['category'];
            if (isset($catalog['products']) && null !== $catalog['products']) $meta[] = 'Productos: ' . number_format_i18n((int) $catalog['products']);
            if (!empty($row['metrics']['position'])) $meta[] = 'Posición: ' . number_format_i18n((float) $row['metrics']['position'], 1);
            if (!empty($row['metrics']['impressions'])) $meta[] = 'Impresiones: ' . number_format_i18n((float) $row['metrics']['impressions'], 0);
            if (isset($row['metrics']['clicks'])) $meta[] = 'Clics: ' . number_format_i18n((float) $row['metrics']['clicks'], 0);
            if (isset($row['metrics']['ctr'])) $meta[] = 'CTR: ' . number_format_i18n(((float) $row['metrics']['ctr']) * 100, 2) . '%';
            if (isset($row['evidence_volume']['previous_impressions'])) $meta[] = 'Base anterior: ' . number_format_i18n((float) $row['evidence_volume']['previous_impressions'], 0);
            if (!empty($row['match_type'])) $meta[] = 'Match: ' . strtoupper((string) $row['match_type']) . ' ' . absint($row['match_confidence'] ?? 0) . '/100';
            if (!empty($row['confidence_level'])) $meta[] = 'Confianza: ' . strtoupper((string) $row['confidence_level']);
            if (!empty($row['recommended_owner'])) $meta[] = 'Owner: ' . (string) $row['recommended_owner'];
            if (!empty($row['pre_validation_bucket']) && (string) $row['pre_validation_bucket'] !== (string) ($row['work_bucket'] ?? '')) {
                $meta[] = '3.8.0 habría quedado: ' . str_replace('_', ' ', (string) $row['pre_validation_bucket']);
            }
            $growth_previous = isset($row['growth_quality']['previous']) ? (float) $row['growth_quality']['previous'] : null;
            $growth_current = isset($row['growth_quality']['current']) ? (float) $row['growth_quality']['current'] : null;
            if ($growth_previous !== null && $growth_previous <= 0 && $growth_current > 0) {
                $meta[] = 'Nueva señal del periodo';
            } elseif (isset($row['metrics']['impressions_growth_pct']) && null !== $row['metrics']['impressions_growth_pct']) {
                $meta[] = 'Variación: ' . sprintf('%+.0f%%', (float) $row['metrics']['impressions_growth_pct']);
            }
            $market_score = max((float) ($row['market']['score'] ?? 0), (float) ($row['metrics']['market_score'] ?? 0));
            $market_kind = sanitize_key((string) ($row['market']['signal_kind'] ?? ''));
            $source_norm = seo_analista_normalize_text((string) ($row['source'] ?? ''));
            $is_real_trends = $market_score > 0 && (strpos($source_norm, 'google trends') !== false || $market_kind !== '');
            $trends_growth = (float) ($row['market']['trends_growth'] ?? ($is_real_trends ? ($row['market']['growth'] ?? 0) : 0));
            if ($is_real_trends && $trends_growth != 0.0) $meta[] = 'Google Trends: ' . sprintf('%+.0f%%', $trends_growth);
            if (!empty($row['market']['breakout'])) $meta[] = 'BREAKOUT';
            if (!empty($row['position_movement']['previous_position']) && abs((float) ($row['position_movement']['gain'] ?? 0)) >= 2) {
                $gain = (float) $row['position_movement']['gain'];
                $meta[] = $gain > 0
                    ? 'Posición mejora ' . number_format_i18n(abs($gain), 1)
                    : 'Posición empeora ' . number_format_i18n(abs($gain), 1);
            }
            if (!empty($row['catalog_strategy']['label'])) $meta[] = 'Surtido: ' . (string) $row['catalog_strategy']['label'];
            if (!empty($row['family_demand']['label'])) $meta[] = (string) $row['family_demand']['label'];
            if (!empty($row['objective'])) {
                $meta[] = 'Score interno autoridad ' . absint($row['objective']['authority'] ?? 0) . '/100';
                $meta[] = 'Score interno visitas ' . absint($row['objective']['traffic'] ?? 0) . '/100';
                $meta[] = 'Score interno ventas ' . absint($row['objective']['sales'] ?? 0) . '/100';
            }
            if (isset($row['confidence'])) $meta[] = 'Confianza ' . absint($row['confidence']) . '/100';
            if ($meta) echo '<div class="seo-analista-plan-meta">' . esc_html(implode(' · ', $meta)) . '</div>';
            seo_analista_render_directive_details($row);
            echo '</div></article>';
        }
        echo '</div></section>';
    }
}

if (!function_exists('seo_analista_render_content_view')) {
    function seo_analista_render_content_view($days) {
        $rows = function_exists('seo_analista_literature_work') ? seo_analista_literature_work($days, 120) : array();
        $counts = array('post' => 0, 'page' => 0, 'product' => 0, 'improve' => 0, 'push' => 0);
        foreach ($rows as $row) {
            $type = (string) ($row['entity']['type'] ?? '');
            if (isset($counts[$type])) $counts[$type]++;
            if (strpos((string) ($row['action'] ?? ''), 'MEJORAR_') === 0) $counts['improve']++;
            if (strpos((string) ($row['action'] ?? ''), 'IMPULSAR_') === 0) $counts['push']++;
        }
        echo '<div class="seo-analista-grid">';
        seo_analista_render_metric_card('Posts a trabajar', number_format_i18n($counts['post']), '', 'Literatura, meta, etiquetas, Vocabulary, enlaces y demanda.');
        seo_analista_render_metric_card('Páginas a trabajar', number_format_i18n($counts['page']), '', 'Landings editoriales fuera de páginas funcionales.');
        seo_analista_render_metric_card('Productos a trabajar', number_format_i18n($counts['product']), '', 'Productos con visibilidad orgánica y margen editorial.');
        seo_analista_render_metric_card('Mejorar', number_format_i18n($counts['improve']), '', 'Hay problemas concretos que corregir.');
        seo_analista_render_metric_card('Impulsar', number_format_i18n($counts['push']), '', 'La base es válida y hay demanda que aprovechar.');
        echo '</div>';
        seo_analista_render_directive_rows($rows, 'Literatura y contenido que debemos trabajar', 'Directrices por URL: qué falla, qué búsquedas cubrir y qué cambios concretos aplicar.', 60);
    }
}

if (!function_exists('seo_analista_render_structure_view')) {
    function seo_analista_render_structure_view($days) {
        $architecture = function_exists('seo_analista_architecture_advice') ? seo_analista_architecture_advice($days, 80) : array();
        $architecture_rows = (array) ($architecture['recommendations'] ?? array());
        $architecture_summary = (array) ($architecture['summary'] ?? array());
        $rows = function_exists('seo_analista_structure_work') ? seo_analista_structure_work($days, 140) : array();
        $counts = array('category' => 0, 'cluster' => 0, 'hub_primary' => 0, 'hub_secondary' => 0);
        foreach ($rows as $row) {
            $type = (string) ($row['entity']['type'] ?? '');
            if (isset($counts[$type])) $counts[$type]++;
        }
        echo '<div class="seo-analista-grid">';
        seo_analista_render_metric_card('Recomendaciones arquitectura', number_format_i18n((int) ($architecture_summary['recommendations'] ?? 0)), '', 'Solo lectura: no mueve ni crea nodos automaticamente.');
        seo_analista_render_metric_card('Nuevos secundarios', number_format_i18n((int) ($architecture_summary['create_secondary'] ?? 0)), '', 'Familias coherentes hoy mezcladas dentro de otros hubs.');
        seo_analista_render_metric_card('Nuevos primarios', number_format_i18n((int) ($architecture_summary['create_primary'] ?? 0)), '', 'Dominios comerciales con varias familias secundarias.');
        seo_analista_render_metric_card('Revisiones de Cluster', number_format_i18n((int) ($architecture_summary['cluster_review'] ?? 0)), '', 'Primarios que pueden estar actuando como un Cluster.');
        echo '</div>';
        seo_analista_render_directive_rows($architecture_rows, 'Arquitectura comercial recomendada', 'Compara catalogo, Vocabulary, demanda y Google Product Category cuando exista. Google se usa como referencia, no como jerarquia obligatoria.', 60);
        echo '<div class="seo-analista-grid">';
        seo_analista_render_metric_card('Categorías', number_format_i18n($counts['category']), '', 'Categorías con mejora o impulso justificable.');
        seo_analista_render_metric_card('Clusters', number_format_i18n($counts['cluster']), '', 'Clusters con literatura, relaciones o demanda a revisar.');
        seo_analista_render_metric_card('Hubs primarios', number_format_i18n($counts['hub_primary']), '', 'Hubs primarios que necesitan mejora o refuerzo.');
        seo_analista_render_metric_card('Hubs secundarios', number_format_i18n($counts['hub_secondary']), '', 'Hubs secundarios conectados a familias de catálogo.');
        echo '</div>';
        seo_analista_render_directive_rows($rows, 'Categorías, hubs y clusters', 'Prioriza dónde reforzar arquitectura, literatura, Vocabulary y enlazado; diferencia entre mejorar una entidad débil e impulsar una que ya tiene demanda.', 70);
    }
}

if (!function_exists('seo_analista_render_trends_view')) {
    function seo_analista_render_trends_view($days) {
        $market = function_exists('seo_analista_market_signals') ? seo_analista_market_signals(100) : array();
        $accel = function_exists('seo_analista_search_acceleration') ? seo_analista_search_acceleration($days, 60) : array();
        $work = function_exists('seo_analista_trend_work') ? seo_analista_trend_work($days, 100) : array();
        $discoveries = 0;
        foreach ($market as $row) if ((string) ($row['signal_kind'] ?? '') === 'discovery') $discoveries++;
        echo '<div class="seo-analista-grid">';
        seo_analista_render_metric_card('Señales Trends', number_format_i18n(count($market)), '', 'Incluye consultas descubiertas; ya no se descartan las discovery.');
        seo_analista_render_metric_card('Descubrimientos', number_format_i18n($discoveries), '', 'Consultas/temas relacionados encontrados por Trends.');
        seo_analista_render_metric_card('Aceleraciones GSC', number_format_i18n(count($accel)), '', 'Búsquedas propias que crecen en impresiones o posición.');
        seo_analista_render_metric_card('Directrices', number_format_i18n(count($work)), '', 'Señal traducida a categoría, URL o decisión de cobertura.');
        echo '</div>';
        if (!$market) echo '<div class="notice notice-warning inline"><p><strong>Google Trends no está entregando señales almacenadas.</strong> Analista muestra aparte la aceleración de Search Console para no confundirla con demanda exterior.</p></div>';
        seo_analista_render_directive_rows($work, 'Qué multiplicar o impulsar', 'Cada tendencia se cruza con catálogo y contenido local para decir si hay que impulsar una categoría/URL existente o resolver una cobertura nueva.', 60);
    }
}

if (!function_exists('seo_analista_render_plan')) {
    function seo_analista_render_plan(array $plan, $limit = 30) {
        $groups = array(
            'HACER_AHORA' => array('title'=>'Hacer ahora','description'=>'Solo trabajos con destino y entidad validados, evidencia suficiente y confianza compatible con ejecución. Máximo 10; no se rellena artificialmente.'),
            'HACER_DESPUES' => array('title'=>'Hacer después','description'=>'Trabajo válido y verificable, pero con menor retorno inmediato, menor urgencia o alguna validación pendiente no bloqueante.'),
            'INVESTIGAR' => array('title'=>'Investigar antes de tocar contenido','description'=>'Destino, entidad, modelo, surtido o preparación comercial no están suficientemente demostrados.'),
            'VIGILAR' => array('title'=>'Vigilar','description'=>'Señales válidas pero todavía inmaduras para dedicar trabajo de ejecución.'),
            'ESPERAR_DATOS' => array('title'=>'Esperar datos','description'=>'La muestra es demasiado pequeña o inestable; conservar línea base y revisar cuando aumente la evidencia.'),
            'SIN_ACCION' => array('title'=>'Sin acción por ahora','description'=>'No existe evidencia suficiente para dedicar recursos ahora.'),
        );
        $bucketed = array();
        foreach ($plan as $row) {
            $bucket = (string) ($row['work_bucket'] ?? 'HACER_DESPUES');
            if (!isset($groups[$bucket])) $bucket = 'HACER_DESPUES';
            $bucketed[$bucket][] = $row;
        }
        $remaining = max(1, absint($limit));
        foreach ($groups as $bucket => $meta) {
            if (empty($bucketed[$bucket]) || $remaining <= 0) continue;
            $rows = $bucketed[$bucket];
            $take = min($remaining, $bucket === 'HACER_AHORA' ? 10 : count($rows));
            seo_analista_render_directive_rows($rows, $meta['title'], $meta['description'], $take);
            $remaining -= $take;
        }
    }
}

if (!function_exists('seo_analista_render_roadmap')) {
    function seo_analista_render_roadmap(array $plan, array $summary, array $suppliers, array $search) {
        echo '<div class="seo-analista-grid">';
        seo_analista_render_metric_card('Hacer ahora', number_format_i18n((int) ($summary['hacer_ahora'] ?? $summary['high'] ?? 0)), '', 'Máximo 10 trabajos con mejor retorno esperado.');
        seo_analista_render_metric_card('Hacer después', number_format_i18n((int) ($summary['hacer_despues'] ?? 0)), '', 'Oportunidades válidas de segunda prioridad.');
        seo_analista_render_metric_card('Investigar', number_format_i18n((int) ($summary['investigar'] ?? 0)), '', 'Hay que resolver destino, asociación o comercialidad antes de ejecutar.');
        seo_analista_render_metric_card('Vigilar', number_format_i18n((int) ($summary['vigilar'] ?? 0)), '', 'Señales que aún necesitan más evidencia.');
        seo_analista_render_metric_card('Esperar datos', number_format_i18n((int) ($summary['esperar_datos'] ?? 0)), '', 'Muestra demasiado pequeña o inestable para priorizar.');
        seo_analista_render_metric_card('Objetivo autoridad', number_format_i18n((int) ($summary['authority'] ?? 0)), '', 'Trabajos cuyo objetivo principal es ganar autoridad.');
        seo_analista_render_metric_card('Objetivo visitas', number_format_i18n((int) ($summary['traffic'] ?? 0)), '', 'Trabajos cuyo objetivo principal es ganar tráfico orgánico.');
        seo_analista_render_metric_card('Objetivo ventas', number_format_i18n((int) ($summary['sales'] ?? 0)), '', 'Trabajos cuyo objetivo principal es mejorar demanda comercial.');
        seo_analista_render_metric_card('Proveedores con aviso', number_format_i18n(count((array) ($suppliers['issues'] ?? array()))), '', 'Problemas de feed que pueden afectar al catálogo.');
        echo '</div>';
        seo_analista_render_plan($plan, 30);

        if (!empty($search['gaps'])) {
            echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Señales de clientes que no debemos perder</h2><p>Búsquedas internas con pocos resultados, útiles para validar el guion de catálogo.</p></div></div><div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Búsqueda</th><th>Veces</th><th>Resultados medios</th><th>Sin resultado</th></tr></thead><tbody>';
            foreach (array_slice((array) $search['gaps'], 0, 15) as $row) {
                echo '<tr><td><strong>' . esc_html((string) ($row['search_term'] ?? '')) . '</strong></td><td>' . esc_html(number_format_i18n((int) ($row['searches'] ?? 0))) . '</td><td>' . esc_html(number_format_i18n((float) ($row['avg_results'] ?? 0), 1)) . '</td><td>' . esc_html(number_format_i18n((int) ($row['zero_count'] ?? 0))) . '</td></tr>';
            }
            echo '</tbody></table></div></section>';
        }
    }
}

if (!function_exists('seo_analista_render_sources')) {
    function seo_analista_render_sources(array $health, array $competition, array $suppliers) {
        echo '<details class="seo-analista-sources"><summary><strong>Fuentes y calidad de datos</strong> · una fuente pendiente pesa 0 en la prioridad</summary><div class="seo-analista-source-grid">';
        foreach ($health as $key => $row) {
            $state = (string) ($row['state'] ?? 'pending');
            $weight = isset($row['weight']) ? ' · peso ' . number_format_i18n((float) $row['weight'], 2) : '';
            echo '<div class="seo-analista-source ' . esc_attr($state) . '"><strong>' . esc_html((string) ($row['label'] ?? 'Fuente')) . '</strong><span>' . esc_html(strtoupper($state)) . '</span><p>' . esc_html((string) ($row['detail'] ?? '') . $weight) . '</p></div>';
        }
        if (!isset($health['competition'])) echo '<div class="seo-analista-source ' . (!empty($competition['available']) ? 'ok' : 'pending') . '"><strong>Competencia</strong><span>' . (!empty($competition['available']) ? 'OK' : 'PENDIENTE') . '</span><p>' . esc_html(!empty($competition['available']) ? number_format_i18n((int) ($competition['row_count'] ?? 0)) . ' posiciones externas disponibles.' : 'Sin posiciones externas disponibles.') . '</p></div>';
        if (!isset($health['suppliers'])) echo '<div class="seo-analista-source ' . (!empty($suppliers['available']) ? 'ok' : 'pending') . '"><strong>Proveedores</strong><span>' . (!empty($suppliers['available']) ? 'OK' : 'PENDIENTE') . '</span><p>' . esc_html(!empty($suppliers['available']) ? number_format_i18n((int) ($suppliers['providers'] ?? 0)) . ' proveedores en la base local.' : 'Fuente no disponible.') . '</p></div>';
        echo '</div></details>';
        if (function_exists('seo_analista_render_bing_settings_form')) seo_analista_render_bing_settings_form();
    }
}

if (!function_exists('seo_analista_render_styles')) {
    function seo_analista_render_styles() {
        echo '<style>
        .seo-analista-wrap{max-width:1500px}.seo-analista-toolbar{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin:8px 0 12px}.seo-analista-toolbar form{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.seo-analista-subnav{display:flex;gap:4px;flex-wrap:wrap;border-bottom:1px solid #c3c4c7;margin:0 0 18px}.seo-analista-subnav a{display:block;text-decoration:none;padding:10px 14px;color:#3c434a;font-weight:600;border:1px solid transparent;border-bottom:0}.seo-analista-subnav a.is-active{background:#fff;border-color:#c3c4c7;color:#1d2327;margin-bottom:-1px}
        .seo-analista-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:12px;margin:0 0 18px}.seo-analista-grid.compact{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}.seo-analista-card,.seo-analista-section,.seo-analista-chart{background:#fff;border:1px solid #dcdcde;border-radius:8px}.seo-analista-card{padding:15px}.seo-analista-label{font-size:11px;text-transform:uppercase;color:#646970;font-weight:700}.seo-analista-number{font-size:28px;line-height:1.1;font-weight:750;margin:5px 0}.seo-analista-delta{font-size:12px}.seo-analista-detail{color:#646970;font-size:12px;line-height:1.45;margin-top:7px}.seo-analista-good{color:#1d6b43;font-weight:700}.seo-analista-bad{color:#b32d2e;font-weight:700}.seo-analista-neutral{color:#646970;font-weight:700}
        .seo-analista-section{padding:18px;margin:0 0 18px}.seo-analista-section-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.seo-analista-section-head h2{margin:0 0 5px}.seo-analista-section-head p{margin:0;color:#646970;max-width:980px}.seo-analista-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.seo-analista-action{border:1px solid #dcdcde;border-radius:7px;padding:14px}.seo-analista-action strong{display:block;margin-bottom:5px}.seo-analista-action p{margin:0;color:#50575e;line-height:1.45}
        .seo-analista-charts{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px}.seo-analista-chart{padding:13px}.seo-analista-chart-head{display:flex;justify-content:space-between;gap:10px}.seo-analista-chart-head strong,.seo-analista-chart-head small{display:block}.seo-analista-chart-head small{color:#646970;margin-top:3px}.seo-analista-chart-head span{font-size:22px;font-weight:700}.seo-analista-chart svg{width:100%;height:auto;margin-top:7px}.seo-analista-chart .line{fill:none;stroke:#2271b1;stroke-width:3;stroke-linejoin:round;stroke-linecap:round}.seo-analista-chart .axis{stroke:#dcdcde;stroke-width:1}
        .seo-analista-legend{display:flex;gap:14px;font-size:12px;color:#646970;margin-bottom:12px}.seo-analista-legend i{display:inline-block;width:18px;height:5px;border-radius:4px;margin-right:5px;vertical-align:middle}.seo-analista-legend i.current{background:#2271b1}.seo-analista-legend i.previous{background:#c3c4c7}.seo-analista-bars{display:flex;flex-direction:column;gap:11px}.seo-analista-bar-meta{display:flex;justify-content:space-between;gap:12px;font-size:12px}.seo-analista-bar-track{height:12px;background:#f0f0f1;border-radius:999px;position:relative;overflow:hidden;margin-top:4px}.seo-analista-bar-track span{position:absolute;left:0;top:0;height:100%;border-radius:999px}.seo-analista-bar-track .previous{background:#c3c4c7;height:100%}.seo-analista-bar-track .current{background:#2271b1;height:6px;top:3px}
        .seo-analista-table-wrap{overflow:auto}.seo-analista-table-wrap td{vertical-align:top}.seo-analista-own td{background:#f0f6fc!important}.seo-analista-score{display:inline-block;border-radius:999px;padding:4px 8px;font-weight:700;white-space:nowrap}.seo-analista-score.high{background:#edfaef;color:#1d6b43}.seo-analista-score.medium{background:#fff8e5;color:#8a6500}.seo-analista-score.low{background:#f0f0f1;color:#50575e}
        .seo-analista-plan{display:flex;flex-direction:column;gap:10px}.seo-analista-plan-row{display:flex;gap:14px;border:1px solid #dcdcde;border-left:5px solid #2271b1;border-radius:7px;padding:14px;background:#fff}.seo-analista-plan-priority{min-width:82px}.seo-analista-plan-priority small{display:block;color:#646970;margin-bottom:6px}.seo-analista-plan-body{min-width:0;flex:1}.seo-analista-plan-head{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.seo-analista-plan-head strong{font-size:16px}.seo-analista-plan-head span{font-size:11px;font-weight:700;background:#f0f6fc;border-radius:999px;padding:4px 8px}.seo-analista-plan-body p{margin:8px 0}.seo-analista-plan-meta{font-size:12px;color:#646970}.seo-analista-plan-head em{font-size:11px;color:#646970;font-style:normal;border:1px solid #dcdcde;border-radius:999px;padding:3px 7px}.seo-analista-directive-block{margin-top:10px;padding-top:9px;border-top:1px solid #f0f0f1}.seo-analista-directive-block strong{display:block;margin-bottom:5px}.seo-analista-directive-block ul,.seo-analista-directive-block ol{margin:5px 0 0 20px}.seo-analista-directive-block li{margin:3px 0}.seo-analista-links{margin-top:11px}.seo-analista-plan details{margin-top:8px}.seo-analista-forms form{margin:12px 0}.seo-analista-forms details{margin-top:16px}.seo-analista-sources{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;margin:18px 0}.seo-analista-source-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-top:12px}.seo-analista-source{border:1px solid #dcdcde;border-radius:7px;padding:12px}.seo-analista-source>span{float:right;font-size:10px;font-weight:700}.seo-analista-source p{color:#646970;margin:7px 0 0;font-size:12px}.seo-analista-source.ok{border-left:4px solid #1d6b43}.seo-analista-source.partial{border-left:4px solid #dba617}.seo-analista-source.pending{border-left:4px solid #b32d2e}
        @media(max-width:782px){.seo-analista-toolbar{display:block}.seo-analista-toolbar form{margin-top:12px}.seo-analista-subnav{overflow-x:auto;flex-wrap:nowrap}.seo-analista-subnav a{white-space:nowrap}.seo-analista-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.seo-analista-number{font-size:24px}.seo-analista-plan-row{display:block}.seo-analista-plan-priority{margin-bottom:9px}.seo-analista-bar-meta{display:block}.seo-analista-bar-meta span{display:block;margin-top:2px}}
        </style>';
    }
}

if (!function_exists('seo_analista_render_report')) {
    function seo_analista_render_report() {
        if (!current_user_can('manage_options')) return;

        $days = isset($_GET['analista_days']) ? seo_analista_days(wp_unslash($_GET['analista_days'])) : 28;

        echo '<div class="seo-analista-wrap">';
        seo_analista_render_styles();
        echo '<style>
        .seo-analista-lazy-intro{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;border-radius:8px;padding:15px 18px;margin:0 0 16px}.seo-analista-lazy-intro p{margin:5px 0 0;color:#646970}
        .seo-analista-lazy-controls{display:grid;grid-template-columns:repeat(auto-fit,minmax(245px,1fr));gap:10px;margin:0 0 16px}.seo-analista-lazy-controls .button{height:auto;min-height:78px;text-align:left;padding:12px 14px;white-space:normal;display:block;background:#fff}.seo-analista-lazy-controls .button strong{display:block;font-size:14px;margin-bottom:5px}.seo-analista-lazy-controls .button span{display:block;color:#646970;font-weight:400;line-height:1.35}.seo-analista-lazy-controls .button.is-running{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
        .seo-analista-lazy-status{min-height:30px;margin:8px 0 12px;color:#50575e}.seo-analista-lazy-status .spinner{float:none;margin:0 7px 0 0;vertical-align:middle}.seo-analista-lazy-result{min-height:120px}.seo-analista-lazy-empty{background:#f6f7f7;border:1px dashed #c3c4c7;border-radius:8px;padding:22px;text-align:center;color:#50575e}.seo-analista-lazy-empty p{margin:5px 0 0}.seo-analista-partial-head{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:15px 18px;margin-bottom:16px}.seo-analista-partial-head h2{margin:0 0 5px}.seo-analista-partial-head p{margin:0;color:#646970}
        </style>';

        echo '<div class="seo-analista-toolbar"><div><h2 style="margin:0 0 4px">Analista</h2><p style="margin:0;color:#646970">La página ya no calcula todos los informes al abrirse. Ejecuta únicamente el bloque que necesites.</p></div>';
        echo '<form method="get"><input type="hidden" name="page" value="seo-reports"><input type="hidden" name="tab" value="analista"><label><strong>Periodo</strong></label><select name="analista_days">';
        foreach (array(28, 60, 90) as $option) echo '<option value="' . absint($option) . '" ' . selected($days, $option, false) . '>' . absint($option) . ' días</option>';
        echo '</select><button class="button">Cambiar periodo</button>';
        if (function_exists('seo_analista_export_json_url')) echo '<a class="button" href="' . esc_url(seo_analista_export_json_url($days)) . '">Generar JSON completo</a>';
        echo '</form></div>';

        if (function_exists('seo_analista_lazy_render_shell')) {
            seo_analista_lazy_render_shell($days);
        } else {
            echo '<div class="notice notice-error inline"><p>No se ha cargado el motor de informes parciales de Analista.</p></div>';
        }

        echo '</div>';
    }
}
