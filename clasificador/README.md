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
