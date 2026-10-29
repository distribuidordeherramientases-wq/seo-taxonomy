<?php
/**
 * Plugin Name: TaxoCommerce Connections (Compatibility Bridge)
 * Description: Temporary compatibility bridge. External connections now live inside TaxoCommerce Insights.
 * Version: 0.3.0
 * Update URI: https://www.distribuidordeherramientas.es/taxocommerce-updates/taxocommerce-connections/
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-connections
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'TAXOCOMMERCE_CONNECTIONS_VERSION' ) ) {
	define( 'TAXOCOMMERCE_CONNECTIONS_VERSION', '0.3.0' );
}
if ( ! defined( 'TAXOCOMMERCE_CONNECTIONS_FILE' ) ) {
	define( 'TAXOCOMMERCE_CONNECTIONS_FILE', __FILE__ );
}
if ( ! defined( 'TAXOCOMMERCE_CONNECTIONS_PATH' ) ) {
	define( 'TAXOCOMMERCE_CONNECTIONS_PATH', plugin_dir_path( __FILE__ ) );
}

/**
 * Returns the new canonical Connections screen.
 *
 * @param array $args Optional query parameters.
 * @return string
 */
function taxocommerce_connections_insights_url( $args = array() ) {
	return add_query_arg(
		array_merge(
			array(
				'page'    => 'taxocommerce-google-bing',
				'tgb_tab' => 'connections',
			),
			is_array( $args ) ? $args : array()
		),
		admin_url( 'admin.php' )
	);
}

/**
 * Redirects historical Connections routes to Insights when the consolidated
 * plugin is active.
 */
function taxocommerce_connections_register_bridge() {
	if ( ! defined( 'TAXOCOMMERCE_GOOGLE_BING_VERSION' ) ) {
		return;
	}

	add_action(
		'admin_menu',
		static function () {
			add_submenu_page(
				null,
				__( 'Connections', 'taxocommerce-connections' ),
				__( 'Connections', 'taxocommerce-connections' ),
				'manage_options',
				'taxocommerce-connections',
				static function () {
					wp_safe_redirect( taxocommerce_connections_insights_url() );
					exit;
				}
			);
		},
		1
	);

	add_action(
		'admin_init',
		static function () {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only legacy-route redirect.
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			if ( 'taxocommerce-connections' !== $page ) {
				return;
			}
			$args = array();
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserves a display-only notice during redirect.
			if ( isset( $_GET['tc_conn_notice'] ) ) {
				$args['tc_conn_notice'] = sanitize_key( wp_unslash( $_GET['tc_conn_notice'] ) );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserves a display-only notice during redirect.
			if ( isset( $_GET['tc_conn_type'] ) ) {
				$args['tc_conn_type'] = sanitize_key( wp_unslash( $_GET['tc_conn_type'] ) );
			}
			wp_safe_redirect( taxocommerce_connections_insights_url( $args ) );
			exit;
		},
		1
	);
}

/**
 * Standalone fallback while the bridge remains installed.
 *
 * If Insights is ever deactivated accidentally, the historical plugin still
 * exposes its previous connection screen instead of making credentials
 * unreachable. No duplicate classes are loaded while Insights is active.
 */
function taxocommerce_connections_boot_fallback() {
	if ( defined( 'TAXOCOMMERCE_GOOGLE_BING_VERSION' ) ) {
		taxocommerce_connections_register_bridge();
		return;
	}

	require_once TAXOCOMMERCE_CONNECTIONS_PATH . 'includes/class-taxocommerce-connections-amazon.php';
	require_once TAXOCOMMERCE_CONNECTIONS_PATH . 'includes/class-taxocommerce-connections-pinterest.php';
	require_once TAXOCOMMERCE_CONNECTIONS_PATH . 'includes/class-taxocommerce-connections-google-bing.php';
	require_once TAXOCOMMERCE_CONNECTIONS_PATH . 'includes/class-taxocommerce-connections-admin.php';

	TaxoCommerce_Connections_Amazon::boot();
	TaxoCommerce_Connections_Pinterest::boot();
	TaxoCommerce_Connections_Google_Bing::boot();
	TaxoCommerce_Connections_Admin::boot();
}
add_action( 'plugins_loaded', 'taxocommerce_connections_boot_fallback', 60 );

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! class_exists( 'TaxoCommerce_Connections_Amazon', false ) ) {
			require_once TAXOCOMMERCE_CONNECTIONS_PATH . 'includes/class-taxocommerce-connections-amazon.php';
		}
		if ( ! class_exists( 'TaxoCommerce_Connections_Pinterest', false ) ) {
			require_once TAXOCOMMERCE_CONNECTIONS_PATH . 'includes/class-taxocommerce-connections-pinterest.php';
		}
		TaxoCommerce_Connections_Amazon::migrate_legacy_settings();
		TaxoCommerce_Connections_Pinterest::migrate_legacy_settings();
	}
);
