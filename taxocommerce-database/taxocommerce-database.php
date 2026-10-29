<?php
/**
 * Plugin Name: TaxoCommerce Database
 * Description: Original Clean Database and SEO Data Table migrated into one independent plugin, without Data Layer.
 * Version: 0.4.5
 * Update URI: https://www.distribuidordeherramientas.es/taxocommerce-updates/taxocommerce-database/
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-database
 */
defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_DATABASE_VERSION', '0.4.5' );
define( 'TAXOCOMMERCE_DATABASE_FILE', __FILE__ );
define( 'TAXOCOMMERCE_DATABASE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_DATABASE_URL', plugin_dir_url( __FILE__ ) );

// The only two original Database services. Namespacing prevents collisions
// with the former monolith while keeping their independent functionality.
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/legacy/seo-database-clean.php';
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/legacy/seo-database-report.php';
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/class-tcdb-manual.php';
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/class-tcdb-auto-test.php';

final class TaxoCommerce_Database {
    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
        add_action( 'taxocommerce_core_register_modules', array( __CLASS__, 'register_core' ) );
        if ( function_exists( 'taxocommerce_core' ) && did_action( 'taxocommerce_core_loaded' ) ) {
            self::register_core( taxocommerce_core() );
        }
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para esta herramienta.', 'taxocommerce-database' ) );
        }
        // Read-only tab routing; writes remain nonce-protected in the original services.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
        $screen = isset( $_GET['tcdb_screen'] ) && is_string( $_GET['tcdb_screen'] )
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation and filtering; GET cannot change data.
            ? sanitize_key( wp_unslash( $_GET['tcdb_screen'] ) ) : 'clean';
        if ( ! in_array( $screen, array( 'clean', 'table', 'manual', 'auto-test' ), true ) ) {
            $screen = 'clean';
        }
        // Existing routing parameters remain unchanged for filters, POST actions and export links.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector.
        $clean_tab = isset( $_GET['seo_clean_db_tab'] ) && is_string( $_GET['seo_clean_db_tab'] )
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation and filtering; GET cannot change data.
            ? sanitize_key( wp_unslash( $_GET['seo_clean_db_tab'] ) ) : 'data_errors';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selector.
        $table_section = isset( $_GET['section'] ) && is_string( $_GET['section'] )
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation and filtering; GET cannot change data.
            ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'overview';
        $tabs = array(
            'clean:data_errors'     => array( 'Errores en datos', 'clean', 'seo_clean_db_tab', 'data_errors' ),
            'clean:database_cleanup'=> array( 'Limpieza BBDD', 'clean', 'seo_clean_db_tab', 'database_cleanup' ),
            'clean:export'          => array( 'Exportar BBDD', 'clean', 'seo_clean_db_tab', 'export' ),
            'table:overview'        => array( 'Resumen tablas', 'table', 'section', 'overview' ),
            'table:explorer'        => array( 'Explorar tablas', 'table', 'section', 'explorer' ),
            'table:export'          => array( 'Exportar tablas', 'table', 'section', 'export' ),
            'manual'                => array( 'Manual', 'manual', 'section', 'manual' ),
            'auto-test'             => array( 'Auto Test', 'auto-test', 'section', 'auto-test' ),
        );
        $active = in_array( $screen, array( 'manual', 'auto-test' ), true ) ? $screen : ( 'table' === $screen ? 'table:' . $table_section : 'clean:' . $clean_tab );
        if ( ! isset( $tabs[ $active ] ) ) {
            $active = 'table' === $screen ? 'table:overview' : 'clean:data_errors';
        }
        $base = admin_url( 'admin.php?page=taxocommerce-database' );
        echo '<div class="wrap"><h1>TaxoCommerce Database</h1>';
        echo '<style>.tcdb-flat-tabs{display:flex;flex-wrap:nowrap;overflow-x:auto;overflow-y:hidden;white-space:nowrap;gap:2px;max-width:100%;scrollbar-width:thin}.tcdb-flat-tabs .nav-tab{flex:0 0 auto;margin-left:0}@media(max-width:782px){.tcdb-flat-tabs{padding-bottom:4px}.tcdb-flat-tabs .nav-tab{font-size:13px;padding:9px 11px}}</style>';
        echo '<nav class="nav-tab-wrapper tcdb-flat-tabs" aria-label="Herramientas de base de datos">';
        foreach ( $tabs as $key => $tab ) {
            $url = add_query_arg( array( 'tcdb_screen' => $tab[1], $tab[2] => $tab[3] ), $base );
            echo '<a class="nav-tab' . ( $active === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $active === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $tab[0] ) . '</a>';
        }
        echo '</nav></div>';
        if ( 'auto-test' === $screen ) {
            TCDB_Auto_Test::render();
        } elseif ( 'manual' === $screen ) {
            TCDB_Manual::render();
        } elseif ( 'table' === $screen ) {
            \TCDB_Legacy\seo_data_table_page();
        } else {
            \TCDB_Legacy\seo_clean_db_page();
        }
    }

    public static function menu() {
        if ( function_exists( 'taxocommerce_core' ) ) {
            add_submenu_page(
                'taxocommerce-core', 'TaxoCommerce Database', 'Database',
                'manage_options', 'taxocommerce-database', array( __CLASS__, 'render' )
            );
        } else {
            add_menu_page(
                'TaxoCommerce Database', 'TaxoCommerce DB', 'manage_options',
                'taxocommerce-database', array( __CLASS__, 'render' ),
                'dashicons-database', 63
            );
        }
    }

    public static function register_core( $core ) {
        if ( is_object( $core ) && method_exists( $core, 'register_module' ) ) {
            $core->register_module( 'database', array(
                'name' => 'TaxoCommerce Database',
                'version' => TAXOCOMMERCE_DATABASE_VERSION,
                'status' => 'active',
                'description' => 'Clean Database y SEO Data Table originales, sin Data Layer.',
                'admin_url' => admin_url( 'admin.php?page=taxocommerce-database' ),
            ) );
        }
    }
}
add_action( 'plugins_loaded', array( 'TaxoCommerce_Database', 'boot' ), 40 );
