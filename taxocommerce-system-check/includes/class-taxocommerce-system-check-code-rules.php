<?php
/**
 * Native static checks for System Check's own PHP source.
 *
 * Token inspection only: never execute plugin source or load other plugins.
 * All results are evidence and conservative review indicators, NOT a
 * replacement for WordPress Plugin Check or runtime security testing.
 *
 * @package TaxoCommerceSystemCheck
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_System_Check_Code_Rules {
    const MAX_FINDINGS = 35;

    /**
     * Find named declarations and literal include targets within one file.
     *
     * @param string $source   PHP source to tokenize.
     * @param string $relative File path relative to this plugin.
     * @return array Structured evidence with confirmed problems and gaps.
     */
    public static function structure( $source, $relative ) {
        $tokens = token_get_all( $source );
        $findings = array();
        $declarations = array();
        $uncertain = array();
        $namespace = '';
        $class_depths = array();
        $pending_class = false;
        $depth = 0;

        foreach ( $tokens as $i => $token ) {
            if ( '{' === $token ) {
                ++$depth;
                if ( $pending_class ) {
                    $class_depths[] = $depth;
                    $pending_class = false;
                }
                continue;
            }
            if ( '}' === $token ) {
                if ( $class_depths && end( $class_depths ) === $depth ) {
                    array_pop( $class_depths );
                }
                $depth = max( 0, $depth - 1 );
                continue;
            }
            if ( ! is_array( $token ) ) {
                continue;
            }

            $id = $token[0];
            if ( T_NAMESPACE === $id ) {
                $name = '';
                for ( $j = $i + 1, $n = count( $tokens ); $j < $n; ++$j ) {
                    $part = $tokens[ $j ];
                    if ( ';' === $part || '{' === $part ) {
                        break;
                    }
                    if ( is_array( $part ) && ( T_STRING === $part[0]
                        || T_NS_SEPARATOR === $part[0]
                        || ( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $part[0] ) ) ) {
                        $name .= $part[1];
                    }
                }
                $namespace = trim( $name, '\\' );
            }

            $named_class = in_array( $id, array_filter( array(
                T_CLASS,
                T_INTERFACE,
                T_TRAIT,
                defined( 'T_ENUM' ) ? constant( 'T_ENUM' ) : 0,
            ) ), true );
            if ( $named_class ) {
                $next = self::next_significant( $tokens, $i );
                // Anonymous classes are not duplicate symbols, but do have
                // methods which must not be confused with global functions.
                $pending_class = true;
                if ( null !== $next && is_array( $tokens[ $next ] )
                    && T_STRING === $tokens[ $next ][0] ) {
                    $declarations[] = array(
                        'type' => 'class',
                        'name' => strtolower( ltrim( $namespace . '\\' . $tokens[ $next ][1], '\\' ) ),
                        'display' => ltrim( $namespace . '\\' . $tokens[ $next ][1], '\\' ),
                        'line' => (int) $token[2],
                    );
                }
            }

            if ( T_FUNCTION === $id && ! $class_depths && 0 === $depth ) {
                $next = self::next_significant( $tokens, $i );
                if ( null !== $next && '&' === $tokens[ $next ] ) {
                    $next = self::next_significant( $tokens, $next );
                }
                if ( null !== $next && is_array( $tokens[ $next ] )
                    && T_STRING === $tokens[ $next ][0] ) {
                    $declarations[] = array(
                        'type' => 'function',
                        'name' => strtolower( ltrim( $namespace . '\\' . $tokens[ $next ][1], '\\' ) ),
                        'display' => ltrim( $namespace . '\\' . $tokens[ $next ][1], '\\' ),
                        'line' => (int) $token[2],
                    );
                }
            }

            if ( in_array( $id, array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) {
                $target = self::include_target( $tokens, $i, $relative );
                if ( 'dynamic' === $target['type'] ) {
                    $uncertain[] = array( 'rule' => 'dynamic_include', 'line' => (int) $token[2] );
                } elseif ( 'outside' === $target['type'] ) {
                    $findings[] = array(
                        'rule' => 'include_outside_plugin',
                        'severity' => 'review',
                        'line' => (int) $token[2],
                        'path' => $target['path'],
                    );
                } elseif ( 'local' === $target['type'] && ! is_file( $target['absolute'] ) ) {
                    $findings[] = array(
                        'rule' => 'missing_required_file',
                        'severity' => 'fail',
                        'line' => (int) $token[2],
                        'path' => $target['path'],
                    );
                }
            }
        }

        return array(
            'findings' => array_slice( $findings, 0, self::MAX_FINDINGS ),
            'declarations' => array_slice( $declarations, 0, 500 ),
            'unverified' => array_slice( $uncertain, 0, self::MAX_FINDINGS ),
            'findings_truncated' => count( $findings ) > self::MAX_FINDINGS,
            'declarations_truncated' => count( $declarations ) > 500,
        );
    }

    private static function next_significant( $tokens, $offset ) {
        for ( $i = $offset + 1, $n = count( $tokens ); $i < $n; ++$i ) {
            if ( ! is_array( $tokens[ $i ] )
                || ! in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Resolve only concatenations of literal paths, __DIR__ / __FILE__ and
     * the two constants owned by System Check. Never evaluate expressions.
     */
    private static function include_target( $tokens, $offset, $relative ) {
        $root = realpath( TAXOCOMMERCE_SYSTEM_CHECK_PATH );
        if ( false === $root ) {
            return array( 'type' => 'dynamic' );
        }
        $value = '';
        $has_literal = false;
        for ( $i = $offset + 1, $n = count( $tokens ); $i < $n; ++$i ) {
            $part = $tokens[ $i ];
            if ( ';' === $part ) {
                break;
            }
            if ( is_string( $part ) ) {
                if ( in_array( $part, array( '.', '(', ')' ), true ) ) {
                    continue;
                }
                return array( 'type' => 'dynamic' );
            }
            if ( in_array( $part[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
                continue;
            }
            if ( T_DIR === $part[0] ) {
                $value .= dirname( $root . '/' . $relative );
                $has_literal = true;
            } elseif ( T_FILE === $part[0] ) {
                $value .= $root . '/' . $relative;
                $has_literal = true;
            } elseif ( T_STRING === $part[0] && 'TAXOCOMMERCE_SYSTEM_CHECK_PATH' === $part[1] ) {
                $value .= $root . '/';
                $has_literal = true;
            } elseif ( T_STRING === $part[0] && 'TAXOCOMMERCE_SYSTEM_CHECK_FILE' === $part[1] ) {
                $value .= $root . '/taxocommerce-system-check.php';
                $has_literal = true;
            } elseif ( T_STRING === $part[0] && 'DIRECTORY_SEPARATOR' === $part[1] ) {
                $value .= DIRECTORY_SEPARATOR;
            } elseif ( T_CONSTANT_ENCAPSED_STRING === $part[0] ) {
                $quoted = $part[1];
                $inner = substr( $quoted, 1, -1 );
                $value .= '"' === substr( $quoted, 0, 1 )
                    ? stripcslashes( $inner )
                    : str_replace( array( "\\\\", "\\'" ), array( '\\', "'" ), $inner );
                $has_literal = true;
            } else {
                return array( 'type' => 'dynamic' );
            }
        }
        if ( ! $has_literal ) {
            return array( 'type' => 'dynamic' );
        }

        // Normalize without requiring the file to exist. A missing path still
        // has to be provably inside this plugin to be a confirmed error.
        $value = str_replace( '\\', '/', $value );
        if ( '/' !== substr( $value, 0, 1 ) ) {
            return array( 'type' => 'dynamic' );
        }
        $parts = array();
        foreach ( explode( '/', $value ) as $segment ) {
            if ( '' === $segment || '.' === $segment ) {
                continue;
            }
            if ( '..' === $segment ) {
                array_pop( $parts );
            } else {
                $parts[] = $segment;
            }
        }
        $absolute = '/' . implode( '/', $parts );
        $root = rtrim( str_replace( '\\', '/', $root ), '/' );
        if ( 0 !== strpos( $absolute, $root . '/' ) ) {
            return array( 'type' => 'outside', 'path' => '[outside-plugin]' );
        }
        return array(
            'type' => 'local',
            'absolute' => $absolute,
            'path' => substr( $absolute, strlen( $root ) + 1 ),
        );
    }

    /**
     * Catch known PHP 8 syntax/function use when this plugin requires PHP 7.4.
     * Does NOT claim broad backward compatibility; CI must test older PHP.
     */
    public static function compatibility( $source ) {
        $tokens = token_get_all( $source );
        $rules = array(
            'T_MATCH' => 'php_8_match',
            'T_NULLSAFE_OBJECT_OPERATOR' => 'php_8_nullsafe',
            'T_ATTRIBUTE' => 'php_8_attributes',
            'T_ENUM' => 'php_8_1_enums',
            'T_READONLY' => 'php_8_1_readonly',
        );
        $ids = array();
        foreach ( $rules as $constant => $rule ) {
            if ( defined( $constant ) ) {
                $ids[ constant( $constant ) ] = $rule;
            }
        }
        $new_functions = array(
            'str_contains' => 'php_8_str_contains',
            'str_starts_with' => 'php_8_str_starts_with',
            'str_ends_with' => 'php_8_str_ends_with',
            'get_debug_type' => 'php_8_get_debug_type',
            'array_is_list' => 'php_8_1_array_is_list',
            'json_validate' => 'php_8_3_json_validate',
        );
        $findings = array();
        foreach ( $tokens as $i => $token ) {
            if ( ! is_array( $token ) ) {
                continue;
            }
            if ( isset( $ids[ $token[0] ] ) ) {
                $findings[] = array( 'rule' => $ids[ $token[0] ], 'line' => (int) $token[2] );
            } elseif ( T_STRING === $token[0] && isset( $new_functions[ strtolower( $token[1] ) ] ) ) {
                $next = self::next_significant( $tokens, $i );
                if ( null !== $next && '(' === $tokens[ $next ] ) {
                    $findings[] = array(
                        'rule' => $new_functions[ strtolower( $token[1] ) ],
                        'line' => (int) $token[2],
                    );
                }
            }
        }
        return array_slice( $findings, 0, self::MAX_FINDINGS );
    }

    /**
     * Heuristic signals only. Never report flow security as positively proved.
     * Tokenization excludes comments and string literals for input/output scans.
     */
    public static function security_signals( $source ) {
        $tokens = token_get_all( $source );
        $signals = array();
        $input = null;
        $output = null;
        $wpdb = null;
        $has_sanitizer = false;
        $has_escaping = false;
        $has_prepare = false;
        $nonce = false;
        $capability = false;
        $rest = null;
        $ajax = null;
        $admin_post = null;
        $has_permission_callback = false;
        foreach ( $tokens as $i => $token ) {
            if ( ! is_array( $token ) ) {
                continue;
            }
            if ( T_VARIABLE === $token[0] && in_array(
                $token[1], array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES' ), true
            ) ) {
                $input = null === $input ? (int) $token[2] : $input;
            }
            if ( T_ECHO === $token[0] || T_PRINT === $token[0] ) {
                $next = self::next_significant( $tokens, $i );
                if ( null !== $next && is_array( $tokens[ $next ] )
                    && T_VARIABLE === $tokens[ $next ][0] ) {
                    $output = null === $output ? (int) $token[2] : $output;
                }
            }
            if ( T_VARIABLE === $token[0] && '$wpdb' === $token[1] ) {
                $wpdb = null === $wpdb ? (int) $token[2] : $wpdb;
            }
            if ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
                if ( false !== strpos( $token[1], 'permission_callback' ) ) {
                    $has_permission_callback = true;
                }
                if ( false !== strpos( $token[1], 'wp_ajax_' ) ) {
                    $ajax = null === $ajax ? (int) $token[2] : $ajax;
                }
                if ( false !== strpos( $token[1], 'admin_post_' ) ) {
                    $admin_post = null === $admin_post ? (int) $token[2] : $admin_post;
                }
                continue;
            }
            if ( T_STRING !== $token[0] ) {
                continue;
            }
            $name = strtolower( $token[1] );
            if ( 0 === strpos( $name, 'sanitize_' ) || in_array(
                $name, array( 'wp_unslash', 'filter_input', 'absint', 'intval', 'sanitize_key', 'wp_kses', 'wp_kses_post' ), true
            ) ) {
                $has_sanitizer = true;
            }
            if ( 0 === strpos( $name, 'esc_' ) || in_array( $name, array( 'wp_kses', 'wp_kses_post' ), true ) ) {
                $has_escaping = true;
            }
            if ( 'prepare' === $name ) {
                $has_prepare = true;
            }
            if ( in_array( $name, array( 'check_admin_referer', 'check_ajax_referer', 'wp_verify_nonce' ), true ) ) {
                $nonce = true;
            }
            if ( in_array( $name, array( 'current_user_can', 'permission_callback' ), true ) ) {
                $capability = true;
            }
            if ( 'register_rest_route' === $name ) {
                $rest = null === $rest ? (int) $token[2] : $rest;
            }
        }
        if ( null !== $input && ! $has_sanitizer ) {
            $signals[] = array( 'rule' => 'input_sanitization_review', 'line' => $input );
        }
        if ( null !== $output && ! $has_escaping ) {
            $signals[] = array( 'rule' => 'output_escaping_review', 'line' => $output );
        }
        if ( null !== $wpdb && ! $has_prepare ) {
            $signals[] = array( 'rule' => 'sql_preparation_review', 'line' => $wpdb );
        }
        if ( null !== $rest && ! $has_permission_callback ) {
            $signals[] = array( 'rule' => 'rest_permission_callback_review', 'line' => $rest );
        }
        if ( null !== $ajax && ! $nonce ) {
            $signals[] = array( 'rule' => 'ajax_nonce_review', 'line' => $ajax );
        }
        if ( null !== $ajax && ! $capability ) {
            $signals[] = array( 'rule' => 'ajax_permission_review', 'line' => $ajax );
        }
        if ( null !== $admin_post && ! $nonce ) {
            $signals[] = array( 'rule' => 'admin_post_nonce_review', 'line' => $admin_post );
        }
        if ( null !== $admin_post && ! $capability ) {
            $signals[] = array( 'rule' => 'admin_post_capability_review', 'line' => $admin_post );
        }
        return array_slice( $signals, 0, self::MAX_FINDINGS );
    }
}
