# Dependiente

Dependiente es el sistema de búsqueda guiada y asistencia al cliente. Interpreta necesidades y utiliza catálogo, semántica, ranking y conocimiento aprendido para orientar al usuario hacia una solución y, cuando corresponde, hacia productos concretos.

## Aprendizaje continuo

Dependiente no tiene un estado funcional de **“aprendizaje terminado”**.

Academia y Entrenador pueden seguir ejecutando lecciones, preguntas y evaluaciones de manera continua.

Las tablas que Solucionador observa para este flujo son:

- `seo_dependiente_trainer_questions`
- `seo_dependiente_trainer_runs`

Una pregunta puede existir desde hace tiempo y recibir posteriormente un nuevo run o una nueva respuesta.


## Informe de errores de la Actualización continua

La **Lección continua · Actualización** exporta un JSON reconstruido desde las tablas reales de preguntas y ejecuciones de Academia. Desde la versión de informe 1.0.3 / schema 2, el documento no se limita a los contadores `passed/failed`: añade `learning_diagnostics`.

El bloque contiene:

- resumen de fallos reales por módulo;
- separación entre **fallos de aprendizaje** y **errores técnicos**;
- agrupación por `diagnostic_type`, `source_type` y `question_type`;
- pregunta concreta que no se superó;
- verdad esperada (`expected`);
- evaluación y score;
- primeros resultados realmente devueltos por Dependiente;
- diagnóstico semántico/de búsqueda;
- fuente y entidad asociada;
- contexto actual de categoría o producto;
- `training_review`, que indica qué conviene revisar.

`training_review` es deliberadamente una **hipótesis de revisión**, no una causa demostrada. El informe distingue por tanto entre el hecho real —la comprobación falló— y la interpretación posterior sobre qué parte de la formación puede necesitar mejora.

En **M1 · Mapa y jerarquía**, las categorías actuales se consideran material formativo de referencia. El informe incluye nombre, jerarquía, número de productos, presencia/longitud de descripción y un extracto de la descripción, pero no propone reescribir automáticamente la categoría. Si esa descripción ya enseña correctamente el concepto, la siguiente revisión debe centrarse en rutas, Vocabulary y contexto de Hub secundario → Hub primario → Cluster.

El informe se calcula al descargarlo y no vuelve a ejecutar preguntas. Por ello también puede analizar un run que ya estaba en curso antes de instalar esta mejora.

## Relación con Solucionador

Solucionador consume únicamente el conocimiento que Dependiente ya ha validado en el momento de la ejecución.

Para el carril Entrenador/Dependiente se exige, como base:

- pregunta activa;
- último run;
- `status=answered`;
- `evaluation_status=pass_*`;
- relación demostrable con una `product_cat`;
- utilidad editorial.

Solucionador mantiene dos puntos de avance:

- cursor de preguntas;
- cursor de runs.

Así puede detectar respuestas nuevas para preguntas antiguas sin reiniciar todo el histórico.

El flujo es:

~~~text
Academia / Entrenador
        ↓
Dependiente aprende y valida
        ↓
Solucionador consulta lo disponible ahora
        ↓
dossier por product_cat
        ↓
propuesta para Editora
~~~

Mientras una propuesta ya está disponible, Dependiente puede seguir aprendiendo nuevas lecciones.

## FAQs

Las FAQs no pertenecen a este carril.

`seo_faq` es una fuente editorial independiente de Solucionador. Una FAQ no necesita ser aprendida por Dependiente para poder formar parte de una propuesta.
