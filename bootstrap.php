<?php
/**
 * Compatibility entry point for the historical TaxoCommerce SEO bootstrap.
 *
 * @package TaxoCommerceSEO
 */
defined( 'ABSPATH' ) || exit;

// Keep one canonical dependency graph; never load the stale root-level list.
require_once __DIR__ . '/includes/seo-includes-bootstrap.php';
