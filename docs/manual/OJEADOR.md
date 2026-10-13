# Ojeador

Ruta: **TaxoCommerce SEO → Herramientas → Ojeador**.

Ojeador analiza oportunidades comerciales, precios y cobertura del catálogo. **Merchant es la fuente prioritaria en modo predeterminado**; los datos antiguos de Google Shopping se conservan como histórico para auditoría y comparación. No crea productos ni categorías automáticamente.

> **Cambio desde octubre de 2026:** se ha retirado la pestaña **Novedades** y desactivado su proceso de búsqueda Trends/Shopping/Search. Los registros previos no se borran. El descubrimiento de novedades técnicas corresponde a **Ingeniero**; los informes de productos populares se consultan ahora desde **Merchant**.

## Pestañas visibles

1. **Análisis y recomendaciones**.
2. **Posición de precio**.
3. **Comparador de productos**.
4. **Operación y consultas**.
5. **Merchant · Comparación gratuita**.

## Barra superior

- **Detener** [Proceso]: detiene un proceso de mercado activo.
- **Continuar / actualizar mercado** [API/Proceso]: inicia el escaneo tradicional de Google Shopping **solo si se ha seleccionado explícitamente el modo tradicional** y las conexiones están disponibles. En modo Merchant-only el backend bloquea el proceso; no sirve para sincronizar Merchant.
- **Procesos** [Consulta]: abre la administración de procesos.
- **Exportar JSON análisis** [Exporta]: exporta KPIs, recomendaciones, categorías, comercios y comparativas del histórico.
- **Versión Ojeador**: indica la versión del motor, no la actualidad de las fuentes.

## Análisis y recomendaciones

Lectura **comercial** de los datos de mercado previamente almacenados. Las tarjetas muestran cobertura de categorías, resultados externos, número de comercios, huecos de catálogo y oportunidades detectadas.

- **Recomendaciones comerciales**: prioridad, categoría, motivo, acción sugerida y señales de Analista. Una misma categoría puede figurar más de una vez por motivos distintos; **todavía está pendiente consolidarlas en una única tarea de Dirección** (issue #857).
- **Índices por categoría** [Consulta]: desglose plegable de catálogo, competencia, visibilidad orgánica y otras señales. Los índices son criterios de prioridad relativos, **no previsiones de ventas**.
- **Comercios más visibles en la muestra** [Consulta]: sección plegable con datos de vendedores observados previamente.
- **Abrir comparación de productos** [Consulta]: conduce a la pestaña de comparación.

**Importante:** mientras no se complete la validación del modo Merchant, los KPIs históricos siguen reflejando instantáneas de Shopping, no datos nuevos de Merchant. Consulta sus fechas antes de decidir precios.

## Posición de precio

Presenta comparaciones de productos del catálogo con las ofertas observadas en Shopping. Semáforo:
- **Verde:** precio competitivo.
- **Amarillo:** dentro o cerca del mercado.
- **Rojo:** precio desfavorable.
- **Sin clasificación:** faltan ofertas equivalentes suficientemente fiables.

Controles: **Producto**, **Proveedor**, **Categoría**, **Estado de precio**, **Impresiones mínimas**, **Orden**, **Filas**; **Aplicar filtros** y **Limpiar productos** [Consulta]. **Exportar JSON precios** [Exporta].

La comparación con ofertas individuales exige equivalencia de artículo (marca, referencia, GTIN u otros identificadores verificables). No debe confundirse con el benchmark agregado de Merchant.

## Comparador de productos

Consulta los resultados de mercado guardados por Ojeador; **no realiza una búsqueda de pago por cada apertura o filtrado**.

Filtros [Consulta]: **Categoría**, **Producto / marca / modelo**, **Comercio**, **Precio mínimo/máximo**, **Rating mínimo**, **Reviews mínimas**, **Orden**, **Filas**, **Sólo con descuento**. Botones **Aplicar filtros** y **Limpiar resultados**.

Los precios, vendedores y valoraciones provienen de las instantáneas conservadas. Contrasta su fecha y la equivalencia del producto antes de tomar decisiones comerciales.

## Merchant · Comparación gratuita

Esta pestaña permite probar la sustitución de consultas de pago por **Google Merchant Reports API**, sin eliminar ni sobrescribir los resultados anteriores.

### Conectar Merchant

- **ID de Merchant Center** [Guarda]: identificador numérico de la cuenta. No es el ID de GA4 ni el de Search Console.
- **Fuente de mercado** [Guarda]:
  - **Merchant solamente · 0 consultas de pago Ojeador** (modo inicial): bloquea las nuevas consultas Shopping de Ojeador. **No desactiva las llamadas externas de Ingeniero**.
  - **Shopping tradicional**: permite volver al proveedor SerpApi → ScraperAPI cuando se elige expresamente.
- **Guardar fuente y cuenta** [Guarda]: aplica la configuración y detiene el proceso Shopping activo si se selecciona Merchant-only.

La autenticación exige una **cuenta de servicio Google** configurada en el plugin, autorizada a acceder a Merchant Center, con el alcance de Merchant. La conexión OAuth de Search Console/Analytics **no basta**. También deben estar disponibles los informes de Merchant pertinentes. Si falla la autorización o la elegibilidad, se muestra el error de Google; **no se activa automáticamente una consulta de pago**.

### Consultar informes [API, sin créditos SerpApi/ScraperAPI]

- **Precios competitivos**: consulta `price_competitiveness_product_view` para España. Conserva benchmarks EUR por oferta propia identificada como `wc-ID`.
- **Productos populares**: consulta `best_sellers_product_cluster_view` (España, informe semanal). Permite estudiar productos populares que Google declara fuera de nuestro inventario.
- **Consultar siguiente lote Merchant (máx. 500)** [API]: ejecuta **una página** de un informe, guarda el cursor y muestra recuentos. Puede ser necesario repetir hasta completar.
- **Reiniciar lectura desde primera página** [Consulta/Proceso]: reinicia el informe desde la primera página en la siguiente ejecución permitida.

Los informes Merchant pueden tener límites de cuota o acceso propios; que no gasten SerpApi no significa que estén disponibles sin requisitos de Google.

### Comparar con Ojeador PRO

- **Importar línea base PRO** [Guarda]: carga un JSON reducido compatible (máximo 2 MB) con productos de referencia y precios de la instantánea anterior.
- El cuadro de comparación informa **productos de referencia**, **con benchmark Merchant**, **comparables** y **diferencias porcentuales** respecto a la mediana histórica de ofertas.
- **Benchmark Merchant** es una **referencia agregada de Google**. La mediana histórica es una estadística calculada sobre una muestra de ofertas individuales. **No son la misma métrica**; una diferencia no demuestra por sí sola error ni superioridad de proveedor.

### Productos populares ajenos al inventario

Muestra candidatos de Merchant no presentes en el inventario comunicado a Google, con marca, categoría, popularidad, evolución y fecha, cuando Google devuelva esos campos. **No crea ni modifica automáticamente categorías WooCommerce**.

## Operación y consultas

Consulta el estado de las categorías del histórico y el log de peticiones externas.

- **Buscador de categoría/consulta** [Consulta].
- **Resumen y log** [Consulta]: cobertura, fechas, peticiones y errores.
- **Ritmo de Ojeador** [Guarda]: **Actualizar cada**, **Categorías por paso**, **Reutilizar consulta durante** y **Mantener el mercado actualizado automáticamente**.
- **Guardar ritmo de Ojeador** [Guarda]: solo afecta al escaneo tradicional; Merchant se sincroniza manualmente desde su propia pestaña.
- **Exportar JSON completo (auditoría)** y **Exportar log JSON** [Exporta].

En modo Merchant-only no se reanudan automáticamente los escaneos de pago. Los históricos siguen visibles, pero **no deben interpretarse como precios actuales**.

## Conexiones compartidas de Google

Ruta: **TaxoCommerce SEO → Herramientas → Conexiones con proveedores**.

Las credenciales SerpApi y ScraperAPI se conservan para otros procesos autorizados y para el modo tradicional de Ojeador. La cadena antigua era **SerpApi primero y ScraperAPI como respaldo** si había error o cuota agotada; una respuesta válida sin resultados no activaba respaldo.

En modo Merchant-only, **Ojeador no consulta esos proveedores para su histórico por categorías ni para Novedades**. Ingeniero mantiene su propio uso de investigación externa, que debe administrarse por separado.

## Criterio de Dirección

Usar Ojeador para responder: **¿qué demanda detectamos?, ¿qué categorías potenciar?, ¿qué precios revisar?, ¿qué productos populares nos faltan?, ¿cuántas consultas de pago evitamos?**

Una simplificación adicional de la pantalla principal (4 bloques accionables, fuentes/fechas y sin datos técnicos masivos en primer plano) queda pendiente en el **issue #857, milestone 2.3.10**.

## Trazabilidad

- Retirada de Novedades: PR #856.
- Merchant-first y comparación paralela: PR #852.
- Simplificación del panel de Dirección: issue #857.

La documentación describe **STAGING** hasta que se autorice su paso a producción.
