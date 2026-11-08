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
    private function option_name( $slug ) {
        return 'tc_official_page_' . sanitize_key( $slug );
    }

    private function action_name( $slug ) {
        return 'tc_save_official_page_' . str_replace( '-', '_', sanitize_key( $slug ) );
    }

    public function register( $slug ) {
        add_action(
            'admin_post_' . $this->action_name( $slug ),
            function () use ( $slug ) {
                $this->save( $slug );
            }
        );
    }

    public function save( $slug ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( $this->action_name( $slug ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
        $submitted = isset( $_POST['tc_official_url'] ) && is_string( $_POST['tc_official_url'] )
            ? trim( sanitize_text_field( wp_unslash( $_POST['tc_official_url'] ) ) ) : '';
        $url = $submitted ? esc_url_raw( $submitted, array( 'https' ) ) : '';
        $parts = $url ? wp_parse_url( $url ) : array();
        if ( $submitted && ( ! is_array( $parts )
            || ! isset( $parts['scheme'], $parts['host'] )
            || 'https' !== strtolower( $parts['scheme'] )
            || isset( $parts['user'] ) || isset( $parts['pass'] )
            || ! filter_var( $url, FILTER_VALIDATE_URL ) ) ) {
            wp_die( esc_html__( 'Enter a valid HTTPS page URL.', 'taxocommerce-google-bing' ) );
        }

        update_option( $this->option_name( $slug ), $url, false );
        wp_safe_redirect( admin_url( 'admin.php?page=' . sanitize_key( $slug ) ) );
        exit;
    }

    private function labels( $locale ) {
        $labels = array(
            'en' => array( 'Plugin information', 'Official plugin page', 'Source page URL (HTTPS)', 'Save', 'No official page configured.', 'Excerpt temporarily unavailable.' ),
            'es' => array( 'Información del plugin', 'Ver ficha oficial', 'URL de la ficha oficial (HTTPS)', 'Guardar', 'No hay ficha oficial configurada.', 'Extracto temporalmente no disponible.' ),
            'fr' => array( 'Informations sur le plugin', 'Voir la fiche officielle', 'URL de la page officielle (HTTPS)', 'Enregistrer', 'Aucune page officielle configurée.', 'Extrait temporairement indisponible.' ),
            'de' => array( 'Plugininformationen', 'Offizielle Pluginseite', 'URL der offiziellen Seite (HTTPS)', 'Speichern', 'Keine offizielle Seite konfiguriert.', 'Auszug vorübergehend nicht verfügbar.' ),
            'nl' => array( 'Plugininformatie', 'Officiële pluginpagina', 'URL van de officiële pagina (HTTPS)', 'Opslaan', 'Geen officiële pagina ingesteld.', 'Samenvatting tijdelijk niet beschikbaar.' ),
        );
        // Prefer the native WordPress language catalog where it is available.
        $english = $labels['en'];
        $selected = $labels[ isset( $labels[ $locale ] ) ? $locale : 'en' ];
        $singular = array(
            __( 'Plugin information', 'taxocommerce-google-bing' ),
            __( 'Official plugin page', 'taxocommerce-google-bing' ),
            __( 'Source page URL (HTTPS)', 'taxocommerce-google-bing' ),
            __( 'Save', 'taxocommerce-google-bing' ),
            __( 'No official page configured.', 'taxocommerce-google-bing' ),
            __( 'Excerpt temporarily unavailable.', 'taxocommerce-google-bing' ),
        );
        foreach ( $singular as $index => $translated ) {
            if ( $translated !== $english[ $index ] ) {
                $selected[ $index ] = $translated;
            }
        }
        return $selected;
    }

    /**
     * Fetch only the REST page excerpt after an administrator configures a URL.
     * Cached by site and source; errors are cached too to protect performance.
     */
    private function excerpt( $url ) {
        $key = 'tc_official_excerpt_' . md5( $url );
        $cached = get_transient( $key );
        if ( is_array( $cached ) && isset( $cached['text'] ) ) {
            return $cached['text'];
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
        set_transient( $key, array( 'text' => $text ), $text ? 12 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
        return $text;
    }

    public function render( $slug, $locale ) {
        $words = $this->labels( $locale );
        $page = get_option( $this->option_name( $slug ), '' );
        $page = is_string( $page ) ? esc_url_raw( $page, array( 'https' ) ) : '';
        $excerpt = $page ? $this->excerpt( $page ) : '';

        echo '<details class="tc-family-info">';
        echo '<summary>' . esc_html( $words[0] ) . '</summary>';
        echo '<div class="tc-family-info__content">';
        if ( $excerpt ) {
            echo '<p>' . esc_html( $excerpt ) . '</p>';
        } else {
            echo '<p>' . esc_html( $page ? $words[5] : $words[4] ) . '</p>';
        }
        if ( $page ) {
            echo '<p><a href="' . esc_url( $page ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $words[1] ) . '</a></p>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( $this->action_name( $slug ) ) . '">';
        wp_nonce_field( $this->action_name( $slug ) );
        echo '<label>' . esc_html( $words[2] ) . ' <input type="url" name="tc_official_url" value="' . esc_attr( $page ) . '" placeholder="https://…" class="regular-text" maxlength="2048"></label> ';
        echo '<button type="submit" class="button button-secondary">' . esc_html( $words[3] ) . '</button>';
        echo '</form></div></details>';
    }
};
