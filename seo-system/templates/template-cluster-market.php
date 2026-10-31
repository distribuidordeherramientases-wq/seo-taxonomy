<?php
/**
 * Resolve the market of a cluster page without relying on visitor geolocation.
 * The legacy Spanish pages keep their existing default.
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dht_cluster_country_for_page' ) ) {
    function dht_cluster_country_for_page( $page_id ) {
        $page_id = absint( $page_id );
        $code = strtoupper( trim( (string) get_post_meta( $page_id, '_seo_pais_origen', true ) ) );
        if ( in_array( $code, array( 'ES', 'US', 'DE', 'NL', 'FR' ), true ) ) {
            return $code;
        }
        if ( class_exists( 'TaxoCommerce_Core_Country', false ) ) {
            return TaxoCommerce_Core_Country::post( $page_id );
        }
        // Read-only compatibility when Core is temporarily inactive.
        $locale = strtolower( str_replace( '_', '-', (string) get_post_meta( $page_id, '_seo_locale', true ) ) );
        foreach ( array( 'en-us' => 'US', 'de' => 'DE', 'nl' => 'NL', 'fr' => 'FR' ) as $prefix => $market ) {
            if ( $locale === $prefix || 0 === strpos( $locale, $prefix . '-' ) ) {
                return $market;
            }
        }
        $slug = (string) get_post_field( 'post_name', $page_id );
        if ( preg_match( '/^vevor-(us|de|nl|fr)(?:-|$)/', $slug, $match ) ) {
            return strtoupper( $match[1] );
        }
        return 'ES';
    }
}
