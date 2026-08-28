<?php
/**
 * Plugin Name: SEO Taxonomy
 * Description: Sistema SEO basado en relaciones.
 * Version: 2.3.0
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

defined('ABSPATH') || exit;

/**
 * VERSIONES
 */
define('SEO_SYSTEM_VERSION', '2.3.0');
define('SEO_SYSTEM_DB_VERSION', '2.3.0');

/**
 * RUTAS
 */
define('SEO_SYSTEM_FILE', __FILE__);
define('SEO_SYSTEM_PATH', plugin_dir_path(__FILE__));
define('SEO_SYSTEM_URL', plugin_dir_url(__FILE__));
define('SEO_SYSTEM_BASENAME', plugin_basename(__FILE__));

/**
 * BOOTSTRAP
 */
require_once SEO_SYSTEM_PATH . 'includes/bootstrap.php';
