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
        add_filter('body_class', array(__CLASS__, 'body_classes'));
    }

    public static function register_shortcodes() {
        add_shortcode('dependiente_productos', array(__CLASS__, 'render'));
        add_shortcode('dependiente', array(__CLASS__, 'render'));
    }

    private static function navigation_groups($limit = 3) {
        global $wpdb;

        $limit = max(1, min(5, absint($limit)));
        $nodes = $wpdb->prefix . 'seo_nodes';
        $relations = $wpdb->prefix . 'seo_relations';

        $cluster_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT n.object_id
                 FROM {$nodes} n
                 INNER JOIN {$wpdb->posts} p ON p.ID = n.object_id
                 WHERE n.object_type = 'page'
                   AND n.seo_role = 'cluster'
                   AND n.status = 1
                   AND p.post_status = 'publish'
                 ORDER BY n.id ASC
                 LIMIT %d",
                $limit
            )
        );

        $groups = array();

        foreach (array_map('absint', (array) $cluster_ids) as $cluster_id) {
            if (!$cluster_id) continue;

            $hub_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT target_id
                     FROM {$relations}
                     WHERE source_type = 'cluster'
                       AND source_id = %d
                       AND target_type = 'hub_primary'
                       AND relation_type IN ('cluster_to_primary','cluster_to_hub_primary')
                     ORDER BY id ASC
                     LIMIT 5",
                    $cluster_id
                )
            );

            $hubs = array();
            $category_ids = array();

            foreach (array_map('absint', (array) $hub_ids) as $hub_id) {
                if (!$hub_id || 'publish' !== get_post_status($hub_id)) continue;

                $hubs[] = array(
                    'title' => get_the_title($hub_id),
                    'url'   => get_permalink($hub_id),
                );

                $direct_categories = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT target_id
                         FROM {$relations}
                         WHERE source_type = 'hub_primary'
                           AND source_id = %d
                           AND target_type = 'product_cat'
                           AND relation_type = 'hub_primary_to_category'",
                        $hub_id
                    )
                );
                $category_ids = array_merge($category_ids, array_map('absint', (array) $direct_categories));

                $secondary_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT target_id
                         FROM {$relations}
                         WHERE source_type = 'hub_primary'
                           AND source_id = %d
                           AND target_type = 'hub_secondary'
                           AND relation_type = 'hub_primary_to_hub_secondary'",
                        $hub_id
                    )
                );

                foreach (array_map('absint', (array) $secondary_ids) as $secondary_id) {
                    if (!$secondary_id || 'publish' !== get_post_status($secondary_id)) continue;
                    if (count($hubs) < 5) {
                        $hubs[] = array(
                            'title' => get_the_title($secondary_id),
                            'url'   => get_permalink($secondary_id),
                        );
                    }

                    $secondary_categories = $wpdb->get_col(
                        $wpdb->prepare(
                            "SELECT target_id
                             FROM {$relations}
                             WHERE source_type = 'hub_secondary'
                               AND source_id = %d
                               AND target_type = 'product_cat'
                               AND relation_type = 'hub_secondary_to_category'",
                            $secondary_id
                        )
                    );
                    $category_ids = array_merge($category_ids, array_map('absint', (array) $secondary_categories));
                }
            }

            $categories = array();
            foreach (array_values(array_unique(array_filter($category_ids))) as $category_id) {
                $term = get_term($category_id, 'product_cat');
                if (!$term || is_wp_error($term) || (int) $term->count < 1) continue;
                $url = get_term_link($term, 'product_cat');
                if (is_wp_error($url)) continue;
                $categories[] = array(
                    'title' => $term->name,
                    'url'   => $url,
                    'count' => (int) $term->count,
                );
            }

            usort($categories, static function ($a, $b) {
                return ($b['count'] <=> $a['count']) ?: strcasecmp($a['title'], $b['title']);
            });

            $groups[] = array(
                'title'      => get_the_title($cluster_id),
                'url'        => get_permalink($cluster_id),
                'hubs'       => array_slice($hubs, 0, 4),
                'categories' => array_slice($categories, 0, 5),
            );
        }

        return $groups;
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
        $navigation_groups = self::navigation_groups(3);
        $assistant_image = defined('SEO_DEPENDIENTE_URL')
            ? SEO_DEPENDIENTE_URL . 'assets/images/dependiente-necesito-herramienta.webp'
            : '';
        ob_start();
        ?>
        <section class="dependiente-v3" data-dep3-root>
            <header class="dependiente-v3__hero dependiente-v3__hero--assistant">
                <?php if ($assistant_image !== '') : ?>
                    <figure class="dependiente-v3__assistant-media">
                        <img src="<?php echo esc_url($assistant_image); ?>" alt="Dependiente de Distribuidor de Herramientas" loading="eager" fetchpriority="high">
                    </figure>
                <?php endif; ?>
                <div class="dependiente-v3__hero-content">
                    <span class="dependiente-v3__eyebrow">Tu dependiente digital</span>
                    <h1><?php echo esc_html($atts['title']); ?></h1>
                    <p>Cuéntame qué quieres hacer, qué se ha roto o qué herramienta buscas. No necesitas conocer el nombre técnico del producto.</p>
                    <form class="dependiente-v3__search" data-dep3-form>
                        <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>">Qué necesitas buscar</label>
                        <input id="<?php echo esc_attr($input_id); ?>" type="search" maxlength="180" autocomplete="off" placeholder="Ej.: quiero elevar una moto · se me ha roto un grifo · necesito cortar azulejos" data-dep3-query>
                        <button type="submit">Buscar</button>
                    </form>
                    <small class="dependiente-v3__search-help">Dependiente interpreta tu necesidad, propone categorías y después ordena los productos que mejor encajan.</small>
                </div>
            </header>

            <?php if (!empty($navigation_groups)) : ?>
                <section class="dependiente-v3__browse" data-dep3-browse aria-label="Explorar catálogo por áreas">
                    <div class="dependiente-v3__browse-head">
                        <span>También puedes explorar</span>
                        <h2>Áreas, guías y categorías</h2>
                        <p>Si todavía no quieres escribir una consulta, entra directamente por la estructura del catálogo.</p>
                    </div>
                    <div class="dependiente-v3__browse-grid">
                        <?php foreach ($navigation_groups as $group) : ?>
                            <article class="dependiente-v3__browse-group">
                                <a class="dependiente-v3__browse-cluster" href="<?php echo esc_url($group['url']); ?>"><?php echo esc_html($group['title']); ?></a>
                                <?php if (!empty($group['hubs'])) : ?>
                                    <div class="dependiente-v3__browse-hubs">
                                        <?php foreach ($group['hubs'] as $hub) : ?>
                                            <a href="<?php echo esc_url($hub['url']); ?>"><?php echo esc_html($hub['title']); ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($group['categories'])) : ?>
                                    <div class="dependiente-v3__browse-categories">
                                        <?php foreach ($group['categories'] as $category) : ?>
                                            <a href="<?php echo esc_url($category['url']); ?>">
                                                <span><?php echo esc_html($category['title']); ?></span>
                                                <small><?php echo esc_html(number_format_i18n($category['count'])); ?></small>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

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
        $asset_version = SEO_DEPENDIENTE_VERSION . '-dependiente-home-20261007';
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

    public static function body_classes($classes) {
        if (!is_array($classes)) $classes = array();
        if (self::is_dependiente_page()) {
            $classes[] = 'dependiente-v3-app-body';
            $classes[] = 'dht-dependiente-page';
        }
        return array_values(array_unique($classes));
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
