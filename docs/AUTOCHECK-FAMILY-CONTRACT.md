# Contrato Autocheck de la familia TaxoCommerce

El nombre visible de la sección en todos los plugins instalables es **Autocheck**. Se mantienen sin cambios las claves internas y las rutas históricas `auto-test`, `autotest`, `autocheck` y `auto_test` para que los enlaces y las ampliaciones existentes sigan funcionando.

## Ejecución y resultados

El administrador inicia el diagnóstico expresamente. La ejecución prueba las funcionalidades que cada plugin puede comprobar de forma segura, sin realizar importaciones, facturación, cambios de productos, envíos, operaciones externas ni modificaciones en las colas. Una comprobación omisa, opcional o que requiera UAT se indica como omitida, nunca como correcta.

Cada Autocheck expone un botón **Descargar resultados (JSON)** que entrega el último informe disponible, con identificación del plugin y su versión, fecha y detalles de las comprobaciones. Los campos concretos de cada fila conservan el esquema previo de cada plugin para no romper consumidores de informes. Los nuevos informes temporales se guardan exclusivamente por usuario y sitio durante una hora; los módulos que ya gestionaban históricos conservan su política existente.

La descarga requiere sesión WordPress, capacidad suficiente y nonce. No ejecuta nuevas comprobaciones ni da por superadas operaciones reales no verificadas. Los JSON nunca deben transportar credenciales, contraseñas, tokens ni claves API.

## Fecha de ejecución (#2154)

Cada ejecución **manual** genera `executed_at` como fecha y hora ISO 8601 UTC, conservada en el informe JSON sin recalcularla al descargar. El panel muestra **Fecha de ejecución** (día, hora y zona horaria, o fecha local histórica para informes existentes). Se mantienen por compatibilidad los campos `date`, `date_utc` y `generated_at` y las referencias ANTES/DESPUÉS. Una descarga, visita al panel, activación o despliegue nunca ejecuta de nuevo el Autocheck.

## Verificación

La prueba `.github/tests/taxocommerce-autocheck-family.php` comprueba los **20 plugins** y falla si falta la denominación o el contrato JSON protegido. La ejecución de CI valida también la sintaxis PHP y pruebas offline de regresión. Estas pruebas no sustituyen la ejecución UAT con WordPress en STAGING ni una prueba real de descarga autenticada en cada sitio.

Los despliegues se limitan a plugins instalados en destinos expresamente autorizados. El flujo actual solo contempla STAGING para módulos que no sean Core; Focazul recibe exclusivamente el mismo Core y nunca se actualiza la tienda de producción sin permiso específico.
