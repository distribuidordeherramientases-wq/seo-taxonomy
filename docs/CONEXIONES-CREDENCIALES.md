# Conexiones y credenciales

Este documento explica los campos de conexión visibles en SEO Taxonomy y muestra ejemplos de formato.

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
Permite que SEO Taxonomy utilice la conexión para diagnósticos.

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
Registrar en la aplicación exactamente la URL mostrada por SEO Taxonomy.

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

## SerpApi / Ojeador

### API key
Clave utilizada por Ojeador para las consultas de Google Shopping.

**Ejemplo ficticio:** `serpapi_EXAMPLE_NOT_REAL_123456`

### Actualizar cada
Intervalo entre revisiones de una categoría.

**Ejemplo:** `720` horas para una revisión aproximadamente mensual.

### Categorías por paso
Número de categorías que procesa cada ciclo.

El valor debe ajustarse a la capacidad del servidor y a la cuota disponible.

### Reutilizar consulta durante
Evita repetir una consulta reciente durante el periodo configurado.

### Límite mensual local
Protección interna para no superar el volumen de consultas previsto.

---

## Qué hacer cuando una conexión falla

1. comprobar que el servicio está activado;
2. verificar que ID, URL, región, rama o propiedad tienen el formato correcto;
3. confirmar que la credencial no ha caducado;
4. comprobar permisos/scopes;
5. utilizar el botón de prueba de conexión cuando exista;
6. revisar el mensaje de error antes de generar una credencial nueva;
7. no pegar credenciales reales en Issues ni en mensajes públicos para pedir soporte.
