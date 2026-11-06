<?php
/**
 * Administrator-managed verification and tracking snippets for the document head.
 *
 * Snippets are intentionally limited to users with the unfiltered_html capability.
 * Tracking is not printed without an affirmative statistics-consent signal.
 *
 * @package TaxoCommerceInsights
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_Head_Tags {
    const OPTION = 'taxocommerce_insights_head_tags';
    const NONCE  = 'tgb_head_tags';
    const MAX    = 20;

    public static function register() {
        add_action( 'admin_post_tgb_head_tag_save', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_tgb_head_tag_delete', array( __CLASS__, 'delete' ) );
        add_action( 'wp_head', array( __CLASS__, 'output' ), 99 );
    }

    private static function allowed() {
        return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
    }

    private static function entries() {
        $value = get_option( self::OPTION, array() );
        return is_array( $value ) ? $value : array();
    }

    private static function url( $notice = '' ) {
        $args = array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'head_tags' );
        if ( '' !== $notice ) {
            $args['tgb_head_notice'] = $notice;
        }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    private static function redirect( $notice ) {
        wp_safe_redirect( self::url( $notice ) );
        exit;
    }

    private static function validate_code( $code, $kind ) {
        if ( '' === trim( $code ) || strlen( $code ) > 12000 ) {
            return false;
        }
        // Only HTML suitable for <head> is accepted. No PHP, page elements or document wrappers.
        if ( false !== strpos( $code, '<?' ) || preg_match( '~</?(?:head|html|body|iframe|object|embed|base|form|style)\\b~i', $code ) ) {
            return false;
        }
        $without_comments = preg_replace( '/<!--.*?-->/s', '', $code );
        if ( 'verification' === $kind ) {
            // Verification tags do not need JavaScript or cookie consent.
            return (bool) preg_match( '~\\A\\s*(?:<meta\\s+[^>]*?/?>\\s*)+\\z~is', $without_comments )
                && ! preg_match( '/\\b(?:http-equiv|on[a-z]+)\\s*=/i', $without_comments );
        }
        if ( 'tracking' !== $kind || ! preg_match( '/<script\\b/i', $without_comments ) ) {
            return false;
        }
        // Scripts and optional meta tags are supported. A GTM <noscript> iframe belongs
        // in wp_body_open, not in the document head.
        $remaining = preg_replace( '~</?script\\b[^>]*>~i', '', $without_comments );
        $remaining = preg_replace( '~<meta\\s+[^>]*?/?>~i', '', $remaining );
        // Text between script tags is valid JavaScript and must not be parsed as HTML.
        return ! preg_match( '~<\\s*/?(?:head|html|body|iframe|object|embed|form)\\b~i', $remaining );
    }

    public static function save() {
        if ( ! self::allowed() ) {
            wp_die( esc_html__( 'No tienes permiso para insertar codigo HTML sin filtrar.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( self::NONCE );
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
        $id      = isset( $_POST['entry_id'] ) ? sanitize_key( wp_unslash( $_POST['entry_id'] ) ) : '';
        $name    = isset( $_POST['entry_name'] ) ? sanitize_text_field( wp_unslash( $_POST['entry_name'] ) ) : '';
        $kind    = isset( $_POST['entry_kind'] ) ? sanitize_key( wp_unslash( $_POST['entry_kind'] ) ) : '';
        $enabled = ! empty( $_POST['entry_enabled'] );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Explicit unfiltered_html capability; checked before storing.
        $code    = isset( $_POST['entry_code'] ) && is_string( $_POST['entry_code'] ) ? trim( wp_unslash( $_POST['entry_code'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( ! in_array( $kind, array( 'verification', 'tracking' ), true ) || ! self::validate_code( $code, $kind ) || '' === $name ) {
            self::redirect( 'invalid' );
        }
        $entries = self::entries();
        if ( '' !== $id && ! isset( $entries[ $id ] ) ) {
            self::redirect( 'invalid' );
        }
        if ( '' === $id ) {
            if ( count( $entries ) >= self::MAX ) {
                self::redirect( 'limit' );
            }
            $id = sanitize_key( wp_generate_uuid4() );
        }
        $entries[ $id ] = array(
            'name'    => $name,
            'kind'    => $kind,
            'code'    => $code,
            'enabled' => $enabled,
        );
        update_option( self::OPTION, $entries, false );
        self::redirect( 'saved' );
    }

    public static function delete() {
        if ( ! self::allowed() ) {
            wp_die( esc_html__( 'No tienes permiso para eliminar etiquetas.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( self::NONCE );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
        $id = isset( $_POST['entry_id'] ) ? sanitize_key( wp_unslash( $_POST['entry_id'] ) ) : '';
        $entries = self::entries();
        if ( isset( $entries[ $id ] ) ) {
            unset( $entries[ $id ] );
            update_option( self::OPTION, $entries, false );
        }
        self::redirect( 'deleted' );
    }

    public static function output() {
        if ( is_admin() || is_feed() ) {
            return;
        }
        foreach ( self::entries() as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['enabled'] ) ) {
                continue;
            }
            $kind = isset( $entry['kind'] ) ? $entry['kind'] : '';
            $code = isset( $entry['code'] ) && is_string( $entry['code'] ) ? $entry['code'] : '';
            if ( ! self::validate_code( $code, $kind ) ) {
                continue;
            }
            if ( 'tracking' === $kind ) {
                // Default deny. Consent plugins can implement WP Consent API's
                // wp_has_consent('statistics') or integrate with this filter.
                $consented = function_exists( 'wp_has_consent' ) && wp_has_consent( 'statistics' );
                $consented = (bool) apply_filters( 'taxocommerce_insights_head_tracking_allowed', $consented, $entry );
                if ( ! $consented ) {
                    continue;
                }
            }
            echo "\n<!-- TaxoCommerce Insights: user-configured head tag -->\n";
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin with unfiltered_html authored this validated HTML. Escaping destroys provider scripts.
            echo $code;
            echo "\n";
        }
    }

    public static function render() {
        $entries = self::entries();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only edit selector.
        $edit = isset( $_GET['edit_tag'] ) ? sanitize_key( wp_unslash( $_GET['edit_tag'] ) ) : '';
        $current = isset( $entries[ $edit ] ) && is_array( $entries[ $edit ] ) ? $entries[ $edit ] : array();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status.
        $notice = isset( $_GET['tgb_head_notice'] ) ? sanitize_key( wp_unslash( $_GET['tgb_head_notice'] ) ) : '';
        $messages = array(
            'saved'   => __( 'Etiqueta guardada.', 'taxocommerce-google-bing' ),
            'deleted' => __( 'Etiqueta eliminada.', 'taxocommerce-google-bing' ),
            'invalid' => __( 'Codigo no valido. Usa etiquetas meta para verificacion, o codigo script para seguimiento. No se admiten etiquetas de pagina ni PHP.', 'taxocommerce-google-bing' ),
            'limit'   => __( 'Has alcanzado el limite de etiquetas.', 'taxocommerce-google-bing' ),
        );

        echo '<h2>' . esc_html__( 'Etiquetas de cabecera', 'taxocommerce-google-bing' ) . '</h2>';
        echo '<p>' . esc_html__( 'Pega el codigo HTML completo del proveedor. Se insertara automaticamente en el head de WordPress sin modificar la plantilla.', 'taxocommerce-google-bing' ) . '</p>';
        if ( isset( $messages[ $notice ] ) ) {
            echo '<div class="notice notice-' . esc_attr( 'invalid' === $notice || 'limit' === $notice ? 'error' : 'success' ) . ' inline"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
        }
        if ( ! self::allowed() ) {
            echo '<p>' . esc_html__( 'Solo los administradores con permiso unfiltered_html pueden gestionar etiquetas.', 'taxocommerce-google-bing' ) . '</p>';
            return;
        }

        if ( $entries ) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Nombre', 'taxocommerce-google-bing' ) . '</th><th>' . esc_html__( 'Tipo', 'taxocommerce-google-bing' ) . '</th><th>' . esc_html__( 'Estado', 'taxocommerce-google-bing' ) . '</th><th>' . esc_html__( 'Acciones', 'taxocommerce-google-bing' ) . '</th></tr></thead><tbody>';
            foreach ( $entries as $id => $entry ) {
                $edit_url = add_query_arg( 'edit_tag', sanitize_key( $id ), self::url() );
                echo '<tr><td>' . esc_html( $entry['name'] ?? '' ) . '</td><td>' . esc_html( 'verification' === ( $entry['kind'] ?? '' ) ? __( 'Verificacion', 'taxocommerce-google-bing' ) : __( 'Seguimiento', 'taxocommerce-google-bing' ) ) . '</td><td>' . esc_html( ! empty( $entry['enabled'] ) ? __( 'Activa', 'taxocommerce-google-bing' ) : __( 'Inactiva', 'taxocommerce-google-bing' ) ) . '</td><td>';
                echo '<a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Editar', 'taxocommerce-google-bing' ) . '</a> ';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="tgb_head_tag_delete"><input type="hidden" name="entry_id" value="' . esc_attr( $id ) . '">';
                wp_nonce_field( self::NONCE );
                echo '<button class="button button-small" type="submit">' . esc_html__( 'Eliminar', 'taxocommerce-google-bing' ) . '</button></form></td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h3>' . esc_html( $current ? __( 'Editar etiqueta', 'taxocommerce-google-bing' ) : __( 'Nueva etiqueta', 'taxocommerce-google-bing' ) ) . '</h3>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_head_tag_save"><input type="hidden" name="entry_id" value="' . esc_attr( $current ? $edit : '' ) . '">';
        wp_nonce_field( self::NONCE );
        echo '<p><label><strong>' . esc_html__( 'Nombre del proveedor', 'taxocommerce-google-bing' ) . '</strong><br><input class="regular-text" name="entry_name" required maxlength="200" value="' . esc_attr( $current['name'] ?? '' ) . '" placeholder="Microsoft Clarity"></label></p>';
        echo '<p><label><strong>' . esc_html__( 'Tipo de codigo', 'taxocommerce-google-bing' ) . '</strong><br><select name="entry_kind">';
        foreach ( array( 'verification' => __( 'Verificacion (meta)', 'taxocommerce-google-bing' ), 'tracking' => __( 'Seguimiento (scripts, requiere consentimiento)', 'taxocommerce-google-bing' ) ) as $kind => $label ) {
            echo '<option value="' . esc_attr( $kind ) . '" ' . selected( $current['kind'] ?? 'verification', $kind, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label><strong>' . esc_html__( 'Codigo proporcionado por el proveedor', 'taxocommerce-google-bing' ) . '</strong><br><textarea name="entry_code" class="large-text code" rows="10" required maxlength="12000" spellcheck="false">' . esc_textarea( $current['code'] ?? '' ) . '</textarea></label></p>';
        echo '<p><label><input type="checkbox" name="entry_enabled" value="1" ' . checked( ! empty( $current['enabled'] ) || ! $current, true, false ) . '> ' . esc_html__( 'Activar esta etiqueta', 'taxocommerce-google-bing' ) . '</label></p>';
        echo '<p class="description">' . esc_html__( 'Las etiquetas de verificacion se publican sin cookies. Los scripts de seguimiento solo se publican si existe consentimiento estadistico confirmado mediante WP Consent API o el filtro de integracion. No pegues codigo de fuentes desconocidas.', 'taxocommerce-google-bing' ) . '</p>';
        submit_button( $current ? __( 'Guardar cambios', 'taxocommerce-google-bing' ) : __( 'Añadir etiqueta', 'taxocommerce-google-bing' ) );
        echo '</form>';
    }
}
