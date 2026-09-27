# Etiquetas y vocabulario

Ruta: **SEO Taxonomy → Etiquetas**

La pantalla concentra la semántica canónica y los atributos técnicos.

## Dominios superiores

- **Etiquetas**
- **Atributos**
- **Asignación**
- **Google esquema**

## Etiquetas

Subpestañas: **Productos**, **Gestionar etiquetas**, **Diccionario** y **Control**.

### Productos

Filtros:

- Producto / SKU / ID.
- Categoría.
- TIPO contiene.
- Cobertura.
- Filas.
- **Filtrar** / **Limpiar** [Consulta].

Tabla: Producto, Etiquetas WooCommerce, Ámbito/ROL, TIPO, APLICACIÓN, PLATAFORMA, SUBTIPO, Alineación y Acción.

### Gestionar etiquetas

**Alta de etiqueta**:

- Grupo.
- Nombre visible.
- ROL para TIPO: se usa cuando el nuevo término pertenece al grupo TIPO.
- **+ Dar de alta** [Guarda].

Filtros: Grupo, Estado, Buscar término y **Filtrar**.

Tabla: ID, Nombre/edición, Slug, Estado, ROL asociado, TIPOS activos, Productos, Asignaciones, Fuente y Acciones.

Acciones:

- selector **ROL asociado** [Guarda al confirmar].
- **Guardar** [Guarda].
- **Dar de baja** [Modifica]: desactiva el término sin tratarlo como una reclasificación.
- **Reactivar** [Modifica].

### Diccionario

Filtros: Grupo, Buscar, **Filtrar** y **Limpiar**. La tabla muestra Grupo, Valor permitido, Slug, ROL asociado, Productos, Asignaciones e ID interno.

### Control

**Control de integridad** reúne diagnósticos sobre el vocabulario y sus relaciones. Es una vista de revisión salvo que aparezca una acción explícita.

## Atributos

Subpestañas: **Productos**, **Atributos**, **Términos y aliases** y **Control**.

### Definición de atributo

Campos:

- Slug.
- Nombre visible.
- Grupo.
- Tipo.
- Unidad base.
- Orden.
- Tipo unidad.

Checkboxes:

- **Múltiple**: admite más de un valor.
- **Filtrable**: permite utilizarlo como filtro.
- **Visible**: puede mostrarse al usuario.
- **SEO**: se considera relevante en la capa SEO.
- **Activo**: habilita la definición.

Botones:

- **Crear atributo** / **Guardar cambios** [Guarda].
- **Eliminar** [Elimina]: borra la definición seleccionada; revisar relaciones antes.

Tabla: Atributo, Grupo, Tipo, Unidad, Productos, Asignaciones, Términos, Estado y Acción.

### Términos y aliases

- selector **Atributo**.
- Nombre.
- Slug.
- Orden.
- checkbox **Activo**.
- **Añadir término** / **Guardar término** [Guarda].
- **Añadir** alias [Guarda].
- **× / Eliminar** [Elimina].

La tabla muestra Término, Productos, Estado, Aliases y Acciones.

### Productos: cobertura de atributos

Filtros: Producto/SKU/ID, Categoría, Cobertura, Filas, **Filtrar** y **Limpiar**.

Tabla: Producto, Categorías, Atributos canónicos, Cobertura, Modificado y Acción.

## Asignación

Filtros: Buscar, Categoría, Cobertura, Prioridad, Filas, **Filtrar** y **Limpiar**.

Flujo de trabajo:

1. **Analizar filtro · rápido** [Proceso]: genera propuestas con la pasada ligera.
2. **Analizar filtro · profundo** [Proceso]: usa la revisión más intensa.
3. **Aplicar propuestas seguras** [Modifica]: aplica únicamente las consideradas seguras por el proceso.
4. **Aceptar todas las propuestas viables** [Modifica]: acción masiva; revisar el filtro antes.
5. **Confirmar datos** [Guarda].
6. **Confirmar fila** [Guarda]: acepta solo la fila revisada.

Controles del proceso: **Pausar**, **Reanudar** y **Cancelar** [Proceso].

Las tablas presentan producto/categoría, cobertura o prioridad y las propuestas de TIPO, ROL, APLICACIÓN, PLATAFORMA y SUBTIPO. **Asignación asistida** y **Revisión manual** separan propuestas automáticas de decisiones humanas.

## Google esquema

Gestiona el mapeo hacia la taxonomía de Google. La vista **Taxonomía Google** permite revisar las equivalencias. La aprobación de una categoría habilita su uso por servicios como Google Shopping/Ojeador cuando también existe una consulta adecuada. Aceptar un mapeo no cambia por sí solo los productos de categoría.
