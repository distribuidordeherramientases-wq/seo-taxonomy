<?php
/**
 * Integrated user and technical instructions for TaxoCommerce Billing.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Billing_Manual {
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Sin permisos para consultar el manual.', 'taxocommerce-facturacion' ) );
        }

        echo '<h2 id="billing-manual">Manual de TaxoCommerce Billing</h2>';
        echo '<p>Versión ' . esc_html( TAXOCOMMERCE_FACTURACION_VERSION ) . '. Guía del módulo documental conectado a pedidos y carrito de WooCommerce.</p>';
        echo '<p><a href="#billing-user">Manual de usuario</a> · <a href="#billing-tech">Manual técnico</a> · <a href="#billing-help">Resolución de incidencias</a></p>';
        echo '<div class="notice notice-info inline"><p><strong>Advertencia:</strong> estas pantallas generan documentos comerciales y fiscales. Configura y revisa previamente los datos de la entidad, la fiscalidad y las series. Autotest no comprueba la validez legal de los documentos.</p></div>';

        echo '<h2 id="billing-user">Manual de usuario</h2>';
        echo '<h3>1. Acceso y activación</h3>';
        echo '<p>Accede a <strong>TaxoCommerce &gt; Billing</strong> con Core instalado, o a <strong>TaxoCommerce Billing</strong> en el menú principal. Solo usuarios con <code>manage_options</code> pueden configurar este módulo. La pantalla contiene Datos empresa, Fiscalidad, Facturas, Proformas, Presupuestos, Documentos, Manual y Autotest.</p>';
        echo '<p>La opción <strong>Sistema documental activo</strong> se gestiona en Datos empresa. El plugin puede estar instalado pero sus emisiones desactivadas: revisa este control antes de probar las funciones comerciales.</p>';

        echo '<h3>2. Datos empresa</h3>';
        echo '<p>Completa denominación de la entidad, nombre comercial, identificador fiscal, dirección postal, localidad, país, contacto y logotipo. Configura el pie de documento y, cuando sea necesario, las claves de metadatos del NIF/CIF del comprador. Guarda los cambios antes de emitir documentos.</p>';
        echo '<p>Los datos de empresa forman parte de la instantánea del documento: comprueba el resultado PDF y verifica que los datos legales del emisor sean correctos.</p>';

        echo '<h3>3. Fiscalidad</h3>';
        echo '<p>La pestaña Fiscalidad permite activar el gestor fiscal de ventas y configurar tratamiento de precios con impuestos incluidos, envíos y tipos para Península, Baleares, Canarias, Ceuta y Melilla. Revisa además las notas sobre gastos o tributos de destino para territorios especiales.</p>';
        echo '<p>WooCommerce sigue siendo la fuente de pedidos, productos y cobros. No des por correcta una configuración fiscal solo porque Autotest marque el módulo como disponible; las obligaciones tributarias concretas requieren revisión profesional.</p>';

        echo '<h3>4. Facturas</h3>';
        echo '<p>Configura serie, longitud de numeración, título, emisión automática, campos visibles y adjuntos a los correos WooCommerce. Con la emisión automática activada, el módulo reacciona a los estados de pago previstos por WooCommerce. El sistema conserva el número, la instantánea y el PDF asociados al pedido.</p>';
        echo '<p>Desde un pedido WooCommerce también existen controles manuales para generar, descargar o enviar el documento, sujetos a permisos. Antes de reintentar, comprueba si ya existe un documento emitido para ese pedido.</p>';

        echo '<h3>5. Proformas</h3>';
        echo '<p>Configura una serie propia y los estados de pedido que permiten emitir proformas (la configuración inicial contempla pedidos en espera). Puedes mostrar instrucciones de pago, beneficiario, IBAN o Bizum cuando sea apropiado. La proforma no equivale a factura fiscal definitiva.</p>';
        echo '<p>La emisión y los adjuntos de correos dependen de las casillas activadas y de los estados de pedido definidos, sin sustituir el registro contable.</p>';

        echo '<h3>6. Presupuestos desde el carrito</h3>';
        echo '<p>Activa la descarga de presupuestos para los clientes y configura: periodo de validez, permiso a invitados, datos solicitados, visualización de SKU/impuestos/envío/descuentos/imágenes, posibilidad de copia por email y límite horario de solicitudes.</p>';
        echo '<p>El formulario está integrado en el carrito clásico y en el bloque Cart de WooCommerce; existe el shortcode <code>[seo_facturas_presupuesto]</code> como alternativa. Los presupuestos generados desde carrito son efímeros y no forman parte del registro de documentos vinculados a pedidos.</p>';

        echo '<h3>7. Documentos emitidos</h3>';
        echo '<p>Consulta el registro de facturas y proformas vinculadas a pedidos: número, tipo, fecha, estado, fecha de email y acciones. Los controles disponibles permiten descargar, reenviar o reintentar el PDF según el estado de cada documento. No utilices esta vista para modificar el estado de pago original del pedido.</p>';

        echo '<h3>8. Autotest</h3>';
        echo '<p>Abre Autotest y pulsa <strong>Ejecutar autotest</strong>. Comprueba disponibilidad del sistema, clases, configuraciones, tabla de documentos, Dompdf e integraciones de WooCommerce. Un aviso de configuración pendiente no equivale a una avería del módulo. El resultado se muestra sin guardarse y sin generar documentos.</p>';

        echo '<h2 id="billing-tech">Manual técnico</h2>';
        echo '<h3>9. Arquitectura y compatibilidad</h3>';
        echo '<p>Entrada: <code>taxocommerce-facturacion.php</code>. El bootstrap <code>includes/seo-facturas-bootstrap.php</code> carga los controladores legados <code>SEO_Facturas_*</code>. El plugin reaprovecha las clases ya cargadas cuando el monolito original está activo; no debe declararlas dos veces ni introducir un segundo emisor de facturas.</p>';
        echo '<p>La administración mantiene la URL <code>admin.php?page=seo-facturas</code>. Las pestañas utilizan el parámetro de navegación <code>tab</code>. Se integra en Core mediante <code>taxocommerce_core_register_modules</code> y conserva la capacidad <code>manage_options</code>.</p>';

        echo '<h3>10. Persistencia y migraciones</h3>';
        echo '<p>Una única tabla de documentos <code>{prefijo}seo_factura_documents</code> (nombre devuelto por <code>SEO_Facturas_Install::table_name()</code>). Cada registro conserva tipo, serie, secuencia, número, pedido asociado, estado, instantánea, HTML, ruta y hash del PDF, fecha de emisión y último error.</p>';
        echo '<p>Opciones existentes: <code>seo_facturas_company_settings</code>, <code>seo_facturas_tax_settings</code>, <code>seo_facturas_invoice_settings</code>, <code>seo_facturas_proforma_settings</code>, <code>seo_facturas_quote_settings</code> y <code>seo_facturas_db_version</code>. Se conserva la migración de <code>seo_facturas_settings</code> al modelo actual. No renombres estas claves: los documentos anteriores dependen de ellas.</p>';
        echo '<p>Los presupuestos de carrito no se guardan en <code>seo_factura_documents</code>. La numeración de facturas y proformas es un flujo propio del registro documental.</p>';

        echo '<h3>11. Integración con WooCommerce</h3>';
        echo '<p><code>SEO_Facturas_WooCommerce</code> registra <code>woocommerce_payment_complete</code>, <code>woocommerce_order_status_changed</code> y <code>woocommerce_email_attachments</code>. El cliente puede consultar documentos autorizados desde la página de agradecimiento y la vista de pedidos, gestionadas por <code>SEO_Facturas_Customer_Documents</code>.</p>';
        echo '<p><code>SEO_Facturas_Quotes</code> integra la descarga en el carrito clásico mediante <code>woocommerce_proceed_to_checkout</code> y en Cart Block mediante <code>render_block_woocommerce/cart</code>. Se aplican nonces y controles de acceso de los flujos existentes.</p>';

        echo '<h3>12. Motor PDF y protección</h3>';
        echo '<p><code>SEO_Facturas_PDF</code> utiliza Dompdf y busca el autocargador en <code>includes/vendor/autoload.php</code> o <code>includes/dompdf/</code>. El motor puede ser heredado del monolito mientras conviven ambos plugins. Verifica el estado «Motor PDF» en la cabecera y prueba manualmente un documento de ejemplo solo en STAGING.</p>';
        echo '<p>El archivo PDF es un documento sensible. Respeta los directorios privados y los controles de descarga; no muevas PDFs a carpetas públicas ni incluyas datos de clientes en tickets o capturas sin anonimizar.</p>';

        echo '<h3>13. Seguridad y Autotest</h3>';
        echo '<p>El Autotest comprueba la presencia de componentes, opciones mediante <code>get_option</code>, handlers registrados y existencia de la tabla mediante <code>SHOW TABLES LIKE</code> preparado. Comprueba archivos de autocarga del motor PDF mediante lectura de metadatos, sin cargarlo ni crear PDFs.</p>';
        echo '<p>El formulario requiere usuario administrador y nonce. No llama a <code>issue_for_order</code>, <code>maybe_upgrade</code>, <code>wp_mail</code> ni a funciones que emitan o envíen documentos. No prueba que una factura tenga los importes correctos ni que un cliente pueda descargarla: eso se verifica con pedidos ficticios en STAGING.</p>';

        echo '<h3>14. Desarrollo y despliegue</h3>';
        echo '<p>Las pruebas automatizadas están en <code>tests/</code> y nunca deben distribuirse en el ZIP público. Valida sintaxis con PHP 7.4/8.4, la prueba de regresión del Autotest y Plugin Check antes de fusionar. Conserva el <code>CHANGELOG.md</code> maestro, sincroniza <code>readme.txt</code> antes de WordPress.org y solicita autorización expresa para producción.</p>';

        echo '<h2 id="billing-help">Resolución de incidencias</h2>';
        echo '<h3>15. Diagnósticos habituales</h3>';
        echo '<ul style="list-style:disc;margin-left:20px">';
        echo '<li><strong>No aparecen facturas:</strong> revisa que WooCommerce esté activo, el sistema documental habilitado, la emisión automática seleccionada y el estado del pedido.</li>';
        echo '<li><strong>No se genera PDF:</strong> revisa Dompdf y permisos de los archivos privados; consulta el último error del documento.</li>';
        echo '<li><strong>Faltan documentos antiguos:</strong> comprueba que persiste la tabla legada y que no se ha alterado la numeración o los datos de migración.</li>';
        echo '<li><strong>El carrito no ofrece presupuestos:</strong> revisa su activación, el tipo de carrito (clásico o bloque) y el shortcode si procede.</li>';
        echo '<li><strong>El cliente no descarga un documento:</strong> revisa permisos del pedido y restricciones de descarga, nunca hagas públicos los archivos.</li>';
        echo '<li><strong>Autotest indica aviso:</strong> distingue una opción no guardada de un error funcional. El test nunca emite facturas de prueba.</li>';
        echo '</ul><p><a href="#billing-manual">Volver al índice</a></p>';
    }
}
