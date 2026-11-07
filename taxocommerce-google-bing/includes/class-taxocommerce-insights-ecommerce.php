<?php
/**
 * WooCommerce -> GA4 ecommerce controls. Independent from legacy SEO settings.
 *
 * The base gtag.js script belongs exclusively to Insights > Head tags.
 * This module emits ecommerce events only, after statistics consent.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_Ecommerce {
    const OPTION = 'taxocommerce_insights_ecommerce_settings';
    const NONCE = 'tci_ecommerce_settings';

    public static function register() {
        add_action( 'admin_post_tci_ecommerce_save', array( __CLASS__, 'save' ) );
    }

    public static function settings() {
        $raw = get_option( self::OPTION, array() );
        return wp_parse_args( is_array( $raw ) ? $raw : array(), array(
            'enabled' => 0,
            'measurement_id' => '',
        ) );
    }

    /** Active GA4 head tags only. Do not copy or reinsert the gtag.js script. */
    public static function available_tags() {
        $entries = get_option( 'taxocommerce_insights_head_tags', array() );
        $tags = array();
        foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['enabled'] )
                || 'google_analytics' !== ( $entry['provider'] ?? '' )
                || 'tracking' !== ( $entry['kind'] ?? '' ) ) {
                continue;
            }
            $id = strtoupper( (string) ( $entry['provider_id'] ?? '' ) );
            if ( preg_match( '/\AG-[A-Z0-9]{5,32}\z/', $id ) ) {
                $tags[ $id ] = $entry;
            }
        }
        return $tags;
    }

    public static function legacy_tracking_active() {
        $legacy = get_option( 'seo_google_search_settings', array() );
        return is_array( $legacy ) && ! empty( $legacy['tracking_enabled'] )
            && ! empty( $legacy['measurement_id'] );
    }

    /** Lightweight readiness check, no network requests or writes. */
    public static function readiness() {
        $settings = self::settings();
        $id = strtoupper( (string) $settings['measurement_id'] );
        $tags = self::available_tags();
        if ( empty( $settings['enabled'] ) ) {
            return 'disabled';
        }
        if ( '' === $id || ! isset( $tags[ $id ] ) ) {
            return 'missing_tag';
        }
        if ( self::legacy_tracking_active() ) {
            return 'legacy_conflict';
        }
        if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_product' ) ) {
            return 'missing_woocommerce';
        }
        return 'ready';
    }

    /** Runtime authorization for the events collector, AJAX and page view. */
    public static function active() {
        if ( ( is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) )
            || current_user_can( 'manage_options' ) || 'ready' !== self::readiness() ) {
            return false;
        }
        $settings = self::settings();
        $tags = self::available_tags();
        $id = strtoupper( (string) $settings['measurement_id'] );
        $consented = function_exists( 'wp_has_consent' ) && wp_has_consent( 'statistics' );
        $consented = (bool) apply_filters( 'taxocommerce_insights_head_tracking_allowed', $consented, $tags[ $id ] );
        return $consented && (bool) apply_filters( 'taxocommerce_insights_ecommerce_allowed', true, $id );
    }

    private static function url( $status = '' ) {
        $args = array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'ecommerce' );
        if ( '' !== $status ) {
            $args['tci_ecommerce_notice'] = $status;
        }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    private static function redirect( $notice ) {
        wp_safe_redirect( self::url( $notice ) );
        exit;
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para configurar el comercio electrónico.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( self::NONCE );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
        $id = isset( $_POST['measurement_id'] ) && is_string( $_POST['measurement_id'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_POST['measurement_id'] ) ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
        $enabled = ! empty( $_POST['enabled'] ) ? 1 : 0;
        $tags = self::available_tags();
        if ( '' !== $id && ! isset( $tags[ $id ] ) ) {
            self::redirect( 'invalid_id' );
        }
        if ( $enabled && '' === $id ) {
            self::redirect( 'missing_tag' );
        }
        if ( $enabled && self::legacy_tracking_active() ) {
            self::redirect( 'legacy_conflict' );
        }
        update_option( self::OPTION, array( 'enabled' => $enabled, 'measurement_id' => $id ), false );
        self::redirect( 'saved' );
    }

    public static function render() {
        $settings = self::settings();
        $tags = self::available_tags();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice.
        $notice = isset( $_GET['tci_ecommerce_notice'] ) ? sanitize_key( wp_unslash( $_GET['tci_ecommerce_notice'] ) ) : '';
        $notices = array(
            'saved' => __( 'Configuración de comercio electrónico guardada.', 'taxocommerce-google-bing' ),
            'invalid_id' => __( 'La etiqueta de Google Analytics ya no está activa en Cabeceras.', 'taxocommerce-google-bing' ),
            'missing_tag' => __( 'Activa primero una etiqueta de Google Analytics en Cabeceras.', 'taxocommerce-google-bing' ),
            'legacy_conflict' => __( 'El seguimiento GA4 antiguo sigue activo. Desactívalo antes de habilitar este motor para evitar eventos duplicados.', 'taxocommerce-google-bing' ),
        );
        echo '<div class="tgb-card" style="max-width:1100px;padding:20px;margin-top:18px">';
        echo '<h2>' . esc_html__( 'Comercio electrónico · WooCommerce → Google Analytics 4', 'taxocommerce-google-bing' ) . '</h2>';
        echo '<p>' . esc_html__( 'Este módulo envía los eventos de ventas de WooCommerce a GA4. No añade scripts a la cabecera: utiliza la etiqueta activa de Insights → Etiquetas de cabecera. No mide compras realizadas en webs de afiliados.', 'taxocommerce-google-bing' ) . '</p>';
        if ( isset( $notices[ $notice ] ) ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>';
        }
        $states = array(
            'disabled' => __( 'Desactivado: no se envían eventos de comercio electrónico desde este módulo.', 'taxocommerce-google-bing' ),
            'missing_tag' => __( 'Bloqueado: falta la etiqueta GA4 activa seleccionada.', 'taxocommerce-google-bing' ),
            'legacy_conflict' => __( 'Bloqueado: el motor antiguo de Analytics está activo.', 'taxocommerce-google-bing' ),
            'missing_woocommerce' => __( 'Bloqueado: WooCommerce no está disponible.', 'taxocommerce-google-bing' ),
            'ready' => __( 'Preparado: se enviarán eventos únicamente cuando exista consentimiento estadístico.', 'taxocommerce-google-bing' ),
        );
        $state = self::readiness();
        echo '<p><strong>' . esc_html__( 'Estado del módulo:', 'taxocommerce-google-bing' ) . '</strong> ' . esc_html( $states[ $state ] ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Etiqueta GA4 en Cabeceras:', 'taxocommerce-google-bing' ) . '</strong> ' . esc_html( count( $tags ) . ' ' . __( 'disponible(s)', 'taxocommerce-google-bing' ) ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Inserción GA4 del plugin antiguo:', 'taxocommerce-google-bing' ) . '</strong> '
            . esc_html( self::legacy_tracking_active() ? __( 'Activada: desactívala para evitar duplicados.', 'taxocommerce-google-bing' ) : __( 'Desactivada', 'taxocommerce-google-bing' ) ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tci_ecommerce_save">';
        wp_nonce_field( self::NONCE );
        echo '<p><label><strong>' . esc_html__( 'ID de medición (tomado de Cabeceras)', 'taxocommerce-google-bing' ) . '</strong><br><select name="measurement_id">';
        echo '<option value="">' . esc_html__( 'Seleccionar etiqueta GA4', 'taxocommerce-google-bing' ) . '</option>';
        foreach ( $tags as $id => $entry ) {
            echo '<option value="' . esc_attr( $id ) . '" ' . selected( $settings['measurement_id'], $id, false ) . '>'
                . esc_html( $id . ' — ' . ( $entry['name'] ?? 'Google Analytics 4' ) ) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $settings['enabled'] ), true, false ) . '> '
            . esc_html__( 'Activar eventos de comercio electrónico de WooCommerce', 'taxocommerce-google-bing' ) . '</label></p>';
        echo '<p class="description">' . esc_html__( 'El control es independiente de la casilla del SEO antiguo. Nunca activa ni modifica el seguimiento anterior. Respeta el consentimiento estadístico y excluye administradores conectados.', 'taxocommerce-google-bing' ) . '</p>';
        submit_button( __( 'Guardar comercio electrónico', 'taxocommerce-google-bing' ) );
        echo '</form>';
        echo '<h3>' . esc_html__( 'Eventos incluidos', 'taxocommerce-google-bing' ) . '</h3>';
        echo '<p><code>view_item</code>, <code>add_to_cart</code>, <code>remove_from_cart</code>, <code>view_cart</code>, <code>begin_checkout</code>, <code>add_shipping_info</code>, <code>add_payment_info</code>, <code>purchase</code>.</p>';
        echo '<p class="description">' . esc_html__( 'Preparado no significa que los eventos estén recibidos en Google. Valida con GA4 DebugView o Tag Assistant tras aceptar cookies estadísticas.', 'taxocommerce-google-bing' ) . '</p>';
        echo '</div>';
    }
}
