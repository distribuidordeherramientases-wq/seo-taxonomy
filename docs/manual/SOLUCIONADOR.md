# Solucionador

## Estado operativo

- **Versión funcional:** 0.7.2.
- **Versión de esquema:** 0.7.1.
- **Arquitectura de referencia:** 04/10/2026.
- **Issues:** #698, #705.
- **Validación:** staging antes de producción.

## Responsabilidad

Solucionador **no aprende**. Su función es recoger conocimiento editorial ya existente, organizarlo por `product_cat` y entregárselo a Editora.

Tiene exactamente dos entradas editoriales:

~~~text
FUENTE 1 · FAQ
seo_faq
pregunta humana + respuesta humana
                 │
                 ├──────→ DOSSIER product_cat
                 │               ↓
FUENTE 2 · DEPENDIENTE           PROPUESTA
conocimiento real/aprendido      ↓
trainer + reglas consolidadas   EDITORA
                                 ↓
                               DRAFT
                                 ↓
                           edición humana
                                 ↓
                            publicación
~~~

No necesita Ingeniero, Comparador, Ojeador, Marketing ni Analista para generar dossiers.

## Fuente 1 — FAQ

Tabla:

`{$wpdb->prefix}seo_faq`

Una FAQ activa con pregunta, respuesta y asociación demostrable a una `product_cat` es material editorial válido por defecto.

Contrato mínimo:

- `origin=faq`
- `faq_id/source_id`
- `question`
- `answer` original
- `object_type`
- `object_id`
- `category_id`
- `product_id` cuando proceda
- `source_hash`
- `created_at/updated_at`

Reglas:

- no necesita pasar por Dependiente;
- no necesita `pass_top1/pass_top3/pass_top8`;
- no necesita masa mínima;
- no se sustituye por una respuesta de Dependiente;
- no se reinterpreta como conocimiento automático;
- si `object_type=2`, el objeto es directamente una `product_cat`;
- si `object_type=3`, se resuelve producto → `product_cat`;
- no se inventa categoría por similitud textual.

Enviar una pregunta FAQ a Academia es únicamente otra forma de entrenar Dependiente. Eso no cambia el origen de la FAQ directa.

## Fuente 2 — Dependiente

Solucionador consume Dependiente mediante:

`SEO_Dependiente_Editorial_Knowledge`

Archivo:

`includes/dependiente/seo-dependiente-editorial-knowledge.php`

El proveedor encapsula las tablas internas para evitar que Solucionador dependa de su implementación.

Actualmente puede exponer:

1. **Entrenador / Academia**
   - pregunta activa del currículo;
   - último run `answered`;
   - `evaluation_status=pass_*`;
   - respuesta/evidencia que realmente devuelve Dependiente.

2. **Reglas semánticas consolidadas**
   - activas;
   - `source=academy|learned`;
   - nunca `academy_stage`, `learned_candidate` o `learned_rejected`.

No se usan como conocimiento editorial:

- candidatos pendientes;
- reglas rechazadas;
- estados transitorios;
- search logs;
- búsquedas de visitantes;
- material que Dependiente todavía no considera aprendido/aprobado.

Contrato normalizado:

- `origin=dependiente`
- `dependiente_source=trainer|academy|learned`
- `source_id`
- `category_id`
- `product_id` cuando proceda
- `question`
- `answer`
- `source_hash`
- `validation`
- `question_type`
- `lesson_key`
- `run_id` cuando exista
- `confidence`
- `first_seen_at/last_seen_at`
- `editorial_candidate`
- `discard_reason`

Si una pregunta nació en una FAQ y después Dependiente la aprendió, dentro de este segundo carril continúa siendo:

`origin=dependiente`

La trazabilidad técnica puede indicar que vino de Entrenador/FAQ, pero editorialmente se conserva lo que **realmente sabe Dependiente**.

## No deduplicación FAQ vs Dependiente

No se realiza deduplicación destructiva entre fuentes.

Ejemplo:

~~~text
FAQ
Pregunta: ¿Cómo sé qué longitud de abrazadera necesito?
Respuesta: [respuesta humana]

DEPENDIENTE
Pregunta: ¿Cómo sé qué longitud de abrazadera necesito?
Respuesta: [respuesta que sabe Dependiente]
Validación: pass_top1
~~~

Se conservan los dos items.

No se elimina por:

- texto idéntico;
- similitud;
- mismo tema;
- mismo producto;
- mismo `category_id`.

Editora decide si usa uno, mezcla ambos o descarta uno.

## Asociación con product_cat

La unidad editorial es:

**1 dossier por `category_id`**

No existe un dossier FAQ separado de otro Dependiente.

La categoría sólo se acepta cuando puede demostrarse mediante:

- relación directa de FAQ con categoría;
- FAQ de producto → producto → categorías WooCommerce;
- metadatos canónicos del aprendizaje;
- producto conocido → categorías WooCommerce;
- relaciones de Vocabulary canónicas ya asignadas a `product_cat`.

No se usa parecido textual.

Un item sin categoría se contabiliza como **SIN_CATEGORY** y no origina propuesta hasta resolverla.

## Modelo de item

Identidades estables actuales:

- `faq:123`
- `dependiente:trainer:456`
- `dependiente:semantic:789`

Las claves mantienen separadas las dos fuentes incluso cuando pregunta y respuesta sean similares.

## Filtro editorial de Dependiente

El conocimiento fuente no se borra.

Solucionador puede excluir de la candidatura editorial ruido de entrenamiento como:

- “¿Qué es esta categoría?”
- “¿Qué productos contiene esta categoría?”
- “Lista los productos de…”

El proveedor marca:

- `editorial_candidate=true|false`
- `discard_reason`

FAQ activa y correctamente relacionada se considera candidata por defecto.

## Densidad de material

No existe un bloqueo de “mínimo 3 preguntas”.

El antiguo `minimum_academy_questions` sólo puede utilizarse como **indicador de densidad**.

Una categoría con una única FAQ útil puede producir una propuesta.

## Cobertura y duplicación

Cobertura, riesgo de duplicación y canibalización son **recomendaciones editoriales**, no permisos.

Acciones recomendadas principales:

- `CREATE_POST`: no se detecta cobertura equivalente.
- `IMPROVE_POST`: existe un post con cobertura débil/parcial.
- `NO_ACTION`: existe cobertura, duplicación, conflicto o no hay una recomendación automática clara.
- `DEFER`: falta `product_cat` demostrable.

Aunque la recomendación sea `NO_ACTION`, el dossier sigue visible para Editora.

## Dossier persistente

Tabla:

`{$wpdb->prefix}seo_solucionador_dossiers`

Campos relevantes:

- `category_id`
- `category_name`
- `question_count`
- `faq_count`
- `dependiente_count`
- `faq_ids`
- `dependiente_keys`
- `question_ids` — compatibilidad con trainer antiguo
- `item_hashes`
- `source_hash`
- `reviewed_hash`
- `reviewed_item_hashes`
- `editorial_status`
- `scan_token`
- fechas

El `source_hash` se calcula con los hashes de todos los items de las dos fuentes y es estable frente al orden de lectura.

Dos ejecuciones sin cambios mantienen el mismo hash.

## Cambios posteriores

Se comparan:

`item_hashes actuales`

contra:

`reviewed_item_hashes`

Estados:

- **NEW**
- **MODIFIED**
- **RETIRED**
- **UNCHANGED**

Cualquiera de estos cambios no revisados provoca:

`source_hash != reviewed_hash → NEEDS_UPDATE`

Casos incluidos:

- nueva FAQ;
- respuesta FAQ modificada;
- FAQ desactivada;
- nuevo aprendizaje de Dependiente;
- regla aprendida modificada/desactivada;
- cambio en una respuesta/evidencia de Dependiente.

Solucionador **no sobrescribe `post_content`**.

Si el post está publicado, permanece exactamente como está hasta revisión humana.

## Procesamiento incremental

Los carriles son independientes:

- `faq_cursor`
- `dependiente_cursor.trainer` — inventario de preguntas;
- `dependiente_cursor.trainer_run` — nuevas respuestas/runs para preguntas ya conocidas;
- `dependiente_cursor.semantic` — conocimiento semántico consolidado

La palabra `complete` describe únicamente que **esa pasada de inventario alcanzó el final conocido de la fuente en ese momento**. No significa que Dependiente haya terminado de aprender ni constituye una condición para crear propuestas.

Dependiente es un sistema de aprendizaje continuo. Por tanto, Solucionador trabaja siempre con una **foto del conocimiento disponible en el momento de la ejecución**.

Puede existir:

~~~text
FAQ: pasada actual completada
Dependiente trainer: 20.000 / 38.391
Dependiente semantic: pasada actual completada
Propuestas: disponibles y revisables
~~~

y Editora puede revisar los dossiers ya disponibles sin esperar a que Entrenador alcance 38.391/38.391 ni a que Dependiente deje de aprender.

### Ejecución por lote

Desde **#705**, `scan_batch()` devuelve las categorías modificadas durante el lote mediante `changed_category_ids`.

`SEO_Solucionador_Engine::scan()` procesa esas categorías inmediatamente con `prepare_category_topic()`.

Por tanto:

~~~text
FAQ nueva / conocimiento validado nuevo de Dependiente
                    ↓
             dossier actualizado
                    ↓
          changed_category_ids
                    ↓
        propuesta creada/actualizada
                    ↓
             revisión de Editora
~~~

El inventario de las fuentes puede continuar después. La propuesta **no espera al final del escaneo global**.

### Rescan y nuevas ejecuciones

Cuando se activa el proceso, un worker o un rescan:

1. FAQ se revisa como fuente independiente.
2. Dependiente/Entrenador se revisa como fuente independiente.
3. Las novedades encontradas actualizan únicamente los dossiers afectados.
4. Las propuestas de esos dossiers se materializan en el mismo ciclo.
5. Ningún carril bloquea al otro.

Una FAQ no necesita ser aprendida por Dependiente para entrar en Solucionador.

Una pregunta de Entrenador sólo entra por el carril Dependiente cuando el conocimiento ya está validado, por ejemplo con último run `answered` y `evaluation_status=pass_*`.

La migración conserva cursores cuando es compatible y cada fuente mantiene su propia reconciliación. En Dependiente, una firma nueva no vacía los dossiers: se continúa desde los high-water marks de preguntas, runs y reglas. Las propuestas disponibles siguen siendo utilizables durante el proceso.

## Propuesta editorial

El flujo obligatorio es:

~~~text
FUENTES
  ↓
DOSSIER
  ↓
PROPUESTA
  ↓
REVISIÓN EDITORIAL
  ↓
DRAFT
  ↓
EDICIÓN HUMANA
  ↓
PUBLICACIÓN HUMANA
~~~

Solucionador:

- no publica;
- no elige las preguntas definitivas;
- no redacta la versión pública final;
- no modifica silenciosamente posts publicados;
- no altera las fuentes al marcar un item como usar/descartar.

## Interfaz de propuesta

El material se presenta por origen.

### FAQs

Para cada item:

- estado editorial: NEW / MODIFIED / RETIRED / UNCHANGED;
- pregunta;
- respuesta humana original;
- producto/categoría;
- última actualización.

### Dependiente

Para cada item:

- estado editorial;
- pregunta/conocimiento;
- respuesta;
- `dependiente_source`;
- tipo;
- lección;
- validación;
- score/confianza;
- fecha del aprendizaje.

Cada novedad puede revisarse/seleccionarse/descartarse sin modificar la fuente original.

## Draft y post publicado

`SEO_Solucionador_Posts::create_draft()` crea exclusivamente:

`post_status=draft`

El borrador incluye un brief interno separado en:

- **FAQs editoriales**
- **Entrevista a Dependiente**

La Editora debe reescribir y decidir qué material utilizar.

Cuando una fuente cambia:

~~~text
Publicado + conocimiento nuevo/modificado/retirado = NEEDS_UPDATE
~~~

El contenido público permanece estable.

## KPIs

Resumen mínimo:

### FAQ

- FAQs activas/totales;
- procesadas;
- con categoría;
- sin categoría;
- items FAQ en dossiers.

### Dependiente

- procesados;
- aprendidos/aprobados;
- candidatos editoriales;
- descartados por ruido;
- con categoría;
- sin categoría;
- items Dependiente en dossiers.

### Categorías

- sólo FAQ;
- sólo Dependiente;
- FAQ + Dependiente;
- sin información.

### Editorial

- propuestas;
- drafts;
- NEEDS_UPDATE;
- publicados.

## Export JSON

Schema:

`seo-solucionador-export-v6`

Cada dossier expone las fuentes por separado:

~~~json
{
  "category_id": 39837,
  "faq_count": 8,
  "dependiente_count": 45,
  "items": {
    "faq": [],
    "dependiente": []
  }
}
~~~

Cada item incluye como mínimo:

- `origin`
- `source_id`
- `item_id`
- `question`
- `answer`
- `hash`
- `status`

Dependiente añade validación, tipo, lección, origen interno y confianza cuando existen.

El resumen de evidencias debe distinguir:

- `faq`
- `dependiente`

Nunca volver a agregar todo como `source=dependiente`.

## Dependencias

Para generar dossiers Solucionador sólo necesita:

- WordPress/WooCommerce `product_cat`;
- `seo_faq`;
- API editorial de Dependiente.

Ingeniero, Comparador, Ojeador, Marketing y Analista pueden estar desactivados.

La cobertura neutral puede ayudar a recomendar una acción, pero no es una fuente de conocimiento.

## Tests de aceptación

La suite 0.7.1 valida, entre otros:

1. FAQ y Dependiente generan el mismo topic por categoría sin mezclar fuentes.
2. El contrato declara exactamente `faq|dependiente`.
3. Una sola FAQ puede recomendar `CREATE_POST`.
4. Dependiente sin FAQ también puede hacerlo.
5. Sin categoría demostrable se usa `DEFER`.
6. Cobertura parcial recomienda `IMPROVE_POST`.
7. Duplicación recomienda `NO_ACTION` pero no bloquea el dossier.
8. FAQ y Dependiente con contenido equivalente conservan dos item_id.
9. Se detectan NEW/MODIFIED/RETIRED.
10. El source_hash es estable sin cambios.
11. Desactivar una FAQ cambia el hash.
12. Existe el proveedor editorial encapsulado de Dependiente.
13. El contrato de post sigue siendo draft/humano.
14. El export usa schema v6 y separa ambas fuentes.
15. No existe dependencia obligatoria de Ingeniero/Comparador.
16. El procesamiento es incremental/migrable.
17. Un lote parcial de FAQ o Dependiente puede generar propuesta sin esperar al final del inventario.
18. Entrenador puede seguir aprendiendo mientras Solucionador mantiene propuestas disponibles.
19. Las categorías modificadas en un lote se materializan inmediatamente mediante `changed_category_ids → prepare_category_topic()`.

## Flujo final

~~~text
seo_faq ───────────────────────┐
pregunta + respuesta humana    │
                               ├──→ dossier product_cat
Dependiente ───────────────────┘       ↓
trainer + reglas consolidadas       propuesta
                                      ↓
                                   Editora
                                      ↓
                                    draft
                                      ↓
                              publicación humana
~~~

**Principio final:** Solucionador no aprende. Recoge conocimiento humano de FAQ y conocimiento real de Dependiente, los mantiene separados dentro de un único dossier por categoría y se los entrega a Editora.
