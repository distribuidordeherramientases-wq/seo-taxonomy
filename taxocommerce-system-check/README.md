# TaxoCommerce System Check

Plugin WordPress independiente en `taxocommerce-system-check/`.

## Alcance inicial

Se copian los dos chequeos del monolito TaxoCommerce SEO sin redisenar sus
algoritmos: Server Status y Plugin Validation. Se incluyen sus modulos
auxiliares, historial y el motor de informes PDF/JSON y correos.

`seo-health-scan.php` se reutiliza como fuente de inventarios y tablas de
resultados. El nuevo ejecutor PHP local procesa lotes de hasta cinco URL mediante
Action Scheduler (cuando existe) o WP-Cron. No se requieren tokens GitHub.
El disparador de trabajos exige un WP-Cron o cron real operativo para progresar
sin visitantes. La recuperacion programada revisa ejecuciones interrumpidas.

## Autocheck independiente (0.2.17)

La pestaña **Autocheck** ejecuta verificaciones locales de solo lectura: datos y
estados del diagnóstico PHP, coherencia de los módulos registrados, rutas de
administración, hooks y programación del auditor HTTP y capacidad real de
serialización JSON. Cada prueba incluye un resultado `pass`, `fail` o `skip`,
explicación, evidencias estructuradas y un resumen de cobertura que **no** suma
las omitidas como superadas.

El histórico del servidor se consulta sin iniciar un nuevo diagnóstico:
si no existe o tiene más de 24 horas se marca `skip`; si contiene datos
estructuralmente inválidos, `fail`. Los avisos técnicos del entorno pueden
existir dentro de un informe válido sin significar que el motor del plugin
haya dejado de funcionar. Las pruebas de HTTP externo, correos, tareas,
cobros o escrituras se dejan para pruebas explícitas independientes.

El JSON se conserva temporalmente por usuario y sitio (una hora) para
descargarlo sin repetir las pruebas. La descarga exige `manage_options`
y nonce de WordPress. El Autocheck **no equivale** al Plugin Check oficial.

**Aviso de alcance:** informes históricos provenientes del monolito pueden
comprobar archivos, clases y plantillas no instaladas en un WooCommerce
modular. Esos resultados no se deben presentar como fallos del Autocheck
independiente; la auditoría heredada profunda permanece separada.

## Compatibilidad con el plugin original

Las implementaciones existentes tienen funciones globales `seo_*`.
Mientras TaxoCommerce SEO siga activo, System Check reutiliza el motor ya
cargado y solo aporta su menu independiente, evitando errores fatales de
redeclaracion. Sin TaxoCommerce SEO, se carga la copia contenida en este
plugin. TaxoCommerce Core no es necesario.

La independencia de instalacion **no implica aun independencia funcional
total**: Plugin Validation contiene chequeos especificos de la plataforma
SEO. En un WordPress generico pueden dar avisos o figurar como no evaluables.
Se corregira al desarrollar la inspeccion por plugin seleccionado.

## Correo y privacidad

En modo independiente el destinatario es por defecto el correo del
administrador de WordPress; no se transporta la direccion privada del
desarrollador. El consentimiento y las opciones del nuevo sistema de correo
utilizan claves propias, independientes de las del monolito. La programacion
requiere activacion expresa.

No se implementan en esta entrega pagos, tarifas ni suscripciones.

## Validacion e instalacion

El workflow `.github/workflows/taxocommerce-system-check.yml` ejecuta lint
PHP 7.4, genera un ZIP independiente como artefacto de Actions y despliega
a STAGING desde la rama staging.

## Segunda fase

1. Chequeo individual de cada plugin WordPress instalado.
2. Marcar verificaciones especificas de TaxoCommerce como no aplicables si
   el modulo no existe, evitando falsos errores.
3. Extraer el motor a clases y namespaces propios para eliminar la
   dependencia de simbolos `seo_*`.
4. Configuracion completa de destinatarios, periodicidad y alertas.
5. Integracion opcional del sistema de suscripciones.

## Chequeos HTTP local: version 0.2.0

- Pestañas: **Páginas, Posts, Imágenes y Productos**.
- KPIs: URL activas, comprobadas, correctas, avisos, errores, pendientes, cobertura;
  última ejecución con promedio, p95, HTTP 429 y HTTP 5xx.
- Detalle por URL: HTTP inicial/final, duración, incidencias, filtros y búsqueda.
- Motor HTTP: `wp_safe_remote_get`, valida URL pública contra SSRF, verifica
  HTTPS, sigue hasta tres redirecciones explícitamente, con cuerpo de máximo 2 KB.
- Ejecución manual en tandas de cinco URL, con pausas de 10 segundos;
  bloqueo para no lanzar varios escaneos a la vez; cancelar/reanudar; vigilancia
  periódica si una ejecución queda detenida.
- Conserva `seo_health_runs` y `seo_health_items`, sin crear un segundo registro
  para la misma URL. Para imágenes añade Media de WordPress al inventario
  histórico; para productos utiliza publicaciones WooCommerce.
- No se debe interpretar como una monitorización externa: si DNS, firewall o
  rutas del proveedor bloquean las solicitudes desde el propio hosting,
  el chequeo local puede fallar aunque los visitantes sí tengan acceso.

## Interfaz 0.2.1

Una sola entrada visible System Check. Pestañas: Sistema, Plugin Validation y HTTP. En HTTP permanecen las cuatro subpestañas: Páginas, Posts, Imágenes y Productos. Las rutas administrativas antiguas siguen disponibles de forma oculta; los enlaces y los formularios del auditor HTTP apuntan a la pantalla unificada.
