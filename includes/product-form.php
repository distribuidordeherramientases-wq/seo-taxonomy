<?php
/**
 * Formulario compartido de alta/edicion de producto.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_product_category_option_tree')) {
    function seo_product_category_option_tree(array $terms, $parent_id, array $selected, $depth = 0) {
        foreach ($terms as $term) {
            if ((int) $term->parent !== (int) $parent_id) {
                continue;
            }

            printf(
                '<option value="%1$d" %2$s>%3$s%4$s</option>',
                absint($term->term_id),
                selected(in_array(absint($term->term_id), $selected, true), true, false),
                esc_html(str_repeat('— ', $depth)),
                esc_html($term->name)
            );

            seo_product_category_option_tree($terms, $term->term_id, $selected, $depth + 1);
        }
    }
}

if (!function_exists('seo_product_current_brand')) {
    function seo_product_current_brand($product_id) {
        $product_id = absint($product_id);
        if ($product_id < 1) {
            return '';
        }

        $brand = (string) get_post_meta($product_id, '_seo_marca_proveedor', true);
        if ($brand !== '') {
            return $brand;
        }

        $taxonomy = function_exists('seo_product_brand_taxonomy') ? seo_product_brand_taxonomy() : '';
        if ($taxonomy !== '') {
            $terms = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'names']);
            if (!is_wp_error($terms) && !empty($terms)) {
                return (string) $terms[0];
            }
        }

        return '';
    }
}

if (!function_exists('seo_render_product_form')) {
    function seo_render_product_form($product_id = 0) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $product_id = absint($product_id);
        $is_edit = $product_id > 0;
        $product = $is_edit && function_exists('wc_get_product') ? wc_get_product($product_id) : null;

        if ($is_edit && !$product) {
            echo '<div class="notice notice-error"><p>El producto solicitado no existe.</p></div>';
            return;
        }

        wp_enqueue_media();
        if (function_exists('wp_script_is') && wp_script_is('selectWoo', 'registered')) {
            wp_enqueue_script('selectWoo');
        }
        if (function_exists('wp_style_is') && wp_style_is('select2', 'registered')) {
            wp_enqueue_style('select2');
        }

        $post = $is_edit ? get_post($product_id) : null;
        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        if (is_wp_error($categories)) {
            $categories = [];
        }

        $selected_categories = $is_edit
            ? array_map('absint', wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']))
            : [];

        $vocabulary_terms = function_exists('seo_product_get_vocabulary_terms')
            ? seo_product_get_vocabulary_terms()
            : ['tipo' => [], 'rol' => [], 'aplicacion' => [], 'plataforma' => [], 'subtipo' => []];
        $assignments = function_exists('seo_product_get_vocabulary_assignments')
            ? seo_product_get_vocabulary_assignments($product_id)
            : ['tipo' => [], 'rol' => [], 'aplicacion' => [], 'plataforma' => [], 'subtipo' => []];
        $type_role_map = function_exists('seo_product_get_type_role_map')
            ? seo_product_get_type_role_map()
            : [];

        $selected_type = !empty($assignments['tipo']) ? absint($assignments['tipo'][0]) : 0;
        $selected_role = !empty($assignments['rol']) ? absint($assignments['rol'][0]) : 0;
        if ($selected_type > 0 && isset($type_role_map[$selected_type])) {
            $selected_role = absint($type_role_map[$selected_type]['id']);
        }

        $role_labels = [];
        foreach ((array) ($vocabulary_terms['rol'] ?? []) as $role_row) {
            $role_labels[absint($role_row['id'] ?? 0)] = (string) ($role_row['label'] ?? $role_row['slug'] ?? '');
        }

        $provider = $is_edit ? (string) get_post_meta($product_id, '_seo_proveedor', true) : '';
        $provider_external_id = $is_edit ? (string) get_post_meta($product_id, '_seo_proveedor_id_externo', true) : '';
        $provider_mpn = $is_edit ? (string) get_post_meta($product_id, '_seo_proveedor_mpn', true) : '';
        $provider_category = $is_edit ? (string) get_post_meta($product_id, '_seo_categoria_proveedor', true) : '';
        $provider_price = $is_edit ? (string) get_post_meta($product_id, '_seo_precio_proveedor', true) : '';
        $provider_url = $is_edit ? (string) get_post_meta($product_id, '_seo_proveedor_url_origen', true) : '';
        $manufacturer = $is_edit ? (string) get_post_meta($product_id, '_seo_fabricante', true) : '';
        $brand = $is_edit ? seo_product_current_brand($product_id) : '';
        $providers = function_exists('seo_product_get_provider_suggestions') ? seo_product_get_provider_suggestions() : [];

        $featured_image_id = $is_edit ? absint($product->get_image_id('edit')) : 0;
        $gallery_ids = $is_edit ? array_map('absint', (array) $product->get_gallery_image_ids('edit')) : [];
        $external_images = $is_edit && function_exists('seo_product_get_external_images')
            ? seo_product_get_external_images($product_id)
            : [];

        $image_mode = !empty($external_images) || ($is_edit && get_post_meta($product_id, '_seo_imagenes_externas', true))
            ? 'external'
            : (($featured_image_id > 0 || !empty($gallery_ids)) ? 'media' : 'none');

        $regular_price = $is_edit ? (string) $product->get_regular_price('edit') : '';
        $sale_price = $is_edit ? (string) $product->get_sale_price('edit') : '';
        $manage_stock = $is_edit ? (bool) $product->get_manage_stock('edit') : false;
        $stock_quantity = $is_edit ? $product->get_stock_quantity('edit') : '';
        $stock_status = $is_edit ? (string) $product->get_stock_status('edit') : 'instock';
        $status = $is_edit ? (string) $product->get_status('edit') : 'draft';
        $attributes_text = $is_edit && function_exists('seo_product_get_attributes_text')
            ? seo_product_get_attributes_text($product_id)
            : '';
        $attribute_catalog = function_exists('seo_attributes_get_catalog')
            ? seo_attributes_get_catalog(true)
            : [];

        $form_id = 'seo-product-form-' . ($is_edit ? $product_id : 'new');
        $role_label = $selected_role > 0 ? ($role_labels[$selected_role] ?? '') : '';
        ?>

        <style>
            .seo-product-form{max-width:1180px}
            .seo-product-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin:0 0 18px}
            .seo-product-card h2{margin:0 0 15px;font-size:16px}
            .seo-product-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px 18px}
            .seo-product-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px 18px}
            .seo-product-field label{display:block;font-weight:600;margin:0 0 5px}
            .seo-product-field input[type=text],.seo-product-field input[type=number],.seo-product-field input[type=url],.seo-product-field select,.seo-product-field textarea{width:100%;max-width:none}
            .seo-product-field textarea{min-height:110px}
            .seo-product-field small{display:block;color:#646970;margin-top:4px}
            .seo-product-full{grid-column:1/-1}
            .seo-product-required{color:#b32d2e}
            .seo-product-media-preview{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
            .seo-product-media-preview img{width:70px;height:70px;object-fit:cover;border:1px solid #dcdcde;border-radius:4px;background:#fff}
            .seo-product-radio-row{display:flex;gap:18px;flex-wrap:wrap;margin-bottom:14px}
            @media(max-width:900px){.seo-product-grid,.seo-product-grid-3{grid-template-columns:1fr}}
        </style>

        <form id="<?php echo esc_attr($form_id); ?>" class="seo-product-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="seo_save_single_product">
            <input type="hidden" name="return_tab" value="<?php echo $is_edit ? 'editar' : 'nuevo'; ?>">
            <input type="hidden" name="seo_product[product_id]" value="<?php echo esc_attr($product_id); ?>">
            <?php wp_nonce_field('seo_save_single_product', 'seo_product_nonce'); ?>

            <div class="seo-product-card">
                <h2>Identidad del producto</h2>
                <div class="seo-product-grid">
                    <div class="seo-product-field seo-product-full">
                        <label>Título <span class="seo-product-required">*</span></label>
                        <input type="text" name="seo_product[title]" required value="<?php echo esc_attr($is_edit ? $product->get_name('edit') : ''); ?>">
                    </div>
                    <div class="seo-product-field">
                        <label>SKU <span class="seo-product-required">*</span></label>
                        <input type="text" name="seo_product[sku]" required value="<?php echo esc_attr($is_edit ? $product->get_sku('edit') : ''); ?>">
                    </div>
                    <div class="seo-product-field">
                        <label>Slug</label>
                        <input type="text" name="seo_product[slug]" value="<?php echo esc_attr($is_edit ? $product->get_slug('edit') : ''); ?>">
                    </div>
                    <div class="seo-product-field">
                        <label>Estado</label>
                        <select name="seo_product[status]">
                            <?php foreach (['draft' => 'Borrador', 'publish' => 'Publicado', 'pending' => 'Pendiente', 'private' => 'Privado'] as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($is_edit): ?>
                        <div class="seo-product-field">
                            <label>ID WooCommerce</label>
                            <input type="text" readonly value="<?php echo esc_attr($product_id); ?>">
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="seo-product-card">
                <h2>Proveedor y marca</h2>
                <div class="seo-product-grid-3">
                    <div class="seo-product-field">
                        <label>Proveedor <?php if (!$is_edit): ?><span class="seo-product-required">*</span><?php endif; ?></label>
                        <input type="text" name="seo_product[provider]" list="seo-provider-list" <?php echo !$is_edit ? 'required' : ''; ?> value="<?php echo esc_attr($provider); ?>" placeholder="Ej. AUDIOLEDCAR">
                        <datalist id="seo-provider-list">
                            <?php foreach ($providers as $provider_option): ?>
                                <option value="<?php echo esc_attr($provider_option); ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="seo-product-field">
                        <label>Referencia externa</label>
                        <input type="text" name="seo_product[provider_external_id]" value="<?php echo esc_attr($provider_external_id); ?>">
                        <small>Si queda vacía se utilizará el SKU.</small>
                    </div>
                    <div class="seo-product-field">
                        <label>MPN / referencia fabricante</label>
                        <input type="text" name="seo_product[provider_mpn]" value="<?php echo esc_attr($provider_mpn); ?>">
                    </div>
                    <div class="seo-product-field">
                        <label>Marca</label>
                        <input type="text" name="seo_product[brand]" value="<?php echo esc_attr($brand); ?>" placeholder="Ej. ZesfOr">
                    </div>
                    <div class="seo-product-field">
                        <label>Fabricante</label>
                        <input type="text" name="seo_product[manufacturer]" value="<?php echo esc_attr($manufacturer); ?>">
                    </div>
                    <div class="seo-product-field">
                        <label>Categoría del proveedor</label>
                        <input type="text" name="seo_product[provider_category]" value="<?php echo esc_attr($provider_category); ?>">
                    </div>
                    <div class="seo-product-field">
                        <label>Coste / precio proveedor</label>
                        <input type="number" step="0.01" min="0" name="seo_product[provider_price]" value="<?php echo esc_attr($provider_price); ?>">
                    </div>
                    <div class="seo-product-field seo-product-full">
                        <label>URL origen del proveedor</label>
                        <input type="url" name="seo_product[provider_url]" value="<?php echo esc_attr($provider_url); ?>">
                    </div>
                </div>
            </div>

            <div class="seo-product-card">
                <h2>Venta y stock</h2>
                <div class="seo-product-grid-3">
                    <div class="seo-product-field">
                        <label>Precio normal</label>
                        <input type="number" step="0.01" min="0" name="seo_product[regular_price]" value="<?php echo esc_attr($regular_price); ?>">
                    </div>
                    <div class="seo-product-field">
                        <label>Precio oferta</label>
                        <input type="number" step="0.01" min="0" name="seo_product[sale_price]" value="<?php echo esc_attr($sale_price); ?>">
                    </div>
                    <div class="seo-product-field">
                        <label>Estado stock</label>
                        <select name="seo_product[stock_status]">
                            <option value="instock" <?php selected($stock_status, 'instock'); ?>>En stock</option>
                            <option value="outofstock" <?php selected($stock_status, 'outofstock'); ?>>Agotado</option>
                            <option value="onbackorder" <?php selected($stock_status, 'onbackorder'); ?>>Bajo pedido</option>
                        </select>
                    </div>
                    <div class="seo-product-field">
                        <label><input type="checkbox" name="seo_product[manage_stock]" value="1" <?php checked($manage_stock); ?>> Gestionar cantidad de stock</label>
                    </div>
                    <div class="seo-product-field">
                        <label>Cantidad</label>
                        <input type="number" step="1" name="seo_product[stock_quantity]" value="<?php echo esc_attr($stock_quantity); ?>">
                    </div>
                </div>
                <div class="seo-product-grid-3" style="margin-top:14px;">
                    <div class="seo-product-field"><label>Peso</label><input type="number" step="0.001" min="0" name="seo_product[weight]" value="<?php echo esc_attr($is_edit ? $product->get_weight('edit') : ''); ?>"></div>
                    <div class="seo-product-field"><label>Longitud</label><input type="number" step="0.01" min="0" name="seo_product[length]" value="<?php echo esc_attr($is_edit ? $product->get_length('edit') : ''); ?>"></div>
                    <div class="seo-product-field"><label>Anchura</label><input type="number" step="0.01" min="0" name="seo_product[width]" value="<?php echo esc_attr($is_edit ? $product->get_width('edit') : ''); ?>"></div>
                    <div class="seo-product-field"><label>Altura</label><input type="number" step="0.01" min="0" name="seo_product[height]" value="<?php echo esc_attr($is_edit ? $product->get_height('edit') : ''); ?>"></div>
                </div>
            </div>

            <div class="seo-product-card">
                <h2>Categoría y vocabulario semántico</h2>
                <div class="seo-product-grid">
                    <div class="seo-product-field seo-product-full">
                        <label>Categorías WooCommerce <span class="seo-product-required">*</span></label>
                        <select name="seo_product[category_ids][]" multiple size="9" required>
                            <?php seo_product_category_option_tree((array) $categories, 0, $selected_categories); ?>
                        </select>
                        <small>Selecciona la categoría editorial real. No se crean términos desde este formulario.</small>
                    </div>
                    <div class="seo-product-field">
                        <label>TIPO <span class="seo-product-required">*</span></label>
                        <select id="seo-product-type" name="seo_product[type_id]" required>
                            <option value="">— Seleccionar TIPO —</option>
                            <?php foreach ((array) ($vocabulary_terms['tipo'] ?? []) as $term): ?>
                                <option value="<?php echo absint($term['id']); ?>" <?php selected($selected_type, absint($term['id'])); ?>><?php echo esc_html($term['label'] ?? $term['slug']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="seo-product-field">
                        <label>ROL derivado</label>
                        <input id="seo-product-role-label" type="text" readonly value="<?php echo esc_attr($role_label); ?>">
                        <small>Se obtiene automáticamente del TIPO y se guarda en el vocabulario.</small>
                    </div>
                    <?php
                    foreach ([
                        'aplicacion' => 'APLICACIÓN',
                        'plataforma' => 'PLATAFORMA',
                        'subtipo'    => 'SUBTIPO',
                    ] as $group => $label):
                        $field_name = $group === 'aplicacion' ? 'application_ids' : ($group === 'plataforma' ? 'platform_ids' : 'subtype_ids');
                        ?>
                        <div class="seo-product-field">
                            <label><?php echo esc_html($label); ?></label>
                            <select name="seo_product[<?php echo esc_attr($field_name); ?>][]" multiple size="7">
                                <?php foreach ((array) ($vocabulary_terms[$group] ?? []) as $term): ?>
                                    <?php $term_id = absint($term['id']); ?>
                                    <option value="<?php echo $term_id; ?>" <?php selected(in_array($term_id, array_map('absint', (array) ($assignments[$group] ?? [])), true), true); ?>><?php echo esc_html($term['label'] ?? $term['slug']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="seo-product-card">
                <h2>Atributos técnicos del producto</h2>
                <div class="seo-product-field">
                    <label>Un atributo por línea: <code>tipo|valor</code></label>
                    <textarea name="seo_product[attributes_text]" spellcheck="false" style="font-family:monospace;min-height:170px;"><?php echo esc_textarea($attributes_text); ?></textarea>
                    <small>Ejemplo: <code>interfaz|OBD2</code>. El tipo debe existir en <code>wp_sql_atributos</code>; si es de tipo término, el valor debe existir como término o alias. El ámbito ya no se guarda aquí: la clasificación del producto se gestiona mediante TIPO/ROL.</small>
                </div>

                <?php if (!empty($attribute_catalog)): ?>
                    <details style="margin-top:14px;">
                        <summary style="cursor:pointer;font-weight:600;">Ver vocabulario de atributos disponible</summary>
                        <div style="overflow:auto;max-height:360px;margin-top:10px;">
                            <table class="widefat striped" style="min-width:760px;">
                                <thead><tr><th>Slug</th><th>Nombre</th><th>Tipo</th><th>Unidad base</th><th>Valores controlados</th></tr></thead>
                                <tbody>
                                <?php foreach ($attribute_catalog as $attribute_definition): ?>
                                    <?php
                                    $terms = array_values(array_filter(array_map(static function ($term) {
                                        return trim((string) ($term['nombre'] ?? ''));
                                    }, (array) ($attribute_definition['terms'] ?? []))));
                                    ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string) ($attribute_definition['slug'] ?? '')); ?></code></td>
                                        <td><?php echo esc_html((string) ($attribute_definition['nombre'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) ($attribute_definition['tipo'] ?? '')); ?></td>
                                        <td><?php echo esc_html((string) ($attribute_definition['unidad_base'] ?? '')); ?></td>
                                        <td><?php echo $terms ? esc_html(implode(', ', $terms)) : '<span style="color:#646970;">valor libre/numérico</span>'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <small>La edición completa de definiciones, términos y aliases se realiza en la pantalla de atributos.</small>
                    </details>
                <?php endif; ?>
            </div>

            <div class="seo-product-card">
                <h2>Contenido</h2>
                <div class="seo-product-field" style="margin-bottom:15px;">
                    <label>Extracto</label>
                    <textarea name="seo_product[excerpt]" style="min-height:90px;"><?php echo esc_textarea($is_edit ? $post->post_excerpt : ''); ?></textarea>
                </div>
                <div class="seo-product-field">
                    <label>Descripción</label>
                    <textarea name="seo_product[description]" style="min-height:260px;"><?php echo esc_textarea($is_edit ? $post->post_content : ''); ?></textarea>
                </div>
            </div>

            <div class="seo-product-card">
                <h2>Imágenes</h2>
                <div class="seo-product-radio-row">
                    <label><input type="radio" name="seo_product[image_mode]" value="media" <?php checked($image_mode, 'media'); ?>> Biblioteca Media</label>
                    <label><input type="radio" name="seo_product[image_mode]" value="external" <?php checked($image_mode, 'external'); ?>> Externas del proveedor</label>
                    <label><input type="radio" name="seo_product[image_mode]" value="none" <?php checked($image_mode, 'none'); ?>> Sin imágenes</label>
                </div>

                <div id="seo-product-images-media" style="<?php echo $image_mode === 'media' ? '' : 'display:none;'; ?>">
                    <input id="seo-featured-image-id" type="hidden" name="seo_product[featured_image_id]" value="<?php echo esc_attr($featured_image_id); ?>">
                    <input id="seo-gallery-ids" type="hidden" name="seo_product[gallery_ids]" value="<?php echo esc_attr(implode(',', $gallery_ids)); ?>">
                    <p>
                        <button type="button" class="button" id="seo-select-featured">Seleccionar imagen principal</button>
                        <button type="button" class="button" id="seo-select-gallery">Seleccionar galería</button>
                        <button type="button" class="button" id="seo-clear-media">Vaciar selección</button>
                    </p>
                    <div id="seo-media-preview" class="seo-product-media-preview">
                        <?php
                        foreach (array_merge($featured_image_id ? [$featured_image_id] : [], $gallery_ids) as $attachment_id) {
                            $src = wp_get_attachment_image_url($attachment_id, 'thumbnail');
                            if ($src) {
                                echo '<img src="' . esc_url($src) . '" alt="">';
                            }
                        }
                        ?>
                    </div>
                </div>

                <div id="seo-product-images-external" style="<?php echo $image_mode === 'external' ? '' : 'display:none;'; ?>">
                    <div class="seo-product-field">
                        <label>URLs de imágenes del proveedor</label>
                        <textarea name="seo_product[external_images]" style="min-height:180px;" placeholder="Una URL por línea"><?php echo esc_textarea(implode("\n", $external_images)); ?></textarea>
                        <small>La primera URL se considera principal. No se descargan a Media: se relacionan mediante <code>seo_supplier_images</code>.</small>
                    </div>
                </div>

                <div id="seo-product-images-none" style="<?php echo $image_mode === 'none' ? '' : 'display:none;'; ?>">
                    <p style="color:#646970;">Al guardar en este modo se eliminará la selección local y se desactivarán las relaciones de imágenes externas del producto.</p>
                </div>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary button-hero">
                    <?php echo $is_edit ? 'Guardar producto' : 'Crear producto'; ?>
                </button>
                <?php if ($is_edit): ?>
                    <a class="button button-secondary" href="<?php echo esc_url(get_permalink($product_id)); ?>" target="_blank" rel="noopener">Ver producto</a>
                <?php endif; ?>
            </p>
        </form>

        <script>
        (function($){
            var typeRoleMap = <?php echo wp_json_encode($type_role_map); ?>;

            function updateRole(){
                var typeId = $('#seo-product-type').val();
                var row = typeRoleMap[typeId] || null;
                $('#seo-product-role-label').val(row && row.label ? row.label : '');
            }

            $('#seo-product-type').on('change', updateRole);
            updateRole();

            function toggleImageMode(){
                var mode = $('input[name="seo_product[image_mode]"]:checked').val();
                $('#seo-product-images-media').toggle(mode === 'media');
                $('#seo-product-images-external').toggle(mode === 'external');
                $('#seo-product-images-none').toggle(mode === 'none');
            }
            $('input[name="seo_product[image_mode]"]').on('change', toggleImageMode);
            toggleImageMode();

            var featuredFrame;
            $('#seo-select-featured').on('click', function(e){
                e.preventDefault();
                if (featuredFrame) { featuredFrame.open(); return; }
                featuredFrame = wp.media({title:'Seleccionar imagen principal', button:{text:'Usar imagen'}, multiple:false});
                featuredFrame.on('select', function(){
                    var item = featuredFrame.state().get('selection').first().toJSON();
                    $('#seo-featured-image-id').val(item.id);
                    renderMediaPreview();
                });
                featuredFrame.open();
            });

            var galleryFrame;
            $('#seo-select-gallery').on('click', function(e){
                e.preventDefault();
                galleryFrame = wp.media({title:'Seleccionar galería', button:{text:'Usar imágenes'}, multiple:true});
                galleryFrame.on('select', function(){
                    var ids = galleryFrame.state().get('selection').map(function(item){ return item.toJSON().id; });
                    $('#seo-gallery-ids').val(ids.join(','));
                    renderMediaPreview();
                });
                galleryFrame.open();
            });

            $('#seo-clear-media').on('click', function(e){
                e.preventDefault();
                $('#seo-featured-image-id').val('');
                $('#seo-gallery-ids').val('');
                $('#seo-media-preview').empty();
            });

            function renderMediaPreview(){
                var ids = [];
                var featured = $('#seo-featured-image-id').val();
                if (featured) ids.push(parseInt(featured, 10));
                var gallery = $('#seo-gallery-ids').val();
                if (gallery) {
                    gallery.split(',').forEach(function(id){
                        id = parseInt(id, 10);
                        if (id) ids.push(id);
                    });
                }
                $('#seo-media-preview').empty();
                ids.forEach(function(id){
                    var attachment = wp.media.attachment(id);
                    attachment.fetch().then(function(){
                        var data = attachment.toJSON();
                        var src = data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : data.url;
                        if (src) $('#seo-media-preview').append($('<img>', {src:src, alt:''}));
                    });
                });
            }

            if ($.fn.selectWoo) {
                $('#seo-product-type').selectWoo({width:'100%'});
                $('select[name="seo_product[application_ids][]"],select[name="seo_product[platform_ids][]"],select[name="seo_product[subtype_ids][]"]').selectWoo({width:'100%'});
            }
        })(jQuery);
        </script>
        <?php
    }
}
