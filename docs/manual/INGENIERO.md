# Ingeniero

Ruta: **SEO Taxonomy → Contenidos → Ingeniero**

Ingeniero investiga conocimiento técnico por `product_cat`, conserva las fuentes y prepara dossiers editoriales especializados. No publica automáticamente.

## Pestañas

- **Investigación**: prepara y ejecuta la investigación técnica por categoría.
- **Editorial**: convierte conocimiento activo en dossiers y permite su aprobación humana.
- **Datos y fuentes**: importa y exporta conocimiento técnico con trazabilidad.
- **Pruebas**: ejecuta comprobaciones funcionales deterministas sin consultar servicios externos.

## Investigación

### KPIs

Los contadores superiores resumen categorías, fuentes, knowledge y estado de trabajo. Son informativos; no ejecutan acciones.

### Lección 1 · Documentación técnica

**Categorías por lote**: número de categorías pendientes que se preparan en una operación. Acepta de 1 a 100. No inicia por sí solo la investigación.

**Preparar lección** [Proceso]: selecciona las categorías pendientes y crea la cola de trabajo.

**Iniciar / continuar investigación** [Proceso][API]: inicia o reanuda el worker de Ingeniero. Procesa una categoría por ciclo y utiliza las conexiones compartidas de búsqueda.

**Detener** [Proceso]: detiene la investigación pendiente. No elimina knowledge, fuentes ni dossiers ya creados.

**Exportar JSON** [Exporta]: descarga el conocimiento persistido de Ingeniero para revisión o respaldo.

### Estado y progreso

- **Estado**: estado operativo actual del worker.
- **Progreso**: categorías procesadas frente al total preparado.
- **Último mensaje**: diagnóstico de la última actividad.
- **Último error**: error más reciente cuando existe.

### Fuente de búsqueda

Ingeniero utiliza las conexiones compartidas configuradas en **Herramientas → Conexiones con proveedores**. El flujo puede usar SerpApi y ScraperAPI según disponibilidad.

**Resultados por consulta** [Guarda]: número de resultados de búsqueda que se solicitan en cada consulta. Rango visible: 3–10.

**Consultas por categoría** [Guarda]: número máximo de consultas técnicas realizadas por categoría. Rango visible: 1–4.

**Páginas HTML a leer** [Guarda][API]: número máximo de páginas HTML que Ingeniero intentará descargar para extraer evidencia. Rango visible: 0–8. Los PDF se detectan, pero quedan como `pdf_pending` en esta versión.

**Guardar configuración** [Guarda]: persiste los tres parámetros anteriores. No inicia una investigación.

### Tabla de categorías

La tabla de categorías muestra el estado de investigación de cada `product_cat`, sus fuentes, knowledge activo/revisión y la última actividad.

**Aprobar todas las pendientes** [Guarda]: aprueba en bloque el knowledge que se encuentra en estado de revisión. No modifica elementos rechazados.

**Aprobar categoría** [Guarda]: aprueba todo el knowledge en revisión de una categoría concreta.

**Reinvestigar categoría** [Proceso][API]: vuelve a colocar la categoría en investigación. Puede consumir cuota de los proveedores externos.

**Aprobar** [Guarda]: cambia un knowledge concreto a estado `active`.

**Mantener revisión** [Guarda]: conserva el knowledge en `review` para una decisión posterior.

**Rechazar** [Guarda]: marca el knowledge como rechazado para que no forme parte del dossier editorial activo.

### Lección 2 · Experiencia práctica

En la versión actual aparece como **desactivada**. Es informativa y no ofrece una acción de ejecución.

## Editorial

Editorial genera un único dossier `technical-overview` por categoría con todo el knowledge `active` disponible.

### Contadores

- **Knowledge activo**: piezas técnicas disponibles para dossiers.
- **Categorías con knowledge**: categorías con al menos un knowledge activo.
- **Categorías <4**: categorías con knowledge pero por debajo del objetivo operativo de cuatro piezas.
- **Dossiers**: dossiers editoriales existentes.
- **Crear post / Mejorar / Fusionar / Sin acción / Revisión**: distribución por recomendación de cobertura.
- **Borradores**: posts WordPress creados por Ingeniero y todavía no publicados.
- **Publicados**: posts de Ingeniero publicados.
- **Necesitan actualizar**: dossiers cuyo conocimiento cambió después de crear el post.

### Acciones globales

**Aceptar todo** [Guarda][Proceso]: aprueba y convierte en borrador todas las propuestas editoriales activas de Ingeniero que todavía no tengan post. La recomendación de cobertura se conserva como diagnóstico. No publica automáticamente.

**Descargar JSON** [Exporta]: exporta la información persistida de Ingeniero.

### Filtros

**Estado** [Consulta]: filtra por `candidate`, `review`, `approved`, `draft`, `published`, `needs_update` o `closed`.

**Acción** [Consulta]: filtra por `CREATE_POST`, `IMPROVE_POST`, `MERGE_CONTENT`, `NO_ACTION` o `NEEDS_REVIEW`.

**Filtrar** [Consulta]: aplica los filtros sin modificar datos.

### Tabla Editorial

Columnas:

- **Categoría / tema**: categoría, título sugerido y `topic_key`.
- **Acción**: recomendación editorial.
- **Cobertura**: resultado de la comprobación de cobertura existente.
- **Estado**: estado del dossier.
- **Base**: número de Q&A técnicas y fuentes.
- **Post**: ID/estado del post asociado cuando existe.
- **Actualizado**: fecha de última actualización.
- **Acciones**: operaciones disponibles para esa propuesta.

**Ver brief** [Consulta]: abre el dossier completo con Q&A, fuentes, cobertura y evidencia.

**Reanalizar** [Proceso]: recalcula la propuesta y su cobertura con el conocimiento actual.

**Aprobar** [Guarda]: acepta la propuesta sin publicar nada.

**Aprobar y crear borrador** [Guarda]: cuando la propuesta está preparada para post y todavía no existe uno, aprueba y crea el borrador WordPress.

**Crear borrador** [Guarda]: aparece para una propuesta ya aprobada que todavía no tiene post. Crea `post_status=draft`.

**Revisar novedades (N)** [Consulta]: abre el post de Ingeniero cuando existen cambios técnicos pendientes respecto al snapshot con el que se creó.

### Brief técnico

El brief muestra:

- título y categoría;
- preguntas y respuestas técnicas;
- tipo de knowledge;
- confianza;
- número de fuentes;
- lista **Must cover**;
- diagnóstico del knowledge;
- fuentes trazables;
- enlaces internos sugeridos;
- cobertura existente;
- aviso de verificación editorial;
- métricas de Analista cuando están disponibles.

Las fuentes enlazadas sirven para verificar las afirmaciones antes de publicar.

## Datos y fuentes

Esta pestaña gestiona el intercambio de conocimiento de Ingeniero.

**Exportar conocimiento JSON** [Exporta]: genera el paquete canónico completo.

**Exportar conocimiento CSV** [Exporta]: genera formato tabular para revisión externa.

**Descargar plantilla CSV** [Exporta]: descarga sólo la cabecera/formato esperado para preparar una importación.

**Archivo**: selector del JSON o CSV que se va a importar. Límite actual: 10 MB.

**Modo de importación**:
- **Añadir solamente**: incorpora conocimiento que no exista sin sustituir revisiones existentes.
- **Actualizar en revisión**: permite actualizar elementos que permanecen en estado de revisión.

**Exigir categoría WooCommerce existente**: si está marcado, cada fila/objeto debe resolverse a una `product_cat` existente. Si no puede resolverse, se registra como error en vez de omitirla.

**Importar conocimiento** [Guarda]: procesa el archivo. El conocimiento importado entra en `review`; no crea posts ni publica contenido.

Campos CSV principales:

- `term_id`
- `category_slug`
- `category_name`
- `lesson`
- `knowledge_type`
- `concept`
- `summary`
- `confidence`
- `tags`
- `source_url`
- `source_title`
- `source_type`
- `trust_level`
- `evidence`

`knowledge_type`, `summary` y `source_url` son obligatorios para una fila importable.

## Pruebas

La pestaña ejecuta pruebas internas de agrupación, hashes, cobertura, aprobación humana, unicidad y rol público.

La tabla muestra:

- **Prueba**: código de la comprobación.
- **Estado**: OK/FALLO.
- **Detalle**: explicación del resultado.

Las pruebas no crean posts ni llaman a servicios externos.

## Posts creados por Ingeniero

Un borrador de Ingeniero se identifica mediante sus metadatos de origen:

- `_seo_ingeniero_editorial_id`
- `_seo_ingeniero_topic_key`

El rol público se guarda aparte en:

`_seo_solucionador_content_role = ingeniero_qa_specialized`

Ese rol público no sustituye al Vocabulary ni a la relación `post_to_category`.

Los borradores pueden revisarse también desde **Contenidos → Entradas → Ingeniero**.
