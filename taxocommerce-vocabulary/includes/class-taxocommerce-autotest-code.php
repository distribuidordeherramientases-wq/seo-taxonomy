<?php
/**
 * Independent, bounded PHP/WordPress/security self-audit.
 *
 * Vendored in this plugin from .github/templates/taxocommerce-autotest-code.php.template.
 * Never loads or depends on TaxoCommerce Core or WordPress Plugin Check.
 *
 * @package TaxoCommerceVocabulary
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Vocabulary_Autotest_Code {
    const ACTION = 'taxocommerce_vocabulary_code_check';
    const EXPORT_ACTION = 'taxocommerce_vocabulary_code_check_json';
    const TTL = HOUR_IN_SECONDS;
    const DOMAIN = 'taxocommerce-vocabulary';

    public static function boot() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
        add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }

    public static function assets() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only; never changes data.
        $section = isset( $_GET['tc_autotest_section'] ) && is_scalar( $_GET['tc_autotest_section'] )
            ? sanitize_key( wp_unslash( $_GET['tc_autotest_section'] ) ) : '';
        if ( 'code' === $section && current_user_can( 'manage_options' ) ) {
            wp_enqueue_script(
                'taxocommerce-vocabulary-autotest-code',
                plugins_url( 'assets/autotest-code.js', dirname( __DIR__ ) . '/taxocommerce-vocabulary.php' ),
                array(),
                '1.0.0',
                true
            );
        }
    }

    public static function speeds() {
        return array(
            1 => array( 'size' => 1, 'delay' => 5000 ),
            2 => array( 'size' => 1, 'delay' => 3000 ),
            3 => array( 'size' => 2, 'delay' => 2000 ),
            4 => array( 'size' => 3, 'delay' => 1200 ),
            5 => array( 'size' => 4, 'delay' => 800 ),
        );
    }

    private static function key() {
        return 'tc_vocabulary_code_' . get_current_blog_id() . '_' . get_current_user_id();
    }

    private static function state() {
        $value = get_transient( self::key() );
        return is_array( $value ) && isset( $value['files'], $value['cursor'], $value['results'], $value['status'] ) ? $value : false;
    }

    /** One plugin directory, no symlink traversal or other plugin code. */
    public static function files() {
        $root = realpath( dirname( __DIR__ ) );
        if ( ! $root ) {
            return array();
        }
        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
        );
        foreach ( $iterator as $entry ) {
            if ( count( $files ) >= 250 ) {
                break;
            }
            if ( $entry->isLink() || ! $entry->isFile() || 'php' !== strtolower( $entry->getExtension() ) ) {
                continue;
            }
            $path = realpath( $entry->getPathname() );
            if ( false !== $path && 0 === strpos( $path, $root . DIRECTORY_SEPARATOR ) ) {
                $files[] = str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( $root ) + 1 ) );
            }
        }
        sort( $files, SORT_STRING );
        return $files;
    }

    private static function finding( $status, $evidence = array() ) {
        return array( 'status' => $status, 'evidence' => array_slice( $evidence, 0, 25 ) );
    }

    public static function analyze( $relative ) {
        $root = realpath( dirname( __DIR__ ) );
        $path = realpath( dirname( __DIR__ ) . '/' . $relative );
        if ( ! $root || ! $path || 0 !== strpos( $path, $root . DIRECTORY_SEPARATOR )
            || ! is_file( $path ) || ! is_readable( $path ) || 'php' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
            $unknown = self::finding( 'skip', array( array( 'rule' => 'file_unavailable' ) ) );
            return array( 'php' => $unknown, 'wordpress' => $unknown, 'security' => $unknown, 'declarations' => array() );
        }
        $size = filesize( $path );
        if ( false === $size || $size > 786432 ) {
            $unknown = self::finding( 'skip', array( array( 'rule' => 'file_too_large' ) ) );
            return array( 'php' => $unknown, 'wordpress' => $unknown, 'security' => $unknown, 'declarations' => array() );
        }
        $source = file_get_contents( $path );
        if ( ! is_string( $source ) ) {
            $unknown = self::finding( 'skip', array( array( 'rule' => 'file_unreadable' ) ) );
            return array( 'php' => $unknown, 'wordpress' => $unknown, 'security' => $unknown, 'declarations' => array() );
        }
        return self::analyze_source( $source, $relative, $root );
    }

    /**
     * Source-only analyzer. PHP parser does not execute inspected code.
     * Security results are heuristic signals, not a WordPress.org verdict.
     */
    public static function analyze_source( $source, $relative, $root ) {
        $php = array();
        try {
            token_get_all( $source, TOKEN_PARSE );
        } catch ( ParseError $error ) {
            $php[] = array( 'rule' => 'php_parse_error', 'line' => absint( $error->getLine() ) );
        }
        $tokens = token_get_all( $source );
        $wp = array();
        $security = array();
        $declarations = array();
        if ( false === strpos( $source, 'ABSPATH' ) ) {
            $wp[] = array( 'rule' => 'missing_abspath_guard' );
        }
        if ( 'taxocommerce-vocabulary.php' === $relative ) {
            foreach ( array( 'Plugin Name', 'Version', 'Text Domain', 'Requires PHP', 'License' ) as $field ) {
                if ( ! preg_match( '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*\S+/mi', $source ) ) {
                    $wp[] = array( 'rule' => 'missing_plugin_header', 'field' => $field );
                }
            }
            if ( ! preg_match( '/^\s*\*\s*Text Domain:\s*taxocommerce-vocabulary\s*$/mi', $source ) ) {
                $wp[] = array( 'rule' => 'incorrect_text_domain' );
            }
            if ( ! is_readable( $root . '/readme.txt' ) ) {
                $wp[] = array( 'rule' => 'missing_readme' );
            }
        }
        $class_depth = array();
        $new_class = false;
        $depth = 0;
        $input = 0;
        $echo = 0;
        $wpdb = 0;
        $nonce = false;
        $capability = false;
        $sanitize = false;
        $escape = false;
        $prepare = false;
        $rest = 0;
        $permission = false;
        $ajax = 0;
        $namespace = '';
        $n = count( $tokens );
        foreach ( $tokens as $i => $token ) {
            if ( '{' === $token ) {
                ++$depth;
                if ( $new_class ) {
                    $class_depth[] = $depth;
                    $new_class = false;
                }
                continue;
            }
            if ( '}' === $token ) {
                if ( $class_depth && end( $class_depth ) === $depth ) {
                    array_pop( $class_depth );
                }
                $depth = max( 0, $depth - 1 );
                continue;
            }
            if ( ! is_array( $token ) ) {
                continue;
            }
            $id = $token[0];
            $text = $token[1];
            $line = $token[2];
            if ( T_NAMESPACE === $id ) {
                $namespace = '';
                for ( $j = $i + 1; $j < $n; ++$j ) {
                    $piece = $tokens[ $j ];
                    if ( ';' === $piece || '{' === $piece ) {
                        break;
                    }
                    if ( is_array( $piece ) && ( T_STRING === $piece[0]
                        || T_NS_SEPARATOR === $piece[0]
                        || ( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $piece[0] ) ) ) {
                        $namespace .= $piece[1];
                    }
                }
            }
            if ( in_array( $id, array( T_CLASS, T_INTERFACE, T_TRAIT ), true )
                || ( defined( 'T_ENUM' ) && T_ENUM === $id ) ) {
                $new_class = true;
                $j = self::next_token( $tokens, $i );
                if ( null !== $j && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
                    $declarations[] = array(
                        'name' => strtolower( trim( $namespace . '\\' . $tokens[ $j ][1], '\\' ) ),
                        'kind' => 'class',
                        'line' => $line,
                    );
                }
            }
            if ( T_FUNCTION === $id && ! $class_depth && 0 === $depth ) {
                $j = self::next_token( $tokens, $i );
                if ( null !== $j && '&' === $tokens[ $j ] ) {
                    $j = self::next_token( $tokens, $j );
                }
                if ( null !== $j && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
                    $declarations[] = array(
                        'name' => strtolower( trim( $namespace . '\\' . $tokens[ $j ][1], '\\' ) ),
                        'kind' => 'function',
                        'line' => $line,
                    );
                }
            }
            if ( T_VARIABLE === $id && in_array( $text, array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES' ), true ) ) {
                $input = $input ?: $line;
            }
            if ( T_ECHO === $id || T_PRINT === $id ) {
                $echo = $echo ?: $line;
            }
            if ( T_VARIABLE === $id && '$wpdb' === $text ) {
                $wpdb = $wpdb ?: $line;
            }
            if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
                if ( false !== strpos( $text, 'wp_ajax_' ) ) {
                    $ajax = $ajax ?: $line;
                }
                if ( false !== strpos( $text, 'permission_callback' ) ) {
                    $permission = true;
                }
            }
            if ( T_EVAL === $id ) {
                $security[] = array( 'rule' => 'dangerous_eval', 'line' => $line );
            }
            if ( T_STRING === $id ) {
                $name = strtolower( $text );
                if ( in_array( $name, array( 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'unserialize' ), true )
                    && self::is_function_call( $tokens, $i ) ) {
                    $security[] = array( 'rule' => 'dangerous_call_' . $name, 'line' => $line );
                }
                if ( 0 === strpos( $name, 'sanitize_' ) || in_array( $name, array( 'wp_unslash', 'absint', 'filter_input', 'wp_kses' ), true ) ) {
                    $sanitize = true;
                }
                if ( 0 === strpos( $name, 'esc_' ) || 'wp_kses' === $name ) {
                    $escape = true;
                }
                if ( 'prepare' === $name ) {
                    $prepare = true;
                }
                if ( in_array( $name, array( 'check_admin_referer', 'check_ajax_referer', 'wp_verify_nonce' ), true ) ) {
                    $nonce = true;
                }
                if ( 'current_user_can' === $name ) {
                    $capability = true;
                }
                if ( 'register_rest_route' === $name ) {
                    $rest = $rest ?: $line;
                }
                $php8 = array( 'str_contains', 'str_starts_with', 'str_ends_with', 'array_is_list' );
                if ( in_array( $name, $php8, true ) && self::is_function_call( $tokens, $i ) ) {
                    $php[] = array( 'rule' => 'php_8_function_' . $name, 'line' => $line );
                }
            }
            if ( defined( 'T_MATCH' ) && T_MATCH === $id ) {
                $php[] = array( 'rule' => 'php_8_match', 'line' => $line );
            }
            if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $id ) {
                $php[] = array( 'rule' => 'php_8_nullsafe', 'line' => $line );
            }
        }
        if ( $input && ! $sanitize ) {
            $security[] = array( 'rule' => 'review_input_sanitization', 'line' => $input );
        }
        if ( $echo && ! $escape ) {
            $security[] = array( 'rule' => 'review_output_escaping', 'line' => $echo );
        }
        if ( $wpdb && ! $prepare ) {
            $security[] = array( 'rule' => 'review_sql_preparation', 'line' => $wpdb );
        }
        if ( $ajax && ! $nonce ) {
            $security[] = array( 'rule' => 'review_ajax_nonce', 'line' => $ajax );
        }
        if ( $ajax && ! $capability ) {
            $security[] = array( 'rule' => 'review_ajax_capability', 'line' => $ajax );
        }
        if ( $rest && ! $permission ) {
            $security[] = array( 'rule' => 'review_rest_permissions', 'line' => $rest );
        }

        // Verify only simple, unambiguously local __DIR__ literal includes.
        if ( preg_match_all(
            '/\b(?:include|require)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*(["\x27])([^"\x27]+)\1\s*\)?\s*;/i',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        ) ) {
            foreach ( $matches[2] as $match ) {
                $candidate = dirname( $root . '/' . $relative ) . '/' . ltrim( $match[0], '/\\' );
                $inside = realpath( $candidate );
                if ( false === $inside ) {
                    $wp[] = array( 'rule' => 'missing_literal_include', 'path' => basename( $candidate ) );
                } elseif ( 0 !== strpos( $inside, $root . DIRECTORY_SEPARATOR ) ) {
                    $wp[] = array( 'rule' => 'include_outside_plugin' );
                }
            }
        }
        return array(
            'php' => self::finding( $php ? ( 'php_parse_error' === $php[0]['rule'] ? 'fail' : 'warning' ) : 'pass', $php ),
            'wordpress' => self::finding( $wp ? 'warning' : 'pass', $wp ),
            'security' => self::finding( $security ? 'warning' : 'pass', $security ),
            'declarations' => array_slice( $declarations, 0, 120 ),
        );
    }

    private static function next_token( $tokens, $offset ) {
        for ( $i = $offset + 1, $n = count( $tokens ); $i < $n; ++$i ) {
            if ( ! is_array( $tokens[ $i ] ) || ! in_array(
                $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true
            ) ) {
                return $i;
            }
        }
        return null;
    }

    private static function is_function_call( $tokens, $offset ) {
        $next = self::next_token( $tokens, $offset );
        if ( null === $next || '(' !== $tokens[ $next ] ) {
            return false;
        }
        $prev = $offset - 1;
        while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
            --$prev;
        }
        return $prev < 0 || ! is_array( $tokens[ $prev ] )
            || ! in_array( $tokens[ $prev ][0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true );
    }

    public static function step( $state ) {
        if ( 'running' !== $state['status'] ) {
            return $state;
        }
        $speeds = self::speeds();
        $batch = $speeds[ $state['speed'] ]['size'] ?? 2;
        $total = count( $state['files'] );
        for ( $i = 0; $i < $batch && $state['cursor'] < $total; ++$i ) {
            $file = $state['files'][ $state['cursor'] ];
            try {
                $report = self::analyze( $file );
                foreach ( $report['declarations'] as $symbol ) {
                    $key = $symbol['kind'] . ':' . $symbol['name'];
                    if ( isset( $state['symbols'][ $key ] ) ) {
                        $other = $state['symbols'][ $key ];
                        $report['wordpress']['evidence'][] = array(
                            'rule' => 'review_duplicate_symbol',
                            'symbol' => $symbol['name'],
                            'line' => $symbol['line'],
                            'other_file' => $other['file'],
                        );
                        $report['wordpress']['status'] = 'warning';
                    } else {
                        $state['symbols'][ $key ] = array( 'file' => $file, 'line' => $symbol['line'] );
                    }
                }
                unset( $report['declarations'] );
                $state['results'][ $file ] = $report;
            } catch ( Throwable $error ) {
                $unknown = self::finding( 'skip', array( array( 'rule' => 'analysis_incomplete' ) ) );
                $state['results'][ $file ] = array( 'php' => $unknown, 'wordpress' => $unknown, 'security' => $unknown );
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
            wp_die( esc_html__( 'Access denied.', 'taxocommerce-vocabulary' ) );
        }
        check_admin_referer( self::ACTION );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
        $op = isset( $_POST['operation'] ) && is_scalar( $_POST['operation'] )
            ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
        $state = self::state();
        if ( 'start' === $op ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked above.
            $requested = isset( $_POST['speed'] ) && is_scalar( $_POST['speed'] )
                ? absint( wp_unslash( $_POST['speed'] ) ) : 3;
            $speeds = self::speeds();
            $files = self::files();
            $state = array(
                'schema' => 1,
                'plugin' => self::DOMAIN,
                'timestamp' => gmdate( 'c' ),
                'status' => $files ? 'running' : 'completed',
                'speed' => isset( $speeds[ $requested ] ) ? $requested : 3,
                'cursor' => 0,
                'files' => $files,
                'symbols' => array(),
                'results' => array(),
                'official_pcp' => 'not_executed',
                'runtime_security' => 'not_verified',
                'older_php_versions' => 'not_tested',
                'inventory_truncated' => count( $files ) >= 250,
            );
        } elseif ( is_array( $state ) && 'stop' === $op ) {
            $state['status'] = 'paused';
        } elseif ( is_array( $state ) && 'resume' === $op && 'paused' === $state['status'] ) {
            $state['status'] = 'running';
        } elseif ( is_array( $state ) && 'step' === $op ) {
            $state = self::step( $state );
        }
        if ( is_array( $state ) ) {
            set_transient( self::key(), $state, self::TTL );
        }
        $redirect = wp_get_referer();
        wp_safe_redirect(
            add_query_arg(
                'tc_autotest_section',
                'code',
                $redirect ? $redirect : admin_url( 'plugins.php' )
            )
        );
        exit;
    }

    public static function export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'taxocommerce-vocabulary' ) );
        }
        check_admin_referer( self::EXPORT_ACTION );
        $state = self::state();
        if ( ! is_array( $state ) || ! $state['results'] ) {
            wp_die( esc_html__( 'Run Code Check first.', 'taxocommerce-vocabulary' ) );
        }
        $json = wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $json ) ) {
            wp_die( esc_html__( 'Unable to create JSON.', 'taxocommerce-vocabulary' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="taxocommerce-vocabulary-code-check-' . gmdate( 'Ymd-His' ) . '.json"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, no HTML.
        echo $json;
        exit;
    }

    /**
     * Render two sub-tabs and return true only when Code Check takes over.
     * Existing functional Autotest is never run unless explicitly selected.
     */
    public static function render_subtabs() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'taxocommerce-vocabulary' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
        $code = isset( $_GET['tc_autotest_section'] ) && 'code' === sanitize_key( wp_unslash( $_GET['tc_autotest_section'] ) );
        $base = remove_query_arg( 'tc_autotest_section' );
        echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Autotest sections', 'taxocommerce-vocabulary' ) . '">';
        echo '<a class="nav-tab' . ( $code ? '' : ' nav-tab-active' ) . '" href="' . esc_url( $base ) . '">'
            . esc_html__( 'Functionality Check', 'taxocommerce-vocabulary' ) . '</a>';
        echo '<a class="nav-tab' . ( $code ? ' nav-tab-active' : '' ) . '" href="'
            . esc_url( add_query_arg( 'tc_autotest_section', 'code', $base ) ) . '">'
            . esc_html__( 'Code Check', 'taxocommerce-vocabulary' ) . '</a></nav>';
        if ( $code ) {
            self::render();
        }
        return $code;
    }

    private static function button( $operation, $label, $primary = false ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
        wp_nonce_field( self::ACTION );
        echo '<button type="submit" class="button ' . ( $primary ? 'button-primary' : 'button-secondary' ) . '">'
            . esc_html( $label ) . '</button></form>';
    }

    public static function render() {
        $state = self::state();
        $speeds = self::speeds();
        echo '<section class="wrap tc-autotest-code">';
        echo '<h2>' . esc_html__( 'Code Check', 'taxocommerce-vocabulary' ) . '</h2>';
        echo '<p>' . esc_html__( 'PHP syntax, WordPress structure and security indicators for this plugin only. No other checker is required. Findings do not replace official Plugin Check or a security audit.', 'taxocommerce-vocabulary' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="operation" value="start">';
        wp_nonce_field( self::ACTION );
        echo '<label for="tc-autotest-code-speed">' . esc_html__( 'CPU usage', 'taxocommerce-vocabulary' ) . '</label> ';
        echo '<select id="tc-autotest-code-speed" name="speed">';
        foreach ( $speeds as $value => $config ) {
            echo '<option value="' . esc_attr( (string) $value ) . '"'
                . selected( $value, is_array( $state ) ? (int) $state['speed'] : 3, false ) . '>'
                . esc_html( (string) $value ) . '</option>';
        }
        echo '</select> ';
        submit_button( __( 'Run Code Check', 'taxocommerce-vocabulary' ), 'primary', 'submit', false );
        echo '</form>';
        if ( is_array( $state ) ) {
            echo '<p>' . esc_html__( 'Progress', 'taxocommerce-vocabulary' ) . ': '
                . esc_html( (string) $state['cursor'] ) . '/' . esc_html( (string) count( $state['files'] ) )
                . ' · ' . esc_html( $state['status'] ) . '</p>';
            if ( 'running' === $state['status'] ) {
                self::button( 'stop', __( 'Stop', 'taxocommerce-vocabulary' ) );
                echo '<form id="tc-autotest-code-step" data-delay="'
                    . esc_attr( (string) $speeds[ $state['speed'] ]['delay'] )
                    . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
                echo '<input type="hidden" name="operation" value="step">';
                wp_nonce_field( self::ACTION );
                echo '<noscript><button type="submit" class="button">' . esc_html__( 'Continue batch', 'taxocommerce-vocabulary' ) . '</button></noscript></form>';
            } elseif ( 'paused' === $state['status'] ) {
                self::button( 'resume', __( 'Resume', 'taxocommerce-vocabulary' ), true );
            }
            if ( $state['results'] ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT_ACTION ) . '">';
                wp_nonce_field( self::EXPORT_ACTION );
                submit_button( __( 'Download JSON', 'taxocommerce-vocabulary' ), 'secondary', 'submit', false );
                echo '</form>';
                echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'File', 'taxocommerce-vocabulary' )
                    . '</th><th>PHP</th><th>WordPress</th><th>' . esc_html__( 'Security', 'taxocommerce-vocabulary' ) . '</th></tr></thead><tbody>';
                foreach ( $state['results'] as $file => $checks ) {
                    echo '<tr><th scope="row">' . esc_html( $file ) . '</th>';
                    foreach ( array( 'php', 'wordpress', 'security' ) as $section ) {
                        $item = $checks[ $section ] ?? array( 'status' => 'skip', 'evidence' => array() );
                        echo '<td><strong>' . esc_html( $item['status'] ) . '</strong>';
                        foreach ( array_slice( $item['evidence'], 0, 8 ) as $finding ) {
                            $rule = isset( $finding['rule'] ) ? $finding['rule'] : 'unknown';
                            echo '<div><code>' . esc_html( $rule ) . '</code>';
                            if ( isset( $finding['line'] ) ) {
                                echo ' #' . esc_html( (string) absint( $finding['line'] ) );
                            }
                            echo '</div>';
                        }
                        echo '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table>';
            }
        }
        echo '</section>';
    }
}
