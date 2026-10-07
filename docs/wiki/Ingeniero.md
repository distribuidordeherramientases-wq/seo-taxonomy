# Ingeniero

Ingeniero es el servicio de **investigación técnica y proceso editorial especializado por categoría** de SEO Taxonomy.

Ruta administrativa: **SEO Taxonomy → Contenidos → Ingeniero**.

Desde la versión interna 0.3.0 se ejecuta como servicio independiente de Dependiente. En 0.3.3 la capa editorial se simplifica para que **todo knowledge active de una categoría llegue a Editora**, sin un segundo filtro por masa o familia:

~~~text
Fuentes técnicas
      ↓
   Ingeniero
      ↓
Dossier técnico
      ↓
    Editora
      ↓
Post WordPress
~~~

Solucionador no decide los posts técnicos de Ingeniero.

## Pestañas

### Investigación

Mantiene el trabajo técnico existente:

- L1 · documentación técnica activa;
- L2 · experiencia práctica preparada pero desactivada;
- fuentes trazables por product_cat;
- source_type, trust_level, URL, fecha, hash y estado;
- conocimiento definition, function, application, type, compatibility, limitation, maintenance, safety, problem, terminology y regulation;
- confianza, estado y source_ids;
- revisión y aprobación humana;
- importación/exportación del conocimiento.

Persistencia existente:

- wp_seo_ingeniero_sources;
- wp_seo_ingeniero_knowledge.

El worker procesa **exactamente una categoría por ciclo**. Así no carga `active_knowledge` de varias categorías en la misma ejecución. Una categoría con error no bloquea las siguientes salvo errores globales de proveedor/cuota.

### Editorial

La pestaña **Editorial** transforma conocimiento `active` en dossiers técnicos sin copiar las fuentes ni duplicar la investigación.

Persistencia: `wp_seo_ingeniero_editorial`.

Campos principales: `term_id`, `topic_key`, `knowledge_ids_json`, `source_ids_json`, `source_hash`, `suggested_title`, `coverage_status`, `recommended_action`, `status`, `post_id` y timestamps.

La clave única `term_id + topic_key` evita duplicar una propuesta en ejecuciones posteriores.

### Regla 0.3.3: dossier completo

Existe **un único dossier canónico por categoría**:

`topic_key=technical-overview`

Ese dossier incluye **todo el knowledge active** de la `product_cat`.

La capa editorial ya no divide por familias ni exige que una familia tenga dos knowledge y dos fuentes para aparecer. La investigación decide qué knowledge es válido/active; Editorial lo organiza y lo entrega a Editora.

Cada knowledge se normaliza además como pregunta-respuesta técnica:

- `definition` → “¿Qué es ... y qué aspectos técnicos conviene conocer?”;
- `function` → “¿Cómo funciona ...?”;
- `compatibility` → “¿Qué compatibilidades o requisitos hay que comprobar ...?”;
- `safety` → “¿Qué precauciones de seguridad ...?”;
- mantenimiento, normativa, limitaciones, aplicaciones, tipos, problemas, etc. siguen el mismo contrato.

La **respuesta** es la síntesis técnica persistida en `summary`; se conservan `facts`, `source_ids`, `confidence`, tipo y fecha para trazabilidad.

No se deduplica ni descarta un knowledge active por masa mínima.

## Objetivo mínimo de investigación

Ingeniero mantiene como objetivo operativo **4 knowledge activos por product_cat**. Una categoría con 1–3 knowledge activos vuelve a considerarse pendiente de completar cuando se prepara la cola de investigación.

Este objetivo pertenece a Investigación. Editorial no usa “4” como gate: si sólo existe un knowledge activo, igualmente se muestra a Editora.


## Proveedores de búsqueda Google

Ingeniero reutiliza las conexiones configuradas en **Herramientas → Conexiones con proveedores**, dentro del bloque **SerpApi + ScraperAPI · conexión compartida para Ojeador e Ingeniero**, y no guarda una segunda copia de las credenciales.

Orden serial:

1. **SerpApi**;
2. **ScraperAPI** si SerpApi no está disponible, ha agotado cuota o falla.

ScraperAPI utiliza el endpoint estructurado de **Google Search**. Ambos proveedores se normalizan al mismo contrato de `organic_results`, por lo que Investigación no depende del proveedor concreto.

Una respuesta válida con cero resultados se considera válida y no dispara el segundo proveedor. El fallback sólo se utiliza ante indisponibilidad, cuota, rate limit, error HTTP/API/red o respuesta inválida.

Ingeniero mantiene su propio límite mensual local de búsquedas lógicas. Ese límite es independiente del proveedor que finalmente atienda cada búsqueda.

## Cobertura editorial

Ingeniero usa SEO_Editorial_Coverage, la API neutral compartida de cobertura.

Actualmente esta API reutiliza mediante un wrapper de compatibilidad el índice de cobertura existente, pero Ingeniero no llama al motor de decisión de Solucionador.

| Acción | Significado |
|---|---|
| CREATE_POST | Hay conocimiento y fuentes suficientes y no existe cobertura equivalente |
| IMPROVE_POST | Existe un post relacionado pero la cobertura es parcial |
| MERGE_CONTENT | Hay piezas técnicas solapadas o en conflicto |
| NO_ACTION | La intención técnica ya está cubierta |
| NEEDS_REVIEW | No existe knowledge activo suficiente para formular una propuesta; confianza/fuentes quedan como indicadores para Editora, no como gate |

## Brief para Editora

Al abrir un dossier se construye el brief bajo demanda. Incluye:

- título, categoría y `topic_key`;
- **todas las preguntas-respuestas técnicas** derivadas del knowledge activo;
- tipo, síntesis, confianza, evidencias y `source_ids`;
- fuentes trazables;
- lista `must cover`;
- cobertura;
- enlaces internos a categorías/productos propios;
- advertencia de verificación.

El dossier sólo persiste IDs y referencias. No copia páginas, manuales ni artículos de terceros.

## Borradores

Ingeniero nunca autopublica. Un post nuevo sólo se crea si la acción es CREATE_POST, la propuesta ha sido aceptada por Editora y la categoría product_cat sigue siendo válida.

En el flujo actual, **Aprobar y crear borrador** es una única acción humana para propuestas CREATE_POST: al aprobar, Ingeniero cambia la propuesta a `approved` y crea inmediatamente el borrador. Las propuestas ya aprobadas sin post pueden recuperarse con **Aceptar todo**, que crea los borradores pendientes sin duplicar los existentes.

El borrador:

- usa `post_type=post` y `post_status=draft`;
- nace con un **brief interno editable** que contiene todas las preguntas-respuestas del dossier;
- el brief avisa expresamente de que debe revisarse/sintetizarse antes de publicar;
- guarda el rol estable `ingeniero_qa_specialized`;
- guarda relación `post_to_category`;
- conserva el ID del dossier, `topic_key`, `source_hash` y snapshot de knowledge;
- reutiliza Vocabulary canónico de la categoría mediante la API común;
- queda en manos de Editora.

Ingeniero nunca publica automáticamente.

Un cambio posterior en conocimiento o fuentes modifica `source_hash`. Si ya existe post, el dossier pasa a `needs_update`; si todavía no existe, vuelve a revisión. Nunca se sobrescribe un post publicado.

Desde 0.3.3 la comparación del snapshot distingue:

- **NUEVO**;
- **MODIFICADO**;
- **RETIRADO**.

Los retirados se muestran a Editora con su última copia conocida; el contenido público no se modifica en silencio.

## Salida pública

Las plantillas sólo muestran posts publish relacionados mediante post_to_category y con rol ingeniero_qa_specialized, bajo **Información técnica**. Si no hay contenido válido no se imprime el bloque.

Las plantillas son de sólo lectura: no investigan ni recalculan dossiers durante la visita.

## Métricas

Ingeniero muestra KPIs operativos de categorías activas, knowledge, dossiers, acciones, borradores, publicados y `needs_update`. El export permite comparar el número de `active_knowledge` con los `qa_items` incluidos en los dossiers y detectar categorías por debajo de cuatro bloques técnicos. Las métricas web —impresiones, clics, CTR, posición, sesiones y vistas— pertenecen a Analista. Ingeniero no llama por su cuenta a GSC, GA4 o Bing.

## Integración

- Clasificador puede seguir consumiendo conocimiento técnico de Ingeniero.
- Dependiente ya no contiene la pantalla ni carga el bootstrap de Ingeniero.
- Solucionador no consume Ingeniero como fuente, no incorpora sus `knowledge`/`sources` al brief y no usa su conocimiento como requisito para decidir posts. La única pieza compartida es la API neutral de cobertura editorial.
- Analista mantiene la medición global.
- Plantillas consumen únicamente posts publicados ya clasificados.

## Rendimiento

- investigación y actualización editorial se procesan categoría a categoría;
- Editorial está paginado;
- el brief completo sólo se construye al abrir una propuesta;
- un dossier se omite cuando su `source_hash` no cambia;
- no se carga `active_knowledge` de todas las categorías en una sola petición;
- fuentes y knowledge se referencian, no se duplican;
- no se aplica un segundo filtro editorial que reduzca el knowledge activo.

## Pruebas

La pestaña **Pruebas** comprueba dossier único completo, conservación de todo knowledge activo, generación de preguntas-respuestas, estabilidad/cambio de `source_hash`, ausencia de gate por masa, detección NUEVO/MODIFICADO/RETIRADO, aprobación humana, unicidad `term_id + topic_key` y el rol `ingeniero_qa_specialized`.

## Código

El servicio vive fuera de Dependiente:

- includes/ingeniero/class-seo-ingeniero.php
- includes/ingeniero/class-seo-ingeniero-db.php
- includes/ingeniero/class-seo-ingeniero-admin.php
- includes/ingeniero/class-seo-ingeniero-process.php
- includes/ingeniero/class-seo-ingeniero-posts.php
- includes/ingeniero/class-seo-ingeniero-tests.php
- includes/ingeniero/class-seo-ingeniero-exchange.php
- includes/ingeniero/class-seo-ingeniero-search.php
- includes/ingeniero/ingeniero-bootstrap.php

La cobertura compartida vive en includes/editorial/class-seo-editorial-coverage.php.
