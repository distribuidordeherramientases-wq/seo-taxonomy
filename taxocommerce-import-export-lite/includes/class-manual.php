<?php
/** Embedded Lite user and technical manuals. */
namespace TaxoCommerce\ImportExportLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Manual {
	public static function user() {
		echo '<section class="tciel-panel"><h2>' . esc_html__( 'Manual de usuario', 'taxocommerce-import-export-lite' ) . '</h2>';
		echo '<h3>' . esc_html__( 'Importación por bloques', 'taxocommerce-import-export-lite' ) . '</h3><ol>';
		foreach ( array(
			__( 'Prepara un CSV con cabeceras (por ejemplo sku,name,regular_price,virtual,downloadable,download_1_name,download_1_url).', 'taxocommerce-import-export-lite' ),
			__( 'En Importar CSV, busca el bloque del contenido que necesitas: páginas, productos, categorías, entradas o etiquetas.', 'taxocommerce-import-export-lite' ),
			__( 'Adjunta el CSV UTF-8 y pulsa Cargar CSV en ese bloque.', 'taxocommerce-import-export-lite' ),
			__( 'Las columnas se reconocen automáticamente. Se muestran las cabeceras reconocidas y omitidas. Los IDs ajenos nunca se usan.', 'taxocommerce-import-export-lite' ),
			__( 'Pulsa Comprobar CSV. Si no hay errores, confirma la importación. Los registros nuevos se crean y los existentes se actualizan automáticamente.', 'taxocommerce-import-export-lite' ),
		) as $item ) echo '<li>' . esc_html( $item ) . '</li>';
		echo '</ol><h3>' . esc_html__( 'Exportación', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'En Exportar CSV pulsa Descargar CSV en el bloque correspondiente. Puedes elegir columnas en Campos opcionales de exportación. Si el CSV incluye relaciones de categorías o etiquetas, los términos deben existir previamente en el destino.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Campos personalizados y atributos de WooCommerce', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'El reconocimiento automático admite los campos personalizados públicos y escalares guardados en las tablas estándar de WordPress, además de los atributos globales de WooCommerce y los locales detectados en productos existentes. Las cabeceras del CSV exportado tienen formato meta:clave o attr:pa_color. Los campos internos protegidos, los valores serializados y las tablas de otros plugins no se importan ni exportan. Los valores de atributos globales deben existir en WooCommerce antes de asignarlos.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Productos virtuales y descargables', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'Usa virtual=yes, downloadable=yes, download_1_name y download_1_url. Los archivos han de estar accesibles por HTTP(S); el plugin no los copia ni los descarga. La protección de acceso a los archivos depende de la configuración de descargas de WooCommerce.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<p>' . esc_html__( 'Las altas se crean como borradores salvo que el CSV indique publish y tengas permiso. Una importación repetida se identifica por SKU o slug. No son obligatorias las categorías ni las imágenes.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '</section>';
	}

	public static function technical() {
		echo '<section class="tciel-panel"><h2>' . esc_html__( 'Manual técnico', 'taxocommerce-import-export-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Versión Lite independiente: WordPress 6.7+, PHP 7.4+, WooCommerce activo. Sin Core, bases de datos propias, taxonomías SEO ni proveedores. No lee ni modifica datos del Import-Export anterior.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Arquitectura y seguridad', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'Usa las API estándar wp_insert_post, wp_insert_term y los objetos WC_Product. Permisos y nonces en cada acción. CSV temporal fuera de Media, token privado por usuario con expiración de 1 hora, lectura por lotes de 25 registros y límites de 30 MB. El tipo y las columnas reconocidas quedan vinculados al token privado en el servidor; no se pueden cambiar mediante la petición AJAX.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Identidad y taxonomías', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'Productos: busca primero SKU, después slug, y solo permite ID si no hay SKU ni slug. Páginas/entradas: slug primero. Categorías: slug/nombre. Los IDs del CSV se ignoran. Las cabeceras exactas categories, tags, parent_slug y featured_image solicitan expresamente relaciones con términos o imágenes existentes. Evita incluirlas si no existen en el destino.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Campos adaptados a cada instalación', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'Los campos personalizados se descubren mediante el registro de metadatos y las tablas nativas postmeta y termmeta. Los atributos globales se leen de WooCommerce y los locales se detectan en productos existentes. Las columnas no reconocidas y las celdas vacías no modifican datos. Antes de importar, la vista previa recorre todas las filas en lotes de 25; solo la confirmación posterior permite guardarlas.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Límites de la primera edición', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'Incluye los contenidos nativos y campos seleccionados. Permite exportar productos simples, variables, agrupados y externos; no migra variaciones hijo, pedidos, clientes, campos privados/serializados ni medios binarios. Estos flujos requieren herramientas específicas de WooCommerce y no se simula su compatibilidad.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Pruebas y compatibilidad', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'En la pestaña Chequeo se valida el arranque y las API disponibles; usa Plugin Check antes de distribuir. La exportación produce CSV estándar con cabeceras nativas. Se reconocen nombres canónicos y alias admitidos, sin asignación manual.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '</section>';
	}
}
