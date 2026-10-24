=== TaxoCommerce Logística ===
Contributors: davidperezmartorell
Tags: woocommerce, logistics, shipping, suppliers, orders
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT

Migración 1:1 del módulo Logística de TaxoCommerce SEO.

== Description ==

Conserva el comportamiento del módulo histórico:

* Gestión de pedidos activos.
* Cruce de líneas WooCommerce con la tabla existente seo_proveedores_productos.
* Enlaces de compra y catálogo de proveedores.
* Gestor de transporte con reglas por zona, importe, peso y dimensiones.
* Importación y exportación JSON de transporte.
* Integración con el cálculo de transporte de WooCommerce.
* Compatibilidad opcional con TaxoCommerce Core.

No crea una segunda tabla de proveedores y reutiliza la opción histórica seo_transporte_costes_settings.
No incluye una rutina de desinstalación destructiva.

== Changelog ==

= 0.1.0 =
* Migración 1:1 de Gestión de pedidos y Transporte desde el monolito.
