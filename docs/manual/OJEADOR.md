# Ojeador

Ruta: **SEO Taxonomy → Herramientas → Ojeador**

Barra: **Detener**, **Continuar / actualizar mercado** [API/Proceso], **Exportar JSON análisis**.

## Pestañas
**Análisis y recomendaciones**, **Posición de precio**, **Comparador de productos**, **Operación y consultas**.

## Posición de precio
**Exportar JSON precios**. Filtros Producto, Proveedor, Categoría, Estado de precio, Impresiones mínimas, Orden y Filas; **Aplicar filtros** / **Limpiar productos**.

Semáforo: verde = competitivo, amarillo = dentro/cerca del mercado, rojo = poco competitivo; sin color = falta comparable fiable.

## Comparador
Filtros Categoría, Producto/marca/modelo, Comercio, Precio min/max, Rating mínimo, Reviews mínimas, Orden, Filas y checkbox **Sólo con descuento**. **Aplicar filtros** / **Limpiar resultados**.

## Operación y consultas
Buscador de categoría/consulta, tabla de estado y log.

La pestaña mantiene sólo la configuración operativa propia de Ojeador: **Actualizar cada**, **Categorías por paso**, **Reutilizar consulta durante** y **Mantener el mercado actualizado automáticamente**.

Las credenciales de **SerpApi** y **ScraperAPI**, el límite local de SerpApi y el botón **Probar cadena SerpApi → ScraperAPI** se gestionan de forma central en **SEO Taxonomy → Herramientas → Conexiones con proveedores**, dentro del bloque **SerpApi + ScraperAPI · conexión compartida para Ojeador e Ingeniero**.

## Proveedores Google

Ojeador utiliza una cadena serial de proveedores:

1. **SerpApi** como proveedor primario mientras la conexión esté disponible y no haya alcanzado la cuota configurada/proveedor.
2. **ScraperAPI** como fallback automático cuando SerpApi no está configurado, alcanza cuota o devuelve error de red, HTTP, API o JSON inválido.

Una respuesta válida sin resultados no activa el fallback: se considera una consulta correcta con 0 resultados.

Para Google Shopping, ScraperAPI usa el endpoint estructurado `/structured/google/shopping`. Las respuestas se normalizan al mismo esquema interno que SerpApi para que Ojeador, Comparador y los snapshots de mercado no dependan del proveedor concreto.

La interfaz muestra el uso de SerpApi y el número local de peticiones realizadas a ScraperAPI. Las API keys nunca se incluyen en las exportaciones JSON.

Exportaciones: **JSON completo (auditoría)** y **log JSON**.
