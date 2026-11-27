<?php
/** Independent user and technical manuals. */
namespace TaxoCommerce\ImportExportPro;

defined( 'ABSPATH' ) || exit;

final class Manual {
	public static function user() {
		echo '<section class="tciep-panel"><h2>' . esc_html__( 'Manual de usuario', 'taxocommerce-import-export-pro' ) . '</h2><ol>';
		foreach ( array(
			__( 'En Importar por lotes elige el bloque de páginas, productos, categorías, entradas o etiquetas. No tienes que seleccionar un tipo ni asignar columnas.', 'taxocommerce-import-export-pro' ),
			__( 'Selecciona uno o varios CSV UTF-8 en el mismo selector. Comprueba el número y los nombres seleccionados y pulsa Cargar lote y comprobar CSV. Hasta 20 archivos por carga, 30 MB y 25.000 registros por archivo, 100 MB en conjunto, sujetos a los límites del servidor. Usa nombres 001, 002… para ordenar el lote.', 'taxocommerce-import-export-pro' ),
			__( 'Selecciona la velocidad de 1 a 5. El prechequeo lee todos los archivos sin guardar. Consulta los errores por fila y las columnas omitidas. Si hay errores, archiva el lote, corrige el CSV y cárgalo de nuevo.', 'taxocommerce-import-export-pro' ),
			__( 'Cuando todos los archivos estén comprobados, pulsa Confirmar e importar. Los registros se identifican por SKU o slug; las altas son borradores salvo indicación explícita y permisos de publicación.', 'taxocommerce-import-export-pro' ),
			__( 'Pausa, cambia la velocidad o reanuda desde el punto de control. Si hay una interrupción, revisa los registros guardados antes de reanudar. Los errores de escritura detienen el lote: los cambios ya realizados no se deshacen automáticamente.', 'taxocommerce-import-export-pro' ),
			__( 'En Exportar CSV genera un archivo por tipo mediante bloques. Se incluyen automáticamente todos los campos compatibles del bloque; descarga el CSV cuando termine. Archivar y limpiar conserva los últimos cinco informes por bloque y elimina únicamente sus archivos temporales.', 'taxocommerce-import-export-pro' ),
		) as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ol><h3>' . esc_html__( 'Campos y relaciones', 'taxocommerce-import-export-pro' ) . '</h3><p>' . esc_html__( 'Usa el CSV exportado como modelo. Se admiten cabeceras nativas y alias reconocidos, metadatos públicos escalares (meta:clave), atributos globales y locales compatibles (attr:pa_color), productos virtuales y descargables. Los IDs externos y las celdas vacías no actualizan datos. Las categorías, etiquetas, padres e imágenes referenciadas deben existir previamente; importa los términos padre antes de sus hijos.', 'taxocommerce-import-export-pro' ) . '</p>';
		echo '<h3>' . esc_html__( 'Velocidad y continuidad', 'taxocommerce-import-export-pro' ) . '</h3><p>' . esc_html__( 'El regulador mide CPU del proceso PHP, memoria y duración, no la carga global del servidor. Los cinco perfiles cambian tamaño, duración objetivo y espera entre bloques. La interfaz asiste el procesamiento; WP-Cron puede continuar cuando está cerrada si el alojamiento lo ejecuta y mantiene los temporales privados. No se garantiza ejecución desatendida en un servidor con cron deshabilitado.', 'taxocommerce-import-export-pro' ) . '</p></section>';
	}

	public static function technical() {
		echo '<section class="tciep-panel"><h2>' . esc_html__( 'Manual técnico', 'taxocommerce-import-export-pro' ) . '</h2>';
		foreach ( array(
			__( 'Arquitectura: TaxoCommerce Import-Export Pro 0.1.2 es un plugin autónomo con el motor CSV de Lite incorporado en su propio namespace. WordPress 6.7+, PHP 7.4+ y WooCommerce; no requiere Lite, Core ni el Import-Export original.', 'taxocommerce-import-export-pro' ),
			__( 'Admin registra una ruta propia y formularios por tipo. Si Core está activo, Pro registra una ficha y un submenú mediante sus hooks opcionales; sin Core conserva su menú propio. Batch vincula el trabajo al usuario, tipo, dirección y token; guarda un estado independiente no autoload en wp_options. Los identificadores y las cabeceras se validan en servidor. Las acciones de escritura y descarga exigen permisos y nonces. El cron vuelve a verificar las capacidades actuales del propietario.', 'taxocommerce-import-export-pro' ),
			__( 'Los CSV se guardan en un directorio privado fuera del árbol de WordPress y del document root, en un directorio temporal privado del sistema, WordPress o PHP. La recepción verifica el origen HTTP, copia local, tamaño y permisos; no usa Media. Si no está disponible se rechaza el trabajo. Los archivos no se publican en Media. Se requiere un almacenamiento compartido entre procesos PHP si hay varios servidores web.', 'taxocommerce-import-export-pro' ),
			__( 'El bloqueo de archivo del sitio serializa trabajadores y cambios de estado. Cada bloque guarda un marcador en curso y un cursor de bytes. Un fallo no se reenvía automáticamente: queda pausado para inspección y confirmación de reanudación. El importador utiliza altas/actualizaciones idempotentes por identidad; no promete una transacción global ni deshacer efectos de otros plugins.', 'taxocommerce-import-export-pro' ),
			__( 'La exportación guarda un offset de salida confirmado y trunca bytes no confirmados antes de reanudar. Consulta identificadores en páginas fijas y escribe CSV por ventanas adaptativas, sin cargar todo el catálogo. Hasta 100 MB por exportación, 1024 columnas y 128 KiB de cabecera. No es una instantánea transaccional: evita editar el catálogo durante la generación.', 'taxocommerce-import-export-pro' ),
			__( 'El regulador de carga utiliza tiempo real, consumo de memoria y CPU local cuando getrusage está disponible. WP-Cron y el navegador comparten la misma cola y el mismo bloqueo. Si se desactiva el plugin se cancelan sus eventos cron; no se borran contenidos ni trabajos.', 'taxocommerce-import-export-pro' ),
			__( 'El chequeo funcional es de solo lectura. Chequeo de código analiza únicamente los PHP del propio plugin, con inventario, hallazgos, gravedad, pausa y JSON; no sustituye Plugin Check oficial ni una auditoría de seguridad. El informe no certifica WordPress.org.', 'taxocommerce-import-export-pro' ),
			__( 'Límites de esta edición: no migra variaciones hijo, pedidos, clientes, archivos binarios Media ni formatos del importador legado. Las URLs de descarga no se copian. Los metadatos protegidos o serializados no se exportan ni importan. El producto original conserva sus propios procesos avanzados y sus datos.', 'taxocommerce-import-export-pro' ),
		) as $item ) {
			echo '<p>' . esc_html( $item ) . '</p>';
		}
		echo '</section>';
	}
}
