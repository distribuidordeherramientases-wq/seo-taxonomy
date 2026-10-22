<?php
/**
 * Pantalla de auditoria local STAGING. Ninguna consulta modifica los productos.
 */
defined('ABSPATH') || exit;

final class TCS_Catalog_Parity_Admin {
    public static function register() {
        add_action('wp_ajax_tcs_catalog_parity', array(__CLASS__, 'ajax'));
    }

    private static function key() {
        return 'tcs_catalog_parity_' . get_current_user_id();
    }

    public static function state() {
        $state = get_transient(self::key());
        return is_array($state) ? $state : null;
    }

    private static function store($state) {
        set_transient(self::key(), $state, 12 * HOUR_IN_SECONDS);
    }

    public static function ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message'=>'No autorizado.'), 403);
        }
        check_ajax_referer('tcs_catalog_parity', 'nonce');
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : 'status';
        if ('start' === $operation) {
            $summary = TCS_Catalog_Parity::summary();
            $required = array('definitions','terms','values');
            foreach ($required as $key) {
                if (empty($summary['exists'][$key])) {
                    wp_send_json_error(array('message'=>'Falta la tabla de origen ' . $summary['tables'][$key]), 400);
                }
            }
            $state = TCS_Catalog_Parity::initial_state();
            self::store($state);
            wp_send_json_success(array('state'=>$state));
        }
        if ('next' === $operation) {
            $state = self::state();
            if (!$state) {
                wp_send_json_error(array('message'=>'No existe auditoria activa.'), 400);
            }
            if ($state['status'] !== 'running') {
                wp_send_json_success(array('state'=>$state));
            }
            $state = TCS_Catalog_Parity::step($state);
            if (is_wp_error($state)) {
                wp_send_json_error(array('message'=>$state->get_error_message()), 500);
            }
            self::store($state);
            wp_send_json_success(array('state'=>$state));
        }
        wp_send_json_success(array('state'=>self::state()));
    }

    public static function download() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        check_admin_referer('tcs_catalog_parity_download');
        $state = self::state();
        if (!$state) {
            wp_die('No existe resultado de auditoria.');
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="staging_paridad_productos.csv"');
        header('X-Content-Type-Options: nosniff');
        $handle = fopen('php://output', 'w');
        if (!$handle) {
            wp_die('No se pudo abrir el fichero de salida.');
        }
        $safe = static function($value) {
            $text = (string)$value;
            return preg_match('/^\s*[=+@-]/u', $text) ? "'" . $text : $text;
        };
        fputcsv($handle,array('tipo','clave','valor','detalle','producto','atributo'));
        foreach ($state as $key => $value) {
            if (is_scalar($value)) {
                fputcsv($handle,array_map($safe,array('resumen',$key,$value,'','','')));
            }
        }
        foreach ((array)$state['by_attribute'] as $slug=>$counters) {
            foreach ($counters as $key=>$value) {
                fputcsv($handle,array_map($safe,array('atributo',$slug,$value,$key,'','')));
            }
        }
        foreach ((array)$state['examples'] as $example) {
            fputcsv($handle,array_map($safe,array(
                'discrepancia', $example['status'], $example['source_value'],
                $example['native_value'], $example['product_id'], $example['attribute']
            )));
        }
        fclose($handle);
        exit;
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        $summary = TCS_Catalog_Parity::summary();
        $state = self::state();
        $counts = array(
            'products_all' => 'Productos en WordPress',
            'products_published' => 'Productos publicados',
            'custom_attribute_definitions' => 'Definiciones propias de atributos',
            'custom_attribute_terms' => 'Terminos propios de atributos',
            'custom_attribute_aliases' => 'Alias de atributos propios',
            'custom_product_attribute_rows' => 'Asignaciones propias de atributos',
            'custom_attr_assigned_products' => 'Productos con atributos propios',
            'custom_attr_orphan_rows' => 'Asignaciones sin producto WordPress',
            'wc_global_attribute_definitions' => 'Definiciones globales WooCommerce',
            'custom_defs_without_matching_wc_global' => 'Definiciones propias activas sin pa_* global equivalente',
            'custom_terms_without_matching_wc_term' => 'Terminos propios activos sin termino pa_* de igual slug',
            'wc_product_attribute_taxonomies' => 'Taxonomias de atributos pa_* presentes',
            'wc_product_attribute_terms' => 'Terminos en taxonomias pa_*',
            'wc_attr_meta_products' => 'Productos con _product_attributes',
            'wc_attr_assigned_products' => 'Productos con relaciones pa_*',
            'semantic_vocabulary_terms' => 'Etiquetas del vocabulary propio',
            'semantic_product_assignments' => 'Asignaciones semanticas a productos',
            'semantic_classified_products' => 'Productos con vocabulary semantico',
            'wc_product_tag_terms' => 'Terminos product_tag nativos',
            'wc_tagged_products' => 'Productos etiquetados con product_tag'
        );
        echo '<div class="wrap"><h2>Paridad de productos: tablas propias / WordPress</h2>';
        echo '<p>Auditoria <strong>solo lectura</strong> sobre ESTA instalacion. No copia etiquetas ni atributos y no escribe en productos, postmeta, WooCommerce ni taxonomias.</p>';
        echo '<p><strong>Importante:</strong> tener el mismo numero de filas no prueba equivalencia. La comprobacion por producto compara atributos, valores y terminos, e informa por separado del vocabulary semantico.</p>';
        foreach ($summary['warnings'] as $warning) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($warning) . '</p></div>';
        }
        echo '<table class="widefat striped"><tbody>';
        foreach ($counts as $key=>$label) {
            $value = isset($summary['counts'][$key]) ? number_format_i18n($summary['counts'][$key]) : 'No disponible';
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>Comparacion de atributos por producto</h3>';
        echo '<p>Examina como maximo 100 productos por peticion para minimizar la carga. Solo se procesan los productos que tienen filas en las tablas propias. El informe de etiquetas semanticas se limita a este mismo conjunto.</p>';
        echo '<p class="description">Las etiquetas product_tag y el vocabulary semantico no son equivalentes por definicion: una diferencia entre ambos no demuestra necesariamente un error de migracion. Los atributos se cotejan por slug y valor normalizado, lo que puede requerir revisar alias, unidades, nombres distintos y variaciones.</p>';
        $nonce = wp_create_nonce('tcs_catalog_parity');
        echo '<div id="tcs-parity-audit" data-nonce="' . esc_attr($nonce) . '" data-endpoint="' . esc_url(admin_url('admin-ajax.php')) . '">';
        echo '<button id="tcs-parity-start" class="button button-primary" type="button">Iniciar auditoria de solo lectura</button> ';
        $download = wp_nonce_url(admin_url('admin-post.php?action=tcs_catalog_parity_download'), 'tcs_catalog_parity_download');
        echo '<a href="' . esc_url($download) . '" class="button" id="tcs-parity-download" ' . (!$state ? 'style="display:none"' : '') . '>Exportar discrepancias CSV</a>';
        echo '<p id="tcs-parity-status" role="status">' . ($state ? esc_html('Estado anterior: ' . $state['status'] . ' · productos: ' . (int)$state['products_scanned']) : 'Sin auditoria iniciada') . '</p>';
        echo '<pre id="tcs-parity-output" style="white-space:pre-wrap;max-height:420px;overflow:auto;padding:12px;background:#f6f7f7;border:1px solid #c3c4c7"></pre>';
        echo '</div>';
        ?>
        <script>
        (function () {
            const root=document.getElementById('tcs-parity-audit');
            const start=document.getElementById('tcs-parity-start');
            const status=document.getElementById('tcs-parity-status');
            const output=document.getElementById('tcs-parity-output');
            const download=document.getElementById('tcs-parity-download');
            if (!root || !start || !status || !output) return;
            let active=false;
            async function request(operation) {
                const data=new URLSearchParams({
                    action:'tcs_catalog_parity',nonce:root.dataset.nonce,operation
                });
                const response=await fetch(root.dataset.endpoint,{method:'POST',
                    credentials:'same-origin',
                    headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:data.toString()
                });
                const body=await response.json();
                if (!body.success) throw new Error((body.data && body.data.message) || 'La auditoria ha fallado.');
                return body.data.state;
            }
            function paint(s) {
                if (!s) return;
                status.textContent='Estado: '+s.status+' · Productos revisados: '+s.products_scanned+
                    ' · Atributos propios: '+s.source_rows+' · Coincidencias: '+s.attribute_matches+
                    ' · Atributos ausentes: '+s.attribute_missing+' · Valores diferentes: '+s.attribute_value_mismatch;
                const top=Object.entries(s.by_attribute||{}).sort((a,b)=>b[1].missing-a[1].missing).slice(0,30);
                const lines=[
                    'Comparación de atributos',
                    'Coinciden: '+s.attribute_matches,
                    'Definición no presente en WooCommerce: '+s.attribute_missing,
                    'Falta término pa_*: '+s.attribute_terms_missing,
                    'Valor no coincidente: '+s.attribute_value_mismatch,
                    'No es posible comparar valor: '+s.attribute_unresolvable,
                    'Definiciones nativas adicionales: '+s.native_extra_attributes,
                    '',
                    'ETIQUETAS — sistemas distintos, coincidencia informativa:',
                    'Etiquetas semánticas propias: '+s.semantic_rows,
                    'Etiquetas product_tag: '+s.native_product_tags,
                    'Solapamiento por slug: '+s.semantic_tag_slug_overlap,
                    'Semánticas sin mismo product_tag: '+s.semantic_not_product_tag,
                    'product_tag sin misma etiqueta semántica: '+s.product_tag_not_semantic,
                    '',
                    'Atributos por tipo (primeros 30, ordenados por ausencias):',
                    ...top.map(([slug,v])=>slug+': '+v.total+' propios; '+v.matched+' iguales; '+v.missing+' ausentes; '+v.mismatch+' distintos'),
                    '',
                    'Ejemplos de discrepancias (máximo 200):',
                    ...(s.examples||[]).slice(0,200).map(e=>'#'+e.product_id+' '+e.attribute+' ['+e.status+'] propio: '+e.source_value+' / WC: '+e.native_value)
                ];
                output.textContent=lines.join('\n');
                if(download) download.style.display='';
            }
            start.addEventListener('click',async function(){
                if (active) return;
                active=true;start.disabled=true;start.textContent='Auditoria en curso...';
                try {
                    let state=await request('start');paint(state);
                    while (state && state.status==='running') {
                        state=await request('next');paint(state);
                    }
                } catch(error) {
                    status.textContent='Auditoria interrumpida: '+error.message+'. Puedes iniciarla de nuevo.';
                } finally {
                    active=false;start.disabled=false;start.textContent='Reiniciar auditoria';
                }
            });
            request('status').then(paint).catch(()=>{});
        })();
        </script>
        <?php
        echo '</div>';
    }
}

TCS_Catalog_Parity_Admin::register();
