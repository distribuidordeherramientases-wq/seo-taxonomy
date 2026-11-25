# Procedimiento — Import-Export Lite 0.1.1

1. Instalar WooCommerce y activar **Import-Export Lite** en una instalación limpia de WordPress. No hace falta TaxoCommerce Core ni el plugin Import-Export antiguo.
2. Abrir **Import-Export Lite → Importar CSV**; subir un solo `.csv` UTF-8 (hasta 30 MB), elegir el contenido y revisar las asignaciones automáticas.
3. Dejar **Crear nuevos y actualizar existentes**. No utilizar «Solo actualizar» cuando se crean fichas nuevas.
4. Elige campos nativos y, cuando existan en esta instalación, campos personalizados públicos (`meta:clave`) o atributos WooCommerce (`attr:pa_color`). Los datos reservados de WordPress/WooCommerce no se muestran. Para productos, incluir `sku,name,slug,status,regular_price`. Para plugins descargables se añaden `virtual,downloadable,download_1_name,download_1_url` y, opcionalmente, límite/caducidad.
5. No es necesario importar categorías ni etiquetas. Su asignación es voluntaria y necesita términos nativos existentes.
6. Pulsar **Comprobar CSV**: el sistema recorre el archivo completo sin guardar ningún registro. Revisa los errores por fila y corrige el CSV o la asignación; si todo es válido aparecerá **Confirmar e importar**. Solo esa segunda acción guarda cambios.
7. Verificar filas guardadas, posibles errores posteriores de persistencia y borradores antes de publicarlos.
8. Para exportar, abrir **Exportar CSV**, seleccionar tipo, marcar las columnas y descargar. La misma estructura puede usarse en otra instalación.
9. Ejecutar **Chequeo** y revisar el manual de usuario y técnico. Antes de publicar utilizar WordPress Plugin Check.

## Seguridad y alcance

- El original `taxocommerce-import-export` queda intacto; no borrar ni migrar su configuración. La futura edición Pro partirá de **este Lite nuevo**, recuperando solo capacidades útiles del antiguo mediante código nuevo.
- Ningún registro de producción debe modificarse sin autorización expresa. PRO no se despliega automáticamente.
- Los ZIP de pago requieren descargas protegidas configuradas en WooCommerce; una URL pública en Media no queda protegida solo por asociarla al producto.
- La edición 0.1.1 **no** migra pedidos, clientes, variaciones hijo ni ficheros binarios; los valores de atributos globales deben existir previamente. Los campos personalizados admitidos son escalares y públicos, no datos serializados o internos.
- No instalar Lite donde el plugin no está autorizado: hacer primero pruebas aisladas y obtener permiso de instalación en los destinos.
