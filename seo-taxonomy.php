<?php
/**
 * Plugin Name: TaxoCommerce SEO - Catalog, Trends, Content & Feeds
 * Plugin URI: https://focazul.wordpress.com/taxocommerce-seo-catalog-trends-content-feeds/
 * Description: Modular SEO, catalog, content and commercial management platform for WordPress and WooCommerce.
 * Version: 2.4.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: David Pérez
 * License: MIT
 * License URI: https://opensource.org/license/mit/
 * Text Domain: taxocommerce-seo
 */

defined('ABSPATH') || exit;

/**
 * TAXOCOMMERCE SEO
 *
 * Plataforma modular de gestión SEO, catálogo, contenidos y actividad comercial para WordPress y WooCommerce.
 *
 * Resumen de la serie 2.4.1:
 * - Consolidacion de Auditor por bloques, prioridades y exportacion de informes.
 * - Evolucion de Analista, Ojeador, Ingeniero, Dependiente y Academia.
 * - Mejoras de importacion/exportacion, proveedores y flujos VEVOR.
 * - Preparacion de la familia modular TaxoCommerce (Core y extensiones).
 * - Herramientas de validacion, diagnostico y gestion de procesos.
 * - Cierre de la linea monolitica: el desarrollo funcional continua en los plugins TaxoCommerce independientes.
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
// Preserve the legacy plugin's own bootstrap path even if another plugin
// has registered SEO_SYSTEM_* constants earlier in the request.
if ( ! defined( 'SEO_SYSTEM_VERSION' ) ) {
    define( 'SEO_SYSTEM_VERSION', '2.4.1' );
}
if ( ! defined( 'SEO_SYSTEM_DB_VERSION' ) ) {
    define( 'SEO_SYSTEM_DB_VERSION', '2.3.9' );
}

if ( ! defined( 'SEO_SYSTEM_FILE' ) ) {
    define( 'SEO_SYSTEM_FILE', __FILE__ );
}
if ( ! defined( 'SEO_SYSTEM_PATH' ) ) {
    define( 'SEO_SYSTEM_PATH', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'SEO_SYSTEM_URL' ) ) {
    define( 'SEO_SYSTEM_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'SEO_SYSTEM_BASENAME' ) ) {
    define( 'SEO_SYSTEM_BASENAME', plugin_basename( __FILE__ ) );
}

// Deployment status is intentionally independent from the legacy bootstrap.
require_once __DIR__ . '/includes/seo-deploy-status.php';

// Do not resolve this file through a global path belonging to another plugin.
require_once __DIR__ . '/includes/seo-includes-bootstrap.php';
