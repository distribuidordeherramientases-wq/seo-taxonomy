# Servicios

Los **Servicios** son motores funcionales especializados. Cada uno observa o transforma un tipo de señal distinto. La regla de arquitectura es evitar duplicar análisis: cada dato tiene una fuente responsable y **Solucionador** es la capa que reúne las conclusiones para decidir actuaciones editoriales.

| Servicio | Función principal |
|---|---|
| [[Clonador]] | Reconstrucción controlada de STAGING a partir de PRO |
| [[Ojeador]] | Observa mercado y Google Shopping por categorías y productos |
| [[Dependiente]] | Asistente/filtro inteligente para resolver necesidades del cliente usando catálogo, semántica, ranking y conocimiento aprendido |
| [[Academia, Intérprete y Lingüista|Academia-Interprete-y-Linguista]] | Formación de Dependiente y comprensión del lenguaje natural |
| [[Ingeniero]] | Investiga y consolida conocimiento técnico externo por categoría, siempre con trazabilidad de fuentes |
| [[Clasificador]] | Propone clasificación semántica y atributos canónicos a partir del catálogo y del conocimiento disponible |
| [[Analista]] | Interpreta demanda, rendimiento y mercado: Google, Bing, GA4, tendencias y oportunidades |
| [[Comentarista]] | Aporta evidencia externa y experiencia observada |
| [[Auditor]] | Analiza calidad, coherencia, cobertura y estructura interna del catálogo y de los contenidos |
| [[Solucionador]] | Capa de decisión editorial: unifica conclusiones, inventaría cobertura y decide qué crear, ampliar, consolidar o no tocar |

Principio común: distinguir entre **observar**, **proponer**, **validar** y **modificar**.

La relación entre servicios está documentada en [[Flujo de datos|Flujo-de-datos]].
