<?php
/**
 * Offline safety contract for System Check EXPLAIN diagnostics.
 *
 * The eight definitions must remain canonical SQL templates with one %i table
 * identifier. No user-supplied table names or raw SQL may be passed through.
 * Run: php taxocommerce-system-check/tests/sql-explain-smoke.php
 */
if ( 'cli' !== PHP_SAPI ) {
    exit;
}
define( 'ABSPATH', __DIR__ . '/' );
class TaxoCommerce_System_Check_Test_WPDB {
    public $prefix = 'wp_';
    public $prepared = array();
    public function prepare( $query, $table ) {
        if ( 1 !== substr_count( $query, '%i' )
            || ! preg_match( '/^wp_seo_[a-z0-9_]+$/', $table ) ) {
            throw new RuntimeException( 'Invalid diagnostic identifier or SQL placeholders.' );
        }
        $result = str_replace( '%i', '`' . $table . '`', $query );
        $this->prepared[] = $result;
        return $result;
    }
}
$wpdb = new TaxoCommerce_System_Check_Test_WPDB();
require dirname( __DIR__ ) . '/includes/system-check/seo-system-mysql-audit.php';

$definitions = seo_system_mysql_audit_explain_definitions();
if ( 8 !== count( $definitions ) ) {
    fwrite( STDERR, "Unexpected EXPLAIN diagnostic definition count.\n" );
    exit( 1 );
}
foreach ( $definitions as $definition ) {
    $table = $definition['table'];
    $sql = $definition['sql'];
    if ( ! is_string( $table ) || ! is_string( $sql )
        || 1 !== substr_count( $sql, '%i' )
        || false !== strpos( $sql, $table )
        || ! preg_match( '/^SELECT [A-Za-z_ ]+ FROM %i WHERE /', $sql ) ) {
        fwrite( STDERR, "Unsafe or changed EXPLAIN template: " . (string) $definition['label'] . "\n" );
        exit( 1 );
    }
    $prepared = $wpdb->prepare( 'EXPLAIN ' . $sql, $table );
    if ( 0 !== strpos( $prepared, 'EXPLAIN SELECT ' )
        || false === strpos( $prepared, '`' . $table . '`' )
        || false !== strpos( $prepared, '%i' ) ) {
        fwrite( STDERR, "EXPLAIN table is not bound.\n" );
        exit( 1 );
    }
}
// Read-only navigation filters and action selectors must not pass arrays
// into WordPress scalar sanitizers (a malformed GET/POST must be ignored).
$http_source = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-system-check-url-audit.php' );
foreach ( array(
    "is_string( \$_POST['tcs_task'] )",
    "is_string( \$_GET['tcs_filter'] )",
    "is_string( \$_GET['tcs_search'] )",
    "is_scalar( \$_GET['tcs_paged'] )",
) as $guard ) {
    if ( false === strpos( $http_source, $guard ) ) {
        fwrite( STDERR, "Unprotected HTTP audit input: " . $guard . "\\n" );
        exit( 1 );
    }
}
// Ensure that Health Scan binds its canonical table identifier before scan
// values and generated integer IDs. This guards a regression of PR #2016.
$health_source = file_get_contents( dirname( __DIR__ ) . '/includes/system-check/seo-health-scan.php' );
if (
    ! is_string( $health_source )
    || false === strpos( $health_source, "'UPDATE %i SET queued_scan_id=%d,queued_at=%s WHERE id IN ('" )
    || false === strpos( $health_source, "array_merge(array(\$tables['items'], \$run_id, \$now), \$ids)" )
    || false !== strpos( $health_source, 'UPDATE {$tables' )
) {
    fwrite( STDERR, "Health Scan queue must bind canonical table and ordered parameters.\n" );
    exit( 1 );
}
echo "Eight canonical System Check EXPLAIN templates prepared safely.\n";
