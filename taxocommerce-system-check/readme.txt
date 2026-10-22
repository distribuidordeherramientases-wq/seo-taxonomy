=== TaxoCommerce System Check ===
Plugin Name: TaxoCommerce System Check
Description: Diagnostico de WordPress, servidor, integridad del plugin e informes voluntarios.
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 0.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==

Plugin independiente con los chequeos existentes de TaxoCommerce SEO:
* System Check: PHP, MySQL, WordPress, hosting, rendimiento, seguridad y logs.
* Plugin Validation: chequeos funcionales, de servicios e integridad del codigo.
* Informes PDF/JSON y correo periodico previa activacion.
* Chequeos HTTP de paginas, posts, imagenes y productos con worker local y KPIs.
* Peticiones HTTP reales a URL publicas, redirecciones, 4xx, 5xx y tiempos de respuesta sin GitHub.

Con TaxoCommerce Core activo System Check aparece como una unica opcion de menu. Dentro hay cuatro pestanas: Sistema, Plugin Validation, HTTP y Paridad catalogo; HTTP incluye Paginas, Posts, Imagenes y Productos. Sin Core permanece una unica entrada de menu independiente.

Cuando TaxoCommerce SEO permanece activo, System Check reutiliza su motor
actual para evitar declaraciones de funciones PHP duplicadas.

== Installation ==

1. Instala el ZIP taxocommerce-system-check.zip y activa el plugin.
2. Abre TaxoCommerce Core > System Check y elige Sistema, Plugin Validation, HTTP o Paridad catalogo.
3. Ejecuta los chequeos y revisa los resultados.
4. Habilita el correo programado expresamente si deseas notificaciones.

== Important notes ==

Esta es la primera extraccion funcional, no la version comercial definitiva.
Determinados tests aun dependen de servicios de TaxoCommerce SEO y no son
generalizables a cualquier instalacion WordPress.
La revision individual de cada plugin se incorporara en una version futura.
No incluye gestion de suscripciones ni cobros.
El envio de correos no se habilita automaticamente.
Los chequeos HTTP arrancan de forma manual y se procesan por lotes pequenos.
Usan Action Scheduler si existe y WP-Cron en caso contrario; si el Cron no se ejecuta, usa Reanudar.
La medicion HTTP local desde hosting no certifica accesibilidad desde Internet.

Version 0.2.3: las cuatro auditorias HTTP se registran como procesos Core. El gestor central permite iniciar, pausar, reanudar y cancelar. La vista HTTP conserva sus KPIs y detalle. Sin Core se mantienen los controles locales.

== Paridad catalogo (0.2.4) ==

* Informe de solo lectura para la base de datos WordPress de esta instalacion (STAGING si se ejecuta en STAGING).
* Distingue etiquetas nativas product_tag del vocabulary semantico seo_vocabulary / seo_object_vocabulary.
* Compara atributos de sql_atributos / sql_atributos_terminos / sql_product_atributos con definiciones WooCommerce, pa_*, postmeta _product_attributes y relaciones de terminos.
* La auditoria por producto se inicia solo con un clic del administrador y se limita a 100 productos con atributos propios por peticion.
* Exporta un CSV con recuentos, atributos y ejemplos de diferencias; no altera tablas ni crea valores nuevos.
* Comparar slugs entre etiquetas semanticas y product_tag es orientativo: NO implica que sean la misma taxonomia ni que exista una migracion equivalente.
* La comprobacion por producto no cubre productos sin atributos en tablas propias ni variaciones; usar el resumen y otras auditorias complementarias antes de declarar migracion completa.
* Requiere ejecutarse en el entorno real para obtener cantidades verificadas. GitHub CI solo valida codigo y comparaciones deterministas.
