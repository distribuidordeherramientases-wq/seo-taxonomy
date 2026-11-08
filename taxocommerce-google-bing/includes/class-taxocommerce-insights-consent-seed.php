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

    private static function seed_path() {
        return dirname( __DIR__ ) . '/config/consent-texts-20261108.json';
    }

    public static function register() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ), 20 );
        add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export_json' ) );
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
        $data = array_merge( array(
            'schema'      => 1,
            'option'      => TaxoCommerce_Insights_Consent::OPTION,
            'exported_at' => gmdate( 'c' ),
        ), $settings );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="taxocommerce-insights-consent-' . gmdate( 'Ymd-His' ) . '.json"' );
        echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    public static function render_export_link() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION ),
            self::EXPORT_NONCE
        );
        echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">'
            . esc_html__( 'Exportar configuración actual (JSON)', 'taxocommerce-google-bing' )
            . '</a></p>';
        $marker = get_option( self::MARKER, array() );
        if ( is_array( $marker ) && ! empty( $marker['completed'] ) ) {
            echo '<p class="description">' .
                esc_html__( 'Se han incorporado los textos de consentimiento ES/EN/FR/DE/NL a wp_options sin activar el banner ni aprobar la revisión legal. El archivo JSON exportado refleja el estado guardado.', 'taxocommerce-google-bing' )
                . '</p>';
        }
    }
}
