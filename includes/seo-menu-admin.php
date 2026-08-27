<?php
/*
Plugin Name: SEO Menu Manager
Description: Genera y sincroniza menús SEO desde base de datos.
Version: 1.0.1
*/

if (!defined('ABSPATH')) exit;

/*************************************************
 * ADMIN PAGE
 *************************************************/
function seo_menu_manager_page() {

    if (!current_user_can('manage_options')) return;

    echo '<div class="wrap">';
    echo '<h1>SEO Menu Structure</h1>';

    $created_menu_id = (int) get_option('seo_menu_created_id');
    $created_date    = get_option('seo_menu_created_date');
    $show_preview    = false;
    $preview_include_solutions = (int) get_option('seo_menu_include_solutions', 0);

    echo '<p><strong>Estado menú:</strong> ';

    if ($created_menu_id && wp_get_nav_menu_object($created_menu_id)) {
        echo 'Creado (ID: ' . intval($created_menu_id) . ')';
        if ($created_date) {
            echo ' · ' . esc_html($created_date);
        }
    } else {
        echo 'No creado';
    }

    echo '</p>';

    /**********************
     * ACTIONS
     **********************/
    if (isset($_POST['seo_action'])) {

        check_admin_referer(
            'seo_menu_manager_action',
            'seo_menu_manager_nonce'
        );

        $action = sanitize_key(wp_unslash($_POST['seo_action']));
        $posted_include_solutions = isset($_POST['seo_include_solutions']) ? 1 : 0;

        /*
         * PREVISUALIZAR
         * No crea, no sincroniza y no activa ningún menú.
         */
        if ($action === 'preview_menu') {
            $preview_include_solutions = $posted_include_solutions;
            $show_preview = true;

            echo '<div class="notice notice-info"><p>';
            echo 'Previsualización generada. No se ha modificado ningún menú de WordPress.';
            echo '</p></div>';
        }

        /*
         * PUBLICAR
         * Solo se ejecuta tras pulsar expresamente "Publicar y activar".
         */
        if ($action === 'publish_menu') {

            $include_solutions = $posted_include_solutions;
            $preview_include_solutions = $include_solutions;
            update_option('seo_menu_include_solutions', $include_solutions, false);

            $menu_id = seo_menu_get_or_create_menu();

            if (is_wp_error($menu_id)) {
                echo '<div class="notice notice-error"><p>' .
                    esc_html($menu_id->get_error_message()) .
                    '</p></div>';
            } else {
                $tree = seo_build_tree_from_db();

                $sync_result = seo_tree_to_wp_menu(
                    $tree,
                    $menu_id,
                    $include_solutions
                );

                if (is_wp_error($sync_result)) {
                    echo '<div class="notice notice-error"><p>' .
                        esc_html($sync_result->get_error_message()) .
                        '</p></div>';
                } else {
                    $activation = seo_menu_activate($menu_id);

                    if (is_wp_error($activation)) {
                        echo '<div class="notice notice-warning"><p>';
                        echo 'El menú se creó y sincronizó, pero no pudo activarse: ';
                        echo esc_html($activation->get_error_message());
                        echo '</p></div>';
                    } else {
                        echo '<div class="notice notice-success"><p>';
                        echo 'Menú publicado y activado correctamente.';
                        echo '</p></div>';
                    }
                }
            }

            $show_preview = true;
            $created_menu_id = (int) get_option('seo_menu_created_id');
        }

        /*
         * SINCRONIZAR MENÚ YA CREADO
         * No cambia la ubicación activa por sí solo.
         */
        if ($action === 'sync_menu') {

            $include_solutions = $posted_include_solutions;
            $preview_include_solutions = $include_solutions;
            update_option('seo_menu_include_solutions', $include_solutions, false);

            $menu_id = seo_menu_get_or_create_menu();

            if (is_wp_error($menu_id)) {
                echo '<div class="notice notice-error"><p>' .
                    esc_html($menu_id->get_error_message()) .
                    '</p></div>';
            } else {
                $tree = seo_build_tree_from_db();

                $result = seo_tree_to_wp_menu(
                    $tree,
                    $menu_id,
                    $include_solutions
                );

                if (is_wp_error($result)) {
                    echo '<div class="notice notice-error"><p>' .
                        esc_html($result->get_error_message()) .
                        '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>';
                    echo 'Menú sincronizado con la estructura SEO. No se ha cambiado su activación.';
                    echo '</p></div>';
                }
            }

            $show_preview = true;
        }

        if ($action === 'activate_seo') {

            $menu_id = (int) get_option('seo_menu_created_id');

            if ($menu_id <= 0 || !wp_get_nav_menu_object($menu_id)) {
                echo '<div class="notice notice-error"><p>';
                echo 'Primero debes publicar el menú SEO.';
                echo '</p></div>';
            } else {
                $result = seo_menu_activate($menu_id);

                if (is_wp_error($result)) {
                    echo '<div class="notice notice-error"><p>' .
                        esc_html($result->get_error_message()) .
                        '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>';
                    echo 'Menú SEO activado correctamente.';
                    echo '</p></div>';
                }
            }
        }

        if ($action === 'restore_wp') {

            $result = seo_menu_restore_original_primary();

            if (is_wp_error($result)) {
                echo '<div class="notice notice-error"><p>' .
                    esc_html($result->get_error_message()) .
                    '</p></div>';
            } else {
                echo '<div class="notice notice-success"><p>';
                echo 'Se ha restaurado el menú anterior.';
                echo '</p></div>';
            }
        }
    }

    /*************************************************
     * FORMULARIO DE PREVISUALIZACIÓN
     *************************************************/
    echo '<form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:20px;">';

    wp_nonce_field(
        'seo_menu_manager_action',
        'seo_menu_manager_nonce'
    );

    echo '<label style="display:inline-flex;align-items:center;gap:6px;margin-right:8px;">';
    echo '<input type="checkbox" name="seo_include_solutions" value="1" ' .
        checked($preview_include_solutions, 1, false) . '>';
    echo '<strong>Incluir Soluciones</strong>';
    echo '</label>';

    echo '<button class="button button-primary" name="seo_action" value="preview_menu">';
    echo 'Previsualizar menú';
    echo '</button>';

    if ($created_menu_id && wp_get_nav_menu_object($created_menu_id)) {
        echo '<button class="button" name="seo_action" value="sync_menu">';
        echo 'Sincronizar menú creado';
        echo '</button>';

        echo '<button class="button" name="seo_action" value="activate_seo">';
        echo 'Activar menú SEO';
        echo '</button>';
    }

    echo '<button class="button" name="seo_action" value="restore_wp">';
    echo 'Restaurar menú anterior';
    echo '</button>';

    echo '</form>';

    /*************************************************
     * PREVISUALIZACIÓN DE LA ESTRUCTURA
     *************************************************/
    if ($show_preview) {
        $tree = seo_build_tree_from_db();

        echo '<div style="max-width:1000px;margin:20px 0;padding:18px;background:#fff;border:1px solid #dcdcde;border-radius:6px;">';
        echo '<h2 style="margin-top:0;">Previsualización del menú</h2>';
        echo '<p style="color:#646970;">Todos los títulos siguientes son enlaces reales. Puedes abrirlos antes de publicar el menú.</p>';

        foreach ($tree as $cluster) {
            $cluster_id  = (int) ($cluster['id'] ?? 0);
            $cluster_url = $cluster_id > 0 ? get_permalink($cluster_id) : '';

            echo '<div style="margin:15px 0;padding:12px;border-left:4px solid #2271b1;">';
            echo '<h3 style="margin:0 0 8px;">';
            if ($cluster_url) {
                echo '<a href="' . esc_url($cluster_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($cluster['title']) . '</a>';
            } else {
                echo esc_html($cluster['title']) . ' <span style="color:#b32d2e;">(sin URL)</span>';
            }
            echo '</h3>';

            foreach (($cluster['children'] ?? []) as $primary) {
                $primary_id  = (int) ($primary['id'] ?? 0);
                $primary_url = $primary_id > 0 ? get_permalink($primary_id) : '';

                echo '<div style="margin:8px 0 8px 20px;">';
                if ($primary_url) {
                    echo '<strong><a href="' . esc_url($primary_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($primary['title']) . '</a></strong>';
                } else {
                    echo '<strong>' . esc_html($primary['title']) . '</strong> <span style="color:#b32d2e;">(sin URL)</span>';
                }

                foreach (($primary['children'] ?? []) as $secondary) {
                    $secondary_id  = (int) ($secondary['id'] ?? 0);
                    $secondary_url = $secondary_id > 0 ? get_permalink($secondary_id) : '';

                    echo '<div style="margin:5px 0 0 20px;color:#646970;">';
                    if ($secondary_url) {
                        echo '<a href="' . esc_url($secondary_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($secondary['title']) . '</a>';
                    } else {
                        echo esc_html($secondary['title']) . ' <span style="color:#b32d2e;">(sin URL)</span>';
                    }
                    echo '</div>';
                }

                echo '</div>';
            }

            echo '</div>';
        }

        if ($preview_include_solutions === 1) {
            $solutions = seo_menu_get_solutions();
            $solutions_page = get_page_by_path('soluciones', OBJECT, 'page');
            $solutions_url = $solutions_page instanceof WP_Post ? get_permalink($solutions_page->ID) : home_url('/soluciones/');

            echo '<div style="margin:15px 0;padding:12px;border-left:4px solid #46b450;">';
            echo '<h3 style="margin:0 0 6px;"><a href="' . esc_url($solutions_url) . '" target="_blank" rel="noopener noreferrer">Soluciones</a></h3>';
            echo '<p style="margin:0;color:#646970;">';
            echo 'Se añadirá un único enlace principal a Soluciones';
            if (!empty($solutions)) {
                echo ' (' . intval(count($solutions)) . ' landings activas y publicadas en la parrilla)';
            }
            echo '. Las landings no se añaden como submenú.</p>';
            echo '</div>';
        }

        echo '<form method="post" style="margin-top:20px;padding-top:16px;border-top:1px solid #dcdcde;">';
        wp_nonce_field('seo_menu_manager_action', 'seo_menu_manager_nonce');
        if ($preview_include_solutions === 1) {
            echo '<input type="hidden" name="seo_include_solutions" value="1">';
        }
        echo '<button class="button button-primary button-hero" name="seo_action" value="publish_menu" onclick="return confirm(\'¿Publicar y activar este menú? Sustituirá el menú principal actual, conservando una copia para poder restaurarlo.\');">';
        echo 'Publicar y activar este menú';
        echo '</button>';
        echo '<p style="margin:8px 0 0;color:#646970;">Hasta pulsar este botón, la previsualización no modifica el menú público.</p>';
        echo '</form>';

        echo '</div>';
    } else {
        echo '<p style="color:#646970;">Pulsa <strong>Previsualizar menú</strong> para revisar la estructura y comprobar todos los enlaces antes de publicarlo.</p>';
    }

    echo '</div>';
}


/*************************************************
 * TREE BUILDER
 *
 * ESTRUCTURA:
 *
 * Cluster
 *   → Hub primario
 *       → Hub secundario
 *
 * No incluye categorías.
 *************************************************/
function seo_build_tree_from_db() {

    global $wpdb;

    $nodes_table = $wpdb->prefix . 'seo_nodes';
    $rel_table   = $wpdb->prefix . 'seo_relations';

    $cluster_map = [];
    $primary_map = [];

    $cluster_to_primary = $wpdb->get_results("
        SELECT source_id, target_id
        FROM {$rel_table}
        WHERE relation_type = 'cluster_to_primary'
    ");

    $primary_to_secondary = $wpdb->get_results("
        SELECT source_id, target_id
        FROM {$rel_table}
        WHERE relation_type = 'hub_primary_to_hub_secondary'
    ");

    foreach ($cluster_to_primary as $r) {
        $cluster_map[(int) $r->source_id][] = (int) $r->target_id;
    }

    foreach ($primary_to_secondary as $r) {
        $primary_map[(int) $r->source_id][] = (int) $r->target_id;
    }

    $clusters = $wpdb->get_results("
        SELECT n.object_id
        FROM {$nodes_table} n
        INNER JOIN {$wpdb->posts} p
            ON p.ID = n.object_id
        WHERE n.object_type = 'page'
          AND n.seo_role = 'cluster'
          AND n.status = 1
          AND p.post_type = 'page'
          AND p.post_status = 'publish'
        ORDER BY p.post_title ASC
    ");

    $tree = [];

    foreach ($clusters as $c) {

        $cluster_id = (int) $c->object_id;

        $tree_cluster = [
            'id'       => $cluster_id,
            'title'    => get_the_title($cluster_id),
            'children' => [],
        ];

        foreach (($cluster_map[$cluster_id] ?? []) as $primary_id) {

            if (
                get_post_type($primary_id) !== 'page' ||
                get_post_status($primary_id) !== 'publish'
            ) {
                continue;
            }

            $tree_primary = [
                'id'       => $primary_id,
                'title'    => get_the_title($primary_id),
                'children' => [],
            ];

            foreach (($primary_map[$primary_id] ?? []) as $secondary_id) {

                if (
                    get_post_type($secondary_id) !== 'page' ||
                    get_post_status($secondary_id) !== 'publish'
                ) {
                    continue;
                }

                $tree_primary['children'][] = [
                    'id'    => $secondary_id,
                    'title' => get_the_title($secondary_id),
                    'type'  => 'hub_secondary',
                ];
            }

            if (empty($tree_primary['children'])) {
                continue;
            }

            $tree_cluster['children'][] = $tree_primary;
        }

        $tree[] = $tree_cluster;
    }

    return $tree;
}


/*************************************************
 * SOLUCIONES / LANDINGS PUBLICADAS
 *************************************************/
function seo_menu_get_solutions() {

    global $wpdb;

    $nodes_table = $wpdb->prefix . 'seo_nodes';

    $rows = $wpdb->get_results("
        SELECT DISTINCT n.object_id
        FROM {$nodes_table} n
        INNER JOIN {$wpdb->posts} p
            ON p.ID = n.object_id
        WHERE n.object_type = 'page'
          AND n.seo_role = 'landing'
          AND n.status = 1
          AND p.post_type = 'page'
          AND p.post_status = 'publish'
        ORDER BY p.post_title ASC, p.ID ASC
    ");

    $solutions = [];

    foreach ((array) $rows as $row) {

        $page_id = (int) $row->object_id;

        if ($page_id <= 0) {
            continue;
        }

        $solutions[] = [
            'id'    => $page_id,
            'title' => get_the_title($page_id),
        ];
    }

    return $solutions;
}


/*************************************************
 * PREPARAR PÁGINA ÍNDICE DE SOLUCIONES
 *************************************************/
function seo_menu_prepare_solutions_page($page_id) {

    $page_id = (int) $page_id;
    $post = $page_id > 0 ? get_post($page_id) : null;

    if (!$post instanceof WP_Post || $post->post_type !== 'page') {
        return new WP_Error(
            'invalid_solutions_page',
            'La página de Soluciones no es válida.'
        );
    }

    /*
     * Limpiar una posible plantilla antigua inválida
     * antes de actualizar la página.
     */
    delete_post_meta(
        $page_id,
        '_wp_page_template'
    );

    clean_post_cache($page_id);

    $updated = wp_update_post(
        [
            'ID'           => $page_id,
            'post_status'  => 'publish',
            'post_title'   => 'Soluciones',
            'post_name'    => 'soluciones',
            'post_content' => '[seo_solutions_index]',
        ],
        true
    );

    if (is_wp_error($updated)) {
        return $updated;
    }

    update_option(
        'seo_menu_solutions_page_id',
        $page_id,
        false
    );

    return $page_id;
}


/*************************************************
 * OBTENER O CREAR PÁGINA SOLUCIONES
 *************************************************/
function seo_menu_get_or_create_solutions_page() {

    $saved_page_id = (int) get_option(
        'seo_menu_solutions_page_id',
        0
    );

    if (
        $saved_page_id > 0 &&
        get_post_type($saved_page_id) === 'page' &&
        get_post_status($saved_page_id) !== false
    ) {
        return seo_menu_prepare_solutions_page(
            $saved_page_id
        );
    }

    $existing = get_page_by_path(
        'soluciones',
        OBJECT,
        'page'
    );

    if ($existing instanceof WP_Post) {
        return seo_menu_prepare_solutions_page(
            (int) $existing->ID
        );
    }

    $page_id = wp_insert_post(
        [
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Soluciones',
            'post_name'    => 'soluciones',
            'post_content' => '[seo_solutions_index]',
        ],
        true
    );

    if (is_wp_error($page_id)) {
        return $page_id;
    }

    return seo_menu_prepare_solutions_page(
        (int) $page_id
    );
}


/*************************************************
 * SHORTCODE DEL ÍNDICE DE SOLUCIONES
 *************************************************/
function seo_menu_render_solutions_index() {

    $solutions = seo_menu_get_solutions();

    ob_start();
    ?>

    <section
        class="seo-solutions-index"
        aria-labelledby="seo-solutions-title"
    >

        <header class="seo-solutions-index__header">

            <h1 id="seo-solutions-title">
                Soluciones
            </h1>

            <p>
                Guías de compra y soluciones para ayudarte
                a elegir productos según el trabajo,
                la aplicación o la necesidad concreta.
            </p>

        </header>

        <?php if (!empty($solutions)) : ?>

            <div class="seo-solutions-index__grid">

                <?php foreach ($solutions as $solution) : ?>

                    <?php
                    $solution_id = (int) ($solution['id'] ?? 0);

                    if ($solution_id <= 0) {
                        continue;
                    }

                    $excerpt = get_the_excerpt($solution_id);

                    if ($excerpt === '') {

                        $raw_content = (string) get_post_field(
                            'post_content',
                            $solution_id
                        );

                        $excerpt = wp_trim_words(
                            wp_strip_all_tags(
                                strip_shortcodes(
                                    $raw_content
                                )
                            ),
                            28,
                            '…'
                        );
                    }
                    ?>

                    <article class="seo-solutions-card">

                        <h2 class="seo-solutions-card__title">

                            <a href="<?php echo esc_url(
                                get_permalink($solution_id)
                            ); ?>">

                                <?php
                                echo esc_html(
                                    get_the_title($solution_id)
                                );
                                ?>

                            </a>

                        </h2>

                        <?php if ($excerpt !== '') : ?>

                            <p class="seo-solutions-card__excerpt">

                                <?php
                                echo esc_html($excerpt);
                                ?>

                            </p>

                        <?php endif; ?>

                        <a
                            class="seo-solutions-card__link"
                            href="<?php echo esc_url(
                                get_permalink($solution_id)
                            ); ?>"
                        >
                            Ver solución →
                        </a>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else : ?>

            <p>
                No hay soluciones publicadas en este momento.
            </p>

        <?php endif; ?>

    </section>

    <style>
        .seo-solutions-index {
            max-width:1200px;
            margin:0 auto;
            padding:32px 20px 56px;
        }

        .seo-solutions-index__header {
            margin-bottom:28px;
        }

        .seo-solutions-index__header h1 {
            margin-bottom:10px;
        }

        .seo-solutions-index__grid {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
            gap:22px;
        }

        .seo-solutions-card {
            padding:22px;
            border:1px solid #e5e5e5;
            border-radius:8px;
            background:#fff;
        }

        .seo-solutions-card__title {
            font-size:1.2rem;
            line-height:1.3;
            margin:0 0 10px;
        }

        .seo-solutions-card__title a {
            text-decoration:none;
        }

        .seo-solutions-card__excerpt {
            margin:0 0 16px;
        }

        .seo-solutions-card__link {
            font-weight:600;
            text-decoration:none;
        }
    </style>

    <?php

    return ob_get_clean();
}

add_shortcode(
    'seo_solutions_index',
    'seo_menu_render_solutions_index'
);


/*************************************************
 * TREE → WORDPRESS MENU
 *************************************************/
function seo_tree_to_wp_menu(
    $tree,
    $menu_id,
    $include_solutions = 0
) {

    $menu_id = (int) $menu_id;
    $include_solutions = (int) $include_solutions;

    if (
        $menu_id <= 0 ||
        !wp_get_nav_menu_object($menu_id)
    ) {
        return new WP_Error(
            'invalid_menu',
            'No se puede sincronizar porque el menú no existe.'
        );
    }

    if (!is_array($tree)) {
        return new WP_Error(
            'invalid_tree',
            'La estructura SEO no es válida.'
        );
    }

    /*
     * Eliminar todos los elementos del menú SEO actual
     * y reconstruirlo desde la base de datos.
     */
    $items = wp_get_nav_menu_items($menu_id);

    if (is_array($items)) {
        foreach ($items as $item) {
            wp_delete_post(
                (int) $item->ID,
                true
            );
        }
    }

    $position = 1;

    /*************************************************
     * CLUSTERS
     *************************************************/
    foreach ($tree as $cluster) {

        $cluster_id = isset($cluster['id'])
            ? (int) $cluster['id']
            : 0;

        if (
            $cluster_id <= 0 ||
            get_post_type($cluster_id) !== 'page' ||
            get_post_status($cluster_id) !== 'publish'
        ) {
            continue;
        }

        $cluster_url = get_permalink($cluster_id);

        if (!$cluster_url) {
            return new WP_Error(
                'cluster_missing_url',
                'El cluster "' . get_the_title($cluster_id) . '" no tiene una URL pública válida.'
            );
        }

        $cluster_menu_id = wp_update_nav_menu_item(
            $menu_id,
            0,
            [
                'menu-item-title'    => get_the_title($cluster_id),
                'menu-item-url'      => $cluster_url,
                'menu-item-type'     => 'custom',
                'menu-item-status'   => 'publish',
                'menu-item-position' => $position++,
            ]
        );

        if (is_wp_error($cluster_menu_id)) {
            return $cluster_menu_id;
        }

        /*************************************************
         * HUBS PRIMARIOS
         *************************************************/
        foreach (($cluster['children'] ?? []) as $primary) {

            $primary_id = isset($primary['id'])
                ? (int) $primary['id']
                : 0;

            if (
                $primary_id <= 0 ||
                get_post_type($primary_id) !== 'page' ||
                get_post_status($primary_id) !== 'publish'
            ) {
                continue;
            }

            $primary_url = get_permalink($primary_id);

            if (!$primary_url) {
                return new WP_Error(
                    'primary_missing_url',
                    'El hub primario "' . get_the_title($primary_id) . '" no tiene una URL pública válida.'
                );
            }

            $primary_menu_id = wp_update_nav_menu_item(
                $menu_id,
                0,
                [
                    'menu-item-title'     => get_the_title($primary_id),
                    'menu-item-url'       => $primary_url,
                    'menu-item-type'      => 'custom',
                    'menu-item-status'    => 'publish',
                    'menu-item-parent-id' => (int) $cluster_menu_id,
                    'menu-item-position'  => $position++,
                ]
            );

            if (is_wp_error($primary_menu_id)) {
                return $primary_menu_id;
            }

            /*************************************************
             * HUBS SECUNDARIOS
             *************************************************/
            foreach (($primary['children'] ?? []) as $secondary) {

                $secondary_id = isset($secondary['id'])
                    ? (int) $secondary['id']
                    : 0;

                if (
                    $secondary_id <= 0 ||
                    get_post_type($secondary_id) !== 'page' ||
                    get_post_status($secondary_id) !== 'publish'
                ) {
                    continue;
                }

                $secondary_url = get_permalink($secondary_id);

                if (!$secondary_url) {
                    return new WP_Error(
                        'secondary_missing_url',
                        'El hub secundario "' . get_the_title($secondary_id) . '" no tiene una URL pública válida.'
                    );
                }

                $secondary_menu_id = wp_update_nav_menu_item(
                    $menu_id,
                    0,
                    [
                        'menu-item-title'     => get_the_title($secondary_id),
                        'menu-item-url'       => $secondary_url,
                        'menu-item-type'      => 'custom',
                        'menu-item-status'    => 'publish',
                        'menu-item-parent-id' => (int) $primary_menu_id,
                        'menu-item-position'  => $position++,
                    ]
                );

                if (is_wp_error($secondary_menu_id)) {
                    return $secondary_menu_id;
                }
            }
        }
    }

    /*************************************************
     * SOLUCIONES
     *
     * OPCIONAL.
     *
     * Se añade al MISMO NIVEL que los clusters.
     *
     * Las páginas landing NO se añaden como
     * elementos ni como submenús.
     *************************************************/
    if ($include_solutions === 1) {

        $solutions_page_id = seo_menu_get_or_create_solutions_page();

        if (is_wp_error($solutions_page_id)) {
            return $solutions_page_id;
        }

        $solutions_page_id = (int) $solutions_page_id;

        if ($solutions_page_id <= 0) {
            return new WP_Error(
                'invalid_solutions_page',
                'No se ha podido localizar o crear la página Soluciones.'
            );
        }

        if (get_post_status($solutions_page_id) !== 'publish') {

            $published = wp_update_post(
                [
                    'ID'          => $solutions_page_id,
                    'post_status' => 'publish',
                ],
                true
            );

            if (is_wp_error($published)) {
                return $published;
            }
        }

        /*
         * Soluciones se crea como ENLACE PERSONALIZADO
         * a la página /soluciones/.
         *
         * Así el generador del menú no depende de
         * ninguna plantilla de página.
         */
        $solutions_menu_id = wp_update_nav_menu_item(
            $menu_id,
            0,
            [
                'menu-item-title'     => 'Soluciones',
                'menu-item-url'       => get_permalink($solutions_page_id),
                'menu-item-type'      => 'custom',
                'menu-item-status'    => 'publish',
                'menu-item-parent-id' => 0,
                'menu-item-position'  => $position++,
            ]
        );

        if (is_wp_error($solutions_menu_id)) {
            return $solutions_menu_id;
        }

        if ((int) $solutions_menu_id <= 0) {
            return new WP_Error(
                'solutions_menu_insert_failed',
                'WordPress no ha podido insertar Soluciones en el menú.'
            );
        }

        /*
         * Verificación real.
         */
        $menu_items = wp_get_nav_menu_items($menu_id);

        $solutions_found = false;

        foreach ((array) $menu_items as $menu_item) {

            if ((int) $menu_item->ID === (int) $solutions_menu_id) {
                $solutions_found = true;
                break;
            }
        }

        if (!$solutions_found) {
            return new WP_Error(
                'solutions_menu_verification_failed',
                'Soluciones se intentó crear, pero no aparece dentro del menú de WordPress.'
            );
        }
    }

    update_option(
        'seo_menu_last_sync',
        current_time('mysql'),
        false
    );

    return true;
}


/*************************************************
 * SET MENU
 *************************************************/
function seo_menu_set_primary_menu($menu_id) {

    $locations = get_theme_mod('nav_menu_locations');

    if (!is_array($locations)) {
        $locations = [];
    }

    $registered = get_registered_nav_menus();

    if (empty($registered)) {
        return;
    }

    $key = array_key_first($registered);

    $locations[$key] = $menu_id;

    set_theme_mod(
        'nav_menu_locations',
        $locations
    );
}


/*************************************************
 * RESTORE
 *************************************************/
function seo_menu_restore_original_primary() {

    $backup = get_option('seo_menu_backup');

    if (!is_array($backup)) {
        return true;
    }

    if (
        isset($backup['locations']) &&
        is_array($backup['locations'])
    ) {

        set_theme_mod(
            'nav_menu_locations',
            $backup['locations']
        );

        return true;
    }

    set_theme_mod(
        'nav_menu_locations',
        $backup
    );

    return true;
}


/*************************************************
 * LOCALIZAR UBICACIÓN PRINCIPAL DEL TEMA
 *************************************************/
function seo_menu_get_primary_location() {

    $registered = get_registered_nav_menus();

    if (
        empty($registered) ||
        !is_array($registered)
    ) {
        return new WP_Error(
            'no_menu_locations',
            'El tema activo no tiene ubicaciones de menú registradas.'
        );
    }

    $preferred_locations = [
        'primary',
        'menu-1',
        'main-menu',
        'main_menu',
        'main-navigation',
        'main_navigation',
        'header',
        'header-menu',
        'top',
    ];

    foreach ($preferred_locations as $location) {

        if (array_key_exists($location, $registered)) {
            return $location;
        }
    }

    return array_key_first($registered);
}


/*************************************************
 * OBTENER O CREAR MENÚ SEO
 *************************************************/
function seo_menu_get_or_create_menu() {

    $saved_menu_id = (int) get_option(
        'seo_menu_created_id'
    );

    if ($saved_menu_id > 0) {

        $saved_menu = wp_get_nav_menu_object(
            $saved_menu_id
        );

        if (
            $saved_menu &&
            !is_wp_error($saved_menu)
        ) {
            return $saved_menu_id;
        }
    }

    $existing_menu = wp_get_nav_menu_object(
        'SEO MENU2'
    );

    if (
        $existing_menu &&
        !is_wp_error($existing_menu)
    ) {

        $menu_id = (int) $existing_menu->term_id;

    } else {

        $menu_id = wp_create_nav_menu(
            'SEO MENU2'
        );

        if (is_wp_error($menu_id)) {
            return $menu_id;
        }

        $menu_id = (int) $menu_id;
    }

    update_option(
        'seo_menu_created_id',
        $menu_id,
        false
    );

    update_option(
        'seo_menu_created_date',
        current_time('mysql'),
        false
    );

    return $menu_id;
}


/*************************************************
 * BACKUP DE LA UBICACIÓN ACTUAL
 *************************************************/
function seo_menu_backup_current_locations(
    $seo_menu_id
) {

    $locations = get_theme_mod(
        'nav_menu_locations',
        []
    );

    if (!is_array($locations)) {
        $locations = [];
    }

    $location = seo_menu_get_primary_location();

    if (is_wp_error($location)) {
        return $location;
    }

    $current_menu_id = isset($locations[$location])
        ? (int) $locations[$location]
        : 0;

    /*
     * Si SEO MENU2 ya está activo,
     * no sustituimos el backup por
     * el propio menú SEO.
     */
    if (
        $current_menu_id ===
        (int) $seo_menu_id
    ) {
        return true;
    }

    $backup = [
        'stylesheet'  => get_stylesheet(),
        'location'    => $location,
        'locations'   => $locations,
        'created_at'  => current_time('mysql'),
    ];

    update_option(
        'seo_menu_backup',
        $backup,
        false
    );

    return true;
}


/*************************************************
 * ACTIVAR SEO MENU2
 *************************************************/
function seo_menu_activate($menu_id) {

    $menu_id = (int) $menu_id;

    if (
        $menu_id <= 0 ||
        !wp_get_nav_menu_object($menu_id)
    ) {
        return new WP_Error(
            'invalid_menu',
            'El menú SEO no existe.'
        );
    }

    $location = seo_menu_get_primary_location();

    if (is_wp_error($location)) {
        return $location;
    }

    $backup_result = seo_menu_backup_current_locations(
        $menu_id
    );

    if (is_wp_error($backup_result)) {
        return $backup_result;
    }

    $locations = get_theme_mod(
        'nav_menu_locations',
        []
    );

    if (!is_array($locations)) {
        $locations = [];
    }

    $locations[$location] = $menu_id;

    set_theme_mod(
        'nav_menu_locations',
        $locations
    );

    update_option(
        'seo_menu_mode',
        'seo',
        false
    );

    update_option(
        'seo_menu_active_location',
        $location,
        false
    );

    return true;
}
