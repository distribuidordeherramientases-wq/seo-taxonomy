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


## Auditor 0.14.0 · especialista interno y propietario de intención

Auditor trabaja **desde dentro hacia dentro**: parte del inventario, arquitectura, relaciones, Vocabulary y contenido ya existente. Su trabajo no es descubrir demanda exterior ni inventar nuevas URLs, sino determinar si lo existente está bien clasificado, suficientemente diferenciado y correctamente conectado.

Antes de recomendar una mejora editorial construye un mapa ligero de cobertura con:

- clusters;
- hubs primarios;
- hubs secundarios;
- categorías de producto;
- landings;
- posts;
- páginas editoriales;
- roles, relaciones y Vocabulary.

Las páginas funcionales y corporativas quedan fuera del análisis editorial. Pueden seguir teniendo controles técnicos propios, pero no generan tareas de longitud, Vocabulary o deriva semántica para Editora.

### Propietario de la intención

Auditor utiliza estas reglas para evitar canibalización:

- **Cluster / Hub primario / Hub secundario**: propietario de la intención jerárquica y estructural.
- **Categoría**: propietaria de la intención comercial de una familia real de productos.
- **Landing**: necesidad amplia o transversal que cruza varias familias sin repetir la taxonomía.
- **Post**: pregunta, problema, comparación, guía, explicación o actualidad concreta.

Auditor compara post ↔ cluster/hub, post ↔ landing, post ↔ categoría, landing ↔ cluster/hub, landing ↔ post, landing ↔ categoría y landing ↔ landing.

Cuando existe solapamiento propone **diferenciar o consolidar** antes de invertir trabajo en enriquecer la URL equivocada. Una coincidencia fuerte puede desembocar en una recomendación de conservar la entidad propietaria, integrar contenido útil y retirar/redirigir el duplicado, siempre con revisión humana.

### Dividir, concentrar y concretar categorías

Auditor también analiza si una categoría está demasiado mezclada o demasiado fragmentada:

- categorías con más de 10 productos entran en revisión de posible división;
- solo propone una división ejecutable cuando encuentra cohortes semánticas TIPO/SUBTIPO suficientemente diferenciadas;
- los productos ambiguos o no asignados permanecen en la categoría origen;
- categorías con 1–4 productos entran en revisión de posible concentración;
- solo propone una concentración cuando encuentra una categoría hermana suficientemente parecida;
- el Vocabulary forma parte de la evidencia para distinguir familias y evitar mezclar conceptos diferentes.

La propuesta y la ejecución están separadas. Auditor puede diagnosticar y preparar una propuesta; cualquier división o concentración debe aprobarse explícitamente desde la interfaz.

### Landings

Una landing no se considera una página genérica. Auditor revisa si:

- duplica un cluster, hub o categoría;
- compite con un post;
- está limitada a una sola categoría y no aporta intención transversal;
- se solapa con otra landing y conviene fusionarlas;
- reúne grupos estructurales suficientemente independientes y conviene estudiar su división.

Las propuestas de fusión/división son señales de revisión humana; Auditor no crea nuevas URLs por sí solo.

### Límite respecto a Analista

- **Auditor**: calidad, estructura, coherencia y canibalización interna de lo existente.
- **Analista**: demanda, rendimiento y mercado; decide si una señal debe reforzar una entidad existente o si existe un hueco real para proponer una nueva.

De este modo una propuesta nueva no nace únicamente porque falte contenido en una URL: primero se comprueba que no exista ya una categoría, hub, landing o post que deba acumular esa autoridad.
