<?php
/**
 * Compatibilidad con la ruta histórica de cobertura editorial.
 *
 * La implementación canónica vive en class-seo-editorial-coverage.php.
 * Este archivo se conserva para no romper includes antiguos durante la
 * transición, pero ya no declara SEO_Editorial_Coverage por sí mismo.
 */

defined('ABSPATH') || exit;

if (!class_exists('SEO_Editorial_Coverage', false)) {
    $seo_editorial_coverage_file = __DIR__ . '/class-seo-editorial-coverage.php';

    if (is_readable($seo_editorial_coverage_file)) {
        require_once $seo_editorial_coverage_file;
    }

    unset($seo_editorial_coverage_file);
}
