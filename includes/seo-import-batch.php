<?php
/**
 * SEO System — Puente seguro de compatibilidad de la cola multientidad.
 *
 * Conserva la ruta historica includes/seo-import-batch.php. La implementacion
 * vive en includes/import-export/queue/batch.php. Si el paquete esta incompleto,
 * no genera un error fatal; el puente principal mostrara el aviso pertinente.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.3.1
 * Build: 030
 */

defined( 'ABSPATH' ) || exit;

$seo_ie_batch_candidates = [
    __DIR__ . '/import-export/queue/batch.php',
    dirname( __DIR__ ) . '/import-export/queue/batch.php',
];

foreach ( [ __DIR__, dirname( __DIR__ ) ] as $seo_ie_batch_root ) {
    $seo_ie_batch_matches = glob( $seo_ie_batch_root . '/*/import-export/queue/batch.php' );
    if ( is_array( $seo_ie_batch_matches ) ) {
        $seo_ie_batch_candidates = array_merge( $seo_ie_batch_candidates, $seo_ie_batch_matches );
    }
}

foreach ( array_unique( $seo_ie_batch_candidates ) as $seo_ie_batch_file ) {
    if ( is_readable( $seo_ie_batch_file ) ) {
        require_once $seo_ie_batch_file;
        return;
    }
}

error_log( '[SEO System Import/Export] No se encuentra import-export/queue/batch.php.' );
