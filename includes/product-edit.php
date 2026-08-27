<?php
/**
 * Selector y edicion unitaria de productos.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_page_edit_products')) {
    function seo_page_edit_products() {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            return;
        }

        $product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
        if ($product_id > 0) {
            echo '<div style="max-width:1180px;padding:10px 0 30px;">';
            echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:15px;">';
            echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'product-page-admin', 'tab' => 'editar'], admin_url('admin.php'))) . '">← Volver a selección</a>';
            echo '<h1 style="margin:0;">Editar producto</h1>';
            echo '</div>';

            if (function_exists('seo_render_product_form')) {
                seo_render_product_form($product_id);
            } else {
                echo '<div class="notice notice-error"><p>No está disponible el formulario compartido de producto.</p></div>';
            }

            echo '</div>';
            return;
        }

        $search = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
        $category_id = isset($_GET['cat']) ? absint($_GET['cat']) : 0;
        $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $provider = isset($_GET['provider']) ? sanitize_text_field(wp_unslash($_GET['provider'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 40;

        $allowed_statuses = ['publish', 'draft', 'pending', 'private'];
        if ($status !== '' && !in_array($status, $allowed_statuses, true)) {
            $status = '';
        }

        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);
        if (is_wp_error($categories)) {
            $categories = [];
        }

        $providers = function_exists('seo_product_get_provider_suggestions')
            ? seo_product_get_provider_suggestions()
            : [];

        $args = [
            'post_type'      => 'product',
            'post_status'    => $status !== '' ? [$status] : $allowed_statuses,
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ];

        if ($category_id > 0) {
            $args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => [$category_id],
            ]];
        }

        if ($provider !== '') {
            $args['meta_query'] = [[
                'key'   => '_seo_proveedor',
                'value' => $provider,
            ]];
        }

        $forced_ids = [];
        if ($search !== '') {
            if (ctype_digit($search) && get_post_type(absint($search)) === 'product') {
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
                    "SELECT ID
                     FROM {$wpdb->posts}
                     WHERE post_type = 'product'
                       AND post_title LIKE %s
                     ORDER BY post_modified DESC
                     LIMIT 100",
                    '%' . $wpdb->esc_like($search) . '%'
                )
            );

            $sku_like_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT post_id
                     FROM {$wpdb->postmeta}
                     WHERE meta_key = '_sku'
                       AND meta_value LIKE %s
                     LIMIT 100",
                    '%' . $wpdb->esc_like($search) . '%'
                )
            );

            $forced_ids = array_values(array_unique(array_filter(array_map('absint', array_merge($forced_ids, $title_ids, $sku_like_ids)))));
            $args['post__in'] = !empty($forced_ids) ? $forced_ids : [0];
        }

        $query = new WP_Query($args);

        echo '<div style="padding:10px 0 30px;max-width:1280px;">';
        echo '<h1 style="margin-bottom:8px;">Editar productos</h1>';
        echo '<p style="margin-top:0;color:#646970;">Selecciona primero un producto. La edición se realiza de forma unitaria para evitar dobles escrituras.</p>';
        ?>

        <form method="get" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px;margin:18px 0;display:grid;grid-template-columns:2fr 1.2fr 1fr 1.2fr auto;gap:12px;align-items:end;">
            <input type="hidden" name="page" value="product-page-admin">
            <input type="hidden" name="tab" value="editar">

            <div>
                <label style="display:block;font-weight:600;margin-bottom:5px;">Buscar</label>
                <input type="text" name="q" value="<?php echo esc_attr($search); ?>" placeholder="Título, SKU o ID" style="width:100%;">
            </div>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:5px;">Categoría</label>
                <select name="cat" style="width:100%;">
                    <option value="">Todas</option>
                    <?php seo_product_category_option_tree((array) $categories, 0, $category_id ? [$category_id] : []); ?>
                </select>
            </div>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:5px;">Estado</label>
                <select name="status" style="width:100%;">
                    <option value="">Todos</option>
                    <option value="publish" <?php selected($status, 'publish'); ?>>Publicado</option>
                    <option value="draft" <?php selected($status, 'draft'); ?>>Borrador</option>
                    <option value="pending" <?php selected($status, 'pending'); ?>>Pendiente</option>
                    <option value="private" <?php selected($status, 'private'); ?>>Privado</option>
                </select>
            </div>

            <div>
                <label style="display:block;font-weight:600;margin-bottom:5px;">Proveedor</label>
                <select name="provider" style="width:100%;">
                    <option value="">Todos</option>
                    <?php foreach ($providers as $provider_option): ?>
                        <option value="<?php echo esc_attr($provider_option); ?>" <?php selected($provider, $provider_option); ?>><?php echo esc_html($provider_option); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex;gap:6px;">
                <button class="button button-primary" type="submit">Filtrar</button>
                <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'product-page-admin', 'tab' => 'editar'], admin_url('admin.php'))); ?>">Limpiar</a>
            </div>
        </form>

        <table class="widefat striped" style="margin-top:15px;">
            <thead>
                <tr>
                    <th style="width:70px;">ID</th>
                    <th style="width:160px;">SKU</th>
                    <th>Producto</th>
                    <th style="width:180px;">Proveedor</th>
                    <th style="width:120px;">Estado</th>
                    <th style="width:90px;">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$query->have_posts()): ?>
                    <tr><td colspan="6">No se han encontrado productos con estos filtros.</td></tr>
                <?php else: ?>
                    <?php foreach ($query->posts as $post): ?>
                        <?php
                        $wc_product = wc_get_product($post->ID);
                        $sku = $wc_product ? $wc_product->get_sku('edit') : '';
                        $row_provider = (string) get_post_meta($post->ID, '_seo_proveedor', true);
                        $edit_url = add_query_arg(
                            [
                                'page'       => 'product-page-admin',
                                'tab'        => 'editar',
                                'product_id' => $post->ID,
                            ],
                            admin_url('admin.php')
                        );
                        ?>
                        <tr>
                            <td><?php echo absint($post->ID); ?></td>
                            <td><code><?php echo esc_html($sku ?: '—'); ?></code></td>
                            <td><strong><?php echo esc_html($post->post_title); ?></strong></td>
                            <td><?php echo esc_html($row_provider ?: '—'); ?></td>
                            <td><?php echo esc_html($post->post_status); ?></td>
                            <td><a class="button button-small" href="<?php echo esc_url($edit_url); ?>">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        if ($query->max_num_pages > 1) {
            $base_args = [
                'page'     => 'product-page-admin',
                'tab'      => 'editar',
                'q'        => $search,
                'cat'      => $category_id,
                'status'   => $status,
                'provider' => $provider,
                'paged'    => '%#%',
            ];

            echo '<div style="margin-top:18px;">';
            echo wp_kses_post(
                paginate_links([
                    'base'      => esc_url_raw(add_query_arg($base_args, admin_url('admin.php'))),
                    'format'    => '',
                    'current'   => $paged,
                    'total'     => max(1, (int) $query->max_num_pages),
                    'type'      => 'list',
                    'prev_text' => '«',
                    'next_text' => '»',
                ])
            );
            echo '</div>';
        }

        wp_reset_postdata();
        echo '</div>';
    }
}
