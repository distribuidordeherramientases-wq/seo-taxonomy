<?php
/**
 * SEO System - proveedor Instagram Professional para Social Network.
 *
 * Reutiliza por defecto la conexion de Facebook Pages ya existente. La cuenta
 * de Instagram debe ser Professional (Business o Creator) y estar vinculada a
 * la pagina de Facebook cuando se usa este modo.
 */

defined('ABSPATH') || exit;

/**
 * @param array $providers
 * @return array
 */
function seo_social_instagram_register_provider($providers)
{
    $providers = is_array($providers) ? $providers : array();
    $providers['instagram'] = array(
        'label'                        => 'Instagram',
        'icon'                         => 'dashicons-format-image',
        'sanitize_connection_callback' => 'seo_social_instagram_sanitize_connection',
        'test_callback'                => 'seo_social_instagram_test_connection',
        'publish_callback'             => 'seo_social_instagram_publish',
        'sync_callback'                => 'seo_social_instagram_sync_publication',
        'render_connection_callback'   => 'seo_social_instagram_render_connection',
    );
    return $providers;
}
add_filter('seo_social_network_providers', 'seo_social_instagram_register_provider');

/**
 * @param array $posted
 * @param array $current
 * @return array|WP_Error
 */
function seo_social_instagram_sanitize_connection($posted, $current)
{
    $posted = is_array($posted) ? $posted : array();
    $current = is_array($current) ? $current : array();

    $use_facebook = !empty($posted['use_facebook_connection']) ? 1 : 0;
    $ig_user_id = isset($posted['ig_user_id'])
        ? preg_replace('/[^0-9]/', '', (string) $posted['ig_user_id'])
        : (isset($current['ig_user_id']) ? preg_replace('/[^0-9]/', '', (string) $current['ig_user_id']) : '');

    $api_version = isset($posted['api_version']) ? sanitize_text_field((string) $posted['api_version']) : 'v25.0';
    if (!preg_match('/^v\d+\.\d+$/', $api_version)) {
        $api_version = 'v25.0';
    }

    $access_token_enc = isset($current['access_token_enc']) ? (string) $current['access_token_enc'] : '';
    $new_token = isset($posted['access_token']) ? trim((string) $posted['access_token']) : '';
    if ($new_token !== '') {
        $access_token_enc = seo_social_network_encrypt_secret($new_token);
        if (is_wp_error($access_token_enc)) {
            return $access_token_enc;
        }
    }

    $has_token = $access_token_enc !== '';
    if ($use_facebook) {
        $settings = seo_social_network_get_settings();
        $facebook = isset($settings['providers']['facebook']) && is_array($settings['providers']['facebook'])
            ? $settings['providers']['facebook']
            : array();
        $has_token = !empty($facebook['access_token_enc']) && !empty($facebook['page_id']);
    }

    return array(
        'enabled'                 => ($ig_user_id !== '' && $has_token) ? 1 : 0,
        'use_facebook_connection' => $use_facebook,
        'ig_user_id'              => $ig_user_id,
        'account_username'        => isset($current['account_username']) ? sanitize_user((string) $current['account_username'], true) : '',
        'page_name'               => isset($current['page_name']) ? sanitize_text_field((string) $current['page_name']) : '',
        'page_link'               => isset($current['page_link']) ? esc_url_raw((string) $current['page_link']) : '',
        'access_token_enc'        => $access_token_enc,
        'api_version'             => $api_version,
        'publish_mode'            => 'image',
        'last_test_at'            => isset($current['last_test_at']) ? sanitize_text_field((string) $current['last_test_at']) : '',
        'last_test_ok'            => !empty($current['last_test_ok']) ? 1 : 0,
        'last_test_error'         => isset($current['last_test_error']) ? sanitize_text_field((string) $current['last_test_error']) : '',
    );
}

/**
 * @param string $version
 * @param string $path
 * @param array  $query
 * @return string
 */
function seo_social_instagram_graph_url($version, $path, $query = array())
{
    $version = preg_match('/^v\d+\.\d+$/', (string) $version) ? (string) $version : 'v25.0';
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . ltrim((string) $path, '/');
    return !empty($query) ? add_query_arg($query, $url) : $url;
}

/**
 * @param mixed $response
 * @return array|WP_Error
 */
function seo_social_instagram_decode_response($response)
{
    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    $data = is_array($data) ? $data : array();

    if ($code < 200 || $code >= 300) {
        $message = isset($data['error']['message']) ? (string) $data['error']['message'] : 'Instagram devolvio HTTP ' . $code . '.';
        $error_code = isset($data['error']['code']) ? ' (' . (int) $data['error']['code'] . ')' : '';
        return new WP_Error('instagram_api_error', sanitize_text_field($message . $error_code));
    }

    return $data;
}

/**
 * Resuelve token, version e Instagram User ID. Si se reutiliza Facebook y aun
 * no conocemos el ID de Instagram, lo descubre desde la pagina vinculada.
 *
 * @param array $config
 * @param bool  $persist
 * @return array|WP_Error
 */
function seo_social_instagram_resolve_credentials($config, $persist = true)
{
    $config = is_array($config) ? $config : array();
    $use_facebook = !empty($config['use_facebook_connection']);
    $version = isset($config['api_version']) && preg_match('/^v\d+\.\d+$/', $config['api_version'])
        ? $config['api_version']
        : 'v25.0';
    $token = '';
    $page_id = '';

    if ($use_facebook) {
        $settings = seo_social_network_get_settings();
        $facebook = isset($settings['providers']['facebook']) && is_array($settings['providers']['facebook'])
            ? $settings['providers']['facebook']
            : array();

        $page_id = isset($facebook['page_id']) ? preg_replace('/[^0-9]/', '', (string) $facebook['page_id']) : '';
        $token = seo_social_network_decrypt_secret(isset($facebook['access_token_enc']) ? $facebook['access_token_enc'] : '');
        if (!empty($facebook['api_version']) && preg_match('/^v\d+\.\d+$/', $facebook['api_version'])) {
            $version = (string) $facebook['api_version'];
        }

        if ($page_id === '' || $token === '') {
            return new WP_Error('instagram_facebook_missing', 'Conecta primero Facebook con una Page Access Token valida.');
        }
    } else {
        $token = seo_social_network_decrypt_secret(isset($config['access_token_enc']) ? $config['access_token_enc'] : '');
        if ($token === '') {
            return new WP_Error('instagram_missing_token', 'Falta el Page Access Token para Instagram.');
        }
    }

    $ig_user_id = isset($config['ig_user_id']) ? preg_replace('/[^0-9]/', '', (string) $config['ig_user_id']) : '';
    if ($ig_user_id === '' && $use_facebook) {
        $url = seo_social_instagram_graph_url(
            $version,
            $page_id,
            array(
                'fields'       => 'instagram_business_account',
                'access_token' => $token,
            )
        );
        $page = seo_social_instagram_decode_response(
            wp_remote_get($url, array('timeout' => 20, 'redirection' => 3))
        );
        if (is_wp_error($page)) {
            return $page;
        }

        $ig_user_id = !empty($page['instagram_business_account']['id'])
            ? preg_replace('/[^0-9]/', '', (string) $page['instagram_business_account']['id'])
            : '';

        if ($ig_user_id === '') {
            return new WP_Error('instagram_account_missing', 'La pagina de Facebook no tiene una cuenta profesional de Instagram vinculada o el token no puede verla.');
        }
    }

    if ($ig_user_id === '') {
        return new WP_Error('instagram_user_missing', 'Falta el Instagram User ID.');
    }

    $profile_url = seo_social_instagram_graph_url(
        $version,
        $ig_user_id,
        array(
            'fields'       => 'id,username,name',
            'access_token' => $token,
        )
    );
    $profile = seo_social_instagram_decode_response(
        wp_remote_get($profile_url, array('timeout' => 20, 'redirection' => 3))
    );
    if (is_wp_error($profile)) {
        return $profile;
    }

    $username = !empty($profile['username']) ? sanitize_user((string) $profile['username'], true) : '';
    $name = !empty($profile['name']) ? sanitize_text_field((string) $profile['name']) : ($username !== '' ? '@' . $username : 'Instagram');
    $page_link = $username !== '' ? 'https://www.instagram.com/' . rawurlencode($username) . '/' : 'https://www.instagram.com/';

    if ($persist) {
        $settings = seo_social_network_get_settings();
        $saved = isset($settings['providers']['instagram']) && is_array($settings['providers']['instagram'])
            ? $settings['providers']['instagram']
            : $config;
        $saved['ig_user_id'] = $ig_user_id;
        $saved['account_username'] = $username;
        $saved['page_name'] = $name;
        $saved['page_link'] = $page_link;
        $saved['api_version'] = $version;
        $saved['enabled'] = 1;
        $settings['providers']['instagram'] = $saved;
        seo_social_network_save_settings($settings);
    }

    return array(
        'token'       => $token,
        'api_version' => $version,
        'ig_user_id'  => $ig_user_id,
        'username'    => $username,
        'page_name'   => $name,
        'page_link'   => $page_link,
    );
}

/**
 * @param array $config
 * @return array|WP_Error
 */
function seo_social_instagram_test_connection($config)
{
    $resolved = seo_social_instagram_resolve_credentials($config, true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    return array(
        'page_name' => $resolved['page_name'],
        'page_link' => $resolved['page_link'],
    );
}

/**
 * @param string $value
 * @param int    $limit
 * @return string
 */
function seo_social_instagram_limit_text($value, $limit = 2200)
{
    $value = trim(wp_strip_all_tags((string) $value));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, max(1, absint($limit)));
    }
    return substr($value, 0, max(1, absint($limit)));
}

/**
 * @param array $payload
 * @return array|WP_Error
 */
function seo_social_instagram_publish($payload)
{
    $payload = is_array($payload) ? $payload : array();
    $config = isset($payload['provider']) && is_array($payload['provider']) ? $payload['provider'] : array();
    $image_url = isset($payload['image_url']) ? esc_url_raw((string) $payload['image_url']) : '';
    $target_url = isset($payload['target_url']) ? esc_url_raw((string) $payload['target_url']) : '';
    $message = isset($payload['message']) ? (string) $payload['message'] : '';

    if ($image_url === '') {
        return new WP_Error('instagram_image_missing', 'Instagram requiere una imagen publica. Asigna una imagen destacada o una creatividad a la publicacion.');
    }

    if ($target_url !== '' && strpos($message, $target_url) === false) {
        $message = trim($message . "\n\n" . $target_url);
    }
    $message = seo_social_instagram_limit_text($message, 2200);

    $resolved = seo_social_instagram_resolve_credentials($config, true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $create_url = seo_social_instagram_graph_url($resolved['api_version'], $resolved['ig_user_id'] . '/media');
    $created = seo_social_instagram_decode_response(
        wp_remote_post(
            $create_url,
            array(
                'timeout' => 30,
                'body'    => array(
                    'image_url'    => $image_url,
                    'caption'      => $message,
                    'access_token' => $resolved['token'],
                ),
            )
        )
    );
    if (is_wp_error($created)) {
        return $created;
    }

    $creation_id = !empty($created['id']) ? preg_replace('/[^0-9]/', '', (string) $created['id']) : '';
    if ($creation_id === '') {
        return new WP_Error('instagram_container_missing', 'Instagram no devolvio el identificador del contenedor de medios.');
    }

    $publish_url = seo_social_instagram_graph_url($resolved['api_version'], $resolved['ig_user_id'] . '/media_publish');
    $published = seo_social_instagram_decode_response(
        wp_remote_post(
            $publish_url,
            array(
                'timeout' => 30,
                'body'    => array(
                    'creation_id'  => $creation_id,
                    'access_token' => $resolved['token'],
                ),
            )
        )
    );
    if (is_wp_error($published)) {
        return $published;
    }

    $media_id = !empty($published['id']) ? preg_replace('/[^0-9]/', '', (string) $published['id']) : '';
    if ($media_id === '') {
        return new WP_Error('instagram_media_missing', 'Instagram acepto la publicacion pero no devolvio el ID del medio.');
    }

    $remote_url = '';
    $media_url = seo_social_instagram_graph_url(
        $resolved['api_version'],
        $media_id,
        array(
            'fields'       => 'id,permalink',
            'access_token' => $resolved['token'],
        )
    );
    $media = seo_social_instagram_decode_response(
        wp_remote_get($media_url, array('timeout' => 20, 'redirection' => 3))
    );
    if (!is_wp_error($media) && !empty($media['permalink'])) {
        $remote_url = esc_url_raw((string) $media['permalink']);
    }

    return array(
        'remote_id'  => $media_id,
        'remote_url' => $remote_url,
    );
}

/**
 * @param object $publication
 * @param array  $config
 * @return array|WP_Error
 */
function seo_social_instagram_sync_publication($publication, $config)
{
    $remote_id = isset($publication->remote_id) ? preg_replace('/[^0-9]/', '', (string) $publication->remote_id) : '';
    if ($remote_id === '') {
        return new WP_Error('instagram_sync_missing_id', 'Falta el ID remoto de Instagram.');
    }

    $resolved = seo_social_instagram_resolve_credentials($config, true);
    if (is_wp_error($resolved)) {
        return $resolved;
    }

    $url = seo_social_instagram_graph_url(
        $resolved['api_version'],
        $remote_id,
        array(
            'fields'       => 'id,permalink,like_count,comments_count',
            'access_token' => $resolved['token'],
        )
    );
    $data = seo_social_instagram_decode_response(
        wp_remote_get($url, array('timeout' => 20, 'redirection' => 3))
    );
    if (is_wp_error($data)) {
        return $data;
    }

    return array(
        'remote_url' => !empty($data['permalink']) ? esc_url_raw((string) $data['permalink']) : '',
        'reactions'  => isset($data['like_count']) ? absint($data['like_count']) : 0,
        'comments'   => isset($data['comments_count']) ? absint($data['comments_count']) : 0,
    );
}

/**
 * @param array $config
 */
function seo_social_instagram_render_connection($config)
{
    $config = is_array($config) ? $config : array();
    $reuse = !isset($config['use_facebook_connection']) || !empty($config['use_facebook_connection']);
    $has_token = !empty($config['access_token_enc']);

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_social_network_save_connection"><input type="hidden" name="provider" value="instagram">';
    wp_nonce_field('seo_social_network_save_connection');

    echo '<div class="seo-social-field"><label><input type="checkbox" name="connection[use_facebook_connection]" value="1" ' . checked($reuse, true, false) . '> Reutilizar la conexion de Facebook</label><p class="seo-social-help">Recomendado. Usa la Page Access Token ya guardada y detecta la cuenta profesional de Instagram vinculada a esa pagina.</p></div>';

    echo '<div class="seo-social-grid" style="margin-top:12px">';
    echo '<div class="seo-social-field"><label>Instagram User ID</label><input type="text" inputmode="numeric" name="connection[ig_user_id]" value="' . esc_attr(isset($config['ig_user_id']) ? $config['ig_user_id'] : '') . '" placeholder="Se detecta al probar si reutilizas Facebook"></div>';
    echo '<div class="seo-social-field"><label>Version Graph API</label><input type="text" name="connection[api_version]" value="' . esc_attr(isset($config['api_version']) ? $config['api_version'] : 'v25.0') . '"></div>';
    echo '</div>';

    echo '<div class="seo-social-field" style="margin-top:12px"><label>Page Access Token manual (opcional)</label><input type="password" autocomplete="new-password" name="connection[access_token]" value="" placeholder="' . esc_attr($has_token ? 'Token manual guardado; deja vacio para conservarlo' : 'Solo si no reutilizas Facebook') . '"><p class="seo-social-help">Si desmarcas la reutilizacion de Facebook, indica el Instagram User ID y un token con permisos de publicacion.</p></div>';

    echo '<p class="seo-social-help">Instagram debe ser una cuenta Professional (Business o Creator). Con Facebook Login, la pagina debe estar vinculada a Instagram y el token debe incluir <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>instagram_basic</code> e <code>instagram_content_publish</code>. Si el token actual de Facebook se creo antes de habilitar Instagram, regeneralo con esos permisos.</p>';

    if (!empty($config['account_username'])) {
        echo '<p><strong>Cuenta detectada:</strong> @' . esc_html($config['account_username']) . '</p>';
    }
    if (!empty($config['last_test_at'])) {
        $ok = !empty($config['last_test_ok']);
        echo '<p><span class="seo-social-state ' . ($ok ? 'is-ok' : 'is-failed') . '">' . esc_html($ok ? 'Ultima prueba correcta' : 'Ultima prueba fallida') . '</span> <small>' . esc_html($config['last_test_at']) . '</small></p>';
        if (!$ok && !empty($config['last_test_error'])) {
            echo '<p class="seo-social-help" style="color:#b32d2e">' . esc_html($config['last_test_error']) . '</p>';
        }
    }

    echo '<div class="seo-social-actions"><button type="submit" class="button">Guardar</button><button type="submit" class="button button-primary" name="test_after_save" value="1">Guardar y probar conexion</button></div>';
    echo '</form>';

    if ($has_token || !empty($config['enabled']) || !empty($config['ig_user_id'])) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
        echo '<input type="hidden" name="action" value="seo_social_network_disconnect"><input type="hidden" name="provider" value="instagram">';
        wp_nonce_field('seo_social_network_disconnect');
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Se eliminara la configuracion guardada de Instagram. ¿Continuar?\');">Desconectar Instagram</button>';
        echo '</form>';
    }
}
