# CHANGELOG — TaxoCommerce Billing

> Historial de las versiones del plugin **independiente**. Las funciones anteriores a su separación están documentadas en [`changelog.txt`](changelog.txt).
> Fuente inicial: entradas verificadas del `readme.txt` en GitHub. Solo se atribuye una fecha si la fuente la acredita.

## [Unreleased]

Sin novedades pendientes documentadas.

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
