<?php
/**
 * Editable legal notice and independently stored consent decisions.
 *
 * @package TaxoCommerceInsights
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_Consent {
    const OPTION = 'taxocommerce_insights_consent_text';
    const VERSION = 'taxocommerce_insights_consent_schema';
    const NONCE = 'tci_consent_admin';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'taxocommerce_consent_events';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
subject_token varchar(64) NOT NULL,
context_key varchar(100) NOT NULL,
decision varchar(10) NOT NULL,
policy_hash char(64) NOT NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY subject_context (subject_token,context_key),
KEY created_at (created_at)
) {$charset};" );
        add_option( self::OPTION, array(
            'title' => 'Privacidad y consentimiento',
            'body' => 'Consulta nuestra política de privacidad antes de aceptar o rechazar el tratamiento opcional de datos.',
            'accept' => 'Aceptar',
            'reject' => 'Rechazar',
        ), '', false );
        update_option( self::VERSION, '1', false );
    }

    public static function register() {
        add_action( 'admin_post_tci_consent_settings', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_tci_consent_decision', array( __CLASS__, 'decision' ) );
        add_action( 'admin_post_nopriv_tci_consent_decision', array( __CLASS__, 'decision' ) );
        add_shortcode( 'taxocommerce_consent_form', array( __CLASS__, 'shortcode' ) );
    }

    public static function settings() {
        $defaults = array(
            'title' => __( 'Privacidad y consentimiento', 'taxocommerce-google-bing' ),
            'body' => __( 'Consulta nuestra política de privacidad antes de aceptar o rechazar el tratamiento opcional de datos.', 'taxocommerce-google-bing' ),
            'accept' => __( 'Aceptar', 'taxocommerce-google-bing' ),
            'reject' => __( 'Rechazar', 'taxocommerce-google-bing' ),
        );
        return wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-google-bing' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( self::NONCE );
        $value = array();
        foreach ( array( 'title', 'body', 'accept', 'reject' ) as $key ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer above.
            $raw = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
            $value[ $key ] = 'body' === $key ? wp_kses_post( $raw ) : sanitize_text_field( $raw );
        }
        if ( '' === trim( wp_strip_all_tags( $value['body'] ) ) || '' === $value['accept'] || '' === $value['reject'] ) {
            wp_die( esc_html__( 'Los textos legales y los botones no pueden estar vacíos.', 'taxocommerce-google-bing' ), '', array( 'response' => 400 ) );
        }
        update_option( self::OPTION, $value, false );
        wp_safe_redirect( add_query_arg( array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'consent', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $s = self::settings();
        echo '<h2>' . esc_html__( 'Consentimientos y textos legales', 'taxocommerce-google-bing' ) . '</h2>';
        echo '<p>' . esc_html__( 'Estos textos aparecen en el formulario reutilizable. Cada decisión se guarda con su versión legal, contexto y fecha. No se recopila la dirección IP.', 'taxocommerce-google-bing' ) . '</p>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin success flag.
        if ( isset( $_GET['saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['saved'] ) ) ) {
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Textos guardados.', 'taxocommerce-google-bing' ) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tci_consent_settings">';
        wp_nonce_field( self::NONCE );
        foreach ( array( 'title' => __( 'Título', 'taxocommerce-google-bing' ), 'body' => __( 'Texto legal editable', 'taxocommerce-google-bing' ), 'accept' => __( 'Texto del botón Aceptar', 'taxocommerce-google-bing' ), 'reject' => __( 'Texto del botón Rechazar', 'taxocommerce-google-bing' ) ) as $key => $label ) {
            echo '<p><label for="tci-' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br>';
            if ( 'body' === $key ) {
                echo '<textarea id="tci-body" name="body" class="large-text" rows="10" maxlength="20000">' . esc_textarea( $s[ $key ] ) . '</textarea>';
            } else {
                echo '<input id="tci-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" class="regular-text" maxlength="250" value="' . esc_attr( $s[ $key ] ) . '">';
            }
            echo '</p>';
        }
        submit_button( __( 'Guardar textos legales', 'taxocommerce-google-bing' ) );
        echo '</form>';
        echo '<p>' . esc_html__( 'Para insertar el formulario utiliza [taxocommerce_consent_form context="contacto"]. Los plugins de terceros pueden utilizar el mismo shortcode o integrarse explícitamente con el evento taxocommerce_insights_consent_decision.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Atención:', 'taxocommerce-google-bing' ) . '</strong> ' . esc_html__( 'Al desinstalar Insights se borrarán sus textos y registros de consentimiento. Conserva previamente los justificantes legales que debas mantener.', 'taxocommerce-google-bing' ) . '</p>';
    }

    public static function shortcode( $atts ) {
        $atts = shortcode_atts( array( 'context' => 'general' ), $atts, 'taxocommerce_consent_form' );
        $context = sanitize_key( $atts['context'] );
        $s = self::settings();
        $hash = hash( 'sha256', wp_json_encode( $s ) );
        $html = '<div class="tci-consent"><h3>' . esc_html( $s['title'] ) . '</h3><div>' . wp_kses_post( $s['body'] ) . '</div>';
        $html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        $html .= '<input type="hidden" name="action" value="tci_consent_decision">';
        $html .= '<input type="hidden" name="context" value="' . esc_attr( $context ) . '">';
        $html .= '<input type="hidden" name="policy_hash" value="' . esc_attr( $hash ) . '">';
        $html .= wp_nonce_field( 'tci_consent_' . $context, '_wpnonce', true, false );
        $html .= '<button type="submit" name="decision" value="accepted">' . esc_html( $s['accept'] ) . '</button> ';
        $html .= '<button type="submit" name="decision" value="rejected">' . esc_html( $s['reject'] ) . '</button></form></div>';
        return $html;
    }

    public static function decision() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified per context below.
        $context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'general';
        check_admin_referer( 'tci_consent_' . $context );
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
        $decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
        $submitted_hash = isset( $_POST['policy_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['policy_hash'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( ! in_array( $decision, array( 'accepted', 'rejected' ), true ) ) {
            wp_die( esc_html__( 'Decisión no válida.', 'taxocommerce-google-bing' ), '', array( 'response' => 400 ) );
        }
        $current_hash = hash( 'sha256', wp_json_encode( self::settings() ) );
        if ( ! hash_equals( $current_hash, $submitted_hash ) ) {
            wp_die( esc_html__( 'El texto legal ha cambiado. Recarga el formulario.', 'taxocommerce-google-bing' ), '', array( 'response' => 409 ) );
        }
        // A random pseudonymous cookie permits later matching without collecting IP addresses.
        $token = isset( $_COOKIE['tci_consent_subject'] ) && is_string( $_COOKIE['tci_consent_subject'] )
            ? sanitize_text_field( wp_unslash( $_COOKIE['tci_consent_subject'] ) ) : '';
        if ( ! preg_match( '/\A[a-f0-9]{64}\z/', $token ) ) {
            $token = bin2hex( random_bytes( 32 ) );
            setcookie( 'tci_consent_subject', $token, array( 'expires' => time() + YEAR_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
        }
        global $wpdb;
        $ok = $wpdb->insert( self::table(), array(
            'subject_token' => hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ),
            'context_key' => $context,
            'decision' => $decision,
            'policy_hash' => $current_hash,
            'created_at' => current_time( 'mysql', true ),
        ), array( '%s', '%s', '%s', '%s', '%s' ) );
        if ( false === $ok ) {
            wp_die( esc_html__( 'No se pudo registrar la decisión.', 'taxocommerce-google-bing' ), '', array( 'response' => 500 ) );
        }
        do_action( 'taxocommerce_insights_consent_decision', $decision, $context, $current_hash );
        $destination = wp_get_referer();
        wp_safe_redirect( $destination ? remove_query_arg( 'tci_consent', $destination ) : home_url( '/' ) );
        exit;
    }
}
