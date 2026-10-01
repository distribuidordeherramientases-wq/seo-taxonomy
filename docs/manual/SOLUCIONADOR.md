# Solucionador

Solucionador es el **sistema central de decisión editorial** de DistribuidorDeHerramientas.es.

Su responsabilidad es contestar:

> ¿Qué debemos hacer con nuestros contenidos, por qué y con qué información debe trabajar la Editora?

No sustituye a los servicios especialistas y no vuelve a realizar su trabajo. Consume sus resultados, comprueba la cobertura editorial y recomienda la **actuación mínima necesaria**.

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
Fuente principal de necesidad real:
- preguntas;
- búsquedas;
- cero resultados;
- problemas que quiere resolver el cliente;
- lenguaje real;
- resultados/feedback.

Una búsqueda aislada es **evidencia**, no una orden para crear un post.

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
- seguimiento.

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

- wp_seo_solucionador_topics
- wp_seo_solucionador_evidence
- wp_seo_solucionador_post_topics (compatibilidad con índice histórico de posts)
- wp_seo_solucionador_coverage
- wp_seo_solucionador_workflow
- wp_seo_solucionador_tracking

## Tests funcionales RF v1.0

SEO_Solucionador_Tests::run() valida:

1. “Guía de Compra: Cómo Elegir un Compresor de Aire” → objeto compresor de aire, nunca compra.
2. “Errores comunes al usar un compresor de aire” → objeto compresor de aire, intención problem/need.
3. Intención ya cubierta → IMPROVE_POST o NO_ACTION, nunca CREATE_POST.
4. Preguntas de elección de la misma categoría → un único canonical topic.
5. Necesidad amplia coincidente con categoría → IMPROVE_CATEGORY antes de landing.
6. Intención transversal válida y sin URL equivalente → CREATE_LANDING.
7. Conocimiento insuficiente → INVESTIGATE.

Un fallo se muestra en la pestaña **Pruebas RF v1.0** y debe bloquear una promoción consciente a producción.

## Regla de seguridad

- análisis: lectura;
- decisión: recomendación;
- workflow: humano;
- brief: preparación;
- borrador: solo tras aprobación;
- publicación/modificación final: editor humano.

Solucionador es el **cerebro de decisión editorial**, no un generador automático de páginas.
