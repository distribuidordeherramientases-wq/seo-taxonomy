<?php
/**
 * Vista administrativa de Informes SEO > Analista.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_metric_delta')) {
    function seo_analista_metric_delta($current, $previous, $inverse = false) {
        $change = seo_analista_percent_change($current, $previous);
        if (null === $change) {
            return '<span style="color:#646970;">nuevo</span>';
        }
        $good = $inverse ? $change < 0 : $change > 0;
        $bad = $inverse ? $change > 0 : $change < 0;
        $color = $good ? '#1d6b43' : ($bad ? '#b32d2e' : '#646970');
        $arrow = $change > 0 ? '↑' : ($change < 0 ? '↓' : '→');
        return '<span style="color:' . esc_attr($color) . ';font-weight:600;">' . esc_html($arrow . ' ' . number_format_i18n(abs($change), 1) . '%') . '</span>';
    }
}

if (!function_exists('seo_analista_score_badge')) {
    function seo_analista_score_badge($score) {
        $score = absint($score);
        if ($score >= 75) {
            $bg = '#edfaef'; $fg = '#1d6b43'; $label = 'Alta';
        } elseif ($score >= 55) {
            $bg = '#fff8e5'; $fg = '#8a6500'; $label = 'Media';
        } else {
            $bg = '#f0f0f1'; $fg = '#50575e'; $label = 'Seguimiento';
        }
        return '<span style="display:inline-block;padding:4px 8px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($fg) . ';font-weight:700;">' . esc_html($score . '/100 · ' . $label) . '</span>';
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

if (!function_exists('seo_analista_render_distribution')) {
    function seo_analista_render_distribution(array $current, array $previous) {
        $labels = array(
            'top3' => 'Top 3',
            'top10' => '4–10',
            'top20' => '11–20',
            'top50' => '21–50',
            'top100' => '51–100',
            'outside' => '>100',
        );
        $total = max(1, array_sum($current));

        echo '<section class="seo-analista-section">';
        echo '<div class="seo-analista-section-head"><div><h2>Distribución de posiciones</h2><p>Equivalente interno al reparto Top 3 / Top 10 / Top 20 / Top 100, calculado con las consultas reales almacenadas por Search Console.</p></div></div>';
        echo '<div class="seo-analista-bars">';
        foreach ($labels as $key => $label) {
            $value = (int) ($current[$key] ?? 0);
            $prev = (int) ($previous[$key] ?? 0);
            $pct = min(100, ($value / $total) * 100);
            echo '<div class="seo-analista-bar-row">';
            echo '<div class="seo-analista-bar-meta"><strong>' . esc_html($label) . '</strong><span>' . esc_html(number_format_i18n($value)) . ' · ' . wp_kses_post(seo_analista_metric_delta($value, $prev)) . '</span></div>';
            echo '<div class="seo-analista-bar-track"><span style="width:' . esc_attr(number_format($pct, 2, '.', '')) . '%"></span></div>';
            echo '</div>';
        }
        echo '</div></section>';
    }
}

if (!function_exists('seo_analista_render_intents')) {
    function seo_analista_render_intents(array $intents) {
        $labels = array(
            'informativa' => 'Informativa',
            'comercial' => 'Comercial',
            'transaccional' => 'Transaccional',
            'navegacion' => 'Navegación',
        );
        $total = max(1, array_sum($intents));
        echo '<section class="seo-analista-section">';
        echo '<div class="seo-analista-section-head"><div><h2>Palabras clave por intención</h2><p>Clasificación heurística propia para priorizar demanda comercial y transaccional. No intenta replicar el algoritmo propietario de SEMrush.</p></div></div>';
        echo '<div class="seo-analista-intents">';
        foreach ($labels as $key => $label) {
            $count = (int) ($intents[$key] ?? 0);
            $pct = ($count / $total) * 100;
            echo '<div class="seo-analista-intent"><strong>' . esc_html(number_format_i18n($count)) . '</strong><span>' . esc_html($label) . '</span><small>' . esc_html(number_format_i18n($pct, 1) . '%') . '</small></div>';
        }
        echo '</div></section>';
    }
}

if (!function_exists('seo_analista_render_opportunities')) {
    function seo_analista_render_opportunities(array $rows) {
        echo '<section class="seo-analista-section">';
        echo '<div class="seo-analista-section-head"><div><h2>Oportunidades prioritarias</h2><p>Consultas donde ya existe visibilidad y hay margen realista para avanzar. El score combina posición, impresiones, intención, CTR, tendencia y cobertura de catálogo.</p></div></div>';
        if (!$rows) {
            echo '<p class="description">No hay suficientes consultas para construir oportunidades.</p></section>';
            return;
        }

        echo '<div class="seo-analista-table-wrap"><table class="widefat striped seo-analista-table"><thead><tr>';
        echo '<th>Consulta</th><th>Score</th><th>Intención</th><th>Posición</th><th>Cambio</th><th>Impresiones</th><th>CTR</th><th>Cobertura catálogo</th>';
        echo '</tr></thead><tbody>';
        foreach (array_slice($rows, 0, 30) as $row) {
            $change = $row['position_change'];
            $change_text = null === $change ? 'Nueva' : (($change > 0 ? '↑ ' : ($change < 0 ? '↓ ' : '→ ')) . number_format_i18n(abs($change), 1));
            $catalog = (array) ($row['catalog'] ?? array());
            $catalog_text = 'Sin relación clara';
            if (!empty($catalog['category'])) {
                $catalog_text = (string) $catalog['category'];
                if (isset($catalog['product_count'])) {
                    $catalog_text .= ' · ' . number_format_i18n((int) $catalog['product_count']) . ' productos';
                }
            }
            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($row['query_text'] ?? '')) . '</strong></td>';
            echo '<td>' . wp_kses_post(seo_analista_score_badge($row['score'] ?? 0)) . '</td>';
            echo '<td>' . esc_html(ucfirst((string) ($row['intent'] ?? ''))) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) ($row['position'] ?? 0), 1)) . '</td>';
            echo '<td>' . esc_html($change_text) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) ($row['impressions'] ?? 0), 0)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n(((float) ($row['ctr'] ?? 0)) * 100, 2) . '%') . '</td>';
            echo '<td>' . esc_html($catalog_text) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></section>';
    }
}

if (!function_exists('seo_analista_render_page_types')) {
    function seo_analista_render_page_types(array $types) {
        echo '<section class="seo-analista-section">';
        echo '<div class="seo-analista-section-head"><div><h2>Qué tipo de página está ganando mercado</h2><p>Reparte la visibilidad entre productos, categorías, entradas, marcas y landings para comprobar si Google empieza a trasladar autoridad al catálogo.</p></div></div>';
        if (!$types) {
            echo '<p class="description">Sin páginas con impresiones para este período.</p></section>';
            return;
        }
        echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Tipo</th><th>Páginas</th><th>Consultas asociadas</th><th>Impresiones</th><th>Clics</th></tr></thead><tbody>';
        foreach ($types as $label => $row) {
            echo '<tr><td><strong>' . esc_html($label) . '</strong></td>';
            echo '<td>' . esc_html(number_format_i18n((int) $row['pages'])) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) $row['queries'])) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) $row['impressions'], 0)) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((float) $row['clicks'], 0)) . '</td></tr>';
        }
        echo '</tbody></table></div></section>';
    }
}

if (!function_exists('seo_analista_render_market')) {
    function seo_analista_render_market(array $signals, array $guidance) {
        echo '<section class="seo-analista-section">';
        echo '<div class="seo-analista-section-head"><div><h2>Radar de mercado</h2><p>Señales de Google Trends cruzadas con el catálogo. Sirve para detectar demanda externa antes de que se convierta en tráfico propio.</p></div></div>';
        if ($signals) {
            echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Señal</th><th>Score mercado</th><th>Tipo</th><th>Variación</th><th>Encaje de catálogo</th></tr></thead><tbody>';
            foreach (array_slice($signals, 0, 12) as $row) {
                $catalog = (array) ($row['catalog'] ?? array());
                $fit = !empty($catalog['category']) ? (string) $catalog['category'] : 'Sin encaje claro';
                echo '<tr><td><strong>' . esc_html((string) ($row['query'] ?? '')) . '</strong></td>';
                echo '<td>' . esc_html(number_format_i18n((float) ($row['score'] ?? 0), 1)) . '</td>';
                echo '<td>' . esc_html((string) ($row['signal_kind'] ?? 'mercado')) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((float) ($row['max_growth'] ?? $row['interest_change_pct'] ?? 0), 1) . '%') . '</td>';
                echo '<td>' . esc_html($fit) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p class="description">Todavía no hay señales de mercado de Google Trends disponibles.</p>';
        }

        $items = (array) ($guidance['items'] ?? array());
        if ($items) {
            echo '<h3 style="margin-top:24px;">Demanda x catálogo</h3>';
            echo '<div class="seo-analista-actions">';
            foreach (array_slice($items, 0, 6) as $item) {
                echo '<div class="seo-analista-action">';
                echo '<strong>' . esc_html((string) ($item['label'] ?? '')) . '</strong>';
                echo '<span>' . esc_html((string) ($item['decision'] ?? '')) . '</span>';
                echo '<small>Score ' . esc_html(number_format_i18n((int) ($item['score'] ?? 0))) . ' · Pos. ' . esc_html(number_format_i18n((float) ($item['position'] ?? 0), 1)) . '</small>';
                if (!empty($item['note'])) echo '<p>' . esc_html((string) $item['note']) . '</p>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</section>';
    }
}

if (!function_exists('seo_analista_render_competitors')) {
    function seo_analista_render_competitors(array $summary, array $competitors) {
        echo '<section class="seo-analista-section">';
        echo '<div class="seo-analista-section-head"><div><h2>Comparativa de competidores</h2><p>Lista de vigilancia preparada para una fuente de posiciones externa. El Analista no raspa Google ni inventa rankings de terceros.</p></div></div>';

        if (!empty($summary['provider_available'])) {
            echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Dominio</th><th>Keywords</th><th>Top 3</th><th>Top 10</th><th>Top 20</th><th>Top 100</th></tr></thead><tbody>';
            foreach ((array) $summary['domains'] as $domain) {
                echo '<tr><td><strong>' . esc_html((string) $domain['domain']) . '</strong></td>';
                echo '<td>' . esc_html(number_format_i18n((int) $domain['keywords'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) $domain['top3'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) $domain['top10'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) $domain['top20'])) . '</td>';
                echo '<td>' . esc_html(number_format_i18n((int) $domain['top100'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<div class="notice notice-info inline"><p><strong>Comparación externa preparada, pero sin proveedor de rankings conectado.</strong> El informe propio ya funciona con Search Console y Trends. Para obtener posiciones de competidores como SEMrush hay que conectar una fuente SERP/API; el módulo expone el filtro <code>seo_analista_competitor_rankings</code> para hacerlo sin modificar esta pantalla.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;max-width:760px;">';
        echo '<input type="hidden" name="action" value="seo_analista_save_settings">';
        wp_nonce_field('seo_analista_save_settings', 'seo_analista_nonce');
        echo '<label for="seo-analista-competitors"><strong>Competidores de referencia</strong></label>';
        echo '<p class="description">Uno por línea. Se usarán cuando conectemos una fuente externa de posiciones.</p>';
        echo '<textarea id="seo-analista-competitors" name="competitors" rows="6" class="large-text code">' . esc_textarea(implode("\n", $competitors)) . '</textarea>';
        submit_button('Guardar competidores', 'secondary', 'submit', false);
        echo '</form></section>';
    }
}

if (!function_exists('seo_analista_render_executive_actions')) {
    function seo_analista_render_executive_actions(array $data) {
        $opportunities = (array) ($data['opportunities'] ?? array());
        $distribution = (array) ($data['distribution'] ?? array());
        $actions = array();

        if ($opportunities) {
            $top = $opportunities[0];
            $actions[] = array(
                'title' => 'Prioridad inmediata',
                'text' => 'Potenciar “' . (string) ($top['query_text'] ?? '') . '”: posición ' . number_format_i18n((float) ($top['position'] ?? 0), 1) . ', ' . number_format_i18n((float) ($top['impressions'] ?? 0), 0) . ' impresiones y score ' . (int) ($top['score'] ?? 0) . '/100.',
            );
        }
        if ((int) ($distribution['top50'] ?? 0) > (int) ($distribution['top10'] ?? 0)) {
            $actions[] = array(
                'title' => 'Zona de crecimiento',
                'text' => 'Hay más consultas entre posiciones 21–50 que en Top 10. Conviene reforzar páginas ya visibles antes de perseguir términos genéricos desde cero.',
            );
        }
        $page_types = (array) ($data['page_types'] ?? array());
        if ($page_types) {
            $first_type = key($page_types);
            $actions[] = array(
                'title' => 'Lectura de arquitectura',
                'text' => 'El tipo de URL con más impresiones es “' . $first_type . '”. El objetivo es comprobar que categorías y productos ganen peso progresivamente frente a contenidos aislados.',
            );
        }

        if (!$actions) return;
        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Lectura del Analista</h2><p>Conclusiones automáticas a partir de los datos del período.</p></div></div><div class="seo-analista-actions">';
        foreach (array_slice($actions, 0, 3) as $action) {
            echo '<div class="seo-analista-action"><strong>' . esc_html($action['title']) . '</strong><p>' . esc_html($action['text']) . '</p></div>';
        }
        echo '</div></section>';
    }
}

if (!function_exists('seo_analista_render_styles')) {
    function seo_analista_render_styles() {
        echo '<style>
        .seo-analista-wrap{max-width:1500px}.seo-analista-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin:10px 0 16px}.seo-analista-toolbar form{display:flex;gap:8px;align-items:center}.seo-analista-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:14px 0 20px}.seo-analista-card,.seo-analista-section{background:#fff;border:1px solid #dcdcde;border-radius:8px}.seo-analista-card{padding:15px}.seo-analista-label{font-size:12px;text-transform:uppercase;color:#646970;font-weight:700}.seo-analista-number{font-size:28px;font-weight:750;margin:5px 0}.seo-analista-delta{font-size:12px}.seo-analista-detail{font-size:12px;color:#646970;margin-top:5px}.seo-analista-section{padding:18px;margin:16px 0}.seo-analista-section-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.seo-analista-section h2{margin:0 0 5px}.seo-analista-section-head p{margin:0;color:#646970}.seo-analista-bars{display:grid;gap:10px;margin-top:16px}.seo-analista-bar-meta{display:flex;justify-content:space-between;gap:12px;margin-bottom:4px}.seo-analista-bar-track{height:9px;background:#f0f0f1;border-radius:999px;overflow:hidden}.seo-analista-bar-track span{display:block;height:100%;background:#3858e9;border-radius:999px}.seo-analista-intents{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:16px}.seo-analista-intent{border:1px solid #dcdcde;border-radius:7px;padding:13px;display:grid;gap:2px}.seo-analista-intent strong{font-size:23px}.seo-analista-intent span{font-weight:600}.seo-analista-intent small{color:#646970}.seo-analista-table-wrap{overflow:auto;margin-top:16px}.seo-analista-table{min-width:980px}.seo-analista-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:14px}.seo-analista-action{border:1px solid #dcdcde;border-radius:7px;padding:14px}.seo-analista-action strong{display:block;font-size:15px;margin-bottom:5px}.seo-analista-action span{display:block;font-weight:700;color:#3858e9}.seo-analista-action small{display:block;color:#646970;margin-top:4px}.seo-analista-action p{margin:7px 0 0}.seo-analista-note{background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 14px;margin:12px 0}@media(max-width:782px){.seo-analista-bar-meta{display:block}.seo-analista-toolbar form{width:100%}}
        </style>';
    }
}

if (!function_exists('seo_analista_render_report')) {
    function seo_analista_render_report() {
        if (!current_user_can('manage_options')) return;

        $days = isset($_GET['analista_days']) ? seo_analista_days(wp_unslash($_GET['analista_days'])) : 28;
        $data = seo_analista_get_data($days);

        echo '<div class="seo-analista-wrap">';
        seo_analista_render_styles();
        echo '<div class="seo-analista-toolbar"><div><h2 style="margin:0 0 4px;">Analista · Mercado orgánico</h2><p style="margin:0;color:#646970;">Análisis propio de visibilidad, demanda, oportunidades, catálogo y competencia.</p></div>';
        echo '<form method="get"><input type="hidden" name="page" value="seo-reports"><input type="hidden" name="tab" value="analista"><label for="analista-days"><strong>Período</strong></label><select id="analista-days" name="analista_days">';
        foreach (array(28, 60, 90) as $option) {
            echo '<option value="' . esc_attr($option) . '" ' . selected($days, $option, false) . '>' . esc_html($option . ' días') . '</option>';
        }
        echo '</select><button class="button">Actualizar</button></form></div>';

        if (isset($_GET['analista_notice']) && 'settings_saved' === sanitize_key(wp_unslash($_GET['analista_notice']))) {
            echo '<div class="notice notice-success is-dismissible"><p>Competidores de Analista actualizados.</p></div>';
        }

        if (empty($data['ready'])) {
            echo '<div class="notice notice-warning inline"><p><strong>Analista necesita datos de Google Intelligence.</strong> Conecta y sincroniza Search Console primero.</p></div>';
            if (function_exists('seo_google_admin_url')) {
                echo '<p><a class="button button-primary" href="' . esc_url(seo_google_admin_url('sync')) . '">Ir a Inteligencia de Google</a></p>';
            }
            echo '</div>';
            return;
        }

        $current = (array) $data['current'];
        $previous = (array) $data['previous'];
        $period = (array) $data['period'];
        $visibility = (float) ($data['visibility_index'] ?? 0);
        $previous_visibility = (float) ($data['previous_visibility_index'] ?? 0);

        echo '<div class="seo-analista-note"><strong>Período analizado:</strong> ' . esc_html($period['date_from'] . ' → ' . $period['date_to']) . '. Comparativa contra ' . esc_html($period['previous_date_from'] . ' → ' . $period['previous_date_to']) . '.</div>';

        echo '<div class="seo-analista-grid">';
        seo_analista_render_metric_card('Índice Analista', number_format_i18n($visibility, 1), seo_analista_metric_delta($visibility, $previous_visibility), 'Índice propio 0–100 según distribución de posiciones; no es la métrica de SEMrush.');
        seo_analista_render_metric_card('Consultas', number_format_i18n((int) ($current['queries'] ?? 0)), seo_analista_metric_delta($current['queries'] ?? 0, $previous['queries'] ?? 0));
        seo_analista_render_metric_card('Páginas visibles', number_format_i18n((int) ($current['pages'] ?? 0)), seo_analista_metric_delta($current['pages'] ?? 0, $previous['pages'] ?? 0));
        seo_analista_render_metric_card('Impresiones', number_format_i18n((float) ($current['impressions'] ?? 0), 0), seo_analista_metric_delta($current['impressions'] ?? 0, $previous['impressions'] ?? 0));
        seo_analista_render_metric_card('Clics', number_format_i18n((float) ($current['clicks'] ?? 0), 0), seo_analista_metric_delta($current['clicks'] ?? 0, $previous['clicks'] ?? 0));
        seo_analista_render_metric_card('CTR', number_format_i18n(((float) ($current['ctr'] ?? 0)) * 100, 2) . '%', seo_analista_metric_delta($current['ctr'] ?? 0, $previous['ctr'] ?? 0));
        seo_analista_render_metric_card('Posición media', number_format_i18n((float) ($current['position'] ?? 0), 1), seo_analista_metric_delta($current['position'] ?? 0, $previous['position'] ?? 0, true), 'Menos es mejor.');
        seo_analista_render_metric_card('Oportunidades', number_format_i18n(count((array) $data['opportunities'])), '', 'Shortlist automático de consultas accionables.');
        echo '</div>';

        seo_analista_render_executive_actions($data);
        seo_analista_render_distribution((array) $data['distribution'], (array) $data['previous_distribution']);
        seo_analista_render_intents((array) $data['intent_distribution']);
        seo_analista_render_opportunities((array) $data['opportunities']);
        seo_analista_render_page_types((array) $data['page_types']);

        $signals = seo_analista_market_signals(20);
        $guidance = seo_analista_catalog_guidance($days, 12);
        seo_analista_render_market($signals, $guidance);

        $competitors = (array) ($data['settings']['competitors'] ?? array());
        $competitor_summary = seo_analista_competitor_summary((array) $data['opportunities'], $competitors);
        seo_analista_render_competitors($competitor_summary, $competitors);

        echo '</div>';
    }
}
