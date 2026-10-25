<?php
defined('ABSPATH') || exit;

function tccampaigns_banner_items($atts) {
    $atts = shortcode_atts(array('id'=>0,'key'=>'','limit'=>8), $atts, 'taxocommerce_campaign');
    $items = tccampaigns_get_public_active();
    $id = absint($atts['id']);
    $key = sanitize_title((string)$atts['key']);
    if ($id || $key) {
        $items = array_values(array_filter($items, static function($item) use($id,$key){
            $c=$item['campaign']??null;
            if(!$c) return false;
            return ($id && absint($c->id)===$id) || ($key && sanitize_title((string)$c->campaign_key)===$key);
        }));
    }
    $limit=max(1,min(24,absint($atts['limit'])));
    foreach($items as &$item) $item['products']=array_slice((array)$item['products'],0,$limit);
    unset($item);
    return $items;
}

function tccampaigns_banner_shortcode($atts=array()) {
    $items=tccampaigns_banner_items($atts);
    if(!$items) return '';
    static $styled=false;
    ob_start();
    if(!$styled){
        $styled=true;
        echo '<style>.tcc-public-campaign{margin:20px 0;padding:18px;border:1px solid #dcdcde;border-radius:10px;background:#f7f9fb}.tcc-public-campaign h2{margin:0 0 4px}.tcc-public-products{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:14px}.tcc-public-product{display:block;padding:12px;background:#fff;border:1px solid #e1e4e7;border-radius:8px;text-decoration:none;color:inherit}.tcc-public-product img{width:100%;aspect-ratio:1/1;object-fit:contain}.tcc-public-price{font-size:1.15em;font-weight:700}.tcc-public-old{text-decoration:line-through;opacity:.65;margin-left:6px}.tcc-public-badge{display:inline-block;padding:3px 7px;border-radius:999px;background:#fff3cd;font-weight:700;font-size:12px}</style>';
    }
    foreach($items as $item){
        $c=$item['campaign'];
        echo '<section class="tcc-public-campaign" data-campaign="'.esc_attr((string)$c->campaign_key).'"><h2>'.esc_html((string)$c->name).'</h2>';
        if(!empty($c->edition_label)) echo '<div>'.esc_html((string)$c->edition_label).'</div>';
        echo '<div class="tcc-public-products">';
        foreach((array)$item['products'] as $p){
            $img='';
            if(!empty($p['product']) && is_callable(array($p['product'],'get_image_id'))){
                $aid=absint($p['product']->get_image_id());
                if($aid) $img=(string)wp_get_attachment_image_url($aid,'medium');
            }
            if(!$img && function_exists('tccampaigns_social_product_image_url')) $img=tccampaigns_social_product_image_url($p);
            echo '<a class="tcc-public-product" href="'.esc_url((string)$p['url']).'">';
            if($img) echo '<img src="'.esc_url($img).'" alt="'.esc_attr((string)$p['name']).'" loading="lazy">';
            if(!empty($p['discount_percent'])) echo '<span class="tcc-public-badge">-'.esc_html((string)$p['discount_percent']).'%</span>';
            echo '<h3>'.esc_html((string)$p['name']).'</h3><span class="tcc-public-price">'.wp_kses_post(wc_price($p['campaign_price'])).'</span>';
            if(!empty($p['regular_price']) && $p['regular_price']>$p['campaign_price']) echo '<span class="tcc-public-old">'.wp_kses_post(wc_price($p['regular_price'])).'</span>';
            echo '</a>';
        }
        echo '</div></section>';
    }
    return (string)ob_get_clean();
}
add_shortcode('taxocommerce_campaign','tccampaigns_banner_shortcode');
add_shortcode('oferta','tccampaigns_banner_shortcode');
