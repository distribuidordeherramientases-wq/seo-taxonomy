<?php
/**
 * Native PHP environment diagnostic for the new TaxoCommerce family.
 *
 * Read-only: no database queries, cron, network calls, legacy globals or
 * dependency on TaxoCommerce SEO. Requirements come from the old PHP screen,
 * not from the old implementation.
 *
 * @package TaxoCommerceSystemCheck
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_System_Check_PHP {

    /**
     * Return a small, serializable list of PHP requirements and observations.
     *
     * @return array<int,array<string,string>>
     */
    public static function collect() {
        $version = (string) phpversion();
        $checks = array(
            self::row(
                'php_version',
                'Versión PHP',
                $version,
                version_compare( $version, '8.0', '>=' ) ? 'ok' : 'warning',
                'Requisito recomendado: PHP 8.0 o superior.'
            ),
        );

        $requirements = array(
            'memory_limit'        => array( 268435456, 'bytes', '256 MB o más para catálogos grandes.' ),
            'max_execution_time'  => array( 60, 'number', '60 segundos o ejecución sin límite.' ),
            'max_input_vars'      => array( 3000, 'number', '3000 variables o más para formularios extensos.' ),
            'upload_max_filesize' => array( 33554432, 'bytes', '32 MB o más para paquetes de importación.' ),
            'post_max_size'       => array( 33554432, 'bytes', 'Debe admitir el tamaño máximo de subida.' ),
        );

        $upload_bytes = self::ini_bytes( (string) ini_get( 'upload_max_filesize' ) );
        foreach ( $requirements as $setting => $rule ) {
            $raw = ini_get( $setting );
            if ( false === $raw ) {
                $checks[] = self::row( $setting, $setting, 'No disponible', 'info', 'Este parámetro no se puede consultar.' );
                continue;
            }

            $value = 'bytes' === $rule[1] ? self::ini_bytes( (string) $raw ) : (int) $raw;
            $unlimited = ( 'memory_limit' === $setting && -1 === $value )
                || ( 'max_execution_time' === $setting && 0 === $value );
            $ok = $unlimited || $value >= $rule[0];
            if ( 'post_max_size' === $setting && 0 < $upload_bytes && 0 < $value && $value < $upload_bytes ) {
                $ok = false;
            }
            $checks[] = self::row(
                $setting,
                $setting,
                (string) $raw,
                $ok ? 'ok' : 'warning',
                $rule[2]
            );
        }

        // Check only genuinely required extensions as warnings.
        foreach ( array( 'curl', 'zip', 'mbstring', 'xml', 'json', 'openssl' ) as $extension ) {
            $available = extension_loaded( $extension );
            $checks[] = self::row(
                'ext_' . $extension,
                'Extensión ' . $extension,
                $available ? 'Disponible' : 'No disponible',
                $available ? 'ok' : 'warning',
                'Componente utilizado por WordPress o sus integraciones.'
            );
        }

        // Either image library is sufficient. Both are not mandatory.
        $has_images = extension_loaded( 'gd' ) || extension_loaded( 'imagick' );
        $checks[] = self::row(
            'image_library',
            'Procesado de imágenes',
            $has_images ? 'Disponible' : 'Sin GD ni Imagick',
            $has_images ? 'ok' : 'warning',
            'Es suficiente con GD o Imagick.'
        );
        $checks[] = self::row(
            'ext_intl',
            'Extensión intl',
            extension_loaded( 'intl' ) ? 'Disponible' : 'No disponible',
            'info',
            'Opcional para formatos regionales.'
        );
        $opcache = function_exists( 'opcache_get_status' ) && (bool) @opcache_get_status( false );
        $checks[] = self::row( 'opcache', 'OPcache', $opcache ? 'Activo' : 'No detectable', 'info', 'Optimización PHP opcional.' );

        foreach ( array(
            'default_socket_timeout', 'max_file_uploads', 'realpath_cache_size',
            'realpath_cache_ttl', 'display_errors', 'log_errors', 'error_log', 'date.timezone',
        ) as $setting ) {
            $value = ini_get( $setting );
            $checks[] = self::row(
                'info_' . $setting,
                $setting,
                false === $value || '' === $value ? 'No definido' : (string) $value,
                'info',
                'Configuración informativa.'
            );
        }

        return $checks;
    }

    /** Convert PHP shorthand sizes without invoking legacy helpers. */
    public static function ini_bytes( $raw ) {
        $raw = trim( (string) $raw );
        if ( '-1' === $raw ) {
            return -1;
        }
        if ( ! preg_match( '/^([0-9]+(?:\\.[0-9]+)?)\\s*([KMG]?)B?$/i', $raw, $match ) ) {
            return 0;
        }
        $scale = array( '' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824 );
        $unit = strtoupper( $match[2] );
        return (int) round( (float) $match[1] * $scale[ $unit ] );
    }

    /** Normalized output contract used by the future System Check interface. */
    private static function row( $id, $label, $value, $status, $detail ) {
        return array(
            'id'     => $id,
            'label'  => $label,
            'value'  => $value,
            'status' => $status,
            'detail' => $detail,
        );
    }

    /** Independent WordPress admin view for this one migrated check. */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-system-check' ) );
        }
        $back = add_query_arg(
            array( 'page' => 'taxocommerce-system-check', 'tcs_tab' => 'system' ),
            admin_url( 'admin.php' )
        );
        echo '<div class="wrap tcc-family-screen">';
        echo '<h2>PHP · TaxoCommerce System Check</h2>';
        echo '<p>Diagnóstico PHP autónomo y de solo lectura. No depende del antiguo TaxoCommerce SEO.</p>';
        echo '<p><a href="' . esc_url( $back ) . '">Volver al resumen del sistema</a></p>';
        echo '<table class="widefat striped"><thead><tr><th>Comprobación</th><th>Valor</th><th>Estado</th><th>Información</th></tr></thead><tbody>';
        foreach ( self::collect() as $check ) {
            echo '<tr><th scope="row">' . esc_html( $check['label'] ) . '</th><td>'
                . esc_html( $check['value'] ) . '</td><td>'
                . esc_html( $check['status'] ) . '</td><td>'
                . esc_html( $check['detail'] ) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
