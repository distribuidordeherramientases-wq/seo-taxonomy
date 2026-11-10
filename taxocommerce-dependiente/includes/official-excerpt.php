<?php
/**
 * Optional official-page excerpt for one standalone TaxoCommerce plugin.
 *
 * This copy is generated for each plugin. No connection to another
 * TaxoCommerce plugin or a particular commercial website is required.
 *
 * @package TaxoCommerce
 */
defined( 'ABSPATH' ) || exit;

return new class() {
    private function labels( $locale ) {
        $labels = array(
            'en' => array( 'Plugin information', 'Official plugin page', 'No official page configured.', 'Excerpt temporarily unavailable.' ),
            'es' => array( 'Información del plugin', 'Ver ficha oficial', 'No hay ficha oficial configurada.', 'Extracto temporalmente no disponible.' ),
            'fr' => array( 'Informations sur le plugin', 'Voir la fiche officielle', 'Aucune page officielle configurée.', 'Extrait temporairement indisponible.' ),
            'de' => array( 'Plugininformationen', 'Offizielle Pluginseite', 'Keine offizielle Seite konfiguriert.', 'Auszug vorübergehend nicht verfügbar.' ),
            'nl' => array( 'Plugininformatie', 'Officiële pluginpagina', 'Geen officiële pagina ingesteld.', 'Samenvatting tijdelijk niet beschikbaar.' ),
        );
        // Prefer the native WordPress language catalog where it is available.
        $english = $labels['en'];
        $selected = $labels[ isset( $labels[ $locale ] ) ? $locale : 'en' ];
        $singular = array(
            __( 'Plugin information', 'taxocommerce-dependiente' ),
            __( 'Official plugin page', 'taxocommerce-dependiente' ),
            __( 'No official page configured.', 'taxocommerce-dependiente' ),
            __( 'Excerpt temporarily unavailable.', 'taxocommerce-dependiente' ),
        );
        foreach ( $singular as $index => $translated ) {
            if ( $translated !== $english[ $index ] ) {
                $selected[ $index ] = $translated;
            }
        }
        return $selected;
    }

    /**
     * Fetch the official excerpt from the developer's public REST endpoint.
     *
     * Do not use the customer store as a source. Remember the last verified
     * excerpt locally so network interruptions never erase known information.
     * Cache failures only briefly, without persisting their empty response.
     */
    private function excerpt( $url ) {
        // v2 deliberately bypasses negative transients made by older releases.
        $hash     = md5( $url );
        $key      = 'tc_official_excerpt_v2_' . $hash;
        $last_key = 'tc_official_excerpt_good_' . $hash;
        $last     = get_option( $last_key, '' );
        $last     = is_string( $last ) ? $last : '';
        $cached   = get_transient( $key );
        if ( is_array( $cached ) && isset( $cached['text'] ) && is_string( $cached['text'] ) ) {
            return '' !== trim( $cached['text'] ) ? $cached['text'] : $last;
        }

        $parts = wp_parse_url( $url );
        $path = is_array( $parts ) && isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
        $slug = $path ? sanitize_title( rawurldecode( basename( $path ) ) ) : '';
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || 'https' !== ( $parts['scheme'] ?? '' ) || ! $slug ) {
            return '';
        }
        $origin = 'https://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '' );
        $endpoint = add_query_arg(
            array( 'slug' => $slug, 'per_page' => 1, '_fields' => 'excerpt,link,status' ),
            $origin . '/wp-json/wp/v2/pages'
        );
        $text = '';
        $response = wp_safe_remote_get(
            $endpoint,
            array( 'timeout' => 4, 'redirection' => 2, 'limit_response_size' => 65536 )
        );
        if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
            $rows = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( is_array( $rows ) && isset( $rows[0]['excerpt']['rendered'], $rows[0]['link'] )
                && is_string( $rows[0]['excerpt']['rendered'] ) ) {
                $source_link = esc_url_raw( $rows[0]['link'], array( 'https' ) );
                $source_host = wp_parse_url( $source_link, PHP_URL_HOST );
                $source_path = wp_parse_url( $source_link, PHP_URL_PATH );
                $returned_slug = sanitize_title( rawurldecode( basename( trim( (string) $source_path, '/' ) ) ) );
                if ( is_string( $source_host ) && strtolower( $source_host ) === strtolower( $parts['host'] )
                    && $returned_slug === $slug ) {
                    $text = wp_html_excerpt(
                        html_entity_decode( wp_strip_all_tags( $rows[0]['excerpt']['rendered'] ), ENT_QUOTES, 'UTF-8' ),
                        700,
                        '…'
                    );
                }
            }
        }
        if ( '' !== trim( $text ) && $text !== $last ) {
            // A bounded, sanitized excerpt from the developer's own URL.
            update_option( $last_key, $text, false );
        }
        set_transient( $key, array( 'text' => $text ), $text ? 12 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
        return '' !== trim( $text ) ? $text : $last;
    }

    public function render( $slug, $locale ) {
        $words = $this->labels( $locale );
        // Plugin URI belongs to the plugin developer. A store administrator
        // cannot change the official documentation source from this screen.
        $plugin_file = dirname( __DIR__ ) . '/' . basename( dirname( __DIR__ ) ) . '.php';
        $page = '';
        $fallback = '';
        if ( is_readable( $plugin_file ) ) {
            $headers = get_file_data(
                $plugin_file,
                array( 'uri' => 'Plugin URI', 'description' => 'Description' ),
                'plugin'
            );
            $page = isset( $headers['uri'] ) && is_string( $headers['uri'] ) ? $headers['uri'] : '';
            $fallback = isset( $headers['description'] ) && is_string( $headers['description'] )
                ? wp_html_excerpt( wp_strip_all_tags( $headers['description'] ), 300, '…' ) : '';
        }
        $page = $page ? esc_url_raw( $page, array( 'https' ) ) : '';
        $excerpt = $page ? $this->excerpt( $page ) : '';
        // Offline first-install fallback: the plugin author's own Description.
        // Never substitute the customer site's posts or excerpt fields.
        if ( '' === trim( $excerpt ) && $page ) {
            $excerpt = $fallback;
        }

        echo '<details class="tc-family-info">';
        echo '<summary>' . esc_html( $words[0] ) . '</summary>';
        echo '<div class="tc-family-info__content">';
        if ( $excerpt ) {
            echo '<p>' . esc_html( $excerpt ) . '</p>';
        } else {
            echo '<p>' . esc_html( $page ? $words[3] : $words[2] ) . '</p>';
        }
        if ( $page ) {
            echo '<p><a href="' . esc_url( $page ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $words[1] ) . '</a></p>';
        }
        echo '</div></details>';
    }
};
