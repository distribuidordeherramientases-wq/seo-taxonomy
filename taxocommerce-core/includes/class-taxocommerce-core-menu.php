<?php
/**
 * TaxoCommerce Core menu manager.
 *
 * @package TaxoCommerceCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-taxocommerce-core-menu-editor.php';

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

    public static function country( $write = false ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Navigation GET is read-only; write handlers already verified action nonces.
        $params = $write ? $_POST : $_GET;
        $value = isset( $params['tc_country'] ) && is_string( $params['tc_country'] ) ? sanitize_text_field( wp_unslash( $params['tc_country'] ) ) : 'ES';
        return TaxoCommerce_Core_Country::normalize( $value ) ?: 'ES';
    }

    public static function menu_option( $country = '' ) {
        $country = TaxoCommerce_Core_Country::normalize( $country ) ?: self::country();
        return 'ES' === $country ? self::MENU_ID_OPTION : self::MENU_ID_OPTION . '_' . strtolower( $country );
    }

    /** Name shown in native WordPress menus; one distinct name per market. */
    public static function menu_name( $country = 'ES' ) {
        $country = TaxoCommerce_Core_Country::normalize( $country ) ?: 'ES';
        return 'TaxoCommerce - ' . $country;
    }

    /**
     * Resolve a menu only when its actual native name identifies this country.
     * Never trust a stored option alone: older installations may point several
     * country options to the same Spanish menu ID.
     *
     * A legacy Spanish menu called "TaxoCommerce" remains readable and is
     * renamed in place when it is next synchronised (preserving its items).
     *
     * @return int|WP_Error Menu ID or zero if missing in read-only mode.
     */
    public static function managed_menu_id( $country = '', $create = false ) {
        $country = TaxoCommerce_Core_Country::normalize( $country ) ?: self::country( $create );
        $name = self::menu_name( $country );
        $stored_id = absint( get_option( self::menu_option( $country ), 0 ) );
        $menu = $stored_id ? wp_get_nav_menu_object( $stored_id ) : false;
        if ( is_wp_error( $menu ) ) {
            $menu = false;
        }
        if ( ! $menu || ! isset( $menu->name ) || (
            (string) $menu->name !== $name &&
            ! ( 'ES' === $country && 'TaxoCommerce' === (string) $menu->name )
        ) ) {
            // The ID points to another country's menu (or an unrelated menu).
            $menu = wp_get_nav_menu_object( $name );
            if ( ( ! $menu || is_wp_error( $menu ) ) && 'ES' === $country ) {
                $menu = wp_get_nav_menu_object( 'TaxoCommerce' );
            }
            if ( is_wp_error( $menu ) ) {
                $menu = false;
            }
        }

        if ( ! $menu && ! $create ) {
            return 0;
        }
        if ( ! $menu ) {
            $menu_id = wp_create_nav_menu( $name );
            if ( is_wp_error( $menu_id ) ) {
                return $menu_id;
            }
        } else {
            $menu_id = absint( $menu->term_id );
        }

        if ( $create ) {
            if ( $menu && 'ES' === $country && 'TaxoCommerce' === (string) $menu->name ) {
                // Rename rather than replacing the Spanish menu or its items.
                $renamed = wp_update_nav_menu_object( $menu_id, array( 'menu-name' => $name ) );
                if ( is_wp_error( $renamed ) ) {
                    return $renamed;
                }
            }
            update_option( self::menu_option( $country ), absint( $menu_id ), false );
        }
        return absint( $menu_id );
    }



	/**
	 * Constructor.
	 *
	 * @param TaxoCommerce_Core_Logger $logger Logger.
	 */
	public function __construct( TaxoCommerce_Core_Logger $logger ) {
		$this->logger = $logger;
	}

	/** Select only a TaxoCommerce-managed menu for the current front-end market. */
    public function route_country_menu( $args ) {
        if ( is_admin() || ! is_array( $args ) ) { return $args; }
        $location = isset( $args['theme_location'] ) ? (string) $args['theme_location'] : '';
        if ( ! $location ) { return $args; }
        $registered = get_theme_mod( 'nav_menu_locations', array() );
        $assigned = absint( is_array( $registered ) ? ( $registered[ $location ] ?? 0 ) : 0 );
        if ( ! $assigned ) { return $args; }
        $managed = array();
        foreach ( array_keys( TaxoCommerce_Core_Country::countries() ) as $market ) {
            $id = $this->get_menu_id( $market );
            if ( $id ) { $managed[ $market ] = $id; }
        }
        if ( ! in_array( $assigned, $managed, true ) ) { return $args; }
        $country = 'ES';
        if ( is_singular( array( 'page', 'post', 'product' ) ) ) {
            $country = TaxoCommerce_Core_Country::post( get_queried_object_id() );
        } elseif ( is_tax( 'product_cat' ) ) {
            $country = TaxoCommerce_Core_Country::term( get_queried_object_id() );
        }
        $args['menu'] = $managed[ $country ] ?? $assigned;
        return $args;
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
		TaxoCommerce_Core_Menu_Editor::register_hooks();
        add_filter( 'wp_nav_menu_args', array( $this, 'route_country_menu' ), 20 );
	}

	/**
	 * Render menu manager.
	 *
	 * @return void
	 */
	public function render() {
		$this->guard();

		$country    = self::country();
		$tree       = $this->build_tree( $country );
		$menu_id    = $this->get_menu_id( $country );
		$result     = isset( $_GET['tc_result'] ) ? sanitize_key( wp_unslash( $_GET['tc_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice.
		$message    = isset( $_GET['tc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['tc_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice.
		?>
		<div class="wrap tcc-family-screen">
            <header class="tcc-page-header">
                <span class="tcc-page-header__icon" aria-hidden="true"><span class="dashicons dashicons-menu-alt3"></span></span>
                <div class="tcc-page-header__content">
                    <p class="tcc-page-header__eyebrow"><?php esc_html_e( 'TaxoCommerce Core', 'taxocommerce-core' ); ?></p>
                    <h1><?php echo esc_html__( 'Gestor de menús', 'taxocommerce-core' ); ?></h1>
                    <p><?php echo esc_html__( 'Propone un menú a partir de la Jerarquía SEO y lo convierte en un menú nativo de WordPress. Los elementos manuales se conservan al sincronizar.', 'taxocommerce-core' ); ?></p>
                </div>
            </header>

			<?php if ( 'ok' === $result && $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php elseif ( 'error' === $result && $message ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

            <?php
            $menu_name = self::menu_name( $country );
            $countries = TaxoCommerce_Core_Country::countries();
            $country_label = isset( $countries[ $country ] ) ? $countries[ $country ] : $country;
            $native_menu = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;
            ?>
            <form method="get" class="tc-menu-country-picker" data-tc-menu-country-form>
                <input type="hidden" name="page" value="taxocommerce-core-menu">
                <label for="tc-menu-country"><strong><?php esc_html_e( 'País del menú', 'taxocommerce-core' ); ?></strong></label>
                <select id="tc-menu-country" name="tc_country"><?php TaxoCommerce_Core_Country::options( $country ); ?></select>
                <button type="submit" class="button"><?php esc_html_e( 'Seleccionar menú', 'taxocommerce-core' ); ?></button>
            </form>
            <section class="tc-menu-identity" aria-labelledby="tc-menu-identity-title">
                <p class="tc-menu-identity__label"><?php esc_html_e( 'MENÚ QUE ESTÁS EDITANDO O CREANDO', 'taxocommerce-core' ); ?></p>
                <h2 id="tc-menu-identity-title"><?php echo esc_html( $menu_name ); ?></h2>
                <p><strong><?php esc_html_e( 'País:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html( $country_label . ' (' . $country . ')' ); ?></p>
                <p><strong><?php esc_html_e( 'Identificador para llamar al menú desde una plantilla:', 'taxocommerce-core' ); ?></strong>
                    <code><?php echo esc_html( "'menu' => '" . $menu_name . "'" ); ?></code>
                </p>
                <?php if ( $menu_id ) : ?>
                    <p><strong><?php esc_html_e( 'ID del menú en WordPress:', 'taxocommerce-core' ); ?></strong> <code><?php echo esc_html( (string) absint( $menu_id ) ); ?></code>
                    <?php if ( $native_menu && ! is_wp_error( $native_menu ) && (string) $native_menu->name !== $menu_name ) : ?>
                        <span class="tc-menu-identity__notice"><?php echo esc_html( sprintf(
                            /* translators: %s: current legacy WordPress menu name. */
                            __( '(Nombre actual en WordPress: %s; se actualizará al sincronizar.)', 'taxocommerce-core' ),
                            $native_menu->name
                        ) ); ?></span>
                    <?php endif; ?></p>
                <?php else : ?>
                    <p class="tc-menu-identity__notice"><?php esc_html_e( 'Este menú todavía no existe. Se creará con este nombre al sincronizar la propuesta.', 'taxocommerce-core' ); ?></p>
                <?php endif; ?>
            </section>
            <?php TaxoCommerce_Core_Menu_Editor::controls( $menu_id, 'top' ); ?>
			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'Fuente:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html__( 'Cluster → Hub primario → Hub secundario → Categoría de producto. La jerarquía sigue siendo la fuente de la propuesta; WordPress conserva el menú publicado.', 'taxocommerce-core' ); ?></p></div>

			<?php $this->render_kpis( $tree, $menu_id ); ?>
			<?php TaxoCommerce_Core_Menu_Editor::render( $menu_id ); ?>

			<details class="tc-menu-proposal">
				<summary><?php echo esc_html__( 'Propuesta automática', 'taxocommerce-core' ); ?></summary>
				<p><?php echo esc_html__( 'Esta vista no modifica nada. Muestra lo que se generará al sincronizar.', 'taxocommerce-core' ); ?></p>
				<?php $this->render_tree( $tree ); ?>
			</details>
			<?php TaxoCommerce_Core_Menu_Editor::controls( $menu_id, 'bottom' ); ?>
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

		$country = self::country( true );
        $menu_id = $this->get_or_create_menu( $country );
		if ( is_wp_error( $menu_id ) ) {
			$this->redirect( 'error', $menu_id->get_error_message() );
		}

		$this->delete_generated_items( $menu_id );
		$result = $this->write_tree_to_menu( $this->build_tree( $country ), $menu_id );

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

		$menu_id   = $this->get_menu_id( self::country( true ) );
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
	private function build_tree( $country = 'ES' ) {
		$clusters    = TaxoCommerce_Core_Hierarchy_Classic::country_pages( 'cluster', $country );
		$primaries   = TaxoCommerce_Core_Hierarchy_Classic::country_pages( 'hub_primary', $country );
		$secondaries = TaxoCommerce_Core_Hierarchy_Classic::country_pages( 'hub_secondary', $country );
		$categories  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		$categories  = is_wp_error( $categories ) ? array() : $categories;

		// seo_relations is now authoritative. Older post/term metadata is
		// retained for compatibility, but does not drive generated menus.
		$cluster_map   = TaxoCommerce_Core_Hierarchy_Classic::map( 'cluster_to_primary', $country );
		$primary_map   = TaxoCommerce_Core_Hierarchy_Classic::map( 'hub_primary_to_hub_secondary', $country );
		$secondary_map = TaxoCommerce_Core_Hierarchy_Classic::map( 'hub_secondary_to_category', $country );
		$primaries_by_id = array();
		$secondaries_by_id = array();
		$categories_by_id = array();
		foreach ( $primaries as $page ) {
			$primaries_by_id[ (int) $page->ID ] = $page;
		}
		foreach ( $secondaries as $page ) {
			$secondaries_by_id[ (int) $page->ID ] = $page;
		}
		foreach ( $categories as $term ) {
            if ( TaxoCommerce_Core_Country::term( $term->term_id ) !== $country ) { continue; }
			$categories_by_id[ (int) $term->term_id ] = $term;
		}

		$tree = array();
		foreach ( $clusters as $cluster ) {
			$node = array( 'page' => $cluster, 'primaries' => array() );
			foreach ( array_keys( $cluster_map[ $cluster->ID ] ?? array() ) as $primary_id ) {
				if ( ! isset( $primaries_by_id[ $primary_id ] ) ) {
					continue;
				}
				$primary_node = array( 'page' => $primaries_by_id[ $primary_id ], 'secondaries' => array() );
				foreach ( array_keys( $primary_map[ $primary_id ] ?? array() ) as $secondary_id ) {
					if ( ! isset( $secondaries_by_id[ $secondary_id ] ) ) {
						continue;
					}
					$category_nodes = array();
					foreach ( array_keys( $secondary_map[ $secondary_id ] ?? array() ) as $category_id ) {
						if ( isset( $categories_by_id[ $category_id ] ) ) {
							$category_nodes[] = $categories_by_id[ $category_id ];
						}
					}
					$primary_node['secondaries'][] = array(
						'page' => $secondaries_by_id[ $secondary_id ],
						'categories' => $category_nodes,
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
		$items         = (array) wp_get_nav_menu_items( $menu_id );
		$generated_ids = array();

		foreach ( $items as $item ) {
			if ( absint( get_post_meta( $item->ID, self::GENERATED_META, true ) ) === 1 ) {
				$generated_ids[] = absint( $item->ID );
			}
		}

		/*
		 * Preserve manual items. If a manually added item was placed below an
		 * automatically generated parent, move it to the menu root before the
		 * generated branch is rebuilt.
		 */
		foreach ( $items as $item ) {
			if (
				absint( get_post_meta( $item->ID, self::GENERATED_META, true ) ) !== 1 &&
				in_array( absint( $item->menu_item_parent ), $generated_ids, true )
			) {
				update_post_meta( $item->ID, '_menu_item_menu_item_parent', 0 );
			}
		}

		foreach ( $generated_ids as $item_id ) {
			wp_delete_post( $item_id, true );
		}
	}

	/**
	 * Get or create native menu.
	 *
	 * @return int|WP_Error
	 */
	private function get_or_create_menu( $country = 'ES' ) {
        return self::managed_menu_id( $country, true );
    }

	/**
	 * Get valid managed menu ID.
	 *
	 * @return int
	 */
	private function get_menu_id( $country = '' ) {
        return self::managed_menu_id( $country, false );
    }

	/**
	 * Pages assigned to one Core hierarchy role.
	 *
	 * @param string $role Role.
	 * @return WP_Post[]
	 */
	private function pages_by_role( $role ) {
		return TaxoCommerce_Core_Hierarchy_Classic::role_pages( sanitize_key( $role ) );
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
                'tc_country' => self::country( true ),
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
