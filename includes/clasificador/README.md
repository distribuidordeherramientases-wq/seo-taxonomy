# Clasificador

Motor de propuesta de clasificación de SEO Taxonomy.

## Contrato

El Clasificador **solo lee y propone**. No crea términos, no modifica vocabularios y no escribe asignaciones. La persistencia sigue perteneciendo a los flujos canónicos de Asignación/Data Layer.

API principal:

```php
$result = seo_classifier_classify_product($product_id);
```

También existen APIs parciales:

```php
$labels = seo_classifier_classify_product_labels($product_id);
$attributes = seo_classifier_classify_product_attributes($product_id);
```

## Estados

- `current`: ya existe en el inventario canónico.
- `derived`: derivado de una relación canónica, por ejemplo ROL desde TIPO.
- `safe`: propuesta con evidencia suficiente para preselección.
- `review`: candidato útil, pero requiere revisión humana.
- `unresolved`: no existe evidencia suficiente o requiere vocabulario/extractor adicional.

## Fuentes de contexto

Título, identidad inicial del título, slug, extracto, descripción, categorías, `product_tag` y SKU.

## Etiquetas

Compara exclusivamente con `wp_seo_vocabulary` activo. TIPO da más peso a la identidad inicial del título que a listas de funciones. ROL se deriva del mapa canónico TIPO → ROL.

## Atributos

Compara exclusivamente con las definiciones activas de `wp_sql_atributos`.

- `termino`: reutiliza términos/aliases existentes.
- `numero`/`rango`: solo propone cuando hay evidencia numérica explícita y una regla segura.
- `texto`/`boolean`: quedan sin resolver hasta disponer de extractores específicos.

Los extractores se amplían en `rules.php` / `attributes.php`; la pantalla de Asignación no debe contener lógica de clasificación.
