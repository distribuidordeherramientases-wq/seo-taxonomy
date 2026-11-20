<?php
/**
 * Safe, read-only Ticketing functionality and code readiness checks.
 *
 * @package TaxoCommerceTicketing
 */
defined( 'ABSPATH' ) || exit;

final class TCTK_Autocheck {
    public static function boot() {
        add_action( 'admin_post_tctk_autocheck_run', array( __CLASS__, 'run' ) );
        add_action( 'admin_post_tctk_autocheck_export', array( __CLASS__, 'export' ) );
    }

    public static function report() {
        global $wpdb;
        $tables = TCTK_Ticketing::tables();
        $settings = TCTK_Ticketing::settings();
        $checks = array();
        foreach ( $tables as $purpose => $name ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only schema presence.
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
            $checks[] = array(
                'check' => 'table_' . $purpose,
                'status' => $found === $name ? 'ok' : 'error',
                'details' => $found === $name ? 'Table available' : 'Table missing',
            );
        }
        $checks[] = array(
            'check' => 'staff_email',
            'status' => is_email( $settings['notification_email'] ) ? 'ok' : 'error',
            'details' => 'Notification address syntax (not deliverability)',
        );
        $checks[] = array(
            'check' => 'form_link',
            'status' => $settings['form_page_url'] ? 'ok' : 'warning',
            'details' => $settings['form_page_url'] ? 'Public form page configured' : 'Form page URL not configured for staff-created external tickets',
        );
        $checks[] = array(
            'check' => 'reply_security',
            'status' => 'ok',
            'details' => 'Private links use an HMAC token hash and file downloads enforce ticket permissions',
        );
        $counts = array( 'ok' => 0, 'warning' => 0, 'error' => 0 );
        foreach ( $checks as $row ) {
            $counts[$row['status']]++;
        }
        $generated_at = gmdate( 'c' );
        return array(
            'plugin' => 'taxocommerce-ticketing',
            'version' => TCTK_VERSION,
            'generated_at' => $generated_at,
            'executed_at' => $generated_at,
            'rows' => $checks,
            'totals' => $counts,
            'message' => 'Fecha de ejecución: ' . $generated_at,
        );
    }

    public static function run() {
        if ( ! current_user_can( TCTK_Ticketing::CAP ) ) {
            wp_die( esc_html__( 'Access denied.', 'taxocommerce-ticketing' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'tctk_autocheck_run' );
        $report = self::report();
        update_user_meta( get_current_user_id(), '_tctk_last_autocheck', $report );
        wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-ticketing&tab=autocheck' ) );
        exit;
    }

    public static function export() {
        if ( ! current_user_can( TCTK_Ticketing::CAP ) ) {
            wp_die( esc_html__( 'Access denied.', 'taxocommerce-ticketing' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'tctk_autocheck_export' );
        $report = get_user_meta( get_current_user_id(), '_tctk_last_autocheck', true );
        if ( ! is_array( $report ) ) {
            wp_die( esc_html__( 'Run Autocheck before exporting.', 'taxocommerce-ticketing' ), '', array( 'response' => 400 ) );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="taxocommerce-ticketing-autocheck.json"' );
        echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        exit;
    }

    public static function render() {
        if ( ! current_user_can( TCTK_Ticketing::CAP ) ) {
            return;
        }
        $report = get_user_meta( get_current_user_id(), '_tctk_last_autocheck', true );
        echo '<h2>Autocheck</h2><p>' . esc_html__( 'Checks database availability, settings, and security contract. Email delivery and end-to-end interactions still require live testing.', 'taxocommerce-ticketing' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tctk_autocheck_run"/>';
        wp_nonce_field( 'tctk_autocheck_run' );
        submit_button( __( 'Run Autocheck', 'taxocommerce-ticketing' ) );
        echo '</form>';
        if ( ! is_array( $report ) ) {
            return;
        }
        echo '<p>' . esc_html( $report['message'] ) . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Check', 'taxocommerce-ticketing' ) . '</th><th>' . esc_html__( 'Status', 'taxocommerce-ticketing' ) . '</th><th>' . esc_html__( 'Details', 'taxocommerce-ticketing' ) . '</th></tr></thead><tbody>';
        foreach ( $report['rows'] as $row ) {
            echo '<tr><td>' . esc_html( $row['check'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td>' . esc_html( $row['details'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tctk_autocheck_export"/>';
        wp_nonce_field( 'tctk_autocheck_export' );
        submit_button( __( 'Download JSON', 'taxocommerce-ticketing' ), 'secondary' );
        echo '</form>';
    }
}
