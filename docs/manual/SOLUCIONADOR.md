# Solucionador

## Estado operativo

- **Versión funcional:** 0.7.1.
- **Versión de esquema:** 0.7.0.
- **Arquitectura de referencia:** 02/10/2026.
- **Issue de implementación:** #560.
- **Entorno de validación:** staging antes de producción.

## Responsabilidad

Solucionador tiene un único cometido editorial:

~~~text
FAQ manual ───────────────┐
                           ├──→ dossier único por product_cat
Academia / Entrenador ─────┘
preguntas aprendidas pass_*
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

- **FAQ manual → Solucionador**: pregunta + respuesta editorial activa, sin depender de Dependiente.
- **Dependiente / Academia → Solucionador**: preguntas realmente aprendidas y validadas `pass_*`.
- **Ingeniero → proceso editorial propio**: contenido técnico especializado.
- **Ojeador → Comparador**: contenido comparativo.
- **Analista**: medición global.

Solucionador no investiga, no consulta Internet, no compara mercado, no redacta contenido técnico, no ejecuta Google Shopping y no abre conexiones propias a GSC/GA4/Bing.

## Dos entradas editoriales independientes

Solucionador recibe material por dos caminos que no dependen entre sí.

### FAQ manual

Tabla fuente:

- `seo_faq`

Una FAQ entra directamente cuando está activa, conserva pregunta y respuesta y puede resolverse a una `product_cat` demostrable. No necesita haber sido aprendida por Dependiente. El dossier conserva su `object_type/object_id`, `category_id`, `source_id` y `source_hash`.

Enviar la misma FAQ al formulario de Academia es opcional y sirve exclusivamente para mejorar el conocimiento de Dependiente.

### Dependiente / Academia

Tablas fuente:

- `seo_dependiente_trainer_questions`
- `seo_dependiente_trainer_runs`

Una pregunta de Entrenador entra cuando:

1. pertenece al currículo activo;
2. su último run está `answered`;
3. `evaluation_status` empieza por `pass_*`;
4. pasa el filtro editorial que elimina preguntas definitorias/catalogales triviales;
5. **no procede de una FAQ**.

Se considera eco de FAQ y se excluye del canal Dependiente cuando `question_type=faq_owner_context`, `source_type=faq` o `expected.kind=faq`.

Motivo: enviar una FAQ a Academia sirve para entrenar/evaluar a Dependiente, pero Solucionador ya dispone de la FAQ original con su respuesta completa mediante `seo_faq`. Reintroducir el resultado de Entrenador duplicaría el contenido y podría sustituir una respuesta editorial buena por una respuesta reconstruida y más pobre.

FAQs y resultados internos de Academia son material para Editora. **Solucionador nunca los publica automáticamente ni obliga a conservar el formato pregunta-respuesta.**

## Asociación con product_cat

Solucionador sólo crea dossier cuando puede demostrar la categoría.

Resolución admitida:

- `kind=category` → category_id;
- `kind=product` → producto → product_cat;
- `kind=features` → source_product_id → product_cat;
- FAQ directa `object_type=2` → product_cat;
- FAQ directa `object_type=3` → producto → product_cat;
- source_type category/product cuando la relación es inequívoca.

No se infiere una categoría por parecido textual.

Un elemento sin product_cat demostrable no crea URL. Dependiente queda contabilizado como **Aprendido sin categoría** y FAQ como **FAQ sin categoría**.

## Dossier por categoría

Tabla:

`{$wpdb->prefix}seo_solucionador_dossiers`

Existe como máximo un dossier por `category_id`.

El dossier persiste sólo información ligera:

- category_id;
- category_name;
- question_count total;
- dependiente_count;
- faq_count;
- question_ids;
- faq_ids;
- item_hashes con claves `dependiente:ID` / `faq:ID`;
- score_avg de la evidencia Dependiente;
- last_validated_at / última actualización de fuente;
- source_hash conjunto;
- scan_token;
- fechas.

No guarda una copia gigante de `top_results`, `evaluation_json` o `response_meta`.

Los detalles se recuperan **bajo demanda** mediante `SEO_Solucionador_Dossiers::question_details()`. Cada elemento conserva `origin=faq|dependiente`; una FAQ aporta además su respuesta, object_type/object_id, category_id, source_id y source_hash.

## Procesamiento por lotes

El escaneo mixto de FAQ y Academia es reanudable y mantiene cursores independientes.

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

`includes/editorial/class-seo-editorial-coverage.php`

El antiguo nombre:

`SEO_Solucionador_Coverage`

permanece como wrapper de compatibilidad. La antigua ruta `includes/editorial/seo-editorial-coverage.php` ha sido retirada; todos los procesos cargan la implementación canónica `class-seo-editorial-coverage.php`.

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

- material editorial FAQ + Dependiente;
- encaje con product_cat/catálogo;
- hueco de cobertura;
- confianza técnica/contextual;
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

Al abrir un dossier, Solucionador recupera bajo demanda las dos fuentes y las muestra separadas:

- **FAQ**: pregunta + respuesta manual + trazabilidad del objeto/categoría;
- **Dependiente**: pregunta aprendida + respuesta/evidencia interna + validación `pass_*`.

Los repetidos entre ambas fuentes no bloquean el dossier: Editora puede eliminar, fusionar o sintetizar.

Regla editorial:

> Editora redacta. El dossier es una entrevista/biblioteca interna; Solucionador no publica literalmente FAQ ni respuestas de Dependiente.

## Contrato del post

`SEO_Solucionador_Posts::create_draft()`:

1. exige que la propuesta sea `CREATE_POST`;
2. exige aprobación humana / brief_ready;
3. vuelve a comprobar los gates;
4. recupera el material mixto del dossier;
5. crea un `post` en estado `draft` y categoría editorial WordPress **Guías**;
6. usa el título propuesto como `post_title`;
7. escribe un **brief interno** separado en “FAQs editoriales” y “Entrevista a Dependiente”, con aviso explícito de revisión;
8. conserva `topic_id` y `canonical_key`;
9. persiste en metadatos `faq_ids`, `question_ids`, `run_ids`, `item_keys` y `source_hash`, independientes del texto editable del borrador;
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

### FAQ + Dependiente / Academia

Dos orígenes editoriales independientes que convergen por product_cat.

KPIs:

- FAQs activas/procesadas/con categoría;
- preguntas Academia procesadas/aprendidas/no aprendidas;
- preguntas aprendidas de origen FAQ excluidas del canal Dependiente;
- aprendidas con/sin categoría;
- elementos FAQ y Dependiente dentro de dossiers;
- categorías con/sin material;
- media de elementos por categoría;
- cursor FAQ y cursor Academia;
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
2. eco de FAQ en Entrenador se excluye del canal Dependiente;
3. masa crítica insuficiente → DEFER;
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

- N preguntas pass_* de una categoría generan referencias Dependiente sólo si no proceden de FAQ.
- Una FAQ original entra una sola vez por `seo_faq`, con su pregunta y respuesta completas.
- Una pregunta sin categoría demostrable no crea dossier ni URL.
- Dos ciclos no duplican dossiers ni posts.
- Un dossier cubierto nunca crea un segundo post.
- CREATE_POST crea sólo draft.
- El borrador se clasifica en la categoría editorial WordPress **Guías**.
- El `post_content` nace con un brief editorial legible y advertencia de reescritura.
- El post usa `dependiente_qa_basic`.
- El post conserva una única relación comercial `post_to_category` con la `product_cat` de origen.
- El post conserva snapshot interno de `faq_ids`, `question_ids`, `run_ids`, `item_keys` y `source_hash` aunque se edite el texto.
- El Vocabulary del post incorpora los grupos canónicos disponibles de la `product_cat` de origen.
- Solucionador funciona sin Ingeniero, Ojeador o Comparador.
- El escaneo es reanudable por lotes.
- Los detalles pesados se cargan bajo demanda.
- Las plantillas no muestran bloques sin un post publish.
- La medición global procede de Analista.

## Flujo final

~~~text
FAQ manual ───────────────┐
                           ├──→ resolución product_cat
Academia / Entrenador ─────┘
preguntas pass_*
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

Solucionador queda como un servicio category-first que combina FAQ editorial y conocimiento aprendido por Dependiente, conservando el origen de cada elemento y dejando siempre la redacción/publicación en manos de Editora.
