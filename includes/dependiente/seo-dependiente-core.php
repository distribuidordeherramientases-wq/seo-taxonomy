<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_Plugin {
    private static $instance = null;
    private $assets_enqueued = false;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Ejecutar upgrades cuando WordPress ya ha inicializado su entorno de
        // reescritura. Hacerlo en plugins_loaded puede dejar $wp_rewrite a null
        // cuando wp_insert_post() intenta generar el permalink de la pagina.
        add_action('init', array($this, 'register_shortcode'), 10);
        add_action('init', array($this, 'maybe_upgrade'), 20);

        // Recuperacion de instalaciones interrumpidas: la version de BD pudo
        // guardarse antes de que se crease la pagina Dependiente. admin_init es
        // suficientemente tarde para crear/actualizar paginas y menus.
        add_action('admin_init', array($this, 'ensure_public_page'), 20);
        add_action('rest_api_init', array('SEO_Dependiente_API', 'register_routes'));

        add_filter('template_include', array($this, 'template_include'), 99);
        add_filter('wp_robots', array($this, 'filter_query_state_robots'), 99);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_page_assets'), 20);

        add_action('woocommerce_new_product', array('SEO_Dependiente_Index', 'index_product'), 99);
        add_action('woocommerce_update_product', array('SEO_Dependiente_Index', 'index_product'), 99);
        add_action('save_post_product', array($this, 'late_index_product'), 999, 3);
        add_action('before_delete_post', array($this, 'delete_product_index'));
        add_action('transition_post_status', array($this, 'sync_product_status'), 99, 3);

        add_action('seo_dependiente_background_index', array($this, 'run_background_index'));

        if (is_admin()) {
            SEO_Dependiente_Admin::init();
        }
    }

    public static function install_module() {
        SEO_Dependiente_Index::install();
        update_option('seo_dependiente_db_version', SEO_DEPENDIENTE_DB_VERSION, false);

        $defaults = array(
            'results_per_page' => 18,
            'menu_cards'       => 8,
            'custom_meta_keys' => '_seo_proveedor,_seo_proveedor_mpn,_seo_categoria_proveedor,_seo_fabricante,_seo_marca_proveedor',
            'action_image_need'    => 0,
            'action_image_product' => 0,
            'action_image_tool'    => 0,
            'action_image_compare' => 0,
        );
        $saved = get_option('seo_dependiente_options', array());
        update_option('seo_dependiente_options', wp_parse_args(is_array($saved) ? $saved : array(), $defaults), false);

        if (class_exists('WooCommerce')) {
            SEO_Dependiente_Index::index_batch(1, 60);
        }

        if (!wp_next_scheduled('seo_dependiente_background_index')) {
            wp_schedule_single_event(time() + 30, 'seo_dependiente_background_index');
        }
    }

    public function maybe_upgrade() {
        $installed = (string) get_option('seo_dependiente_db_version', '');
        if (SEO_DEPENDIENTE_DB_VERSION !== $installed) {
            self::install_module();
        }
    }

    /**
     * Crea o recupera la pagina publica desde un contexto seguro del admin.
     * Tambien repara instalaciones en las que el primer intento se interrumpio
     * despues de guardar la version de la base de datos.
     */
    public function ensure_public_page() {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            return;
        }

        self::ensure_page();
    }

    public static function option($key, $default = null) {
        $options = get_option('seo_dependiente_options', array());
        return is_array($options) && array_key_exists($key, $options) ? $options[$key] : $default;
    }

    public function register_shortcode() {
        add_shortcode('dependiente_productos', array($this, 'render_shortcode'));
        add_shortcode('dependiente', array($this, 'render_shortcode'));
    }

    public function template_include($template) {
        if (is_admin() || !is_singular('page')) {
            return $template;
        }

        $page_id = get_queried_object_id();
        $configured_page_id = absint(get_option('seo_dependiente_page_id', 0));
        $is_dependiente = ($configured_page_id && $page_id === $configured_page_id) || is_page('dependiente');

        if (!$is_dependiente) {
            return $template;
        }

        $dependiente_template = SEO_DEPENDIENTE_PATH . 'template-dependiente.php';
        return is_readable($dependiente_template) ? $dependiente_template : $template;
    }

    public function enqueue_page_assets() {
        if (is_admin() || !is_singular('page')) {
            return;
        }

        $page_id = get_queried_object_id();
        $configured_page_id = absint(get_option('seo_dependiente_page_id', 0));
        if (($configured_page_id && $page_id === $configured_page_id) || is_page('dependiente')) {
            $this->enqueue_assets();
        }
    }

    /**
     * La portada de Dependiente puede indexarse segun la politica SEO general
     * del sitio. Los estados internos compartidos con ?dep_q= son busquedas de
     * la aplicacion, no nuevas landings SEO, por lo que se marcan noindex.
     * En staging se respeta cualquier nofollow/noindex global ya existente.
     */
    public function filter_query_state_robots($robots) {
        if (!is_array($robots) || is_admin() || !is_singular('page')) {
            return $robots;
        }

        $page_id = get_queried_object_id();
        $configured_page_id = absint(get_option('seo_dependiente_page_id', 0));
        $is_dependiente = ($configured_page_id && $page_id === $configured_page_id) || is_page('dependiente');
        if (!$is_dependiente) {
            return $robots;
        }

        $query = isset($_GET['dep_q']) ? sanitize_text_field(wp_unslash($_GET['dep_q'])) : '';
        if ('' === trim($query)) {
            return $robots;
        }

        $robots['noindex'] = true;
        if (!isset($robots['nofollow'])) {
            $robots['follow'] = true;
        }

        return $robots;
    }

    public function render_shortcode($atts = array()) {
        if (!class_exists('WooCommerce')) {
            return current_user_can('activate_plugins')
                ? '<div class="woocommerce-info">Dependiente necesita WooCommerce activo.</div>'
                : '';
        }

        $atts = shortcode_atts(
            array(
                'title'    => '¿Qué necesitas?',
                'subtitle' => 'Busca una herramienta, describe un trabajo o compara opciones.',
            ),
            $atts,
            'dependiente_productos'
        );

        $this->enqueue_assets();

        $query_input_id = wp_unique_id('seo-dependiente-query-');
        $action_images = array(
            'need'    => $this->action_image_url('need', 'dependiente-arreglar-algo.webp'),
            'product' => $this->action_image_url('product', 'dependiente-buscar-herramienta.webp'),
            'tool'    => $this->action_image_url('tool', 'dependiente-necesito-herramienta.webp'),
            'compare' => $this->action_image_url('compare', 'dependiente-comparar-herramientas.webp'),
        );

        ob_start();
        ?>
        <section class="seo-dependiente" data-dependiente-root>
            <div class="seo-dependiente__hero">
                <div class="seo-dependiente__hero-copy">
                    <span class="seo-dependiente__eyebrow">Tu dependiente digital</span>
                    <h1><?php echo esc_html($atts['title']); ?></h1>
                    <p><?php echo esc_html($atts['subtitle']); ?></p>
                </div>

                <form class="seo-dependiente__ask" data-dependiente-search-form>
                    <label class="screen-reader-text" for="<?php echo esc_attr($query_input_id); ?>">Describe el producto o trabajo que necesitas</label>
                    <div class="seo-dependiente__ask-row">
                        <span class="seo-dependiente__ask-icon" aria-hidden="true">⌕</span>
                        <input
                            id="<?php echo esc_attr($query_input_id); ?>"
                            type="search"
                            data-dependiente-query
                            placeholder="Ej.: se me ha roto un grifo · necesito un taladro · comparar infladores"
                            autocomplete="off"
                            maxlength="180"
                        >
                        <button type="submit">Buscar solución</button>
                    </div>
                    <div class="seo-dependiente__examples" data-dependiente-examples aria-label="Ejemplos de búsqueda"></div>
                </form>

                <div class="seo-dependiente__path-intro">O elige una forma de empezar</div>
                <div class="seo-dependiente__paths" aria-label="Formas de buscar">
                    <button type="button" class="seo-dependiente__path is-active" data-dependiente-mode="need">
                        <span class="seo-dependiente__path-image"><img src="<?php echo esc_url($action_images['need']); ?>" alt="" loading="eager" decoding="async"></span>
                        <span class="seo-dependiente__path-copy"><strong>Tengo que arreglar o hacer algo</strong><small>Cuéntame el trabajo y buscaré qué herramientas o productos necesitas.</small></span>
                    </button>
                    <button type="button" class="seo-dependiente__path" data-dependiente-mode="product">
                        <span class="seo-dependiente__path-image"><img src="<?php echo esc_url($action_images['product']); ?>" alt="" loading="eager" decoding="async"></span>
                        <span class="seo-dependiente__path-copy"><strong>Busco un producto concreto</strong><small>Busca por nombre, marca, referencia, medida o característica.</small></span>
                    </button>
                    <button type="button" class="seo-dependiente__path" data-dependiente-mode="tool">
                        <span class="seo-dependiente__path-image"><img src="<?php echo esc_url($action_images['tool']); ?>" alt="" loading="lazy" decoding="async"></span>
                        <span class="seo-dependiente__path-copy"><strong>Busco algo compatible</strong><small>Dime qué máquina, batería, plataforma o sistema ya tienes.</small></span>
                    </button>
                    <button type="button" class="seo-dependiente__path" data-dependiente-mode="compare">
                        <span class="seo-dependiente__path-image"><img src="<?php echo esc_url($action_images['compare']); ?>" alt="" loading="lazy" decoding="async"></span>
                        <span class="seo-dependiente__path-copy"><strong>Quiero comparar</strong><small>Busca varias opciones y compara hasta cuatro lado a lado.</small></span>
                    </button>
                </div>
            </div>

            <section class="seo-dependiente__discovery" data-dependiente-discovery>
                <div class="seo-dependiente__section-heading">
                    <div><span>Explora</span><h2>Explora por tipo de tarea</h2></div>
                    <p>Elige una tarea habitual y descubre opciones relacionadas.</p>
                </div>
                <div class="seo-dependiente__visual-menu" data-dependiente-actions></div>

                <div class="seo-dependiente__section-heading seo-dependiente__section-heading--spaced">
                    <div><span>Compatibilidad</span><h2>Explora por herramienta o sistema</h2></div>
                    <p>Útil cuando ya tienes una máquina, plataforma o sistema y buscas algo compatible.</p>
                </div>
                <div class="seo-dependiente__visual-menu" data-dependiente-tools></div>
            </section>

            <section class="seo-dependiente__workspace" data-dependiente-workspace hidden>
                <div class="seo-dependiente__toolbar">
                    <button type="button" class="seo-dependiente__filter-toggle" data-dependiente-filter-toggle aria-expanded="false">Filtros</button>
                    <div class="seo-dependiente__summary" data-dependiente-summary aria-live="polite"></div>
                    <label class="seo-dependiente__sort">Ordenar
                        <select data-dependiente-sort>
                            <option value="relevance">Mejor coincidencia</option>
                            <option value="price_asc">Precio: menor a mayor</option>
                            <option value="price_desc">Precio: mayor a menor</option>
                            <option value="newest">Más recientes</option>
                            <option value="title">Nombre</option>
                        </select>
                    </label>
                </div>

                <div class="seo-dependiente__layout">
                    <aside class="seo-dependiente__filters" data-dependiente-filters aria-label="Filtros de productos"></aside>
                    <aside class="seo-dependiente__related" data-dependiente-related aria-label="Guías y soluciones relacionadas" hidden></aside>
                    <div class="seo-dependiente__results-column">
                        <div class="seo-dependiente__active-filters" data-dependiente-active-filters></div>
                        <div class="seo-dependiente__status" data-dependiente-status aria-live="polite"></div>
                        <div class="seo-dependiente__results" data-dependiente-results></div>
                        <nav class="seo-dependiente__pagination" data-dependiente-pagination aria-label="Paginación"></nav>
                    </div>
                </div>
            </section>

            <div class="seo-dependiente__compare-tray" data-dependiente-compare-tray hidden>
                <div>
                    <strong data-dependiente-compare-count>0 productos</strong>
                    <span>Selecciona entre 2 y 4 para comparar.</span>
                </div>
                <div class="seo-dependiente__compare-actions">
                    <button type="button" class="is-secondary" data-dependiente-compare-clear>Vaciar</button>
                    <button type="button" data-dependiente-compare-open disabled>Comparar</button>
                </div>
            </div>

            <dialog class="seo-dependiente__dialog" data-dependiente-dialog>
                <div class="seo-dependiente__dialog-head">
                    <div><span>Comparador</span><h2>Qué cambia entre estas opciones</h2></div>
                    <button type="button" class="seo-dependiente__dialog-close" data-dependiente-dialog-close aria-label="Cerrar">×</button>
                </div>
                <div data-dependiente-compare-content></div>
            </dialog>
        </section>
        <?php
        return ob_get_clean();
    }

    private function action_image_url($key, $filename) {
        $attachment_id = absint(self::option('action_image_' . sanitize_key($key), 0));
        if ($attachment_id) {
            $url = wp_get_attachment_image_url($attachment_id, 'large');
            if ($url) {
                return (string) $url;
            }
        }

        return SEO_DEPENDIENTE_URL . 'assets/images/' . ltrim((string) $filename, '/');
    }

    private function enqueue_assets() {
        if ($this->assets_enqueued) {
            return;
        }
        $this->assets_enqueued = true;

        wp_enqueue_style(
            'seo-dependiente',
            SEO_DEPENDIENTE_URL . 'assets/css/seo-dependiente.css',
            array(),
            SEO_DEPENDIENTE_VERSION
        );
        wp_enqueue_script(
            'seo-dependiente',
            SEO_DEPENDIENTE_URL . 'assets/js/seo-dependiente.js',
            array(),
            SEO_DEPENDIENTE_VERSION,
            true
        );

        $fallback_image = '';
        $custom_logo_id = absint(get_theme_mod('custom_logo'));
        if ($custom_logo_id) {
            $fallback_image = (string) wp_get_attachment_image_url($custom_logo_id, 'full');
        }
        if (!$fallback_image) {
            $fallback_image = (string) get_site_icon_url(512);
        }
        if (!$fallback_image && function_exists('wc_placeholder_img_src')) {
            $fallback_image = (string) wc_placeholder_img_src('woocommerce_thumbnail');
        }

        wp_localize_script('seo-dependiente', 'SEODependienteConfig', array(
            'root'             => esc_url_raw(rest_url('seo-taxonomy/v1/')),
            'currencySymbol'   => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '€',
            'weightUnit'       => get_option('woocommerce_weight_unit', 'kg'),
            'dimensionUnit'    => get_option('woocommerce_dimension_unit', 'cm'),
            'resultsPerPage'   => absint(self::option('results_per_page', 18)),
            'compareMax'       => 4,
            'placeholderImage' => esc_url_raw($fallback_image),
            'labels'           => array(
                'error'       => 'No he podido completar la búsqueda. Inténtalo de nuevo.',
                'loading'     => 'Estoy revisando el catálogo…',
                'noResults'   => 'No he encontrado una coincidencia clara. Puedes quitar filtros, simplificar la búsqueda o explorar una alternativa.',
                'viewProduct' => 'Ver producto',
                'compare'     => 'Comparar',
            ),
            'modePlaceholders' => array(
                'need'    => 'Ej.: se me ha roto un grifo y quiero cambiarlo',
                'product' => 'Ej.: taladro Bosch 18 V, referencia, medida o característica',
                'tool'    => 'Ej.: batería compatible con Makita LXT 18 V',
                'compare' => 'Ej.: infladores de ruedas 12 V para comparar',
            ),
            'modeButtons'      => array(
                'need'    => 'Buscar solución',
                'product' => 'Buscar producto',
                'tool'    => 'Buscar compatibles',
                'compare' => 'Buscar para comparar',
            ),
        ));
    }

    public function late_index_product($post_id, $post, $update) {
        unset($update);
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || 'product' !== $post->post_type) {
            return;
        }
        SEO_Dependiente_Index::index_product($post_id);
    }

    public function delete_product_index($post_id) {
        if ('product' === get_post_type($post_id)) {
            SEO_Dependiente_Index::delete_product($post_id);
        }
    }

    public function sync_product_status($new_status, $old_status, $post) {
        unset($old_status);
        if (!$post || 'product' !== $post->post_type) {
            return;
        }
        if ('publish' === $new_status) {
            SEO_Dependiente_Index::index_product($post->ID);
        } else {
            SEO_Dependiente_Index::delete_product($post->ID);
        }
    }

    public function run_background_index() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $page = max(1, absint(get_option('seo_dependiente_background_page', 1)));
        $result = SEO_Dependiente_Index::index_batch($page, 50);

        if (!empty($result['done'])) {
            delete_option('seo_dependiente_background_page');
            update_option('seo_dependiente_last_full_index', current_time('mysql'), false);
            return;
        }

        update_option('seo_dependiente_background_page', $page + 1, false);
        wp_schedule_single_event(time() + 60, 'seo_dependiente_background_index');
    }

    public static function ensure_page() {
        $page_id = absint(get_option('seo_dependiente_page_id', 0));
        if ($page_id && 'trash' !== get_post_status($page_id)) {
            return $page_id;
        }

        $existing = get_page_by_path('dependiente', OBJECT, 'page');
        if ($existing instanceof WP_Post) {
            if (!has_shortcode((string) $existing->post_content, 'dependiente_productos') && !has_shortcode((string) $existing->post_content, 'dependiente')) {
                $updated = wp_update_post(array(
                    'ID'           => $existing->ID,
                    'post_content' => rtrim((string) $existing->post_content) . "\n\n[dependiente_productos]",
                ), true);
                if (!is_wp_error($updated)) {
                    update_option('seo_dependiente_shortcode_appended_page_id', $existing->ID, false);
                }
            }
            update_option('seo_dependiente_page_id', $existing->ID, false);
            return $existing->ID;
        }

        $page_id = wp_insert_post(array(
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Dependiente',
            'post_name'    => 'dependiente',
            'post_content' => '[dependiente_productos]',
        ), true);

        if (is_wp_error($page_id)) {
            return 0;
        }

        update_option('seo_dependiente_page_id', absint($page_id), false);
        update_option('seo_dependiente_page_created_id', absint($page_id), false);
        return absint($page_id);
    }

    public static function maybe_add_page_to_primary_menu($page_id) {
        $page_id = absint($page_id);
        if (!$page_id || !function_exists('wp_get_nav_menus')) {
            return 0;
        }

        $locations = get_nav_menu_locations();
        $preferred = array('primary', 'menu-1', 'main', 'main-menu', 'header', 'header-menu');
        $menu_id = 0;

        foreach ($preferred as $location) {
            if (!empty($locations[$location])) {
                $menu_id = absint($locations[$location]);
                break;
            }
        }
        if (!$menu_id && $locations) {
            $menu_id = absint(reset($locations));
        }
        if (!$menu_id) {
            return 0;
        }

        foreach ((array) wp_get_nav_menu_items($menu_id) as $item) {
            if ('page' === $item->object && absint($item->object_id) === $page_id) {
                return absint($item->ID);
            }
        }

        $item_id = wp_update_nav_menu_item($menu_id, 0, array(
            'menu-item-title'     => 'Dependiente',
            'menu-item-object'    => 'page',
            'menu-item-object-id' => $page_id,
            'menu-item-type'      => 'post_type',
            'menu-item-status'    => 'publish',
        ));

        if (!is_wp_error($item_id)) {
            update_option('seo_dependiente_menu_item_id', absint($item_id), false);
            return absint($item_id);
        }
        return 0;
    }
}
