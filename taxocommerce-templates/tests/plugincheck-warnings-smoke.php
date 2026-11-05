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
    'taxocommerce-templates.php' => array(
        "TCT_Request_Registry::role_for_object( 'page', absint( \$id ) )",
        "wp_cache_delete( 'role_page_' . \$id, 'taxocommerce_templates' )",
        'SELECT COUNT(*) FROM %i WHERE (source_id=',
        'SELECT id FROM %i WHERE object_type=',
        "SELECT object_id, seo_role FROM %i WHERE object_type='page'",
        "is_string(\$_POST['tct_page_role_nonce'])",
    ),
    'includes/class-tct-routing-admin.php' => array(
        "esc_html__( 'Automático / sin rol SEO', 'taxocommerce-templates' )",
    ),
    'includes/class-tct-request-registry.php' => array(
        'SELECT seo_role FROM %i',
        "wp_cache_get( \$cache_key, 'taxocommerce_templates' )",
        "wp_cache_set( \$cache_key, \$role, 'taxocommerce_templates', 15 )",
        'FROM %i WHERE template_key IN ({$slots})',
        'array_merge( array( $table ), $candidates )',
        'FROM %i WHERE template_key = %s',
    ),
    'includes/class-tct-request-admin.php' => array(
        'SELECT id FROM %i WHERE template_key = %s LIMIT 1',
        "is_string(\$_POST['request_key'])",
        "is_string(\$_POST['template_file'])",
    ),
    'includes/class-tct-router.php' => array(
        'SELECT template_key FROM %i',
        'FROM %i ORDER BY display_order',
        'FROM %i WHERE template_key = %s LIMIT 1',
    ),
    'includes/class-tct-association-audit.php' => array(
        'FROM %i GROUP BY seo_role',
        'FROM %i ORDER BY mail_key',
        'SELECT object_type, object_id FROM %i',
        'SELECT template_key FROM %i',
    ),
    'includes/class-tct-template-editor.php' => array(
        "is_string(\$_POST['filename'])",
        "is_string(\$_POST['source_filename'])",
        "is_string(\$_POST['code'])",
        'check_admin_referer(',
        'self::validate_php(',
        "is_string(\$files['name'])",
        "is_string(\$files['tmp_name'])",
        "is_int(\$files['size'])",
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
// Keep the migrated runtime layouts backed by prepared table identifiers.
// This catches accidental reintroduction of interpolated custom-table SQL.
$prepared_templates = array(
    'template-cluster-desktop.php' => 2,
    'template-cluster-mobile.php' => 2,
    'template-hub-primary-desktop.php' => 3,
    'template-hub-primary-mobile.php' => 3,
    'template-hub-secondary-desktop.php' => 3,
    'template-hub-secondary-mobile.php' => 3,
    'template-product-stock-alert.php' => 1,
    'template-product-stock-alert-us.php' => 1,
    'template-category-comparison.php' => 1,
    'template-blog-desktop.php' => 3,
    'template-blog-mobile.php' => 3,
    'template-shop-desktop.php' => 2,
    'template-shop-mobile.php' => 2,
    'template-product-us-desktop.php' => 2,
    'template-product-us-mobile.php' => 2,
    'template-cluster-international.php' => 2,
    'template-pilot-vevor.php' => 3,
    'template-vevor-affiliate.php' => 2,
    'template-vevor-affiliate-us.php' => 2,
    'template-helpers.php' => 1,
);
foreach ( $prepared_templates as $template => $minimum ) {
    $source = file_get_contents( $root . '/templates/' . $template );
    if ( false === $source || substr_count( $source, 'FROM %i' ) < $minimum ) {
        fwrite( STDERR, "FAIL $template has fewer than $minimum prepared table identifiers\\n" );
        exit( 1 );
    }
}

$plugin = file_get_contents( $root . '/taxocommerce-templates.php' );
$readme = file_get_contents( $root . '/readme.txt' );
if ( false === strpos( $plugin, 'Version: 0.4.17' ) ||
    false === strpos( $plugin, "define('TCT_VERSION','0.4.17');" ) ||
    false === strpos( $readme, 'Stable tag: 0.4.17' ) ) {
    fwrite( STDERR, "FAIL inconsistent Templates version\n" );
    exit( 1 );
}
echo "PASS: Templates Plugin Check first-batch SQL, admin input and version regression\n";

require_once __DIR__ . '/tct-db-cache-smoke.php';
