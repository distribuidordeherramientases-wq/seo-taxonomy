=== TaxoCommerce Database ===
Contributors: davidperezmartorell
Tags: woocommerce, mysql, database, backup, seo
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

El plugin independiente conserva Clean Database y SEO Data Table del monolito TaxoCommerce SEO.

== Description ==

La version 0.4.0 contiene solamente los dos servicios que existian en el plugin anterior:

* Clean Database: Errores en datos, Limpieza BBDD, Exportar BBDD.
* Validacion y reparacion de relaciones/nodos SEO, ambitos y categorias, y desbloqueo de aceptacion masiva de proveedores.
* Las 17 acciones originales de limpieza de WordPress, WooCommerce y Action Scheduler. Sin limite total de 100 filas ni necesidad de escribir palabras de confirmacion. Las acciones se ejecutan manualmente y de forma sincrona, como el original.
* Exportar SQL completo de WordPress o SQL de tablas SEO.
* SEO Data Table: Resumen de tablas SEO, explorador con busqueda/orden/filtros, exportacion individual CSV/SQL y paquete ZIP de tablas SEO en CSV, SQL o ambos.

La unica funcionalidad deliberadamente excluida es el Data Layer transversal y sus funciones de operaciones, historial y rollback. El plugin no crea dependencias para otros plugins. Los backups son el mecanismo previsto de recuperacion.

No se incorporan los servicios posteriores de MySQL, Data Table nuevo, optimizacion, importador CSV, copias o mantenimiento duplicados. Se conservan los controles originales de acceso de administrador, nonces y confirmaciones normales de las acciones de borrado.

== Installation ==

1. Instalar y activar TaxoCommerce Database.
2. Abrir TaxoCommerce Database, desde su menu o desde Core.
3. Usar las pantallas originales Clean Database o SEO Data Table.
4. Descargar un backup SQL antes de las limpiezas irreversibles.

== Changelog ==

= 0.4.0 =
* Reemplaza los servicios inventados por los dos originales, Clean Database y SEO Data Table.
* Restituye los chequeos, reparaciones, limpiezas y exportaciones existentes.
* Excluye Data Layer y rollback historico, manteniendo la independencia de plugins.
