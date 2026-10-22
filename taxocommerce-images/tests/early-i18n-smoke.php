<?php
// The module descriptor is evaluated while Core boots on plugins_loaded.
$images = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-images.php' );
$start = strpos( $images, 'public function register_with_core(' );
$end = strpos( $images, 'public function register_admin_menu(', $start );
$register = substr( $images, $start, $end - $start );
if ( false === $start || false === $end || false !== strpos( $register, "__( " ) ||
     false === strpos( $register, "'description' => 'Gestión" ) ) {
    fwrite( STDERR, "Early translation regression in Images registration\n" );
    exit( 1 );
}
echo "Images early-i18n guard OK\n";
