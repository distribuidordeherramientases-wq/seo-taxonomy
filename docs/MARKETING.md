# Marketing

Ruta administrativa: **SEO Taxonomy → Herramientas → Marketing**

Marketing agrupa la gestión comercial del sitio. Las pestañas disponibles dependen de los módulos activos. Actualmente incluye, entre otras funciones, **Campañas**, **Redes sociales / Programador** y opciones de estilo visual.

Esta página documenta especialmente el flujo de **Campañas**, ampliado en la versión **2.3.8.1**.

---

## Campañas

Ruta: **SEO Taxonomy → Herramientas → Marketing → Campañas**

La pantalla clasifica las campañas en:

- **En curso**
- **Próximas**
- **Finalizadas**
- **Deshabilitadas**

Cada campaña muestra su nombre, edición, periodo, número de productos, tipo y recurrencia.

### Crear una campaña

**Nueva campaña** crea una campaña nueva.

Campos:

- **Nombre**
- **Edición**
- **Tipo**
  - Calendario comercial
  - Táctica / puntual
  - Producto estrella
- **Repetición**
  - No recurrente
  - Anual
  - Nueva edición manual
- **Creatividad social**
  - Diseño 1 · Tarjeta oscura
  - Diseño 2 · Oferta clara
  - Diseño 3 · Banner intenso
- **Inicio**
- **Fin**
- **Campaña habilitada**

Al crearla se generan referencias internas estables:

- `campaign_key`: identificador portable de la campaña.
- `series_key`: identifica la serie a la que pertenece.

En campañas existentes ambos valores se muestran como solo lectura.

### Editar una campaña

**Guardar cambios** actualiza la campaña existente.

Se pueden modificar nombre, edición, tipo, repetición, creatividad social, fechas y estado habilitado.

Si se cambian las fechas, el sistema comprueba que ninguno de sus productos solape con otra campaña habilitada.

---

## Productos de la campaña

La tabla muestra:

- producto;
- SKU;
- precio habitual;
- oferta actual;
- precio de campaña;
- estado **Aplicado**;
- casilla **Quitar**.

### Guardar productos y precios

**Guardar productos y precios** actualiza el precio de campaña de cada producto y retira únicamente los marcados con **Quitar**.

Si un producto tenía la campaña aplicada, antes de retirarlo se intenta restaurar su oferta anterior.

### Añadir productos

El catálogo no se carga completo para evitar una consulta masiva.

Utilizar **Buscar por nombre o SKU**, seleccionar uno o varios resultados e indicar **Precio campaña**.

**Añadir seleccionados** añade el producto o actualiza su precio si ya estaba asociado.

Los productos variables quedan fuera de esta versión del flujo.

### Protección frente a solapamientos

Un producto no puede participar en dos campañas habilitadas cuyos periodos se solapen.

La validación se aplica tanto al añadir productos como al modificar fechas o importar información.

---

## Importar / Exportar campañas

La versión 2.3.8.1 permite transportar la campaña completa, no solo el calendario.

Se admiten:

- **JSON** como formato canónico;
- **CSV** para revisión y edición externa.

### Formato actual

Los registros se distinguen por `record_type`:

- `campaign`
- `product`

Columnas CSV actuales:

```text
record_type
campaign_key
series_key
name
edition
campaign_type
social_creative_template
start_at
end_at
recurrence
enabled
product_id
sku
campaign_price
position
```

Ejemplo estructural:

```csv
record_type;campaign_key;series_key;name;edition;campaign_type;social_creative_template;start_at;end_at;recurrence;enabled;product_id;sku;campaign_price;position
campaign;otono-2026;otono;Otoño;2026;calendar;design_1;2026-10-01T00:00:00+02:00;2026-10-31T23:59:59+01:00;none;1;;;;
product;otono-2026;;;;;;;;;;12345;SKU-EJEMPLO;49.90;1
```

Los valores del ejemplo son ilustrativos.

### Importar / actualizar

La importación realiza alta/actualización por `campaign_key`.

Por defecto trabaja en **modo fusión**:

- crea campañas inexistentes;
- actualiza campañas existentes;
- añade productos;
- actualiza precio y posición de productos ya asociados;
- no elimina productos que falten en el archivo;
- no elimina campañas que no aparezcan en el archivo.

### Sustituir completamente los productos

La casilla **Sustituir completamente los productos de las campañas incluidas** convierte las filas de producto de cada campaña incluida en la lista definitiva.

En este modo, los productos asociados localmente que no aparezcan en el archivo se retiran de esa campaña.

Usar esta opción solo cuando el archivo sea deliberadamente completo.

### Compatibilidad con archivos antiguos

El importador mantiene compatibilidad con los CSV/JSON anteriores que solo contenían calendario.

También reconoce archivos antiguos de productos sin `record_type`:

- si encuentra datos como `product_id`, SKU o `campaign_price` y no hay datos obligatorios de campaña, interpreta la fila como producto;
- en caso contrario la interpreta como campaña.

Para resolver el producto utiliza:

1. `product_id`;
2. SKU como respaldo.

---

## Crear nueva edición

**Crear nueva edición (+1 año)** crea otra campaña de la misma serie desplazada un año.

Conserva la identidad de serie, pero crea otro ID y otra `campaign_key`.

**No copia productos ni precios.**

La nueva edición debe completarse de forma independiente.

---

## Vaciar productos

**Vaciar productos** elimina todos los productos asociados, pero conserva:

- la campaña;
- nombre;
- edición;
- tipo;
- fechas;
- configuración social.

Antes de eliminar una relación cuyo precio siga aplicado, se intenta restaurar la oferta anterior del producto.

La interfaz solicita confirmación.

---

## Eliminar campaña

**Eliminar campaña** elimina definitivamente la campaña.

El proceso seguro es:

1. cancelar las acciones programadas de aplicación y restauración;
2. localizar los productos asociados;
3. restaurar los precios que todavía tengan la campaña aplicada;
4. eliminar las relaciones campaña-producto;
5. eliminar la campaña.

La interfaz solicita confirmación antes de ejecutar el borrado.

Una campaña finalizada legítima puede conservarse como histórico comercial. El borrado está pensado principalmente para pruebas, errores o campañas creadas accidentalmente.

---

## Aplicación y restauración automática de precios

Cuando una campaña habilitada entra en vigor, el sistema aplica a cada producto:

- precio de campaña;
- fecha de inicio;
- fecha de finalización.

Antes guarda una instantánea de:

- oferta anterior;
- fecha de inicio anterior;
- fecha de finalización anterior.

Cuando termina o se deshabilita la campaña, intenta restaurar esos valores.

### Protección de cambios manuales

Si alguien modifica manualmente la oferta mientras la campaña está activa y el precio actual ya no coincide con el precio de campaña, la restauración automática no pisa ese cambio manual.

---

## Flujo recomendado

Para una campaña normal:

1. definir fechas y tipo;
2. seleccionar productos a partir de criterios comerciales;
3. fijar precios de campaña;
4. importar en **modo fusión**;
5. revisar productos y precios;
6. exportar una copia si se necesita trazabilidad;
7. probar primero en **STAGING** cuando el cambio sea masivo;
8. promover a producción tras validar;
9. conservar las campañas finalizadas como histórico salvo que exista un motivo para borrarlas.

---

## Relación con otros módulos

Las campañas pueden alimentar o integrarse con:

- **Redes sociales / Programador**, para publicaciones comerciales;
- **Analista**, para propuestas basadas en señales internas y demanda;
- **Ojeador**, como fuente de información de competitividad y mercado;
- feeds comerciales, mediante el precio de oferta y su periodo de vigencia;
- plantillas públicas de campaña en la tienda.

La selección de productos y precios sigue siendo una decisión comercial; el módulo se encarga de almacenar, programar, aplicar y restaurar la campaña.
