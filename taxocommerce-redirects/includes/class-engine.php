<?php
/**
 * One HTTP redirect engine shared with the legacy seo_redirects table.
 *
 * When the monolith is also active, unregister its old send_headers callback
 * during this request. Deactivation of this plugin restores old behavior.
 */

namespace TaxoCommerce\Redirects;

defined( 'ABSPATH' ) || exit;

final class Engine {
    const MAX_CHAIN_HOPS = 20;

    public static function register() {
        add_action( 'init', array( __CLASS__, 'disable_legacy_runtime' ), 100 );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
    }

    public static function disable_legacy_runtime() {
        // Runtime-only coordination. NEVER edit the monolith's source files.
        if ( false !== has_action( 'send_headers', 'interceptar_redireccion_antes_de_wordpress' ) ) {
            remove_action( 'send_headers', 'interceptar_redireccion_antes_de_wordpress', 1 );
        }
    }

    public static function maybe_redirect() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron()
            || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
            || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
        if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
            return;
        }
        if ( '1' !== (string) get_option( 'tc_redirects_schema_ready', '' ) ) {
            return;
        }

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
        $path = wp_parse_url( $uri, PHP_URL_PATH );
        if ( ! is_string( $path ) || '' === $path ) {
            return;
        }
        if ( preg_match( '/\.(jpg|jpeg|png|gif|ico|css|js|webp|svg|woff|woff2|map|pdf|xml|txt)$/i', $path ) ) {
            return;
        }
        // Preserve the explicit exception in the original runtime.
        if ( false !== strpos( $path, 'logistica-embalaje-preparacion-de-pedidos' ) ) {
            return;
        }

        $origin = Store::effective_path( $path );
        if ( null === $origin ) {
            return;
        }
        $rule = Store::find_origin( $origin );
        if ( ! $rule ) {
            return;
        }

        $target = Store::absolute_target( $rule->target_url );
        if ( is_wp_error( $target ) ) {
            return;
        }

        // Historical entries can contain chains/cycles even when new writes
        // validate them. Never issue a redirect into a detected cycle.
        $visited = array( $origin => true );
        $cursor = (string) $rule->target_url;
        $terminated = false;
        for ( $hop = 0; $hop < self::MAX_CHAIN_HOPS; $hop++ ) {
            $effective = Store::effective_path( $cursor );
            if ( null === $effective || isset( $visited[ $effective ] ) ) {
                return;
            }
            $visited[ $effective ] = true;
            $next = Store::find_origin( $effective );
            if ( ! $next ) {
                $terminated = true;
                break;
            }
            $cursor = (string) $next->target_url;
        }
        if ( ! $terminated ) {
            return;
        }

        $status = absint( $rule->status_code );
        if ( ! in_array( $status, array( 301, 302 ), true ) ) {
            return;
        }
        // WordPress enforces allowed hosts; the store only accepts this site's
        // host. The rule's status code is honored (unlike the old runtime).
        if ( wp_safe_redirect( $target, $status, 'TaxoCommerce Redirects' ) ) {
            Store::record_hit( $rule->id );
            exit;
        }
    }
}
