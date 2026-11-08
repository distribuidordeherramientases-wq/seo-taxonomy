<?php
/**
 * Bounded family-wide, read-only diagnostics for installed TaxoCommerce plugins.
 *
 * This never starts a provider request, Autotest worker or Plugin Check internals.
 * A Plugin Check verdict must come from the official tool, not inferred here.
 *
 * @package TaxoCommerceSystemCheck
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_System_Check_Family {
    const ACTION = 'taxocommerce_system_check_family';
    const EXPORT_ACTION = 'taxocommerce_system_check_family_json';
    const TTL = HOUR_IN_SECONDS;

    /** Five speeds with finite per-request work and browser-side pacing. */
    public static function speeds() {
        return array(
            1 => array( 'batch' => 1, 'delay' => 5000, 'label' => __( 'Muy baja', 'taxocommerce-system-check' ) ),
            2 => array( 'batch' => 1, 'delay' => 3000, 'label' => __( 'Baja', 'taxocommerce-system-check' ) ),
            3 => array( 'batch' => 2, 'delay' => 2000, 'label' => __( 'Normal', 'taxocommerce-system-check' ) ),
            4 => array( 'batch' => 3, 'delay' => 1250, 'label' => __( 'Alta', 'taxocommerce-system-check' ) ),
            5 => array( 'batch' => 4, 'delay' => 800, 'label' => __( 'Máxima', 'taxocommerce-system-check' ) ),
        );
    }

    public static function boot() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
        add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export' ) );
    }

    private static function key() {
        return 'tcs_family_audit_' . get_current_blog_id() . '_' . get_current_user_id();
    }

    private static function latest() {
        $state = get_transient( self::key() );
        return is_array( $state ) && isset( $state['plugins'], $state['cursor'], $state['results'] )
            ? $state : false;
    }

    /** Read WordPress's installed-plugins registry; never scan arbitrary paths. */
    public static function inventory() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $entries = array();
        foreach ( get_plugins() as $file => $data ) {
            $slug = dirname( $file );
            if ( ! preg_match( '/^taxocommerce-[a-z0-9-]+$/D', $slug ) ) {
                continue;
            }
            // Only WordPress-provided installed plugin entrypoints are used.
            $entries[ $file ] = array(
                'name'    => sanitize_text_field( (string) ( $data['Name'] ?? $slug ) ),
                'version' => sanitize_text_field( (string) ( $data['Version'] ?? '' ) ),
                'domain'  => sanitize_key( (string) ( $data['TextDomain'] ?? '' ) ),
                'active'  => ( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) )
                    || ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $file ) ),
            );
        }
        ksort( $entries, SORT_STRING );
        return $entries;
    }

    private static function finding( $status, $message, $details = array() ) {
        return array(
            'status'  => $status,
            'message' => $message,
            'details' => $details,
        );
    }

    /**
     * Static, strictly bounded inspection of installed plugin entrypoints.
     * Does not claim to run PHP lint, PHPCS or the official Plugin Check.
     */
    public static function inspect( $file, $plugin ) {
        $slug = dirname( $file );
        if ( ! preg_match( '/^taxocommerce-[a-z0-9-]+$/D', $slug ) || basename( $file ) !== $slug . '.php' ) {
            return self::finding( 'warning', __( 'Archivo principal no estándar; revisión manual necesaria.', 'taxocommerce-system-check' ) );
        }
        $path = WP_PLUGIN_DIR . '/' . $file;
        if ( ! is_readable( $path ) || ! is_file( $path ) ) {
            return self::finding( 'warning', __( 'El archivo principal no está disponible.', 'taxocommerce-system-check' ) );
        }
        $bytes = file_get_contents( $path, false, null, 0, 65536 );
        if ( ! is_string( $bytes ) ) {
            return self::finding( 'warning', __( 'No se pudo leer el archivo principal.', 'taxocommerce-system-check' ) );
        }
        $checks = array(
            'version'      => '' !== $plugin['version'],
            'text_domain'  => $plugin['domain'] === $slug,
            'access_guard' => (bool) preg_match( '/(?:defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)|\bABSPATH\b)/', $bytes ),
            'readme'       => is_readable( WP_PLUGIN_DIR . '/' . $slug . '/readme.txt' ),
        );
        $missing = array_keys( array_filter( $checks, static function ( $passed ) {
            return ! $passed;
        } ) );
        return self::finding(
            $missing ? 'warning' : 'pass',
            $missing
                ? __( 'Hay requisitos de estructura que necesitan revisión.', 'taxocommerce-system-check' )
                : __( 'Cabecera, dominio, protección de acceso y readme detectados.', 'taxocommerce-system-check' ),
            array( 'checks' => $checks, 'missing' => $missing, 'scope' => 'entrypoint_only_no_php_lint' )
        );
    }

    /** Checks actual loaded plugin entrypoint; does not invoke business logic. */
    public static function runtime( $file, $plugin ) {
        if ( ! $plugin['active'] ) {
            return self::finding( 'skip', __( 'Plugin inactivo. No se ejecutan sus servicios.', 'taxocommerce-system-check' ), array( 'autotest' => 'not_executed' ) );
        }
        $expected = wp_normalize_path( WP_PLUGIN_DIR . '/' . $file );
        $included = array_map( 'wp_normalize_path', get_included_files() );
        if ( ! in_array( $expected, $included, true ) ) {
            return self::finding( 'warning', __( 'WordPress indica que está activo, pero su archivo principal no figura cargado en esta petición.', 'taxocommerce-system-check' ), array( 'autotest' => 'not_executed' ) );
        }
        return self::finding(
            'pass',
            __( 'Archivo principal cargado durante esta petición. No equivale a una prueba funcional completa.', 'taxocommerce-system-check' ),
            array( 'autotest' => 'not_executed', 'scope' => 'loaded_entrypoint_only' )
        );
    }

    /** The official checker is external: never synthesize its verdict. */
    public static function official() {
        return self::finding( 'not_run', __( 'Sin ejecutar. Utiliza Herramientas > Plugin Check o el pipeline oficial de desarrollo.', 'taxocommerce-system-check' ) );
    }

    public static function check( $file, $plugin ) {
        return array(
            'static'   => self::inspect( $file, $plugin ),
            'official' => self::official(),
            'runtime'  => self::runtime( $file, $plugin ),
        );
    }

    public static function process( $state, $current ) {
        if ( 'running' !== $state['status'] ) {
            return $state;
        }
        $files = array_keys( $state['plugins'] );
        $speeds = self::speeds();
        $speed = isset( $speeds[ $state['speed'] ] ) ? $state['speed'] : 3;
        $batch = $speeds[ $speed ]['batch'];
        $total = count( $files );
        for ( $i = 0; $i < $batch && $state['cursor'] < $total; ++$i ) {
            $file = $files[ $state['cursor'] ];
            if ( ! isset( $current[ $file ] ) ) {
                $checks = array(
                    'static' => self::finding( 'skip', __( 'Plugin desinstalado durante la comprobación.', 'taxocommerce-system-check' ) ),
                    'official' => self::official(),
                    'runtime' => self::finding( 'skip', __( 'No disponible.', 'taxocommerce-system-check' ) ),
                );
            } else {
                try {
                    $checks = self::check( $file, $current[ $file ] );
                } catch ( Throwable $error ) {
                    $checks = array(
                        'static' => self::finding( 'warning', __( 'La inspección no se completó; consulta el registro del servidor.', 'taxocommerce-system-check' ) ),
                        'official' => self::official(),
                        'runtime' => self::finding( 'skip', __( 'No comprobado.', 'taxocommerce-system-check' ) ),
                    );
                }
            }
            $state['results'][ $file ] = $checks;
            ++$state['cursor'];
        }
        if ( $state['cursor'] >= $total ) {
            $state['status'] = 'completed';
        }
        $state['updated_at'] = gmdate( 'c' );
        return $state;
    }

    private static function return_to_tab() {
        wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-system-check&tcs_tab=family' ) );
        exit;
    }

    public static function handle() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No autorizado.', 'taxocommerce-system-check' ) );
        }
        check_admin_referer( self::ACTION );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
        $operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] )
            ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
        $state = self::latest();
        if ( 'start' === $operation ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
            $requested = isset( $_POST['speed'] ) ? absint( wp_unslash( $_POST['speed'] ) ) : 3;
            $speed = isset( self::speeds()[ $requested ] ) ? $requested : 3;
            $state = array(
                'schema' => 1,
                'started_at' => gmdate( 'c' ),
                'updated_at' => gmdate( 'c' ),
                'speed' => $speed,
                'status' => 'running',
                'cursor' => 0,
                'plugins' => self::inventory(),
                'results' => array(),
            );
            if ( ! $state['plugins'] ) {
                $state['status'] = 'completed';
            }
        } elseif ( is_array( $state ) && 'pause' === $operation ) {
            $state['status'] = 'paused';
        } elseif ( is_array( $state ) && 'resume' === $operation && 'paused' === $state['status'] ) {
            $state['status'] = 'running';
        } elseif ( is_array( $state ) && 'step' === $operation ) {
            $state = self::process( $state, self::inventory() );
        }
        if ( is_array( $state ) ) {
            set_transient( self::key(), $state, self::TTL );
        }
        self::return_to_tab();
    }

    private static function status_label( $finding ) {
        $names = array(
            'pass' => __( 'Correcto', 'taxocommerce-system-check' ),
            'warning' => __( 'Revisar', 'taxocommerce-system-check' ),
            'skip' => __( 'No aplicable', 'taxocommerce-system-check' ),
            'not_run' => __( 'No ejecutado', 'taxocommerce-system-check' ),
        );
        $status = is_array( $finding ) ? ( $finding['status'] ?? 'not_run' ) : 'not_run';
        return $names[ $status ] ?? $names['not_run'];
    }

    private static function form( $operation, $label, $primary = false, $extra = array(), $attributes = '' ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $attributes . '>';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="operation" value="' . esc_attr( $operation ) . '">';
        foreach ( $extra as $name => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
        }
        wp_nonce_field( self::ACTION );
        echo '<button class="button ' . ( $primary ? 'button-primary' : 'button-secondary' ) . '" type="submit">' . esc_html( $label ) . '</button></form>';
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No autorizado.', 'taxocommerce-system-check' ) );
        }
        $state = self::latest();
        $plugins = self::inventory();
        $speeds = self::speeds();
        echo '<div class="wrap tcs-family-check">';
        echo '<h2>' . esc_html__( 'Chequeo de plugins TaxoCommerce instalados', 'taxocommerce-system-check' ) . '</h2>';
        echo '<p>' . esc_html__( 'Comprueba todos los módulos instalados, también los inactivos, en lotes limitados. No modifica productos, opciones de otros plugins ni ejecuta servicios externos.', 'taxocommerce-system-check' ) . '</p>';
        echo '<p>' . esc_html__( 'Estático: cabeceras y archivos esenciales, no PHP Lint completo. Ejecución: confirma la carga del plugin, no ejecuta procesos comerciales ni Autotest de otros plugins. Plugin Check oficial: requiere ejecutarlo en su propia herramienta; aquí aparece como No ejecutado.', 'taxocommerce-system-check' ) . '</p>';
        echo '<div class="notice notice-info inline"><p>';
        if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'plugin-check/plugin.php' ) ) {
            echo '<a href="' . esc_url( admin_url( 'tools.php?page=plugin-check' ) ) . '">' . esc_html__( 'Abrir WordPress Plugin Check oficial', 'taxocommerce-system-check' ) . '</a>';
        } else {
            echo esc_html__( 'WordPress Plugin Check no está activo; puedes instalarlo opcionalmente desde el directorio de WordPress.', 'taxocommerce-system-check' );
        }
        echo '</p></div>';
        echo '<div class="tcs-family-controls">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="operation" value="start">';
        wp_nonce_field( self::ACTION );
        echo '<label for="tcs-family-speed">' . esc_html__( 'Velocidad de CPU', 'taxocommerce-system-check' ) . '</label> ';
        echo '<select id="tcs-family-speed" name="speed">';
        foreach ( $speeds as $id => $info ) {
            $selected = is_array( $state ) ? (int) $state['speed'] === $id : 3 === $id;
            echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $selected, true, false ) . '>' . esc_html( $info['label'] ) . '</option>';
        }
        echo '</select> ';
        submit_button( __( 'Iniciar nuevo chequeo', 'taxocommerce-system-check' ), 'primary', 'submit', false );
        echo '</form>';
        if ( is_array( $state ) ) {
            echo '<p><strong>' . esc_html__( 'Estado:', 'taxocommerce-system-check' ) . '</strong> ' . esc_html( $state['status'] ) . ' · '
                . esc_html( (string) $state['cursor'] ) . ' / ' . esc_html( (string) count( $state['plugins'] ) ) . '</p>';
            if ( 'running' === $state['status'] ) {
                self::form( 'pause', __( 'Detener', 'taxocommerce-system-check' ), false, array(), 'data-tcs-pause="1"' );
                echo '<form id="tcs-family-next" data-delay="' . esc_attr( (string) $speeds[ $state['speed'] ]['delay'] ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="operation" value="step">';
                wp_nonce_field( self::ACTION );
                echo '<noscript><button type="submit" class="button">' . esc_html__( 'Continuar lote', 'taxocommerce-system-check' ) . '</button></noscript></form>';
            } elseif ( 'paused' === $state['status'] ) {
                self::form( 'resume', __( 'Reanudar', 'taxocommerce-system-check' ), true );
            }
            if ( $state['results'] ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT_ACTION ) . '">';
                wp_nonce_field( self::EXPORT_ACTION );
                submit_button( __( 'Descargar resultados JSON', 'taxocommerce-system-check' ), 'secondary', 'submit', false );
                echo '</form>';
            }
        }
        echo '</div>';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Plugin', 'taxocommerce-system-check' ) . '</th><th>'
            . esc_html__( 'Versión', 'taxocommerce-system-check' ) . '</th><th>' . esc_html__( 'Instalación', 'taxocommerce-system-check' )
            . '</th><th>' . esc_html__( 'Estático', 'taxocommerce-system-check' ) . '</th><th>'
            . esc_html__( 'Plugin Check oficial', 'taxocommerce-system-check' ) . '</th><th>'
            . esc_html__( 'Ejecución', 'taxocommerce-system-check' ) . '</th></tr></thead><tbody>';
        foreach ( $plugins as $file => $plugin ) {
            $row = is_array( $state ) ? ( $state['results'][ $file ] ?? array() ) : array();
            echo '<tr><th scope="row">' . esc_html( $plugin['name'] ) . '</th><td>' . esc_html( $plugin['version'] )
                . '</td><td>' . esc_html( $plugin['active'] ? __( 'Activo', 'taxocommerce-system-check' ) : __( 'Inactivo', 'taxocommerce-system-check' ) ) . '</td>';
            foreach ( array( 'static', 'official', 'runtime' ) as $type ) {
                $result = $row[ $type ] ?? false;
                $description = is_array( $result ) ? $result['message'] : __( 'Sin ejecutar', 'taxocommerce-system-check' );
                echo '<td><strong>' . esc_html( self::status_label( $result ) ) . '</strong><br><small>' . esc_html( $description ) . '</small></td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p class="description">' . esc_html__( 'Los resultados no ejecutados no cuentan como correctos. El último informe es temporal y exclusivo del administrador y el sitio actual.', 'taxocommerce-system-check' ) . '</p></div>';
    }

    public static function export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No autorizado.', 'taxocommerce-system-check' ) );
        }
        check_admin_referer( self::EXPORT_ACTION );
        $report = self::latest();
        if ( ! is_array( $report ) || ! $report['results'] ) {
            wp_die( esc_html__( 'Ejecuta primero el chequeo.', 'taxocommerce-system-check' ) );
        }
        $report['plugin'] = 'taxocommerce-system-check';
        $report['report_type'] = 'taxocommerce_family_audit';
        $report['official_plugin_check_executed'] = false;
        $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
        if ( ! is_string( $json ) ) {
            wp_die( esc_html__( 'Error al generar JSON.', 'taxocommerce-system-check' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Disposition: attachment; filename="taxocommerce-family-check-' . gmdate( 'Ymd-His' ) . '.json"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response.
        echo $json;
        exit;
    }
}
