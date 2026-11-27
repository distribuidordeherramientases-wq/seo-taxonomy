# Import-Export Lite: altas y actualizaciones por CSV

La importación por bloques crea o actualiza automáticamente y localiza productos por SKU o slug usando las API estándar de WooCommerce y WordPress. No se necesita ninguna categoría, etiqueta ni plugin TaxoCommerce adicional.

## Identidades de productos — versión 0.1.5

Los slugs de archivos multimedia no son identidades de productos: la búsqueda se limita al tipo de contenido solicitado. Un ZIP con el mismo slug no impide crear un producto.

Si un identificador de SKU/slug apunta a un registro antiguo que ya no existe, se considera una referencia huérfana, no un producto editable. Si hay un producto en la papelera, no se restaura ni actualiza automáticamente; se permite crear uno nuevo solo si el SKU está realmente disponible.

Un producto activo cuyo SKU o slug coincida se actualiza únicamente cuando WooCommerce lo puede cargar y las identidades no entran en conflicto. Si WooCommerce mantiene un SKU reservado o un registro activo que no puede cargar, el prechequeo detiene la importación con una explicación concreta. No se borran índices de SKU ni se modifican registros ajenos.

## Procedimiento de prueba con Mail Lite y Pro

1. En **Importar CSV**, localizar el bloque **Productos WooCommerce**.
2. Adjuntar el CSV de Mail Lite y Mail Pro y pulsar **Cargar CSV**. El servidor reconoce las cabeceras; no hay asignación manual ni selector de acción.
3. Pulsar **Comprobar CSV** y revisar el resultado de solo lectura.
4. Si aparecen **2 filas, 0 errores**, pulsar **Confirmar e importar**.
5. Comprobar ambos productos en **Productos** (borrador, precio, SKU, descargas y URL). Repetir el CSV para verificar que se actualizan sin duplicarse.

## Otros contenidos

Cada tipo tiene su propio bloque de importación y exportación, incluidas las etiquetas. Usa el CSV exportado como modelo de cabeceras. Los IDs ajenos se omiten. Las relaciones categories, tags, parent_slug y featured_image se reconocen cuando aparecen como cabeceras exactas; los términos o imágenes deben existir previamente. Las columnas desconocidas se muestran como omitidas y las celdas vacías no sobrescriben datos.

## Notas internas de mantenimiento

A partir de 0.1.7, estas notas se conservan en el repositorio, no como una pestaña de Lite. WordPress 6.7+, PHP 7.4+, WooCommerce activo; sin dependencia de Core, bases de datos propias ni taxonomías SEO. Se utilizan las API wp_insert_post, wp_insert_term y los objetos WC_Product, con permisos y nonces en las acciones. Los CSV se reciben fuera de Media, con token privado por usuario y expiración de una hora, bloques de 25 registros y límite de 30 MB. El servidor vincula el tipo y las columnas al token; el prechequeo es de solo lectura y requiere confirmación antes de escribir.

Los campos públicos escalares se descubren mediante el registro de metadatos y las tablas nativas postmeta/termmeta; los atributos globales proceden de WooCommerce y los locales de productos existentes. No se migran variaciones hijo, pedidos, clientes, campos privados/serializados ni medios binarios. Las relaciones requieren términos o imágenes existentes y las celdas vacías no modifican registros. El chequeo funcional valida arranque, APIs y componentes legibles; las revisiones profesionales de código corresponden a Pro y Plugin Check sigue siendo obligatorio antes de distribuir.
