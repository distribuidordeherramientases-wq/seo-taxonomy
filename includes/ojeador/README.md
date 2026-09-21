# Ojeador 0.1.0

Primera version interna de inteligencia de precios/ofertas.

## Que hace

- Crea cuatro tablas propias: productos vigilados, ofertas actuales, historico de cambios y ejecuciones.
- Relaciona cada oferta externa con el `object_id` WooCommerce.
- Usa GTIN/EAN, MPN, marca/modelo, SKU y similitud de titulo con niveles de confianza.
- Reutiliza el catalogo `wp_seo_proveedores_productos` como una fuente cuando hay `object_id` o MPN coincidente.
- Permite agregar una URL externa manualmente; la inspecciona con `wp_safe_remote_get` y extrae JSON-LD/meta cuando existe.
- Guarda precio bruto/neto, IVA, transporte, total comparable, stock, comercio, URL y fecha de observacion.
- Solo crea una fila de historico cuando cambia precio/stock/condiciones normalizadas.
- Se integra en `SEO Taxonomy > Procesos > Ojeador` y en el Gestor de workers mediante los filtros extensibles existentes.
- Puede ejecutar un producto concreto, un piloto de N productos o un barrido semanal.
- El barrido automatico viene desactivado y no corre en STAGING por defecto.

## Que NO hace todavia

Ojeador 0.1.0 no raspa Google/Bing ni descubre por si solo URLs desconocidas. La capa de descubrimiento queda preparada mediante el filtro:

`seo_ojeador_offer_candidates`

La siguiente version puede conectar un runner/API de busqueda y devolver candidatos al mismo motor sin cambiar las tablas ni Dependiente.

## API interna

`seo_ojeador_get_comparison($product_id)`

Devuelve producto vigilado, ofertas y estadisticas min/mediana/max. Esta pensada para integrar Ojeador mas adelante en Dependiente, filtros, fichas y Analista.

## Despliegue

La carpeta debe quedar en:

`/includes/ojeador/`

Y `includes/procesos/bootstrap.php` carga `../ojeador/ojeador-bootstrap.php` al final.
