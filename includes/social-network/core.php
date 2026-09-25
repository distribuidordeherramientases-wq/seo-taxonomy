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
        'auto_publish_providers' => array(
            'facebook'  => 1,
            'instagram' => 1,
            'linkedin'  => 1,
            'pinterest' => 1,
            'x'         => 1,
        ),
        'templates' => array(
            'facebook' => array(
                'post'     => "{titulo}\n\n{extracto}\n\n{url}",
                'page'     => "{titulo}\n\n{extracto}\n\n{url}",
                'campaign' => "{producto}\n\n{campana}: {precio_oferta} (antes {precio_regular}).\nDisponible hasta {fecha_fin}.",
            ),
            'instagram' => array(
                'post'     => "{titulo}\n\n{extracto}\n\n{url}",
                'page'     => "{titulo}\n\n{extracto}\n\n{url}",
                'campaign' => "{producto}\n\n{campana}: {precio_oferta} (antes {precio_regular}).\nDisponible hasta {fecha_fin}.\n\n{url}",
            ),
            'linkedin' => array(
                'post'     => "{titulo}\n\n{extracto}",
                'page'     => "{titulo}\n\n{extracto}",
                'campaign' => "{producto}\n\nDisponible dentro de {campana} por {precio_oferta} (precio habitual {precio_regular}).\nOferta hasta {fecha_fin}.",
            ),
            'pinterest' => array(
                'post'     => "{titulo}\n\n{extracto}",
                'page'     => "{titulo}\n\n{extracto}",
                'campaign' => "{producto}\n\n{campana}. Precio de campaña: {precio_oferta}. Disponible hasta {fecha_fin}.",
            ),
            'x' => array(
                'post'     => "{titulo}\n\n{url}",
                'page'     => "{titulo}\n\n{url}",
                'campaign' => "{producto}: {precio_oferta} · {campana}\n{url}",
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
            'instagram' => array(
                'enabled'                 => 0,
                'use_facebook_connection' => 1,
                'ig_user_id'              => '',
                'account_username'        => '',
                'page_name'               => '',
                'page_link'               => '',
                'access_token_enc'        => '',
                'api_version'             => 'v25.0',
                'publish_mode'            => 'image',
                'last_test_at'            => '',
                'last_test_ok'            => 0,
                'last_test_error'         => '',
            ),
            'linkedin' => array(
                'enabled'                  => 0,
                'client_id'                => '',
                'client_secret_enc'        => '',
                'access_token_enc'         => '',
                'refresh_token_enc'        => '',
                'token_expires_at'         => 0,
                'refresh_token_expires_at' => 0,
                'organization_id'          => '',
                'organization_name'        => '',
                'organization_vanity'      => '',
                'organization_link'        => '',
                'page_name'                => '',
                'page_link'                => '',
                'organizations'            => array(),
                'api_version'              => '202608',
                'publish_mode'             => 'article',
                'last_test_at'             => '',
                'last_test_ok'             => 0,
                'last_test_error'          => '',
            ),
            'pinterest' => array(
                'enabled'                  => 0,
                'app_id'                   => '',
                'app_secret_enc'           => '',
                'access_token_enc'         => '',
                'refresh_token_enc'        => '',
                'token_expires_at'         => 0,
                'refresh_token_expires_at' => 0,
                'scope'                    => 'boards:read pins:read pins:write',
                'board_id'                 => '',
                'board_name'               => '',
                'boards'                   => array(),
                'account_username'         => '',
                'page_name'                => '',
                'page_link'                => '',
                'publish_mode'             => 'image',
                'last_test_at'             => '',
                'last_test_ok'             => 0,
                'last_test_error'          => '',
            ),
            'x' => array(
                'enabled'                  => 0,
                'client_id'                => '',
                'client_secret_enc'        => '',
                'access_token_enc'         => '',
                'refresh_token_enc'        => '',
                'token_expires_at'         => 0,
                'scope'                    => 'tweet.read tweet.write users.read offline.access',
                'user_id'                  => '',
                'username'                 => '',
                'page_name'                => '',
                'page_link'                => '',
                'publish_mode'             => 'text',
                'last_test_at'             => '',
                'last_test_ok'             => 0,
                'last_test_error'          => '',
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

    if (!isset($settings['auto_publish_providers']) || !is_array($settings['auto_publish_providers'])) {
        $settings['auto_publish_providers'] = $defaults['auto_publish_providers'];
    }
    foreach (array_keys($defaults['auto_publish_providers']) as $provider_key) {
        $settings['auto_publish_providers'][$provider_key] = !empty($settings['auto_publish_providers'][$provider_key]) ? 1 : 0;
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
        if (isset($settings['auto_publish_providers'][$provider_key]) && empty($settings['auto_publish_providers'][$provider_key])) {
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
 * Clave meta de una programacion manual.
 *
 * @param string $provider
 * @return string
 */
function seo_social_network_schedule_meta_key($provider)
{
    return '_seo_social_schedule_' . sanitize_key($provider);
}

/**
 * @param int    $content_id
 * @param string $provider
 * @return int
 */
function seo_social_network_get_scheduled_timestamp($content_id, $provider)
{
    return absint(get_post_meta(absint($content_id), seo_social_network_schedule_meta_key($provider), true));
}

/**
 * Programa o reprograma una publicacion concreta.
 *
 * @param int    $content_id
 * @param string $provider
 * @param int    $timestamp Unix timestamp UTC.
 * @return true|WP_Error
 */
function seo_social_network_set_scheduled_publication($content_id, $provider, $timestamp)
{
    $content_id = absint($content_id);
    $provider = sanitize_key($provider);
    $timestamp = absint($timestamp);
    $post = get_post($content_id);

    if (!$post || 'publish' !== $post->post_status) {
        return new WP_Error('invalid_content', 'El contenido no existe o no esta publicado.');
    }
    if (!seo_social_network_get_provider($provider)) {
        return new WP_Error('invalid_provider', 'La red social indicada no esta disponible.');
    }
    if ($timestamp <= time() + 30) {
        return new WP_Error('invalid_schedule', 'La fecha debe estar en el futuro.');
    }

    $args = array($content_id, $provider);
    wp_clear_scheduled_hook('seo_social_network_publish_manual_scheduled', $args);
    update_post_meta($content_id, seo_social_network_schedule_meta_key($provider), $timestamp);

    if (!wp_schedule_single_event($timestamp, 'seo_social_network_publish_manual_scheduled', $args)) {
        delete_post_meta($content_id, seo_social_network_schedule_meta_key($provider));
        return new WP_Error('schedule_failed', 'WordPress no pudo crear la tarea programada.');
    }

    return true;
}

/**
 * @param int    $content_id
 * @param string $provider
 */
function seo_social_network_clear_scheduled_publication($content_id, $provider)
{
    $content_id = absint($content_id);
    $provider = sanitize_key($provider);
    wp_clear_scheduled_hook('seo_social_network_publish_manual_scheduled', array($content_id, $provider));
    delete_post_meta($content_id, seo_social_network_schedule_meta_key($provider));
}

/**
 * Ejecuta una publicacion creada desde el Programador.
 *
 * @param int    $content_id
 * @param string $provider
 */
function seo_social_network_publish_manual_scheduled($content_id, $provider)
{
    seo_social_network_clear_scheduled_publication($content_id, $provider);
    $result = seo_social_network_publish_content($content_id, $provider);
    if (is_wp_error($result)) {
        error_log('[SEO Social] Publicacion programada fallida: ' . $result->get_error_message());
    }
}
add_action('seo_social_network_publish_manual_scheduled', 'seo_social_network_publish_manual_scheduled', 10, 2);

/**
 * URL del modulo social.
 *
 * @param string $subtab
 * @param array  $args
 * @return string
 */
function seo_social_network_admin_url($subtab = 'templates', $args = array())
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
 * Guarda exclusivamente las plantillas generales.
 */
function seo_social_network_handle_save_templates()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para modificar plantillas sociales.', 'seo-system'));
    }

    check_admin_referer('seo_social_network_save_templates');
    $settings = seo_social_network_get_settings();
    $templates = isset($_POST['templates']) && is_array($_POST['templates'])
        ? wp_unslash($_POST['templates'])
        : array();

    foreach (seo_social_network_get_providers() as $provider_key => $provider) {
        foreach (seo_social_network_supported_post_types() as $post_type) {
            if (!isset($templates[$provider_key][$post_type])) {
                continue;
            }
            $value = trim((string) $templates[$provider_key][$post_type]);
            if ($value !== '') {
                $settings['templates'][$provider_key][$post_type] = $value;
            }
        }

        // Marketing / Campañas no es un post_type de WordPress, pero comparte
        // la misma pantalla de plantillas y se guarda por proveedor.
        if (isset($templates[$provider_key]['campaign'])) {
            $campaign_value = trim((string) $templates[$provider_key]['campaign']);
            if ($campaign_value !== '') {
                $settings['templates'][$provider_key]['campaign'] = $campaign_value;
            }
        }
    }

    seo_social_network_save_settings($settings);
    wp_safe_redirect(seo_social_network_admin_url('templates', array('social_msg' => 'templates_saved')));
    exit;
}
add_action('admin_post_seo_social_network_save_templates', 'seo_social_network_handle_save_templates');

/**
 * Guarda exclusivamente las reglas de publicacion automatica.
 */
function seo_social_network_handle_save_automation()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para modificar la automatizacion social.', 'seo-system'));
    }

    check_admin_referer('seo_social_network_save_automation');
    $settings = seo_social_network_get_settings();

    $auto = isset($_POST['auto_publish']) && is_array($_POST['auto_publish'])
        ? wp_unslash($_POST['auto_publish'])
        : array();
    foreach (seo_social_network_supported_post_types() as $post_type) {
        $settings['auto_publish'][$post_type] = !empty($auto[$post_type]) ? 1 : 0;
    }

    $targets = isset($_POST['auto_publish_providers']) && is_array($_POST['auto_publish_providers'])
        ? wp_unslash($_POST['auto_publish_providers'])
        : array();
    foreach (seo_social_network_get_providers() as $provider_key => $provider) {
        $settings['auto_publish_providers'][$provider_key] = !empty($targets[$provider_key]) ? 1 : 0;
    }

    seo_social_network_save_settings($settings);
    wp_safe_redirect(seo_social_network_admin_url('automation', array('social_msg' => 'automation_saved')));
    exit;
}
add_action('admin_post_seo_social_network_save_automation', 'seo_social_network_handle_save_automation');

/**
 * Columnas reconocidas por el importador CSV del Programador.
 *
 * El formato esta pensado para Excel/LibreOffice usando CSV UTF-8 con punto y coma.
 * Se aceptan algunos alias para que una exportacion antigua o una hoja manual siga
 * siendo importable.
 *
 * @return array<string,string[]>
 */
function seo_social_network_scheduler_import_columns()
{
    return array(
        'content_id'   => array('contenido_id', 'content_id', 'id', 'post_id'),
        'providers'    => array('redes', 'red', 'provider', 'providers'),
        'scheduled_at' => array('fecha_hora', 'fecha', 'scheduled_at', 'date_time', 'datetime'),
        'title'        => array('titulo', 'title'),
    );
}

/**
 * Evita que Excel interprete como formula un valor exportado a CSV.
 *
 * @param mixed $value
 * @return string
 */
function seo_social_network_scheduler_csv_safe_cell($value)
{
    $value = (string) $value;
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

/**
 * Envia un CSV UTF-8 compatible con Excel y finaliza la peticion.
 *
 * @param string $filename
 * @param array  $headers
 * @param array  $rows
 */
function seo_social_network_scheduler_send_csv($filename, $headers, $rows)
{
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    if (false === $out) {
        wp_die(esc_html__('No se pudo generar el archivo CSV.', 'seo-system'));
    }

    // BOM UTF-8 para que Excel conserve acentos sin preguntar por la codificacion.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_map('seo_social_network_scheduler_csv_safe_cell', $headers), ';', '"', '');
    foreach ((array) $rows as $row) {
        fputcsv($out, array_map('seo_social_network_scheduler_csv_safe_cell', (array) $row), ';', '"', '');
    }
    fclose($out);
    exit;
}

/**
 * Devuelve contenidos publicables para facilitar la preparacion del Excel.
 *
 * @return array<int,array<int,string>>
 */
function seo_social_network_scheduler_exportable_content_rows()
{
    global $wpdb;
    $types = function_exists('seo_social_network_supported_post_types')
        ? array_values(array_filter(array_map('sanitize_key', seo_social_network_supported_post_types())))
        : array('post', 'page');

    if (empty($types)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($types), '%s'));
    $sql = "SELECT ID, post_title, post_type, post_modified
            FROM {$wpdb->posts}
            WHERE post_status = 'publish'
              AND post_type IN ({$placeholders})
            ORDER BY post_modified DESC, ID DESC";
    $rows = $wpdb->get_results($wpdb->prepare($sql, $types)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    $result = array();
    foreach ((array) $rows as $row) {
        $post = get_post((int) $row->ID);
        if (!$post) {
            continue;
        }
        $result[] = array(
            (string) $post->ID,
            get_the_title($post),
            function_exists('seo_social_network_content_type_label') ? seo_social_network_content_type_label($post) : $post->post_type,
            get_permalink($post),
            has_post_thumbnail($post) ? 'si' : 'no',
        );
    }
    return $result;
}

/**
 * Devuelve la agenda manual vigente (una fila por contenido/red).
 *
 * @return array<int,array<int,string>>
 */
function seo_social_network_scheduler_exportable_agenda_rows()
{
    global $wpdb;
    $prefix = '_seo_social_schedule_';
    $like = $wpdb->esc_like($prefix) . '%';
    $sql = $wpdb->prepare(
        "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key LIKE %s
           AND p.post_status = 'publish'
         ORDER BY CAST(pm.meta_value AS UNSIGNED) ASC, pm.post_id ASC",
        $like
    );
    $rows = $wpdb->get_results($sql);
    $providers = function_exists('seo_social_network_get_providers') ? seo_social_network_get_providers() : array();
    $result = array();

    foreach ((array) $rows as $row) {
        $timestamp = absint($row->meta_value);
        if (!$timestamp) {
            continue;
        }
        $provider = sanitize_key(substr((string) $row->meta_key, strlen($prefix)));
        if ($provider === '' || !isset($providers[$provider])) {
            continue;
        }
        $result[] = array(
            (string) absint($row->post_id),
            (string) $row->post_title,
            (string) $row->post_type,
            $provider,
            wp_date('Y-m-d H:i', $timestamp, wp_timezone()),
            wp_timezone_string(),
            'programada',
        );
    }

    if (function_exists('seo_social_campaign_exportable_agenda_rows')) {
        $result = array_merge($result, seo_social_campaign_exportable_agenda_rows());
    }

    return $result;
}

/**
 * Historial de intentos de publicacion para exportacion.
 *
 * @return array<int,array<int,string>>
 */
function seo_social_network_scheduler_exportable_history_rows()
{
    global $wpdb;
    $table = seo_social_network_publications_table();
    $rows = $wpdb->get_results(
        "SELECT sp.*, p.post_title
         FROM {$table} sp
         LEFT JOIN {$wpdb->posts} p ON p.ID = sp.content_id
         ORDER BY sp.id DESC"
    ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

    $result = array();
    foreach ((array) $rows as $row) {
        $result[] = array(
            (string) absint($row->id),
            (string) absint($row->content_id),
            $row->post_title ? (string) $row->post_title : 'Contenido #' . absint($row->content_id),
            (string) $row->content_type,
            (string) $row->provider,
            (string) $row->status,
            (string) ($row->published_at ?: ''),
            (string) absint($row->clicks),
            (string) absint($row->reactions),
            (string) absint($row->comments),
            (string) absint($row->shares),
            (string) ($row->target_url ?: ''),
            (string) ($row->remote_url ?: ''),
            (string) ($row->error_message ?: ''),
        );
    }
    return $result;
}

/**
 * Exportaciones CSV del Programador.
 */
function seo_social_network_handle_scheduler_export()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para exportar la programacion social.', 'seo-system'));
    }
    check_admin_referer('seo_social_network_scheduler_export');

    $kind = isset($_POST['export_kind']) ? sanitize_key(wp_unslash($_POST['export_kind'])) : '';
    $stamp = wp_date('Ymd-His', time(), wp_timezone());

    if ('template' === $kind) {
        seo_social_network_scheduler_send_csv(
            'programacion-social-plantilla-' . $stamp . '.csv',
            array('contenido_id', 'titulo', 'redes', 'fecha_hora'),
            array()
        );
    }

    if ('content' === $kind) {
        seo_social_network_scheduler_send_csv(
            'contenidos-publicables-' . $stamp . '.csv',
            array('contenido_id', 'titulo', 'tipo', 'url', 'imagen_destacada'),
            seo_social_network_scheduler_exportable_content_rows()
        );
    }

    if ('agenda' === $kind) {
        seo_social_network_scheduler_send_csv(
            'agenda-social-' . $stamp . '.csv',
            array('contenido_id', 'titulo', 'tipo', 'redes', 'fecha_hora', 'zona_horaria', 'estado'),
            seo_social_network_scheduler_exportable_agenda_rows()
        );
    }

    if ('history' === $kind) {
        seo_social_network_scheduler_send_csv(
            'historial-social-' . $stamp . '.csv',
            array('publicacion_id', 'contenido_id', 'titulo', 'tipo', 'red', 'estado', 'fecha_publicacion', 'visitas_web', 'reacciones', 'comentarios', 'compartidos', 'url_medida', 'url_red_social', 'error'),
            seo_social_network_scheduler_exportable_history_rows()
        );
    }

    wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => 'scheduler_export_invalid')));
    exit;
}
add_action('admin_post_seo_social_network_scheduler_export', 'seo_social_network_handle_scheduler_export');

/**
 * Detecta el delimitador mas probable de un CSV exportado por Excel/LibreOffice.
 *
 * @param string $line
 * @return string
 */
function seo_social_network_scheduler_detect_delimiter($line)
{
    $counts = array(
        ';'  => substr_count((string) $line, ';'),
        ','  => substr_count((string) $line, ','),
        "\t" => substr_count((string) $line, "\t"),
    );
    arsort($counts);
    $delimiter = key($counts);
    return $counts[$delimiter] > 0 ? $delimiter : ';';
}

/**
 * Normaliza un nombre de columna de CSV.
 *
 * @param string $value
 * @return string
 */
function seo_social_network_scheduler_normalize_header($value)
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);
    $value = remove_accents(strtolower(trim($value)));
    $value = preg_replace('/[^a-z0-9_]+/', '_', $value);
    return trim((string) $value, '_');
}

/**
 * Resuelve indices de columnas a partir de los alias admitidos.
 *
 * @param array $headers
 * @return array<string,int>
 */
function seo_social_network_scheduler_resolve_columns($headers)
{
    $normalized = array();
    foreach ((array) $headers as $index => $header) {
        $normalized[seo_social_network_scheduler_normalize_header($header)] = (int) $index;
    }

    $resolved = array();
    foreach (seo_social_network_scheduler_import_columns() as $canonical => $aliases) {
        foreach ($aliases as $alias) {
            $alias = seo_social_network_scheduler_normalize_header($alias);
            if (array_key_exists($alias, $normalized)) {
                $resolved[$canonical] = $normalized[$alias];
                break;
            }
        }
    }
    return $resolved;
}

/**
 * Convierte una fecha de CSV/Excel a timestamp en la zona horaria de WordPress.
 *
 * @param string $value
 * @return int|WP_Error
 */
function seo_social_network_scheduler_parse_import_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return new WP_Error('missing_datetime', 'Falta fecha_hora.');
    }

    $timezone = wp_timezone();
    $formats = array('Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d\\TH:i', 'd/m/Y H:i', 'd/m/Y H:i:s', 'd-m-Y H:i', 'd-m-Y H:i:s');
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date instanceof DateTimeImmutable && (false === $errors || (0 === (int) $errors['warning_count'] && 0 === (int) $errors['error_count']))) {
            return $date->getTimestamp();
        }
    }

    return new WP_Error('invalid_datetime', 'Fecha/hora no reconocida: ' . $value);
}

/**
 * Divide la columna redes. Se aceptan coma, barra vertical o espacios.
 *
 * @param string $value
 * @return string[]
 */
function seo_social_network_scheduler_parse_providers($value)
{
    $value = strtolower(trim((string) $value));
    if ($value === '') {
        return array();
    }
    $parts = preg_split('/[,|\s]+/', $value);
    return array_values(array_unique(array_filter(array_map('sanitize_key', (array) $parts))));
}

/**
 * Lee y valida un CSV sin crear todavia ninguna tarea.
 *
 * @param string $path
 * @return array|WP_Error
 */
function seo_social_network_scheduler_parse_import_file($path)
{
    if (!is_readable($path)) {
        return new WP_Error('file_unreadable', 'No se puede leer el CSV seleccionado.');
    }

    $handle = fopen($path, 'r');
    if (false === $handle) {
        return new WP_Error('file_unreadable', 'No se puede abrir el CSV seleccionado.');
    }

    $first_line = fgets($handle);
    if (false === $first_line) {
        fclose($handle);
        return new WP_Error('empty_file', 'El archivo CSV esta vacio.');
    }
    $delimiter = seo_social_network_scheduler_detect_delimiter($first_line);
    rewind($handle);

    $headers = fgetcsv($handle, 0, $delimiter, '"', '');
    if (!is_array($headers)) {
        fclose($handle);
        return new WP_Error('invalid_header', 'No se ha podido leer la cabecera del CSV.');
    }
    $columns = seo_social_network_scheduler_resolve_columns($headers);
    foreach (array('content_id', 'providers', 'scheduled_at') as $required) {
        if (!isset($columns[$required])) {
            fclose($handle);
            return new WP_Error('missing_column', 'Falta una columna obligatoria: ' . $required . '.');
        }
    }

    $available = function_exists('seo_social_network_get_providers') ? seo_social_network_get_providers() : array();
    $settings = function_exists('seo_social_network_get_settings') ? seo_social_network_get_settings() : array();
    $supported_types = function_exists('seo_social_network_supported_post_types') ? seo_social_network_supported_post_types() : array('post', 'page');
    $entries = array();
    $seen = array();
    $line_number = 1;
    $expanded_count = 0;

    while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        $line_number++;
        if (!is_array($row) || empty(array_filter($row, static function ($v) { return trim((string) $v) !== ''; }))) {
            continue;
        }
        if ($line_number > 2001) {
            fclose($handle);
            return new WP_Error('too_many_rows', 'El CSV supera el limite de 2000 filas. Divide la importacion en varios archivos.');
        }

        $content_id = absint(isset($row[$columns['content_id']]) ? $row[$columns['content_id']] : 0);
        $provider_values = isset($row[$columns['providers']]) ? $row[$columns['providers']] : '';
        $raw_date = isset($row[$columns['scheduled_at']]) ? $row[$columns['scheduled_at']] : '';
        $csv_title = isset($columns['title'], $row[$columns['title']]) ? sanitize_text_field($row[$columns['title']]) : '';
        $providers = seo_social_network_scheduler_parse_providers($provider_values);
        $post = $content_id ? get_post($content_id) : null;
        $date_result = seo_social_network_scheduler_parse_import_datetime($raw_date);
        $timestamp = is_wp_error($date_result) ? 0 : (int) $date_result;

        $base_errors = array();
        if (!$content_id) {
            $base_errors[] = 'contenido_id no valido';
        } elseif (!$post || 'publish' !== $post->post_status) {
            $base_errors[] = 'el contenido no existe o no esta publicado';
        } elseif (!in_array($post->post_type, $supported_types, true)) {
            $base_errors[] = 'tipo de contenido no publicable';
        }
        if (empty($providers)) {
            $base_errors[] = 'falta la red';
        }
        if (is_wp_error($date_result)) {
            $base_errors[] = $date_result->get_error_message();
        } elseif ($timestamp <= time() + 30) {
            $base_errors[] = 'la fecha debe estar al menos un minuto en el futuro';
        }

        if (empty($providers)) {
            $providers = array('');
        }

        foreach ($providers as $provider) {
            $expanded_count++;
            if ($expanded_count > 3000) {
                fclose($handle);
                return new WP_Error('too_many_jobs', 'La importacion supera 3000 programaciones al expandir las redes.');
            }

            $errors = $base_errors;
            if ($provider !== '') {
                if (!isset($available[$provider])) {
                    $errors[] = 'red no disponible: ' . $provider;
                } elseif (empty($settings['providers'][$provider]['enabled'])) {
                    $errors[] = 'red sin conectar: ' . $provider;
                }
            }

            $key = $content_id . '|' . $provider;
            if ($content_id && $provider !== '' && isset($seen[$key])) {
                $errors[] = 'duplicado dentro del archivo (mismo contenido y red)';
            } else {
                $seen[$key] = true;
            }

            $existing = ($content_id && $provider !== '') ? seo_social_network_get_scheduled_timestamp($content_id, $provider) : 0;
            $entries[] = array(
                'row'           => $line_number,
                'content_id'    => $content_id,
                'title'         => $post ? get_the_title($post) : $csv_title,
                'provider'      => $provider,
                'scheduled_at'  => $timestamp,
                'scheduled_txt' => $timestamp ? wp_date('Y-m-d H:i', $timestamp, wp_timezone()) : (string) $raw_date,
                'existing_at'   => $existing,
                'errors'        => array_values(array_unique($errors)),
            );
        }
    }
    fclose($handle);

    if (empty($entries)) {
        return new WP_Error('no_rows', 'El CSV no contiene filas de programacion.');
    }

    return array(
        'entries'   => $entries,
        'delimiter' => $delimiter,
    );
}

/**
 * Primera fase del import: valida y muestra una previsualizacion antes de tocar WP-Cron.
 */
function seo_social_network_handle_scheduler_import_preview()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para importar programaciones sociales.', 'seo-system'));
    }
    check_admin_referer('seo_social_network_scheduler_import');

    if (empty($_FILES['schedule_csv']) || !is_array($_FILES['schedule_csv'])) {
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => 'scheduler_import_missing_file')));
        exit;
    }

    $file = $_FILES['schedule_csv']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    if (!empty($file['error']) || empty($file['tmp_name'])) {
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => 'scheduler_import_upload_error')));
        exit;
    }
    if (!empty($file['size']) && (int) $file['size'] > 2 * MB_IN_BYTES) {
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => 'scheduler_import_too_large')));
        exit;
    }

    $name = isset($file['name']) ? sanitize_file_name((string) $file['name']) : '';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, array('csv', 'txt', 'tsv'), true)) {
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => 'scheduler_import_bad_type')));
        exit;
    }

    $parsed = seo_social_network_scheduler_parse_import_file((string) $file['tmp_name']);
    if (is_wp_error($parsed)) {
        set_transient('seo_social_scheduler_import_error_' . get_current_user_id(), $parsed->get_error_message(), 5 * MINUTE_IN_SECONDS);
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => 'scheduler_import_invalid')));
        exit;
    }

    $token = sanitize_key(wp_generate_password(20, false, false));
    $key = 'seo_social_scheduler_import_' . get_current_user_id() . '_' . $token;
    set_transient($key, $parsed, 20 * MINUTE_IN_SECONDS);

    wp_safe_redirect(
        seo_social_network_admin_url(
            'scheduler',
            array(
                'social_msg' => 'scheduler_import_preview',
                'import_key' => $token,
            )
        )
    );
    exit;
}
add_action('admin_post_seo_social_network_scheduler_import_preview', 'seo_social_network_handle_scheduler_import_preview');

/**
 * Segunda fase del import: crea/reprograma solo las filas validadas.
 */
function seo_social_network_handle_scheduler_import_confirm()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para importar programaciones sociales.', 'seo-system'));
    }
    check_admin_referer('seo_social_network_scheduler_import_confirm');

    $token = isset($_POST['import_key']) ? sanitize_key(wp_unslash($_POST['import_key'])) : '';
    $key = 'seo_social_scheduler_import_' . get_current_user_id() . '_' . $token;
    $parsed = $token !== '' ? get_transient($key) : false;
    if (!is_array($parsed) || empty($parsed['entries'])) {
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => 'scheduler_import_expired')));
        exit;
    }

    $created = 0;
    $reprogrammed = 0;
    $failed = 0;
    foreach ($parsed['entries'] as $entry) {
        if (!empty($entry['errors'])) {
            continue;
        }
        $had_existing = !empty($entry['existing_at']);
        $result = seo_social_network_set_scheduled_publication(
            absint($entry['content_id']),
            sanitize_key($entry['provider']),
            absint($entry['scheduled_at'])
        );
        if (is_wp_error($result)) {
            $failed++;
        } elseif ($had_existing) {
            $reprogrammed++;
        } else {
            $created++;
        }
    }
    delete_transient($key);

    wp_safe_redirect(
        seo_social_network_admin_url(
            'scheduler',
            array(
                'social_msg'  => $failed ? 'scheduler_import_partial' : 'scheduler_imported',
                'created'     => $created,
                'reprogrammed'=> $reprogrammed,
                'failed'      => $failed,
            )
        )
    );
    exit;
}
add_action('admin_post_seo_social_network_scheduler_import_confirm', 'seo_social_network_handle_scheduler_import_confirm');

/**
 * Acciones del Programador: programar, publicar ahora o cancelar.
 */
function seo_social_network_handle_scheduler_action()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para programar publicaciones sociales.', 'seo-system'));
    }

    check_admin_referer('seo_social_network_scheduler');

    if (!empty($_POST['cancel_schedule'])) {
        $parts = explode('|', sanitize_text_field(wp_unslash($_POST['cancel_schedule'])), 2);
        $content_id = isset($parts[0]) ? absint($parts[0]) : 0;
        $provider = isset($parts[1]) ? sanitize_key($parts[1]) : '';
        if ($content_id && $provider) {
            seo_social_network_clear_scheduled_publication($content_id, $provider);
        }
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => 'schedule_cancelled')));
        exit;
    }

    $content_id = !empty($_POST['schedule_content_id'])
        ? absint($_POST['schedule_content_id'])
        : (!empty($_POST['publish_content_id']) ? absint($_POST['publish_content_id']) : 0);
    $mode = !empty($_POST['publish_content_id']) ? 'now' : 'schedule';
    $providers_by_content = isset($_POST['providers']) && is_array($_POST['providers'])
        ? wp_unslash($_POST['providers'])
        : array();
    $providers = isset($providers_by_content[$content_id]) && is_array($providers_by_content[$content_id])
        ? array_values(array_unique(array_map('sanitize_key', $providers_by_content[$content_id])))
        : array();

    if (!$content_id || empty($providers)) {
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => 'scheduler_missing_selection')));
        exit;
    }

    $available = seo_social_network_get_providers();
    $settings = seo_social_network_get_settings();
    $providers = array_values(array_filter($providers, function ($provider) use ($available, $settings) {
        return isset($available[$provider]) && !empty($settings['providers'][$provider]['enabled']);
    }));

    if (empty($providers)) {
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => 'scheduler_no_connected_provider')));
        exit;
    }

    if ('now' === $mode) {
        $failed = 0;
        foreach ($providers as $provider) {
            seo_social_network_clear_scheduled_publication($content_id, $provider);
            if (is_wp_error(seo_social_network_publish_content($content_id, $provider))) {
                $failed++;
            }
        }
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => $failed ? 'publish_failed' : 'published')));
        exit;
    }

    $dates = isset($_POST['schedule_at']) && is_array($_POST['schedule_at']) ? wp_unslash($_POST['schedule_at']) : array();
    $raw_date = isset($dates[$content_id]) ? sanitize_text_field($dates[$content_id]) : '';
    $dt = $raw_date !== '' ? DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw_date, wp_timezone()) : false;
    $timestamp = $dt instanceof DateTimeImmutable ? $dt->getTimestamp() : 0;

    if (!$timestamp || $timestamp <= time() + 30) {
        wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => 'scheduler_invalid_date')));
        exit;
    }

    $failed = 0;
    foreach ($providers as $provider) {
        if (is_wp_error(seo_social_network_set_scheduled_publication($content_id, $provider, $timestamp))) {
            $failed++;
        }
    }

    wp_safe_redirect(seo_social_network_admin_url('scheduler', array('social_msg' => $failed ? 'schedule_failed' : 'scheduled')));
    exit;
}
add_action('admin_post_seo_social_network_scheduler_action', 'seo_social_network_handle_scheduler_action');

/**
 * Guarda automaticos y plantillas globales. Se conserva por compatibilidad con
 * formularios antiguos; la interfaz nueva usa acciones separadas.
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
    wp_safe_redirect(seo_social_network_admin_url('templates', array('social_msg' => 'publication_settings_saved')));
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

    wp_safe_redirect(seo_social_network_admin_url('scheduler', $args));
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
            'scheduler',
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
    $provider_key = isset($_POST['provider']) ? sanitize_key(wp_unslash($_POST['provider'])) : '';
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
        'publication_settings_saved' => array('success', 'Configuracion guardada.'),
        'templates_saved'            => array('success', 'Plantillas generales guardadas.'),
        'automation_saved'           => array('success', 'Automatizacion guardada.'),
        'scheduled'                  => array('success', 'Publicacion programada.'),
        'schedule_cancelled'         => array('success', 'Programacion cancelada.'),
        'schedule_failed'            => array('error', 'No se pudo crear alguna de las tareas programadas.'),
        'scheduler_missing_selection'=> array('warning', 'Selecciona al menos una red social para ese contenido.'),
        'scheduler_no_connected_provider' => array('warning', 'Las redes seleccionadas no estan conectadas.'),
        'scheduler_invalid_date'     => array('warning', 'Indica una fecha y hora futuras.'),
        'scheduler_export_invalid'   => array('warning', 'No se reconoce el tipo de exportacion solicitado.'),
        'scheduler_import_missing_file' => array('warning', 'Selecciona un archivo CSV para importar.'),
        'scheduler_import_upload_error' => array('error', 'No se pudo recibir el archivo CSV.'),
        'scheduler_import_too_large' => array('warning', 'El CSV supera el limite de 2 MB. Divide la programacion en varios archivos.'),
        'scheduler_import_bad_type'  => array('warning', 'El archivo debe ser CSV, TXT o TSV. Para Excel, guarda la hoja como CSV UTF-8.'),
        'scheduler_import_invalid'   => array('error', 'El CSV no se pudo validar.'),
        'scheduler_import_preview'   => array('success', 'CSV validado. Revisa la previsualizacion antes de importar.'),
        'scheduler_import_expired'   => array('warning', 'La previsualizacion de importacion ha caducado. Vuelve a cargar el CSV.'),
        'scheduler_imported'         => array('success', 'Programacion importada correctamente.'),
        'scheduler_import_partial'   => array('warning', 'La importacion termino con alguna tarea que no pudo programarse.'),
        'content_template_saved'     => array('success', 'Plantilla particular guardada. Si la dejas vacia, se usa la plantilla general.'),
        'published'                  => array('success', 'Contenido publicado correctamente en la red social.'),
        'publish_failed'             => array('error', 'No se pudo publicar el contenido.'),
        'sync_unavailable'           => array('warning', 'Este proveedor aun no ofrece sincronizacion de metricas.'),
        'reports_synced'             => array('success', 'Metricas sociales actualizadas.'),
        'linkedin_connected'         => array('success', 'LinkedIn autorizado. Revisa la pagina de empresa seleccionada y prueba la conexion.'),
        'linkedin_app_missing'       => array('warning', 'Guarda primero el Client ID y el Client Secret de LinkedIn.'),
        'linkedin_oauth_failed'      => array('error', 'No se pudo completar la autorizacion OAuth de LinkedIn.'),
        'pinterest_connected'        => array('success', 'Pinterest autorizado. Selecciona el tablero de destino y prueba la conexion.'),
        'pinterest_app_missing'      => array('warning', 'Guarda primero el App ID y el App Secret de Pinterest.'),
        'pinterest_oauth_failed'     => array('error', 'No se pudo completar la autorizacion OAuth de Pinterest.'),
        'x_connected'                => array('success', 'X autorizado. Revisa la cuenta detectada y prueba la conexion.'),
        'x_app_missing'              => array('warning', 'Guarda primero el Client ID y el Client Secret de X.'),
        'x_oauth_failed'             => array('error', 'No se pudo completar la autorizacion OAuth de X.'),
    );

    if (!isset($messages[$message])) {
        return;
    }

    $text = $messages[$message][1];
    if ('scheduler_import_invalid' === $message) {
        $detail = get_transient('seo_social_scheduler_import_error_' . get_current_user_id());
        if ($detail) {
            delete_transient('seo_social_scheduler_import_error_' . get_current_user_id());
            $text .= ' ' . $detail;
        }
    }

    if (in_array($message, array('scheduler_imported', 'scheduler_import_partial'), true)) {
        $created = isset($_GET['created']) ? absint($_GET['created']) : 0;
        $reprogrammed = isset($_GET['reprogrammed']) ? absint($_GET['reprogrammed']) : 0;
        $failed = isset($_GET['failed']) ? absint($_GET['failed']) : 0;
        $text .= ' Nuevas: ' . $created . '. Reprogramadas: ' . $reprogrammed . '. Fallidas: ' . $failed . '.';
    }

    if ('publish_failed' === $message) {
        $detail = get_transient('seo_social_network_error_' . get_current_user_id());
        if ($detail) {
            delete_transient('seo_social_network_error_' . get_current_user_id());
            $text .= ' ' . $detail;
        }
    }

    if ('linkedin_oauth_failed' === $message) {
        $detail = get_transient('seo_social_linkedin_oauth_error_' . get_current_user_id());
        if ($detail) {
            delete_transient('seo_social_linkedin_oauth_error_' . get_current_user_id());
            $text .= ' ' . $detail;
        }
    }

    if ('pinterest_oauth_failed' === $message) {
        $detail = get_transient('seo_social_pinterest_oauth_error_' . get_current_user_id());
        if ($detail) {
            delete_transient('seo_social_pinterest_oauth_error_' . get_current_user_id());
            $text .= ' ' . $detail;
        }
    }

    if ('x_oauth_failed' === $message) {
        $detail = get_transient('seo_social_x_oauth_error_' . get_current_user_id());
        if ($detail) {
            delete_transient('seo_social_x_oauth_error_' . get_current_user_id());
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

    $allowed = array('templates', 'automation', 'scheduler', 'connections', 'reports', 'publications');
    $subtab = isset($_GET['social_subtab']) ? sanitize_key(wp_unslash($_GET['social_subtab'])) : 'templates';
    if (!in_array($subtab, $allowed, true)) {
        $subtab = 'templates';
    }
    if ('publications' === $subtab) {
        $subtab = 'templates';
    }

    seo_social_network_render_styles();
    seo_social_network_render_notice();

    echo '<div class="seo-social-header">';
    echo '<div><h2>Redes sociales</h2><p>Define una vez el formato, automatiza si quieres y usa el Programador solo como agenda.</p></div>';
    echo '<div class="seo-social-provider-strip">';
    $settings = seo_social_network_get_settings();
    foreach (seo_social_network_get_providers() as $provider_key => $provider) {
        $connected = !empty($settings['providers'][$provider_key]['enabled']);
        echo '<span class="seo-social-provider-pill ' . ($connected ? 'is-connected' : '') . '">';
        echo esc_html(isset($provider['label']) ? $provider['label'] : ucfirst($provider_key));
        echo ' · ' . esc_html($connected ? 'conectado' : 'sin conectar');
        echo '</span>';
    }
    echo '</div></div>';

    $tabs = array(
        'templates'   => 'Plantillas',
        'automation'  => 'Automatización',
        'scheduler'   => 'Programador',
        'connections' => 'Conexiones',
        'reports'     => 'Informes',
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
    } elseif ('automation' === $subtab) {
        seo_social_network_render_automation();
    } elseif ('scheduler' === $subtab) {
        seo_social_network_render_scheduler();
    } else {
        seo_social_network_render_templates();
    }
}

/**
 * CSS aislado del modulo.
 */
function seo_social_network_render_styles()
{
    echo '<style>
        .seo-social-header{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin:4px 0 18px;padding:20px;background:#fff;border:1px solid #dcdcde;border-radius:8px}.seo-social-header h2{margin:0 0 5px;font-size:22px}.seo-social-header p{margin:0;color:#646970;max-width:720px}.seo-social-provider-strip{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.seo-social-provider-pill{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;background:#f0f0f1;color:#50575e;font-size:12px;font-weight:600}.seo-social-provider-pill.is-connected{background:#edfaef;color:#176b2c}.seo-social-provider-pill.is-planned{background:#f6f7f7;color:#787c82}
        .seo-social-subnav{display:flex;gap:8px;margin:0 0 18px;border-bottom:1px solid #c3c4c7;overflow:auto}.seo-social-subnav-link{display:inline-block;margin-bottom:-1px;padding:10px 14px;text-decoration:none;border:1px solid transparent;border-radius:6px 6px 0 0;font-weight:600;white-space:nowrap}.seo-social-subnav-link.is-active{background:#fff;border-color:#c3c4c7 #c3c4c7 #fff;color:#1d2327}
        .seo-social-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:0 0 18px}.seo-social-card h2,.seo-social-card h3{margin-top:0}.seo-social-intro{display:flex;justify-content:space-between;gap:18px;align-items:flex-start}.seo-social-intro p{max-width:760px;margin-top:4px;color:#646970}.seo-social-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}.seo-social-field label{display:block;font-weight:600;margin:0 0 6px}.seo-social-field input[type=text],.seo-social-field input[type=password],.seo-social-field select,.seo-social-field textarea{width:100%}.seo-social-field textarea{min-height:120px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}.seo-social-help{color:#646970;font-size:12px;line-height:1.45}.seo-social-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:16px}.seo-social-state{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:11px;font-weight:700;background:#f0f0f1;color:#50575e}.seo-social-state.is-published,.seo-social-state.is-ok{background:#edfaef;color:#176b2c}.seo-social-state.is-failed{background:#fcf0f1;color:#b32d2e}.seo-social-state.is-pending,.seo-social-state.is-scheduled{background:#fff8e5;color:#8a5500}
        .seo-social-vars{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 0}.seo-social-vars code{font-size:11px;padding:3px 6px;background:#f6f7f7}.seo-social-table-wrap{overflow:auto}.seo-social-table{width:100%;border-collapse:collapse}.seo-social-table th,.seo-social-table td{padding:12px 10px;border-bottom:1px solid #e2e4e7;text-align:left;vertical-align:top}.seo-social-table th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#50575e}.seo-social-content-title{min-width:250px}.seo-social-preview{white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;padding:10px;max-height:170px;overflow:auto;font-size:12px;line-height:1.45}.seo-social-row-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.seo-social-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}.seo-social-metric{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}.seo-social-metric strong{display:block;font-size:24px;line-height:1.1}.seo-social-metric span{display:block;color:#646970;margin-top:5px;font-size:12px}.seo-social-provider-card{position:relative}.seo-social-provider-card .dashicons{font-size:32px;width:32px;height:32px;margin-bottom:8px}.seo-social-code-note{padding:10px 12px;background:#f6f7f7;border-left:4px solid #2271b1}.seo-social-filterbar{display:flex;gap:8px;flex-wrap:wrap;align-items:end}.seo-social-filterbar .seo-social-field{min-width:180px;flex:1}.seo-social-filterbar .seo-social-field.is-search{min-width:280px;flex:2}
        .seo-social-template-card{border:1px solid #e2e4e7;border-radius:8px;padding:16px;background:#fcfcfc}.seo-social-template-card__head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:14px}.seo-social-template-card__head h3{margin:0}.seo-social-automation-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.seo-social-option{display:block;padding:14px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.seo-social-option strong{display:block;margin-bottom:4px}.seo-social-option small{display:block;color:#646970;margin-left:24px}.seo-social-network-checks{display:flex;flex-wrap:wrap;gap:8px}.seo-social-network-check{display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border:1px solid #dcdcde;border-radius:7px;background:#fff}.seo-social-scheduler-networks{display:flex;flex-wrap:wrap;gap:8px;min-width:220px}.seo-social-scheduler-networks label{white-space:nowrap}.seo-social-date{min-width:190px}.seo-social-date input{width:100%}.seo-social-scheduled-list{margin-top:7px}.seo-social-scheduled-item{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:5px}.seo-social-scheduled-item button{padding:0;border:0;background:none;color:#b32d2e;cursor:pointer;text-decoration:underline;font-size:11px}.seo-social-thumb{width:64px;height:48px;object-fit:cover;border-radius:5px;vertical-align:middle;margin-top:8px}.seo-social-template-preview-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px;margin-top:8px}.seo-social-template-preview-grid strong{display:block;margin-bottom:4px}.seo-social-scheduler-io{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin:18px 0}.seo-social-scheduler-io__block{padding:15px;border:1px solid #dcdcde;border-radius:8px;background:#fcfcfc}.seo-social-scheduler-io__block h3{margin:0 0 6px}.seo-social-import-form,.seo-social-export-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px}.seo-social-import-form input[type=file]{max-width:100%}.seo-social-import-preview{margin:16px 0;padding:16px;border:1px solid #dcdcde;border-radius:8px;background:#fff}.seo-social-import-preview .seo-social-table{margin-top:10px}
        @media(max-width:900px){.seo-social-header,.seo-social-intro{flex-direction:column}.seo-social-provider-strip{justify-content:flex-start}.seo-social-table th,.seo-social-table td{padding:10px 7px}.seo-social-scheduler-networks{min-width:180px}.seo-social-scheduler-io{grid-template-columns:1fr}}
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
        $icon = isset($provider['icon']) ? sanitize_html_class((string) $provider['icon']) : 'dashicons-share';
        echo '<span class="dashicons ' . esc_attr($icon) . '"></span>';
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
    echo '</div>';
}

/**
 * Consulta contenidos publicados para el Programador.
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
 * Plantillas globales: solo define como se redacta cada red.
 */
function seo_social_network_render_templates()
{
    $settings = seo_social_network_get_settings();
    $providers = seo_social_network_get_providers();

    echo '<section class="seo-social-card">';
    echo '<div class="seo-social-intro"><div><h2>Plantillas de publicación</h2><p>Define una sola vez cómo debe redactarse una entrada, una página o un producto incluido en una campaña de Marketing para cada red. El Programador utilizará estas plantillas automáticamente; aquí no se decide cuándo publicar.</p></div><span class="seo-social-state is-ok">Formato general</span></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_social_network_save_templates">';
    wp_nonce_field('seo_social_network_save_templates');

    echo '<div class="seo-social-grid" style="margin-top:18px">';
    foreach ($providers as $provider_key => $provider) {
        $label = isset($provider['label']) ? $provider['label'] : ucfirst($provider_key);
        $connected = !empty($settings['providers'][$provider_key]['enabled']);
        echo '<div class="seo-social-template-card">';
        echo '<div class="seo-social-template-card__head"><h3>' . esc_html($label) . '</h3><span class="seo-social-state ' . ($connected ? 'is-ok' : '') . '">' . esc_html($connected ? 'Conectado' : 'Sin conectar') . '</span></div>';
        foreach (array('post' => 'Entradas', 'page' => 'Páginas / Landings') as $post_type => $type_label) {
            $value = isset($settings['templates'][$provider_key][$post_type]) ? (string) $settings['templates'][$provider_key][$post_type] : '';
            echo '<div class="seo-social-field" style="margin-bottom:14px"><label>' . esc_html($type_label) . '</label><textarea name="templates[' . esc_attr($provider_key) . '][' . esc_attr($post_type) . ']">' . esc_textarea($value) . '</textarea></div>';
        }
        $campaign_value = isset($settings['templates'][$provider_key]['campaign']) ? (string) $settings['templates'][$provider_key]['campaign'] : '';
        echo '<div class="seo-social-field" style="margin-bottom:14px"><label>Campañas / productos en oferta</label><textarea name="templates[' . esc_attr($provider_key) . '][campaign]">' . esc_textarea($campaign_value) . '</textarea><p class="seo-social-help">Se utiliza solo para productos leídos desde Marketing → Campañas.</p></div>';
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="seo-social-vars"><strong>Variables disponibles:</strong> ';
    foreach (array('{titulo}', '{extracto}', '{fecha}', '{url}', '{sitio}', '{autor}', '{tipo}', '{categorias}', '{imagen}', '{campana}', '{producto}', '{precio_regular}', '{precio_oferta}', '{descuento}', '{descuento_pct}', '{fecha_inicio}', '{fecha_fin}') as $var) {
        echo '<code>' . esc_html($var) . '</code>';
    }
    echo '</div>';
    echo '<div class="seo-social-actions"><button type="submit" class="button button-primary">Guardar plantillas</button></div>';
    echo '</form></section>';
}

/**
 * Automatizacion: solo decide qué eventos disparan publicaciones y a qué redes.
 */
function seo_social_network_render_automation()
{
    $settings = seo_social_network_get_settings();
    $providers = seo_social_network_get_providers();

    echo '<section class="seo-social-card">';
    echo '<div class="seo-social-intro"><div><h2>Publicación automática</h2><p>Activa esta opción únicamente si quieres que una pieza se publique en redes la primera vez que pasa a estado Publicado en WordPress. No cambia el formato: utiliza las plantillas generales.</p></div><span class="seo-social-state">Disparador</span></div>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_social_network_save_automation">';
    wp_nonce_field('seo_social_network_save_automation');

    echo '<h3 style="margin-top:20px">Qué contenido activa la automatización</h3>';
    echo '<div class="seo-social-automation-options">';
    echo '<label class="seo-social-option"><input type="checkbox" name="auto_publish[post]" value="1" ' . checked(!empty($settings['auto_publish']['post']), true, false) . '> <strong style="display:inline">Entradas</strong><small>Solo en la primera transición a Publicado.</small></label>';
    echo '<label class="seo-social-option"><input type="checkbox" name="auto_publish[page]" value="1" ' . checked(!empty($settings['auto_publish']['page']), true, false) . '> <strong style="display:inline">Páginas / Landings</strong><small>Solo en la primera transición a Publicado.</small></label>';
    echo '</div>';

    echo '<h3 style="margin-top:22px">Redes de destino</h3>';
    echo '<div class="seo-social-network-checks">';
    foreach ($providers as $provider_key => $provider) {
        $label = isset($provider['label']) ? $provider['label'] : ucfirst($provider_key);
        $connected = !empty($settings['providers'][$provider_key]['enabled']);
        $checked = !empty($settings['auto_publish_providers'][$provider_key]);
        echo '<label class="seo-social-network-check"><input type="checkbox" name="auto_publish_providers[' . esc_attr($provider_key) . ']" value="1" ' . checked($checked, true, false) . '> ' . esc_html($label) . ' <span class="seo-social-state ' . ($connected ? 'is-ok' : '') . '">' . esc_html($connected ? 'conectado' : 'sin conectar') . '</span></label>';
    }
    echo '</div>';
    echo '<p class="seo-social-help">Una red no conectada no publicará aunque esté seleccionada. Las ediciones posteriores del contenido no generan duplicados automáticos.</p>';
    echo '<div class="seo-social-actions"><button type="submit" class="button button-primary">Guardar automatización</button></div>';
    echo '</form></section>';
}

/**
 * Programador: agenda simple, sin repetir los editores de plantillas.
 */
function seo_social_network_render_scheduler()
{
    $settings = seo_social_network_get_settings();
    $providers = seo_social_network_get_providers();

    echo '<section class="seo-social-card">';
    echo '<div class="seo-social-intro"><div><h2>Programador</h2><p>Selecciona una pieza, marca las redes y elige fecha y hora. El texto se genera automáticamente con la plantilla correspondiente. No tienes que volver a redactar Facebook, Instagram, LinkedIn, Pinterest o X aquí.</p></div><span class="seo-social-state is-scheduled">Agenda</span></div>';

    echo '<div class="seo-social-scheduler-io">';
    echo '<div class="seo-social-scheduler-io__block"><h3>Programacion masiva con Excel / CSV</h3><p class="seo-social-help">Descarga la lista de contenidos, prepara la hoja en Excel y guardala como <strong>CSV UTF-8</strong>. Columnas obligatorias: <code>contenido_id</code>, <code>redes</code> y <code>fecha_hora</code>. En <code>redes</code> puedes usar una o varias, por ejemplo <code>facebook,instagram,x</code>. Las fechas aceptan <code>2026-09-25 10:30</code> o <code>25/09/2026 10:30</code>.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" class="seo-social-import-form">';
    echo '<input type="hidden" name="action" value="seo_social_network_scheduler_import_preview">';
    wp_nonce_field('seo_social_network_scheduler_import');
    echo '<input type="file" name="schedule_csv" accept=".csv,.txt,.tsv,text/csv,text/plain" required>';
    echo '<button type="submit" class="button button-primary">Validar CSV antes de importar</button></form></div>';

    echo '<div class="seo-social-scheduler-io__block"><h3>Exportar</h3><p class="seo-social-help">Todos los archivos usan UTF-8 y punto y coma para abrirlos directamente con Excel en configuraciones regionales españolas.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="seo-social-export-actions">';
    echo '<input type="hidden" name="action" value="seo_social_network_scheduler_export">';
    wp_nonce_field('seo_social_network_scheduler_export');
    echo '<button class="button" type="submit" name="export_kind" value="template">Descargar plantilla CSV</button>';
    echo '<button class="button" type="submit" name="export_kind" value="content">Exportar contenidos</button>';
    echo '<button class="button" type="submit" name="export_kind" value="agenda">Exportar agenda</button>';
    echo '<button class="button" type="submit" name="export_kind" value="history">Exportar historial</button>';
    echo '</form></div></div>';

    $import_token = isset($_GET['import_key']) ? sanitize_key(wp_unslash($_GET['import_key'])) : '';
    $import_preview = false;
    if ($import_token !== '') {
        $import_preview = get_transient('seo_social_scheduler_import_' . get_current_user_id() . '_' . $import_token);
    }
    if (is_array($import_preview) && !empty($import_preview['entries'])) {
        $valid_count = 0;
        $error_count = 0;
        $reprogram_count = 0;
        foreach ($import_preview['entries'] as $entry) {
            if (!empty($entry['errors'])) {
                $error_count++;
            } else {
                $valid_count++;
                if (!empty($entry['existing_at'])) {
                    $reprogram_count++;
                }
            }
        }

        echo '<div class="seo-social-import-preview"><div class="seo-social-intro"><div><h3>Previsualizacion de importacion</h3><p>Valida antes de crear tareas. Las filas con error no se importaran. Si ya existe una programacion para el mismo contenido y red, se reprogramara a la nueva fecha.</p></div><span class="seo-social-state ' . ($error_count ? 'is-pending' : 'is-ok') . '">' . esc_html((string) $valid_count) . ' validas · ' . esc_html((string) $error_count) . ' con error</span></div>';
        echo '<div class="seo-social-table-wrap"><table class="seo-social-table"><thead><tr><th>Fila</th><th>Contenido</th><th>Red</th><th>Fecha y hora</th><th>Resultado</th></tr></thead><tbody>';
        foreach ($import_preview['entries'] as $entry) {
            $errors = isset($entry['errors']) && is_array($entry['errors']) ? $entry['errors'] : array();
            $state_class = !empty($errors) ? 'is-failed' : (!empty($entry['existing_at']) ? 'is-pending' : 'is-ok');
            $state_text = !empty($errors) ? 'Error' : (!empty($entry['existing_at']) ? 'Reprogramara' : 'Nueva');
            echo '<tr>';
            echo '<td>' . esc_html((string) absint($entry['row'])) . '</td>';
            echo '<td><strong>' . esc_html((string) $entry['title']) . '</strong><br><small>#' . esc_html((string) absint($entry['content_id'])) . '</small></td>';
            echo '<td>' . esc_html((string) $entry['provider']) . '</td>';
            echo '<td>' . esc_html((string) $entry['scheduled_txt']) . '</td>';
            echo '<td><span class="seo-social-state ' . esc_attr($state_class) . '">' . esc_html($state_text) . '</span>';
            if (!empty($errors)) {
                echo '<br><small style="color:#b32d2e">' . esc_html(implode('; ', $errors)) . '</small>';
            } elseif (!empty($entry['existing_at'])) {
                echo '<br><small>Actual: ' . esc_html(wp_date('Y-m-d H:i', absint($entry['existing_at']), wp_timezone())) . '</small>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div class="seo-social-actions">';
        if ($valid_count > 0) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="seo_social_network_scheduler_import_confirm"><input type="hidden" name="import_key" value="' . esc_attr($import_token) . '">';
            wp_nonce_field('seo_social_network_scheduler_import_confirm');
            echo '<button type="submit" class="button button-primary">Importar ' . esc_html((string) $valid_count) . ' programaciones</button></form>';
        }
        echo '<a class="button" href="' . esc_url(seo_social_network_admin_url('scheduler')) . '">Descartar previsualizacion</a>';
        if ($reprogram_count > 0) {
            echo '<span class="seo-social-help">' . esc_html((string) $reprogram_count) . ' sustituiran una programacion existente.</span>';
        }
        echo '</div></div>';
    }

    if (function_exists('seo_social_campaign_render_scheduler_panel')) {
        seo_social_campaign_render_scheduler_panel();
    }

    echo '<form method="get" class="seo-social-filterbar" style="margin-top:18px">';
    echo '<input type="hidden" name="page" value="seo-menu-marketing"><input type="hidden" name="tab" value="social"><input type="hidden" name="social_subtab" value="scheduler">';
    echo '<div class="seo-social-field"><label>Tipo</label><select name="social_type"><option value="">Entradas + Páginas/Landings</option>';
    $current_type = isset($_GET['social_type']) ? sanitize_key(wp_unslash($_GET['social_type'])) : '';
    echo '<option value="post" ' . selected($current_type, 'post', false) . '>Entradas</option><option value="page" ' . selected($current_type, 'page', false) . '>Páginas / Landings</option></select></div>';
    $search = isset($_GET['social_search']) ? sanitize_text_field(wp_unslash($_GET['social_search'])) : '';
    echo '<div class="seo-social-field is-search"><label>Buscar contenido</label><input type="text" name="social_search" value="' . esc_attr($search) . '" placeholder="Título del contenido..."></div>';
    echo '<button class="button" type="submit">Filtrar</button>';
    echo '</form>';

    $query = seo_social_network_publications_query();
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_social_network_scheduler_action">';
    wp_nonce_field('seo_social_network_scheduler');
    echo '<div class="seo-social-table-wrap"><table class="seo-social-table"><thead><tr><th>Contenido</th><th>Redes</th><th>Fecha y hora</th><th>Acción</th></tr></thead><tbody>';

    if (!$query->have_posts()) {
        echo '<tr><td colspan="4">No se ha encontrado contenido publicado.</td></tr>';
    }

    while ($query->have_posts()) {
        $query->the_post();
        $post = get_post();
        if (!$post) {
            continue;
        }

        echo '<tr id="seo-social-content-' . esc_attr((string) $post->ID) . '">';
        echo '<td class="seo-social-content-title"><strong><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html(get_the_title($post)) . '</a></strong><br><span class="seo-social-state">' . esc_html(seo_social_network_content_type_label($post)) . '</span><p class="seo-social-help">Publicado: ' . esc_html(get_the_date('', $post)) . ' · #' . esc_html((string) $post->ID) . '</p>';
        if (has_post_thumbnail($post)) {
            echo get_the_post_thumbnail($post->ID, array(64, 48), array('class' => 'seo-social-thumb')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        echo '</td>';

        echo '<td><div class="seo-social-scheduler-networks">';
        $has_connected = false;
        foreach ($providers as $provider_key => $provider) {
            $label = isset($provider['label']) ? $provider['label'] : ucfirst($provider_key);
            $connected = !empty($settings['providers'][$provider_key]['enabled']);
            if ($connected) {
                $has_connected = true;
            }
            echo '<label><input type="checkbox" name="providers[' . esc_attr((string) $post->ID) . '][]" value="' . esc_attr($provider_key) . '" ' . disabled(!$connected, true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</div>';
        if (!$has_connected) {
            echo '<p class="seo-social-help"><a href="' . esc_url(seo_social_network_admin_url('connections')) . '">Conecta una red para poder programar</a>.</p>';
        }

        echo '<div class="seo-social-scheduled-list">';
        foreach ($providers as $provider_key => $provider) {
            $timestamp = seo_social_network_get_scheduled_timestamp($post->ID, $provider_key);
            if (!$timestamp) {
                continue;
            }
            $label = isset($provider['label']) ? $provider['label'] : ucfirst($provider_key);
            echo '<div class="seo-social-scheduled-item"><span class="seo-social-state is-scheduled">' . esc_html($label) . ': ' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp, wp_timezone())) . '</span><button type="submit" name="cancel_schedule" value="' . esc_attr($post->ID . '|' . $provider_key) . '">Cancelar</button></div>';
        }
        echo '</div></td>';

        echo '<td class="seo-social-date"><input type="datetime-local" name="schedule_at[' . esc_attr((string) $post->ID) . ']" aria-label="Fecha y hora para ' . esc_attr(get_the_title($post)) . '"><p class="seo-social-help">Zona horaria: ' . esc_html(wp_timezone_string()) . '</p></td>';

        echo '<td><div class="seo-social-row-actions" style="margin-top:0"><button type="submit" name="schedule_content_id" value="' . esc_attr((string) $post->ID) . '" class="button button-primary" ' . disabled(!$has_connected, true, false) . '>Programar</button><button type="submit" name="publish_content_id" value="' . esc_attr((string) $post->ID) . '" class="button" ' . disabled(!$has_connected, true, false) . '>Publicar ahora</button></div>';
        echo '<details style="margin-top:9px"><summary style="cursor:pointer">Vista previa</summary><div class="seo-social-template-preview-grid">';
        foreach ($providers as $provider_key => $provider) {
            $label = isset($provider['label']) ? $provider['label'] : ucfirst($provider_key);
            $template = seo_social_network_get_template_for_content($post, $provider_key);
            $preview_url = add_query_arg(array('utm_source' => $provider_key, 'utm_medium' => 'social'), get_permalink($post));
            $preview = seo_social_network_render_template($template, $post, $preview_url);
            echo '<div><strong>' . esc_html($label) . '</strong><div class="seo-social-preview">' . esc_html($preview) . '</div></div>';
        }
        echo '</div></details></td>';
        echo '</tr>';
    }
    wp_reset_postdata();

    echo '</tbody></table></div></form>';
    echo '<p class="seo-social-code-note"><strong>Cómo funciona:</strong> el Programador solo guarda la fecha y las redes. En el momento de publicar, toma la plantilla general de cada red. WordPress ejecuta las tareas con WP-Cron, por lo que la hora puede depender de que el sitio reciba una petición alrededor de ese momento.</p>';
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
    echo '<div class="seo-social-actions" style="margin-top:0">';
    foreach (seo_social_network_get_providers() as $provider_key => $provider) {
        if (empty($provider['sync_callback']) || !is_callable($provider['sync_callback'])) {
            continue;
        }
        $label = isset($provider['label']) ? (string) $provider['label'] : ucfirst($provider_key);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_social_network_sync_reports"><input type="hidden" name="provider" value="' . esc_attr($provider_key) . '">';
        wp_nonce_field('seo_social_network_sync_reports');
        echo '<button type="submit" class="button button-primary">Actualizar metricas de ' . esc_html($label) . '</button></form>';
    }
    echo '</div></div>';
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
