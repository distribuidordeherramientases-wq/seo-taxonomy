<?php
/**
 * Comparador - lectura pública ligera.
 *
 * Las plantillas solo consumen resultados persistidos. Nunca recalculan perfiles
 * ni consultan Ojeador durante la visita.
 */

defined('ABSPATH') || exit;

final class SEO_Comparador_Public {
    public static function init() {
        add_shortcode('seo_comparador_extracto', array(__CLASS__, 'shortcode'));
    }

    public static function payload_for_category($term_id) {
        $profile=SEO_Comparador_DB::profile_for_category(absint($term_id));
        if (!$profile) return array();
        $map=SEO_Comparador_DB::post_map(absint($profile['id']));
        $post_id=absint($map['post_id'] ?? 0);
        if (!$post_id || get_post_status($post_id)!=='publish') return array();

        $editorial=SEO_Comparador_DB::editorial(absint($profile['id']));
        $stored_excerpt=(string)get_post_meta($post_id,'_seo_comparador_excerpt',true);
        $stored_axes=get_post_meta($post_id,'_seo_comparador_axes',true);
        $axes=is_array($stored_axes)?$stored_axes:array();
        if (!$axes) {
            foreach (SEO_Comparador_DB::axes(absint($profile['id'])) as $axis) {
                if (!empty($axis['publishable'])) $axes[]=sanitize_text_field((string)$axis['label']);
                if (count($axes)>=5) break;
            }
        }

        return array(
            'profile_id'=>absint($profile['id']),
            'category_id'=>absint($term_id),
            'title'=>get_the_title($post_id),
            'excerpt'=>$stored_excerpt !== '' ? $stored_excerpt : sanitize_text_field((string)($editorial['excerpt'] ?? '')),
            'axes'=>array_slice(array_values(array_filter(array_map('sanitize_text_field',$axes))),0,5),
            'url'=>get_permalink($post_id),
            'post_id'=>$post_id,
            'status'=>(string)$profile['status'],
        );
    }

    public static function payload_for_product($product_id) {
        $terms=wp_get_post_terms(absint($product_id),'product_cat');
        if (is_wp_error($terms) || !$terms) return array();
        usort($terms,static function($a,$b){
            $da=count(get_ancestors(absint($a->term_id),'product_cat'));
            $db=count(get_ancestors(absint($b->term_id),'product_cat'));
            return $db<=>$da;
        });
        foreach ($terms as $term) {
            $payload=self::payload_for_category(absint($term->term_id));
            if ($payload) {
                $payload['product_id']=absint($product_id);
                return $payload;
            }
        }
        return array();
    }

    public static function render_category($term_id) {
        self::render(self::payload_for_category($term_id),'category');
    }

    public static function render_product($product_id) {
        self::render(self::payload_for_product($product_id),'product');
    }

    private static function render($payload,$context) {
        if (!$payload) return;
        $context=sanitize_key((string)$context);
        echo '<section class="dht-comparador-extract dht-comparador-extract-' . esc_attr($context) . '">';
        echo '<div class="dht-container"><div class="dht-comparador-extract__card">';
        echo '<span class="dht-kicker">Comparativa</span>';
        echo '<h2>' . esc_html((string)$payload['title']) . '</h2>';
        if (!empty($payload['excerpt'])) echo '<p>' . esc_html((string)$payload['excerpt']) . '</p>';
        if (!empty($payload['axes'])) {
            echo '<ul class="dht-comparador-extract__axes">';
            foreach ($payload['axes'] as $axis) echo '<li>' . esc_html($axis) . '</li>';
            echo '</ul>';
        }
        echo '<p><a class="button dht-comparador-extract__link" href="' . esc_url((string)$payload['url']) . '">Ver comparativa completa</a></p>';
        echo '</div></div></section>';
    }

    public static function shortcode($atts=array()) {
        $atts=shortcode_atts(array('category_id'=>0,'product_id'=>0),$atts,'seo_comparador_extracto');
        ob_start();
        if (absint($atts['product_id'])) self::render_product(absint($atts['product_id']));
        elseif (absint($atts['category_id'])) self::render_category(absint($atts['category_id']));
        return ob_get_clean();
    }
}

if (!function_exists('seo_comparador_render_category_block')) {
    function seo_comparador_render_category_block($term_id) {
        SEO_Comparador_Public::render_category(absint($term_id));
    }
}

if (!function_exists('seo_comparador_render_product_block')) {
    function seo_comparador_render_product_block($product_id) {
        SEO_Comparador_Public::render_product(absint($product_id));
    }
}
