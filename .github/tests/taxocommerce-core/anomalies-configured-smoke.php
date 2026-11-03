<?php
/**
 * Run independently: php .github/tests/taxocommerce-core/anomalies-configured-smoke.php
 * WordPress and WooCommerce stubs are intentionally confined to this CLI test.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_CORE_VERSION', 'test' );
function add_action( $hook, $callback ) {}
function current_user_can( $cap ) { return true; }
function apply_filters( $hook, $value ) { return $value; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_attr__( $text, $domain = '' ) { return $text; }
function esc_sql( $value ) { return $value; }
function sanitize_key( $value ) { return strtolower( $value ); }

final class TaxoCommerce_Core_Hierarchy_Classic {
    public static function role_pages( $role ) {
        $count = (int) ( $GLOBALS['tc_role_counts'][ $role ] ?? 0 );
        return array_fill( 0, $count, (object) array( 'ID' => 123 ) );
    }
}

final class Anomalies_Configured_Test_DB {
    public $prefix = 'wp_';
    public $tables = array();
    public $active_nodes = 0;
    public $relations = 0;
    public $structural_relations = 0;
    public function esc_like( $text ) { return $text; }
    public function prepare( $query, $value ) {
        return 'TEST_TABLE:' . (string) $value;
    }
    public function get_var( $query ) {
        if ( 0 === strpos( $query, 'TEST_TABLE:' ) ) {
            $table = substr( $query, strlen( 'TEST_TABLE:' ) );
            return in_array( $table, $this->tables, true ) ? $table : null;
        }
        if ( false !== strpos( $query, 'FROM wp_seo_nodes' ) ) {
            return $this->active_nodes;
        }
        if ( false !== strpos( $query, 'FROM wp_seo_relations' ) ) {
            if ( false !== strpos( $query, 'relation_type IN' ) ) {
                return $this->structural_relations;
            }
            return $this->relations;
        }
        throw new RuntimeException( 'Unexpected SQL: ' . $query );
    }
}
$GLOBALS['tc_role_counts'] = array();
$wpdb = new Anomalies_Configured_Test_DB();
require dirname( __DIR__, 3 ) . '/taxocommerce-core/includes/class-taxocommerce-core-anomalies.php';
function tc_anomalies_expect( $test, $message ) {
    if ( ! $test ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}

ob_start();
taxocommerce_core_anomalies_render();
$html = ob_get_clean();
tc_anomalies_expect( false !== strpos( $html, 'jerarquía SEO no está disponible' ), 'missing schema is neutral' );
tc_anomalies_expect( false === strpos( $html, 'notice-error' ), 'missing schema not presented as an error' );
tc_anomalies_expect( false === strpos( $html, 'Reporte de Auditoría' ), 'missing schema does not show report' );

$wpdb->tables = array( 'wp_seo_nodes', 'wp_seo_relations' );
ob_start();
taxocommerce_core_anomalies_render();
$html = ob_get_clean();
tc_anomalies_expect( false !== strpos( $html, 'Jerarquía SEO no configurada' ), 'empty schema is unconfigured' );
tc_anomalies_expect( false === strpos( $html, 'Clusters sin hubs primarios' ), 'empty schema has no structural sections' );
tc_anomalies_expect( false === strpos( $html, 'JSON correctivo no disponible' ), 'empty schema has no Vocabulary warning' );

$wpdb->relations = 5; // Other SEO relations exist, but there is no hierarchy definition.
ob_start();
taxocommerce_core_anomalies_render();
$html = ob_get_clean();
tc_anomalies_expect( false !== strpos( $html, 'Jerarquía SEO no configurada' ), 'unrelated SEO relations do not enable hierarchy audit' );
tc_anomalies_expect( false === strpos( $html, 'Clusters sin hubs primarios' ), 'unrelated SEO relations do not produce fake structural statuses' );

ob_start();
taxocommerce_core_anomalies_render_faq_orphans();
$html = ob_get_clean();
tc_anomalies_expect( '' === $html, 'missing optional FAQ table produces no warning' );

// A configured hierarchy with no findings should not render successful sections.
$reported = 0;
ob_start();
ob_start();
echo '<h3>Sin incidencias</h3>';
taxocommerce_core_anomalies_finish_section( false, false, $reported );
$hidden = ob_get_clean();
tc_anomalies_expect( '' === $hidden && 0 === $reported, 'only issues mode omits successful sections' );

// Real findings remain visible and counted; the optional detailed view preserves old output.
ob_start();
ob_start();
echo '<h3>Incidencia real</h3>';
taxocommerce_core_anomalies_finish_section( true, false, $reported );
$found_html = ob_get_clean();
tc_anomalies_expect( false !== strpos( $found_html, 'Incidencia real' ) && 1 === $reported, 'actual findings remain visible' );

ob_start();
ob_start();
echo '<h3>Comprobación superada</h3>';
taxocommerce_core_anomalies_finish_section( false, true, $reported );
$detail_html = ob_get_clean();
tc_anomalies_expect( false !== strpos( $detail_html, 'Comprobación superada' ), 'detailed view preserves success sections' );
tc_anomalies_expect( 1 === $reported, 'detailed view does not count success as findings' );

echo 'Core conditional anomalies smoke OK' . PHP_EOL;
