<?php
/**
 * Read-only in-plugin user and technical manuals.
 *
 * @package TaxoCommerceImportExport
 */

defined( 'ABSPATH' ) || exit;

/**
 * Documentation aligned with the existing TaxoCommerce family tab pattern.
 */
final class TaxoCommerce_Import_Export_Manual {

	/**
	 * Documents the current implementation; no side effects.
	 *
	 * @return array
	 */
	public static function sections() {
		return array(
			array(
				'id' => 'tc-ie-manual-access', 'group' => 'user',
				'title' => __( '1. Acceso y recomendaciones', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'Abre TaxoCommerce Import-Export, directamente o desde TaxoCommerce Core si está activo. Las pestañas son Exportación, Importación individual, Importación por lotes, Auto Test y Manual.', 'taxocommerce-import-export' ),
					__( 'Para utilizar el módulo necesitas la capacidad configurada en taxocommerce_import_export_capability (por defecto manage_woocommerce), o manage_options.', 'taxocommerce-import-export' ),
					__( 'Antes de importar, guarda una copia de seguridad de base de datos y uploads; realiza una prueba con una muestra pequeña en staging. Leer este manual no cambia ningún dato.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-export', 'group' => 'user',
				'title' => __( '2. Exportar CSV', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'En Exportación selecciona el tipo de dato, configura los filtros y elige velocidad de 1 (suave) a 5 (máxima); 3 es el valor recomendado. Pulsa Crear CSV por bloques.', 'taxocommerce-import-export' ),
					__( 'El proceso escribe el CSV en bloques y muestra su progreso. Puedes reintentar un bloque, cancelar el trabajo o descargar el CSV terminado. El estado se conserva para recuperarlo tras recargar la pantalla.', 'taxocommerce-import-export' ),
					__( 'No confundas un trabajo parcial con un archivo finalizado. Los archivos CSV no incorporan las imágenes binarias de la biblioteca de Media.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-filters', 'group' => 'user',
				'title' => __( '3. Estados y filtros del catálogo', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'Puedes filtrar por estados: publicado, borrador, pendiente de revisión, privado, programado y papelera. Si no eliges estados, se incluyen los admitidos para el tipo seleccionado.', 'taxocommerce-import-export' ),
					__( 'En productos puedes combinar Cluster, Hub primario, Hub secundario y categorías WooCommerce. Los filtros se cruzan; si eliges una rama y categorías concretas se exporta la intersección.', 'taxocommerce-import-export' ),
					__( 'Los filtros de jerarquía requieren que los datos correspondientes estén disponibles. Sin TaxoCommerce Core se mantienen los filtros por categorías y estados.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-csv', 'group' => 'user',
				'title' => __( '4. Productos completos y reducidos', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'La exportación completa incluye los campos comerciales y editoriales del producto (identidad, tipo, SKU, precios, inventario, contenido, categorías, atributos y datos de proveedor disponibles).', 'taxocommerce-import-export' ),
					__( 'La exportación reducida transporta identificación, estructura, categorías, etiquetas, marca, atributos y datos semánticos. Excluye textos largos, precios, stock e imágenes; no sustituye una copia de seguridad completa.', 'taxocommerce-import-export' ),
					__( 'Categorías, entradas y páginas disponen de sus propios contratos CSV. Comprueba siempre la cabecera antes de reutilizar un fichero como plantilla de importación.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-individual', 'group' => 'user',
				'title' => __( '5. Importación individual', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'Abre Importación individual, elige el CSV generado por el plugin y pulsa Importar CSV. El trabajo se procesa mediante la cola y el limitador de carga.', 'taxocommerce-import-export' ),
					__( 'El monitor muestra el fichero, filas procesadas, errores y mensajes. Las importaciones parciales de corrección solo aplican los campos presentes; valida el ID o SKU y las columnas antes de enviar un fichero masivo.', 'taxocommerce-import-export' ),
					__( 'Ante un error, consulta el detalle de las filas fallidas antes de repetir el fichero. No reimportes a ciegas un archivo que ya haya procesado parte de sus filas.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-queue', 'group' => 'user',
				'title' => __( '6. Importación por lotes', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'Selecciona hasta 20 CSV por envío. Guardar sin iniciar (pausado) los deja pendientes; Añadir e iniciar activa la cola. El orden natural del nombre determina la secuencia: 001_..., 002_..., etc.', 'taxocommerce-import-export' ),
					__( 'El gestor separa Pendientes, En proceso, Importados y Fallidos. Puedes eliminar un CSV aún pendiente; no se permite borrar el archivo activo mediante ese control.', 'taxocommerce-import-export' ),
					__( 'Los archivos se almacenan en una cola privada bajo uploads/taxocommerce-import-export/queue. No edites ni muevas los archivos manualmente durante la ejecución.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-controls', 'group' => 'user',
				'title' => __( '7. Controles, progreso y recuperación', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'Guardar velocidad ajusta los futuros bloques entre 1 y 5; el regulador adapta la carga a tiempo y memoria. El progreso representa bytes leídos del CSV actual, no productos nuevos.', 'taxocommerce-import-export' ),
					__( 'Iniciar / continuar reanuda la cola; Pausar después del archivo actual respeta el trabajo en curso; Detener importación actual conserva el offset; Procesar siguiente bloque solicita otra unidad de trabajo.', 'taxocommerce-import-export' ),
					__( 'La continuación puede usar solicitudes del navegador, WP-Cron, Action Scheduler (si existe) y un watchdog como respaldo. Si no avanza, revisa el último mensaje, los permisos del servidor y las peticiones internas de WordPress.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-countries', 'group' => 'user',
				'title' => __( '8. País de productos y contenidos', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'La columna country_code (también reconocida como pais_origen) permite exportar e importar el país de productos, categorías, entradas y páginas. Los códigos admitidos son ES, US, FR, DE y NL.', 'taxocommerce-import-export' ),
					__( 'Un CSV que no incluye el campo conserva el país existente. Un valor vacío en una importación completa puede borrar la asociación; en correcciones parciales de producto se ignora el país vacío.', 'taxocommerce-import-export' ),
					__( 'No se asigna España por defecto. Revisa país, producto y jerarquía de categorías en una muestra antes de importar catálogos internacionales.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-media', 'group' => 'user',
				'title' => __( '9. Archivos ZIP de Media', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'En Exportación utiliza Descargar ZIP de Media. El paquete contiene manifest.json, media.csv y los archivos originales en files/<ID anterior>/. Este ZIP es independiente de los CSV por bloques.', 'taxocommerce-import-export' ),
					__( 'En Importación individual utiliza Importar ZIP de Media. Se crean adjuntos reales, se regeneran miniaturas y se intenta restaurar imágenes destacadas, galerías y relaciones con categorías.', 'taxocommerce-import-export' ),
					__( 'Realiza copia de seguridad y comprueba que los productos y categorías de destino existan. La restauración de Media no descarga imágenes externas de proveedores ni garantiza relaciones con elementos inexistentes.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-autotest', 'group' => 'user',
				'title' => __( '10. Auto Test y comprobación del despliegue', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'La pestaña se llama Auto Test, igual que en el resto de TaxoCommerce. Permite ejecutar verificaciones de componentes, cabeceras, países, colas, exportación, Media, seguridad y regulador sin importar datos de prueba al catálogo.', 'taxocommerce-import-export' ),
					__( 'Guarda la línea base Antes de un despliegue, ejecuta Después en el mismo sitio y compara el informe por identificador de comprobación. Puedes exportar los resultados JSON saneados.', 'taxocommerce-import-export' ),
					__( 'Las operaciones reales de importar CSV, descargar CSV, restaurar Media o continuar una cola sin navegador requieren pruebas manuales: Auto Test no sustituye esas comprobaciones.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-errors', 'group' => 'user',
				'title' => __( '11. Diagnóstico de incidencias', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'Si el CSV falla, revisa cabecera, delimitador, tipo de registro, codificación y columna de identidad. Examina el detalle por fila antes de repetir o corregir el archivo.', 'taxocommerce-import-export' ),
					__( 'Si la cola no avanza, comprueba heartbeat, WP-Cron, límite de memoria y directorios de uploads. Reanuda mediante los controles previstos; no manipules manualmente el offset.', 'taxocommerce-import-export' ),
					__( 'Si Media falla, comprueba ZipArchive, límites de subida, estructura del ZIP, originales presentes y objetos de destino. Conserva el ZIP original para poder reproducir el problema.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-architecture', 'group' => 'technical',
				'title' => __( '12. Arquitectura de PHP', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'La entrada es taxocommerce-import-export.php; TaxoCommerce_Import_Export coordina menú y servicios. Las clases CSV, Exporter, Batch, Media, IE_Load y Auto_Test implementan sus respectivas responsabilidades.', 'taxocommerce-import-export' ),
					__( 'El plugin puede integrarse con TaxoCommerce Core, pero conserva el menú propio cuando Core no está disponible. WooCommerce es una dependencia declarada para las funciones de catálogo.', 'taxocommerce-import-export' ),
					__( 'Manual es una clase aislada de solo lectura. No registra acciones de escritura, no instala tablas, no cambia el regulador y no realiza consultas para mostrar su contenido.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-endpoints', 'group' => 'technical',
				'title' => __( '13. Rutas, capacidades y validación', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'Las rutas admin-post de CSV son taxocommerce_import_export_export e import; Media utiliza taxocommerce_import_export_media_export y media_import. Exporter y Batch registran callbacks AJAX y acciones de control.', 'taxocommerce-import-export' ),
					__( 'Los controladores deben comprobar la capacidad configurada y verificar nonces antes de procesar datos. Los estados y categorías se normalizan con listas permitidas; las descargas de exportación requieren autorización.', 'taxocommerce-import-export' ),
					__( 'La pestaña Manual usa el mismo control de acceso que el resto de pantallas. No añade AJAX, formularios, endpoints ni operaciones destructivas.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-data', 'group' => 'technical',
				'title' => __( '14. Estructura de datos y persistencia', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'CSV normaliza cabeceras, infiere tipos compatibles y aplica cada fila; country_code se almacena en metadatos _seo_pais_origen. Las categorías usan term meta y los contenidos usan post meta.', 'taxocommerce-import-export' ),
					__( 'Batch conserva estado, locks y cursores, con cuatro carpetas pending, processing, imported y failed en uploads/taxocommerce-import-export/queue. Sus mecanismos de bloqueo protegen del borrado de archivos en proceso.', 'taxocommerce-import-export' ),
					__( 'Exporter genera CSV incrementales y conserva un estado temporal asociado al usuario. Media utiliza manifest.json, media.csv y originales en ZIP, reconstruyendo adjuntos y relaciones tras la importación.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-extension', 'group' => 'technical',
				'title' => __( '15. Integraciones y puntos de extensión', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'El filtro taxocommerce_import_export_capability regula la capacidad de uso. taxocommerce_import_export_batch_importers permite registrar miniimportadores CSV de otros módulos; verifica el contrato y las entradas del manejador antes de extenderlo.', 'taxocommerce-import-export' ),
					__( 'La exportación de atributos y Vocabulary canónico es opcional y solo se incorpora si están disponibles sus tablas y clases. No hagas depender las importaciones básicas de módulos adicionales.', 'taxocommerce-import-export' ),
					__( 'Mantén separadas las cargas de Media, CSV y la cola. Cambios en cabeceras, metadatos, países o estado persistente requieren pruebas de compatibilidad hacia atrás.', 'taxocommerce-import-export' ),
				),
			),
			array(
				'id' => 'tc-ie-manual-release', 'group' => 'technical',
				'title' => __( '16. CI, publicación y regresiones', 'taxocommerce-import-export' ),
				'body' => array(
					__( 'Antes de publicar, ejecuta PHP lint, pruebas smoke de Auto Test, colas, CSV, países, guardado sin cambios, regulador y navegador. Genera el ZIP sin tests, ficheros ocultos, backups ni material de desarrollo.', 'taxocommerce-import-export' ),
					__( 'WordPress Plugin Check debe ejecutarse contra el ZIP distribuible sin ignorar errores ni advertencias. En staging compara Auto Test Antes y Después y realiza pruebas reales de importación, exportación y Media.', 'taxocommerce-import-export' ),
					__( 'No declares un despliegue completo sin comprobar resultados en WordPress. Los scripts de tests siguen en GitHub, pero no se instalan dentro del plugin distribuido.', 'taxocommerce-import-export' ),
				),
			),
		);
	}

	/**
	 * Renders both manuals using native WordPress admin presentation.
	 *
	 * @return void
	 */
	public static function render() {
		$capability = (string) apply_filters( 'taxocommerce_import_export_capability', 'manage_woocommerce' );
		if ( ! current_user_can( $capability ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para consultar el manual.', 'taxocommerce-import-export' ) );
		}

		$sections = self::sections();
		echo '<section class="tc-ie-manual" aria-label="' . esc_attr__( 'Manual de TaxoCommerce Import-Export', 'taxocommerce-import-export' ) . '">';
		echo '<style>.tc-ie-manual{max-width:1200px;margin-top:20px}.tc-ie-manual__index,.tc-ie-manual__card{padding:20px 24px;background:#fff;border:1px solid #dcdcde;border-radius:6px;margin:16px 0}.tc-ie-manual__index a{display:inline-block;margin:5px 14px 5px 0}.tc-ie-manual__card{scroll-margin-top:32px}.tc-ie-manual__card p{line-height:1.7}.tc-ie-manual h2{margin:22px 0 12px}.tc-ie-manual h3{margin:0 0 14px}.tc-ie-manual__index{overflow-wrap:anywhere}@media(max-width:782px){.tc-ie-manual__index,.tc-ie-manual__card{padding:14px}}</style>';
		echo '<h2>' . esc_html__( 'Manual de TaxoCommerce Import-Export', 'taxocommerce-import-export' ) . '</h2>';
		echo '<p>' . esc_html__( 'Manual de usuario y manual técnico integrados. Consultarlos no inicia procesos ni modifica el catálogo.', 'taxocommerce-import-export' ) . '</p>';
		foreach ( array( 'user' => __( 'Manual de usuario', 'taxocommerce-import-export' ), 'technical' => __( 'Manual técnico', 'taxocommerce-import-export' ) ) as $group => $title ) {
			echo '<nav class="tc-ie-manual__index" aria-label="' . esc_attr( $title ) . '"><strong>' . esc_html( $title ) . '</strong><div>';
			foreach ( $sections as $section ) {
				if ( $section['group'] === $group ) {
					echo '<a href="#' . esc_attr( $section['id'] ) . '">' . esc_html( $section['title'] ) . '</a>';
				}
			}
			echo '</div></nav>';
			echo '<h2>' . esc_html( $title ) . '</h2>';
			foreach ( $sections as $section ) {
				if ( $section['group'] !== $group ) {
					continue;
				}
				echo '<section class="tc-ie-manual__card" id="' . esc_attr( $section['id'] ) . '">';
				echo '<h3>' . esc_html( $section['title'] ) . '</h3>';
				foreach ( $section['body'] as $paragraph ) {
					echo '<p>' . esc_html( $paragraph ) . '</p>';
				}
				echo '</section>';
			}
		}
		echo '</section>';
	}
}
