# Import / Export

Ruta: **TaxoCommerce SEO → Herramientas → Import / Export**

## Pestañas
**Importación individual**, **Importación por lotes**, **Clonador PRO → STAGING**, **Catálogo semántico**, **Inventarios comerciales**, **Importar proveedor**, **Importar Amazon**, **Conexiones con proveedores**, **Catálogo de proveedores**, **Sincronización V2**.

## Importación individual
Cuando hay proceso activo: **Reintentar cola**, **Detener importación**, **Liberar importación bloqueada** y diagnóstico.

Productos: **Exportar productos**, **Exportar inventario reducido portable**. Categorías: Exportar e Importar.

### Importar traducciones de categorías (PR #914)

El nuevo bloque **Importar traducciones de categorías** se encuentra junto a **Importar categorías** en la pestaña **Importación individual** una vez que el PR #914 esté fusionado y desplegado. Admite un CSV UTF-8 separado por `;`, con **100 filas como máximo** y **8 MB como máximo**.

- **Archivo CSV:** selecciona un archivo `.csv` con los campos `source_slug;locale;titulo;slug;excerpt;description;parent_slug`. Los obligatorios son `source_slug`, `locale`, `titulo` y `slug`.
- **`source_slug`** es el slug actual de la categoría española de referencia; no se obtiene de un ID de STAGING/PRO ni por coincidencia aproximada del título. Debe existir en el entorno donde se importa.
- **`locale`** acepta `en-US`, `de-DE` y `nl-NL`. El idioma se guarda en `_seo_locale` (`termmeta`) y la nueva categoría `product_cat` se relaciona con su origen por `seo_relations.relation_type = category_translation`.
- **`parent_slug`** señala una categoría padre del mismo mercado; para categorías nuevas se debe resolver una raíz internacional o la traducción del padre español, en ningún caso copiar automáticamente el ID de padre español.
- **Botón Importar traducciones de categorías:** crea o actualiza la categoría traducida, guarda el contenido SEO en `seo_nodes`, registra la relación auditada y redirige a la pantalla mostrando cuántas categorías se crearon, actualizaron o vincularon y qué filas fallaron.

El servicio no traduce textos automáticamente, no cambia la categoría española, no asigna productos, no crea 301 y no debe utilizarse para importar las categorías internacionales ya existentes sin verificar su `source_slug` español. Una nueva importación con el mismo `slug` e idioma reutiliza la ficha; las relaciones existentes no se duplican. Ver [guía multidioma](../wiki/Categorias-multidioma.md) y [PR #914](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/pull/914).

Importar productos V2: checkboxes de Contenido, WooCommerce, Categorías, Etiquetas, Marca/proveedor, Ámbito, Atributos SEO, Atributos WooCommerce e Imágenes. Seguridad: **Simular primero**, **Crear productos simples**, **Mantener nuevos como borrador**, **Las celdas vacías eliminan el dato**. **Analizar / importar productos** ejecuta.

Páginas y Entradas tienen exportación por estado, modo de importación, checkboxes de bloques de datos y **Simular primero**. La exportación incluye **Papelera (`trash`)** cuando se marca ese estado. En importación, `estado=trash` envía a la papelera una página o entrada ya existente mediante el flujo nativo de WordPress; también se aceptan `papelera` y `basura` como alias. Un CSV con `trash` nunca crea un objeto nuevo en la papelera y nunca realiza borrado definitivo. Si WordPress tiene la papelera desactivada (`EMPTY_TRASH_DAYS=0`), la operación se bloquea para evitar una eliminación permanente. Si un objeto existente está en papelera y el CSV indica otro estado válido, se restaura antes de aplicar el nuevo estado. También existen Exportar/Importar FAQs y Redirects.

## Importación por lotes
1. Subir trabajos: **Añadir a pendientes**, **Añadir e iniciar**.
2. Estado: **Iniciar / continuar**, **Pausar cola después del archivo actual**, **Detener importación actual**, **Procesar siguiente bloque adaptativo**.
3. Gestor de archivos: **A pending** y **Borrar**.

## Clonador PRO → STAGING
Por conexión: checkbox **Activar esta conexión**, campos de BBDD, Contraseña, checkbox **Eliminar la contraseña guardada**, **Guardar conexión**, **Probar conexión**. STAGING puede reconstruirse de forma destructiva desde PRO; no usar como flujo de producción.

## Catálogo semántico
**Exportar catálogo semántico**, **Descargar paquete JSON**, selector de archivo/modo, **Simular importación**, checkbox de confirmación y **Aplicar paquete simulado**.

## Inventarios comerciales
Configuración por canal, **Guardar configuración**, **Iniciar generación**, **Parar generación**, tabla URLs e historial.

## Importar proveedor
Seleccionar Receta y Archivo, **Leer archivo y mostrar relaciones**, revisar columnas/transformaciones, seleccionar política y modo de imágenes. Checkboxes **Usar también este modo al actualizar** y **Este archivo contiene el catálogo completo**. **Crear CSV e importar al catálogo intermedio** ejecuta. En crawler: **Catálogo completo**, **Iniciar obtención**, **Detener todos y vaciar rastreos**.

## Importar Amazon
Partner Tag, checkbox para eliminarlo, Client ID, Secret, versión, Search Index y productos por llamada. **Guardar configuración**, **Explorar Amazon**, **Explorar** y **Probar conexión OAuth**.

## Catálogo de proveedores
Checkboxes de columnas visibles, filtros, checkboxes de selección y selector de acción masiva. Acciones por fila: **Aceptar**, **Descartar**, **Actualizar**, **Aplicar baja**, **Reactivar**, **Reintentar actualización**.

## Sincronización V2
Ejecuta sincronización de catálogo intermedio/proveedor según las reglas V2. Los controles de inicio/parada modifican procesos, no deben ejecutarse sin revisar alcance y entorno.
