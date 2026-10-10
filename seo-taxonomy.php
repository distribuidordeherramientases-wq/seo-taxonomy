<?php
/**
 * Plugin Name: TaxoCommerce SEO - Catalog, Trends, Content & Feeds
 * Plugin URI: https://focazul.wordpress.com/taxocommerce-seo-catalog-trends-content-feeds/
 * Description: Modular SEO, catalog, content and commercial management platform for WordPress and WooCommerce.
 * Version: 2.3.10
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: David Pérez
 * License: MIT
 * License URI: https://opensource.org/license/mit/
 * Text Domain: seo-taxonomy
 */

defined('ABSPATH') || exit;

/**
 * TAXOCOMMERCE SEO
 *
 * Plataforma modular de gestión SEO, catálogo, contenidos y actividad comercial para WordPress y WooCommerce.
 *
 * Incluye:
 * - Arquitectura Cluster → Hub → Categoría → Producto.
 * - Clasificación semántica y Vocabulary.
 * - Dependiente, Intérprete, Academia y Lingüista.
 * - Auditoría SEO y control de calidad del catálogo.
 * - Importación y sincronización de proveedores.
 * - Ojeador y análisis de mercado / Google Shopping.
 * - Inventarios comerciales y feeds externos.
 * - Automatización mediante gestor central de procesos y workers.
 * - FAQs, redirects, contenidos y redes sociales.
 */

/**
 * VERSIONES
 */
define('SEO_SYSTEM_VERSION', '2.3.10');
define('SEO_SYSTEM_DB_VERSION', '2.3.9');

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
require_once SEO_SYSTEM_PATH . 'includes/seo-includes-bootstrap.php';
