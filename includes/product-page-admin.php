<?php
/**
 * Pestaña Products del SEO System.
 *
 * La página ya no guarda productos directamente. Nuevo y Editar delegan en
 * product-service.php; Inventario y Recategorizar mantienen sus módulos.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_product_admin_callback')) {
    function seo_product_admin_callback() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'editar';
        $allowed_tabs = ['nuevo', 'editar', 'inventario', 'recategorizar', 'informes'];
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = 'editar';
        }

        echo '<div class="wrap">';
        echo '<h1 style="margin-bottom:16px;">Productos</h1>';

        if (function_exists('seo_product_render_admin_notice')) {
            seo_product_render_admin_notice();
        }

        $tabs = [
            'nuevo'         => 'Nuevo producto',
            'editar'        => 'Editar producto',
            'inventario'    => 'Inventario',
            'recategorizar' => 'Recategorizar',
            'informes'      => 'Informes Google',
        ];

        echo '<nav class="nav-tab-wrapper" style="margin-bottom:20px;">';
        foreach ($tabs as $tab_key => $label) {
            $url = add_query_arg(
                [
                    'page' => 'product-page-admin',
                    'tab'  => $tab_key,
                ],
                admin_url('admin.php')
            );
            $class = 'nav-tab' . ($active_tab === $tab_key ? ' nav-tab-active' : '');
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        switch ($active_tab) {
            case 'nuevo':
                if (function_exists('seo_page_create_product')) {
                    seo_page_create_product();
                } else {
                    echo '<div class="notice notice-error"><p>No está disponible el módulo de alta de producto.</p></div>';
                }
                break;

            case 'inventario':
                if (function_exists('seo_product_inventory_page')) {
                    seo_product_inventory_page();
                } else {
                    echo '<div class="notice notice-error"><p>No está disponible el inventario de productos.</p></div>';
                }
                break;

            case 'recategorizar':
                if (function_exists('product_recategorization')) {
                    product_recategorization();
                } else {
                    echo '<div class="notice notice-error"><p>No está disponible el módulo de recategorización.</p></div>';
                }
                break;

            case 'informes':
                if (function_exists('seo_product_reports_page')) {
                    seo_product_reports_page();
                } else {
                    echo '<div class="notice notice-error"><p>No esta disponible el modulo de informes Google por producto.</p></div>';
                }
                break;

            case 'editar':
            default:
                if (function_exists('seo_page_edit_products')) {
                    seo_page_edit_products();
                } else {
                    echo '<div class="notice notice-error"><p>No está disponible el editor de productos.</p></div>';
                }
                break;
        }

        echo '</div>';
    }
}
