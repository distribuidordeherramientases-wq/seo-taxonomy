<?php
/**
 * Plugin Name: TaxoCommerce Database
 * Description: Original Clean Database and SEO Data Table migrated into one independent plugin, without Data Layer.
 * Version: 0.4.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-database
 */
defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_DATABASE_VERSION', '0.4.0' );
define( 'TAXOCOMMERCE_DATABASE_FILE', __FILE__ );
define( 'TAXOCOMMERCE_DATABASE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_DATABASE_URL', plugin_dir_url( __FILE__ ) );

// The only two original Database services. Namespacing prevents collisions
// with the former monolith while keeping their independent functionality.
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/legacy/seo-database-clean.php';
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/legacy/seo-database-report.php';

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
            ? sanitize_key( wp_unslash( $_GET['tcdb_screen'] ) ) : 'clean';
        if ( ! in_array( $screen, array( 'clean', 'table' ), true ) ) {
            $screen = 'clean';
        }
        $base = admin_url( 'admin.php?page=taxocommerce-database' );
        echo '<div class="wrap"><h1>TaxoCommerce Database</h1>';
        echo '<nav class="nav-tab-wrapper" aria-label="Servicios originales de base de datos">';
        echo '<a class="nav-tab' . ( 'clean' === $screen ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&tcdb_screen=clean' ) . '">Clean Database</a>';
        echo '<a class="nav-tab' . ( 'table' === $screen ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&tcdb_screen=table' ) . '">SEO Data Table</a>';
        echo '</nav></div>';
        if ( 'table' === $screen ) {
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
