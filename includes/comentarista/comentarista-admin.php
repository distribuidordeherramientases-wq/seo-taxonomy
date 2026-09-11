<?php
/**
 * Comentarista - administracion manual.
 */

defined('ABSPATH') || exit;

function seo_comentarista_register_admin_page()
{
    add_submenu_page(
        'seo-system',
        'Comentarista',
        'Comentarista',
        'manage_options',
        'seo-comentarista',
        'seo_comentarista_admin_page'
    );
}
add_action('admin_menu', 'seo_comentarista_register_admin_page', 35);

function seo_comentarista_admin_assets()
{
    if (empty($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== 'seo-comentarista') {
        return;
    }

    if (wp_script_is('wc-enhanced-select', 'registered')) {
        wp_enqueue_script('wc-enhanced-select');
    }
    if (wp_style_is('woocommerce_admin_styles', 'registered')) {
        wp_enqueue_style('woocommerce_admin_styles');
    }
}
add_action('admin_enqueue_scripts', 'seo_comentarista_admin_assets');

function seo_comentarista_admin_datetime_value($mysql_datetime)
{
    $value = trim((string) $mysql_datetime);
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    return $timestamp ? wp_date('Y-m-d\TH:i', $timestamp) : '';
}

function seo_comentarista_admin_parse_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    return $timestamp ? wp_date('Y-m-d H:i:s', $timestamp) : null;
}

/**
 * Procesa altas/ediciones/borrados y devuelve un aviso.
 *
 * @return array{0:string,1:string}|null
 */
function seo_comentarista_admin_handle_action()
{
    if (!current_user_can('manage_options')) {
        return null;
    }

    if (!empty($_GET['seo_comentarista_delete'])) {
        $id = absint($_GET['seo_comentarista_delete']);
        check_admin_referer('seo_comentarista_delete_' . $id);

        if (seo_comentarista_delete($id)) {
            return array('success', 'Registro eliminado.');
        }
        return array('error', 'No se pudo eliminar el registro.');
    }

    if (empty($_POST['seo_comentarista_action']) || $_POST['seo_comentarista_action'] !== 'save') {
        return null;
    }

    check_admin_referer('seo_comentarista_save', 'seo_comentarista_nonce');

    $data = array(
        'product_id'          => absint($_POST['product_id'] ?? 0),
        'content_type'        => sanitize_key(wp_unslash($_POST['content_type'] ?? 'comment')),
        'source_platform'     => sanitize_key(wp_unslash($_POST['source_platform'] ?? 'web')),
        'source_name'         => sanitize_text_field(wp_unslash($_POST['source_name'] ?? '')),
        'source_url'          => esc_url_raw(wp_unslash($_POST['source_url'] ?? '')),
        'external_id'         => sanitize_text_field(wp_unslash($_POST['external_id'] ?? '')),
        'source_title'        => sanitize_text_field(wp_unslash($_POST['source_title'] ?? '')),
        'author_name'         => sanitize_text_field(wp_unslash($_POST['author_name'] ?? '')),
        'author_url'          => esc_url_raw(wp_unslash($_POST['author_url'] ?? '')),
        'source_published_at' => seo_comentarista_admin_parse_datetime(wp_unslash($_POST['source_published_at'] ?? '')),
        'captured_at'         => seo_comentarista_admin_parse_datetime(wp_unslash($_POST['captured_at'] ?? '')) ?: current_time('mysql'),
        'source_content'      => wp_kses_post(wp_unslash($_POST['source_content'] ?? '')),
        'editorial_summary'   => wp_kses_post(wp_unslash($_POST['editorial_summary'] ?? '')),
        'rating_value'        => isset($_POST['rating_value']) ? trim((string) wp_unslash($_POST['rating_value'])) : '',
        'rating_scale'        => isset($_POST['rating_scale']) ? trim((string) wp_unslash($_POST['rating_scale'])) : '',
        'embed_url'           => esc_url_raw(wp_unslash($_POST['embed_url'] ?? '')),
        'thumbnail_url'       => esc_url_raw(wp_unslash($_POST['thumbnail_url'] ?? '')),
        'status'              => sanitize_key(wp_unslash($_POST['status'] ?? 'published')),
        'display_order'       => intval($_POST['display_order'] ?? 0),
    );

    $data = seo_comentarista_enrich_source_data($data);
    $id = absint($_POST['comentarista_id'] ?? 0);

    if ($id) {
        $result = seo_comentarista_update($id, $data);
        if (is_wp_error($result)) {
            return array('error', $result->get_error_message());
        }
        return array('success', 'Registro actualizado.');
    }

    $result = seo_comentarista_insert($data);
    if (is_wp_error($result)) {
        return array('error', $result->get_error_message());
    }

    return array('success', 'Registro creado.');
}

function seo_comentarista_admin_product_field($product_id)
{
    $product_id = absint($product_id);
    $label = '';
    if ($product_id) {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $label = $product ? $product->get_formatted_name() : ('#' . $product_id . ' - ' . get_the_title($product_id));
    }
    ?>
    <select
        class="wc-product-search"
        style="width:100%;max-width:650px;"
        name="product_id"
        data-placeholder="Buscar producto..."
        data-action="woocommerce_json_search_products_and_variations"
        required
    >
        <?php if ($product_id) : ?>
            <option value="<?php echo esc_attr($product_id); ?>" selected><?php echo esc_html($label); ?></option>
        <?php endif; ?>
    </select>
    <?php
}

function seo_comentarista_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-system'));
    }

    $install = seo_comentarista_maybe_install_schema();
    if (is_wp_error($install)) {
        echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html($install->get_error_message()) . '</p></div></div>';
        return;
    }

    $notice = seo_comentarista_admin_handle_action();
    $edit_id = absint($_GET['edit'] ?? 0);
    $edit = $edit_id ? seo_comentarista_get($edit_id) : null;

    $defaults = array(
        'id'                  => 0,
        'product_id'          => 0,
        'content_type'        => 'comment',
        'source_platform'     => 'web',
        'source_name'         => '',
        'source_url'          => '',
        'external_id'         => '',
        'source_title'        => '',
        'author_name'         => '',
        'author_url'          => '',
        'source_published_at' => '',
        'captured_at'         => current_time('mysql'),
        'source_content'      => '',
        'editorial_summary'   => '',
        'rating_value'        => '',
        'rating_scale'        => '',
        'embed_url'           => '',
        'thumbnail_url'       => '',
        'status'              => 'published',
        'display_order'       => 0,
    );
    $row = array_merge($defaults, is_array($edit) ? $edit : array());

    global $wpdb;
    $items = seo_comentarista_table_exists()
        ? (array) $wpdb->get_results(
            'SELECT * FROM ' . seo_comentarista_table_name() . ' ORDER BY id DESC LIMIT 100',
            ARRAY_A
        )
        : array();

    $types = seo_comentarista_allowed_content_types();
    $statuses = seo_comentarista_allowed_statuses();
    ?>
    <div class="wrap">
        <h1>Comentarista</h1>
        <p>Gestiona manualmente comentarios, vídeos, publicaciones sociales y enlaces externos asociados a productos. El módulo no realiza scraping.</p>

        <?php if ($notice) : ?>
            <div class="notice notice-<?php echo esc_attr($notice[0]); ?> is-dismissible"><p><?php echo esc_html($notice[1]); ?></p></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:minmax(360px,620px) minmax(520px,1fr);gap:24px;align-items:start;">
            <div class="card" style="max-width:none;padding:20px;">
                <h2><?php echo $edit ? 'Editar registro' : 'Añadir contenido externo'; ?></h2>
                <form method="post">
                    <?php wp_nonce_field('seo_comentarista_save', 'seo_comentarista_nonce'); ?>
                    <input type="hidden" name="seo_comentarista_action" value="save">
                    <input type="hidden" name="comentarista_id" value="<?php echo esc_attr($row['id']); ?>">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th><label>Producto</label></th>
                            <td><?php seo_comentarista_admin_product_field($row['product_id']); ?></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-type">Tipo</label></th>
                            <td>
                                <select id="seo-comentarista-type" name="content_type">
                                    <?php foreach ($types as $key => $label) : ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($row['content_type'], $key); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Comentario se guarda como texto capturado. Vídeo/social se representa como medio o enlace según la plataforma.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-platform">Plataforma</label></th>
                            <td><input id="seo-comentarista-platform" type="text" class="regular-text" name="source_platform" value="<?php echo esc_attr($row['source_platform']); ?>" placeholder="web, youtube, tiktok, instagram, proveedor..."></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-source-name">Fuente</label></th>
                            <td><input id="seo-comentarista-source-name" type="text" class="regular-text" name="source_name" value="<?php echo esc_attr($row['source_name']); ?>" placeholder="VEVOR, YouTube, Proveedor X..."></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-url">URL de origen</label></th>
                            <td>
                                <input id="seo-comentarista-url" type="url" class="large-text" name="source_url" value="<?php echo esc_attr($row['source_url']); ?>">
                                <p class="description">Opcional para un comentario capturado; obligatoria para vídeos, publicaciones sociales, artículos y enlaces.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-external-id">ID externo</label></th>
                            <td><input id="seo-comentarista-external-id" type="text" class="regular-text" name="external_id" value="<?php echo esc_attr($row['external_id']); ?>"><p class="description">ID del vídeo/post/comentario si existe. YouTube/TikTok/Instagram se detectan automáticamente desde la URL cuando es posible.</p></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-title">Título externo</label></th>
                            <td><input id="seo-comentarista-title" type="text" class="large-text" name="source_title" value="<?php echo esc_attr($row['source_title']); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-author">Autor</label></th>
                            <td><input id="seo-comentarista-author" type="text" class="regular-text" name="author_name" value="<?php echo esc_attr($row['author_name']); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-author-url">URL autor</label></th>
                            <td><input id="seo-comentarista-author-url" type="url" class="large-text" name="author_url" value="<?php echo esc_attr($row['author_url']); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-published">Fecha original</label></th>
                            <td><input id="seo-comentarista-published" type="datetime-local" name="source_published_at" value="<?php echo esc_attr(seo_comentarista_admin_datetime_value($row['source_published_at'])); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-captured">Fecha captura</label></th>
                            <td><input id="seo-comentarista-captured" type="datetime-local" name="captured_at" value="<?php echo esc_attr(seo_comentarista_admin_datetime_value($row['captured_at'])); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-content">Contenido capturado</label></th>
                            <td><textarea id="seo-comentarista-content" class="large-text" rows="7" name="source_content"><?php echo esc_textarea($row['source_content']); ?></textarea><p class="description">Para comentarios/reseñas: guarda la evidencia textual que has seleccionado. No es necesario copiar la página completa.</p></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-summary">Resumen editorial</label></th>
                            <td><textarea id="seo-comentarista-summary" class="large-text" rows="4" name="editorial_summary"><?php echo esc_textarea($row['editorial_summary']); ?></textarea></td>
                        </tr>
                        <tr>
                            <th>Valoración</th>
                            <td><input type="number" step="0.01" min="0" name="rating_value" value="<?php echo esc_attr($row['rating_value']); ?>" style="width:90px;"> de <input type="number" step="0.01" min="0.01" name="rating_scale" value="<?php echo esc_attr($row['rating_scale']); ?>" style="width:90px;" placeholder="5"></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-embed">URL embed</label></th>
                            <td><input id="seo-comentarista-embed" type="url" class="large-text" name="embed_url" value="<?php echo esc_attr($row['embed_url']); ?>"><p class="description">Opcional. Se genera automáticamente para URLs reconocidas cuando es posible.</p></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-thumb">Miniatura</label></th>
                            <td><input id="seo-comentarista-thumb" type="url" class="large-text" name="thumbnail_url" value="<?php echo esc_attr($row['thumbnail_url']); ?>"></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-status">Estado</label></th>
                            <td><select id="seo-comentarista-status" name="status"><?php foreach ($statuses as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($row['status'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td>
                        </tr>
                        <tr>
                            <th><label for="seo-comentarista-order">Orden</label></th>
                            <td><input id="seo-comentarista-order" type="number" name="display_order" value="<?php echo esc_attr($row['display_order']); ?>"></td>
                        </tr>
                    </table>

                    <?php submit_button($edit ? 'Actualizar' : 'Guardar'); ?>
                    <?php if ($edit) : ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seo-comentarista')); ?>">Cancelar</a><?php endif; ?>
                </form>
            </div>

            <div class="card" style="max-width:none;padding:20px;">
                <h2>Últimos registros</h2>
                <?php if (!$items) : ?>
                    <p>No hay contenido externo registrado.</p>
                <?php else : ?>
                    <div style="overflow:auto;">
                        <table class="widefat striped">
                            <thead><tr><th>ID</th><th>Producto</th><th>Tipo</th><th>Fuente</th><th>Autor / título</th><th>Estado</th><th>Acciones</th></tr></thead>
                            <tbody>
                            <?php foreach ($items as $item) :
                                $product_title = get_the_title((int) $item['product_id']);
                                $edit_url = add_query_arg(array('page' => 'seo-comentarista', 'edit' => (int) $item['id']), admin_url('admin.php'));
                                $delete_url = wp_nonce_url(
                                    add_query_arg(array('page' => 'seo-comentarista', 'seo_comentarista_delete' => (int) $item['id']), admin_url('admin.php')),
                                    'seo_comentarista_delete_' . (int) $item['id']
                                );
                                ?>
                                <tr>
                                    <td><?php echo esc_html($item['id']); ?></td>
                                    <td>#<?php echo esc_html($item['product_id']); ?> <?php echo esc_html($product_title); ?></td>
                                    <td><?php echo esc_html($types[$item['content_type']] ?? $item['content_type']); ?></td>
                                    <td><?php echo esc_html($item['source_name'] ?: $item['source_platform']); ?></td>
                                    <td><?php echo esc_html($item['author_name'] ?: $item['source_title']); ?></td>
                                    <td><?php echo esc_html($statuses[$item['status']] ?? $item['status']); ?></td>
                                    <td><a href="<?php echo esc_url($edit_url); ?>">Editar</a> · <a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('¿Eliminar este registro?');">Eliminar</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}
