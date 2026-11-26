<?php
/**
 * Plugin Name: TaxoCommerce Mail Lite
 * Plugin URI: https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/staging/taxocommerce-mail-lite
 * Description: Free, independent Lite mail client for hosting accounts with secure IMAP inbox reading, replies and authenticated SMTP.
 * Version: 0.1.5
 * Release Date: 2026-11-26
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-mail-lite
 * Domain Path: /languages
 */
defined( 'ABSPATH' ) || exit;
// Do not load the same classes twice during the native WordPress cutover.
if ( in_array( 'taxocommerce-mail/taxocommerce-mail.php', (array) get_option( 'active_plugins', array() ), true ) ||
    ( is_multisite() && isset( get_site_option( 'active_sitewide_plugins', array() )['taxocommerce-mail/taxocommerce-mail.php'] ) ) ) {
    register_activation_hook( __FILE__, static function () {
        wp_die( esc_html__( 'Deactivate the previous Mail Lite installation before activating this version.', 'taxocommerce-mail-lite' ), '', array( 'response' => 409 ) );
    } );
    return;
}
define( 'TCMAIL_VERSION', '0.1.5' );
define( 'TCMAIL_PATH', plugin_dir_path( __FILE__ ) );
define( 'TCMAIL_URL', plugin_dir_url( __FILE__ ) );
require_once TCMAIL_PATH . 'includes/class-tcmail-accounts.php';
require_once TCMAIL_PATH . 'includes/class-tcmail-imap.php';
require_once TCMAIL_PATH . 'includes/class-tcmail-smtp.php';
require_once TCMAIL_PATH . 'includes/class-tcmail-admin.php';
require_once TCMAIL_PATH . 'includes/class-tcmail-autocheck.php';
require_once TCMAIL_PATH . 'includes/admin-branding.php';
register_activation_hook( __FILE__, array( 'TCMAIL_Admin', 'activate' ) );
// Prefer WordPress.org language packs; use bundled locale catalogs for offline shops.
add_action( 'init', static function () {
    if ( is_textdomain_loaded( 'taxocommerce-mail-lite' ) ) {
        return;
    }
    $locale = sanitize_file_name( determine_locale() );
    $official = WP_LANG_DIR . '/plugins/taxocommerce-mail-lite-' . $locale;
    if ( is_file( $official . '.mo' ) || is_file( $official . '.l10n.php' ) ) {
        return;
    }
    $supported_locales = array( 'es_ES', 'en_US', 'fr_FR', 'de_DE', 'nl_NL' );
    if ( in_array( $locale, $supported_locales, true ) ) {
        $catalog = TCMAIL_PATH . 'languages/taxocommerce-mail-lite-' . $locale . '.mo';
        if ( is_readable( $catalog ) ) {
            load_textdomain( 'taxocommerce-mail-lite', $catalog );
        }
    }
}, 1 );
add_action( 'plugins_loaded', array( 'TCMAIL_Admin', 'boot' ) );
add_action( 'plugins_loaded', array( 'TCMAIL_Autocheck', 'boot' ) );
