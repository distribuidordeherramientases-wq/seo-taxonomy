<?php
/**
 * SEO System - Integracion Social de Marketing / Campanas.
 *
 * Marketing es la fuente de verdad: esta capa solo consume campanas habilitadas
 * y sus productos, genera el mensaje por red y los inserta en huecos libres de
 * la agenda social sin mover publicaciones ya programadas.
 */

defined('ABSPATH') || exit;

/**
 * Plantilla social de campana para un proveedor.
 *
 * @param string $provider
 * @return string
 */
function seo_social_campaign_template($provider)
{
    $provider = sanitize_key($provider);
    $settings = function_exists('seo_social_network_get_settings') ? seo_social_network_get_settings() : array();
    if (!empty($settings['templates'][$provider]['campaign'])) {
        return (string) $settings['templates'][$provider]['campaign'];
    }

    return "{producto}\n\n{campana}: {precio_oferta} (antes {precio_regular}).\nDisponible hasta {fecha_fin}.";
}

/**
 * @param float $price
 * @return string
 */
function seo_social_campaign_format_price($price)
{
    $price = (float) $price;
    if (function_exists('wc_price')) {
        return trim(wp_strip_all_tags(html_entity_decode((string) wc_price($price), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8')));
    }
    return number_format_i18n($price, 2) . ' EUR';
}

/**
 * @param object $campaign
 * @param array  $product_item
 * @param string $tracking_url
 * @return array<string,string>
 */
function seo_social_campaign_template_variables($campaign, $product_item, $tracking_url)
{
    $product = isset($product_item['product']) ? $product_item['product'] : null;
    $product_id = isset($product_item['id']) ? absint($product_item['id']) : 0;
    $post = $product_id ? get_post($product_id) : null;

    $excerpt = '';
    if ($product && is_callable(array($product, 'get_short_description'))) {
        $excerpt = (string) $product->get_short_description();
        if ($excerpt === '') {
            $excerpt = (string) $product->get_description();
        }
    } elseif ($post instanceof WP_Post) {
        $excerpt = has_excerpt($post) ? get_the_excerpt($post) : (string) $post->post_content;
    }
    $excerpt = wp_trim_words(wp_strip_all_tags(strip_shortcodes($excerpt)), 32, '...');

    $categories = '';
    if ($product_id) {
        $terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
        if (!is_wp_error($terms)) {
            $categories = implode(', ', (array) $terms);
        }
    }

    $image = !empty($product_item['image_url']) ? (string) $product_item['image_url'] : '';
    if ($image === '' && $product_id) {
        $image = (string) get_the_post_thumbnail_url($product_id, 'full');
    }

    $regular = seo_social_campaign_format_price(isset($product_item['regular_price']) ? $product_item['regular_price'] : 0);
    $offer = seo_social_campaign_format_price(isset($product_item['campaign_price']) ? $product_item['campaign_price'] : 0);
    $discount = isset($product_item['discount_percent']) ? max(0, absint($product_item['discount_percent'])) : 0;
    $campaign_name = isset($campaign->name) ? (string) $campaign->name : '';
    $edition = isset($campaign->edition_label) ? (string) $campaign->edition_label : '';
    $product_name = !empty($product_item['name']) ? (string) $product_item['name'] : ($post ? get_the_title($post) : '');

    $start = !empty($campaign->start_at) && function_exists('seo_marketing_campaigns_timestamp')
        ? seo_marketing_campaigns_timestamp($campaign->start_at)
        : 0;
    $end = !empty($campaign->end_at) && function_exists('seo_marketing_campaigns_timestamp')
        ? seo_marketing_campaigns_timestamp($campaign->end_at)
        : 0;

    $vars = array(
        '{titulo}'         => $product_name,
        '{producto}'       => $product_name,
        '{extracto}'       => $excerpt,
        '{fecha}'          => $start ? wp_date(get_option('date_format'), $start, wp_timezone()) : '',
        '{fecha_inicio}'   => $start ? wp_date(get_option('date_format'), $start, wp_timezone()) : '',
        '{fecha_fin}'      => $end ? wp_date(get_option('date_format'), $end, wp_timezone()) : '',
        '{url}'            => (string) $tracking_url,
        '{url_producto}'   => (string) $tracking_url,
        '{sitio}'          => get_bloginfo('name'),
        '{autor}'          => '',
        '{tipo}'           => 'Producto en campaña',
        '{categorias}'     => $categories,
        '{imagen}'         => $image,
        '{imagen_producto}'=> $image,
        '{campana}'        => $campaign_name,
        '{edicion}'        => $edition,
        '{precio_regular}' => $regular,
        '{precio_oferta}'  => $offer,
        '{precio}'         => $offer,
        '{descuento}'      => (string) $discount,
        '{descuento_pct}'  => $discount > 0 ? $discount . '%' : '',
    );

    return (array) apply_filters('seo_social_campaign_template_variables', $vars, $campaign, $product_item, $tracking_url);
}

/**
 * @param string $template
 * @param object $campaign
 * @param array  $product_item
 * @param string $tracking_url
 * @return string
 */
function seo_social_campaign_render_template($template, $campaign, $product_item, $tracking_url)
{
    $message = strtr((string) $template, seo_social_campaign_template_variables($campaign, $product_item, $tracking_url));
    $message = preg_replace("/\r\n?|\n/", "\n", $message);
    $message = preg_replace("/\n{4,}/", "\n\n\n", (string) $message);
    return trim(wp_strip_all_tags((string) $message));
}

/**
 * @param int    $product_id
 * @param string $provider
 * @param int    $publication_id
 * @param object $campaign
 * @return string
 */
function seo_social_campaign_tracking_url($product_id, $provider, $publication_id, $campaign)
{
    $url = get_permalink(absint($product_id));
    if (!$url) {
        return '';
    }

    $campaign_key = !empty($campaign->campaign_key)
        ? sanitize_title((string) $campaign->campaign_key)
        : 'campaign-' . absint(isset($campaign->id) ? $campaign->id : 0);
    $reference = absint($publication_id) . '.' . seo_social_network_tracking_signature($publication_id);

    return add_query_arg(
        array(
            'utm_source'     => sanitize_key($provider),
            'utm_medium'     => 'social',
            'utm_campaign'   => $campaign_key,
            'utm_content'    => 'campaign-' . absint(isset($campaign->id) ? $campaign->id : 0) . '-product-' . absint($product_id),
            'seo_social_ref' => $reference,
        ),
        $url
    );
}

/**
 * @param int    $product_id
 * @param string $provider
 * @param string $template
 * @return int|WP_Error
 */
function seo_social_campaign_create_pending_publication($product_id, $provider, $template)
{
    global $wpdb;
    $post = get_post(absint($product_id));
    if (!$post || 'publish' !== $post->post_status) {
        return new WP_Error('campaign_product_missing', 'El producto de la campaña no esta publicado.');
    }

    $table = seo_social_network_publications_table();
    $now = current_time('mysql');
    $inserted = $wpdb->insert(
        $table,
        array(
            'content_id'   => absint($product_id),
            'content_type' => 'campaign_product',
            'provider'     => sanitize_key($provider),
            'status'       => 'pending',
            'message'      => (string) $template,
            'created_at'   => $now,
            'updated_at'   => $now,
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
    );

    return false === $inserted
        ? new WP_Error('campaign_publication_insert_failed', 'No se pudo registrar la publicacion de campaña.')
        : (int) $wpdb->insert_id;
}

/**
 * Publica un producto usando el contexto y precio de una campaña Marketing.
 *
 * @param int    $campaign_id
 * @param int    $product_id
 * @param string $provider
 * @return array|WP_Error
 */
function seo_social_campaign_publish_product($campaign_id, $product_id, $provider)
{
    $campaign_id = absint($campaign_id);
    $product_id = absint($product_id);
    $provider = sanitize_key($provider);

    if (!function_exists('seo_marketing_campaigns_get_social_campaign')) {
        return new WP_Error('marketing_campaigns_unavailable', 'Marketing / Campañas no esta disponible.');
    }

    $item = seo_marketing_campaigns_get_social_campaign($campaign_id);
    if (!$item || empty($item['campaign'])) {
        return new WP_Error('campaign_unavailable', 'La campaña ya no esta disponible para Redes Sociales.');
    }

    $campaign = $item['campaign'];
    $now = function_exists('seo_marketing_campaigns_now_mysql') ? seo_marketing_campaigns_now_mysql() : current_time('mysql');
    if ((string) $campaign->start_at > $now || (string) $campaign->end_at < $now) {
        return new WP_Error('campaign_not_active', 'La campaña no esta activa en este momento.');
    }

    $product_item = null;
    foreach ((array) $item['products'] as $candidate) {
        if (absint($candidate['id']) === $product_id) {
            $product_item = $candidate;
            break;
        }
    }
    if (!$product_item) {
        return new WP_Error('campaign_product_unavailable', 'El producto ya no esta disponible dentro de la campaña.');
    }

    $product = isset($product_item['product']) ? $product_item['product'] : null;
    if (!$product || !$product->is_visible() || !$product->is_in_stock()) {
        return new WP_Error('campaign_product_not_sellable', 'El producto no esta visible o no tiene stock; no se publica la oferta.');
    }

    $provider_config = seo_social_network_get_provider($provider);
    $settings = seo_social_network_get_settings();
    $saved_provider = isset($settings['providers'][$provider]) && is_array($settings['providers'][$provider])
        ? $settings['providers'][$provider]
        : array();
    if (!$provider_config || empty($provider_config['publish_callback']) || !is_callable($provider_config['publish_callback'])) {
        return new WP_Error('provider_unavailable', 'La red social no tiene publicador disponible.');
    }
    if (empty($saved_provider['enabled'])) {
        return new WP_Error('provider_disconnected', 'La red social no esta conectada.');
    }

    $template = seo_social_campaign_template($provider);
    $publication_id = seo_social_campaign_create_pending_publication($product_id, $provider, $template);
    if (is_wp_error($publication_id)) {
        return $publication_id;
    }

    $tracking_url = seo_social_campaign_tracking_url($product_id, $provider, $publication_id, $campaign);
    $message = seo_social_campaign_render_template($template, $campaign, $product_item, $tracking_url);
    $image_url = function_exists('seo_social_campaign_resolve_publication_image_url')
        ? seo_social_campaign_resolve_publication_image_url($campaign, $product_item, $provider)
        : (!empty($product_item['image_url']) ? (string) $product_item['image_url'] : (string) get_the_post_thumbnail_url($product_id, 'full'));
    $post = get_post($product_id);

    $payload = array(
        'content_id'     => $product_id,
        'publication_id' => $publication_id,
        'post'           => $post,
        'message'        => $message,
        'target_url'     => $tracking_url,
        'image_url'      => $image_url,
        'provider'       => $saved_provider,
        'campaign_id'    => $campaign_id,
    );

    $result = call_user_func($provider_config['publish_callback'], $payload);

    global $wpdb;
    $table = seo_social_network_publications_table();
    $updated_at = current_time('mysql');
    if (is_wp_error($result)) {
        $wpdb->update(
            $table,
            array(
                'status'        => 'failed',
                'message'       => $message,
                'target_url'    => $tracking_url,
                'image_url'     => $image_url,
                'error_message' => $result->get_error_message(),
                'updated_at'    => $updated_at,
            ),
            array('id' => $publication_id),
            array('%s', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );
        return $result;
    }

    $remote_id = isset($result['remote_id']) ? sanitize_text_field((string) $result['remote_id']) : '';
    $remote_url = isset($result['remote_url']) ? esc_url_raw((string) $result['remote_url']) : '';
    $wpdb->update(
        $table,
        array(
            'remote_id'     => $remote_id,
            'remote_url'    => $remote_url,
            'status'        => 'published',
            'message'       => $message,
            'target_url'    => $tracking_url,
            'image_url'     => $image_url,
            'error_message' => '',
            'published_at'  => $updated_at,
            'updated_at'    => $updated_at,
        ),
        array('id' => $publication_id),
        array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
        array('%d')
    );

    update_post_meta($product_id, seo_social_campaign_published_meta_key($campaign_id, $provider), time());

    return array(
        'publication_id' => $publication_id,
        'remote_id'      => $remote_id,
        'remote_url'     => $remote_url,
    );
}

/**
 * @param int    $campaign_id
 * @param string $provider
 * @return string
 */
function seo_social_campaign_schedule_meta_key($campaign_id, $provider)
{
    return '_seo_social_campaign_schedule_' . absint($campaign_id) . '_' . sanitize_key($provider);
}

/**
 * @param int    $campaign_id
 * @param string $provider
 * @return string
 */
function seo_social_campaign_published_meta_key($campaign_id, $provider)
{
    return '_seo_social_campaign_published_' . absint($campaign_id) . '_' . sanitize_key($provider);
}

/**
 * @param int    $campaign_id
 * @param int    $product_id
 * @param string $provider
 * @return int
 */
function seo_social_campaign_get_scheduled_timestamp($campaign_id, $product_id, $provider)
{
    return absint(get_post_meta(absint($product_id), seo_social_campaign_schedule_meta_key($campaign_id, $provider), true));
}

/**
 * @param int    $campaign_id
 * @param int    $product_id
 * @param string $provider
 */
function seo_social_campaign_clear_schedule($campaign_id, $product_id, $provider)
{
    $args = array(absint($campaign_id), absint($product_id), sanitize_key($provider));
    wp_clear_scheduled_hook('seo_social_campaign_publish_scheduled', $args);
    delete_post_meta(absint($product_id), seo_social_campaign_schedule_meta_key($campaign_id, $provider));
}

/**
 * @param int    $campaign_id
 * @param int    $product_id
 * @param string $provider
 * @param int    $timestamp
 * @return true|WP_Error
 */
function seo_social_campaign_set_schedule($campaign_id, $product_id, $provider, $timestamp)
{
    $campaign_id = absint($campaign_id);
    $product_id = absint($product_id);
    $provider = sanitize_key($provider);
    $timestamp = absint($timestamp);

    if ($timestamp <= time() + 30) {
        return new WP_Error('invalid_campaign_schedule', 'La fecha de campaña debe estar en el futuro.');
    }
    $provider_config = seo_social_network_get_provider($provider);
    $settings = seo_social_network_get_settings();
    if (!$provider_config || empty($settings['providers'][$provider]['enabled'])) {
        return new WP_Error('campaign_provider_disconnected', 'La red social no esta conectada.');
    }

    $item = function_exists('seo_marketing_campaigns_get_social_campaign')
        ? seo_marketing_campaigns_get_social_campaign($campaign_id)
        : null;
    if (!$item || empty($item['campaign'])) {
        return new WP_Error('campaign_missing', 'La campaña no esta disponible.');
    }

    $start = seo_marketing_campaigns_timestamp($item['campaign']->start_at);
    $end = seo_marketing_campaigns_timestamp($item['campaign']->end_at);
    if ($timestamp < $start || $timestamp > $end) {
        return new WP_Error('campaign_schedule_outside_window', 'La fecha queda fuera del periodo de la campaña.');
    }

    $belongs = false;
    foreach ((array) $item['products'] as $product_item) {
        if (absint($product_item['id']) === $product_id) {
            $belongs = true;
            break;
        }
    }
    if (!$belongs) {
        return new WP_Error('campaign_product_missing', 'El producto no pertenece a esta campaña.');
    }

    seo_social_campaign_clear_schedule($campaign_id, $product_id, $provider);
    $args = array($campaign_id, $product_id, $provider);
    update_post_meta($product_id, seo_social_campaign_schedule_meta_key($campaign_id, $provider), $timestamp);
    if (!wp_schedule_single_event($timestamp, 'seo_social_campaign_publish_scheduled', $args)) {
        delete_post_meta($product_id, seo_social_campaign_schedule_meta_key($campaign_id, $provider));
        return new WP_Error('campaign_schedule_failed', 'WordPress no pudo crear la tarea de campaña.');
    }
    return true;
}

/**
 * @param int    $campaign_id
 * @param int    $product_id
 * @param string $provider
 */
function seo_social_campaign_run_scheduled($campaign_id, $product_id, $provider)
{
    seo_social_campaign_clear_schedule($campaign_id, $product_id, $provider);
    $result = seo_social_campaign_publish_product($campaign_id, $product_id, $provider);
    if (is_wp_error($result)) {
        error_log('[SEO Social Campaign] Publicacion fallida: ' . $result->get_error_message());
    }
}
add_action('seo_social_campaign_publish_scheduled', 'seo_social_campaign_run_scheduled', 10, 3);

/**
 * Devuelve todos los timestamps ya ocupados por una red: agenda normal y campañas.
 *
 * @param string $provider
 * @return int[]
 */
function seo_social_campaign_occupied_timestamps($provider)
{
    global $wpdb;
    $provider = sanitize_key($provider);
    $timestamps = array();

    $normal_key = seo_social_network_schedule_meta_key($provider);
    $normal = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > %d",
            $normal_key,
            time()
        )
    );
    foreach ((array) $normal as $value) {
        $ts = absint($value);
        if ($ts) {
            $timestamps[] = $ts;
        }
    }

    $prefix = '_seo_social_campaign_schedule_';
    $suffix = '_' . $provider;
    $like = $wpdb->esc_like($prefix) . '%' . $wpdb->esc_like($suffix);
    $campaign = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND CAST(meta_value AS UNSIGNED) > %d",
            $like,
            time()
        )
    );
    foreach ((array) $campaign as $value) {
        $ts = absint($value);
        if ($ts) {
            $timestamps[] = $ts;
        }
    }

    // Tambien se consideran publicaciones recientes ya ejecutadas para que una
    // campaña no se coloque pegada a algo que acaba de salir en la misma red.
    $publications_table = seo_social_network_publications_table();
    $recent_from = wp_date('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS), wp_timezone());
    $recent = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT published_at FROM {$publications_table}
             WHERE provider = %s
               AND status = 'published'
               AND published_at IS NOT NULL
               AND published_at >= %s",
            $provider,
            $recent_from
        )
    );
    foreach ((array) $recent as $mysql) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $mysql, wp_timezone());
        if ($dt instanceof DateTimeImmutable) {
            $timestamps[] = $dt->getTimestamp();
        }
    }

    $timestamps = array_values(array_unique($timestamps));
    sort($timestamps, SORT_NUMERIC);
    return $timestamps;
}

/**
 * @param object $campaign
 * @param string $preferred_time HH:MM
 * @return int[]
 */
function seo_social_campaign_candidate_slots($campaign, $preferred_time)
{
    $preferred_time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $preferred_time)
        ? (string) $preferred_time
        : '10:30';
    $timezone = wp_timezone();
    $start_ts = seo_marketing_campaigns_timestamp($campaign->start_at);
    $end_ts = seo_marketing_campaigns_timestamp($campaign->end_at);
    $from_ts = max(time() + 60, $start_ts);
    if (!$from_ts || !$end_ts || $from_ts > $end_ts) {
        return array();
    }

    $from = (new DateTimeImmutable('@' . $from_ts))->setTimezone($timezone)->setTime(0, 0, 0);
    $to = (new DateTimeImmutable('@' . $end_ts))->setTimezone($timezone)->setTime(23, 59, 59);
    list($hour, $minute) = array_map('intval', explode(':', $preferred_time));

    $slots = array();
    for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
        $candidate = $day->setTime($hour, $minute, 0)->getTimestamp();
        if ($candidate < $from_ts || $candidate > $end_ts || $candidate <= time() + 30) {
            continue;
        }
        $slots[] = $candidate;
    }
    return $slots;
}

/**
 * @param int   $timestamp
 * @param int[] $occupied
 * @param int   $min_gap_seconds
 * @return bool
 */
function seo_social_campaign_slot_is_free($timestamp, $occupied, $min_gap_seconds)
{
    foreach ((array) $occupied as $existing) {
        if (abs((int) $timestamp - (int) $existing) < $min_gap_seconds) {
            return false;
        }
    }
    return true;
}

/**
 * Inserta productos de una campaña en huecos libres sin mover la agenda existente.
 */
function seo_social_campaign_handle_plan()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para programar campañas sociales.', 'seo-system'));
    }
    check_admin_referer('seo_social_campaign_plan');

    $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
    $providers = isset($_POST['providers']) && is_array($_POST['providers'])
        ? array_values(array_unique(array_filter(array_map('sanitize_key', wp_unslash($_POST['providers'])))))
        : array();
    $max_items = isset($_POST['max_items']) ? min(50, max(1, absint($_POST['max_items']))) : 4;
    $min_gap_hours = isset($_POST['min_gap_hours']) ? min(168, max(6, absint($_POST['min_gap_hours']))) : 36;
    $preferred_time = isset($_POST['preferred_time']) ? sanitize_text_field(wp_unslash($_POST['preferred_time'])) : '10:30';

    $item = function_exists('seo_marketing_campaigns_get_social_campaign')
        ? seo_marketing_campaigns_get_social_campaign($campaign_id)
        : null;
    if (!$item || empty($item['campaign']) || empty($item['products']) || empty($providers)) {
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('campaign_social_msg' => 'missing')));
        exit;
    }

    $settings = seo_social_network_get_settings();
    $available = seo_social_network_get_providers();
    $providers = array_values(array_filter($providers, static function ($provider) use ($settings, $available) {
        return isset($available[$provider]) && !empty($settings['providers'][$provider]['enabled']);
    }));
    if (!$providers) {
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('campaign_social_msg' => 'no_provider')));
        exit;
    }

    $created = 0;
    $skipped = 0;
    $gap = $min_gap_hours * HOUR_IN_SECONDS;
    $slots = seo_social_campaign_candidate_slots($item['campaign'], $preferred_time);

    foreach ($providers as $provider) {
        $occupied = seo_social_campaign_occupied_timestamps($provider);
        $provider_created = 0;

        foreach ((array) $item['products'] as $product_item) {
            if ($provider_created >= $max_items) {
                break;
            }
            $product_id = absint($product_item['id']);
            if (!$product_id) {
                continue;
            }
            if (get_post_meta($product_id, seo_social_campaign_published_meta_key($campaign_id, $provider), true)) {
                $skipped++;
                continue;
            }
            if (seo_social_campaign_get_scheduled_timestamp($campaign_id, $product_id, $provider)) {
                $skipped++;
                continue;
            }

            $chosen = 0;
            foreach ($slots as $slot) {
                if (seo_social_campaign_slot_is_free($slot, $occupied, $gap)) {
                    $chosen = $slot;
                    break;
                }
            }
            if (!$chosen) {
                $skipped++;
                continue;
            }

            $result = seo_social_campaign_set_schedule($campaign_id, $product_id, $provider, $chosen);
            if (is_wp_error($result)) {
                $skipped++;
                continue;
            }
            $occupied[] = $chosen;
            sort($occupied, SORT_NUMERIC);
            $created++;
            $provider_created++;
        }
    }

    wp_safe_redirect(
        seo_social_network_admin_url(
            'scheduler',
            array(
                'campaign_social_msg' => $created ? 'planned' : 'no_slots',
                'campaign_created'    => $created,
                'campaign_skipped'    => $skipped,
            )
        )
    );
    exit;
}
add_action('admin_post_seo_social_campaign_plan', 'seo_social_campaign_handle_plan');

/**
 * Cancela una programacion de producto de campaña.
 */
function seo_social_campaign_handle_cancel()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para cancelar campañas sociales.', 'seo-system'));
    }
    check_admin_referer('seo_social_campaign_cancel');
    $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    $provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
    if ($campaign_id && $product_id && $provider) {
        seo_social_campaign_clear_schedule($campaign_id, $product_id, $provider);
    }
    wp_safe_redirect(seo_social_network_admin_url('scheduler', array('campaign_social_msg' => 'cancelled')));
    exit;
}
add_action('admin_post_seo_social_campaign_cancel', 'seo_social_campaign_handle_cancel');

/**
 * Programaciones de campaña guardadas en postmeta.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_social_campaign_scheduled_rows()
{
    global $wpdb;
    $prefix = '_seo_social_campaign_schedule_';
    $like = $wpdb->esc_like($prefix) . '%';
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title\n             FROM {$wpdb->postmeta} pm\n             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id\n             WHERE pm.meta_key LIKE %s\n               AND CAST(pm.meta_value AS UNSIGNED) > 0\n             ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC",
            $like
        )
    );

    $result = array();
    foreach ((array) $rows as $row) {
        if (!preg_match('/^_seo_social_campaign_schedule_(\d+)_([a-z0-9_-]+)$/', (string) $row->meta_key, $match)) {
            continue;
        }
        $campaign_id = absint($match[1]);
        $provider = sanitize_key($match[2]);
        $campaign = function_exists('seo_marketing_campaigns_get') ? seo_marketing_campaigns_get($campaign_id) : null;
        $result[] = array(
            'campaign_id'   => $campaign_id,
            'campaign_name' => $campaign ? (string) $campaign->name : 'Campaña #' . $campaign_id,
            'product_id'    => absint($row->post_id),
            'product_name'  => (string) $row->post_title,
            'provider'      => $provider,
            'timestamp'     => absint($row->meta_value),
        );
    }
    return $result;
}

/**
 * Filas compatibles con la exportacion de agenda del Programador.
 *
 * @return array<int,array<int,string>>
 */
function seo_social_campaign_exportable_agenda_rows()
{
    $result = array();
    foreach (seo_social_campaign_scheduled_rows() as $row) {
        $result[] = array(
            (string) $row['product_id'],
            $row['campaign_name'] . ' — ' . $row['product_name'],
            'Campaña / Producto',
            (string) $row['provider'],
            wp_date('Y-m-d H:i', (int) $row['timestamp'], wp_timezone()),
            wp_timezone_string(),
            'programada',
        );
    }
    return $result;
}

/**
 * Panel de campañas dentro del Programador Social.
 */
function seo_social_campaign_render_scheduler_panel()
{
    if (!function_exists('seo_marketing_campaigns_get_social_catalog')) {
        echo '<div class="seo-social-code-note"><strong>Campañas:</strong> el modulo Marketing / Campañas no esta disponible.</div>';
        return;
    }

    $message = isset($_GET['campaign_social_msg']) ? sanitize_key(wp_unslash($_GET['campaign_social_msg'])) : '';
    if ($message !== '') {
        $created = isset($_GET['campaign_created']) ? absint($_GET['campaign_created']) : 0;
        $skipped = isset($_GET['campaign_skipped']) ? absint($_GET['campaign_skipped']) : 0;
        $texts = array(
            'planned'    => array('success', sprintf('Campaña insertada en la agenda: %d publicacion(es) nuevas; %d producto(s) sin hueco o ya programados/publicados.', $created, $skipped)),
            'no_slots'   => array('warning', 'No hay huecos suficientes dentro del periodo de la campaña con la separacion elegida. No se ha movido ninguna publicacion existente.'),
            'missing'    => array('warning', 'Selecciona una campaña valida y al menos una red social.'),
            'no_provider'=> array('warning', 'Ninguna de las redes seleccionadas esta conectada.'),
            'cancelled'  => array('success', 'Publicacion de campaña cancelada.'),
        );
        if (isset($texts[$message])) {
            echo '<div class="notice notice-' . esc_attr($texts[$message][0]) . ' inline"><p>' . esc_html($texts[$message][1]) . '</p></div>';
        }
    }

    $campaigns = seo_marketing_campaigns_get_social_catalog();
    $settings = seo_social_network_get_settings();
    $providers = seo_social_network_get_providers();
    $connected = array();
    foreach ($providers as $provider_key => $provider) {
        if (!empty($settings['providers'][$provider_key]['enabled'])) {
            $connected[$provider_key] = $provider;
        }
    }

    echo '<section class="seo-social-card" style="margin-top:18px">';
    echo '<div class="seo-social-intro"><div><h2>Campañas de Marketing</h2><p>Lee directamente <strong>Marketing → Campañas</strong>. Inserta productos en oferta en huecos libres de la agenda de cada red sin mover entradas ni landings ya programadas.</p></div><span class="seo-social-state is-ok">Fuente: Marketing</span></div>';

    if (!$campaigns) {
        echo '<p class="seo-social-code-note">No hay campañas habilitadas actuales o futuras con productos disponibles.</p></section>';
        return;
    }
    if (!$connected) {
        echo '<p class="seo-social-code-note">Conecta al menos una red social para programar productos de campaña.</p>';
    }

    echo '<div class="seo-social-grid" style="margin-top:18px">';
    foreach ($campaigns as $item) {
        $campaign = $item['campaign'];
        $products = (array) $item['products'];
        $state = isset($campaign->state) ? (string) $campaign->state : 'future';
        $state_label = 'current' === $state ? 'En curso' : 'Proxima';
        $start = seo_marketing_campaigns_timestamp($campaign->start_at);
        $end = seo_marketing_campaigns_timestamp($campaign->end_at);

        echo '<div class="seo-social-template-card">';
        echo '<div class="seo-social-template-card__head"><h3>' . esc_html((string) $campaign->name) . '</h3><span class="seo-social-state ' . ('current' === $state ? 'is-ok' : 'is-scheduled') . '">' . esc_html($state_label) . '</span></div>';
        echo '<p class="seo-social-help"><strong>' . esc_html((string) count($products)) . ' productos</strong> · ' . esc_html(wp_date('d/m/Y H:i', $start, wp_timezone())) . ' → ' . esc_html(wp_date('d/m/Y H:i', $end, wp_timezone())) . '</p>';

        if (!empty($products[0])) {
            echo '<p class="seo-social-help">Primer producto por orden de campaña: <strong>' . esc_html((string) $products[0]['name']) . '</strong> · ' . esc_html(seo_social_campaign_format_price($products[0]['campaign_price'])) . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_social_campaign_plan"><input type="hidden" name="campaign_id" value="' . esc_attr((string) $campaign->id) . '">';
        wp_nonce_field('seo_social_campaign_plan');
        echo '<div class="seo-social-field"><label>Redes</label><div class="seo-social-network-checks">';
        foreach ($providers as $provider_key => $provider) {
            $label = isset($provider['label']) ? (string) $provider['label'] : ucfirst($provider_key);
            $is_connected = isset($connected[$provider_key]);
            echo '<label class="seo-social-network-check"><input type="checkbox" name="providers[]" value="' . esc_attr($provider_key) . '" ' . disabled(!$is_connected, true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</div></div>';
        echo '<div class="seo-social-grid" style="margin-top:12px">';
        echo '<div class="seo-social-field"><label>Max. productos por red</label><input type="number" name="max_items" min="1" max="50" value="4"><p class="seo-social-help">Evita convertir la campaña en una sucesion de anuncios.</p></div>';
        echo '<div class="seo-social-field"><label>Separacion minima</label><select name="min_gap_hours"><option value="18">18 horas</option><option value="24">24 horas</option><option value="36" selected>36 horas</option><option value="48">48 horas</option><option value="72">72 horas</option></select><p class="seo-social-help">Se aplica respecto a cualquier publicacion ya programada en esa red.</p></div>';
        echo '<div class="seo-social-field"><label>Hora preferida</label><input type="time" name="preferred_time" value="10:30"></div>';
        echo '</div>';
        echo '<div class="seo-social-actions"><button class="button button-primary" type="submit" ' . disabled(empty($connected), true, false) . '>Insertar en huecos libres</button></div>';
        echo '<p class="seo-social-help">No reprograma contenido existente. Si no cabe un producto dentro de las fechas de campaña respetando la separacion, se deja fuera.</p>';
        echo '</form>';

        if (!empty($products[0])) {
            echo '<details style="margin-top:10px"><summary style="cursor:pointer">Vista previa de plantilla</summary><div class="seo-social-template-preview-grid">';
            foreach ($providers as $provider_key => $provider) {
                $label = isset($provider['label']) ? (string) $provider['label'] : ucfirst($provider_key);
                $preview_url = add_query_arg(array('utm_source' => $provider_key, 'utm_medium' => 'social'), (string) $products[0]['url']);
                $preview = seo_social_campaign_render_template(seo_social_campaign_template($provider_key), $campaign, $products[0], $preview_url);
                echo '<div><strong>' . esc_html($label) . '</strong><div class="seo-social-preview">' . esc_html($preview) . '</div></div>';
            }
            echo '</div></details>';
        }
        echo '</div>';
    }
    echo '</div>';

    $scheduled = seo_social_campaign_scheduled_rows();
    echo '<h3 style="margin-top:22px">Campañas ya insertadas en la agenda</h3>';
    if (!$scheduled) {
        echo '<p class="seo-social-help">Todavia no hay productos de campaña programados.</p>';
    } else {
        echo '<div class="seo-social-table-wrap"><table class="seo-social-table"><thead><tr><th>Fecha</th><th>Campaña</th><th>Producto</th><th>Red</th><th>Accion</th></tr></thead><tbody>';
        foreach ($scheduled as $row) {
            echo '<tr><td><strong>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $row['timestamp'], wp_timezone())) . '</strong></td>';
            echo '<td>' . esc_html((string) $row['campaign_name']) . '</td>';
            echo '<td><a href="' . esc_url(get_edit_post_link((int) $row['product_id'])) . '">' . esc_html((string) $row['product_name']) . '</a><br><small>#' . esc_html((string) $row['product_id']) . '</small></td>';
            echo '<td>' . esc_html(ucfirst((string) $row['provider'])) . '</td><td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_social_campaign_cancel"><input type="hidden" name="campaign_id" value="' . esc_attr((string) $row['campaign_id']) . '"><input type="hidden" name="product_id" value="' . esc_attr((string) $row['product_id']) . '"><input type="hidden" name="provider" value="' . esc_attr((string) $row['provider']) . '">';
            wp_nonce_field('seo_social_campaign_cancel');
            echo '<button class="button button-small" type="submit">Cancelar</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}
