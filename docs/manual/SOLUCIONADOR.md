# Solucionador

## Estado operativo

- **Versión funcional:** 0.7.1.
- **Contrato editorial:** FAQ + Dependiente.
- **Arquitectura de referencia:** 04/10/2026.
- **Issue:** #698.
- **Publicación:** siempre humana.

## Responsabilidad

Solucionador **no aprende, no investiga y no publica**.

Su función es recoger conocimiento editorial ya existente, organizarlo por `product_cat` y entregárselo a Editora.

Tiene exactamente dos entradas editoriales:

```text
FUENTE 1 · FAQ
seo_faq
pregunta humana + respuesta humana
                 │
                 ├──────→ DOSSIER product_cat
                 │               ↓
FUENTE 2 · DEPENDIENTE           PROPUESTA
conocimiento consolidado         ↓
trainer + reglas aprobadas     EDITORA
                                 ↓
                               DRAFT
                                 ↓
                           edición humana
                                 ↓
                            publicación
```

Ingeniero, Comparador, Ojeador, Marketing y Analista **no son fuentes de conocimiento de Solucionador**.

## Fuente 1 — FAQ

Tabla:

`{$wpdb->prefix}seo_faq`

Una FAQ entra cuando:

1. está activa;
2. tiene pregunta;
3. tiene respuesta;
4. puede asociarse de forma demostrable a una `product_cat`.

Contrato mínimo:

- `origin=faq`;
- `faq_id/source_id`;
- `question`;
- `answer` humana original;
- `object_type`;
- `object_id`;
- `category_id`;
- `product_id`, si procede;
- `source_hash`;
- fechas.

Reglas:

- no necesita pasar por Dependiente;
- no necesita `pass_top1/pass_top3/pass_top8`;
- no necesita una masa mínima;
- no se sustituye por una respuesta de Dependiente;
- no se reinterpreta como conocimiento generado automáticamente.

Asociación:

- `object_type=2` → categoría directa;
- `object_type=3` → producto → categorías WooCommerce.

No se inventa `category_id` por similitud textual.

Enviar una pregunta FAQ a Academia/Entrenador es sólo una forma adicional de entrenar Dependiente. Si Dependiente termina aprendiendo esa pregunta, podrá aparecer también en la segunda fuente como `origin=dependiente`.

## Fuente 2 — Dependiente

Solucionador accede al conocimiento de Dependiente mediante:

`SEO_Dependiente_Editorial_Knowledge`

Archivo:

`includes/dependiente/seo-dependiente-editorial-knowledge.php`

La API encapsula las tablas internas y evita que Solucionador tenga que conocer cada mecanismo de aprendizaje.

### Entrenador / Academia

Se considera conocimiento consolidado cuando:

- la pregunta pertenece al currículo activo;
- su último run está `answered`;
- `evaluation_status` empieza por `pass_*`.

Solucionador conserva:

- pregunta;
- respuesta/evidencia que realmente devuelve Dependiente;
- tipo;
- lección;
- run;
- validación;
- score;
- origen interno;
- fechas.

### Reglas semánticas consolidadas

También pueden entrar reglas activas que Dependiente considera conocimiento consolidado.

Se sigue el mismo criterio que la herramienta de transferencia de conocimiento de Dependiente:

- incluir conocimiento activo consolidado;
- excluir `seed`;
- excluir `academy_stage`;
- excluir `learned_candidate`;
- excluir `learned_rejected`.

No se leen search logs ni consultas de visitantes como si fueran conocimiento.

Las reglas semánticas sólo entran en un dossier cuando existe una relación canónica demostrable con una `product_cat`, por ejemplo mediante metadatos, producto relacionado o Vocabulary ya asignado.

### Contrato común de Dependiente

- `origin=dependiente`;
- `dependiente_source=trainer|academy|learned|manual|...`;
- `source_id`;
- `category_id`;
- `product_id`, si procede;
- `question`;
- `answer`;
- `source_hash`;
- `validation`;
- `question_type`;
- `lesson_key`;
- `run_id`, si existe;
- `confidence`;
- `first_seen_at`;
- `last_seen_at`;
- `editorial_candidate`;
- `discard_reason`.

## FAQ y Dependiente no se deduplican entre sí

Si la misma pregunta aparece en las dos fuentes, se conservan los dos items.

Ejemplo:

```text
FAQ
Pregunta: ¿Cómo sé qué longitud de abrazadera necesito?
Respuesta: [respuesta humana original]

DEPENDIENTE
Pregunta: ¿Cómo sé qué longitud de abrazadera necesito?
Respuesta: [respuesta que realmente sabe Dependiente]
Validación: pass_top1
```

No se elimina por igualdad de texto, similitud, misma categoría o mismo producto.

Editora decide:

- usar FAQ;
- usar Dependiente;
- combinar ambos;
- descartar uno;
- reescribir una pregunta mejor.

## Dossier único por product_cat

La unidad editorial es:

**1 dossier por `category_id`**

No existe un dossier FAQ y otro Dependiente para la misma categoría.

Identidades de item:

- `faq:123`;
- `dependiente:trainer:456`;
- `dependiente:semantic:789`.

Las claves antiguas `dependiente:456` se normalizan a `dependiente:trainer:456` para evitar falsos cambios durante la migración.

## Filtro editorial

### FAQ

Una FAQ activa, con pregunta/respuesta y categoría demostrable es candidata por defecto.

### Dependiente

El conocimiento fuente nunca se borra.

Solucionador puede marcar como no candidato editorial ruido de entrenamiento como:

- “¿Qué es esta categoría?”;
- “¿Qué productos contiene esta categoría?”;
- “Lista los productos de…”.

Se conserva diagnóstico mediante:

- `editorial_candidate=true|false`;
- `discard_reason`.

## Sin masa mínima

No existe un bloqueo del tipo “mínimo 3 preguntas”.

La densidad de material puede mostrarse como indicador, pero una única FAQ útil puede ser suficiente para iniciar una propuesta.

## Cobertura y duplicación

Cobertura, duplicación y canibalización son indicadores editoriales.

Recomendaciones principales:

- `CREATE_POST`: material válido sin cobertura equivalente;
- `IMPROVE_POST`: existe un post con cobertura parcial/débil;
- `NO_ACTION`: contenido relacionado/cubierto/solapado;
- `DEFER`: falta `product_cat` demostrable.

Estas acciones **no son permisos**. Un `NO_ACTION` no oculta el dossier.

## Flujo obligatorio

```text
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
```

Solucionador:

- no publica;
- no decide las preguntas definitivas;
- no redacta automáticamente el texto público final;
- no modifica silenciosamente un post publicado;
- no altera las fuentes al marcar “Usar/Descartar”.

## Cambios y NEEDS_UPDATE

El dossier mantiene:

- `item_hashes`;
- `source_hash`;
- `reviewed_item_hashes`;
- `reviewed_hash`;
- snapshot de items revisados.

Estados de cambio:

- **NEW**;
- **MODIFIED**;
- **RETIRED**;
- **UNCHANGED**.

Casos que pueden cambiar el hash:

- nueva FAQ;
- respuesta FAQ modificada;
- FAQ desactivada;
- nuevo aprendizaje de Dependiente;
- nuevo run de una pregunta ya existente;
- regla semántica añadida/modificada/desactivada.

Si cambia una fuente tras revisión:

`source_hash != reviewed_hash → NEEDS_UPDATE`

El contenido público no se modifica.

## Procesamiento incremental

Carriles independientes:

- `faq_cursor`;
- `dependiente_cursor` — preguntas de Entrenador;
- `dependiente_run_cursor` — nuevos runs para preguntas ya inventariadas;
- `dependiente_semantic_cursor` — reglas semánticas consolidadas.

Puede ocurrir:

```text
FAQ: complete
Dependiente trainer: 20.000 / 38.391
Dependiente semantic: en curso
```

y Editora puede revisar propuestas existentes.

Cambiar FAQ no reinicia el recorrido de Dependiente.

La migración conserva el cursor largo de Entrenador cuando existe. Los nuevos collectors continúan en sus propios cursores.

## KPIs

### FAQ

- FAQs totales;
- activas;
- procesadas;
- con categoría;
- sin categoría;
- items FAQ en dossiers.

### Dependiente

- inventario de preguntas de Entrenador;
- inventario de reglas semánticas consolidadas;
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
- ambas fuentes;
- sin información.

### Editorial

- propuestas;
- drafts;
- NEEDS_UPDATE;
- publicados.

## Interfaz de propuesta

La propuesta muestra dos secciones independientes:

### FAQs

- estado NEW/MODIFIED/RETIRED/UNCHANGED;
- pregunta;
- respuesta humana original;
- producto/categoría;
- fecha.

### Dependiente

- estado;
- pregunta/conocimiento;
- respuesta;
- `dependiente_source`;
- tipo;
- lección;
- validación;
- score/confianza;
- fecha.

Cada item puede marcarse:

- Pendiente;
- Usar;
- Descartar.

Esta decisión editorial no modifica la fuente.

## Draft y posts publicados

`SEO_Solucionador_Posts::create_draft()` crea únicamente un `post_status=draft`.

El brief separa:

- FAQs editoriales;
- conocimiento de Dependiente.

La Editora revisa y reescribe.

Un post publicado con conocimiento nuevo/modificado/retirado pasa a `NEEDS_UPDATE`, pero conserva su contenido hasta que una persona lo edite.

## Export JSON

Schema:

`seo-solucionador-export-v6`

Cada dossier debe exponer:

```json
{
  "category_id": 39837,
  "faq_count": 8,
  "dependiente_count": 45,
  "items": {
    "faq": [],
    "dependiente": []
  }
}
```

Cada item incluye como mínimo:

- `origin`;
- `source_id`;
- `item_id`;
- `question`;
- `answer`;
- `hash`;
- `status`.

Dependiente añade, cuando existe:

- `dependiente_source`;
- validación;
- tipo;
- lección;
- confianza;
- fechas.

## Dependencias

Para generar dossiers Solucionador necesita:

- WordPress/WooCommerce `product_cat`;
- `seo_faq`;
- API editorial de Dependiente.

Ingeniero y Comparador pueden estar desactivados.

La cobertura editorial puede utilizarse para recomendar una acción, pero no es una fuente de conocimiento.

## Criterios de aceptación

1. FAQ nunca enviada a Dependiente aparece igualmente.
2. Conocimiento Dependiente sin FAQ aparece en Dependiente.
3. La misma pregunta en ambas fuentes conserva dos items.
4. Respuestas diferentes se conservan.
5. Desactivar FAQ cambia la huella y genera revisión.
6. Nuevo aprendizaje de Dependiente genera revisión.
7. Una FAQ válida no necesita masa mínima.
8. Sin `product_cat` no se inventa categoría.
9. Solucionador nunca publica automáticamente.
10. Un post publicado no se modifica automáticamente.
11. Ingeniero/Comparador no son requisitos.
12. Reiniciar/continuar el worker no crea dossiers duplicados.
13. Dos ejecuciones sin cambios mantienen el mismo `source_hash`.
14. El export separa FAQ y Dependiente.

**Principio final:** Solucionador no aprende. Recoge conocimiento humano de FAQ y conocimiento real de Dependiente, los mantiene separados dentro de un único dossier por categoría y se los entrega a Editora.
