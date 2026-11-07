<?php
/**
 * Cloudflare settings and explicit read-only connectivity tests.
 *
 * Keeps the existing seo_cloudflare_settings option and encryption envelope,
 * so credentials remain available when the SEO monolith is deactivated.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_Cloudflare_UI {
    const TEST_NONCE = 'taxocommerce_insights_cloudflare_test';

    public static function register() {
        add_action( 'admin_post_tci_cloudflare_test', array( __CLASS__, 'test' ) );
    }

    private static function url() {
        return add_query_arg(
            array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'connections' ),
            admin_url( 'admin.php' )
        );
    }

    public static function test() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para probar la conexión.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( self::TEST_NONCE );
        $result = seo_cloudflare_test_connection();
        $success = is_array( $result ) && ! empty( $result['ok'] );
        $messages = is_array( $result ) && isset( $result['messages'] ) ? (array) $result['messages'] : array();
        $message = $messages ? implode( ' ', array_map( 'sanitize_text_field', $messages ) )
            : __( 'Consulta de Cloudflare finalizada. Comprueba su estado.', 'taxocommerce-google-bing' );
        set_transient(
            'tci_cloudflare_test_' . get_current_user_id(),
            array( 'ok' => $success ? 1 : 0, 'message' => $message ),
            5 * MINUTE_IN_SECONDS
        );
        wp_safe_redirect( self::url() );
        exit;
    }

    public static function render() {
        $cfg = seo_cloudflare_settings();
        $state = seo_cloudflare_connection_state();
        $has_token = 'none' !== (string) ( $state['token_source'] ?? 'none' );
        $configured = ! empty( $state['configured'] );
        $status = get_transient( 'tci_cloudflare_test_' . get_current_user_id() );
        if ( $status ) {
            delete_transient( 'tci_cloudflare_test_' . get_current_user_id() );
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice.
        $saved = isset( $_GET['cloudflare_saved'] ) ? absint( $_GET['cloudflare_saved'] ) : 0;
        $error = isset( $_GET['cloudflare_error'] ) ? sanitize_key( wp_unslash( $_GET['cloudflare_error'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        echo '<div class="card" style="max-width:1300px;padding:20px;margin-top:18px">';
        echo '<h2>' . esc_html__( 'Cloudflare · Infraestructura (solo lectura)', 'taxocommerce-google-bing' ) . '</h2>';
        echo '<p>' . esc_html__( 'La conexión y sus diagnósticos de seguridad pertenecen ahora a Insights. Conserva el mismo token cifrado de la configuración anterior; no modifica DNS, reglas, WAF ni SSL.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Estado:', 'taxocommerce-google-bing' ) . '</strong> '
            . esc_html( $configured ? __( 'Configurada', 'taxocommerce-google-bing' ) : __( 'Sin configurar', 'taxocommerce-google-bing' ) )
            . ' · <strong>' . esc_html__( 'Habilitada:', 'taxocommerce-google-bing' ) . '</strong> '
            . esc_html( ! empty( $cfg['enabled'] ) ? __( 'Sí', 'taxocommerce-google-bing' ) : __( 'No', 'taxocommerce-google-bing' ) ) . '</p>';
        if ( $saved ) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Configuración Cloudflare guardada.', 'taxocommerce-google-bing' ) . '</p></div>';
        } elseif ( '' !== $error ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No se ha guardado Cloudflare. Comprueba la zona y el token.', 'taxocommerce-google-bing' ) . '</p></div>';
        }
        if ( is_array( $status ) ) {
            echo '<div class="notice notice-' . ( ! empty( $status['ok'] ) ? 'success' : 'error' ) . ' inline"><p>'
                . esc_html( $status['message'] ?? '' ) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="seo_cloudflare_save">';
        wp_nonce_field( 'seo_cloudflare_save', 'seo_cloudflare_nonce' );
        echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $cfg['enabled'] ), true, false ) . '> '
            . esc_html__( 'Habilitar la conexión de diagnóstico Cloudflare', 'taxocommerce-google-bing' ) . '</label></p>';
        echo '<p><label>' . esc_html__( 'Zona / dominio', 'taxocommerce-google-bing' )
            . '<br><input class="regular-text" type="text" name="zone_name" value="' . esc_attr( $cfg['zone_name'] ?? '' )
            . '" autocomplete="off" required></label></p>';
        if ( 'constant' === ( $state['token_source'] ?? '' ) ) {
            echo '<p>' . esc_html__( 'El token está definido en wp-config.php; no se mostrará ni se sobrescribirá.', 'taxocommerce-google-bing' ) . '</p>';
        } else {
            echo '<p><label>' . esc_html__( 'API Token (dejar vacío para conservar)', 'taxocommerce-google-bing' )
                . '<br><input class="regular-text" type="password" name="api_token" value="" autocomplete="new-password" placeholder="'
                . esc_attr( $has_token ? __( 'Token guardado', 'taxocommerce-google-bing' ) : __( 'API Token', 'taxocommerce-google-bing' ) ) . '"></label></p>';
            if ( $has_token ) {
                echo '<p><label><input type="checkbox" name="clear_token" value="1"> '
                    . esc_html__( 'Eliminar el token guardado explícitamente', 'taxocommerce-google-bing' ) . '</label></p>';
            }
        }
        submit_button( __( 'Guardar conexión Cloudflare', 'taxocommerce-google-bing' ), 'secondary', 'submit', false );
        echo '</form>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
        echo '<input type="hidden" name="action" value="tci_cloudflare_test">';
        wp_nonce_field( self::TEST_NONCE );
        echo '<button type="submit" class="button" ' . disabled( ! $configured, true, false ) . '>'
            . esc_html__( 'Probar token y zona (solo lectura)', 'taxocommerce-google-bing' ) . '</button></form>';
        if ( ! empty( $cfg['last_verified_at'] ) ) {
            echo '<p class="description">' . esc_html__( 'Última verificación:', 'taxocommerce-google-bing' )
                . ' ' . esc_html( wp_date( 'Y-m-d H:i', absint( $cfg['last_verified_at'] ) ) ) . '</p>';
        }
        echo '</div>';
    }
}
