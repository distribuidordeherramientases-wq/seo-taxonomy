<?php
/**
 * Plugin Name: TaxoCommerce Distribution - Feeds & Sitemaps for WooCommerce
 * Description: Publishes commercial feeds and professional XML sitemaps through independent bounded processes.
 * Version: 0.1.2
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-distribution
 */

defined( 'ABSPATH' ) || exit;

define( 'TAXOCOMMERCE_DISTRIBUTION_VERSION', '0.1.2' );
define( 'TAXOCOMMERCE_DISTRIBUTION_FILE', __FILE__ );
define( 'TAXOCOMMERCE_DISTRIBUTION_PATH', plugin_dir_path( __FILE__ ) );

if ( ! defined( 'TCDIST_FEED_VERSION' ) ) {
    define( 'TCDIST_FEED_VERSION', '1.5.0' );
}
if ( ! defined( 'TCDIST_FEED_DIR' ) ) {
    define( 'TCDIST_FEED_DIR', TAXOCOMMERCE_DISTRIBUTION_PATH . 'includes/feeds' );
}
if ( ! defined( 'TCDIST_FEED_GROUP' ) ) {
    define( 'TCDIST_FEED_GROUP', 'taxocommerce-distribution-feeds' );
}
if ( ! defined( 'TCDIST_SITEMAP_BATCH_SIZE' ) ) {
    define( 'TCDIST_SITEMAP_BATCH_SIZE', 1000 );
}
if ( ! defined( 'TCDIST_SITEMAP_SCHEMA_VERSION' ) ) {
    define( 'TCDIST_SITEMAP_SCHEMA_VERSION', 2 );
}
if ( ! defined( 'TCDIST_SITEMAP_REWRITE_VERSION' ) ) {
    define( 'TCDIST_SITEMAP_REWRITE_VERSION', 1 );
}
if ( ! defined( 'TCDIST_SITEMAP_REGEN_DELAY' ) ) {
    define( 'TCDIST_SITEMAP_REGEN_DELAY', 300 );
}

/**
 * Load after the monolith so coexistence can be detected deterministically.
 */
function taxocommerce_distribution_boot() {
    $legacy_feed = function_exists( 'seo_ie_cf_register_runtime' );
    $legacy_sitemap = function_exists( 'seo_marketing_maybe_serve_sitemap' );

    if ( ! defined( 'TCDIST_LEGACY_FEED_ACTIVE' ) ) {
        define( 'TCDIST_LEGACY_FEED_ACTIVE', $legacy_feed );
    }
    if ( ! defined( 'TCDIST_LEGACY_SITEMAP_ACTIVE' ) ) {
        define( 'TCDIST_LEGACY_SITEMAP_ACTIVE', $legacy_sitemap );
    }

    require_once TAXOCOMMERCE_DISTRIBUTION_PATH . 'includes/worker.php';
    require_once TAXOCOMMERCE_DISTRIBUTION_PATH . 'includes/feeds/core.php';
    require_once TAXOCOMMERCE_DISTRIBUTION_PATH . 'includes/feeds/channels.php';
    require_once TAXOCOMMERCE_DISTRIBUTION_PATH . 'includes/feeds/admin.php';
    require_once TAXOCOMMERCE_DISTRIBUTION_PATH . 'includes/sitemaps/engine.php';
    require_once TAXOCOMMERCE_DISTRIBUTION_PATH . 'includes/manual.php';
    require_once TAXOCOMMERCE_DISTRIBUTION_PATH . 'includes/autocheck.php';
    require_once TAXOCOMMERCE_DISTRIBUTION_PATH . 'includes/admin.php';

    // During coexistence the old plugin keeps automatic ownership. Manual
    // generation from Distribution remains available for parity testing.
    if ( ! TCDIST_LEGACY_FEED_ACTIVE ) {
        tcdist_feed_register_runtime();
    }

    taxocommerce_distribution_migrate_legacy_settings();
}
add_action( 'plugins_loaded', 'taxocommerce_distribution_boot', 50 );

function taxocommerce_distribution_migrate_legacy_settings() {
    if ( false === get_option( 'tcdist_commercial_feeds_settings_v1', false ) ) {
        $legacy = get_option( 'seo_ie_commercial_feeds_settings_v1', false );
        if ( is_array( $legacy ) ) {
            update_option( 'tcdist_commercial_feeds_settings_v1', $legacy, false );
        }
    }

    if ( false === get_option( 'tcdist_commercial_feeds_history_v1', false ) ) {
        $legacy = get_option( 'seo_ie_commercial_feeds_history_v1', false );
        if ( is_array( $legacy ) ) {
            update_option( 'tcdist_commercial_feeds_history_v1', $legacy, false );
        }
    }
}

/**
 * Core family registration.
 */
function taxocommerce_distribution_register_in_core( $core ) {
    if ( ! is_object( $core ) || ! method_exists( $core, 'register_module' ) ) {
        return;
    }
    $core->register_module(
        'distribution',
        array(
            'name'        => 'TaxoCommerce Distribution',
            'version'     => TAXOCOMMERCE_DISTRIBUTION_VERSION,
            'status'      => 'active',
            'description' => 'Commercial feeds and XML sitemaps.',
            'admin_url'   => admin_url( 'admin.php?page=taxocommerce-distribution' ),
        )
    );
}
add_action( 'taxocommerce_core_register_modules', 'taxocommerce_distribution_register_in_core' );

register_activation_hook(
    __FILE__,
    static function () {
        update_option( 'tcdist_activation_version', TAXOCOMMERCE_DISTRIBUTION_VERSION, false );
        flush_rewrite_rules( false );
    }
);

register_deactivation_hook(
    __FILE__,
    static function () {
        wp_clear_scheduled_hook( 'taxocommerce_distribution_feed_worker' );
        wp_clear_scheduled_hook( 'tcdist_sitemap_regenerate_sitemaps_event' );
        flush_rewrite_rules( false );
    }
);
