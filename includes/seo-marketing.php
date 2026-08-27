<?php
/**
 * SEO System - Marketing, sitemaps y estilo visual.
 *
 * La pestaña Estilo visual mantiene styles_template.css como hoja base y
 * publica únicamente tokens y reglas CSS validadas. La configuración se
 * guarda como JSON en wp_options mediante el Data Layer auditable.
 */

defined('ABSPATH') || exit;

/*
 * Servicio Google compartido.
 *
 * Se carga también en frontend para que la medición GA4 configurada desde
 * SEO System no dependa de que se abra antes Importar / Exportar.
 * require_once evita dobles cargas cuando legacy-engine.php ya lo incluyó.
 */
$seo_google_service = __DIR__ . '/import-export/suppliers/google-search.php';
if (is_readable($seo_google_service)) {
    require_once $seo_google_service;
}
unset($seo_google_service);

$seo_landing_module = __DIR__ . '/seo-landing-pages.php';
if (is_readable($seo_landing_module)) {
    require_once $seo_landing_module;
}
unset($seo_landing_module);

$seo_social_network_module = __DIR__ . '/seo-social-network.php';
if (is_readable($seo_social_network_module)) {
    require_once $seo_social_network_module;
}
unset($seo_social_network_module);

if (!defined('SEO_MARKETING_STYLE_OPTION')) {
    define('SEO_MARKETING_STYLE_OPTION', 'seo_marketing_style_settings_v1');
}

if (!defined('SEO_MARKETING_STYLE_SCHEMA_VERSION')) {
    define('SEO_MARKETING_STYLE_SCHEMA_VERSION', 5);
}

if (!defined('SEO_MARKETING_AUTO_CATEGORY_LIMIT')) {
    define('SEO_MARKETING_AUTO_CATEGORY_LIMIT', 4);
}

if (!defined('SEO_MARKETING_SITEMAP_BATCH_SIZE')) {
    define('SEO_MARKETING_SITEMAP_BATCH_SIZE', 1000);
}

if (!defined('SEO_MARKETING_SITEMAP_SCHEMA_VERSION')) {
    define('SEO_MARKETING_SITEMAP_SCHEMA_VERSION', 2);
}

if (!defined('SEO_MARKETING_SITEMAP_REWRITE_VERSION')) {
    define('SEO_MARKETING_SITEMAP_REWRITE_VERSION', 3);
}

if (!defined('SEO_MARKETING_SITEMAP_REGEN_DELAY')) {
    define('SEO_MARKETING_SITEMAP_REGEN_DELAY', 300);
}

if (!defined('SEO_MARKETING_IDENTITY_OPTION')) {
    define('SEO_MARKETING_IDENTITY_OPTION', 'seo_marketing_identity_settings_v1');
}

/**
 * Registra wp_options para que el estilo pueda guardarse y revertirse
 * mediante el Data Layer.
 *
 * @param array $tables
 * @return array
 */
function seo_marketing_register_data_layer_tables($tables)
{
    global $wpdb;

    if (!is_array($tables)) {
        $tables = array();
    }

    $tables['marketing_style_options'] = array(
        'table'       => $wpdb->options,
        'primary_key' => array('option_id'),
        'entity_type' => 'visual_style',
    );

    return $tables;
}
add_filter('seo_data_layer_tables', 'seo_marketing_register_data_layer_tables');

/**
 * Valores que reproducen el diseño actual de styles_template.css.
 * Guardar y restaurar estos valores no debe alterar el aspecto de partida.
 *
 * @return array
 */
function seo_marketing_style_defaults()
{
    return array(
        // Colores del sistema.
        'primary'                  => '#007acc',
        'primary_dark'             => '#005b96',
        'secondary'                => '#f0b400',
        'dark'                     => '#101820',
        'dark_soft'                => '#1c2d3d',
        'white'                    => '#ffffff',
        'background'               => '#f7f8fa',
        'background_light'         => '#fafbfc',
        'text'                     => '#222222',
        'text_soft'                => '#5b6570',
        'border'                   => '#e7ebef',
        'success'                  => '#1d6b43',
        'error'                    => '#c62828',
        'hero_title'               => '#ffffff',
        'hero_text'                => '#d7dde4',

        // Tipografía.
        'font_body'                => 'inherit',
        'font_headings'            => 'inherit',
        'body_size'                => 16,
        'body_line_height'         => 1.75,
        'paragraph_line_height'    => 1.85,
        'h1_min'                   => 36,
        'h1_max'                   => 58,
        'h1_weight'                => 800,
        'h1_color'                 => '#222222',
        'h2_min'                   => 28,
        'h2_max'                   => 40,
        'h2_weight'                => 700,
        'h2_color'                 => '#222222',
        'h3_size'                  => 24,
        'h3_weight'                => 700,
        'h3_color'                 => '#222222',
        'heading_align'            => 'left',
        'heading_transform'        => 'none',
        'custom_links'             => 0,
        'link_color'               => '#007acc',
        'link_hover'               => '#005b96',
        'link_decoration'          => 'underline',
        'custom_strong'            => 0,
        'strong_color'             => '#222222',

        // Geometría y componentes.
        'radius_small'             => 8,
        'radius'                   => 14,
        'radius_large'             => 20,
        'shadow_preset'            => 'standard',
        'container_width'          => 1400,
        'content_width'            => 1050,
        'section_spacing'          => 75,
        'button_height'            => 50,
        'button_padding_x'         => 26,
        'button_primary_bg'        => '#f0b400',
        'button_primary_text'      => '#111111',
        'button_blue_bg'           => '#007acc',
        'button_blue_hover'        => '#005b96',
        'button_blue_text'         => '#ffffff',
        'card_background'          => '#ffffff',
        'card_padding'             => 25,
        'card_image_height'        => 230,

        // Índice de Soluciones / parrilla de landings.
        'solutions_columns_desktop' => 3,
        'solutions_columns_tablet'  => 2,
        'solutions_columns_mobile'  => 1,
        'solutions_grid_gap'        => 24,
        'solutions_image_height'    => 220,
        'solutions_title_size'      => 22,

        // Productos WooCommerce.
        'products_columns_desktop' => 4,
        'products_columns_tablet'  => 3,
        'products_columns_small'   => 2,
        'products_columns_mobile'  => 1,
        'product_image_height'     => 230,
        'product_image_mobile'     => 190,
        // Título de producto en cuadrículas y listados.
        'product_title_size'       => 18,

        // Título principal de la ficha individual.
        'product_page_title_min'         => 24,
        'product_page_title_max'         => 34,
        'product_page_title_weight'      => 700,
        'product_page_title_line_height' => 1.18,

        'product_price_color'      => '#007acc',
        'product_button_bg'        => '#007acc',
        'product_button_hover'     => '#005b96',
        'product_button_text'      => '#ffffff',

        // Navegación principal.
        'menu_style'                => 'soft',
        'menu_background'           => '#ffffff',
        'menu_text'                 => '#222222',
        'menu_hover'                => '#007acc',
        'menu_active_text'          => '#005b96',
        'menu_active_background'    => '#edf6fc',
        'menu_dropdown_background'  => '#ffffff',
        'menu_dropdown_text'        => '#222222',
        'menu_dropdown_hover_bg'    => '#fafbfc',
        'menu_dropdown_hover_text'  => '#007acc',
        'menu_border'               => '#e7ebef',
        'menu_font_size'            => 14,
        'menu_font_weight'          => 700,
        'menu_height'               => 52,
        'menu_padding_x'            => 15,
        'menu_radius'               => 14,
        'menu_dropdown_min_width'   => 260,
        'menu_shadow_preset'        => 'standard',
        'menu_transform'            => 'none',
        'menu_animation'            => 'slide',
        'menu_indicator'            => 1,

        // Pie de pagina.
        'footer_background'        => '#101820',
        'footer_heading_color'     => '#ffffff',
        'footer_text_color'        => '#d7dde4',
        'footer_link_color'        => '#ffffff',
        'footer_link_hover'        => '#f0b400',
        'footer_meta_color'        => '#aeb8c2',
        'footer_border_color'      => '#33404c',
        'footer_padding_top'       => 42,
        'footer_padding_bottom'    => 28,
        'footer_gap'               => 28,
        'footer_heading_size'      => 16,
        'footer_text_size'         => 14,
        'footer_logo_width'        => 170,

        // FAQ.
        'faq_section_bg'           => '#fafbfc',
        'faq_item_bg'              => '#ffffff',
        'faq_question_color'       => '#222222',
        'faq_answer_color'         => '#5b6570',
        'faq_border_color'         => '#e7ebef',
        'faq_icon_color'           => '#007acc',
        'faq_radius'               => 14,
    );
}

/**
 * Fuentes permitidas. Se guardan claves, nunca CSS libre.
 *
 * @return array
 */
function seo_marketing_style_font_choices()
{
    return array(
        'inherit'   => array('label' => 'Fuente heredada del tema', 'stack' => 'inherit'),
        'system'    => array('label' => 'Sistema moderna', 'stack' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif'),
        'arial'     => array('label' => 'Arial', 'stack' => 'Arial, Helvetica, sans-serif'),
        'verdana'   => array('label' => 'Verdana', 'stack' => 'Verdana, Geneva, sans-serif'),
        'trebuchet' => array('label' => 'Trebuchet', 'stack' => '"Trebuchet MS", Helvetica, sans-serif'),
        'georgia'   => array('label' => 'Georgia', 'stack' => 'Georgia, "Times New Roman", serif'),
        'times'     => array('label' => 'Times New Roman', 'stack' => '"Times New Roman", Times, serif'),
        'mono'      => array('label' => 'Monoespaciada', 'stack' => 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace'),
    );
}

/**
 * @param string $key
 * @return string
 */
function seo_marketing_style_font_stack($key)
{
    $choices = seo_marketing_style_font_choices();
    return isset($choices[$key]['stack']) ? $choices[$key]['stack'] : $choices['inherit']['stack'];
}

/**
 * Sombras predeterminadas.
 *
 * @return array
 */
function seo_marketing_style_shadow_choices()
{
    return array(
        'none' => array(
            'label' => 'Sin sombra',
            'small' => 'none',
            'normal' => 'none',
            'large' => 'none',
        ),
        'soft' => array(
            'label' => 'Suave',
            'small' => '0 5px 16px rgba(0, 0, 0, .04)',
            'normal' => '0 10px 28px rgba(0, 0, 0, .07)',
            'large' => '0 18px 50px rgba(0, 0, 0, .11)',
        ),
        'standard' => array(
            'label' => 'Estándar actual',
            'small' => '0 8px 24px rgba(0, 0, 0, .05)',
            'normal' => '0 14px 35px rgba(0, 0, 0, .08)',
            'large' => '0 24px 65px rgba(0, 0, 0, .14)',
        ),
        'strong' => array(
            'label' => 'Marcada',
            'small' => '0 9px 26px rgba(0, 0, 0, .10)',
            'normal' => '0 17px 42px rgba(0, 0, 0, .15)',
            'large' => '0 28px 72px rgba(0, 0, 0, .22)',
        ),
    );
}

/**
 * Limita un valor numérico.
 *
 * @param mixed $value
 * @param float $minimum
 * @param float $maximum
 * @param float $fallback
 * @return float|int
 */
function seo_marketing_style_clamp($value, $minimum, $maximum, $fallback)
{
    if (!is_numeric($value)) {
        return $fallback;
    }

    $value = (float) $value;
    $value = max((float) $minimum, min((float) $maximum, $value));

    if (floor($value) === $value) {
        return (int) $value;
    }

    return round($value, 2);
}

/**
 * @param mixed $value
 * @param string $fallback
 * @return string
 */
function seo_marketing_style_color($value, $fallback)
{
    $color = sanitize_hex_color((string) $value);
    return $color ? $color : $fallback;
}

/**
 * Sanea toda la configuración con una lista blanca estricta.
 *
 * @param array $input
 * @return array
 */
function seo_marketing_style_sanitize_settings($input)
{
    $defaults = seo_marketing_style_defaults();
    $input = is_array($input) ? $input : array();
    $settings = $defaults;

    $color_keys = array(
        'primary', 'primary_dark', 'secondary', 'dark', 'dark_soft', 'white',
        'background', 'background_light', 'text', 'text_soft', 'border',
        'success', 'error', 'hero_title', 'hero_text', 'h1_color', 'h2_color',
        'h3_color', 'link_color', 'link_hover', 'strong_color',
        'button_primary_bg', 'button_primary_text', 'button_blue_bg',
        'button_blue_hover', 'button_blue_text', 'card_background',
        'product_price_color', 'product_button_bg', 'product_button_hover',
        'product_button_text', 'faq_section_bg', 'faq_item_bg',
        'faq_question_color', 'faq_answer_color', 'faq_border_color',
        'faq_icon_color', 'menu_background', 'menu_text', 'menu_hover',
        'menu_active_text', 'menu_active_background',
        'menu_dropdown_background', 'menu_dropdown_text',
        'menu_dropdown_hover_bg', 'menu_dropdown_hover_text', 'menu_border',
        'footer_background', 'footer_heading_color', 'footer_text_color',
        'footer_link_color', 'footer_link_hover', 'footer_meta_color',
        'footer_border_color',
    );

    foreach ($color_keys as $key) {
        $settings[$key] = seo_marketing_style_color(
            isset($input[$key]) ? $input[$key] : $defaults[$key],
            $defaults[$key]
        );
    }

    $fonts = seo_marketing_style_font_choices();
    foreach (array('font_body', 'font_headings') as $key) {
        $candidate = isset($input[$key]) ? sanitize_key($input[$key]) : $defaults[$key];
        $settings[$key] = isset($fonts[$candidate]) ? $candidate : $defaults[$key];
    }

    $settings['body_size']             = seo_marketing_style_clamp(isset($input['body_size']) ? $input['body_size'] : null, 13, 22, $defaults['body_size']);
    $settings['body_line_height']      = seo_marketing_style_clamp(isset($input['body_line_height']) ? $input['body_line_height'] : null, 1.2, 2.2, $defaults['body_line_height']);
    $settings['paragraph_line_height'] = seo_marketing_style_clamp(isset($input['paragraph_line_height']) ? $input['paragraph_line_height'] : null, 1.2, 2.4, $defaults['paragraph_line_height']);
    $settings['h1_min']                = seo_marketing_style_clamp(isset($input['h1_min']) ? $input['h1_min'] : null, 24, 64, $defaults['h1_min']);
    $settings['h1_max']                = seo_marketing_style_clamp(isset($input['h1_max']) ? $input['h1_max'] : null, 30, 90, $defaults['h1_max']);
    $settings['h1_weight']             = seo_marketing_style_clamp(isset($input['h1_weight']) ? $input['h1_weight'] : null, 400, 900, $defaults['h1_weight']);
    $settings['h2_min']                = seo_marketing_style_clamp(isset($input['h2_min']) ? $input['h2_min'] : null, 20, 52, $defaults['h2_min']);
    $settings['h2_max']                = seo_marketing_style_clamp(isset($input['h2_max']) ? $input['h2_max'] : null, 24, 72, $defaults['h2_max']);
    $settings['h2_weight']             = seo_marketing_style_clamp(isset($input['h2_weight']) ? $input['h2_weight'] : null, 400, 900, $defaults['h2_weight']);
    $settings['h3_size']               = seo_marketing_style_clamp(isset($input['h3_size']) ? $input['h3_size'] : null, 16, 40, $defaults['h3_size']);
    $settings['h3_weight']             = seo_marketing_style_clamp(isset($input['h3_weight']) ? $input['h3_weight'] : null, 400, 900, $defaults['h3_weight']);

    if ($settings['h1_max'] < $settings['h1_min']) {
        $settings['h1_max'] = $settings['h1_min'];
    }
    if ($settings['h2_max'] < $settings['h2_min']) {
        $settings['h2_max'] = $settings['h2_min'];
    }

    $alignments = array('left', 'center');
    $transforms = array('none', 'uppercase');
    $decorations = array('none', 'underline');

    $settings['heading_align'] = isset($input['heading_align']) && in_array($input['heading_align'], $alignments, true)
        ? $input['heading_align']
        : $defaults['heading_align'];
    $settings['heading_transform'] = isset($input['heading_transform']) && in_array($input['heading_transform'], $transforms, true)
        ? $input['heading_transform']
        : $defaults['heading_transform'];
    $settings['link_decoration'] = isset($input['link_decoration']) && in_array($input['link_decoration'], $decorations, true)
        ? $input['link_decoration']
        : $defaults['link_decoration'];

    $settings['custom_links']  = !empty($input['custom_links']) ? 1 : 0;
    $settings['custom_strong'] = !empty($input['custom_strong']) ? 1 : 0;

    $settings['radius_small']      = seo_marketing_style_clamp(isset($input['radius_small']) ? $input['radius_small'] : null, 0, 40, $defaults['radius_small']);
    $settings['radius']            = seo_marketing_style_clamp(isset($input['radius']) ? $input['radius'] : null, 0, 50, $defaults['radius']);
    $settings['radius_large']      = seo_marketing_style_clamp(isset($input['radius_large']) ? $input['radius_large'] : null, 0, 70, $defaults['radius_large']);
    $settings['container_width']   = seo_marketing_style_clamp(isset($input['container_width']) ? $input['container_width'] : null, 960, 1800, $defaults['container_width']);
    $settings['content_width']     = seo_marketing_style_clamp(isset($input['content_width']) ? $input['content_width'] : null, 640, 1400, $defaults['content_width']);
    $settings['section_spacing']   = seo_marketing_style_clamp(isset($input['section_spacing']) ? $input['section_spacing'] : null, 30, 130, $defaults['section_spacing']);
    $settings['button_height']     = seo_marketing_style_clamp(isset($input['button_height']) ? $input['button_height'] : null, 34, 76, $defaults['button_height']);
    $settings['button_padding_x']  = seo_marketing_style_clamp(isset($input['button_padding_x']) ? $input['button_padding_x'] : null, 10, 52, $defaults['button_padding_x']);
    $settings['card_padding']      = seo_marketing_style_clamp(isset($input['card_padding']) ? $input['card_padding'] : null, 10, 60, $defaults['card_padding']);
    $settings['card_image_height'] = seo_marketing_style_clamp(isset($input['card_image_height']) ? $input['card_image_height'] : null, 120, 420, $defaults['card_image_height']);

    $settings['solutions_columns_desktop'] = seo_marketing_style_clamp(isset($input['solutions_columns_desktop']) ? $input['solutions_columns_desktop'] : null, 1, 5, $defaults['solutions_columns_desktop']);
    $settings['solutions_columns_tablet']  = seo_marketing_style_clamp(isset($input['solutions_columns_tablet']) ? $input['solutions_columns_tablet'] : null, 1, 4, $defaults['solutions_columns_tablet']);
    $settings['solutions_columns_mobile']  = seo_marketing_style_clamp(isset($input['solutions_columns_mobile']) ? $input['solutions_columns_mobile'] : null, 1, 2, $defaults['solutions_columns_mobile']);
    $settings['solutions_grid_gap']        = seo_marketing_style_clamp(isset($input['solutions_grid_gap']) ? $input['solutions_grid_gap'] : null, 8, 60, $defaults['solutions_grid_gap']);
    $settings['solutions_image_height']    = seo_marketing_style_clamp(isset($input['solutions_image_height']) ? $input['solutions_image_height'] : null, 100, 420, $defaults['solutions_image_height']);
    $settings['solutions_title_size']      = seo_marketing_style_clamp(isset($input['solutions_title_size']) ? $input['solutions_title_size'] : null, 14, 36, $defaults['solutions_title_size']);

    $shadow_choices = seo_marketing_style_shadow_choices();
    $shadow = isset($input['shadow_preset']) ? sanitize_key($input['shadow_preset']) : $defaults['shadow_preset'];
    $settings['shadow_preset'] = isset($shadow_choices[$shadow]) ? $shadow : $defaults['shadow_preset'];

    $settings['products_columns_desktop'] = seo_marketing_style_clamp(isset($input['products_columns_desktop']) ? $input['products_columns_desktop'] : null, 1, 6, $defaults['products_columns_desktop']);
    $settings['products_columns_tablet']  = seo_marketing_style_clamp(isset($input['products_columns_tablet']) ? $input['products_columns_tablet'] : null, 1, 5, $defaults['products_columns_tablet']);
    $settings['products_columns_small']   = seo_marketing_style_clamp(isset($input['products_columns_small']) ? $input['products_columns_small'] : null, 1, 4, $defaults['products_columns_small']);
    $settings['products_columns_mobile']  = seo_marketing_style_clamp(isset($input['products_columns_mobile']) ? $input['products_columns_mobile'] : null, 1, 2, $defaults['products_columns_mobile']);
    $settings['product_image_height']     = seo_marketing_style_clamp(isset($input['product_image_height']) ? $input['product_image_height'] : null, 120, 420, $defaults['product_image_height']);
    $settings['product_image_mobile']     = seo_marketing_style_clamp(isset($input['product_image_mobile']) ? $input['product_image_mobile'] : null, 100, 320, $defaults['product_image_mobile']);
    $settings['product_title_size']             = seo_marketing_style_clamp(isset($input['product_title_size']) ? $input['product_title_size'] : null, 13, 28, $defaults['product_title_size']);
    $settings['product_page_title_min']          = seo_marketing_style_clamp(isset($input['product_page_title_min']) ? $input['product_page_title_min'] : null, 18, 48, $defaults['product_page_title_min']);
    $settings['product_page_title_max']          = seo_marketing_style_clamp(isset($input['product_page_title_max']) ? $input['product_page_title_max'] : null, 22, 64, $defaults['product_page_title_max']);
    $settings['product_page_title_weight']       = seo_marketing_style_clamp(isset($input['product_page_title_weight']) ? $input['product_page_title_weight'] : null, 400, 900, $defaults['product_page_title_weight']);
    $settings['product_page_title_line_height']  = seo_marketing_style_clamp(isset($input['product_page_title_line_height']) ? $input['product_page_title_line_height'] : null, 1, 1.8, $defaults['product_page_title_line_height']);

    if ($settings['product_page_title_max'] < $settings['product_page_title_min']) {
        $settings['product_page_title_max'] = $settings['product_page_title_min'];
    }

    $settings['footer_padding_top']    = seo_marketing_style_clamp(isset($input['footer_padding_top']) ? $input['footer_padding_top'] : null, 16, 120, $defaults['footer_padding_top']);
    $settings['footer_padding_bottom'] = seo_marketing_style_clamp(isset($input['footer_padding_bottom']) ? $input['footer_padding_bottom'] : null, 12, 100, $defaults['footer_padding_bottom']);
    $settings['footer_gap']            = seo_marketing_style_clamp(isset($input['footer_gap']) ? $input['footer_gap'] : null, 8, 72, $defaults['footer_gap']);
    $settings['footer_heading_size']   = seo_marketing_style_clamp(isset($input['footer_heading_size']) ? $input['footer_heading_size'] : null, 12, 28, $defaults['footer_heading_size']);
    $settings['footer_text_size']      = seo_marketing_style_clamp(isset($input['footer_text_size']) ? $input['footer_text_size'] : null, 11, 22, $defaults['footer_text_size']);
    $settings['footer_logo_width']     = seo_marketing_style_clamp(isset($input['footer_logo_width']) ? $input['footer_logo_width'] : null, 90, 300, $defaults['footer_logo_width']);

    $settings['faq_radius'] = seo_marketing_style_clamp(isset($input['faq_radius']) ? $input['faq_radius'] : null, 0, 50, $defaults['faq_radius']);

    $menu_styles = array('soft', 'pills', 'underline', 'minimal');
    $menu_transforms = array('none', 'uppercase');
    $menu_animations = array('none', 'fade', 'slide');

    $menu_style = isset($input['menu_style']) ? sanitize_key($input['menu_style']) : $defaults['menu_style'];
    $settings['menu_style'] = in_array($menu_style, $menu_styles, true) ? $menu_style : $defaults['menu_style'];

    $menu_transform = isset($input['menu_transform']) ? sanitize_key($input['menu_transform']) : $defaults['menu_transform'];
    $settings['menu_transform'] = in_array($menu_transform, $menu_transforms, true) ? $menu_transform : $defaults['menu_transform'];

    $menu_animation = isset($input['menu_animation']) ? sanitize_key($input['menu_animation']) : $defaults['menu_animation'];
    $settings['menu_animation'] = in_array($menu_animation, $menu_animations, true) ? $menu_animation : $defaults['menu_animation'];

    $menu_shadow = isset($input['menu_shadow_preset']) ? sanitize_key($input['menu_shadow_preset']) : $defaults['menu_shadow_preset'];
    $settings['menu_shadow_preset'] = isset($shadow_choices[$menu_shadow]) ? $menu_shadow : $defaults['menu_shadow_preset'];

    $settings['menu_font_size']          = seo_marketing_style_clamp(isset($input['menu_font_size']) ? $input['menu_font_size'] : null, 11, 22, $defaults['menu_font_size']);
    $settings['menu_font_weight']        = seo_marketing_style_clamp(isset($input['menu_font_weight']) ? $input['menu_font_weight'] : null, 400, 900, $defaults['menu_font_weight']);
    $settings['menu_height']             = seo_marketing_style_clamp(isset($input['menu_height']) ? $input['menu_height'] : null, 38, 82, $defaults['menu_height']);
    $settings['menu_padding_x']          = seo_marketing_style_clamp(isset($input['menu_padding_x']) ? $input['menu_padding_x'] : null, 6, 36, $defaults['menu_padding_x']);
    $settings['menu_radius']             = seo_marketing_style_clamp(isset($input['menu_radius']) ? $input['menu_radius'] : null, 0, 40, $defaults['menu_radius']);
    $settings['menu_dropdown_min_width'] = seo_marketing_style_clamp(isset($input['menu_dropdown_min_width']) ? $input['menu_dropdown_min_width'] : null, 180, 520, $defaults['menu_dropdown_min_width']);
    $settings['menu_indicator']          = array_key_exists('menu_indicator', $input) ? (!empty($input['menu_indicator']) ? 1 : 0) : $defaults['menu_indicator'];

    return $settings;
}

/**
 * Lee directamente la opción JSON para no depender de cachés persistentes.
 *
 * @return array{exists:bool,option_id:int,payload:array,settings:array}
 */
function seo_marketing_style_get_record()
{
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            SEO_MARKETING_STYLE_OPTION
        ),
        ARRAY_A
    );

    $payload = array();
    $values = array();

    if (is_array($row) && isset($row['option_value'])) {
        $decoded = json_decode((string) $row['option_value'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
            if (isset($decoded['values']) && is_array($decoded['values'])) {
                $values = $decoded['values'];
            } else {
                $values = $decoded;
            }
        }
    }

    $cache = array(
        'exists'    => is_array($row),
        'option_id' => is_array($row) ? (int) $row['option_id'] : 0,
        'payload'   => $payload,
        'settings'  => seo_marketing_style_sanitize_settings($values),
    );

    return $cache;
}

/**
 * @return array
 */
function seo_marketing_style_get_settings()
{
    $record = seo_marketing_style_get_record();
    return $record['settings'];
}

/**
 * Borra las cachés de opciones después de una escritura auditable.
 */
function seo_marketing_style_clear_option_cache()
{
    wp_cache_delete(SEO_MARKETING_STYLE_OPTION, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
}

/**
 * Crea o actualiza la opción mediante el Data Layer.
 *
 * @param array  $settings
 * @param string $operation_type
 * @param string $operation_label
 * @param array  $extra_metadata
 * @return int ID de operación, 0 si no había cambios.
 */
function seo_marketing_style_persist($settings, $operation_type, $operation_label, $extra_metadata = array())
{
    global $wpdb;

    if (!class_exists('SEO_Data_Layer')) {
        throw new RuntimeException('El Data Layer no está disponible. No se ha guardado el estilo.');
    }

    $settings = seo_marketing_style_sanitize_settings($settings);
    $current = seo_marketing_style_get_record();

    if ($current['exists'] && $current['settings'] === $settings) {
        return 0;
    }

    $changed_keys = array();
    foreach ($settings as $key => $value) {
        $old_value = isset($current['settings'][$key]) ? $current['settings'][$key] : null;
        if ($old_value !== $value) {
            $changed_keys[] = $key;
        }
    }

    $payload = array(
        'schema_version' => SEO_MARKETING_STYLE_SCHEMA_VERSION,
        'values'         => $settings,
        'updated_at_utc' => current_time('mysql', true),
        'updated_by'     => get_current_user_id(),
    );

    $json = wp_json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if (!is_string($json) || $json === '') {
        throw new RuntimeException('No se pudo generar el JSON del estilo visual.');
    }

    $metadata = array_merge(
        array(
            'option_name'  => SEO_MARKETING_STYLE_OPTION,
            'schema_version' => SEO_MARKETING_STYLE_SCHEMA_VERSION,
            'changed_keys' => $changed_keys,
            'changed_count'=> count($changed_keys),
        ),
        is_array($extra_metadata) ? $extra_metadata : array()
    );

    $data_operation = SEO_Data_Layer::operation(array(
        'type'          => sanitize_key($operation_type),
        'label'         => sanitize_text_field($operation_label),
        'source_module' => 'seo_marketing',
        'rollbackable'  => true,
        'risk_level'    => 'medium',
        'audit_level'   => 'full',
        'metadata'      => $metadata,
    ));

    $data_operation->mark_validated(array(
        'validated_by'   => get_current_user_id(),
        'validated_from' => 'visual_style',
    ));
    $data_operation->mark_previewed(1, array(
        'preview_entity' => SEO_MARKETING_STYLE_OPTION,
    ));

    $option_id = (int) $current['option_id'];

    $data_operation->execute(function ($operation) use ($option_id, $json) {
        if ($option_id > 0) {
            $operation->update(
                'marketing_style_options',
                array('option_id' => $option_id),
                array('option_value' => $json),
                array('related_object_type' => 'wordpress_option')
            );
        } else {
            $operation->insert(
                'marketing_style_options',
                array(
                    'option_name'  => SEO_MARKETING_STYLE_OPTION,
                    'option_value' => $json,
                    'autoload'     => 'no',
                ),
                array('related_object_type' => 'wordpress_option')
            );
        }
    });

    seo_marketing_style_clear_option_cache();

    return $data_operation->id();
}

/**
 * URL de la pestaña de Estilo visual.
 *
 * @param array $args
 * @return string
 */
function seo_marketing_style_admin_url($args = array())
{
    $base = array(
        'page' => 'seo-menu-marketing',
        'tab'  => 'style',
    );

    return add_query_arg(array_merge($base, is_array($args) ? $args : array()), admin_url('admin.php'));
}

/**
 * Guarda la configuración publicada.
 */
function seo_marketing_style_handle_save()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para modificar el estilo visual.', 'seo-system'));
    }

    check_admin_referer('seo_marketing_style_save');

    $posted = isset($_POST['settings']) && is_array($_POST['settings'])
        ? wp_unslash($_POST['settings'])
        : array();

    try {
        $operation_id = seo_marketing_style_persist(
            $posted,
            'update_visual_style',
            'Actualizar estilo visual',
            array('action' => 'save')
        );

        $args = array('style_msg' => $operation_id > 0 ? 'saved' : 'unchanged');
        if ($operation_id > 0) {
            $args['operation_id'] = $operation_id;
        }
    } catch (Throwable $exception) {
        error_log('[SEO Marketing] Error al guardar el estilo: ' . $exception->getMessage());
        $args = array('style_msg' => 'error');
    }

    wp_safe_redirect(seo_marketing_style_admin_url($args));
    exit;
}
add_action('admin_post_seo_marketing_style_save', 'seo_marketing_style_handle_save');

/**
 * Restaura el perfil base mediante una operación reversible.
 */
function seo_marketing_style_handle_reset()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para restaurar el estilo visual.', 'seo-system'));
    }

    check_admin_referer('seo_marketing_style_reset');

    try {
        $operation_id = seo_marketing_style_persist(
            seo_marketing_style_defaults(),
            'reset_visual_style',
            'Restaurar estilo visual predeterminado',
            array('action' => 'reset')
        );

        $args = array('style_msg' => $operation_id > 0 ? 'reset' : 'unchanged');
        if ($operation_id > 0) {
            $args['operation_id'] = $operation_id;
        }
    } catch (Throwable $exception) {
        error_log('[SEO Marketing] Error al restaurar el estilo: ' . $exception->getMessage());
        $args = array('style_msg' => 'error');
    }

    wp_safe_redirect(seo_marketing_style_admin_url($args));
    exit;
}
add_action('admin_post_seo_marketing_style_reset', 'seo_marketing_style_handle_reset');

/**
 * Importa un JSON exportado por el propio módulo.
 */
function seo_marketing_style_handle_import()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para importar estilos.', 'seo-system'));
    }

    check_admin_referer('seo_marketing_style_import');

    $raw = isset($_POST['style_json']) ? trim((string) wp_unslash($_POST['style_json'])) : '';
    $decoded = $raw !== '' ? json_decode($raw, true) : null;

    if (!is_array($decoded)) {
        wp_safe_redirect(seo_marketing_style_admin_url(array('style_msg' => 'invalid_json')));
        exit;
    }

    $values = isset($decoded['values']) && is_array($decoded['values'])
        ? $decoded['values']
        : $decoded;

    try {
        $operation_id = seo_marketing_style_persist(
            $values,
            'import_visual_style',
            'Importar configuración de estilo visual',
            array('action' => 'import')
        );

        $args = array('style_msg' => $operation_id > 0 ? 'imported' : 'unchanged');
        if ($operation_id > 0) {
            $args['operation_id'] = $operation_id;
        }
    } catch (Throwable $exception) {
        error_log('[SEO Marketing] Error al importar el estilo: ' . $exception->getMessage());
        $args = array('style_msg' => 'error');
    }

    wp_safe_redirect(seo_marketing_style_admin_url($args));
    exit;
}
add_action('admin_post_seo_marketing_style_import', 'seo_marketing_style_handle_import');

/**
 * Descarga la configuración actual como JSON.
 */
function seo_marketing_style_handle_export()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para exportar estilos.', 'seo-system'));
    }

    check_admin_referer('seo_marketing_style_export');

    $payload = array(
        'schema_version' => SEO_MARKETING_STYLE_SCHEMA_VERSION,
        'plugin_version' => defined('SEO_SYSTEM_VERSION') ? SEO_SYSTEM_VERSION : '',
        'exported_at_utc'=> current_time('mysql', true),
        'site'           => home_url('/'),
        'values'         => seo_marketing_style_get_settings(),
    );

    $json = wp_json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="seo-visual-style-' . gmdate('Ymd-His') . '.json"');
    echo is_string($json) ? $json : '{}';
    exit;
}
add_action('admin_post_seo_marketing_style_export', 'seo_marketing_style_handle_export');

/**
 * Construye CSS seguro. No acepta selectores ni CSS procedente del usuario.
 *
 * @param array $settings
 * @return string
 */
function seo_marketing_style_build_css($settings)
{
    $settings = seo_marketing_style_sanitize_settings($settings);
    $shadows = seo_marketing_style_shadow_choices();
    $shadow = $shadows[$settings['shadow_preset']];
    $menu_shadow = $shadows[$settings['menu_shadow_preset']];

    $body_font = seo_marketing_style_font_stack($settings['font_body']);
    $heading_font = seo_marketing_style_font_stack($settings['font_headings']);

    $css = array();
    $css[] = 'html:root {';
    $css[] = '  --dht-primary: ' . $settings['primary'] . ';';
    $css[] = '  --dht-primary-dark: ' . $settings['primary_dark'] . ';';
    $css[] = '  --dht-secondary: ' . $settings['secondary'] . ';';
    $css[] = '  --dht-dark: ' . $settings['dark'] . ';';
    $css[] = '  --dht-dark-soft: ' . $settings['dark_soft'] . ';';
    $css[] = '  --dht-white: ' . $settings['white'] . ';';
    $css[] = '  --dht-bg: ' . $settings['background'] . ';';
    $css[] = '  --dht-bg-light: ' . $settings['background_light'] . ';';
    $css[] = '  --dht-text: ' . $settings['text'] . ';';
    $css[] = '  --dht-text-soft: ' . $settings['text_soft'] . ';';
    $css[] = '  --dht-border: ' . $settings['border'] . ';';
    $css[] = '  --dht-success: ' . $settings['success'] . ';';
    $css[] = '  --dht-error: ' . $settings['error'] . ';';
    $css[] = '  --dht-radius-small: ' . (int) $settings['radius_small'] . 'px;';
    $css[] = '  --dht-radius: ' . (int) $settings['radius'] . 'px;';
    $css[] = '  --dht-radius-large: ' . (int) $settings['radius_large'] . 'px;';
    $css[] = '  --dht-shadow-small: ' . $shadow['small'] . ';';
    $css[] = '  --dht-shadow: ' . $shadow['normal'] . ';';
    $css[] = '  --dht-shadow-large: ' . $shadow['large'] . ';';
    $css[] = '  --dht-container: ' . (int) $settings['container_width'] . 'px;';
    $css[] = '  --dht-content: ' . (int) $settings['content_width'] . 'px;';
    $css[] = '  --dht-product-page-title-min: ' . (int) $settings['product_page_title_min'] . 'px;';
    $css[] = '  --dht-product-page-title-max: ' . (int) $settings['product_page_title_max'] . 'px;';
    $css[] = '  --dht-product-page-title-weight: ' . (int) $settings['product_page_title_weight'] . ';';
    $css[] = '  --dht-product-page-title-line-height: ' . (float) $settings['product_page_title_line_height'] . ';';
    $css[] = '  --dht-menu-bg: ' . $settings['menu_background'] . ';';
    $css[] = '  --dht-menu-text: ' . $settings['menu_text'] . ';';
    $css[] = '  --dht-menu-hover: ' . $settings['menu_hover'] . ';';
    $css[] = '  --dht-menu-active-text: ' . $settings['menu_active_text'] . ';';
    $css[] = '  --dht-menu-active-bg: ' . $settings['menu_active_background'] . ';';
    $css[] = '  --dht-menu-dropdown-bg: ' . $settings['menu_dropdown_background'] . ';';
    $css[] = '  --dht-menu-dropdown-text: ' . $settings['menu_dropdown_text'] . ';';
    $css[] = '  --dht-menu-dropdown-hover-bg: ' . $settings['menu_dropdown_hover_bg'] . ';';
    $css[] = '  --dht-menu-dropdown-hover-text: ' . $settings['menu_dropdown_hover_text'] . ';';
    $css[] = '  --dht-menu-border: ' . $settings['menu_border'] . ';';
    $css[] = '  --dht-menu-height: ' . (int) $settings['menu_height'] . 'px;';
    $css[] = '  --dht-menu-padding-x: ' . (int) $settings['menu_padding_x'] . 'px;';
    $css[] = '  --dht-menu-font-size: ' . (int) $settings['menu_font_size'] . 'px;';
    $css[] = '  --dht-menu-font-weight: ' . (int) $settings['menu_font_weight'] . ';';
    $css[] = '  --dht-menu-radius: ' . (int) $settings['menu_radius'] . 'px;';
    $css[] = '  --dht-menu-dropdown-min-width: ' . (int) $settings['menu_dropdown_min_width'] . 'px;';
    $css[] = '  --dht-menu-shadow: ' . $menu_shadow['normal'] . ';';
    $css[] = '  --dht-menu-transform: ' . $settings['menu_transform'] . ';';
    $css[] = '  --dht-solutions-columns: ' . (int) $settings['solutions_columns_desktop'] . ';';
    $css[] = '  --dht-solutions-gap: ' . (int) $settings['solutions_grid_gap'] . 'px;';
    $css[] = '  --dht-solutions-image-height: ' . (int) $settings['solutions_image_height'] . 'px;';
    $css[] = '  --dht-solutions-title-size: ' . (int) $settings['solutions_title_size'] . 'px;';
    $css[] = '  --dht-footer-bg: ' . $settings['footer_background'] . ';';
    $css[] = '  --dht-footer-heading: ' . $settings['footer_heading_color'] . ';';
    $css[] = '  --dht-footer-text: ' . $settings['footer_text_color'] . ';';
    $css[] = '  --dht-footer-link: ' . $settings['footer_link_color'] . ';';
    $css[] = '  --dht-footer-link-hover: ' . $settings['footer_link_hover'] . ';';
    $css[] = '  --dht-footer-meta: ' . $settings['footer_meta_color'] . ';';
    $css[] = '  --dht-footer-border: ' . $settings['footer_border_color'] . ';';

    if ('none' === $settings['menu_animation']) {
        $css[] = '  --dht-menu-dropdown-offset: 0px;';
        $css[] = '  --dht-menu-dropdown-duration: 0ms;';
    } elseif ('fade' === $settings['menu_animation']) {
        $css[] = '  --dht-menu-dropdown-offset: 0px;';
        $css[] = '  --dht-menu-dropdown-duration: .2s;';
    } else {
        $css[] = '  --dht-menu-dropdown-offset: 8px;';
        $css[] = '  --dht-menu-dropdown-duration: .25s;';
    }

    $css[] = '}';

    $scope = 'html body .dht-page, html body .dht-home, html body .hub-page, html body .landing-page, html body .dh-product-page, html body .page-corporate, html body .solutions-index-page';
    $css[] = $scope . ' {';
    $css[] = '  font-family: ' . $body_font . ';';
    $css[] = '  font-size: ' . (int) $settings['body_size'] . 'px;';
    $css[] = '  line-height: ' . (float) $settings['body_line_height'] . ';';
    $css[] = '}';

    $paragraphs = 'html body .dht-page p, html body .dht-home p, html body .hub-page p, html body .landing-page p, html body .dh-product-page p, html body .page-corporate p, html body .solutions-index-page p';
    $css[] = $paragraphs . ' { line-height: ' . (float) $settings['paragraph_line_height'] . '; }';

    $h1 = 'html body .dht-page h1, html body .dht-home h1, html body .hub-page h1, html body .landing-page h1, html body .dh-product-page h1, html body .page-corporate h1, html body .solutions-index-page h1';
    $h2 = 'html body .dht-page h2, html body .dht-home h2, html body .hub-page h2, html body .landing-page h2, html body .dh-product-page h2, html body .page-corporate h2, html body .solutions-index-page h2';
    $h3 = 'html body .dht-page h3, html body .dht-home h3, html body .hub-page h3, html body .landing-page h3, html body .dh-product-page h3, html body .page-corporate h3, html body .solutions-index-page h3';

    $css[] = $h1 . ' {';
    $css[] = '  font-family: ' . $heading_font . ';';
    $css[] = '  font-size: clamp(' . (int) $settings['h1_min'] . 'px, 5vw, ' . (int) $settings['h1_max'] . 'px);';
    $css[] = '  font-weight: ' . (int) $settings['h1_weight'] . ';';
    $css[] = '  color: ' . $settings['h1_color'] . ';';
    $css[] = '  text-align: ' . $settings['heading_align'] . ';';
    $css[] = '  text-transform: ' . $settings['heading_transform'] . ';';
    $css[] = '}';

    $css[] = $h2 . ' {';
    $css[] = '  font-family: ' . $heading_font . ';';
    $css[] = '  font-size: clamp(' . (int) $settings['h2_min'] . 'px, 4vw, ' . (int) $settings['h2_max'] . 'px);';
    $css[] = '  font-weight: ' . (int) $settings['h2_weight'] . ';';
    $css[] = '  color: ' . $settings['h2_color'] . ';';
    $css[] = '  text-align: ' . $settings['heading_align'] . ';';
    $css[] = '  text-transform: ' . $settings['heading_transform'] . ';';
    $css[] = '}';

    $css[] = $h3 . ' {';
    $css[] = '  font-family: ' . $heading_font . ';';
    $css[] = '  font-size: ' . (int) $settings['h3_size'] . 'px;';
    $css[] = '  font-weight: ' . (int) $settings['h3_weight'] . ';';
    $css[] = '  color: ' . $settings['h3_color'] . ';';
    $css[] = '  text-align: ' . $settings['heading_align'] . ';';
    $css[] = '  text-transform: ' . $settings['heading_transform'] . ';';
    $css[] = '}';

    // Los títulos y textos de hero conservan controles independientes.
    $css[] = 'html body .dht-hero h1, html body .hub-hero h1, html body .cluster-hero h1, html body .hub-title, html body .cluster-title { color: ' . $settings['hero_title'] . '; }';
    $css[] = 'html body .dht-hero p, html body .hub-hero p, html body .cluster-hero p, html body .hub-excerpt, html body .cluster-excerpt { color: ' . $settings['hero_text'] . '; }';

    if (!empty($settings['custom_links'])) {
        $links = 'html body .dht-page p a, html body .dht-page li a, html body .dht-home p a, html body .dht-home li a, html body .hub-page p a, html body .hub-page li a, html body .landing-page p a, html body .landing-page li a, html body .dh-product-page p a, html body .dh-product-page li a, html body .page-corporate p a, html body .page-corporate li a, html body .solutions-index-page p a, html body .solutions-index-page li a';
        $css[] = $links . ' { color: ' . $settings['link_color'] . ' !important; text-decoration: ' . $settings['link_decoration'] . ' !important; }';
        $link_hover_selectors = array();
        foreach (explode(',', $links) as $link_selector) {
            $link_hover_selectors[] = trim($link_selector) . ':hover';
        }
        $css[] = implode(', ', $link_hover_selectors) . ' { color: ' . $settings['link_hover'] . ' !important; }';
    }

    if (!empty($settings['custom_strong'])) {
        $css[] = 'html body .dht-page strong, html body .dht-home strong, html body .hub-page strong, html body .landing-page strong, html body .dh-product-page strong, html body .page-corporate strong, html body .solutions-index-page strong { color: ' . $settings['strong_color'] . '; }';
    }

    $css[] = 'html body .dht-section { padding-top: ' . (int) $settings['section_spacing'] . 'px; padding-bottom: ' . (int) $settings['section_spacing'] . 'px; }';
    $css[] = 'html body .hub-section, html body .hub-links, html body .hub-content, html body .cluster-section { padding-top: ' . (int) $settings['section_spacing'] . 'px; padding-bottom: ' . (int) $settings['section_spacing'] . 'px; }';

    $css[] = 'html body .dht-btn { min-height: ' . (int) $settings['button_height'] . 'px; padding-left: ' . (int) $settings['button_padding_x'] . 'px; padding-right: ' . (int) $settings['button_padding_x'] . 'px; }';
    $css[] = 'html body .dht-btn-primary { background: ' . $settings['button_primary_bg'] . '; color: ' . $settings['button_primary_text'] . '; }';
    $css[] = 'html body .dht-btn-blue { background: ' . $settings['button_blue_bg'] . '; color: ' . $settings['button_blue_text'] . '; }';
    $css[] = 'html body .dht-btn-blue:hover { background: ' . $settings['button_blue_hover'] . '; }';

    $css[] = 'html body .dht-card, html body .dht-category-card, html body .hub-card, html body .cluster-card { background: ' . $settings['card_background'] . '; }';
    $css[] = 'html body .dht-card-body, html body .dht-category-content, html body .hub-body, html body .cluster-body { padding: ' . (int) $settings['card_padding'] . 'px; }';
    $css[] = 'html body .dht-card-image img, html body .dht-category-card img, html body .hub-img, html body .cluster-img { height: ' . (int) $settings['card_image_height'] . 'px; }';

    $css[] = 'html body .header-menu, html body .custom-header .header-menu { background: var(--dht-menu-bg); border-color: var(--dht-menu-border); }';
    $css[] = 'html body .dht-primary-menu > li > a, html body .header-menu .menu > li > a { color: var(--dht-menu-text); min-height: var(--dht-menu-height); padding-left: var(--dht-menu-padding-x); padding-right: var(--dht-menu-padding-x); font-size: var(--dht-menu-font-size); font-weight: var(--dht-menu-font-weight); text-transform: var(--dht-menu-transform); }';
    $css[] = 'html body .dht-primary-menu > li > a:hover, html body .dht-primary-menu > li > a:focus-visible, html body .header-menu .menu > li > a:hover, html body .header-menu .menu > li > a:focus-visible { color: var(--dht-menu-hover); }';
    $css[] = 'html body .dht-primary-menu > .current-menu-item > a, html body .dht-primary-menu > .current-menu-ancestor > a, html body .dht-primary-menu > .current_page_item > a, html body .header-menu .menu > .current-menu-item > a, html body .header-menu .menu > .current-menu-ancestor > a, html body .header-menu .menu > .current_page_item > a { color: var(--dht-menu-active-text); background: var(--dht-menu-active-bg); }';
    $css[] = 'html body .dht-primary-menu .sub-menu, html body .header-menu .menu .sub-menu { min-width: var(--dht-menu-dropdown-min-width); background: var(--dht-menu-dropdown-bg); border-color: var(--dht-menu-border); border-radius: var(--dht-menu-radius); box-shadow: var(--dht-menu-shadow); }';
    $css[] = 'html body .dht-primary-menu .sub-menu a, html body .header-menu .menu .sub-menu a { color: var(--dht-menu-dropdown-text); font-size: var(--dht-menu-font-size); font-weight: var(--dht-menu-font-weight); text-transform: var(--dht-menu-transform); }';
    $css[] = 'html body .dht-primary-menu .sub-menu a:hover, html body .dht-primary-menu .sub-menu a:focus-visible, html body .dht-primary-menu .sub-menu .current-menu-item > a, html body .header-menu .menu .sub-menu a:hover, html body .header-menu .menu .sub-menu a:focus-visible, html body .header-menu .menu .sub-menu .current-menu-item > a { color: var(--dht-menu-dropdown-hover-text); background: var(--dht-menu-dropdown-hover-bg); }';

    if ('pills' === $settings['menu_style']) {
        $css[] = 'html body .dht-primary-menu > li > a, html body .header-menu .menu > li > a { border-radius: 999px; }';
    } elseif ('underline' === $settings['menu_style']) {
        $css[] = 'html body .dht-primary-menu > li > a, html body .header-menu .menu > li > a { border-radius: 0; background: transparent; box-shadow: inset 0 -3px 0 transparent; }';
        $css[] = 'html body .dht-primary-menu > li > a:hover, html body .dht-primary-menu > li > a:focus-visible, html body .dht-primary-menu > .current-menu-item > a, html body .dht-primary-menu > .current-menu-ancestor > a, html body .dht-primary-menu > .current_page_item > a, html body .header-menu .menu > li > a:hover, html body .header-menu .menu > li > a:focus-visible, html body .header-menu .menu > .current-menu-item > a, html body .header-menu .menu > .current-menu-ancestor > a, html body .header-menu .menu > .current_page_item > a { background: transparent; box-shadow: inset 0 -3px 0 var(--dht-menu-hover); }';
    } elseif ('minimal' === $settings['menu_style']) {
        $css[] = 'html body .dht-primary-menu > li > a, html body .header-menu .menu > li > a { border-radius: 0; background: transparent; }';
        $css[] = 'html body .dht-primary-menu > .current-menu-item > a, html body .dht-primary-menu > .current-menu-ancestor > a, html body .dht-primary-menu > .current_page_item > a, html body .header-menu .menu > .current-menu-item > a, html body .header-menu .menu > .current-menu-ancestor > a, html body .header-menu .menu > .current_page_item > a { background: transparent; }';
    }

    if (empty($settings['menu_indicator'])) {
        $css[] = 'html body .dht-primary-menu .menu-item-has-children > a::after, html body .header-menu .menu .menu-item-has-children > a::after { display: none; }';
    }

    $css[] = 'html body .solutions-index-page .solutions-grid { display:grid; grid-template-columns:repeat(' . (int) $settings['solutions_columns_desktop'] . ', minmax(0,1fr)); gap:' . (int) $settings['solutions_grid_gap'] . 'px; }';
    $css[] = 'html body .solutions-index-page .solution-card { height:100%; overflow:hidden; background:' . $settings['card_background'] . '; border:1px solid ' . $settings['border'] . '; border-radius:' . (int) $settings['radius_large'] . 'px; box-shadow:' . $shadow['small'] . '; }';
    $css[] = 'html body .solutions-index-page .solution-card-image { display:block; overflow:hidden; height:' . (int) $settings['solutions_image_height'] . 'px; }';
    $css[] = 'html body .solutions-index-page .solution-card-image img { width:100%; height:100%; object-fit:cover; display:block; }';
    $css[] = 'html body .solutions-index-page .solution-card-body { padding:' . (int) $settings['card_padding'] . 'px; }';
    $css[] = 'html body .solutions-index-page .solution-card-body h3 { font-size:' . (int) $settings['solutions_title_size'] . 'px; margin-top:0; }';
    $css[] = 'html body .solutions-index-page .solution-card-body h3 a { color:inherit; text-decoration:none; }';
    $css[] = 'html body .solutions-index-page .solution-card-body h3 a:hover { color:' . $settings['link_hover'] . '; }';
    $css[] = 'html body .solutions-index-page .solutions-pagination { margin-top:32px; }';
    $css[] = 'html body .solutions-index-page .solutions-pagination ul { display:flex; flex-wrap:wrap; gap:8px; padding:0; margin:0; list-style:none; }';

    $product_grid = 'html body .dht-page ul.products, html body .dht-home ul.products, html body .dht-category-products ul.products, html body .dh-product-page ul.products';
    $product_image = 'html body .dht-page ul.products li.product img, html body .dht-home ul.products li.product img, html body .dht-category-products ul.products li.product img, html body .dh-product-page ul.products li.product img';
    $product_title = 'html body .dht-page ul.products li.product .woocommerce-loop-product__title, html body .dht-home ul.products li.product .woocommerce-loop-product__title, html body .dht-category-products ul.products li.product .woocommerce-loop-product__title, html body .dh-product-page ul.products li.product .woocommerce-loop-product__title';
    $product_price = 'html body .dht-page ul.products li.product .price, html body .dht-home ul.products li.product .price, html body .dht-category-products ul.products li.product .price, html body .dh-product-page ul.products li.product .price';
    $product_button = 'html body .dht-page ul.products li.product .button, html body .dht-home ul.products li.product .button, html body .dht-category-products ul.products li.product .button, html body .dh-product-page ul.products li.product .button';

    $css[] = $product_grid . ' { grid-template-columns: repeat(' . (int) $settings['products_columns_desktop'] . ', minmax(0, 1fr)) !important; }';
    $css[] = $product_image . ' { height: ' . (int) $settings['product_image_height'] . 'px !important; }';
    $css[] = $product_title . ' { font-size: ' . (int) $settings['product_title_size'] . 'px !important; }';
    $css[] = $product_price . ' { color: ' . $settings['product_price_color'] . ' !important; }';
    $css[] = $product_button . ' { background: ' . $settings['product_button_bg'] . ' !important; color: ' . $settings['product_button_text'] . ' !important; }';
    $product_button_hover_selectors = array();
    foreach (explode(',', $product_button) as $product_button_selector) {
        $product_button_hover_selectors[] = trim($product_button_selector) . ':hover';
    }
    $css[] = implode(', ', $product_button_hover_selectors) . ' { background: ' . $settings['product_button_hover'] . ' !important; }';

    $css[] = 'html body .site-footer { padding-top:' . (int) $settings['footer_padding_top'] . 'px; padding-bottom:' . (int) $settings['footer_padding_bottom'] . 'px; background:' . $settings['footer_background'] . '; color:' . $settings['footer_text_color'] . '; }';
    $css[] = 'html body .site-footer .footer-container { gap:' . (int) $settings['footer_gap'] . 'px; }';
    $css[] = 'html body .site-footer .footer-logo img { width:' . (int) $settings['footer_logo_width'] . 'px; height:auto; }';
    $css[] = 'html body .site-footer .footer-brand > strong, html body .site-footer .footer-column h2 { color:' . $settings['footer_heading_color'] . '; }';
    $css[] = 'html body .site-footer .footer-column h2 { font-size:' . (int) $settings['footer_heading_size'] . 'px; }';
    $css[] = 'html body .site-footer p, html body .site-footer .footer-contact, html body .site-footer .footer-domain, html body .site-footer .footer-official-domain small { color:' . $settings['footer_text_color'] . '; font-size:' . (int) $settings['footer_text_size'] . 'px; }';
    $css[] = 'html body .site-footer a { color:' . $settings['footer_link_color'] . '; }';
    $css[] = 'html body .site-footer a:hover, html body .site-footer a:focus-visible { color:' . $settings['footer_link_hover'] . '; }';
    $css[] = 'html body .site-footer .footer-official-domain { border-color:' . $settings['footer_border_color'] . '; }';
    $css[] = 'html body .site-footer .footer-meta { border-color:' . $settings['footer_border_color'] . '; color:' . $settings['footer_meta_color'] . '; font-size:' . max(11, (int) $settings['footer_text_size'] - 1) . 'px; }';

    $css[] = 'html body .dht-category-page .taxonomy-faq { background: ' . $settings['faq_section_bg'] . '; }';
    $css[] = 'html body .dht-category-page .taxonomy-faq-item { background: ' . $settings['faq_item_bg'] . '; border-color: ' . $settings['faq_border_color'] . '; border-radius: ' . (int) $settings['faq_radius'] . 'px; }';
    $css[] = 'html body .dht-category-page .taxonomy-faq-question { color: ' . $settings['faq_question_color'] . '; }';
    $css[] = 'html body .dht-category-page .taxonomy-faq-answer { color: ' . $settings['faq_answer_color'] . '; border-color: ' . $settings['faq_border_color'] . '; }';
    $css[] = 'html body .dht-category-page .taxonomy-faq-question::after { color: ' . $settings['faq_icon_color'] . '; }';

    $css[] = '@media (max-width: 1100px) {';
    $css[] = '  html body .solutions-index-page .solutions-grid { grid-template-columns: repeat(' . (int) $settings['solutions_columns_tablet'] . ', minmax(0,1fr)); }';
    $css[] = '  ' . $product_grid . ' { grid-template-columns: repeat(' . (int) $settings['products_columns_tablet'] . ', minmax(0, 1fr)) !important; }';
    $css[] = '}';
    $css[] = '@media (max-width: 768px) {';
    $css[] = '  ' . $product_grid . ' { grid-template-columns: repeat(' . (int) $settings['products_columns_small'] . ', minmax(0, 1fr)) !important; }';
    $css[] = '  ' . $product_image . ' { height: ' . (int) $settings['product_image_mobile'] . 'px !important; }';
    $css[] = '}';
    $css[] = '@media (max-width: 520px) {';
    $css[] = '  html body .solutions-index-page .solutions-grid { grid-template-columns: repeat(' . (int) $settings['solutions_columns_mobile'] . ', minmax(0,1fr)); }';
    $css[] = '  ' . $product_grid . ' { grid-template-columns: repeat(' . (int) $settings['products_columns_mobile'] . ', minmax(0, 1fr)) !important; }';
    $css[] = '}';

    return implode("\n", $css);
}

/**
 * Publica el estilo después de los estilos encolados. La especificidad de
 * html:root protege las variables incluso en plantillas que enlazan la hoja
 * base manualmente más tarde.
 */
function seo_marketing_print_dynamic_styles()
{
    if (is_admin()) {
        return;
    }

    $record = seo_marketing_style_get_record();
    if (empty($record['exists'])) {
        return;
    }

    echo "\n<style id=\"seo-marketing-visual-style\">\n";
    echo seo_marketing_style_build_css($record['settings']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo "\n</style>\n";
}
add_action('wp_head', 'seo_marketing_print_dynamic_styles', 99);

/**
 * Configuración de identidad y metadatos de portada.
 *
 * Título del sitio, descripción corta, logo e icono se guardan usando las
 * opciones nativas de WordPress. El título SEO y la meta description de la
 * portada permanecen separados para evitar reutilizar la descripción corta
 * como metadato SEO.
 *
 * @return array
 */
function seo_marketing_identity_get_settings()
{
    $stored = get_option(SEO_MARKETING_IDENTITY_OPTION, array());
    $stored = is_array($stored) ? $stored : array();

    return array(
        'home_title'       => isset($stored['home_title']) ? sanitize_text_field((string) $stored['home_title']) : '',
        'home_description' => isset($stored['home_description']) ? sanitize_textarea_field((string) $stored['home_description']) : '',
    );
}

/**
 * URL de la pestaña Identidad / Cabecera.
 *
 * @param array $args
 * @return string
 */
function seo_marketing_identity_admin_url($args = array())
{
    return add_query_arg(
        array_merge(
            array(
                'page' => 'seo-menu-marketing',
                'tab'  => 'identity',
            ),
            is_array($args) ? $args : array()
        ),
        admin_url('admin.php')
    );
}

/**
 * Carga la biblioteca multimedia solo en la pestaña de identidad.
 * Permite subir una imagen nueva o elegir una existente en Medios.
 */
function seo_marketing_identity_enqueue_media()
{
    if (!is_admin()) {
        return;
    }

    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

    if ('seo-menu-marketing' === $page && 'identity' === $tab) {
        wp_enqueue_media();
    }
}
add_action('admin_enqueue_scripts', 'seo_marketing_identity_enqueue_media');

/**
 * Comprueba que un ID corresponde a una imagen de la biblioteca.
 *
 * @param int $attachment_id
 * @return int
 */
function seo_marketing_identity_validate_image_id($attachment_id)
{
    $attachment_id = absint($attachment_id);

    if ($attachment_id <= 0 || !wp_attachment_is_image($attachment_id)) {
        return 0;
    }

    return $attachment_id;
}

/**
 * Guarda identidad, logo, icono y metadatos SEO de portada.
 */
function seo_marketing_identity_handle_save()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para modificar la identidad del sitio.', 'seo-system'));
    }

    check_admin_referer('seo_marketing_identity_save');

    $site_title = isset($_POST['site_title'])
        ? sanitize_text_field(wp_unslash($_POST['site_title']))
        : '';
    $tagline = isset($_POST['tagline'])
        ? sanitize_text_field(wp_unslash($_POST['tagline']))
        : '';
    $home_title = isset($_POST['home_title'])
        ? sanitize_text_field(wp_unslash($_POST['home_title']))
        : '';
    $home_description = isset($_POST['home_description'])
        ? sanitize_textarea_field(wp_unslash($_POST['home_description']))
        : '';

    // Evita saltos y espacios repetidos dentro de la meta description.
    $home_description = preg_replace('/\s+/u', ' ', trim((string) $home_description));
    $home_description = is_string($home_description) ? $home_description : '';

    $logo_id = isset($_POST['logo_id'])
        ? seo_marketing_identity_validate_image_id($_POST['logo_id'])
        : 0;
    $site_icon_id = isset($_POST['site_icon_id'])
        ? seo_marketing_identity_validate_image_id($_POST['site_icon_id'])
        : 0;

    update_option('blogname', $site_title);
    update_option('blogdescription', $tagline);

    if ($logo_id > 0) {
        set_theme_mod('custom_logo', $logo_id);
    } else {
        remove_theme_mod('custom_logo');
    }

    if ($site_icon_id > 0) {
        update_option('site_icon', $site_icon_id);
    } else {
        delete_option('site_icon');
    }

    update_option(
        SEO_MARKETING_IDENTITY_OPTION,
        array(
            'home_title'       => $home_title,
            'home_description' => $home_description,
        ),
        false
    );

    wp_safe_redirect(seo_marketing_identity_admin_url(array('identity_msg' => 'saved')));
    exit;
}
add_action('admin_post_seo_marketing_identity_save', 'seo_marketing_identity_handle_save');

/**
 * Título SEO específico de la portada. Si está vacío, WordPress conserva su
 * título normal.
 *
 * @param string $title
 * @return string
 */
function seo_marketing_identity_filter_home_title($title)
{
    if (is_admin() || !is_front_page()) {
        return $title;
    }

    $settings = seo_marketing_identity_get_settings();
    return $settings['home_title'] !== '' ? $settings['home_title'] : $title;
}
add_filter('pre_get_document_title', 'seo_marketing_identity_filter_home_title', 99);

/**
 * Publica una sola meta description SEO para la portada desde la configuración
 * del módulo. No depende de la descripción corta de WordPress.
 */
function seo_marketing_identity_print_home_meta_description()
{
    if (is_admin() || !is_front_page()) {
        return;
    }

    $settings = seo_marketing_identity_get_settings();
    if ($settings['home_description'] === '') {
        return;
    }

    echo "\n<meta name=\"description\" content=\"" . esc_attr($settings['home_description']) . "\">\n";
}
add_action('wp_head', 'seo_marketing_identity_print_home_meta_description', 2);

/**
 * Mensajes de la pestaña Identidad / Cabecera.
 */
function seo_marketing_identity_render_notice()
{
    $message = isset($_GET['identity_msg'])
        ? sanitize_key(wp_unslash($_GET['identity_msg']))
        : '';

    if ('saved' === $message) {
        echo '<div class="notice notice-success is-dismissible"><p>Identidad, cabecera y SEO de portada guardados correctamente.</p></div>';
    }
}

/**
 * Pestaña Identidad / Cabecera.
 */
function seo_marketing_render_identity_tab()
{
    $settings     = seo_marketing_identity_get_settings();
    $site_title   = (string) get_option('blogname', '');
    $tagline      = (string) get_option('blogdescription', '');
    $logo_id      = absint(get_theme_mod('custom_logo', 0));
    $site_icon_id = absint(get_option('site_icon', 0));
    $logo_url     = $logo_id > 0 ? wp_get_attachment_image_url($logo_id, 'medium') : '';
    $icon_url     = $site_icon_id > 0 ? wp_get_attachment_image_url($site_icon_id, 'thumbnail') : '';
    $front_page_id = absint(get_option('page_on_front', 0));
    $front_url     = home_url('/');

    seo_marketing_identity_render_notice();

    echo '<div class="seo-marketing-card">';
    echo '<h2>Identidad y cabecera del sitio</h2>';
    echo '<p>Centraliza aquí los datos que WordPress y el tema utilizan para identificar el sitio. El logo e icono se guardan en las opciones nativas de WordPress, por lo que puedes subir una imagen nueva o elegir una existente de la Biblioteca de medios.</p>';
    echo '<p><strong>Importante:</strong> la meta description SEO de la portada es independiente de la descripción corta de WordPress. Así puedes dejar la descripción corta vacía si visualmente se duplica y seguir enviando a Bing una meta description correcta.</p>';
    echo '</div>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="seo-marketing-identity-form">';
    echo '<input type="hidden" name="action" value="seo_marketing_identity_save">';
    wp_nonce_field('seo_marketing_identity_save');

    echo '<div class="seo-identity-grid">';
    echo '<div>';

    echo '<section class="seo-marketing-card">';
    echo '<h2>WordPress / tema</h2>';

    echo '<div class="seo-identity-field">';
    echo '<label for="seo-identity-site-title">Título del sitio</label>';
    echo '<input type="text" class="regular-text" id="seo-identity-site-title" name="site_title" value="' . esc_attr($site_title) . '">';
    echo '<p class="description">Valor nativo <code>blogname</code>. Lo usan WordPress, el tema y las plantillas que llaman a <code>get_bloginfo(&quot;name&quot;)</code>.</p>';
    echo '</div>';

    echo '<div class="seo-identity-field">';
    echo '<label for="seo-identity-tagline">Descripción corta</label>';
    echo '<input type="text" class="large-text" id="seo-identity-tagline" name="tagline" value="' . esc_attr($tagline) . '">';
    echo '<p class="description">Valor nativo <code>blogdescription</code>. Puede dejarse vacío si tu cabecera o tema lo muestra duplicado. <strong>No se usa como meta description SEO.</strong></p>';
    echo '</div>';

    seo_marketing_identity_render_media_field(
        'logo',
        'Logo del sitio',
        $logo_id,
        $logo_url,
        'Se guarda como <code>custom_logo</code> del tema. El selector permite subir un archivo o elegirlo en Medios.'
    );

    seo_marketing_identity_render_media_field(
        'site_icon',
        'Icono del sitio / favicon',
        $site_icon_id,
        $icon_url,
        'Se guarda como icono nativo de WordPress y se utiliza para favicon y otros iconos del navegador.'
    );

    echo '</section>';

    echo '<section class="seo-marketing-card">';
    echo '<h2>SEO de la portada</h2>';

    echo '<div class="seo-identity-field">';
    echo '<label for="seo-identity-home-title">Título SEO de la portada</label>';
    echo '<input type="text" class="large-text" id="seo-identity-home-title" name="home_title" maxlength="180" value="' . esc_attr($settings['home_title']) . '">';
    echo '<p class="description">Si lo dejas vacío, WordPress conserva el título que genera actualmente. Este campo permite sobrescribir solo el <code>&lt;title&gt;</code> de la portada.</p>';
    echo '</div>';

    echo '<div class="seo-identity-field">';
    echo '<label for="seo-identity-home-description">Meta description de la portada</label>';
    echo '<textarea class="large-text" rows="4" id="seo-identity-home-description" name="home_description" maxlength="320">' . esc_textarea($settings['home_description']) . '</textarea>';
    echo '<p class="description"><span id="seo-identity-description-count">' . esc_html((string) (function_exists('mb_strlen') ? mb_strlen($settings['home_description']) : strlen($settings['home_description']))) . '</span>/320 caracteres. Este texto genera <code>&lt;meta name=&quot;description&quot; ...&gt;</code> en la portada y corrige el aviso de Bing cuando no está vacío.</p>';
    echo '</div>';

    echo '</section>';
    echo '</div>';

    echo '<aside>';
    echo '<section class="seo-marketing-card">';
    echo '<h2>Estado actual</h2>';
    echo '<table class="widefat striped"><tbody>';
    echo '<tr><th>Inicio</th><td><a href="' . esc_url($front_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($front_url) . '</a></td></tr>';
    echo '<tr><th>Página de inicio</th><td>' . ($front_page_id > 0 ? '#' . esc_html((string) $front_page_id) . ' · ' . esc_html(get_the_title($front_page_id)) : 'Últimas entradas / sin página estática') . '</td></tr>';
    echo '<tr><th>Logo</th><td>' . ($logo_id > 0 ? 'Medio #' . esc_html((string) $logo_id) : 'Sin logo nativo seleccionado') . '</td></tr>';
    echo '<tr><th>Icono</th><td>' . ($site_icon_id > 0 ? 'Medio #' . esc_html((string) $site_icon_id) : 'Sin icono seleccionado') . '</td></tr>';
    echo '<tr><th>Meta description</th><td>' . ($settings['home_description'] !== '' ? 'Configurada' : '<strong style="color:#b32d2e;">Vacía</strong>') . '</td></tr>';
    echo '</tbody></table>';
    echo '</section>';

    echo '<section class="seo-marketing-card">';
    echo '<h2>Qué modifica este formulario</h2>';
    echo '<ul style="margin-left:18px;list-style:disc;">';
    echo '<li><code>blogname</code> — título del sitio.</li>';
    echo '<li><code>blogdescription</code> — descripción corta.</li>';
    echo '<li><code>custom_logo</code> — logo nativo del tema.</li>';
    echo '<li><code>site_icon</code> — favicon/icono del sitio.</li>';
    echo '<li><code>' . esc_html(SEO_MARKETING_IDENTITY_OPTION) . '</code> — título SEO y meta description de portada.</li>';
    echo '</ul>';
    echo '</section>';
    echo '</aside>';
    echo '</div>';

    echo '<p><button type="submit" class="button button-primary button-large">Guardar identidad y SEO</button></p>';
    echo '</form>';

    seo_marketing_identity_render_script();
}

/**
 * Campo de imagen conectado con la Biblioteca de medios.
 *
 * @param string $key
 * @param string $label
 * @param int    $attachment_id
 * @param string $image_url
 * @param string $description_html
 */
function seo_marketing_identity_render_media_field($key, $label, $attachment_id, $image_url, $description_html)
{
    $field_id = 'seo-identity-' . sanitize_html_class($key);

    echo '<div class="seo-identity-field seo-identity-media-field" data-media-field="' . esc_attr($key) . '">';
    echo '<label>' . esc_html($label) . '</label>';
    echo '<input type="hidden" id="' . esc_attr($field_id) . '-id" name="' . esc_attr($key) . '_id" value="' . esc_attr((string) $attachment_id) . '">';
    echo '<div class="seo-identity-media-preview" id="' . esc_attr($field_id) . '-preview">';
    if ($image_url) {
        echo '<img src="' . esc_url($image_url) . '" alt="">';
    } else {
        echo '<span>Sin imagen seleccionada</span>';
    }
    echo '</div>';
    echo '<div class="seo-identity-media-actions">';
    echo '<button type="button" class="button seo-identity-media-select" data-target="' . esc_attr($field_id) . '">Subir o elegir en Medios</button>';
    echo '<button type="button" class="button seo-identity-media-remove" data-target="' . esc_attr($field_id) . '">Quitar</button>';
    echo '</div>';
    echo '<p class="description">' . wp_kses($description_html, array('code' => array())) . '</p>';
    echo '</div>';
}

/**
 * JS de selección de medios y contador de descripción.
 */
function seo_marketing_identity_render_script()
{
    ?>
    <script>
    (function () {
        document.addEventListener('click', function (event) {
            var selectButton = event.target.closest('.seo-identity-media-select');
            var removeButton = event.target.closest('.seo-identity-media-remove');

            if (selectButton) {
                event.preventDefault();
                if (typeof wp === 'undefined' || !wp.media) {
                    return;
                }

                var target = selectButton.getAttribute('data-target');
                var input = document.getElementById(target + '-id');
                var preview = document.getElementById(target + '-preview');
                var frame = wp.media({
                    title: 'Seleccionar imagen',
                    button: { text: 'Usar esta imagen' },
                    library: { type: 'image' },
                    multiple: false
                });

                frame.on('select', function () {
                    var attachment = frame.state().get('selection').first().toJSON();
                    var url = attachment.url || '';
                    if (attachment.sizes && attachment.sizes.medium) {
                        url = attachment.sizes.medium.url;
                    }
                    input.value = attachment.id || '';
                    preview.innerHTML = url ? '<img src="' + url.replace(/"/g, '&quot;') + '" alt="">' : '<span>Imagen seleccionada</span>';
                });

                frame.open();
            }

            if (removeButton) {
                event.preventDefault();
                var removeTarget = removeButton.getAttribute('data-target');
                var removeInput = document.getElementById(removeTarget + '-id');
                var removePreview = document.getElementById(removeTarget + '-preview');
                if (removeInput) {
                    removeInput.value = '';
                }
                if (removePreview) {
                    removePreview.innerHTML = '<span>Sin imagen seleccionada</span>';
                }
            }
        });

        var description = document.getElementById('seo-identity-home-description');
        var counter = document.getElementById('seo-identity-description-count');
        if (description && counter) {
            var updateCounter = function () {
                counter.textContent = String(description.value.length);
            };
            description.addEventListener('input', updateCounter);
            updateCounter();
        }
    }());
    </script>
    <?php
}

/**
 * Pantalla principal.
 */
function seo_menu_manager_marketing_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $allowed_tabs = array('marketing', 'identity', 'landings', 'social', 'sitemaps', 'scan', 'style');
    $current_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'marketing';
    if (!in_array($current_tab, $allowed_tabs, true)) {
        $current_tab = 'marketing';
    }

    $sitemap_notice = null;
    if ($current_tab === 'sitemaps') {
        $sitemap_notice = seo_marketing_maybe_create_sitemaps();
    }

    echo '<div class="wrap seo-marketing-admin">';
    echo '<h1 style="margin-bottom:20px;">SEO Marketing</h1>';

    seo_marketing_render_admin_styles();
    seo_marketing_render_tabs($current_tab);

    echo '<div class="seo-marketing-panel">';

    if ($current_tab === 'identity') {
        seo_marketing_render_identity_tab();
    } elseif ($current_tab === 'sitemaps') {
        seo_marketing_render_sitemaps_tab($sitemap_notice);
    } elseif ($current_tab === 'scan') {
        seo_marketing_render_scan_tab();
    } elseif ($current_tab === 'style') {
        seo_marketing_render_style_tab();
    } elseif ($current_tab === 'landings') {
        if (function_exists('seo_landing_render_admin_tab')) {
            seo_landing_render_admin_tab();
        } else {
            echo '<div class="notice notice-error inline"><p>No se ha podido cargar el modulo <code>seo-landing-pages.php</code>.</p></div>';
        }
    } elseif ($current_tab === 'social') {
        if (function_exists('seo_social_network_render_admin_tab')) {
            seo_social_network_render_admin_tab();
        } else {
            echo '<div class="notice notice-error inline"><p>No se ha podido cargar el modulo <code>seo-social-network.php</code>.</p></div>';
        }
    } else {
        seo_marketing_render_relations_tab();
    }

    echo '</div>';
    echo '</div>';
}

/**
 * @param string $current_tab
 */
function seo_marketing_render_tabs($current_tab)
{
    $tabs = array(
        'marketing' => 'Marketing',
        'identity'  => 'Identidad / Cabecera',
        'landings'  => 'Landing Pages',
        'social'    => 'Redes sociales',
        'sitemaps'  => 'Sitemaps',
        'scan'      => 'Escaneo',
        'style'     => 'Estilo visual',
    );

    echo '<nav class="nav-tab-wrapper">';
    foreach ($tabs as $tab => $label) {
        $url = add_query_arg(
            array('page' => 'seo-menu-marketing', 'tab' => $tab),
            admin_url('admin.php')
        );
        $class = $current_tab === $tab ? ' nav-tab-active' : '';
        echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';
}

/**
 * Estilos exclusivamente administrativos.
 */
function seo_marketing_render_admin_styles()
{
    echo '<style>
        .seo-marketing-panel{margin-top:20px;max-width:1500px;}
        .seo-marketing-card{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:20px;margin:0 0 20px;}
        .seo-identity-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(300px,.6fr);gap:20px;align-items:start;}
        .seo-identity-field{margin:0 0 22px;}
        .seo-identity-field>label{display:block;font-weight:700;margin:0 0 7px;}
        .seo-identity-field input[type=text],.seo-identity-field textarea{width:100%;max-width:none;}
        .seo-identity-media-preview{display:flex;align-items:center;justify-content:center;min-height:120px;max-width:420px;padding:14px;border:1px dashed #a7aaad;border-radius:6px;background:#f6f7f7;color:#646970;}
        .seo-identity-media-preview img{display:block;max-width:100%;max-height:160px;width:auto;height:auto;}
        .seo-identity-media-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:9px;}
        .seo-identity-grid aside{position:sticky;top:48px;}
        .seo-marketing-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;}
        .seo-style-layout{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(340px,.75fr);gap:22px;align-items:start;}
        .seo-style-section{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:18px;margin-bottom:16px;}
        .seo-style-section h2{margin-top:0;}
        .seo-style-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;}
        .seo-style-field label{display:block;font-weight:600;margin-bottom:6px;}
        .seo-style-field small{display:block;color:#646970;margin-top:5px;line-height:1.35;}
        .seo-style-field input[type=number],.seo-style-field select,.seo-style-field input[type=text]{width:100%;}
        .seo-style-color{display:flex;gap:8px;align-items:center;}
        .seo-style-color input[type=color]{width:52px;height:36px;padding:2px;border:1px solid #8c8f94;border-radius:4px;background:#fff;}
        .seo-style-color code{font-size:12px;}
        .seo-style-actions{position:sticky;bottom:0;z-index:4;background:#f0f0f1;border-top:1px solid #dcdcde;padding:14px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
        .seo-style-preview-wrap{position:sticky;top:48px;}
        .seo-style-preview{--preview-primary:#007acc;--preview-primary-dark:#005b96;--preview-secondary:#f0b400;--preview-dark:#101820;--preview-dark-soft:#1c2d3d;--preview-bg:#f7f8fa;--preview-bg-light:#fafbfc;--preview-text:#222;--preview-text-soft:#5b6570;--preview-border:#e7ebef;--preview-radius-small:8px;--preview-radius:14px;--preview-radius-large:20px;--preview-card:#fff;--preview-faq:#fff;--preview-faq-section:#fafbfc;overflow:hidden;background:#fff;border:1px solid #ccd0d4;border-radius:8px;box-shadow:0 12px 30px rgba(0,0,0,.08);font-size:16px;line-height:1.75;}
        .seo-style-preview *{box-sizing:border-box;}
        .seo-style-preview-hero{padding:28px;background:linear-gradient(135deg,var(--preview-dark),var(--preview-dark-soft));}
        .seo-style-preview-hero h1{margin:0 0 10px;color:#fff;line-height:1.15;}
        .seo-style-preview-hero p{margin:0;color:#d7dde4;}
        .seo-style-preview-body{padding:24px;background:var(--preview-bg);color:var(--preview-text);}
        .seo-style-preview-body h2,.seo-style-preview-body h3{color:var(--preview-text);line-height:1.2;}
        .seo-style-preview-body p{color:var(--preview-text-soft);}
        .seo-style-preview-product-detail{margin:18px 0;padding:16px;border:1px solid var(--preview-border);border-radius:var(--preview-radius);background:#fff;}
        .seo-style-preview-product-detail small{display:block;margin-bottom:7px;color:var(--preview-text-soft);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}
        .seo-style-preview-product-title{margin:0;color:var(--preview-text);font-size:clamp(var(--preview-product-title-min,24px),2.5vw,var(--preview-product-title-max,34px));font-weight:var(--preview-product-title-weight,700);line-height:var(--preview-product-title-line-height,1.18);}
        .seo-style-preview-button{display:inline-flex;min-height:50px;padding:0 26px;align-items:center;border:0;border-radius:var(--preview-radius-small);background:var(--preview-primary);color:#fff;font-weight:700;}
        .seo-style-preview-card{margin-top:18px;background:var(--preview-card);border:1px solid var(--preview-border);border-radius:var(--preview-radius-large);overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.05);}
        .seo-style-preview-image{height:130px;display:flex;align-items:center;justify-content:center;background:var(--preview-bg-light);font-size:42px;}
        .seo-style-preview-card-content{padding:20px;}
        .seo-style-preview-price{color:var(--preview-primary);font-size:21px;font-weight:700;}
        .seo-style-preview-solutions{margin-top:18px;display:grid;grid-template-columns:repeat(min(var(--preview-solutions-columns,3),3),minmax(0,1fr));gap:var(--preview-solutions-gap,16px);}
        .seo-style-preview-solution{overflow:hidden;background:var(--preview-card);border:1px solid var(--preview-border);border-radius:var(--preview-radius-large);}
        .seo-style-preview-solution-image{height:min(var(--preview-solutions-image-height,110px),130px);display:flex;align-items:center;justify-content:center;background:var(--preview-bg-light);font-size:28px;}
        .seo-style-preview-solution-body{padding:14px;}
        .seo-style-preview-solution-body strong{display:block;font-size:min(var(--preview-solutions-title-size,18px),22px);line-height:1.2;}
        .seo-style-preview-faq{margin-top:18px;padding:14px;background:var(--preview-faq-section);border-radius:var(--preview-radius);}
        .seo-style-preview-faq-item{background:var(--preview-faq);border:1px solid var(--preview-border);border-radius:var(--preview-radius);padding:15px;}
        .seo-style-preview-footer{padding:42px 18px 28px;background:var(--preview-footer-bg,#101820);color:var(--preview-footer-text,#d7dde4);}
        .seo-style-preview-footer-grid{display:grid;grid-template-columns:1.35fr repeat(3,1fr);gap:var(--preview-footer-gap,28px);}
        .seo-style-preview-footer h3,.seo-style-preview-footer strong{margin:0 0 8px;color:var(--preview-footer-heading,#fff);font-size:var(--preview-footer-heading-size,16px);}
        .seo-style-preview-footer p{margin:0 0 8px;color:var(--preview-footer-text,#d7dde4);font-size:var(--preview-footer-text-size,14px);line-height:1.5;}
        .seo-style-preview-footer a{display:block;margin:5px 0;color:var(--preview-footer-link,#fff);font-size:var(--preview-footer-text-size,14px);text-decoration:none;}
        .seo-style-preview-footer a:hover{color:var(--preview-footer-link-hover,#f0b400);}
        .seo-style-preview-footer-logo{display:grid;width:min(var(--preview-footer-logo-width,170px),100%);height:42px;margin-bottom:10px;place-items:center;border:1px solid var(--preview-footer-border,#33404c);border-radius:6px;color:var(--preview-footer-heading,#fff);font-weight:800;}
        .seo-style-preview-footer-domain{margin-top:8px;padding:8px;border:1px solid var(--preview-footer-border,#33404c);border-radius:6px;color:var(--preview-footer-text,#d7dde4);font-size:11px;}
        .seo-style-preview-footer-meta{margin-top:18px;padding-top:12px;border-top:1px solid var(--preview-footer-border,#33404c);color:var(--preview-footer-meta,#aeb8c2);font-size:11px;}
        .seo-style-preview-menu{position:relative;display:flex;align-items:center;gap:4px;padding:7px 12px;background:var(--preview-menu-bg,#fff);border-bottom:1px solid var(--preview-menu-border,#e7ebef);font-size:var(--preview-menu-size,14px);font-weight:var(--preview-menu-weight,700);text-transform:var(--preview-menu-transform,none);}
        .seo-style-preview-menu-item{position:relative;display:flex;min-height:var(--preview-menu-height,46px);align-items:center;padding:0 var(--preview-menu-padding,12px);border-radius:var(--preview-menu-radius,10px);color:var(--preview-menu-text,#222);}
        .seo-style-preview-menu-item.is-active{background:var(--preview-menu-active-bg,#edf6fc);color:var(--preview-menu-active-text,#005b96);}
        .seo-style-preview-menu-item.is-parent{color:var(--preview-menu-hover,#007acc);}
        .seo-style-preview-menu-indicator{margin-left:6px;font-size:11px;}
        .seo-style-preview-dropdown{position:absolute;z-index:3;top:calc(100% + 6px);left:0;width:var(--preview-menu-dropdown-width,220px);padding:8px;background:var(--preview-menu-dropdown-bg,#fff);border:1px solid var(--preview-menu-border,#e7ebef);border-radius:var(--preview-menu-radius,10px);box-shadow:var(--preview-menu-shadow,0 14px 35px rgba(0,0,0,.08));}
        .seo-style-preview-dropdown span{display:block;padding:9px 11px;border-radius:min(var(--preview-menu-radius,10px),10px);color:var(--preview-menu-dropdown-text,#222);font-size:12px;line-height:1.35;}
        .seo-style-preview-dropdown span:first-child{background:var(--preview-menu-dropdown-hover-bg,#fafbfc);color:var(--preview-menu-dropdown-hover-text,#007acc);}
        .seo-style-preview-menu[data-menu-style="pills"] .seo-style-preview-menu-item{border-radius:999px;}
        .seo-style-preview-menu[data-menu-style="underline"] .seo-style-preview-menu-item{border-radius:0;background:transparent;box-shadow:inset 0 -3px 0 transparent;}
        .seo-style-preview-menu[data-menu-style="underline"] .seo-style-preview-menu-item.is-active{box-shadow:inset 0 -3px 0 var(--preview-menu-hover,#007acc);}
        .seo-style-preview-menu[data-menu-style="minimal"] .seo-style-preview-menu-item{border-radius:0;background:transparent;}
        .seo-style-json textarea{width:100%;min-height:180px;font-family:monospace;}
        .seo-marketing-relation-form{margin:0 0 14px;}
        .seo-marketing-relation-details{border:1px solid #dcdcde;border-radius:6px;background:#fff;overflow:hidden;}
        .seo-marketing-relation-details>summary{cursor:pointer;padding:14px 16px;background:#f6f7f7;display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
        .seo-marketing-relation-details[open]>summary{border-bottom:1px solid #dcdcde;}
        .seo-marketing-status{display:inline-block;padding:2px 6px;border-radius:4px;background:#646970;color:#fff;font-size:11px;font-weight:700;}
        .seo-marketing-status-publish{background:#2e7d32;}
        .seo-marketing-status-draft,.seo-marketing-status-pending{background:#d97706;}
        .seo-marketing-selected-count{margin-left:auto;color:#50575e;font-size:12px;}
        .seo-marketing-relation-body{display:grid;grid-template-columns:minmax(280px,.8fr) minmax(360px,1.2fr);gap:18px;padding:18px;}
        .seo-marketing-category-column h3{margin-top:0;}
        .seo-marketing-category-search{width:100%;max-width:none;margin:0 0 8px;}
        .seo-marketing-category-list{max-height:260px;overflow:auto;border:1px solid #dcdcde;border-radius:5px;padding:8px;background:#fff;}
        .seo-marketing-category-selected{background:#f0fff4;border-color:#72a97c;}
        .seo-marketing-category-item{display:block;padding:5px 7px;border-radius:4px;}
        .seo-marketing-category-item:hover{background:#f0f0f1;}
        .seo-marketing-category-item small{color:#646970;}
        .seo-marketing-relation-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:0;padding:0 18px 18px;}
        .seo-marketing-hierarchy-card{padding:0;overflow:hidden;}
        .seo-marketing-hierarchy-title{padding:20px 20px 4px;margin:0;}
        .seo-marketing-hierarchy-description{padding:0 20px 16px;margin:0;color:#50575e;}
        .seo-marketing-bulk-actions{display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:0 20px 20px;}
        .seo-marketing-bulk-actions form{margin:0;}
        .seo-marketing-bulk-actions .description{max-width:760px;margin:0;}
        .seo-marketing-hierarchy-columns,.seo-marketing-hierarchy-row{display:grid;grid-template-columns:minmax(210px,.75fr) minmax(260px,1fr) minmax(330px,1.25fr) minmax(150px,.55fr);gap:14px;align-items:start;}
        .seo-marketing-hierarchy-columns{padding:10px 16px;background:#1d2327;color:#fff;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;}
        .seo-marketing-hierarchy-group{border-top:1px solid #dcdcde;padding:14px 16px;}
        .seo-marketing-hierarchy-group:first-of-type{border-top:0;}
        .seo-marketing-hierarchy-row{padding:14px;border:1px solid #dcdcde;border-radius:7px;background:#fff;}
        .seo-marketing-hierarchy-row.is-cluster{border-color:#72a97c;background:#f6fff6;}
        .seo-marketing-hierarchy-row.is-hub-primary{margin:10px 0 0 34px;border-left:5px solid #2271b1;background:#f7fbff;}
        .seo-marketing-hierarchy-source strong{display:block;font-size:14px;margin-bottom:5px;}
        .seo-marketing-hierarchy-source code{display:inline-block;margin-right:5px;}
        .seo-marketing-hierarchy-label{display:block;margin:0 0 7px;font-weight:700;}
        .seo-marketing-hierarchy-count{display:block;margin-top:8px;color:#50575e;font-size:12px;}
        .seo-marketing-hierarchy-row .seo-marketing-category-list{max-height:190px;}
        .seo-marketing-hierarchy-actions{display:flex;flex-direction:column;gap:9px;align-items:flex-start;}
        .seo-marketing-hierarchy-actions .button{width:100%;text-align:center;}
        .seo-marketing-hierarchy-empty{margin:10px 0 0 34px;padding:12px 14px;border-left:5px solid #dcdcde;background:#f6f7f7;color:#646970;}
        .seo-marketing-orphan-heading{margin:22px 16px 12px;padding-top:18px;border-top:2px solid #dcdcde;}
        @media(max-width:1250px){.seo-marketing-hierarchy-columns,.seo-marketing-hierarchy-row{grid-template-columns:minmax(190px,.7fr) minmax(240px,1fr) minmax(280px,1fr);}.seo-marketing-hierarchy-columns>div:last-child{display:none;}.seo-marketing-hierarchy-actions{grid-column:1/-1;flex-direction:row;align-items:center;}.seo-marketing-hierarchy-actions .button{width:auto;}}
        @media(max-width:1100px){.seo-style-layout{grid-template-columns:1fr;} .seo-style-preview-footer-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.seo-style-preview-wrap{position:static;}.seo-marketing-relation-body{grid-template-columns:1fr;}.seo-marketing-selected-count{margin-left:0;}.seo-marketing-hierarchy-columns{display:none;}.seo-marketing-hierarchy-row{grid-template-columns:1fr 1fr;}.seo-marketing-hierarchy-source{grid-column:1/-1;}.seo-marketing-hierarchy-row.is-hub-primary,.seo-marketing-hierarchy-empty{margin-left:18px;}}
        @media(max-width:700px){.seo-marketing-hierarchy-row{grid-template-columns:1fr;}.seo-marketing-hierarchy-row.is-hub-primary,.seo-marketing-hierarchy-empty{margin-left:8px;}.seo-marketing-hierarchy-actions{grid-column:auto;flex-direction:column;align-items:stretch;}.seo-marketing-hierarchy-actions .button{width:100%;}}
        @media(max-width:900px){.seo-identity-grid{grid-template-columns:1fr;}.seo-identity-grid aside{position:static;}}
    </style>';
}

/**
 * Configuración de las relaciones editoriales administradas desde Marketing.
 *
 * Estas relaciones controlan únicamente las categorías visibles en las
 * plantillas públicas. No forman parte de la jerarquía taxonómica.
 *
 * @param string $source_type cluster o hub_primary.
 * @return array|null
 */
function seo_marketing_category_relation_config($source_type)
{
    $configs = array(
        'cluster' => array(
            'source_type'   => 'cluster',
            'seo_role'      => 'cluster',
            'relation_type' => 'cluster_to_category',
            'singular'      => 'cluster',
            'title'         => 'Categorías mostradas en clusters',
            'description'   => 'Selecciona las categorías que aparecerán como tarjetas o enlaces editoriales en cada plantilla de cluster.',
        ),
        'hub_primary' => array(
            'source_type'   => 'hub_primary',
            'seo_role'      => 'hub_primary',
            'relation_type' => 'hub_primary_to_category',
            'singular'      => 'hub primario',
            'title'         => 'Categorías mostradas en hubs primarios',
            'description'   => 'Selecciona las categorías que aparecerán como tarjetas o enlaces editoriales en cada plantilla de hub primario.',
        ),
    );

    return isset($configs[$source_type]) ? $configs[$source_type] : null;
}

/**
 * URL de la pestaña Marketing.
 *
 * @param array $args Argumentos adicionales.
 * @return string
 */
function seo_marketing_relations_admin_url($args = array())
{
    return add_query_arg(
        array_merge(
            array(
                'page' => 'seo-menu-marketing',
                'tab'  => 'marketing',
            ),
            is_array($args) ? $args : array()
        ),
        admin_url('admin.php')
    );
}

/**
 * Obtiene los clusters o hubs primarios disponibles.
 *
 * @param string $source_type Tipo de origen.
 * @return object[]
 */
function seo_marketing_get_category_relation_sources($source_type)
{
    global $wpdb;

    $config = seo_marketing_category_relation_config($source_type);

    if (!$config) {
        return array();
    }

    $nodes_table = $wpdb->prefix . 'seo_nodes';

    return (array) $wpdb->get_results(
        $wpdb->prepare(
            "
            SELECT DISTINCT p.ID, p.post_title, p.post_status
            FROM {$wpdb->posts} p
            INNER JOIN {$nodes_table} n
                ON n.object_id = p.ID
            WHERE p.post_type = 'page'
              AND n.object_type = 'page'
              AND n.seo_role = %s
              AND n.status = 1
            ORDER BY p.post_title ASC, p.ID ASC
            ",
            $config['seo_role']
        )
    );
}

/**
 * Comprueba que una página mantiene el rol esperado.
 *
 * @param string $source_type Tipo de origen.
 * @param int    $source_id   ID de página.
 * @return bool
 */
function seo_marketing_category_relation_source_is_valid($source_type, $source_id)
{
    global $wpdb;

    $config   = seo_marketing_category_relation_config($source_type);
    $source_id = absint($source_id);

    if (!$config || $source_id <= 0 || 'page' !== get_post_type($source_id)) {
        return false;
    }

    $nodes_table = $wpdb->prefix . 'seo_nodes';

    return (bool) $wpdb->get_var(
        $wpdb->prepare(
            "
            SELECT 1
            FROM {$nodes_table}
            WHERE object_type = 'page'
              AND object_id = %d
              AND seo_role = %s
              AND status = 1
            LIMIT 1
            ",
            $source_id,
            $config['seo_role']
        )
    );
}

/**
 * Lee las categorías editoriales seleccionadas para un origen.
 *
 * @param string $source_type Tipo de origen.
 * @param int    $source_id   ID de página.
 * @return int[]
 */
function seo_marketing_get_selected_category_ids($source_type, $source_id)
{
    global $wpdb;

    $config    = seo_marketing_category_relation_config($source_type);
    $source_id = absint($source_id);

    if (!$config || $source_id <= 0) {
        return array();
    }

    $relations_table = $wpdb->prefix . 'seo_relations';
    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "
            SELECT DISTINCT target_id
            FROM {$relations_table}
            WHERE source_type = %s
              AND source_id = %d
              AND target_type = 'product_cat'
              AND relation_type = %s
            ORDER BY target_id ASC
            ",
            $config['source_type'],
            $source_id,
            $config['relation_type']
        )
    );

    return array_values(
        array_unique(
            array_filter(
                array_map('absint', (array) $ids)
            )
        )
    );
}


/**
 * Devuelve las categorías estructurales que pertenecen a la rama de un
 * cluster o hub primario.
 *
 * La selección automática nunca usa las relaciones editoriales actuales.
 * Recorre únicamente:
 * cluster_to_primary → hub_primary_to_hub_secondary → hub_secondary_to_category.
 *
 * @param string $source_type cluster o hub_primary.
 * @param int    $source_id   ID de página.
 * @return int[]
 */
function seo_marketing_get_structural_category_ids($source_type, $source_id)
{
    global $wpdb;

    $source_type = sanitize_key($source_type);
    $source_id   = absint($source_id);

    if ($source_id <= 0 || !in_array($source_type, array('cluster', 'hub_primary'), true)) {
        return array();
    }

    $relations_table = $wpdb->prefix . 'seo_relations';

    if ('cluster' === $source_type) {
        $sql = $wpdb->prepare(
            "
            SELECT DISTINCT category_relation.target_id
            FROM {$relations_table} cluster_primary
            INNER JOIN {$relations_table} primary_secondary
                ON primary_secondary.source_type = 'hub_primary'
               AND primary_secondary.source_id = cluster_primary.target_id
               AND primary_secondary.target_type = 'hub_secondary'
               AND primary_secondary.relation_type = 'hub_primary_to_hub_secondary'
            INNER JOIN {$relations_table} category_relation
                ON category_relation.source_type = 'hub_secondary'
               AND category_relation.source_id = primary_secondary.target_id
               AND category_relation.target_type = 'product_cat'
               AND category_relation.relation_type = 'hub_secondary_to_category'
            WHERE cluster_primary.source_type = 'cluster'
              AND cluster_primary.source_id = %d
              AND cluster_primary.target_type = 'hub_primary'
              AND cluster_primary.relation_type = 'cluster_to_primary'
              AND category_relation.target_id > 0
            ",
            $source_id
        );
    } else {
        $sql = $wpdb->prepare(
            "
            SELECT DISTINCT category_relation.target_id
            FROM {$relations_table} primary_secondary
            INNER JOIN {$relations_table} category_relation
                ON category_relation.source_type = 'hub_secondary'
               AND category_relation.source_id = primary_secondary.target_id
               AND category_relation.target_type = 'product_cat'
               AND category_relation.relation_type = 'hub_secondary_to_category'
            WHERE primary_secondary.source_type = 'hub_primary'
              AND primary_secondary.source_id = %d
              AND primary_secondary.target_type = 'hub_secondary'
              AND primary_secondary.relation_type = 'hub_primary_to_hub_secondary'
              AND category_relation.target_id > 0
            ",
            $source_id
        );
    }

    return array_values(
        array_unique(
            array_filter(
                array_map('absint', (array) $wpdb->get_col($sql))
            )
        )
    );
}

/**
 * Selecciona las categorías con más productos dentro de la rama estructural.
 *
 * El límite está fijado por SEO_MARKETING_AUTO_CATEGORY_LIMIT. Las categorías
 * sin productos no se proponen. En caso de empate se prioriza el ID menor para
 * que el resultado sea estable.
 *
 * @param string $source_type cluster o hub_primary.
 * @param int    $source_id   ID de página.
 * @param int    $limit       Número máximo de categorías.
 * @return int[]
 */
function seo_marketing_get_recommended_category_ids($source_type, $source_id, $limit = SEO_MARKETING_AUTO_CATEGORY_LIMIT)
{
    global $wpdb;

    $candidate_ids = seo_marketing_get_structural_category_ids($source_type, $source_id);
    $limit         = max(1, absint($limit));

    if (empty($candidate_ids)) {
        return array();
    }

    $placeholders = implode(', ', array_fill(0, count($candidate_ids), '%d'));
    $query_args   = array_merge($candidate_ids, array($limit));

    $sql = "
        SELECT term_id
        FROM {$wpdb->term_taxonomy}
        WHERE taxonomy = 'product_cat'
          AND term_id IN ({$placeholders})
          AND count > 0
        ORDER BY count DESC, term_id ASC
        LIMIT %d
    ";

    $prepared = $wpdb->prepare($sql, $query_args);

    return array_values(
        array_unique(
            array_filter(
                array_map('absint', (array) $wpdb->get_col($prepared))
            )
        )
    );
}

/**
 * Guarda las categorías editoriales de un cluster o hub primario.
 */
function seo_marketing_handle_save_category_relations()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para modificar estas asociaciones.', 'seo-system'));
    }

    $source_type = isset($_POST['source_type'])
        ? sanitize_key(wp_unslash($_POST['source_type']))
        : '';
    $source_id = isset($_POST['source_id'])
        ? absint($_POST['source_id'])
        : 0;
    $config = seo_marketing_category_relation_config($source_type);

    if (!$config || $source_id <= 0) {
        wp_safe_redirect(
            seo_marketing_relations_admin_url(array('marketing_relations_msg' => 'invalid'))
        );
        exit;
    }

    check_admin_referer(
        'seo_marketing_save_category_relations_' . $source_type . '_' . $source_id
    );

    if (!seo_marketing_category_relation_source_is_valid($source_type, $source_id)) {
        wp_safe_redirect(
            seo_marketing_relations_admin_url(
                array(
                    'marketing_relations_msg' => 'invalid_source',
                    'marketing_source_id'      => $source_id,
                )
            )
        );
        exit;
    }

    $auto_assign = !empty($_POST['seo_marketing_auto_assign']);

    if ($auto_assign) {
        $valid_category_ids = seo_marketing_get_recommended_category_ids(
            $source_type,
            $source_id,
            SEO_MARKETING_AUTO_CATEGORY_LIMIT
        );

        // No vaciamos una selección existente si la rama aún no tiene datos útiles.
        if (empty($valid_category_ids)) {
            wp_safe_redirect(
                seo_marketing_relations_admin_url(
                    array(
                        'marketing_relations_msg' => 'auto_empty',
                        'marketing_source_type'    => $source_type,
                        'marketing_source_id'      => $source_id,
                    )
                )
            );
            exit;
        }
    } else {
        $posted_ids = isset($_POST['category_ids'])
            ? (array) wp_unslash($_POST['category_ids'])
            : array();
        $category_ids = array_values(
            array_unique(
                array_filter(
                    array_map('absint', $posted_ids)
                )
            )
        );

        // Solo se guardan términos reales de product_cat.
        $valid_category_ids = array();
        foreach ($category_ids as $category_id) {
            $term = get_term($category_id, 'product_cat');

            if ($term && !is_wp_error($term)) {
                $valid_category_ids[] = $category_id;
            }
        }
    }

    $current_ids       = seo_marketing_get_selected_category_ids($source_type, $source_id);
    $current_compare   = $current_ids;
    $proposed_compare  = $valid_category_ids;
    sort($current_compare, SORT_NUMERIC);
    sort($proposed_compare, SORT_NUMERIC);

    if ($current_compare === $proposed_compare) {
        wp_safe_redirect(
            seo_marketing_relations_admin_url(
                array(
                    'marketing_relations_msg' => $auto_assign ? 'auto_unchanged' : 'unchanged',
                    'marketing_source_type'    => $source_type,
                    'marketing_source_id'      => $source_id,
                    'marketing_auto_count'     => $auto_assign ? count($valid_category_ids) : 0,
                )
            )
        );
        exit;
    }

    global $wpdb;
    $relations_table = $wpdb->prefix . 'seo_relations';
    $failed = false;

    $wpdb->query('START TRANSACTION');

    $deleted = $wpdb->delete(
        $relations_table,
        array(
            'source_type'   => $config['source_type'],
            'source_id'     => $source_id,
            'target_type'   => 'product_cat',
            'relation_type' => $config['relation_type'],
        ),
        array('%s', '%d', '%s', '%s')
    );

    if (false === $deleted) {
        $failed = true;
    }

    if (!$failed) {
        foreach ($valid_category_ids as $category_id) {
            $inserted = $wpdb->insert(
                $relations_table,
                array(
                    'source_type'   => $config['source_type'],
                    'source_id'     => $source_id,
                    'target_type'   => 'product_cat',
                    'target_id'     => $category_id,
                    'relation_type' => $config['relation_type'],
                    'created_at'    => current_time('mysql'),
                ),
                array('%s', '%d', '%s', '%d', '%s', '%s')
            );

            if (false === $inserted) {
                $failed = true;
                break;
            }
        }
    }

    if ($failed) {
        $wpdb->query('ROLLBACK');
        $message = 'error';
    } else {
        $wpdb->query('COMMIT');
        $message = $auto_assign ? 'auto_saved' : 'saved';
    }

    wp_safe_redirect(
        seo_marketing_relations_admin_url(
            array(
                'marketing_relations_msg' => $message,
                'marketing_source_type'    => $source_type,
                'marketing_source_id'      => $source_id,
                'marketing_auto_count'     => $auto_assign ? count($valid_category_ids) : 0,
            )
        )
    );
    exit;
}
add_action(
    'admin_post_seo_marketing_save_category_relations',
    'seo_marketing_handle_save_category_relations'
);

/**
 * Asigna automáticamente las categorías favoritas a todos los clusters y
 * hubs primarios. Cada página recibe, como máximo, las cuatro categorías con
 * más productos dentro de su propia rama estructural.
 *
 * Las ramas sin categorías con productos se omiten y conservan su selección
 * actual. La operación no modifica ninguna relación taxonómica estructural.
 */
function seo_marketing_handle_auto_assign_all_category_relations()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para modificar estas asociaciones.', 'seo-system'));
    }

    check_admin_referer('seo_marketing_auto_assign_all_category_relations');

    $sources_by_type = array(
        'cluster'     => seo_marketing_get_category_relation_sources('cluster'),
        'hub_primary' => seo_marketing_get_category_relation_sources('hub_primary'),
    );

    $updated        = 0;
    $unchanged      = 0;
    $skipped        = 0;
    $relations_saved = 0;
    $failed         = false;

    global $wpdb;
    $relations_table = $wpdb->prefix . 'seo_relations';

    $wpdb->query('START TRANSACTION');

    foreach ($sources_by_type as $source_type => $sources) {
        $config = seo_marketing_category_relation_config($source_type);

        if (!$config) {
            continue;
        }

        foreach ((array) $sources as $source) {
            $source_id = isset($source->ID) ? absint($source->ID) : 0;

            if (
                $source_id <= 0
                || !seo_marketing_category_relation_source_is_valid($source_type, $source_id)
            ) {
                $skipped++;
                continue;
            }

            $recommended_ids = seo_marketing_get_recommended_category_ids(
                $source_type,
                $source_id,
                SEO_MARKETING_AUTO_CATEGORY_LIMIT
            );

            // No vaciamos selecciones cuando una rama todavía no aporta datos.
            if (empty($recommended_ids)) {
                $skipped++;
                continue;
            }

            $current_ids      = seo_marketing_get_selected_category_ids($source_type, $source_id);
            $current_compare  = $current_ids;
            $proposed_compare = $recommended_ids;
            sort($current_compare, SORT_NUMERIC);
            sort($proposed_compare, SORT_NUMERIC);

            if ($current_compare === $proposed_compare) {
                $unchanged++;
                continue;
            }

            $deleted = $wpdb->delete(
                $relations_table,
                array(
                    'source_type'   => $config['source_type'],
                    'source_id'     => $source_id,
                    'target_type'   => 'product_cat',
                    'relation_type' => $config['relation_type'],
                ),
                array('%s', '%d', '%s', '%s')
            );

            if (false === $deleted) {
                $failed = true;
                break 2;
            }

            foreach ($recommended_ids as $category_id) {
                $inserted = $wpdb->insert(
                    $relations_table,
                    array(
                        'source_type'   => $config['source_type'],
                        'source_id'     => $source_id,
                        'target_type'   => 'product_cat',
                        'target_id'     => absint($category_id),
                        'relation_type' => $config['relation_type'],
                        'created_at'    => current_time('mysql'),
                    ),
                    array('%s', '%d', '%s', '%d', '%s', '%s')
                );

                if (false === $inserted) {
                    $failed = true;
                    break 3;
                }
            }

            $updated++;
            $relations_saved += count($recommended_ids);
        }
    }

    if ($failed) {
        $wpdb->query('ROLLBACK');
        $message = 'bulk_error';
        $updated = 0;
        $relations_saved = 0;
    } else {
        $wpdb->query('COMMIT');

        if ($updated > 0) {
            $message = 'bulk_saved';
        } elseif ($unchanged > 0) {
            $message = 'bulk_unchanged';
        } else {
            $message = 'bulk_empty';
        }
    }

    wp_safe_redirect(
        seo_marketing_relations_admin_url(
            array(
                'marketing_relations_msg'       => $message,
                'marketing_bulk_updated'        => $updated,
                'marketing_bulk_unchanged'      => $unchanged,
                'marketing_bulk_skipped'        => $skipped,
                'marketing_bulk_relations'      => $relations_saved,
            )
        )
    );
    exit;
}
add_action(
    'admin_post_seo_marketing_auto_assign_all_category_relations',
    'seo_marketing_handle_auto_assign_all_category_relations'
);

/**
 * Muestra el resultado del último guardado.
 */
function seo_marketing_render_relations_notice()
{
    $message = isset($_GET['marketing_relations_msg'])
        ? sanitize_key(wp_unslash($_GET['marketing_relations_msg']))
        : '';

    if ('' === $message) {
        return;
    }

    $auto_count = isset($_GET['marketing_auto_count'])
        ? absint($_GET['marketing_auto_count'])
        : 0;
    $bulk_updated = isset($_GET['marketing_bulk_updated'])
        ? absint($_GET['marketing_bulk_updated'])
        : 0;
    $bulk_unchanged = isset($_GET['marketing_bulk_unchanged'])
        ? absint($_GET['marketing_bulk_unchanged'])
        : 0;
    $bulk_skipped = isset($_GET['marketing_bulk_skipped'])
        ? absint($_GET['marketing_bulk_skipped'])
        : 0;
    $bulk_relations = isset($_GET['marketing_bulk_relations'])
        ? absint($_GET['marketing_bulk_relations'])
        : 0;

    $messages = array(
        'saved'          => array('success', 'Categorías de marketing guardadas correctamente.'),
        'unchanged'      => array('info', 'No había cambios en las categorías seleccionadas.'),
        'auto_saved'     => array(
            'success',
            sprintf(
                'Asignación automática completada: %d categoría%s con más productos dentro de la rama.',
                $auto_count,
                1 === $auto_count ? '' : 's'
            ),
        ),
        'auto_unchanged' => array('info', 'Las categorías automáticas ya estaban asignadas.'),
        'auto_empty'     => array('warning', 'La rama no tiene categorías estructurales con productos. No se modificó la selección actual.'),
        'bulk_saved'     => array(
            'success',
            sprintf(
                'Asignación masiva completada: %1$d página%2$s actualizada%3$s, %4$d sin cambios y %5$d omitida%6$s. Se guardaron %7$d asociaciones de categorías favoritas.',
                $bulk_updated,
                1 === $bulk_updated ? '' : 's',
                1 === $bulk_updated ? '' : 's',
                $bulk_unchanged,
                $bulk_skipped,
                1 === $bulk_skipped ? '' : 's',
                $bulk_relations
            ),
        ),
        'bulk_unchanged' => array('info', 'Todas las categorías favoritas ya estaban correctamente asignadas. No había cambios que aplicar.'),
        'bulk_empty'     => array('warning', 'No se encontraron ramas con categorías estructurales y productos. No se modificó ninguna selección.'),
        'bulk_error'     => array('error', 'No se pudo completar la asignación masiva. La operación se revirtió y no se aplicaron cambios parciales.'),
        'invalid'        => array('error', 'La solicitud de guardado no es válida.'),
        'invalid_source' => array('error', 'El cluster o hub primario ya no existe o no mantiene el rol esperado.'),
        'error'          => array('error', 'No se pudieron guardar las asociaciones. No se aplicaron cambios parciales.'),
    );

    if (!isset($messages[$message])) {
        return;
    }

    echo '<div class="notice notice-' . esc_attr($messages[$message][0]) . ' is-dismissible"><p>';
    echo esc_html($messages[$message][1]);
    echo '</p></div>';
}

/**
 * Devuelve la jerarquía estructural cluster → hub primario.
 *
 * Solo consulta cluster_to_primary. Las categorías que se administran en esta
 * pantalla siguen siendo relaciones editoriales independientes.
 *
 * @return array<int,int[]>
 */
function seo_marketing_get_cluster_primary_map()
{
    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';
    $rows = $wpdb->get_results(
        "
        SELECT source_id, target_id
        FROM {$relations_table}
        WHERE source_type = 'cluster'
          AND target_type = 'hub_primary'
          AND relation_type = 'cluster_to_primary'
          AND source_id > 0
          AND target_id > 0
        ORDER BY source_id ASC, target_id ASC
        "
    );

    $map = array();

    foreach ((array) $rows as $row) {
        $cluster_id = absint($row->source_id);
        $hub_id     = absint($row->target_id);

        if ($cluster_id <= 0 || $hub_id <= 0) {
            continue;
        }

        if (!isset($map[$cluster_id])) {
            $map[$cluster_id] = array();
        }

        $map[$cluster_id][] = $hub_id;
    }

    foreach ($map as $cluster_id => $hub_ids) {
        $map[$cluster_id] = array_values(
            array_unique(
                array_filter(
                    array_map('absint', $hub_ids)
                )
            )
        );
    }

    return $map;
}

/**
 * Relaciones editoriales de categorías organizadas según la jerarquía real.
 */
function seo_marketing_render_relations_tab()
{
    seo_marketing_render_relations_notice();

    echo '<details class="seo-marketing-card" open>';
    echo '<summary style="cursor:pointer;font-weight:600;font-size:14px;">Categorías visibles en plantillas</summary>';
    echo '<div style="margin-top:12px;max-width:980px;line-height:1.6;">';
    echo '<p>Estas asociaciones determinan qué categorías aparecen como tarjetas o enlaces en los clusters y hubs primarios.</p>';
    echo '<p><strong>No modifican la jerarquía SEO.</strong> La estructura real continúa administrándose en Taxonomía: cluster → hub primario → hub secundario → categoría.</p>';
    echo '<p>La pantalla se ordena utilizando <code>cluster_to_primary</code>, pero solamente guarda <code>cluster_to_category</code> y <code>hub_primary_to_category</code>.</p>';
    echo '</div></details>';

    $clusters   = seo_marketing_get_category_relation_sources('cluster');
    $hubs       = seo_marketing_get_category_relation_sources('hub_primary');
    $categories = get_terms(
        array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        )
    );

    if (is_wp_error($categories) || empty($categories)) {
        echo '<div class="seo-marketing-card"><div class="notice notice-warning inline"><p>No hay categorías de producto disponibles.</p></div></div>';
        return;
    }

    $hub_map = array();
    foreach ((array) $hubs as $hub) {
        $hub_map[absint($hub->ID)] = $hub;
    }

    $cluster_primary_map = seo_marketing_get_cluster_primary_map();
    $assigned_hub_ids    = array();

    echo '<section class="seo-marketing-card seo-marketing-hierarchy-card">';
    echo '<h2 class="seo-marketing-hierarchy-title">Asignaciones editoriales por cluster</h2>';
    echo '<p class="seo-marketing-hierarchy-description">Cada cluster aparece como fila principal y debajo se muestran sus hubs primarios estructurales.</p>';

    echo '<div class="seo-marketing-bulk-actions">';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_marketing_auto_assign_all_category_relations">';
    wp_nonce_field('seo_marketing_auto_assign_all_category_relations');
    echo '<button type="submit" class="button button-primary" onclick="return confirm(\'Se reemplazarán las categorías de marketing de todos los clusters y hubs primarios por las ' . esc_js((string) SEO_MARKETING_AUTO_CATEGORY_LIMIT) . ' categorías con más productos de cada rama. Las ramas sin datos se conservarán. ¿Continuar?\');">Asignar todas las categorías favoritas</button>';
    echo '</form>';
    echo '<p class="description">Aplica automáticamente el top ' . esc_html((string) SEO_MARKETING_AUTO_CATEGORY_LIMIT) . ' de cada rama a todos los clusters y hubs primarios. No modifica la jerarquía.</p>';
    echo '</div>';

    echo '<div class="seo-marketing-hierarchy-columns">';
    echo '<div>Página</div>';
    echo '<div>Categorías asignadas</div>';
    echo '<div>Asignar otras categorías</div>';
    echo '<div>Guardar</div>';
    echo '</div>';

    if (empty($clusters)) {
        echo '<div class="seo-marketing-hierarchy-group"><p>No hay clusters disponibles.</p></div>';
    }

    foreach ((array) $clusters as $cluster) {
        $cluster_id = absint($cluster->ID);
        $hub_ids    = $cluster_primary_map[$cluster_id] ?? array();

        echo '<div class="seo-marketing-hierarchy-group">';
        seo_marketing_render_hierarchy_relation_row(
            'cluster',
            $cluster,
            $categories,
            'cluster'
        );

        $rendered_hubs = 0;

        foreach ($hub_ids as $hub_id) {
            $hub_id = absint($hub_id);

            if (!isset($hub_map[$hub_id])) {
                continue;
            }

            $assigned_hub_ids[$hub_id] = true;
            $rendered_hubs++;

            seo_marketing_render_hierarchy_relation_row(
                'hub_primary',
                $hub_map[$hub_id],
                $categories,
                'hub_primary'
            );
        }

        if (0 === $rendered_hubs) {
            echo '<div class="seo-marketing-hierarchy-empty">Este cluster no tiene hubs primarios estructurales asignados.</div>';
        }

        echo '</div>';
    }

    $orphan_hubs = array();
    foreach ((array) $hubs as $hub) {
        $hub_id = absint($hub->ID);

        if (!isset($assigned_hub_ids[$hub_id])) {
            $orphan_hubs[] = $hub;
        }
    }

    if (!empty($orphan_hubs)) {
        echo '<h2 class="seo-marketing-orphan-heading">Hubs primarios sin cluster</h2>';
        echo '<p class="seo-marketing-hierarchy-description">También pueden conservar categorías editoriales, aunque conviene revisar su asignación estructural en Taxonomía.</p>';

        foreach ($orphan_hubs as $hub) {
            echo '<div class="seo-marketing-hierarchy-group">';
            seo_marketing_render_hierarchy_relation_row(
                'hub_primary',
                $hub,
                $categories,
                'hub_primary'
            );
            echo '</div>';
        }
    }

    echo '</section>';

    seo_marketing_render_category_relation_script();
}

/**
 * Renderiza una fila editable de cluster o hub primario.
 *
 * @param string $source_type cluster o hub_primary.
 * @param object $source      Página origen.
 * @param array  $categories  Categorías WooCommerce.
 * @param string $level       Nivel visual.
 */
function seo_marketing_render_hierarchy_relation_row($source_type, $source, $categories, $level)
{
    $config = seo_marketing_category_relation_config($source_type);

    if (!$config || !is_object($source)) {
        return;
    }

    $source_id    = absint($source->ID);
    $selected_ids = seo_marketing_get_selected_category_ids($source_type, $source_id);
    $selected_map = array_fill_keys($selected_ids, true);
    $editor_id    = sanitize_html_class('seo-marketing-categories-' . $source_type . '-' . $source_id);
    $form_id      = sanitize_html_class('seo-marketing-form-' . $source_type . '-' . $source_id);
    $status       = sanitize_key((string) $source->post_status);
    $row_class    = 'cluster' === $level ? 'is-cluster' : 'is-hub-primary';
    $role_label   = 'cluster' === $level ? 'Cluster' : 'Hub primario';

    echo '<form id="' . esc_attr($form_id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="seo-marketing-hierarchy-row ' . esc_attr($row_class) . '">';
    echo '<input type="hidden" name="action" value="seo_marketing_save_category_relations">';
    echo '<input type="hidden" name="source_type" value="' . esc_attr($source_type) . '">';
    echo '<input type="hidden" name="source_id" value="' . esc_attr((string) $source_id) . '">';
    wp_nonce_field(
        'seo_marketing_save_category_relations_' . $source_type . '_' . $source_id
    );

    echo '<div class="seo-marketing-hierarchy-source">';
    echo '<span class="seo-marketing-hierarchy-label">' . esc_html($role_label) . '</span>';
    echo '<strong>' . esc_html($source->post_title) . '</strong>';
    echo '<code>#' . esc_html((string) $source_id) . '</code>';
    echo '<span class="seo-marketing-status seo-marketing-status-' . esc_attr($status) . '">' . esc_html(strtoupper($status)) . '</span>';
    echo '<span class="seo-marketing-hierarchy-count">' . esc_html((string) count($selected_ids)) . ' categorías asignadas</span>';
    echo '</div>';

    echo '<div>';
    echo '<span class="seo-marketing-hierarchy-label">Categorías asignadas</span>';
    echo '<div class="seo-marketing-category-list seo-marketing-category-selected">';

    if (empty($selected_ids)) {
        echo '<p class="description">No hay categorías asignadas.</p>';
    } else {
        foreach ($categories as $category) {
            $category_id = absint($category->term_id);

            if (!isset($selected_map[$category_id])) {
                continue;
            }

            echo '<label class="seo-marketing-category-item">';
            echo '<input type="checkbox" name="category_ids[]" value="' . esc_attr((string) $category_id) . '" checked> ';
            echo '<span>' . esc_html($category->name) . '</span> ';
            echo '<small>#' . esc_html((string) $category_id) . '</small>';
            echo '</label>';
        }
    }

    echo '</div>';
    echo '</div>';

    echo '<div>';
    echo '<span class="seo-marketing-hierarchy-label">Asignar otras categorías</span>';
    echo '<input type="search" class="regular-text seo-marketing-category-search" data-category-target="' . esc_attr($editor_id) . '" placeholder="Buscar categoría...">';
    echo '<div id="' . esc_attr($editor_id) . '" class="seo-marketing-category-list seo-marketing-category-available">';

    $available_count = 0;
    foreach ($categories as $category) {
        $category_id = absint($category->term_id);

        if (isset($selected_map[$category_id])) {
            continue;
        }

        $available_count++;
        echo '<label class="seo-marketing-category-item">';
        echo '<input type="checkbox" name="category_ids[]" value="' . esc_attr((string) $category_id) . '"> ';
        echo '<span>' . esc_html($category->name) . '</span> ';
        echo '<small>#' . esc_html((string) $category_id) . '</small>';
        echo '</label>';
    }

    if (0 === $available_count) {
        echo '<p class="description">Todas las categorías están asignadas.</p>';
    }

    echo '</div>';
    echo '</div>';

    echo '<div class="seo-marketing-hierarchy-actions">';
    echo '<button type="submit" class="button button-primary">Guardar selección</button>';
    echo '<button type="submit" class="button" name="seo_marketing_auto_assign" value="1" onclick="return confirm(\'Se reemplazará la selección actual por las ' . esc_js((string) SEO_MARKETING_AUTO_CATEGORY_LIMIT) . ' categorías con más productos dentro de esta rama. ¿Continuar?\');">Asignar automáticamente ' . esc_html((string) SEO_MARKETING_AUTO_CATEGORY_LIMIT) . '</button>';
    echo '<code>' . esc_html($config['relation_type']) . '</code>';
    echo '<span class="description">El automático usa únicamente categorías estructurales de esta rama y no modifica la jerarquía.</span>';
    echo '</div>';

    echo '</form>';
}

/**
 * Buscador local para las listas de categorías disponibles.
 */
function seo_marketing_render_category_relation_script()
{
    ?>
    <script>
    document.addEventListener('input', function (event) {
        if (!event.target.matches('.seo-marketing-category-search')) {
            return;
        }

        var targetId = event.target.getAttribute('data-category-target');
        var container = targetId ? document.getElementById(targetId) : null;

        if (!container) {
            return;
        }

        var query = event.target.value.toLowerCase().trim();
        var items = container.querySelectorAll('.seo-marketing-category-item');

        items.forEach(function (item) {
            var text = (item.textContent || '').toLowerCase();
            item.style.display = text.indexOf(query) !== -1 ? 'block' : 'none';
        });
    });
    </script>
    <?php
}

/**
 * Procesa la creación manual de sitemaps.
 *
 * @return array|null
 */
function seo_marketing_maybe_create_sitemaps()
{
    if (!isset($_POST['seo_create_sitemaps'])) {
        return null;
    }

    if (!current_user_can('manage_options')) {
        return array('type' => 'error', 'message' => 'No tienes permisos para crear sitemaps.');
    }

    check_admin_referer('seo_create_sitemaps_action', 'seo_create_sitemaps_nonce');

    return seo_marketing_create_sitemaps();
}

/**
 * @param array|null $notice
 */
function seo_marketing_render_sitemaps_tab($notice)
{
    $report   = seo_marketing_get_sitemap_report();
    $main_url = seo_marketing_sitemap_public_url('sitemap.xml');

    echo '<div class="seo-marketing-card">';
    echo '<h2>Sitemaps XML</h2>';
    echo '<p>El índice profesional se publica en <a href="' . esc_url($main_url) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html($main_url) . '</code></a>.</p>';
    echo '<p>Los archivos físicos permanecen protegidos en <code>/wp-content/uploads/seo-sitemaps/</code>, pero Google y otros rastreadores reciben URLs estables desde la raíz del dominio.</p>';

    if (is_array($notice)) {
        $notice_type = isset($notice['type']) ? sanitize_key($notice['type']) : 'error';
        $class = $notice_type === 'success' ? 'notice-success' : ($notice_type === 'warning' ? 'notice-warning' : 'notice-error');
        echo '<div class="notice ' . esc_attr($class) . ' inline"><p>' . wp_kses_post($notice['message']) . '</p></div>';
    }

    if ($report['main_valid']) {
        echo '<div class="notice notice-success inline"><p><strong>Índice principal válido.</strong> Se han contabilizado ' . esc_html(number_format_i18n($report['total_urls'])) . ' URLs publicadas.</p></div>';
    } else {
        echo '<div class="notice notice-warning inline"><p><strong>El índice principal todavía no es válido o no existe.</strong> Pulsa “Regenerar y validar sitemaps”.</p></div>';
    }

    echo '<form method="post" style="margin:18px 0;">';
    wp_nonce_field('seo_create_sitemaps_action', 'seo_create_sitemaps_nonce');
    echo '<button type="submit" name="seo_create_sitemaps" value="1" class="button button-primary">Regenerar y validar sitemaps</button>';
    echo '</form>';

    echo '<details style="margin:16px 0;">';
    echo '<summary style="cursor:pointer;font-weight:600;">Cómo funciona este sitemap</summary>';
    echo '<div style="padding:14px 0;max-width:980px;line-height:1.65;">';
    echo '<p>El sistema separa productos, categorías, entradas del blog, páginas, clusters y hubs. Los productos se dividen en lotes para evitar archivos excesivamente grandes.</p>';
    echo '<p>Solo se incluyen contenidos publicados y sin contraseña. Las páginas de carrito, pago y cuenta se excluyen. Las páginas estructurales no se duplican dentro del sitemap general de páginas.</p>';
    echo '<p>Cada XML se valida antes de publicarse. Si una generación falla, se conserva la versión válida anterior. Para Search Console utiliza únicamente <code>' . esc_html($main_url) . '</code>.</p>';
    echo '</div></details>';

    if (empty($report['files'])) {
        echo '<p>No hay archivos generados.</p>';
        echo '</div>';
        return;
    }

    echo '<table class="widefat striped" style="margin-top:16px;">';
    echo '<thead><tr><th>Archivo</th><th>Estado</th><th>Entradas</th><th>Tamaño</th><th>Modificado</th></tr></thead><tbody>';

    foreach ($report['files'] as $file) {
        echo '<tr>';
        echo '<td><a href="' . esc_url($file['url']) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html($file['filename']) . '</code></a></td>';
        echo '<td>' . ($file['valid'] ? '<span style="color:#1d6b43;font-weight:700;">Válido</span>' : '<span style="color:#c62828;font-weight:700;">Inválido</span>') . '</td>';
        echo '<td>' . esc_html(number_format_i18n($file['count'])) . '</td>';
        echo '<td>' . esc_html(size_format($file['bytes'], 1)) . '</td>';
        echo '<td>' . esc_html(wp_date('d/m/Y H:i', $file['modified'])) . '</td>';
        echo '</tr>';

        if (!$file['valid'] && $file['error'] !== '') {
            echo '<tr><td colspan="5"><span style="color:#c62828;">' . esc_html($file['error']) . '</span></td></tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Pestaña de estilo visual.
 */
function seo_marketing_render_style_tab()
{
    $settings = seo_marketing_style_get_settings();
    $record = seo_marketing_style_get_record();

    seo_marketing_render_style_notice();

    echo '<div class="seo-marketing-card">';
    echo '<h2>Estilo visual de las plantillas públicas</h2>';
    echo '<p>Personaliza navegación, clusters, hubs, categorías, productos, tarjetas, FAQs y pie de página sin editar CSS. El sistema solo publica propiedades validadas y conserva <code>styles_template.css</code> como base.</p>';
    echo '<p><strong>Estado:</strong> ' . ($record['exists'] ? 'configuración personalizada publicada' : 'se utilizan los valores originales de la hoja CSS') . '.</p>';
    echo '<p>Cada guardado queda registrado en el Centro de Operaciones y puede revertirse si no existen cambios posteriores.</p>';
    echo '</div>';

    echo '<div class="seo-style-layout">';
    echo '<div>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="seo-marketing-style-form">';
    echo '<input type="hidden" name="action" value="seo_marketing_style_save">';
    wp_nonce_field('seo_marketing_style_save');

    seo_marketing_render_style_colors($settings);
    seo_marketing_render_style_typography($settings);
    seo_marketing_render_style_components($settings);
    seo_marketing_render_style_products($settings);
    seo_marketing_render_style_solutions($settings);
    seo_marketing_render_style_faq($settings);
    seo_marketing_render_style_menu($settings);
    seo_marketing_render_style_footer($settings);

    echo '<div class="seo-style-actions">';
    echo '<button type="submit" class="button button-primary">Guardar y publicar estilo</button>';
    $export_url = wp_nonce_url(
        admin_url('admin-post.php?action=seo_marketing_style_export'),
        'seo_marketing_style_export'
    );
    echo '<a class="button" href="' . esc_url($export_url) . '">Exportar JSON</a>';
    echo '<span style="color:#646970;">La vista previa no modifica el frontal hasta guardar.</span>';
    echo '</div>';
    echo '</form>';

    echo '<div class="seo-style-section seo-style-json">';
    echo '<h2>Importar configuración JSON</h2>';
    echo '<p>Pega un JSON exportado por este módulo. Los valores desconocidos se ignoran y todos los campos se validan.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_marketing_style_import">';
    wp_nonce_field('seo_marketing_style_import');
    echo '<textarea name="style_json" placeholder="{ &quot;schema_version&quot;: 5, &quot;values&quot;: { ... } }"></textarea>';
    echo '<p><button type="submit" class="button">Importar y publicar</button></p>';
    echo '</form>';
    echo '</div>';

    echo '<div class="seo-style-section">';
    echo '<h2>Restaurar diseño original</h2>';
    echo '<p>Publica los valores originales de <code>styles_template.css</code>. La restauración también queda auditada y es reversible.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'¿Restaurar todos los valores visuales predeterminados?\');">';
    echo '<input type="hidden" name="action" value="seo_marketing_style_reset">';
    wp_nonce_field('seo_marketing_style_reset');
    echo '<button type="submit" class="button">Restaurar valores predeterminados</button>';
    echo '</form>';
    echo '</div>';

    echo '</div>';

    echo '<aside class="seo-style-preview-wrap">';
    seo_marketing_render_style_preview($settings);
    echo '</aside>';
    echo '</div>';

    seo_marketing_render_style_preview_script();
}

/**
 * Avisos de acciones de estilo.
 */
function seo_marketing_render_style_notice()
{
    $message = isset($_GET['style_msg']) ? sanitize_key(wp_unslash($_GET['style_msg'])) : '';
    if ($message === '') {
        return;
    }

    $operation_id = isset($_GET['operation_id']) ? absint($_GET['operation_id']) : 0;
    $messages = array(
        'saved'        => array('success', 'Estilo visual guardado y publicado.'),
        'reset'        => array('success', 'Valores visuales predeterminados restaurados.'),
        'imported'     => array('success', 'Configuración JSON importada y publicada.'),
        'unchanged'    => array('info', 'No había cambios que guardar.'),
        'invalid_json' => array('error', 'El JSON indicado no es válido.'),
        'error'        => array('error', 'No se pudo completar la operación. Revisa el registro de errores.'),
    );

    if (!isset($messages[$message])) {
        return;
    }

    $type = $messages[$message][0];
    $text = $messages[$message][1];
    if ($operation_id > 0) {
        $text .= ' Operación auditable: #' . $operation_id . '.';
    }

    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
}

/**
 * @param string $name
 * @param string $label
 * @param string $value
 * @param string $description
 */
function seo_marketing_style_color_field($name, $label, $value, $description = '')
{
    echo '<div class="seo-style-field">';
    echo '<label for="seo-style-' . esc_attr($name) . '">' . esc_html($label) . '</label>';
    echo '<div class="seo-style-color">';
    echo '<input type="color" id="seo-style-' . esc_attr($name) . '" name="settings[' . esc_attr($name) . ']" value="' . esc_attr($value) . '" data-preview-key="' . esc_attr($name) . '">';
    echo '<code data-color-label="' . esc_attr($name) . '">' . esc_html($value) . '</code>';
    echo '</div>';
    if ($description !== '') {
        echo '<small>' . esc_html($description) . '</small>';
    }
    echo '</div>';
}

/**
 * @param string $name
 * @param string $label
 * @param mixed  $value
 * @param float  $minimum
 * @param float  $maximum
 * @param float  $step
 * @param string $description
 */
function seo_marketing_style_number_field($name, $label, $value, $minimum, $maximum, $step = 1, $description = '')
{
    echo '<div class="seo-style-field">';
    echo '<label for="seo-style-' . esc_attr($name) . '">' . esc_html($label) . '</label>';
    echo '<input type="number" id="seo-style-' . esc_attr($name) . '" name="settings[' . esc_attr($name) . ']" value="' . esc_attr((string) $value) . '" min="' . esc_attr((string) $minimum) . '" max="' . esc_attr((string) $maximum) . '" step="' . esc_attr((string) $step) . '" data-preview-key="' . esc_attr($name) . '">';
    if ($description !== '') {
        echo '<small>' . esc_html($description) . '</small>';
    }
    echo '</div>';
}

/**
 * @param string $name
 * @param string $label
 * @param string $value
 * @param array  $options
 * @param string $description
 */
function seo_marketing_style_select_field($name, $label, $value, $options, $description = '')
{
    echo '<div class="seo-style-field">';
    echo '<label for="seo-style-' . esc_attr($name) . '">' . esc_html($label) . '</label>';
    echo '<select id="seo-style-' . esc_attr($name) . '" name="settings[' . esc_attr($name) . ']" data-preview-key="' . esc_attr($name) . '">';
    foreach ($options as $option_value => $option_label) {
        echo '<option value="' . esc_attr($option_value) . '" ' . selected($value, $option_value, false) . '>' . esc_html($option_label) . '</option>';
    }
    echo '</select>';
    if ($description !== '') {
        echo '<small>' . esc_html($description) . '</small>';
    }
    echo '</div>';
}

/**
 * @param array $settings
 */
function seo_marketing_render_style_colors($settings)
{
    echo '<section class="seo-style-section"><h2>1. Colores del sistema</h2><div class="seo-style-fields">';
    seo_marketing_style_color_field('primary', 'Color principal', $settings['primary']);
    seo_marketing_style_color_field('primary_dark', 'Principal oscuro / hover', $settings['primary_dark']);
    seo_marketing_style_color_field('secondary', 'Color secundario', $settings['secondary']);
    seo_marketing_style_color_field('dark', 'Fondo oscuro', $settings['dark']);
    seo_marketing_style_color_field('dark_soft', 'Fondo oscuro degradado', $settings['dark_soft']);
    seo_marketing_style_color_field('background', 'Fondo gris', $settings['background']);
    seo_marketing_style_color_field('background_light', 'Fondo claro', $settings['background_light']);
    seo_marketing_style_color_field('text', 'Texto principal', $settings['text']);
    seo_marketing_style_color_field('text_soft', 'Texto secundario', $settings['text_soft']);
    seo_marketing_style_color_field('border', 'Bordes', $settings['border']);
    seo_marketing_style_color_field('hero_title', 'Título sobre fondo oscuro', $settings['hero_title']);
    seo_marketing_style_color_field('hero_text', 'Texto sobre fondo oscuro', $settings['hero_text']);
    echo '</div></section>';
}

/**
 * @param array $settings
 */
function seo_marketing_render_style_typography($settings)
{
    $font_options = array();
    foreach (seo_marketing_style_font_choices() as $key => $choice) {
        $font_options[$key] = $choice['label'];
    }

    echo '<section class="seo-style-section"><h2>2. Tipografía y encabezados</h2><div class="seo-style-fields">';
    seo_marketing_style_select_field('font_body', 'Fuente del texto', $settings['font_body'], $font_options);
    seo_marketing_style_select_field('font_headings', 'Fuente de títulos', $settings['font_headings'], $font_options);
    seo_marketing_style_number_field('body_size', 'Tamaño del texto (px)', $settings['body_size'], 13, 22);
    seo_marketing_style_number_field('body_line_height', 'Interlineado general', $settings['body_line_height'], 1.2, 2.2, 0.05);
    seo_marketing_style_number_field('paragraph_line_height', 'Interlineado de párrafos', $settings['paragraph_line_height'], 1.2, 2.4, 0.05);
    seo_marketing_style_number_field('h1_min', 'H1 mínimo (px)', $settings['h1_min'], 24, 64);
    seo_marketing_style_number_field('h1_max', 'H1 máximo (px)', $settings['h1_max'], 30, 90);
    seo_marketing_style_number_field('h1_weight', 'Peso H1', $settings['h1_weight'], 400, 900, 100);
    seo_marketing_style_color_field('h1_color', 'Color H1 normal', $settings['h1_color']);
    seo_marketing_style_number_field('h2_min', 'H2 mínimo (px)', $settings['h2_min'], 20, 52);
    seo_marketing_style_number_field('h2_max', 'H2 máximo (px)', $settings['h2_max'], 24, 72);
    seo_marketing_style_number_field('h2_weight', 'Peso H2', $settings['h2_weight'], 400, 900, 100);
    seo_marketing_style_color_field('h2_color', 'Color H2', $settings['h2_color']);
    seo_marketing_style_number_field('h3_size', 'Tamaño H3 (px)', $settings['h3_size'], 16, 40);
    seo_marketing_style_number_field('h3_weight', 'Peso H3', $settings['h3_weight'], 400, 900, 100);
    seo_marketing_style_color_field('h3_color', 'Color H3', $settings['h3_color']);
    seo_marketing_style_select_field('heading_align', 'Alineación de títulos', $settings['heading_align'], array('left' => 'Izquierda', 'center' => 'Centro'));
    seo_marketing_style_select_field('heading_transform', 'Mayúsculas en títulos', $settings['heading_transform'], array('none' => 'No', 'uppercase' => 'Sí'));
    echo '</div>';

    echo '<div style="margin-top:18px;display:grid;gap:12px;">';
    echo '<label><input type="checkbox" name="settings[custom_links]" value="1" ' . checked($settings['custom_links'], 1, false) . ' data-preview-key="custom_links"> Aplicar color personalizado a enlaces dentro del contenido</label>';
    echo '<div class="seo-style-fields">';
    seo_marketing_style_color_field('link_color', 'Color de enlace', $settings['link_color']);
    seo_marketing_style_color_field('link_hover', 'Color de enlace al pasar', $settings['link_hover']);
    seo_marketing_style_select_field('link_decoration', 'Subrayado de enlaces', $settings['link_decoration'], array('none' => 'Sin subrayado', 'underline' => 'Subrayado'));
    echo '</div>';
    echo '<label><input type="checkbox" name="settings[custom_strong]" value="1" ' . checked($settings['custom_strong'], 1, false) . ' data-preview-key="custom_strong"> Aplicar color personalizado a negritas</label>';
    echo '<div class="seo-style-fields">';
    seo_marketing_style_color_field('strong_color', 'Color de negritas', $settings['strong_color']);
    echo '</div></div>';
    echo '</section>';
}

/**
 * @param array $settings
 */
function seo_marketing_render_style_components($settings)
{
    $shadow_options = array();
    foreach (seo_marketing_style_shadow_choices() as $key => $choice) {
        $shadow_options[$key] = $choice['label'];
    }

    echo '<section class="seo-style-section"><h2>3. Componentes y dimensiones</h2><div class="seo-style-fields">';
    seo_marketing_style_number_field('container_width', 'Ancho máximo general (px)', $settings['container_width'], 960, 1800);
    seo_marketing_style_number_field('content_width', 'Ancho máximo de lectura (px)', $settings['content_width'], 640, 1400);
    seo_marketing_style_number_field('section_spacing', 'Espaciado vertical de secciones (px)', $settings['section_spacing'], 30, 130);
    seo_marketing_style_number_field('radius_small', 'Radio pequeño (px)', $settings['radius_small'], 0, 40);
    seo_marketing_style_number_field('radius', 'Radio medio (px)', $settings['radius'], 0, 50);
    seo_marketing_style_number_field('radius_large', 'Radio grande (px)', $settings['radius_large'], 0, 70);
    seo_marketing_style_select_field('shadow_preset', 'Sombras', $settings['shadow_preset'], $shadow_options);
    seo_marketing_style_number_field('button_height', 'Altura mínima de botón (px)', $settings['button_height'], 34, 76);
    seo_marketing_style_number_field('button_padding_x', 'Relleno horizontal botón (px)', $settings['button_padding_x'], 10, 52);
    seo_marketing_style_color_field('button_primary_bg', 'Fondo botón secundario', $settings['button_primary_bg']);
    seo_marketing_style_color_field('button_primary_text', 'Texto botón secundario', $settings['button_primary_text']);
    seo_marketing_style_color_field('button_blue_bg', 'Fondo botón principal', $settings['button_blue_bg']);
    seo_marketing_style_color_field('button_blue_hover', 'Hover botón principal', $settings['button_blue_hover']);
    seo_marketing_style_color_field('button_blue_text', 'Texto botón principal', $settings['button_blue_text']);
    seo_marketing_style_color_field('card_background', 'Fondo de tarjetas', $settings['card_background']);
    seo_marketing_style_number_field('card_padding', 'Relleno de tarjetas (px)', $settings['card_padding'], 10, 60);
    seo_marketing_style_number_field('card_image_height', 'Altura de imagen en tarjeta (px)', $settings['card_image_height'], 120, 420);
    echo '</div></section>';
}

/**
 * @param array $settings
 */
function seo_marketing_render_style_products($settings)
{
    echo '<section class="seo-style-section"><h2>4. Productos WooCommerce</h2>';

    echo '<h3 style="margin:0 0 12px;">Cuadrículas y listados</h3>';
    echo '<div class="seo-style-fields">';
    seo_marketing_style_number_field('products_columns_desktop', 'Columnas escritorio', $settings['products_columns_desktop'], 1, 6);
    seo_marketing_style_number_field('products_columns_tablet', 'Columnas tablet grande', $settings['products_columns_tablet'], 1, 5);
    seo_marketing_style_number_field('products_columns_small', 'Columnas tablet pequeña', $settings['products_columns_small'], 1, 4);
    seo_marketing_style_number_field('products_columns_mobile', 'Columnas móvil', $settings['products_columns_mobile'], 1, 2);
    seo_marketing_style_number_field('product_image_height', 'Altura imagen producto (px)', $settings['product_image_height'], 120, 420);
    seo_marketing_style_number_field('product_image_mobile', 'Altura imagen móvil (px)', $settings['product_image_mobile'], 100, 320);
    seo_marketing_style_number_field(
        'product_title_size',
        'Título en tarjetas/listados (px)',
        $settings['product_title_size'],
        13,
        28,
        1,
        'Controla .woocommerce-loop-product__title; no modifica el H1 de la ficha individual.'
    );
    seo_marketing_style_color_field('product_price_color', 'Color del precio', $settings['product_price_color']);
    seo_marketing_style_color_field('product_button_bg', 'Fondo botón producto', $settings['product_button_bg']);
    seo_marketing_style_color_field('product_button_hover', 'Hover botón producto', $settings['product_button_hover']);
    seo_marketing_style_color_field('product_button_text', 'Texto botón producto', $settings['product_button_text']);
    echo '</div>';

    echo '<h3 style="margin:24px 0 12px;">Ficha individual</h3>';
    echo '<p style="margin-top:0;color:#646970;">Estos valores controlan exclusivamente <code>h1.dh-product-title</code>, sin cambiar los H1 de portada, categorías, hubs o páginas.</p>';
    echo '<div class="seo-style-fields">';
    seo_marketing_style_number_field('product_page_title_min', 'Título mínimo (px)', $settings['product_page_title_min'], 18, 48, 1, 'Tamaño mínimo en pantallas estrechas.');
    seo_marketing_style_number_field('product_page_title_max', 'Título máximo (px)', $settings['product_page_title_max'], 22, 64, 1, 'Límite máximo en escritorio.');
    seo_marketing_style_number_field('product_page_title_weight', 'Peso del título', $settings['product_page_title_weight'], 400, 900, 100);
    seo_marketing_style_number_field('product_page_title_line_height', 'Interlineado del título', $settings['product_page_title_line_height'], 1, 1.8, 0.05);
    echo '</div>';

    echo '</section>';
}

/**
 * @param array $settings
 */
function seo_marketing_render_style_solutions($settings)
{
    echo '<section class="seo-style-section"><h2>5. Índice de Soluciones</h2>';
    echo '<p>Controla la parrilla que presenta las páginas con rol <code>landing</code> en <code>template-soluciones.php</code>. La plantilla decide el contenido; aquí solo se define su presentación.</p>';
    echo '<div class="seo-style-fields">';
    seo_marketing_style_number_field('solutions_columns_desktop', 'Columnas escritorio', $settings['solutions_columns_desktop'], 1, 5);
    seo_marketing_style_number_field('solutions_columns_tablet', 'Columnas tablet', $settings['solutions_columns_tablet'], 1, 4);
    seo_marketing_style_number_field('solutions_columns_mobile', 'Columnas móvil', $settings['solutions_columns_mobile'], 1, 2);
    seo_marketing_style_number_field('solutions_grid_gap', 'Separación entre tarjetas (px)', $settings['solutions_grid_gap'], 8, 60);
    seo_marketing_style_number_field('solutions_image_height', 'Altura de imagen (px)', $settings['solutions_image_height'], 100, 420);
    seo_marketing_style_number_field('solutions_title_size', 'Título de cada solución (px)', $settings['solutions_title_size'], 14, 36);
    echo '</div></section>';
}

/**
 * @param array $settings
 */
function seo_marketing_render_style_faq($settings)
{
    echo '<section class="seo-style-section"><h2>6. FAQs</h2><div class="seo-style-fields">';
    seo_marketing_style_color_field('faq_section_bg', 'Fondo de la sección FAQ', $settings['faq_section_bg']);
    seo_marketing_style_color_field('faq_item_bg', 'Fondo de cada FAQ', $settings['faq_item_bg']);
    seo_marketing_style_color_field('faq_question_color', 'Color de pregunta', $settings['faq_question_color']);
    seo_marketing_style_color_field('faq_answer_color', 'Color de respuesta', $settings['faq_answer_color']);
    seo_marketing_style_color_field('faq_border_color', 'Color del borde', $settings['faq_border_color']);
    seo_marketing_style_color_field('faq_icon_color', 'Color del icono + / −', $settings['faq_icon_color']);
    seo_marketing_style_number_field('faq_radius', 'Radio del acordeón (px)', $settings['faq_radius'], 0, 50);
    echo '</div></section>';
}

/**
 * @param array $settings
 */
function seo_marketing_render_style_menu($settings)
{
    $shadow_options = array();
    foreach (seo_marketing_style_shadow_choices() as $key => $choice) {
        $shadow_options[$key] = $choice['label'];
    }

    echo '<section class="seo-style-section"><h2>7. Navegación principal</h2>';
    echo '<p>Configura el aspecto del menú y de sus desplegables. La estructura, el comportamiento responsive y la accesibilidad permanecen controlados por la hoja base.</p>';
    echo '<div class="seo-style-fields">';
    seo_marketing_style_select_field('menu_style', 'Tipo visual', $settings['menu_style'], array(
        'soft'      => 'Suave',
        'pills'     => 'Píldoras',
        'underline' => 'Subrayado',
        'minimal'   => 'Minimalista',
    ));
    seo_marketing_style_color_field('menu_background', 'Fondo del menú', $settings['menu_background']);
    seo_marketing_style_color_field('menu_text', 'Texto principal', $settings['menu_text']);
    seo_marketing_style_color_field('menu_hover', 'Texto al pasar / foco', $settings['menu_hover']);
    seo_marketing_style_color_field('menu_active_text', 'Texto del elemento activo', $settings['menu_active_text']);
    seo_marketing_style_color_field('menu_active_background', 'Fondo del elemento activo', $settings['menu_active_background']);
    seo_marketing_style_color_field('menu_dropdown_background', 'Fondo del desplegable', $settings['menu_dropdown_background']);
    seo_marketing_style_color_field('menu_dropdown_text', 'Texto del desplegable', $settings['menu_dropdown_text']);
    seo_marketing_style_color_field('menu_dropdown_hover_bg', 'Fondo hover del desplegable', $settings['menu_dropdown_hover_bg']);
    seo_marketing_style_color_field('menu_dropdown_hover_text', 'Texto hover del desplegable', $settings['menu_dropdown_hover_text']);
    seo_marketing_style_color_field('menu_border', 'Bordes del menú', $settings['menu_border']);
    seo_marketing_style_number_field('menu_font_size', 'Tamaño del texto (px)', $settings['menu_font_size'], 11, 22);
    seo_marketing_style_number_field('menu_font_weight', 'Peso de la fuente', $settings['menu_font_weight'], 400, 900, 100);
    seo_marketing_style_number_field('menu_height', 'Altura mínima (px)', $settings['menu_height'], 38, 82);
    seo_marketing_style_number_field('menu_padding_x', 'Relleno horizontal (px)', $settings['menu_padding_x'], 6, 36);
    seo_marketing_style_number_field('menu_radius', 'Radio de menú y desplegable (px)', $settings['menu_radius'], 0, 40);
    seo_marketing_style_number_field('menu_dropdown_min_width', 'Ancho mínimo desplegable (px)', $settings['menu_dropdown_min_width'], 180, 520);
    seo_marketing_style_select_field('menu_shadow_preset', 'Sombra del desplegable', $settings['menu_shadow_preset'], $shadow_options);
    seo_marketing_style_select_field('menu_transform', 'Mayúsculas', $settings['menu_transform'], array('none' => 'No', 'uppercase' => 'Sí'));
    seo_marketing_style_select_field('menu_animation', 'Animación del desplegable', $settings['menu_animation'], array('none' => 'Sin animación', 'fade' => 'Fundido', 'slide' => 'Deslizamiento'));
    echo '</div>';
    echo '<p style="margin-top:16px;"><input type="hidden" name="settings[menu_indicator]" value="0"><label><input type="checkbox" name="settings[menu_indicator]" value="1" ' . checked($settings['menu_indicator'], 1, false) . ' data-preview-key="menu_indicator"> Mostrar indicador en elementos con submenú</label></p>';
    echo '</section>';
}

/**
 * @param array $settings
 */
function seo_marketing_render_style_footer($settings)
{
    echo '<section class="seo-style-section"><h2>8. Pie de pagina</h2>';
    echo '<p>Controla el aspecto del <code>footer.php</code> compartido: fondo, textos, enlaces, separadores, espaciado y logotipo. La estructura y los enlaces permanecen en la plantilla.</p>';
    echo '<div class="seo-style-fields">';
    seo_marketing_style_color_field('footer_background', 'Fondo del pie', $settings['footer_background']);
    seo_marketing_style_color_field('footer_heading_color', 'Titulos del pie', $settings['footer_heading_color']);
    seo_marketing_style_color_field('footer_text_color', 'Texto secundario', $settings['footer_text_color']);
    seo_marketing_style_color_field('footer_link_color', 'Color de enlaces', $settings['footer_link_color']);
    seo_marketing_style_color_field('footer_link_hover', 'Enlace al pasar / foco', $settings['footer_link_hover']);
    seo_marketing_style_color_field('footer_meta_color', 'Texto legal / inferior', $settings['footer_meta_color']);
    seo_marketing_style_color_field('footer_border_color', 'Separadores y bordes', $settings['footer_border_color']);
    seo_marketing_style_number_field('footer_padding_top', 'Espacio superior (px)', $settings['footer_padding_top'], 16, 120);
    seo_marketing_style_number_field('footer_padding_bottom', 'Espacio inferior (px)', $settings['footer_padding_bottom'], 12, 100);
    seo_marketing_style_number_field('footer_gap', 'Separacion entre columnas (px)', $settings['footer_gap'], 8, 72);
    seo_marketing_style_number_field('footer_heading_size', 'Tamano de titulos (px)', $settings['footer_heading_size'], 12, 28);
    seo_marketing_style_number_field('footer_text_size', 'Tamano de texto (px)', $settings['footer_text_size'], 11, 22);
    seo_marketing_style_number_field('footer_logo_width', 'Ancho del logotipo (px)', $settings['footer_logo_width'], 90, 300);
    echo '</div></section>';
}

/**
 * @param array $settings
 */
function seo_marketing_render_style_preview($settings)
{
    $body_stack = seo_marketing_style_font_stack($settings['font_body']);
    $heading_stack = seo_marketing_style_font_stack($settings['font_headings']);

    $style = array(
        '--preview-primary:' . $settings['primary'],
        '--preview-primary-dark:' . $settings['primary_dark'],
        '--preview-secondary:' . $settings['secondary'],
        '--preview-dark:' . $settings['dark'],
        '--preview-dark-soft:' . $settings['dark_soft'],
        '--preview-bg:' . $settings['background'],
        '--preview-bg-light:' . $settings['background_light'],
        '--preview-text:' . $settings['text'],
        '--preview-text-soft:' . $settings['text_soft'],
        '--preview-border:' . $settings['border'],
        '--preview-radius-small:' . (int) $settings['radius_small'] . 'px',
        '--preview-radius:' . (int) $settings['radius'] . 'px',
        '--preview-radius-large:' . (int) $settings['radius_large'] . 'px',
        '--preview-card:' . $settings['card_background'],
        '--preview-faq:' . $settings['faq_item_bg'],
        '--preview-faq-section:' . $settings['faq_section_bg'],
        '--preview-product-title-min:' . (int) $settings['product_page_title_min'] . 'px',
        '--preview-product-title-max:' . (int) $settings['product_page_title_max'] . 'px',
        '--preview-product-title-weight:' . (int) $settings['product_page_title_weight'],
        '--preview-product-title-line-height:' . (float) $settings['product_page_title_line_height'],
        '--preview-menu-bg:' . $settings['menu_background'],
        '--preview-menu-text:' . $settings['menu_text'],
        '--preview-menu-hover:' . $settings['menu_hover'],
        '--preview-menu-active-text:' . $settings['menu_active_text'],
        '--preview-menu-active-bg:' . $settings['menu_active_background'],
        '--preview-menu-dropdown-bg:' . $settings['menu_dropdown_background'],
        '--preview-menu-dropdown-text:' . $settings['menu_dropdown_text'],
        '--preview-menu-dropdown-hover-bg:' . $settings['menu_dropdown_hover_bg'],
        '--preview-menu-dropdown-hover-text:' . $settings['menu_dropdown_hover_text'],
        '--preview-menu-border:' . $settings['menu_border'],
        '--preview-menu-size:' . (int) $settings['menu_font_size'] . 'px',
        '--preview-menu-weight:' . (int) $settings['menu_font_weight'],
        '--preview-menu-height:' . (int) $settings['menu_height'] . 'px',
        '--preview-menu-padding:' . (int) $settings['menu_padding_x'] . 'px',
        '--preview-menu-radius:' . (int) $settings['menu_radius'] . 'px',
        '--preview-menu-dropdown-width:' . (int) $settings['menu_dropdown_min_width'] . 'px',
        '--preview-menu-transform:' . $settings['menu_transform'],
        '--preview-solutions-columns:' . (int) $settings['solutions_columns_desktop'],
        '--preview-solutions-gap:' . (int) $settings['solutions_grid_gap'] . 'px',
        '--preview-solutions-image-height:' . (int) $settings['solutions_image_height'] . 'px',
        '--preview-solutions-title-size:' . (int) $settings['solutions_title_size'] . 'px',
        '--preview-footer-bg:' . $settings['footer_background'],
        '--preview-footer-heading:' . $settings['footer_heading_color'],
        '--preview-footer-text:' . $settings['footer_text_color'],
        '--preview-footer-link:' . $settings['footer_link_color'],
        '--preview-footer-link-hover:' . $settings['footer_link_hover'],
        '--preview-footer-meta:' . $settings['footer_meta_color'],
        '--preview-footer-border:' . $settings['footer_border_color'],
        '--preview-footer-gap:' . (int) $settings['footer_gap'] . 'px',
        '--preview-footer-heading-size:' . (int) $settings['footer_heading_size'] . 'px',
        '--preview-footer-text-size:' . (int) $settings['footer_text_size'] . 'px',
        '--preview-footer-logo-width:' . (int) $settings['footer_logo_width'] . 'px',
        'font-family:' . $body_stack,
        'font-size:' . (int) $settings['body_size'] . 'px',
        'line-height:' . (float) $settings['body_line_height'],
    );

    echo '<div class="seo-style-preview" id="seo-style-preview" style="' . esc_attr(implode(';', $style)) . '">';
    echo '<div class="seo-style-preview-menu" id="seo-preview-menu" data-menu-style="' . esc_attr($settings['menu_style']) . '">';
    echo '<span class="seo-style-preview-menu-item is-active">Inicio</span>';
    echo '<span class="seo-style-preview-menu-item is-parent">Categorías <span class="seo-style-preview-menu-indicator" id="seo-preview-menu-indicator"' . (empty($settings['menu_indicator']) ? ' style="display:none"' : '') . '>⌄</span><span class="seo-style-preview-dropdown" id="seo-preview-dropdown"><span>Herramientas de taller</span><span>Jardín y exterior</span><span>Seguridad laboral</span></span></span>';
    echo '<span class="seo-style-preview-menu-item">Contacto</span>';
    echo '</div>';
    echo '<div class="seo-style-preview-hero">';
    echo '<h1 id="seo-preview-h1" style="font-family:' . esc_attr($heading_stack) . ';font-size:' . (int) $settings['h1_min'] . 'px;font-weight:' . (int) $settings['h1_weight'] . ';color:' . esc_attr($settings['hero_title']) . ';text-align:' . esc_attr($settings['heading_align']) . ';text-transform:' . esc_attr($settings['heading_transform']) . ';">Ejemplo de página</h1>';
    echo '<p id="seo-preview-hero-text" style="color:' . esc_attr($settings['hero_text']) . ';">Así se verá el encabezado de un cluster o hub.</p>';
    echo '</div>';
    echo '<div class="seo-style-preview-body">';
    echo '<h2 id="seo-preview-h2" style="font-family:' . esc_attr($heading_stack) . ';font-size:' . (int) $settings['h2_min'] . 'px;font-weight:' . (int) $settings['h2_weight'] . ';color:' . esc_attr($settings['h2_color']) . ';text-align:' . esc_attr($settings['heading_align']) . ';text-transform:' . esc_attr($settings['heading_transform']) . ';">Título de sección</h2>';
    echo '<p id="seo-preview-paragraph">Texto de ejemplo con un <a id="seo-preview-link" href="#" onclick="return false;">enlace</a> y una <strong id="seo-preview-strong">idea importante</strong>.</p>';
    echo '<div class="seo-style-preview-product-detail"><small>Ficha individual de producto</small><div class="seo-style-preview-product-title" id="seo-preview-product-title" style="font-family:' . esc_attr($heading_stack) . ';">Brocas de barrena para plantar bulbos y perforar jardines</div></div>';
    echo '<button type="button" class="seo-style-preview-button" id="seo-preview-button">Ver productos</button>';
    echo '<div class="seo-style-preview-card" id="seo-preview-card">';
    echo '<div class="seo-style-preview-image" id="seo-preview-image">🛠️</div>';
    echo '<div class="seo-style-preview-card-content" id="seo-preview-card-content">';
    echo '<h3 id="seo-preview-h3" style="font-family:' . esc_attr($heading_stack) . ';font-size:' . (int) $settings['h3_size'] . 'px;font-weight:' . (int) $settings['h3_weight'] . ';color:' . esc_attr($settings['h3_color']) . ';text-align:' . esc_attr($settings['heading_align']) . ';text-transform:' . esc_attr($settings['heading_transform']) . ';">Tarjeta de producto</h3>';
    echo '<p>Descripción breve del producto o categoría.</p>';
    echo '<div class="seo-style-preview-price" id="seo-preview-price">166,90 €</div>';
    echo '</div></div>';
    echo '<div class="seo-style-preview-solutions" id="seo-preview-solutions">';
    echo '<div class="seo-style-preview-solution"><div class="seo-style-preview-solution-image">⚡</div><div class="seo-style-preview-solution-body"><strong>Herramientas para electricistas</strong><small>Solución profesional</small></div></div>';
    echo '<div class="seo-style-preview-solution"><div class="seo-style-preview-solution-image">🔧</div><div class="seo-style-preview-solution-body"><strong>Equipar un taller mecánico</strong><small>Solución profesional</small></div></div>';
    echo '<div class="seo-style-preview-solution"><div class="seo-style-preview-solution-image">📏</div><div class="seo-style-preview-solution-body"><strong>Cómo elegir herramientas</strong><small>Guía de elección</small></div></div>';
    echo '</div>';
    echo '<div class="seo-style-preview-faq" id="seo-preview-faq">';
    echo '<div class="seo-style-preview-faq-item" id="seo-preview-faq-item">';
    echo '<strong id="seo-preview-faq-question">¿Qué opción me conviene?</strong>';
    echo '<p id="seo-preview-faq-answer" style="margin:8px 0 0;">Una respuesta clara y útil para el cliente.</p>';
    echo '</div></div>';
    echo '</div>';
    echo '<div class="seo-style-preview-footer" id="seo-preview-footer">';
    echo '<div class="seo-style-preview-footer-grid">';
    echo '<div class="seo-style-preview-footer-brand"><div class="seo-style-preview-footer-logo" id="seo-preview-footer-logo">DHT</div><strong>Distribuidor de Herramientas</strong><p>Herramientas y equipamiento tecnico para profesionales y empresas.</p><a href="#" onclick="return false;">servicioacliente@ejemplo.es</a></div>';
    echo '<div><h3>Empresa</h3><a href="#" onclick="return false;">Nosotros</a><a href="#" onclick="return false;">Contacto</a></div>';
    echo '<div><h3>Atencion</h3><a href="#" onclick="return false;">Devoluciones</a><a href="#" onclick="return false;">Privacidad</a></div>';
    echo '<div><h3>Servicio en Espana</h3><p>Peninsula, Baleares y Canarias.</p><div class="seo-style-preview-footer-domain">Dominio oficial<br><strong>www.ejemplo.es</strong></div></div>';
    echo '</div><div class="seo-style-preview-footer-meta">© 2026 Distribuidor de Herramientas · Distribucion especializada en Espana.</div>';
    echo '</div></div>';
}

/**
 * Vista previa reactiva en administración.
 */
function seo_marketing_render_style_preview_script()
{
    $font_stacks = array();
    foreach (seo_marketing_style_font_choices() as $key => $choice) {
        $font_stacks[$key] = $choice['stack'];
    }

    $menu_shadows = array();
    foreach (seo_marketing_style_shadow_choices() as $key => $choice) {
        $menu_shadows[$key] = $choice['normal'];
    }

    echo '<script>
    (function(){
        const form = document.getElementById("seo-marketing-style-form");
        const preview = document.getElementById("seo-style-preview");
        if (!form || !preview) return;

        const fontStacks = ' . wp_json_encode($font_stacks) . ';
        const menuShadows = ' . wp_json_encode($menu_shadows) . ';
        const get = key => form.querySelector("[name=\"settings[" + key + "]\"]");
        const value = key => {
            const el = get(key);
            if (!el) return "";
            if (el.type === "checkbox") return el.checked;
            return el.value;
        };
        const px = key => String(value(key) || 0) + "px";

        function update(){
            const vars = {
                "--preview-primary": value("primary"),
                "--preview-primary-dark": value("primary_dark"),
                "--preview-secondary": value("secondary"),
                "--preview-dark": value("dark"),
                "--preview-dark-soft": value("dark_soft"),
                "--preview-bg": value("background"),
                "--preview-bg-light": value("background_light"),
                "--preview-text": value("text"),
                "--preview-text-soft": value("text_soft"),
                "--preview-border": value("border"),
                "--preview-radius-small": px("radius_small"),
                "--preview-radius": px("radius"),
                "--preview-radius-large": px("radius_large"),
                "--preview-card": value("card_background"),
                "--preview-faq": value("faq_item_bg"),
                "--preview-faq-section": value("faq_section_bg"),
                "--preview-product-title-min": px("product_page_title_min"),
                "--preview-product-title-max": px("product_page_title_max"),
                "--preview-product-title-weight": value("product_page_title_weight"),
                "--preview-product-title-line-height": value("product_page_title_line_height"),
                "--preview-footer-bg": value("footer_background"),
                "--preview-footer-heading": value("footer_heading_color"),
                "--preview-footer-text": value("footer_text_color"),
                "--preview-footer-link": value("footer_link_color"),
                "--preview-footer-link-hover": value("footer_link_hover"),
                "--preview-footer-meta": value("footer_meta_color"),
                "--preview-footer-border": value("footer_border_color"),
                "--preview-footer-gap": px("footer_gap"),
                "--preview-footer-heading-size": px("footer_heading_size"),
                "--preview-footer-text-size": px("footer_text_size"),
                "--preview-footer-logo-width": px("footer_logo_width")
            };
            Object.keys(vars).forEach(key => preview.style.setProperty(key, vars[key]));

            preview.style.fontFamily = fontStacks[value("font_body")] || "inherit";
            preview.style.fontSize = px("body_size");
            preview.style.lineHeight = value("body_line_height");

            const headingFont = fontStacks[value("font_headings")] || "inherit";
            const h1 = document.getElementById("seo-preview-h1");
            const h2 = document.getElementById("seo-preview-h2");
            const h3 = document.getElementById("seo-preview-h3");
            const productPageTitle = document.getElementById("seo-preview-product-title");
            [h1,h2,h3].forEach(el => {
                el.style.fontFamily = headingFont;
                el.style.textAlign = value("heading_align");
                el.style.textTransform = value("heading_transform");
            });
            if (productPageTitle) {
                productPageTitle.style.fontFamily = headingFont;
            }
            h1.style.fontSize = px("h1_min");
            h1.style.fontWeight = value("h1_weight");
            h1.style.color = value("hero_title");
            h2.style.fontSize = px("h2_min");
            h2.style.fontWeight = value("h2_weight");
            h2.style.color = value("h2_color");
            h3.style.fontSize = px("h3_size");
            h3.style.fontWeight = value("h3_weight");
            h3.style.color = value("h3_color");

            document.getElementById("seo-preview-hero-text").style.color = value("hero_text");
            document.getElementById("seo-preview-paragraph").style.lineHeight = value("paragraph_line_height");

            const link = document.getElementById("seo-preview-link");
            link.style.color = value("custom_links") ? value("link_color") : "inherit";
            link.style.textDecoration = value("custom_links") ? value("link_decoration") : "none";
            const strong = document.getElementById("seo-preview-strong");
            strong.style.color = value("custom_strong") ? value("strong_color") : "inherit";

            const button = document.getElementById("seo-preview-button");
            button.style.minHeight = px("button_height");
            button.style.paddingLeft = px("button_padding_x");
            button.style.paddingRight = px("button_padding_x");
            button.style.background = value("button_blue_bg");
            button.style.color = value("button_blue_text");

            document.getElementById("seo-preview-card-content").style.padding = px("card_padding");
            document.getElementById("seo-preview-image").style.height = px("card_image_height");
            document.getElementById("seo-preview-price").style.color = value("product_price_color");
            document.getElementById("seo-preview-faq-question").style.color = value("faq_question_color");
            document.getElementById("seo-preview-faq-answer").style.color = value("faq_answer_color");
            document.getElementById("seo-preview-faq-item").style.borderColor = value("faq_border_color");
            document.getElementById("seo-preview-faq-item").style.borderRadius = px("faq_radius");

            const menu = document.getElementById("seo-preview-menu");
            const dropdown = document.getElementById("seo-preview-dropdown");
            menu.dataset.menuStyle = value("menu_style") || "soft";
            menu.style.setProperty("--preview-menu-bg", value("menu_background"));
            menu.style.setProperty("--preview-menu-text", value("menu_text"));
            menu.style.setProperty("--preview-menu-hover", value("menu_hover"));
            menu.style.setProperty("--preview-menu-active-text", value("menu_active_text"));
            menu.style.setProperty("--preview-menu-active-bg", value("menu_active_background"));
            menu.style.setProperty("--preview-menu-dropdown-bg", value("menu_dropdown_background"));
            menu.style.setProperty("--preview-menu-dropdown-text", value("menu_dropdown_text"));
            menu.style.setProperty("--preview-menu-dropdown-hover-bg", value("menu_dropdown_hover_bg"));
            menu.style.setProperty("--preview-menu-dropdown-hover-text", value("menu_dropdown_hover_text"));
            menu.style.setProperty("--preview-menu-border", value("menu_border"));
            menu.style.setProperty("--preview-menu-size", px("menu_font_size"));
            menu.style.setProperty("--preview-menu-weight", value("menu_font_weight"));
            menu.style.setProperty("--preview-menu-height", px("menu_height"));
            menu.style.setProperty("--preview-menu-padding", px("menu_padding_x"));
            menu.style.setProperty("--preview-menu-radius", px("menu_radius"));
            menu.style.setProperty("--preview-menu-dropdown-width", px("menu_dropdown_min_width"));
            menu.style.setProperty("--preview-menu-transform", value("menu_transform"));
            dropdown.style.boxShadow = menuShadows[value("menu_shadow_preset")] || "none";
            document.getElementById("seo-preview-menu-indicator").style.display = value("menu_indicator") ? "inline" : "none";

            const footer = document.getElementById("seo-preview-footer");
            if (footer) {
                footer.style.paddingTop = px("footer_padding_top");
                footer.style.paddingBottom = px("footer_padding_bottom");
            }

            form.querySelectorAll("input[type=color][data-preview-key]").forEach(el => {
                const label = document.querySelector("[data-color-label=\"" + el.dataset.previewKey + "\"]");
                if (label) label.textContent = el.value;
            });
        }

        form.addEventListener("input", update);
        form.addEventListener("change", update);
        update();
    })();
    </script>';
}

/**
 * Devuelve la configuración del almacenamiento de sitemaps.
 *
 * Los XML se guardan en uploads, pero se publican mediante URLs estables en
 * la raíz del dominio: /sitemap.xml, /sitemap-products-1.xml, etc.
 *
 * @return array|WP_Error
 */
function seo_marketing_sitemap_storage()
{
    $upload = wp_upload_dir();

    if (!empty($upload['error'])) {
        return new WP_Error(
            'seo_sitemap_upload_error',
            'No se puede utilizar el directorio de uploads: ' . $upload['error']
        );
    }

    return array(
        'dir'     => trailingslashit($upload['basedir']) . 'seo-sitemaps/',
        'baseurl' => trailingslashit($upload['baseurl']) . 'seo-sitemaps/',
    );
}

/**
 * Nombres admitidos para evitar traversal y servir únicamente XML generados.
 *
 * @param string $filename
 * @return bool
 */
function seo_marketing_sitemap_filename_is_allowed($filename)
{
    return (bool) preg_match(
        '/\Asitemap(?:[_-][a-z0-9]+)*(?:-\d+)?\.xml\z/',
        strtolower((string) $filename)
    );
}

/**
 * URL pública canónica de un sitemap.
 *
 * @param string $filename
 * @return string
 */
function seo_marketing_sitemap_public_url($filename)
{
    $filename = basename((string) $filename);

    if (!seo_marketing_sitemap_filename_is_allowed($filename)) {
        return '';
    }

    return home_url('/' . $filename);
}

/**
 * Registra las URLs públicas de los sitemaps.
 */
function seo_marketing_register_sitemap_rewrite_rules()
{
    // Índice principal, alias históricos y sitemaps hijos generados.
    add_rewrite_rule(
        '^(wp-sitemap\.xml|sitemap(?:[_-][a-z0-9-]+)?\.xml)$',
        'index.php?seo_marketing_sitemap=$matches[1]',
        'top'
    );
}
add_action('init', 'seo_marketing_register_sitemap_rewrite_rules', 1);

/**
 * Desactiva el sitemap nativo de WordPress. Este módulo es la única fuente
 * pública de sitemaps y mantiene /wp-sitemap.xml como alias compatible.
 *
 * @return bool
 */
function seo_marketing_disable_wordpress_core_sitemaps()
{
    return false;
}
add_filter('wp_sitemaps_enabled', 'seo_marketing_disable_wordpress_core_sitemaps', 100);

/**
 * @param string[] $query_vars
 * @return string[]
 */
function seo_marketing_register_sitemap_query_var($query_vars)
{
    $query_vars[] = 'seo_marketing_sitemap';
    return array_values(array_unique($query_vars));
}
add_filter('query_vars', 'seo_marketing_register_sitemap_query_var');

/**
 * Actualiza las reglas una sola vez cuando cambia la versión del endpoint.
 */
function seo_marketing_maybe_flush_sitemap_rewrite_rules()
{
    $stored_version = (string) get_option('seo_marketing_sitemap_rewrite_version', '');

    if ($stored_version === (string) SEO_MARKETING_SITEMAP_REWRITE_VERSION) {
        return;
    }

    seo_marketing_register_sitemap_rewrite_rules();
    flush_rewrite_rules(false);
    update_option(
        'seo_marketing_sitemap_rewrite_version',
        (string) SEO_MARKETING_SITEMAP_REWRITE_VERSION,
        false
    );
}
add_action('admin_init', 'seo_marketing_maybe_flush_sitemap_rewrite_rules', 20);

/**
 * Obtiene el nombre solicitado incluso si las reglas todavía no se han
 * refrescado. Esto evita que /sitemap.xml dependa de una visita previa al admin.
 *
 * @return string
 */
function seo_marketing_get_requested_sitemap_filename()
{
    $filename = (string) get_query_var('seo_marketing_sitemap');

    if ($filename === '' && isset($_SERVER['REQUEST_URI'])) {
        $request_path = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $home_path    = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        $request_path = '/' . ltrim($request_path, '/');
        $home_path    = '/' . trim($home_path, '/');

        if ($home_path !== '/' && strpos($request_path, $home_path . '/') === 0) {
            $request_path = substr($request_path, strlen($home_path));
        }

        $candidate = strtolower(basename(trim($request_path, '/')));

        if (
            'wp-sitemap.xml' === $candidate
            || in_array($candidate, array('sitemap-index.xml', 'sitemap_index.xml'), true)
        ) {
            $filename = 'sitemap.xml';
        } elseif (seo_marketing_sitemap_filename_is_allowed($candidate)) {
            $filename = $candidate;
        }
    }

    $filename = strtolower(basename($filename));

    if (
        'wp-sitemap.xml' === $filename
        || in_array($filename, array('sitemap-index.xml', 'sitemap_index.xml'), true)
    ) {
        return 'sitemap.xml';
    }

    return seo_marketing_sitemap_filename_is_allowed($filename) ? $filename : '';
}

/**
 * Sirve el XML con cabeceras correctas y sin salida del tema o de otros hooks.
 */
function seo_marketing_maybe_serve_sitemap()
{
    $filename = seo_marketing_get_requested_sitemap_filename();

    if ($filename === '') {
        return;
    }

    $storage = seo_marketing_sitemap_storage();
    if (is_wp_error($storage)) {
        status_header(503);
        exit;
    }

    $file_path = $storage['dir'] . $filename;

    if (!is_file($file_path) || !is_readable($file_path)) {
        status_header(404);
        nocache_headers();
        exit;
    }

    $mtime = (int) filemtime($file_path);
    $size  = (int) filesize($file_path);
    $etag  = '"' . md5($filename . '|' . $mtime . '|' . $size) . '"';

    $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH'])
        ? trim((string) wp_unslash($_SERVER['HTTP_IF_NONE_MATCH']))
        : '';
    $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
        ? strtotime((string) wp_unslash($_SERVER['HTTP_IF_MODIFIED_SINCE']))
        : false;

    if ($if_none_match === $etag || ($if_modified_since && $if_modified_since >= $mtime)) {
        status_header(304);
        header('ETag: ' . $etag);
        exit;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    status_header(200);
    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, follow');
    header('Cache-Control: public, max-age=300, stale-while-revalidate=86400');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('ETag: ' . $etag);

    readfile($file_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
    exit;
}
add_action('template_redirect', 'seo_marketing_maybe_serve_sitemap', 0);

/**
 * Declara el índice principal en robots.txt sin duplicarlo.
 *
 * @param string $output
 * @param bool   $public
 * @return string
 */
function seo_marketing_add_sitemap_to_robots($output, $public)
{
    if (!$public) {
        return $output;
    }

    $canonical_url = seo_marketing_sitemap_public_url('sitemap.xml');
    $lines         = preg_split('/\r\n|\r|\n/', (string) $output);
    $clean_lines   = array();

    foreach ((array) $lines as $existing_line) {
        $trimmed = trim((string) $existing_line);

        if (preg_match('/^Sitemap:\s*(.+)$/i', $trimmed, $matches)) {
            $sitemap_path = (string) wp_parse_url(trim($matches[1]), PHP_URL_PATH);
            $basename     = strtolower(basename($sitemap_path));

            // Elimina únicamente nuestros alias y el sitemap nativo para no
            // publicar dos índices distintos en robots.txt.
            if (in_array(
                $basename,
                array('wp-sitemap.xml', 'sitemap.xml', 'sitemap-index.xml', 'sitemap_index.xml'),
                true
            )) {
                continue;
            }
        }

        $clean_lines[] = $existing_line;
    }

    $output = rtrim(implode("\n", $clean_lines));
    $output .= ($output !== '' ? "\n\n" : '') . 'Sitemap: ' . $canonical_url . "\n";

    return $output;
}
add_filter('robots_txt', 'seo_marketing_add_sitemap_to_robots', 20, 2);

/**
 * Elimina caracteres no permitidos por XML 1.0 y escapa entidades.
 *
 * @param mixed $value
 * @return string
 */
function seo_marketing_xml_escape($value)
{
    $value = (string) $value;

    if (function_exists('wp_check_invalid_utf8')) {
        $value = wp_check_invalid_utf8($value, true);
    }

    $cleaned = preg_replace(
        '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
        '',
        $value
    );

    if (is_string($cleaned)) {
        $value = $cleaned;
    }

    return htmlspecialchars(
        $value,
        ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Normaliza una URL del sitio y descarta hosts externos o esquemas inválidos.
 *
 * @param string $url
 * @return string
 */
function seo_marketing_sitemap_normalize_url($url)
{
    $url = esc_url_raw((string) $url, array('http', 'https'));

    if ($url === '') {
        return '';
    }

    $parts      = wp_parse_url($url);
    $home_parts = wp_parse_url(home_url('/'));

    if (
        !is_array($parts)
        || empty($parts['scheme'])
        || empty($parts['host'])
        || !is_array($home_parts)
        || empty($home_parts['host'])
        || strtolower($parts['host']) !== strtolower($home_parts['host'])
    ) {
        return '';
    }

    return preg_replace('/#.*$/', '', $url);
}

/**
 * Normaliza una fecha al formato W3C empleado por sitemaps.
 *
 * @param mixed $value
 * @return string
 */
function seo_marketing_sitemap_normalize_lastmod($value)
{
    if ($value === '' || $value === null) {
        return '';
    }

    $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);

    if ($timestamp <= 0) {
        return '';
    }

    return gmdate('c', $timestamp);
}

/**
 * Limpia, deduplica y ordena entradas antes de construir el XML.
 *
 * @param array $urls
 * @return array
 */
function seo_marketing_prepare_sitemap_urls($urls)
{
    $prepared = array();
    $seen     = array();

    foreach ((array) $urls as $url) {
        if (!is_array($url) || empty($url['loc'])) {
            continue;
        }

        $loc = seo_marketing_sitemap_normalize_url($url['loc']);
        if ($loc === '' || isset($seen[$loc])) {
            continue;
        }

        $entry = array(
            'loc'     => $loc,
            'lastmod' => seo_marketing_sitemap_normalize_lastmod(
                isset($url['lastmod']) ? $url['lastmod'] : ''
            ),
            'images'  => array(),
        );

        $image_seen = array();
        foreach ((array) (isset($url['images']) ? $url['images'] : array()) as $image_url) {
            $image_url = esc_url_raw((string) $image_url, array('http', 'https'));
            if ($image_url === '' || isset($image_seen[$image_url])) {
                continue;
            }

            $image_parts = wp_parse_url($image_url);
            if (!is_array($image_parts) || empty($image_parts['host'])) {
                continue;
            }

            $image_seen[$image_url] = true;
            $entry['images'][]      = $image_url;

            if (count($entry['images']) >= 5) {
                break;
            }
        }

        $seen[$loc]   = true;
        $prepared[]   = $entry;
    }

    usort(
        $prepared,
        static function ($left, $right) {
            return strcmp($left['loc'], $right['loc']);
        }
    );

    return $prepared;
}

/**
 * @param array $urls
 * @return string
 */
function seo_marketing_build_urlset_xml($urls)
{
    $urls       = seo_marketing_prepare_sitemap_urls($urls);
    $has_images = false;

    foreach ($urls as $url) {
        if (!empty($url['images'])) {
            $has_images = true;
            break;
        }
    }

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
    if ($has_images) {
        $xml .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
    }
    $xml .= ">\n";

    foreach ($urls as $url) {
        $xml .= "  <url>\n";
        $xml .= '    <loc>' . seo_marketing_xml_escape($url['loc']) . "</loc>\n";

        if ($url['lastmod'] !== '') {
            $xml .= '    <lastmod>' . seo_marketing_xml_escape($url['lastmod']) . "</lastmod>\n";
        }

        foreach ($url['images'] as $image_url) {
            $xml .= "    <image:image>\n";
            $xml .= '      <image:loc>' . seo_marketing_xml_escape($image_url) . "</image:loc>\n";
            $xml .= "    </image:image>\n";
        }

        $xml .= "  </url>\n";
    }

    $xml .= "</urlset>\n";
    return $xml;
}

/**
 * @param array $files
 * @return string
 */
function seo_marketing_build_sitemap_index_xml($files)
{
    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ((array) $files as $file) {
        if (empty($file['filename'])) {
            continue;
        }

        $loc = seo_marketing_sitemap_public_url($file['filename']);
        if ($loc === '') {
            continue;
        }

        $xml .= "  <sitemap>\n";
        $xml .= '    <loc>' . seo_marketing_xml_escape($loc) . "</loc>\n";

        $lastmod = seo_marketing_sitemap_normalize_lastmod(
            isset($file['lastmod']) ? $file['lastmod'] : ''
        );
        if ($lastmod !== '') {
            $xml .= '    <lastmod>' . seo_marketing_xml_escape($lastmod) . "</lastmod>\n";
        }

        $xml .= "  </sitemap>\n";
    }

    $xml .= "</sitemapindex>\n";
    return $xml;
}

/**
 * Valida XML, raíz y namespace. También cuenta las entradas publicadas.
 *
 * @param string $xml
 * @param string $expected_root
 * @return array{valid:bool,root:string,count:int,error:string}
 */
function seo_marketing_validate_xml_string($xml, $expected_root = '')
{
    $result = array(
        'valid' => false,
        'root'  => '',
        'count' => 0,
        'error' => '',
    );

    if (!is_string($xml) || trim($xml) === '') {
        $result['error'] = 'El archivo está vacío.';
        return $result;
    }

    if (class_exists('DOMDocument')) {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom   = new DOMDocument('1.0', 'UTF-8');
        $flags = 0;
        if (defined('LIBXML_NONET')) {
            $flags |= LIBXML_NONET;
        }
        if (defined('LIBXML_NOBLANKS')) {
            $flags |= LIBXML_NOBLANKS;
        }

        $loaded = $dom->loadXML($xml, $flags);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$dom->documentElement) {
            $first_error = !empty($errors) ? trim($errors[0]->message) : 'XML no analizable.';
            $result['error'] = $first_error;
            return $result;
        }

        $root      = $dom->documentElement->localName;
        $namespace = $dom->documentElement->namespaceURI;

        if ($expected_root !== '' && $root !== $expected_root) {
            $result['error'] = 'Raíz inesperada: ' . $root . '.';
            return $result;
        }

        if ($namespace !== 'http://www.sitemaps.org/schemas/sitemap/0.9') {
            $result['error'] = 'Namespace de sitemap ausente o incorrecto.';
            return $result;
        }

        $result['valid'] = true;
        $result['root']  = $root;
        $result['count'] = $root === 'sitemapindex'
            ? $dom->getElementsByTagNameNS($namespace, 'sitemap')->length
            : $dom->getElementsByTagNameNS($namespace, 'url')->length;

        return $result;
    }

    $root = preg_match('/<sitemapindex\b/i', $xml) ? 'sitemapindex' : (
        preg_match('/<urlset\b/i', $xml) ? 'urlset' : ''
    );

    if ($root === '' || ($expected_root !== '' && $root !== $expected_root)) {
        $result['error'] = 'No se pudo confirmar la raíz XML.';
        return $result;
    }

    $closing = $root === 'sitemapindex' ? '</sitemapindex>' : '</urlset>';
    if (stripos($xml, $closing) === false) {
        $result['error'] = 'Falta la etiqueta de cierre principal.';
        return $result;
    }

    $result['valid'] = true;
    $result['root']  = $root;
    $result['count'] = $root === 'sitemapindex'
        ? preg_match_all('/<sitemap>/i', $xml)
        : preg_match_all('/<url>/i', $xml);

    return $result;
}

/**
 * @param string $file_path
 * @param string $expected_root
 * @return array{valid:bool,root:string,count:int,error:string}
 */
function seo_marketing_validate_xml_file($file_path, $expected_root = '')
{
    if (!is_file($file_path) || !is_readable($file_path)) {
        return array(
            'valid' => false,
            'root'  => '',
            'count' => 0,
            'error' => 'El archivo no existe o no es legible.',
        );
    }

    $xml = file_get_contents($file_path);
    return seo_marketing_validate_xml_string($xml, $expected_root);
}

/**
 * Escribe y valida un XML dentro del directorio temporal de construcción.
 *
 * @param string $file_path
 * @param string $xml
 * @param string $expected_root
 * @return array|WP_Error
 */
function seo_marketing_write_validated_xml($file_path, $xml, $expected_root)
{
    $validation = seo_marketing_validate_xml_string($xml, $expected_root);
    if (!$validation['valid']) {
        return new WP_Error('seo_sitemap_invalid_xml', $validation['error']);
    }

    $bytes = file_put_contents($file_path, $xml, LOCK_EX);
    if ($bytes === false) {
        return new WP_Error('seo_sitemap_write_error', 'No se pudo escribir ' . basename($file_path) . '.');
    }

    @chmod($file_path, 0644);

    $file_validation = seo_marketing_validate_xml_file($file_path, $expected_root);
    if (!$file_validation['valid']) {
        @unlink($file_path);
        return new WP_Error('seo_sitemap_invalid_written_xml', $file_validation['error']);
    }

    $file_validation['bytes'] = (int) $bytes;
    return $file_validation;
}

/**
 * Publica un archivo de forma atómica para no dejar XML parciales visibles.
 *
 * @param string $source
 * @param string $target
 * @return bool
 */
function seo_marketing_publish_sitemap_file($source, $target)
{
    $temporary = $target . '.tmp-' . wp_generate_uuid4();

    if (!copy($source, $temporary)) {
        return false;
    }

    @chmod($temporary, 0644);

    if (@rename($temporary, $target)) {
        return true;
    }

    if (is_file($target)) {
        @unlink($target);
    }

    $renamed = @rename($temporary, $target);
    if (!$renamed && is_file($temporary)) {
        @unlink($temporary);
    }

    return $renamed;
}

/**
 * @param string $dir
 */
function seo_marketing_remove_directory($dir)
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            seo_marketing_remove_directory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

/**
 * @param string[] $roles
 * @return int[]
 */
function seo_marketing_get_node_page_ids_by_roles($roles)
{
    global $wpdb;

    $roles = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $roles))));
    if (empty($roles)) {
        return array();
    }

    $nodes_table = $wpdb->prefix . 'seo_nodes';
    $table_exists = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $nodes_table)
    );

    if ($table_exists !== $nodes_table) {
        return array();
    }

    $placeholders = implode(', ', array_fill(0, count($roles), '%s'));
    $sql = "
        SELECT DISTINCT object_id
        FROM {$nodes_table}
        WHERE object_type = 'page'
          AND seo_role IN ({$placeholders})
          AND status = 1
          AND object_id > 0
        ORDER BY object_id ASC
    ";

    $prepared = $wpdb->prepare($sql, $roles);

    return array_values(
        array_unique(
            array_filter(array_map('absint', (array) $wpdb->get_col($prepared)))
        )
    );
}

/**
 * @param string $post_type
 * @return int[]
 */
function seo_marketing_get_published_post_ids($post_type)
{
    global $wpdb;

    $post_type = sanitize_key($post_type);
    if ($post_type === '') {
        return array();
    }

    return array_map(
        'absint',
        (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status = 'publish'
                   AND post_password = ''
                 ORDER BY ID ASC",
                $post_type
            )
        )
    );
}

/**
 * Páginas de sistema que no deben enviarse al índice de búsqueda.
 *
 * @return int[]
 */
function seo_marketing_get_excluded_page_ids_for_sitemap()
{
    $ids = array();

    if (function_exists('wc_get_page_id')) {
        foreach (array('cart', 'checkout', 'myaccount') as $page_key) {
            $page_id = (int) wc_get_page_id($page_key);
            if ($page_id > 0) {
                $ids[] = $page_id;
            }
        }
    }

    $ids = array_merge(
        $ids,
        seo_marketing_get_node_page_ids_by_roles(
            array('cluster', 'hub_primary', 'hub_secondary')
        )
    );

    return array_values(array_unique(array_filter(array_map('absint', $ids))));
}

/**
 * Convierte posts a entradas de sitemap. Los productos pueden incorporar su
 * imagen destacada mediante la extensión oficial de imágenes.
 *
 * @param array $ids
 * @param bool  $include_images
 * @return array
 */
function seo_marketing_posts_to_sitemap_urls($ids, $include_images = false)
{
    $ids  = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    $urls = array();

    foreach (array_chunk($ids, 500) as $id_chunk) {
        $posts = get_posts(
            array(
                'post_type'              => 'any',
                'post_status'            => 'publish',
                'post__in'               => $id_chunk,
                'posts_per_page'         => count($id_chunk),
                'orderby'                => 'post__in',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => (bool) $include_images,
                'suppress_filters'       => false,
            )
        );

        foreach ((array) $posts as $post) {
            if (
                !$post
                || $post->post_status !== 'publish'
                || $post->post_password !== ''
            ) {
                continue;
            }

            $include = apply_filters('seo_marketing_sitemap_include_post', true, $post);
            if (!$include) {
                continue;
            }

            $url = get_permalink($post->ID);
            $url = apply_filters('seo_marketing_sitemap_post_url', $url, $post);
            $url = seo_marketing_sitemap_normalize_url($url);

            if ($url === '') {
                continue;
            }

            $entry = array(
                'loc'     => $url,
                'lastmod' => get_post_modified_time('c', true, $post),
                'images'  => array(),
            );

            if ($include_images) {
                $thumbnail_id = get_post_thumbnail_id($post->ID);
                if ($thumbnail_id) {
                    $image_url = wp_get_attachment_image_url($thumbnail_id, 'full');
                    if ($image_url) {
                        $entry['images'][] = $image_url;
                    }
                }
            }

            $entry['images'] = apply_filters(
                'seo_marketing_sitemap_post_images',
                $entry['images'],
                $post
            );

            $urls[] = $entry;
        }
    }

    return seo_marketing_prepare_sitemap_urls($urls);
}

/**
 * Calcula en una única consulta la modificación más reciente de los productos
 * directamente asociados a cada categoría.
 *
 * @return array<int,string>
 */
function seo_marketing_get_product_category_lastmods()
{
    global $wpdb;

    $rows = $wpdb->get_results(
        "SELECT tt.term_id, MAX(p.post_modified_gmt) AS lastmod
         FROM {$wpdb->term_taxonomy} tt
         INNER JOIN {$wpdb->term_relationships} tr
            ON tr.term_taxonomy_id = tt.term_taxonomy_id
         INNER JOIN {$wpdb->posts} p
            ON p.ID = tr.object_id
         WHERE tt.taxonomy = 'product_cat'
           AND p.post_type = 'product'
           AND p.post_status = 'publish'
           AND p.post_password = ''
         GROUP BY tt.term_id"
    );

    $lastmods = array();
    foreach ((array) $rows as $row) {
        $term_id  = absint($row->term_id);
        $timestamp = !empty($row->lastmod)
            ? strtotime($row->lastmod . ' UTC')
            : false;

        if ($term_id > 0 && $timestamp) {
            $lastmods[$term_id] = gmdate('c', $timestamp);
        }
    }

    return $lastmods;
}

/**
 * @return array
 */
function seo_marketing_get_product_category_sitemap_urls()
{
    $terms = get_terms(
        array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'orderby'    => 'term_id',
            'order'      => 'ASC',
        )
    );

    if (is_wp_error($terms)) {
        return array();
    }

    $lastmods = seo_marketing_get_product_category_lastmods();
    $urls     = array();

    foreach ((array) $terms as $term) {
        if (!$term || empty($term->term_id)) {
            continue;
        }

        $include = apply_filters('seo_marketing_sitemap_include_term', true, $term);
        if (!$include) {
            continue;
        }

        $link = get_term_link($term);
        if (is_wp_error($link)) {
            continue;
        }

        $urls[] = array(
            'loc'     => $link,
            'lastmod' => isset($lastmods[$term->term_id]) ? $lastmods[$term->term_id] : '',
            'images'  => array(),
        );
    }

    return seo_marketing_prepare_sitemap_urls($urls);
}

/**
 * @param array $ids
 * @return int[]
 */
function seo_marketing_filter_generic_page_ids_for_sitemap($ids)
{
    $excluded_ids   = array_fill_keys(seo_marketing_get_excluded_page_ids_for_sitemap(), true);
    $excluded_slugs = array(
        'carrito', 'finalizar-compra', 'mi-cuenta', 'checkout', 'cart',
        'my-account',
    );
    $filtered = array();

    foreach ((array) $ids as $id) {
        $id = absint($id);
        if ($id <= 0 || isset($excluded_ids[$id])) {
            continue;
        }

        $post = get_post($id);
        if (!$post || in_array($post->post_name, $excluded_slugs, true)) {
            continue;
        }

        $filtered[] = $id;
    }

    return $filtered;
}

/**
 * Crea uno o varios archivos de un grupo, respetando el tamaño de lote.
 *
 * @param string $build_dir
 * @param string $group_slug
 * @param array  $urls
 * @param array  $files
 * @param array  $errors
 */
function seo_marketing_create_sitemap_group($build_dir, $group_slug, $urls, &$files, &$errors)
{
    $urls = seo_marketing_prepare_sitemap_urls($urls);
    if (empty($urls)) {
        return;
    }

    $batch_size = (int) apply_filters(
        'seo_marketing_sitemap_batch_size',
        SEO_MARKETING_SITEMAP_BATCH_SIZE,
        $group_slug
    );
    $batch_size = max(100, min(50000, $batch_size));
    $chunks     = array_chunk($urls, $batch_size);
    $generated  = gmdate('c');

    foreach ($chunks as $index => $chunk) {
        $filename = 'sitemap-' . sanitize_title($group_slug) . '-' . ($index + 1) . '.xml';
        $xml      = seo_marketing_build_urlset_xml($chunk);
        $written  = seo_marketing_write_validated_xml(
            $build_dir . $filename,
            $xml,
            'urlset'
        );

        if (is_wp_error($written)) {
            $errors[] = $filename . ': ' . $written->get_error_message();
            continue;
        }

        $files[] = array(
            'filename'  => $filename,
            'url_count' => (int) $written['count'],
            'bytes'     => (int) $written['bytes'],
            'lastmod'   => $generated,
            'group'     => $group_slug,
        );
    }
}

/**
 * Escribe un manifiesto administrativo sin exponer datos sensibles.
 *
 * @param string $dir
 * @param array  $manifest
 * @return bool
 */
function seo_marketing_write_sitemap_manifest($dir, $manifest)
{
    $json = wp_json_encode(
        $manifest,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if (!is_string($json)) {
        return false;
    }

    $target    = $dir . 'manifest.json';
    $temporary = $target . '.tmp-' . wp_generate_uuid4();

    if (file_put_contents($temporary, $json, LOCK_EX) === false) {
        return false;
    }

    @chmod($temporary, 0644);

    if (@rename($temporary, $target)) {
        return true;
    }

    @unlink($target);
    return @rename($temporary, $target);
}

/**
 * Lee el último manifiesto de generación.
 *
 * @return array
 */
function seo_marketing_get_sitemap_manifest()
{
    $storage = seo_marketing_sitemap_storage();
    if (is_wp_error($storage)) {
        return array();
    }

    $file = $storage['dir'] . 'manifest.json';
    if (!is_file($file) || !is_readable($file)) {
        return array();
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    return is_array($decoded) ? $decoded : array();
}

/**
 * Genera todos los sitemaps en staging, los valida y solo entonces sustituye
 * la versión pública. Nunca borra primero los XML válidos existentes.
 *
 * @return array
 */
function seo_marketing_create_sitemaps()
{
    $storage = seo_marketing_sitemap_storage();
    if (is_wp_error($storage)) {
        return array('type' => 'error', 'message' => esc_html($storage->get_error_message()));
    }

    $dir = $storage['dir'];
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        return array('type' => 'error', 'message' => 'No se pudo crear el directorio de sitemaps.');
    }

    $lock_file = $dir . '.generation.lock';
    $lock      = @fopen($lock_file, 'c');

    if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) {
            @fclose($lock);
        }
        return array('type' => 'error', 'message' => 'Ya existe otra generación de sitemaps en curso.');
    }

    $build_dir = $dir . '.build-' . wp_generate_uuid4() . '/';
    $errors    = array();
    $files     = array();
    $published = array();

    try {
        if (!wp_mkdir_p($build_dir)) {
            throw new RuntimeException('No se pudo crear el directorio temporal de generación.');
        }

        $product_ids = seo_marketing_get_published_post_ids('product');
        seo_marketing_create_sitemap_group(
            $build_dir,
            'products',
            seo_marketing_posts_to_sitemap_urls($product_ids, true),
            $files,
            $errors
        );

        seo_marketing_create_sitemap_group(
            $build_dir,
            'product-categories',
            seo_marketing_get_product_category_sitemap_urls(),
            $files,
            $errors
        );

        $post_ids = seo_marketing_get_published_post_ids('post');
        seo_marketing_create_sitemap_group(
            $build_dir,
            'posts',
            seo_marketing_posts_to_sitemap_urls($post_ids, false),
            $files,
            $errors
        );

        $cluster_ids = seo_marketing_get_node_page_ids_by_roles(array('cluster'));
        seo_marketing_create_sitemap_group(
            $build_dir,
            'clusters',
            seo_marketing_posts_to_sitemap_urls($cluster_ids, false),
            $files,
            $errors
        );

        $hub_ids = seo_marketing_get_node_page_ids_by_roles(array('hub_primary', 'hub_secondary'));
        seo_marketing_create_sitemap_group(
            $build_dir,
            'hubs',
            seo_marketing_posts_to_sitemap_urls($hub_ids, false),
            $files,
            $errors
        );

        $page_ids = seo_marketing_filter_generic_page_ids_for_sitemap(
            seo_marketing_get_published_post_ids('page')
        );
        seo_marketing_create_sitemap_group(
            $build_dir,
            'pages',
            seo_marketing_posts_to_sitemap_urls($page_ids, false),
            $files,
            $errors
        );

        if (!empty($errors)) {
            throw new RuntimeException(implode(' ', $errors));
        }

        if (empty($files)) {
            throw new RuntimeException('No se encontraron URLs públicas para generar los sitemaps.');
        }

        $index_xml = seo_marketing_build_sitemap_index_xml($files);
        foreach (array('sitemap-index.xml', 'sitemap_index.xml', 'sitemap.xml') as $index_filename) {
            $written = seo_marketing_write_validated_xml(
                $build_dir . $index_filename,
                $index_xml,
                'sitemapindex'
            );

            if (is_wp_error($written)) {
                throw new RuntimeException(
                    $index_filename . ': ' . $written->get_error_message()
                );
            }
        }

        // Los hijos se publican antes que el índice para que este nunca apunte
        // a un archivo todavía inexistente. sitemap.xml se publica el último.
        $publish_order = array();
        foreach ($files as $file) {
            $publish_order[] = $file['filename'];
        }
        $publish_order[] = 'sitemap-index.xml';
        $publish_order[] = 'sitemap_index.xml';
        $publish_order[] = 'sitemap.xml';

        foreach ($publish_order as $filename) {
            if (!seo_marketing_publish_sitemap_file(
                $build_dir . $filename,
                $dir . $filename
            )) {
                throw new RuntimeException('No se pudo publicar ' . $filename . '.');
            }
            $published[] = $filename;
        }

        $keep = array_fill_keys($published, true);
        foreach ((array) glob($dir . '*.xml') as $old_file) {
            $old_filename = basename($old_file);
            if (!isset($keep[$old_filename])) {
                @unlink($old_file);
            }
        }

        $main_validation = seo_marketing_validate_xml_file(
            $dir . 'sitemap.xml',
            'sitemapindex'
        );
        if (!$main_validation['valid']) {
            throw new RuntimeException(
                'El índice principal publicado no superó la validación: ' . $main_validation['error']
            );
        }

        $total_urls = 0;
        foreach ($files as $file) {
            $total_urls += (int) $file['url_count'];
        }

        $manifest = array(
            'schema_version'  => SEO_MARKETING_SITEMAP_SCHEMA_VERSION,
            'generated_at_utc'=> gmdate('c'),
            'main_url'        => seo_marketing_sitemap_public_url('sitemap.xml'),
            'total_urls'      => $total_urls,
            'child_files'     => count($files),
            'files'           => $files,
        );
        seo_marketing_write_sitemap_manifest($dir, $manifest);

        return array(
            'type'    => 'success',
            'message' => sprintf(
                'Sitemaps generados y validados: <strong>%1$s URLs</strong> en <strong>%2$s archivos</strong>. Índice principal: <a href="%3$s" target="_blank" rel="noopener noreferrer">%3$s</a>.',
                number_format_i18n($total_urls),
                number_format_i18n(count($files)),
                esc_url(seo_marketing_sitemap_public_url('sitemap.xml'))
            ),
        );
    } catch (Throwable $exception) {
        error_log('[SEO Marketing] Error al generar sitemaps: ' . $exception->getMessage());

        return array(
            'type'    => 'error',
            'message' => 'No se publicaron sitemaps nuevos: ' . esc_html($exception->getMessage()),
        );
    } finally {
        seo_marketing_remove_directory($build_dir);
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/**
 * Informe local de validez, tamaño y número de entradas.
 *
 * @return array
 */
function seo_marketing_get_sitemap_report()
{
    $storage = seo_marketing_sitemap_storage();
    if (is_wp_error($storage) || !is_dir($storage['dir'])) {
        return array(
            'main_valid' => false,
            'total_urls' => 0,
            'files'      => array(),
            'manifest'   => array(),
        );
    }

    $files = array();
    foreach ((array) glob($storage['dir'] . '*.xml') as $file_path) {
        $filename = basename($file_path);

        if (in_array($filename, array('sitemap-index.xml', 'sitemap_index.xml'), true)) {
            continue;
        }

        $expected_root = $filename === 'sitemap.xml' ? 'sitemapindex' : 'urlset';
        $validation    = seo_marketing_validate_xml_file($file_path, $expected_root);

        $files[] = array(
            'filename' => $filename,
            'url'      => seo_marketing_sitemap_public_url($filename),
            'valid'    => (bool) $validation['valid'],
            'count'    => (int) $validation['count'],
            'root'     => $validation['root'],
            'error'    => $validation['error'],
            'bytes'    => (int) filesize($file_path),
            'modified' => (int) filemtime($file_path),
        );
    }

    usort(
        $files,
        static function ($left, $right) {
            if ($left['filename'] === 'sitemap.xml') {
                return -1;
            }
            if ($right['filename'] === 'sitemap.xml') {
                return 1;
            }
            return strcmp($left['filename'], $right['filename']);
        }
    );

    $total_urls = 0;
    $main_valid = false;
    foreach ($files as $file) {
        if ($file['filename'] === 'sitemap.xml') {
            $main_valid = $file['valid'];
        } else {
            $total_urls += $file['count'];
        }
    }

    return array(
        'main_valid' => $main_valid,
        'total_urls' => $total_urls,
        'files'      => $files,
        'manifest'   => seo_marketing_get_sitemap_manifest(),
    );
}

/**
 * Compatibilidad con la interfaz previa.
 *
 * @return string[]
 */
function seo_marketing_get_existing_sitemaps()
{
    $report = seo_marketing_get_sitemap_report();
    $urls   = array();

    foreach ($report['files'] as $file) {
        if (!empty($file['url'])) {
            $urls[] = $file['url'];
        }
    }

    return $urls;
}

/**
 * Programa una única regeneración diferida después de cambios de contenido.
 */
function seo_marketing_schedule_sitemap_regeneration($post_id = 0)
{
    $post_id = absint($post_id);

    if (
        (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        || ($post_id > 0 && wp_is_post_revision($post_id))
    ) {
        return;
    }

    if (!wp_next_scheduled('seo_marketing_regenerate_sitemaps_event')) {
        wp_schedule_single_event(
            time() + SEO_MARKETING_SITEMAP_REGEN_DELAY,
            'seo_marketing_regenerate_sitemaps_event'
        );
    }
}
add_action('save_post_product', 'seo_marketing_schedule_sitemap_regeneration', 20, 1);
add_action('save_post_page', 'seo_marketing_schedule_sitemap_regeneration', 20, 1);
add_action('save_post_post', 'seo_marketing_schedule_sitemap_regeneration', 20, 1);

/**
 * Los hooks de términos entregan un term_id, no un post_id.
 */
function seo_marketing_schedule_sitemap_regeneration_for_term()
{
    seo_marketing_schedule_sitemap_regeneration(0);
}
add_action('created_product_cat', 'seo_marketing_schedule_sitemap_regeneration_for_term', 20, 0);
add_action('edited_product_cat', 'seo_marketing_schedule_sitemap_regeneration_for_term', 20, 0);
add_action('delete_product_cat', 'seo_marketing_schedule_sitemap_regeneration_for_term', 20, 0);

/**
 * Regeneración silenciosa para WP-Cron.
 */
function seo_marketing_regenerate_sitemaps_silent()
{
    $result = seo_marketing_create_sitemaps();

    if (!is_array($result) || ($result['type'] ?? '') !== 'success') {
        $message = is_array($result) && isset($result['message'])
            ? wp_strip_all_tags($result['message'])
            : 'Resultado desconocido.';
        error_log('[SEO Marketing] Regeneración automática de sitemaps: ' . $message);
    }
}
add_action('seo_marketing_regenerate_sitemaps_event', 'seo_marketing_regenerate_sitemaps_silent');


/**
 * Garantiza una primera generación sin bloquear la carga pública.
 */
function seo_marketing_ensure_initial_sitemap_generation()
{
    $storage = seo_marketing_sitemap_storage();
    if (is_wp_error($storage) || is_file($storage['dir'] . 'sitemap.xml')) {
        return;
    }

    if (!wp_next_scheduled('seo_marketing_regenerate_sitemaps_event')) {
        wp_schedule_single_event(
            time() + 60,
            'seo_marketing_regenerate_sitemaps_event'
        );
    }
}
add_action('init', 'seo_marketing_ensure_initial_sitemap_generation', 30);

/* ========================================================================
 * Inventario + auditoria externa por lotes (GitHub Actions + Python)
 * ===================================================================== */

if (!defined('SEO_MARKETING_SCAN_SCHEMA_VERSION')) {
    define('SEO_MARKETING_SCAN_SCHEMA_VERSION', 3);
}
if (!defined('SEO_MARKETING_SCAN_BATCH_LIMIT')) {
    define('SEO_MARKETING_SCAN_BATCH_LIMIT', 500);
}

function seo_marketing_scan_tables()
{
    global $wpdb;

    return array(
        'scans'     => $wpdb->prefix . 'seo_sitemap_scans',
        'urls'      => $wpdb->prefix . 'seo_sitemap_scan_urls',
        'inventory' => $wpdb->prefix . 'seo_sitemap_inventory',
    );
}

/**
 * Crea/actualiza las tablas de forma idempotente mediante dbDelta.
 */
function seo_marketing_scan_ensure_tables()
{
    $installed = (int) get_option('seo_marketing_scan_schema_version', 0);
    if ($installed >= SEO_MARKETING_SCAN_SCHEMA_VERSION) {
        return;
    }

    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql_scans = "CREATE TABLE {$tables['scans']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        scan_uuid varchar(64) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'queued',
        source_url text NOT NULL,
        selection_mode varchar(24) NOT NULL DEFAULT 'coverage',
        batch_limit smallint(5) unsigned NOT NULL DEFAULT 500,
        inventory_total bigint(20) unsigned NOT NULL DEFAULT 0,
        callback_token_hash char(64) NOT NULL DEFAULT '',
        processed_urls bigint(20) unsigned NOT NULL DEFAULT 0,
        total_urls bigint(20) unsigned NOT NULL DEFAULT 0,
        status_200 bigint(20) unsigned NOT NULL DEFAULT 0,
        status_3xx bigint(20) unsigned NOT NULL DEFAULT 0,
        status_404 bigint(20) unsigned NOT NULL DEFAULT 0,
        status_403 bigint(20) unsigned NOT NULL DEFAULT 0,
        status_5xx bigint(20) unsigned NOT NULL DEFAULT 0,
        other_errors bigint(20) unsigned NOT NULL DEFAULT 0,
        duration_ms bigint(20) unsigned NOT NULL DEFAULT 0,
        error_message text NULL,
        started_at datetime NULL,
        completed_at datetime NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY scan_uuid (scan_uuid),
        KEY status (status),
        KEY created_at (created_at)
    ) {$charset_collate};";

    $sql_urls = "CREATE TABLE {$tables['urls']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        scan_id bigint(20) unsigned NOT NULL,
        inventory_id bigint(20) unsigned NULL,
        url_hash char(32) NOT NULL,
        resource_type varchar(20) NOT NULL DEFAULT 'page',
        queue_status varchar(20) NOT NULL DEFAULT 'queued',
        sitemap_url text NULL,
        url text NOT NULL,
        http_status smallint(5) unsigned NULL,
        final_status smallint(5) unsigned NULL,
        final_url text NULL,
        redirect_count smallint(5) unsigned NOT NULL DEFAULT 0,
        redirect_chain longtext NULL,
        response_ms int(10) unsigned NOT NULL DEFAULT 0,
        error_type varchar(64) NOT NULL DEFAULT '',
        error_message text NULL,
        checked_at datetime NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY scan_url (scan_id,url_hash),
        KEY scan_id (scan_id),
        KEY inventory_id (inventory_id),
        KEY scan_queue (scan_id,queue_status),
        KEY scan_error (scan_id,error_type),
        KEY scan_http (scan_id,http_status)
    ) {$charset_collate};";

    $sql_inventory = "CREATE TABLE {$tables['inventory']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        url_hash char(32) NOT NULL,
        url text NOT NULL,
        sitemap_url text NULL,
        active_in_sitemap tinyint(1) unsigned NOT NULL DEFAULT 1,
        sync_token char(36) NOT NULL DEFAULT '',
        status_bucket varchar(20) NOT NULL DEFAULT 'pending',
        http_status smallint(5) unsigned NULL,
        final_status smallint(5) unsigned NULL,
        final_url text NULL,
        redirect_count smallint(5) unsigned NOT NULL DEFAULT 0,
        redirect_chain longtext NULL,
        response_ms int(10) unsigned NOT NULL DEFAULT 0,
        error_type varchar(64) NOT NULL DEFAULT '',
        error_message text NULL,
        first_seen_at datetime NOT NULL,
        last_seen_at datetime NOT NULL,
        removed_at datetime NULL,
        last_checked_at datetime NULL,
        last_ok_at datetime NULL,
        last_error_at datetime NULL,
        consecutive_errors int(10) unsigned NOT NULL DEFAULT 0,
        checks_total bigint(20) unsigned NOT NULL DEFAULT 0,
        last_scan_id bigint(20) unsigned NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY url_hash (url_hash),
        KEY active_status (active_in_sitemap,status_bucket),
        KEY last_checked (active_in_sitemap,last_checked_at),
        KEY http_status (http_status),
        KEY error_type (error_type),
        KEY last_scan_id (last_scan_id)
    ) {$charset_collate};";

    dbDelta($sql_scans);
    dbDelta($sql_urls);
    dbDelta($sql_inventory);

    update_option('seo_marketing_scan_schema_version', SEO_MARKETING_SCAN_SCHEMA_VERSION, false);
}
add_action('admin_init', 'seo_marketing_scan_ensure_tables', 25);

function seo_marketing_scan_admin_url($args = array())
{
    return add_query_arg(
        array_merge(
            array('page' => 'seo-menu-marketing', 'tab' => 'scan'),
            is_array($args) ? $args : array()
        ),
        admin_url('admin.php')
    );
}

/**
 * Reutiliza la conexion GitHub Python Runner ya configurada.
 */
function seo_marketing_scan_get_runner_config()
{
    if (!function_exists('seo_github_python_runner_settings')) {
        $runner_file = __DIR__ . '/import-export/suppliers/github-python-runner.php';
        if (is_readable($runner_file)) {
            require_once $runner_file;
        }
    }

    $settings = function_exists('seo_github_python_runner_settings')
        ? seo_github_python_runner_settings()
        : array();

    return array(
        'available'         => function_exists('seo_github_python_runner_settings')
            && function_exists('seo_github_python_runner_api_request'),
        'enabled'           => !empty($settings['enabled']),
        'owner'             => trim((string) ($settings['owner'] ?? '')),
        'repo'              => trim((string) ($settings['repo'] ?? '')),
        'ref'               => trim((string) ($settings['ref'] ?? 'main')),
        'token'             => trim((string) ($settings['token'] ?? '')),
        'workflow'          => 'sitemap-scan.yml',
        'supplier_workflow' => trim((string) ($settings['workflow_id'] ?? 'supplier-scraper.yml')),
        'callback_url'      => rest_url('seo-system/v1/sitemap-scan/results'),
        'batch_endpoint'    => rest_url('seo-system/v1/sitemap-scan/batch'),
    );
}

function seo_marketing_scan_config_errors($config)
{
    $errors = array();
    if (empty($config['available'])) {
        return array('github_runner');
    }
    if (empty($config['enabled'])) {
        $errors[] = 'conexion_desactivada';
    }
    foreach (array('owner', 'repo', 'ref', 'token') as $key) {
        if (empty($config[$key])) {
            $errors[] = $key;
        }
    }
    return $errors;
}

/**
 * Extrae los <loc> de un XML generado localmente, sin hacer HTTP contra la web.
 */
function seo_marketing_scan_xml_locs($path, $expected_root)
{
    if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
        return new WP_Error('seo_scan_dom', 'La extension DOM de PHP no esta disponible.');
    }
    if (!is_file($path) || !is_readable($path)) {
        return new WP_Error('seo_scan_xml_missing', 'No se puede leer ' . basename((string) $path) . '.');
    }

    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $loaded = $dom->load($path, LIBXML_NONET | LIBXML_NOBLANKS);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded || !$dom->documentElement) {
        $detail = !empty($errors) ? trim((string) $errors[0]->message) : 'XML no valido';
        return new WP_Error('seo_scan_xml_invalid', basename((string) $path) . ': ' . $detail);
    }

    $root = strtolower((string) $dom->documentElement->localName);
    if ($root !== strtolower((string) $expected_root)) {
        return new WP_Error('seo_scan_xml_root', basename((string) $path) . ': raiz ' . $root . ' inesperada.');
    }

    $xpath = new DOMXPath($dom);
    $query = $root === 'sitemapindex'
        ? '/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]/*[local-name()="loc"]'
        : '/*[local-name()="urlset"]/*[local-name()="url"]/*[local-name()="loc"]';

    $nodes = $xpath->query($query);
    $locs = array();
    if ($nodes) {
        foreach ($nodes as $node) {
            $value = trim((string) $node->textContent);
            if ($value !== '') {
                $locs[] = $value;
            }
        }
    }
    return $locs;
}

/**
 * Construye en memoria el inventario exacto publicado por sitemap.xml.
 */
function seo_marketing_scan_collect_inventory()
{
    $storage = seo_marketing_sitemap_storage();
    if (is_wp_error($storage)) {
        return $storage;
    }

    $index_path = $storage['dir'] . 'sitemap.xml';
    $children = seo_marketing_scan_xml_locs($index_path, 'sitemapindex');
    if (is_wp_error($children)) {
        return $children;
    }

    $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    $pages = array();

    foreach ($children as $child_url) {
        $child_host = strtolower((string) wp_parse_url($child_url, PHP_URL_HOST));
        if ($child_host === '' || $child_host !== $home_host) {
            continue;
        }

        $child_path = (string) wp_parse_url($child_url, PHP_URL_PATH);
        $filename = basename($child_path);
        if (!seo_marketing_sitemap_filename_is_allowed($filename) || $filename === 'sitemap.xml') {
            continue;
        }

        $locs = seo_marketing_scan_xml_locs($storage['dir'] . $filename, 'urlset');
        if (is_wp_error($locs)) {
            return $locs;
        }

        $public_sitemap = seo_marketing_sitemap_public_url($filename);
        foreach ($locs as $loc) {
            $url = function_exists('seo_marketing_sitemap_normalize_url')
                ? seo_marketing_sitemap_normalize_url($loc)
                : esc_url_raw($loc, array('http', 'https'));
            if ($url !== '') {
                $pages[$url] = $public_sitemap;
            }
        }
    }

    if (empty($pages)) {
        return new WP_Error('seo_scan_inventory_empty', 'El sitemap no contiene URLs inventariables.');
    }

    return $pages;
}

/**
 * Sincroniza las URLs actuales con la tabla persistente. No visita las paginas.
 */
function seo_marketing_scan_sync_inventory()
{
    seo_marketing_scan_ensure_tables();
    $pages = seo_marketing_scan_collect_inventory();
    if (is_wp_error($pages)) {
        return $pages;
    }

    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $now = current_time('mysql', true);
    $sync_token = wp_generate_uuid4();

    foreach (array_chunk($pages, 200, true) as $chunk) {
        $values = array();
        $params = array();
        foreach ($chunk as $url => $sitemap_url) {
            $values[] = "(%s,%s,%s,1,%s,%s,NULL,%s,'pending')";
            $params[] = md5($url);
            $params[] = $url;
            $params[] = $sitemap_url;
            $params[] = $now;
            $params[] = $now;
            $params[] = $sync_token;
        }

        $sql = "INSERT INTO {$tables['inventory']}
            (url_hash,url,sitemap_url,active_in_sitemap,first_seen_at,last_seen_at,removed_at,sync_token,status_bucket)
            VALUES " . implode(',', $values) . "
            ON DUPLICATE KEY UPDATE
                url=VALUES(url),
                sitemap_url=VALUES(sitemap_url),
                active_in_sitemap=1,
                last_seen_at=VALUES(last_seen_at),
                removed_at=NULL,
                sync_token=VALUES(sync_token)";

        $prepared = $wpdb->prepare($sql, $params);
        if ($prepared === false || $wpdb->query($prepared) === false) {
            return new WP_Error('seo_scan_inventory_db', 'No se pudo actualizar el inventario: ' . $wpdb->last_error);
        }
    }

    $removed = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$tables['inventory']}
             SET active_in_sitemap=0, removed_at=COALESCE(removed_at,%s)
             WHERE active_in_sitemap=1 AND sync_token<>%s",
            $now,
            $sync_token
        )
    );

    return array(
        'total'   => count($pages),
        'removed' => max(0, (int) $removed),
    );
}

function seo_marketing_scan_handle_inventory_sync()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para actualizar el inventario.', 'seo-system'));
    }
    check_admin_referer('seo_marketing_scan_inventory_sync');

    $result = seo_marketing_scan_sync_inventory();
    if (is_wp_error($result)) {
        wp_safe_redirect(seo_marketing_scan_admin_url(array(
            'scan_msg' => 'inventory_error',
            'detail'    => rawurlencode($result->get_error_message()),
        )));
        exit;
    }

    wp_safe_redirect(seo_marketing_scan_admin_url(array(
        'scan_msg' => 'inventory_synced',
        'total'    => (int) $result['total'],
        'removed'  => (int) $result['removed'],
    )));
    exit;
}
add_action('admin_post_seo_marketing_scan_inventory_sync', 'seo_marketing_scan_handle_inventory_sync');

function seo_marketing_scan_inventory_stats()
{
    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $table = $tables['inventory'];

    $row = $wpdb->get_row(
        "SELECT
            SUM(active_in_sitemap=1) AS total,
            SUM(active_in_sitemap=1 AND last_checked_at IS NOT NULL) AS checked,
            SUM(active_in_sitemap=1 AND last_checked_at IS NULL) AS pending,
            SUM(active_in_sitemap=1 AND status_bucket='ok') AS ok_count,
            SUM(active_in_sitemap=1 AND status_bucket NOT IN ('ok','pending')) AS issues,
            SUM(active_in_sitemap=1 AND status_bucket='3xx') AS s3xx,
            SUM(active_in_sitemap=1 AND status_bucket='404') AS s404,
            SUM(active_in_sitemap=1 AND status_bucket='403') AS s403,
            SUM(active_in_sitemap=1 AND status_bucket='4xx') AS s4xx,
            SUM(active_in_sitemap=1 AND status_bucket='5xx') AS s5xx,
            SUM(active_in_sitemap=1 AND status_bucket='network') AS network_count,
            SUM(active_in_sitemap=0) AS removed
         FROM {$table}",
        ARRAY_A
    );

    $defaults = array(
        'total' => 0, 'checked' => 0, 'pending' => 0, 'ok_count' => 0, 'issues' => 0,
        's3xx' => 0, 's404' => 0, 's403' => 0, 's4xx' => 0, 's5xx' => 0,
        'network_count' => 0, 'removed' => 0,
    );
    $row = is_array($row) ? array_merge($defaults, $row) : $defaults;
    return array_map('intval', $row);
}

/**
 * Selecciona maximo 500 URLs.
 * Fase 1: cobertura completa de pendientes.
 * Fase 2: incidencias + 75% sanas mas antiguas + 25% muestra aleatoria.
 */
function seo_marketing_scan_select_batch($limit = SEO_MARKETING_SCAN_BATCH_LIMIT)
{
    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $limit = max(1, min(SEO_MARKETING_SCAN_BATCH_LIMIT, (int) $limit));

    $pending = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$tables['inventory']} WHERE active_in_sitemap=1 AND last_checked_at IS NULL"
    );

    if ($pending > 0) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,url,sitemap_url,status_bucket,last_checked_at,consecutive_errors
                 FROM {$tables['inventory']}
                 WHERE active_in_sitemap=1 AND last_checked_at IS NULL
                 ORDER BY id ASC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        return array('mode' => 'coverage', 'rows' => is_array($rows) ? $rows : array());
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id,url,sitemap_url,status_bucket,last_checked_at,consecutive_errors
             FROM {$tables['inventory']}
             WHERE active_in_sitemap=1 AND status_bucket NOT IN ('ok','pending')
             ORDER BY last_checked_at ASC,
                 CASE status_bucket WHEN '5xx' THEN 1 WHEN 'network' THEN 2 WHEN '404' THEN 3 WHEN '403' THEN 4 WHEN '4xx' THEN 5 WHEN '3xx' THEN 6 ELSE 7 END,
                 consecutive_errors DESC
             LIMIT %d",
            $limit
        ),
        ARRAY_A
    );
    $rows = is_array($rows) ? $rows : array();

    $remaining = $limit - count($rows);
    if ($remaining <= 0) {
        return array('mode' => 'maintenance', 'rows' => $rows);
    }

    $selected_ids = array_map('intval', wp_list_pluck($rows, 'id'));
    $exclude = !empty($selected_ids) ? ' AND id NOT IN (' . implode(',', $selected_ids) . ')' : '';
    $oldest_count = (int) ceil($remaining * 0.75);

    $oldest = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id,url,sitemap_url,status_bucket,last_checked_at,consecutive_errors
             FROM {$tables['inventory']}
             WHERE active_in_sitemap=1 AND status_bucket='ok' {$exclude}
             ORDER BY last_checked_at ASC, id ASC LIMIT %d",
            $oldest_count
        ),
        ARRAY_A
    );
    $oldest = is_array($oldest) ? $oldest : array();
    $rows = array_merge($rows, $oldest);

    $remaining = $limit - count($rows);
    if ($remaining > 0) {
        $selected_ids = array_map('intval', wp_list_pluck($rows, 'id'));
        $exclude = !empty($selected_ids) ? ' AND id NOT IN (' . implode(',', $selected_ids) . ')' : '';
        $random = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,url,sitemap_url,status_bucket,last_checked_at,consecutive_errors
                 FROM {$tables['inventory']}
                 WHERE active_in_sitemap=1 AND status_bucket='ok' {$exclude}
                 ORDER BY RAND() LIMIT %d",
                $remaining
            ),
            ARRAY_A
        );
        if (is_array($random)) {
            $rows = array_merge($rows, $random);
        }
    }

    return array('mode' => 'maintenance', 'rows' => array_slice($rows, 0, $limit));
}

function seo_marketing_scan_expire_stale_runs()
{
    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $cutoff = gmdate('Y-m-d H:i:s', time() - 6 * HOUR_IN_SECONDS);
    $now = current_time('mysql', true);
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$tables['scans']}
             SET status='failed', error_message='Ejecucion caducada sin callback final.', callback_token_hash='', completed_at=%s, updated_at=%s
             WHERE status IN ('queued','running') AND updated_at<%s",
            $now,
            $now,
            $cutoff
        )
    );

    // Conservamos el token una hora tras terminar para que los callbacks finales
    // puedan reintentarse de forma idempotente si se pierde la respuesta HTTP.
    $token_cutoff = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$tables['scans']} SET callback_token_hash=''
             WHERE status IN ('completed','failed') AND callback_token_hash<>'' AND updated_at<%s",
            $token_cutoff
        )
    );
}

/**
 * Crea la ejecucion, deja las 500 URLs en la cola SQL y dispara GitHub.
 */
function seo_marketing_scan_handle_start()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para iniciar el escaneo.', 'seo-system'));
    }
    check_admin_referer('seo_marketing_scan_start');
    seo_marketing_scan_ensure_tables();

    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $config = seo_marketing_scan_get_runner_config();
    $missing = seo_marketing_scan_config_errors($config);
    if (!empty($missing)) {
        wp_safe_redirect(seo_marketing_scan_admin_url(array('scan_msg' => 'config')));
        exit;
    }

    seo_marketing_scan_expire_stale_runs();
    $busy = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$tables['scans']} WHERE status IN ('queued','running')"
    );
    if ($busy > 0) {
        wp_safe_redirect(seo_marketing_scan_admin_url(array('scan_msg' => 'busy')));
        exit;
    }

    // Antes de seleccionar, refrescamos el inventario desde los XML locales.
    $sync = seo_marketing_scan_sync_inventory();
    if (is_wp_error($sync)) {
        wp_safe_redirect(seo_marketing_scan_admin_url(array(
            'scan_msg' => 'inventory_error',
            'detail'    => rawurlencode($sync->get_error_message()),
        )));
        exit;
    }

    $selection = seo_marketing_scan_select_batch(SEO_MARKETING_SCAN_BATCH_LIMIT);
    $batch = $selection['rows'];
    if (empty($batch)) {
        wp_safe_redirect(seo_marketing_scan_admin_url(array('scan_msg' => 'empty')));
        exit;
    }

    $stats = seo_marketing_scan_inventory_stats();
    $scan_uuid = wp_generate_uuid4();
    $callback_token = wp_generate_password(64, false, false);
    $now = current_time('mysql', true);
    $source_url = seo_marketing_sitemap_public_url('sitemap.xml');

    $inserted = $wpdb->insert(
        $tables['scans'],
        array(
            'scan_uuid'           => $scan_uuid,
            'status'              => 'queued',
            'source_url'          => $source_url,
            'selection_mode'      => $selection['mode'],
            'batch_limit'         => SEO_MARKETING_SCAN_BATCH_LIMIT,
            'inventory_total'     => $stats['total'],
            'callback_token_hash' => hash('sha256', $callback_token),
            'total_urls'          => count($batch),
            'created_at'          => $now,
            'updated_at'          => $now,
        ),
        array('%s','%s','%s','%s','%d','%d','%s','%d','%s','%s')
    );
    if ($inserted === false) {
        wp_safe_redirect(seo_marketing_scan_admin_url(array('scan_msg' => 'db_error')));
        exit;
    }

    $scan_id = (int) $wpdb->insert_id;
    foreach ($batch as $item) {
        $wpdb->insert(
            $tables['urls'],
            array(
                'scan_id'       => $scan_id,
                'inventory_id'  => (int) $item['id'],
                'url_hash'      => md5((string) $item['url']),
                'resource_type' => 'page',
                'queue_status'  => 'queued',
                'sitemap_url'   => (string) $item['sitemap_url'],
                'url'           => (string) $item['url'],
            ),
            array('%d','%d','%s','%s','%s','%s','%s')
        );
    }

    $batch_url = add_query_arg('scan_id', rawurlencode($scan_uuid), $config['batch_endpoint']);
    $endpoint = sprintf(
        'https://api.github.com/repos/%1$s/%2$s/actions/workflows/%3$s/dispatches',
        rawurlencode($config['owner']),
        rawurlencode($config['repo']),
        rawurlencode($config['workflow'])
    );
    $payload = array(
        'ref' => $config['ref'],
        'inputs' => array(
            'scan_id'        => $scan_uuid,
            'batch_url'      => $batch_url,
            'callback_url'   => $config['callback_url'],
            'callback_token' => $callback_token,
        ),
    );

    $response = function_exists('seo_github_python_runner_api_request')
        ? seo_github_python_runner_api_request('POST', $endpoint, $payload)
        : new WP_Error('seo_scan_github_runner', 'La conexion GitHub Python Runner no esta disponible.');

    if (is_wp_error($response)) {
        $wpdb->update(
            $tables['scans'],
            array(
                'status' => 'failed',
                'error_message' => substr($response->get_error_message(), 0, 4000),
                'callback_token_hash' => '',
                'completed_at' => $now,
                'updated_at' => $now,
            ),
            array('id' => $scan_id),
            array('%s','%s','%s','%s','%s'),
            array('%d')
        );
        wp_safe_redirect(seo_marketing_scan_admin_url(array('scan_msg' => 'github_error')));
        exit;
    }

    wp_safe_redirect(seo_marketing_scan_admin_url(array(
        'scan_msg' => 'queued',
        'batch' => count($batch),
        'mode' => $selection['mode'],
    )));
    exit;
}
add_action('admin_post_seo_marketing_scan_start', 'seo_marketing_scan_handle_start');

/**
 * Cancela una ejecucion localmente.
 *
 * Al invalidar el token, el worker externo deja de poder enviar callbacks.
 * Si GitHub aun sigue procesando, se detendra en el siguiente callback porque
 * recibira un 403; las URLs no procesadas quedan disponibles para otro lote.
 */
function seo_marketing_scan_handle_cancel()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para detener el escaneo.', 'seo-system'));
    }
    check_admin_referer('seo_marketing_scan_cancel');
    seo_marketing_scan_ensure_tables();

    $scan_uuid = isset($_POST['scan_uuid'])
        ? sanitize_text_field(wp_unslash($_POST['scan_uuid']))
        : '';

    if ($scan_uuid === '') {
        wp_safe_redirect(seo_marketing_scan_admin_url(array('scan_msg' => 'cancel_missing')));
        exit;
    }

    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $scan = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id,status FROM {$tables['scans']} WHERE scan_uuid=%s LIMIT 1",
            $scan_uuid
        ),
        ARRAY_A
    );

    if (!$scan) {
        wp_safe_redirect(seo_marketing_scan_admin_url(array('scan_msg' => 'cancel_missing')));
        exit;
    }

    if (!in_array((string) $scan['status'], array('queued', 'running'), true)) {
        wp_safe_redirect(seo_marketing_scan_admin_url(array('scan_msg' => 'cancel_not_running')));
        exit;
    }

    $now = current_time('mysql', true);
    $wpdb->update(
        $tables['scans'],
        array(
            'status' => 'cancelled',
            'callback_token_hash' => '',
            'error_message' => 'Cancelado manualmente desde SEO Marketing.',
            'completed_at' => $now,
            'updated_at' => $now,
        ),
        array('id' => (int) $scan['id']),
        array('%s','%s','%s','%s','%s'),
        array('%d')
    );

    // Las filas ya recibidas permanecen como done; solo liberamos las que el
    // worker no llego a entregar. El inventario de esas URLs sigue pendiente
    // y podra seleccionarse de nuevo en el siguiente lote.
    $wpdb->update(
        $tables['urls'],
        array('queue_status' => 'cancelled'),
        array('scan_id' => (int) $scan['id'], 'queue_status' => 'queued'),
        array('%s'),
        array('%d','%s')
    );

    wp_safe_redirect(seo_marketing_scan_admin_url(array('scan_msg' => 'cancelled')));
    exit;
}
add_action('admin_post_seo_marketing_scan_cancel', 'seo_marketing_scan_handle_cancel');

function seo_marketing_scan_verify_request(WP_REST_Request $request, $scan_uuid)
{
    $scan_uuid = sanitize_text_field((string) $scan_uuid);
    if ($scan_uuid === '') {
        return new WP_Error('seo_scan_payload', 'Falta scan_id.', array('status' => 400));
    }

    $authorization = trim((string) $request->get_header('authorization'));
    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
        return new WP_Error('seo_scan_auth_missing', 'Falta autenticacion del escaneo.', array('status' => 401));
    }
    $token = trim((string) $match[1]);
    if ($token === '') {
        return new WP_Error('seo_scan_auth_missing', 'Token vacio.', array('status' => 401));
    }

    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $scan = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$tables['scans']} WHERE scan_uuid=%s LIMIT 1", $scan_uuid),
        ARRAY_A
    );
    if (!$scan) {
        return new WP_Error('seo_scan_unknown', 'La ejecucion no existe.', array('status' => 404));
    }

    $stored = trim((string) ($scan['callback_token_hash'] ?? ''));
    if ($stored === '' || !hash_equals($stored, hash('sha256', $token))) {
        return new WP_Error('seo_scan_auth_invalid', 'Token no valido o ya consumido.', array('status' => 403));
    }

    return $scan;
}

/**
 * GitHub descarga aqui exactamente las URLs preseleccionadas por WordPress.
 */
function seo_marketing_scan_batch_endpoint(WP_REST_Request $request)
{
    seo_marketing_scan_ensure_tables();
    $scan_uuid = sanitize_text_field((string) $request->get_param('scan_id'));
    $scan = seo_marketing_scan_verify_request($request, $scan_uuid);
    if (is_wp_error($scan)) {
        return $scan;
    }

    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $items = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT url,sitemap_url FROM {$tables['urls']} WHERE scan_id=%d ORDER BY id ASC LIMIT %d",
            (int) $scan['id'],
            SEO_MARKETING_SCAN_BATCH_LIMIT
        ),
        ARRAY_A
    );

    return rest_ensure_response(array(
        'scan_id' => $scan_uuid,
        'count'   => is_array($items) ? count($items) : 0,
        'items'   => is_array($items) ? $items : array(),
    ));
}

function seo_marketing_scan_sanitize_result_row($row)
{
    $row = is_array($row) ? $row : array();
    $url = isset($row['url']) ? esc_url_raw((string) $row['url'], array('http','https')) : '';
    if ($url === '') {
        return null;
    }
    $chain = isset($row['redirect_chain']) && is_array($row['redirect_chain']) ? $row['redirect_chain'] : array();

    return array(
        'url_hash'       => md5($url),
        'sitemap_url'    => isset($row['sitemap_url']) ? esc_url_raw((string) $row['sitemap_url'], array('http','https')) : '',
        'url'            => $url,
        'http_status'    => isset($row['http_status']) && is_numeric($row['http_status']) ? absint($row['http_status']) : 0,
        'final_status'   => isset($row['final_status']) && is_numeric($row['final_status']) ? absint($row['final_status']) : 0,
        'final_url'      => isset($row['final_url']) ? esc_url_raw((string) $row['final_url'], array('http','https')) : '',
        'redirect_count' => isset($row['redirect_count']) ? min(100, absint($row['redirect_count'])) : 0,
        'redirect_chain' => wp_json_encode($chain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'response_ms'    => isset($row['response_ms']) ? min(3600000, absint($row['response_ms'])) : 0,
        'error_type'     => isset($row['error_type']) ? sanitize_key((string) $row['error_type']) : '',
        'error_message'  => isset($row['error_message']) ? sanitize_textarea_field((string) $row['error_message']) : '',
        'checked_at'     => isset($row['checked_at']) && strtotime((string) $row['checked_at'])
            ? gmdate('Y-m-d H:i:s', strtotime((string) $row['checked_at']))
            : current_time('mysql', true),
    );
}

function seo_marketing_scan_result_bucket($row)
{
    $redirects = (int) $row['redirect_count'];
    $final = (int) ($row['final_status'] ?: $row['http_status']);
    if ($redirects > 0) {
        return '3xx';
    }
    if ($final === 200 && $row['error_type'] === '') {
        return 'ok';
    }
    if (in_array($final, array(404,410), true)) {
        return '404';
    }
    if ($final === 403) {
        return '403';
    }
    if ($final >= 400 && $final <= 499) {
        return '4xx';
    }
    if ($final >= 500 && $final <= 599) {
        return '5xx';
    }
    return 'network';
}

/**
 * Actualiza simultaneamente el resultado de la ejecucion y el estado actual del inventario.
 */
function seo_marketing_scan_store_batch($scan_id, $results)
{
    global $wpdb;
    $tables = seo_marketing_scan_tables();

    foreach ((array) $results as $raw) {
        $row = seo_marketing_scan_sanitize_result_row($raw);
        if (!$row) {
            continue;
        }

        $queue = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id,inventory_id,queue_status FROM {$tables['urls']} WHERE scan_id=%d AND url_hash=%s LIMIT 1",
                (int) $scan_id,
                $row['url_hash']
            ),
            ARRAY_A
        );
        if (!$queue) {
            continue; // Nunca aceptamos resultados de URLs no seleccionadas para este lote.
        }
        if ((string) ($queue['queue_status'] ?? '') === 'done') {
            continue; // Idempotencia: un callback reintentado no cuenta dos veces.
        }

        $bucket = seo_marketing_scan_result_bucket($row);
        $wpdb->update(
            $tables['urls'],
            array(
                'queue_status'   => 'done',
                'sitemap_url'    => $row['sitemap_url'],
                'http_status'    => $row['http_status'],
                'final_status'   => $row['final_status'],
                'final_url'      => $row['final_url'],
                'redirect_count' => $row['redirect_count'],
                'redirect_chain' => $row['redirect_chain'],
                'response_ms'    => $row['response_ms'],
                'error_type'     => $row['error_type'],
                'error_message'  => $row['error_message'],
                'checked_at'     => $row['checked_at'],
            ),
            array('id' => (int) $queue['id']),
            array('%s','%s','%d','%d','%s','%d','%s','%d','%s','%s','%s'),
            array('%d')
        );

        $is_ok = $bucket === 'ok' ? 1 : 0;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$tables['inventory']} SET
                    status_bucket=%s,
                    http_status=%d,
                    final_status=%d,
                    final_url=%s,
                    redirect_count=%d,
                    redirect_chain=%s,
                    response_ms=%d,
                    error_type=%s,
                    error_message=%s,
                    last_checked_at=%s,
                    last_ok_at=IF(%d=1,%s,last_ok_at),
                    last_error_at=IF(%d=1,last_error_at,%s),
                    consecutive_errors=IF(%d=1,0,consecutive_errors+1),
                    checks_total=checks_total+1,
                    last_scan_id=%d
                 WHERE id=%d",
                $bucket,
                $row['http_status'],
                $row['final_status'],
                $row['final_url'],
                $row['redirect_count'],
                $row['redirect_chain'],
                $row['response_ms'],
                $row['error_type'],
                $row['error_message'],
                $row['checked_at'],
                $is_ok,
                $row['checked_at'],
                $is_ok,
                $row['checked_at'],
                $is_ok,
                (int) $scan_id,
                (int) $queue['inventory_id']
            )
        );
    }
}

function seo_marketing_scan_refresh_progress($scan_id)
{
    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $processed = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tables['urls']} WHERE scan_id=%d AND queue_status='done'",
            (int) $scan_id
        )
    );
    $wpdb->update(
        $tables['scans'],
        array('status' => 'running', 'processed_urls' => $processed, 'updated_at' => current_time('mysql', true)),
        array('id' => (int) $scan_id),
        array('%s','%d','%s'),
        array('%d')
    );
    return $processed;
}

function seo_marketing_scan_callback(WP_REST_Request $request)
{
    seo_marketing_scan_ensure_tables();
    $payload = json_decode((string) $request->get_body(), true);
    if (!is_array($payload)) {
        return new WP_Error('seo_scan_json', 'JSON no valido.', array('status' => 400));
    }

    $scan_uuid = isset($payload['scan_id']) ? sanitize_text_field((string) $payload['scan_id']) : '';
    $scan = seo_marketing_scan_verify_request($request, $scan_uuid);
    if (is_wp_error($scan)) {
        return $scan;
    }

    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $scan_id = (int) $scan['id'];
    $event = isset($payload['event']) ? sanitize_key((string) $payload['event']) : '';
    $now = current_time('mysql', true);

    // Si el callback final se proceso pero se perdio la respuesta HTTP, un reintento
    // posterior no debe convertir un lote ya completado en fallido.
    if ((string) $scan['status'] === 'completed' && $event === 'failed') {
        return rest_ensure_response(array('ok' => true, 'ignored' => 'already_completed'));
    }

    if ($event === 'start') {
        $wpdb->update(
            $tables['scans'],
            array(
                'status' => 'running',
                'started_at' => !empty($scan['started_at']) ? $scan['started_at'] : $now,
                'updated_at' => $now,
                'error_message' => '',
            ),
            array('id' => $scan_id),
            array('%s','%s','%s','%s'),
            array('%d')
        );
        return rest_ensure_response(array('ok' => true));
    }

    if ($event === 'batch') {
        $results = isset($payload['results']) && is_array($payload['results']) ? $payload['results'] : array();
        if (count($results) > SEO_MARKETING_SCAN_BATCH_LIMIT) {
            return new WP_Error('seo_scan_batch', 'El callback supera 500 resultados.', array('status' => 413));
        }
        seo_marketing_scan_store_batch($scan_id, $results);
        $processed = seo_marketing_scan_refresh_progress($scan_id);
        return rest_ensure_response(array('ok' => true, 'processed' => $processed));
    }

    if ($event === 'complete') {
        $summary = isset($payload['summary']) && is_array($payload['summary']) ? $payload['summary'] : array();
        $processed = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$tables['urls']} WHERE scan_id=%d AND queue_status='done'", $scan_id)
        );
        $wpdb->update(
            $tables['scans'],
            array(
                'status' => 'completed',
                'processed_urls' => $processed,
                'status_200' => absint($summary['status_200'] ?? 0),
                'status_3xx' => absint($summary['status_3xx'] ?? 0),
                'status_404' => absint($summary['status_404'] ?? 0),
                'status_403' => absint($summary['status_403'] ?? 0),
                'status_5xx' => absint($summary['status_5xx'] ?? 0),
                'other_errors' => absint($summary['other_errors'] ?? 0),
                'duration_ms' => absint($summary['duration_ms'] ?? 0),
                'completed_at' => $now,
                'updated_at' => $now,
                'error_message' => '',
            ),
            array('id' => $scan_id),
            array('%s','%d','%d','%d','%d','%d','%d','%d','%d','%s','%s','%s'),
            array('%d')
        );
        return rest_ensure_response(array('ok' => true));
    }

    if ($event === 'failed') {
        $message = sanitize_textarea_field((string) ($payload['error_message'] ?? 'El runner externo informo de un error.'));
        $wpdb->update(
            $tables['scans'],
            array(
                'status' => 'failed',
                'error_message' => substr($message, 0, 4000),
                'completed_at' => $now,
                'updated_at' => $now,
            ),
            array('id' => $scan_id),
            array('%s','%s','%s','%s'),
            array('%d')
        );
        return rest_ensure_response(array('ok' => true));
    }

    return new WP_Error('seo_scan_event', 'Evento no soportado.', array('status' => 400));
}

function seo_marketing_scan_register_rest_routes()
{
    register_rest_route('seo-system/v1', '/sitemap-scan/batch', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'seo_marketing_scan_batch_endpoint',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('seo-system/v1', '/sitemap-scan/results', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'seo_marketing_scan_callback',
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'seo_marketing_scan_register_rest_routes');

function seo_marketing_scan_latest_run()
{
    global $wpdb;
    $tables = seo_marketing_scan_tables();
    return $wpdb->get_row("SELECT * FROM {$tables['scans']} ORDER BY id DESC LIMIT 1", ARRAY_A);
}

function seo_marketing_scan_active_run()
{
    global $wpdb;
    $tables = seo_marketing_scan_tables();
    return $wpdb->get_row(
        "SELECT * FROM {$tables['scans']} WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1",
        ARRAY_A
    );
}

function seo_marketing_scan_status_label($status)
{
    $labels = array(
        'queued' => 'En cola',
        'running' => 'En curso',
        'completed' => 'Completado',
        'failed' => 'Fallido',
        'cancelled' => 'Cancelado',
    );
    return $labels[$status] ?? ucfirst((string) $status);
}

function seo_marketing_scan_bucket_label($bucket)
{
    $labels = array(
        'pending' => 'Pendiente',
        'ok' => 'OK',
        '3xx' => 'Redireccion',
        '404' => '404/410',
        '403' => '403',
        '4xx' => 'Otros 4xx',
        '5xx' => '5xx',
        'network' => 'Red/timeout',
    );
    return $labels[$bucket] ?? (string) $bucket;
}

function seo_marketing_scan_render_notice()
{
    $message = isset($_GET['scan_msg']) ? sanitize_key(wp_unslash($_GET['scan_msg'])) : '';
    if ($message === '') {
        return;
    }

    $class = 'notice notice-success is-dismissible';
    $text = '';
    if ($message === 'inventory_synced') {
        $text = sprintf(
            'Inventario actualizado: %s URLs activas. %s URLs han salido del sitemap.',
            number_format_i18n(absint($_GET['total'] ?? 0)),
            number_format_i18n(absint($_GET['removed'] ?? 0))
        );
    } elseif ($message === 'queued') {
        $text = sprintf(
            'Lote enviado a GitHub: %s URLs (%s).',
            number_format_i18n(absint($_GET['batch'] ?? 0)),
            sanitize_text_field((string) ($_GET['mode'] ?? '')) === 'coverage' ? 'cobertura' : 'mantenimiento'
        );
    } elseif ($message === 'busy') {
        $class = 'notice notice-warning';
        $text = 'Ya hay un lote en curso. Puedes detenerlo con el boton Parar proceso antes de lanzar otro.';
    } elseif ($message === 'cancelled') {
        $class = 'notice notice-success is-dismissible';
        $text = 'Proceso detenido. El lote ha quedado cancelado y ya puedes iniciar otro.';
    } elseif ($message === 'cancel_not_running') {
        $class = 'notice notice-warning';
        $text = 'Ese proceso ya no estaba en ejecucion.';
    } elseif ($message === 'cancel_missing') {
        $class = 'notice notice-error';
        $text = 'No se pudo identificar el proceso que querias detener.';
    } elseif ($message === 'empty') {
        $class = 'notice notice-warning';
        $text = 'No hay URLs activas disponibles para escanear.';
    } elseif ($message === 'config') {
        $class = 'notice notice-error';
        $text = 'La conexion GitHub Python Runner esta incompleta.';
    } elseif ($message === 'inventory_error') {
        $class = 'notice notice-error';
        $text = 'No se pudo actualizar el inventario. ' . sanitize_text_field(rawurldecode((string) ($_GET['detail'] ?? '')));
    } elseif ($message === 'github_error') {
        $class = 'notice notice-error';
        $text = 'GitHub no pudo aceptar el workflow.';
    } elseif ($message === 'db_error') {
        $class = 'notice notice-error';
        $text = 'No se pudo crear la ejecucion en la base de datos.';
    }

    if ($text !== '') {
        echo '<div class="' . esc_attr($class) . '"><p><strong>' . esc_html($text) . '</strong></p></div>';
    }
}

function seo_marketing_scan_render_styles()
{
    echo '<style>
    .seo-scan-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin:16px 0}
    .seo-scan-kpi{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:14px;box-sizing:border-box}
    .seo-scan-kpi strong{display:block;font-size:24px;line-height:1.1;margin-bottom:4px}.seo-scan-kpi span{font-size:12px;color:#646970}
    .seo-scan-kpi.ok{border-left:4px solid #00a32a}.seo-scan-kpi.err{border-left:4px solid #d63638}.seo-scan-kpi.warn{border-left:4px solid #dba617}
    .seo-scan-actions{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0}.seo-scan-actions form{margin:0}.seo-scan-stop{border-color:#d63638!important;color:#b32d2e!important}.seo-scan-stop:hover,.seo-scan-stop:focus{border-color:#b32d2e!important;color:#8a2424!important}.seo-scan-card{background:#fff;border:1px solid #dcdcde;padding:16px;margin:14px 0}
    .seo-scan-progress{height:14px;background:#f0f0f1;border-radius:7px;overflow:hidden}.seo-scan-progress>span{display:block;height:100%;background:#2271b1}
    .seo-scan-filters{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0}.seo-scan-search{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0}
    .seo-scan-badge{display:inline-block;border-radius:12px;padding:2px 8px;font-size:11px;font-weight:600;white-space:nowrap}
    .seo-scan-badge.ok{background:#edfaef;color:#006b1b}.seo-scan-badge.err{background:#fcf0f1;color:#b32d2e}.seo-scan-badge.warn{background:#fff8e5;color:#7a4d00}.seo-scan-badge.info{background:#f0f6fc;color:#135e96}
    .seo-scan-table code{white-space:normal;word-break:break-word;font-size:11px}.seo-scan-table .url-col{min-width:280px}.seo-scan-table .small-col{width:90px}
    .seo-scan-table tr.is-error{background:#fff7f7}.seo-scan-table tr.is-pending{background:#fbfbfc}.seo-scan-table small{color:#646970}
    </style>';
}

function seo_marketing_scan_render_kpi($value, $label, $class = '')
{
    echo '<div class="seo-scan-kpi ' . esc_attr($class) . '"><strong>' . esc_html(number_format_i18n((int) $value)) . '</strong><span>' . esc_html($label) . '</span></div>';
}

function seo_marketing_scan_inventory_filter_sql($filter)
{
    switch ($filter) {
        case 'ok': return "active_in_sitemap=1 AND status_bucket='ok'";
        case 'issues': return "active_in_sitemap=1 AND status_bucket NOT IN ('ok','pending')";
        case 'pending': return "active_in_sitemap=1 AND last_checked_at IS NULL";
        case '3xx': return "active_in_sitemap=1 AND status_bucket='3xx'";
        case '404': return "active_in_sitemap=1 AND status_bucket='404'";
        case '403': return "active_in_sitemap=1 AND status_bucket='403'";
        case '4xx': return "active_in_sitemap=1 AND status_bucket='4xx'";
        case '5xx': return "active_in_sitemap=1 AND status_bucket='5xx'";
        case 'network': return "active_in_sitemap=1 AND status_bucket='network'";
        case 'removed': return "active_in_sitemap=0";
        default: return "active_in_sitemap=1";
    }
}

function seo_marketing_scan_render_inventory_table($stats)
{
    global $wpdb;
    $tables = seo_marketing_scan_tables();
    $filter = isset($_GET['inventory_filter']) ? sanitize_key(wp_unslash($_GET['inventory_filter'])) : 'all';
    $allowed = array('all','ok','issues','pending','3xx','404','403','4xx','5xx','network','removed');
    if (!in_array($filter, $allowed, true)) {
        $filter = 'all';
    }
    $search = isset($_GET['inventory_s']) ? sanitize_text_field(wp_unslash($_GET['inventory_s'])) : '';
    $page = max(1, isset($_GET['inventory_page']) ? absint($_GET['inventory_page']) : 1);
    $per_page = 100;
    $where = seo_marketing_scan_inventory_filter_sql($filter);
    $params = array();
    if ($search !== '') {
        $where .= ' AND (url LIKE %s OR sitemap_url LIKE %s OR error_type LIKE %s)';
        $like = '%' . $wpdb->esc_like($search) . '%';
        $params = array($like,$like,$like);
    }

    $count_sql = "SELECT COUNT(*) FROM {$tables['inventory']} WHERE {$where}";
    $total = !empty($params) ? (int) $wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int) $wpdb->get_var($count_sql);
    $total_pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $list_sql = "SELECT * FROM {$tables['inventory']} WHERE {$where}
        ORDER BY active_in_sitemap DESC,
        CASE status_bucket WHEN '5xx' THEN 1 WHEN 'network' THEN 2 WHEN '404' THEN 3 WHEN '403' THEN 4 WHEN '4xx' THEN 5 WHEN '3xx' THEN 6 WHEN 'pending' THEN 7 ELSE 8 END,
        COALESCE(last_checked_at,'1970-01-01 00:00:00') ASC, id ASC
        LIMIT %d OFFSET %d";
    $list_params = array_merge($params, array($per_page, $offset));
    $rows = $wpdb->get_results($wpdb->prepare($list_sql, $list_params), ARRAY_A);

    $filters = array(
        'all' => array('Todas', $stats['total']),
        'ok' => array('OK', $stats['ok_count']),
        'issues' => array('Incidencias', $stats['issues']),
        'pending' => array('Pendientes', $stats['pending']),
        '3xx' => array('3xx', $stats['s3xx']),
        '404' => array('404/410', $stats['s404']),
        '403' => array('403', $stats['s403']),
        '4xx' => array('Otros 4xx', $stats['s4xx']),
        '5xx' => array('5xx', $stats['s5xx']),
        'network' => array('Red/timeout', $stats['network_count']),
        'removed' => array('Fuera sitemap', $stats['removed']),
    );

    echo '<div class="seo-scan-card"><h2 style="margin-top:0">Inventario de URLs</h2>';
    echo '<div class="seo-scan-filters">';
    foreach ($filters as $key => $data) {
        $url = seo_marketing_scan_admin_url(array('inventory_filter' => $key));
        echo '<a class="button ' . ($filter === $key ? 'button-primary' : '') . '" href="' . esc_url($url) . '">' . esc_html($data[0]) . ' (' . esc_html(number_format_i18n((int) $data[1])) . ')</a>';
    }
    echo '</div>';

    echo '<form method="get" class="seo-scan-search">';
    echo '<input type="hidden" name="page" value="seo-menu-marketing"><input type="hidden" name="tab" value="scan"><input type="hidden" name="inventory_filter" value="' . esc_attr($filter) . '">';
    echo '<input type="search" name="inventory_s" value="' . esc_attr($search) . '" class="regular-text" placeholder="Buscar URL, sitemap o error">';
    echo '<button class="button" type="submit">Buscar</button>';
    if ($search !== '') {
        echo '<a class="button" href="' . esc_url(seo_marketing_scan_admin_url(array('inventory_filter' => $filter))) . '">Limpiar</a>';
    }
    echo '</form>';

    echo '<p><strong>' . esc_html(number_format_i18n($total)) . '</strong> resultados para este filtro.</p>';
    echo '<table class="wp-list-table widefat striped seo-scan-table"><thead><tr><th class="url-col">URL</th><th>Sitemap</th><th>Estado</th><th class="small-col">HTTP</th><th class="small-col">Tiempo</th><th>Ultimo chequeo</th><th class="small-col">Fallos seg.</th></tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="7">No hay URLs para este filtro.</td></tr>';
    } else {
        foreach ($rows as $row) {
            $bucket = (string) $row['status_bucket'];
            $is_active = (int) $row['active_in_sitemap'] === 1;
            $row_class = !$is_active || $bucket === 'pending' ? 'is-pending' : ($bucket === 'ok' ? '' : 'is-error');
            $badge_class = !$is_active || $bucket === 'pending' ? 'info' : ($bucket === 'ok' ? 'ok' : ($bucket === '3xx' ? 'warn' : 'err'));
            $http = '—';
            if (!empty($row['http_status'])) {
                $http = (string) (int) $row['http_status'];
                if (!empty($row['redirect_count']) && !empty($row['final_status'])) {
                    $http .= '→' . (int) $row['final_status'];
                }
            }
            $checked = !empty($row['last_checked_at']) ? get_date_from_gmt($row['last_checked_at'], 'd/m/Y H:i') : 'Nunca';
            $sitemap_name = !empty($row['sitemap_url']) ? basename((string) wp_parse_url($row['sitemap_url'], PHP_URL_PATH)) : '—';

            echo '<tr class="' . esc_attr($row_class) . '">';
            echo '<td><code><a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener">' . esc_html($row['url']) . '</a></code>';
            if (!$is_active) {
                echo '<br><small>Fuera del sitemap actual</small>';
            } elseif (!empty($row['error_message'])) {
                echo '<br><small>' . esc_html($row['error_message']) . '</small>';
            }
            echo '</td>';
            echo '<td><code>' . esc_html($sitemap_name) . '</code></td>';
            echo '<td><span class="seo-scan-badge ' . esc_attr($badge_class) . '">' . esc_html(!$is_active ? 'Fuera sitemap' : seo_marketing_scan_bucket_label($bucket)) . '</span></td>';
            echo '<td>' . esc_html($http) . '</td>';
            echo '<td>' . (!empty($row['last_checked_at']) ? esc_html(number_format_i18n((int) $row['response_ms'])) . ' ms' : '—') . '</td>';
            echo '<td>' . esc_html($checked) . '</td>';
            echo '<td>' . esc_html(number_format_i18n((int) $row['consecutive_errors'])) . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';

    if ($total_pages > 1) {
        $base_args = array('inventory_filter' => $filter);
        if ($search !== '') {
            $base_args['inventory_s'] = $search;
        }
        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        echo wp_kses_post(paginate_links(array(
            'base' => add_query_arg(array_merge($base_args, array('inventory_page' => '%#%')), seo_marketing_scan_admin_url()),
            'format' => '', 'current' => $page, 'total' => $total_pages, 'prev_text' => '‹', 'next_text' => '›',
        )));
        echo '</div></div>';
    }
    echo '</div>';
}

function seo_marketing_scan_render_latest_run($scan)
{
    if (!$scan) {
        return;
    }
    $issues = (int) $scan['status_3xx'] + (int) $scan['status_404'] + (int) $scan['status_403'] + (int) $scan['status_5xx'] + (int) $scan['other_errors'];
    $status = (string) $scan['status'];
    $badge = $status === 'completed'
        ? ($issues ? 'warn' : 'ok')
        : (in_array($status, array('failed','cancelled'), true) ? 'err' : 'info');
    echo '<div class="seo-scan-card"><h2 style="margin-top:0">Ultimo lote</h2>';
    echo '<p><span class="seo-scan-badge ' . esc_attr($badge) . '">' . esc_html(seo_marketing_scan_status_label($status)) . '</span> &nbsp; <strong>Modo:</strong> ' . esc_html($scan['selection_mode'] === 'coverage' ? 'Cobertura' : 'Incidencias + muestreo') . '</p>';
    echo '<div class="seo-scan-kpis">';
    seo_marketing_scan_render_kpi((int) $scan['processed_urls'], 'Procesadas', '');
    seo_marketing_scan_render_kpi((int) $scan['status_200'], '200 directos', 'ok');
    seo_marketing_scan_render_kpi((int) $scan['status_3xx'], '3xx', (int) $scan['status_3xx'] ? 'warn' : 'ok');
    seo_marketing_scan_render_kpi((int) $scan['status_404'], '404/410', (int) $scan['status_404'] ? 'err' : 'ok');
    seo_marketing_scan_render_kpi((int) $scan['status_403'], '403', (int) $scan['status_403'] ? 'err' : 'ok');
    seo_marketing_scan_render_kpi((int) $scan['status_5xx'], '5xx', (int) $scan['status_5xx'] ? 'err' : 'ok');
    seo_marketing_scan_render_kpi((int) $scan['other_errors'], 'Otros', (int) $scan['other_errors'] ? 'err' : 'ok');
    echo '</div>';
    if (!empty($scan['error_message'])) {
        echo '<p style="color:#b32d2e"><strong>Error:</strong> ' . esc_html($scan['error_message']) . '</p>';
    }
    echo '</div>';
}

function seo_marketing_render_scan_tab()
{
    seo_marketing_scan_ensure_tables();
    seo_marketing_scan_render_styles();
    seo_marketing_scan_render_notice();

    $stats = seo_marketing_scan_inventory_stats();
    $scan = seo_marketing_scan_latest_run();
    $active_scan = seo_marketing_scan_active_run();
    $config = seo_marketing_scan_get_runner_config();
    $missing = seo_marketing_scan_config_errors($config);
    $coverage = $stats['total'] > 0 ? min(100, round(($stats['checked'] / $stats['total']) * 100, 1)) : 0;

    echo '<h2>Auditoria persistente de URLs del sitemap</h2>';
    echo '<p>El sitemap se convierte en un inventario permanente. Cada ejecucion comprueba como maximo <strong>' . esc_html(number_format_i18n(SEO_MARKETING_SCAN_BATCH_LIMIT)) . ' URLs</strong>. Hasta llegar al 100% se priorizan URLs nunca comprobadas; despues se revisan incidencias y se completa el lote con muestreo rotatorio de URLs sanas.</p>';

    echo '<div class="seo-scan-actions">';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_marketing_scan_inventory_sync">';
    wp_nonce_field('seo_marketing_scan_inventory_sync');
    submit_button('Actualizar inventario', 'secondary', 'submit', false);
    echo '</form>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="seo_marketing_scan_start">';
    wp_nonce_field('seo_marketing_scan_start');
    $start_disabled = !empty($missing) || !empty($active_scan);
    submit_button(
        $stats['pending'] > 0 ? 'Escanear siguiente bloque (max. 500)' : 'Escanear incidencias + muestra (max. 500)',
        'primary',
        'submit',
        false,
        $start_disabled ? array('disabled' => 'disabled') : array()
    );
    echo '</form>';

    if (!empty($active_scan)) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'¿Detener este proceso de escaneo? Las URLs no recibidas quedaran disponibles para el siguiente lote.\');">';
        echo '<input type="hidden" name="action" value="seo_marketing_scan_cancel">';
        echo '<input type="hidden" name="scan_uuid" value="' . esc_attr((string) $active_scan['scan_uuid']) . '">';
        wp_nonce_field('seo_marketing_scan_cancel');
        submit_button('Parar proceso', 'secondary seo-scan-stop', 'submit', false);
        echo '</form>';
    }
    echo '</div>';

    if (!empty($active_scan)) {
        echo '<p><strong>Hay un lote activo:</strong> ' . esc_html(seo_marketing_scan_status_label((string) $active_scan['status'])) . ' · ' . esc_html(number_format_i18n((int) $active_scan['processed_urls'])) . '/' . esc_html(number_format_i18n((int) $active_scan['total_urls'])) . ' resultados recibidos. Al detenerlo se invalida inmediatamente su callback y se habilita un nuevo lote.</p>';
    }

    echo '<div class="seo-scan-kpis">';
    seo_marketing_scan_render_kpi($stats['total'], 'URLs activas', '');
    seo_marketing_scan_render_kpi($stats['checked'], 'Comprobadas', '');
    seo_marketing_scan_render_kpi($stats['pending'], 'Pendientes', $stats['pending'] ? 'warn' : 'ok');
    seo_marketing_scan_render_kpi($stats['ok_count'], 'Correctas', 'ok');
    seo_marketing_scan_render_kpi($stats['issues'], 'Incidencias', $stats['issues'] ? 'err' : 'ok');
    seo_marketing_scan_render_kpi($stats['removed'], 'Fuera del sitemap', '');
    echo '</div>';

    echo '<div class="seo-scan-card"><h3 style="margin-top:0">Cobertura del inventario: ' . esc_html(number_format_i18n($coverage, 1)) . '%</h3>';
    echo '<div class="seo-scan-progress"><span style="width:' . esc_attr((string) $coverage) . '%"></span></div>';
    if ($stats['pending'] > 0) {
        echo '<p>Proximo lote: hasta 500 URLs nunca comprobadas. Quedan <strong>' . esc_html(number_format_i18n($stats['pending'])) . '</strong> pendientes.</p>';
    } else {
        echo '<p>Cobertura completa. Proximos lotes: primero todas las incidencias; los huecos se rellenan con <strong>75% URLs sanas mas antiguas</strong> y <strong>25% muestra aleatoria</strong>.</p>';
    }
    echo '</div>';

    seo_marketing_scan_render_latest_run($scan);
    seo_marketing_scan_render_inventory_table($stats);
    seo_marketing_scan_render_config($config, $missing);
}

function seo_marketing_scan_render_config($config, $missing)
{
    echo '<div class="seo-scan-card"><h2 style="margin-top:0">Runner GitHub</h2><table class="widefat striped"><tbody>';
    echo '<tr><th>Repositorio</th><td><code>' . esc_html(($config['owner'] ?: '—') . '/' . ($config['repo'] ?: '—')) . '</code></td></tr>';
    echo '<tr><th>Workflow</th><td><code>' . esc_html($config['workflow']) . '</code></td></tr>';
    echo '<tr><th>Ref</th><td><code>' . esc_html($config['ref'] ?: '—') . '</code></td></tr>';
    echo '<tr><th>Lote</th><td>Maximo ' . esc_html(number_format_i18n(SEO_MARKETING_SCAN_BATCH_LIMIT)) . ' URLs por ejecucion</td></tr>';
    echo '<tr><th>Conexion</th><td>' . (empty($missing) ? '<span class="seo-scan-badge ok">Operativa</span>' : '<span class="seo-scan-badge err">Incompleta</span>') . '</td></tr>';
    echo '</tbody></table>';
    echo '<p class="description">Reutiliza la conexion <strong>GitHub Actions Python Runner</strong>. WordPress mantiene el inventario y GitHub solo procesa el lote seleccionado.</p>';
    if (!empty($missing)) {
        echo '<p style="color:#b32d2e"><strong>Faltan:</strong> ' . esc_html(implode(', ', $missing)) . '.</p>';
    }
    seo_marketing_scan_render_github_install_instructions();
    echo '</div>';
}

function seo_marketing_scan_render_github_install_instructions()
{
    echo '<details style="margin-top:16px"><summary style="cursor:pointer;font-weight:600">Instalar/actualizar Sitemap Scanner en GitHub</summary>';
    echo '<div style="padding:12px 0"><p>En el repositorio privado de automatizaciones crea o sustituye exactamente estos dos archivos:</p>';
    echo '<ol><li><code>.github/workflows/sitemap-scan.yml</code></li><li><code>scripts/sitemap_scan.py</code></li></ol>';
    echo '<h3><code>.github/workflows/sitemap-scan.yml</code></h3><textarea class="large-text code" rows="24" readonly spellcheck="false">' . esc_textarea(seo_marketing_scan_github_workflow_code()) . '</textarea>';
    echo '<h3><code>scripts/sitemap_scan.py</code></h3><textarea class="large-text code" rows="30" readonly spellcheck="false">' . esc_textarea(seo_marketing_scan_github_python_code()) . '</textarea>';
    echo '</div></details>';
}


function seo_marketing_scan_github_workflow_code()
{
    return <<<'SEO_SITEMAP_WORKFLOW'
name: SEO Taxonomy - Sitemap batch audit

on:
  workflow_dispatch:
    inputs:
      scan_id:
        description: "ID unico del lote"
        required: true
        type: string
      batch_url:
        description: "Endpoint temporal de WordPress que entrega hasta 500 URLs"
        required: true
        type: string
      callback_url:
        description: "Endpoint REST para devolver resultados"
        required: true
        type: string
      callback_token:
        description: "Token temporal de esta ejecucion"
        required: true
        type: string

permissions:
  contents: read

concurrency:
  group: sitemap-audit-${{ inputs.scan_id }}
  cancel-in-progress: false

jobs:
  audit:
    runs-on: ubuntu-latest
    timeout-minutes: 180

    steps:
      - name: Checkout
        uses: actions/checkout@v6

      - name: Setup Python
        uses: actions/setup-python@v5
        with:
          python-version: "3.12"

      - name: Install dependencies
        run: python -m pip install --disable-pip-version-check requests

      - name: Audit selected batch
        env:
          SEO_SCAN_CALLBACK_TOKEN: ${{ inputs.callback_token }}
        run: |
          python scripts/sitemap_scan.py \
            --scan-id '${{ inputs.scan_id }}' \
            --batch-url '${{ inputs.batch_url }}' \
            --callback-url '${{ inputs.callback_url }}' \
            --workers 8 \
            --timeout 15
SEO_SITEMAP_WORKFLOW;
}

function seo_marketing_scan_github_python_code()
{
    return <<<'SEO_SITEMAP_PYTHON'
#!/usr/bin/env python3
"""Auditor adaptativo por lotes para SEO Taxonomy.

WordPress mantiene el inventario y selecciona un lote de hasta 500 URLs.
Este worker descarga ese lote autenticado, comprueba solamente esas URLs y
retorna TODOS los resultados (incluidos los 200) para actualizar el estado
persistente del inventario.

La carga se autorregula: empieza suave, aumenta si el servidor responde bien y
reduce concurrencia/ritmo ante latencia, 429, 502, 503, 504 o timeouts.
"""

from __future__ import annotations

import argparse
import concurrent.futures
import json
import os
import random
import sys
import threading
import time
from collections import deque
from datetime import datetime, timezone
from typing import Callable, Deque, Dict, Iterable, List, Optional, Tuple

import requests
from requests import Response
from requests.exceptions import (
    ConnectionError as RequestsConnectionError,
    InvalidURL,
    RequestException,
    SSLError,
    Timeout,
    TooManyRedirects,
)

USER_AGENT = "SEO-System-Sitemap-Auditor/2.0 (+adaptive-batch-health-check)"
DEFAULT_TIMEOUT = 15.0
DEFAULT_WORKERS = 8
MAX_ADAPTIVE_WORKERS = 8
INITIAL_WORKERS = 2
MAX_BATCH_URLS = 500
CALLBACK_BATCH = 100
MAX_REDIRECTS = 12

INITIAL_REQUEST_INTERVAL = 0.30
MIN_REQUEST_INTERVAL = 0.12
MAX_REQUEST_INTERVAL = 3.00
ADAPT_WINDOW = 30
ADJUST_EVERY = 20
FAST_P95_SECONDS = 0.80
SLOW_P95_SECONDS = 1.50
VERY_SLOW_P95_SECONDS = 2.50
PRESSURE_STATUSES = {429, 502, 503, 504}
PRESSURE_ERROR_TYPES = {"timeout"}

BACKOFF_SECONDS = (2, 4, 8, 16, 30, 45, 60)
TRANSIENT_API_STATUSES = {408, 425, 429, 500, 502, 503, 504}

_thread_local = threading.local()


class AdaptiveLoadController:
    def __init__(self, requested_max_workers: int) -> None:
        self.min_workers = 1
        self.max_workers = max(1, min(MAX_ADAPTIVE_WORKERS, int(requested_max_workers)))
        self._target_workers = min(INITIAL_WORKERS, self.max_workers)
        self._request_interval = INITIAL_REQUEST_INTERVAL
        self._window: Deque[Tuple[float, bool]] = deque(maxlen=ADAPT_WINDOW)
        self._results_since_adjust = 0
        self._cooldown_until = 0.0
        self._last_p95 = 0.0
        self._state_lock = threading.Lock()
        self._pacer_lock = threading.Lock()
        self._next_request_start = 0.0

    @property
    def target_workers(self) -> int:
        with self._state_lock:
            return self._target_workers

    def wait_before_request(self) -> None:
        with self._state_lock:
            base = self._request_interval
        spacing = max(0.01, base * random.uniform(0.85, 1.15))
        now = time.monotonic()
        with self._pacer_lock:
            scheduled = max(now, self._next_request_start)
            self._next_request_start = scheduled + spacing
        delay = scheduled - now
        if delay > 0:
            time.sleep(delay)

    @staticmethod
    def _p95(values: List[float]) -> float:
        if not values:
            return 0.0
        values = sorted(values)
        idx = max(0, min(len(values) - 1, int(len(values) * 0.95 + 0.999999) - 1))
        return values[idx]

    def _pressure_locked(self, callback: bool = False) -> None:
        self._target_workers = max(self.min_workers, self._target_workers // 2)
        floor = 0.75 if callback else 0.45
        factor = 1.50 if callback else 1.35
        cap = 1.50 if callback else 1.20
        self._request_interval = min(cap, max(floor, self._request_interval * factor))
        self._cooldown_until = max(
            self._cooldown_until,
            time.monotonic() + (15.0 if callback else 8.0),
        )
        self._results_since_adjust = 0

    def note_api_pressure(self) -> None:
        with self._state_lock:
            self._pressure_locked(callback=True)

    def note_result(self, result: dict) -> None:
        latency = max(0.0, float(result.get("response_ms") or 0) / 1000.0)
        status = int(result.get("final_status") or result.get("http_status") or 0)
        error_type = str(result.get("error_type") or "")
        pressure = status in PRESSURE_STATUSES or error_type in PRESSURE_ERROR_TYPES

        with self._state_lock:
            self._window.append((latency, pressure))
            self._results_since_adjust += 1
            if pressure:
                self._pressure_locked(False)
                return
            if self._results_since_adjust < ADJUST_EVERY:
                return

            self._results_since_adjust = 0
            latencies = [value for value, _ in self._window if value > 0]
            self._last_p95 = self._p95(latencies)
            if any(flag for _, flag in self._window):
                return
            if time.monotonic() < self._cooldown_until:
                return

            p95 = self._last_p95
            if p95 and p95 < FAST_P95_SECONDS:
                if self._target_workers < self.max_workers:
                    self._target_workers += 1
                self._request_interval = max(MIN_REQUEST_INTERVAL, self._request_interval - 0.10)
            elif p95 > VERY_SLOW_P95_SECONDS:
                self._target_workers = max(self.min_workers, self._target_workers // 2)
                self._request_interval = min(MAX_REQUEST_INTERVAL, max(0.80, self._request_interval * 1.5))
                self._cooldown_until = time.monotonic() + 15.0
            elif p95 > SLOW_P95_SECONDS:
                self._target_workers = max(self.min_workers, self._target_workers - 1)
                self._request_interval = min(MAX_REQUEST_INTERVAL, self._request_interval + 0.15)
            elif self._request_interval > INITIAL_REQUEST_INTERVAL:
                self._request_interval = max(INITIAL_REQUEST_INTERVAL, self._request_interval - 0.05)

    def status_text(self) -> str:
        with self._state_lock:
            return (
                f"workers={self._target_workers}/{self.max_workers} "
                f"intervalo={self._request_interval:.2f}s "
                f"p95={self._last_p95:.2f}s"
            )


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def session() -> requests.Session:
    current = getattr(_thread_local, "session", None)
    if current is None:
        current = requests.Session()
        current.max_redirects = MAX_REDIRECTS
        current.headers.update(
            {
                "User-Agent": USER_AGENT,
                "Accept": "text/html,application/xhtml+xml,application/json,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language": "es,en;q=0.8",
                "Cache-Control": "no-cache",
            }
        )
        _thread_local.session = current
    return current


def response_chain(response: Response) -> List[dict]:
    chain: List[dict] = []
    for hop in response.history:
        chain.append(
            {
                "status": int(hop.status_code),
                "url": hop.url,
                "location": hop.headers.get("Location", ""),
            }
        )
    if response.history:
        chain.append({"status": int(response.status_code), "url": response.url, "location": ""})
    return chain


def classify_response(initial: int, final: int, redirects: int) -> Tuple[str, str]:
    if redirects > 0:
        if final in (404, 410):
            return "redirect_to_404", f"Redireccion termina en HTTP {final}"
        if final == 403:
            return "redirect_to_403", "Redireccion termina en HTTP 403"
        if 500 <= final <= 599:
            return "redirect_to_5xx", f"Redireccion termina en HTTP {final}"
        if final == 429:
            return "redirect_to_429", "Redireccion termina en HTTP 429"
        if final == 200:
            return "redirect", f"La URL del sitemap redirige ({initial} -> 200)"
        return "redirect_error", f"Redireccion termina en HTTP {final}"
    if final == 200:
        return "", ""
    if final in (404, 410):
        return "http_404" if final == 404 else "http_410", f"HTTP {final}"
    if final == 403:
        return "http_403", "HTTP 403"
    if final == 429:
        return "rate_limited", "HTTP 429"
    if 500 <= final <= 599:
        return "http_5xx", f"HTTP {final}"
    if 400 <= final <= 499:
        return "http_4xx", f"HTTP {final}"
    if 300 <= final <= 399:
        return "http_3xx", f"HTTP {final} sin destino final"
    return "http_status", f"HTTP inesperado {final}"


def check_url(url: str, sitemap_url: str, timeout: float, controller: AdaptiveLoadController) -> dict:
    checked_at = utc_now_iso()
    controller.wait_before_request()
    started = time.perf_counter()
    try:
        response = session().get(url, timeout=timeout, allow_redirects=True, stream=True, verify=True)
        try:
            redirects = len(response.history)
            initial = int(response.history[0].status_code) if response.history else int(response.status_code)
            final = int(response.status_code)
            error_type, error_message = classify_response(initial, final, redirects)
            return {
                "resource_type": "page",
                "sitemap_url": sitemap_url,
                "url": url,
                "http_status": initial,
                "final_status": final,
                "final_url": response.url,
                "redirect_count": redirects,
                "redirect_chain": response_chain(response),
                "response_ms": int((time.perf_counter() - started) * 1000),
                "error_type": error_type,
                "error_message": error_message,
                "checked_at": checked_at,
            }
        finally:
            response.close()
    except TooManyRedirects as exc:
        error_type, message = "redirect_loop", f"Demasiadas redirecciones o bucle: {exc}"
    except Timeout as exc:
        error_type, message = "timeout", f"Timeout: {exc}"
    except SSLError as exc:
        error_type, message = "ssl_error", f"SSL: {exc}"
    except InvalidURL as exc:
        error_type, message = "invalid_url", f"URL invalida: {exc}"
    except RequestsConnectionError as exc:
        error_type, message = "connection_error", f"Conexion/DNS: {exc}"
    except RequestException as exc:
        error_type, message = "request_error", f"Peticion: {exc}"
    except Exception as exc:
        error_type, message = "unexpected_error", f"{type(exc).__name__}: {exc}"

    return {
        "resource_type": "page",
        "sitemap_url": sitemap_url,
        "url": url,
        "http_status": 0,
        "final_status": 0,
        "final_url": "",
        "redirect_count": 0,
        "redirect_chain": [],
        "response_ms": int((time.perf_counter() - started) * 1000),
        "error_type": error_type,
        "error_message": message[:1000],
        "checked_at": checked_at,
    }


def _api_request_with_backoff(
    method: str,
    url: str,
    token: str,
    *,
    json_payload: Optional[dict] = None,
    timeout: float = 30.0,
    controller: Optional[AdaptiveLoadController] = None,
) -> Response:
    headers = {"Authorization": f"Bearer {token}", "User-Agent": USER_AGENT}
    if json_payload is not None:
        headers["Content-Type"] = "application/json"

    last_error: Optional[BaseException] = None
    attempts = len(BACKOFF_SECONDS) + 1
    for attempt in range(attempts):
        try:
            response = requests.request(
                method,
                url,
                headers=headers,
                json=json_payload,
                timeout=timeout,
            )
            if 200 <= response.status_code < 300:
                return response
            last_error = RuntimeError(f"HTTP {response.status_code}: {response.text[:500]}")
            if response.status_code not in TRANSIENT_API_STATUSES:
                response.close()
                raise last_error
            if controller is not None:
                controller.note_api_pressure()
            response.close()
        except RequestException as exc:
            last_error = exc
            if controller is not None:
                controller.note_api_pressure()

        if attempt < len(BACKOFF_SECONDS):
            delay = BACKOFF_SECONDS[attempt] * random.uniform(0.90, 1.10)
            print(f"API temporalmente no disponible; reintento en {delay:.1f}s", flush=True)
            time.sleep(delay)

    raise RuntimeError(f"No se pudo completar la llamada API: {last_error}")


def fetch_batch(batch_url: str, callback_token: str, controller: AdaptiveLoadController) -> List[dict]:
    response = _api_request_with_backoff(
        "GET",
        batch_url,
        callback_token,
        timeout=30.0,
        controller=controller,
    )
    try:
        payload = response.json()
    finally:
        response.close()

    items = payload.get("items") if isinstance(payload, dict) else None
    if not isinstance(items, list):
        raise RuntimeError("El endpoint de lote no devolvio items validos")
    if len(items) > MAX_BATCH_URLS:
        raise RuntimeError(f"El lote contiene {len(items)} URLs; maximo permitido {MAX_BATCH_URLS}")

    clean: List[dict] = []
    for item in items:
        if not isinstance(item, dict):
            continue
        url = str(item.get("url") or "").strip()
        sitemap_url = str(item.get("sitemap_url") or "").strip()
        if url.startswith("http://") or url.startswith("https://"):
            clean.append({"url": url, "sitemap_url": sitemap_url})
    return clean


def send_callback(
    callback_url: str,
    callback_token: str,
    payload: dict,
    controller: AdaptiveLoadController,
) -> None:
    response = _api_request_with_backoff(
        "POST",
        callback_url,
        callback_token,
        json_payload=payload,
        timeout=30.0,
        controller=controller,
    )
    response.close()


def chunked(items: List[dict], size: int) -> Iterable[List[dict]]:
    for i in range(0, len(items), size):
        yield items[i : i + size]


def bucket(result: dict) -> str:
    redirects = int(result.get("redirect_count") or 0)
    final = int(result.get("final_status") or result.get("http_status") or 0)
    if redirects > 0:
        return "3xx"
    if final == 200 and not result.get("error_type"):
        return "200"
    if final in (404, 410):
        return "404"
    if final == 403:
        return "403"
    if 500 <= final <= 599:
        return "5xx"
    return "other"


def run(args: argparse.Namespace) -> int:
    callback_token = os.getenv("SEO_SCAN_CALLBACK_TOKEN", "").strip()
    if not callback_token:
        print("ERROR: falta SEO_SCAN_CALLBACK_TOKEN", file=sys.stderr)
        return 2

    controller = AdaptiveLoadController(args.workers)
    started = time.perf_counter()

    try:
        items = fetch_batch(args.batch_url, callback_token, controller)
        total_urls = len(items)
        if total_urls == 0:
            raise RuntimeError("WordPress ha devuelto un lote vacio")

        print(
            f"Auditoria por lote: {total_urls} URLs; inicio={controller.target_workers} "
            f"worker(s), max={controller.max_workers}",
            flush=True,
        )

        send_callback(
            args.callback_url,
            callback_token,
            {"scan_id": args.scan_id, "event": "start", "total_urls": total_urls},
            controller,
        )

        counts: Dict[str, int] = {"200": 0, "3xx": 0, "404": 0, "403": 0, "5xx": 0, "other": 0}
        processed = 0
        buffer: List[dict] = []
        next_index = 0
        in_flight: Dict[concurrent.futures.Future, dict] = {}

        def fill_queue(executor: concurrent.futures.ThreadPoolExecutor) -> None:
            nonlocal next_index
            target = controller.target_workers
            while next_index < total_urls and len(in_flight) < target:
                item = items[next_index]
                next_index += 1
                future = executor.submit(
                    check_url,
                    item["url"],
                    item.get("sitemap_url", ""),
                    args.timeout,
                    controller,
                )
                in_flight[future] = item

        with concurrent.futures.ThreadPoolExecutor(max_workers=controller.max_workers) as executor:
            fill_queue(executor)
            while in_flight:
                done, _ = concurrent.futures.wait(
                    list(in_flight.keys()),
                    return_when=concurrent.futures.FIRST_COMPLETED,
                )
                for future in done:
                    in_flight.pop(future, None)
                    result = future.result()
                    controller.note_result(result)
                    processed += 1
                    counts[bucket(result)] += 1
                    buffer.append(result)  # Importante: tambien guardamos los 200.

                    if len(buffer) >= CALLBACK_BATCH:
                        send_callback(
                            args.callback_url,
                            callback_token,
                            {
                                "scan_id": args.scan_id,
                                "event": "batch",
                                "total_urls": total_urls,
                                "processed_urls": processed,
                                "results": buffer,
                            },
                            controller,
                        )
                        buffer = []

                    if processed % 100 == 0 or processed == total_urls:
                        print(
                            f"Procesadas {processed}/{total_urls} URLs | {controller.status_text()}",
                            flush=True,
                        )

                fill_queue(executor)

        if buffer:
            send_callback(
                args.callback_url,
                callback_token,
                {
                    "scan_id": args.scan_id,
                    "event": "batch",
                    "total_urls": total_urls,
                    "processed_urls": processed,
                    "results": buffer,
                },
                controller,
            )

        summary = {
            "processed_urls": processed,
            "total_urls": total_urls,
            "status_200": counts["200"],
            "status_3xx": counts["3xx"],
            "status_404": counts["404"],
            "status_403": counts["403"],
            "status_5xx": counts["5xx"],
            "other_errors": counts["other"],
            "duration_ms": int((time.perf_counter() - started) * 1000),
        }
        send_callback(
            args.callback_url,
            callback_token,
            {"scan_id": args.scan_id, "event": "complete", "summary": summary},
            controller,
        )
        print(json.dumps(summary, indent=2, ensure_ascii=False))
        return 0

    except Exception as exc:
        message = f"{type(exc).__name__}: {exc}"
        print(f"ERROR: {message}", file=sys.stderr)
        try:
            send_callback(
                args.callback_url,
                callback_token,
                {"scan_id": args.scan_id, "event": "failed", "error_message": message[:2000]},
                controller,
            )
        except Exception as callback_exc:
            print(f"ERROR adicional enviando callback de fallo: {callback_exc}", file=sys.stderr)
        return 1


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Audita un lote de hasta 500 URLs seleccionado por WordPress")
    parser.add_argument("--scan-id", required=True)
    parser.add_argument("--batch-url", required=True)
    parser.add_argument("--callback-url", required=True)
    parser.add_argument("--workers", type=int, default=DEFAULT_WORKERS)
    parser.add_argument("--timeout", type=float, default=DEFAULT_TIMEOUT)
    args = parser.parse_args()
    args.workers = max(1, min(MAX_ADAPTIVE_WORKERS, args.workers))
    args.timeout = max(3.0, min(60.0, args.timeout))
    return args


if __name__ == "__main__":
    raise SystemExit(run(parse_args()))
SEO_SITEMAP_PYTHON;
}
