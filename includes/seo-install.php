<?php
/**
 * Activation bridge for SEO Taxonomy.
 *
 * WordPress does not look for an install.php file automatically. The main
 * plugin file loads this bridge, and this bridge registers the activation
 * hook against SEO_SYSTEM_FILE (the real main plugin file).
 */

defined('ABSPATH') || exit;

require_once SEO_SYSTEM_PATH . 'includes/class-seo-installer.php';

/**
 * Create or reconcile the complete plugin schema and base data.
 */
function seo_taxonomy_install(): void
{
    SEO_System_Installer::install();
}

/*
 * IMPORTANT: the first argument must be the main plugin file. Registering the
 * hook with __FILE__ here would point to an included file and WordPress would
 * not execute it as the plugin activation hook.
 */
register_activation_hook(SEO_SYSTEM_FILE, 'seo_taxonomy_install');
