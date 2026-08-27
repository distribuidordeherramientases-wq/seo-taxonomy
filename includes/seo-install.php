<?php
/**
 * Puente de instalación de SEO Taxonomy.
 *
 * Este archivo conserva la función histórica seo_taxonomy_install()
 * utilizada por el hook de activación, pero delega toda la instalación
 * real en SEO_System_Installer.
 */

defined('ABSPATH') || exit;

require_once SEO_SYSTEM_PATH . 'includes/class-seo-installer.php';

/**
 * Instala o actualiza el esquema base del plugin.
 *
 * Esta función debe permanecer disponible mientras el archivo principal
 * registre el hook de activación con seo_taxonomy_install().
 */
function seo_taxonomy_install(): void
{
    SEO_System_Installer::install();
}
