<?php
/** Read-only installation, functionality and code hygiene checks. */
namespace TaxoCommerce\ImportExportPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AutoCheck {
	public static function register() {
		add_action( 'admin_post_tciep_check', array( __CLASS__, 'run' ) );
		add_action( 'admin_post_tciep_check_json', array( __CLASS__, 'download' ) );
	}

	public static function report() {
		$rows = array();
		$rows[] = array( 'id' => 'wordpress', 'ok' => function_exists( 'wp_insert_post' ), 'description' => __( 'API de contenidos de WordPress', 'taxocommerce-import-export-pro' ) );
		$rows[] = array( 'id' => 'woocommerce', 'ok' => function_exists( 'wc_get_products' ) && class_exists( '\\WC_Product_Simple' ), 'description' => __( 'API estándar de WooCommerce', 'taxocommerce-import-export-pro' ) );
		$rows[] = array( 'id' => 'capability', 'ok' => current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ), 'description' => __( 'Permisos administrativos', 'taxocommerce-import-export-pro' ) );
		$rows[] = array( 'id' => 'native_fields', 'ok' => isset( CSV::fields( 'product' )['download_1_url'] ) && isset( CSV::fields( 'page' )['content'] ), 'description' => __( 'Mapeo nativo de páginas y productos descargables', 'taxocommerce-import-export-pro' ) );
		$rows[] = array( 'id' => 'dynamic_fields', 'ok' => is_array( NativeFields::fields( 'product' ) ) && function_exists( 'get_registered_meta_keys' ), 'description' => __( 'Campos personalizados nativos y atributos disponibles', 'taxocommerce-import-export-pro' ) );
		$rows[] = array( 'id' => 'batch_engine', 'ok' => class_exists( __NAMESPACE__ . '\\Batch' ) && class_exists( __NAMESPACE__ . '\\Load' ) && 5 === count( Load::profiles() ), 'description' => __( 'Colas por tipo y cinco perfiles de carga', 'taxocommerce-import-export-pro' ) );
		$rows[] = array( 'id' => 'basic_csv', 'ok' => function_exists( 'fgetcsv' ) && function_exists( 'fputcsv' ), 'description' => __( 'Soporte nativo CSV', 'taxocommerce-import-export-pro' ) );
		$files = array( 'taxocommerce-import-export-pro.php', 'includes/class-csv.php', 'includes/class-native-fields.php', 'includes/class-admin.php', 'includes/class-batch.php', 'includes/class-load.php', 'includes/class-taxocommerce-autotest-code.php', 'includes/class-taxocommerce-code-review-rules.php', 'includes/class-autocheck.php', 'includes/class-manual.php', 'assets/admin.js', 'assets/admin.css' );
		foreach ( $files as $file ) {
			$rows[] = array( 'id' => 'code_' . sanitize_key( str_replace( '/', '_', $file ) ), 'ok' => is_readable( dirname( __DIR__ ) . '/' . $file ), 'description' => sprintf( /* translators: %s: a local component path */ __( 'Componente legible: %s', 'taxocommerce-import-export-pro' ), $file ) );
		}
		$metadata = get_file_data( dirname( __DIR__ ) . '/taxocommerce-import-export-pro.php', array( 'requires' => 'Requires Plugins' ) );
		$rows[] = array( 'id' => 'no_core_required', 'ok' => 'woocommerce' === trim( $metadata['requires'] ) && class_exists( __NAMESPACE__ . '\\Admin' ) && class_exists( __NAMESPACE__ . '\\CSV' ), 'description' => __( 'WooCommerce como única dependencia de plugin; integración opcional de Core', 'taxocommerce-import-export-pro' ) );
		$passes = count( array_filter( $rows, static function ( $row ) { return $row['ok']; } ) );
		return array( 'plugin' => SLUG, 'version' => VERSION, 'timestamp' => gmdate( 'c' ), 'checks' => $rows, 'passed' => $passes, 'failed' => count( $rows ) - $passes );
	}

	public static function run() {
		Admin::allowed();
		check_admin_referer( 'tciep_check' );
		update_option( 'tciep_last_check', self::report(), false );
		wp_safe_redirect( Admin::url( array( 'tab' => 'check' ) ) );
		exit;
	}

	public static function download() {
		Admin::allowed();
		check_admin_referer( 'tciep_check_json' );
		$report = get_option( 'tciep_last_check' );
		if ( ! is_array( $report ) ) wp_die( esc_html__( 'Ejecuta el chequeo primero.', 'taxocommerce-import-export-pro' ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="taxocommerce-import-export-pro-check.json"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw JSON output, not HTML.
		exit;
	}

	public static function render() {
		$report = get_option( 'tciep_last_check' );
		echo '<section class="tciep-panel"><h2>' . esc_html__( 'Chequeo de funcionalidades y componentes', 'taxocommerce-import-export-pro' ) . '</h2><p>' . esc_html__( 'Comprobaciones de solo lectura: WordPress, WooCommerce, CSV, permisos y archivos. No importa ni modifica registros. Para un análisis exhaustivo de estándares utiliza Plugin Check.', 'taxocommerce-import-export-pro' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tciep_check">';
		wp_nonce_field( 'tciep_check' );
		submit_button( __( 'Ejecutar chequeo', 'taxocommerce-import-export-pro' ), 'primary', 'submit', false );
		echo '</form>';
		if ( is_array( $report ) ) {
			echo '<p>' . esc_html( (string) $report['timestamp'] ) . ' · ' . esc_html__( 'Superado', 'taxocommerce-import-export-pro' ) . ' ' . (int) $report['passed'] . ' / ' . esc_html__( 'Fallido', 'taxocommerce-import-export-pro' ) . ' ' . (int) $report['failed'] . '</p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Prueba', 'taxocommerce-import-export-pro' ) . '</th><th>' . esc_html__( 'Resultado', 'taxocommerce-import-export-pro' ) . '</th></tr></thead><tbody>';
			foreach ( $report['checks'] as $row ) {
				echo '<tr><td>' . esc_html( $row['description'] ) . '</td><td class="' . ( $row['ok'] ? 'tciep-check-pass' : 'tciep-check-fail' ) . '">' . ( $row['ok'] ? esc_html__( 'Superado', 'taxocommerce-import-export-pro' ) : esc_html__( 'Fallido', 'taxocommerce-import-export-pro' ) ) . '</td></tr>';
			}
			echo '</tbody></table><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="tciep_check_json">';
			wp_nonce_field( 'tciep_check_json' );
			submit_button( __( 'Descargar informe JSON', 'taxocommerce-import-export-pro' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</section>';
	}
}
