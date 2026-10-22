# TaxoCommerce Google / Bing

Plugin independiente de la familia TaxoCommerce para centralizar conexiones y, progresivamente, la extracción de datos de Google y Microsoft Bing.

## 0.1.0

Primera fase: **Conexiones**.

### Google

- Cuenta de servicio JSON.
- Propiedad de Google Search Console.
- Propiedad de Google Analytics 4.
- Measurement ID existente.
- Prueba real de autenticación.
- Prueba de acceso a Search Console cuando hay propiedad configurada.
- Prueba de acceso a Google Analytics cuando hay propiedad GA4 configurada.
- Detección informativa del OAuth heredado de Google Intelligence.

La primera versión conserva la opción existente `seo_google_search_settings` para no romper los consumidores actuales.

### Microsoft Bing

- Activación/desactivación.
- Sitio de Bing Webmaster.
- API key.
- Compatibilidad con `SEO_BING_WEBMASTER_API_KEY` en `wp-config.php`.
- Prueba real contra Bing Webmaster API.

La primera versión conserva la opción existente `seo_analista_bing_settings`.

## Arquitectura prevista

El plugin será la fuente común para Analista, Ojeador, Ingeniero, Mercado e informes. Las siguientes pestañas se añadirán por fuente/dataset; no se implementan todavía para mantener separada la fase de conexiones.
