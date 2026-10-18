<?php
/**
 * TaxoCommerce Core hierarchy manager.
 *
 * @package TaxoCommerceCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages SEO page roles and hierarchy using native WordPress metadata.
 */
final class TaxoCommerce_Core_Hierarchy {

	const ROLE_META                  = '_taxocommerce_core_seo_role';
	const PARENT_CLUSTER_META        = '_taxocommerce_core_parent_cluster';
	const PARENT_PRIMARY_META        = '_taxocommerce_core_parent_hub_primary';
	const PARENT_SECONDARY_TERM_META = '_taxocommerce_core_parent_hub_secondary';
	const LEGACY_IMPORT_OPTION       = 'taxocommerce_core_hierarchy_legacy_import_v1';

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
	 * Registers admin actions.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'maybe_import_legacy' ), 30 );
		add_action( 'admin_post_taxocommerce_core_save_page_role', array( $this, 'handle_save_page_role' ) );
		add_action( 'admin_post_taxocommerce_core_add_hierarchy_relation', array( $this, 'handle_add_relation' ) );
		add_action( 'admin_post_taxocommerce_core_delete_hierarchy_relation', array( $this, 'handle_delete_relation' ) );
	}

	/**
	 * Allowed page roles.
	 *
	 * @return array
	 */
	public function roles() {
		return array(
			'cluster'        => __( 'Cluster', 'taxocommerce-core' ),
			'hub_primary'    => __( 'Hub primario', 'taxocommerce-core' ),
			'hub_secondary'  => __( 'Hub secundario', 'taxocommerce-core' ),
			'landing'        => __( 'Landing', 'taxocommerce-core' ),
			'corporate_page' => __( 'Página corporativa', 'taxocommerce-core' ),
		);
	}

	/**
	 * Returns a page role.
	 *
	 * @param int $page_id Page ID.
	 * @return string
	 */
	public function get_page_role( $page_id ) {
		$role = sanitize_key( (string) get_post_meta( absint( $page_id ), self::ROLE_META, true ) );
		return isset( $this->roles()[ $role ] ) ? $role : '';
	}

	/**
	 * Assigns one role to a page.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $role    Role, or empty to remove.
	 * @return true|WP_Error
	 */
	public function set_page_role( $page_id, $role ) {
		$page_id = absint( $page_id );
		$role    = sanitize_key( (string) $role );

		if ( ! $page_id || 'page' !== get_post_type( $page_id ) ) {
			return new WP_Error( 'taxocommerce_core_invalid_page', __( 'La página indicada no existe.', 'taxocommerce-core' ) );
		}

		if ( '' !== $role && ! isset( $this->roles()[ $role ] ) ) {
			return new WP_Error( 'taxocommerce_core_invalid_role', __( 'El rol SEO indicado no es válido.', 'taxocommerce-core' ) );
		}

		$current = $this->get_page_role( $page_id );
		if ( $current === $role ) {
			return true;
		}

		if ( $current && $this->page_has_hierarchy_links( $page_id ) ) {
			return new WP_Error(
				'taxocommerce_core_role_has_links',
				__( 'Esta página ya participa en la jerarquía. Desvincúlala antes de cambiar o quitar su rol.', 'taxocommerce-core' )
			);
		}

		if ( '' === $role ) {
			delete_post_meta( $page_id, self::ROLE_META );
		} else {
			update_post_meta( $page_id, self::ROLE_META, $role );
		}

		$this->logger->info(
			'Rol SEO de página actualizado.',
			array(
				'page_id' => $page_id,
				'role'    => $role,
			)
		);

		return true;
	}

	/**
	 * One-time import from the current TaxoCommerce SEO plugin.
	 *
	 * Uses the legacy plugin public helper APIs instead of reading its tables.
	 *
	 * @return void
	 */
	public function maybe_import_legacy() {
		if ( get_option( self::LEGACY_IMPORT_OPTION, false ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'seo_page_editor_get_structure_tree' ) || ! function_exists( 'seo_page_editor_get_pages_by_roles' ) ) {
			return;
		}

		$report = array(
			'roles'      => 0,
			'relations'  => 0,
			'categories' => 0,
			'imported_at'=> current_time( 'mysql' ),
		);

		$rows = seo_page_editor_get_pages_by_roles( array_keys( $this->roles() ) );
		foreach ( (array) $rows as $row ) {
			$page_id = absint( isset( $row->ID ) ? $row->ID : 0 );
			$role    = sanitize_key( isset( $row->seo_role ) ? (string) $row->seo_role : '' );

			if ( ! $page_id || ! isset( $this->roles()[ $role ] ) || 'page' !== get_post_type( $page_id ) ) {
				continue;
			}

			if ( ! get_post_meta( $page_id, self::ROLE_META, true ) ) {
				update_post_meta( $page_id, self::ROLE_META, $role );
				$report['roles']++;
			}
		}

		$tree = seo_page_editor_get_structure_tree();
		foreach ( (array) $tree as $cluster ) {
			$cluster_id = absint( isset( $cluster['id'] ) ? $cluster['id'] : 0 );
			if ( ! $cluster_id ) {
				continue;
			}
			update_post_meta( $cluster_id, self::ROLE_META, 'cluster' );

			foreach ( (array) $cluster['primaries'] as $primary ) {
				$primary_id = absint( isset( $primary['id'] ) ? $primary['id'] : 0 );
				if ( ! $primary_id ) {
					continue;
				}

				update_post_meta( $primary_id, self::ROLE_META, 'hub_primary' );
				if ( $this->add_unique_post_meta_id( $primary_id, self::PARENT_CLUSTER_META, $cluster_id ) ) {
					$report['relations']++;
				}

				foreach ( (array) $primary['secondaries'] as $secondary ) {
					$secondary_id = absint( isset( $secondary['id'] ) ? $secondary['id'] : 0 );
					if ( ! $secondary_id ) {
						continue;
					}

					update_post_meta( $secondary_id, self::ROLE_META, 'hub_secondary' );
					if ( $this->add_unique_post_meta_id( $secondary_id, self::PARENT_PRIMARY_META, $primary_id ) ) {
						$report['relations']++;
					}

					foreach ( (array) $secondary['categories'] as $category ) {
						$category_id = absint( isset( $category['id'] ) ? $category['id'] : 0 );
						if ( $category_id && $this->add_unique_term_meta_id( $category_id, self::PARENT_SECONDARY_TERM_META, $secondary_id ) ) {
							$report['categories']++;
						}
					}
				}
			}
		}

		update_option( self::LEGACY_IMPORT_OPTION, $report, false );
		$this->logger->info( 'Jerarquía SEO importada desde el plugin actual.', $report );
	}

	/**
	 * Renders hierarchy administration.
	 *
	 * @return void
	 */
	public function render() {
		$this->guard();

		$import_report = get_option( self::LEGACY_IMPORT_OPTION, array() );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Jerarquía SEO', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Asigna un rol estructural a las páginas y enlaza Cluster → Hub primario → Hub secundario → Categoría de producto.', 'taxocommerce-core' ); ?></p>

			<div class="notice notice-info inline">
				<p><strong><?php echo esc_html__( 'Equivalente actual:', 'taxocommerce-core' ); ?></strong>
				<?php echo esc_html__( 'este sistema sustituye funcionalmente la jerarquía basada en seo_nodes y seo_relations del plugin actual, pero utiliza páginas, post_meta y term_meta nativos de WordPress.', 'taxocommerce-core' ); ?></p>
			</div>

			<?php if ( is_array( $import_report ) && ! empty( $import_report ) ) : ?>
				<div class="notice notice-success inline">
					<p>
						<strong><?php echo esc_html__( 'Importación inicial realizada:', 'taxocommerce-core' ); ?></strong>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: roles, 2: page relations, 3: categories. */
								__( '%1$d roles, %2$d relaciones entre páginas y %3$d asociaciones con categorías.', 'taxocommerce-core' ),
								absint( $import_report['roles'] ?? 0 ),
								absint( $import_report['relations'] ?? 0 ),
								absint( $import_report['categories'] ?? 0 )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php $this->render_notices(); ?>
			<?php $this->render_kpis(); ?>

			<hr>
			<h2><?php echo esc_html__( '1. Roles de las páginas', 'taxocommerce-core' ); ?></h2>
			<p><?php echo esc_html__( 'Cada página puede tener un único rol SEO estructural. Una página ya vinculada debe desvincularse antes de cambiar de rol.', 'taxocommerce-core' ); ?></p>
			<?php $this->render_page_roles(); ?>

			<hr>
			<h2><?php echo esc_html__( '2. Asociaciones jerárquicas', 'taxocommerce-core' ); ?></h2>
			<?php $this->render_relation_forms(); ?>

			<hr>
			<h2><?php echo esc_html__( '3. Árbol actual', 'taxocommerce-core' ); ?></h2>
			<?php $this->render_tree(); ?>
		</div>
		<?php
	}

	/**
	 * Saves page role action.
	 *
	 * @return void
	 */
	public function handle_save_page_role() {
		$this->guard();
		check_admin_referer( 'taxocommerce_core_save_page_role', 'taxocommerce_core_nonce' );

		$page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$role    = isset( $_POST['seo_role'] ) ? sanitize_key( wp_unslash( $_POST['seo_role'] ) ) : '';
		$result  = $this->set_page_role( $page_id, $role );

		$this->redirect_with_result( $result, 'role' );
	}

	/**
	 * Adds hierarchy relation action.
	 *
	 * @return void
	 */
	public function handle_add_relation() {
		$this->guard();
		check_admin_referer( 'taxocommerce_core_add_hierarchy_relation', 'taxocommerce_core_nonce' );

		$type      = isset( $_POST['relation_type'] ) ? sanitize_key( wp_unslash( $_POST['relation_type'] ) ) : '';
		$source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
		$target_id = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;

		$result = $this->add_relation( $type, $source_id, $target_id );
		$this->redirect_with_result( $result, 'relation' );
	}

	/**
	 * Deletes one relation.
	 *
	 * @return void
	 */
	public function handle_delete_relation() {
		$this->guard();
		check_admin_referer( 'taxocommerce_core_delete_hierarchy_relation', 'taxocommerce_core_nonce' );

		$type      = isset( $_POST['relation_type'] ) ? sanitize_key( wp_unslash( $_POST['relation_type'] ) ) : '';
		$source_id = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
		$target_id = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;

		$result = $this->delete_relation( $type, $source_id, $target_id );
		$this->redirect_with_result( $result, 'unlink' );
	}

	/**
	 * Adds one validated relation.
	 *
	 * @param string $type      Relation type.
	 * @param int    $source_id Source ID.
	 * @param int    $target_id Target ID.
	 * @return true|WP_Error
	 */
	private function add_relation( $type, $source_id, $target_id ) {
		if ( ! $source_id || ! $target_id ) {
			return new WP_Error( 'taxocommerce_core_relation_missing', __( 'Debes seleccionar ambos extremos de la relación.', 'taxocommerce-core' ) );
		}

		if ( 'cluster_to_primary' === $type ) {
			if ( 'cluster' !== $this->get_page_role( $source_id ) || 'hub_primary' !== $this->get_page_role( $target_id ) ) {
				return new WP_Error( 'taxocommerce_core_relation_roles', __( 'La relación debe ser Cluster → Hub primario.', 'taxocommerce-core' ) );
			}
			$this->add_unique_post_meta_id( $target_id, self::PARENT_CLUSTER_META, $source_id );
		} elseif ( 'primary_to_secondary' === $type ) {
			if ( 'hub_primary' !== $this->get_page_role( $source_id ) || 'hub_secondary' !== $this->get_page_role( $target_id ) ) {
				return new WP_Error( 'taxocommerce_core_relation_roles', __( 'La relación debe ser Hub primario → Hub secundario.', 'taxocommerce-core' ) );
			}
			$this->add_unique_post_meta_id( $target_id, self::PARENT_PRIMARY_META, $source_id );
		} elseif ( 'secondary_to_category' === $type ) {
			$term = get_term( $target_id, 'product_cat' );
			if ( 'hub_secondary' !== $this->get_page_role( $source_id ) || ! $term || is_wp_error( $term ) ) {
				return new WP_Error( 'taxocommerce_core_relation_roles', __( 'La relación debe ser Hub secundario → Categoría de producto.', 'taxocommerce-core' ) );
			}
			$this->add_unique_term_meta_id( $target_id, self::PARENT_SECONDARY_TERM_META, $source_id );
		} else {
			return new WP_Error( 'taxocommerce_core_relation_invalid', __( 'Tipo de relación no válido.', 'taxocommerce-core' ) );
		}

		$this->logger->info(
			'Relación de jerarquía guardada.',
			array(
				'type'      => $type,
				'source_id' => $source_id,
				'target_id' => $target_id,
			)
		);

		return true;
	}

	/**
	 * Deletes one relation.
	 *
	 * @param string $type      Relation type.
	 * @param int    $source_id Source ID.
	 * @param int    $target_id Target ID.
	 * @return true|WP_Error
	 */
	private function delete_relation( $type, $source_id, $target_id ) {
		if ( 'cluster_to_primary' === $type ) {
			delete_post_meta( $target_id, self::PARENT_CLUSTER_META, $source_id );
		} elseif ( 'primary_to_secondary' === $type ) {
			delete_post_meta( $target_id, self::PARENT_PRIMARY_META, $source_id );
		} elseif ( 'secondary_to_category' === $type ) {
			delete_term_meta( $target_id, self::PARENT_SECONDARY_TERM_META, $source_id );
		} else {
			return new WP_Error( 'taxocommerce_core_relation_invalid', __( 'Tipo de relación no válido.', 'taxocommerce-core' ) );
		}

		$this->logger->info(
			'Relación de jerarquía eliminada.',
			array(
				'type'      => $type,
				'source_id' => $source_id,
				'target_id' => $target_id,
			)
		);

		return true;
	}

	/**
	 * Role table.
	 *
	 * @return void
	 */
	private function render_page_roles() {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$filter = isset( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.

		$args = array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => 50,
			'orderby'        => 'title',
			'order'          => 'ASC',
			's'              => $search,
		);

		if ( $filter && isset( $this->roles()[ $filter ] ) ) {
			$args['meta_key']   = self::ROLE_META; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin-only hierarchy filter.
			$args['meta_value'] = $filter; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Admin-only hierarchy filter.
		}

		$query = new WP_Query( $args );
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:12px 0;">
			<input type="hidden" name="page" value="taxocommerce-core-hierarchy">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Buscar página', 'taxocommerce-core' ); ?>">
			<select name="role">
				<option value=""><?php echo esc_html__( 'Todos los roles', 'taxocommerce-core' ); ?></option>
				<?php foreach ( $this->roles() as $role => $label ) : ?>
					<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $filter, $role ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button"><?php echo esc_html__( 'Filtrar', 'taxocommerce-core' ); ?></button>
		</form>

		<table class="widefat striped">
			<thead><tr><th><?php echo esc_html__( 'Página', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Estado', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Rol SEO', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Acción', 'taxocommerce-core' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $query->posts ) ) : ?>
				<tr><td colspan="4"><?php echo esc_html__( 'No se han encontrado páginas.', 'taxocommerce-core' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $query->posts as $page ) : ?>
					<?php $current = $this->get_page_role( $page->ID ); ?>
					<tr>
						<td><strong><?php echo esc_html( get_the_title( $page ) ); ?></strong><br><small>#<?php echo esc_html( (string) $page->ID ); ?></small></td>
						<td><?php echo esc_html( $page->post_status ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;align-items:center;">
								<input type="hidden" name="action" value="taxocommerce_core_save_page_role">
								<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page->ID ); ?>">
								<?php wp_nonce_field( 'taxocommerce_core_save_page_role', 'taxocommerce_core_nonce' ); ?>
								<select name="seo_role">
									<option value=""><?php echo esc_html__( 'Sin rol', 'taxocommerce-core' ); ?></option>
									<?php foreach ( $this->roles() as $role => $label ) : ?>
										<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $current, $role ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<button class="button button-small"><?php echo esc_html__( 'Guardar', 'taxocommerce-core' ); ?></button>
							</form>
						</td>
						<td><a href="<?php echo esc_url( get_edit_post_link( $page->ID, '' ) ); ?>"><?php echo esc_html__( 'Editar página', 'taxocommerce-core' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
		wp_reset_postdata();
	}

	/**
	 * Relation forms.
	 *
	 * @return void
	 */
	private function render_relation_forms() {
		$clusters    = $this->pages_by_role( 'cluster' );
		$primaries   = $this->pages_by_role( 'hub_primary' );
		$secondaries = $this->pages_by_role( 'hub_secondary' );
		$categories  = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		$categories = is_wp_error( $categories ) ? array() : $categories;
		?>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;">
			<?php $this->render_relation_card( __( 'Cluster → Hub primario', 'taxocommerce-core' ), 'cluster_to_primary', $clusters, $primaries, false ); ?>
			<?php $this->render_relation_card( __( 'Hub primario → Hub secundario', 'taxocommerce-core' ), 'primary_to_secondary', $primaries, $secondaries, false ); ?>
			<?php $this->render_relation_card( __( 'Hub secundario → Categoría', 'taxocommerce-core' ), 'secondary_to_category', $secondaries, $categories, true ); ?>
		</div>
		<?php
	}

	/**
	 * One relation card.
	 *
	 * @param string $title       Title.
	 * @param string $type        Type.
	 * @param array  $sources     Sources.
	 * @param array  $targets     Targets.
	 * @param bool   $target_term Whether target is term.
	 * @return void
	 */
	private function render_relation_card( $title, $type, $sources, $targets, $target_term ) {
		?>
		<div class="card" style="max-width:none;">
			<h3><?php echo esc_html( $title ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="taxocommerce_core_add_hierarchy_relation">
				<input type="hidden" name="relation_type" value="<?php echo esc_attr( $type ); ?>">
				<?php wp_nonce_field( 'taxocommerce_core_add_hierarchy_relation', 'taxocommerce_core_nonce' ); ?>
				<p><select name="source_id" style="width:100%;"><option value=""><?php echo esc_html__( 'Seleccionar origen', 'taxocommerce-core' ); ?></option><?php foreach ( $sources as $source ) : ?><option value="<?php echo esc_attr( (string) $source->ID ); ?>"><?php echo esc_html( get_the_title( $source ) ); ?></option><?php endforeach; ?></select></p>
				<p><select name="target_id" style="width:100%;"><option value=""><?php echo esc_html__( 'Seleccionar destino', 'taxocommerce-core' ); ?></option><?php foreach ( $targets as $target ) : ?><?php $target_id = $target_term ? absint( $target->term_id ) : absint( $target->ID ); $target_name = $target_term ? $target->name : get_the_title( $target ); ?><option value="<?php echo esc_attr( (string) $target_id ); ?>"><?php echo esc_html( $target_name ); ?></option><?php endforeach; ?></select></p>
				<p><button class="button button-primary"><?php echo esc_html__( 'Asociar', 'taxocommerce-core' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Tree rendering.
	 *
	 * @return void
	 */
	private function render_tree() {
		$clusters    = $this->pages_by_role( 'cluster' );
		$primaries   = $this->pages_by_role( 'hub_primary' );
		$secondaries = $this->pages_by_role( 'hub_secondary' );
		$categories  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
		$categories  = is_wp_error( $categories ) ? array() : $categories;

		$primary_by_cluster = array();
		foreach ( $primaries as $primary ) {
			foreach ( $this->meta_ids( $primary->ID, self::PARENT_CLUSTER_META ) as $cluster_id ) {
				$primary_by_cluster[ $cluster_id ][] = $primary;
			}
		}

		$secondary_by_primary = array();
		foreach ( $secondaries as $secondary ) {
			foreach ( $this->meta_ids( $secondary->ID, self::PARENT_PRIMARY_META ) as $primary_id ) {
				$secondary_by_primary[ $primary_id ][] = $secondary;
			}
		}

		$category_by_secondary = array();
		foreach ( $categories as $category ) {
			foreach ( $this->term_meta_ids( $category->term_id, self::PARENT_SECONDARY_TERM_META ) as $secondary_id ) {
				$category_by_secondary[ $secondary_id ][] = $category;
			}
		}

		if ( empty( $clusters ) ) {
			echo '<p>' . esc_html__( 'Todavía no hay páginas con rol Cluster.', 'taxocommerce-core' ) . '</p>';
			return;
		}

		echo '<div style="max-width:1200px;">';
		foreach ( $clusters as $cluster ) {
			echo '<div class="card" style="max-width:none;margin-bottom:14px;">';
			echo '<h3>' . esc_html( get_the_title( $cluster ) ) . ' <small>#' . esc_html( (string) $cluster->ID ) . '</small></h3>';

			$cluster_primaries = $primary_by_cluster[ $cluster->ID ] ?? array();
			if ( empty( $cluster_primaries ) ) {
				echo '<p><em>' . esc_html__( 'Sin hubs primarios asociados.', 'taxocommerce-core' ) . '</em></p>';
			}

			foreach ( $cluster_primaries as $primary ) {
				echo '<div style="margin-left:20px;border-left:3px solid #ccd0d4;padding-left:12px;">';
				echo '<p><strong>↳ ' . esc_html( get_the_title( $primary ) ) . '</strong> ';
				$this->render_unlink_button( 'cluster_to_primary', $cluster->ID, $primary->ID );
				echo '</p>';

				$primary_secondaries = $secondary_by_primary[ $primary->ID ] ?? array();
				foreach ( $primary_secondaries as $secondary ) {
					echo '<div style="margin-left:20px;">';
					echo '<p>↳ <strong>' . esc_html( get_the_title( $secondary ) ) . '</strong> ';
					$this->render_unlink_button( 'primary_to_secondary', $primary->ID, $secondary->ID );
					echo '</p>';

					$secondary_categories = $category_by_secondary[ $secondary->ID ] ?? array();
					if ( empty( $secondary_categories ) ) {
						echo '<p style="margin-left:20px;"><em>' . esc_html__( 'Sin categorías asociadas.', 'taxocommerce-core' ) . '</em></p>';
					} else {
						echo '<ul style="margin-left:35px;list-style:disc;">';
						foreach ( $secondary_categories as $category ) {
							echo '<li>' . esc_html( $category->name ) . ' ';
							$this->render_unlink_button( 'secondary_to_category', $secondary->ID, $category->term_id );
							echo '</li>';
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
	 * Unlink form.
	 *
	 * @param string $type      Relation type.
	 * @param int    $source_id Source.
	 * @param int    $target_id Target.
	 * @return void
	 */
	private function render_unlink_button( $type, $source_id, $target_id ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
			<input type="hidden" name="action" value="taxocommerce_core_delete_hierarchy_relation">
			<input type="hidden" name="relation_type" value="<?php echo esc_attr( $type ); ?>">
			<input type="hidden" name="source_id" value="<?php echo esc_attr( (string) absint( $source_id ) ); ?>">
			<input type="hidden" name="target_id" value="<?php echo esc_attr( (string) absint( $target_id ) ); ?>">
			<?php wp_nonce_field( 'taxocommerce_core_delete_hierarchy_relation', 'taxocommerce_core_nonce' ); ?>
			<button class="button-link-delete" type="submit"><?php echo esc_html__( 'Desvincular', 'taxocommerce-core' ); ?></button>
		</form>
		<?php
	}

	/**
	 * KPI cards.
	 *
	 * @return void
	 */
	private function render_kpis() {
		$cards = array();
		foreach ( $this->roles() as $role => $label ) {
			$cards[ $label ] = count( $this->pages_by_role( $role ) );
		}

		$linked_categories = 0;
		$categories        = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) );
		if ( ! is_wp_error( $categories ) ) {
			foreach ( $categories as $category_id ) {
				if ( $this->term_meta_ids( $category_id, self::PARENT_SECONDARY_TERM_META ) ) {
					$linked_categories++;
				}
			}
		}
		$cards[ __( 'Categorías vinculadas', 'taxocommerce-core' ) ] = $linked_categories;
		?>
		<div style="display:flex;flex-wrap:wrap;gap:12px;margin:16px 0;">
			<?php foreach ( $cards as $label => $value ) : ?>
				<div class="card" style="min-width:150px;margin:0;padding:12px 16px;"><strong style="display:block;font-size:22px;"><?php echo esc_html( (string) $value ); ?></strong><span><?php echo esc_html( $label ); ?></span></div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Success/error notices from redirect.
	 *
	 * @return void
	 */
	private function render_notices() {
		$result = isset( $_GET['tc_result'] ) ? sanitize_key( wp_unslash( $_GET['tc_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice.
		$msg    = isset( $_GET['tc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['tc_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice.

		if ( 'ok' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Jerarquía actualizada.', 'taxocommerce-core' ) . '</p></div>';
		} elseif ( 'error' === $result && $msg ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/**
	 * Redirects after a write action.
	 *
	 * @param true|WP_Error $result Result.
	 * @param string        $context Context.
	 * @return void
	 */
	private function redirect_with_result( $result, $context ) {
		$args = array(
			'page'       => 'taxocommerce-core-hierarchy',
			'tc_context' => sanitize_key( $context ),
		);

		if ( is_wp_error( $result ) ) {
			$args['tc_result']  = 'error';
			$args['tc_message'] = $result->get_error_message();
		} else {
			$args['tc_result'] = 'ok';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Pages by role.
	 *
	 * @param string $role Role.
	 * @return WP_Post[]
	 */
	private function pages_by_role( $role ) {
		$role = sanitize_key( $role );
		if ( ! isset( $this->roles()[ $role ] ) ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => self::ROLE_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Small admin-only page set.
				'meta_value'     => $role, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Small admin-only page set.
			)
		);
	}

	/**
	 * Whether a page participates in any hierarchy relation.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	private function page_has_hierarchy_links( $page_id ) {
		$page_id = absint( $page_id );

		if ( $this->meta_ids( $page_id, self::PARENT_CLUSTER_META ) || $this->meta_ids( $page_id, self::PARENT_PRIMARY_META ) ) {
			return true;
		}

		foreach ( $this->pages_by_role( 'hub_primary' ) as $primary ) {
			if ( in_array( $page_id, $this->meta_ids( $primary->ID, self::PARENT_CLUSTER_META ), true ) ) {
				return true;
			}
		}

		foreach ( $this->pages_by_role( 'hub_secondary' ) as $secondary ) {
			if ( in_array( $page_id, $this->meta_ids( $secondary->ID, self::PARENT_PRIMARY_META ), true ) ) {
				return true;
			}
		}

		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) );
		if ( ! is_wp_error( $categories ) ) {
			foreach ( $categories as $category_id ) {
				if ( in_array( $page_id, $this->term_meta_ids( $category_id, self::PARENT_SECONDARY_TERM_META ), true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Adds a numeric post-meta value only once.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param int    $value   ID.
	 * @return bool True when newly added.
	 */
	private function add_unique_post_meta_id( $post_id, $key, $value ) {
		$value = absint( $value );
		if ( ! $value || in_array( $value, $this->meta_ids( $post_id, $key ), true ) ) {
			return false;
		}
		add_post_meta( absint( $post_id ), $key, $value, false );
		return true;
	}

	/**
	 * Adds a numeric term-meta value only once.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Meta key.
	 * @param int    $value   ID.
	 * @return bool True when newly added.
	 */
	private function add_unique_term_meta_id( $term_id, $key, $value ) {
		$value = absint( $value );
		if ( ! $value || in_array( $value, $this->term_meta_ids( $term_id, $key ), true ) ) {
			return false;
		}
		add_term_meta( absint( $term_id ), $key, $value, false );
		return true;
	}

	/**
	 * Numeric post-meta values.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Key.
	 * @return int[]
	 */
	private function meta_ids( $post_id, $key ) {
		return array_values( array_unique( array_filter( array_map( 'absint', get_post_meta( absint( $post_id ), $key, false ) ) ) ) );
	}

	/**
	 * Numeric term-meta values.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $key     Key.
	 * @return int[]
	 */
	private function term_meta_ids( $term_id, $key ) {
		return array_values( array_unique( array_filter( array_map( 'absint', get_term_meta( absint( $term_id ), $key, false ) ) ) ) );
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
