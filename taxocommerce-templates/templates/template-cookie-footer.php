<?php
/**
 * Policy-page links and consent-preferences control shared by public templates.
 *
 * The country is explicit; no translated links are guessed from the browser
 * language or from international categories.
 *
 * @package TaxoCommerceTemplates
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dht_template_cookie_footer_markets' ) ) {
    function dht_template_cookie_footer_markets() {
        return array(
            'ES' => array(
                'slug' => 'politica-de-cookies',
                'policy' => 'Política de cookies',
                'preferences' => 'Preferencias de cookies',
                'navigation' => 'Privacidad y cookies',
            ),
            'US' => array(
                'slug' => 'cookie-policy-us',
                'policy' => 'Cookie policy',
                'preferences' => 'Cookie preferences',
                'navigation' => 'Privacy and cookies',
            ),
            'FR' => array(
                'slug' => 'politique-de-cookies-fr',
                'policy' => 'Politique relative aux cookies',
                'preferences' => 'Préférences de cookies',
                'navigation' => 'Confidentialité et cookies',
            ),
            'DE' => array(
                'slug' => 'cookie-richtlinie-de',
                'policy' => 'Cookie-Richtlinie',
                'preferences' => 'Cookie-Einstellungen',
                'navigation' => 'Datenschutz und Cookies',
            ),
            'NL' => array(
                'slug' => 'cookiebeleid-nl',
                'policy' => 'Cookiebeleid',
                'preferences' => 'Cookievoorkeuren',
                'navigation' => 'Privacy en cookies',
            ),
        );
    }
}

if ( ! function_exists( 'dht_template_cookie_policy_url' ) ) {
    /**
     * Return only the published page for the requested country.
     * Never link a draft or another country's legal text.
     */
    function dht_template_cookie_policy_url( $market ) {
        $market = strtoupper( trim( (string) $market ) );
        $countries = dht_template_cookie_footer_markets();
        if ( ! isset( $countries[ $market ] ) ) {
            return '';
        }

        $page = get_page_by_path( $countries[ $market ]['slug'], OBJECT, 'page' );
        if ( ! ( $page instanceof WP_Post ) || 'publish' !== $page->post_status ) {
            return '';
        }
        $page_market = strtoupper( trim( (string) get_post_meta( $page->ID, '_seo_pais_origen', true ) ) );
        if ( $page_market !== $market ) {
            return '';
        }
        $url = get_permalink( $page );
        return $url && ! is_wp_error( $url ) ? (string) $url : '';
    }
}

if ( ! function_exists( 'dht_template_render_cookie_footer_links' ) ) {
    /** Show the policy and a voluntary reopen action, without changing consent. */
    function dht_template_render_cookie_footer_links( $market ) {
        $market = strtoupper( trim( (string) $market ) );
        $countries = dht_template_cookie_footer_markets();
        if ( ! isset( $countries[ $market ] ) ) {
            return;
        }
        $labels = $countries[ $market ];
        $url = dht_template_cookie_policy_url( $market );
        $active = class_exists( 'TaxoCommerce_Insights_Consent', false )
            && TaxoCommerce_Insights_Consent::active();

        if ( '' === $url && ! $active ) {
            return;
        }
        echo '<nav class="dht-footer-cookie-links" aria-label="' . esc_attr( $labels['navigation'] ) . '"'
            . ' style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-top:12px;">';
        if ( '' !== $url ) {
            echo '<a href="' . esc_url( $url ) . '">' . esc_html( $labels['policy'] ) . '</a>';
        }
        if ( $active ) {
            wp_enqueue_script(
                'taxocommerce-templates-cookie-footer',
                TCT_URL . 'assets/footer-cookie-preferences.js',
                array(),
                TCT_VERSION,
                true
            );
            echo '<button type="button" data-tci-open-preferences'
                . ' style="font:inherit;background:none;border:0;padding:0;color:inherit;text-decoration:underline;cursor:pointer;">'
                . esc_html( $labels['preferences'] ) . '</button>';
        }
        echo '</nav>';
    }
}
