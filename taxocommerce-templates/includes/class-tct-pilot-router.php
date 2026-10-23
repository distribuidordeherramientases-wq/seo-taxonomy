<?php
/**
 * Enrutamiento del piloto afiliado por pais.
 * Migrado del antiguo template-loader.php, usando solo archivos del
 * nuevo modulo y las taxonomias/metadatos de WordPress.
 */
defined('ABSPATH') || exit;

final class TCT_Pilot_Router {
    public static function market_for_term($term) {
        if (!($term instanceof WP_Term) || 'product_cat' !== $term->taxonomy) {
            return '';
        }
        $helper = TCT_Router::path('template-pilot-market.php');
        if (!$helper) {
            return '';
        }
        require_once $helper;
        return function_exists('dht_pilot_market_for_term')
            ? (string)dht_pilot_market_for_term($term) : '';
    }

    public static function market_for_request() {
        if (is_tax('product_cat')) {
            return self::market_for_term(get_queried_object());
        }
        if (is_singular('product')) {
            $product_id = absint(get_queried_object_id());
            $country = strtoupper(trim((string)get_post_meta($product_id, '_seo_pais_origen', true)));
            $provider = strtoupper(trim((string)get_post_meta($product_id, '_seo_proveedor', true)));
            if ('VEVOR' === $provider && in_array($country,array('US','DE','NL','FR'),true)) {
                return $country;
            }
            $terms = get_the_terms($product_id,'product_cat');
            if (!is_array($terms) || is_wp_error($terms)) {
                return '';
            }
            $markets = array();
            foreach ($terms as $term) {
                $market = self::market_for_term($term);
                if ($market !== '') {
                    $markets[$market] = true;
                }
            }
            return count($markets) === 1 ? (string)key($markets) : '';
        }
        if (is_singular('page')) {
            $id = absint(get_queried_object_id());
            $slug = (string)get_post_field('post_name', $id);
            if (!preg_match('/^vevor-(us|de|nl|fr)-[a-z0-9-]+$/',$slug,$matches)) {
                return '';
            }
            $expected = strtoupper($matches[1]);
            $metadata = strtoupper(trim((string)get_post_meta($id,'dht_pilot_market',true)));
            if ($metadata !== '' && $metadata !== $expected) {
                return '';
            }
            global $wpdb;
            $nodes = $wpdb->prefix . 'seo_nodes';
            $relations = $wpdb->prefix . 'seo_relations';
            if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($relations)))) {
                return '';
            }
            $role_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$nodes}
                 WHERE object_type = 'page' AND object_id = %d
                   AND seo_role = 'landing' AND status = 1 LIMIT 1", $id
            ));
            if (!$role_id) {
                return '';
            }
            $term_ids = (array)$wpdb->get_col($wpdb->prepare(
                "SELECT target_id FROM {$relations}
                 WHERE source_type = 'landing' AND source_id = %d
                   AND target_type = 'product_cat'
                   AND relation_type = 'landing_to_category'", $id
            ));
            if (!$term_ids) {
                return '';
            }
            foreach ($term_ids as $term_id) {
                $term = get_term(absint($term_id), 'product_cat');
                if (!($term instanceof WP_Term) || self::market_for_term($term) !== $expected) {
                    return '';
                }
            }
            return $expected;
        }
        return '';
    }

    public static function resolve() {
        if (!is_tax('product_cat') && !is_singular('product') && !is_singular('page')) {
            return '';
        }
        $market = self::market_for_request();
        if (!in_array($market,array('US','DE','NL','FR'),true)) {
            return '';
        }
        $pilot = TCT_Router::path('template-pilot-vevor.php');
        if (!$pilot) {
            return '';
        }
        $GLOBALS['dht_pilot_market'] = $market;
        if ($market === 'FR') {
            $specific = is_tax('product_cat') ? 'template-category-fr.php' :
                (is_singular('product') ? 'template-product-fr.php' : '');
            if ($specific !== '') {
                $path = TCT_Router::path($specific);
                if ($path) {
                    return $path;
                }
            }
        }
        return $pilot;
    }
}
