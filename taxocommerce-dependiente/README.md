# TaxoCommerce Dependiente 0.3.0

Plugin independiente de la familia TaxoCommerce. El objetivo de esta versión es mantener la arquitectura auditable del Dependiente moderno y recuperar, como mínimo, las capacidades útiles que daban buen resultado en el conjunto Dependiente + Intérprete anterior.

No se presentan servicios de formación separados. Toda la lógica pertenece a un único Dependiente y se organiza como **L1-L10 + M11 Intérprete + M12 Actualización continua**.

## Flujo

### L1-L10 · Aprendizaje

Cada lección conserva una **foto fija auditable** de las fuentes que va a estudiar. El proceso es:

1. Construir y sellar la foto fija.
2. Aprender exclusivamente de esa foto.
3. Generar conocimiento semántico cuando corresponde.
4. Evaluar una muestra mediante Top 1, Top 3, Top 8 y `pass_any`.
5. Aplicar un quality gate.
6. Promocionar reglas y memoria al runtime solo cuando la lección supera el gate.

El catálogo original nunca se modifica durante el aprendizaje.

### M11 · Intérprete

Después de L10, Dependiente construye y valida su Intérprete. El runtime combina:

- reglas semánticas consolidadas;
- Vocabulary canónico;
- rutas semánticas;
- memoria L9 ponderada por producto;
- catálogo WooCommerce publicado;
- búsqueda textual únicamente como red de seguridad.

El módulo ejecuta regresión sobre necesidades construidas de forma independiente a la memoria L9: la frase de examen no se guarda como señal y no incluye el título exacto del producto. Si no alcanza el umbral, el Intérprete queda degradado y el proceso no se considera listo.

Las reglas y señales importadas desde el Dependiente antiguo también forman parte del runtime; no se guardan como un archivo muerto de compatibilidad.

### M12 · Actualización continua

Una vez completado M11, el conocimiento se mantiene incrementalmente sin repetir L1-L10.

Se actualizan:

- productos nuevos o modificados;
- cambios de estado de productos;
- categorías nuevas o editadas;
- posts y páginas publicados/modificados;
- FAQs activas modificadas;
- Vocabulary y asignaciones semánticas que afecten al catálogo.

Los hooks de WordPress/WooCommerce realizan actualizaciones inmediatas y una revisión periódica incremental funciona como red de seguridad. Cuando M12 cambia conocimiento, vuelve a ejecutar la regresión de M11: el Intérprete se crea una vez, pero se revalida y actualiza durante toda la vida del catálogo.

## Las diez lecciones

- **L1 Mapa del catálogo**: categorías, jerarquía, nombres y Vocabulary de categoría.
- **L2 Inventario y producto**: identidad, SKU, marca, categorías y clasificación semántica.
- **L3 TIPO y ROL**: conceptos canónicos y asociaciones con productos.
- **L4 Características y necesidades**: aplicaciones, plataformas, subtipos, tags y atributos; consolida conceptos semánticos globales sin duplicar una regla por producto.
- **L5 Posts y páginas**: contenido editorial y Vocabulary compartido.
- **L6 FAQs contextualizadas**: pregunta, respuesta, propietario y Vocabulary.
- **L7 Relaciones cruzadas**: conceptos que conectan catálogo y contenido.
- **L8 Examen integrado y regresión**: revisión conjunta de L1-L7 mediante una muestra determinista y acotada por lección, sin volver a duplicar todo el conocimiento.
- **L9 Necesidades y productos**: memoria lingüística ponderada por producto.
- **L10 Consolidación, deuda y regresión**: revisión global L1-L9 con el mismo muestreo reproducible y control de deuda.

## Memoria L9

L9 no guarda una única frase por producto. Construye múltiples señales ponderadas a partir de datos reales:

- título;
- TIPO;
- aplicación;
- subtipo;
- plataforma;
- ROL;
- categoría;
- marca;
- tags;
- atributos;
- frases útiles de descripción.

Las señales genéricas aisladas no deben bastar para recomendar un producto. El ranking exige evidencia suficiente o una frase fuerte. La necesidad usada para evaluar L9/M11 se construye aparte con categoría, semántica y atributos y no se memoriza como atajo.

## Evaluación y quality gates

Dependiente conserva métricas equivalentes a las útiles del sistema anterior:

- `pass_top1`;
- `pass_top3`;
- `pass_top8`;
- `pass_any_ratio`;
- fallos;
- umbral mínimo por lección.

El conocimiento generado por una lección se mantiene inactivo hasta superar el quality gate.

## Compatibilidad con el conocimiento antiguo

El importador acepta `seo_dependiente_knowledge v1`.

- `semantic_rules` → `tcd_portable_rules`.
- `academy.lesson9_memory` → `tcd_l9_memory`.
- las lecciones antiguas completadas se conservan como evidencia histórica.

Las lecciones históricas no sustituyen las fotos fijas modernas. Sin embargo, las reglas y la memoria L9 importadas sí pueden ser consumidas inmediatamente por M11/runtime.

## Datos propios

- `tcd_knowledge`: conocimiento moderno L1-L10.
- `tcd_lesson_snapshots`: fotos fijas.
- `tcd_lesson_items`: material exacto estudiado.
- `tcd_quality_checks`: evaluación y quality gates.
- `tcd_portable_rules`: reglas semánticas modernas e importadas.
- `tcd_l9_memory`: memoria lingüística/producto moderna e importada.
- `tcd_legacy_lessons`: evidencia histórica del sistema anterior.

## Continuidad de runs existentes

Al actualizar una instalación que ya estaba aprendiendo con 0.2.x no es obligatorio reiniciar. Antes de continuar con una lección posterior, 0.3 reconstruye y evalúa progresivamente la inteligencia que falte en las lecciones anteriores ya aprendidas.

## Rendimiento

El aprendizaje mantiene el gestor por lotes, prioridad 1-5, snapshots y adaptación de carga. La memoria semántica se separa en:

- reglas generales para conceptos/rutas;
- señales L9 específicas de producto.

Esto evita duplicar miles de reglas generales por cada producto. El runtime añade además una decisión de confianza: si solo hay coincidencia textual débil o una tarea ambigua sin evidencia semántica suficiente, no muestra productos como si la interpretación fuera segura; devuelve familias candidatas para concretar.

## Exportación

Se mantienen dos exports distintos:

- **Aprendizaje completo · JSON**: auditoría del run y sus fotos fijas.
- **Conocimientos · JSON**: paquete portable para copia, migración o análisis externo.

La exportación se transmite por lotes para evitar construir documentos enormes en memoria.


## Log de aprendizaje

Desde 0.3.1 Dependiente dispone de una pestaña **Log de aprendizaje** orientada a diagnóstico operativo.

El log registra, entre otros:

- entrada y salida de cada tick;
- adquisición, espera y recuperación de locks;
- programación del siguiente tick y nudges al gestor;
- petición y resultado de cada lote de fuentes;
- fuente concreta leída y resumen de sus hechos/Vocabulary;
- escritura de cada fila en la foto fija;
- lectura, validación y escritura de cada conocimiento;
- llamada al motor semántico;
- progreso de cada lote;
- errores de base de datos, excepciones y fatales PHP.

El fichero activo se guarda en una carpeta privada bajo uploads, protegida frente a acceso web directo, y rota conservando únicamente la parte reciente. **Nunca debe superar 1 MiB**. La pestaña permite visualizarlo, autoactualizarlo, descargarlo y vaciarlo sin modificar el aprendizaje.

Durante la fase `learning` la barra de progreso usa el tamaño de la **foto fija sellada**, no el tamaño actual del catálogo. Si el catálogo cambia después del sellado, ambos tamaños se muestran por separado para evitar interpretar como bloqueo una foto fija que, por diseño, ya no debe crecer.


## Feedback real de clientes

Desde 0.3.3 Dependiente incorpora dos vías de feedback anónimo que alimentan M11/M12 sin permitir que una interacción aislada se convierta directamente en conocimiento canónico.

### Feedback implícito: clic en producto

Cuando Dependiente muestra productos y el cliente pulsa uno:

1. se registra la consulta normalizada;
2. se registra el producto pulsado;
3. se guarda únicamente un hash de una sesión aleatoria del navegador;
4. no se almacena IP ni identidad personal;
5. clics repetidos de la misma sesión sobre la misma consulta/producto no suman evidencia adicional;
6. la asociación queda inicialmente en `staged`;
7. solo se activa cuando distintas sesiones repiten de forma suficientemente dominante la misma relación.

Umbral base: al menos 4 sesiones y al menos 65 % de la evidencia de clics para esa consulta.

Las asociaciones de producto activas aportan una señal adicional de ranking, nunca sustituyen por sí solas a Vocabulary/L9.

### Feedback explícito: aclaraciones cerradas

Si M11 determina que la consulta es ambigua:

1. Dependiente analiza los productos candidatos;
2. selecciona un eje semántico útil —preferentemente aplicación, TIPO, subtipo, plataforma o ROL—;
3. muestra entre 3 y 5 opciones cerradas y una alternativa `Otro`;
4. la opción elegida se aplica inmediatamente como hint de la consulta;
5. Dependiente vuelve a interpretar y buscar;
6. puede hacer como máximo dos aclaraciones;
7. la elección queda como evidencia semántica para consultas futuras.

Las aclaraciones pesan más que los clics, pero una sola respuesta tampoco activa conocimiento. El umbral base exige al menos 2 sesiones coherentes y 60 % de la evidencia explícita.

Si no existen opciones semánticas suficientes, Dependiente puede preguntar por una familia de producto. Las aclaraciones de texto libre mediante `Otro` se conservan para análisis, pero no se promueven automáticamente como regla.

### Consolidación M12

M12 revisa periódicamente las asociaciones `staged` y `validated`, recalcula su evidencia y activa únicamente patrones repetidos. Antes de que M11 exista, la evidencia puede acumularse pero no se promociona a `active`.

Estados:

- `staged`: evidencia insuficiente;
- `validated`: existe repetición, pero no alcanza el umbral de activación;
- `active`: evidencia repetida y dominante; puede influir en M11/runtime.

Cuando una asociación llega a `active`, se materializa en los almacenes portables: consulta→producto como señal L9 y consulta→concepto como regla semántica. Por tanto, el export de conocimiento conserva el aprendizaje consolidado sin exportar eventos, sesiones ni clics individuales.

Los eventos de feedback no activos se conservan durante 180 días y se limpian después. El administrador muestra búsquedas, clics, aclaraciones y asociaciones activas. El log de aprendizaje registra también `feedback_event` y `feedback_promoted`.

## Auditoría de calidad y corrección de datos

Desde 0.3.4 un quality gate fallido no se limita a mostrar un porcentaje. Dependiente conserva un diagnóstico accionable.

Cuando una lección no alcanza su umbral:

- distingue entre **dato fuente**, **ambigüedad real** y **fallo interno de Dependiente/ranking**;
- conserva todos los casos que quedaron fuera del Top-8;
- indica consulta de examen, posición, resultados observados, causa probable y acción recomendada;
- audita además la lección completa para localizar anomalías de datos aunque el elemento haya aprobado el examen;
- incluye elementos `unlearned` y su motivo.

El botón **JSON · auditoría y corrección** descarga un documento estructurado con:

- resumen del quality gate;
- `quality_failures`;
- `correction_queue`: elementos que sí deben revisarse en WordPress;
- `dependiente_internal_queue`: casos que no deben corregirse alterando el contenido, porque el problema es memoria/ranking;
- `ambiguity_queue`;
- `data_audit`: anomalías detectadas en toda la lección;
- recomendaciones y política de corrección.

Los CSV son vistas tabulares del mismo diagnóstico: uno para fallos del examen y otro para deuda de datos.

### Ciclo de corrección

La foto fija original se conserva como evidencia de lo que Dependiente estudió. Después de corregir un producto, categoría, post/página, FAQ o Vocabulary en WordPress, el botón **Revisar correcciones y reevaluar**:

1. vuelve a leer únicamente los casos que fallaron;
2. actualiza su conocimiento con los datos actuales;
3. regenera las señales de esos casos;
4. repite el quality gate;
5. continúa con la siguiente lección únicamente cuando se supera el mínimo.

No reinicia L1-L10 ni vuelve a procesar todo el catálogo.

Dependiente actúa así también como mecanismo de auditoría: aprender y aprobar no son la misma cosa, y un elemento puede ser recuperable por el buscador pero seguir teniendo deuda de datos (por ejemplo SKU inválido, Vocabulary incompleto o FAQ sin contexto).
