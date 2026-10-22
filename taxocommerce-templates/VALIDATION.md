# Verificación de TaxoCommerce Templates — 22/10/2026

Base revisada: staging c66b9094dcd4c18697962491cf0fc8c5aa527c01, que incluye PR #1243.

## Resultado y alcance

No se acredita todavía el relevo completo del monolito dentro de WordPress STAGING.
No se ha activado el enrutador, modificado producción, desplegado ni alterado el monolito.
Las correcciones se entregan en una rama separada para revisión.

El módulo comparte seo_templates y seo_nodes; no copia automáticamente los archivos
antiguos ni sus dependencias. Solo incluye templates/template-default.php. La biblioteca
persistente puede contener más archivos en el servidor, pero no se ha podido inspeccionar.
Conservar las filas de asignación no demuestra que los archivos puedan ejecutarse sin
el plugin antiguo. Por ejemplo, las plantillas cluster y producto antiguas requieren
archivos auxiliares contiguos; producto también contiene decisiones de mercado.
No se han copiado ni sustituido a ciegas esos archivos.

## Correcciones

- La petición de página normal consulta single_type_page, ofrecida por el catálogo
  dinámico, después del rol explícito y antes de page/singular/index.
- La detección de dispatcher analiza tokens PHP: ignora comentarios y admite espacios
  y paréntesis en require/require_once del helper de variantes.
- El cambio o borrado de un rol protege relaciones con landing y landing_page.
- Una asignación cuyo archivo falta sigue seleccionada y se envía al guardar. Antes,
  la opción deshabilitada podía omitirse y provocar el borrado accidental de la asignación.
  El servidor rechaza el archivo ausente sin modificar la fila.
- El flujo exclusivo de despliegue de Templates ejecuta las pruebas antes de subir.
  El flujo general de staging no se ha modificado.

## Pruebas reproducibles

    php taxocommerce-templates/tests/smoke.php
    php taxocommerce-templates/tests/relations.php
    find taxocommerce-templates -type f -name '*.php' -print0 | xargs -0 -n1 php -l

Ejecutadas localmente con PHP 8.3.6: correctas. Los flujos mantienen PHP 7.4;
no se afirma un resultado remoto de CI hasta observar su ejecución.

Las pruebas usan servicios WordPress simulados y archivos temporales. Comprueban:

- Enrutador habilitado: páginas, entradas, categorías de blog/producto, etiquetas de
  producto, productos, portada, blog, tienda, carrito, pago, gracias, cuenta, búsqueda y 404.
- Prioridad de roles frente a asignación genérica, independientemente del orden SQL.
- Conservación del original sin asignación, con fila inactiva o archivo ausente.
- Protección de las rutas Dependiente y piloto que ya devuelve otro controlador.
- Variantes móvil/escritorio, principal de respaldo, dispatcher y variante opuesta.
- Fragmentos variable.php y variation.php de WooCommerce, template parts y comentarios.
  No se trata product_variation como una página pública independiente.
- Guardado real del texto PHP en biblioteca temporal, copia previa, confirmación,
  rechazo de sintaxis inválida, permisos y protección de archivos integrados.
- Relaciones landing/landing_page bloquean el borrado de rol sin escribir en BD.

No equivalen a probar consultas reales de WordPress, subidas multipart, interfaz,
renderizado de PHP arbitrario, cachés, Divi, AJAX de variaciones ni compra completa.
El análisis sintáctico no demuestra que una plantilla tenga todas sus dependencias.

## Validación pendiente antes del relevo

1. Confirmar URL y acceso de administración al WordPress STAGING y versión instalada.
2. Obtener copia recuperable de biblioteca, asignaciones, nodos, relaciones y opciones;
   registrar plugins activos y estado del enrutador antes del ensayo.
3. Inventariar cada asignación activa, archivo principal, variantes y dependencias;
   contrastar lo ya asignado en el nuevo gestor sin cambiar claves ni relaciones.
4. Migrar únicamente los archivos que falten, resolviendo dependencias del antiguo,
   con comparación de contenido y sin sobrescribir las plantillas existentes.
5. Validar rutas representativas y recuperación en un entorno aislado de STAGING:
   rol por página, entradas, categorías, productos simples/variables, variantes de
   dispositivo, tienda/carrito/pago, rutas especiales y páginas sin asignación.
6. Solo tras superar lo anterior, ensayar en STAGING el cambio de plugin con recuperación
   preparada. Comprobar también el caso en que el controlador antiguo ya no exista:
   conservar su resultado por nombre de archivo no migra las rutas internacionales.
7. Comparar asignaciones y relaciones antes/después. Si hay fallo, deshabilitar el nuevo
   enrutador y restaurar el estado previo de plugins/opciones; conservar los archivos y
   copias, sin borrar tablas compartidas. Purgar las cachés afectadas.

El acceso operativo y el inventario real faltantes impiden certificar “apagar el viejo,
encender el nuevo y mantener todas las plantillas” en este momento.
