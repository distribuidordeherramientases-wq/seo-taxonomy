<?php
if (!defined('ABSPATH')) exit;

/**
 * Resuelve la jerarquía SEO de una categoría para reconstruir
 * correctamente los filtros después de mover productos.
 *
 * @param int $category_id ID de product_cat.
 * @return array|WP_Error
 */
function seo_recategorization_get_category_context($category_id) {

    $category_id = absint($category_id);
    $category = get_term($category_id, 'product_cat');

    if (!$category_id || !$category || is_wp_error($category)) {
        return new WP_Error(
            'seo_recategorization_invalid_category',
            'La categoría de destino no es válida.'
        );
    }

    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';

    $hub_secondary_id = absint($wpdb->get_var($wpdb->prepare(
        "SELECT source_id
         FROM {$relations_table}
         WHERE target_id = %d
           AND relation_type = 'hub_secondary_to_category'
         LIMIT 1",
        $category_id
    )));

    $hub_primary_id = 0;
    $cluster_id = 0;

    if ($hub_secondary_id) {
        $hub_primary_id = absint($wpdb->get_var($wpdb->prepare(
            "SELECT source_id
             FROM {$relations_table}
             WHERE target_id = %d
               AND relation_type = 'hub_primary_to_hub_secondary'
             LIMIT 1",
            $hub_secondary_id
        )));
    }

    if ($hub_primary_id) {
        $cluster_id = absint($wpdb->get_var($wpdb->prepare(
            "SELECT source_id
             FROM {$relations_table}
             WHERE target_id = %d
               AND relation_type = 'cluster_to_primary'
             LIMIT 1",
            $hub_primary_id
        )));
    }

    return [
        'cluster'        => $cluster_id,
        'hub_primario'   => $hub_primary_id,
        'hub_secundario' => $hub_secondary_id,
        'cat'            => $category_id,
    ];
}


/**
 * Normaliza una lista de IDs de productos.
 *
 * Admite:
 * - array de IDs
 * - cadena separada por comas
 * - espacios
 * - punto y coma
 * - saltos de línea
 *
 * @param array|string $product_ids
 * @return int[]
 */
function seo_recategorization_normalize_product_ids($product_ids) {

    if (is_string($product_ids)) {
        $product_ids = preg_split(
            '/[\s,;]+/',
            $product_ids,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
    }

    if (!is_array($product_ids)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map(
        'absint',
        $product_ids
    ))));
}

/**
 * Sustituye las categorías actuales de varios productos por una categoría
 * de producto de destino.
 *
 * Esta es la función modular que puede llamarse desde formularios, AJAX,
 * importadores, WP-CLI u otras partes del plugin.
 *
 * Ejemplos:
 *
 * seo_move_products_to_category([10, 20, 30], 450);
 * seo_move_products_to_category('10,20,30', 450);
 *
 * @param array|string $product_ids       IDs de productos.
 * @param int          $target_category   term_id de product_cat.
 * @return array|WP_Error
 */
function seo_move_products_to_category($product_ids, $target_category) {

    $product_ids = seo_recategorization_normalize_product_ids($product_ids);
    $target_category = absint($target_category);

    if (empty($product_ids)) {
        return new WP_Error(
            'seo_recategorization_no_products',
            'No se ha indicado ningún ID de producto válido.'
        );
    }

    $target_term = get_term($target_category, 'product_cat');

    if (
        !$target_category ||
        !$target_term ||
        is_wp_error($target_term)
    ) {
        return new WP_Error(
            'seo_recategorization_invalid_target',
            'La categoría de destino no es válida.'
        );
    }

    $moved = [];
    $failed = [];
    $source_category_ids = [];

    foreach ($product_ids as $product_id) {

        $product = get_post($product_id);

        if (!$product || $product->post_type !== 'product') {
            $failed[$product_id] = 'El ID no corresponde a un producto.';
            continue;
        }

        $current_categories = wp_get_object_terms(
            $product_id,
            'product_cat',
            ['fields' => 'ids']
        );

        if (!is_wp_error($current_categories)) {
            $source_category_ids = array_merge(
                $source_category_ids,
                array_map('absint', $current_categories)
            );
        }

        /*
         * false = sustituir todas las categorías actuales por la categoría
         * de destino.
         */
        $result = wp_set_object_terms(
            $product_id,
            [$target_category],
            'product_cat',
            false
        );

        if (is_wp_error($result)) {
            $failed[$product_id] = $result->get_error_message();
            continue;
        }

        clean_object_term_cache($product_id, 'product');
        clean_post_cache($product_id);

        $moved[] = $product_id;
    }

    $terms_to_clean = array_values(array_unique(array_filter(array_merge(
        $source_category_ids,
        [$target_category]
    ))));

    if ($terms_to_clean) {
        clean_term_cache($terms_to_clean, 'product_cat');
    }

    return [
        'success'             => empty($failed),
        'target_category_id'  => $target_category,
        'target_category'     => $target_term->name,
        'requested'           => count($product_ids),
        'moved_count'         => count($moved),
        'failed_count'        => count($failed),
        'moved_ids'           => $moved,
        'failed'              => $failed,
    ];
}

function product_recategorization() {

    // Seguridad básica de acceso
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;

    // Tabla de relaciones SEO
    $relations_table = $wpdb->prefix . 'seo_relations';

    // Leer filtros desde GET o POST
    $cluster = isset($_REQUEST['cluster']) ? intval($_REQUEST['cluster']) : 0;
    $hub_primario = isset($_REQUEST['hub_primario']) ? intval($_REQUEST['hub_primario']) : 0;
    $hub_secundario = isset($_REQUEST['hub_secundario']) ? intval($_REQUEST['hub_secundario']) : 0;
    $cat = isset($_REQUEST['cat']) ? intval($_REQUEST['cat']) : 0;

    // Leer búsqueda de producto
    $product_search = isset($_REQUEST['product_search'])
        ? sanitize_text_field($_REQUEST['product_search'])
        : '';

    // Página y pestaña actual del admin
    $current_page = isset($_REQUEST['page'])
        ? sanitize_text_field($_REQUEST['page'])
        : 'product-page-admin';

    $current_tab = isset($_REQUEST['tab'])
        ? sanitize_text_field($_REQUEST['tab'])
        : 'duplicados';

    // Movimiento directo mediante IDs de productos e ID de categoría.
    if (isset($_POST['move_products_by_ids'])) {

        check_admin_referer(
            'seo_move_products_by_ids',
            'seo_move_products_by_ids_nonce'
        );

        $raw_product_ids = isset($_POST['direct_product_ids'])
            ? sanitize_textarea_field(
                wp_unslash($_POST['direct_product_ids'])
            )
            : '';

        $target_category = isset($_POST['direct_target_category_id'])
            ? absint($_POST['direct_target_category_id'])
            : 0;

        $move_result = seo_move_products_to_category(
            $raw_product_ids,
            $target_category
        );

        if (is_wp_error($move_result)) {
            wp_die(esc_html($move_result->get_error_message()));
        }

        $destination_context = seo_recategorization_get_category_context(
            $target_category
        );

        $redirect_args = [
            'page'             => $current_page,
            'tab'              => $current_tab,
            'cat'              => $target_category,
            'moved_products'   => absint($move_result['moved_count']),
            'move_errors'      => absint($move_result['failed_count']),
            'recategorized_at' => time(),
        ];

        if (!empty($move_result['failed'])) {
            $redirect_args['invalid_product_ids'] = implode(
                ',',
                array_keys($move_result['failed'])
            );
        }

        if (!is_wp_error($destination_context)) {
            $redirect_args = array_merge(
                $redirect_args,
                $destination_context
            );
        }

        wp_safe_redirect(
            add_query_arg(
                $redirect_args,
                admin_url('admin.php')
            )
        );

        exit;
    }

    // Procesar el movimiento antes de generar cualquier HTML.
    if (isset($_POST['move_selected_products'])) {

        check_admin_referer(
            'seo_move_selected_products',
            'seo_move_products_nonce'
        );

        $selected_products = isset($_POST['selected_products'])
            ? array_filter(array_map(
                'absint',
                (array) wp_unslash($_POST['selected_products'])
            ))
            : [];

        $target_category = isset($_POST['target_category'])
            ? absint($_POST['target_category'])
            : 0;

        $move_result = seo_move_products_to_category(
            $selected_products,
            $target_category
        );

        if (is_wp_error($move_result)) {
            wp_die(esc_html($move_result->get_error_message()));
        }

        $moved_products = absint($move_result['moved_count']);
        $move_errors = absint($move_result['failed_count']);

        /*
         * La categoría destino puede pertenecer a otra jerarquía. No se deben
         * conservar el cluster y los hubs de la categoría de origen.
         */
        $destination_context = seo_recategorization_get_category_context(
            $target_category
        );

        $redirect_args = [
            'page'              => $current_page,
            'tab'               => $current_tab,
            'cat'               => $target_category,
            'moved_products'    => $moved_products,
            'move_errors'       => $move_errors,
            'recategorized_at'  => time(),
        ];

        if (!is_wp_error($destination_context)) {
            $redirect_args = array_merge(
                $redirect_args,
                $destination_context
            );
        }

        wp_safe_redirect(
            add_query_arg(
                $redirect_args,
                admin_url('admin.php')
            )
        );

        exit;
    }

    // Obtener clusters disponibles
    $cluster_ids = $wpdb->get_col("
        SELECT DISTINCT source_id
        FROM {$relations_table}
        WHERE source_type = 'cluster'
        AND source_id > 0
    ");

    // Obtener hubs primarios del cluster seleccionado
    $hub_primarios_ids = [];

    if ($cluster > 0) {
        $hub_primarios_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$relations_table}
                 WHERE source_id = %d
                 AND relation_type = 'cluster_to_primary'",
                $cluster
            )
        );
    }

    // Obtener hubs secundarios del hub primario seleccionado
    $hub_secundarios_ids = [];

    if ($hub_primario > 0) {
        $hub_secundarios_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$relations_table}
                 WHERE source_id = %d
                 AND relation_type = 'hub_primary_to_hub_secondary'",
                $hub_primario
            )
        );
    }

    // Obtener categorías del hub secundario seleccionado
    $category_ids_from_db = [];

    if ($hub_secundario > 0) {
        $category_ids_from_db = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT target_id
                 FROM {$relations_table}
                 WHERE source_id = %d
                 AND relation_type = 'hub_secondary_to_category'",
                $hub_secundario
            )
        );
    }

    // Obtener todas las categorías WooCommerce
    $all_cats = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false
    ]);

    // Aviso verificable después de mover productos.
    if (isset($_GET['moved_products'])) {
        $moved_products = absint($_GET['moved_products']);
        $move_errors = isset($_GET['move_errors'])
            ? absint($_GET['move_errors'])
            : 0;

        if ($moved_products > 0) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo 'Productos movidos correctamente: <strong>' .
                intval($moved_products) .
                '</strong>';
            echo '</p></div>';
        }

        if ($move_errors > 0) {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo 'Productos que no se pudieron mover: <strong>' .
                intval($move_errors) .
                '</strong>';
            echo '</p></div>';
        }

        if (!empty($_GET['invalid_product_ids'])) {
            $invalid_product_ids = sanitize_text_field(
                wp_unslash($_GET['invalid_product_ids'])
            );

            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo 'IDs que no se pudieron procesar: <strong>' .
                esc_html($invalid_product_ids) .
                '</strong>';
            echo '</p></div>';
        }
    }

    // Formulario directo por IDs
    ?>
    <div style="margin-bottom:30px;padding:20px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:6px;">
        <h2 style="margin-top:0;">Movimiento directo por IDs</h2>

        <p>
            Introduce los IDs de productos separados por comas, espacios,
            punto y coma o saltos de línea, y el ID de la categoría destino.
        </p>

        <form method="POST" id="seo-direct-category-move-form">
            <?php
            wp_nonce_field(
                'seo_move_products_by_ids',
                'seo_move_products_by_ids_nonce'
            );
            ?>

            <input type="hidden" name="page" value="<?php echo esc_attr($current_page); ?>">
            <input type="hidden" name="tab" value="<?php echo esc_attr($current_tab); ?>">

            <div style="display:flex;gap:15px;flex-wrap:wrap;align-items:flex-end;">

                <div>
                    <label for="direct_product_ids" style="display:block;font-weight:600;margin-bottom:6px;">
                        IDs de productos
                    </label>

                    <textarea
                        id="direct_product_ids"
                        name="direct_product_ids"
                        rows="3"
                        cols="45"
                        placeholder="Ejemplo: 123, 456, 789"
                        required
                    ></textarea>
                </div>

                <div>
                    <label for="direct_target_category_id" style="display:block;font-weight:600;margin-bottom:6px;">
                        ID categoría destino
                    </label>

                    <input
                        type="number"
                        id="direct_target_category_id"
                        name="direct_target_category_id"
                        min="1"
                        step="1"
                        placeholder="Ejemplo: 40112"
                        required
                    >
                </div>

                <div>
                    <button
                        type="submit"
                        name="move_products_by_ids"
                        value="1"
                        class="button button-primary"
                    >
                        Mover productos de categoría
                    </button>
                </div>

            </div>
        </form>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('seo-direct-category-move-form');

        if (!form) {
            return;
        }

        form.addEventListener('submit', function (event) {
            const rawIds = document
                .getElementById('direct_product_ids')
                .value
                .trim();

            const categoryId = document
                .getElementById('direct_target_category_id')
                .value
                .trim();

            const ids = rawIds
                .split(/[\s,;]+/)
                .filter(Boolean);

            if (!ids.length || !categoryId) {
                return;
            }

            const confirmed = window.confirm(
                'Se moverán ' + ids.length +
                ' productos a la categoría ID ' + categoryId + '.\n\n' +
                'Las categorías actuales serán sustituidas.\n\n' +
                '¿Quieres continuar?'
            );

            if (!confirmed) {
                event.preventDefault();
            }
        });
    });
    </script>
    <?php

    // Formulario de filtros
    ?>
    <form method="GET" style="margin-bottom:30px;padding:20px;background:#f6f7f7;border:1px solid #c3c4c7;border-radius:6px;">

        <input type="hidden" name="page" value="<?php echo esc_attr($current_page); ?>">
        <input type="hidden" name="tab" value="<?php echo esc_attr($current_tab); ?>">

        <div style="display:flex;gap:15px;flex-wrap:wrap;align-items:center;">

            <select name="cluster" onchange="this.form.submit()">
                <option value="0">Cluster</option>

                <?php foreach ($cluster_ids as $id): ?>
                    <?php $post = get_post($id); ?>

                    <option value="<?php echo intval($id); ?>" <?php selected($cluster, $id); ?>>
                        <?php echo esc_html($post ? $post->post_title : 'Cluster ' . $id); ?>
                    </option>

                <?php endforeach; ?>
            </select>

            <select name="hub_primario" onchange="this.form.submit()">
                <option value="0">Hub primario</option>

                <?php foreach ($hub_primarios_ids as $id): ?>
                    <?php $post = get_post($id); ?>

                    <option value="<?php echo intval($id); ?>" <?php selected($hub_primario, $id); ?>>
                        <?php echo esc_html($post ? $post->post_title : 'HP ' . $id); ?>
                    </option>

                <?php endforeach; ?>
            </select>

            <select name="hub_secundario" onchange="this.form.submit()">
                <option value="0">Hub secundario</option>

                <?php foreach ($hub_secundarios_ids as $id): ?>
                    <?php $post = get_post($id); ?>

                    <option value="<?php echo intval($id); ?>" <?php selected($hub_secundario, $id); ?>>
                        <?php echo esc_html($post ? $post->post_title : 'HS ' . $id); ?>
                    </option>

                <?php endforeach; ?>
            </select>

            <select name="cat" onchange="this.form.submit()">
                <option value="0">Categoría</option>

                <?php foreach ($all_cats as $c): ?>

                    <?php if (empty($category_ids_from_db) || in_array($c->term_id, $category_ids_from_db)): ?>

                        <option value="<?php echo intval($c->term_id); ?>" <?php selected($cat, $c->term_id); ?>>
                            <?php echo esc_html($c->name); ?>
                        </option>

                    <?php endif; ?>

                <?php endforeach; ?>
            </select>

            <input
                type="text"
                name="product_search"
                value="<?php echo esc_attr($product_search); ?>"
                placeholder="Buscar producto..."
            >

            <input
                type="submit"
                class="button button-primary"
                value="Buscar"
            >

        </div>
    </form>
    <?php

    // No cargar productos si no hay categoría ni búsqueda
    if ($cat <= 0 && empty($product_search)) {
        echo '<p>Selecciona una categoría o busca un producto.</p>';
        return;
    }

    // Preparar consulta de productos
    $args = [
        'post_type'              => 'product',
        'post_status'            => ['publish', 'draft', 'private'],
        'posts_per_page'         => -1,
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'cache_results'          => false,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ];

    // Filtrar por categoría seleccionada
    if ($cat > 0) {
        $args['tax_query'] = [
            [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => $cat,
                /*
                 * Fundamental para recategorizar: no mostrar productos de
                 * categorías hijas como si pertenecieran directamente a esta.
                 */
                'include_children' => false,
            ]
        ];
    }

    // Filtrar por búsqueda de producto
    if (!empty($product_search)) {
        $args['s'] = $product_search;
    }

    // Cargar productos
    $products = get_posts($args);

    echo '<h2>Productos de la categoría</h2>';

    if (empty($products)) {
        echo '<p>No hay productos.</p>';
        return;
    }

    // Formulario para mover productos seleccionados
    echo '<form method="POST">';
    wp_nonce_field(
        'seo_move_selected_products',
        'seo_move_products_nonce'
    );

    // Mantener filtros después del POST
    echo '<input type="hidden" name="page" value="' . esc_attr($current_page) . '">';
    echo '<input type="hidden" name="tab" value="' . esc_attr($current_tab) . '">';
    echo '<input type="hidden" name="cluster" value="' . intval($cluster) . '">';
    echo '<input type="hidden" name="hub_primario" value="' . intval($hub_primario) . '">';
    echo '<input type="hidden" name="hub_secundario" value="' . intval($hub_secundario) . '">';
    echo '<input type="hidden" name="cat" value="' . intval($cat) . '">';
    echo '<input type="hidden" name="product_search" value="' . esc_attr($product_search) . '">';

    // Tabla de productos
    echo '<table class="widefat striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th width="80">ID</th>';
    echo '<th>Producto</th>';
    echo '<th width="80">Mover</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ($products as $product) {

        echo '<tr>';

        echo '<td>' . intval($product->ID) . '</td>';

        echo '<td>';
        echo esc_html($product->post_title);
        echo '<div style="margin-top:8px;">';
        echo '<a href="' . esc_url(get_edit_post_link($product->ID)) . '" class="button button-small">Ver producto</a>';
        echo '</div>';
        echo '</td>';

        echo '<td>';
        echo '<input type="checkbox" name="selected_products[]" value="' . intval($product->ID) . '">';
        echo '</td>';

        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '<br>';

    // Selector de categoría destino
    echo '<select name="target_category">';
    echo '<option value="0">Mover a categoría...</option>';

    foreach ($all_cats as $c) {
        echo '<option value="' . intval($c->term_id) . '">';
        echo esc_html($c->name);
        echo '</option>';
    }

    echo '</select>';

    echo ' ';

    // Botón para mover seleccionados
    echo '<input type="submit" name="move_selected_products" class="button button-primary" value="Mover seleccionados">';

    echo '</form>';
}