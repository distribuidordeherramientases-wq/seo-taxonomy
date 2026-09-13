<?php
/**
 * Alta manual de un unico producto usando el pipeline canonico.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_page_create_product')) {
    function seo_page_create_product() {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div style="max-width:1180px;padding:10px 0 30px;">';
        echo '<h1 style="margin-bottom:8px;">Nuevo producto</h1>';
        echo '<p style="margin-top:0;color:#646970;">Alta unitaria integrada con WooCommerce, proveedor, vocabulario, atributos e imágenes.</p>';

        if (function_exists('seo_render_product_form')) {
            seo_render_product_form(0);
        } else {
            echo '<div class="notice notice-error"><p>No está disponible el formulario compartido de producto.</p></div>';
        }

        echo '</div>';
    }
}
