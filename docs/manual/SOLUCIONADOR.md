# Solucionador

Ruta: **SEO Taxonomy → Contenidos → Solucionador**

Solucionador es la **capa única de decisión editorial** del plugin.

Su responsabilidad no es volver a medir, investigar o interpretar lo que otros servicios ya hacen. Su función es **recoger sus conclusiones, cruzarlas, priorizarlas y convertirlas en una decisión editorial justificable**.

## Responsabilidades

Solucionador consume señales de:

- **Auditor**: carencias, calidad, cobertura, duplicidades y problemas detectados.
- **Analista**: tráfico, demanda, rendimiento y prioridades.
- **Clasificador**: estructura semántica y conceptos/atributos que faltan o tienen equivalentes dudosos.
- **Dependiente / Intérprete**: preguntas reales, intención, contexto, resultados y feedback de usuarios.
- **Ojeador**: mercado, competencia, precios y oportunidades ya analizadas.
- **Ingeniero**: conocimiento técnico trazable y aprobado por categoría.
- **Comentarista**: preguntas y problemas observados en experiencias externas.
- **Entradas, Páginas, Categorías e Imágenes**: inventario y estado actual del contenido.

Solucionador utiliza estas conclusiones para detectar:

- carencias editoriales;
- oportunidades;
- duplicidades;
- canibalización;
- temas con potencial;
- contenido existente que debe ampliarse;
- casos donde no conviene crear nada nuevo.

## Lo que Solucionador no hace

Solucionador no debe:

- aprender el catálogo por su cuenta;
- sustituir a Intérprete;
- buscar comparativas;
- investigar documentación técnica;
- consultar Google Shopping directamente;
- crear una segunda capa de medición de tráfico;
- redactar el contenido final;
- publicar automáticamente.

Los servicios especialistas siguen siendo propietarios de sus datos y cálculos. Solucionador **consume su resultado**.

## Pestañas

### Resumen

Presenta el estado del circuito editorial y permite **Reanalizar fuentes**.

La relectura consolida señales ya disponibles y actualiza las propuestas; no convierte Solucionador en el productor original de esas métricas o conocimientos.

### Diagnóstico editorial

Es el **único punto visible de análisis editorial consolidado**.

Agrupa:

- visión general de servicios fuente;
- rendimiento y oportunidades de Entradas;
- errores de Entradas;
- landings y oportunidades de Páginas;
- errores de Páginas;
- rendimiento Google de Categorías;
- estructura/cobertura de Categorías;
- cobertura objetiva de contenido desde Cluster hasta Producto;
- estado de los servicios fuente.

La lógica de cálculo existente se reutiliza. No se mantienen paneles paralelos visibles en Entradas, Páginas o Categorías.

Los editores locales conservan:

- datos fuente;
- inventarios operativos;
- formularios de edición;
- acciones de ejecución.

Los enlaces antiguos de informes redirigen a Solucionador cuando es posible.

### Propuestas editoriales

Mantiene un único registro de decisión y estado.

Para temas de posts/guías muestra:

- prioridad;
- tema/pregunta;
- clasificación;
- evidencias por servicio;
- cobertura existente;
- acción recomendada;
- acceso al **brief editorial**.

Acciones posibles:

- **Crear post**;
- **Mejorar contenido existente**;
- **Añadir sección**;
- **Observar**;
- **No hacer nada**.

La creación de un post sigue requiriendo aprobación explícita. Se crea únicamente un **borrador**, nunca se publica automáticamente.

La misma pestaña incorpora **Decisiones de landing** usando el sistema de candidatas de Páginas. Solucionador centraliza la decisión visible y Páginas conserva la ejecución final.

### Brief editorial

Cada propuesta puede abrir un brief que reúne:

- tema;
- intención;
- salida recomendada;
- motivo/prioridad;
- fuentes y evidencias;
- categorías relacionadas;
- productos relacionados;
- Vocabulary canónico;
- conceptos que faltan o requieren revisión según Clasificador;
- conocimiento técnico disponible de Ingeniero;
- contenido existente que debe conservarse;
- enlaces internos sugeridos;
- seguimiento disponible de la pieza ya publicada.

El brief es la **entrega a la Editora**. Solucionador decide y justifica; la Editora redacta y modifica el contenido en el editor correspondiente.

### Cobertura editorial

Mantiene el índice de soluciones ya existentes para evitar:

- duplicar artículos;
- crear otra URL para la misma intención;
- canibalizar contenido que puede ampliarse.

### Fuentes y servicios

Muestra qué señales aporta cada servicio y un snapshot operativo de disponibilidad/cobertura.

Abrir esta pestaña no debe lanzar investigación técnica ni búsquedas externas de Ojeador/Ingeniero; consume sus resultados almacenados o APIs internas de lectura.

### Datos internos

Vista de solo lectura de:

- `seo_solucionador_topics`;
- `seo_solucionador_evidence`;
- `seo_solucionador_post_topics`.

Sirve para trazabilidad de las decisiones.

## Ciclo editorial

El flujo objetivo es:

**Servicios especialistas → Solucionador → Brief → Editora/editor especializado → Publicación → métricas/cobertura → Solucionador**

La medición posterior sigue procediendo de los servicios que ya recogen Search Console, Analytics, cobertura y demás señales. Solucionador las reutiliza para comprobar si la decisión editorial tuvo efecto y alimentar la siguiente decisión.

## Compatibilidad interna

Se conserva:

- slug administrativo `seo-solucionador`;
- clases `SEO_Solucionador_*`;
- tablas y datos existentes;
- acciones históricas;
- enlaces antiguos redirigidos cuando procede.

La centralización es principalmente de **responsabilidad y visibilidad**, no una duplicación de motores.
