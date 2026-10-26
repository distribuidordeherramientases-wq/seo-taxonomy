<?php
/**
 * Local, network-free Mercado integration test for category import, schema,
 * inherited country metadata and real API-response transformation.
 */
$fake_root = sys_get_temp_dir() . '/tgb_market_test_' . getmypid() . '/';
@mkdir( $fake_root . 'wp-admin/includes', 0775, true );
file_put_contents( $fake_root . 'wp-admin/includes/upgrade.php', '<?php function dbDelta($sql) { preg_match("/CREATE TABLE ([^ ]+)/", $sql, $m); $GLOBALS["market_test_tables"][] = $m[1]; }' );
define( 'ABSPATH', $fake_root );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['market_test_tables'] = array();
$GLOBALS['tgb_test_options'] = array();
$GLOBALS['tgb_test_remote_calls'] = array();
$GLOBALS['tgb_test_actions'] = array();

class WP_Error {
    private $code;
    private $message;
    public function __construct( $c, $m ) { $this->code = $c; $this->message = $m; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function absint( $v ) { return abs( (int) $v ); }
function get_option( $k, $default = false ) { return $GLOBALS['tgb_test_options'][ $k ] ?? $default; }
function update_option( $k, $v, $autoload = false ) { $GLOBALS['tgb_test_options'][ $k ] = $v; return true; }
function do_action( $hook ) {
    $GLOBALS['tgb_test_actions'][] = func_get_args();
}
function get_locale() { return 'es_ES'; }
function get_current_user_id() { return 1; }
function current_time( $type, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_transient( $k ) { return $GLOBALS['tgb_test_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['tgb_test_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['tgb_test_transients'][ $k ] ); return true; }
function taxonomy_exists( $name ) { return 'product_cat' === $name; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $v ) ); }
function sanitize_title( $v ) { return strtolower( trim( (string) $v ) ); }
function esc_url_raw( $v ) { return trim( (string) $v ); }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function add_query_arg( $params, $url ) { return $url . '?' . http_build_query( $params ); }
function get_terms( $args ) {
    $ids = range( 1, 10 );
    return array_slice( $ids, $args['offset'], $args['number'] );
}
function get_term( $id, $taxonomy ) {
    $id = (int) $id;
    if ( $id < 1 || $id > 10 ) { return false; }
    return (object) array(
        'term_id' => $id, 'taxonomy' => 'product_cat',
        'name' => $id === 1 ? 'Taladros' : 'Categoría ' . $id,
        'slug' => $id === 1 ? 'taladros' : 'categoria-' . $id,
        'parent' => $id === 10 ? 9 : 0,
    );
}
function get_term_meta( $term_id, $key, $single = false ) {
    if ( 9 === (int) $term_id && '_seo_pais_origen' === $key ) { return 'FR'; }
    if ( 9 === (int) $term_id && '_seo_locale' === $key ) { return 'fr-FR'; }
    return '';
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_safe_remote_get( $url, $args = array() ) {
    $GLOBALS['tgb_test_remote_calls'][] = $url;
    if ( false !== strpos( $url, '/youtube/v3/search' ) ) {
        $data = array( 'items' => array(
            array( 'id' => array( 'videoId' => 'aBcDeFGhI_1' ),
                'snippet' => array( 'title' => 'Pruebas reales de taladros',
                    'description' => 'Duración de batería y par motor',
                    'publishedAt' => '2026-10-22T10:00:00Z' ) ),
        ) );
    } elseif ( false !== strpos( $url, '/youtube/v3/commentThreads' ) ) {
        $data = array( 'items' => array(
            array( 'snippet' => array( 'topLevelComment' => array(
                'id' => 'Ugx1_ab',
                'snippet' => array( 'textOriginal' => 'Lo uso para montar muebles; la batería dura varias horas.', 'publishedAt' => '2026-10-22T15:00:00Z' ),
            ) ) ),
        ) );
    } else {
        throw new RuntimeException( 'Unexpected API URL' );
    }
    return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( $data ) );
}
function wp_safe_remote_post() { throw new RuntimeException( 'Unexpected Merchant API access' ); }

final class FakeMarketDB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $categories = array();
    public $sources = array();
    public $runs = array();
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }
    public function esc_like( $v ) { return $v; }
    public function prepare( $sql, ...$args ) {
        $i = 0;
        return preg_replace_callback( '/%[sd]/', static function( $m ) use ( $args, &$i ) {
            $value = $args[ $i++ ];
            return '%d' === $m[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
        }, $sql );
    }
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) ) {
            preg_match( "/LIKE '([^']+)'/", $sql, $m );
            return in_array( $m[1] ?? '', $GLOBALS['market_test_tables'], true ) ? $m[1] : null;
        }
        if ( preg_match( '/SELECT id FROM [^ ]+ WHERE term_id = (\d+)/', $sql, $m ) ) {
            foreach ( $this->categories as $row ) {
                if ( $row['term_id'] === (int) $m[1] ) { return $row['id']; }
            }
        }
        if ( false !== strpos( $sql, 'COUNT(*)' ) ) { return count( $this->categories ); }
        return null;
    }
    public function get_row( $sql, $mode = null ) {
        if ( preg_match( '/WHERE term_id = (\d+)/', $sql, $m ) ) {
            foreach ( $this->categories as $row ) {
                if ( $row['term_id'] === (int) $m[1] ) { return $row; }
            }
        }
        if ( preg_match( '/WHERE id = (\d+)/', $sql, $m ) ) {
            return $this->categories[(int) $m[1]] ?? null;
        }
        return null;
    }
    public function insert( $table, $data ) {
        $map = strrpos( $table, 'categories' ) !== false ? 'categories'
            : ( strrpos( $table, 'sources' ) !== false ? 'sources' : 'runs' );
        $this->insert_id = count( $this->$map ) + 1;
        $data['id'] = $this->insert_id;
        $this->{$map}[$this->insert_id] = $data;
        return 1;
    }
    public function update( $table, $data, $where ) {
        $map = strrpos( $table, 'categories' ) !== false ? 'categories'
            : ( strrpos( $table, 'sources' ) !== false ? 'sources' : 'runs' );
        foreach ( $this->$map as $id => $row ) {
            $match = true;
            foreach ( $where as $k => $v ) {
                if ( (string) ( $row[ $k ] ?? '' ) !== (string) $v ) { $match = false; }
            }
            if ( $match ) { $this->{$map}[$id] = array_merge( $row, $data ); }
        }
        return 1;
    }
    public function query( $sql ) { return 0; }
}
$GLOBALS['wpdb'] = new FakeMarketDB();
final class TaxoCommerce_Google_Bing_Connections {
    public static function merchant_account() { return ''; }
    public static function google_settings() { return array( 'search_console_site_url' => '', 'service_account_json' => '' ); }
}

final class TaxoCommerce_Google_Bing_OAuth {
    public static function settings() { return array( 'property_id' => '' ); }
    public static function tokens() { return array( 'access_token' => '', 'refresh_token' => '' ); }
}

require dirname( __DIR__ ) . '/includes/class-taxocommerce-google-bing-market-sources.php';
require dirname( __DIR__ ) . '/includes/class-taxocommerce-google-bing-market.php';

TaxoCommerce_Google_Bing_Market::activate();
if ( count( $GLOBALS['market_test_tables'] ) !== 3 ) {
    fwrite( STDERR, "Schema did not create 3 tables\n" ); exit( 1 );
}
$result = TaxoCommerce_Google_Bing_Market::sync_categories_batch();
if ( is_wp_error( $result ) || count( $GLOBALS['wpdb']->categories ) !== 10 || ! $result['complete'] ) {
    fwrite( STDERR, "Expected 10 independent category records\n" ); exit( 1 );
}
if ( $GLOBALS['wpdb']->categories[10]['country'] !== 'FR' ||
    $GLOBALS['wpdb']->categories[10]['locale'] !== 'fr-FR' ) {
    fwrite( STDERR, "Category 10 did not inherit country/locale from parent\n" ); exit( 1 );
}
update_option( 'tgb_market_youtube_key', 'TEST_ONLY' );
$scanned = TaxoCommerce_Google_Bing_Market::scan_category( 1 );
if ( is_wp_error( $scanned ) || $scanned['status'] !== 'complete' ||
    count( $GLOBALS['wpdb']->sources ) !== 2 || count( $GLOBALS['wpdb']->runs ) !== 1 ||
    count( $GLOBALS['tgb_test_remote_calls'] ) !== 2 ) {
    fwrite( STDERR, "Expected one video and one comment from two mocked API calls\n" ); exit( 1 );
}
if ( $GLOBALS['wpdb']->sources[2]['evidence_level'] !== 'public_comment' ||
    false === strpos( $GLOBALS['wpdb']->sources[2]['source_url'], '&lc=Ugx1_ab' ) ) {
    fwrite( STDERR, "Comment evidence missing provenance\n" ); exit( 1 );
}
if ( (int) get_option( 'tgb_market_daily_budget', array() )['spent'] !== 1 ) {
    fwrite( STDERR, "Daily budget did not increment\n" ); exit( 1 );
}
$prepared_hook = array_values( array_filter(
    $GLOBALS['tgb_test_actions'],
    static function ( $args ) {
        return isset( $args[0] ) && 'tgb_market_evidence_saved' === $args[0];
    }
) );
if ( empty( $prepared_hook ) ) {
    fwrite( STDERR, "Market evidence hook was not emitted\n" ); exit( 1 );
}

unlink( $fake_root . 'wp-admin/includes/upgrade.php' );
rmdir( $fake_root . 'wp-admin/includes' );
rmdir( $fake_root . 'wp-admin' );
rmdir( $fake_root );
echo "Mercado category integration: OK (10 categories, country inheritance, video + real comment format, local quota)\n";
