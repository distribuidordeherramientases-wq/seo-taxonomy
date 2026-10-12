# Analista

Capa de **análisis estratégico de demanda, rendimiento y mercado**.

Combina señales de Google, Bing, GA4, Search Console, tendencias, mercado/competencia, campañas, evolución del tráfico, literatura y otras fuentes ya integradas.

Su función es detectar:

- contenidos y URLs con tracción;
- contenidos con impresiones pero bajo rendimiento;
- temas o consultas emergentes;
- oportunidades de crecimiento;
- activos que conviene ampliar o replicar por intención;
- contenidos con poco seguimiento que pueden requerir mejora;
- señales de mercado relevantes para catálogo y contenido.

Analista no debe modificar por sí solo el catálogo ni crear contenido. Produce **conclusiones y oportunidades**.

La separación es:

- **Auditor** analiza la estructura y calidad interna.
- **Analista** interpreta comportamiento, demanda y mercado.
- **Solucionador** utiliza ambas capas para tomar decisiones editoriales.

## Analista 3.8.1 · validación antes de ejecutar

La cola de trabajo deja de tratar una señal estadística como una instrucción suficiente por sí sola. Antes de priorizar valida **consulta, entidad, destino, evidencia y preparación comercial**.

Reglas principales:

- una consulta de modelo/variante que contradice el producto atribuido produce **CORREGIR ASOCIACIÓN / INVESTIGAR**;
- una categoría amplia no absorbe consultas específicas de otro tipo/modelo solo por parentesco taxonómico;
- una URL no resuelta produce **INVESTIGAR COBERTURA** y nunca “mejorar esta URL”;
- 1–4 impresiones (por defecto, configurable) se tratan como muestra baja y no convierten una posición 5–20 en quick win fiable;
- los porcentajes de crecimiento muestran también base anterior y variación absoluta;
- title/meta se consideran efectivos cuando los gestiona postmeta, una plantilla de plugin SEO o el fallback público del sitio;
- antes de proponer una nueva URL se comprueba cobertura publicada de Dependiente, Ingeniero y Comparador relacionada con la categoría;
- para objetivos de ventas, stock, precio, proveedor y margen/comisión se separan de la señal SEO; si faltan, la preparación comercial queda explícitamente no verificada.

La prioridad y la confianza son dimensiones independientes. Los buckets operativos son **HACER AHORA, HACER DESPUÉS, INVESTIGAR, VIGILAR y ESPERAR DATOS**. HACER AHORA está limitado a diez trabajos y no se rellena artificialmente.

Cada tarea expone un contrato auditable con task_id, entidad, URL objetivo, consulta, match, evidencia, métricas, prioridad, confianza, objetivo, acción atómica, dependencias, responsable, baseline y revisión 28/60/90 días.

Analista sigue siendo **solo diagnóstico y derivación**. No crea ni publica contenido, no modifica categorías y no abre workers adicionales.


## Analista 3.8.2 · gates y tareas desbloqueables

La segunda corrección del Plan de acción reduce el exceso de `INVESTIGAR` sin rebajar la seguridad de 3.8.1.

- Analista intenta resolver automáticamente una consulta contra categorías y entidades locales existentes antes de entregar la tarea.
- El resolver automático se vuelve a validar; no puede sustituir un modelo específico por una categoría genérica de baja afinidad.
- Los bloqueos se separan en **entity**, **evidence** y **commercial**.
- `INVESTIGAR` se reserva para entidad/destino/modelo no resueltos.
- Las oportunidades SEO válidas con bloqueo comercial quedan en **HACER DESPUÉS** y exponen exactamente qué falta; para categorías se usa una muestra acotada de productos para comprobar stock, precio, proveedor y margen. GA4 se mantiene como señal separada y no bloquea por sí sola si la oferta ya está validada.
- Cada tarea define `unlock_condition` y `bucket_if_unblocked`.
- La prioridad no se modifica por resolver o no resolver una dependencia; el gate determina si la tarea es ejecutable.
- El diagnóstico `gate_summary` permite detectar oportunidades de score alto que el filtro conservador está reteniendo.


## Analista 3.8.3 · contrato único y HACER AHORA ejecutable

La tercera corrección del Plan de acción elimina contradicciones entre señal, bucket y texto de acción.

- `task_decision` es la fuente única de verdad para bucket, intervención, bloqueo y acción primaria.
- **HACER AHORA** exige acción ejecutable con verbo, objeto, destino, responsable y URL validada.
- Una acción de observar/esperar/investigar nunca puede quedar en HACER AHORA.
- El estado de la señal se muestra separado de la prioridad de trabajo, de modo que una señal estable o en aceleración no se confunde con el bucket.
- INVESTIGAR incluye fuente de verificación y criterio de salida.
- El límite máximo de diez actualiza el contrato, no solo el campo visible.
- `task_id`/traza se estabiliza por consulta y tema; al resolver un bloqueo no se duplica la tarea.
- Una tarea ya ejecutada pasa a SIN ACCIÓN y deja de proponer de nuevo la intervención.
- El JSON incorpora el contrato completo y un informe de consistencia del plan.

## Analista 3.9.0 · propietario de intención y anti-canibalización

Analista mantiene su función principal de inteligencia de demanda y mercado, pero antes de proponer una intervención editorial debe resolver si la intención ya tiene propietario local.

Orden de decisión:

1. detectar la señal externa o de rendimiento;
2. buscar cobertura en categoría, cluster, hub primario, hub secundario, landing, post y página editorial;
3. si existe una entidad suficientemente próxima, proponer **mejorar/impulsar esa entidad**;
4. si no existe cobertura suficiente, clasificar el hueco:
   - **CREAR_POST** para una intención informativa concreta;
   - **CREAR_LANDING** cuando la demanda sea transversal y cruce varias familias;
   - **INVESTIGAR_COBERTURA** cuando la demanda sea comercial pero todavía no esté claro si falta categoría, surtido o landing;
5. antes de publicar cualquier URL nueva, volver a revisar la cobertura para evitar canibalización.

Prioridad de propiedad para demanda comercial: categoría → hub secundario → hub primario → cluster → landing → post.

Para demanda informativa, un post o landing puede ser el destino correcto, pero una coincidencia fuerte con una entidad estructural existente bloquea la creación de una URL paralela.

Las páginas corporativas y funcionales no participan como destino editorial de estas decisiones.

Auditor y Analista quedan así separados:

- **Auditor**: análisis interno de calidad, arquitectura, Vocabulary, división/fusión, redundancia y canibalización de lo existente.
- **Analista**: señales externas y de rendimiento, oportunidades y decisión entre mejorar lo existente o proponer una entidad nueva sin repartir autoridad.

Analista sigue siendo diagnóstico/derivación: no publica automáticamente posts, landings o categorías.

## Informe competitivo profundo

Analista puede generar, bajo demanda, un **informe competitivo profundo** a partir de los dominios configurados en la vista Comparación.

La comparación combina tres capas que no deben confundirse:

1. **Datos privados propios**: Search Console, GA4 y Bing sólo para el dominio conectado.
2. **Evidencia pública competitiva**: SERP, HTML público, robots, sitemap, estructura observable y señales comerciales.
3. **Contexto de mercado**: Google Trends y las señales ya disponibles en Ojeador/Analista, sin convertirlas en una estimación de tráfico de competidores.

El adaptador de rankings usa la conexión compartida **SerpApi → ScraperAPI**. El informe no se ejecuta al abrir la pantalla: requiere una acción explícita del usuario.

La salida ofrece puntuaciones comparables en SERP, arquitectura, producto, enlazado, confianza, UX y SEO técnico observable. Cada puntuación lleva una confianza de evidencia y los datos ausentes se mantienen como **N/D**, no como cero.

La finalidad es producir una matriz competitiva, detectar fortalezas y brechas y traducirlas a un plan priorizado sin inventar autoridad, tráfico, Core Web Vitals o datos privados de terceros.

