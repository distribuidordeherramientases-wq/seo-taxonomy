=== TaxoCommerce FAQs ===
Contributors: davidperezmartorell
Tags: faq, woocommerce, questions, csv, vocabulary
Requires at least: 6.5
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.1.3
License: GPLv2 or later

== Description ==

TaxoCommerce FAQs reutiliza la tabla canonica wp_seo_faq y se integra
como extension activable desde TaxoCommerce Core.

* FAQs de hubs/paginas, categorias y productos.
* Alta y edicion manual, activacion y eliminacion individual.
* KPIs de volumen, estado, destinos y aperturas.
* Importacion/exportacion CSV UTF-8.
* Etiquetas transversales con tablas Vocabulary, sin inventar taxonomias.

La pestaña FAQs aparece dentro de Core > Contenidos. Sin Core se ofrece
una pantalla propia. No activa nuevas FAQs ni altera la version SEO.

Cada nueva FAQ debe pertenecer a una categoria WooCommerce product_cat existente. Las FAQs heredadas no categorizadas permanecen visibles y se contabilizan para revision.


== Installation ==

== Instalación y desinstalación (0.1.3) ==

La activación crea la tabla [prefijo]seo_faq si no existe; si ya existe, conserva las preguntas y actualiza el esquema sin duplicar tablas. Se verifica la tabla y su columna product_cat_id antes de confirmar la activación. Desactivar o actualizar el plugin no borra datos.

ATENCIÓN: Eliminar el plugin desde WordPress borra definitivamente seo_faq, todas sus preguntas y las relaciones object_type=faq de seo_object_vocabulary. Esta tabla también es utilizada por el monolito TaxoCommerce SEO: exporta antes el CSV si vas a necesitar las preguntas. No se borran las categorías, las tablas ni los términos de Vocabulary, ni las relaciones de productos/posts. No se elimina seo_faq_metrics: pertenece al sistema histórico y no lo crea este módulo.

En multisitio la limpieza se limita a sitios con un marcador de inicialización de este plugin; no elimina tablas de sitios que nunca lo usaron. Subir el ZIP sin activarlo no reclama tablas de otros módulos. Borrar los archivos manualmente por FTP no ejecuta uninstall.php.
