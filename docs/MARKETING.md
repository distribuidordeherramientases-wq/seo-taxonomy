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

### Imágenes de las tarjetas públicas

Las franjas públicas de campaña utilizan el mismo resolvedor compartido de imágenes que otras plantillas del catálogo.

Orden de resolución:

1. imagen local de Media, si el producto dispone de una;
2. imágenes externas activas del proveedor asociadas al producto;
3. compatibilidad con el resolvedor externo de proveedor;
4. logo/placeholder como último recurso.

Las imágenes externas se cargan directamente desde su URL de proveedor: **no se descargan ni se copian automáticamente a Media**. Esto permite mostrar productos cuyo catálogo gráfico se mantiene fuera del alojamiento local.

Si una URL externa falla en el navegador, la tarjeta intenta la siguiente imagen disponible del proveedor antes de caer al recurso de sustitución.

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


---

## Publicación social de campañas

Las campañas pueden insertarse en la agenda de Redes Sociales producto a producto.

Para cada producto, la imagen se resuelve con este orden:

1. imagen local de WooCommerce/Media, si existe;
2. imagen externa activa del proveedor;
3. otras imágenes activas del mismo proveedor como fallback.

Las imágenes externas **no se importan a Media ni crean attachments**. El sistema intenta leerlas en memoria para generar la creatividad de campaña. Si una URL remota no puede descargarse desde el servidor, se entrega directamente al publicador como fallback cuando la red admite URL remota.

La creatividad de campaña incorpora la fotografía real del producto junto con el nombre de campaña, nombre de producto, precio anterior, precio de campaña y descuento. Los PNG finales de campaña se guardan fuera de Media en `uploads/seo-social-campaigns/<red>/` como caché de publicación y se limpian automáticamente al superar 60 días.

En Facebook, las publicaciones de campaña fuerzan el formato con imagen aunque la conexión general esté configurada como «Enlace con vista previa». Las publicaciones normales conservan la configuración general de Facebook.

La vista previa del Programador muestra tanto el texto como la creatividad que se utilizará para el primer producto de la campaña.

### Horarios comerciales y prioridad editorial

La agenda separa las publicaciones comerciales de las editoriales:

- las **ofertas de campaña** se programan a las **18:00**;
- las entradas de la categoría **Noticias** se programan a las **20:00**;
- una oferta y una noticia pueden publicarse el mismo día en la misma red;
- las páginas y landings mantienen la fecha/hora manual del Programador.

Las Noticias pueden marcarse como **Prioritarias**. Para cada red, el Programador reutiliza los huecos editoriales futuros ya existentes y los ordena así:

1. Noticias prioritarias;
2. Noticias no prioritarias.

Una noticia prioritaria adelanta a las no prioritarias, que se desplazan a los siguientes huecos editoriales. Entre noticias con la misma prioridad se conserva el orden cronológico previsto. Este reordenamiento no mueve ofertas, páginas ni landings.

La importación CSV admite la columna opcional `prioridad` con valores como `si`, `1`, `true` o `prioritaria`. Para las Noticias, la hora indicada en el CSV se normaliza a las 20:00.

### Prioridad comercial y hora de publicación

Las ofertas de campaña se programan con una regla distinta al contenido editorial:

- hora fija de publicación: **18:00**, usando la zona horaria configurada en WordPress;
- una entrada o landing programada el mismo día **no bloquea** una oferta;
- puede haber, por tanto, una publicación editorial y una oferta en la misma red durante el mismo día;
- se limita a **una oferta de campaña por día y por red** para evitar saturación;
- si las 18:00 de un día ya están reservadas por otra oferta de campaña en esa red, el planificador utiliza el siguiente día disponible dentro de las fechas de campaña;
- los contenidos editoriales existentes no se mueven ni se reprograman.

El Programador ya no solicita separación mínima ni hora preferida para campañas. Esos controles siguen perteneciendo al contenido editorial/manual cuando corresponda; las ofertas usan su propia franja comercial.


---

## Calendario visual de publicaciones sociales

La pestaña **Marketing → Redes sociales → Calendario** muestra la agenda futura en formato mensual sin crear una fuente de datos nueva. Lee exactamente las mismas programaciones que utiliza el Programador y que aparecen en **Exportar agenda**.

La vista combina:

- **Entradas**, identificadas con un color propio.
- **Páginas / Landings**, con un segundo color.
- **Ofertas de campañas**, con un tercer color.

Cada evento muestra hora, red y título. El calendario permite navegar por meses y filtrar por red social.

El resumen mensual muestra:

- número de publicaciones;
- días ocupados;
- días libres futuros;
- días con más de una publicación en la misma red.

Los días con colisión real en una misma red se resaltan visualmente. La combinación intencional **oferta a las 18:00 + pieza editorial a las 20:00** no se considera colisión; sí se avisa cuando hay más de una oferta o más de una pieza editorial de la misma red en el mismo día. Debajo del calendario se mantiene una **vista compacta cronológica** para pantallas pequeñas y revisiones rápidas.

No se duplica ninguna programación ni se crea una tabla adicional: las entradas/landings proceden de la agenda social existente y las ofertas de los metadatos de programación de Campañas.


---

## Informes de rendimiento

La pestaña **Marketing → Informes** cierra el ciclo de medición comercial y contiene dos vistas.

### Campañas

Para cada campaña muestra:

- productos incluidos;
- publicaciones sociales;
- visitas desde la franja pública de campaña;
- visitas desde publicaciones sociales;
- interacciones;
- pedidos con atribución directa;
- facturación atribuida;
- conversión visita → pedido;
- pedidos, unidades y ventas de los productos observadas durante las fechas de campaña.

**Atribuido** significa que el pedido conserva una interacción firmada de campaña o red social. **Observado durante campaña** significa únicamente que el producto se vendió durante las fechas; no se presenta como venta causada por la campaña.

El detalle por producto separa visitas de la franja, visitas sociales, pedidos atribuidos, unidades y facturación observada.

### Redes sociales

La vista por red y publicación muestra publicaciones, visitas a la web, interacciones, pedidos, facturación y conversión. Las métricas de proveedor pueden actualizarse mediante los conectores disponibles.

Las visitas sociales utilizan el identificador firmado `seo_social_ref`. La atribución se conserva durante **30 días** y guarda solo identificadores técnicos de fuente/campaña/publicación, sin añadir PII. Al crear el pedido se registran metadatos privados de WooCommerce para reconstruir el recorrido.

Las publicaciones de campaña guardan también `campaign_id`, por lo que el informe puede relacionar:

`Campaña → publicación → visita → pedido`.

La franja pública usa `seo_campaign_ref` firmado. No añade parámetros UTM a enlaces internos para no alterar la atribución de Google Analytics. Sus clics se cuentan en la propia fila campaña-producto sin importar información personal.
