# CHANGELOG — TaxoCommerce Billing

> Historial de las versiones del plugin **independiente**. Las funciones anteriores a su separación están documentadas en [`changelog.txt`](changelog.txt).
> Fuente inicial: entradas verificadas del `readme.txt` en GitHub. Solo se atribuye una fecha si la fuente la acredita.

## [Unreleased]

- Normalizadas con prefijo `taxocommerce_billing_` las variables locales de las plantillas PDF de presupuesto y factura, sin alterar claves de snapshots ni estructura de salida.
- Parametrizada también la consulta de tasas de WooCommerce (`%i`) y justificados localmente los accesos directos a tablas propias/legadas, la lectura sin caché de snapshots y las escrituras que deben ser inmediatas. Issue #1546.
- Se conserva la comprobación de firma y caducidad de enlaces de descarga, los nonces administrativos, las series fiscales y la generación de PDF. Validación de Plugin Check y regresión en CI pendiente.

- Se parametrizan los identificadores de tablas SQL con `%i` en Documents y Snapshot (requiere WordPress 6.2 o superior); valores de consulta continúan enlazados con `prepare()`.
- Documentadas con exclusiones PHPCS individualizadas las lecturas GET sin efectos secundarios en administración y los enlaces temporales HMAC de descarga del cliente; se mantienen las verificaciones de capacidad y nonce existentes en acciones administrativas.
- Correcciones parciales de la auditoría Plugin Check #1546: pendientes la revisión de consultas directas y caché, y la validación funcional y del ZIP antes de fusionar.

## [0.1.2]

- Corregida la identidad administrativa del plugin independiente: ruta propia `admin.php?page=taxocommerce-facturacion` y ocho pestañas visibles, incluidas Manual y Autotest, sin depender de `page=seo-facturas` del monolito.
- Conservada la lógica documental existente sin nuevos handlers ni cambios en las clases del monolito. Se oculta exclusivamente la navegación duplicada del controlador heredado al mostrar las pestañas de Billing.
- Añadida una regresión que simula la carga previa de la clase legada y comprueba la navegación, la compatibilidad y la visualización de Manual/Autotest. Relacionado con #1970.

## [0.1.1]

- Añadida pestaña Manual en Billing con guía de usuario y referencia técnica de los flujos existentes de empresa, fiscalidad, facturas, proformas, presupuestos y documentos, WooCommerce, Dompdf y solución de incidencias.
- Añadida pestaña Autotest manual de solo lectura, protegida por permisos administrativos y nonce. Comprueba componentes, hooks, opciones, tabla documental y disponibilidad del motor PDF, sin generar facturas, modificar pedidos ni enviar correos.
- Añadida regresión aislada en CI sin acceso a servicios externos. Scripts `tests/` excluidos del ZIP. Issue #1970.


## [0.1.0]

* Migrated the existing document module into an independent TaxoCommerce plugin.

## Referencias

- [Origen monolítico y transición](changelog.txt)
- [Índice de evidencias de los 20 plugins](../docs/PLUGIN-HISTORY-SOURCES.md)

## Mantenimiento

Este es el historial maestro que se actualiza al introducir novedades. Antes de publicar cada versión en WordPress.org, su resumen debe trasladarse al apartado `== Changelog ==` de `readme.txt`; WordPress.org no presenta este Markdown automáticamente.
