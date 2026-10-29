<?php
/**
 * Stable country-specific WooCommerce category navigation for VEVOR pilot pages.
 *
 * The order and visible slots are consistent across product/category/landing pages.
 * Links are resolved from actual WordPress terms, never from invented URLs or IDs.
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dht_pilot_country_navigation' ) ) {
    function dht_pilot_country_navigation( $market ) {
        $empty = array(
            'market_root' => null,
            'featured'    => array(),
            'roots'       => array(),
            'children'    => array(),
        );
        $market = strtoupper( (string) $market );
        if ( ! in_array( $market, array( 'US', 'DE', 'NL', 'FR' ), true ) || ! taxonomy_exists( 'product_cat' ) ) {
            return $empty;
        }

        // Container categories organise each international hierarchy, but the
        // visitor menu should show their actual child categories.
        $containers = array(
            'US' => array( 'vevor-us', 'us', 'usa', 'eua', 'eeuu', 'estados-unidos', 'united-states', 'en-us' ),
            'DE' => array( 'vevor-de', 'de', 'alemania', 'deutschland', 'germany', 'de-de' ),
            'NL' => array( 'vevor-nl', 'nl', 'holanda', 'paises-bajos', 'nederland', 'netherlands', 'nl-nl' ),
            'FR' => array( 'vevor-fr', 'fr', 'france', 'fr-fr' ),
        );

        $all = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        if ( is_wp_error( $all ) || ! is_array( $all ) ) {
            return $empty;
        }

        $market_terms = array();
        $container_terms = array();
        foreach ( $all as $term ) {
            if ( ! $term instanceof WP_Term || dht_pilot_country_for_term( $term ) !== $market ) {
                continue;
            }
            $term_id = (int) $term->term_id;
            $market_terms[ $term_id ] = $term;
            if ( in_array( $term->slug, $containers[ $market ], true ) ) {
                $container_terms[ $term->slug ] = $term;
            }
        }

        // Use the canonical VEVOR market root where available. When regional
        // categories are flat, fall back to the first existing regional term.
        $root_term = $container_terms[ 'vevor-' . strtolower( $market ) ] ?? null;
        if ( ! $root_term && $container_terms ) {
            $root_term = reset( $container_terms );
        }

        $roots = array();
        $children = array();
        foreach ( $market_terms as $term ) {
            if ( in_array( $term->slug, $containers[ $market ], true ) ) {
                continue;
            }

            $parent = (int) $term->parent;
            if (
                $parent > 0
                && isset( $market_terms[ $parent ] )
                && ! in_array( $market_terms[ $parent ]->slug, $containers[ $market ], true )
            ) {
                $children[ $parent ][] = $term;
            } else {
                $roots[] = $term;
            }
        }

        if ( ! $roots && $root_term instanceof WP_Term ) {
            $roots[] = $root_term;
        }

        return array(
            'market_root' => $root_term,
            'featured'    => array_slice( $roots, 0, 6 ),
            'roots'       => $roots,
            'children'    => $children,
        );
    }
}

if ( ! function_exists( 'dht_pilot_render_category_menu_tree' ) ) {
    /**
     * Render nested category links in the native, keyboard-accessible menu.
     * A depth limit protects the public view against corrupted term parents.
     */
    function dht_pilot_render_category_menu_tree( $terms, $children, $depth = 0 ) {
        if ( empty( $terms ) || $depth > 8 ) {
            return;
        }

        echo '<ul class="dht-pilot-menu-list dht-pilot-menu-list--depth-' . esc_attr( (string) $depth ) . '">';
        foreach ( $terms as $term ) {
            if ( ! $term instanceof WP_Term ) {
                continue;
            }
            $url = get_term_link( $term );
            if ( is_wp_error( $url ) || ! $url ) {
                continue;
            }
            $active = (int) get_queried_object_id() === (int) $term->term_id
                && function_exists( 'is_product_category' ) && is_product_category();

            echo '<li>';
            echo '<a href="' . esc_url( $url ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>'
                . esc_html( $term->name ) . '</a>';
            if ( ! empty( $children[ $term->term_id ] ) ) {
                dht_pilot_render_category_menu_tree( $children[ $term->term_id ], $children, $depth + 1 );
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}

/**
 * Resolve the country menu built by the TaxoCommerce menu manager.
 * A missing or incorrectly assigned menu never falls back to ES or to
 * another market. Core is optional: use the native menu name as a fallback.
 */
if ( ! function_exists( 'dht_pilot_country_managed_menu_id' ) ) {
    function dht_pilot_country_managed_menu_id( $market ) {
        $market = strtoupper( trim( (string) $market ) );
        if ( ! in_array( $market, array( 'US', 'DE', 'NL', 'FR' ), true ) ) {
            return 0;
        }

        $name = 'TaxoCommerce - ' . $market;
        $menu_id = 0;
        if ( class_exists( 'TaxoCommerce_Core_Menu', false )
            && is_callable( array( 'TaxoCommerce_Core_Menu', 'managed_menu_id' ) ) ) {
            $menu_id = absint( TaxoCommerce_Core_Menu::managed_menu_id( $market, false ) );
        }

        $menu = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;
        if ( ! ( $menu instanceof WP_Term ) || (string) $menu->name !== $name ) {
            $menu = wp_get_nav_menu_object( $name );
        }

        return $menu instanceof WP_Term && (string) $menu->name === $name
            ? absint( $menu->term_id ) : 0;
    }
}
