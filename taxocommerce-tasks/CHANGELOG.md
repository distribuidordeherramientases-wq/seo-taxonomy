# TaxoCommerce Tasks — CHANGELOG

## Fusión de traducciones gettext (Issue #2311)

- Incorporado gettext de WordPress para las 67 etiquetas existentes y cinco catálogos PO/MO.
- Se conservan las traducciones de respaldo y las funciones de manuales y edición de categorías de v0.1.2.
- Se mantiene la independencia de WooCommerce, los datos y la navegación opcional bajo Core.


## 0.1.2 — 2026-11-07

- Incorporados **Manual de usuario** y **Manual técnico** como pestañas de administración de lectura, completos en ES/EN/FR/DE.
- Añadida la edición de nombres de categorías privadas existentes (Editar, Guardar cambios, Cancelar), conservando su `id` y las relaciones con tareas.
- Reutilizada la acción POST protegida por nonce y `manage_options`; validación de ID y nombre único, con errores informados en la interfaz.
- Pruebas automatizadas de renombrado, altas, nombres duplicados, IDs inexistentes, formulario y cuatro idiomas; integradas en CI.
- Se conserva el menú opcional bajo Core, la autonomía sin Core y la privacidad absoluta de tareas y categorías.
- Issue #2313, seguimiento en STAGING y Focazul; producción excluida.


## 0.1.1 — 2026-11-07

- Corregida la navegación: Tasks pasa a figurar en el submenú de TaxoCommerce Core cuando Core está activo, sin generar un menú de primer nivel duplicado.
- Inscrito como módulo y extensión opcional de Core para aparecer en el catálogo de extensiones, con el mismo `admin.php?page=taxocommerce-tasks`.
- Sin Core, mantiene el menú principal propio y puede funcionar de manera independiente.
- Sin modificaciones de tablas, categorías, tareas, permisos o URLs; pruebas de menú de ambas modalidades y Plugin Check.
- Incidencia #2290; PR correctivo independiente de la implantación inicial.

## 0.1.0 — 2026-11-07

- Nuevo plugin autónomo de tareas internas, sin dependencia de WooCommerce ni TaxoCommerce Core.
- Tablas propias de tareas y categorías mediante dbDelta; sin posts, CPT, taxonomías públicas ni datos preinstalados.
- CRUD privado de tareas/categorías, prioridades, estados, responsables WordPress, inicio, vencimiento y finalización.
- Calendario mensual, filtros y panel de indicadores con gráficos CSS accesibles.
- Autocheck manual de solo lectura y descarga JSON con nonce y control de permisos.
- Etiquetas de administración internacionalizadas para ES, EN, FR y DE; WordPress gettext tiene prioridad.
- Issue #2290; validación mediante CI y WordPress Plugin Check antes de publicar.
