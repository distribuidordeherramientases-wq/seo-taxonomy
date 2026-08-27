<?php

require_once __DIR__ . '/wp-load.php';

if (!is_user_logged_in() || !current_user_can('manage_options')) {
    wp_die('Acceso no autorizado.');
}

if (!isset($_GET['run']) || $_GET['run'] !== '1') {
    wp_die('Añade ?run=1 para ejecutar la limpieza.');
}

global $wpdb;

@set_time_limit(120);

$batch = 100;

$proveedores = $wpdb->prefix . 'seo_proveedores_productos';
$imagenes    = $wpdb->prefix . 'seo_supplier_images';
$media       = $wpdb->prefix . 'seo_media_imagenes';
$postmeta    = $wpdb->postmeta;

$sql = "
SELECT DISTINCT
    CAST(pm.meta_value AS UNSIGNED) AS attachment_id
FROM {$proveedores} pp

INNER JOIN {$postmeta} pm
    ON pm.post_id = pp.object_id
   AND pm.meta_key = '_thumbnail_id'

INNER JOIN {$media} mi
    ON mi.attachment_id = CAST(pm.meta_value AS UNSIGNED)
   AND mi.proveedor = 'VEVOR'

INNER JOIN {$imagenes} si
    ON si.product_id = pp.object_id
   AND si.supplier = 'VEVOR'
   AND si.is_primary = 1
   AND si.status = 'active'

WHERE pp.proveedor = 'VEVOR'
  AND LEFT(pp.sku, 2) = 'p_'
  AND pp.object_id > 0
  AND pp.modo_imagenes = 'external'
  AND pp.primera_importacion > '2026-06-15 23:59:59'

ORDER BY attachment_id
LIMIT {$batch}
";

$ids = $wpdb->get_col($sql);

$eliminadas = 0;
$errores    = [];

foreach ($ids as $attachment_id) {

    $attachment_id = (int) $attachment_id;

    if (!$attachment_id) {
        continue;
    }

    $resultado = wp_delete_attachment($attachment_id, true);

    if ($resultado) {
        $eliminadas++;
    } else {
        $errores[] = $attachment_id;
    }
}

/*
 * Contar lo que todavía queda.
 */
$restantes = (int) $wpdb->get_var("
SELECT COUNT(DISTINCT CAST(pm.meta_value AS UNSIGNED))
FROM {$proveedores} pp

INNER JOIN {$postmeta} pm
    ON pm.post_id = pp.object_id
   AND pm.meta_key = '_thumbnail_id'

INNER JOIN {$media} mi
    ON mi.attachment_id = CAST(pm.meta_value AS UNSIGNED)
   AND mi.proveedor = 'VEVOR'

INNER JOIN {$imagenes} si
    ON si.product_id = pp.object_id
   AND si.supplier = 'VEVOR'
   AND si.is_primary = 1
   AND si.status = 'active'

WHERE pp.proveedor = 'VEVOR'
  AND LEFT(pp.sku, 2) = 'p_'
  AND pp.object_id > 0
  AND pp.modo_imagenes = 'external'
  AND pp.primera_importacion > '2026-06-15 23:59:59'
");

echo '<h2>Limpieza VEVOR</h2>';
echo '<p>Eliminadas en esta tanda: <strong>' . $eliminadas . '</strong></p>';
echo '<p>Restantes: <strong>' . $restantes . '</strong></p>';

if ($errores) {
    echo '<p>Errores: ' . esc_html(implode(', ', $errores)) . '</p>';
}

if ($restantes > 0 && $eliminadas > 0) {
    echo '<p>Continuando automáticamente...</p>';
    echo '<meta http-equiv="refresh" content="2;url=?run=1">';
} else {
    echo '<h3>Proceso terminado.</h3>';
}