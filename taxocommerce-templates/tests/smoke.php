<?php
/**
 * Smoke tests sin WordPress para las reglas no dependientes de la base de datos.
 * Ejecutar: php taxocommerce-templates/tests/smoke.php
 */
define('ABSPATH', __DIR__);
define('WP_CONTENT_DIR', __DIR__);
function trailingslashit($path) { return rtrim($path, '/\\') . '/'; }

class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_message() {
        return $this->message;
    }
}

function is_wp_error($value) {
    return $value instanceof WP_Error;
}
function sanitize_key($key) {
    return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$key));
}
function sanitize_file_name($name) {
    return preg_replace('/[^A-Za-z0-9._-]/', '', basename((string)$name));
}
function apply_filters($name, $value) {
    return $value;
}
function is_404() { return $GLOBALS['scenario'] === 'not_found'; }
function is_front_page() { return $GLOBALS['scenario'] === 'front' || $GLOBALS['scenario'] === 'front_posts'; }
function is_home() { return $GLOBALS['scenario'] === 'home' || $GLOBALS['scenario'] === 'front_posts'; }
function is_search() { return $GLOBALS['scenario'] === 'search'; }
function is_category() { return $GLOBALS['scenario'] === 'category'; }
function is_tag() { return false; }
function is_tax() { return false; }
function is_attachment() { return false; }
function is_singular() { return false; }
function is_author() { return false; }
function is_date() { return false; }
function is_post_type_archive() { return false; }
function is_archive() { return false; }
function is_order_received_page() { return $GLOBALS['scenario'] === 'thankyou'; }
function is_checkout() { return $GLOBALS['scenario'] === 'checkout' || $GLOBALS['scenario'] === 'thankyou'; }
function is_cart() { return $GLOBALS['scenario'] === 'cart'; }
function is_shop() { return $GLOBALS['scenario'] === 'shop'; }
function is_account_page() { return $GLOBALS['scenario'] === 'account'; }
function is_paged() { return false; }
function get_option($key, $default = false) { return isset($GLOBALS['test_options'][$key]) ? $GLOBALS['test_options'][$key] : false; }
function get_post_meta($id, $key, $single) { return isset($GLOBALS['test_market']) ? $GLOBALS['test_market'] : 'ES'; }
function get_term_meta($id, $key, $single) { return isset($GLOBALS['test_market']) ? $GLOBALS['test_market'] : 'ES'; }
function absint($number) { return abs((int)$number); }
function get_queried_object_id() { return 5; }
function get_queried_object() { return (object)array('term_id'=>1); }
function get_query_var($name) { return ''; }

class TaxoCommerce_Templates {
    const ENABLE_OPTION = 'tct_test_enabled';
    const REVIEW_CONSENT_OPTION = 'tct_test_review_consent';
    public static function installed() { return false; }
    public static function policies_enabled() {
        return 1 === (int) get_option(self::ENABLE_OPTION, 0)
            && 1 === (int) get_option(self::REVIEW_CONSENT_OPTION, 0);
    }
}

define('TCT_DIR', dirname(__DIR__) . '/');
require_once TCT_DIR . 'includes/class-tct-router.php';
require_once TCT_DIR . 'includes/class-tct-request-registry.php';
require_once TCT_DIR . 'includes/class-tct-template-editor.php';

function tct_assert($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAILED: " . $message . "\n");
        exit(1);
    }
}

tct_assert(count(TCT_Router::catalog()) >= 25, 'Las rutas legadas deben registrarse.');
tct_assert(TCT_Router::valid_filename('template-product.php'), 'Aceptar PHP simple.');
tct_assert(!TCT_Router::valid_filename('../evil.php'), 'Rechazar traversal.');
tct_assert(!TCT_Router::valid_filename('bad.php.jpg'), 'Rechazar doble extension no PHP.');
tct_assert(TCT_Router::variant_name('template-product.php', 'mobile') === 'template-product-mobile.php', 'Derivar secundaria movil.');
tct_assert(TCT_Router::variant_name('template-product-mobile.php', 'desktop') === '', 'No crear variante de variante.');
tct_assert(TCT_Router::path('template-default.php') !== '', 'Encontrar archivo PHP integrado.');
tct_assert(TCT_Template_Editor::validate_php("<?php\necho 2;\n") === true, 'Aceptar PHP correcto.');
tct_assert(is_wp_error(TCT_Template_Editor::validate_php('<?php function invalid( {')), 'Rechazar PHP sintacticamente incorrecto.');
tct_assert(TCT_Template_Editor::source('template-helpers.php') !== '', 'Permitir consultar Helpers integrados desde la biblioteca.');
tct_assert(TCT_Template_Editor::source('../evil.php') === '', 'Bloquear traversal tambien en el editor.');
tct_assert(in_array('template-product-mobile.php', TCT_Router::available_files(true), true), 'La biblioteca completa debe incluir variantes.');

$defs = TCT_Request_Registry::definitions(false);
foreach (array('front_page','blog_index','single_post','page','taxonomy_category','comments',
               'error_404','archive','single_product','checkout','thankyou','index') as $key) {
    tct_assert(isset($defs[$key]), 'Catalogo no contiene ' . $key);
}

$cases = array(
    'not_found'   => array('error_404','404'),
    'front'       => array('front_page','page'),
    'front_posts' => array('front_page','blog_index'),
    'home'        => array('blog_index'),
    'cart'        => array('cart','page'),
    'checkout'    => array('checkout','page'),
    'thankyou'    => array('thankyou','checkout','page'),
    'search'      => array('search'),
    'shop'        => array('shop','archive_type_product'),
);
foreach ($cases as $name => $expected) {
    $GLOBALS['scenario'] = $name;
    $found = TCT_Request_Registry::candidates();
    foreach ($expected as $key) {
        tct_assert(in_array($key,$found,true), 'Falta ruta ' . $key . ' en el escenario ' . $name);
    }
    tct_assert(end($found) === 'index', 'Mantener ultimo fallback index: ' . $name);
    tct_assert(TCT_Request_Registry::resolve('/theme/default.php') === '/theme/default.php',
        'Mantener plantilla del tema con el nuevo router desactivado.');
}
tct_assert(
    TCT_Request_Registry::woocommerce_key('loop/add-to-cart.php') !==
    TCT_Request_Registry::woocommerce_key('checkout/thankyou.php'),
    'Cada plantilla interna WooCommerce debe tener su propia clave.'
);


/* Las plantillas originales se incorporan junto con sus secundarias
   y recursos; no se aceptan sustitutos visuales genericos. */
$required_files = array(
    'template-helpers.php','header.php','footer.php','styles-template.css',
    'template-cluster.php','template-cluster-mobile.php','template-cluster-desktop.php',
    'template-hub-primary.php','template-hub-primary-mobile.php','template-hub-primary-desktop.php',
    'template-hub-secondary.php','template-hub-secondary-mobile.php','template-hub-secondary-desktop.php',
    'template-landing.php','template-landing-mobile.php','template-landing-desktop.php',
    'template-front.php','template-front-mobile.php','template-front-desktop.php',
    'template-page.php','template-page-mobile.php','template-page-desktop.php',
    'template-post.php','template-post-mobile.php','template-post-desktop.php',
    'template-product.php','template-product-mobile.php','template-product-desktop.php',
    'template-category.php','template-category-mobile.php','template-category-desktop.php',
    'template-shop.php','template-shop-mobile.php','template-shop-desktop.php',
    'template-cart.php','template-cart-mobile.php','template-cart-desktop.php',
    'template-checkout.php','template-thankyou.php','template-myaccount.php',
    'template-blog.php','template-blog-mobile.php','template-blog-desktop.php',
    'template-blog-category.php','template-404.php',
    'email-processing.php','email-completed.php','email-refunded.php','email-cancelled.php',
    'assets/blog.css','assets/cart-mobile.css','assets/cart-desktop.css'
);
foreach ($required_files as $file) {
    tct_assert(is_file(TCT_DIR . 'templates/' . $file), 'La biblioteca nueva carece de ' . $file);
}
tct_assert(count((array)glob(TCT_DIR . 'templates/*.php')) >= 75,
    'Falta parte de la biblioteca PHP del plugin original.');
$routes = TCT_Router::catalog();
foreach (array(
    'cluster'=>'template-cluster.php', 'hub_primary'=>'template-hub-primary.php',
    'hub_secondary'=>'template-hub-secondary.php', 'landing'=>'template-landing.php',
    'single_product'=>'template-product.php', 'taxonomy_product_cat'=>'template-category.php',
    'shop'=>'template-shop.php', 'cart'=>'template-cart.php',
    'checkout'=>'template-checkout.php', 'thankyou'=>'template-thankyou.php',
    'blog_index'=>'template-blog.php', 'single_post'=>'template-post.php',
    'front_page'=>'template-front.php'
) as $key=>$file) {
    tct_assert(isset($routes[$key]) && $routes[$key][1] === $file,
        'Ruta antigua no conserva archivo: ' . $key);
}


// All pilot markets must use a single international view and their own native menus.
$pilot_router = file_get_contents(TCT_DIR . 'includes/class-tct-pilot-router.php');
$pilot_view = file_get_contents(TCT_DIR . 'templates/template-pilot-vevor.php');
$pilot_nav = file_get_contents(TCT_DIR . 'templates/template-pilot-navigation.php');
tct_assert(strpos($pilot_view, 'dht_pilot_country_managed_menu_id( $market )') !== false, 'The pilot header must resolve each country menu.');
tct_assert(strpos($pilot_view, "'menu' => \$managed_menu_id") !== false, 'The shared header must render the selected menu.');
tct_assert(strpos($pilot_router, "if (\$market === 'FR')") === false, 'All pilot markets must use one router.');
foreach (array('US','DE','NL','FR') as $market) {
    tct_assert(strpos($pilot_nav, "'TaxoCommerce - ' . \$market") !== false, 'Country menu lookup must be shared.');
}
tct_assert(!is_file(TCT_DIR . 'templates/header-us.php'), 'US must not have a separate header.');
tct_assert(!is_file(TCT_DIR . 'templates/footer-us.php'), 'US must not have a separate footer.');


/* The two international renderers must keep the native country menu compact,
 * including when the legacy router is still active in the monolith. */
$pilot_renderers = array( $pilot_view );
$legacy_pilot = dirname( TCT_DIR ) . '/seo-system/templates/template-pilot-vevor.php';
if ( is_file( $legacy_pilot ) ) {
    $pilot_renderers[] = file_get_contents( $legacy_pilot );
}
foreach ( $pilot_renderers as $view ) {
    tct_assert( strpos( $view, "'menu_id' => 'dht-pilot-country-menu'" ) !== false,
        'International menu must have a stable ID for mobile navigation.' );
    tct_assert( strpos( $view, '.dht-pilot-menu-list--depth-0{display:flex' ) !== false,
        'International desktop navigation must be horizontal.' );
    tct_assert( strpos( $view, 'dht-pilot-nav--expanded' ) !== false
        && strpos( $view, 'dht-pilot-submenu-toggle' ) !== false,
        'Mobile and nested navigation require operable toggle buttons.' );
    tct_assert( strpos( $view, 'dht_pilot_render_category_menu_tree($navigation_roots' ) === false,
        'International header must never render the full category taxonomy as a fallback.' );
    tct_assert( strpos( $view, "'fallback_cb' => false" ) !== false,
        'WordPress must not replace an unavailable country menu with an unrelated menu.' );
}

// Country-aware clusters: ES stays on the original mobile/desktop variants.
// US/DE/NL/FR use one responsive market view with their own WordPress menu.
$cluster_view = file_get_contents( TCT_DIR . 'templates/template-cluster-international.php' );
$cluster_market = file_get_contents( TCT_DIR . 'templates/template-cluster-market.php' );
tct_assert( strpos( $cluster_market, "'_seo_pais_origen'" ) !== false,
    'Cluster market must use the canonical country metadata.' );
tct_assert( strpos( $cluster_view, "'TaxoCommerce - ' . \$market" ) !== false
    && strpos( $cluster_view, "'fallback_cb' => false" ) !== false,
    'International clusters must use only their native country menu.' );
tct_assert( strpos( $cluster_view, "'inLanguage' => \$t['lang']" ) !== false,
    'International cluster schema must use the page market language.' );
tct_assert( strpos( $cluster_view, 'dht_cluster_country_for_page( $id ) === $market' ) !== false
    && strpos( $cluster_view, 'TaxoCommerce_Core_Country::term( $term_id ) === $market' ) !== false,
    'International cluster related content must be scoped to its country.' );
tct_assert( strpos( $cluster_view, 'wp_footer();' ) !== false
    && strpos( $cluster_view, 'wp_head();' ) !== false,
    'International clusters must retain WordPress frontend hooks.' );
foreach ( array( 'desktop', 'mobile' ) as $device ) {
    $variant = file_get_contents( TCT_DIR . 'templates/template-cluster-' . $device . '.php' );
    tct_assert( strpos( $variant, "require __DIR__ . '/template-cluster-international.php';" ) !== false,
        'Both cluster variants must route international pages.' );
    tct_assert( strpos( $variant, 'dht_template_render_header();' ) !== false,
        'Spanish cluster markup must remain unchanged.' );
}
tct_assert( strpos( $cluster_view, 'dht_template_render_dependiente_cta' ) === false
    && strpos( $cluster_view, 'dht_template_shop_url' ) === false
    && strpos( $cluster_view, 'seo_marketing_campaigns_get_public_active' ) === false,
    'International clusters must not expose Spanish sales, assistant, or campaign components.' );
tct_assert( strpos( $cluster_view, 'wp_get_attachment_image_url(' ) === false
    && strpos( $cluster_view, 'media_handle_sideload' ) === false,
    'Cluster presentation must not fetch or store supplier images locally.' );
tct_assert( strpos( $cluster_view, 'dht_cluster_external_image_url(' ) !== false
    && strpos( $cluster_view, "\$host !== \$site_host" ) !== false
    && strpos( $cluster_view, 'dht_template_structural_image_url' ) !== false,
    'External supplier images must preserve visual slots without using local Media.' );
$legacy_cluster = dirname( TCT_DIR ) . '/seo-system/templates/template-cluster-international.php';
if ( is_file( $legacy_cluster ) ) {
    tct_assert( file_get_contents( $legacy_cluster ) === $cluster_view,
        'Legacy and modular international cluster renderers must be identical.' );
}

// FRONT composer pilot: pure configuration contracts and no changes to public PHP.
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim(strip_tags((string)$text)); }
}
require_once TCT_DIR . 'includes/class-tct-front-composer.php';
$front_default = TCT_Front_Composer::defaults('ES');
$front_fr = TCT_Front_Composer::defaults('FR');
tct_assert($front_default['role'] === 'front_page', 'El piloto debe conservar el rol FRONT.');
tct_assert($front_default['market'] === 'ES' && $front_fr['market'] === 'FR', 'Mercado determinado explicitamente.');
tct_assert(count($front_default['desktop']) > 0 && count($front_default['mobile']) > 0, 'Composicion para dos dispositivos.');
tct_assert(!in_array('campaigns',array_column($front_fr['desktop'],'id'),true), 'No inventar campañas españolas en una portada FR.');
tct_assert(count(TCT_Front_Composer::catalog()) >= 10, 'Inventario de modulos disponible.');
$dirty_layout = array(
    array('id'=>'header','enabled'=>true,'title'=>'Test <b>unsafe</b>'),
    array('id'=>'header','enabled'=>false,'title'=>'Duplicado'),
    array('id'=>'php_injection','enabled'=>true,'title'=>'No disponible'),
    array('id'=>'footer','enabled'=>false,'title'=>''),
);
$valid_layout = TCT_Front_Composer::normalize_layout($dirty_layout);
tct_assert(count($valid_layout) === 2 && $valid_layout[0]['id'] === 'header' && $valid_layout[1]['id'] === 'footer',
    'No se admiten modulos duplicados o ajenos al inventario.');
tct_assert($valid_layout[0]['title'] === 'Test unsafe' && $valid_layout[1]['enabled'] === false,
    'Saneamiento de nombre y visibilidad.');
$front_desktop = file_get_contents(TCT_DIR . 'templates/template-front-desktop.php');
$front_mobile = file_get_contents(TCT_DIR . 'templates/template-front-mobile.php');
tct_assert(strpos($front_desktop, 'dht_template_render_header();') !== false
    && strpos($front_mobile, 'dht_template_render_header();') !== false,
    'Las plantillas FRONT existentes deben permanecer intactas.');
$entrypoint = file_get_contents(TCT_DIR . 'taxocommerce-templates.php');
tct_assert(strpos($entrypoint, "TCT_Unified_Manager::render(\$tab);") !== false
    && strpos($entrypoint, "admin_post_tct_front_composer_save") !== false,
    'El compositor sigue conectado al gestor administrativo.');

// Routing controls must use the existing SEO template table, never rewrite PHP.
require_once TCT_DIR . 'includes/class-tct-unified-manager.php';
require_once TCT_DIR . 'includes/class-tct-routing-admin.php';
$manager_sections = TCT_Unified_Manager::sections();
tct_assert(count($manager_sections) === 2
    && isset($manager_sections['composer'], $manager_sections['editor']),
    'Designer must contain only the compositor and PHP library.');
tct_assert(strpos($entrypoint, "'routing'  => 'Enrutamiento'") !== false
    && strpos($entrypoint, "'designer' => 'Diseñador de plantillas'") !== false,
    'The Templates navigation must expose Enrutamiento and Diseñador only.');
$routing = TCT_Routing_Admin::items();
tct_assert(isset($routing['front_page'], $routing['page'], $routing['single_post'],
    $routing['taxonomy_product_cat'], $routing['single_product'], $routing['cluster'],
    $routing['hub_primary'], $routing['hub_secondary'], $routing['error_404']),
    'The routing inventory must include WordPress, WooCommerce and editorial roles.');
tct_assert($routing['cluster']['role'] === 'cluster' && $routing['front_page']['role'] === '',
    'SEO roles and automatic WordPress requests remain distinct.');
// One-click workload filters keep installed routes accessible without showing
// hundreds of native WooCommerce fragments in the everyday admin view.
$empty_map = array();
$sample = array(
    'front_page' => array('label'=>'Portada', 'group'=>'WordPress', 'native'=>'front-page.php', 'role'=>''),
    'cluster' => array('label'=>'Clúster', 'group'=>'TaxoCommerce - roles y rutas', 'native'=>'', 'role'=>'cluster'),
    'wc_partial' => array('label'=>'Mini-cart', 'group'=>'WooCommerce - fragmentos', 'native'=>'cart/mini-cart.php', 'role'=>''),
);
tct_assert(count(TCT_Routing_Admin::visible($sample, $empty_map, 'managed')) === 0,
    'An empty new installation must show zero managed routes.');
$unconfigured = TCT_Routing_Admin::visible($sample, $empty_map, 'pending');
tct_assert(count($unconfigured) === 2 && !isset($unconfigured['wc_partial']),
    'Unconfigured view excludes internal WooCommerce partial files.');
$sample_map = array(
    'front_page' => (object)array('template_file'=>'template-front.php', 'is_active'=>1),
    'cluster' => (object)array('template_file'=>'template-cluster.php', 'is_active'=>0),
);
$managed = TCT_Routing_Admin::visible($sample, $sample_map, 'managed');
tct_assert(count($managed) === 1 && isset($managed['front_page']),
    'Managed view requires an active, nonempty file association.');
tct_assert(count(TCT_Routing_Admin::visible($sample, $sample_map, 'all')) === 3,
    'The full inventory must remain available.');
tct_assert(count(TCT_Routing_Admin::visible($sample, $sample_map, 'pending')) === 1,
    'A disabled saved mapping is available to configure again.');
tct_assert(count(TCT_Routing_Admin::visible($sample, $sample_map, 'managed', 'WordPress', 'front')) === 1,
    'The view preserves family/search filtering.');
$seed_source = file_get_contents(TCT_DIR . 'includes/class-tct-router.php');
tct_assert(strpos($seed_source, "'template_file'           => ''") !== false
    && strpos($seed_source, "'is_active'              => 0") !== false
    && strpos($seed_source, 'if (isset($existing[$key]))') !== false,
    'New installations must seed inactive routes and leave existing assignments untouched.');
$short_inventory = TCT_Routing_Admin::items(false);
tct_assert(isset($short_inventory['front_page'], $short_inventory['cluster'], $short_inventory['single_product']),
    'Working inventory includes the main native requests and all TaxoCommerce roles.');
$router_view = file_get_contents(TCT_DIR . 'includes/class-tct-routing-admin.php');
tct_assert(strpos($router_view, "check_admin_referer( 'tct_save_route_mapping' )") !== false
    && strpos($router_view, "current_user_can( 'manage_options' )") !== false
    && strpos($router_view, 'TCT_Router::path( $file )') !== false,
    'Route writes must use nonce, capability and existing safe library files.');
tct_assert(strpos($router_view, "'template_file' => " . '$file') !== false
    && strpos($router_view, "'is_active' => '' !== " . '$file' . " ? 1 : 0") !== false,
    'Route changes must persist in seo_templates and support WordPress fallback.');
tct_assert(strpos($router_view, 'VEVOR US/DE/NL/FR: ruta especializada con prioridad.') !== false
    && strpos($router_view, 'Enrutador TaxoCommerce desactivado.') !== false,
    'Router must disclose its activation status and country-specific priority.');
tct_assert(strpos($entrypoint, "add_action('admin_post_tct_save_route_mapping'") !== false
    && strpos($entrypoint, "TCT_Association_Audit::render();") !== false
    && strpos($entrypoint, "add_action('admin_post_tct_assign_page'") !== false,
    'New routing must keep the existing audit and page-to-role forms.');
$manager_view = file_get_contents(TCT_DIR . 'includes/class-tct-unified-manager.php');
tct_assert(strpos($manager_view, 'TCT_Front_Composer::render( true )') !== false
    && strpos($manager_view, 'TCT_Template_Editor::render( true )') !== false
    && strpos($manager_view, "TCT_Request_Admin::admin_url( 'routing' )") !== false,
    'Designer must link back to routing and reuse the existing editor.');
$front_composer_class = file_get_contents(TCT_DIR . 'includes/class-tct-front-composer.php');
tct_assert(strpos($front_composer_class, "'designer', 'manager', 'composer', 'editor'") !== false,
    'Composer scripts must load in the Designer tab.');


// Individually stored country-aware designs: identity, conflict resolution,
// per-market/module settings and no automatic frontend takeover.
require_once TCT_DIR . 'includes/class-tct-designs.php';
tct_assert(TCT_Designs::countries(array('ES','FR','ES','XX')) === array('ES','FR'),
    'Country selection must be validated and deduplicated.');
tct_assert(TCT_Designs::name_for('Clúster', array('ES'), 3) !== '',
    'A newly created template receives a recognizable name.');
$design = array(
    'id'=>'one', 'route'=>'cluster', 'name'=>'Clúster ES',
    'countries'=>array('ES','FR'), 'file'=>'template-cluster.php',
    'active'=>false, 'layouts'=>array('FR'=>array(
        'desktop'=>array(array('id'=>'header','enabled'=>true,'title'=>'FR header')),
        'mobile'=>array(array('id'=>'menu','enabled'=>true,'title'=>'FR menu')),
    )),
);
$es_design = TCT_Designs::config($design, 'ES');
$fr_design = TCT_Designs::config($design, 'FR');
tct_assert($es_design['desktop'][0]['id'] === 'header'
    && count($es_design['desktop']) > 1
    && count($fr_design['desktop']) === 1
    && $fr_design['desktop'][0]['title'] === 'FR header',
    'Market layouts must be independent inside one template instance.');
$others = array('other'=>array(
    'route'=>'cluster','active'=>true,'countries'=>array('FR'),
    'file'=>'template-cluster.php',
));
tct_assert(TCT_Designs::can_activate($others, 'one', 'cluster', array('ES','FR'), 'template-cluster.php') !== '',
    'Overlapping active templates for the same route must be rejected.');
tct_assert(TCT_Designs::can_activate($others, 'one', 'cluster', array('ES'), 'template-cluster.php') === '',
    'Disjoint countries for one role must remain valid.');
tct_assert(TCT_Designs::can_activate(array(), 'one', 'cluster', array('ES'), '') !== '',
    'A visual-only composition cannot be activated without PHP renderer.');
tct_assert(TCT_Designs::can_activate(array(), 'one', 'woo_file_abc', array('ES'), 'template-cluster.php') !== '',
    'WooCommerce partials must not claim support from the main template resolver.');
$GLOBALS['test_options'] = array(
    TaxoCommerce_Templates::ENABLE_OPTION => 1,
    TaxoCommerce_Templates::REVIEW_CONSENT_OPTION => 1,
    TCT_Designs::OPTION => array(
    'one'=>array('id'=>'one', 'route'=>'cluster', 'name'=>'Cluster ES',
        'countries'=>array('ES'), 'file'=>'template-cluster.php', 'active'=>true),
    'two'=>array('id'=>'two', 'route'=>'cluster', 'name'=>'Cluster FR',
        'countries'=>array('FR'), 'file'=>'template-cluster.php', 'active'=>true),
    ),
);
$GLOBALS['scenario'] = 'front';
$GLOBALS['test_market'] = 'ES';
tct_assert(TCT_Designs::resolve(array('cluster')) === TCT_Router::path('template-cluster.php')
    && $GLOBALS['tct_template_design_id'] === 'one',
    'A route must resolve to the individually configured design for the current country.');
$GLOBALS['test_market'] = 'FR';
tct_assert(TCT_Designs::resolve(array('cluster')) === TCT_Router::path('template-cluster.php')
    && $GLOBALS['tct_template_design_id'] === 'two',
    'The same SEO role must resolve a different design in France.');
$GLOBALS['test_market'] = 'DE';
tct_assert(TCT_Designs::resolve(array('cluster')) === '',
    'Unconfigured countries must fall back to legacy WordPress routing.');
// On upgrade the legacy ENABLE_OPTION may still be ON, but a new consent
// must be explicitly given before this plugin can affect public pages.
$GLOBALS['test_options'][TaxoCommerce_Templates::REVIEW_CONSENT_OPTION] = 0;
tct_assert(!TaxoCommerce_Templates::policies_enabled(),
    'Old enabled settings must never activate policies after an upgrade.');
tct_assert(TCT_Designs::resolve(array('cluster')) === '',
    'Previously enabled designs must not take effect before explicit new consent.');
$GLOBALS['test_options'][TaxoCommerce_Templates::REVIEW_CONSENT_OPTION] = 1;
tct_assert(TaxoCommerce_Templates::policies_enabled(),
    'Explicit consent re-enables the existing policy switch.');
// The emergency switch must bypass all presentation hooks, including direct
// calls into country-specific rules; saved designs are not modified.
$GLOBALS['test_options'][TaxoCommerce_Templates::ENABLE_OPTION] = 0;
tct_assert(TCT_Designs::resolve(array('cluster')) === '',
    'Disabled policies must not resolve a country-specific design.');
tct_assert(TCT_Designs::get('one')['name'] === 'Cluster ES',
    'Disabling must preserve every existing saved design.');
tct_assert(TCT_Request_Registry::resolve('original-template.php') === 'original-template.php',
    'Disabled policies must preserve the template selected by another plugin.');
tct_assert(TCT_Request_Registry::resolve_comments('comments.php') === 'comments.php',
    'Disabled policies must preserve third-party comment templates.');
tct_assert(TCT_Request_Registry::resolve_woocommerce_partial('previous.php', 'cart/mini-cart.php') === 'previous.php',
    'Disabled policies must preserve WooCommerce template overrides.');
tct_assert(TCT_Request_Registry::resolve_woocommerce_part('previous-part.php', 'cart/mini-cart') === 'previous-part.php',
    'Disabled policies must preserve WooCommerce template parts.');
require_once TCT_DIR . 'includes/class-tct-pilot-router.php';
tct_assert(TCT_Pilot_Router::resolve() === '',
    'Disabled policies must suppress direct calls to the country-specific VEVOR pilot.');
require_once TCT_DIR . 'includes/class-tct-legacy-email-compat.php';
$emails = array('dummy' => (object)array('id' => 'processing'));
tct_assert(TCT_Legacy_Email_Compat::apply($emails) === $emails,
    'Disabled policies must leave WooCommerce email templates unchanged.');
$bootstrap = file_get_contents(TCT_DIR . 'taxocommerce-templates.php');
tct_assert(strpos($bootstrap, "const REVIEW_CONSENT_OPTION = 'taxocommerce_templates_review_consent_v1'") !== false
    && strpos($bootstrap, 'get_option(self::REVIEW_CONSENT_OPTION, 0)') !== false
    && strpos($bootstrap, 'update_option(self::REVIEW_CONSENT_OPTION, 0, false)') !== false
    && strpos($bootstrap, 'update_option(self::REVIEW_CONSENT_OPTION, 1, false)') !== false,
    'A safe upgrade requires fresh opt-in and can always suspend policies.');
tct_assert(strpos($bootstrap, "const ENABLE_OPTION = 'taxocommerce_templates_render_enabled'") !== false
    && strpos($bootstrap, "if (self::policies_enabled()) {") !== false
    && strpos($bootstrap, "add_filter('template_include'") !== false
    && strpos($bootstrap, "add_filter('woocommerce_email_classes'") !== false
    && strpos($bootstrap, "add_filter('wc_get_template'") !== false
    && strpos($bootstrap, "add_filter('wc_get_template_part'") !== false
    && strpos($bootstrap, "add_filter('comments_template'") !== false,
    'All public template and email filters must be registered only while the master switch is enabled.');
tct_assert(strpos($bootstrap, "check_admin_referer('tct_settings')") !== false
    && strpos($bootstrap, "public static function render_policy_control()") !== false
    && strpos($bootstrap, "self::render_policy_control();") !== false
    && strpos($bootstrap, "public static function enqueue_policy_control()") !== false,
    'The master switch must be secured and visible across both admin tabs.');
tct_assert(strpos($bootstrap, "self::guard('tct_settings')") === false,
    'An emergency disable must remain usable even when template tables are damaged.');
unset($GLOBALS['test_options'], $GLOBALS['test_market'], $GLOBALS['tct_template_design_id']);
$design_class = file_get_contents(TCT_DIR . 'includes/class-tct-designs.php');
tct_assert(strpos($design_class, "const OPTION = 'tct_template_designs_v1'") !== false
    && strpos($design_class, "check_admin_referer( 'tct_design_save' )") !== false
    && strpos($design_class, "check_admin_referer( 'tct_design_create' )") !== false
    && strpos($design_class, 'TCT_Front_Composer::normalize_layout') !== false,
    'Designs must have independent durable storage and secured module validation.');
tct_assert(strpos($entrypoint, "admin_post_tct_design_create") !== false
    && strpos($entrypoint, "admin_post_tct_design_save") !== false,
    'New template actions must be registered in the WordPress plugin.');
$registry_code = file_get_contents(TCT_DIR . 'includes/class-tct-request-registry.php');
tct_assert(strpos($registry_code, 'TCT_Designs::resolve($candidates)') !== false
    && strpos($registry_code, '$pilot = TCT_Pilot_Router::resolve();') <
        strpos($registry_code, 'TCT_Designs::resolve($candidates)'),
    'Country-aware designs only execute after the existing protected pilot routing.');
$ui_code = file_get_contents(TCT_DIR . 'includes/class-tct-routing-admin.php');
$manager_code = file_get_contents(TCT_DIR . 'includes/class-tct-unified-manager.php');
$js_code = file_get_contents(TCT_DIR . 'assets/front-composer.js');
tct_assert(strpos($ui_code, 'TCT_Designs::render_routes(') !== false
    && strpos($ui_code, 'TCT_Designs::create_form(') !== false
    && strpos($manager_code, 'TCT_Designs::render( $design_id )') !== false
    && strpos($js_code, "typeof TCTDesignComposer !== 'undefined'") !== false,
    'Edit must load the selected instance and its own market/device module configuration.');

echo "OK: Smoke tests TaxoCommerce Templates\n";
