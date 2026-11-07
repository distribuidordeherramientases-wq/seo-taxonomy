<?php
/**
 * WordPress-independent rendering check for the persistent header-tag inventory.
 * Run: php tests/head-tags-inventory-smoke.php
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['head_test_options'] = array();
function get_option( $name, $default = array() ) {
    return $GLOBALS['head_test_options'][ $name ] ?? $default;
}
function current_user_can( $capability ) { return true; }
function esc_html__( $text, $domain ) { return $text; }
function __( $text, $domain ) { return $text; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_textarea( $value ) { return esc_html( $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
function admin_url( $path ) { return '/wp-admin/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_field( $name ) { echo '<input name="_wpnonce" value="test">'; }
function submit_button( $text ) { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }
function selected( $actual, $expected, $echo = true ) {
    $text = $actual === $expected ? ' selected="selected"' : '';
    if ( $echo ) { echo $text; }
    return $text;
}
function checked( $actual, $expected, $echo = true ) {
    $text = $actual === $expected ? ' checked="checked"' : '';
    if ( $echo ) { echo $text; }
    return $text;
}

require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-head-tags.php';
ob_start();
TaxoCommerce_Insights_Head_Tags::render();
$empty = ob_get_clean();
if ( false === strpos( $empty, 'Inventario de etiquetas de cabecera' )
    || false === strpos( $empty, 'Todavia no hay etiquetas guardadas' ) ) {
    throw new RuntimeException( 'Empty inventory is not visible.' );
}

$GLOBALS['head_test_options']['taxocommerce_insights_head_tags'] = array(
    'google-test' => array(
        'name' => 'Mi Google',
        'kind' => 'verification',
        'code' => '<meta name="google-site-verification" content="abcdef123456">',
        'enabled' => true,
        'provider' => 'google_verification',
        'provider_id' => 'abcdef123456',
    ),
    'clarity-test' => array(
        'name' => 'Mi Clarity',
        'kind' => 'tracking',
        'code' => '<script>/* sample */</script>',
        'enabled' => false,
        'provider' => 'clarity',
        'provider_id' => 'ww0i0jxbs8',
    ),
);
ob_start();
TaxoCommerce_Insights_Head_Tags::render();
$list = ob_get_clean();
foreach ( array( 'Mi Google', 'Mi Clarity', 'Google Search Console', 'Microsoft Clarity',
    'abcdef123456', 'ww0i0jxbs8', 'Editar', 'Activar', 'Desactivar',
    'Eliminar', 'tgb_head_tag_toggle', 'tgb_head_tag_delete', 'edit_tag=google-test',
    'entry_provider_id', 'entry_provider' ) as $expected ) {
    if ( false === strpos( $list, $expected ) ) {
        throw new RuntimeException( 'Inventory missing: ' . $expected );
    }
}
echo "Head tags inventory smoke test passed\n";
