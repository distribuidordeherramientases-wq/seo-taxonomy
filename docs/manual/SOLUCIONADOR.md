# Solucionador

Solucionador es el **sistema central de decisión editorial** de DistribuidorDeHerramientas.es.

Su responsabilidad es contestar:

> ¿Qué debemos hacer con nuestros contenidos, por qué y con qué información debe trabajar la Editora?

No sustituye a los servicios especialistas y no vuelve a realizar su trabajo. Consume sus resultados, comprueba la cobertura editorial y recomienda la **actuación mínima necesaria**.

## Estado operativo actual

- **Versión funcional de Solucionador:** 0.4.2.
- **Versión del esquema de base de datos:** 0.4.0.
- **Entorno de validación:** staging antes de producción.
- **RF de referencia:** Requisitos Funcionales de Solucionador v1.0, 01/10/2026.
- **Incidencias relacionadas:** #480 (primer análisis no inicializado), #482 (validación de tablas y arranque en producción) y #544 (Academia de Dependiente → dossiers por categoría).

La versión 0.4.1 corrige un problema operativo observado en producción con 0.4.0: Solucionador podía estar instalado, con su esquema reconocido, pero mostrar todos los KPIs a cero porque todavía no se había ejecutado ningún análisis inicial.

Desde 0.4.1, si no existe `seo_solucionador_last_scan`, Solucionador ejecuta automáticamente el primer análisis al abrir su pantalla o antes de exportar sus resultados. El botón **Reanalizar fuentes** se mantiene para posteriores recalculados manuales.

## Inicialización y persistencia

Solucionador dispone de persistencia propia y no depende de que las tablas se creen manualmente desde phpMyAdmin.

`SEO_Solucionador_DB::maybe_install()` comprueba:

1. la versión registrada en `seo_solucionador_db_version`;
2. que existan las seis tablas propias de Solucionador.

Si la versión es correcta y las seis tablas existen, no hace nada. Si falta una tabla o el esquema necesita instalación/actualización, llama a `install()`, que usa el mecanismo `dbDelta()` de WordPress para crear o actualizar el esquema.

Esto sigue el patrón:

~~~text
si esquema y tablas existen
    continuar
si falta alguna tabla o versión
    crear/actualizar con dbDelta()
~~~

El primer análisis funcional se gestiona de forma separada mediante `SEO_Solucionador_Engine::ensure_initialized()`:

~~~text
si existe seo_solucionador_last_scan
    no repetir el arranque inicial
si no existe
    bloquear inicializaciones simultáneas
    ejecutar scan()
    guardar seo_solucionador_last_scan
~~~

El bloqueo temporal evita que dos pestañas o una apertura de pantalla y una exportación intenten iniciar el mismo análisis a la vez. Si el arranque falla, se conserva un error de inicialización para poder diagnosticarlo.

La instalación de tablas y el primer análisis son dos pasos distintos:

- **persistencia:** asegura que el esquema existe;
- **análisis:** llena evidencias, temas, cobertura y decisiones a partir de datos ya producidos por los servicios especialistas.

## Principios RF v1.0

1. **Category-first.** La oportunidad intenta asociarse primero a product_cat (term_id) y desde ahí conoce hub secundario, hub primario, cluster y productos.
2. **Separación de capas.** Evidencia, tema canónico, cobertura, decisión y workflow se almacenan como conceptos distintos.
3. **Mejorar antes de crear.** Una URL nueva es la última opción.
4. **Cobertura multientidad.** Se comprueban posts, páginas, landings, hubs y categorías.
5. **Decisión explicable.** La prioridad muestra componentes; la puntuación nunca sustituye a los requisitos obligatorios.
6. **Brief, no artículo.** Solucionador entrega estructura, evidencias y conocimiento. La Editora redacta.
7. **Sin publicación automática.** Como máximo prepara un borrador de trabajo después de aprobación humana.
8. **Ciclo cerrado.** El contenido publicado entra en seguimiento y recibe métricas posteriores de los informes/Analista.

## Flujo

~~~text
Analista
Dependiente / Intérprete
Ingeniero
Ojeador
Comparador
Auditor
Clasificador
Marketing (solo prioridad)
otras señales válidas
        ↓
      EVIDENCIAS
        ↓
  TEMA CANÓNICO
        ↓
 COBERTURA EDITORIAL
        ↓
 DECISIÓN MÍNIMA
        ↓
   BRIEF EDITORIAL
        ↓
      EDITORA
        ↓
 CONTENIDO PUBLICADO
        ↓
 ANALISTA / MÉTRICAS
        ↓
    SEGUIMIENTO
~~~

Solucionador **no** consulta Internet, Search Console, Analytics, Bing, Trends ni Google Shopping por su cuenta.

## Fuentes

### Dependiente / Intérprete

Solucionador consume **dos carriles distintos** de Dependiente y no modifica su forma de aprender.

#### Demanda real

`seo_dependiente_search_log` representa lo que preguntan los visitantes:
- preguntas;
- búsquedas;
- cero resultados;
- problemas que quiere resolver el cliente;
- lenguaje real;
- resultados/feedback.

Una búsqueda aislada es **evidencia**, no una orden para crear un post.

#### Academia / conocimiento aprendido

Solucionador lee `seo_dependiente_trainer_questions` y la **última ejecución** de cada pregunta en `seo_dependiente_trainer_runs`.

Una pregunta sólo entra en el carril editorial de conocimiento aprendido cuando:
- está activa;
- pertenece al currículo de Academia;
- el último run está `answered`;
- `evaluation_status` empieza por `pass_`.

Se conserva:
- pregunta y tipo;
- lección y módulo;
- origen;
- `expected_json`;
- estado y score de evaluación;
- `evaluation_json`;
- `top_results`;
- `response_meta`;
- fecha del último run.

Solucionador resuelve `product_cat` únicamente cuando puede demostrarla por categoría, producto o owner de FAQ. Si no puede resolver la categoría, la pregunta permanece visible en KPI como **aprendida sin categoría** pero no se fuerza a ningún dossier.

Las preguntas aprendidas se agrupan en **un dossier por categoría**, no en una URL por pregunta.

Ejemplo:

~~~text
Taladros · term_id 77
17 preguntas aprendidas
        ↓
dossier dependiente_qa_basic
        ↓
cobertura existente
        ↓
CREATE_POST / IMPROVE_POST / MERGE_CONTENT / NO_ACTION / INVESTIGATE
~~~

El search log sigue midiendo demanda real y Academia sigue representando lo que Dependiente ya sabe. Son señales diferentes.

### Analista
Aporta lo que está ocurriendo:
- consultas;
- impresiones;
- clics;
- CTR;
- posición;
- evolución;
- URLs al alza/baja;
- búsquedas internas;
- huecos y conclusiones.

**Analista = qué está ocurriendo. Solucionador = qué debemos hacer con ello.**

### Ingeniero
Aporta conocimiento técnico activo y validado por categoría:
- definición;
- funcionamiento;
- aplicaciones;
- tipos;
- compatibilidad;
- limitaciones;
- mantenimiento;
- problemas;
- seguridad;
- normativa;
- terminología.

El brief conserva fuentes, URL, tipo, confianza y fecha disponibles en Ingeniero.

### Ojeador
Aporta contexto de mercado ya calculado por categoría:
- variedad observada;
- marcas/modelos;
- amplitud de comerciantes;
- precios/promoción;
- competencia;
- profundidad/huecos de catálogo.

### Comparador
Contrato extensible mediante el filtro **seo_solucionador_comparador_signals**.

Cuando Comparador publique su análisis ampliado, Solucionador podrá consumir:
- tipos/configuraciones;
- factores decisivos;
- diferencias;
- ventajas/limitaciones;
- referencias representativas.

Solucionador no recalcula comparativas.

### Auditor
Aporta carencias, contenido débil, repeticiones, conceptos ausentes y problemas estructurales. Un hallazgo del Auditor no implica crear una URL.

### Clasificador
Es la referencia semántica del catálogo:
- Vocabulary;
- rol;
- tipo/subtipo;
- aplicación;
- plataforma;
- jerarquía;
- categorías.

También aporta huecos de vocabulario detectados a partir de Ingeniero.

### Marketing
Contrato opcional mediante el filtro **seo_solucionador_marketing_priorities**.

Puede reforzar:
- prioridad de categoría;
- campaña;
- estacionalidad;
- interés comercial.

Marketing **nunca origina por sí solo una URL nueva**.

## Evidencias

Tabla: **wp_seo_solucionador_evidence**.

Campos RF relevantes:
- source_type;
- source_id;
- signal_type;
- source_text;
- entity_type;
- entity_id;
- category_id;
- confidence;
- observed_at;
- evidence_score;
- source_meta.

Varias evidencias pueden reforzar **un único tema canónico**.

## Tema canónico

Tabla: **wp_seo_solucionador_topics**.

Modelo semántico:
- intent;
- action_term;
- object_term;
- condition_term;
- context_term;
- primary_category_id;
- proposed_vocabulary;
- proposed_categories.

El objeto se selecciona con prioridad **category-first**:
1. categorías;
2. Vocabulary canónico;
3. familias/productos;
4. materiales/aplicaciones;
5. lenguaje restante.

Términos editoriales como compra, guía, comunes, mejores, consejos, problema o información tienen peso bajo y no deben desplazar a la entidad técnica.

El normalizador conserva objetos nominales compuestos como **compresor de aire**.

## Agrupación de preguntas

Las dudas de elección de una misma categoría pueden converger en una huella canónica de categoría.

Ejemplo:

~~~text
¿Qué taladro necesito para hormigón?
¿Qué potencia necesito?
¿Taladro con cable o batería?
        ↓
decision|elegir|category-<term_id>|general|general
~~~

El objetivo es una necesidad editorial consolidada, no un artículo por pregunta.

## Cobertura editorial

Tabla nueva: **wp_seo_solucionador_coverage**.

Se reconstruye al reanalizar.

Entidades:
- post;
- page (incluye landing/hubs según seo_nodes);
- product_cat.

Ámbitos indexados:
- título/H1 lógico;
- H2/H3;
- fragmento representativo del contenido;
- categorías relacionadas;
- Vocabulary.

Estados:
- uncovered;
- weak_coverage;
- partial_coverage;
- covered;
- duplicate;
- conflict.

duplicate aparece cuando varias entidades fuertes responden prácticamente a la misma intención. conflict añade indicios de contradicción entre contenidos.

Solucionador calcula además:
- duplication_risk;
- cannibalization_risk.

## Orden de decisión

1. comprobar contenido existente;
2. comprobar si puede ampliarse;
3. comprobar si corresponde mejorar la categoría;
4. comprobar si encaja en una landing existente;
5. comprobar fusión/consolidación;
6. investigar si falta conocimiento;
7. solo entonces plantear URL nueva.

Acciones RF v1.0:

- NO_ACTION
- IMPROVE_POST
- IMPROVE_LANDING
- IMPROVE_CATEGORY
- IMPROVE_PAGE
- MERGE_CONTENT
- CREATE_POST
- CREATE_LANDING
- INVESTIGATE
- DEFER
- UPDATE_PRODUCT queda reservado para casos explícitamente específicos de ficha.

### Reglas principales

- covered → NO_ACTION.
- duplicate → MERGE_CONTENT.
- conflict → INVESTIGATE.
- cobertura parcial/débil → mejorar la entidad existente.
- conocimiento técnico insuficiente → INVESTIGATE.
- intención amplia que coincide con una familia existente → IMPROVE_CATEGORY antes de CREATE_LANDING.
- URL nueva exige cobertura libre, evidencia real, conocimiento suficiente y riesgo aceptable.

## CREATE_POST

Requiere:
- necesidad real;
- cobertura inexistente/débil;
- evidencia suficiente;
- conocimiento técnico suficiente;
- riesgo de duplicación/canibalización por debajo del umbral;
- aprobación humana antes de preparar el borrador.

SEO_Solucionador_Posts::create_draft() rechaza una propuesta que no esté approved o brief_ready o que haya dejado de cumplir los gates.

## CREATE_LANDING

No se deriva únicamente de una palabra clave o del tamaño del catálogo.

Requiere una candidata de landing válida y:
- intención diferenciada/estable;
- cobertura comercial;
- utilidad de compra;
- destino lógico;
- ausencia de URL equivalente;
- evidencia, conocimiento y riesgos aceptables.

Una necesidad que coincide directamente con product_cat debe valorar primero IMPROVE_CATEGORY.

## Prioridad explicable

priority_score sigue existiendo para ordenar, pero la UI muestra priority_components.

Componentes actuales:
- necesidad de usuario;
- demanda/búsqueda;
- oportunidad de visibilidad;
- conocimiento técnico;
- encaje con catálogo;
- amplitud de mercado;
- hueco de cobertura;
- relevancia comercial;
- penalización por duplicación.

Los pesos se pueden ajustar en el futuro sin convertir la puntuación en una decisión automática.

## Condiciones obligatorias

decision_requirements registra, entre otras:
- evidencia real;
- categoría identificada;
- cobertura compatible con URL nueva;
- conocimiento suficiente;
- duplicación/canibalización por debajo del umbral;
- requisitos de landing cuando aplica.

La ficha de oportunidad muestra estos gates.

## Pantallas

### Resumen
KPIs de:
- oportunidades activas;
- candidatos;
- aprobados/brief;
- contenidos a mejorar;
- duplicados/conflictos;
- investigación;
- aplazados;
- seguimiento;
- preguntas de Academia aprendidas;
- categorías con conocimiento aprendido.

### Diagnóstico editorial
Punto central visible para reutilizar informes existentes de:
- Entradas;
- Páginas/landings;
- Categorías;
- cobertura de contenido;
- servicios fuente.

Las pantallas de edición siguen siendo lugares de ejecución.

### Propuestas editoriales
Filtros por:
- categoría;
- cluster;
- fuente;
- acción;
- cobertura;
- prioridad;
- workflow.

### Ficha de oportunidad
Nueve bloques:
1. Resumen.
2. Evidencias.
3. Categoría/hubs/cluster.
4. Cobertura.
5. Conocimiento.
6. Mercado.
7. Decisión.
8. Brief para Editora.
9. Seguimiento.

### Cobertura editorial
Muestra el índice multientidad que sustenta la detección de duplicidad/canibalización.

### Fuentes y servicios
Explica el contrato de cada fuente y muestra disponibilidad.

Para Dependiente/Academia añade KPIs de:
- preguntas totales;
- aprendidas;
- no aprendidas;
- aprendidas con categoría;
- aprendidas sin categoría;
- categorías con conocimiento;
- categorías sin conocimiento;
- media de preguntas por categoría;
- última ejecución de Academia.

### Pruebas RF v1.0
Ejecuta siete regresiones funcionales sin modificar datos.

## Brief para Editora

Incluye:
- topic_id;
- categoría y jerarquía;
- acción/tipo de contenido;
- necesidad/pregunta/intención/audiencia si existe;
- evidencias;
- URLs relacionadas y cobertura;
- contenido que no debe repetirse;
- preguntas reales;
- preguntas aprendidas de Academia y su último resultado validado;
- conceptos y conocimiento obligatorio;
- productos/categorías relacionadas;
- enlaces internos;
- fuentes de Ingeniero;
- contexto Ojeador/Comparador;
- riesgos;
- condiciones de decisión.

Solucionador **no** decide longitud exacta, estilo final, frases, introducción ni texto definitivo.

## Workflow

Tabla: **wp_seo_solucionador_workflow**.

Estados:
- detected;
- validated;
- candidate;
- approved;
- brief_ready;
- in_editing;
- scheduled;
- published;
- monitoring;
- closed;
- rejected;
- deferred.

Los cambios manuales registran:
- usuario;
- fecha;
- motivo;
- acción editorial asociada.

Cuando una propuesta procede del dossier de Academia/Dependiente y termina en CREATE_POST, el borrador se marca con `_seo_solucionador_content_role = dependiente_qa_basic` y conserva la relación `post_to_category`. Las plantillas sólo recuperan estos posts cuando están publicados.

Al publicar un borrador creado por Solucionador, el tema pasa a seguimiento; no se publica automáticamente desde Solucionador.

## Seguimiento

Tabla: **wp_seo_solucionador_tracking**.

Solucionador reutiliza snapshots disponibles de informes/Analista para asociar al topic_id:
- impresiones;
- clics;
- CTR;
- posición;
- sesiones;
- vistas.

Registra un estado orientativo:
- baseline;
- improved;
- flat;
- declined;
- insufficient_data.

Este historial permite contestar posteriormente: **¿la intervención funcionó?**

No ajusta automáticamente los criterios editoriales.

## Tablas

Solucionador mantiene seis tablas propias:

- `wp_seo_solucionador_topics`
- `wp_seo_solucionador_evidence`
- `wp_seo_solucionador_post_topics` (compatibilidad con índice histórico de posts)
- `wp_seo_solucionador_coverage`
- `wp_seo_solucionador_workflow`
- `wp_seo_solucionador_tracking`

No deben crearse manualmente en una instalación normal. `SEO_Solucionador_DB::maybe_install()` valida las seis y, si falta cualquiera, vuelve a ejecutar la instalación del esquema mediante `dbDelta()`.

### Qué almacena cada tabla

- **topics:** tema canónico, categoría principal, cobertura, decisión, riesgos, prioridad y estado.
- **evidence:** todas las evidencias que justifican cada tema.
- **post_topics:** índice histórico de temas asociados a posts, conservado por compatibilidad.
- **coverage:** huellas semánticas de posts, páginas, landings, hubs y categorías.
- **workflow:** historial de cambios de estado, usuario, fecha y motivo.
- **tracking:** métricas posteriores asociadas a la intervención editorial.

### Comprobación previa a producción

Antes de dar por válida una promoción de Solucionador a producción:

1. confirmar que las seis tablas existen;
2. confirmar la versión de esquema registrada;
3. abrir Solucionador y comprobar que existe un `last_scan`;
4. verificar que la cobertura editorial se ha indexado;
5. verificar que las fuentes aportan señales;
6. comprobar que los temas y decisiones aparecen cuando existen evidencias válidas;
7. descargar el JSON y confirmar que refleja el mismo estado que la pantalla.

Que los KPIs estén a cero no debe interpretarse automáticamente como ausencia de oportunidades. Primero debe comprobarse que existen tablas, que el análisis se ha ejecutado y que las fuentes están disponibles.

## Tests funcionales RF v1.0

SEO_Solucionador_Tests::run() valida:

1. “Guía de Compra: Cómo Elegir un Compresor de Aire” → objeto compresor de aire, nunca compra.
2. “Errores comunes al usar un compresor de aire” → objeto compresor de aire, intención problem/need.
3. Intención ya cubierta → IMPROVE_POST o NO_ACTION, nunca CREATE_POST.
4. Preguntas de elección de la misma categoría → un único canonical topic.
5. Necesidad amplia coincidente con categoría → IMPROVE_CATEGORY antes de landing.
6. Intención transversal válida y sin URL equivalente → CREATE_LANDING.
7. Conocimiento insuficiente → INVESTIGATE.
8. Dossier Academia → huella estable `dependiente_qa_basic` por categoría.
9. Dossier aprendido sin cobertura, con evidencia y conocimiento suficientes → CREATE_POST.

Un fallo se muestra en la pestaña **Pruebas RF v1.0** y debe bloquear una promoción consciente a producción.

## Regla de seguridad

- análisis: lectura;
- decisión: recomendación;
- workflow: humano;
- brief: preparación;
- borrador: solo tras aprobación;
- publicación/modificación final: editor humano.

Solucionador es el **cerebro de decisión editorial**, no un generador automático de páginas.
