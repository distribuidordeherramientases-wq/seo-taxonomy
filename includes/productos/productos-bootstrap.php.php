<?php
/**
 * Bootstrap del subsistema de productos.
 *
 * Centraliza los modulos propios de producto bajo includes/productos/ y
 * conserva el resto de dependencias compartidas en sus carpetas canonicas.
 */

defined('ABSPATH') || exit;

// Base canonica del producto.
require_once __DIR__ . '/product-classification.php';
require_once __DIR__ . '/product-attributes.php';
require_once __DIR__ . '/product-service.php';
require_once __DIR__ . '/product-form.php';
require_once __DIR__ . '/product-create.php';
require_once __DIR__ . '/product-edit.php';
require_once __DIR__ . '/product-inventory.php';

// Clasificador: dependencia compartida que permanece fuera de productos/.
require_once dirname(__DIR__) . '/clasificador/bootstrap.php';
require_once __DIR__ . '/product-recategorization.php';

// Informes, tamanos y administracion.
require_once __DIR__ . '/seo-product-reports.php';
require_once __DIR__ . '/product-sizes.php';
require_once __DIR__ . '/product-page-admin.php';
require_once __DIR__ . '/seo-product-vocabulary-editor.php';
