<?php
/**
 * Bootstrap de TaxoCommerce SEO.
 *
 * Carga las dependencias y registra el arranque de los componentes.
 */

defined('ABSPATH') || exit;

/*
|--------------------------------------------------------------------------
| VERSIONES Y MIGRACIONES
|--------------------------------------------------------------------------
*/

require_once dirname( __DIR__ ) . '/includes/class-seo-updater.php';

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

require_once dirname( __DIR__ ) . '/includes/seo-install.php';
require_once dirname( __DIR__ ) . '/includes/seo-core.php';

/*
|--------------------------------------------------------------------------
| UTILIDADES COMPARTIDAS
|--------------------------------------------------------------------------
*/

require_once dirname( __DIR__ ) . '/functions.php';
require_once dirname( __DIR__ ) . '/includes/seo-file-uploads.php';
require_once dirname( __DIR__ ) . '/includes/seo-text-utils.php';
require_once dirname( __DIR__ ) . '/includes/seo-vocabulary-bridge.php';
require_once dirname( __DIR__ ) . '/includes/system-check/seo-health-scan.php';
require_once dirname( __DIR__ ) . '/includes/procesos/class-seo-managed-service-process.php';

/*
|--------------------------------------------------------------------------
| PLANTILLAS Y PÁGINAS ESTRUCTURALES
|--------------------------------------------------------------------------
*/

require_once dirname( __DIR__ ) . '/includes/template-loader.php';
require_once dirname( __DIR__ ) . '/includes/template-manager.php';
require_once dirname( __DIR__ ) . '/includes/template-mail.php';
require_once dirname( __DIR__ ) . '/includes/pages-schema.php';
require_once dirname( __DIR__ ) . '/includes/seo-page-vocabulary-editor.php';
require_once dirname( __DIR__ ) . '/includes/seo-content-vocabulary.php';
require_once dirname( __DIR__ ) . '/includes/pages-admin.php';

/*
|--------------------------------------------------------------------------
| IMÁGENES
|--------------------------------------------------------------------------
*/

require_once dirname( __DIR__ ) . '/includes/seo-image-inventory.php';
require_once dirname( __DIR__ ) . '/includes/seo-images.php';

/*
|--------------------------------------------------------------------------
| PRODUCTOS
|--------------------------------------------------------------------------
*/

// Subsistema de productos centralizado.
// Incluye clasificacion, atributos, alta/edicion, inventario, informes y administracion.
require_once dirname( __DIR__ ) . '/includes/productos/product-bootstrap.php';

/*
|--------------------------------------------------------------------------
| CATEGORÍAS Y CLASIFICACIÓN
|--------------------------------------------------------------------------
*/

// Subsistema de categorias centralizado en includes/categorias/.
// El bootstrap canonico carga clasificacion, informacion relacionada,
// informes y administracion desde su propia carpeta.
require_once dirname( __DIR__ ) . '/includes/categorias/category-bootstrap.php';

/*
|--------------------------------------------------------------------------
| DATOS, EXPORTACIÓN Y LIMPIEZA
|--------------------------------------------------------------------------
*/
/**
 * Data Layer transaccional.
 */
require_once dirname( __DIR__ ) . '/includes/data-layer/data-layer-bootstrap.php';

// Clonador determinista PRO -> STAGING.
require_once dirname( __DIR__ ) . '/includes/clonador/clonador-bootstrap.php';

require_once dirname( __DIR__ ) . '/includes/seo-export.php';
require_once dirname( __DIR__ ) . '/includes/seo-database-report.php';
require_once dirname( __DIR__ ) . '/includes/seo-database-clean.php';

require_once dirname( __DIR__ ) . '/includes/ojeador/ojeador-bootstrap.php';



/*
|--------------------------------------------------------------------------
| REDIRECCIONES
|--------------------------------------------------------------------------
*/

require_once dirname( __DIR__ ) . '/includes/admin-redirects.php';

/*
|--------------------------------------------------------------------------
| BÚSQUEDA, MARKETING Y FAQ
|--------------------------------------------------------------------------
*/

require_once dirname( __DIR__ ) . '/includes/seo-search.php';
require_once dirname( __DIR__ ) . '/includes/seo-marketing.php';
require_once dirname( __DIR__ ) . '/includes/seo-faq.php';
require_once dirname( __DIR__ ) . '/includes/comentarista/comentarista-bootstrap.php';

/*
|--------------------------------------------------------------------------
| DEPENDIENTE: BUSQUEDA GUIADA Y COMPARADOR
|--------------------------------------------------------------------------
*/

require_once dirname( __DIR__ ) . '/includes/dependiente/seo-dependiente-bootstrap.php';

/*
|--------------------------------------------------------------------------
| INFORMES Y DASHBOARD
|--------------------------------------------------------------------------
*/

require_once dirname( __DIR__ ) . '/includes/seo-reports.php';
require_once dirname( __DIR__ ) . '/includes/seo-report-classification.php';
require_once dirname( __DIR__ ) . '/includes/seo-dashboard.php';
if ( ! function_exists( 'seo_google_get_settings' ) ) {
	require_once dirname( __DIR__ ) . '/includes/seo-google-info.php';
}
require_once dirname( __DIR__ ) . '/includes/analista/analista-bootstrap.php';

// Comparador: inteligencia comparativa persistente por categoria y contrato para Solucionador.
require_once dirname( __DIR__ ) . '/includes/comparador/comparador-bootstrap.php';

// Solucionador: convierte senales de cliente/mercado en propuestas de posts y borradores clasificados.
require_once dirname( __DIR__ ) . '/includes/solucionador/solucionador-bootstrap.php';

// API neutral de cobertura editorial compartida por procesos especializados.
require_once dirname( __DIR__ ) . '/includes/editorial/class-seo-editorial-coverage.php';

// Ingeniero: servicio técnico/editorial independiente de Dependiente.
require_once dirname( __DIR__ ) . '/includes/ingeniero/ingeniero-bootstrap.php';

// Unified, snapshot-driven editorial proposal bridge. Both services share the landing inventory.
require_once dirname( __DIR__ ) . '/includes/seo-landing-pages.php';
require_once dirname( __DIR__ ) . '/includes/editorial/class-seo-editorial-opportunities.php';
require_once dirname( __DIR__ ) . '/includes/editorial/class-seo-editorial-landings.php';
SEO_Editorial_Opportunities::init();
SEO_Editorial_Landings::init();

/*
|--------------------------------------------------------------------------
| ADMINISTRACIÓN Y MENÚS
|--------------------------------------------------------------------------
*/

require_once dirname( __DIR__ ) . '/includes/seo-menu-admin.php';
require_once dirname( __DIR__ ) . '/includes/seo-tags-vocabulary-admin.php';
require_once dirname( __DIR__ ) . '/includes/procesos/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/seo-logistica.php';
require_once dirname( __DIR__ ) . '/includes/seo-transporte.php';
require_once dirname( __DIR__ ) . '/includes/seo-admin.php';

/*
|--------------------------------------------------------------------------
| DIAGNÓSTICOS Y VALIDACIÓN
|--------------------------------------------------------------------------
*/

//require_once dirname( __DIR__ ) . '/includes/seo-server-status.php';
require_once dirname( __DIR__ ) . '/includes/system-check/seo-core-validation.php';
require_once dirname( __DIR__ ) . '/includes/system-check/seo-system-diagnostics-reporting.php';