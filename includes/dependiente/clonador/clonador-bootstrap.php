<?php
/**
 * Compatibility entry point for the moved Clonador engine.
 *
 * The canonical engine and version are defined in includes/clonador/.
 * Never set SEO_CLONADOR_LOADED before verifying/calling that loader.
 *
 * @package TaxoCommerceSEO
 */
defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__, 2 ) . '/clonador/clonador-bootstrap.php';
