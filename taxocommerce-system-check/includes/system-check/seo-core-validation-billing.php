<?php
/**
 * Chequeos pasivos del modulo de facturas, proformas y presupuestos.
 *
 * No genera documentos, no reserva numeracion, no crea pedidos y no modifica
 * opciones ni tablas. Solo inspecciona carga, configuracion, hooks, esquema y
 * documentos ya emitidos.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_core_system_test_billing')) {
    function seo_core_system_test_billing() {
        $results = array();
        $module_loaded = defined('SEO_FACTURAS_VERSION')
            && class_exists('SEO_Facturas_Settings')
            && class_exists('SEO_Facturas_Install')
            && class_exists('SEO_Facturas_Documents')
            && class_exists('SEO_Facturas_PDF')
            && class_exists('SEO_Facturas_WooCommerce')
            && class_exists('SEO_Facturas_Quotes')
            && class_exists('SEO_Facturas_Admin');

        $required_classes = array(
            'SEO_Facturas_Settings',
            'SEO_Facturas_Install',
            'SEO_Facturas_Documents',
            'SEO_Facturas_PDF',
            'SEO_Facturas_WooCommerce',
            'SEO_Facturas_Quotes',
            'SEO_Facturas_Admin',
        );
        $missing_classes = array_values(array_filter($required_classes, static function ($class) {
            return !class_exists($class);
        }));

        $module_detail = $module_loaded
            ? 'Modulo cargado. Version: ' . (defined('SEO_FACTURAS_VERSION') ? SEO_FACTURAS_VERSION : 'desconocida') . '. Clases requeridas: ' . count($required_classes) . '/' . count($required_classes) . '.'
            : 'Modulo incompleto o no cargado. Clases ausentes: ' . (empty($missing_classes) ? 'ninguna identificada; revisar bootstrap' : implode(', ', $missing_classes)) . '.';

        $results[] = seo_core_system_test_result(
            'checkout',
            '4.7 Modulo de facturacion cargado',
            $module_loaded,
            $module_detail,
            $module_loaded ? 'ok' : 'ko',
            array(
                'owner' => 'Woo',
                'area' => 'billing',
                'confidence' => 99,
                'evidence' => array(
                    'version' => defined('SEO_FACTURAS_VERSION') ? SEO_FACTURAS_VERSION : '',
                    'required_classes' => $required_classes,
                    'missing_classes' => $missing_classes,
                ),
                'remediation' => array(
                    'kind' => 'Carga del modulo',
                    'summary' => 'El bootstrap de facturacion y todas sus clases deben estar disponibles antes de ejecutar la validacion.',
                    'steps' => array(
                        'Comprueba includes/facturas/seo-facturas-bootstrap.php y los archivos de clase del modulo.',
                        'Verifica que includes/seo-admin.php cargue el bootstrap de facturas.',
                        'Vuelve a ejecutar la validacion despues de corregir cualquier archivo ausente.',
                    ),
                ),
            )
        );

        if (!$module_loaded) {
            foreach (array(
                '4.8 Tabla documental preparada',
                '4.9 Datos comunes de empresa',
                '4.10 Series documentales coherentes',
                '4.11 Motor PDF disponible',
                '4.12 Hooks de factura y proforma',
                '4.13 Emails documentales resolubles',
                '4.14 Presupuestos de carrito preparados',
                '4.15 Configuracion documental coherente',
                '4.16 Integridad de documentos emitidos',
            ) as $label) {
                $results[] = seo_core_system_test_result(
                    'checkout',
                    $label,
                    false,
                    'No evaluable porque el modulo de facturacion no esta cargado completamente.',
                    'info',
                    array(
                        'owner' => 'Woo',
                        'area' => 'billing',
                        'status' => 'not_evaluable',
                        'blocked_by' => 'billing_module_unavailable',
                        'coverage' => 0,
                        'confidence' => 100,
                    )
                );
            }
            return $results;
        }

        $settings = SEO_Facturas_Settings::all();
        $master_enabled = !empty($settings['enabled']);
        $invoice_enabled = !empty($settings['invoice_enabled']);
        $proforma_enabled = !empty($settings['proforma_enabled']);
        $quote_enabled = !empty($settings['quote_enabled']);

        $results[] = seo_core_system_test_billing_table_check();
        $results[] = seo_core_system_test_billing_company_check($settings, $master_enabled);
        $results[] = seo_core_system_test_billing_series_check($settings);
        $results[] = seo_core_system_test_billing_pdf_check($master_enabled, $invoice_enabled, $proforma_enabled, $quote_enabled);
        $results[] = seo_core_system_test_billing_hooks_check($master_enabled, $invoice_enabled, $proforma_enabled);
        $results[] = seo_core_system_test_billing_email_check($settings, $master_enabled);
        $results[] = seo_core_system_test_billing_quote_check($settings, $master_enabled, $quote_enabled);
        $results[] = seo_core_system_test_billing_configuration_check($settings, $master_enabled);
        $results[] = seo_core_system_test_billing_documents_check();

        return $results;
    }
}

if (!function_exists('seo_core_system_test_billing_table_check')) {
    function seo_core_system_test_billing_table_check() {
        global $wpdb;

        $table = SEO_Facturas_Install::table_name();
        $exists = function_exists('seo_core_system_test_table_exists')
            ? seo_core_system_test_table_exists($table)
            : ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);

        if (!$exists) {
            return seo_core_system_test_result(
                'checkout',
                '4.8 Tabla documental preparada',
                false,
                'No existe la tabla documental ' . $table . '.',
                'ko',
                array(
                    'owner' => 'Woo',
                    'area' => 'billing',
                    'confidence' => 99,
                    'evidence' => array('table' => $table, 'exists' => false),
                    'remediation' => array(
                        'kind' => 'Esquema de base de datos',
                        'summary' => 'El modulo necesita su tabla documental para facturas y proformas.',
                        'steps' => array(
                            'Comprueba que SEO_Facturas_Install::maybe_upgrade() se ejecute al cargar el modulo.',
                            'Revisa permisos de base de datos y el valor seo_facturas_db_version.',
                            'No crees la tabla manualmente si puede repararla la migracion del modulo.',
                        ),
                    ),
                )
            );
        }

        $identifier = '`' . str_replace('`', '``', $table) . '`';
        $columns_raw = $wpdb->get_results('SHOW COLUMNS FROM ' . $identifier, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $indexes_raw = $wpdb->get_results('SHOW INDEX FROM ' . $identifier, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $columns = array();
        foreach ((array) $columns_raw as $column) {
            if (!empty($column['Field'])) {
                $columns[] = (string) $column['Field'];
            }
        }

        $required_columns = array(
            'id', 'order_id', 'document_type', 'series', 'sequence_number',
            'document_number', 'status', 'issued_at', 'order_status',
            'payment_method', 'snapshot', 'rendered_html', 'pdf_path',
            'file_hash', 'email_sent_at', 'last_error', 'created_at', 'updated_at',
        );
        $missing_columns = array_values(array_diff($required_columns, $columns));

        $index_map = array();
        foreach ((array) $indexes_raw as $index) {
            $name = isset($index['Key_name']) ? (string) $index['Key_name'] : '';
            if ($name === '') {
                continue;
            }
            if (!isset($index_map[$name])) {
                $index_map[$name] = array('unique' => isset($index['Non_unique']) && (int) $index['Non_unique'] === 0, 'columns' => array());
            }
            $seq = isset($index['Seq_in_index']) ? max(1, (int) $index['Seq_in_index']) : count($index_map[$name]['columns']) + 1;
            $index_map[$name]['columns'][$seq] = isset($index['Column_name']) ? (string) $index['Column_name'] : '';
        }
        foreach ($index_map as $name => $definition) {
            ksort($definition['columns']);
            $index_map[$name]['columns'] = array_values($definition['columns']);
        }

        $order_unique = false;
        $number_unique = false;
        foreach ($index_map as $definition) {
            if (empty($definition['unique'])) {
                continue;
            }
            if ($definition['columns'] === array('order_id', 'document_type')) {
                $order_unique = true;
            }
            if ($definition['columns'] === array('document_number')) {
                $number_unique = true;
            }
        }

        $passed = empty($missing_columns) && $order_unique && $number_unique;
        $detail = $passed
            ? 'Tabla disponible con ' . count($columns) . ' columnas y claves unicas de pedido/tipo y numero documental.'
            : 'Esquema documental incompleto. Columnas ausentes: ' . (empty($missing_columns) ? 'ninguna' : implode(', ', $missing_columns)) . '; UNIQUE pedido/tipo: ' . ($order_unique ? 'si' : 'no') . '; UNIQUE numero: ' . ($number_unique ? 'si' : 'no') . '.';

        return seo_core_system_test_result(
            'checkout',
            '4.8 Tabla documental preparada',
            $passed,
            $detail,
            $passed ? 'ok' : 'ko',
            array(
                'owner' => 'Woo',
                'area' => 'billing',
                'confidence' => 99,
                'evidence' => array(
                    'table' => $table,
                    'columns' => $columns,
                    'missing_columns' => $missing_columns,
                    'order_document_unique' => $order_unique,
                    'document_number_unique' => $number_unique,
                ),
                'remediation' => array(
                    'kind' => 'Esquema de base de datos',
                    'summary' => 'La tabla debe conservar la unicidad por pedido/tipo y por numero de documento.',
                    'steps' => array(
                        'Ejecuta la migracion propia del modulo mediante SEO_Facturas_Install::maybe_upgrade().',
                        'Comprueba que dbDelta puede crear o reparar indices.',
                        'No elimines documentos existentes para reparar el esquema.',
                    ),
                ),
            )
        );
    }
}

if (!function_exists('seo_core_system_test_billing_company_check')) {
    function seo_core_system_test_billing_company_check($settings, $master_enabled) {
        $settings = is_array($settings) ? $settings : array();
        if (!$master_enabled) {
            return seo_core_system_test_result(
                'checkout',
                '4.9 Datos comunes de empresa',
                true,
                'Sistema documental desactivado. Los datos fiscales se validaran cuando se active.',
                'info',
                array(
                    'owner' => 'Woo',
                    'area' => 'billing',
                    'status' => 'not_applicable',
                    'coverage' => 100,
                    'confidence' => 100,
                    'evidence' => array('enabled' => false),
                )
            );
        }

        $required = array(
            'company_name' => 'razon social',
            'company_tax_id' => 'NIF/CIF',
            'company_address' => 'direccion',
            'company_postcode' => 'codigo postal',
            'company_city' => 'localidad',
            'company_country' => 'pais',
        );
        $missing = array();
        foreach ($required as $key => $label) {
            if (trim((string) ($settings[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        $email = trim((string) ($settings['company_email'] ?? ''));
        $invalid_email = $email !== '' && !is_email($email);
        $passed = empty($missing) && !$invalid_email;
        $detail = $passed
            ? 'Identidad fiscal comun configurada para facturas, proformas y presupuestos.'
            : 'Revisar datos comunes. Faltan: ' . (empty($missing) ? 'ninguno' : implode(', ', $missing)) . ($invalid_email ? '; email no valido' : '') . '.';

        return seo_core_system_test_result(
            'checkout',
            '4.9 Datos comunes de empresa',
            $passed,
            $detail,
            $passed ? 'ok' : 'warning',
            array(
                'owner' => 'Woo',
                'area' => 'billing',
                'confidence' => 98,
                'evidence' => array(
                    'enabled' => true,
                    'missing_fields' => $missing,
                    'email_configured' => $email !== '',
                    'email_valid' => !$invalid_email,
                    'logo_configured' => !empty($settings['logo_id']),
                ),
                'remediation' => array(
                    'kind' => 'Configuracion fiscal',
                    'summary' => 'Los tres tipos de documento comparten una unica identidad del vendedor.',
                    'steps' => array(
                        'Completa Datos empresa en Herramientas > Facturas y presupuestos.',
                        'Configura razon social, NIF/CIF y direccion antes de emitir documentos reales.',
                        'El logo, telefono y web son opcionales para este chequeo.',
                    ),
                ),
            )
        );
    }
}

if (!function_exists('seo_core_system_test_billing_series_check')) {
    function seo_core_system_test_billing_series_check($settings) {
        $settings = is_array($settings) ? $settings : array();
        $series = array(
            'invoice' => strtoupper(trim((string) ($settings['invoice_series'] ?? ''))),
            'proforma' => strtoupper(trim((string) ($settings['proforma_series'] ?? ''))),
            'quote' => strtoupper(trim((string) ($settings['quote_series'] ?? ''))),
        );
        $nonempty = count(array_filter($series, static function ($value) { return $value !== ''; })) === 3;
        $unique = count(array_unique(array_values($series))) === 3;
        $valid = true;
        foreach ($series as $value) {
            if ($value === '' || !preg_match('/^[A-Z0-9_-]{1,20}$/', $value)) {
                $valid = false;
                break;
            }
        }
        $passed = $nonempty && $unique && $valid;
        $detail = $passed
            ? 'Series separadas: factura ' . $series['invoice'] . ', proforma ' . $series['proforma'] . ', presupuesto ' . $series['quote'] . '.'
            : 'Series no validas o repetidas: factura=' . $series['invoice'] . ', proforma=' . $series['proforma'] . ', presupuesto=' . $series['quote'] . '.';

        return seo_core_system_test_result(
            'checkout',
            '4.10 Series documentales coherentes',
            $passed,
            $detail,
            $passed ? 'ok' : 'ko',
            array(
                'owner' => 'Woo',
                'area' => 'billing',
                'confidence' => 100,
                'evidence' => array('series' => $series, 'unique' => $unique, 'format_valid' => $valid),
                'remediation' => array(
                    'kind' => 'Numeracion documental',
                    'summary' => 'FAC, PRO y PRE deben usar series distintas para evitar colisiones.',
                    'steps' => array(
                        'Abre las pestañas Facturas, Proformas y Presupuestos.',
                        'Asigna una serie distinta a cada tipo de documento.',
                        'No reutilices una serie ya usada para otro tipo en el mismo ejercicio.',
                    ),
                ),
            )
        );
    }
}

if (!function_exists('seo_core_system_test_billing_pdf_check')) {
    function seo_core_system_test_billing_pdf_check($master_enabled, $invoice_enabled, $proforma_enabled, $quote_enabled) {
        $status = SEO_Facturas_PDF::engine_status();
        $dompdf = !empty($status['available']);
        $custom_filter = function_exists('has_filter') ? has_filter('seo_facturas_pdf_binary') : false;
        $engine_available = $dompdf || false !== $custom_filter;
        $document_template = defined('SEO_FACTURAS_PATH') ? SEO_FACTURAS_PATH . 'templates/document.php' : '';
        $quote_template = defined('SEO_FACTURAS_PATH') ? SEO_FACTURAS_PATH . 'templates/quote.php' : '';
        $document_template_ok = $document_template !== '' && is_readable($document_template);
        $quote_template_ok = !$quote_enabled || ($quote_template !== '' && is_readable($quote_template));
        $available = $engine_available && $document_template_ok && $quote_template_ok;
        $needed = $master_enabled && ($invoice_enabled || $proforma_enabled || $quote_enabled);

        if ($available) {
            $detail = ($dompdf
                ? 'Motor PDF disponible: ' . (string) ($status['label'] ?? 'Dompdf')
                : 'Motor PDF externo detectado mediante seo_facturas_pdf_binary')
                . '; plantillas documentales legibles.';
            $severity = 'ok';
            $passed = true;
            $result_status = 'pass';
        } elseif ($needed) {
            $problems = array();
            if (!$engine_available) {
                $problems[] = 'motor PDF no disponible';
            }
            if (!$document_template_ok) {
                $problems[] = 'templates/document.php no legible';
            }
            if (!$quote_template_ok) {
                $problems[] = 'templates/quote.php no legible';
            }
            $detail = 'Generacion PDF no preparada: ' . implode('; ', $problems) . '.';
            $severity = 'ko';
            $passed = false;
            $result_status = 'fail';
        } else {
            $detail = 'Generacion PDF no exigida porque el sistema documental esta desactivado actualmente.';
            $severity = 'info';
            $passed = true;
            $result_status = 'not_applicable';
        }

        return seo_core_system_test_result(
            'checkout',
            '4.11 Motor PDF disponible',
            $passed,
            $detail,
            $severity,
            array(
                'owner' => 'Woo',
                'area' => 'billing',
                'status' => $result_status,
                'confidence' => $dompdf ? 100 : ($available ? 80 : 100),
                'evidence' => array(
                    'dompdf_available' => $dompdf,
                    'custom_pdf_filter' => false !== $custom_filter,
                    'document_template_readable' => $document_template_ok,
                    'quote_template_readable' => $quote_template_ok,
                    'documents_enabled' => $needed,
                ),
                'remediation' => array(
                    'kind' => 'Dependencia PDF',
                    'summary' => 'Facturas, proformas y presupuestos necesitan un motor que convierta HTML a PDF.',
                    'steps' => array(
                        'Ejecuta composer install --no-dev --optimize-autoloader dentro de includes/facturas/.',
                        'Comprueba que includes/facturas/vendor/autoload.php sea legible.',
                        'Si usas otro motor, verifica el filtro seo_facturas_pdf_binary.',
                    ),
                ),
            )
        );
    }
}

if (!function_exists('seo_core_system_test_billing_hooks_check')) {
    function seo_core_system_test_billing_hooks_check($master_enabled, $invoice_enabled, $proforma_enabled) {
        $hooks = array(
            'payment_complete' => function_exists('has_action') ? has_action('woocommerce_payment_complete', array('SEO_Facturas_WooCommerce', 'on_payment_complete')) : false,
            'status_changed' => function_exists('has_action') ? has_action('woocommerce_order_status_changed', array('SEO_Facturas_WooCommerce', 'on_status_changed')) : false,
            'email_attachments' => function_exists('has_filter') ? has_filter('woocommerce_email_attachments', array('SEO_Facturas_WooCommerce', 'email_attachments')) : false,
        );
        $missing = array();
        foreach ($hooks as $name => $priority) {
            if (false === $priority) {
                $missing[] = $name;
            }
        }
        $needed = $master_enabled && ($invoice_enabled || $proforma_enabled);
        $passed = empty($missing);
        $severity = $passed ? 'ok' : ($needed ? 'ko' : 'warning');
        $detail = $passed
            ? 'Hooks WooCommerce registrados para pago, cambio de estado y adjuntos de email.'
            : 'Faltan hooks del adaptador WooCommerce: ' . implode(', ', $missing) . '.';

        return seo_core_system_test_result(
            'checkout',
            '4.12 Hooks de factura y proforma',
            $passed,
            $detail,
            $severity,
            array(
                'owner' => 'Woo',
                'area' => 'billing',
                'confidence' => 99,
                'evidence' => array('hooks' => $hooks, 'required_now' => $needed),
                'remediation' => array(
                    'kind' => 'Integracion WooCommerce',
                    'summary' => 'La emision automatica depende de los eventos nativos de WooCommerce.',
                    'steps' => array(
                        'Comprueba que SEO_Facturas_WooCommerce::init() se haya ejecutado.',
                        'Verifica WooCommerce activo y wc_get_order disponible.',
                        'No dupliques los hooks en seo-admin.php; deben registrarse desde el modulo.',
                    ),
                ),
            )
        );
    }
}

if (!function_exists('seo_core_system_test_billing_email_check')) {
    function seo_core_system_test_billing_email_check($settings, $master_enabled) {
        $settings = is_array($settings) ? $settings : array();
        $configured = array();
        if ($master_enabled && !empty($settings['invoice_enabled']) && !empty($settings['invoice_attach_to_woo_emails'])) {
            $configured = array_merge($configured, seo_core_system_test_billing_csv_keys($settings['invoice_email_ids'] ?? ''));
        }
        if ($master_enabled && !empty($settings['proforma_enabled']) && !empty($settings['proforma_attach_to_woo_emails'])) {
            $configured = array_merge($configured, seo_core_system_test_billing_csv_keys($settings['proforma_email_ids'] ?? ''));
        }
        $configured = array_values(array_unique($configured));

        if (empty($configured)) {
            return seo_core_system_test_result(
                'checkout',
                '4.13 Emails documentales resolubles',
                true,
                'No hay adjuntos documentales activos que requieran validar IDs de correo WooCommerce.',
                'info',
                array(
                    'owner' => 'Woo',
                    'area' => 'billing',
                    'status' => 'not_applicable',
                    'confidence' => 100,
                    'evidence' => array('configured_email_ids' => array()),
                )
            );
        }

        if (!function_exists('WC') || !WC()) {
            return seo_core_system_test_result(
                'checkout',
                '4.13 Emails documentales resolubles',
                false,
                'WooCommerce no esta disponible para resolver los emails configurados.',
                'warning',
                array('owner' => 'Woo', 'area' => 'billing', 'confidence' => 90)
            );
        }

        try {
            $mailer = WC()->mailer();
            $emails = $mailer && method_exists($mailer, 'get_emails') ? $mailer->get_emails() : array();
        } catch (Throwable $throwable) {
            return seo_core_system_test_result(
                'checkout',
                '4.13 Emails documentales resolubles',
                false,
                'Error al cargar el registro de emails WooCommerce: ' . $throwable->getMessage(),
                'ko',
                array('owner' => 'Woo', 'area' => 'billing', 'confidence' => 99)
            );
        }

        $available = array();
        foreach ((array) $emails as $email) {
            if (is_object($email) && isset($email->id)) {
                $available[] = sanitize_key((string) $email->id);
            }
        }
        $available = array_values(array_unique($available));
        $missing = array_values(array_diff($configured, $available));
        $passed = empty($missing);
        $detail = $passed
            ? 'Todos los IDs de email configurados existen en WooCommerce: ' . implode(', ', $configured) . '.'
            : 'IDs de email configurados que WooCommerce no reconoce: ' . implode(', ', $missing) . '.';

        return seo_core_system_test_result(
            'checkout',
            '4.13 Emails documentales resolubles',
            $passed,
            $detail,
            $passed ? 'ok' : 'warning',
            array(
                'owner' => 'Woo',
                'area' => 'billing',
                'confidence' => 98,
                'evidence' => array(
                    'configured_email_ids' => $configured,
                    'available_email_ids' => $available,
                    'missing_email_ids' => $missing,
                ),
                'remediation' => array(
                    'kind' => 'Configuracion de emails',
                    'summary' => 'Los documentos solo pueden adjuntarse a IDs de email registrados por WooCommerce.',
                    'steps' => array(
                        'Revisa los IDs configurados en las pestañas Facturas y Proformas.',
                        'Usa IDs reales devueltos por WC_Emails, no nombres visibles traducidos.',
                        'Vuelve a ejecutar el chequeo despues de guardar.',
                    ),
                ),
            )
        );
    }
}

if (!function_exists('seo_core_system_test_billing_quote_check')) {
    function seo_core_system_test_billing_quote_check($settings, $master_enabled, $quote_enabled) {
        $settings = is_array($settings) ? $settings : array();
        if (!$master_enabled || !$quote_enabled) {
            return seo_core_system_test_result(
                'checkout',
                '4.14 Presupuestos de carrito preparados',
                true,
                'Presupuestos de carrito desactivados. No se exige su integracion publica.',
                'info',
                array(
                    'owner' => 'Woo',
                    'area' => 'billing',
                    'status' => 'not_applicable',
                    'confidence' => 100,
                    'evidence' => array('master_enabled' => $master_enabled, 'quote_enabled' => $quote_enabled),
                )
            );
        }

        $hooks = array(
            'classic_cart' => function_exists('has_action') ? has_action('woocommerce_proceed_to_checkout', array('SEO_Facturas_Quotes', 'render_cart_form')) : false,
            'cart_block' => function_exists('has_filter') ? has_filter('render_block_woocommerce/cart', array('SEO_Facturas_Quotes', 'append_to_cart_block')) : false,
            'template_redirect' => function_exists('has_action') ? has_action('template_redirect', array('SEO_Facturas_Quotes', 'handle_download')) : false,
            'shortcode' => function_exists('shortcode_exists') ? shortcode_exists('seo_facturas_presupuesto') : false,
        );
        $missing_hooks = array();
        foreach ($hooks as $name => $value) {
            if (false === $value) {
                $missing_hooks[] = $name;
            }
        }

        $files = array(
            'template' => defined('SEO_FACTURAS_PATH') ? SEO_FACTURAS_PATH . 'templates/quote.php' : '',
            'css' => defined('SEO_FACTURAS_PATH') ? SEO_FACTURAS_PATH . 'assets/frontend.css' : '',
            'js' => defined('SEO_FACTURAS_PATH') ? SEO_FACTURAS_PATH . 'assets/frontend.js' : '',
        );
        $missing_files = array();
        foreach ($files as $name => $path) {
            if ($path === '' || !is_readable($path)) {
                $missing_files[] = $name;
            }
        }

        $cart_id = function_exists('wc_get_page_id') ? (int) wc_get_page_id('cart') : 0;
        $cart_ready = function_exists('seo_core_system_test_valid_page_id')
            ? seo_core_system_test_valid_page_id($cart_id)
            : ($cart_id > 0 && 'publish' === get_post_status($cart_id));

        $passed = empty($missing_hooks) && empty($missing_files) && $cart_ready;
        $detail = $passed
            ? 'Presupuesto listo para carrito clasico, Cart Block y shortcode; plantilla y assets legibles.'
            : 'Presupuesto incompleto. Hooks ausentes: ' . (empty($missing_hooks) ? 'ninguno' : implode(', ', $missing_hooks)) . '; archivos ausentes: ' . (empty($missing_files) ? 'ninguno' : implode(', ', $missing_files)) . '; carrito: ' . ($cart_ready ? 'si' : 'no') . '.';

        return seo_core_system_test_result(
            'checkout',
            '4.14 Presupuestos de carrito preparados',
            $passed,
            $detail,
            $passed ? 'ok' : 'ko',
            array(
                'owner' => 'Woo',
                'area' => 'billing',
                'confidence' => 98,
                'evidence' => array(
                    'hooks' => $hooks,
                    'missing_hooks' => $missing_hooks,
                    'files' => $files,
                    'missing_files' => $missing_files,
                    'cart_page_id' => $cart_id,
                    'cart_ready' => $cart_ready,
                ),
                'remediation' => array(
                    'kind' => 'Presupuestos de carrito',
                    'summary' => 'El boton de presupuesto debe funcionar tanto en carrito clasico como en Cart Block.',
                    'steps' => array(
                        'Comprueba SEO_Facturas_Quotes::init() y sus hooks.',
                        'Verifica templates/quote.php y assets/frontend.css|js.',
                        'Confirma que WooCommerce tenga una pagina de carrito publicada.',
                    ),
                ),
            )
        );
    }
}

if (!function_exists('seo_core_system_test_billing_configuration_check')) {
    function seo_core_system_test_billing_configuration_check($settings, $master_enabled) {
        $settings = is_array($settings) ? $settings : array();
        $issues = array();

        if (!empty($settings['quote_require_email']) && empty($settings['quote_ask_email'])) {
            $issues[] = 'presupuesto exige email pero el campo email esta oculto';
        }
        if (!empty($settings['quote_send_email_copy']) && empty($settings['quote_ask_email'])) {
            $issues[] = 'copia por email activa pero el presupuesto no solicita email';
        }
        $validity = absint($settings['quote_validity_days'] ?? 0);
        if ($validity < 1 || $validity > 365) {
            $issues[] = 'validez de presupuesto fuera de 1-365 dias';
        }
        $hourly_limit = absint($settings['quote_hourly_limit'] ?? 0);
        if ($hourly_limit < 1) {
            $issues[] = 'limite horario de presupuestos no valido';
        }

        if (!empty($settings['proforma_enabled'])) {
            $statuses = seo_core_system_test_billing_csv_keys($settings['proforma_order_statuses'] ?? '');
            $known = array();
            if (function_exists('wc_get_order_statuses')) {
                foreach (array_keys((array) wc_get_order_statuses()) as $status) {
                    $known[] = preg_replace('/^wc-/', '', sanitize_key((string) $status));
                }
            }
            $unknown = !empty($known) ? array_values(array_diff($statuses, $known)) : array();
            if (empty($statuses)) {
                $issues[] = 'proforma activa sin estados WooCommerce configurados';
            } elseif (!empty($unknown)) {
                $issues[] = 'estados de proforma desconocidos: ' . implode(', ', $unknown);
            }

            if (!empty($settings['proforma_show_payment_info'])) {
                $has_payment_info = trim((string) ($settings['proforma_beneficiary'] ?? '')) !== ''
                    || trim((string) ($settings['proforma_iban'] ?? '')) !== ''
                    || trim((string) ($settings['proforma_bizum'] ?? '')) !== ''
                    || trim((string) ($settings['proforma_payment_instructions'] ?? '')) !== '';
                if (!$has_payment_info) {
                    $issues[] = 'proforma muestra informacion de pago pero no hay instrucciones configuradas';
                }
            }
        }

        if (!$master_enabled) {
            $detail = empty($issues)
                ? 'Sistema documental desactivado; la configuracion guardada no presenta contradicciones.'
                : 'Sistema documental desactivado, pero hay configuraciones incoherentes: ' . implode('; ', $issues) . '.';
            return seo_core_system_test_result(
                'checkout',
                '4.15 Configuracion documental coherente',
                empty($issues),
                $detail,
                empty($issues) ? 'info' : 'warning',
                array(
                    'owner' => 'Woo',
                    'area' => 'billing',
                    'status' => empty($issues) ? 'not_applicable' : 'warning',
                    'confidence' => 98,
                    'evidence' => array('issues' => $issues, 'enabled' => false),
                )
            );
        }

        $passed = empty($issues);
        $detail = $passed
            ? 'Configuracion de facturas, proformas y presupuestos sin contradicciones detectables.'
            : 'Configuracion a revisar: ' . implode('; ', $issues) . '.';

        return seo_core_system_test_result(
            'checkout',
            '4.15 Configuracion documental coherente',
            $passed,
            $detail,
            $passed ? 'ok' : 'warning',
            array(
                'owner' => 'Woo',
                'area' => 'billing',
                'confidence' => 98,
                'evidence' => array('issues' => $issues, 'enabled' => true),
                'remediation' => array(
                    'kind' => 'Configuracion documental',
                    'summary' => 'Evitar combinaciones de opciones que hagan imposible completar el flujo.',
                    'steps' => array(
                        'Revisa la pestaña Proformas y sus estados WooCommerce.',
                        'Revisa la pestaña Presupuestos si exige o envia email.',
                        'Guarda los cambios y repite la validacion.',
                    ),
                ),
            )
        );
    }
}

if (!function_exists('seo_core_system_test_billing_documents_check')) {
    function seo_core_system_test_billing_documents_check() {
        global $wpdb;

        $table = SEO_Facturas_Install::table_name();
        if (function_exists('seo_core_system_test_table_exists') && !seo_core_system_test_table_exists($table)) {
            return seo_core_system_test_result(
                'checkout',
                '4.16 Integridad de documentos emitidos',
                false,
                'No evaluable porque la tabla documental no existe.',
                'info',
                array(
                    'owner' => 'Woo',
                    'area' => 'billing',
                    'status' => 'not_evaluable',
                    'blocked_by' => 'billing_table_missing',
                    'coverage' => 0,
                    'confidence' => 100,
                )
            );
        }

        $identifier = '`' . str_replace('`', '``', $table) . '`';
        $cutoff = function_exists('current_datetime')
            ? current_datetime()->modify('-30 minutes')->format('Y-m-d H:i:s')
            : gmdate('Y-m-d H:i:s', time() - 1800);

        $sql = $wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN document_type NOT IN ('invoice','proforma') THEN 1 ELSE 0 END) AS invalid_types,
                SUM(CASE WHEN status NOT IN ('rendering','issued','error') THEN 1 ELSE 0 END) AS invalid_statuses,
                SUM(CASE WHEN document_number = '' OR document_number IS NULL THEN 1 ELSE 0 END) AS empty_numbers,
                SUM(CASE WHEN snapshot = '' OR snapshot IS NULL THEN 1 ELSE 0 END) AS empty_snapshots,
                SUM(CASE WHEN status = 'issued' AND (pdf_path = '' OR pdf_path IS NULL) THEN 1 ELSE 0 END) AS issued_without_path,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS error_documents,
                SUM(CASE WHEN status = 'rendering' AND updated_at < %s THEN 1 ELSE 0 END) AS stale_rendering
             FROM {$identifier}",
            $cutoff
        );
        $stats = $wpdb->get_row($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if (!empty($wpdb->last_error)) {
            return seo_core_system_test_result(
                'checkout',
                '4.16 Integridad de documentos emitidos',
                false,
                'No se pudo consultar la tabla documental: ' . (string) $wpdb->last_error,
                'ko',
                array(
                    'owner' => 'Woo',
                    'area' => 'billing',
                    'confidence' => 100,
                    'evidence' => array('database_error' => (string) $wpdb->last_error),
                )
            );
        }
        $stats = is_array($stats) ? array_map('intval', $stats) : array();
        $total = isset($stats['total']) ? (int) $stats['total'] : 0;

        if ($total === 0) {
            return seo_core_system_test_result(
                'checkout',
                '4.16 Integridad de documentos emitidos',
                true,
                'Todavia no hay facturas o proformas registradas. La tabla esta preparada para la primera emision.',
                'info',
                array(
                    'owner' => 'Woo',
                    'area' => 'billing',
                    'status' => 'not_applicable',
                    'confidence' => 100,
                    'evidence' => array('total_documents' => 0),
                )
            );
        }

        $sample = $wpdb->get_results(
            "SELECT id, document_number, pdf_path, file_hash
             FROM {$identifier}
             WHERE status = 'issued'
             ORDER BY id DESC
             LIMIT 25",
            ARRAY_A
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $unreadable = array();
        $hash_mismatch = array();
        foreach ((array) $sample as $row) {
            $path = isset($row['pdf_path']) ? (string) $row['pdf_path'] : '';
            $number = isset($row['document_number']) ? (string) $row['document_number'] : '';
            if ($path === '' || !is_readable($path)) {
                $unreadable[] = $number !== '' ? $number : '#' . absint($row['id'] ?? 0);
                continue;
            }
            $expected_hash = trim((string) ($row['file_hash'] ?? ''));
            if ($expected_hash !== '') {
                $actual_hash = hash_file('sha256', $path);
                if (!is_string($actual_hash) || !hash_equals(strtolower($expected_hash), strtolower($actual_hash))) {
                    $hash_mismatch[] = $number !== '' ? $number : '#' . absint($row['id'] ?? 0);
                }
            }
        }

        $hard = (int) ($stats['invalid_types'] ?? 0)
            + (int) ($stats['invalid_statuses'] ?? 0)
            + (int) ($stats['empty_numbers'] ?? 0)
            + (int) ($stats['empty_snapshots'] ?? 0)
            + (int) ($stats['issued_without_path'] ?? 0)
            + count($unreadable)
            + count($hash_mismatch);
        $soft = (int) ($stats['error_documents'] ?? 0) + (int) ($stats['stale_rendering'] ?? 0);

        if ($hard > 0) {
            $severity = 'ko';
            $passed = false;
        } elseif ($soft > 0) {
            $severity = 'warning';
            $passed = false;
        } else {
            $severity = 'ok';
            $passed = true;
        }

        $detail = 'Documentos: ' . number_format_i18n($total)
            . '; errores PDF: ' . number_format_i18n((int) ($stats['error_documents'] ?? 0))
            . '; renderizados bloqueados >30 min: ' . number_format_i18n((int) ($stats['stale_rendering'] ?? 0))
            . '; PDFs no legibles en muestra reciente: ' . number_format_i18n(count($unreadable))
            . '; hashes distintos: ' . number_format_i18n(count($hash_mismatch)) . '.';

        return seo_core_system_test_result(
            'checkout',
            '4.16 Integridad de documentos emitidos',
            $passed,
            $detail,
            $severity,
            array(
                'owner' => 'Woo',
                'area' => 'billing',
                'confidence' => 97,
                'evidence' => array(
                    'stats' => $stats,
                    'issued_files_sampled' => count((array) $sample),
                    'unreadable_documents' => array_slice($unreadable, 0, 10),
                    'hash_mismatch_documents' => array_slice($hash_mismatch, 0, 10),
                    'file_sample_limit' => 25,
                ),
                'remediation' => array(
                    'kind' => 'Integridad documental',
                    'summary' => 'Los documentos emitidos deben conservar snapshot, numero y PDF legible; los errores deben poder reintentarse sin cambiar el numero.',
                    'steps' => array(
                        'Revisa primero documentos con status=error o rendering antiguo desde la pestaña Documentos.',
                        'Comprueba permisos de uploads/seo-facturas y la disponibilidad del motor PDF.',
                        'No renumeres manualmente documentos ya registrados; usa el reintento del modulo.',
                    ),
                ),
            )
        );
    }
}

if (!function_exists('seo_core_system_test_billing_csv_keys')) {
    function seo_core_system_test_billing_csv_keys($value) {
        $parts = preg_split('/[\s,;]+/', strtolower((string) $value));
        $parts = is_array($parts) ? $parts : array();
        $parts = array_map('sanitize_key', $parts);
        return array_values(array_unique(array_filter($parts)));
    }
}
