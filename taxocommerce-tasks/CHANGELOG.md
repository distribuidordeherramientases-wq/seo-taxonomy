## [0.1.6] — 2026-11-07 — Cabecera visual uniforme para Tasks (#2336)

- Tasks utiliza la misma cabecera visual autónoma que el resto de plugins TaxoCommerce (título, versión y fecha de actualización).
- Se elimina el encabezado sencillo duplicado; se mantienen intactas las siete pestañas, navegación, tareas, categorías, calendario, manuales y autocheck.
- La cabecera se genera desde la plantilla común con gettext de WordPress para ES/EN/FR/DE/NL y funciona con o sin Core.
- Prueba de regresión de cabecera común y versión sincronizada con readme.
- Corregido el auditor GNU `.mo` de CI: acepta catálogos válidos sin tabla hash opcional; mantiene comprobaciones de tablas y punteros dañados, con pruebas adicionales. Esta corrección evita falsos bloqueos de despliegue.

## [0.1.5] — 2026-11-07 — Cabecera con versión y fecha (#2336)

- Cabecera de administración con título, versión actual y fecha de esta versión, sin modificar datos ni funcionalidades.
- Fecha de publicación persistente, independiente del reloj o FTP.

# TaxoCommerce Tasks — CHANGELOG

## 0.1.4 — 2026-11-07

- Calendario mensual con barras continuas desde fecha de inicio hasta vencimiento y segmentación por semanas, incluidos cruces entre meses.
- Distribución sin solapamientos visuales mediante carriles; continuidad entre semanas cuando es posible, barras de un día cuando solo hay una fecha.
- Leyenda por prioridad (baja/normal/alta/urgente), bloqueo rayado y completadas atenuadas; información accesible y apertura de la tarea al pulsar la barra.
- Consulta privada por fechas superpuestas al periodo visible, sin crear publicaciones ni cambiar tablas.
- CSS independiente y adaptable a pantallas pequeñas; documentación y traducciones nativas ES/EN/FR/DE/NL.
- Prueba de regresión para cruces de semana/mes, posiciones, fechas ausentes, seguridad y enlaces; Issue #2329.


## 0.1.3 — 2026-11-07

- Los manuales de usuario y técnico se muestran en sus pestañas de administración existentes, con contenido completo en ES/EN/FR/DE/NL mediante gettext literal de WordPress.
- Ampliados y compilados los catálogos de 72 a 126 entradas por idioma (POT/PO/MO); añadidas las etiquetas de los manuales y de la edición de categorías que faltaban en los catálogos anteriores.
- Compatibilidad con los paquetes oficiales de idioma y selección de locale de WordPress; los textos de cliente no se traducen ni publican.
- Pruebas de 126 mensajes por idioma, 2 manuales y cinco locales, preservando gestión de categorías, menús e independencia.
- Incidencia #2313. Sin modificación de tablas ni despliegue a producción.


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
