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
        add_action( 'admin_post_tgb_head_tag_toggle', array( __CLASS__, 'toggle' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_analytics' ), 99 );
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

    /**
     * Generate the canonical Clarity loader from a validated project identifier.
     * The browser sends only the ID, never executable HTML, to admin-post.php.
     *
     * @param string $project_id Project identifier.
     * @return string
     */
    private static function clarity_code( $project_id ) {
        return '<script type="text/javascript">' . "\n"
            . '(function(c,l,a,r,i,t,y){' . "\n"
            . 'c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};' . "\n"
            . 't=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;' . "\n"
            . 'y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);' . "\n"
            . '})(window,document,"clarity","script","' . $project_id . '");' . "\n"
            . '</script>';
    }


    /** Construct a known Google snippet from a vetted provider identifier. */
    private static function google_code( $provider, $value ) {
        if ( 'google_verification' === $provider ) {
            return '<meta name="google-site-verification" content="' . esc_attr( $value ) . '">';
        }
        if ( 'google_analytics' === $provider ) {
            // Provider preview and stored code use WordPress's script-tag API.
            // Live output is handled separately by wp_enqueue_script().
            $source = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $value );
            $initializer = 'window.dataLayer=window.dataLayer||[];'
                . 'function gtag(){dataLayer.push(arguments);}'
                . 'gtag("js",new Date());gtag("config",' . wp_json_encode( $value ) . ');';
            return wp_get_script_tag( array( 'async' => true, 'src' => $source ) ) . "\n"
                . wp_get_inline_script_tag( $initializer );
        }
        if ( 'google_tag_manager' === $provider ) {
            return '<script>(function(w,d,s,l,i){w[l]=w[l]||[];'
                . 'w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});'
                . 'var f=d.getElementsByTagName(s)[0],j=d.createElement(s),'
                . 'dl=l!="dataLayer"?"&l="+l:"";j.async=true;'
                . 'j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;'
                . 'f.parentNode.insertBefore(j,f);'
                . '})(window,document,"script","dataLayer","' . $value . '");</script>';
        }
        return '';
    }

    /** Validate and normalize provider identifiers before constructing executable code. */
    private static function normalized_provider_code( $provider, $value, $kind ) {
        if ( 'clarity' === $provider && 'tracking' === $kind && preg_match( '/\A[a-z0-9]{6,32}\z/', $value ) ) {
            return self::clarity_code( $value );
        }
        if ( 'google_verification' === $provider && 'verification' === $kind && preg_match( '/\A[A-Za-z0-9_-]{8,256}\z/', $value ) ) {
            return self::google_code( $provider, $value );
        }
        if ( 'google_analytics' === $provider && 'tracking' === $kind && preg_match( '/\AG-[A-Z0-9]{5,32}\z/', $value ) ) {
            return self::google_code( $provider, $value );
        }
        if ( 'google_tag_manager' === $provider && 'tracking' === $kind && preg_match( '/\AGTM-[A-Z0-9]{5,32}\z/', $value ) ) {
            return self::google_code( $provider, $value );
        }
        return '';
    }

    /** Recognize existing entries saved before provider IDs were introduced. */
    private static function provider_name( $entry ) {
        $provider = isset( $entry['provider'] ) ? $entry['provider'] : '';
        $code = isset( $entry['code'] ) ? (string) $entry['code'] : '';
        if ( '' === $provider ) {
            if ( false !== strpos( $code, 'clarity.ms/tag' ) ) {
                $provider = 'clarity';
            } elseif ( false !== strpos( $code, 'google-site-verification' ) ) {
                $provider = 'google_verification';
            } elseif ( false !== strpos( $code, 'googletagmanager.com/gtag/js' ) ) {
                $provider = 'google_analytics';
            } elseif ( false !== strpos( $code, 'googletagmanager.com/gtm.js' ) ) {
                $provider = 'google_tag_manager';
            }
        }
        $names = array(
            'clarity'             => 'Microsoft Clarity',
            'google_verification' => 'Google Search Console',
            'google_analytics'    => 'Google Analytics 4',
            'google_tag_manager'  => 'Google Tag Manager',
        );
        return isset( $names[ $provider ] ) ? $names[ $provider ] : __( 'Personalizado', 'taxocommerce-google-bing' );
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
        $clarity_id = isset( $_POST['entry_clarity_id'] ) && is_string( $_POST['entry_clarity_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['entry_clarity_id'] ) )
            : '';
        $provider = isset( $_POST['entry_provider'] ) ? sanitize_key( wp_unslash( $_POST['entry_provider'] ) ) : '';
        $provider_id = isset( $_POST['entry_provider_id'] ) && is_string( $_POST['entry_provider_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['entry_provider_id'] ) )
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( '' !== $clarity_id && '' === $provider ) {
            // Compatibility with the Clarity form from 1.1.19.
            $provider = 'clarity';
            $provider_id = $clarity_id;
        }
        if ( '' !== $provider ) {
            $code = self::normalized_provider_code( $provider, $provider_id, $kind );
            if ( '' === $code ) {
                self::redirect( 'invalid' );
            }
        }
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
        if ( '' !== $provider ) {
            foreach ( $entries as $other_id => $other ) {
                if ( $id !== $other_id && is_array( $other ) && ( $other['provider'] ?? '' ) === $provider && ( $other['provider_id'] ?? '' ) === $provider_id ) {
                    self::redirect( 'duplicate' );
                }
            }
            if ( 'google_analytics' === $provider && $enabled ) {
                $google = get_option( 'seo_google_search_settings', array() );
                if ( is_array( $google ) && ! empty( $google['tracking_enabled'] ) && strtoupper( (string) ( $google['measurement_id'] ?? '' ) ) === $provider_id ) {
                    self::redirect( 'duplicate_ga4' );
                }
            }
        }
        $entries[ $id ] = array(
            'name'        => $name,
            'kind'        => $kind,
            'code'        => $code,
            'enabled'     => $enabled,
            'provider'    => $provider,
            'provider_id' => $provider_id,
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

    /** Enable/disable an existing tag without posting its HTML to admin-post.php. */
    public static function toggle() {
        if ( ! self::allowed() ) {
            wp_die( esc_html__( 'No tienes permiso para cambiar etiquetas.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( self::NONCE );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
        $id = isset( $_POST['entry_id'] ) ? sanitize_key( wp_unslash( $_POST['entry_id'] ) ) : '';
        $entries = self::entries();
        if ( ! isset( $entries[ $id ] ) || ! is_array( $entries[ $id ] ) ) {
            self::redirect( 'invalid' );
        }
        $enabled = empty( $entries[ $id ]['enabled'] );
        $entry = $entries[ $id ];
        if ( $enabled && 'google_analytics' === ( $entry['provider'] ?? '' ) ) {
            $google = get_option( 'seo_google_search_settings', array() );
            if ( is_array( $google ) && ! empty( $google['tracking_enabled'] ) && strtoupper( (string) ( $google['measurement_id'] ?? '' ) ) === ( $entry['provider_id'] ?? '' ) ) {
                self::redirect( 'duplicate_ga4' );
            }
        }
        $entries[ $id ]['enabled'] = $enabled;
        update_option( self::OPTION, $entries, false );
        self::redirect( 'updated' );
    }

    /**
     * Enqueue administrator-configured GA4 without injecting external script tags.
     * Preserve the existing per-entry consent and duplicate-provider safeguards.
     *
     * @return void
     */
    public static function enqueue_analytics() {
        if ( is_admin() || is_feed() ) {
            return;
        }
        foreach ( self::entries() as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['enabled'] )
                || 'google_analytics' !== ( $entry['provider'] ?? '' )
                || 'tracking' !== ( $entry['kind'] ?? '' ) ) {
                continue;
            }
            $measurement_id = (string) ( $entry['provider_id'] ?? '' );
            if ( ! preg_match( '/\\AG-[A-Z0-9]{5,32}\\z/', $measurement_id )
                || ! self::validate_code( (string) ( $entry['code'] ?? '' ), 'tracking' ) ) {
                continue;
            }
            $consented = function_exists( 'wp_has_consent' ) && wp_has_consent( 'statistics' );
            $consented = (bool) apply_filters( 'taxocommerce_insights_head_tracking_allowed', $consented, $entry );
            if ( ! $consented ) {
                continue;
            }
            $google = get_option( 'seo_google_search_settings', array() );
            if ( is_array( $google ) && ! empty( $google['tracking_enabled'] )
                && strtoupper( (string) ( $google['measurement_id'] ?? '' ) ) === $measurement_id ) {
                continue;
            }
            $handle = 'tci-gtag-' . sanitize_key( strtolower( $measurement_id ) );
            wp_enqueue_script(
                $handle,
                'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $measurement_id ),
                array(),
                TAXOCOMMERCE_GOOGLE_BING_VERSION,
                false // Supports WordPress 5.8 where the final argument is a boolean.
            );
            if ( function_exists( 'wp_script_add_data' ) ) {
                wp_script_add_data( $handle, 'strategy', 'async' );
            }
            wp_add_inline_script(
                $handle,
                'window.dataLayer=window.dataLayer||[];'
                . 'function gtag(){dataLayer.push(arguments);}'
                . 'gtag("js",new Date());gtag("config",' . wp_json_encode( $measurement_id ) . ');',
                'after'
            );
        }
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
                // GTM can load marketing pixels: require marketing consent, not just analytics.
                $category = 'google_tag_manager' === ( $entry['provider'] ?? '' )
                    || false !== strpos( $code, 'googletagmanager.com/gtm.js' )
                    ? 'marketing' : 'statistics';
                $consented = function_exists( 'wp_has_consent' ) && wp_has_consent( $category );
                $consented = (bool) apply_filters( 'taxocommerce_insights_head_tracking_allowed', $consented, $entry );
                if ( ! $consented ) {
                    continue;
                }
            }
            // GA4 with a validated provider ID was queued by WordPress above.
            // Do not print its saved HTML script again in wp_head().
            if ( 'tracking' === $kind
                && 'google_analytics' === ( $entry['provider'] ?? '' )
                && preg_match( '/\\AG-[A-Z0-9]{5,32}\\z/', (string) ( $entry['provider_id'] ?? '' ) ) ) {
                continue;
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
            'updated' => __( 'Estado de la etiqueta actualizado.', 'taxocommerce-google-bing' ),
            'duplicate' => __( 'Ya existe una etiqueta de este proveedor con el mismo identificador.', 'taxocommerce-google-bing' ),
            'duplicate_ga4' => __( 'Ese ID de Google Analytics ya esta activo en la configuracion de conexiones de Insights. Evita duplicar el seguimiento.', 'taxocommerce-google-bing' ),
        );

        echo '<h2>' . esc_html__( 'Etiquetas de cabecera', 'taxocommerce-google-bing' ) . '</h2>';
        echo '<p>' . esc_html__( 'Pega el codigo HTML completo del proveedor. Se insertara automaticamente en el head de WordPress sin modificar la plantilla.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<p class="description">' . esc_html__( 'Admite Google Search Console (meta), Google Analytics 4, Google Tag Manager y Microsoft Clarity. Los identificadores se extraen del codigo pegado para evitar bloqueos al guardar.', 'taxocommerce-google-bing' ) . '</p>';
        if ( isset( $messages[ $notice ] ) ) {
            echo '<div class="notice notice-' . esc_attr( 'invalid' === $notice || 'limit' === $notice ? 'error' : 'success' ) . ' inline"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
        }
        if ( ! self::allowed() ) {
            echo '<p>' . esc_html__( 'Solo los administradores con permiso unfiltered_html pueden gestionar etiquetas.', 'taxocommerce-google-bing' ) . '</p>';
            return;
        }

        echo '<h3>' . esc_html( $current ? __( 'Editar etiqueta', 'taxocommerce-google-bing' ) : __( 'Nueva etiqueta', 'taxocommerce-google-bing' ) ) . '</h3>';
        if ( $current ) {
            echo '<p><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Cancelar edicion y crear nueva', 'taxocommerce-google-bing' ) . '</a></p>';
        }
        echo '<form id="tgb-head-tags-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_head_tag_save"><input type="hidden" name="entry_id" value="' . esc_attr( $current ? $edit : '' ) . '">';
        echo '<input type="hidden" name="entry_clarity_id" id="tgb-head-tags-clarity-id" value="">';
        echo '<input type="hidden" name="entry_provider" id="tgb-head-tags-provider" value="">';
        echo '<input type="hidden" name="entry_provider_id" id="tgb-head-tags-provider-id" value="">';
        wp_nonce_field( self::NONCE );
        echo '<p><label><strong>' . esc_html__( 'Nombre del proveedor', 'taxocommerce-google-bing' ) . '</strong><br><input class="regular-text" name="entry_name" required maxlength="200" value="' . esc_attr( $current['name'] ?? '' ) . '" placeholder="Microsoft Clarity"></label></p>';
        echo '<p><label><strong>' . esc_html__( 'Tipo de codigo', 'taxocommerce-google-bing' ) . '</strong><br><select name="entry_kind">';
        foreach ( array( 'verification' => __( 'Verificacion (meta)', 'taxocommerce-google-bing' ), 'tracking' => __( 'Seguimiento (scripts, requiere consentimiento)', 'taxocommerce-google-bing' ) ) as $kind => $label ) {
            echo '<option value="' . esc_attr( $kind ) . '" ' . selected( $current['kind'] ?? 'verification', $kind, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label><strong>' . esc_html__( 'Codigo proporcionado por el proveedor', 'taxocommerce-google-bing' ) . '</strong><br><textarea name="entry_code" class="large-text code" rows="10" required maxlength="12000" spellcheck="false">' . esc_textarea( $current['code'] ?? '' ) . '</textarea></label></p>';
        echo '<p><label><input type="checkbox" name="entry_enabled" value="1" ' . checked( ! empty( $current['enabled'] ) || ! $current, true, false ) . '> ' . esc_html__( 'Activar esta etiqueta', 'taxocommerce-google-bing' ) . '</label></p>';
        echo '<p class="description">' . esc_html__( 'Las etiquetas de verificacion se publican sin cookies. Los scripts de seguimiento requieren consentimiento: estadisticas para Analytics/Clarity y marketing para Tag Manager. Se usa WP Consent API o un filtro de integracion. No pegues codigo de fuentes desconocidas.', 'taxocommerce-google-bing' ) . '</p>';
        submit_button( $current ? __( 'Guardar cambios', 'taxocommerce-google-bing' ) : __( 'Añadir etiqueta', 'taxocommerce-google-bing' ) );
        echo '</form>';
        echo '<hr><h3>' . esc_html__( 'Inventario de etiquetas de cabecera', 'taxocommerce-google-bing' )
            . ' (' . esc_html( (string) count( $entries ) ) . ')</h3>';
        if ( ! $entries ) {
            echo '<p class="description">' . esc_html__( 'Todavia no hay etiquetas guardadas. Añade una desde el formulario anterior.', 'taxocommerce-google-bing' ) . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            foreach ( array(
                __( 'Nombre', 'taxocommerce-google-bing' ),
                __( 'Proveedor', 'taxocommerce-google-bing' ),
                __( 'Tipo', 'taxocommerce-google-bing' ),
                __( 'Identificador', 'taxocommerce-google-bing' ),
                __( 'Estado', 'taxocommerce-google-bing' ),
                __( 'Acciones', 'taxocommerce-google-bing' ),
            ) as $heading ) {
                echo '<th scope="col">' . esc_html( $heading ) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ( $entries as $id => $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                $active = ! empty( $entry['enabled'] );
                $edit_url = add_query_arg( 'edit_tag', sanitize_key( $id ), self::url() );
                $provider_id = isset( $entry['provider_id'] ) && '' !== $entry['provider_id']
                    ? $entry['provider_id'] : '—';
                echo '<tr><td><strong>' . esc_html( $entry['name'] ?? '' ) . '</strong></td>';
                echo '<td>' . esc_html( self::provider_name( $entry ) ) . '</td>';
                echo '<td>' . esc_html( 'verification' === ( $entry['kind'] ?? '' )
                    ? __( 'Verificacion', 'taxocommerce-google-bing' )
                    : __( 'Seguimiento', 'taxocommerce-google-bing' ) ) . '</td>';
                echo '<td><code>' . esc_html( $provider_id ) . '</code></td>';
                echo '<td>' . esc_html( $active
                    ? __( 'Activa', 'taxocommerce-google-bing' )
                    : __( 'Inactiva', 'taxocommerce-google-bing' ) ) . '</td><td>';
                echo '<a class="button button-small" href="' . esc_url( $edit_url ) . '">'
                    . esc_html__( 'Editar', 'taxocommerce-google-bing' ) . '</a> ';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="tgb_head_tag_toggle"><input type="hidden" name="entry_id" value="' . esc_attr( $id ) . '">';
                wp_nonce_field( self::NONCE );
                echo '<button class="button button-small" type="submit">'
                    . esc_html( $active ? __( 'Desactivar', 'taxocommerce-google-bing' ) : __( 'Activar', 'taxocommerce-google-bing' ) )
                    . '</button></form> ';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="tgb_head_tag_delete"><input type="hidden" name="entry_id" value="' . esc_attr( $id ) . '">';
                wp_nonce_field( self::NONCE );
                echo '<button class="button button-small" type="submit">'
                    . esc_html__( 'Eliminar', 'taxocommerce-google-bing' ) . '</button></form></td></tr>';
            }
            echo '</tbody></table>';
        }

        // A host WAF may reject POST requests containing raw <script> markup.
        // Extract only a known Clarity project ID in the browser, and generate
        // the official snippet server-side after checking the nonce and caps.
        ?>
        <script>
        (function () {
            'use strict';
            var form = document.getElementById('tgb-head-tags-form');
            var code = form && form.querySelector('textarea[name="entry_code"]');
            var kind = form && form.querySelector('select[name="entry_kind"]');
            var legacyClarity = document.getElementById('tgb-head-tags-clarity-id');
            var providerInput = document.getElementById('tgb-head-tags-provider');
            var providerIdInput = document.getElementById('tgb-head-tags-provider-id');
            if (!form || !code || !kind || !legacyClarity || !providerInput || !providerIdInput) {
                return;
            }
            form.addEventListener('submit', function () {
                var text = code.value;
                var provider = '';
                var identifier = '';
                var type = '';
                var match;
                code.disabled = false;
                legacyClarity.value = '';
                providerInput.value = '';
                providerIdInput.value = '';

                // Search Console's official meta verification tag (independent of attribute order).
                var tags = text.match(/<meta\b[^>]*>/gi) || [];
                for (var i = 0; i < tags.length; i++) {
                    if (/\bname\s*=\s*["']google-site-verification["']/i.test(tags[i])) {
                        match = tags[i].match(/\bcontent\s*=\s*["']([A-Za-z0-9_-]{8,256})["']/);
                        if (match) {
                            provider = 'google_verification';
                            identifier = match[1];
                            type = 'verification';
                            break;
                        }
                    }
                }
                if (!provider) {
                    match = text.match(/\b(GTM-[A-Z0-9]{5,32})\b/i);
                    if (match && (text.indexOf('<script') !== -1 || /^\s*GTM-[A-Z0-9]+\s*$/i.test(text))) {
                        provider = 'google_tag_manager';
                        identifier = match[1].toUpperCase();
                        type = 'tracking';
                    }
                }
                if (!provider) {
                    match = text.match(/\b(G-[A-Z0-9]{5,32})\b/i);
                    if (match && (text.indexOf('<script') !== -1 || /^\s*G-[A-Z0-9]+\s*$/i.test(text))) {
                        provider = 'google_analytics';
                        identifier = match[1].toUpperCase();
                        type = 'tracking';
                    }
                }
                if (!provider) {
                    match = text.match(/\}\s*\)\s*\(\s*window\s*,\s*document\s*,\s*["']clarity["']\s*,\s*["']script["']\s*,\s*["']([a-z0-9]{6,32})["']\s*\)/i);
                    if (!match) {
                        match = text.match(/https:\/\/(?:www\.)?clarity\.ms\/tag\/([a-z0-9]{6,32})/i);
                    }
                    if (match) {
                        provider = 'clarity';
                        identifier = match[1].toLowerCase();
                        type = 'tracking';
                    }
                }
                if (provider) {
                    // The user still pastes the provider's full snippet. No HTML/JS
                    // travels through the WAF: PHP rebuilds a fixed, vetted snippet.
                    kind.value = type;
                    providerInput.value = provider;
                    providerIdInput.value = identifier;
                    if (provider === 'clarity') {
                        legacyClarity.value = identifier;
                    }
                    code.disabled = true;
                }
            });
        }());
        </script>
        <?php
    }
}
