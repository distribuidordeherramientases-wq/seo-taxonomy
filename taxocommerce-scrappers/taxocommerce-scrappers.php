<?php
/**
 * Plugin Name: TaxoCommerce Scrappers
 * Description: External market and product research using the legacy Ojeador and Ingeniero engines with shared SerpApi and ScraperAPI connections.
 * Version: 0.1.2
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-scrappers
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_SCRAPPERS_VERSION', '0.1.2' );
define( 'TAXOCOMMERCE_SCRAPPERS_FILE', __FILE__ );
define( 'TAXOCOMMERCE_SCRAPPERS_PATH', plugin_dir_path( __FILE__ ) );
define( 'TAXOCOMMERCE_SCRAPPERS_URL', plugin_dir_url( __FILE__ ) );

require_once TAXOCOMMERCE_SCRAPPERS_PATH . 'includes/class-taxocommerce-scrappers-auto-test.php';
require_once TAXOCOMMERCE_SCRAPPERS_PATH . 'includes/class-taxocommerce-scrappers-manual.php';

// During the migration the historical monolith may still be active. Reuse its
// already-loaded classes instead of declaring the same legacy class names twice.
if ( ! class_exists( 'SEO_Ojeador_Admin', false ) ) {
    require_once TAXOCOMMERCE_SCRAPPERS_PATH . 'includes/ojeador/ojeador-bootstrap.php';
}
if ( ! class_exists( 'SEO_Ingeniero', false ) ) {
    if ( ! interface_exists( 'SEO_Managed_Service_Process', false ) ) {
        require_once TAXOCOMMERCE_SCRAPPERS_PATH . 'includes/class-seo-managed-service-process.php';
    }
    require_once TAXOCOMMERCE_SCRAPPERS_PATH . 'includes/ingeniero/ingeniero-bootstrap.php';
}

final class TaxoCommerce_Scrappers {
    public static function boot() {
        // The standalone Ingeniero bootstrap does not initialize its admin
        // controller. Do it here only when its actions have not been registered.
        if ( class_exists( 'SEO_Ingeniero_Admin' ) && ! has_action( 'admin_post_seo_ingeniero_prepare', array( 'SEO_Ingeniero_Admin', 'handle_prepare' ) ) ) {
            SEO_Ingeniero_Admin::init();
        }

        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 65 );
        add_action( 'taxocommerce_core_register_modules', array( __CLASS__, 'register_core' ) );

        if ( function_exists( 'taxocommerce_core' ) && did_action( 'taxocommerce_core_loaded' ) ) {
            self::register_core( taxocommerce_core() );
        }
    }

    public static function activate() {
        if ( class_exists( 'SEO_Ojeador_DB' ) ) {
            SEO_Ojeador_DB::maybe_install();
        }
        if ( class_exists( 'SEO_Ingeniero_DB' ) ) {
            SEO_Ingeniero_DB::maybe_install();
        }
    }

    public static function menu() {
        if ( function_exists( 'taxocommerce_core' ) ) {
            add_submenu_page(
                'taxocommerce-core',
                'TaxoCommerce Scrappers',
                'Scrappers',
                'manage_options',
                'taxocommerce-scrappers',
                array( __CLASS__, 'screen' )
            );
            return;
        }

        add_menu_page(
            'TaxoCommerce Scrappers',
            'Scrappers',
            'manage_options',
            'taxocommerce-scrappers',
            array( __CLASS__, 'screen' ),
            'dashicons-search',
            63
        );
    }

    public static function register_core( $core ) {
        if ( ! is_object( $core ) || ! method_exists( $core, 'register_module' ) ) {
            return;
        }

        $core->register_module(
            'scrappers',
            array(
                'name'        => 'TaxoCommerce Scrappers',
                'version'     => TAXOCOMMERCE_SCRAPPERS_VERSION,
                'status'      => 'active',
                'description' => 'SerpApi/ScraperAPI connections, market price intelligence with Ojeador, and technical research with Ingeniero.',
                'admin_url'   => admin_url( 'admin.php?page=taxocommerce-scrappers' ),
            )
        );
    }

    private static function tab() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
        $tab = isset( $_GET['tcs_tab'] ) ? sanitize_key( wp_unslash( $_GET['tcs_tab'] ) ) : '';

        // Legacy Ojeador links only carry ojeador_tab. Keep them inside this plugin.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
        if ( '' === $tab && isset( $_GET['ojeador_tab'] ) ) {
            $tab = 'ojeador';
        }

        // Legacy Ingeniero links only carry tab=research/editorial/data/tests.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
        if ( '' === $tab && isset( $_GET['tab'] ) ) {
            $legacy = sanitize_key( wp_unslash( $_GET['tab'] ) );
            if ( in_array( $legacy, array( 'research', 'editorial', 'data', 'tests' ), true ) ) {
                $tab = 'ingeniero';
            }
        }

        return in_array( $tab, array( 'connections', 'ojeador', 'ingeniero', 'autotest', 'manual' ), true ) ? $tab : 'connections';
    }

    public static function screen() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage TaxoCommerce Scrappers.', 'taxocommerce-scrappers' ) );
        }

        $tab = self::tab();

        echo '<div class="wrap">';
        echo '<h1>TaxoCommerce Scrappers</h1>';
        echo '<p>External research layer. Ojeador and Ingeniero reuse the proven legacy engines while SerpApi and ScraperAPI are configured once for both services.</p>';
        echo '<nav class="nav-tab-wrapper" aria-label="TaxoCommerce Scrappers">';
        foreach ( array(
            'connections' => 'Connections',
            'ojeador'     => 'Ojeador',
            'ingeniero'   => 'Ingeniero',
            'autotest'   => 'Autotest',
            'manual'     => 'Manual',
        ) as $key => $label ) {
            $url = add_query_arg(
                array(
                    'page'    => 'taxocommerce-scrappers',
                    'tcs_tab' => $key,
                ),
                admin_url( 'admin.php' )
            );
            echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
        echo '</div>';

        if ( 'connections' === $tab ) {
            if ( class_exists( 'SEO_Ojeador_Admin' ) && is_callable( array( 'SEO_Ojeador_Admin', 'render_shared_google_connections' ) ) ) {
                SEO_Ojeador_Admin::render_shared_google_connections();
            }
            return;
        }

        if ( 'ojeador' === $tab ) {
            SEO_Ojeador_Admin::render();
            return;
        }

        if ( 'autotest' === $tab ) {
            TaxoCommerce_Scrappers_Auto_Test::render();
            return;
        }

        if ( 'manual' === $tab ) {
            TaxoCommerce_Scrappers_Manual::render();
            return;
        }

        SEO_Ingeniero_Admin::render();
    }
}

register_activation_hook( __FILE__, array( 'TaxoCommerce_Scrappers', 'activate' ) );
add_action( 'plugins_loaded', array( 'TaxoCommerce_Scrappers', 'boot' ), 100 );
