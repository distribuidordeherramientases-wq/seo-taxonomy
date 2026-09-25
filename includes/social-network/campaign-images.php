<?php
/**
 * SEO System - Creatividades sociales automáticas para campañas.
 *
 * Genera imágenes simples, ligeras y cacheables para publicaciones sociales
 * de campañas. Expone tamaños adaptados para Facebook, Instagram, LinkedIn,
 * Pinterest y X.
 */

defined('ABSPATH') || exit;

/**
 * @return array<string,array<string,mixed>>
 */
function seo_social_campaign_image_presets()
{
    return array(
        'design_1' => array(
            'label'      => 'Diseño 1 · Tarjeta oscura',
            'bg_top'     => '#18253f',
            'bg_bottom'  => '#314b87',
            'accent'     => '#f7b733',
            'accent_2'   => '#ffffff',
            'text'       => '#ffffff',
            'muted'      => '#d7deef',
            'price_box'  => '#ffffff',
            'price_text' => '#16233b',
            'badge_bg'   => '#f7b733',
            'badge_text' => '#16233b',
        ),
        'design_2' => array(
            'label'      => 'Diseño 2 · Oferta clara',
            'bg_top'     => '#f7f1e4',
            'bg_bottom'  => '#fffaf0',
            'accent'     => '#db4c3f',
            'accent_2'   => '#1b3a57',
            'text'       => '#1b2b3a',
            'muted'      => '#5f6b76',
            'price_box'  => '#1b3a57',
            'price_text' => '#ffffff',
            'badge_bg'   => '#db4c3f',
            'badge_text' => '#ffffff',
        ),
        'design_3' => array(
            'label'      => 'Diseño 3 · Banner intenso',
            'bg_top'     => '#0b3b2e',
            'bg_bottom'  => '#13795b',
            'accent'     => '#8ef0c2',
            'accent_2'   => '#f6fff8',
            'text'       => '#f6fff8',
            'muted'      => '#ccebdd',
            'price_box'  => '#f6fff8',
            'price_text' => '#0b3b2e',
            'badge_bg'   => '#8ef0c2',
            'badge_text' => '#0b3b2e',
        ),
    );
}

/**
 * @param string $provider
 * @return array{width:int,height:int}
 */
function seo_social_campaign_image_canvas($provider)
{
    $provider = sanitize_key($provider);
    switch ($provider) {
        case 'instagram':
            return array('width' => 1080, 'height' => 1350);
        case 'linkedin':
            return array('width' => 1200, 'height' => 627);
        case 'pinterest':
            return array('width' => 1000, 'height' => 1500);
        case 'x':
            return array('width' => 1200, 'height' => 675);
        case 'facebook':
        default:
            return array('width' => 1200, 'height' => 630);
    }
}

/**
 * @return array{regular:string,bold:string}
 */
function seo_social_campaign_image_fonts()
{
    $candidates = array(
        'regular' => array(
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
        ),
        'bold' => array(
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        ),
    );

    $fonts = array('regular' => '', 'bold' => '');
    foreach ($candidates as $weight => $paths) {
        foreach ($paths as $font_path) {
            if (is_readable($font_path)) {
                $fonts[$weight] = $font_path;
                break;
            }
        }
    }

    return (array) apply_filters('seo_social_campaign_image_fonts', $fonts);
}

/**
 * @param string $hex
 * @return array{0:int,1:int,2:int}
 */
function seo_social_campaign_image_hex_to_rgb($hex)
{
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return array(0, 0, 0);
    }
    return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
}

/**
 * @param resource|GdImage $image
 * @param string $hex
 * @param int    $alpha
 * @return int
 */
function seo_social_campaign_image_color($image, $hex, $alpha = 0)
{
    $rgb = seo_social_campaign_image_hex_to_rgb($hex);
    return imagecolorallocatealpha($image, $rgb[0], $rgb[1], $rgb[2], max(0, min(127, $alpha)));
}

/**
 * @param string $text
 * @param int    $limit
 * @return string
 */
function seo_social_campaign_image_trim($text, $limit = 120)
{
    $text = trim(wp_strip_all_tags((string) $text));
    if ($text === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($text) > $limit) {
        return rtrim(mb_substr($text, 0, max(1, $limit - 1))) . '…';
    }
    if (strlen($text) > $limit) {
        return rtrim(substr($text, 0, max(1, $limit - 1))) . '…';
    }
    return $text;
}

/**
 * @param string $text
 * @return string
 */
function seo_social_campaign_image_currency($text)
{
    $text = wp_strip_all_tags(html_entity_decode((string) $text, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8'));
    $text = trim(preg_replace('/\s+/', ' ', $text));
    return $text;
}

/**
 * @param string $text
 * @param int    $limit
 * @return array<int,string>
 */
function seo_social_campaign_image_wrap_text($text, $limit)
{
    $text = trim((string) $text);
    if ($text === '') {
        return array('');
    }

    $words = preg_split('/\s+/u', $text);
    $lines = array();
    $line = '';
    foreach ((array) $words as $word) {
        $candidate = trim($line === '' ? $word : $line . ' ' . $word);
        $length = function_exists('mb_strlen') ? mb_strlen($candidate) : strlen($candidate);
        if ($length <= $limit || $line === '') {
            $line = $candidate;
            continue;
        }
        $lines[] = $line;
        $line = $word;
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines ? $lines : array('');
}

/**
 * @param resource|\GdImage $image
 * @param string            $font_file
 * @param int               $font_size
 * @param int               $x
 * @param int               $y
 * @param int               $color
 * @param string            $text
 */
function seo_social_campaign_image_draw_text($image, $font_file, $font_size, $x, $y, $color, $text)
{
    if (is_readable($font_file) && function_exists('imagettftext')) {
        imagettftext($image, $font_size, 0, $x, $y, $color, $font_file, (string) $text);
        return;
    }

    imagestring($image, 5, $x, max(0, $y - 15), (string) $text, $color);
}

/**
 * @param resource|\GdImage $image
 * @param string            $font_file
 * @param int               $font_size
 * @param string            $text
 * @return array{width:int,height:int}
 */
function seo_social_campaign_image_measure_text($image, $font_file, $font_size, $text)
{
    if (is_readable($font_file) && function_exists('imagettfbbox')) {
        $box = imagettfbbox($font_size, 0, $font_file, (string) $text);
        if (is_array($box) && count($box) >= 8) {
            return array(
                'width'  => (int) abs($box[2] - $box[0]),
                'height' => (int) abs($box[7] - $box[1]),
            );
        }
    }
    return array('width' => imagefontwidth(5) * strlen((string) $text), 'height' => imagefontheight(5));
}

/**
 * @param resource|\GdImage $image
 * @param string            $font_file
 * @param string            $text
 * @param int               $max_width
 * @param int               $start_size
 * @param int               $min_size
 * @return int
 */
function seo_social_campaign_image_fit_font_size($image, $font_file, $text, $max_width, $start_size, $min_size = 14)
{
    $size = $start_size;
    while ($size > $min_size) {
        $measure = seo_social_campaign_image_measure_text($image, $font_file, $size, $text);
        if ($measure['width'] <= $max_width) {
            return $size;
        }
        $size -= 2;
    }
    return max($size, $min_size);
}

/**
 * @param resource|\GdImage $image
 * @param string            $font_file
 * @param int               $start_size
 * @param int               $x
 * @param int               $y
 * @param int               $line_height
 * @param int               $color
 * @param array<int,string> $lines
 * @return int
 */
function seo_social_campaign_image_draw_multiline($image, $font_file, $start_size, $x, $y, $line_height, $color, $lines)
{
    $current_y = $y;
    foreach ((array) $lines as $line) {
        $size = seo_social_campaign_image_fit_font_size($image, $font_file, (string) $line, 540, $start_size, max(12, $start_size - 10));
        seo_social_campaign_image_draw_text($image, $font_file, $size, $x, $current_y, $color, (string) $line);
        $current_y += $line_height;
    }
    return $current_y;
}

/**
 * @param resource|\GdImage $image
 * @param int               $width
 * @param int               $height
 * @param string            $top_hex
 * @param string            $bottom_hex
 */
function seo_social_campaign_image_fill_gradient($image, $width, $height, $top_hex, $bottom_hex)
{
    $top = seo_social_campaign_image_hex_to_rgb($top_hex);
    $bottom = seo_social_campaign_image_hex_to_rgb($bottom_hex);
    for ($y = 0; $y < $height; $y++) {
        $ratio = $height > 1 ? ($y / ($height - 1)) : 0;
        $r = (int) round($top[0] + (($bottom[0] - $top[0]) * $ratio));
        $g = (int) round($top[1] + (($bottom[1] - $top[1]) * $ratio));
        $b = (int) round($top[2] + (($bottom[2] - $top[2]) * $ratio));
        $color = imagecolorallocate($image, $r, $g, $b);
        imageline($image, 0, $y, $width, $y, $color);
    }
}

/**
 * @param object $campaign
 * @return string
 */
function seo_social_campaign_image_template_from_campaign($campaign)
{
    $selected = isset($campaign->social_creative_template) ? (string) $campaign->social_creative_template : 'design_1';
    $templates = seo_social_campaign_image_presets();
    return isset($templates[$selected]) ? $selected : 'design_1';
}

/**
 * @param string $provider
 * @return array{path:string,url:string}|WP_Error
 */
function seo_social_campaign_image_upload_base($provider)
{
    $provider = sanitize_key($provider);
    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) {
        return new WP_Error('upload_dir_unavailable', (string) $uploads['error']);
    }

    $subdir = 'seo-social-campaigns/' . ($provider !== '' ? $provider : 'facebook');
    $path = trailingslashit($uploads['basedir']) . $subdir;
    $url = trailingslashit($uploads['baseurl']) . $subdir;

    if (!wp_mkdir_p($path)) {
        return new WP_Error('upload_dir_create_failed', 'No se pudo crear la carpeta de creatividades sociales.');
    }

    return array('path' => $path, 'url' => $url);
}

/**
 * Genera o recupera de caché la creatividad de una publicación de campaña.
 *
 * @param object $campaign
 * @param array  $product_item
 * @param string $provider
 * @return array{url:string,path:string,generated:bool}|WP_Error
 */
function seo_social_campaign_image_generate($campaign, $product_item, $provider = 'facebook')
{
    if (!function_exists('imagecreatetruecolor')) {
        return new WP_Error('gd_missing', 'La extensión GD no está disponible.');
    }

    $provider = sanitize_key($provider);
    $product_id = isset($product_item['id']) ? absint($product_item['id']) : 0;
    $template_key = seo_social_campaign_image_template_from_campaign($campaign);
    $preset_map = seo_social_campaign_image_presets();
    $preset = $preset_map[$template_key];
    $canvas = seo_social_campaign_image_canvas($provider);
    $upload = seo_social_campaign_image_upload_base($provider);
    if (is_wp_error($upload)) {
        return $upload;
    }

    $hash = md5(wp_json_encode(array(
        'provider'      => $provider,
        'campaign_id'   => absint(isset($campaign->id) ? $campaign->id : 0),
        'product_id'    => $product_id,
        'template'      => $template_key,
        'campaign_name' => (string) ($campaign->name ?? ''),
        'edition'       => (string) ($campaign->edition_label ?? ''),
        'price'         => (string) ($product_item['campaign_price'] ?? ''),
        'regular'       => (string) ($product_item['regular_price'] ?? ''),
        'discount'      => (string) ($product_item['discount_percent'] ?? ''),
        'site'          => get_bloginfo('name'),
        'end'           => (string) ($campaign->end_at ?? ''),
    )));

    $filename = 'campaign-' . absint(isset($campaign->id) ? $campaign->id : 0) . '-product-' . $product_id . '-' . $template_key . '-' . $hash . '.png';
    $full_path = trailingslashit($upload['path']) . $filename;
    $full_url = trailingslashit($upload['url']) . $filename;

    if (file_exists($full_path)) {
        return array('url' => $full_url, 'path' => $full_path, 'generated' => false);
    }

    $image = imagecreatetruecolor($canvas['width'], $canvas['height']);
    if (!$image) {
        return new WP_Error('image_create_failed', 'No se pudo crear el lienzo de la creatividad.');
    }
    imagealphablending($image, true);
    imagesavealpha($image, false);

    seo_social_campaign_image_fill_gradient($image, $canvas['width'], $canvas['height'], $preset['bg_top'], $preset['bg_bottom']);

    $accent = seo_social_campaign_image_color($image, $preset['accent']);
    $accent2 = seo_social_campaign_image_color($image, $preset['accent_2'], 90);
    $text = seo_social_campaign_image_color($image, $preset['text']);
    $muted = seo_social_campaign_image_color($image, $preset['muted']);
    $price_box = seo_social_campaign_image_color($image, $preset['price_box']);
    $price_text = seo_social_campaign_image_color($image, $preset['price_text']);
    $badge_bg = seo_social_campaign_image_color($image, $preset['badge_bg']);
    $badge_text = seo_social_campaign_image_color($image, $preset['badge_text']);
    $shadow = imagecolorallocatealpha($image, 0, 0, 0, 95);

    $w = $canvas['width'];
    $h = $canvas['height'];
    imagefilledellipse($image, (int) ($w * 0.90), (int) ($h * 0.20), (int) ($w * 0.30), (int) ($h * 0.55), $accent2);
    imagefilledellipse($image, (int) ($w * 0.85), (int) ($h * 0.85), (int) ($w * 0.45), (int) ($h * 0.35), $accent2);
    imagefilledrectangle($image, 0, (int) ($h * 0.78), $w, $h, imagecolorallocatealpha($image, 255, 255, 255, 118));
    imagefilledpolygon($image, array((int) ($w * 0.72), 0, $w, 0, $w, (int) ($h * 0.25)), 3, imagecolorallocatealpha($image, 255, 255, 255, 118));

    $fonts = seo_social_campaign_image_fonts();
    $font_regular = $fonts['regular'];
    $font_bold = $fonts['bold'];

    // Etiqueta superior.
    imagefilledroundedrectangle($image = $image, 70, 52, 290, 102, $badge_bg, 16);
    seo_social_campaign_image_draw_text($image, $font_bold, 20, 92, 86, $badge_text, 'OFERTA ESPECIAL');

    // Título campaña.
    $campaign_name = seo_social_campaign_image_trim((string) ($campaign->name ?? 'Campaña'), 42);
    $campaign_lines = seo_social_campaign_image_wrap_text($campaign_name, 24);
    $y = 165;
    foreach ($campaign_lines as $line) {
        seo_social_campaign_image_draw_text($image, $font_bold, 36, 72, $y, $text, $line);
        $y += 44;
    }

    $edition = trim((string) ($campaign->edition_label ?? ''));
    if ($edition !== '') {
        seo_social_campaign_image_draw_text($image, $font_regular, 18, 74, $y + 4, $muted, 'Edición ' . $edition);
    }

    // Producto.
    $product_name = seo_social_campaign_image_trim((string) ($product_item['name'] ?? 'Producto destacado'), $provider === 'pinterest' ? 95 : 80);
    $product_lines = seo_social_campaign_image_wrap_text($product_name, $provider === 'pinterest' ? 26 : 28);
    $product_y = $provider === 'pinterest' ? 370 : 312;
    foreach ($product_lines as $line) {
        $size = $provider === 'pinterest' ? 42 : 34;
        seo_social_campaign_image_draw_text($image, $font_bold, $size, 72, $product_y, $text, $line);
        $product_y += $provider === 'pinterest' ? 52 : 44;
    }

    // Caja de precios.
    $box_x1 = 70;
    $box_y1 = $provider === 'pinterest' ? 620 : 420;
    $box_x2 = $provider === 'pinterest' ? 930 : 760;
    $box_y2 = $provider === 'pinterest' ? 830 : 555;
    imagefilledroundedrectangle($image = $image, $box_x1, $box_y1, $box_x2, $box_y2, $price_box, 24);

    $regular = seo_social_campaign_image_currency(seo_social_campaign_format_price(isset($product_item['regular_price']) ? $product_item['regular_price'] : 0));
    $offer = seo_social_campaign_image_currency(seo_social_campaign_format_price(isset($product_item['campaign_price']) ? $product_item['campaign_price'] : 0));
    $discount = isset($product_item['discount_percent']) ? max(0, absint($product_item['discount_percent'])) : 0;

    seo_social_campaign_image_draw_text($image, $font_regular, 20, $box_x1 + 34, $box_y1 + 48, $price_text, 'Antes');
    seo_social_campaign_image_draw_text($image, $font_bold, 24, $box_x1 + 34, $box_y1 + 86, $price_text, $regular);
    imageline($image, $box_x1 + 28, $box_y1 + 95, $box_x1 + 245, $box_y1 + 72, $accent);

    seo_social_campaign_image_draw_text($image, $font_regular, 20, $box_x1 + 330, $box_y1 + 48, $price_text, 'Ahora');
    seo_social_campaign_image_draw_text($image, $font_bold, $provider === 'pinterest' ? 48 : 44, $box_x1 + 330, $box_y1 + 98, $price_text, $offer);

    if ($discount > 0) {
        imagefilledroundedrectangle($image = $image, $box_x2 - 190, $box_y1 + 28, $box_x2 - 28, $box_y1 + 92, $badge_bg, 18);
        seo_social_campaign_image_draw_text($image, $font_bold, 28, $box_x2 - 164, $box_y1 + 69, $badge_text, '-' . $discount . '%');
    }

    // Fechas y sitio.
    $end_label = '';
    if (!empty($campaign->end_at) && function_exists('seo_marketing_campaigns_timestamp')) {
        $end_ts = seo_marketing_campaigns_timestamp($campaign->end_at);
        if ($end_ts > 0) {
            $end_label = wp_date(get_option('date_format'), $end_ts, wp_timezone());
        }
    }
    $site = seo_social_campaign_image_trim((string) get_bloginfo('name'), 40);
    $footer_y = $provider === 'pinterest' ? 950 : 605;
    seo_social_campaign_image_draw_text($image, $font_bold, 22, 74, $footer_y - 18, $text, $site);
    if ($end_label !== '') {
        seo_social_campaign_image_draw_text($image, $font_regular, 18, 74, $footer_y + 18, $muted, 'Oferta válida hasta ' . $end_label);
    }

    seo_social_campaign_image_draw_text($image, $font_regular, 18, (int) ($w - 330), $footer_y + 18, $muted, 'Distribuidor de herramientas');

    $saved = imagepng($image, $full_path, 8);
    imagedestroy($image);

    if (!$saved || !file_exists($full_path)) {
        return new WP_Error('image_save_failed', 'No se pudo guardar la creatividad generada.');
    }

    return array('url' => $full_url, 'path' => $full_path, 'generated' => true);
}

/**
 * @param object $campaign
 * @param array  $product_item
 * @param string $provider
 * @return string
 */
function seo_social_campaign_resolve_publication_image_url($campaign, $product_item, $provider = 'facebook')
{
    $generated = seo_social_campaign_image_generate($campaign, $product_item, $provider);
    if (!is_wp_error($generated) && !empty($generated['url'])) {
        return esc_url_raw((string) $generated['url']);
    }

    if (!empty($product_item['image_url'])) {
        return esc_url_raw((string) $product_item['image_url']);
    }

    $product_id = isset($product_item['id']) ? absint($product_item['id']) : 0;
    if ($product_id > 0) {
        $thumbnail = (string) get_the_post_thumbnail_url($product_id, 'full');
        if ($thumbnail !== '') {
            return esc_url_raw($thumbnail);
        }
    }

    return '';
}

/**
 * Polyfill ligero para rectángulos redondeados en GD.
 *
 * @param resource|\GdImage $image
 * @param int               $x1
 * @param int               $y1
 * @param int               $x2
 * @param int               $y2
 * @param int               $color
 * @param int               $radius
 * @return void
 */
if (!function_exists('imagefilledroundedrectangle')) {
    function imagefilledroundedrectangle($image, $x1, $y1, $x2, $y2, $color, $radius)
    {
        $radius = max(0, (int) $radius);
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }
}
