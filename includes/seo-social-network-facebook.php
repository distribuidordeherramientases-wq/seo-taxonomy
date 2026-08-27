<?php
/**
 * SEO System - proveedor Facebook Pages para Social Network.
 */

defined('ABSPATH') || exit;

/**
 * @param array $providers
 * @return array
 */
function seo_social_facebook_register_provider($providers)
{
    $providers = is_array($providers) ? $providers : array();
    $providers['facebook'] = array(
        'label'                        => 'Facebook',
        'sanitize_connection_callback' => 'seo_social_facebook_sanitize_connection',
        'test_callback'                => 'seo_social_facebook_test_connection',
        'publish_callback'             => 'seo_social_facebook_publish',
        'sync_callback'                => 'seo_social_facebook_sync_publication',
        'render_connection_callback'   => 'seo_social_facebook_render_connection',
    );
    return $providers;
}
add_filter('seo_social_network_providers', 'seo_social_facebook_register_provider');

/**
 * @param array $posted
 * @param array $current
 * @return array|WP_Error
 */
function seo_social_facebook_sanitize_connection($posted, $current)
{
    $posted = is_array($posted) ? $posted : array();
    $current = is_array($current) ? $current : array();

    $page_id = isset($posted['page_id']) ? preg_replace('/[^0-9]/', '', (string) $posted['page_id']) : '';
    $api_version = isset($posted['api_version']) ? sanitize_text_field((string) $posted['api_version']) : 'v25.0';
    if (!preg_match('/^v\d+\.\d+$/', $api_version)) {
        $api_version = 'v25.0';
    }

    $publish_mode = isset($posted['publish_mode']) ? sanitize_key((string) $posted['publish_mode']) : 'link';
    if (!in_array($publish_mode, array('link', 'photo'), true)) {
        $publish_mode = 'link';
    }

    $encrypted = isset($current['access_token_enc']) ? (string) $current['access_token_enc'] : '';
    $new_token = isset($posted['access_token']) ? trim((string) $posted['access_token']) : '';
    if ($new_token !== '') {
        $encrypted = seo_social_network_encrypt_secret($new_token);
        if (is_wp_error($encrypted)) {
            return $encrypted;
        }
    }

    return array(
        'enabled'          => ($page_id !== '' && $encrypted !== '') ? 1 : 0,
        'page_id'          => $page_id,
        'page_name'        => isset($current['page_name']) ? sanitize_text_field((string) $current['page_name']) : '',
        'page_link'        => isset($current['page_link']) ? esc_url_raw((string) $current['page_link']) : '',
        'access_token_enc' => $encrypted,
        'api_version'      => $api_version,
        'publish_mode'     => $publish_mode,
        'last_test_at'     => isset($current['last_test_at']) ? sanitize_text_field((string) $current['last_test_at']) : '',
        'last_test_ok'     => !empty($current['last_test_ok']) ? 1 : 0,
        'last_test_error'  => isset($current['last_test_error']) ? sanitize_text_field((string) $current['last_test_error']) : '',
    );
}

/**
 * @param array  $config
 * @param string $path
 * @param array  $query
 * @return string
 */
function seo_social_facebook_graph_url($config, $path, $query = array())
{
    $version = isset($config['api_version']) && preg_match('/^v\d+\.\d+$/', $config['api_version'])
        ? $config['api_version']
        : 'v25.0';
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/' . ltrim($path, '/');
    return !empty($query) ? add_query_arg($query, $url) : $url;
}

/**
 * @param mixed $response
 * @return array|WP_Error
 */
function seo_social_facebook_decode_response($response)
{
    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode((string) $body, true);
    $data = is_array($data) ? $data : array();

    if ($code < 200 || $code >= 300) {
        $message = isset($data['error']['message']) ? (string) $data['error']['message'] : 'Facebook devolvio HTTP ' . $code . '.';
        $error_code = isset($data['error']['code']) ? ' (' . (int) $data['error']['code'] . ')' : '';
        return new WP_Error('facebook_api_error', $message . $error_code);
    }

    return $data;
}

/**
 * @param array $config
 * @return array|WP_Error
 */
function seo_social_facebook_test_connection($config)
{
    $page_id = isset($config['page_id']) ? preg_replace('/[^0-9]/', '', (string) $config['page_id']) : '';
    $token = seo_social_network_decrypt_secret(isset($config['access_token_enc']) ? $config['access_token_enc'] : '');

    if ($page_id === '' || $token === '') {
        return new WP_Error('facebook_missing_credentials', 'Faltan el Page ID o el Page Access Token.');
    }

    $url = seo_social_facebook_graph_url(
        $config,
        $page_id,
        array(
            'fields'       => 'id,name,link',
            'access_token' => $token,
        )
    );

    $data = seo_social_facebook_decode_response(
        wp_remote_get(
            $url,
            array(
                'timeout'     => 15,
                'redirection' => 3,
                'user-agent'  => 'SEO-System-Social/' . (defined('SEO_SYSTEM_VERSION') ? SEO_SYSTEM_VERSION : '1.0'),
            )
        )
    );

    if (is_wp_error($data)) {
        return $data;
    }

    if (empty($data['id']) || (string) $data['id'] !== (string) $page_id) {
        return new WP_Error('facebook_page_mismatch', 'El token responde, pero no corresponde con el Page ID configurado.');
    }

    return array(
        'page_name' => isset($data['name']) ? (string) $data['name'] : '',
        'page_link' => isset($data['link']) ? (string) $data['link'] : '',
    );
}

/**
 * @param array $payload
 * @return array|WP_Error
 */
function seo_social_facebook_publish($payload)
{
    $config = isset($payload['provider']) && is_array($payload['provider']) ? $payload['provider'] : array();
    $page_id = isset($config['page_id']) ? preg_replace('/[^0-9]/', '', (string) $config['page_id']) : '';
    $token = seo_social_network_decrypt_secret(isset($config['access_token_enc']) ? $config['access_token_enc'] : '');
    $message = isset($payload['message']) ? (string) $payload['message'] : '';
    $target_url = isset($payload['target_url']) ? esc_url_raw((string) $payload['target_url']) : '';
    $image_url = isset($payload['image_url']) ? esc_url_raw((string) $payload['image_url']) : '';

    if ($page_id === '' || $token === '') {
        return new WP_Error('facebook_missing_credentials', 'Facebook no esta conectado correctamente.');
    }

    $mode = isset($config['publish_mode']) ? sanitize_key($config['publish_mode']) : 'link';

    if ('photo' === $mode && $image_url !== '') {
        if ($target_url !== '' && strpos($message, $target_url) === false) {
            $message = trim($message . "\n\n" . $target_url);
        }

        $url = seo_social_facebook_graph_url($config, $page_id . '/photos');
        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 25,
                'body'    => array(
                    'url'          => $image_url,
                    'caption'      => $message,
                    'published'    => 'true',
                    'access_token' => $token,
                ),
            )
        );
    } else {
        $url = seo_social_facebook_graph_url($config, $page_id . '/feed');
        $body = array(
            'message'      => $message,
            'access_token' => $token,
        );
        if ($target_url !== '') {
            $body['link'] = $target_url;
        }
        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 25,
                'body'    => $body,
            )
        );
    }

    $data = seo_social_facebook_decode_response($response);
    if (is_wp_error($data)) {
        return $data;
    }

    $remote_id = '';
    if (!empty($data['post_id'])) {
        $remote_id = (string) $data['post_id'];
    } elseif (!empty($data['id'])) {
        $remote_id = (string) $data['id'];
    }

    if ($remote_id === '') {
        return new WP_Error('facebook_missing_remote_id', 'Facebook acepto la solicitud, pero no devolvio un identificador de publicacion.');
    }

    return array(
        'remote_id'  => $remote_id,
        'remote_url' => '',
    );
}

/**
 * @param object $publication
 * @param array  $config
 * @return array|WP_Error
 */
function seo_social_facebook_sync_publication($publication, $config)
{
    $remote_id = isset($publication->remote_id) ? sanitize_text_field((string) $publication->remote_id) : '';
    $token = seo_social_network_decrypt_secret(isset($config['access_token_enc']) ? $config['access_token_enc'] : '');

    if ($remote_id === '' || $token === '') {
        return new WP_Error('facebook_sync_missing_data', 'Falta el ID remoto o el token de Facebook.');
    }

    $fields = 'id,permalink_url,shares,reactions.limit(0).summary(true),comments.limit(0).summary(true)';
    $url = seo_social_facebook_graph_url(
        $config,
        $remote_id,
        array(
            'fields'       => $fields,
            'access_token' => $token,
        )
    );

    $data = seo_social_facebook_decode_response(
        wp_remote_get(
            $url,
            array(
                'timeout'     => 20,
                'redirection' => 3,
            )
        )
    );

    if (is_wp_error($data)) {
        return $data;
    }

    return array(
        'remote_url' => isset($data['permalink_url']) ? (string) $data['permalink_url'] : '',
        'shares'     => isset($data['shares']['count']) ? absint($data['shares']['count']) : 0,
        'reactions'  => isset($data['reactions']['summary']['total_count']) ? absint($data['reactions']['summary']['total_count']) : 0,
        'comments'   => isset($data['comments']['summary']['total_count']) ? absint($data['comments']['summary']['total_count']) : 0,
    );
}

/**
 * @param array $config
 */
function seo_social_facebook_render_connection($config)
{
    $has_token = !empty($config['access_token_enc']);
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_social_network_save_connection"><input type="hidden" name="provider" value="facebook">';
    wp_nonce_field('seo_social_network_save_connection');

    echo '<div class="seo-social-field"><label>Facebook Page ID</label><input type="text" inputmode="numeric" name="connection[page_id]" value="' . esc_attr(isset($config['page_id']) ? $config['page_id'] : '') . '" placeholder="1234567890"></div>';
    echo '<div class="seo-social-field" style="margin-top:12px"><label>Page Access Token</label><input type="password" autocomplete="new-password" name="connection[access_token]" value="" placeholder="' . esc_attr($has_token ? 'Token guardado; deja vacio para conservarlo' : 'Pega aqui el Page Access Token') . '"><p class="seo-social-help">Se cifra antes de guardarlo y nunca se vuelve a imprimir en la pagina.</p></div>';
    echo '<div class="seo-social-grid" style="margin-top:12px">';
    echo '<div class="seo-social-field"><label>Version Graph API</label><input type="text" name="connection[api_version]" value="' . esc_attr(isset($config['api_version']) ? $config['api_version'] : 'v25.0') . '"></div>';
    echo '<div class="seo-social-field"><label>Modo de publicacion</label><select name="connection[publish_mode]"><option value="link" ' . selected(isset($config['publish_mode']) ? $config['publish_mode'] : 'link', 'link', false) . '>Enlace con vista previa Open Graph</option><option value="photo" ' . selected(isset($config['publish_mode']) ? $config['publish_mode'] : 'link', 'photo', false) . '>Foto destacada + texto/enlace</option></select></div>';
    echo '</div>';

    echo '<p class="seo-social-help">Para publicar en una Pagina, el token debe pertenecer a esa Pagina y disponer de los permisos necesarios de Pages. El modo Enlace deja que Facebook componga la vista previa desde los metadatos Open Graph de tu URL; el modo Foto fuerza la imagen destacada cuando existe.</p>';

    if (!empty($config['last_test_at'])) {
        $ok = !empty($config['last_test_ok']);
        echo '<p><span class="seo-social-state ' . ($ok ? 'is-ok' : 'is-failed') . '">' . esc_html($ok ? 'Ultima prueba correcta' : 'Ultima prueba fallida') . '</span> <small>' . esc_html($config['last_test_at']) . '</small></p>';
        if (!$ok && !empty($config['last_test_error'])) {
            echo '<p class="seo-social-help" style="color:#b32d2e">' . esc_html($config['last_test_error']) . '</p>';
        }
    }

    echo '<div class="seo-social-actions"><button type="submit" class="button">Guardar</button><button type="submit" class="button button-primary" name="test_after_save" value="1">Guardar y probar conexion</button></div>';
    echo '</form>';

    if ($has_token || !empty($config['enabled'])) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
        echo '<input type="hidden" name="action" value="seo_social_network_disconnect"><input type="hidden" name="provider" value="facebook">';
        wp_nonce_field('seo_social_network_disconnect');
        echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'Se eliminara el token guardado de Facebook. ¿Continuar?\');">Desconectar Facebook</button>';
        echo '</form>';
    }
}
