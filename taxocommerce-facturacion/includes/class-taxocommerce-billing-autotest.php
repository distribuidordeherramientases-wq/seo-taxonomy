<?php
/**
 * Diagnostics for the existing Billing services. No records or PDFs are created.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Billing_Autotest {
    private static function row( &$rows, $group, $name, $status, $detail ) {
        $rows[] = array(
            'group' => $group,
            'name' => $name,
            'status' => $status,
            'detail' => $detail,
        );
    }

    public static function run() {
        global $wpdb;
        $rows = array();

        self::row( $rows, 'Sistema', 'Plugin y versión',
            defined( 'TAXOCOMMERCE_FACTURACION_VERSION' ) ? 'ok' : 'error',
            defined( 'TAXOCOMMERCE_FACTURACION_VERSION' ) ? 'Billing ' . TAXOCOMMERCE_FACTURACION_VERSION . ' cargado.' : 'No se encontró la versión del plugin.' );
        $woo = class_exists( 'WooCommerce', false ) && function_exists( 'wc_get_order' );
        self::row( $rows, 'Sistema', 'WooCommerce', $woo ? 'ok' : 'warning',
            $woo ? 'WooCommerce y la API de pedidos están disponibles.' : 'WooCommerce no está operativo; los documentos de pedidos requieren este servicio.' );

        $services = array(
            'SEO_Facturas_Settings',
            'SEO_Facturas_Admin',
            'SEO_Facturas_Install',
            'SEO_Facturas_Documents',
            'SEO_Facturas_Snapshot',
            'SEO_Facturas_Tax',
            'SEO_Facturas_PDF',
            'SEO_Facturas_Quotes',
            'SEO_Facturas_WooCommerce',
            'SEO_Facturas_Customer_Documents',
        );
        $missing = array();
        foreach ( $services as $service ) {
            if ( ! class_exists( $service, false ) ) {
                $missing[] = $service;
            }
        }
        self::row( $rows, 'Componentes', 'Clases del módulo',
            empty( $missing ) ? 'ok' : 'error',
            empty( $missing ) ? 'Los 10 componentes heredados están disponibles.' : 'No se han cargado: ' . implode( ', ', $missing ) . '.' );

        $actions = array(
            'admin_post_seo_facturas_generate' => 'handle_generate',
            'admin_post_seo_facturas_download' => 'handle_download',
            'admin_post_seo_facturas_email'    => 'handle_email',
        );
        $missing = array();
        foreach ( $actions as $hook => $method ) {
            if ( false === has_action( $hook, array( 'SEO_Facturas_Admin', $method ) ) ) {
                $missing[] = $hook;
            }
        }
        self::row( $rows, 'Administración', 'Acciones de documento',
            empty( $missing ) ? 'ok' : 'warning',
            empty( $missing ) ? 'Los tres controladores están registrados; no se invocan.' : 'Sin registrar: ' . implode( ', ', $missing ) . '.' );

        $options = array(
            'seo_facturas_company_settings'  => 'Empresa',
            'seo_facturas_tax_settings'      => 'Fiscalidad',
            'seo_facturas_invoice_settings'  => 'Facturas',
            'seo_facturas_proforma_settings' => 'Proformas',
            'seo_facturas_quote_settings'    => 'Presupuestos',
        );
        $invalid = array();
        $not_saved = array();
        foreach ( $options as $key => $label ) {
            $stored = get_option( $key, false );
            if ( false === $stored ) {
                $not_saved[] = $label;
            } elseif ( ! is_array( $stored ) ) {
                $invalid[] = $label;
            }
        }
        self::row( $rows, 'Configuración', 'Opciones del plugin',
            $invalid ? 'error' : ( $not_saved ? 'warning' : 'ok' ),
            $invalid ? 'Opciones con formato incorrecto: ' . implode( ', ', $invalid ) . '.' :
            ( $not_saved ? 'Sin configuración guardada: ' . implode( ', ', $not_saved ) . '. Puede aplicarse el valor predeterminado.' : 'Las cinco opciones guardadas contienen estructuras válidas.' ) );
        $company = get_option( 'seo_facturas_company_settings', array() );
        $enabled = is_array( $company ) && ! empty( $company['enabled'] );
        self::row( $rows, 'Configuración', 'Activación documental',
            $enabled ? 'ok' : 'warning',
            $enabled ? 'Sistema documental habilitado en su configuración.' : 'Sistema documental desactivado o todavía sin configurar.' );

        $table = ( class_exists( 'SEO_Facturas_Install', false ) && method_exists( 'SEO_Facturas_Install', 'table_name' ) )
            ? SEO_Facturas_Install::table_name() : '';
        if ( ! $table || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) ) {
            self::row( $rows, 'Persistencia', 'Registro de documentos', 'error', 'No está disponible el nombre de tabla o la conexión de WordPress.' );
        } else {
            // The only database query in Autotest: read-only schema inspection.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit admin-only check of the live schema; caching can mask a missing table.
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            self::row( $rows, 'Persistencia', 'Registro de documentos',
                $exists === $table ? 'ok' : 'error',
                $exists === $table ? 'Tabla de documentos disponible; no se consultan ni alteran facturas.' : 'No se encontró la tabla esperada; revisar la instalación/migración.' );
        }

        $pdf_class = class_exists( 'Dompdf\\Dompdf', false );
        $pdf_path = defined( 'SEO_FACTURAS_PATH' ) ? SEO_FACTURAS_PATH : '';
        $autoload = $pdf_path && (
            is_readable( $pdf_path . 'vendor/autoload.php' ) ||
            is_readable( $pdf_path . 'dompdf/autoload.inc.php' ) ||
            is_readable( $pdf_path . 'dompdf/vendor/autoload.php' )
        );
        self::row( $rows, 'Documentos PDF', 'Motor Dompdf',
            $pdf_class || $autoload ? 'ok' : 'warning',
            $pdf_class ? 'Clase Dompdf cargada; no se genera ningún documento.' :
                ( $autoload ? 'Autocargador Dompdf localizado; no se carga ni genera un PDF.' : 'No se ha encontrado el motor PDF en las rutas configuradas.' ) );

        $woo_hooks = array(
            'woocommerce_payment_complete'    => 'on_payment_complete',
            'woocommerce_order_status_changed' => 'on_status_changed',
        );
        $hooks_missing = array();
        foreach ( $woo_hooks as $hook => $method ) {
            if ( false === has_action( $hook, array( 'SEO_Facturas_WooCommerce', $method ) ) ) {
                $hooks_missing[] = $hook;
            }
        }
        self::row( $rows, 'WooCommerce', 'Eventos de pedidos',
            $woo && empty( $hooks_missing ) ? 'ok' : 'warning',
            $woo && empty( $hooks_missing ) ? 'Eventos registrados; no se simulan pagos ni cambios de estado.' :
                'No están disponibles todos los eventos de pedidos; revisar WooCommerce y el bootstrap.' );
        $quote_hook = false !== has_filter( 'render_block_woocommerce/cart', array( 'SEO_Facturas_Quotes', 'append_to_cart_block' ) );
        $client_hook = false !== has_action( 'woocommerce_view_order', array( 'SEO_Facturas_Customer_Documents', 'render_for_order' ) );
        self::row( $rows, 'WooCommerce', 'Presupuestos y documentos del cliente',
            $woo && $quote_hook && $client_hook ? 'ok' : 'warning',
            $woo && $quote_hook && $client_hook ? 'Hooks de carrito y cuenta disponibles; no se crea ningún documento.' : 'Alguna integración de carrito/cuenta no está registrada.' );

        return $rows;
    }

    public static function render() {
        if ( \TaxoCommerce_Facturacion_Autotest_Code::render_subtabs() ) { return; }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos para el Autocheck.', 'taxocommerce-facturacion' ) );
        }
        echo '<h2>' . esc_html__( 'Autocheck de Billing', 'taxocommerce-facturacion' ) . '</h2>';
        echo '<p>' . esc_html__( 'Comprobaciones de disponibilidad y configuración. No se crean facturas, proformas ni presupuestos, no se generan PDF, no se envían correos y no se modifica ningún pedido.', 'taxocommerce-facturacion' ) . '</p>';
        echo '<form method="post">';
        wp_nonce_field( 'taxocommerce_billing_autotest' );
        echo '<input type="hidden" name="taxocommerce_billing_run" value="1">';
        submit_button( 'Ejecutar Autocheck', 'secondary', 'submit', false );
        echo '</form>';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked immediately before any diagnostics.
        if ( ! isset( $_POST['taxocommerce_billing_run'] ) ) {
            echo '<p class="description">' . esc_html__( 'Pulsa el botón para realizar las comprobaciones. El último resultado se conserva una hora para descargar el JSON.', 'taxocommerce-facturacion' ) . '</p>';
            return;
        }
        check_admin_referer( 'taxocommerce_billing_autotest' );
        $rows = self::run();
        $autocheck_totals = array();
        foreach ( $rows as $autocheck_item ) {
            $autocheck_status = (string) ( $autocheck_item['status'] ?? 'unknown' );
            $autocheck_totals[ $autocheck_status ] = ( $autocheck_totals[ $autocheck_status ] ?? 0 ) + 1;
        }
        $autocheck_report = array(
            'schema' => 1,
            'plugin' => 'taxocommerce-facturacion',
            'version' => defined( 'TAXOCOMMERCE_FACTURACION_VERSION' ) ? constant( 'TAXOCOMMERCE_FACTURACION_VERSION' ) : '',
            'generated_at' => gmdate( 'c' ), 'executed_at' => gmdate( 'c' ),
            'totals' => $autocheck_totals,
            'rows' => $rows,
        );
        if ( function_exists( 'set_transient' ) && function_exists( 'get_current_blog_id' ) && function_exists( 'get_current_user_id' ) ) {
            set_transient( 'taxocommerce_facturacion_autocheck_latest_' . get_current_blog_id() . '_' . get_current_user_id(), $autocheck_report, HOUR_IN_SECONDS );
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="taxocommerce_facturacion_autocheck_json">';
        wp_nonce_field( 'taxocommerce_facturacion_autocheck_json' );
        submit_button( __( 'Descargar resultados (JSON)', 'taxocommerce-facturacion' ), 'secondary', 'submit', false );
        echo '<p><strong>' . esc_html__( 'Fecha de ejecución:', 'taxocommerce-facturacion' ) . '</strong> ' . esc_html( ( function_exists( 'wp_date' ) ? wp_date( 'd/m/Y H:i:s T', strtotime( (string) $autocheck_report['executed_at'] ) ) : gmdate( 'd/m/Y H:i:s', strtotime( (string) $autocheck_report['executed_at'] ) ) . ' UTC' ) ) . '</p>';
        echo '</form>';
        $counts = array( 'ok' => 0, 'warning' => 0, 'error' => 0 );
        foreach ( $rows as $row ) {
            $counts[ $row['status'] ]++;
        }
        echo '<h3>Resumen</h3><p>' . esc_html( (string) count( $rows ) ) . ' comprobaciones: ' .
            esc_html( (string) $counts['ok'] ) . ' correctas, ' .
            esc_html( (string) $counts['warning'] ) . ' avisos y ' .
            esc_html( (string) $counts['error'] ) . ' errores.</p>';

        $group = '';
        foreach ( $rows as $row ) {
            if ( $row['group'] !== $group ) {
                if ( $group ) {
                    echo '</tbody></table>';
                }
                $group = $row['group'];
                echo '<h3>' . esc_html( $group ) . '</h3><table class="widefat striped"><thead><tr><th>Control</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';
            }
            $names = array( 'ok' => 'Correcto', 'warning' => 'Aviso', 'error' => 'Error' );
            echo '<tr><th scope="row">' . esc_html( $row['name'] ) . '</th><td>' .
                esc_html( $names[ $row['status'] ] ) . '</td><td>' . esc_html( $row['detail'] ) . '</td></tr>';
        }
        if ( $group ) {
            echo '</tbody></table>';
        }
        echo '<p class="description">' . esc_html__( 'Es un diagnóstico de disponibilidad: no valida cálculos fiscales, PDF emitidos, entrega de emails ni permisos efectivos de clientes.', 'taxocommerce-facturacion' ) . '</p>';
    }
}


/**
 * Download the current user's most recent explicit diagnostic run.
 * Never execute an import, export, connection or worker during download.
 */
function taxocommerce_facturacion_autocheck_export_json() {
    if ( ! ( current_user_can( 'manage_options' ) ) ) {
        wp_die( esc_html__( 'Sin permisos para descargar el Autocheck.', 'taxocommerce-facturacion' ) );
    }
    check_admin_referer( 'taxocommerce_facturacion_autocheck_json' );
    $report = get_transient( 'taxocommerce_facturacion_autocheck_latest_' . get_current_blog_id() . '_' . get_current_user_id() );
    if ( ! is_array( $report ) || ! isset( $report['rows'], $report['totals'] ) ) {
        wp_die( esc_html__( 'Ejecuta el Autocheck antes de descargar el JSON.', 'taxocommerce-facturacion' ) );
    }
    $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
    if ( ! is_string( $json ) ) {
        wp_die( esc_html__( 'No se pudo generar el informe JSON.', 'taxocommerce-facturacion' ) );
    }
    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'X-Content-Type-Options: nosniff' );
    header( 'Content-Disposition: attachment; filename="taxocommerce-facturacion-autocheck-' . gmdate( 'Ymd-His' ) . '.json"' );
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The response is a JSON attachment.
    echo $json;
    exit;
}
if ( function_exists( 'add_action' ) ) {
    add_action( 'admin_post_taxocommerce_facturacion_autocheck_json', 'taxocommerce_facturacion_autocheck_export_json' );
}
