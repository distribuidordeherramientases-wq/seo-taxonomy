# Solucionador

**Solucionador convierte el conocimiento disponible en propuestas editoriales para Editora.**

No aprende, no espera a que Academia termine y no necesita que Dependiente alcance un supuesto estado final. Dependiente y Entrenador trabajan de forma continua, por lo que Solucionador procesa una **foto del conocimiento disponible en cada ejecución**.

## Las dos fuentes editoriales

Solucionador trabaja con dos fuentes independientes.

### 1. FAQs

Fuente canónica:

`seo_faq`

Contiene preguntas y respuestas humanas ya almacenadas.

Una FAQ activa puede incorporarse directamente cuando tiene una relación demostrable con una `product_cat`.

No necesita pasar por Entrenador ni ser aprendida por Dependiente.

### 2. Dependiente / Entrenador

Fuentes técnicas:

- `seo_dependiente_trainer_questions`
- `seo_dependiente_trainer_runs`

Solucionador revisa las preguntas de Entrenador y toma únicamente conocimiento que Dependiente ya ha validado.

Contrato mínimo actual:

- pregunta activa;
- último run disponible;
- `status=answered`;
- `evaluation_status=pass_*`;
- asociación demostrable a `product_cat`;
- valor editorial suficiente.

Las preguntas puramente identificativas o de inventario de catálogo pueden descartarse como ruido de entrenamiento.

## Independencia de los procesos

El flujo no es:

~~~text
Entrenador termina
      ↓
Dependiente termina
      ↓
Solucionador empieza
~~~

Ese final no existe.

El flujo correcto es:

~~~text
seo_faq ───────────────────────────┐
                                  ├──→ dossier product_cat ──→ propuesta
Entrenador / Dependiente ─────────┘
          │
          └── sigue aprendiendo después
~~~

En cualquier momento puede ocurrir:

~~~text
Entrenador: lecciones en curso
Dependiente: sigue aprendiendo
FAQs: disponibles
Solucionador: propuestas ya revisables
~~~

## Procesamiento incremental

Solucionador mantiene puntos de avance separados.

### Dependiente

- `dependiente_cursor`: última pregunta inventariada.
- `dependiente_run_cursor`: último run de Entrenador consumido.

El segundo cursor es importante porque una pregunta antigua puede recibir una **respuesta nueva** sin crear una pregunta nueva.

Cuando aparece un nuevo `run_id`, Solucionador puede actualizar el dossier correspondiente sin volver a vaciar ni recorrer desde cero todo el conocimiento de Dependiente.

Una firma nueva de Dependiente significa:

**hay novedades**

No significa:

**borra todo y empieza otra vez**

### FAQs

FAQ mantiene su propio `faq_cursor` y su propia firma.

Si cambia la tabla de FAQs, sólo se revisa el carril FAQ. Dependiente conserva su avance.

## Propuestas por lote

Cada lote de escaneo devuelve las categorías que realmente han cambiado mediante:

`changed_category_ids`

Solucionador llama en el mismo ciclo a:

`prepare_category_topic()`

Por tanto:

~~~text
nueva FAQ o nueva respuesta válida de Dependiente
                    ↓
             dossier actualizado
                    ↓
          changed_category_ids
                    ↓
        propuesta creada/actualizada
                    ↓
             revisión de Editora
~~~

No hay que esperar a completar todo el inventario.

## Dossier

La unidad editorial es **un dossier por `product_cat`**.

El mismo dossier puede contener material de:

- FAQ;
- Dependiente;
- ambas fuentes.

Cada item conserva su origen.

Ejemplos:

- `faq:123`
- `dependiente:456`

El `source_hash` permite saber si el conocimiento ha cambiado desde la última revisión editorial.

## Decisión editorial

Solucionador puede recomendar, entre otras:

- `CREATE_POST`
- `IMPROVE_POST`
- `NO_ACTION`
- `DEFER`

La propuesta no publica automáticamente.

El flujo público sigue siendo:

~~~text
FUENTES
  ↓
DOSSIER
  ↓
PROPUESTA
  ↓
EDITORA
  ↓
DRAFT
  ↓
EDICIÓN HUMANA
  ↓
PUBLICACIÓN
~~~

## Regla operativa

**Solucionador trabaja con lo que Dependiente sabe ahora y con las FAQs que existen ahora.**

En el siguiente worker, ejecución o rescan vuelve a comprobar novedades y actualiza sólo lo que corresponda. Nunca debe bloquearse esperando a que Dependiente deje de aprender.
