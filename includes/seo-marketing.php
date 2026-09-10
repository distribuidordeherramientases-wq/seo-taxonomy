<?php
/**
 * Loader de compatibilidad de Marketing.
 *
 * La implementación vive en includes/marketing/. Se conserva esta ruta porque
 * el bootstrap histórico del plugin y posibles integraciones externas todavía
 * pueden requerir includes/seo-marketing.php.
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/marketing/bootstrap.php';
