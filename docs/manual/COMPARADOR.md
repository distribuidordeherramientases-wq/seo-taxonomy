# Comparador

## Propósito

**Comparador** es el servicio de inteligencia comparativa de producto de SEO Taxonomy.

Su unidad de trabajo principal es la **categoría o familia comparable**. Cruza el catálogo propio de WooCommerce con el mercado ya observado por **Ojeador**, normaliza los ejes de comparación, conserva trazabilidad y genera un perfil persistente que puede entregar a **Solucionador**.

No sustituye al comparador interactivo de la tienda. Ese comparador sigue permitiendo al cliente seleccionar varios productos propios, ver diferencias y descargar la comparativa en PDF.

## Dos comparadores: no son el mismo servicio

En el proyecto conviven **dos funciones de comparación** con objetivos diferentes.

| Componente | Usuario principal | Qué compara | Fuentes | Resultado |
|---|---|---|---|---|
| **Comparador de tienda** | Cliente de la web | Entre 2 y 6 productos propios | WooCommerce + atributos canónicos del catálogo | Tabla comparativa en la tienda y PDF descargable |
| **Servicio Comparador** | Equipo editorial / SEO | Una categoría o familia frente a catálogo propio y mercado observado | WooCommerce + snapshots ya guardados por Ojeador + conocimiento disponible | Perfil comparativo, ejes, evidencia, JSON editorial, señal para Solucionador y seguimiento |

### Comparador de tienda

El comparador de tienda es una herramienta de **ayuda directa a la compra**.

Permite seleccionar entre **2 y 6 productos propios**, ver sus diferencias usando los atributos disponibles en el catálogo y descargar la comparativa en PDF.

Su función es responder a una pregunta concreta del comprador: **“¿en qué se diferencian estos productos que estoy valorando?”**

No estudia el mercado externo, no genera contenido editorial y no decide si debe existir un post.

El límite máximo de productos se administra desde **Contenidos > Comparador > Configuración** y se comparte con la comparación interactiva, Dependiente y el PDF para que no existan límites distintos en cada punto.

### Servicio Comparador

El servicio Comparador es una herramienta de **inteligencia comparativa y preparación editorial**.

Trabaja principalmente por **categoría**. Su función es responder a preguntas como:

- qué tipos o configuraciones existen dentro de una familia;
- cuáles son los ejes técnicos que realmente permiten distinguir productos;
- qué diferencias puede demostrar el catálogo propio;
- qué variantes aparecen en el mercado ya observado por Ojeador;
- qué datos están suficientemente cubiertos y cuáles siguen siendo desconocidos;
- qué material puede entregarse a Solucionador para decidir si crear, mejorar, fusionar o no publicar contenido.

Comparador **no es un redactor automático ni un sistema de publicación**. Prepara la evidencia y la capa comparativa; Solucionador conserva la decisión editorial y Editora ejecuta el contenido.

## Ubicación

**Contenidos > Comparador**

El servicio está organizado en tres pestañas:

1. **Configuración**: límites, umbrales de calidad, construcción/reconstrucción de perfiles y pruebas funcionales.
2. **Comparativas**: perfiles por categoría, revisión de ejes, capa editorial, Import/Export JSON, relación con Solucionador y post canónico.
3. **Rendimiento**: métricas de los posts de comparativas ya vinculados, reutilizando los datos de Analista.

## Qué produce un perfil comparativo

Cada perfil es una fotografía estructurada de una categoría comparable. Puede contener:

- categoría principal y categorías asociadas;
- número de productos propios;
- referencias externas vistas por Ojeador;
- referencias externas que han podido deduplicarse y comparar;
- ejes comparativos detectados;
- cobertura y confianza de cada eje;
- valores conocidos y valores `unknown`;
- fecha del snapshot de mercado;
- hash de las fuentes;
- estado del perfil;
- resumen generado por el sistema;
- capa editorial desarrollada;
- relación con el post canónico;
- historial de workflow.

El objetivo no es llenar todos los campos a cualquier precio. **Un dato ausente permanece desconocido.** Un eje sólo puede considerarse publicable cuando supera los umbrales mínimos de cobertura y confianza.

## Cómo interpretar los ejes

Un **eje comparativo** es una característica que ayuda a distinguir configuraciones o productos dentro de la categoría, por ejemplo:

- potencia;
- capacidad;
- presión;
- par;
- voltaje;
- frecuencia;
- carga;
- peso;
- velocidad;
- caudal;
- dimensiones;
- temperatura.

Para cada eje Comparador conserva, cuando existe:

- nombre normalizado;
- unidad;
- prioridad;
- cobertura;
- confianza medida;
- confianza mínima exigida;
- condición publicable;
- origen automático o ajuste manual.

Un ajuste manual **no puede saltarse los controles de calidad**: si no existe cobertura o confianza suficiente, el eje no se convierte en publicable sólo porque un usuario marque una casilla.

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

## Informes y salidas del sistema

Comparador no genera un único “informe”. Tiene varias salidas, cada una para una fase distinta del trabajo.

### 1. Comparativa interactiva de tienda

**Destino:** cliente.

**Contenido:** selección de 2–6 productos propios, atributos comparables y diferencias disponibles en WooCommerce.

**Salida:** vista en la web y PDF descargable.

Esta salida ayuda a comprar; no incorpora el estudio de mercado de Ojeador ni la capa editorial del servicio Comparador.

### 2. Informe interno del perfil comparativo

**Destino:** equipo interno.

Se visualiza en **Contenidos > Comparador > Comparativas** al abrir un perfil.

Resume:

- estado;
- número de productos propios;
- referencias externas vistas/comparables;
- número de ejes válidos;
- confianza;
- fecha del snapshot;
- texto de contexto generado;
- versión y origen de la capa editorial;
- ejes con cobertura y confianza;
- referencias representativas;
- post canónico vinculado;
- avisos de caducidad o necesidad de revisión.

Es el informe operativo para decidir si el perfil está suficientemente preparado antes de enviarlo a Solucionador.

### 3. JSON de contenido

**Botón:** `Exportar JSON de contenido`.

**Schema:** `seo-comparador-editorial-v1`.

Es el paquete pensado para sacar la evidencia del sistema, desarrollar una comparativa editorial y volver a importarla sin perder trazabilidad.

La parte de fuentes es **sólo lectura**. Incluye:

- categoría, slug y URL;
- perfil, estado, confianza, snapshot y hash;
- ejes, cobertura y confianza;
- productos propios;
- referencias externas representativas de Ojeador;
- valores conocidos y desconocidos;
- estado de verificación de los valores;
- fechas de observación;
- post canónico si ya existe;
- resumen de contexto generado.

La parte editable es `editorial` y, opcionalmente, `manual_axis_overrides`.

### 4. Importación de comparativa editorial

**Botón:** `Importar comparativa editorial`.

No es una importación de inventario.

Puede actualizar:

- título sugerido;
- extracto;
- texto comparativo desarrollado;
- tipos de producto;
- diferencias principales;
- criterios de compra;
- casos de uso;
- panorama de mercado;
- posición del catálogo propio;
- limitaciones editoriales;
- conclusión;
- overrides explícitos de ejes.

No puede reemplazar:

- productos WooCommerce;
- resultados o snapshots de Ojeador;
- valores automáticos;
- inventario;
- datos de visitas.

Al importar:

1. se valida el schema;
2. se comprueba categoría y perfil;
3. se compara el snapshot/hash con el estado actual;
4. se avisa si el texto fue redactado con fuentes antiguas;
5. se incrementa la versión editorial;
6. se registra origen `manual_import`;
7. el perfil queda en `needs_review`;
8. se registra el cambio en workflow.

Si existe un post publicado, la importación **no lo sobrescribe**. Si sólo existe un borrador vinculado, pueden mantenerse sincronizados sus metadatos internos de Comparador sin publicar nada.

### 5. JSON de visitas

**Botón:** `Exportar JSON de visitas`.

**Schema:** `seo-comparador-visitas-v1`.

Disponible para **28 o 90 días**.

Es el informe de rendimiento de las comparativas ya vinculadas a un post. Reutiliza Analista y no abre otra conexión con Google Search Console, Analytics o Bing.

Por cada comparativa puede incluir:

- perfil;
- categoría;
- post y URL;
- estado;
- periodo actual;
- periodo anterior;
- impresiones;
- clics;
- CTR;
- posición;
- número de consultas, cuando Analista lo proporciona;
- sesiones y vistas, cuando están disponibles;
- diferencia absoluta y porcentual respecto al periodo anterior.

Este JSON sirve para cerrar el ciclo: una comparativa publicada vuelve a ser observada por Analista y puede revisarse si su rendimiento o sus fuentes cambian.

### 6. Extracto público en categoría y producto

Cuando existe un post canónico publicado, las plantillas pueden mostrar un bloque ligero con:

- título;
- extracto;
- hasta cinco ejes/diferencias relevantes;
- enlace **Ver comparativa completa**.

La plantilla sólo lee datos persistidos. **No ejecuta Ojeador ni recalcula el perfil durante una visita del cliente.**

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

## Flujo operativo recomendado

Un ciclo normal de trabajo es:

1. seleccionar o construir una categoría comparable;
2. revisar productos propios y referencias externas deduplicadas;
3. revisar los ejes y retirar los que no tengan suficiente cobertura/confianza;
4. exportar el **JSON de contenido**;
5. desarrollar o completar la sección editorial;
6. importar la comparativa editorial;
7. resolver cualquier aviso de snapshot antiguo;
8. enviar el perfil a **Solucionador**;
9. Solucionador comprueba cobertura, duplicación y canibalización;
10. Solucionador decide `NO_ACTION`, mejorar/fusionar una pieza existente o `CREATE_POST`;
11. Editora revisa y publica;
12. Comparador vincula el post canónico y aplica la etiqueta `comparativas`;
13. Analista mide el rendimiento;
14. el **JSON de visitas** permite revisar resultados a 28/90 días;
15. si cambian el catálogo o las fuentes de Ojeador, el perfil vuelve a revisión o `needs_update`.

## Ejemplo conceptual

Para una categoría como **limpiadores ultrasónicos**, Comparador puede encontrar productos propios y referencias externas con capacidades, potencias o frecuencias distintas.

No concluye automáticamente que un modelo sea “mejor”. Primero determina qué diferencias están realmente demostradas y con qué cobertura. Después puede preparar un perfil que permita explicar:

- qué capacidades aparecen;
- qué diferencias de potencia/frecuencia son observables;
- qué configuraciones están presentes;
- qué casos de uso pueden documentarse con las fuentes disponibles;
- dónde encaja el catálogo propio.

Ese material puede convertirse en una comparativa editorial, pero la existencia de un post nuevo la decide Solucionador después de comprobar si ya existe una URL que deba mejorarse o fusionarse.

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

- Implementación principal del servicio: **#485**.
- Import/Export JSON editorial y JSON de visitas: **#498**.
- Ampliación de documentación Wiki sobre comparadores, informes y funcionalidades: **#520**.

La implementación se valida primero en **staging**. Producción requiere autorización expresa.
