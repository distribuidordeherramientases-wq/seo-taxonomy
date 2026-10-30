# CHANGELOG — TaxoCommerce FAQs

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## Versiones independientes

### 0.1.6

* Correct prepared SQL identifiers and validation in the FAQ editor, CSV and Vocabulary operations.
* Exclude development tests and FTP sync state from installed plugin, and clean obsolete files on staging.
* Retain all FAQ records and existing editor, Auto Test and Manual functionality.

### 0.1.5

* Add an in-plugin Auto Test tab with 25 read-only checks across six groups.
* Add a separate read-only Manual tab with eleven user-guide chapters and six technical chapters, following the family layout.
* Store before/after deployment baselines per user and site, compare regressions and export sanitized JSON.
* Never change FAQ rows, run migrations, or import/export files in diagnostic mode; live operations remain manual.

### 0.1.4

* Replace direct PHP CSV file handles with WordPress filesystem reads and streamed CSV encoding.
* Preserve quoted multiline FAQ answers during bounded CSV import.
* Keep current prepared SQL and English WordPress.org readme metadata.

### 0.1.3

* Standalone FAQ editor and WooCommerce category validation.
* CSV exchange and visibility/usage KPIs.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
