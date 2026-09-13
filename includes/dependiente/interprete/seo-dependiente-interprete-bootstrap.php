<?php

defined('ABSPATH') || exit;

$seo_dependiente_interprete_db_file = __DIR__ . '/seo-dependiente-interprete-db.php';
if (!class_exists('SEO_Dependiente_Interprete_DB') && is_readable($seo_dependiente_interprete_db_file)) {
    require_once $seo_dependiente_interprete_db_file;
}

$seo_dependiente_interprete_file = __DIR__ . '/seo-dependiente-interprete.php';
if (!class_exists('SEO_Dependiente_Interprete') && is_readable($seo_dependiente_interprete_file)) {
    require_once $seo_dependiente_interprete_file;
}
