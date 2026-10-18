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

		$this->logger  = new TaxoCommerce_Core_Logger();
		$this->content = new TaxoCommerce_Core_Content_Manager( $this->logger );

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
		add_filter( 'plugin_action_links_' . TAXOCOMMERCE_CORE_BASENAME, array( $this, 'plugin_action_links' ) );

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
			array( $this, 'render_hierarchy' )
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
					<tr><td><strong><?php echo esc_html__( 'Jerarquía SEO', 'taxocommerce-core' ); ?></strong></td><td><?php echo esc_html__( 'Cluster → Hub primario → Hub secundario → Categoría → Producto; nodos y relaciones del sistema actual.', 'taxocommerce-core' ); ?></td><td><?php echo esc_html__( 'Pendiente de migrar', 'taxocommerce-core' ); ?></td></tr>
					<tr><td><strong><?php echo esc_html__( 'Procesos automáticos', 'taxocommerce-core' ); ?></strong></td><td><?php echo esc_html__( 'Supervisor, Gestor de procesos, workers, WP-Cron, cursores, colas y recuperaciones del plugin actual.', 'taxocommerce-core' ); ?></td><td><?php echo esc_html__( 'Contrato creado; motores pendientes', 'taxocommerce-core' ); ?></td></tr>
					<tr><td><strong><?php echo esc_html__( 'Extensiones', 'taxocommerce-core' ); ?></strong></td><td><?php echo esc_html__( 'Los módulos que hoy viven dentro del monolito: Imágenes, Vocabulary, Auditor, Mercado, Proveedores, Assistant, etc.', 'taxocommerce-core' ); ?></td><td><?php echo esc_html__( 'Sistema nuevo', 'taxocommerce-core' ); ?></td></tr>
					<tr><td><strong><?php echo esc_html__( 'Registro técnico', 'taxocommerce-core' ); ?></strong></td><td><?php echo esc_html__( 'Logs y diagnósticos que actualmente están repartidos entre distintos servicios y pantallas.', 'taxocommerce-core' ); ?></td><td><?php echo esc_html__( 'Base funcional', 'taxocommerce-core' ); ?></td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Hierarchy pilot page.
	 *
	 * @return void
	 */
	public function render_hierarchy() {
		$this->guard();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Jerarquía SEO', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Modelo objetivo: Cluster → Hub primario → Hub secundario → Categoría de producto → Producto.', 'taxocommerce-core' ); ?></p>
			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'Equivalente actual:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html__( 'la jerarquía SEO del plugin TaxoCommerce actual, incluidos sus nodos y relaciones. Todavía no está migrada al nuevo Core.', 'taxocommerce-core' ); ?></p></div>
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
	 * Modules page.
	 *
	 * @return void
	 */
	public function render_modules() {
		$this->guard();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Extensiones', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Aquí aparecerán los plugins que amplían el Core: Imágenes, Vocabulary, Auditor, Conexiones, Mercado, Proveedores, Assistant y otros.', 'taxocommerce-core' ); ?></p>
			<div class="notice notice-info inline"><p><strong><?php echo esc_html__( 'Equivalente actual:', 'taxocommerce-core' ); ?></strong> <?php echo esc_html__( 'son los servicios que hoy están integrados dentro del plugin monolítico. En la nueva arquitectura pasarán a ser extensiones independientes cuando tenga sentido.', 'taxocommerce-core' ); ?></p></div>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Extensión', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Versión', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Estado', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Descripción', 'taxocommerce-core' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $this->modules as $module ) : ?>
						<tr><td><?php echo esc_html( $module['name'] ); ?></td><td><?php echo esc_html( $module['version'] ); ?></td><td><?php echo esc_html( $module['status'] ); ?></td><td><?php echo esc_html( $module['description'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
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
