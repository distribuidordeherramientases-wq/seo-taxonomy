<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_Admin {
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('admin_post_seo_dependiente_save', array(__CLASS__, 'save_options'));
        add_action('admin_post_seo_dependiente_export_diagnostic', array(__CLASS__, 'export_diagnostic_json'));
        add_action('admin_post_seo_dependiente_learning_review', array(__CLASS__, 'review_learning_candidate'));
        add_action('wp_ajax_seo_dependiente_reindex', array(__CLASS__, 'ajax_reindex'));
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
        if (!in_array($tab, array('settings', 'diagnostic', 'learning', 'trainer'), true)) {
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
                <?php self::render_tab_link('trainer', 'Entrenador', $tab); ?>
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
                    echo '<div class="notice notice-error"><p>No está disponible el módulo Entrenador.</p></div>';
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
        $indexed_percentage = $status['published'] ? min(100, round(($status['indexed'] / $status['published']) * 100)) : 0;
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
                    <h2 class="seo-dependiente-admin__box-title">Lógica del Dependiente</h2>
                    <ol class="seo-dependiente-admin__steps">
                        <li>Interpreta la necesidad escrita por el cliente.</li>
                        <li>Busca en todos los campos comerciales útiles y en el vocabulario semántico.</li>
                        <li>Explica por qué encaja cada producto.</li>
                        <li>Permite afinar por filtros técnicos.</li>
                        <li>Compara hasta cuatro opciones y prioriza criterios de compra de la categoría.</li>
                        <li>Registra búsquedas, resultados ofrecidos, clics, aclaraciones y reformulaciones para aprendizaje supervisado.</li>
                        <li>Si la búsqueda no resuelve la necesidad, ofrece asistencia humana y envía el recorrido de búsqueda para poder responder con contexto.</li>
                    </ol>
                </div>
            </div>
        </div>

        <?php self::render_danger_zone(); ?>
        <?php
    }

    private static function render_diagnostic_tab() {
        if (!class_exists('SEO_Dependiente_Insights')) {
            echo '<div class="notice notice-error"><p>No está disponible el módulo de diagnóstico.</p></div>';
            return;
        }
        $days = SEO_Dependiente_Insights::normalize_days($_GET['days'] ?? 7);
        $snapshot = SEO_Dependiente_Insights::snapshot($days, 80);
        $summary = (array) ($snapshot['summary'] ?? array());

        $export_url = wp_nonce_url(
            add_query_arg(array(
                'action' => 'seo_dependiente_export_diagnostic',
                'days'   => $days,
            ), admin_url('admin-post.php')),
            'seo_dependiente_export_diagnostic'
        );
        ?>
        <div class="seo-dependiente-admin__toolbar-row">
            <div class="seo-dependiente-admin__periods" aria-label="Periodo del diagnóstico">
                <?php foreach (SEO_Dependiente_Insights::allowed_periods() as $period) : ?>
                    <a class="button <?php echo $period === $days ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg(array('page' => 'seo-dependiente', 'tab' => 'diagnostic', 'days' => $period), admin_url('admin.php'))); ?>"><?php echo esc_html($period); ?> día<?php echo 1 === $period ? '' : 's'; ?></a>
                <?php endforeach; ?>
            </div>
            <a class="button button-primary" href="<?php echo esc_url($export_url); ?>">Descargar informe JSON</a>
        </div>

        <div class="seo-dependiente-admin__metrics">
            <?php self::metric('Búsquedas escritas', $summary['primary_searches'] ?? 0); ?>
            <?php self::metric('Rutas semánticas', $summary['semantic_routes'] ?? 0); ?>
            <?php self::metric('Solo anclaje de objeto', $summary['object_anchor'] ?? 0); ?>
            <?php self::metric('Fallback amplio', $summary['broad_fallback'] ?? 0, !empty($summary['broad_fallback']) ? 'warning' : ''); ?>
            <?php self::metric('Sin resultados', $summary['zero_results'] ?? 0, !empty($summary['zero_results']) ? 'warning' : ''); ?>
            <?php self::metric('Con términos sin resolver', $summary['unresolved_searches'] ?? 0); ?>
            <?php self::metric('Aclaraciones respondidas', $summary['clarifications_answered'] ?? 0); ?>
            <?php self::metric('Clics en producto', $summary['product_clicks'] ?? 0); ?>
            <?php self::metric('Reglas activas', $summary['semantic_rules_active'] ?? 0); ?>
            <?php self::metric('Candidatos de aprendizaje', $summary['learned_candidates'] ?? 0); ?>
        </div>

        <div class="seo-dependiente-admin__diagnostic-grid">
            <?php self::render_top_box('Consultas más repetidas', $snapshot['top']['queries'] ?? array()); ?>
            <?php self::render_top_box('Intenciones detectadas', $snapshot['top']['intents'] ?? array()); ?>
            <?php self::render_top_box('Objetos detectados', $snapshot['top']['objects'] ?? array()); ?>
            <?php self::render_top_box('Contextos detectados', $snapshot['top']['contexts'] ?? array()); ?>
            <?php self::render_top_box('Estrategias de búsqueda', $snapshot['top']['strategies'] ?? array()); ?>
        </div>

        <section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">
            <h2 class="seo-dependiente-admin__box-title">Términos todavía no resueltos</h2>
            <p class="description">Se agregan desde los logs. No se convierten automáticamente en reglas.</p>
            <?php self::render_unresolved_table($snapshot['unresolved_terms'] ?? array()); ?>
        </section>

        <section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">
            <h2 class="seo-dependiente-admin__box-title">Vigilancia de cobertura de catálogo</h2>
            <p class="description">Señales heurísticas: el Dependiente entendió algo de la consulta, pero no encontró una ruta semántica clara o tuvo que recurrir a fallback. No significa automáticamente “producto fuera de catálogo”.</p>
            <?php self::render_coverage_table($snapshot['coverage_watch'] ?? array()); ?>
        </section>

        <section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">
            <h2 class="seo-dependiente-admin__box-title">Productos que más está ofreciendo Dependiente</h2>
            <p class="description">Cuenta cuántas veces aparece cada producto entre los primeros resultados del periodo y cuántos clics recibe.</p>
            <?php self::render_offered_products_table($snapshot['top']['offered_products'] ?? array()); ?>
        </section>

        <section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">
            <h2 class="seo-dependiente-admin__box-title">Estado de la semántica</h2>
            <p class="description">Resumen de reglas activas e hipótesis aprendidas en <code>wp_seo_dependiente_semantics</code>.</p>
            <?php self::render_semantics_overview($snapshot['semantics'] ?? array()); ?>
        </section>

        <section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">
            <h2 class="seo-dependiente-admin__box-title">Qué busca la gente y qué le ofrecemos</h2>
            <?php self::render_recent_table($snapshot['recent_searches'] ?? array()); ?>
        </section>
        <?php
    }

    private static function render_learning_tab() {
        if (!class_exists('SEO_Dependiente_Insights')) {
            echo '<div class="notice notice-error"><p>No está disponible el módulo de aprendizaje.</p></div>';
            return;
        }

        $learning = SEO_Dependiente_Insights::learning_rules();
        if (isset($_GET['reviewed'])) {
            $message = 'approved' === $_GET['reviewed'] ? 'Candidato aprobado y activado.' : ('rejected' === $_GET['reviewed'] ? 'Candidato rechazado.' : 'Revisión guardada.');
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
        if (isset($_GET['review_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>No se pudo completar la revisión del candidato.</p></div>';
        }
        ?>
        <div class="seo-dependiente-admin__metrics seo-dependiente-admin__metrics--small">
            <?php self::metric('Pendientes', count($learning['candidates'] ?? array())); ?>
            <?php self::metric('Aprendidas activas', count($learning['active'] ?? array())); ?>
            <?php self::metric('Rechazadas', count($learning['rejected'] ?? array())); ?>
        </div>

        <section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">
            <h2 class="seo-dependiente-admin__box-title">Candidatos pendientes</h2>
            <p class="description">Aprobar activa la regla. Rechazar conserva la evidencia para auditoría, pero la regla permanece inactiva.</p>
            <?php self::render_learning_cards($learning['candidates'] ?? array(), true); ?>
        </section>

        <section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">
            <h2 class="seo-dependiente-admin__box-title">Aprendizajes activos</h2>
            <?php self::render_learning_cards($learning['active'] ?? array(), false); ?>
        </section>

        <section class="postbox seo-dependiente-admin__box seo-dependiente-admin__wide-box">
            <h2 class="seo-dependiente-admin__box-title">Rechazados</h2>
            <?php self::render_learning_cards($learning['rejected'] ?? array(), false); ?>
        </section>
        <?php
    }

    public static function export_diagnostic_json() {
        if (!current_user_can(self::capability())) {
            wp_die('Permisos insuficientes.');
        }
        check_admin_referer('seo_dependiente_export_diagnostic');
        if (!class_exists('SEO_Dependiente_Insights')) {
            wp_die('No está disponible el módulo de diagnóstico.');
        }

        $days = SEO_Dependiente_Insights::normalize_days($_GET['days'] ?? 7);
        $snapshot = SEO_Dependiente_Insights::snapshot($days, 120);
        $json = wp_json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            wp_die('No se pudo generar el JSON de diagnóstico.');
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(SEO_Dependiente_Insights::json_filename($days)) . '"');
        echo $json;
        exit;
    }

    public static function review_learning_candidate() {
        if (!current_user_can(self::capability())) {
            wp_die('Permisos insuficientes.');
        }
        check_admin_referer('seo_dependiente_learning_review');

        $candidate_id = absint($_POST['candidate_id'] ?? 0);
        $decision = sanitize_key((string) ($_POST['decision'] ?? ''));
        $result = new WP_Error('seo_dependiente_review_invalid', 'Revisión no válida.');

        if ($candidate_id && 'approve' === $decision && class_exists('SEO_Dependiente_Learning')) {
            $result = SEO_Dependiente_Learning::approve_candidate($candidate_id, get_current_user_id());
        } elseif ($candidate_id && 'reject' === $decision && class_exists('SEO_Dependiente_Learning')) {
            $result = SEO_Dependiente_Learning::reject_candidate($candidate_id, get_current_user_id());
        }

        $args = array('page' => 'seo-dependiente', 'tab' => 'learning');
        if (is_wp_error($result) || !$result) {
            $args['review_error'] = 1;
        } else {
            $args['reviewed'] = 'approve' === $decision ? 'approved' : 'rejected';
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
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
            'results_per_page'     => min(48, max(6, absint($_POST['results_per_page'] ?? 18))),
            'menu_cards'           => min(12, max(4, absint($_POST['menu_cards'] ?? 8))),
            'help_email'           => $help_email,
            'custom_meta_keys'     => self::sanitize_meta_keys($_POST['custom_meta_keys'] ?? ''),
            'action_image_need'    => absint($_POST['action_image_need'] ?? 0),
            'action_image_product' => absint($_POST['action_image_product'] ?? 0),
            'action_image_tool'    => absint($_POST['action_image_tool'] ?? 0),
            'action_image_compare' => absint($_POST['action_image_compare'] ?? 0),
        );
        update_option('seo_dependiente_options', $options, false);
        wp_safe_redirect(add_query_arg(array('page' => 'seo-dependiente', 'tab' => 'settings', 'updated' => 1), admin_url('admin.php')));
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

    public static function ajax_reset_knowledge() {
        if (!current_user_can(self::capability())) {
            wp_send_json_error(array('message' => 'Permisos insuficientes.'), 403);
        }
        check_ajax_referer('seo_dependiente_admin', 'nonce');

        $confirmation = sanitize_text_field((string) wp_unslash($_POST['confirmation'] ?? ''));
        if ('BORRAR_CONOCIMIENTO' !== $confirmation) {
            wp_send_json_error(array('message' => 'Falta la confirmación explícita del reinicio.'), 400);
        }
        if (!class_exists('SEO_Dependiente_Reset')) {
            wp_send_json_error(array('message' => 'El servicio de reinicio no está disponible.'), 500);
        }

        $result = SEO_Dependiente_Reset::reset();
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 500);
        }
        wp_send_json_success($result);
    }

    private static function render_danger_zone() {
        if (!class_exists('SEO_Dependiente_Reset')) {
            return;
        }
        $counts = SEO_Dependiente_Reset::preview();
        ?>
        <section class="postbox seo-dependiente-admin__box seo-dependiente-admin__danger-zone" data-dependiente-reset-root>
            <h2 class="seo-dependiente-admin__box-title">Zona peligrosa · Reiniciar conocimiento</h2>
            <p><strong>Devuelve Dependiente a un estado limpio de entrenamiento.</strong> Está pensado para staging y para repetir ciclos de aprendizaje desde cero.</p>
            <div class="seo-dependiente-admin__reset-grid">
                <div><strong><?php echo esc_html(number_format_i18n(absint($counts['semantic_reset'] ?? 0))); ?></strong><span>reglas no seed que se borrarán</span></div>
                <div><strong><?php echo esc_html(number_format_i18n(absint($counts['search_log'] ?? 0))); ?></strong><span>búsquedas/evidencias que se borrarán</span></div>
                <div><strong><?php echo esc_html(number_format_i18n(absint($counts['trainer_questions'] ?? 0))); ?></strong><span>preguntas del Entrenador que se borrarán</span></div>
                <div><strong><?php echo esc_html(number_format_i18n(absint($counts['trainer_runs'] ?? 0))); ?></strong><span>ejecuciones del Entrenador que se borrarán</span></div>
                <div><strong><?php echo esc_html(number_format_i18n(absint($counts['index'] ?? 0))); ?></strong><span>productos del índice derivado que se borrarán</span></div>
                <div><strong><?php echo esc_html(number_format_i18n(absint($counts['semantic_seed'] ?? 0))); ?></strong><span>reglas base seed que se conservarán</span></div>
            </div>
            <p class="description"><strong>Se conservan:</strong> productos WooCommerce, categorías, etiquetas, vocabulario/atributos SEO, configuración del módulo, página pública y solicitudes de ayuda. El índice derivado se vacía y deberá reindexarse antes de volver a entrenar.</p>
            <p><button type="button" class="button seo-dependiente-admin__danger-button" data-dependiente-reset-prepare>Borrar conocimiento del Dependiente</button></p>

            <div class="seo-dependiente-admin__reset-confirm" data-dependiente-reset-confirm hidden>
                <h3>Confirmación necesaria</h3>
                <p>Esta acción eliminará el aprendizaje, el historial usado como evidencia, el banco y las ejecuciones del Entrenador y el índice de productos de Dependiente. No se puede deshacer desde esta pantalla.</p>
                <p><strong>Las reglas base <code>seed</code> se mantienen como baseline limpio.</strong></p>
                <div class="seo-dependiente-admin__reset-actions">
                    <button type="button" class="button seo-dependiente-admin__danger-button is-confirm" data-dependiente-reset-confirm-button>Sí, borrar todo el conocimiento de pruebas</button>
                    <button type="button" class="button" data-dependiente-reset-cancel>Cancelar</button>
                </div>
            </div>
            <p class="seo-dependiente-admin__reset-status" data-dependiente-reset-status aria-live="polite"></p>
        </section>
        <?php
    }

    private static function render_tab_link($slug, $label, $current) {
        $url = add_query_arg(array('page' => 'seo-dependiente', 'tab' => $slug), admin_url('admin.php'));
        printf(
            '<a href="%s" class="nav-tab %s">%s</a>',
            esc_url($url),
            $slug === $current ? 'nav-tab-active' : '',
            esc_html($label)
        );
    }

    private static function metric($label, $value, $tone = '') {
        ?>
        <div class="seo-dependiente-admin__metric <?php echo $tone ? 'is-' . esc_attr($tone) : ''; ?>">
            <strong><?php echo esc_html(number_format_i18n((int) $value)); ?></strong>
            <span><?php echo esc_html($label); ?></span>
        </div>
        <?php
    }

    private static function render_top_box($title, $items) {
        ?>
        <div class="postbox seo-dependiente-admin__box">
            <h2 class="seo-dependiente-admin__box-title"><?php echo esc_html($title); ?></h2>
            <?php if (!$items) : ?>
                <p class="description">Todavía no hay datos suficientes.</p>
            <?php else : ?>
                <ol class="seo-dependiente-admin__ranking">
                    <?php foreach (array_slice((array) $items, 0, 10) as $item) : ?>
                        <li><span><?php echo esc_html((string) ($item['value'] ?? '')); ?></span><strong><?php echo esc_html(number_format_i18n(absint($item['count'] ?? 0))); ?></strong></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_unresolved_table($items) {
        if (!$items) {
            echo '<p>No hay términos sin resolver en este periodo.</p>';
            return;
        }
        ?>
        <div class="seo-dependiente-admin__table-wrap"><table class="widefat striped">
            <thead><tr><th>Término</th><th>Apariciones</th><th>Sin resultados</th><th>Feedback negativo</th><th>Ejemplos</th></tr></thead>
            <tbody>
            <?php foreach (array_slice((array) $items, 0, 30) as $item) : ?>
                <tr>
                    <td><code><?php echo esc_html((string) ($item['term'] ?? '')); ?></code></td>
                    <td><?php echo esc_html(number_format_i18n(absint($item['occurrences'] ?? 0))); ?></td>
                    <td><?php echo esc_html(number_format_i18n(absint($item['zero_results'] ?? 0))); ?></td>
                    <td><?php echo esc_html(number_format_i18n(absint($item['negative_feedback'] ?? 0))); ?></td>
                    <td><?php echo esc_html(implode(' · ', array_slice((array) ($item['examples'] ?? array()), 0, 3))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    }

    private static function render_coverage_table($items) {
        if (!$items) {
            echo '<p>No hay señales destacadas de cobertura en este periodo.</p>';
            return;
        }
        ?>
        <div class="seo-dependiente-admin__table-wrap"><table class="widefat striped">
            <thead><tr><th>Intent</th><th>Objeto</th><th>Señal</th><th>Casos</th><th>Ejemplos</th></tr></thead>
            <tbody>
            <?php foreach ((array) $items as $item) : ?>
                <tr>
                    <td><?php echo esc_html((string) ($item['intent'] ?? '—')); ?></td>
                    <td><?php echo esc_html((string) ($item['object'] ?? '—')); ?></td>
                    <td><code><?php echo esc_html((string) ($item['reason'] ?? '')); ?></code></td>
                    <td><?php echo esc_html(number_format_i18n(absint($item['occurrences'] ?? 0))); ?></td>
                    <td><?php echo esc_html(implode(' · ', array_slice((array) ($item['examples'] ?? array()), 0, 3))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    }

    private static function render_recent_table($rows) {
        if (!$rows) {
            echo '<p>No hay búsquedas registradas en este periodo.</p>';
            return;
        }
        ?>
        <div class="seo-dependiente-admin__table-wrap"><table class="widefat striped seo-dependiente-admin__search-table">
            <thead><tr><th>Consulta</th><th>Interpretación</th><th>Estrategia</th><th>Productos ofrecidos</th><th>Interacción</th><th>Aprendizaje</th><th>Fecha</th></tr></thead>
            <tbody>
            <?php foreach ((array) $rows as $row) :
                $concepts = array_filter(array(
                    $row['intent'] ? 'intent: ' . $row['intent'] : '',
                    $row['object'] ? 'objeto: ' . $row['object'] : '',
                    $row['context'] ? 'contexto: ' . $row['context'] : '',
                    $row['state'] ? 'estado: ' . $row['state'] : '',
                ));
                $interactions = (array) ($row['interaction_types'] ?? array());
                $clicked_id = absint($row['clicked_product_id'] ?? 0);
                if ($clicked_id) {
                    $interactions[] = 'product_click';
                }
                $clarification = (array) ($row['clarification'] ?? array());
                if (!empty($clarification['selected_label'])) {
                    $interactions[] = 'aclaró: ' . (string) $clarification['selected_label'];
                }
                $top_results = array_slice((array) ($row['top_results'] ?? array()), 0, 5);
                ?>
                <tr>
                    <td><strong><?php echo esc_html((string) ($row['query'] ?? '')); ?></strong><?php if (!empty($row['unresolved_terms'])) : ?><br><small>Sin resolver: <?php echo esc_html(implode(', ', (array) $row['unresolved_terms'])); ?></small><?php endif; ?></td>
                    <td><?php echo $concepts ? esc_html(implode(' · ', $concepts)) : '—'; ?></td>
                    <td><code><?php echo esc_html((string) ($row['strategy'] ?? '')); ?></code><br><small><?php echo esc_html(number_format_i18n(absint($row['result_count'] ?? 0))); ?> resultados</small></td>
                    <td>
                        <?php if (!$top_results) : ?>
                            —
                        <?php else : ?>
                            <ol class="seo-dependiente-admin__offered-list">
                                <?php foreach ($top_results as $product) :
                                    $product_id = absint($product['id'] ?? 0);
                                    $is_clicked = $clicked_id && $clicked_id === $product_id;
                                    ?>
                                    <li class="<?php echo $is_clicked ? 'is-clicked' : ''; ?>">
                                        <span><?php echo esc_html((string) ($product['title'] ?? ('Producto #' . $product_id))); ?></span>
                                        <small>#<?php echo esc_html(absint($product['position'] ?? 0)); ?><?php echo $is_clicked ? ' · clic' : ''; ?></small>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $interactions ? esc_html(implode(' · ', array_unique($interactions))) : '—'; ?></td>
                    <td><?php echo esc_html((string) ($row['learning_status'] ?? 'new')); ?></td>
                    <td><?php echo esc_html((string) ($row['created_at'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    }

    private static function render_offered_products_table($items) {
        if (!$items) {
            echo '<p>No hay productos ofrecidos registrados en este periodo.</p>';
            return;
        }
        ?>
        <div class="seo-dependiente-admin__table-wrap"><table class="widefat striped">
            <thead><tr><th>Producto</th><th>Veces ofrecido</th><th>Top 3</th><th>Posición media</th><th>Clics</th></tr></thead>
            <tbody>
            <?php foreach (array_slice((array) $items, 0, 30) as $item) : ?>
                <tr>
                    <td><strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong><br><small>ID <?php echo esc_html(absint($item['id'] ?? 0)); ?></small></td>
                    <td><?php echo esc_html(number_format_i18n(absint($item['appearances'] ?? 0))); ?></td>
                    <td><?php echo esc_html(number_format_i18n(absint($item['top3'] ?? 0))); ?></td>
                    <td><?php echo esc_html(number_format_i18n((float) ($item['average_position'] ?? 0), 1)); ?></td>
                    <td><?php echo esc_html(number_format_i18n(absint($item['clicks'] ?? 0))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    }

    private static function render_semantics_overview($semantics) {
        $semantics = is_array($semantics) ? $semantics : array();
        $sources = (array) ($semantics['by_source'] ?? array());
        if (!$sources) {
            echo '<p>No hay reglas semánticas disponibles.</p>';
            return;
        }
        ?>
        <div class="seo-dependiente-admin__metrics seo-dependiente-admin__metrics--small">
            <?php self::metric('Reglas totales', $semantics['total'] ?? 0); ?>
            <?php self::metric('Reglas activas', $semantics['active'] ?? 0); ?>
            <?php self::metric('Candidatos pendientes', $semantics['candidate'] ?? 0); ?>
        </div>
        <div class="seo-dependiente-admin__table-wrap"><table class="widefat striped">
            <thead><tr><th>Origen</th><th>Activas</th><th>Inactivas</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($sources as $source => $counts) : ?>
                <tr>
                    <td><code><?php echo esc_html((string) $source); ?></code></td>
                    <td><?php echo esc_html(number_format_i18n(absint($counts['active'] ?? 0))); ?></td>
                    <td><?php echo esc_html(number_format_i18n(absint($counts['inactive'] ?? 0))); ?></td>
                    <td><?php echo esc_html(number_format_i18n(absint($counts['total'] ?? 0))); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    }

    private static function render_learning_cards($items, $reviewable) {
        if (!$items) {
            echo '<p class="description">No hay elementos en esta sección.</p>';
            return;
        }
        echo '<div class="seo-dependiente-admin__learning-list">';
        foreach ((array) $items as $item) {
            $evidence = (array) ($item['evidence'] ?? array());
            ?>
            <article class="seo-dependiente-admin__learning-card">
                <div class="seo-dependiente-admin__learning-head">
                    <div>
                        <strong><code><?php echo esc_html((string) ($item['expression'] ?? '')); ?></code> → <code><?php echo esc_html((string) ($item['canonical_expression'] ?? '')); ?></code></strong>
                        <div class="seo-dependiente-admin__badges">
                            <span><?php echo esc_html((string) ($item['semantic_role'] ?? '')); ?></span>
                            <span><?php echo esc_html((string) ($item['relation_type'] ?? '')); ?></span>
                            <span>conf. <?php echo esc_html(number_format_i18n(100 * (float) ($item['confidence'] ?? 0), 1)); ?>%</span>
                        </div>
                    </div>
                    <?php if ($reviewable) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="seo-dependiente-admin__learning-actions">
                            <input type="hidden" name="action" value="seo_dependiente_learning_review">
                            <input type="hidden" name="candidate_id" value="<?php echo esc_attr(absint($item['id'] ?? 0)); ?>">
                            <?php wp_nonce_field('seo_dependiente_learning_review'); ?>
                            <button type="submit" class="button button-primary" name="decision" value="approve">Aprobar</button>
                            <button type="submit" class="button" name="decision" value="reject">Rechazar</button>
                        </form>
                    <?php endif; ?>
                </div>
                <p class="seo-dependiente-admin__evidence">
                    <?php echo esc_html(sprintf(
                        '%d clics · %d aclaraciones · %d reformulaciones · %d sesiones · %d productos',
                        absint($evidence['clicks'] ?? 0),
                        absint($evidence['clarifications'] ?? 0),
                        absint($evidence['reformulations'] ?? 0),
                        absint($evidence['sessions'] ?? 0),
                        absint($evidence['products'] ?? 0)
                    )); ?>
                </p>
                <?php if (!empty($evidence['examples'])) : ?>
                    <details><summary>Ver ejemplos</summary><ul><?php foreach ((array) $evidence['examples'] as $example) : ?><li><?php echo esc_html((string) $example); ?></li><?php endforeach; ?></ul></details>
                <?php endif; ?>
            </article>
            <?php
        }
        echo '</div>';
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
                <?php if ($preview) : ?><img src="<?php echo esc_url($preview); ?>" alt="" data-fallback-src="<?php echo esc_url($fallback); ?>" data-dependiente-media-preview><?php endif; ?>
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
