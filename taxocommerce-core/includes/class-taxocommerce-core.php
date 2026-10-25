<?php
/**
 * Main TaxoCommerce Core coordinator.
 *
 * @package TaxoCommerceCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates Core services and administration.
 */
final class TaxoCommerce_Core {

	/**
	 * Singleton.
	 *
	 * @var TaxoCommerce_Core|null
	 */
	private static $instance = null;

	/**
	 * Whether Core booted.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Logger.
	 *
	 * @var TaxoCommerce_Core_Logger|null
	 */
	private $logger = null;

	/**
	 * Hierarchy manager.
	 *
	 * @var TaxoCommerce_Core_Hierarchy|null
	 */
	private $hierarchy = null;

	/**
	 * Menu manager.
	 *
	 * @var TaxoCommerce_Core_Menu|null
	 */
	private $menu = null;

	/**
	 * Content manager.
	 *
	 * @var TaxoCommerce_Core_Content_Manager|null
	 */
	private $content = null;

	/**
	 * Runtime module registry.
	 *
	 * @var array
	 */
	private $modules = array();

	/**
	 * Returns singleton.
	 *
	 * @return TaxoCommerce_Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boots the Core.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->logger    = new TaxoCommerce_Core_Logger();
		$this->hierarchy = new TaxoCommerce_Core_Hierarchy( $this->logger );
		$this->menu      = new TaxoCommerce_Core_Menu( $this->logger );
		$this->content   = new TaxoCommerce_Core_Content_Manager( $this->logger );

		$this->register_module(
			'core',
			array(
				'name'        => 'TaxoCommerce Core',
				'version'     => TAXOCOMMERCE_CORE_VERSION,
				'status'      => 'active',
				'description' => 'Shared content, hierarchy, and extension services.',
			)
		);

		/**
		 * Lets extensions register themselves.
		 *
		 * @param TaxoCommerce_Core $core Core instance.
		 */
		do_action( 'taxocommerce_core_register_modules', $this );


		add_action( 'admin_menu', array( $this, 'register_menu' ), 5 );
		add_action( 'admin_menu', array( $this, 'normalize_family_submenu_labels' ), 9999 );
		add_action( 'admin_head', array( $this, 'hide_sidebar_submenu' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_family_page' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_anomalies_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_family_styles' ) );
		add_filter( 'admin_body_class', array( $this, 'family_body_class' ) );
		add_action( 'admin_post_taxocommerce_core_install_extension', array( $this, 'install_extension' ) );
		add_action( 'admin_post_taxocommerce_core_upload_extension', array( $this, 'upload_extension' ) );
		add_action( 'admin_post_taxocommerce_core_activate_extension', array( $this, 'activate_extension' ) );
		add_action( 'admin_post_taxocommerce_core_deactivate_extension', array( $this, 'deactivate_extension' ) );
		add_filter( 'plugin_action_links_' . TAXOCOMMERCE_CORE_BASENAME, array( $this, 'plugin_action_links' ) );

		$this->hierarchy->register_hooks();
		$this->menu->register_hooks();
		$this->content->register_hooks();
		$this->booted = true;

		/**
		 * Fires after Core is ready.
		 *
		 * @param TaxoCommerce_Core $core Core instance.
		 */
		do_action( 'taxocommerce_core_loaded', $this );
	}

	/**
	 * Adds a plugin-list shortcut.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$url = admin_url( 'admin.php?page=taxocommerce-core' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open Core', 'taxocommerce-core' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Registers main menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$capability = $this->capability();

		add_menu_page(
			__( 'TaxoCommerce Core', 'taxocommerce-core' ),
			__( 'TaxoCommerce Core', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core',
			array( $this, 'render_dashboard' ),
			'dashicons-networking',
			58
		);

		// Keep a single visible entry in the WordPress sidebar. The dashboard
		// cards are the navigation for Core modules.
		add_submenu_page(
			'taxocommerce-core',
			__( 'TaxoCommerce Core', 'taxocommerce-core' ),
			__( 'TaxoCommerce Core', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core',
			array( $this, 'render_dashboard' )
		);

		// Register module screens normally so WordPress keeps their routes and capabilities.
		add_submenu_page(
			'taxocommerce-core',
			__( 'Content', 'taxocommerce-core' ),
			__( 'Content', 'taxocommerce-core' ),
			$capability,
			TaxoCommerce_Core_Content_Manager::PAGE_SLUG,
			array( $this->content, 'render' )
		);

		add_submenu_page(
			'taxocommerce-core',
			__( 'SEO Hierarchy', 'taxocommerce-core' ),
			__( 'SEO Hierarchy', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-hierarchy',
			array( $this->hierarchy, 'render' )
		);

		add_submenu_page(
			'taxocommerce-core',
			__( 'Menu Manager', 'taxocommerce-core' ),
			__( 'Menu Manager', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-menu',
			array( $this->menu, 'render' )
		);

		add_submenu_page(
			'taxocommerce-core',
			__( 'Extensions', 'taxocommerce-core' ),
			__( 'Extensions', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-modules',
			array( $this, 'render_modules' )
		);
	}


	/**
	 * Normalizes visible TaxoCommerce family submenu labels.
	 *
	 * Core is the visual authority for family navigation. Extensions keep
	 * ownership of their routes and capabilities, but Core applies canonical
	 * English labels after every active extension has registered its submenu.
	 *
	 * @return void
	 */
	public function normalize_family_submenu_labels() {
		global $submenu;

		if ( empty( $submenu['taxocommerce-core'] ) || ! is_array( $submenu['taxocommerce-core'] ) ) {
			return;
		}

		$labels = array(
			'taxocommerce-core'          => 'TaxoCommerce Core',
			'taxocommerce-core-content'  => 'Content',
			'taxocommerce-core-hierarchy'=> 'SEO Hierarchy',
			'taxocommerce-core-menu'     => 'Menu Manager',
			'taxocommerce-core-modules'  => 'Extensions',
			'taxocommerce-vocabulary'    => 'Vocabulary',
			'taxocommerce-dependiente'   => 'Sales Assistant',
			'taxocommerce-import-export' => 'Import / Export',
			'taxocommerce-suppliers'     => 'Suppliers',
			'taxocommerce-redirects'     => 'Redirects',
			'taxocommerce-distribution'  => 'Distribution',
			'taxocommerce-images'        => 'Images',
			'taxocommerce-faqs'          => 'FAQs',
			'taxocommerce-database'      => 'Database',
			'taxocommerce-system-check'  => 'System Check',
			'taxocommerce-templates'     => 'Templates',
			'taxocommerce-google-bing'   => 'Insights',
			'taxocommerce-auditor'       => 'Auditor',
			'taxocommerce-social'        => 'Social',
			'seo-facturas'               => 'Billing',
			'seo-logistica'              => 'Logistics',
		);

		foreach ( $submenu['taxocommerce-core'] as &$item ) {
			if ( ! is_array( $item ) || ! isset( $item[2] ) ) {
				continue;
			}

			$slug = (string) $item[2];
			if ( isset( $labels[ $slug ] ) ) {
				$item[0] = $labels[ $slug ];
			}
		}
		unset( $item );
	}


	/**
	 * Hides the Core submenu only in the browser.
	 *
	 * The submenu remains registered in WordPress so direct links, capability
	 * checks and page hooks continue to work normally.
	 *
	 * @return void
	 */
	public function hide_sidebar_submenu() {
		// When Core runs alone, keep the compact dashboard navigation.
		// As soon as an active extension adds a real submenu, reveal the native
		// WordPress flyout, including Core's own pages and that extension.
		global $submenu;
		$items = isset( $submenu['taxocommerce-core'] ) ? (array) $submenu['taxocommerce-core'] : array();
		$core_pages = array( 'taxocommerce-core', 'taxocommerce-core-content', 'taxocommerce-core-hierarchy', 'taxocommerce-core-menu', 'taxocommerce-core-modules' );
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item[2] ) ) {
				continue;
			}
			if ( ! in_array( (string) $item[2], $core_pages, true ) ) {
				return;
			}
		}
		echo '<style id="taxocommerce-core-sidebar">#toplevel_page_taxocommerce-core > ul.wp-submenu{display:none!important;}</style>';
	}

	/**
	 * Returns required capability.
	 *
	 * @return string
	 */
	private function capability() {
		return (string) apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' );
	}

	/**
	 * Verifies current user.
	 *
	 * @return void
	 */
	private function guard() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'No tienes permisos para gestionar TaxoCommerce Core.', 'taxocommerce-core' ) );
		}
	}

	/**
	 * Registers one module.
	 *
	 * @param string $id   Module ID.
	 * @param array  $args Module metadata.
	 * @return bool
	 */
	public function register_module( $id, $args = array() ) {
		$id = sanitize_key( $id );
		if ( '' === $id || isset( $this->modules[ $id ] ) ) {
			return false;
		}

		$args = wp_parse_args(
			$args,
			array(
				'name'        => $id,
				'version'     => '',
				'status'      => 'active',
				'description' => '',
				'admin_url'   => '',
				'actions'     => array(),
			)
		);

		$status = sanitize_key( (string) $args['status'] );
		if ( ! in_array( $status, array( 'active', 'inactive', 'unavailable' ), true ) ) {
			$status = 'active';
		}

		$this->modules[ $id ] = array(
			'id'          => $id,
			'name'        => sanitize_text_field( (string) $args['name'] ),
			'version'     => sanitize_text_field( (string) $args['version'] ),
			'status'      => $status,
			'description' => sanitize_text_field( (string) $args['description'] ),
			'admin_url'   => esc_url_raw( (string) $args['admin_url'] ),
		);
		return true;
	}


	/** Return only modules registered by active plugins at runtime. */
	public function get_registered_modules() {
		return $this->modules;
	}

	/**
	 * Returns the built-in TaxoCommerce family catalog.
	 *
	 * Inactive extensions cannot execute hooks, so Core keeps the minimal
	 * identity needed to discover, activate, deactivate, or search for them.
	 *
	 * @return array
	 */
	private function family_extensions() {
		$extensions = array(
			'import_export' => array(
				'id'          => 'import_export',
				'name'        => 'TaxoCommerce Import-Export',
				'plugin_file' => 'taxocommerce-import-export/taxocommerce-import-export.php',
				'org_slug'    => 'taxocommerce-import-export',
				'search_term'   => 'TaxoCommerce Import-Export',
				'wordpress_org' => true,
				'description' => __( 'Catalog, content, and Media import/export.', 'taxocommerce-core' ),
			),
			'suppliers' => array(
				'id'            => 'suppliers',
				'name'          => 'TaxoCommerce Suppliers',
				'plugin_file'   => 'taxocommerce-suppliers/taxocommerce-suppliers.php',
				'org_slug'      => 'taxocommerce-suppliers',
				'search_term'   => 'TaxoCommerce Suppliers',
				'wordpress_org' => false,
				'admin_url'     => admin_url( 'admin.php?page=taxocommerce-suppliers' ),
				'description'   => __( 'Supplier catalogue, purchasing, prices, and external images.', 'taxocommerce-core' ),
			),
			'database' => array(
				'id'            => 'database',
				'name'          => 'TaxoCommerce Database',
				'plugin_file'   => 'taxocommerce-database/taxocommerce-database.php',
				'org_slug'      => 'taxocommerce-database',
				'search_term'   => 'TaxoCommerce Database',
				'wordpress_org' => false,
				'admin_url'     => admin_url( 'admin.php?page=taxocommerce-database' ),
				'description'   => __( 'MySQL diagnostics, tables, backups, and controlled maintenance in an independent plugin.', 'taxocommerce-core' ),
			),
			'images' => array(
				'id'            => 'images',
				'name'          => 'TaxoCommerce Images',
				'plugin_file'   => 'taxocommerce-images/taxocommerce-images.php',
				'org_slug'      => 'taxocommerce-images',
				'search_term'   => 'TaxoCommerce Images',
				'wordpress_org' => true,
				'description'   => __( 'Image management, auditing, thumbnails, metadata, and optimization.', 'taxocommerce-core' ),
			),
			'system_check' => array(
				'id'            => 'system_check',
				'name'          => 'TaxoCommerce System Check',
				'plugin_file'   => 'taxocommerce-system-check/taxocommerce-system-check.php',
				'org_slug'      => 'taxocommerce-system-check',
				'search_term'   => 'TaxoCommerce System Check',
				'wordpress_org' => true,
				'description'   => __( 'Server, WordPress, and plugin validation diagnostics with reports and optional email notifications.', 'taxocommerce-core' ),
			),
			'templates' => array(
				'id'            => 'templates',
				'name'          => 'TaxoCommerce Templates',
				'plugin_file'   => 'taxocommerce-templates/taxocommerce-templates.php',
				'org_slug'      => 'taxocommerce-templates',
				'search_term'   => 'TaxoCommerce Templates',
				'wordpress_org' => true,
				'description'   => 'Editorial roles, automatic assignment, and template management.',
			),
			'dependiente' => array(
				'id'            => 'dependiente',
				'name'          => 'TaxoCommerce Assistant',
				'plugin_file'   => 'taxocommerce-dependiente/taxocommerce-dependiente.php',
				'org_slug'      => 'taxocommerce-dependiente',
				'search_term'   => 'TaxoCommerce Assistant',
				'wordpress_org' => true,
				'description'   => __( 'Independent ten-lesson academy and catalog assistant, without automatic recycling.', 'taxocommerce-core' ),
			),
			'faqs' => array(
				'id'            => 'faqs',
				'name'          => 'TaxoCommerce FAQs',
				'plugin_file'   => 'taxocommerce-faqs/taxocommerce-faqs.php',
				'org_slug'      => 'taxocommerce-faqs',
				'search_term'   => 'TaxoCommerce FAQs',
				'wordpress_org' => true,
				'description'   => 'FAQs, manual editing, CSV, indicators, and Vocabulary integration.',
			),
			'vocabulary' => array(
				'id'            => 'vocabulary',
				'name'          => 'TaxoCommerce Vocabulary',
				'plugin_file'   => 'taxocommerce-vocabulary/taxocommerce-vocabulary.php',
				'org_slug'      => 'taxocommerce-vocabulary',
				'search_term'   => 'TaxoCommerce Vocabulary',
				'wordpress_org' => true,
				'admin_url'     => admin_url( 'admin.php?page=taxocommerce-vocabulary' ),
				'description'   => __( 'Cross-catalog tag and attribute inventory with automatic classification and local execution.', 'taxocommerce-core' ),
			),
			'distribution' => array(
				'id'            => 'distribution',
				'name'          => 'TaxoCommerce Distribution',
				'plugin_file'   => 'taxocommerce-distribution/taxocommerce-distribution.php',
				'org_slug'      => 'taxocommerce-distribution',
				'search_term'   => 'TaxoCommerce Distribution',
				'wordpress_org' => false,
				'admin_url'     => admin_url( 'admin.php?page=taxocommerce-distribution' ),
				'description'   => __( 'Commercial feeds and XML sitemaps with bounded block-based publishing.', 'taxocommerce-core' ),
			),
			'social' => array(
				'id'            => 'social',
				'name'          => 'TaxoCommerce Social',
				'plugin_file'   => 'taxocommerce-social/taxocommerce-social.php',
				'org_slug'      => 'taxocommerce-social',
				'search_term'   => 'TaxoCommerce Social',
				'wordpress_org' => false,
				'admin_url'     => admin_url( 'admin.php?page=taxocommerce-social' ),
				'description'   => __( 'Social network connections, publishing templates, queue, history, and standalone publication calendar.', 'taxocommerce-core' ),
			),
			'campaigns' => array(
				'id'            => 'campaigns',
				'name'          => 'TaxoCommerce Campaigns',
				'plugin_file'   => 'taxocommerce-campaigns/taxocommerce-campaigns.php',
				'org_slug'      => 'taxocommerce-campaigns',
				'search_term'   => 'TaxoCommerce Campaigns',
				'wordpress_org' => false,
				'admin_url'     => admin_url( 'admin.php?page=taxocommerce-campaigns' ),
				'description'   => __( 'Offer campaigns, promotional prices, banners, social offer publication, and a standalone commercial calendar.', 'taxocommerce-core' ),
			),
			'google_bing' => array(
				'id'            => 'google_bing',
				'name'          => 'TaxoCommerce Insights',
				'plugin_file'   => 'taxocommerce-google-bing/taxocommerce-google-bing.php',
				'org_slug'      => 'taxocommerce-google-bing',
				'search_term'   => 'TaxoCommerce Google Bing',
				'wordpress_org' => true,
				'description'   => __( 'Search, Analytics, Bing, Trends, market, opportunity, and executive reports.', 'taxocommerce-core' ),
			),
			'logistica' => array(
				'id'            => 'logistica',
				'name'          => 'TaxoCommerce Logistics',
				'plugin_file'   => 'taxocommerce-logistica/taxocommerce-logistica.php',
				'org_slug'      => 'taxocommerce-logistica',
				'search_term'   => 'TaxoCommerce Logistics',
				'wordpress_org' => false,
				'admin_url'     => admin_url( 'admin.php?page=seo-logistica' ),
				'description'   => __( 'WooCommerce order weights, dimensions, and shipping-cost rules.', 'taxocommerce-core' ),
			),
			'facturacion' => array(
				'id'            => 'facturacion',
				'name'          => 'TaxoCommerce Billing',
				'plugin_file'   => 'taxocommerce-facturacion/taxocommerce-facturacion.php',
				'org_slug'      => 'taxocommerce-facturacion',
				'search_term'   => 'TaxoCommerce Billing',
				'wordpress_org' => false,
				'admin_url'     => admin_url( 'admin.php?page=seo-facturas' ),
				'description'   => __( 'Invoices, proformas, and PDF quotations connected to WooCommerce.', 'taxocommerce-core' ),
			),
			'redirects' => array(
				'id'            => 'redirects',
				'name'          => 'TaxoCommerce Redirects',
				'plugin_file'   => 'taxocommerce-redirects/taxocommerce-redirects.php',
				'org_slug'      => 'taxocommerce-redirects',
				'search_term'   => 'TaxoCommerce Redirects',
				'wordpress_org' => true,
				'admin_url'     => admin_url( 'admin.php?page=taxocommerce-redirects' ),
				'description'   => __( '301/302 redirects and assisted content deletion.', 'taxocommerce-core' ),
			),
			'auditor' => array(
				'id'            => 'auditor',
				'name'          => 'TaxoCommerce Auditor',
				'plugin_file'   => 'taxocommerce-auditor/taxocommerce-auditor.php',
				'org_slug'      => 'taxocommerce-auditor',
				'search_term'   => 'TaxoCommerce Auditor',
				'wordpress_org' => true,
				'description'   => __( 'Independent editorial and content quality auditing.', 'taxocommerce-core' ),
			),
		);

		/**
		 * Filters the TaxoCommerce family catalog.
		 *
		 * Active extensions may add discovery metadata for related plugins.
		 *
		 * @param array $extensions Family extensions.
		 */
		return (array) apply_filters( 'taxocommerce_core_family_extensions', $extensions );
	}

	/**
	 * Returns the local TaxoCommerce package directory.
	 *
	 * Packages are intentionally stored outside the plugin directory so Core
	 * updates do not remove them.
	 *
	 * @return string
	 */
	private function local_package_directory() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return trailingslashit( (string) $uploads['basedir'] ) . 'taxocommerce-core/plugins';
	}

	/**
	 * Returns the expected local ZIP for an extension when it exists.
	 *
	 * The default file name is the family slug plus ".zip", for example
	 * taxocommerce-auditor.zip. Extensions may override it with zip_filename.
	 *
	 * @param array $extension Extension metadata.
	 * @return string
	 */
	private function local_package_path( $extension ) {
		$directory = $this->local_package_directory();
		if ( '' === $directory ) {
			return '';
		}

		$slug = sanitize_key( (string) ( $extension['org_slug'] ?? '' ) );
		$file = sanitize_file_name( (string) ( $extension['zip_filename'] ?? ( $slug ? $slug . '.zip' : '' ) ) );
		if ( '' === $file ) {
			return '';
		}

		$path = trailingslashit( $directory ) . $file;
		return is_file( $path ) && is_readable( $path ) ? $path : '';
	}

	/**
	 * Installs one known TaxoCommerce extension from the local uploads package.
	 *
	 * @return void
	 */
	public function install_extension() {
		$this->guard();

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'No tienes permisos para instalar plugins.', 'taxocommerce-core' ) );
		}

		check_admin_referer( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' );

		$extension_id = isset( $_POST['extension_id'] ) ? sanitize_key( wp_unslash( $_POST['extension_id'] ) ) : '';
		$catalog      = $this->family_extensions();

		if ( ! isset( $catalog[ $extension_id ]['plugin_file'] ) ) {
			wp_die( esc_html__( 'Unknown TaxoCommerce extension.', 'taxocommerce-core' ) );
		}

		$extension   = (array) $catalog[ $extension_id ];
		$plugin_file = sanitize_text_field( (string) $extension['plugin_file'] );
		if ( $this->installed_plugin_data( $plugin_file ) ) {
			$this->redirect_extension_result( 'error', __( 'The extension is already installed.', 'taxocommerce-core' ) );
		}

		$package = $this->local_package_path( $extension );
		if ( '' === $package ) {
			$this->redirect_extension_result(
				'error',
				__( 'The local ZIP for this extension was not found in uploads/taxocommerce-core/plugins.', 'taxocommerce-core' )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			$this->redirect_extension_result( 'error', $result->get_error_message() );
		}
		if ( false === $result ) {
			$this->redirect_extension_result( 'error', __( 'WordPress could not install the local ZIP.', 'taxocommerce-core' ) );
		}

		wp_clean_plugins_cache( true );
		if ( ! $this->installed_plugin_data( $plugin_file ) ) {
			$this->redirect_extension_result(
				'error',
				__( 'The ZIP was processed, but it does not contain the expected structure for this extension.', 'taxocommerce-core' )
			);
		}

		$this->redirect_extension_result( 'ok', __( 'Extension installed from the local ZIP. You can activate it now.', 'taxocommerce-core' ) );
	}


	/**
	 * Installs one known TaxoCommerce extension from a ZIP uploaded in Core.
	 *
	 * The ZIP must contain the exact plugin basename declared in the family
	 * catalog, so a package cannot accidentally be installed from another row.
	 *
	 * @return void
	 */
	public function upload_extension() {
		$this->guard();

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'No tienes permisos para instalar plugins.', 'taxocommerce-core' ) );
		}

		check_admin_referer( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' );

		$extension_id = isset( $_POST['extension_id'] ) ? sanitize_key( wp_unslash( $_POST['extension_id'] ) ) : '';
		$catalog      = $this->family_extensions();

		if ( ! isset( $catalog[ $extension_id ]['plugin_file'] ) ) {
			wp_die( esc_html__( 'Unknown TaxoCommerce extension.', 'taxocommerce-core' ) );
		}

		$extension   = (array) $catalog[ $extension_id ];
		$plugin_file = sanitize_text_field( (string) $extension['plugin_file'] );

		if ( $this->installed_plugin_data( $plugin_file ) ) {
			$this->redirect_extension_result( 'error', __( 'The extension is already installed.', 'taxocommerce-core' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File upload is validated below before use.
		$file = isset( $_FILES['plugin_zip'] ) && is_array( $_FILES['plugin_zip'] ) ? $_FILES['plugin_zip'] : array();
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $error ) {
			$this->redirect_extension_result( 'error', __( 'A valid ZIP was not received.', 'taxocommerce-core' ) );
		}

		$name     = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$size     = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( '' === $name || ! preg_match( '/\.zip$/i', $name ) || '' === $tmp_name || $size < 1 || ! is_uploaded_file( $tmp_name ) ) {
			$this->redirect_extension_result( 'error', __( 'The file must be a correctly uploaded ZIP.', 'taxocommerce-core' ) );
		}

		$max_size = (int) wp_max_upload_size();
		if ( $max_size > 0 && $size > $max_size ) {
			$this->redirect_extension_result( 'error', __( 'The ZIP exceeds the maximum upload size allowed by WordPress.', 'taxocommerce-core' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->redirect_extension_result( 'error', __( 'ZipArchive is not available on the server to validate the package.', 'taxocommerce-core' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$temp_dir  = trailingslashit( get_temp_dir() );
		$temp_name = wp_unique_filename( $temp_dir, $name );
		$temp_file = $temp_dir . $temp_name;

		if ( ! move_uploaded_file( $tmp_name, $temp_file ) ) {
			$this->redirect_extension_result( 'error', __( 'WordPress could not prepare the ZIP temporarily.', 'taxocommerce-core' ) );
		}

		$zip   = new ZipArchive();
		$valid = false;

		if ( true === $zip->open( $temp_file ) ) {
			for ( $index = 0; $index < $zip->numFiles; $index++ ) {
				$entry = str_replace( '\\', '/', ltrim( (string) $zip->getNameIndex( $index ), '/' ) );
				if ( $plugin_file === $entry ) {
					$valid = true;
					break;
				}
			}
			$zip->close();
		}

		if ( ! $valid ) {
			@unlink( $temp_file );
			$this->redirect_extension_result(
				'error',
				sprintf(
					/* translators: %s: expected plugin basename. */
					__( 'The ZIP does not match this extension. It must contain %s.', 'taxocommerce-core' ),
					$plugin_file
				)
			);
		}

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $temp_file );
		@unlink( $temp_file );

		if ( is_wp_error( $result ) ) {
			$this->redirect_extension_result( 'error', $result->get_error_message() );
		}
		if ( false === $result ) {
			$this->redirect_extension_result( 'error', __( 'WordPress could not install the uploaded ZIP.', 'taxocommerce-core' ) );
		}

		wp_clean_plugins_cache( true );
		if ( ! $this->installed_plugin_data( $plugin_file ) ) {
			$this->redirect_extension_result(
				'error',
				__( 'The ZIP was processed, but the extension was not installed at the expected path.', 'taxocommerce-core' )
			);
		}

		$this->redirect_extension_result( 'ok', __( 'Extension installed from ZIP. You can activate it now.', 'taxocommerce-core' ) );
	}

	/**
	 * Returns installed plugin data for a family extension.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return array
	 */
	private function installed_plugin_data( $plugin_file ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		return isset( $plugins[ $plugin_file ] ) && is_array( $plugins[ $plugin_file ] )
			? $plugins[ $plugin_file ]
			: array();
	}

	/**
	 * Whether a family extension is active.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return bool
	 */
	private function extension_is_active( $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( $plugin_file );
	}

	/**
	 * Activates a known TaxoCommerce family extension.
	 *
	 * @return void
	 */
	public function activate_extension() {
		$this->guard();

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'No tienes permisos para activar plugins.', 'taxocommerce-core' ) );
		}

		check_admin_referer( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' );

		$extension_id = isset( $_POST['extension_id'] ) ? sanitize_key( wp_unslash( $_POST['extension_id'] ) ) : '';
		$catalog      = $this->family_extensions();

		if ( ! isset( $catalog[ $extension_id ]['plugin_file'] ) ) {
			wp_die( esc_html__( 'Unknown TaxoCommerce extension.', 'taxocommerce-core' ) );
		}

		$plugin_file = sanitize_text_field( (string) $catalog[ $extension_id ]['plugin_file'] );
		if ( ! $this->installed_plugin_data( $plugin_file ) ) {
			$this->redirect_extension_result( 'error', __( 'The extension is not installed yet.', 'taxocommerce-core' ) );
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$result = activate_plugin( $plugin_file, '', false, false );
		if ( is_wp_error( $result ) ) {
			$this->redirect_extension_result( 'error', $result->get_error_message() );
		}

		$this->redirect_extension_result( 'ok', __( 'Extension activated.', 'taxocommerce-core' ) );
	}

	/**
	 * Deactivates a known TaxoCommerce family extension.
	 *
	 * @return void
	 */
	public function deactivate_extension() {
		$this->guard();

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'No tienes permisos para desactivar plugins.', 'taxocommerce-core' ) );
		}

		check_admin_referer( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' );

		$extension_id = isset( $_POST['extension_id'] ) ? sanitize_key( wp_unslash( $_POST['extension_id'] ) ) : '';
		$catalog      = $this->family_extensions();

		if ( ! isset( $catalog[ $extension_id ]['plugin_file'] ) ) {
			wp_die( esc_html__( 'Unknown TaxoCommerce extension.', 'taxocommerce-core' ) );
		}

		$plugin_file = sanitize_text_field( (string) $catalog[ $extension_id ]['plugin_file'] );
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( $plugin_file, false, false );
		$this->redirect_extension_result( 'ok', __( 'Extension deactivated.', 'taxocommerce-core' ) );
	}

	/**
	 * Redirects to the extension registry after an action.
	 *
	 * @param string $result  Result key.
	 * @param string $message Message.
	 * @return void
	 */
	private function redirect_extension_result( $result, $message ) {
		$url = add_query_arg(
			array(
				'page'       => 'taxocommerce-core-modules',
				'tc_result'  => sanitize_key( $result ),
				'tc_message' => sanitize_text_field( $message ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Returns logger.
	 *
	 * @return TaxoCommerce_Core_Logger|null
	 */
	public function logger() {
		return $this->logger;
	}

	/**
	 * Returns content manager.
	 *
	 * @return TaxoCommerce_Core_Content_Manager|null
	 */
	public function content() {
		return $this->content;
	}

	/**
	 * Returns hierarchy manager.
	 *
	 * @return TaxoCommerce_Core_Hierarchy|null
	 */
	public function hierarchy() {
		return $this->hierarchy;
	}

	/**
	 * Returns menu manager.
	 *
	 * @return TaxoCommerce_Core_Menu|null
	 */
	public function menu() {
		return $this->menu;
	}

	/**
	 * Detects legacy plugin.
	 *
	 * @return bool
	 */
	public function legacy_detected() {
		return defined( 'SEO_SYSTEM_VERSION' );
	}

	/**
	 * Detects WooCommerce.
	 *
	 * @return bool
	 */
	public function woocommerce_detected() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Identify TaxoCommerce screens without styling unrelated WordPress pages.
	 *
	 * @param string $hook WordPress screen hook.
	 * @return bool
	 */
	private function is_family_admin_screen( $hook = '' ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$screen_id = $screen ? (string) $screen->id : '';
		$hook = (string) $hook;
		// Explicit allowlist: never style TaxoCommerce SEO or another plugin
		// merely because its slug happens to contain "taxocommerce".
		$slugs = array(
			'taxocommerce-core',
			'taxocommerce-images',
			'taxocommerce-import-export',
			'taxocommerce-system-check',
			'taxocommerce-vocabulary',
			'tcs-system-status',
			'tcs-plugin-validation',
			'tcs-url-audit',
		);
		foreach ( array( $screen_id, $hook ) as $candidate ) {
			foreach ( $slugs as $slug ) {
				if ( $candidate === $slug
					|| $candidate === 'toplevel_page_' . $slug
					|| false !== strpos( $candidate, '_page_' . $slug ) ) {
					return true;
				}
			}
		}
		return false;
	}
	/**
	 * One shared stylesheet for every screen owned by the plugin family.
	 *
	 * @param string $hook WordPress page hook.
	 * @return void
	 */
	public function enqueue_family_styles( $hook ) {
		if ( ! $this->is_family_admin_screen( $hook ) ) {
			return;
		}
		wp_enqueue_style(
			'taxocommerce-core-admin',
			plugins_url( 'assets/admin.css', TAXOCOMMERCE_CORE_FILE ),
			array(),
			TAXOCOMMERCE_CORE_VERSION
		);
	}

	/**
	 * Scope shared styling to the family instead of WordPress itself.
	 *
	 * @param string $classes Admin body classes.
	 * @return string
	 */
	public function family_body_class( $classes ) {
		return $this->is_family_admin_screen() ? $classes . ' tcc-family-admin' : $classes;
	}

	/**
	 * Core dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$this->guard();
		TaxoCommerce_Core_Dashboard::render(
			$this->modules,
			$this->legacy_detected(),
			$this->woocommerce_detected()
		);
	}

	/**
	 * Redirect old Ampliar familia bookmarks to the single Extensions page.
	 *
	 * @return void
	 */
	public function redirect_legacy_family_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'taxocommerce-core-family' !== $page ) {
			return;
		}
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'No tienes permisos para gestionar extensiones.', 'taxocommerce-core' ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-core-modules' ) );
		exit;
	}

	/**
	 * Redirect links from the former dedicated Anomalies screen to Content.
	 *
	 * @return void
	 */
	public function redirect_legacy_anomalies_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'taxocommerce-core-anomalies' !== $page ) {
			return;
		}

		$this->guard();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => TaxoCommerce_Core_Content_Manager::PAGE_SLUG,
					'tc_type' => 'anomalies',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Modules page.
	 *
	 * @return void
	 */
	public function render_modules() {
		$this->guard();

		$family_search_url = add_query_arg(
			array(
				's'    => 'TaxoCommerce',
				'tab'  => 'search',
				'type' => 'term',
			),
			admin_url( 'plugin-install.php' )
		);
		$result     = isset( $_GET['tc_result'] ) ? sanitize_key( wp_unslash( $_GET['tc_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice only.
		$message    = isset( $_GET['tc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['tc_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice only.
		$catalog    = $this->family_extensions();
		?>
		<div class="wrap tcc-family-screen">
			<header class="tcc-page-header">
				<span class="tcc-page-header__icon" aria-hidden="true"><span class="dashicons dashicons-screenoptions"></span></span>
				<div class="tcc-page-header__content">
					<p class="tcc-page-header__eyebrow"><?php esc_html_e( 'TaxoCommerce Core', 'taxocommerce-core' ); ?></p>
					<h1><?php echo esc_html__( 'Extensions', 'taxocommerce-core' ); ?></h1>
					<p><?php echo esc_html__( 'Install, activate, deactivate, and manage TaxoCommerce family extensions from a single screen.', 'taxocommerce-core' ); ?></p>
				</div>
			</header>

			<?php if ( 'ok' === $result && $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php elseif ( 'error' === $result && $message ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'TaxoCommerce family:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html__( 'Core uses a single extension catalog across all environments. Each extension can be installed from WordPress.org when published, from a ZIP uploaded in its row, or from a local ZIP prepared by Core.', 'taxocommerce-core' ); ?></p></div>

			<?php $local_package_dir = $this->local_package_directory(); ?>
			<p class="description"><strong><?php echo esc_html__( 'Local ZIP repository:', 'taxocommerce-core' ); ?></strong> <code><?php echo esc_html( $local_package_dir ? $local_package_dir : 'uploads/taxocommerce-core/plugins' ); ?></code></p>
			<div class="tcc-extension-toolbar">
				<a class="button button-primary" href="<?php echo esc_url( $family_search_url ); ?>"><?php echo esc_html__( 'Search TaxoCommerce family on WordPress.org', 'taxocommerce-core' ); ?></a>
				<span class="description"><?php echo esc_html__( 'To install a ZIP manually, use the Upload ZIP action for the corresponding extension.', 'taxocommerce-core' ); ?></span>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Extension', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Version', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Description', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Actions', 'taxocommerce-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $catalog as $extension_id => $extension ) : ?>
						<?php
						$plugin_file = sanitize_text_field( (string) ( $extension['plugin_file'] ?? '' ) );
						$plugin_data = $plugin_file ? $this->installed_plugin_data( $plugin_file ) : array();
						$installed   = ! empty( $plugin_data );
						$active      = $installed && $this->extension_is_active( $plugin_file );
						$runtime     = isset( $this->modules[ $extension_id ] ) ? $this->modules[ $extension_id ] : array();
						$version     = ! empty( $runtime['version'] ) ? $runtime['version'] : ( $plugin_data['Version'] ?? '' );
						$admin_url   = ! empty( $runtime['admin_url'] )
							? $runtime['admin_url']
							: ( ! empty( $extension['admin_url'] ) ? esc_url_raw( (string) $extension['admin_url'] ) : '' );
						$search_term   = sanitize_text_field( (string) ( $extension['search_term'] ?? $extension['name'] ?? '' ) );
						$local_package = $this->local_package_path( $extension );
						$search_url  = add_query_arg(
							array(
								's'    => $search_term,
								'tab'  => 'search',
								'type' => 'term',
							),
							admin_url( 'plugin-install.php' )
						);
						?>
						<tr>
							<td><strong><?php echo esc_html( (string) $extension['name'] ); ?></strong></td>
							<td><?php echo esc_html( $version ? (string) $version : '—' ); ?></td>
							<td>
								<?php
								if ( $active ) {
									echo esc_html__( 'Active', 'taxocommerce-core' );
								} elseif ( $installed ) {
									echo esc_html__( 'Installed · inactive', 'taxocommerce-core' );
								} else {
									echo esc_html__( 'Not installed', 'taxocommerce-core' );
									if ( $local_package ) {
										echo '<br><small>' . esc_html__( 'Local ZIP available', 'taxocommerce-core' ) . '</small>';
									}
								}
								?>
							</td>
							<td><?php echo esc_html( (string) ( $extension['description'] ?? '' ) ); ?></td>
							<td>
								<?php if ( ! $installed ) : ?>
									<div class="tcc-extension-actions">
										<a class="button button-small" href="<?php echo esc_url( $search_url ); ?>"><?php echo esc_html__( 'Search on WordPress.org', 'taxocommerce-core' ); ?></a>
										<form class="tcc-extension-upload" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="taxocommerce_core_upload_extension">
											<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension_id ); ?>">
											<?php wp_nonce_field( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' ); ?>
											<label class="button button-small">
												<?php echo esc_html__( 'Upload ZIP', 'taxocommerce-core' ); ?>
												<input class="tcc-extension-upload__input" type="file" name="plugin_zip" accept=".zip,application/zip" required>
											</label>
											<button class="button button-primary button-small" type="submit"><?php echo esc_html__( 'Install ZIP', 'taxocommerce-core' ); ?></button>
										</form>
										<?php if ( $local_package ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="taxocommerce_core_install_extension">
												<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension_id ); ?>">
												<?php wp_nonce_field( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' ); ?>
												<button class="button button-primary button-small"><?php echo esc_html__( 'Install ZIP local', 'taxocommerce-core' ); ?></button>
											</form>
										<?php endif; ?>
									</div>
								<?php elseif ( ! $active ) : ?>
									<div class="tcc-extension-actions">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="taxocommerce_core_activate_extension">
											<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension_id ); ?>">
											<?php wp_nonce_field( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' ); ?>
											<button class="button button-primary button-small"><?php echo esc_html__( 'Activate', 'taxocommerce-core' ); ?></button>
										</form>
									</div>
								<?php else : ?>
									<div class="tcc-extension-actions">
										<?php if ( $admin_url ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $admin_url ); ?>"><?php echo esc_html__( 'Open', 'taxocommerce-core' ); ?></a>
										<?php endif; ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="taxocommerce_core_deactivate_extension">
											<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension_id ); ?>">
											<?php wp_nonce_field( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' ); ?>
											<button class="button button-small"><?php echo esc_html__( 'Deactivate', 'taxocommerce-core' ); ?></button>
										</form>
									</div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Registered active extensions', 'taxocommerce-core' ); ?></h2>
			<p><?php echo esc_html__( 'When an extension is active it can register new menu pages, KPIs, and other Core services. Each extension manages its own processes locally.', 'taxocommerce-core' ); ?></p>
		</div>
		<?php
	}

}
