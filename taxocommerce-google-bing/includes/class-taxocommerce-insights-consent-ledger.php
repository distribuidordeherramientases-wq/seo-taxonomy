<?php
/** Durable consent evidence independent from browser cookie lifecycle. */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_Consent_Ledger {
    const SCHEMA = 'taxocommerce_insights_ledger_schema';
    const SECRET = 'taxocommerce_insights_ledger_secret';
    const NONCE = 'tci_ledger_manage';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'taxocommerce_consent_events';
    }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collate = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
subject_hash char(64) NOT NULL,
purpose varchar(50) NOT NULL,
decision varchar(12) NOT NULL,
locale varchar(10) NOT NULL,
legal_revision bigint(20) unsigned NOT NULL,
legal_hash char(64) NOT NULL,
source varchar(60) NOT NULL,
created_at datetime NOT NULL,
erased_at datetime DEFAULT NULL,
PRIMARY KEY  (id),
KEY subject_hash (subject_hash),
KEY created_at (created_at),
KEY purpose_date (purpose,created_at)
) {$collate};" );
        if ( ! get_option( self::SECRET ) ) {
            add_option( self::SECRET, wp_generate_password( 64, true, true ), '', false );
        }
        update_option( self::SCHEMA, '1', false );
    }
    public static function register() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
        add_action( 'wp_ajax_tci_consent_record', array( __CLASS__, 'record' ) );
        add_action( 'wp_ajax_nopriv_tci_consent_record', array( __CLASS__, 'record' ) );
        add_action( 'admin_post_tci_ledger_export', array( __CLASS__, 'export' ) );
        add_action( 'admin_post_tci_ledger_erase', array( __CLASS__, 'erase' ) );
    }
    public static function maybe_install() {
        if ( '1' !== get_option( self::SCHEMA ) ) {
            self::install();
        }
    }
    public static function subject_cookie() {
        return 'tci_consent_evidence';
    }
    private static function subject() {
        $token = isset( $_COOKIE[ self::subject_cookie() ] ) && is_string( $_COOKIE[ self::subject_cookie() ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::subject_cookie() ] ) ) : '';
        if ( ! preg_match( '/\\A[a-f0-9]{64}\\z/D', $token ) ) {
            $token = bin2hex( random_bytes( 32 ) );
            setcookie( self::subject_cookie(), $token, array(
                'expires' => time() + YEAR_IN_SECONDS,
                'path' => COOKIEPATH ? COOKIEPATH : '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ) );
        }
        return hash_hmac( 'sha256', $token, (string) get_option( self::SECRET ) );
    }
    public static function legal_hash( $settings ) {
        return hash( 'sha256', wp_json_encode( array(
            'revision' => (int) $settings['revision'],
            'texts' => $settings['texts'],
            'policy_url' => $settings['cookie_policy_url'],
        ) ) );
    }
    public static function archive( $settings ) {
        $hash = self::legal_hash( $settings );
        $history = (array) get_option( 'taxocommerce_insights_legal_history', array() );
        if ( ! isset( $history[ $hash ] ) ) {
            $history[ $hash ] = array( 'revision' => (int) $settings['revision'], 'saved_at' => current_time( 'mysql', true ), 'texts' => $settings['texts'], 'cookie_policy_url' => $settings['cookie_policy_url'] );
            update_option( 'taxocommerce_insights_legal_history', $history, false );
        }
    }
    public static function record() {
        if ( '1' !== get_option( self::SCHEMA ) ) {
            self::install();
        }
        check_ajax_referer( 'tci_consent_record', 'nonce' );
        $settings = TaxoCommerce_Insights_Consent::settings();
        if ( ! TaxoCommerce_Insights_Consent::active() ) {
            wp_send_json_error( array( 'message' => 'inactive' ), 403 );
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked by check_ajax_referer above.
        $revision = isset( $_POST['revision'] ) ? absint( wp_unslash( $_POST['revision'] ) ) : 0;
        $stats = isset( $_POST['statistics'] ) && is_scalar( $_POST['statistics'] ) && 1 === absint( wp_unslash( $_POST['statistics'] ) );
        $marketing = isset( $_POST['marketing'] ) && is_scalar( $_POST['marketing'] ) && 1 === absint( wp_unslash( $_POST['marketing'] ) );
        $preferences = isset( $_POST['preferences'] ) && is_scalar( $_POST['preferences'] ) && 1 === absint( wp_unslash( $_POST['preferences'] ) );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( $revision !== (int) $settings['revision'] ) {
            wp_send_json_error( array( 'message' => 'revision_changed' ), 409 );
        }
        global $wpdb;
        self::archive( $settings );
        $hash = self::subject();
        $legal_hash = self::legal_hash( $settings );
        foreach ( array( 'statistics' => $stats, 'marketing' => $marketing, 'preferences' => $preferences ) as $purpose => $accepted ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Append-only, immediately durable evidence in the plugin-owned SQL ledger.
            $saved = $wpdb->insert( self::table(), array(
                'subject_hash' => $hash,
                'purpose' => $purpose,
                'decision' => $accepted ? 'accepted' : 'rejected',
                'locale' => TaxoCommerce_Insights_Consent::language(),
                'legal_revision' => $revision,
                'legal_hash' => $legal_hash,
                'source' => 'cookie_banner',
                'created_at' => current_time( 'mysql', true ),
            ), array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );
            if ( false === $saved ) {
                wp_send_json_error( array( 'message' => 'database_error' ), 500 );
            }
        }
        wp_send_json_success( array( 'recorded' => true ) );
    }
    private static function filters() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
        $status = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';
        $purpose = isset( $_GET['purpose'] ) ? sanitize_key( wp_unslash( $_GET['purpose'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return array(
            'decision' => in_array( $status, array( 'accepted', 'rejected' ), true ) ? $status : '',
            'purpose' => in_array( $purpose, array( 'statistics', 'marketing', 'preferences' ), true ) ? $purpose : '',
        );
    }
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        global $wpdb;
        self::maybe_install();
        $f = self::filters();
        // The database prefix is provided by WordPress. Escape the known,
        // plugin-owned identifier while retaining WordPress 5.8 compatibility.
        // Every variable filter is still bound through wpdb::prepare().
        $table = esc_sql( self::table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live legal evidence must not use stale caching.
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Escaped plugin-owned table identifier, all values bound below.
            "SELECT COUNT(*) FROM {$table} WHERE (%s = '' OR decision = %s) AND (%s = '' OR purpose = %s)",
            $f['decision'], $f['decision'], $f['purpose'], $f['purpose']
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicitly bounded live evidence results.
        $rows = $wpdb->get_results( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Escaped plugin-owned identifier, all filter values bound.
            "SELECT id,subject_hash,purpose,decision,locale,legal_revision,legal_hash,source,created_at,erased_at FROM {$table} WHERE (%s = '' OR decision = %s) AND (%s = '' OR purpose = %s) ORDER BY id DESC LIMIT 100",
            $f['decision'], $f['decision'], $f['purpose'], $f['purpose']
        ), ARRAY_A );
        echo '<div class="card" style="max-width:1200px;padding:20px;margin-top:18px">';
        echo '<h2>' . esc_html__( 'Inventario de consentimientos', 'taxocommerce-google-bing' ) . '</h2>';
        echo '<p>' . esc_html__( 'Registro histórico de decisiones; no se modifica una decisión pasada. Para rectificar se registra una nueva decisión. La supresión anonimiza el identificador y conserva únicamente evidencia no identificativa.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<form method="get"><input type="hidden" name="page" value="taxocommerce-google-bing"><input type="hidden" name="tgb_tab" value="consent">';
        foreach ( array( 'purpose' => array( '' => 'Todas las finalidades', 'statistics' => 'Estadísticas', 'marketing' => 'Marketing', 'preferences' => 'Preferencias' ), 'decision' => array( '' => 'Todas las decisiones', 'accepted' => 'Aceptado', 'rejected' => 'Rechazado' ) ) as $key => $options ) {
            echo '<select name="' . esc_attr( $key ) . '">';
            foreach ( $options as $value => $label ) {
                echo '<option value="' . esc_attr( $value ) . '" ' . selected( $f[ $key ], $value, false ) . '>' . esc_html( $label ) . '</option>';
            }
            echo '</select> ';
        }
        echo '<button class="button" type="submit">Filtrar</button></form>';
        echo '<p><strong>' . esc_html( (string) $total ) . '</strong> registros; se muestran los últimos 100 filtrados.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tci_ledger_export">';
        wp_nonce_field( self::NONCE );
        echo '<button class="button" type="submit">Exportar inventario CSV (hasta 10.000 registros)</button></form>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Fecha UTC</th><th>Finalidad</th><th>Decisión</th><th>Idioma</th><th>Revisión</th><th>Identificador pseudónimo</th><th>Supresión</th></tr></thead><tbody>';
        foreach ( (array) $rows as $row ) {
            echo '<tr>';
            foreach ( array( 'id', 'created_at', 'purpose', 'decision', 'locale', 'legal_revision', 'subject_hash' ) as $key ) {
                echo '<td>' . esc_html( (string) $row[ $key ] ) . '</td>';
            }
            echo '<td>' . ( $row['erased_at'] ? esc_html( $row['erased_at'] ) : '&mdash;' ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h3>Solicitud de supresión de identificador</h3>';
        echo '<p>Indica el identificador pseudónimo completo obtenido de una exportación o de la consulta. Confirma la petición por un canal verificable antes de actuar.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tci_ledger_erase">';
        wp_nonce_field( self::NONCE );
        echo '<p><input name="subject_hash" class="large-text" required pattern="[a-f0-9]{64}" maxlength="64" placeholder="Identificador pseudónimo de 64 caracteres"></p>';
        echo '<p><label><input type="checkbox" name="confirm" value="1" required> Identidad y fundamento de la solicitud comprobados; confirmo la supresión del vínculo identificativo.</label></p>';
        echo '<button type="submit" class="button button-secondary">Anonimizar identificador</button></form>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status flag.
        if ( isset( $_GET['ledger_erased'] ) ) {
            echo '<p class="notice notice-success">Solicitud de anonimización procesada.</p>';
        }
        echo '</div>';
    }
    public static function erase() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', '', array( 'response' => 403 ) );
        }
        check_admin_referer( self::NONCE );
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified above.
        $hash = isset( $_POST['subject_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['subject_hash'] ) ) : '';
        $confirmed = ! empty( $_POST['confirm'] );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( ! $confirmed || ! preg_match( '/\\A[a-f0-9]{64}\\z/D', $hash ) ) {
            wp_die( 'Invalid deletion request', '', array( 'response' => 400 ) );
        }
        global $wpdb;
        // Remove the subject link but preserve a minimal immutable legal evidence record.
        $table = esc_sql( self::table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immediate anonymization of stored legal evidence.
        $wpdb->query( $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Escaped plugin-owned table; subject values remain bound.
            "UPDATE {$table} SET subject_hash=%s, erased_at=%s WHERE subject_hash=%s AND erased_at IS NULL",
            str_repeat( '0', 64 ),
            current_time( 'mysql', true ),
            $hash
        ) );
        wp_safe_redirect( add_query_arg( array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'consent', 'ledger_erased' => '1' ), admin_url( 'admin.php' ) ) );
        exit;
    }
    public static function export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden', '', array( 'response' => 403 ) );
        }
        check_admin_referer( self::NONCE );
        global $wpdb;
        $table = esc_sql( self::table() );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current, administrator-only export, limited to 10,000 rows.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Escaped fixed, plugin-owned table with no user-derived SQL.
            "SELECT id,subject_hash,purpose,decision,locale,legal_revision,legal_hash,source,created_at,erased_at FROM {$table} ORDER BY id DESC LIMIT 10000",
            ARRAY_A
        );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="insights-consent-evidence-' . gmdate( 'Ymd' ) . '.csv"' );
        $stream = fopen( 'php://output', 'w' );
        fputcsv( $stream, array( 'id', 'subject_hash', 'purpose', 'decision', 'locale', 'legal_revision', 'legal_hash', 'source', 'created_at_utc', 'erased_at_utc' ) );
        foreach ( (array) $rows as $row ) {
            // Prevent spreadsheet formula injection in exported user-associated values.
            fputcsv( $stream, array_map( static function ( $value ) {
                $value = (string) $value;
                return preg_match( '/\\A[=+@\\-\\t\\r]/', $value ) ? "'" . $value : $value;
            }, array_values( $row ) ) );
        }
        // php://output closes automatically at request shutdown.
        exit;
    }
}
