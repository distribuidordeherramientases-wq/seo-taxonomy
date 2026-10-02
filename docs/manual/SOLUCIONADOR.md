# Solucionador

## Propósito

**Solucionador 0.5.0** tiene un alcance editorial reducido y predecible:

**Academia / Entrenador → dossier por product_cat → cobertura → decisión editorial → brief básico → Editora → post WordPress.**

Solucionador ya no es el concentrador de Ingeniero, Ojeador, Comparador, Clasificador, Marketing, Comentarista, Auditor o Analista.

Los tres procesos editoriales principales quedan separados:

1. **Academia/Entrenador → Solucionador → post básico** con rol `dependiente_qa_basic`.
2. **Ingeniero → proceso editorial técnico propio**.
3. **Ojeador → Comparador → post comparativo**.

La cobertura editorial puede compartirse. La medición global de resultados pertenece a **Analista**.

## Qué hace

Solucionador:

- lee preguntas activas de Academia;
- exige que el último run esté `answered` y `evaluation_status = pass_*`;
- resuelve `product_cat` únicamente mediante relaciones demostrables;
- agrupa preguntas aprendidas por categoría;
- comprueba cobertura editorial existente;
- propone una acción mínima;
- prepara el brief de trabajo para Editora;
- crea un borrador WordPress únicamente después de aprobación humana;
- relaciona el post con `product_cat`;
- marca el contenido con el rol `dependiente_qa_basic`.

Solucionador no:

- investiga;
- consulta Internet;
- consulta Google Shopping;
- calcula comparativas de mercado;
- necesita conocimiento de Ingeniero;
- crea landings;
- redacta respuestas públicas automáticamente;
- publica posts automáticamente;
- abre conexiones propias a GSC/GA4/Bing;
- mide resultados globales.

## Entrada editorial única

La fuente editorial es Academia/Entrenador.

Tablas:

- `{$wpdb->prefix}seo_dependiente_trainer_questions`
- `{$wpdb->prefix}seo_dependiente_trainer_runs`

Una pregunta entra en el circuito cuando:

- está activa;
- pertenece al currículo normal, no a Laboratorio;
- tiene un último run;
- el run está `answered`;
- `evaluation_status` empieza por `pass_`.

Durante el escaneo se leen sólo los datos ligeros necesarios:

- question_id;
- pregunta;
- question_type;
- lesson_key;
- source_type/source_id/source_key;
- expected_json;
- evaluation_status;
- evaluation_score;
- fecha del run.

Los datos pesados —`evaluation_json`, `top_results`, `response_meta`— se recuperan **bajo demanda** cuando se abre el brief.

## Resolución de categoría

Solucionador sólo acepta una categoría cuando puede demostrarse.

Casos soportados:

- `kind=category` → `category_id`;
- `kind=product` → producto → `product_cat`;
- `kind=features` → `source_product_id` → `product_cat`;
- `kind=faq` con owner_type 2 → categoría;
- `kind=faq` con owner_type 3 → producto → `product_cat`;
- source_type category/product/features cuando la relación es inequívoca.

No se usa similitud textual para inventar una categoría.

Una pregunta aprendida sin categoría demostrable:

- no crea dossier;
- no crea URL;
- aparece en el KPI **Aprendidas sin categoría**.

## Dossier por categoría

Por defecto existe un único dossier por `product_cat`.

Clave canónica:

`dependiente-qa-basic|resolver|category-{term_id}|general|general`

El dossier persiste únicamente:

- category_id;
- categoría principal;
- question_count;
- question_ids;
- score agregado;
- última validación;
- hash;
- cobertura;
- decisión;
- workflow.

No almacena una copia gigante de todos los resultados de Academia.

Dos escaneos consecutivos reutilizan la misma clave canónica; no deben crear dossiers duplicados.

## Procesamiento por lotes

El escaneo usa lotes pequeños y cursor persistente.

Parámetros iniciales:

- `BATCH_SIZE = 200`;
- máximo de 20 lotes por petición;
- límite temporal aproximado de 20 segundos por petición.

Estado persistido:

`seo_solucionador_scan_state`

Si una ejecución no termina:

- conserva cursor;
- conserva contadores;
- conserva evidencias ya procesadas;
- puede reanudarse desde Resumen.

Al finalizar se elimina el estado temporal del escaneo.

El objetivo es impedir que Solucionador cargue simultáneamente todas las categorías, preguntas y payloads en memoria.

## Search log

`seo_dependiente_search_log` continúa existiendo, pero cambia su función dentro de Solucionador.

No origina temas independientes.

Sólo puede reforzar la prioridad de una categoría que ya tenga dossier de Academia. Se consumen agregados ligeros de demanda y no payloads completos.

Academia indica **lo que Dependiente ya sabe**.

Search log indica **qué demanda real existe entre visitantes**.

## Decisiones permitidas

| Acción | Regla |
| --- | --- |
| `CREATE_POST` | Masa crítica suficiente, categoría demostrada, riesgo aceptable y sin cobertura equivalente |
| `IMPROVE_POST` | Existe un post de la misma intención con cobertura parcial/débil |
| `MERGE_CONTENT` | Existen piezas duplicadas, solapadas o conflictivas |
| `NO_ACTION` | La intención ya está suficientemente cubierta |
| `DEFER` | Falta categoría, masa crítica o existe una condición que requiere revisión |

Solucionador no propone:

- `CREATE_LANDING`;
- `IMPROVE_LANDING`;
- `IMPROVE_CATEGORY`;
- `IMPROVE_PAGE`;
- investigación técnica dependiente de Ingeniero.

El umbral inicial de masa crítica es de **3 preguntas aprendidas**, filtrable mediante:

`seo_solucionador_min_academy_questions`

## Brief para Editora

El brief carga los detalles de las preguntas sólo cuando se abre.

Incluye:

- título propuesto;
- product_cat principal;
- número de preguntas;
- lista de preguntas aprendidas;
- lesson/type;
- evaluation_status;
- evaluation_score;
- resultados internos relevantes como evidencia;
- cobertura existente;
- decisión y motivo;
- Vocabulary;
- enlaces internos recomendados;
- workflow.

Regla editorial:

**Editora redacta. Solucionador no publica literalmente respuestas internas de Academia.**

## Contrato del post

Cuando una propuesta `CREATE_POST` está aprobada:

- `post_type = post`;
- `post_status = draft`;
- WordPress no publica automáticamente;
- se conserva `_seo_solucionador_topic_id`;
- se conserva `_seo_solucionador_canonical_key`;
- se asigna rol `dependiente_qa_basic` mediante la API editorial común;
- se crea la relación `post_to_category`;
- se aplica Vocabulary mediante las APIs existentes.

API común de rol:

`seo_post_editor_set_public_content_role()`

## Plantillas

Las plantillas de categoría y producto recuperan posts relacionados mediante `post_to_category`.

Para el bloque de preguntas habituales:

- sólo `post_status=publish`;
- sólo rol `dependiente_qa_basic`;
- si no existe contenido publicado, no se muestra título ni contenedor vacío.

El borrador de Solucionador nunca debe aparecer públicamente.

## Cobertura editorial compartida

La implementación principal se expone mediante:

`SEO_Editorial_Coverage`

Operaciones:

- `rebuild_index()`;
- `rebuild_post_index()`;
- `find()`.

Durante la transición, la tabla histórica de cobertura se conserva para no introducir una migración destructiva.

El wrapper:

`SEO_Solucionador_Coverage`

continúa disponible y delega en la API compartida.

## Medición

Solucionador no considera su antigua tabla de tracking como fuente global de rendimiento.

Los KPIs posteriores a publicación pertenecen a **Analista**.

La interfaz de Solucionador indica esta frontera y no inicia conexiones propias con Google Search Console, Analytics o Bing.

## Interfaz

Pestañas visibles:

1. **Resumen**
2. **Dossiers / propuestas**
3. **Cobertura editorial**
4. **Academia / fuentes**
5. **Pruebas arquitectura**
6. **Datos internos**

Resumen muestra:

- dossiers;
- CREATE_POST;
- IMPROVE_POST;
- MERGE_CONTENT;
- NO_ACTION;
- DEFER;
- preguntas aprendidas;
- aprendidas con/sin categoría;
- categorías con conocimiento;
- estado del escaneo por lotes.

## Pruebas

Las regresiones `SOL-A01` a `SOL-A11` comprueban:

- una clave canónica por categoría;
- masa crítica;
- CREATE_POST sin Ingeniero;
- NO_ACTION si ya existe cobertura;
- IMPROVE_POST;
- MERGE_CONTENT;
- DEFER sin categoría;
- acciones dentro del alcance reducido;
- rol `dependiente_qa_basic`;
- procesamiento ligero/bajo demanda;
- independencia de Ingeniero/Ojeador/Comparador/Analista.

## Seguimiento

Issue de implementación: **#547**.

Referencia funcional: **Requisitos Solucionador Arquitectura Separada — 02/10/2026**.

Versión funcional: **0.5.0**.

Versión de esquema: **0.5.0**.

La validación se realiza primero en **staging**. Producción requiere autorización expresa.
