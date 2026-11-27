<?php
/**
 * Read-only functionality and code checks. Does not connect to mail
 * servers or change messages unless administrator separately selects Test IMAP.
 *
 * @package TaxoCommerceMail
 */
defined( 'ABSPATH' ) || exit;
final class TCMAIL_Autocheck {
    public static function boot() {
        add_action( 'admin_post_tcmail_autocheck', array( __CLASS__, 'run' ) );
        add_action( 'admin_post_tcmail_autocheck_export', array( __CLASS__, 'export' ) );
    }

    private static function report() {
        $rows = array();
        $add = static function ( $name, $ok, $detail ) use ( &$rows ) {
            $rows[] = array( 'check' => $name, 'status' => $ok ? 'ok' : 'error', 'details' => $detail );
        };
        $add( 'encrypted_credentials', TCMAIL_Accounts::ready(), 'AES-256-GCM available for account secrets.' );
        $add( 'imap_transport', TCMAIL_IMAP::available(), 'PHP OpenSSL and secure socket functions available.' );
        $add( 'account_configuration', count( TCMAIL_Accounts::public_list() ) > 0, 'At least one mailbox is configured.' );
        $add( 'wp_mail_transport_isolated', class_exists( 'TCMAIL_SMTP' ), 'Plugin SMTP uses a new PHPMailer instance; does not modify wp_mail().' );
        $rows[] = array(
            'check' => 'live_imap_and_smtp',
            'status' => 'warning',
            'details' => 'No remote connections or emails were sent by Autocheck. Use Test IMAP per mailbox and send one controlled SMTP email.',
        );
        $totals = array( 'ok' => 0, 'warning' => 0, 'error' => 0 );
        foreach ( $rows as $item ) {
            $totals[ $item['status'] ]++;
        }
        return array(
            'plugin' => 'taxocommerce-mail',
            'version' => TCMAIL_VERSION,
            'type' => 'functionality',
            'executed_at' => gmdate( 'c' ),
            'rows' => $rows,
            'totals' => $totals,
            'remote_connections_tested' => false,
            'outbound_messages_sent' => false,
        );
    }

    public static function run() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'taxocommerce-mail-lite' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'tcmail_autocheck' );
        $mode = 'functionality';
        update_user_meta( get_current_user_id(), '_tcmail_check_' . $mode, self::report() );
        wp_safe_redirect( add_query_arg( array( 'page' => 'taxocommerce-mail', 'tab' => 'autocheck', 'check_mode' => $mode ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'taxocommerce-mail-lite' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( 'tcmail_autocheck_export' );
        $mode = 'functionality';
        $report = get_user_meta( get_current_user_id(), '_tcmail_check_' . $mode, true );
        if ( ! is_array( $report ) ) {
            wp_die( esc_html__( 'Run the check before exporting.', 'taxocommerce-mail-lite' ), '', array( 'response' => 400 ) );
        }
        $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) ) {
            wp_die( esc_html__( 'Unable to create JSON.', 'taxocommerce-mail-lite' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="taxocommerce-mail-' . $mode . '-check.json"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw JSON download.
        echo $json;
        exit;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $mode = 'functionality';
        echo '<h2>' . esc_html__( 'Autocheck', 'taxocommerce-mail-lite' ) . '</h2>';
        echo '<p>' . esc_html__( 'Checks do not access your inbox or send messages. Each connection must be tested separately.', 'taxocommerce-mail-lite' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tcmail_autocheck"><input type="hidden" name="check_mode" value="' . esc_attr( $mode ) . '">';
        wp_nonce_field( 'tcmail_autocheck' );
        submit_button( __( 'Run Functionality Check', 'taxocommerce-mail-lite' ) );
        echo '</form>';
        $report = get_user_meta( get_current_user_id(), '_tcmail_check_' . $mode, true );
        if ( ! is_array( $report ) ) {
            return;
        }
        echo '<p>' . esc_html__( 'Last executed', 'taxocommerce-mail-lite' ) . ': ' . esc_html( $report['executed_at'] ) . '</p>';
        echo '<p><strong>' . esc_html( 'OK ' . (int) $report['totals']['ok'] . ' / WARN ' . (int) $report['totals']['warning'] . ' / ERROR ' . (int) $report['totals']['error'] ) . '</strong></p>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Check', 'taxocommerce-mail-lite' ) . '</th><th>' . esc_html__( 'Status', 'taxocommerce-mail-lite' ) . '</th><th>' . esc_html__( 'Details', 'taxocommerce-mail-lite' ) . '</th></tr></thead><tbody>';
        foreach ( $report['rows'] as $row ) {
            echo '<tr><td>' . esc_html( $row['check'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td>' . esc_html( $row['details'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tcmail_autocheck_export"><input type="hidden" name="check_mode" value="' . esc_attr( $mode ) . '">';
        wp_nonce_field( 'tcmail_autocheck_export' );
        submit_button( __( 'Download JSON report', 'taxocommerce-mail-lite' ), 'secondary' );
        echo '</form>';
    }

    public static function manual() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div style="max-width:920px"><h2>' . esc_html( __( 'User manual', 'taxocommerce-mail-lite' ) ) . '</h2>';
        $sections = array(
            array( __( 'Add accounts', 'taxocommerce-mail-lite' ), __( 'Create the email address at your hosting provider, then open Mail > Accounts in WordPress. Enter only the complete email address as username, masked password and secure IMAP hostname. Additional accounts can be configured separately. SMTP name, host and encrypted port are optional advanced settings for sending.', 'taxocommerce-mail-lite' ) ),
            array( __( 'Hosting provider settings', 'taxocommerce-mail-lite' ), __( 'In your hosting control panel, find email client connection details. Use the exact SSL/TLS host and ports provided: IMAP 993, SMTP 465 or STARTTLS 587.', 'taxocommerce-mail-lite' ) ),
            array( __( 'Read mail', 'taxocommerce-mail-lite' ), __( 'Select Mail > Inbox, choose a configured account and open a message. The plugin reads up to 12 recent messages from INBOX without marking them read. Messages remain on the mail server.', 'taxocommerce-mail-lite' ) ),
            array( __( 'Send and reply', 'taxocommerce-mail-lite' ), __( 'Use Compose to send plain-text mail from the selected account. Reply prefills the sender and subject. One recipient per message; no attachments in this initial version.', 'taxocommerce-mail-lite' ) ),
            array( __( 'Check accounts', 'taxocommerce-mail-lite' ), __( 'Use Test IMAP per account to verify inbound access. Send a test SMTP message to an address you control and verify it arrived. Autocheck alone cannot confirm external delivery.', 'taxocommerce-mail-lite' ) ),
            array( __( 'Lite and Pro editions', 'taxocommerce-mail-lite' ), __( 'Mail Lite is the free standalone edition. It can configure multiple accounts, read the 12 newest inbox messages, compose and reply; it does not provide folders, a Sent view or message deletion. Pro is a future separate add-on, not required to use Lite.', 'taxocommerce-mail-lite' ) ),
        );
        foreach ( $sections as $section ) {
            echo '<section class="card" style="max-width:880px;margin:14px 0;padding:15px"><h3>' . esc_html( $section[0] ) . '</h3><p>' . esc_html( $section[1] ) . '</p></section>';
        }
        echo '</div>';
    }
}
