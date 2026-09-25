<?php
/**
 * SEO System - proveedor X para Social Network.
 *
 * Usa X API v2 con OAuth 2.0 Authorization Code + PKCE. Los tokens se guardan
 * cifrados y, si existe refresh token, se renuevan automaticamente.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_SOCIAL_X_API_BASE')) {
    define('SEO_SOCIAL_X_API_BASE', 'https://api.x.com/2');
}
if (!defined('SEO_SOCIAL_X_AUTHORIZE_URL')) {
    define('SEO_SOCIAL_X_AUTHORIZE_URL', 'https://x.com/i/oauth2/authorize');
}
if (!defined('SEO_SOCIAL_X_TOKEN_URL')) {
    define('SEO_SOCIAL_X_TOKEN_URL', 'https://api.x.com/2/oauth2/token');
}
if (!defined('SEO_SOCIAL_X_SCOPES')) {
    define('SEO_SOCIAL_X_SCOPES', 'tweet.read tweet.write users.read media.write offline.access');
}

/**
 * @param array $providers
 * @return array
 */
function seo_social_x_register_provider($providers)
{
    $providers = is_array($providers) ? $providers : array();
    $providers['x'] = array(
        'label'                        => 'X',
        'icon'                         => 'dashicons-share',
        'sanitize_connection_callback' => 'seo_social_x_sanitize_connection',
        'test_callback'                => 'seo_social_x_test_connection',
        'publish_callback'             => 'seo_social_x_publish',
        'sync_callback'                => 'seo_social_x_sync_publication',
        'render_connection_callback'   => 'seo_social_x_render_connection',
    );
    return $providers;
}
add_filter('seo_social_network_providers', 'seo_social_x_register_provider');

/**
 * @param array $posted
 * @param array $current
 * @return array|WP_Error
 */
function seo_social_x_sanitize_connection($posted, $current)
{
    $posted = is_array($posted) ? $posted : array();
    $current = is_array($current) ? $current : array();

    $client_id = isset($posted['client_id']) ? sanitize_text_field((string) $posted['client_id']) : '';
    $client_secret_enc = isset($current['client_secret_enc']) ? (string) $current['client_secret_enc'] : '';
    $new_secret = isset($posted['client_secret']) ? trim((string) $posted['client_secret']) : '';
    if ($new_secret !== '') {
        $client_secret_enc = seo_social_network_encrypt_secret($new_secret);
        if (is_wp_error($client_secret_enc)) {
            return $client_secret_enc;
        }
    }

    $access_token_enc = isset($current['access_token_enc']) ? (string) $current['access_token_enc'] : '';
    $token_expires_at = isset($current['token_expires_at']) ? absint($current['token_expires_at']) : 0;
    $new_token = isset($posted['access_token']) ? trim((string) $posted['access_token']) : '';
    if ($new_token !== '') {
        $access_token_enc = seo_social_network_encrypt_secret($new_token);
        if (is_wp_error($access_token_enc)) {
            return $access_token_enc;
        }
        $token_expires_at = 0;
    }

    $scope = isset($current['scope']) ? sanitize_text_field((string) $current['scope']) : SEO_SOCIAL_X_SCOPES;

    return array(
        'enabled'           => ($client_id !== '' && $access_token_enc !== '') ? 1 : 0,
        'client_id'         => $client_id,
        'client_secret_enc' => $client_secret_enc,
        'access_token_enc'  => $access_token_enc,
        'refresh_token_enc' => isset($current['refresh_token_enc']) ? (string) $current['refresh_token_enc'] : '',
        'token_expires_at'  => $token_expires_at,
        'scope'             => $scope,
        'user_id'           => isset($current['user_id']) ? preg_replace('/[^0-9]/', '', (string) $current['user_id']) : '',
        'username'          => isset($current['username']) ? sanitize_user((string) $current['username'], true) : '',
        'page_name'         => isset($current['page_name']) ? sanitize_text_field((string) $current['page_name']) : '',
        'page_link'         => isset($current['page_link']) ? esc_url_raw((string) $current['page_link']) : '',
        'publish_mode'      => 'text',
        'last_test_at'      => isset($current['last_test_at']) ? sanitize_text_field((string) $current['last_test_at']) : '',
        'last_test_ok'      => !empty($current['last_test_ok']) ? 1 : 0,
        'last_test_error'   => isset($current['last_test_error']) ? sanitize_text_field((string) $current['last_test_error']) : '',
    );
}

/**
 * @param string $token
 * @param array  $extra
 * @return array
 */
function seo_social_x_headers($token, $extra = array())
{
    return array_merge(
        array(
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ),
        is_array($extra) ? $extra : array()
    );
}

/**
 * @param mixed $response
 * @return array|WP_Error
 */
function seo_social_x_decode_response($response)
{
    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    $data = is_array($data) ? $data : array();

    if ($code < 200 || $code >= 300) {
        $message = '';
        if (!empty($data['detail'])) {
            $message = (string) $data['detail'];
        } elseif (!empty($data['title'])) {
            $message = (string) $data['title'];
        } elseif (!empty($data['error_description'])) {
            $message = (string) $data['error_description'];
        } elseif (!empty($data['errors'][0]['message'])) {
            $message = (string) $data['errors'][0]['message'];
        }
        if ($message === '') {
            $message = 'X devolvio HTTP ' . $code . '.';
        }
        return new WP_Error('x_api_error', sanitize_text_field($message));
    }

    return $data;
}

/**
 * @return string
 */
function seo_social_x_redirect_uri()
{
    return rest_url('seo-system/v1/x/callback');
}

/**
 * @param array $config
 * @param array $token_data
 * @return array|WP_Error
 */
function seo_social_x_apply_token_data($config, $token_data)
{
    $config = is_array($config) ? $config : array();
    $token_data = is_array($token_data) ? $token_data : array();

    if (empty($token_data['access_token'])) {
        return new WP_Error('x_missing_access_token', 'X no devolvio Access Token.');
    }

    $access = seo_social_network_encrypt_secret((string) $token_data['access_token']);
    if (is_wp_error($access)) {
        return $access;
    }

    $config['access_token_enc'] = $access;
    $config['token_expires_at'] = !empty($token_data['expires_in'])
        ? time() + max(60, absint($token_data['expires_in']))
        : 0;

    if (!empty($token_data['refresh_token'])) {
        $refresh = seo_social_network_encrypt_secret((string) $token_data['refresh_token']);
        if (is_wp_error($refresh)) {
            return $refresh;
        }
        $config['refresh_token_enc'] = $refresh;
    }

    if (!empty($token_data['scope'])) {
        $config['scope'] = sanitize_text_field((string) $token_data['scope']);
    }

    return $config;
}

/**
 * @param string $client_id
 * @param string $client_secret
 * @return array
 */
function seo_social_x_oauth_headers($client_id, $client_secret)
{
    $headers = array(
        'Accept'       => 'application/json',
        'Content-Type' => 'application/x-www-form-urlencoded',
    );
    if ($client_secret !== '') {
        $headers['Authorization'] = 'Basic ' . base64_encode($client_id . ':' . $client_secret);
    }
    return $headers;
}

/**
 * @param array $config
 * @return string|WP_Error
 */
function seo_social_x_get_access_token($config)
{
    $config = is_array($config) ? $config : array();
    $token = seo_social_network_decrypt_secret(isset($config['access_token_enc']) ? $config['access_token_enc'] : '');
    if ($token === '') {
        return new WP_Error('x_missing_token', 'X no tiene un Access Token guardado.');
    }

    $expires_at = isset($config['token_expires_at']) ? absint($config['token_expires_at']) : 0;
    if ($expires_at === 0 || $expires_at > time() + 300) {
        return $token;
    }

    $refresh = seo_social_network_decrypt_secret(isset($config['refresh_token_enc']) ? $config['refresh_token_enc'] : '');
    $client_id = isset($config['client_id']) ? trim((string) $config['client_id']) : '';
    $client_secret = seo_social_network_decrypt_secret(isset($config['client_secret_enc']) ? $config['client_secret_enc'] : '');

    if ($refresh === '' || $client_id === '') {
        return new WP_Error('x_token_expired', 'El token de X ha caducado y no hay refresh token. Vuelve a conectar X.');
    }

    $body = array(
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refresh,
        'client_id'     => $client_id,
    );

    $response = wp_remote_post(
        SEO_SOCIAL_X_TOKEN_URL,
        array(
            'timeout' => 20,
            'headers' => seo_social_x_oauth_headers($client_id, $client_secret),
            'body'    => $body,
        )
    );
    $data = seo_social_x_decode_response($response);
    if (is_wp_error($data)) {
        return $data;
    }

    $updated = seo_social_x_apply_token_data($config, $data);
    if (is_wp_error($updated)) {
        return $updated;
    }

    $settings = seo_social_network_get_settings();
    $settings['providers']['x'] = $updated;
    seo_social_network_save_settings($settings);

    return seo_social_network_decrypt_secret($updated['access_token_enc']);
}

/**
 * @param array $config
 * @return array|WP_Error
 */
function seo_social_x_test_connection($config)
{
    $token = seo_social_x_get_access_token($config);
    if (is_wp_error($token)) {
        return $token;
    }

    $response = wp_remote_get(
        SEO_SOCIAL_X_API_BASE . '/users/me?user.fields=id,name,username',
        array(
            'timeout' => 20,
            'headers' => seo_social_x_headers($token),
        )
    );
    $data = seo_social_x_decode_response($response);
    if (is_wp_error($data)) {
        return $data;
    }

    $user = isset($data['data']) && is_array($data['data']) ? $data['data'] : array();
    if (empty($user['id'])) {
        return new WP_Error('x_user_missing', 'X respondio, pero no devolvio la cuenta autorizada.');
    }

    $username = !empty($user['username']) ? sanitize_user((string) $user['username'], true) : '';
    $page_name = !empty($user['name']) ? sanitize_text_field((string) $user['name']) : ($username !== '' ? '@' . $username : 'X');
    $page_link = $username !== '' ? 'https://x.com/' . rawurlencode($username) : 'https://x.com/';

    $settings = seo_social_network_get_settings();
    $saved = isset($settings['providers']['x']) && is_array($settings['providers']['x'])
        ? $settings['providers']['x']
        : $config;
    $saved['user_id'] = preg_replace('/[^0-9]/', '', (string) $user['id']);
    $saved['username'] = $username;
    $saved['page_name'] = $page_name;
    $saved['page_link'] = $page_link;
    $saved['enabled'] = 1;
    $settings['providers']['x'] = $saved;
    seo_social_network_save_settings($settings);

    return array(
        'page_name' => $page_name,
        'page_link' => $page_link,
    );
}

/**
 * Construye un post de X conservando el enlace medido. Se reserva espacio para
 * el enlace porque X lo acorta mediante t.co.
 *
 * @param string $message
 * @param string $target_url
 * @return string
 */
function seo_social_x_prepare_text($message, $target_url)
{
    $message = trim(wp_strip_all_tags((string) $message));
    $target_url = esc_url_raw((string) $target_url);

    if ($target_url !== '') {
        $message = trim(str_replace($target_url, '', $message));
        $limit = 250;
        if (function_exists('mb_substr')) {
            $message = mb_substr($message, 0, $limit);
        } else {
            $message = substr($message, 0, $limit);
        }
        return trim($message) . "\n" . $target_url;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($message, 0, 280);
    }
    return substr($message, 0, 280);
}

/**
 * @param array $payload
 * @return array|WP_Error
 */
function seo_social_x_publish($payload)
{
    $payload = is_array($payload) ? $payload : array();
    $config = isset($payload['provider']) && is_array($payload['provider']) ? $payload['provider'] : array();
    $token = seo_social_x_get_access_token($config);
    if (is_wp_error($token)) {
        return $token;
    }

    $text = seo_social_x_prepare_text(
        isset($payload['message']) ? (string) $payload['message'] : '',
        isset($payload['target_url']) ? (string) $payload['target_url'] : ''
    );
    if ($text === '') {
        return new WP_Error('x_empty_post', 'No hay texto para publicar en X.');
    }

    $response = wp_remote_post(
        SEO_SOCIAL_X_API_BASE . '/tweets',
        array(
            'timeout' => 25,
            'headers' => seo_social_x_headers($token),
            'body'    => wp_json_encode(array('text' => $text)),
        )
    );
    $data = seo_social_x_decode_response($response);
    if (is_wp_error($data)) {
        return $data;
    }

    $tweet = isset($data['data']) && is_array($data['data']) ? $data['data'] : array();
    $id = !empty($tweet['id']) ? preg_replace('/[^0-9]/', '', (string) $tweet['id']) : '';
    if ($id === '') {
        return new WP_Error('x_missing_post_id', 'X acepto la solicitud pero no devolvio el ID de la publicacion.');
    }

    return array(
        'remote_id'  => $id,
        'remote_url' => 'https://x.com/i/web/status/' . rawurlencode($id),
    );
}

/**
 * @param object $publication
 * @param array  $config
 * @return array|WP_Error
 */
function seo_social_x_sync_publication($publication, $config)
{
    $id = isset($publication->remote_id) ? preg_replace('/[^0-9]/', '', (string) $publication->remote_id) : '';
    if ($id === '') {
        return new WP_Error('x_sync_missing_id', 'Falta el ID remoto de X.');
    }

    $token = seo_social_x_get_access_token($config);
    if (is_wp_error($token)) {
        return $token;
    }

    $response = wp_remote_get(
        SEO_SOCIAL_X_API_BASE . '/tweets/' . rawurlencode($id) . '?tweet.fields=public_metrics',
        array(
            'timeout' => 20,
            'headers' => seo_social_x_headers($token),
        )
    );
    $data = seo_social_x_decode_response($response);
    if (is_wp_error($data)) {
        return $data;
    }

    $metrics = !empty($data['data']['public_metrics']) && is_array($data['data']['public_metrics'])
        ? $data['data']['public_metrics']
        : array();

    return array(
        'remote_url' => 'https://x.com/i/web/status/' . rawurlencode($id),
        'reactions'  => isset($metrics['like_count']) ? absint($metrics['like_count']) : 0,
        'comments'   => isset($metrics['reply_count']) ? absint($metrics['reply_count']) : 0,
        'shares'     => (isset($metrics['retweet_count']) ? absint($metrics['retweet_count']) : 0)
            + (isset($metrics['quote_count']) ? absint($metrics['quote_count']) : 0),
        'impressions'=> isset($metrics['impression_count']) ? absint($metrics['impression_count']) : 0,
    );
}

/**
 * Inicia OAuth 2.0 + PKCE.
 */
function seo_social_x_oauth_start()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para conectar X.', 'seo-system'));
    }
    check_admin_referer('seo_social_x_oauth_start');

    $settings = seo_social_network_get_settings();
    $config = isset($settings['providers']['x']) && is_array($settings['providers']['x'])
        ? $settings['providers']['x']
        : array();
    $client_id = isset($config['client_id']) ? trim((string) $config['client_id']) : '';
    $client_secret = seo_social_network_decrypt_secret(isset($config['client_secret_enc']) ? $config['client_secret_enc'] : '');

    if ($client_id === '' || $client_secret === '') {
        wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'x_app_missing')));
        exit;
    }

    $state = wp_generate_password(48, false, false);
    $verifier = wp_generate_password(72, false, false);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $key = 'seo_x_state_' . substr(hash('sha256', $state), 0, 32);

    set_transient(
        $key,
        array(
            'user_id'       => get_current_user_id(),
            'code_verifier' => $verifier,
            'created'       => time(),
        ),
        10 * MINUTE_IN_SECONDS
    );

    $url = add_query_arg(
        array(
            'response_type'         => 'code',
            'client_id'             => $client_id,
            'redirect_uri'          => seo_social_x_redirect_uri(),
            'scope'                 => SEO_SOCIAL_X_SCOPES,
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ),
        SEO_SOCIAL_X_AUTHORIZE_URL
    );

    wp_redirect(esc_url_raw($url)); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
    exit;
}
add_action('admin_post_seo_social_x_oauth_start', 'seo_social_x_oauth_start');

/**
 * @param WP_REST_Request $request
 * @return WP_REST_Response|void
 */
function seo_social_x_oauth_callback(WP_REST_Request $request)
{
    $state = sanitize_text_field((string) $request->get_param('state'));
    $code = sanitize_text_field((string) $request->get_param('code'));
    $oauth_error = sanitize_text_field((string) $request->get_param('error'));
    $oauth_error_description = sanitize_text_field((string) $request->get_param('error_description'));
    $key = $state !== '' ? 'seo_x_state_' . substr(hash('sha256', $state), 0, 32) : '';
    $state_data = $key !== '' ? get_transient($key) : false;
    if ($key !== '') {
        delete_transient($key);
    }

    if (!is_array($state_data) || empty($state_data['user_id']) || empty($state_data['code_verifier'])) {
        return new WP_REST_Response(array('error' => 'Estado OAuth de X invalido o caducado.'), 400);
    }

    $user_id = absint($state_data['user_id']);
    $user = get_user_by('id', $user_id);
    if (!$user || !user_can($user, 'manage_options')) {
        return new WP_REST_Response(array('error' => 'El usuario que inicio OAuth ya no tiene permisos.'), 403);
    }

    if ($oauth_error !== '' || $code === '') {
        $detail = $oauth_error_description !== '' ? $oauth_error_description : $oauth_error;
        if ($detail === '') {
            $detail = 'X no devolvio un codigo de autorizacion.';
        }
        set_transient('seo_social_x_oauth_error_' . $user_id, $detail, 90);
        wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'x_oauth_failed')));
        exit;
    }

    $settings = seo_social_network_get_settings();
    $config = isset($settings['providers']['x']) && is_array($settings['providers']['x'])
        ? $settings['providers']['x']
        : array();
    $client_id = isset($config['client_id']) ? trim((string) $config['client_id']) : '';
    $client_secret = seo_social_network_decrypt_secret(isset($config['client_secret_enc']) ? $config['client_secret_enc'] : '');

    if ($client_id === '' || $client_secret === '') {
        set_transient('seo_social_x_oauth_error_' . $user_id, 'Faltan el Client ID o Client Secret guardados.', 90);
        wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'x_oauth_failed')));
        exit;
    }

    $response = wp_remote_post(
        SEO_SOCIAL_X_TOKEN_URL,
        array(
            'timeout' => 20,
            'headers' => seo_social_x_oauth_headers($client_id, $client_secret),
            'body'    => array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => seo_social_x_redirect_uri(),
                'code_verifier' => (string) $state_data['code_verifier'],
                'client_id'     => $client_id,
            ),
        )
    );
    $token_data = seo_social_x_decode_response($response);
    if (is_wp_error($token_data)) {
        set_transient('seo_social_x_oauth_error_' . $user_id, $token_data->get_error_message(), 90);
        wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'x_oauth_failed')));
        exit;
    }

    $updated = seo_social_x_apply_token_data($config, $token_data);
    if (is_wp_error($updated)) {
        set_transient('seo_social_x_oauth_error_' . $user_id, $updated->get_error_message(), 90);
        wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'x_oauth_failed')));
        exit;
    }

    $settings['providers']['x'] = $updated;
    seo_social_network_save_settings($settings);

    $test = seo_social_x_test_connection($updated);
    if (is_wp_error($test)) {
        set_transient('seo_social_x_oauth_error_' . $user_id, $test->get_error_message(), 90);
        wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'x_oauth_failed')));
        exit;
    }

    $settings = seo_social_network_get_settings();
    $settings['providers']['x']['last_test_at'] = current_time('mysql');
    $settings['providers']['x']['last_test_ok'] = 1;
    $settings['providers']['x']['last_test_error'] = '';
    seo_social_network_save_settings($settings);

    wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'x_connected')));
    exit;
}

/**
 * Registra callback OAuth de X.
 */
function seo_social_x_register_rest_route()
{
    register_rest_route(
        'seo-system/v1',
        '/x/callback',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'seo_social_x_oauth_callback',
            'permission_callback' => '__return_true',
        )
    );
}
add_action('rest_api_init', 'seo_social_x_register_rest_route');

/**
 * @param array $config
 */
function seo_social_x_render_connection($config)
{
    $config = is_array($config) ? $config : array();
    $has_secret = !empty($config['client_secret_enc']);
    $has_token = !empty($config['access_token_enc']);

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_social_network_save_connection"><input type="hidden" name="provider" value="x">';
    wp_nonce_field('seo_social_network_save_connection');

    echo '<div class="seo-social-grid">';
    echo '<div class="seo-social-field"><label>X OAuth 2.0 Client ID</label><input type="text" name="connection[client_id]" value="' . esc_attr(isset($config['client_id']) ? $config['client_id'] : '') . '" autocomplete="off"></div>';
    echo '<div class="seo-social-field"><label>Client Secret</label><input type="password" name="connection[client_secret]" value="" autocomplete="new-password" placeholder="' . esc_attr($has_secret ? 'Secreto guardado; deja vacio para conservarlo' : 'Client Secret de la app de X') . '"></div>';
    echo '</div>';

    echo '<div class="seo-social-field" style="margin-top:12px"><label>Access Token manual (opcional)</label><input type="password" name="connection[access_token]" value="" autocomplete="new-password" placeholder="' . esc_attr($has_token ? 'Token guardado; deja vacio para conservarlo' : 'Normalmente se obtiene con Conectar / renovar') . '"></div>';

    echo '<p class="seo-social-code-note"><strong>Callback URL:</strong><br><code>' . esc_html(seo_social_x_redirect_uri()) . '</code></p>';
    echo '<p class="seo-social-help">Configura esa URL como Redirect URI en tu app de X. La app debe permitir OAuth 2.0 con permisos de lectura y escritura. Se solicitan <code>' . esc_html(SEO_SOCIAL_X_SCOPES) . '</code>. La publicacion inicial usa texto + enlace; las imagenes pueden añadirse despues mediante Media API sin cambiar el programador.</p>';

    if (!empty($config['username'])) {
        echo '<p><strong>Cuenta autorizada:</strong> @' . esc_html($config['username']) . '</p>';
    }
    if (!empty($config['last_test_at'])) {
        $ok = !empty($config['last_test_ok']);
        echo '<p><span class="seo-social-state ' . ($ok ? 'is-ok' : 'is-failed') . '">' . esc_html($ok ? 'Ultima prueba correcta' : 'Ultima prueba fallida') . '</span> <small>' . esc_html($config['last_test_at']) . '</small></p>';
        if (!$ok && !empty($config['last_test_error'])) {
            echo '<p class="seo-social-help" style="color:#b32d2e">' . esc_html($config['last_test_error']) . '</p>';
        }
    }

    echo '<div class="seo-social-actions">';
    echo '<button type="submit" class="button">Guardar</button>';
    echo '<button type="submit" class="button button-primary" name="test_after_save" value="1">Guardar y probar conexion</button>';
    echo '</div>';
    echo '</form>';

    if ($has_secret && !empty($config['client_id'])) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
        echo '<input type="hidden" name="action" value="seo_social_x_oauth_start">';
        wp_nonce_field('seo_social_x_oauth_start');
        echo '<button type="submit" class="button button-primary">Conectar / renovar X</button>';
        echo '</form>';
    }

    if ($has_token || !empty($config['enabled'])) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
        echo '<input type="hidden" name="action" value="seo_social_network_disconnect"><input type="hidden" name="provider" value="x">';
        wp_nonce_field('seo_social_network_disconnect');
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Se eliminaran los tokens guardados de X. ¿Continuar?\');">Desconectar X</button>';
        echo '</form>';
    }
}
