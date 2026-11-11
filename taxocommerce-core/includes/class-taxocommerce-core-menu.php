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
require_once __DIR__ . '/class-taxocommerce-core-block-navigation.php';

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
     * Market identity for a menu. Empty string is Global, never the country ES.
     * Page values are explicit two-letter country codes, not preset markets.
     */
    public static function normalize_code( $country ) {
        $country = strtoupper( trim( (string) $country ) );
        return preg_match( '/^[A-Z]{2}$/D', $country ) ? $country : '';
    }

    public static function page_country( $post_id ) {
        return self::normalize_code( get_post_meta( absint( $post_id ), TaxoCommerce_Core_Country::META, true ) );
    }

    /** Product categories can inherit a declared country from their ancestors. */
    public static function term_country( $term_id ) {
        $term_id = absint( $term_id );
        if ( ! $term_id ) { return ''; }
        $ids = array_merge( array( $term_id ), (array) get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );
        foreach ( $ids as $id ) {
            $country = self::normalize_code( get_term_meta( absint( $id ), TaxoCommerce_Core_Country::META, true ) );
            if ( '' !== $country ) { return $country; }
        }
        return '';
    }

    /**
     * Only actual country assignments on pages may introduce menu countries.
     * A new installation has exactly one choice: Global (empty country).
     */
    public static function available_countries() {
        // One source of truth for both the menu proposal and Core country fields.
        return TaxoCommerce_Core_Country::page_countries();
    }

    public static function country( $write = false ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Navigation GET is read-only; write handlers already verified action nonces.
        $params = $write ? $_POST : $_GET;
        $value = isset( $params['tc_country'] ) && is_string( $params['tc_country'] ) ? sanitize_text_field( wp_unslash( $params['tc_country'] ) ) : '';
        return self::normalize_code( $value );
    }

    public static function menu_option( $country = '' ) {
        $country = self::normalize_code( $country );
        if ( '' === $country ) { return self::MENU_ID_OPTION . '_global'; }
        return 'ES' === $country ? self::MENU_ID_OPTION : self::MENU_ID_OPTION . '_' . strtolower( $country );
    }

    /** Name shown in native WordPress menus; Global is not a synthetic country. */
    public static function menu_name( $country = '' ) {
        $country = self::normalize_code( $country );
        return 'TaxoCommerce - ' . ( $country ?: 'Global' );
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
        $country = self::normalize_code( $country );
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
     * Select a native WordPress menu explicitly without adopting, renaming or
     * overwriting it. With no selection, use the managed menu for the country.
     * The selector itself never changes WordPress data or Core options.
     */
    public static function selected_menu_id( $write = false ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only menu ID lookup; all modifying handlers verify their action nonce.
        $params = $write ? $_POST : $_GET;
        if ( isset( $params['tc_menu_id'] ) ) {
            $id = is_scalar( $params['tc_menu_id'] ) ? absint( $params['tc_menu_id'] ) : 0;
            if ( $id ) {
                $menu = wp_get_nav_menu_object( $id );
                return $menu && ! is_wp_error( $menu ) ? $id : 0;
            }
        }
        $id = self::managed_menu_id( self::country( $write ), false );
        return is_wp_error( $id ) ? 0 : absint( $id );
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
        foreach ( array_keys( self::available_countries() ) as $market ) {
            $id = $this->get_menu_id( $market );
            if ( $id ) { $managed[ $market ] = $id; }
        }
        if ( ! in_array( $assigned, $managed, true ) ) { return $args; }
        $country = '';
        if ( is_singular( array( 'page', 'post', 'product' ) ) ) {
            $country = self::page_country( get_queried_object_id() );
        } elseif ( is_tax( 'product_cat' ) ) {
            $country = self::term_country( get_queried_object_id() );
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
        TaxoCommerce_Core_Block_Navigation::register_hooks();
        add_filter( 'wp_nav_menu_args', array( $this, 'route_country_menu' ), 20 );
	}

	/**
	 * Render menu manager.
	 *
	 * @return void
	 */
	public function render() {
		$this->guard();
		// Native WordPress menus are the sole editing interface. Both block
		// navigation and classic theme locations are handled within that screen.
		TaxoCommerce_Core_Menu_Editor::render_wordpress_screen();
	}

	/**
	 * Show SEO-driven menu generation only for sites with a populated Core
	 * hierarchy. Never assume that an installed plugin implies a hierarchy.
	 * Existing native menus and theme assignments remain untouched until the
	 * administrator explicitly clicks the synchronization button.
	 */
	public function render_hierarchy_proposal() {
		$trees = array();
        $available = self::available_countries();
		foreach ( array_keys( $available ) as $market ) {
			$tree = $this->build_tree( $market );
			if ( $tree ) {
				$trees[ $market ] = $tree;
			}
		}
		if ( ! $trees ) {
			return;
		}
		$country = self::country();
		if ( ! isset( $trees[ $country ] ) ) {
			$country = (string) key( $trees );
		}
		$menu_id = $this->get_menu_id( $country );
		$tree = $trees[ $country ];
		?>
		<details class="tc-menu-proposal">
			<summary><?php esc_html_e( 'Propuesta automática', 'taxocommerce-core-catalog-content-management-for-woocommerce' ); ?></summary>
			<p><?php esc_html_e( 'Esta vista no modifica nada. Muestra lo que se generará al sincronizar.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="taxocommerce-core-menu">
				<label for="tc-seo-country"><?php esc_html_e( 'País del menú', 'taxocommerce-core-catalog-content-management-for-woocommerce' ); ?></label>
				<select id="tc-seo-country" name="tc_country">
					<?php foreach ( $trees as $market => $unused_tree ) : ?>
						<option value="<?php echo esc_attr( $market ); ?>"<?php selected( $market, $country ); ?>><?php echo esc_html( $available[ $market ] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Seleccionar menú', 'taxocommerce-core-catalog-content-management-for-woocommerce' ); ?></button>
			</form>
			<?php $this->render_kpis( $tree, $menu_id ); ?>
			<?php $this->render_tree( $tree ); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="taxocommerce_core_sync_menu">
				<input type="hidden" name="tc_country" value="<?php echo esc_attr( $country ); ?>">
				<?php wp_nonce_field( 'taxocommerce_core_sync_menu', 'taxocommerce_core_nonce' ); ?>
				<button type="submit" class="button button-primary"><?php
					echo esc_html( $menu_id ? __( 'Sincronizar propuesta', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) : __( 'Crear menú desde la jerarquía', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
				?></button>
			</form>
		</details>
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
        // Reject a country which is not actually assigned to any page.
        // Never silently turn a forged country value into a Global write.
        $submitted = isset( $_POST['tc_country'] ) && is_string( $_POST['tc_country'] )
            ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['tc_country'] ) ) ) ) : '';
        if ( $submitted !== $country || ! array_key_exists( $country, self::available_countries() ) ) {
            $this->redirect( 'error', __( 'País no válido.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
		$tree = $this->build_tree( $country );
		if ( ! $tree ) {
			$this->redirect( 'error', __( 'No hay elementos disponibles.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
		}
        $menu_id = $this->get_or_create_menu( $country );
		if ( is_wp_error( $menu_id ) ) {
			$this->redirect( 'error', $menu_id->get_error_message() );
		}

		$this->delete_generated_items( $menu_id );
		$result = $this->write_tree_to_menu( $tree, $menu_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message() );
		}

		$this->logger->info( 'Menú TaxoCommerce sincronizado desde la Jerarquía SEO.', array( 'menu_id' => absint( $menu_id ) ) );
		$this->redirect( 'ok', __( 'Menú sincronizado. Los elementos manuales se han conservado.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
	}

	/**
	 * Activate menu in a theme location.
	 *
	 * @return void
	 */
	public function handle_activate_menu() {
		$this->guard();
		check_admin_referer( 'taxocommerce_core_activate_menu', 'taxocommerce_core_nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para asignar menús al tema.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
        }
        $menu_id   = TaxoCommerce_Core_Menu_Editor::target_menu_id();
        $location  = isset( $_POST['menu_location'] ) ? sanitize_key( wp_unslash( $_POST['menu_location'] ) ) : '';
		$locations = get_registered_nav_menus();

		if ( is_wp_error( $menu_id ) || ! $menu_id || ! isset( $locations[ $location ] ) ) {
			$this->redirect( 'error', __( 'La ubicación de menú seleccionada no es válida.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
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

		$this->redirect( 'ok', __( 'Menú TaxoCommerce activado en la ubicación seleccionada.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
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
			$this->redirect( 'error', __( 'No existe una copia válida de las ubicaciones anteriores.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
		}

		set_theme_mod( 'nav_menu_locations', (array) $backup['locations'] );
		delete_option( self::BACKUP_OPTION );

		$this->redirect( 'ok', __( 'Se han restaurado las ubicaciones de menú anteriores.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
	}

	/**
	 * Build hierarchy tree from native Core metadata.
	 *
	 * @return array
	 */
	private function build_tree( $country = '' ) {
        $country = self::normalize_code( $country );
        $by_country = static function ( $page ) use ( $country ) {
            return self::page_country( $page->ID ) === $country;
        };
		$clusters    = array_values( array_filter( TaxoCommerce_Core_Hierarchy_Classic::role_pages( 'cluster' ), $by_country ) );
		$primaries   = array_values( array_filter( TaxoCommerce_Core_Hierarchy_Classic::role_pages( 'hub_primary' ), $by_country ) );
		$secondaries = array_values( array_filter( TaxoCommerce_Core_Hierarchy_Classic::role_pages( 'hub_secondary' ), $by_country ) );
		$categories  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
		$categories  = is_wp_error( $categories ) ? array() : $categories;

		// seo_relations is now authoritative. Older post/term metadata is
		// retained for compatibility, but does not drive generated menus.
		// Filter endpoints by their page/term membership below, not by legacy
        // relation.country_code (which may still contain implicit ES).
		$cluster_map   = TaxoCommerce_Core_Hierarchy_Classic::map( 'cluster_to_primary' );
		$primary_map   = TaxoCommerce_Core_Hierarchy_Classic::map( 'hub_primary_to_hub_secondary' );
		$secondary_map = TaxoCommerce_Core_Hierarchy_Classic::map( 'hub_secondary_to_category' );
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
            if ( self::term_country( $term->term_id ) !== $country ) { continue; }
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
			echo '<p>' . esc_html__( 'No hay una jerarquía suficiente para proponer el menú.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<div style="max-width:1100px;">';
		foreach ( $tree as $cluster ) {
			echo '<div class="card" style="max-width:none;margin-bottom:12px;">';
			echo '<strong>' . esc_html( get_the_title( $cluster['page'] ) ) . '</strong>';
			if ( empty( $cluster['primaries'] ) ) {
				echo '<p><em>' . esc_html__( 'Sin hubs primarios.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) . '</em></p>';
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
	private function get_or_create_menu( $country = '' ) {
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
			__( 'Clusters', 'taxocommerce-core-catalog-content-management-for-woocommerce' )        => $clusters,
			__( 'Hubs primarios', 'taxocommerce-core-catalog-content-management-for-woocommerce' )  => $primaries,
			__( 'Hubs secundarios', 'taxocommerce-core-catalog-content-management-for-woocommerce' )=> $secondaries,
			__( 'Categorías', 'taxocommerce-core-catalog-content-management-for-woocommerce' )      => $categories,
			__( 'Elementos publicados', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) => $items,
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
                'tc_menu_id' => self::selected_menu_id( true ),
                'tc_tab' => TaxoCommerce_Core_Menu_Editor::is_wordpress_tab( true ) ? 'wordpress' : 'seo',
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
			wp_die( esc_html__( 'No tienes permisos para gestionar TaxoCommerce Core.', 'taxocommerce-core-catalog-content-management-for-woocommerce' ) );
		}
	}
}
