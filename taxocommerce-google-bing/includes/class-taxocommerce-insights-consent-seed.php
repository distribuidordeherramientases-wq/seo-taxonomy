<?php
/**
 * Safe, one-time import of the five-language cookie consent form into wp_options.
 *
 * The bundled JSON is a DRAFT, not a legal approval or a consent decision.
 * Existing custom labels, controls, and all other settings remain untouched.
 *
 * @package TaxoCommerceInsights
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_Consent_Seed {
    const MARKER = 'taxocommerce_insights_consent_seed_20261108';
    const BACKUP = 'taxocommerce_insights_consent_before_seed_20261108';
    const EXPORT_ACTION = 'tci_consent_export_json';
    const EXPORT_NONCE = 'tci_consent_export_json';
    const TEMPLATE_ACTION = 'tci_consent_template_json';
    const TEMPLATE_NONCE = 'tci_consent_template_json';
    const IMPORT_ACTION = 'tci_consent_import_json';
    const IMPORT_NONCE = 'tci_consent_import_json';
    const IMPORT_BACKUP = 'taxocommerce_insights_consent_before_last_json_import';
    const MAX_IMPORT_BYTES = 65536;

    private static function seed_path() {
        return dirname( __DIR__ ) . '/config/consent-texts-20261108.json';
    }

    public static function register() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ), 20 );
        add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export_json' ) );
        add_action( 'admin_post_' . self::TEMPLATE_ACTION, array( __CLASS__, 'export_template_json' ) );
        add_action( 'admin_post_' . self::IMPORT_ACTION, array( __CLASS__, 'import_json' ) );
    }

    public static function seed() {
        $path = self::seed_path();
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return null;
        }
        // This is a fixed, read-only JSON file bundled with the plugin, not a
        // user-supplied path, remote URL or request parameter.
        $json = file_get_contents( $path );
        if ( ! is_string( $json ) || strlen( $json ) > 32768 ) {
            return null;
        }
        $seed = json_decode( $json, true );
        if ( ! is_array( $seed ) || 1 !== ( $seed['schema'] ?? null )
            || TaxoCommerce_Insights_Consent::OPTION !== ( $seed['option'] ?? null )
            || ! isset( $seed['texts'] ) || ! is_array( $seed['texts'] ) ) {
            return null;
        }
        foreach ( array_keys( TaxoCommerce_Insights_Consent::languages() ) as $language ) {
            if ( ! isset( $seed['texts'][ $language ] ) || ! is_array( $seed['texts'][ $language ] ) ) {
                return null;
            }
            foreach ( array_keys( TaxoCommerce_Insights_Consent::defaults_for_locale( $language ) ) as $field ) {
                $value = $seed['texts'][ $language ][ $field ] ?? null;
                if ( ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 4096 ) {
                    return null;
                }
            }
        }
        return $seed;
    }

    /**
     * Merge all 65 text fields without overriding an administrator's edits.
     *
     * @return array|null Null means that an existing activation/review must not
     *                    be altered automatically.
     */
    public static function merge( $existing, $seed ) {
        if ( ! is_array( $existing ) || ! is_array( $seed ) || ! isset( $seed['texts'] ) ) {
            return null;
        }
        if ( ! empty( $existing['enabled'] ) || ! empty( $existing['legal_reviewed'] ) ) {
            return null;
        }
        $next = $existing;
        $next['texts'] = isset( $existing['texts'] ) && is_array( $existing['texts'] )
            ? $existing['texts'] : array();
        foreach ( array_keys( TaxoCommerce_Insights_Consent::languages() ) as $language ) {
            $defaults = TaxoCommerce_Insights_Consent::defaults_for_locale( $language );
            $stored = isset( $next['texts'][ $language ] ) && is_array( $next['texts'][ $language ] )
                ? $next['texts'][ $language ] : array();
            foreach ( $defaults as $field => $default_text ) {
                $current = $stored[ $field ] ?? null;
                if ( ! is_string( $current ) || '' === trim( $current ) || $current === $default_text ) {
                    $stored[ $field ] = $seed['texts'][ $language ][ $field ];
                }
            }
            $next['texts'][ $language ] = $stored;
        }
        // Neither the policy URL nor the banner/legal-review controls may be
        // changed by importing draft texts. Five policy pages are still drafts.
        return $next;
    }

    /** Runs once on the first authenticated admin request after deployment. */
    public static function maybe_migrate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $marker = get_option( self::MARKER, array() );
        if ( is_array( $marker ) && ! empty( $marker['completed'] ) ) {
            return;
        }
        $seed = self::seed();
        if ( null === $seed ) {
            return;
        }
        $stored = get_option( TaxoCommerce_Insights_Consent::OPTION, false );
        $existing = TaxoCommerce_Insights_Consent::settings();
        $next = self::merge( $existing, $seed );
        if ( null === $next ) {
            // An already active or legally reviewed form is never rewritten.
            return;
        }
        $changed = $existing !== $next;
        if ( $changed ) {
            $next['revision'] = max( 1, (int) $existing['revision'] + 1 );
            // Store the exact former option for manual rollback, not only the
            // defaults supplied by settings().
            add_option( self::BACKUP, array(
                'had_option' => is_array( $stored ),
                'previous'   => $stored,
            ), '', false );
            TaxoCommerce_Insights_Consent_Ledger::archive( $existing );
            $saved = update_option( TaxoCommerce_Insights_Consent::OPTION, $next, false );
            if ( ! $saved && get_option( TaxoCommerce_Insights_Consent::OPTION ) !== $next ) {
                return;
            }
            TaxoCommerce_Insights_Consent_Ledger::archive( $next );
        }
        update_option( self::MARKER, array(
            'completed' => 1,
            'saved_at'  => current_time( 'mysql', true ),
            'updated'   => $changed ? 1 : 0,
            'version'   => '20261108',
        ), false );
    }

    /** Export the actual stored form for verification and future backup. */
    public static function export_json() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( self::EXPORT_NONCE );
        $settings = TaxoCommerce_Insights_Consent::settings();
        $urls = array();
        foreach ( array_keys( TaxoCommerce_Insights_Consent::policy_page_defaults() ) as $country ) {
            $urls[ $country ] = TaxoCommerce_Insights_Consent::policy_url_for_market( $country, $settings );
        }
        $data = array_merge(
            array(
                'schema'        => 2,
                'option'        => TaxoCommerce_Insights_Consent::OPTION,
                'mode'          => 'replace',
                'exported_at'   => gmdate( 'c' ),
            ),
            $settings
        );
        // Informational URLs are NOT portable. Slugs in cookie_policy_pages are.
        $data['resolved_policy_urls'] = $urls;
        self::download( $data, 'taxocommerce-insights-consent-completo-' . gmdate( 'Ymd-His' ) . '.json' );
    }

    /**
     * Editable JSON skeleton with empty values for every one of the 65 texts.
     * Activation, legal review and revision are informational; JSON uploads
     * are never allowed to set these protected controls.
     */
    public static function blank_template() {
        $texts = array();
        foreach ( array_keys( TaxoCommerce_Insights_Consent::languages() ) as $language ) {
            $texts[ $language ] = array();
            foreach ( array_keys( TaxoCommerce_Insights_Consent::defaults_for_locale( $language ) ) as $field ) {
                $texts[ $language ][ $field ] = '';
            }
        }
        return array(
            'schema'              => 2,
            'option'              => TaxoCommerce_Insights_Consent::OPTION,
            'mode'                => 'merge',
            'enabled'             => 0,
            'legal_reviewed'      => 0,
            'revision'            => 0,
            'cookie_policy_url'   => '',
            'cookie_policy_pages' => array_fill_keys(
                array_keys( TaxoCommerce_Insights_Consent::policy_page_defaults() ), ''
            ),
            'texts'               => $texts,
        );
    }

    private static function download( $data, $filename ) {
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    public static function export_template_json() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( self::TEMPLATE_NONCE );
        self::download( self::blank_template(), 'taxocommerce-insights-consent-plantilla-vacia.json' );
    }

    /**
     * Validate the complete exported/blank JSON shape before touching options.
     * Missing, unknown or excessively long fields invalidate the entire file.
     *
     * @return array|false Validated data or false.
     */
    public static function parse_import( $content ) {
        if ( ! is_string( $content ) || '' === trim( $content )
            || strlen( $content ) > self::MAX_IMPORT_BYTES ) {
            return false;
        }
        $data = json_decode( $content, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data )
            || ! in_array( $data['schema'] ?? null, array( 1, 2 ), true )
            || TaxoCommerce_Insights_Consent::OPTION !== ( $data['option'] ?? null )
            || ! isset( $data['texts'] ) || ! is_array( $data['texts'] ) ) {
            return false;
        }
        $languages = TaxoCommerce_Insights_Consent::languages();
        if ( count( $data['texts'] ) !== count( $languages ) ) {
            return false;
        }
        foreach ( $languages as $language => $name ) {
            if ( ! isset( $data['texts'][ $language ] ) || ! is_array( $data['texts'][ $language ] ) ) {
                return false;
            }
            $fields = TaxoCommerce_Insights_Consent::defaults_for_locale( $language );
            if ( count( $data['texts'][ $language ] ) !== count( $fields ) ) {
                return false;
            }
            foreach ( $fields as $field => $default ) {
                if ( ! array_key_exists( $field, $data['texts'][ $language ] )
                    || ! is_string( $data['texts'][ $language ][ $field ] ) ) {
                    return false;
                }
                $limit = in_array( $field, array( 'intro', 'legal' ), true ) ? 4096 : 300;
                if ( strlen( $data['texts'][ $language ][ $field ] ) > $limit ) {
                    return false;
                }
            }
        }
        if ( isset( $data['cookie_policy_url'] )
            && ( ! is_string( $data['cookie_policy_url'] ) || strlen( $data['cookie_policy_url'] ) > 2048 ) ) {
            return false;
        }
        // Legacy v1 files only contained texts and one URL: merge without
        // assuming the site-specific legal review and banner can be copied.
        if ( 1 === $data['schema'] ) {
            $data['mode'] = 'merge';
            $data['enabled'] = 0;
            $data['legal_reviewed'] = 0;
            $data['cookie_policy_pages'] = null;
            return $data;
        }
        if ( ! in_array( $data['mode'] ?? null, array( 'merge', 'replace' ), true ) ) {
            return false;
        }
        foreach ( array( 'enabled', 'legal_reviewed' ) as $key ) {
            if ( ! array_key_exists( $key, $data )
                || ! in_array( $data[ $key ], array( 0, 1, true, false ), true ) ) {
                return false;
            }
        }
        if ( ! isset( $data['cookie_policy_pages'] ) || ! is_array( $data['cookie_policy_pages'] ) ) {
            return false;
        }
        $markets = TaxoCommerce_Insights_Consent::policy_page_defaults();
        if ( count( $data['cookie_policy_pages'] ) !== count( $markets ) ) {
            return false;
        }
        foreach ( $markets as $market => $default_slug ) {
            if ( ! isset( $data['cookie_policy_pages'][ $market ] )
                || ! is_string( $data['cookie_policy_pages'][ $market ] )
                || strlen( $data['cookie_policy_pages'][ $market ] ) > 200 ) {
                return false;
            }
            $slug = $data['cookie_policy_pages'][ $market ];
            if ( '' !== $slug && ! preg_match( '/\A[a-z0-9][a-z0-9-]*\z/D', $slug ) ) {
                return false;
            }
        }
        return $data;
    }

    /**
     * Import content only. Empty cells preserve their current text.
     * The JSON's enabled/legal_reviewed/revision values are never trusted.
     * A live or already legally reviewed banner must be edited via the UI.
     *
     * @return array|false New configuration or false when protected.
     */
    public static function merge_import( $current, $payload, $confirm_site_legal_review = false ) {
        if ( ! is_array( $current ) || ! is_array( $payload ) ) {
            return false;
        }
        $replace = 'replace' === ( $payload['mode'] ?? 'merge' );
        $next = $current;
        $next['texts'] = isset( $next['texts'] ) && is_array( $next['texts'] ) ? $next['texts'] : array();
        foreach ( array_keys( TaxoCommerce_Insights_Consent::languages() ) as $language ) {
            $existing = isset( $next['texts'][ $language ] ) && is_array( $next['texts'][ $language ] )
                ? $next['texts'][ $language ] : TaxoCommerce_Insights_Consent::defaults_for_locale( $language );
            foreach ( array_keys( TaxoCommerce_Insights_Consent::defaults_for_locale( $language ) ) as $field ) {
                $value = $payload['texts'][ $language ][ $field ];
                if ( '' === trim( $value ) && ! $replace ) {
                    continue;
                }
                $sanitized = in_array( $field, array( 'intro', 'legal' ), true )
                    ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
                $existing[ $field ] = $sanitized;
            }
            $next['texts'][ $language ] = $existing;
        }

        $current_pages = isset( $next['cookie_policy_pages'] ) && is_array( $next['cookie_policy_pages'] )
            ? $next['cookie_policy_pages'] : TaxoCommerce_Insights_Consent::policy_page_defaults();
        if ( is_array( $payload['cookie_policy_pages'] ?? null ) ) {
            foreach ( TaxoCommerce_Insights_Consent::policy_page_defaults() as $market => $default_slug ) {
                $slug = $payload['cookie_policy_pages'][ $market ];
                if ( '' === $slug && ! $replace ) {
                    continue;
                }
                $current_pages[ $market ] = sanitize_title( $slug );
            }
        }
        $next['cookie_policy_pages'] = $current_pages;

        $url = $payload['cookie_policy_url'] ?? '';
        if ( '' !== trim( $url ) || $replace ) {
            $safe = '' === trim( $url ) ? '' : esc_url_raw( $url, array( 'https' ) );
            if ( '' !== $safe ) {
                $parts = wp_parse_url( $safe );
                if ( ! is_array( $parts ) || empty( $parts['host'] )
                    || 'https' !== ( $parts['scheme'] ?? '' )
                    || isset( $parts['user'] ) || isset( $parts['pass'] )
                    || ! filter_var( $safe, FILTER_VALIDATE_URL ) ) {
                    return false;
                }
            } elseif ( '' !== trim( $url ) ) {
                return false;
            }
            $next['cookie_policy_url'] = $safe;
        }

        // Explicit admin import restores every switch from JSON. Signing off
        // a legal review on THIS installation also needs an explicit checkbox.
        $next['enabled'] = ! empty( $payload['enabled'] ) ? 1 : 0;
        $next['legal_reviewed'] = ! empty( $payload['legal_reviewed'] ) ? 1 : 0;
        if ( $next['legal_reviewed'] && ! $confirm_site_legal_review ) {
            return false;
        }
        if ( $next['enabled'] ) {
            if ( ! $next['legal_reviewed'] || ! TaxoCommerce_Insights_Consent::policies_ready( $next ) ) {
                return false;
            }
            foreach ( $next['texts'] as $language => $fields ) {
                foreach ( TaxoCommerce_Insights_Consent::defaults_for_locale( $language ) as $field => $default ) {
                    if ( ! isset( $fields[ $field ] ) || '' === trim( (string) $fields[ $field ] ) ) {
                        return false;
                    }
                }
            }
        }
        // Imported revision is informational. The caller increments the
        // destination's revision once if any setting changes, invalidating old
        // affirmative client choices instead of reusing an old cookie version.
        $next['revision'] = (int) ( $current['revision'] ?? 1 );
        return $next;
    }

    private static function import_redirect( $message ) {
        $url = add_query_arg(
            array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'consent',
                'tci_consent_notice' => $message ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }

    /** Save a nonce-verified, size-limited JSON upload to WordPress wp_options. */
    public static function import_json() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( self::IMPORT_NONCE );
        // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is checked above. PHP-generated upload metadata must stay intact for is_uploaded_file(); the filename is sanitized and every JSON field is validated before use.
        $file = isset( $_FILES['tci_consent_json'] ) && is_array( $_FILES['tci_consent_json'] )
            ? $_FILES['tci_consent_json'] : array();
        // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE )
            || ! isset( $file['size'], $file['name'], $file['tmp_name'] )
            || ! is_string( $file['name'] ) || ! is_string( $file['tmp_name'] )
            || (int) $file['size'] < 1 || (int) $file['size'] > self::MAX_IMPORT_BYTES
            || 'json' !== strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) )
            || ! is_uploaded_file( $file['tmp_name'] ) ) {
            self::import_redirect( 'import_invalid' );
        }
        $raw = file_get_contents( $file['tmp_name'], false, null, 0, self::MAX_IMPORT_BYTES + 1 );
        $payload = self::parse_import( $raw );
        if ( false === $payload ) {
            self::import_redirect( 'import_invalid' );
        }
        $previous = get_option( TaxoCommerce_Insights_Consent::OPTION, false );
        $current = TaxoCommerce_Insights_Consent::settings();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The import nonce is verified above.
        $confirm_site_legal_review = ! empty( $_POST['tci_confirm_legal_review'] );
        if ( ! empty( $payload['legal_reviewed'] ) && ! $confirm_site_legal_review ) {
            self::import_redirect( 'import_review_required' );
        }
        $next = self::merge_import( $current, $payload, $confirm_site_legal_review );
        if ( false === $next ) {
            self::import_redirect( 'import_invalid' );
        }
        if ( $next === $current ) {
            self::import_redirect( 'import_unchanged' );
        }
        // Never restore the old JSON's revision, enable flag or legal-approval
        // flag. Force any earlier browser preferences to become stale.
        $next['revision'] = max( 1, (int) ( $current['revision'] ?? 0 ) + 1 );
        // Explicitly restored flags passed the legal-page gate above.
        // This backup is intentionally overwritten at each import. The
        // separately archived legal history continues to keep every revision.
        $backup = array(
            'had_option' => is_array( $previous ),
            'previous' => $previous,
            'saved_at' => current_time( 'mysql', true ),
        );
        $backed_up = update_option( self::IMPORT_BACKUP, $backup, false );
        if ( ! $backed_up && get_option( self::IMPORT_BACKUP ) !== $backup ) {
            self::import_redirect( 'import_failed' );
        }
        TaxoCommerce_Insights_Consent_Ledger::archive( $current );
        $saved = update_option( TaxoCommerce_Insights_Consent::OPTION, $next, false );
        if ( ! $saved && get_option( TaxoCommerce_Insights_Consent::OPTION ) !== $next ) {
            self::import_redirect( 'import_failed' );
        }
        TaxoCommerce_Insights_Consent_Ledger::archive( $next );
        self::import_redirect( 'imported' );
    }

    public static function render_export_link() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION ),
            self::EXPORT_NONCE
        );
        $template_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=' . self::TEMPLATE_ACTION ),
            self::TEMPLATE_NONCE
        );
        echo '<div class="card" style="max-width:1200px;padding:18px;margin-top:16px">';
        echo '<h3>' . esc_html__( 'Importar y exportar consentimientos (JSON)', 'taxocommerce-google-bing' ) . '</h3>';
        echo '<p>' . esc_html__( 'Copia de seguridad completa: textos, enlaces a las cinco páginas por país, casillas, activación y revisión legal. El JSON exportado restaura toda la configuración; la plantilla vacía permite rellenar solo lo necesario.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">'
            . esc_html__( 'Exportar configuración actual (JSON)', 'taxocommerce-google-bing' ) . '</a> ';
        echo '<a class="button button-secondary" href="' . esc_url( $template_url ) . '">'
            . esc_html__( 'Descargar plantilla vacía (JSON)', 'taxocommerce-google-bing' ) . '</a></p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::IMPORT_ACTION ) . '">';
        wp_nonce_field( self::IMPORT_NONCE );
        echo '<p><label for="tci-consent-json"><strong>' .
            esc_html__( 'Archivo JSON de consentimientos (máximo 64 KB)', 'taxocommerce-google-bing' ) .
            '</strong></label><br>';
        echo '<input type="file" id="tci-consent-json" name="tci_consent_json" accept=".json,application/json" required></p>';
        echo '<p><label><input type="checkbox" name="tci_confirm_legal_review" value="1"> ' .
            esc_html__( 'Confirmo que he revisado las políticas legales de esta instalación: permitir restaurar la casilla de revisión legal si figura activada en el JSON.', 'taxocommerce-google-bing' ) .
            '</label></p>';
                submit_button( __( 'Importar JSON y guardar en wp_options', 'taxocommerce-google-bing' ), 'secondary', 'submit', false );
        echo '</form>';
        echo '<p class="description">' .
            esc_html__( 'El JSON completo restaura los campos y casillas; los enlaces usan slugs de páginas locales, portables entre entornos. La importación solo activa el banner si se ha confirmado la revisión legal y están publicadas las cinco políticas del país correcto. Una revisión restaurada exige marcar expresamente la confirmación anterior. Las decisiones de visitantes NO se importan.', 'taxocommerce-google-bing' )
            . '</p>';
        $marker = get_option( self::MARKER, array() );
        if ( is_array( $marker ) && ! empty( $marker['completed'] ) ) {
            echo '<p class="description">' .
                esc_html__( 'Los textos iniciales de cinco idiomas se incorporaron a wp_options desde el JSON versionado. La exportación actual refleja los valores guardados.', 'taxocommerce-google-bing' )
                . '</p>';
        }
        echo '</div>';
    }

}
