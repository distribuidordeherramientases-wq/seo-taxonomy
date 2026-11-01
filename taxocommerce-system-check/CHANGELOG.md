# CHANGELOG — TaxoCommerce System Check

> Historial de las versiones independientes del plugin. El origen de los motores de diagnóstico está en el monolito TaxoCommerce SEO; no se asignan versiones retrospectivas a esa etapa.

## [Unreleased]

## [0.2.12]

* Integrados los cambios del PR #2016 sobre la corrección posterior del PR #2020: conservadas las consultas preparadas y las lecturas de diagnóstico actuales; añadida una prueba de regresión del orden de parámetros del UPDATE de Health Scan.

* Resueltos los casos pendientes de preparación SQL en informes de facturación, vocabulario, Health Scan y consulta de imágenes externas, manteniendo los identificadores mediante `%i` y los parámetros vinculados.
* Las consultas de diagnóstico de estado actual conservan resultados en tiempo real. Las excepciones a avisos de acceso directo/caché se acotan a las operaciones concretas y se justifican, sin ocultar alertas de seguridad generales.
* Verificada la procedencia de listas de parámetros SQL variables: enlaces de imágenes y escaneos usan plantillas de cláusulas e identificadores restringidas, con cada dato enlazado mediante `$wpdb->prepare()`; no se introducen datos SQL arbitrarios.
* Justificadas localmente las lecturas sin caché que comprueban el estado actual de datos; se conservan los mensajes de error y la respuesta de las comprobaciones.
* Ajustada la ubicación de las excepciones PHPCS a instrucciones individuales, evitando modificar el comportamiento de los avisos del resto de consultas del archivo.
* Actualizados los tests y el empaquetado; no se cambian las API históricas `seo_*`, las tablas ni el monolítico.
* PR y chequeo WordPress.org requeridos antes de desplegar; PRODUCCIÓN no incluida.


## [0.2.11]

* Corregidos identificadores `%i` de Facturación (sin doble entrecomillado), sondeo de servicios, consultas de imágenes y escritura del inventario Health Scan; filtros de diagnóstico protegidos contra parámetros no escalares.

* Compatible con WordPress.org sin renombrar APIs públicas heredadas: las excepciones de prefijos se acotan exclusivamente a archivos del motor histórico `seo_*`, que sigue compartiendo interfaces con el monolítico. Ninguna supresión global en el repositorio.
* Anotadas individualmente las consultas directas necesarias para verificar estados actuales del servidor y las operaciones sincronizadas del programador; no se introduce caché que altere los diagnósticos.
* Documentadas las consultas preparadas con fragmentos SQL de lista generados a partir de estructuras internas y datos vinculados por `$wpdb->prepare()`, sin omitir el chequeo de seguridad para SQL no validado.

* Seguridad SQL: preparados con `%i` los identificadores de las ocho consultas `EXPLAIN` de `seo_system_mysql_audit_explain_plans()`; se mantienen el nombre canónico de cada tabla, los filtros y los índices inspeccionados.
* Endurecidas las entradas `tcs_task`, `tcs_filter`, `tcs_search` y `tcs_paged` contra parámetros GET/POST no escalares, sin cambiar las acciones válidas ni las rutas.
* Nueva prueba CLI de contratos SQL y entradas y matriz de CI PHP 7.4/8.4; ZIP sin archivos `tests/` ni archivos ocultos como antes.
* Continúan pendientes y documentadas las advertencias de funciones/consultas heredadas (issue #1958). Se exige una nueva revisión del paquete y comprobación funcional en STAGING antes de considerar WordPress.org.
* Sin cambios en el plugin monolítico ni publicación en PRODUCCIÓN.


* Corregir la introspección del esquema de facturación con `%i` sin concatenar identificadores SQL; pasar explícitamente listas de parámetros a `wpdb::prepare()` en las consultas semánticas y en la recuperación de Health Scan.
* Restringir los selectores de navegación a entradas escalares, conservar únicamente las excepciones documentadas de lectura y actualizar las etiquetas del `readme.txt` a términos admitidos por WordPress.org.
* El escaneo ampliado del paquete de GitHub detecta numerosas advertencias pendientes de prefijos heredados (`seo_*`) y consultas diagnósticas: esta versión no se considera validada para WordPress.org hasta resolverlas con comprobación de compatibilidad.

* WordPress Plugin Check: endurecer preparación SQL de tablas WordPress y TaxoCommerce mediante placeholders `%i`, preparar límites `%d`, IDs de conjuntos `%d` y parámetros de filtro, preservando consultas semánticas, Data Layer, Health Scan y auditorías HTTP.
* Sanear y validar el tipo de parámetros GET/POST del diagnóstico; mantener los nonces exigidos para acciones y documentar las lecturas de navegación que no modifican datos.
* Usar la API `wp_count_posts()` para recuentos de productos y páginas publicados.
* Generar el ZIP instalable exclusivamente con archivos operativos, sin `tests/`, ficheros ocultos ni respaldos. Las pruebas CLI continúan en el repositorio y en GitHub Actions.
* Mantener los procesos, informes, tablas históricas y compatibilidad con WordPress 6.2+ / PHP 7.4. Issue #1958; sin despliegue a PRO.

## Versiones independientes

### 0.2.10

* Add a dedicated, administrator-triggered **Auto Test** tab with grouped, non-destructive checks for startup, permissions, PHP diagnostics, module registration, HTTP audit hooks and exports.
* Distinguish checks that pass, fail or are not executed / not applicable; no HTTP probes, mail, cron execution, database writes or report persistence are performed.
* Add a **Manual** tab with indexed Spanish User Manual and Technical Manual (H2/H3), describing the current System, Plugin Validation and HTTP screens, data/privacy controls, limitations, compatibility and diagnostics.
* Add smoke regression tests and execute them in System Check CI.

### 0.2.9

* Update WordPress.org metadata for the standalone System Check package.
* Keep gettext calls in the standalone text domain and use prepared SQL table identifiers where required by the existing checker.

### 0.2.8

* Provide standalone system and plugin diagnostics with compatibility routes.
* Include independent PHP checks and optional bounded local HTTP URL audits.
* Preserve administrator-controlled exports, reports and notification settings.

## Origen anterior a las versiones independientes

Los diagnósticos de servidor, la validación de plugins y las auditorías de URL proceden de TaxoCommerce SEO. No se documentan aquí supuestas versiones independientes anteriores a la separación.
