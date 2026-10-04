# Solucionador

## Estado operativo

- **Versión funcional:** 0.7.1.
- **Versión de esquema:** 0.7.1.
- **Arquitectura de referencia:** 04/10/2026.
- **Issue:** #698.
- **Validación:** staging antes de producción.

## Objetivo

Solucionador no aprende y no investiga. Su trabajo es sencillo:

1. leer material útil ya existente;
2. agruparlo por `product_cat`;
3. preparar una propuesta para Editora;
4. crear únicamente un borrador cuando Editora lo decida.

Flujo:

~~~text
FAQ activas ───────────────┐
                           ├──→ dossier product_cat → propuesta → Editora → draft
Entrenador de Dependiente ─┘
último run answered + pass_*
~~~

Solucionador no necesita Ingeniero, Comparador, Ojeador, Marketing ni Analista para generar dossiers.

## Fuente 1 — FAQ

Tabla:

`{$wpdb->prefix}seo_faq`

Una FAQ entra directamente cuando:

- está activa;
- tiene pregunta y respuesta;
- puede asociarse de forma demostrable a una `product_cat`.

La pregunta y la respuesta son contenido humano original.

No necesita:

- haber pasado por Dependiente;
- `pass_top1/pass_top3/pass_top8`;
- masa mínima;
- confianza de Academia.

Se conserva:

- `origin=faq`;
- `source_id/faq_id`;
- pregunta;
- respuesta;
- objeto;
- categoría;
- hash;
- fecha.

## Fuente 2 — Dependiente

Solucionador lee directamente:

- `seo_dependiente_trainer_questions`;
- `seo_dependiente_trainer_runs`.

No lee reglas semánticas, search logs, candidatos, estados transitorios ni otras tablas de conocimiento.

Para cada pregunta activa del Entrenador:

1. busca su **último run**;
2. exige `status=answered`;
3. exige `evaluation_status=pass_*`;
4. sólo entonces la considera conocimiento aprendido por Dependiente.

Editorialmente:

`origin=dependiente`

Se conserva, cuando existe:

- `question_id`;
- pregunta;
- tipo;
- lección;
- origen técnico;
- `run_id`;
- validación;
- score;
- resultados/evidencia;
- fecha del run.

Si la pregunta no tiene un último resultado bueno, Solucionador no la usa.

## Filtro de ruido

Después de comprobar que Dependiente la evaluó correctamente, Solucionador puede marcar como no candidata editorial preguntas puramente mecánicas, por ejemplo:

- “¿Qué es esta categoría?”
- “¿Qué productos contiene esta categoría?”
- “Lista los productos de…”

La pregunta no se borra del Entrenador.

Se registra como descartada editorialmente para diagnóstico.

## FAQ y Dependiente no se deduplican

Si existe la misma pregunta en FAQ y en Entrenador, se conservan las dos:

~~~text
FAQ
pregunta + respuesta humana

DEPENDIENTE
misma pregunta + respuesta/evidencia aprendida
~~~

Editora decide cuál utilizar.

## Asociación a product_cat

Solucionador sólo usa relaciones demostrables.

FAQ:

- categoría directa;
- producto → categorías WooCommerce.

Dependiente:

- `expected_json`;
- origen category/product/features;
- producto → categorías WooCommerce;
- asociación canónica ya guardada por Entrenador.

No se inventa una categoría por similitud textual.

Si no puede demostrarse la categoría, el elemento queda como **sin categoría** y no genera propuesta.

## Dossier

Existe un único dossier por `category_id`.

Ejemplo:

~~~text
Abrazaderas
├─ FAQ 123
├─ FAQ 456
├─ Dependiente 115078
└─ Dependiente 126929
~~~

No existe un tercer origen mixto.

## Masa mínima

No hay bloqueo por número mínimo de preguntas.

Una sola FAQ útil o una sola pregunta buena de Dependiente puede ser suficiente para que Editora vea el dossier.

La cantidad de material sólo sirve como indicador.

## Recomendación editorial

Solucionador puede recomendar:

- `CREATE_POST`;
- `IMPROVE_POST`;
- `NO_ACTION`;
- `DEFER` si no hay categoría demostrable.

Cobertura, duplicación y canibalización son indicadores, no permisos para ocultar el dossier.

## Cambios en las fuentes

Cada item mantiene un hash.

El dossier calcula un `source_hash` estable a partir de los hashes de sus items.

Comparación:

~~~text
source_hash actual
vs
reviewed_hash
~~~

Si cambia una FAQ o cambia el último run válido de Dependiente:

`NEEDS_UPDATE`

Se distinguen:

- NUEVO;
- MODIFICADO;
- RETIRADO.

Nunca se sobrescribe automáticamente el contenido de un post.

## Posts publicados

Un post publicado permanece estable.

~~~text
post publicado + conocimiento nuevo = NEEDS_UPDATE
~~~

Editora debe revisar el cambio antes de modificar el contenido público.

## Procesamiento por lotes

Hay dos cursores independientes:

- `faq_cursor`;
- `dependiente_cursor`.

El cursor de Dependiente recorre únicamente la tabla de preguntas del Entrenador.

Para mejorar rendimiento, el worker:

1. obtiene un lote pequeño de preguntas;
2. busca sólo el último run de los IDs de ese lote;
3. evalúa `answered + pass_*`;
4. persiste únicamente los items útiles.

No se agrupa toda la tabla de runs en cada lote.

FAQ puede haber terminado mientras Dependiente sigue procesando.

## KPIs

### FAQ

- activas;
- procesadas;
- con categoría;
- sin categoría.

### Dependiente

- preguntas de Entrenador procesadas;
- evaluadas con buen resultado;
- útiles editorialmente;
- descartadas por ruido;
- con categoría;
- sin categoría.

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

## Interfaz editorial

Dentro de una propuesta se muestran dos bloques separados.

### FAQ

- pregunta;
- respuesta humana;
- categoría/producto;
- fecha;
- estado editorial.

### Dependiente

- pregunta;
- respuesta/evidencia;
- tipo;
- lección;
- validación;
- score;
- fecha;
- estado editorial.

Cada item puede quedar:

- pendiente;
- usar;
- descartar.

Estas decisiones no modifican las tablas fuente.

## Draft

El flujo obligatorio es:

~~~text
fuentes → dossier → propuesta → revisión → draft → edición humana → publicación humana
~~~

Solucionador nunca publica automáticamente.

## Export JSON

Schema:

`seo-solucionador-export-v6`

Por dossier:

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

Cada item expone claramente:

- origen;
- source_id;
- pregunta;
- respuesta/evidencia;
- hash;
- estado.

## Principio final

**Solucionador debe ser sencillo y eficiente.**

Para Dependiente no intenta reconstruir todo su conocimiento interno: lee las preguntas del Entrenador y utiliza únicamente aquellas cuyo último resultado Dependiente ha evaluado como bueno.
