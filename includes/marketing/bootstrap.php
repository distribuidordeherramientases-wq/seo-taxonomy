<?php
/**
 * Bootstrap del subsistema Marketing.
 *
 * Mantiene un punto de entrada estable para poder seguir separando identidad,
 * relaciones, sitemaps y estilo sin cambiar el bootstrap general del plugin.
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/seo-marketing.php';
