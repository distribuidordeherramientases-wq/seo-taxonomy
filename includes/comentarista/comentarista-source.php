<?php
/**
 * Comentarista - deteccion local de URLs conocidas.
 *
 * No realiza solicitudes HTTP. Solo interpreta la URL introducida para
 * identificar plataforma, ID y, cuando procede, construir una URL de embed.
 */

defined('ABSPATH') || exit;

/**
 * @param string $url
 * @return array{source_platform:string,external_id:?string,embed_url:?string,suggested_type:string}
 */
function seo_comentarista_detect_source($url)
{
    $result = array(
        'source_platform' => 'web',
        'external_id'     => null,
        'embed_url'       => null,
        'suggested_type'  => 'link',
    );

    $url = esc_url_raw(trim((string) $url), array('http', 'https'));
    if ($url === '') {
        return $result;
    }

    $parts = wp_parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    $query = array();
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }

    if (in_array($host, array('youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'), true)) {
        $video_id = '';
        if ($host === 'youtu.be') {
            $video_id = trim($path, '/');
        } elseif (!empty($query['v'])) {
            $video_id = sanitize_text_field((string) $query['v']);
        } elseif (preg_match('~/(?:shorts|embed)/([A-Za-z0-9_-]{6,})~', $path, $match)) {
            $video_id = $match[1];
        }

        $result['source_platform'] = 'youtube';
        $result['suggested_type'] = 'video';
        if ($video_id !== '') {
            $result['external_id'] = $video_id;
            $result['embed_url'] = 'https://www.youtube.com/embed/' . rawurlencode($video_id);
        }
        return $result;
    }

    if (preg_match('~(^|\.)tiktok\.com$~', $host)) {
        $result['source_platform'] = 'tiktok';
        $result['suggested_type'] = 'social_post';
        if (preg_match('~/video/(\d+)~', $path, $match)) {
            $result['external_id'] = $match[1];
            $result['embed_url'] = 'https://www.tiktok.com/player/v1/' . rawurlencode($match[1]);
        }
        return $result;
    }

    if (preg_match('~(^|\.)instagram\.com$~', $host)) {
        $result['source_platform'] = 'instagram';
        $result['suggested_type'] = 'social_post';
        if (preg_match('~/(p|reel|tv)/([^/]+)~', $path, $match)) {
            $result['external_id'] = $match[2];
            $result['embed_url'] = 'https://www.instagram.com/' . $match[1] . '/' . rawurlencode($match[2]) . '/embed/';
        }
        return $result;
    }

    return $result;
}

/**
 * Completa plataforma/ID/embed cuando se ha introducido una URL conocida.
 * Los valores introducidos expresamente por el administrador tienen prioridad.
 *
 * @param array $data
 * @return array
 */
function seo_comentarista_enrich_source_data($data)
{
    $data = is_array($data) ? $data : array();
    $url = trim((string) ($data['source_url'] ?? ''));
    if ($url === '') {
        return $data;
    }

    $detected = seo_comentarista_detect_source($url);

    if (empty($data['source_platform']) || $data['source_platform'] === 'web') {
        $data['source_platform'] = $detected['source_platform'];
    }
    if (empty($data['external_id']) && !empty($detected['external_id'])) {
        $data['external_id'] = $detected['external_id'];
    }
    if (empty($data['embed_url']) && !empty($detected['embed_url'])) {
        $data['embed_url'] = $detected['embed_url'];
    }
    if (empty($data['content_type']) && !empty($detected['suggested_type'])) {
        $data['content_type'] = $detected['suggested_type'];
    }

    return $data;
}
