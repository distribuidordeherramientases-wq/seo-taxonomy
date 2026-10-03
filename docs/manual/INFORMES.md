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
