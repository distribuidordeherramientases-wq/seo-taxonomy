<?php
/**
 * Mercado data adapters. Network access is restricted to explicit scans or an
 * operator-started queue, never page rendering, activation, or category sync.
 *
 * "Discovered" means a search result was returned, NOT that its content was
 * read, checked or that a consumer opinion was verified.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_Market_Sources {
    const SERPAPI_MONTHLY_MAX = 200;
    const SERPAPI_USAGE_OPTION = 'tgb_market_serpapi_monthly_usage';

    public static function any_global_provider() {
        return '' !== (string) get_option( 'tgb_market_youtube_key', '' )
            || '' !== (string) get_option( 'tgb_market_serpapi_key', '' )
            || '' !== (string) TaxoCommerce_Google_Bing_Connections::merchant_account();
    }

    public static function any_available( $category ) {
        return '' !== (string) get_option( 'tgb_market_youtube_key', '' )
            || '' !== (string) get_option( 'tgb_market_serpapi_key', '' )
            || ( ! empty( $category['google_category_id'] )
                && '' !== (string) TaxoCommerce_Google_Bing_Connections::merchant_account() );
    }

    private static function region( $category ) {
        $code = strtoupper( (string) ( $category['country'] ?? 'ES' ) );
        return in_array( $code, array( 'ES', 'DE', 'FR', 'NL', 'US' ), true ) ? $code : 'ES';
    }

    private static function language( $category ) {
        $locale = (string) ( $category['locale'] ?? 'es-ES' );
        $lang = strtolower( substr( $locale, 0, 2 ) );
        return preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : 'es';
    }

    private static function clean( $value, $limit = 500 ) {
        return mb_substr( sanitize_text_field( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) ), 0, $limit );
    }

    private static function safe_url( $url ) {
        $url = esc_url_raw( (string) $url );
        return preg_match( '~^https://~i', $url ) ? $url : '';
    }

    private static function request_json( $url, $method = 'GET', $data = array(), $headers = array() ) {
        $args = array(
            'timeout' => 22,
            'redirection' => 0,
            'limit_response_size' => 1024 * 1024,
            'headers' => $headers,
        );
        if ( 'POST' === $method ) {
            $args['body'] = wp_json_encode( $data );
            $response = wp_safe_remote_post( $url, $args );
        } else {
            $response = wp_safe_remote_get( $url, $args );
        }
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'market_remote_failure', 'Solicitud externa fallida: ' . self::clean( $response->get_error_message(), 140 ) );
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $status < 200 || $status >= 300 ) {
            $reason = is_array( $body ) ? self::clean( (string) ( $body['error']['message'] ?? $body['error'] ?? '' ), 120 ) : '';
            // Never include request URLs: YouTube/SerpApi credentials are query parameters.
            return new WP_Error( 'market_http_error', 'Proveedor HTTP ' . $status . ( $reason ? ' · ' . $reason : '' ) );
        }
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'market_response_invalid', 'El proveedor no ha devuelto JSON válido.' );
        }
        return $body;
    }

    public static function collect( $category ) {
        $all = array( 'items' => array(), 'providers' => array(), 'errors' => array() );
        $tasks = array();
        if ( '' !== (string) get_option( 'tgb_market_youtube_key', '' ) ) {
            $tasks['youtube'] = self::youtube( $category );
        }
        if ( '' !== (string) get_option( 'tgb_market_serpapi_key', '' ) ) {
            $tasks['web'] = self::serpapi( $category, 'google' );
            $tasks['shopping'] = self::serpapi( $category, 'google_shopping' );
        }
        if ( ! empty( $category['google_category_id'] ) && TaxoCommerce_Google_Bing_Connections::merchant_account() ) {
            $tasks['merchant'] = self::merchant_best_sellers( $category );
        }
        foreach ( $tasks as $provider => $result ) {
            if ( is_wp_error( $result ) ) {
                $all['errors'][] = $provider . ': ' . $result->get_error_message();
                $all['providers'][ $provider ] = array( 'status' => 'error', 'count' => 0 );
            } else {
                $items = (array) ( $result['items'] ?? array() );
                $all['items'] = array_merge( $all['items'], $items );
                $all['providers'][ $provider ] = array( 'status' => 'ok', 'count' => count( $items ) );
            }
        }
        return $all;
    }

    /**
     * YouTube search costs 100 units per category, plus one low-cost comment
     * lookup for each selected video. Only public top-level comments are read.
     */
    private static function youtube( $category ) {
        $key = (string) get_option( 'tgb_market_youtube_key', '' );
        $q = trim( (string) $category['name'] . ' opiniones experiencia uso problemas comparativa' );
        $params = array(
            'part' => 'snippet', 'type' => 'video', 'maxResults' => 5,
            'q' => $q, 'regionCode' => self::region( $category ),
            'relevanceLanguage' => self::language( $category ),
            'safeSearch' => 'moderate', 'key' => $key,
        );
        $result = self::request_json( add_query_arg( $params, 'https://www.googleapis.com/youtube/v3/search' ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $items = array();
        $seen = array();
        foreach ( (array) ( $result['items'] ?? array() ) as $video ) {
            $id = (string) ( $video['id']['videoId'] ?? '' );
            if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) || isset( $seen[ $id ] ) ) {
                continue;
            }
            $seen[ $id ] = true;
            $snippet = (array) ( $video['snippet'] ?? array() );
            $link = 'https://www.youtube.com/watch?v=' . $id;
            $items[] = array(
                'provider' => 'youtube',
                'type' => 'video',
                'key' => $id,
                'url' => $link,
                'title' => self::clean( $snippet['title'] ?? '' ),
                'snippet' => self::clean( $snippet['description'] ?? '', 900 ),
                'level' => 'published_video',
                'observed_at' => strtotime( (string) ( $snippet['publishedAt'] ?? '' ) ) ?: null,
                'metadata' => array( 'video_id' => $id ),
            );
            $comments = self::request_json( add_query_arg( array(
                'part' => 'snippet', 'videoId' => $id,
                'maxResults' => 10, 'order' => 'relevance',
                'textFormat' => 'plainText', 'key' => $key,
            ), 'https://www.googleapis.com/youtube/v3/commentThreads' ) );
            if ( is_wp_error( $comments ) ) {
                // Comments can be disabled. A valid video is still evidence of a video.
                continue;
            }
            foreach ( (array) ( $comments['items'] ?? array() ) as $comment ) {
                $top = (array) ( $comment['snippet']['topLevelComment'] ?? array() );
                $cid = (string) ( $top['id'] ?? '' );
                $cs = (array) ( $top['snippet'] ?? array() );
                $text = self::clean( $cs['textOriginal'] ?? $cs['textDisplay'] ?? '', 1100 );
                if ( '' === $cid || '' === $text ) {
                    continue;
                }
                $items[] = array(
                    'provider' => 'youtube',
                    'type' => 'comment',
                    'key' => $id . ':' . $cid,
                    'url' => $link . '&lc=' . rawurlencode( $cid ),
                    'title' => self::clean( $snippet['title'] ?? '' ),
                    'evidence' => $text,
                    'level' => 'public_comment',
                    'observed_at' => strtotime( (string) ( $cs['publishedAt'] ?? '' ) ) ?: null,
                    'metadata' => array( 'video_id' => $id, 'comment_id' => $cid,
                        'like_count' => absint( $cs['likeCount'] ?? 0 ) ),
                );
            }
        }
        return array( 'items' => $items );
    }

    /**
     * SerpApi results link to third-party sites. Snippets are discovery data,
     * not independently verified consumer testimony. Shopping shows examples
     * of individual offers, NEVER a homogeneous price average for a category.
     */
    private static function serpapi_budget() {
        $month = gmdate( 'Y-m' );
        $saved = get_option( self::SERPAPI_USAGE_OPTION, array() );
        $used = ( $saved['month'] ?? '' ) === $month ? absint( $saved['calls'] ?? 0 ) : 0;
        if ( $used >= self::SERPAPI_MONTHLY_MAX ) {
            return false;
        }
        update_option( self::SERPAPI_USAGE_OPTION, array( 'month' => $month, 'calls' => $used + 1 ), false );
        return true;
    }

    private static function serpapi( $category, $engine ) {
        if ( ! self::serpapi_budget() ) {
            return new WP_Error( 'market_serpapi_monthly_limit', 'Límite interno de 200 consultas SerpApi al mes alcanzado.' );
        }
        $key = (string) get_option( 'tgb_market_serpapi_key', '' );
        $query = (string) $category['name'];
        if ( 'google' === $engine ) {
            $query .= ' opiniones usuarios problemas consejos foro experiencias';
        }
        $params = array(
            'engine' => $engine, 'q' => $query,
            'gl' => strtolower( self::region( $category ) ),
            'hl' => self::language( $category ),
            'api_key' => $key,
        );
        $result = self::request_json( add_query_arg( $params, 'https://serpapi.com/search.json' ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( ! empty( $result['error'] ) ) {
            return new WP_Error( 'market_serpapi_failure', self::clean( $result['error'], 170 ) );
        }
        $items = array();
        if ( 'google' === $engine ) {
            foreach ( array_slice( (array) ( $result['organic_results'] ?? array() ), 0, 10 ) as $web ) {
                $url = self::safe_url( $web['link'] ?? '' );
                if ( '' === $url ) {
                    continue;
                }
                $items[] = array(
                    'provider' => 'serpapi',
                    'type' => 'web_result',
                    'key' => $url,
                    'url' => $url,
                    'title' => self::clean( $web['title'] ?? '' ),
                    'snippet' => self::clean( $web['snippet'] ?? '', 900 ),
                    'level' => 'discovered',
                    'metadata' => array( 'search_engine' => 'google', 'query' => $query ),
                );
            }
        } else {
            foreach ( array_slice( (array) ( $result['shopping_results'] ?? array() ), 0, 10 ) as $offer ) {
                $url = self::safe_url( $offer['link'] ?? $offer['product_link'] ?? '' );
                if ( '' === $url ) {
                    continue;
                }
                $price = isset( $offer['extracted_price'] ) && is_numeric( $offer['extracted_price'] )
                    ? (float) $offer['extracted_price'] : null;
                $items[] = array(
                    'provider' => 'serpapi',
                    'type' => 'offer',
                    'key' => $url,
                    'url' => $url,
                    'title' => self::clean( $offer['title'] ?? '' ),
                    'snippet' => self::clean( $offer['source'] ?? '' ),
                    'level' => 'search_offer',
                    'metadata' => array(
                        'price_label' => self::clean( $offer['price'] ?? '', 80 ),
                        'price_value' => $price,
                        'seller' => self::clean( $offer['source'] ?? '', 150 ),
                        'market_country' => self::region( $category ),
                        'observed_utc' => gmdate( 'c' ),
                        'not_comparable_without_specs' => true,
                    ),
                );
            }
        }
        return array( 'items' => $items );
    }

    /**
     * Merchant Best Sellers is a demand signal by official Google taxonomy ID,
     * NOT an inventory of competing offers or retail prices.
     */
    private static function merchant_best_sellers( $category ) {
        $account = TaxoCommerce_Google_Bing_Connections::merchant_account();
        $google_id = absint( $category['google_category_id'] ?? 0 );
        if ( '' === $account || ! $google_id ) {
            return new WP_Error( 'market_merchant_mapping_missing', 'Merchant exige cuenta y correspondencia con categoría Google.' );
        }
        $token = TaxoCommerce_Google_Bing_Connections::access_token( 'https://www.googleapis.com/auth/content' );
        if ( is_wp_error( $token ) ) {
            return new WP_Error( 'market_merchant_auth', 'Merchant: cuenta de servicio no autorizada para esta consulta.' );
        }
        $country = self::region( $category );
        $query = 'SELECT report_date,report_granularity,report_country_code,report_category_id,rank,relative_demand,title,brand,category_l1,category_l2 '
            . 'FROM best_sellers_product_cluster_view '
            . "WHERE report_granularity = 'WEEKLY' AND report_country_code = '" . $country . "' "
            . 'AND report_category_id = ' . $google_id . ' ORDER BY rank';
        $result = self::request_json(
            'https://merchantapi.googleapis.com/reports/v1/accounts/' . rawurlencode( $account ) . '/reports:search',
            'POST',
            array( 'query' => $query, 'pageSize' => 30 ),
            array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'Content-Type' => 'application/json' )
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $items = array();
        foreach ( (array) ( $result['results'] ?? array() ) as $entry ) {
            $row = (array) ( $entry['bestSellersProductClusterView'] ?? array() );
            $title = self::clean( $row['title'] ?? '' );
            if ( ! $title ) {
                continue;
            }
            $date = (array) ( $row['reportDate'] ?? array() );
            $date_key = sprintf( '%04d-%02d-%02d',
                absint( $date['year'] ?? 0 ),
                absint( $date['month'] ?? 0 ),
                absint( $date['day'] ?? 0 )
            );
            $items[] = array(
                'provider' => 'merchant',
                'type' => 'market_signal',
                'key' => $google_id . '|' . $date_key . '|' . self::clean( $row['brand'] ?? '' ) . '|' . $title,
                'url' => '',
                'title' => $title,
                'snippet' => 'Señal de popularidad Merchant; no representa una opinión ni un precio individual.',
                'level' => 'merchant_report',
                'metadata' => array(
                    'brand' => self::clean( $row['brand'] ?? '' ),
                    'rank' => absint( $row['rank'] ?? 0 ),
                    'relative_demand' => self::clean( $row['relativeDemand'] ?? '' ),
                    'report_date' => $date_key,
                    'google_category_id' => $google_id,
                    'country' => $country,
                    'source_report' => 'Merchant API best_sellers_product_cluster_view',
                ),
            );
        }
        return array( 'items' => $items );
    }
}
