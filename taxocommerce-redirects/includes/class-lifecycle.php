<?php
/**
 * Installation and permanent removal for TaxoCommerce Redirects.
 */
namespace TaxoCommerce\Redirects;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {
    const MANAGED = 'tc_redirects_table_managed';
    const READY   = 'tc_redirects_schema_ready';

    public static function activate( $network_wide = false ) {
        $result = self::on_sites( 'install_site', is_multisite() && $network_wide );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ), '', array( 'back_link' => true ) );
        }
    }

    private static function install_site() {
        $result = Store::install();
        if ( is_wp_error( $result ) || ! Store::table_exists( true ) ) {
            delete_option( self::READY );
            return is_wp_error( $result ) ? $result : new \WP_Error(
                'tc_redirects_install_verify',
                'La tabla seo_redirects no está disponible después de la activación.'
            );
        }
        update_option( self::MANAGED, 1, false );
        return true;
    }

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

        // READY is the marker used by 0.1.0, so upgraded installations are owned too.
        if ( ! get_option( self::MANAGED, false ) && ! get_option( self::READY, false ) ) {
            return true;
        }

        $table = $wpdb->prefix . 'seo_redirects';
        if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) {
            return new \WP_Error(
                'tc_redirects_remove_table',
                'No se pudo borrar la tabla de redirecciones. La desinstalación no ha terminado.'
            );
        }

        // Assisted-delete markers point to redirect IDs that no longer exist.
        if ( isset( $wpdb->postmeta ) ) {
            $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_tc_redirects_assisted_rule' ), array( '%s' ) );
        }

        delete_option( 'tc_redirects_last_assisted_action' );
        delete_option( self::READY );
        delete_option( self::MANAGED );

        // Remove only this plugin's short-lived admin notices.
        if ( isset( $wpdb->options ) ) {
            $names = $wpdb->get_col( $wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
                $wpdb->options,
                $wpdb->esc_like( '_transient_tc_redirects_notice_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_tc_redirects_notice_' ) . '%'
            ) );
            foreach ( (array) $names as $name ) {
                $key = preg_replace( '/^_transient_(?:timeout_)?/', '', $name );
                delete_transient( $key );
            }
        }
        return true;
    }

    private static function on_sites( $operation, $network ) {
        if ( ! $network ) {
            return self::$operation();
        }
        $offset = 0;
        do {
            $ids = get_sites( array(
                'fields' => 'ids',
                'number' => 100,
                'offset' => $offset,
                'orderby' => 'id',
                'order' => 'ASC',
            ) );
            foreach ( (array) $ids as $id ) {
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
            $offset += count( (array) $ids );
        } while ( count( (array) $ids ) === 100 );
        return true;
    }

    public static function removal_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( ! current_user_can( 'delete_plugins' ) || Plugin::SLUG !== $page ) {
            return;
        }
        echo '<div class="notice notice-warning inline"><p>';
        echo esc_html__( 'Desactivar conserva las redirecciones. Eliminar TaxoCommerce Redirects borra definitivamente la tabla seo_redirects y todas sus reglas, incluidas las compartidas con TaxoCommerce SEO. Exporta una copia antes de eliminar el plugin.', 'taxocommerce-redirects' );
        echo '</p></div>';
    }
}
