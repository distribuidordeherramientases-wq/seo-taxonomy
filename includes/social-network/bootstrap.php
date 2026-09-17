<?php
/**
 * SEO System - bootstrap del Programador Social.
 */

defined('ABSPATH') || exit;

$seo_social_programador_modules = array(
    __DIR__ . '/programador.php',
);

foreach ($seo_social_programador_modules as $seo_social_programador_module) {
    if (is_readable($seo_social_programador_module)) {
        require_once $seo_social_programador_module;
    }
}
unset($seo_social_programador_modules, $seo_social_programador_module);
