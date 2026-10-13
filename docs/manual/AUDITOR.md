# Auditor

Ruta de contenido: **TaxoCommerce SEO → Contenidos → Auditor**

El diagnóstico de Auditor es de solo lectura respecto al contenido: detecta incidencias, calidad y prioridades y no redacta ni corrige automáticamente productos, categorías, posts, páginas o FAQs. La excepción son las acciones explícitas y confirmadas del módulo de reequilibrio de categorías, que solo se ejecutan después de guardar/aprobar una propuesta.

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

## Reequilibrio de categorías

Después de una auditoría de **Categorías y arquitectura**, Auditor puede mostrar un bloque de diagnóstico y propuestas para categorías demasiado grandes, mezcladas o demasiado pequeñas.

### Métricas visibles

- **Revisar por división**: categorías con más de 10 productos que merecen revisión por tamaño.
- **Propuestas de división**: casos en los que además existe evidencia semántica suficiente para formar cohortes TIPO/SUBTIPO.
- **Revisar concentración**: categorías con 1–4 productos.
- **Propuestas de concentración**: casos en los que se ha encontrado una categoría hermana suficientemente parecida.
- **Tamaño objetivo**: referencia operativa 5–10 productos.
- **Aprobadas**: propuestas guardadas como aprobadas y todavía pendientes de aplicar.

Una revisión por tamaño no modifica nada. Solo una propuesta aprobada puede llegar a ejecutarse.

### División de categorías

Cada propuesta puede mostrar cohortes semánticas, número de productos, productos ambiguos y productos sin asignación segura.

Controles visibles:

- **Abrir categoría** [Consulta]: abre la categoría origen en WordPress.
- **Destino** [Selector]: permite elegir una categoría existente para cada cohorte.
- **Crear categoría nueva / nombre** [Campo]: si no se elige un destino existente, permite indicar el nombre de la nueva categoría que se creará al aplicar.
- **Ver productos** [Consulta]: despliega los productos incluidos en la cohorte.
- **Ver ambiguos** [Consulta]: despliega productos que encajan en más de una cohorte; se mantienen en origen.
- **Aprobar división** [Guarda]: guarda la decisión y los destinos. No mueve productos todavía.
- **Descartar propuesta** [Guarda]: marca la propuesta como rechazada.
- **Aplicar división aprobada** [Modifica catálogo]: tras confirmación, vuelve a validar la propuesta y mueve productos; puede crear categorías nuevas. Los productos ambiguos/no asignados permanecen en origen.
- **Aplicar todas las divisiones aprobadas** [Modifica catálogo]: procesa las divisiones aprobadas tras revalidarlas.

### Concentración de categorías pequeñas

Controles visibles:

- **Abrir categoría** [Consulta]: abre la categoría origen.
- **Categoría destino** [Selector]: permite confirmar o cambiar la categoría hermana sugerida.
- **Aprobar concentración** [Guarda]: guarda la decisión; todavía no modifica el catálogo.
- **Descartar propuesta** [Guarda]: rechaza la propuesta.
- **Aplicar concentración aprobada** [Modifica catálogo][301]: tras confirmación, mueve los productos al destino, conserva las demás categorías de cada producto, valida que el origen no tenga hijas ni landings, elimina la categoría origen y crea el redirect 301.
- **Aplicar todas las concentraciones aprobadas** [Modifica catálogo][301]: procesa las concentraciones aprobadas tras revalidarlas.

Auditor no fuerza una división o concentración solo por tamaño. Si no hay evidencia semántica suficiente, muestra únicamente el diagnóstico y **Abrir categoría** para revisión manual.

## Posts

Revisa entradas publicadas, slugs, duplicación y Vocabulary, pero antes construye un mapa editorial ligero con páginas estructurales, landings y categorías. De este modo no recomienda enriquecer un post que en realidad compite con un cluster, hub, categoría o landing ya existente.

Auditor no propone crear posts nuevos. Cuando detecta solapamiento, identifica el posible propietario de la intención y recomienda revisar, diferenciar o consolidar.

## Páginas y landings

La auditoría editorial excluye páginas funcionales y corporativas. Estas páginas pueden tener comprobaciones técnicas en otras capas, pero no generan tareas editoriales por longitud, Vocabulary o deriva semántica.

Las páginas de contenido se clasifican por función:

- **Canónicas estructurales**: cluster, hub primario y hub secundario. Deben concentrar la intención jerárquica amplia que les corresponde.
- **Landings**: deben resolver necesidades amplias o transversales que agrupen varias familias/categorías sin duplicar la taxonomía estructural.
- **Páginas editoriales no clasificadas**: se señalan para definir su función antes de crear o enriquecer contenido.

El especialista editorial compara posts, landings, clusters/hubs y categorías para detectar:

- canibalización exacta por identidad/título/slug;
- solapamientos semánticos apoyados por categorías y Vocabulary;
- landings demasiado próximas a una sola categoría;
- landings que duplican un cluster/hub;
- landings con cobertura muy solapada, candidatas a fusión;
- landings con varios grupos estructurales independientes, candidatas a división.

Las propuestas de unir o dividir landings son señales para revisión humana. Auditor no crea, divide, fusiona, publica ni redirige URLs.

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

### Contexto de decisión

Cada auditoría incorpora un bloque `contexto_de_decision` construido exclusivamente con lecturas ya persistidas. Auditor **no llama directamente** a GA4, Bing, SerpApi, ScraperAPI ni Google Shopping.

Fuentes de contexto:

- **Analista**: rendimiento y prioridad por entidad calculados desde sus datos locales disponibles.
- **Ojeador**: último snapshot persistido de mercado por categoría.
- **Dependiente**: búsquedas, apariciones, clics, términos sin resolver y resultados cero del log local.
- **Comparador**: perfiles comparativos persistidos y su estado.
- **Proveedores**: frescura e incidencias resumidas de los datos ya importados.

Estados:

- `fresh`: snapshot disponible y vigente;
- `partial`: sólo existe cobertura parcial o una parte requiere revisión;
- `stale`: el snapshot está vencido o marcado para actualización;
- `unavailable`: no existe una observación utilizable.

**Importante:** `0` significa cero observado. La ausencia de dato se representa como `null` o `unavailable`; Auditor no convierte datos desconocidos en cero.

Cuando Analista dispone de una prioridad para la misma entidad, Auditor puede usarla únicamente para **ordenar mejor tareas dentro de la misma prioridad P1–P5**. No cambia la clase del hallazgo y no convierte una señal de demanda en un problema interno nuevo.

En cada tarea, **Contexto de decisión** despliega las señales asociadas a la entidad y sus categorías relacionadas.

### Hallazgos

La tabla de hallazgos es una vista de diagnóstico. Cuando el volumen es grande puede ser una muestra; el informe conserva los totales declarados por sus metadatos.

**Volver al Auditor de contenidos** [Consulta]: regresa a la vista de tarjetas sin modificar el informe.

## Auditor de Academia

Ruta: **TaxoCommerce SEO → Dependiente → Auditor Academia**

Este auditor está separado del Auditor de contenidos.

**Auditar Academia** [Proceso]: analiza lecciones, Entrenador, runs, reglas promocionadas, `academy_stage`, snapshots y cadena del Estudiante. No recorre productos, categorías, posts ni páginas.

**Repetir auditoría de Academia** [Proceso]: vuelve a ejecutar el mismo diagnóstico.

**Descargar JSON Academia** [Exporta]: descarga el último informe completo de Academia.

### Subvistas

- **Resumen**: indicadores principales.
- **Academia**: detalle del aprendizaje.
- **Cadena**: estado de la cadena del Estudiante y sus dependencias.

## Separación Auditor / Analista

Auditor revisa y protege el universo existente: detecta defectos, redundancia, canibalización, necesidad de consolidar, reclasificar o mejorar una entidad ya publicada. **No propone nuevas URLs.**

Analista es quien detecta demanda, huecos u oportunidades nuevas. Antes de proponer una nueva entidad debe consultar la cobertura existente para decidir si la oportunidad corresponde a mejorar una categoría, cluster/hub, landing o post ya existente.

## Qué no hace Auditor

- no propone crear posts, landings, páginas o categorías nuevas;
- no publica;
- no modifica contenido editorial;
- no reescribe categorías;
- no entrena Dependiente;
- no ejecuta el motor/índice profundo de Dependiente;
- no sustituye Plugin Validation.

Los chequeos técnicos del motor, índice y compatibilidad del plugin pertenecen a **Herramientas → Plugin Validation**.
