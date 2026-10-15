<?php
/**
 * Template Name: Categoria WooCommerce SEO
 *
 * Router de categorias WooCommerce:
 * - movil      -> template-category-mobile.php
 * - escritorio -> template-category-desktop.php
 */

defined('ABSPATH') || exit;

if (!is_product_category()) {
    wp_safe_redirect(home_url('/'));
    exit;
}

require_once __DIR__ . '/template-helpers.php';

// Seleccionar la variante US si la categoría pertenece a la rama de Estados Unidos.
// Se comprueba también la ascendencia para cubrir subcategorías y sus productos.
$is_us_category = false;
$current_category = get_queried_object();
if ($current_category instanceof WP_Term && 'product_cat' === $current_category->taxonomy) {
    $category_ids = array_merge(
        array((int) $current_category->term_id),
        get_ancestors($current_category->term_id, 'product_cat', 'taxonomy')
    );
    foreach ($category_ids as $category_id) {
        $category_term = get_term((int) $category_id, 'product_cat');
        if ($category_term instanceof WP_Term
            && preg_match('/^(?:us|usa|eua|eeuu|estados-unidos|united-states|en-us)(?:-|$)/', $category_term->slug)
        ) {
            $is_us_category = true;
            break;
        }
    }
}

$device = wp_is_mobile() ? 'mobile' : 'desktop';
$template_file = __DIR__ . '/template-category-' . ($is_us_category ? 'us-' : '') . $device . '.php';

if (!is_readable($template_file)) {
    wp_die(
        'No se encuentra la plantilla de categoria: ' .
        esc_html(basename($template_file))
    );
}

require $template_file;
