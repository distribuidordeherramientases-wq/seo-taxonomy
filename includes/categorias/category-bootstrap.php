<?php
/**
 * Bootstrap del subsistema de categorias.
 *
 * Centraliza la carga de administracion, clasificacion, informacion relacionada
 * e informes de categorias. Los modulos legacy de Anomalias y Schema ya no se
 * cargan desde este subsistema.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/category-classification.php';
require_once __DIR__ . '/category-info-related.php';
require_once __DIR__ . '/seo-category-reports.php';
require_once __DIR__ . '/category-admin.php';
