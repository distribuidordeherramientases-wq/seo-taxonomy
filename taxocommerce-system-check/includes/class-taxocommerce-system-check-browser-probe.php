<?php
/**
 * Measures public site response from the administrator's browser.
 *
 * No requests to the local public URL originate in this PHP class. The browser
 * is the probe runner; WordPress only records authenticated measurements.
 *
 * @package TaxoCommerceSystemCheck
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_System_Check_Browser_Probe {
    const OPTION = 'tcs_browser_probe_history';
    const ACTION = 'taxocommerce_system_check_browser_probe';
    const MAX_SAMPLES = 300;

    public static function boot() {
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'receive' ) );
    }

    public static function enqueue( $hook ) {
        if ( false === strpos( (string) $hook, 'taxocommerce-system-check' )
            && false === strpos( (string) $hook, 'tcs-system-status' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- UI tab selection is read-only.
        $tab = isset( $_GET['tcs_tab'] ) ? sanitize_key( wp_unslash( $_GET['tcs_tab'] ) ) : 'system';
        if ( 'system' !== $tab ) {
            return;
        }
        wp_enqueue_script(
            'tcs-browser-probe',
            TAXOCOMMERCE_SYSTEM_CHECK_URL . 'assets/browser-probe.js',
            array(),
            TAXOCOMMERCE_SYSTEM_CHECK_VERSION,
            true
        );
        wp_add_inline_script(
            'tcs-browser-probe',
            'window.tcsBrowserProbe = ' . wp_json_encode(
                array(
                    'target' => home_url( '/' ),
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'nonce' => wp_create_nonce( 'tcs_browser_probe' ),
                    'action' => self::ACTION,
                    'timeoutMs' => 15000,
                )
            ) . ';',
            'before'
        );
    }

    public static function samples() {
        $rows = get_option( self::OPTION, array() );
        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Only an authenticated administrator can submit a browser measurement.
     * No arbitrary target URL is accepted: this cannot be abused as an SSRF API.
     */
    public static function receive() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permisos insuficientes.' ), 403 );
        }
        check_ajax_referer( 'tcs_browser_probe', 'nonce' );
        $status = isset( $_POST['status'] ) ? absint( wp_unslash( $_POST['status'] ) ) : 0;
        $duration = isset( $_POST['duration_ms'] ) ? wp_unslash( $_POST['duration_ms'] ) : '';
        $ttfb = isset( $_POST['ttfb_ms'] ) ? wp_unslash( $_POST['ttfb_ms'] ) : '';
        $redirected = ! empty( $_POST['redirected'] ) && '1' === (string) wp_unslash( $_POST['redirected'] );
        $error = isset( $_POST['error'] ) ? sanitize_text_field( wp_unslash( $_POST['error'] ) ) : '';
        if ( $status > 599 || ( 0 !== $status && $status < 100 )
            || ! is_scalar( $duration ) || ! is_numeric( $duration ) || ! is_finite( (float) $duration )
            || (float) $duration < 0 || (float) $duration > 60000
            || ! is_scalar( $ttfb ) || ! is_numeric( $ttfb ) || ! is_finite( (float) $ttfb )
            || (float) $ttfb < 0 || (float) $ttfb > (float) $duration ) {
            wp_send_json_error( array( 'message' => 'Medición no válida.' ), 400 );
        }
        $severity = 'ok';
        if ( 0 === $status || $status >= 500 ) {
            $severity = 'error';
        } elseif ( $status >= 400 || $redirected || (float) $duration >= 3000 ) {
            $severity = 'important';
        } elseif ( (float) $duration >= 1500 ) {
            $severity = 'warning';
        }
        $sample = array(
            'timestamp' => time(),
            'http_code' => $status,
            'response_ms' => round( (float) $duration, 1 ),
            'ttfb_ms' => round( (float) $ttfb, 1 ),
            'redirected' => $redirected,
            'status' => $severity,
            'source' => 'admin_browser',
            'error' => mb_substr( $error, 0, 180 ),
        );
        $history = self::samples();
        $history[] = $sample;
        $cutoff = time() - 30 * DAY_IN_SECONDS;
        $history = array_values( array_filter( $history, static function ( $row ) use ( $cutoff ) {
            return is_array( $row ) && (int) ( $row['timestamp'] ?? 0 ) >= $cutoff;
        } ) );
        update_option( self::OPTION, array_slice( $history, -self::MAX_SAMPLES ), false );
        wp_send_json_success( array(
            'status' => $severity,
            'sample' => $sample,
            'count' => count( $history ),
        ) );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $history = self::samples();
        $recent = array_reverse( array_slice( $history, -10 ) );
        $latest = $recent ? $recent[0] : array();
        echo '<section class="tcs-browser-probe" data-tcs-browser-probe>';
        echo '<h2>Disponibilidad pública · comprobación desde tu navegador</h2>';
        echo '<p>La petición GET sale de este navegador hasta la URL pública de la web, como visitante sin cookies. No depende de GitHub y <strong>no es una petición desde PHP al propio servidor</strong>.</p>';
        echo '<p><button type="button" class="button button-primary" data-tcs-probe-run>Comprobar respuesta HTTP</button> ';
        echo '<span data-tcs-probe-status role="status" aria-live="polite">Preparado para comprobar.</span></p>';
        echo '<div class="tcs-browser-probe-result" data-tcs-probe-result>';
        if ( $latest ) {
            echo '<strong>Última muestra guardada:</strong> HTTP ' . esc_html( (string) $latest['http_code'] )
                . ' · ' . esc_html( (string) $latest['response_ms'] ) . ' ms'
                . ' · ' . esc_html( date_i18n( 'Y-m-d H:i', (int) $latest['timestamp'] ) );
        } else {
            echo 'Todavía no hay muestras.';
        }
        echo '</div>';
        echo '<p class="description">Modo cliente externo asistido: solo mide mientras un administrador tenga la pantalla abierta. '
            . 'No proporciona vigilancia automática si la web deja de responder o nadie tiene abierta esta página. '
            . 'La latencia incluye la conexión del propio navegador; no es una medición independiente de otro centro de datos.</p>';
        if ( $recent ) {
            echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Fecha</th><th>HTTP</th><th>Total</th><th>Hasta cabeceras</th><th>Resultado</th></tr></thead><tbody>';
            foreach ( $recent as $row ) {
                echo '<tr><td>' . esc_html( date_i18n( 'Y-m-d H:i:s', (int) $row['timestamp'] ) ) . '</td>';
                echo '<td>' . esc_html( (string) $row['http_code'] ) . '</td>';
                echo '<td>' . esc_html( (string) $row['response_ms'] ) . ' ms</td>';
                echo '<td>' . esc_html( (string) $row['ttfb_ms'] ) . ' ms</td>';
                echo '<td>' . esc_html( (string) $row['status'] );
                if ( ! empty( $row['error'] ) ) {
                    echo ' · ' . esc_html( $row['error'] );
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
        echo '<style>.tcs-browser-probe{padding:18px 20px;margin:16px 0;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;border-radius:5px;max-width:1300px}.tcs-browser-probe h2{margin-top:0}.tcs-browser-probe-result{margin:12px 0;font-size:15px}body:has([data-tcs-browser-probe]) .seo-server-status-wrap .seo-response-monitor{display:none!important}</style>';
    }
}
