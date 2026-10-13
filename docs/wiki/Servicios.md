# Servicios

Los **Servicios** son motores funcionales especializados. Cada uno observa o transforma un tipo de señal distinto. La regla de arquitectura es evitar duplicar análisis: cada dato tiene una fuente responsable y **Solucionador** es la capa que reúne las conclusiones para decidir actuaciones editoriales.

| Servicio | Función principal |
|---|---|
| [[Clonador]] | Reconstrucción controlada de STAGING a partir de PRO |
| [[Ojeador]] | Observa mercado y Google Merchant/Shopping por categorías y productos |
| [[Comparador]] | Construye perfiles y comparativas usando catálogo propio, snapshots de Ojeador y señales ya calculadas por Analista |
| [[Dependiente]] | Asistente/filtro inteligente para resolver necesidades del cliente usando catálogo, semántica, ranking y conocimiento aprendido |
| [[Academia, Intérprete y Lingüista|Academia-Interprete-y-Linguista]] | Formación de Dependiente y comprensión del lenguaje natural |
| [[Ingeniero]] | Investiga y consolida conocimiento externo L1 técnico, L2 práctico/foros y L3 actualidad, siempre con trazabilidad de fuentes |
| [[Clasificador]] | Propone clasificación semántica y atributos canónicos a partir del catálogo y del conocimiento disponible; solo aplica tras revisión humana |
| [[Analista]] | Interpreta demanda, rendimiento y mercado: Search Console, Analytics, Bing, tendencias, competencia y oportunidades |
| [[Comentarista]] | Aporta evidencia externa y experiencia observada |
| [[Auditor]] | Analiza calidad, coherencia, cobertura y estructura interna del catálogo y de los contenidos; puede contextualizar con snapshots persistidos de otros servicios |
| [[Solucionador]] | Capa de decisión editorial: unifica conclusiones, inventaría cobertura y decide qué crear, ampliar, consolidar o no tocar |

Principio común: distinguir entre **observar**, **proponer**, **validar**, **aplicar** y **publicar**.

La relación entre servicios está documentada en [[Flujo de datos|Flujo-de-datos]].

El inventario de persistencia está documentado en [[Servicios y tablas|Servicios-y-tablas]], donde se identifica qué tablas son canónicas, derivadas, de workflow o de runtime y qué servicio las utiliza.
