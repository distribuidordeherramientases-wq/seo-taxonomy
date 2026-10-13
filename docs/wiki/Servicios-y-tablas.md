# Servicios y tablas

Esta página identifica las tablas principales del plugin y explica qué servicio las utiliza. El objetivo es poder reconocer rápidamente una tabla antes de tocarla, exportarla, clonarla o borrarla.

> **Prefijo:** los nombres se muestran sin asumir `wp_`. En cada instalación debe anteponerse el prefijo real de WordPress, por ejemplo `{prefix}seo_vocabulary`.

## Cómo leer el inventario

- **Canónica**: contiene una fuente de verdad aceptada del catálogo, arquitectura, Vocabulary o contenido.
- **Persistente derivada**: resultado calculado por un servicio que se conserva para reutilizarlo o auditarlo.
- **Workflow**: estado de propuestas, revisiones, relaciones editoriales o decisiones.
- **Runtime / regenerable**: índice, log, cola o ejercicio que puede reconstruirse y no debe tratarse como fuente canónica.
- **Opción / snapshot**: algunos servicios no necesitan una tabla propia y guardan estado agregado en `wp_options`.

## WordPress y WooCommerce: base del catálogo

| Tabla | Para qué sirve | Tipo |
|---|---|---|
| `{prefix}posts` | Productos, variaciones, páginas, entradas, adjuntos y otros tipos de contenido WordPress. | Canónica |
| `{prefix}postmeta` | Metadatos de productos, páginas, posts y adjuntos. | Canónica |
| `{prefix}terms` | Términos de taxonomías. | Canónica |
| `{prefix}term_taxonomy` | Define a qué taxonomía pertenece cada término, incluidas categorías de producto. | Canónica |
| `{prefix}term_relationships` | Relaciona objetos con términos/categorías. | Canónica |
| `{prefix}termmeta` | Metadatos de términos y categorías. | Canónica |
| `{prefix}comments` | Comentarios WordPress y evidencias asociadas cuando correspondan. | Canónica WordPress |
| `{prefix}commentmeta` | Metadatos de comentarios. | Canónica WordPress |
| `{prefix}options` | Configuración y snapshots agregados de servicios que no necesitan tabla propia. | Configuración / snapshot |

## Semántica, atributos y arquitectura canónica

| Tabla | Servicio/área | Para qué sirve | Tipo |
|---|---|---|---|
| `{prefix}seo_vocabulary` | Vocabulary | Diccionario controlado de términos semánticos. | Canónica |
| `{prefix}seo_type_role_map` | Vocabulary | Compatibilidad y relación entre TIPO y ROL. | Canónica |
| `{prefix}sql_atributos` | Atributos | Definiciones de atributos canónicos. | Canónica |
| `{prefix}sql_atributos_terminos` | Atributos | Valores/términos admitidos por los atributos. | Canónica |
| `{prefix}sql_atributos_aliases` | Atributos | Alias y equivalencias de atributos o valores. | Canónica |
| `{prefix}sql_product_atributos` | Atributos | Asignaciones normalizadas de atributos a productos. | Canónica |
| `{prefix}seo_object_vocabulary` | Vocabulary / Clasificador | Asignación de términos de Vocabulary a productos, categorías, posts u otros objetos. | Canónica |
| `{prefix}seo_nodes` | Arquitectura SEO | Nodos de la arquitectura SEO. | Canónica |
| `{prefix}seo_relations` | Arquitectura SEO | Relaciones entre nodos, hubs, categorías y otros objetos estructurales. | Canónica |
| `{prefix}seo_faq` | FAQ / Academia / Solucionador | Preguntas y respuestas persistidas. | Canónica editorial |

### Clasificador

Clasificador **no necesita una familia `seo_clasificador_*` propia como fuente final**. Genera propuestas y, solo después de revisión humana y aceptación, aplica cambios en las tablas canónicas de Vocabulary y atributos: principalmente `seo_vocabulary`, `seo_object_vocabulary`, `seo_type_role_map`, `sql_atributos`, `sql_atributos_terminos`, `sql_atributos_aliases` y `sql_product_atributos`.

## Dependiente, Academia e Intérprete

| Tabla | Servicio | Para qué sirve | Tipo | Regenerable |
|---|---|---|---|---|
| `{prefix}seo_dependiente_semantics` | Dependiente | Memoria semántica portable y conocimiento consolidado. | Persistente derivada | No tratar como runtime |
| `{prefix}seo_interprete_lexicon` | Intérprete | Léxico aprendido para traducir lenguaje natural a estructura semántica. | Persistente derivada | Parcialmente |
| `{prefix}seo_interprete_lexicon_evidence` | Intérprete | Evidencias que justifican entradas del léxico. | Persistente derivada | Parcialmente |
| `{prefix}seo_dependiente_trainer_lessons` | Academia | Lecciones/material de entrenamiento. | Persistente derivada | Sí, con coste |
| `{prefix}seo_dependiente_trainer_questions` | Academia | Preguntas utilizadas por Entrenador/Academia. | Persistente derivada | No borrar sin revisar |
| `{prefix}seo_dependiente_trainer_runs` | Academia | Ejecuciones y resultados de aprendizaje de las preguntas. | Histórico de proceso | Sí, pero pierde trazabilidad |
| `{prefix}seo_dependiente_l9_signals` | Dependiente / Intérprete | Señales aprendidas de la capa L9. | Persistente derivada | Sí, con reaprendizaje |
| `{prefix}seo_dependiente_index` | Dependiente | Índice operativo de búsqueda/ranking. | Runtime derivado | Sí |
| `{prefix}seo_dependiente_search_log` | Dependiente | Registro de consultas y búsquedas reales. | Runtime / señal de uso | No es canónica; conservar si se quiere histórico |
| `{prefix}seo_dependiente_l9_exercises` | Dependiente / L9 | Ejercicios derivados de aprendizaje. | Runtime derivado | Sí |

El Clonador copia el conocimiento portable, pero reconstruye o vacía las tablas de runtime cuando cambia el catálogo entre PRO y STAGING.

## Ingeniero

| Tabla | Para qué sirve | Tipo |
|---|---|---|
| `{prefix}seo_ingeniero_sources` | Fuentes externas investigadas: fabricantes, manuales, normativa, foros, noticias, etc. | Evidencia persistente |
| `{prefix}seo_ingeniero_knowledge` | Conocimiento extraído y estructurado por categoría/capa L1-L2-L3. | Persistente derivada |
| `{prefix}seo_ingeniero_editorial` | Dossiers o propuestas editoriales construidas a partir del conocimiento de Ingeniero. | Workflow editorial |

## Solucionador

| Tabla | Para qué sirve | Tipo |
|---|---|---|
| `{prefix}seo_solucionador_topics` | Temas/candidatos detectados, cobertura, acción recomendada, riesgos y estado. | Workflow |
| `{prefix}seo_solucionador_evidence` | Evidencias que sustentan cada tema o necesidad editorial. | Evidencia |
| `{prefix}seo_solucionador_post_topics` | Relación entre posts existentes y temas canónicos detectados. | Relación / cobertura |
| `{prefix}seo_solucionador_coverage` | Índice de cobertura editorial existente por entidad y tema. | Persistente derivada |
| `{prefix}seo_solucionador_workflow` | Transiciones y estado del flujo editorial. | Workflow |
| `{prefix}seo_solucionador_tracking` | Seguimiento de decisiones y evolución de propuestas. | Workflow / histórico |
| `{prefix}seo_solucionador_dossiers` | Dossier por categoría con preguntas, fuentes, hashes y estado editorial. | Persistente derivada / workflow |

## Ojeador

| Tabla | Para qué sirve | Tipo | Se clona PRO → STAGING |
|---|---|---|---|
| `{prefix}seo_ojeador_market_products` | Capa histórica de matching exacto por producto. | Snapshot de mercado | Sí |
| `{prefix}seo_ojeador_market_offers` | Ofertas individuales observadas por producto/comercio. | Snapshot de mercado | Sí |
| `{prefix}seo_ojeador_market_categories` | Estado de observación por categoría WooCommerce. | Snapshot de mercado | Sí |
| `{prefix}seo_ojeador_market_category_results` | Resultados completos de mercado por categoría. | Snapshot de mercado | Sí |
| `{prefix}seo_ojeador_market_runs` | Ejecuciones del worker y contadores de proceso. | Runtime / histórico operativo | No |
| `{prefix}seo_ojeador_query_log` | Trazabilidad petición a petición, incluidos errores HTTP/API/DB. | Runtime / diagnóstico | No |

Las tablas de snapshot representan **mercado observado**, no productos propios ni catálogo canónico.

## Comparador

| Tabla | Para qué sirve | Tipo |
|---|---|---|
| `{prefix}seo_comparador_profiles` | Perfil canónico de cada comparación/categoría y su decisión editorial. | Persistente derivada |
| `{prefix}seo_comparador_axes` | Ejes o criterios comparables: medida, potencia, capacidad, etc. | Persistente derivada |
| `{prefix}seo_comparador_products` | Productos propios y externos incluidos en cada perfil. | Persistente derivada |
| `{prefix}seo_comparador_values` | Valores normalizados de cada producto para cada eje de comparación. | Persistente derivada |
| `{prefix}seo_comparador_editorial` | Resumen, diferencias, criterios de compra, usos, mercado y texto editorial. | Workflow editorial |
| `{prefix}seo_comparador_post_map` | Relación entre un perfil de comparación y el post publicado/asociado. | Relación editorial |
| `{prefix}seo_comparador_workflow` | Histórico de transiciones y decisiones del Comparador. | Workflow |

Comparador consume catálogo propio, snapshots persistidos de Ojeador y señales de rendimiento ya calculadas por Analista; no necesita duplicar esas fuentes.

## Comentarista

| Tabla | Para qué sirve | Tipo |
|---|---|---|
| `{prefix}seo_comentarista` | Opiniones/evidencias externas y sus indicadores asociados. | Evidencia persistente |

## Analista y Auditor

Actualmente **Analista** y **Auditor** trabajan principalmente leyendo catálogo, contenido y snapshots de otros servicios y guardando estados o informes agregados en opciones de WordPress, en lugar de mantener una familia grande de tablas propias.

Ejemplos de estado/snapshot en `options`:

- Analista: `seo_analista_task_history_381`, `seo_analista_competitive_report_v1` y otros snapshots calculados.
- Auditor: `seo_auditor_last_catalog_report` y otros estados de auditoría.

Esto significa que una tabla de Ojeador, Comparador, Dependiente o Ingeniero puede aparecer en una pantalla de Auditor o Analista **sin pertenecer a Auditor/Analista**.

## Clonador: qué viaja y qué se regenera

El Clonador distingue tres grupos:

1. **WordPress/WooCommerce canónico**: contenido, términos, relaciones y metadatos.
2. **Tablas custom persistentes**: Vocabulary, atributos, Dependiente portable, Ingeniero, Solucionador, Comparador y snapshots persistentes de Ojeador.
3. **Runtime derivado**: índices, logs operativos, workers, colas y ejercicios que no deben copiarse ciegamente entre entornos.

Antes de eliminar una tabla hay que comprobar en qué grupo está. Que una tabla pueda regenerarse no significa que sea conveniente borrarla: puede contener histórico, evidencias o estados de revisión que costaría reconstruir.

## Regla práctica

Si aparece una tabla desconocida:

1. identifica su prefijo funcional (`seo_dependiente_`, `seo_ingeniero_`, `seo_solucionador_`, `seo_comparador_`, `seo_ojeador_`, etc.);
2. localiza el servicio en esta página;
3. comprueba si es **canónica, persistente derivada, workflow o runtime**;
4. solo después decide si debe conservarse, clonarse, regenerarse o eliminarse.
