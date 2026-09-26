# Herramientas

Ruta: **SEO Taxonomy → Herramientas**

Esta pantalla es un lanzador. El botón **Abrir** de cada tarjeta solo navega al módulo y no ejecuta cambios.

## Tarjetas visibles

| Herramienta | Finalidad |
|---|---|
| Taxonomy | Arquitectura SEO y relaciones estructurales |
| Templates | Plantillas, asignación, disponibilidad y PRO → STAGING |
| Search | Buscador, campos, filtros y estadísticas |
| Redirects | Redirecciones, importación/exportación y diagnóstico |
| Marketing | Campañas, identidad, redes, sitemaps y estilo |
| Data Table | Exploración de tablas, backups y rollback |
| Clean DB | Auditoría, reparación y limpieza controlada |
| Import / Export | Importaciones, proveedores, catálogos y colas |
| Procesos | Estado, velocidad y workers |
| Logística | Pedidos/proveedores y transporte |
| Conexiones con proveedores | Credenciales e integraciones externas |
| FAQs | Gestión, cobertura y calidad de FAQs |
| Estado del servidor | Servidor, WordPress, MySQL, seguridad y logs |
| Plugin Validation | Validación interna del plugin |
| Menu Manager | Generación y sincronización del menú SEO |
| Facturas y presupuestos | Documentos PDF conectados a WooCommerce |
| Solucionador | Propuestas editoriales a partir de señales |
| Ojeador | Mercado por categorías en Google Shopping |

## Taxonomy

Pestañas: **Gestión SEO**, **Taxonomía** y **Semántica**.

### Gestión SEO
Cada Cluster permite **Toggle**, **Guardar cambios**, **Eliminar** y seleccionar mediante checkboxes sus Hubs primarios. Los Hubs primarios permiten Toggle, Guardar, Eliminar y seleccionar Hubs secundarios. Los Hubs secundarios permiten Toggle, Guardar, Eliminar, buscar categorías disponibles y marcarlas mediante checkboxes.

En **Reasignación de Categorías**, el selector **Reasignar a…** elige el nuevo Hub secundario y **Modificar** [Modifica] cambia la relación.

### Taxonomía
Vista jerárquica de lectura para comprobar el árbol efectivo.

### Semántica
Abre la pantalla canónica de Etiquetas/Vocabulary.

## Templates

Pestañas: **Asignación a páginas**, **Archivos de plantilla**, **Disponibilidad y activación**, **PRO → STAGING**.

- **Activar plantilla específica para teléfono y ordenador**: usa variantes por dispositivo cuando existen.
- checkboxes de páginas asignadas/disponibles.
- **Guardar asignaciones y modo de renderizado** [Guarda].
- **Buscar plantilla** [Consulta].
- **Reemplazar principal y crear backup / Crear principal faltante** [Modifica].
- **Reemplazar secundaria y crear backup / Crear secundaria** [Modifica].
- **Subir y registrar principal** [Guarda].
- **Registrar archivo principal** [Guarda].
- Campos: Clave única, Nombre visible, Archivo y Tipo inicial.
- Tabla de disponibilidad: Orden, Plantilla, Archivo, Tipo, Modo, Usable, Activa, Asignable, Descripción.
- checkboxes **Usable**, **Activa** y **Asignable**.
- **Guardar disponibilidad y activación** [Guarda].
- En PRO → STAGING: **Copiar ahora**, checkbox de confirmación y **Copiar PRO → STAGING** [Modifica]. Esta operación está destinada a STAGING.

## Search

Pestañas: **General**, **Campos de búsqueda**, **Resultados y filtros**, **Búsqueda con filtros**, **Estadísticas**.

### General
Resultados por página, Página de resultados, Parámetro de URL, checkbox **Activar sugerencias mientras se escribe**, mínimo de caracteres, número de sugerencias, checkbox **Activar coincidencias aproximadas**, límite fuzzy, Sinónimos y checkbox **Registrar términos y número de resultados**. El botón estándar **Guardar cambios** persiste la configuración.

### Campos de búsqueda
Checkboxes: Título, Descripción, SKU, Categorías, Vocabulario canónico y Atributos. Campo **Metadatos personalizados** para claves separadas por comas.

### Resultados y filtros
Checkboxes de tarjeta: imagen, precio, disponibilidad, SKU, categorías y descripción corta. Selector Cuadrícula/Lista, número de columnas y checkboxes de filtros de categoría, marca, atributos, precio y disponibilidad. Campos Taxonomía de marca y Mensaje sin resultados.

### Búsqueda con filtros
Checkbox **Activar filtros semánticos canónicos**; checkboxes ROL, TIPO, APLICACIÓN, PLATAFORMA y SUBTIPO; checkboxes **Mostrar cantidad** y **Aplicar automáticamente**. Muestra el shortcode `[advanced_search]` y una vista previa.

### Estadísticas
KPIs de 30 días, tablas Más buscado/Sin resultados y **Eliminar historial** [Elimina] con confirmación.

## Redirects

Bloque **Exportar / importar / analizar**, botón **Importar solo nuevas**, listado **Requieren revisión** y alta manual con URL origen, URL destino y Tipo. El buscador filtra la tabla ID, Origen, Destino, Diagnóstico, Tipo, Hits, Último uso y Acciones.

## Data Table

Pestañas: **Resumen**, **Explorar tablas**, **Exportar / Backup**, **Operaciones**.

En Explorar: Tabla, Buscar en toda la tabla, Columna, Valor, Filas y **Aplicar**. En Exportar: CSV/SQL por tabla y Backup completo. En Operaciones: Buscar, Estado, Módulo, Riesgo, **Filtrar**, detalle de operación y **Revertir** [Modifica] cuando existe rollback.

## Clean DB

Pestañas: **Errores en datos**, **Limpieza BBDD**, **Exportar BBDD**.

Errores en datos revisa relaciones SEO, nodos sin objeto activo, duplicados, múltiples ámbitos, jerarquía de categorías y restablecimiento manual. El restablecimiento pide ID del objeto y Rol SEO; **Restablecer objeto SEO** modifica el registro.

Limpieza BBDD lista acción, encontrados y riesgo. Exportar BBDD ofrece **Backup completo de WordPress** y **Backup solo SEO System**.

## Procesos

Pestañas base: **Procesos** y **Gestor de workers**.

En Procesos: **Actualizar ahora**, monitor en tiempo real y tabla Proceso / Estado / Velocidad-respuesta / Regulador-carga / Detalle.

La sección Control de velocidad muestra, según proceso, lote mínimo/inicial/máximo, tiempos objetivo/crítico, multiplicadores, memoria/CPU preventiva y crítica, pausas, workers y p95. **Guardar velocidades** guarda; **Restaurar valores originales** restablece.

Gestor de workers: checkbox **Gestor activo**; frecuencia, ventana y espera de reintento; checkboxes para Import/Export, Inventarios comerciales, Importación proveedores, Academia, Lingüista, Clasificador, Clonador para Academia, WP-Cron de respaldo y Registrar cada ciclo. Botones **Guardar configuración**, **Activar gestor**, **Reiniciar gestor**, **Comprobar procesos ahora**, **Detener gestor** y **Vaciar log**.

## Logística

Pestañas: **Gestión de pedidos** y **Transporte**.

Gestión de pedidos muestra Pedido, Estado, Object ID, Producto WooCommerce, Cantidad, Proveedor, ID/SKU del proveedor, Producto proveedor y Compra.

Transporte: checkboxes **Activar gestor propio**, **Solo España**, **Transporte sujeto a impuestos** y **No usar reglas avanzadas si faltan peso o medidas**; campos Nombre que verá el cliente y Zona provisional. **Añadir regla** crea una regla. Cada regla tiene checkbox Activa, Eliminar, Prioridad, Nombre, Destino, límites de subtotal/peso/dimensiones/volumen y coste fijo/por kg/por unidad/gratuidad.

## FAQs

Pestañas: **Hubs SEO**, **Categorías**, **Productos**, **Informe**.

En edición: selector de elemento, **Nueva FAQ**, Orden, Pregunta, Respuesta, checkbox **Activa** y Guardar/Actualizar.

En Informe: Buscar, Nivel, Estado, Diagnóstico, Min. renders, Min. aperturas, Orden y **Aplicar filtros**. Incluye KPIs, calidad, diagnóstico editorial, cobertura e interacción. Limpiezas: **Eliminar copias seleccionadas**, **Eliminar todas las copias**, **Eliminar copias del grupo**, **Eliminar seleccionadas** y **Eliminar todas las huérfanas**.

## Estado del servidor

Pestañas: **Resumen**, **PHP**, **MySQL**, **WordPress**, **Seguridad**, **Servidor**, **WooCommerce**, **Rendimiento**, **Logs**.

**Ejecutar chequeo completo** [Proceso] guarda un snapshot. **Rotar secreto** [Modifica] exige actualizar la variable SEO_MONITOR_SECRET en GitHub. El resto de tarjetas son diagnósticas.

## Plugin Validation

Pestañas: **Resumen**, **Integridad del código**, **Chequeos avanzados**, **Configuración**.

Acciones: **Ejecutar validación completa**, **Ejecutar siguiente bloque**, repetir los bloques de auditoría 404, **Reiniciar auditoría**, **Actualizar chequeos pasivos** y **Ejecutar prueba transaccional controlada**. En diagnósticos hay checkbox de autorización para envío automático, **Guardar autorización** y **Enviar ahora el último diagnóstico**.

## Menu Manager

Checkboxes: **Incluir Soluciones**, **Incluir Blog**, **Incluir Dependiente**.

Acciones principales: **Previsualizar menú**, **Sincronizar menú creado**, **Activar menú SEO**, **Restaurar menú anterior**.

Para añadir elementos hay paneles de Páginas, Entradas, Categorías y Enlaces personalizados con Más reciente / Ver todo / Buscar, checkboxes y botones de añadir. En enlaces personalizados se introducen URL y Texto del enlace.
