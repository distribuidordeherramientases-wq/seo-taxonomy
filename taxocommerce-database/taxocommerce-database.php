<?php
/**
 * Plugin Name: TaxoCommerce Database
 * Description: Auditoría MySQL, exploración de tablas, copias SQL/CSV y mantenimiento controlado. Independiente de TaxoCommerce Core.
 * Version: 0.2.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-database
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_DATABASE_VERSION', '0.2.1' );
define( 'TAXOCOMMERCE_DATABASE_FILE', __FILE__ );
define( 'TAXOCOMMERCE_DATABASE_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_DATABASE_URL', plugin_dir_url( __FILE__ ) );

require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/class-tcdb-storage.php';
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/class-tcdb-export.php';
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/class-tcdb-import.php';
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/class-tcdb-cleanup.php';
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/class-tcdb-diagnostics.php';
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/class-tcdb-maintenance.php';
require_once TAXOCOMMERCE_DATABASE_PATH . 'includes/class-tcdb-admin.php';

final class TaxoCommerce_Database {
    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
        add_action( 'admin_enqueue_scripts', array( 'TCDB_Admin', 'register_styles' ) );
        add_action( 'admin_post_tcdb_audit', array( 'TCDB_Storage', 'handle_audit' ) );
        add_action( 'admin_post_tcdb_export', array( 'TCDB_Export', 'handle' ) );
        add_action( 'admin_post_tcdb_import_preview', array( 'TCDB_Import', 'preview' ) );
        add_action( 'admin_post_tcdb_import_apply', array( 'TCDB_Import', 'apply' ) );
        add_action( 'admin_post_tcdb_cleanup', array( 'TCDB_Cleanup', 'handle' ) );
        add_action( 'admin_post_tcdb_advanced_audit', array( 'TCDB_Diagnostics', 'handle_audit' ) );
        add_action( 'admin_post_tcdb_advanced_cleanup', array( 'TCDB_Diagnostics', 'handle_cleanup' ) );
        add_action( 'admin_post_tcdb_maintenance', array( 'TCDB_Maintenance', 'handle' ) );
        add_action( 'taxocommerce_core_register_modules', array( __CLASS__, 'register_core' ) );

        // Core may have completed its plugins_loaded hook already.
        if ( function_exists( 'taxocommerce_core' ) && did_action( 'taxocommerce_core_loaded' ) ) {
            self::register_core( taxocommerce_core() );
        }
    }

    public static function menu() {
        $slug = 'taxocommerce-database';
        if ( function_exists( 'taxocommerce_core' ) ) {
            add_submenu_page(
                'taxocommerce-core',
                'TaxoCommerce Database',
                'Database',
                'manage_options',
                $slug,
                array( 'TCDB_Admin', 'render' )
            );
        } else {
            add_menu_page(
                'TaxoCommerce Database',
                'TaxoCommerce DB',
                'manage_options',
                $slug,
                array( 'TCDB_Admin', 'render' ),
                'dashicons-database',
                63
            );
        }
    }

    public static function register_core( $core ) {
        if ( is_object( $core ) && method_exists( $core, 'register_module' ) ) {
            $core->register_module( 'database', array(
                'name'        => 'TaxoCommerce Database',
                'version'     => TAXOCOMMERCE_DATABASE_VERSION,
                'status'      => 'active',
                'description' => 'Administración de MySQL, copias, importaciones y mantenimiento independiente.',
                'admin_url'   => admin_url( 'admin.php?page=taxocommerce-database' ),
            ) );
        }
    }
}
add_action( 'plugins_loaded', array( 'TaxoCommerce_Database', 'boot' ), 40 );
