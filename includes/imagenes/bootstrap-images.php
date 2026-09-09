<?php
/**
 * Bootstrap específico del dominio de imágenes.
 *
 * Todo proceso nuevo relacionado con imágenes debe cargarse desde este punto
 * y vivir bajo includes/imagenes/.
 *
 * @package SEOSystem
 */

defined('ABSPATH') || exit;

// Núcleo histórico de imágenes. Se mantiene en su ruta actual por compatibilidad.
require_once dirname(__DIR__) . '/seo-images.php';

// Componentes existentes del módulo.
require_once __DIR__ . '/seo-image-optimizer.php';
require_once __DIR__ . '/seo-image-webp.php';

// Limpieza de Media frente a fuentes externas.
require_once __DIR__ . '/fuentes-externas.php';
require_once __DIR__ . '/comparar-media.php';
require_once __DIR__ . '/limpiar-media.php';
require_once __DIR__ . '/limpieza-ajax.php';
require_once __DIR__ . '/limpieza-admin.php';

// Panel de Imágenes. Se carga al final para que las pestañas puedan delegar
// en los módulos anteriores sin dependencias circulares.
require_once __DIR__ . '/seo-image-inventory.php';
