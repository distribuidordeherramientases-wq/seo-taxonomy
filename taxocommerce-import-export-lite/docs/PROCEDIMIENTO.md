# Procedimiento — Import-Export Lite 0.1.0

1. Instalar WooCommerce y activar **Import-Export Lite** en una instalación limpia de WordPress. No hace falta TaxoCommerce Core ni el plugin Import-Export antiguo.
2. Abrir **Import-Export Lite → Importar CSV**; subir un solo `.csv` UTF-8 (hasta 30 MB), elegir el contenido y revisar las asignaciones automáticas.
3. Dejar **Crear nuevos y actualizar existentes**. No utilizar «Solo actualizar» cuando se crean fichas nuevas.
4. Para productos, incluir `sku,name,slug,status,regular_price`. Para plugins descargables se añaden `virtual,downloadable,download_1_name,download_1_url` y, opcionalmente, límite/caducidad.
5. No es necesario importar categorías ni etiquetas. Su asignación es voluntaria y necesita términos nativos existentes.
6. Pulsar **Iniciar importación**. Comprobar filas tratadas, guardadas y errores. Revisar los borradores antes de publicarlos.
7. Para exportar, abrir **Exportar CSV**, seleccionar tipo, marcar las columnas y descargar. La misma estructura puede usarse en otra instalación.
8. Ejecutar **Chequeo** y revisar el manual de usuario y técnico. Antes de publicar utilizar WordPress Plugin Check.

## Seguridad y alcance

- El original `taxocommerce-import-export` queda intacto; no borrar ni migrar su configuración. La futura edición Pro partirá de él en una implementación independiente.
- Ningún registro de producción debe modificarse sin autorización expresa. PRO no se despliega automáticamente.
- Los ZIP de pago requieren descargas protegidas configuradas en WooCommerce; una URL pública en Media no queda protegida solo por asociarla al producto.
- La edición 0.1.0 **no** importa pedidos, clientes, atributos avanzados, variaciones hijo ni ficheros binarios.
- No instalar Lite donde el plugin no está autorizado: hacer primero pruebas aisladas y obtener permiso de instalación en los destinos.
