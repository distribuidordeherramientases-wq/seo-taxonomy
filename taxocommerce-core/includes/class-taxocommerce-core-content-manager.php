<?php
/**
 * Unified WordPress and WooCommerce content manager.
 *
 * @package TaxoCommerceCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides one editing surface without duplicating canonical content data.
 */
final class TaxoCommerce_Core_Content_Manager {

	const PAGE_SLUG = 'taxocommerce-core-content';
	const PER_PAGE  = 25;

	/**
	 * Logger.
	 *
	 * @var TaxoCommerce_Core_Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param TaxoCommerce_Core_Logger $logger Logger.
	 */
	public function __construct( TaxoCommerce_Core_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_taxocommerce_core_save_content', array( $this, 'save_content' ) );
	}

	/**
	 * Main page.
	 *
	 * @return void
	 */
	public function render() {
		$this->guard();

		$content_type = $this->request_content_type();
		$action       = isset( $_GET['tc_action'] ) ? sanitize_key( wp_unslash( $_GET['tc_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$item_id      = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Gestor de contenidos', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Productos, categorías de producto, posts y páginas se editan desde un único lugar, manteniendo WordPress y WooCommerce como fuente de verdad.', 'taxocommerce-core' ); ?></p>
			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'Equivalente actual:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html__( 'estas ediciones están repartidas entre WordPress, WooCommerce y varias herramientas del plugin TaxoCommerce actual. Aquí empezamos a unificarlas sin duplicar los datos.', 'taxocommerce-core' ); ?></p></div>
			<?php $this->render_tabs( $content_type ); ?>

			<?php if ( isset( $_GET['updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Success flag only. ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Contenido actualizado.', 'taxocommerce-core' ); ?></p></div>
			<?php endif; ?>

			<?php
			if ( 'edit' === $action && $item_id ) {
				$this->render_editor( $content_type, $item_id );
				return;
			}

			$this->render_kpis( $content_type );
			$this->render_filters( $content_type );
			$this->render_list( $content_type );
			?>
		</div>
		<?php
	}

	/**
	 * Saves one canonical object.
	 *
	 * @return void
	 */
	public function save_content() {
		$this->guard();
		check_admin_referer( 'taxocommerce_core_save_content', 'taxocommerce_core_nonce' );

		$content_type = isset( $_POST['content_type'] ) ? sanitize_key( wp_unslash( $_POST['content_type'] ) ) : '';
		$item_id      = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;

		if ( ! in_array( $content_type, array( 'products', 'categories', 'posts', 'pages' ), true ) || ! $item_id ) {
			wp_die( esc_html__( 'Solicitud de contenido no válida.', 'taxocommerce-core' ) );
		}

		if ( 'products' === $content_type ) {
			$result = $this->save_product( $item_id );
		} elseif ( 'categories' === $content_type ) {
			$result = $this->save_product_category( $item_id );
		} else {
			$result = $this->save_post_object( $content_type, $item_id );
		}

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$this->logger->info(
			'Contenido actualizado desde el Core.',
			array(
				'content_type' => $content_type,
				'item_id'      => $item_id,
			)
		);

		$redirect = add_query_arg(
			array(
				'page'      => self::PAGE_SLUG,
				'tc_type'   => $content_type,
				'tc_action' => 'edit',
				'item_id'   => $item_id,
				'updated'   => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Checks access.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'No tienes permisos para gestionar TaxoCommerce Core.', 'taxocommerce-core' ) );
		}
	}

	/**
	 * Returns selected content type.
	 *
	 * @return string
	 */
	private function request_content_type() {
		$type = isset( $_GET['tc_type'] ) ? sanitize_key( wp_unslash( $_GET['tc_type'] ) ) : 'products'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		return in_array( $type, array( 'products', 'categories', 'posts', 'pages' ), true ) ? $type : 'products';
	}

	/**
	 * Tabs.
	 *
	 * @param string $current Current type.
	 * @return void
	 */
	private function render_tabs( $current ) {
		$tabs = array(
			'products'   => __( 'Productos', 'taxocommerce-core' ),
			'categories' => __( 'Categorías de producto', 'taxocommerce-core' ),
			'posts'      => __( 'Posts', 'taxocommerce-core' ),
			'pages'      => __( 'Páginas', 'taxocommerce-core' ),
		);
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Tipos de contenido', 'taxocommerce-core' ); ?>">
			<?php foreach ( $tabs as $type => $label ) : ?>
				<?php
				$url   = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tc_type' => $type ), admin_url( 'admin.php' ) );
				$class = 'nav-tab' . ( $current === $type ? ' nav-tab-active' : '' );
				?>
				<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Top KPIs.
	 *
	 * @param string $content_type Type.
	 * @return void
	 */
	private function render_kpis( $content_type ) {
		if ( 'categories' === $content_type ) {
			$total     = wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
			$non_empty = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'fields'     => 'ids',
				)
			);

			$cards = array(
				__( 'Categorías', 'taxocommerce-core' )    => is_wp_error( $total ) ? 0 : absint( $total ),
				__( 'Con productos', 'taxocommerce-core' ) => is_wp_error( $non_empty ) ? 0 : count( $non_empty ),
			);
		} else {
			$counts = wp_count_posts( $this->post_type_for_content( $content_type ) );
			$cards  = array(
				__( 'Publicado', 'taxocommerce-core' ) => isset( $counts->publish ) ? absint( $counts->publish ) : 0,
				__( 'Borrador', 'taxocommerce-core' )     => isset( $counts->draft ) ? absint( $counts->draft ) : 0,
				__( 'Pendiente', 'taxocommerce-core' )   => isset( $counts->pending ) ? absint( $counts->pending ) : 0,
				__( 'Papelera', 'taxocommerce-core' )     => isset( $counts->trash ) ? absint( $counts->trash ) : 0,
			);
		}

		/**
		 * Lets modules add or replace summary KPIs.
		 *
		 * @param array  $cards        KPI pairs.
		 * @param string $content_type Content type.
		 */
		$cards = (array) apply_filters( 'taxocommerce_core_content_summary_kpis', $cards, $content_type );
		?>
		<div style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0;">
			<?php foreach ( $cards as $label => $value ) : ?>
				<div class="card" style="min-width:160px;margin:0;padding:12px 16px;">
					<strong style="display:block;font-size:22px;"><?php echo esc_html( (string) $value ); ?></strong>
					<span><?php echo esc_html( (string) $label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Filters.
	 *
	 * @param string $content_type Type.
	 * @return void
	 */
	private function render_filters( $content_type ) {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$status = isset( $_GET['tc_status'] ) ? sanitize_key( wp_unslash( $_GET['tc_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$cat_id = isset( $_GET['tc_category'] ) ? absint( $_GET['tc_category'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tc_type" value="<?php echo esc_attr( $content_type ); ?>">
			<label class="screen-reader-text" for="tc-content-search"><?php echo esc_html__( 'Buscar contenidos', 'taxocommerce-core' ); ?></label>
			<input id="tc-content-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Buscar', 'taxocommerce-core' ); ?>">

			<?php if ( 'categories' !== $content_type ) : ?>
				<select name="tc_status" aria-label="<?php echo esc_attr__( 'Estado', 'taxocommerce-core' ); ?>">
					<option value=""><?php echo esc_html__( 'Todos los estados', 'taxocommerce-core' ); ?></option>
					<?php foreach ( $this->editable_statuses() as $status_key => $status_label ) : ?>
						<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<?php if ( in_array( $content_type, array( 'products', 'posts' ), true ) ) : ?>
				<?php
				wp_dropdown_categories(
					array(
						'show_option_all' => __( 'Todas las categorías', 'taxocommerce-core' ),
						'taxonomy'        => 'products' === $content_type ? 'product_cat' : 'category',
						'hide_empty'      => false,
						'name'            => 'tc_category',
						'selected'        => $cat_id,
						'value_field'     => 'term_id',
					)
				);
				?>
			<?php endif; ?>

			<button class="button"><?php echo esc_html__( 'Filtrar', 'taxocommerce-core' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Content list.
	 *
	 * @param string $content_type Type.
	 * @return void
	 */
	private function render_list( $content_type ) {
		if ( 'categories' === $content_type ) {
			$this->render_category_list();
			return;
		}
		$this->render_post_list( $content_type );
	}

	/**
	 * Products/posts/pages.
	 *
	 * @param string $content_type Type.
	 * @return void
	 */
	private function render_post_list( $content_type ) {
		$post_type = $this->post_type_for_content( $content_type );
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$status    = isset( $_GET['tc_status'] ) ? sanitize_key( wp_unslash( $_GET['tc_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$cat_id    = isset( $_GET['tc_category'] ) ? absint( $_GET['tc_category'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.

		$allowed_statuses = $this->editable_statuses();
		$args             = array(
			'post_type'      => $post_type,
			'post_status'    => $status && isset( $allowed_statuses[ $status ] ) ? $status : array_keys( $allowed_statuses ),
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			's'              => $search,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( $cat_id ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- User-requested admin taxonomy filter.
				array(
					'taxonomy' => 'products' === $content_type ? 'product_cat' : 'category',
					'field'    => 'term_id',
					'terms'    => array( $cat_id ),
				),
			);
		}

		$query = new WP_Query( $args );
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Título', 'taxocommerce-core' ); ?></th>
					<th><?php echo esc_html__( 'Estado', 'taxocommerce-core' ); ?></th>
					<th><?php echo esc_html__( 'Categorías', 'taxocommerce-core' ); ?></th>
					<?php if ( 'products' === $content_type ) : ?>
						<th><?php echo esc_html__( 'Precio', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Stock', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Imagen', 'taxocommerce-core' ); ?></th>
					<?php endif; ?>
					<th><?php echo esc_html__( 'KPI', 'taxocommerce-core' ); ?></th>
					<th><?php echo esc_html__( 'Modificado', 'taxocommerce-core' ); ?></th>
					<th><?php echo esc_html__( 'Acciones', 'taxocommerce-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $query->posts ) ) : ?>
				<tr><td colspan="10"><?php echo esc_html__( 'No se ha encontrado contenido.', 'taxocommerce-core' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $query->posts as $post ) : ?>
					<?php
					$taxonomy   = 'products' === $content_type ? 'product_cat' : ( 'posts' === $content_type ? 'category' : '' );
					$terms      = $taxonomy ? get_the_terms( $post, $taxonomy ) : array();
					$term_names = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : array();
					$product    = 'products' === $content_type && function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : false;
					$status_obj = get_post_status_object( $post->post_status );
					$native     = get_edit_post_link( $post->ID, '' );
					?>
					<tr>
						<td><strong><?php echo esc_html( get_the_title( $post ) ); ?></strong><br><small>#<?php echo esc_html( (string) $post->ID ); ?></small></td>
						<td><?php echo esc_html( $status_obj ? $status_obj->label : $post->post_status ); ?></td>
						<td><?php echo esc_html( $term_names ? implode( ', ', array_slice( $term_names, 0, 4 ) ) : '—' ); ?></td>
						<?php if ( 'products' === $content_type ) : ?>
							<td><?php echo $product ? wp_kses_post( $product->get_price_html() ) : '—'; ?></td>
							<td><?php echo $product ? esc_html( $product->get_stock_status() ) : '—'; ?></td>
							<td><?php echo has_post_thumbnail( $post->ID ) ? esc_html__( 'Sí', 'taxocommerce-core' ) : esc_html__( 'No', 'taxocommerce-core' ); ?></td>
						<?php endif; ?>
						<td><strong><?php echo esc_html( (string) $this->content_score( $content_type, $post->ID ) ); ?>%</strong></td>
						<td><?php echo esc_html( get_post_modified_time( 'Y-m-d H:i', false, $post ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( $this->core_edit_url( $content_type, $post->ID ) ); ?>"><?php echo esc_html__( 'Editar aquí', 'taxocommerce-core' ); ?></a>
							<?php if ( $native ) : ?> · <a href="<?php echo esc_url( $native ); ?>"><?php echo esc_html__( 'Editor nativo', 'taxocommerce-core' ); ?></a><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		$this->render_pagination( absint( $query->max_num_pages ), $paged, $content_type );
		wp_reset_postdata();
	}

	/**
	 * Product-category list.
	 *
	 * @return void
	 */
	private function render_category_list() {
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$args   = array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'number'     => self::PER_PAGE,
			'offset'     => $offset,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		if ( $search ) {
			$args['search'] = $search;
		}

		$terms = get_terms( $args );
		$count = wp_count_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'search'     => $search,
			)
		);
		$total_pages = is_wp_error( $count ) ? 1 : max( 1, (int) ceil( absint( $count ) / self::PER_PAGE ) );
		?>
		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html__( 'Categoría', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Superior', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Productos', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Descripción', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Imagen', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'KPI', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Acciones', 'taxocommerce-core' ); ?></th></tr></thead>
			<tbody>
			<?php if ( is_wp_error( $terms ) || empty( $terms ) ) : ?>
				<tr><td colspan="7"><?php echo esc_html__( 'No se han encontrado categorías.', 'taxocommerce-core' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $terms as $term ) : ?>
					<?php
					$parent = $term->parent ? get_term( $term->parent, 'product_cat' ) : null;
					$native = get_edit_term_link( $term->term_id, 'product_cat', 'product' );
					?>
					<tr>
						<td><strong><?php echo esc_html( $term->name ); ?></strong><br><small><?php echo esc_html( $term->slug ); ?> · #<?php echo esc_html( (string) $term->term_id ); ?></small></td>
						<td><?php echo $parent && ! is_wp_error( $parent ) ? esc_html( $parent->name ) : '—'; ?></td>
						<td><?php echo esc_html( (string) $term->count ); ?></td>
						<td><?php echo $term->description ? esc_html__( 'Sí', 'taxocommerce-core' ) : esc_html__( 'No', 'taxocommerce-core' ); ?></td>
						<td><?php echo absint( get_term_meta( $term->term_id, 'thumbnail_id', true ) ) ? esc_html__( 'Sí', 'taxocommerce-core' ) : esc_html__( 'No', 'taxocommerce-core' ); ?></td>
						<td><strong><?php echo esc_html( (string) $this->content_score( 'categories', $term->term_id ) ); ?>%</strong></td>
						<td><a href="<?php echo esc_url( $this->core_edit_url( 'categories', $term->term_id ) ); ?>"><?php echo esc_html__( 'Editar aquí', 'taxocommerce-core' ); ?></a><?php if ( $native ) : ?> · <a href="<?php echo esc_url( $native ); ?>"><?php echo esc_html__( 'Editor nativo', 'taxocommerce-core' ); ?></a><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		$this->render_pagination( $total_pages, $paged, 'categories' );
	}

	/**
	 * Object editor router.
	 *
	 * @param string $content_type Type.
	 * @param int    $item_id      ID.
	 * @return void
	 */
	private function render_editor( $content_type, $item_id ) {
		if ( 'categories' === $content_type ) {
			$this->render_category_editor( $item_id );
		} elseif ( 'products' === $content_type ) {
			$this->render_product_editor( $item_id );
		} else {
			$this->render_post_editor( $content_type, $item_id );
		}
	}

	/**
	 * Product editor.
	 *
	 * @param int $item_id Product ID.
	 * @return void
	 */
	private function render_product_editor( $item_id ) {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_post', $item_id ) ) {
			wp_die( esc_html__( 'La edición de productos no está disponible.', 'taxocommerce-core' ) );
		}

		$product = wc_get_product( $item_id );
		if ( ! $product ) {
			wp_die( esc_html__( 'Producto no encontrado.', 'taxocommerce-core' ) );
		}

		$category_ids = array_map( 'absint', $product->get_category_ids() );
		$categories   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );

		$this->render_editor_header( 'products', $item_id, $product->get_name(), $this->content_score( 'products', $item_id ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="taxocommerce_core_save_content">
			<input type="hidden" name="content_type" value="products">
			<input type="hidden" name="item_id" value="<?php echo esc_attr( (string) $item_id ); ?>">
			<?php wp_nonce_field( 'taxocommerce_core_save_content', 'taxocommerce_core_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="tc-title"><?php echo esc_html__( 'Nombre', 'taxocommerce-core' ); ?></label></th><td><input class="large-text" id="tc-title" name="title" value="<?php echo esc_attr( $product->get_name( 'edit' ) ); ?>" required></td></tr>
				<tr><th scope="row"><label for="tc-slug"><?php echo esc_html__( 'Slug', 'taxocommerce-core' ); ?></label></th><td><input class="regular-text" id="tc-slug" name="slug" value="<?php echo esc_attr( $product->get_slug( 'edit' ) ); ?>"></td></tr>
				<tr><th scope="row"><label for="tc-status"><?php echo esc_html__( 'Estado', 'taxocommerce-core' ); ?></label></th><td><?php $this->render_status_select( $product->get_status( 'edit' ) ); ?></td></tr>
				<tr><th scope="row"><label for="tc-sku"><?php echo esc_html__( 'SKU', 'taxocommerce-core' ); ?></label></th><td><input class="regular-text" id="tc-sku" name="sku" value="<?php echo esc_attr( $product->get_sku( 'edit' ) ); ?>"></td></tr>
				<tr><th scope="row"><label for="tc-regular-price"><?php echo esc_html__( 'Precio normal', 'taxocommerce-core' ); ?></label></th><td><input id="tc-regular-price" name="regular_price" value="<?php echo esc_attr( $product->get_regular_price( 'edit' ) ); ?>"></td></tr>
				<tr><th scope="row"><label for="tc-sale-price"><?php echo esc_html__( 'Precio rebajado', 'taxocommerce-core' ); ?></label></th><td><input id="tc-sale-price" name="sale_price" value="<?php echo esc_attr( $product->get_sale_price( 'edit' ) ); ?>"></td></tr>
				<tr><th scope="row"><label for="tc-stock-status"><?php echo esc_html__( 'Estado de stock', 'taxocommerce-core' ); ?></label></th><td><select id="tc-stock-status" name="stock_status"><option value="instock" <?php selected( $product->get_stock_status( 'edit' ), 'instock' ); ?>><?php echo esc_html__( 'En stock', 'taxocommerce-core' ); ?></option><option value="outofstock" <?php selected( $product->get_stock_status( 'edit' ), 'outofstock' ); ?>><?php echo esc_html__( 'Agotado', 'taxocommerce-core' ); ?></option><option value="onbackorder" <?php selected( $product->get_stock_status( 'edit' ), 'onbackorder' ); ?>><?php echo esc_html__( 'Disponible bajo pedido', 'taxocommerce-core' ); ?></option></select></td></tr>
				<tr><th scope="row"><label for="tc-excerpt"><?php echo esc_html__( 'Descripción corta', 'taxocommerce-core' ); ?></label></th><td><textarea class="large-text" rows="5" id="tc-excerpt" name="excerpt"><?php echo esc_textarea( $product->get_short_description( 'edit' ) ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="tc-content"><?php echo esc_html__( 'Descripción', 'taxocommerce-core' ); ?></label></th><td><textarea class="large-text" rows="14" id="tc-content" name="content"><?php echo esc_textarea( $product->get_description( 'edit' ) ); ?></textarea></td></tr>
				<tr>
					<th scope="row"><label for="tc-product-categories"><?php echo esc_html__( 'Categorías de producto', 'taxocommerce-core' ); ?></label></th>
					<td>
						<select id="tc-product-categories" name="category_ids[]" multiple size="14" style="min-width:420px;max-width:100%;">
							<?php if ( is_array( $categories ) ) : ?>
								<?php foreach ( $categories as $category ) : ?>
									<option value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php selected( in_array( absint( $category->term_id ), $category_ids, true ) ); ?>><?php echo esc_html( $category->name ); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
						<p class="description"><?php echo esc_html__( 'Esta edición escribe directamente en la relación nativa producto-categoría de WooCommerce.', 'taxocommerce-core' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Actualizar producto', 'taxocommerce-core' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Category editor.
	 *
	 * @param int $item_id Term ID.
	 * @return void
	 */
	private function render_category_editor( $item_id ) {
		$taxonomy = get_taxonomy( 'product_cat' );
		if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->edit_terms ) ) {
			wp_die( esc_html__( 'La edición de categorías de producto no está disponible.', 'taxocommerce-core' ) );
		}

		$term = get_term( $item_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_die( esc_html__( 'Categoría no encontrada.', 'taxocommerce-core' ) );
		}

		$this->render_editor_header( 'categories', $item_id, $term->name, $this->content_score( 'categories', $item_id ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="taxocommerce_core_save_content">
			<input type="hidden" name="content_type" value="categories">
			<input type="hidden" name="item_id" value="<?php echo esc_attr( (string) $item_id ); ?>">
			<?php wp_nonce_field( 'taxocommerce_core_save_content', 'taxocommerce_core_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="tc-name"><?php echo esc_html__( 'Nombre', 'taxocommerce-core' ); ?></label></th><td><input class="large-text" id="tc-name" name="name" value="<?php echo esc_attr( $term->name ); ?>" required></td></tr>
				<tr><th scope="row"><label for="tc-slug"><?php echo esc_html__( 'Slug', 'taxocommerce-core' ); ?></label></th><td><input class="regular-text" id="tc-slug" name="slug" value="<?php echo esc_attr( $term->slug ); ?>"></td></tr>
				<tr><th scope="row"><label for="tc-parent"><?php echo esc_html__( 'Superior', 'taxocommerce-core' ); ?></label></th><td><?php wp_dropdown_categories( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'name' => 'parent', 'id' => 'tc-parent', 'selected' => $term->parent, 'show_option_none' => __( 'Ninguna', 'taxocommerce-core' ), 'exclude' => $term->term_id, 'value_field' => 'term_id' ) ); ?></td></tr>
				<tr><th scope="row"><label for="tc-description"><?php echo esc_html__( 'Descripción', 'taxocommerce-core' ); ?></label></th><td><textarea class="large-text" rows="14" id="tc-description" name="description"><?php echo esc_textarea( $term->description ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="tc-thumbnail-id"><?php echo esc_html__( 'ID de la imagen', 'taxocommerce-core' ); ?></label></th><td><input type="number" min="0" id="tc-thumbnail-id" name="thumbnail_id" value="<?php echo esc_attr( (string) absint( get_term_meta( $item_id, 'thumbnail_id', true ) ) ); ?>"><p class="description"><?php echo esc_html__( 'El selector visual de imágenes se incorporará al futuro módulo de Imágenes; el Core guarda la referencia nativa de imagen de categoría de WooCommerce.', 'taxocommerce-core' ); ?></p></td></tr>
			</table>
			<?php submit_button( __( 'Actualizar categoría', 'taxocommerce-core' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Post/page editor.
	 *
	 * @param string $content_type Type.
	 * @param int    $item_id      Post ID.
	 * @return void
	 */
	private function render_post_editor( $content_type, $item_id ) {
		$post = get_post( $item_id );
		if ( ! $post || $post->post_type !== $this->post_type_for_content( $content_type ) || ! current_user_can( 'edit_post', $item_id ) ) {
			wp_die( esc_html__( 'El contenido no existe o no se puede editar.', 'taxocommerce-core' ) );
		}

		$selected_categories = 'posts' === $content_type ? array_map( 'absint', wp_get_post_categories( $item_id ) ) : array();
		$this->render_editor_header( $content_type, $item_id, get_the_title( $post ), $this->content_score( $content_type, $item_id ) );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="taxocommerce_core_save_content">
			<input type="hidden" name="content_type" value="<?php echo esc_attr( $content_type ); ?>">
			<input type="hidden" name="item_id" value="<?php echo esc_attr( (string) $item_id ); ?>">
			<?php wp_nonce_field( 'taxocommerce_core_save_content', 'taxocommerce_core_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="tc-title"><?php echo esc_html__( 'Título', 'taxocommerce-core' ); ?></label></th><td><input class="large-text" id="tc-title" name="title" value="<?php echo esc_attr( $post->post_title ); ?>" required></td></tr>
				<tr><th scope="row"><label for="tc-slug"><?php echo esc_html__( 'Slug', 'taxocommerce-core' ); ?></label></th><td><input class="regular-text" id="tc-slug" name="slug" value="<?php echo esc_attr( $post->post_name ); ?>"></td></tr>
				<tr><th scope="row"><label for="tc-status"><?php echo esc_html__( 'Estado', 'taxocommerce-core' ); ?></label></th><td><?php $this->render_status_select( $post->post_status ); ?></td></tr>
				<tr><th scope="row"><label for="tc-excerpt"><?php echo esc_html__( 'Extracto', 'taxocommerce-core' ); ?></label></th><td><textarea class="large-text" rows="5" id="tc-excerpt" name="excerpt"><?php echo esc_textarea( $post->post_excerpt ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="tc-content"><?php echo esc_html__( 'Contenido', 'taxocommerce-core' ); ?></label></th><td><textarea class="large-text" rows="20" id="tc-content" name="content"><?php echo esc_textarea( $post->post_content ); ?></textarea></td></tr>
				<?php if ( 'posts' === $content_type ) : ?>
					<tr>
						<th scope="row"><label for="tc-post-categories"><?php echo esc_html__( 'Categorías', 'taxocommerce-core' ); ?></label></th>
						<td>
							<select id="tc-post-categories" name="category_ids[]" multiple size="12" style="min-width:360px;">
								<?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $category ) : ?>
									<option value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php selected( in_array( absint( $category->term_id ), $selected_categories, true ) ); ?>><?php echo esc_html( $category->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endif; ?>
			</table>
			<?php submit_button( 'posts' === $content_type ? __( 'Actualizar post', 'taxocommerce-core' ) : __( 'Actualizar página', 'taxocommerce-core' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Editor header.
	 *
	 * @param string $content_type Type.
	 * @param int    $item_id      ID.
	 * @param string $title        Title.
	 * @param int    $score        KPI.
	 * @return void
	 */
	private function render_editor_header( $content_type, $item_id, $title, $score ) {
		$back = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tc_type' => $content_type ), admin_url( 'admin.php' ) );
		?>
		<p><a href="<?php echo esc_url( $back ); ?>">&larr; <?php echo esc_html__( 'Volver al listado', 'taxocommerce-core' ); ?></a></p>
		<div class="card" style="max-width:none;margin:16px 0;">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p><strong><?php echo esc_html__( 'ID del elemento:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html( (string) $item_id ); ?> &nbsp; <strong><?php echo esc_html__( 'KPI del Core:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html( (string) $score ); ?>%</p>
		</div>
		<?php
	}

	/**
	 * Saves WooCommerce product through CRUD.
	 *
	 * @param int $item_id Product ID.
	 * @return true|WP_Error
	 */
	private function save_product( $item_id ) {
		if ( ! function_exists( 'wc_get_product' ) || ! current_user_can( 'edit_post', $item_id ) ) {
			return new WP_Error( 'taxocommerce_core_product_permission', __( 'No tienes permisos para editar este producto.', 'taxocommerce-core' ) );
		}

		$product = wc_get_product( $item_id );
		if ( ! $product ) {
			return new WP_Error( 'taxocommerce_core_product_missing', __( 'Producto no encontrado.', 'taxocommerce-core' ) );
		}

		$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$slug          = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$status        = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft';
		$sku           = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';
		$regular_price = isset( $_POST['regular_price'] ) ? wc_format_decimal( wp_unslash( $_POST['regular_price'] ) ) : '';
		$sale_price    = isset( $_POST['sale_price'] ) ? wc_format_decimal( wp_unslash( $_POST['sale_price'] ) ) : '';
		$stock_status  = isset( $_POST['stock_status'] ) ? sanitize_key( wp_unslash( $_POST['stock_status'] ) ) : 'instock';
		$excerpt       = isset( $_POST['excerpt'] ) ? wp_kses_post( wp_unslash( $_POST['excerpt'] ) ) : '';
		$body          = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
		$category_ids  = isset( $_POST['category_ids'] ) && is_array( $_POST['category_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['category_ids'] ) ) ) ) ) : array();

		if ( ! $title || ! isset( $this->editable_statuses()[ $status ] ) || ! in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
			return new WP_Error( 'taxocommerce_core_product_invalid', __( 'Los datos del producto no son válidos.', 'taxocommerce-core' ) );
		}

		if ( 'trash' === $status ) {
			return wp_trash_post( $item_id ) ? true : new WP_Error( 'taxocommerce_core_product_trash', __( 'No se ha podido mover el producto a la papelera.', 'taxocommerce-core' ) );
		}

		try {
			$product->set_name( $title );
			$product->set_slug( $slug );
			$product->set_status( $status );
			$product->set_sku( $sku );
			$product->set_regular_price( $regular_price );
			$product->set_sale_price( $sale_price );
			$product->set_stock_status( $stock_status );
			$product->set_short_description( $excerpt );
			$product->set_description( $body );
			$product->set_category_ids( $category_ids );
			$product->save();
		} catch ( Exception $exception ) {
			return new WP_Error( 'taxocommerce_core_product_save', sanitize_text_field( $exception->getMessage() ) );
		}

		return true;
	}

	/**
	 * Saves product category.
	 *
	 * @param int $item_id Term ID.
	 * @return true|WP_Error
	 */
	private function save_product_category( $item_id ) {
		$taxonomy = get_taxonomy( 'product_cat' );
		if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->edit_terms ) ) {
			return new WP_Error( 'taxocommerce_core_category_permission', __( 'No tienes permisos para editar categorías de producto.', 'taxocommerce-core' ) );
		}

		$name         = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug         = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$parent       = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;
		$description  = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';
		$thumbnail_id = isset( $_POST['thumbnail_id'] ) ? absint( $_POST['thumbnail_id'] ) : 0;

		if ( ! $name || $parent === $item_id ) {
			return new WP_Error( 'taxocommerce_core_category_invalid', __( 'Los datos de la categoría no son válidos.', 'taxocommerce-core' ) );
		}

		$result = wp_update_term(
			$item_id,
			'product_cat',
			array(
				'name'        => $name,
				'slug'        => $slug,
				'parent'      => $parent,
				'description' => $description,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_term_meta( $item_id, 'thumbnail_id', $thumbnail_id );
		return true;
	}

	/**
	 * Saves post/page.
	 *
	 * @param string $content_type Type.
	 * @param int    $item_id      Post ID.
	 * @return true|WP_Error
	 */
	private function save_post_object( $content_type, $item_id ) {
		$post = get_post( $item_id );
		if ( ! $post || $post->post_type !== $this->post_type_for_content( $content_type ) || ! current_user_can( 'edit_post', $item_id ) ) {
			return new WP_Error( 'taxocommerce_core_post_permission', __( 'No tienes permisos para editar este contenido.', 'taxocommerce-core' ) );
		}

		$title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$slug    = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$status  = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft';
		$excerpt = isset( $_POST['excerpt'] ) ? wp_kses_post( wp_unslash( $_POST['excerpt'] ) ) : '';
		$body    = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';

		if ( ! $title || ! isset( $this->editable_statuses()[ $status ] ) ) {
			return new WP_Error( 'taxocommerce_core_post_invalid', __( 'Los datos del contenido no son válidos.', 'taxocommerce-core' ) );
		}

		if ( 'trash' === $status ) {
			return wp_trash_post( $item_id ) ? true : new WP_Error( 'taxocommerce_core_post_trash', __( 'No se ha podido mover el contenido a la papelera.', 'taxocommerce-core' ) );
		}

		$result = wp_update_post(
			array(
				'ID'           => $item_id,
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_status'  => $status,
				'post_excerpt' => $excerpt,
				'post_content' => $body,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'posts' === $content_type ) {
			$category_ids = isset( $_POST['category_ids'] ) && is_array( $_POST['category_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['category_ids'] ) ) ) ) ) : array();
			wp_set_post_categories( $item_id, $category_ids, false );
		}

		return true;
	}

	/**
	 * Lightweight rebuildable completeness KPI.
	 *
	 * @param string $content_type Type.
	 * @param int    $item_id      ID.
	 * @return int
	 */
	private function content_score( $content_type, $item_id ) {
		$checks = array();

		if ( 'categories' === $content_type ) {
			$term = get_term( $item_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$checks = array(
					'' !== trim( $term->name ),
					'' !== trim( wp_strip_all_tags( $term->description ) ),
					absint( get_term_meta( $item_id, 'thumbnail_id', true ) ) > 0,
					$term->count > 0,
				);
			}
		} else {
			$post = get_post( $item_id );
			if ( $post ) {
				$checks = array(
					'' !== trim( $post->post_title ),
					'' !== trim( wp_strip_all_tags( $post->post_content ) ),
					has_post_thumbnail( $item_id ),
				);

				if ( in_array( $content_type, array( 'products', 'posts' ), true ) ) {
					$terms    = wp_get_object_terms( $item_id, 'products' === $content_type ? 'product_cat' : 'category', array( 'fields' => 'ids' ) );
					$checks[] = ! is_wp_error( $terms ) && ! empty( $terms );
				}

				if ( 'products' === $content_type && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $item_id );
					if ( $product ) {
						$checks[] = '' !== trim( $product->get_short_description() );
						$checks[] = '' !== trim( $product->get_sku() );
						$checks[] = '' !== trim( $product->get_regular_price() );
					}
				} else {
					$checks[] = '' !== trim( $post->post_excerpt );
				}
			}
		}

		$score = $checks ? (int) round( 100 * count( array_filter( $checks ) ) / count( $checks ) ) : 0;

		/**
		 * Lets modules enrich or replace object KPI.
		 *
		 * @param int    $score        Core score.
		 * @param string $content_type Type.
		 * @param int    $item_id      ID.
		 */
		$score = apply_filters( 'taxocommerce_core_content_kpi', $score, $content_type, $item_id );
		return max( 0, min( 100, absint( $score ) ) );
	}

	/**
	 * Post type mapping.
	 *
	 * @param string $content_type Type.
	 * @return string
	 */
	private function post_type_for_content( $content_type ) {
		if ( 'products' === $content_type ) {
			return 'product';
		}
		return 'pages' === $content_type ? 'page' : 'post';
	}

	/**
	 * Editable statuses.
	 *
	 * @return array
	 */
	private function editable_statuses() {
		return array(
			'publish' => __( 'Publicado', 'taxocommerce-core' ),
			'draft'   => __( 'Borrador', 'taxocommerce-core' ),
			'pending' => __( 'Pendiente de revisión', 'taxocommerce-core' ),
			'private' => __( 'Privado', 'taxocommerce-core' ),
			'trash'   => __( 'Papelera', 'taxocommerce-core' ),
		);
	}

	/**
	 * Status selector.
	 *
	 * @param string $current Current status.
	 * @return void
	 */
	private function render_status_select( $current ) {
		?>
		<select id="tc-status" name="status">
			<?php foreach ( $this->editable_statuses() as $status => $label ) : ?>
				<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $current, $status ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Core edit URL.
	 *
	 * @param string $content_type Type.
	 * @param int    $item_id      ID.
	 * @return string
	 */
	private function core_edit_url( $content_type, $item_id ) {
		return add_query_arg(
			array(
				'page'      => self::PAGE_SLUG,
				'tc_type'   => $content_type,
				'tc_action' => 'edit',
				'item_id'   => $item_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Pagination.
	 *
	 * @param int    $total_pages  Total pages.
	 * @param int    $current_page Current page.
	 * @param string $content_type Type.
	 * @return void
	 */
	private function render_pagination( $total_pages, $current_page, $content_type ) {
		if ( $total_pages < 2 ) {
			return;
		}

		$args = array(
			'page'    => self::PAGE_SLUG,
			'tc_type' => $content_type,
		);

		foreach ( array( 's', 'tc_status', 'tc_category' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
			}
		}

		$base  = add_query_arg( $args, admin_url( 'admin.php' ) );
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%', $base ),
				'format'    => '',
				'current'   => max( 1, absint( $current_page ) ),
				'total'     => max( 1, absint( $total_pages ) ),
				'type'      => 'list',
				'prev_text' => __( '&laquo; Anterior', 'taxocommerce-core' ),
				'next_text' => __( 'Siguiente &raquo;', 'taxocommerce-core' ),
			)
		);

		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}
}
