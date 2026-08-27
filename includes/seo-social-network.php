<?php
/**
 * SEO System - Social Network.
 *
 * Capa comun para conexiones, plantillas, publicaciones, seguimiento de visitas
 * e informes de redes sociales. Los proveedores viven en archivos separados.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_SOCIAL_NETWORK_DB_VERSION')) {
    define('SEO_SOCIAL_NETWORK_DB_VERSION', 1);
}

if (!defined('SEO_SOCIAL_NETWORK_SETTINGS_OPTION')) {
    define('SEO_SOCIAL_NETWORK_SETTINGS_OPTION', 'seo_social_network_settings_v1');
}

if (!defined('SEO_SOCIAL_NETWORK_DB_VERSION_OPTION')) {
    define('SEO_SOCIAL_NETWORK_DB_VERSION_OPTION', 'seo_social_network_db_version');
}

$seo_social_facebook_provider = __DIR__ . '/seo-social-network-facebook.php';
if (is_readable($seo_social_facebook_provider)) {
    require_once $seo_social_facebook_provider;
}
unset($seo_social_facebook_provider);

/**
 * Registro de proveedores. Cada conector anade sus callbacks mediante filtro.
 *
 * @return array
 */
function seo_social_network_get_providers()
{
    $providers = apply_filters('seo_social_network_providers', array());
    return is_array($providers) ? $providers : array();
}

/**
 * @param string $provider_key
 * @return array|null
 */
function seo_social_network_get_provider($provider_key)
{
    $providers = seo_social_network_get_providers();
    return isset($providers[$provider_key]) && is_array($providers[$provider_key])
        ? $providers[$provider_key]
        : null;
}

/**
 * Ajustes iniciales. La publicacion automatica queda apagada por seguridad.
 *
 * @return array
 */
function seo_social_network_default_settings()
{
    return array(
        'auto_publish' => array(
            'post' => 0,
            'page' => 0,
        ),
        'templates' => array(
            'facebook' => array(
                'post' => "{titulo}\n\n{extracto}\n\n{url}",
                'page' => "{titulo}\n\n{extracto}\n\n{url}",
            ),
        ),
        'providers' => array(
            'facebook' => array(
                'enabled'          => 0,
                'page_id'          => '',
                'page_name'        => '',
                'page_link'        => '',
                'access_token_enc' => '',
                'api_version'      => 'v25.0',
                'publish_mode'     => 'link',
                'last_test_at'     => '',
                'last_test_ok'     => 0,
                'last_test_error'  => '',
            ),
        ),
    );
}

/**
 * @return array
 */
function seo_social_network_get_settings()
{
    $defaults = seo_social_network_default_settings();
    $stored = get_option(SEO_SOCIAL_NETWORK_SETTINGS_OPTION, array());
    $stored = is_array($stored) ? $stored : array();

    $settings = array_replace_recursive($defaults, $stored);

    foreach (array('post', 'page') as $post_type) {
        $settings['auto_publish'][$post_type] = !empty($settings['auto_publish'][$post_type]) ? 1 : 0;
    }

    if (!isset($settings['templates']) || !is_array($settings['templates'])) {
        $settings['templates'] = $defaults['templates'];
    }

    if (!isset($settings['providers']) || !is_array($settings['providers'])) {
        $settings['providers'] = $defaults['providers'];
    }

    return $settings;
}

/**
 * Guarda opciones con autoload desactivado.
 *
 * @param array $settings
 */
function seo_social_network_save_settings($settings)
{
    $settings = is_array($settings) ? $settings : array();

    if (false === get_option(SEO_SOCIAL_NETWORK_SETTINGS_OPTION, false)) {
        add_option(SEO_SOCIAL_NETWORK_SETTINGS_OPTION, $settings, '', 'no');
    } else {
        update_option(SEO_SOCIAL_NETWORK_SETTINGS_OPTION, $settings, false);
    }
}

/**
 * Cifra secretos con la sal del sitio. El token no vuelve a mostrarse en HTML.
 *
 * @param string $plain
 * @return string|WP_Error
 */
function seo_social_network_encrypt_secret($plain)
{
    $plain = trim((string) $plain);
    if ($plain === '') {
        return '';
    }

    if (!function_exists('openssl_encrypt') || !function_exists('openssl_random_pseudo_bytes')) {
        return new WP_Error('openssl_missing', 'OpenSSL no esta disponible; el token no se ha guardado.');
    }

    $method = 'aes-256-gcm';
    $key = hash('sha256', wp_salt('auth'), true);
    $iv_length = openssl_cipher_iv_length($method);
    $iv = openssl_random_pseudo_bytes($iv_length);
    $tag = '';
    $cipher = openssl_encrypt($plain, $method, $key, OPENSSL_RAW_DATA, $iv, $tag);

    if (!is_string($cipher) || $cipher === '' || $tag === '') {
        return new WP_Error('encrypt_failed', 'No se pudo cifrar el token.');
    }

    return 'enc1:' . base64_encode($iv . $tag . $cipher);
}

/**
 * @param string $encoded
 * @return string
 */
function seo_social_network_decrypt_secret($encoded)
{
    $encoded = (string) $encoded;
    if (strpos($encoded, 'enc1:') !== 0 || !function_exists('openssl_decrypt')) {
        return '';
    }

    $raw = base64_decode(substr($encoded, 5), true);
    if (!is_string($raw) || $raw === '') {
        return '';
    }

    $method = 'aes-256-gcm';
    $iv_length = openssl_cipher_iv_length($method);
    $tag_length = 16;

    if (strlen($raw) <= $iv_length + $tag_length) {
        return '';
    }

    $iv = substr($raw, 0, $iv_length);
    $tag = substr($raw, $iv_length, $tag_length);
    $cipher = substr($raw, $iv_length + $tag_length);
    $key = hash('sha256', wp_salt('auth'), true);

    $plain = openssl_decrypt($cipher, $method, $key, OPENSSL_RAW_DATA, $iv, $tag);
    return is_string($plain) ? $plain : '';
}

/**
 * @return string
 */
function seo_social_network_publications_table()
{
    global $wpdb;
    return $wpdb->prefix . 'seo_social_publications';
}

/**
 * Instala/actualiza la tabla operativa de publicaciones.
 */
function seo_social_network_maybe_install_tables()
{
    $installed = (int) get_option(SEO_SOCIAL_NETWORK_DB_VERSION_OPTION, 0);
    if ($installed >= SEO_SOCIAL_NETWORK_DB_VERSION) {
        return;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = seo_social_network_publications_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        content_id bigint(20) unsigned NOT NULL,
        content_type varchar(32) NOT NULL DEFAULT '',
        provider varchar(32) NOT NULL DEFAULT '',
        remote_id varchar(191) NOT NULL DEFAULT '',
        remote_url text NULL,
        status varchar(24) NOT NULL DEFAULT 'pending',
        message longtext NULL,
        target_url text NULL,
        image_url text NULL,
        clicks bigint(20) unsigned NOT NULL DEFAULT 0,
        reactions bigint(20) unsigned NOT NULL DEFAULT 0,
        comments bigint(20) unsigned NOT NULL DEFAULT 0,
        shares bigint(20) unsigned NOT NULL DEFAULT 0,
        impressions bigint(20) unsigned NOT NULL DEFAULT 0,
        reach bigint(20) unsigned NOT NULL DEFAULT 0,
        error_message text NULL,
        published_at datetime NULL,
        last_sync_at datetime NULL,
        last_click_at datetime NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY content_provider (content_id, provider),
        KEY provider_status (provider, status),
        KEY published_at (published_at)
    ) {$charset_collate};";

    dbDelta($sql);
    update_option(SEO_SOCIAL_NETWORK_DB_VERSION_OPTION, SEO_SOCIAL_NETWORK_DB_VERSION, false);
}
add_action('admin_init', 'seo_social_network_maybe_install_tables', 15);

/**
 * Publica las tablas al Data Layer si el sistema las quiere auditar/consultar.
 *
 * @param array $tables
 * @return array
 */
function seo_social_network_register_data_layer_tables($tables)
{
    $tables = is_array($tables) ? $tables : array();
    $tables['social_publications'] = array(
        'table'       => seo_social_network_publications_table(),
        'primary_key' => array('id'),
        'entity_type' => 'social_publication',
    );
    return $tables;
}
add_filter('seo_data_layer_tables', 'seo_social_network_register_data_layer_tables');

/**
 * Tipos de contenido publicables. Se puede extender desde otros modulos.
 *
 * @return string[]
 */
function seo_social_network_supported_post_types()
{
    $types = apply_filters('seo_social_network_post_types', array('post', 'page'));
    $types = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $types))));
    return $types ?: array('post', 'page');
}

/**
 * @param WP_Post $post
 * @return string
 */
function seo_social_network_content_type_label($post)
{
    if (!$post instanceof WP_Post) {
        return '';
    }

    if ('post' === $post->post_type) {
        return 'Entrada';
    }
    if ('page' === $post->post_type) {
        return 'Pagina / Landing';
    }

    $object = get_post_type_object($post->post_type);
    return $object && isset($object->labels->singular_name)
        ? (string) $object->labels->singular_name
        : $post->post_type;
}

/**
 * Firma corta para atribuir una visita a una publicacion sin usar redirecciones.
 *
 * @param int $publication_id
 * @return string
 */
function seo_social_network_tracking_signature($publication_id)
{
    return substr(hash_hmac('sha256', 'social|' . absint($publication_id), wp_salt('nonce')), 0, 16);
}

/**
 * @param int    $content_id
 * @param string $provider
 * @param int    $publication_id
 * @return string
 */
function seo_social_network_build_tracking_url($content_id, $provider, $publication_id)
{
    $url = get_permalink($content_id);
    if (!$url) {
        return '';
    }

    $provider = sanitize_key($provider);
    $reference = absint($publication_id) . '.' . seo_social_network_tracking_signature($publication_id);

    return add_query_arg(
        array(
            'utm_source'      => $provider,
            'utm_medium'      => 'social',
            'utm_campaign'    => 'seo_social',
            'utm_content'     => get_post_type($content_id) . '-' . absint($content_id),
            'seo_social_ref'  => $reference,
        ),
        $url
    );
}

/**
 * Cuenta la llegada a la web desde un enlace generado por el modulo.
 */
function seo_social_network_capture_visit()
{
    if (is_admin() || empty($_GET['seo_social_ref'])) {
        return;
    }

    $raw = sanitize_text_field(wp_unslash($_GET['seo_social_ref']));
    if (!preg_match('/^(\d+)\.([a-f0-9]{16})$/', $raw, $matches)) {
        return;
    }

    $publication_id = absint($matches[1]);
    $signature = (string) $matches[2];

    if ($publication_id <= 0 || !hash_equals(seo_social_network_tracking_signature($publication_id), $signature)) {
        return;
    }

    $content_id = absint(get_queried_object_id());
    if ($content_id <= 0) {
        return;
    }

    global $wpdb;
    $table = seo_social_network_publications_table();
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table}
             SET clicks = clicks + 1,
                 last_click_at = %s,
                 updated_at = %s
             WHERE id = %d
               AND content_id = %d
               AND status = 'published'",
            current_time('mysql'),
            current_time('mysql'),
            $publication_id,
            $content_id
        )
    );
}
add_action('template_redirect', 'seo_social_network_capture_visit', 1);

/**
 * @param WP_Post $post
 * @param string  $tracking_url
 * @return array
 */
function seo_social_network_template_variables($post, $tracking_url)
{
    $excerpt = has_excerpt($post)
        ? get_the_excerpt($post)
        : wp_trim_words(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)), 32, '...');

    $author = '';
    if ($post->post_author) {
        $user = get_userdata($post->post_author);
        $author = $user ? $user->display_name : '';
    }

    $categories = '';
    if ('post' === $post->post_type) {
        $names = wp_get_post_categories($post->ID, array('fields' => 'names'));
        if (!is_wp_error($names)) {
            $categories = implode(', ', (array) $names);
        }
    }

    $image = get_the_post_thumbnail_url($post->ID, 'full');
    $type_label = seo_social_network_content_type_label($post);

    $vars = array(
        '{titulo}'     => get_the_title($post),
        '{extracto}'   => $excerpt,
        '{fecha}'      => get_the_date(get_option('date_format'), $post),
        '{url}'        => $tracking_url,
        '{sitio}'      => get_bloginfo('name'),
        '{autor}'      => $author,
        '{tipo}'       => $type_label,
        '{categorias}' => $categories,
        '{imagen}'     => $image ? $image : '',
        '{title}'      => get_the_title($post),
        '{excerpt}'    => $excerpt,
        '{date}'       => get_the_date(get_option('date_format'), $post),
        '{author}'     => $author,
    );

    return apply_filters('seo_social_network_template_variables', $vars, $post, $tracking_url);
}

/**
 * @param string  $template
 * @param WP_Post $post
 * @param string  $tracking_url
 * @return string
 */
function seo_social_network_render_template($template, $post, $tracking_url)
{
    $template = (string) $template;
    $message = strtr($template, seo_social_network_template_variables($post, $tracking_url));
    $message = preg_replace("/\r\n?|\n/", "\n", $message);
    $message = preg_replace("/\n{4,}/", "\n\n\n", $message);
    return trim(wp_strip_all_tags((string) $message));
}

/**
 * @param int    $content_id
 * @param string $provider
 * @return string
 */
function seo_social_network_custom_template_meta_key($content_id, $provider)
{
    unset($content_id);
    return '_seo_social_template_' . sanitize_key($provider);
}

/**
 * @param WP_Post $post
 * @param string  $provider
 * @return string
 */
function seo_social_network_get_template_for_content($post, $provider)
{
    $provider = sanitize_key($provider);
    $custom = get_post_meta($post->ID, seo_social_network_custom_template_meta_key($post->ID, $provider), true);
    if (is_string($custom) && trim($custom) !== '') {
        return $custom;
    }

    $settings = seo_social_network_get_settings();
    $post_type = isset($settings['templates'][$provider][$post->post_type])
        ? $post->post_type
        : 'post';

    return isset($settings['templates'][$provider][$post_type])
        ? (string) $settings['templates'][$provider][$post_type]
        : '{titulo}' . "\n\n" . '{url}';
}

/**
 * @param int    $content_id
 * @param string $provider
 * @param string $template
 */
function seo_social_network_save_custom_template($content_id, $provider, $template)
{
    $key = seo_social_network_custom_template_meta_key($content_id, $provider);
    $template = trim((string) $template);

    if ($template === '') {
        delete_post_meta($content_id, $key);
    } else {
        update_post_meta($content_id, $key, $template);
    }
}

/**
 * @param int    $content_id
 * @param string $provider
 * @param string $template
 * @return int|WP_Error
 */
function seo_social_network_create_pending_publication($content_id, $provider, $template)
{
    global $wpdb;
    $post = get_post($content_id);
    if (!$post || 'publish' !== $post->post_status) {
        return new WP_Error('invalid_content', 'El contenido no existe o no esta publicado.');
    }

    $table = seo_social_network_publications_table();
    $now = current_time('mysql');

    $inserted = $wpdb->insert(
        $table,
        array(
            'content_id'    => absint($content_id),
            'content_type'  => sanitize_key($post->post_type),
            'provider'      => sanitize_key($provider),
            'status'        => 'pending',
            'message'       => (string) $template,
            'created_at'    => $now,
            'updated_at'    => $now,
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
    );

    if (false === $inserted) {
        return new WP_Error('db_insert_failed', 'No se pudo crear el registro previo de publicacion.');
    }

    return (int) $wpdb->insert_id;
}

/**
 * Ejecuta una publicacion en el proveedor y conserva el resultado.
 *
 * @param int    $content_id
 * @param string $provider
 * @param string $template_override
 * @return array|WP_Error
 */
function seo_social_network_publish_content($content_id, $provider, $template_override = '')
{
    $content_id = absint($content_id);
    $provider = sanitize_key($provider);
    $post = get_post($content_id);
    $provider_config = seo_social_network_get_provider($provider);
    $settings = seo_social_network_get_settings();

    if (!$post || 'publish' !== $post->post_status) {
        return new WP_Error('invalid_content', 'Solo se puede publicar contenido ya publicado en WordPress.');
    }

    if (!in_array($post->post_type, seo_social_network_supported_post_types(), true)) {
        return new WP_Error('unsupported_content', 'Este tipo de contenido no esta habilitado para redes sociales.');
    }

    if (!$provider_config || empty($provider_config['publish_callback']) || !is_callable($provider_config['publish_callback'])) {
        return new WP_Error('provider_unavailable', 'El proveedor no tiene un conector de publicacion disponible.');
    }

    $saved_provider = isset($settings['providers'][$provider]) && is_array($settings['providers'][$provider])
        ? $settings['providers'][$provider]
        : array();

    if (empty($saved_provider['enabled'])) {
        return new WP_Error('provider_disconnected', 'El proveedor no esta conectado.');
    }

    $template = trim((string) $template_override);
    if ($template === '') {
        $template = seo_social_network_get_template_for_content($post, $provider);
    }

    $publication_id = seo_social_network_create_pending_publication($content_id, $provider, $template);
    if (is_wp_error($publication_id)) {
        return $publication_id;
    }

    $tracking_url = seo_social_network_build_tracking_url($content_id, $provider, $publication_id);
    $message = seo_social_network_render_template($template, $post, $tracking_url);
    $image_url = get_the_post_thumbnail_url($post->ID, 'full');
    $image_url = $image_url ? $image_url : '';

    $payload = array(
        'content_id'     => $content_id,
        'publication_id' => $publication_id,
        'post'           => $post,
        'message'        => $message,
        'target_url'     => $tracking_url,
        'image_url'      => $image_url,
        'provider'       => $saved_provider,
    );

    $result = call_user_func($provider_config['publish_callback'], $payload);

    global $wpdb;
    $table = seo_social_network_publications_table();
    $now = current_time('mysql');

    if (is_wp_error($result)) {
        $wpdb->update(
            $table,
            array(
                'status'        => 'failed',
                'message'       => $message,
                'target_url'    => $tracking_url,
                'image_url'     => $image_url,
                'error_message' => $result->get_error_message(),
                'updated_at'    => $now,
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
            'published_at'  => $now,
            'updated_at'    => $now,
        ),
        array('id' => $publication_id),
        array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'),
        array('%d')
    );

    return array(
        'publication_id' => $publication_id,
        'remote_id'      => $remote_id,
        'remote_url'     => $remote_url,
    );
}

/**
 * @param int    $content_id
 * @param string $provider
 * @return object|null
 */
function seo_social_network_get_latest_publication($content_id, $provider)
{
    global $wpdb;
    $table = seo_social_network_publications_table();

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE content_id = %d AND provider = %s
             ORDER BY id DESC LIMIT 1",
            absint($content_id),
            sanitize_key($provider)
        )
    );
}

/**
 * Programa una publicacion automatica solo en la primera transicion a publish.
 *
 * @param string  $new_status
 * @param string  $old_status
 * @param WP_Post $post
 */
function seo_social_network_schedule_on_publish($new_status, $old_status, $post)
{
    if ('publish' !== $new_status || 'publish' === $old_status || !$post instanceof WP_Post) {
        return;
    }

    if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
        return;
    }

    if (!in_array($post->post_type, seo_social_network_supported_post_types(), true)) {
        return;
    }

    $settings = seo_social_network_get_settings();
    if (empty($settings['auto_publish'][$post->post_type])) {
        return;
    }

    foreach (seo_social_network_get_providers() as $provider_key => $provider) {
        if (empty($settings['providers'][$provider_key]['enabled'])) {
            continue;
        }

        $args = array($post->ID, sanitize_key($provider_key));
        if (!wp_next_scheduled('seo_social_network_publish_scheduled', $args)) {
            wp_schedule_single_event(time() + 5, 'seo_social_network_publish_scheduled', $args);
        }
    }
}
add_action('transition_post_status', 'seo_social_network_schedule_on_publish', 20, 3);

/**
 * @param int    $content_id
 * @param string $provider
 */
function seo_social_network_publish_scheduled($content_id, $provider)
{
    $latest = seo_social_network_get_latest_publication($content_id, $provider);
    if ($latest && in_array($latest->status, array('pending', 'published'), true)) {
        return;
    }

    $result = seo_social_network_publish_content($content_id, $provider);
    if (is_wp_error($result)) {
        error_log('[SEO Social] Auto-publicacion fallida: ' . $result->get_error_message());
    }
}
add_action('seo_social_network_publish_scheduled', 'seo_social_network_publish_scheduled', 10, 2);

/**
 * URL del modulo social.
 *
 * @param string $subtab
 * @param array  $args
 * @return string
 */
function seo_social_network_admin_url($subtab = 'publications', $args = array())
{
    return add_query_arg(
        array_merge(
            array(
                'page'          => 'seo-menu-marketing',
                'tab'           => 'social',
                'social_subtab' => sanitize_key($subtab),
            ),
            is_array($args) ? $args : array()
        ),
        admin_url('admin.php')
    );
}

/**
 * Guarda conexion de proveedor.
 */
function seo_social_network_handle_save_connection()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para configurar redes sociales.', 'seo-system'));
    }

    check_admin_referer('seo_social_network_save_connection');

    $provider_key = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
    $provider = seo_social_network_get_provider($provider_key);
    if (!$provider || empty($provider['sanitize_connection_callback']) || !is_callable($provider['sanitize_connection_callback'])) {
        wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'invalid_provider')));
        exit;
    }

    $settings = seo_social_network_get_settings();
    $current = isset($settings['providers'][$provider_key]) && is_array($settings['providers'][$provider_key])
        ? $settings['providers'][$provider_key]
        : array();
    $posted = isset($_POST['connection']) && is_array($_POST['connection'])
        ? wp_unslash($_POST['connection'])
        : array();

    $sanitized = call_user_func($provider['sanitize_connection_callback'], $posted, $current);
    if (is_wp_error($sanitized)) {
        wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'connection_error')));
        exit;
    }

    $settings['providers'][$provider_key] = $sanitized;
    seo_social_network_save_settings($settings);

    $message = 'connection_saved';
    if (!empty($_POST['test_after_save']) && !empty($provider['test_callback']) && is_callable($provider['test_callback'])) {
        $test = call_user_func($provider['test_callback'], $sanitized);
        $settings = seo_social_network_get_settings();
        $saved = $settings['providers'][$provider_key];
        $saved['last_test_at'] = current_time('mysql');

        if (is_wp_error($test)) {
            $saved['last_test_ok'] = 0;
            $saved['last_test_error'] = $test->get_error_message();
            $message = 'connection_test_failed';
        } else {
            $saved['last_test_ok'] = 1;
            $saved['last_test_error'] = '';
            if (isset($test['page_name'])) {
                $saved['page_name'] = sanitize_text_field((string) $test['page_name']);
            }
            if (isset($test['page_link'])) {
                $saved['page_link'] = esc_url_raw((string) $test['page_link']);
            }
            $message = 'connection_test_ok';
        }

        $settings['providers'][$provider_key] = $saved;
        seo_social_network_save_settings($settings);
    }

    wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => $message)));
    exit;
}
add_action('admin_post_seo_social_network_save_connection', 'seo_social_network_handle_save_connection');

/**
 * Desconecta un proveedor y elimina su secreto.
 */
function seo_social_network_handle_disconnect()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para configurar redes sociales.', 'seo-system'));
    }

    check_admin_referer('seo_social_network_disconnect');
    $provider_key = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
    $settings = seo_social_network_get_settings();

    if (isset($settings['providers'][$provider_key])) {
        $defaults = seo_social_network_default_settings();
        $settings['providers'][$provider_key] = isset($defaults['providers'][$provider_key])
            ? $defaults['providers'][$provider_key]
            : array('enabled' => 0);
        seo_social_network_save_settings($settings);
    }

    wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'disconnected')));
    exit;
}
add_action('admin_post_seo_social_network_disconnect', 'seo_social_network_handle_disconnect');

/**
 * Guarda automaticos y plantillas globales.
 */
function seo_social_network_handle_save_publication_settings()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para modificar las publicaciones sociales.', 'seo-system'));
    }

    check_admin_referer('seo_social_network_save_publication_settings');
    $settings = seo_social_network_get_settings();

    $auto = isset($_POST['auto_publish']) && is_array($_POST['auto_publish'])
        ? wp_unslash($_POST['auto_publish'])
        : array();
    foreach (seo_social_network_supported_post_types() as $post_type) {
        $settings['auto_publish'][$post_type] = !empty($auto[$post_type]) ? 1 : 0;
    }

    $templates = isset($_POST['templates']) && is_array($_POST['templates'])
        ? wp_unslash($_POST['templates'])
        : array();

    foreach (seo_social_network_get_providers() as $provider_key => $provider) {
        foreach (seo_social_network_supported_post_types() as $post_type) {
            if (isset($templates[$provider_key][$post_type])) {
                $value = trim((string) $templates[$provider_key][$post_type]);
                if ($value !== '') {
                    $settings['templates'][$provider_key][$post_type] = $value;
                }
            }
        }
    }

    seo_social_network_save_settings($settings);
    wp_safe_redirect(seo_social_network_admin_url('publications', array('social_msg' => 'publication_settings_saved')));
    exit;
}
add_action('admin_post_seo_social_network_save_publication_settings', 'seo_social_network_handle_save_publication_settings');

/**
 * Publicacion manual desde una fila.
 */
function seo_social_network_handle_publish_now()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para publicar en redes sociales.', 'seo-system'));
    }

    $content_id = isset($_POST['content_id']) ? absint($_POST['content_id']) : 0;
    $provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
    check_admin_referer('seo_social_network_publish_' . $content_id . '_' . $provider);

    $template = isset($_POST['message_template']) ? trim((string) wp_unslash($_POST['message_template'])) : '';
    $save_custom = !empty($_POST['save_custom_template']);
    if ($save_custom) {
        seo_social_network_save_custom_template($content_id, $provider, $template);
    }

    $result = seo_social_network_publish_content($content_id, $provider, $template);
    $args = array(
        'social_msg'        => is_wp_error($result) ? 'publish_failed' : 'published',
        'social_content_id' => $content_id,
    );

    if (is_wp_error($result)) {
        set_transient('seo_social_network_error_' . get_current_user_id(), $result->get_error_message(), 90);
    }

    wp_safe_redirect(seo_social_network_admin_url('publications', $args));
    exit;
}
add_action('admin_post_seo_social_network_publish_now', 'seo_social_network_handle_publish_now');

/**
 * Guarda o elimina una plantilla particular sin publicar.
 */
function seo_social_network_handle_save_content_template()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para modificar plantillas sociales.', 'seo-system'));
    }

    $content_id = isset($_POST['content_id']) ? absint($_POST['content_id']) : 0;
    $provider = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
    check_admin_referer('seo_social_network_template_' . $content_id . '_' . $provider);

    $template = isset($_POST['message_template']) ? trim((string) wp_unslash($_POST['message_template'])) : '';
    seo_social_network_save_custom_template($content_id, $provider, $template);

    wp_safe_redirect(
        seo_social_network_admin_url(
            'publications',
            array(
                'social_msg'        => 'content_template_saved',
                'social_content_id' => $content_id,
            )
        )
    );
    exit;
}
add_action('admin_post_seo_social_network_save_content_template', 'seo_social_network_handle_save_content_template');

/**
 * Sincroniza metricas de las publicaciones recientes de un proveedor.
 */
function seo_social_network_handle_sync_reports()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para actualizar informes sociales.', 'seo-system'));
    }

    check_admin_referer('seo_social_network_sync_reports');
    $provider_key = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : 'facebook';
    $provider = seo_social_network_get_provider($provider_key);
    $settings = seo_social_network_get_settings();

    if (!$provider || empty($provider['sync_callback']) || !is_callable($provider['sync_callback'])) {
        wp_safe_redirect(seo_social_network_admin_url('reports', array('social_msg' => 'sync_unavailable')));
        exit;
    }

    global $wpdb;
    $table = seo_social_network_publications_table();
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE provider = %s AND status = 'published' AND remote_id <> ''
             ORDER BY id DESC LIMIT 25",
            $provider_key
        )
    );

    $updated = 0;
    $failed = 0;
    foreach ((array) $rows as $row) {
        $result = call_user_func(
            $provider['sync_callback'],
            $row,
            isset($settings['providers'][$provider_key]) ? $settings['providers'][$provider_key] : array()
        );

        if (is_wp_error($result)) {
            $failed++;
            continue;
        }

        $data = array(
            'reactions'    => isset($result['reactions']) ? absint($result['reactions']) : absint($row->reactions),
            'comments'     => isset($result['comments']) ? absint($result['comments']) : absint($row->comments),
            'shares'       => isset($result['shares']) ? absint($result['shares']) : absint($row->shares),
            'impressions'  => isset($result['impressions']) ? absint($result['impressions']) : absint($row->impressions),
            'reach'        => isset($result['reach']) ? absint($result['reach']) : absint($row->reach),
            'last_sync_at' => current_time('mysql'),
            'updated_at'   => current_time('mysql'),
        );
        if (!empty($result['remote_url'])) {
            $data['remote_url'] = esc_url_raw((string) $result['remote_url']);
        }

        $wpdb->update($table, $data, array('id' => absint($row->id)));
        $updated++;
    }

    wp_safe_redirect(
        seo_social_network_admin_url(
            'reports',
            array(
                'social_msg'    => 'reports_synced',
                'sync_updated'  => $updated,
                'sync_failed'   => $failed,
            )
        )
    );
    exit;
}
add_action('admin_post_seo_social_network_sync_reports', 'seo_social_network_handle_sync_reports');

/**
 * Avisos del modulo.
 */
function seo_social_network_render_notice()
{
    $message = isset($_GET['social_msg']) ? sanitize_key(wp_unslash($_GET['social_msg'])) : '';
    if ($message === '') {
        return;
    }

    $messages = array(
        'connection_saved'           => array('success', 'Conexion guardada.'),
        'connection_test_ok'         => array('success', 'Conexion guardada y comprobada correctamente.'),
        'connection_test_failed'     => array('error', 'La configuracion se guardo, pero la prueba de conexion fallo.'),
        'connection_error'           => array('error', 'No se pudo guardar la conexion.'),
        'invalid_provider'           => array('error', 'El proveedor solicitado no esta disponible.'),
        'disconnected'               => array('success', 'Proveedor desconectado y credencial eliminada.'),
        'publication_settings_saved' => array('success', 'Automaticos y plantillas guardados.'),
        'content_template_saved'     => array('success', 'Plantilla particular guardada. Si la dejas vacia, se usa la plantilla general.'),
        'published'                  => array('success', 'Contenido publicado correctamente en la red social.'),
        'publish_failed'             => array('error', 'No se pudo publicar el contenido.'),
        'sync_unavailable'           => array('warning', 'Este proveedor aun no ofrece sincronizacion de metricas.'),
        'reports_synced'             => array('success', 'Metricas sociales actualizadas.'),
    );

    if (!isset($messages[$message])) {
        return;
    }

    $text = $messages[$message][1];
    if ('publish_failed' === $message) {
        $detail = get_transient('seo_social_network_error_' . get_current_user_id());
        if ($detail) {
            delete_transient('seo_social_network_error_' . get_current_user_id());
            $text .= ' ' . $detail;
        }
    }

    if ('reports_synced' === $message) {
        $updated = isset($_GET['sync_updated']) ? absint($_GET['sync_updated']) : 0;
        $failed = isset($_GET['sync_failed']) ? absint($_GET['sync_failed']) : 0;
        $text .= ' Actualizadas: ' . $updated . '. Fallidas: ' . $failed . '.';
    }

    echo '<div class="notice notice-' . esc_attr($messages[$message][0]) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
}

/**
 * Entrada de la pestaña principal Redes sociales.
 */
function seo_social_network_render_admin_tab()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    seo_social_network_maybe_install_tables();

    $allowed = array('publications', 'connections', 'reports');
    $subtab = isset($_GET['social_subtab']) ? sanitize_key(wp_unslash($_GET['social_subtab'])) : 'publications';
    if (!in_array($subtab, $allowed, true)) {
        $subtab = 'publications';
    }

    seo_social_network_render_styles();
    seo_social_network_render_notice();

    echo '<div class="seo-social-header">';
    echo '<div><h2>Redes sociales</h2><p>Publicacion, atribucion de visitas y resultados desde un unico modulo.</p></div>';
    echo '<div class="seo-social-provider-strip">';
    foreach (seo_social_network_get_providers() as $provider_key => $provider) {
        $settings = seo_social_network_get_settings();
        $connected = !empty($settings['providers'][$provider_key]['enabled']);
        echo '<span class="seo-social-provider-pill ' . ($connected ? 'is-connected' : '') . '">';
        echo esc_html(isset($provider['label']) ? $provider['label'] : ucfirst($provider_key));
        echo ' · ' . esc_html($connected ? 'conectado' : 'sin conectar');
        echo '</span>';
    }
    echo '<span class="seo-social-provider-pill is-planned">LinkedIn · preparado</span>';
    echo '<span class="seo-social-provider-pill is-planned">X · preparado</span>';
    echo '</div></div>';

    $tabs = array(
        'publications' => 'Publicaciones',
        'connections'  => 'Conexiones',
        'reports'      => 'Informes',
    );

    echo '<nav class="seo-social-subnav">';
    foreach ($tabs as $key => $label) {
        $class = $key === $subtab ? ' is-active' : '';
        echo '<a class="seo-social-subnav-link' . esc_attr($class) . '" href="' . esc_url(seo_social_network_admin_url($key)) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';

    if ('connections' === $subtab) {
        seo_social_network_render_connections();
    } elseif ('reports' === $subtab) {
        seo_social_network_render_reports();
    } else {
        seo_social_network_render_publications();
    }
}

/**
 * CSS aislado del modulo.
 */
function seo_social_network_render_styles()
{
    echo '<style>
        .seo-social-header{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin:4px 0 18px;padding:20px;background:#fff;border:1px solid #dcdcde;border-radius:8px}.seo-social-header h2{margin:0 0 5px;font-size:22px}.seo-social-header p{margin:0;color:#646970}.seo-social-provider-strip{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.seo-social-provider-pill{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;background:#f0f0f1;color:#50575e;font-size:12px;font-weight:600}.seo-social-provider-pill.is-connected{background:#edfaef;color:#176b2c}.seo-social-provider-pill.is-planned{background:#f6f7f7;color:#787c82}
        .seo-social-subnav{display:flex;gap:8px;margin:0 0 18px;border-bottom:1px solid #c3c4c7}.seo-social-subnav-link{display:inline-block;margin-bottom:-1px;padding:10px 14px;text-decoration:none;border:1px solid transparent;border-radius:6px 6px 0 0;font-weight:600}.seo-social-subnav-link.is-active{background:#fff;border-color:#c3c4c7 #c3c4c7 #fff;color:#1d2327}
        .seo-social-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:0 0 18px}.seo-social-card h2,.seo-social-card h3{margin-top:0}.seo-social-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}.seo-social-field label{display:block;font-weight:600;margin:0 0 6px}.seo-social-field input[type=text],.seo-social-field input[type=password],.seo-social-field select,.seo-social-field textarea{width:100%}.seo-social-field textarea{min-height:120px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}.seo-social-help{color:#646970;font-size:12px;line-height:1.45}.seo-social-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:16px}.seo-social-state{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:11px;font-weight:700;background:#f0f0f1;color:#50575e}.seo-social-state.is-published,.seo-social-state.is-ok{background:#edfaef;color:#176b2c}.seo-social-state.is-failed{background:#fcf0f1;color:#b32d2e}.seo-social-state.is-pending{background:#fff8e5;color:#8a5500}
        .seo-social-vars{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 0}.seo-social-vars code{font-size:11px;padding:3px 6px;background:#f6f7f7}.seo-social-table-wrap{overflow:auto}.seo-social-table{width:100%;border-collapse:collapse}.seo-social-table th,.seo-social-table td{padding:12px 10px;border-bottom:1px solid #e2e4e7;text-align:left;vertical-align:top}.seo-social-table th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#50575e}.seo-social-content-title{min-width:230px}.seo-social-editor{min-width:360px}.seo-social-editor textarea{width:100%;min-height:120px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}.seo-social-preview{white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px;max-height:170px;overflow:auto;font-size:12px;line-height:1.45}.seo-social-row-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.seo-social-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}.seo-social-metric{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}.seo-social-metric strong{display:block;font-size:24px;line-height:1.1}.seo-social-metric span{display:block;color:#646970;margin-top:5px;font-size:12px}.seo-social-provider-card{position:relative}.seo-social-provider-card .dashicons{font-size:32px;width:32px;height:32px;margin-bottom:8px}.seo-social-code-note{padding:10px 12px;background:#f6f7f7;border-left:4px solid #2271b1}.seo-social-filterbar{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.seo-social-filterbar .seo-social-field{min-width:180px;flex:1}.seo-social-filterbar .seo-social-field.is-search{min-width:280px;flex:2}
        @media(max-width:900px){.seo-social-header{flex-direction:column}.seo-social-provider-strip{justify-content:flex-start}.seo-social-editor{min-width:290px}}
    </style>';
}

/**
 * Subpestana de conexiones.
 */
function seo_social_network_render_connections()
{
    $settings = seo_social_network_get_settings();
    $providers = seo_social_network_get_providers();

    echo '<div class="seo-social-grid">';
    foreach ($providers as $provider_key => $provider) {
        $config = isset($settings['providers'][$provider_key]) ? $settings['providers'][$provider_key] : array();
        echo '<section class="seo-social-card seo-social-provider-card">';
        echo '<span class="dashicons dashicons-facebook"></span>';
        echo '<h2>' . esc_html(isset($provider['label']) ? $provider['label'] : ucfirst($provider_key)) . '</h2>';

        if (!empty($config['enabled'])) {
            echo '<p><span class="seo-social-state is-ok">Conectado</span> ';
            if (!empty($config['page_name'])) {
                echo '<strong>' . esc_html($config['page_name']) . '</strong>';
            }
            echo '</p>';
        } else {
            echo '<p><span class="seo-social-state">Sin conectar</span></p>';
        }

        if (!empty($provider['render_connection_callback']) && is_callable($provider['render_connection_callback'])) {
            call_user_func($provider['render_connection_callback'], $config);
        }
        echo '</section>';
    }

    echo '<section class="seo-social-card seo-social-provider-card">';
    echo '<span class="dashicons dashicons-linkedin"></span><h2>LinkedIn</h2><p><span class="seo-social-state">Preparado para siguiente fase</span></p><p class="seo-social-help">La arquitectura de proveedor ya esta separada. Se anadira como conector independiente sin tocar la logica de publicaciones o informes.</p>';
    echo '</section>';

    echo '<section class="seo-social-card seo-social-provider-card">';
    echo '<span class="dashicons dashicons-share"></span><h2>X / otras redes</h2><p><span class="seo-social-state">Preparado para siguiente fase</span></p><p class="seo-social-help">Cada nueva red podra definir su conexion, longitud/formato de texto, publicacion y sincronizacion de metricas.</p>';
    echo '</section>';
    echo '</div>';
}

/**
 * Consulta contenidos publicados para la pantalla.
 *
 * @return WP_Query
 */
function seo_social_network_publications_query()
{
    $type = isset($_GET['social_type']) ? sanitize_key(wp_unslash($_GET['social_type'])) : '';
    $search = isset($_GET['social_search']) ? sanitize_text_field(wp_unslash($_GET['social_search'])) : '';
    $supported = seo_social_network_supported_post_types();

    $post_types = in_array($type, $supported, true) ? array($type) : $supported;

    return new WP_Query(
        apply_filters(
            'seo_social_network_publications_query_args',
            array(
                'post_type'              => $post_types,
                'post_status'            => 'publish',
                'posts_per_page'         => 40,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                's'                      => $search,
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
            )
        )
    );
}

/**
 * Subpestana Publicaciones.
 */
function seo_social_network_render_publications()
{
    $settings = seo_social_network_get_settings();
    $providers = seo_social_network_get_providers();

    echo '<section class="seo-social-card">';
    echo '<h2>Automaticos y plantillas</h2>';
    echo '<p>Las variables se sustituyen justo antes de publicar. Cada contenido puede tener una plantilla propia sin perder la general.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_social_network_save_publication_settings">';
    wp_nonce_field('seo_social_network_save_publication_settings');

    echo '<div class="seo-social-grid">';
    echo '<div>';
    echo '<h3>Publicacion automatica</h3>';
    echo '<label><input type="checkbox" name="auto_publish[post]" value="1" ' . checked(!empty($settings['auto_publish']['post']), true, false) . '> Entradas nuevas al publicarse</label><br>';
    echo '<label><input type="checkbox" name="auto_publish[page]" value="1" ' . checked(!empty($settings['auto_publish']['page']), true, false) . '> Paginas / Landings nuevas al publicarse</label>';
    echo '<p class="seo-social-help">Solo se dispara en la primera transicion a Publicado. Las actualizaciones posteriores no generan duplicados automaticamente.</p>';
    echo '</div>';

    foreach ($providers as $provider_key => $provider) {
        echo '<div>';
        echo '<h3>Plantillas · ' . esc_html(isset($provider['label']) ? $provider['label'] : ucfirst($provider_key)) . '</h3>';
        foreach (array('post' => 'Entradas', 'page' => 'Paginas / Landings') as $post_type => $label) {
            $value = isset($settings['templates'][$provider_key][$post_type])
                ? (string) $settings['templates'][$provider_key][$post_type]
                : '';
            echo '<div class="seo-social-field" style="margin-bottom:12px"><label>' . esc_html($label) . '</label><textarea name="templates[' . esc_attr($provider_key) . '][' . esc_attr($post_type) . ']">' . esc_textarea($value) . '</textarea></div>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="seo-social-vars"><strong>Comodines:</strong> ';
    foreach (array('{titulo}', '{extracto}', '{fecha}', '{url}', '{sitio}', '{autor}', '{tipo}', '{categorias}', '{imagen}') as $var) {
        echo '<code>' . esc_html($var) . '</code>';
    }
    echo '</div>';
    echo '<div class="seo-social-actions"><button type="submit" class="button button-primary">Guardar automaticos y plantillas</button></div>';
    echo '</form>';
    echo '</section>';

    echo '<section class="seo-social-card">';
    echo '<h2>Contenido publicable</h2>';
    echo '<form method="get" class="seo-social-filterbar">';
    echo '<input type="hidden" name="page" value="seo-menu-marketing"><input type="hidden" name="tab" value="social"><input type="hidden" name="social_subtab" value="publications">';
    echo '<div class="seo-social-field"><label>Tipo</label><select name="social_type"><option value="">Entradas + Paginas/Landings</option>';
    $current_type = isset($_GET['social_type']) ? sanitize_key(wp_unslash($_GET['social_type'])) : '';
    echo '<option value="post" ' . selected($current_type, 'post', false) . '>Entradas</option><option value="page" ' . selected($current_type, 'page', false) . '>Paginas / Landings</option></select></div>';
    $search = isset($_GET['social_search']) ? sanitize_text_field(wp_unslash($_GET['social_search'])) : '';
    echo '<div class="seo-social-field is-search"><label>Buscar</label><input type="text" name="social_search" value="' . esc_attr($search) . '" placeholder="Titulo del contenido..."></div>';
    echo '<button class="button" type="submit">Filtrar</button>';
    echo '</form>';

    $query = seo_social_network_publications_query();
    echo '<div class="seo-social-table-wrap"><table class="seo-social-table"><thead><tr><th>Contenido</th><th>Red / estado</th><th>Texto para publicar</th></tr></thead><tbody>';

    if (!$query->have_posts()) {
        echo '<tr><td colspan="3">No se ha encontrado contenido publicado.</td></tr>';
    }

    while ($query->have_posts()) {
        $query->the_post();
        $post = get_post();
        if (!$post) {
            continue;
        }

        echo '<tr id="seo-social-content-' . esc_attr((string) $post->ID) . '">';
        echo '<td class="seo-social-content-title"><strong><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html(get_the_title($post)) . '</a></strong><br><span class="seo-social-state">' . esc_html(seo_social_network_content_type_label($post)) . '</span><p class="seo-social-help">Publicado: ' . esc_html(get_the_date('', $post)) . '<br>#' . esc_html((string) $post->ID) . '</p>';
        if (has_post_thumbnail($post)) {
            echo get_the_post_thumbnail($post->ID, array(90, 60), array('style' => 'width:90px;height:60px;object-fit:cover;border-radius:5px')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo '</td>';

        echo '<td>';
        foreach ($providers as $provider_key => $provider) {
            $latest = seo_social_network_get_latest_publication($post->ID, $provider_key);
            $connected = !empty($settings['providers'][$provider_key]['enabled']);
            $status = $latest ? sanitize_key($latest->status) : '';
            $label = isset($provider['label']) ? $provider['label'] : ucfirst($provider_key);
            echo '<p><strong>' . esc_html($label) . '</strong><br>';
            if ($latest) {
                echo '<span class="seo-social-state is-' . esc_attr($status) . '">' . esc_html(ucfirst($status)) . '</span>';
                if ('published' === $status && !empty($latest->published_at)) {
                    echo '<br><small>' . esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $latest->published_at)) . '</small>';
                }
                if (!empty($latest->remote_url)) {
                    echo '<br><a href="' . esc_url($latest->remote_url) . '" target="_blank" rel="noopener noreferrer">Ver publicacion</a>';
                }
                if ('failed' === $status && !empty($latest->error_message)) {
                    echo '<br><small style="color:#b32d2e">' . esc_html(wp_trim_words($latest->error_message, 18, '...')) . '</small>';
                }
                if ('published' === $status) {
                    echo '<br><small>Visitas web: ' . esc_html(number_format_i18n((int) $latest->clicks)) . '</small>';
                }
            } else {
                echo '<span class="seo-social-state">No publicado</span>';
            }
            if (!$connected) {
                echo '<br><small><a href="' . esc_url(seo_social_network_admin_url('connections')) . '">Conectar primero</a></small>';
            }
            echo '</p>';
        }
        echo '</td>';

        echo '<td class="seo-social-editor">';
        foreach ($providers as $provider_key => $provider) {
            $template = seo_social_network_get_template_for_content($post, $provider_key);
            $is_custom = (string) get_post_meta($post->ID, seo_social_network_custom_template_meta_key($post->ID, $provider_key), true) !== '';
            $preview_url = add_query_arg(array('utm_source' => $provider_key, 'utm_medium' => 'social'), get_permalink($post));
            $preview = seo_social_network_render_template($template, $post, $preview_url);

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:18px">';
            echo '<input type="hidden" name="content_id" value="' . esc_attr((string) $post->ID) . '"><input type="hidden" name="provider" value="' . esc_attr($provider_key) . '">';
            wp_nonce_field('seo_social_network_publish_' . $post->ID . '_' . $provider_key);
            echo '<label><strong>' . esc_html(isset($provider['label']) ? $provider['label'] : ucfirst($provider_key)) . '</strong> ' . ($is_custom ? '<span class="seo-social-state is-pending">Plantilla propia</span>' : '<span class="seo-social-state">Plantilla general</span>') . '</label>';
            echo '<textarea name="message_template">' . esc_textarea($template) . '</textarea>';
            echo '<details style="margin-top:6px"><summary style="cursor:pointer">Vista previa</summary><div class="seo-social-preview">' . esc_html($preview) . '</div></details>';
            echo '<label style="display:block;margin-top:7px"><input type="checkbox" name="save_custom_template" value="1"> Guardar este texto como plantilla propia del contenido</label>';
            echo '<div class="seo-social-row-actions">';
            echo '<button type="submit" name="action" value="seo_social_network_publish_now" class="button button-primary" ' . disabled(empty($settings['providers'][$provider_key]['enabled']), true, false) . '>Publicar ahora</button>';
            echo '<button type="submit" name="action" value="seo_social_network_save_content_template" class="button" formaction="' . esc_url(admin_url('admin-post.php')) . '" onclick="this.form.querySelector(\'[name=_wpnonce]\').value=\'' . esc_js(wp_create_nonce('seo_social_network_template_' . $post->ID . '_' . $provider_key)) . '\';">Guardar plantilla</button>';
            if ($is_custom) {
                echo '<span class="seo-social-help">Vacia el campo y pulsa Guardar plantilla para volver a la general.</span>';
            }
            echo '</div></form>';
        }
        echo '</td>';
        echo '</tr>';
    }
    wp_reset_postdata();

    echo '</tbody></table></div>';
    echo '</section>';
}

/**
 * Subpestana Informes.
 */
function seo_social_network_render_reports()
{
    global $wpdb;
    $table = seo_social_network_publications_table();

    $summary = $wpdb->get_row(
        "SELECT
            COUNT(*) AS total_attempts,
            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_count,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
            COALESCE(SUM(clicks),0) AS clicks,
            COALESCE(SUM(reactions),0) AS reactions,
            COALESCE(SUM(comments),0) AS comments,
            COALESCE(SUM(shares),0) AS shares
         FROM {$table}"
    );

    echo '<div class="seo-social-metrics">';
    $metrics = array(
        'Publicaciones' => $summary ? (int) $summary->published_count : 0,
        'Visitas a la web' => $summary ? (int) $summary->clicks : 0,
        'Reacciones' => $summary ? (int) $summary->reactions : 0,
        'Comentarios' => $summary ? (int) $summary->comments : 0,
        'Compartidos' => $summary ? (int) $summary->shares : 0,
        'Errores' => $summary ? (int) $summary->failed_count : 0,
    );
    foreach ($metrics as $label => $value) {
        echo '<div class="seo-social-metric"><strong>' . esc_html(number_format_i18n($value)) . '</strong><span>' . esc_html($label) . '</span></div>';
    }
    echo '</div>';

    echo '<section class="seo-social-card">';
    echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap"><div><h2>Rendimiento por publicacion</h2><p>Las visitas a la web se miden con el enlace etiquetado generado por el plugin. Las interacciones se sincronizan desde cada proveedor cuando su API lo permite.</p></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_social_network_sync_reports"><input type="hidden" name="provider" value="facebook">';
    wp_nonce_field('seo_social_network_sync_reports');
    echo '<button type="submit" class="button button-primary">Actualizar metricas de Facebook</button></form></div>';
    echo '<p class="seo-social-code-note">La metrica <strong>Visitas a la web</strong> no depende de Facebook: se incrementa cuando alguien entra mediante el enlace social firmado. Esto permite comparar que publicaciones traen trafico real al sitio.</p>';

    $rows = $wpdb->get_results(
        "SELECT sp.*, p.post_title
         FROM {$table} sp
         LEFT JOIN {$wpdb->posts} p ON p.ID = sp.content_id
         ORDER BY sp.id DESC
         LIMIT 100"
    );

    echo '<div class="seo-social-table-wrap"><table class="seo-social-table"><thead><tr><th>Contenido</th><th>Red</th><th>Estado</th><th>Fecha</th><th>Visitas web</th><th>Reacciones</th><th>Comentarios</th><th>Compartidos</th><th>Enlace</th></tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="9">Aun no hay publicaciones sociales registradas.</td></tr>';
    }
    foreach ((array) $rows as $row) {
        echo '<tr>';
        echo '<td><strong>' . esc_html($row->post_title ? $row->post_title : 'Contenido #' . $row->content_id) . '</strong><br><small>#' . esc_html((string) $row->content_id) . '</small></td>';
        echo '<td>' . esc_html(ucfirst($row->provider)) . '</td>';
        echo '<td><span class="seo-social-state is-' . esc_attr($row->status) . '">' . esc_html(ucfirst($row->status)) . '</span>';
        if (!empty($row->error_message)) {
            echo '<br><small style="color:#b32d2e">' . esc_html(wp_trim_words($row->error_message, 14, '...')) . '</small>';
        }
        echo '</td>';
        echo '<td>' . esc_html($row->published_at ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $row->published_at) : '-') . '</td>';
        echo '<td><strong>' . esc_html(number_format_i18n((int) $row->clicks)) . '</strong></td>';
        echo '<td>' . esc_html(number_format_i18n((int) $row->reactions)) . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $row->comments)) . '</td>';
        echo '<td>' . esc_html(number_format_i18n((int) $row->shares)) . '</td>';
        echo '<td>';
        if (!empty($row->remote_url)) {
            echo '<a href="' . esc_url($row->remote_url) . '" target="_blank" rel="noopener noreferrer">Red social</a><br>';
        }
        if (!empty($row->target_url)) {
            echo '<a href="' . esc_url($row->target_url) . '" target="_blank" rel="noopener noreferrer">URL medida</a>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '</section>';
}
