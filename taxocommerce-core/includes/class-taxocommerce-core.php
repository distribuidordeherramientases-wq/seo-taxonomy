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
				'description' => 'Servicios comunes de contenidos, jerarquía, procesos, registro técnico y extensiones.',
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
		add_action( 'admin_post_taxocommerce_core_clear_logs', array( $this, 'clear_logs' ) );
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
			__( 'Ampliar familia', 'taxocommerce-core' ),
			__( 'Ampliar familia', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-family',
			array( $this, 'render_family_catalog' )
		);

		add_submenu_page(
			'taxocommerce-core',
			__( 'Extensiones', 'taxocommerce-core' ),
			__( 'Extensiones', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-modules',
			array( $this, 'render_modules' )
		);

		add_submenu_page(
			'taxocommerce-core',
			__( 'Registro técnico', 'taxocommerce-core' ),
			__( 'Registro técnico', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-logs',
			array( $this, 'render_logs' )
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
				'search_term' => 'TaxoCommerce Import-Export',
				'description' => __( 'Importación y exportación de catálogo, contenidos y Media.', 'taxocommerce-core' ),
			),
			'images' => array(
				'id'          => 'images',
				'name'        => 'TaxoCommerce Images',
				'plugin_file' => 'taxocommerce-images/taxocommerce-images.php',
				'org_slug'    => 'taxocommerce-images',
				'search_term' => 'TaxoCommerce Images',
				'description' => __( 'Gestión, auditoría, miniaturas, metadatos y optimización de imágenes.', 'taxocommerce-core' ),
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
				'description' => '',
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
			'description' => sanitize_text_field( (string) $args['description'] ),
		);
		return true;
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
	 * Dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		$this->guard();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'TaxoCommerce Core', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Núcleo modular piloto que funciona de forma independiente y puede convivir con el TaxoCommerce SEO actual durante la migración.', 'taxocommerce-core' ); ?></p>
			<table class="widefat striped" style="max-width:900px;">
				<tbody>
					<tr><th scope="row"><?php echo esc_html__( 'Versión del Core', 'taxocommerce-core' ); ?></th><td><?php echo esc_html( TAXOCOMMERCE_CORE_VERSION ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'WooCommerce', 'taxocommerce-core' ); ?></th><td><?php echo $this->woocommerce_detected() ? esc_html__( 'Detectado', 'taxocommerce-core' ) : esc_html__( 'No detectado', 'taxocommerce-core' ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'TaxoCommerce SEO actual', 'taxocommerce-core' ); ?></th><td><?php echo $this->legacy_detected() ? esc_html__( 'Detectado · modo convivencia', 'taxocommerce-core' ) : esc_html__( 'No detectado', 'taxocommerce-core' ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Extensiones registradas', 'taxocommerce-core' ); ?></th><td><?php echo esc_html( (string) count( $this->modules ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Procesos registrados', 'taxocommerce-core' ); ?></th><td><?php echo esc_html( (string) count( $this->processes ) ); ?></td></tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Mapa de migración', 'taxocommerce-core' ); ?></h2>
			<p><?php echo esc_html__( 'Esta tabla permite identificar qué función del plugin actual corresponde a cada pieza del nuevo Core.', 'taxocommerce-core' ); ?></p>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Nuevo Core', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Equivalente en el plugin actual', 'taxocommerce-core' ); ?></th>
						<th><?php echo esc_html__( 'Estado de migración', 'taxocommerce-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><strong><?php echo esc_html__( 'Contenidos', 'taxocommerce-core' ); ?></strong></td><td><?php echo esc_html__( 'Edición de productos, categorías WooCommerce, posts y páginas repartida entre WordPress, WooCommerce y herramientas TaxoCommerce.', 'taxocommerce-core' ); ?></td><td><?php echo esc_html__( 'Piloto funcional', 'taxocommerce-core' ); ?></td></tr>
					<tr><td><strong><?php echo esc_html__( 'Jerarquía SEO', 'taxocommerce-core' ); ?></strong></td><td><?php echo esc_html__( 'Cluster → Hub primario → Hub secundario → Categoría → Producto; nodos y relaciones del sistema actual.', 'taxocommerce-core' ); ?></td><td><?php echo esc_html__( 'Migrado al modelo nativo del Core', 'taxocommerce-core' ); ?></td></tr>
					<tr><td><strong><?php echo esc_html__( 'Gestor de menús', 'taxocommerce-core' ); ?></strong></td><td><?php echo esc_html__( 'Menu Manager del plugin actual, basado en la jerarquía SEO y en menús nativos de WordPress.', 'taxocommerce-core' ); ?></td><td><?php echo esc_html__( 'Migrado al Core', 'taxocommerce-core' ); ?></td></tr>
					<tr><td><strong><?php echo esc_html__( 'Procesos automáticos', 'taxocommerce-core' ); ?></strong></td><td><?php echo esc_html__( 'Supervisor, Gestor de procesos, workers, WP-Cron, cursores, colas y recuperaciones del plugin actual.', 'taxocommerce-core' ); ?></td><td><?php echo esc_html__( 'Contrato creado; motores pendientes', 'taxocommerce-core' ); ?></td></tr>
					<tr><td><strong><?php echo esc_html__( 'Extensiones', 'taxocommerce-core' ); ?></strong></td><td><?php echo esc_html__( 'Los módulos que hoy viven dentro del monolito: Imágenes, Vocabulary, Auditor, Mercado, Proveedores, Assistant, etc.', 'taxocommerce-core' ); ?></td><td><?php echo esc_html__( 'Sistema nuevo', 'taxocommerce-core' ); ?></td></tr>
					<tr><td><strong><?php echo esc_html__( 'Registro técnico', 'taxocommerce-core' ); ?></strong></td><td><?php echo esc_html__( 'Logs y diagnósticos que actualmente están repartidos entre distintos servicios y pantallas.', 'taxocommerce-core' ); ?></td><td><?php echo esc_html__( 'Base funcional', 'taxocommerce-core' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Processes page.
	 *
	 * @return void
	 */
	public function render_processes() {
		$this->guard();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Procesos automáticos', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Aquí se registrarán y controlarán los trabajos automáticos de las extensiones.', 'taxocommerce-core' ); ?></p>
			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'Equivalente actual:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html__( 'Supervisor, Gestor de procesos, workers, WP-Cron, colas, cursores y mecanismos de recuperación. Todavía no se han trasladado al nuevo Core.', 'taxocommerce-core' ); ?></p></div>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Proceso', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Extensión', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Estado', 'taxocommerce-core' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $this->processes ) ) : ?>
						<tr><td colspan="3"><?php echo esc_html__( 'Todavía no hay procesos del nuevo Core registrados.', 'taxocommerce-core' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $this->processes as $process ) : ?>
							<tr><td><?php echo esc_html( $process['name'] ); ?></td><td><?php echo esc_html( $process['module'] ); ?></td><td><?php echo esc_html( $process['status'] ); ?></td></tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}


	/**
	 * TaxoCommerce family installer/discovery page.
	 *
	 * @return void
	 */
	public function render_family_catalog() {
		$this->guard();

		$catalog    = $this->family_extensions();
		$upload_url = admin_url( 'plugin-install.php?tab=upload' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Ampliar familia TaxoCommerce', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Instala nuevas extensiones de la familia. Mientras una extensión no esté publicada en WordPress.org puedes instalarla mediante su ZIP.', 'taxocommerce-core' ); ?></p>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;max-width:1100px;margin-top:18px;">
				<?php foreach ( $catalog as $extension_id => $extension ) : ?>
					<?php
					$plugin_file = sanitize_text_field( (string) ( isset( $extension['plugin_file'] ) ? $extension['plugin_file'] : '' ) );
					$plugin_data = $plugin_file ? $this->installed_plugin_data( $plugin_file ) : array();
					$installed   = ! empty( $plugin_data );
					$active      = $installed && $this->extension_is_active( $plugin_file );
					$runtime     = isset( $this->modules[ $extension_id ] ) ? $this->modules[ $extension_id ] : array();
					$version     = ! empty( $runtime['version'] ) ? $runtime['version'] : ( isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '' );
					$admin_url   = ! empty( $runtime['admin_url'] ) ? $runtime['admin_url'] : '';
					$search_term = sanitize_text_field( (string) ( isset( $extension['search_term'] ) ? $extension['search_term'] : $extension['name'] ) );
					$search_url  = add_query_arg(
						array(
							's'    => $search_term,
							'tab'  => 'search',
							'type' => 'term',
						),
						admin_url( 'plugin-install.php' )
					);
					?>
					<div class="card" style="max-width:none;margin:0;padding:18px;">
						<h2 style="margin-top:0;"><?php echo esc_html( (string) $extension['name'] ); ?></h2>
						<p><?php echo esc_html( (string) ( isset( $extension['description'] ) ? $extension['description'] : '' ) ); ?></p>
						<p>
							<strong><?php echo esc_html__( 'Estado:', 'taxocommerce-core' ); ?></strong>
							<?php
							if ( $active ) {
								echo esc_html__( 'Activo', 'taxocommerce-core' );
							} elseif ( $installed ) {
								echo esc_html__( 'Instalado · inactivo', 'taxocommerce-core' );
							} else {
								echo esc_html__( 'No instalado', 'taxocommerce-core' );
							}
							?>
							<?php if ( $version ) : ?> · <?php echo esc_html__( 'Versión', 'taxocommerce-core' ); ?> <?php echo esc_html( (string) $version ); ?><?php endif; ?>
						</p>

						<?php if ( ! $installed ) : ?>
							<p>
								<a class="button" href="<?php echo esc_url( $search_url ); ?>"><?php echo esc_html__( 'Buscar en WordPress.org', 'taxocommerce-core' ); ?></a>
								<a class="button button-primary" href="<?php echo esc_url( $upload_url ); ?>"><?php echo esc_html__( 'Subir ZIP', 'taxocommerce-core' ); ?></a>
							</p>
							<p class="description"><?php
							if ( 'images' === $extension_id ) {
								echo esc_html__( 'Sube el ZIP de TaxoCommerce Images. Tras instalarlo, vuelve al Core para activarlo.', 'taxocommerce-core' );
							} elseif ( 'import_export' === $extension_id ) {
								echo esc_html__( 'Sube el ZIP de TaxoCommerce Import-Export. Tras instalarlo, vuelve al Core para activarlo.', 'taxocommerce-core' );
							}
							?></p>
						<?php elseif ( ! $active ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="taxocommerce_core_activate_extension">
								<input type="hidden" name="extension_id" value="<?php echo esc_attr( $extension_id ); ?>">
								<?php wp_nonce_field( 'taxocommerce_core_extension_action', 'taxocommerce_core_nonce' ); ?>
								<button class="button button-primary"><?php echo esc_html__( 'Activar extensión', 'taxocommerce-core' ); ?></button>
							</form>
						<?php else : ?>
							<p>
								<?php if ( $admin_url ) : ?><a class="button button-primary" href="<?php echo esc_url( $admin_url ); ?>"><?php echo esc_html__( 'Abrir', 'taxocommerce-core' ); ?></a><?php endif; ?>
								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=taxocommerce-core-modules' ) ); ?>"><?php echo esc_html__( 'Gestionar extensión', 'taxocommerce-core' ); ?></a>
							</p>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
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
		<div class="wrap">
			<h1><?php echo esc_html__( 'Extensiones', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Amplía TaxoCommerce instalando plugins de la misma familia. Pueden funcionar de forma independiente y, cuando Core está activo, integran sus funciones en este menú.', 'taxocommerce-core' ); ?></p>

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
						$admin_url   = ! empty( $runtime['admin_url'] ) ? $runtime['admin_url'] : '';
						$search_term = sanitize_text_field( (string) ( $extension['search_term'] ?? $extension['name'] ?? '' ) );
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
									<a class="button button-small" href="<?php echo esc_url( $search_url ); ?>"><?php echo esc_html__( 'Buscar en WordPress.org', 'taxocommerce-core' ); ?></a>
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

	/**
	 * Logs page.
	 *
	 * @return void
	 */
	public function render_logs() {
		$this->guard();
		$entries = $this->logger ? $this->logger->all() : array();
		$url     = wp_nonce_url(
			admin_url( 'admin-post.php?action=taxocommerce_core_clear_logs' ),
			'taxocommerce_core_clear_logs'
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Registro técnico', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Registro común de avisos, errores y sucesos del Core y, más adelante, de todas sus extensiones.', 'taxocommerce-core' ); ?></p>
			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'Equivalente actual:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html__( 'los logs y diagnósticos que ahora están repartidos entre procesos, servicios y herramientas del plugin actual.', 'taxocommerce-core' ); ?></p></div>
			<p><a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html__( 'Vaciar registro técnico', 'taxocommerce-core' ); ?></a></p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Hora UTC', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Nivel', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Mensaje', 'taxocommerce-core' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $entries ) ) : ?>
						<tr><td colspan="3"><?php echo esc_html__( 'No hay registros técnicos.', 'taxocommerce-core' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $entries as $entry ) : ?>
							<tr><td><?php echo esc_html( (string) $entry['time'] ); ?></td><td><?php echo esc_html( (string) $entry['level'] ); ?></td><td><?php echo esc_html( (string) $entry['message'] ); ?></td></tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Clears logs.
	 *
	 * @return void
	 */
	public function clear_logs() {
		$this->guard();
		check_admin_referer( 'taxocommerce_core_clear_logs' );

		if ( $this->logger ) {
			$this->logger->clear();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-core-logs' ) );
		exit;
	}
}
