# Clasificador

Motor de propuesta de clasificación de SEO Taxonomy.

## Contrato

El Clasificador **solo lee y propone**. No crea términos, no modifica vocabularios y no escribe asignaciones. La persistencia sigue perteneciendo a los flujos canónicos de Asignación/Data Layer.

API principal:

```php
$result = seo_classifier_classify_product($product_id);
```

APIs parciales:

```php
$labels = seo_classifier_classify_product_labels($product_id);
$labels = seo_classifier_classify_product_groups($product_id, ['aplicacion', 'subtipo']);
$attributes = seo_classifier_classify_product_attributes($product_id);
```

## Arquitectura v2

El motor combina cinco familias de señales:

1. **Ficha local**: título, identidad inicial, slug, extracto, descripción, categorías, `product_tag`, marca y SKU.
2. **Catálogo del proveedor**: nombre, descripción, categoría del proveedor, MPN, SKU y `raw_json` de `seo_proveedores_productos`.
3. **Perfiles aprendidos**: relaciones observadas en asignaciones canónicas ya confirmadas, especialmente `TIPO -> APLICACIÓN/SUBTIPO/PLATAFORMA` y perfiles de categoría.
4. **Fuentes externas conocidas**: URLs de fabricante/proveedor ya almacenadas en el producto. Se leen en segundo plano, con caché; nunca se realiza una búsqueda general ni se siguen enlaces arbitrarios.
5. **Reglas técnicas**: identidad principal, familias como OBD/HUD, broca/cincel, plataformas de batería, unidades y expresiones técnicas.

La pantalla de Asignación consume estas APIs. La interfaz no debe volver a contener lógica de clasificación propia.

## Estados

- `current`: ya existe en el inventario canónico.
- `derived`: derivado de una relación canónica, por ejemplo ROL desde TIPO.
- `safe`: propuesta con evidencia suficiente para preselección/aceptación masiva.
- `review`: candidato útil, pero requiere revisión humana.
- `unresolved`: no existe evidencia suficiente.
- `new_attribute` / `gap`: se ha detectado un concepto técnico para el que falta una definición canónica; solo se informa.

## Clasificación semántica

El orden es deliberado:

1. TIPO
2. APLICACIÓN
3. SUBTIPO
4. PLATAFORMA
5. ROL derivado de TIPO

TIPO se apoya principalmente en la identidad del producto. APLICACIÓN y SUBTIPO pueden aprovechar tanto el texto como el consenso de productos ya clasificados del mismo TIPO o categoría.

Los perfiles estadísticos exigen soporte, cobertura, dominancia y margen frente al segundo candidato. Un único ejemplo no genera una propuesta segura.

## Atributos

Solo se consultan definiciones activas de `wp_sql_atributos`.

- `termino`: reutiliza términos y aliases existentes.
- `numero`/`rango`: exige una medida explícita y una unidad compatible.
- `texto`/`boolean`: permanecen sin resolver salvo que exista un extractor específico.

El resultado puede incluir `gaps` cuando se reconoce un concepto como SDS o HSS pero falta el atributo canónico correspondiente. El Clasificador nunca da de alta ese atributo.

## Fuentes externas

La clasificación normal no bloquea el administrador esperando una web externa. Cuando encuentra una URL conocida:

1. utiliza la caché si existe;
2. si falta, encola el producto;
3. WP-Cron descarga una o dos fichas por ejecución;
4. se extraen JSON-LD Product, metadatos y texto visible;
5. la siguiente clasificación aprovecha esa información.

Filtros principales:

```php
seo_classifier_external_sources_enabled
seo_classifier_external_source_urls
seo_classifier_external_fetch_allowed
seo_classifier_external_cache_ttl
seo_classifier_external_queue_batch_size
seo_classifier_profile_ttl
seo_classifier_group_thresholds
```

No se realiza descubrimiento de URLs mediante Google/Bing. Para añadir otra fuente, se debe guardar su URL en el producto o aportarla mediante `seo_classifier_external_source_urls`.

## Evaluación

El Clasificador puede medir su calidad ocultando una etiqueta ya conocida y tratando de reconstruirla:

```php
$report = seo_classifier_evaluate_product_group('aplicacion', [
    'limit' => 50,
    'offset' => 0,
]);
```

WP-CLI:

```bash
wp seo-classifier evaluate --group=aplicacion --limit=50
wp seo-classifier classify 137212
wp seo-classifier classify 137212 --refresh-source
wp seo-classifier refresh-source 137212
```

La evaluación no escribe datos ni realiza lecturas externas síncronas.

## Descubrimiento de vocabulario nuevo (Build 044)

Cuando una dimensión está vacía y no existe una alternativa canónica suficientemente sólida, el Clasificador puede devolver `new_terms`. Son propuestas de descubrimiento, no asignaciones automáticas.

El flujo es deliberadamente separado:

1. Se intenta reutilizar vocabulario existente (`safe` / `review`).
2. Si el concepto explícito del producto no tiene equivalente canónico próximo, se devuelve una propuesta `new_terms` con confianza, fuentes y término existente más cercano.
3. La pantalla de Asignación la muestra en morado.
4. Solo el botón **Crear y asignar** puede incorporarla a `seo_vocabulary` y asignarla al producto.
5. A partir de ese momento el término forma parte del inventario y el Clasificador puede reutilizarlo en otros productos.

Las propuestas nuevas nunca participan en la aceptación masiva. Para TIPO, la creación exige seleccionar el ROL canónico para mantener la relación TIPO -> ROL.


## Jobs adaptativos y Asignación bajo demanda (Build 045)

La matriz de Asignación deja de ejecutar el Clasificador al abrir la página. Su función es corregir anomalías, no mostrar el inventario completo.

Flujo:

1. La pantalla muestra KPI y filtros, sin precargar productos.
2. El administrador filtra una anomalía (`Sin APLICACIÓN`, `P4 · falta SUBTIPO`, etc.).
3. **Analizar este filtro en segundo plano** crea un job persistente.
4. El job inserta las referencias de producto mediante `INSERT ... SELECT`, sin cargar el catálogo entero en memoria PHP.
5. Un worker único procesa lotes pequeños y adaptativos mediante Action Scheduler, con WP-Cron como respaldo.
6. Las propuestas se guardan en `wp_seo_classifier_proposals` y la matriz solo las lee.
7. Los resultados se distinguen como `safe`, `review`, `new`, `unresolved` o `pending`.
8. **Aplicar propuestas seguras en cola** usa el mismo regulador y evita escrituras masivas dentro de una petición web.

Tablas:

- `wp_seo_classifier_jobs`: estado, progreso y métricas de cada trabajo.
- `wp_seo_classifier_job_items`: productos pendientes/procesados por trabajo y máscara de dimensiones.
- `wp_seo_classifier_proposals`: última propuesta persistida por producto y dimensión.

El regulador comienza de forma conservadora (3 productos en modo rápido y 1 en modo profundo), mide duración, segundos por fila, memoria, consultas SQL y la CPU global estimada por `load average / núcleos`. Por defecto, a partir del 65 % de CPU reduce a 1–2 productos y amplía la pausa; desde el 80 % no ejecuta el lote y se reprograma. Si detecta una importación de productos activa, también cede recursos y reprograma el worker.

La firma de contexto permite reutilizar propuestas cuando el producto no ha cambiado. La clasificación rápida no encola ni consulta fuentes externas; el modo profundo se reserva para casos difíciles.
