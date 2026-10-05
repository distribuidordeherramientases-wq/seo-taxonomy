<?php
/**
 * Marketing - Informes de rendimiento.
 *
 * Une campañas, publicaciones sociales, visitas firmadas y pedidos WooCommerce
 * sin guardar datos personales adicionales.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_MARKETING_PERFORMANCE_COOKIE')) {
    define('SEO_MARKETING_PERFORMANCE_COOKIE', 'seo_marketing_attribution');
}
if (!defined('SEO_MARKETING_PERFORMANCE_SESSION_KEY')) {
    define('SEO_MARKETING_PERFORMANCE_SESSION_KEY', 'seo_marketing_attribution');
}
if (!defined('SEO_MARKETING_PERFORMANCE_WINDOW_DAYS')) {
    define('SEO_MARKETING_PERFORMANCE_WINDOW_DAYS', 30);
}

/**
 * @param string $value
 * @return string
 */
function seo_marketing_performance_b64_encode($value)
{
    return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
}

/**
 * @param string $value
 * @return string
 */
function seo_marketing_performance_b64_decode($value)
{
    $value = strtr((string) $value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) {
        $value .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($value, true);
    return is_string($decoded) ? $decoded : '';
}

/**
 * @param array $payload
 * @return string
 */
function seo_marketing_performance_encode_attribution($payload)
{
    $payload = is_array($payload) ? $payload : array();
    $json = wp_json_encode($payload);
    $body = seo_marketing_performance_b64_encode($json);
    $sig = substr(hash_hmac('sha256', $body, wp_salt('nonce')), 0, 24);
    return $body . '.' . $sig;
}

/**
 * @param string $token
 * @return array
 */
function seo_marketing_performance_decode_attribution($token)
{
    $parts = explode('.', (string) $token, 2);
    if (count($parts) !== 2) {
        return array();
    }
    list($body, $sig) = $parts;
    $expected = substr(hash_hmac('sha256', $body, wp_salt('nonce')), 0, 24);
    if (!hash_equals($expected, $sig)) {
        return array();
    }
    $payload = json_decode(seo_marketing_performance_b64_decode($body), true);
    if (!is_array($payload) || absint($payload['expires_at'] ?? 0) < time()) {
        return array();
    }
    return $payload;
}

/**
 * Guarda solo identificadores de atribución. No almacena PII.
 *
 * @param array $payload
 */
function seo_marketing_performance_store_attribution($payload)
{
    $payload = is_array($payload) ? $payload : array();
    $payload['source'] = sanitize_key((string) ($payload['source'] ?? ''));
    $payload['provider'] = sanitize_key((string) ($payload['provider'] ?? ''));
    $payload['publication_id'] = absint($payload['publication_id'] ?? 0);
    $payload['campaign_id'] = absint($payload['campaign_id'] ?? 0);
    $payload['content_id'] = absint($payload['content_id'] ?? 0);
    $payload['touched_at'] = time();
    $payload['expires_at'] = time() + (SEO_MARKETING_PERFORMANCE_WINDOW_DAYS * DAY_IN_SECONDS);

    if (function_exists('WC') && WC() && WC()->session) {
        WC()->session->set(SEO_MARKETING_PERFORMANCE_SESSION_KEY, $payload);
    }

    $token = seo_marketing_performance_encode_attribution($payload);
    if (function_exists('wc_setcookie')) {
        wc_setcookie(
            SEO_MARKETING_PERFORMANCE_COOKIE,
            $token,
            $payload['expires_at'],
            is_ssl(),
            true
        );
    } elseif (!headers_sent()) {
        setcookie(
            SEO_MARKETING_PERFORMANCE_COOKIE,
            $token,
            array(
                'expires' => $payload['expires_at'],
                'path' => COOKIEPATH ?: '/',
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
    }
}

/**
 * @return array
 */
function seo_marketing_performance_current_attribution()
{
    if (function_exists('WC') && WC() && WC()->session) {
        $session = WC()->session->get(SEO_MARKETING_PERFORMANCE_SESSION_KEY);
        if (is_array($session) && absint($session['expires_at'] ?? 0) >= time()) {
            return $session;
        }
    }

    if (!empty($_COOKIE[SEO_MARKETING_PERFORMANCE_COOKIE])) {
        return seo_marketing_performance_decode_attribution(
            sanitize_text_field(wp_unslash($_COOKIE[SEO_MARKETING_PERFORMANCE_COOKIE]))
        );
    }
    return array();
}

/**
 * Limpia la atribución después de asociarla a un pedido.
 */
function seo_marketing_performance_clear_attribution()
{
    if (function_exists('WC') && WC() && WC()->session) {
        WC()->session->__unset(SEO_MARKETING_PERFORMANCE_SESSION_KEY);
    }
    if (function_exists('wc_setcookie')) {
        wc_setcookie(SEO_MARKETING_PERFORMANCE_COOKIE, '', time() - HOUR_IN_SECONDS, is_ssl(), true);
    } elseif (!headers_sent()) {
        setcookie(SEO_MARKETING_PERFORMANCE_COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
    }
}

/**
 * Recupera campaign_id en publicaciones antiguas que todavía no lo tengan.
 *
 * @param object $publication
 * @return int
 */
function seo_marketing_performance_campaign_id_from_publication($publication)
{
    $campaign_id = absint($publication->campaign_id ?? 0);
    if ($campaign_id > 0) {
        return $campaign_id;
    }
    if ((string) ($publication->content_type ?? '') !== 'campaign_product') {
        return 0;
    }

    $target_url = (string) ($publication->target_url ?? '');
    if ($target_url === '') {
        return 0;
    }
    $query = array();
    parse_str((string) wp_parse_url($target_url, PHP_URL_QUERY), $query);
    $campaign_key = sanitize_title((string) ($query['utm_campaign'] ?? ''));
    if ($campaign_key === '' || !function_exists('seo_marketing_campaigns_tables')) {
        return 0;
    }

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    return absint(
        $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$tables['campaigns']} WHERE campaign_key = %s LIMIT 1",
                $campaign_key
            )
        )
    );
}

/**
 * Recibe las visitas sociales que ya han sido validadas por el tracking firmado.
 *
 * @param object $publication
 */
function seo_marketing_performance_capture_social_touch($publication)
{
    if (!is_object($publication)) {
        return;
    }

    seo_marketing_performance_store_attribution(
        array(
            'source' => 'social',
            'provider' => sanitize_key((string) ($publication->provider ?? '')),
            'publication_id' => absint($publication->id ?? 0),
            'campaign_id' => seo_marketing_performance_campaign_id_from_publication($publication),
            'content_id' => absint($publication->content_id ?? 0),
        )
    );
}
add_action('seo_social_network_attributed_visit', 'seo_marketing_performance_capture_social_touch', 10, 1);

/**
 * @param int $campaign_id
 * @param int $product_id
 * @return string
 */
function seo_marketing_performance_campaign_signature($campaign_id, $product_id)
{
    return substr(
        hash_hmac(
            'sha256',
            'campaign|' . absint($campaign_id) . '|' . absint($product_id),
            wp_salt('nonce')
        ),
        0,
        16
    );
}

/**
 * Añade tracking al enlace del producto mostrado en la franja pública.
 *
 * @param object $campaign
 * @param int    $product_id
 * @param string $url
 * @return string
 */
function seo_marketing_performance_campaign_url($campaign, $product_id, $url)
{
    $campaign_id = absint(is_object($campaign) ? ($campaign->id ?? 0) : 0);
    $product_id = absint($product_id);
    $url = esc_url_raw((string) $url);
    if (!$campaign_id || !$product_id || $url === '') {
        return $url;
    }

    $reference = $campaign_id . '.' . $product_id . '.' . seo_marketing_performance_campaign_signature($campaign_id, $product_id);

    // No se añaden UTM a enlaces internos: hacerlo alteraría la atribución de
    // Google Analytics. El identificador firmado es suficiente para medir la
    // franja sin contaminar la fuente de adquisición original.
    return add_query_arg(
        array('seo_campaign_ref' => $reference),
        $url
    );
}

/**
 * Cuenta una visita desde la franja pública y crea atribución de última interacción.
 */
function seo_marketing_performance_capture_campaign_visit()
{
    if (is_admin() || empty($_GET['seo_campaign_ref']) || !function_exists('seo_marketing_campaigns_tables')) {
        return;
    }

    $raw = sanitize_text_field(wp_unslash($_GET['seo_campaign_ref']));
    if (!preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{16})$/', $raw, $matches)) {
        return;
    }

    $campaign_id = absint($matches[1]);
    $product_id = absint($matches[2]);
    $signature = (string) $matches[3];
    if (!$campaign_id || !$product_id || !hash_equals(seo_marketing_performance_campaign_signature($campaign_id, $product_id), $signature)) {
        return;
    }

    $queried_id = absint(get_queried_object_id());
    if ($queried_id !== $product_id) {
        return;
    }

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$tables['products']}
             SET visits = visits + 1,
                 last_visit_at = %s,
                 updated_at = %s
             WHERE campaign_id = %d
               AND product_id = %d",
            current_time('mysql'),
            current_time('mysql'),
            $campaign_id,
            $product_id
        )
    );

    if ($updated) {
        seo_marketing_performance_store_attribution(
            array(
                'source' => 'campaign_strip',
                'provider' => '',
                'publication_id' => 0,
                'campaign_id' => $campaign_id,
                'content_id' => $product_id,
            )
        );
    }
}
add_action('template_redirect', 'seo_marketing_performance_capture_campaign_visit', 2);

/**
 * @param WC_Order $order
 */
function seo_marketing_performance_attach_order_attribution($order)
{
    if (!is_a($order, 'WC_Order')) {
        return;
    }
    if ($order->get_meta('_seo_marketing_attribution_version', true)) {
        return;
    }

    $touch = seo_marketing_performance_current_attribution();
    if (!$touch) {
        return;
    }

    $order->update_meta_data('_seo_marketing_attribution_version', '1');
    $order->update_meta_data('_seo_marketing_source', sanitize_key((string) ($touch['source'] ?? '')));
    $order->update_meta_data('_seo_marketing_provider', sanitize_key((string) ($touch['provider'] ?? '')));
    $order->update_meta_data('_seo_marketing_publication_id', absint($touch['publication_id'] ?? 0));
    $order->update_meta_data('_seo_marketing_campaign_id', absint($touch['campaign_id'] ?? 0));
    $order->update_meta_data('_seo_marketing_content_id', absint($touch['content_id'] ?? 0));
    $order->update_meta_data('_seo_marketing_touch_at', absint($touch['touched_at'] ?? 0));

    if (absint($touch['campaign_id'] ?? 0) > 0 && function_exists('seo_marketing_campaigns_tables')) {
        global $wpdb;
        $tables = seo_marketing_campaigns_tables();
        $key = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT campaign_key FROM {$tables['campaigns']} WHERE id = %d LIMIT 1",
                absint($touch['campaign_id'])
            )
        );
        if ($key !== '') {
            $order->update_meta_data('_seo_marketing_campaign_key', sanitize_title($key));
        }
    }

    seo_marketing_performance_clear_attribution();
}
add_action('woocommerce_checkout_create_order', 'seo_marketing_performance_attach_order_attribution', 20, 1);
add_action('woocommerce_store_api_checkout_update_order_meta', 'seo_marketing_performance_attach_order_attribution', 20, 1);

/**
 * @param int $start_ts
 * @param int $end_ts
 * @return WC_Order[]
 */
function seo_marketing_performance_paid_orders_between($start_ts, $end_ts)
{
    if (!function_exists('wc_get_orders')) {
        return array();
    }

    $start_ts = absint($start_ts);
    $end_ts = absint($end_ts);
    if (!$start_ts || !$end_ts || $end_ts < $start_ts) {
        return array();
    }

    $cache_key = $start_ts . ':' . $end_ts;
    static $cache = array();
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }

    $statuses = function_exists('wc_get_is_paid_statuses')
        ? wc_get_is_paid_statuses()
        : array('processing', 'completed');

    $orders = wc_get_orders(
        array(
            'status' => $statuses,
            'limit' => -1,
            'return' => 'objects',
            'date_created' => $start_ts . '...' . $end_ts,
            'orderby' => 'date',
            'order' => 'ASC',
        )
    );

    $cache[$cache_key] = is_array($orders) ? $orders : array();
    return $cache[$cache_key];
}

/**
 * @param float $amount
 * @return string
 */
function seo_marketing_performance_money($amount)
{
    return function_exists('wc_price')
        ? wp_strip_all_tags(wc_price((float) $amount))
        : number_format_i18n((float) $amount, 2) . ' €';
}

/**
 * @param int $days
 * @return array
 */
function seo_marketing_performance_social_report($days = 28)
{
    global $wpdb;
    $days = in_array(absint($days), array(7, 28, 90, 365), true) ? absint($days) : 28;
    $table = function_exists('seo_social_network_publications_table')
        ? seo_social_network_publications_table()
        : $wpdb->prefix . 'seo_social_publications';

    $since_ts = current_time('timestamp') - ($days * DAY_IN_SECONDS);
    $since = wp_date('Y-m-d H:i:s', $since_ts, wp_timezone());

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE published_at >= %s
               AND status = 'published'
             ORDER BY published_at DESC",
            $since
        )
    );

    $providers = array();
    $summary = array(
        'publications' => 0,
        'clicks' => 0,
        'reactions' => 0,
        'comments' => 0,
        'shares' => 0,
        'impressions' => 0,
        'reach' => 0,
        'orders' => 0,
        'revenue' => 0.0,
    );
    $by_publication = array();

    foreach ($rows as $row) {
        $provider = sanitize_key((string) $row->provider);
        if (!isset($providers[$provider])) {
            $providers[$provider] = array(
                'provider' => $provider,
                'publications' => 0,
                'clicks' => 0,
                'reactions' => 0,
                'comments' => 0,
                'shares' => 0,
                'impressions' => 0,
                'reach' => 0,
                'orders' => 0,
                'revenue' => 0.0,
            );
        }
        $providers[$provider]['publications']++;
        foreach (array('clicks','reactions','comments','shares','impressions','reach') as $metric) {
            $value = absint($row->{$metric} ?? 0);
            $providers[$provider][$metric] += $value;
            $summary[$metric] += $value;
        }
        $summary['publications']++;

        $by_publication[absint($row->id)] = array(
            'row' => $row,
            'orders' => 0,
            'revenue' => 0.0,
        );
    }

    foreach (seo_marketing_performance_paid_orders_between($since_ts, current_time('timestamp')) as $order) {
        $publication_id = absint($order->get_meta('_seo_marketing_publication_id', true));
        if (!$publication_id || !isset($by_publication[$publication_id])) {
            continue;
        }
        $provider = sanitize_key((string) $order->get_meta('_seo_marketing_provider', true));
        if ($provider === '') {
            $provider = sanitize_key((string) $by_publication[$publication_id]['row']->provider);
        }
        $amount = (float) $order->get_total();

        $by_publication[$publication_id]['orders']++;
        $by_publication[$publication_id]['revenue'] += $amount;
        $summary['orders']++;
        $summary['revenue'] += $amount;
        if (isset($providers[$provider])) {
            $providers[$provider]['orders']++;
            $providers[$provider]['revenue'] += $amount;
        }
    }

    foreach ($providers as &$provider) {
        $provider['conversion'] = $provider['clicks'] > 0
            ? round(($provider['orders'] / $provider['clicks']) * 100, 2)
            : 0;
        $provider['engagement'] = $provider['reactions'] + $provider['comments'] + $provider['shares'];
    }
    unset($provider);

    $summary['conversion'] = $summary['clicks'] > 0
        ? round(($summary['orders'] / $summary['clicks']) * 100, 2)
        : 0;
    $summary['engagement'] = $summary['reactions'] + $summary['comments'] + $summary['shares'];

    return array(
        'days' => $days,
        'summary' => $summary,
        'providers' => array_values($providers),
        'publications' => array_values($by_publication),
    );
}

/**
 * Métricas de una campaña.
 *
 * @param object $campaign
 * @return array
 */
function seo_marketing_performance_campaign_metrics($campaign)
{
    global $wpdb;
    $campaign_id = absint($campaign->id ?? 0);
    $tables = seo_marketing_campaigns_tables();
    $social_table = function_exists('seo_social_network_publications_table')
        ? seo_social_network_publications_table()
        : $wpdb->prefix . 'seo_social_publications';

    $product_rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT cp.*, p.post_title
             FROM {$tables['products']} cp
             LEFT JOIN {$wpdb->posts} p ON p.ID = cp.product_id
             WHERE cp.campaign_id = %d
             ORDER BY cp.position ASC, cp.id ASC",
            $campaign_id
        )
    );
    $product_ids = array_values(array_unique(array_filter(array_map(static function ($row) {
        return absint($row->product_id ?? 0);
    }, $product_rows))));
    $product_map = array_fill_keys($product_ids, true);

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Tabla social interna; campaign_id enlazado mediante $wpdb->prepare().
    $social_sql = $wpdb->prepare(
        "SELECT
            COUNT(*) publications,
            COALESCE(SUM(clicks),0) clicks,
            COALESCE(SUM(reactions),0) reactions,
            COALESCE(SUM(comments),0) comments,
            COALESCE(SUM(shares),0) shares,
            COALESCE(SUM(impressions),0) impressions,
            COALESCE(SUM(reach),0) reach
         FROM {$social_table}
         WHERE campaign_id = %d
           AND status = 'published'",
        $campaign_id
    );
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- $social_sql es el resultado de $wpdb->prepare().
    $social = $wpdb->get_row($social_sql, ARRAY_A);
    $social = is_array($social) ? $social : array();

    $strip_visits = 0;
    foreach ($product_rows as $row) {
        $strip_visits += absint($row->visits ?? 0);
    }

    $start_ts = function_exists('seo_marketing_campaigns_timestamp')
        ? seo_marketing_campaigns_timestamp((string) $campaign->start_at)
        : strtotime((string) $campaign->start_at);
    $end_ts = function_exists('seo_marketing_campaigns_timestamp')
        ? seo_marketing_campaigns_timestamp((string) $campaign->end_at)
        : strtotime((string) $campaign->end_at);
    $effective_end = min($end_ts ?: current_time('timestamp'), current_time('timestamp'));

    $observed_order_ids = array();
    $observed_units = 0;
    $observed_revenue = 0.0;
    $attributed_order_ids = array();
    $attributed_revenue = 0.0;
    $product_metrics = array();
    foreach ($product_rows as $row) {
        $pid = absint($row->product_id);
        $product_metrics[$pid] = array(
            'product_id' => $pid,
            'name' => (string) ($row->post_title ?: ('Producto #' . $pid)),
            'strip_visits' => absint($row->visits ?? 0),
            'social_clicks' => 0,
            'observed_units' => 0,
            'observed_revenue' => 0.0,
            'attributed_orders' => 0,
            'attributed_revenue' => 0.0,
        );
    }

    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Tabla social interna; campaign_id enlazado mediante $wpdb->prepare().
    $social_products_sql = $wpdb->prepare(
        "SELECT content_id,
                COALESCE(SUM(clicks),0) clicks
         FROM {$social_table}
         WHERE campaign_id = %d
           AND status = 'published'
         GROUP BY content_id",
        $campaign_id
    );
    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- $social_products_sql es el resultado de $wpdb->prepare().
    $social_by_product = (array) $wpdb->get_results($social_products_sql, ARRAY_A);
    foreach ($social_by_product as $row) {
        $pid = absint($row['content_id'] ?? 0);
        if (isset($product_metrics[$pid])) {
            $product_metrics[$pid]['social_clicks'] = absint($row['clicks'] ?? 0);
        }
    }

    if ($start_ts && $effective_end >= $start_ts) {
        foreach (seo_marketing_performance_paid_orders_between($start_ts, $effective_end) as $order) {
            $order_has_campaign_product = false;
            $order_campaign_line_revenue = 0.0;

            foreach ($order->get_items('line_item') as $item) {
                $pid = absint($item->get_product_id());
                if (!$pid || !isset($product_map[$pid])) {
                    continue;
                }
                $order_has_campaign_product = true;
                $qty = (float) $item->get_quantity();
                $line_revenue = (float) $item->get_total() + (float) $item->get_total_tax();

                $observed_units += $qty;
                $observed_revenue += $line_revenue;
                $order_campaign_line_revenue += $line_revenue;
                if (isset($product_metrics[$pid])) {
                    $product_metrics[$pid]['observed_units'] += $qty;
                    $product_metrics[$pid]['observed_revenue'] += $line_revenue;
                }
            }

            if ($order_has_campaign_product) {
                $observed_order_ids[$order->get_id()] = true;
            }

            if (absint($order->get_meta('_seo_marketing_campaign_id', true)) === $campaign_id) {
                $attributed_order_ids[$order->get_id()] = true;
                $attributed_revenue += (float) $order->get_total();

                $touch_product = absint($order->get_meta('_seo_marketing_content_id', true));
                if ($touch_product && isset($product_metrics[$touch_product])) {
                    $product_metrics[$touch_product]['attributed_orders']++;
                    $product_metrics[$touch_product]['attributed_revenue'] += (float) $order->get_total();
                }
            }
        }
    }

    $social_clicks = absint($social['clicks'] ?? 0);
    $tracked_visits = $strip_visits + $social_clicks;
    $attributed_orders = count($attributed_order_ids);

    return array(
        'campaign_id' => $campaign_id,
        'products' => count($product_ids),
        'publications' => absint($social['publications'] ?? 0),
        'social_clicks' => $social_clicks,
        'strip_visits' => $strip_visits,
        'tracked_visits' => $tracked_visits,
        'impressions' => absint($social['impressions'] ?? 0),
        'reach' => absint($social['reach'] ?? 0),
        'reactions' => absint($social['reactions'] ?? 0),
        'comments' => absint($social['comments'] ?? 0),
        'shares' => absint($social['shares'] ?? 0),
        'engagement' => absint($social['reactions'] ?? 0) + absint($social['comments'] ?? 0) + absint($social['shares'] ?? 0),
        'attributed_orders' => $attributed_orders,
        'attributed_revenue' => round($attributed_revenue, 2),
        'conversion' => $tracked_visits > 0 ? round(($attributed_orders / $tracked_visits) * 100, 2) : 0,
        'observed_orders' => count($observed_order_ids),
        'observed_units' => $observed_units,
        'observed_revenue' => round($observed_revenue, 2),
        'product_metrics' => array_values($product_metrics),
    );
}

/**
 * @return array
 */
function seo_marketing_performance_campaign_report()
{
    global $wpdb;
    if (!function_exists('seo_marketing_campaigns_tables')) {
        return array();
    }
    $tables = seo_marketing_campaigns_tables();

    $campaigns = (array) $wpdb->get_results(
        "SELECT *
         FROM {$tables['campaigns']}
         ORDER BY start_at DESC, id DESC
         LIMIT 40"
    );

    $rows = array();
    foreach ($campaigns as $campaign) {
        $rows[] = array(
            'campaign' => $campaign,
            'metrics' => seo_marketing_performance_campaign_metrics($campaign),
        );
    }
    return $rows;
}

/**
 * @param int $days
 * @param string $view
 * @return string
 */
function seo_marketing_performance_admin_url($days = 28, $view = 'campaigns')
{
    return add_query_arg(
        array(
            'page' => 'seo-menu-marketing',
            'tab' => 'reports',
            'report_view' => sanitize_key($view),
            'report_days' => absint($days),
        ),
        admin_url('admin.php')
    );
}

/**
 * Render principal del informe de Marketing.
 */
function seo_marketing_performance_render_tab()
{
    $view = isset($_GET['report_view']) ? sanitize_key(wp_unslash($_GET['report_view'])) : 'campaigns';
    if (!in_array($view, array('campaigns','social'), true)) {
        $view = 'campaigns';
    }
    $days = isset($_GET['report_days']) ? absint($_GET['report_days']) : 28;
    if (!in_array($days, array(7,28,90,365), true)) {
        $days = 28;
    }

    echo '<style>
        .seo-mkt-report-nav{display:flex;gap:8px;margin:0 0 16px;flex-wrap:wrap}.seo-mkt-report-nav a{display:inline-flex;padding:8px 12px;border:1px solid #c3c4c7;border-radius:6px;background:#fff;text-decoration:none;font-weight:600}.seo-mkt-report-nav a.is-active{background:#2271b1;border-color:#2271b1;color:#fff}
        .seo-mkt-report-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:10px;margin:0 0 18px}.seo-mkt-report-kpi{padding:14px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.seo-mkt-report-kpi strong{display:block;font-size:24px;line-height:1.1}.seo-mkt-report-kpi span{display:block;margin-top:5px;color:#646970;font-size:12px}
        .seo-mkt-report-table{width:100%;border-collapse:collapse}.seo-mkt-report-table th,.seo-mkt-report-table td{padding:9px 8px;border-bottom:1px solid #e5e5e5;text-align:left;vertical-align:top}.seo-mkt-report-table th{font-size:11px;text-transform:uppercase;color:#50575e}.seo-mkt-report-table td.is-number{text-align:right;white-space:nowrap}.seo-mkt-report-table .is-muted{color:#646970;font-size:11px}.seo-mkt-report-good{color:#176b2c;font-weight:700}.seo-mkt-report-zero{color:#8c8f94}.seo-mkt-report-filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px}.seo-mkt-report-filter a{padding:5px 9px;border-radius:999px;text-decoration:none;background:#f0f0f1}.seo-mkt-report-filter a.is-active{background:#1d2327;color:#fff}.seo-mkt-report-note{padding:10px 12px;border-left:4px solid #72aee6;background:#f0f6fc;margin:12px 0}.seo-mkt-report-actions{display:flex;gap:8px;flex-wrap:wrap}
    </style>';

    echo '<div class="seo-mkt-report-nav">';
    echo '<a class="' . ($view === 'campaigns' ? 'is-active' : '') . '" href="' . esc_url(seo_marketing_performance_admin_url($days, 'campaigns')) . '">Campañas</a>';
    echo '<a class="' . ($view === 'social' ? 'is-active' : '') . '" href="' . esc_url(seo_marketing_performance_admin_url($days, 'social')) . '">Redes sociales</a>';
    echo '</div>';

    if ($view === 'social') {
        seo_marketing_performance_render_social_report($days);
    } else {
        seo_marketing_performance_render_campaign_report($days);
    }
}

/**
 * @param int $days
 */
function seo_marketing_performance_render_social_report($days)
{
    $report = seo_marketing_performance_social_report($days);
    $summary = $report['summary'];

    echo '<div class="seo-marketing-card"><h2 style="margin-top:0">Rendimiento de redes sociales</h2><p>Une publicaciones, visitas firmadas a la web y pedidos WooCommerce atribuidos a la última interacción social registrada.</p>';
    echo '<div class="seo-mkt-report-filter"><strong>Periodo:</strong>';
    foreach (array(7=>'7 días',28=>'28 días',90=>'90 días',365=>'365 días') as $period => $label) {
        echo '<a class="' . ($days === $period ? 'is-active' : '') . '" href="' . esc_url(seo_marketing_performance_admin_url($period, 'social')) . '">' . esc_html($label) . '</a>';
    }
    echo '</div>';

    echo '<div class="seo-mkt-report-kpis">';
    $kpis = array(
        'Publicaciones' => number_format_i18n($summary['publications']),
        'Visitas web' => number_format_i18n($summary['clicks']),
        'Interacciones' => number_format_i18n($summary['engagement']),
        'Pedidos atribuidos' => number_format_i18n($summary['orders']),
        'Facturación atribuida' => seo_marketing_performance_money($summary['revenue']),
        'Conversión visita → pedido' => number_format_i18n((float) $summary['conversion'], 2) . '%',
    );
    foreach ($kpis as $label => $value) {
        echo '<div class="seo-mkt-report-kpi"><strong>' . esc_html($value) . '</strong><span>' . esc_html($label) . '</span></div>';
    }
    echo '</div>';

    echo '<div class="seo-mkt-report-actions">';
    foreach (function_exists('seo_social_network_get_providers') ? seo_social_network_get_providers() : array() as $provider_key => $provider) {
        if (empty($provider['sync_callback']) || !is_callable($provider['sync_callback'])) {
            continue;
        }
        $label = isset($provider['label']) ? (string) $provider['label'] : ucfirst($provider_key);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_social_network_sync_reports"><input type="hidden" name="provider" value="' . esc_attr($provider_key) . '">';
        wp_nonce_field('seo_social_network_sync_reports');
        echo '<button type="submit" class="button">Actualizar ' . esc_html($label) . '</button></form>';
    }
    echo '</div>';
    echo '<div class="seo-mkt-report-note"><strong>Visitas web</strong> se miden en la tienda mediante enlaces firmados. El filtro selecciona publicaciones publicadas en el periodo; sus visitas e interacciones son acumuladas. <strong>Pedidos atribuidos</strong> se guardan en WooCommerce durante 30 días y no necesitan datos personales adicionales.</div>';

    echo '<h3>Por red</h3><div style="overflow:auto"><table class="seo-mkt-report-table"><thead><tr><th>Red</th><th>Publicaciones</th><th>Visitas</th><th>Interacciones</th><th>Impresiones</th><th>Alcance</th><th>Pedidos</th><th>Facturación</th><th>Conversión</th></tr></thead><tbody>';
    if (!$report['providers']) {
        echo '<tr><td colspan="9">Todavía no hay publicaciones sociales en este periodo.</td></tr>';
    }
    foreach ($report['providers'] as $row) {
        echo '<tr><td><strong>' . esc_html(ucfirst($row['provider'])) . '</strong></td>';
        echo '<td class="is-number">' . esc_html(number_format_i18n($row['publications'])) . '</td>';
        echo '<td class="is-number">' . esc_html(number_format_i18n($row['clicks'])) . '</td>';
        echo '<td class="is-number">' . esc_html(number_format_i18n($row['engagement'])) . '</td>';
        echo '<td class="is-number">' . esc_html(number_format_i18n($row['impressions'])) . '</td>';
        echo '<td class="is-number">' . esc_html(number_format_i18n($row['reach'])) . '</td>';
        echo '<td class="is-number">' . esc_html(number_format_i18n($row['orders'])) . '</td>';
        echo '<td class="is-number">' . esc_html(seo_marketing_performance_money($row['revenue'])) . '</td>';
        echo '<td class="is-number">' . esc_html(number_format_i18n((float) $row['conversion'], 2)) . '%</td></tr>';
    }
    echo '</tbody></table></div>';

    echo '<h3>Publicaciones</h3><div style="overflow:auto"><table class="seo-mkt-report-table"><thead><tr><th>Contenido</th><th>Red</th><th>Tipo</th><th>Fecha</th><th>Visitas</th><th>Interacciones</th><th>Pedidos</th><th>Facturación</th><th>Enlace</th></tr></thead><tbody>';
    $shown = 0;
    foreach ($report['publications'] as $item) {
        if ($shown++ >= 100) break;
        $row = $item['row'];
        $title = get_the_title(absint($row->content_id));
        if ($title === '') $title = 'Contenido #' . absint($row->content_id);
        $engagement = absint($row->reactions) + absint($row->comments) + absint($row->shares);
        echo '<tr><td><strong>' . esc_html($title) . '</strong></td><td>' . esc_html(ucfirst($row->provider)) . '</td><td>' . esc_html($row->content_type === 'campaign_product' ? 'Oferta' : ucfirst($row->content_type)) . '</td><td>' . esc_html($row->published_at ? mysql2date('d/m/Y H:i', $row->published_at) : '—') . '</td>';
        echo '<td class="is-number">' . esc_html(number_format_i18n(absint($row->clicks))) . '</td><td class="is-number">' . esc_html(number_format_i18n($engagement)) . '</td><td class="is-number">' . esc_html(number_format_i18n($item['orders'])) . '</td><td class="is-number">' . esc_html(seo_marketing_performance_money($item['revenue'])) . '</td>';
        echo '<td>' . (!empty($row->remote_url) ? '<a href="' . esc_url($row->remote_url) . '" target="_blank" rel="noopener">Ver publicación</a>' : '—') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

/**
 * @param int $days No se usa para cálculo de campaña; se conserva al alternar vistas.
 */
function seo_marketing_performance_render_campaign_report($days)
{
    $rows = seo_marketing_performance_campaign_report();
    echo '<div class="seo-marketing-card"><h2 style="margin-top:0">Rendimiento de campañas</h2><p>Compara visitas de la franja de campaña y redes sociales con pedidos atribuidos y ventas observadas de los productos durante las fechas de cada campaña.</p>';
    echo '<div class="seo-mkt-report-note"><strong>Atribuido</strong> significa que el pedido conserva una interacción firmada de campaña/social. <strong>Observado durante campaña</strong> cuenta ventas de esos productos dentro de las fechas, aunque no podamos afirmar que la campaña las causó.</div>';

    echo '<div style="overflow:auto"><table class="seo-mkt-report-table"><thead><tr><th>Campaña</th><th>Periodo</th><th>Productos</th><th>Publicaciones</th><th>Visitas franja</th><th>Visitas sociales</th><th>Interacciones</th><th>Pedidos atrib.</th><th>Facturación atrib.</th><th>Conv.</th><th>Pedidos observados</th><th>Unidades observadas</th><th>Ventas producto periodo</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="13">Todavía no hay campañas disponibles.</td></tr>';
    }
    foreach ($rows as $item) {
        $campaign = $item['campaign'];
        $m = $item['metrics'];
        $name = trim((string) $campaign->name . ' ' . (string) $campaign->edition_label);
        echo '<tr><td><strong>' . esc_html($name) . '</strong><br><span class="is-muted">' . esc_html((string) $campaign->campaign_key) . '</span></td>';
        echo '<td>' . esc_html(mysql2date('d/m/Y', $campaign->start_at)) . ' → ' . esc_html(mysql2date('d/m/Y', $campaign->end_at)) . '</td>';
        foreach (array('products','publications','strip_visits','social_clicks','engagement','attributed_orders') as $key) {
            echo '<td class="is-number">' . esc_html(number_format_i18n($m[$key])) . '</td>';
        }
        echo '<td class="is-number">' . esc_html(seo_marketing_performance_money($m['attributed_revenue'])) . '</td>';
        echo '<td class="is-number">' . esc_html(number_format_i18n((float) $m['conversion'], 2)) . '%</td>';
        echo '<td class="is-number">' . esc_html(number_format_i18n($m['observed_orders'])) . '</td>';
        echo '<td class="is-number">' . esc_html(number_format_i18n((float) $m['observed_units'], 0)) . '</td>';
        echo '<td class="is-number">' . esc_html(seo_marketing_performance_money($m['observed_revenue'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';

    $selected_id = isset($_GET['campaign_report_id']) ? absint($_GET['campaign_report_id']) : 0;
    if (!$selected_id && $rows) {
        $selected_id = absint($rows[0]['campaign']->id);
    }

    if ($rows) {
        echo '<form method="get" style="margin-top:18px;display:flex;gap:8px;align-items:end;flex-wrap:wrap"><input type="hidden" name="page" value="seo-menu-marketing"><input type="hidden" name="tab" value="reports"><input type="hidden" name="report_view" value="campaigns"><input type="hidden" name="report_days" value="' . esc_attr((string) $days) . '"><label><strong>Detalle de campaña</strong><br><select name="campaign_report_id">';
        foreach ($rows as $item) {
            $campaign = $item['campaign'];
            echo '<option value="' . esc_attr((string) absint($campaign->id)) . '" ' . selected($selected_id, absint($campaign->id), false) . '>' . esc_html(trim((string) $campaign->name . ' ' . (string) $campaign->edition_label)) . '</option>';
        }
        echo '</select></label><button class="button" type="submit">Ver detalle</button></form>';

        foreach ($rows as $item) {
            if (absint($item['campaign']->id) !== $selected_id) continue;
            echo '<h3>Productos · ' . esc_html(trim((string) $item['campaign']->name . ' ' . (string) $item['campaign']->edition_label)) . '</h3>';
            echo '<div style="overflow:auto"><table class="seo-mkt-report-table"><thead><tr><th>Producto</th><th>Visitas franja</th><th>Visitas sociales</th><th>Pedidos atrib.</th><th>Facturación atrib.</th><th>Unidades observadas</th><th>Ventas observadas</th></tr></thead><tbody>';
            foreach ($item['metrics']['product_metrics'] as $product) {
                echo '<tr><td><a href="' . esc_url(get_edit_post_link($product['product_id'])) . '"><strong>' . esc_html($product['name']) . '</strong></a></td>';
                echo '<td class="is-number">' . esc_html(number_format_i18n($product['strip_visits'])) . '</td>';
                echo '<td class="is-number">' . esc_html(number_format_i18n($product['social_clicks'])) . '</td>';
                echo '<td class="is-number">' . esc_html(number_format_i18n($product['attributed_orders'])) . '</td>';
                echo '<td class="is-number">' . esc_html(seo_marketing_performance_money($product['attributed_revenue'])) . '</td>';
                echo '<td class="is-number">' . esc_html(number_format_i18n((float) $product['observed_units'], 0)) . '</td>';
                echo '<td class="is-number">' . esc_html(seo_marketing_performance_money($product['observed_revenue'])) . '</td></tr>';
            }
            echo '</tbody></table></div>';
            break;
        }
    }

    echo '</div>';
}
