=== TaxoCommerce Core - Catalog & Content Management for WooCommerce ===
Contributors: davidperezmartorell
Tags: woocommerce, catalog, content, management, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.24
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A modular WordPress/WooCommerce core for catalog hierarchy, unified content editing, processes, logs, and future TaxoCommerce extensions.

== Description ==

Version 0.4.22 registers the optional, independent TaxoCommerce Database plugin in the extensions catalog. Installing Core does not install or activate Database. If Database is active, it registers its native submenu under Core; without Core it uses its own top-level menu.

TaxoCommerce Core is the clean modular foundation for the next TaxoCommerce architecture. The 0.2.0 pilot is validated and deployed independently to STAGING.

Version 0.3.1 includes a unified Content Manager, WordPress-native SEO hierarchy and extension installer links for:

* WooCommerce products.
* WooCommerce product categories.
* WordPress posts.
* WordPress pages.

WordPress and WooCommerce remain the canonical source of truth. Core does not duplicate those objects into parallel tables.

The pilot also includes:

* A module registry contract.
* A process registry contract.
* A bounded technical log.
* A hierarchy shell for the future Cluster -> Primary Hub -> Secondary Hub -> Category -> Product model.
* Extension hooks for KPIs and future modules.

## Informe de arquitectura SEO en Contenidos

La pestaña «Arquitectura SEO» recupera el informe de distribución de clusters, hubs,
categorías y productos publicados. Consulta exclusivamente las relaciones gestionadas
por Core y WordPress/WooCommerce, sin cargar el plugin antiguo. Recuentos únicos,
actualización manual y caché local de 15 minutos; sin escaneos ni procesos periódicos.

## Guías de edición en Contenidos

El Gestor de contenidos incluye instrucciones de redacción para Productos,
Categorías, Entradas y Páginas. Se muestran en cada pestaña y dentro de cada
editor individual. La guía combina criterios originales recuperados del editor
anterior con recomendaciones públicas de Google Search Central:

* Información útil para personas, verificable y específica de cada objeto.
* Redacción sencilla, sin contenido copiado ni plantillas repetitivas.
* Identificación precisa de productos, variantes, categorías y páginas.
* Enlaces internos y arquitectura comercial comprensibles.
* Títulos descriptivos, imágenes relevantes y metadescripciones únicas.
* Sin cuotas obligatorias de palabras, FAQs o porcentajes de keywords.
* Enlaces a las referencias oficiales de Google.

Esta ayuda es de solo lectura; no genera ni modifica contenido automáticamente
y no cambia el plugin TaxoCommerce SEO.

== Changelog ==

= 0.4.24 =
* Registra TaxoCommerce Logística y TaxoCommerce Facturación como extensiones de la familia.

= 0.4.12 =
* Añadida extensión TaxoCommerce FAQs al catálogo de familia.
* Nueva pestaña FAQs en Contenidos al activar el módulo, con edición y CSV.


= 0.4.12 =
* Removed the unused technical log administration page, its menu shortcut and associated clear-log endpoint. Internal diagnostic logging remains available for dependent components.

= 0.4.10 =
* Gestor de procesos con acciones autenticadas, estado real y callbacks de extensiones, comenzando por auditorias HTTP System Check.


= 0.4.9 =
* Restored and expanded editorial guidance for all four content editors.
* Responsive read-only guidance at the overview and individual editor levels.
* Linked to official Google Search Central documentation; removed fixed-length SEO myths.


= 0.4.8 =
* Consolidated Ampliar familia and Extensiones into one administration page.
* Kept install, activate, deactivate, open and ZIP functions in Extensiones.
* Redirects old family-page bookmarks to the unified screen without changing plugin state.

= 0.4.6 =
* Redisenado el editor de contenidos: interfaz responsive con tarjetas, editor visual de WordPress, imagen, publicacion y buscadores AJAX de categorias y etiquetas.
* Etiquetas semanticas transversales para productos, categorias, posts y paginas, reutilizando seo_vocabulary y seo_object_vocabulary; las asignaciones automaticas se conservan.
* Atributos WooCommerce globales y personalizados editables por CRUD, con preservacion de atributos de variaciones.
* Campos especificos de productos externos (URL de afiliacion y texto del boton) y etiquetas nativas de WordPress/WooCommerce.
* Se mantiene la fuente de verdad canonica y no se crean terminos Vocabulary automaticamente.


= 0.4.5 =
* TaxoCommerce System Check se incorpora al catalogo de la familia con instalacion mediante ZIP, activacion y acceso integrado en el menu Core.


= 0.4.4 =
* Process registry now exposes progress, detail and an extension action URL.
* Process Manager can display the automatic Vocabulary classifier as a real managed process.
* Updated Vocabulary family description for automatic inventory classification.

= 0.4.3 =
* Added TaxoCommerce Vocabulary to the family catalog.
* Vocabulary is distributed by ZIP only until the owner publishes it.
* Added support for family extensions that intentionally are not yet listed on WordPress.org.

= 0.4.2 =
* Added a dedicated "Ampliar familia" screen in Core.
* TaxoCommerce Import-Export and TaxoCommerce Images are always visible there, even before installation.
* Each family extension offers WordPress.org search and ZIP upload when not installed.
* Installed extensions can be activated from the family screen.
* Active extensions can be opened or managed from Core.

= 0.4.1 =
* Added TaxoCommerce Images to the built-in family catalog.
* Core can detect, activate, deactivate and open the Images extension like Import-Export.

= 0.4.0 =
* Added Core menu manager based on the native SEO hierarchy.
* Generates a proposal from Cluster -> Primary Hub -> Secondary Hub -> Product Category.
* Creates and synchronizes a native WordPress navigation menu.
* Keeps manually added menu items during synchronization.
* Adds a native WordPress manual-edit shortcut.
* Can activate the managed menu in a registered theme location and restore the previous location map.

= 0.3.2 =
* Added built-in TaxoCommerce family catalog starting with Import-Export.
* Detects family extensions even when installed but inactive.
* Adds per-extension search in WordPress.org and ZIP upload actions.
* Adds secure Activate / Deactivate controls from Core.
* Active extensions may merge their administration pages into the Core menu.

= 0.3.1 =
* Extensions screen can open the native WordPress.org search and native ZIP upload installer.
* Registered TaxoCommerce extensions can expose an Open action back into their own administration page.
* Prepared Core integration for TaxoCommerce Import-Export.

= 0.3.0 =
* Added SEO hierarchy management: Cluster -> Primary Hub -> Secondary Hub -> Product Category.
* Added one SEO structural role per WordPress page.
* Stores hierarchy roles and links in native post meta / term meta; no duplicate page/category tables.
* Imports the active hierarchy once from the current TaxoCommerce SEO helper APIs when both plugins coexist.
* Added hierarchy KPIs, role filters, relation forms, tree view and unlink actions.
* Does not copy legacy seo_nodes or seo_relations tables.

= 0.2.1 =
* Spanish administration labels throughout the Core.
* Renamed Dashboard, Content, Hierarchy, Processes, Modules and Logs to Inicio, Contenidos, Jerarquía SEO, Procesos automáticos, Extensiones and Registro técnico.
* Added a migration map showing the equivalent feature in the current TaxoCommerce SEO plugin and migration status.
* Added equivalent-current-system notices to the main Core sections.

= 0.2.0 =
* Added unified content lists, filters and editing.
* Added basic rebuildable KPIs.
* Product editing uses WooCommerce CRUD.
* Category editing uses WordPress taxonomy APIs.
* Post and page editing uses WordPress APIs.
* No custom SQL or duplicate catalog/content tables.

= 0.1.0 =
* Initial modular Core pilot.

Deployment marker: Core 0.2.0.
