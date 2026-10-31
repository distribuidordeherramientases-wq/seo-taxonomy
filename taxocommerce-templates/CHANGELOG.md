# CHANGELOG — TaxoCommerce Templates

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
