<?php
/**
 * Plugin Name: TaxoCommerce Redirects - SEO Redirect Manager
 * Plugin URI: https://www.distribuidordeherramientas.es/
 * Description: Redirecciones 301/302 y borrado asistido de productos, categorias, posts y paginas. Funciona con o sin TaxoCommerce Core.
 * Version: 0.1.3
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-redirects
 */

namespace TaxoCommerce\Redirects;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-lifecycle.php';
require_once __DIR__ . '/includes/class-engine.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-assisted-delete.php';

final class Plugin {
    const VERSION = '0.1.3';
    const SLUG = 'taxocommerce-redirects';
    const CONFIRM_SLUG = 'taxocommerce-redirects-confirm';

    public static function boot() {
        Engine::register();
        Admin::register();
        Assisted_Delete::register();
        // Optional: Database may register reversible changes to our own SQL rules.
        add_filter( 'tcdb_rollback_supported_tables', array( __NAMESPACE__ . '\\Store', 'register_rollback_table' ) );
        add_action( 'admin_notices', array( __NAMESPACE__ . '\\Lifecycle', 'removal_notice' ) );
        add_action( 'taxocommerce_core_register_modules', array( __CLASS__, 'register_core' ) );

        // Core boots at plugins_loaded priority 20; Redirects boots at 30.
        // Register now if Core already dispatched the registration hook.
        // This is optional: Redirects still works without Core.
        if ( function_exists( 'taxocommerce_core' ) && did_action( 'taxocommerce_core_register_modules' ) ) {
            self::register_core( taxocommerce_core() );
        }
    }

    public static function register_core( $core ) {
        if ( is_object( $core ) && method_exists( $core, 'register_module' ) ) {
            $core->register_module(
                'redirects',
                array(
                    'name' => 'TaxoCommerce Redirects',
                    'version' => self::VERSION,
                    'status' => 'active',
                    'description' => 'Redirecciones y borrado asistido con el gestor independiente.',
                    'admin_url' => admin_url( 'admin.php?page=' . self::SLUG ),
                )
            );
        }
    }
}

register_activation_hook( __FILE__, array( __NAMESPACE__ . '\Lifecycle', 'activate' ) );
add_action( 'plugins_loaded', array( __NAMESPACE__ . '\Plugin', 'boot' ), 30 );
