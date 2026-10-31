<?php
/**
 * Regression for standalone Billing's admin route when the monolith owns the
 * historical SEO_Facturas_Admin class. No WordPress installation is required.
 */
define( 'ABSPATH', __DIR__ );
function plugin_dir_path( $path ) { return dirname( $path ) . '/'; }
function plugin_dir_url( $path ) { return 'https://example.invalid/plugins/billing/'; }
function add_action( $hook, $callback, $priority = 10 ) { return true; }
function add_filter( $hook, $callback, $priority = 10 ) { return true; }
function did_action( $hook ) { return 0; }
function taxocommerce_core() { return null; }
function current_user_can( $capability ) { return 'manage_options' === $capability; }
function esc_html__( $message, $domain = '' ) { return $message; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function admin_url( $path = '' ) { return 'https://example.invalid/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_field( $action ) { echo '<input type="hidden" name="nonce" value="safe">'; }
function submit_button( $label, $type = 'primary', $name = 'submit', $wrap = true ) {
    echo '<button type="submit">' . esc_html( $label ) . '</button>';
}
function wp_die( $message ) { throw new RuntimeException( $message ); }
function wp_enqueue_media() {}
function wp_enqueue_style( $name, $url, $deps, $version ) {}
$tc_billing_menu = array();
function add_submenu_page( $parent, $title, $label, $capability, $slug, $callback ) {
    global $tc_billing_menu;
    $tc_billing_menu[] = array( $parent, $slug, $callback );
}
class SEO_Facturas_Admin {
    public static $renders = 0;
    public static function render_page() {
        ++self::$renders;
        echo '<div class="wrap seo-facturas-wrap"><h1>Legacy Billing</h1>';
        echo '<nav class="nav-tab-wrapper"><a href="?page=seo-facturas">Legacy link</a></nav>';
        echo '<section id="legacy-billing-form">Existing invoice functionality</section></div>';
    }
}

require dirname( __DIR__ ) . '/taxocommerce-facturacion.php';

function billing_route_check( $ok, $message ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
        exit( 1 );
    }
    echo 'PASS: ' . $message . PHP_EOL;
}
taxocommerce_facturacion_register_menu();
billing_route_check(
    1 === count( $tc_billing_menu ) &&
    'taxocommerce-facturacion' === $tc_billing_menu[0][1] &&
    'taxocommerce_facturacion_render_page' === $tc_billing_menu[0][2],
    'Billing registers its own slug, not the monolith page'
);
$family = taxocommerce_facturacion_family_catalog( array() );
billing_route_check(
    strpos( $family['facturacion']['admin_url'], 'page=taxocommerce-facturacion' ) !== false,
    'Core extension catalog directs users to standalone Billing'
);

$_GET = array( 'page' => 'taxocommerce-facturacion', 'tab' => 'company' );
ob_start();
taxocommerce_facturacion_render_page();
$html = ob_get_clean();
billing_route_check(
    1 === SEO_Facturas_Admin::$renders &&
    8 === substr_count( $html, '<a class="nav-tab ' ) &&
    strpos( $html, 'tab=manual' ) !== false &&
    strpos( $html, 'tab=autotest' ) !== false &&
    strpos( $html, 'id="legacy-billing-form"' ) !== false &&
    false === strpos( $html, '?page=seo-facturas&tab=' ),
    'legacy document screen remains available under eight standalone navigation tabs'
);
$css = file_get_contents( dirname( __DIR__ ) . '/includes/assets/admin.css' );
billing_route_check(
    strpos( $css, '.taxocommerce-billing-shell > .taxocommerce-billing-tabs' ) !== false &&
    strpos( $css, '.taxocommerce-billing-existing-settings > .seo-facturas-wrap > .nav-tab-wrapper' ) !== false &&
    strpos( $css, 'flex-wrap: wrap' ) !== false,
    'only legacy duplicate tabs are hidden; standalone navigation wraps'
);

foreach ( array( 'manual' => 'Manual de usuario', 'autotest' => 'Ejecutar autotest' ) as $tab => $needle ) {
    $_GET['tab'] = $tab;
    ob_start();
    taxocommerce_facturacion_render_page();
    $html = ob_get_clean();
    billing_route_check(
        1 === SEO_Facturas_Admin::$renders &&
        strpos( $html, $needle ) !== false &&
        8 === substr_count( $html, '<a class="nav-tab ' ) &&
        strpos( $html, 'page=taxocommerce-facturacion' ) !== false,
        $tab . ' renders without calling the monolith controller'
    );
}
echo "Billing standalone admin routing smoke passed." . PHP_EOL;
