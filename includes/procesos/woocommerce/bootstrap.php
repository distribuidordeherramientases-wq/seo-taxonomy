<?php
/**
 * Bootstrap del control de procesos de WooCommerce.
 *
 * @package SEOSystem
 * @subpackage Processes_WooCommerce
 * @since 2.3.3
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/process-control.php';

if (function_exists('seo_processes_register_tab')) {
    seo_processes_register_tab(
        'woocommerce',
        'WooCommerce',
        'seo_wc_process_control_render_page',
        30
    );
}
