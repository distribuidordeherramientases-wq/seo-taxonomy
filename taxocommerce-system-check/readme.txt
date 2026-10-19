=== TaxoCommerce System Check ===
Plugin Name: TaxoCommerce System Check
Description: Diagnostico de WordPress, servidor, integridad del plugin e informes voluntarios.
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==

Plugin independiente con los chequeos existentes de TaxoCommerce SEO:
* System Check: PHP, MySQL, WordPress, hosting, rendimiento, seguridad y logs.
* Plugin Validation: chequeos funcionales, de servicios e integridad del codigo.
* Informes PDF/JSON y correo periodico previa activacion.

Cuando TaxoCommerce Core esta activo, los menus y el registro de modulos se integran en Core. Sin Core se ofrece un menu independiente.

Cuando TaxoCommerce SEO permanece activo, System Check reutiliza su motor
actual para evitar declaraciones de funciones PHP duplicadas.

== Installation ==

1. Instala el ZIP taxocommerce-system-check.zip y activa el plugin.
2. Abre el menu System Check en WordPress.
3. Ejecuta los chequeos y revisa los resultados.
4. Habilita el correo programado expresamente si deseas notificaciones.

== Important notes ==

Esta es la primera extraccion funcional, no la version comercial definitiva.
Determinados tests aun dependen de servicios de TaxoCommerce SEO y no son
generalizables a cualquier instalacion WordPress.
La revision individual de cada plugin se incorporara en una version futura.
No incluye gestion de suscripciones ni cobros.
El envio de correos no se habilita automaticamente.
