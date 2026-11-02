<?php
/**
 * Localize static HTML text spans in PHP echo expressions.
 * Keep markup, HTML attributes, user data, and numeric machine identifiers intact.
 * Usage: php i18n-family-2062-echo.php /path/to/repository
 */
function taxocommerce_i18n_rewrite_echo( string $source, string $domain, int &$count ): string {
    $tokens = token_get_all( $source );
    $out = '';
    $echo_mode = false;
    $depth = 0;
    foreach ( $tokens as $token ) {
        if ( is_array( $token ) ) {
            list( $id, $value ) = $token;
            if ( T_ECHO === $id ) {
                $echo_mode = true;
            }
            if ( $echo_mode && T_CONSTANT_ENCAPSED_STRING === $id && strlen( $value ) > 6 && "'" === $value[0] ) {
                $inside = substr( $value, 1, -1 );
                if ( false === strpos( $inside, '\\' ) && false === strpos( $inside, '%' )
                    && ! preg_match( '~<(script|style|textarea|pre|code)\\b~i', $inside )
                    && ! preg_match( '~&(?:\\w+|#\\d+);~', $inside ) ) {
                    $changed = false;
                    $result = preg_replace_callback(
                        '~>([^<>\\n]{3,}?)</~u',
                        static function( $m ) use ( $domain, &$changed, &$count ) {
                            $visible = $m[1];
                            if ( ! preg_match( '~[a-zA-ZÀ-ÿ]~u', $visible ) ) {
                                return $m[0];
                            }
                            preg_match( '~^\\s*~u', $visible, $left );
                            preg_match( '~\\s*$~u', $visible, $right );
                            $word = trim( $visible );
                            if ( strlen( $word ) < 3 || preg_match( '~^[{\\[\\$]|^(https?:|[0-9.,]*)$~', $word ) ) {
                                return $m[0];
                            }
                            $count++;
                            $changed = true;
                            return '>' . $left[0] . "' . esc_html__( '" . str_replace( "'", "\\'", $word ) . "', '" . $domain . "' ) . '" . $right[0] . '</';
                        },
                        $inside
                    );
                    if ( $changed ) {
                        $value = "'" . $result . "'";
                    }
                }
            }
            $out .= $value;
        } else {
            if ( $echo_mode ) {
                if ( '(' === $token || '[' === $token ) {
                    $depth++;
                }
                if ( ')' === $token || ']' === $token ) {
                    $depth--;
                }
                if ( ';' === $token && 0 === $depth ) {
                    $echo_mode = false;
                }
            }
            $out .= $token;
        }
    }
    return $out;
}
if ( isset( $argv[1] ) ) {
    $root = realpath( $argv[1] );
    if ( ! $root ) {
        exit( 1 );
    }
    $sum = 0;
    foreach ( glob( $root . '/taxocommerce-*' ) as $dir ) {
        if ( ! is_dir( $dir ) ) {
            continue;
        }
        $domain = basename( $dir );
        $iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iter as $file ) {
            if ( 'php' !== $file->getExtension() || false !== strpos( $file->getPathname(), '/tests/' ) ) {
                continue;
            }
            $source = file_get_contents( $file->getPathname() );
            $count = 0;
            $updated = taxocommerce_i18n_rewrite_echo( $source, $domain, $count );
            if ( $count > 0 ) {
                file_put_contents( $file->getPathname(), $updated );
                echo $domain . '/' . $file->getFilename() . ': ' . $count . PHP_EOL;
                $sum += $count;
            }
        }
    }
    echo 'Localized HTML text spans: ' . $sum . PHP_EOL;
}
