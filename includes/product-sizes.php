<?php
/**
 * Analisis de tamanos y pesos del catalogo de WooCommerce.
 *
 * Anade una vista de administracion para conocer la distribucion de paquetes
 * por peso y tamano, con limites configurables y graficos dinamicos.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_product_sizes_default_settings')) {
    /**
     * Convierte referencias razonables (kg/cm) a las unidades configuradas en WooCommerce.
     *
     * @return array<string,float>
     */
    function seo_product_sizes_default_settings() {
        $weight_unit = get_option('woocommerce_weight_unit', 'kg');
        $dimension_unit = get_option('woocommerce_dimension_unit', 'cm');

        $kg_factor = 1.0;
        switch ($weight_unit) {
            case 'g':
                $kg_factor = 1000.0;
                break;
            case 'lbs':
                $kg_factor = 2.2046226218;
                break;
            case 'oz':
                $kg_factor = 35.27396195;
                break;
        }

        $cm_factor = 1.0;
        switch ($dimension_unit) {
            case 'm':
                $cm_factor = 0.01;
                break;
            case 'mm':
                $cm_factor = 10.0;
                break;
            case 'in':
                $cm_factor = 0.3937007874;
                break;
            case 'yd':
                $cm_factor = 0.010936133;
                break;
        }

        return [
            'weight_light_max'  => round(2.0 * $kg_factor, 4),
            'weight_medium_max' => round(10.0 * $kg_factor, 4),
            'weight_heavy_from' => round(10.0 * $kg_factor, 4),
            'size_small_max'     => round(30.0 * $cm_factor, 4),
            'size_medium_max'    => round(80.0 * $cm_factor, 4),
            'size_large_from'    => round(80.0 * $cm_factor, 4),
        ];
    }
}

if (!function_exists('seo_product_sizes_get_settings')) {
    /**
     * @return array<string,float>
     */
    function seo_product_sizes_get_settings() {
        $defaults = seo_product_sizes_default_settings();
        $saved = get_option('seo_product_sizes_settings', []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $settings = wp_parse_args($saved, $defaults);
        foreach ($defaults as $key => $default) {
            $settings[$key] = isset($settings[$key]) ? max(0.0, (float) $settings[$key]) : $default;
        }

        // Los limites de Mediano/Pesado y Mediano/Grande son una misma frontera.
        $settings['weight_heavy_from'] = $settings['weight_medium_max'];
        $settings['size_large_from'] = $settings['size_medium_max'];

        return $settings;
    }
}

if (!function_exists('seo_product_sizes_parse_decimal')) {
    /**
     * @param mixed $value
     * @return float
     */
    function seo_product_sizes_parse_decimal($value) {
        if (function_exists('wc_format_decimal')) {
            return (float) wc_format_decimal($value);
        }

        $value = is_scalar($value) ? (string) $value : '0';
        $value = str_replace(',', '.', $value);
        return (float) preg_replace('/[^0-9.\-]/', '', $value);
    }
}

if (!function_exists('seo_product_sizes_collect_products')) {
    /**
     * Devuelve productos fisicos publicados y variaciones publicadas sin duplicar
     * el padre de productos variables. Las variaciones heredan peso/dimensiones
     * del padre cuando el valor propio esta vacio, igual que WooCommerce.
     *
     * @return array<int,array<string,mixed>>
     */
    function seo_product_sizes_collect_products() {
        global $wpdb;

        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $term_relationships = $wpdb->term_relationships;
        $term_taxonomy = $wpdb->term_taxonomy;
        $terms = $wpdb->terms;

        $sql = "
            SELECT
                p.ID,
                p.post_parent,
                p.post_title,
                p.post_type,
                pt.product_type,
                pm.sku,
                CASE
                    WHEN p.post_type = 'product_variation'
                        THEN COALESCE(NULLIF(pm.weight_value, ''), NULLIF(ppm.weight_value, ''))
                    ELSE pm.weight_value
                END AS weight_value,
                CASE
                    WHEN p.post_type = 'product_variation'
                        THEN COALESCE(NULLIF(pm.length_value, ''), NULLIF(ppm.length_value, ''))
                    ELSE pm.length_value
                END AS length_value,
                CASE
                    WHEN p.post_type = 'product_variation'
                        THEN COALESCE(NULLIF(pm.width_value, ''), NULLIF(ppm.width_value, ''))
                    ELSE pm.width_value
                END AS width_value,
                CASE
                    WHEN p.post_type = 'product_variation'
                        THEN COALESCE(NULLIF(pm.height_value, ''), NULLIF(ppm.height_value, ''))
                    ELSE pm.height_value
                END AS height_value,
                CASE
                    WHEN p.post_type = 'product_variation'
                        THEN COALESCE(NULLIF(pm.virtual_value, ''), NULLIF(ppm.virtual_value, ''), 'no')
                    ELSE COALESCE(NULLIF(pm.virtual_value, ''), 'no')
                END AS virtual_value
            FROM {$posts} p
            LEFT JOIN (
                SELECT
                    post_id,
                    MAX(CASE WHEN meta_key = '_sku' THEN meta_value END) AS sku,
                    MAX(CASE WHEN meta_key = '_weight' THEN meta_value END) AS weight_value,
                    MAX(CASE WHEN meta_key = '_length' THEN meta_value END) AS length_value,
                    MAX(CASE WHEN meta_key = '_width' THEN meta_value END) AS width_value,
                    MAX(CASE WHEN meta_key = '_height' THEN meta_value END) AS height_value,
                    MAX(CASE WHEN meta_key = '_virtual' THEN meta_value END) AS virtual_value
                FROM {$postmeta}
                WHERE meta_key IN ('_sku', '_weight', '_length', '_width', '_height', '_virtual')
                GROUP BY post_id
            ) pm ON pm.post_id = p.ID
            LEFT JOIN (
                SELECT
                    post_id,
                    MAX(CASE WHEN meta_key = '_weight' THEN meta_value END) AS weight_value,
                    MAX(CASE WHEN meta_key = '_length' THEN meta_value END) AS length_value,
                    MAX(CASE WHEN meta_key = '_width' THEN meta_value END) AS width_value,
                    MAX(CASE WHEN meta_key = '_height' THEN meta_value END) AS height_value,
                    MAX(CASE WHEN meta_key = '_virtual' THEN meta_value END) AS virtual_value
                FROM {$postmeta}
                WHERE meta_key IN ('_weight', '_length', '_width', '_height', '_virtual')
                GROUP BY post_id
            ) ppm ON ppm.post_id = p.post_parent
            LEFT JOIN (
                SELECT tr.object_id, MAX(t.slug) AS product_type
                FROM {$term_relationships} tr
                INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                INNER JOIN {$terms} t ON t.term_id = tt.term_id
                WHERE tt.taxonomy = 'product_type'
                GROUP BY tr.object_id
            ) pt ON pt.object_id = p.ID
            WHERE p.post_type IN ('product', 'product_variation')
              AND p.post_status = 'publish'
              AND (
                    p.post_type = 'product_variation'
                    OR COALESCE(pt.product_type, 'simple') NOT IN ('variable', 'grouped', 'external')
                  )
            ORDER BY p.ID DESC
        ";

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $products = [];
        foreach ($rows as $row) {
            if (isset($row['virtual_value']) && 'yes' === $row['virtual_value']) {
                continue;
            }

            $weight = seo_product_sizes_parse_decimal($row['weight_value']);
            $length = seo_product_sizes_parse_decimal($row['length_value']);
            $width = seo_product_sizes_parse_decimal($row['width_value']);
            $height = seo_product_sizes_parse_decimal($row['height_value']);

            $has_weight = $weight > 0;
            $has_dimensions = $length > 0 && $width > 0 && $height > 0;
            $max_side = $has_dimensions ? max($length, $width, $height) : 0.0;
            $volume = $has_dimensions ? ($length * $width * $height) : 0.0;

            $products[] = [
                'id'         => (int) $row['ID'],
                'name'       => wp_strip_all_tags((string) $row['post_title']),
                'sku'        => isset($row['sku']) ? wp_strip_all_tags((string) $row['sku']) : '',
                'kind'       => ('product_variation' === $row['post_type']) ? 'Variacion' : 'Producto',
                'weight'     => $has_weight ? round($weight, 6) : null,
                'length'     => $length > 0 ? round($length, 6) : null,
                'width'      => $width > 0 ? round($width, 6) : null,
                'height'     => $height > 0 ? round($height, 6) : null,
                'maxSide'    => $has_dimensions ? round($max_side, 6) : null,
                'volume'     => $has_dimensions ? round($volume, 6) : null,
            ];
        }

        return $products;
    }
}

if (!function_exists('seo_product_sizes_format_input')) {
    /**
     * @param float $value
     * @return string
     */
    function seo_product_sizes_format_input($value) {
        $formatted = number_format((float) $value, 4, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }
}

if (!function_exists('seo_product_sizes_page')) {
    function seo_product_sizes_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $notice = '';
        $notice_type = 'success';
        $defaults = seo_product_sizes_default_settings();

        if ('POST' === strtoupper($_SERVER['REQUEST_METHOD']) && isset($_POST['seo_product_sizes_action'])) {
            check_admin_referer('seo_product_sizes_settings', 'seo_product_sizes_nonce');
            $action = sanitize_key(wp_unslash($_POST['seo_product_sizes_action']));

            if ('reset' === $action) {
                update_option('seo_product_sizes_settings', $defaults, false);
                $notice = 'Limites restablecidos a los valores iniciales.';
            } elseif ('save' === $action) {
                $weight_light = isset($_POST['weight_light_max']) ? seo_product_sizes_parse_decimal(wp_unslash($_POST['weight_light_max'])) : 0;
                $weight_medium = isset($_POST['weight_medium_max']) ? seo_product_sizes_parse_decimal(wp_unslash($_POST['weight_medium_max'])) : 0;
                $size_small = isset($_POST['size_small_max']) ? seo_product_sizes_parse_decimal(wp_unslash($_POST['size_small_max'])) : 0;
                $size_medium = isset($_POST['size_medium_max']) ? seo_product_sizes_parse_decimal(wp_unslash($_POST['size_medium_max'])) : 0;

                if ($weight_light <= 0 || $weight_medium <= $weight_light || $size_small <= 0 || $size_medium <= $size_small) {
                    $notice = 'No se han guardado los limites. El maximo de Mediano debe ser mayor que el de Ligero y el maximo de Mediano debe ser mayor que el de Pequeno.';
                    $notice_type = 'error';
                } else {
                    $settings_to_save = [
                        'weight_light_max'  => $weight_light,
                        'weight_medium_max' => $weight_medium,
                        'weight_heavy_from' => $weight_medium,
                        'size_small_max'     => $size_small,
                        'size_medium_max'    => $size_medium,
                        'size_large_from'    => $size_medium,
                    ];
                    update_option('seo_product_sizes_settings', $settings_to_save, false);
                    $notice = 'Limites guardados. Los graficos usan estos valores como configuracion predeterminada.';
                }
            }
        }

        $settings = seo_product_sizes_get_settings();
        $products = seo_product_sizes_collect_products();
        $weight_unit = get_option('woocommerce_weight_unit', 'kg');
        $dimension_unit = get_option('woocommerce_dimension_unit', 'cm');

        $products_json = wp_json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $settings_json = wp_json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $edit_base_json = wp_json_encode(admin_url('post.php?action=edit&post='), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>
        <style>
            .seo-sizes-shell{max-width:1500px}
            .seo-sizes-lead{max-width:1000px;color:#50575e;font-size:14px;margin:0 0 18px}
            .seo-sizes-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:18px 0}
            .seo-sizes-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
            .seo-sizes-card h2,.seo-sizes-card h3{margin-top:0}
            .seo-sizes-controls{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
            .seo-sizes-field label{display:block;font-weight:600;margin-bottom:5px}
            .seo-sizes-field input{width:100%}
            .seo-sizes-field small{display:block;color:#646970;margin-top:4px;line-height:1.35}
            .seo-sizes-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:18px 0}
            .seo-sizes-kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px}
            .seo-sizes-kpi .seo-kpi-label{color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
            .seo-sizes-kpi .seo-kpi-value{font-size:25px;font-weight:700;line-height:1.2;margin-top:4px}
            .seo-sizes-chart-row{display:grid;grid-template-columns:110px 1fr 105px;align-items:center;gap:10px;margin:14px 0}
            .seo-sizes-chart-label{font-weight:600}
            .seo-sizes-track{height:24px;background:#f0f0f1;border-radius:999px;overflow:hidden;position:relative}
            .seo-sizes-fill{height:100%;width:0;transition:width .2s ease;border-radius:999px;background:linear-gradient(90deg,#2271b1,#72aee6)}
            .seo-sizes-chart-value{text-align:right;font-variant-numeric:tabular-nums}
            .seo-sizes-missing{margin-top:12px;color:#646970}
            .seo-sizes-profile{padding:14px 16px;border-left:4px solid #2271b1;background:#f6f7f7;margin:16px 0}
            .seo-sizes-matrix{width:100%;border-collapse:collapse}
            .seo-sizes-matrix th,.seo-sizes-matrix td{border:1px solid #dcdcde;padding:9px;text-align:center}
            .seo-sizes-matrix th{background:#f6f7f7}
            .seo-sizes-table-tools{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px}
            .seo-sizes-table-tools input[type=search]{min-width:280px}
            .seo-sizes-table-wrap{overflow:auto;max-height:650px;border:1px solid #dcdcde}
            .seo-sizes-table{width:100%;border-collapse:collapse;background:#fff;min-width:1050px}
            .seo-sizes-table th{position:sticky;top:0;background:#f6f7f7;z-index:1}
            .seo-sizes-table th,.seo-sizes-table td{border-bottom:1px solid #dcdcde;padding:9px 10px;text-align:left;vertical-align:top}
            .seo-sizes-table td.num,.seo-sizes-table th.num{text-align:right;font-variant-numeric:tabular-nums}
            .seo-sizes-tag{display:inline-block;padding:3px 8px;border-radius:999px;background:#f0f0f1;font-size:12px;font-weight:600}
            .seo-sizes-pagination{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-top:12px}
            .seo-sizes-muted{color:#646970}
            .seo-sizes-boundary-warning{display:none;margin:10px 0 0;color:#b32d2e;font-weight:600}
            @media (max-width:1050px){.seo-sizes-grid{grid-template-columns:1fr}.seo-sizes-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
            @media (max-width:700px){.seo-sizes-controls{grid-template-columns:1fr}.seo-sizes-kpis{grid-template-columns:1fr}.seo-sizes-chart-row{grid-template-columns:90px 1fr}.seo-sizes-chart-value{grid-column:2;text-align:left}.seo-sizes-table-tools>*{width:100%}.seo-sizes-table-tools input[type=search]{min-width:0}}
        </style>

        <div class="seo-sizes-shell">
            <p class="seo-sizes-lead">
                Analiza todos los productos fisicos publicados y sus variaciones vendibles. El peso usa el valor de WooCommerce y el tamano se clasifica por el <strong>lado mas largo del paquete</strong>; para clasificar tamano deben existir largo, ancho y alto. Los productos variables padre no se duplican y las variaciones heredan las medidas del padre cuando corresponde.
            </p>

            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <form method="post" id="seo-product-sizes-settings">
                <?php wp_nonce_field('seo_product_sizes_settings', 'seo_product_sizes_nonce'); ?>
                <input type="hidden" name="seo_product_sizes_action" id="seo-product-sizes-action" value="save">
                <div class="seo-sizes-grid">
                    <section class="seo-sizes-card">
                        <h2>Variables de peso</h2>
                        <p class="seo-sizes-muted">Unidad actual de WooCommerce: <strong><?php echo esc_html($weight_unit); ?></strong>.</p>
                        <div class="seo-sizes-controls">
                            <div class="seo-sizes-field">
                                <label for="seo-weight-light">Ligero hasta</label>
                                <input id="seo-weight-light" name="weight_light_max" type="number" min="0.0001" step="any" inputmode="decimal" value="<?php echo esc_attr(seo_product_sizes_format_input($settings['weight_light_max'])); ?>">
                                <small>Incluye valores menores o iguales.</small>
                            </div>
                            <div class="seo-sizes-field">
                                <label for="seo-weight-medium">Mediano hasta</label>
                                <input id="seo-weight-medium" name="weight_medium_max" type="number" min="0.0001" step="any" inputmode="decimal" value="<?php echo esc_attr(seo_product_sizes_format_input($settings['weight_medium_max'])); ?>">
                                <small>Debe ser mayor que Ligero.</small>
                            </div>
                            <div class="seo-sizes-field">
                                <label for="seo-weight-heavy">Pesado desde</label>
                                <input id="seo-weight-heavy" name="weight_heavy_from" type="number" min="0.0001" step="any" inputmode="decimal" value="<?php echo esc_attr(seo_product_sizes_format_input($settings['weight_heavy_from'])); ?>">
                                <small>Se sincroniza con el limite superior de Mediano para no dejar huecos.</small>
                            </div>
                        </div>
                        <div id="seo-weight-warning" class="seo-sizes-boundary-warning">El limite Mediano debe ser mayor que el limite Ligero.</div>
                    </section>

                    <section class="seo-sizes-card">
                        <h2>Variables de tamano</h2>
                        <p class="seo-sizes-muted">Se usa el lado mas largo. Unidad actual de WooCommerce: <strong><?php echo esc_html($dimension_unit); ?></strong>.</p>
                        <div class="seo-sizes-controls">
                            <div class="seo-sizes-field">
                                <label for="seo-size-small">Pequeno hasta</label>
                                <input id="seo-size-small" name="size_small_max" type="number" min="0.0001" step="any" inputmode="decimal" value="<?php echo esc_attr(seo_product_sizes_format_input($settings['size_small_max'])); ?>">
                                <small>Incluye valores menores o iguales.</small>
                            </div>
                            <div class="seo-sizes-field">
                                <label for="seo-size-medium">Mediano hasta</label>
                                <input id="seo-size-medium" name="size_medium_max" type="number" min="0.0001" step="any" inputmode="decimal" value="<?php echo esc_attr(seo_product_sizes_format_input($settings['size_medium_max'])); ?>">
                                <small>Debe ser mayor que Pequeno.</small>
                            </div>
                            <div class="seo-sizes-field">
                                <label for="seo-size-large">Grande desde</label>
                                <input id="seo-size-large" name="size_large_from" type="number" min="0.0001" step="any" inputmode="decimal" value="<?php echo esc_attr(seo_product_sizes_format_input($settings['size_large_from'])); ?>">
                                <small>Se sincroniza con el limite superior de Mediano para no dejar huecos.</small>
                            </div>
                        </div>
                        <div id="seo-size-warning" class="seo-sizes-boundary-warning">El limite Mediano debe ser mayor que el limite Pequeno.</div>
                    </section>
                </div>
                <p>
                    <button type="submit" class="button button-primary">Guardar limites</button>
                    <button type="submit" class="button" id="seo-sizes-reset">Restablecer valores</button>
                    <span class="seo-sizes-muted" style="margin-left:8px">Los graficos cambian al instante al modificar cualquier limite; guardar solo los deja como valores predeterminados.</span>
                </p>
            </form>

            <div class="seo-sizes-kpis">
                <div class="seo-sizes-kpi"><div class="seo-kpi-label">Paquetes evaluados</div><div class="seo-kpi-value" id="seo-kpi-total">0</div></div>
                <div class="seo-sizes-kpi"><div class="seo-kpi-label">Con peso</div><div class="seo-kpi-value" id="seo-kpi-weight">0</div></div>
                <div class="seo-sizes-kpi"><div class="seo-kpi-label">Sin peso</div><div class="seo-kpi-value" id="seo-kpi-no-weight">0</div></div>
                <div class="seo-sizes-kpi"><div class="seo-kpi-label">Con dimensiones</div><div class="seo-kpi-value" id="seo-kpi-size">0</div></div>
                <div class="seo-sizes-kpi"><div class="seo-kpi-label">Sin dimensiones completas</div><div class="seo-kpi-value" id="seo-kpi-no-size">0</div></div>
            </div>

            <div class="seo-sizes-grid">
                <section class="seo-sizes-card">
                    <h2>Grafico de peso</h2>
                    <div class="seo-sizes-chart-row"><div class="seo-sizes-chart-label">Ligero</div><div class="seo-sizes-track"><div class="seo-sizes-fill" id="seo-bar-weight-light"></div></div><div class="seo-sizes-chart-value" id="seo-val-weight-light">0</div></div>
                    <div class="seo-sizes-chart-row"><div class="seo-sizes-chart-label">Mediano</div><div class="seo-sizes-track"><div class="seo-sizes-fill" id="seo-bar-weight-medium"></div></div><div class="seo-sizes-chart-value" id="seo-val-weight-medium">0</div></div>
                    <div class="seo-sizes-chart-row"><div class="seo-sizes-chart-label">Pesado</div><div class="seo-sizes-track"><div class="seo-sizes-fill" id="seo-bar-weight-heavy"></div></div><div class="seo-sizes-chart-value" id="seo-val-weight-heavy">0</div></div>
                    <div class="seo-sizes-missing" id="seo-weight-missing-note"></div>
                </section>

                <section class="seo-sizes-card">
                    <h2>Grafico de tamano</h2>
                    <div class="seo-sizes-chart-row"><div class="seo-sizes-chart-label">Pequeno</div><div class="seo-sizes-track"><div class="seo-sizes-fill" id="seo-bar-size-small"></div></div><div class="seo-sizes-chart-value" id="seo-val-size-small">0</div></div>
                    <div class="seo-sizes-chart-row"><div class="seo-sizes-chart-label">Mediano</div><div class="seo-sizes-track"><div class="seo-sizes-fill" id="seo-bar-size-medium"></div></div><div class="seo-sizes-chart-value" id="seo-val-size-medium">0</div></div>
                    <div class="seo-sizes-chart-row"><div class="seo-sizes-chart-label">Grande</div><div class="seo-sizes-track"><div class="seo-sizes-fill" id="seo-bar-size-large"></div></div><div class="seo-sizes-chart-value" id="seo-val-size-large">0</div></div>
                    <div class="seo-sizes-missing" id="seo-size-missing-note"></div>
                </section>
            </div>

            <div class="seo-sizes-profile" id="seo-sizes-profile"></div>

            <section class="seo-sizes-card" style="margin-bottom:18px">
                <h2>Matriz peso x tamano</h2>
                <p class="seo-sizes-muted">Cruza solo los paquetes que tienen tanto peso como las tres dimensiones. Sirve para ver que combinaciones dominan realmente el catalogo.</p>
                <div style="overflow:auto">
                    <table class="seo-sizes-matrix">
                        <thead><tr><th>Peso / Tamano</th><th>Pequeno</th><th>Mediano</th><th>Grande</th><th>Total peso</th></tr></thead>
                        <tbody>
                            <tr><th>Ligero</th><td id="seo-matrix-light-small">0</td><td id="seo-matrix-light-medium">0</td><td id="seo-matrix-light-large">0</td><td id="seo-matrix-light-total">0</td></tr>
                            <tr><th>Mediano</th><td id="seo-matrix-medium-small">0</td><td id="seo-matrix-medium-medium">0</td><td id="seo-matrix-medium-large">0</td><td id="seo-matrix-medium-total">0</td></tr>
                            <tr><th>Pesado</th><td id="seo-matrix-heavy-small">0</td><td id="seo-matrix-heavy-medium">0</td><td id="seo-matrix-heavy-large">0</td><td id="seo-matrix-heavy-total">0</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="seo-sizes-card">
                <h2>Productos analizados</h2>
                <div class="seo-sizes-table-tools">
                    <input type="search" id="seo-sizes-search" placeholder="Buscar por producto, SKU o ID">
                    <select id="seo-sizes-filter-weight">
                        <option value="">Todos los pesos</option>
                        <option value="light">Ligero</option><option value="medium">Mediano</option><option value="heavy">Pesado</option><option value="missing">Sin peso</option>
                    </select>
                    <select id="seo-sizes-filter-size">
                        <option value="">Todos los tamanos</option>
                        <option value="small">Pequeno</option><option value="medium">Mediano</option><option value="large">Grande</option><option value="missing">Sin dimensiones</option>
                    </select>
                    <select id="seo-sizes-page-size"><option value="25">25 filas</option><option value="50" selected>50 filas</option><option value="100">100 filas</option></select>
                    <strong id="seo-sizes-filter-count"></strong>
                </div>
                <div class="seo-sizes-table-wrap">
                    <table class="seo-sizes-table">
                        <thead><tr><th>ID</th><th>Producto</th><th>SKU</th><th>Tipo</th><th class="num">Peso</th><th>Grupo peso</th><th class="num">L x A x H</th><th class="num">Lado max.</th><th>Grupo tamano</th><th></th></tr></thead>
                        <tbody id="seo-sizes-table-body"></tbody>
                    </table>
                </div>
                <div class="seo-sizes-pagination"><button type="button" class="button" id="seo-sizes-prev">Anterior</button><span id="seo-sizes-page-info"></span><button type="button" class="button" id="seo-sizes-next">Siguiente</button></div>
            </section>
        </div>

        <script>
        (function(){
            'use strict';
            const products = <?php echo $products_json ?: '[]'; ?>;
            const initialSettings = <?php echo $settings_json ?: '{}'; ?>;
            const editBase = <?php echo $edit_base_json ?: '""'; ?>;
            const weightUnit = <?php echo wp_json_encode($weight_unit); ?>;
            const dimensionUnit = <?php echo wp_json_encode($dimension_unit); ?>;
            const numberFormat = new Intl.NumberFormat(undefined, {maximumFractionDigits: 2});
            const integerFormat = new Intl.NumberFormat();
            const state = { page: 1, rows: [], counts: null };

            const $ = (id) => document.getElementById(id);
            const fields = {
                weightLight: $('seo-weight-light'),
                weightMedium: $('seo-weight-medium'),
                weightHeavy: $('seo-weight-heavy'),
                sizeSmall: $('seo-size-small'),
                sizeMedium: $('seo-size-medium'),
                sizeLarge: $('seo-size-large')
            };

            const labels = {
                light: 'Ligero', heavy: 'Pesado', small: 'Pequeno', large: 'Grande', medium: 'Mediano', missing: 'Sin dato'
            };

            function value(el, fallback) {
                const n = parseFloat(el.value);
                return Number.isFinite(n) && n >= 0 ? n : fallback;
            }

            function currentThresholds() {
                return {
                    weightLight: value(fields.weightLight, initialSettings.weight_light_max || 0),
                    weightMedium: value(fields.weightMedium, initialSettings.weight_medium_max || 0),
                    sizeSmall: value(fields.sizeSmall, initialSettings.size_small_max || 0),
                    sizeMedium: value(fields.sizeMedium, initialSettings.size_medium_max || 0)
                };
            }

            function classifyWeight(product, t) {
                if (product.weight === null || !Number.isFinite(Number(product.weight)) || Number(product.weight) <= 0) return 'missing';
                const v = Number(product.weight);
                if (v <= t.weightLight) return 'light';
                if (v <= t.weightMedium) return 'medium';
                return 'heavy';
            }

            function classifySize(product, t) {
                if (product.maxSide === null || !Number.isFinite(Number(product.maxSide)) || Number(product.maxSide) <= 0) return 'missing';
                const v = Number(product.maxSide);
                if (v <= t.sizeSmall) return 'small';
                if (v <= t.sizeMedium) return 'medium';
                return 'large';
            }

            function pct(part, total) {
                return total > 0 ? (part * 100 / total) : 0;
            }

            function updateBar(prefix, group, count, total) {
                const percentage = pct(count, total);
                $(prefix + group).style.width = Math.max(0, Math.min(100, percentage)) + '%';
                $('seo-val-' + prefix.replace('seo-bar-', '') + group).textContent = integerFormat.format(count) + ' (' + numberFormat.format(percentage) + '%)';
            }

            function dominant(counts, keys) {
                let best = keys[0];
                keys.forEach((key) => { if ((counts[key] || 0) > (counts[best] || 0)) best = key; });
                return best;
            }

            function compute() {
                const t = currentThresholds();
                const weightValid = t.weightMedium > t.weightLight;
                const sizeValid = t.sizeMedium > t.sizeSmall;
                $('seo-weight-warning').style.display = weightValid ? 'none' : 'block';
                $('seo-size-warning').style.display = sizeValid ? 'none' : 'block';

                const counts = {
                    weight: {light:0, medium:0, heavy:0, missing:0},
                    size: {small:0, medium:0, large:0, missing:0},
                    matrix: {
                        light:{small:0,medium:0,large:0},
                        medium:{small:0,medium:0,large:0},
                        heavy:{small:0,medium:0,large:0}
                    }
                };

                state.rows = products.map((product) => {
                    const weightGroup = classifyWeight(product, t);
                    const sizeGroup = classifySize(product, t);
                    counts.weight[weightGroup]++;
                    counts.size[sizeGroup]++;
                    if (weightGroup !== 'missing' && sizeGroup !== 'missing') counts.matrix[weightGroup][sizeGroup]++;
                    return Object.assign({}, product, {weightGroup, sizeGroup});
                });
                state.counts = counts;

                const total = products.length;
                const knownWeight = total - counts.weight.missing;
                const knownSize = total - counts.size.missing;

                $('seo-kpi-total').textContent = integerFormat.format(total);
                $('seo-kpi-weight').textContent = integerFormat.format(knownWeight);
                $('seo-kpi-no-weight').textContent = integerFormat.format(counts.weight.missing);
                $('seo-kpi-size').textContent = integerFormat.format(knownSize);
                $('seo-kpi-no-size').textContent = integerFormat.format(counts.size.missing);

                updateBar('seo-bar-weight-', 'light', counts.weight.light, knownWeight);
                updateBar('seo-bar-weight-', 'medium', counts.weight.medium, knownWeight);
                updateBar('seo-bar-weight-', 'heavy', counts.weight.heavy, knownWeight);
                updateBar('seo-bar-size-', 'small', counts.size.small, knownSize);
                updateBar('seo-bar-size-', 'medium', counts.size.medium, knownSize);
                updateBar('seo-bar-size-', 'large', counts.size.large, knownSize);

                $('seo-weight-missing-note').textContent = integerFormat.format(counts.weight.missing) + ' paquetes sin peso (' + numberFormat.format(pct(counts.weight.missing, total)) + '% del total).';
                $('seo-size-missing-note').textContent = integerFormat.format(counts.size.missing) + ' paquetes sin las tres dimensiones (' + numberFormat.format(pct(counts.size.missing, total)) + '% del total).';

                ['light','medium','heavy'].forEach((wg) => {
                    let rowTotal = 0;
                    ['small','medium','large'].forEach((sg) => {
                        const n = counts.matrix[wg][sg];
                        rowTotal += n;
                        $('seo-matrix-' + wg + '-' + sg).textContent = integerFormat.format(n);
                    });
                    $('seo-matrix-' + wg + '-total').textContent = integerFormat.format(rowTotal);
                });

                const dominantWeight = dominant(counts.weight, ['light','medium','heavy']);
                const dominantSize = dominant(counts.size, ['small','medium','large']);
                const weightShare = pct(counts.weight[dominantWeight], knownWeight);
                const sizeShare = pct(counts.size[dominantSize], knownSize);
                let profile = '<strong>Perfil dominante:</strong> ' + labels[dominantWeight] + ' por peso (' + numberFormat.format(weightShare) + '% de los que tienen peso) y ' + labels[dominantSize] + ' por tamano (' + numberFormat.format(sizeShare) + '% de los que tienen dimensiones).';
                if (counts.weight.missing || counts.size.missing) {
                    profile += ' Antes de decidir una tarifa o envio gratis, conviene completar los datos faltantes para que la muestra no quede sesgada.';
                } else {
                    profile += ' Puedes usar esta distribucion como base para comparar tus tarifas reales de transporte por tipo de paquete.';
                }
                $('seo-sizes-profile').innerHTML = profile;

                state.page = 1;
                renderTable();
            }

            function fmtNumber(value, unit) {
                if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
                return numberFormat.format(Number(value)) + (unit ? ' ' + unit : '');
            }

            function escapeHtml(value) {
                return String(value === null || value === undefined ? '' : value)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            function renderTable() {
                const search = $('seo-sizes-search').value.trim().toLowerCase();
                const fw = $('seo-sizes-filter-weight').value;
                const fs = $('seo-sizes-filter-size').value;
                const pageSize = Math.max(1, parseInt($('seo-sizes-page-size').value, 10) || 50);

                const filtered = state.rows.filter((row) => {
                    if (fw && row.weightGroup !== fw) return false;
                    if (fs && row.sizeGroup !== fs) return false;
                    if (search) {
                        const haystack = (row.id + ' ' + (row.name || '') + ' ' + (row.sku || '')).toLowerCase();
                        if (haystack.indexOf(search) === -1) return false;
                    }
                    return true;
                });

                const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
                if (state.page > pages) state.page = pages;
                const start = (state.page - 1) * pageSize;
                const slice = filtered.slice(start, start + pageSize);
                const body = $('seo-sizes-table-body');

                if (!slice.length) {
                    body.innerHTML = '<tr><td colspan="10" class="seo-sizes-muted">No hay productos para estos filtros.</td></tr>';
                } else {
                    body.innerHTML = slice.map((row) => {
                        const dims = (row.length !== null && row.width !== null && row.height !== null)
                            ? [fmtNumber(row.length,''), fmtNumber(row.width,''), fmtNumber(row.height,'')].join(' x ') + ' ' + escapeHtml(dimensionUnit)
                            : '—';
                        return '<tr>' +
                            '<td>' + integerFormat.format(row.id) + '</td>' +
                            '<td><strong>' + escapeHtml(row.name || '(sin titulo)') + '</strong></td>' +
                            '<td>' + (row.sku ? escapeHtml(row.sku) : '<span class="seo-sizes-muted">—</span>') + '</td>' +
                            '<td>' + escapeHtml(row.kind || '') + '</td>' +
                            '<td class="num">' + fmtNumber(row.weight, weightUnit) + '</td>' +
                            '<td><span class="seo-sizes-tag">' + escapeHtml(labels[row.weightGroup]) + '</span></td>' +
                            '<td class="num">' + dims + '</td>' +
                            '<td class="num">' + fmtNumber(row.maxSide, dimensionUnit) + '</td>' +
                            '<td><span class="seo-sizes-tag">' + escapeHtml(labels[row.sizeGroup]) + '</span></td>' +
                            '<td><a href="' + escapeHtml(editBase + row.id) + '">Editar</a></td>' +
                        '</tr>';
                    }).join('');
                }

                $('seo-sizes-filter-count').textContent = integerFormat.format(filtered.length) + ' productos';
                $('seo-sizes-page-info').textContent = 'Pagina ' + state.page + ' de ' + pages;
                $('seo-sizes-prev').disabled = state.page <= 1;
                $('seo-sizes-next').disabled = state.page >= pages;
            }

            fields.weightMedium.addEventListener('input', function(){ fields.weightHeavy.value = fields.weightMedium.value; compute(); });
            fields.weightHeavy.addEventListener('input', function(){ fields.weightMedium.value = fields.weightHeavy.value; compute(); });
            fields.sizeMedium.addEventListener('input', function(){ fields.sizeLarge.value = fields.sizeMedium.value; compute(); });
            fields.sizeLarge.addEventListener('input', function(){ fields.sizeMedium.value = fields.sizeLarge.value; compute(); });
            fields.weightLight.addEventListener('input', compute);
            fields.sizeSmall.addEventListener('input', compute);

            $('seo-sizes-reset').addEventListener('click', function(){ $('seo-product-sizes-action').value = 'reset'; });
            $('seo-product-sizes-settings').addEventListener('submit', function(e){
                const t = currentThresholds();
                if ($('seo-product-sizes-action').value === 'save' && (t.weightMedium <= t.weightLight || t.sizeMedium <= t.sizeSmall)) {
                    e.preventDefault();
                    $('seo-weight-warning').style.display = t.weightMedium <= t.weightLight ? 'block' : 'none';
                    $('seo-size-warning').style.display = t.sizeMedium <= t.sizeSmall ? 'block' : 'none';
                }
            });

            ['seo-sizes-search','seo-sizes-filter-weight','seo-sizes-filter-size','seo-sizes-page-size'].forEach((id) => {
                $(id).addEventListener(id === 'seo-sizes-search' ? 'input' : 'change', function(){ state.page = 1; renderTable(); });
            });
            $('seo-sizes-prev').addEventListener('click', function(){ if (state.page > 1) { state.page--; renderTable(); } });
            $('seo-sizes-next').addEventListener('click', function(){ state.page++; renderTable(); });

            compute();
        })();
        </script>
        <?php
    }
}
