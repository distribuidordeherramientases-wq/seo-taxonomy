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
            'Atributos canónicos SEO'         => function_exists('seo_attributes_tables') && !array_filter(seo_attributes_tables(), static function ($table) { return !SEO_Dependiente_Index::table_exists($table); }),
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
            <div class="seo-dependiente-admin__grid" style="display:grid;grid-template-columns:minmax(0,1.4fr) minmax(300px,.8fr);gap:20px;max-width:1200px;margin-top:20px;">
                <div>
                    <div class="postbox" style="padding:20px;">
                        <h2 style="margin-top:0;">Estado del catálogo</h2>
                        <p><strong data-dependiente-indexed><?php echo esc_html(number_format_i18n($status['indexed'])); ?></strong> de <strong data-dependiente-total><?php echo esc_html(number_format_i18n($status['published'])); ?></strong> productos publicados están indexados.</p>
                        <div style="height:12px;border-radius:999px;background:#e5e7eb;overflow:hidden;margin:14px 0;">
                            <div data-dependiente-progress-bar style="height:100%;width:<?php echo esc_attr($indexed_percentage); ?>%;background:#111827;transition:width .2s ease;"></div>
                        </div>
                        <p data-dependiente-progress-text><?php echo esc_html($indexed_percentage); ?>% completado<?php echo $status['last_full'] ? ' · Último índice completo: ' . esc_html($status['last_full']) : ''; ?></p>
                        <p>
                            <button type="button" class="button button-primary" data-dependiente-reindex>Reindexar catálogo completo</button>
                            <button type="button" class="button" data-dependiente-clear>Vaciar índice</button>
                        </p>
                        <p class="description">El índice se actualiza también al guardar cada producto. La reindexación completa recoge cambios masivos en términos, vocabulario o atributos.</p>
                    </div>

                    <form class="postbox" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="padding:20px;">
                        <input type="hidden" name="action" value="seo_dependiente_save">
                        <?php wp_nonce_field('seo_dependiente_save'); ?>
                        <h2 style="margin-top:0;">Configuración del piloto</h2>
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
                    <div class="postbox" style="padding:20px;">
                        <h2 style="margin-top:0;">Página pública</h2>
                        <?php if ($page_id && $page_url) : ?>
                            <p><strong>Dependiente</strong><br><code>[dependiente_productos]</code></p>
                            <p><a class="button button-primary" href="<?php echo esc_url($page_url); ?>" target="_blank" rel="noopener">Abrir piloto</a> <a class="button" href="<?php echo esc_url(get_edit_post_link($page_id)); ?>">Editar página</a></p>
                            <p class="description">La presencia de Dependiente en el menú principal se controla desde el generador SEO Menu Structure, mediante la casilla “Incluir Dependiente”.</p>
                        <?php else : ?>
                            <p>No se ha podido crear la página automáticamente. Crea una página y añade el shortcode <code>[dependiente_productos]</code>.</p>
                        <?php endif; ?>
                    </div>

                    <div class="postbox" style="padding:20px;">
                        <h2 style="margin-top:0;">Imágenes de navegación</h2>
                        <p>Dependiente puede usar imágenes ligeras de la Biblioteca de Medios para que las tarjetas se reconozcan de un vistazo.</p>
                        <p><strong>Solo tienes que subir los WebP sin cambiarles el nombre.</strong> El sistema detecta automáticamente archivos con el patrón <code>seo-dependiente-{slug}.webp</code>.</p>
                        <p class="description">Ejemplos: <code>seo-dependiente-cortar.webp</code>, <code>seo-dependiente-taladro.webp</code> o <code>seo-dependiente-porcelanico.webp</code>. Si no existe una imagen específica, se conserva la imagen de categoría o de producto que ya utilizaba el buscador.</p>
                    </div>

                    <div class="postbox" style="padding:20px;">
                        <h2 style="margin-top:0;">Fuentes de datos detectadas</h2>
                        <ul>
                            <?php foreach ($integrations as $label => $active) : ?>
                                <li style="display:flex;gap:8px;align-items:center;margin:10px 0;"><span aria-hidden="true" style="display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;background:<?php echo $active ? '#dcfce7' : '#f3f4f6'; ?>;color:<?php echo $active ? '#166534' : '#6b7280'; ?>;"><?php echo $active ? '✓' : '–'; ?></span><?php echo esc_html($label); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="description">El buscador funciona con WooCommerce. Las fuentes SEO añaden contexto de aplicación, plataforma, subtipo, atributos técnicos y criterios de comparación.</p>
                    </div>

                    <div class="postbox" style="padding:20px;">
                        <h2 style="margin-top:0;">Lógica del dependiente</h2>
                        <ol style="padding-left:20px;">
                            <li>Interpreta la necesidad escrita por el cliente.</li>
                            <li>Busca en todos los campos comerciales útiles.</li>
                            <li>Explica por qué encaja cada producto.</li>
                            <li>Permite afinar por filtros técnicos.</li>
                            <li>Compara hasta cuatro opciones y prioriza criterios de compra de la categoría.</li>
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

        $options = array(
            'results_per_page' => min(48, max(6, absint($_POST['results_per_page'] ?? 18))),
            'menu_cards'       => min(12, max(4, absint($_POST['menu_cards'] ?? 8))),
            'custom_meta_keys' => self::sanitize_meta_keys($_POST['custom_meta_keys'] ?? ''),
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

    private static function sanitize_meta_keys($value) {
        $keys = array_values(array_unique(array_filter(array_map('sanitize_key', explode(',', wp_unslash((string) $value))))));
        return implode(',', $keys);
    }

    private static function capability() {
        return class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
    }
}
