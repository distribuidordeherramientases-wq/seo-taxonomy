<?php
/**
 * Canonical FAQ source adapter.
 *
 * TaxoCommerce FAQs intentionally reuses the historic {prefix}seo_faq table.
 * This adapter reads it directly without loading or depending on the monolith.
 */

namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

final class FAQ_Record {
    public $ID = 0;
    public $post_type = 'taxocommerce_faq';
    public $post_title = '';
    public $post_name = '';
    public $post_content = '';
    public $post_excerpt = '';
    public $object_type = 0;
    public $object_id = 0;
    public $product_cat_id = 0;
    public $active = 1;
    public $updated_at = '';

    public function __construct( $row ) {
        $row = is_array( $row ) ? $row : array();
        $this->ID             = absint( $row['id'] ?? 0 );
        $this->post_title     = (string) ( $row['question'] ?? '' );
        $this->post_name      = 'faq-' . $this->ID;
        $this->post_content   = (string) ( $row['answer'] ?? '' );
        $this->object_type    = absint( $row['object_type'] ?? 0 );
        $this->object_id      = absint( $row['object_id'] ?? 0 );
        $this->product_cat_id = absint( $row['product_cat_id'] ?? 0 );
        $this->active         = absint( $row['active'] ?? 1 );
        $this->updated_at     = (string) ( $row['updated_at'] ?? '' );
    }
}

final class FAQ_Source {
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_faq';
    }

    public static function available() {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical FAQ storage is a custom table; live schema availability must reflect activation/migrations, and the prepared SHOW TABLES read has no equivalent WP post API.
        return (string) $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
        ) === $table;
    }

    public static function count_active() {
        if ( ! self::available() ) {
            return null;
        }
        global $wpdb;
        $table = self::table();
        return absint(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live count of active canonical FAQ rows is needed for current audit coverage; a persistent cache could report stale totals after FAQ edits.
            $wpdb->get_var(
                $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE active=1', $table )
            )
        );
    }

    public static function batch( $cursor, $limit, $search = '' ) {
        if ( ! self::available() ) {
            return array();
        }
        global $wpdb;
        $table = self::table();
        $cursor = absint( $cursor );
        $limit = max( 1, min( 200, absint( $limit ) ) );
        $search = trim( sanitize_text_field( (string) $search ) );

        if ( ctype_digit( $search ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact FAQ ID lookup uses the canonical table, prepared identifiers and a maximum of 200 rows; fresh results are required while auditing.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,object_type,object_id,product_cat_id,question,answer,active,updated_at
                     FROM %i
                     WHERE active=1 AND id>%d AND id=%d
                     ORDER BY id ASC
                     LIMIT %d",
                    $table,
                    $cursor,
                    absint( $search ),
                    $limit
                ),
                ARRAY_A
            );
        } elseif ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical FAQ text search has prepared LIKE and a strict 200-row limit; cached results could obscure edits during manual audits.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,object_type,object_id,product_cat_id,question,answer,active,updated_at
                     FROM %i
                     WHERE active=1 AND id>%d AND question LIKE %s
                     ORDER BY id ASC
                     LIMIT %d",
                    $table,
                    $cursor,
                    $like,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical FAQ cursor batch uses prepared IDs and a strict 200-row limit; reusing cached rows would hide updates during an audit.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,object_type,object_id,product_cat_id,question,answer,active,updated_at
                     FROM %i
                     WHERE active=1 AND id>%d
                     ORDER BY id ASC
                     LIMIT %d",
                    $table,
                    $cursor,
                    $limit
                ),
                ARRAY_A
            );
        }

        $out = array();
        foreach ( (array) $rows as $row ) {
            $out[] = new FAQ_Record( $row );
        }
        return $out;
    }

    public static function get( $faq_id ) {
        if ( ! self::available() ) {
            return null;
        }
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical FAQ record is read by unique ID with LIMIT 1; WordPress post APIs cannot read this table and results must reflect edits.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id,object_type,object_id,product_cat_id,question,answer,active,updated_at
                 FROM %i
                 WHERE id=%d
                 LIMIT 1",
                $table,
                absint( $faq_id )
            ),
            ARRAY_A
        );
        return is_array( $row ) && $row ? new FAQ_Record( $row ) : null;
    }
}
