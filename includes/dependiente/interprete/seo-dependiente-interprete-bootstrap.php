<?php

defined('ABSPATH') || exit;

$seo_dependiente_interprete_db_file = __DIR__ . '/seo-dependiente-interprete-db.php';
if (!class_exists('SEO_Dependiente_Interprete_DB') && is_readable($seo_dependiente_interprete_db_file)) {
    require_once $seo_dependiente_interprete_db_file;
}

// Intérprete runtime: puente lingüístico. Se carga para diagnóstico y/o para
// enriquecer la consulta que después resolverá exclusivamente Dependiente.
$seo_dependiente_interpreter_runtime =
    (defined('SEO_DEPENDIENTE_INTERPRETER_LOG') && SEO_DEPENDIENTE_INTERPRETER_LOG)
    || (defined('SEO_DEPENDIENTE_INTERPRETER_ASSIST') && SEO_DEPENDIENTE_INTERPRETER_ASSIST);
if ($seo_dependiente_interpreter_runtime) {
    $seo_dependiente_interprete_file = __DIR__ . '/seo-dependiente-interprete.php';
    if (!class_exists('SEO_Dependiente_Interprete') && is_readable($seo_dependiente_interprete_file)) {
        require_once $seo_dependiente_interprete_file;
    }
}


// Lingüista se gestiona desde la pestaña Intérprete; el worker solo ejecuta sus lotes.
$seo_dependiente_linguista_file = __DIR__ . '/linguista/seo-dependiente-linguista.php';
if (!class_exists('SEO_Dependiente_Linguista') && is_readable($seo_dependiente_linguista_file)) {
    require_once $seo_dependiente_linguista_file;
}
if (class_exists('SEO_Dependiente_Linguista')) {
    SEO_Dependiente_Linguista::init();
}
