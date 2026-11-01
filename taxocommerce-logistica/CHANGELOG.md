# CHANGELOG — TaxoCommerce Logistics

> Historial maestro de versiones del plugin independiente. El origen monolítico se documenta en [`changelog.txt`](changelog.txt).

## [Unreleased]

- Inicio de internacionalización WordPress mediante gettext (`Domain Path: /languages`), catálogos por idioma y traducción de etiquetas del menú, sin cambios funcionales. Seguimiento: #2039 (plan global #2036). Cobertura restante de interfaz, Autotest y manuales pendiente de revisión por idioma.


* WordPress Plugin Check: corregidas las 12 advertencias de `includes/seo-transporte.php` sin cambiar las reglas, costes ni rutas de checkout.
* Mantener parámetros GET de enrutado y avisos como lecturas inofensivas y justificar sus excepciones de nonce solo en esos puntos.
* Exigir los nonces nativos `update-order-review` o `woocommerce-process_checkout` para aceptar destino enviado por POST; datos de destino saneados por campo y respaldo con paquete/sesión cuando no hay petición válida.
* Añadir regresión CLI de checkout (nonce ausente, inválido, revisión y finalización válidas, campos mal formados), excluirla del ZIP e impedir que Plugin Check ignore advertencias en CI.
* Issue #1947. Sin cambios en el plugin monolítico ni despliegue a producción.


## [0.2.4]

* Integrate the Logistics menu into TaxoCommerce Core when installed, keeping the standalone fallback when Core is absent.
* Preserve the Shipping Rules, Auto Test and Manual tabs and the existing shipping-option storage.
* Remove the redundant separate WordPress sidebar entry while Core is active.

## [0.2.3]

* Fix escaped output of list tags in the user and technical manuals.
* Standardize the built-in documentation tab as Manual, with Manual de usuario and Manual técnico, preserving its existing URLs.
* Keep Auto Test with 35 read-only checks and before/after comparisons.

## [0.2.2]

* Add User Guide tab with an indexed User Manual (10 chapters) and Technical Manual (11 chapters), using the same layout as the TaxoCommerce family.
* Explain real shipping regions, fees, rule priorities, missing dimensions, order audits, JSON configuration backups and the before/after Auto Test.
* Keep documentation read-only: it never loads order audits, calculates live shipping, changes settings or imports files.

## [0.2.1]

* Add separate Auto Test tab with 35 read-only checks in eight functional areas and pre/post deployment comparison.
* Check WooCommerce shipping hooks, rates, region mapping, weight/dimensions, fixed/variable/free cost rules, saved settings and import/export controllers without changing real orders or shipping rules.
* Store BEFORE and latest diagnostics by site and administrator; export JSON, detect new failures and lost check coverage.
* Mark checkout, tax, real imports and cache tests as unverified until run against staging.

## [0.2.0]

* Move sale-to-supplier purchasing to TaxoCommerce Suppliers.
* Keep Logistics focused on order weights and shipping costs.
* Add active-order shipping weight and charged-cost audit.

## [0.1.0]

* Migrated the existing Order Management and Shipping modules into an independent TaxoCommerce plugin.

## Fuentes

- [`changelog.txt`](changelog.txt), etapa monolítica.
- [`docs/PLUGIN-HISTORY-SOURCES.md`](../docs/PLUGIN-HISTORY-SOURCES.md), PR y referencias históricas.

## Actualizaciones

Para nuevas funcionalidades se edita este `CHANGELOG.md`. Antes de publicar, se debe reflejar el resumen de la versión en `readme.txt`, que es el único Changelog mostrado automáticamente por WordPress.org.
