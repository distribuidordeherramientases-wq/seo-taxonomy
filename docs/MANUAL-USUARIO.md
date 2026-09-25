# Manual de usuario de SEO Taxonomy

## 1. Objetivo de este manual

Este documento explica cómo utilizar SEO Taxonomy desde el panel de administración de WordPress.

La introducción general del proyecto explica la arquitectura y los conceptos. Este manual baja un nivel más: **qué hace cada pantalla, para qué sirve cada pestaña, qué significan sus controles y qué ocurre al pulsar cada botón**.

No es una referencia de código.

---

## 2. Convenciones utilizadas

Para identificar rápidamente el efecto de un control se utilizan estas etiquetas:

- **[Consulta]**: cambia la vista o lee datos; no modifica el catálogo.
- **[Guarda]**: guarda configuración, asignaciones o contenido.
- **[Modifica]**: cambia datos existentes.
- **[Elimina]**: elimina o desactiva información. Revisar antes de confirmar.
- **[Proceso]**: inicia, pausa, reanuda o controla un proceso automático.
- **[API]**: utiliza un servicio externo y puede consumir cuota.
- **[Exporta]**: genera un archivo o salida para revisión externa.

Cuando una acción sea reversible mediante el Data Layer o exista una protección específica, la pantalla correspondiente debe indicarlo.

---

# 3. Navegación principal

SEO Taxonomy registra actualmente estas áreas principales:

- Inicio
- Productos
- Categorías
- Etiquetas
- Páginas
- Entradas
- Imágenes
- Informes
- Dependiente
- Herramientas

**Herramientas** actúa como acceso a servicios avanzados como Taxonomy, Templates, Search, Redirects, Marketing, Data Table, Clean DB, Import / Export, Procesos, Logística, Conexiones con proveedores, FAQs, Estado del servidor, Plugin Validation y Menu Manager.

Algunos servicios registran sus propias subpestañas de forma dinámica. Por ello una instalación puede mostrar opciones adicionales cuando el módulo correspondiente está activo.

---

# 4. Productos

Ruta: **SEO Taxonomy → Productos**

## Pestañas

### Nuevo producto
Permite dar de alta manualmente un producto utilizando el flujo canónico del plugin.

**Uso recomendado:** productos individuales que no proceden de una importación masiva.

### Editar producto
Permite localizar y modificar productos existentes.

**Efecto:** **[Modifica]**. Los cambios guardados afectan al producto seleccionado.

### Inventario
Vista de control del catálogo de productos.

Se utiliza para revisar productos, buscar referencias y detectar información incompleta.

### Recategorizar
Herramienta para revisar o modificar la categoría de productos.

**Efecto:** **[Modifica]**. La recategorización cambia la estructura comercial y SEO del producto. No debe aplicarse de forma masiva sin revisar la categoría destino.

### Tamaños
Analiza peso y dimensiones del catálogo y permite revisar productos fuera de los umbrales configurados.

### Informes Google
Agrupa información de rendimiento o señales de Google asociadas al catálogo.

### Errores
Muestra incidencias detectadas para productos.

## Recomendación de uso

1. Localizar el producto.
2. Comprobar identidad, proveedor, SKU y categoría.
3. Revisar contenido y atributos.
4. Guardar solo después de confirmar que se está editando la referencia correcta.
5. Si la modificación afecta a la clasificación, comprobar posteriormente Vocabulary, categorías y Dependiente.

---

# 5. Categorías

Ruta: **SEO Taxonomy → Categorías**

## Pestañas actuales

- **Categorías**
- **Informe categorías**
- **Reasignación de Categorías**
- **Informes Google**
- **Inventario**
- **Tabla catálogo**

## Categorías

Es la pantalla principal de mantenimiento de categorías WooCommerce.

Incluye la creación de nuevas categorías y la relación con la arquitectura SEO.

### Crear nueva categoría

La interfaz permite trabajar con la jerarquía:

1. Cluster
2. Hub primario
3. Hub secundario
4. Categoría destino

**[Guarda]** La creación o asignación incorpora la categoría a la estructura elegida.

Antes de crear una categoría nueva se debe comprobar que no existe otra categoría que cubra la misma intención.

## Informe categorías

Presenta controles y métricas para revisar la calidad y cobertura de las categorías.

## Reasignación de Categorías

Permite mover categorías entre nodos estructurales.

**[Modifica]** Puede alterar navegación, relaciones internas y arquitectura SEO.

## Inventario

Incluye filtros como:

- búsqueda de categoría, producto o SKU;
- estado;
- orden;
- filas por página.

La tabla distingue productos reales, publicados, no publicados y el contador de WooCommerce.

## Tabla catálogo

Vista orientada a revisar el catálogo distribuido por categorías.

---

# 6. Etiquetas y Vocabulary

Ruta: **SEO Taxonomy → Etiquetas**

Esta área concentra la semántica canónica y los atributos técnicos.

## Áreas principales

- **Etiquetas**
- **Atributos**
- **Asignación**
- **Google esquema**

### Etiquetas

Subsecciones visibles:

- Productos
- Gestionar etiquetas
- Diccionario
- Control

Los grupos semánticos utilizados por el sistema incluyen, según el contexto, TIPO, ROL, APLICACIÓN, PLATAFORMA y SUBTIPO.

### Alta de etiqueta

Campos principales:

- Grupo
- Nombre visible
- ROL asociado cuando el nuevo término es un TIPO

Botón **+ Dar de alta** — **[Guarda]** crea el término semántico.

### Gestionar etiquetas

Permite filtrar por grupo, estado y texto.

Acciones disponibles:

- **Guardar** — **[Guarda]**
- **Dar de baja** — **[Modifica]**
- **Reactivar** — **[Modifica]**

Dar de baja no debe utilizarse como sustituto de una reclasificación correcta.

### Diccionario

Muestra los valores permitidos para los editores y su relación con grupos, slugs, ROL, productos y asignaciones.

### Atributos

Subsecciones:

- Productos
- Atributos
- Términos y aliases
- Control

La definición de un atributo puede incluir:

- slug;
- nombre visible;
- grupo;
- tipo;
- unidad base;
- orden;
- múltiple;
- filtrable;
- visible;
- SEO;
- activo;
- tipo de unidad.

Los botones **Crear atributo**, **Guardar cambios**, **Añadir término** y **Añadir alias** guardan cambios en el catálogo semántico.

**Eliminar** es una acción destructiva y debe revisarse antes de ejecutarse.

### Asignación

Dispone de filtros por producto, categoría, cobertura, prioridad y número de filas.

Procesos disponibles:

- **Analizar filtro · rápido** — **[Proceso]**
- **Analizar filtro · profundo** — **[Proceso]**
- **Aplicar propuestas seguras** — **[Modifica]**
- **Aceptar todas las propuestas viables** — **[Modifica]**
- **Pausar / Reanudar / Cancelar** — **[Proceso]**
- **Confirmar fila** — **[Guarda]**

El análisis no equivale a la aplicación. Las propuestas deben revisarse antes de aceptar cambios masivos.

### Google esquema

Relaciona la taxonomía propia con la clasificación utilizada por Google. La aprobación del mapeo permite que otros servicios, como Ojeador, trabajen con categorías reconocibles y consultas comerciales controladas.

---

# 7. Páginas

Ruta: **SEO Taxonomy → Páginas**

## Pestañas

- Estructura SEO
- Landings
- Informe landings
- Corporativas
- Errores

## Editor

Campos visibles:

- Título
- Slug
- Rol SEO
- Estado
- Extracto
- Contenido

La pantalla permite relacionar una página con:

1. Cluster
2. Hub primario
3. Hub secundario
4. Categoría de producto

Controles importantes:

- **Crear borrador** — **[Guarda]**
- **Añadir categoría seleccionada** — **[Guarda]**
- **Añadir directa** — **[Guarda]**
- **Guardar esta página** — **[Guarda]**
- **Descartar del mapa** — **[Modifica]**
- **Borrar de WordPress** — **[Elimina]**

**Borrar de WordPress** es una acción distinta de retirar una página de la arquitectura SEO.

---

# 8. Entradas

Ruta: **SEO Taxonomy → Entradas**

## Pestañas

- Editar posts
- Oportunidades posts
- Errores

## Editor

Campos principales:

- Título
- Slug
- Estado
- Excerpt
- Contenido
- Categorías de producto
- Etiquetas semánticas

Controles:

- **Marcar visibles / Desmarcar visibles** — cambia selecciones en pantalla.
- **Guardar** — **[Guarda]**.
- **Enviar a la papelera** — **[Elimina]** del flujo editorial activo.

La lista permite filtrar por búsqueda, categoría de producto y estado. También muestra Vocabulary y puntuación de Google cuando está disponible.

---

# 9. Imágenes

Ruta: **SEO Taxonomy → Imágenes**

## Pestañas

- Inventario
- Anomalías
- Asignación
- Errores
- Liberar espacio

## Inventario

Muestra formatos de Media local y el inventario externo por proveedor.

Incluye datos de proveedor, número de imágenes, productos, promedio y referencias sin product_id.

## Anomalías

Permite localizar, entre otros:

- productos con pocas imágenes externas;
- imágenes externas sin producto;
- referencias de Media inexistentes;
- Media local sin referencias.

## Asignación

Controles principales:

- **Asignar automáticamente hasta 25** — **[Modifica]**.
- **Subir y asignar** — **[Guarda]** una imagen y la relaciona con el elemento seleccionado.

## Liberar espacio

Las operaciones de eliminación deben comprobar que el recurso no tiene referencias activas.

**Eliminar seleccionadas sin uso** — **[Elimina]**.

---

# 10. Informes

Ruta: **SEO Taxonomy → Informes**

## Pestañas

- Informes
- Panel
- Contenido
- Anomalías
- Analista

La pantalla permite crear y cerrar informes y agrupa métricas de visibilidad orgánica, rendimiento de posts, landing pages, estructura y calidad de datos.

Controles importantes pueden incluir:

- **Crear informe** — **[Proceso]**
- **Cerrar informe**
- **Recalcular contadores** — **[Proceso]**
- limpieza de FAQs huérfanas — **[Elimina]**
- eliminación de categorías seleccionadas cuando el informe las considera eliminables — **[Elimina]**

Una recomendación de informe es una señal de revisión. No debe convertirse automáticamente en una modificación del catálogo sin validar el contexto.

---

# 11. Dependiente

Ruta: **SEO Taxonomy → Dependiente**

Dependiente es el buscador guiado y comparador orientado al frontal de la tienda.

## Configuración y estado

La pantalla incluye:

- Estado del catálogo
- Configuración del piloto
- Resultados por página
- Tarjetas visuales por bloque
- Correo para consultas no resueltas
- Imágenes de las cuatro acciones
- Metadatos comerciales adicionales
- Página pública
- Imágenes de navegación
- Fuentes de datos detectadas
- Lógica del Dependiente

## Controles de índice

- **Reindexar catálogo completo** — **[Proceso]** reconstruye el índice.
- **Vaciar índice** — **[Elimina]** el índice de búsqueda; no equivale a borrar productos WooCommerce.

## Aprendizaje

La pantalla permite revisar:

- términos todavía no resueltos;
- vigilancia de cobertura;
- productos ofrecidos con mayor frecuencia;
- estado de semántica;
- consultas e interpretación;
- candidatos de aprendizaje;
- aprendizajes activos;
- rechazados.

Acciones:

- **Aprobar** — **[Guarda]** el aprendizaje aceptado.
- **Rechazar** — **[Guarda]** la decisión de no utilizar el candidato.

## Zona peligrosa

**Borrar conocimiento del Dependiente** reinicia el conocimiento aprendido.

La pantalla exige confirmación explícita mediante la casilla:

**Sí, borrar el conocimiento y empezar desde cero**

Esta acción no debe utilizarse como solución rutinaria a un problema de búsqueda.

---

# 12. Herramientas

Ruta: **SEO Taxonomy → Herramientas**

Herramientas presenta accesos a módulos avanzados.

## 12.1 Taxonomy

Pestañas:

- Gestión SEO
- Taxonomía
- Semántica

Gestiona clusters, hubs, relaciones y reasignación estructural.

La tabla de reasignación permite cambiar el Hub actual de una categoría.

**Modificar** — **[Modifica]** la posición de la categoría dentro de la arquitectura.

## 12.2 Templates

Pestañas:

- Asignación a páginas
- Archivos de plantilla
- Disponibilidad y activación
- PRO → STAGING

Controles destacados:

- **Activar plantilla específica para teléfono y ordenador** — **[Guarda]**
- **Guardar modo por dispositivo** — **[Guarda]**
- **Guardar asignaciones y modo de renderizado** — **[Guarda]**
- **Subir y registrar principal** — **[Guarda]**
- **Registrar archivo principal** — **[Guarda]**
- **Guardar disponibilidad y activación** — **[Guarda]**
- **Copiar PRO → STAGING** — **[Modifica]**

La sincronización PRO → STAGING exige confirmación y puede reemplazar el registro de plantillas del entorno destino.

## 12.3 Search

Configura el buscador del sitio.

Entre los campos detectados se encuentran:

- resultados por página;
- página de resultados;
- parámetro de consulta;
- mínimo de caracteres de autocompletado;
- límite de autocompletado;
- límite de búsqueda fuzzy;
- sinónimos;
- claves meta adicionales;
- layout predeterminado;
- columnas del grid;
- taxonomía de marca;
- texto para resultados vacíos.

Los cambios son **[Guarda]** configuración del buscador.

## 12.4 Redirects

Gestor de redirecciones.

Campos de alta:

- URL origen
- URL destino
- Tipo

Incluye búsqueda, diagnóstico, hits, último uso y acciones.

Funciones:

- importar;
- exportar;
- analizar;
- importar solo nuevas;
- revisar registros señalados;
- añadir nueva redirección.

Una redirección debe apuntar a un destino semánticamente válido. Evitar cadenas y destinos artificiales.

## 12.5 Marketing

Agrupa herramientas de campañas y redes sociales cuando están activas.

Las subpestañas pueden variar según los módulos cargados. Entre los servicios registrados actualmente se encuentran campañas y social/programador.

## 12.6 Data Table

Pestañas:

- Resumen
- Explorar tablas
- Exportar / Backup
- Operaciones

Permite consultar tablas del sistema, filtrar filas, exportar CSV/SQL, crear backups y revisar operaciones.

**Revertir** — utiliza el historial de operaciones cuando existe rollback disponible.

## 12.7 Clean DB

Herramienta de diagnóstico y limpieza.

Incluye controles de integridad para:

- relaciones SEO;
- nodos sin objeto WordPress activo;
- duplicados;
- productos con múltiples ámbitos;
- jerarquía de categorías;
- restablecimiento manual de objetos SEO.

Acciones de limpieza deben ejecutarse después de revisar los registros detectados.

También ofrece exportación de BBDD y backup de WordPress o de las tablas SEO System.

## 12.8 Import / Export

Gestiona importaciones, exportaciones, proveedores, recetas, colas y catálogos.

La interfaz depende del tipo de entidad y del proveedor seleccionado.

Regla de uso: **cargar → validar → revisar → ejecutar → comprobar resultado**.

No iniciar dos importaciones incompatibles sobre el mismo conjunto de datos.

## 12.9 Procesos

Pestañas base:

- Procesos
- Gestor de workers

Otros módulos pueden registrar pestañas adicionales.

El gestor supervisa estado, velocidad y carga de procesos automáticos. Los controles de arrancar, parar, pausar o reanudar actúan sobre el proceso correspondiente, no sobre el catálogo completo.

## 12.10 Logística

Subáreas visibles:

- Gestión de pedidos
- Transporte

La gestión de pedidos cruza:

- pedido;
- estado;
- Object ID;
- producto WooCommerce;
- cantidad;
- proveedor;
- ID/SKU del proveedor;
- producto proveedor;
- compra.

Su finalidad es facilitar la tramitación de compras a proveedor a partir de pedidos WooCommerce.

## 12.11 Conexiones con proveedores

Centraliza credenciales y conexiones reutilizadas por varios módulos.

Incluye actualmente servicios como Cloudflare, Google, GitHub Actions, Amazon y otras conexiones activadas por módulos.

Consultar [Conexiones y credenciales](CONEXIONES-CREDENCIALES.md).

## 12.12 FAQs

Pestañas:

- Hubs SEO
- Categorías
- Productos
- Informe

Funciones principales:

- crear FAQ;
- editar FAQ;
- activar/desactivar;
- ordenar;
- buscar y filtrar;
- revisar duplicadas;
- revisar huérfanas;
- limpiar copias;
- analizar cobertura, calidad e interacción.

Acciones destructivas:

- **Eliminar copias seleccionadas**
- **Eliminar todas las copias**
- **Eliminar copias del grupo**
- **Eliminar seleccionadas**
- **Eliminar todas las huérfanas**

La relación técnica por ID no implica por sí sola que una FAQ esté mal asociada. Las detecciones semánticas deben revisarse antes de reasignar o eliminar.

## 12.13 Estado del servidor

Pestañas:

- Resumen
- PHP
- MySQL
- WordPress
- Seguridad
- Servidor
- WooCommerce
- Rendimiento
- Logs

**Ejecutar chequeo completo** — **[Proceso]** guarda una referencia completa y permite calcular tendencia.

**Rotar secreto** — **[Modifica]** el secreto utilizado por el monitor externo y obliga a actualizar el secreto correspondiente en GitHub.

## 12.14 Plugin Validation

Ejecuta comprobaciones internas del plugin. Debe utilizarse como diagnóstico y no como sustituto de una revisión funcional cuando una prueba señala un problema.

## 12.15 Menu Manager

Gestiona elementos de menú administrados por SEO Taxonomy.

Antes de retirar una entrada, comprobar que no sea necesaria para la navegación pública o administrativa.

---

# 13. Ojeador

Ojeador analiza el mercado por categorías utilizando Google Shopping y señales internas.

## Pestañas

- Análisis y recomendaciones
- Posición de precio
- Comparador de productos
- Operación y consultas

## Análisis y recomendaciones

Resume qué está diciendo el mercado y separa bloqueos operativos de recomendaciones comerciales.

## Posición de precio

Clasifica los productos con comparación fiable mediante un semáforo comercial.

Filtros disponibles:

- producto o SKU;
- proveedor;
- categoría;
- estado de precio;
- impresiones mínimas;
- orden;
- filas.

Acciones:

- **Aplicar filtros** — **[Consulta]**
- **Limpiar productos** — **[Consulta]**
- **Exportar JSON precios** — **[Exporta]**

Interpretación recomendada:

- **Verde**: precio objetivo competitivo frente al mercado comparable.
- **Amarillo**: precio dentro o cerca del mercado; puede requerir modulación.
- **Rojo**: precio objetivo poco competitivo; revisar margen o negociar proveedor.
- Sin color comercial: falta una comparación suficientemente fiable.

## Comparador de productos

Filtros:

- categoría;
- producto / marca / modelo;
- comercio;
- precio mínimo;
- precio máximo;
- rating mínimo;
- reviews mínimas;
- orden;
- filas;
- solo con descuento.

La tabla muestra categoría, producto, comercio, precio, descuento, rating/reviews, posición y fecha de observación.

## Operación y consultas

Incluye:

- estado de cada shopping_query;
- log de consultas;
- clave SerpApi;
- intervalo de actualización;
- categorías por paso;
- tiempo de reutilización de consulta;
- límite mensual local;
- mantenimiento automático.

Acciones:

- **Detener** — **[Proceso]**
- **Continuar / actualizar mercado** — **[Proceso][API]**
- **Guardar** — **[Guarda]**
- **Exportar JSON análisis** — **[Exporta]**
- **Exportar JSON completo (auditoría)** — **[Exporta]**
- **Exportar log JSON** — **[Exporta]**

El JSON de análisis debe utilizarse para decisiones; el JSON completo está orientado a auditoría y diagnóstico.

---

# 14. Comentarista

Comentarista gestiona comentarios, vídeos, publicaciones sociales y enlaces externos asociados a productos.

El módulo administrativo no realiza scraping.

## Pestañas

- Registros
- Cobertura
- Importar / Exportar
- JSON

## Registro

Campos visibles:

- Producto
- Tipo
- Plataforma
- Fuente
- URL de origen
- ID externo
- Título externo
- Autor
- URL autor
- Fecha original
- Fecha captura
- Contenido capturado
- Resumen editorial
- Valoración
- URL embed
- Miniatura
- Estado
- Orden

Los campos de origen deben conservar trazabilidad suficiente para poder comprobar de dónde procede cada contenido externo.

---

# 15. Seguridad de credenciales

Nunca incluir credenciales reales en documentación, issues, commits, capturas públicas o ejemplos.

Los ejemplos del manual utilizan valores como:

`api_key_EXAMPLE_NOT_REAL_123456`

Estos valores indican el formato esperado, pero **no son credenciales válidas**.

Los campos tipo password/secret que ya tienen un valor guardado deben dejarse vacíos cuando la interfaz indique que hacerlo conserva la credencial existente.

Consultar [Conexiones y credenciales](CONEXIONES-CREDENCIALES.md).

---

# 16. Procedimiento general ante una duda

Cuando un usuario no sepa qué hace un botón:

1. identificar la pantalla y pestaña;
2. comprobar si la acción es Consulta, Guarda, Modifica, Elimina, Proceso, API o Exporta;
3. revisar qué entidad está seleccionada;
4. comprobar si existe confirmación o vista previa;
5. ejecutar primero en staging cuando la operación pueda alterar datos masivamente;
6. verificar el resultado antes de continuar con la siguiente operación.

---

# 17. Mantenimiento del manual

Cada cambio de interfaz debe revisar esta documentación.

Los cambios guardados en este manual en la rama `staging` se publican automáticamente en la GitHub Wiki mediante el workflow de sincronización.

Una nueva pestaña, botón, checkbox, selector, campo de credencial o acción masiva no se considera completamente terminada hasta que el manual explique:

- qué es;
- qué valor espera;
- qué modifica;
- qué ocurre al ejecutarla;
- riesgos y dependencias;
- ejemplo cuando el formato pueda resultar ambiguo.
