<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_Admin {
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('admin_post_seo_dependiente_save', array(__CLASS__, 'save_options'));
        add_action('wp_ajax_seo_dependiente_reindex', array(__CLASS__, 'ajax_reindex'));
        add_action('wp_ajax_seo_dependiente_clear', array(__CLASS__, 'ajax_clear'));
    }

    public static function enqueue($hook) {
        if (false === strpos((string) $hook, 'seo-dependiente')) {
            return;
        }
        wp_enqueue_media();

        wp_enqueue_style(
            'seo-dependiente',
            SEO_DEPENDIENTE_URL . 'assets/css/seo-dependiente.css',
            array(),
            SEO_DEPENDIENTE_VERSION
        );

        wp_enqueue_script(
            'seo-dependiente-admin',
            SEO_DEPENDIENTE_URL . 'assets/js/seo-dependiente-admin.js',
            array(),
            SEO_DEPENDIENTE_VERSION,
            true
        );
        wp_localize_script('seo-dependiente-admin', 'SEODependienteAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('seo_dependiente_admin'),
        ));
    }

    public static function render_page() {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-taxonomy'));
        }

        $status = SEO_Dependiente_Index::status();
        $options = get_option('seo_dependiente_options', array());
        $page_id = absint($status['page_id']);
        $page_url = $page_id ? get_permalink($page_id) : '';
        $indexed_percentage = $status['published'] ? min(100, round(($status['indexed'] / $status['published']) * 100)) : 0;
        global $wpdb;
        $integrations = array(
            'WooCommerce'                     => class_exists('WooCommerce'),
            'Vocabulario semántico SEO'       => SEO_Dependiente_Index::table_exists($wpdb->prefix . 'seo_vocabulary') && SEO_Dependiente_Index::table_exists($wpdb->prefix . 'seo_object_vocabulary'),
            'Semántica de consultas Dependiente'=> class_exists('SEO_Dependiente_Semantics') && SEO_Dependiente_Semantics::table_exists(),
            'Asistencia humana por correo'     => class_exists('SEO_Dependiente_Help') && SEO_Dependiente_Help::table_exists(),
            'Atributos SEO'                   => SEO_Dependiente_Index::table_exists($wpdb->prefix . 'seo_attributes'),
            'Comparativas por categoría'      => SEO_Dependiente_Index::table_exists($wpdb->prefix . 'seo_category_comparisons'),
            'Catálogo intermedio de proveedor'=> SEO_Dependiente_Index::table_exists($wpdb->prefix . 'seo_proveedores_productos'),
        );
        ?>
        <div class="wrap seo-dependiente-admin">
            <h1>Dependiente</h1>
            <p class="description">Piloto de búsqueda guiada y comparación de productos para WooCommerce.</p>

            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>
            <?php endif; ?>
            <div class="seo-dependiente-admin__grid">
                <div>
                    <div class="postbox seo-dependiente-admin__box">
                        <h2 class="seo-dependiente-admin__box-title">Estado del catálogo</h2>
                        <p><strong data-dependiente-indexed><?php echo esc_html(number_format_i18n($status['indexed'])); ?></strong> de <strong data-dependiente-total><?php echo esc_html(number_format_i18n($status['published'])); ?></strong> productos publicados están indexados.</p>
                        <div class="seo-dependiente-admin__progress">
                            <div class="seo-dependiente-admin__progress-bar" data-dependiente-progress-bar data-initial-percent="<?php echo esc_attr($indexed_percentage); ?>"></div>
                        </div>
                        <p data-dependiente-progress-text><?php echo esc_html($indexed_percentage); ?>% completado<?php echo $status['last_full'] ? ' · Último índice completo: ' . esc_html($status['last_full']) : ''; ?></p>
                        <p>
                            <button type="button" class="button button-primary" data-dependiente-reindex>Reindexar catálogo completo</button>
                            <button type="button" class="button" data-dependiente-clear>Vaciar índice</button>
                        </p>
                        <p class="description">El índice se actualiza también al guardar cada producto. La reindexación completa recoge cambios masivos en términos, vocabulario o atributos.</p>
                    </div>

                    <form class="postbox seo-dependiente-admin__box" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="seo_dependiente_save">
                        <?php wp_nonce_field('seo_dependiente_save'); ?>
                        <h2 class="seo-dependiente-admin__box-title">Configuración del piloto</h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="dht-results-per-page">Resultados por página</label></th>
                                <td><input id="dht-results-per-page" type="number" min="6" max="48" name="results_per_page" value="<?php echo esc_attr(absint($options['results_per_page'] ?? 18)); ?>" class="small-text"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dht-menu-cards">Tarjetas visuales por bloque</label></th>
                                <td><input id="dht-menu-cards" type="number" min="4" max="12" name="menu_cards" value="<?php echo esc_attr(absint($options['menu_cards'] ?? 8)); ?>" class="small-text"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dht-help-email">Correo para consultas no resueltas</label></th>
                                <td>
                                    <input id="dht-help-email" type="email" name="help_email" value="<?php echo esc_attr((string) ($options['help_email'] ?? get_option('admin_email', ''))); ?>" class="regular-text" autocomplete="email">
                                    <p class="description">Recibe las solicitudes de ayuda de Dependiente junto con la consulta, el enrutado semántico, las aclaraciones, interacciones y resultados mostrados. Si queda vacío o no es válido, se usará el correo de administración de WordPress.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Imágenes de las cuatro acciones</th>
                                <td>
                                    <div class="seo-dependiente-admin__media-grid">
                                        <?php self::render_media_field('action_image_need', 'Arreglar o hacer algo', $options, 'dependiente-arreglar-algo.webp'); ?>
                                        <?php self::render_media_field('action_image_product', 'Buscar un producto', $options, 'dependiente-buscar-herramienta.webp'); ?>
                                        <?php self::render_media_field('action_image_tool', 'Elegir una herramienta', $options, 'dependiente-necesito-herramienta.webp'); ?>
                                        <?php self::render_media_field('action_image_compare', 'Comparar opciones', $options, 'dependiente-comparar-herramientas.webp'); ?>
                                    </div>
                                    <p class="description">Puedes usar las imágenes incluidas en el módulo o subir las tuyas a Medios y seleccionarlas aquí. El texto siempre se muestra separado de la imagen para mantener la legibilidad.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="dht-custom-meta">Metadatos comerciales adicionales</label></th>
                                <td>
                                    <textarea id="dht-custom-meta" name="custom_meta_keys" rows="4" class="large-text code"><?php echo esc_textarea((string) ($options['custom_meta_keys'] ?? '')); ?></textarea>
                                    <p class="description">Claves separadas por comas. Solo se usan para búsqueda; no se muestran directamente al cliente.</p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button('Guardar configuración'); ?>
                    </form>
                </div>

                <div>
                    <div class="postbox seo-dependiente-admin__box">
                        <h2 class="seo-dependiente-admin__box-title">Página pública</h2>
                        <?php if ($page_id && $page_url) : ?>
                            <p><strong>Dependiente</strong><br><code>[dependiente_productos]</code></p>
                            <p><a class="button button-primary" href="<?php echo esc_url($page_url); ?>" target="_blank" rel="noopener">Abrir piloto</a> <a class="button" href="<?php echo esc_url(get_edit_post_link($page_id)); ?>">Editar página</a></p>
                            <p class="description">La presencia de Dependiente en el menú principal se controla desde el generador SEO Menu Structure, mediante la casilla “Incluir Dependiente”.</p>
                        <?php else : ?>
                            <p>No se ha podido crear la página automáticamente. Crea una página y añade el shortcode <code>[dependiente_productos]</code>.</p>
                        <?php endif; ?>
                    </div>

                    <div class="postbox seo-dependiente-admin__box">
                        <h2 class="seo-dependiente-admin__box-title">Imágenes de navegación</h2>
                        <p><strong>Las cuatro entradas principales usan imágenes fijas y reconocibles.</strong> Se configuran arriba desde la Biblioteca de Medios.</p>
                        <p class="description">Las tarjetas dinámicas de exploración siguen resolviendo sus imágenes automáticamente desde categorías y productos relacionados. No necesitas asociar imágenes manualmente a etiquetas o atributos.</p>
                    </div>

                    <div class="postbox seo-dependiente-admin__box">
                        <h2 class="seo-dependiente-admin__box-title">Fuentes de datos detectadas</h2>
                        <ul>
                            <?php foreach ($integrations as $label => $active) : ?>
                                <li class="seo-dependiente-admin__integration <?php echo $active ? 'is-active' : 'is-inactive'; ?>"><span class="seo-dependiente-admin__integration-icon" aria-hidden="true"><?php echo $active ? '✓' : '–'; ?></span><?php echo esc_html($label); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="description">El buscador funciona con WooCommerce. Las fuentes SEO añaden contexto de aplicación, plataforma, subtipo, atributos técnicos y criterios de comparación.</p>
                    </div>

                    <div class="postbox seo-dependiente-admin__box">
                        <h2 class="seo-dependiente-admin__box-title">Lógica del dependiente</h2>
                        <ol class="seo-dependiente-admin__steps">
                            <li>Interpreta la necesidad escrita por el cliente.</li>
                            <li>Busca en todos los campos comerciales útiles.</li>
                            <li>Explica por qué encaja cada producto.</li>
                            <li>Permite afinar por filtros técnicos.</li>
                            <li>Compara hasta cuatro opciones y prioriza criterios de compra de la categoría.</li>
                            <li>Si la búsqueda no resuelve la necesidad, ofrece asistencia humana y envía el recorrido de búsqueda para poder responder con contexto.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function save_options() {
        if (!current_user_can(self::capability())) {
            wp_die('Permisos insuficientes.');
        }
        check_admin_referer('seo_dependiente_save');

        $help_email = sanitize_email((string) wp_unslash($_POST['help_email'] ?? ''));
        if (!$help_email || !is_email($help_email)) {
            $help_email = sanitize_email((string) get_option('admin_email', ''));
        }

        $options = array(
            'results_per_page' => min(48, max(6, absint($_POST['results_per_page'] ?? 18))),
            'menu_cards'       => min(12, max(4, absint($_POST['menu_cards'] ?? 8))),
            'help_email'       => $help_email,
            'custom_meta_keys' => self::sanitize_meta_keys($_POST['custom_meta_keys'] ?? ''),
            'action_image_need'    => absint($_POST['action_image_need'] ?? 0),
            'action_image_product' => absint($_POST['action_image_product'] ?? 0),
            'action_image_tool'    => absint($_POST['action_image_tool'] ?? 0),
            'action_image_compare' => absint($_POST['action_image_compare'] ?? 0),
        );
        update_option('seo_dependiente_options', $options, false);
        wp_safe_redirect(add_query_arg(array('page' => 'seo-dependiente', 'updated' => 1), admin_url('admin.php')));
        exit;
    }

    public static function ajax_reindex() {
        if (!current_user_can(self::capability())) {
            wp_send_json_error(array('message' => 'Permisos insuficientes.'), 403);
        }
        check_ajax_referer('seo_dependiente_admin', 'nonce');

        $page = max(1, absint($_POST['page'] ?? 1));
        $reset = !empty($_POST['reset']);
        if ($reset) {
            SEO_Dependiente_Index::clear();
            $page = 1;
        }
        $result = SEO_Dependiente_Index::index_batch($page, 50);
        if (!empty($result['done'])) {
            update_option('seo_dependiente_last_full_index', current_time('mysql'), false);
            delete_option('seo_dependiente_background_page');
        }
        $result['indexed'] = SEO_Dependiente_Index::count_indexed();
        wp_send_json_success($result);
    }

    public static function ajax_clear() {
        if (!current_user_can(self::capability())) {
            wp_send_json_error(array('message' => 'Permisos insuficientes.'), 403);
        }
        check_ajax_referer('seo_dependiente_admin', 'nonce');
        SEO_Dependiente_Index::clear();
        wp_send_json_success(array('indexed' => 0));
    }

    private static function render_media_field($key, $label, $options, $fallback_filename) {
        $attachment_id = absint($options[$key] ?? 0);
        $fallback = method_exists('SEO_Dependiente_Plugin', 'bundled_action_image_url')
            ? SEO_Dependiente_Plugin::bundled_action_image_url($fallback_filename)
            : '';
        $preview = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'medium') : '';
        if (!$preview) {
            $preview = $fallback;
        }
        ?>
        <div class="seo-dependiente-admin__media-field" data-dependiente-media-field>
            <strong><?php echo esc_html($label); ?></strong>
            <div class="seo-dependiente-admin__media-preview">
                <img src="<?php echo esc_url($preview); ?>" alt="" data-fallback-src="<?php echo esc_url($fallback); ?>" data-dependiente-media-preview>
            </div>
            <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($attachment_id); ?>" data-dependiente-media-id>
            <div class="seo-dependiente-admin__media-actions">
                <button type="button" class="button" data-dependiente-media-select>Elegir en Medios</button>
                <button type="button" class="button-link-delete" data-dependiente-media-clear>Usar imagen incluida</button>
            </div>
        </div>
        <?php
    }

    private static function sanitize_meta_keys($value) {
        $keys = array_values(array_unique(array_filter(array_map('sanitize_key', explode(',', wp_unslash((string) $value))))));
        return implode(',', $keys);
    }

    private static function capability() {
        return class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
    }
}
