# Solucionador

**Solucionador es la capa editorial que convierte el conocimiento disponible en propuestas revisables por Editora.**

Solucionador **no aprende** y no debe esperar a que Academia, Entrenador o Dependiente terminen un proceso global. Dependiente está en aprendizaje continuo, por lo que Solucionador trabaja siempre con una **foto del conocimiento disponible en el momento de cada ejecución**.

## Fuentes editoriales

Solucionador tiene exactamente dos fuentes editoriales de contenido:

1. **FAQs**
   - tabla canónica: `seo_faq`;
   - pregunta y respuesta humanas;
   - fuente independiente y cerrada;
   - una FAQ activa y asociada de forma demostrable a una `product_cat` puede entrar directamente en un dossier.

2. **Dependiente**
   - acceso mediante `SEO_Dependiente_Editorial_Knowledge`;
   - preguntas/respuestas que Entrenador ya ha validado;
   - último run `answered` con `evaluation_status=pass_*`;
   - conocimiento semántico consolidado/aprendido cuando sea editorialmente utilizable.

Ingeniero, Comparador, Ojeador, Marketing, Analista y Auditor pueden aportar contexto a otros procesos, pero **no son fuentes obligatorias para que Solucionador genere un dossier o una propuesta**.

## Principio de independencia

Los dos carriles se procesan de forma independiente.

~~~text
seo_faq ────────────────┐
                        ├──→ dossier product_cat ──→ propuesta
Dependiente/Entrenador ─┘
~~~

No existe una condición del tipo:

~~~text
"esperar a que termine Entrenador"
"esperar a que Dependiente termine de aprender"
"esperar a completar todas las FAQs"
~~~

Ese estado final no forma parte del contrato de Solucionador.

Cada vez que se ejecuta el proceso, el worker, un rescan o una actualización programada:

- revisa las FAQs disponibles;
- revisa el conocimiento disponible de Dependiente/Entrenador;
- incorpora las novedades al dossier correspondiente;
- identifica las categorías modificadas;
- genera o actualiza inmediatamente la propuesta de esas categorías;
- continúa el inventario en segundo plano sin bloquear la revisión editorial.

Por tanto, puede existir al mismo tiempo:

~~~text
FAQ: inventario disponible
Dependiente: aprendizaje en curso
Entrenador: nuevas lecciones ejecutándose
Solucionador: propuestas ya disponibles para Editora
~~~

## Unidad editorial

La unidad de trabajo es **un dossier por `product_cat`**.

Una misma categoría puede contener simultáneamente:

- FAQs;
- preguntas/respuestas validadas de Dependiente;
- ambos orígenes.

Las fuentes no se mezclan ni se destruyen. Cada item conserva su origen y trazabilidad.

Identidades estables:

- `faq:123`
- `dependiente:trainer:456`
- `dependiente:semantic:789`

## Propuesta incremental

Desde el issue **#705**, cada lote de escaneo devuelve las categorías cuyo dossier ha cambiado.

Solucionador materializa o actualiza el topic de esas categorías **en el mismo ciclo**, sin esperar a que el inventario completo de Entrenador termine.

Flujo:

~~~text
novedad en FAQ o Dependiente
        ↓
actualización del dossier
        ↓
changed_category_ids
        ↓
prepare_category_topic()
        ↓
propuesta actualizada
        ↓
Editora puede revisarla
~~~

El inventario completo puede seguir avanzando después.

## Qué conocimiento de Dependiente entra

No todo lo que ejecuta Entrenador se convierte en material editorial.

Entra únicamente conocimiento que Dependiente ya considera válido, por ejemplo:

- run `answered`;
- `evaluation_status=pass_*`;
- categoría demostrable;
- pregunta con valor editorial.

Se descarta ruido de entrenamiento como preguntas puramente identificativas o de inventario de catálogo.

## Cambios posteriores

Cada dossier mantiene hashes de sus items y del conjunto de fuentes.

Una novedad puede producir:

- **NEW**
- **MODIFIED**
- **RETIRED**
- **UNCHANGED**

Cuando el conocimiento cambia, la propuesta se vuelve a analizar. Si existe un post asociado, Solucionador puede marcarlo como **NEEDS_UPDATE**, pero **no sobrescribe el contenido público**.

## Decisiones editoriales

Solucionador puede recomendar:

- `CREATE_POST`
- `IMPROVE_POST`
- `NO_ACTION`
- `DEFER`

La recomendación no publica nada automáticamente.

El flujo final sigue siendo:

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

## Regla operativa

**Solucionador trabaja con lo que existe ahora, no con lo que Dependiente pueda llegar a saber al final.**

Dependiente puede seguir aprendiendo indefinidamente. Cada nueva ejecución de Solucionador debe poder incorporar el conocimiento que ya esté validado en ese momento y convertirlo en una propuesta útil sin esperar a ningún cierre global.
