# Import-Export Lite: altas y actualizaciones por CSV

El modo **Crear nuevos y actualizar existentes** localiza productos por SKU o slug usando las API estándar de WooCommerce y WordPress. No se necesita ninguna categoría, etiqueta ni plugin TaxoCommerce adicional.

## Identidades de productos — versión 0.1.4

Los slugs de archivos multimedia no son identidades de productos: la búsqueda se limita al tipo de contenido solicitado. Un ZIP con el mismo slug no impide crear un producto.

Si un identificador de SKU/slug apunta a un registro antiguo que ya no existe, se considera una referencia huérfana, no un producto editable. Si hay un producto en la papelera, no se restaura ni actualiza automáticamente; se permite crear uno nuevo solo si el SKU está realmente disponible.

Un producto activo cuyo SKU o slug coincida se actualiza únicamente cuando WooCommerce lo puede cargar y las identidades no entran en conflicto. Si WooCommerce mantiene un SKU reservado o un registro activo que no puede cargar, el prechequeo detiene la importación con una explicación concreta. No se borran índices de SKU ni se modifican registros ajenos.

## Procedimiento de prueba con Mail Lite y Pro

1. Seleccionar **Productos WooCommerce**.
2. Cargar el CSV de Mail Lite y Mail Pro y mantener la asignación automática de SKU, slug, nombre, precio, virtual, descargable y URL de descarga. Categorías y etiquetas vacías/no asignadas.
3. Elegir **Crear nuevos y actualizar existentes**.
4. Pulsar **Comprobar CSV**; se valida el archivo íntegro sin guardar ningún producto.
5. Si aparecen **2 filas, 0 errores**, pulsar **Confirmar e importar**.
6. Comprobar ambos productos en **Productos** (borrador, precio, SKU, descargas y URL). Repetir el CSV para verificar que se actualizan sin duplicarse.
