<?php
/** Embedded Lite user and technical manuals. */
namespace TaxoCommerce\ImportExportLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Manual {
	public static function user() {
		echo '<section class="tciel-panel"><h2>' . esc_html__( 'Manual de usuario', 'taxocommerce-import-export-lite' ) . '</h2>';
		echo '<h3>' . esc_html__( 'Importación en cinco pasos', 'taxocommerce-import-export-lite' ) . '</h3><ol>';
		foreach ( array(
			__( 'Prepara un CSV con cabeceras (por ejemplo sku,name,regular_price,virtual,downloadable,download_1_name,download_1_url).', 'taxocommerce-import-export-lite' ),
			__( 'En Importar CSV selecciona un archivo CSV UTF-8.', 'taxocommerce-import-export-lite' ),
			__( 'Selecciona el tipo de contenido: producto, página, entrada, categoría o etiqueta.', 'taxocommerce-import-export-lite' ),
			__( 'Comprueba las columnas detectadas. Las categorías, las etiquetas, los IDs ajenos y las imágenes no se asignan automáticamente.', 'taxocommerce-import-export-lite' ),
			__( 'Deja «Crear nuevos y actualizar existentes» y pulsa Comprobar CSV. Tras revisar todas las filas, confirma expresamente la importación.', 'taxocommerce-import-export-lite' ),
		) as $item ) echo '<li>' . esc_html( $item ) . '</li>';
		echo '</ol><h3>' . esc_html__( 'Exportación', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'En Exportar CSV escoge el tipo, marca o desmarca los campos y descarga el CSV. Puedes reimportar ese archivo en otra tienda; no tienes que importar categorías salvo que quieras asignarlas expresamente.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Campos personalizados y atributos de WooCommerce', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'El selector muestra los campos personalizados públicos y escalares guardados en las tablas estándar de WordPress, además de los atributos globales de WooCommerce y los locales detectados en productos existentes. Las cabeceras del CSV exportado tienen formato meta:clave o attr:pa_color. Los campos internos protegidos, los valores serializados y las tablas de otros plugins no se importan ni exportan. Los valores de atributos globales deben existir en WooCommerce antes de asignarlos.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Productos virtuales y descargables', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'Usa virtual=yes, downloadable=yes, download_1_name y download_1_url. Los archivos han de estar accesibles por HTTP(S); el plugin no los copia ni los descarga. La protección de acceso a los archivos depende de la configuración de descargas de WooCommerce.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<p>' . esc_html__( 'Las altas se crean como borradores salvo que el CSV indique publish y tengas permiso. Una importación repetida se identifica por SKU o slug. No son obligatorias las categorías ni las imágenes.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '</section>';
	}

	public static function technical() {
		echo '<section class="tciel-panel"><h2>' . esc_html__( 'Manual técnico', 'taxocommerce-import-export-lite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Versión Lite independiente: WordPress 6.7+, PHP 7.4+, WooCommerce activo. Sin Core, bases de datos propias, taxonomías SEO ni proveedores. No lee ni modifica datos del Import-Export anterior.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Arquitectura y seguridad', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'Usa las API estándar wp_insert_post, wp_insert_term y los objetos WC_Product. Permisos y nonces en cada acción. CSV temporal fuera de Media, token privado por usuario con expiración de 1 hora, lectura por lotes de 25 registros y límites de 30 MB. Las columnas solo pueden mapearse a campos permitidos.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Identidad y taxonomías', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'Productos: busca primero SKU, después slug, y solo permite ID si no hay SKU ni slug. Páginas/entradas: slug primero. Categorías: slug/nombre. ID, categorías, etiquetas e imagen destacada no se mapean automáticamente; las relaciones taxonómicas solo se asignan expresamente a términos ya existentes.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Campos adaptados a cada instalación', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'Los campos personalizados se descubren mediante el registro de metadatos y las tablas nativas postmeta y termmeta. Los atributos globales se leen de WooCommerce y los locales se detectan en productos existentes. Las columnas no asignadas no modifican datos. Antes de importar, la vista previa recorre todas las filas en lotes de 25; solo la confirmación posterior permite guardarlas.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Límites de la primera edición', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'Incluye los contenidos nativos y campos seleccionados. Permite exportar productos simples, variables, agrupados y externos; no migra variaciones hijo, pedidos, clientes, campos privados/serializados ni medios binarios. Estos flujos requieren herramientas específicas de WooCommerce y no se simula su compatibilidad.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '<h3>' . esc_html__( 'Pruebas y compatibilidad', 'taxocommerce-import-export-lite' ) . '</h3><p>' . esc_html__( 'En la pestaña Chequeo se valida el arranque y las API disponibles; usa Plugin Check antes de distribuir. La exportación produce CSV estándar y admite asignación explícita de columnas para importar datos procedentes de otras tiendas.', 'taxocommerce-import-export-lite' ) . '</p>';
		echo '</section>';
	}
}
