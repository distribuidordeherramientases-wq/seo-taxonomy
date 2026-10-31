# CHANGELOG — TaxoCommerce Templates

## 0.4.5 — 2026-10-31
- Enrutamiento abre por defecto **Mis plantillas**: solo asociaciones con archivo y estado activo, incluidas aquellas cuyo archivo falta para poder corregirlas.
- Filtros de trabajo **Mis plantillas**, **Por configurar** y **Todas**, conservando búsqueda, familia y paginación. Los fragmentos internos de WooCommerce quedan fuera de la vista de pendientes y solo se enumeran en el inventario completo, sin perder ninguna opción.
- El total ahora describe plantillas asignadas, rutas pendientes u opciones de catálogo, según el filtro, en lugar de llamar «relaciones» a las posibles rutas.
- Las instalaciones nuevas se inicializan **sin archivos asignados y con rutas inactivas**; permanecen disponibles todas las sugerencias para que el administrador las configure progresivamente. En instalaciones existentes no se modifica ni desactiva ninguna relación guardada.
- Se reutilizan los gestores y la tabla `seo_templates`; no se alteran los renderizadores públicos, el motor VEVOR ni las plantillas existentes. Se amplían las pruebas de regresión de vistas y migración.


## 0.4.4 — 2026-10-31
- La administración pasa a **Enrutamiento** y **Diseñador de plantillas**. Auditoría queda accesible como diagnóstico técnico, sin pestaña principal.
- Nueva tabla editable y filtrable que combina peticiones WordPress/WooCommerce, roles SEO registrados, asignación de archivo y estado del motor. Incluye los tipos públicos de contenido y taxonomías que registra WordPress.
- Cada relación se modifica desde el administrador y se guarda en `seo_templates`, mediante nonce, permisos y validación de archivos; se permite devolver una ruta al respaldo nativo de WordPress.
- Los enlaces de cada fila llevan al Diseñador para consultar o crear su archivo. Se mantienen los accesos anteriores y los formularios de asignación de páginas a roles, así como la biblioteca y el compositor FRONT.
- Se aclara que las rutas internacionales VEVOR tienen una prioridad especializada y que el enrutamiento configurado no equivale a haber comprobado la plantilla finalmente ejecutada. No se activa automáticamente el motor.
- Sin modificaciones de plantillas públicas, contenidos, catálogos, imágenes externas VEVOR ni reglas de campaña. Pruebas smoke ampliadas.


## 0.4.3 — 2026-10-31
- Unificada la administración de plantillas en solo dos pestañas: **Gestor de plantillas** y **Auditoría**.
- El gestor organiza el compositor FRONT, asociaciones de rol/archivo, biblioteca PHP y rutas WordPress/WooCommerce en secciones de una sola pantalla, que se abren con un clic. La sección no seleccionada no ejecuta consultas costosas.
- Se mantienen operativos los enlaces anteriores (`tab=composer`, `tab=roles`, `tab=editor`, `tab=requests`), sus filtros, permisos, redirecciones, subida y edición PHP.
- Conservadas las bases de datos, la composición FRONT y sus borradores, la asignación de roles y las plantillas públicas sin alteración. No se activa el renderizador modular.
- Añadidas comprobaciones de regresión para la navegación consolidada.


## 0.4.2 — 2026-10-31
- Clústeres de US/DE/NL/FR: presentación internacional adaptable con idioma, menú por país y contenido relacionado filtrado por mercado; sin elementos comerciales españoles ni descarga de imágenes de proveedor.
- Los clústeres españoles mantienen las plantillas originales de móvil y ordenador.
- Pruebas de aislamiento por país, navegación, marcado estructurado y coexistencia con el compositor FRONT.


## 0.4.1 — 2026-10-30
- Se añade el **compositor FRONT** como piloto administrativo dentro de TaxoCommerce Templates.
- Inventario cerrado de módulos con altas/bajas, ordenación mediante arrastre y controles de teclado, visibilidad y etiqueta opcional.
- Configuración independiente por **mercado (ES/US/DE/NL/FR)** y por dispositivo (**ordenador/móvil**), con nombre y rol automático `front_page`.
- Guardado de borradores con capacidades de administrador, nonce, saneamiento y lista blanca de módulos.
- **No activa el nuevo renderizador ni altera la portada existente, las asignaciones, las campañas o las imágenes VEVOR.** La activación pública queda para una fase posterior, después de probar la interfaz en staging.
- Se incluyen pruebas de validación de composiciones e invariantes del FRONT original.

> Este fichero documenta únicamente cambios verificables desde la versión 0.4.1. No reconstruye ni atribuye versiones históricas sin evidencia.
