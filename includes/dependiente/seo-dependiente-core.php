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
        add_action('init', array($this, 'cleanup_legacy_background_index'), 30);

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

        // El hook de fondo solo continua una reindexacion iniciada manualmente.
        // Nunca crea un proceso nuevo por si mismo.
        add_action('seo_dependiente_background_index', array($this, 'run_background_index'));

        // Integracion con el Gestor de procesos de SEO Taxonomy. El supervisor
        // solo recibe un target mientras exista una reindexacion ya iniciada.
        add_filter('seo_process_supervisor_has_pending_work', array(__CLASS__, 'supervisor_has_pending_reindex'), 10, 1);
        add_filter('seo_process_supervisor_manager_targets', array(__CLASS__, 'supervisor_add_reindex_target'), 10, 3);

        if (is_admin()) {
            SEO_Dependiente_Admin::init();
        }
    }

    public static function install_module() {
        SEO_Dependiente_Index::install();
        SEO_Dependiente_Semantics::install();
        if (class_exists('SEO_Dependiente_Help')) {
            SEO_Dependiente_Help::install();
        }
        update_option('seo_dependiente_db_version', SEO_DEPENDIENTE_DB_VERSION, false);

        $defaults = array(
            'results_per_page' => 18,
            'menu_cards'       => 8,
            'custom_meta_keys' => '_seo_proveedor,_seo_proveedor_mpn,_seo_categoria_proveedor,_seo_fabricante,_seo_marca_proveedor',
            'help_email'       => sanitize_email((string) get_option('admin_email', '')),
            'action_image_need'    => 0,
            'action_image_product' => 0,
            'action_image_tool'    => 0,
            'action_image_compare' => 0,
        );
        $saved = get_option('seo_dependiente_options', array());
        update_option('seo_dependiente_options', wp_parse_args(is_array($saved) ? $saved : array(), $defaults), false);

        // La instalacion/upgrade no inicia una reindexacion. El indice completo
        // solo se reconstruye cuando un administrador pulsa Reindexar.
        wp_clear_scheduled_hook('seo_dependiente_background_index');
        delete_option('seo_dependiente_background_page');
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
                'subtitle' => 'Describe lo que necesitas y elige qué tipo de solución quieres encontrar.',
            ),
            $atts,
            'dependiente_productos'
        );

        $this->enqueue_assets();

        $query_input_id = wp_unique_id('seo-dependiente-query-');
        $solution_role_id = wp_unique_id('seo-dependiente-role-');
        $help_email_id = wp_unique_id('seo-dependiente-help-email-');
        $help_note_id = wp_unique_id('seo-dependiente-help-note-');
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
                        <span class="seo-dependiente__assistant-avatar seo-dependiente__assistant-avatar--search" aria-hidden="true"><span>👤</span></span>
                        <input
                            id="<?php echo esc_attr($query_input_id); ?>"
                            type="search"
                            data-dependiente-query
                            placeholder="Ej.: se me ha roto un grifo · necesito trabajar en una tubería"
                            autocomplete="off"
                            maxlength="180"
                        >
                        <label class="seo-dependiente__role-select" for="<?php echo esc_attr($solution_role_id); ?>">
                            <span>Quiero encontrar</span>
                            <select id="<?php echo esc_attr($solution_role_id); ?>" data-dependiente-role required>
                                <option value="" selected disabled>Elige una opción</option>
                                <option value="herramienta">Herramienta</option>
                                <option value="repuesto">Repuesto / recambio</option>
                                <option value="accesorio">Accesorio</option>
                                <option value="equipamiento">Equipamiento</option>
                            </select>
                        </label>
                        <button type="submit">Buscar</button>
                    </div>
                    <p class="seo-dependiente__ask-help">Describe el problema, producto o trabajo y selecciona qué tipo de solución quieres. El Dependiente usará ambas cosas en la misma búsqueda.</p>
                    <div class="seo-dependiente__examples" data-dependiente-examples aria-label="Ejemplos de búsqueda"></div>
                </form>

                <aside class="seo-dependiente__help" data-dependiente-help aria-label="Asistencia personal">
                    <div class="seo-dependiente__help-row">
                        <span class="seo-dependiente__assistant-avatar seo-dependiente__assistant-avatar--help" aria-hidden="true"><span>👤</span></span>
                        <div class="seo-dependiente__help-copy">
                            <strong data-dependiente-help-title>¿No encuentras lo que buscas?</strong>
                            <span data-dependiente-help-text>Podemos revisar tu búsqueda con todo el contexto y responderte por correo.</span>
                        </div>
                        <button type="button" class="seo-dependiente__help-toggle" data-dependiente-help-toggle aria-expanded="false">Pedir ayuda</button>
                    </div>
                    <div class="seo-dependiente__help-panel" data-dependiente-help-panel hidden>
                        <form class="seo-dependiente__help-form" data-dependiente-help-form>
                            <div class="seo-dependiente__help-fields">
                                <label for="<?php echo esc_attr($help_email_id); ?>">
                                    <span>Tu correo</span>
                                    <input id="<?php echo esc_attr($help_email_id); ?>" type="email" name="help_email" autocomplete="email" maxlength="254" required placeholder="tu@correo.es">
                                </label>
                                <label for="<?php echo esc_attr($help_note_id); ?>">
                                    <span>Un detalle adicional <small>(opcional)</small></span>
                                    <textarea id="<?php echo esc_attr($help_note_id); ?>" name="help_note" rows="3" maxlength="1500" placeholder="Si quieres, añade aquí cualquier detalle que no haya quedado claro."></textarea>
                                </label>
                            </div>
                            <label class="seo-dependiente__help-honeypot" aria-hidden="true">Web<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                            <div class="seo-dependiente__help-actions">
                                <p>Usaremos tu correo únicamente para responder a esta solicitud. Enviaremos también la ruta de búsqueda de Dependiente para que no tengas que explicarlo todo de nuevo.</p>
                                <button type="submit" data-dependiente-help-submit>Enviar consulta</button>
                            </div>
                            <div class="seo-dependiente__help-status" data-dependiente-help-status aria-live="polite"></div>
                        </form>
                    </div>
                </aside>

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
                    <div class="seo-dependiente__results-column">
                        <div class="seo-dependiente__active-filters" data-dependiente-active-filters></div>
                        <div class="seo-dependiente__status" data-dependiente-status aria-live="polite"></div>
                        <div class="seo-dependiente__results" data-dependiente-results></div>
                        <aside class="seo-dependiente__feedback" data-dependiente-feedback aria-label="Valoración del Dependiente" hidden></aside>
                        <nav class="seo-dependiente__pagination" data-dependiente-pagination aria-label="Paginación"></nav>
                        <aside class="seo-dependiente__related" data-dependiente-related aria-label="Guías y soluciones relacionadas" hidden></aside>
                        <section class="seo-dependiente__amazon" data-dependiente-amazon aria-label="Opciones relacionadas en Amazon" aria-live="polite" hidden></section>
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

        return self::bundled_action_image_url($filename);
    }

    /**
     * Resuelve las imagenes incluidas del selector inicial.
     *
     * La ubicacion canonica es assets/images, pero instalaciones anteriores
     * guardaron estos ficheros directamente en includes/dependiente/images.
     * Admitimos ambas para no romper produccion ni obligar a migrar Medios.
     */
    public static function bundled_action_image_url($filename) {
        $filename = basename((string) $filename);
        if ('' === $filename) {
            return '';
        }

        $locations = array(
            array(
                'path' => SEO_DEPENDIENTE_PATH . 'assets/images/' . $filename,
                'url'  => SEO_DEPENDIENTE_URL . 'assets/images/' . $filename,
            ),
            array(
                'path' => SEO_DEPENDIENTE_PATH . 'images/' . $filename,
                'url'  => SEO_DEPENDIENTE_URL . 'images/' . $filename,
            ),
        );

        foreach ($locations as $location) {
            if (is_readable($location['path'])) {
                return (string) $location['url'];
            }
        }

        return '';
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

    /**
     * Elimina restos del mecanismo antiguo que podia arrancar trabajo sin una
     * orden manual. Si hay una reindexacion nueva en curso, conserva su evento
     * de respaldo.
     */
    public function cleanup_legacy_background_index() {
        $state = self::reindex_state();
        if ('running' !== (string) ($state['status'] ?? '')) {
            wp_clear_scheduled_hook('seo_dependiente_background_index');
            delete_option('seo_dependiente_background_page');
        }
    }

    private static function fresh_option($name, $default = false) {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete((string) $name, 'options');
        }
        return get_option((string) $name, $default);
    }

    private static function reindex_state_defaults() {
        return array(
            'status'          => 'idle',
            'run_id'          => '',
            'page'            => 1,
            'pages'           => 0,
            'limit'           => 50,
            'total'           => 0,
            'indexed'         => 0,
            'processed'       => 0,
            'percent'         => 0,
            'verified'        => 0,
            'missing'         => 0,
            'extra'           => 0,
            'missing_ids'     => array(),
            'extra_ids'       => array(),
            'published_total' => 0,
            'excluded_hidden' => 0,
            'scan_total'      => 0,
            'started_at'      => '',
            'started_ts'      => 0,
            'heartbeat_at'    => '',
            'heartbeat_ts'    => 0,
            'finished_at'     => '',
            'finished_ts'     => 0,
            'worker_source'   => '',
            'last_error'      => '',
            'last_batch_size' => 0,
        );
    }

    public static function reindex_state() {
        $raw = self::fresh_option('seo_dependiente_reindex_state', array());
        $state = wp_parse_args(is_array($raw) ? $raw : array(), self::reindex_state_defaults());
        $state['status'] = sanitize_key((string) $state['status']);
        if (!in_array($state['status'], array('idle', 'running', 'completed', 'failed', 'stopped'), true)) {
            $state['status'] = 'idle';
        }
        $state['page'] = max(1, absint($state['page']));
        $state['limit'] = min(100, max(10, absint($state['limit'])));
        $state['total'] = absint($state['total']);
        $state['pages'] = absint($state['pages']);
        $state['processed'] = absint($state['processed']);
        $state['verified'] = empty($state['verified']) ? 0 : 1;
        $state['missing'] = absint($state['missing']);
        $state['extra'] = absint($state['extra'] ?? 0);
        $state['missing_ids'] = array_values(array_filter(array_map('absint', (array) ($state['missing_ids'] ?? array()))));
        $state['extra_ids'] = array_values(array_filter(array_map('absint', (array) ($state['extra_ids'] ?? array()))));
        $state['published_total'] = absint($state['published_total'] ?? 0);
        $state['excluded_hidden'] = absint($state['excluded_hidden'] ?? 0);
        $state['scan_total'] = absint($state['scan_total'] ?? 0);

        // El contador real de la tabla es la fuente de verdad para el panel.
        $state['indexed'] = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::count_indexed() : absint($state['indexed']);
        if (!$state['total'] && class_exists('SEO_Dependiente_Index')) {
            $state['total'] = SEO_Dependiente_Index::count_indexable();
        }
        if (!$state['published_total'] && class_exists('SEO_Dependiente_Index')) {
            $state['published_total'] = SEO_Dependiente_Index::count_published();
        }
        if (!$state['excluded_hidden'] && $state['published_total'] >= $state['total']) {
            $state['excluded_hidden'] = max(0, $state['published_total'] - $state['total']);
        }
        if (!$state['scan_total']) {
            $state['scan_total'] = $state['published_total'];
        }
        if (!$state['pages'] && $state['scan_total']) {
            $state['pages'] = (int) ceil($state['scan_total'] / $state['limit']);
        }
        $state['percent'] = $state['total']
            ? min(100, (int) round(($state['indexed'] / $state['total']) * 100))
            : ('completed' === $state['status'] ? 100 : 0);
        if ('completed' === $state['status']) {
            $state['percent'] = 100;
        }
        return $state;
    }

    private static function save_reindex_state($changes) {
        $current = self::fresh_option('seo_dependiente_reindex_state', array());
        $state = wp_parse_args(is_array($changes) ? $changes : array(), is_array($current) ? $current : self::reindex_state_defaults());
        update_option('seo_dependiente_reindex_state', $state, false);
        return self::reindex_state();
    }

    public static function start_reindex() {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('seo_dependiente_woocommerce_required', 'Dependiente necesita WooCommerce activo para reindexar.');
        }

        $existing = self::reindex_state();
        if ('running' === (string) ($existing['status'] ?? '')) {
            // Un segundo clic nunca reinicia ni vacia un proceso que ya esta vivo.
            return $existing;
        }

        wp_clear_scheduled_hook('seo_dependiente_background_index');
        delete_option('seo_dependiente_background_page');
        delete_option('seo_dependiente_last_full_index');
        delete_option('seo_dependiente_reindex_lock');

        if (!SEO_Dependiente_Index::clear()) {
            return new WP_Error('seo_dependiente_index_clear_failed', 'No se pudo vaciar el indice antes de iniciar la reindexacion.');
        }

        $limit = 50;
        $published_total = SEO_Dependiente_Index::count_published();
        $total = SEO_Dependiente_Index::count_indexable();
        $pages = $published_total ? (int) ceil($published_total / $limit) : 0;
        $now = time();
        $state = array(
            'status'          => $total ? 'running' : 'completed',
            'run_id'          => function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('dep_', true),
            'page'            => 1,
            'pages'           => $pages,
            'limit'           => $limit,
            'total'           => $total,
            'indexed'         => 0,
            'processed'       => 0,
            'percent'         => $total ? 0 : 100,
            'verified'        => $total ? 0 : 1,
            'missing'         => 0,
            'extra'           => 0,
            'missing_ids'     => array(),
            'extra_ids'       => array(),
            'published_total' => $published_total,
            'excluded_hidden' => max(0, $published_total - $total),
            'scan_total'      => $published_total,
            'started_at'      => current_time('mysql'),
            'started_ts'      => $now,
            'heartbeat_at'    => current_time('mysql'),
            'heartbeat_ts'    => $now,
            'finished_at'     => $total ? '' : current_time('mysql'),
            'finished_ts'     => $total ? 0 : $now,
            'worker_source'   => 'manual_admin',
            'last_error'      => '',
            'last_batch_size' => 0,
        );
        update_option('seo_dependiente_reindex_state', $state, false);

        if (!$total) {
            update_option('seo_dependiente_last_full_index', current_time('mysql'), false);
            return self::reindex_state();
        }

        self::schedule_reindex_fallback(1);
        if (function_exists('seo_process_supervisor_nudge')) {
            seo_process_supervisor_nudge(0, 'dependiente_index');
        }
        return self::reindex_state();
    }

    public static function stop_reindex($clear_index = false, $reason = 'manual_stop') {
        $state = self::reindex_state();
        if ('running' === (string) ($state['status'] ?? '')) {
            self::save_reindex_state(array(
                'status'        => 'stopped',
                'finished_at'   => current_time('mysql'),
                'finished_ts'   => time(),
                'worker_source' => sanitize_key((string) $reason),
            ));
        }
        wp_clear_scheduled_hook('seo_dependiente_background_index');
        delete_option('seo_dependiente_background_page');

        // Si un lote estaba dentro de index_batch(), esperamos a que libere su
        // lock antes de vaciar/resetear. Asi el lote no puede repoblar el indice
        // despues de que el usuario haya pulsado Vaciar o Reset.
        $deadline = microtime(true) + 45.0;
        while (microtime(true) < $deadline) {
            $lock_value = (string) self::fresh_option('seo_dependiente_reindex_lock', '');
            if ('' === $lock_value) {
                break;
            }
            $parts = explode('|', $lock_value, 2);
            $locked_at = absint($parts[0] ?? 0);
            if ($locked_at && (time() - $locked_at) > 120) {
                delete_option('seo_dependiente_reindex_lock');
                break;
            }
            usleep(150000);
        }

        if ('' !== (string) self::fresh_option('seo_dependiente_reindex_lock', '')) {
            return new WP_Error(
                'seo_dependiente_reindex_still_stopping',
                'La reindexacion se ha detenido, pero un lote todavia esta terminando. Espera unos segundos y vuelve a intentarlo antes de vaciar el indice.'
            );
        }

        if ($clear_index && !SEO_Dependiente_Index::clear()) {
            return new WP_Error('seo_dependiente_index_clear_failed', 'La reindexacion se detuvo, pero no se pudo vaciar el indice.');
        }
        return self::reindex_state();
    }

    private static function acquire_reindex_lock() {
        $key = 'seo_dependiente_reindex_lock';
        $now = time();
        $existing = (string) self::fresh_option($key, '');
        if ($existing) {
            $parts = explode('|', $existing, 2);
            $locked_at = absint($parts[0] ?? 0);
            if ($locked_at && ($now - $locked_at) > 120) {
                delete_option($key);
            } else {
                return '';
            }
        }
        $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('lock_', true);
        return add_option($key, $now . '|' . $token, '', 'no') ? $token : '';
    }

    private static function release_reindex_lock($token) {
        $current = (string) self::fresh_option('seo_dependiente_reindex_lock', '');
        if ($token && false !== strpos($current, '|' . $token)) {
            delete_option('seo_dependiente_reindex_lock');
        }
    }

    private static function schedule_reindex_fallback($delay = 5) {
        $state = self::reindex_state();
        if ('running' !== (string) ($state['status'] ?? '')) {
            return false;
        }
        if (wp_next_scheduled('seo_dependiente_background_index')) {
            return true;
        }
        return (bool) wp_schedule_single_event(time() + max(1, absint($delay)), 'seo_dependiente_background_index');
    }

    private static function finish_reindex_verification($source) {
        $report = SEO_Dependiente_Index::verification_report(20);
        $total = absint($report['indexable'] ?? 0);
        $published = absint($report['published'] ?? 0);
        $indexed = absint($report['indexed'] ?? 0);
        $missing = absint($report['missing'] ?? 0);
        $extra = absint($report['extra'] ?? 0);
        $verified = !empty($report['verified']);
        $missing_ids = array_values(array_filter(array_map('absint', (array) ($report['missing_ids'] ?? array()))));
        $extra_ids = array_values(array_filter(array_map('absint', (array) ($report['extra_ids'] ?? array()))));
        $now = time();

        $error = '';
        if (!$verified) {
            $parts = array('La reindexacion recorrio todos los lotes, pero la cobertura exacta del indice no coincide con los productos indexables.');
            if ($missing) {
                $parts[] = 'Faltan ' . $missing . ' productos' . ($missing_ids ? ' (IDs: ' . implode(', ', $missing_ids) . ')' : '') . '.';
            }
            if ($extra) {
                $parts[] = 'Sobran ' . $extra . ' filas' . ($extra_ids ? ' (IDs: ' . implode(', ', $extra_ids) . ')' : '') . '.';
            }
            $error = implode(' ', $parts);
        }

        $changes = array(
            'status'          => $verified ? 'completed' : 'failed',
            'total'           => $total,
            'published_total' => $published,
            'excluded_hidden' => absint($report['excluded_hidden'] ?? 0),
            'scan_total'      => $published,
            'indexed'         => $indexed,
            'percent'         => $verified ? 100 : ($total ? min(100, (int) round(($indexed / $total) * 100)) : 0),
            'verified'        => $verified ? 1 : 0,
            'missing'         => $missing,
            'extra'           => $extra,
            'missing_ids'     => $missing_ids,
            'extra_ids'       => $extra_ids,
            'finished_at'     => current_time('mysql'),
            'finished_ts'     => $now,
            'heartbeat_at'    => current_time('mysql'),
            'heartbeat_ts'    => $now,
            'worker_source'   => sanitize_key((string) $source),
            'last_error'      => $error,
        );
        $state = self::save_reindex_state($changes);
        wp_clear_scheduled_hook('seo_dependiente_background_index');
        if ($verified) {
            update_option('seo_dependiente_last_full_index', current_time('mysql'), false);
        }
        return $state;
    }

    public static function process_reindex_slice($seconds = 20, $source = 'background') {
        $state = self::reindex_state();
        if ('running' !== (string) ($state['status'] ?? '')) {
            return false;
        }
        if (!class_exists('WooCommerce')) {
            self::save_reindex_state(array(
                'status'      => 'failed',
                'last_error'  => 'WooCommerce no esta disponible.',
                'finished_at' => current_time('mysql'),
                'finished_ts' => time(),
            ));
            return false;
        }

        $lock = self::acquire_reindex_lock();
        if (!$lock) {
            return false;
        }

        $started = microtime(true);
        $seconds = max(5, min(50, absint($seconds)));
        $worked = false;
        try {
            while ((microtime(true) - $started) < $seconds) {
                $state = self::reindex_state();
                if ('running' !== (string) ($state['status'] ?? '')) {
                    break;
                }

                $page = max(1, absint($state['page'] ?? 1));
                $limit = min(100, max(10, absint($state['limit'] ?? 50)));
                $result = SEO_Dependiente_Index::index_batch($page, $limit);
                $worked = true;
                $indexed = SEO_Dependiente_Index::count_indexed();
                $processed = absint($state['processed'] ?? 0) + absint($result['processed'] ?? 0);
                $total = absint($result['indexable_total'] ?? $state['total'] ?? 0);
                $scan_total = absint($result['scan_total'] ?? $state['scan_total'] ?? 0);
                $pages = absint($result['pages'] ?? $state['pages'] ?? 0);

                $updated = self::save_reindex_state(array(
                    'page'            => !empty($result['done']) ? $page : $page + 1,
                    'pages'           => $pages,
                    'total'           => $total,
                    'published_total' => $scan_total,
                    'excluded_hidden' => max(0, $scan_total - $total),
                    'scan_total'      => $scan_total,
                    'indexed'         => $indexed,
                    'processed'       => $processed,
                    'percent'         => $total ? min(100, (int) round(($indexed / $total) * 100)) : 0,
                    'heartbeat_at'    => current_time('mysql'),
                    'heartbeat_ts'    => time(),
                    'worker_source'   => sanitize_key((string) $source),
                    'last_batch_size' => absint($result['processed'] ?? 0),
                    'last_error'      => '',
                ));

                // Un Vaciar/Reset concurrente puede haber cambiado el estado a
                // stopped mientras este lote estaba procesando. No lo revivimos.
                if ('running' !== (string) ($updated['status'] ?? '')) {
                    break;
                }
                if (!empty($result['done'])) {
                    self::finish_reindex_verification($source);
                    break;
                }
            }
        } catch (Throwable $error) {
            $fresh_on_error = self::reindex_state();
            if ('running' === (string) ($fresh_on_error['status'] ?? '')) {
                self::save_reindex_state(array(
                    'status'        => 'failed',
                    'last_error'    => $error->getMessage(),
                    'finished_at'   => current_time('mysql'),
                    'finished_ts'   => time(),
                    'worker_source' => sanitize_key((string) $source),
                ));
            }
        } finally {
            self::release_reindex_lock($lock);
        }

        $fresh = self::reindex_state();
        if ('running' === (string) ($fresh['status'] ?? '')) {
            self::schedule_reindex_fallback(5);
            if (function_exists('seo_process_supervisor_nudge')) {
                seo_process_supervisor_nudge(0, 'dependiente_index');
            }
        }
        return $worked;
    }

    public function run_background_index() {
        // Un evento antiguo o espurio no puede crear una reindexacion: la funcion
        // sale inmediatamente si no existe estado manual en running.
        self::process_reindex_slice(20, 'wp_cron');
    }

    public static function supervisor_has_pending_reindex($pending) {
        $state = self::reindex_state();
        return $pending || ('running' === (string) ($state['status'] ?? ''));
    }

    public static function supervisor_add_reindex_target($targets, $settings = array(), $source = '') {
        unset($settings, $source);
        $targets = is_array($targets) ? $targets : array();
        $state = self::reindex_state();
        if ('running' !== (string) ($state['status'] ?? '')) {
            return $targets;
        }
        foreach ($targets as $target) {
            if ('dependiente-index' === (string) ($target['type'] ?? '')) {
                return $targets;
            }
        }
        $targets[] = array(
            'type'     => 'dependiente-index',
            'data'     => array(),
            'callback' => array(__CLASS__, 'process_reindex_manager_target'),
        );
        return $targets;
    }

    public static function process_reindex_manager_target($budget, $source = 'manager', $target = array()) {
        unset($target);
        return self::process_reindex_slice($budget, $source);
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
