<?php
/**
 * Compact manual navigation between the Spanish store and the VEVOR pilot
 * markets. Country URLs must correspond to existing WooCommerce categories.
 * This selector never infers country from IP, language or visitor location.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/template-pilot-market.php';

if ( ! function_exists( 'dht_pilot_country_switcher_urls' ) ) {
    function dht_pilot_country_switcher_urls() {
        static $urls = null;
        if ( null !== $urls ) {
            return $urls;
        }

        $urls = array( 'ES' => home_url( '/' ) );
        $candidate_slugs = array(
            'US' => array( 'vevor-us', 'us', 'usa', 'eua', 'eeuu', 'estados-unidos', 'united-states', 'en-us' ),
            'DE' => array( 'vevor-de', 'de', 'alemania', 'deutschland', 'germany', 'de-de' ),
            'NL' => array( 'vevor-nl', 'nl', 'holanda', 'paises-bajos', 'nederland', 'netherlands', 'nl-nl' ),
            'FR' => array( 'vevor-fr' ),
        );
        if ( ! taxonomy_exists( 'product_cat' ) ) {
            return $urls;
        }

        // A short query for known regional roots avoids scanning the whole
        // product catalogue on each public page.
        $roots = get_terms( array(
            'taxonomy'                   => 'product_cat',
            'slug'                       => array_values( array_unique( array_merge( ...array_values( $candidate_slugs ) ) ) ),
            'hide_empty'                 => false,
            'number'                     => 0,
            'seo_pilot_country_switcher' => true,
        ) );
        $by_slug = array();
        if ( is_array( $roots ) && ! is_wp_error( $roots ) ) {
            foreach ( $roots as $root ) {
                if ( $root instanceof WP_Term ) {
                    $by_slug[ $root->slug ] = $root;
                }
            }
        }

        $unresolved = array();
        foreach ( $candidate_slugs as $country => $slugs ) {
            foreach ( $slugs as $slug ) {
                if ( empty( $by_slug[ $slug ] ) ) {
                    continue;
                }
                $term = $by_slug[ $slug ];
                if ( dht_pilot_country_for_term( $term ) !== $country
                    || ( 'FR' === $country && (int) $term->parent !== 0 ) ) {
                    continue;
                }
                $link = get_term_link( $term );
                if ( ! is_wp_error( $link ) && $link ) {
                    $urls[ $country ] = (string) $link;
                    break;
                }
            }
            // France must use its dedicated root; never infer its home from
            // a random subcategory or from a different market.
            if ( ! isset( $urls[ $country ] ) && 'FR' !== $country ) {
                $unresolved[] = $country;
            }
        }

        // Fallback for older country hierarchies without canonical root slugs.
        // Never invent a path: choose an existing regional category.
        if ( $unresolved ) {
            $all_terms = get_terms( array(
                'taxonomy'                   => 'product_cat',
                'hide_empty'                 => false,
                'number'                     => 0,
                'seo_pilot_country_switcher' => true,
            ) );
            if ( is_array( $all_terms ) && ! is_wp_error( $all_terms ) ) {
                $country_terms = array();
                foreach ( $all_terms as $term ) {
                    if ( ! $term instanceof WP_Term ) {
                        continue;
                    }
                    $country = dht_pilot_country_for_term( $term );
                    if ( ! in_array( $country, $unresolved, true ) ) {
                        continue;
                    }
                    $depth = count( get_ancestors( (int) $term->term_id, 'product_cat', 'taxonomy' ) );
                    if ( ! isset( $country_terms[ $country ] ) || $depth < $country_terms[ $country ]['depth'] ) {
                        $country_terms[ $country ] = array( 'term' => $term, 'depth' => $depth );
                    }
                }
                foreach ( $country_terms as $country => $item ) {
                    $link = get_term_link( $item['term'] );
                    if ( ! is_wp_error( $link ) && $link ) {
                        $urls[ $country ] = (string) $link;
                    }
                }
            }
        }

        return $urls;
    }
}

if ( ! function_exists( 'dht_pilot_render_country_switcher' ) ) {
    /**
     * Show exactly the other markets with valid destination URLs.
     * All visible labels are available to keyboard and screen-reader users.
     */
    function dht_pilot_render_country_switcher( $current = 'ES' ) {
        $current = strtoupper( (string) $current );
        $labels = array(
            'ES' => array( 'flag' => '🇪🇸', 'name' => 'España' ),
            'US' => array( 'flag' => '🇺🇸', 'name' => 'United States' ),
            'DE' => array( 'flag' => '🇩🇪', 'name' => 'Deutschland' ),
            'NL' => array( 'flag' => '🇳🇱', 'name' => 'Nederland' ),
            'FR' => array( 'flag' => '🇫🇷', 'name' => 'France' ),
        );
        $nav_labels = array(
            'ES' => 'Ver tiendas de otros países',
            'US' => 'Explore other countries',
            'DE' => 'Andere Länder entdecken',
            'NL' => 'Bekijk andere landen',
            'FR' => 'Explorer les autres pays',
        );
        $urls = dht_pilot_country_switcher_urls();
        $items = array();
        foreach ( $labels as $country => $label ) {
            if ( $country !== $current && ! empty( $urls[ $country ] ) ) {
                $items[ $country ] = $label;
            }
        }
        if ( ! $items ) {
            return;
        }

        echo '<nav class="dht-country-switcher" aria-label="' . esc_attr( $nav_labels[ $current ] ?? $nav_labels['ES'] ) . '">';
        foreach ( $items as $country => $item ) {
            echo '<a class="dht-country-switcher__link" href="' . esc_url( $urls[ $country ] ) . '"'
                . ' aria-label="' . esc_attr( $item['name'] ) . '"'
                . ' title="' . esc_attr( $item['name'] ) . '">'
                . '<span aria-hidden="true">' . esc_html( $item['flag'] ) . '</span>'
                . '</a>';
        }
        echo '</nav>';
    }
}
