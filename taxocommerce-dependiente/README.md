# TaxoCommerce Dependiente 0.1.0

Plugin independiente para WooCommerce con Academia inicial de diez lecciones: L1 jerarquía, L2 inventario, L3 TIPO/ROL, L4 atributos, L5 posts y páginas, L6 FAQs contextualizadas, L7 relaciones cruzadas, L8 examen, L9 necesidades y productos y L10 consolidación/deuda. **No incluye actualización continua ni reciclado.**

## Instalación
Instalar carpeta taxocommerce-dependiente desde el ZIP del plugin y activarlo con WooCommerce. Aparece en la familia TaxoCommerce Core y en el administrador. Las tablas nuevas se crean en la activación.

## Datos y ejecución
WordPress/WooCommerce como fuentes de verdad. No se utilizan las tablas antiguas seo_faq, seo_vocabulary, seo_object_vocabulary, seo_nodes ni la Academia monolítica. Se leen product_cat, posts, páginas, productos, etiquetas y taxonomías nativas tc_tipo, tc_rol, tc_semantic_tag cuando existen.

L6 requiere un CPT FAQ registrado (tc_faq, taxocommerce_faq o faq) y propietario producto/categoría identificable en postmeta o mediante filtro taxocommerce_dependiente_faq_owner. Si falta, muestra error y no inventa aprendizaje.

Los lotes son de 8 elementos, con cursor guardado en wp_options, parada explícita por formulario admin-post y registro persistente. La tabla propia wp_tcd_knowledge contiene fuentes y evidencia de aprendizaje. WP-Cron debe ejecutarse para avanzar. Reiniciar borra exclusivamente la memoria de este plugin; nunca productos o FAQs.

## Estado actual y límites
Primera versión funcional del recorrido y administración, **no equivalencia completa con el motor V3**. L8 y L10 realizan revisión estructural, no las evaluaciones complejas del motor antiguo. La interfaz de cara al cliente es por ahora un buscador de WordPress con resultados atribuibles, no un chat conversacional. Sin migración automática desde Dependiente antiguo. Comprobar las 10 lecciones, fuentes, parada y reanudación en staging antes de instalar en producción.
