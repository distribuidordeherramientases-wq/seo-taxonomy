# CHANGELOG — TaxoCommerce Templates

## 0.4.8 — 2026-11-01
- Protección de **consentimiento explícito tras actualización**: la opción `taxocommerce_templates_review_consent_v1` permanece a cero al instalar una versión nueva, por lo que las políticas públicas no se activan aunque el interruptor heredado `taxocommerce_templates_render_enabled` estuviera activado.
- La casilla visible **Aplicación de políticas** sigue siendo el único control de administración. Al guardar marcada establece el consentimiento y habilita las reglas; desmarcada retira primero el consentimiento de forma segura. No se pierden diseños, países, archivos, relaciones ni borradores.
- Todos los resolutores de plantillas de WordPress/WooCommerce, el enrutamiento PHP heredado, las vistas por país, los correos y el estado mostrado en el administrador utilizan el mismo estado efectivo, evitando discrepancias.
- Se mantiene la posibilidad de editar plantillas con el plugin instalado y políticas suspendidas. Las reglas VEVOR especiales del monolito no se modifican.
- Pruebas de regresión: actualización con opción antigua activa, nueva autorización explícita, suspensión de emergencia y conservación de configuraciones. No hay activación automática del nuevo enrutador ni despliegue a PRODUCCIÓN en este cambio.


## 0.4.7 — 2026-11-01
- **Interruptor maestro de políticas** visible en las dos pestañas de Templates: permite mantener el plugin activo y gestionar plantillas sin aplicarlas al frontend.
- Se reutiliza la opción histórica `taxocommerce_templates_render_enabled`; su valor se conserva durante la actualización. En instalaciones nuevas comienza desactivada.
- Con la casilla desmarcada, el plugin no registra filtros públicos de sustitución de plantillas para WordPress (`template_include`), comentarios, archivos y fragmentos WooCommerce ni clases de correo; tampoco carga el helper ES de escaparate ni ejecuta rutas por país mediante sus resolutores.
- La casilla es un control de emergencia que funciona incluso si las tablas `seo_templates`/`seo_nodes` no están disponibles. Tiene control de permisos administrativos y nonce.
- Se retiran los interruptores duplicados y ocultos: solo existe un control superior, con estado explícito y botón de guardar. El editor, los roles, los diseños, la biblioteca y los archivos almacenados siguen disponibles mientras las políticas están suspendidas.
- Al activar la casilla se recupera el comportamiento previo, respetando las rutas individuales, borradores y prioridad VEVOR. No se modifica ninguna plantilla pública, país ni asignación guardada.
- Ampliadas pruebas de regresión para confirmar que el estado OFF devuelve el archivo original de WordPress/WooCommerce, preserva correos, comentarios, el piloto VEVOR y los diseños almacenados.


## 0.4.6 — 2026-10-31
- Instancias de plantilla identificables con nombres autogenerados editables para una misma petición/rol, sin sobrescribir las rutas históricas de `seo_templates`.
- Cada plantilla guarda su identificador, petición/rol, archivo PHP opcional, países ES/US/DE/NL/FR y composiciones separadas por **país y dispositivo** mediante la opción `tct_template_designs_v1` (`autoload=false`); no se añade ninguna tabla SQL.
- El botón **Editar** de Enrutamiento abre exclusivamente la configuración guardada de esa plantilla. **Crear otra plantilla** permite múltiples diseños para el mismo tipo y rol, asignados a países distintos.
- El enrutamiento público puede usar plantillas PHP activadas explícitamente por país y rol, respetando el interruptor general y las rutas VEVOR con prioridad; detecta y rechaza solapamientos de países entre dos instancias activas de la misma ruta.
- Las composiciones visuales sin archivo PHP quedan como borradores hasta implementar y validar el renderizador modular. Los diseños nuevos no reemplazan páginas públicas ni se activan automáticamente.
- Se reutilizan los módulos y controles del compositor anterior, preservando los borradores FRONT existentes, las asociaciones legadas, la biblioteca y los formularios técnicos.
- Pruebas ampliadas para identidad, países, independencia de configuraciones, seguridad y fallback.


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
