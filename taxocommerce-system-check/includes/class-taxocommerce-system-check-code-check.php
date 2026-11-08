<?php
/**
 * TaxoCommerce System Check: bounded self-only source code diagnostics.
 *
 * No dependency on WordPress Plugin Check, CLI tools or other plugins.
 * This scanner checks only files inside its own installed plugin directory.
 *
 * @package TaxoCommerceSystemCheck
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_System_Check_Code_Check {
    const ACTION = 'taxocommerce_system_check_code_check';
    const EXPORT_ACTION = 'taxocommerce_system_check_code_check_json';
    const TTL = HOUR_IN_SECONDS;

    public static function speeds() {
        return array(
            1 => array( 'batch' => 1, 'delay' => 5000, 'label' => __( 'Very low', 'taxocommerce-system-check' ) ),
            2 => array( 'batch' => 1, 'delay' => 3000, 'label' => __( 'Low', 'taxocommerce-system-check' ) ),
            3 => array( 'batch' => 2, 'delay' => 2000, 'label' => __( 'Normal', 'taxocommerce-system-check' ) ),
            4 => array( 'batch' => 3, 'delay' => 1250, 'label' => __( 'High', 'taxocommerce-system-check' ) ),
            5 => array( 'batch' => 4, 'delay' => 800, 'label' => __( 'Maximum', 'taxocommerce-system-check' ) ),
        );
    }

    public static function boot() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
        add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export' ) );
    }

    private static function state_key() {
        return 'tcs_code_check_' . get_current_blog_id() . '_' . get_current_user_id();
    }

    private static function state() {
        $value = get_transient( self::state_key() );
        return is_array( $value ) && isset( $value['files'], $value['results'], $value['cursor'], $value['status'] )
            ? $value : false;
    }

    /**
     * Enumerate only System Check's own source, never its neighbour plugins.
     * Ignore symlinks and limit the number of entries to bound directory work.
     */
    public static function files() {
        $root = realpath( TAXOCOMMERCE_SYSTEM_CHECK_PATH );
        if ( false === $root || ! is_dir( $root ) ) {
            return array();
        }
        $relative = array();
        $directory = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
        $iterator = new RecursiveIteratorIterator( $directory );
        foreach ( $iterator as $entry ) {
            if ( count( $relative ) >= 300 ) {
                break;
            }
            if ( $entry->isLink() || ! $entry->isFile() || 'php' !== strtolower( $entry->getExtension() ) ) {
                continue;
            }
            $path = realpath( $entry->getPathname() );
            if ( false === $path || 0 !== strpos( $path, $root . DIRECTORY_SEPARATOR ) ) {
                continue;
            }
            $relative[] = str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( $root ) + 1 ) );
        }
        sort( $relative, SORT_STRING );
        return $relative;
    }

    private static function finding( $status, $message, $evidence = array() ) {
        return array(
            'status'   => $status,
            'message'  => $message,
            'evidence' => $evidence,
        );
    }

    /**
     * Native PHP parser checks syntax without executing or spawning PHP.
     * Valid only for the current server PHP version, not every older PHP.
     */
    public static function php_check( $source ) {
        if ( ! defined( 'TOKEN_PARSE' ) ) {
            return self::finding( 'skip', __( 'PHP parser mode is not available on this server.', 'taxocommerce-system-check' ) );
        }
        try {
            token_get_all( $source, TOKEN_PARSE );
        } catch ( ParseError $error ) {
            return self::finding(
                'fail',
                __( 'PHP syntax error detected by the local parser.', 'taxocommerce-system-check' ),
                array( 'line' => absint( $error->getLine() ), 'php_version' => PHP_VERSION )
            );
        }
        return self::finding(
            'pass',
            __( 'PHP syntax accepted by this server version. Older versions are not tested.', 'taxocommerce-system-check' ),
            array( 'php_version' => PHP_VERSION, 'compatibility_tested' => false )
        );
    }

    /**
     * Focused WordPress structure conventions, not official Plugin Check.
     * Never treat nonce/sanitization/escaping as verified from regex matching.
     */
    public static function wordpress_check( $source, $relative ) {
        $checks = array();
        $checks['ABSPATH guard'] = (bool) preg_match(
            '/(?:defined\s*\(\s*[\x27\x22]ABSPATH[\x27\x22]\s*\)|\bABSPATH\b)/',
            $source
        );
        if ( 'taxocommerce-system-check.php' === $relative ) {
            foreach ( array( 'Plugin Name', 'Version', 'Text Domain', 'Requires at least', 'Requires PHP', 'License' ) as $field ) {
                $checks[ $field ] = (bool) preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*\S+/mi', $source );
            }
            $checks['Text Domain match'] = (bool) preg_match(
                '/^\s*\*\s*Text Domain:\s*taxocommerce-system-check\s*$/mi',
                $source
            );
            $checks['readme.txt'] = is_readable( TAXOCOMMERCE_SYSTEM_CHECK_PATH . 'readme.txt' );
        }
        $missing = array();
        foreach ( $checks as $label => $ok ) {
            if ( ! $ok ) {
                $missing[] = $label;
            }
        }
        return self::finding(
            $missing ? 'warning' : 'pass',
            $missing
                ? __( 'Some WordPress structure conventions need review.', 'taxocommerce-system-check' )
                : __( 'The inspected WordPress structure conventions are present.', 'taxocommerce-system-check' ),
            array(
                'checks' => $checks,
                'missing' => $missing,
                'not_verified' => array( 'nonce_flow', 'authorization_flow', 'sanitization', 'output_escaping', 'wordpress_org_full_rules' ),
            )
        );
    }

    /**
     * Detect direct use of dangerous PHP call tokens, excluding comments and
     * text literals. Findings require human review; no code is executed.
     */
    public static function security_check( $source ) {
        $tokens = token_get_all( $source );
        $bad = array();
        $names = array( 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'unserialize' );
        $count = count( $tokens );
        for ( $i = 0; $i < $count; ++$i ) {
            $token = $tokens[ $i ];
            if ( ! is_array( $token ) ) {
                continue;
            }
            if ( defined( 'T_EVAL' ) && T_EVAL === $token[0] ) {
                $bad[] = array( 'function' => 'eval', 'line' => $token[2] );
                continue;
            }
            if ( T_STRING !== $token[0] || ! in_array( strtolower( $token[1] ), $names, true ) ) {
                continue;
            }
            $next = $i + 1;
            while ( $next < $count && is_array( $tokens[ $next ] )
                && in_array( $tokens[ $next ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
                ++$next;
            }
            if ( $next >= $count || '(' !== $tokens[ $next ] ) {
                continue;
            }
            // Ignore method calls; a token name alone does not prove a PHP function.
            $previous = $i - 1;
            while ( $previous >= 0 && is_array( $tokens[ $previous ] )
                && in_array( $tokens[ $previous ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
                --$previous;
            }
            if ( $previous >= 0 && is_array( $tokens[ $previous ] )
                && in_array( $tokens[ $previous ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
                continue;
            }
            $bad[] = array( 'function' => strtolower( $token[1] ), 'line' => $token[2] );
        }
        return self::finding(
            $bad ? 'warning' : 'pass',
            $bad
                ? __( 'Potentially dangerous function calls need manual review.', 'taxocommerce-system-check' )
                : __( 'No blocked function calls found; this does not certify security.', 'taxocommerce-system-check' ),
            array(
                'calls' => $bad,
                'not_verified' => array( 'injection_analysis', 'nonce_flow', 'capability_checks', 'escaping', 'dependency_vulnerabilities' ),
            )
        );
    }

    public static function check_file( $relative ) {
        $path = realpath( TAXOCOMMERCE_SYSTEM_CHECK_PATH . $relative );
        $root = realpath( TAXOCOMMERCE_SYSTEM_CHECK_PATH );
        if ( false === $root || false === $path
            || 0 !== strpos( $path, $root . DIRECTORY_SEPARATOR )
            || ! is_file( $path ) || ! is_readable( $path ) || 'php' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
            return array(
                'php' => self::finding( 'skip', __( 'File unavailable or outside System Check.', 'taxocommerce-system-check' ) ),
                'wordpress' => self::finding( 'skip', __( 'Not checked.', 'taxocommerce-system-check' ) ),
                'security' => self::finding( 'skip', __( 'Not checked.', 'taxocommerce-system-check' ) ),
            );
        }
        $length = filesize( $path );
        if ( false === $length || $length > 1048576 ) {
            $skip = self::finding( 'skip', __( 'File exceeds the 1 MB read limit.', 'taxocommerce-system-check' ) );
            return array( 'php' => $skip, 'wordpress' => $skip, 'security' => $skip );
        }
        $source = file_get_contents( $path );
        if ( ! is_string( $source ) ) {
            $skip = self::finding( 'skip', __( 'Unable to read the file.', 'taxocommerce-system-check' ) );
            return array( 'php' => $skip, 'wordpress' => $skip, 'security' => $skip );
        }
        return array(
            'php' => self::php_check( $source ),
            'wordpress' => self::wordpress_check( $source, $relative ),
            'security' => self::security_check( $source ),
        );
    }

    public static function process( $state ) {
        if ( 'running' !== $state['status'] ) {
            return $state;
        }
        $speeds = self::speeds();
        $speed = isset( $speeds[ $state['speed'] ] ) ? $state['speed'] : 3;
        $batch = $speeds[ $speed ]['batch'];
        $total = count( $state['files'] );
        for ( $i = 0; $i < $batch && $state['cursor'] < $total; ++$i ) {
            $file = $state['files'][ $state['cursor'] ];
            try {
                $state['results'][ $file ] = self::check_file( $file );
            } catch ( Throwable $error ) {
                $state['results'][ $file ] = array(
                    'php' => self::finding( 'warning', __( 'Unable to complete the PHP check.', 'taxocommerce-system-check' ) ),
                    'wordpress' => self::finding( 'skip', __( 'Not checked.', 'taxocommerce-system-check' ) ),
                    'security' => self::finding( 'skip', __( 'Not checked.', 'taxocommerce-system-check' ) ),
                );
            }
            ++$state['cursor'];
        }
        if ( $state['cursor'] >= $total ) {
            $state['status'] = 'completed';
        }
        $state['updated_at'] = gmdate( 'c' );
        return $state;
    }

    public static function handle() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'taxocommerce-system-check' ) );
        }
        check_admin_referer( self::ACTION );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
        $operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] )
            ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
        $state = self::state();
        if ( 'start' === $operation ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
            $requested = isset( $_POST['speed'] ) && is_scalar( $_POST['speed'] )
                ? absint( wp_unslash( $_POST['speed'] ) ) : 3;
            $speeds = self::speeds();
            $speed = isset( $speeds[ $requested ] ) ? $requested : 3;
            $files = self::files();
            $state = array(
                'schema' => 1,
                'plugin' => 'taxocommerce-system-check',
                'version' => TAXOCOMMERCE_SYSTEM_CHECK_VERSION,
                'started_at' => gmdate( 'c' ),
                'updated_at' => gmdate( 'c' ),
                'status' => $files ? 'running' : 'completed',
                'speed' => $speed,
                'files' => $files,
                'cursor' => 0,
                'results' => array(),
                'checks' => array( 'php_parser', 'wordpress_structure', 'security_patterns' ),
                'official_plugin_check' => 'not_executed',
            );
        } elseif ( is_array( $state ) && 'stop' === $operation ) {
            $state['status'] = 'paused';
        } elseif ( is_array( $state ) && 'resume' === $operation && 'paused' === $state['status'] ) {
            $state['status'] = 'running';
        } elseif ( is_array( $state ) && 'step' === $operation ) {
            $state = self::process( $state );
        }
        if ( is_array( $state ) ) {
            set_transient( self::state_key(), $state, self::TTL );
        }
        wp_safe_redirect( taxocommerce_system_check_tab_url( 'code-check' ) );
        exit;
    }

    /** Count actual inspected results; skipped and review are never counted as passed. */
    public static function summary( $results ) {
        $counts = array();
        foreach ( array( 'php', 'wordpress', 'security' ) as $category ) {
            $counts[ $category ] = array( 'pass' => 0, 'fail' => 0, 'warning' => 0, 'skip' => 0 );
        }
        foreach ( $results as $result ) {
            foreach ( $counts as $category => $statuses ) {
                $status = isset( $result[ $category ]['status'] ) ? $result[ $category ]['status'] : 'skip';
                if ( ! isset( $counts[ $category ][ $status ] ) ) {
                    $status = 'skip';
                }
                ++$counts[ $category ][ $status ];
            }
        }
        return $counts;
    }

    private static function status( $item ) {
        if ( ! is_array( $item ) ) {
            return __( 'Not run', 'taxocommerce-system-check' );
        }
        $names = array(
            'pass' => __( 'Passed', 'taxocommerce-system-check' ),
            'fail' => __( 'Failed', 'taxocommerce-system-check' ),
            'warning' => __( 'Review', 'taxocommerce-system-check' ),
            'skip' => __( 'Not checked', 'taxocommerce-system-check' ),
        );
        return $names[ $item['status'] ?? 'skip' ] ?? $names['skip'];
    }

    private static function button( $action, $label, $primary = false ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="operation" value="' . esc_attr( $action ) . '">';
        wp_nonce_field( self::ACTION );
        echo '<button type="submit" class="button ' . ( $primary ? 'button-primary' : 'button-secondary' ) . '">' . esc_html( $label ) . '</button></form>';
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'taxocommerce-system-check' ) );
        }
        $state = self::state();
        $speeds = self::speeds();
        echo '<section class="wrap tcs-code-check">';
        echo '<h2>' . esc_html__( 'Code Check · System Check', 'taxocommerce-system-check' ) . '</h2>';
        echo '<p>' . esc_html__( 'Only this plugin is inspected. Three independent checks: PHP syntax, WordPress conventions and security patterns. No other checker plugin is required.', 'taxocommerce-system-check' ) . '</p>';
        echo '<p class="description">' . esc_html__( 'This is a bounded local code review, not the official WordPress Plugin Check. Complex nonce, sanitization, escaping and compatibility cases still require manual review.', 'taxocommerce-system-check' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="operation" value="start">';
        wp_nonce_field( self::ACTION );
        echo '<label for="tcs-code-speed">' . esc_html__( 'CPU speed', 'taxocommerce-system-check' ) . '</label> ';
        echo '<select id="tcs-code-speed" name="speed">';
        foreach ( $speeds as $index => $config ) {
            $selected = is_array( $state ) ? (int) $state['speed'] === $index : 3 === $index;
            echo '<option value="' . esc_attr( (string) $index ) . '"' . selected( $selected, true, false ) . '>' . esc_html( $config['label'] ) . '</option>';
        }
        echo '</select> ';
        submit_button( __( 'Run Code Check', 'taxocommerce-system-check' ), 'primary', 'submit', false );
        echo '</form>';
        if ( is_array( $state ) ) {
            echo '<p><strong>' . esc_html__( 'Progress', 'taxocommerce-system-check' ) . ':</strong> '
                . esc_html( (string) $state['cursor'] ) . '/' . esc_html( (string) count( $state['files'] ) )
                . ' · ' . esc_html( $state['status'] ) . '</p>';
            if ( 'running' === $state['status'] ) {
                self::button( 'stop', __( 'Stop', 'taxocommerce-system-check' ) );
                echo '<form id="tcs-code-next" data-delay="' . esc_attr( (string) $speeds[ $state['speed'] ]['delay'] ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="operation" value="step">';
                wp_nonce_field( self::ACTION );
                echo '<noscript><button class="button" type="submit">' . esc_html__( 'Continue batch', 'taxocommerce-system-check' ) . '</button></noscript></form>';
            } elseif ( 'paused' === $state['status'] ) {
                self::button( 'resume', __( 'Resume', 'taxocommerce-system-check' ), true );
            }
            if ( $state['results'] ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT_ACTION ) . '">';
                wp_nonce_field( self::EXPORT_ACTION );
                submit_button( __( 'Download JSON report', 'taxocommerce-system-check' ), 'secondary', 'submit', false );
                echo '</form>';
            }
            if ( $state['results'] ) {
                $summary = self::summary( $state['results'] );
                echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'File', 'taxocommerce-system-check' ) . '</th><th>'
                    . esc_html__( 'Passed', 'taxocommerce-system-check' ) . '</th><th>'
                    . esc_html__( 'Failed', 'taxocommerce-system-check' ) . '</th><th>'
                    . esc_html__( 'Review', 'taxocommerce-system-check' ) . '</th><th>'
                    . esc_html__( 'Not checked', 'taxocommerce-system-check' ) . '</th></tr></thead><tbody>';
                foreach ( array( 'php' => 'PHP', 'wordpress' => 'WordPress', 'security' => 'Security' ) as $category => $label ) {
                    echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';
                    foreach ( array( 'pass', 'fail', 'warning', 'skip' ) as $status ) {
                        echo '<td>' . esc_html( (string) $summary[ $category ][ $status ] ) . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'File', 'taxocommerce-system-check' ) . '</th><th>'
                . esc_html__( 'PHP', 'taxocommerce-system-check' ) . '</th><th>'
                . esc_html__( 'WordPress', 'taxocommerce-system-check' ) . '</th><th>'
                . esc_html__( 'Security', 'taxocommerce-system-check' ) . '</th></tr></thead><tbody>';
            foreach ( $state['results'] as $file => $result ) {
                echo '<tr><th scope="row">' . esc_html( $file ) . '</th>';
                foreach ( array( 'php', 'wordpress', 'security' ) as $category ) {
                    $item = $result[ $category ] ?? false;
                    echo '<td><strong>' . esc_html( self::status( $item ) ) . '</strong>';
                    if ( is_array( $item ) ) {
                        echo '<p>' . esc_html( $item['message'] ) . '</p>';
                        // Surface actionable evidence; retain complete detail in the JSON report.
                        if ( 'php' === $category && ! empty( $item['evidence']['line'] ) ) {
                            echo '<p>#' . esc_html( (string) absint( $item['evidence']['line'] ) ) . '</p>';
                        }
                        if ( 'wordpress' === $category && ! empty( $item['evidence']['missing'] ) ) {
                            echo '<ul>';
                            foreach ( array_slice( $item['evidence']['missing'], 0, 10 ) as $missing ) {
                                echo '<li>' . esc_html( $missing ) . '</li>';
                            }
                            echo '</ul>';
                        }
                        if ( 'security' === $category && ! empty( $item['evidence']['calls'] ) ) {
                            echo '<ul>';
                            foreach ( array_slice( $item['evidence']['calls'], 0, 10 ) as $call ) {
                                echo '<li>' . esc_html( (string) $call['function'] . ' #' . absint( $call['line'] ) ) . '</li>';
                            }
                            echo '</ul>';
                        }
                    }
                    echo '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
    }

    public static function export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'taxocommerce-system-check' ) );
        }
        check_admin_referer( self::EXPORT_ACTION );
        $report = self::state();
        if ( ! is_array( $report ) || ! $report['results'] ) {
            wp_die( esc_html__( 'Run Code Check before downloading results.', 'taxocommerce-system-check' ) );
        }
        $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( ! is_string( $json ) ) {
            wp_die( esc_html__( 'Unable to generate JSON.', 'taxocommerce-system-check' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="taxocommerce-system-check-code-check-' . gmdate( 'Ymd-His' ) . '.json"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
        echo $json;
        exit;
    }
}
