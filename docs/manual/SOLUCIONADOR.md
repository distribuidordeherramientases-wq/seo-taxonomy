# Solucionador

## Estado operativo

- **Versión funcional:** 0.7.1.
- **Versión de esquema:** 0.7.1.
- **Arquitectura de referencia:** 04/10/2026.
- **Issue:** #698.
- **Validación:** staging antes de producción.

## Objetivo

Solucionador no aprende ni investiga. Hace cuatro cosas:

1. lee material útil ya existente;
2. lo agrupa por `product_cat`;
3. prepara una propuesta para Editora;
4. crea sólo un borrador cuando Editora lo decide.

~~~text
FAQ activas ───────────────┐
                           ├──→ dossier product_cat → propuesta → Editora → draft
Entrenador de Dependiente ─┘
último run answered + pass_*
~~~

No necesita Ingeniero, Comparador, Ojeador, Marketing ni Analista para generar dossiers.

## Fuente 1 — FAQ

Tabla:

`{$wpdb->prefix}seo_faq`

Una FAQ entra cuando:

- está activa;
- tiene pregunta y respuesta;
- puede asociarse de forma demostrable a una `product_cat`.

La pregunta y respuesta son contenido humano original.

No necesita pasar por Dependiente, tener `pass_top*` ni alcanzar una masa mínima.

## Fuente 2 — Dependiente

Solucionador lee directamente:

- `seo_dependiente_trainer_questions`;
- `seo_dependiente_trainer_runs`.

No necesita leer reglas semánticas, search logs, candidatos, estados transitorios ni otras fuentes internas.

Para cada pregunta activa del Entrenador:

1. obtiene su **último run**;
2. exige `status=answered`;
3. exige `evaluation_status=pass_*`;
4. sólo entonces la considera conocimiento válido de Dependiente.

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

Si el último resultado no es bueno, Solucionador no utiliza esa pregunta.

## Filtro de ruido

Después de comprobar que Dependiente la evaluó correctamente, Solucionador puede excluir como candidato editorial preguntas puramente mecánicas, por ejemplo:

- “¿Qué es esta categoría?”
- “¿Qué productos contiene esta categoría?”
- “Lista los productos de…”

La pregunta no se borra del Entrenador.

## FAQ y Dependiente no se deduplican

Si la misma pregunta existe en FAQ y en Entrenador, se conservan las dos.

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
- asociación guardada por Entrenador.

No se inventa categoría por similitud textual.

Si no puede demostrarse la categoría, el elemento queda sin categoría y no genera propuesta.

## Dossier

Existe un único dossier por `category_id`.

~~~text
Abrazaderas
├─ FAQ 123
├─ FAQ 456
├─ Dependiente 115078
└─ Dependiente 126929
~~~

No existe una tercera fuente mixta.

## Masa mínima

No hay bloqueo por número mínimo de preguntas.

Una sola FAQ útil o una sola pregunta buena de Dependiente puede ser suficiente para que Editora vea el dossier.

La cantidad de material sólo sirve como indicador.

## Recomendación editorial

Solucionador puede recomendar:

- `CREATE_POST`;
- `IMPROVE_POST`;
- `NO_ACTION`;
- `DEFER` cuando no existe una categoría demostrable.

Cobertura, duplicación y canibalización son indicadores, no permisos para ocultar el dossier.

## Cambios en las fuentes

Cada item tiene un hash.

El dossier calcula un `source_hash` estable con los hashes de FAQ y Dependiente.

Si cambia una FAQ o cambia el último run válido de una pregunta:

`source_hash != reviewed_hash → NEEDS_UPDATE`

Se distinguen:

- NUEVO;
- MODIFICADO;
- RETIRADO.

Nunca se sobrescribe automáticamente un post.

## Posts publicados

~~~text
post publicado + conocimiento nuevo = NEEDS_UPDATE
~~~

El contenido público sigue estable hasta que Editora lo revise.

## Procesamiento por lotes

Hay dos cursores independientes:

- `faq_cursor`;
- `dependiente_cursor`.

El cursor de Dependiente recorre únicamente las preguntas del Entrenador.

Para ser eficiente, cada lote:

1. lee un pequeño grupo de preguntas;
2. consulta sólo el último run de esos IDs;
3. comprueba `answered + pass_*`;
4. persiste sólo el material útil.

No se agrupa toda la tabla de runs en cada ciclo.

FAQ puede terminar mientras Dependiente continúa.

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

La propuesta muestra dos bloques.

### FAQ

- pregunta;
- respuesta humana;
- producto/categoría;
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

Cada item puede quedar pendiente, usar o descartar sin modificar la fuente original.

## Draft

Flujo obligatorio:

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

## Principio final

**Solucionador debe ser sencillo y eficiente.**

Para Dependiente no intenta reconstruir todo su conocimiento interno: lee las preguntas del Entrenador y utiliza únicamente aquellas cuyo último resultado Dependiente ha evaluado como bueno.
