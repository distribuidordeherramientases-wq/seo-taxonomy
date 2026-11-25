# TaxoCommerce Import-Export Lite

## [0.1.0] — 2026-11-25

- Primera instalación autorizada exclusivamente en STAGING y Asociación Focazul mediante el despliegue selectivo de GitHub Actions (Issue #2952); no se instala en producción ni se altera el plugin Import-Export original.

- First standalone Lite release, separate from the current TaxoCommerce Import-Export plugin and its advanced batch importer.
- Native WordPress pages, posts, categories and tags; native WooCommerce products, product categories and product tags.
- Single CSV upload; per-column mapping; default create-or-update with SKU/slug identity; new content saved as draft unless explicitly published with appropriate capability.
- CSV export with explicit field selection and incremental native API pagination.
- Native WooCommerce virtual/downloadable products, up to two HTTP(S) download links, prices, stock, dimensions and tax fields.
- No implicit taxonomy or foreign ID mapping; categories remain optional. No SEO taxonomy, supplier, Core, cross-plugin data or configuration required.
- Independent admin screen with version and release date, import/export, read-only checks, user and technical manuals.
- Security: capabilities, nonces, temporary private files, bounded per-request batches, field allowlists, output escaping, formula-injection prevention.
- Excludes child variation migration, orders, customers, binary media and third-party metadata in this first Lite release. The original plugin is unmodified.
