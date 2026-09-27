<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_Admin {
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('admin_post_seo_dependiente_save', array(__CLASS__, 'save_options'));
        add_action('admin_post_seo_dependiente_export_diagnostic', array(__CLASS__, 'export_diagnostic_json'));
        add_action('admin_post_seo_dependiente_learning_review', array(__CLASS__, 'review_learning_candidate'));
        add_action('wp_ajax_seo_dependiente_reindex', array(__CLASS__, 'ajax_reindex'));
        add_action('wp_ajax_seo_dependiente_reindex_status', array(__CLASS__, 'ajax_reindex_status'));
        add_action('wp_ajax_seo_dependiente_clear', array(__CLASS__, 'ajax_clear'));
        add_action('wp_ajax_seo_dependiente_reset_knowledge', array(__CLASS__, 'ajax_reset_knowledge'));
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

        $tab = sanitize_key((string) ($_GET['tab'] ?? 'settings'));
        if (!in_array($tab, array('settings', 'diagnostic', 'learning', 'trainer', 'interpreter', 'researcher', 'knowledge', 'auditor'), true)) {
            $tab = 'settings';
        }
        ?>
        <div class="wrap seo-dependiente-admin">
            <h1>Dependiente</h1>
            <p class="description">Búsqueda guiada, aprendizaje supervisado e informe de lo que buscan los clientes y de cómo responde el catálogo.</p>

            <nav class="nav-tab-wrapper seo-dependiente-admin__tabs" aria-label="Secciones de Dependiente">
                <?php self::render_tab_link('settings', 'Configuración', $tab); ?>
                <?php self::render_tab_link('diagnostic', 'Informe', $tab); ?>
                <?php self::render_tab_link('learning', 'Aprendizaje', $tab); ?>
                <?php self::render_tab_link('trainer', 'Academia', $tab); ?>
                <?php self::render_tab_link('interpreter', 'Intérprete', $tab); ?>
                <?php self::render_tab_link('researcher', 'Investigador', $tab); ?>
                <?php self::render_tab_link('knowledge', 'Conocimiento', $tab); ?>
                <?php self::render_tab_link('auditor', 'Auditor', $tab); ?>
            </nav>

            <?php
            if ('diagnostic' === $tab) {
                self::render_diagnostic_tab();
            } elseif ('learning' === $tab) {
                self::render_learning_tab();
            } elseif ('trainer' === $tab) {
                if (class_exists('SEO_Dependiente_Entrenador')) {
                    SEO_Dependiente_Entrenador::render_tab();
                } else {
                    echo '<div class="notice notice-error"><p>No está disponible el módulo Academia.</p></div>';
                }
            } elseif ('interpreter' === $tab) {
                if (class_exists('SEO_Dependiente_Interprete')) {
                    SEO_Dependiente_Interprete::render_tab();
                } else {
                    echo '<div class="notice notice-error"><p>No está disponible el módulo Intérprete.</p></div>';
                }
            } elseif ('researcher' === $tab) {
                if (class_exists('SEO_Investigador_Admin')) {
                    SEO_Investigador_Admin::render_tab();
                } else {
                    echo '<div class="notice notice-error"><p>No está disponible el módulo Investigador.</p></div>';
                }
            } elseif ('knowledge' === $tab) {
                if (class_exists('SEO_Dependiente_Knowledge_Transfer')) {
                    SEO_Dependiente_Knowledge_Transfer::render_tab();
                } else {
                    echo '<div class="notice notice-error"><p>No está disponible el módulo de portabilidad del conocimiento.</p></div>';
                }
            } elseif ('auditor' === $tab) {
                if (class_exists('SEO_Auditor')) {
                    SEO_Auditor::render_tab();
                } else {
                    echo '<div class="notice notice-error"><p>No está disponible el módulo Auditor.</p></div>';
                }
            } else {
                self::render_settings_tab();
            }
            ?>
        </div>
        <?php
    }

    private static function render_settings_tab() {
        $status = SEO_Dependiente_Index::status();
        $options = get_option('seo_dependiente_options', array());
        $page_id = absint($status['page_id']);
        $page_url = $page_id ? get_permalink($page_id) : '';
        $indexable_total = absint($status['indexable'] ?? $status['published'] ?? 0);
        $excluded_hidden = absint($status['excluded_hidden'] ?? 0);
        $indexed_percentage = $indexable_total ? min(100, round(($status['indexed'] / $indexable_total) * 100)) : 0;
        global $wpdb;
        $integrations = array(
            'WooCommerce'                       => class_exists('WooCommerce'),
            'Vocabulario semántico SEO'         => SEO_Dependiente_Index::table_exists($wpdb->prefix . 'seo_vocabulary') && SEO_Dependiente_Index::table_exists($wpdb->prefix . 'seo_object_vocabulary'),
            'Semántica de consultas Dependiente'=> class_exists('SEO_Dependiente_Semantics') && SEO_Dependiente_Semantics::table_exists(),
            'Registro de búsquedas'             => class_exists('SEO_Dependiente_Search_Log') && SEO_Dependiente_Search_Log::table_exists(),
            'Asistencia humana por correo'      => class_exists('SEO_Dependiente_Help') && SEO_Dependiente_Help::table_exists(),
            'Atributos SEO'                     => SEO_Dependiente_Index::table_exists($wpdb->prefix . 'seo_attributes'),
            'Comparativas por categoría'        => SEO_Dependiente_Index::table_exists($wpdb->prefix . 'seo_category_comparisons'),
            'Catálogo intermedio de proveedor'  => SEO_Dependiente_Index::table_exists($wpdb->prefix . 'seo_proveedores_productos'),
        );

        if (isset($_GET['updated'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>
        <?php endif; ?>

        <div class="seo-dependiente-admin__grid">
            <div>
                <div class="postbox seo-dependiente-admin__box">
                    <h2 class="seo-dependiente-admin__box-title">Estado del catálogo</h2>
                    <p><strong data-dependiente-indexed><?php echo esc_html(number_format_i18n($status['indexed'])); ?></strong> de <strong data-dependiente-total><?php echo esc_html(number_format_i18n($indexable_total)); ?></strong> productos indexables están indexados.</p>
                    <?php if ($excluded_hidden > 0) : ?>
                        <p class="description"><strong><?php echo esc_html(number_format_i18n($excluded_hidden)); ?></strong> producto(s) publicado(s) con visibilidad WooCommerce "Oculto" se excluyen correctamente del índice.</p>
                    <?php endif; ?>
                    <div class="seo-dependiente-admin__progress">
                        <div class="seo-dependiente-admin__progress-bar" data-dependiente-progress-bar data-initial-percent="<?php echo esc_attr($indexed_percentage); ?>"></div>
                    </div>
                    <p data-dependiente-progress-text><?php echo esc_html($indexed_percentage); ?>% completado<?php echo $status['last_full'] ? ' · Último índice completo: ' . esc_html($status['last_full']) : ''; ?></p>
                    <p>
                        <button type="button" class="button button-primary" data-dependiente-reindex>Reindexar catálogo completo</button>
                        <button type="button" class="button" data-dependiente-clear>Vaciar índice</button>
                    </p>
                    <p class="description">El índice se actualiza también al guardar cada producto. La reindexación completa se ejecuta por lotes en segundo plano: puedes cerrar esta pantalla y el Gestor de procesos continuará una reindexación que hayas iniciado manualmente.</p>
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