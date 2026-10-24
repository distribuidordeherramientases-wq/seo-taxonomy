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
        TaxoCommerce_Core_Editor_UI::register_hooks();
        TaxoCommerce_Core_Architecture_Report::register_hooks();
	}

	/**
	 * Main page.
	 *
	 * @return void
	 */
    public function render() {
        $this->guard();

        $content_type = $this->request_content_type();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor routing.
        $action = isset( $_GET['tc_action'] ) ? sanitize_key( wp_unslash( $_GET['tc_action'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor routing.
        $item_id = isset( $_GET['item_id'] ) ? absint( $_GET['item_id'] ) : 0;

        if ( 'faqs' === $content_type && class_exists( 'TaxoCommerce_FAQs' ) ) {
            echo '<div class="wrap tc-content-overview tcc-family-screen">';
            $this->render_tabs( $content_type );
            TaxoCommerce_FAQs::render();
            echo '</div>';
            return;
        }
        if ( ! in_array( $content_type, array( 'anomalies', 'architecture' ), true ) && 'edit' === $action && $item_id ) {
            echo '<div class="wrap tc-content-editor-wrap">';
            $this->render_editor( $content_type, $item_id );
            echo '</div>';
            return;
        }
        ?>
        <div class="wrap tc-content-overview tcc-family-screen">
            <header class="tcc-page-header">
                <span class="tcc-page-header__icon" aria-hidden="true"><span class="dashicons dashicons-edit-page"></span></span>
                <div class="tcc-page-header__content">
                    <p class="tcc-page-header__eyebrow"><?php esc_html_e( 'TaxoCommerce Core', 'taxocommerce-core' ); ?></p>
                    <h1><?php echo esc_html__( 'Gestor de contenidos', 'taxocommerce-core' ); ?></h1>
                    <p><?php echo esc_html__( 'Administra contenidos y catálogo en un único lugar, manteniendo WordPress y WooCommerce como fuente canónica.', 'taxocommerce-core' ); ?></p>
                </div>
            </header>
            <?php $this->render_tabs( $content_type ); ?>
            <?php if ( 'anomalies' === $content_type ) : ?>
                <?php taxocommerce_core_anomalies_render(); ?>
            <?php elseif ( 'architecture' === $content_type ) : ?>
                <?php TaxoCommerce_Core_Architecture_Report::render(); ?>
            <?php else : ?>
            <?php TaxoCommerce_Core_Editorial_Guide::render( $content_type, 'overview' ); ?>

            <?php if ( isset( $_GET['updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only confirmation. ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Contenido actualizado.', 'taxocommerce-core' ); ?></p></div>
            <?php endif; ?>

            <?php
            $this->render_kpis( $content_type );
            $this->render_filters( $content_type );
            $this->render_list( $content_type );
            ?>
            <?php endif; ?>
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

        // A trash action must not rewrite the semantic relationships.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
        $next_status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
        if ( 'trash' !== $next_status ) {
            $vocab_result = TaxoCommerce_Core_Vocabulary::save( $content_type, $item_id );
            if ( is_wp_error( $vocab_result ) ) {
                wp_die( esc_html( $vocab_result->get_error_message() ) );
            }
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
		return in_array( $type, array( 'products', 'categories', 'posts', 'pages', 'faqs', 'anomalies', 'architecture' ), true ) ? $type : 'products';
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
            'architecture' => __( 'Arquitectura SEO', 'taxocommerce-core' ),
            'faqs'       => class_exists( 'TaxoCommerce_FAQs' ) ? __( 'FAQs', 'taxocommerce-core' ) : '',
            'anomalies'  => __( 'Anomalías', 'taxocommerce-core' ),
		);
		?>
		<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Opciones de contenidos', 'taxocommerce-core' ); ?>">
			<?php foreach ( $tabs as $type => $label ) : if ( '' === $label ) { continue; } ?>
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
		<div class="tc-content-kpis">
			<?php foreach ( $cards as $label => $value ) : ?>
				<div class="tc-content-kpi">
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search controls.
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search controls.
        $status = isset( $_GET['tc_status'] ) ? sanitize_key( wp_unslash( $_GET['tc_status'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search controls.
        $cat_id = isset( $_GET['tc_category'] ) ? absint( $_GET['tc_category'] ) : 0;
        ?>
        <form method="get" class="tc-content-filters" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
            <input type="hidden" name="tc_type" value="<?php echo esc_attr( $content_type ); ?>">
            <div class="tc-content-filter-field tc-content-filter-search">
                <label for="tc-content-search"><?php echo esc_html__( 'Buscar contenido', 'taxocommerce-core' ); ?></label>
                <input id="tc-content-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Título o descripción…', 'taxocommerce-core' ); ?>">
            </div>

            <?php if ( 'categories' !== $content_type ) : ?>
                <div class="tc-content-filter-field">
                    <label for="tc-content-status"><?php echo esc_html__( 'Estado', 'taxocommerce-core' ); ?></label>
                    <select id="tc-content-status" name="tc_status">
                        <option value=""><?php echo esc_html__( 'Todos los estados', 'taxocommerce-core' ); ?></option>
                        <?php foreach ( $this->editable_statuses() as $status_key => $status_label ) : ?>
                            <option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status, $status_key ); ?>><?php echo esc_html( $status_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ( in_array( $content_type, array( 'products', 'posts' ), true ) ) : ?>
                <div class="tc-content-filter-field tc-content-filter-category">
                    <label><?php echo esc_html__( 'Categoría', 'taxocommerce-core' ); ?></label>
                    <?php
                    $taxonomy = 'products' === $content_type ? 'product_cat' : 'category';
                    $selected = array();
                    if ( $cat_id ) {
                        $term = get_term( $cat_id, $taxonomy );
                        if ( $term && ! is_wp_error( $term ) ) {
                            $selected[] = array( 'id' => $cat_id, 'label' => $term->name );
                        }
                    }
                    TaxoCommerce_Core_Vocabulary::render_picker(
                        'tc_category',
                        'taxonomy',
                        $taxonomy,
                        $selected,
                        __( 'Buscar categoría…', 'taxocommerce-core' ),
                        true
                    );
                    ?>
                </div>
            <?php endif; ?>

            <div class="tc-content-filter-actions">
                <button class="button button-primary"><?php echo esc_html__( 'Aplicar filtros', 'taxocommerce-core' ); ?></button>
                <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tc_type' => $content_type ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Limpiar', 'taxocommerce-core' ); ?></a>
            </div>
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
		<div class="tc-overview-table-scroll"><table class="widefat striped tc-overview-table">
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
		</table></div>
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
		<div class="tc-overview-table-scroll"><table class="widefat striped tc-overview-table">
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
		</table></div>
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
        TaxoCommerce_Core_Editor_UI::product( $product, $this->content_score( 'products', $item_id ) );
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
        TaxoCommerce_Core_Editor_UI::category( $term, $this->content_score( 'categories', $item_id ) );
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
        TaxoCommerce_Core_Editor_UI::post( $post, $this->content_score( $content_type, $item_id ), $content_type );
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
        // Editor header is rendered by TaxoCommerce_Core_Editor_UI.
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

        // All mutations are invoked from save_content(), which verifies nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
        $slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
        $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : $product->get_status();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
        $sku = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : $product->get_sku( 'edit' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
        $stock_status = isset( $_POST['stock_status'] ) ? sanitize_key( wp_unslash( $_POST['stock_status'] ) ) : $product->get_stock_status();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
        $excerpt = isset( $_POST['excerpt'] ) ? wp_kses_post( wp_unslash( $_POST['excerpt'] ) ) : $product->get_short_description( 'edit' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
        $body = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : $product->get_description( 'edit' );

        if ( ! $title || ! isset( $this->editable_statuses()[ $status ] ) || ! in_array( $stock_status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
            return new WP_Error( 'taxocommerce_core_product_invalid', __( 'Los datos del producto no son válidos.', 'taxocommerce-core' ) );
        }
        if ( 'trash' === $status ) {
            return wp_trash_post( $item_id ) ? true : new WP_Error( 'taxocommerce_core_product_trash', __( 'No se ha podido mover el producto a la papelera.', 'taxocommerce-core' ) );
        }

        $attributes = TaxoCommerce_Core_Editor_UI::read_product_attributes( $product );
        if ( is_wp_error( $attributes ) ) {
            return $attributes;
        }
        $image_id = TaxoCommerce_Core_Editor_UI::image_id();
        if ( is_wp_error( $image_id ) ) {
            return $image_id;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
        $tax_present = ! empty( $_POST['tc_tax_present'] );
        if ( $tax_present ) {
            $category_ids = TaxoCommerce_Core_Editor_UI::selected_terms( 'product_cat' );
            $tag_ids = TaxoCommerce_Core_Editor_UI::selected_terms( 'product_tag' );
            if ( is_wp_error( $category_ids ) ) {
                return $category_ids;
            }
            if ( is_wp_error( $tag_ids ) ) {
                return $tag_ids;
            }
        } else {
            // Preserve compatibility with pre-0.4.6 forms.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
            $category_ids = isset( $_POST['category_ids'] ) && is_array( $_POST['category_ids'] )
                ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['category_ids'] ) ) ) ) ) : $product->get_category_ids();
            $tag_ids = $product->get_tag_ids();
        }

        try {
            $product->set_name( $title );
            $product->set_slug( $slug );
            $product->set_status( $status );
            $product->set_sku( $sku );
            $product->set_stock_status( $stock_status );
            $product->set_short_description( $excerpt );
            $product->set_description( $body );
            if ( ! $product->is_type( 'variation' ) ) {
                $product->set_category_ids( $category_ids );
                $product->set_tag_ids( $tag_ids );
                if ( null !== $image_id ) {
                    $product->set_image_id( $image_id );
                }
            }
            if ( is_array( $attributes ) ) {
                $product->set_attributes( $attributes );
            }
            if ( $product->is_type( 'simple' ) || $product->is_type( 'external' ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
                $regular_price = isset( $_POST['regular_price'] ) ? wc_format_decimal( wp_unslash( $_POST['regular_price'] ) ) : $product->get_regular_price( 'edit' );
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
                $sale_price = isset( $_POST['sale_price'] ) ? wc_format_decimal( wp_unslash( $_POST['sale_price'] ) ) : $product->get_sale_price( 'edit' );
                $product->set_regular_price( $regular_price );
                $product->set_sale_price( $sale_price );
            }
            if ( $product->is_type( 'external' ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
                $url = isset( $_POST['product_url'] ) ? esc_url_raw( wp_unslash( $_POST['product_url'] ) ) : $product->get_product_url( 'edit' );
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save_content().
                $button_text = isset( $_POST['button_text'] ) ? sanitize_text_field( wp_unslash( $_POST['button_text'] ) ) : $product->get_button_text( 'edit' );
                $product->set_product_url( $url );
                $product->set_button_text( $button_text );
            }
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_content() verified nonce.
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_content() verified nonce.
        $slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_content() verified nonce.
        $parent = isset( $_POST['parent'] ) ? absint( wp_unslash( $_POST['parent'] ) ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_content() verified nonce.
        $description = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';
        $image_id = TaxoCommerce_Core_Editor_UI::image_id();
        if ( is_wp_error( $image_id ) ) {
            return $image_id;
        }
        if ( ! $name || $parent === $item_id ) {
            return new WP_Error( 'taxocommerce_core_category_invalid', __( 'Los datos de la categoría no son válidos.', 'taxocommerce-core' ) );
        }
        if ( $parent ) {
            $parent_term = get_term( $parent, 'product_cat' );
            if ( ! $parent_term || is_wp_error( $parent_term ) ) {
                return new WP_Error( 'taxocommerce_core_category_parent', __( 'La categoría superior no existe.', 'taxocommerce-core' ) );
            }
        }
        $result = wp_update_term(
            $item_id,
            'product_cat',
            array(
                'name' => $name,
                'slug' => $slug,
                'parent' => $parent,
                'description' => $description,
            )
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( null !== $image_id ) {
            update_term_meta( $item_id, 'thumbnail_id', $image_id );
        }
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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_content() verified nonce.
        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_content() verified nonce.
        $slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_content() verified nonce.
        $status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : $post->post_status;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_content() verified nonce.
        $excerpt = isset( $_POST['excerpt'] ) ? wp_kses_post( wp_unslash( $_POST['excerpt'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_content() verified nonce.
        $body = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : '';

        if ( ! $title || ! isset( $this->editable_statuses()[ $status ] ) ) {
            return new WP_Error( 'taxocommerce_core_post_invalid', __( 'Los datos del contenido no son válidos.', 'taxocommerce-core' ) );
        }
        if ( 'trash' === $status ) {
            return wp_trash_post( $item_id ) ? true : new WP_Error( 'taxocommerce_core_post_trash', __( 'No se ha podido mover el contenido a la papelera.', 'taxocommerce-core' ) );
        }
        $image_id = TaxoCommerce_Core_Editor_UI::image_id();
        if ( is_wp_error( $image_id ) ) {
            return $image_id;
        }

        // Validate native category/tag IDs before saving the post.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_content() verified nonce.
        $tax_present = ! empty( $_POST['tc_tax_present'] );
        if ( 'posts' === $content_type && $tax_present ) {
            $category_ids = TaxoCommerce_Core_Editor_UI::selected_terms( 'category' );
            $tag_ids = TaxoCommerce_Core_Editor_UI::selected_terms( 'post_tag' );
            if ( is_wp_error( $category_ids ) ) {
                return $category_ids;
            }
            if ( is_wp_error( $tag_ids ) ) {
                return $tag_ids;
            }
        }

        $result = wp_update_post(
            array(
                'ID' => $item_id,
                'post_title' => $title,
                'post_name' => $slug,
                'post_status' => $status,
                'post_excerpt' => $excerpt,
                'post_content' => $body,
            ),
            true
        );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( 'posts' === $content_type ) {
            if ( $tax_present ) {
                $categories_saved = wp_set_post_categories( $item_id, $category_ids, false );
                $tags_saved = wp_set_post_terms( $item_id, $tag_ids, 'post_tag', false );
                if ( is_wp_error( $categories_saved ) ) {
                    return $categories_saved;
                }
                if ( is_wp_error( $tags_saved ) ) {
                    return $tags_saved;
                }
            } else {
                // Backward compatibility for earlier Core editor submissions.
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- save_content() verified nonce.
                if ( isset( $_POST['category_ids'] ) && is_array( $_POST['category_ids'] ) ) {
                    $ids = array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['category_ids'] ) ) ) ) );
                    wp_set_post_categories( $item_id, $ids, false );
                }
            }
        }
        if ( null !== $image_id ) {
            if ( $image_id ) {
                set_post_thumbnail( $item_id, $image_id );
            } else {
                delete_post_thumbnail( $item_id );
            }
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
