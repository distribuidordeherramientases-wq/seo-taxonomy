# Productos

Ruta: **SEO Taxonomy → Productos**

## Pestañas

| Pestaña | Uso |
|---|---|
| Nuevo producto | Alta manual de un producto |
| Editar producto | Localizar y editar productos existentes |
| Inventario | Auditoría del catálogo y cobertura |
| Recategorizar | Mover productos entre categorías |
| Tamaños | Revisar peso y dimensiones |
| Informes Google | Rendimiento y señales externas |
| Errores | Disponibilidad y diagnóstico técnico |

## Nuevo producto / Editar producto

### Identidad del producto

| Control | Qué significa |
|---|---|
| Título | Nombre visible del producto. Obligatorio en el alta. |
| SKU | Referencia WooCommerce. Obligatoria en el alta. |
| Slug | Fragmento de URL. Debe ser estable y descriptivo. |
| Estado | Borrador, Publicado, Pendiente o Privado. Cambiar a Publicado afecta al catálogo público. |
| ID WooCommerce | Solo lectura en edición; identifica el objeto existente. |

### Proveedor y marca

| Campo | Función |
|---|---|
| Proveedor | Proveedor asociado. En alta se exige para mantener trazabilidad. |
| Referencia externa | Identificador del proveedor; si falta puede utilizarse el SKU. |
| MPN / referencia fabricante | Referencia del fabricante. |
| Marca | Marca comercial. |
| Fabricante | Fabricante cuando no coincide con la marca. |
| Categoría del proveedor | Clasificación original recibida. |
| Coste / precio proveedor | Coste de referencia para cálculos comerciales. |
| URL origen del proveedor | Enlace a la ficha de origen. |

### Venta y stock

- **Precio normal**: precio regular.
- **Precio oferta**: precio promocional si existe.
- **Estado stock**: En stock, Agotado o Bajo pedido.
- **Gestionar cantidad de stock**: checkbox que activa el control por unidades.
- **Cantidad**: unidades cuando la gestión de stock está activada.
- **Peso / Longitud / Anchura / Altura**: datos logísticos usados por informes y transporte.

### Categoría y semántica

- **Categorías WooCommerce**: selección múltiple de categorías comerciales.
- **TIPO**: clasificación semántica principal.
- **ROL derivado**: valor calculado a partir del vocabulario; se muestra como referencia.
- **APLICACIÓN**, **PLATAFORMA** y **SUBTIPO**: selecciones múltiples complementarias.
- **Atributos técnicos**: admite líneas tipo atributo|valor y muestra el vocabulario permitido.

### Contenido

- **Extracto**: resumen corto.
- **Descripción**: contenido largo de la ficha.

### Imágenes

Selector de modo:

- **Biblioteca Media**: usa adjuntos de WordPress.
- **Externas del proveedor**: guarda URLs externas relacionadas con el producto.
- **Sin imágenes**: retira la selección y desactiva las relaciones externas correspondientes.

Botones visibles:

- **Seleccionar imagen principal** [Guarda]: abre la biblioteca y elige la destacada.
- **Seleccionar galería** [Guarda]: selecciona varias imágenes.
- **Vaciar selección** [Modifica]: limpia la selección local del formulario.
- **Crear producto / Guardar producto** [Guarda][Producción]: persiste los campos.
- **Ver producto** [Consulta]: abre la ficha del producto editado.

## Editar producto: listado

Filtros visibles: **Buscar**, **Categoría**, **Estado**, **Proveedor** y **Filtrar**.

La tabla muestra ID, SKU, producto, puntuación Google 28 días, proveedor, estado y acciones. El acceso de edición abre el formulario anterior.

## Inventario

Filtros visibles:

- Buscar;
- Estado;
- Stock;
- Proveedor;
- Marca;
- Cluster;
- Hub primario;
- Hub secundario;
- Categoría;
- Cobertura semántica;
- cobertura de categoría;
- Atributos;
- Vínculo proveedor;
- Contenido editorial;
- Valor de atributo contiene;
- Orden;
- Por página.

**Aplicar filtros** [Consulta] reconstruye la lista. La tabla muestra ID, producto, SKU/estado, categorías, Vocabulary, origen, cobertura y acciones. **Editar SEO** abre el producto correspondiente.

## Recategorizar

### Movimiento directo por IDs

- **IDs de productos**: lista de IDs a mover.
- **ID categoría destino**: categoría que sustituirá la clasificación seleccionada.
- **Mover productos de categoría** [Modifica]: realiza el movimiento. La pantalla advierte que las categorías actuales pueden ser sustituidas.

### Navegación y selección

Filtros: Cluster, Hub primario, Hub secundario, Categoría y Buscar producto.

Cada fila tiene checkbox **Mover**. Debajo:

- **Mover a categoría…**: selector de destino.
- **Mover seleccionados** [Modifica]: aplica el destino a los productos marcados.

## Tamaños

### Umbrales de peso
- Ligero hasta.
- Mediano hasta.
- Pesado desde.

### Umbrales de tamaño
- Pequeño hasta.
- Mediano hasta.
- Grande desde.

Botones:

- **Guardar límites** [Guarda].
- **Restablecer valores** [Modifica]: vuelve a los umbrales definidos por defecto.

La pantalla muestra gráficos de peso, tamaño y matriz peso × tamaño, más una tabla paginada con ID, producto, SKU, tipo, peso, grupo de peso, dimensiones, lado máximo y grupo de tamaño. El selector 25/50/100 cambia filas por página; **Anterior/Siguiente** navega.

## Informes Google

Filtros: Buscar, Categoría, Proveedor y Periodo. **Filtrar** aplica y **Limpiar** restablece.

La tabla muestra ID, SKU, producto, puntuación del periodo, impresiones, clics, visitas, compras, proveedor, estado y acción. El detalle reúne Search Console, GA4 y rendimiento de ecommerce cuando las fuentes están disponibles.

## Errores

Comparte el escáner de salud del plugin. Controles principales:

- **Iniciar escaneo** [Proceso].
- botones de tarea según el alcance disponible [Proceso].
- **Parar escaneo** [Proceso].
- filtros de estado y **Buscar** [Consulta].

La tabla informa elemento, estado, HTTP, tiempo, detalle y último chequeo.
