=== SEO Taxonomy ===

Contributors: davidperezmartorell
Tags: seo, woocommerce, taxonomy, catalog, automation
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.3.9
License: MIT
License URI: https://opensource.org/license/mit

SEO and commercial platform for WooCommerce: taxonomy, catalog, analytics, automation, campaigns, suppliers, and maintenance tools.

== Description ==

SEO Taxonomy is a modular platform for WordPress and WooCommerce designed for sites with large product catalogs and integrated SEO, commercial, and operational management needs.

The plugin centralizes tools that are often spread across multiple screens and processes.

Key features:

* SEO architecture based on Cluster -> Primary Hub -> Secondary Hub -> Category -> Product.
* Product, category, page, post, and image management.
* Vocabulary, semantic labels, and attributes.
* SEO reports, auditing, and quality controls.
* Redirect management.
* Templates for products, categories, cart, checkout, and other site areas.
* Supplier import, export, and synchronization.
* Local and external image management.
* Market monitoring and price comparison.
* Demand analysis and signals.
* Commercial campaigns with products, prices, dates, and automatic restoration.
* Social networks and publication scheduling.
* FAQs and content management.
* Dependent, Interpreter, Academy, and learning services.
* Process manager and workers for heavy operations.
* Diagnostic, maintenance, and validation tools.

SEO Taxonomy is actively developed and uses staging to validate changes before promoting them to production.

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

== External services ==

SEO Taxonomy puede conectarse a servicios externos únicamente cuando el administrador activa o utiliza el módulo correspondiente. El plugin no carga balizas de analítica de terceros de forma automática.

= Google APIs (Search Console, Analytics, OAuth, Trends y Cloud Run) =

Se utilizan para consultar Search Console y Analytics, obtener señales de Google Trends y, cuando el administrador lo configura, ejecutar procesos técnicos en Google Cloud Run.

Datos enviados: URL/sitio configurado, identificadores de propiedad o proyecto, consultas de búsqueda/tendencias, parámetros técnicos de la petición y credenciales OAuth o de cuenta de servicio cuando son necesarias. Estos datos se envían solo al guardar/probar la conexión o ejecutar el módulo correspondiente.

Terms: https://policies.google.com/terms
Privacy: https://policies.google.com/privacy

= Meta Graph API (Facebook e Instagram) =

Se utiliza para conectar páginas/cuentas y publicar contenido cuando el administrador activa estas integraciones.

Datos enviados: identificadores de página/cuenta, tokens OAuth, texto de publicación, enlaces e imágenes/URLs de medios que el administrador decide publicar.

Terms: https://www.facebook.com/terms.php
Privacy: https://www.facebook.com/privacy/policy/

= LinkedIn API =

Se utiliza para conectar una organización y publicar contenido en LinkedIn cuando el administrador activa la integración.

Datos enviados: identificador de organización, credenciales OAuth, texto de publicación y referencias a medios/enlaces seleccionados para publicar.

Terms: https://www.linkedin.com/legal/user-agreement
Privacy: https://www.linkedin.com/legal/privacy-policy

= Pinterest API =

Se utiliza para conectar una cuenta/tablero y crear Pins cuando el administrador activa la integración.

Datos enviados: identificadores de cuenta/tablero, credenciales OAuth, título/descripción, enlaces e imágenes asociadas al Pin.

Terms: https://policy.pinterest.com/terms-of-service
Privacy: https://policy.pinterest.com/privacy-policy

= X API =

Se utiliza para conectar una cuenta, publicar y consultar métricas de publicaciones cuando el administrador activa la integración.

Datos enviados: credenciales OAuth, identificador de cuenta/publicación y contenido que el administrador decide publicar.

Terms: https://x.com/en/tos
Privacy: https://x.com/en/privacy

= Microsoft Bing Webmaster API =

Se utiliza para consultar métricas de Bing Webmaster cuando el administrador configura la conexión.

Datos enviados: URL del sitio, parámetros de consulta y credenciales/API key configuradas por el administrador.

Terms: https://www.microsoft.com/servicesagreement
Privacy: https://privacy.microsoft.com/privacystatement

= SerpApi =

Ojeador e Ingeniero pueden utilizar SerpApi para consultar Google Shopping y resultados de búsqueda técnicos.

Datos enviados: API key, términos de búsqueda, categoría/mercado e idioma necesarios para la consulta. No se envían datos personales de visitantes de la tienda.

Terms and Privacy: https://serpapi.com/legal

= Amazon APIs =

Los importadores/integraciones de Amazon pueden solicitar tokens y consultar productos cuando el administrador configura sus credenciales.

Datos enviados: credenciales de aplicación, mercado y términos/identificadores de producto necesarios para la consulta.

Conditions of Use: https://www.amazon.com/gp/help/customer/display.html?nodeId=508088
Privacy Notice: https://www.amazon.com/gp/help/customer/display.html?nodeId=GX7NJQ4ZB8MHFRNJ

= GitHub API and GitHub Actions =

Algunos procesos opcionales de diagnóstico, chequeo visual y automatización pueden lanzar workflows o consultar repositorios configurados por el administrador.

Datos enviados: propietario/repositorio/workflow, parámetros del proceso, URL de callback cuando corresponde y token configurado por el administrador.

Terms: https://docs.github.com/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement

= Cloudflare API =

El módulo de conexiones puede consultar la zona de Cloudflare cuando el administrador configura un API Token. SEO Taxonomy no carga Cloudflare Web Analytics automáticamente.

Datos enviados: token de API, identificador de zona y parámetros de consulta necesarios para comprobar la conexión.

Terms: https://www.cloudflare.com/website-terms/
Privacy: https://www.cloudflare.com/privacypolicy/

= TikTok embeds =

Comentarista puede mostrar un reproductor de TikTok únicamente cuando un contenido/importación incluye expresamente una URL o identificador de TikTok. En ese caso, el navegador del visitante puede conectarse a TikTok para cargar el reproductor.

Terms: https://www.tiktok.com/legal/terms-of-service
Privacy: https://www.tiktok.com/legal/privacy-policy

= Supplier and external content sources =

Los módulos de proveedores, imágenes, Clasificador e Ingeniero pueden descargar feeds, páginas públicas, imágenes o documentación desde URLs externas indicadas/configuradas por el administrador o asociadas a productos/proveedores. La petición puede incluir la URL solicitada y cabeceras HTTP técnicas. No se envían datos personales de los visitantes de la tienda. El administrador es responsable de disponer de autorización para usar cada fuente y de revisar sus condiciones y política de privacidad.

Estas conexiones son opcionales salvo que una función concreta dependa expresamente de ellas. Las credenciales se almacenan en la instalación de WordPress y no deben distribuirse dentro del plugin.

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

= 2.3.9 - 2026-09-30 =

* Consolida el ciclo completo de parches 2.3.8.x en una versión estable.
* Mejora Dependiente, Academia e Intérprete, incluida la estabilidad de L9/L10 y el entrenamiento por preguntas.
* Incorpora Ingeniero y su integración con Clasificador para ampliar conocimiento, atributos y vocabulario por categoría.
* Amplía Auditor con calidad SEO, auditorías por bloques y equilibrio de categorías.
* Mejora Marketing, campañas, calendario e informes de redes sociales.
* Reorganiza Contenidos, Editor y Comentarista.
* Mejora comparador, carrito, Presupuestos/Proformas y generación PDF.
* Corrige el tratamiento de PVP con IVA incluido para evitar duplicar impuestos en carrito y checkout.
* Amplía la documentación operativa, Wiki y proceso de publicación.

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

= 2.3.9 =

Consolida los parches 2.3.8.x en la versión estable 2.3.9. Incluye mejoras de Dependiente/Academia, Ingeniero y Clasificador, Auditor, Marketing, categorías, comparador, documentos comerciales y correcciones de IVA incluido. Se recomienda validar catálogo, carrito/checkout y procesos automáticos después de actualizar.

= 2.3.8.1 =

Amplía Marketing > Campañas con edición, importación completa de productos y precios, vaciado y eliminación segura. Se recomienda revisar las campañas activas después de actualizar.

== License ==

SEO Taxonomy se distribuye bajo licencia MIT. Consulta el archivo `LICENSE` incluido en el plugin.
