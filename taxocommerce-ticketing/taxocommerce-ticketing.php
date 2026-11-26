<?php
/**
 * Plugin Name: TaxoCommerce Ticketing
 * Plugin URI: https://asociacionfocazul.com/taxocommerce-ticketing/
 * Description: Independent customer and internal helpdesk: configurable public forms, private attachments, replies, notifications and ticket management.
 * Version: 0.1.3
 * Release Date: 2026-11-26
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-ticketing
 * Domain Path: /languages
 */
defined( 'ABSPATH' ) || exit;

define( 'TCTK_VERSION', '0.1.3' );
define( 'TCTK_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCTK_URL', plugin_dir_url( __FILE__ ) );

require_once TCTK_PATH . 'includes/class-tctk-ticketing.php';
require_once TCTK_PATH . 'includes/class-tctk-autocheck.php';
require_once TCTK_PATH . 'includes/class-tctk-autotest-code.php';
require_once TCTK_PATH . 'includes/class-tctk-event-log.php';
require_once TCTK_PATH . 'includes/class-tctk-manuals.php';
require_once TCTK_PATH . 'includes/admin-branding.php';
TCTK_Autocheck::boot();
TCTK_Autotest_Code::boot();
TCTK_Event_Log::boot();

// Prefer official WordPress language packs; fall back to bundled MO catalogs for standalone installations.
add_action( 'init', static function () {
    if ( is_textdomain_loaded( 'taxocommerce-ticketing' ) ) {
        return;
    }
    $locale = sanitize_file_name( determine_locale() );
    $official = WP_LANG_DIR . '/plugins/taxocommerce-ticketing-' . $locale;
    if ( is_readable( $official . '.mo' ) || is_readable( $official . '.l10n.php' ) ) {
        return;
    }
    $fallbacks = array( 'es' => 'es_ES', 'en' => 'en_US', 'fr' => 'fr_FR', 'de' => 'de_DE', 'nl' => 'nl_NL' );
    $language = substr( strtolower( $locale ), 0, 2 );
    $catalog = TCTK_PATH . 'languages/taxocommerce-ticketing-' . ( $fallbacks[ $language ] ?? $locale ) . '.mo';
    if ( is_readable( $catalog ) ) {
        load_textdomain( 'taxocommerce-ticketing', $catalog, $locale );
    }
}, 0 );

register_activation_hook( __FILE__, array( 'TCTK_Ticketing', 'activate' ) );
add_action( 'plugins_loaded', array( 'TCTK_Ticketing', 'boot' ) );

add_filter( 'taxocommerce_core_family_extensions', static function ( $extensions ) {
    $extensions = is_array( $extensions ) ? $extensions : array();
    $extensions['ticketing'] = array(
        'id'            => 'ticketing',
        'name'          => 'TaxoCommerce Ticketing',
        'plugin_file'   => 'taxocommerce-ticketing/taxocommerce-ticketing.php',
        'org_slug'      => 'taxocommerce-ticketing',
        'wordpress_org' => false,
        'admin_url'     => admin_url( 'admin.php?page=taxocommerce-ticketing' ),
        'description'   => 'Independent customer and internal ticket management.',
    );
    return $extensions;
} );

add_action( 'taxocommerce_core_register_modules', static function ( $core ) {
    if ( is_object( $core ) && method_exists( $core, 'register_module' ) ) {
        $core->register_module( 'ticketing', array(
            'name'        => 'TaxoCommerce Ticketing',
            'version'     => TCTK_VERSION,
            'status'      => 'active',
            'description' => 'Customer and internal helpdesk.',
            'admin_url'   => admin_url( 'admin.php?page=taxocommerce-ticketing' ),
        ) );
    }
} );
