# TaxoCommerce System Check

Plugin WordPress independiente en `taxocommerce-system-check/`.

## Alcance inicial

Se copian los dos chequeos del monolito TaxoCommerce SEO sin redisenar sus
algoritmos: Server Status y Plugin Validation. Se incluyen sus modulos
auxiliares, historial y el motor de informes PDF/JSON y correos.

El archivo de escaneo externo `seo-health-scan.php` queda copiado como parte
del inventario, pero no se carga automaticamente: depende de GitHub Actions
y crea tablas separadas fuera de esta primera extraccion.

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
