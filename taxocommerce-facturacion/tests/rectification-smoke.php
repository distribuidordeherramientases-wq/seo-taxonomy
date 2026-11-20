<?php
/**
 * Standalone regression for independent VAT-only rectifications.
 * Run: php taxocommerce-facturacion/tests/rectification-smoke.php
 */
define( 'ABSPATH', __DIR__ );
function __( $text ) { return $text; }
function add_filter( $name, $fn, $priority = 10, $args = 1 ) { return true; }
function add_action( $name, $fn, $priority = 10, $args = 1 ) { return true; }
class WP_Error {
    private $code;
    public function __construct( $code, $message ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
require dirname( __DIR__ ) . '/includes/class-seo-facturas-rectifications.php';
function verify_rectification( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL rectification: ' . $message . PHP_EOL );
        exit( 1 );
    }
}
verify_rectification( SEO_Facturas_Rectifications::same_vat_quarter( '2026-11-16 12:00:00', '2026-11-20 18:00:00' ),
    'Correction of November invoice stays in fourth VAT quarter' );
verify_rectification( ! SEO_Facturas_Rectifications::same_vat_quarter( '2026-12-16 12:00:00', '2027-01-02 10:00:00' ),
    'Cross-quarter VAT correction cannot silently alter a closed accounting period' );
$amounts = SEO_Facturas_Rectifications::replacement_amounts( 621.60, 21 );
verify_rectification( ! is_wp_error( $amounts ), '21% produces a valid correction' );
verify_rectification( 513.72 === $amounts['base_total'] && 107.88 === $amounts['total_tax'] &&
    621.60 === $amounts['total'], 'VAT-inclusive 621.60 remains 621.60; base 513.72 and tax 107.88' );
$lines = SEO_Facturas_Rectifications::replacement_lines( array(
    array( 'name' => 'Power Set BOSCH', 'quantity' => 2, 'total' => 621.60, 'total_tax' => 0.00, 'tax_class' => '' ),
), 21, 513.72, 621.60 );
verify_rectification( ! is_wp_error( $lines ) && 1 === count( $lines ) &&
    256.86 === $lines[0]['unit_net'] && 513.72 === $lines[0]['base'] &&
    107.88 === $lines[0]['tax'] && 621.60 === $lines[0]['gross'], 'Correct VAT unit/line breakdown on immutable original items' );
$mixed = SEO_Facturas_Rectifications::replacement_lines( array(
    array( 'name' => 'Standard', 'quantity' => 1, 'total' => 100.00, 'total_tax' => 0, 'tax_class' => '' ),
    array( 'name' => 'Reduced', 'quantity' => 1, 'total' => 100.00, 'total_tax' => 0, 'tax_class' => 'reduced-rate' ),
), 21, 165.29, 200.00 );
verify_rectification( is_wp_error( $mixed ), 'Mixed VAT classes cannot be misrepresented as one rate' );
foreach ( array( 0, 4, 10, 21 ) as $rate ) {
    $result = SEO_Facturas_Rectifications::replacement_amounts( 121.0, $rate );
    verify_rectification( ! is_wp_error( $result ) && abs( $result['base_total'] + $result['total_tax'] - 121 ) < 0.001,
        'Every allowed rate preserves exact customer gross' );
}
foreach ( array( -1, 5, 22, 100 ) as $rate ) {
    verify_rectification( is_wp_error( SEO_Facturas_Rectifications::replacement_amounts( 121.0, $rate ) ),
        'Unconfigured tax rate is rejected' );
}
verify_rectification( is_wp_error( SEO_Facturas_Rectifications::replacement_amounts( -10, 21 ) ),
    'No negative invoice manipulation' );
$root = dirname( __DIR__ );
$read = static function ( $file ) use ( $root ) { return file_get_contents( $root . '/' . $file ); };
$rectifications = $read( 'includes/class-seo-facturas-rectifications.php' );
$schema = $read( 'includes/class-seo-facturas-install.php' );
$documents = $read( 'includes/class-seo-facturas-documents.php' );
$tax = $read( 'includes/class-seo-facturas-tax.php' );
$template = $read( 'includes/templates/rectification.php' );
$required = array(
    array( $schema, 'SEO_Facturas_Rectifications::install_schema()', 'independent table migration' ),
    array( $rectifications, 'UNIQUE KEY original_id (original_id)', 'unique original prevents duplicate rectifications' ),
    array( $rectifications, "SEO_Facturas_Documents::get(", 'issued original is consulted' ),
    array( $rectifications, "'issued' !== \$original->status", 'only issued original invoices' ),
    array( $rectifications, 'wp_nonce_field(', 'form uses CSRF protection' ),
    array( $rectifications, 'check_admin_referer(', 'write action verifies nonce' ),
    array( $rectifications, 'current_user_can(', 'write action verifies admin role' ),
    array( $rectifications, 'strlen( $reason ) < 15', 'mandatory reason' ),
    array( $rectifications, "'total' => \$gross", 'gross remains fixed' ),
    array( $rectifications, "status = 'issued'", 'only issued rectification is effective' ),
    array( $rectifications, "'taxocommerce_accounting_effective_issued_invoice'", 'optional Accounting integration' ),
    array( $rectifications, 'wp_mail(', 'customer email is explicitly initiated' ),
    array( $rectifications, 'hash_equals(', 'download verifies original PDF hash' ),
    array( $template, "'old_base'", 'PDF identifies previous taxable base' ),
    array( $template, "'old_tax'", 'PDF identifies previous VAT amount' ),
    array( $template, "original_number", 'PDF references original invoice' ),
    array( $documents, "TYPE_RECTIFICATION", 'separate numbering type' ),
    array( $tax, 'validate_order_tax', 'preventive original order tax guard is preserved' ),
);
foreach ( $required as $check ) {
    verify_rectification( false !== strpos( $check[0], $check[1] ), $check[2] );
}
verify_rectification( false === strpos( $rectifications, '$order->save(' ),
    'Never change WooCommerce order or customer payment' );
verify_rectification( false === strpos( $rectifications, 'get_for_order(' ),
    'Rectification never issues a replacement of the original invoice' );
echo "TaxoCommerce Billing rectification regression: PASS\n";
