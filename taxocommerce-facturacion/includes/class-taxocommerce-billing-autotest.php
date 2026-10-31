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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos para el Autotest.', 'taxocommerce-facturacion' ) );
        }
        echo '<h2>Autotest de Billing</h2>';
        echo '<p>Comprobaciones de disponibilidad y configuración. No se crean facturas, proformas ni presupuestos, no se generan PDF, no se envían correos y no se modifica ningún pedido.</p>';
        echo '<form method="post">';
        wp_nonce_field( 'taxocommerce_billing_autotest' );
        echo '<input type="hidden" name="taxocommerce_billing_run" value="1">';
        submit_button( 'Ejecutar autotest', 'secondary', 'submit', false );
        echo '</form>';

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked immediately before any diagnostics.
        if ( ! isset( $_POST['taxocommerce_billing_run'] ) ) {
            echo '<p class="description">Pulsa el botón para realizar las comprobaciones. El resultado no se guarda.</p>';
            return;
        }
        check_admin_referer( 'taxocommerce_billing_autotest' );
        $rows = self::run();
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
        echo '<p class="description">Es un diagnóstico de disponibilidad: no valida cálculos fiscales, PDF emitidos, entrega de emails ni permisos efectivos de clientes.</p>';
    }
}
