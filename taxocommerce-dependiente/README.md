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
