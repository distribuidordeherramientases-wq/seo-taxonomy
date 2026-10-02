# Solucionador

## Estado operativo

- **Versión funcional:** 0.5.0.
- **Versión de esquema:** 0.5.0.
- **Arquitectura de referencia:** 02/10/2026.
- **Issue de implementación:** #560.
- **Entorno de validación:** staging antes de producción.

## Responsabilidad

Solucionador tiene un único cometido editorial:

~~~text
Academia / Entrenador
        ↓
preguntas aprendidas pass_*
        ↓
dossier básico por product_cat
        ↓
cobertura editorial
        ↓
CREATE_POST / IMPROVE_POST / MERGE_CONTENT / NO_ACTION / DEFER
        ↓
brief básico
        ↓
Editora
        ↓
post WordPress
~~~

Solucionador **no** es el concentrador de todos los servicios.

Los procesos editoriales quedan separados:

- **Dependiente / Academia → Solucionador**: contenido básico de preguntas habituales.
- **Ingeniero → proceso editorial propio**: contenido técnico especializado.
- **Ojeador → Comparador**: contenido comparativo.
- **Analista**: medición global.

Solucionador no investiga, no consulta Internet, no compara mercado, no redacta contenido técnico, no ejecuta Google Shopping y no abre conexiones propias a GSC/GA4/Bing.

## Entrada editorial única

La materia prima editorial procede de Academia/Entrenador.

Tablas fuente:

- `seo_dependiente_trainer_questions`
- `seo_dependiente_trainer_runs`

Una pregunta entra en Solucionador únicamente cuando:

1. está activa;
2. pertenece al currículo;
3. su último run está `answered`;
4. `evaluation_status` empieza por `pass_`.

Se conserva como contexto:

- question;
- question_type;
- lesson_key;
- source_type/source_id/source_key;
- expected_json;
- evaluation_status;
- evaluation_score;
- evaluation_json;
- top_results;
- response_meta;
- fecha del último run.

Los resultados internos de Academia son evidencia para Editora. **No se publican literalmente.**

## Asociación con product_cat

Solucionador sólo crea dossier cuando puede demostrar la categoría.

Resolución admitida:

- `kind=category` → category_id;
- `kind=product` → producto → product_cat;
- `kind=features` → source_product_id → product_cat;
- `kind=faq`, owner_type=2 → product_cat;
- `kind=faq`, owner_type=3 → producto → product_cat;
- source_type category/product cuando la relación es inequívoca.

No se infiere una categoría por parecido textual.

Una pregunta aprendida sin product_cat demostrable:

- no crea dossier;
- no crea URL;
- permanece contabilizada en el KPI **Aprendidas sin categoría**.

## Dossier por categoría

Tabla:

`{$wpdb->prefix}seo_solucionador_dossiers`

Existe como máximo un dossier por `category_id`.

El dossier persiste sólo información ligera:

- category_id;
- category_name;
- question_count;
- question_ids;
- score_avg;
- last_validated_at;
- source_hash;
- scan_token;
- fechas.

No guarda una copia gigante de `top_results`, `evaluation_json` o `response_meta`.

Esos detalles se recuperan **bajo demanda** mediante `SEO_Solucionador_Dossiers::question_details()` al abrir el brief.

## Procesamiento por lotes

El escaneo de Academia es reanudable.

Estado persistente:

`seo_solucionador_academia_scan_state`

Contiene, entre otros:

- token del ciclo;
- cursor;
- preguntas procesadas;
- aprendidas;
- aprendidas con/sin categoría;
- errores aislados;
- estado complete;
- fechas.

Reglas:

- lotes pequeños;
- cursor persistente;
- no cargar todas las preguntas en una petición;
- una categoría/pregunta con error no invalida el resto;
- si la ejecución queda a medias, la siguiente continúa;
- si un ciclo termina, el siguiente análisis manual inicia un token nuevo;
- al finalizar se retiran dossiers antiguos que no aparecieron en el nuevo ciclo.

La fase editorial tiene su propio cursor:

`seo_solucionador_editorial_scan_state`

De esta forma la construcción de dossiers y el análisis editorial pueden reanudarse independientemente.

## Search log

`seo_dependiente_search_log` representa **demanda real de visitantes**.

En Solucionador 0.5:

- no origina temas;
- no crea dossiers;
- no carga payloads pesados en el flujo principal.

Se conserva como fuente separada de información y puede utilizarse en el futuro como refuerzo ligero de prioridad sobre un dossier de Academia ya existente.

## Cobertura editorial compartida

La implementación de cobertura se ha extraído a la API neutral:

`SEO_Editorial_Coverage`

Archivo:

`includes/editorial/seo-editorial-coverage.php`

El antiguo nombre:

`SEO_Solucionador_Coverage`

permanece como wrapper de compatibilidad.

La cobertura busca contenido existente para evitar crear URLs duplicadas. Puede detectar:

- uncovered;
- weak_coverage;
- partial_coverage;
- covered;
- duplicate;
- conflict.

Ingeniero y Comparador pueden reutilizar la misma API sin depender conceptualmente de Solucionador.

## Decisiones permitidas

Solucionador sólo propone:

### CREATE_POST

Cuando:

- existe masa crítica de preguntas aprendidas;
- hay product_cat demostrable;
- no existe cobertura equivalente;
- el riesgo de duplicación/canibalización es aceptable.

El mínimo por defecto es 3 preguntas pass_* y puede ajustarse mediante:

`seo_solucionador_min_academy_questions`

### IMPROVE_POST

Cuando existe un post equivalente con cobertura débil o parcial.

### MERGE_CONTENT

Cuando la cobertura detecta piezas solapadas/duplicadas.

### NO_ACTION

Cuando la intención básica ya está suficientemente cubierta o existe un borrador/post del mismo topic.

### DEFER

Cuando:

- falta categoría;
- falta masa crítica;
- existe conflicto;
- la cobertura parcial no corresponde a un post editable equivalente;
- no se cumplen las condiciones necesarias.

Solucionador ya no crea landings ni propone acciones dependientes de conocimiento técnico de Ingeniero o de perfiles de Comparador.

## Prioridad

La prioridad sirve para ordenar dossiers, no sustituye los gates.

Componentes actuales:

- preguntas aprendidas;
- encaje con product_cat/catálogo;
- hueco de cobertura;
- confianza de Academia;
- penalización por duplicación.

No utiliza amplitud de mercado, prioridades de Marketing ni conocimiento técnico de Ingeniero.

## Interfaz visible simplificada

La navegación diaria de Solucionador queda reducida a tres vistas:

1. **Resumen**
   - Posts propuestos.
   - Borradores.
   - Publicados.
   - La sincronización con Academia se ejecuta automáticamente en segundo plano; no se muestra un botón manual de procesamiento.

2. **Diagnóstico editorial**
   - Una fila por categoría con conocimiento aprendido.
   - Columnas: título propuesto, número de preguntas, estado y acción.
   - Si está pendiente, la única acción visible es **Convertir en post**.
   - Si ya se convirtió, se muestra como **Borrador** o **Publicado** y no vuelve a ofrecer el botón de conversión.
   - Las preguntas/respuestas no se muestran en esta pantalla. Se recuperan sólo al convertir la propuesta y se copian al `post_content` del borrador.

3. **Visitas Google**
   - Sólo posts publicados creados por Solucionador.
   - Impresiones y clics de Google Search Console.
   - Vistas de Google Analytics.
   - Periodo visible: 28 días.
   - La propia pantalla solicita el snapshot de reporting cacheado/actualizado del sitio.
   - El informe JSON se descarga desde esta misma vista; no tiene una pestaña separada.

Cobertura, Vocabulary, workflow, evidencias, tests y diagnóstico técnico siguen disponibles para el motor como infraestructura interna, pero no forman parte de la interfaz operativa.
## Brief para Editora

Al abrir un dossier, Solucionador recupera bajo demanda las preguntas y resultados internos.

El contenido visible para Editora se reduce a:

- título propuesto;
- preguntas aprendidas;
- respuesta legible de Dependiente para cada pregunta.

La evidencia técnica completa sigue guardada internamente y no se muestra en el flujo diario.

Regla editorial:

> Editora redacta. Solucionador no publica literalmente las respuestas internas de Academia.

## Contrato del post

`SEO_Solucionador_Posts::create_draft()`:

1. exige que la propuesta sea `CREATE_POST`;
2. exige aprobación humana / brief_ready;
3. vuelve a comprobar los gates;
4. recupera desde el dossier las preguntas `pass_*` y el último run válido de cada una;
5. crea un `post` en estado `draft` y categoría editorial WordPress **Guías**;
6. usa el título propuesto como `post_title` y deja `post_excerpt` vacío;
7. escribe en `post_content` las mismas **preguntas y respuestas** que se muestran en la propuesta, encabezadas por el aviso de borrador editorial;
8. conserva `topic_id` y `canonical_key`;
9. persiste en metadatos la trazabilidad `dossier/category_id -> question_ids -> run_ids -> source_hash`, independiente del texto editable del borrador;
10. asigna el rol estable `dependiente_qa_basic`;
11. crea una única relación comercial `post_to_category` con la `product_cat` principal que originó el dossier;
12. asigna Vocabulary combinando la propuesta editorial con los grupos canónicos activos ya disponibles en esa `product_cat`.

El `post_content` es una **copia editorial legible**, no la fuente de verdad. El inventario interno y la trazabilidad persistida siguen mandando aunque la Editora reescriba por completo el borrador.

El rol se asigna mediante la API común:

`seo_post_editor_set_public_content_role()`

Solucionador no publica automáticamente.

El acoplamiento especial anterior con Comparador se ha eliminado.

## Plantillas

Las plantillas deben recuperar sólo posts:

- `post_status=publish`;
- relacionados con la product_cat correspondiente;
- rol `dependiente_qa_basic`.

Si no existe un post publicado compatible, no se muestra título, contenedor ni sección vacía.

Las plantillas no consultan Academia directamente.

## Fuentes y servicios en la interfaz

La pestaña **Fuentes y servicios** muestra:

### Dependiente / Academia

Origen editorial único.

KPIs:

- procesadas;
- aprendidas;
- no aprendidas;
- aprendidas con categoría;
- aprendidas sin categoría;
- categorías con conocimiento;
- categorías sin conocimiento;
- media de preguntas por categoría;
- cursor;
- errores aislados;
- estado del escaneo.

### Dependiente / demanda real

Search log informativo, separado del flujo editorial.

### Cobertura editorial compartida

Número de huellas del índice neutral.

### Analista

Fuente de medición posterior, no generador de temas.

Ingeniero, Ojeador y Comparador se muestran conceptualmente como **procesos independientes** y Solucionador debe funcionar aunque estén desactivados.

## Resumen

El Resumen muestra principalmente:

- dossiers con conocimiento;
- preguntas aprendidas;
- aprendidas sin categoría;
- CREATE_POST;
- IMPROVE_POST;
- MERGE_CONTENT;
- NO_ACTION;
- DEFER/REVIEW;
- borradores.

También muestra el progreso del procesamiento por lotes.

## Medición

Los KPIs editoriales globales pertenecen a **Analista**.

Solucionador conserva workflow y referencias de contenido, pero la antigua tabla de tracking se considera compatibilidad histórica y no la fuente global de medición.

Al abrir un brief puede consultar métricas disponibles de Analista para el post correspondiente.

## Persistencia

Solucionador 0.5 mantiene:

- `seo_solucionador_topics`
- `seo_solucionador_evidence`
- `seo_solucionador_post_topics` (compatibilidad)
- `seo_solucionador_coverage` (almacenamiento actual del índice compartido)
- `seo_solucionador_workflow`
- `seo_solucionador_tracking` (compatibilidad histórica)
- `seo_solucionador_dossiers`

`SEO_Solucionador_DB::maybe_install()` comprueba versión y existencia de todas las tablas y usa `dbDelta()` cuando hace falta crear/actualizar el esquema.

## Exportación JSON

Schema actual:

`seo-solucionador-export-v3`

El brief carga los detalles de las preguntas sólo cuando se abre.

Incluye:

- estado del último escaneo;
- snapshot de Academia;
- dossiers ligeros;
- temas/decisiones;
- evidencias ligeras;
- cobertura;
- workflow.

No exporta la tabla histórica de tracking como fuente de KPIs globales.

Los detalles pesados de las preguntas siguen siendo bajo demanda en el brief.

## Tests

`SEO_Solucionador_Tests::run()` valida, sin escribir datos:

1. dossier canónico único por categoría;
2. masa crítica insuficiente → DEFER;
3. categoría no demostrable → DEFER;
4. dossier sin cobertura → CREATE_POST;
5. covered → NO_ACTION;
6. partial post → IMPROVE_POST;
7. duplicate → MERGE_CONTENT;
8. Academia como origen editorial único;
9. APIs de lote/cursor/detalle bajo demanda;
10. contrato del rol editorial común;
11. cobertura neutral + wrapper compatible.

Un fallo debe bloquear conscientemente una promoción a producción.

## Criterios de aceptación

- N preguntas pass_* de una categoría generan un dossier con N referencias.
- Una pregunta sin categoría demostrable no crea dossier ni URL.
- Dos ciclos no duplican dossiers ni posts.
- Un dossier cubierto nunca crea un segundo post.
- CREATE_POST crea sólo draft.
- El borrador se clasifica en la categoría editorial WordPress **Guías**.
- El `post_content` nace con un brief editorial legible y advertencia de reescritura.
- El post usa `dependiente_qa_basic`.
- El post conserva una única relación comercial `post_to_category` con la `product_cat` de origen.
- El post conserva snapshot interno de `question_ids`, `run_ids` y `source_hash` aunque se edite el texto.
- El Vocabulary del post incorpora los grupos canónicos disponibles de la `product_cat` de origen.
- Solucionador funciona sin Ingeniero, Ojeador o Comparador.
- El escaneo es reanudable por lotes.
- Los detalles pesados se cargan bajo demanda.
- Las plantillas no muestran bloques sin un post publish.
- La medición global procede de Analista.

## Flujo final

~~~text
Academia / Entrenador
        ↓
preguntas pass_*
        ↓
resolución product_cat
        ↓
dossier ligero persistente
        ↓
cobertura compartida
        ↓
decisión mínima
        ↓
brief bajo demanda
        ↓
Editora
        ↓
post WordPress draft
        ↓
publicación humana
        ↓
plantillas
        ↓
Analista
~~~

Solucionador queda como un servicio pequeño, category-first y predecible para contenido básico de Dependiente.
