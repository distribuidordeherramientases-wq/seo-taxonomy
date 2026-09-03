<?php

defined('ABSPATH') || exit;

/**
 * Portabilidad del conocimiento consolidado de Dependiente.
 *
 * Exporta solo conocimiento portable y estados de Academia ya completados.
 * El importador trabaja en modo merge: nunca elimina conocimiento local ni
 * sobreescribe silenciosamente una regla distinta con la misma identidad.
 */
final class SEO_Dependiente_Knowledge_Transfer {
    const SCHEMA_NAME = 'seo_dependiente_knowledge';
    const SCHEMA_VERSION = 1;
    const MAX_IMPORT_BYTES = 134217728; // 128 MiB, sujeto tambien a upload_max_filesize.
    const LAST_IMPORT_OPTION = 'seo_dependiente_knowledge_last_import';
    const BRAIN_ID_OPTION = 'seo_dependiente_brain_id';
    const LAST_EXPORT_OPTION = 'seo_dependiente_knowledge_last_export';
    const NOTICE_TRANSIENT_PREFIX = 'seo_dependiente_knowledge_notice_';

    public static function init() {
        add_action('admin_post_seo_dependiente_export_knowledge', array(__CLASS__, 'export_knowledge'));
        add_action('admin_post_seo_dependiente_import_knowledge', array(__CLASS__, 'import_knowledge'));
    }

    public static function render_tab() {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-taxonomy'));
        }

        if (class_exists('SEO_Dependiente_Semantics')) {
            SEO_Dependiente_Semantics::ensure_ready();
        }
        if (class_exists('SEO_Dependiente_Entrenador')) {
            SEO_Dependiente_Entrenador::ensure_ready();
        }

        $stats = self::stats();
        $last_import = get_option(self::LAST_IMPORT_OPTION, array());
        $notice = self::pull_notice();
        $export_url = wp_nonce_url(
            add_query_arg(array('action' => 'seo_dependiente_export_knowledge'), admin_url('admin-post.php')),
            'seo_dependiente_export_knowledge'
        );

        if ($notice) {
            $class = !empty($notice['error']) ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html((string) ($notice['message'] ?? '')) . '</p>';
            if (!empty($notice['details']) && is_array($notice['details'])) {
                echo '<p class="description">' . esc_html(self::format_import_details($notice['details'])) . '</p>';
            }
            echo '</div>';
        }
        ?>
        <div class="seo-dependiente-admin__metrics">
            <?php self::metric('Snapshot de conocimiento', absint($stats['snapshot'] ?? 0)); ?>
            <?php self::metric('Reglas portables', absint($stats['portable_rules'] ?? 0)); ?>
            <?php self::metric('Reglas Academia', absint($stats['academy_rules'] ?? 0)); ?>
            <?php self::metric('Reglas aprendidas', absint($stats['learned_rules'] ?? 0)); ?>
            <?php self::metric('Lecciones completadas', absint($stats['completed_lessons'] ?? 0)); ?>
            <?php self::metric('No exportadas / pendientes', absint($stats['transient_rules'] ?? 0)); ?>
        </div>

        <div class="seo-dependiente-admin__grid">
            <div>
                <section class="postbox seo-dependiente-admin__box">
                    <h2 class="seo-dependiente-admin__box-title">Exportar conocimiento</h2>
                    <p>Genera una copia portable del cerebro consolidado de Dependiente para moverlo entre staging y producción o conservarlo como backup.</p>
                    <p><strong>Incluye:</strong> reglas semánticas consolidadas, reglas aprobadas/aprendidas y el estado de las lecciones de Academia ya completadas.</p>
                    <p><strong>No incluye:</strong> índice de productos, búsquedas de clientes, sesiones, candidatos pendientes o rechazados, preguntas/runs de Academia ni reglas <code>academy_stage</code> todavía no promocionadas.</p>
                    <p><a class="button button-primary" href="<?php echo esc_url($export_url); ?>">Descargar copia del conocimiento</a></p>
                    <p class="description">El catálogo del destino debe reindexarse desde su propia base de datos. Las referencias al vocabulario se transportan por grupo + slug y se resuelven de nuevo al importar.</p>
                </section>

                <section class="postbox seo-dependiente-admin__box">
                    <h2 class="seo-dependiente-admin__box-title">Importar y fusionar conocimiento</h2>
                    <p>La importación funciona en <strong>modo merge seguro</strong>: añade lo que falta y conserva el conocimiento que ya exista en esta instalación.</p>
                    <ul class="seo-dependiente-admin__steps">
                        <li>Una regla inexistente se inserta.</li>
                        <li>Una regla ya idéntica se conserva sin duplicarla.</li>
                        <li>Si la misma clave tiene contenido distinto, se considera conflicto y <strong>se mantiene la versión local</strong>.</li>
                        <li>No se borra ninguna regla local que no venga en el archivo.</li>
                        <li>Las lecciones completadas solo pueden avanzar el estado de Academia; nunca se retrocede un snapshot.</li>
                    </ul>
                    <?php if (!empty($stats['academy_busy'])) : ?>
                        <div class="notice notice-warning inline"><p>La Academia tiene una lección activa o en preparación. Puedes exportar, pero la importación queda bloqueada hasta que esa formación termine o quede en estado estable.</p></div>
                    <?php endif; ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="seo_dependiente_import_knowledge">
                        <?php wp_nonce_field('seo_dependiente_import_knowledge'); ?>
                        <p><input type="file" name="knowledge_file" accept=".json,.gz,.json.gz,application/json,application/gzip" required></p>
                        <p><label><input type="checkbox" name="confirm_merge" value="1" required> Confirmo que quiero fusionar este conocimiento con el cerebro local.</label></p>
                        <?php submit_button('Importar y fusionar conocimiento', 'primary', 'submit', false, !empty($stats['academy_busy']) ? array('disabled' => 'disabled') : array()); ?>
                    </form>
                    <p class="description">Se aceptan los archivos generados por esta pantalla. Si el servidor dispone de gzip, la exportación se comprime automáticamente.</p>
                </section>
            </div>

            <div>
                <section class="postbox seo-dependiente-admin__box">
                    <h2 class="seo-dependiente-admin__box-title">Cómo se protege producción</h2>
                    <p>Esta herramienta no hace un reemplazo de tablas. Está pensada para el flujo <strong>producción → staging → formación → producción</strong> sin borrar lo que producción haya aprendido mientras staging trabaja.</p>
                    <p>La identidad primaria de una regla es <code>rule_key</code>. Como segunda defensa se calcula una huella semántica independiente de IDs MySQL para detectar reglas equivalentes aunque procedan de otra instalación.</p>
                    <p class="description">Los conflictos no se resuelven automáticamente. Se contabilizan y la versión de destino gana por defecto, evitando que una importación silenciosa pise aprendizaje local.</p>
                </section>

                <section class="postbox seo-dependiente-admin__box">
                    <h2 class="seo-dependiente-admin__box-title">Identidad del cerebro</h2>
                    <p><strong>Brain ID</strong><br><code><?php echo esc_html(self::brain_id()); ?></code></p>
                    <?php if (!empty($last_import) && is_array($last_import)) : ?>
                        <p><strong>Última importación</strong><br><?php echo esc_html((string) ($last_import['imported_at'] ?? '')); ?></p>
                        <?php if (!empty($last_import['source_home_url'])) : ?><p class="description">Origen: <?php echo esc_html((string) $last_import['source_home_url']); ?></p><?php endif; ?>
                        <?php if (!empty($last_import['checkpoint_id'])) : ?><p class="description">Checkpoint: <code><?php echo esc_html((string) $last_import['checkpoint_id']); ?></code></p><?php endif; ?>
                    <?php else : ?>
                        <p class="description">Todavía no se ha importado conocimiento externo en esta instalación.</p>
                    <?php endif; ?>
                </section>

                <section class="postbox seo-dependiente-admin__box">
                    <h2 class="seo-dependiente-admin__box-title">Reglas excluidas del paquete</h2>
                    <p><code>seed</code> se obtiene de la versión instalada del plugin y no se copia entre sitios.</p>
                    <p><code>academy_stage</code>, <code>learned_candidate</code> y <code>learned_rejected</code> son estados de trabajo/evidencia y no forman parte del conocimiento consolidado.</p>
                    <p class="description">Los metadatos de reglas aprobadas se limpian de hashes de sesión, ejemplos de consultas, IDs de usuario y referencias a logs antes de exportarse.</p>
                </section>
            </div>
        </div>
        <?php
    }

    public static function export_knowledge() {
        if (!current_user_can(self::capability())) {
            wp_die('Permisos insuficientes.');
        }
        check_admin_referer('seo_dependiente_export_knowledge');

        if (!class_exists('SEO_Dependiente_Semantics')) {
            wp_die('La semántica de Dependiente no está disponible.');
        }
        SEO_Dependiente_Semantics::ensure_ready();
        if (class_exists('SEO_Dependiente_Entrenador')) {
            SEO_Dependiente_Entrenador::ensure_ready();
        }

        $checkpoint_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('checkpoint-', true);
        $rules = self::export_rules();
        $lessons = self::export_completed_lessons();
        $snapshot = absint(get_option('seo_dependiente_knowledge_snapshot', 0));
        $catalog = class_exists('SEO_Dependiente_Index') ? SEO_Dependiente_Index::status() : array();

        $document = array(
            'schema' => array(
                'name'    => self::SCHEMA_NAME,
                'version' => self::SCHEMA_VERSION,
            ),
            'generated_at' => current_time('c'),
            'source' => array(
                'home_url'             => home_url('/'),
                'site_url'             => site_url('/'),
                'brain_id'             => self::brain_id(),
                'checkpoint_id'        => $checkpoint_id,
                'dependiente_version'  => defined('SEO_DEPENDIENTE_VERSION') ? SEO_DEPENDIENTE_VERSION : '',
                'trainer_db_version'   => (string) get_option('seo_dependiente_entrenador_db_version', ''),
                'wordpress_version'    => get_bloginfo('version'),
            ),
            'manifest' => array(
                'knowledge_snapshot'         => $snapshot,
                'semantic_rules'             => count($rules),
                'academy_completed_lessons'  => count($lessons),
                'rules_digest'               => self::rules_digest($rules),
                'catalog'                    => array(
                    'indexed'   => absint($catalog['indexed'] ?? 0),
                    'published' => absint($catalog['published'] ?? 0),
                    'last_full' => (string) ($catalog['last_full'] ?? ''),
                ),
            ),
            'semantic_rules' => $rules,
            'academy' => array(
                'completed_lessons' => $lessons,
            ),
        );

        $json = wp_json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || '' === $json) {
            wp_die('No se pudo generar la copia de conocimiento.');
        }

        update_option(self::LAST_EXPORT_OPTION, array(
            'checkpoint_id' => $checkpoint_id,
            'exported_at'   => current_time('mysql'),
            'rules'         => count($rules),
            'snapshot'      => $snapshot,
        ), false);

        $base = 'dependiente-conocimiento-' . current_time('Ymd-His');
        nocache_headers();
        if (function_exists('gzencode')) {
            $compressed = gzencode($json, 6);
            if (false !== $compressed) {
                header('Content-Type: application/gzip');
                header('Content-Disposition: attachment; filename="' . sanitize_file_name($base . '.json.gz') . '"');
                header('Content-Length: ' . strlen($compressed));
                echo $compressed;
                exit;
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($base . '.json') . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    public static function import_knowledge() {
        if (!current_user_can(self::capability())) {
            wp_die('Permisos insuficientes.');
        }
        check_admin_referer('seo_dependiente_import_knowledge');

        if (empty($_POST['confirm_merge'])) {
            self::redirect_with_notice(true, 'Debes confirmar explícitamente la fusión del conocimiento.');
        }
        if (self::academy_busy()) {
            self::redirect_with_notice(true, 'La Academia tiene una lección activa. La importación se ha bloqueado para no alterar un entrenamiento en curso.');
        }
        if (empty($_FILES['knowledge_file']) || !is_array($_FILES['knowledge_file'])) {
            self::redirect_with_notice(true, 'No se ha recibido ningún archivo de conocimiento.');
        }

        $file = $_FILES['knowledge_file'];
        $error = absint($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (UPLOAD_ERR_OK !== $error) {
            self::redirect_with_notice(true, 'La subida del archivo ha fallado (código ' . $error . ').');
        }
        $size = absint($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_IMPORT_BYTES) {
            self::redirect_with_notice(true, 'El archivo está vacío o supera el límite de 128 MiB del importador.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!$tmp || !is_uploaded_file($tmp) || !is_readable($tmp)) {
            self::redirect_with_notice(true, 'No se puede leer el archivo temporal subido.');
        }

        $raw = file_get_contents($tmp);
        if (false === $raw || '' === $raw) {
            self::redirect_with_notice(true, 'El archivo de conocimiento está vacío.');
        }
        if (0 === strncmp($raw, "\x1f\x8b", 2)) {
            if (!function_exists('gzdecode')) {
                self::redirect_with_notice(true, 'El servidor no dispone de soporte gzip para leer esta copia comprimida.');
            }
            $decoded = gzdecode($raw);
            if (false === $decoded) {
                self::redirect_with_notice(true, 'No se ha podido descomprimir la copia de conocimiento.');
            }
            $raw = $decoded;
        }

        $document = json_decode($raw, true);
        if (!is_array($document) || JSON_ERROR_NONE !== json_last_error()) {
            self::redirect_with_notice(true, 'El archivo no contiene un JSON de conocimiento válido.');
        }
        $validation = self::validate_document($document);
        if (is_wp_error($validation)) {
            self::redirect_with_notice(true, $validation->get_error_message());
        }

        if (!class_exists('SEO_Dependiente_Semantics')) {
            self::redirect_with_notice(true, 'La semántica de Dependiente no está disponible en el destino.');
        }
        SEO_Dependiente_Semantics::ensure_ready();
        if (class_exists('SEO_Dependiente_Entrenador')) {
            SEO_Dependiente_Entrenador::ensure_ready();
        }

        $result = self::merge_document($document);
        if (is_wp_error($result)) {
            self::redirect_with_notice(true, $result->get_error_message());
        }

        update_option(self::LAST_IMPORT_OPTION, array(
            'imported_at'     => current_time('mysql'),
            'source_home_url' => esc_url_raw((string) ($document['source']['home_url'] ?? '')),
            'source_brain_id' => sanitize_text_field((string) ($document['source']['brain_id'] ?? '')),
            'checkpoint_id'   => sanitize_text_field((string) ($document['source']['checkpoint_id'] ?? '')),
            'source_version'  => sanitize_text_field((string) ($document['source']['dependiente_version'] ?? '')),
            'result'          => $result,
        ), false);

        self::redirect_with_notice(false, 'Conocimiento fusionado correctamente.', $result);
    }

    private static function merge_document($document) {
        global $wpdb;

        $rules = isset($document['semantic_rules']) && is_array($document['semantic_rules']) ? $document['semantic_rules'] : array();
        $lessons = isset($document['academy']['completed_lessons']) && is_array($document['academy']['completed_lessons'])
            ? $document['academy']['completed_lessons']
            : array();
        $incoming_snapshot = absint($document['manifest']['knowledge_snapshot'] ?? 0);
        $before_snapshot = absint(get_option('seo_dependiente_knowledge_snapshot', 0));
        $transaction_started = false;

        try {
            if (false === $wpdb->query('START TRANSACTION')) {
                throw new RuntimeException('No se pudo iniciar la transacción de importación.');
            }
            $transaction_started = true;

            $rule_result = self::merge_rules($rules);
            if (is_wp_error($rule_result)) {
                throw new RuntimeException($rule_result->get_error_message());
            }

            $lesson_result = self::merge_completed_lessons($lessons);
            if (is_wp_error($lesson_result)) {
                throw new RuntimeException($lesson_result->get_error_message());
            }

            $lesson_snapshot = absint($lesson_result['max_snapshot'] ?? 0);
            $after_snapshot = max($before_snapshot, $incoming_snapshot, $lesson_snapshot);
            if ($after_snapshot > $before_snapshot) {
                update_option('seo_dependiente_knowledge_snapshot', $after_snapshot, false);
            }

            if (false === $wpdb->query('COMMIT')) {
                throw new RuntimeException('No se pudo confirmar la importación.');
            }
            $transaction_started = false;

            SEO_Dependiente_Semantics::resolve_vocabulary_ids();
            SEO_Dependiente_Semantics::ensure_ready();
            if (class_exists('SEO_Dependiente_Entrenador')) {
                SEO_Dependiente_Entrenador::ensure_ready();
            }

            return array(
                'rules_inserted'       => absint($rule_result['inserted'] ?? 0),
                'rules_identical'      => absint($rule_result['identical'] ?? 0),
                'rules_equivalent'     => absint($rule_result['equivalent'] ?? 0),
                'rules_conflicts'      => absint($rule_result['conflicts'] ?? 0),
                'rules_ignored'        => absint($rule_result['ignored'] ?? 0),
                'conflict_samples'     => array_slice((array) ($rule_result['conflict_samples'] ?? array()), 0, 20),
                'lessons_completed'    => absint($lesson_result['completed'] ?? 0),
                'lessons_already_done' => absint($lesson_result['already_completed'] ?? 0),
                'snapshot_before'      => $before_snapshot,
                'snapshot_after'       => $after_snapshot,
            );
        } catch (Throwable $error) {
            if ($transaction_started) {
                $wpdb->query('ROLLBACK');
            }
            return new WP_Error('seo_dependiente_knowledge_import_failed', $error->getMessage());
        }
    }

    private static function merge_rules($rules) {
        global $wpdb;

        $table = SEO_Dependiente_Semantics::table();
        if (!SEO_Dependiente_Semantics::table_exists()) {
            return new WP_Error('seo_dependiente_semantics_missing', 'No existe la tabla de semántica de Dependiente.');
        }

        $existing_rows = (array) $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);
        $by_key = array();
        $by_hash = array();
        foreach ($existing_rows as $existing) {
            $normalized = self::normalize_rule($existing, true);
            if (!$normalized) {
                continue;
            }
            $key = (string) $normalized['rule_key'];
            $hash = self::rule_hash($normalized);
            if ($key) {
                $by_key[$key] = $normalized;
            }
            if ($hash && !isset($by_hash[$hash])) {
                $by_hash[$hash] = $key;
            }
        }

        $result = array(
            'inserted' => 0,
            'identical' => 0,
            'equivalent' => 0,
            'conflicts' => 0,
            'ignored' => 0,
            'conflict_samples' => array(),
        );

        foreach ((array) $rules as $incoming_raw) {
            if (!is_array($incoming_raw)) {
                $result['ignored']++;
                continue;
            }
            $incoming = self::normalize_rule($incoming_raw, false);
            if (!$incoming || self::is_transient_source((string) ($incoming['source'] ?? ''))) {
                $result['ignored']++;
                continue;
            }

            $rule_key = (string) $incoming['rule_key'];
            $incoming_hash = self::rule_hash($incoming);
            if (!$rule_key || !$incoming_hash) {
                $result['ignored']++;
                continue;
            }

            if (isset($by_key[$rule_key])) {
                $existing_hash = self::rule_hash($by_key[$rule_key]);
                if (hash_equals($existing_hash, $incoming_hash)) {
                    $result['identical']++;
                } else {
                    $result['conflicts']++;
                    if (count($result['conflict_samples']) < 20) {
                        $result['conflict_samples'][] = $rule_key;
                    }
                }
                continue;
            }

            if (isset($by_hash[$incoming_hash])) {
                $result['equivalent']++;
                continue;
            }

            $insert = self::rule_db_row($incoming);
            $insert['created_at'] = self::valid_mysql_datetime((string) ($incoming['created_at'] ?? '')) ?: current_time('mysql');
            $insert['updated_at'] = current_time('mysql');
            if (false === $wpdb->insert($table, $insert)) {
                return new WP_Error('seo_dependiente_rule_import_failed', 'No se pudo importar la regla ' . $rule_key . '.');
            }

            $result['inserted']++;
            $by_key[$rule_key] = $incoming;
            $by_hash[$incoming_hash] = $rule_key;
        }

        return $result;
    }

    private static function merge_completed_lessons($lessons) {
        global $wpdb;

        $result = array('completed' => 0, 'already_completed' => 0, 'max_snapshot' => 0);
        if (!$lessons || !class_exists('SEO_Dependiente_Entrenador')) {
            return $result;
        }
        if (!SEO_Dependiente_Entrenador::ensure_ready()) {
            return new WP_Error('seo_dependiente_academy_missing', 'No se pueden preparar las tablas de Academia en el destino.');
        }

        $table = SEO_Dependiente_Entrenador::lessons_table();
        foreach ((array) $lessons as $incoming) {
            if (!is_array($incoming)) {
                continue;
            }
            $key = sanitize_key((string) ($incoming['lesson_key'] ?? ''));
            if (!$key || 'completed' !== sanitize_key((string) ($incoming['status'] ?? 'completed'))) {
                continue;
            }

            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE lesson_key = %s LIMIT 1", $key), ARRAY_A);
            if (!$row) {
                continue;
            }
            $incoming_after = absint($incoming['snapshot_after'] ?? 0);
            $result['max_snapshot'] = max($result['max_snapshot'], $incoming_after);

            if ('completed' === (string) ($row['status'] ?? '')) {
                $result['already_completed']++;
                continue;
            }

            $metadata = self::decode_metadata($incoming['metadata'] ?? array());
            $metadata['knowledge_import'] = array(
                'imported_at' => current_time('mysql'),
                'source'      => 'knowledge_transfer',
            );
            $item_count = absint($incoming['item_count'] ?? 0);
            $completed_items = absint($incoming['completed_items'] ?? $item_count);
            $data = array(
                'status'          => 'completed',
                'module_count'    => absint($incoming['module_count'] ?? 0),
                'item_count'      => $item_count,
                'completed_items' => max($completed_items, $item_count),
                'prepare_offset'  => $item_count,
                'prepare_total'   => $item_count,
                'snapshot_before' => absint($incoming['snapshot_before'] ?? 0),
                'snapshot_after'  => $incoming_after,
                'source_signature'=> null,
                'metadata'        => wp_json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'completed_at'    => self::valid_mysql_datetime((string) ($incoming['completed_at'] ?? '')) ?: current_time('mysql'),
                'updated_at'      => current_time('mysql'),
            );
            if (false === $wpdb->update($table, $data, array('lesson_key' => $key))) {
                return new WP_Error('seo_dependiente_lesson_import_failed', 'No se pudo importar el estado de la lección ' . $key . '.');
            }
            $result['completed']++;
        }

        return $result;
    }

    private static function export_rules() {
        global $wpdb;

        $table = SEO_Dependiente_Semantics::table();
        if (!SEO_Dependiente_Semantics::table_exists()) {
            return array();
        }
        $rows = (array) $wpdb->get_results(
            "SELECT * FROM {$table} WHERE source IS NULL OR source NOT IN ('seed','academy_stage','learned_candidate','learned_rejected') ORDER BY rule_key ASC",
            ARRAY_A
        );
        $vocabulary = self::vocabulary_identity_map();
        $out = array();
        foreach ($rows as $row) {
            $row = self::hydrate_vocabulary_identity($row, $vocabulary);
            $rule = self::normalize_rule($row, true);
            if (!$rule || self::is_transient_source((string) ($rule['source'] ?? ''))) {
                continue;
            }
            $rule['rule_hash'] = self::rule_hash($rule);
            $out[] = $rule;
        }
        return $out;
    }

    private static function export_completed_lessons() {
        global $wpdb;

        if (!class_exists('SEO_Dependiente_Entrenador') || !SEO_Dependiente_Entrenador::ensure_ready()) {
            return array();
        }
        $table = SEO_Dependiente_Entrenador::lessons_table();
        $rows = (array) $wpdb->get_results(
            "SELECT lesson_key, lesson_order, title, status, module_size, module_count, item_count, completed_items, snapshot_before, snapshot_after, metadata, completed_at FROM {$table} WHERE status = 'completed' ORDER BY lesson_order ASC, id ASC",
            ARRAY_A
        );
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'lesson_key'      => sanitize_key((string) ($row['lesson_key'] ?? '')),
                'lesson_order'    => absint($row['lesson_order'] ?? 0),
                'title'           => sanitize_text_field((string) ($row['title'] ?? '')),
                'status'          => 'completed',
                'module_size'     => absint($row['module_size'] ?? 0),
                'module_count'    => absint($row['module_count'] ?? 0),
                'item_count'      => absint($row['item_count'] ?? 0),
                'completed_items' => absint($row['completed_items'] ?? 0),
                'snapshot_before' => absint($row['snapshot_before'] ?? 0),
                'snapshot_after'  => absint($row['snapshot_after'] ?? 0),
                'metadata'        => self::portable_metadata($row['metadata'] ?? null),
                'completed_at'    => (string) ($row['completed_at'] ?? ''),
            );
        }
        return $out;
    }

    private static function normalize_rule($row, $from_database) {
        if (!is_array($row)) {
            return null;
        }

        $source = sanitize_key((string) ($row['source'] ?? 'manual')) ?: 'manual';
        if (!$from_database && ('seed' === $source || self::is_transient_source($source))) {
            return null;
        }
        $rule_key = sanitize_key((string) ($row['rule_key'] ?? ''));
        if (!$rule_key) {
            return null;
        }

        $confidence = $row['confidence'] ?? null;
        if ('' === (string) $confidence || null === $confidence) {
            $confidence = null;
        } else {
            $confidence = round(min(1, max(0, (float) $confidence)), 4);
        }

        $metadata = self::portable_metadata($row['metadata'] ?? null);
        return array(
            'rule_key'             => $rule_key,
            'rule_type'            => sanitize_key((string) ($row['rule_type'] ?? 'alias')) ?: 'alias',
            'expression'           => self::nullable_text($row['expression'] ?? null, 255),
            'normalized_expression'=> self::nullable_text($row['normalized_expression'] ?? null, 255),
            'canonical_expression' => self::nullable_text($row['canonical_expression'] ?? null, 255),
            'match_type'           => sanitize_key((string) ($row['match_type'] ?? 'token')) ?: 'token',
            'semantic_role'        => self::nullable_key($row['semantic_role'] ?? null),
            'source_group'         => self::nullable_key($row['source_group'] ?? null),
            'source_slug'          => self::nullable_text($row['source_slug'] ?? null, 191),
            'context_group'        => self::nullable_key($row['context_group'] ?? null),
            'context_slug'         => self::nullable_text($row['context_slug'] ?? null, 191),
            'target_group'         => self::nullable_key($row['target_group'] ?? null),
            'target_slug'          => self::nullable_text($row['target_slug'] ?? null, 191),
            'relation_type'        => self::nullable_key($row['relation_type'] ?? null),
            'result_role'          => self::nullable_key($row['result_role'] ?? null),
            'weight'               => min(1000, max(0, absint($row['weight'] ?? 100))),
            'priority'             => min(100, max(0, absint($row['priority'] ?? 5))),
            'confidence'           => $confidence,
            'language'             => sanitize_key((string) ($row['language'] ?? 'es')) ?: 'es',
            'source'               => $source,
            'metadata'             => $metadata,
            'active'               => empty($row['active']) ? 0 : 1,
            'created_at'           => (string) ($row['created_at'] ?? ''),
            'updated_at'           => (string) ($row['updated_at'] ?? ''),
        );
    }

    private static function rule_db_row($rule) {
        return array(
            'rule_key'              => (string) $rule['rule_key'],
            'rule_type'             => (string) $rule['rule_type'],
            'expression'            => $rule['expression'],
            'normalized_expression' => $rule['normalized_expression'],
            'canonical_expression'  => $rule['canonical_expression'],
            'match_type'            => (string) $rule['match_type'],
            'semantic_role'         => $rule['semantic_role'],
            'source_vocabulary_id'  => null,
            'source_group'          => $rule['source_group'],
            'source_slug'           => $rule['source_slug'],
            'context_vocabulary_id' => null,
            'context_group'         => $rule['context_group'],
            'context_slug'          => $rule['context_slug'],
            'target_vocabulary_id'  => null,
            'target_group'          => $rule['target_group'],
            'target_slug'           => $rule['target_slug'],
            'relation_type'         => $rule['relation_type'],
            'result_role'           => $rule['result_role'],
            'weight'                => absint($rule['weight']),
            'priority'              => absint($rule['priority']),
            'confidence'            => $rule['confidence'],
            'language'              => (string) $rule['language'],
            'source'                => (string) $rule['source'],
            'metadata'              => empty($rule['metadata']) ? null : wp_json_encode($rule['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'active'                => empty($rule['active']) ? 0 : 1,
        );
    }

    private static function rule_hash($rule) {
        if (!is_array($rule)) {
            return '';
        }
        $payload = array(
            'rule_type'             => (string) ($rule['rule_type'] ?? ''),
            'expression'            => $rule['expression'] ?? null,
            'normalized_expression' => $rule['normalized_expression'] ?? null,
            'canonical_expression'  => $rule['canonical_expression'] ?? null,
            'match_type'            => (string) ($rule['match_type'] ?? ''),
            'semantic_role'         => $rule['semantic_role'] ?? null,
            'source_group'          => $rule['source_group'] ?? null,
            'source_slug'           => $rule['source_slug'] ?? null,
            'context_group'         => $rule['context_group'] ?? null,
            'context_slug'          => $rule['context_slug'] ?? null,
            'target_group'          => $rule['target_group'] ?? null,
            'target_slug'           => $rule['target_slug'] ?? null,
            'relation_type'         => $rule['relation_type'] ?? null,
            'result_role'           => $rule['result_role'] ?? null,
            'weight'                => absint($rule['weight'] ?? 0),
            'priority'              => absint($rule['priority'] ?? 0),
            'confidence'            => $rule['confidence'] ?? null,
            'language'              => (string) ($rule['language'] ?? 'es'),
            'active'                => empty($rule['active']) ? 0 : 1,
        );
        return hash('sha256', wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function rules_digest($rules) {
        $hashes = array();
        foreach ((array) $rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $hashes[] = (string) ($rule['rule_key'] ?? '') . ':' . self::rule_hash($rule);
        }
        sort($hashes, SORT_STRING);
        return hash('sha256', implode("\n", $hashes));
    }

    private static function portable_metadata($metadata) {
        $decoded = self::decode_metadata($metadata);
        if (!$decoded) {
            return array();
        }
        return self::strip_private_metadata($decoded);
    }

    private static function strip_private_metadata($value) {
        if (!is_array($value)) {
            return $value;
        }
        $out = array();
        foreach ($value as $key => $child) {
            $key_string = (string) $key;
            $normalized = strtolower($key_string);
            if (preg_match('/(^|_)(session|session_hash|session_hashes|ip|ip_address|email|user_id|reviewed_by|source_log_id|last_log_id|query_example|examples|product_ids|positions)($|_)/', $normalized)) {
                continue;
            }
            $out[$key] = is_array($child) ? self::strip_private_metadata($child) : $child;
        }
        return $out;
    }

    private static function decode_metadata($metadata) {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (is_object($metadata)) {
            return (array) $metadata;
        }
        if (!is_string($metadata) || '' === trim($metadata)) {
            return array();
        }
        $decoded = json_decode($metadata, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function vocabulary_identity_map() {
        global $wpdb;

        $table = $wpdb->prefix . 'seo_vocabulary';
        if (!class_exists('SEO_Dependiente_Index') || !SEO_Dependiente_Index::table_exists($table)) {
            return array();
        }
        $rows = (array) $wpdb->get_results("SELECT id, semantic_group, slug FROM {$table}", ARRAY_A);
        $map = array();
        foreach ($rows as $row) {
            $id = absint($row['id'] ?? 0);
            if (!$id) {
                continue;
            }
            $map[$id] = array(
                'group' => sanitize_key((string) ($row['semantic_group'] ?? '')),
                'slug'  => sanitize_title((string) ($row['slug'] ?? '')),
            );
        }
        return $map;
    }

    private static function hydrate_vocabulary_identity($row, $map) {
        foreach (array('source', 'context', 'target') as $prefix) {
            $id_key = $prefix . '_vocabulary_id';
            $group_key = $prefix . '_group';
            $slug_key = $prefix . '_slug';
            $id = absint($row[$id_key] ?? 0);
            if (!$id || empty($map[$id])) {
                continue;
            }
            if (empty($row[$group_key])) {
                $row[$group_key] = $map[$id]['group'];
            }
            if (empty($row[$slug_key])) {
                $row[$slug_key] = $map[$id]['slug'];
            }
        }
        return $row;
    }

    private static function validate_document($document) {
        $schema = isset($document['schema']) && is_array($document['schema']) ? $document['schema'] : array();
        if (self::SCHEMA_NAME !== (string) ($schema['name'] ?? '')) {
            return new WP_Error('seo_dependiente_knowledge_schema', 'El archivo no es una copia de conocimiento de Dependiente.');
        }
        if (self::SCHEMA_VERSION !== absint($schema['version'] ?? 0)) {
            return new WP_Error('seo_dependiente_knowledge_version', 'La versión del formato de conocimiento no es compatible con este Dependiente.');
        }
        if (!isset($document['semantic_rules']) || !is_array($document['semantic_rules'])) {
            return new WP_Error('seo_dependiente_knowledge_rules', 'La copia no contiene el bloque de reglas semánticas esperado.');
        }
        return true;
    }

    private static function stats() {
        global $wpdb;

        $stats = array(
            'snapshot'          => absint(get_option('seo_dependiente_knowledge_snapshot', 0)),
            'portable_rules'    => 0,
            'academy_rules'     => 0,
            'learned_rules'     => 0,
            'transient_rules'   => 0,
            'completed_lessons' => 0,
            'academy_busy'      => self::academy_busy(),
        );

        if (class_exists('SEO_Dependiente_Semantics') && SEO_Dependiente_Semantics::table_exists()) {
            $table = SEO_Dependiente_Semantics::table();
            $stats['portable_rules'] = absint($wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE source IS NULL OR source NOT IN ('seed','academy_stage','learned_candidate','learned_rejected')"
            ));
            $stats['academy_rules'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE source = 'academy'"));
            $stats['learned_rules'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE source = 'learned'"));
            $stats['transient_rules'] = absint($wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE source IN ('academy_stage','learned_candidate','learned_rejected')"
            ));
        }

        if (class_exists('SEO_Dependiente_Entrenador') && SEO_Dependiente_Entrenador::ensure_ready()) {
            $table = SEO_Dependiente_Entrenador::lessons_table();
            $stats['completed_lessons'] = absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'completed'"));
        }

        return $stats;
    }

    private static function academy_busy() {
        global $wpdb;

        $auto = get_option('seo_dependiente_academy_auto_state', array());
        if (is_array($auto) && !empty($auto['enabled']) && in_array((string) ($auto['status'] ?? ''), array('running','preparing'), true)) {
            return true;
        }
        if (!class_exists('SEO_Dependiente_Entrenador')) {
            return false;
        }
        if (!SEO_Dependiente_Entrenador::ensure_ready()) {
            return false;
        }
        $table = SEO_Dependiente_Entrenador::lessons_table();
        $count = absint($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('preparing','prepared','in_progress')"));
        return $count > 0;
    }

    private static function is_transient_source($source) {
        return in_array(sanitize_key((string) $source), array('seed','academy_stage','learned_candidate','learned_rejected'), true);
    }

    private static function brain_id() {
        $brain_id = sanitize_text_field((string) get_option(self::BRAIN_ID_OPTION, ''));
        if ($brain_id) {
            return $brain_id;
        }
        $brain_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('brain-', true);
        update_option(self::BRAIN_ID_OPTION, $brain_id, false);
        return $brain_id;
    }

    private static function nullable_key($value) {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }
        $value = sanitize_key((string) $value);
        return '' === $value ? null : $value;
    }

    private static function nullable_text($value, $max_length) {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }
        $value = sanitize_text_field((string) $value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, absint($max_length));
        }
        return substr($value, 0, absint($max_length));
    }

    private static function valid_mysql_datetime($value) {
        $value = trim((string) $value);
        if (!$value || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return '';
        }
        return $value;
    }

    private static function format_import_details($details) {
        $parts = array(
            'nuevas ' . absint($details['rules_inserted'] ?? 0),
            'idénticas ' . absint($details['rules_identical'] ?? 0),
            'equivalentes ' . absint($details['rules_equivalent'] ?? 0),
            'conflictos conservados en destino ' . absint($details['rules_conflicts'] ?? 0),
            'lecciones avanzadas ' . absint($details['lessons_completed'] ?? 0),
            'snapshot ' . absint($details['snapshot_before'] ?? 0) . ' → ' . absint($details['snapshot_after'] ?? 0),
        );
        if (!empty($details['conflict_samples'])) {
            $parts[] = 'ejemplos de conflicto: ' . implode(', ', array_map('sanitize_key', array_slice((array) $details['conflict_samples'], 0, 5)));
        }
        return implode(' · ', $parts);
    }

    private static function redirect_with_notice($error, $message, $details = array()) {
        self::push_notice(array(
            'error'   => (bool) $error,
            'message' => (string) $message,
            'details' => is_array($details) ? $details : array(),
        ));
        wp_safe_redirect(add_query_arg(array('page' => 'seo-dependiente', 'tab' => 'knowledge'), admin_url('admin.php')));
        exit;
    }

    private static function push_notice($notice) {
        set_transient(self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(), $notice, 300);
    }

    private static function pull_notice() {
        $key = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);
        return is_array($notice) ? $notice : array();
    }

    private static function metric($label, $value) {
        ?>
        <div class="seo-dependiente-admin__metric">
            <strong><?php echo esc_html(number_format_i18n((int) $value)); ?></strong>
            <span><?php echo esc_html($label); ?></span>
        </div>
        <?php
    }

    private static function capability() {
        return class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
    }
}

SEO_Dependiente_Knowledge_Transfer::init();
