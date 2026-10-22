<?php
/**
 * Detects a pilot market only from explicit WooCommerce category slug prefixes.
 * Spanish storefront categories remain unchanged.
 */
defined('ABSPATH') || exit;

if (!function_exists('dht_pilot_market_for_term')) {
    function dht_pilot_market_for_term($term) {
        if (!($term instanceof WP_Term) || 'product_cat' !== $term->taxonomy) {
            return '';
        }
        $ids = array_merge(
            array((int) $term->term_id),
            get_ancestors($term->term_id, 'product_cat', 'taxonomy')
        );
        $patterns = array(
            'US' => '/^(?:vevor-)?(?:us|usa|eua|eeuu|estados-unidos|united-states|en-us)(?:-|$)/',
            'DE' => '/^(?:vevor-)?(?:(?:de|deu)(?:$|-(?:best-sellers|bestsellers|kategorie|category|produkte|products)(?:-|$))|(?:deutschland|alemania|germany|de-de)(?:-|$))/',
            'NL' => '/^(?:vevor-)?(?:nl|nld|nederland|netherlands|holanda|paises-bajos|nl-nl)(?:-|$)/',
            'FR' => '/^(?:vevor-)?(?:fr|fra|france|fr-fr)(?:-|$)/',
        );
        foreach ($ids as $id) {
            $category = get_term((int) $id, 'product_cat');
            if (!($category instanceof WP_Term)) {
                continue;
            }
            foreach ($patterns as $country => $pattern) {
                if (preg_match($pattern, (string) $category->slug)) {
                    return $country;
                }
            }
        }
        return '';
    }
}

if (!function_exists('dht_pilot_country_for_term')) {
    function dht_pilot_country_for_term($term) {
        return dht_pilot_market_for_term($term);
    }
}
