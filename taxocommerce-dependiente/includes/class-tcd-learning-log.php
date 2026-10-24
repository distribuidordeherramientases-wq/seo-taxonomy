<?php
defined( 'ABSPATH' ) || exit;

/**
 * Detailed, bounded learning trace for Dependiente.
 *
 * The active file is kept below 1 MiB. When it approaches the limit the
 * oldest part is discarded and the most recent ~768 KiB are preserved.
 */
final class TCD_Learning_Log {
    const MAX_BYTES = 1048576;
    const KEEP_BYTES = 786432;
    const DIR_NAME = 'taxocommerce-dependiente-private';
    const FILE_NAME = 'learning.log';

    private static $reserve = '';

    public static function boot() {
        self::$reserve = str_repeat( 'R', 262144 );
        register_shutdown_function( array( __CLASS__, 'shutdown' ) );
        add_action( 'admin_post_tcd_learning_log_clear', array( __CLASS__, 'clear_action' ) );
        add_action( 'admin_post_tcd_learning_log_download', array( __CLASS__, 'download_action' ) );
    }

    public static function write( $level, $event, $context = array() ) {
        $path = self::path();
        if ( ! $path ) return false;

        $level = strtoupper( sanitize_key( (string) $level ) );
        if ( ! in_array( $level, array( 'DEBUG', 'INFO', 'WARN', 'ERROR' ), true ) ) $level = 'INFO';
        $event = sanitize_key( (string) $event );

        $state = array();
        if ( class_exists( 'TCD_Academy', false ) ) {
            $raw = get_option( TCD_Academy::STATE, array() );
            if ( is_array( $raw ) ) {
                $state = array(
                    'run' => substr( (string) ( $raw['run_id'] ?? '' ), 0, 36 ),
                    'lesson' => absint( $raw['lesson'] ?? 0 ),
                    'phase' => sanitize_key( (string) ( $raw['phase'] ?? '' ) ),
                    'status' => sanitize_key( (string) ( $raw['status'] ?? '' ) ),
                );
            }
        }

        $payload = array_filter( array_merge( $state, self::clean_context( (array) $context ) ), static function ( $value ) {
            return null !== $value && '' !== $value && array() !== $value;
        } );

        $json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $json ) $json = '{"encoding_error":true}';
        if ( strlen( $json ) > 16384 ) $json = substr( $json, 0, 16300 ) . '...[truncated]';

        $line = sprintf( "%s %-5s %-32s %s\n", gmdate( 'c' ), $level, $event, $json );
        return self::append_bounded( $path, $line );
    }

    public static function read() {
        $path = self::path( false );
        if ( ! $path || ! is_readable( $path ) ) return '';
        $data = file_get_contents( $path );
        return is_string( $data ) ? $data : '';
    }

    public static function size() {
        $path = self::path( false );
        if ( ! $path || ! is_file( $path ) ) return 0;
        clearstatcache( true, $path );
        return max( 0, (int) filesize( $path ) );
    }

    public static function modified_at() {
        $path = self::path( false );
        if ( ! $path || ! is_file( $path ) ) return '';
        $time = filemtime( $path );
        return $time ? gmdate( 'c', $time ) : '';
    }

    public static function relative_path() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) return self::DIR_NAME . '/' . self::FILE_NAME;
        return trailingslashit( basename( (string) $uploads['basedir'] ) ) . self::DIR_NAME . '/' . self::FILE_NAME;
    }

    public static function clear() {
        $path = self::path();
        if ( ! $path ) return false;
        $ok = false !== file_put_contents(
            $path,
            gmdate( 'c' ) . " INFO  log_cleared                      {\"message\":\"Log vaciado por un administrador.\"}\n",
            LOCK_EX
        );
        return $ok;
    }

    public static function clear_action() {
        self::assert_admin();
        check_admin_referer( 'tcd_learning_log_clear' );
        self::clear();
        wp_safe_redirect( self::admin_url() );
        exit;
    }

    public static function download_action() {
        self::assert_admin();
        check_admin_referer( 'tcd_learning_log_download' );
        $data = self::read();
        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="dependiente_aprendizaje_' . gmdate( 'Ymd_His' ) . '.log"' );
        echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text admin download.
        exit;
    }

    public static function clear_url() {
        return wp_nonce_url(
            add_query_arg( 'action', 'tcd_learning_log_clear', admin_url( 'admin-post.php' ) ),
            'tcd_learning_log_clear'
        );
    }

    public static function download_url() {
        return wp_nonce_url(
            add_query_arg( 'action', 'tcd_learning_log_download', admin_url( 'admin-post.php' ) ),
            'tcd_learning_log_download'
        );
    }

    public static function shutdown() {
        self::$reserve = '';
        $error = error_get_last();
        if ( ! is_array( $error ) ) return;
        $fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
        if ( ! in_array( absint( $error['type'] ?? 0 ), $fatal, true ) ) return;
        self::write( 'ERROR', 'php_fatal', array(
            'type' => absint( $error['type'] ?? 0 ),
            'message' => (string) ( $error['message'] ?? '' ),
            'file' => self::short_path( (string) ( $error['file'] ?? '' ) ),
            'line' => absint( $error['line'] ?? 0 ),
            'memory' => memory_get_usage( true ),
            'memory_peak' => memory_get_peak_usage( true ),
        ) );
    }

    private static function assert_admin() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permisos insuficientes.' );
        }
    }

    private static function admin_url() {
        return add_query_arg(
            array( 'page' => 'taxocommerce-dependiente', 'tab' => 'learning-log' ),
            admin_url( 'admin.php' )
        );
    }

    private static function path( $create = true ) {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) return '';
        $dir = trailingslashit( (string) $uploads['basedir'] ) . self::DIR_NAME;
        if ( $create && ! is_dir( $dir ) ) {
            if ( ! wp_mkdir_p( $dir ) ) return '';
        }
        if ( $create ) self::protect_dir( $dir );
        $path = trailingslashit( $dir ) . self::FILE_NAME;
        if ( $create && ! file_exists( $path ) ) {
            file_put_contents(
                $path,
                gmdate( 'c' ) . " INFO  log_started                      {\"max_bytes\":" . self::MAX_BYTES . "}\n",
                LOCK_EX
            );
        }
        return $path;
    }

    private static function protect_dir( $dir ) {
        $index = trailingslashit( $dir ) . 'index.php';
        if ( ! file_exists( $index ) ) file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX );

        $htaccess = trailingslashit( $dir ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents(
                $htaccess,
                "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
                LOCK_EX
            );
        }

        $webconfig = trailingslashit( $dir ) . 'web.config';
        if ( ! file_exists( $webconfig ) ) {
            file_put_contents(
                $webconfig,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>",
                LOCK_EX
            );
        }
    }

    private static function append_bounded( $path, $line ) {
        $handle = fopen( $path, 'c+b' );
        if ( ! $handle ) return false;
        $ok = false;
        try {
            if ( ! flock( $handle, LOCK_EX ) ) return false;
            fseek( $handle, 0, SEEK_END );
            $size = ftell( $handle );
            $line_bytes = strlen( $line );

            if ( $size + $line_bytes > self::MAX_BYTES ) {
                $keep = min( self::KEEP_BYTES, max( 0, $size ) );
                $start = max( 0, $size - $keep );
                fseek( $handle, $start, SEEK_SET );
                $tail = (string) stream_get_contents( $handle );
                if ( $start > 0 ) {
                    $newline = strpos( $tail, "\n" );
                    if ( false !== $newline ) $tail = substr( $tail, $newline + 1 );
                }
                ftruncate( $handle, 0 );
                rewind( $handle );
                $header = gmdate( 'c' ) . " INFO  log_rotated                      {\"kept_bytes\":" . strlen( $tail ) . "}\n";
                fwrite( $handle, $header );
                fwrite( $handle, $tail );
            }

            fseek( $handle, 0, SEEK_END );
            $ok = false !== fwrite( $handle, $line );
            fflush( $handle );

            $end = ftell( $handle );
            if ( $end > self::MAX_BYTES ) {
                $keep = min( self::KEEP_BYTES, $end );
                fseek( $handle, max( 0, $end - $keep ), SEEK_SET );
                $tail = (string) stream_get_contents( $handle );
                $newline = strpos( $tail, "\n" );
                if ( false !== $newline ) $tail = substr( $tail, $newline + 1 );
                ftruncate( $handle, 0 );
                rewind( $handle );
                fwrite( $handle, $tail );
                fflush( $handle );
            }
        } finally {
            flock( $handle, LOCK_UN );
            fclose( $handle );
        }
        return $ok;
    }

    private static function clean_context( $context, $depth = 0 ) {
        if ( $depth > 3 ) return '[depth-limit]';
        $out = array();
        foreach ( (array) $context as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( is_array( $value ) ) {
                $out[ $key ] = self::clean_context( $value, $depth + 1 );
            } elseif ( is_scalar( $value ) || null === $value ) {
                $text = null === $value ? null : (string) $value;
                $out[ $key ] = is_string( $text ) && strlen( $text ) > 2048 ? substr( $text, 0, 2048 ) . '...[truncated]' : $text;
            } else {
                $out[ $key ] = gettype( $value );
            }
        }
        return $out;
    }

    private static function short_path( $path ) {
        $path = wp_normalize_path( $path );
        $root = wp_normalize_path( ABSPATH );
        return 0 === strpos( $path, $root ) ? ltrim( substr( $path, strlen( $root ) ), '/' ) : basename( $path );
    }
}
