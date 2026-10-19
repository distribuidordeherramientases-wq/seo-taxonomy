=== TaxoCommerce System Check ===
Plugin Name: TaxoCommerce System Check
Description: Diagnostico de WordPress, servidor, integridad del plugin e informes voluntarios.
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==

Plugin independiente con los chequeos existentes de TaxoCommerce SEO:
* System Check: PHP, MySQL, WordPress, hosting, rendimiento, seguridad y logs.
* Plugin Validation: chequeos funcionales, de servicios e integridad del codigo.
* Informes PDF/JSON y correo periodico previa activacion.
* Chequeos HTTP de paginas, posts, imagenes y productos con worker local y KPIs.
* Peticiones HTTP reales a URL publicas, redirecciones, 4xx, 5xx y tiempos de respuesta sin GitHub.

Cuando TaxoCommerce Core esta activo, los menus y el registro de modulos se integran en Core. Sin Core se ofrece un menu independiente.

Cuando TaxoCommerce SEO permanece activo, System Check reutiliza su motor
actual para evitar declaraciones de funciones PHP duplicadas.

== Installation ==

1. Instala el ZIP taxocommerce-system-check.zip y activa el plugin.
2. Abre TaxoCommerce Core > Chequeos HTTP (o System Check si Core no esta activo).
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
