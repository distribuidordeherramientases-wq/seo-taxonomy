# Ingeniero

Ingeniero es el servicio de **investigación técnica y proceso editorial especializado por categoría** de SEO Taxonomy.

Ruta administrativa: **SEO Taxonomy → Contenidos → Ingeniero**.

Desde la versión interna 0.3.0 se ejecuta como servicio independiente de Dependiente. Conserva la investigación y persistencia técnica existentes y añade un circuito editorial propio:

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

El worker procesa las categorías de forma secuencial. Una categoría con error no bloquea las siguientes salvo errores globales de proveedor/cuota.

### Editorial

La pestaña **Editorial** transforma conocimiento active en dossiers técnicos sin copiar las fuentes ni duplicar el conocimiento.

Persistencia: wp_seo_ingeniero_editorial.

Campos principales: term_id, topic_key, knowledge_ids_json, source_ids_json, source_hash, suggested_title, coverage_status, recommended_action, status, post_id y timestamps.

La clave única term_id + topic_key evita duplicar una propuesta en ejecuciones posteriores.

Por defecto se genera **una propuesta técnica por categoría**. Sólo se divide cuando hay al menos dos intenciones técnicas diferenciadas y cada una dispone de evidencia suficiente. Las familias orientativas son:

- fundamentos / funcionamiento;
- elección / compatibilidad;
- mantenimiento / problemas;
- seguridad / normativa.

No son una taxonomía rígida: la agrupación parte de los knowledge_type realmente disponibles.

## Cobertura editorial

Ingeniero usa SEO_Editorial_Coverage, la API neutral compartida de cobertura.

Actualmente esta API reutiliza mediante un wrapper de compatibilidad el índice de cobertura existente, pero Ingeniero no llama al motor de decisión de Solucionador.

| Acción | Significado |
|---|---|
| CREATE_POST | Hay conocimiento y fuentes suficientes y no existe cobertura equivalente |
| IMPROVE_POST | Existe un post relacionado pero la cobertura es parcial |
| MERGE_CONTENT | Hay piezas técnicas solapadas o en conflicto |
| NO_ACTION | La intención técnica ya está cubierta |
| NEEDS_REVIEW | Falta confianza, fuentes o masa técnica suficiente |

## Brief para Editora

Al abrir un dossier se construye el brief bajo demanda. Incluye título, categoría y topic_key; knowledge con síntesis/confianza; evidencias y source_ids; fuentes trazables; lista must cover; cobertura; enlaces internos a categorías/productos propios; y advertencia de verificación.

El dossier sólo persiste IDs y referencias. No copia páginas, manuales ni artículos de terceros.

## Borradores

Ingeniero nunca autopublica. Un post nuevo sólo se crea si la acción es CREATE_POST, la propuesta está approved y la categoría product_cat sigue siendo válida.

El borrador:

- usa post_type=post y post_status=draft;
- guarda el rol estable ingeniero_qa_specialized;
- guarda relación post_to_category;
- conserva el ID del dossier, topic_key y source_hash;
- reutiliza Vocabulary canónico de la categoría mediante la API común;
- queda en manos de la Editora.

Un cambio posterior en conocimiento o fuentes modifica source_hash. Si ya existe post, el dossier pasa a needs_update; si todavía no existe, vuelve a revisión. Nunca se sobrescribe un post publicado.

## Salida pública

Las plantillas sólo muestran posts publish relacionados mediante post_to_category y con rol ingeniero_qa_specialized, bajo **Información técnica**. Si no hay contenido válido no se imprime el bloque.

Las plantillas son de sólo lectura: no investigan ni recalculan dossiers durante la visita.

## Métricas

Ingeniero muestra KPIs operativos de categorías activas, dossiers, acciones, borradores, publicados y needs_update. Las métricas web —impresiones, clics, CTR, posición, sesiones y vistas— pertenecen a Analista. Ingeniero no llama por su cuenta a GSC, GA4 o Bing.

## Integración

- Clasificador puede seguir consumiendo conocimiento técnico de Ingeniero.
- Dependiente ya no contiene la pantalla ni carga el bootstrap de Ingeniero.
- Solucionador no consume Ingeniero como fuente ni usa su conocimiento como requisito para decidir posts.
- Analista mantiene la medición global.
- Plantillas consumen únicamente posts publicados ya clasificados.

## Rendimiento

- investigación y actualización editorial se procesan categoría a categoría;
- Editorial está paginado;
- el brief completo sólo se construye al abrir una propuesta;
- un dossier se omite cuando su source_hash no cambia;
- no se carga active_knowledge de todas las categorías en una sola petición;
- fuentes y knowledge se referencian, no se duplican.

## Pruebas

La pestaña **Pruebas** comprueba agrupación conservadora, división con evidencia, estabilidad/cambio de source_hash, matriz de acciones, aprobación humana, unicidad term_id + topic_key y el rol ingeniero_qa_specialized.

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
