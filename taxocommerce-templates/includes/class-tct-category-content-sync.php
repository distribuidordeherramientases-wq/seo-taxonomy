<?php
/**
 * Explicit administrator reconciliation of WooCommerce category descriptions
 * with the optional legacy SEO content table used by category templates.
 *
 * No changes are made on activation, on page load or on term edits. Only
 * selected rows are updated after nonce/capability and optimistic checks.
 *
 * @package TaxoCommerceTemplates
 */
defined( 'ABSPATH' ) || exit;

final class TCT_Category_Content_Sync {
    const ACTION = 'tct_sync_category_content';
    const LIMIT = 30;

    /** @return string */
    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'seo_nodes';
    }

    /** @return bool */
    private static function available() {
        global $wpdb;
        if ( ! taxonomy_exists( 'product_cat' ) ) {
            return false;
        }
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Check optional site schema on demand.
        return $table === $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
        );
    }

    /**
     * Return the current effective legacy description row, if available.
     *
     * @param int $term_id WooCommerce category ID.
     * @return array|null
     */
    private static function stored( $term_id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read latest canonical category description, bounded by category ID.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, keywords FROM %i
                 WHERE object_type=%s AND object_id=%d AND seo_role=%s AND status=1
                 ORDER BY updated_at DESC, id DESC LIMIT 1",
                self::table(),
                'category',
                absint( $term_id ),
                'description'
            ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Fingerprint of BOTH read states for optimistic concurrency.
     *
     * @param string $native  Native term description.
     * @param string $legacy  SEO node description.
     * @return string
     */
    private static function fingerprint( $native, $legacy ) {
        return hash( 'sha256', (string) $native . "\0" . (string) $legacy );
    }

    /**
     * Read-only reconciliation UI inside Templates, without new top-level menus.
     */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage this site.', 'taxocommerce-templates' ) );
        }
        echo '<div class="wrap"><h2>' . esc_html__( 'Category descriptions', 'taxocommerce-templates' ) . '</h2>';
        echo '<p>' . esc_html__( 'Compare native WooCommerce descriptions with the legacy SEO descriptions used by templates. No data changes until you select rows and apply.', 'taxocommerce-templates' ) . '</p>';

        if ( ! self::available() ) {
            echo '<p>' . esc_html__( 'The optional legacy SEO table or WooCommerce taxonomy is unavailable. Nothing was changed.', 'taxocommerce-templates' ) . '</p></div>';
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
        $page = isset( $_GET['sync_page'] ) ? max( 1, absint( wp_unslash( $_GET['sync_page'] ) ) ) : 1;
        $terms = get_terms(
            array(
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'number'     => self::LIMIT,
                'offset'     => ( $page - 1 ) * self::LIMIT,
                'orderby'    => 'term_id',
                'order'      => 'ASC',
            )
        );
        if ( is_wp_error( $terms ) ) {
            echo '<p>' . esc_html( $terms->get_error_message() ) . '</p></div>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        wp_nonce_field( self::ACTION );
        echo '<table class="widefat striped"><thead><tr>';
        foreach ( array( ' ', __( 'Category', 'taxocommerce-templates' ), __( 'Current WooCommerce text', 'taxocommerce-templates' ), __( 'Legacy SEO text', 'taxocommerce-templates' ) ) as $header ) {
            echo '<th>' . esc_html( $header ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $differences = 0;
        foreach ( (array) $terms as $term ) {
            if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
                continue;
            }
            $native = (string) $term->description;
            $row = self::stored( $term->term_id );
            if ( ! $row || '' === trim( wp_strip_all_tags( $native ) ) || $native === (string) $row['keywords'] ) {
                continue;
            }
            ++$differences;
            $id = absint( $term->term_id );
            $fingerprint = self::fingerprint( $native, (string) $row['keywords'] );
            echo '<tr><td><input type="checkbox" name="term_ids[]" value="' . esc_attr( (string) $id ) . '">';
            echo '<input type="hidden" name="fingerprints[' . esc_attr( (string) $id ) . ']" value="' . esc_attr( $fingerprint ) . '"></td>';
            echo '<td>' . esc_html( (string) $term->name ) . ' (#' . esc_html( (string) $id ) . ')</td>';
            echo '<td>' . esc_html( wp_html_excerpt( wp_strip_all_tags( $native ), 210, '…' ) ) . '</td>';
            echo '<td>' . esc_html( wp_html_excerpt( wp_strip_all_tags( (string) $row['keywords'] ), 210, '…' ) ) . '</td></tr>';
        }
        if ( 0 === $differences ) {
            echo '<tr><td colspan="4">' . esc_html__( 'No differences were found on this page.', 'taxocommerce-templates' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ( $differences ) {
            submit_button( __( 'Synchronize selected category descriptions', 'taxocommerce-templates' ), 'primary', 'submit', true );
        }
        echo '</form>';
        $url = add_query_arg(
            array( 'page' => 'taxocommerce-templates', 'tab' => 'category-sync', 'sync_page' => $page + 1 ),
            admin_url( 'admin.php' )
        );
        echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Next page', 'taxocommerce-templates' ) . '</a></p>';
        echo '</div>';
    }

    /**
     * Apply only selected, previewed corrections after rechecking both sources.
     */
    public static function handle() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage this site.', 'taxocommerce-templates' ) );
        }
        check_admin_referer( self::ACTION );
        if ( ! self::available() ) {
            wp_die( esc_html__( 'The optional SEO table or WooCommerce taxonomy is unavailable.', 'taxocommerce-templates' ) );
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every array member is immediately reduced to a positive integer.
        $raw_ids = isset( $_POST['term_ids'] ) && is_array( $_POST['term_ids'] )
            ? array_map( 'absint', wp_unslash( $_POST['term_ids'] ) ) : array();
        $ids = array_slice( array_values( array_unique( array_filter( $raw_ids ) ) ), 0, self::LIMIT );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each submitted fingerprint is sanitized before hash_equals.
        $hashes = isset( $_POST['fingerprints'] ) && is_array( $_POST['fingerprints'] )
            ? map_deep( wp_unslash( $_POST['fingerprints'] ), 'sanitize_text_field' ) : array();
        $updated = 0;
        $skipped = 0;
        global $wpdb;
        foreach ( $ids as $id ) {
            $term = get_term( $id, 'product_cat' );
            if ( ! $term || is_wp_error( $term ) ) {
                ++$skipped;
                continue;
            }
            $native = (string) $term->description;
            $row = self::stored( $id );
            $expected = isset( $hashes[ $id ] ) && is_scalar( $hashes[ $id ] )
                ? sanitize_text_field( (string) $hashes[ $id ] ) : '';
            if ( ! $row || '' === trim( wp_strip_all_tags( $native ) ) || ! hash_equals( self::fingerprint( $native, (string) $row['keywords'] ), $expected ) ) {
                ++$skipped;
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit, nonce-checked synchronization of one existing SEO node, with optimistic fingerprint.
            $result = $wpdb->update(
                self::table(),
                array(
                    'keywords'   => wp_kses_post( $native ),
                    'updated_at' => current_time( 'mysql', true ),
                ),
                array( 'id' => absint( $row['id'] ) ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            if ( false !== $result && $result > 0 ) {
                ++$updated;
            } else {
                ++$skipped;
            }
        }
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'taxocommerce-templates',
                    'tab' => 'category-sync',
                    'tct_notice' => sprintf(
                        /* translators: %1$d number of updated categories, %2$d number skipped. */
                        __( 'Categories updated: %1$d. Skipped: %2$d.', 'taxocommerce-templates' ),
                        $updated,
                        $skipped
                    ),
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }
}
