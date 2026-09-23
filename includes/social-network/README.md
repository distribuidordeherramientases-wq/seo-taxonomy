# SEO System - Social Network

El subsistema de redes sociales vive completamente en este directorio:

- `social-bootstrap.php`: carga el nucleo y los proveedores.
- `core.php`: configuracion comun, plantillas, cola/publicaciones, tracking e informes.
- `facebook.php`: proveedor Facebook Pages.
- `linkedin.php`: proveedor LinkedIn Pages.

## LinkedIn

El proveedor usa LinkedIn Posts API (`/rest/posts`) con la cabecera de version `YYYYMM` y OAuth 2.0.

Configuracion desde **SEO Taxonomy > Marketing > Redes sociales > Conexiones**:

1. Crea/usa una aplicacion en LinkedIn Developer Portal asociada a la empresa adecuada.
2. Solicita/activa los productos que concedan `r_organization_admin` y `w_organization_social`.
3. Copia en la aplicacion la Redirect URL exacta que muestra el plugin.
4. Guarda `Client ID` y `Client Secret` en el plugin.
5. Pulsa **Conectar / renovar autorizacion con LinkedIn**.
6. Selecciona la pagina de empresa, guarda y prueba la conexion.

El Client Secret, Access Token y Refresh Token se almacenan cifrados. El token manual queda disponible solo como alternativa para pruebas o configuraciones donde el token ya se obtiene fuera del plugin.

El modo **Articulo con enlace y tarjeta** envia `source`, `title` y `description` directamente a LinkedIn. LinkedIn no hace scraping automatico de la URL en publicaciones creadas por API. La miniatura es opcional y no se sube en esta primera integracion.

No hay migracion de base de datos: Facebook y LinkedIn comparten la tabla `wp_seo_social_publications` y el tracking UTM ya existente.


## Programador: importacion y exportacion CSV

La subpestana **Programador** admite ahora programacion manual y programacion masiva con CSV UTF-8 compatible con Excel/LibreOffice.

- `Descargar plantilla CSV`: cabecera minima para crear una hoja nueva.
- `Exportar contenidos`: IDs, titulos, tipos, URL e indicador de imagen destacada.
- `Exportar agenda`: programaciones manuales pendientes.
- `Exportar historial`: intentos y resultados de publicaciones sociales.
- `Validar CSV antes de importar`: muestra una previsualizacion; no crea tareas hasta confirmar.

Columnas obligatorias de importacion:

```text
contenido_id;titulo;redes;fecha_hora
141184;Taladro percutor;facebook;2026-09-25 10:30
141185;Aspiracion en taller;facebook,linkedin;25/09/2026 12:00
```

`titulo` es opcional para importar, pero se conserva en la plantilla para facilitar el trabajo en Excel. La zona horaria usada es la configurada en WordPress. El programador actual mantiene una sola fecha pendiente por combinacion contenido/red; importar de nuevo esa misma combinacion la reprograma.
