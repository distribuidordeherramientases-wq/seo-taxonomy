<?php
/**
 * Offline smoke: manuals are integrated, readable, permission-gated and read-only.
 *
 * Run: php taxocommerce-templates/tests/manual-smoke.php
 */
define( 'ABSPATH', __DIR__ );
define( 'TCT_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['tct_manual_test_allowed'] = true;

function current_user_can( $capability ) {
    return 'manage_options' === $capability && $GLOBALS['tct_manual_test_allowed'];
}
function esc_html__( $text, $domain = '' ) {
    return $text;
}
function esc_html_e( $text, $domain = '' ) {
    echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr_e( $text, $domain = '' ) {
    echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}
function wp_die( $message ) {
    throw new RuntimeException( $message );
}
function expect_tct_manual( $ok, $message ) {
    if ( ! $ok ) {
        fwrite( STDERR, "FAIL: " . $message . "\n" );
        exit( 1 );
    }
}

require_once TCT_DIR . 'includes/class-tct-manual.php';
ob_start();
TCT_Manual::render();
$html = ob_get_clean();

foreach ( array(
    'Manual de usuario',
    'Manual técnico',
    'Diagnóstico y resolución de incidencias',
    'Aplicación de políticas',
    'Enrutamiento',
    'Por configurar',
    'Diseñador de plantillas',
    'ES, US, DE, NL y FR',
    'wp-content/taxocommerce-templates-library',
    'Autocheck y JSON',
    'tct_template_designs_v1',
    'template_include',
    'wc_get_template',
    'tct_request_definitions',
    'TCT_Association_Audit',
) as $phrase ) {
    expect_tct_manual( false !== strpos( $html, $phrase ), 'Missing documentation: ' . $phrase );
}
expect_tct_manual( false !== strpos( $html, 'id="tct-manual-usuario"' ), 'Missing user anchor' );
expect_tct_manual( false !== strpos( $html, 'id="tct-manual-tecnico"' ), 'Missing developer anchor' );
expect_tct_manual( false !== strpos( $html, 'id="tct-manual-incidencias"' ), 'Missing troubleshooting anchor' );
expect_tct_manual( 2 === substr_count( $html, '<table class="widefat striped">' ), 'Expected technical and troubleshooting tables' );

$main = file_get_contents( TCT_DIR . 'taxocommerce-templates.php' );
expect_tct_manual( false !== strpos( $main, "require_once TCT_DIR . 'includes/class-tct-manual.php';" ), 'Manual must load from bootstrap' );
expect_tct_manual( false !== strpos( $main, "'manual' => __( 'Manual', 'taxocommerce-templates' )" ), 'Manual must have a tab' );
expect_tct_manual( false !== strpos( $main, "TCT_Manual::render();" ), 'Manual must be routed' );
expect_tct_manual( false !== strpos( $main, "'manual' === \$main_tab" ), 'Manual must use its own route' );

$manual_code = file_get_contents( TCT_DIR . 'includes/class-tct-manual.php' );
foreach ( array( 'update_option(', 'delete_option(', 'wp_insert_post(', 'wp_schedule_event(', 'set_transient(', 'TCT_Autocheck::run(', 'wp_remote_get(' ) as $forbidden ) {
    expect_tct_manual( false === strpos( $manual_code, $forbidden ), 'Manual must not execute writes or checks: ' . $forbidden );
}
$GLOBALS['tct_manual_test_allowed'] = false;
$blocked = false;
try {
    TCT_Manual::render();
} catch ( RuntimeException $exception ) {
    $blocked = true;
}
expect_tct_manual( $blocked, 'Unauthorized manual access must be denied' );

echo "PASS: TaxoCommerce Templates user and developer manuals (read-only, permissioned, tabbed).\n";
