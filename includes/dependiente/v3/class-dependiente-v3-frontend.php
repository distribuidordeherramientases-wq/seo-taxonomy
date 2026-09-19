<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_V3_Frontend {
    private static $editor_style_attached = false;
    public static function init() {
        add_action('init', array(__CLASS__, 'register_shortcodes'), 20);
        add_action('init', array(__CLASS__, 'ensure_page'), 30);
        add_filter('template_include', array(__CLASS__, 'template_include'), 99);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_page_assets'), 20);
        add_filter('wp_robots', array(__CLASS__, 'query_robots'));
    }

    public static function register_shortcodes() {
        add_shortcode('dependiente_productos', array(__CLASS__, 'render'));
        add_shortcode('dependiente', array(__CLASS__, 'render'));
    }

    public static function render($atts = array()) {
        if (!class_exists('WooCommerce')) {
            return '<div class="woocommerce-info">Dependiente necesita WooCommerce activo.</div>';
        }
        self::enqueue_assets();
        $atts = shortcode_atts(array(
            'title' => '¿Qué necesitas?',
            'subtitle' => 'Escribe como hablarías con un dependiente. El Intérprete limpia la consulta y Dependiente consulta el catálogo aprendido.',
        ), $atts, 'dependiente_productos');

        $input_id = wp_unique_id('dependiente-v3-query-');
        ob_start();
        ?>
        <section class="dependiente-v3" data-dep3-root>
            <header class="dependiente-v3__hero">
                <div>
                    <span class="dependiente-v3__eyebrow">Dependiente 3.0</span>
                    <h1><?php echo esc_html($atts['title']); ?></h1>
                    <p><?php echo esc_html($atts['subtitle']); ?></p>
                </div>
                <form class="dependiente-v3__search" data-dep3-form>
                    <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>">Qué necesitas buscar</label>
                    <input id="<?php echo esc_attr($input_id); ?>" type="search" maxlength="180" autocomplete="off" placeholder="Ej.: elevador de moto · extractor de tornillos · se me ha roto un grifo" data-dep3-query>
                    <button type="submit">Buscar</button>
                </form>
            </header>

            <div class="dependiente-v3__status" data-dep3-status aria-live="polite"></div>

            <div class="dependiente-v3__workspace" data-dep3-workspace hidden>
                <aside class="dependiente-v3__debug" data-dep3-debug hidden>
                    <div class="dependiente-v3__panel-head">
                        <span>STAGING</span>
                        <h2>Recorrido de la búsqueda</h2>
                    </div>
                    <div data-dep3-debug-content></div>
                </aside>

                <main class="dependiente-v3__main">
                    <section class="dependiente-v3__categories dependiente-v3__guidance" data-dep3-categories-section hidden>
                        <div class="dependiente-v3__section-head">
                            <span>Lo que he entendido</span>
                            <h2>¿Cuál de estas opciones encaja mejor?</h2>
                            <p class="dependiente-v3__guidance-copy" data-dep3-guidance-copy></p>
                        </div>
                        <div class="dependiente-v3__category-grid" data-dep3-categories></div>
                    </section>

                    <section class="dependiente-v3__products" data-dep3-products-section hidden>
                        <div class="dependiente-v3__section-head dependiente-v3__section-head--products">
                            <div><span>Selección</span><h2>Productos que mejor encajan</h2></div>
                            <button type="button" class="dependiente-v3__clear-category" data-dep3-clear-category hidden>Quitar elección</button>
                        </div>
                        <div class="dependiente-v3__product-grid" data-dep3-products></div>
                        <nav class="dependiente-v3__pagination" data-dep3-pagination aria-label="Paginación"></nav>
                    </section>
                </main>
            </div>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function enqueue_page_assets() {
        if (!self::is_dependiente_page()) return;
        self::enqueue_assets();
    }

    public static function enqueue_assets() {
        // Sufijo propio para invalidar caches sin cambiar la version global del plugin.
        $asset_version = SEO_DEPENDIENTE_VERSION . '-visual-guidance-2-style-editor';
        wp_enqueue_style(
            'seo-dependiente-v3',
            SEO_DEPENDIENTE_V3_URL . 'assets/css/dependiente-v3.css',
            array(),
            $asset_version
        );
        self::attach_editor_style();
        wp_enqueue_script(
            'seo-dependiente-v3',
            SEO_DEPENDIENTE_V3_URL . 'assets/js/dependiente-v3.js',
            array(),
            $asset_version,
            true
        );
        wp_localize_script('seo-dependiente-v3', 'SEO_DEPENDIENTE_V3', array(
            'endpoint' => esc_url_raw(rest_url('seo-taxonomy/v3/search')),
            'showDebug' => current_user_can('manage_options'),
            'version' => SEO_DEPENDIENTE_VERSION,
        ));
    }

    /**
     * Inyecta sobre la hoja base las variables validadas por
     * SEO Marketing > Estilo visual > Dependiente 3.0.
     *
     * La hoja base mantiene fallbacks, de modo que Dependiente sigue siendo
     * funcional incluso si el modulo de Marketing no esta disponible.
     */
    private static function attach_editor_style() {
        if (self::$editor_style_attached) {
            return;
        }

        if (
            !function_exists('seo_marketing_style_get_settings')
            || !function_exists('seo_marketing_style_build_dependiente_css')
        ) {
            return;
        }

        $css = seo_marketing_style_build_dependiente_css(seo_marketing_style_get_settings());
        if (is_string($css) && $css !== '') {
            wp_add_inline_style('seo-dependiente-v3', $css);
            self::$editor_style_attached = true;
        }
    }

    public static function template_include($template) {
        if (!self::is_dependiente_page()) return $template;
        $file = SEO_DEPENDIENTE_V3_PATH . 'template-dependiente-v3.php';
        return is_readable($file) ? $file : $template;
    }

    public static function ensure_page() {
        $page_id = absint(get_option('seo_dependiente_page_id', 0));
        if ($page_id && 'trash' !== get_post_status($page_id)) return $page_id;
        $existing = get_page_by_path('dependiente', OBJECT, 'page');
        if ($existing instanceof WP_Post) {
            if (!has_shortcode((string) $existing->post_content, 'dependiente_productos') && !has_shortcode((string) $existing->post_content, 'dependiente')) {
                wp_update_post(array('ID' => $existing->ID, 'post_content' => rtrim((string) $existing->post_content) . "\n\n[dependiente_productos]"));
            }
            update_option('seo_dependiente_page_id', absint($existing->ID), false);
            return absint($existing->ID);
        }
        $created = wp_insert_post(array(
            'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Dependiente',
            'post_name' => 'dependiente', 'post_content' => '[dependiente_productos]',
        ), true);
        if (is_wp_error($created)) return 0;
        update_option('seo_dependiente_page_id', absint($created), false);
        return absint($created);
    }

    public static function query_robots($robots) {
        if (!is_array($robots) || !self::is_dependiente_page()) return $robots;
        if (!empty($_GET['dep_q'])) {
            $robots['noindex'] = true;
            $robots['follow'] = true;
        }
        return $robots;
    }

    private static function is_dependiente_page() {
        if (is_admin() || !is_singular('page')) return false;
        $page_id = get_queried_object_id();
        $configured = absint(get_option('seo_dependiente_page_id', 0));
        return ($configured && $page_id === $configured) || is_page('dependiente');
    }
}
