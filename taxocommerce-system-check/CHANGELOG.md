# CHANGELOG — TaxoCommerce System Check

> Historial de las versiones independientes del plugin. El origen de los motores de diagnóstico está en el monolito TaxoCommerce SEO; no se asignan versiones retrospectivas a esa etapa.

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
