<?php
/**
 * Ojeador admin: one summary/comparison screen, no tabs.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.5.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Admin {
    const PAGE = 'seo-ojeador';
    private static $tools_filter_used = false;

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_page'), 90);
        add_filter('seo_tools_cards', array(__CLASS__, 'register_tool_card'));
        add_action('admin_footer', array(__CLASS__, 'tools_fallback_card'), 99);
        add_action('admin_post_seo_ojeador_settings_save', array(__CLASS__, 'save_settings'));
        add_action('admin_post_seo_ojeador_start', array(__CLASS__, 'start'));
        add_action('admin_post_seo_ojeador_stop', array(__CLASS__, 'stop'));
    }

    public static function register_page() {
        add_submenu_page(null, 'Ojeador', 'Ojeador', 'manage_options', self::PAGE, array(__CLASS__, 'render'));
    }

    public static function register_tool_card($tools) {
        self::$tools_filter_used = true;
        $tools = is_array($tools) ? $tools : array();
        foreach ($tools as $tool) {
            if (($tool['page'] ?? '') === self::PAGE) {
                return $tools;
            }
        }
        $tools[] = array(
            'title' => 'Ojeador',
            'icon' => 'dashicons-chart-line',
            'page' => self::PAGE,
            'desc' => 'Compara nuestros precios con ofertas del mismo producto encontradas en Google Shopping.',
        );
        return $tools;
    }

    public static function tools_fallback_card() {
        if (self::$tools_filter_used || !current_user_can('manage_options')) {
            return;
        }
        if (sanitize_key((string) ($_GET['page'] ?? '')) !== 'seo-tools') {
            return;
        }
        $url = admin_url('admin.php?page=' . self::PAGE);
        ?>
        <script id="seo-ojeador-tools-card-fallback">
        (function(){
            var grid=document.querySelector('.seo-tools-grid'); if(!grid) return;
            var href=<?php echo wp_json_encode($url); ?>;
            var links=grid.querySelectorAll('a[href]');
            for(var i=0;i<links.length;i++){if(links[i].href===href||links[i].getAttribute('href')===href)return;}
            var a=document.createElement('a');a.className='seo-tool-card-link';a.href=href;
            a.innerHTML='<div class="seo-tool-card"><span class="dashicons dashicons-chart-line"></span><h2>Ojeador</h2><p>Comparativa automática de precios en Google Shopping.</p><span class="button button-primary">Abrir</span></div>';
            grid.appendChild(a);
        })();
        </script>
        <?php
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'seo-taxonomy'));
        }
        check_admin_referer($action);
    }

    private static function redirect($args = array()) {
        wp_safe_redirect(add_query_arg(array_merge(array('page'=>self::PAGE), $args), admin_url('admin.php')));
        exit;
    }

    public static function save_settings() {
        self::guard('seo_ojeador_settings_save');
        $raw = isset($_POST['ojeador']) && is_array($_POST['ojeador']) ? wp_unslash($_POST['ojeador']) : array();
        $raw['auto_enabled'] = empty($raw['auto_enabled']) ? 0 : 1;
        SEO_Ojeador_Shopping::save_settings($raw);
        self::redirect(array('ojeador_notice'=>'settings_saved'));
    }

    public static function start() {
        self::guard('seo_ojeador_start');
        $result = SEO_Ojeador_Worker::start_run('admin');
        if (is_wp_error($result)) {
            self::redirect(array('ojeador_error'=>rawurlencode($result->get_error_message())));
        }
        self::redirect(array('ojeador_notice'=>'scan_started'));
    }

    public static function stop() {
        self::guard('seo_ojeador_stop');
        SEO_Ojeador_Worker::stop_run();
        self::redirect(array('ojeador_notice'=>'scan_stopped'));
    }

    private static function money($value, $currency='EUR') {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '—';
        }
        return number_format_i18n((float) $value, 2) . ' ' . esc_html($currency ?: 'EUR');
    }

    private static function signal($our, $median) {
        if ($our === null || $median === null || $median <= 0) {
            return array('Sin comparación','#646970',null);
        }
        $pct = (($our - $median) / $median) * 100;
        if ($pct < -3) {
            return array('Nuestro precio menor','#008a20',$pct);
        }
        if ($pct > 3) {
            return array('Nuestro precio mayor','#b32d2e',$pct);
        }
        return array('Precio similar','#996800',$pct);
    }

    public static function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $summary = SEO_Ojeador_DB::summary();
        $settings = SEO_Ojeador_Shopping::settings();
        $ready = SEO_Ojeador_Shopping::readiness();
        $run = SEO_Ojeador_DB::active_run();
        if (!$run) {
            $run = SEO_Ojeador_DB::latest_run();
        }
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $rows = SEO_Ojeador_DB::list_comparisons(array('limit'=>150,'search'=>$search));
        $notice = sanitize_key((string) ($_GET['ojeador_notice'] ?? ''));
        $error = isset($_GET['ojeador_error']) ? sanitize_text_field(rawurldecode((string) $_GET['ojeador_error'])) : '';
        ?>
        <div class="wrap seo-ojeador-v050">
            <style>
                .seo-ojeador-v050{max-width:1450px}.seo-ojeador-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}
                .seo-ojeador-sub{color:#646970;margin-top:5px;max-width:920px}.seo-ojeador-actions{display:flex;gap:8px;flex-wrap:wrap}.seo-ojeador-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin:18px 0}
                .seo-ojeador-card,.seo-ojeador-box{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:15px}.seo-ojeador-card strong{display:block;font-size:24px;line-height:1.1}.seo-ojeador-card span{color:#646970}
                .seo-ojeador-table{overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:8px}.seo-ojeador-table table{border:0;margin:0}.seo-ojeador-muted{color:#646970}.seo-ojeador-pill{font-weight:600}
                .seo-ojeador-offers summary{cursor:pointer}.seo-ojeador-offers ul{margin:8px 0 0 18px;min-width:280px}.seo-ojeador-offers li{margin:5px 0}.seo-ojeador-run{margin:12px 0 0;padding:10px 12px;background:#f6f7f7;border-radius:6px}
                .seo-ojeador-config{margin-top:18px}.seo-ojeador-config summary{cursor:pointer;font-weight:600;font-size:15px}.seo-ojeador-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px;margin-top:14px}.seo-ojeador-grid label{display:block}.seo-ojeador-grid input{width:100%;margin-top:5px}
            </style>

            <div class="seo-ojeador-head">
                <div>
                    <h1 style="margin-bottom:0">Ojeador <small style="font-size:14px;color:#646970">v<?php echo esc_html(SEO_OJEADOR_VERSION); ?></small></h1>
                    <p class="seo-ojeador-sub">Una sola función: recorrer automáticamente nuestros productos, consultar Google Shopping con su identidad y guardar unas pocas ofertas comparables para saber cómo está nuestro precio.</p>
                </div>
                <div class="seo-ojeador-actions">
                    <?php if (SEO_Ojeador_Worker::is_pending()) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_stop'); ?><input type="hidden" name="action" value="seo_ojeador_stop"><button class="button">Detener</button></form>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('seo_ojeador_start'); ?><input type="hidden" name="action" value="seo_ojeador_start"><button class="button button-primary" <?php disabled(is_wp_error($ready)); ?>>Continuar / actualizar comparativa</button></form>
                    <?php endif; ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=seo-processes')); ?>">Procesos</a>
                </div>
            </div>

            <?php if ($notice === 'settings_saved') : ?><div class="notice notice-success inline"><p>Configuración guardada.</p></div><?php endif; ?>
            <?php if ($notice === 'scan_started') : ?><div class="notice notice-success inline"><p>Ojeador está recorriendo automáticamente los productos pendientes. Los lotes internos no requieren intervención.</p></div><?php endif; ?>
            <?php if ($notice === 'scan_stopped') : ?><div class="notice notice-info inline"><p>Proceso detenido.</p></div><?php endif; ?>
            <?php if ($error !== '') : ?><div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
            <?php if (is_wp_error($ready)) : ?><div class="notice notice-warning inline"><p><strong>Falta conectar Google Shopping:</strong> <?php echo esc_html($ready->get_error_message()); ?></p></div><?php endif; ?>

            <div class="seo-ojeador-cards">
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['published']); ?></strong><span>Productos publicados</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['compared']); ?></strong><span>Con comparativa</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['offers']); ?></strong><span>Ofertas activas</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['cheaper']); ?></strong><span>Nuestro precio menor</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['aligned']); ?></strong><span>Precio similar ±3%</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['dearer']); ?></strong><span>Nuestro precio mayor</span></div>
                <div class="seo-ojeador-card"><strong><?php echo number_format_i18n($summary['due']); ?></strong><span>Pendientes de escanear</span></div>
            </div>

            <?php if ($run) : ?>
                <div class="seo-ojeador-run"><strong>Proceso:</strong> <?php echo esc_html((string) ($run['status'] ?? '—')); ?> · <?php echo number_format_i18n(absint($run['processed_products'] ?? 0)); ?> / <?php echo number_format_i18n(absint($run['total_candidates'] ?? 0)); ?> productos · <?php echo number_format_i18n(absint($run['offers_seen'] ?? 0)); ?> ofertas<?php if (!empty($run['last_error'])) echo ' · Último error: ' . esc_html((string) $run['last_error']); ?></div>
            <?php endif; ?>

            <div style="display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap;margin:18px 0 8px">
                <div><h2 style="margin:0">Comparativa</h2><p class="seo-ojeador-muted" style="margin:4px 0 0">Mínimo, mediana y máximo se calculan con las ofertas activas del mismo producto encontradas en Google Shopping.</p></div>
                <form method="get"><input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Producto, GTIN, MPN, marca"><button class="button">Buscar</button></form>
            </div>

            <div class="seo-ojeador-table"><table class="widefat striped"><thead><tr><th>Producto</th><th>Nuestro</th><th>Mínimo</th><th>Mediana</th><th>Máximo</th><th>Diferencia</th><th>Ofertas</th><th>Último escaneo</th></tr></thead><tbody>
            <?php if (!$rows) : ?><tr><td colspan="8">Todavía no hay comparativas. Conecta la API y deja que el worker recorra el catálogo.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row) :
                $our = SEO_Ojeador_DB::live_price(absint($row['object_id']));
                $min = is_numeric($row['market_min']) ? (float) $row['market_min'] : null;
                $median = is_numeric($row['market_median']) ? (float) $row['market_median'] : null;
                $max = is_numeric($row['market_max']) ? (float) $row['market_max'] : null;
                list($signal,$color,$pct) = self::signal($our,$median);
                $offers = SEO_Ojeador_DB::offers_for_object(absint($row['object_id']), 8);
                $currency = (string) ($row['currency'] ?: 'EUR');
            ?>
                <tr>
                    <td><strong><?php echo esc_html((string) ($row['post_title'] ?: $row['google_title'])); ?></strong><br><span class="seo-ojeador-muted">#<?php echo absint($row['object_id']); ?><?php if (!empty($row['gtin'])) echo ' · GTIN ' . esc_html((string) $row['gtin']); ?><?php if (!empty($row['mpn'])) echo ' · ' . esc_html((string) $row['mpn']); ?></span></td>
                    <td><strong><?php echo wp_kses_post(self::money($our,$currency)); ?></strong></td>
                    <td><?php echo wp_kses_post(self::money($min,$currency)); ?></td>
                    <td><strong><?php echo wp_kses_post(self::money($median,$currency)); ?></strong></td>
                    <td><?php echo wp_kses_post(self::money($max,$currency)); ?></td>
                    <td><span class="seo-ojeador-pill" style="color:<?php echo esc_attr($color); ?>"><?php echo esc_html($signal); ?></span><?php if ($pct !== null) echo '<br><span class="seo-ojeador-muted">' . esc_html(number_format_i18n($pct,1) . '%') . '</span>'; ?></td>
                    <td>
                        <?php if ($offers) : ?><details class="seo-ojeador-offers"><summary><?php echo number_format_i18n(count($offers)); ?> ofertas</summary><ul>
                            <?php foreach ($offers as $offer) : $price = is_numeric($offer['total_price']) ? (float) $offer['total_price'] : (float) $offer['price']; ?>
                                <li><strong><?php echo esc_html((string) $offer['merchant']); ?></strong> · <?php echo wp_kses_post(self::money($price,(string) $offer['currency'])); ?><?php if (!empty($offer['url'])) : ?> · <a href="<?php echo esc_url((string) $offer['url']); ?>" target="_blank" rel="noopener noreferrer">ver</a><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ul></details><?php else : echo '—'; endif; ?>
                    </td>
                    <td><?php echo esc_html((string) ($row['last_scan_at'] ?: '—')); ?><br><span class="seo-ojeador-muted"><?php echo esc_html((string) ($row['status'] ?: '')); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>

            <details class="seo-ojeador-box seo-ojeador-config" <?php echo is_wp_error($ready) ? 'open' : ''; ?>>
                <summary>Conexión Google Shopping</summary>
                <p class="seo-ojeador-muted">Ojeador no visita las tiendas. Consulta resultados estructurados de Google Shopping mediante SerpApi y guarda proveedor, precio y enlace. Recomendado: guardar la API key en <code>wp-config.php</code>.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('seo_ojeador_settings_save'); ?><input type="hidden" name="action" value="seo_ojeador_settings_save">
                    <div class="seo-ojeador-grid">
                        <label><strong>SerpApi API key</strong><input type="password" name="ojeador[api_key]" value="" placeholder="<?php echo $settings['api_key'] !== '' ? esc_attr('Configurada · dejar vacío para conservar') : esc_attr('Pegar API key'); ?>" <?php disabled(defined('SEO_OJEADOR_SERPAPI_KEY')); ?>></label>
                        <label><strong>Actualizar cada</strong><input type="number" min="6" max="720" name="ojeador[interval_hours]" value="<?php echo absint($settings['interval_hours']); ?>"><small>horas por producto; 168 = semanal.</small></label>
                        <label><strong>Productos por paso del worker</strong><input type="number" min="1" max="20" name="ojeador[batch_size]" value="<?php echo absint($settings['batch_size']); ?>"><small>Es interno y automático; no requiere ir agregando productos manualmente.</small></label>
                        <label><strong>Máximo de ofertas por producto</strong><input type="number" min="3" max="13" name="ojeador[max_offers]" value="<?php echo absint($settings['max_offers']); ?>"></label>
                    </div>
                    <p><label><input type="checkbox" name="ojeador[auto_enabled]" value="1" <?php checked(!empty($settings['auto_enabled'])); ?>> Mantener la comparativa actualizada automáticamente</label></p>
                    <p><button class="button button-primary">Guardar</button></p>
                </form>
                <pre style="white-space:pre-wrap;background:#f6f7f7;padding:10px;border-radius:4px">define('SEO_OJEADOR_SERPAPI_KEY', 'tu_api_key');</pre>
            </details>
        </div>
        <?php
    }
}
