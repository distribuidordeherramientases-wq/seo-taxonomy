# Auditor

Ruta de contenido: **SEO Taxonomy → Contenidos → Auditor**

Auditor es de solo lectura respecto al contenido: detecta incidencias, calidad y prioridades, pero no redacta ni corrige automáticamente productos, categorías, posts, páginas o FAQs.

## Auditorías por bloque

La pantalla ofrece cinco tarjetas independientes:

- **Productos · contenido e identidad**
- **Categorías y arquitectura**
- **Posts**
- **Páginas y landings**
- **FAQs**

Cada tarjeta carga sólo las fuentes necesarias para ese ámbito.

**Auditar** [Proceso]: ejecuta por primera vez la auditoría del bloque.

**Repetir** [Proceso]: vuelve a ejecutar un bloque que ya tiene informe guardado.

**Ver último** [Consulta]: abre el último informe persistido de ese bloque.

**JSON** [Exporta]: descarga el último informe completo del bloque.

La fecha y duración mostradas en la tarjeta corresponden a la última ejecución.

## Productos · contenido e identidad

Revisa títulos, excerpts, descripciones, datos SEO, categorías, Vocabulary, SKU e identidad de producto. No ejecuta auditorías de posts, páginas, FAQs o motor de Dependiente.

## Categorías y arquitectura

Revisa contenido de categorías, Vocabulary, relaciones y la cadena Cluster → Hub primario → Hub secundario → categoría.

## Posts

Revisa entradas publicadas, slugs, duplicación y Vocabulary.

## Páginas y landings

Revisa páginas publicadas, incluidas landings y su estructura editorial.

## FAQs

Revisa owners, duplicados, respuestas, coherencia y orfandad. Puede incluir el inventario de migración FAQ para apoyar FAQ v2, pero Auditor no elimina ni migra contenido por sí mismo.

## Informe de un bloque

### Métricas

- **Hallazgos**: número total de incidencias detectadas.
- **Críticos / Alta / Media**: distribución por severidad.
- **Entidades a revisar**: productos, términos, posts, páginas o FAQs afectados.

### Plan de trabajo priorizado

Cada tarea puede incluir:

- `task_id`
- prioridad
- `priority_score`
- clase de acción
- estado
- `ready_now`
- necesidad de verificación de fuente
- dependencias
- entidad
- problema
- evidencia
- recomendación
- impacto esperado

Prioridades operativas:

- **P1 · ATENDER PRIMERO**
- **P2 · ESPERAR_ENRIQUECIMIENTO**
- **P3 · MIGRAR_A_SOLUCIONADOR**
- **P4 · REVISAR**
- **P5 · INFORMATIVO**

Una prioridad alta no autoriza por sí sola un cambio automático.

### Hallazgos

La tabla de hallazgos es una vista de diagnóstico. Cuando el volumen es grande puede ser una muestra; el informe conserva los totales declarados por sus metadatos.

**Volver al Auditor de contenidos** [Consulta]: regresa a la vista de tarjetas sin modificar el informe.

## Auditor de Academia

Ruta: **SEO Taxonomy → Dependiente → Auditor Academia**

Este auditor está separado del Auditor de contenidos.

**Auditar Academia** [Proceso]: analiza lecciones, Entrenador, runs, reglas promocionadas, `academy_stage`, snapshots y cadena del Estudiante. No recorre productos, categorías, posts ni páginas.

**Repetir auditoría de Academia** [Proceso]: vuelve a ejecutar el mismo diagnóstico.

**Descargar JSON Academia** [Exporta]: descarga el último informe completo de Academia.

### Subvistas

- **Resumen**: indicadores principales.
- **Academia**: detalle del aprendizaje.
- **Cadena**: estado de la cadena del Estudiante y sus dependencias.

## Qué no hace Auditor

- no publica;
- no modifica contenido editorial;
- no reescribe categorías;
- no entrena Dependiente;
- no ejecuta el motor/índice profundo de Dependiente;
- no sustituye Plugin Validation.

Los chequeos técnicos del motor, índice y compatibilidad del plugin pertenecen a **Herramientas → Plugin Validation**.
