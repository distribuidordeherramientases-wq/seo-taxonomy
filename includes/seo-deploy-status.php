<?php
/**
 * Production deployment helpers for TaxoCommerce sibling plugins.
 *
 * GitHub stages one compact ZIP per active module. WordPress applies the ZIP
 * to its real WP_PLUGIN_DIR and overwrites only files whose SHA-256 differs.
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
 * The token is sent as a POST field because some hosting/WAF configurations
 * strip custom HTTP headers before WordPress receives the request.
 *
 * @param WP_REST_Request $request REST request.
 * @return true|WP_Error
 */
function seo_taxocommerce_deploy_sync_permission( $request ) {
	$run_id = seo_taxocommerce_deploy_run_id( $request->get_param( 'run_id' ) );
	$token  = (string) $request->get_param( 'deploy_token' );

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
 * Validate a ZIP relative path.
 *
 * @param string $relative ZIP entry.
 * @return bool
 */
function seo_taxocommerce_deploy_safe_relative_path( $relative ) {
	$relative = str_replace( '\\', '/', (string) $relative );

	if ( '' === $relative || '/' === substr( $relative, -1 ) ) {
		return false;
	}

	if ( '/' === substr( $relative, 0, 1 ) || preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
		return false;
	}

	return 1 === preg_match( '#^[A-Za-z0-9._/+-]+$#', $relative );
}

/**
 * Apply one ZIP package to an existing plugin directory.
 *
 * Only files with different SHA-256 content are written. No existing files are
 * deleted and the plugin root must already exist.
 *
 * @param string $zip_file   Package file.
 * @param string $target_dir Existing plugin directory.
 * @return array|WP_Error
 */
function seo_taxocommerce_deploy_apply_zip_delta( $zip_file, $target_dir ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'taxocommerce_deploy_zip', 'ZipArchive no está disponible en PRO.' );
	}

	if ( ! is_file( $zip_file ) || ! is_dir( $target_dir ) ) {
		return new WP_Error( 'taxocommerce_deploy_path', 'Paquete o destino de despliegue no válido.' );
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_file ) ) {
		return new WP_Error( 'taxocommerce_deploy_zip', 'No se pudo abrir el paquete ZIP.' );
	}

	$changed   = array();
	$unchanged = 0;

	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = (string) $zip->getNameIndex( $i );

		if ( ! seo_taxocommerce_deploy_safe_relative_path( $name ) ) {
			continue;
		}

		$content = $zip->getFromIndex( $i );
		if ( false === $content ) {
			$zip->close();
			return new WP_Error( 'taxocommerce_deploy_zip_read', 'No se pudo leer un archivo del paquete.' );
		}

		$dest = rtrim( wp_normalize_path( $target_dir ), '/' ) . '/' . $name;

		if ( is_file( $dest ) && hash_file( 'sha256', $dest ) === hash( 'sha256', $content ) ) {
			$unchanged++;
			continue;
		}

		$parent = dirname( $dest );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			$zip->close();
			return new WP_Error( 'taxocommerce_deploy_mkdir', 'No se pudo crear un subdirectorio del plugin.' );
		}

		if ( false === file_put_contents( $dest, $content, LOCK_EX ) ) {
			$zip->close();
			return new WP_Error( 'taxocommerce_deploy_write', 'No se pudo actualizar un archivo del plugin.' );
		}

		if ( function_exists( 'opcache_invalidate' ) && preg_match( '/\.php$/i', $dest ) ) {
			@opcache_invalidate( $dest, true );
		}

		$changed[] = $name;
	}

	$zip->close();

	return array(
		'changed'   => $changed,
		'unchanged' => $unchanged,
	);
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
 * Secure sync callback.
 *
 * Only active modules whose main file already exists in WP_PLUGIN_DIR are
 * eligible. Each module is applied as a content delta.
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
		$zip_file    = $handoff . '/' . $slug . '.zip';
		$target_main = WP_PLUGIN_DIR . '/' . $plugin_file;
		$target_dir  = dirname( $target_main );

		if ( ! is_file( $zip_file ) ) {
			seo_taxocommerce_deploy_remove_tree( $handoff );
			return new WP_Error( 'taxocommerce_deploy_source_missing', 'Falta el paquete fuente de ' . $slug . '.', array( 'status' => 500 ) );
		}

		if ( ! is_file( $target_main ) || ! is_dir( $target_dir ) ) {
			seo_taxocommerce_deploy_remove_tree( $handoff );
			return new WP_Error( 'taxocommerce_deploy_target_missing', 'El plugin activo ' . $slug . ' no existe físicamente en WP_PLUGIN_DIR.', array( 'status' => 500 ) );
		}

		$targets[ $slug ] = array(
			'zip_file'    => $zip_file,
			'target_dir'  => $target_dir,
			'target_main' => $target_main,
		);
	}

	$updated = array();

	foreach ( $targets as $slug => $paths ) {
		$result = seo_taxocommerce_deploy_apply_zip_delta( $paths['zip_file'], $paths['target_dir'] );
		if ( is_wp_error( $result ) ) {
			seo_taxocommerce_deploy_remove_tree( $handoff );
			return $result;
		}

		clearstatcache( true, $paths['target_main'] );
		$header = get_file_data( $paths['target_main'], array( 'Version' => 'Version' ), 'plugin' );

		$updated[] = array(
			'slug'      => $slug,
			'version'   => isset( $header['Version'] ) ? (string) $header['Version'] : '',
			'changed'   => $result['changed'],
			'unchanged' => (int) $result['unchanged'],
		);
	}

	seo_taxocommerce_deploy_remove_tree( $handoff );

	return rest_ensure_response(
		array(
			'updated' => $updated,
		)
	);
}
