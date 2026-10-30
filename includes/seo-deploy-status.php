<?php
/**
 * Production deployment helpers for TaxoCommerce sibling plugins.
 *
 * GitHub stages one compact ZIP per installed module. WordPress applies the ZIP
 * to its real WP_PLUGIN_DIR and overwrites only files whose SHA-256 differs.
 * Activation state is never changed by this deployment layer.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return physically installed TaxoCommerce sibling plugins.
 *
 * Discovery is based on the canonical plugin layout:
 * taxocommerce-slug/taxocommerce-slug.php. This avoids maintaining a second
 * hard-coded family list that can drift from the repository and Core catalog.
 *
 * @return array
 */
function seo_taxocommerce_known_deploy_modules() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugins = get_plugins();
	$known   = array();

	foreach ( array_keys( $plugins ) as $plugin_file ) {
		$plugin_file = wp_normalize_path( (string) $plugin_file );
		if ( ! preg_match( '#^(taxocommerce-[a-z0-9-]+)/\\1\\.php$#', $plugin_file, $matches ) ) {
			continue;
		}

		$known[ $matches[1] ] = $plugin_file;
	}

	ksort( $known, SORT_STRING );
	return $known;
}
/**
 * Return known TaxoCommerce sibling plugins physically installed in WordPress.
 *
 * Active and inactive plugins are both deployable. Updating files must never
 * alter the activation state selected by the administrator.
 *
 * @return array
 */
function seo_taxocommerce_installed_deploy_modules() {
	return array_keys( seo_taxocommerce_known_deploy_modules() );
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
			'modules' => seo_taxocommerce_installed_deploy_modules(),
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

	// Reject unsafe or repeated ZIP entries before touching any installed file.
	$names = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = (string) $zip->getNameIndex( $i );
		if ( '/' === substr( $name, -1 ) ) {
			continue;
		}
		if ( ! seo_taxocommerce_deploy_safe_relative_path( $name ) || isset( $names[ $name ] ) ) {
			$zip->close();
			return new WP_Error( 'taxocommerce_deploy_zip_path', 'El ZIP contiene rutas no válidas o duplicadas.' );
		}
		$names[ $name ] = true;
	}

	$changed   = array();
	$unchanged = 0;
	$manifest  = array();

	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = (string) $zip->getNameIndex( $i );
		if ( '/' === substr( $name, -1 ) ) {
			continue;
		}

		$content = $zip->getFromIndex( $i );
		if ( false === $content ) {
			$zip->close();
			return new WP_Error( 'taxocommerce_deploy_zip_read', 'No se pudo leer un archivo del paquete.' );
		}

		$expected_hash = hash( 'sha256', $content );
		$dest = rtrim( wp_normalize_path( $target_dir ), '/' ) . '/' . $name;
		if ( is_file( $dest ) && hash_file( 'sha256', $dest ) === $expected_hash ) {
			$unchanged++;
		} else {
			$parent = dirname( $dest );
			if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
				$zip->close();
				return new WP_Error( 'taxocommerce_deploy_mkdir', 'No se pudo crear un subdirectorio del plugin.' );
			}

			if ( false === file_put_contents( $dest, $content, LOCK_EX ) ) {
				$zip->close();
				return new WP_Error( 'taxocommerce_deploy_write', 'No se pudo actualizar un archivo del plugin.' );
			}

			if ( function_exists( 'opcache_invalidate' ) && preg_match( '/\\.php$/i', $dest ) ) {
				@opcache_invalidate( $dest, true );
			}
			$changed[] = $name;
		}

		// WordPress is the authority for the actual plugin directory, not the FTPS account root.
		clearstatcache( true, $dest );
		if ( ! is_file( $dest ) || hash_file( 'sha256', $dest ) !== $expected_hash ) {
			$zip->close();
			return new WP_Error( 'taxocommerce_deploy_integrity', 'El archivo desplegado no coincide con el ZIP: ' . $name );
		}
		$manifest[ $name ] = $expected_hash;
	}

	$zip->close();
	ksort( $manifest, SORT_STRING );
	$manifest_json = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $manifest_json ) ) {
		return new WP_Error( 'taxocommerce_deploy_integrity', 'No se pudo verificar el manifiesto desplegado.' );
	}

	return array(
		'changed'         => $changed,
		'unchanged'       => $unchanged,
		'verified_files'  => count( $manifest ),
		'manifest_sha256' => hash( 'sha256', $manifest_json ),
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
 * Only installed modules whose main file already exists in WP_PLUGIN_DIR are
 * eligible. Active and inactive modules are treated identically. Each module
 * is applied as a content delta without changing its activation state.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function seo_taxocommerce_deploy_sync_rest( $request ) {
	$run_id  = seo_taxocommerce_deploy_run_id( $request->get_param( 'run_id' ) );
	$handoff = seo_taxocommerce_deploy_handoff_root() . '/' . $run_id;
	$known     = seo_taxocommerce_known_deploy_modules();
	$installed = seo_taxocommerce_installed_deploy_modules();
	$targets   = array();

	foreach ( $installed as $slug ) {
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
			return new WP_Error( 'taxocommerce_deploy_target_missing', 'El plugin instalado ' . $slug . ' no existe físicamente en WP_PLUGIN_DIR.', array( 'status' => 500 ) );
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
			'verified_files'  => (int) $result['verified_files'],
			'manifest_sha256' => $result['manifest_sha256'],
		);
	}

	seo_taxocommerce_deploy_remove_tree( $handoff );

	return rest_ensure_response(
		array(
			'updated' => $updated,
		)
	);
}
