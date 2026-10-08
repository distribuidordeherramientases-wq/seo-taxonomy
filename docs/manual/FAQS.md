# FAQs

Ruta: **SEO Taxonomy → Contenidos → FAQs**

La pantalla administra preguntas frecuentes asociadas a hubs SEO, categorías y productos, además de su informe de cobertura, calidad e interacción.

## Pestañas

- **Hubs SEO**: gestiona FAQs de clusters, hubs primarios y hubs secundarios.
- **Categorías**: gestiona FAQs de `product_cat`.
- **Productos**: gestiona FAQs de productos publicados.
- **Informe**: muestra cobertura, calidad, telemetría, duplicados y huérfanas.

## Clasificación y búsqueda

Los filtros se encadenan siguiendo la jerarquía SEO.

**Nivel de hub**: sólo aparece en Hubs SEO. Permite elegir Clusters, Hubs primarios o Hubs secundarios.

**Cluster**: limita la pantalla a un cluster concreto.

**Hub primario**: limita a un hub primario del cluster seleccionado.

**Hub secundario**: limita a un hub secundario del hub primario.

**Categoría**: aparece en Categorías y Productos. Limita a una `product_cat` concreta.

Los selectores jerárquicos se actualizan al cambiar la selección.

**Buscar por nombre o ID** [Consulta]: filtra los elementos visibles por título/nombre o ID.

**Presencia de FAQs** [Consulta]:
- **Con y sin FAQs**: no filtra por número de FAQs.
- **Solo con FAQs**: muestra destinos que ya tienen al menos una.
- **Solo sin FAQs**: muestra destinos sin FAQs.

**Aplicar** [Consulta]: aplica búsqueda y filtro de presencia.

**Limpiar** [Consulta]: restablece los filtros de la pestaña actual.

## Tabla de elementos

**Seleccionar todos**: marca o desmarca todos los destinos visibles.

**Casilla de cada fila**: selecciona el destino para una creación múltiple.

**FAQs**: número total de FAQs existentes en ese destino.

**Gestionar** [Consulta]: abre la gestión individual del hub, categoría o producto.

## Nueva FAQ múltiple

Permite crear la misma FAQ en varios destinos seleccionados.

**Pregunta** [Guarda]: texto de la pregunta. Máximo 255 caracteres.

**Respuesta** [Guarda]: respuesta editable con el editor de WordPress. No muestra botones de medios.

**Orden** [Guarda]: entero desde 0. Los valores menores se muestran antes.

**Activa / Mostrar esta FAQ**: si está marcada, la FAQ queda visible para el consumo público correspondiente. Si no, se guarda desactivada.

**Crear FAQ en los elementos seleccionados** [Guarda][Producción]: crea una copia independiente de la FAQ en cada destino marcado. No hace nada si faltan destinos, pregunta o respuesta.

## Gestión de un destino

La tabla muestra:

- **Orden**
- **Pregunta**
- **Activa**
- **Acciones**

**Editar** [Consulta]: carga la FAQ en el formulario de edición.

**Activar / Desactivar** [Modifica][Producción]: cambia sólo el estado visible de la FAQ.

**Eliminar** [Elimina][Producción]: elimina la FAQ mediante la capa de datos y pide confirmación. Es una acción destructiva y se registra con la operación correspondiente.

El formulario individual usa los mismos campos **Pregunta**, **Respuesta**, **Orden** y **Activa**.

**Guardar / Actualizar** [Guarda][Producción]: crea una FAQ nueva o actualiza la FAQ en edición.

## Productos · criterios de calidad

En la pestaña Productos aparece el panel desplegable **Criterios de calidad y recomendaciones de Google para FAQs de producto**.

El panel es informativo. Explica compatibilidad, uso, límites, instalación, accesorios, elección de modelo, mantenimiento, fuentes, cantidad recomendada y validación previa. No genera FAQs automáticamente.

## Informe

La pestaña Informe es de consulta y mantenimiento.

### Cabecera

**Exportar** [Exporta]: descarga el informe según los filtros de rendimiento disponibles.

### Bloques del informe

- cobertura general;
- calidad editorial;
- telemetría de renderizado/viewport/apertura;
- uso e interacción;
- explorador de rendimiento;
- calidad por nivel;
- duplicados;
- huérfanas;
- destinos no publicados.

Las métricas de interacción distinguen que una FAQ haya sido renderizada, haya entrado en viewport y haya sido abierta.

## Duplicados

El sistema agrupa preguntas repetidas dentro del mismo destino y elige una copia a conservar según señales como estado activo, aperturas, cargas, actualización e ID.

Las acciones de limpieza pueden aparecer a nivel de copia, grupo, selección o conjunto completo.

**Eliminar copia duplicada** [Elimina]: elimina una copia concreta y conserva la seleccionada como principal.

**Eliminar copias del grupo** [Elimina]: elimina las copias de un grupo conservando el keeper.

**Eliminar duplicadas seleccionadas** [Elimina]: elimina las copias marcadas.

**Eliminar todas las copias duplicadas** [Elimina]: limpieza masiva de mayor riesgo. Debe utilizarse sólo después de revisar el informe.

## Huérfanas

Una FAQ huérfana apunta a un destino que ya no existe de forma válida.

**Eliminar FAQ huérfana** [Elimina]: elimina una fila concreta.

**Eliminar grupo de huérfanas** [Elimina]: elimina las FAQs del mismo destino inexistente.

**Eliminar huérfanas seleccionadas** [Elimina]: elimina las filas marcadas.

**Eliminar todas las huérfanas** [Elimina]: limpieza global de riesgo crítico; requiere revisión previa.

Las operaciones destructivas pasan por la capa de datos y generan la trazabilidad disponible en el plugin.

## Relación con Solucionador

Las FAQs son una fuente editorial independiente de Solucionador. Una FAQ activa puede incorporarse a un dossier de Solucionador cuando existe una relación demostrable con `product_cat`.

El valor de una FAQ para Solucionador no depende de que Dependiente haya aprendido una pregunta equivalente.
