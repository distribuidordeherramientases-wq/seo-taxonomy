<?php
/** CLI structural contract for the read-only Images manual. */
if ( PHP_SAPI !== 'cli' ) { exit(1); }
define( 'ABSPATH', __DIR__ );
function current_user_can( $permission ) { return 'manage_options' === $permission; }
function apply_filters( $name, $value ) { return $value; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_die( $s ) { throw new Exception( $s ); }
require dirname( __DIR__ ) . '/includes/class-taxocommerce-images-manual.php';
$sections = TaxoCommerce_Images_Manual::sections();
function check( $ok, $label ) { if ( ! $ok ) { fwrite(STDERR, "FAIL " . $label . "\n"); exit(1); } }
check( count( $sections ) >= 16, 'complete user/developer sections' );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-images.php' );
$entry = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-images.php' );
check( false !== strpos( $source, "'manual'     =>" ), 'manual navigation' );
check( false !== strpos( $source, "TaxoCommerce_Images_Manual::render()" ), 'manual router' );
check( false !== strpos( $entry, "class-taxocommerce-images-manual.php" ), 'manual included' );
$keys = array_column( $sections, 'id' );
foreach ( array('usuario','inventario','anomalias','asignacion','sinuso','etiquetas','media','miniaturas','ajustes','autotest','servidor','desarrollador','arquitectura','rutas','datos','integraciones','pruebas') as $id ) {
    check( in_array( $id, $keys, true ), 'missing section: ' . $id );
}
ob_start();
TaxoCommerce_Images_Manual::render();
$html = ob_get_clean();
check( substr_count( $html, '<h1' ) >= 3, 'h1 headings' );
check( substr_count( $html, '<h2' ) >= 14, 'h2 headings' );
check( false !== strpos( $html, 'Índice' ), 'manual index' );
check( false !== strpos( $html, 'WP-Cron' ), 'persistent jobs explained' );
check( false !== strpos( $html, 'SHA-256' ), 'duplicates explained' );
check( false !== strpos( $html, 'Data Layer' ) || false !== strpos( $html, 'WordPress' ), 'architecture explained' );
echo "Images manual smoke OK: sections, routing, headings and content.\n";
