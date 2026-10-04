# Dependiente

Sistema de búsqueda guiada y asistencia al cliente. Conceptualmente funciona como un **filtro inteligente**: interpreta una necesidad y utiliza catálogo, semántica, ranking y conocimiento aprendido para acercar al usuario a la solución adecuada y, cuando corresponde, a productos concretos.

## Función

Dependiente debe saber resolver preguntas de uso, elección, compatibilidad y aplicación práctica, no limitarse a encontrar productos.

Su conocimiento se forma y mantiene mediante Academia y se apoya en capas especializadas:

- **Intérprete / Lingüista**: cómo expresa el cliente su necesidad;
- **Ingeniero**: conocimiento técnico externo trazable;
- **Clasificador**: estructura semántica y atributos del catálogo;
- **Auditor Academia**: calidad del aprendizaje;
- catálogo y Vocabulary canónicos.

Las señales de Auditor, Analista u Ojeador no deben incorporarse indiscriminadamente como conocimiento de Dependiente. Pueden indicar qué conviene aprender o revisar, pero cada servicio mantiene su responsabilidad.

## Aprendizaje continuo

Dependiente **no tiene un estado editorial de “aprendizaje terminado”**.

Academia y Entrenador pueden seguir ejecutando lecciones, incorporando preguntas y validando respuestas de manera continua.

Por ello, otros servicios no deben bloquearse esperando a que Dependiente termine de aprender.

## Relación con Solucionador

Dependiente es una de las dos fuentes editoriales directas de Solucionador; la otra es la tabla canónica de FAQs.

Solucionador no espera al final de Academia ni al final de Entrenador. Cuando se ejecuta, consume el conocimiento que Dependiente **ya tiene validado en ese momento**.

Para preguntas de Entrenador, el contrato editorial exige conocimiento ya resuelto y validado, por ejemplo:

- último run `answered`;
- `evaluation_status=pass_*`;
- respuesta/evidencia disponible;
- asociación demostrable con `product_cat`;
- valor editorial suficiente.

El flujo correcto es:

~~~text
Academia / Entrenador
        ↓
Dependiente aprende y valida
        ↓
Solucionador consulta la foto disponible
        ↓
dossier por product_cat
        ↓
propuesta para Editora
~~~

Mientras Solucionador ya puede tener propuestas disponibles, Dependiente puede continuar aprendiendo nuevas lecciones.

Cuando aparezcan nuevas preguntas/respuestas validadas, una nueva ejecución o rescan de Solucionador las incorpora sin exigir un cierre global del aprendizaje.
