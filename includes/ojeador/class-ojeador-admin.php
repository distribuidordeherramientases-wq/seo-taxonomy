<?php
/**
 * Ojeador administration UI.
 *
 * Functional/data management lives in Herramientas > Ojeador.
 * Server pressure lives in SEO Taxonomy > Procesos.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.2.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Admin {
    const PAGE = 'seo-ojeador';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_page'), 90);
        add_filter('seo_tools_cards', array(__CLASS__, 'register_tool_card'));
        add_action('admin_footer', array(__CLASS__, 'inject_tools_card_fallback'));

        add_action('admin_post_seo_ojeador_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_seo_ojeador_start', array(__CLASS__, 'start'));
        add_action('admin_post_seo_ojeador_stop', array(__CLASS__, 'stop'));
        add_action('admin_post_seo_ojeador_add_offer', array(__CLASS__, 'add_offer'));
        add_action('admin_post_seo_ojeador_offer_status', array(__CLASS__, 'offer_status'));
        add_action('admin_post_seo_ojeador_offer_active', array(__CLASS__, 'offer_active'));
        add_action('admin_post_seo_ojeador_product_status', array(__CLASS__, 'product_status'));
    }

    public static function register_page() {
        add_submenu_page(
            null,
            'Ojeador',
            'Ojeador',
            'manage_options',
            self::PAGE,
            array(__CLASS__, 'render')
        );
    }

    public static function register_tool_card($tools) {
        if (!is_array($tools)) {
            $tools = array();
        }
        foreach ($tools as $tool) {
            if (isset($tool['page']) && self::PAGE === (string) $tool['page']) {
                return $tools;
            }
        }
        $tools[] = array(
            'title' => 'Ojeador',
            'icon' => 'dashicons-visibility',
            'page' => self::PAGE,
            'desc' => 'Precios externos, comparativas, ofertas, históricos y barridos del mercado.',
        );
        return $tools;
    }

    /**
     * Compatibility fallback for installations whose Herramientas page has not
     * yet added the generic seo_tools_cards filter. It injects only the card.
     */
    public static function inject_tools_card_fallback() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if ('seo-tools' !== $page) {
            return;
        }
        $url = add_query_arg(array('page' => self::PAGE), admin_url('admin.php'));
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var grid = document.querySelector('.seo-tools-grid');
            if (!grid || grid.querySelector('a[href*="page=seo-ojeador"]')) return;
            var link = document.createElement('a');
            link.className = 'seo-tool-card-link';
            link.href = <?php echo wp_json_encode($url); ?>;
            link.innerHTML = '<div class="seo-tool-card"><span class="dashicons dashicons-visibility"></span><h2>Ojeador</h2><p>Precios externos, comparativas, ofertas, históricos y barridos del mercado.</p><span class="button button-primary">Abrir</span></div>';
            grid.appendChild(link);
        });
        </script>
        <?php
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'seo-taxonomy'));
        }
        check_admin_referer($action);
    }

    private static function current_tab() {
        $tab = sanitize_key((string) ($_REQUEST['tab'] ?? 'resumen'));
        return in_array($tab, array('resumen','comparativas','productos','ofertas','historico','barridos','configuracion'), true)
            ? $tab
            : 'resumen';
    }

    private static function redirect($args = array(), $tab = '') {
        $base = array('page' => self::PAGE);
        if ($tab !== '') {
            $base['tab'] = sanitize_key($tab);
        }
        wp_safe_redirect(add_query_arg(array_merge($base, $args), admin_url('admin.php')));
        exit;
    }

    public static function save_settings() {
        self::guard('seo_ojeador_save_settings');
        $raw = isset($_POST['ojeador']) && is_array($_POST['ojeador']) ? wp_unslash($_POST['ojeador']) : array();
        foreach (array('auto_enabled', 'allow_non_production', 'include_provider_catalog', 'refresh_existing_urls') as $key) {
            $raw[$key] = empty($raw[$key]) ? 0 : 1;
        }
        SEO_Ojeador_Worker::save_settings($raw);
        self::redirect(array('ojeador_notice' => 'settings_saved'), 'configuracion');
    }

    public static function start() {
        self::guard('seo_ojeador_start');
        $product_id = absint($_POST['product_id'] ?? 0);
        $max_products = absint($_POST['max_products'] ?? 0);
        $result = SEO_Ojeador_Worker::start_run('manual', $max_products, $product_id, 'admin');
        if (is_wp_error($result)) {
            self::redirect(array('ojeador_error' => rawurlencode($result->get_error_message())), 'barridos');
        }
        self::redirect(array('ojeador_notice' => 'run_started'), 'barridos');
    }

    public static function stop() {
        self::guard('seo_ojeador_stop');
        SEO_Ojeador_Worker::stop_run();
        self::redirect(array('ojeador_notice' => 'run_stopped'), 'barridos');
    }

    public static function add_offer() {
        self::guard('seo_ojeador_add_offer');
        $product_id = absint($_POST['product_id'] ?? 0);
        $merchant = sanitize_text_field(wp_unslash($_POST['merchant_name'] ?? ''));
        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        if ($product_id < 1 || $url === '') {
            self::redirect(array('object_id' => $product_id, 'ojeador_error' => rawurlencode('Indica product_id y URL externa.')), 'comparativas');
        }
        $result = SEO_Ojeador_Worker::inspect_manual_url($product_id, $merchant, $url);
        if (is_wp_error($result)) {
            self::redirect(array('object_id' => $product_id, 'ojeador_error' => rawurlencode($result->get_error_message())), 'comparativas');
        }
        self::redirect(array('object_id' => $product_id, 'ojeador_notice' => 'offer_added'), 'comparativas');
    }

    public static function offer_status() {
        self::guard('seo_ojeador_offer_status');
        $offer_id = absint($_POST['offer_id'] ?? 0);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? 'review'));
        $offer = SEO_Ojeador_DB::offer_row($offer_id);
        if (!$offer) {
            self::redirect(array('ojeador_error' => rawurlencode('Oferta no encontrada.')), 'ofertas');
        }
        SEO_Ojeador_DB::set_offer_status($offer_id, $status);
        self::redirect(array('ojeador_notice' => 'offer_status'), 'ofertas');
    }

    public static function offer_active() {
        self::guard('seo_ojeador_offer_active');
        $offer_id = absint($_POST['offer_id'] ?? 0);
        $active = empty($_POST['active']) ? 0 : 1;
        SEO_Ojeador_DB::set_offer_active($offer_id, $active);
        self::redirect(array('ojeador_notice' => 'offer_active'), 'ofertas');
    }

    public static function product_status() {
        self::guard('seo_ojeador_product_status');
        $object_id = absint($_POST['object_id'] ?? 0);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? 'paused'));
        SEO_Ojeador_DB::set_product_status($object_id, $status);
        self::redirect(array('ojeador_notice' => 'product_status'), 'productos');
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-taxonomy'));
        }
        SEO_Ojeador_DB::maybe_install();
        $tab = self::current_tab();
        ?>
        <div class="wrap seo-ojeador-wrap">
            <div class="seo-ojeador-titlebar">
                <div>
                    <h1>Ojeador <span>v<?php echo esc_html(defined('SEO_OJEADOR_VERSION') ? SEO_OJEADOR_VERSION : '0.2.0'); ?></span></h1>
                    <p>Inteligencia de precios y ofertas externas. La presión sobre el servidor se regula desde <strong>SEO Taxonomy → Procesos</strong>.</p>
                </div>
                <a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'seo-processes'), admin_url('admin.php'))); ?>">Abrir Procesos</a>
            </div>
            <?php self::tabs($tab); ?>
            <?php self::notices(); ?>
            <?php
            switch ($tab) {
                case 'comparativas': self::render_comparisons(); break;
                case 'productos': self::render_products(); break;
                case 'ofertas': self::render_offers(); break;
                case 'historico': self::render_history(); break;
                case 'barridos': self::render_scans(); break;
                case 'configuracion': self::render_settings(); break;
                case 'resumen':
                default: self::render_summary(); break;
            }
            ?>
        </div>
        <?php self::styles(); ?>
        <?php
    }

    private static function tabs($active) {
        $tabs = array(
            'resumen' => 'Resumen',
            'comparativas' => 'Comparativas',
            'productos' => 'Productos vigilados',
            'ofertas' => 'Ofertas',
            'historico' => 'Histórico',
            'barridos' => 'Barridos',
            'configuracion' => 'Configuración',
        );
        echo '<nav class="nav-tab-wrapper seo-ojeador-tabs">';
        foreach ($tabs as $slug => $label) {
            $url = add_query_arg(array('page'=>self::PAGE,'tab'=>$slug), admin_url('admin.php'));
            echo '<a class="nav-tab ' . ($slug === $active ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function notices() {
        $notice = sanitize_key(wp_unslash($_GET['ojeador_notice'] ?? ''));
        $messages = array(
            'settings_saved' => 'Configuración de Ojeador guardada.',
            'run_started' => 'Barrido iniciado. Procesos lo ejecutará por ventanas.',
            'run_stopped' => 'Barrido detenido por el usuario.',
            'offer_added' => 'URL externa inspeccionada y guardada.',
            'offer_status' => 'Estado de coincidencia actualizado.',
            'offer_active' => 'Estado de la oferta actualizado.',
            'product_status' => 'Estado de vigilancia del producto actualizado.',
        );
        if (isset($messages[$notice])) {
            echo '<div class="notice notice-success inline"><p>' . esc_html($messages[$notice]) . '</p></div>';
        }
        $error = trim((string) wp_unslash($_GET['ojeador_error'] ?? ''));
        if ($error !== '') {
            echo '<div class="notice notice-error inline"><p>' . esc_html(rawurldecode($error)) . '</p></div>';
        }
    }

    private static function render_summary() {
        $summary = SEO_Ojeador_DB::market_summary();
        $settings = SEO_Ojeador_Worker::settings();
        $next = wp_next_scheduled(SEO_Ojeador_Worker::CRON_HOOK);
        $run = SEO_Ojeador_DB::active_run();
        if (!$run) $run = SEO_Ojeador_DB::latest_run();
        ?>
        <div class="seo-ojeador-kpis">
            <?php self::kpi($summary['products'], 'Productos vigilados'); ?>
            <?php self::kpi($summary['products_compared'], 'Con comparación'); ?>
            <?php self::kpi($summary['offers'], 'Ofertas activas'); ?>
            <?php self::kpi($summary['merchants'], 'Comercios observados'); ?>
            <?php self::kpi($summary['review'], 'Matches por revisar'); ?>
            <?php self::kpi($summary['stale'], 'Ofertas caducadas'); ?>
        </div>
        <section class="seo-ojeador-card">
            <div class="seo-ojeador-card-head"><div><h2>Estado del sistema</h2><p>Visión rápida de vigilancia, programación y último trabajo.</p></div></div>
            <div class="seo-ojeador-summary-grid">
                <div><b>Automático</b><span><?php echo !empty($settings['auto_enabled']) ? 'Activo' : 'Desactivado'; ?></span></div>
                <div><b>Próximo barrido</b><span><?php echo esc_html($next ? wp_date('Y-m-d H:i', $next) : 'No programado'); ?></span></div>
                <div><b>Último estado</b><span><?php echo esc_html($run ? strtoupper((string)$run['status']) : 'Sin ejecuciones'); ?></span></div>
                <div><b>Histórico</b><span><?php echo esc_html(number_format_i18n($summary['history_rows'])); ?> cambios guardados</span></div>
            </div>
        </section>
        <?php self::render_comparison_table(SEO_Ojeador_DB::list_products(array('limit'=>30))); ?>
        <?php
    }

    private static function kpi($value, $label) {
        echo '<div><strong>' . esc_html(number_format_i18n(absint($value))) . '</strong><span>' . esc_html($label) . '</span></div>';
    }

    private static function render_comparisons() {
        $object_id = absint($_GET['object_id'] ?? 0);
        if ($object_id > 0) {
            self::render_product_detail($object_id);
            return;
        }
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        self::search_form('comparativas', $search, 'Buscar por producto, SKU, EAN, MPN, marca o modelo');
        $rows = SEO_Ojeador_DB::list_products(array('limit'=>150,'search'=>$search));
        self::render_comparison_table($rows);
    }

    private static function render_comparison_table($rows) {
        ?>
        <section class="seo-ojeador-card">
            <div class="seo-ojeador-card-head"><div><h2>Comparativas</h2><p>Nuestro precio frente a ofertas confirmadas o probables del mismo producto.</p></div></div>
            <div class="seo-ojeador-table-wrap"><table class="widefat striped"><thead><tr><th>Producto</th><th>Nuestro precio</th><th>Mínimo</th><th>Mediana</th><th>Máximo</th><th>Diferencia vs mediana</th><th>Ofertas</th><th>Último barrido</th></tr></thead><tbody>
            <?php if (!$rows) : ?><tr><td colspan="8">Todavía no hay comparativas.</td></tr><?php else : foreach ($rows as $row) :
                $object_id = absint($row['object_id']);
                $product = function_exists('wc_get_product') ? wc_get_product($object_id) : null;
                $our = $product ? self::our_gross_price($product) : null;
                $comparison = SEO_Ojeador_DB::comparison_for_object($object_id);
                $median = $comparison['stats']['median'] ?? null;
                $delta = ($median && $our) ? (($our - $median) / $median) * 100 : null;
                $url = add_query_arg(array('page'=>self::PAGE,'tab'=>'comparativas','object_id'=>$object_id), admin_url('admin.php'));
            ?>
                <tr>
                    <td><a href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html($row['canonical_name']); ?></strong></a><br><small>#<?php echo esc_html((string)$object_id); ?> · <?php echo esc_html($row['brand'] ?: 'sin marca'); ?></small></td>
                    <td><?php echo esc_html(self::money($our)); ?></td>
                    <td><?php echo esc_html(self::money($comparison['stats']['min'] ?? null)); ?></td>
                    <td><strong><?php echo esc_html(self::money($median)); ?></strong></td>
                    <td><?php echo esc_html(self::money($comparison['stats']['max'] ?? null)); ?></td>
                    <td><?php echo null === $delta ? '—' : esc_html(number_format_i18n($delta,1) . '%'); ?></td>
                    <td><?php echo esc_html(number_format_i18n(absint($comparison['stats']['count'] ?? 0))); ?></td>
                    <td><?php echo esc_html((string)($row['last_scan_at'] ?: '—')); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody></table></div>
        </section>
        <?php
    }

    private static function render_product_detail($object_id) {
        $data = SEO_Ojeador_DB::comparison_for_object($object_id);
        $row = $data['product'];
        $product = function_exists('wc_get_product') ? wc_get_product($object_id) : null;
        if (!$product) {
            echo '<div class="notice notice-warning inline"><p>Producto WooCommerce no encontrado.</p></div>';
            return;
        }
        if (!$row) {
            $identity = SEO_Ojeador_Identity::from_product($object_id);
            if (!is_wp_error($identity)) {
                SEO_Ojeador_DB::upsert_product($identity);
                $data = SEO_Ojeador_DB::comparison_for_object($object_id);
                $row = $data['product'];
            }
        }
        $our_price = self::our_gross_price($product);
        $median = $data['stats']['median'] ?? null;
        $delta = ($median && $our_price) ? (($our_price - $median) / $median) * 100 : null;
        ?>
        <p><a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE,'tab'=>'comparativas'), admin_url('admin.php'))); ?>">← Volver a comparativas</a></p>
        <section class="seo-ojeador-card">
            <div class="seo-ojeador-card-head"><div><h2><?php echo esc_html($product->get_name()); ?></h2><p>product_id <?php echo esc_html((string)$object_id); ?> · SKU <?php echo esc_html($product->get_sku() ?: '—'); ?></p></div><a class="button" href="<?php echo esc_url(get_edit_post_link($object_id)); ?>">Editar producto</a></div>
            <div class="seo-ojeador-identity">
                <span><b>GTIN</b> <?php echo esc_html($row['gtin'] ?? '—'); ?></span><span><b>MPN</b> <?php echo esc_html($row['mpn'] ?? '—'); ?></span><span><b>Marca</b> <?php echo esc_html($row['brand'] ?? '—'); ?></span><span><b>Modelo</b> <?php echo esc_html($row['model'] ?? '—'); ?></span>
            </div>
            <div class="seo-ojeador-kpis is-small">
                <div><strong><?php echo esc_html(self::money($our_price)); ?></strong><span>Nuestro precio con IVA</span></div>
                <div><strong><?php echo esc_html(self::money($data['stats']['min'] ?? null)); ?></strong><span>Mínimo observado</span></div>
                <div><strong><?php echo esc_html(self::money($median)); ?></strong><span>Mediana observada</span></div>
                <div><strong><?php echo null === $delta ? '—' : esc_html(number_format_i18n($delta,1) . '%'); ?></strong><span>Diferencia vs mediana</span></div>
            </div>
            <p class="description">El total solo es plenamente comparable cuando IVA y transporte están confirmados. Si faltan, Ojeador conserva la oferta pero la marca como comparación parcial.</p>
            <h3>Ofertas observadas</h3>
            <?php self::offers_table($data['offers']); ?>
            <h3>Añadir URL externa</h3>
            <form class="seo-ojeador-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="seo_ojeador_add_offer"><input type="hidden" name="product_id" value="<?php echo esc_attr((string)$object_id); ?>"><?php wp_nonce_field('seo_ojeador_add_offer'); ?>
                <label>Comercio <input type="text" name="merchant_name" placeholder="TodoTaladros"></label><label>URL producto <input type="url" name="url" required placeholder="https://..."></label><button class="button button-primary" type="submit">Inspeccionar y guardar</button>
            </form>
        </section>
        <?php
    }

    private static function render_products() {
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $status = sanitize_key((string)($_GET['status'] ?? ''));
        self::search_form('productos', $search, 'Buscar producto vigilado');
        $rows = SEO_Ojeador_DB::list_products(array('limit'=>200,'search'=>$search,'status'=>$status));
        ?>
        <section class="seo-ojeador-card">
            <div class="seo-ojeador-card-head"><div><h2>Productos vigilados</h2><p>Alta por escaneo; baja lógica mediante pausa para no perder el histórico.</p></div></div>
            <div class="seo-ojeador-table-wrap"><table class="widefat striped"><thead><tr><th>Producto</th><th>Identidad</th><th>Estado</th><th>Ofertas</th><th>Último barrido</th><th>Próximo</th><th>Acciones</th></tr></thead><tbody>
            <?php if (!$rows) : ?><tr><td colspan="7">No hay productos vigilados.</td></tr><?php else : foreach ($rows as $row) : ?>
                <tr><td><strong><?php echo esc_html($row['canonical_name']); ?></strong><br><small>#<?php echo esc_html((string)absint($row['object_id'])); ?></small></td><td><?php echo esc_html(self::identity_label($row)); ?></td><td><?php echo esc_html((string)$row['status']); ?></td><td><?php echo esc_html(number_format_i18n(absint($row['offer_count']))); ?></td><td><?php echo esc_html((string)($row['last_scan_at'] ?: '—')); ?></td><td><?php echo esc_html((string)($row['next_scan_at'] ?: '—')); ?></td><td><?php self::product_actions($row); ?></td></tr>
            <?php endforeach; endif; ?>
            </tbody></table></div>
        </section>
        <?php
    }

    private static function product_actions($row) {
        $object_id = absint($row['object_id']);
        $comparison_url = add_query_arg(array('page'=>self::PAGE,'tab'=>'comparativas','object_id'=>$object_id), admin_url('admin.php'));
        echo '<a class="button button-small" href="' . esc_url($comparison_url) . '">Ver</a> ';
        echo '<form class="seo-ojeador-inline-action" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_ojeador_product_status"><input type="hidden" name="object_id" value="' . esc_attr((string)$object_id) . '"><input type="hidden" name="status" value="' . esc_attr('active' === (string)$row['status'] ? 'paused' : 'active') . '">';
        wp_nonce_field('seo_ojeador_product_status');
        echo '<button class="button button-small" type="submit">' . ('active' === (string)$row['status'] ? 'Pausar' : 'Activar') . '</button></form>';
    }

    private static function render_offers() {
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $match_status = sanitize_key((string)($_GET['match_status'] ?? ''));
        self::search_form('ofertas', $search, 'Buscar por producto, comercio, EAN o MPN');
        $rows = SEO_Ojeador_DB::list_offers(array('limit'=>250,'search'=>$search,'match_status'=>$match_status));
        ?>
        <section class="seo-ojeador-card"><div class="seo-ojeador-card-head"><div><h2>Ofertas</h2><p>Tabla normalizada de precios, IVA, transporte, stock y confianza de identidad.</p></div></div><?php self::offers_table($rows, true); ?></section>
        <?php
    }

    private static function offers_table($rows, $show_product = false) {
        ?>
        <div class="seo-ojeador-table-wrap"><table class="widefat striped"><thead><tr><?php if ($show_product) : ?><th>Producto</th><?php endif; ?><th>Comercio</th><th>Precio</th><th>IVA</th><th>Transporte</th><th>Total</th><th>Stock</th><th>Match</th><th>Observado</th><th>Acciones</th></tr></thead><tbody>
        <?php if (!$rows) : ?><tr><td colspan="10">No hay ofertas.</td></tr><?php else : foreach ($rows as $offer) : ?>
            <tr><?php if ($show_product) : ?><td><a href="<?php echo esc_url(add_query_arg(array('page'=>self::PAGE,'tab'=>'comparativas','object_id'=>absint($offer['object_id'] ?? 0)), admin_url('admin.php'))); ?>"><strong><?php echo esc_html($offer['canonical_name'] ?? ''); ?></strong></a><br><small>#<?php echo esc_html((string)absint($offer['object_id'] ?? 0)); ?></small></td><?php endif; ?>
                <td><strong><?php echo esc_html($offer['merchant_name'] ?: $offer['source_key']); ?></strong><?php if (!empty($offer['url'])) : ?><br><a href="<?php echo esc_url($offer['url']); ?>" target="_blank" rel="noopener noreferrer">Abrir oferta</a><?php endif; ?></td>
                <td><?php echo esc_html(self::money($offer['price_raw'])); ?></td><td><?php echo esc_html(self::vat_label($offer)); ?></td><td><?php echo esc_html(self::shipping_label($offer)); ?></td><td><strong><?php echo esc_html(self::money($offer['total_price'] ?: ($offer['price_gross'] ?: $offer['price_raw']))); ?></strong></td><td><?php echo esc_html($offer['stock_status'] ?: '—'); ?></td><td><?php echo esc_html((string)$offer['match_status']); ?><br><small><?php echo esc_html((string)$offer['match_method']); ?> · <?php echo esc_html(number_format_i18n(((float)$offer['match_confidence'])*100,1)); ?>%</small></td><td><?php echo esc_html((string)$offer['observed_at']); ?><?php if (empty($offer['active'])) : ?><br><small>INACTIVA</small><?php endif; ?></td><td><?php self::offer_actions($offer); ?></td>
            </tr>
        <?php endforeach; endif; ?></tbody></table></div>
        <?php
    }

    private static function offer_actions($offer) {
        foreach (array('confirmed'=>'Confirmar','review'=>'Revisar','rejected'=>'Descartar') as $status=>$label) {
            if ((string)$offer['match_status'] === $status) continue;
            echo '<form class="seo-ojeador-inline-action" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_ojeador_offer_status"><input type="hidden" name="offer_id" value="' . esc_attr((string)absint($offer['id'])) . '"><input type="hidden" name="status" value="' . esc_attr($status) . '">'; wp_nonce_field('seo_ojeador_offer_status'); echo '<button class="button button-small" type="submit">' . esc_html($label) . '</button></form>';
        }
        echo '<form class="seo-ojeador-inline-action" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_ojeador_offer_active"><input type="hidden" name="offer_id" value="' . esc_attr((string)absint($offer['id'])) . '"><input type="hidden" name="active" value="' . (empty($offer['active']) ? '1' : '0') . '">'; wp_nonce_field('seo_ojeador_offer_active'); echo '<button class="button button-small" type="submit">' . (empty($offer['active']) ? 'Activar' : 'Desactivar') . '</button></form>';
    }

    private static function render_history() {
        $rows = SEO_Ojeador_DB::list_history(250);
        ?>
        <section class="seo-ojeador-card"><div class="seo-ojeador-card-head"><div><h2>Histórico de cambios</h2><p>Se guarda la primera observación y después una nueva fila solo cuando cambia la huella comercial de la oferta.</p></div></div><div class="seo-ojeador-table-wrap"><table class="widefat striped"><thead><tr><th>Producto</th><th>Comercio</th><th>Precio</th><th>IVA</th><th>Transporte</th><th>Total</th><th>Stock</th><th>Observado</th></tr></thead><tbody>
        <?php if (!$rows) : ?><tr><td colspan="8">Todavía no hay histórico.</td></tr><?php else : foreach ($rows as $row) : ?><tr><td><?php echo esc_html($row['canonical_name']); ?><br><small>#<?php echo esc_html((string)absint($row['object_id'])); ?></small></td><td><?php echo esc_html($row['merchant_name']); ?></td><td><?php echo esc_html(self::money($row['price_raw'])); ?></td><td><?php echo esc_html((string)$row['vat_mode']); ?></td><td><?php echo esc_html(self::money($row['shipping_price'])); ?></td><td><strong><?php echo esc_html(self::money($row['total_price'])); ?></strong></td><td><?php echo esc_html((string)$row['stock_status']); ?></td><td><?php echo esc_html((string)$row['observed_at']); ?></td></tr><?php endforeach; endif; ?>
        </tbody></table></div></section>
        <?php
    }

    private static function render_scans() {
        $settings = SEO_Ojeador_Worker::settings();
        $run = SEO_Ojeador_DB::active_run();
        if (!$run) $run = SEO_Ojeador_DB::latest_run();
        self::render_run_panel($run, $settings);
        $runs = SEO_Ojeador_DB::list_runs(100);
        ?>
        <section class="seo-ojeador-card"><div class="seo-ojeador-card-head"><div><h2>Ejecuciones</h2><p>Histórico de barridos. La velocidad/carga se regula en Procesos.</p></div><a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'seo-processes'), admin_url('admin.php'))); ?>">Regular carga</a></div><div class="seo-ojeador-table-wrap"><table class="widefat striped"><thead><tr><th>ID</th><th>Tipo</th><th>Estado</th><th>Productos</th><th>Ofertas</th><th>Errores</th><th>Inicio</th><th>Fin</th></tr></thead><tbody>
        <?php if (!$runs) : ?><tr><td colspan="8">Sin ejecuciones.</td></tr><?php else : foreach ($runs as $item) : ?><tr><td>#<?php echo esc_html((string)absint($item['id'])); ?></td><td><?php echo esc_html((string)$item['run_type']); ?></td><td><?php echo esc_html((string)$item['status']); ?></td><td><?php echo esc_html(number_format_i18n(absint($item['processed_products']))) . ' / ' . esc_html(number_format_i18n(absint($item['total_candidates']))); ?></td><td><?php echo esc_html(number_format_i18n(absint($item['offers_seen']))); ?></td><td><?php echo esc_html(number_format_i18n(absint($item['errors_count']))); ?></td><td><?php echo esc_html((string)($item['started_at'] ?: $item['created_at'])); ?></td><td><?php echo esc_html((string)($item['completed_at'] ?: '—')); ?></td></tr><?php endforeach; endif; ?>
        </tbody></table></div></section>
        <?php
    }

    private static function render_run_panel($run, $settings) {
        $active = is_array($run) && in_array((string)($run['status'] ?? ''), array('pending','running'), true);
        $processed = absint($run['processed_products'] ?? 0);
        $total = absint($run['total_candidates'] ?? 0);
        $percent = $total > 0 ? min(100, round(($processed/$total)*100,1)) : 0;
        ?>
        <section class="seo-ojeador-card"><div class="seo-ojeador-card-head"><div><h2>Barridos</h2><p>Arranca trabajo aquí. El Gestor de procesos decide la presión y el tamaño de las ventanas.</p></div><strong class="seo-ojeador-state <?php echo $active ? 'is-running' : ''; ?>"><?php echo esc_html($run ? strtoupper((string)$run['status']) : 'SIN EJECUCIONES'); ?></strong></div>
        <?php if ($run) : ?><div class="seo-ojeador-progress"><span style="width:<?php echo esc_attr((string)$percent); ?>%"></span></div><p><strong><?php echo esc_html(number_format_i18n($processed)); ?> / <?php echo esc_html(number_format_i18n($total)); ?></strong> productos · <?php echo esc_html(number_format_i18n(absint($run['offers_seen'] ?? 0))); ?> ofertas · <?php echo esc_html(number_format_i18n(absint($run['errors_count'] ?? 0))); ?> errores</p><?php endif; ?>
        <div class="seo-ojeador-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="seo_ojeador_start"><?php wp_nonce_field('seo_ojeador_start'); ?><label>Product ID <input type="number" name="product_id" min="1" placeholder="Ej. 12345"></label><button class="button button-primary" type="submit" <?php disabled($active); ?>>Escanear producto</button></form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="seo_ojeador_start"><?php wp_nonce_field('seo_ojeador_start'); ?><label>Productos <input type="number" name="max_products" min="1" max="10000" value="<?php echo esc_attr((string)$settings['max_products_per_run']); ?>"></label><button class="button button-primary" type="submit" <?php disabled($active); ?>>Iniciar barrido</button></form>
            <?php if ($active) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="seo_ojeador_stop"><?php wp_nonce_field('seo_ojeador_stop'); ?><button class="button" type="submit">Detener</button></form><?php endif; ?>
        </div></section>
        <?php
    }

    private static function render_settings() {
        $settings = SEO_Ojeador_Worker::settings();
        $next = wp_next_scheduled(SEO_Ojeador_Worker::CRON_HOOK);
        $days = array('Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado');
        ?>
        <section class="seo-ojeador-card"><div class="seo-ojeador-card-head"><div><h2>Configuración funcional</h2><p>Programación, vigencia y fuentes. La presión del servidor no se configura aquí.</p></div><strong>Próxima: <?php echo esc_html($next ? wp_date('Y-m-d H:i',$next) : 'No programado'); ?></strong></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="seo_ojeador_save_settings"><?php wp_nonce_field('seo_ojeador_save_settings'); ?><div class="seo-ojeador-grid">
            <label><input type="checkbox" name="ojeador[auto_enabled]" value="1" <?php checked(!empty($settings['auto_enabled'])); ?>> Barrido automático semanal</label><label><input type="checkbox" name="ojeador[allow_non_production]" value="1" <?php checked(!empty($settings['allow_non_production'])); ?>> Permitir automático fuera de producción</label>
            <label>Día <select name="ojeador[weekday]"><?php foreach ($days as $i=>$day) : ?><option value="<?php echo esc_attr((string)$i); ?>" <?php selected((int)$settings['weekday'],$i); ?>><?php echo esc_html($day); ?></option><?php endforeach; ?></select></label><label>Hora <input type="number" name="ojeador[hour]" min="0" max="23" value="<?php echo esc_attr((string)$settings['hour']); ?>"></label><label>Minuto <input type="number" name="ojeador[minute]" min="0" max="59" value="<?php echo esc_attr((string)$settings['minute']); ?>"></label>
            <label>Productos por barrido <input type="number" name="ojeador[max_products_per_run]" min="1" max="10000" value="<?php echo esc_attr((string)$settings['max_products_per_run']); ?>"></label><label>Máx. ofertas por producto <input type="number" name="ojeador[max_offers_per_product]" min="1" max="20" value="<?php echo esc_attr((string)$settings['max_offers_per_product']); ?>"></label><label>Vigencia oferta (h) <input type="number" name="ojeador[freshness_hours]" min="1" max="720" value="<?php echo esc_attr((string)$settings['freshness_hours']); ?>"></label><label>Timeout URL (s) <input type="number" name="ojeador[request_timeout]" min="5" max="25" value="<?php echo esc_attr((string)$settings['request_timeout']); ?>"></label>
            <label><input type="checkbox" name="ojeador[include_provider_catalog]" value="1" <?php checked(!empty($settings['include_provider_catalog'])); ?>> Usar catálogo de proveedores</label><label><input type="checkbox" name="ojeador[refresh_existing_urls]" value="1" <?php checked(!empty($settings['refresh_existing_urls'])); ?>> Refrescar URLs ya guardadas</label>
        </div><p><button class="button button-primary" type="submit">Guardar configuración</button> <a class="button" href="<?php echo esc_url(add_query_arg(array('page'=>'seo-processes'), admin_url('admin.php'))); ?>">Regular presión en Procesos</a></p></form></section>
        <?php
    }

    private static function search_form($tab, $value, $placeholder) {
        ?>
        <form class="seo-ojeador-search" method="get"><input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>"><input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>"><input type="search" name="s" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>"><button class="button" type="submit">Buscar</button></form>
        <?php
    }

    private static function identity_label($row) {
        if (!empty($row['gtin'])) return 'GTIN ' . $row['gtin'];
        if (!empty($row['mpn'])) return 'MPN ' . $row['mpn'];
        if (!empty($row['model'])) return 'Modelo ' . $row['model'];
        if (!empty($row['sku'])) return 'SKU ' . $row['sku'];
        return 'Identidad débil';
    }

    private static function our_gross_price($product) {
        if (!$product) return null;
        $price = (float)$product->get_price();
        if ($price <= 0) return null;
        return function_exists('wc_get_price_including_tax') ? (float)wc_get_price_including_tax($product,array('price'=>$price)) : $price;
    }

    private static function money($value) {
        if ($value === null || $value === '') return '—';
        return number_format_i18n((float)$value,2) . ' EUR';
    }

    private static function vat_label($offer) {
        $mode = (string)($offer['vat_mode'] ?? 'unknown'); $rate = $offer['vat_rate'] ?? null;
        if ('included' === $mode) return 'Incluido' . ($rate !== null && $rate !== '' ? ' (' . number_format_i18n((float)$rate,1) . '%)' : '');
        if ('excluded' === $mode) return 'No incluido' . ($rate !== null && $rate !== '' ? ' (' . number_format_i18n((float)$rate,1) . '%)' : '');
        return 'No confirmado';
    }

    private static function shipping_label($offer) {
        $mode = (string)($offer['shipping_mode'] ?? 'unknown');
        if ('free' === $mode || 'included' === $mode) return 'Incluido';
        if ('separate' === $mode) return self::money($offer['shipping_price'] ?? null);
        return 'No confirmado';
    }

    private static function styles() {
        ?>
        <style>
        .seo-ojeador-wrap{max-width:1500px}.seo-ojeador-titlebar,.seo-ojeador-card-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start}.seo-ojeador-titlebar h1{margin-bottom:5px}.seo-ojeador-titlebar h1 span{font-size:13px;font-weight:500;color:#646970}.seo-ojeador-titlebar p,.seo-ojeador-card-head p{margin:0;color:#646970}.seo-ojeador-tabs{margin:18px 0}.seo-ojeador-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px 20px;margin:16px 0}.seo-ojeador-card-head h2{margin:0 0 4px}.seo-ojeador-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin:16px 0}.seo-ojeador-kpis.is-small{grid-template-columns:repeat(4,minmax(0,1fr))}.seo-ojeador-kpis>div{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px}.seo-ojeador-kpis strong{display:block;font-size:22px}.seo-ojeador-kpis span{color:#646970}.seo-ojeador-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.seo-ojeador-summary-grid>div{display:grid;gap:4px;padding:12px;background:#f6f7f7;border-radius:8px}.seo-ojeador-state{padding:5px 9px;border-radius:999px;background:#f0f0f1}.seo-ojeador-state.is-running{background:#e8f4fd;color:#005a9c}.seo-ojeador-progress{height:10px;background:#f0f0f1;border-radius:999px;overflow:hidden;margin:14px 0}.seo-ojeador-progress span{display:block;height:100%;background:#2271b1}.seo-ojeador-actions,.seo-ojeador-inline-form{display:flex;gap:12px;flex-wrap:wrap;align-items:end}.seo-ojeador-actions form,.seo-ojeador-inline-form label{display:flex;gap:8px;align-items:center}.seo-ojeador-inline-form label{flex:1;min-width:220px}.seo-ojeador-inline-form input[type=url],.seo-ojeador-inline-form input[type=text]{width:100%}.seo-ojeador-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.seo-ojeador-grid label{display:grid;gap:5px}.seo-ojeador-grid label:has(input[type=checkbox]){display:flex;align-items:center;gap:8px}.seo-ojeador-identity{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.seo-ojeador-identity span{background:#f6f7f7;border-radius:999px;padding:6px 10px}.seo-ojeador-table-wrap{overflow:auto}.seo-ojeador-table-wrap table{min-width:1000px}.seo-ojeador-inline-action{display:inline-block;margin:2px}.seo-ojeador-inline-action ._wpnonce,.seo-ojeador-inline-action input[name=_wp_http_referer]{display:none}.seo-ojeador-search{display:flex;gap:8px;margin:16px 0}.seo-ojeador-search input[type=search]{width:min(520px,100%)}
        @media(max-width:1000px){.seo-ojeador-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.seo-ojeador-summary-grid,.seo-ojeador-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:600px){.seo-ojeador-titlebar,.seo-ojeador-card-head{display:block}.seo-ojeador-titlebar>a,.seo-ojeador-card-head>a{margin-top:10px}.seo-ojeador-kpis,.seo-ojeador-kpis.is-small,.seo-ojeador-summary-grid,.seo-ojeador-grid{grid-template-columns:1fr}.seo-ojeador-actions form{width:100%;flex-wrap:wrap}.seo-ojeador-inline-form{display:grid}.seo-ojeador-tabs{overflow:auto;white-space:nowrap}.seo-ojeador-search{display:grid}.seo-ojeador-search input[type=search]{width:100%}}
        </style>
        <?php
    }
}
