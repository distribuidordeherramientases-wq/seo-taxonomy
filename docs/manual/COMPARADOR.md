# Comparador

## Propósito

**Comparador** es el servicio de inteligencia comparativa de producto de SEO Taxonomy.

Su unidad de trabajo principal es la **categoría o familia comparable**. Cruza el catálogo propio de WooCommerce con el mercado ya observado por **Ojeador**, normaliza los ejes de comparación, conserva trazabilidad y genera un perfil persistente que puede entregar a **Solucionador**.

No sustituye al comparador interactivo de la tienda. Ese comparador sigue permitiendo al cliente seleccionar varios productos propios, ver diferencias y descargar la comparativa en PDF.

## Ubicación

**Contenidos > Comparador**

Pestañas:

1. **Configuración**
2. **Comparativas**
3. **Rendimiento**

## Responsabilidades

Comparador:

- conserva el comparador de tienda y hace administrable su límite de productos;
- identifica ejes comparativos por categoría;
- cruza productos propios con snapshots ya guardados por Ojeador;
- deduplica referencias externas equivalentes;
- conserva valores externos con procedencia, fecha y confianza;
- registra datos desconocidos como `unknown`; no inventa atributos;
- genera perfil estructurado, resumen editorial base y extracto;
- entrega una señal estructurada a Solucionador;
- vincula el perfil con el post canónico cuando Solucionador/Editora lo crean;
- muestra en categoría y producto solamente extracto + enlace persistido;
- consume métricas de Analista para los posts vinculados.

Comparador **no**:

- consulta Google Shopping;
- scrapea merchants;
- consulta GA4/GSC/Bing por su cuenta;
- decide crear una URL pública;
- publica automáticamente;
- sobrescribe un post ya publicado;
- declara “mejor”, “peor”, “profesional” o equivalentes sin evidencia.

## Arquitectura

Flujo principal:

~~~text
WooCommerce + Ojeador + conocimiento existente
                  |
                  v
              Comparador
      perfil + ejes + texto base
                  |
                  v
              Solucionador
 cobertura / duplicación / intención
                  |
                  v
 NO_ACTION | IMPROVE | MERGE | CREATE_POST
                  |
                  v
               Editora
                  |
                  v
          Post comparativa canónico
                  |
                  +--> categoría/productos: extracto + enlace
                  +--> Marketing: flujo normal evergreen
                  +--> Analista: medición
~~~

## Persistencia

Versión funcional actual: **1.1.0**.

Versión del esquema: **1.1.0**.

Tablas propias:

- `{$wpdb->prefix}seo_comparador_profiles`
- `{$wpdb->prefix}seo_comparador_axes`
- `{$wpdb->prefix}seo_comparador_products`
- `{$wpdb->prefix}seo_comparador_values`
- `{$wpdb->prefix}seo_comparador_editorial`
- `{$wpdb->prefix}seo_comparador_post_map`
- `{$wpdb->prefix}seo_comparador_workflow`

`SEO_Comparador_DB::maybe_install()` verifica versión y existencia del esquema. Si falta una tabla, ejecuta la instalación mediante `dbDelta()`.

Nunca fijar `wp_` como prefijo físico.

## Configuración

Parámetros administrables:

- máximo de productos seleccionables en el comparador actual de tienda: 2–6;
- máximo de productos propios procesados por perfil;
- máximo de referencias de Ojeador procesadas;
- máximo de ejes;
- mínimo de productos comparables;
- cobertura mínima de un eje;
- confianza mínima de un eje;
- máximo de referencias externas representativas.

Los ejes generados automáticamente pueden revisarse por perfil: etiqueta, unidad, prioridad, confianza mínima y condición publicable.

## Datos propios

Los productos WooCommerce aportan, cuando están disponibles:

- ID y SKU;
- marca/modelo;
- precio;
- peso;
- atributos;
- categoría;
- disponibilidad a través del comparador actual.

Los atributos nativos se consideran verificados cuando proceden directamente del producto.

## Datos externos

Comparador consume exclusivamente `seo_ojeador_get_category_market()`.

No inicia búsquedas externas.

Deduplicación preferente:

1. Google product ID;
2. marca + modelo;
3. título normalizado como fallback.

Merchant no forma parte de la identidad de producto cuando ya existe una identidad de modelo suficiente.

Los valores explícitos de potencia, capacidad, presión, par, voltaje, frecuencia, velocidad, caudal, temperatura, peso/carga y dimensiones pueden normalizarse cuando existe una regla determinista.

Si no existe dato explícito, el valor queda en estado **unknown**.

## Estados

Estados operativos:

- `detected`
- `profile_building`
- `needs_review`
- `ready_for_solucionador`
- `approved`
- `post_draft`
- `published`
- `monitoring`
- `needs_update`
- `blocked`
- `archived`

Cada cambio de estado se guarda en `seo_comparador_workflow` con estado anterior, estado nuevo, acción, motivo, usuario/proceso, origen y fecha.

## Integración con Solucionador

Comparador publica su contrato mediante:

`seo_solucionador_comparador_signals`

Un perfil sólo se envía manualmente a Solucionador cuando dispone de:

- mínimo de productos comparables;
- al menos un eje publicable;
- perfil no bloqueado.

Solucionador conserva la autoridad editorial:

- si ya existe cobertura suficiente: `NO_ACTION`;
- si existe pieza relacionada: mejorar o fusionar;
- si no existe cobertura y se cumplen los requisitos: puede decidir `CREATE_POST`.

Una señal de Comparador mantiene intención `comparison`; no debe transformarse automáticamente en intención comercial de categoría.

Cuando Solucionador crea el borrador de una comparativa aprobada, el perfil se vincula al post y se aplica la etiqueta WordPress **comparativas**.

## Integración pública

Las plantillas de categoría y producto no ejecutan el motor de Comparador.

Sólo leen resultados persistidos mediante `SEO_Comparador_Public`.

El bloque público puede mostrar:

- título del post canónico;
- extracto;
- hasta cinco ejes/diferencias relevantes;
- enlace **Ver comparativa completa**.

Nunca replica el artículo completo.

## Caducidad

Cambios en productos WooCommerce marcan el perfil para revisión.

Cuando Ojeador guarda un nuevo snapshot, publica el evento:

`seo_ojeador_category_snapshot_saved`

Comparador marca el perfil como pendiente de revisión/actualización. Si ya existe un post publicado y cambia materialmente el hash de fuentes, pasa a `needs_update`.

El post público no se sobrescribe automáticamente.

## Import / Export JSON

Desde la ficha de cada perfil Comparador existen dos flujos distintos.

### Exportar JSON de contenido

La operación **Exportar JSON de contenido** genera un paquete con schema:

`seo-comparador-editorial-v1`

Incluye como contexto de solo lectura:

- categoría y URL;
- perfil, estado, confianza, snapshot y hash de fuentes;
- ejes, cobertura y confianza medida;
- productos propios;
- referencias externas deduplicadas de Ojeador;
- valores normalizados, estado de verificación, confianza y fecha;
- post canónico existente, si lo hay;
- resumen de contexto generado.

Las únicas secciones editables/importables son:

- `editorial`;
- `manual_axis_overrides`.

La sección `editorial` soporta:

- `suggested_title`;
- `excerpt`;
- `comparison_text`;
- `product_types`;
- `main_differences`;
- `buying_criteria`;
- `use_cases`;
- `market_overview`;
- `own_catalog_position`;
- `limitations`;
- `conclusion`.

### Importar comparativa editorial

El importador:

1. comprueba el schema;
2. resuelve la categoría por slug/term_id y el perfil compatible;
3. compara `source_hash` y `source_snapshot_at` con las fuentes actuales;
4. avisa si el texto fue redactado con un snapshot anterior;
5. importa sólo la capa editorial;
6. aplica únicamente los overrides de ejes incluidos expresamente;
7. incrementa la versión editorial;
8. registra origen `manual_import`;
9. deja el perfil en `needs_review`;
10. registra el cambio en workflow.

El importador **no** sustituye productos, snapshots de Ojeador, valores automáticos ni inventario y no modifica un post publicado.

Los recálculos posteriores de Comparador actualizan el contexto generado, pero no pisan una `comparison_text` importada manualmente.

## JSON de visitas

La pestaña Rendimiento y la ficha de perfil permiten exportar un segundo schema:

`seo-comparador-visitas-v1`

Puede generarse para 28 o 90 días.

El JSON reutiliza exclusivamente los datos disponibles en Analista e incluye:

- perfil y categoría;
- post canónico y URL;
- periodo actual y anterior;
- impresiones;
- clics;
- CTR;
- posición;
- número de consultas cuando Analista lo proporciona;
- sesiones y vistas si están disponibles;
- variación absoluta y porcentual frente al periodo anterior.

Comparador no crea otra conexión con Search Console, Analytics o Bing para producir este JSON.

## Rendimiento

La pestaña Rendimiento usa `seo_analista_get_data()`.

Muestra, cuando Analista dispone de datos:

- impresiones;
- clics;
- CTR;
- posición;
- sesiones;
- vistas;
- estado del perfil.

Comparador no crea otra conexión con Search Console o Analytics.

## Marketing

Los posts de comparativa son posts WordPress normales con etiqueta `comparativas`.

No son Noticias ni promociones. Por tanto entran en el flujo editorial/social normal y evergreen existente, sin crear otra automatización.

## Pruebas RF

La pantalla Configuración ejecuta las comprobaciones CMP-001 a CMP-011 sin escribir datos ni llamar a servicios externos.

Cubren:

- conservación del comparador actual;
- normalización parcial sin inventar;
- deduplicación de merchants;
- umbral de cobertura de ejes;
- bloqueo por conflicto semántico;
- preferencia por mejorar/fusionar si existe cobertura;
- posibilidad de CREATE_POST sin cobertura;
- lectura pública persistida;
- transición a NEEDS_UPDATE;
- reutilización de Analista;
- tratamiento social como post normal.

## Seguimiento

Issue principal de implementación: **#485**.

La implementación se valida primero en **staging**. Producción requiere autorización expresa.
