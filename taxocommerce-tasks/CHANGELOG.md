## [Unreleased] — i18n WordPress ES/EN/FR/DE/NL (#2311)

- Migradas 67 etiquetas de la interfaz desde diccionarios regionales propios a gettext de WordPress con dominios y cadenas literales extraíbles.
- Añadidos cinco catálogos POT/PO/MO, incluido neerlandés, con prioridad de traducciones oficiales y fallback regional propio para 5 idiomas.
- Mantenidos los mismos identificadores, menús independientes, registro opcional en Core y reglas de tareas.
- Pendiente comprobar la interfaz completa, la selección del idioma por usuario y la calidad en WordPress real.

# TaxoCommerce Tasks — CHANGELOG

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
