<?php
// Regression check: Core hides native submenu only when there are no active family extensions.
define( 'ABSPATH', __DIR__ );
function esc_html__( $s, $domain = '' ) { return $s; }
class TaxoCommerce_Core {
    public function hide_sidebar_submenu() {
        global $submenu;
        $items = isset( $submenu['taxocommerce-core'] ) ? (array) $submenu['taxocommerce-core'] : array();
        $core_pages = array( 'taxocommerce-core', 'taxocommerce-core-content', 'taxocommerce-core-hierarchy', 'taxocommerce-core-menu', 'taxocommerce-core-modules' );
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) || ! isset( $item[2] ) ) { continue; }
            if ( ! in_array( (string) $item[2], $core_pages, true ) ) { return; }
        }
        echo '<style id="taxocommerce-core-sidebar">#toplevel_page_taxocommerce-core > ul.wp-submenu{display:none!important;}</style>';
    }
}
$core = new TaxoCommerce_Core();
$submenu = array( 'taxocommerce-core' => array( array( 'Inicio', 'manage_options', 'taxocommerce-core' ), array( 'Contenidos', 'manage_options', 'taxocommerce-core-content' ) ) );
ob_start(); $core->hide_sidebar_submenu(); $alone = ob_get_clean();
if ( false === strpos( $alone, 'display:none' ) ) { fwrite( STDERR, "Core-only menu must remain compact\n" ); exit( 1 ); }
$submenu['taxocommerce-core'][] = array( 'Imágenes', 'manage_options', 'taxocommerce-images' );
ob_start(); $core->hide_sidebar_submenu(); $with_plugin = ob_get_clean();
if ( '' !== $with_plugin ) { fwrite( STDERR, "Extension submenu must be visible\n" ); exit( 1 ); }
echo "Family menu smoke OK\n";
