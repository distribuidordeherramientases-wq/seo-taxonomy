<?php
/**
 * SEO System — Puente de compatibilidad del importador universal V3.
 *
 * La implementacion canonica del importador de proveedores vive en
 * includes/import-export/suppliers/engine.php. Se conserva esta ruta para no
 * romper instalaciones, enlaces o includes historicos que aun apunten al V3.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.0.0
 * @version 2026-08-18
 */

defined( 'ABSPATH' ) || exit;

$seo_supplier_engine_file = dirname( __DIR__ ) . '/suppliers/engine.php';
if ( is_readable( $seo_supplier_engine_file ) ) {
    require_once $seo_supplier_engine_file;
}
unset( $seo_supplier_engine_file );
