# Auditor

Sistema de **control de calidad, cobertura y coherencia interna**.

Su objetivo es observar y medir la estructura nuclear del sistema, no decidir por sí solo qué contenido público debe crearse.

Analiza, entre otros:

- productos y categorías;
- Vocabulary y atributos;
- relaciones de arquitectura;
- cobertura de posts, páginas y landings;
- índices derivados;
- aprendizaje de Academia/Dependiente cuando corresponde;
- incoherencias, huecos y anomalías.

Los hallazgos se clasifican por severidad y contexto y se convierten en **informes de diagnóstico**.

Auditor no debe duplicar el trabajo de Analista ni de Solucionador:

- **Auditor** responde principalmente a “¿qué tenemos, cómo está estructurado y qué falta o no cuadra internamente?”.
- **Analista** responde a “¿qué está funcionando, qué demanda existe y dónde hay oportunidad?”.
- **Solucionador** cruza ambos y decide si procede crear, ampliar, consolidar o no actuar sobre contenido.

Auditor no corrige silenciosamente ni publica contenido.

## Contexto de decisión

Auditor puede enriquecer sus hallazgos con snapshots ya persistidos de **Analista, Ojeador, Dependiente, Comparador y proveedores**. Esta capa sirve para entender mejor la importancia y el contexto de una incidencia interna sin duplicar el trabajo de esos servicios.

Reglas:

- no llama APIs externas durante una auditoría;
- distingue entre dato real `0`, dato ausente `null` y snapshot `stale`;
- conserva las prioridades P1–P5 basadas en el tipo de hallazgo;
- una prioridad calculada por Analista sólo puede ordenar tareas dentro de la misma P;
- Solucionador mantiene la decisión editorial final.

El JSON de Auditor expone esta capa como `contexto_de_decision`.

