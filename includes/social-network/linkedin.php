<?php
/**
 * SEO System - proveedor LinkedIn Pages para Social Network.
 *
 * Publica mediante LinkedIn Posts API y permite autorizar una aplicacion
 * mediante OAuth 2.0. El token y el client secret se guardan cifrados.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_SOCIAL_LINKEDIN_API_VERSION')) {
    define('SEO_SOCIAL_LINKEDIN_API_VERSION', '202608');
}

/**
 * @param array $providers
 * @return array
 */
function seo_social_linkedin_register_provider($providers)
{
    $providers = is_array($providers) ? $providers : array();
    $providers['linkedin'] = array(
        'label'                        => 'LinkedIn',
        'icon'                         => 'dashicons-linkedin',
        'sanitize_connection_callback' => 'seo_social_linkedin_sanitize_connection',
        'test_callback'                => 'seo_social_linkedin_test_connection',
        'publish_callback'             => 'seo_social_linkedin_publish',
        'render_connection_callback'   => 'seo_social_linkedin_render_connection',
    );
    return $providers;
}
add_filter('seo_social_network_providers', 'seo_social_linkedin_register_provider');

/**
 * @param string $value
 * @param int    $limit
 * @return string
 */
function seo_social_linkedin_limit_text($value, $limit)
{
    $value = trim(wp_strip_all_tags((string) $value));
    $limit = max(1, absint($limit));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit);
    }
    return substr($value, 0, $limit);
}

/**
 * @param array $posted
 * @param array $current
 * @return array|WP_Error
 */
function seo_social_linkedin_sanitize_connection($posted, $current)
{
    $posted = is_array($posted) ? $posted : array();
    $current = is_array($current) ? $current : array();

    $client_id = isset($posted['client_id']) ? sanitize_text_field((string) $posted['client_id']) : '';
    $organization_id = isset($posted['organization_id']) ? preg_replace('/[^0-9]/', '', (string) $posted['organization_id']) : '';
    $api_version = isset($posted['api_version']) ? preg_replace('/[^0-9]/', '', (string) $posted['api_version']) : SEO_SOCIAL_LINKEDIN_API_VERSION;
    if (!preg_match('/^20\d{4}$/', $api_version)) {
        $api_version = SEO_SOCIAL_LINKEDIN_API_VERSION;
    }

    $publish_mode = isset($posted['publish_mode']) ? sanitize_key((string) $posted['publish_mode']) : 'article';
    if (!in_array($publish_mode, array('article', 'text'), true)) {
        $publish_mode = 'article';
    }

    $client_secret_enc = isset($current['client_secret_enc']) ? (string) $current['client_secret_enc'] : '';
    $new_client_secret = isset($posted['client_secret']) ? trim((string) $posted['client_secret']) : '';
    if ($new_client_secret !== '') {
        $client_secret_enc = seo_social_network_encrypt_secret($new_client_secret);
        if (is_wp_error($client_secret_enc)) {
            return $client_secret_enc;
        }
    }

    $access_token_enc = isset($current['access_token_enc']) ? (string) $current['access_token_enc'] : '';
    $token_expires_at = isset($current['token_expires_at']) ? absint($current['token_expires_at']) : 0;
    $new_access_token = isset($posted['access_token']) ? trim((string) $posted['access_token']) : '';
    if ($new_access_token !== '') {
        $access_token_enc = seo_social_network_encrypt_secret($new_access_token);
        if (is_wp_error($access_token_enc)) {
            return $access_token_enc;
        }
        // Un token pegado manualmente no trae TTL fiable: no heredar la caducidad del token anterior.
        $token_expires_at = 0;
    }

    $organizations = isset($current['organizations']) && is_array($current['organizations'])
        ? $current['organizations']
        : array();
    $organization_name = isset($current['organization_name']) ? sanitize_text_field((string) $current['organization_name']) : '';
    $organization_vanity = isset($current['organization_vanity']) ? sanitize_title((string) $current['organization_vanity']) : '';
    $organization_link = isset($current['organization_link']) ? esc_url_raw((string) $current['organization_link']) : '';

    foreach ($organizations as $organization) {
        if (!is_array($organization) || empty($organization['id']) || (string) $organization['id'] !== (string) $organization_id) {
            continue;
        }
        $organization_name = isset($organization['name']) ? sanitize_text_field((string) $organization['name']) : $organization_name;
        $organization_vanity = isset($organization['vanity']) ? sanitize_title((string) $organization['vanity']) : $organization_vanity;
        $organization_link = isset($organization['link']) ? esc_url_raw((string) $organization['link']) : $organization_link;
        break;
    }

    return array(
        'enabled'                  => ($organization_id !== '' && $access_token_enc !== '') ? 1 : 0,
        'client_id'                => $client_id,
        'client_secret_enc'        => $client_secret_enc,
        'access_token_enc'         => $access_token_enc,
        'refresh_token_enc'        => isset($current['refresh_token_enc']) ? (string) $current['refresh_token_enc'] : '',
        'token_expires_at'         => $token_expires_at,
        'refresh_token_expires_at' => isset($current['refresh_token_expires_at']) ? absint($current['refresh_token_expires_at']) : 0,
        'organization_id'          => $organization_id,
        'organization_name'        => $organization_name,
        'organization_vanity'      => $organization_vanity,
        'organization_link'        => $organization_link,
        'page_name'                => $organization_name,
        'page_link'                => $organization_link,
        'organizations'            => $organizations,
        'api_version'              => $api_version,
        'publish_mode'             => $publish_mode,
        'last_test_at'             => isset($current['last_test_at']) ? sanitize_text_field((string) $current['last_test_at']) : '',
        'last_test_ok'             => !empty($current['last_test_ok']) ? 1 : 0,
        'last_test_error'          => isset($current['last_test_error']) ? sanitize_text_field((string) $current['last_test_error']) : '',
    );
}

/**
 * @param array $config
 * @return string
 */
function seo_social_linkedin_api_version($config)
{
    $version = isset($config['api_version']) ? preg_replace('/[^0-9]/', '', (string) $config['api_version']) : '';
    return preg_match('/^20\d{4}$/', $version) ? $version : SEO_SOCIAL_LINKEDIN_API_VERSION;
}

/**
 * @param string $token
 * @param array  $config
 * @param array  $extra
 * @return array
 */
function seo_social_linkedin_headers($token, $config, $extra = array())
{
    return array_merge(
        array(
            'Authorization'                => 'Bearer ' . $token,
            'Linkedin-Version'             => seo_social_linkedin_api_version($config),
            'X-Restli-Protocol-Version'    => '2.0.0',
            'Content-Type'                 => 'application/json',
            'Accept'                       => 'application/json',
        ),
        is_array($extra) ? $extra : array()
    );
}

/**
 * @param mixed $response
 * @return array|WP_Error
 */
function seo_social_linkedin_decode_response($response)
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
        if (!empty($data['message'])) {
            $message = (string) $data['message'];
        } elseif (!empty($data['error_description'])) {
            $message = (string) $data['error_description'];
        } elseif (!empty($data['error'])) {
            $message = is_string($data['error']) ? $data['error'] : wp_json_encode($data['error']);
        }
        if ($message === '') {
            $message = 'LinkedIn devolvio HTTP ' . $code . '.';
        }
        return new WP_Error('linkedin_api_error', sanitize_text_field($message));
    }

    $data['_http_code'] = $code;
    return $data;
}

/**
 * @return string
 */
function seo_social_linkedin_redirect_uri()
{
    return rest_url('seo-system/v1/linkedin/callback');
}

/**
 * @param array $config
 * @return string|WP_Error
 */
function seo_social_linkedin_get_access_token($config)
{
    $config = is_array($config) ? $config : array();
    $token = seo_social_network_decrypt_secret(isset($config['access_token_enc']) ? $config['access_token_enc'] : '');
    if ($token === '') {
        return new WP_Error('linkedin_missing_token', 'LinkedIn no tiene un Access Token guardado.');
    }

    $expires_at = isset($config['token_expires_at']) ? absint($config['token_expires_at']) : 0;
    if ($expires_at === 0 || $expires_at > time() + 300) {
        return $token;
    }

    $refresh_token = seo_social_network_decrypt_secret(isset($config['refresh_token_enc']) ? $config['refresh_token_enc'] : '');
    $client_secret = seo_social_network_decrypt_secret(isset($config['client_secret_enc']) ? $config['client_secret_enc'] : '');
    $client_id = isset($config['client_id']) ? trim((string) $config['client_id']) : '';

    if ($refresh_token === '' || $client_id === '' || $client_secret === '') {
        return new WP_Error('linkedin_token_expired', 'El Access Token de LinkedIn ha caducado. Vuelve a autorizar LinkedIn desde Conexiones.');
    }

    $response = wp_remote_post(
        'https://www.linkedin.com/oauth/v2/accessToken',
        array(
            'timeout' => 20,
            'headers' => array('Accept' => 'application/json'),
            'body'    => array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ),
        )
    );
    $data = seo_social_linkedin_decode_response($response);
    if (is_wp_error($data) || empty($data['access_token'])) {
        return is_wp_error($data)
            ? $data
            : new WP_Error('linkedin_refresh_failed', 'LinkedIn no devolvio un nuevo Access Token.');
    }

    $encrypted = seo_social_network_encrypt_secret((string) $data['access_token']);
    if (is_wp_error($encrypted)) {
        return $encrypted;
    }

    $settings = seo_social_network_get_settings();
    if (!isset($settings['providers']['linkedin']) || !is_array($settings['providers']['linkedin'])) {
        $settings['providers']['linkedin'] = array();
    }
    $saved = array_replace($settings['providers']['linkedin'], $config);
    $saved['access_token_enc'] = $encrypted;
    $saved['token_expires_at'] = !empty($data['expires_in']) ? time() + absint($data['expires_in']) : 0;

    if (!empty($data['refresh_token'])) {
        $refresh_encrypted = seo_social_network_encrypt_secret((string) $data['refresh_token']);
        if (!is_wp_error($refresh_encrypted)) {
            $saved['refresh_token_enc'] = $refresh_encrypted;
        }
    }
    if (!empty($data['refresh_token_expires_in'])) {
        $saved['refresh_token_expires_at'] = time() + absint($data['refresh_token_expires_in']);
    }

    $settings['providers']['linkedin'] = $saved;
    seo_social_network_save_settings($settings);

    return (string) $data['access_token'];
}

/**
 * @param string $token
 * @param array  $config
 * @param string $organization_id
 * @return array|WP_Error
 */
function seo_social_linkedin_fetch_organization($token, $config, $organization_id)
{
    $organization_id = preg_replace('/[^0-9]/', '', (string) $organization_id);
    if ($organization_id === '') {
        return new WP_Error('linkedin_missing_organization', 'Falta el ID de la pagina de empresa de LinkedIn.');
    }

    $response = wp_remote_get(
        'https://api.linkedin.com/rest/organizations/' . rawurlencode($organization_id),
        array(
            'timeout' => 20,
            'headers' => seo_social_linkedin_headers($token, $config),
        )
    );
    $data = seo_social_linkedin_decode_response($response);
    if (is_wp_error($data)) {
        return $data;
    }

    $name = '';
    if (!empty($data['localizedName'])) {
        $name = (string) $data['localizedName'];
    } elseif (!empty($data['name']['localized']['es_ES'])) {
        $name = (string) $data['name']['localized']['es_ES'];
    }
    $vanity = !empty($data['vanityName']) ? sanitize_title((string) $data['vanityName']) : '';
    $link = $vanity !== '' ? 'https://www.linkedin.com/company/' . rawurlencode($vanity) . '/' : '';

    return array(
        'id'     => $organization_id,
        'name'   => sanitize_text_field($name),
        'vanity' => $vanity,
        'link'   => esc_url_raw($link),
    );
}

/**
 * @param array $config
 * @return array|WP_Error
 */
function seo_social_linkedin_test_connection($config)
{
    $organization_id = isset($config['organization_id']) ? preg_replace('/[^0-9]/', '', (string) $config['organization_id']) : '';
    $token = seo_social_linkedin_get_access_token($config);
    if (is_wp_error($token)) {
        return $token;
    }

    $organization = seo_social_linkedin_fetch_organization($token, $config, $organization_id);
    if (is_wp_error($organization)) {
        return $organization;
    }

    return array(
        'page_name' => $organization['name'],
        'page_link' => $organization['link'],
    );
}

/**
 * @param array $payload
 * @return array|WP_Error
 */
function seo_social_linkedin_publish($payload)
{
    $config = isset($payload['provider']) && is_array($payload['provider']) ? $payload['provider'] : array();
    $organization_id = isset($config['organization_id']) ? preg_replace('/[^0-9]/', '', (string) $config['organization_id']) : '';
    if ($organization_id === '') {
        return new WP_Error('linkedin_missing_organization', 'Selecciona una pagina de empresa de LinkedIn antes de publicar.');
    }

    $token = seo_social_linkedin_get_access_token($config);
    if (is_wp_error($token)) {
        return $token;
    }

    $message = isset($payload['message']) ? seo_social_linkedin_limit_text($payload['message'], 3000) : '';
    $target_url = isset($payload['target_url']) ? esc_url_raw((string) $payload['target_url']) : '';
    $post = isset($payload['post']) && $payload['post'] instanceof WP_Post ? $payload['post'] : null;
    $mode = isset($config['publish_mode']) ? sanitize_key((string) $config['publish_mode']) : 'article';

    $body = array(
        'author'     => 'urn:li:organization:' . $organization_id,
        'commentary' => $message,
        'visibility' => 'PUBLIC',
        'distribution' => array(
            'feedDistribution'                => 'MAIN_FEED',
            'targetEntities'                  => array(),
            'thirdPartyDistributionChannels'  => array(),
        ),
        'lifecycleState'             => 'PUBLISHED',
        'isReshareDisabledByAuthor'  => false,
    );

    if ('article' === $mode && $target_url !== '') {
        $title = $post ? seo_social_linkedin_limit_text(get_the_title($post), 400) : '';
        $description = '';
        if ($post) {
            $description_source = has_excerpt($post) ? $post->post_excerpt : $post->post_content;
            $description = seo_social_linkedin_limit_text($description_source, 4086);
        }
        $body['content'] = array(
            'article' => array(
                'source'      => $target_url,
                'title'       => $title,
                'description' => $description,
            ),
        );
    } elseif ($target_url !== '' && strpos($message, $target_url) === false) {
        $body['commentary'] = seo_social_linkedin_limit_text(trim($message . "\n\n" . $target_url), 3000);
    }

    $response = wp_remote_post(
        'https://api.linkedin.com/rest/posts',
        array(
            'timeout' => 25,
            'headers' => seo_social_linkedin_headers($token, $config),
            'body'    => wp_json_encode($body),
        )
    );
    $data = seo_social_linkedin_decode_response($response);
    if (is_wp_error($data)) {
        return $data;
    }

    $remote_id = (string) wp_remote_retrieve_header($response, 'x-restli-id');
    if ($remote_id === '' && !empty($data['id'])) {
        $remote_id = (string) $data['id'];
    }
    if ($remote_id === '') {
        return new WP_Error('linkedin_missing_remote_id', 'LinkedIn acepto la publicacion, pero no devolvio su identificador.');
    }

    return array(
        'remote_id'  => sanitize_text_field($remote_id),
        'remote_url' => esc_url_raw('https://www.linkedin.com/feed/update/' . $remote_id . '/'),
    );
}

/**
 * Devuelve las organizaciones en las que el usuario puede publicar.
 *
 * @param string $token
 * @param array  $config
 * @return array|WP_Error
 */
function seo_social_linkedin_discover_organizations($token, $config)
{
    $url = add_query_arg(
        array(
            'q'     => 'roleAssignee',
            'state' => 'APPROVED',
            'count' => 100,
        ),
        'https://api.linkedin.com/rest/organizationAcls'
    );

    $response = wp_remote_get(
        $url,
        array(
            'timeout' => 20,
            'headers' => seo_social_linkedin_headers($token, $config),
        )
    );
    $data = seo_social_linkedin_decode_response($response);
    if (is_wp_error($data)) {
        return $data;
    }

    $allowed_roles = array('ADMINISTRATOR', 'CONTENT_ADMIN', 'CONTENT_ADMINISTRATOR', 'DIRECT_SPONSORED_CONTENT_POSTER');
    $ids = array();
    foreach ((array) ($data['elements'] ?? array()) as $element) {
        if (!is_array($element) || empty($element['role']) || !in_array((string) $element['role'], $allowed_roles, true)) {
            continue;
        }
        $urn = '';
        if (!empty($element['organization'])) {
            $urn = (string) $element['organization'];
        } elseif (!empty($element['organizationTarget'])) {
            $urn = (string) $element['organizationTarget'];
        }
        if (preg_match('/urn:li:organization:(\d+)/', $urn, $matches)) {
            $ids[$matches[1]] = $matches[1];
        }
    }

    $organizations = array();
    foreach ($ids as $id) {
        $organization = seo_social_linkedin_fetch_organization($token, $config, $id);
        if (!is_wp_error($organization)) {
            $organizations[] = $organization;
        }
    }

    return $organizations;
}

/**
 * Inicia OAuth desde wp-admin.
 */
function seo_social_linkedin_oauth_start()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para conectar LinkedIn.', 'seo-system'));
    }
    check_admin_referer('seo_social_linkedin_oauth_start');

    $settings = seo_social_network_get_settings();
    $config = isset($settings['providers']['linkedin']) && is_array($settings['providers']['linkedin'])
        ? $settings['providers']['linkedin']
        : array();
    $client_id = isset($config['client_id']) ? trim((string) $config['client_id']) : '';
    $client_secret = seo_social_network_decrypt_secret(isset($config['client_secret_enc']) ? $config['client_secret_enc'] : '');
    if ($client_id === '' || $client_secret === '') {
        wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'linkedin_app_missing')));
        exit;
    }

    $state = wp_generate_password(48, false, false);
    $state_key = 'seo_li_state_' . substr(hash('sha256', $state), 0, 32);
    set_transient(
        $state_key,
        array(
            'user_id' => get_current_user_id(),
            'created' => time(),
        ),
        10 * MINUTE_IN_SECONDS
    );

    $url = add_query_arg(
        array(
            'response_type' => 'code',
            'client_id'     => $client_id,
            'redirect_uri'  => seo_social_linkedin_redirect_uri(),
            'state'         => $state,
            'scope'         => 'r_organization_admin w_organization_social',
        ),
        'https://www.linkedin.com/oauth/v2/authorization'
    );

    wp_redirect(esc_url_raw($url)); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
    exit;
}
add_action('admin_post_seo_social_linkedin_oauth_start', 'seo_social_linkedin_oauth_start');

/**
 * Callback OAuth expuesto mediante REST para tener una Redirect URL estable.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|void
 */
function seo_social_linkedin_oauth_callback(WP_REST_Request $request)
{
    $state = sanitize_text_field((string) $request->get_param('state'));
    $code = sanitize_text_field((string) $request->get_param('code'));
    $oauth_error = sanitize_text_field((string) $request->get_param('error'));
    $state_key = $state !== '' ? 'seo_li_state_' . substr(hash('sha256', $state), 0, 32) : '';
    $state_data = $state_key !== '' ? get_transient($state_key) : false;

    if ($state_key !== '') {
        delete_transient($state_key);
    }

    if (!is_array($state_data) || empty($state_data['user_id'])) {
        return new WP_REST_Response(array('error' => 'Estado OAuth invalido o caducado.'), 400);
    }

    $user = get_user_by('id', absint($state_data['user_id']));
    if (!$user || !user_can($user, 'manage_options')) {
        return new WP_REST_Response(array('error' => 'El usuario que inicio OAuth ya no tiene permisos.'), 403);
    }

    if ($oauth_error !== '' || $code === '') {
        wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'linkedin_oauth_failed')));
        exit;
    }

    $settings = seo_social_network_get_settings();
    $config = isset($settings['providers']['linkedin']) && is_array($settings['providers']['linkedin'])
        ? $settings['providers']['linkedin']
        : array();
    $client_id = isset($config['client_id']) ? trim((string) $config['client_id']) : '';
    $client_secret = seo_social_network_decrypt_secret(isset($config['client_secret_enc']) ? $config['client_secret_enc'] : '');

    $response = wp_remote_post(
        'https://www.linkedin.com/oauth/v2/accessToken',
        array(
            'timeout' => 20,
            'headers' => array('Accept' => 'application/json'),
            'body'    => array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => seo_social_linkedin_redirect_uri(),
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
            ),
        )
    );
    $token_data = seo_social_linkedin_decode_response($response);
    if (is_wp_error($token_data) || empty($token_data['access_token'])) {
        $detail = is_wp_error($token_data) ? $token_data->get_error_message() : 'LinkedIn no devolvio Access Token.';
        set_transient('seo_social_linkedin_oauth_error_' . absint($state_data['user_id']), $detail, 90);
        wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'linkedin_oauth_failed')));
        exit;
    }

    $access_enc = seo_social_network_encrypt_secret((string) $token_data['access_token']);
    if (is_wp_error($access_enc)) {
        return new WP_REST_Response(array('error' => $access_enc->get_error_message()), 500);
    }

    $config['access_token_enc'] = $access_enc;
    $config['token_expires_at'] = !empty($token_data['expires_in']) ? time() + absint($token_data['expires_in']) : 0;
    if (!empty($token_data['refresh_token'])) {
        $refresh_enc = seo_social_network_encrypt_secret((string) $token_data['refresh_token']);
        if (!is_wp_error($refresh_enc)) {
            $config['refresh_token_enc'] = $refresh_enc;
        }
    }
    if (!empty($token_data['refresh_token_expires_in'])) {
        $config['refresh_token_expires_at'] = time() + absint($token_data['refresh_token_expires_in']);
    }

    $organizations = seo_social_linkedin_discover_organizations((string) $token_data['access_token'], $config);
    if (is_wp_error($organizations)) {
        $organizations = array();
    }
    $config['organizations'] = $organizations;

    $selected_id = isset($config['organization_id']) ? preg_replace('/[^0-9]/', '', (string) $config['organization_id']) : '';
    $selected = null;
    foreach ($organizations as $organization) {
        if ($selected_id !== '' && (string) $organization['id'] === $selected_id) {
            $selected = $organization;
            break;
        }
    }
    if (!$selected && count($organizations) === 1) {
        $selected = reset($organizations);
    }
    if ($selected) {
        $config['organization_id'] = (string) $selected['id'];
        $config['organization_name'] = (string) $selected['name'];
        $config['organization_vanity'] = (string) $selected['vanity'];
        $config['organization_link'] = (string) $selected['link'];
        $config['page_name'] = (string) $selected['name'];
        $config['page_link'] = (string) $selected['link'];
    }
    $config['enabled'] = (!empty($config['organization_id']) && !empty($config['access_token_enc'])) ? 1 : 0;
    $config['last_test_at'] = '';
    $config['last_test_ok'] = 0;
    $config['last_test_error'] = '';

    $settings['providers']['linkedin'] = $config;
    seo_social_network_save_settings($settings);

    wp_safe_redirect(seo_social_network_admin_url('connections', array('social_msg' => 'linkedin_connected')));
    exit;
}

/**
 * Registra el callback OAuth de LinkedIn.
 */
function seo_social_linkedin_register_rest_route()
{
    register_rest_route(
        'seo-system/v1',
        '/linkedin/callback',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'seo_social_linkedin_oauth_callback',
            'permission_callback' => '__return_true',
        )
    );
}
add_action('rest_api_init', 'seo_social_linkedin_register_rest_route');

/**
 * @param array $config
 */
function seo_social_linkedin_render_connection($config)
{
    $config = is_array($config) ? $config : array();
    $has_secret = !empty($config['client_secret_enc']);
    $has_token = !empty($config['access_token_enc']);
    $organizations = isset($config['organizations']) && is_array($config['organizations']) ? $config['organizations'] : array();
    $selected_id = isset($config['organization_id']) ? (string) $config['organization_id'] : '';

    echo '<p class="seo-social-help"><strong>Redirect URL para LinkedIn Developer Portal:</strong><br><code>' . esc_html(seo_social_linkedin_redirect_uri()) . '</code></p>';
    echo '<p class="seo-social-help">La aplicacion de LinkedIn debe disponer de permisos para administrar/leer la organizacion y publicar como pagina. El plugin usa OAuth 2.0 y LinkedIn Posts API.</p>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_social_network_save_connection"><input type="hidden" name="provider" value="linkedin">';
    wp_nonce_field('seo_social_network_save_connection');

    echo '<div class="seo-social-grid">';
    echo '<div class="seo-social-field"><label>LinkedIn Client ID</label><input type="text" name="connection[client_id]" value="' . esc_attr(isset($config['client_id']) ? $config['client_id'] : '') . '" autocomplete="off"></div>';
    echo '<div class="seo-social-field"><label>LinkedIn Client Secret</label><input type="password" name="connection[client_secret]" value="" autocomplete="new-password" placeholder="' . esc_attr($has_secret ? 'Secret guardado; deja vacio para conservarlo' : 'Client Secret de la aplicacion') . '"></div>';
    echo '</div>';

    echo '<div class="seo-social-grid" style="margin-top:12px">';
    echo '<div class="seo-social-field"><label>Version LinkedIn API</label><input type="text" name="connection[api_version]" value="' . esc_attr(isset($config['api_version']) ? $config['api_version'] : SEO_SOCIAL_LINKEDIN_API_VERSION) . '" placeholder="202608"></div>';
    echo '<div class="seo-social-field"><label>Modo de publicacion</label><select name="connection[publish_mode]"><option value="article" ' . selected(isset($config['publish_mode']) ? $config['publish_mode'] : 'article', 'article', false) . '>Articulo con enlace y tarjeta</option><option value="text" ' . selected(isset($config['publish_mode']) ? $config['publish_mode'] : 'article', 'text', false) . '>Texto + enlace</option></select></div>';
    echo '</div>';

    echo '<div class="seo-social-field" style="margin-top:12px"><label>Pagina de empresa</label>';
    if (!empty($organizations)) {
        echo '<select name="connection[organization_id]"><option value="">Selecciona una pagina</option>';
        foreach ($organizations as $organization) {
            if (!is_array($organization) || empty($organization['id'])) {
                continue;
            }
            $label = !empty($organization['name']) ? $organization['name'] : 'Organizacion #' . $organization['id'];
            echo '<option value="' . esc_attr((string) $organization['id']) . '" ' . selected($selected_id, (string) $organization['id'], false) . '>' . esc_html($label . ' (#' . $organization['id'] . ')') . '</option>';
        }
        echo '</select>';
    } else {
        echo '<input type="text" inputmode="numeric" name="connection[organization_id]" value="' . esc_attr($selected_id) . '" placeholder="ID numerico de la pagina de empresa">';
    }
    echo '</div>';

    echo '<details style="margin-top:12px"><summary style="cursor:pointer">Token manual (opcional)</summary><div class="seo-social-field" style="margin-top:10px"><label>Access Token</label><input type="password" name="connection[access_token]" value="" autocomplete="new-password" placeholder="' . esc_attr($has_token ? 'Token guardado; deja vacio para conservarlo' : 'Solo si ya tienes un token de LinkedIn') . '"><p class="seo-social-help">Util para pruebas o si ya generaste el token fuera del plugin. El token se cifra antes de guardarlo.</p></div></details>';

    if (!empty($config['last_test_at'])) {
        $ok = !empty($config['last_test_ok']);
        echo '<p><span class="seo-social-state ' . ($ok ? 'is-ok' : 'is-failed') . '">' . esc_html($ok ? 'Ultima prueba correcta' : 'Ultima prueba fallida') . '</span> <small>' . esc_html($config['last_test_at']) . '</small></p>';
        if (!$ok && !empty($config['last_test_error'])) {
            echo '<p class="seo-social-help" style="color:#b32d2e">' . esc_html($config['last_test_error']) . '</p>';
        }
    }

    echo '<div class="seo-social-actions"><button type="submit" class="button">Guardar configuracion</button>';
    if ($has_token && $selected_id !== '') {
        echo '<button type="submit" class="button button-primary" name="test_after_save" value="1">Guardar y probar conexion</button>';
    }
    echo '</div></form>';

    if (!empty($config['client_id']) && $has_secret) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
        echo '<input type="hidden" name="action" value="seo_social_linkedin_oauth_start">';
        wp_nonce_field('seo_social_linkedin_oauth_start');
        echo '<button type="submit" class="button button-primary">Conectar / renovar autorizacion con LinkedIn</button>';
        echo '</form>';
    } else {
        echo '<p class="seo-social-help">Guarda primero el Client ID y el Client Secret. Despues aparecera el boton para autorizar LinkedIn.</p>';
    }

    if ($has_token || !empty($config['enabled'])) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
        echo '<input type="hidden" name="action" value="seo_social_network_disconnect"><input type="hidden" name="provider" value="linkedin">';
        wp_nonce_field('seo_social_network_disconnect');
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Se eliminaran los tokens guardados de LinkedIn. ¿Continuar?\');">Desconectar LinkedIn</button>';
        echo '</form>';
    }
}
