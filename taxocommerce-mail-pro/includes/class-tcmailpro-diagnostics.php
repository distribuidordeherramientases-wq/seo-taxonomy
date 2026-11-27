<?php
/**
 * Administrator-only account diagnostics; never store passwords or raw server replies.
 *
 * @package TaxoCommerceMailPro
 */
defined( 'ABSPATH' ) || exit;
final class TCMAILPRO_Diagnostics {
    const OPTION = 'tcmailpro_imap_diagnostics';

    private static function messages() {
        return array(
            'accounts_present' => __( 'Mailboxes are configured.', 'taxocommerce-mail-pro' ),
            'no_accounts' => __( 'No mailbox is configured. Add a mailbox first.', 'taxocommerce-mail-pro' ),
            'configuration_ok' => __( 'Local account settings and encrypted credentials are valid.', 'taxocommerce-mail-pro' ),
            'invalid_configuration' => __( 'Invalid account settings. Verify the email address, username, server names and SMTP port (465 or 587). IMAP uses port 993.', 'taxocommerce-mail-pro' ),
            'invalid_credentials' => __( 'The saved credentials are missing or cannot be used. Check the username and password.', 'taxocommerce-mail-pro' ),
            'decrypt_failed' => __( 'The stored password cannot be decrypted. Re-enter the mailbox password.', 'taxocommerce-mail-pro' ),
            'invalid_host' => __( 'The IMAP server name is invalid.', 'taxocommerce-mail-pro' ),
            'imap_unavailable' => __( 'The hosting PHP environment lacks the required secure socket or OpenSSL support.', 'taxocommerce-mail-pro' ),
            'imap_dns_failed' => __( 'The IMAP server name could not be resolved. Check DNS and the configured hostname.', 'taxocommerce-mail-pro' ),
            'imap_tcp_failed' => __( 'Could not connect to IMAP port 993. Check the server, firewall and hosting network restrictions.', 'taxocommerce-mail-pro' ),
            'tls_connection_failed' => __( 'TLS negotiation or certificate verification failed. Check the secure hostname and certificate.', 'taxocommerce-mail-pro' ),
            'imap_greeting_failed' => __( 'The server did not return a valid IMAP greeting.', 'taxocommerce-mail-pro' ),
            'imap_auth_rejected' => __( 'The server rejected authentication. Check the mailbox username, password and server access policy; the server may not distinguish them.', 'taxocommerce-mail-pro' ),
            'imap_auth_policy' => __( 'The server rejected authentication due to an access or security policy.', 'taxocommerce-mail-pro' ),
            'imap_protocol_rejected' => __( 'The server rejected an IMAP command as invalid. Protocol compatibility or a plugin defect needs investigation.', 'taxocommerce-mail-pro' ),
            'imap_inbox_rejected' => __( 'Authentication succeeded but access to INBOX was rejected.', 'taxocommerce-mail-pro' ),
            'imap_timeout' => __( 'The server did not respond within the connection time limit.', 'taxocommerce-mail-pro' ),
            'imap_read_failed' => __( 'The IMAP connection closed or a response could not be read.', 'taxocommerce-mail-pro' ),
            'imap_write_failed' => __( 'The IMAP command could not be transmitted completely.', 'taxocommerce-mail-pro' ),
            'imap_disconnected' => __( 'The IMAP connection is unavailable.', 'taxocommerce-mail-pro' ),
            'imap_response_too_large' => __( 'The server response exceeded the safe diagnostic limit.', 'taxocommerce-mail-pro' ),
            'imap_message_too_large' => __( 'An unexpected server response exceeded the safe limit.', 'taxocommerce-mail-pro' ),
            'imap_literal_read_failed' => __( 'The server response could not be read completely.', 'taxocommerce-mail-pro' ),
            'internal_error' => __( 'An unexpected internal error occurred. Review the plugin code and hosting PHP environment.', 'taxocommerce-mail-pro' ),
            'imap_ok' => __( 'Secure IMAP connection, authentication and INBOX access succeeded. No emails were read or sent.', 'taxocommerce-mail-pro' ),
            'imap_not_tested' => __( 'IMAP has not been tested for this configuration.', 'taxocommerce-mail-pro' ),
            'smtp_not_tested' => __( 'SMTP authentication and delivery have not been tested. No email was sent.', 'taxocommerce-mail-pro' ),
        );
    }

    public static function known( $code ) {
        return isset( self::messages()[ $code ] );
    }

    public static function message( $code ) {
        $messages = self::messages();
        return isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['internal_error'];
    }

    public static function local_code( $account ) {
        if ( ! TCMAILPRO_Accounts::validate( $account ) || ! is_email( $account['username'] ) ) {
            return 'invalid_configuration';
        }
        if ( ! TCMAILPRO_Accounts::credentials( $account['id'] ) ) {
            return 'decrypt_failed';
        }
        return 'configuration_ok';
    }

    private static function saved() {
        $data = get_option( self::OPTION, array() );
        return is_array( $data ) ? $data : array();
    }

    public static function clear( $id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $data = self::saved();
        unset( $data[ $id ] );
        update_option( self::OPTION, $data, false );
    }

    public static function last( $id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return null;
        }
        $data = self::saved();
        return isset( $data[ $id ] ) ? $data[ $id ] : null;
    }

    public static function test( $id ) {
        $result = array( 'code' => 'invalid_configuration', 'status' => 'error', 'stage' => 'configuration', 'attempted' => false, 'tested_at' => gmdate( 'c' ) );
        if ( ! current_user_can( 'manage_options' ) ) {
            return $result;
        }
        foreach ( TCMAILPRO_Accounts::public_list() as $account ) {
            if ( $account['id'] !== $id ) {
                continue;
            }
            $code = self::local_code( $account );
            if ( 'configuration_ok' === $code ) {
                $imap = new TCMAILPRO_IMAP();
                try {
                    $result['attempted'] = true;
                    $imap->connect( TCMAILPRO_Accounts::credentials( $id ) );
                    $code = 'imap_ok';
                } catch ( RuntimeException $error ) {
                    $code = self::known( $error->getMessage() ) ? $error->getMessage() : 'internal_error';
                } catch ( Throwable $error ) {
                    $code = 'internal_error';
                } finally {
                    $result['stage'] = $imap->connection_stage();
                    $imap->disconnect();
                }
            }
            $result['code'] = $code;
            $result['status'] = 'imap_ok' === $code ? 'ok' : 'error';
            $data = self::saved();
            $data[ $id ] = $result;
            update_option( self::OPTION, $data, false );
            break;
        }
        return $result;
    }
}
