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

## Relación con Solucionador

Dependiente es una de las fuentes de conocimiento más importantes para Solucionador.

Las preguntas, necesidades, problemas y conocimiento que Dependiente puede resolver ayudan a detectar contenidos públicos útiles: guías, soluciones, comparativas o explicaciones que todavía no existen.

El flujo es:

**Academia y servicios de conocimiento → Dependiente → Solucionador → contenido público**

y después:

**uso del contenido + nuevas consultas → Analista/Auditor/Dependiente → nueva decisión de Solucionador**.
