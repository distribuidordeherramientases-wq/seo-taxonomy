# Actualizaciones nativas TaxoCommerce — repositorio temporal

- **Origen temporal:** `wp-content/uploads/taxocommerce-core/plugins/` del propio WordPress.
- **Proveedor común:** `wp-content/mu-plugins/taxocommerce-upload-update-provider.php` (MU; independiente de Core).
- **Identificación del canal:** el proveedor MU reconoce únicamente plugins instalados con nombre/carpeta canónicos `taxocommerce-<slug>/taxocommerce-<slug>.php` y presentes en el manifiesto verificado. Las cabeceras de los plugins **no** contienen `Update URI` para conservar compatibilidad con WordPress.org.
- **Paquetes:** ZIP por módulo con carpeta raíz `<slug>/` y entrada `<slug>/<slug>.php`.
- **Índice:** `manifest.json` con versión, tamaño y SHA-256. Publicar al **final** para evitar ofrecer paquetes incompletos.
- **Control:** WordPress permite activar o desactivar autoactualizaciones **individualmente** en Plugins instalados. No se fuerza ningún ajuste.
- **Detección:** el MU-provider usa `pre_set_site_transient_update_plugins` (API nativa de WordPress), nunca reemplaza respuestas existentes de WordPress.org u otro proveedor, y publica `no_update` para mantener el control de autoactualización por plugin. WordPress revisa la disponibilidad mediante su sistema habitual de actualizaciones y WP-Cron. Requiere **incrementar la cabecera Version** del plugin; modificar únicamente la fecha o el contenido del ZIP no dispara una actualización del mismo número de versión.
- **Seguridad:** solo se anuncian ZIP verificados, con estructura y SHA-256 correctos. La carpeta uploads usada como repositorio debe ser accesible por HTTPS y escribible únicamente por el proceso de publicación autorizado. No habilitar instalaciones automáticas sin resolver antes WP-Cron, permisos y QA.

## Publicación

El workflow `taxocommerce-native-updates.yml` valida paquetes y proveedor en PR; tras merge a `staging` publica automáticamente en el repositorio de **WordPress STAGING**. La publicación en **producción** es una acción manual `workflow_dispatch` con `target=production` sobre la rama `main`; esta PR no ejecuta ningún despliegue a PRO.

El workflow de publicación no sobreescribe plugins instalados: WordPress comprueba las versiones de /uploads y muestra la actualización. La habilitación de la instalación automática se controla por separado en WP.

## QA antes de producción

1. Comprobar que el MU-provider aparece cargado en STAGING.
2. Verificar `manifest.json` y todos los ZIP; descargar y abrir un paquete en un entorno de prueba.
3. Comprobar que aparece **Activar actualizaciones automáticas** en Plugins instalados.
4. Instalar primero una versión posterior de un plugin de prueba mediante el actualizador nativo, con backup y validación de regresión.
5. Revisar los avisos de Action Scheduler y WP-Cron antes de confiar en actualizaciones desatendidas.
6. No cambiar el mecanismo FTPS de despliegue directo a producción hasta validar el circuito de actualización y recuperación.

## Transición a WordPress.org

Cuando cada plugin se publique en WordPress.org, **retirar su Update URI temporal** y excluirlo del manifiesto privado en la misma release que migra el canal. El sistema oficial WordPress.org pasará a suministrar sus actualizaciones. No mantener dos canales activos para el mismo plugin.

## Alcance del repositorio

Se configuran los 20 módulos con `taxocommerce-<slug>/taxocommerce-<slug>.php` existentes. **Assistant, Insights y SEO** se deben localizar y adaptar desde sus repositorios respectivos, si no comparten este árbol de código. No se deben inventar sus rutas.
