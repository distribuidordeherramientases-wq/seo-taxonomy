<?php
/** Installation and removal of the canonical FAQ store. */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_FAQs_Lifecycle {
    const MANAGED = 'taxocommerce_faqs_table_managed';
    const SCHEMA = 'taxocommerce_faqs_category_schema';

    /** Called by the real WordPress activation hook, not by deactivation. */
    public static function activate( $network_wide = false ) {
        $result = self::on_sites( 'install_site', is_multisite() && $network_wide );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) );
        }
    }

    private static function install_site() {
        global $wpdb;
        // Reuse the existing, non-destructive installer; do not duplicate schema.
        TaxoCommerce_FAQs::install();
        $table = $wpdb->prefix . 'seo_faq';
        $exists = self::table_exists( $table );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lifecycle migration or removal on optional custom FAQ tables; fresh DB state is required, never cache schema or destructive operations.
        $column = $exists ? $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'product_cat_id' ) ) : null;
        if ( ! $exists || 'product_cat_id' !== $column ) {
            delete_option( self::SCHEMA );
            return new WP_Error( 'taxocommerce_faqs_install', 'No se ha podido crear o actualizar la tabla de FAQs. Revisa los permisos CREATE/ALTER de la base de datos.' );
        }
        update_option( self::MANAGED, 1, false );
        return true;
    }

    private static function table_exists( $table ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lifecycle migration or removal on optional custom FAQ tables; fresh DB state is required, never cache schema or destructive operations.
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    /** Only uninstall.php may invoke permanent cleanup. */
    public static function uninstall() {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            return;
        }
        $result = self::on_sites( 'uninstall_site', is_multisite() );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) );
        }
    }

    private static function uninstall_site() {
        global $wpdb;
        // Old releases set SCHEMA on activation. Do not remove a legacy table
        // on sites where this extension has only been uploaded, never used.
        if ( ! get_option( self::MANAGED, false ) && ! get_option( self::SCHEMA, false ) ) {
            return true;
        }
        $table = $wpdb->prefix . 'seo_faq';
        $relations = $wpdb->prefix . 'seo_object_vocabulary';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Lifecycle migration or removal on optional custom FAQ tables; fresh DB state is required, never cache schema or destructive operations.
        if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) {
            return new WP_Error( 'taxocommerce_faqs_remove_table', 'No se pudo borrar la tabla de FAQs. La desinstalación no ha terminado.' );
        }
        // Delete FAQ assignments only. Never drop Vocabulary or its terms.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lifecycle migration or removal on optional custom FAQ tables; fresh DB state is required, never cache schema or destructive operations.
        if ( self::table_exists( $relations ) && false === $wpdb->delete( $relations, array( 'object_type' => 'faq' ), array( '%s' ) ) ) {
            return new WP_Error( 'taxocommerce_faqs_remove_relations', 'La tabla de FAQs se ha borrado, pero quedan relaciones de FAQs por limpiar. Repite la desinstalación.' );
        }
        // Delete only this module's short-lived notices, through WP cache APIs.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lifecycle migration or removal on optional custom FAQ tables; fresh DB state is required, never cache schema or destructive operations.
        $names = $wpdb->get_col( $wpdb->prepare(
            'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
            $wpdb->options,
            $wpdb->esc_like( '_transient_taxocommerce_faqs_notice_' ) . '%',
            $wpdb->esc_like( '_transient_timeout_taxocommerce_faqs_notice_' ) . '%'
        ) );
        foreach ( (array) $names as $name ) {
            $key = preg_replace( '/^_transient_(?:timeout_)?/', '', $name );
            delete_transient( $key );
        }
        delete_transient( 'taxocommerce_faqs_notice_' . get_current_user_id() );
        delete_option( self::SCHEMA );
        delete_option( self::MANAGED );
        return true;
    }

    /** Iterate bounded pages of site IDs; always restore the original blog. */
    private static function on_sites( $operation, $network ) {
        if ( ! $network ) {
            return self::$operation();
        }
        $offset = 0;
        do {
            $ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ) );
            foreach ( $ids as $id ) {
                switch_to_blog( (int) $id );
                try {
                    $result = self::$operation();
                } finally {
                    restore_current_blog();
                }
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }
            $offset += count( $ids );
        } while ( count( $ids ) === 100 );
        return true;
    }

    /** Visible warning before an administrator considers deleting the module. */
    public static function removal_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
        $type = isset( $_GET['tc_type'] ) ? sanitize_key( wp_unslash( $_GET['tc_type'] ) ) : '';
        if ( ! current_user_can( 'delete_plugins' ) || ( 'taxocommerce-faqs' !== $page && ( 'taxocommerce-core-content' !== $page || 'faqs' !== $type ) ) ) {
            return;
        }
        echo '<div class="notice notice-warning inline"><p>';
        echo esc_html__( 'Desactivar conserva las FAQs. Eliminar TaxoCommerce FAQs borra definitivamente la tabla seo_faq y todas sus preguntas, incluidas las compartidas con TaxoCommerce SEO. Exporta el CSV antes de eliminarlo. Las categorías y el vocabulario se conservan.', 'taxocommerce-faqs' );
        echo '</p></div>';
    }
}
