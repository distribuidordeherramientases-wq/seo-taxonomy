# Dependiente

Dependiente es el sistema de búsqueda guiada y asistencia al cliente. Interpreta necesidades y utiliza catálogo, semántica, ranking y conocimiento aprendido para orientar al usuario hacia una solución y, cuando corresponde, hacia productos concretos.

## Aprendizaje continuo

Dependiente no tiene un estado funcional de **“aprendizaje terminado”**.

Academia y Entrenador pueden seguir ejecutando lecciones, preguntas y evaluaciones de manera continua.

Las tablas que Solucionador observa para este flujo son:

- `seo_dependiente_trainer_questions`
- `seo_dependiente_trainer_runs`

Una pregunta puede existir desde hace tiempo y recibir posteriormente un nuevo run o una nueva respuesta.

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
