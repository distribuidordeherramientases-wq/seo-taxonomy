<?php
/**
 * Central request-to-template resolver.
 * Independent of TaxoCommerce Core, SEO Taxonomy and any theme.
 *
 * @package TaxoCommerceTemplates
 */
defined('ABSPATH') || exit;

final class TCT_Helpers {
    /**
     * Resolve a WordPress request into a named template route and an
     * optional role stored in the shared, canonical seo_nodes table.
     */
    public static function request_context() {
        $context = array(
            'route' => '',
            'object_type' => '',
            'object_id' => 0,
            'role' => '',
        );

        // Endpoint priority matters: "order received" and "cart" are pages.
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            $context['route'] = 'thankyou';
        } elseif (function_exists('is_checkout') && is_checkout()) {
            $context['route'] = 'checkout';
        } elseif (function_exists('is_cart') && is_cart()) {
            $context['route'] = 'cart';
        } elseif (function_exists('is_account_page') && is_account_page()) {
            $context['route'] = 'myaccount';
        } elseif (is_front_page()) {
            $context['route'] = 'front_page';
        } elseif (is_home()) {
            $context['route'] = 'blog_index';
        } elseif (is_search()) {
            $context['route'] = 'search';
        } elseif (function_exists('is_shop') && is_shop()) {
            $context['route'] = 'shop';
        } elseif (is_404()) {
            $context['route'] = 'error_404';
        }

        if (is_singular()) {
            $id = absint(get_queried_object_id());
            $type = sanitize_key(get_post_type($id));
            $context['object_id'] = $id;
            $context['object_type'] = $type;
            if (!$context['route']) {
                $context['route'] = 'single_' . $type;
            }
        } elseif (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                $context['object_id'] = absint($term->term_id);
                $context['object_type'] = sanitize_key($term->taxonomy);
                if (!$context['route']) {
                    $context['route'] = 'taxonomy_' . $context['object_type'];
                }
            }
        }

        if ($context['object_type'] && $context['object_id']) {
            $context['role'] = TCT_Store::get_role($context['object_type'], $context['object_id']);
        }

        /** @param array $context Current WordPress request and role. */
        return (array) apply_filters('taxocommerce_templates_request_context', $context);
    }

    public static function path($filename) {
        $filename = basename((string) $filename);
        if (!preg_match('/^[A-Za-z0-9._-]+\.php$/D', $filename)) {
            return '';
        }
        $custom = trailingslashit(TCT_Files::directory()) . $filename;
        if (is_file($custom) && is_readable($custom)) {
            return $custom;
        }
        $bundled = TCT_DIR . 'templates/' . $filename;
        return (is_file($bundled) && is_readable($bundled)) ? $bundled : '';
    }

    /**
     * Active registry records already exist in seo_templates on older sites.
     * The new resolver never reads PHP files from SEO Taxonomy's directory.
     */
    public static function registered_template($key) {
        global $wpdb;
        $key = sanitize_key((string) $key);
        if (!$key) {
            return null;
        }
        $table = TCT_Store::templates();
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT template_file,is_active,device_variants_enabled FROM {$table} WHERE template_key=%s LIMIT 1",
                $key
            )
        );
    }

    public static function select_template($original) {
        if (!get_option('tct_render_enabled', 0) || is_admin() || !TCT_Store::installed()) {
            return $original;
        }

        $context = self::request_context();
        $keys = array();
        if (!empty($context['role'])) {
            $keys[] = sanitize_key($context['role']);
        }
        if (!empty($context['route'])) {
            $keys[] = sanitize_key($context['route']);
        }

        foreach (array_unique($keys) as $key) {
            $record = self::registered_template($key);
            if (!$record || (int) $record->is_active !== 1) {
                continue;
            }

            $filename = basename((string) $record->template_file);
            $file = self::path($filename);
            if (!$file) {
                continue; // Missing file: permit the standard WordPress fallback.
            }

            if (!empty($record->device_variants_enabled)) {
                $suffix = wp_is_mobile() ? '-mobile' : '-desktop';
                $variant = preg_replace('/\.php$/', $suffix . '.php', $filename);
                $candidate = self::path($variant);
                if ($candidate) {
                    $file = $candidate;
                }
            }
            /** Filters final file path after checking a registered mapping. */
            $selected = apply_filters('taxocommerce_templates_resolved_path', $file, $context, $key);
            return (is_string($selected) && is_file($selected) && is_readable($selected)) ? $selected : $original;
        }
        return $original;
    }

    /** Human-readable route names; extensions can register new routes. */
    public static function routes() {
        return (array) apply_filters('taxocommerce_templates_routes', array(
            'front_page'           => 'Portada',
            'blog_index'           => 'Índice del blog',
            'search'               => 'Búsqueda',
            'shop'                 => 'Tienda',
            'cart'                 => 'Carrito',
            'checkout'             => 'Finalizar compra',
            'thankyou'             => 'Pedido recibido',
            'myaccount'            => 'Mi cuenta',
            'error_404'            => 'Página 404',
            'single_post'          => 'Entrada de blog',
            'single_page'          => 'Página sin rol específico',
            'single_product'       => 'Producto WooCommerce',
            'taxonomy_product_cat' => 'Categoría de productos',
            'taxonomy_category'    => 'Categoría del blog',
            'taxonomy_post_tag'    => 'Etiqueta',
        ));
    }
}
