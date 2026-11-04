# Registro de cambios de la infraestructura QA

## 2026-11-04 — Aislamiento FTPS de Focazul (#2164)

- El constructor de rutas remoto impide cualquier destino distinto de `taxocommerce-core` en Asociación Focazul. STAGING mantiene sus módulos instalados y autorizados; PRO permanece fuera del flujo. Se reutiliza la prueba existente que falló en el PR #2155 (`test_shared_ftp_site_roots_are_isolated`), sin cambiar archivos instalables de plugins ni ejecutar ningún AutoCheck automáticamente.

## 2026-11-03 — Auditoría comparativa de Core en STAGING y Focazul (#2107)

- Auditoria read-only desde GitHub de dos instalaciones que reciben el MISMO plugin Core: comparar hashes SHA-256 de todos los archivos instalables con el commit fuente y detectar divergencias sin reparar/copiar contenidos de WordPress.
- Revisar sitio público, enlaces y posibles referencias comerciales en el código genérico; los menús, categorías, páginas, países, proveedores y datos son independientes en cada WordPress.
- Verificar estructura común de las pantallas del editor de menús vía acceso autenticado **solo cuando** haya usuarios de prueba y secretos de GitHub por sitio. Si faltan, estado NOT_CHECKED; no se presenta un éxito ficticio. Pruebas offline del auditor y tests Core de CI.
- Producir informe en resumen del workflow y artifacts JSON/Markdown con PASS / FAIL / WARNING / NOT_CHECKED. Se ejecuta tras los despliegues de STAGING y Focazul, o por workflow_dispatch independiente; además se ejecutó auditoría read-only desde rama de QA.
- No hay cambios de código funcional de Core, ni instalaciones/deploys a PRODUCCIÓN. No cambia la versión 0.4.51 ni el histórico congelado del monolito.
