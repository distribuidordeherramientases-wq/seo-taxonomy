<?php
/**
 * Optional, site-scoped IndexNow publisher for TaxoCommerce Insights.
 *
 * Disabled by default to avoid duplicate notifications when a CDN or another
 * SEO plugin already submits changes. No other TaxoCommerce plugin is required.
 *
 * @package TaxoCommerce_Insights
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_IndexNow {
    const KEY_OPTION   = 'taxocommerce_insights_indexnow_key';
    const QUEUE_OPTION = 'taxocommerce_insights_indexnow_queue';
    const LOG_OPTION   = 'taxocommerce_insights_indexnow_last_result';
    const CRON_HOOK    = 'taxocommerce_insights_indexnow_send';

    public static function register() {
        add_action( 'parse_request', array( __CLASS__, 'serve_key' ) );
        add_action( 'transition_post_status', array( __CLASS__, 'post_change' ), 10, 3 );
        add_action( 'before_delete_post', array( __CLASS__, 'post_delete' ), 10, 2 );
        add_action( 'created_term', array( __CLASS__, 'term_change' ), 10, 3 );
        add_action( 'edited_term', array( __CLASS__, 'term_change' ), 10, 3 );
        add_action( 'delete_term', array( __CLASS__, 'term_delete' ), 10, 4 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'send_pending' ) );
        add_action( 'admin_post_taxocommerce_insights_indexnow_url', array( __CLASS__, 'manual_url' ) );
    }

    public static function enabled() {
        $settings = TaxoCommerce_Connections_Google_Bing::bing_settings();
        return ! empty( $settings['indexnow_enabled'] );
    }

    /**
     * Generate a unique per-installation public ownership key when enabled.
     *
     * @return string Key or empty when disabled.
     */
    public static function key() {
        if ( ! self::enabled() ) {
            return '';
        }
        $key = (string) get_option( self::KEY_OPTION, '' );
        if ( ! preg_match( '/^[a-zA-Z0-9-]{8,128}$/', $key ) ) {
            $key = wp_generate_password( 32, false, false );
            update_option( self::KEY_OPTION, $key, false );
        }
        return $key;
    }

    public static function key_location() {
        $key = self::key();
        return $key ? home_url( '/indexnow-' . $key . '.txt' ) : '';
    }

    /**
     * Serve the ownership key from WordPress without filesystem or SSH access.
     *
     * @param WP $wp Request object.
     * @return void
     */
    public static function serve_key( $wp ) {
        $key = self::key();
        if ( ! $key || ! is_object( $wp ) ||
            (string) ( $wp->request ?? '' ) !== 'indexnow-' . $key . '.txt' ) {
            return;
        }
        nocache_headers();
        header( 'Content-Type: text/plain; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex' );
        echo esc_html( $key );
        exit;
    }

    /**
     * Check that an URL belongs to this installation and uses HTTPS/HTTP.
     *
     * @param string $url Public URL.
     * @return string Valid URL or empty.
     */
    public static function normalize_url( $url ) {
        $url = esc_url_raw( (string) $url );
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ||
            ! $home_host || ! $host || $host !== $home_host ||
            wp_parse_url( $url, PHP_URL_USER ) || wp_parse_url( $url, PHP_URL_PASS ) ) {
            return '';
        }
        return strtok( $url, '#' );
    }

    /**
     * Queue changed, locally owned public URLs with bounded, deduplicated load.
     *
     * @param string $url URL to notify.
     * @return bool Whether accepted.
     */
    public static function enqueue( $url ) {
        if ( ! self::enabled() ) {
            return false;
        }
        $url = self::normalize_url( $url );
        if ( '' === $url ) {
            return false;
        }
        $queue = get_option( self::QUEUE_OPTION, array() );
        $queue = is_array( $queue ) ? $queue : array();
        $hash = md5( $url );
        if ( ! isset( $queue[ $hash ] ) && count( $queue ) >= 1000 ) {
            return false;
        }
        $queue[ $hash ] = $url;
        update_option( self::QUEUE_OPTION, $queue, false );
        self::schedule( 60 );
        return true;
    }

    public static function schedule( $delay = 60 ) {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + max( 1, absint( $delay ) ), self::CRON_HOOK );
        }
    }

    /**
     * Queue published content changes. Excludes drafts and private content.
     *
     * @param string  $new_status New status.
     * @param string  $old_status Previous status.
     * @param WP_Post $post Post.
     * @return void
     */
    public static function post_change( $new_status, $old_status, $post ) {
        if ( ! self::enabled() || ! is_a( $post, 'WP_Post' ) ||
            ( 'publish' !== $new_status && 'publish' !== $old_status ) ||
            ! empty( $post->post_password ) ) {
            return;
        }
        $type = get_post_type_object( $post->post_type );
        if ( ! $type || ! is_post_type_viewable( $type ) ) {
            return;
        }
        self::enqueue( get_permalink( $post ) );
    }

    public static function post_delete( $post_id, $post ) {
        if ( self::enabled() && is_a( $post, 'WP_Post' ) &&
            'publish' === $post->post_status && empty( $post->post_password ) ) {
            $type = get_post_type_object( $post->post_type );
            if ( $type && is_post_type_viewable( $type ) ) {
                self::enqueue( get_permalink( $post ) );
            }
        }
    }

    public static function term_change( $term_id, $tt_id, $taxonomy ) {
        if ( ! self::enabled() ) {
            return;
        }
        $object = get_taxonomy( $taxonomy );
        if ( ! $object || ! is_taxonomy_viewable( $object ) ) {
            return;
        }
        $url = get_term_link( absint( $term_id ), $taxonomy );
        if ( ! is_wp_error( $url ) ) {
            self::enqueue( $url );
        }
    }

    public static function term_delete( $term_id, $tt_id, $taxonomy, $deleted_term ) {
        if ( ! self::enabled() || ! is_object( $deleted_term ) ) {
            return;
        }
        $taxonomy_object = get_taxonomy( $taxonomy );
        if ( ! $taxonomy_object || ! is_taxonomy_viewable( $taxonomy_object ) ) {
            return;
        }
        // Use a public permalink as provided by the WordPress taxonomy API;
        // this hook runs before the term is finally removed.
        $url = get_term_link( $deleted_term, $taxonomy );
        if ( ! is_wp_error( $url ) ) {
            self::enqueue( $url );
        }
    }

    public static function status() {
        $queue = get_option( self::QUEUE_OPTION, array() );
        $last = get_option( self::LOG_OPTION, array() );
        return array(
            'enabled'   => self::enabled(),
            'key_url'   => self::key_location(),
            'pending'   => is_array( $queue ) ? count( $queue ) : 0,
            'last'      => is_array( $last ) ? $last : array(),
        );
    }

    /**
     * Send at most 100 URLs to IndexNow per cron tick, with retry/backoff.
     *
     * @return void
     */
    public static function send_pending() {
        if ( ! self::enabled() ) {
            return;
        }
        $queue = get_option( self::QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) || ! $queue ) {
            return;
        }
        $key = self::key();
        if ( ! $key ) {
            return;
        }
        $subset = array_slice( $queue, 0, 100, true );
        $payload = array(
            'host'        => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
            'key'         => $key,
            'keyLocation' => self::key_location(),
            'urlList'     => array_values( $subset ),
        );
        $response = wp_safe_remote_post(
            'https://api.indexnow.org/indexnow',
            array(
                'timeout' => 12,
                'redirection' => 0,
                'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
                'body' => wp_json_encode( $payload ),
            )
        );
        $is_error = is_wp_error( $response );
        $code = $is_error ? 0 : absint( wp_remote_retrieve_response_code( $response ) );
        $accepted = ! $is_error && in_array( $code, array( 200, 202 ), true );
        update_option(
            self::LOG_OPTION,
            array(
                'time' => time(),
                'code' => $code,
                'message' => $is_error ? sanitize_text_field( $response->get_error_message() )
                    : ( $accepted ? 'Accepted' : 'IndexNow returned HTTP ' . $code ),
                'accepted' => $accepted ? count( $subset ) : 0,
            ),
            false
        );
        if ( $accepted ) {
            // Re-read the queue to preserve any URLs received during HTTP I/O.
            $fresh = get_option( self::QUEUE_OPTION, array() );
            $fresh = is_array( $fresh ) ? $fresh : array();
            foreach ( $subset as $hash => $url ) {
                if ( isset( $fresh[ $hash ] ) && $fresh[ $hash ] === $url ) {
                    unset( $fresh[ $hash ] );
                }
            }
            update_option( self::QUEUE_OPTION, $fresh, false );
            if ( $fresh ) {
                self::schedule( 60 );
            }
        } elseif ( 0 === $code || 429 === $code || $code >= 500 ) {
            self::schedule( 600 );
        }
        // Do not retry permanent 4xx errors endlessly. Preserve the queue and
        // expose the HTTP status so the site administrator can repair the key.
    }

    public static function manual_url() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para enviar URL a IndexNow.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( 'taxocommerce_insights_indexnow_url' );
        $url = isset( $_POST['indexnow_url'] ) && is_string( $_POST['indexnow_url'] )
            ? esc_url_raw( wp_unslash( $_POST['indexnow_url'] ) ) : '';
        $ok = self::enqueue( $url );
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => 'taxocommerce-google-bing',
                    'tgb_tab' => 'connections',
                    'indexnow_message' => $ok ? 'queued' : 'rejected',
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function disabled() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        delete_option( self::QUEUE_OPTION );
    }
}
