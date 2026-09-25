<?php
/**
 * Dispatcher seguro de la franja global de campañas: móvil / escritorio.
 *
 * A diferencia de los dispatchers de página completa, esta pieza es opcional.
 * Si durante una subida falta temporalmente una variante, no debe derribar el
 * sitio: usa la variante disponible y, si no hay ninguna, no imprime nada.
 */

defined('ABSPATH') || exit;

$preferred = wp_is_mobile() ? 'mobile' : 'desktop';
$fallback  = $preferred === 'mobile' ? 'desktop' : 'mobile';

$preferred_file = __DIR__ . '/template-campaign-' . $preferred . '.php';
$fallback_file  = __DIR__ . '/template-campaign-' . $fallback . '.php';

if (is_readable($preferred_file)) {
    $GLOBALS['dht_template_active_variant'] = $preferred;
    $GLOBALS['dht_template_active_base']    = 'campaign';
    require $preferred_file;
} elseif (is_readable($fallback_file)) {
    $GLOBALS['dht_template_active_variant'] = $fallback;
    $GLOBALS['dht_template_active_base']    = 'campaign';
    require $fallback_file;
}

unset($preferred, $fallback, $preferred_file, $fallback_file);
