<?php
/**
 * Dependiente 3.0 - bootstrap hibrido.
 *
 * V3 es el runtime publico/decisor del Dependiente.
 * El runtime historico se conserva como soporte de administracion,
 * informes, Academia, Interprete, conocimiento y tareas de mantenimiento.
 *
 * IMPORTANTE:
 * - Se carga la clase SEO_Dependiente_Plugin porque el panel legacy usa
 *   algunos de sus metodos estaticos (reindexacion, estado, etc.).
 * - NO se ejecuta SEO_Dependiente_Plugin::instance(), para evitar que el
 *   runtime publico antiguo registre shortcodes, endpoints REST, plantillas
 *   y busqueda en paralelo con V3.
 */
defined('ABSPATH') || exit;

if (!defined('SEO_DEPENDIENTE_VERSION')) {
    define('SEO_DEPENDIENTE_VERSION', '3.0.0');
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

// Diagnostico/puente del Interprete historico para Academia y administracion.
// No convierte al runtime legacy en el buscador publico porque el core antiguo
// no se instancia en este bootstrap.
if (!defined('SEO_DEPENDIENTE_INTERPRETER_LOG')) {
    define('SEO_DEPENDIENTE_INTERPRETER_LOG', true);
}
if (!defined('SEO_DEPENDIENTE_INTERPRETER_ASSIST')) {
    define('SEO_DEPENDIENTE_INTERPRETER_ASSIST', true);
}

/*
 * -------------------------------------------------------------------------
 * SOPORTE LEGACY: admin, informes, Academia y conocimiento
 * -------------------------------------------------------------------------
 * Estas clases siguen siendo necesarias para la interfaz administrativa y
 * para los workers de Academia. El API legacy se carga como clase porque
 * Academia lo usa internamente, pero sus rutas REST publicas NO se registran:
 * ese registro dependia de SEO_Dependiente_Plugin::instance(), que aqui no
 * se ejecuta.
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

// El core legacy ya no se instancia. Solo recuperamos sus hooks de admin.
if (is_admin() && class_exists('SEO_Dependiente_Admin')) {
    SEO_Dependiente_Admin::init();
}

/*
 * -------------------------------------------------------------------------
 * RUNTIME V3: buscador / interprete / catalogo / decisor publico
 * -------------------------------------------------------------------------
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
