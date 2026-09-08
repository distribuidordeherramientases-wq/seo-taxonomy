<?php
/**
 * SEO System - Portable semantic catalog transfer.
 *
 * Moves catalog truth (masters + assignments) between installations using
 * portable keys. Source numeric IDs are exported for audit only and are never
 * used to resolve a destination row.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.3.4
 */

defined('ABSPATH') || exit;

final class SEO_Semantic_Catalog_Transfer {
    const SCHEMA_NAME = 'seo_semantic_catalog';
    const SCHEMA_VERSION = 1;
    const MODULE_VERSION = '1.1.0';
    const MAX_IMPORT_BYTES = 268435456; // 256 MiB.
    const PREVIEW_TTL = 7200;
    const NOTICE_PREFIX = 'seo_semantic_catalog_notice_';
    const PREVIEW_PREFIX = 'seo_semantic_catalog_preview_';
    const LAST_IMPORT_OPTION = 'seo_semantic_catalog_last_import';
    const REINDEX_OPTION = 'seo_semantic_catalog_reindex_pending';
    const DRIFT_OPTION = 'seo_semantic_catalog_drift';

    public static function init() {
        add_action('admin_post_seo_semantic_catalog_export', array(__CLASS__, 'export_package'));
        add_action('admin_post_seo_semantic_catalog_import', array(__CLASS__, 'import_package'));
        add_filter('seo_data_layer_tables', array(__CLASS__, 'register_data_layer_tables'));
    }

    public static function capability() {
        return 'manage_options';
    }

    public static function register_data_layer_tables($tables) {
        global $wpdb;
        $tables = is_array($tables) ? $tables : array();
        $defs = array(
            'semantic_vocabulary' => array($wpdb->prefix . 'seo_vocabulary', 'semantic_vocabulary'),
            'object_vocabulary' => array($wpdb->prefix . 'seo_object_vocabulary', 'object_vocabulary'),
            'semantic_type_role_map' => array($wpdb->prefix . 'seo_type_role_map', 'semantic_type_role_map'),
            'attribute_definitions' => array($wpdb->prefix . 'sql_atributos', 'attribute_definition'),
            'attribute_terms' => array($wpdb->prefix . 'sql_atributos_terminos', 'attribute_term'),
            'attribute_aliases' => array($wpdb->prefix . 'sql_atributos_aliases', 'attribute_alias'),
            'product_attributes' => array($wpdb->prefix . 'sql_product_atributos', 'product_attribute'),
        );
        foreach ($defs as $key => $def) {
            if (isset($tables[$key])) {
                continue;
            }
            if (!self::table_is_innodb($def[0])) {
                continue;
            }
            $tables[$key] = array(
                'table' => $def[0],
                'primary_key' => array('id'),
                'entity_type' => $def[1],
            );
        }
        return $tables;
    }

    private static function table_is_innodb($table) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
        return is_array($row) && !empty($row['Name']) && 0 === strcasecmp((string) ($row['Engine'] ?? ''), 'InnoDB');
    }

    private static function table_exists($table) {
        global $wpdb;
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    }

    public static function render_tab() {
        if (!current_user_can(self::capability())) {
            wp_die(esc_html__('No tienes permisos para acceder a esta pagina.', 'seo-taxonomy'));
        }
        $notice = self::pull_notice();
        $last = get_option(self::LAST_IMPORT_OPTION, array());
        $export_url = admin_url('admin-post.php');
        $import_url = admin_url('admin-post.php');
        if ($notice) {
            $class = !empty($notice['error']) ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
            echo '<div class="' . esc_attr($class) . '"><p><strong>' . esc_html((string) ($notice['message'] ?? '')) . '</strong></p>';
            if (!empty($notice['summary']) && is_array($notice['summary'])) {
                echo '<p class="description">' . esc_html(self::format_summary($notice['summary'])) . '</p>';
            }
            if (!empty($notice['conflicts']) && is_array($notice['conflicts'])) {
                echo '<details style="margin:8px 0"><summary>Conflictos detectados (' . esc_html(number_format_i18n(count($notice['conflicts']))) . ')</summary><ol>';
                foreach (array_slice($notice['conflicts'], 0, 100) as $conflict) {
                    echo '<li><code>' . esc_html((string) $conflict) . '</code></li>';
                }
                echo '</ol></details>';
            }
            echo '</div>';
        }
        ?>
        <div class="card" style="max-width:1200px;padding:20px;margin-bottom:20px;">
            <h2>Catalogo semantico portable</h2>
            <p><strong>Regla central:</strong> los IDs numericos de PRO pueden viajar como auditoria, pero nunca se usan para localizar ni modificar el destino. Productos, categorias, terminos y vocabulario se resuelven de nuevo mediante claves portables.</p>
            <p>Este modulo transporta verdad de catalogo. No exporta ni importa reglas de Dependiente, snapshots, academy_stage, lecciones, preguntas, runs, candidatos, estadisticas de busqueda ni sesiones.</p>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:20px;max-width:1200px;">
            <div class="card" style="max-width:none;padding:20px;">
                <h2>Exportar catalogo semantico</h2>
                <p>Genera un JSON portable con maestros y asignaciones. Los objetos se exportan aunque una capa este vacia para que el modo MIRROR pueda limpiar correctamente el destino.</p>
                <form method="post" action="<?php echo esc_url($export_url); ?>">
                    <input type="hidden" name="action" value="seo_semantic_catalog_export">
                    <?php wp_nonce_field('seo_semantic_catalog_export'); ?>
                    <fieldset style="margin:12px 0;">
                        <legend><strong>Capas</strong></legend>
                        <?php self::scope_checkbox('masters', 'Maestros / diccionarios', true); ?>
                        <?php self::scope_checkbox('product_tags', 'Producto -> Etiquetas WC', true); ?>
                        <?php self::scope_checkbox('product_semantic', 'Producto -> Vocabulary semantico', true); ?>
                        <?php self::scope_checkbox('product_attributes', 'Producto -> Atributos canonicos', true); ?>
                        <?php self::scope_checkbox('category_semantic', 'Categoria -> Vocabulary semantico', true); ?>
                        <?php self::scope_checkbox('category_labels', 'Categoria -> etiquetas legacy seo_nodes/category', true); ?>
                    </fieldset>
                    <p class="description">Formato actual: paquete completo (full). El manifest ya reserva el campo para futuros deltas.</p>
                    <p><button type="submit" class="button button-primary">Descargar paquete JSON</button></p>
                </form>
            </div>

            <div class="card" style="max-width:none;padding:20px;">
                <h2>Importar catalogo semantico</h2>
                <p><strong>Simular es obligatorio antes de escribir.</strong> La aplicacion exige volver a subir exactamente el mismo archivo y usar el mismo modo que fue simulado.</p>
                <form method="post" action="<?php echo esc_url($import_url); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="seo_semantic_catalog_import">
                    <?php wp_nonce_field('seo_semantic_catalog_import'); ?>
                    <p><input type="file" name="semantic_catalog_file" accept=".json,.gz,.json.gz,application/json,application/gzip" required></p>
                    <p><label><strong>Modo</strong><br><select name="import_mode">
                        <option value="mirror">ALINEAR / MIRROR: dejar las capas incluidas como el origen</option>
                        <option value="merge">MERGE: anadir faltantes sin retirar asignaciones locales</option>
                    </select></label></p>
                    <label style="display:block;margin:10px 0;padding:10px;border-left:4px solid #dba617;background:#fff8e5;">
                        <input type="checkbox" name="purge_extra_masters" value="1">
                        En MIRROR, retirar/desactivar maestros extra cuando sea seguro. Si tienen referencias fuera del alcance del paquete se bloquea como conflicto.
                    </label>
                    <p><button type="submit" name="semantic_catalog_intent" value="preview" class="button button-primary">1. Simular importacion</button></p>
                    <hr>
                    <label style="display:block;margin:10px 0;"><input type="checkbox" name="confirm_apply" value="1"> Confirmo que quiero aplicar el ultimo paquete simulado.</label>
                    <p><button type="submit" name="semantic_catalog_intent" value="apply" class="button">2. Aplicar paquete simulado</button></p>
                </form>
                <?php if (!empty($last) && is_array($last)) : ?>
                    <p class="description"><strong>Ultima importacion:</strong> <?php echo esc_html((string) ($last['imported_at'] ?? '')); ?><?php echo !empty($last['package_id']) ? ' · paquete ' . esc_html((string) $last['package_id']) : ''; ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card" style="max-width:1200px;padding:20px;margin-top:20px;">
            <h2>Identidades portables</h2>
            <table class="widefat striped"><thead><tr><th>Entidad</th><th>Clave portable</th><th>ID numerico</th></tr></thead><tbody>
                <tr><td>Producto</td><td><code>catalog_uid</code> futuro; hoy proveedor+id externo -> SKU unico -> slug unico</td><td>Solo auditoria</td></tr>
                <tr><td>Categoria</td><td><code>taxonomy=product_cat + slug + path_slugs</code></td><td>Solo auditoria</td></tr>
                <tr><td>Vocabulary</td><td><code>semantic_group + slug</code></td><td>Solo auditoria</td></tr>
                <tr><td>product_tag</td><td><code>taxonomy + slug</code></td><td>Solo auditoria</td></tr>
                <tr><td>Atributo</td><td><code>attribute_slug</code></td><td>Solo auditoria</td></tr>
                <tr><td>Termino atributo</td><td><code>attribute_slug + term_slug</code></td><td>Solo auditoria</td></tr>
            </tbody></table>
            <p class="description">Si varias claves portables de un mismo objeto apuntan a IDs locales distintos, la importacion se detiene: no adivina.</p>
        </div>
        <?php
    }

    private static function scope_checkbox($value, $label, $checked) {
        echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="scope[]" value="' . esc_attr($value) . '" ' . checked($checked, true, false) . '> ' . esc_html($label) . '</label>';
    }

    private static function allowed_scopes() {
        return array('masters', 'product_tags', 'product_semantic', 'product_attributes', 'category_semantic', 'category_labels');
    }

    private static function requested_scopes() {
        $raw = isset($_POST['scope']) && is_array($_POST['scope']) ? wp_unslash($_POST['scope']) : array();
        $out = array();
        foreach ($raw as $value) {
            $value = sanitize_key($value);
            if (in_array($value, self::allowed_scopes(), true)) {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }

    public static function export_package() {
        if (!current_user_can(self::capability())) {
            wp_die('Permisos insuficientes.');
        }
        check_admin_referer('seo_semantic_catalog_export');
        $scopes = self::requested_scopes();
        if (!$scopes) {
            wp_die('Selecciona al menos una capa.');
        }
        $document = self::build_document($scopes);
        if (is_wp_error($document)) {
            wp_die(esc_html($document->get_error_message()));
        }
        $json = wp_json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || '' === $json) {
            wp_die('No se pudo generar el paquete semantico.');
        }
        $filename = 'seo-catalogo-semantico-' . current_time('Ymd-His') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    private static function build_document($scopes) {
        $package_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('sem-', true);
        $sections = array();
        if (in_array('masters', $scopes, true)) {
            $masters = self::export_masters();
            if (is_wp_error($masters)) {
                return $masters;
            }
            $sections['masters'] = $masters;
        }
        $product_scopes = array_values(array_intersect($scopes, array('product_tags', 'product_semantic', 'product_attributes')));
        if ($product_scopes) {
            $products = self::export_products($product_scopes);
            if (is_wp_error($products)) {
                return $products;
            }
            $sections['products'] = $products;
        }
        $category_scopes = array_values(array_intersect($scopes, array('category_semantic', 'category_labels')));
        if ($category_scopes) {
            $categories = self::export_categories($category_scopes);
            if (is_wp_error($categories)) {
                return $categories;
            }
            $sections['categories'] = $categories;
        }
        $fingerprints = array();
        foreach ($sections as $key => $section) {
            $fingerprints[$key] = self::portable_digest($section);
        }
        $fingerprints['content'] = self::portable_digest($sections);
        return array(
            'schema' => array('name' => self::SCHEMA_NAME, 'version' => self::SCHEMA_VERSION),
            'manifest' => array(
                'module_version' => self::MODULE_VERSION,
                'plugin_version' => defined('SEO_SYSTEM_VERSION') ? SEO_SYSTEM_VERSION : '',
                'generated_at' => gmdate('c'),
                'source_environment' => self::current_environment(),
                'source_home_url' => home_url('/'),
                'package_id' => $package_id,
                'mode' => 'full',
                'scope' => array_values($scopes),
                'fingerprints' => $fingerprints,
                'counts' => self::section_counts($sections),
                'identity_policy' => 'source_numeric_ids_are_audit_only',
            ),
            'sections' => $sections,
        );
    }

    /**
     * Compose a portable catalog document from already-portable sections.
     * Used by direct PRO -> STAGING transfer so the exact same schema,
     * fingerprints and destination engine are shared with file import/export.
     */
    public static function compose_portable_document($sections, $scopes, $source_environment = '', $source_home_url = '', $package_id = '') {
        $sections = is_array($sections) ? $sections : array();
        $allowed = self::allowed_scopes();
        $clean_scopes = array();
        foreach ((array) $scopes as $scope) {
            $scope = sanitize_key((string) $scope);
            if ($scope && in_array($scope, $allowed, true)) {
                $clean_scopes[] = $scope;
            }
        }
        $clean_scopes = array_values(array_unique($clean_scopes));
        if (!$clean_scopes) {
            return new WP_Error('semantic_catalog_scope', 'No hay capas portables validas para componer el catalogo.');
        }
        if (!$package_id) {
            $package_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('sem-direct-', true);
        }
        $fingerprints = array();
        foreach ($sections as $key => $section) {
            $fingerprints[$key] = self::portable_digest($section);
        }
        $fingerprints['content'] = self::portable_digest($sections);
        return array(
            'schema' => array('name' => self::SCHEMA_NAME, 'version' => self::SCHEMA_VERSION),
            'manifest' => array(
                'module_version' => self::MODULE_VERSION,
                'plugin_version' => defined('SEO_SYSTEM_VERSION') ? SEO_SYSTEM_VERSION : '',
                'generated_at' => gmdate('c'),
                'source_environment' => sanitize_key((string) $source_environment),
                'source_home_url' => (string) $source_home_url,
                'package_id' => (string) $package_id,
                'mode' => 'full',
                'scope' => $clean_scopes,
                'fingerprints' => $fingerprints,
                'counts' => self::section_counts($sections),
                'identity_policy' => 'source_numeric_ids_are_audit_only',
            ),
            'sections' => $sections,
        );
    }

    /**
     * Public safe preflight for an in-memory portable document.
     */
    public static function preview_portable_document($document, $mode = 'mirror', $purge = false) {
        $valid = self::validate_document($document);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $mode = sanitize_key((string) $mode);
        if (!in_array($mode, array('merge', 'mirror'), true)) {
            return new WP_Error('semantic_catalog_mode', 'Modo de importacion no valido.');
        }
        $purge = ('mirror' === $mode && !empty($purge));
        return self::analyze_document($document, $mode, $purge);
    }

    /**
     * Apply an in-memory portable document using the same transactional engine
     * as file import. Optionally requires the destination plan fingerprint from
     * a previous preview, preventing writes after destination drift.
     */
    public static function apply_portable_document($document, $mode = 'mirror', $purge = false, $expected_plan_fingerprint = '') {
        $preview = self::preview_portable_document($document, $mode, $purge);
        if (is_wp_error($preview)) {
            return $preview;
        }
        if (!empty($preview['conflicts'])) {
            return new WP_Error('semantic_catalog_conflicts', 'La simulacion contiene conflictos; no se ha escrito ningun dato.');
        }
        $expected_plan_fingerprint = (string) $expected_plan_fingerprint;
        if ($expected_plan_fingerprint && !hash_equals($expected_plan_fingerprint, (string) ($preview['plan_fingerprint'] ?? ''))) {
            return new WP_Error('semantic_catalog_destination_drift', 'STAGING ha cambiado desde la simulacion. Repite la simulacion antes de aplicar.');
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        $result = self::apply_document($document, $mode, $purge);
        if (is_wp_error($result)) {
            return $result;
        }
        update_option(self::LAST_IMPORT_OPTION, array(
            'package_id' => (string) ($document['manifest']['package_id'] ?? ''),
            'source_environment' => (string) ($document['manifest']['source_environment'] ?? ''),
            'source_home_url' => (string) ($document['manifest']['source_home_url'] ?? ''),
            'mode' => sanitize_key((string) $mode),
            'purge_extra_masters' => !empty($purge) ? 1 : 0,
            'transport' => 'direct_environment_sync',
            'imported_at' => current_time('mysql'),
            'summary' => (array) ($result['summary'] ?? array()),
        ), false);
        self::mark_catalog_drift($document, $result);

        $postflight = self::analyze_document($document, $mode, $purge);
        if (is_wp_error($postflight)) {
            $result['verified'] = false;
            $result['verification_error'] = $postflight->get_error_message();
            return $result;
        }
        $summary = (array) ($postflight['summary'] ?? array());
        $remaining = (int) ($summary['masters_create'] ?? 0)
            + (int) ($summary['masters_update'] ?? 0)
            + (int) ($summary['masters_remove'] ?? 0)
            + (int) ($summary['relationships_add'] ?? 0)
            + (int) ($summary['relationships_remove'] ?? 0)
            + (int) ($summary['conflicts'] ?? 0);
        $result['verified'] = (0 === $remaining);
        $result['verification'] = $postflight;
        return $result;
    }

    private static function current_environment() {
        if (function_exists('seo_clonador_current_env')) {
            $value = sanitize_key((string) seo_clonador_current_env());
            if ($value) {
                return $value;
            }
        }
        if (function_exists('wp_get_environment_type')) {
            return sanitize_key((string) wp_get_environment_type());
        }
        return '';
    }

    private static function required_tables() {
        global $wpdb;
        return array(
            'vocabulary' => $wpdb->prefix . 'seo_vocabulary',
            'object_vocabulary' => $wpdb->prefix . 'seo_object_vocabulary',
            'type_role_map' => $wpdb->prefix . 'seo_type_role_map',
            'attributes' => $wpdb->prefix . 'sql_atributos',
            'attribute_terms' => $wpdb->prefix . 'sql_atributos_terminos',
            'attribute_aliases' => $wpdb->prefix . 'sql_atributos_aliases',
            'product_attributes' => $wpdb->prefix . 'sql_product_atributos',
            'nodes' => $wpdb->prefix . 'seo_nodes',
        );
    }

    private static function export_masters() {
        global $wpdb;
        $t = self::required_tables();
        foreach (array('vocabulary', 'type_role_map', 'attributes', 'attribute_terms', 'attribute_aliases') as $key) {
            if (!self::table_exists($t[$key])) {
                return new WP_Error('semantic_catalog_missing_table', 'Falta la tabla ' . $t[$key] . '.');
            }
        }
        $vocab_rows = (array) $wpdb->get_results("SELECT id,semantic_group,slug,label,parent_id,source,active,created_at,updated_at FROM {$t['vocabulary']} ORDER BY semantic_group,slug,id", ARRAY_A);
        $vocab_by_id = array();
        foreach ($vocab_rows as $row) {
            $vocab_by_id[absint($row['id'])] = $row;
        }
        $vocabulary = array();
        foreach ($vocab_rows as $row) {
            $parent = null;
            $parent_id = absint($row['parent_id'] ?? 0);
            if ($parent_id && isset($vocab_by_id[$parent_id])) {
                $parent = array(
                    'semantic_group' => sanitize_key((string) $vocab_by_id[$parent_id]['semantic_group']),
                    'slug' => sanitize_title((string) $vocab_by_id[$parent_id]['slug']),
                );
            }
            $vocabulary[] = array(
                'key' => array('semantic_group' => sanitize_key((string) $row['semantic_group']), 'slug' => sanitize_title((string) $row['slug'])),
                'label' => (string) $row['label'],
                'parent' => $parent,
                'source' => (string) $row['source'],
                'active' => absint($row['active']) ? 1 : 0,
                'audit' => array('source_id' => absint($row['id']), 'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at']),
            );
        }
        $maps = (array) $wpdb->get_results(
            "SELECT m.id,m.type_vocabulary_id,tv.slug type_slug,m.role_vocabulary_id,rv.slug role_slug,m.confidence,m.source,m.active,m.created_at,m.updated_at
             FROM {$t['type_role_map']} m
             JOIN {$t['vocabulary']} tv ON tv.id=m.type_vocabulary_id AND tv.semantic_group='tipo'
             JOIN {$t['vocabulary']} rv ON rv.id=m.role_vocabulary_id AND rv.semantic_group='rol'
             ORDER BY tv.slug,m.id",
            ARRAY_A
        );
        $type_role_map = array();
        foreach ($maps as $row) {
            $type_role_map[] = array(
                'type' => array('semantic_group' => 'tipo', 'slug' => sanitize_title((string) $row['type_slug'])),
                'role' => array('semantic_group' => 'rol', 'slug' => sanitize_title((string) $row['role_slug'])),
                'confidence' => (string) $row['confidence'],
                'source' => (string) $row['source'],
                'active' => absint($row['active']) ? 1 : 0,
                'audit' => array('source_id' => absint($row['id']), 'type_vocabulary_id' => absint($row['type_vocabulary_id']), 'role_vocabulary_id' => absint($row['role_vocabulary_id']), 'created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at']),
            );
        }
        $attribute_rows = (array) $wpdb->get_results("SELECT * FROM {$t['attributes']} ORDER BY slug,id", ARRAY_A);
        $attributes = array();
        $attribute_by_id = array();
        foreach ($attribute_rows as $row) {
            $attribute_by_id[absint($row['id'])] = sanitize_key((string) $row['slug']);
            $attributes[] = array(
                'key' => array('attribute_slug' => sanitize_key((string) $row['slug'])),
                'name' => (string) $row['nombre'],
                'group' => (string) $row['grupo'],
                'type' => (string) $row['tipo'],
                'unit_type' => (string) $row['unidad_tipo'],
                'base_unit' => (string) $row['unidad_base'],
                'multiple' => absint($row['multiple']),
                'filterable' => absint($row['filtrable']),
                'visible' => absint($row['visible']),
                'seo' => absint($row['seo']),
                'sort_order' => (int) $row['orden'],
                'active' => absint($row['activo']) ? 1 : 0,
                'audit' => array('source_id' => absint($row['id']), 'created_at' => (string) ($row['created_at'] ?? ''), 'updated_at' => (string) ($row['updated_at'] ?? '')),
            );
        }
        $term_rows = (array) $wpdb->get_results("SELECT * FROM {$t['attribute_terms']} ORDER BY atributo_id,slug,id", ARRAY_A);
        $terms = array();
        $term_by_id = array();
        foreach ($term_rows as $row) {
            $attribute_slug = $attribute_by_id[absint($row['atributo_id'])] ?? '';
            if (!$attribute_slug) {
                continue;
            }
            $term_by_id[absint($row['id'])] = array('attribute_slug' => $attribute_slug, 'term_slug' => sanitize_title((string) $row['slug']));
            $terms[] = array(
                'key' => array('attribute_slug' => $attribute_slug, 'term_slug' => sanitize_title((string) $row['slug'])),
                'name' => (string) $row['nombre'],
                'sort_order' => (int) $row['orden'],
                'active' => absint($row['activo']) ? 1 : 0,
                'audit' => array('source_id' => absint($row['id']), 'attribute_id' => absint($row['atributo_id'])),
            );
        }
        $alias_rows = (array) $wpdb->get_results("SELECT id,atributo_id,termino_id,alias FROM {$t['attribute_aliases']} ORDER BY atributo_id,alias,id", ARRAY_A);
        $alias_groups = array();
        foreach ($alias_rows as $row) {
            $attribute_slug = $attribute_by_id[absint($row['atributo_id'])] ?? '';
            if (!$attribute_slug) {
                continue;
            }
            $key = $attribute_slug . '|' . mb_strtolower((string) $row['alias'], 'UTF-8');
            if (!isset($alias_groups[$key])) {
                $alias_groups[$key] = array(
                    'key' => array('attribute_slug' => $attribute_slug, 'alias' => (string) $row['alias']),
                    'term_slugs' => array(),
                    'audit' => array('source_ids' => array()),
                );
            }
            $term_id = absint($row['termino_id'] ?? 0);
            if ($term_id && isset($term_by_id[$term_id])) {
                $alias_groups[$key]['term_slugs'][] = $term_by_id[$term_id]['term_slug'];
            } else {
                $alias_groups[$key]['term_slugs'][] = '';
            }
            $alias_groups[$key]['audit']['source_ids'][] = absint($row['id']);
        }
        $aliases = array_values($alias_groups);
        foreach ($aliases as &$row) {
            $row['term_slugs'] = array_values(array_unique($row['term_slugs']));
            sort($row['term_slugs'], SORT_STRING);
        }
        unset($row);
        $product_tags = array();
        if (taxonomy_exists('product_tag')) {
            $tag_rows = (array) $wpdb->get_results(
                "SELECT t.term_id,t.name,t.slug,tt.description,tt.count
                 FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
                 WHERE tt.taxonomy='product_tag' ORDER BY t.slug,t.term_id",
                ARRAY_A
            );
            foreach ($tag_rows as $row) {
                $product_tags[] = array(
                    'key' => array('taxonomy' => 'product_tag', 'slug' => sanitize_title((string) $row['slug'])),
                    'name' => (string) $row['name'],
                    'description' => (string) $row['description'],
                    'audit' => array('source_term_id' => absint($row['term_id']), 'count' => absint($row['count'])),
                );
            }
        }
        return array(
            'semantic_vocabulary' => $vocabulary,
            'type_role_map' => $type_role_map,
            'attributes' => $attributes,
            'attribute_terms' => $terms,
            'attribute_aliases' => $aliases,
            'product_tags' => $product_tags,
        );
    }

    private static function export_products($scopes) {
        global $wpdb;
        $t = self::required_tables();
        if (in_array('product_semantic', $scopes, true) && (!self::table_exists($t['vocabulary']) || !self::table_exists($t['object_vocabulary']))) {
            return new WP_Error('semantic_catalog_missing_table', 'Faltan tablas de Vocabulary para productos.');
        }
        if (in_array('product_attributes', $scopes, true) && (!self::table_exists($t['attributes']) || !self::table_exists($t['attribute_terms']) || !self::table_exists($t['product_attributes']))) {
            return new WP_Error('semantic_catalog_missing_table', 'Faltan tablas de atributos canonicos.');
        }
        $rows = (array) $wpdb->get_results(
            "SELECT p.ID,p.post_name,p.post_title,
                    MAX(CASE WHEN pm.meta_key='_seo_catalog_uid' THEN pm.meta_value END) catalog_uid,
                    MAX(CASE WHEN pm.meta_key='_seo_proveedor' THEN pm.meta_value END) provider,
                    MAX(CASE WHEN pm.meta_key='_seo_proveedor_id_externo' THEN pm.meta_value END) external_id,
                    MAX(CASE WHEN pm.meta_key='_sku' THEN pm.meta_value END) sku
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key IN ('_seo_catalog_uid','_seo_proveedor','_seo_proveedor_id_externo','_sku')
             WHERE p.post_type='product' AND p.post_status<>'trash'
             GROUP BY p.ID,p.post_name,p.post_title
             ORDER BY p.ID",
            ARRAY_A
        );
        $ids = array_values(array_filter(array_map('absint', wp_list_pluck($rows, 'ID'))));
        $tag_map = array();
        if ($ids && in_array('product_tags', $scopes, true)) {
            foreach (array_chunk($ids, 2000) as $chunk) {
                $id_sql = implode(',', array_map('absint', $chunk));
                $tag_rows = (array) $wpdb->get_results(
                    "SELECT tr.object_id,t.slug
                     FROM {$wpdb->term_relationships} tr
                     JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_tag'
                     JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                     WHERE tr.object_id IN ({$id_sql}) ORDER BY tr.object_id,t.slug",
                    ARRAY_A
                );
                foreach ($tag_rows as $tag) {
                    $pid = absint($tag['object_id']);
                    $tag_map[$pid][] = sanitize_title((string) $tag['slug']);
                }
            }
        }
        $semantic_map = array();
        if ($ids && in_array('product_semantic', $scopes, true)) {
            foreach (array_chunk($ids, 2000) as $chunk) {
                $id_sql = implode(',', array_map('absint', $chunk));
                $semantic_rows = (array) $wpdb->get_results(
                    "SELECT ov.object_id,ov.source,ov.confidence,v.semantic_group,v.slug
                     FROM {$t['object_vocabulary']} ov
                     JOIN {$t['vocabulary']} v ON v.id=ov.vocabulary_id
                     WHERE ov.object_type='product' AND ov.status=1 AND v.active=1 AND ov.object_id IN ({$id_sql})
                     ORDER BY ov.object_id,v.semantic_group,v.slug",
                    ARRAY_A
                );
                foreach ($semantic_rows as $sem) {
                    $pid = absint($sem['object_id']);
                    $semantic_map[$pid][] = array(
                        'key' => array('semantic_group' => sanitize_key((string) $sem['semantic_group']), 'slug' => sanitize_title((string) $sem['slug'])),
                        'source' => (string) $sem['source'],
                        'confidence' => (string) $sem['confidence'],
                    );
                }
            }
        }
        $attribute_map = array();
        if ($ids && in_array('product_attributes', $scopes, true)) {
            foreach (array_chunk($ids, 1200) as $chunk) {
                $id_sql = implode(',', array_map('absint', $chunk));
                $attribute_rows = (array) $wpdb->get_results(
                    "SELECT pa.id,pa.product_id,a.slug attribute_slug,t.slug term_slug,
                            pa.valor_texto,pa.valor_numero,pa.valor_numero_max,pa.unidad,pa.valor_original,pa.orden
                     FROM {$t['product_attributes']} pa
                     JOIN {$t['attributes']} a ON a.id=pa.atributo_id
                     LEFT JOIN {$t['attribute_terms']} t ON t.id=pa.termino_id
                     WHERE pa.product_id IN ({$id_sql})
                     ORDER BY pa.product_id,a.slug,pa.orden,pa.id",
                    ARRAY_A
                );
                foreach ($attribute_rows as $attr) {
                    $pid = absint($attr['product_id']);
                    $attribute_map[$pid][] = array(
                        'attribute_slug' => sanitize_key((string) $attr['attribute_slug']),
                        'term_slug' => sanitize_title((string) ($attr['term_slug'] ?? '')),
                        'value_text' => null === $attr['valor_texto'] ? null : (string) $attr['valor_texto'],
                        'value_number' => null === $attr['valor_numero'] ? null : (string) $attr['valor_numero'],
                        'value_number_max' => null === $attr['valor_numero_max'] ? null : (string) $attr['valor_numero_max'],
                        'unit' => null === $attr['unidad'] ? null : (string) $attr['unidad'],
                        'original_value' => null === $attr['valor_original'] ? null : (string) $attr['valor_original'],
                        'sort_order' => (int) $attr['orden'],
                        'audit' => array('source_id' => absint($attr['id'])),
                    );
                }
            }
        }
        $products = array();
        foreach ($rows as $row) {
            $pid = absint($row['ID']);
            $entry = array(
                'identity' => array(
                    'catalog_uid' => trim((string) $row['catalog_uid']),
                    'provider' => trim((string) $row['provider']),
                    'external_id' => trim((string) $row['external_id']),
                    'sku' => trim((string) $row['sku']),
                    'slug' => sanitize_title((string) $row['post_name']),
                ),
                'display_name' => (string) $row['post_title'],
                'audit' => array('source_id' => $pid),
            );
            if (in_array('product_tags', $scopes, true)) {
                $tags = array_values(array_unique($tag_map[$pid] ?? array()));
                sort($tags, SORT_STRING);
                $entry['product_tags'] = $tags;
            }
            if (in_array('product_semantic', $scopes, true)) {
                $entry['semantic'] = array_values($semantic_map[$pid] ?? array());
            }
            if (in_array('product_attributes', $scopes, true)) {
                $entry['attributes'] = array_values($attribute_map[$pid] ?? array());
            }
            $products[] = $entry;
        }
        usort($products, static function ($a, $b) {
            return strcmp(self::product_identity_string($a['identity'] ?? array()), self::product_identity_string($b['identity'] ?? array()));
        });
        return $products;
    }

    private static function export_categories($scopes) {
        global $wpdb;
        $t = self::required_tables();
        $rows = (array) $wpdb->get_results(
            "SELECT t.term_id,t.name,t.slug,tt.parent
             FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
             WHERE tt.taxonomy='product_cat' ORDER BY t.term_id",
            ARRAY_A
        );
        $by_id = array();
        foreach ($rows as $row) {
            $by_id[absint($row['term_id'])] = $row;
        }
        $ids = array_keys($by_id);
        $semantic_map = array();
        if ($ids && in_array('category_semantic', $scopes, true) && self::table_exists($t['object_vocabulary']) && self::table_exists($t['vocabulary'])) {
            foreach (array_chunk($ids, 2000) as $chunk) {
                $id_sql = implode(',', array_map('absint', $chunk));
                $sem_rows = (array) $wpdb->get_results(
                    "SELECT ov.object_id,ov.source,ov.confidence,v.semantic_group,v.slug
                     FROM {$t['object_vocabulary']} ov
                     JOIN {$t['vocabulary']} v ON v.id=ov.vocabulary_id
                     WHERE ov.object_type='product_cat' AND ov.status=1 AND v.active=1 AND ov.object_id IN ({$id_sql})
                     ORDER BY ov.object_id,v.semantic_group,v.slug",
                    ARRAY_A
                );
                foreach ($sem_rows as $sem) {
                    $cid = absint($sem['object_id']);
                    $semantic_map[$cid][] = array(
                        'key' => array('semantic_group' => sanitize_key((string) $sem['semantic_group']), 'slug' => sanitize_title((string) $sem['slug'])),
                        'source' => (string) $sem['source'],
                        'confidence' => (string) $sem['confidence'],
                    );
                }
            }
        }
        $label_map = array();
        if ($ids && in_array('category_labels', $scopes, true) && self::table_exists($t['nodes'])) {
            foreach (array_chunk($ids, 2000) as $chunk) {
                $id_sql = implode(',', array_map('absint', $chunk));
                $node_rows = (array) $wpdb->get_results(
                    "SELECT id,object_id,keywords,title,status,created_at,updated_at
                     FROM {$t['nodes']}
                     WHERE object_type='category' AND seo_role='category' AND object_id IN ({$id_sql})
                     ORDER BY object_id,id",
                    ARRAY_A
                );
                foreach ($node_rows as $node) {
                    $cid = absint($node['object_id']);
                    $label_map[$cid] = array(
                        'keywords' => (string) $node['keywords'],
                        'title' => (string) $node['title'],
                        'status' => absint($node['status']) ? 1 : 0,
                        'audit' => array('source_id' => absint($node['id']), 'created_at' => (string) $node['created_at'], 'updated_at' => (string) $node['updated_at']),
                    );
                }
            }
        }
        $out = array();
        foreach ($rows as $row) {
            $cid = absint($row['term_id']);
            $entry = array(
                'identity' => array(
                    'taxonomy' => 'product_cat',
                    'slug' => sanitize_title((string) $row['slug']),
                    'path_slugs' => self::category_path_slugs($cid, $by_id),
                ),
                'display_name' => (string) $row['name'],
                'audit' => array('source_term_id' => $cid),
            );
            if (in_array('category_semantic', $scopes, true)) {
                $entry['semantic'] = array_values($semantic_map[$cid] ?? array());
            }
            if (in_array('category_labels', $scopes, true)) {
                $entry['category_labels'] = $label_map[$cid] ?? null;
            }
            $out[] = $entry;
        }
        usort($out, static function ($a, $b) {
            return strcmp(implode('/', (array) ($a['identity']['path_slugs'] ?? array())), implode('/', (array) ($b['identity']['path_slugs'] ?? array())));
        });
        return $out;
    }

    private static function category_path_slugs($term_id, $by_id) {
        $path = array();
        $seen = array();
        $current = absint($term_id);
        while ($current && isset($by_id[$current]) && empty($seen[$current])) {
            $seen[$current] = true;
            array_unshift($path, sanitize_title((string) $by_id[$current]['slug']));
            $current = absint($by_id[$current]['parent'] ?? 0);
        }
        return $path;
    }

    private static function product_identity_string($identity) {
        $identity = is_array($identity) ? $identity : array();
        foreach (array('catalog_uid', 'sku', 'slug') as $key) {
            $value = trim((string) ($identity[$key] ?? ''));
            if ('' !== $value) {
                return $key . '|' . mb_strtolower($value, 'UTF-8');
            }
        }
        $provider = trim((string) ($identity['provider'] ?? ''));
        $external = trim((string) ($identity['external_id'] ?? ''));
        if ('' !== $provider || '' !== $external) {
            return 'provider|' . mb_strtolower($provider, 'UTF-8') . '|external|' . mb_strtolower($external, 'UTF-8');
        }
        return '';
    }

    private static function section_counts($sections) {
        $counts = array();
        if (isset($sections['masters'])) {
            foreach ((array) $sections['masters'] as $key => $rows) {
                $counts['masters.' . $key] = is_array($rows) ? count($rows) : 0;
            }
        }
        if (isset($sections['products'])) {
            $counts['products'] = count((array) $sections['products']);
        }
        if (isset($sections['categories'])) {
            $counts['categories'] = count((array) $sections['categories']);
        }
        return $counts;
    }

    private static function portable_digest($value) {
        return hash('sha256', wp_json_encode(self::portable_value($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function portable_value($value) {
        if (!is_array($value)) {
            return $value;
        }
        if (self::is_assoc($value)) {
            $out = array();
            $keys = array_keys($value);
            sort($keys, SORT_STRING);
            foreach ($keys as $key) {
                if ('audit' === $key) {
                    continue;
                }
                $out[$key] = self::portable_value($value[$key]);
            }
            return $out;
        }
        $out = array();
        foreach ($value as $child) {
            $out[] = self::portable_value($child);
        }
        usort($out, static function ($a, $b) {
            $ja = wp_json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $jb = wp_json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return strcmp((string) $ja, (string) $jb);
        });
        return $out;
    }

    private static function is_assoc($array) {
        if (!is_array($array) || array() === $array) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    public static function import_package() {
        if (!current_user_can(self::capability())) {
            wp_die('Permisos insuficientes.');
        }
        check_admin_referer('seo_semantic_catalog_import');
        $intent = sanitize_key((string) ($_POST['semantic_catalog_intent'] ?? 'preview'));
        $mode = sanitize_key((string) ($_POST['import_mode'] ?? 'mirror'));
        if (!in_array($mode, array('merge', 'mirror'), true)) {
            $mode = 'mirror';
        }
        $purge = ('mirror' === $mode && !empty($_POST['purge_extra_masters']));
        $loaded = self::load_uploaded_document();
        if (is_wp_error($loaded)) {
            self::redirect_notice(true, $loaded->get_error_message());
        }
        $document = $loaded['document'];
        $package_hash = $loaded['hash'];
        $valid = self::validate_document($document);
        if (is_wp_error($valid)) {
            self::redirect_notice(true, $valid->get_error_message());
        }
        $preview = self::analyze_document($document, $mode, $purge);
        if (is_wp_error($preview)) {
            self::redirect_notice(true, $preview->get_error_message());
        }
        if ('preview' === $intent) {
            set_transient(self::PREVIEW_PREFIX . get_current_user_id(), array(
                'hash' => $package_hash,
                'mode' => $mode,
                'purge' => $purge ? 1 : 0,
                'package_id' => (string) ($document['manifest']['package_id'] ?? ''),
                'plan_fingerprint' => (string) ($preview['plan_fingerprint'] ?? ''),
                'previewed_at' => time(),
            ), self::PREVIEW_TTL);
            self::redirect_notice(
                !empty($preview['conflicts']),
                !empty($preview['conflicts']) ? 'Simulacion terminada con conflictos. No se puede aplicar hasta resolverlos.' : 'Simulacion correcta. El paquete puede aplicarse.',
                $preview['summary'],
                $preview['conflicts']
            );
        }
        if ('apply' !== $intent) {
            self::redirect_notice(true, 'Accion de importacion no valida.');
        }
        if (empty($_POST['confirm_apply'])) {
            self::redirect_notice(true, 'Debes confirmar explicitamente la aplicacion del paquete.');
        }
        $last_preview = get_transient(self::PREVIEW_PREFIX . get_current_user_id());
        if (!is_array($last_preview)
            || !hash_equals((string) ($last_preview['hash'] ?? ''), $package_hash)
            || (string) ($last_preview['mode'] ?? '') !== $mode
            || absint($last_preview['purge'] ?? 0) !== ($purge ? 1 : 0)
            || !hash_equals((string) ($last_preview['plan_fingerprint'] ?? ''), (string) ($preview['plan_fingerprint'] ?? ''))) {
            self::redirect_notice(true, 'Primero debes simular exactamente este mismo archivo con el mismo modo/opciones y sin cambios posteriores en el destino.');
        }
        if (!empty($preview['conflicts'])) {
            self::redirect_notice(true, 'La aplicacion se ha bloqueado porque la simulacion contiene conflictos.', $preview['summary'], $preview['conflicts']);
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        $result = self::apply_document($document, $mode, $purge);
        if (is_wp_error($result)) {
            self::redirect_notice(true, $result->get_error_message());
        }
        delete_transient(self::PREVIEW_PREFIX . get_current_user_id());
        update_option(self::LAST_IMPORT_OPTION, array(
            'package_id' => (string) ($document['manifest']['package_id'] ?? ''),
            'source_environment' => (string) ($document['manifest']['source_environment'] ?? ''),
            'source_home_url' => (string) ($document['manifest']['source_home_url'] ?? ''),
            'mode' => $mode,
            'purge_extra_masters' => $purge ? 1 : 0,
            'imported_at' => current_time('mysql'),
            'summary' => $result['summary'],
        ), false);
        self::mark_catalog_drift($document, $result);
        self::redirect_notice(false, 'Catalogo semantico importado correctamente. Reindexa Dependiente desde la base local antes de continuar aprendizaje nuevo.', $result['summary']);
    }

    private static function load_uploaded_document() {
        if (empty($_FILES['semantic_catalog_file']) || !is_array($_FILES['semantic_catalog_file'])) {
            return new WP_Error('semantic_catalog_no_file', 'No se ha recibido ningun archivo.');
        }
        $file = $_FILES['semantic_catalog_file'];
        $error = absint($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (UPLOAD_ERR_OK !== $error) {
            return new WP_Error('semantic_catalog_upload', 'La subida ha fallado (codigo ' . $error . ').');
        }
        $size = absint($file['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_IMPORT_BYTES) {
            return new WP_Error('semantic_catalog_size', 'El archivo esta vacio o supera 256 MiB.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!$tmp || !is_uploaded_file($tmp) || !is_readable($tmp)) {
            return new WP_Error('semantic_catalog_tmp', 'No se puede leer el archivo subido.');
        }
        $raw = file_get_contents($tmp);
        if (!is_string($raw) || '' === $raw) {
            return new WP_Error('semantic_catalog_read', 'No se pudo leer el archivo.');
        }
        $name = strtolower((string) ($file['name'] ?? ''));
        if (substr($name, -3) === '.gz' || (strlen($raw) > 2 && ord($raw[0]) === 31 && ord($raw[1]) === 139)) {
            if (!function_exists('gzdecode')) {
                return new WP_Error('semantic_catalog_gzip', 'El servidor no puede descomprimir gzip.');
            }
            $decoded = gzdecode($raw);
            if (!is_string($decoded) || '' === $decoded) {
                return new WP_Error('semantic_catalog_gzip', 'El gzip no se pudo descomprimir.');
            }
            $raw = $decoded;
        }
        $document = json_decode($raw, true);
        if (!is_array($document)) {
            return new WP_Error('semantic_catalog_json', 'El JSON no es valido.');
        }
        return array('document' => $document, 'hash' => hash('sha256', $raw));
    }

    private static function validate_document($document) {
        if (!is_array($document)) {
            return new WP_Error('semantic_catalog_schema', 'Documento no valido.');
        }
        $schema = isset($document['schema']) && is_array($document['schema']) ? $document['schema'] : array();
        if (self::SCHEMA_NAME !== (string) ($schema['name'] ?? '')) {
            return new WP_Error('semantic_catalog_schema', 'El archivo no es un catalogo semantico portable.');
        }
        if (self::SCHEMA_VERSION !== absint($schema['version'] ?? 0)) {
            return new WP_Error('semantic_catalog_version', 'Version de formato no compatible.');
        }
        $manifest = isset($document['manifest']) && is_array($document['manifest']) ? $document['manifest'] : array();
        $sections = isset($document['sections']) && is_array($document['sections']) ? $document['sections'] : null;
        if (!is_array($sections)) {
            return new WP_Error('semantic_catalog_sections', 'Falta el bloque sections.');
        }
        $scopes = isset($manifest['scope']) && is_array($manifest['scope']) ? array_map('sanitize_key', $manifest['scope']) : array();
        foreach ($scopes as $scope) {
            if (!in_array($scope, self::allowed_scopes(), true)) {
                return new WP_Error('semantic_catalog_scope', 'El paquete contiene una capa no soportada: ' . $scope . '.');
            }
        }
        $expected = (string) ($manifest['fingerprints']['content'] ?? '');
        if (!$expected || !hash_equals($expected, self::portable_digest($sections))) {
            return new WP_Error('semantic_catalog_fingerprint', 'La huella portable del contenido no coincide. El archivo puede estar incompleto o modificado.');
        }
        foreach (array('masters', 'products', 'categories') as $key) {
            if (!isset($sections[$key])) {
                continue;
            }
            $section_expected = (string) ($manifest['fingerprints'][$key] ?? '');
            if (!$section_expected || !hash_equals($section_expected, self::portable_digest($sections[$key]))) {
                return new WP_Error('semantic_catalog_fingerprint', 'La huella de la seccion ' . $key . ' no coincide.');
            }
        }
        return true;
    }

    private static function blank_summary() {
        return array(
            'masters_create' => 0,
            'masters_update' => 0,
            'masters_remove' => 0,
            'objects_found' => 0,
            'objects_missing' => 0,
            'objects_ambiguous' => 0,
            'relationships_add' => 0,
            'relationships_remove' => 0,
            'objects_changed' => 0,
            'objects_unchanged' => 0,
            'products_changed' => 0,
            'categories_changed' => 0,
            'conflicts' => 0,
        );
    }

    private static function add_conflict(&$state, $message, $kind) {
        $state['conflicts'][] = (string) $message;
        $state['summary']['conflicts']++;
        if ('missing' === $kind) {
            $state['summary']['objects_missing']++;
        } elseif ('ambiguous' === $kind) {
            $state['summary']['objects_ambiguous']++;
        }
    }

    private static function analyze_document($document, $mode, $purge) {
        $state = array('summary' => self::blank_summary(), 'conflicts' => array(), 'plan' => array());
        $sections = (array) ($document['sections'] ?? array());
        $scope = array_map('sanitize_key', (array) ($document['manifest']['scope'] ?? array()));
        $destination_ok = self::validate_destination_for_scope($scope);
        if (is_wp_error($destination_ok)) {
            return $destination_ok;
        }
        // Cada simulacion debe reflejar el estado real de destino, incluso si
        // en esta misma peticion se acaba de aplicar un MIRROR.
        self::local_master_index(true);
        $package_masters = self::package_master_index((array) ($sections['masters'] ?? array()), $state);
        if (in_array('masters', $scope, true)) {
            self::analyze_masters((array) ($sections['masters'] ?? array()), $package_masters, $mode, $purge, $state, $scope);
        }
        $local_products = null;
        if (isset($sections['products'])) {
            $local_products = self::local_product_index();
            foreach ((array) $sections['products'] as $source) {
                $resolved = self::resolve_product($source['identity'] ?? array(), $local_products);
                if (is_wp_error($resolved)) {
                    self::add_conflict($state, 'Producto ' . self::source_object_label($source) . ': ' . $resolved->get_error_message(), $resolved->get_error_code());
                    continue;
                }
                $state['summary']['objects_found']++;
                self::analyze_product_assignments($resolved, $source, $scope, $mode, $package_masters, $state);
            }
            if ('mirror' === $mode && 'full' === (string) ($document['manifest']['mode'] ?? '')) {
                self::analyze_extra_products((array) $sections['products'], $local_products, $state);
            }
        }
        if (isset($sections['categories'])) {
            $local_categories = self::local_category_index();
            foreach ((array) $sections['categories'] as $source) {
                $resolved = self::resolve_category($source['identity'] ?? array(), $local_categories);
                if (is_wp_error($resolved)) {
                    self::add_conflict($state, 'Categoria ' . self::source_object_label($source) . ': ' . $resolved->get_error_message(), $resolved->get_error_code());
                    continue;
                }
                $state['summary']['objects_found']++;
                self::analyze_category_assignments($resolved, $source, $scope, $mode, $package_masters, $state);
            }
            if ('mirror' === $mode && 'full' === (string) ($document['manifest']['mode'] ?? '')) {
                self::analyze_extra_categories((array) $sections['categories'], $local_categories, $state);
            }
        }
        $state['plan_fingerprint'] = self::portable_digest($state['plan']);
        unset($state['plan']);
        return $state;
    }

    private static function validate_destination_for_scope($scope) {
        $t = self::required_tables();
        $required = array();
        if (in_array('masters', $scope, true)) {
            $required = array_merge($required, array('vocabulary', 'type_role_map', 'attributes', 'attribute_terms', 'attribute_aliases'));
            if (!taxonomy_exists('product_tag')) {
                return new WP_Error('semantic_catalog_destination', 'La taxonomia product_tag no esta registrada en el destino.');
            }
        }
        if (in_array('product_semantic', $scope, true) || in_array('category_semantic', $scope, true)) {
            $required = array_merge($required, array('vocabulary', 'object_vocabulary'));
        }
        if (in_array('product_attributes', $scope, true)) {
            $required = array_merge($required, array('attributes', 'attribute_terms', 'product_attributes'));
        }
        if (in_array('category_labels', $scope, true)) {
            $required[] = 'nodes';
        }
        if (in_array('product_tags', $scope, true) && !taxonomy_exists('product_tag')) {
            return new WP_Error('semantic_catalog_destination', 'La taxonomia product_tag no esta registrada en el destino.');
        }
        foreach (array_values(array_unique($required)) as $key) {
            if (empty($t[$key]) || !self::table_exists($t[$key])) {
                return new WP_Error('semantic_catalog_destination', 'Falta la tabla requerida en destino: ' . ($t[$key] ?? $key) . '.');
            }
        }
        return true;
    }

    private static function master_key($group, $slug) {
        return sanitize_key((string) $group) . '|' . sanitize_title((string) $slug);
    }

    private static function attribute_key($slug) {
        return sanitize_key((string) $slug);
    }

    private static function attribute_term_key($attribute_slug, $term_slug) {
        return self::attribute_key($attribute_slug) . '|' . sanitize_title((string) $term_slug);
    }

    private static function alias_key($attribute_slug, $alias) {
        return self::attribute_key($attribute_slug) . '|' . mb_strtolower(trim((string) $alias), 'UTF-8');
    }

    private static function package_master_index($masters, &$state) {
        $index = array(
            'vocabulary' => array(),
            'type_role_map' => array(),
            'attributes' => array(),
            'attribute_terms' => array(),
            'attribute_aliases' => array(),
            'product_tags' => array(),
        );
        $lists = array(
            'semantic_vocabulary' => 'vocabulary',
            'type_role_map' => 'type_role_map',
            'attributes' => 'attributes',
            'attribute_terms' => 'attribute_terms',
            'attribute_aliases' => 'attribute_aliases',
            'product_tags' => 'product_tags',
        );
        foreach ($lists as $source_key => $target_key) {
            foreach ((array) ($masters[$source_key] ?? array()) as $row) {
                if ('vocabulary' === $target_key) {
                    $key = self::master_key($row['key']['semantic_group'] ?? '', $row['key']['slug'] ?? '');
                } elseif ('type_role_map' === $target_key) {
                    $key = sanitize_title((string) ($row['type']['slug'] ?? ''));
                } elseif ('attributes' === $target_key) {
                    $key = self::attribute_key($row['key']['attribute_slug'] ?? '');
                } elseif ('attribute_terms' === $target_key) {
                    $key = self::attribute_term_key($row['key']['attribute_slug'] ?? '', $row['key']['term_slug'] ?? '');
                } elseif ('attribute_aliases' === $target_key) {
                    $key = self::alias_key($row['key']['attribute_slug'] ?? '', $row['key']['alias'] ?? '');
                } else {
                    $key = sanitize_title((string) ($row['key']['slug'] ?? ''));
                }
                if ('' === trim($key, '|')) {
                    self::add_conflict($state, 'Maestro con clave portable vacia en ' . $source_key . '.', 'ambiguous');
                    continue;
                }
                if (isset($index[$target_key][$key]) && self::portable_digest($index[$target_key][$key]) !== self::portable_digest($row)) {
                    self::add_conflict($state, 'Clave portable duplicada y contradictoria en ' . $source_key . ': ' . $key . '.', 'ambiguous');
                    continue;
                }
                $index[$target_key][$key] = $row;
            }
        }
        return $index;
    }

    private static function local_master_index($refresh = false) {
        global $wpdb;
        static $cache = null;
        if (!$refresh && is_array($cache)) {
            return $cache;
        }
        $t = self::required_tables();
        $out = array(
            'vocabulary' => array(),
            'type_role_map' => array(),
            'attributes' => array(),
            'attribute_terms' => array(),
            'attribute_aliases' => array(),
            'product_tags' => array(),
        );
        if (self::table_exists($t['vocabulary'])) {
            $rows = (array) $wpdb->get_results("SELECT id,semantic_group,slug,label,parent_id,source,active FROM {$t['vocabulary']} ORDER BY id", ARRAY_A);
            $by_id = array();
            foreach ($rows as $row) {
                $by_id[absint($row['id'])] = $row;
            }
            foreach ($rows as $row) {
                $parent = null;
                $parent_id = absint($row['parent_id'] ?? 0);
                if ($parent_id && isset($by_id[$parent_id])) {
                    $parent = array(
                        'semantic_group' => sanitize_key((string) $by_id[$parent_id]['semantic_group']),
                        'slug' => sanitize_title((string) $by_id[$parent_id]['slug']),
                    );
                }
                $portable = array(
                    'key' => array('semantic_group' => sanitize_key((string) $row['semantic_group']), 'slug' => sanitize_title((string) $row['slug'])),
                    'label' => (string) $row['label'],
                    'parent' => $parent,
                    'source' => (string) $row['source'],
                    'active' => absint($row['active']) ? 1 : 0,
                );
                $out['vocabulary'][self::master_key($row['semantic_group'], $row['slug'])] = array('id' => absint($row['id']), 'portable' => $portable, 'raw' => $row);
            }
        }
        if (self::table_exists($t['type_role_map']) && self::table_exists($t['vocabulary'])) {
            $rows = (array) $wpdb->get_results(
                "SELECT m.id,m.type_vocabulary_id,tv.slug type_slug,m.role_vocabulary_id,rv.slug role_slug,m.confidence,m.source,m.active
                 FROM {$t['type_role_map']} m
                 JOIN {$t['vocabulary']} tv ON tv.id=m.type_vocabulary_id AND tv.semantic_group='tipo'
                 JOIN {$t['vocabulary']} rv ON rv.id=m.role_vocabulary_id AND rv.semantic_group='rol'",
                ARRAY_A
            );
            foreach ($rows as $row) {
                $portable = array(
                    'type' => array('semantic_group' => 'tipo', 'slug' => sanitize_title((string) $row['type_slug'])),
                    'role' => array('semantic_group' => 'rol', 'slug' => sanitize_title((string) $row['role_slug'])),
                    'confidence' => (string) $row['confidence'],
                    'source' => (string) $row['source'],
                    'active' => absint($row['active']) ? 1 : 0,
                );
                $out['type_role_map'][sanitize_title((string) $row['type_slug'])] = array('id' => absint($row['id']), 'portable' => $portable, 'raw' => $row);
            }
        }
        if (self::table_exists($t['attributes'])) {
            $rows = (array) $wpdb->get_results("SELECT * FROM {$t['attributes']} ORDER BY id", ARRAY_A);
            $attr_by_id = array();
            foreach ($rows as $row) {
                $slug = self::attribute_key($row['slug']);
                $attr_by_id[absint($row['id'])] = $slug;
                $portable = array(
                    'key' => array('attribute_slug' => $slug),
                    'name' => (string) $row['nombre'],
                    'group' => (string) $row['grupo'],
                    'type' => (string) $row['tipo'],
                    'unit_type' => (string) $row['unidad_tipo'],
                    'base_unit' => (string) $row['unidad_base'],
                    'multiple' => absint($row['multiple']),
                    'filterable' => absint($row['filtrable']),
                    'visible' => absint($row['visible']),
                    'seo' => absint($row['seo']),
                    'sort_order' => (int) $row['orden'],
                    'active' => absint($row['activo']) ? 1 : 0,
                );
                $out['attributes'][$slug] = array('id' => absint($row['id']), 'portable' => $portable, 'raw' => $row);
            }
            if (self::table_exists($t['attribute_terms'])) {
                $term_rows = (array) $wpdb->get_results("SELECT * FROM {$t['attribute_terms']} ORDER BY id", ARRAY_A);
                $term_by_id = array();
                foreach ($term_rows as $row) {
                    $attr_slug = $attr_by_id[absint($row['atributo_id'])] ?? '';
                    if (!$attr_slug) {
                        continue;
                    }
                    $term_slug = sanitize_title((string) $row['slug']);
                    $key = self::attribute_term_key($attr_slug, $term_slug);
                    $term_by_id[absint($row['id'])] = array($attr_slug, $term_slug);
                    $portable = array(
                        'key' => array('attribute_slug' => $attr_slug, 'term_slug' => $term_slug),
                        'name' => (string) $row['nombre'],
                        'sort_order' => (int) $row['orden'],
                        'active' => absint($row['activo']) ? 1 : 0,
                    );
                    $out['attribute_terms'][$key] = array('id' => absint($row['id']), 'portable' => $portable, 'raw' => $row);
                }
                if (self::table_exists($t['attribute_aliases'])) {
                    $alias_rows = (array) $wpdb->get_results("SELECT id,atributo_id,termino_id,alias FROM {$t['attribute_aliases']} ORDER BY id", ARRAY_A);
                    foreach ($alias_rows as $row) {
                        $attr_slug = $attr_by_id[absint($row['atributo_id'])] ?? '';
                        if (!$attr_slug) {
                            continue;
                        }
                        $key = self::alias_key($attr_slug, $row['alias']);
                        if (!isset($out['attribute_aliases'][$key])) {
                            $out['attribute_aliases'][$key] = array(
                                'ids' => array(),
                                'portable' => array(
                                    'key' => array('attribute_slug' => $attr_slug, 'alias' => (string) $row['alias']),
                                    'term_slugs' => array(),
                                ),
                                'raw' => array(),
                            );
                        }
                        $out['attribute_aliases'][$key]['ids'][] = absint($row['id']);
                        $out['attribute_aliases'][$key]['raw'][] = $row;
                        $term_id = absint($row['termino_id'] ?? 0);
                        $out['attribute_aliases'][$key]['portable']['term_slugs'][] = ($term_id && isset($term_by_id[$term_id])) ? $term_by_id[$term_id][1] : '';
                    }
                    foreach ($out['attribute_aliases'] as &$entry) {
                        $entry['portable']['term_slugs'] = array_values(array_unique($entry['portable']['term_slugs']));
                        sort($entry['portable']['term_slugs'], SORT_STRING);
                    }
                    unset($entry);
                }
            }
        }
        if (taxonomy_exists('product_tag')) {
            $rows = (array) $wpdb->get_results(
                "SELECT t.term_id,t.name,t.slug,tt.description,tt.count
                 FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
                 WHERE tt.taxonomy='product_tag'",
                ARRAY_A
            );
            foreach ($rows as $row) {
                $slug = sanitize_title((string) $row['slug']);
                $portable = array(
                    'key' => array('taxonomy' => 'product_tag', 'slug' => $slug),
                    'name' => (string) $row['name'],
                    'description' => (string) $row['description'],
                );
                $out['product_tags'][$slug] = array('id' => absint($row['term_id']), 'portable' => $portable, 'raw' => $row);
            }
        }
        $cache = $out;
        return $out;
    }

    private static function analyze_masters($masters, $package, $mode, $purge, &$state, $scope = array()) {
        $local = self::local_master_index();
        foreach ($package as $type => $entries) {
            foreach ($entries as $key => $source) {
                $local_portable = isset($local[$type][$key]) ? $local[$type][$key]['portable'] : null;
                $state['plan'][] = array('master', $type, $key, self::portable_digest($source), null === $local_portable ? '' : self::portable_digest($local_portable));
                if (!isset($local[$type][$key])) {
                    $state['summary']['masters_create']++;
                    continue;
                }
                if (self::portable_digest($source) !== self::portable_digest($local_portable)) {
                    $state['summary']['masters_update']++;
                }
            }
        }
        if (!$purge || 'mirror' !== $mode) {
            return;
        }
        foreach ($local as $type => $entries) {
            foreach ($entries as $key => $entry) {
                if (isset($package[$type][$key])) {
                    continue;
                }
                // Vocabulary/atributos/terminos extra se retiran por desactivacion.
                // Si ya estan inactivos, no queda ninguna accion funcional pendiente.
                if ('vocabulary' === $type && empty($entry['raw']['active'])) {
                    continue;
                }
                if (in_array($type, array('attributes', 'attribute_terms'), true) && empty($entry['raw']['activo'])) {
                    continue;
                }
                $state['plan'][] = array('master_extra', $type, $key, self::portable_digest($entry['portable'] ?? array()));
                $safe = self::master_extra_is_safe($type, $entry, $package, $state, $scope);
                if ($safe) {
                    $state['summary']['masters_remove']++;
                }
            }
        }
    }

    private static function master_extra_is_safe($type, $entry, $package, &$state, $scope = array()) {
        global $wpdb;
        $t = self::required_tables();
        $scope = array_map('sanitize_key', (array) $scope);

        if ('vocabulary' === $type) {
            $id = absint($entry['id'] ?? 0);
            $uses = (array) $wpdb->get_results($wpdb->prepare(
                "SELECT object_type,COUNT(*) total FROM {$t['object_vocabulary']} WHERE vocabulary_id=%d AND status=1 GROUP BY object_type",
                $id
            ), ARRAY_A);
            $unsafe = array();
            foreach ($uses as $use) {
                $object_type = sanitize_key((string) ($use['object_type'] ?? ''));
                $count = (int) ($use['total'] ?? 0);
                if ($count < 1) {
                    continue;
                }
                if ('product' === $object_type && in_array('product_semantic', $scope, true)) {
                    continue;
                }
                if ('product_cat' === $object_type && in_array('category_semantic', $scope, true)) {
                    continue;
                }
                $unsafe[] = $object_type . ':' . $count;
            }
            if ($unsafe) {
                self::add_conflict($state, 'No se puede retirar Vocabulary extra ' . ($entry['portable']['key']['semantic_group'] ?? '') . ':' . ($entry['portable']['key']['slug'] ?? '') . ' porque tiene usos fuera de las capas MIRROR (' . implode(', ', $unsafe) . ').', 'ambiguous');
                return false;
            }
        } elseif ('attributes' === $type) {
            $id = absint($entry['id'] ?? 0);
            $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['product_attributes']} WHERE atributo_id=%d", $id));
            if ($count > 0 && !in_array('product_attributes', $scope, true)) {
                self::add_conflict($state, 'No se puede retirar el atributo extra ' . ($entry['portable']['key']['attribute_slug'] ?? '') . ' porque tiene asignaciones y product_attributes no forma parte del MIRROR.', 'ambiguous');
                return false;
            }
        } elseif ('attribute_terms' === $type) {
            $id = absint($entry['id'] ?? 0);
            $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['product_attributes']} WHERE termino_id=%d", $id));
            if ($count > 0 && !in_array('product_attributes', $scope, true)) {
                self::add_conflict($state, 'No se puede retirar un termino de atributo extra porque tiene asignaciones y product_attributes no forma parte del MIRROR.', 'ambiguous');
                return false;
            }
        } elseif ('product_tags' === $type) {
            $term_id = absint($entry['id'] ?? 0);
            $tt_id = (int) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_tag' AND term_id=%d", $term_id));
            if ($tt_id) {
                $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id=%d", $tt_id));
                if ($count > 0) {
                    if (!in_array('product_tags', $scope, true)) {
                        self::add_conflict($state, 'No se puede borrar product_tag extra ' . ($entry['portable']['key']['slug'] ?? '') . ' porque tiene relaciones y product_tags no forma parte del MIRROR.', 'ambiguous');
                        return false;
                    }
                    $outside = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE tr.term_taxonomy_id=%d AND (p.ID IS NULL OR p.post_type<>'product' OR p.post_status='trash')",
                        $tt_id
                    ));
                    if ($outside > 0) {
                        self::add_conflict($state, 'No se puede borrar product_tag extra ' . ($entry['portable']['key']['slug'] ?? '') . ' porque tiene ' . $outside . ' relacion(es) fuera de los productos cubiertos por el MIRROR.', 'ambiguous');
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private static function local_product_index() {
        global $wpdb;
        $rows = (array) $wpdb->get_results(
            "SELECT p.ID,p.post_name,p.post_title,
                    MAX(CASE WHEN pm.meta_key='_seo_catalog_uid' THEN pm.meta_value END) catalog_uid,
                    MAX(CASE WHEN pm.meta_key='_seo_proveedor' THEN pm.meta_value END) provider,
                    MAX(CASE WHEN pm.meta_key='_seo_proveedor_id_externo' THEN pm.meta_value END) external_id,
                    MAX(CASE WHEN pm.meta_key='_sku' THEN pm.meta_value END) sku
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key IN ('_seo_catalog_uid','_seo_proveedor','_seo_proveedor_id_externo','_sku')
             WHERE p.post_type='product' AND p.post_status<>'trash'
             GROUP BY p.ID,p.post_name,p.post_title",
            ARRAY_A
        );
        $index = array('rows' => array(), 'catalog_uid' => array(), 'provider_external' => array(), 'sku' => array(), 'slug' => array());
        foreach ($rows as $row) {
            $id = absint($row['ID']);
            $index['rows'][$id] = $row;
            self::index_candidate($index['catalog_uid'], trim((string) $row['catalog_uid']), $id);
            $provider = trim((string) $row['provider']);
            $external = trim((string) $row['external_id']);
            if ('' !== $provider && '' !== $external) {
                self::index_candidate($index['provider_external'], mb_strtolower($provider, 'UTF-8') . '|' . mb_strtolower($external, 'UTF-8'), $id);
            }
            self::index_candidate($index['sku'], trim((string) $row['sku']), $id);
            self::index_candidate($index['slug'], sanitize_title((string) $row['post_name']), $id);
        }
        return $index;
    }

    private static function index_candidate(&$map, $key, $id) {
        $key = trim((string) $key);
        if ('' === $key) {
            return;
        }
        if (!isset($map[$key])) {
            $map[$key] = array();
        }
        if (!in_array((int) $id, $map[$key], true)) {
            $map[$key][] = (int) $id;
        }
    }

    private static function resolve_product($identity, $index) {
        $identity = is_array($identity) ? $identity : array();
        $checks = array();
        $catalog_uid = trim((string) ($identity['catalog_uid'] ?? ''));
        if ('' !== $catalog_uid) {
            $checks['catalog_uid:' . $catalog_uid] = $index['catalog_uid'][$catalog_uid] ?? array();
        }
        $provider = trim((string) ($identity['provider'] ?? ''));
        $external = trim((string) ($identity['external_id'] ?? ''));
        if ('' !== $provider && '' !== $external) {
            $key = mb_strtolower($provider, 'UTF-8') . '|' . mb_strtolower($external, 'UTF-8');
            $checks['provider_external:' . $key] = $index['provider_external'][$key] ?? array();
        }
        $sku = trim((string) ($identity['sku'] ?? ''));
        if ('' !== $sku) {
            $checks['sku:' . $sku] = $index['sku'][$sku] ?? array();
        }
        $slug = sanitize_title((string) ($identity['slug'] ?? ''));
        if ('' !== $slug) {
            $checks['slug:' . $slug] = $index['slug'][$slug] ?? array();
        }
        if (!$checks) {
            return new WP_Error('missing', 'no contiene ninguna clave portable utilizable.');
        }
        $resolved = array();
        foreach ($checks as $label => $ids) {
            $ids = array_values(array_unique(array_map('absint', (array) $ids)));
            if (count($ids) > 1) {
                return new WP_Error('ambiguous', $label . ' coincide con varios productos locales (' . implode(',', $ids) . ').');
            }
            if (1 === count($ids)) {
                $resolved[$ids[0]] = true;
            }
        }
        if (!$resolved) {
            return new WP_Error('missing', 'no existe ningun producto local con sus claves portables.');
        }
        if (count($resolved) !== 1) {
            return new WP_Error('ambiguous', 'sus claves portables apuntan a productos locales distintos (' . implode(',', array_keys($resolved)) . ').');
        }
        return (int) array_key_first($resolved);
    }

    private static function local_category_index() {
        global $wpdb;
        $rows = (array) $wpdb->get_results(
            "SELECT t.term_id,t.name,t.slug,tt.parent FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy='product_cat'",
            ARRAY_A
        );
        $by_id = array();
        foreach ($rows as $row) {
            $by_id[absint($row['term_id'])] = $row;
        }
        $index = array('rows' => $by_id, 'path' => array(), 'slug' => array());
        foreach ($by_id as $id => $row) {
            $path = implode('/', self::category_path_slugs($id, $by_id));
            self::index_candidate($index['path'], $path, $id);
            self::index_candidate($index['slug'], sanitize_title((string) $row['slug']), $id);
        }
        return $index;
    }

    private static function resolve_category($identity, $index) {
        $identity = is_array($identity) ? $identity : array();
        if ('product_cat' !== sanitize_key((string) ($identity['taxonomy'] ?? 'product_cat'))) {
            return new WP_Error('missing', 'taxonomia no soportada.');
        }
        $slug = sanitize_title((string) ($identity['slug'] ?? ''));
        $path_slugs = array_values(array_filter(array_map('sanitize_title', (array) ($identity['path_slugs'] ?? array()))));
        if ($path_slugs) {
            $path = implode('/', $path_slugs);
            $ids = array_values(array_unique(array_map('absint', (array) ($index['path'][$path] ?? array()))));
            if (count($ids) > 1) {
                return new WP_Error('ambiguous', 'la ruta ' . $path . ' coincide con varias categorias locales.');
            }
            if (1 === count($ids)) {
                $local_slug = sanitize_title((string) ($index['rows'][$ids[0]]['slug'] ?? ''));
                if ($slug && $local_slug !== $slug) {
                    return new WP_Error('ambiguous', 'la ruta coincide pero el slug local es distinto.');
                }
                return $ids[0];
            }
            return new WP_Error('missing', 'no existe la ruta de categoria ' . $path . '.');
        }
        if (!$slug) {
            return new WP_Error('missing', 'no contiene slug ni ruta portable.');
        }
        $ids = array_values(array_unique(array_map('absint', (array) ($index['slug'][$slug] ?? array()))));
        if (count($ids) > 1) {
            return new WP_Error('ambiguous', 'el slug ' . $slug . ' coincide con varias categorias locales.');
        }
        if (!$ids) {
            return new WP_Error('missing', 'no existe categoria local con slug ' . $slug . '.');
        }
        return $ids[0];
    }

    private static function source_object_label($source) {
        $name = trim((string) ($source['display_name'] ?? ''));
        $identity = (array) ($source['identity'] ?? array());
        $slug = trim((string) ($identity['slug'] ?? ''));
        if ($name && $slug) {
            return '"' . $name . '" [' . $slug . ']';
        }
        if ($name) {
            return '"' . $name . '"';
        }
        return $slug ?: '(sin clave visible)';
    }

    private static function local_product_tags($product_id) {
        $terms = wp_get_object_terms((int) $product_id, 'product_tag', array('fields' => 'slugs'));
        if (is_wp_error($terms)) {
            return array();
        }
        $terms = array_values(array_unique(array_map('sanitize_title', (array) $terms)));
        sort($terms, SORT_STRING);
        return $terms;
    }

    private static function local_semantic_rows($object_type, $object_id) {
        global $wpdb;
        $t = self::required_tables();
        if (!self::table_exists($t['object_vocabulary']) || !self::table_exists($t['vocabulary'])) {
            return array();
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT ov.id,ov.vocabulary_id,ov.source,ov.confidence,ov.status,v.semantic_group,v.slug
             FROM {$t['object_vocabulary']} ov JOIN {$t['vocabulary']} v ON v.id=ov.vocabulary_id
             WHERE ov.object_type=%s AND ov.object_id=%d ORDER BY ov.id",
            $object_type,
            (int) $object_id
        ), ARRAY_A);
    }

    private static function active_semantic_keys($object_type, $object_id) {
        $keys = array();
        foreach (self::local_semantic_rows($object_type, $object_id) as $row) {
            if (absint($row['status']) !== 1) {
                continue;
            }
            $keys[] = self::master_key($row['semantic_group'], $row['slug']);
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        return $keys;
    }

    private static function local_active_semantic_portable($object_type, $object_id) {
        $out = array();
        foreach (self::local_semantic_rows($object_type, $object_id) as $row) {
            if (absint($row['status']) !== 1) {
                continue;
            }
            $out[] = array(
                'key' => array(
                    'semantic_group' => sanitize_key((string) $row['semantic_group']),
                    'slug' => sanitize_title((string) $row['slug']),
                ),
                'source' => (string) $row['source'],
                'confidence' => (string) $row['confidence'],
            );
        }
        return $out;
    }

    private static function source_semantic_portable($rows) {
        $out = array();
        foreach ((array) $rows as $row) {
            $out[] = array(
                'key' => array(
                    'semantic_group' => sanitize_key((string) ($row['key']['semantic_group'] ?? '')),
                    'slug' => sanitize_title((string) ($row['key']['slug'] ?? '')),
                ),
                'source' => (string) ($row['source'] ?? 'import'),
                'confidence' => (string) ($row['confidence'] ?? '1.0000'),
            );
        }
        return $out;
    }

    private static function normalize_semantic_source($rows, $package_masters, &$state, $label) {
        $keys = array();
        foreach ((array) $rows as $row) {
            $key = self::master_key($row['key']['semantic_group'] ?? '', $row['key']['slug'] ?? '');
            if (!isset($package_masters['vocabulary'][$key])) {
                $local = self::local_master_index();
                if (!isset($local['vocabulary'][$key])) {
                    self::add_conflict($state, $label . ': Vocabulary no encontrado ' . $key . '.', 'ambiguous');
                    continue;
                }
            }
            $keys[] = $key;
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);
        return $keys;
    }

    private static function local_product_attribute_rows($product_id) {
        global $wpdb;
        $t = self::required_tables();
        if (!self::table_exists($t['product_attributes']) || !self::table_exists($t['attributes'])) {
            return array();
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT pa.id,a.slug attribute_slug,t.slug term_slug,pa.valor_texto,pa.valor_numero,pa.valor_numero_max,pa.unidad,pa.valor_original,pa.orden
             FROM {$t['product_attributes']} pa
             JOIN {$t['attributes']} a ON a.id=pa.atributo_id
             LEFT JOIN {$t['attribute_terms']} t ON t.id=pa.termino_id
             WHERE pa.product_id=%d ORDER BY a.slug,pa.orden,pa.id",
            (int) $product_id
        ), ARRAY_A);
    }

    private static function portable_attribute_row($row) {
        return array(
            'attribute_slug' => self::attribute_key($row['attribute_slug'] ?? ''),
            'term_slug' => sanitize_title((string) ($row['term_slug'] ?? '')),
            'value_text' => array_key_exists('value_text', $row) ? $row['value_text'] : ($row['valor_texto'] ?? null),
            'value_number' => array_key_exists('value_number', $row) ? $row['value_number'] : ($row['valor_numero'] ?? null),
            'value_number_max' => array_key_exists('value_number_max', $row) ? $row['value_number_max'] : ($row['valor_numero_max'] ?? null),
            'unit' => array_key_exists('unit', $row) ? $row['unit'] : ($row['unidad'] ?? null),
            'original_value' => array_key_exists('original_value', $row) ? $row['original_value'] : ($row['valor_original'] ?? null),
            'sort_order' => (int) (array_key_exists('sort_order', $row) ? $row['sort_order'] : ($row['orden'] ?? 0)),
        );
    }

    private static function multiset($rows, $normalizer = null) {
        $set = array();
        foreach ((array) $rows as $row) {
            $value = $normalizer ? call_user_func($normalizer, $row) : $row;
            $hash = self::portable_digest($value);
            if (!isset($set[$hash])) {
                $set[$hash] = 0;
            }
            $set[$hash]++;
        }
        ksort($set, SORT_STRING);
        return $set;
    }

    private static function set_delta_counts($source, $local, $mode) {
        $source = array_values(array_unique((array) $source));
        $local = array_values(array_unique((array) $local));
        $add = count(array_diff($source, $local));
        $remove = ('mirror' === $mode) ? count(array_diff($local, $source)) : 0;
        return array($add, $remove);
    }

    private static function analyze_product_assignments($product_id, $source, $scope, $mode, $package_masters, &$state) {
        $changed = false;
        $label = 'Producto ' . self::source_object_label($source);
        if (in_array('product_tags', $scope, true) && array_key_exists('product_tags', $source)) {
            $desired = array_values(array_unique(array_map('sanitize_title', (array) $source['product_tags'])));
            foreach ($desired as $slug) {
                if (!isset($package_masters['product_tags'][$slug])) {
                    $local_masters = self::local_master_index();
                    if (!isset($local_masters['product_tags'][$slug])) {
                        self::add_conflict($state, $label . ': product_tag no encontrado ' . $slug . '.', 'ambiguous');
                    }
                }
            }
            $local = self::local_product_tags($product_id);
            $state['plan'][] = array('product', (int) $product_id, 'product_tags', self::portable_digest($desired), self::portable_digest($local));
            list($add, $remove) = self::set_delta_counts($desired, $local, $mode);
            $state['summary']['relationships_add'] += $add;
            $state['summary']['relationships_remove'] += $remove;
            $changed = $changed || $add > 0 || $remove > 0;
        }
        if (in_array('product_semantic', $scope, true) && array_key_exists('semantic', $source)) {
            $desired = self::normalize_semantic_source($source['semantic'], $package_masters, $state, $label);
            $local = self::active_semantic_keys('product', $product_id);
            $desired_portable = self::source_semantic_portable($source['semantic']);
            $local_portable = self::local_active_semantic_portable('product', $product_id);
            $state['plan'][] = array('product', (int) $product_id, 'product_semantic', self::portable_digest($desired_portable), self::portable_digest($local_portable));
            list($add, $remove) = self::set_delta_counts($desired, $local, $mode);
            $state['summary']['relationships_add'] += $add;
            $state['summary']['relationships_remove'] += $remove;
            $changed = $changed || $add > 0 || $remove > 0 || self::portable_digest($desired_portable) !== self::portable_digest($local_portable);
        }
        if (in_array('product_attributes', $scope, true) && array_key_exists('attributes', $source)) {
            $local_masters = self::local_master_index();
            foreach ((array) $source['attributes'] as $attr) {
                $attr_slug = self::attribute_key($attr['attribute_slug'] ?? '');
                $term_slug = sanitize_title((string) ($attr['term_slug'] ?? ''));
                if (!isset($package_masters['attributes'][$attr_slug]) && !isset($local_masters['attributes'][$attr_slug])) {
                    self::add_conflict($state, $label . ': atributo no encontrado ' . $attr_slug . '.', 'ambiguous');
                }
                if ($term_slug) {
                    $term_key = self::attribute_term_key($attr_slug, $term_slug);
                    if (!isset($package_masters['attribute_terms'][$term_key]) && !isset($local_masters['attribute_terms'][$term_key])) {
                        self::add_conflict($state, $label . ': termino de atributo no encontrado ' . $term_key . '.', 'ambiguous');
                    }
                }
            }
            $source_set = self::multiset((array) $source['attributes'], array(__CLASS__, 'portable_attribute_row'));
            $local_rows = self::local_product_attribute_rows($product_id);
            $local_set = self::multiset($local_rows, array(__CLASS__, 'portable_attribute_row'));
            $state['plan'][] = array('product', (int) $product_id, 'product_attributes', self::portable_digest($source_set), self::portable_digest($local_set));
            $add = 0;
            $remove = 0;
            foreach ($source_set as $hash => $count) {
                $add += max(0, $count - (int) ($local_set[$hash] ?? 0));
            }
            if ('mirror' === $mode) {
                foreach ($local_set as $hash => $count) {
                    $remove += max(0, $count - (int) ($source_set[$hash] ?? 0));
                }
            }
            $state['summary']['relationships_add'] += $add;
            $state['summary']['relationships_remove'] += $remove;
            $changed = $changed || $add > 0 || $remove > 0;
        }
        if ($changed) {
            $state['summary']['objects_changed']++;
            $state['summary']['products_changed']++;
        } else {
            $state['summary']['objects_unchanged']++;
        }
    }

    private static function analyze_extra_products($sources, $local_index, &$state) {
        $matched = array();
        foreach ((array) $sources as $source) {
            $id = self::resolve_product($source['identity'] ?? array(), $local_index);
            if (!is_wp_error($id)) {
                $matched[(int) $id] = true;
            }
        }
        foreach (array_keys((array) $local_index['rows']) as $id) {
            if (!isset($matched[(int) $id])) {
                $row = $local_index['rows'][$id];
                $state['plan'][] = array('product_extra', (int) $id, sanitize_title((string) ($row['post_name'] ?? '')));
                self::add_conflict($state, 'Producto local sin equivalente en el paquete full: ' . (string) ($row['post_title'] ?? '') . ' [' . (string) ($row['post_name'] ?? '') . ']. No se eliminan objetos de negocio.', 'ambiguous');
            }
        }
    }

    private static function local_category_label($category_id) {
        global $wpdb;
        $t = self::required_tables();
        if (!self::table_exists($t['nodes'])) {
            return null;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,keywords,title,status FROM {$t['nodes']} WHERE object_type='category' AND object_id=%d AND seo_role='category' LIMIT 1",
            (int) $category_id
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        return array('keywords' => (string) $row['keywords'], 'title' => (string) $row['title'], 'status' => absint($row['status']) ? 1 : 0);
    }

    private static function analyze_category_assignments($category_id, $source, $scope, $mode, $package_masters, &$state) {
        $changed = false;
        $label = 'Categoria ' . self::source_object_label($source);
        if (in_array('category_semantic', $scope, true) && array_key_exists('semantic', $source)) {
            $desired = self::normalize_semantic_source($source['semantic'], $package_masters, $state, $label);
            $local = self::active_semantic_keys('product_cat', $category_id);
            $desired_portable = self::source_semantic_portable($source['semantic']);
            $local_portable = self::local_active_semantic_portable('product_cat', $category_id);
            $state['plan'][] = array('category', (int) $category_id, 'category_semantic', self::portable_digest($desired_portable), self::portable_digest($local_portable));
            list($add, $remove) = self::set_delta_counts($desired, $local, $mode);
            $state['summary']['relationships_add'] += $add;
            $state['summary']['relationships_remove'] += $remove;
            $changed = $changed || $add > 0 || $remove > 0 || self::portable_digest($desired_portable) !== self::portable_digest($local_portable);
        }
        if (in_array('category_labels', $scope, true) && array_key_exists('category_labels', $source)) {
            $desired = $source['category_labels'];
            $local = self::local_category_label($category_id);
            $state['plan'][] = array('category', (int) $category_id, 'category_labels', self::portable_digest($desired), self::portable_digest($local));
            if (null === $desired) {
                if ('mirror' === $mode && null !== $local) {
                    $state['summary']['relationships_remove']++;
                    $changed = true;
                }
            } else {
                $desired_portable = array(
                    'keywords' => (string) ($desired['keywords'] ?? ''),
                    'title' => (string) ($desired['title'] ?? ''),
                    'status' => !empty($desired['status']) ? 1 : 0,
                );
                if (null === $local) {
                    $state['summary']['relationships_add']++;
                    $changed = true;
                } elseif (self::portable_digest($desired_portable) !== self::portable_digest($local)) {
                    $changed = true;
                }
            }
        }
        if ($changed) {
            $state['summary']['objects_changed']++;
            $state['summary']['categories_changed']++;
        } else {
            $state['summary']['objects_unchanged']++;
        }
    }

    private static function analyze_extra_categories($sources, $local_index, &$state) {
        $matched = array();
        foreach ((array) $sources as $source) {
            $id = self::resolve_category($source['identity'] ?? array(), $local_index);
            if (!is_wp_error($id)) {
                $matched[(int) $id] = true;
            }
        }
        foreach (array_keys((array) $local_index['rows']) as $id) {
            if (!isset($matched[(int) $id])) {
                $row = $local_index['rows'][$id];
                $state['plan'][] = array('category_extra', (int) $id, sanitize_title((string) ($row['slug'] ?? '')));
                self::add_conflict($state, 'Categoria local sin equivalente en el paquete full: ' . (string) ($row['name'] ?? '') . ' [' . (string) ($row['slug'] ?? '') . ']. No se eliminan objetos de negocio.', 'ambiguous');
            }
        }
    }

    private static function apply_document($document, $mode, $purge) {
        if (!class_exists('SEO_Data_Layer') || !class_exists('SEO_Data_Operation')) {
            return new WP_Error('semantic_catalog_data_layer', 'El Data Layer no esta disponible; se cancela la importacion.');
        }
        $preflight = self::analyze_document($document, $mode, $purge);
        if (is_wp_error($preflight)) {
            return $preflight;
        }
        if (!empty($preflight['conflicts'])) {
            return new WP_Error('semantic_catalog_conflicts', 'La prevalidacion contiene conflictos; no se ha escrito ningun dato.');
        }
        $package_id = (string) ($document['manifest']['package_id'] ?? '');
        $operation = SEO_Data_Layer::operation(array(
            'type' => 'semantic_catalog_import',
            'label' => 'Importar catalogo semantico portable',
            'source_module' => 'semantic_catalog',
            'rollbackable' => true,
            'risk_level' => 'high',
            'audit_level' => 'full',
            'metadata' => array(
                'package_id' => $package_id,
                'mode' => $mode,
                'purge_extra_masters' => $purge ? 1 : 0,
                'identity_policy' => 'source_numeric_ids_are_audit_only',
                'source_environment' => (string) ($document['manifest']['source_environment'] ?? ''),
            ),
        ));
        $operation->mark_validated(array('preflight_summary' => $preflight['summary']));
        $expected = (int) ($preflight['summary']['masters_create'] + $preflight['summary']['masters_update'] + $preflight['summary']['masters_remove'] + $preflight['summary']['relationships_add'] + $preflight['summary']['relationships_remove']);
        $operation->mark_previewed($expected, array('portable_content_fingerprint' => (string) ($document['manifest']['fingerprints']['content'] ?? '')));
        try {
            return $operation->execute(static function (SEO_Data_Operation $op) use ($document, $mode, $purge, $package_id) {
                $sections = (array) ($document['sections'] ?? array());
                $scope = array_map('sanitize_key', (array) ($document['manifest']['scope'] ?? array()));
                $stats = self::blank_summary();
                $changed_products = array();
                $changed_categories = array();
                $changed_layers = array();

                $dummy = array('summary' => self::blank_summary(), 'conflicts' => array());
                $package_masters = self::package_master_index((array) ($sections['masters'] ?? array()), $dummy);
                if (in_array('masters', $scope, true)) {
                    $masters_before = (int) $stats['masters_create'] + (int) $stats['masters_update'] + (int) $stats['masters_remove'];
                    self::apply_masters($op, (array) ($sections['masters'] ?? array()), $package_masters, $mode, $stats);
                    $masters_after = (int) $stats['masters_create'] + (int) $stats['masters_update'] + (int) $stats['masters_remove'];
                    if ($masters_after > $masters_before) {
                        $changed_layers['masters'] = true;
                    }
                }
                $local_masters = self::local_master_index(true);

                if (isset($sections['products'])) {
                    $local_products = self::local_product_index();
                    foreach ((array) $sections['products'] as $source) {
                        $product_id = self::resolve_product($source['identity'] ?? array(), $local_products);
                        if (is_wp_error($product_id)) {
                            throw new RuntimeException('Producto no resoluble durante apply: ' . $product_id->get_error_message());
                        }
                        $stats['objects_found']++;
                        $object_changed = false;
                        if (in_array('product_tags', $scope, true) && array_key_exists('product_tags', $source)) {
                            if (self::apply_product_tags((int) $product_id, (array) $source['product_tags'], $mode, $local_masters, $stats)) {
                                $object_changed = true;
                                $changed_layers['product_tags'] = true;
                            }
                        }
                        if (in_array('product_semantic', $scope, true) && array_key_exists('semantic', $source)) {
                            if (self::apply_semantic_assignments($op, 'product', (int) $product_id, (array) $source['semantic'], $mode, $local_masters, $stats)) {
                                $object_changed = true;
                                $changed_layers['product_semantic'] = true;
                            }
                        }
                        if (in_array('product_attributes', $scope, true) && array_key_exists('attributes', $source)) {
                            if (self::apply_product_attributes($op, (int) $product_id, (array) $source['attributes'], $mode, $local_masters, $stats)) {
                                $object_changed = true;
                                $changed_layers['product_attributes'] = true;
                            }
                        }
                        if ($object_changed) {
                            $stats['objects_changed']++;
                            $stats['products_changed']++;
                            $changed_products[(int) $product_id] = true;
                        } else {
                            $stats['objects_unchanged']++;
                        }
                    }
                }

                if (isset($sections['categories'])) {
                    $local_categories = self::local_category_index();
                    foreach ((array) $sections['categories'] as $source) {
                        $category_id = self::resolve_category($source['identity'] ?? array(), $local_categories);
                        if (is_wp_error($category_id)) {
                            throw new RuntimeException('Categoria no resoluble durante apply: ' . $category_id->get_error_message());
                        }
                        $stats['objects_found']++;
                        $object_changed = false;
                        if (in_array('category_semantic', $scope, true) && array_key_exists('semantic', $source)) {
                            if (self::apply_semantic_assignments($op, 'product_cat', (int) $category_id, (array) $source['semantic'], $mode, $local_masters, $stats)) {
                                $object_changed = true;
                                $changed_layers['category_semantic'] = true;
                            }
                        }
                        if (in_array('category_labels', $scope, true) && array_key_exists('category_labels', $source)) {
                            if (self::apply_category_label($op, (int) $category_id, $source['category_labels'], $mode, $stats)) {
                                $object_changed = true;
                                $changed_layers['category_labels'] = true;
                            }
                        }
                        if ($object_changed) {
                            $stats['objects_changed']++;
                            $stats['categories_changed']++;
                            $changed_categories[(int) $category_id] = true;
                        } else {
                            $stats['objects_unchanged']++;
                        }
                    }
                }

                if ($purge && 'mirror' === $mode && in_array('masters', $scope, true)) {
                    $masters_before = (int) $stats['masters_create'] + (int) $stats['masters_update'] + (int) $stats['masters_remove'];
                    self::apply_master_purge($op, $package_masters, $stats);
                    $masters_after = (int) $stats['masters_create'] + (int) $stats['masters_update'] + (int) $stats['masters_remove'];
                    if ($masters_after > $masters_before) {
                        $changed_layers['masters'] = true;
                    }
                }

                return array(
                    'summary' => $stats,
                    'changed_product_ids' => array_values(array_map('intval', array_keys($changed_products))),
                    'changed_category_ids' => array_values(array_map('intval', array_keys($changed_categories))),
                    'changed_layers' => array_values(array_keys($changed_layers)),
                    'package_id' => $package_id,
                );
            });
        } catch (Throwable $e) {
            return new WP_Error('semantic_catalog_apply', 'Importacion cancelada y transaccion revertida: ' . $e->getMessage());
        }
    }

    private static function apply_masters(SEO_Data_Operation $op, $masters, $package, $mode, &$stats) {
        global $wpdb;
        $t = self::required_tables();
        $local = self::local_master_index();

        foreach ((array) ($masters['semantic_vocabulary'] ?? array()) as $row) {
            $group = sanitize_key((string) ($row['key']['semantic_group'] ?? ''));
            $slug = sanitize_title((string) ($row['key']['slug'] ?? ''));
            $key = self::master_key($group, $slug);
            $data = array(
                'semantic_group' => $group,
                'slug' => $slug,
                'label' => (string) ($row['label'] ?? ''),
                'source' => (string) ($row['source'] ?? 'import'),
                'active' => !empty($row['active']) ? 1 : 0,
                'updated_at' => current_time('mysql', true),
            );
            if (isset($local['vocabulary'][$key])) {
                $entry = $local['vocabulary'][$key];
                $changes = self::changed_db_fields($entry['raw'], $data);
                if ($changes) {
                    $op->update('semantic_vocabulary', array('id' => (int) $entry['id']), $changes, array('portable_key' => $key));
                    $stats['masters_update']++;
                }
            } else {
                $data['parent_id'] = null;
                $data['created_at'] = current_time('mysql', true);
                $op->insert('semantic_vocabulary', $data, array('portable_key' => $key));
                $stats['masters_create']++;
            }
        }
        $local = self::local_master_index(true);
        foreach ((array) ($masters['semantic_vocabulary'] ?? array()) as $row) {
            $key = self::master_key($row['key']['semantic_group'] ?? '', $row['key']['slug'] ?? '');
            if (!isset($local['vocabulary'][$key])) {
                throw new RuntimeException('Vocabulary no creado: ' . $key);
            }
            $parent_id = null;
            if (!empty($row['parent']) && is_array($row['parent'])) {
                $parent_key = self::master_key($row['parent']['semantic_group'] ?? '', $row['parent']['slug'] ?? '');
                if (!isset($local['vocabulary'][$parent_key])) {
                    throw new RuntimeException('Parent Vocabulary no resuelto: ' . $parent_key);
                }
                $parent_id = (int) $local['vocabulary'][$parent_key]['id'];
            }
            $current_parent = absint($local['vocabulary'][$key]['raw']['parent_id'] ?? 0);
            if ($current_parent !== (int) ($parent_id ?: 0)) {
                $op->update('semantic_vocabulary', array('id' => (int) $local['vocabulary'][$key]['id']), array('parent_id' => $parent_id, 'updated_at' => current_time('mysql', true)), array('portable_key' => $key));
                $stats['masters_update']++;
            }
        }

        $local = self::local_master_index(true);
        foreach ((array) ($masters['type_role_map'] ?? array()) as $row) {
            $type_slug = sanitize_title((string) ($row['type']['slug'] ?? ''));
            $role_slug = sanitize_title((string) ($row['role']['slug'] ?? ''));
            $type_key = self::master_key('tipo', $type_slug);
            $role_key = self::master_key('rol', $role_slug);
            if (!isset($local['vocabulary'][$type_key], $local['vocabulary'][$role_key])) {
                throw new RuntimeException('TIPO/ROL no resuelto para mapa ' . $type_slug . ' -> ' . $role_slug);
            }
            $data = array(
                'type_vocabulary_id' => (int) $local['vocabulary'][$type_key]['id'],
                'role_vocabulary_id' => (int) $local['vocabulary'][$role_key]['id'],
                'confidence' => (string) ($row['confidence'] ?? '1.0000'),
                'source' => (string) ($row['source'] ?? 'import'),
                'active' => !empty($row['active']) ? 1 : 0,
            );
            if (isset($local['type_role_map'][$type_slug])) {
                $entry = $local['type_role_map'][$type_slug];
                $changes = self::changed_db_fields($entry['raw'], $data);
                if ($changes) {
                    $op->update('semantic_type_role_map', array('id' => (int) $entry['id']), $changes, array('portable_key' => $type_slug));
                    $stats['masters_update']++;
                }
            } else {
                $op->insert('semantic_type_role_map', $data, array('portable_key' => $type_slug));
                $stats['masters_create']++;
            }
        }

        $local = self::local_master_index(true);
        foreach ((array) ($masters['attributes'] ?? array()) as $row) {
            $slug = self::attribute_key($row['key']['attribute_slug'] ?? '');
            $data = array(
                'slug' => $slug,
                'nombre' => (string) ($row['name'] ?? ''),
                'grupo' => (string) ($row['group'] ?? ''),
                'tipo' => (string) ($row['type'] ?? 'texto'),
                'unidad_tipo' => (string) ($row['unit_type'] ?? ''),
                'unidad_base' => (string) ($row['base_unit'] ?? ''),
                'multiple' => absint($row['multiple'] ?? 0),
                'filtrable' => absint($row['filterable'] ?? 0),
                'visible' => absint($row['visible'] ?? 0),
                'seo' => absint($row['seo'] ?? 0),
                'orden' => (int) ($row['sort_order'] ?? 0),
                'activo' => !empty($row['active']) ? 1 : 0,
            );
            if (isset($local['attributes'][$slug])) {
                $entry = $local['attributes'][$slug];
                $changes = self::changed_db_fields($entry['raw'], $data);
                if ($changes) {
                    $op->update('attribute_definitions', array('id' => (int) $entry['id']), $changes, array('portable_key' => $slug));
                    $stats['masters_update']++;
                }
            } else {
                $op->insert('attribute_definitions', $data, array('portable_key' => $slug));
                $stats['masters_create']++;
            }
        }

        $local = self::local_master_index(true);
        foreach ((array) ($masters['attribute_terms'] ?? array()) as $row) {
            $attr_slug = self::attribute_key($row['key']['attribute_slug'] ?? '');
            $term_slug = sanitize_title((string) ($row['key']['term_slug'] ?? ''));
            if (!isset($local['attributes'][$attr_slug])) {
                throw new RuntimeException('Atributo no resuelto para termino: ' . $attr_slug);
            }
            $key = self::attribute_term_key($attr_slug, $term_slug);
            $data = array(
                'atributo_id' => (int) $local['attributes'][$attr_slug]['id'],
                'slug' => $term_slug,
                'nombre' => (string) ($row['name'] ?? ''),
                'orden' => (int) ($row['sort_order'] ?? 0),
                'activo' => !empty($row['active']) ? 1 : 0,
            );
            if (isset($local['attribute_terms'][$key])) {
                $entry = $local['attribute_terms'][$key];
                $changes = self::changed_db_fields($entry['raw'], $data);
                if ($changes) {
                    $op->update('attribute_terms', array('id' => (int) $entry['id']), $changes, array('portable_key' => $key));
                    $stats['masters_update']++;
                }
            } else {
                $op->insert('attribute_terms', $data, array('portable_key' => $key));
                $stats['masters_create']++;
            }
        }

        $local = self::local_master_index(true);
        foreach ((array) ($masters['attribute_aliases'] ?? array()) as $row) {
            $attr_slug = self::attribute_key($row['key']['attribute_slug'] ?? '');
            $alias = (string) ($row['key']['alias'] ?? '');
            $key = self::alias_key($attr_slug, $alias);
            if (!isset($local['attributes'][$attr_slug])) {
                throw new RuntimeException('Atributo no resuelto para alias: ' . $attr_slug);
            }
            $desired_term_ids = array();
            foreach ((array) ($row['term_slugs'] ?? array()) as $term_slug) {
                $term_slug = sanitize_title((string) $term_slug);
                if ('' === $term_slug) {
                    $desired_term_ids[] = 0;
                    continue;
                }
                $term_key = self::attribute_term_key($attr_slug, $term_slug);
                if (!isset($local['attribute_terms'][$term_key])) {
                    throw new RuntimeException('Termino no resuelto para alias: ' . $term_key);
                }
                $desired_term_ids[] = (int) $local['attribute_terms'][$term_key]['id'];
            }
            $desired_term_ids = array_values(array_unique($desired_term_ids));
            sort($desired_term_ids, SORT_NUMERIC);
            $existing_ids = array();
            if (isset($local['attribute_aliases'][$key])) {
                foreach ((array) $local['attribute_aliases'][$key]['raw'] as $raw) {
                    $existing_ids[] = absint($raw['termino_id'] ?? 0);
                }
                $existing_ids = array_values(array_unique($existing_ids));
                sort($existing_ids, SORT_NUMERIC);
            }
            if ($existing_ids !== $desired_term_ids) {
                foreach ((array) ($local['attribute_aliases'][$key]['ids'] ?? array()) as $alias_id) {
                    $op->delete('attribute_aliases', array('id' => (int) $alias_id), array('portable_key' => $key));
                }
                foreach ($desired_term_ids as $term_id) {
                    $op->insert('attribute_aliases', array(
                        'atributo_id' => (int) $local['attributes'][$attr_slug]['id'],
                        'termino_id' => $term_id > 0 ? $term_id : null,
                        'alias' => $alias,
                    ), array('portable_key' => $key));
                }
                if (isset($local['attribute_aliases'][$key])) {
                    $stats['masters_update']++;
                } else {
                    $stats['masters_create']++;
                }
            }
        }

        foreach ((array) ($masters['product_tags'] ?? array()) as $row) {
            $slug = sanitize_title((string) ($row['key']['slug'] ?? ''));
            $name = (string) ($row['name'] ?? $slug);
            $description = (string) ($row['description'] ?? '');
            $term = get_term_by('slug', $slug, 'product_tag');
            if (!$term || is_wp_error($term)) {
                $created = wp_insert_term($name, 'product_tag', array('slug' => $slug, 'description' => $description));
                if (is_wp_error($created)) {
                    throw new RuntimeException('No se pudo crear product_tag ' . $slug . ': ' . $created->get_error_message());
                }
                $stats['masters_create']++;
            } else {
                if ((string) $term->name !== $name || (string) $term->description !== $description) {
                    $updated = wp_update_term((int) $term->term_id, 'product_tag', array('name' => $name, 'slug' => $slug, 'description' => $description));
                    if (is_wp_error($updated)) {
                        throw new RuntimeException('No se pudo actualizar product_tag ' . $slug . ': ' . $updated->get_error_message());
                    }
                    $stats['masters_update']++;
                }
            }
        }
    }

    private static function changed_db_fields($raw, $data) {
        $changes = array();
        foreach ((array) $data as $key => $value) {
            $old = $raw[$key] ?? null;
            if (null === $value) {
                if (null !== $old && '' !== $old && '0' !== (string) $old) {
                    $changes[$key] = null;
                }
                continue;
            }
            if ((string) $old !== (string) $value) {
                $changes[$key] = $value;
            }
        }
        return $changes;
    }

    private static function apply_product_tags($product_id, $desired_slugs, $mode, $masters, &$stats) {
        $desired_slugs = array_values(array_unique(array_map('sanitize_title', (array) $desired_slugs)));
        sort($desired_slugs, SORT_STRING);
        $local_slugs = self::local_product_tags($product_id);
        $target_slugs = ('merge' === $mode) ? array_values(array_unique(array_merge($local_slugs, $desired_slugs))) : $desired_slugs;
        sort($target_slugs, SORT_STRING);
        if ($target_slugs === $local_slugs) {
            return false;
        }
        $term_ids = array();
        foreach ($target_slugs as $slug) {
            $term = get_term_by('slug', $slug, 'product_tag');
            if (!$term || is_wp_error($term)) {
                throw new RuntimeException('product_tag local no resuelto: ' . $slug);
            }
            $term_ids[] = (int) $term->term_id;
        }
        $result = wp_set_object_terms($product_id, $term_ids, 'product_tag', false);
        if (is_wp_error($result)) {
            throw new RuntimeException('No se pudieron aplicar product_tags al producto ' . $product_id . ': ' . $result->get_error_message());
        }
        $stats['relationships_add'] += count(array_diff($target_slugs, $local_slugs));
        $stats['relationships_remove'] += count(array_diff($local_slugs, $target_slugs));
        return true;
    }

    private static function apply_semantic_assignments(SEO_Data_Operation $op, $object_type, $object_id, $desired_rows, $mode, $masters, &$stats) {
        $desired = array();
        foreach ((array) $desired_rows as $row) {
            $key = self::master_key($row['key']['semantic_group'] ?? '', $row['key']['slug'] ?? '');
            if (!isset($masters['vocabulary'][$key])) {
                throw new RuntimeException('Vocabulary local no resuelto: ' . $key);
            }
            $desired[$key] = array(
                'vocabulary_id' => (int) $masters['vocabulary'][$key]['id'],
                'source' => (string) ($row['source'] ?? 'import'),
                'confidence' => (string) ($row['confidence'] ?? '1.0000'),
            );
        }
        $current_rows = self::local_semantic_rows($object_type, $object_id);
        $current = array();
        foreach ($current_rows as $row) {
            $key = self::master_key($row['semantic_group'], $row['slug']);
            $current[$key] = $row;
        }
        $changed = false;
        foreach ($desired as $key => $want) {
            if (isset($current[$key])) {
                $row = $current[$key];
                $changes = array();
                if (absint($row['status']) !== 1) {
                    $changes['status'] = 1;
                    $stats['relationships_add']++;
                }
                if ((string) $row['source'] !== $want['source']) {
                    $changes['source'] = $want['source'];
                }
                if ((string) $row['confidence'] !== (string) $want['confidence']) {
                    $changes['confidence'] = $want['confidence'];
                }
                if ($changes) {
                    $changes['updated_at'] = current_time('mysql', true);
                    $op->update('object_vocabulary', array('id' => (int) $row['id']), $changes, array('related_object_type' => $object_type, 'related_object_id' => $object_id, 'portable_key' => $key));
                    $changed = true;
                }
            } else {
                $op->insert('object_vocabulary', array(
                    'object_type' => $object_type,
                    'object_id' => $object_id,
                    'vocabulary_id' => $want['vocabulary_id'],
                    'source' => $want['source'],
                    'confidence' => $want['confidence'],
                    'status' => 1,
                    'created_at' => current_time('mysql', true),
                    'updated_at' => current_time('mysql', true),
                ), array('related_object_type' => $object_type, 'related_object_id' => $object_id, 'portable_key' => $key));
                $stats['relationships_add']++;
                $changed = true;
            }
        }
        if ('mirror' === $mode) {
            foreach ($current as $key => $row) {
                if (isset($desired[$key]) || absint($row['status']) !== 1) {
                    continue;
                }
                $op->update('object_vocabulary', array('id' => (int) $row['id']), array('status' => 0, 'updated_at' => current_time('mysql', true)), array('related_object_type' => $object_type, 'related_object_id' => $object_id, 'portable_key' => $key));
                $stats['relationships_remove']++;
                $changed = true;
            }
        }
        return $changed;
    }

    private static function attribute_insert_data($product_id, $row, $masters) {
        $portable = self::portable_attribute_row($row);
        $attr_slug = $portable['attribute_slug'];
        if (!isset($masters['attributes'][$attr_slug])) {
            throw new RuntimeException('Atributo local no resuelto: ' . $attr_slug);
        }
        $term_id = null;
        if (!empty($portable['term_slug'])) {
            $term_key = self::attribute_term_key($attr_slug, $portable['term_slug']);
            if (!isset($masters['attribute_terms'][$term_key])) {
                throw new RuntimeException('Termino local no resuelto: ' . $term_key);
            }
            $term_id = (int) $masters['attribute_terms'][$term_key]['id'];
        }
        return array(
            'product_id' => (int) $product_id,
            'atributo_id' => (int) $masters['attributes'][$attr_slug]['id'],
            'termino_id' => $term_id,
            'valor_texto' => $portable['value_text'],
            'valor_numero' => $portable['value_number'],
            'valor_numero_max' => $portable['value_number_max'],
            'unidad' => $portable['unit'],
            'valor_original' => $portable['original_value'],
            'orden' => (int) $portable['sort_order'],
        );
    }

    private static function apply_product_attributes(SEO_Data_Operation $op, $product_id, $desired_rows, $mode, $masters, &$stats) {
        $local_rows = self::local_product_attribute_rows($product_id);
        $source_set = self::multiset($desired_rows, array(__CLASS__, 'portable_attribute_row'));
        $local_set = self::multiset($local_rows, array(__CLASS__, 'portable_attribute_row'));
        if ($source_set === $local_set) {
            return false;
        }
        if ('mirror' === $mode) {
            foreach ($local_rows as $row) {
                $op->delete('product_attributes', array('id' => (int) $row['id']), array('related_object_type' => 'product', 'related_object_id' => $product_id));
                $stats['relationships_remove']++;
            }
            foreach ($desired_rows as $row) {
                $op->insert('product_attributes', self::attribute_insert_data($product_id, $row, $masters), array('related_object_type' => 'product', 'related_object_id' => $product_id));
                $stats['relationships_add']++;
            }
            return true;
        }
        $remaining = $local_set;
        $changed = false;
        foreach ($desired_rows as $row) {
            $portable = self::portable_attribute_row($row);
            $hash = self::portable_digest($portable);
            if (!empty($remaining[$hash])) {
                $remaining[$hash]--;
                continue;
            }
            $op->insert('product_attributes', self::attribute_insert_data($product_id, $row, $masters), array('related_object_type' => 'product', 'related_object_id' => $product_id));
            $stats['relationships_add']++;
            $changed = true;
        }
        return $changed;
    }

    private static function apply_category_label(SEO_Data_Operation $op, $category_id, $desired, $mode, &$stats) {
        global $wpdb;
        $t = self::required_tables();
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id,keywords,title,status FROM {$t['nodes']} WHERE object_type='category' AND object_id=%d AND seo_role='category' LIMIT 1",
            $category_id
        ), ARRAY_A);
        if (null === $desired) {
            if ('mirror' === $mode && $existing) {
                $op->delete('nodes', array('id' => (int) $existing['id']), array('related_object_type' => 'product_cat', 'related_object_id' => $category_id));
                $stats['relationships_remove']++;
                return true;
            }
            return false;
        }
        $data = array(
            'keywords' => (string) ($desired['keywords'] ?? ''),
            'title' => (string) ($desired['title'] ?? ''),
            'status' => !empty($desired['status']) ? 1 : 0,
            'updated_at' => current_time('mysql', true),
        );
        if ($existing) {
            $changes = self::changed_db_fields($existing, $data);
            if (!$changes) {
                return false;
            }
            $op->update('nodes', array('id' => (int) $existing['id']), $changes, array('related_object_type' => 'product_cat', 'related_object_id' => $category_id));
            return true;
        }
        $op->insert('nodes', array_merge(array(
            'object_type' => 'category',
            'object_id' => $category_id,
            'seo_role' => 'category',
            'created_at' => current_time('mysql', true),
        ), $data), array('related_object_type' => 'product_cat', 'related_object_id' => $category_id));
        $stats['relationships_add']++;
        return true;
    }

    private static function apply_master_purge(SEO_Data_Operation $op, $package, &$stats) {
        global $wpdb;
        $local = self::local_master_index();
        $t = self::required_tables();
        foreach ($local['type_role_map'] as $key => $entry) {
            if (!isset($package['type_role_map'][$key])) {
                $op->delete('semantic_type_role_map', array('id' => (int) $entry['id']), array('portable_key' => $key));
                $stats['masters_remove']++;
            }
        }
        foreach ($local['attribute_aliases'] as $key => $entry) {
            if (!isset($package['attribute_aliases'][$key])) {
                foreach ((array) $entry['ids'] as $id) {
                    $op->delete('attribute_aliases', array('id' => (int) $id), array('portable_key' => $key));
                }
                $stats['masters_remove']++;
            }
        }
        foreach ($local['attribute_terms'] as $key => $entry) {
            if (!isset($package['attribute_terms'][$key]) && absint($entry['raw']['activo'] ?? 0) === 1) {
                $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['product_attributes']} WHERE termino_id=%d", (int) $entry['id']));
                if ($count > 0) {
                    throw new RuntimeException('Termino extra aun en uso: ' . $key);
                }
                $op->update('attribute_terms', array('id' => (int) $entry['id']), array('activo' => 0), array('portable_key' => $key));
                $stats['masters_remove']++;
            }
        }
        foreach ($local['attributes'] as $key => $entry) {
            if (!isset($package['attributes'][$key]) && absint($entry['raw']['activo'] ?? 0) === 1) {
                $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['product_attributes']} WHERE atributo_id=%d", (int) $entry['id']));
                if ($count > 0) {
                    throw new RuntimeException('Atributo extra aun en uso: ' . $key);
                }
                $op->update('attribute_definitions', array('id' => (int) $entry['id']), array('activo' => 0), array('portable_key' => $key));
                $stats['masters_remove']++;
            }
        }
        foreach ($local['vocabulary'] as $key => $entry) {
            if (!isset($package['vocabulary'][$key]) && absint($entry['raw']['active'] ?? 0) === 1) {
                $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['object_vocabulary']} WHERE vocabulary_id=%d AND status=1", (int) $entry['id']));
                if ($count > 0) {
                    throw new RuntimeException('Vocabulary extra aun en uso: ' . $key);
                }
                $op->update('semantic_vocabulary', array('id' => (int) $entry['id']), array('active' => 0, 'updated_at' => current_time('mysql', true)), array('portable_key' => $key));
                $stats['masters_remove']++;
            }
        }
        foreach ($local['product_tags'] as $key => $entry) {
            if (isset($package['product_tags'][$key])) {
                continue;
            }
            $tt_id = (int) $wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='product_tag' AND term_id=%d", (int) $entry['id']));
            $count = $tt_id ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id=%d", $tt_id)) : 0;
            if ($count > 0) {
                throw new RuntimeException('product_tag extra aun en uso: ' . $key);
            }
            $deleted = wp_delete_term((int) $entry['id'], 'product_tag');
            if (is_wp_error($deleted) || false === $deleted) {
                throw new RuntimeException('No se pudo borrar product_tag extra: ' . $key);
            }
            $stats['masters_remove']++;
        }
    }

    private static function mark_catalog_drift($document, $result) {
        $product_ids = array_values(array_unique(array_map('absint', (array) ($result['changed_product_ids'] ?? array()))));
        $category_ids = array_values(array_unique(array_map('absint', (array) ($result['changed_category_ids'] ?? array()))));
        $layers = array_values(array_unique(array_map('sanitize_key', (array) ($result['changed_layers'] ?? array()))));
        $payload = array(
            'package_id' => (string) ($document['manifest']['package_id'] ?? ''),
            'source_environment' => (string) ($document['manifest']['source_environment'] ?? ''),
            'changed_at' => current_time('mysql'),
            'product_ids' => $product_ids,
            'category_ids' => $category_ids,
            'layers' => $layers,
            'summary' => (array) ($result['summary'] ?? array()),
            'academy_snapshot_mutated' => 0,
        );
        update_option(self::DRIFT_OPTION, $payload, false);
        update_option(self::REINDEX_OPTION, array(
            'required' => !empty($product_ids) || !empty($category_ids) ? 1 : 0,
            'package_id' => $payload['package_id'],
            'product_ids' => $product_ids,
            'category_ids' => $category_ids,
            'layers' => $layers,
            'requested_at' => current_time('mysql'),
            'reason' => 'semantic_catalog_import',
        ), false);
        do_action('seo_semantic_catalog_imported', $payload);
    }

    private static function format_summary($summary) {
        $summary = wp_parse_args((array) $summary, self::blank_summary());
        return sprintf(
            'Maestros +%1$d / ~%2$d / -%3$d · objetos encontrados %4$d · cambiados %5$d · relaciones +%6$d / -%7$d · conflictos %8$d',
            (int) $summary['masters_create'],
            (int) $summary['masters_update'],
            (int) $summary['masters_remove'],
            (int) $summary['objects_found'],
            (int) $summary['objects_changed'],
            (int) $summary['relationships_add'],
            (int) $summary['relationships_remove'],
            (int) $summary['conflicts']
        );
    }

    private static function pull_notice() {
        $key = self::NOTICE_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        if ($notice) {
            delete_transient($key);
        }
        return is_array($notice) ? $notice : null;
    }

    private static function redirect_notice($error, $message, $summary = array(), $conflicts = array()) {
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), array(
            'error' => $error ? 1 : 0,
            'message' => (string) $message,
            'summary' => (array) $summary,
            'conflicts' => (array) $conflicts,
        ), 300);
        $url = add_query_arg(array('page' => 'seo-import-export', 'seo_ie_tab' => 'catalogo-semantico'), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}

SEO_Semantic_Catalog_Transfer::init();
