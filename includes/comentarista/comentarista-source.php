<?php
/**
 * Comentarista - deteccion, normalizacion y lectura de fuentes externas.
 */

defined('ABSPATH') || exit;

/**
 * Normaliza una URL para deduplicacion sin alterar el destino visible.
 * Elimina fragmentos y parametros de tracking conocidos.
 *
 * @param string $url
 * @return string
 */
function seo_comentarista_normalize_url($url)
{
    $url = esc_url_raw(trim((string) $url));
    if ($url === '') {
        return '';
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }

    $scheme = !empty($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
    if (!in_array($scheme, array('http', 'https'), true)) {
        return '';
    }

    $host = strtolower($parts['host']);
    $port = isset($parts['port']) ? ':' . absint($parts['port']) : '';
    $path = isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';

    $query = array();
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        foreach (array_keys($query) as $key) {
            $lower = strtolower((string) $key);
            if (strpos($lower, 'utm_') === 0 || in_array($lower, array('fbclid', 'gclid', 'msclkid'), true)) {
                unset($query[$key]);
            }
        }
        ksort($query);
    }

    $normalized = $scheme . '://' . $host . $port . $path;
    if ($query) {
        $normalized .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return esc_url_raw($normalized);
}

/**
 * Detecta la plataforma por host.
 *
 * @param string $url
 * @return string
 */
function seo_comentarista_detect_platform($url)
{
    $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host);

    $matches_domain = static function ($candidate, $domain) {
        return $candidate === $domain
            || substr($candidate, -(strlen($domain) + 1)) === '.' . $domain;
    };

    if ($host === 'youtu.be' || $matches_domain($host, 'youtube.com') || $matches_domain($host, 'youtube-nocookie.com')) {
        return 'youtube';
    }

    if ($matches_domain($host, 'tiktok.com')) {
        return 'tiktok';
    }

    return 'web';
}

/**
 * Extrae un ID externo conocido de la URL.
 *
 * @param string $platform
 * @param string $url
 * @return string
 */
function seo_comentarista_extract_external_id($platform, $url)
{
    if ($platform === 'youtube') {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');

        if (strpos($host, 'youtu.be') !== false && $path !== '') {
            return sanitize_text_field(explode('/', $path)[0]);
        }

        $query = array();
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        if (!empty($query['v'])) {
            return sanitize_text_field((string) $query['v']);
        }

        if (preg_match('~(?:embed|shorts)/([A-Za-z0-9_-]{6,})~', $path, $matches)) {
            return sanitize_text_field($matches[1]);
        }
    }

    if ($platform === 'tiktok') {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if (preg_match('~/video/(\d+)~', $path, $matches)) {
            return sanitize_text_field($matches[1]);
        }
    }

    return '';
}

/**
 * Genera una URL de embed oficial cuando la plataforma lo permite sin API.
 *
 * @param string $platform
 * @param string $external_id
 * @return string
 */
function seo_comentarista_build_embed_url($platform, $external_id)
{
    $external_id = trim((string) $external_id);
    if ($external_id === '') {
        return '';
    }

    if ($platform === 'youtube' && preg_match('/^[A-Za-z0-9_-]{6,}$/', $external_id)) {
        return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($external_id);
    }

    if ($platform === 'tiktok' && ctype_digit($external_id)) {
        return 'https://www.tiktok.com/player/v1/' . rawurlencode($external_id) . '?description=1';
    }

    return '';
}

/**
 * Obtiene metadatos de TikTok mediante su endpoint oEmbed.
 *
 * @param string $url
 * @return array|WP_Error
 */
function seo_comentarista_probe_tiktok($url)
{
    $endpoint = add_query_arg('url', $url, 'https://www.tiktok.com/oembed');
    $response = wp_safe_remote_get(
        $endpoint,
        array(
            'timeout'             => 8,
            'redirection'         => 2,
            'limit_response_size' => 262144,
        )
    );

    if (is_wp_error($response)) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        return new WP_Error('seo_comentarista_tiktok_oembed', 'TikTok oEmbed ha respondido con HTTP ' . $code . '.');
    }

    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data)) {
        return new WP_Error('seo_comentarista_tiktok_json', 'Respuesta oEmbed de TikTok no valida.');
    }

    return array(
        'source_title'       => isset($data['title']) ? sanitize_text_field($data['title']) : '',
        'source_description' => isset($data['title']) ? sanitize_textarea_field($data['title']) : '',
        'source_name'        => 'TikTok',
        'author_name'        => isset($data['author_name']) ? sanitize_text_field($data['author_name']) : '',
        'author_url'         => isset($data['author_url']) ? esc_url_raw($data['author_url']) : '',
        'thumbnail_url'      => isset($data['thumbnail_url']) ? esc_url_raw($data['thumbnail_url']) : '',
        'http_status'        => $code,
        'source_status'      => 'active',
    );
}

/**
 * Extrae metadatos basicos de HTML sin intentar adivinar comentarios concretos.
 *
 * @param string $html
 * @return array
 */
function seo_comentarista_extract_html_metadata($html)
{
    $metadata = array(
        'source_title'       => '',
        'source_description' => '',
        'source_name'        => '',
        'author_name'        => '',
        'author_url'         => '',
        'thumbnail_url'      => '',
    );

    if (!is_string($html) || trim($html) === '') {
        return $metadata;
    }

    if (class_exists('DOMDocument')) {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);

        if ($loaded) {
            $titles = $dom->getElementsByTagName('title');
            if ($titles->length > 0) {
                $metadata['source_title'] = sanitize_text_field($titles->item(0)->textContent);
            }

            foreach ($dom->getElementsByTagName('meta') as $meta) {
                $property = strtolower(trim((string) $meta->getAttribute('property')));
                $name = strtolower(trim((string) $meta->getAttribute('name')));
                $content = trim((string) $meta->getAttribute('content'));
                if ($content === '') {
                    continue;
                }

                if (in_array($property, array('og:title', 'twitter:title'), true) || in_array($name, array('twitter:title'), true)) {
                    $metadata['source_title'] = sanitize_text_field($content);
                } elseif ($property === 'og:description' || in_array($name, array('description', 'twitter:description'), true)) {
                    $metadata['source_description'] = sanitize_textarea_field($content);
                } elseif ($property === 'og:site_name') {
                    $metadata['source_name'] = sanitize_text_field($content);
                } elseif (in_array($name, array('author', 'article:author'), true)) {
                    $metadata['author_name'] = sanitize_text_field($content);
                } elseif (in_array($property, array('og:image', 'twitter:image'), true) || in_array($name, array('twitter:image'), true)) {
                    $metadata['thumbnail_url'] = esc_url_raw($content);
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    if ($metadata['source_title'] === '' && preg_match('~<title[^>]*>(.*?)</title>~is', $html, $matches)) {
        $metadata['source_title'] = sanitize_text_field(wp_strip_all_tags(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8')));
    }

    return $metadata;
}

/**
 * Inspecciona una URL de forma segura desde servidor.
 * Para paginas genericas solo obtiene metadatos; el comentario concreto debe
 * indicarse manualmente o mediante un adaptador especifico futuro.
 *
 * @param string $url
 * @return array|WP_Error
 */
function seo_comentarista_probe_source($url)
{
    $normalized = seo_comentarista_normalize_url($url);
    if ($normalized === '') {
        return new WP_Error('seo_comentarista_invalid_url', 'La URL de la fuente no es valida.');
    }

    $platform = seo_comentarista_detect_platform($normalized);
    $external_id = seo_comentarista_extract_external_id($platform, $normalized);

    $base = array(
        'source_platform'     => $platform,
        'source_name'         => '',
        'source_title'        => '',
        'source_description'  => '',
        'author_name'         => '',
        'author_url'          => '',
        'thumbnail_url'       => '',
        'external_content_id' => $external_id,
        'embed_url'           => seo_comentarista_build_embed_url($platform, $external_id),
        'http_status'         => null,
        'source_status'       => 'unchecked',
    );

    if ($platform === 'tiktok') {
        $tiktok = seo_comentarista_probe_tiktok($normalized);
        if (!is_wp_error($tiktok)) {
            return array_merge($base, $tiktok);
        }
        // Si oEmbed falla, se intenta la URL normal antes de clasificarla como error.
    }

    $response = wp_safe_remote_get(
        $normalized,
        array(
            'timeout'             => 8,
            'redirection'         => 3,
            'limit_response_size' => 1048576,
            'headers'             => array(
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
            ),
            'user-agent'          => 'DistribuidorDeHerramientas-Comentarista/1.0; ' . home_url('/'),
        )
    );

    if (is_wp_error($response)) {
        $base['source_status'] = 'error';
        return array_merge($base, array('error_message' => $response->get_error_message()));
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $base['http_status'] = $code;

    if (in_array($code, array(404, 410), true)) {
        $base['source_status'] = 'unavailable';
        return $base;
    }

    if ($code >= 200 && $code < 400) {
        $base['source_status'] = 'active';
    } elseif (in_array($code, array(401, 403, 429), true)) {
        $base['source_status'] = 'blocked';
    } else {
        $base['source_status'] = 'error';
    }

    $metadata = seo_comentarista_extract_html_metadata((string) wp_remote_retrieve_body($response));
    $base = array_merge($base, $metadata);

    if ($base['source_name'] === '') {
        if ($platform === 'youtube') {
            $base['source_name'] = 'YouTube';
        } elseif ($platform === 'tiktok') {
            $base['source_name'] = 'TikTok';
        } else {
            $base['source_name'] = (string) wp_parse_url($normalized, PHP_URL_HOST);
        }
    }

    return $base;
}

/**
 * Comprueba solo disponibilidad, conservando la evidencia aunque la fuente falle.
 *
 * @param string $url
 * @return array
 */
function seo_comentarista_check_source_url($url)
{
    $normalized = seo_comentarista_normalize_url($url);
    if ($normalized === '') {
        return array('source_status' => 'error', 'http_status' => null);
    }

    $platform = seo_comentarista_detect_platform($normalized);
    if ($platform === 'tiktok') {
        $probe = seo_comentarista_probe_tiktok($normalized);
        if (!is_wp_error($probe)) {
            return array(
                'source_status' => 'active',
                'http_status'   => isset($probe['http_status']) ? (int) $probe['http_status'] : 200,
            );
        }
    }

    $response = wp_safe_remote_get(
        $normalized,
        array(
            'timeout'             => 6,
            'redirection'         => 3,
            'limit_response_size' => 32768,
            'headers'             => array('Accept' => 'text/html,*/*;q=0.8'),
            'user-agent'          => 'DistribuidorDeHerramientas-Comentarista/1.0; ' . home_url('/'),
        )
    );

    if (is_wp_error($response)) {
        return array('source_status' => 'error', 'http_status' => null);
    }

    $code = (int) wp_remote_retrieve_response_code($response);

    if (in_array($code, array(404, 410), true)) {
        $status = 'unavailable';
    } elseif ($code >= 200 && $code < 400) {
        $status = 'active';
    } elseif (in_array($code, array(401, 403, 429), true)) {
        $status = 'blocked';
    } else {
        $status = 'error';
    }

    return array(
        'source_status' => $status,
        'http_status'   => $code,
    );
}
