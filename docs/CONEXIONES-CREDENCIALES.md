# Conexiones y credenciales

Este documento explica los campos de conexión visibles en TaxoCommerce SEO y muestra ejemplos de formato.

> **Todos los valores de esta página son ficticios. No funcionan y no deben copiarse como credenciales reales.**

## Regla general para campos sensibles

Cuando un campo contiene una clave, token, contraseña o secreto:

- obtenerlo siempre del proveedor oficial;
- no publicarlo en GitHub, tickets, documentación o capturas;
- utilizar una credencial dedicada cuando sea posible;
- conceder únicamente los permisos necesarios;
- si la interfaz indica que dejar el campo vacío conserva el secreto guardado, no volver a pegarlo innecesariamente.

---

## Cloudflare

### Activar Cloudflare para diagnósticos
Permite que TaxoCommerce SEO utilice la conexión para diagnósticos.

Desactivarlo no implica necesariamente borrar la credencial almacenada.

### Zona / dominio
Dominio raíz gestionado en Cloudflare.

**Ejemplo:** `ejemplo.es`

No utilizar normalmente `www.ejemplo.es` si la zona configurada en Cloudflare es `ejemplo.es`.

### API Token
Token de Cloudflare.

**Ejemplo ficticio:** `cf_api_token_EXAMPLE_NOT_REAL_7f2a9c`

El plugin cifra el token antes de guardarlo y no debe volver a mostrarlo.

Se recomienda **API Token** limitado a la zona, no Global API Key.

Permisos orientativos indicados por la propia interfaz: lectura de zona, configuración, DNS, SSL/certificados, WAF y Analytics. No conceder permisos de escritura si no son necesarios.

---

## Google Search Console y Analytics

### GA4 Measurement ID
Identificador de medición utilizado en el frontend.

**Ejemplo:** `G-AB12CD34EF`

### Analytics Property ID
ID numérico de la propiedad GA4.

**Ejemplo:** `123456789`

### Propiedad de Search Console
Debe coincidir con una propiedad registrada en Search Console.

**Ejemplos:**

- dominio: `sc-domain:ejemplo.es`
- URL-prefix: `https://www.ejemplo.es/`

### JSON de cuenta de servicio Google

El campo espera un JSON de una cuenta de servicio.

**Ejemplo ficticio abreviado:**

```json
{
  "type": "service_account",
  "project_id": "demo-seo-project",
  "client_email": "seo-demo@demo-seo-project.iam.gserviceaccount.com",
  "private_key": "EXAMPLE_NOT_REAL"
}
```

No utilizar el usuario y contraseña personales de Google.

---

## Google Cloud Python Runner

### Activar Google Python Runner
Habilita el ejecutor externo cuando la configuración es válida.

### Google Cloud Project ID
**Ejemplo:** `demo-seo-project`

### Región
**Ejemplo:** `europe-west1`

### Cloud Run Job
**Ejemplo:** `seo-supplier-runner`

### JSON de cuenta de servicio Google Cloud
Utilizar una cuenta de servicio dedicada.

**Ejemplo ficticio:** igual formato que el JSON de cuenta de servicio anterior.

El callback privado y el token temporal de ejecución son generados por WordPress; el usuario no debe inventarlos ni copiarlos manualmente.

---

## GitHub Actions Python Runner

### Activar GitHub Actions Python Runner
Permite utilizar un workflow como ejecutor externo.

### Usuario / organización GitHub
**Ejemplo:** `mi-organizacion-demo`

### Repositorio
**Ejemplo:** `seo-scrapers-demo`

### Workflow
Nombre del archivo dentro de `.github/workflows/`.

**Ejemplo:** `supplier-runner.yml`

### Rama / ref
**Ejemplo:** `main`

### Token de acceso GitHub
**Ejemplo ficticio:** `github_pat_EXAMPLE_NOT_REAL_123456789`

Debe ser un token dedicado y limitado al repositorio con los permisos requeridos por el workflow. No introducir la contraseña de GitHub.

---

## Amazon Afiliados

### Partner Tag amazon.es
Es el dato necesario para generar enlaces afiliados.

**Ejemplo:** `mitienda-demo-21`

### Credential ID Creators
Opcional.

**Ejemplo ficticio:** `amzn1.application-oa2-client.EXAMPLE`

### Credential Secret Creators
Opcional.

**Ejemplo ficticio:** `amazon_secret_EXAMPLE_NOT_REAL`

La ausencia de Creators API no impide utilizar enlaces afiliados cuando existe un Partner Tag válido.

---

## Facebook

### Facebook Page ID
ID numérico de la página.

**Ejemplo:** `123456789012345`

### Page Access Token
**Ejemplo ficticio:** `EAAB_EXAMPLE_NOT_REAL_123456`

Debe pertenecer a la página configurada y contar con los permisos requeridos.

### Versión Graph API
**Ejemplo:** `v25.0`

### Modo de publicación
La interfaz permite elegir entre publicación por enlace con vista previa Open Graph y publicación con foto destacada cuando está disponible.

---

## Instagram

### Reutilizar conexión de Facebook
Opción recomendada cuando la página de Facebook tiene vinculada una cuenta profesional de Instagram.

### Instagram User ID
**Ejemplo:** `17841400000000000`

### Versión Graph API
**Ejemplo:** `v25.0`

### Page Access Token manual
Opcional cuando no se reutiliza Facebook.

**Ejemplo ficticio:** `IG_TOKEN_EXAMPLE_NOT_REAL_123456`

La cuenta de Instagram debe ser Professional (Business o Creator) para las funciones de publicación utilizadas por el módulo.

---

## LinkedIn

### Client ID
**Ejemplo ficticio:** `linkedin-client-demo-123456`

### Client Secret
**Ejemplo ficticio:** `linkedin_secret_EXAMPLE_NOT_REAL`

### Organización
Cuando la publicación se realiza como organización, utilizar el identificador indicado por LinkedIn.

**Ejemplo:** `123456789`

### Access Token manual
Opcional para pruebas o cuando el token se obtiene fuera del plugin.

**Ejemplo ficticio:** `linkedin_token_EXAMPLE_NOT_REAL`

### Callback URL
Registrar en la aplicación exactamente la URL mostrada por TaxoCommerce SEO.

**Ejemplo ilustrativo:**
`https://www.ejemplo.es/wp-json/seo-system/v1/linkedin/callback`

No sustituir el dominio real por el del ejemplo en la aplicación de producción.

---

## Pinterest

### Pinterest App ID
**Ejemplo ficticio:** `1234567890123`

### Pinterest App Secret
**Ejemplo ficticio:** `pinterest_secret_EXAMPLE_NOT_REAL`

### Tablero destino
Seleccionar uno de los tableros accesibles tras autorizar OAuth.

### Access Token manual
Opcional. OAuth es la opción recomendada.

**Ejemplo ficticio:** `pinterest_token_EXAMPLE_NOT_REAL`

### Callback URL
La URL real se muestra en la pantalla de conexión.

**Ejemplo ilustrativo:**
`https://www.ejemplo.es/wp-json/seo-system/v1/pinterest/callback`

---

## X

### OAuth 2.0 Client ID
**Ejemplo ficticio:** `x-client-demo-123456`

### Client Secret
**Ejemplo ficticio:** `x_secret_EXAMPLE_NOT_REAL`

### Access Token manual
Opcional.

**Ejemplo ficticio:** `x_access_token_EXAMPLE_NOT_REAL`

### Callback URL
Debe registrarse como Redirect URI en la aplicación de X.

**Ejemplo ilustrativo:**
`https://www.ejemplo.es/wp-json/seo-system/v1/x/callback`

---

## Google Merchant Center · informes de mercado en Ojeador

Ruta: **TaxoCommerce SEO → Herramientas → Ojeador → Merchant · Comparación gratuita**.

Esta integración **no comparte automáticamente la autorización OAuth de Search Console**. Requiere:
- **ID de Merchant Center**: número de cuenta Merchant, por ejemplo `123456789` (ejemplo ficticio).
- **Cuenta de servicio de Google**: el JSON configurado en las conexiones de Google Search/Analytics; debe recibir acceso a la cuenta Merchant y solicitar por separado el alcance `https://www.googleapis.com/auth/content`.
- **Merchant Reports API** disponible y permisos para sus informes de competitividad de precios y productos populares. Algunos informes requieren elegibilidad adicional.

Los botones **Consultar siguiente lote Merchant** realizan consultas manuales, paginadas y de solo lectura. **No consumen créditos SerpApi/ScraperAPI**, pero se rigen por las cuotas y permisos de Google.

En **Fuente de mercado**, el modo predeterminado **Merchant solamente** bloquea las búsquedas Shopping de pago ejecutadas por Ojeador. El modo **Shopping tradicional** se habilita únicamente mediante elección expresa del administrador. Ambos modos conservan los históricos anteriores.

## SerpApi + ScraperAPI · conexión compartida para Ojeador (modo tradicional) e Ingeniero

Ruta: **TaxoCommerce SEO → Herramientas → Conexiones con proveedores**.

Estas credenciales siguen almacenadas de forma central. **Ingeniero** las utiliza para investigación externa. **Ojeador** solo las emplea para nuevos escaneos Shopping cuando un administrador ha elegido expresamente el modo tradicional; la pestaña Ojeador → Novedades está retirada y no consume esos proveedores.

### SerpApi API key
Proveedor primario para Google Shopping y Google Search cuando la operación correspondiente esté habilitada.

**Ejemplo ficticio:** `serpapi_EXAMPLE_NOT_REAL_123456`

También puede definirse mediante `SEO_OJEADOR_SERPAPI_KEY` en `wp-config.php`.

### ScraperAPI API key
Proveedor secundario/fallback para Google Shopping y Google Search.

**Ejemplo ficticio:** `scraperapi_EXAMPLE_NOT_REAL_123456`

También puede definirse mediante `SEO_OJEADOR_SCRAPERAPI_KEY` en `wp-config.php`.

Orden operativo del modo tradicional y las búsquedas de Ingeniero:

1. SerpApi.
2. Si falta conexión, se alcanza cuota o falla la petición, ScraperAPI.
3. Si SerpApi responde correctamente con 0 resultados, no se activa el segundo proveedor.

Las credenciales se muestran en campos separados. **Probar cadena SerpApi → ScraperAPI** ejecuta una comprobación real de proveedores y **puede consumir una consulta**; no debe utilizarse innecesariamente. Un campo de API key vacío conserva la clave guardada cuando la interfaz lo indica.

### Límite mensual local SerpApi
Es una protección compartida para las búsquedas de pago. Si se alcanza y existe conexión ScraperAPI, los servicios que tengan habilitadas esas búsquedas podrían usar el proveedor de respaldo. **Merchant-only no activa ningún fallback de pago por falta de datos Merchant**.

Los ajustes **Actualizar cada**, **Categorías por paso**, **Reutilizar consulta durante** y **Mantener el mercado actualizado automáticamente** pertenecen a la operación tradicional de Ojeador. No determinan la sincronización manual de Merchant.

Ingeniero mantiene su propio límite local de búsquedas lógicas, independiente de la cuota concreta de cada proveedor.


---

## Qué hacer cuando una conexión falla

1. comprobar que el servicio está activado;
2. verificar que ID, URL, región, rama o propiedad tienen el formato correcto;
3. confirmar que la credencial no ha caducado;
4. comprobar permisos/scopes;
5. utilizar el botón de prueba de conexión cuando exista;
6. revisar el mensaje de error antes de generar una credencial nueva;
7. no pegar credenciales reales en Issues ni en mensajes públicos para pedir soporte.
