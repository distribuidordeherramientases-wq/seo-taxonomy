<?php
/**
 * Bootstrap del modulo de imagenes.
 *
 * Los componentes nuevos o reorganizados del panel de imagenes se cargan
 * desde includes/imagenes para mantener este dominio separado del resto del
 * plugin.
 */

defined('ABSPATH') || exit;

require_once dirname(__DIR__) . '/seo-images.php';
require_once __DIR__ . '/seo-image-optimizer.php';
require_once __DIR__ . '/seo-image-inventory.php';
