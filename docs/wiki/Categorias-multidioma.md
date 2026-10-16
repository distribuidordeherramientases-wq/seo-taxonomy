# Importador de categorías multidioma

> **Estado:** nuevo servicio desarrollado en el [PR #914](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/pull/914), dirigido a `staging`. La documentación describe el comportamiento del código propuesto. **Antes de usarlo en PRO, comprobar que el PR está fusionado y que ha superado las pruebas funcionales en STAGING.** No realiza traducciones automáticas de textos.

## Finalidad

TaxoCommerce SEO trata cada categoría traducida como una categoría **WooCommerce `product_cat` independiente**, con URL, contenido, idioma y catálogo propios. El sistema mantiene además una **relación lógica común** con la categoría original en español, para futuros informes agregados y análisis por mercado.

El servicio de importación crea o actualiza una categoría en su mercado y añade su relación con la categoría española en **una sola operación de importación**. No modifica el nombre, slug, jerarquía ni el contenido español.

### Ejemplo ilustrativo

| Referencia | WordPress `product_cat` | `locale` | Relación |
|---|---|---|---|
| Original | Taladros | `es-ES` | Referencia común |
| EE. UU. | Drills | `en-US` | Taladros → Drills |
| Alemania | Bohrmaschinen | `de-DE` | Taladros → Bohrmaschinen |
| Países Bajos | Boormachines | `nl-NL` | Taladros → Boormachines |

Los IDs, slugs y nombres son independientes. Las categorías **no se fusionan** dentro de WordPress.

## Persistencia y tablas existentes

No se crea una tabla de traducciones ni se modifica la estructura nativa de WordPress:

| Tabla | Qué almacena |
|---|---|
| `{prefix}terms` / `{prefix}term_taxonomy` | Categorías `product_cat` independientes; nombre, slug, padre, descripción nativa |
| `{prefix}termmeta` | `_seo_locale` (p. ej. `en-US`, `de-DE`, `nl-NL`; la referencia española puede marcarse `es-ES`). También `seo_excerpt` si se aporta extracto |
| `{prefix}seo_nodes` | `seo_role = excerpt` y `seo_role = description` para la categoría, compatible con el editor y plantillas actuales |
| `{prefix}seo_relations` | `category_translation`: origen `product_cat` español → destino `product_cat` traducido |

Relación concreta:

```text
source_type   = product_cat
source_id     = ID local de la categoría española
target_type   = product_cat
target_id     = ID local de la categoría traducida
relation_type = category_translation
```

La tabla `seo_relations` **ya contiene** estas columnas; la novedad es el valor de `relation_type`. No confundir con `hub_secondary_to_category` (arquitectura SEO): son enlaces independientes.

## Interfaz

Ruta: **TaxoCommerce SEO → Herramientas → Import / Export → Importación individual**.

Debajo del importador general **Importar categorías** aparece el nuevo bloque:

**Importar traducciones de categorías** → seleccionar CSV → botón **Importar traducciones de categorías**.

Formato del archivo: **CSV UTF-8, separador punto y coma (`;`)**, máximo **100 filas por archivo**, máximo **8 MB**.

### Columnas del CSV

| Columna | Obligatoria | Función |
|---|---|---|
| `source_slug` | Sí | **Slug real y existente** de la categoría española original en el entorno de destino |
| `locale` | Sí | `en-US`, `de-DE` o `nl-NL` |
| `titulo` | Sí | Nombre de la categoría traducida |
| `slug` | Sí | Slug de destino, único e identificable en WooCommerce |
| `excerpt` | No | Extracto SEO en idioma destino; guarda también `seo_excerpt` |
| `description` | No | Descripción SEO en idioma destino, admitiendo HTML seguro |
| `parent_slug` | No | Slug de la **categoría padre en el mismo idioma/mercado**, si no puede deducirse |

Ejemplo ilustrativo (adaptar slugs a categorías reales):

```csv
source_slug;locale;titulo;slug;excerpt;description;parent_slug
taladros;en-US;Drills;vevor-us-drills;Find your drill;Guide to choosing drills;vevor-us
taladros;de-DE;Bohrmaschinen;vevor-de-bohrmaschinen;Bohrmaschinen auswählen;Ratgeber zu Bohrmaschinen;vevor-de
taladros;nl-NL;Boormachines;vevor-nl-boormachines;Vind de juiste boormachine;Gids voor boormachines;vevor-nl
```

**Importante:** `taladros`, `vevor-us-drills` y los demás slugs son **ejemplos**. El `source_slug` debe existir en WordPress y representar una equivalencia editorial verificada. No se vinculan categorías por similitud de nombres ni se inventa la correspondencia española.

### Orden y jerarquía

1. Verificar la categoría española y su slug.
2. Crear primero los padres internacionales cuando sea necesario. Para el piloto existen raíces `vevor-us`, `vevor-de` y `vevor-nl`, que deben existir en el entorno antes de usarlas.
3. Importar categorías traducidas mediante el CSV.
4. Revisar los contadores de **creadas**, **actualizadas**, **nuevas relaciones** y **errores por fila**.
5. Consultar cada categoría traducida y comprobar idioma, textos, jerarquía y enlaces.
6. Reimportar el mismo CSV para verificar que **no duplica** ni las categorías ni los enlaces.
7. Solo tras validación, asociar productos del mercado correspondiente; esta importación **no mueve ni crea productos**.

La importación no acepta un `source_slug` inexistente, no vincula una categoría consigo misma, evita destinos identificados como otro mercado y rechaza una relación española incompatible o un segundo destino del mismo idioma. Si falta el padre internacional exigido para una categoría nueva, la fila se rechaza en vez de crearla bajo el padre español.

## STAGING, PRO y Clonador

No usar IDs SQL de un CSV de STAGING como si fueran IDs de PRO. La identidad de origen para este servicio es el **`source_slug` español del entorno de destino**. El importador resuelve allí los IDs locales y después crea la relación.

El Clonador PRO → STAGING deberá incluir `category_translation` en los tipos gestionados y remapear **ambos extremos** (`product_cat` → `product_cat`) a los IDs locales de STAGING. La auditoría y la limpieza de base de datos también deben reconocer ese tipo de relación y no clasificarlo como desconocido.

No cambiar slugs existentes en el piloto sin comprobar las plantillas/enrutadores internacionales y las redirecciones; estas plantillas todavía reconocen mercados por prefijos y raíces de categorías.

## Productos y próximos desarrollos

- Cada oferta VEVOR por mercado permanece separada, con sus propios datos comerciales y destino afiliado. Los 36 productos del primer piloto **no se eliminan ni migran por este importador**.
- Si se confirma que varias fichas representan el mismo modelo físico, una futura funcionalidad `product_translation` podría relacionarlas en `seo_relations`. **No forma parte de este importador**.
- Los informes agrupados por categoría lógica y desglosados por mercado son una **integración posterior**: añadir la relación en la base no cambia automáticamente las consultas actuales de Analista, Auditor, Ojeador o Dependiente.
- No se crean redirecciones 301 desde este servicio. Solo procederían tras identificar duplicados reales, decidir la URL canónica y validar que el cambio no rompa el piloto.

## Verificación previa a PRO

- [ ] PR #914 fusionado y código cargado en STAGING.
- [ ] El bloque de importación aparece sin PHP warnings o errores.
- [ ] CSV de muestra: altas/actualizaciones correctas y `_seo_locale` coherente.
- [ ] `seo_nodes` y `term_taxonomy.description` conservan los textos.
- [ ] Un segundo import del mismo CSV no crea duplicados.
- [ ] Rechaza una categoría española inexistente, locale incorrecto y relación contradictoria.
- [ ] El Clonador conserva relaciones, remapeando IDs de PRO a STAGING.
- [ ] No cambia categorías españolas, productos, checkout, rutas SEO ni URLs de forma inesperada.

**Código del servicio:** [`includes/import-export/category-translations.php`](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/blob/staging/includes/import-export/category-translations.php) una vez fusionado el PR #914.
