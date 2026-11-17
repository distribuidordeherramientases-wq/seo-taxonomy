## 0.1.11 — 2026-11-17 — Importación/exportación CSV de gastos desde Tesorería (#2785)

- Sección en Tesorería para descargar plantilla, importar y exportar por ejercicio CSV de gastos de asociación/tienda (hosting, dominios, software, certificados, tasas y Correos), sin duplicar libros ni tratar esos documentos como compras de mercancía.
- Cada fila se incorpora exclusivamente al registro **Expenses** como borrador revisable; conserva fecha original, emisor, documento, base, IVA, porcentaje de IVA propuesto y tratamiento. No aprueba deducciones, no modifica el IVA de documentos emitidos y no crea movimientos de caja ni banco.
- Identificación de destino association/commercial/shared/review en las notas del mismo asiento, sin tablas o columnas adicionales; el exportador recupera esa clasificación.
- CSV UTF-8 compatible con Excel español (punto y coma, también admite CSV con coma), límite 2 MB/500 filas por importación, validación completa previa, duplicados omitidos y exportación protegida frente a fórmulas CSV.
- Se mantiene la adjunción cifrada de justificantes PDF desde la pestaña Expenses, usando el mismo registro manual de gastos. Pruebas de seguridad y compatibilidad con los flujos existentes.

## 0.1.10 — 2026-11-17 — Pestaña Tesorería: caja, bancos y préstamos (#2782)

- Una nueva pestaña independiente **Tesorería** presenta indicadores de caja, saldos bancarios combinados, fondos disponibles, deuda declarada pendiente y cuotas mensuales previstas.
- Alta de cajas y múltiples cuentas bancarias con saldo inicial, movimientos de entrada/salida y traspasos internos atómicos entre cuentas; resumen mensual de entradas, salidas y saldos sin mezclarlo con los indicadores de IVA.
- Préstamos sencillos con importe inicial, entidad acreedora, cuota, próximo vencimiento y saldo pendiente editable según extracto del prestamista. No estima intereses, no descuenta mensualidades automáticamente y no registra cobros ni pagos ficticios.
- Las facturas comerciales de WooCommerce/Billing no generan entradas o salidas de tesorería hasta registrar efectivamente un movimiento. Preservados los registros y cálculos fiscales.
- Datos en tablas propias y permisos WordPress, acciones con nonces, validación de importes y fechas; pruebas de saldos y transferencias.

## 0.1.9 — 2026-11-17 — Traspaso automático de factura emitida a Accounting (#2778)

- Captura automática mediante una acción estándar de WordPress tras la emisión de una factura de Billing: asiento de venta en borrador, sin reimportación manual ni dependencia obligatoria entre plugins.
- Para nuevas ventas utiliza número, base imponible, IVA y total del snapshot fiscal inmutable de la factura si está disponible; si no hay Billing, conserva la importación convencional de WooCommerce.
- Reconcilia únicamente borradores intactos que se importaron antes de emitir su factura. No modifica documentos originales, pedidos, asientos validados ni correcciones auditadas.
- Impide aprobar borradores que discrepen de los importes de una factura emitida y avisa cuando ya existen ventas validadas con IVA cero.
- No recalcula ni inventa cuotas de IVA: la prueba simulada de 621,60 € con 107,88 € de IVA corresponde exclusivamente al asiento corregido de prueba; el documento histórico conserva los 0 € de IVA emitidos.

## 0.1.8 — 2026-11-17 — Guía para IVA deducible y revisión de ventas con IVA cero (#2773)

- Se conservan los importes de ventas importados desde WooCommerce, sin reescribir automáticamente documentos emitidos ni asientos validados.
- Las ventas WooCommerce con base positiva e IVA cero muestran aviso de revisión: para validarlas como operación doméstica se exige confirmación expresa tras contrastar la factura y el tratamiento; no se aplica artificialmente el 21 % a cualquier producto.
- En la incorporación manual de facturas de proveedores, el porcentaje de IVA deducible no comienza en 0 por defecto y debe indicarse expresamente. Se explica la diferencia entre **tipo de IVA** y **porcentaje deducible** (0 %, 100 % u otro legalmente aplicable).
- Vista previa automática del total de factura (base + cuota) y del importe de IVA potencialmente deducible. Solo ayuda a revisar los datos, no decide el tratamiento fiscal.
- Pruebas de regresión y validación de sintaxis JavaScript. Sin cambio de registros existentes, WooCommerce, Billing ni producción.

## 0.1.7 — 2026-11-17 — Corrección controlada de asientos validados (#2771)

- Botones **Corregir asiento** e **Historial** en Ventas, Facturas recibidas y los demás registros contables.
- Edición acotada de fecha, referencia, cliente/proveedor, NIF, concepto, base imponible, IVA y porcentaje deducible, con motivo obligatorio; el total se calcula como base más IVA.
- Toda corrección se registra en una tabla propia con datos antes/después, usuario, fecha y motivo; escritura atómica de asiento e historial (sin sobrescribir el historial anterior). La modificación devuelve el asiento a **borrador** para nueva validación y lo excluye de totales fiscales validados hasta entonces.
- La factura emitida de Billing, el PDF del proveedor y los pedidos WooCommerce no se modifican. Si el documento fiscal original es incorrecto, debe corregirse en origen por el procedimiento adecuado, normalmente mediante factura rectificativa; ajustar la contabilidad no lo sustituye.
- Control de permisos, nonce específico, validación de importes y fechas, comprobación del número de factura de Billing en servidor y protección ante edición simultánea.
- Prueba PHP independiente para recálculo, reversión, trazabilidad, retención del original y facturas de proveedor. Compatibilidad con WordPress.org y despliegue acotado a STAGING.

## [0.1.6] — 2026-11-17 — Import ISP → treatment intra (#2761)

- Al importar pedidos WooCommerce con régimen `intra_eu_reverse_charge` / VIES válido, asigna `treatment=intra` y copia el NIF-IVA (sigue en draft).
## 0.1.6 — 2026-11-17 — Asociación automática de factura emitida y venta (#2767)

- Accounting obtiene de manera opcional el número de la factura **emitida** en Billing utilizando el identificador exacto de pedido WooCommerce. No hay dependencia obligatoria de otro plugin TaxoCommerce.
- Para pedidos nuevos se registra la referencia si ya existe; para ventas antiguas todavía en borrador se propone el número en pantalla sin modificar datos previos. En el momento de aprobar la venta se vuelve a comprobar en el servidor la factura real del pedido y no se acepta como referencia un valor cambiado desde el navegador.
- Si Billing no está instalado o no hay factura emitida, se conserva la referencia manual para otros sistemas de facturación. Proformas, presupuestos, facturas de proveedor y devoluciones no se confunden con la factura de venta.
- El enlace de la referencia **no aprueba automáticamente** IVA, facturas incorrectas ni asientos contables. Se conservan las revisiones fiscales, la numeración y los datos existentes.
- Añadida prueba de regresión PHP del vínculo, su ausencia cuando falta Billing y validación del identificador exacto de pedido. Plugin Check y CI requeridos.

## 0.1.5 — 2026-11-15 — Pestaña activa destacada (#2720)

- La pestaña activa del menú de Accounting y la subpestaña de Hacienda se distinguen con fondo azul y texto blanco, con estado de foco visible al navegar con teclado.
- CSS propio, cargado únicamente en la pantalla del plugin mediante admin_enqueue_scripts; sin cambiar las otras pantallas de WordPress, el código fiscal o las traducciones.
- Prueba de regresión para encolado y marcado visual de la navegación. Sin cambios funcionales ni de datos.

## 0.1.4 — 2026-11-15 — Preparación fiscal acumulada y sincronización opcional (#2707)

- Hacienda muestra en los modelos 303 y 390 los importes acumulados del periodo (bases de ventas y compras, IVA repercutido, soportado deducible y diferencia indicativa) calculados desde el registro fiscal actualizado al abrir la pestaña. No se confunden con las casillas oficiales ni con la deuda tributaria final.
- Incorporada importación periódica **opcional, desactivada por defecto** de pedidos pagados desde WooCommerce mediante WP-Cron cada hora, con un máximo de tres bloques de 25 pedidos por ejecución. Se guarda cursor, último chequeo y fecha de última vuelta completa; no se confunde la importación con la validación fiscal.
- Los pedidos importados y devoluciones permanecen en borrador. No se editan pedidos, facturas ni asientos validados. El plugin no importa automáticamente facturas de proveedores; cada factura debe registrarse y verificarse.
- Hacienda indica el estado de sincronización y si está desactivada o todavía no se ha completado. Aviso sobre el desglose por tipos impositivos, compensaciones de periodos anteriores y otros datos fiscales no recogidos todavía.
- Corregida la tabla de Autocheck para mostrar los resultados devueltos por la función de pruebas.
- Pruebas de bases de cálculo y paginación del proceso automático en CI, documentación WordPress.org y verificación con Plugin Check.
- Sin PDF adicional ni integración de envío a la AEAT. El resultado es una herramienta preparatoria, no una autoliquidación oficial completa.

## 0.1.3 — 2026-11-15 — Preparación fiscal sin PDFs ni envíos (#2702)

- En Hacienda se incorpora un control de cobertura por ejercicio y, para el modelo 303, por trimestre: total de asientos, pendientes de validar, tratamientos fiscales especiales, referencias documentales ausentes y NIF no informados (estos últimos solo orientativos).
- Las alertas distinguen un periodo vacío de una declaración a cero y advierten de importaciones incompletas, fechas de inicio y operaciones no incluidas en el registro.
- Accesos a revisión de ventas y compras, aclaración de la posible aplicación de los modelos 130 y 200 según situación fiscal y enlace a la información oficial de la AEAT.
- Se conservan los libros e informes CSV/JSON existentes. Ningún PDF adicional, envío a Hacienda, generación de modelo oficial o modificación de pedidos/asientos.
- Pruebas de regresión de periodos y recuentos; queda por verificar funcionamiento real y ausencia de errores en STAGING.

## 0.1.2 — 2026-11-14 — Pestaña Hacienda y descargas preparatorias (#2687)

- Nueva pestaña «Hacienda» con subpestañas de los cuatro libros registro de IVA y modelos 303, 390, 347, 349 y 200. Mantiene compatible la antigua ruta de «Declaraciones fiscales».
- Las pestañas de libros de IVA y 303 enlazan además a las **plantillas XLSX oficiales vacías** que publica la AEAT para personas jurídicas y formato general. El plugin no las rellena.
- Cada vista dispone de informe interno descargable en CSV/JSON con advertencia inequívoca **«borrador no presentable»** y enlace al trámite o documentación oficial de la AEAT. No se ofrecen falsos formatos .303/.390/.347/.349/.200 ni XLSX Pre303/LSI que no se ajusten al diseño oficial.
- Modelo 303: selección trimestral; modelo 390: resumen anual. Ambos calculan importes orientativos de operaciones nacionales validadas mediante agregación SQL de **todas las filas** del periodo, sin el límite histórico de 400 filas del panel de Resumen; borradores y regímenes no nacionales se indican para revisión.
- Libros/347/349/200: vista previa de hasta 20 movimientos y exportación paginada de hasta 20 000 filas por vista. Bloquea exportaciones de mayor tamaño en lugar de generar informes incompletos. No computa umbrales, claves tributarias ni contabilidad completa.
- Permisos WordPress, nonce, parámetros controlados, protección CSV y JSON y enlaces oficiales de la AEAT. Sin conexión remota ni envíos; sin modificar pedidos WooCommerce, facturas originales, proveedores u otros plugins.
- Actualizaciones de i18n ES/EN/FR/DE/NL, pruebas de regresión y Plugin Check. Los informes no sustituyen la validación por asesoría profesional ni una presentación efectiva.

## 0.1.1 — 2026-11-14 — Facturas originales de compras y proveedores (#2681)

- Las compras, gastos, activos e intracomunitarias registradas manualmente ahora permiten adjuntar uno o varios PDF originales del proveedor, con enlaces de descarga protegidos y estado pendiente si no existe documento digital.
- Validación de subida de WordPress, firma PDF, 10 MB máximo, permisos `manage_woocommerce` y nonces. Los PDF se cifran mediante AES-256-GCM, se archivan como archivos opacos con nombres aleatorios y solo pueden recuperarse por una descarga autenticada, verificando integridad y relación con el asiento.
- Nueva tabla de documentos exclusiva del plugin, migración no destructiva desde 0.1.0. No cambia importación WooCommerce, ventas, proveedores, importes, IVA, validaciones ni exportaciones; tampoco extrae automáticamente datos de las facturas ni genera documentos de Hacienda.
- Pruebas aisladas de cifrado/descifrado, alteración, permisos y vínculos, PHP y WordPress Plugin Check. La copia de seguridad debe incluir los archivos cifrados, la BBDD y las claves salt de WordPress para preservar el descifrado.

## 0.1.0 — 2026-11-13 — Initial fiscal register (#2644)

- Independent WooCommerce accounting plugin with native WordPress admin, optional TaxoCommerce Core menu, own table and configuration.
- Manual, batched and repeat-safe import of paid WooCommerce orders after the merchant's configurable fiscal start date.
- Draft-only imported sales and refunds, to be reconciled with issued invoices and VAT before validation.
- Manual received invoices and expense, asset and intracommunity categorisation with tax bases, VAT, deductible proportion, issuer and invoice reference.
- Monthly domestic VAT working balance, provisional Model 303 view and CSV/JSON internal exports.
- Read-only Autocheck for actual missing invoices, drafts, special-treatment operations and schema health.
- Strict privilege/nonce checks and no modifications to source orders, suppliers, billing, logistics or production data.
- No tax submissions to AEAT; initial release excludes official format exports, comprehensive books and automatic deduction policy.
