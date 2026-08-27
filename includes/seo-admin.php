<?php
/**
 * SEO Taxonomy
 *
 * Archivo: seo-admin.php
 * Descripción: Registra el menú principal y los submenús del panel de administración
 * del plugin SEO Taxonomy. Centraliza la navegación hacia los distintos módulos.
 *
 * @package     SEOSystem
 * @subpackage  Admin
 * @author      David Pérez Martorell
 * @license     GPL-2.0-or-later
 * @since       2.0.0
 * @version     2.0.1
 *
 * Convenciones:
 * - Registrar únicamente menús y submenús.
 * - Mantener la lógica en los callbacks correspondientes.
 * - Documentar cualquier nueva entrada del menú.
 */

defined('ABSPATH') || exit;

/*
 * Carga defensiva del modulo Estado del servidor.
 * seo-admin.php registra el callback seo_server_status(), por lo que debe
 * estar declarado antes de que WordPress intente ejecutar esa pagina.
 */
if (!function_exists('seo_server_status')) {
    $seo_server_status_file = __DIR__ . '/seo-system-server-status.php';
    if (is_readable($seo_server_status_file)) {
        require_once $seo_server_status_file;
    }
}

/*
 * Editor de posts.
 * Se desactiva su submenu nativo bajo Entradas porque este modulo se integra
 * dentro de SEO Taxonomy.
 */
add_filter('seo_post_editor_register_submenu', '__return_false');

/*
 * Carga defensiva del editor. Si por cualquier motivo no puede cargarse aqui,
 * el callback del menu volvera a intentarlo y mostrara un diagnostico visible.
 */
if (!function_exists('seo_page_edit_posts')) {
    $seo_post_editor_file = __DIR__ . '/post-edit.php';
    if (is_readable($seo_post_editor_file)) {
        require_once $seo_post_editor_file;
    }
}

if (!function_exists('seo_post_admin_callback')) {
    function seo_post_admin_callback() {
        if (!function_exists('seo_page_edit_posts')) {
            $seo_post_editor_file = __DIR__ . '/post-edit.php';
            if (is_readable($seo_post_editor_file)) {
                require_once $seo_post_editor_file;
            }
        }

        if (function_exists('seo_page_edit_posts')) {
            seo_page_edit_posts();
            return;
        }

        $seo_post_editor_file = __DIR__ . '/post-edit.php';
        echo '<div class="wrap">';
        echo '<h1>Posts</h1>';
        echo '<div class="notice notice-error"><p><strong>No se ha podido cargar el editor de posts.</strong></p>';
        echo '<p>Archivo esperado: <code>' . esc_html($seo_post_editor_file) . '</code></p>';
        echo '<p>Existe/legible: <strong>' . (is_readable($seo_post_editor_file) ? 'SI' : 'NO') . '</strong></p>';
        echo '<p>Funcion <code>seo_page_edit_posts()</code>: <strong>NO disponible</strong></p></div>';
        echo '</div>';
    }
}

/****************************
 ADMIN MENU
***************************/





add_action('admin_menu', function () {


// Menú principal
add_menu_page(
    'SEO Taxonomy',
    'SEO Taxonomy',
    'manage_options',
    'seo-system',
    'seo_home_page',
    'dashicons-networking',
    30
);


// Home
add_submenu_page(
    'seo-system',
    'Home',
    'Home',
    'manage_options',
    'seo-home',
    'seo_home_page'
);
    // Products
    add_submenu_page(
        'seo-system',
        'Product Admin',
        'Products',
        'manage_options',
        'product-page-admin',
        'seo_product_admin_callback'
    );

    // Categories
    add_submenu_page(
        'seo-system',
        'Category Admin',
        'Categories',
        'manage_options',
        'category-seo-admin',
        'seo_category_admin_callback'
    );

    // Etiquetas / vocabulario semántico de producto
    add_submenu_page(
        'seo-system',
        'Etiquetas y vocabulario',
        'Etiquetas',
        'manage_options',
        'seo-tags-vocabulary',
        'seo_tags_vocabulary_admin_page'
    );

    // Pages
    add_submenu_page(
        'seo-system',
        'Page Admin',
        'Pages',
        'manage_options',
        'seo-page-admin',
        'seo_page_admin_callback'
    );

    // Posts
    // Se registra siempre. Si el modulo no esta disponible, el callback muestra
    // el motivo exacto en vez de ocultar silenciosamente la opcion del menu.
    add_submenu_page(
        'seo-system',
        'Post Admin',
        'Posts',
        'manage_options',
        'seo-post-editor',
        'seo_post_admin_callback'
    );

    // Pictures
    add_submenu_page(
        'seo-system',
        'Pictures',
        'Pictures',
        'manage_options',
        'seo-pictures-admin',
        'seo_pictures_admin_page'
    );

    // Reports
    add_submenu_page(
        'seo-system',
        'Reports',
        'Reports',
        'manage_options',
        'seo-reports',
        'seo_reports_page'
    );

    // Tools
    add_submenu_page(
        'seo-system',
        'Herramientas',
        'Herramientas',
        'manage_options',
        'seo-tools',
        'seo_tools_page'
    );
    
    // Páginas ocultas (accesibles desde Tools)
add_submenu_page(null, 'Taxonomy', 'Taxonomy', 'manage_options', 'seo-taxonomy', 'seo_taxonomy_page');
add_submenu_page(null, 'Templates', 'Templates', 'manage_options', 'seo-templates', 'seo_templates_page');
add_submenu_page(null, 'Search Settings', 'Search', 'manage_options', 'seo-search-settings', 'seo_search_settings_page');
add_submenu_page(null, 'Redirects', 'Redirects', 'manage_options', 'seo-menu-redirects', 'seo_menu_manager_redirects_page');
add_submenu_page(null, 'Marketing', 'Marketing', 'manage_options', 'seo-menu-marketing', 'seo_menu_manager_marketing_page');
add_submenu_page(null, 'SEO Data Table', 'Data Table', 'manage_options', 'seo-data-table', 'seo_data_table_page');
add_submenu_page(null, 'Clean DB', 'Clean DB', 'manage_options', 'seo-clean-db', 'seo_clean_db_page');
add_submenu_page(null, 'Import / Export', 'Import / Export', 'manage_options', 'seo-import-export', 'seo_import_export_page');
add_submenu_page(null, 'Menu Manager', 'Menu Manager', 'manage_options', 'seo-menu-manager', 'seo_menu_manager_page');
add_submenu_page( null, 'FAQs', 'FAQs', 'manage_options', 'seo-faq','seo_faq_page');
add_submenu_page( null, 'Server status', 'Server status', 'manage_options', 'seo_server_status','seo_server_status');
add_submenu_page(null,'Plugin Validation','Plugin Validation','manage_options','seo-core-system-test', 'seo_core_system_test');
});

// Elimina el submenú duplicado que WordPress crea para la página principal.
add_action('admin_menu', function () {
    remove_submenu_page('seo-system', 'seo-system');
}, 999);


function seo_tools_page() {
?>
<div class="wrap seo-tools">

    <h1>SEO Taxonomy - Tools</h1>

    <p>Herramientas avanzadas del plugin SEO Taxonomy.</p>

    <div class="seo-tools-grid">

        <?php

        $tools = [

            [
                'title' => 'Taxonomy',
                'icon'  => 'dashicons-networking',
                'page'  => 'seo-taxonomy',
                'desc'  => 'Gestiona la taxonomía SEO.'
            ],

            [
                'title' => 'Templates',
                'icon'  => 'dashicons-media-code',
                'page'  => 'seo-templates',
                'desc'  => 'Plantillas SEO.'
            ],

            [
                'title' => 'Search',
                'icon'  => 'dashicons-search',
                'page'  => 'seo-search-settings',
                'desc'  => 'Configuración del buscador.'
            ],

            [
                'title' => 'Redirects',
                'icon'  => 'dashicons-randomize',
                'page'  => 'seo-menu-redirects',
                'desc'  => 'Gestión de redirecciones.'
            ],

            [
                'title' => 'Marketing',
                'icon'  => 'dashicons-chart-area',
                'page'  => 'seo-menu-marketing',
                'desc'  => 'Herramientas de marketing.'
            ],

            [
                'title' => 'Data Table',
                'icon'  => 'dashicons-table-col-after',
                'page'  => 'seo-data-table',
                'desc'  => 'Vista tipo Excel.'
            ],

            [
                'title' => 'Clean DB',
                'icon'  => 'dashicons-database-remove',
                'page'  => 'seo-clean-db',
                'desc'  => 'Limpieza de base de datos.'
            ],

            [
                'title' => 'Import / Export',
                'icon'  => 'dashicons-migrate',
                'page'  => 'seo-import-export',
                'desc'  => 'Importar y exportar datos.'
            ],


            [
                'title' => 'FAQs',
                'icon'  => 'dashicons-editor-help',
                'page'  => 'seo-faq',
                'desc'  => 'Gestión de preguntas frecuentes.'
            ],

            [
                'title' => 'Estado del servidor',
                'icon'  => 'dashicons-shield',
                'page'  => 'seo_server_status',
                'desc'  => 'Estado del servidor y tests de las funciones'
            ],
            
            [
                'title' => 'Plugin Validation',
                'icon'  => 'dashicons-shield',
                'page'  => 'seo-core-system-test',
                'desc'  => 'Framework de validación y pruebas internas del plugin.'
            ],


            [
                'title' => 'Menu Manager',
                'icon'  => 'dashicons-menu',
                'page'  => 'seo-menu-manager',
                'desc'  => 'Gestión de menús.'
            ]
        ];

        foreach ($tools as $tool) :
        ?>

            <a class="seo-tool-card-link" href="<?php echo esc_url(admin_url('admin.php?page=' . $tool['page'])); ?>">

                <div class="seo-tool-card">

                    <span class="dashicons <?php echo esc_attr($tool['icon']); ?>"></span>

                    <h2><?php echo esc_html($tool['title']); ?></h2>

                    <p><?php echo esc_html($tool['desc']); ?></p>

                    <span class="button button-primary">
                        Abrir
                    </span>

                </div>

            </a>

        <?php endforeach; ?>

    </div>

</div>
<?php
}


/**
 * Identidad visual del menú principal de SEO Taxonomy.
 *
 * Se carga en todo el administrador para que el plugin pueda localizarse
 * visualmente sin necesidad de leer cada entrada del menú lateral.
 */
add_action('admin_head', function () {
?>
<style id="seo-taxonomy-admin-menu-identity">

/* Icono principal siempre reconocible. */
#adminmenu #toplevel_page_seo-system > a .wp-menu-image::before{
    color:#35d0ba !important;
    text-shadow:0 0 10px rgba(53,208,186,.35);
}

/* Pequeño indicador visual junto al nombre del plugin. */
#adminmenu #toplevel_page_seo-system > a .wp-menu-name::after{
    content:"";
    display:inline-block;
    width:7px;
    height:7px;
    margin-left:8px;
    vertical-align:middle;
    background:#35d0ba;
    border-radius:50%;
    box-shadow:0 0 0 3px rgba(53,208,186,.14);
}

/* Refuerzo visual al pasar el ratón. */
#adminmenu #toplevel_page_seo-system:hover > a,
#adminmenu #toplevel_page_seo-system > a:focus{
    color:#fff !important;
    background:#174f4b !important;
}

/* Estado activo del plugin. */
#adminmenu #toplevel_page_seo-system.wp-has-current-submenu > a.wp-has-current-submenu,
#adminmenu #toplevel_page_seo-system.current > a.current{
    color:#fff !important;
    background:#126e65 !important;
}

/* Mantiene el icono destacado también cuando el menú está activo. */
#adminmenu #toplevel_page_seo-system.wp-has-current-submenu > a .wp-menu-image::before,
#adminmenu #toplevel_page_seo-system.current > a .wp-menu-image::before{
    color:#bffaf1 !important;
}

@media (prefers-reduced-motion: no-preference){
    #adminmenu #toplevel_page_seo-system > a .wp-menu-image::before,
    #adminmenu #toplevel_page_seo-system > a .wp-menu-name::after{
        transition:color .15s ease, background-color .15s ease, box-shadow .15s ease;
    }
}

</style>
<?php
});


add_action('admin_head', function () {

    /*
     * No se comprueba $screen->id porque WordPress construye ese ID a partir
     * del título del menú y puede no coincidir con "seo-system_page_*".
     * El parámetro page contiene directamente el slug registrado.
     */
    $page = isset($_GET['page'])
        ? sanitize_key(wp_unslash($_GET['page']))
        : '';

    if (!in_array($page, ['seo-system', 'seo-home', 'seo-tools'], true)) {
        return;
    }
?>
<style id="seo-taxonomy-admin-cards">

.seo-home,
.seo-tools{
    box-sizing:border-box;
}

.seo-tools-grid{
    display:grid !important;
    grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    align-items:stretch;
    gap:20px;
    width:100%;
    max-width:1600px;
    margin-top:30px;
}

.seo-tools-grid > *{
    min-width:0;
}

.seo-tool-card-link{
    display:flex;
    height:100%;
    color:inherit;
    text-decoration:none;
}

.seo-tool-card-link:hover,
.seo-tool-card-link:focus{
    color:inherit;
    text-decoration:none;
}

.seo-tool-card-link:focus{
    outline:2px solid #2271b1;
    outline-offset:3px;
    box-shadow:none;
}

.seo-tool-card{
    display:flex;
    flex-direction:column;
    width:100%;
    min-height:250px;
    box-sizing:border-box;
    background:#fff;
    border:1px solid #dcdcde;
    border-radius:8px;
    padding:25px;
    text-align:center;
    box-shadow:0 1px 3px rgba(0,0,0,.08);
    cursor:pointer;
    transition:transform .15s ease,border-color .15s ease,box-shadow .15s ease;
}

.seo-tool-card:hover{
    transform:translateY(-2px);
    border-color:#2271b1;
    box-shadow:0 4px 12px rgba(0,0,0,.12);
}

.seo-tool-card .dashicons{
    flex:0 0 auto;
    width:42px;
    height:42px;
    margin:0 auto 15px;
    color:#2271b1;
    font-size:42px;
    line-height:1;
}

.seo-tool-card h2{
    margin:10px 0;
    font-size:18px;
    line-height:1.3;
}

.seo-tool-card p{
    flex:1 1 auto;
    min-height:48px;
    margin:0 0 15px;
    color:#646970;
    line-height:1.55;
}

.seo-tool-card .button{
    align-self:center;
    margin-top:auto;
    pointer-events:none;
}

.seo-plugin-definition{
    box-sizing:border-box;
    max-width:1600px;
    margin:25px 0 30px;
    padding:25px;
    background:#fff;
    border:1px solid #dcdcde;
    border-left:5px solid #2271b1;
    border-radius:8px;
    box-shadow:0 1px 3px rgba(0,0,0,.08);
}

.seo-plugin-definition h2{
    margin-top:0;
}

.seo-plugin-definition p{
    max-width:1200px;
    font-size:15px;
    line-height:1.8;
}

@media screen and (max-width:782px){
    .seo-tools-grid{
        grid-template-columns:1fr;
        gap:14px;
        margin-top:20px;
    }

    .seo-tool-card{
        min-height:0;
        padding:20px;
    }
}

</style>
<?php
});



function seo_home_page() {
?>

<div class="wrap seo-home">

    <div class="notice notice-info" style="padding:25px;margin:20px 0;border-left:5px solid #2271b1;">

        <h1 style="margin-top:0;">🚀 Bienvenido a SEO Taxonomy</h1>

        <p style="font-size:15px;line-height:1.8;max-width:1100px;">

            <strong>SEO Taxonomy</strong> es una plataforma integral para administrar el SEO de WordPress desde un único panel de control.

            Permite gestionar el SEO de <strong>productos, categorías, páginas, posts e imágenes</strong>, generar contenido automáticamente,
            crear plantillas SEO, analizar informes y utilizar herramientas avanzadas para automatizar tareas de optimización.

        </p>

        <p style="margin-top:20px;">

            Desde este panel puedes acceder rápidamente a todas las funciones principales del plugin.

        </p>

    </div>

<div class="seo-plugin-definition">

    <h2>📚 Definición del plugin</h2>

    <p>
        SEO Taxonomy organiza la arquitectura SEO del sitio, las páginas estratégicas,
        las categorías de producto y sus relaciones. También permite revisar la
        calidad del contenido, detectar errores y aplicar mejoras SEO asistidas por IA.
    </p>

    <details style="margin-top:15px;padding:14px 16px;background:#fff;border:1px solid #dcdcde;border-radius:4px;">

        <summary style="cursor:pointer;font-weight:600;">
            💡 Procedimiento recomendado para crear contenido
        </summary>

        <div style="margin-top:14px;">

            <p>
                Sigue este orden para crear e integrar correctamente una nueva página
                dentro de la arquitectura SEO:
            </p>

            <ol style="margin-left:22px;line-height:1.8;">

                <li>
                    <strong>Crear y publicar la página.</strong><br>
                    Añade el título, el contenido, las imágenes y los datos necesarios
                    antes de incorporarla a la estructura SEO.
                </li>

                <li>
                    <strong>Asignar la plantilla correspondiente.</strong><br>
                    Selecciona la plantilla adecuada según el rol de la página:
                    cluster, hub primario, hub secundario u otro tipo disponible.
                </li>

                <li>
                    <strong>Asociar la página desde Taxonomía.</strong><br>
                    Define su posición dentro de la arquitectura y establece las
                    relaciones con los demás nodos SEO.
                </li>

                <li>
                    <strong>Seleccionar categorías representativas.</strong><br>
                    Cuando sea necesario, elige las categorías que se mostrarán en
                    la plantilla con fines informativos, comerciales o de navegación.
                </li>

                <li>
                    <strong>Asignar productos a las categorías.</strong><br>
                    Comprueba que las categorías relacionadas contienen productos
                    relevantes y correctamente clasificados.
                </li>

                <li>
                    <strong>Revisar la puntuación SEO y las mejoras con IA.</strong><br>
                    Analiza el contenido, corrige las recomendaciones detectadas y
                    aplica únicamente las mejoras que sean coherentes con la página.
                </li>

            </ol>

            <p style="margin-bottom:0;">
                <strong>Importante:</strong> publicar una página no la incorpora
                automáticamente a la arquitectura SEO. La plantilla, el rol y sus
                relaciones deben configurarse expresamente.
            </p>

        </div>

    </details>

</div>

    <div class="seo-tools-grid">

        <?php

        $home_cards = [

            [
                'title' => 'Products',
                'icon'  => 'dashicons-products',
                'page'  => 'product-page-admin',
                'desc'  => 'Gestiona el SEO de todos los productos WooCommerce. Edita títulos, descripciones, IA, imágenes y datos SEO desde una única pantalla.'
            ],

            [
                'title' => 'Categories',
                'icon'  => 'dashicons-category',
                'page'  => 'category-seo-admin',
                'desc'  => 'Optimiza categorías, taxonomías y estructuras SEO para mejorar la organización y el posicionamiento.'
            ],

            [
                'title' => 'Pages',
                'icon'  => 'dashicons-admin-page',
                'page'  => 'seo-page-admin',
                'desc'  => 'Administra las páginas de WordPress y su información dentro de la arquitectura SEO.'
            ],

            [
                'title' => 'Posts',
                'icon'  => 'dashicons-admin-post',
                'page'  => 'seo-post-editor',
                'desc'  => 'Crea y edita posts de WordPress y relaciona cada entrada con categorías de producto mediante SEO Relations.'
            ],

            [
                'title' => 'Pictures',
                'icon'  => 'dashicons-format-image',
                'page'  => 'seo-pictures-admin',
                'desc'  => 'Gestiona títulos, atributos ALT, descripciones y metadatos de todas las imágenes del sitio.'
            ],

            [
                'title' => 'Reports',
                'icon'  => 'dashicons-chart-area',
                'page'  => 'seo-reports',
                'desc'  => 'Consulta informes y estadísticas para conocer el estado SEO de tu proyecto y detectar mejoras.'
            ],

            [
                'title' => 'Herramientas',
                'icon'  => 'dashicons-admin-tools',
                'page'  => 'seo-tools',
                'desc'  => 'Accede a las herramientas avanzadas: Taxonomy, Templates, Search Settings, Redirects, Marketing, Data Table, Clean DB, Import / Export, Semantic Learning y Menu Manager.'
            ]
        ];

        foreach ($home_cards as $card) :
        ?>

            <a class="seo-tool-card-link" href="<?php echo esc_url(admin_url('admin.php?page=' . $card['page'])); ?>">

                <div class="seo-tool-card">

                    <span class="dashicons <?php echo esc_attr($card['icon']); ?>"></span>

                    <h2><?php echo esc_html($card['title']); ?></h2>

                    <p><?php echo esc_html($card['desc']); ?></p>

                    <span class="button button-primary">
                        Abrir
                    </span>

                </div>

            </a>

        <?php endforeach; ?>

    </div>

    <div class="notice notice-warning" style="padding:20px;margin-top:40px;">

        <h2>🧰 Herramientas avanzadas</h2>

        <p>

            El menú <strong>Herramientas</strong> reúne todas las utilidades avanzadas del plugin.

            Desde allí podrás configurar plantillas SEO, administrar redirecciones,
            importar o exportar datos, mantener la base de datos, gestionar taxonomías,
            configurar el buscador interno y acceder al resto de módulos especializados.

        </p>

        <p>

            La mayoría de usuarios utilizarán diariamente las opciones
            <strong>Products</strong>, <strong>Categories</strong>,
            <strong>Pages</strong>, <strong>Posts</strong>, <strong>Pictures</strong> y
            <strong>Reports</strong>, mientras que <strong>Tools</strong>
            concentra las funciones de configuración y mantenimiento.

        </p>

    </div>

</div>

<?php
}
/* TODO: Reorganize Herramientas into: Arquitectura, SEO, Datos, IA, Diagnóstico */