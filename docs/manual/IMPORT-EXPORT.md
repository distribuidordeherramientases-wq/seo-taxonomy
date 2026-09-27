# Import / Export

Ruta: **SEO Taxonomy → Herramientas → Import / Export**

## Pestañas
**Importación individual**, **Importación por lotes**, **Clonador PRO → STAGING**, **Catálogo semántico**, **Inventarios comerciales**, **Importar proveedor**, **Importar Amazon**, **Conexiones con proveedores**, **Catálogo de proveedores**, **Sincronización V2**.

## Importación individual
Cuando hay proceso activo: **Reintentar cola**, **Detener importación**, **Liberar importación bloqueada** y diagnóstico.

Productos: **Exportar productos**, **Exportar inventario reducido portable**. Categorías: Exportar e Importar.

Importar productos V2: checkboxes de Contenido, WooCommerce, Categorías, Etiquetas, Marca/proveedor, Ámbito, Atributos SEO, Atributos WooCommerce e Imágenes. Seguridad: **Simular primero**, **Crear productos simples**, **Mantener nuevos como borrador**, **Las celdas vacías eliminan el dato**. **Analizar / importar productos** ejecuta.

Páginas y Entradas tienen exportación por estado, modo de importación, checkboxes de bloques de datos y **Simular primero**. También existen Exportar/Importar FAQs y Redirects.

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
