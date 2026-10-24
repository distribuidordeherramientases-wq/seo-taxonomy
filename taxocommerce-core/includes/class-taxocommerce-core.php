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
				'description' => 'Servicios comunes de contenidos, jerarquía y extensiones.',
			)
		);

		/**
		 * Lets extensions register themselves.
		 *
		 * @param TaxoCommerce_Core $core Core instance.
		 */
		do_action( 'taxocommerce_core_register_modules', $this );


		add_action( 'admin_menu', array( $this, 'register_menu' ), 5 );
		add_action( 'admin_head', array( $this, 'hide_sidebar_submenu' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_family_page' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_anomalies_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_family_styles' ) );
		add_filter( 'admin_body_class', array( $this, 'family_body_class' ) );
		add_action( 'admin_post_taxocommerce_core_install_extension', array( $this, 'install_extension' ) );
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
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Abrir Core', 'taxocommerce-core' ) . '</a>'
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
			__( 'Contenidos', 'taxocommerce-core' ),
			__( 'Contenidos', 'taxocommerce-core' ),
			$capability,
			TaxoCommerce_Core_Content_Manager::PAGE_SLUG,
			array( $this->content, 'render' )
		);

		add_submenu_page(
			'taxocommerce-core',
			__( 'Jerarquía SEO', 'taxocommerce-core' ),
			__( 'Jerarquía SEO', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-hierarchy',
			array( $this->hierarchy, 'render' )
		);

		add_submenu_page(
			'taxocommerce-core',
			__( 'Gestor de menús', 'taxocommerce-core' ),
			__( 'Gestor de menús', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-menu',
			array( $this->menu, 'render' )
		);

		add_submenu_page(
			'taxocommerce-core',
			__( 'Extensiones', 'taxocommerce-core' ),
			__( 'Extensiones', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-modules',
			array( $this, 'render_modules' )
		);
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
				'description' => __( 'Importación y exportación de catálogo, contenidos y Media.', 'taxocommerce-core' ),
			),
			'images' => array(
				'id'            => 'images',
				'name'          => 'TaxoCommerce Images',
				'plugin_file'   => 'taxocommerce-images/taxocommerce-images.php',
				'org_slug'      => 'taxocommerce-images',
				'search_term'   => 'TaxoCommerce Images',
				'wordpress_org' => true,
				'description'   => __( 'Gestión, auditoría, miniaturas, metadatos y optimización de imágenes.', 'taxocommerce-core' ),
			),
			'system_check' => array(
				'id'            => 'system_check',
				'name'          => 'TaxoCommerce System Check',
				'plugin_file'   => 'taxocommerce-system-check/taxocommerce-system-check.php',
				'org_slug'      => 'taxocommerce-system-check',
				'search_term'   => 'TaxoCommerce System Check',
				'wordpress_org' => true,
				'description'   => __( 'Diagnosticos del servidor, WordPress y Plugin Validation con informes y correo opcional.', 'taxocommerce-core' ),
			),
			'templates' => array(
				'id'            => 'templates',
				'name'          => 'TaxoCommerce Templates',
				'plugin_file'   => 'taxocommerce-templates/taxocommerce-templates.php',
				'org_slug'      => 'taxocommerce-templates',
				'search_term'   => 'TaxoCommerce Templates',
				'wordpress_org' => true,
				'description'   => 'Roles editoriales, asignación automática y gestión de plantillas.',
			),
			'dependiente' => array(
				'id'            => 'dependiente',
				'name'          => 'TaxoCommerce Dependiente',
				'plugin_file'   => 'taxocommerce-dependiente/taxocommerce-dependiente.php',
				'org_slug'      => 'taxocommerce-dependiente',
				'search_term'   => 'TaxoCommerce Dependiente',
				'wordpress_org' => true,
				'description'   => __( 'Academia independiente de diez lecciones y asistente de catálogo, sin reciclado automático.', 'taxocommerce-core' ),
			),
			'faqs' => array(
				'id'            => 'faqs',
				'name'          => 'TaxoCommerce FAQs',
				'plugin_file'   => 'taxocommerce-faqs/taxocommerce-faqs.php',
				'org_slug'      => 'taxocommerce-faqs',
				'search_term'   => 'TaxoCommerce FAQs',
				'wordpress_org' => true,
				'description'   => 'Preguntas frecuentes, edición manual, CSV, indicadores y Vocabulary.',
			),
			'vocabulary' => array(
				'id'            => 'vocabulary',
				'name'          => 'TaxoCommerce Vocabulary',
				'plugin_file'   => 'taxocommerce-vocabulary/taxocommerce-vocabulary.php',
				'org_slug'      => 'taxocommerce-vocabulary',
				'search_term'   => 'TaxoCommerce Vocabulary',
				'wordpress_org' => true,
				'admin_url'     => admin_url( 'admin.php?page=taxocommerce-vocabulary' ),
				'description'   => __( 'Inventario transversal de etiquetas y atributos con Clasificador automático y ejecución local.', 'taxocommerce-core' ),
			),
			'google_bing' => array(
				'id'            => 'google_bing',
				'name'          => 'TaxoCommerce Google / Bing',
				'plugin_file'   => 'taxocommerce-google-bing/taxocommerce-google-bing.php',
				'org_slug'      => 'taxocommerce-google-bing',
				'search_term'   => 'TaxoCommerce Google Bing',
				'wordpress_org' => true,
				'description'   => __( 'Conexiones centralizadas y futuras fuentes de datos de Google y Microsoft Bing.', 'taxocommerce-core' ),
			),
			'redirects' => array(
				'id'            => 'redirects',
				'name'          => 'TaxoCommerce Redirects',
				'plugin_file'   => 'taxocommerce-redirects/taxocommerce-redirects.php',
				'org_slug'      => 'taxocommerce-redirects',
				'search_term'   => 'TaxoCommerce Redirects',
				'wordpress_org' => true,
				'description'   => __( 'Redirecciones 301/302 y borrado asistido de contenidos.', 'taxocommerce-core' ),
			),
			'auditor' => array(
				'id'            => 'auditor',
				'name'          => 'TaxoCommerce Auditor',
				'plugin_file'   => 'taxocommerce-auditor/taxocommerce-auditor.php',
				'org_slug'      => 'taxocommerce-auditor',
				'search_term'   => 'TaxoCommerce Auditor',
				'wordpress_org' => true,
				'description'   => __( 'Auditoría independiente de calidad editorial y contenido.', 'taxocommerce-core' ),
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
			wp_die( esc_html__( 'Extensión TaxoCommerce no reconocida.', 'taxocommerce-core' ) );
		}

		$extension   = (array) $catalog[ $extension_id ];
		$plugin_file = sanitize_text_field( (string) $extension['plugin_file'] );
		if ( $this->installed_plugin_data( $plugin_file ) ) {
			$this->redirect_extension_result( 'error', __( 'La extensión ya está instalada.', 'taxocommerce-core' ) );
		}

		$package = $this->local_package_path( $extension );
		if ( '' === $package ) {
			$this->redirect_extension_result(
				'error',
				__( 'No se ha encontrado el ZIP local de esta extensión en uploads/taxocommerce-core/plugins.', 'taxocommerce-core' )
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
			$this->redirect_extension_result( 'error', __( 'WordPress no pudo instalar el ZIP local.', 'taxocommerce-core' ) );
		}

		wp_clean_plugins_cache( true );
		if ( ! $this->installed_plugin_data( $plugin_file ) ) {
			$this->redirect_extension_result(
				'error',
				__( 'El ZIP se procesó, pero no contiene la estructura esperada para esta extensión.', 'taxocommerce-core' )
			);
		}

		$this->redirect_extension_result( 'ok', __( 'Extensión instalada desde el ZIP local. Ya puedes activarla.', 'taxocommerce-core' ) );
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
			wp_die( esc_html__( 'Extensión TaxoCommerce no reconocida.', 'taxocommerce-core' ) );
		}

		$plugin_file = sanitize_text_field( (string) $catalog[ $extension_id ]['plugin_file'] );
		if ( ! $this->installed_plugin_data( $plugin_file ) ) {
			$this->redirect_extension_result( 'error', __( 'La extensión todavía no está instalada.', 'taxocommerce-core' ) );
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$result = activate_plugin( $plugin_file, '', false, false );
		if ( is_wp_error( $result ) ) {
			$this->redirect_extension_result( 'error', $result->get_error_message() );
		}

		$this->redirect_extension_result( 'ok', __( 'Extensión activada.', 'taxocommerce-core' ) );
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
			wp_die( esc_html__( 'Extensión TaxoCommerce no reconocida.', 'taxocommerce-core' ) );
		}

		$plugin_file = sanitize_text_field( (string) $catalog[ $extension_id ]['plugin_file'] );
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( $plugin_file, false, false );
		$this->redirect_extension_result( 'ok', __( 'Extensión desactivada.', 'taxocommerce-core' ) );
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
	 * Redirect old Ampliar familia bookmarks to the single Extensiones page.
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
	 * Redirect links to the former dedicated Anomalías screen to Contenidos.
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

		$upload_url = admin_url( 'plugin-install.php?tab=upload' );
		$result     = isset( $_GET['tc_result'] ) ? sanitize_key( wp_unslash( $_GET['tc_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice only.
		$message    = isset( $_GET['tc_message'] ) ? sanitize_text_field( wp_unslash( $_GET['tc_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice only.
		$catalog    = $this->family_extensions();
		?>
		<div class="wrap tcc-family-screen">
			<header class="tcc-page-header">
				<span class="tcc-page-header__icon" aria-hidden="true"><span class="dashicons dashicons-screenoptions"></span></span>
				<div class="tcc-page-header__content">
					<p class="tcc-page-header__eyebrow"><?php esc_html_e( 'TaxoCommerce Core', 'taxocommerce-core' ); ?></p>
					<h1><?php echo esc_html__( 'Extensiones', 'taxocommerce-core' ); ?></h1>
					<p><?php echo esc_html__( 'Instala, activa, desactiva y administra las extensiones de la familia TaxoCommerce desde una única pantalla.', 'taxocommerce-core' ); ?></p>
				</div>
			</header>

			<?php if ( 'ok' === $result && $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php elseif ( 'error' === $result && $message ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'Familia TaxoCommerce:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html__( 'Core conoce todas las extensiones de la familia y ofrece los mismos canales de instalación para todas: buscador oficial de WordPress.org y carga de ZIP. Si Core detecta además un ZIP local preparado, permite instalarlo directamente.', 'taxocommerce-core' ); ?></p></div>

			<?php $local_package_dir = $this->local_package_directory(); ?>
			<p class="description"><strong><?php echo esc_html__( 'Repositorio local de ZIP:', 'taxocommerce-core' ); ?></strong> <code><?php echo esc_html( $local_package_dir ? $local_package_dir : 'uploads/taxocommerce-core/plugins' ); ?></code></p>
			<div class="tcc-extension-toolbar"><a class="button button-primary" href="<?php echo esc_url( $upload_url ); ?>"><?php echo esc_html__( 'Subir extensión ZIP', 'taxocommerce-core' ); ?></a><span class="description"><?php echo esc_html__( 'También puedes buscar cualquier extensión de la familia en el instalador oficial de WordPress.', 'taxocommerce-core' ); ?></span></div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Extensión', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Versión', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Estado', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Descripción', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Acciones', 'taxocommerce-core' ); ?></th>
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
									echo esc_html__( 'Activo', 'taxocommerce-core' );
								} elseif ( $installed ) {
									echo esc_html__( 'Instalado · inactivo', 'taxocommerce-core' );
								} else {
									echo esc_html__( 'No instalado', 'taxocommerce-core' );
									if ( $local_package ) {
										echo '<br><small>' . esc_html__( 'ZIP local disponible', 'taxocommerce-core' ) . '</small>';
									}
								}
								?>
							</td>
							<td><?php echo esc_html( (string) ( $extension['description'] ?? '' ) ); ?></td>
							<td>
								<?php if ( ! $installed ) : ?>
									<div class="tcc-extension-actions">
										<a class="button button-small" href="<?php echo esc_url( $search_url ); ?>"><?php echo esc_html__( 'Buscar en WordPress.org', 'taxocommerce-core' ); ?></a>
										<a class="button button-small" href="<?php echo esc_url( $upload_url ); ?>"><?php echo esc_html__( 'Subir ZIP', 'taxocommerce-core' ); ?></a>
										<?php if ( $local_package ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="taxocommerce_core_install_extension">
												<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension_id ); ?>">
												<?php wp_nonce_field( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' ); ?>
												<button class="button button-primary button-small"><?php echo esc_html__( 'Instalar ZIP local', 'taxocommerce-core' ); ?></button>
											</form>
										<?php endif; ?>
									</div>
								<?php elseif ( ! $active ) : ?>
									<div class="tcc-extension-actions">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="taxocommerce_core_activate_extension">
											<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension_id ); ?>">
											<?php wp_nonce_field( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' ); ?>
											<button class="button button-primary button-small"><?php echo esc_html__( 'Activar', 'taxocommerce-core' ); ?></button>
										</form>
									</div>
								<?php else : ?>
									<div class="tcc-extension-actions">
										<?php if ( $admin_url ) : ?>
											<a class="button button-small" href="<?php echo esc_url( $admin_url ); ?>"><?php echo esc_html__( 'Abrir', 'taxocommerce-core' ); ?></a>
										<?php endif; ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="taxocommerce_core_deactivate_extension">
											<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension_id ); ?>">
											<?php wp_nonce_field( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' ); ?>
											<button class="button button-small"><?php echo esc_html__( 'Desactivar', 'taxocommerce-core' ); ?></button>
										</form>
									</div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Extensiones activas registradas', 'taxocommerce-core' ); ?></h2>
			<p><?php echo esc_html__( 'Cuando una extensión está activa puede registrar nuevas páginas del menú, KPIs y otros servicios del Core. Cada extensión gestiona localmente sus propios procesos.', 'taxocommerce-core' ); ?></p>
		</div>
		<?php
	}

}
