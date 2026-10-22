# Gestor de menús de TaxoCommerce Core

Ruta: TaxoCommerce Core → Gestor de menús.

## Opciones recuperadas
El panel izquierdo permite incorporar Páginas, Entradas, Enlaces personalizados y Categorías. Si WooCommerce registra `product_cat`, también muestra Categorías de producto. Las listas tienen recientes/más utilizadas, listado paginado y búsqueda en todos los contenidos publicados; la selección se conserva al cambiar de página o pestaña.

El panel derecho muestra el menú nativo realmente guardado, su jerarquía y la distinción entre elementos automáticos y manuales. Los manuales se pueden quitar del menú sin borrar su página, entrada o categoría. Los automáticos se modifican en Jerarquía SEO, no desde el botón de eliminación.

## Controles arriba y abajo
Crear/sincronizar propuesta, abrir el editor nativo, ubicación y activación aparecen antes y después del contenido. Restaurar aparece en ambos lugares si existe copia de ubicaciones anterior. Los selectores de ubicación se mantienen sincronizados en la página. Abrir la pantalla no crea, sincroniza ni activa un menú.

La propuesta automática se conserva en un desplegable; no cambia su fuente `seo_relations`. Los añadidos son `nav_menu_item` nativos sin el marcador automático y sobreviven a una sincronización. Si el menú ya está activo, añadir o retirar un elemento modifica lo que ven los visitantes tras enviar el formulario.

## Validación
`php taxocommerce-core/tests/menu-editor-smoke.php`: 39 comprobaciones aisladas de callbacks, permisos/nonces, renderizado sin escrituras, controles duplicados/IDs únicos, búsqueda paginada, altas y protección de elementos automáticos/de otro menú. Workflow con PHP 7.4/8.4 y sintaxis JavaScript. Estas pruebas no sustituyen la comprobación funcional dentro del WordPress de STAGING.
