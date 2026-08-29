<?php
/*
Plugin Name: SEO Menu Manager
Plugin URI: https://www.distribuidordeherramientas.es/
Description: GProceso central de la adminsitracion
Version: 1.0.0
Requires PHP: 7.4
Requires at least: 5.8
Author: David Perez Martorell davidperezmartorell@gmail.com
Author URI: https://focazul.wordpress.com/
License: GPL2
Text Domain: seo-menu-manager
*/


if (!defined('ABSPATH')) exit;

// Vista jerárquica de Taxonomía. Se mantiene separada de seo-reports.php.
$seo_taxonomy_view_file = __DIR__ . '/seo-taxonomy.php';
if (is_readable($seo_taxonomy_view_file)) {
    require_once $seo_taxonomy_view_file;
}



/****************************
 PAGE PRINCIPAL
***************************/
function seo_taxonomy_page() {

    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos suficientes para acceder a esta página.'));
    }

    if (isset($_POST['action_type']) && in_array($_POST['action_type'], ['save','delete','toggle'], true)) {
        seo_save_taxonomy();
    }

    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'manage_taxonomy';

    // La gestión semántica vive ahora en su página única del menú principal.
    // Conservamos las URLs antiguas como redirección para no romper favoritos.
    if ($active_tab === 'semantic') {
        wp_safe_redirect(admin_url('admin.php?page=seo-tags-vocabulary'));
        exit;
    }

    $allowed_tabs = ['manage_taxonomy', 'taxonomy'];
    if (!in_array($active_tab, $allowed_tabs, true)) {
        $active_tab = 'manage_taxonomy';
    }


    $data = function_exists('seo_get_taxonomy_data') ? seo_get_taxonomy_data() : [];

    echo '<div class="wrap">';
    echo '<h1>Taxonomía SEO</h1>';

    echo '<h2 class="nav-tab-wrapper">';
    echo '<a href="' . esc_url(admin_url('admin.php?page=seo-taxonomy&tab=manage_taxonomy')) . '" class="nav-tab ' . ($active_tab === 'manage_taxonomy' ? 'nav-tab-active' : '') . '">Gestión SEO</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=seo-taxonomy&tab=taxonomy')) . '" class="nav-tab ' . ($active_tab === 'taxonomy' ? 'nav-tab-active' : '') . '">Taxonomía</a>';
    echo '<a href="' . esc_url(admin_url('admin.php?page=seo-tags-vocabulary')) . '" class="nav-tab">Semántica</a>';
    echo '</h2>';

    echo '<div class="tab-content" style="padding-top:20px;">';

    if ($active_tab === 'manage_taxonomy') {
        echo '<form method="post">';
        if (function_exists('seo_render_taxonomy_ui')) {
            seo_render_taxonomy_ui($data);
        }
        echo '</form>';
        echo '</div>';
        echo '</div>';
        return;
    }

    if ($active_tab === 'taxonomy') {
        if (function_exists('seo_render_taxonomy_hierarchy')) {
            seo_render_taxonomy_hierarchy(false);
        } else {
            echo '<div class="notice notice-error inline"><p>No se ha podido cargar la vista jerárquica de Taxonomía desde <code>seo-taxonomy.php</code>.</p></div>';
        }

        echo '</div>';
        echo '</div>';
        return;
    }

    echo '</div>';
    echo '</div>';
}

/****************************
 SAVE DE RELACIONES ESTRUCTURALES
***************************/
function seo_save_taxonomy() {

    global $wpdb;

    if (!isset($_POST['cluster']) && !isset($_POST['hub_primary']) && !isset($_POST['hub_secondary'])) {
        return;
    }

    $table = $wpdb->prefix . 'seo_relations';

    /*******************************************
     * CLUSTERS
     *******************************************/
    if (!empty($_POST['cluster']) && is_array($_POST['cluster'])) {

        foreach ($_POST['cluster'] as $cluster_id => $data) {

            $cluster_id = (int) $cluster_id;

            /* TEMPLATE */
            if (!empty($data['template'])) {
                update_post_meta(
                    $cluster_id,
                    '_wp_page_template',
                    sanitize_text_field($data['template'])
                );
            }

            /******** HUB PRIMARY ********/
            $wpdb->delete($table, [
                'source_type'   => 'cluster',
                'source_id'     => $cluster_id,
                'relation_type' => 'cluster_to_primary'
            ]);

            $hub_ids = array_map('intval', $data['hub_primary'] ?? []);

            foreach ($hub_ids as $hub_id) {
                if ($hub_id <= 0) continue;

                $wpdb->insert($table, [
                    'source_type'   => 'cluster',
                    'source_id'     => $cluster_id,
                    'target_type'   => 'hub_primary',
                    'target_id'     => $hub_id,
                    'relation_type' => 'cluster_to_primary',
                    'created_at'    => current_time('mysql')
                ]);
            }

        }
    }

    /*******************************************
     * HUB PRIMARY
     *******************************************/
    if (!empty($_POST['hub_primary']) && is_array($_POST['hub_primary'])) {

        foreach ($_POST['hub_primary'] as $hub_id => $data) {

            $hub_id = (int) $hub_id;


            /******** HUB SECONDARY ********/
            $wpdb->delete($table, [
                'source_type'   => 'hub_primary',
                'source_id'     => $hub_id,
                'relation_type' => 'hub_primary_to_hub_secondary'
            ]);

            $secondary = array_map('intval', $data['hub_secondary'] ?? []);

            foreach ($secondary as $sec_id) {
                if ($sec_id <= 0) continue;

                $wpdb->insert($table, [
                    'source_type'   => 'hub_primary',
                    'source_id'     => $hub_id,
                    'target_type'   => 'hub_secondary',
                    'target_id'     => $sec_id,
                    'relation_type' => 'hub_primary_to_hub_secondary',
                    'created_at'    => current_time('mysql')
                ]);
            }
        }
    }

    /*******************************************
     * HUB SECONDARY
     *******************************************/
    if (!empty($_POST['hub_secondary']) && is_array($_POST['hub_secondary'])) {

        foreach ($_POST['hub_secondary'] as $hub_id => $data) {

            $hub_id = (int) $hub_id;

            /******** LANDINGS ********/
            $wpdb->delete($table, [
                'source_type'   => 'hub_secondary',
                'source_id'     => $hub_id,
                'relation_type' => 'hub_secondary_to_landing'
            ]);

            $landings = array_map('intval', $data['landing_pages'] ?? []);

            foreach ($landings as $landing_id) {
                if ($landing_id <= 0) continue;

                $wpdb->insert($table, [
                    'source_type'   => 'hub_secondary',
                    'source_id'     => $hub_id,
                    'target_type'   => 'landing_page',
                    'target_id'     => $landing_id,
                    'relation_type' => 'hub_secondary_to_landing',
                    'created_at'    => current_time('mysql')
                ]);
            }

            /******** CATEGORIES ********/
            $wpdb->delete($table, [
                'source_type'   => 'hub_secondary',
                'source_id'     => $hub_id,
                'relation_type' => 'hub_secondary_to_category'
            ]);

            $cats = array_map('intval', $data['categories'] ?? []);

            foreach ($cats as $cat_id) {
                if ($cat_id <= 0) continue;

                $wpdb->insert($table, [
                    'source_type'   => 'hub_secondary',
                    'source_id'     => $hub_id,
                    'target_type'   => 'product_cat',
                    'target_id'     => $cat_id,
                    'relation_type' => 'hub_secondary_to_category',
                    'created_at'    => current_time('mysql')
                ]);
            }
        }
    }
}


/****************************
 DATOS WORDPRESS (SOLO SQL REAL)
***************************/
function seo_get_taxonomy_data() {

    global $wpdb;

    /**********************
     PLANTILLAS (solo display opcional)
    **********************/
    $theme = wp_get_theme();

    $templates = array_merge(
        ['default' => 'Plantilla por defecto'],
        $theme->get_page_templates()
    );

    /**********************
     TODAS LAS PÁGINAS
    **********************/
    $pages = $wpdb->get_results("
        SELECT ID, post_title
        FROM {$wpdb->posts}
        WHERE post_type = 'page'
    ");

    /**********************
     LEER ROLES SEO (FUENTE REAL)
    **********************/
    $table_nodes = $wpdb->prefix . 'seo_nodes';

    $rows = $wpdb->get_results("
        SELECT object_id, seo_role
        FROM $table_nodes
        WHERE object_type = 'page'
    ");

    $roles_map = [];

    foreach ($rows as $row) {
        $roles_map[(int)$row->object_id] = $row->seo_role;
    }

    /**********************
     CLUSTERS / HUBS SEGÚN SEO ROLE
    **********************/
    $clusters = [];
    $hub_primary_pages = [];
    $hub_secondary_pages = [];

    foreach ($pages as $p) {

        $id = (int) $p->ID;
        $role = $roles_map[$id] ?? null;

        if ($role === 'cluster') {
            $clusters[] = $p;
        }

        if ($role === 'hub_primary') {
            $hub_primary_pages[] = $p;
        }

        if ($role === 'hub_secondary') {
            $hub_secondary_pages[] = $p;
        }
    }

    /**********************
     CATEGORÍAS
    **********************/
    $categories = get_terms([
        'taxonomy'   => 'product_cat',
        'hide_empty' => false
    ]);

    return [
        'templates'           => $templates,
        'clusters'            => $clusters,
        'categories'          => $categories,
        'hub_primary_pages'   => $hub_primary_pages,
        'hub_secondary_pages' => $hub_secondary_pages
    ];
}


/****************************
 UI PRINCIPAL
***************************/
function seo_render_taxonomy_ui($data) {

    global $wpdb;

    foreach ($data['clusters'] as $cluster) {

        seo_render_cluster_row($cluster, $data['templates'], $data['hub_primary_pages']);


        $hub_ids = $wpdb->get_col($wpdb->prepare("
            SELECT target_id
            FROM {$wpdb->prefix}seo_relations
            WHERE source_type='cluster'
              AND source_id=%d
              AND target_type='hub_primary'
              AND relation_type='cluster_to_primary'
        ", $cluster->ID));
        
        $hub_ids = array_map('intval', (array)$hub_ids);
        
        foreach ($hub_ids as $hid) {
        
            foreach ($data['hub_primary_pages'] as $hub) {
        
                if ((int)$hub->ID !== (int)$hid) continue;
        
                echo '<div style="margin-left:60px;">';
        
                // HUB PRIMARY
                seo_render_hub_primary_row(
                    $hub,
                    $data['templates']
                );
        
                // 🔥 AÑADIDO: HUB SECONDARY BAJO CADA PRIMARY
                $sec_ids = $wpdb->get_col($wpdb->prepare("
                    SELECT target_id
                    FROM {$wpdb->prefix}seo_relations
                    WHERE source_type = 'hub_primary'
                      AND source_id = %d
                      AND target_type = 'hub_secondary'
                      AND relation_type = 'hub_primary_to_hub_secondary'
                ", $hub->ID));
        
                $sec_ids = array_map('intval', (array)$sec_ids);
        
                foreach ($sec_ids as $sid) {
        
                    foreach ($data['hub_secondary_pages'] as $sec) {
        
                        if ((int)$sec->ID !== $sid) continue;
        
                        echo '<div style="margin-left:60px;">';
        
                        seo_render_hub_secondary_row(
                            $sec,
                            $data['templates'],
                            $data['categories']
                        );
        
                        echo '</div>';
                    }
                }
        
                echo '</div>';
            }
        }
    }
}




/****************************
 ROW CLUSTER
***************************/

function seo_render_cluster_row($page, $templates, $hub_primary_pages) {

    global $wpdb;

    $id = (int) $page->ID;

    /**********************
     TEMPLATE
    **********************/
    $template = get_page_template_slug($id) ?: 'default';
    $template_label = $templates[$template] ?? $template;

    /**********************
     HUBS SELECCIONADOS EN ESTE CLUSTER
    **********************/
    $selected_hubs = $wpdb->get_col($wpdb->prepare("
        SELECT target_id
        FROM {$wpdb->prefix}seo_relations
        WHERE source_type = 'cluster'
          AND source_id = %d
          AND target_type = 'hub_primary'
          AND relation_type = 'cluster_to_primary'
    ", $id));

    $selected_hubs = array_map('intval', (array) $selected_hubs);
    $hub_map = array_flip($selected_hubs);

    /**********************
     🔥 NUEVO: HUBS ASIGNADOS GLOBALMENTE A CUALQUIER CLUSTER
    **********************/
    $global_assigned_hubs = $wpdb->get_col("
        SELECT DISTINCT target_id
        FROM {$wpdb->prefix}seo_relations
        WHERE source_type = 'cluster'
          AND target_type = 'hub_primary'
          AND relation_type = 'cluster_to_primary'
    ");
    $global_assigned_hubs = array_map('intval', (array) $global_assigned_hubs);


    /**********************
     FORM
    **********************/
    echo '<form method="post">';
    echo '<input type="hidden" name="cluster_id" value="' . esc_attr($id) . '">';

    echo '<div style="display:flex;gap:20px;padding:12px;border:1px solid #4caf50;margin-bottom:10px;background:#f6fff6;">';

    /**********************
     INFO
    **********************/
    $post_status = get_post_status($id);
    $is_publish  = ($post_status === 'publish');

    echo '<div style="min-width:220px;">';

    echo '<strong>' . esc_html($page->post_title) . '</strong><br>';
    echo 'ID: ' . $id . '<br><br>';

    echo '<span style="font-size:12px;padding:3px 6px;background:' .
        ($is_publish ? '#2e7d32' : '#e53935') .
        ';color:#fff;border-radius:4px;">' .
        strtoupper($post_status) .
    '</span><br><br>';

    wp_nonce_field('seo_cluster_action', 'seo_cluster_nonce');

    echo '<button type="submit" name="action_type" value="toggle">Toggle</button>';

    echo '<br><br>';

    echo '<button type="submit" name="action_type" value="save" style="padding:6px 10px;background:#2e7d32;color:#fff;border:none;border-radius:4px;cursor:pointer;">
        Guardar cambios
    </button>';

    echo '<br><br>';

    echo '<button type="submit" name="action_type" value="delete"
        onclick="return confirm(\'¿Seguro que quieres eliminar este cluster?\')"
        style="padding:6px 10px;background:#d32f2f;color:#fff;border:none;border-radius:4px;cursor:pointer;">
        Eliminar
    </button>';

    echo '</div>';

    /**********************
     TEMPLATE
    **********************/
    echo '<div style="min-width:260px;">';
    echo '<strong>Plantilla</strong><br>';
    echo '<span style="color:#555;">' . esc_html($template_label) . '</span>';
    echo '</div>';

    /**********************
     HUBS (REVISADO)
    **********************/
    echo '<div style="min-width:260px;">';
    echo '<strong>Hubs primarios</strong><br>';

    /* SELECCIONADOS (VERDE ARRIBA): Muestra los de este cluster */
    echo '<div style="max-height:120px;overflow-y:auto;background:#e8f5e9;border:1px solid #2e7d32;padding:6px;margin-bottom:6px;">';

    foreach ($hub_primary_pages as $h) {

        $hid = (int) $h->ID;

        if (!isset($hub_map[$hid])) continue;

        echo '<label style="display:block;color:#1b5e20;font-weight:600;">';
        echo "<input type='checkbox' name='cluster[$id][hub_primary][]' value='{$hid}' checked> ";
        echo esc_html($h->post_title);
        echo '</label>';
    }

    echo '</div>';

    /* DISPONIBLES REALES (SCROLL ABAJO): Solo los que no están asignados a NADIE */
    echo '<div style="max-height:180px;overflow-y:auto;border:1px solid #ccc;padding:6px;">';

    foreach ($hub_primary_pages as $h) {

        $hid = (int) $h->ID;

        // Condición clave: Si ya está seleccionado AQUÍ, nos lo saltamos
        if (isset($hub_map[$hid])) continue;
        
        // 🔥 NUEVA Condición de Mercado: Si está en la lista global de asignados a otros clusters, lo ocultamos
        if (in_array($hid, $global_assigned_hubs)) continue;

        echo '<label style="display:block;">';
        echo "<input type='checkbox' name='cluster[$id][hub_primary][]' value='{$hid}'> ";
        echo esc_html($h->post_title);
        echo '</label>';
    }

    echo '</div>';

    echo '</div>';


    echo '</div>';
    echo '</form>';
}

/****************************
 ROW PRIMARIO
***************************/
function seo_render_hub_primary_row($page, $templates) {

    global $wpdb;

    $id = (int) $page->ID;

    $template = get_page_template_slug($id) ?: 'default';
    $template_label = $templates[$template] ?? $template;

    /**********************
     HUBS SECUNDARIOS SELECCIONADOS EN ESTE HUB PRIMARIO
    **********************/
    $selected_secondary = $wpdb->get_col($wpdb->prepare("
        SELECT target_id
        FROM {$wpdb->prefix}seo_relations
        WHERE source_type = 'hub_primary'
          AND source_id = %d
          AND target_type = 'hub_secondary'
          AND relation_type = 'hub_primary_to_hub_secondary'
    ", $id));

    $selected_secondary = array_map('intval', (array) $selected_secondary);
    $selected_map = array_flip($selected_secondary);

    /**********************
     🔥 NUEVO: HUBS SECUNDARIOS ASIGNADOS GLOBALMENTE A CUALQUIER HUB PRIMARIO
    **********************/
    $global_assigned_secondary = $wpdb->get_col("
        SELECT DISTINCT target_id
        FROM {$wpdb->prefix}seo_relations
        WHERE source_type = 'hub_primary'
          AND target_type = 'hub_secondary'
          AND relation_type = 'hub_primary_to_hub_secondary'
    ");
    $global_assigned_secondary = array_map('intval', (array) $global_assigned_secondary);

    /**********************
     TODOS LOS HUBS SECUNDARIOS EXISTENTES Y PUBLICADOS
    **********************/
    $hub_secondary_pages = $wpdb->get_results("
        SELECT p.ID, p.post_title
        FROM {$wpdb->prefix}seo_nodes n
        INNER JOIN {$wpdb->prefix}posts p ON p.ID = n.object_id
        WHERE n.object_type = 'page'
          AND n.seo_role = 'hub_secondary'
          AND p.post_status = 'publish'
        ORDER BY p.post_title ASC
    ");


    echo '<form method="post">';
    echo '<input type="hidden" name="hub_primary_id" value="' . esc_attr($id) . '">';
    echo '<input type="hidden" name="action_type" value="save">';
    wp_nonce_field('seo_cluster_action', 'seo_cluster_nonce');

    echo '<div style="display:flex;gap:20px;padding:12px;border:1px solid #4caf50;margin-bottom:10px;background:#f6fff6;">';

    /* =========================
       BLOQUE INFO
    ========================= */
    echo '<div style="min-width:220px;">';

    $status = get_post_status($id);
    $next = ($status === 'publish') ? 'draft' : 'publish';

    echo '<strong>' . esc_html($page->post_title) . '</strong><br>';
    echo 'ID: ' . $id . '<br><br>';

    echo '<span style="font-size:12px;padding:3px 6px;background:' .
        ($status === 'publish' ? '#2e7d32' : '#e53935') .
        ';color:#fff;border-radius:4px;">' .
        strtoupper($status) .
    '</span><br><br>';

    echo "<button type='button' class='seo-toggle-status' data-id='{$id}' data-type='hub_primary' data-status='{$next}'>Toggle</button>";

    echo '<br><br>';

    echo '<button type="submit" style="padding:6px 10px;background:#2e7d32;color:#fff;border:none;border-radius:4px;cursor:pointer;">
        Guardar
    </button>';

    echo '<br><br>';

    echo "<button type='submit' name='action_type' value='delete'
        onclick='return confirm(\"¿Eliminar hub primario?\")'
        style='padding:6px 10px;background:#d32f2f;color:#fff;border:none;border-radius:4px;cursor:pointer;'>
        Eliminar
    </button>";

    echo '</div>';

    /* =========================
       PLANTILLA
    ========================= */
    echo '<div style="min-width:260px;">';
    echo '<strong>Plantilla</strong><br>';
    echo esc_html($template_label);
    echo '</div>';

    /* =========================
       HUB SECUNDARIO (REVISADO)
    ========================= */
    echo '<div style="min-width:320px;">';
    echo '<strong>Hub secundario</strong><br>';

    /* SELECCIONADOS (VERDE ARRIBA): Muestra los asignados a este Hub Primario */
    echo '<div style="max-height:120px;overflow-y:auto;background:#e8f5e9;border:1px solid #2e7d32;padding:6px;margin-bottom:6px;">';

    foreach ($hub_secondary_pages as $h) {

        $hid = (int) $h->ID;

        if (!isset($selected_map[$hid])) continue;

        echo '<label style="display:block;color:#1b5e20;font-weight:600;">';
        echo "<input type='checkbox' name='hub_primary[$id][hub_secondary][]' value='{$hid}' checked> ";
        echo esc_html($h->post_title);
        echo '</label>';
    }

    echo '</div>';

    /* DISPONIBLES REALES (SCROLL ABAJO): Solo huérfanos que no pertenecen a ningún otro padre */
    echo '<div style="max-height:180px;overflow-y:auto;border:1px solid #ccc;padding:6px;">';

    foreach ($hub_secondary_pages as $h) {

        $hid = (int) $h->ID;

        // Si ya está seleccionado en este Hub Primario, lo saltamos (ya se renderiza arriba)
        if (isset($selected_map[$hid])) continue;

        // 🔥 Condición clave: Si ya está ocupado en la base de datos por otro Hub Primario, se oculta
        if (in_array($hid, $global_assigned_secondary)) continue;

        echo '<label style="display:block;">';
        echo "<input type='checkbox' name='hub_primary[$id][hub_secondary][]' value='{$hid}'> ";
        echo esc_html($h->post_title);
        echo '</label>';
    }

    echo '</div>';

    echo '</div>';


    echo '</div>';
    echo '</form>';
}


/****************************
 ROW SECUNDARIO
***************************/
function seo_render_hub_secondary_row($page, $templates, $categories) {

    global $wpdb;

    $id = (int) $page->ID;

    /************************************************************
     * 1. CATEGORÍAS ASIGNADAS A *ESTE* HUB SECUNDARIO ESPECÍFICO
     ************************************************************/
    $cat_ids = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT target_id
        FROM {$wpdb->prefix}seo_relations
        WHERE source_type = 'hub_secondary'
          AND source_id = %d
          AND target_type = 'product_cat'
          AND relation_type = 'hub_secondary_to_category'
    ", $id));

    $cat_map = array_flip(array_map('intval', (array) $cat_ids));

    /************************************************************
     * 🔥 MODIFICACIÓN CLAVE: CONTROL DE EXCLUSIVIDAD ESTRUCTURAL
     * Buscamos SOLAMENTE las categorías que ya estén ocupadas en relaciones
     * de tipo 'hub_secondary_to_category'. Las de marketing (cluster, primary) se ignoran.
     ************************************************************/
    $global_assigned_cats = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT target_id
        FROM {$wpdb->prefix}seo_relations
        WHERE target_type = 'product_cat'
          AND relation_type = 'hub_secondary_to_category'
    "));
    $global_assigned_map = array_flip(array_map('intval', (array) $global_assigned_cats));

    /************************************************************
     * 2. MEJORA: ORDENAR LAS CATEGORÍAS ALFABÉTICAMENTE
     ************************************************************/
    if (is_array($categories)) {
        usort($categories, function($a, $b) {
            return strcasecmp($a->name, $b->name);
        });
    }

    echo '<form method="post">';
    wp_nonce_field('seo_cluster_action', 'seo_cluster_nonce');

    echo "<input type='hidden' name='action_type' value='save'>";
    echo "<input type='hidden' name='hub_secondary[$id][id]' value='{$id}'>";

    echo '<div style="display:flex;gap:20px;padding:12px;border:1px solid #ff9800;margin:10px 0;background:#fffaf0;">';

    /* =========================
       INFO
    ========================= */
    echo '<div style="min-width:220px;">';

    $status = get_post_status($id);
    $next = ($status === 'publish') ? 'draft' : 'publish';

    echo '<strong>' . esc_html($page->post_title) . '</strong><br>';
    echo 'ID: ' . $id . '<br><br>';

    echo '<span style="padding:3px 6px;background:' .
        ($status === 'publish' ? '#2e7d32' : '#e53935') .
        ';color:#fff;border-radius:4px;">' .
        strtoupper($status) .
    '</span><br><br>';

    echo "<button type='button' class='seo-toggle-status' data-id='{$id}' data-type='hub_secondary' data-status='{$next}'>Toggle</button>";

    echo '<br><br>';

    echo "<button type='submit' style='padding:6px 10px;background:#1976d2;color:#fff;border:none;border-radius:4px;'>
        Guardar
    </button>";

    echo '<br><br>';

    echo "<button type='submit' name='action_type' value='delete'
        onclick='return confirm(\"¿Eliminar hub secundario?\")'
        style='padding:6px 10px;background:#d32f2f;color:#fff;border:none;border-radius:4px;'>
        Eliminar
    </button>";

    echo '</div>';

    /* =========================
       CATEGORÍAS
    ========================= */
    echo '<div style="flex:1;">';
    echo '<strong>Categorías</strong><br>';

    /* Campo de búsqueda */
    echo '<input type="text" 
                 placeholder="🔍 Buscar categoría disponible..." 
                 onkeyup="filtrarCategoriasHubSecundario(this, ' . $id . ')" 
                 style="width:100%; max-width:300px; margin: 6px 0; padding: 4px 8px; border: 1px solid #ccc; border-radius: 4px;">';

    /* SELECCIONADAS (VERDE ARRIBA) */
    echo '<div style="max-height:120px;overflow-y:auto;background:#e8f5e9;border:1px solid #2e7d32;padding:6px;margin-bottom:6px;">';

    foreach ($categories as $c) {

        $cid = (int) $c->term_id;

        if (!isset($cat_map[$cid])) continue;

        echo '<label style="display:block;color:#1b5e20;font-weight:600;">';
        echo "<input type='checkbox' name='hub_secondary[$id][categories][]' value='{$cid}' checked> ";
        echo esc_html($c->name);
        echo '</label>';
    }

    echo '</div>';

        /* DISPONIBLES REALES (SCROLL ABAJO) */
            echo '<div id="container-disponibles-' . $id . '" style="max-height:180px;overflow-y:auto;border:1px solid #ccc;padding:6px;">';
        
            foreach ($categories as $c) {
                $cid = (int) $c->term_id;
        
                // 1. Si ya es de este hub, se ignora porque ya está arriba (OK)
                if (isset($cat_map[$cid])) continue;
        
                // 2. FILTRO CORREGIDO:
                // Solo descartamos si la categoría existe en la tabla con relación de hub_secondary
                // Y además NO es la misma categoría que ya tenemos asignada (por seguridad).
                if (isset($global_assigned_map[$cid])) {
                    // Aquí es donde está el bloqueo: 
                    // Si la categoría tiene un registro en la tabla, NO se muestra.
                    // Si quieres verla, solo se debe mostrar si NO tiene relación de hub_secondary.
                    continue; 
                }
        
                echo '<label class="cat-item" style="display:block;">';
                echo "<input type='checkbox' name='hub_secondary[$id][categories][]' value='{$cid}'> ";
                echo esc_html($c->name);
                echo '</label>';
            }
            echo '</div>';

    echo '</div>';

    echo '</div>';
    echo '</form>';

    /* JavaScript Inyectado */
    static $js_inyectado = false;
    if (!$js_inyectado) {
        ?>
        <script>
        function filtrarCategoriasHubSecundario(input, hubId) {
            var filtro = input.value.toLowerCase();
            var contenedor = document.getElementById('container-disponibles-' + hubId);
            var items = contenedor.getElementsByClassName('cat-item');

            for (var i = 0; i < items.length; i++) {
                var textoItem = items[i].textContent || items[i].innerText;
                if (textoItem.toLowerCase().indexOf(filtro) > -1) {
                    items[i].style.display = "block";
                } else {
                    items[i].style.display = "none";
                }
            }
        }
        </script>
        <?php
        $js_inyectado = true;
    }
}

/****************************
 REASIGNACION DE CATEGORIAS
***************************/
function seo_render_reasignacion_ui($data) {
    global $wpdb;
    $rel_table = $wpdb->prefix . 'seo_relations';
    $nodes_table = $wpdb->prefix . 'seo_nodes';

    // 1. Obtener relaciones
    $results = $wpdb->get_results("SELECT * FROM $rel_table WHERE relation_type = 'hub_secondary_to_category'");

    // 2. Obtener lista de Hubs Secundarios para el select
    $hubs = $wpdb->get_results("
        SELECT p.ID, p.post_title 
        FROM {$wpdb->prefix}posts p
        INNER JOIN {$nodes_table} n ON p.ID = n.object_id
        WHERE n.seo_role = 'hub_secondary' 
        AND p.post_status = 'publish'
        ORDER BY p.post_title ASC
    ");

    echo '<h3>Reasignación de Categorías (Hubs Secundarios)</h3>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>Categoría</th><th>Hub Actual</th><th>Reasignar a...</th><th>Acción</th></tr></thead><tbody>';
    
    foreach ($results as $row) {
        $term = get_term($row->target_id, 'product_cat');
        $hub  = get_post($row->source_id);

        echo '<tr>';
        // Mostramos nombres con IDs entre paréntesis
        echo '<td>' . ($term ? esc_html($term->name) : 'ID:?') . ' (' . (int)$row->target_id . ')</td>';
        echo '<td>' . ($hub ? esc_html($hub->post_title) : 'ID:?') . ' (' . (int)$row->source_id . ')</td>';
        
        echo '<td>';
        echo '<form method="post">';
        // El ID de la relación va oculto, no molesta al usuario
        echo '<input type="hidden" name="rel_id" value="' . (int)$row->id . '">';
        echo '<input type="hidden" name="action_type" value="reasignar_cat">';
        
        echo '<select name="new_hub_id" style="width:100%;">';
        foreach ($hubs as $h) {
            $selected = ($h->ID == $row->source_id) ? 'selected' : '';
            echo '<option value="' . (int)$h->ID . '" ' . $selected . '>' . esc_html($h->post_title) . ' (' . (int)$h->ID . ')</option>';
        }
        echo '</select>';
        echo '</td>';
        
        echo '<td><button type="submit" class="button button-primary">Modificar</button></td>';
        echo '</form>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}



/****************************
 PROCESAMIENTO DE REASIGNACIÓN DE CATEGORÍAS
****************************/
function seo_process_reasignacion() {
    // Solo actuamos si el action_type es el correcto
    if (isset($_POST['action_type']) && $_POST['action_type'] === 'reasignar_cat') {
        
        global $wpdb;
        $rel_table = $wpdb->prefix . 'seo_relations';
        
        // Capturamos los datos
        $rel_id     = isset($_POST['rel_id']) ? (int) $_POST['rel_id'] : 0;
        $new_hub_id = isset($_POST['new_hub_id']) ? (int) $_POST['new_hub_id'] : 0;

        error_log("DEBUG: Rel ID: $rel_id, Nuevo Hub: $new_hub_id");

        if ($rel_id > 0 && $new_hub_id > 0) {
            // Ejecución directa sin complicaciones
            $result = $wpdb->update(
                $rel_table,
                array('source_id' => $new_hub_id),
                array('id' => $rel_id),
                array('%d'),
                array('%d')
            );

            error_log("RESULTADO UPDATE: " . ($result === false ? 'Error DB' : "$result filas afectadas"));
        } else {
            error_log("DEBUG: Datos inválidos. RelID: $rel_id, HubID: $new_hub_id");
        }
        
        // Forzamos redirección para limpiar
        wp_safe_redirect(admin_url('admin.php?page=category-seo-admin&tab=reasignar_categorias'));
        exit;
    }
}
add_action('admin_init', 'seo_process_reasignacion');
    
    
    /****************************
     BORRADO COMPLETO SEO + LOG SQL REAL
    ****************************/
if (isset($_POST['action_type']) && $_POST['action_type'] === 'delete') {

    global $wpdb;

    $id = 0;

    if (!empty($_POST['cluster_id'])) {
        $id = (int) $_POST['cluster_id'];
    }

    if (!empty($_POST['hub_primary_id'])) {
        $id = (int) $_POST['hub_primary_id'];
    }

    if (!empty($_POST['hub_secondary'])) {
        $keys = array_keys($_POST['hub_secondary']);
        $id = (int) $keys[0];
    }

    $nodes_table = $wpdb->prefix . 'seo_nodes';
    $rel_table   = $wpdb->prefix . 'seo_relations';

    error_log("========== SEO DELETE EXECUTION ==========");
    error_log("TARGET ID: " . $id);

    /**********************
     1. NODES
    **********************/
    $sql1 = $wpdb->prepare(
        "DELETE FROM $nodes_table WHERE object_id = %d",
        $id
    );

    $res1 = $wpdb->query($sql1);

    error_log("SQL1: $sql1");
    error_log("ROWS DELETED NODES: " . $res1);

    /**********************
     2. RELATIONS SOURCE
    **********************/
    $sql2 = $wpdb->prepare(
        "DELETE FROM $rel_table WHERE source_id = %d",
        $id
    );

    $res2 = $wpdb->query($sql2);

    error_log("SQL2: $sql2");
    error_log("ROWS DELETED SOURCE: " . $res2);

    /**********************
     3. RELATIONS TARGET
    **********************/
    $sql3 = $wpdb->prepare(
        "DELETE FROM $rel_table WHERE target_id = %d",
        $id
    );

    $res3 = $wpdb->query($sql3);

    error_log("SQL3: $sql3");
    error_log("ROWS DELETED TARGET: " . $res3);

    /**********************
     4. FORCE CLEANUP
    **********************/
    $sql4 = $wpdb->prepare(
        "DELETE FROM $rel_table WHERE source_id = %d OR target_id = %d",
        $id,
        $id
    );

    $res4 = $wpdb->query($sql4);

    error_log("SQL4: $sql4");
    error_log("ROWS DELETED FORCE: " . $res4);

    error_log("========== DELETE END ==========");

    return;
}

function seo_label_manager_page() {

    global $wpdb;

    $tabla = $wpdb->prefix . 'seo_labels';

    $modelo = $wpdb->get_results("
        SELECT *
        FROM {$tabla}
        WHERE status = 1
        ORDER BY object_type, seo_option, sort_order, keyword
    ");

    ?>

    <h2>Modelo taxonómico</h2>

    <p>
        Desde esta pantalla se define la información mínima que deberán contener
        las categorías y los productos.
    </p>

    <p>
        Cada grupo (<strong>SEO Option</strong>) define un criterio de clasificación.
        Si el campo <strong>Keyword</strong> está vacío, se trata de un atributo libre.
    </p>

    <hr>

    <p>
        <a href="#" class="button button-primary">
            Añadir definición
        </a>
    </p>

    <table class="widefat striped">

        <thead>

        <tr>

            <th>Tipo</th>
            <th>Grupo</th>
            <th>Valor</th>
            <th>Obligatorio</th>
            <th>Múltiple</th>
            <th width="90">Editar</th>

        </tr>

        </thead>

        <tbody>

        <?php

        if ($modelo) {

            foreach ($modelo as $fila) {

                echo '<tr>';

                echo '<td>' . esc_html($fila->object_type) . '</td>';

                echo '<td>' . esc_html($fila->seo_option) . '</td>';

                echo '<td>' .
                    ($fila->keyword === null || $fila->keyword === ''
                        ? '<em>Atributo libre</em>'
                        : esc_html($fila->keyword))
                    . '</td>';

                echo '<td>' . ($fila->required ? 'Sí' : 'No') . '</td>';

                echo '<td>' . ($fila->multiple ? 'Sí' : 'No') . '</td>';

                echo '<td>
                        <a href="#" class="button">
                            Editar
                        </a>
                      </td>';

                echo '</tr>';

            }

        } else {

            echo '<tr><td colspan="6">No existen definiciones.</td></tr>';

        }

        ?>

        </tbody>

    </table>

    <?php
}