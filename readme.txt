=== SEO Taxonomy ===

Contributors: davidperezmartorell
Tags: seo, woocommerce, taxonomy, catalog, automation
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.3.8.1
License: MIT
License URI: https://opensource.org/license/mit

Plataforma SEO y comercial para WooCommerce: taxonomía, catálogo, análisis, automatización, campañas, proveedores y herramientas de mantenimiento.

== Description ==

SEO Taxonomy es una plataforma modular para WordPress y WooCommerce orientada a sitios con catálogos amplios y necesidades de gestión SEO, comercial y operativa desde un único entorno.

El plugin centraliza herramientas que normalmente quedan repartidas entre múltiples pantallas y procesos.

Funciones principales:

* Arquitectura SEO basada en Cluster → Hub primario → Hub secundario → Categoría → Producto.
* Gestión de productos, categorías, páginas, entradas e imágenes.
* Vocabulary, etiquetas semánticas y atributos.
* Informes SEO, auditoría y controles de calidad.
* Gestión de redirecciones.
* Plantillas para productos, categorías, carrito, checkout y otras áreas del sitio.
* Importación, exportación y sincronización de proveedores.
* Gestión de imágenes locales y externas.
* Ojeador y análisis comparativo de mercado.
* Analista y señales de demanda.
* Campañas comerciales con productos, precios, fechas y restauración automática.
* Redes sociales y programador de publicaciones.
* FAQs y contenidos.
* Dependiente, Intérprete, Academia y servicios de aprendizaje.
* Gestor de procesos y workers para operaciones pesadas.
* Herramientas de diagnóstico, mantenimiento y validación.

SEO Taxonomy se desarrolla de forma activa y utiliza STAGING para validar cambios antes de promoverlos a producción.

== Installation ==

1. Descarga el paquete del plugin.
2. Sube la carpeta `seo-taxonomy` a `/wp-content/plugins/` o instala el ZIP desde WordPress.
3. Activa **SEO Taxonomy** desde **Plugins**.
4. Accede a **SEO Taxonomy** en el panel de administración.
5. Revisa los módulos que utilizarás y configura las conexiones externas solo cuando sean necesarias.
6. En instalaciones con WooCommerce, verifica catálogo, impuestos, moneda y entorno antes de ejecutar procesos masivos.

Para cambios importantes se recomienda validar primero en un entorno de staging.

== Frequently Asked Questions ==

= ¿SEO Taxonomy requiere WooCommerce? =

El objetivo principal del plugin es WordPress con WooCommerce. Muchas funciones de catálogo, productos, campañas, precios y proveedores dependen de WooCommerce. Algunas herramientas generales pueden funcionar sin él, pero la distribución está orientada a WooCommerce.

= ¿El plugin modifica automáticamente precios y productos? =

Solo los módulos que realizan acciones explícitas de escritura. Por ejemplo, una campaña habilitada puede aplicar su precio durante el periodo configurado y restaurar después la oferta anterior. Las pantallas administrativas indican cuándo una acción guarda, modifica o elimina información.

= ¿Puedo probar cambios antes de aplicarlos en producción? =

Sí. El flujo de desarrollo recomendado utiliza una rama `staging` y un WordPress de pruebas antes de promover la versión a producción.

= ¿La importación de campañas puede eliminar productos? =

Por defecto trabaja en modo fusión: añade o actualiza sin retirar productos ausentes. Solo sustituye completamente los productos cuando se activa expresamente esa opción.

= ¿Las APIs externas son obligatorias? =

No todas. Algunos módulos pueden usar servicios externos para obtener información adicional. Las credenciales se configuran por módulo y no deben incluirse en archivos públicos ni en el repositorio.

== Screenshots ==

1. Panel principal de SEO Taxonomy.
2. Gestión de productos y catálogo.
3. Arquitectura de categorías y taxonomía.
4. Informes y auditoría.
5. Ojeador y análisis de mercado.
6. Marketing y campañas comerciales.
7. Dependiente y herramientas de aprendizaje.
8. Gestor de procesos y workers.

== Changelog ==

= 2.3.8.1 - 2026-09-26 =

* Ampliada la gestión de campañas comerciales.
* Añadida edición explícita de campañas existentes.
* Añadidos vaciado de productos y eliminación segura de campañas.
* La importación/exportación incluye campañas, productos, SKU, precios y posición.
* Añadidos modos de fusión y sustitución completa de productos.
* Añadida compatibilidad con archivos antiguos y autodetección de filas de producto.
* Reforzada la restauración segura de precios y la prevención de solapamientos.

= 2.3.7 - 2026-09-25 =

* Creado el servicio de campañas comerciales y su integración con Analista.
* Mejorado Ojeador con semáforo de precios, oportunidades y comparativas.
* Añadidos inventarios comerciales y procesos de sincronización con proveedores.
* Reforzado Dependiente V3 y la cobertura del catálogo.
* Incorporado Solucionador y ampliado Comentarista.
* Mejorados informes, Google Trends y feeds comerciales.

= 2.3.6 - 2026-09-23 =

* Ampliado Ojeador y la integración con Google Shopping.
* Incorporadas nuevas fases de Academia y Entrenador.
* Evolucionados Dependiente e Intérprete.
* Ampliados auditoría, validación, FAQs, redirects e inventarios.
* Mejorados importación, proveedores, imágenes y estabilidad de workers.

= 2.3.5 - 2026-09-17 =

* Evolución amplia de Dependiente, Intérprete y Lingüista/Academia.
* Mejoras de arquitectura comercial, plantillas e informes.
* Nuevas herramientas de importación de proveedores.
* Mejoras de mantenimiento y limpieza del plugin.

= 2.0.0 =

* Primera versión pública documentada.
* Nueva interfaz administrativa.
* Gestión de productos, categorías, páginas e imágenes.
* Informes, herramientas avanzadas y aprendizaje semántico.

El historial técnico completo se mantiene en `CHANGELOG.md`.

== Upgrade Notice ==

= 2.3.8.1 =

Amplía Marketing > Campañas con edición, importación completa de productos y precios, vaciado y eliminación segura. Se recomienda revisar las campañas activas después de actualizar.

== License ==

SEO Taxonomy se distribuye bajo licencia MIT. Consulta el archivo `LICENSE` incluido en el plugin.
