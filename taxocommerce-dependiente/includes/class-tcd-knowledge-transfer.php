<?php
/**
 * Portable knowledge export/import for standalone TaxoCommerce Dependiente.
 *
 * Accepts:
 * - taxocommerce_dependiente_knowledge v1 (native standalone package).
 * - seo_dependiente_knowledge v1 (legacy monolith package).
 *
 * Legacy semantic rules and L9 memory are preserved losslessly in dedicated
 * standalone tables. Legacy completed lessons are historical evidence only:
 * they never mark the new L1-L10 snapshots as completed.
 *
 * @package TaxoCommerceDependiente
 */

defined( 'ABSPATH' ) || exit;

final class TCD_Knowledge_Transfer {

	const SCHEMA_NAME    = 'taxocommerce_dependiente_knowledge';
	const SCHEMA_VERSION = 1;
	const LEGACY_SCHEMA  = 'seo_dependiente_knowledge';
	const LEGACY_VERSION = 1;
	const DB_VERSION     = '1.0.0';
	const DB_OPTION      = 'tcd_knowledge_transfer_db_version';
	const BRAIN_OPTION   = 'tcd_brain_id';
	const LAST_IMPORT    = 'tcd_knowledge_last_import';
	const LAST_EXPORT    = 'tcd_knowledge_last_export';
	const MAX_BYTES      = 134217728; // 128 MiB, same ceiling as the historical tool.
	const REPORT_PREFIX  = 'tcd_knowledge_report_';

	public static function rules_table() {
		global $wpdb;
		return $wpdb->prefix . 'tcd_portable_rules';
	}

	public static function l9_table() {
		global $wpdb;
		return $wpdb->prefix . 'tcd_l9_memory';
	}

	public static function legacy_lessons_table() {
		global $wpdb;
		return $wpdb->prefix . 'tcd_legacy_lessons';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$rules   = self::rules_table();
		$l9      = self::l9_table();
		$lessons = self::legacy_lessons_table();

		dbDelta(
			"CREATE TABLE {$rules} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				rule_key varchar(191) NOT NULL,
				rule_type varchar(50) NOT NULL DEFAULT 'alias',
				expression varchar(255) NULL,
				normalized_expression varchar(255) NULL,
				canonical_expression varchar(255) NULL,
				match_type varchar(50) NOT NULL DEFAULT 'token',
				semantic_role varchar(50) NULL,
				source_group varchar(50) NULL,
				source_slug varchar(191) NULL,
				context_group varchar(50) NULL,
				context_slug varchar(191) NULL,
				target_group varchar(50) NULL,
				target_slug varchar(191) NULL,
				relation_type varchar(50) NULL,
				result_role varchar(50) NULL,
				weight int unsigned NOT NULL DEFAULT 100,
				priority smallint unsigned NOT NULL DEFAULT 5,
				confidence decimal(8,4) NULL,
				language varchar(10) NOT NULL DEFAULT 'es',
				source varchar(50) NOT NULL DEFAULT 'manual',
				metadata longtext NULL,
				active tinyint(1) unsigned NOT NULL DEFAULT 1,
				rule_hash char(64) NOT NULL,
				source_brain_id varchar(64) NULL,
				created_at datetime NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY rule_key (rule_key),
				KEY rule_hash (rule_hash),
				KEY active_type (active,rule_type)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$l9} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				lesson_key varchar(64) NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				signal_type varchar(50) NOT NULL DEFAULT 'term',
				signal_text varchar(250) NOT NULL,
				normalized_signal varchar(250) NOT NULL,
				semantic_group varchar(50) NULL,
				vocabulary_id bigint(20) unsigned NULL,
				weight int unsigned NOT NULL DEFAULT 50,
				source_hash char(64) NOT NULL,
				metadata longtext NULL,
				active tinyint(1) unsigned NOT NULL DEFAULT 1,
				source_brain_id varchar(64) NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY source_signal (lesson_key,product_id,source_hash),
				KEY product_active (product_id,active),
				KEY normalized_signal (normalized_signal(100))
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$lessons} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_brain_id varchar(64) NOT NULL,
				source_checkpoint_id varchar(64) NULL,
				lesson_key varchar(64) NOT NULL,
				lesson_order smallint unsigned NOT NULL DEFAULT 0,
				title text NULL,
				status varchar(20) NOT NULL DEFAULT 'completed',
				module_size int unsigned NOT NULL DEFAULT 0,
				module_count int unsigned NOT NULL DEFAULT 0,
				item_count bigint(20) unsigned NOT NULL DEFAULT 0,
				completed_items bigint(20) unsigned NOT NULL DEFAULT 0,
				snapshot_before bigint(20) unsigned NOT NULL DEFAULT 0,
				snapshot_after bigint(20) unsigned NOT NULL DEFAULT 0,
				metadata longtext NULL,
				completed_at datetime NULL,
				imported_at datetime NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY source_lesson (source_brain_id,lesson_key),
				KEY lesson_order (lesson_order)
			) {$charset};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION, false );
		self::brain_id();
	}

	public static function boot() {
		if ( self::DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
			self::install();
		}
		add_action( 'admin_post_tcd_knowledge_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_post_tcd_knowledge_analyze', array( __CLASS__, 'analyze_upload' ) );
		add_action( 'admin_post_tcd_knowledge_import', array( __CLASS__, 'import_upload' ) );
	}

	public static function brain_id() {
		$id = sanitize_text_field( (string) get_option( self::BRAIN_OPTION, '' ) );
		if ( '' !== $id ) {
			return $id;
		}
		$id = wp_generate_uuid4();
		update_option( self::BRAIN_OPTION, $id, false );
		return $id;
	}

	public static function export_url() {
		return wp_nonce_url(
			add_query_arg( array( 'action' => 'tcd_knowledge_export' ), admin_url( 'admin-post.php' ) ),
			'tcd_knowledge_export'
		);
	}

	public static function stats() {
		global $wpdb;
		$knowledge = TCD_Academy::knowledge_table();
		$rules     = self::rules_table();
		$l9        = self::l9_table();
		$lessons   = self::legacy_lessons_table();

		return array(
			'modern_knowledge' => absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $knowledge ) ) ),
			'modern_learned'   => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='learned'", $knowledge ) ) ),
			'modern_unlearned' => absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status='unlearned'", $knowledge ) ) ),
			'portable_rules'   => absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE active=1', $rules ) ) ),
			'l9_memory'        => absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE active=1', $l9 ) ) ),
			'legacy_lessons'   => absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $lessons ) ) ),
		);
	}

	public static function last_report() {
		$value = get_transient( self::REPORT_PREFIX . get_current_user_id() );
		return is_array( $value ) ? $value : array();
	}

	public static function last_import() {
		$value = get_option( self::LAST_IMPORT, array() );
		return is_array( $value ) ? $value : array();
	}


	public static function field_map() {
		return array(
			'Reglas semánticas' => array(
				'rule_key'              => 'tcd_portable_rules.rule_key',
				'rule_type'             => 'tcd_portable_rules.rule_type',
				'expression'            => 'tcd_portable_rules.expression',
				'normalized_expression' => 'tcd_portable_rules.normalized_expression',
				'canonical_expression'  => 'tcd_portable_rules.canonical_expression',
				'match_type'            => 'tcd_portable_rules.match_type',
				'semantic_role'         => 'tcd_portable_rules.semantic_role',
				'source_group'          => 'tcd_portable_rules.source_group',
				'source_slug'           => 'tcd_portable_rules.source_slug',
				'context_group'         => 'tcd_portable_rules.context_group',
				'context_slug'          => 'tcd_portable_rules.context_slug',
				'target_group'          => 'tcd_portable_rules.target_group',
				'target_slug'           => 'tcd_portable_rules.target_slug',
				'relation_type'         => 'tcd_portable_rules.relation_type',
				'result_role'           => 'tcd_portable_rules.result_role',
				'weight'                => 'tcd_portable_rules.weight',
				'priority'              => 'tcd_portable_rules.priority',
				'confidence'            => 'tcd_portable_rules.confidence',
				'language'              => 'tcd_portable_rules.language',
				'source'                => 'tcd_portable_rules.source',
				'metadata'              => 'tcd_portable_rules.metadata (limpiada de sesión/IP/email/usuario/log)',
				'active'                => 'tcd_portable_rules.active',
				'created_at'            => 'tcd_portable_rules.created_at',
				'updated_at'            => 'tcd_portable_rules.updated_at (fecha de fusión)',
				'rule_hash'             => 'tcd_portable_rules.rule_hash (recalculado para detectar equivalencias/conflictos)',
				'source_vocabulary_id'  => 'No se copia: el export antiguo ya lo sustituía por source_group + source_slug',
				'context_vocabulary_id' => 'No se copia: se transporta por context_group + context_slug',
				'target_vocabulary_id'  => 'No se copia: se transporta por target_group + target_slug',
			),
			'Memoria L9' => array(
				'lesson_key'        => 'tcd_l9_memory.lesson_key',
				'product_id'        => 'tcd_l9_memory.product_id',
				'signal_type'       => 'tcd_l9_memory.signal_type',
				'signal_text'       => 'tcd_l9_memory.signal_text',
				'normalized_signal' => 'tcd_l9_memory.normalized_signal',
				'semantic_group'    => 'tcd_l9_memory.semantic_group',
				'vocabulary_id'     => 'tcd_l9_memory.vocabulary_id',
				'weight'            => 'tcd_l9_memory.weight',
				'source_hash'       => 'tcd_l9_memory.source_hash',
				'metadata'          => 'tcd_l9_memory.metadata (limpiada de datos privados)',
				'active'            => 'tcd_l9_memory.active',
			),
			'Lecciones antiguas completadas' => array(
				'lesson_key'      => 'tcd_legacy_lessons.lesson_key',
				'lesson_order'    => 'tcd_legacy_lessons.lesson_order',
				'title'           => 'tcd_legacy_lessons.title',
				'status'          => 'tcd_legacy_lessons.status',
				'module_size'     => 'tcd_legacy_lessons.module_size',
				'module_count'    => 'tcd_legacy_lessons.module_count',
				'item_count'      => 'tcd_legacy_lessons.item_count',
				'completed_items' => 'tcd_legacy_lessons.completed_items',
				'snapshot_before' => 'tcd_legacy_lessons.snapshot_before',
				'snapshot_after'  => 'tcd_legacy_lessons.snapshot_after',
				'metadata'        => 'tcd_legacy_lessons.metadata',
				'completed_at'    => 'tcd_legacy_lessons.completed_at',
			),
		);
	}

	public static function compatibility_map() {
		return array(
			array(
				'legacy' => 'semantic_rules[]',
				'new'    => self::rules_table(),
				'mode'   => 'Migración directa',
				'note'   => 'Se conservan rule_key, expresiones, grupos/slugs, relación, peso, prioridad, confianza, idioma, fuente y metadata portable.',
			),
			array(
				'legacy' => 'academy.lesson9_memory[]',
				'new'    => self::l9_table(),
				'mode'   => 'Migración directa',
				'note'   => 'Se conservan producto, señal, grupo semántico, peso, hash y metadata.',
			),
			array(
				'legacy' => 'academy.completed_lessons[]',
				'new'    => self::legacy_lessons_table(),
				'mode'   => 'Histórico',
				'note'   => 'Se conserva como evidencia. No marca L1-L10 modernas como completadas porque faltan sus snapshots/material.',
			),
			array(
				'legacy' => 'manifest.knowledge_snapshot + catalog',
				'new'    => 'Metadatos del informe de importación',
				'mode'   => 'Informativo',
				'note'   => 'No se importa el índice de catálogo ni se altera el estado operativo moderno.',
			),
			array(
				'legacy' => 'búsquedas, sesiones, candidatos pendientes/rechazados',
				'new'    => 'No aplicable',
				'mode'   => 'No se exportan eventos brutos',
				'note'   => 'Sesiones, clics individuales y búsquedas de clientes quedan fuera. Solo el feedback moderno ya consolidado se materializa como reglas semánticas o señales L9 portables.',
			),
		);
	}


	public static function render_admin_section() {
		$stats       = self::stats();
		$report      = self::last_report();
		$last_import = self::last_import();
		$learning    = TCD_Academy::state();
		$busy        = 'running' === (string) ( $learning['status'] ?? '' );
		?>
		<section id="tcd-portable-knowledge" style="padding:18px;background:#fff;border:1px solid #c3c4c7">
			<h2 style="margin-top:0"><?php esc_html_e( 'Conocimiento portable · Exportar / Importar', 'taxocommerce-dependiente' ); ?></h2>
			<p><?php esc_html_e( 'Esta herramienta mueve el conocimiento consolidado de Dependiente sin copiar el índice del catálogo, sesiones, búsquedas ni clics individuales. El feedback de clientes que ya haya superado los umbrales se transporta únicamente como regla semántica o señal L9 consolidada. Acepta tanto el export antiguo como el formato del plugin moderno.', 'taxocommerce-dependiente' ); ?></p>

			<div style="display:flex;gap:12px;flex-wrap:wrap;margin:14px 0">
				<div class="card" style="min-width:155px"><strong style="display:block;font-size:22px"><?php echo esc_html( number_format_i18n( $stats['modern_knowledge'] ) ); ?></strong><?php esc_html_e( 'Conocimiento moderno', 'taxocommerce-dependiente' ); ?></div>
				<div class="card" style="min-width:155px"><strong style="display:block;font-size:22px"><?php echo esc_html( number_format_i18n( $stats['portable_rules'] ) ); ?></strong><?php esc_html_e( 'Reglas semánticas portables', 'taxocommerce-dependiente' ); ?></div>
				<div class="card" style="min-width:155px"><strong style="display:block;font-size:22px"><?php echo esc_html( number_format_i18n( $stats['l9_memory'] ) ); ?></strong><?php esc_html_e( 'Señales L9 importadas', 'taxocommerce-dependiente' ); ?></div>
				<div class="card" style="min-width:155px"><strong style="display:block;font-size:22px"><?php echo esc_html( number_format_i18n( $stats['legacy_lessons'] ) ); ?></strong><?php esc_html_e( 'Lecciones antiguas conservadas', 'taxocommerce-dependiente' ); ?></div>
			</div>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::export_url() ); ?>"><?php esc_html_e( 'Descargar todo el conocimiento portable', 'taxocommerce-dependiente' ); ?></a>
				<span class="description"><?php echo esc_html( sprintf( 'Brain ID: %s', self::brain_id() ) ); ?></span>
			</p>

			<h3><?php esc_html_e( 'Correspondencia antiguo → moderno', 'taxocommerce-dependiente' ); ?></h3>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Export antiguo', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Destino moderno', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Tratamiento', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Observación', 'taxocommerce-dependiente' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( self::compatibility_map() as $map ) : ?>
					<tr>
						<td><code><?php echo esc_html( $map['legacy'] ); ?></code></td>
						<td><code><?php echo esc_html( $map['new'] ); ?></code></td>
						<td><?php echo esc_html( $map['mode'] ); ?></td>
						<td><?php echo esc_html( $map['note'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<details style="margin:14px 0">
				<summary><strong><?php esc_html_e( 'Ver correspondencia campo por campo', 'taxocommerce-dependiente' ); ?></strong></summary>
				<?php foreach ( self::field_map() as $group => $fields ) : ?>
					<h4><?php echo esc_html( $group ); ?></h4>
					<table class="widefat striped" style="max-width:1000px">
						<thead><tr><th><?php esc_html_e( 'Campo antiguo', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Destino / transformación moderna', 'taxocommerce-dependiente' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $fields as $old_field => $new_field ) : ?>
							<tr><td><code><?php echo esc_html( $old_field ); ?></code></td><td><code><?php echo esc_html( $new_field ); ?></code></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>
			</details>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-top:18px">
				<div>
					<h3><?php esc_html_e( '1. Analizar un export antes de importarlo', 'taxocommerce-dependiente' ); ?></h3>
					<p><?php esc_html_e( 'No escribe datos. Identifica el formato, cuenta reglas/lecciones/memoria y muestra cómo se transformaría.', 'taxocommerce-dependiente' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="tcd_knowledge_analyze">
						<?php wp_nonce_field( 'tcd_knowledge_analyze' ); ?>
						<p><input type="file" name="knowledge_file" accept=".json,.gz,.json.gz,application/json,application/gzip" required></p>
						<?php submit_button( __( 'Analizar compatibilidad', 'taxocommerce-dependiente' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
				<div>
					<h3><?php esc_html_e( '2. Importar y fusionar', 'taxocommerce-dependiente' ); ?></h3>
					<p><?php esc_html_e( 'Añade lo que falta y conserva la versión local cuando detecta un conflicto. No elimina conocimiento existente.', 'taxocommerce-dependiente' ); ?></p>
					<?php if ( $busy ) : ?>
						<div class="notice notice-warning inline"><p><?php esc_html_e( 'Dependiente está aprendiendo. Detén el proceso antes de importar para mantener una foto coherente.', 'taxocommerce-dependiente' ); ?></p></div>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="tcd_knowledge_import">
						<?php wp_nonce_field( 'tcd_knowledge_import' ); ?>
						<p><input type="file" name="knowledge_file" accept=".json,.gz,.json.gz,application/json,application/gzip" required <?php disabled( $busy ); ?>></p>
						<p><label><input type="checkbox" name="confirm_merge" value="1" required <?php disabled( $busy ); ?>> <?php esc_html_e( 'Confirmo que quiero fusionar este conocimiento con el existente.', 'taxocommerce-dependiente' ); ?></label></p>
						<?php submit_button( __( 'Importar y fusionar conocimiento', 'taxocommerce-dependiente' ), 'primary', 'submit', false, $busy ? array( 'disabled' => 'disabled' ) : array() ); ?>
					</form>
				</div>
			</div>

			<?php if ( $report ) : ?>
				<h3><?php esc_html_e( 'Resultado de la última comprobación/importación', 'taxocommerce-dependiente' ); ?></h3>
				<?php if ( ! empty( $report['error'] ) ) : ?>
					<div class="notice notice-error inline"><p><?php echo esc_html( (string) $report['error'] ); ?></p></div>
				<?php else : ?>
					<p><strong><?php esc_html_e( 'Formato:', 'taxocommerce-dependiente' ); ?></strong> <?php echo esc_html( (string) ( $report['schema'] ?? '' ) ); ?>
					<?php if ( ! empty( $report['source_brain_id'] ) ) : ?> · <strong>Brain ID:</strong> <code><?php echo esc_html( (string) $report['source_brain_id'] ); ?></code><?php endif; ?></p>
					<table class="widefat striped" style="max-width:900px">
						<thead><tr><th><?php esc_html_e( 'Bloque', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Filas', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Compatibilidad', 'taxocommerce-dependiente' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( (array) ( $report['counts'] ?? array() ) as $key => $value ) : ?>
							<tr><td><code><?php echo esc_html( (string) $key ); ?></code></td><td><?php echo esc_html( number_format_i18n( absint( $value ) ) ); ?></td><td><?php echo esc_html( (string) ( $report['compatibility'][ $key ] ?? '—' ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( ! empty( $report['warning'] ) ) : ?><p class="description"><?php echo esc_html( (string) $report['warning'] ); ?></p><?php endif; ?>
					<?php if ( ! empty( $report['import'] ) && is_array( $report['import'] ) ) : ?>
						<h4><?php esc_html_e( 'Fusión realizada', 'taxocommerce-dependiente' ); ?></h4>
						<table class="widefat striped" style="max-width:900px"><thead><tr><th><?php esc_html_e( 'Almacén', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Insertados', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Idénticos', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Conflictos', 'taxocommerce-dependiente' ); ?></th><th><?php esc_html_e( 'Ignorados', 'taxocommerce-dependiente' ); ?></th></tr></thead><tbody>
						<?php foreach ( $report['import'] as $store => $values ) : if ( ! is_array( $values ) ) continue; ?>
							<tr><td><?php echo esc_html( (string) $store ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $values['inserted'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $values['identical'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $values['conflicts'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( number_format_i18n( absint( $values['ignored'] ?? 0 ) ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $last_import ) : ?>
				<p class="description" style="margin-top:14px"><?php echo esc_html( sprintf( 'Última importación: %s · %s', (string) ( $last_import['imported_at'] ?? '' ), (string) ( $last_import['source_schema'] ?? '' ) ) ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function export() {
		if ( ! self::can_manage() ) {
			wp_die( 'Permisos insuficientes.' );
		}
		check_admin_referer( 'tcd_knowledge_export' );
		self::install();

		$stats      = self::stats();
		$checkpoint = wp_generate_uuid4();
		$state      = TCD_Academy::state();

		update_option(
			self::LAST_EXPORT,
			array(
				'checkpoint_id' => $checkpoint,
				'exported_at'   => current_time( 'mysql' ),
				'stats'         => $stats,
			),
			false
		);

		$filename = 'dependiente_conocimiento_' . gmdate( 'Ymd_His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

		echo '{"schema":{"name":';
		echo wp_json_encode( self::SCHEMA_NAME );
		echo ',"version":';
		echo wp_json_encode( absint( self::SCHEMA_VERSION ) );
		echo '},';
		echo '"generated_at":' . wp_json_encode( gmdate( 'c' ) ) . ',';
		echo '"source":' . wp_json_encode(
			array(
				'home_url'            => home_url( '/' ),
				'site_url'            => site_url( '/' ),
				'brain_id'            => self::brain_id(),
				'checkpoint_id'       => $checkpoint,
				'dependiente_version' => TAXOCOMMERCE_DEPENDIENTE_VERSION,
				'wordpress_version'   => get_bloginfo( 'version' ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		) . ',';
		echo '"manifest":' . wp_json_encode( $stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ',';
		echo '"learning_state":' . wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ',';

		echo '"modern_knowledge":[';
		self::stream_table(
			TCD_Academy::knowledge_table(),
			static function ( $row ) {
				$context = json_decode( (string) ( $row['context_json'] ?? '' ), true );
				return array(
					'lesson'      => absint( $row['lesson'] ?? 0 ),
					'source_type' => sanitize_key( (string) ( $row['source_type'] ?? '' ) ),
					'source_id'   => absint( $row['source_id'] ?? 0 ),
					'signature'   => sanitize_text_field( (string) ( $row['signature'] ?? '' ) ),
					'title'       => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
					'context'     => is_array( $context ) ? $context : array(),
					'status'      => sanitize_key( (string) ( $row['status'] ?? 'learned' ) ),
					'reason'      => sanitize_textarea_field( (string) ( $row['reason'] ?? '' ) ),
					'learned_at'  => (string) ( $row['learned_at'] ?? '' ),
				);
			}
		);
		echo '],"portable_rules":[';
		self::stream_table( self::rules_table(), array( __CLASS__, 'portable_rule_for_export' ) );
		echo '],"lesson9_memory":[';
		self::stream_table( self::l9_table(), array( __CLASS__, 'l9_for_export' ) );
		echo '],"legacy_completed_lessons":[';
		self::stream_table( self::legacy_lessons_table(), array( __CLASS__, 'legacy_lesson_for_export' ) );
		echo ']}';
		exit;
	}

	private static function stream_table( $table, $mapper ) {
		global $wpdb;
		$last  = 0;
		$first = true;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT 250",
					$table,
					$last
				),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$last = absint( $row['id'] ?? 0 );
				$out  = call_user_func( $mapper, $row );
				if ( ! is_array( $out ) ) {
					continue;
				}
				if ( ! $first ) {
					echo ',';
				}
				$first = false;
				echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		} while ( count( (array) $rows ) === 250 );
	}

	public static function portable_rule_for_export( $row ) {
		$out = $row;
		unset( $out['id'] );
		$out['metadata'] = self::decode_json( $out['metadata'] ?? null );
		$out['active']   = empty( $out['active'] ) ? 0 : 1;
		$out['weight']   = absint( $out['weight'] ?? 0 );
		$out['priority'] = absint( $out['priority'] ?? 0 );
		$out['confidence'] = null === $out['confidence'] ? null : (float) $out['confidence'];
		return $out;
	}

	public static function l9_for_export( $row ) {
		$out = $row;
		unset( $out['id'] );
		$out['metadata'] = self::decode_json( $out['metadata'] ?? null );
		$out['product_id'] = absint( $out['product_id'] ?? 0 );
		$out['vocabulary_id'] = absint( $out['vocabulary_id'] ?? 0 ) ?: null;
		$out['weight'] = absint( $out['weight'] ?? 0 );
		$out['active'] = empty( $out['active'] ) ? 0 : 1;
		return $out;
	}

	public static function legacy_lesson_for_export( $row ) {
		$out = $row;
		unset( $out['id'] );
		$out['metadata'] = self::decode_json( $out['metadata'] ?? null );
		foreach ( array( 'lesson_order', 'module_size', 'module_count', 'item_count', 'completed_items', 'snapshot_before', 'snapshot_after' ) as $key ) {
			$out[ $key ] = absint( $out[ $key ] ?? 0 );
		}
		return $out;
	}

	public static function analyze_upload() {
		if ( ! self::can_manage() ) {
			wp_die( 'Permisos insuficientes.' );
		}
		check_admin_referer( 'tcd_knowledge_analyze' );
		$document = self::read_uploaded_document();
		if ( is_wp_error( $document ) ) {
			self::save_report( array( 'error' => $document->get_error_message() ) );
			self::redirect();
		}
		$report = self::analyze_document( $document );
		self::save_report( $report );
		self::redirect();
	}

	public static function import_upload() {
		if ( ! self::can_manage() ) {
			wp_die( 'Permisos insuficientes.' );
		}
		check_admin_referer( 'tcd_knowledge_import' );
		if ( empty( $_POST['confirm_merge'] ) ) {
			self::save_report( array( 'error' => 'Debes confirmar explícitamente la fusión.' ) );
			self::redirect();
		}
		$state = TCD_Academy::state();
		if ( 'running' === (string) ( $state['status'] ?? '' ) ) {
			self::save_report( array( 'error' => 'Dependiente está aprendiendo. Detén el proceso antes de importar conocimiento.' ) );
			self::redirect();
		}

		$document = self::read_uploaded_document();
		if ( is_wp_error( $document ) ) {
			self::save_report( array( 'error' => $document->get_error_message() ) );
			self::redirect();
		}
		$analysis = self::analyze_document( $document );
		if ( ! empty( $analysis['error'] ) ) {
			self::save_report( $analysis );
			self::redirect();
		}

		self::install();
		$result = 'legacy' === (string) ( $analysis['kind'] ?? '' )
			? self::merge_legacy( $document )
			: self::merge_native( $document );
		if ( is_wp_error( $result ) ) {
			$analysis['error'] = $result->get_error_message();
			self::save_report( $analysis );
			self::redirect();
		}

		$analysis['import'] = $result;
		$analysis['success'] = true;
		self::save_report( $analysis );
		update_option(
			self::LAST_IMPORT,
			array(
				'imported_at'      => current_time( 'mysql' ),
				'source_schema'    => (string) ( $analysis['schema'] ?? '' ),
				'source_brain_id'  => sanitize_text_field( (string) ( $analysis['source_brain_id'] ?? '' ) ),
				'checkpoint_id'    => sanitize_text_field( (string) ( $analysis['checkpoint_id'] ?? '' ) ),
				'result'           => $result,
			),
			false
		);
		self::redirect();
	}

	private static function read_uploaded_document() {
		if ( empty( $_FILES['knowledge_file'] ) || ! is_array( $_FILES['knowledge_file'] ) ) {
			return new WP_Error( 'tcd_knowledge_file_missing', 'No se ha recibido ningún archivo.' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Native upload structure is validated before reading.
		$file = $_FILES['knowledge_file'];
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		$size  = isset( $file['size'] ) ? absint( $file['size'] ) : 0;
		$tmp   = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( UPLOAD_ERR_OK !== $error || ! $size || $size > self::MAX_BYTES || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'tcd_knowledge_file_invalid', 'El archivo no es válido o supera 128 MB.' );
		}

		$raw = file_get_contents( $tmp );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return new WP_Error( 'tcd_knowledge_file_read', 'No se ha podido leer el archivo.' );
		}
		if ( 0 === strncmp( $raw, "\x1f\x8b", 2 ) ) {
			if ( ! function_exists( 'gzdecode' ) ) {
				return new WP_Error( 'tcd_knowledge_gzip', 'El servidor no puede descomprimir este archivo gzip.' );
			}
			$decoded = gzdecode( $raw );
			if ( ! is_string( $decoded ) || '' === $decoded ) {
				return new WP_Error( 'tcd_knowledge_gzip_invalid', 'El gzip no contiene JSON válido.' );
			}
			$raw = $decoded;
		}
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new WP_Error( 'tcd_knowledge_uncompressed_too_large', 'El JSON descomprimido supera 128 MB.' );
		}
		$document = json_decode( $raw, true );
		if ( ! is_array( $document ) ) {
			return new WP_Error( 'tcd_knowledge_json', 'El archivo no contiene un documento JSON válido.' );
		}
		return $document;
	}

	private static function analyze_document( $document ) {
		$schema  = isset( $document['schema'] ) && is_array( $document['schema'] ) ? $document['schema'] : array();
		$name    = (string) ( $schema['name'] ?? '' );
		$version = absint( $schema['version'] ?? 0 );
		$source  = isset( $document['source'] ) && is_array( $document['source'] ) ? $document['source'] : array();

		if ( self::LEGACY_SCHEMA === $name && self::LEGACY_VERSION === $version ) {
			$rules   = isset( $document['semantic_rules'] ) && is_array( $document['semantic_rules'] ) ? $document['semantic_rules'] : array();
			$academy = isset( $document['academy'] ) && is_array( $document['academy'] ) ? $document['academy'] : array();
			$lessons = isset( $academy['completed_lessons'] ) && is_array( $academy['completed_lessons'] ) ? $academy['completed_lessons'] : array();
			$l9      = isset( $academy['lesson9_memory'] ) && is_array( $academy['lesson9_memory'] ) ? $academy['lesson9_memory'] : array();
			return array(
				'kind'             => 'legacy',
				'schema'           => $name . ' v' . $version,
				'source_brain_id'  => sanitize_text_field( (string) ( $source['brain_id'] ?? '' ) ),
				'checkpoint_id'    => sanitize_text_field( (string) ( $source['checkpoint_id'] ?? '' ) ),
				'counts'           => array(
					'semantic_rules' => count( $rules ),
					'completed_lessons' => count( $lessons ),
					'lesson9_memory' => count( $l9 ),
					'modern_knowledge' => 0,
				),
				'compatibility'    => array(
					'semantic_rules' => 'direct',
					'completed_lessons' => 'historical_only',
					'lesson9_memory' => 'direct',
					'modern_knowledge' => 'not_present',
				),
				'warning'          => 'Las lecciones antiguas se conservarán como histórico; no se falsificará el estado L1-L10 moderno.',
			);
		}

		if ( self::SCHEMA_NAME === $name && self::SCHEMA_VERSION === $version ) {
			return array(
				'kind'             => 'native',
				'schema'           => $name . ' v' . $version,
				'source_brain_id'  => sanitize_text_field( (string) ( $source['brain_id'] ?? '' ) ),
				'checkpoint_id'    => sanitize_text_field( (string) ( $source['checkpoint_id'] ?? '' ) ),
				'counts'           => array(
					'semantic_rules' => count( (array) ( $document['portable_rules'] ?? array() ) ),
					'completed_lessons' => count( (array) ( $document['legacy_completed_lessons'] ?? array() ) ),
					'lesson9_memory' => count( (array) ( $document['lesson9_memory'] ?? array() ) ),
					'modern_knowledge' => count( (array) ( $document['modern_knowledge'] ?? array() ) ),
				),
				'compatibility'    => array(
					'semantic_rules' => 'direct',
					'completed_lessons' => 'historical_only',
					'lesson9_memory' => 'direct',
					'modern_knowledge' => 'direct',
				),
			);
		}

		if ( 'taxocommerce_dependiente_learning' === $name ) {
			return array(
				'error'  => 'Este archivo es una auditoría de aprendizaje/snapshot, no una copia portable de conocimiento. Exporta desde “Conocimiento portable”.',
				'schema' => $name . ' v' . $version,
			);
		}

		return array( 'error' => 'El archivo no corresponde a un formato de conocimiento reconocido por Dependiente.' );
	}

	private static function merge_legacy( $document ) {
		global $wpdb;
		$rules   = isset( $document['semantic_rules'] ) && is_array( $document['semantic_rules'] ) ? $document['semantic_rules'] : array();
		$academy = isset( $document['academy'] ) && is_array( $document['academy'] ) ? $document['academy'] : array();
		$l9      = isset( $academy['lesson9_memory'] ) && is_array( $academy['lesson9_memory'] ) ? $academy['lesson9_memory'] : array();
		$lessons = isset( $academy['completed_lessons'] ) && is_array( $academy['completed_lessons'] ) ? $academy['completed_lessons'] : array();
		$source  = isset( $document['source'] ) && is_array( $document['source'] ) ? $document['source'] : array();

		$brain      = sanitize_text_field( (string) ( $source['brain_id'] ?? 'legacy-unknown' ) );
		$checkpoint = sanitize_text_field( (string) ( $source['checkpoint_id'] ?? '' ) );

		$started = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'No se pudo iniciar la transacción.' );
			}
			$started = true;
			$rule_result   = self::merge_rules( $rules, $brain );
			$l9_result     = self::merge_l9( $l9, $brain );
			$lesson_result = self::merge_legacy_lessons( $lessons, $brain, $checkpoint );
			foreach ( array( $rule_result, $l9_result, $lesson_result ) as $part ) {
				if ( is_wp_error( $part ) ) {
					throw new RuntimeException( $part->get_error_message() );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'No se pudo confirmar la transacción.' );
			}
			$started = false;
			return array(
				'modern_knowledge' => array( 'inserted' => 0, 'identical' => 0, 'conflicts' => 0, 'ignored' => 0 ),
				'portable_rules'   => $rule_result,
				'lesson9_memory'   => $l9_result,
				'legacy_lessons'   => $lesson_result,
			);
		} catch ( Throwable $error ) {
			if ( $started ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return new WP_Error( 'tcd_legacy_import_failed', $error->getMessage() );
		}
	}

	private static function merge_native( $document ) {
		global $wpdb;
		$source = isset( $document['source'] ) && is_array( $document['source'] ) ? $document['source'] : array();
		$brain  = sanitize_text_field( (string) ( $source['brain_id'] ?? 'native-unknown' ) );
		$checkpoint = sanitize_text_field( (string) ( $source['checkpoint_id'] ?? '' ) );
		$started = false;
		try {
			if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
				throw new RuntimeException( 'No se pudo iniciar la transacción.' );
			}
			$started = true;
			$knowledge = self::merge_modern_knowledge( (array) ( $document['modern_knowledge'] ?? array() ) );
			$rules     = self::merge_rules( (array) ( $document['portable_rules'] ?? array() ), $brain );
			$l9        = self::merge_l9( (array) ( $document['lesson9_memory'] ?? array() ), $brain );
			$lessons   = self::merge_legacy_lessons( (array) ( $document['legacy_completed_lessons'] ?? array() ), $brain, $checkpoint );
			foreach ( array( $knowledge, $rules, $l9, $lessons ) as $part ) {
				if ( is_wp_error( $part ) ) {
					throw new RuntimeException( $part->get_error_message() );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'No se pudo confirmar la transacción.' );
			}
			$started = false;
			return array(
				'modern_knowledge' => $knowledge,
				'portable_rules'   => $rules,
				'lesson9_memory'   => $l9,
				'legacy_lessons'   => $lessons,
			);
		} catch ( Throwable $error ) {
			if ( $started ) {
				$wpdb->query( 'ROLLBACK' );
			}
			return new WP_Error( 'tcd_native_import_failed', $error->getMessage() );
		}
	}

	private static function merge_modern_knowledge( $rows ) {
		global $wpdb;
		$table  = TCD_Academy::knowledge_table();
		$result = array( 'inserted' => 0, 'identical' => 0, 'conflicts' => 0, 'ignored' => 0 );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				++$result['ignored'];
				continue;
			}
			$lesson      = absint( $row['lesson'] ?? 0 );
			$source_type = sanitize_key( (string) ( $row['source_type'] ?? '' ) );
			$source_id   = absint( $row['source_id'] ?? 0 );
			$signature   = sanitize_text_field( (string) ( $row['signature'] ?? '' ) );
			if ( $lesson < 1 || $lesson > 10 || ! $source_type || ! $source_id || ! preg_match( '/^[a-f0-9]{64}$/i', $signature ) ) {
				++$result['ignored'];
				continue;
			}
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT signature FROM %i WHERE lesson=%d AND source_type=%s AND source_id=%d LIMIT 1",
					$table,
					$lesson,
					$source_type,
					$source_id
				),
				ARRAY_A
			);
			if ( $existing ) {
				if ( hash_equals( (string) $existing['signature'], $signature ) ) {
					++$result['identical'];
				} else {
					++$result['conflicts'];
				}
				continue;
			}
			$context = isset( $row['context'] ) && is_array( $row['context'] ) ? $row['context'] : array();
			$status  = sanitize_key( (string) ( $row['status'] ?? 'learned' ) );
			if ( ! in_array( $status, array( 'learned', 'unlearned' ), true ) ) {
				$status = 'learned';
			}
			$ok = $wpdb->insert(
				$table,
				array(
					'lesson'      => $lesson,
					'source_type' => $source_type,
					'source_id'   => $source_id,
					'signature'   => $signature,
					'title'       => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
					'context_json'=> wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'status'      => $status,
					'reason'      => sanitize_textarea_field( (string) ( $row['reason'] ?? '' ) ),
					'learned_at'  => self::mysql_date( $row['learned_at'] ?? '' ) ?: current_time( 'mysql' ),
				)
			);
			if ( false === $ok ) {
				return new WP_Error( 'tcd_knowledge_insert', 'No se pudo insertar conocimiento moderno.' );
			}
			++$result['inserted'];
		}
		return $result;
	}

	private static function merge_rules( $rows, $brain ) {
		global $wpdb;
		$table  = self::rules_table();
		$result = array( 'inserted' => 0, 'identical' => 0, 'conflicts' => 0, 'ignored' => 0 );
		foreach ( $rows as $row ) {
			$rule = self::normalize_rule( $row, $brain );
			if ( ! $rule ) {
				++$result['ignored'];
				continue;
			}
			$existing = $wpdb->get_row(
				$wpdb->prepare( "SELECT rule_hash FROM %i WHERE rule_key=%s LIMIT 1", $table, $rule['rule_key'] ),
				ARRAY_A
			);
			if ( $existing ) {
				if ( hash_equals( (string) $existing['rule_hash'], (string) $rule['rule_hash'] ) ) {
					++$result['identical'];
				} else {
					++$result['conflicts'];
				}
				continue;
			}
			if ( false === $wpdb->insert( $table, $rule ) ) {
				return new WP_Error( 'tcd_rule_insert', 'No se pudo importar la regla ' . $rule['rule_key'] . '.' );
			}
			++$result['inserted'];
		}
		return $result;
	}

	private static function normalize_rule( $row, $brain ) {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$key = sanitize_key( (string) ( $row['rule_key'] ?? '' ) );
		if ( ! $key ) {
			return null;
		}
		$source = sanitize_key( (string) ( $row['source'] ?? 'manual' ) ) ?: 'manual';
		if ( in_array( $source, array( 'seed', 'academy_stage', 'learned_candidate', 'learned_rejected' ), true ) ) {
			return null;
		}
		$metadata = isset( $row['metadata'] ) && is_array( $row['metadata'] ) ? $row['metadata'] : self::decode_json( $row['metadata'] ?? null );
		$normalized = array(
			'rule_key'              => $key,
			'rule_type'             => sanitize_key( (string) ( $row['rule_type'] ?? 'alias' ) ) ?: 'alias',
			'expression'            => self::nullable_text( $row['expression'] ?? null, 255 ),
			'normalized_expression' => self::nullable_text( $row['normalized_expression'] ?? null, 255 ),
			'canonical_expression'  => self::nullable_text( $row['canonical_expression'] ?? null, 255 ),
			'match_type'            => sanitize_key( (string) ( $row['match_type'] ?? 'token' ) ) ?: 'token',
			'semantic_role'         => self::nullable_key( $row['semantic_role'] ?? null ),
			'source_group'          => self::nullable_key( $row['source_group'] ?? null ),
			'source_slug'           => self::nullable_text( $row['source_slug'] ?? null, 191 ),
			'context_group'         => self::nullable_key( $row['context_group'] ?? null ),
			'context_slug'          => self::nullable_text( $row['context_slug'] ?? null, 191 ),
			'target_group'          => self::nullable_key( $row['target_group'] ?? null ),
			'target_slug'           => self::nullable_text( $row['target_slug'] ?? null, 191 ),
			'relation_type'         => self::nullable_key( $row['relation_type'] ?? null ),
			'result_role'           => self::nullable_key( $row['result_role'] ?? null ),
			'weight'                => min( 1000, max( 0, absint( $row['weight'] ?? 100 ) ) ),
			'priority'              => min( 100, max( 0, absint( $row['priority'] ?? 5 ) ) ),
			'confidence'            => ( '' === (string) ( $row['confidence'] ?? '' ) || null === ( $row['confidence'] ?? null ) ) ? null : round( min( 1, max( 0, (float) $row['confidence'] ) ), 4 ),
			'language'              => sanitize_key( (string) ( $row['language'] ?? 'es' ) ) ?: 'es',
			'source'                => $source,
			'metadata'              => empty( $metadata ) ? null : wp_json_encode( self::strip_private_metadata( $metadata ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'active'                => empty( $row['active'] ) ? 0 : 1,
			'source_brain_id'       => sanitize_text_field( $brain ),
			'created_at'            => self::mysql_date( $row['created_at'] ?? '' ),
			'updated_at'            => current_time( 'mysql' ),
		);
		$normalized['rule_hash'] = self::rule_hash( $normalized );
		return $normalized;
	}

	private static function rule_hash( $rule ) {
		$fields = array(
			'rule_type', 'expression', 'normalized_expression', 'canonical_expression',
			'match_type', 'semantic_role', 'source_group', 'source_slug', 'context_group',
			'context_slug', 'target_group', 'target_slug', 'relation_type', 'result_role',
			'weight', 'priority', 'confidence', 'language', 'active',
		);
		$data = array();
		foreach ( $fields as $field ) {
			$data[ $field ] = $rule[ $field ] ?? null;
		}
		return hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	private static function merge_l9( $rows, $brain ) {
		global $wpdb;
		$table  = self::l9_table();
		$result = array( 'inserted' => 0, 'identical' => 0, 'conflicts' => 0, 'ignored' => 0 );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				++$result['ignored'];
				continue;
			}
			$product_id = absint( $row['product_id'] ?? 0 );
			$signal     = self::normalize_phrase( $row['normalized_signal'] ?? '' );
			$lesson_key = sanitize_key( (string) ( $row['lesson_key'] ?? 'v3_l9_product_language' ) ) ?: 'v3_l9_product_language';
			if ( ! $product_id || ! $signal ) {
				++$result['ignored'];
				continue;
			}
			$hash = sanitize_text_field( (string) ( $row['source_hash'] ?? '' ) );
			if ( ! preg_match( '/^[a-f0-9]{64}$/i', $hash ) ) {
				$hash = hash( 'sha256', $lesson_key . '|' . $product_id . '|' . sanitize_key( (string) ( $row['signal_type'] ?? 'term' ) ) . '|' . $signal );
			}
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT normalized_signal,weight FROM %i WHERE lesson_key=%s AND product_id=%d AND source_hash=%s LIMIT 1",
					$table,
					$lesson_key,
					$product_id,
					$hash
				),
				ARRAY_A
			);
			if ( $existing ) {
				if ( (string) $existing['normalized_signal'] === $signal && absint( $existing['weight'] ) === min( 500, max( 1, absint( $row['weight'] ?? 50 ) ) ) ) {
					++$result['identical'];
				} else {
					++$result['conflicts'];
				}
				continue;
			}
			$metadata = isset( $row['metadata'] ) && is_array( $row['metadata'] ) ? $row['metadata'] : self::decode_json( $row['metadata'] ?? null );
			$ok = $wpdb->insert(
				$table,
				array(
					'lesson_key'        => $lesson_key,
					'product_id'        => $product_id,
					'signal_type'       => sanitize_key( (string) ( $row['signal_type'] ?? 'term' ) ) ?: 'term',
					'signal_text'       => self::shorten( (string) ( $row['signal_text'] ?? $signal ), 250 ),
					'normalized_signal' => self::shorten( $signal, 250 ),
					'semantic_group'    => self::nullable_key( $row['semantic_group'] ?? null ),
					'vocabulary_id'     => absint( $row['vocabulary_id'] ?? 0 ) ?: null,
					'weight'            => min( 500, max( 1, absint( $row['weight'] ?? 50 ) ) ),
					'source_hash'       => $hash,
					'metadata'          => empty( $metadata ) ? null : wp_json_encode( self::strip_private_metadata( $metadata ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'active'            => empty( $row['active'] ) ? 0 : 1,
					'source_brain_id'   => sanitize_text_field( $brain ),
					'updated_at'        => current_time( 'mysql' ),
				)
			);
			if ( false === $ok ) {
				return new WP_Error( 'tcd_l9_insert', 'No se pudo importar una señal L9.' );
			}
			++$result['inserted'];
		}
		return $result;
	}

	private static function merge_legacy_lessons( $rows, $brain, $checkpoint ) {
		global $wpdb;
		$table  = self::legacy_lessons_table();
		$result = array( 'inserted' => 0, 'identical' => 0, 'conflicts' => 0, 'ignored' => 0 );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				++$result['ignored'];
				continue;
			}
			$key = sanitize_key( (string) ( $row['lesson_key'] ?? '' ) );
			if ( ! $key ) {
				++$result['ignored'];
				continue;
			}
			$payload = array(
				'source_brain_id'     => sanitize_text_field( $brain ?: 'legacy-unknown' ),
				'source_checkpoint_id'=> sanitize_text_field( $checkpoint ),
				'lesson_key'          => $key,
				'lesson_order'        => absint( $row['lesson_order'] ?? 0 ),
				'title'               => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
				'status'              => sanitize_key( (string) ( $row['status'] ?? 'completed' ) ) ?: 'completed',
				'module_size'         => absint( $row['module_size'] ?? 0 ),
				'module_count'        => absint( $row['module_count'] ?? 0 ),
				'item_count'          => absint( $row['item_count'] ?? 0 ),
				'completed_items'     => absint( $row['completed_items'] ?? 0 ),
				'snapshot_before'     => absint( $row['snapshot_before'] ?? 0 ),
				'snapshot_after'      => absint( $row['snapshot_after'] ?? 0 ),
				'metadata'            => wp_json_encode( isset( $row['metadata'] ) && is_array( $row['metadata'] ) ? self::strip_private_metadata( $row['metadata'] ) : self::decode_json( $row['metadata'] ?? null ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'completed_at'        => self::mysql_date( $row['completed_at'] ?? '' ),
				'imported_at'         => current_time( 'mysql' ),
			);
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE source_brain_id=%s AND lesson_key=%s LIMIT 1",
					$table,
					$payload['source_brain_id'],
					$key
				),
				ARRAY_A
			);
			if ( $existing ) {
				$same = absint( $existing['snapshot_after'] ?? 0 ) === $payload['snapshot_after']
					&& absint( $existing['completed_items'] ?? 0 ) === $payload['completed_items']
					&& (string) ( $existing['status'] ?? '' ) === $payload['status'];
				if ( $same ) {
					++$result['identical'];
				} else {
					++$result['conflicts'];
				}
				continue;
			}
			if ( false === $wpdb->insert( $table, $payload ) ) {
				return new WP_Error( 'tcd_lesson_insert', 'No se pudo conservar el histórico de la lección ' . $key . '.' );
			}
			++$result['inserted'];
		}
		return $result;
	}

	private static function save_report( $report ) {
		set_transient( self::REPORT_PREFIX . get_current_user_id(), (array) $report, HOUR_IN_SECONDS );
	}

	private static function redirect() {
		wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-dependiente&tab=knowledge' ) );
		exit;
	}

	private static function can_manage() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	private static function decode_json( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function strip_private_metadata( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $key => $child ) {
			$normalized = strtolower( (string) $key );
			if ( preg_match( '/(^|_)(session|session_hash|session_hashes|ip|ip_address|email|user_id|reviewed_by|log_id|search_uuid|search_id)($|_)/', $normalized ) ) {
				continue;
			}
			$out[ $key ] = is_array( $child ) ? self::strip_private_metadata( $child ) : $child;
		}
		return $out;
	}

	private static function nullable_key( $value ) {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}
		$value = sanitize_key( (string) $value );
		return '' === $value ? null : $value;
	}

	private static function nullable_text( $value, $length ) {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}
		return self::shorten( sanitize_text_field( (string) $value ), $length );
	}

	private static function shorten( $value, $length ) {
		$value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, absint( $length ), 'UTF-8' ) : substr( $value, 0, absint( $length ) );
	}

	private static function normalize_phrase( $value ) {
		$value = trim( wp_strip_all_tags( (string) $value ) );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = remove_accents( $value );
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value );
		return trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
	}

	private static function mysql_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$time = strtotime( $value );
		return false === $time ? null : gmdate( 'Y-m-d H:i:s', $time );
	}
}
