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
	 * Runtime process registry.
	 *
	 * @var array
	 */
	private $processes = array();

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

		/**
		 * Lets extensions register background processes.
		 *
		 * @param TaxoCommerce_Core $core Core instance.
		 */
		do_action( 'taxocommerce_core_register_processes', $this );

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy_family_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_family_styles' ) );
		add_filter( 'admin_body_class', array( $this, 'family_body_class' ) );
		add_action( 'admin_post_taxocommerce_core_process_action', array( $this, 'handle_process_action' ) );
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

		add_submenu_page(
			'taxocommerce-core',
			__( 'Inicio', 'taxocommerce-core' ),
			__( 'Inicio', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core',
			array( $this, 'render_dashboard' )
		);

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
			__( 'Procesos automáticos', 'taxocommerce-core' ),
			__( 'Procesos automáticos', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-processes',
			array( $this, 'render_processes' )
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
				'wordpress_org' => false,
				'description'   => __( 'Diagnosticos del servidor, WordPress y Plugin Validation con informes y correo opcional.', 'taxocommerce-core' ),
			),
			'templates' => array(
				'id'            => 'templates',
				'name'          => 'TaxoCommerce Templates',
				'plugin_file'   => 'taxocommerce-templates/taxocommerce-templates.php',
				'org_slug'      => 'taxocommerce-templates',
				'search_term'   => 'TaxoCommerce Templates',
				'wordpress_org' => false,
				'description'   => 'Roles editoriales, asignación automática y gestión de plantillas.',
			),
			'dependiente' => array(
				'id'            => 'dependiente',
				'name'          => 'TaxoCommerce Dependiente',
				'plugin_file'   => 'taxocommerce-dependiente/taxocommerce-dependiente.php',
				'org_slug'      => 'taxocommerce-dependiente',
				'search_term'   => 'TaxoCommerce Dependiente',
				'wordpress_org' => false,
				'description'   => __( 'Academia independiente de diez lecciones y asistente de catálogo, sin reciclado automático.', 'taxocommerce-core' ),
			),
			'faqs' => array(
				'id'            => 'faqs',
				'name'          => 'TaxoCommerce FAQs',
				'plugin_file'   => 'taxocommerce-faqs/taxocommerce-faqs.php',
				'org_slug'      => 'taxocommerce-faqs',
				'search_term'   => 'TaxoCommerce FAQs',
				'wordpress_org' => false,
				'description'   => 'Preguntas frecuentes, edición manual, CSV, indicadores y Vocabulary.',
			),
			'vocabulary' => array(
				'id'            => 'vocabulary',
				'name'          => 'TaxoCommerce Vocabulary',
				'plugin_file'   => 'taxocommerce-vocabulary/taxocommerce-vocabulary.php',
				'org_slug'      => 'taxocommerce-vocabulary',
				'search_term'   => 'TaxoCommerce Vocabulary',
				'wordpress_org' => false,
				'admin_url'     => admin_url( 'admin.php?page=taxocommerce-vocabulary' ),
				'description'   => __( 'Inventario transversal de etiquetas y atributos con Clasificador automático gestionado por Procesos.', 'taxocommerce-core' ),
			),
			'google_bing' => array(
				'id'            => 'google_bing',
				'name'          => 'TaxoCommerce Google / Bing',
				'plugin_file'   => 'taxocommerce-google-bing/taxocommerce-google-bing.php',
				'org_slug'      => 'taxocommerce-google-bing',
				'search_term'   => 'TaxoCommerce Google Bing',
				'wordpress_org' => false,
				'description'   => __( 'Conexiones centralizadas y futuras fuentes de datos de Google y Microsoft Bing.', 'taxocommerce-core' ),
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
	 * Registers one background process descriptor.
	 *
	 * @param string $id   Process ID.
	 * @param array  $args Process metadata.
	 * @return bool
	 */
	public function register_process( $id, $args = array() ) {
		$id = sanitize_key( $id );
		if ( '' === $id || isset( $this->processes[ $id ] ) ) {
			return false;
		}

		$args = wp_parse_args(
			$args,
			array(
				'name'        => $id,
				'module'      => '',
				'status'      => 'idle',
				'progress'    => 0,
				'description' => '',
				'admin_url'   => '',
				'actions'     => array(),
			)
		);

		$status = sanitize_key( (string) $args['status'] );
		if ( ! in_array( $status, array( 'idle', 'queued', 'running', 'paused', 'completed', 'error' ), true ) ) {
			$status = 'idle';
		}

		$this->processes[ $id ] = array(
			'id'          => $id,
			'name'        => sanitize_text_field( (string) $args['name'] ),
			'module'      => sanitize_key( (string) $args['module'] ),
			'status'      => $status,
			'progress'    => max( 0, min( 100, absint( $args['progress'] ) ) ),
			'actions'     => isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array(),
			'description' => sanitize_text_field( (string) $args['description'] ),
			'admin_url'   => esc_url_raw( (string) $args['admin_url'] ),
		);
		return true;
	}

	/** Execute only actions registered by a trusted module. */
	public function handle_process_action() {
		$this->guard();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No autorizado.', 'taxocommerce-core' ) );
		}
		check_admin_referer( 'taxocommerce_core_process_action', 'taxocommerce_core_process_nonce' );
		$process_id = isset( $_POST['process_id'] ) ? sanitize_key( wp_unslash( $_POST['process_id'] ) ) : '';
		$command = isset( $_POST['command'] ) ? sanitize_key( wp_unslash( $_POST['command'] ) ) : '';
		$process = isset( $this->processes[ $process_id ] ) ? $this->processes[ $process_id ] : array();
		$callback = isset( $process['actions'][ $command ] ) ? $process['actions'][ $command ] : null;
		if ( ! is_callable( $callback ) ) {
			wp_die( esc_html__( 'Acción no disponible para este proceso.', 'taxocommerce-core' ) );
		}
		$result = call_user_func( $callback );
		$url = add_query_arg( array(
			'page' => 'taxocommerce-core-processes',
			'tc_process_notice' => is_wp_error( $result ) ? 'error' : 'ok',
			'tc_process_message' => is_wp_error( $result ) ? $result->get_error_message() : 'Acción enviada al trabajador.',
		), admin_url( 'admin.php' ) );
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
			$this->processes,
			$this->legacy_detected(),
			$this->woocommerce_detected()
		);
	}

	/**
	 * Processes page.
	 *
	 * @return void
	 */
	public function render_processes() {
		$this->guard();
		?>
		<div class="wrap tcc-family-screen">
			<header class="tcc-page-header">
				<span class="tcc-page-header__icon" aria-hidden="true"><span class="dashicons dashicons-admin-settings"></span></span>
				<div class="tcc-page-header__content">
					<p class="tcc-page-header__eyebrow"><?php esc_html_e( 'TaxoCommerce Core', 'taxocommerce-core' ); ?></p>
					<h1><?php echo esc_html__( 'Procesos automáticos', 'taxocommerce-core' ); ?></h1>
					<p><?php echo esc_html__( 'Aquí se registrarán y controlarán los trabajos automáticos de las extensiones.', 'taxocommerce-core' ); ?></p>
				</div>
			</header>
			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'Equivalente actual:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html__( 'Supervisor, Gestor de procesos, workers, WP-Cron, colas, cursores y mecanismos de recuperación. Todavía no se han trasladado al nuevo Core.', 'taxocommerce-core' ); ?></p></div>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Proceso', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Extensión', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Estado', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Progreso', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Detalle', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Acciones', 'taxocommerce-core' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $this->processes ) ) : ?>
						<tr><td colspan="6"><?php echo esc_html__( 'Todavía no hay procesos del nuevo Core registrados.', 'taxocommerce-core' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $this->processes as $process ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $process['name'] ); ?></strong></td>
								<td><?php echo esc_html( $process['module'] ); ?></td>
								<td><?php echo esc_html( $process['status'] ); ?></td>
								<td><?php echo esc_html( (string) absint( $process['progress'] ?? 0 ) ); ?>%</td>
								<td><?php echo esc_html( (string) ( $process['description'] ?? '' ) ); ?></td>
								<td>
								<?php if ( ! empty( $process['admin_url'] ) ) : ?><a class="button button-small" href="<?php echo esc_url( $process['admin_url'] ); ?>"><?php echo esc_html__( 'Abrir', 'taxocommerce-core' ); ?></a><?php endif; ?>
								<?php foreach ( (array) ( $process['actions'] ?? array() ) as $command => $callback ) : ?>
									<?php if ( ! is_callable( $callback ) ) { continue; } ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:2px">
										<input type="hidden" name="action" value="taxocommerce_core_process_action">
										<input type="hidden" name="process_id" value="<?php echo esc_attr( $process['id'] ); ?>">
										<input type="hidden" name="command" value="<?php echo esc_attr( $command ); ?>">
										<?php wp_nonce_field( 'taxocommerce_core_process_action', 'taxocommerce_core_process_nonce' ); ?>
										<button class="button button-small"><?php echo esc_html( ucfirst( $command ) ); ?></button>
									</form>
								<?php endforeach; ?>
							</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
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

			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'Familia TaxoCommerce:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html__( 'si una extensión todavía no está publicada en WordPress.org, puedes instalarla con su ZIP. Cuando se publique, Core podrá localizarla mediante el buscador oficial.', 'taxocommerce-core' ); ?></p></div>

			<p><a class="button button-primary" href="<?php echo esc_url( $upload_url ); ?>"><?php echo esc_html__( 'Subir extensión ZIP', 'taxocommerce-core' ); ?></a></p>

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
						$wordpress_org = ! isset( $extension['wordpress_org'] ) || (bool) $extension['wordpress_org'];
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
								}
								?>
							</td>
							<td><?php echo esc_html( (string) ( $extension['description'] ?? '' ) ); ?></td>
							<td>
								<?php if ( ! $installed ) : ?>
									<?php if ( $wordpress_org ) : ?><a class="button button-small" href="<?php echo esc_url( $search_url ); ?>"><?php echo esc_html__( 'Buscar en WordPress.org', 'taxocommerce-core' ); ?></a><?php endif; ?>
									<a class="button button-small" href="<?php echo esc_url( $upload_url ); ?>"><?php echo esc_html__( 'Subir ZIP', 'taxocommerce-core' ); ?></a>
								<?php elseif ( ! $active ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<input type="hidden" name="action" value="taxocommerce_core_activate_extension">
										<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension_id ); ?>">
										<?php wp_nonce_field( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' ); ?>
										<button class="button button-primary button-small"><?php echo esc_html__( 'Activar', 'taxocommerce-core' ); ?></button>
									</form>
								<?php else : ?>
									<?php if ( $admin_url ) : ?>
										<a class="button button-small" href="<?php echo esc_url( $admin_url ); ?>"><?php echo esc_html__( 'Abrir', 'taxocommerce-core' ); ?></a>
									<?php endif; ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
										<input type="hidden" name="action" value="taxocommerce_core_deactivate_extension">
										<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension_id ); ?>">
										<?php wp_nonce_field( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' ); ?>
										<button class="button button-small"><?php echo esc_html__( 'Desactivar', 'taxocommerce-core' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Extensiones activas registradas', 'taxocommerce-core' ); ?></h2>
			<p><?php echo esc_html__( 'Cuando una extensión está activa puede registrar nuevas páginas del menú, procesos automáticos, KPIs y otros servicios del Core.', 'taxocommerce-core' ); ?></p>
		</div>
		<?php
	}

}
