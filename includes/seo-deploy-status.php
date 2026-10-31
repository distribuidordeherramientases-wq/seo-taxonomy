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
 * Temporary hand-off is outside all installed plugins and not part of any ZIP.
 *
 * @return string
 */
function seo_taxocommerce_deploy_handoff_root() {
	return WP_CONTENT_DIR . '/.taxocommerce-deploy';
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
 * Remove only obsolete Images developer tests from the installed WP_PLUGIN_DIR.
 *
 * These two scripts were deployed by an older FTP process. Keep the originals
 * in GitHub for CI; never distribute them in an Images release ZIP. Backup is
 * written to WordPress's private temporary directory before deletion.
 *
 * @param string $target_dir Actual installed Images directory.
 * @param string $zip_file   Validated Images release archive.
 * @return array|WP_Error Removed paths and backup name, or a safe failure.
 */
function seo_taxocommerce_deploy_cleanup_images_tests( $target_dir, $zip_file ) {
	$expected_dir = realpath( WP_PLUGIN_DIR . '/taxocommerce-images' );
	$actual_dir   = realpath( $target_dir );
	if ( false === $expected_dir || false === $actual_dir || $expected_dir !== $actual_dir
		|| is_link( $target_dir ) ) {
		return new WP_Error( 'taxocommerce_images_cleanup_path', 'La ruta del plugin Images no coincide con WP_PLUGIN_DIR.' );
	}

	$tests_dir = $actual_dir . '/tests';
	if ( ! is_dir( $tests_dir ) ) {
		return array( 'removed' => array(), 'backup_name' => '' );
	}
	if ( is_link( $tests_dir ) ) {
		return new WP_Error( 'taxocommerce_images_cleanup_symlink', 'No se puede limpiar un directorio tests enlazado.' );
	}

	$allowed = array(
		'early-i18n-smoke.php' => 'Early translation regression in Images registration',
		'functional-contract.php' => 'IMAGES_CONTRACT_TEST',
	);
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_file ) ) {
		return new WP_Error( 'taxocommerce_images_cleanup_zip', 'No se pudo verificar el ZIP de Images.' );
	}

	$files = array();
	foreach ( $allowed as $name => $signature ) {
		$relative = 'tests/' . $name;
		if ( false !== $zip->locateName( $relative ) ) {
			$zip->close();
			return new WP_Error( 'taxocommerce_images_cleanup_package', 'Los tests no deben formar parte del ZIP de Images.' );
		}
		$path = $tests_dir . '/' . $name;
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			continue;
		}
		if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > 262144 ) {
			$zip->close();
			return new WP_Error( 'taxocommerce_images_cleanup_unexpected', 'Archivo de pruebas inesperado: ' . $name );
		}
		$content = file_get_contents( $path );
		if ( ! is_string( $content ) || false === strpos( $content, $signature ) ) {
			$zip->close();
			return new WP_Error( 'taxocommerce_images_cleanup_unrecognized', 'No se reconoce el contenido del archivo: ' . $name );
		}
		$files[ $relative ] = $content;
	}
	$zip->close();

	if ( ! $files ) {
		return array( 'removed' => array(), 'backup_name' => '' );
	}

	// Do not keep PHP source backups anywhere inside the public WordPress root.
	$temp_dir = realpath( get_temp_dir() );
	$web_root = realpath( ABSPATH );
	if ( false === $temp_dir || ! is_writable( $temp_dir )
		|| ( false !== $web_root && 0 === strpos( wp_normalize_path( $temp_dir ) . '/', rtrim( wp_normalize_path( $web_root ), '/' ) . '/' ) ) ) {
		return new WP_Error( 'taxocommerce_images_cleanup_backup', 'No hay un directorio temporal privado para la copia de seguridad.' );
	}
	$backup_name = 'taxocommerce-images-developer-tests-' . wp_generate_uuid4() . '.zip';
	$backup_path = $temp_dir . '/' . $backup_name;
	$backup = new ZipArchive();
	if ( true !== $backup->open( $backup_path, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
		return new WP_Error( 'taxocommerce_images_cleanup_backup', 'No se pudo crear la copia de seguridad.' );
	}
	foreach ( $files as $name => $content ) {
		if ( ! $backup->addFromString( $name, $content ) ) {
			$backup->close();
			return new WP_Error( 'taxocommerce_images_cleanup_backup', 'No se pudo guardar un archivo en la copia.' );
		}
	}
	if ( ! $backup->close() || true !== $backup->open( $backup_path ) ) {
		return new WP_Error( 'taxocommerce_images_cleanup_backup', 'No se pudo verificar la copia de seguridad.' );
	}
	foreach ( $files as $name => $content ) {
		if ( $backup->getFromName( $name ) !== $content ) {
			$backup->close();
			return new WP_Error( 'taxocommerce_images_cleanup_backup', 'La copia de seguridad no coincide con los archivos originales.' );
		}
	}
	$backup->close();

	$removed = array();
	foreach ( array_keys( $files ) as $relative ) {
		$path = $actual_dir . '/' . $relative;
		wp_delete_file( $path );
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			return new WP_Error( 'taxocommerce_images_cleanup_delete', 'No se pudo retirar ' . $relative . '; copia disponible: ' . $backup_name );
		}
		$removed[] = $relative;
	}

	return array( 'removed' => $removed, 'backup_name' => $backup_name );
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

		$cleanup = array( 'removed' => array(), 'backup_name' => '' );
		if ( 'taxocommerce-images' === $slug ) {
			$cleanup = seo_taxocommerce_deploy_cleanup_images_tests( $paths['target_dir'], $paths['zip_file'] );
			if ( is_wp_error( $cleanup ) ) {
				seo_taxocommerce_deploy_remove_tree( $handoff );
				return $cleanup;
			}
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
			'removed_legacy_test_files' => $cleanup['removed'],
			'cleanup_backup_name' => $cleanup['backup_name'],
		);
	}

	seo_taxocommerce_deploy_remove_tree( $handoff );

	return rest_ensure_response(
		array(
			'updated' => $updated,
		)
	);
}
