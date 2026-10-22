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
function get_option($key, $default = false) { return false; }
function absint($number) { return abs((int)$number); }
function get_queried_object_id() { return 5; }
function get_queried_object() { return (object)array('term_id'=>1); }
function get_query_var($name) { return ''; }

class TaxoCommerce_Templates {
    const ENABLE_OPTION = 'tct_test_enabled';
    public static function installed() { return false; }
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

echo "OK: Smoke tests TaxoCommerce Templates\n";
