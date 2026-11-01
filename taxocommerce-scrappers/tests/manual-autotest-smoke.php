<?php
/**
 * Standalone dry-run for Scrappers' read-only diagnostics.
 * A local fake DB and provider objects; never contacts WordPress or the network.
 */
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_SCRAPPERS_VERSION', '0.1.4' );

function wp_remote_get() { throw new RuntimeException( 'Autotest must not call remote services' ); }
function wp_remote_post() { throw new RuntimeException( 'Autotest must not call remote services' ); }
function taxonomy_exists( $name ) { return 'product_cat' === $name; }
function has_action( $hook, $callback = false ) {
    return in_array( $hook, array(
        'seo_ojeador_market_tick', 'admin_post_seo_ojeador_start',
        'admin_post_seo_ojeador_stop', 'admin_post_seo_ingeniero_prepare',
        'seo_ingeniero_editorial_refresh',
    ), true ) ? 10 : false;
}
function absint( $value ) { return abs( (int) $value ); }
function update_option() { throw new RuntimeException( 'Autotest must not write options' ); }
function wp_schedule_event() { throw new RuntimeException( 'Autotest must not schedule jobs' ); }
function wp_schedule_single_event() { throw new RuntimeException( 'Autotest must not schedule jobs' ); }

final class Scrappers_Smoke_DB {
    public $queries = 0;
    public $excluded = '';
    public function prepare( $query, $table ) {
        if ( 'SHOW TABLES LIKE %s' !== $query ) { throw new RuntimeException( 'Unexpected SQL query' ); }
        return "SHOW TABLES LIKE '" . $table . "'";
    }
    public function get_var( $query ) {
        $this->queries++;
        $table = substr( $query, strlen( "SHOW TABLES LIKE '" ), -1 );
        return $table !== $this->excluded ? $table : null;
    }
}
$wpdb = new Scrappers_Smoke_DB();

class SEO_Ojeador_Admin { public static function start() {} public static function stop() {} }
class SEO_Ojeador_Shopping {
    public static $configured = true;
    public static function settings() {
        return array( 'api_key' => self::$configured ? 'sensitive-credential-never-print' : '', 'scraperapi_key' => '', 'monthly_query_limit' => 250 );
    }
}
class SEO_Ojeador_Worker { const CRON_HOOK = 'seo_ojeador_market_tick'; public static function cron_tick() {} }
class SEO_Ojeador_DB {
    public static function table( $name ) { return 'wp_seo_ojeador_' . $name; }
}
class SEO_Ojeador_Analysis {}
class SEO_Ojeador_Merchant {}
class SEO_Ingeniero {}
class SEO_Ingeniero_Admin { public static function handle_prepare() {} }
class SEO_Ingeniero_DB {
    public static function table( $name ) { return 'wp_seo_ingeniero_' . $name; }
}
class SEO_Ingeniero_Process {
    const EDITORIAL_REFRESH_HOOK = 'seo_ingeniero_editorial_refresh';
    public static function editorial_refresh() {}
}
class SEO_Ingeniero_Posts {}
class SEO_Ingeniero_Tests { public static function run() {} }

require dirname( __DIR__ ) . '/includes/class-taxocommerce-scrappers-auto-test.php';
$results = TaxoCommerce_Scrappers_Auto_Test::run();
$values  = array_column( $results, 'status' );
$details = implode( '\n', array_column( $results, 'detail' ) );
if ( count( $results ) < 12 || in_array( 'error', $values, true ) || in_array( 'warning', $values, true ) ) {
    fwrite( STDERR, 'FAIL: all available components must pass. ' . json_encode( $results ) . PHP_EOL );
    exit( 1 );
}
if ( false !== strpos( $details, 'sensitive-credential-never-print' ) || 9 !== $wpdb->queries ) {
    fwrite( STDERR, 'FAIL: secrets leaked or unexpected SQL operations' . PHP_EOL );
    exit( 1 );
}

SEO_Ojeador_Shopping::$configured = false;
$wpdb->excluded = 'wp_seo_ingeniero_editorial';
$wpdb->queries  = 0;
$results = TaxoCommerce_Scrappers_Auto_Test::run();
$warnings = array_filter( $results, static function ( $item ) {
    return 'Conexiones' === $item['group'] && 'Credenciales configuradas' === $item['name'] && 'warning' === $item['status'];
} );
$errors = array_filter( $results, static function ( $item ) {
    return 'Ingeniero' === $item['group'] && 'Tablas de conocimiento' === $item['name'] && 'error' === $item['status'];
} );
if ( 1 !== count( $warnings ) || 1 !== count( $errors ) || 9 !== $wpdb->queries ) {
    fwrite( STDERR, 'FAIL: missing credentials or schema reported incorrectly' . PHP_EOL );
    exit( 1 );
}

$main = file_get_contents( dirname( __DIR__ ) . '/taxocommerce-scrappers.php' );
$manual = file_get_contents( dirname( __DIR__ ) . '/includes/class-taxocommerce-scrappers-manual.php' );
if ( false === strpos( $main, "'autotest'   => 'Autotest'" )
    || false === strpos( $main, "'manual'     => 'Manual'" )
    || false === strpos( $manual, 'Manual de usuario' )
    || false === strpos( $manual, 'Manual técnico' )
    || false === strpos( $main, "TaxoCommerce_Scrappers_Auto_Test::render()" )
    || false === strpos( $main, "TaxoCommerce_Scrappers_Manual::render()" ) ) {
    fwrite( STDERR, 'FAIL: missing menu tabs or manual sections' . PHP_EOL );
    exit( 1 );
}
echo "TaxoCommerce Scrappers manual/autotest smoke passed.\n";
