<?php
/**
 * CSV-only supplier importer and reusable mapping templates.
 *
 * @package TaxoCommerceSuppliers
 */

defined( 'ABSPATH' ) || exit;

final class TCSUP_CSV {

	const TEMPLATE_OPTION = 'taxocommerce_suppliers_csv_templates';

	public static function fields() {
		return array(
			'proveedor_id_externo' => array( 'label' => 'Supplier product ID', 'required' => true, 'type' => 'text' ),
			'sku'                   => array( 'label' => 'Supplier SKU', 'type' => 'text' ),
			'source_row_key'        => array( 'label' => 'Stable variant key', 'type' => 'text' ),
			'pais_origen'           => array( 'label' => 'Market country', 'type' => 'country' ),
			'idioma_origen'         => array( 'label' => 'Source language', 'type' => 'text' ),
			'mpn'                   => array( 'label' => 'MPN', 'type' => 'text' ),
			'url_origen'            => array( 'label' => 'Supplier product URL', 'type' => 'url' ),
			'url_canonica'          => array( 'label' => 'Canonical URL', 'type' => 'url' ),
			'nombre'                => array( 'label' => 'Product name', 'required' => true, 'type' => 'text' ),
			'descripcion'           => array( 'label' => 'Description', 'type' => 'html' ),
			'marca'                 => array( 'label' => 'Brand', 'type' => 'text' ),
			'categoria_proveedor'   => array( 'label' => 'Supplier category', 'type' => 'text' ),
			'precio_sin_iva'        => array( 'label' => 'Price excl. tax', 'type' => 'decimal' ),
			'precio_con_iva'        => array( 'label' => 'Price incl. tax', 'type' => 'decimal' ),
			'iva_porcentaje'        => array( 'label' => 'Tax %', 'type' => 'decimal' ),
			'moneda'                => array( 'label' => 'Currency', 'type' => 'currency' ),
			'stock_estado'          => array( 'label' => 'Stock state', 'type' => 'text' ),
			'stock_cantidad'        => array( 'label' => 'Stock quantity', 'type' => 'decimal' ),
			'stock_texto'           => array( 'label' => 'Stock text', 'type' => 'text' ),
			'imagenes'              => array( 'label' => 'External image URLs', 'type' => 'images' ),
		);
	}

	public static function builtin_templates() {
		return array(
			'canonical' => array(
				'id'       => 'canonical',
				'name'     => 'TaxoCommerce canonical CSV',
				'provider' => '',
				'mode'     => 'canonical',
				'builtin'  => true,
				'mapping'  => array(),
			),
			'vevor' => array(
				'id'       => 'vevor',
				'name'     => 'VEVOR official CSV',
				'provider' => 'VEVOR',
				'mode'     => 'vevor',
				'builtin'  => true,
				'mapping'  => array(),
			),
			'satkit' => array(
				'id'       => 'satkit',
				'name'     => 'SATKIT visual mapping',
				'provider' => 'SATKIT',
				'mode'     => 'mapping',
				'builtin'  => true,
				'mapping'  => array(),
			),
			'todotaladros' => array(
				'id'       => 'todotaladros',
				'name'     => 'TodoTaladros visual mapping',
				'provider' => 'TODOTALADROS',
				'mode'     => 'mapping',
				'builtin'  => true,
				'mapping'  => array(),
			),
		);
	}

	public static function templates() {
		$templates = get_option( self::TEMPLATE_OPTION, array() );
		if ( ! is_array( $templates ) ) {
			$templates = array();
		}
		return array_merge( self::builtin_templates(), $templates );
	}

	private static function guard( $nonce_action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage supplier imports.', 'taxocommerce-suppliers' ) );
		}
		check_admin_referer( $nonce_action );
	}

	public static function transient_key() {
		return 'tcsup_csv_' . get_current_user_id();
	}

	public static function state() {
		$state = get_transient( self::transient_key() );
		return is_array( $state ) ? $state : array();
	}

	public static function clear_state() {
		delete_transient( self::transient_key() );
	}

	private static function redirect( $args = array() ) {
		$args = array_merge( array( 'page' => 'taxocommerce-suppliers', 'tab' => 'import' ), $args );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function detect_delimiter( $line ) {
		$candidates = array( ';', ',', "	" );
		$best       = ';';
		$best_count = -1;
		foreach ( $candidates as $candidate ) {
			$count = count( str_getcsv( (string) $line, $candidate, '"', '' ) );
			if ( $count > $best_count ) {
				$best       = $candidate;
				$best_count = $count;
			}
		}
		return $best;
	}

	private static function open_csv( $path, $delimiter = '' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native CSV streaming is required.
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'tcsup_csv_open', 'The CSV file could not be opened.' );
		}
		$first = fgets( $handle );
		rewind( $handle );
		$delimiter = $delimiter ?: self::detect_delimiter( (string) $first );
		return array( $handle, $delimiter );
	}

	private static function clean_header( $value ) {
		$value = preg_replace( '/^ï»¿/', '', (string) $value );
		return trim( wp_strip_all_tags( $value ) );
	}

	public static function handle_analyze() {
		self::guard( 'tcsup_analyze_csv' );

		$template_id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : 'canonical';
		$templates   = self::templates();
		$template    = isset( $templates[ $template_id ] ) ? $templates[ $template_id ] : $templates['canonical'];
		$provider    = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : (string) ( $template['provider'] ?? '' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Upload is validated below.
		$file = isset( $_FILES['supplier_csv'] ) && is_array( $_FILES['supplier_csv'] ) ? $_FILES['supplier_csv'] : array();
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			self::redirect( array( 'tc_error' => 'upload' ) );
		}

		$name     = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$size     = absint( $file['size'] ?? 0 );

		if ( ! preg_match( '/\.csv$/i', $name ) || ! is_uploaded_file( $tmp_name ) || $size < 1 ) {
			self::redirect( array( 'tc_error' => 'csv_only' ) );
		}
		$max = (int) wp_max_upload_size();
		if ( $max > 0 && $size > $max ) {
			self::redirect( array( 'tc_error' => 'too_large' ) );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			self::redirect( array( 'tc_error' => 'storage' ) );
		}

		$directory = trailingslashit( $uploads['basedir'] ) . 'taxocommerce-suppliers/originals';
		wp_mkdir_p( $directory );
		if ( ! is_dir( $directory ) || ! wp_is_writable( $directory ) ) {
			self::redirect( array( 'tc_error' => 'storage' ) );
		}

		$stored_name = wp_unique_filename( $directory, wp_date( 'Ymd_His' ) . '_' . $name );
		$path        = trailingslashit( $directory ) . $stored_name;
		if ( ! move_uploaded_file( $tmp_name, $path ) ) {
			self::redirect( array( 'tc_error' => 'storage' ) );
		}

		$opened = self::open_csv( $path );
		if ( is_wp_error( $opened ) ) {
			self::redirect( array( 'tc_error' => 'read' ) );
		}

		list( $handle, $delimiter ) = $opened;
		$header = fgetcsv( $handle, 0, $delimiter, '"', '' );
		if ( ! is_array( $header ) || count( $header ) < 2 ) {
			fclose( $handle );
			self::redirect( array( 'tc_error' => 'header' ) );
		}
		$header = array_map( array( __CLASS__, 'clean_header' ), $header );

		$preview = array();
		while ( count( $preview ) < 8 && false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) {
			if ( ! array_filter( $row, static function ( $value ) { return '' !== trim( (string) $value ); } ) ) {
				continue;
			}
			$preview[] = $row;
		}
		fclose( $handle );

		$state = array(
			'path'        => wp_normalize_path( $path ),
			'filename'    => $stored_name,
			'header'      => $header,
			'delimiter'   => $delimiter,
			'preview'     => $preview,
			'provider'    => $provider,
			'template_id' => $template_id,
			'analyzed_at' => time(),
		);
		set_transient( self::transient_key(), $state, 2 * HOUR_IN_SECONDS );

		self::redirect( array( 'analyzed' => 1 ) );
	}

	private static function normalize_decimal( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$value = str_replace( array( "Â ", ' ' ), '', $value );
		if ( false !== strpos( $value, ',' ) && false !== strpos( $value, '.' ) ) {
			if ( strrpos( $value, ',' ) > strrpos( $value, '.' ) ) {
				$value = str_replace( '.', '', $value );
				$value = str_replace( ',', '.', $value );
			} else {
				$value = str_replace( ',', '', $value );
			}
		} else {
			$value = str_replace( ',', '.', $value );
		}
		$value = preg_replace( '/[^0-9.\-]/', '', $value );
		return is_numeric( $value ) ? (string) $value : null;
	}

	public static function image_urls( $value ) {
		if ( is_array( $value ) ) {
			$candidates = $value;
		} else {
			$value = trim( (string) $value );
			$decoded = json_decode( $value, true );
			$candidates = is_array( $decoded ) ? $decoded : preg_split( '/[\r\n|]+/', $value );
		}
		$urls = array();
		foreach ( (array) $candidates as $candidate ) {
			$url = esc_url_raw( trim( (string) $candidate ) );
			if ( '' !== $url && ! in_array( $url, $urls, true ) ) {
				$urls[] = $url;
			}
		}
		return $urls;
	}

	private static function sanitize_value( $field, $value ) {
		$type = self::fields()[ $field ]['type'] ?? 'text';
		switch ( $type ) {
			case 'decimal':
				return self::normalize_decimal( $value );
			case 'url':
				return esc_url_raw( trim( (string) $value ) );
			case 'html':
				return wp_kses_post( (string) $value );
			case 'currency':
				return strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $value ), 0, 12 ) );
			case 'country':
				return strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $value ), 0, 2 ) );
			case 'images':
				$urls = self::image_urls( $value );
				return $urls ? wp_json_encode( $urls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	private static function mapping_from_request( $header ) {
		$posted = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : array();
		$map    = array();
		foreach ( self::fields() as $field => $definition ) {
			$index = isset( $posted[ $field ] ) ? intval( $posted[ $field ] ) : -1;
			if ( $index >= 0 && isset( $header[ $index ] ) ) {
				$map[ $field ] = $index;
			}
		}
		return $map;
	}

	private static function mapping_from_template( $template, $header ) {
		$mode = (string) ( $template['mode'] ?? 'mapping' );
		if ( 'canonical' === $mode ) {
			$map = array();
			foreach ( self::fields() as $field => $definition ) {
				foreach ( $header as $index => $column ) {
					if ( sanitize_key( $column ) === $field ) {
						$map[ $field ] = $index;
						break;
					}
				}
			}
			return $map;
		}

		$stored = (array) ( $template['mapping'] ?? array() );
		$map    = array();
		foreach ( $stored as $field => $source_name ) {
			foreach ( $header as $index => $column ) {
				if ( (string) $column === (string) $source_name || sanitize_key( $column ) === sanitize_key( $source_name ) ) {
					$map[ sanitize_key( $field ) ] = $index;
					break;
				}
			}
		}
		return $map;
	}

	private static function vevor_transform( $assoc ) {
		$url = trim( (string) ( $assoc['URL to product'] ?? '' ) );
		$marker = strrpos( $url, 'p_' );
		if ( false === $marker ) {
			return new WP_Error( 'tcsup_vevor_id', 'VEVOR product URL does not contain p_.' );
		}
		$id = substr( $url, $marker + 2, 12 );
		if ( 12 !== strlen( $id ) || ! ctype_digit( $id ) ) {
			return new WP_Error( 'tcsup_vevor_id', 'VEVOR product ID could not be extracted.' );
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		$markets = array(
			'vevor.de' => array( 'DE', 'de' ),
			'vevor.nl' => array( 'NL', 'nl' ),
			'vevor.com' => array( 'US', 'en' ),
			'vevor.fr' => array( 'FR', 'fr' ),
			'vevor.co.uk' => array( 'GB', 'en' ),
			'vevor.es' => array( 'ES', 'es' ),
			'vevor.it' => array( 'IT', 'it' ),
			'vevor.ca' => array( 'CA', 'en' ),
			'vevor.com.au' => array( 'AU', 'en' ),
		);
		if ( ! isset( $markets[ $host ] ) ) {
			return new WP_Error( 'tcsup_vevor_market', 'VEVOR domain is not supported.' );
		}
		$country = strtoupper( trim( (string) ( $assoc['Country'] ?? $assoc['country'] ?? '' ) ) );
		if ( '' === $country ) {
			$country = $markets[ $host ][0];
		}
		$languages = array( 'DE'=>'de','NL'=>'nl','US'=>'en','FR'=>'fr','GB'=>'en','ES'=>'es','IT'=>'it','CA'=>'en','AU'=>'en' );
		$image = esc_url_raw( trim( (string) ( $assoc['URL to image'] ?? '' ) ) );
		$canonical = preg_replace( '/[?#].*$/', '', $url );

		return array(
			'proveedor_id_externo' => $id,
			'sku'                   => 'p_' . $id,
			'pais_origen'           => $country,
			'idioma_origen'         => $languages[ $country ] ?? 'en',
			'url_origen'            => esc_url_raw( $url ),
			'url_canonica'          => esc_url_raw( $canonical ),
			'nombre'                => sanitize_text_field( (string) ( $assoc['Name'] ?? '' ) ),
			'categoria_proveedor'   => sanitize_text_field( (string) ( $assoc['categoryPath'] ?? '' ) ),
			'imagenes'              => $image ? wp_json_encode( array( $image ), JSON_UNESCAPED_SLASHES ) : '',
			'precio_con_iva'        => self::normalize_decimal( $assoc['Sales'] ?? '' ),
			'moneda'                => strtoupper( sanitize_text_field( (string) ( $assoc['Currency'] ?? '' ) ) ),
		);
	}

	private static function row_hash( $data ) {
		$copy = $data;
		unset( $copy['raw_json'], $copy['ultima_importacion'], $copy['primera_importacion'], $copy['actualizado'] );
		ksort( $copy );
		return hash( 'sha256', wp_json_encode( $copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private static function changed_fields( $existing, $data ) {
		$changed = array();
		foreach ( $data as $field => $value ) {
			if ( in_array( $field, array( 'raw_json', 'ultima_importacion', 'actualizado', 'hash_producto' ), true ) ) {
				continue;
			}
			$old = isset( $existing[ $field ] ) ? (string) $existing[ $field ] : '';
			$new = null === $value ? '' : (string) $value;
			if ( $old !== $new ) {
				$changed[] = $field;
			}
		}
		return $changed;
	}

	public static function handle_import() {
		self::guard( 'tcsup_import_csv' );

		$state = self::state();
		if ( empty( $state['path'] ) || ! is_readable( $state['path'] ) ) {
			self::redirect( array( 'tc_error' => 'state' ) );
		}

		$templates   = self::templates();
		$template_id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : (string) ( $state['template_id'] ?? 'canonical' );
		$template    = isset( $templates[ $template_id ] ) ? $templates[ $template_id ] : $templates['canonical'];
		$provider    = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : (string) ( $state['provider'] ?? $template['provider'] ?? '' );
		$provider    = trim( $provider );
		if ( '' === $provider ) {
			self::redirect( array( 'tc_error' => 'provider' ) );
		}

		$opened = self::open_csv( $state['path'], (string) ( $state['delimiter'] ?? '' ) );
		if ( is_wp_error( $opened ) ) {
			self::redirect( array( 'tc_error' => 'read' ) );
		}
		list( $handle, $delimiter ) = $opened;
		$header = fgetcsv( $handle, 0, $delimiter, '"', '' );
		$header = is_array( $header ) ? array_map( array( __CLASS__, 'clean_header' ), $header ) : array();

		$mode = (string) ( $template['mode'] ?? 'mapping' );
		$mapping = 'mapping' === $mode
			? self::mapping_from_request( $header )
			: self::mapping_from_template( $template, $header );

		if ( 'vevor' !== $mode ) {
			foreach ( self::fields() as $field => $definition ) {
				if ( ! empty( $definition['required'] ) && ! isset( $mapping[ $field ] ) ) {
					fclose( $handle );
					self::redirect( array( 'tc_error' => 'required_mapping' ) );
				}
			}
		}

		global $wpdb;
		$table      = TCSUP_Schema::catalog_table();
		$now        = current_time( 'mysql' );
		$processed  = 0;
		$inserted   = 0;
		$updated    = 0;
		$unchanged  = 0;
		$errors     = 0;
		$occurrence = array();

		while ( false !== ( $row = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) {
			if ( ! array_filter( $row, static function ( $value ) { return '' !== trim( (string) $value ); } ) ) {
				continue;
			}
			$processed++;
			$assoc = array();
			foreach ( $header as $index => $column ) {
				$assoc[ $column ] = isset( $row[ $index ] ) ? $row[ $index ] : '';
			}

			if ( 'vevor' === $mode ) {
				$data = self::vevor_transform( $assoc );
				if ( is_wp_error( $data ) ) {
					$errors++;
					continue;
				}
			} else {
				$data = array();
				foreach ( self::fields() as $field => $definition ) {
					$value = isset( $mapping[ $field ] ) && isset( $row[ $mapping[ $field ] ] )
						? $row[ $mapping[ $field ] ]
						: '';
					$data[ $field ] = self::sanitize_value( $field, $value );
				}
			}

			$external_id = trim( (string) ( $data['proveedor_id_externo'] ?? '' ) );
			$sku         = trim( (string) ( $data['sku'] ?? '' ) );
			$name        = trim( (string) ( $data['nombre'] ?? '' ) );
			if ( '' === $external_id ) {
				$external_id = $sku;
				$data['proveedor_id_externo'] = $external_id;
			}
			if ( '' === $external_id || '' === $name ) {
				$errors++;
				continue;
			}

			$country = strtoupper( trim( (string) ( $data['pais_origen'] ?? '' ) ) );
			$row_key = trim( (string) ( $data['source_row_key'] ?? '' ) );
			if ( 'VEVOR' === strtoupper( $provider ) && '' === $row_key ) {
				$base = hash( 'sha256', wp_json_encode( array( $country, $sku, $name, $external_id ) ) );
				$occurrence[ $base ] = isset( $occurrence[ $base ] ) ? $occurrence[ $base ] + 1 : 1;
				$row_key = hash( 'sha256', $base . ':' . $occurrence[ $base ] );
				$data['source_row_key'] = $row_key;
			}

			$data['proveedor']          = $provider;
			$data['pais_origen']        = $country;
			$data['moneda']             = (string) ( $data['moneda'] ?? '' ) ?: 'EUR';
			$data['raw_json']           = wp_json_encode( $assoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$data['ultima_importacion'] = $now;
			$data['actualizado']        = $now;
			$data['hash_producto']      = self::row_hash( $data );

			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE proveedor=%s AND pais_origen=%s AND proveedor_id_externo=%s AND source_row_key=%s LIMIT 1",
					$provider,
					$country,
					$external_id,
					$row_key
				),
				ARRAY_A
			);

			if ( $existing ) {
				$changed = self::changed_fields( $existing, $data );
				$data['estado_sincronizacion'] = $changed ? 'modificado' : (string) ( $existing['estado_sincronizacion'] ?? 'sin_cambios' );
				$data['cambios_detectados']    = $changed ? implode( ',', $changed ) : '';
				$result = $wpdb->update( $table, $data, array( 'id' => absint( $existing['id'] ) ) );
				if ( false === $result ) {
					$errors++;
				} elseif ( $changed ) {
					$updated++;
				} else {
					$unchanged++;
				}
			} else {
				$data['estado_seleccion']      = 'pendiente';
				$data['estado_sincronizacion'] = 'nuevo';
				$data['modo_imagenes']         = 'external';
				$data['primera_importacion']   = $now;
				$data['creado']                = $now;
				$result = $wpdb->insert( $table, $data );
				if ( false === $result ) {
					$errors++;
				} else {
					$inserted++;
				}
			}
		}
		fclose( $handle );
		self::clear_state();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'taxocommerce-suppliers',
					'tab'        => 'inventory',
					'imported'   => $inserted + $updated + $unchanged,
					'new'        => $inserted,
					'updated'    => $updated,
					'unchanged'  => $unchanged,
					'errors'     => $errors,
					'processed'  => $processed,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_save_template() {
		self::guard( 'tcsup_save_template' );

		$state = self::state();
		$header = (array) ( $state['header'] ?? array() );
		if ( ! $header ) {
			self::redirect( array( 'tc_error' => 'state' ) );
		}

		$name     = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '';
		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		if ( '' === trim( $name ) ) {
			self::redirect( array( 'tc_error' => 'template_name' ) );
		}

		$mapping = self::mapping_from_request( $header );
		$stored_mapping = array();
		foreach ( $mapping as $field => $index ) {
			$stored_mapping[ $field ] = (string) $header[ $index ];
		}

		$id = 'custom_' . sanitize_key( $name );
		if ( 'custom_' === $id ) {
			$id .= wp_generate_password( 6, false, false );
		}

		$templates = get_option( self::TEMPLATE_OPTION, array() );
		if ( ! is_array( $templates ) ) {
			$templates = array();
		}
		$templates[ $id ] = array(
			'id'       => $id,
			'name'     => $name,
			'provider' => $provider,
			'mode'     => 'mapping',
			'builtin'  => false,
			'mapping'  => $stored_mapping,
		);
		update_option( self::TEMPLATE_OPTION, $templates, false );

		self::redirect( array( 'template_saved' => 1 ) );
	}

	public static function handle_delete_template() {
		self::guard( 'tcsup_delete_template' );
		$id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : '';
		if ( 0 === strpos( $id, 'custom_' ) ) {
			$templates = get_option( self::TEMPLATE_OPTION, array() );
			if ( is_array( $templates ) && isset( $templates[ $id ] ) ) {
				unset( $templates[ $id ] );
				update_option( self::TEMPLATE_OPTION, $templates, false );
			}
		}
		self::redirect( array( 'tab' => 'templates', 'template_deleted' => 1 ) );
	}

	public static function handle_download_template() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden.' );
		}
		check_admin_referer( 'tcsup_download_template' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="taxocommerce_supplier_template.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "ï»¿" );
		fputcsv( $out, array_keys( self::fields() ), ';', '"', '' );
		fclose( $out );
		exit;
	}
}
