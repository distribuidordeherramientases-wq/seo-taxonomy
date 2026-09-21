<?php
/**
 * Ojeador admin UI under SEO Taxonomy > Procesos > Ojeador.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.1.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Admin {
    public static function init() {
        if (function_exists('seo_processes_register_tab')) {
            seo_processes_register_tab('ojeador', 'Ojeador', array(__CLASS__, 'render'), 30);
        }
        add_action('admin_post_seo_ojeador_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_seo_ojeador_start', array(__CLASS__, 'start'));
        add_action('admin_post_seo_ojeador_stop', array(__CLASS__, 'stop'));
        add_action('admin_post_seo_ojeador_add_offer', array(__CLASS__, 'add_offer'));
        add_action('admin_post_seo_ojeador_offer_status', array(__CLASS__, 'offer_status'));
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'seo-taxonomy'));
        }
        check_admin_referer($action);
    }

    private static function redirect($args = array()) {
        $base = array('page' => 'seo-processes', 'tab' => 'ojeador');
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
        self::redirect(array('ojeador_notice' => 'settings_saved'));
    }

    public static function start() {
        self::guard('seo_ojeador_start');
        $product_id = absint($_POST['product_id'] ?? 0);
        $max_products = absint($_POST['max_products'] ?? 0);
        $result = SEO_Ojeador_Worker::start_run('manual', $max_products, $product_id, 'admin');
        if (is_wp_error($result)) {
            self::redirect(array('ojeador_error' => rawurlencode($result->get_error_message())));
        }
        self::redirect(array('ojeador_notice' => 'run_started'));
    }

    public static function stop() {
        self::guard('seo_ojeador_stop');
        SEO_Ojeador_Worker::stop_run();
        self::redirect(array('ojeador_notice' => 'run_stopped'));
    }

    public static function add_offer() {
        self::guard('seo_ojeador_add_offer');
        $product_id = absint($_POST['product_id'] ?? 0);
        $merchant = sanitize_text_field(wp_unslash($_POST['merchant_name'] ?? ''));
        $url = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
        if ($product_id < 1 || $url === '') {
            self::redirect(array('ojeador_error' => rawurlencode('Indica product_id y URL externa.')));
        }
        $result = SEO_Ojeador_Worker::inspect_manual_url($product_id, $merchant, $url);
        if (is_wp_error($result)) {
            self::redirect(array('object_id' => $product_id, 'ojeador_error' => rawurlencode($result->get_error_message())));
        }
        self::redirect(array('object_id' => $product_id, 'ojeador_notice' => 'offer_added'));
    }

    public static function offer_status() {
        self::guard('seo_ojeador_offer_status');
        $offer_id = absint($_POST['offer_id'] ?? 0);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? 'review'));
        $offer = SEO_Ojeador_DB::offer_row($offer_id);
        if ($offer) {
            SEO_Ojeador_DB::set_offer_status($offer_id, $status);
            global $wpdb;
            $object_id = absint($wpdb->get_var($wpdb->prepare(
                'SELECT object_id FROM ' . SEO_Ojeador_DB::table('products') . ' WHERE id=%d',
                absint($offer['ojeador_product_id'])
            )));
            self::redirect(array('object_id' => $object_id, 'ojeador_notice' => 'offer_status'));
        }
        self::redirect(array('ojeador_error' => rawurlencode('Oferta no encontrada.')));
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para acceder a esta pagina.', 'seo-taxonomy'));
        }
        SEO_Ojeador_DB::maybe_install();
        $settings = SEO_Ojeador_Worker::settings();
        $summary = SEO_Ojeador_DB::summary();
        $run = SEO_Ojeador_DB::active_run();
        if (!$run) {
            $run = SEO_Ojeador_DB::latest_run();
        }
        $object_id = absint($_GET['object_id'] ?? 0);
        ?>
        <div class="wrap seo-ojeador-wrap">
            <h1>Ojeador <span style="font-size:13px;font-weight:500;color:#646970">v<?php echo esc_html(defined('SEO_OJEADOR_VERSION') ? SEO_OJEADOR_VERSION : '0.1.0'); ?></span></h1>
            <?php if (function_exists('seo_processes_render_tabs')) { seo_processes_render_tabs('ojeador'); } ?>
            <?php self::notices(); ?>

            <div class="seo-ojeador-intro">
                <div><strong>Objetivo:</strong> observar ofertas externas del mismo producto, normalizar precio/IVA/transporte y conservar historico.</div>
                <div><strong>V0.1:</strong> usa URLs directas conocidas, refresca ofertas guardadas y reutiliza el catalogo de proveedores. La busqueda automatica en Internet queda preparada mediante <code>seo_ojeador_offer_candidates</code> para un adaptador posterior.</div>
            </div>

            <div class="seo-ojeador-kpis">
                <div><strong><?php echo esc_html(number_format_i18n($summary['products'])); ?></strong><span>Productos vigilados</span></div>
                <div><strong><?php echo esc_html(number_format_i18n($summary['offers'])); ?></strong><span>Ofertas activas</span></div>
                <div><strong><?php echo esc_html(number_format_i18n($summary['confirmed'])); ?></strong><span>Matches confirmados</span></div>
                <div><strong><?php echo esc_html(number_format_i18n($summary['review'])); ?></strong><span>Por revisar</span></div>
                <div><strong><?php echo esc_html(number_format_i18n($summary['fresh'])); ?></strong><span>Ofertas vigentes</span></div>
            </div>

            <?php self::render_run_panel($run, $settings); ?>
            <?php if ($object_id > 0) { self::render_product_detail($object_id); } ?>
            <?php self::render_recent_products(); ?>
            <?php self::render_settings($settings); ?>
        </div>
        <?php self::styles(); ?>
        <?php
    }

    private static function notices() {
        $notice = sanitize_key(wp_unslash($_GET['ojeador_notice'] ?? ''));
        $messages = array(
            'settings_saved' => 'Configuracion de Ojeador guardada.',
            'run_started' => 'Barrido iniciado. El Gestor de workers lo procesara por ventanas.',
            'run_stopped' => 'Barrido detenido por el usuario.',
            'offer_added' => 'URL externa inspeccionada y guardada.',
            'offer_status' => 'Estado de la coincidencia actualizado.',
        );
        if (isset($messages[$notice])) {
            echo '<div class="notice notice-success inline"><p>' . esc_html($messages[$notice]) . '</p></div>';
        }
        $error = trim((string) wp_unslash($_GET['ojeador_error'] ?? ''));
        if ($error !== '') {
            echo '<div class="notice notice-error inline"><p>' . esc_html(rawurldecode($error)) . '</p></div>';
        }
    }

    private static function render_run_panel($run, $settings) {
        $active = is_array($run) && in_array((string) ($run['status'] ?? ''), array('pending', 'running'), true);
        $processed = absint($run['processed_products'] ?? 0);
        $total = absint($run['total_candidates'] ?? 0);
        $percent = $total > 0 ? min(100, round(($processed / $total) * 100, 1)) : 0;
        ?>
        <section class="seo-ojeador-card">
            <div class="seo-ojeador-card-head"><div><h2>Barrido</h2><p>Empieza con un producto concreto o con un piloto corto antes de ampliar el catalogo.</p></div><strong class="seo-ojeador-state <?php echo $active ? 'is-running' : ''; ?>"><?php echo esc_html($run ? strtoupper((string) $run['status']) : 'SIN EJECUCIONES'); ?></strong></div>
            <?php if ($run) : ?>
                <div class="seo-ojeador-progress"><span style="width:<?php echo esc_attr((string) $percent); ?>%"></span></div>
                <p><strong><?php echo esc_html(number_format_i18n($processed)); ?> / <?php echo esc_html(number_format_i18n($total)); ?></strong> productos · <?php echo esc_html(number_format_i18n(absint($run['offers_seen'] ?? 0))); ?> ofertas · <?php echo esc_html(number_format_i18n(absint($run['errors_count'] ?? 0))); ?> errores.</p>
            <?php endif; ?>
            <div class="seo-ojeador-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="seo_ojeador_start">
                    <?php wp_nonce_field('seo_ojeador_start'); ?>
                    <label>Producto concreto <input type="number" name="product_id" min="1" placeholder="product_id"></label>
                    <button class="button button-primary" type="submit" <?php disabled($active); ?>>Escanear producto</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="seo_ojeador_start">
                    <?php wp_nonce_field('seo_ojeador_start'); ?>
                    <label>Piloto <input type="number" name="max_products" min="1" max="10000" value="<?php echo esc_attr((string) $settings['max_products_per_run']); ?>"></label>
                    <button class="button button-primary" type="submit" <?php disabled($active); ?>>Arrancar barrido</button>
                </form>
                <?php if ($active) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="seo_ojeador_stop">
                        <?php wp_nonce_field('seo_ojeador_stop'); ?>
                        <button class="button" type="submit">Detener</button>
                    </form>
                <?php endif; ?>
            </div>
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
        <section class="seo-ojeador-card">
            <div class="seo-ojeador-card-head"><div><h2><?php echo esc_html($product->get_name()); ?></h2><p>product_id <?php echo esc_html((string) $object_id); ?> · SKU <?php echo esc_html($product->get_sku() ?: '---'); ?></p></div><a class="button" href="<?php echo esc_url(get_edit_post_link($object_id)); ?>">Editar producto</a></div>
            <div class="seo-ojeador-identity">
                <span><b>GTIN</b> <?php echo esc_html($row['gtin'] ?? '---'); ?></span>
                <span><b>MPN</b> <?php echo esc_html($row['mpn'] ?? '---'); ?></span>
                <span><b>Marca</b> <?php echo esc_html($row['brand'] ?? '---'); ?></span>
                <span><b>Modelo</b> <?php echo esc_html($row['model'] ?? '---'); ?></span>
            </div>
            <div class="seo-ojeador-kpis is-small">
                <div><strong><?php echo esc_html(self::money($our_price)); ?></strong><span>Nuestro precio con IVA</span></div>
                <div><strong><?php echo esc_html(self::money($data['stats']['min'] ?? null)); ?></strong><span>Minimo observado</span></div>
                <div><strong><?php echo esc_html(self::money($median)); ?></strong><span>Mediana observada</span></div>
                <div><strong><?php echo null === $delta ? '---' : esc_html(number_format_i18n($delta, 1) . '%'); ?></strong><span>Diferencia vs mediana</span></div>
            </div>
            <p class="description">El precio externo solo es totalmente comparable cuando IVA y transporte estan confirmados. Si faltan, Ojeador lo conserva como comparacion parcial.</p>

            <h3>Ofertas observadas</h3>
            <div class="seo-ojeador-table-wrap"><table class="widefat striped"><thead><tr><th>Comercio</th><th>Precio</th><th>IVA</th><th>Transporte</th><th>Total</th><th>Stock</th><th>Match</th><th>Observado</th><th></th></tr></thead><tbody>
            <?php if (empty($data['offers'])) : ?>
                <tr><td colspan="9">Todavia no hay ofertas externas para este producto.</td></tr>
            <?php else : foreach ($data['offers'] as $offer) : ?>
                <tr>
                    <td><strong><?php echo esc_html($offer['merchant_name'] ?: $offer['source_key']); ?></strong><?php if (!empty($offer['url'])) : ?><br><a href="<?php echo esc_url($offer['url']); ?>" target="_blank" rel="noopener noreferrer">Abrir oferta</a><?php endif; ?></td>
                    <td><?php echo esc_html(self::money($offer['price_raw'])); ?></td>
                    <td><?php echo esc_html(self::vat_label($offer)); ?></td>
                    <td><?php echo esc_html(self::shipping_label($offer)); ?></td>
                    <td><strong><?php echo esc_html(self::money($offer['total_price'])); ?></strong></td>
                    <td><?php echo esc_html($offer['stock_status'] ?: '---'); ?></td>
                    <td><?php echo esc_html((string) $offer['match_status']); ?><br><small><?php echo esc_html((string) $offer['match_method']); ?> · <?php echo esc_html(number_format_i18n(((float) $offer['match_confidence']) * 100, 1)); ?>%</small></td>
                    <td><?php echo esc_html((string) $offer['observed_at']); ?></td>
                    <td><?php self::offer_status_buttons($offer); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody></table></div>

            <h3>Anadir URL externa</h3>
            <form class="seo-ojeador-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="seo_ojeador_add_offer">
                <input type="hidden" name="product_id" value="<?php echo esc_attr((string) $object_id); ?>">
                <?php wp_nonce_field('seo_ojeador_add_offer'); ?>
                <label>Comercio <input type="text" name="merchant_name" placeholder="TodoTaladros"></label>
                <label>URL producto <input type="url" name="url" required placeholder="https://..."></label>
                <button class="button button-primary" type="submit">Inspeccionar y guardar</button>
            </form>
        </section>
        <?php
    }

    private static function offer_status_buttons($offer) {
        foreach (array('confirmed' => 'Confirmar', 'review' => 'Revisar', 'rejected' => 'Descartar') as $status => $label) {
            if ((string) $offer['match_status'] === $status) {
                continue;
            }
            echo '<form style="display:inline-block;margin:2px" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="seo_ojeador_offer_status">';
            echo '<input type="hidden" name="offer_id" value="' . esc_attr((string) absint($offer['id'])) . '">';
            echo '<input type="hidden" name="status" value="' . esc_attr($status) . '">';
            wp_nonce_field('seo_ojeador_offer_status');
            echo '<button class="button button-small" type="submit">' . esc_html($label) . '</button></form>';
        }
    }

    private static function render_recent_products() {
        $rows = SEO_Ojeador_DB::recent_products(60);
        ?>
        <section class="seo-ojeador-card">
            <div class="seo-ojeador-card-head"><div><h2>Productos vigilados</h2><p>Ultimos productos procesados y rango de precio observado.</p></div></div>
            <div class="seo-ojeador-table-wrap"><table class="widefat striped"><thead><tr><th>Producto</th><th>Identidad</th><th>Nuestro precio</th><th>Ofertas</th><th>Min mercado</th><th>Max mercado</th><th>Ultimo barrido</th></tr></thead><tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="7">Todavia no hay productos vigilados.</td></tr>
            <?php else : foreach ($rows as $row) :
                $product = function_exists('wc_get_product') ? wc_get_product(absint($row['object_id'])) : null;
                $name = $product ? $product->get_name() : $row['canonical_name'];
                $our = $product ? self::our_gross_price($product) : null;
                ?>
                <tr>
                    <td><a href="<?php echo esc_url(add_query_arg(array('page'=>'seo-processes','tab'=>'ojeador','object_id'=>absint($row['object_id'])), admin_url('admin.php'))); ?>"><strong><?php echo esc_html($name); ?></strong></a><br><small>#<?php echo esc_html((string) absint($row['object_id'])); ?></small></td>
                    <td><?php echo esc_html($row['gtin'] ? 'GTIN ' . $row['gtin'] : ($row['mpn'] ? 'MPN ' . $row['mpn'] : ($row['model'] ? 'Modelo ' . $row['model'] : 'Debil'))); ?></td>
                    <td><?php echo esc_html(self::money($our)); ?></td>
                    <td><?php echo esc_html(number_format_i18n(absint($row['offer_count']))); ?></td>
                    <td><?php echo esc_html(self::money($row['market_min'])); ?></td>
                    <td><?php echo esc_html(self::money($row['market_max'])); ?></td>
                    <td><?php echo esc_html((string) ($row['last_scan_at'] ?: '---')); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody></table></div>
        </section>
        <?php
    }

    private static function render_settings($settings) {
        $next = wp_next_scheduled(SEO_Ojeador_Worker::CRON_HOOK);
        $next_text = $next ? wp_date('Y-m-d H:i', $next) : 'No programado';
        $days = array('Domingo','Lunes','Martes','Miercoles','Jueves','Viernes','Sabado');
        ?>
        <section class="seo-ojeador-card">
            <div class="seo-ojeador-card-head"><div><h2>Programacion y carga</h2><p>Por seguridad, el automatico esta desactivado de fabrica y no corre en STAGING salvo permiso expreso.</p></div><strong>Proxima: <?php echo esc_html($next_text); ?></strong></div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="seo_ojeador_save_settings">
                <?php wp_nonce_field('seo_ojeador_save_settings'); ?>
                <div class="seo-ojeador-grid">
                    <label><input type="checkbox" name="ojeador[auto_enabled]" value="1" <?php checked(!empty($settings['auto_enabled'])); ?>> Barrido automatico semanal</label>
                    <label><input type="checkbox" name="ojeador[allow_non_production]" value="1" <?php checked(!empty($settings['allow_non_production'])); ?>> Permitir automatico fuera de produccion</label>
                    <label>Dia <select name="ojeador[weekday]"><?php foreach ($days as $i => $day) : ?><option value="<?php echo esc_attr((string) $i); ?>" <?php selected((int)$settings['weekday'], $i); ?>><?php echo esc_html($day); ?></option><?php endforeach; ?></select></label>
                    <label>Hora <input type="number" name="ojeador[hour]" min="0" max="23" value="<?php echo esc_attr((string) $settings['hour']); ?>"></label>
                    <label>Minuto <input type="number" name="ojeador[minute]" min="0" max="59" value="<?php echo esc_attr((string) $settings['minute']); ?>"></label>
                    <label>Productos por barrido <input type="number" name="ojeador[max_products_per_run]" min="1" max="10000" value="<?php echo esc_attr((string) $settings['max_products_per_run']); ?>"></label>
                    <label>Productos por ventana <input type="number" name="ojeador[batch_products]" min="1" max="25" value="<?php echo esc_attr((string) $settings['batch_products']); ?>"></label>
                    <label>Max. ofertas por producto <input type="number" name="ojeador[max_offers_per_product]" min="1" max="20" value="<?php echo esc_attr((string) $settings['max_offers_per_product']); ?>"></label>
                    <label>Vigencia oferta (h) <input type="number" name="ojeador[freshness_hours]" min="1" max="720" value="<?php echo esc_attr((string) $settings['freshness_hours']); ?>"></label>
                    <label>Timeout URL (s) <input type="number" name="ojeador[request_timeout]" min="5" max="25" value="<?php echo esc_attr((string) $settings['request_timeout']); ?>"></label>
                    <label><input type="checkbox" name="ojeador[include_provider_catalog]" value="1" <?php checked(!empty($settings['include_provider_catalog'])); ?>> Usar catalogo de proveedores como fuente</label>
                    <label><input type="checkbox" name="ojeador[refresh_existing_urls]" value="1" <?php checked(!empty($settings['refresh_existing_urls'])); ?>> Refrescar URLs externas ya guardadas</label>
                </div>
                <p><button class="button button-primary" type="submit">Guardar configuracion</button></p>
            </form>
        </section>
        <?php
    }

    private static function our_gross_price($product) {
        if (!$product) {
            return null;
        }
        $price = (float) $product->get_price();
        if ($price <= 0) {
            return null;
        }
        if (function_exists('wc_get_price_including_tax')) {
            return (float) wc_get_price_including_tax($product, array('price' => $price));
        }
        return $price;
    }

    private static function money($value) {
        if ($value === null || $value === '') {
            return '---';
        }
        return number_format_i18n((float) $value, 2) . ' EUR';
    }

    private static function vat_label($offer) {
        $mode = (string) ($offer['vat_mode'] ?? 'unknown');
        $rate = $offer['vat_rate'] ?? null;
        if ('included' === $mode) {
            return 'Incluido' . ($rate !== null && $rate !== '' ? ' (' . number_format_i18n((float) $rate, 1) . '%)' : '');
        }
        if ('excluded' === $mode) {
            return 'No incluido' . ($rate !== null && $rate !== '' ? ' (' . number_format_i18n((float) $rate, 1) . '%)' : '');
        }
        return 'No confirmado';
    }

    private static function shipping_label($offer) {
        $mode = (string) ($offer['shipping_mode'] ?? 'unknown');
        if ('free' === $mode || 'included' === $mode) {
            return 'Incluido';
        }
        if ('separate' === $mode) {
            return self::money($offer['shipping_price'] ?? null);
        }
        return 'No confirmado';
    }

    private static function styles() {
        ?>
        <style>
            .seo-ojeador-wrap{max-width:1500px}.seo-ojeador-intro,.seo-ojeador-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px 20px;margin:16px 0}.seo-ojeador-intro{display:grid;gap:8px}.seo-ojeador-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:16px 0}.seo-ojeador-kpis.is-small{grid-template-columns:repeat(4,minmax(0,1fr))}.seo-ojeador-kpis>div{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px}.seo-ojeador-kpis strong{display:block;font-size:22px}.seo-ojeador-kpis span{color:#646970}.seo-ojeador-card-head{display:flex;gap:20px;justify-content:space-between;align-items:flex-start}.seo-ojeador-card-head h2{margin:0 0 4px}.seo-ojeador-card-head p{margin:0;color:#646970}.seo-ojeador-state{padding:5px 9px;border-radius:999px;background:#f0f0f1}.seo-ojeador-state.is-running{background:#e8f4fd;color:#005a9c}.seo-ojeador-progress{height:10px;background:#f0f0f1;border-radius:999px;overflow:hidden;margin:14px 0}.seo-ojeador-progress span{display:block;height:100%;background:#2271b1}.seo-ojeador-actions,.seo-ojeador-inline-form{display:flex;gap:12px;flex-wrap:wrap;align-items:end}.seo-ojeador-actions form,.seo-ojeador-inline-form label{display:flex;gap:8px;align-items:center}.seo-ojeador-inline-form label{flex:1;min-width:220px}.seo-ojeador-inline-form input[type=url],.seo-ojeador-inline-form input[type=text]{width:100%}.seo-ojeador-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.seo-ojeador-grid label{display:grid;gap:5px}.seo-ojeador-grid label:has(input[type=checkbox]){display:flex;align-items:center;gap:8px}.seo-ojeador-identity{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.seo-ojeador-identity span{background:#f6f7f7;border-radius:999px;padding:6px 10px}.seo-ojeador-table-wrap{overflow:auto}.seo-ojeador-table-wrap table{min-width:980px}@media(max-width:900px){.seo-ojeador-kpis,.seo-ojeador-kpis.is-small,.seo-ojeador-grid{grid-template-columns:1fr 1fr}.seo-ojeador-card-head{display:block}.seo-ojeador-card-head>strong,.seo-ojeador-card-head>a{display:inline-block;margin-top:10px}}@media(max-width:560px){.seo-ojeador-kpis,.seo-ojeador-kpis.is-small,.seo-ojeador-grid{grid-template-columns:1fr}.seo-ojeador-actions form{width:100%;flex-wrap:wrap}.seo-ojeador-inline-form{display:grid}}
        </style>
        <?php
    }
}
