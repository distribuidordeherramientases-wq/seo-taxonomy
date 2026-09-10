<?php
/**
 * SEO System - bootstrap del subsistema de redes sociales.
 */

defined('ABSPATH') || exit;

$seo_social_modules = array(
    __DIR__ . '/core.php',
    __DIR__ . '/facebook.php',
    __DIR__ . '/linkedin.php',
    __DIR__ . '/pinterest.php',
);

foreach ($seo_social_modules as $seo_social_module) {
    if (is_readable($seo_social_module)) {
        require_once $seo_social_module;
    }
}
unset($seo_social_modules, $seo_social_module);
