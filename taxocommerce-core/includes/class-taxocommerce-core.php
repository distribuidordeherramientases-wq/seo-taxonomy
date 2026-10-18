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
				'description' => 'Shared content, hierarchy, process, logging, and extension services.',
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

		add_submenu_page(
			'taxocommerce-core',
			__( 'Dashboard', 'taxocommerce-core' ),
			__( 'Dashboard', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core',
			array( $this, 'render_dashboard' )
		);

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
			__( 'Hierarchy', 'taxocommerce-core' ),
			__( 'Hierarchy', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-hierarchy',
			array( $this, 'render_hierarchy' )
		);

		add_submenu_page(
			'taxocommerce-core',
			__( 'Processes', 'taxocommerce-core' ),
			__( 'Processes', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-processes',
			array( $this, 'render_processes' )
		);

		add_submenu_page(
			'taxocommerce-core',
			__( 'Modules', 'taxocommerce-core' ),
			__( 'Modules', 'taxocommerce-core' ),
			$capability,
			'taxocommerce-core-modules',
			array( $this, 'render_modules' )
		);

		add_submenu_page(
			'taxocommerce-core',
			__( 'Logs', 'taxocommerce-core' ),
			__( 'Logs', 'taxocommerce-core' ),
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
			wp_die( esc_html__( 'You do not have permission to manage TaxoCommerce Core.', 'taxocommerce-core' ) );
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
			<p><?php echo esc_html__( 'Pilot modular foundation running independently from the legacy TaxoCommerce SEO plugin.', 'taxocommerce-core' ); ?></p>
			<table class="widefat striped" style="max-width:900px;">
				<tbody>
					<tr><th scope="row"><?php echo esc_html__( 'Core version', 'taxocommerce-core' ); ?></th><td><?php echo esc_html( TAXOCOMMERCE_CORE_VERSION ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'WooCommerce', 'taxocommerce-core' ); ?></th><td><?php echo $this->woocommerce_detected() ? esc_html__( 'Detected', 'taxocommerce-core' ) : esc_html__( 'Not detected', 'taxocommerce-core' ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Legacy TaxoCommerce SEO', 'taxocommerce-core' ); ?></th><td><?php echo $this->legacy_detected() ? esc_html__( 'Detected - coexistence mode', 'taxocommerce-core' ) : esc_html__( 'Not detected', 'taxocommerce-core' ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Registered modules', 'taxocommerce-core' ); ?></th><td><?php echo esc_html( (string) count( $this->modules ) ); ?></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Registered processes', 'taxocommerce-core' ); ?></th><td><?php echo esc_html( (string) count( $this->processes ) ); ?></td></tr>
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
			<h1><?php echo esc_html__( 'Hierarchy', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Target model: Cluster → Primary hub → Secondary hub → Product category → Product. This pilot does not duplicate or migrate legacy hierarchy data yet.', 'taxocommerce-core' ); ?></p>
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
			<h1><?php echo esc_html__( 'Processes', 'taxocommerce-core' ); ?></h1>
			<p><?php echo esc_html__( 'Extensions can register their workers here. Core does not take over legacy workers yet.', 'taxocommerce-core' ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Process', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Module', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Status', 'taxocommerce-core' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $this->processes ) ) : ?>
						<tr><td colspan="3"><?php echo esc_html__( 'No Core processes registered yet.', 'taxocommerce-core' ); ?></td></tr>
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
			<h1><?php echo esc_html__( 'Modules', 'taxocommerce-core' ); ?></h1>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Module', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Version', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Status', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Description', 'taxocommerce-core' ); ?></th></tr></thead>
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
			<h1><?php echo esc_html__( 'Logs', 'taxocommerce-core' ); ?></h1>
			<p><a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html__( 'Clear Core logs', 'taxocommerce-core' ); ?></a></p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'UTC time', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Level', 'taxocommerce-core' ); ?></th><th><?php echo esc_html__( 'Message', 'taxocommerce-core' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $entries ) ) : ?>
						<tr><td colspan="3"><?php echo esc_html__( 'No log entries.', 'taxocommerce-core' ); ?></td></tr>
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
