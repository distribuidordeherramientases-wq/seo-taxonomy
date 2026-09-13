<?php
/**
 * Loader de compatibilidad de Marketing.
 *
 * La implementación canónica vive en includes/marketing/. Se conserva esta
 * ruta para integraciones antiguas que todavía requieran includes/seo-marketing.php.
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/marketing/bootstrap.php';
