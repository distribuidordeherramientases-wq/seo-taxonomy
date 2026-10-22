<?php
/**
 * PHP 7.4+ pure/storage smoke tests. They use in-memory WPDB stubs and do not
 * depend on a live WordPress installation or modify any production data.
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class WP_Post {
    public $ID;
    public $post_type;
    public $post_status;
    public function __construct( $id, $type ) { $this->ID = $id; $this->post_type = $type; $this->post_status = 'publish'; }
}
class WP_Term {
    public $term_id;
    public function __construct( $id ) { $this->term_id = $id; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function home_url( $path = '/' ) { return 'https://www.example.test' . ( strpos( (string) $path, '/' ) === 0 ? $path : '/' . $path ); }
function wp_parse_url( $v, $comp = -1 ) { return parse_url( $v, $comp ); }
function wp_validate_redirect( $url, $fallback = '' ) {
    return parse_url( $url, PHP_URL_HOST ) === 'www.example.test' ? $url : $fallback;
}
function get_option( $name, $default = false ) { return $GLOBALS['tc_options'][ $name ] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['tc_options'][ $key ] = $value; return true; }
function absint( $x ) { return abs( (int) $x ); }
function current_time( $type, $gmt = false ) { return '2026-10-22 12:00:00'; }
function wp_unslash( $s ) { return $s; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s ) ); }
function current_user_can( $cap, $id = 0 ) { return true; }
function get_post_type( $id ) { return 1 === $id ? 'post' : ( 2 === $id ? 'page' : ( 3 === $id ? 'product' : false ) ); }
function get_term( $id, $taxonomy ) { return 'product_cat' === $taxonomy && 4 === $id ? new WP_Term( $id ) : null; }
function get_taxonomy( $taxonomy ) { return 'product_cat' === $taxonomy ? (object) array( 'cap' => (object) array( 'delete_terms' => 'delete_product_terms' ) ) : null; }
function add_action( $name, $callback, $priority = 10, $arguments = 1 ) { $GLOBALS['tc_actions'][ $name ][] = $callback; }
function add_filter( $name, $callback, $priority = 10, $arguments = 1 ) { $GLOBALS['tc_filters'][ $name ][] = $callback; }
function has_action( $name, $callback = null ) { return false; }
function wp_enqueue_script() {}
function wp_localize_script() {}
function esc_url( $s ) { return $s; }
function admin_url( $s = '' ) { return 'https://www.example.test/wp-admin/' . $s; }
function add_query_arg( $args, $url ) { return $url . ( strpos( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }

class FakeDB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $rules = array();

    public function esc_like( $s ) { return $s; }
    public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
    public function prepare( $query ) { return array( 'query' => $query, 'args' => array_slice( func_get_args(), 1 ) ); }
    public function get_var( $prepared ) {
        return false !== strpos( $prepared['query'], 'SHOW TABLES LIKE' ) ? 'wp_seo_redirects' : null;
    }
    public function get_results( $prepared ) { return array_values( $this->rules ); }
    public function get_row( $prepared ) {
        $args = $prepared['args'];
        if ( false !== strpos( $prepared['query'], 'WHERE id' ) ) {
            return $this->rules[ (int) $args[1] ] ?? null;
        }
        if ( false !== strpos( $prepared['query'], 'origin_url IN' ) ) {
            foreach ( $this->rules as $row ) {
                if ( in_array( $row->origin_url, array( $args[1], $args[2] ), true ) ) {
                    return $row;
                }
            }
        }
        return null;
    }
    public function insert( $table, $fields, $formats = array() ) {
        $id = ++$this->insert_id;
        $this->rules[ $id ] = (object) array_merge( array( 'id' => $id ), $fields );
        return 1;
    }
    public function update( $table, $fields, $where, $formats = array(), $where_format = array() ) {
        $id = (int) $where['id'];
        if ( ! isset( $this->rules[ $id ] ) ) {
            return false;
        }
        foreach ( $fields as $key => $value ) {
            $this->rules[ $id ]->$key = $value;
        }
        return 1;
    }
    public function delete( $table, $where, $formats = array() ) {
        $id = (int) $where['id'];
        if ( ! isset( $this->rules[ $id ] ) ) {
            return 0;
        }
        unset( $this->rules[ $id ] );
        return 1;
    }
    public function query( $prepared ) {
        if ( false !== strpos( $prepared['query'], 'hits = hits + 1' ) ) {
            $id = (int) $prepared['args'][2];
            $this->rules[ $id ]->hits++;
            $this->rules[ $id ]->last_hit = $prepared['args'][1];
        }
        return 1;
    }
}
$GLOBALS['wpdb'] = new FakeDB();
$GLOBALS['tc_options'] = array( 'tc_redirects_schema_ready' => '1' );

require dirname( __DIR__ ) . '/includes/class-store.php';
require dirname( __DIR__ ) . '/includes/class-engine.php';
require dirname( __DIR__ ) . '/includes/class-admin.php';
require dirname( __DIR__ ) . '/includes/class-assisted-delete.php';

use TaxoCommerce\Redirects\Store;
use TaxoCommerce\Redirects\Engine;
use TaxoCommerce\Redirects\Assisted_Delete;

function expect( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, 'FAIL: ' . $message . "\n" );
        exit( 1 );
    }
}

expect( Store::same_host( 'www.example.test', 'example.test' ), 'www equivalent host' );
expect( Store::normalize_origin( 'https://www.example.test/antigua/' ) === '/antigua', 'Normalize absolute local origin' );
expect( Store::normalize_origin( '/antigua/' ) === '/antigua', 'Trailing slash normalize' );
expect( Store::normalize_origin( '/antigua?campaign=a' ) instanceof WP_Error, 'Origin query prohibited' );
expect( Store::normalize_origin( 'https://other.test/path' ) instanceof WP_Error, 'External origin rejected' );
expect( Store::normalize_origin( '/' ) instanceof WP_Error, 'Homepage origin is protected' );
expect( Store::normalize_origin( '/wp-admin/admin.php' ) instanceof WP_Error, 'Admin path protected' );
expect( Store::normalize_target( '/nueva/?ref=a#parte' ) === '/nueva?ref=a#parte', 'Destination query/fragment preserved' );
expect( Store::normalize_target( 'https://www.example.test/destino/' ) === '/destino', 'Local absolute destination becomes relative' );
expect( Store::normalize_target( 'https://bad.test/evil' ) instanceof WP_Error, 'External destination prohibited' );
expect( Store::absolute_target( '/nueva' ) === 'https://www.example.test/nueva', 'Absolute WP safe redirect URL' );

$one = Store::save( '/vieja', '/nueva', 302 );
expect( $one === 1, 'Save a 302 rule' );
expect( (int) Store::find_origin( '/vieja/' )->status_code === 302, 'Status preserved by rule lookup' );
expect( Store::save( '/vieja/', '/otro' ) instanceof WP_Error, 'Duplicate effective origin rejected' );
expect( Store::save( '/nueva', '/vieja' ) instanceof WP_Error, 'Two-rule cycle rejected' );
expect( Store::save( '/nueva', '/final' ) === 2, 'Chained but acyclic rule accepted for diagnostics' );
$counts = Store::diagnostics( Store::rows() );
expect( $counts['stats']['total'] === 2 && $counts['stats']['chains'] >= 1, 'Structural diagnostics detect chains' );
Store::record_hit( 1 );
expect( Store::get( 1 )->hits === 1, 'Hits incremented atomically' );
expect( Store::save( '/vieja', '/otra', 301, 1 ) === 1, 'Edit existing rule' );
expect( (int) Store::get( 1 )->status_code === 301, 'Edited HTTP status preserved' );
expect( Store::remove( 1 ) && ! Store::get( 1 ), 'Delete individual rule' );

expect( Assisted_Delete::allowed_object( 'post', 1 ), 'Native post supported' );
expect( Assisted_Delete::allowed_object( 'page', 2 ), 'Native page supported' );
expect( Assisted_Delete::allowed_object( 'product', 3 ), 'WooCommerce product supported' );
expect( Assisted_Delete::allowed_object( 'product_cat', 4 ), 'WooCommerce product category supported' );
expect( ! Assisted_Delete::allowed_object( 'page', 3 ), 'Wrong post type not silently changed' );
expect( ! Assisted_Delete::allowed_object( 'other_type', 1 ), 'Unknown entity types rejected' );
expect( false !== strpos( Assisted_Delete::confirmation_url( 'product_cat', 4 ), 'tc_id=4' ), 'Single confirmation URL' );

Engine::register();
Assisted_Delete::register();
expect( isset( $GLOBALS['tc_actions']['template_redirect'] ), 'Native WordPress runtime hook registered' );
expect( isset( $GLOBALS['tc_actions']['admin_post_taxocommerce_core_save_content'] ), 'Core status=trash interception available only when installed' );
expect( isset( $GLOBALS['tc_actions']['post_submitbox_misc_actions'] ), 'Classic editor button registered' );
expect( isset( $GLOBALS['tc_actions']['product_cat_edit_form_fields'] ), 'Native product category button registered' );
expect( isset( $GLOBALS['tc_actions']['enqueue_block_editor_assets'] ), 'Block editor extension registered' );

echo "TaxoCommerce Redirects rules/integration smoke tests passed.\n";
