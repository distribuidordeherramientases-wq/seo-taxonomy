<?php
/**
 * Private, resumable CSV jobs. One queue per administrator, direction and content type.
 * No dependency on Lite, Core, the original Import-Export or Action Scheduler.
 *
 * @package TaxoCommerceImportExportPro
 */
namespace TaxoCommerce\ImportExportPro;

defined( 'ABSPATH' ) || exit;

final class Batch {
	const HOOK = 'tciep_run_batch';
	const MAX_FILES = 20;
	const MAX_FILE_BYTES = 31457280;
	const MAX_TOTAL_BYTES = 104857600;
	const MAX_ROWS = 25000;
	const EXPORT_PAGE = 100;
	const MAX_COLUMNS = 1024;
	const MAX_HEADER_BYTES = 131072;

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'cron' ), 10, 4 );
	}

	public static function valid( $kind, $type ) {
		return in_array( $kind, array( 'import', 'export' ), true ) && isset( CSV::types()[ $type ] );
	}

	public static function allowed( $kind, $type ) {
		if ( ! self::valid( $kind, $type ) || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) ) {
			return false;
		}
		$capabilities = array(
			'page' => 'edit_pages', 'post' => 'edit_posts', 'product' => 'edit_products',
			'category' => 'manage_categories', 'post_tag' => 'manage_categories',
			'product_cat' => 'manage_product_terms', 'product_tag' => 'manage_product_terms',
		);
		if ( ! current_user_can( $capabilities[ $type ] ) ) {
			return false;
		}
		// The export includes drafts and private records, not just public content.
		if ( 'export' === $kind && in_array( $type, array( 'page', 'post', 'product' ), true ) ) {
			$suffix = array( 'page' => 'pages', 'post' => 'posts', 'product' => 'products' )[ $type ];
			return current_user_can( 'read_private_' . $suffix ) && current_user_can( 'edit_others_' . $suffix );
		}
		return true;
	}

	private static function error( $code, $message ) {
		return new \WP_Error( $code, $message );
	}

	private static function key( $owner, $kind, $type ) {
		return 'tciep_job_' . (int) $owner . '_' . $kind . '_' . $type;
	}

	public static function state( $owner, $kind, $type ) {
		if ( $owner < 1 || ! self::valid( $kind, $type ) ) {
			return false;
		}
		$state = get_option( self::key( $owner, $kind, $type ), false );
		return is_array( $state ) && (int) ( $state['owner'] ?? 0 ) === (int) $owner && ( $state['kind'] ?? '' ) === $kind && ( $state['type'] ?? '' ) === $type && self::valid_token( $state['token'] ?? '' ) ? $state : false;
	}

	private static function valid_token( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $token );
	}

	private static function save( $state ) {
		$state['updated'] = gmdate( 'c' );
		$key = self::key( $state['owner'], $state['kind'], $state['type'] );
		if ( ! update_option( $key, $state, false ) && get_option( $key ) !== $state ) {
			throw new \RuntimeException( 'Could not persist job checkpoint.' );
		}
		return $state;
	}

	/** Canonical non-public temporary directory, never a web-accessible Media path. */
	private static function private_temp_dir( $candidate ) {
		$temp = is_string( $candidate ) && '' !== $candidate ? realpath( $candidate ) : false;
		if ( ! $temp || ! is_dir( $temp ) || ! wp_is_writable( $temp ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Used only for canonical containment, never as a write path.
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ? wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : '';
		$normalized = trailingslashit( strtolower( wp_normalize_path( $temp ) ) );
		foreach ( array( ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH, $document_root ) as $public ) {
			$resolved = '' !== $public ? realpath( $public ) : false;
			if ( $resolved && 0 === strpos( $normalized, trailingslashit( strtolower( wp_normalize_path( $resolved ) ) ) ) ) {
				return false;
			}
		}
		return $temp;
	}

	/** Prefer an existing private site spool; never split jobs across two locks. */
	public static function root() {
		$suffix = 'tciep-' . hash_hmac( 'sha256', ABSPATH . '|' . get_current_blog_id(), wp_salt( 'auth' ) );
		$directories = array();
		foreach ( array( sys_get_temp_dir(), get_temp_dir(), ini_get( 'upload_tmp_dir' ) ) as $candidate ) {
			$temp = self::private_temp_dir( $candidate );
			if ( $temp ) {
				$directories[] = $temp . DIRECTORY_SEPARATOR . $suffix;
			}
		}
		$directories = array_values( array_unique( $directories ) );
		$existing = array_filter( $directories, 'file_exists' );
		if ( count( $existing ) > 1 ) {
			return false;
		}
		$root = $existing ? reset( $existing ) : ( $directories[0] ?? false );
		if ( ! $root || is_link( $root ) || ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Owner-only private worker spool, outside the public web root.
		if ( ! chmod( $root, 0700 ) || ! wp_is_writable( $root ) ) {
			return false;
		}
		return $root;
	}

	private static function directory( $token, $create = false ) {
		$root = self::root();
		if ( ! $root || ! self::valid_token( $token ) ) {
			return false;
		}
		$dir = $root . DIRECTORY_SEPARATOR . $token;
		if ( is_link( $dir ) || ( $create && ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) || ! is_dir( $dir ) ) {
			return false;
		}
		return $dir;
	}

	/** A shared site lock covers every writer and prevents concurrent chunks. */
	public static function locked( $callback ) {
		$root = self::root();
		if ( ! $root || is_link( $root . '/worker.lock' ) ) {
			return self::error( 'storage', __( 'No hay almacenamiento temporal privado disponible.', 'taxocommerce-import-export-pro' ) );
		}
		$lock = fopen( $root . '/worker.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A local advisory lock is required for atomic checkpoint ownership.
		if ( ! $lock ) {
			return self::error( 'storage', __( 'No se puede abrir el bloqueo del proceso.', 'taxocommerce-import-export-pro' ) );
		}
		if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release private lock handle.
			return self::error( 'busy', __( 'Otro bloque está trabajando. Vuelve a intentarlo.', 'taxocommerce-import-export-pro' ) );
		}
		try {
			return $callback();
		} catch ( \Throwable $exception ) {
			return self::error( 'worker_error', __( 'El proceso no pudo guardar su punto de control. Revisa el estado antes de reanudar.', 'taxocommerce-import-export-pro' ) );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release private lock handle.
		}
	}

	public static function active( $state ) {
		return is_array( $state ) && in_array( $state['phase'], array( 'checking', 'importing', 'exporting' ), true );
	}

	private static function args( $state ) {
		return array( (int) $state['owner'], $state['kind'], $state['type'], $state['token'] );
	}

	private static function schedule( $state ) {
		$args = self::args( $state );
		if ( self::active( $state ) && empty( $state['paused'] ) && ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + Load::profile( $state['speed'] )['delay'], self::HOOK, $args );
		}
	}

	private static function unschedule( $state ) {
		wp_clear_scheduled_hook( self::HOOK, self::args( $state ) );
	}

	private static function clean( $state ) {
		self::unschedule( $state );
		$dir = self::directory( $state['token'] );
		if ( ! $dir ) {
			return;
		}
		for ( $i = 0; $i < self::MAX_FILES; ++$i ) {
			wp_delete_file( $dir . '/input-' . $i . '.csv' );
		}
		wp_delete_file( $dir . '/export.csv' );
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only this job's empty private spool directory.
	}

	public static function history( $owner, $kind, $type ) {
		$items = get_option( self::key( $owner, $kind, $type ) . '_history', array() );
		return is_array( $items ) ? $items : array();
	}

	private static function archive( $state ) {
		$history = self::history( $state['owner'], $state['kind'], $state['type'] );
		array_unshift( $history, self::view( $state ) );
		update_option( self::key( $state['owner'], $state['kind'], $state['type'] ) . '_history', array_slice( $history, 0, 5 ), false );
		self::clean( $state );
	}

	private static function initial( $kind, $type, $speed ) {
		$owner = get_current_user_id();
		if ( ! self::allowed( $kind, $type ) || $owner < 1 ) {
			return self::error( 'forbidden', __( 'Acceso no autorizado.', 'taxocommerce-import-export-pro' ) );
		}
		$old = self::state( $owner, $kind, $type );
		if ( $old ) {
			return self::error( 'active_job', __( 'Archiva y limpia el trabajo anterior de este bloque.', 'taxocommerce-import-export-pro' ) );
		}
		$token = str_replace( '-', '', wp_generate_uuid4() );
		if ( ! self::directory( $token, true ) ) {
			return self::error( 'storage', __( 'No se puede crear el directorio privado.', 'taxocommerce-import-export-pro' ) );
		}
		return array(
			'owner' => $owner, 'kind' => $kind, 'type' => $type, 'token' => $token,
			'phase' => 'import' === $kind ? 'checking' : 'exporting', 'paused' => false, 'inflight' => false,
			'speed' => max( 1, min( 5, (int) $speed ) ), 'sample' => array(), 'files' => array(),
			'cursor' => 0, 'seen' => array(), 'notices' => array(), 'started' => gmdate( 'c' ),
		);
	}

	private static function activate( $state ) {
		$state = self::save( $state );
		self::schedule( $state );
		return $state;
	}

	/** Read CSV headers once; mapping remains bound to the block type. */
	public static function inspect( $path, $type, $name ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read validated private CSV.
		if ( ! $handle ) {
			return self::error( 'open', __( 'No se puede abrir el CSV.', 'taxocommerce-import-export-pro' ) );
		}
		try {
			$line = fgets( $handle, self::MAX_HEADER_BYTES + 1 );
			$counts = array();
			foreach ( array( ',', ';', "\t" ) as $delimiter ) {
				$counts[ $delimiter ] = is_string( $line ) ? count( str_getcsv( $line, $delimiter, '"', '' ) ) : 0;
			}
			arsort( $counts );
			$delimiter = key( $counts );
			rewind( $handle );
			$headers = fgetcsv( $handle, 0, $delimiter, '"', '' );
			$offset = ftell( $handle );
			$sample = fgetcsv( $handle, 0, $delimiter, '"', '' );
			if ( ! is_array( $headers ) || count( $headers ) < 1 || count( $headers ) > self::MAX_COLUMNS || $offset > self::MAX_HEADER_BYTES || ! is_array( $sample ) || count( $sample ) !== count( $headers ) || false !== strpos( implode( '', $headers ) . implode( '', $sample ), "\0" ) ) {
				return self::error( 'header', __( 'Cabecera o primera fila incorrecta.', 'taxocommerce-import-export-pro' ) );
			}
			$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );
			$mapping = CSV::automatic_mapping( $type, $headers );
			if ( is_wp_error( $mapping ) ) {
				return $mapping;
			}
			return array(
				'name' => $name, 'columns' => count( $headers ), 'map' => $mapping['map'], 'ignored' => array_map( 'sanitize_text_field', $mapping['ignored'] ),
				'delimiter' => $delimiter, 'start' => $offset, 'offset' => $offset, 'phase' => 'pending',
				'checked' => 0, 'processed' => 0, 'saved' => 0, 'errors' => 0, 'notices' => array(),
			);
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- End CSV inspection.
		}
	}

	/** Receive 1-20 native CSVs, in natural filename order, as private data from actual PHP HTTP uploads. */
	public static function create_import( $type, $speed, $uploads ) {
		return self::locked( static function () use ( $type, $speed, $uploads ) {
			$state = self::initial( 'import', $type, $speed );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$dir = self::directory( $state['token'] );
			$names = $uploads['name'] ?? array();
			$total = 0;
			$items = array();
			if ( ! is_array( $names ) || count( $names ) < 1 || count( $names ) > self::MAX_FILES ) {
				self::clean( $state );
				return self::error( 'count', __( 'Selecciona entre 1 y 20 archivos CSV.', 'taxocommerce-import-export-pro' ) );
			}
			foreach ( $names as $index => $raw_name ) {
				$name = is_string( $raw_name ) ? sanitize_file_name( $raw_name ) : '';
				$tmp = $uploads['tmp_name'][ $index ] ?? '';
				$size = $uploads['size'][ $index ] ?? 0;
				$error = $uploads['error'][ $index ] ?? UPLOAD_ERR_NO_FILE;
				if ( ! is_string( $tmp ) || ! is_numeric( $size ) || ! is_numeric( $error ) || UPLOAD_ERR_OK !== (int) $error || (int) $size < 1 || (int) $size > self::MAX_FILE_BYTES || 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) || ! is_uploaded_file( $tmp ) || (int) $size !== filesize( $tmp ) ) {
					self::clean( $state );
					return self::error( 'upload', sprintf( /* translators: 1: CSV filename; 2: PHP upload error number. */ __( '%1$s: el CSV debe ser válido y tener hasta 30 MB. Código de recepción PHP: %2$d.', 'taxocommerce-import-export-pro' ), $name, (int) $error ) );
				}
				$total += (int) $size;
				$items[] = array( 'name' => $name, 'tmp_name' => $tmp, 'size' => (int) $size, 'error' => UPLOAD_ERR_OK, 'type' => 'text/csv' );
			}
			if ( $total > self::MAX_TOTAL_BYTES ) {
				self::clean( $state );
				return self::error( 'size', __( 'La carga conjunta no puede superar 100 MB.', 'taxocommerce-import-export-pro' ) );
			}
			usort( $items, static function ( $a, $b ) { return strnatcasecmp( $a['name'], $b['name'] ); } );
			// Like Lite 0.1.6, bypass Media MIME and upload filters. The source
			// has passed is_uploaded_file(), extension, size and PHP error checks.
			if ( ! class_exists( '\WP_Filesystem_Direct', false ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			$filesystem = new \WP_Filesystem_Direct( null );
			$result = true;
			foreach ( $items as $index => $item ) {
				$label = $item['name'];
				$expected = $dir . '/input-' . $index . '.csv';
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Do not disclose private filesystem paths in an upload failure.
				if ( file_exists( $expected ) || is_link( $expected ) || ! @$filesystem->copy( $item['tmp_name'], $expected, false, 0600 ) ) {
					$result = self::error( 'upload_copy', sprintf( /* translators: %s: CSV filename. */ __( '%s: no se pudo copiar el CSV al directorio privado. Comprueba los permisos y el espacio disponible.', 'taxocommerce-import-export-pro' ), $label ) );
					break;
				}
				clearstatcache( true, $expected );
				if ( ! $filesystem->chmod( $expected, 0600 ) || ! is_readable( $expected ) || $item['size'] !== wp_filesize( $expected ) ) {
					$result = self::error( 'upload_integrity', sprintf( /* translators: %s: CSV filename. */ __( '%s: el CSV temporal no es legible o no coincide con el tamaño recibido.', 'taxocommerce-import-export-pro' ), $label ) );
					break;
				}
				$file = self::inspect( $expected, $type, $label );
				if ( is_wp_error( $file ) ) {
					$result = self::error( 'csv', $label . ': ' . $file->get_error_message() );
					break;
				}
				$state['files'][] = $file;
			}
			if ( is_wp_error( $result ) ) {
				self::clean( $state );
				return $result;
			}
			return self::activate( $state );
		} );
	}

	public static function create_export( $type, $speed ) {
		return self::locked( static function () use ( $type, $speed ) {
			$state = self::initial( 'export', $type, $speed );
			if ( is_wp_error( $state ) ) {
				return $state;
			}
			$fields = array_keys( CSV::fields( $type ) );
			if ( ! $fields || count( $fields ) > self::MAX_COLUMNS ) {
				self::clean( $state );
				return self::error( 'fields', __( 'El esquema de exportación supera el límite de columnas.', 'taxocommerce-import-export-pro' ) );
			}
			$handle = fopen( self::directory( $state['token'] ) . '/export.csv', 'xb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Create private resumable CSV.
			if ( ! $handle ) {
				self::clean( $state );
				return self::error( 'export', __( 'No se puede crear el CSV de exportación.', 'taxocommerce-import-export-pro' ) );
			}
			$written = fputcsv( $handle, $fields, ',', '"', '' );
			$offset = ftell( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- End CSV initialization.
			if ( false === $written || false === $offset || $offset > self::MAX_HEADER_BYTES ) {
				self::clean( $state );
				return self::error( 'export', __( 'No se pudo escribir la cabecera.', 'taxocommerce-import-export-pro' ) );
			}
			$state['export'] = array( 'fields' => $fields, 'page' => 1, 'ids' => array(), 'index' => 0, 'offset' => $offset, 'rows' => 0 );
			return self::activate( $state );
		} );
	}

	/** Detect duplicate normalized identities inside a file without merging unrelated files. */
	public static function identities( $type, $data ) {
		$keys = array();
		if ( 'product' === $type && ! empty( $data['sku'] ) ) {
			$keys[] = 'sku:' . strtolower( sanitize_text_field( $data['sku'] ) );
		}
		if ( ! empty( $data['slug'] ) ) {
			$keys[] = 'slug:' . sanitize_title( $data['slug'] );
		} elseif ( ! in_array( $type, array( 'page', 'post', 'product' ), true ) && ! empty( $data['name'] ) ) {
			$keys[] = 'name:' . sanitize_title( $data['name'] );
		}
		return array_map( static function ( $key ) { return hash( 'sha256', $key ); }, $keys );
	}

	private static function import_chunk( &$state, $window ) {
		$i = (int) $state['cursor'];
		$file =& $state['files'][ $i ];
		$checking = 'checking' === $state['phase'];
		$file['phase'] = $checking ? 'checking' : 'importing';
		$dir = self::directory( $state['token'] );
		if ( ! $dir ) {
			throw new \RuntimeException( 'Private input directory unavailable.' );
		}
		$path = $dir . '/input-' . $i . '.csv';
		$handle = ! is_link( $path ) && is_file( $path ) ? fopen( $path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Private job CSV, no user-supplied path.
		if ( ! $handle ) {
			throw new \RuntimeException( 'Missing input file.' );
		}
		$rows = 0;
		try {
			if ( 0 !== fseek( $handle, $file['offset'] ) ) {
				throw new \RuntimeException( 'Invalid input offset.' );
			}
			while ( Load::can_continue( $window, $rows ) && false !== ( $row = fgetcsv( $handle, 0, $file['delimiter'], '"', '' ) ) ) {
				++$rows;
				$number = $checking ? ++$file['checked'] : ++$file['processed'];
				$result = true;
				if ( $number > self::MAX_ROWS ) {
					$result = self::error( 'row_limit', __( 'Divide el archivo: máximo 25.000 registros por CSV.', 'taxocommerce-import-export-pro' ) );
				} elseif ( count( $row ) !== $file['columns'] || false !== strpos( implode( '', $row ), "\0" ) ) {
					$result = self::error( 'columns', __( 'Número de columnas incorrecto.', 'taxocommerce-import-export-pro' ) );
				} else {
					$data = CSV::map_row( $row, $file['map'] );
					if ( $checking ) {
						$result = CSV::precheck( $state['type'], $data, 'upsert' );
						if ( ! is_wp_error( $result ) ) {
							foreach ( self::identities( $state['type'], $data ) as $hash ) {
								if ( isset( $state['seen'][ $hash ] ) ) {
									$result = self::error( 'duplicate', __( 'Esta identidad se repite en el archivo.', 'taxocommerce-import-export-pro' ) );
									break;
								}
								$state['seen'][ $hash ] = true;
							}
						}
					} else {
						$result = CSV::import( $state['type'], $data, 'upsert' );
					}
				}
				$file['offset'] = ftell( $handle );
				if ( is_wp_error( $result ) ) {
					++$file['errors'];
					$file['notices'][] = sprintf( /* translators: 1: CSV line; 2: error. */ __( 'Fila %1$d: %2$s', 'taxocommerce-import-export-pro' ), $number + 1, $result->get_error_message() );
					$file['notices'] = array_slice( $file['notices'], -20 );
					if ( ! $checking ) {
						$file['phase'] = 'failed';
						$state['phase'] = 'failed';
						break;
					}
					if ( $number > self::MAX_ROWS ) {
						$file['phase'] = 'invalid';
						break;
					}
				} elseif ( ! $checking ) {
					++$file['saved'];
				}
			}
			if ( false === ( $row ?? null ) && ! feof( $handle ) ) {
				throw new \RuntimeException( 'CSV read failed before end of file.' );
			}
			if ( 'failed' !== $state['phase'] && ( feof( $handle ) || 'invalid' === $file['phase'] ) ) {
				$file['phase'] = $file['errors'] ? 'invalid' : ( $checking ? 'checked' : 'imported' );
				$state['seen'] = array();
				++$state['cursor'];
				if ( $state['cursor'] >= count( $state['files'] ) ) {
					$errors = array_sum( array_column( $state['files'], 'errors' ) );
					$state['phase'] = $checking ? ( $errors ? 'invalid' : 'ready' ) : 'done';
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close input checkpoint stream.
		}
		return $rows;
	}

	private static function export_chunk( &$state, $window ) {
		$out =& $state['export'];
		$dir = self::directory( $state['token'] );
		if ( ! $dir ) {
			throw new \RuntimeException( 'Private export directory unavailable.' );
		}
		$path = $dir . '/export.csv';
		clearstatcache( true, $path );
		$handle = ! is_link( $path ) && is_file( $path ) ? fopen( $path, 'r+b' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reopen private export for checkpointed append.
		if ( ! $handle ) {
			throw new \RuntimeException( 'Missing export file.' );
		}
		$rows = 0;
		try {
			if ( filesize( $path ) < $out['offset'] || ! ftruncate( $handle, $out['offset'] ) || 0 !== fseek( $handle, $out['offset'] ) ) {
				throw new \RuntimeException( 'Invalid export checkpoint.' );
			}
			while ( Load::can_continue( $window, $rows ) ) {
				if ( ! $out['ids'] ) {
					$ids = CSV::export_ids( $state['type'], $out['page'], self::EXPORT_PAGE );
					if ( is_wp_error( $ids ) || ! is_array( $ids ) ) {
						throw new \RuntimeException( 'Export query failed.' );
					}
					if ( ! $ids ) {
						$state['phase'] = 'done';
						break;
					}
					$out['ids'] = array_values( array_map( 'absint', $ids ) );
					$out['index'] = 0;
				}
				$id = $out['ids'][ $out['index'] ];
				if ( in_array( $state['type'], array( 'product', 'page', 'post' ), true ) && ! current_user_can( 'read_post', $id ) ) {
					throw new \RuntimeException( 'Record access denied.' );
				}
				$row = array();
				foreach ( $out['fields'] as $field ) {
					$row[] = CSV::csv_safe( CSV::export_value( $state['type'], $id, $field ) );
				}
				if ( false === fputcsv( $handle, $row, ',', '"', '' ) ) {
					throw new \RuntimeException( 'Export write failed.' );
				}
				$out['offset'] = ftell( $handle );
				if ( $out['offset'] > self::MAX_TOTAL_BYTES ) {
					throw new \RuntimeException( 'Export limit exceeded.' );
				}
				++$out['rows'];
				++$rows;
				++$out['index'];
				if ( $out['index'] >= count( $out['ids'] ) ) {
					if ( count( $out['ids'] ) < self::EXPORT_PAGE ) {
						$state['phase'] = 'done';
						break;
					}
					++$out['page'];
					$out['ids'] = array();
					$out['index'] = 0;
				}
			}
			if ( ! fflush( $handle ) ) {
				throw new \RuntimeException( 'Export flush failed.' );
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close export checkpoint stream.
		}
		return $rows;
	}

	/** Persist an in-flight marker before touching rows; never replay an interrupted chunk silently. */
	private static function step( $state ) {
		if ( ! self::active( $state ) || ! empty( $state['paused'] ) ) {
			return $state;
		}
		if ( ! empty( $state['inflight'] ) ) {
			$state['paused'] = true;
			$state['notices'][] = __( 'Bloque interrumpido. Comprueba los datos guardados antes de reanudar.', 'taxocommerce-import-export-pro' );
			self::unschedule( $state );
			return self::save( $state );
		}
		$state['inflight'] = true;
		$state = self::save( $state );
		self::schedule( $state );
		$window = Load::begin( $state['speed'], $state['sample'] );
		try {
			$rows = 'export' === $state['kind'] ? self::export_chunk( $state, $window ) : self::import_chunk( $state, $window );
		} catch ( \Throwable $exception ) {
			// The persisted pre-chunk offset remains authoritative after a failed write.
			$state = self::state( $state['owner'], $state['kind'], $state['type'] );
			$state['paused'] = true;
			$state['notices'][] = __( 'Error de lectura, escritura o límite de recursos. Revisa el archivo y el punto de control antes de reanudar.', 'taxocommerce-import-export-pro' );
			self::unschedule( $state );
			return self::save( $state );
		}
		$state['sample'] = Load::finish( $window, $rows );
		$state['inflight'] = false;
		if ( 0 === $rows && self::active( $state ) && null !== $state['sample']['memory'] && $state['sample']['memory'] >= 78 ) {
			$state['paused'] = true;
			$state['notices'][] = __( 'Pausado por memoria PHP elevada.', 'taxocommerce-import-export-pro' );
		}
		$state = self::save( $state );
		if ( ! self::active( $state ) || $state['paused'] ) {
			self::unschedule( $state );
		} else {
			self::schedule( $state );
		}
		return $state;
	}

	public static function command( $owner, $kind, $type, $token, $operation, $speed = 3 ) {
		return self::locked( static function () use ( $owner, $kind, $type, $token, $operation, $speed ) {
			if ( (int) $owner !== get_current_user_id() || ! self::allowed( $kind, $type ) ) {
				return self::error( 'forbidden', __( 'Acceso no autorizado.', 'taxocommerce-import-export-pro' ) );
			}
			$state = self::state( $owner, $kind, $type );
			if ( ! $state || ! is_string( $token ) || ! hash_equals( $state['token'], $token ) ) {
				return self::error( 'stale', __( 'El trabajo ha cambiado. Recarga la página.', 'taxocommerce-import-export-pro' ) );
			}
			switch ( $operation ) {
				case 'step':
					return self::step( $state );
				case 'confirm':
					if ( 'ready' !== $state['phase'] ) {
						return self::error( 'preflight', __( 'El prechequeo debe terminar sin errores.', 'taxocommerce-import-export-pro' ) );
					}
					$state['phase'] = 'importing';
					$state['cursor'] = 0;
					$state['seen'] = array();
					foreach ( $state['files'] as &$file ) {
						$file['offset'] = $file['start'];
						$file['phase'] = 'pending';
					}
					unset( $file );
					break;
				case 'pause':
					if ( ! self::active( $state ) ) {
						return self::error( 'finished', __( 'El trabajo ya ha terminado.', 'taxocommerce-import-export-pro' ) );
					}
					$state['paused'] = true;
					break;
				case 'resume':
					if ( ! self::active( $state ) || ! $state['paused'] ) {
						return self::error( 'not_paused', __( 'El trabajo no está pausado.', 'taxocommerce-import-export-pro' ) );
					}
					$state['paused'] = false;
					$state['inflight'] = false;
					break;
				case 'speed':
					$state['speed'] = max( 1, min( 5, (int) $speed ) );
					break;
				case 'clear':
					if ( self::active( $state ) && ! $state['paused'] ) {
						return self::error( 'active_job', __( 'Pausa el trabajo antes de descartarlo.', 'taxocommerce-import-export-pro' ) );
					}
					self::archive( $state );
					delete_option( self::key( $owner, $kind, $type ) );
					return array();
				default:
					return self::error( 'operation', __( 'Acción no válida.', 'taxocommerce-import-export-pro' ) );
			}
			$state = self::save( $state );
			if ( self::active( $state ) && ! $state['paused'] ) {
				self::schedule( $state );
			} else {
				self::unschedule( $state );
			}
			return $state;
		} );
	}

	/** WordPress runs the job with its original user's current permissions. */
	public static function cron( $owner, $kind, $type, $token ) {
		if ( ! self::valid( $kind, $type ) || ! self::valid_token( $token ) || ! get_userdata( (int) $owner ) ) {
			return;
		}
		$previous = get_current_user_id();
		wp_set_current_user( (int) $owner );
		try {
			$result = self::command( $owner, $kind, $type, $token, 'step' );
			if ( is_wp_error( $result ) && 'busy' === $result->get_error_code() ) {
				$state = self::state( $owner, $kind, $type );
				if ( $state && hash_equals( $state['token'], $token ) ) {
					self::schedule( $state );
				}
			}
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/** Never send paths, row identities or internal state to the browser. */
	public static function view( $state ) {
		if ( ! $state ) {
			return array();
		}
		$files = array();
		foreach ( $state['files'] as $file ) {
			$files[] = array_intersect_key( $file, array_flip( array( 'name', 'phase', 'checked', 'processed', 'saved', 'errors', 'notices', 'ignored' ) ) );
		}
		return array(
			'token' => $state['token'], 'kind' => $state['kind'], 'type' => $state['type'], 'phase' => $state['phase'],
			'paused' => $state['paused'], 'active' => self::active( $state ), 'speed' => $state['speed'], 'sample' => $state['sample'],
			'files' => $files, 'notices' => array_slice( $state['notices'], -10 ), 'rows' => (int) ( $state['export']['rows'] ?? 0 ),
			'started' => $state['started'], 'updated' => $state['updated'] ?? $state['started'],
		);
	}

	public static function download_path( $state ) {
		if ( ! is_array( $state ) || 'export' !== $state['kind'] || 'done' !== $state['phase'] ) {
			return false;
		}
		$dir = self::directory( $state['token'] );
		$path = $dir ? $dir . '/export.csv' : false;
		if ( $path ) {
			clearstatcache( true, $path );
		}
		return $path && ! is_link( $path ) && is_file( $path ) && filesize( $path ) === $state['export']['offset'] ? $path : false;
	}
}
