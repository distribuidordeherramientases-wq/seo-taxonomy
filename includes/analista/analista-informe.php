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
        .seo-analista-wrap{max-width:1500px}.seo-analista-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin:10px 0 16px}.seo-analista-toolbar form{display:flex;gap:8px;align-items:center}.seo-analista-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:14px 0 20px}.seo-analista-card,.seo-analista-section{background:#fff;border:1px solid #dcdcde;border-radius:8px}.seo-analista-card{padding:15px}.seo-analista-label{font-size:12px;text-transform:uppercase;color:#646970;font-weight:700}.seo-analista-number{font-size:28px;font-weight:750;margin:5px 0}.seo-analista-delta{font-size:12px}.seo-analista-detail{font-size:12px;color:#646970;margin-top:5px}.seo-analista-section{padding:18px;margin:16px 0}.seo-analista-section-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.seo-analista-section h2{margin:0 0 5px}.seo-analista-section-head p{margin:0;color:#646970}.seo-analista-bars{display:grid;gap:10px;margin-top:16px}.seo-analista-bar-meta{display:flex;justify-content:space-between;gap:12px;margin-bottom:4px}.seo-analista-bar-track{height:9px;background:#f0f0f1;border-radius:999px;overflow:hidden}.seo-analista-bar-track span{display:block;height:100%;background:#3858e9;border-radius:999px}.seo-analista-intents{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:16px}.seo-analista-intent{border:1px solid #dcdcde;border-radius:7px;padding:13px;display:grid;gap:2px}.seo-analista-intent strong{font-size:23px}.seo-analista-intent span{font-weight:600}.seo-analista-intent small{color:#646970}.seo-analista-table-wrap{overflow:auto;margin-top:16px}.seo-analista-table{min-width:980px}.seo-analista-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-top:14px}.seo-analista-action{border:1px solid #dcdcde;border-radius:7px;padding:14px}.seo-analista-action strong{display:block;font-size:15px;margin-bottom:5px}.seo-analista-action span{display:block;font-weight:700;color:#3858e9}.seo-analista-action small{display:block;color:#646970;margin-top:4px}.seo-analista-action p{margin:7px 0 0}.seo-analista-note{background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 14px;margin:12px 0}.seo-analista-subnav{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px}.seo-analista-subnav a{padding:7px 10px;border:1px solid #dcdcde;background:#fff;border-radius:6px;text-decoration:none}.seo-analista-subnav a.is-active{background:#2271b1;color:#fff;border-color:#2271b1}.seo-analista-plan{display:grid;gap:10px;margin-top:14px}.seo-analista-plan-item{display:grid;grid-template-columns:54px 1fr;gap:12px;border:1px solid #dcdcde;border-left:4px solid #8c8f94;border-radius:7px;padding:12px}.seo-analista-plan-item.high{border-left-color:#d63638}.seo-analista-plan-item.medium{border-left-color:#dba617}.seo-analista-priority{font-size:22px;font-weight:800}.seo-analista-plan-item h3{margin:2px 0 5px;font-size:16px}.seo-analista-plan-item p{margin:0 0 4px}.seo-analista-plan-item small{color:#646970}.seo-analista-plan-action{font-size:12px;text-transform:uppercase;font-weight:800;color:#3858e9}.seo-analista-health{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin-top:14px}.seo-analista-health-item{border:1px solid #dcdcde;border-left:4px solid #dba617;padding:12px;border-radius:6px;display:grid;gap:4px}.seo-analista-health-item.is-ok{border-left-color:#00a32a}.seo-analista-health-item span{font-size:12px;color:#646970}@media(max-width:782px){.seo-analista-bar-meta{display:block}.seo-analista-toolbar form{width:100%}.seo-analista-plan-item{grid-template-columns:42px 1fr}}
        </style>';
    }
}

if (!function_exists('seo_analista_render_subnav')) {
    function seo_analista_render_subnav($active, $days) {
        $tabs = array(
            'resumen'     => 'Resumen',
            'hoja_ruta'   => 'Hoja de ruta',
            'demanda'     => 'Demanda',
            'catalogo'    => 'Catálogo y proveedores',
            'competencia' => 'Competencia',
            'fuentes'     => 'Fuentes y errores',
        );
        echo '<div class="seo-analista-subnav">';
        foreach ($tabs as $key=>$label) {
            $url = seo_analista_admin_url(array('analista_view'=>$key,'analista_days'=>$days));
            echo '<a class="'.($active===$key?'is-active':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }
        echo '</div>';
    }
}

if (!function_exists('seo_analista_render_health')) {
    function seo_analista_render_health(array $data, array $ga4, array $search, array $suppliers, array $semrush) {
        $items=array();
        $items[] = array('label'=>'Search Console','ok'=>!empty($data['ready']),'text'=>!empty($data['ready'])?'Datos disponibles':'Sin datos sincronizados');
        $items[] = array('label'=>'Google Analytics','ok'=>!empty($ga4['available']),'text'=>!empty($ga4['available'])?'Conectado':(!empty($ga4['error'])?$ga4['error']:'Sin conexión')); 
        $items[] = array('label'=>'Búsqueda interna','ok'=>!empty($search['available']),'text'=>!empty($search['available'])?number_format_i18n((int)$search['total']).' búsquedas registradas':'Sin registro disponible');
        $items[] = array('label'=>'Proveedores','ok'=>!empty($suppliers['available']) && empty($suppliers['issues']),'text'=>!empty($suppliers['available'])?(number_format_i18n((int)$suppliers['providers']).' proveedores · '.number_format_i18n(count((array)$suppliers['issues'])).' con avisos'):'Tabla no disponible');
        $items[] = array('label'=>'SEMrush','ok'=>!empty($semrush['rows']),'text'=>!empty($semrush['rows'])?number_format_i18n((int)$semrush['row_count']).' posiciones importadas':'Opcional · sin CSV importado');
        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Estado de las fuentes</h2><p>Solo aparecen avisos que afectan al análisis; el diagnóstico técnico completo sigue en Anomalías.</p></div></div><div class="seo-analista-health">';
        foreach($items as $item){echo '<div class="seo-analista-health-item '.($item['ok']?'is-ok':'is-warn').'"><strong>'.esc_html($item['label']).'</strong><span>'.esc_html($item['text']).'</span></div>';}
        echo '</div></section>';
    }
}

if (!function_exists('seo_analista_render_plan')) {
    function seo_analista_render_plan(array $plan, $limit = 12) {
        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Qué debemos hacer</h2><p>Lista priorizada. No son métricas: son decisiones generadas a partir de las señales disponibles.</p></div></div>';
        if(!$plan){echo '<p class="description">Todavía no hay señales suficientes para generar una hoja de ruta fiable.</p></section>';return;}
        echo '<div class="seo-analista-plan">';
        foreach(array_slice($plan,0,$limit) as $row){
            $priority=(int)($row['priority']??0); $tone=$priority>=75?'high':($priority>=60?'medium':'low');
            echo '<article class="seo-analista-plan-item '.$tone.'"><div class="seo-analista-priority">'.esc_html($priority).'</div><div><div class="seo-analista-plan-action">'.esc_html((string)($row['action_label']??$row['action']??'Acción')).'</div><h3>'.esc_html((string)($row['topic']??'')).'</h3><p>'.esc_html((string)($row['reason']??'')).'</p>';
            $meta=array_filter(array((string)($row['source']??''),(string)($row['detail']??''))); if($meta) echo '<small>'.esc_html(implode(' · ',$meta)).'</small>';
            echo '</div></article>';
        }
        echo '</div></section>';
    }
}

if (!function_exists('seo_analista_render_supplier_table')) {
    function seo_analista_render_supplier_table(array $snapshot) {
        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Proveedores y materia prima del catálogo</h2><p>Qué recibimos, cuánto se aprovecha y dónde hay problemas de sincronización o revisión.</p></div></div>';
        if(empty($snapshot['available'])){echo '<p class="description">No está disponible la tabla de productos de proveedores.</p></section>';return;}
        echo '<div class="seo-analista-grid">';
        seo_analista_render_metric_card('Productos recibidos',number_format_i18n((int)$snapshot['total']));
        seo_analista_render_metric_card('Proveedores',number_format_i18n((int)$snapshot['providers']));
        seo_analista_render_metric_card('Enlazados a WooCommerce',number_format_i18n((int)$snapshot['published_links']));
        seo_analista_render_metric_card('Proveedores con avisos',number_format_i18n(count((array)$snapshot['issues'])));
        echo '</div><div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Proveedor</th><th>Recibidos</th><th>En catálogo</th><th>Descartados</th><th>Pendientes</th><th>Con stock</th><th>Errores sync</th><th>Última importación</th></tr></thead><tbody>';
        foreach((array)$snapshot['rows'] as $r){echo '<tr><td><strong>'.esc_html((string)$r['proveedor']).'</strong></td><td>'.number_format_i18n((int)$r['total']).'</td><td>'.number_format_i18n((int)$r['linked']).'</td><td>'.number_format_i18n((int)$r['discarded']).'</td><td>'.number_format_i18n((int)$r['pending']).'</td><td>'.number_format_i18n((int)$r['with_stock']).'</td><td>'.number_format_i18n((int)$r['errors']).'</td><td>'.esc_html((string)($r['last_import']??'—')).'</td></tr>';}
        echo '</tbody></table></div></section>';
    }
}

if (!function_exists('seo_analista_render_internal_search')) {
    function seo_analista_render_internal_search(array $snapshot) {
        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Qué buscan dentro de la tienda</h2><p>Demanda de primera mano. Las búsquedas sin resultados son candidatas directas para catálogo, proveedor o contenido.</p></div></div>';
        if(empty($snapshot['available'])){echo '<p class="description">El registro de búsquedas internas no está disponible.</p></section>';return;}
        echo '<div class="seo-analista-grid">'; seo_analista_render_metric_card('Búsquedas',number_format_i18n((int)$snapshot['total'])); seo_analista_render_metric_card('Términos distintos',number_format_i18n((int)$snapshot['unique'])); seo_analista_render_metric_card('Sin resultados',number_format_i18n((int)$snapshot['zero_results'])); echo '</div>';
        echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Búsqueda</th><th>Veces</th><th>Resultados medios</th><th>Sin resultado</th><th>Última vez</th></tr></thead><tbody>';
        foreach(array_slice((array)$snapshot['top'],0,25) as $r){echo '<tr><td><strong>'.esc_html((string)$r['search_term']).'</strong></td><td>'.number_format_i18n((int)$r['searches']).'</td><td>'.number_format_i18n((float)$r['avg_results'],1).'</td><td>'.number_format_i18n((int)$r['zero_count']).'</td><td>'.esc_html((string)$r['last_search']).'</td></tr>';}
        echo '</tbody></table></div></section>';
    }
}

if (!function_exists('seo_analista_render_semrush')) {
    function seo_analista_render_semrush(array $snapshot, array $competitors) {
        $opps=seo_analista_semrush_opportunities(30);
        echo '<section class="seo-analista-section"><div class="seo-analista-section-head"><div><h2>Competencia · estilo SEMrush</h2><p>Importa un CSV de Posicionamiento orgánico o Keyword Gap. Analista se queda con las diferencias accionables y evita mostrar miles de filas sin utilidad.</p></div></div>';
        if(!empty($snapshot['rows'])) echo '<div class="seo-analista-note"><strong>Última importación:</strong> '.esc_html((string)$snapshot['imported_at']).' · '.number_format_i18n((int)$snapshot['row_count']).' posiciones.</div>';
        if($opps){echo '<div class="seo-analista-table-wrap"><table class="widefat striped"><thead><tr><th>Prioridad</th><th>Keyword</th><th>Decisión</th><th>Por qué</th><th>Datos</th></tr></thead><tbody>'; foreach($opps as $r){echo '<tr><td><strong>'.(int)$r['priority'].'</strong></td><td><strong>'.esc_html($r['topic']).'</strong></td><td>'.esc_html($r['action_label']).'</td><td>'.esc_html($r['reason']).'</td><td>'.esc_html($r['detail']).'</td></tr>';} echo '</tbody></table></div>';}
        else echo '<p class="description">Sin comparación importada todavía. Puedes seguir usando el Analista con Search Console, Trends, búsquedas internas y catálogo.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:16px"><input type="hidden" name="action" value="seo_analista_semrush_import">'; wp_nonce_field('seo_analista_semrush_import','seo_analista_semrush_nonce'); echo '<input type="file" name="semrush_csv" accept=".csv,text/csv"> <button class="button button-primary">Importar CSV SEMrush</button></form>';
        echo '<details style="margin-top:18px"><summary><strong>Competidores vigilados</strong></summary><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="max-width:760px;margin-top:12px"><input type="hidden" name="action" value="seo_analista_save_settings">'; wp_nonce_field('seo_analista_save_settings','seo_analista_nonce'); echo '<textarea name="competitors" rows="6" class="large-text code">'.esc_textarea(implode("\n",$competitors)).'</textarea>'; submit_button('Guardar competidores','secondary','submit',false); echo '</form></details></section>';
    }
}

if (!function_exists('seo_analista_render_report')) {
    function seo_analista_render_report() {
        if (!current_user_can('manage_options')) return;
        $days = isset($_GET['analista_days']) ? seo_analista_days(wp_unslash($_GET['analista_days'])) : 28;
        $view = isset($_GET['analista_view']) ? sanitize_key(wp_unslash($_GET['analista_view'])) : 'resumen';
        $allowed=array('resumen','hoja_ruta','demanda','catalogo','competencia','fuentes'); if(!in_array($view,$allowed,true))$view='resumen';
        $data=seo_analista_get_data($days); $ga4=seo_analista_ga4_snapshot($days); $search=seo_analista_internal_search_snapshot($days,40); $suppliers=seo_analista_supplier_snapshot(40); $semrush=seo_analista_semrush_snapshot(); $plan=seo_analista_decision_plan($days,30); $plan_summary=seo_analista_plan_summary($plan);
        echo '<div class="seo-analista-wrap">'; seo_analista_render_styles();
        echo '<div class="seo-analista-toolbar"><div><h2 style="margin:0 0 4px">Analista</h2><p style="margin:0;color:#646970">Convierte Google, demanda interna, catálogo, proveedores y competencia en decisiones de trabajo.</p></div><form method="get"><input type="hidden" name="page" value="seo-reports"><input type="hidden" name="tab" value="analista"><input type="hidden" name="analista_view" value="'.esc_attr($view).'"><label><strong>Período</strong></label><select name="analista_days">'; foreach(array(28,60,90) as $d)echo '<option value="'.$d.'" '.selected($days,$d,false).'>'.$d.' días</option>'; echo '</select><button class="button">Actualizar</button></form></div>';
        seo_analista_render_subnav($view,$days);
        $notice=isset($_GET['analista_notice'])?sanitize_key(wp_unslash($_GET['analista_notice'])):''; if($notice==='semrush_ok')echo '<div class="notice notice-success inline"><p>CSV competitivo importado correctamente.</p></div>'; if($notice==='semrush_error'){ $msg=get_transient('seo_analista_semrush_error_'.get_current_user_id()); echo '<div class="notice notice-error inline"><p>'.esc_html($msg?:'No se pudo importar el CSV.').'</p></div>'; }
        if(empty($data['ready'])) echo '<div class="notice notice-warning inline"><p><strong>Sin Search Console activo.</strong> Analista seguirá mostrando búsquedas internas, proveedores y competencia si existen. En un staging clonado también puede reutilizar datos locales de Search Console ya almacenados.</p></div>';

        if($view==='resumen'){
            $c=(array)($data['current']??array()); $p=(array)($data['previous']??array());
            echo '<div class="seo-analista-grid">';
            seo_analista_render_metric_card('Impresiones Google',number_format_i18n((float)($c['impressions']??0),0),seo_analista_metric_delta($c['impressions']??0,$p['impressions']??0),'Demanda que ya nos está mostrando Google.');
            seo_analista_render_metric_card('Páginas visibles',number_format_i18n((int)($c['pages']??0)),seo_analista_metric_delta($c['pages']??0,$p['pages']??0),'URLs con presencia real en Search Console.');
            seo_analista_render_metric_card('Búsquedas internas',number_format_i18n((int)$search['total']),'',$search['zero_results'].' sin resultado.');
            seo_analista_render_metric_card('Proveedores',number_format_i18n((int)$suppliers['providers']),'',count((array)$suppliers['issues']).' con avisos.');
            seo_analista_render_metric_card('Acciones prioritarias',number_format_i18n(count($plan)),'',$plan_summary['catalogo'].' catálogo · '.$plan_summary['contenido'].' contenido · '.$plan_summary['seo'].' SEO.');
            seo_analista_render_metric_card('Ventas GA4',!empty($ga4['available'])?number_format_i18n((int)$ga4['purchases']):'—','',!empty($ga4['available'])?number_format_i18n((float)$ga4['revenue'],2).' de ingresos medidos':'Analytics no disponible');
            echo '</div>';
            seo_analista_render_plan($plan,8);
            echo '<div class="seo-analista-actions"><div class="seo-analista-action"><strong>Catálogo</strong><span>'.number_format_i18n((int)$plan_summary['catalogo']).' acciones</span><p>Qué productos/familias ampliar o investigar.</p><a href="'.esc_url(seo_analista_admin_url(array('analista_view'=>'catalogo','analista_days'=>$days))).'">Profundizar</a></div><div class="seo-analista-action"><strong>Contenido y SEO</strong><span>'.number_format_i18n((int)($plan_summary['contenido']+$plan_summary['seo'])).' acciones</span><p>Qué páginas, landings o posts reforzar/crear.</p><a href="'.esc_url(seo_analista_admin_url(array('analista_view'=>'hoja_ruta','analista_days'=>$days))).'">Profundizar</a></div><div class="seo-analista-action"><strong>Competencia</strong><span>'.number_format_i18n((int)$plan_summary['competencia']).' oportunidades</span><p>Brechas importadas de SEMrush.</p><a href="'.esc_url(seo_analista_admin_url(array('analista_view'=>'competencia','analista_days'=>$days))).'">Profundizar</a></div></div>';
        } elseif($view==='hoja_ruta'){
            seo_analista_render_plan($plan,30);
            if(!empty($data['ready'])) seo_analista_render_opportunities((array)$data['opportunities']);
        } elseif($view==='demanda'){
            seo_analista_render_internal_search($search); $signals=seo_analista_market_signals(30); $guidance=seo_analista_catalog_guidance($days,20); seo_analista_render_market($signals,$guidance); if(!empty($data['ready'])){seo_analista_render_distribution((array)$data['distribution'],(array)$data['previous_distribution']);seo_analista_render_intents((array)$data['intent_distribution']);}
        } elseif($view==='catalogo'){
            seo_analista_render_supplier_table($suppliers); $catalog_plan=array_values(array_filter($plan,static function($r){return preg_match('/PRODUCT|CATALOG|CATEGORIA|PROVEEDOR/',(string)($r['action']??''));})); seo_analista_render_plan($catalog_plan,20);
        } elseif($view==='competencia'){
            $competitors=(array)($data['settings']['competitors']??seo_analista_get_settings()['competitors']); seo_analista_render_semrush($semrush,$competitors);
        } else {
            seo_analista_render_health($data,$ga4,$search,$suppliers,$semrush); if(!empty($data['ready'])) seo_analista_render_page_types((array)$data['page_types']);
        }
        echo '</div>';
    }
}
