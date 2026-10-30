# CHANGELOG — TaxoCommerce Redirects - SEO Redirect Manager

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## Versiones independientes

### 0.1.7

* Add User Guide tab with separate User Manual and Technical Manual, indexed sections, accessible navigation, responsive typography and links to Redirects/Auto Test.
* Document existing 301/302 functionality, administration, rules diagnostics, JSON backups, assisted deletion, data schema, legacy coexistence and the destructive uninstall caveat.
* Manual rendering is read-only and does not load the redirects table or invoke any handlers.

### 0.1.6

* Add native Auto Test tab with 26 read-only checks in 8 groups for redirects, schema, assisted deletion, integration, and safety.
* Save a per-user, per-site BEFORE baseline and compare it with AFTER results, including lost coverage, new errors and resolved issues.
* Allow downloading private diagnostic JSON; no redirects, hit counters, or content changes are made by tests.
* Keep the Redirects manager and all existing rules, legacy integration, and assisted-delete workflows unchanged.

### 0.1.5

* WordPress.org release metadata cleanup and English plugin description.
* Confirmed bounded assisted-delete queries keep WordPress filters enabled.

### 0.1.4

* Retirada la integracion opcional del motor historico de rollback de Database. CRUD de redirecciones vuelve al flujo autonomo original, sin dependencia ni comprobaciones de Database.
* Conservado el boton Abrir y la navegacion por Core cuando esta instalado.

### 0.1.3

* Version experimental retirada de la integracion de rollback.

### 0.1.2

* Integracion de menu y accion Abrir en el catalogo Core.

### 0.1.1

* Verified activation lifecycle and explicit uninstall cleanup for seo_redirects.

### 0.1.0

* Independent runtime, administration, import/export and 4+4 assisted deletion.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
