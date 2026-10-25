<?php
/**
 * TaxoCommerce Suppliers administration.
 *
 * @package TaxoCommerceSuppliers
 */

defined( 'ABSPATH' ) || exit;

final class TCSUP_Admin {

	const PAGE = 'taxocommerce-suppliers';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 70 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_action( 'admin_post_tcsup_analyze_csv', array( 'TCSUP_CSV', 'handle_analyze' ) );
		add_action( 'admin_post_tcsup_import_csv', array( 'TCSUP_CSV', 'handle_import' ) );
		add_action( 'admin_post_tcsup_save_template', array( 'TCSUP_CSV', 'handle_save_template' ) );
		add_action( 'admin_post_tcsup_delete_template', array( 'TCSUP_CSV', 'handle_delete_template' ) );
		add_action( 'admin_post_tcsup_download_template', array( 'TCSUP_CSV', 'handle_download_template' ) );

		add_action( 'admin_post_tcsup_row_action', array( 'TCSUP_Products', 'handle_row_action' ) );
		add_action( 'admin_post_tcsup_bulk_action', array( 'TCSUP_Products', 'handle_bulk_action' ) );
		add_action( 'admin_post_tcsup_price_update', array( 'TCSUP_Products', 'handle_price_update' ) );
		add_action( 'admin_post_tcsup_images_update', array( 'TCSUP_Products', 'handle_images_update' ) );
		add_action( 'admin_post_tcsup_settings', array( __CLASS__, 'handle_settings' ) );
	}

	public static function capability() {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	public static function menu() {
		$capability = self::capability();
		if ( function_exists( 'taxocommerce_core' ) ) {
			add_submenu_page(
				'taxocommerce-core',
				'TaxoCommerce Suppliers',
				'Suppliers',
				$capability,
				self::PAGE,
				array( __CLASS__, 'render' )
			);
		} else {
			add_menu_page(
				'TaxoCommerce Suppliers',
				'TaxoCommerce Suppliers',
				$capability,
				self::PAGE,
				array( __CLASS__, 'render' ),
				'dashicons-database-import',
				59
			);
		}
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style(
			'taxocommerce-suppliers-admin',
			TAXOCOMMERCE_SUPPLIERS_URL . 'assets/admin.css',
			array(),
			TAXOCOMMERCE_SUPPLIERS_VERSION
		);
	}

	private static function guard() {
		if ( ! current_user_can( self::capability() ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage TaxoCommerce Suppliers.', 'taxocommerce-suppliers' ) );
		}
	}

	private static function tab_url( $tab, $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ),
			admin_url( 'admin.php' )
		);
	}

	private static function active_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import';
		return in_array( $tab, array( 'import', 'templates', 'inventory', 'prices', 'images' ), true ) ? $tab : 'import';
	}

	public static function render() {
		self::guard();
		TCSUP_Schema::maybe_install();

		$tab = self::active_tab();
		?>
		<div class="wrap tcsup-wrap">
			<header class="tcsup-header">
				<div>
					<p class="tcsup-eyebrow">TAXOCOMMERCE SUPPLIERS</p>
					<h1>Supplier Catalogue</h1>
					<p>CSV-only supplier intake, intermediate product decisions, price control, and external image references.</p>
				</div>
			</header>
			<nav class="nav-tab-wrapper tcsup-tabs">
				<?php
				$tabs = array(
					'import'    => 'CSV Import',
					'templates' => 'CSV Templates',
					'inventory' => 'Inventory',
					'prices'    => 'Prices',
					'images'    => 'External Images',
				);
				foreach ( $tabs as $key => $label ) {
					printf(
						'<a class="nav-tab %1$s" href="%2$s">%3$s</a>',
						$key === $tab ? 'nav-tab-active' : '',
						esc_url( self::tab_url( $key ) ),
						esc_html( $label )
					);
				}
				?>
			</nav>
			<?php self::notices(); ?>
			<?php
			switch ( $tab ) {
				case 'templates':
					self::render_templates();
					break;
				case 'inventory':
					self::render_inventory();
					break;
				case 'prices':
					self::render_prices();
					break;
				case 'images':
					self::render_images();
					break;
				default:
					self::render_import();
			}
			?>
		</div>
		<?php
	}

	private static function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Redirect notices only.
		if ( isset( $_GET['tc_error_message'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['tc_error_message'] ) ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['tc_error'] ) ) {
			$codes = array(
				'upload'           => 'The CSV upload failed.',
				'csv_only'         => 'Only CSV files are accepted. XLS/XLSX and web scraping are intentionally excluded.',
				'too_large'        => 'The CSV exceeds the WordPress upload limit.',
				'storage'          => 'The supplier import storage directory is not writable.',
				'read'             => 'The CSV could not be read.',
				'header'           => 'The CSV header is not valid.',
				'state'            => 'The analyzed CSV session expired. Upload the file again.',
				'provider'         => 'A supplier name is required.',
				'required_mapping' => 'Supplier product ID and product name must be mapped before importing.',
				'vevor_headers'     => 'The VEVOR CSV is missing one or more required official feed columns.',
				'price_mapping'    => 'Price updates require an identity column and at least one price column. VEVOR also requires market country and external ID.',
				'template_name'    => 'A template name is required.',
				'missing'          => 'The supplier catalogue row no longer exists.',
			);
			$code = sanitize_key( wp_unslash( $_GET['tc_error'] ) );
			if ( isset( $codes[ $code ] ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $codes[ $code ] ) . '</p></div>';
			}
		}
		if ( isset( $_GET['imported'] ) ) {
			printf(
				'<div class="notice notice-success"><p>CSV processed: %1$d rows; %2$d new, %3$d updated, %4$d unchanged, %5$d errors.</p></div>',
				absint( $_GET['processed'] ?? 0 ),
				absint( $_GET['new'] ?? 0 ),
				absint( $_GET['updated'] ?? 0 ),
				absint( $_GET['unchanged'] ?? 0 ),
				absint( $_GET['errors'] ?? 0 )
			);
		}
		if ( isset( $_GET['bulk_done'] ) ) {
			printf(
				'<div class="notice notice-success"><p>%1$d supplier rows processed. %2$d errors.</p></div>',
				absint( $_GET['bulk_done'] ),
				absint( $_GET['bulk_errors'] ?? 0 )
			);
		}
		if ( isset( $_GET['template_saved'] ) ) {
			echo '<div class="notice notice-success"><p>CSV mapping template saved.</p></div>';
		}
		if ( isset( $_GET['price_saved'] ) ) {
			echo '<div class="notice notice-success"><p>Price data saved.</p></div>';
		}
		if ( isset( $_GET['images_saved'] ) ) {
			echo '<div class="notice notice-success"><p>External image references saved.</p></div>';
		}
		// phpcs:enable
	}

	private static function render_import() {
		$templates = TCSUP_CSV::templates();
		$state     = TCSUP_CSV::state();
		$selected  = (string) ( $state['template_id'] ?? 'canonical' );
		?>
		<section class="tcsup-card">
			<h2>1. Upload supplier CSV</h2>
			<p>Only supplier-provided CSV files are processed. This plugin contains no scraper, crawler, remote runner, or automatic web extraction.</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tcsup_analyze_csv">
				<?php wp_nonce_field( 'tcsup_analyze_csv' ); ?>
				<div class="tcsup-form-grid">
					<label><strong>CSV template</strong>
						<select name="template_id">
							<?php foreach ( $templates as $id => $template ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $selected, $id ); ?>><?php echo esc_html( $template['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><strong>Supplier</strong>
						<input type="text" name="provider" value="<?php echo esc_attr( (string) ( $state['provider'] ?? '' ) ); ?>" placeholder="e.g. SATKIT">
					</label>
					<label><strong>CSV file</strong>
						<input type="file" name="supplier_csv" accept=".csv,text/csv" required>
					</label>
				</div>
				<?php submit_button( 'Analyze CSV', 'primary', 'submit', false ); ?>
			</form>
		</section>
		<?php
		if ( ! empty( $state['header'] ) ) {
			self::render_mapping( $state, $templates );
		}
	}

	private static function template_for_state( $state, $templates ) {
		$id = sanitize_key( (string) ( $state['template_id'] ?? 'canonical' ) );
		return isset( $templates[ $id ] ) ? $templates[ $id ] : $templates['canonical'];
	}

	private static function suggested_index( $field, $header, $template ) {
		$mode = (string) ( $template['mode'] ?? 'mapping' );
		if ( 'canonical' === $mode ) {
			foreach ( $header as $index => $column ) {
				if ( sanitize_key( $column ) === $field ) {
					return $index;
				}
			}
		}
		$source = (string) ( $template['mapping'][ $field ] ?? '' );
		if ( $source ) {
			foreach ( $header as $index => $column ) {
				if ( $column === $source || sanitize_key( $column ) === sanitize_key( $source ) ) {
					return $index;
				}
			}
		}
		foreach ( $header as $index => $column ) {
			if ( TCSUP_CSV::suggest_field_for_header( $column ) === $field ) {
				return $index;
			}
		}
		return -1;
	}

	private static function render_mapping( $state, $templates ) {
		$template = self::template_for_state( $state, $templates );
		$mode     = (string) ( $template['mode'] ?? 'mapping' );
		$header   = (array) $state['header'];
		?>
		<section class="tcsup-card">
			<h2>2. Review normalization</h2>
			<p><strong>File:</strong> <code><?php echo esc_html( (string) $state['filename'] ); ?></code> · <strong>Delimiter:</strong> <code><?php echo "	" === $state['delimiter'] ? 'TAB' : esc_html( $state['delimiter'] ); ?></code></p>
			<?php if ( 'vevor' === $mode ) : ?>
				<div class="notice notice-info inline"><p>The VEVOR preset maps the official feed locally: product URL → external ID/SKU/market, Name → name, categoryPath → supplier category, Sales → price, Currency → currency, URL to image → external image. No network request is made.</p></div>
			<?php elseif ( 'price_update' === $mode ) : ?>
				<div class="notice notice-info inline"><p>Price update mode never creates supplier catalogue rows. Map supplier external ID or SKU plus at least one price column. For VEVOR, map external ID and market country so regional products cannot be mixed.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tcsup_import_csv">
				<input type="hidden" name="template_id" value="<?php echo esc_attr( (string) $state['template_id'] ); ?>">
				<?php wp_nonce_field( 'tcsup_import_csv' ); ?>
				<p><label><strong>Supplier</strong> <input type="text" name="provider" value="<?php echo esc_attr( (string) ( $state['provider'] ?? $template['provider'] ?? '' ) ); ?>" required></label></p>
				<?php if ( 'vevor' !== $mode ) : ?>
					<table class="widefat striped tcsup-map-table">
						<thead><tr><th>TaxoCommerce field</th><th>CSV column</th><th>Required</th></tr></thead>
						<tbody>
						<?php foreach ( TCSUP_CSV::fields() as $field => $definition ) : ?>
							<?php $suggested = self::suggested_index( $field, $header, $template ); ?>
							<tr>
								<td><code><?php echo esc_html( $field ); ?></code><br><?php echo esc_html( $definition['label'] ); ?></td>
								<td>
									<select name="mapping[<?php echo esc_attr( $field ); ?>]">
										<option value="-1">— Do not import —</option>
										<?php foreach ( $header as $index => $column ) : ?>
											<option value="<?php echo esc_attr( $index ); ?>" <?php selected( $suggested, $index ); ?>><?php echo esc_html( $column ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<?php
									if ( 'price_update' === $mode ) {
										if ( in_array( $field, array( 'proveedor_id_externo', 'sku' ), true ) ) {
											echo esc_html( 'Identity' );
										} elseif ( in_array( $field, array( 'precio_sin_iva', 'precio_con_iva' ), true ) ) {
											echo esc_html( 'At least one price' );
										} elseif ( 'pais_origen' === $field ) {
											echo esc_html( 'Required for VEVOR' );
										} else {
											echo esc_html( 'No' );
										}
									} else {
										echo esc_html( ! empty( $definition['required'] ) ? 'Yes' : 'No' );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php if ( 'vevor' !== $mode ) : ?>
					<details class="tcsup-template-save">
						<summary>Save this mapping as a reusable template</summary>
						<p><input type="text" name="save_template_name" placeholder="Optional template name"></p>
					</details>
				<?php endif; ?>
				<p class="submit"><?php submit_button( 'Import into intermediate inventory', 'primary', 'submit', false ); ?></p>
			</form>

			<h3>Preview</h3>
			<div class="tcsup-scroll"><table class="widefat striped"><thead><tr>
				<?php foreach ( $header as $column ) : ?><th><?php echo esc_html( $column ); ?></th><?php endforeach; ?>
			</tr></thead><tbody>
				<?php foreach ( (array) $state['preview'] as $row ) : ?><tr>
					<?php foreach ( $header as $index => $column ) : ?><td><?php echo esc_html( (string) ( $row[ $index ] ?? '' ) ); ?></td><?php endforeach; ?>
				</tr><?php endforeach; ?>
			</tbody></table></div>
		</section>
		<?php
	}

	private static function render_templates() {
		$templates = TCSUP_CSV::templates();
		$download = wp_nonce_url( admin_url( 'admin-post.php?action=tcsup_download_template' ), 'tcsup_download_template' );
		?>
		<section class="tcsup-card">
			<h2>CSV Templates</h2>
			<p>Templates only define local CSV normalization. They never fetch supplier websites.</p>
			<p><a class="button button-primary" href="<?php echo esc_url( $download ); ?>">Download canonical CSV header</a></p>
			<table class="widefat striped">
				<thead><tr><th>Template</th><th>Supplier</th><th>Mode</th><th>Columns mapped</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $templates as $id => $template ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $template['name'] ); ?></strong><?php echo ! empty( $template['builtin'] ) ? '<br><small>Built in</small>' : ''; ?></td>
						<td><?php echo esc_html( (string) ( $template['provider'] ?? '—' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $template['mode'] ?? 'mapping' ) ); ?></td>
						<td><?php echo esc_html( count( (array) ( $template['mapping'] ?? array() ) ) ); ?></td>
						<td>
						<?php if ( empty( $template['builtin'] ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="tcsup_delete_template">
								<input type="hidden" name="template_id" value="<?php echo esc_attr( $id ); ?>">
								<?php wp_nonce_field( 'tcsup_delete_template' ); ?>
								<button class="button button-small">Delete</button>
							</form>
						<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	private static function request_filter( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	private static function catalogue_query( $limit = 50, $accepted_only = false ) {
		global $wpdb;

		$table         = TCSUP_Schema::catalog_table();
		$provider      = self::request_filter( 'supplier' );
		$selection     = self::request_filter( 'selection' );
		$country       = strtoupper( self::request_filter( 'country' ) );
		$search        = self::request_filter( 's' );
		$accepted_flag = $accepted_only ? 1 : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$limit  = max( 1, min( 200, absint( $limit ) ) );
		$offset = ( $page - 1 ) * $limit;
		$like   = '' !== $search ? '%' . $wpdb->esc_like( $search ) . '%' : '';

		$total = absint(
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i
					 WHERE (
						(%d=1 AND estado_seleccion IN (%s,%s,%s))
						OR
						(%d=0 AND (%s='' OR estado_seleccion=%s))
					 )
					 AND (%s='' OR proveedor=%s)
					 AND (%s='' OR pais_origen=%s)
					 AND (%s='' OR nombre LIKE %s OR sku LIKE %s OR proveedor_id_externo LIKE %s)",
					$table,
					$accepted_flag,
					'aceptado',
					'publicado',
					'actualizar',
					$accepted_flag,
					$selection,
					$selection,
					$provider,
					$provider,
					$country,
					$country,
					$search,
					$like,
					$like,
					$like
				)
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
				 WHERE (
					(%d=1 AND estado_seleccion IN (%s,%s,%s))
					OR
					(%d=0 AND (%s='' OR estado_seleccion=%s))
				 )
				 AND (%s='' OR proveedor=%s)
				 AND (%s='' OR pais_origen=%s)
				 AND (%s='' OR nombre LIKE %s OR sku LIKE %s OR proveedor_id_externo LIKE %s)
				 ORDER BY actualizado DESC,id DESC
				 LIMIT %d OFFSET %d",
				$table,
				$accepted_flag,
				'aceptado',
				'publicado',
				'actualizar',
				$accepted_flag,
				$selection,
				$selection,
				$provider,
				$provider,
				$country,
				$country,
				$search,
				$like,
				$like,
				$like,
				$limit,
				$offset
			),
			ARRAY_A
		);

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'page'  => $page,
			'pages' => max( 1, (int) ceil( $total / $limit ) ),
			'limit' => $limit,
		);
	}

	private static function render_pagination( $tab, $result ) {
		$pages = absint( $result['pages'] ?? 1 );
		if ( $pages <= 1 ) {
			return;
		}
		$current = max( 1, absint( $result['page'] ?? 1 ) );
		$args = array(
			'page' => self::PAGE,
			'tab'  => $tab,
		);
		foreach ( array( 'supplier', 'selection', 'country', 's' ) as $key ) {
			$value = self::request_filter( $key );
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}
		$base = add_query_arg( $args, admin_url( 'admin.php' ) );
		$base = add_query_arg( 'paged', 999999999, $base );
		$base = str_replace( '999999999', '%#%', $base );
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'      => $base,
					'format'    => '',
					'current'   => $current,
					'total'     => $pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			)
		);
		echo '</div></div>';
	}

	private static function providers() {
		global $wpdb;
		return $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT proveedor FROM %i WHERE proveedor<>%s ORDER BY proveedor ASC',
				TCSUP_Schema::catalog_table(),
				''
			)
		);
	}

	private static function filter_bar( $tab, $show_selection = true ) {
		?>
		<form method="get" class="tcsup-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<select name="supplier"><option value="">All suppliers</option>
				<?php foreach ( self::providers() as $provider ) : ?><option <?php selected( self::request_filter( 'supplier' ), $provider ); ?>><?php echo esc_html( $provider ); ?></option><?php endforeach; ?>
			</select>
			<?php if ( $show_selection ) : ?>
			<select name="selection">
				<option value="">All decisions</option>
				<?php foreach ( array( 'pendiente'=>'Pending','revisar'=>'Review','aceptado'=>'Accepted','descartado'=>'Discarded','publicado'=>'Published · legacy','actualizar'=>'Update · legacy','duplicado'=>'Duplicate · legacy' ) as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( self::request_filter( 'selection' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php endif; ?>
			<input type="text" name="country" value="<?php echo esc_attr( self::request_filter( 'country' ) ); ?>" placeholder="Country">
			<input type="search" name="s" value="<?php echo esc_attr( self::request_filter( 's' ) ); ?>" placeholder="Name, SKU or external ID">
			<button class="button">Filter</button>
		</form>
		<?php
	}

	private static function selection_label( $value ) {
		$labels = array(
			'pendiente'  => 'Pending',
			'revisar'    => 'Review',
			'aceptado'   => 'Accepted',
			'descartado' => 'Discarded',
			'publicado'  => 'Published',
			'actualizar' => 'Update',
			'duplicado'   => 'Duplicate',
		);
		$value = sanitize_key( (string) $value );
		return $labels[ $value ] ?? ucfirst( str_replace( '_', ' ', $value ) );
	}

	private static function sync_label( $value ) {
		$labels = array(
			'nuevo'        => 'New',
			'modificado'   => 'Modified',
			'sin_cambios'  => 'Unchanged',
			'sincronizado' => 'Synchronized',
			'conflicto'    => 'Conflict',
			'ignorado'     => 'Ignored',
			'pendiente'    => 'Pending',
			'legacy'       => 'Legacy',
		);
		$value = sanitize_key( (string) $value );
		return $labels[ $value ] ?? ucfirst( str_replace( '_', ' ', $value ) );
	}

	private static function render_inventory() {
		$result = self::catalogue_query( 50, false );
		$rows = $result['rows'];
		?>
		<section class="tcsup-card">
			<div class="tcsup-card-head"><div><h2>Intermediate supplier inventory</h2><p>CSV rows stay here until they are accepted, sent to review, or discarded.</p></div></div>
			<?php self::filter_bar( 'inventory', true ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tcsup_bulk_action">
				<?php wp_nonce_field( 'tcsup_bulk_action' ); ?>
				<div class="tcsup-bulk">
					<select name="bulk_supplier_action">
						<option value="">Bulk action</option>
						<option value="accept">Accept / link to WooCommerce</option>
						<option value="review">Mark for review</option>
						<option value="discard">Discard</option>
						<option value="pending">Return to pending</option>
					</select>
					<button class="button button-primary">Apply</button>
				</div>
				<div class="tcsup-scroll"><table class="widefat striped">
					<thead><tr><th><input type="checkbox" id="tcsup-select-page" aria-label="Select all rows on this page"></th><th>Supplier</th><th>External identity</th><th>Product</th><th>Market</th><th>Supplier price</th><th>Stock</th><th>Decision</th><th>Sync</th><th>Woo ID</th><th>Actions</th></tr></thead>
					<tbody>
					<?php if ( ! $rows ) : ?><tr><td colspan="11">No supplier rows match the current filters.</td></tr><?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><input type="checkbox" name="row_ids[]" value="<?php echo esc_attr( $row['id'] ); ?>"></td>
							<td><strong><?php echo esc_html( $row['proveedor'] ); ?></strong></td>
							<td><code><?php echo esc_html( $row['proveedor_id_externo'] ); ?></code><br><small><?php echo esc_html( $row['sku'] ); ?></small></td>
							<td><strong><?php echo esc_html( $row['nombre'] ); ?></strong><br><small><?php echo esc_html( $row['marca'] ); ?></small></td>
							<td><?php echo esc_html( $row['pais_origen'] ?: '—' ); ?></td>
							<td><?php echo esc_html( ( '' !== (string) $row['precio_con_iva'] ? $row['precio_con_iva'] : $row['precio_sin_iva'] ) . ' ' . $row['moneda'] ); ?></td>
							<td><?php echo esc_html( '' !== (string) $row['stock_cantidad'] ? $row['stock_cantidad'] : $row['stock_estado'] ); ?></td>
							<td><strong><?php echo esc_html( self::selection_label( $row['estado_seleccion'] ) ); ?></strong></td>
							<td><?php echo esc_html( self::sync_label( $row['estado_sincronizacion'] ) ); ?><?php echo $row['cambios_detectados'] ? '<br><small>' . esc_html( $row['cambios_detectados'] ) . '</small>' : ''; ?></td>
							<td><?php echo $row['object_id'] ? '<a href="' . esc_url( get_edit_post_link( absint( $row['object_id'] ) ) ) . '">#' . esc_html( $row['object_id'] ) . '</a>' : '—'; ?></td>
							<td class="tcsup-row-actions">
								<button class="button button-small button-primary" name="row_action" value="accept:<?php echo esc_attr( $row['id'] ); ?>">Accept</button>
								<button class="button button-small" name="row_action" value="review:<?php echo esc_attr( $row['id'] ); ?>">Review</button>
								<button class="button button-small" name="row_action" value="discard:<?php echo esc_attr( $row['id'] ); ?>">Discard</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			</form>
			<script>
			(function(){
				var toggle=document.getElementById('tcsup-select-page');
				if(!toggle){return;}
				toggle.addEventListener('change',function(){
					document.querySelectorAll('input[name="row_ids[]"]').forEach(function(box){box.checked=toggle.checked;});
				});
			})();
			</script>
			<?php self::render_pagination( 'inventory', $result ); ?>
		</section>
		<?php
	}

	private static function render_prices() {
		$result = self::catalogue_query( 40, false );
		$rows = $result['rows'];
		$sale_margin = (float) get_option( 'taxocommerce_suppliers_sale_margin', 20 );
		$regular_margin = (float) get_option( 'taxocommerce_suppliers_regular_margin', 45 );
		?>
		<section class="tcsup-card">
			<h2>Price control</h2>
			<p>Supplier cost remains separate from the WooCommerce selling price. You can correct imported supplier prices without re-importing a CSV.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tcsup-settings">
				<input type="hidden" name="action" value="tcsup_settings">
				<?php wp_nonce_field( 'tcsup_settings' ); ?>
				<label>Default sale margin % <input type="number" step="0.01" name="sale_margin" value="<?php echo esc_attr( $sale_margin ); ?>"></label>
				<label>Default regular margin % <input type="number" step="0.01" name="regular_margin" value="<?php echo esc_attr( $regular_margin ); ?>"></label>
				<button class="button">Save margins</button>
			</form>
			<?php self::filter_bar( 'prices', true ); ?>
			<div class="tcsup-price-list">
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$product = ! empty( $row['object_id'] ) && function_exists( 'wc_get_product' ) ? wc_get_product( absint( $row['object_id'] ) ) : false;
				?>
				<form class="tcsup-price-row" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="tcsup_price_update">
					<input type="hidden" name="row_id" value="<?php echo esc_attr( $row['id'] ); ?>">
					<?php wp_nonce_field( 'tcsup_price_update' ); ?>
					<div><strong><?php echo esc_html( $row['nombre'] ); ?></strong><br><small><?php echo esc_html( $row['proveedor'] . ' · ' . $row['sku'] ); ?></small></div>
					<label>Excl. tax<input name="precio_sin_iva" value="<?php echo esc_attr( $row['precio_sin_iva'] ); ?>"></label>
					<label>Incl. tax<input name="precio_con_iva" value="<?php echo esc_attr( $row['precio_con_iva'] ); ?>"></label>
					<label>Tax %<input name="iva_porcentaje" value="<?php echo esc_attr( $row['iva_porcentaje'] ); ?>"></label>
					<label>Currency<input name="moneda" value="<?php echo esc_attr( $row['moneda'] ); ?>"></label>
					<label>Woo regular<input name="woo_regular" value="<?php echo esc_attr( $product ? $product->get_regular_price() : '' ); ?>" <?php disabled( ! $product ); ?>></label>
					<label>Woo sale<input name="woo_sale" value="<?php echo esc_attr( $product ? $product->get_sale_price() : '' ); ?>" <?php disabled( ! $product ); ?>></label>
					<label class="tcsup-check"><input type="checkbox" name="apply_woo" value="1" <?php disabled( ! $product ); ?>> Apply Woo prices</label>
					<button class="button button-primary">Save</button>
				</form>
			<?php endforeach; ?>
			</div>
			<?php self::render_pagination( 'prices', $result ); ?>
		</section>
		<?php
	}

	private static function render_images() {
		$result = self::catalogue_query( 50, false );
		$rows = $result['rows'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only row selector.
		$selected_id = isset( $_GET['row_id'] ) ? absint( $_GET['row_id'] ) : 0;
		$selected = $selected_id ? TCSUP_Products::row( $selected_id ) : array();
		?>
		<section class="tcsup-card">
			<h2>External Images</h2>
			<p>External image URLs are stored as references in <code>seo_supplier_images</code>. They are not downloaded into WordPress Media.</p>
			<?php self::filter_bar( 'images', true ); ?>
			<?php if ( $selected ) : ?>
				<?php $urls = TCSUP_CSV::image_urls( $selected['imagenes'] ?? '' ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tcsup-image-editor">
					<input type="hidden" name="action" value="tcsup_images_update">
					<input type="hidden" name="row_id" value="<?php echo esc_attr( $selected_id ); ?>">
					<?php wp_nonce_field( 'tcsup_images_update' ); ?>
					<h3><?php echo esc_html( $selected['nombre'] ); ?></h3>
					<p><strong><?php echo esc_html( $selected['proveedor'] ); ?></strong> · <code><?php echo esc_html( $selected['sku'] ); ?></code> · Woo ID <?php echo esc_html( $selected['object_id'] ?: '—' ); ?></p>
					<label><strong>Image mode</strong>
						<select name="image_mode">
							<option value="external" <?php selected( $selected['modo_imagenes'], 'external' ); ?>>External references</option>
							<option value="local" <?php selected( $selected['modo_imagenes'], 'local' ); ?>>Local Media</option>
							<option value="inherit" <?php selected( $selected['modo_imagenes'], 'inherit' ); ?>>Inherit existing behavior</option>
						</select>
					</label>
					<label><strong>External image URLs — one per line</strong>
						<textarea name="image_urls" rows="12"><?php echo esc_textarea( implode( "\n", $urls ) ); ?></textarea>
					</label>
					<?php submit_button( 'Save external images', 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<div class="tcsup-scroll"><table class="widefat striped">
				<thead><tr><th>Supplier</th><th>Product</th><th>Woo ID</th><th>Mode</th><th>URLs</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['proveedor'] ); ?><br><code><?php echo esc_html( $row['sku'] ); ?></code></td>
						<td><?php echo esc_html( $row['nombre'] ); ?></td>
						<td><?php echo esc_html( $row['object_id'] ?: '—' ); ?></td>
						<td><?php echo esc_html( $row['modo_imagenes'] ); ?></td>
						<td><?php echo esc_html( count( TCSUP_CSV::image_urls( $row['imagenes'] ?? '' ) ) ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( self::tab_url( 'images', array( 'row_id' => absint( $row['id'] ) ) ) ); ?>">Edit</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<?php self::render_pagination( 'images', $result ); ?>
		</section>
		<?php
	}

	public static function handle_settings() {
		self::guard();
		check_admin_referer( 'tcsup_settings' );
		$sale = isset( $_POST['sale_margin'] ) ? (float) wp_unslash( $_POST['sale_margin'] ) : 20;
		$regular = isset( $_POST['regular_margin'] ) ? (float) wp_unslash( $_POST['regular_margin'] ) : 45;
		$sale = max( -99, min( 1000, $sale ) );
		$regular = max( $sale, min( 1000, $regular ) );
		update_option( 'taxocommerce_suppliers_sale_margin', $sale, false );
		update_option( 'taxocommerce_suppliers_regular_margin', $regular, false );
		wp_safe_redirect( self::tab_url( 'prices', array( 'settings_saved' => 1 ) ) );
		exit;
	}
}
