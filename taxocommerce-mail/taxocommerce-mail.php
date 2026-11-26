<?php
/**
 * Plugin Name: TaxoCommerce Mail Lite
 * Plugin URI: https://github.com/distribuidordeherramientases-wq/seo-taxonomy/tree/staging/taxocommerce-mail
 * Description: Free, independent Lite mail client for hosting accounts with secure IMAP inbox reading, replies and authenticated SMTP.
 * Version: 0.1.4
 * Release Date: 2026-11-26
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: David Perez
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: taxocommerce-mail
 * Domain Path: /languages
 */
defined( 'ABSPATH' ) || exit;
define( 'TCMAIL_VERSION', '0.1.4' );
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
    if ( is_textdomain_loaded( 'taxocommerce-mail' ) ) {
        return;
    }
    $locale = sanitize_file_name( determine_locale() );
    $official = WP_LANG_DIR . '/plugins/taxocommerce-mail-' . $locale;
    if ( is_file( $official . '.mo' ) || is_file( $official . '.l10n.php' ) ) {
        return;
    }
    $supported_locales = array( 'es_ES', 'en_US', 'fr_FR', 'de_DE', 'nl_NL' );
    if ( in_array( $locale, $supported_locales, true ) ) {
        $catalog = TCMAIL_PATH . 'languages/taxocommerce-mail-' . $locale . '.mo';
        if ( is_readable( $catalog ) ) {
            load_textdomain( 'taxocommerce-mail', $catalog );
        }
    }
}, 1 );
add_action( 'plugins_loaded', array( 'TCMAIL_Admin', 'boot' ) );
add_action( 'plugins_loaded', array( 'TCMAIL_Autocheck', 'boot' ) );
