=== TaxoCommerce Database ===
Contributors: davidperezmartorell
Tags: woocommerce, database, mysql, backup, csv
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gestión independiente de bases de datos WordPress/WooCommerce con diagnóstico MySQL, explorador de tablas de la familia TaxoCommerce, exportación SQL/CSV, importación controlada y limpieza opcional de caducidades.

== Description ==

TaxoCommerce Database funciona sin TaxoCommerce Core. Si Core está activo, aparece dentro de su menú de extensiones; si no, utiliza su propio menú principal de WordPress.

Pantallas:
* MySQL: chequeo manual de tamaño, motores, contadores globales del servidor y relaciones SEO, con hasta 20 fotografías.
* Data Table: inventario de tablas del prefijo actual y explorador filtrable y paginado de tablas internas. No permite editar celdas.
* Backups: exportación CSV/SQL por tabla interna, SQL consolidado de tablas internas o SQL de todas las tablas del sitio. Los ficheros se transmiten directamente al administrador; no quedan en uploads ni en la carpeta del plugin.
* Importar CSV: vista previa con límite de 1 MB y 250 registros, validación de columnas y confirmación antes de añadir nuevos registros en tablas propias InnoDB. Requiere una copia reciente y referencias válidas; jamás sobrescribe ni elimina registros existentes. Las importaciones de productos, categorías y posts se realizan mediante TaxoCommerce Import-Export, no aquí.
* Clean DB: diagnóstico de caducidades y dos acciones manuales acotadas a 100 elementos: transients caducados de WordPress y sesiones WooCommerce caducadas. Las colas Action Scheduler se presentan solo como información.
* Operaciones: auditoría informativa de chequeos, descargas, importaciones y limpiezas sin registrar contenidos sensibles.

Seguridad: permisos manage_options, nonce, validación de identificadores MySQL contra las tablas reales del prefijo actual, escapado de salida, SQL parametrizado, límites estrictos de importación y acciones manuales.

IMPORTANTE:
* No se ha migrado ningún sistema de rollback/reversión del antiguo Data Table ni Clean DB, ni se ha añadido un endpoint de ejecución de SQL o restauración automática.
* Una exportación SQL desde una tienda en funcionamiento NO es una fotografía transaccional. Puede ser inconsistente si se modifican registros durante la descarga. Para copias completas/restauración utiliza las herramientas de backup del hosting.
* El backup completo puede contener hashes de contraseñas, datos de clientes, tokens, configuraciones y secretos. Guárdalo fuera de ubicaciones públicas.
* El CSV es una exportación exacta, no saneada para abrirse directamente como hoja de cálculo. Los datos no fiables pueden contener fórmulas.
* La limpieza de datos caducados modifica datos de manera irreversible en el módulo. No incluye borrados de contenidos, relaciones SEO ni reparaciones automáticas.

== Installation ==

1. Sube la carpeta taxocommerce-database a wp-content/plugins.
2. Activa el plugin desde WordPress > Plugins.
3. Abre TaxoCommerce Database en el menú principal o TaxoCommerce Core > Base de datos.
4. Ejecuta chequeos/descargas solamente cuando los necesites.

== Changelog ==

= 0.2.0 =
* Añade diagnóstico completo de Clean DB a petición, optimización limitada de tablas internas y API opcional de rollback INSERT/UPDATE/DELETE sobre tablas opt-in InnoDB con PK.
* Primera integración opcional con Redirects; no se capturan automáticamente las operaciones de otros plugins. Reversión con transacción y comprobación de cambios posteriores.
* El historial reversible se mantiene en tablas `tcdb_operations` y `tcdb_changes`, independientes del monolito. No se borran automáticamente al desinstalar para evitar pérdida irreversible de historial.
* Las acciones de limpieza de logs caducados/stock no generan snapshot reversible; se consideran descartes de datos expirados. Los borrados de contenidos y relaciones SEO siguen siendo solo diagnósticos.

= 0.1.0 =
* Plugin independiente MySQL/Data Table/Backups/CSV/Clean DB, sin portabilidad de rollback.
