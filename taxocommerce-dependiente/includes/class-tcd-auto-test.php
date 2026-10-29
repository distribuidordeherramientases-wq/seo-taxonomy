<?php
/**
 * On-demand read-only functional health checks for TaxoCommerce Dependiente.
 *
 * Neither a health check nor its rendering may train models, run a worker,
 * submit search feedback, modify catalogue data or restore backups. Only a
 * per-user transient of diagnostic outcomes is persisted.
 *
 * @package TaxoCommerceDependiente
 */
defined( 'ABSPATH' ) || exit;

final class TCD_Auto_Test {
	const ACTION = 'tcd_run_auto_test';
	const NONCE = 'tcd_auto_test_run';
	const REPORT_PREFIX = 'tcd_auto_test_';

	public static function boot() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_run' ) );
	}

	private static function allowed() {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	public static function groups() {
		return array(
			'startup' => 'Arranque y seguridad',
			'storage' => 'Tablas y esquema',
			'sources' => 'Fuentes L1–L10',
			'learning' => 'Aprendizaje y estado',
			'quality' => 'Calidad L1–L10',
			'interpreter' => 'Intérprete / Lingüista M11',
			'search' => 'Búsqueda y feedback',
			'maintenance' => 'M12 y procesos',
			'transfer' => 'Backup y conocimiento',
			'write' => 'Pruebas con escritura',
		);
	}

	public static function cases() {
		$cases = array(
			'runtime' => array( 'startup', 'Servicios cargados y WooCommerce' ),
			'hooks' => array( 'startup', 'Acciones de administración y workers' ),
			'admin' => array( 'startup', 'Acceso y navegación de Dependiente' ),
			'schema_academy' => array( 'storage', 'Tablas de aprendizaje, fotos fijas y elementos' ),
			'schema_quality' => array( 'storage', 'Tabla de exámenes y resultados' ),
			'schema_transfer' => array( 'storage', 'Tablas de conocimiento portable y memoria L9' ),
			'schema_feedback' => array( 'storage', 'Tablas de feedback y evidencias' ),
			'schema_lexicon' => array( 'storage', 'Tablas del Intérprete y sus evidencias' ),
			'learning_state' => array( 'learning', 'Estado, cursores y posibles errores' ),
			'snapshot' => array( 'learning', 'Integridad de una foto fija del curso' ),
			'progress' => array( 'learning', 'Contadores de progreso' ),
			'log' => array( 'learning', 'Log de aprendizaje limitado a 1 MiB' ),
			'load' => array( 'learning', 'Regulador de carga y prioridades 1–5' ),
			'linguist_curriculum' => array( 'interpreter', 'Lecciones y gramática del Lingüista' ),
			'linguist_state' => array( 'interpreter', 'Curso de Lingüista y estado M11' ),
			'lexicon_read' => array( 'interpreter', 'Lectura de memoria y evidencia lingüística' ),
			'linguistic_interpret' => array( 'interpreter', 'Interpretación real de frase española' ),
			'linguist_contract' => array( 'interpreter', 'El Intérprete no busca SKU ni pregunta' ),
			'l9_bridge' => array( 'interpreter', 'Puente lingüístico hacia L9' ),
			'rest_routes' => array( 'search', 'Endpoints REST de búsqueda y feedback' ),
			'shortcodes' => array( 'search', 'Shortcode moderno y compatibilidad' ),
			'product_search' => array( 'search', 'Búsqueda real de producto publicado (muestra)' ),
			'feedback_read' => array( 'search', 'Recuperación de feedback validado' ),
			'feedback_privacy' => array( 'search', 'Hash de sesión y regla antirruido' ),
			'maintenance_hooks' => array( 'maintenance', 'Hooks de cambios de productos y contenido' ),
			'maintenance_state' => array( 'maintenance', 'Estado y errores de mantenimiento' ),
			'cron' => array( 'maintenance', 'Registro y planificación de trabajos' ),
			'backup_manifest' => array( 'transfer', 'Cobertura del backup por tablas y opciones' ),
			'transfer_manifest' => array( 'transfer', 'Contrato de exportación/importación portable' ),
			'backup_endpoints' => array( 'transfer', 'Endpoints protegidos de backup y exportación' ),
			'write_uat' => array( 'write', 'Reentrenamiento, clics, importación y restauración reales' ),
		);
		for ( $lesson = 1; $lesson <= 10; ++$lesson ) {
			$cases[ 'source_l' . $lesson ] = array( 'sources', 'L' . $lesson . ' · Fuente y lectura de muestra' );
			$cases[ 'quality_l' . $lesson ] = array( 'quality', 'L' . $lesson . ' · Examen, umbral y resultado' );
		}
		return $cases;
	}

	public static function handle_run() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'No tienes permisos para ejecutar Auto Test.', 'taxocommerce-dependiente' ) );
		}
		check_admin_referer( self::NONCE, 'tcd_auto_test_nonce' );
		$requested = isset( $_POST['test_group'] ) && is_scalar( $_POST['test_group'] )
			? sanitize_key( wp_unslash( $_POST['test_group'] ) ) : 'all';
		$group = 'all' === $requested || isset( self::groups()[ $requested ] ) ? $requested : 'all';
		set_transient( self::REPORT_PREFIX . get_current_user_id(), self::run_suite( $group ), DAY_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=taxocommerce-dependiente&tab=auto-test' ) );
		exit;
	}

	private static function result( $status, $message ) {
		return array( 'status' => $status, 'message' => (string) $message );
	}
	private static function pass( $msg ) { return self::result( 'pass', $msg ); }
	private static function fail( $msg ) { return self::result( 'fail', $msg ); }
	private static function skip( $msg ) { return self::result( 'skip', $msg ); }

	/** Group runs explicitly display every unexecuted check as grey, never green. */
	public static function run_suite( $selected = 'all', $cases = null ) {
		$groups = self::groups();
		$selected = 'all' === $selected || isset( $groups[ $selected ] ) ? $selected : 'all';
		$cases = null === $cases ? self::cases() : $cases;
		$start = microtime( true );
		$totals = array( 'pass' => 0, 'fail' => 0, 'skip' => 0, 'not_run' => 0 );
		$rows = array();
		foreach ( $cases as $id => $labels ) {
			$group = $labels[0];
			$began = microtime( true );
			if ( 'all' !== $selected && $selected !== $group ) {
				$result = self::result( 'not_run', 'Este grupo no se ha ejecutado.' );
			} else {
				try {
					$method = array( __CLASS__, 'check_' . $id );
					if ( is_callable( $method ) ) {
						$result = call_user_func( $method );
					} elseif ( preg_match( '/^source_l([1-9]|10)$/', $id, $match ) ) {
						$result = self::check_source( (int) $match[1] );
					} elseif ( preg_match( '/^quality_l([1-9]|10)$/', $id, $match ) ) {
						$result = self::check_quality( (int) $match[1] );
					} else {
						$result = self::fail( 'Prueba no implementada: ' . $id );
					}
					if ( ! is_array( $result ) || ! isset( $result['status'], $result['message'] ) ||
						! in_array( $result['status'], array( 'pass', 'fail', 'skip' ), true ) ) {
						$result = self::fail( 'El test devolvió un resultado incorrecto.' );
					}
				} catch ( Throwable $error ) {
					$result = self::fail( get_class( $error ) . ': ' . substr( $error->getMessage(), 0, 250 ) );
				}
			}
			$result['id'] = $id;
			$result['group_id'] = $group;
			$result['group'] = $groups[ $group ] ?? $group;
			$result['label'] = $labels[1];
			$result['ms'] = (int) round( 1000 * ( microtime( true ) - $began ) );
			$rows[] = $result;
			++$totals[ $result['status'] ];
		}
		return array(
			'version' => defined( 'TAXOCOMMERCE_DEPENDIENTE_VERSION' ) ? TAXOCOMMERCE_DEPENDIENTE_VERSION : '',
			'date' => current_time( 'mysql' ),
			'group' => $selected,
			'ms' => (int) round( 1000 * ( microtime( true ) - $start ) ),
			'totals' => $totals,
			'rows' => $rows,
		);
	}

	private static function check_runtime() {
		$names = array( 'TCD_Admin', 'TCD_Academy', 'TCD_Sources', 'TCD_Intelligence',
			'TCD_Search', 'TCD_Feedback', 'TCD_Maintenance', 'TCD_Backup',
			'TCD_Knowledge_Transfer', 'TCD_Load', 'TCD_Original_Linguista',
			'TCD_Original_Interprete', 'TCD_Original_Interprete_DB' );
		$missing = array();
		foreach ( $names as $name ) {
			if ( ! class_exists( $name, false ) ) { $missing[] = $name; }
		}
		if ( $missing ) { return self::fail( 'Módulos no cargados: ' . implode( ', ', $missing ) ); }
		if ( ! class_exists( 'WooCommerce', false ) || ! post_type_exists( 'product' ) ) {
			return self::fail( 'WooCommerce no está activo o no registra productos.' );
		}
		return self::pass( count( $names ) . ' componentes y WooCommerce cargados.' );
	}

	private static function check_hooks() {
		$required = array(
			'admin_menu', 'admin_post_tcd_academy_control',
			'admin_post_tcd_knowledge_export', 'admin_post_tcd_backup_export',
			'admin_post_tcd_backup_restore', 'admin_post_tcd_learning_log_download',
			'admin_post_' . self::ACTION, TCD_Academy::CRON_HOOK,
		);
		$missing = array();
		foreach ( $required as $hook ) {
			if ( ! has_action( $hook ) ) { $missing[] = $hook; }
		}
		return $missing ? self::fail( 'Hooks ausentes: ' . implode( ', ', $missing ) )
			: self::pass( count( $required ) . ' puntos de entrada registrados; no se invocan operaciones de escritura.' );
	}

	private static function check_admin() {
		if ( ! self::allowed() ) { return self::fail( 'El usuario no tiene permisos de gestión.' ); }
		$hooks = array( 'admin_post_' . self::ACTION, 'admin_menu' );
		foreach ( $hooks as $hook ) { if ( ! has_action( $hook ) ) { return self::fail( 'Falta ' . $hook ); } }
		return self::pass( 'Acceso autorizado y panel/Auto Test registrados; petición protegida con nonce.' );
	}

	private static function schema( $tables ) {
		global $wpdb;
		$missing = array();
		$wrong = array();
		foreach ( $tables as $table => $required_columns ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( (string) $exists !== (string) $table ) {
				$missing[] = $table;
				continue;
			}
			$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
			if ( ! is_array( $columns ) || $wpdb->last_error ) {
				$wrong[] = $table . ' (no se pueden leer columnas)';
				continue;
			}
			$diff = array_diff( $required_columns, array_map( 'strval', $columns ) );
			if ( $diff ) { $wrong[] = $table . ' (' . implode( ', ', $diff ) . ')'; }
		}
		if ( $missing || $wrong ) {
			return self::fail( 'Faltan tablas: ' . implode( ', ', $missing ) . '; faltan columnas: ' . implode( '; ', $wrong ) . '. No se han reparado ni creado tablas.' );
		}
		return self::pass( count( $tables ) . ' tablas y columnas imprescindibles comprobadas en lectura.' );
	}

	private static function check_schema_academy() {
		return self::schema( array(
			TCD_Academy::knowledge_table() => array( 'id', 'lesson', 'source_type', 'source_id', 'status', 'context_json' ),
			TCD_Academy::snapshots_table() => array( 'id', 'run_id', 'lesson', 'source_count', 'learned_count', 'unlearned_count', 'status' ),
			TCD_Academy::items_table() => array( 'id', 'snapshot_id', 'source_id', 'payload_json', 'status' ),
		) );
	}
	private static function check_schema_quality() {
		return self::schema( array( TCD_Intelligence::quality_table() => array( 'id', 'lesson', 'snapshot_id', 'pass_any_ratio', 'threshold', 'status', 'evaluated' ) ) );
	}
	private static function check_schema_transfer() {
		return self::schema( array(
			TCD_Knowledge_Transfer::rules_table() => array( 'id', 'rule_key', 'active', 'expression', 'rule_type' ),
			TCD_Knowledge_Transfer::l9_table() => array( 'id', 'lesson_key', 'product_id', 'signal_text', 'active' ),
			TCD_Knowledge_Transfer::legacy_lessons_table() => array( 'id', 'source_brain_id', 'lesson_key', 'status' ),
		) );
	}
	private static function check_schema_feedback() {
		return self::schema( array(
			TCD_Feedback::events_table() => array( 'id', 'session_hash', 'query_hash', 'event_type' ),
			TCD_Feedback::learning_table() => array( 'id', 'target_type', 'query_hash', 'status', 'confidence' ),
		) );
	}
	private static function check_schema_lexicon() {
		return self::schema( array(
			TCD_Original_Interprete_DB::table() => array( 'id', 'expression', 'active' ),
			TCD_Original_Interprete_DB::evidence_table() => array( 'id', 'lexicon_id' ),
		) );
	}

	private static function check_source( $lesson ) {
		$syllabus = TCD_Sources::syllabus();
		if ( ! isset( $syllabus[ $lesson ]['key'], $syllabus[ $lesson ]['name'] ) ) {
			return self::fail( 'L' . $lesson . ': no existe una definición completa.' );
		}
		if ( 6 === $lesson ) {
			$faq = TCD_Sources::faq_diagnostics();
			if ( ! is_array( $faq ) ) { return self::fail( 'L6: diagnóstico FAQ inválido.' ); }
			if ( in_array( $faq['status'] ?? '', array( 'schema_error', 'schema_incompatible', 'count_error' ), true ) ) {
				return self::fail( 'L6: ' . (string) ( $faq['message'] ?? 'Esquema FAQ incorrecto' ) );
			}
			if ( in_array( $faq['status'] ?? '', array( 'missing_table', 'empty_table', 'no_active_rows' ), true ) ) {
				return self::skip( 'L6: ' . (string) $faq['message'] );
			}
		}
		$total = TCD_Sources::source_total( $lesson );
		if ( ! is_numeric( $total ) || (int) $total < 0 ) {
			return self::fail( 'L' . $lesson . ': recuento de fuentes inválido.' );
		}
		if ( 0 === (int) $total ) {
			return self::skip( 'L' . $lesson . ': todavía no hay material de origen que probar.' );
		}
		$batch = TCD_Sources::batch( $lesson, 0, 1 );
		if ( is_wp_error( $batch ) ) {
			return self::fail( 'L' . $lesson . ': lectura fallida: ' . $batch->get_error_message() );
		}
		if ( ! is_array( $batch ) || ! isset( $batch['items'], $batch['cursor'], $batch['more'] ) ||
			! is_array( $batch['items'] ) || count( $batch['items'] ) > 1 ) {
			return self::fail( 'L' . $lesson . ': lote de lectura inválido o sin límites.' );
		}
		if ( ! $batch['items'] ) {
			return self::fail( 'L' . $lesson . ': existen fuentes, pero el primer elemento no se ha podido interpretar.' );
		}
		return self::pass( 'L' . $lesson . ': ' . absint( $total ) . ' fuentes; lectura real de 1 registro correcta (muestra, no formación).' );
	}

	private static function check_learning_state() {
		$state = TCD_Academy::state();
		if ( ! is_array( $state ) || ! isset( $state['status'], $state['lesson'], $state['phase'] ) ) {
			return self::fail( 'Estado de aprendizaje ilegible.' );
		}
		// A saved training outcome (including a failed quality gate) is not proof
		// that the Academy API or its worker has regressed. Hooks and sources
		// are checked independently by the other functional tests.
		$status = (string) $state['status'];
		if ( ! in_array( $status, array( 'idle', 'stopped', 'paused', 'running', 'completed', 'error' ), true ) ) {
			return self::fail( 'Estado de Academia desconocido: ' . $status );
		}
		$lesson = absint( $state['lesson'] );
		if ( $lesson < 1 || $lesson > 12 ) {
			return self::fail( 'Módulo fuera de rango: ' . $lesson );
		}
		return self::pass( 'Estado ' . $status . ', módulo ' . $lesson . ', fase ' . (string) $state['phase'] .
			'. Lectura correcta; el resultado pedagógico y posibles errores del curso no se evalúan aquí.' );
	}

	private static function check_snapshot() {
		$state = TCD_Academy::state();
		if ( empty( $state['run_id'] ) ) { return self::skip( 'No hay curso iniciado y, por tanto, no existe foto fija actual.' ); }
		$lesson = min( 10, max( 1, absint( $state['lesson'] ?? 1 ) ) );
		$row = TCD_Academy::snapshot_for_lesson( $lesson );
		if ( ! $row ) { return self::skip( 'La foto fija de L' . $lesson . ' aún no ha sido creada.' ); }
		$total = absint( $row['source_count'] ?? 0 );
		$done = absint( $row['learned_count'] ?? 0 ) + absint( $row['unlearned_count'] ?? 0 );
		if ( $total < $done || ( 'completed' === (string) ( $row['status'] ?? '' ) && $total !== $done ) ) {
			return self::fail( 'L' . $lesson . ': contadores de foto fija incoherentes (' . $done . '/' . $total . ').' );
		}
		return self::pass( 'L' . $lesson . ': foto fija #' . absint( $row['id'] ?? 0 ) . ' íntegra a nivel de contadores (' . $done . '/' . $total . ').' );
	}

	private static function check_progress() {
		$state = TCD_Academy::state();
		$cursor = absint( $state['snapshot_cursor'] ?? 0 );
		$learned = absint( $state['learned'] ?? 0 );
		$unlearned = absint( $state['unlearned'] ?? 0 );
		if ( ! isset( $state['status'] ) || $cursor < 0 || $learned < 0 || $unlearned < 0 ) {
			return self::fail( 'Cursores o contadores no legibles.' );
		}
		return self::pass( 'Cursores legibles: fuentes ' . $cursor . '; aprendidos ' . $learned . '; no aprendidos ' . $unlearned . '. No verifica avance futuro.' );
	}

	private static function check_log() {
		$size = TCD_Learning_Log::size();
		if ( ! is_numeric( $size ) || $size < 0 || $size > TCD_Learning_Log::MAX_BYTES ) {
			return self::fail( 'Log supera 1 MiB o no puede medirse: ' . (string) $size );
		}
		return self::pass( 'Log de ' . absint( $size ) . ' bytes; lectura de tamaño correcta sin crear ficheros.' );
	}

	private static function check_load() {
		$priority = TCD_Load::priority();
		if ( $priority < 1 || $priority > 5 ) { return self::fail( 'Prioridad fuera del rango 1–5.' ); }
		for ( $p = 1; $p <= 5; ++$p ) {
			if ( TCD_Load::initial_batch( $p ) > TCD_Load::max_batch( $p ) ||
				TCD_Load::initial_batch( $p ) < 1 || TCD_Load::delay( $p ) < 1 ) {
				return self::fail( 'Regulación incoherente en velocidad ' . $p );
			}
		}
		return self::pass( 'Prioridad ' . $priority . '; cinco velocidades coherentes. No modifica lotes ni CPU.' );
	}

	private static function check_quality( $lesson ) {
		$row = TCD_Intelligence::quality_for_lesson( $lesson );
		if ( ! $row ) { return self::skip( 'L' . $lesson . ': todavía no se ha realizado el examen.' ); }
		$total = absint( $row['evaluated'] ?? 0 );
		$top1 = absint( $row['pass_top1'] ?? 0 );
		$top3 = absint( $row['pass_top3'] ?? 0 );
		$top8 = absint( $row['pass_top8'] ?? 0 );
		$failed = absint( $row['failed'] ?? 0 );
		$ratio = (float) ( $row['pass_any_ratio'] ?? -1 );
		$threshold = (float) ( $row['threshold'] ?? -1 );
		$status = (string) ( $row['status'] ?? '' );
		if ( $top1 > $top3 || $top3 > $top8 || $top8 > $total ||
			$failed > $total || $ratio < 0 || $ratio > 1 || $threshold < 0 || $threshold > 1 ) {
			return self::fail( 'L' . $lesson . ': métricas Top1/3/8 o umbral incoherentes.' );
		}
		if ( 'passed' === $status && $ratio < $threshold ) {
			return self::fail( 'L' . $lesson . ': inconsistencia de datos: examen aprobado con métrica inferior al umbral.' );
		}
		if ( ! in_array( $status, array( 'passed', 'failed' ), true ) ) {
			return self::skip( 'L' . $lesson . ': resultado del examen todavía no definitivo (' . $status . ').' );
		}
		// An unsuccessful examination is an educational outcome, not a broken
		// feature. PASS means the report was readable and consistent.
		return self::pass( 'L' . $lesson . ': informe accesible y coherente. Resultado de la lección: ' .
			( 'passed' === $status ? 'aprobada' : 'no aprobada' ) . ' (' .
			round( $ratio * 100, 1 ) . '%; umbral ' . round( $threshold * 100, 1 ) .
			'%; ' . $failed . ' casos fallidos). El Auto Test no califica la formación.' );
	}

	private static function check_linguist_curriculum() {
		$lessons = TCD_Original_Linguista::lessons();
		$grammar = TCD_Original_Linguista::grammar();
		if ( ! is_array( $lessons ) || count( $lessons ) < 8 ||
			! is_array( $grammar ) || ! $grammar ) {
			return self::fail( 'Faltan fases o datos gramaticales del Lingüista original.' );
		}
		return self::pass( count( $lessons ) . ' fases lingüísticas y diccionario gramatical accesibles.' );
	}

	private static function check_linguist_state() {
		$state = TCD_Original_Linguista::state();
		$course = TCD_Original_Linguista::course_progress();
		if ( ! is_array( $state ) || ! isset( $state['status'] ) || ! is_numeric( $course ) ||
			$course < 0 || $course > 100 ) {
			return self::fail( 'Estado o progreso de M11 incorrectos.' );
		}
		if ( ! in_array( (string) $state['status'], array( 'stopped', 'paused', 'running', 'completed', 'error' ), true ) ) {
			return self::fail( 'Estado M11 desconocido: ' . (string) $state['status'] );
		}
		return self::pass( 'Estado M11 legible: ' . (string) $state['status'] . '; curso al ' .
			round( (float) $course, 1 ) . '%. Los resultados del curso se diagnostican por separado: no condicionan la salud del intérprete.' );
	}

	private static function check_lexicon_read() {
		$stats = TCD_Original_Interprete_DB::stats();
		if ( ! is_array( $stats ) || empty( $stats['ready'] ) || empty( $stats['evidence_ready'] ) ) {
			return self::fail( 'No se pueden leer las tablas de memoria y evidencia del Intérprete.' );
		}
		$active = absint( $stats['active'] ?? 0 );
		return $active
			? self::pass( $active . ' expresiones activas en memoria lingüística.' )
			: self::skip( 'Tablas correctas, pero todavía no hay expresiones lingüísticas activas.' );
	}

	private static function check_linguistic_interpret() {
		$result = TCD_Original_Interprete::interpret( 'necesito un taladro para pared' );
		if ( ! is_array( $result ) || empty( $result['dependiente_query'] ) ||
			! isset( $result['structured'], $result['language'] ) ||
			! is_array( $result['structured'] ) || ! is_array( $result['language'] ) ) {
			return self::fail( 'El Intérprete original no devuelve una consulta y estructura lingüística válidas.' );
		}
		return self::pass( 'Interpretación ejecutada; búsqueda normalizada: ' . substr( (string) $result['dependiente_query'], 0, 90 ) . '.' );
	}

	private static function check_linguist_contract() {
		$plan = TCD_Original_Interprete::plan_clarification();
		if ( ! is_array( $plan ) || ! isset( $plan['should_ask'] ) || $plan['should_ask'] ) {
			return self::fail( 'El Intérprete intenta interrogar al cliente en vez de traducir su lenguaje.' );
		}
		return self::pass( 'El Intérprete entrega lenguaje al motor de búsqueda; no dispara preguntas ni elige SKU.' );
	}

	private static function check_l9_bridge() {
		$result = TCD_Original_L9_Interpreter::interpret( 'quiero un taladro de batería' );
		return is_array( $result ) && isset( $result['groups'], $result['concepts'] ) &&
			is_array( $result['groups'] ) && is_array( $result['concepts'] )
			? self::pass( 'Puente L9 devuelve conceptos y grupos estructurados sin entrenar.' )
			: self::fail( 'El puente lingüístico L9 no devuelve grupos/conceptos.' );
	}

	private static function check_rest_routes() {
		$server = rest_get_server();
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) ) {
			return self::fail( 'Servidor REST no disponible.' );
		}
		$routes = $server->get_routes();
		$missing = array();
		foreach ( array( '/taxocommerce-dependiente/v1/search', '/taxocommerce-dependiente/v1/feedback' ) as $route ) {
			if ( empty( $routes[ $route ] ) ) { $missing[] = $route; }
		}
		return $missing ? self::fail( 'Rutas REST ausentes: ' . implode( ', ', $missing ) )
			: self::pass( 'Rutas públicas de búsqueda y feedback registradas; no se envían peticiones ni clics.' );
	}

	private static function check_shortcodes() {
		return shortcode_exists( 'taxocommerce_dependiente_moderno' )
			? self::pass( '[taxocommerce_dependiente_moderno] disponible; el alias antiguo se respeta cuando corresponde.' )
			: self::fail( 'No se ha registrado el shortcode moderno.' );
	}

	private static function check_product_search() {
		if ( ! post_type_exists( 'product' ) ) { return self::skip( 'No existe catálogo WooCommerce.' ); }
		$ids = get_posts( array(
			'post_type' => 'product', 'post_status' => 'publish',
			'fields' => 'ids', 'posts_per_page' => 4, 'orderby' => 'ID', 'order' => 'DESC',
			'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false,
		) );
		if ( ! $ids ) { return self::skip( 'No hay productos publicados para la búsqueda funcional.' ); }
		$product_id = absint( $ids[0] );
		$title = trim( (string) get_the_title( $product_id ) );
		if ( '' === $title ) { return self::skip( 'El producto de muestra no tiene título.' ); }
		$hits = TCD_Intelligence::search_products( substr( $title, 0, 150 ), 8 );
		if ( ! is_array( $hits ) ) { return self::fail( 'La búsqueda no devuelve una lista de resultados.' ); }
		foreach ( $hits as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['title'] ) ||
				'publish' !== get_post_status( absint( $row['id'] ) ) ) {
				return self::fail( 'La búsqueda devuelve un candidato no publicado o mal formado.' );
			}
		}
		if ( ! $hits ) {
			$academy = TCD_Academy::state();
			if ( 'completed' === (string) ( $academy['status'] ?? '' ) ) {
				return self::fail( 'Dependiente completado pero la búsqueda no encuentra candidatos publicados para el producto #' . $product_id . '.' );
			}
			return self::skip( 'Búsqueda operativa sin excepciones, pero aún sin resultados. El aprendizaje no está completado; revisar la relevancia tras entrenar.' );
		}
		return self::pass( 'Búsqueda real del producto #' . $product_id . ' devuelve ' . count( $hits ) . ' candidatos publicados (muestra, no certifica relevancia general).' );
	}

	private static function check_feedback_read() {
		$scores = TCD_Feedback::active_product_scores( 'taladro' );
		$hints = TCD_Feedback::active_semantic_hints( 'taladro' );
		return is_array( $scores ) && is_array( $hints )
			? self::pass( 'Recuperación de señales validadas correcta (' . count( $scores ) . ' productos; ' . count( $hints ) . ' pistas).' )
			: self::fail( 'Lecturas de feedback no devuelven listas.' );
	}

	private static function check_feedback_privacy() {
		$valid = TCD_Feedback::session_hash( 'session-auto-test-123456' );
		$invalid = TCD_Feedback::session_hash( 'a' );
		$hash = TCD_Feedback::query_hash( 'taladro' );
		return 64 === strlen( $valid ) && ctype_xdigit( $valid ) && '' === $invalid &&
			64 === strlen( $hash ) && ctype_xdigit( $hash )
			? self::pass( 'Hash anónimo y descarte de sesiones insuficientes verificados, sin registrar eventos.' )
			: self::fail( 'No se aplica correctamente la anonimización básica de feedback.' );
	}

	private static function check_maintenance_hooks() {
		$hooks = array( TCD_Maintenance::CRON_HOOK, 'woocommerce_new_product', 'woocommerce_update_product',
			'save_post_product', 'transition_post_status', 'created_product_cat', 'edited_product_cat',
			'save_post_post', 'save_post_page' );
		$missing = array();
		foreach ( $hooks as $hook ) {
			if ( ! has_action( $hook ) ) { $missing[] = $hook; }
		}
		return $missing ? self::fail( 'M12 sin hooks: ' . implode( ', ', $missing ) )
			: self::pass( count( $hooks ) . ' hooks de cambios y mantenimiento registrados; no se disparan.' );
	}

	private static function check_maintenance_state() {
		$state = TCD_Maintenance::state();
		if ( ! is_array( $state ) || ! isset( $state['status'], $state['products_updated'] ) ) {
			return self::fail( 'Estado de M12 inválido.' );
		}
		if ( ! in_array( (string) $state['status'], array( 'idle', 'active', 'running', 'paused', 'stopped', 'error', 'completed' ), true ) ) {
			return self::fail( 'Estado de M12 desconocido: ' . (string) $state['status'] );
		}
		return self::pass( 'Estado M12 legible: ' . (string) $state['status'] . '; productos actualizados ' .
			absint( $state['products_updated'] ) . '. Los errores registrados se consultan en el log; los hooks y cron se prueban aparte.' );
	}

	private static function check_cron() {
		if ( ! has_action( TCD_Academy::CRON_HOOK ) ||
			! has_action( TCD_Maintenance::CRON_HOOK ) ||
			! has_action( 'tcd_original_linguista_worker' ) ) {
			return self::fail( 'Falta registrar algún worker de aprendizaje, Lingüista o mantenimiento.' );
		}
		$academy = TCD_Academy::state();
		$state = TCD_Maintenance::state();
		$scheduled = wp_next_scheduled( TCD_Maintenance::CRON_HOOK );
		if ( 'active' === (string) ( $state['status'] ?? '' ) && ! $scheduled ) {
			return self::fail( 'M12 activo, pero falta planificación WordPress Cron.' );
		}
		if ( 'running' === (string) ( $academy['status'] ?? '' ) && ! wp_next_scheduled( TCD_Academy::CRON_HOOK ) ) {
			return self::skip( 'Academia en marcha sin tick programado ahora: puede depender del supervisor; comprobar avance real.' );
		}
		return self::pass( 'Workers registrados; planificación M12 compatible con su estado. No se ejecuta ningún tick.' );
	}

	private static function check_backup_manifest() {
		$tables = TCD_Backup::tables();
		$options = TCD_Backup::options();
		$expected = array( 'learning_knowledge', 'learning_snapshots', 'learning_items',
			'quality_checks', 'portable_rules', 'l9_memory', 'legacy_lessons',
			'feedback_events', 'feedback_learning' );
		if ( ! is_array( $tables ) || ! is_array( $options ) || array_diff( $expected, array_keys( $tables ) ) ) {
			return self::fail( 'El backup no cubre los nueve conjuntos persistentes de Dependiente.' );
		}
		foreach ( $tables as $name => $row ) {
			if ( empty( $row['table'] ) || ! is_string( $row['table'] ) ) {
				return self::fail( 'Tabla de backup mal identificada: ' . $name );
			}
		}
		return self::pass( 'Backup declara ' . count( $tables ) . ' conjuntos y ' . count( $options ) . ' opciones; no se exporta ni restaura aquí.' );
	}

	private static function check_transfer_manifest() {
		$fields = TCD_Knowledge_Transfer::field_map();
		$compat = TCD_Knowledge_Transfer::compatibility_map();
		return is_array( $fields ) && ! empty( $fields ) && is_array( $compat ) && ! empty( $compat )
			? self::pass( 'Contratos de conocimiento moderno y compatibilidad antigua accesibles.' )
			: self::fail( 'Mapas de importación/exportación ausentes o inválidos.' );
	}

	private static function check_backup_endpoints() {
		$hooks = array( 'admin_post_tcd_backup_export', 'admin_post_tcd_backup_analyze',
			'admin_post_tcd_backup_restore', 'admin_post_tcd_knowledge_export',
			'admin_post_tcd_knowledge_analyze', 'admin_post_tcd_knowledge_import' );
		$missing = array();
		foreach ( $hooks as $hook ) { if ( ! has_action( $hook ) ) { $missing[] = $hook; } }
		return $missing ? self::fail( 'Endpoints no registrados: ' . implode( ', ', $missing ) )
			: self::pass( 'Endpoints de exportación, análisis y restauración registrados (sin ejecutarlos).' );
	}

	private static function check_write_uat() {
		return self::skip( 'No se ejecutan escrituras sobre catálogo, aprendizaje, feedback ni backups. La UAT completa requiere staging aislado y restauración controlada.' );
	}

	public static function render() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'No tienes permisos para abrir Auto Test.', 'taxocommerce-dependiente' ) );
		}
		$cases = self::cases();
		$report = get_transient( self::REPORT_PREFIX . get_current_user_id() );
		if ( ! is_array( $report ) || (string) ( $report['version'] ?? '' ) !== TAXOCOMMERCE_DEPENDIENTE_VERSION ||
			! isset( $report['totals'], $report['rows'] ) ) { $report = array(); }
		$rows = array();
		foreach ( (array) ( $report['rows'] ?? array() ) as $row ) {
			if ( isset( $row['id'] ) ) { $rows[ $row['id'] ] = $row; }
		}
		$totals = $report['totals'] ?? array( 'pass'=>0, 'fail'=>0, 'skip'=>0, 'not_run'=>count( $cases ) );
		?>
		<section id="tcd-auto-test">
			<h2><?php esc_html_e( 'Auto Test', 'taxocommerce-dependiente' ); ?></h2>
			<p><?php esc_html_e( 'Comprueba los principales contratos de Dependiente tras cada actualización, por grupos o en una sola pasada. No modifica el aprendizaje en curso.', 'taxocommerce-dependiente' ); ?></p>
			<style>
				#tcd-auto-test .tc-at-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:18px 0}
				#tcd-auto-test .tc-at-summary{display:flex;gap:12px;flex-wrap:wrap;margin:15px 0}
				#tcd-auto-test .tc-at-summary span{padding:12px 18px;border:1px solid #dcdcde;background:#fff;border-radius:5px}
				#tcd-auto-test .tc-at-row{display:flex;gap:12px;align-items:flex-start;padding:12px 15px;border:1px solid #dcdcde;border-bottom:0;background:#fff}
				#tcd-auto-test .tc-at-row:last-child{border-bottom:1px solid #dcdcde}
				#tcd-auto-test .tc-at-dot{display:inline-block;flex:0 0 13px;width:13px;height:13px;margin-top:4px;border-radius:50%;background:#777}
				#tcd-auto-test .tc-at-dot--pass{background:#188038}#tcd-auto-test .tc-at-dot--fail{background:#c5221f}
				#tcd-auto-test .tc-at-row strong{display:block}#tcd-auto-test .tc-at-row p{margin:4px 0;overflow-wrap:anywhere}
				#tcd-auto-test .tc-at-row--fail p{color:#b32d2e;font-weight:600}
				#tcd-auto-test .tc-at-small{font-size:12px;color:#50575e}
				#tcd-auto-test details.tc-at-group{max-width:1100px;margin-bottom:12px;border:1px solid #dcdcde;background:#fff}
				#tcd-auto-test details.tc-at-group summary{padding:13px 16px;cursor:pointer;font-weight:600}
				#tcd-auto-test .tc-at-group-control{padding:0 16px 12px}
			</style>
			<form class="tc-at-actions" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="test_group" value="all">
				<?php wp_nonce_field( self::NONCE, 'tcd_auto_test_nonce' ); ?>
				<?php submit_button( __( 'Ejecutar Auto Test completo', 'taxocommerce-dependiente' ), 'primary', 'submit', false ); ?>
				<span><?php esc_html_e( 'Solo lectura, sin reiniciar formación ni alterar productos, memoria o workers.', 'taxocommerce-dependiente' ); ?></span>
			</form>
			<?php if ( $report ) : ?>
				<p><strong><?php echo esc_html( $report['date'] ); ?></strong> · <?php echo esc_html( $report['version'] ); ?> · <?php echo esc_html( absint( $report['ms'] ) . ' ms' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Aún no hay resultados de esta versión. Ejecuta una prueba para ver el estado.', 'taxocommerce-dependiente' ); ?></p>
			<?php endif; ?>
			<div class="tc-at-summary">
				<span><strong style="color:#188038"><?php echo esc_html( absint( $totals['pass'] ?? 0 ) ); ?></strong> <?php esc_html_e( 'Correctas', 'taxocommerce-dependiente' ); ?></span>
				<span><strong style="color:#c5221f"><?php echo esc_html( absint( $totals['fail'] ?? 0 ) ); ?></strong> <?php esc_html_e( 'Errores', 'taxocommerce-dependiente' ); ?></span>
				<span><strong><?php echo esc_html( absint( $totals['skip'] ?? 0 ) ); ?></strong> <?php esc_html_e( 'No aplicables / sin datos', 'taxocommerce-dependiente' ); ?></span>
				<span><strong><?php echo esc_html( absint( $totals['not_run'] ?? 0 ) ); ?></strong> <?php esc_html_e( 'Sin ejecutar', 'taxocommerce-dependiente' ); ?></span>
			</div>
			<?php foreach ( self::groups() as $group_id => $group_title ) : ?>
				<?php
				$group_cases = array_filter( $cases, static function ( $labels ) use ( $group_id ) { return $labels[0] === $group_id; } );
				$failed = 0;
				foreach ( $group_cases as $id => $labels ) { if ( 'fail' === ( $rows[ $id ]['status'] ?? '' ) ) { ++$failed; } }
				?>
				<details class="tc-at-group" <?php echo $failed || ! $report || 'all' === ( $report['group'] ?? '' ) || ( $report['group'] ?? '' ) === $group_id ? 'open' : ''; ?>>
					<summary><?php echo esc_html( $group_title . ' · ' . count( $group_cases ) . ' pruebas' ); ?><?php echo $failed ? ' · ' . esc_html( $failed . ' errores' ) : ''; ?></summary>
					<form class="tc-at-group-control" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<input type="hidden" name="test_group" value="<?php echo esc_attr( $group_id ); ?>">
						<?php wp_nonce_field( self::NONCE, 'tcd_auto_test_nonce' ); ?>
						<?php submit_button( __( 'Ejecutar este grupo', 'taxocommerce-dependiente' ), 'secondary small', 'submit', false ); ?>
					</form>
					<?php foreach ( $group_cases as $id => $labels ) : ?>
						<?php $row = $rows[ $id ] ?? array( 'id'=>$id, 'status'=>'not_run', 'message'=>'No ejecutado.', 'ms'=>0 ); ?>
						<div class="tc-at-row tc-at-row--<?php echo esc_attr( $row['status'] ); ?>">
							<span class="tc-at-dot tc-at-dot--<?php echo esc_attr( $row['status'] ); ?>" aria-hidden="true"></span>
							<div><strong><?php echo esc_html( $labels[1] ); ?> <span class="tc-at-small"><?php echo esc_html( $id ); ?></span></strong>
								<p><?php echo esc_html( $row['message'] ); ?></p>
								<span class="tc-at-small"><?php echo esc_html( absint( $row['ms'] ) . ' ms' ); ?></span></div>
						</div>
					<?php endforeach; ?>
				</details>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Verde: comprobación superada. Rojo: regresión técnica o datos incoherentes. Gris: sin ejecutar o no aplicable. Las pruebas de lectura y las muestras no garantizan por sí solas el correcto funcionamiento de todas las operaciones de escritura ni la relevancia de todas las búsquedas.', 'taxocommerce-dependiente' ); ?></p>
		</section>
		<?php
	}
}
