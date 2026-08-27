<?php
/**
 * SEO System — Puente estable del importador de proveedores.
 *
 * Mantiene la ruta publica includes/seo-import-suppliers.php, pero ya no
 * depende del antiguo suppliers/importer.php. El motor canonico vive en
 * includes/import-export/suppliers/engine.php.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.3.1
 * @version 2026-08-18
 * Build: 033
 */

defined( 'ABSPATH' ) || exit;

$seo_supplier_sync_file = __DIR__ . '/import-export/suppliers/sync.php';
if ( is_readable( $seo_supplier_sync_file ) ) {
    require_once $seo_supplier_sync_file;
}
unset( $seo_supplier_sync_file );

$seo_ie_supplier_candidates = [
    __DIR__ . '/import-export/suppliers/engine.php',
    __DIR__ . '/import-export/queue/importer-actualizador-universal-v3.php',
];

foreach ( array_unique( $seo_ie_supplier_candidates ) as $seo_ie_supplier_file ) {
    if ( ! is_readable( $seo_ie_supplier_file ) ) {
        continue;
    }

    require_once $seo_ie_supplier_file;

    if (
        function_exists( 'seo_proveedores_render_importador' )
        && function_exists( 'seo_proveedores_render_catalogo' )
    ) {
        unset( $seo_ie_supplier_candidates, $seo_ie_supplier_file );
        return;
    }
}

error_log( '[SEO System Import/Export] El motor de proveedores no esta disponible o no expone sus renderizadores.' );
unset( $seo_ie_supplier_candidates, $seo_ie_supplier_file );
