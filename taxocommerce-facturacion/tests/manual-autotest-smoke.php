<?php
/**
 * Isolated Billing Autotest regression: no WordPress account, no network or writes.
 */
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_FACTURACION_VERSION', '0.1.1' );
define( 'SEO_FACTURAS_PATH', dirname( __DIR__, 2 ) . '/includes/facturas/' );

function wc_get_order() { return null; }
function get_option( $key, $default = false ) {
    global $billing_smoke_saved_options;
    return array_key_exists( $key, $billing_smoke_saved_options ) ? $billing_smoke_saved_options[ $key ] : $default;
}
function has_action( $hook, $callback = false ) {
    return in_array( $hook, array(
        'admin_post_seo_facturas_generate',
        'admin_post_seo_facturas_download',
        'admin_post_seo_facturas_email',
        'woocommerce_payment_complete',
        'woocommerce_order_status_changed',
        'woocommerce_view_order',
    ), true ) ? 10 : false;
}
function has_filter( $hook, $callback = false ) {
    return 'render_block_woocommerce/cart' === $hook ? 20 : false;
}
function update_option() { throw new RuntimeException( 'Autotest tried to update options' ); }
function wp_insert_post() { throw new RuntimeException( 'Autotest tried to create content' ); }
function wp_mail() { throw new RuntimeException( 'Autotest tried to send an email' ); }
function wp_remote_get() { throw new RuntimeException( 'Autotest tried to use the network' ); }
function wp_remote_post() { throw new RuntimeException( 'Autotest tried to use the network' ); }
function wp_schedule_event() { throw new RuntimeException( 'Autotest tried to schedule a process' ); }

class WooCommerce {}
class SEO_Facturas_Settings {}
class SEO_Facturas_Admin {
    public static function handle_generate() {}
    public static function handle_download() {}
    public static function handle_email() {}
}
class SEO_Facturas_Install {
    public static function table_name() { return 'wp_seo_factura_documents'; }
}
class SEO_Facturas_Documents {}
class SEO_Facturas_Snapshot {}
class SEO_Facturas_Tax {}
class SEO_Facturas_PDF {}
class SEO_Facturas_Quotes { public static function append_to_cart_block() {} }
class SEO_Facturas_WooCommerce {
    public static function on_payment_complete() {}
    public static function on_status_changed() {}
}
class SEO_Facturas_Customer_Documents { public static function render_for_order() {} }

final class Taxocommerce_Billing_Fake_DB {
    public $calls = 0;
    public $found = true;
    public function prepare( $query, $table ) {
        if ( 'SHOW TABLES LIKE %s' !== $query || 'wp_seo_factura_documents' !== $table ) {
            throw new RuntimeException( 'Autotest queried unexpected data' );
        }
        return 'schema_lookup';
    }
    public function get_var( $query ) {
        if ( 'schema_lookup' !== $query ) {
            throw new RuntimeException( 'Autotest queried unexpected data' );
        }
        $this->calls++;
        return $this->found ? 'wp_seo_factura_documents' : null;
    }
}
$wpdb = new Taxocommerce_Billing_Fake_DB();
$billing_smoke_saved_options = array(
    'seo_facturas_company_settings' => array( 'enabled' => 1 ),
    'seo_facturas_tax_settings' => array(),
    'seo_facturas_invoice_settings' => array(),
    'seo_facturas_proforma_settings' => array(),
    'seo_facturas_quote_settings' => array(),
);
require dirname( __DIR__ ) . '/includes/class-taxocommerce-billing-autotest.php';

function billing_smoke_assert( $ok, $label ) {
    if ( ! $ok ) {
        fwrite( STDERR, 'FAIL: ' . $label . PHP_EOL );
        exit( 1 );
    }
    echo 'PASS: ' . $label . PHP_EOL;
}

$rows = TaxoCommerce_Billing_Autotest::run();
$errors = array_filter( $rows, static function ( $row ) { return 'error' === $row['status']; } );
billing_smoke_assert( count( $rows ) >= 10 && 0 === count( $errors ), 'available components produce no functional errors' );
billing_smoke_assert( 1 === $wpdb->calls, 'one read-only metadata query; no order queries' );
$codes = array_unique( array_column( $rows, 'status' ) );
billing_smoke_assert( ! in_array( 'error', $codes, true ), 'correct classification of available services' );

$wpdb->found = false;
$billing_smoke_saved_options['seo_facturas_company_settings']['enabled'] = 0;
unset( $billing_smoke_saved_options['seo_facturas_quote_settings'] );
$rows = TaxoCommerce_Billing_Autotest::run();
$database_issue = false;
$config_warning = false;
foreach ( $rows as $item ) {
    if ( 'Persistencia' === $item['group'] && 'error' === $item['status'] ) {
        $database_issue = true;
    }
    if ( 'Configuración' === $item['group'] && 'warning' === $item['status'] ) {
        $config_warning = true;
    }
}
billing_smoke_assert( $database_issue && $config_warning, 'missing schema is error while disabled/unset options are warnings' );
billing_smoke_assert( 2 === $wpdb->calls, 'no extra queries or database writes' );

$main   = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-facturacion.php' );
$admin  = file_get_contents( dirname( __DIR__ ) . '/includes/class-seo-facturas-admin.php' );
$manual = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-billing-manual.php' );
billing_smoke_assert(
    strpos( $admin, "'manual'    => 'Manual'" ) !== false &&
    strpos( $admin, "'autotest'  => 'Autotest'" ) !== false &&
    strpos( $admin, 'TaxoCommerce_Billing_Autotest::render();' ) !== false &&
    strpos( $admin, 'TaxoCommerce_Billing_Manual::render();' ) !== false &&
    strpos( $main, 'class-taxocommerce-billing-autotest.php' ) !== false &&
    strpos( $main, 'taxocommerce_facturacion_render_page' ) !== false &&
    strpos( $main, 'taxocommerce_facturacion_legacy_admin_links' ) !== false &&
    strpos( $manual, 'Manual de usuario' ) !== false &&
    strpos( $manual, 'Manual técnico' ) !== false,
    'both documentation and autotest routed inside existing Billing admin screen'
);
echo "TaxoCommerce Billing Manual and Autotest smoke passed." . PHP_EOL;
