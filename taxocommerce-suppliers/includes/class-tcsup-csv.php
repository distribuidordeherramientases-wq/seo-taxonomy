<?php
/**
 * CSV-only supplier importer and reusable mapping templates.
 *
 * @package TaxoCommerceSuppliers
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Stable TCSUP_ public class names are an existing namespace/API contract; renaming would break extensions.
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
			'price_update' => array(
				'id'       => 'price_update',
				'name'     => 'Supplier Price Update CSV',
				'provider' => '',
				'mode'     => 'price_update',
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

	public static function suggest_field_for_header( $header ) {
		$key = remove_accents( strtolower( trim( (string) $header ) ) );
		$key = preg_replace( '/[^a-z0-9]+/', '_', $key );
		$key = trim( (string) $key, '_' );
		$aliases = array(
			'id_satkit'           => 'proveedor_id_externo',
			'id_producto'         => 'proveedor_id_externo',
			'product_id'          => 'proveedor_id_externo',
			'productid'           => 'proveedor_id_externo',
			'id'                  => 'proveedor_id_externo',
			'codigo'              => 'proveedor_id_externo',
			'referencia'          => 'proveedor_id_externo',
			'sku'                 => 'sku',
			'mpn'                 => 'mpn',
			'country'             => 'pais_origen',
			'pais'                => 'pais_origen',
			'language'            => 'idioma_origen',
			'idioma'              => 'idioma_origen',
			'url_origen'          => 'url_origen',
			'url_to_product'      => 'url_origen',
			'product_url'         => 'url_origen',
			'enlace'              => 'url_origen',
			'url_canonica'        => 'url_canonica',
			'nombre'              => 'nombre',
			'name'                => 'nombre',
			'titulo'              => 'nombre',
			'title'               => 'nombre',
			'descripcion'         => 'descripcion',
			'description'         => 'descripcion',
			'marca_proveedor'     => 'marca',
			'marca'               => 'marca',
			'brand'               => 'marca',
			'categoria_proveedor' => 'categoria_proveedor',
			'categoria'           => 'categoria_proveedor',
			'category'            => 'categoria_proveedor',
			'categorypath'        => 'categoria_proveedor',
			'precio_sin_iva'      => 'precio_sin_iva',
			'cost'                => 'precio_sin_iva',
			'precio_iva'          => 'precio_con_iva',
			'precio_con_iva'      => 'precio_con_iva',
			'precio'              => 'precio_con_iva',
			'price'               => 'precio_con_iva',
			'sales'               => 'precio_con_iva',
			'iva_porcentaje'      => 'iva_porcentaje',
			'tax'                 => 'iva_porcentaje',
			'moneda'              => 'moneda',
			'currency'            => 'moneda',
			'stock_estado'        => 'stock_estado',
			'stock_status'        => 'stock_estado',
			'stock_cantidad'      => 'stock_cantidad',
			'stock_quantity'      => 'stock_cantidad',
			'stock_texto'         => 'stock_texto',
			'imagenes'            => 'imagenes',
			'imagen'              => 'imagenes',
			'url_to_image'        => 'imagenes',
			'image_url'           => 'imagenes',
		);
		return $aliases[ $key ] ?? '';
	}

	public static function templates() {
		$templates = get_option( self::TEMPLATE_OPTION, array() );
		if ( ! is_array( $templates ) ) {
			$templates = array();
		}
		return array_merge( self::builtin_templates(), $templates );
	}

	private static function guard() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage supplier imports.', 'taxocommerce-suppliers' ) );
		}
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
		try {
			$handle = new SplFileObject( $path, 'rb' );
		} catch ( RuntimeException $exception ) {
			return new WP_Error( 'tcsup_csv_open', 'The CSV file could not be opened.' );
		}

		$first = $handle->fgets();
		$handle->rewind();
		$delimiter = $delimiter ?: self::detect_delimiter( (string) $first );
		$handle->setCsvControl( $delimiter, '"', '' );
		return array( $handle, $delimiter );
	}

	private static function clean_header( $value ) {
		$value = preg_replace( '/^\\x{FEFF}/u', '', (string) $value );
		return trim( wp_strip_all_tags( $value ) );
	}

	public static function handle_analyze() {
		self::guard();
		check_admin_referer( 'tcsup_analyze_csv' );

		$template_id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : 'canonical';
		$templates   = self::templates();
		$template    = isset( $templates[ $template_id ] ) ? $templates[ $template_id ] : $templates['canonical'];
		$provider    = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		if ( '' === trim( $provider ) ) {
			$provider = sanitize_text_field( (string) ( $template['provider'] ?? '' ) );
		}

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

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem || ! $wp_filesystem->move( $tmp_name, $path, true ) ) {
			self::redirect( array( 'tc_error' => 'storage' ) );
		}

		$opened = self::open_csv( $path );
		if ( is_wp_error( $opened ) ) {
			self::redirect( array( 'tc_error' => 'read' ) );
		}

		list( $handle, $delimiter ) = $opened;
		$header = $handle->fgetcsv();
		if ( ! is_array( $header ) || count( $header ) < 2 ) {
			
			self::redirect( array( 'tc_error' => 'header' ) );
		}
		$header = array_map( array( __CLASS__, 'clean_header' ), $header );

		$preview = array();
		while ( count( $preview ) < 8 && ! $handle->eof() ) {
			$row = $handle->fgetcsv();
			if ( false === $row || array( null ) === $row ) {
				continue;
			}
			if ( ! array_filter( $row, static function ( $value ) { return '' !== trim( (string) $value ); } ) ) {
				continue;
			}
			$preview[] = $row;
		}
		

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
		$value = preg_replace( '/[\\x{00A0}\\s]+/u', '', $value );
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

	private static function mapping_from_request( $header, $posted ) {
		$posted = is_array( $posted ) ? $posted : array();
		$map    = array();
		foreach ( self::fields() as $field => $definition ) {
			$index = isset( $posted[ $field ] ) && is_scalar( $posted[ $field ] ) ? intval( $posted[ $field ] ) : -1;
			if ( $index >= 0 && isset( $header[ $index ] ) ) {
				$map[ $field ] = $index;
			}
		}
		return $map;
	}

	private static function save_mapping_template( $name, $provider, $mapping, $header ) {
		$name = sanitize_text_field( (string) $name );
		$provider = sanitize_text_field( (string) $provider );
		if ( '' === trim( $name ) ) {
			return false;
		}

		$stored_mapping = array();
		foreach ( (array) $mapping as $field => $index ) {
			$index = intval( $index );
			if ( $index >= 0 && isset( $header[ $index ] ) ) {
				$stored_mapping[ sanitize_key( $field ) ] = (string) $header[ $index ];
			}
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
		return $id;
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
		self::guard();
		check_admin_referer( 'tcsup_import_csv' );

		$state = self::state();
		if ( empty( $state['path'] ) || ! is_readable( $state['path'] ) ) {
			self::redirect( array( 'tc_error' => 'state' ) );
		}

		$templates   = self::templates();
		$template_id = isset( $_POST['template_id'] ) ? sanitize_key( wp_unslash( $_POST['template_id'] ) ) : (string) ( $state['template_id'] ?? 'canonical' );
		$template    = isset( $templates[ $template_id ] ) ? $templates[ $template_id ] : $templates['canonical'];
		$provider    = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		if ( '' === trim( $provider ) ) {
			$provider = sanitize_text_field( (string) ( $state['provider'] ?? '' ) );
		}
		if ( '' === trim( $provider ) ) {
			$provider = sanitize_text_field( (string) ( $template['provider'] ?? '' ) );
		}
		$provider = trim( $provider );
		if ( '' === $provider ) {
			self::redirect( array( 'tc_error' => 'provider' ) );
		}

		$opened = self::open_csv( $state['path'], (string) ( $state['delimiter'] ?? '' ) );
		if ( is_wp_error( $opened ) ) {
			self::redirect( array( 'tc_error' => 'read' ) );
		}
		list( $handle, $delimiter ) = $opened;
		$header = $handle->fgetcsv();
		$header = is_array( $header ) ? array_map( array( __CLASS__, 'clean_header' ), $header ) : array();

		$mode = (string) ( $template['mode'] ?? 'mapping' );
		$posted_mapping = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] )
			? map_deep( wp_unslash( $_POST['mapping'] ), 'sanitize_text_field' )
			: array();
		$mapping = 'vevor' === $mode
			? array()
			: self::mapping_from_request( $header, $posted_mapping );

		if ( 'vevor' === $mode ) {
			$required_headers = array( 'Name', 'URL to product', 'categoryPath', 'URL to image', 'Currency', 'Sales' );
			$missing_headers = array_values( array_diff( $required_headers, $header ) );
			if ( $missing_headers ) {
				
				self::redirect( array( 'tc_error' => 'vevor_headers' ) );
			}
		}

		if ( 'vevor' !== $mode ) {
			if ( 'price_update' === $mode ) {
				$is_vevor_provider = 'VEVOR' === strtoupper( $provider );
				$has_identity = $is_vevor_provider
					? isset( $mapping['proveedor_id_externo'] )
					: ( isset( $mapping['proveedor_id_externo'] ) || isset( $mapping['sku'] ) );
				$has_price = isset( $mapping['precio_sin_iva'] ) || isset( $mapping['precio_con_iva'] );
				$vevor_country_ok = ! $is_vevor_provider || isset( $mapping['pais_origen'] );
				if ( ! $has_identity || ! $has_price || ! $vevor_country_ok ) {
					
					self::redirect( array( 'tc_error' => 'price_mapping' ) );
				}
			} else {
				foreach ( self::fields() as $field => $definition ) {
					if ( ! empty( $definition['required'] ) && ! isset( $mapping[ $field ] ) ) {
						
						self::redirect( array( 'tc_error' => 'required_mapping' ) );
					}
				}
			}

			$template_name_to_save = isset( $_POST['save_template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['save_template_name'] ) ) : '';
			if ( '' !== trim( $template_name_to_save ) ) {
				self::save_mapping_template( $template_name_to_save, $provider, $mapping, $header );
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

		while ( ! $handle->eof() ) {
			$row = $handle->fgetcsv();
			if ( false === $row || array( null ) === $row ) {
				continue;
			}
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
					if ( ! isset( $mapping[ $field ] ) || ! array_key_exists( $mapping[ $field ], $row ) ) {
						continue;
					}
					$data[ $field ] = self::sanitize_value( $field, $row[ $mapping[ $field ] ] );
				}
			}

			$external_id = trim( (string) ( $data['proveedor_id_externo'] ?? '' ) );
			$has_external_id = '' !== $external_id;
			$sku         = trim( (string) ( $data['sku'] ?? '' ) );
			$name        = trim( (string) ( $data['nombre'] ?? '' ) );
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $name, 'UTF-8' ) > 255 ) {
				$name = mb_substr( $name, 0, 255, 'UTF-8' );
				$data['nombre'] = $name;
			} elseif ( strlen( $name ) > 255 ) {
				$name = substr( $name, 0, 255 );
				$data['nombre'] = $name;
			}
			if ( '' === $external_id ) {
				$external_id = $sku;
				if ( 'price_update' !== $mode ) {
					$data['proveedor_id_externo'] = $external_id;
					$has_external_id = '' !== $external_id;
				}
			}
			if ( '' === $external_id || ( 'price_update' !== $mode && '' === $name ) ) {
				$errors++;
				continue;
			}

			$country = strtoupper( trim( (string) ( $data['pais_origen'] ?? '' ) ) );
			if ( 'price_update' === $mode && 'VEVOR' === strtoupper( $provider ) && '' === $country ) {
				$errors++;
				continue;
			}
			$is_vevor = 'VEVOR' === strtoupper( $provider ) && '' !== $country;
			$row_key = trim( (string) ( $data['source_row_key'] ?? '' ) );
			if ( 'VEVOR' === strtoupper( $provider ) && '' === $row_key && 'price_update' !== $mode ) {
				$base = hash( 'sha256', wp_json_encode( array( $country, $sku, $name, $external_id ) ) );
				$occurrence[ $base ] = isset( $occurrence[ $base ] ) ? $occurrence[ $base ] + 1 : 1;
				$row_key = hash( 'sha256', $base . ':' . $occurrence[ $base ] );
				$data['source_row_key'] = $row_key;
			}

			$data['proveedor'] = $provider;
			if ( array_key_exists( 'pais_origen', $data ) || $is_vevor ) {
				$data['pais_origen'] = $country;
			}
			if ( array_key_exists( 'moneda', $data ) && '' === (string) $data['moneda'] ) {
				$data['moneda'] = 'EUR';
			}
			$data['raw_json']           = wp_json_encode( $assoc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$data['ultima_importacion'] = $now;
			$data['actualizado']        = $now;
			if ( 'price_update' !== $mode ) {
				$data['hash_producto'] = self::row_hash( $data );
			}

			$existing = null;

			if ( $is_vevor ) {
				if ( 'price_update' === $mode && '' === $row_key ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the current supplier inventory/schema for this admin operation; stale cache would misreport changes.
					$price_matches = $wpdb->get_results(
						$wpdb->prepare(
							"SELECT * FROM %i
							 WHERE proveedor=%s AND pais_origen=%s AND proveedor_id_externo=%s
							 ORDER BY id ASC LIMIT 2",
							$table,
							$provider,
							$country,
							$external_id
						),
						ARRAY_A
					);
					if ( 1 === count( (array) $price_matches ) ) {
						$existing = $price_matches[0];
					} else {
						$errors++;
						continue;
					}
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the current supplier inventory/schema for this admin operation; stale cache would misreport changes.
					$existing = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT * FROM %i WHERE proveedor=%s AND pais_origen=%s AND proveedor_id_externo=%s AND source_row_key=%s LIMIT 1",
							$table,
							$provider,
							$country,
							$external_id,
							$row_key
						),
						ARRAY_A
					);

					// Bind a single pre-source_row_key legacy row instead of creating
					// a duplicate on the first import through the independent module.
					if ( ! $existing ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the current supplier inventory/schema for this admin operation; stale cache would misreport changes.
						$legacy_rows = $wpdb->get_results(
							$wpdb->prepare(
								"SELECT * FROM %i
								 WHERE proveedor=%s AND pais_origen=%s AND proveedor_id_externo=%s
								   AND sku=%s AND nombre=%s
								   AND (source_row_key='' OR source_row_key IS NULL)
								 ORDER BY id ASC LIMIT 2",
								$table,
								$provider,
								$country,
								$external_id,
								$sku,
								$name
							),
							ARRAY_A
						);
						if ( 1 === count( (array) $legacy_rows ) ) {
							$existing = $legacy_rows[0];
						}
					}
				}
			} else {
				$existing_by_external = null;
				if ( $has_external_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the current supplier inventory/schema for this admin operation; stale cache would misreport changes.
					$existing_by_external = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT * FROM %i WHERE proveedor=%s AND proveedor_id_externo=%s ORDER BY id ASC LIMIT 1",
							$table,
							$provider,
							$external_id
						),
						ARRAY_A
					);
				}
				$existing_by_sku = null;
				if ( '' !== $sku ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the current supplier inventory/schema for this admin operation; stale cache would misreport changes.
					$existing_by_sku = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT * FROM %i WHERE proveedor=%s AND sku=%s ORDER BY id ASC LIMIT 1",
							$table,
							$provider,
							$sku
						),
						ARRAY_A
					);
				}

				if (
					$existing_by_external
					&& $existing_by_sku
					&& absint( $existing_by_external['id'] ?? 0 ) !== absint( $existing_by_sku['id'] ?? 0 )
				) {
					foreach ( array( $existing_by_external, $existing_by_sku ) as $conflict_row ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Synchronous write to Suppliers-owned inventory/lock state; caching writes is not applicable.
						$wpdb->update(
							$table,
							array(
								'estado_seleccion'      => 'revisar',
								'estado_sincronizacion' => 'conflicto',
								'ultimo_error_sync'      => 'Supplier SKU and external ID resolve to different catalogue rows.',
								'actualizado'            => $now,
							),
							array( 'id' => absint( $conflict_row['id'] ) )
						);
					}
					$errors++;
					continue;
				}

				if (
					$existing_by_external
					&& '' !== $sku
					&& '' !== trim( (string) ( $existing_by_external['sku'] ?? '' ) )
					&& $sku !== trim( (string) $existing_by_external['sku'] )
				) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Synchronous write to Suppliers-owned inventory/lock state; caching writes is not applicable.
					$wpdb->update(
						$table,
						array(
							'estado_seleccion'      => 'revisar',
							'estado_sincronizacion' => 'conflicto',
							'ultimo_error_sync'      => 'Supplier external ID arrived with a different SKU.',
							'actualizado'            => $now,
						),
						array( 'id' => absint( $existing_by_external['id'] ) )
					);
					$errors++;
					continue;
				}

				$existing = $existing_by_sku ?: $existing_by_external;
			}

			if ( ! $existing && 'price_update' === $mode ) {
				$errors++;
				continue;
			}

			if ( $existing ) {
				$changed = self::changed_fields( $existing, $data );
				$data['estado_sincronizacion'] = $changed ? 'modificado' : (string) ( $existing['estado_sincronizacion'] ?? 'sin_cambios' );
				$data['cambios_detectados']    = $changed ? implode( ',', $changed ) : '';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Synchronous write to Suppliers-owned inventory/lock state; caching writes is not applicable.
				$result = $wpdb->update( $table, $data, array( 'id' => absint( $existing['id'] ) ) );
				if ( false === $result ) {
					$errors++;
				} elseif ( $changed ) {
					$updated++;
				} else {
					$unchanged++;
				}
			} else {
				if ( ! array_key_exists( 'moneda', $data ) ) {
					$data['moneda'] = 'EUR';
				}
				if ( ! array_key_exists( 'pais_origen', $data ) ) {
					$data['pais_origen'] = '';
				}
				$data['estado_seleccion']      = 'pendiente';
				$data['estado_sincronizacion'] = 'nuevo';
				$data['modo_imagenes']         = 'external';
				$data['primera_importacion']   = $now;
				$data['creado']                = $now;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Synchronous write to Suppliers-owned inventory/lock state; caching writes is not applicable.
				$result = $wpdb->insert( $table, $data );
				if ( false === $result ) {
					$errors++;
				} else {
					$inserted++;
				}
			}
		}
		
		self::clear_state();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'taxocommerce-suppliers',
					'tab'        => 'price_update' === $mode ? 'prices' : 'inventory',
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
		self::guard();
		check_admin_referer( 'tcsup_save_template' );

		$state = self::state();
		$header = (array) ( $state['header'] ?? array() );
		if ( ! $header ) {
			self::redirect( array( 'tc_error' => 'state' ) );
		}

		$name     = isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '';
		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		$posted_mapping = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] )
			? map_deep( wp_unslash( $_POST['mapping'] ), 'sanitize_text_field' )
			: array();
		$mapping  = self::mapping_from_request( $header, $posted_mapping );
		if ( ! self::save_mapping_template( $name, $provider, $mapping, $header ) ) {
			self::redirect( array( 'tc_error' => 'template_name' ) );
		}

		self::redirect( array( 'template_saved' => 1 ) );
	}

	public static function handle_delete_template() {
		self::guard();
		check_admin_referer( 'tcsup_delete_template' );
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
		$csv = "\xEF\xBB\xBF" . implode( ';', array_keys( self::fields() ) ) . "\r\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Download contains fixed internal field keys only.
		echo $csv;
		exit;
	}

}
