# Informes

Ruta: **SEO Taxonomy → Informes**

## Pestañas

- Informes
- Panel
- Contenido
- Anomalías
- Analista

## Informes

Las tarjetas disponibles tienen:

- **Crear informe** [Proceso]: genera el informe elegido.
- **Cerrar informe** [Consulta]: cierra su vista.

## Panel y Contenido

Reúnen, según las fuentes disponibles:

- Google · Visibilidad orgánica.
- Últimos 28 días disponibles.
- Rendimiento de Posts.
- Rendimiento de Landing Pages.
- Señales externas.

Las tarjetas y gráficos son de lectura; no modifican contenido.

## Anomalías

Incluye controles de estructura y contenido como:

- Estructura Completa de Categorías.
- FAQs sin elemento relacionado.
- Clusters sin hubs primarios.
- Hubs primarios sin cluster.
- Hubs secundarios sin hub primario.
- Hubs primarios en múltiples clusters.
- Landings sin hub secundario.
- Landings sin categoría de producto asociada.
- Posts editoriales sin categoría de producto relacionada (`post_to_category`).
- Posts sin Vocabulary semántico activo.
- Categorías sin asignación estructural.
- Categorías de producto con 0 productos.
- Categorías duplicadas en múltiples Hubs Secundarios.

Acciones visibles:

- **Descargar JSON** [Exporta]: descarga un paquete correctivo con los posts/landings detectados, su contenido, categorías WordPress, relaciones con `product_cat` y Vocabulary.
- **Importar JSON** [Guarda]: aplica correcciones únicamente sobre IDs existentes; no crea ni borra contenidos, categorías o términos de Vocabulary.
- **Recalcular contadores** [Proceso].
- **Borrar FAQs de categorías desaparecidas** [Elimina].
- **Borrar FAQs de productos desaparecidos** [Elimina].
- **Seleccionar todas las eliminables** [Consulta].
- **Eliminar categorías seleccionadas** [Elimina][Producción].

Para poder eliminar una categoría con 0 productos, el sistema exige además que no tenga relaciones funcionales protegidas y que disponga de un único Hub secundario publicado. Antes del borrado se valida el destino y, al completarse, se crea/actualiza automáticamente un redirect 301 hacia ese Hub; los datos SEO propios se limpian mediante Data Layer.

Una anomalía es una señal; revisar siempre el objeto antes de ejecutar una eliminación.

## Analista

### Periodo

- selector **Periodo**.
- **Cambiar periodo** [Consulta].

### Bloques de análisis

La pantalla presenta, entre otros:

- Cómo avanzan nuestras posiciones.
- Movimiento de keywords.
- Cómo se posiciona el catálogo.
- Lectura rápida.
- Evolución en Google.
- Arquitectura que Analista tiene en cuenta.
- Palabras clave vigiladas.
- Nosotros frente a competidores.
- Palabras clave que debemos disputar.
- Demanda exterior · Google Trends.
- Señales de clientes que no debemos perder.

### Configurar comparación

Permite definir competidores y palabras que se quieren vigilar.

**Guardar comparación** [Guarda].

Analista carga los bloques bajo demanda para evitar calcular todos los informes cada vez que se abre la página.

### Plan de acción validado · Analista 3.8.1

El bloque **Plan de acción** sigue siendo **bajo demanda**: abrir Informes > Analista no calcula esta cola. Al pulsar Plan de acción se genera únicamente ese informe.

Antes de mostrar una instrucción, Analista 3.8.1 valida:

1. **Consulta ↔ entidad**: marca, modelo/variante, SKU/MPN cuando están disponibles, intención, categoría y URL.
2. **Destino**: una instrucción de edición necesita una URL local/canónica resuelta. Si no existe, la salida es **INVESTIGAR COBERTURA**.
3. **Volumen de evidencia**: impresiones, clics, número de consultas y base del periodo anterior. Las muestras pequeñas no se convierten en quick wins solo por posición.
4. **Preparación comercial**: stock/disponibilidad, precio y, cuando existen, proveedor y margen/comisión.
5. **Cobertura editorial existente**: categoría/producto y posts publicados de Dependiente, Ingeniero y Comparador. Se prefiere actualizar/enlazar antes que crear otra URL.
6. **Metadatos efectivos**: Analista distingue postmeta explícito de title/meta gestionados por plugin SEO o fallback de plantilla, para no generar falsos positivos de “meta inexistente”.

Los estados del plan son:

- **HACER AHORA**: destino y asociación validados, evidencia suficiente y confianza compatible con ejecución. Máximo 10; el sistema no rellena el cupo.
- **HACER DESPUÉS**: trabajo válido, pero menos urgente; en 3.8.2 también puede contener una oportunidad SEO válida temporalmente bloqueada por una dependencia comercial explícita.
- **INVESTIGAR**: conflicto de modelo, entidad no demostrada o destino sin resolver. Los bloqueos puramente comerciales ya no se clasifican aquí.
- **VIGILAR**: señal coherente pero todavía inmadura.
- **ESPERAR DATOS**: muestra demasiado pequeña o crecimiento sobre base insuficiente.
- **SIN ACCIÓN**: compatibilidad histórica para señales sin valor de trabajo.

Cada tarjeta muestra **consulta exacta, URL atribuida, tipo/confianza de match, impresiones, clics, CTR, posición, variación absoluta, base anterior, confianza, preparación comercial, responsable recomendado y dependencias**.

Los valores **Autoridad / Visitas / Ventas** son **scores internos de priorización**. No son métricas de autoridad de Google.

#### Umbrales configurables

En **Analista → Comparación**, el formulario de configuración incluye los umbrales internos de evidencia y matching. Son criterios operativos del proyecto, no reglas SEO universales:

- máximo de impresiones considerado muestra pequeña;
- impresiones consideradas fiables;
- volumen alto;
- base previa mínima para interpretar porcentajes;
- match parcial mínimo;
- match exacto mínimo.

#### Seguridad y responsabilidades

Analista **diagnostica y deriva**. No publica, no crea posts/URLs ni modifica categorías o productos automáticamente.

- **Auditor** conserva la gestión de anomalías/tareas.
- **Editora** revisa cambios de contenido.
- **Catálogo/proveedores** valida surtido, disponibilidad, proveedor, precio y margen.
- **SEO/taxonomía** resuelve asociaciones, destinos y enlazado.
- **Ingeniero / Solucionador / Comparador** aportan cobertura o evidencia; Analista no duplica sus workers.

En **Fuentes** se muestran las pruebas de aceptación de 3.8.1/3.8.2 y un diagnóstico separado de Action Scheduler para detectar colas vencidas que puedan afectar a la frescura de datos.


### Corrección del Plan de acción · Analista 3.8.2

3.8.2 mantiene intactos los demás módulos de Analista y modifica únicamente la **generación/explicación de tareas del Plan de acción**.

Cambios:

- antes de devolver **INVESTIGAR**, intenta resolver la consulta contra la taxonomía `product_cat` y el índice local de entidades ya conocido por Analista;
- un candidato automático solo se acepta si vuelve a superar la validación de entidad, URL e identificadores; las consultas con modelo no se degradan a categorías genéricas;
- **INVESTIGAR** queda reservado a conflictos de modelo, entidad no demostrada o destino sin resolver;
- una oportunidad SEO válida con datos comerciales incompletos pasa a **HACER DESPUÉS** con gate `BLOCKED_COMMERCIAL`, no a INVESTIGAR;
- una muestra insuficiente pasa a **ESPERAR DATOS**;
- el **score de oportunidad permanece estable** cuando existe una dependencia: los gates modifican ejecutabilidad/confianza, no la puntuación;
- cada bloqueo incluye `blocker_type`, `investigation_steps`, valor actual, condición de desbloqueo, responsable y `bucket_if_unblocked`;
- si se resuelve una dependencia comercial y el bucket base era HACER AHORA, la siguiente ejecución puede devolver la tarea a HACER AHORA sin subir artificialmente el score;
- el informe muestra un diagnóstico agregado de asociaciones auto-resueltas, bloqueos de entidad, bloqueos comerciales, evidencia insuficiente y tareas de score alto retenidas por un gate.

Ejemplo comercial: una categoría puede tener demanda SEO suficiente pero quedar temporalmente en HACER DESPUÉS porque faltan `stock_categoria`, `precio_categoria`, proveedor, margen/comisión o GA4. La tarjeta enumera exactamente los campos pendientes y qué debe ocurrir para desbloquearla.

### Informe competitivo profundo

Dentro de **Analista → Comparación**, después de guardar competidores y palabras vigiladas, aparece el bloque **Informe competitivo profundo**.

**Competidores configurados** [Consulta]: número de dominios guardados en la comparación. El informe profundo analiza como máximo 8 competidores por ejecución.

**Keywords vigiladas** [Consulta]: número de palabras/intenciones configuradas. Si no hay ninguna, Analista toma automáticamente una muestra limitada de consultas accionables de Search Console del dominio propio.

**Último informe** [Consulta]: fecha del último informe competitivo persistido.

**Generar informe competitivo** [Proceso][API]: ejecuta de forma explícita el informe. Abrir la pantalla no consume consultas externas. La ejecución puede usar la conexión compartida **SerpApi → ScraperAPI** para muestrear Google Search y realiza un rastreo público acotado de cada dominio.

El informe separa estrictamente:

- **dominio propio**: puede usar Search Console, GA4 y Bing, además de la evidencia pública;
- **competidores**: sólo utiliza evidencia pública observable y el muestreo SERP;
- **Google Trends**: se usa como contexto de demanda temática, nunca como tráfico estimado de un dominio.

Dimensiones comparadas:

- **SERP**;
- **Arquitectura**;
- **Producto**;
- **Enlazado**;
- **Confianza**;
- **UX**;
- **Técnico observable**.

Cada dimensión muestra una puntuación sobre 5 y un porcentaje de **confianza de evidencia**. Si faltan datos, se muestra **N/D**; la ausencia de datos no se transforma en cero ni penaliza artificialmente el índice.

El muestreo público revisa, de forma limitada:

- homepage;
- `robots.txt`;
- sitemap declarado en robots;
- una URL representativa encontrada en las SERP, cuando existe;
- title, meta description, canonical, H1, schema, enlaces internos y señales comerciales observables;
- señales de ficha como precio, stock, SKU/EAN, reviews, envío, devoluciones, garantía, especificaciones y compatibilidad, cuando la URL representativa parece una ficha de producto.

**Exportar JSON** [Exporta]: descarga el último informe completo, incluyendo matriz competitiva, keywords muestreadas, contexto propio, fortalezas, brechas, plan priorizado y límites de evidencia.

#### Límites

- Search Console, GA4 y Bing sólo describen el sitio conectado.
- La visibilidad de los competidores es un muestreo sobre las keywords analizadas, no una estimación de tráfico total.
- Google Trends no se interpreta como tráfico de dominio.
- El rastreo competitivo es una muestra pública y no sustituye Screaming Frog/Sitebulb.
- Core Web Vitals homogéneos y autoridad/backlinks requieren fuentes adicionales; si no están disponibles quedan como N/D.

