<?php
/**
 * Read-only user and technical manuals for TaxoCommerce FAQs.
 *
 * @package TaxoCommerceFAQs
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_FAQs_Manual {
	/**
	 * Operational guidance for administrators and maintainers.
	 *
	 * @return array
	 */
	public static function sections() {
		return array(
			array(
				'id' => 'tc-faq-manual-access', 'group' => 'user',
				'title' => __( '1. Acceso y precauciones', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'Abre TaxoCommerce FAQs desde el menú propio o desde Contenidos de TaxoCommerce Core, cuando esté activo. Las pestañas son FAQs, Auto Test y Manual. La administración requiere manage_options.', 'taxocommerce-faqs' ),
					__( 'El manual es de solo lectura. No crea ni altera preguntas, categorías, etiquetas, registros o configuraciones.', 'taxocommerce-faqs' ),
					__( 'Antes de operaciones masivas exporta el CSV y guarda una copia de seguridad de la base de datos. La tabla de preguntas también puede utilizarse desde otros módulos TaxoCommerce.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-overview', 'group' => 'user',
				'title' => __( '2. Inventario e indicadores', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'La pestaña FAQs muestra total de preguntas, activas, desactivadas, destinos distintos, categorías cubiertas, preguntas sin categoría y aperturas registradas.', 'taxocommerce-faqs' ),
					__( 'Utiliza Buscar para filtrar por el texto de la pregunta y el desplegable de categoría para revisar un grupo concreto. La lista muestra hasta 25 FAQs por página.', 'taxocommerce-faqs' ),
					__( 'Los indicadores reflejan los datos existentes en la tabla canónica: no hacen auditorías de contenido ni sustituyen una comprobación editorial.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-create', 'group' => 'user',
				'title' => __( '3. Crear una pregunta frecuente', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'En Nueva FAQ selecciona una categoría de producto WooCommerce existente. Es obligatoria para las nuevas preguntas, incluso cuando el destino sea una página o un producto.', 'taxocommerce-faqs' ),
					__( 'Escoge Destino: Categoría, Hub / Página o Producto. Indica el ID del destino cuando sea necesario; para Categoría puede utilizarse la categoría seleccionada.', 'taxocommerce-faqs' ),
					__( 'Introduce Pregunta y Respuesta, configura el orden y marca FAQ activa si deseas habilitarla. Guarda con Crear FAQ. La pregunta se limita a 255 caracteres; el destino debe existir.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-edit', 'group' => 'user',
				'title' => __( '4. Editar, activar y desactivar', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'Desde Inventario pulsa Editar para recuperar una FAQ existente. Cambia sus campos y pulsa Guardar FAQ. La aplicación conserva su identificador.', 'taxocommerce-faqs' ),
					__( 'Desmarca FAQ activa para mantener la pregunta guardada sin publicarla. La disponibilidad final en el sitio público depende de los módulos que consumen estas preguntas.', 'taxocommerce-faqs' ),
					__( 'Las preguntas heredadas sin categoría siguen disponibles para revisión. Al guardar una nueva versión selecciona una categoría válida; la activación del plugin no publica automáticamente las preguntas anteriores.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-destinations', 'group' => 'user',
				'title' => __( '5. Destinos y categoría obligatoria', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'Los tipos de destino son 1 (Hub / Página), 2 (Categoría de producto) y 3 (Producto). El ID debe apuntar a un objeto real de WordPress o WooCommerce.', 'taxocommerce-faqs' ),
					__( 'product_cat_id establece la categoría de producto de la FAQ. No confundas esta asociación editorial con el ID del objeto de destino cuando corresponda a una página o un producto.', 'taxocommerce-faqs' ),
					__( 'El indicador Sin categoría permite localizar registros históricos que todavía requieren clasificación. No modifiques el esquema directamente para forzar asociaciones.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-vocabulary', 'group' => 'user',
				'title' => __( '6. Etiquetas semánticas de Vocabulary', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'Si están disponibles seo_vocabulary y seo_object_vocabulary, la edición permite seleccionar etiquetas ya existentes en los grupos rol, tipo, aplicación, plataforma y subtipo.', 'taxocommerce-faqs' ),
					__( 'Las etiquetas no se crean desde FAQs. Si Vocabulary no está disponible, se mantiene la edición de preguntas y las relaciones anteriores no se eliminan por ausencia del módulo.', 'taxocommerce-faqs' ),
					__( 'Revisa las etiquetas antes de guardar una edición. No confundas los términos semánticos con categorías WooCommerce: se almacenan en relaciones distintas.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-export', 'group' => 'user',
				'title' => __( '7. Exportar FAQs a CSV', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'En Importar / Exportar CSV pulsa Exportar FAQs CSV. Guarda el fichero UTF-8 resultante antes de aplicar correcciones masivas o actualizar componentes.', 'taxocommerce-faqs' ),
					__( 'El formato transporta identificadores, destino, categoría, pregunta, respuesta, orden, estado y etiquetas semánticas disponibles. Las etiquetas se representan mediante slugs separados por barras verticales.', 'taxocommerce-faqs' ),
					__( 'Comprueba el fichero descargado y conserva una copia inalterada. La exportación no equivale a un respaldo de todas las tablas WordPress.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-import', 'group' => 'user',
				'title' => __( '8. Importar FAQs desde CSV', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'Utiliza un CSV UTF-8 compatible con las cabeceras exportadas por este plugin. El límite por importación es 500 filas y 2 MB.', 'taxocommerce-faqs' ),
					__( 'Si una fila contiene el ID de una pregunta existente se actualiza ese registro; si el ID es desconocido o cero, se crea una pregunta con ID local nuevo. Verifica el destino y la categoría válida antes de importar.', 'taxocommerce-faqs' ),
					__( 'Las respuestas pueden contener comas, comillas y saltos de línea si el CSV está correctamente entrecomillado. Revisa los mensajes de error, las preguntas afectadas y la copia de seguridad antes de repetir una importación.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-delete', 'group' => 'user',
				'title' => __( '9. Borrado y conservación de datos', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'El botón Eliminar de una fila solicita confirmación y borra esa FAQ. Esta operación es permanente: exporta los datos antes de borrar.', 'taxocommerce-faqs' ),
					__( 'Desactivar el plugin NO borra sus preguntas. En cambio, eliminar TaxoCommerce FAQs desde WordPress ejecuta uninstall.php y elimina la tabla compartida seo_faq, sus preguntas y relaciones semánticas FAQ.', 'taxocommerce-faqs' ),
					__( 'Nunca utilices desinstalar como prueba en producción: las preguntas también pueden ser utilizadas por TaxoCommerce SEO. Para ensayar desinstalación usa un WordPress desechable y un respaldo verificable.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-autotest', 'group' => 'user',
				'title' => __( '10. Auto Test antes y después de actualizar', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'Abre la pestaña Auto Test y pulsa Guardar estado ANTES antes del despliegue. Después de instalar la nueva versión pulsa Comprobar DESPUÉS en el mismo sitio.', 'taxocommerce-faqs' ),
					__( 'El chequeo muestra pruebas correctas, errores, omitidas y no ejecutadas, agrupadas por módulo. Se pueden ejecutar grupos independientes y descargar resultados JSON.', 'taxocommerce-faqs' ),
					__( 'Auto Test no importa, modifica ni elimina preguntas. Las pruebas de creación, edición, CSV, frontend y desinstalación quedan señaladas como manuales y deben realizarse en un entorno de prueba.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-help', 'group' => 'user',
				'title' => __( '11. Resolución de incidencias', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'Si falta la tabla de FAQs, no fuerces importaciones: revisa instalación, permisos SQL y estado de la tabla antes de iniciar una migración. Auto Test puede verificar el esquema sin modificarlo.', 'taxocommerce-faqs' ),
					__( 'Si no aparece una pregunta revisa su estado activo, destino, categoría y el módulo que presenta FAQs en el frontend. Un registro guardado no garantiza por sí solo su visualización pública.', 'taxocommerce-faqs' ),
					__( 'Si fallan las etiquetas comprueba las tablas opcionales Vocabulary. Para errores de CSV revisa cabecera, comillas, UTF-8, máximo de filas y tamaño del fichero.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-architecture', 'group' => 'technical',
				'title' => __( '12. Arquitectura y responsabilidades', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'La entrada taxocommerce-faqs.php carga TaxoCommerce_FAQs (editor y CSV), TaxoCommerce_FAQs_Lifecycle (instalación y desinstalación), y TaxoCommerce_FAQs_Auto_Test (diagnóstico). TaxoCommerce_FAQs_Manual documenta el módulo sin ejecutar procesos.', 'taxocommerce-faqs' ),
					__( 'TaxoCommerce FAQs utiliza la tabla histórica compartida [prefijo]seo_faq como fuente de verdad. No se crea un CPT ni se duplica la tabla del monolito.', 'taxocommerce-faqs' ),
					__( 'La integración con TaxoCommerce Core es opcional: registra el módulo faqs para Contenidos y ofrece menú administrativo cuando Core no existe.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-schema', 'group' => 'technical',
				'title' => __( '13. Esquema y migración', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'La tabla seo_faq incluye id, object_type, object_id, product_cat_id, question, answer, sort_order, active, load_count, open_count, created_at y updated_at.', 'taxocommerce-faqs' ),
					__( 'El activador crea o actualiza de forma incremental la tabla, preservando las filas existentes y añadiendo product_cat_id cuando falta. Solo completa automáticamente categorías heredadas inequívocas.', 'taxocommerce-faqs' ),
					__( 'En multisitio, la inicialización usa el prefijo de cada sitio y restaura el contexto original. Auto Test solo consulta el esquema: jamás invoca instaladores ni migraciones.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-api', 'group' => 'technical',
				'title' => __( '14. Rutas, permisos y seguridad', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'Las acciones de administración son admin_post_taxocommerce_faqs_save, _delete, _export e _import. Todas requieren capacidad manage_options y validación de nonce en su controlador.', 'taxocommerce-faqs' ),
					__( 'La pestaña Auto Test ofrece acciones propias para ejecutar comprobaciones y descargar JSON, protegidas también mediante capacidad y nonce. Manual es únicamente una pantalla de lectura, sin formularios de escritura.', 'taxocommerce-faqs' ),
					__( 'Las entradas de preguntas se sanean y las respuestas admiten HTML permitido por WordPress. Mantén escaping en salida y nunca expongas SQL, trazas, contenido editorial o rutas internas en resultados de diagnóstico.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-csv', 'group' => 'technical',
				'title' => __( '15. Contrato CSV y Vocabulary', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'CSV incluye id, object_type, object_id, product_cat_id, question, answer, sort_order y active, además de grupos semánticos rol, tipo, aplicacion, plataforma y subtipo cuando procede.', 'taxocommerce-faqs' ),
					__( 'La importación CSV valida límites de 500 filas y 2 MB y la relación con categorías reales. El parser propio permite celdas entrecomilladas con saltos de línea sin truncar respuestas.', 'taxocommerce-faqs' ),
					__( 'Las relaciones de etiquetas se almacenan en seo_object_vocabulary con object_type=faq, usando términos ya existentes de seo_vocabulary. Ambos elementos son opcionales y no deben ser eliminados por una migración de FAQs.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-lifecycle', 'group' => 'technical',
				'title' => __( '16. Ciclo de vida y riesgos de desinstalación', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'La activación se registra mediante register_activation_hook; la desactivación no borra datos. Solo uninstall.php puede iniciar la limpieza permanente, condicionada a WP_UNINSTALL_PLUGIN y los marcadores de inicialización.', 'taxocommerce-faqs' ),
					__( 'La desinstalación elimina seo_faq y las relaciones object_type=faq, pero preserva Vocabulary, categorías y otras relaciones. Una eliminación puede afectar a TaxoCommerce SEO porque comparte los datos FAQ.', 'taxocommerce-faqs' ),
					__( 'Nunca lances pruebas destructivas en producción. Verifica instalación, actualización y limpieza de multisitio mediante tests aislados y un sitio desechable.', 'taxocommerce-faqs' ),
				),
			),
			array(
				'id' => 'tc-faq-manual-qa', 'group' => 'technical',
				'title' => __( '17. Pruebas y despliegue', 'taxocommerce-faqs' ),
				'body' => array(
					__( 'En GitHub se mantienen las pruebas CLI de menú, esquema, ciclo de vida, desinstalación y Auto Test. Estas pruebas no deben instalarse dentro del ZIP para WordPress.', 'taxocommerce-faqs' ),
					__( 'Antes de desplegar ejecuta PHP lint compatible con PHP 7.4, las regresiones, Plugin Check del paquete y un Auto Test ANTES/DESPUÉS. Comprueba también el menú con Core y sin Core.', 'taxocommerce-faqs' ),
					__( 'Valida manualmente crear, editar, desactivar, CSV y presentación pública con datos de prueba. No declares la actualización aceptada solo porque las comprobaciones estructurales pasen.', 'taxocommerce-faqs' ),
				),
			),
		);
	}

	/**
	 * Renders documentation only: no SQL, file IO, AJAX or writes.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos para consultar el manual.', 'taxocommerce-faqs' ) );
		}
		$sections = self::sections();
		echo '<section class="tc-faq-manual" aria-label="' . esc_attr__( 'Manual de TaxoCommerce FAQs', 'taxocommerce-faqs' ) . '">';
		echo '<style>.tc-faq-manual{max-width:1200px;margin:20px 0}.tc-faq-manual__index,.tc-faq-manual__card{padding:20px 24px;background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:16px 0}.tc-faq-manual__index a{display:inline-block;margin:5px 14px 5px 0}.tc-faq-manual__card{scroll-margin-top:32px}.tc-faq-manual__card p{line-height:1.7}.tc-faq-manual h2{margin:22px 0 12px}.tc-faq-manual h3{margin:0 0 14px}.tc-faq-manual__index{overflow-wrap:anywhere}@media(max-width:782px){.tc-faq-manual__index,.tc-faq-manual__card{padding:14px}}</style>';
		echo '<h2>' . esc_html__( 'Manual de TaxoCommerce FAQs', 'taxocommerce-faqs' ) . '</h2>';
		echo '<p>' . esc_html__( 'Manual de usuario y manual técnico integrados. Su consulta no modifica preguntas ni inicia trabajos.', 'taxocommerce-faqs' ) . '</p>';
		foreach ( array( 'user' => __( 'Manual de usuario', 'taxocommerce-faqs' ), 'technical' => __( 'Manual técnico', 'taxocommerce-faqs' ) ) as $group => $title ) {
			echo '<nav class="tc-faq-manual__index" aria-label="' . esc_attr( $title ) . '"><strong>' . esc_html( $title ) . '</strong><div>';
			foreach ( $sections as $section ) {
				if ( $section['group'] === $group ) {
					echo '<a href="#' . esc_attr( $section['id'] ) . '">' . esc_html( $section['title'] ) . '</a>';
				}
			}
			echo '</div></nav><h2>' . esc_html( $title ) . '</h2>';
			foreach ( $sections as $section ) {
				if ( $section['group'] !== $group ) {
					continue;
				}
				echo '<section class="tc-faq-manual__card" id="' . esc_attr( $section['id'] ) . '"><h3>' . esc_html( $section['title'] ) . '</h3>';
				foreach ( $section['body'] as $paragraph ) {
					echo '<p>' . esc_html( $paragraph ) . '</p>';
				}
				echo '</section>';
			}
		}
		echo '</section>';
	}
}
