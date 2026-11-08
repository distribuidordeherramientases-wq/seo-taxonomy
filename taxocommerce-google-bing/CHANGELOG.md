## [1.1.27] — 2026-11-08 — Historial plegable en cabecera (#2382)

- Desplegable cerrado por defecto inmediatamente debajo del título, con el historial propio de `CHANGELOG.md`; sin nueva pestaña ni dependencia de otros plugins.
- Textos del título preparados para ES/EN/FR/DE/NL, salida escapada, desplazamiento vertical y mantenimiento de menús y funciones existentes.
- El paquete instalable y la subida incremental incluyen únicamente el historial raíz necesario. Sin cambios en base de datos ni servicios.

## 1.1.25 - 2026-11-08 - Consentimientos: textos legales editables y ciclo de vida (#2366)

- Nueva pestaña Consentimientos de Insights con mensaje público y texto legal ampliado editables en ES/EN/FR/DE/NL, etiquetas de botones y URL de política de cookies.
- Banner integrado opt-in, botones Aceptar, Rechazar y Configurar, categorías por finalidad, revocación y revisión legal obligatoria antes de habilitarlo.
- Opción `taxocommerce_insights_consent_settings` en `wp_options`; no se crean tablas para los textos ni se registran datos personales del visitante en el servidor. Instalación añade borradores, desactivación conserva los datos y desinstalación definitiva elimina la opción.
- Clarity ConsentV2 e integración opcional WP Consent API; protección de caché compartida para no servir etiquetas consentidas a terceros.
- Las pruebas de navegador y proveedores reales siguen pendientes en STAGING antes de habilitar en PRO.

## 1.1.24 - 2026-11-08 - Analysis independiente (#2345)

- Eliminar condiciones basadas en `SEO_SYSTEM_URL` y `SEO_SYSTEM_VERSION` de la pestaña Analysis.
- Cargar JavaScript desde Insights y estilos desde su propio `analista-informe.php`, incluso con TaxoCommerce SEO desactivado.
- Usar el motor `seo_analista_*` ya incluido en `includes/analysis`, sin cambiar once informes, AJAX, datos ni configuraciones.
- Añadir prueba de regresión específica para asegurar la independencia de ficheros. Validación remota en STAGING con SEO apagado todavía necesaria.

## 1.1.23 - 2026-11-07 - Independencia Cloudflare (#2339)

- Migrar la API de Cloudflare (solo lectura) y controles de zona/token a Insights Connections.
- Mantener la opción cifrada y el formato de token original para no perder acceso tras desactivar SEO antiguo.
- Exponer pruebas explícitas de token y zona, sin escritura en DNS/WAF/SSL.
- Pendiente: prueba autenticada en STAGING con monolito desactivado, y migración GitHub Runner.

## [1.1.23] — 2026-11-07 — Cabecera administrativa de versión y fecha (#2336)

- Muestra título, versión activa y fecha de publicación en el administrador mediante Release Date.
- La fecha no depende de la hora de consulta ni del momento de copia por FTP. Sin cambios en servicios, datos, menús ni permisos.

## 1.1.22 — 2026-11-07 — Migración de comercio electrónico GA4 (#2334)

- Pestaña propia «Comercio electrónico» en Insights, separada de Cabeceras y Connections.
- Activación independiente, selector de GA4 únicamente de etiquetas activas, control de consentimiento y bloqueo con medición antigua activa.
- Motor propio de eventos de WooCommerce, sin depender del interruptor SEO heredado; desactivado por defecto hasta la validación funcional.
- Tests de regresión y despliegue homogéneo STAGING + Focazul; no cambia credenciales o etiquetas en PRO.

## [Unreleased] — Traducciones de interfaz Insights (#2062)

- Traducidas 97 cadenas de menús, tablas, comparaciones SEO, métricas de tráfico, permisos y recomendaciones en cada uno de los idiomas EN/FR/DE/NL (388 entradas gettext completadas).
- Regenerados los cuatro catálogos MO (en_US, fr_FR, de_DE, nl_NL) manteniendo exactamente los `msgid` originales y sin alterar datos comerciales ni resultados analíticos.
- Pendiente completar el resto del catálogo: 686 mensajes vacíos en EN/FR y 685 en DE/NL, comprobar la carga de idioma real, PHP/JS fuera de gettext y Plugin Check. El castellano está cubierto por el texto fuente donde corresponda, pero tiene entradas `msgstr` vacías.

## [Unreleased] — Reutilización segura de traducciones (#2307)

- Completadas únicamente entradas gettext PO vacías con traducciones idénticas y no ambiguas ya existentes en otros catálogos TaxoCommerce.
- Regenerados los MO de los idiomas modificados. Se conservan los textos, identificadores técnicos, funciones y datos anteriores sin cambios.
- Esta corrección es parcial: los textos restantes, las cadenas fuera de gettext y la validación visual por idioma siguen abiertos en #2062.

## [1.1.21] — 2026-11-07 — Google Analytics mediante scripts de WordPress (#2299)

- GA4 configurado en Insights usa el sistema nativo `wp_enqueue_script()` y `wp_add_inline_script()` en lugar de emitir una etiqueta script externa directamente.
- Se conservan los identificadores, la edición del inventario de etiquetas y la autorización previa de estadísticas. No se duplican cargas cuando Google Analytics ya se gestiona mediante conexiones antiguas.
- Prueba de regresión para consentimiento, etiquetas y encolado; verificación mediante Plugin Check oficial antes de fusionar.

## [Unreleased] — Regional WordPress locale fallback (#2295)

- Respeta primero los paquetes oficiales de WordPress .mo y .l10n.php para el locale exacto.
- Si falta un catálogo local regional, usa el catálogo existente del mismo idioma (es_ES, en_US, fr_FR, de_DE o nl_NL); no cambia la configuración ni el idioma del usuario.
- Sin cambios en lógica, datos, menús o dependencias del plugin. Las traducciones de contenido incompletas siguen abiertas en #2062.

## [1.1.17] — 2026-11-06 — IndexNow independiente de Cloudflare (#2259)

- Emisor IndexNow opcional por tienda dentro de Insights > Connections > Bing, desactivado por defecto para evitar duplicaciones con proveedores SEO o CDN.
- Clave pública aleatoria generada por instalación, URL de verificación virtual y cola deduplicada de hasta 1000 cambios; envío por WP-Cron de 100 URL como máximo, con reintentos controlados para 429, 5xx y errores de red.
- Notifica cambios de entradas, productos y taxonomías publicadas, sin enviar URL de otros dominios ni contenido privado. Formulario para notificar una URL concreta del sitio.
- Estado visible: URL de clave, pendientes y último HTTP; sin dependencia de otros plugins TaxoCommerce ni credenciales Bing Webmaster.
- Se elimina la suposición histórica de que Cloudflare siempre gestiona IndexNow. Activarlo y comprobar la URL de clave en la tienda pertinente antes de esperar avisos Bing.
- Test offline de permisos de dominio, cola, deduplicación y reintentos; ningún envío a producción.

## Unreleased - Familia i18n #2062


## [1.1.16] — 2026-11-03

- Pestaña «Autocheck» con descarga JSON ya existente y conservación de las comparaciones antes/después.
- Issue #2150, PR #2151; rutas de pestaña y acciones previas conservadas.

- Dominio gettext, carga de catalogos locales, etiquetas de menús y textos HTML de salida estáticos; catálogos PO/MO sincronizados con el texto fuente.

# CHANGELOG — TaxoCommerce Insights

## [1.1.15] — 2026-11-03

- Cabecera común de la familia basada en Core: icono, nombre, descripción, versión y diseño adaptable.
- Navegación y funciones originales conservadas, sin dependencia de Core. Issue #2137.

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## Versiones independientes

### En desarrollo — STAGING (posterior a 1.1.14)

* Limpieza de Insights exclusivamente en STAGING (issue #1943): comprobar el directorio remoto `tests/`, retirar solo las 12 pruebas de desarrollo conocidas si aún existen y fallar ante archivos ajenos o si la carpeta sigue presente. Comprobar también y retirar, con copia ZIP y verificación SHA-256 previa, cinco clases PHP descartadas en el historial del 27/10/2026 (`executive`, `analyst`, `board`, `market` y `prepared`), si aún permanecen instaladas. Las pruebas siguen en GitHub y CI, pero no en el paquete instalado. El despliegue general de otros plugins se omite en esta integración; sin cambios en PRO ni en el monolítico.

* Documentadas 26 excepciones PHPCS precisas para constantes `SEO_GOOGLE_*` heredadas utilizadas por el módulo, sin renombrarlas ni cambiar valores, opciones guardadas, conexiones o APIs del monolítico (issue #1945). Sin exclusiones generales del Plugin Check.

* Unify the former **Qué hacer** and **Plan de acción** reports into one executive Analysis view while preserving the original specialized reports.
* Build one prioritised strategic shortlist from GA4 funnel investigations and up to five evidence-backed SEO/GEO signals drawn from Analista, Auditor, Merchant and Product JSON-LD; the complete validated roadmap remains available below.
* Never equate referred AI traffic with brand mentions, infer individual checkout abandonment from aggregate events, or claim direct GPTCore integration.
* Reuse a single decision-plan computation for the GEO summary and roadmap, keep Analytics and source snapshots read-only, and add standalone regression coverage.
* Preserve the historical SEO plugin and `changelog.txt` unchanged. No WordPress.org release or production deployment in this change.

### 1.1.14

* Use the TaxoCommerce Insights plugin prefix for seven new GEO functions flagged by WordPress Plugin Check.
* Update all internal report, JSON, AJAX bridge and smoke-test references without changing saved data, provider calls or decision logic.

### 1.1.12

* Align the published WordPress.org stable tag with the plugin's 1.1.12 code version, retaining existing provider connections and Analysis reports.

### 1.1.11

* Add dedicated Auto Test tab with grouped non-destructive checks for Insights, providers, reports, Trends and Analysis.
* Preserve a per-site, per-user BEFORE baseline across updates and compare it with AFTER results; export safe JSON reports.
* Clearly identify skipped external API and OAuth integration tests instead of reporting false success.

### 1.1.10

* WordPress.org Plugin Check remediation: fix migrated Analista text domains and translator notes, and use a safelisted OAuth redirect.
* Document nonce-protected admin handlers; improve input validation and preserve the existing connections, Analysis reports, Trends and manuals.
* Build a clean distribution ZIP without development tests or FTP synchronization metadata and add official Plugin Check to continuous integration.

### 1.1.9

* Add User Guide with separate User Manual and Technical Manual, responsive layout and indexed H2/H3 sections.
* Describe active Connections, Analysis, Search Console, Analytics, Bing and Trends workflows; report persistence and OAuth compatibility.
* Keep provider calls, credential handling, reporting and all existing functions unchanged.

### 1.1.8

* Add an automatic Google Trends report for Spanish clusters and primary hubs, building on the independent Trends workload manager.

### 1.1.7

* Scope Google Trends to Spain (geo=ES) by cluster/primary hub and run work with a bounded load manager.

### 1.1.6

* Show explicit provider connection states (connected, configured but unverified, pending, error and disabled), per-service checks and latest test timestamps.

### 1.1.5

* Restored the previous Analista 3.9.0 engine as the Insights > Analysis tab instead of rebuilding it.
* Preserved its 28/60/90-day views, lazy partial analyses, action plan, comparison, structure, content, trends and JSON export.
* Adapted legacy admin URLs and assets to the standalone Insights plugin.

### 1.1.4

* Removed Analyst, Executive Reports, Market, their supporting code, assets, and tests.
* Kept Connections, Google Search, Google Analytics, Bing, and Google Trends.
* Removed Market-only YouTube and SerpApi provider configuration.

### 1.1.3

* Reuses the authorized Google Search Console OAuth property and credentials already configured in the historic plugin, without copying or modifying its secrets.
* Tries explicitly paired modern OAuth, legacy OAuth, and service-account properties during administrator-initiated report downloads; a 403 from one identity no longer masks another valid connection.
* Preserves the previous report schema and saves the property/auth source used for transparency.
* Fixes the HTTP status inspection in WP_Error and adds deterministic regression tests for fallback behavior.

### 1.1.2

* Added a concise commercial decision panel before the detailed Analyst diagnostics, with KPIs, event funnel bars, and specific customer actions.
* Existing Prepared Intelligence and Market reports remain read-only sources. Missing data is unavailable, not zero; supplier commissions cannot be inferred from WooCommerce purchases.
* Added smoke checks for commercial summary contracts, navigation, and absent data.

### 1.1.1

* Added persistent Prepared Intelligence built from saved Search, GA4, Bing, Trends and Market evidence.
* Analyst now consumes prepared report data instead of re-running deterministic Search/GA4 preprocessing on page load.
* Market aggregation is cached and refreshed when new Market evidence is saved.
* Detailed provider reports remain the auditable source while Analyst receives compact prepared summaries and candidate rows.

### 1.1.0

* Recovered the clear diagnostic dashboard from the former Analista inside the modern Analyst tab.
* Added visibility index, ranking distribution, keyword movement, Search evolution, catalog URL types, and architecture counters from saved snapshots.
* Search snapshots now keep previous-period queries/pages and daily position so Top 10/50/100 movement is auditable.
* Fixed GA4 batchRunReports by splitting report requests into batches of at most five.
* Added GA4 ecommerce funnel event counts for product views, add-to-cart, checkout and purchase diagnostics.
* Renamed report retrieval actions to “Actualizar datos” for clearer manual refresh semantics.

### 1.0.0

* Consolidated compatible external connections into Insights.
* Added unified Connections and global Analyst reporting.
* Retained historical settings and OAuth callback contracts.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
