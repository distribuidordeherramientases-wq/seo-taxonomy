# Ojeador

Ruta: **TaxoCommerce SEO → Herramientas → Ojeador**

Barra: **Detener**, **Continuar / actualizar mercado** [API/Proceso], **Exportar JSON análisis**.

## Pestañas
**Análisis y recomendaciones**, **Novedades**, **Posición de precio**, **Comparador de productos**, **Operación y consultas**.

## Novedades

La pestaña **Novedades** amplía Ojeador con un radar de mercado que no parte de las categorías WooCommerce actuales.

### Frontera de exploración
- **Clusters**: fijan el perímetro comercial máximo.
- **Hubs primarios**: son las semillas de búsqueda.
- Hubs secundarios y categorías existentes se usan para contrastar cobertura, pero no bloquean el descubrimiento de familias nuevas.

### Fuentes
1. **Google Trends (SerpApi)**: consulta *Related Queries* en crecimiento y, si no hay resultados, *Related Topics*.
2. **Google Shopping (SerpApi → ScraperAPI)**: comprueba que el término tiene oferta comercial real y conserva ejemplos de productos/comercios como fuente.
3. **Google Search (SerpApi → ScraperAPI)**: valida contexto y aporta fuentes adicionales.

Cada oportunidad conserva enlaces de origen y la evidencia usada para puntuarla. ScraperAPI es fallback de Search/Shopping; Trends requiere SerpApi.

### Clasificación de oportunidades
- **Nueva categoría/familia**: no se encuentra una categoría equivalente.
- **Hueco en catálogo**: existe una categoría próxima, pero la familia parece cubrirse parcialmente.
- **Producto/tecnología en tendencia**: la categoría ya existe y la señal apunta a una variante o producto emergente.
- **Expansión estratégica**: oportunidad más adyacente que requiere revisión humana.

### Score
El score máximo es 100 y se muestra desglosado:
- Tendencia: 35.
- Mercado: 30.
- Afinidad con Cluster/Hub primario: 25.
- Hueco de catálogo: 10.

El score es una ayuda de priorización, no una orden de alta. **Ojeador no crea categorías ni productos automáticamente.**

### Uso
**Buscar novedades** ejecuta un lote acotado y con un tope duro de 8 peticiones de proveedor. Por defecto rota por los Hubs primarios para no repetir siempre el mismo territorio. Se puede seleccionar un Hub concreto, cambiar el número de semillas y candidatos y filtrar después por estado, tipo o score.

Estados humanos: **Detectada**, **Investigar**, **Vigilar**, **Descartada**.

**Exportar JSON Novedades** descarga semillas, último run, puntuaciones, cobertura, evidencia y fuentes.

## Posición de precio
**Exportar JSON precios**. Filtros Producto, Proveedor, Categoría, Estado de precio, Impresiones mínimas, Orden y Filas; **Aplicar filtros** / **Limpiar productos**.

Semáforo: verde = competitivo, amarillo = dentro/cerca del mercado, rojo = poco competitivo; sin color = falta comparable fiable.

## Comparador
Filtros Categoría, Producto/marca/modelo, Comercio, Precio min/max, Rating mínimo, Reviews mínimas, Orden, Filas y checkbox **Sólo con descuento**. **Aplicar filtros** / **Limpiar resultados**.

## Operación y consultas
Buscador de categoría/consulta, tabla de estado y log.

La pestaña mantiene sólo la configuración operativa propia de Ojeador: **Actualizar cada**, **Categorías por paso**, **Reutilizar consulta durante** y **Mantener el mercado actualizado automáticamente**.

Las credenciales de **SerpApi** y **ScraperAPI**, el límite local de SerpApi y el botón **Probar cadena SerpApi → ScraperAPI** se gestionan de forma central en **TaxoCommerce SEO → Herramientas → Conexiones con proveedores**, dentro del bloque **SerpApi + ScraperAPI · conexión compartida para Ojeador e Ingeniero**.

## Proveedores Google

Ojeador utiliza una cadena serial de proveedores:

1. **SerpApi** como proveedor primario mientras la conexión esté disponible y no haya alcanzado la cuota configurada/proveedor.
2. **ScraperAPI** como fallback automático cuando SerpApi no está configurado, alcanza cuota o devuelve error de red, HTTP, API o JSON inválido.

Una respuesta válida sin resultados no activa el fallback: se considera una consulta correcta con 0 resultados.

Para Google Shopping, ScraperAPI usa el endpoint estructurado `/structured/google/shopping`. Las respuestas se normalizan al mismo esquema interno que SerpApi para que Ojeador, Comparador y los snapshots de mercado no dependan del proveedor concreto.

La interfaz muestra el uso de SerpApi y el número local de peticiones realizadas a ScraperAPI. Las API keys nunca se incluyen en las exportaciones JSON.

Exportaciones: **JSON completo (auditoría)** y **log JSON**.
