<?php

defined( 'ABSPATH' ) || exit;

/**
 * Google Trends: user-directed Explore charts embedded in the admin UI.
 *
 * The historic official Trending Now RSS integration remains in the code for
 * compatibility, but it is no longer shown on this screen. No old snapshots
 * are deleted and there are no automatic searches or scheduled syncs.
 * The official Trends API remains alpha-restricted.
 */
final class TaxoCommerce_Google_Bing_Trends {
    const OPTION_PREFIX = 'tgb_trends_rss_snapshot_v1_';

    public static function register() {
        add_action( 'admin_post_tgb_trends_download', array( __CLASS__, 'download' ) );
        add_action( 'admin_post_tgb_trends_export', array( __CLASS__, 'export_json' ) );
        add_action( 'admin_post_tgb_trends_explore', array( __CLASS__, 'explore_terms' ) );
    }

    /**
     * WooCommerce provides the country names without maintaining a restrictive
     * country allowlist in this plugin. Google determines RSS availability.
     */
    public static function countries() {
        $featured = array(
            'ES' => 'Spain',
            'DE' => 'Alemania',
            'FR' => 'Francia',
            'NL' => 'Netherlands',
            'US' => 'United States',
        );
        $additional = array();
        if ( function_exists( 'WC' ) ) {
            $woocommerce = WC();
            if ( is_object( $woocommerce ) && isset( $woocommerce->countries )
                && is_object( $woocommerce->countries )
                && method_exists( $woocommerce->countries, 'get_countries' ) ) {
                foreach ( (array) $woocommerce->countries->get_countries() as $code => $name ) {
                    if ( preg_match( '/^[A-Z]{2}$/', (string) $code ) && ! isset( $featured[ $code ] ) ) {
                        $additional[ $code ] = (string) $name;
                    }
                }
            }
        }
        asort( $additional, SORT_NATURAL | SORT_FLAG_CASE );
        return $featured + $additional;
    }

    private static function valid_geo( $geo ) {
        $geo = is_scalar( $geo ) ? strtoupper( trim( (string) $geo ) ) : '';
        return preg_match( '/^[A-Z]{2}$/', $geo ) ? $geo : 'ES';
    }

    private static function geo_label( $geo ) {
        $countries = self::countries();
        return $countries[ $geo ] ?? $geo;
    }

    public static function saved_snapshot( $geo = 'ES' ) {
        $data = get_option( self::OPTION_PREFIX . self::valid_geo( $geo ), array() );
        return is_array( $data ) && ! empty( $data['items'] ) ? $data : array();
    }

    private static function dashboard_url( $geo ) {
        return add_query_arg(
            array(
                'page'       => 'taxocommerce-google-bing',
                'tgb_tab'    => 'google_trends',
                'trends_geo' => self::valid_geo( $geo ),
            ),
            admin_url( 'admin.php' )
        );
    }

    private static function trending_url( $geo ) {
        return add_query_arg(
            array( 'geo' => self::valid_geo( $geo ) ),
            'https://trends.google.com/trending'
        );
    }

    private static function rss_url( $geo ) {
        return add_query_arg(
            array( 'geo' => self::valid_geo( $geo ) ),
            'https://trends.google.com/trending/rss'
        );
    }

    private static function explore_url( $term, $geo ) {
        return add_query_arg(
            array(
                'q'   => (string) $term,
                'geo' => self::valid_geo( $geo ),
            ),
            'https://trends.google.com/trends/explore'
        );
    }

    /**
     * A line, comma or semicolon separates terms. Spaces remain part of each
     * phrase. Google Trends compares a limited number of terms at a time.
     */
    public static function parse_terms( $raw ) {
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return new WP_Error( 'tgb_trends_terms_empty', 'Introduce al menos una palabra o frase.' );
        }
        if ( strlen( $raw ) > 1500 ) {
            return new WP_Error( 'tgb_trends_terms_long', 'The term list is too long.' );
        }
        $terms = array();
        $seen = array();
        foreach ( (array) preg_split( '/[\r\n;,]+/u', $raw ) as $part ) {
            $term = trim( sanitize_text_field( $part ) );
            if ( '' === $term ) {
                continue;
            }
            $length = function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term );
            if ( $length > 120 ) {
                return new WP_Error( 'tgb_trends_term_long', 'Each phrase may contain at most 120 characters.' );
            }
            $key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term, 'UTF-8' ) : strtolower( $term );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $terms[] = $term;
            if ( count( $terms ) > 5 ) {
                return new WP_Error( 'tgb_trends_terms_limit', 'Compare at most five words or phrases per query.' );
            }
        }
        return $terms ? $terms : new WP_Error( 'tgb_trends_terms_empty', 'Introduce al menos una palabra o frase.' );
    }

    /**
     * Official Google Trends Explore URL only. This does not fetch, scrape
     * or store arbitrary keyword-interest series on the WordPress server.
     */
    public static function explore_link( $terms, $geo = 'ES', $period = 'today 12-m' ) {
        $periods = array( 'now 7-d', 'today 1-m', 'today 3-m', 'today 12-m', 'today 5-y' );
        $period = in_array( $period, $periods, true ) ? $period : 'today 12-m';
        // This Trends dashboard analyses only Spain; ignore legacy geo inputs.
        $arguments = array(
            'q'    => implode( ',', (array) $terms ),
            'date' => $period,
            'geo'  => 'ES',
        );
        return add_query_arg( $arguments, 'https://trends.google.com/trends/explore' );
    }

    public static function explore_terms() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( 'tgb_trends_explore' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
        $raw = isset( $_POST['trends_terms'] ) && is_string( $_POST['trends_terms'] )
            ? wp_unslash( $_POST['trends_terms'] ) : '';
        $terms = self::parse_terms( $raw );
        if ( is_wp_error( $terms ) ) {
            wp_die( esc_html( $terms->get_error_message() ) );
        }
        // Spanish-only Trends; never accept country overrides from POST.
        $geo = 'ES';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
        $period = isset( $_POST['explore_period'] ) && is_string( $_POST['explore_period'] )
            ? sanitize_text_field( wp_unslash( $_POST['explore_period'] ) ) : 'today 12-m';

        // Fixed trusted destination, with validated parameters. The browser
        // opens the official Explore interface only after the button click.
        wp_redirect( self::explore_link( $terms, $geo, $period ), 302, 'TaxoCommerce Google/Bing' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External official Google host is intentional.
        exit;
    }

    public static function render() {
        // Enqueue a local script only: no Google request occurs until a search.
        wp_enqueue_script(
            'taxocommerce-google-bing-trends',
            TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/trends-embed.js',
            array(),
            TAXOCOMMERCE_GOOGLE_BING_VERSION,
            true
        );
        echo '<div class="tgb-trends">';
        echo '<h2>Google Trends</h2>';
        echo '<p>Explore search interest for the terms you choose in the selected country and period. <strong>Click “Search trends” to display the charts below.</strong></p>';

        echo '<section class="tgb-trends-query">';
        echo '<form id="tgb-trends-search-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" target="_blank" rel="noopener noreferrer">';
        echo '<input type="hidden" name="action" value="tgb_trends_explore">';
        // Accessible no-JavaScript fallback: original handler opens Explore.
        wp_nonce_field( 'tgb_trends_explore' );
        echo '<label for="tgb-trends-terms"><strong>Words or phrases to explore</strong></label>';
        echo '<textarea id="tgb-trends-terms" class="large-text" name="trends_terms" rows="4" maxlength="1500" required placeholder="cordless drills&#10;Makita&#10;impact wrenches"></textarea>';
        echo '<p class="description">Enter one word or phrase per line, up to five. Results are shown only for those searches.</p>';

        echo '<div class="tgb-trends-controls">';
        echo '<span><strong>Región:</strong> España</span>';
        echo '<input type="hidden" id="tgb-trends-explore-geo" name="explore_geo" value="ES">';

        echo '<label for="tgb-trends-explore-period"><strong>Period:</strong></label>';
        echo '<select id="tgb-trends-explore-period" name="explore_period">';
        foreach ( array(
            'now 7-d' => 'Last 7 days',
            'today 1-m' => 'Last 30 days',
            'today 3-m' => 'Last 90 days',
            'today 12-m' => 'Last 12 months',
            'today 5-y' => 'Last 5 years',
        ) as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '"' . ( 'today 12-m' === $value ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="tgb-trends-actions">';
        echo '<button class="button button-primary" type="submit">Search trends</button>';
        echo '<a class="button" id="tgb-trends-open-google" href="https://trends.google.com/trends/explore" target="_blank" rel="noopener noreferrer">Open in Google Trends ↗</a>';
        echo '</div>';
        echo '<p id="tgb-trends-validation" class="notice notice-error inline" role="alert" hidden></p>';
        echo '</form>';
        echo '</section>';

        echo '<section id="tgb-trends-results" class="tgb-trends-results" aria-label="Google Trends results" hidden></section>';
        echo '<div id="tgb-trends-empty" class="tgb-trends-empty">';
        echo '<strong>No results for this query yet.</strong>';
        echo '<p>Enter terms, choose a country and period, then click “Search trends”. No automatic query is made when opening this tab.</p>';
        echo '</div>';

        echo '<p class="description tgb-trends-api"><strong>Source: </strong><a href="https://trends.google.com/trends/" target="_blank" rel="noopener noreferrer">Google Trends</a>. Embedded charts show relative interest indexes (0–100), not absolute volumes or store visits. Google may limit embedding for some charts. The <a href="https://developers.google.com/search/apis/trends" target="_blank" rel="noopener noreferrer">official API for downloadable series</a> remains in restricted alpha access.</p>';
        echo '<style>
          .tgb-trends{margin-top:18px;max-width:1250px}
          .tgb-trends-query{margin:18px 0;padding:18px;background:#fff;border:1px solid #dcdcde;border-radius:6px}
          .tgb-trends-query textarea{display:block;margin:12px 0 8px;max-width:800px}
          .tgb-trends-query p{max-width:830px}
          .tgb-trends-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:16px 0}
          .tgb-trends-controls select{max-width:230px}
          .tgb-trends-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px}
          .tgb-trends-empty{padding:22px;background:#fff;border:1px solid #dcdcde;border-radius:6px;margin-top:18px}
          .tgb-trends-results{margin-top:22px}
          .tgb-trends-result-heading{margin-bottom:14px}
          .tgb-trends-result-heading h3{font-size:18px;margin:0 0 8px}
          .tgb-trends-result-heading a{margin:6px 0}
          .tgb-trends-chart{background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:14px 0;padding:14px;overflow:hidden}
          .tgb-trends-chart h3{font-size:16px;margin:4px 0}
          .tgb-trends-chart iframe{display:block;width:100%;max-width:100%;border:0;background:#fff;min-height:390px}
          .tgb-trends-embed-notice{margin-top:14px}
          .tgb-trends-api{margin:18px 0}
          #tgb-trends-validation:not([hidden]){display:block;padding:10px;margin:12px 0}
          @media(max-width:782px){
            .tgb-trends-query{padding:12px}
            .tgb-trends-controls{align-items:stretch}
            .tgb-trends-controls select{max-width:100%}
            .tgb-trends-chart{padding:8px}
          }
        </style>';
        echo '</div>';
    }

    /**
     * Parses the documented Trending Now RSS; this is not an Explore API.
     * The function is network-free and may be regression tested independently.
     *
     * @return array|WP_Error
     */
    public static function parse_rss( $body ) {
        if ( ! function_exists( 'simplexml_load_string' ) ) {
            return new WP_Error( 'tgb_trends_simplexml', 'SimpleXML is not enabled in PHP.' );
        }
        if ( '' === trim( (string) $body ) ) {
            return new WP_Error( 'tgb_trends_empty', 'Google Trends returned an empty RSS feed.' );
        }

        $previous = libxml_use_internal_errors( true );
        $xml = simplexml_load_string( (string) $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( false === $xml || ! isset( $xml->channel->item ) ) {
            return new WP_Error( 'tgb_trends_xml', 'The RSS feed does not contain a valid trends list.' );
        }

        $items = array();
        $seen = array();
        foreach ( $xml->channel->item as $item ) {
            $title = sanitize_text_field( (string) $item->title );
            if ( '' === $title || isset( $seen[ $title ] ) ) {
                continue;
            }
            $seen[ $title ] = true;

            $namespaces = $item->getNamespaces( true );
            $ht_namespace = isset( $namespaces['ht'] ) ? $namespaces['ht'] : 'https://trends.google.com/trending/rss';
            $ht = $item->children( $ht_namespace );
            $traffic = sanitize_text_field( (string) $ht->approx_traffic );
            $published_at = strtotime( (string) $item->pubDate );

            $items[] = array(
                'title'        => $title,
                'traffic_label' => '' !== $traffic ? $traffic : 'No disponible',
                'published_at' => $published_at ? (int) $published_at : 0,
            );

            if ( count( $items ) >= 100 ) {
                break;
            }
        }
        if ( ! $items ) {
            return new WP_Error( 'tgb_trends_no_items', 'El RSS de Google Trends no contiene tendencias reconocibles.' );
        }
        return $items;
    }

    /**
     * Only called from download(), never from render(), plugin boot or cron.
     */
    public static function fetch_rss( $geo ) {
        $geo = self::valid_geo( $geo );
        $url = self::rss_url( $geo );
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 20,
                'redirection'         => 0,
                'limit_response_size' => 1024 * 1024,
                'headers' => array(
                    'Accept'          => 'application/rss+xml,application/xml,text/xml;q=0.9',
                    'Accept-Language' => 'es-ES,es;q=0.9',
                    'User-Agent'      => 'TaxoCommerce-Google-Bing/' . TAXOCOMMERCE_GOOGLE_BING_VERSION,
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'tgb_trends_connection', 'Google Trends RSS could not be downloaded: ' . $response->get_error_message() );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'tgb_trends_http', 'Google Trends returned HTTP ' . $code . '. The saved snapshot was not modified.' );
        }
        $items = self::parse_rss( wp_remote_retrieve_body( $response ) );
        if ( is_wp_error( $items ) ) {
            return $items;
        }
        return array( 'source_url' => $url, 'items' => $items );
    }

    public static function download() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to download reports.', 'taxocommerce-google-bing' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified with source-specific nonce below.
        $geo = isset( $_POST['trends_geo'] ) ? self::valid_geo( wp_unslash( $_POST['trends_geo'] ) ) : 'ES';
        check_admin_referer( 'tgb_trends_download_' . $geo );

        $lock = 'tgb_trends_download_lock_' . get_current_blog_id() . '_' . $geo;
        if ( get_transient( $lock ) ) {
            self::finish( $geo, 'A Google Trends download is already running for this country.', true );
        }
        set_transient( $lock, 1, 90 );
        $result = self::fetch_rss( $geo );
        delete_transient( $lock );

        if ( is_wp_error( $result ) ) {
            self::finish( $geo, $result->get_error_message(), true );
        }
        $snapshot = array(
            'schema'        => 'tgb-google-trends-rss-v1',
            'source'        => 'Google Trends - Trending Now RSS',
            'country'       => $geo,
            'source_url'    => $result['source_url'],
            'downloaded_at' => time(),
            'items'         => $result['items'],
        );
        update_option( self::OPTION_PREFIX . $geo, $snapshot, false );
        do_action( 'tgb_trends_snapshot_saved', $geo, $snapshot );
        self::finish( $geo, 'Tendencias de ' . self::geo_label( $geo ) . ' descargadas y guardadas.', false );
    }

    public static function export_json() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to export reports.', 'taxocommerce-google-bing' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified immediately below.
        $geo = isset( $_GET['trends_geo'] ) ? self::valid_geo( wp_unslash( $_GET['trends_geo'] ) ) : 'ES';
        check_admin_referer( 'tgb_trends_export_' . $geo );

        $snapshot = self::saved_snapshot( $geo );
        if ( ! $snapshot ) {
            wp_die( esc_html__( 'There are no saved data to export.', 'taxocommerce-google-bing' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="google_trends_' . $geo . '.json"' );
        echo wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON export endpoint.
        exit;
    }

    private static function finish( $geo, $message, $error ) {
        set_transient(
            'tgb_trends_notice_' . get_current_user_id() . '_' . $geo,
            array( 'error' => (bool) $error, 'message' => sanitize_text_field( $message ) ),
            2 * MINUTE_IN_SECONDS
        );
        wp_safe_redirect( self::dashboard_url( $geo ) );
        exit;
    }
}
