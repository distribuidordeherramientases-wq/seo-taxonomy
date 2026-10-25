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
        return (string) $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
        ) === $table;
    }

    public static function count_active() {
        if ( ! self::available() ) {
            return null;
        }
        global $wpdb;
        $table = esc_sql( self::table() );
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned table, fixed query.
        return absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE active=1" ) );
    }

    public static function batch( $cursor, $limit, $search = '' ) {
        if ( ! self::available() ) {
            return array();
        }
        global $wpdb;
        $table = esc_sql( self::table() );
        $cursor = absint( $cursor );
        $limit = max( 1, min( 200, absint( $limit ) ) );
        $search = trim( sanitize_text_field( (string) $search ) );

        if ( ctype_digit( $search ) ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,object_type,object_id,product_cat_id,question,answer,active,updated_at
                     FROM {$table}
                     WHERE active=1 AND id>%d AND id=%d
                     ORDER BY id ASC
                     LIMIT %d",
                    $cursor,
                    absint( $search ),
                    $limit
                ),
                ARRAY_A
            );
        } elseif ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,object_type,object_id,product_cat_id,question,answer,active,updated_at
                     FROM {$table}
                     WHERE active=1 AND id>%d AND question LIKE %s
                     ORDER BY id ASC
                     LIMIT %d",
                    $cursor,
                    $like,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,object_type,object_id,product_cat_id,question,answer,active,updated_at
                     FROM {$table}
                     WHERE active=1 AND id>%d
                     ORDER BY id ASC
                     LIMIT %d",
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
        $table = esc_sql( self::table() );
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id,object_type,object_id,product_cat_id,question,answer,active,updated_at
                 FROM {$table}
                 WHERE id=%d
                 LIMIT 1",
                absint( $faq_id )
            ),
            ARRAY_A
        );
        return is_array( $row ) && $row ? new FAQ_Record( $row ) : null;
    }
}
