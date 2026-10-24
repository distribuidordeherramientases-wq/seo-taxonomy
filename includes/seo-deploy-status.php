<?php
/**
 * Production deployment helpers for TaxoCommerce sibling plugins.
 *
 * The public GET endpoint exposes only the slugs of known active modules.
 * The POST sync endpoint requires an ephemeral token marker uploaded by the
 * production workflow and copies only already-installed, active modules into
 * WordPress' real WP_PLUGIN_DIR.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Known deployable TaxoCommerce sibling plugins.
 *
 * @return array
 */
function seo_taxocommerce_known_deploy_modules() {
	return array(
		'taxocommerce-core'          => 'taxocommerce-core/taxocommerce-core.php',
		'taxocommerce-images'        => 'taxocommerce-images/taxocommerce-images.php',
		'taxocommerce-import-export' => 'taxocommerce-import-export/taxocommerce-import-export.php',
		'taxocommerce-system-check'  => 'taxocommerce-system-check/taxocommerce-system-check.php',
		'taxocommerce-templates'     => 'taxocommerce-templates/taxocommerce-templates.php',
		'taxocommerce-dependiente'   => 'taxocommerce-dependiente/taxocommerce-dependiente.php',
		'taxocommerce-faqs'          => 'taxocommerce-faqs/taxocommerce-faqs.php',
		'taxocommerce-vocabulary'    => 'taxocommerce-vocabulary/taxocommerce-vocabulary.php',
		'taxocommerce-google-bing'   => 'taxocommerce-google-bing/taxocommerce-google-bing.php',
		'taxocommerce-auditor'       => 'taxocommerce-auditor/taxocommerce-auditor.php',
		'taxocommerce-redirects'     => 'taxocommerce-redirects/taxocommerce-redirects.php',
	);
}

/**
 * Return known TaxoCommerce sibling plugins that WordPress reports as active.
 *
 * @return array
 */
function seo_taxocommerce_active_deploy_modules() {
	$known  = seo_taxocommerce_known_deploy_modules();
	$active = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() ) {
		$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		$active  = array_merge( $active, $network );
	}

	$active = array_fill_keys( array_map( 'strval', $active ), true );
	$result = array();

	foreach ( $known as $slug => $plugin_file ) {
		if ( isset( $active[ $plugin_file ] ) ) {
			$result[] = $slug;
		}
	}

	return $result;
}

/**
 * Root used only as a temporary hand-off area inside the legacy plugin.
 *
 * @return string
 */
function seo_taxocommerce_deploy_handoff_root() {
	return dirname( __DIR__ ) . '/.taxocommerce-deploy';
}

/**
 * Validate a numeric GitHub Actions run id.
 *
 * @param mixed $value Candidate run id.
 * @return string
 */
function seo_taxocommerce_deploy_run_id( $value ) {
	$value = (string) $value;
	return preg_match( '/^[0-9]+$/', $value ) ? $value : '';
}

/**
 * Register deployment REST endpoints.
 *
 * @return void
 */
function seo_taxocommerce_register_deploy_status_route() {
	register_rest_route(
		'taxocommerce/v1',
		'/deploy-active-modules',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'seo_taxocommerce_deploy_status_rest',
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'seo_taxocommerce_deploy_sync_rest',
				'permission_callback' => 'seo_taxocommerce_deploy_sync_permission',
			),
		)
	);
}
add_action( 'rest_api_init', 'seo_taxocommerce_register_deploy_status_route' );

/**
 * Read-only status callback.
 *
 * @return WP_REST_Response
 */
function seo_taxocommerce_deploy_status_rest() {
	return rest_ensure_response(
		array(
			'modules' => seo_taxocommerce_active_deploy_modules(),
		)
	);
}

/**
 * Validate the ephemeral deployment token uploaded by GitHub Actions.
 *
 * @param WP_REST_Request $request REST request.
 * @return true|WP_Error
 */
function seo_taxocommerce_deploy_sync_permission( $request ) {
	$run_id = seo_taxocommerce_deploy_run_id( $request->get_param( 'run_id' ) );
	$token  = (string) $request->get_header( 'x-taxocommerce-deploy-token' );

	if ( '' === $run_id || '' === $token ) {
		return new WP_Error( 'taxocommerce_deploy_forbidden', 'Credenciales de despliegue incompletas.', array( 'status' => 403 ) );
	}

	$marker = seo_taxocommerce_deploy_handoff_root() . '/' . $run_id . '/.token-' . hash( 'sha256', $token );
	if ( ! is_file( $marker ) ) {
		return new WP_Error( 'taxocommerce_deploy_forbidden', 'Token de despliegue no válido.', array( 'status' => 403 ) );
	}

	return true;
}

/**
 * Copy one directory tree, overwriting existing files but never creating a new
 * plugin root. Extra legacy files are intentionally not deleted.
 *
 * @param string $source Source directory.
 * @param string $target Existing target directory.
 * @return true|WP_Error
 */
function seo_taxocommerce_deploy_copy_tree( $source, $target ) {
	if ( ! is_dir( $source ) || ! is_dir( $target ) ) {
		return new WP_Error( 'taxocommerce_deploy_path', 'Ruta de despliegue no válida.' );
	}

	$source = rtrim( wp_normalize_path( $source ), '/' );
	$target = rtrim( wp_normalize_path( $target ), '/' );

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $iterator as $item ) {
		$path     = wp_normalize_path( $item->getPathname() );
		$relative = ltrim( substr( $path, strlen( $source ) ), '/' );
		$dest     = $target . '/' . $relative;

		if ( $item->isDir() ) {
			if ( ! is_dir( $dest ) && ! wp_mkdir_p( $dest ) ) {
				return new WP_Error( 'taxocommerce_deploy_mkdir', 'No se pudo crear un subdirectorio del plugin.' );
			}
			continue;
		}

		$parent = dirname( $dest );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			return new WP_Error( 'taxocommerce_deploy_mkdir', 'No se pudo crear un subdirectorio del plugin.' );
		}

		if ( ! copy( $path, $dest ) ) {
			return new WP_Error( 'taxocommerce_deploy_copy', 'No se pudo copiar un archivo del plugin.' );
		}

		if ( function_exists( 'opcache_invalidate' ) && preg_match( '/\.php$/i', $dest ) ) {
			@opcache_invalidate( $dest, true );
		}
	}

	return true;
}

/**
 * Remove a temporary directory tree.
 *
 * @param string $path Directory path.
 * @return void
 */
function seo_taxocommerce_deploy_remove_tree( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			@rmdir( $item->getPathname() );
		} else {
			@unlink( $item->getPathname() );
		}
	}

	@rmdir( $path );
}

/**
 * Secure sync callback. Only active modules whose target main file already
 * exists in WP_PLUGIN_DIR are eligible.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function seo_taxocommerce_deploy_sync_rest( $request ) {
	$run_id  = seo_taxocommerce_deploy_run_id( $request->get_param( 'run_id' ) );
	$handoff = seo_taxocommerce_deploy_handoff_root() . '/' . $run_id;
	$known   = seo_taxocommerce_known_deploy_modules();
	$active  = seo_taxocommerce_active_deploy_modules();
	$targets = array();

	foreach ( $active as $slug ) {
		if ( ! isset( $known[ $slug ] ) ) {
			continue;
		}

		$plugin_file = $known[ $slug ];
		$source_main = $handoff . '/' . $plugin_file;
		$target_main = WP_PLUGIN_DIR . '/' . $plugin_file;
		$target_dir  = dirname( $target_main );

		if ( ! is_file( $source_main ) ) {
			seo_taxocommerce_deploy_remove_tree( $handoff );
			return new WP_Error( 'taxocommerce_deploy_source_missing', 'Falta el paquete fuente de ' . $slug . '.', array( 'status' => 500 ) );
		}

		if ( ! is_file( $target_main ) || ! is_dir( $target_dir ) ) {
			seo_taxocommerce_deploy_remove_tree( $handoff );
			return new WP_Error( 'taxocommerce_deploy_target_missing', 'El plugin activo ' . $slug . ' no existe físicamente en WP_PLUGIN_DIR.', array( 'status' => 500 ) );
		}

		$targets[ $slug ] = array(
			'plugin_file' => $plugin_file,
			'source_dir'  => $handoff . '/' . $slug,
			'target_dir'  => $target_dir,
			'target_main' => $target_main,
		);
	}

	$updated = array();

	foreach ( $targets as $slug => $paths ) {
		$copied = seo_taxocommerce_deploy_copy_tree( $paths['source_dir'], $paths['target_dir'] );
		if ( is_wp_error( $copied ) ) {
			seo_taxocommerce_deploy_remove_tree( $handoff );
			return $copied;
		}

		clearstatcache( true, $paths['target_main'] );
		$header = get_file_data( $paths['target_main'], array( 'Version' => 'Version' ), 'plugin' );

		$updated[] = array(
			'slug'    => $slug,
			'version' => isset( $header['Version'] ) ? (string) $header['Version'] : '',
		);
	}

	seo_taxocommerce_deploy_remove_tree( $handoff );

	return rest_ensure_response(
		array(
			'updated' => $updated,
		)
	);
}
