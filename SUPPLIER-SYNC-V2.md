# Supplier Import / Sync V2

## Objetivo

Unificar la entrada de catalogos de proveedores independientemente de su origen:

- CSV/XLS/XLSX entregado por el proveedor.
- CSV generado por el crawler PHP.
- CSV generado por un scraper Python u otro proceso externo.

Todos los origenes terminan en el mismo CSV normalizado y en el mismo motor de sincronizacion.

## Identidad

La identidad comercial se resuelve por `proveedor + SKU`. El `object_id` de WordPress se crea una sola vez y se conserva en actualizaciones, bajas y reactivaciones.

## Estados separados

`estado_seleccion` responde a si el producto forma parte del catalogo comercial.

`estado_sincronizacion` indica el trabajo tecnico pendiente:

- `nuevo`
- `sin_cambios`
- `actualizar`
- `reactivar`
- `baja_pendiente`
- `baja_aplicada`
- `error`
- `ignorado`

## Actualizaciones seguras

En productos existentes se actualizan los datos del proveedor y se protegen los datos editoriales ya trabajados en WordPress:

- titulo y slug
- descripcion y excerpt
- categorias
- atributos
- etiquetas

Se siguen sincronizando precios, stock, snapshot comercial, MPN/EAN, URLs, marca e imagenes segun el modo guardado.

## Imagenes

Cada fila de proveedor guarda `modo_imagenes`:

- `external`: no descarga imagenes; reconstruye `wp_seo_supplier_images` y sirve las URLs del proveedor.
- `local`: usa la biblioteca de medios de WordPress.
- `inherit`: modo historico, resuelto automaticamente sin cambiar el comportamiento existente.

La prioridad visual es:

1. imagen local valida de WordPress;
2. imagen externa activa del proveedor;
3. logo configurado de la tienda;
4. placeholder de WooCommerce solo si no existe logo.

Si una URL externa falla en el navegador, se sustituye visualmente por el logo de la tienda.

## Productos nuevos

Los productos nuevos quedan `pendiente` hasta aceptacion. Al crear un nuevo `object_id`, se asigna la categoria `Nuevos productos` si existe. Las siguientes sincronizaciones no cambian esa categoria ni cualquier categoria posterior elegida manualmente.

## Bajas

Las bajas solo se detectan cuando una ejecucion se declara `catalogo completo` y termina sin errores ni filas omitidas.

Un SKU ausente pasa a `baja_pendiente`. Aplicar la baja:

- pone stock agotado;
- oculta el producto del catalogo;
- cambia el estado a borrador;
- conserva object_id, contenido, categorias, SEO e historial.

Si el SKU reaparece, se reactiva el mismo `object_id`.

## Crawler

El crawler conserva su historico, pero cada CSV automatico se construye solo con registros vistos desde el inicio del ciclo actual. Solo el cierre correcto de la cola puede declarar el CSV como catalogo completo.

## Migracion

La migracion V2 es aditiva. No borra tablas ni filas existentes. Reutiliza:

- `wp_seo_proveedores_productos`
- `wp_seo_supplier_images`
- los CSV guardados
- los `object_id` existentes

Se crean dos tablas de auditoria:

- `wp_seo_supplier_import_runs`
- `wp_seo_supplier_import_run_items`

## Cola de aplicacion

Las actualizaciones automaticas y las solicitadas desde la pantalla de Sincronizacion V2 se encolan mediante Action Scheduler cuando esta disponible. Si no lo esta, se usa WP-Cron y, como ultimo recurso, ejecucion inmediata. Esto evita intentar actualizar cientos de productos dentro de una sola peticion HTTP.

## Primera ejecucion V2

Las filas historicas aceptadas que todavia no tienen `hash_aplicado` se sincronizan una vez para establecer una linea base. Esta primera sincronizacion conserva el `object_id` y restaura el contenido editorial protegido; tambien repara galerias externas desalineadas, por ejemplo cuando el proveedor declara 20 URLs y solo existe una relacion activa.
