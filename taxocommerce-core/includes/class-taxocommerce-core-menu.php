<?php
/**
 * TaxoCommerce Core menu manager.
 *
 * @package TaxoCommerceCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a native WordPress menu from the TaxoCommerce SEO hierarchy.
 */
final class TaxoCommerce_Core_Menu {

	const MENU_ID_OPTION       = 'taxocommerce_core_menu_id';
	const BACKUP_OPTION        = 'taxocommerce_core_menu_location_backup';
	const GENERATED_META       = '_taxocommerce_core_generated_menu_item';
	const GENERATED_SOURCE_META= '_taxocommerce_core_generated_source';

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
	 * Register write actions.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_taxocommerce_core_sync_menu', array( $this, 'handle_sync_menu' ) );
		add_action( 'admin_post_taxocommerce_core_activate_menu', array( $this, 'handle_activate_menu' ) );
		add_action( 'admin_post_taxocommerce_core_restore_menu', array( $this, 'handle_restore_menu' ) );
	}

	/**
	 * Render menu manager.
	 *
	 * @return void
	 */
	public function render() {
		$this->guard();

		$tree       = $this->build_tree();
		$menu_id    = $this->get_menu_id();
		$menu       = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;
		$locations  = get_registered_nav_menus();
		$active_map = get_theme_mod( 'nav_menu_locations', array() );
		$result     = isset( $_GET['tc_result'] ) ? sanitize_key( wp_unslash( $_GET['tc_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice.
		$message    = isset( $_GET['tc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['tc_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice.
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Gestor de menús', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Propone un menú a partir de la Jerarquía SEO y lo convierte en un menú nativo de WordPress. Los elementos manuales se conservan al sincronizar.', 'taxocommerce-core' ); ?></p>

			<?php if ( 'ok' === $result && $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php elseif ( 'error' === $result && $message ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'Fuente:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html__( 'Cluster → Hub primario → Hub secundario → Categoría de producto. La jerarquía sigue siendo la fuente de la propuesta; WordPress conserva el menú publicado.', 'taxocommerce-core' ); ?></p></div>

			<?php $this->render_kpis( $tree, $menu_id ); ?>

			<h2><?php echo esc_html__( 'Propuesta automática', 'taxocommerce-core' ); ?></h2>
			<p><?php echo esc_html__( 'Esta vista no modifica nada. Muestra lo que se generará al sincronizar.', 'taxocommerce-core' ); ?></p>
			<?php $this->render_tree( $tree ); ?>

			<h2><?php echo esc_html__( 'Publicación del menú', 'taxocommerce-core' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
				<input type="hidden" name="action" value="taxocommerce_core_sync_menu">
				<?php wp_nonce_field( 'taxocommerce_core_sync_menu', 'taxocommerce_core_nonce' ); ?>
				<button class="button button-primary"><?php echo $menu ? esc_html__( 'Sincronizar propuesta', 'taxocommerce-core' ) : esc_html__( 'Crear menú desde la jerarquía', 'taxocommerce-core' ); ?></button>
			</form>

			<?php if ( $menu ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'nav-menus.php?action=edit&menu=' . absint( $menu_id ) ) ); ?>"><?php echo esc_html__( 'Editar manualmente en WordPress', 'taxocommerce-core' ); ?></a>
			<?php endif; ?>

			<?php if ( $menu && ! empty( $locations ) ) : ?>
				<hr>
				<h3><?php echo esc_html__( 'Ubicación en el tema', 'taxocommerce-core' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="taxocommerce_core_activate_menu">
					<?php wp_nonce_field( 'taxocommerce_core_activate_menu', 'taxocommerce_core_nonce' ); ?>
					<select name="menu_location">
						<?php foreach ( $locations as $location => $label ) : ?>
							<option value="<?php echo esc_attr( $location ); ?>" <?php selected( isset( $active_map[ $location ] ) && absint( $active_map[ $location ] ) === absint( $menu_id ) ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button"><?php echo esc_html__( 'Activar en esta ubicación', 'taxocommerce-core' ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( get_option( self::BACKUP_OPTION, false ) ) : ?>
				<p style="margin-top:12px;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="taxocommerce_core_restore_menu">
						<?php wp_nonce_field( 'taxocommerce_core_restore_menu', 'taxocommerce_core_nonce' ); ?>
						<button class="button"><?php echo esc_html__( 'Restaurar menú anterior del tema', 'taxocommerce-core' ); ?></button>
					</form>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Synchronize generated hierarchy items while keeping manual items.
	 *
	 * @return void
	 */
	public function handle_sync_menu() {
		$this->guard();
		check_admin_referer( 'taxocommerce_core_sync_menu', 'taxocommerce_core_nonce' );

		$menu_id = $this->get_or_create_menu();
		if ( is_wp_error( $menu_id ) ) {
			$this->redirect( 'error', $menu_id->get_error_message() );
		}

		$this->delete_generated_items( $menu_id );
		$result = $this->write_tree_to_menu( $this->build_tree(), $menu_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}

		$this->logger->info( 'Menú TaxoCommerce sincronizado desde la Jerarquía SEO.', array( 'menu_id' => absint( $menu_id ) ) );
		$this->redirect( 'ok', __( 'Menú sincronizado. Los elementos manuales se han conservado.', 'taxocommerce-core' ) );
	}

	/**
	 * Activate menu in a theme location.
	 *
	 * @return void
	 */
	public function handle_activate_menu() {
		$this->guard();
		check_admin_referer( 'taxocommerce_core_activate_menu', 'taxocommerce_core_nonce' );

		$menu_id   = $this->get_menu_id();
		$location  = isset( $_POST['menu_location'] ) ? sanitize_key( wp_unslash( $_POST['menu_location'] ) ) : '';
		$locations = get_registered_nav_menus();

		if ( ! $menu_id || ! isset( $locations[ $location ] ) ) {
			$this->redirect( 'error', __( 'La ubicación de menú seleccionada no es válida.', 'taxocommerce-core' ) );
		}

		$current = get_theme_mod( 'nav_menu_locations', array() );
		$current = is_array( $current ) ? $current : array();

		if ( ! get_option( self::BACKUP_OPTION, false ) ) {
			update_option(
				self::BACKUP_OPTION,
				array(
					'stylesheet' => get_stylesheet(),
					'locations'  => $current,
					'created_at' => current_time( 'mysql' ),
				),
				false
			);
		}

		$current[ $location ] = absint( $menu_id );
		set_theme_mod( 'nav_menu_locations', $current );

		$this->redirect( 'ok', __( 'Menú TaxoCommerce activado en la ubicación seleccionada.', 'taxocommerce-core' ) );
	}

	/**
	 * Restore theme menu locations saved before activation.
	 *
	 * @return void
	 */
	public function handle_restore_menu() {
		$this->guard();
		check_admin_referer( 'taxocommerce_core_restore_menu', 'taxocommerce_core_nonce' );

		$backup = get_option( self::BACKUP_OPTION, array() );
		if ( ! is_array( $backup ) || empty( $backup['locations'] ) || ( $backup['stylesheet'] ?? '' ) !== get_stylesheet() ) {
			$this->redirect( 'error', __( 'No existe una copia válida de las ubicaciones anteriores.', 'taxocommerce-core' ) );
		}

		set_theme_mod( 'nav_menu_locations', (array) $backup['locations'] );
		delete_option( self::BACKUP_OPTION );

		$this->redirect( 'ok', __( 'Se han restaurado las ubicaciones de menú anteriores.', 'taxocommerce-core' ) );
	}

	/**
	 * Build hierarchy tree from native Core metadata.
	 *
	 * @return array
	 */
	private function build_tree() {
		$clusters    = $this->pages_by_role( 'cluster' );
		$primaries   = $this->pages_by_role( 'hub_primary' );
		$secondaries = $this->pages_by_role( 'hub_secondary' );
		$categories  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		$categories  = is_wp_error( $categories ) ? array() : $categories;

		$primary_map = array();
		foreach ( $primaries as $primary ) {
			foreach ( $this->post_meta_ids( $primary->ID, TaxoCommerce_Core_Hierarchy::PARENT_CLUSTER_META ) as $cluster_id ) {
				$primary_map[ $cluster_id ][] = $primary;
			}
		}

		$secondary_map = array();
		foreach ( $secondaries as $secondary ) {
			foreach ( $this->post_meta_ids( $secondary->ID, TaxoCommerce_Core_Hierarchy::PARENT_PRIMARY_META ) as $primary_id ) {
				$secondary_map[ $primary_id ][] = $secondary;
			}
		}

		$category_map = array();
		foreach ( $categories as $category ) {
			foreach ( $this->term_meta_ids( $category->term_id, TaxoCommerce_Core_Hierarchy::PARENT_SECONDARY_TERM_META ) as $secondary_id ) {
				$category_map[ $secondary_id ][] = $category;
			}
		}

		$tree = array();
		foreach ( $clusters as $cluster ) {
			$node = array(
				'page'       => $cluster,
				'primaries'  => array(),
			);
			foreach ( $primary_map[ $cluster->ID ] ?? array() as $primary ) {
				$primary_node = array(
					'page'        => $primary,
					'secondaries' => array(),
				);
				foreach ( $secondary_map[ $primary->ID ] ?? array() as $secondary ) {
					$primary_node['secondaries'][] = array(
						'page'       => $secondary,
						'categories' => $category_map[ $secondary->ID ] ?? array(),
					);
				}
				$node['primaries'][] = $primary_node;
			}
			$tree[] = $node;
		}

		return $tree;
	}

	/**
	 * Render proposal tree.
	 *
	 * @param array $tree Tree.
	 * @return void
	 */
	private function render_tree( $tree ) {
		if ( empty( $tree ) ) {
			echo '<p>' . esc_html__( 'No hay una jerarquía suficiente para proponer el menú.', 'taxocommerce-core' ) . '</p>';
			return;
		}

		echo '<div style="max-width:1100px;">';
		foreach ( $tree as $cluster ) {
			echo '<div class="card" style="max-width:none;margin-bottom:12px;">';
			echo '<strong>' . esc_html( get_the_title( $cluster['page'] ) ) . '</strong>';
			if ( empty( $cluster['primaries'] ) ) {
				echo '<p><em>' . esc_html__( 'Sin hubs primarios.', 'taxocommerce-core' ) . '</em></p>';
			}
			foreach ( $cluster['primaries'] as $primary ) {
				echo '<div style="margin:8px 0 0 20px;">↳ <strong>' . esc_html( get_the_title( $primary['page'] ) ) . '</strong>';
				foreach ( $primary['secondaries'] as $secondary ) {
					echo '<div style="margin:6px 0 0 20px;">↳ ' . esc_html( get_the_title( $secondary['page'] ) );
					if ( ! empty( $secondary['categories'] ) ) {
						echo '<ul style="margin:5px 0 0 28px;list-style:disc;">';
						foreach ( $secondary['categories'] as $category ) {
							echo '<li>' . esc_html( $category->name ) . '</li>';
						}
						echo '</ul>';
					}
					echo '</div>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Write generated hierarchy into native WordPress menu.
	 *
	 * @param array $tree    Tree.
	 * @param int   $menu_id Menu ID.
	 * @return true|WP_Error
	 */
	private function write_tree_to_menu( $tree, $menu_id ) {
		$position = 1;

		foreach ( $tree as $cluster ) {
			$cluster_item = $this->add_page_item( $menu_id, $cluster['page'], 0, $position++ );
			if ( is_wp_error( $cluster_item ) ) {
				return $cluster_item;
			}

			foreach ( $cluster['primaries'] as $primary ) {
				$primary_item = $this->add_page_item( $menu_id, $primary['page'], $cluster_item, $position++ );
				if ( is_wp_error( $primary_item ) ) {
					return $primary_item;
				}

				foreach ( $primary['secondaries'] as $secondary ) {
					$secondary_item = $this->add_page_item( $menu_id, $secondary['page'], $primary_item, $position++ );
					if ( is_wp_error( $secondary_item ) ) {
						return $secondary_item;
					}

					foreach ( $secondary['categories'] as $category ) {
						$category_item = wp_update_nav_menu_item(
							$menu_id,
							0,
							array(
								'menu-item-object-id' => absint( $category->term_id ),
								'menu-item-object'    => 'product_cat',
								'menu-item-type'      => 'taxonomy',
								'menu-item-status'    => 'publish',
								'menu-item-parent-id' => absint( $secondary_item ),
								'menu-item-position'  => $position++,
								'menu-item-title'     => $category->name,
							)
						);
						if ( is_wp_error( $category_item ) ) {
							return $category_item;
						}
						$this->mark_generated( $category_item, 'product_cat:' . absint( $category->term_id ) );
					}
				}
			}
		}

		return true;
	}

	/**
	 * Add a page menu item.
	 *
	 * @param int     $menu_id   Menu ID.
	 * @param WP_Post $page      Page.
	 * @param int     $parent_id Parent menu item.
	 * @param int     $position  Position.
	 * @return int|WP_Error
	 */
	private function add_page_item( $menu_id, WP_Post $page, $parent_id, $position ) {
		$item_id = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-object-id' => absint( $page->ID ),
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => absint( $parent_id ),
				'menu-item-position'  => absint( $position ),
				'menu-item-title'     => get_the_title( $page ),
			)
		);

		if ( ! is_wp_error( $item_id ) ) {
			$this->mark_generated( $item_id, 'page:' . absint( $page->ID ) );
		}

		return $item_id;
	}

	/**
	 * Mark an item as generated so future syncs can replace only generated items.
	 *
	 * @param int    $item_id Item ID.
	 * @param string $source  Source descriptor.
	 * @return void
	 */
	private function mark_generated( $item_id, $source ) {
		update_post_meta( absint( $item_id ), self::GENERATED_META, 1 );
		update_post_meta( absint( $item_id ), self::GENERATED_SOURCE_META, sanitize_text_field( $source ) );
	}

	/**
	 * Delete only automatically generated items.
	 *
	 * @param int $menu_id Menu ID.
	 * @return void
	 */
	private function delete_generated_items( $menu_id ) {
		foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
			if ( absint( get_post_meta( $item->ID, self::GENERATED_META, true ) ) === 1 ) {
				wp_delete_post( $item->ID, true );
			}
		}
	}

	/**
	 * Get or create native menu.
	 *
	 * @return int|WP_Error
	 */
	private function get_or_create_menu() {
		$menu_id = $this->get_menu_id();
		if ( $menu_id ) {
			return $menu_id;
		}

		$existing = wp_get_nav_menu_object( 'TaxoCommerce' );
		if ( $existing && ! is_wp_error( $existing ) ) {
			$menu_id = absint( $existing->term_id );
		} else {
			$menu_id = wp_create_nav_menu( 'TaxoCommerce' );
			if ( is_wp_error( $menu_id ) ) {
				return $menu_id;
			}
		}

		update_option( self::MENU_ID_OPTION, absint( $menu_id ), false );
		return absint( $menu_id );
	}

	/**
	 * Get valid managed menu ID.
	 *
	 * @return int
	 */
	private function get_menu_id() {
		$menu_id = absint( get_option( self::MENU_ID_OPTION, 0 ) );
		return $menu_id && wp_get_nav_menu_object( $menu_id ) ? $menu_id : 0;
	}

	/**
	 * Pages assigned to one Core hierarchy role.
	 *
	 * @param string $role Role.
	 * @return WP_Post[]
	 */
	private function pages_by_role( $role ) {
		return get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => TaxoCommerce_Core_Hierarchy::ROLE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small admin-only structural page set.
				'meta_value'     => sanitize_key( $role ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small admin-only structural page set.
			)
		);
	}

	/**
	 * Numeric post-meta values.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return int[]
	 */
	private function post_meta_ids( $post_id, $key ) {
		return array_values( array_unique( array_filter( array_map( 'absint', get_post_meta( absint( $post_id ), $key, false ) ) ) ) );
	}

	/**
	 * Numeric term-meta values.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Meta key.
	 * @return int[]
	 */
	private function term_meta_ids( $term_id, $key ) {
		return array_values( array_unique( array_filter( array_map( 'absint', get_term_meta( absint( $term_id ), $key, false ) ) ) ) );
	}

	/**
	 * KPI cards.
	 *
	 * @param array $tree    Tree.
	 * @param int   $menu_id Menu ID.
	 * @return void
	 */
	private function render_kpis( $tree, $menu_id ) {
		$clusters = count( $tree );
		$primaries = 0;
		$secondaries = 0;
		$categories = 0;
		foreach ( $tree as $cluster ) {
			$primaries += count( $cluster['primaries'] );
			foreach ( $cluster['primaries'] as $primary ) {
				$secondaries += count( $primary['secondaries'] );
				foreach ( $primary['secondaries'] as $secondary ) {
					$categories += count( $secondary['categories'] );
				}
			}
		}
		$items = $menu_id ? count( (array) wp_get_nav_menu_items( $menu_id ) ) : 0;
		$cards = array(
			__( 'Clusters', 'taxocommerce-core' )        => $clusters,
			__( 'Hubs primarios', 'taxocommerce-core' )  => $primaries,
			__( 'Hubs secundarios', 'taxocommerce-core' )=> $secondaries,
			__( 'Categorías', 'taxocommerce-core' )      => $categories,
			__( 'Elementos publicados', 'taxocommerce-core' ) => $items,
		);
		?>
		<div style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0;">
			<?php foreach ( $cards as $label => $value ) : ?>
				<div class="card" style="min-width:150px;margin:0;padding:12px 16px;"><strong style="display:block;font-size:22px;"><?php echo esc_html( (string) $value ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Redirect helper.
	 *
	 * @param string $result  Result key.
	 * @param string $message Message.
	 * @return void
	 */
	private function redirect( $result, $message ) {
		$url = add_query_arg(
			array(
				'page'       => 'taxocommerce-core-menu',
				'tc_result'  => sanitize_key( $result ),
				'tc_message' => sanitize_text_field( $message ),
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Permission guard.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'No tienes permisos para gestionar TaxoCommerce Core.', 'taxocommerce-core' ) );
		}
	}
}
