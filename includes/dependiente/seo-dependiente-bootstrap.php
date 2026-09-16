<?php
/**
 * Dependiente V3 - bootstrap hibrido con infraestructura legacy completa.
 *
 * OBJETIVO
 * -------
 * Mantener TODO el ecosistema historico de Dependiente que no es el buscador
 * publico: Academia, Interprete/Linguista, conocimiento, aprendizaje,
 * indexacion y reindexacion, mantenimiento incremental del indice, supervisor
 * de procesos, import/export, diagnostico, auditoria, ayuda, etc.
 *
 * Solo se sustituye el runtime publico de busqueda por Dependiente V3:
 * - UI/shortcodes publicos -> V3
 * - plantilla publica       -> V3
 * - assets publicos         -> V3
 * - endpoint de busqueda    -> /seo-taxonomy/v3/search
 *
 * El core legacy SI se instancia para conservar sus hooks de infraestructura.
 * Inmediatamente despues se desactivan unicamente sus hooks de UI/busqueda
 * publica. De este modo no perdemos servicios auxiliares cada vez que V3
 * sustituye el algoritmo de busqueda.
 */
defined('ABSPATH') || exit;

if (!defined('SEO_DEPENDIENTE_VERSION')) {
    define('SEO_DEPENDIENTE_VERSION', '3.0.3');
}
if (!defined('SEO_DEPENDIENTE_DB_VERSION')) {
    define('SEO_DEPENDIENTE_DB_VERSION', '0.4.0');
}
if (!defined('SEO_DEPENDIENTE_PATH')) {
    define('SEO_DEPENDIENTE_PATH', __DIR__ . '/');
}
if (!defined('SEO_DEPENDIENTE_URL')) {
    define('SEO_DEPENDIENTE_URL', SEO_SYSTEM_URL . 'includes/dependiente/');
}

// El Interprete historico sigue disponible para Academia, diagnostico,
// Linguista y conocimiento. La busqueda publica la decide V3.
if (!defined('SEO_DEPENDIENTE_INTERPRETER_LOG')) {
    define('SEO_DEPENDIENTE_INTERPRETER_LOG', true);
}
if (!defined('SEO_DEPENDIENTE_INTERPRETER_ASSIST')) {
    define('SEO_DEPENDIENTE_INTERPRETER_ASSIST', true);
}

/*
 * -------------------------------------------------------------------------
 * INFRAESTRUCTURA HISTORICA COMPLETA
 * -------------------------------------------------------------------------
 * Se mantiene el mismo conjunto de modulos que utilizaba Dependiente antes de
 * V3. No duplicamos su logica: cargamos e instanciamos el core original y
 * despues apagamos solamente su superficie publica de busqueda.
 */
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-index.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-semantics.php';

$seo_dependiente_interpreter_bootstrap = SEO_DEPENDIENTE_PATH . 'interprete/seo-dependiente-interprete-bootstrap.php';
if (is_readable($seo_dependiente_interpreter_bootstrap)) {
    require_once $seo_dependiente_interpreter_bootstrap;
}

require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-help.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-amazon.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-api.php';
require_once SEO_DEPENDIENTE_PATH . 'entrenador/seo-dependiente-entrenador.php';

$seo_dependiente_actualizacion = SEO_DEPENDIENTE_PATH . 'entrenador/seo-dependiente-actualizacion.php';
if (is_readable($seo_dependiente_actualizacion)) {
    require_once $seo_dependiente_actualizacion;
}

require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-training-quality.php';

// Auditor academico: modulo hermano de solo lectura en STAGING.
$seo_auditor_bootstrap = defined('SEO_SYSTEM_PATH')
    ? rtrim(SEO_SYSTEM_PATH, '/\\') . '/includes/auditor/seo-auditor-bootstrap.php'
    : dirname(rtrim(SEO_DEPENDIENTE_PATH, '/\\')) . '/auditor/seo-auditor-bootstrap.php';
if (is_readable($seo_auditor_bootstrap)) {
    require_once $seo_auditor_bootstrap;
}

require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-insights.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-reset.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-knowledge-transfer.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-admin.php';
require_once SEO_DEPENDIENTE_PATH . 'seo-dependiente-core.php';

/*
 * Instanciamos el core legacy porque su constructor no solo contenia el
 * buscador: tambien registra upgrades, pagina, AJAX/admin, indexacion al
 * guardar productos, borrado del indice, reindexacion en segundo plano y
 * conexion con el Gestor de procesos.
 */
$seo_dependiente_legacy = SEO_Dependiente_Plugin::instance();

/*
 * -------------------------------------------------------------------------
 * DESACTIVAR SOLO LA SUPERFICIE PUBLICA LEGACY
 * -------------------------------------------------------------------------
 * Conservamos todos los hooks de infraestructura registrados por el core.
 * Quitamos exclusivamente los que harian convivir la UI/busqueda antigua con
 * V3.
 */
if (is_object($seo_dependiente_legacy)) {
    // Los shortcodes publicos los registra V3.
    remove_action('init', array($seo_dependiente_legacy, 'register_shortcode'), 10);

    // La pagina publica usa la plantilla V3.
    remove_filter('template_include', array($seo_dependiente_legacy, 'template_include'), 99);

    // Robots y assets de la pagina publica pasan a V3.
    remove_filter('wp_robots', array($seo_dependiente_legacy, 'filter_query_state_robots'), 99);
    remove_action('wp_enqueue_scripts', array($seo_dependiente_legacy, 'enqueue_page_assets'), 20);

    // Proteccion para cargas extraordinariamente tardias.
    remove_shortcode('dependiente_productos');
    remove_shortcode('dependiente');
}

/*
 * El API v1 legacy contiene mas cosas que la busqueda (feedback, ayuda,
 * comparacion, bootstrap). Lo conservamos para no amputar funcionalidades
 * auxiliares, pero retiramos EXCLUSIVAMENTE /v1/search.
 *
 * V3 registra su busqueda en /seo-taxonomy/v3/search.
 */
add_action('rest_api_init', static function () {
    if (function_exists('unregister_rest_route')) {
        unregister_rest_route('seo-taxonomy/v1', '/search');
    }
}, 999);

// Fallback compatible: aunque unregister_rest_route no exista, el endpoint
// antiguo de busqueda no se publica en el mapa final de REST.
add_filter('rest_endpoints', static function ($endpoints) {
    if (is_array($endpoints)) {
        unset($endpoints['/seo-taxonomy/v1/search']);
    }
    return $endpoints;
}, PHP_INT_MAX);

/*
 * -------------------------------------------------------------------------
 * RUNTIME PUBLICO V3
 * -------------------------------------------------------------------------
 * Esta es la unica capa que sustituye al Dependiente antiguo: interpretacion
 * de la consulta para V3, recuperacion, ranking, API de busqueda y frontend.
 */
if (!defined('SEO_DEPENDIENTE_V3_PATH')) {
    define('SEO_DEPENDIENTE_V3_PATH', __DIR__ . '/v3/');
}
if (!defined('SEO_DEPENDIENTE_V3_URL')) {
    define('SEO_DEPENDIENTE_V3_URL', SEO_DEPENDIENTE_URL . 'v3/');
}

require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-db.php';
require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-interpreter.php';
require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-catalog.php';
require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-api.php';
require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-frontend.php';
require_once SEO_DEPENDIENTE_V3_PATH . 'class-dependiente-v3-app.php';

SEO_Dependiente_V3_App::boot();
