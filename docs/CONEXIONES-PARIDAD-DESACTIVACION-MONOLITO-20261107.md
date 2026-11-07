# Auditoría de paridad antes de retirar TaxoCommerce SEO
Fecha: 2026-11-07. No se ha cambiado PRO. Estado: **RETIRADA BLOQUEADA**.

## Resultado de los cinco pasos
1. **Desactivación temporal en STAGING: NOT_CHECKED.** El conector WordPress no da acceso a STAGING. El último informe de CI (run 42384735338) muestra TC_STAGING_WP_USER y TC_STAGING_WP_PASSWORD vacíos. Sin acceso autenticado para verificar y restaurar, no se ha desactivado.
2. **Autotest: PARCIAL.** Los smoke tests aislados comprueban funciones; no equivalen al Autocheck de cada plugin dentro del WordPress de STAGING.
3. **Pruebas API reales: NOT_CHECKED.** Los tests de repositorio no autentican ante Google, Bing, Pinterest, Amazon o Cloudflare con credenciales del entorno.
4. **Sincronizaciones y cabeceras: NOT_CHECKED.** Comprobar una única carga de GA4 tras consentimiento, ecommerce GA4 DebugView, IndexNow último HTTP, Pinterest domain verification, feeds, cron y las descargas/actualizaciones de cada proveedor.
5. **Independencia: BLOQUEADA.** Hasta que no se migren motores/credenciales/callbacks y se pruebe en STAGING con SEO desactivado, no hay garantía de retirar SEO en PRO.

## Matriz de sistemas
| Sistema | Destino | Riesgo pendiente |
|---|---|---|
| Google Analytics tráfico | Insights: Cabeceras | Confirmar gtag.js único y consentimiento estadístico |
| GA4 ecommerce ventas | Insights: Comercio electrónico | Activación independiente y prueba real de compras, eventos y consentimiento |
| Google Search Console, OAuth, Merchant | Insights: Connections | Probar identidad, API, propiedad y descarga/sincronización |
| Bing Webmaster e IndexNow | Insights: Connections | Probar clave, cola, última respuesta y renovación de datos |
| Pinterest Business/Merchant | Insights: Connections, Social Network, Distribution | Probar OAuth, etiqueta de verificación, feed y pruebas de conexión |
| Amazon Afiliados y Creators | Insights: Connections | Probar credenciales y consultas reales |
| Cloudflare | Insights: Connections, System Check | Código migrado en PR #2341, verificar token cifrado, zona, DNSSEC, WAF y SSL sin editar nada |
| Google Cloud Run | Insights: Connections | Probar ejecución y callback del job |
| GitHub Actions Python Runner | Import-Export / Suppliers: NO COMPLETO | El archivo antiguo conserva callbacks REST y el motor CSV requiere funciones seo_proveedores_*; migrar todas antes de desactivar |
| Google Intelligence e informes | Insights: análisis e informes | Comprobar sincronizaciones históricas, crons y paridad de once informes |

## Evidencia y seguridad
- Cloudflare antiguo en includes/import-export/suppliers/cloudflare.php; la nueva copia conserva option seo_cloudflare_settings, AES-256-GCM v1 y el token opcional de wp-config.
- GitHub Runner antiguo en includes/import-export/suppliers/github-python-runner.php aún requiere el motor de recetas y CSV del monolito.
- Verificación actual estática: tests de Insights de cabeceras, ecommerce y Cloudflare. No demuestra conexión real.
- PRO: NO desactivar ni borrar el monolito ni las opciones anteriores. Cloudflare no requiere una etiqueta HTML; su conexión PHP no gestiona la zona DNS externa.
- Los pilotos VEVOR con enlaces afiliados no generan eventos de compra WooCommerce verificables en su web externa.
- Incidencia ajena de i18n: 23 catálogos .mo estructuralmente inválidos bloquean CI general; no modificar catálogos en esta tarea.
- Criterio para levantar bloqueo: pruebas reales en STAGING tras desactivación temporal con restauración garantizada y confirmación de todos los indicadores, sincronizaciones, conectores y estados.
