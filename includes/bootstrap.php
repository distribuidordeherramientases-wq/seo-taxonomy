<?php
/**
 * Bootstrap de SEO Taxonomy.
 *
 * Carga las dependencias y registra el arranque de los componentes.
 */

defined('ABSPATH') || exit;

/*
|--------------------------------------------------------------------------
| VERSIONES Y MIGRACIONES
|--------------------------------------------------------------------------
*/

require_once SEO_SYSTEM_PATH . 'includes/class-seo-updater.php';

add_action(
    'plugins_loaded',
    ['SEO_System_Updater', 'maybe_upgrade'],
    5
);

/*
|--------------------------------------------------------------------------
| INSTALACIÓN Y NÚCLEO
|--------------------------------------------------------------------------
*/

require_once SEO_SYSTEM_PATH . 'includes/seo-install.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-core.php';

/*
|--------------------------------------------------------------------------
| UTILIDADES COMPARTIDAS
|--------------------------------------------------------------------------
*/

require_once SEO_SYSTEM_PATH . 'functions.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-text-utils.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-vocabulary-bridge.php';

/*
|--------------------------------------------------------------------------
| PLANTILLAS Y PÁGINAS ESTRUCTURALES
|--------------------------------------------------------------------------
*/

require_once SEO_SYSTEM_PATH . 'includes/template-loader.php';
require_once SEO_SYSTEM_PATH . 'includes/template-manager.php';
require_once SEO_SYSTEM_PATH . 'includes/template-mail.php';
require_once SEO_SYSTEM_PATH . 'includes/pages-schema.php';
require_once SEO_SYSTEM_PATH . 'includes/pages-admin.php';

/*
|--------------------------------------------------------------------------
| IMÁGENES
|--------------------------------------------------------------------------
*/

require_once SEO_SYSTEM_PATH . 'includes/seo-image-inventory.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-images.php';

/*
|--------------------------------------------------------------------------
| PRODUCTOS
|--------------------------------------------------------------------------
*/

// Helpers compartidos de categorías + compatibilidad del informe de reclasificación.
// La clasificación legacy de etiquetas de producto ya está retirada.
require_once SEO_SYSTEM_PATH . 'includes/product-classification.php';
require_once SEO_SYSTEM_PATH . 'includes/product-inventory.php';
require_once SEO_SYSTEM_PATH . 'includes/product-attributes.php';
require_once SEO_SYSTEM_PATH . 'includes/product-recategorization.php';

// Alta/edición unitaria: ambos caminos usan el mismo servicio canónico.
require_once SEO_SYSTEM_PATH . 'includes/product-service.php';
require_once SEO_SYSTEM_PATH . 'includes/product-form.php';
require_once SEO_SYSTEM_PATH . 'includes/product-create.php';
require_once SEO_SYSTEM_PATH . 'includes/product-edit.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-product-reports.php';
require_once SEO_SYSTEM_PATH . 'includes/product-page-admin.php';

/*
|--------------------------------------------------------------------------
| CATEGORÍAS Y CLASIFICACIÓN
|--------------------------------------------------------------------------
*/

require_once SEO_SYSTEM_PATH . 'includes/category-classification.php';
require_once SEO_SYSTEM_PATH . 'includes/category-anomaly.php';
require_once SEO_SYSTEM_PATH . 'includes/category-admin.php';
require_once SEO_SYSTEM_PATH . 'includes/seo_schema_search.php';

/*
|--------------------------------------------------------------------------
| DATOS, EXPORTACIÓN Y LIMPIEZA
|--------------------------------------------------------------------------
*/
/**
 * Data Layer transaccional.
 */
require_once SEO_SYSTEM_PATH . 'includes/data-layer/data-layer-bootstrap.php';

require_once SEO_SYSTEM_PATH . 'includes/seo-export.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-database-report.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-database-clean.php';

/*
|--------------------------------------------------------------------------
| REDIRECCIONES
|--------------------------------------------------------------------------
*/

require_once SEO_SYSTEM_PATH . 'includes/admin-redirects.php';

/*
|--------------------------------------------------------------------------
| BÚSQUEDA, MARKETING Y FAQ
|--------------------------------------------------------------------------
*/

require_once SEO_SYSTEM_PATH . 'includes/seo-search.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-marketing.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-faq.php';

/*
|--------------------------------------------------------------------------
| INFORMES Y DASHBOARD
|--------------------------------------------------------------------------
*/

require_once SEO_SYSTEM_PATH . 'includes/seo-reports.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-report-classification.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-dashboard.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-google-info.php';

/*
|--------------------------------------------------------------------------
| ADMINISTRACIÓN Y MENÚS
|--------------------------------------------------------------------------
*/

require_once SEO_SYSTEM_PATH . 'includes/seo-menu-admin.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-tags-vocabulary-admin.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-product-vocabulary-editor.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-admin.php';

/*
|--------------------------------------------------------------------------
| DIAGNÓSTICOS Y VALIDACIÓN
|--------------------------------------------------------------------------
*/

//require_once SEO_SYSTEM_PATH . 'includes/seo-server-status.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-core-validation.php';
require_once SEO_SYSTEM_PATH . 'includes/seo-system-diagnostics-reporting.php';