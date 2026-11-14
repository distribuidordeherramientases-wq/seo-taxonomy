## 0.1.1 — 2026-11-14 — Facturas originales de compras y proveedores (#2681)

- Las compras, gastos, activos e intracomunitarias registradas manualmente ahora permiten adjuntar uno o varios PDF originales del proveedor, con enlaces de descarga protegidos y estado pendiente si no existe documento digital.
- Validación de subida de WordPress, firma PDF, 10 MB máximo, permisos `manage_woocommerce` y nonces. Los PDF se cifran mediante AES-256-GCM, se archivan como archivos opacos con nombres aleatorios y solo pueden recuperarse por una descarga autenticada, verificando integridad y relación con el asiento.
- Nueva tabla de documentos exclusiva del plugin, migración no destructiva desde 0.1.0. No cambia importación WooCommerce, ventas, proveedores, importes, IVA, validaciones ni exportaciones; tampoco extrae automáticamente datos de las facturas ni genera documentos de Hacienda.
- Pruebas aisladas de cifrado/descifrado, alteración, permisos y vínculos, PHP y WordPress Plugin Check. La copia de seguridad debe incluir los archivos cifrados, la BBDD y las claves salt de WordPress para preservar el descifrado.

## 0.1.0 — 2026-11-13 — Initial fiscal register (#2644)

- Independent WooCommerce accounting plugin with native WordPress admin, optional TaxoCommerce Core menu, own table and configuration.
- Manual, batched and repeat-safe import of paid WooCommerce orders after the merchant's configurable fiscal start date.
- Draft-only imported sales and refunds, to be reconciled with issued invoices and VAT before validation.
- Manual received invoices and expense, asset and intracommunity categorisation with tax bases, VAT, deductible proportion, issuer and invoice reference.
- Monthly domestic VAT working balance, provisional Model 303 view and CSV/JSON internal exports.
- Read-only Autocheck for actual missing invoices, drafts, special-treatment operations and schema health.
- Strict privilege/nonce checks and no modifications to source orders, suppliers, billing, logistics or production data.
- No tax submissions to AEAT; initial release excludes official format exports, comprehensive books and automatic deduction policy.
