<?php
/**
 * Simple field-mapped CSV import/export for native WordPress and WooCommerce.
 *
 * Deliberately separate from advanced TaxoCommerce batch formats.
 *
 * @package TaxoCommerceImportExport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TaxoCommerce_Import_Export_Basic {
	const TRANSIENT_PREFIX = 'tc_ie_basic_';
	const MAX_BYTES = 5242880;
	const STEP = 20;

	public function boot() {
		add_action( 'admin_post_tc_ie_basic_upload', array( $this, 'upload' ) );
		add_action( 'admin_post_tc_ie_basic_export', array( $this, 'export' ) );
		add_action( 'wp_ajax_tc_ie_basic_step', array( $this, 'step' ) );
	}

	private function guard() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para importar o exportar.', 'taxocommerce-import-export' ) );
		}
	}

	private static function types() {
		return array(
			'product' => __( 'Productos WooCommerce', 'taxocommerce-import-export' ),
			'product_cat' => __( 'Categorías de producto', 'taxocommerce-import-export' ),
			'category' => __( 'Categorías de entradas WordPress', 'taxocommerce-import-export' ),
			'page' => __( 'Páginas WordPress', 'taxocommerce-import-export' ),
			'post' => __( 'Entradas WordPress', 'taxocommerce-import-export' ),
		);
	}

	private static function fields( $type ) {
		if ( 'product' === $type ) {
			return array(
				'id' => 'ID', 'sku' => 'SKU', 'name' => __( 'Nombre', 'taxocommerce-import-export' ),
				'slug' => 'Slug', 'status' => __( 'Estado', 'taxocommerce-import-export' ),
				'regular_price' => __( 'Precio normal', 'taxocommerce-import-export' ),
				'sale_price' => __( 'Precio rebajado', 'taxocommerce-import-export' ),
				'stock_quantity' => __( 'Cantidad en stock', 'taxocommerce-import-export' ),
				'description' => __( 'Descripción', 'taxocommerce-import-export' ),
				'short_description' => __( 'Descripción corta', 'taxocommerce-import-export' ),
			);
		}
		if ( in_array( $type, array( 'category', 'product_cat' ), true ) ) {
			return array(
				'id' => 'ID', 'name' => __( 'Nombre', 'taxocommerce-import-export' ),
				'slug' => 'Slug', 'description' => __( 'Descripción', 'taxocommerce-import-export' ),
				'parent_slug' => __( 'Slug categoría padre', 'taxocommerce-import-export' ),
			);
		}
		return array(
			'id' => 'ID', 'title' => __( 'Título', 'taxocommerce-import-export' ),
			'slug' => 'Slug', 'status' => __( 'Estado', 'taxocommerce-import-export' ),
			'excerpt' => __( 'Extracto', 'taxocommerce-import-export' ),
			'content' => __( 'Contenido', 'taxocommerce-import-export' ),
		);
	}

	private function redirect( $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'taxocommerce-import-export', 'tc_ie_tab' => 'individual' ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function directory() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$path = trailingslashit( $uploads['basedir'] ) . 'taxocommerce-import-export/basic';
		if ( ! wp_mkdir_p( $path ) ) {
			return '';
		}
		if ( ! file_exists( $path . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Protect a private, nonpublic CSV work directory.
			file_put_contents( $path . '/index.php', '<?php // Silence is golden.' );
		}
		if ( ! file_exists( $path . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Apache protection; filenames also random and never disclosed publicly.
			file_put_contents( $path . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		return $path;
	}

	private function transient_key( $token ) {
		return self::TRANSIENT_PREFIX . get_current_user_id() . '_' . sanitize_key( $token );
	}

	private function load_state( $token ) {
		$state = get_transient( $this->transient_key( $token ) );
		return is_array( $state ) ? $state : array();
	}

	private function store_state( $token, $state ) {
		set_transient( $this->transient_key( $token ), $state, HOUR_IN_SECONDS );
	}

	private function delimiter( $line ) {
		$counts = array(
			',' => count( str_getcsv( $line, ',' ) ),
			';' => count( str_getcsv( $line, ';' ) ),
			"\t" => count( str_getcsv( $line, "\t" ) ),
		);
		arsort( $counts, SORT_NUMERIC );
		return (string) key( $counts );
	}

	private function csv_row( $handle, $delimiter ) {
		return fgetcsv( $handle, 0, $delimiter, '"', '' );
	}

	public function upload() {
		$this->guard();
		check_admin_referer( 'tc_ie_basic_upload' );
		$file = isset( $_FILES['csv_file'] ) && is_array( $_FILES['csv_file'] ) ? $_FILES['csv_file'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Uploaded file validated below.
		$name = isset( $file['name'] ) && is_scalar( $file['name'] ) ? sanitize_file_name( wp_unslash( (string) $file['name'] ) ) : '';
		$size = absint( $file['size'] ?? 0 );
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) || 0 === $size || $size > self::MAX_BYTES || ! is_uploaded_file( (string) ( $file['tmp_name'] ?? '' ) ) ) {
			$this->redirect( array( 'tc_ie_basic_error' => 'invalid_upload' ) );
		}
		$dir = $this->directory();
		if ( '' === $dir ) {
			$this->redirect( array( 'tc_ie_basic_error' => 'storage' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$uploaded = wp_handle_upload( $file, array( 'test_form' => false ) );
		if ( ! is_array( $uploaded ) || ! empty( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
			$this->redirect( array( 'tc_ie_basic_error' => 'storage' ) );
		}
		$token = strtolower( wp_generate_password( 24, false, false ) );
		$path = $dir . '/' . $token . '.csv';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Move a validated upload to a protected private directory.
		if ( ! rename( $uploaded['file'], $path ) ) {
			wp_delete_file( $uploaded['file'] );
			$this->redirect( array( 'tc_ie_basic_error' => 'storage' ) );
		}
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Stream bounded uploaded CSV.
		if ( ! $handle ) {
			wp_delete_file( $path );
			$this->redirect( array( 'tc_ie_basic_error' => 'csv' ) );
		}
		$first = fgets( $handle );
		rewind( $handle );
		$delimiter = $this->delimiter( (string) $first );
		$headers = $this->csv_row( $handle, $delimiter );
		if ( ! is_array( $headers ) || count( $headers ) > 100 || count( $headers ) < 1 ) {
			fclose( $handle );
			wp_delete_file( $path );
			$this->redirect( array( 'tc_ie_basic_error' => 'csv' ) );
		}
		$headers[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $headers[0] );
		$sample = array();
		for ( $i = 0; $i < 3 && false !== ( $row = $this->csv_row( $handle, $delimiter ) ); $i++ ) {
			if ( is_array( $row ) ) {
				$sample[] = array_slice( $row, 0, count( $headers ) );
			}
		}
		fclose( $handle );
		$this->store_state( $token, array(
			'path' => $path, 'filename' => $name, 'headers' => $headers, 'sample' => $sample,
			'delimiter' => $delimiter, 'offset' => 0, 'processed' => 0, 'saved' => 0,
			'errors' => 0, 'notices' => array(), 'done' => false, 'started' => false,
		) );
		$this->redirect( array( 'tc_ie_basic_token' => $token ) );
	}

	public function render() {
		$this->guard();
		$token = isset( $_GET['tc_ie_basic_token'] ) ? sanitize_key( wp_unslash( $_GET['tc_ie_basic_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view of current user's private transient.
		$state = $token ? $this->load_state( $token ) : array();
		$types = self::types();
		$nonce = wp_create_nonce( 'tc_ie_basic_step' );
		$error = isset( $_GET['tc_ie_basic_error'] ) ? sanitize_key( wp_unslash( $_GET['tc_ie_basic_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only error.
		?>
		<h2><?php esc_html_e( 'Importación y exportación básica de WordPress y WooCommerce', 'taxocommerce-import-export' ); ?></h2>
		<p><?php esc_html_e( 'Utiliza campos nativos sin necesitar columnas ni módulos de TaxoCommerce. Las importaciones avanzadas siguen disponibles en Importación por lotes.', 'taxocommerce-import-export' ); ?></p>
		<?php if ( $error ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'No se ha podido leer o guardar el CSV. Utiliza un archivo CSV válido de hasta 5 MB.', 'taxocommerce-import-export' ); ?></p></div>
		<?php endif; ?>
		<div class="card" style="max-width:none;padding:20px;">
			<h3><?php esc_html_e( 'Exportar CSV seleccionando campos', 'taxocommerce-import-export' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tc_ie_basic_export">
				<?php wp_nonce_field( 'tc_ie_basic_export' ); ?>
				<p><label for="tc-ie-basic-export-type"><?php esc_html_e( 'Tipo de contenido', 'taxocommerce-import-export' ); ?></label>
					<select name="content_type" id="tc-ie-basic-export-type">
						<?php foreach ( $types as $type => $label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select></p>
				<?php foreach ( $types as $type => $label ) : ?>
				<fieldset class="tc-ie-basic-fields" data-type="<?php echo esc_attr( $type ); ?>" style="display:none;margin:12px 0;">
					<legend><strong><?php esc_html_e( 'Campos a exportar', 'taxocommerce-import-export' ); ?></strong></legend>
					<?php foreach ( self::fields( $type ) as $name => $field_label ) : ?>
						<label style="display:inline-block;margin:6px 15px 6px 0;"><input type="checkbox" name="fields[<?php echo esc_attr( $type ); ?>][]" value="<?php echo esc_attr( $name ); ?>" checked> <?php echo esc_html( $field_label ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<?php endforeach; ?>
				<?php submit_button( __( 'Descargar CSV básico', 'taxocommerce-import-export' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<div class="card" style="max-width:none;padding:20px;margin-top:18px;">
			<h3><?php esc_html_e( 'Importar CSV asignando columnas', 'taxocommerce-import-export' ); ?></h3>
			<?php if ( ! $state ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="tc_ie_basic_upload">
					<?php wp_nonce_field( 'tc_ie_basic_upload' ); ?>
					<p><input type="file" name="csv_file" accept=".csv,text/csv" required></p>
					<?php submit_button( __( 'Leer columnas CSV', 'taxocommerce-import-export' ), 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p><strong><?php echo esc_html( $state['filename'] ); ?></strong></p>
				<form id="tc-ie-basic-map-form">
					<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
					<p><label for="tc-ie-basic-map-type"><?php esc_html_e( 'Importar como', 'taxocommerce-import-export' ); ?></label>
						<select name="content_type" id="tc-ie-basic-map-type">
							<?php foreach ( $types as $type => $label ) : ?><option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
						</select></p>
					<p><label for="tc-ie-basic-mode"><?php esc_html_e( 'Acción', 'taxocommerce-import-export' ); ?></label>
						<select name="mode" id="tc-ie-basic-mode">
							<option value="update"><?php esc_html_e( 'Actualizar solo registros existentes', 'taxocommerce-import-export' ); ?></option>
							<option value="create"><?php esc_html_e( 'Actualizar y crear los que falten', 'taxocommerce-import-export' ); ?></option>
						</select></p>
					<p class="description"><?php esc_html_e( 'La identidad se busca por SKU (productos), ID o slug. Los campos sin asignar y las celdas vacías no modifican datos existentes. Las altas de páginas/entradas se crean como borrador salvo que se asigne el estado.', 'taxocommerce-import-export' ); ?></p>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Columna del CSV', 'taxocommerce-import-export' ); ?></th><th><?php esc_html_e( 'Campo de destino', 'taxocommerce-import-export' ); ?></th><th><?php esc_html_e( 'Ejemplo', 'taxocommerce-import-export' ); ?></th></tr></thead><tbody>
					<?php foreach ( $state['headers'] as $i => $header ) : ?>
						<tr><td><?php echo esc_html( $header ); ?></td><td>
							<select class="tc-ie-basic-map" name="mapping[<?php echo esc_attr( (string) $i ); ?>]" data-header="<?php echo esc_attr( sanitize_key( $header ) ); ?>">
								<option value=""><?php esc_html_e( 'No importar', 'taxocommerce-import-export' ); ?></option>
							</select>
						</td><td><?php echo esc_html( (string) ( $state['sample'][0][ $i ] ?? '' ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
					<p><?php submit_button( __( 'Iniciar importación', 'taxocommerce-import-export' ), 'primary', 'submit', false ); ?></p>
				</form>
				<p id="tc-ie-basic-progress" role="status" aria-live="polite"></p>
				<pre id="tc-ie-basic-errors" style="white-space:pre-wrap;max-height:160px;overflow:auto;"></pre>
			<?php endif; ?>
		</div>
		<script>
		(function(){
			var exportSelect=document.getElementById('tc-ie-basic-export-type');
			if(exportSelect){var showExport=function(){document.querySelectorAll('.tc-ie-basic-fields').forEach(function(x){x.style.display=x.dataset.type===exportSelect.value?'block':'none';});};exportSelect.addEventListener('change',showExport);showExport();}
			var form=document.getElementById('tc-ie-basic-map-form');
			if(!form)return;
			var typeSelect=document.getElementById('tc-ie-basic-map-type');
			var fields=<?php echo wp_json_encode( array_map( array( __CLASS__, 'fields' ), array_keys( $types ) ) ); ?>;
			var keys=<?php echo wp_json_encode( array_keys( $types ) ); ?>;
			var byType={};keys.forEach(function(k,i){byType[k]=fields[i];});
			var alias={product:{product_id:'id',product_name:'name',nombre:'name',precio:'regular_price',precio_normal:'regular_price',precio_rebajado:'sale_price'},page:{post_id:'id',post_title:'title',post_content:'content',titulo:'title'},post:{post_id:'id',post_title:'title',post_content:'content',titulo:'title'},category:{nombre:'name'},product_cat:{nombre:'name'}};
			function updateFields(){
				var type=typeSelect.value, available=byType[type]||{};
				document.querySelectorAll('.tc-ie-basic-map').forEach(function(el){
					var current=el.value, suggested=alias[type]&&alias[type][el.dataset.header]||el.dataset.header;
					el.replaceChildren();
					var blank=document.createElement('option');blank.value='';blank.textContent='<?php echo esc_js( __( 'No importar', 'taxocommerce-import-export' ) ); ?>';el.appendChild(blank);
					Object.keys(available).forEach(function(key){var op=document.createElement('option');op.value=key;op.textContent=available[key];el.appendChild(op);});
					el.value=Object.prototype.hasOwnProperty.call(available,current)?current:(Object.prototype.hasOwnProperty.call(available,suggested)?suggested:'');
				});
			}
			typeSelect.addEventListener('change',updateFields);updateFields();
			var progress=document.getElementById('tc-ie-basic-progress'), errors=document.getElementById('tc-ie-basic-errors');
			var endpoint='<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', nonce='<?php echo esc_js( $nonce ); ?>';
			form.addEventListener('submit',function(event){
				event.preventDefault();
				var payload=new FormData(form), used={},duplicate=false;
				form.querySelectorAll('.tc-ie-basic-map').forEach(function(sel){if(sel.value){if(used[sel.value])duplicate=true;used[sel.value]=true;}});
				if(duplicate){progress.textContent='<?php echo esc_js( __( 'Dos columnas apuntan al mismo campo. Corrige el mapeo.', 'taxocommerce-import-export' ) ); ?>';return;}
				form.querySelectorAll('button,input,select').forEach(function(el){el.disabled=true;});
				payload.append('action','tc_ie_basic_step');payload.append('nonce',nonce);
				progress.textContent='<?php echo esc_js( __( 'Importando por bloques…', 'taxocommerce-import-export' ) ); ?>';
				function send(){
					fetch(endpoint,{method:'POST',body:payload,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(o){
						if(!o.success)throw new Error(o.data&&o.data.message||'Error de importación');
						var s=o.data;progress.textContent=s.processed+' filas · '+s.saved+' guardadas · '+s.errors+' errores';
						errors.textContent=(s.notices||[]).join('\n');
						if(!s.done){payload=new FormData();payload.append('action','tc_ie_basic_step');payload.append('nonce',nonce);payload.append('token',form.querySelector('[name="token"]').value);send();}
						else{progress.textContent+=' · <?php echo esc_js( __( 'Importación finalizada', 'taxocommerce-import-export' ) ); ?>';}
					}).catch(function(e){progress.textContent=e.message;form.querySelectorAll('button,input,select').forEach(function(el){el.disabled=false;});});
				}send();
			});
		})();
		</script>
		<?php
	}

	private function safe_csv( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+@\t\r]/u', $value ) ? "'" . $value : $value;
	}

	private function export_value( $type, $id, $field ) {
		if ( 'product' === $type ) {
			$p = wc_get_product( $id );
			if ( ! $p ) return '';
			$methods = array( 'id' => 'get_id', 'sku' => 'get_sku', 'name' => 'get_name', 'slug' => 'get_slug',
				'status' => 'get_status', 'regular_price' => 'get_regular_price', 'sale_price' => 'get_sale_price',
				'stock_quantity' => 'get_stock_quantity', 'description' => 'get_description',
				'short_description' => 'get_short_description' );
			$method = $methods[ $field ] ?? '';
			return $method ? (string) $p->$method() : '';
		}
		if ( in_array( $type, array( 'category', 'product_cat' ), true ) ) {
			$t = get_term( $id, $type );
			if ( ! $t || is_wp_error( $t ) ) return '';
			if ( 'parent_slug' === $field ) {
				$parent = $t->parent ? get_term( $t->parent, $type ) : null;
				return $parent && ! is_wp_error( $parent ) ? $parent->slug : '';
			}
			return (string) ( $t->$field ?? '' );
		}
		$p = get_post( $id );
		$key = array( 'id' => 'ID', 'title' => 'post_title', 'slug' => 'post_name',
			'status' => 'post_status', 'excerpt' => 'post_excerpt', 'content' => 'post_content' )[ $field ] ?? '';
		return $p && $key ? (string) $p->$key : '';
	}

	public function export() {
		$this->guard();
		check_admin_referer( 'tc_ie_basic_export' );
		$type = sanitize_key( wp_unslash( $_POST['content_type'] ?? '' ) );
		$allowed = self::fields( $type );
		if ( ! isset( self::types()[ $type ] ) || ! $allowed ) {
			wp_die( esc_html__( 'Tipo no válido.', 'taxocommerce-import-export' ) );
		}
		$requested = isset( $_POST['fields'][ $type ] ) ? (array) wp_unslash( $_POST['fields'][ $type ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Whitelist against fields.
		$selected = array_values( array_intersect( array_keys( $allowed ), array_map( 'sanitize_key', $requested ) ) );
		if ( ! $selected ) {
			wp_die( esc_html__( 'Selecciona al menos un campo.', 'taxocommerce-import-export' ) );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wordpress-' . $type . '-' . gmdate( 'Ymd' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- CSV stream to authorised browser.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for spreadsheet clients.
		fputcsv( $out, $selected, ',', '"', '' );
		$page = 1;
		do {
			if ( in_array( $type, array( 'category', 'product_cat' ), true ) ) {
				$ids = get_terms( array( 'taxonomy' => $type, 'hide_empty' => false,
					'fields' => 'ids', 'number' => 200, 'offset' => ( $page - 1 ) * 200 ) );
			} else {
				$post_type = 'product' === $type ? 'product' : $type;
				$q = new WP_Query( array( 'post_type' => $post_type, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => 200, 'paged' => $page, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
					'no_found_rows' => true ) );
				$ids = $q->posts;
			}
			if ( is_wp_error( $ids ) ) break;
			foreach ( $ids as $id ) {
				$row = array();
				foreach ( $selected as $field ) $row[] = $this->safe_csv( $this->export_value( $type, $id, $field ) );
				fputcsv( $out, $row, ',', '"', '' );
			}
			$page++;
		} while ( count( $ids ) === 200 && $page < 1001 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- End CSV stream.
		exit;
	}

	private function mapped_row( $headers, $row, $map ) {
		$data = array();
		foreach ( $map as $i => $field ) {
			if ( '' === $field || ! isset( $headers[ $i ] ) ) continue;
			$value = isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : '';
			if ( '' !== $value ) $data[ $field ] = $value;
		}
		return $data;
	}

	private function import_record( $type, $data, $mode ) {
		if ( ! $data ) return new WP_Error( 'empty', __( 'Fila sin campos con valores.', 'taxocommerce-import-export' ) );
		if ( in_array( $type, array( 'category', 'product_cat' ), true ) ) {
			// Portable slug takes precedence over source-site numeric IDs.
			$term = ! empty( $data['slug'] ) ? get_term_by( 'slug', sanitize_title( $data['slug'] ), $type ) : null;
			if ( ! $term && empty( $data['slug'] ) && isset( $data['id'] ) ) $term = get_term( absint( $data['id'] ), $type );
			if ( is_wp_error( $term ) ) $term = null;
			if ( ! $term && 'update' === $mode ) return new WP_Error( 'not_found', __( 'Categoría no encontrada.', 'taxocommerce-import-export' ) );
			$args = array();
			if ( isset( $data['slug'] ) ) $args['slug'] = sanitize_title( $data['slug'] );
			if ( isset( $data['description'] ) ) $args['description'] = wp_kses_post( $data['description'] );
			if ( isset( $data['parent_slug'] ) ) {
				$parent = get_term_by( 'slug', sanitize_title( $data['parent_slug'] ), $type );
				if ( ! $parent ) return new WP_Error( 'parent_missing', __( 'La categoría padre no existe.', 'taxocommerce-import-export' ) );
				$args['parent'] = (int) $parent->term_id;
			}
			if ( $term ) {
				if ( isset( $data['name'] ) ) $args['name'] = sanitize_text_field( $data['name'] );
				return $args ? ! is_wp_error( wp_update_term( $term->term_id, $type, $args ) ) : true;
			}
			if ( empty( $data['name'] ) ) return new WP_Error( 'missing_name', __( 'Falta el nombre de la categoría.', 'taxocommerce-import-export' ) );
			return ! is_wp_error( wp_insert_term( sanitize_text_field( $data['name'] ), $type, $args ) );
		}
		if ( 'product' === $type ) {
			if ( ! function_exists( 'wc_get_product' ) ) return new WP_Error( 'woocommerce_missing', 'WooCommerce unavailable' );
			$id = 0;
			if ( ! empty( $data['sku'] ) ) $id = wc_get_product_id_by_sku( sanitize_text_field( $data['sku'] ) );
			if ( ! $id && ! empty( $data['slug'] ) ) {
				$found = get_page_by_path( sanitize_title( $data['slug'] ), OBJECT, 'product' );
				if ( $found ) $id = $found->ID;
			}
			if ( ! $id && empty( $data['sku'] ) && empty( $data['slug'] ) && isset( $data['id'] ) && 'product' === get_post_type( absint( $data['id'] ) ) ) $id = absint( $data['id'] );
			if ( ! $id && 'update' === $mode ) return new WP_Error( 'not_found', __( 'Producto no encontrado.', 'taxocommerce-import-export' ) );
			if ( ! $id && empty( $data['name'] ) ) return new WP_Error( 'missing_name', __( 'Falta el nombre del producto.', 'taxocommerce-import-export' ) );
			$p = $id ? wc_get_product( $id ) : new WC_Product_Simple();
			if ( ! $id && empty( $data['status'] ) ) $p->set_status( 'draft' );
			if ( ! $p ) return new WP_Error( 'invalid_product', __( 'Producto no válido.', 'taxocommerce-import-export' ) );
			$methods = array( 'sku' => 'set_sku', 'name' => 'set_name', 'slug' => 'set_slug',
				'status' => 'set_status', 'description' => 'set_description', 'short_description' => 'set_short_description',
				'regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price' );
			try {
				foreach ( $methods as $field => $method ) {
					if ( ! isset( $data[ $field ] ) ) continue;
					$value = $data[ $field ];
					if ( in_array( $field, array( 'description', 'short_description' ), true ) ) $value = wp_kses_post( $value );
					elseif ( in_array( $field, array( 'regular_price', 'sale_price' ), true ) ) {
						if ( ! is_numeric( $value ) || (float) $value < 0 ) return new WP_Error( 'invalid_price', __( 'Precio no válido.', 'taxocommerce-import-export' ) );
						$value = wc_format_decimal( $value );
					} else $value = sanitize_text_field( $value );
					$p->$method( $value );
				}
				if ( isset( $data['stock_quantity'] ) ) {
					if ( ! ctype_digit( $data['stock_quantity'] ) ) return new WP_Error( 'invalid_stock', __( 'Stock no válido.', 'taxocommerce-import-export' ) );
					$p->set_manage_stock( true );
					$p->set_stock_quantity( (int) $data['stock_quantity'] );
				}
				$p->save();
			} catch ( Exception $e ) {
				return new WP_Error( 'product_save', $e->getMessage() );
			}
			return true;
		}
		$id = 0;
		if ( ! empty( $data['slug'] ) ) {
			$p = get_page_by_path( sanitize_title( $data['slug'] ), OBJECT, $type );
			if ( $p ) $id = $p->ID;
		}
		if ( ! $id && empty( $data['slug'] ) && isset( $data['id'] ) && $type === get_post_type( absint( $data['id'] ) ) ) $id = absint( $data['id'] );
		if ( ! $id && 'update' === $mode ) return new WP_Error( 'not_found', __( 'Página o entrada no encontrada.', 'taxocommerce-import-export' ) );
		if ( ! $id && empty( $data['title'] ) ) return new WP_Error( 'missing_title', __( 'Falta el título para crear el contenido.', 'taxocommerce-import-export' ) );
		$fields = array( 'title' => 'post_title', 'slug' => 'post_name', 'status' => 'post_status',
			'excerpt' => 'post_excerpt', 'content' => 'post_content' );
		$args = array( 'post_type' => $type );
		if ( $id ) $args['ID'] = $id;
		foreach ( $fields as $key => $target ) {
			if ( ! isset( $data[ $key ] ) ) continue;
			$args[ $target ] = in_array( $key, array( 'content', 'excerpt' ), true ) ? wp_kses_post( $data[ $key ] ) : sanitize_text_field( $data[ $key ] );
		}
		if ( ! $id && empty( $args['post_status'] ) ) $args['post_status'] = 'draft';
		$result = $id ? wp_update_post( $args, true ) : wp_insert_post( $args, true );
		return ! is_wp_error( $result ) && (int) $result > 0;
	}

	public function step() {
		$this->guard();
		check_ajax_referer( 'tc_ie_basic_step', 'nonce' );
		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$state = $this->load_state( $token );
		if ( ! $state || empty( $state['path'] ) ) wp_send_json_error( array( 'message' => __( 'El CSV ha caducado. Vuelve a subirlo.', 'taxocommerce-import-export' ) ) );
		if ( empty( $state['started'] ) ) {
			$type = sanitize_key( wp_unslash( $_POST['content_type'] ?? '' ) );
			$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? 'update' ) );
			if ( ! isset( self::types()[ $type ] ) || ! in_array( $mode, array( 'update', 'create' ), true ) ) wp_send_json_error( array( 'message' => 'Invalid type or mode' ) );
			$raw_map = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value sanitized and whitelisted below.
			$allowed = self::fields( $type );
			$map = array();
			foreach ( $raw_map as $i => $name ) {
				$name = is_scalar( $name ) ? sanitize_key( (string) $name ) : '';
				if ( '' !== $name && isset( $allowed[ $name ] ) && is_numeric( $i ) && isset( $state['headers'][ (int) $i ] ) ) $map[ (int) $i ] = $name;
			}
			if ( ! $map || count( $map ) !== count( array_unique( array_values( $map ) ) ) ) wp_send_json_error( array( 'message' => __( 'Mapeo vacío o repetido.', 'taxocommerce-import-export' ) ) );
			$identity = 'product' === $type ? array( 'sku', 'slug', 'id' ) : array( 'slug', 'id' );
			if ( 'update' === $mode && ! array_intersect( $identity, array_values( $map ) ) ) {
				wp_send_json_error( array( 'message' => __( 'Para actualizar, relaciona una columna de identidad (SKU, slug o ID).', 'taxocommerce-import-export' ) ) );
			}
			$state['type'] = $type; $state['mode'] = $mode; $state['map'] = $map; $state['started'] = true;
			$state['offset'] = 0;
		}
		if ( ! empty( $state['done'] ) ) {
			wp_send_json_success( array( 'processed' => $state['processed'], 'saved' => $state['saved'], 'errors' => $state['errors'], 'notices' => $state['notices'], 'done' => true ) );
		}
		$handle = fopen( $state['path'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read private, size-limited CSV in bounded increments.
		if ( ! $handle ) wp_send_json_error( array( 'message' => __( 'No se puede abrir el CSV.', 'taxocommerce-import-export' ) ) );
		if ( ! $state['offset'] ) {
			$this->csv_row( $handle, $state['delimiter'] );
		} else fseek( $handle, $state['offset'] );
		$count = 0;
		while ( $count < self::STEP && false !== ( $row = $this->csv_row( $handle, $state['delimiter'] ) ) ) {
			$count++; $state['processed']++;
			if ( ! is_array( $row ) || count( $row ) !== count( $state['headers'] ) ) {
				$result = new WP_Error( 'bad_columns', __( 'Número de columnas incorrecto.', 'taxocommerce-import-export' ) );
			} else {
				$result = $this->import_record( $state['type'], $this->mapped_row( $state['headers'], $row, $state['map'] ), $state['mode'] );
			}
			if ( true === $result ) $state['saved']++;
			else {
				$state['errors']++;
				$detail = is_wp_error( $result ) ? $result->get_error_message() : __( 'No se ha guardado la fila.', 'taxocommerce-import-export' );
				$state['notices'][] = sprintf( '%d: %s', $state['processed'] + 1, $detail );
				$state['notices'] = array_slice( $state['notices'], -12 );
			}
		}
		$state['offset'] = ftell( $handle );
		$state['done'] = feof( $handle );
		fclose( $handle );
		if ( $state['done'] ) wp_delete_file( $state['path'] );
		// Return only public progress data, not internal storage paths.
		$this->store_state( $token, $state );
		wp_send_json_success( array(
			'processed' => $state['processed'], 'saved' => $state['saved'],
			'errors' => $state['errors'], 'notices' => $state['notices'], 'done' => $state['done'],
		) );
	}
}
