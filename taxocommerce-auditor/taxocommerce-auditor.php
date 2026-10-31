<?php
/**
 * Plugin Name: TaxoCommerce Auditor - Content Quality for WooCommerce
 * Description: Read-only WordPress and WooCommerce content quality audits with detailed KPIs and full JSON exports.
 * Version: 0.3.2
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-auditor
 */

namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-rules.php';
require_once __DIR__ . '/includes/class-editorial-specialist.php';
require_once __DIR__ . '/includes/class-category-review.php';
require_once __DIR__ . '/includes/class-priority.php';
require_once __DIR__ . '/includes/class-faq-source.php';
require_once __DIR__ . '/includes/class-export-store.php';
require_once __DIR__ . '/includes/class-decision-context.php';
require_once __DIR__ . '/includes/class-runner.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-manual.php';
require_once __DIR__ . '/includes/class-auto-test.php';

/**
 * Standalone entry point. No SEO_SYSTEM_* globals or legacy source files.
 */
final class Plugin {
    const VERSION = '0.3.2';
    const MENU_SLUG = 'taxocommerce-auditor';

    public static function boot() {
        Runner::register();
        Admin::register();
        Auto_Test::register();

        add_action( 'taxocommerce_core_register_modules', array( __CLASS__, 'register_core_module' ) );
        if ( function_exists( 'taxocommerce_core' ) && did_action( 'taxocommerce_core_loaded' ) ) {
            self::register_core_module( \taxocommerce_core() );
        }
    }

    public static function register_core_module( $core ) {
        if ( is_object( $core ) && method_exists( $core, 'register_module' ) ) {
            $core->register_module(
                'auditor',
                array(
                    'name'        => 'TaxoCommerce Auditor',
                    'version'     => self::VERSION,
                    'status'      => 'active',
                    'description' => 'Auditoria de calidad editorial por bloques, solo lectura.',
                    'admin_url'   => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
                )
            );
        }
    }
}

add_action( 'plugins_loaded', array( __NAMESPACE__ . '\\Plugin', 'boot' ), 30 );
register_deactivation_hook( __FILE__, array( __NAMESPACE__ . '\\Runner', 'deactivate' ) );
