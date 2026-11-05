<?php
/**
 * Static regression contract for the first Plugin Check warning remediation.
 *
 * @package TaxoCommerceTemplates
 */
if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}

$root = dirname( __DIR__ );
$cases = array(
    'includes/class-tct-request-registry.php' => array(
        'SELECT seo_role FROM %i',
        'FROM %i WHERE template_key IN ({$slots})',
        'array_merge( array( $table ), $candidates )',
        'FROM %i WHERE template_key = %s',
    ),
    'includes/class-tct-request-admin.php' => array(
        'SELECT id FROM %i WHERE template_key = %s LIMIT 1',
    ),
    'includes/class-tct-router.php' => array(
        'SELECT template_key FROM %i',
        'FROM %i ORDER BY display_order',
        'FROM %i WHERE template_key = %s LIMIT 1',
    ),
    'includes/class-tct-association-audit.php' => array(
        'FROM %i GROUP BY seo_role',
        'FROM %i ORDER BY mail_key',
    ),
    'includes/class-tct-template-editor.php' => array(
        "is_string(\$_POST['filename'])",
        "is_string(\$_POST['source_filename'])",
        "is_string(\$_POST['code'])",
        'check_admin_referer(',
        'self::validate_php(',
    ),
    'templates/template-cluster-international.php' => array(
        "'dht-cluster-international-base'",
        'TCT_VERSION',
    ),
);

foreach ( $cases as $relative => $expected ) {
    $source = file_get_contents( $root . '/' . $relative );
    if ( false === $source ) {
        fwrite( STDERR, "FAIL missing $relative\n" );
        exit( 1 );
    }
    foreach ( $expected as $text ) {
        if ( false === strpos( $source, $text ) ) {
            fwrite( STDERR, "FAIL $relative does not contain $text\n" );
            exit( 1 );
        }
    }
}
$plugin = file_get_contents( $root . '/taxocommerce-templates.php' );
$readme = file_get_contents( $root . '/readme.txt' );
if ( false === strpos( $plugin, 'Version: 0.4.14' ) ||
    false === strpos( $plugin, "define('TCT_VERSION','0.4.14');" ) ||
    false === strpos( $readme, 'Stable tag: 0.4.14' ) ) {
    fwrite( STDERR, "FAIL inconsistent Templates version\n" );
    exit( 1 );
}
echo "PASS: Templates Plugin Check first-batch SQL, admin input and version regression\n";
