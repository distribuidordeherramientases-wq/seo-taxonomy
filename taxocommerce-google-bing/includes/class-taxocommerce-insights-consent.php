<?php
/**
 * Visitor consent manager with editable, per-locale legal texts.
 *
 * Settings live in wp_options; visitor preferences stay in the browser cookie.
 * No customer identifiers, logs, or custom SQL tables are created.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_Consent {
    const OPTION = 'taxocommerce_insights_consent_settings';
    const COOKIE = 'tci_consent';
    const NONCE = 'tci_consent_save';
    const DAYS = 180;

    public static function languages() {
        return array(
            'es' => 'Español',
            'en' => 'English',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'nl' => 'Nederlands',
        );
    }

    /** Initial drafts are configurable examples, not a substitute for legal review. */
    public static function defaults_for_locale( $lang ) {
        $texts = array(
            'es' => array(
                'title' => 'Tu privacidad y las cookies',
                'intro' => 'Utilizamos tecnologías necesarias para el funcionamiento de la tienda. Con tu permiso también podemos medir visitas y mejorar la experiencia mediante Google Analytics y Microsoft Clarity.',
                'legal' => 'Las cookies necesarias permiten funcionalidades como el carrito y la seguridad. Las estadísticas pueden medir páginas vistas, clics, navegación y conversiones. Las tecnologías de marketing, cuando estén configuradas, pueden personalizar anuncios. Puedes aceptar todas, rechazarlas o elegir por finalidad. No activamos estadísticas ni marketing sin tu permiso. Puedes modificar o retirar tu elección desde el enlace de preferencias de cookies. Revisa la política de cookies para conocer proveedores, plazos y responsables.',
                'accept' => 'Aceptar todas',
                'reject' => 'Rechazar opcionales',
                'configure' => 'Configurar',
                'save' => 'Guardar preferencias',
                'reopen' => 'Preferencias de cookies',
                'statistics' => 'Estadísticas (GA4 y Clarity)',
                'marketing' => 'Marketing y publicidad',
                'preferences' => 'Preferencias opcionales',
                'necessary' => 'Necesarias (siempre activas)',
                'policy' => 'Política de cookies',
            ),
            'en' => array(
                'title' => 'Your privacy and cookies',
                'intro' => 'We use technologies necessary for this shop to work. With your permission we may also measure visits and improve usability using Google Analytics and Microsoft Clarity.',
                'legal' => 'Necessary cookies support shopping carts and security. Statistics may measure page views, clicks, navigation and conversions. Marketing technologies, where configured, may personalise advertising. You can accept all, reject optional cookies or select purposes. Statistics and marketing remain off without your permission. You can change or withdraw your choice via Cookie preferences. Consult the cookie policy for providers, retention periods and controllers.',
                'accept' => 'Accept all',
                'reject' => 'Reject optional',
                'configure' => 'Customise',
                'save' => 'Save preferences',
                'reopen' => 'Cookie preferences',
                'statistics' => 'Statistics (GA4 and Clarity)',
                'marketing' => 'Marketing and advertising',
                'preferences' => 'Optional preferences',
                'necessary' => 'Necessary (always active)',
                'policy' => 'Cookie policy',
            ),
            'fr' => array(
                'title' => 'Confidentialité et cookies',
                'intro' => 'Nous utilisons des technologies nécessaires au fonctionnement de cette boutique. Avec votre accord, Google Analytics et Microsoft Clarity peuvent nous aider à mesurer la fréquentation.',
                'legal' => 'Les cookies nécessaires permettent le panier et la sécurité. Les statistiques peuvent mesurer les pages vues, clics, navigation et conversions. Les technologies marketing, lorsqu’elles sont configurées, peuvent personnaliser les publicités. Vous pouvez tout accepter, refuser les cookies facultatifs ou choisir par finalité. Vous pouvez modifier ou retirer votre choix dans les préférences de cookies. Consultez notre politique pour connaître les prestataires et les durées de conservation.',
                'accept' => 'Tout accepter',
                'reject' => 'Refuser les facultatifs',
                'configure' => 'Personnaliser',
                'save' => 'Enregistrer',
                'reopen' => 'Préférences de cookies',
                'statistics' => 'Statistiques (GA4 et Clarity)',
                'marketing' => 'Marketing et publicité',
                'preferences' => 'Préférences facultatives',
                'necessary' => 'Nécessaires (toujours actifs)',
                'policy' => 'Politique de cookies',
            ),
            'de' => array(
                'title' => 'Datenschutz und Cookies',
                'intro' => 'Wir verwenden notwendige Technologien für den Betrieb dieses Shops. Mit Ihrer Einwilligung können Google Analytics und Microsoft Clarity uns bei der Analyse der Nutzung helfen.',
                'legal' => 'Notwendige Cookies unterstützen Warenkorb und Sicherheit. Statistikdienste können Seitenaufrufe, Klicks, Navigation und Conversions messen. Marketing-Technologien können, sofern eingerichtet, Werbung personalisieren. Sie können alle akzeptieren, optionale Cookies ablehnen oder nach Zweck auswählen. Statistik und Marketing bleiben ohne Ihre Einwilligung deaktiviert. Sie können Ihre Wahl jederzeit über Cookie-Einstellungen ändern oder widerrufen. Weitere Angaben finden Sie in der Cookie-Richtlinie.',
                'accept' => 'Alle akzeptieren',
                'reject' => 'Optionale ablehnen',
                'configure' => 'Konfigurieren',
                'save' => 'Auswahl speichern',
                'reopen' => 'Cookie-Einstellungen',
                'statistics' => 'Statistik (GA4 und Clarity)',
                'marketing' => 'Marketing und Werbung',
                'preferences' => 'Optionale Präferenzen',
                'necessary' => 'Notwendig (immer aktiv)',
                'policy' => 'Cookie-Richtlinie',
            ),
            'nl' => array(
                'title' => 'Privacy en cookies',
                'intro' => 'We gebruiken noodzakelijke technologie voor deze winkel. Met jouw toestemming kunnen Google Analytics en Microsoft Clarity ons helpen bezoeken te analyseren.',
                'legal' => 'Noodzakelijke cookies ondersteunen het winkelmandje en de beveiliging. Statistieken kunnen paginaweergaven, klikken, navigatie en conversies meten. Marketingtechnologie kan, indien ingesteld, advertenties personaliseren. Je kunt alles accepteren, optionele cookies weigeren of per doel kiezen. Statistieken en marketing blijven uit zonder toestemming. Je kunt je keuze op elk moment wijzigen of intrekken via Cookievoorkeuren. Raadpleeg het cookiebeleid voor aanbieders en bewaartermijnen.',
                'accept' => 'Alles accepteren',
                'reject' => 'Optionele weigeren',
                'configure' => 'Instellen',
                'save' => 'Voorkeuren opslaan',
                'reopen' => 'Cookievoorkeuren',
                'statistics' => 'Statistieken (GA4 en Clarity)',
                'marketing' => 'Marketing en advertenties',
                'preferences' => 'Optionele voorkeuren',
                'necessary' => 'Noodzakelijk (altijd actief)',
                'policy' => 'Cookiebeleid',
            ),
        );
        return isset( $texts[ $lang ] ) ? $texts[ $lang ] : $texts['es'];
    }

    public static function defaults() {
        $texts = array();
        foreach ( array_keys( self::languages() ) as $lang ) {
            $texts[ $lang ] = self::defaults_for_locale( $lang );
        }
        return array(
            'enabled' => 0,
            'legal_reviewed' => 0,
            'revision' => 1,
            'cookie_policy_url' => '',
            'texts' => $texts,
        );
    }

    /** Store initial draft only on first installation; never reset on update. */
    public static function install( $network_wide = false ) {
        if ( $network_wide && is_multisite() ) {
            $offset = 0;
            do {
                $sites = get_sites( array( 'number' => 100, 'offset' => $offset, 'fields' => 'ids' ) );
                foreach ( $sites as $site_id ) {
                    switch_to_blog( (int) $site_id );
                    add_option( self::OPTION, self::defaults(), '', false );
                    restore_current_blog();
                }
                $offset += count( $sites );
            } while ( count( $sites ) === 100 );
        } else {
            add_option( self::OPTION, self::defaults(), '', false );
        }
    }

    public static function settings() {
        $saved = get_option( self::OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        $defaults = self::defaults();
        $config = wp_parse_args( $saved, $defaults );
        $config['texts'] = is_array( $config['texts'] ) ? $config['texts'] : array();
        foreach ( array_keys( self::languages() ) as $lang ) {
            $row = isset( $config['texts'][ $lang ] ) && is_array( $config['texts'][ $lang ] )
                ? $config['texts'][ $lang ] : array();
            $config['texts'][ $lang ] = wp_parse_args( $row, $defaults['texts'][ $lang ] );
        }
        return $config;
    }

    public static function language() {
        $locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
        $code = strtolower( substr( (string) $locale, 0, 2 ) );
        $languages = self::languages();
        return isset( $languages[ $code ] ) ? $code : 'es';
    }

    /** v1|revision|statistics|marketing|preferences, strictly parsed. */
    public static function choices() {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict grammar and a non-mutating sanitization check below reject every malformed value before use.
        $raw = isset( $_COOKIE[ self::COOKIE ] ) && is_string( $_COOKIE[ self::COOKIE ] )
            ? rawurldecode( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // Sanitizing a malformed cookie must NEVER turn it into a valid opt-in.
        if ( strlen( $raw ) > 64 || $raw !== sanitize_text_field( $raw )
            || ! preg_match( '/\A1\|([0-9]{1,10})\|([01])\|([01])\|([01])\z/D', $raw, $matches ) ) {
            return null;
        }
        $settings = self::settings();
        if ( (int) $matches[1] !== (int) $settings['revision'] ) {
            return null;
        }
        return array(
            'statistics' => '1' === $matches[2],
            'marketing' => '1' === $matches[3],
            'preferences' => '1' === $matches[4],
        );
    }

    public static function active() {
        $cfg = self::settings();
        return ! empty( $cfg['enabled'] ) && ! empty( $cfg['legal_reviewed'] )
            && ! empty( $cfg['cookie_policy_url'] );
    }

    public static function has_consent( $category ) {
        if ( 'functional' === $category || 'necessary' === $category ) {
            return true;
        }
        if ( ! self::active() ) {
            return false;
        }
        $choices = self::choices();
        return is_array( $choices ) && ! empty( $choices[ $category ] );
    }

    /** Shared contract for Insights ecommerce, Cabeceras and other modules. */
    public static function tracking_allowed( $allowed, $entry ) {
        if ( ! self::active() ) {
            return $allowed;
        }
        $category = is_array( $entry ) && (
            'google_tag_manager' === ( $entry['provider'] ?? '' ) ||
            false !== strpos( (string) ( $entry['code'] ?? '' ), 'googletagmanager.com/gtm.js' )
        ) ? 'marketing' : 'statistics';
        return self::has_consent( $category );
    }

    /** WP Consent API returns a signal to its consumers, with default denial. */
    public static function consent_type( $type ) {
        return self::active() ? 'optin' : $type;
    }

    public static function consent_api( $allowed, $category ) {
        return self::active() ? self::has_consent( $category ) : $allowed;
    }

    public static function register() {
        add_action( 'admin_post_tci_consent_save', array( __CLASS__, 'save' ) );
        add_filter( 'taxocommerce_insights_head_tracking_allowed', array( __CLASS__, 'tracking_allowed' ), 999, 2 );
        add_filter( 'wp_get_consent_type', array( __CLASS__, 'consent_type' ), 999 );
        add_filter( 'wp_has_consent', array( __CLASS__, 'consent_api' ), 999, 2 );
        add_action( 'send_headers', array( __CLASS__, 'privacy_cache_headers' ), 1 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 9 );
        add_action( 'wp_head', array( __CLASS__, 'clarity_consent' ), 95 );
        add_action( 'wp_footer', array( __CLASS__, 'banner' ), 30 );
    }

    private static function url( $notice = '' ) {
        $args = array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'consent' );
        if ( $notice ) {
            $args['tci_consent_notice'] = $notice;
        }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( self::NONCE );
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer above.

        $old = self::settings();
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nested text values are sanitized individually in the allowlisted language/field loop below.
        $raw_texts = isset( $_POST['texts'] ) && is_array( $_POST['texts'] )
            ? wp_unslash( $_POST['texts'] ) : array();
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $texts = array();
        foreach ( array_keys( self::languages() ) as $lang ) {
            $texts[ $lang ] = array();
            foreach ( array_keys( self::defaults_for_locale( $lang ) ) as $key ) {
                $value = $raw_texts[ $lang ][ $key ] ?? '';
                $texts[ $lang ][ $key ] = is_string( $value )
                    ? sanitize_textarea_field( $value ) : '';
                if ( '' === trim( $texts[ $lang ][ $key ] ) ) {
                    $texts[ $lang ][ $key ] = self::defaults_for_locale( $lang )[ $key ];
                }
            }
        }
        $policy_url = isset( $_POST['cookie_policy_url'] ) && is_string( $_POST['cookie_policy_url'] )
            ? esc_url_raw( wp_unslash( $_POST['cookie_policy_url'] ) ) : '';
        $reviewed = ! empty( $_POST['legal_reviewed'] ) ? 1 : 0;
        $enabled = ! empty( $_POST['enabled'] ) ? 1 : 0;
        if ( $enabled && ( ! $reviewed || '' === $policy_url ) ) {
            wp_safe_redirect( self::url( 'review_required' ) );
            exit;
        }
        $next = array(
            'enabled' => $enabled,
            'legal_reviewed' => $reviewed,
            'cookie_policy_url' => $policy_url,
            'texts' => $texts,
            // A change to legal wording or purpose requires a new affirmative choice.
            'revision' => max( 1, (int) $old['revision'] + 1 ),
        );
        TaxoCommerce_Insights_Consent_Ledger::archive( $old );
        TaxoCommerce_Insights_Consent_Ledger::archive( $next );
        update_option( self::OPTION, $next, false );
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
        wp_safe_redirect( self::url( 'saved' ) );
        exit;
    }

    public static function render() {
        $cfg = self::settings();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only notice from a previous, nonce-protected admin action.
        $notice = isset( $_GET['tci_consent_notice'] )
            ? sanitize_key( wp_unslash( $_GET['tci_consent_notice'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        echo '<div class="card" style="max-width:1200px;padding:20px;margin-top:18px">';
        echo '<h2>' . esc_html__( 'Consentimientos · textos legales y banner', 'taxocommerce-google-bing' ) . '</h2>';
        echo '<p>' . esc_html__( 'Configuración compartida para las etiquetas de Insights y otros módulos. Desactivada hasta revisar los textos legales y la política de cookies. Los proveedores API de servidor no requieren estas cookies.', 'taxocommerce-google-bing' ) . '</p>';
        if ( 'saved' === $notice ) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Configuración guardada. Las decisiones antiguas deberán renovarse.', 'taxocommerce-google-bing' ) . '</p></div>';
        } elseif ( 'review_required' === $notice ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Antes de activar, revisa el texto legal y añade una URL válida de política de cookies.', 'taxocommerce-google-bing' ) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tci_consent_save">';
        wp_nonce_field( self::NONCE );
        echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $cfg['enabled'] ), true, false ) . '> '
            . esc_html__( 'Activar el banner propio (solo si no hay otro gestor de consentimiento)', 'taxocommerce-google-bing' ) . '</label></p>';
        echo '<p><label for="tci-policy"><strong>' . esc_html__( 'URL de la política de cookies', 'taxocommerce-google-bing' ) . '</strong></label><br>';
        echo '<input id="tci-policy" class="large-text" type="url" name="cookie_policy_url" value="' . esc_attr( $cfg['cookie_policy_url'] ) . '" placeholder="https://ejemplo.es/politica-de-cookies/"></p>';
        echo '<p class="description">' . esc_html__( 'La política debe identificar los proveedores, las finalidades y los periodos de conservación reales. Los textos iniciales son ejemplos que el titular de la web debe revisar.', 'taxocommerce-google-bing' ) . '</p>';
        $current = self::language();
        foreach ( self::languages() as $lang => $name ) {
            echo '<details style="margin:14px 0;padding:12px;border:1px solid #dcdcde;border-radius:4px"' . ( $lang === $current ? ' open' : '' ) . '>';
            echo '<summary><strong>' . esc_html( $name ) . ' (' . esc_html( strtoupper( $lang ) ) . ')</strong></summary>';
            foreach ( self::defaults_for_locale( $lang ) as $key => $default ) {
                $labels = array(
                    'title' => 'Título del aviso', 'intro' => 'Mensaje inicial para aceptar o rechazar',
                    'legal' => 'Texto legal detallado visible al configurar',
                    'accept' => 'Botón Aceptar', 'reject' => 'Botón Rechazar',
                    'configure' => 'Botón Configurar', 'save' => 'Botón Guardar',
                    'reopen' => 'Enlace para cambiar permisos',
                    'statistics' => 'Categoría estadísticas', 'marketing' => 'Categoría marketing',
                    'preferences' => 'Categoría preferencias', 'necessary' => 'Categoría necesarias',
                    'policy' => 'Enlace política de cookies',
                );
                $value = (string) ( $cfg['texts'][ $lang ][ $key ] ?? $default );
                echo '<p><label><strong>' . esc_html( $labels[ $key ] ) . '</strong><br>';
                if ( 'intro' === $key || 'legal' === $key ) {
                    echo '<textarea class="large-text" name="texts[' . esc_attr( $lang ) . '][' . esc_attr( $key ) . ']" rows="' . ( 'legal' === $key ? '5' : '3' ) . '">'
                        . esc_textarea( $value ) . '</textarea>';
                } else {
                    echo '<input class="large-text" type="text" maxlength="300" name="texts[' . esc_attr( $lang ) . '][' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
                }
                echo '</label></p>';
            }
            echo '</details>';
        }
        echo '<p><label><input type="checkbox" name="legal_reviewed" value="1" ' . checked( ! empty( $cfg['legal_reviewed'] ), true, false ) . '> '
            . esc_html__( 'Confirmo que he revisado los textos y las obligaciones legales de esta instalación en los idiomas publicados.', 'taxocommerce-google-bing' ) . '</label></p>';
        echo '<p class="description">' . esc_html__( 'Los textos se guardan en wp_options y las decisiones en una tabla SQL persistente. Desactivar o desinstalar el plugin no elimina las evidencias. Las preferencias del navegador se guardan durante 180 días.', 'taxocommerce-google-bing' ) . '</p>';
        submit_button( __( 'Guardar textos y consentimientos', 'taxocommerce-google-bing' ) );
        echo '</form></div>';
        TaxoCommerce_Insights_Consent_Ledger::render();
    }

    public static function privacy_cache_headers() {
        if ( ! self::active() || is_admin() || is_feed() ) {
            return;
        }
        // User-specific PHP head output must never be stored in a shared page
        // cache and served to a different, non-consenting visitor.
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is WordPress' standard page-cache suppression constant.
            define( 'DONOTCACHEPAGE', true );
        }
        nocache_headers();
    }

    public static function assets() {
        if ( ! self::active() || is_admin() || is_feed() ) {
            return;
        }
        wp_enqueue_style(
            'tci-consent',
            TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/consent.css',
            array(),
            TAXOCOMMERCE_GOOGLE_BING_VERSION
        );
        wp_enqueue_script(
            'tci-consent',
            TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/consent.js',
            array(),
            TAXOCOMMERCE_GOOGLE_BING_VERSION,
            true
        );
        $cfg = self::settings();
        wp_add_inline_script( 'tci-consent', 'window.tciConsentConfig=' . wp_json_encode(
            array( 'cookie' => self::COOKIE, 'revision' => (int) $cfg['revision'], 'days' => self::DAYS, 'endpoint' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'tci_consent_record' ) ),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . ';', 'before' );
    }

    /** Before the vendor loader: consent v2 never runs without statistics permission. */
    public static function clarity_consent() {
        if ( ! self::active() || ! self::has_consent( 'statistics' ) || is_admin() || is_feed() ) {
            return;
        }
        $entries = get_option( 'taxocommerce_insights_head_tags', array() );
        $enabled = false;
        foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
            if ( is_array( $entry ) && ! empty( $entry['enabled'] )
                && 'tracking' === ( $entry['kind'] ?? '' )
                && ( 'clarity' === ( $entry['provider'] ?? '' )
                    || false !== strpos( (string) ( $entry['code'] ?? '' ), 'clarity.ms/tag' ) ) ) {
                $enabled = true;
                break;
            }
        }
        if ( ! $enabled ) {
            return;
        }
        $ads = self::has_consent( 'marketing' ) ? 'granted' : 'denied';
        echo '<script id="tci-clarity-consentv2">window.clarity=window.clarity||function(){(window.clarity.q=window.clarity.q||[]).push(arguments)};window.clarity("consentv2",{ad_Storage:"'
            . esc_js( $ads ) . '",analytics_Storage:"granted"});</script>' . "\n";
    }

    /** Footer link remains available so a visitor can change or withdraw consent. */
    public static function banner() {
        if ( ! self::active() || is_admin() || is_feed() ) {
            return;
        }
        $settings = self::settings();
        $locale = self::language();
        $t = $settings['texts'][ $locale ];
        $already = self::choices();
        echo '<aside id="tci-consent-banner" class="tci-consent-banner" role="region" aria-label="' . esc_attr( $t['title'] ) . '"' . ( null !== $already ? ' hidden' : '' ) . '>';
        echo '<h2>' . esc_html( $t['title'] ) . '</h2>';
        echo '<p>' . nl2br( esc_html( $t['intro'] ) ) . '</p>';
        echo '<div class="tci-consent-actions">';
        echo '<button type="button" class="tci-consent-btn" data-tci-consent="accept">' . esc_html( $t['accept'] ) . '</button>';
        echo '<button type="button" class="tci-consent-btn" data-tci-consent="reject">' . esc_html( $t['reject'] ) . '</button>';
        echo '<button type="button" class="tci-consent-btn" data-tci-consent="configure">' . esc_html( $t['configure'] ) . '</button>';
        echo '</div>';
        echo '<div id="tci-consent-details" hidden>';
        echo '<p>' . nl2br( esc_html( $t['legal'] ) ) . '</p>';
        echo '<p><label><input type="checkbox" checked disabled> ' . esc_html( $t['necessary'] ) . '</label></p>';
        foreach ( array( 'statistics', 'marketing', 'preferences' ) as $category ) {
            echo '<p><label><input type="checkbox" data-tci-category="' . esc_attr( $category ) . '"> '
                . esc_html( $t[ $category ] ) . '</label></p>';
        }
        echo '<p><button type="button" class="tci-consent-btn" data-tci-consent="save">' . esc_html( $t['save'] ) . '</button></p></div>';
        echo '<p><a href="' . esc_url( $settings['cookie_policy_url'] ) . '" rel="noopener noreferrer">' . esc_html( $t['policy'] ) . '</a></p>';
        echo '</aside>';
        echo '<button id="tci-consent-reopen" class="tci-consent-reopen" type="button">' . esc_html( $t['reopen'] ) . '</button>';
    }
}

/** Public API for other TaxoCommerce modules; defaults to no optional consent. */
function taxocommerce_insights_has_consent( $category ) {
    return TaxoCommerce_Insights_Consent::has_consent( $category );
}
