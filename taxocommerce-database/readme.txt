=== TaxoCommerce Database ===
Contributors: davidperezmartorell
Tags: woocommerce, database, mysql, backup, csv
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gestor independiente de MySQL para WordPress y WooCommerce: inventario, diagnostico, optimizacion controlada, backups, exportaciones, importacion CSV y limpieza de datos caducados.

== Description ==

TaxoCommerce Database funciona SIN TaxoCommerce Core ni ningun otro plugin de la familia. Con Core activo aparece como submenú de sus extensiones; en ausencia de Core muestra un menu independiente.

== Herramientas ==

* MySQL: fotografia bajo demanda de version, indicadores del servidor, motores, tamaños, relaciones SEO y hasta 20 chequeos anteriores. No analiza continuamente.
* Data Table: inventario de las tablas del sitio y explorador de solo lectura, con filtros y paginas limitadas, de tablas propias de la familia.
* Backups: CSV/SQL por tabla interna, SQL conjunto de tablas de la familia o SQL de todas las tablas del prefijo WordPress. Descarga directa al navegador, sin guardar el dump en uploads.
* Optimizar: ANALYZE TABLE manual en tablas internas InnoDB de hasta 32 MB y OPTIMIZE TABLE manual en tablas internas de hasta 8 MB/50.000 filas. Confirmacion expresa, backup y ninguna tarea en segundo plano.
* Importar CSV: solo INSERT en tablas internas InnoDB, con validacion, vista previa, confirmacion y limites de 1 MB / 250 filas. La transaccion SQL cancela el lote completo en caso de fallo. No actualiza ni elimina registros existentes. No restaura copias SQL.
* Clean DB: deteccion de transients/sesiones caducadas y diagnostico avanzado bajo demanda de revisiones, papelera, metadatos huerfanos, registros Action Scheduler y reservas de stock. Unicamente se habilitan limpiezas acotadas (maximo 100) de datos ya caducados/descartables. Sin borrados directos de pedidos, productos o categorias.
* Operaciones: registro informativo local de chequeos, descargas, importaciones, optimizaciones y limpiezas; sin posibilidad de deshacer operaciones anteriores.

Seguridad: manage_options, nonce, inventario real de identificadores SQL, escape de salida y limites para evitar consultas y escrituras descontroladas.

== Limitaciones y recuperacion ==

* Los ficheros SQL exportados mientras la tienda recibe escrituras NO son necesariamente snapshots transaccionales. Utiliza backups verificados del hosting para la restauracion completa.
* Un backup completo puede contener datos personales, hashes, claves de API y tokens. Protege los archivos fuera de carpetas publicas.
* El CSV conserva los valores originales y puede contener formulas al abrirse como una hoja de calculo.
* Mantiene transacciones SQL para evitar importaciones a medias, pero no existe un motor de reversión historica en los plugins modulares.
* Si esta version sustituye a la experimental 0.2.0, los objetos fisicos `tcdb_operations` y `tcdb_changes` que ya existieran en la base de datos no se eliminan de manera automatica por precaucion. No se cargan, escriben ni registran y quedan excluidos de importaciones y optimizaciones. Comprobar su contenido antes de eliminarlos manualmente.
* Las tablas del gestor de operaciones del TaxoCommerce SEO antiguo no se modifican.

== Installation ==

1. Instala o actualiza la carpeta taxocommerce-database en wp-content/plugins.
2. Activa el plugin desde WordPress > Plugins.
3. Abre TaxoCommerce Database, o TaxoCommerce Core > Base de datos cuando Core este activo.
4. Ejecuta los chequeos o mantenimientos solo cuando sean necesarios.

== Changelog ==

= 0.2.1 =
* Eliminado el motor experimental de rollback historico, su API, sus endpoints, su panel y los adaptadores de otros plugins.
* Sin tablas nuevas ni tareas de captura de operaciones. Importacion CSV independiente y atomica.
* Se mantienen MySQL, Data Table, Backups, Optimizar, Clean DB y el registro informativo de operaciones.

= 0.2.0 =
* Ampliados los diagnósticos Clean DB y las operaciones manuales de optimización.
* Funcion experimental de historial reversible, retirada en 0.2.1.

= 0.1.0 =
* Plugin autonomo inicial con inventario MySQL, Data Table, exportacion CSV/SQL, importacion controlada y limpieza de caducidades.
