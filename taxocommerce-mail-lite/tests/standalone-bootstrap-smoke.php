<?php
/**
 * Verify the legacy activation guard.
 */
require __DIR__ . '/smoke.php';
function get_site_option( $name, $default = false ) { return $default; }
function is_multisite() { return false; }
function register_activation_hook( $file, $callback ) { $GLOBALS['tcmail_rename_activation'] = $callback; }
function esc_html__( $text, $domain = '' ) { return $text; }
function wp_die( $message, $title = '', $args = array() ) { throw new RuntimeException( $message ); }
$GLOBALS['tcmail_options']['active_plugins'] = array( 'taxocommerce-mail/taxocommerce-mail.php' );
require dirname( __DIR__ ) . '/taxocommerce-mail-lite.php';
assert_mail( ! defined( 'TCMAIL_VERSION' ), 'Renamed plugin must not boot alongside legacy.' );
assert_mail( is_callable( $GLOBALS['tcmail_rename_activation'] ?? null ), 'Guard must reject activation.' );
try {
    $GLOBALS['tcmail_rename_activation']();
    assert_mail( false, 'Activation must be blocked.' );
} catch ( RuntimeException $e ) {
    assert_mail( false !== strpos( $e->getMessage(), 'Deactivate' ), 'Migration explanation missing.' );
}
echo "PASS: previous installation cannot be activated simultaneously with renamed Lite.\n";
