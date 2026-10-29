<?php
/**
 * Standalone regression for the complete legacy Clean Database restoration.
 * No WordPress or MySQL server required.
 */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ );
define( 'TAXOCOMMERCE_DATABASE_VERSION', '0.3.0' );
define( 'TAXOCOMMERCE_DATABASE_URL', 'https://example.test/wp-content/plugins/database/' );
function add_action( $hook, $callback ) { return true; }
class TCDB_Fake_Legacy_WPDB {
    public $prefix = 'wp_';
    public $queries = array();
    public function prepare( $sql, $values ) {
        $i = 0;
        return preg_replace_callback( '/%[id]/', static function ( $m ) use ( &$i, $values ) {
            $v = $values[ $i++ ];
            return '%i' === $m[0] ? chr( 96 ) . $v . chr( 96 ) : (string) (int) $v;
        }, $sql );
    }
    public function query( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'DELETE FROM' ) ) {
            preg_match( '/ IN \\(([^)]+)\\)/', $sql, $m );
            return empty( $m[1] ) ? false : substr_count( $m[1], ',' ) + 1;
        }
        return 0;
    }
}
$wpdb = new TCDB_Fake_Legacy_WPDB();
require dirname( __DIR__ ) . '/includes/legacy/seo-database-clean.php';
function tcdb_legacy_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: " . $message . "\n" ); exit( 1 ); }
}
$cleanup = file_get_contents( dirname( __DIR__ ) . '/includes/legacy/seo-database-clean.php' );
foreach ( array(
    'clean_revisions', 'clean_auto_drafts', 'clean_trashed_posts', 'clean_spam_comments',
    'clean_trashed_comments', 'clean_expired_transients', 'clean_orphan_postmeta',
    'clean_orphan_commentmeta', 'clean_orphan_termmeta', 'clean_orphan_term_relationships',
    'clean_wc_sessions', 'clean_orphan_usermeta', 'clean_as_orphan_logs',
    'clean_as_old_completed_logs', 'clean_as_orphan_claims',
    'clean_wc_expired_reserved_stock', 'clean_wc_orphan_order_itemmeta',
) as $action ) {
    tcdb_legacy_assert( false !== strpos( $cleanup, "case '" . $action . "':" ), 'Legacy cleanup action present: ' . $action );
}
tcdb_legacy_assert( false === strpos( $cleanup, 'SEO_Data_Layer' ), 'No Data Layer dependency' );
tcdb_legacy_assert( false === strpos( $cleanup, 'SEO_Data_Rollback' ), 'No rollback engine' );
tcdb_legacy_assert( false !== strpos( $cleanup, 'seo_clean_db_analyze_relations(' ), 'All SEO relation checks' );
tcdb_legacy_assert( false !== strpos( $cleanup, 'seo_clean_db_render_bulk_accept_lock_section(' ), 'Supplier lock checks' );
tcdb_legacy_assert( false !== strpos( $cleanup, 'seo_clean_db_get_multiple_ambito_products(' ), 'Multiple scope checks' );
tcdb_legacy_assert( false !== strpos( $cleanup, 'seo_clean_db_get_broken_categories(' ), 'Broken category checks' );
$one = \TCDB_Legacy\seo_clean_db_transactional_delete_ids( 'relations', range( 1, 1201 ) );
tcdb_legacy_assert( 1201 === $one['deleted'], 'More than 100 IDs deleted in one operation' );
tcdb_legacy_assert( 3 === count( array_filter( $wpdb->queries, static function ( $q ) { return false !== strpos( $q, 'DELETE FROM' ); } ) ), 'Three SQL batches 500+500+201' );
tcdb_legacy_assert( 'START TRANSACTION' === $wpdb->queries[0] && 'COMMIT' === end( $wpdb->queries ), 'Local transaction committed' );
$wpdb->queries = array();
$both = \TCDB_Legacy\seo_clean_db_delete_relation_node_ids( range( 1, 700 ), range( 1, 501 ) );
tcdb_legacy_assert( 700 === $both['relations'] && 501 === $both['nodes'], 'Both SEO tables cleaned atomically' );
tcdb_legacy_assert( 'COMMIT' === end( $wpdb->queries ), 'SEO repair transaction committed' );
echo "Legacy Clean DB standalone test OK: 17 cleanup actions, full SEO diagnostics, unbounded batched deletes, no Data Layer.\n";
