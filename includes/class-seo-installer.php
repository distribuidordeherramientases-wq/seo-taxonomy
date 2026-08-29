<?php
/**
 * Complete installer for SEO Taxonomy.
 *
 * Responsibilities:
 * - Create the current schema required by a clean installation.
 * - Reconcile additive schema changes on existing installations.
 * - Run the schema installers owned by feature modules.
 * - Insert only idempotent base data.
 * - Register the installed database version and installation status.
 *
 * Historical data migrations remain the responsibility of SEO_System_Updater.
 */

defined('ABSPATH') || exit;

final class SEO_System_Installer
{
    private const DB_VERSION_OPTION = 'seo_system_db_version';
    private const INSTALL_STATUS_OPTION = 'seo_system_install_status';
    private const INSTALL_ERROR_OPTION = 'seo_system_install_error';

    /**
     * Install or reconcile the complete schema.
     *
     * This method is intentionally idempotent. It is used both on activation
     * and by the updater after historical migrations have run.
     */
    public static function install(): void
    {
        global $wpdb;

        if (!defined('SEO_SYSTEM_DB_VERSION')) {
            throw new RuntimeException('SEO_SYSTEM_DB_VERSION is not defined.');
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        update_option(
            self::INSTALL_STATUS_OPTION,
            [
                'status'     => 'running',
                'started_at' => current_time('mysql'),
                'version'    => SEO_SYSTEM_DB_VERSION,
            ],
            false
        );
        delete_option(self::INSTALL_ERROR_OPTION);

        try {
            self::create_core_schema($wpdb);
            self::install_module_schemas($wpdb);
            self::install_default_data($wpdb);
            self::assert_required_tables_exist($wpdb, self::required_tables($wpdb));

            update_option(self::DB_VERSION_OPTION, SEO_SYSTEM_DB_VERSION, false);
            update_option(
                self::INSTALL_STATUS_OPTION,
                [
                    'status'       => 'completed',
                    'completed_at' => current_time('mysql'),
                    'version'      => SEO_SYSTEM_DB_VERSION,
                    'tables'       => count(self::required_tables($wpdb)),
                ],
                false
            );

            do_action('seo_system_install_completed', SEO_SYSTEM_DB_VERSION);
        } catch (Throwable $exception) {
            update_option(
                self::INSTALL_STATUS_OPTION,
                [
                    'status'    => 'failed',
                    'failed_at' => current_time('mysql'),
                    'version'   => SEO_SYSTEM_DB_VERSION,
                ],
                false
            );

            update_option(
                self::INSTALL_ERROR_OPTION,
                [
                    'message' => $exception->getMessage(),
                    'date'    => current_time('mysql'),
                    'version' => SEO_SYSTEM_DB_VERSION,
                ],
                false
            );

            error_log('[SEO Taxonomy] Installation error: ' . $exception->getMessage());
            throw $exception;
        }
    }

    /**
     * Core/shared tables whose schema is owned by the central installer.
     */
    private static function create_core_schema(wpdb $wpdb): void
    {
        $charset_collate = $wpdb->get_charset_collate();

        $relations          = $wpdb->prefix . 'seo_relations';
        $nodes              = $wpdb->prefix . 'seo_nodes';
        $templates          = $wpdb->prefix . 'seo_templates';
        $redirects          = $wpdb->prefix . 'seo_redirects';
        $dictionary         = $wpdb->prefix . 'seo_dictionari';
        $attributes         = $wpdb->prefix . 'seo_attributes'; // legado, solo compatibilidad/migración
        $sql_attributes     = $wpdb->prefix . 'sql_atributos';
        $sql_terms          = $wpdb->prefix . 'sql_atributos_terminos';
        $sql_aliases        = $wpdb->prefix . 'sql_atributos_aliases';
        $sql_product_attrs  = $wpdb->prefix . 'sql_product_atributos';
        $vocabulary         = $wpdb->prefix . 'seo_vocabulary';
        $object_vocabulary  = $wpdb->prefix . 'seo_object_vocabulary';
        $type_role_map      = $wpdb->prefix . 'seo_type_role_map';
        $operations         = $wpdb->prefix . 'seo_operations';
        $operation_changes  = $wpdb->prefix . 'seo_operation_changes';
        $faq                = $wpdb->prefix . 'seo_faq';
        $media_images       = $wpdb->prefix . 'seo_media_imagenes';
        $media_usages       = $wpdb->prefix . 'seo_media_usos';
        $provider_products  = $wpdb->prefix . 'seo_proveedores_productos';

        $queries = [];

        $queries[] = "CREATE TABLE {$relations} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type VARCHAR(50) NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            relation_type VARCHAR(80) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY source_lookup (source_type, source_id),
            KEY target_lookup (target_type, target_id),
            KEY relation_type (relation_type),
            KEY relation_endpoints (source_id, target_id)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$nodes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(50) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            seo_role VARCHAR(80) NOT NULL,
            keywords LONGTEXT NULL,
            title VARCHAR(255) NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_object_role (object_type, object_id, seo_role),
            KEY object_lookup (object_type, object_id),
            KEY seo_role (seo_role),
            KEY status (status)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$templates} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            template_key VARCHAR(191) NOT NULL,
            template_name VARCHAR(191) NOT NULL,
            template_file VARCHAR(191) NOT NULL,
            template_content LONGTEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            template_type VARCHAR(30) NOT NULL DEFAULT 'page',
            is_public TINYINT(1) NOT NULL DEFAULT 1,
            is_assignable TINYINT(1) NOT NULL DEFAULT 0,
            assignment_mode VARCHAR(20) NOT NULL DEFAULT 'automatic',
            display_order INT(11) NOT NULL DEFAULT 0,
            description TEXT NULL,
            device_variants_enabled TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY template_key (template_key),
            KEY is_active (is_active),
            KEY template_type (template_type)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$redirects} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            origin_url VARCHAR(255) NOT NULL,
            target_url VARCHAR(255) NOT NULL,
            status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
            hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_hit DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY origin_url (origin_url),
            KEY status_code (status_code)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$dictionary} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            palabra VARCHAR(191) NOT NULL,
            puntuacion INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY palabra (palabra),
            KEY puntuacion (puntuacion)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$attributes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            ambito VARCHAR(120) NOT NULL DEFAULT 'global',
            attribute_type VARCHAR(191) NOT NULL,
            attribute_value LONGTEXT NOT NULL,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY ambito (ambito),
            KEY attribute_type (attribute_type),
            KEY attr_lookup (attribute_type, product_id)
        ) {$charset_collate};";

        // Vocabulario canónico de atributos técnicos de producto.
        $queries[] = "CREATE TABLE {$sql_attributes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(100) NOT NULL,
            nombre VARCHAR(150) NOT NULL,
            grupo VARCHAR(100) NULL,
            tipo ENUM('texto','numero','boolean','termino','rango') NOT NULL DEFAULT 'texto',
            unidad_tipo VARCHAR(50) NULL,
            unidad_base VARCHAR(30) NULL,
            multiple TINYINT(1) NOT NULL DEFAULT 0,
            filtrable TINYINT(1) NOT NULL DEFAULT 0,
            visible TINYINT(1) NOT NULL DEFAULT 1,
            seo TINYINT(1) NOT NULL DEFAULT 1,
            orden INT NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_slug (slug)
        ) ENGINE=InnoDB {$charset_collate};";

        $queries[] = "CREATE TABLE {$sql_terms} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            atributo_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(150) NOT NULL,
            nombre VARCHAR(191) NOT NULL,
            orden INT NOT NULL DEFAULT 0,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_atributo_termino (atributo_id, slug),
            KEY idx_atributo (atributo_id)
        ) ENGINE=InnoDB {$charset_collate};";

        $queries[] = "CREATE TABLE {$sql_product_attrs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            atributo_id BIGINT UNSIGNED NOT NULL,
            termino_id BIGINT UNSIGNED NULL,
            valor_texto TEXT NULL,
            valor_numero DECIMAL(20,6) NULL,
            valor_numero_max DECIMAL(20,6) NULL,
            unidad VARCHAR(30) NULL,
            valor_original TEXT NULL,
            orden INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY idx_product (product_id),
            KEY idx_atributo (atributo_id),
            KEY idx_termino (termino_id),
            KEY idx_product_atributo (product_id, atributo_id)
        ) ENGINE=InnoDB {$charset_collate};";

        $queries[] = "CREATE TABLE {$sql_aliases} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            atributo_id BIGINT UNSIGNED NOT NULL,
            termino_id BIGINT UNSIGNED NULL,
            alias VARCHAR(191) NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_alias (alias),
            KEY idx_atributo_alias (atributo_id)
        ) ENGINE=InnoDB {$charset_collate};";

        /*
         * Canonical semantic vocabulary. This replaces the obsolete installer
         * schema based on term/related_terms/usage_type.
         */
        $queries[] = "CREATE TABLE {$vocabulary} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            semantic_group VARCHAR(100) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            label VARCHAR(255) NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            source VARCHAR(80) NOT NULL DEFAULT 'manual',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY group_slug (semantic_group, slug),
            KEY semantic_group (semantic_group),
            KEY parent_id (parent_id),
            KEY active (active)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$object_vocabulary} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(50) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            vocabulary_id BIGINT UNSIGNED NOT NULL,
            source VARCHAR(80) NOT NULL DEFAULT 'manual',
            confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY object_vocabulary (object_type, object_id, vocabulary_id),
            KEY object_lookup (object_type, object_id, status),
            KEY vocabulary_lookup (vocabulary_id, status),
            KEY source (source)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$type_role_map} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type_vocabulary_id BIGINT UNSIGNED NOT NULL,
            role_vocabulary_id BIGINT UNSIGNED NOT NULL,
            confidence DECIMAL(5,4) NOT NULL,
            source VARCHAR(60) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_type_vocabulary (type_vocabulary_id),
            KEY idx_role_vocabulary (role_vocabulary_id),
            KEY idx_active (active)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$operations} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operation_uuid CHAR(36) NOT NULL,
            operation_type VARCHAR(80) NOT NULL,
            operation_label VARCHAR(255) NOT NULL,
            source_module VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            rollback_status VARCHAR(30) NOT NULL DEFAULT 'not_available',
            rollbackable TINYINT(1) NOT NULL DEFAULT 0,
            risk_level VARCHAR(20) NOT NULL DEFAULT 'medium',
            audit_level VARCHAR(20) NOT NULL DEFAULT 'full',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            plugin_version VARCHAR(30) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            validated_at DATETIME NULL,
            previewed_at DATETIME NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            failed_at DATETIME NULL,
            rolled_back_at DATETIME NULL,
            rolled_back_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            affected_rows INT UNSIGNED NOT NULL DEFAULT 0,
            metadata LONGTEXT NULL,
            error_message LONGTEXT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY operation_uuid (operation_uuid),
            KEY status (status),
            KEY rollback_status (rollback_status),
            KEY operation_type (operation_type),
            KEY source_module (source_module),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$operation_changes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            operation_id BIGINT UNSIGNED NOT NULL,
            sequence_number INT UNSIGNED NOT NULL DEFAULT 0,
            entity_type VARCHAR(80) NOT NULL,
            entity_id VARCHAR(190) NOT NULL DEFAULT '',
            related_object_type VARCHAR(80) NOT NULL DEFAULT '',
            related_object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            table_name VARCHAR(190) NOT NULL DEFAULT '',
            action_type VARCHAR(30) NOT NULL,
            record_identity LONGTEXT NULL,
            before_data LONGTEXT NULL,
            after_data LONGTEXT NULL,
            before_hash CHAR(64) NULL,
            after_hash CHAR(64) NULL,
            rollback_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            rollback_error LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY operation_id (operation_id),
            KEY entity_type (entity_type),
            KEY entity_id (entity_id),
            KEY related_object (related_object_type, related_object_id),
            KEY action_type (action_type),
            KEY rollback_status (rollback_status)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$faq} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type SMALLINT UNSIGNED NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            question VARCHAR(255) NOT NULL,
            answer LONGTEXT NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            load_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            open_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY object_lookup (object_type, object_id, active),
            KEY sort_order (sort_order),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$media_images} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NULL,
            proveedor VARCHAR(190) NOT NULL DEFAULT '',
            url_origen LONGTEXT NOT NULL,
            url_hash CHAR(64) NOT NULL,
            content_hash CHAR(64) NULL,
            nombre_archivo VARCHAR(255) NULL,
            estado VARCHAR(30) NOT NULL DEFAULT 'disponible',
            fecha_creacion DATETIME NOT NULL,
            ultima_revision DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY url_hash (url_hash),
            KEY attachment_id (attachment_id),
            KEY content_hash (content_hash),
            KEY estado (estado)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$media_usages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            object_type VARCHAR(50) NOT NULL DEFAULT 'product',
            tipo_uso VARCHAR(30) NOT NULL DEFAULT 'featured',
            fecha DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY attachment_object_usage (attachment_id, object_id, tipo_uso),
            KEY object_lookup (object_type, object_id),
            KEY attachment_id (attachment_id),
            KEY tipo_uso (tipo_uso)
        ) {$charset_collate};";

        /*
         * Supplier staging catalogue. The supplier sync module may add future
         * columns additively; this is the complete schema used by the current
         * importer/sync code.
         */
        $queries[] = "CREATE TABLE {$provider_products} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            proveedor VARCHAR(190) NOT NULL,
            proveedor_id_externo VARCHAR(190) NOT NULL,
            sku VARCHAR(190) NOT NULL DEFAULT '',
            mpn VARCHAR(190) NOT NULL DEFAULT '',
            url_origen TEXT NULL,
            url_canonica TEXT NULL,
            nombre VARCHAR(255) NOT NULL,
            descripcion LONGTEXT NULL,
            marca VARCHAR(190) NOT NULL DEFAULT '',
            categoria_proveedor TEXT NULL,
            precio_sin_iva DECIMAL(18,6) NULL,
            precio_con_iva DECIMAL(18,6) NULL,
            iva_porcentaje DECIMAL(8,4) NULL,
            moneda VARCHAR(12) NOT NULL DEFAULT 'EUR',
            stock_estado VARCHAR(50) NOT NULL DEFAULT '',
            stock_cantidad DECIMAL(18,4) NULL,
            stock_texto VARCHAR(255) NOT NULL DEFAULT '',
            imagenes LONGTEXT NULL,
            hash_producto CHAR(64) NOT NULL DEFAULT '',
            hash_aplicado CHAR(64) NULL,
            snapshot_aplicado LONGTEXT NULL,
            raw_json LONGTEXT NULL,
            estado_seleccion VARCHAR(30) NOT NULL DEFAULT 'pendiente',
            estado_sincronizacion VARCHAR(30) NOT NULL DEFAULT 'legacy',
            object_id BIGINT UNSIGNED NULL,
            modo_imagenes VARCHAR(20) NOT NULL DEFAULT 'inherit',
            last_seen_run_id BIGINT UNSIGNED NULL,
            last_applied_run_id BIGINT UNSIGNED NULL,
            missing_since_run_id BIGINT UNSIGNED NULL,
            primera_importacion DATETIME NULL,
            ultima_importacion DATETIME NULL,
            ultima_sincronizacion DATETIME NULL,
            ultimo_error_sync TEXT NULL,
            cambios_detectados TEXT NULL,
            creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY proveedor_externo (proveedor, proveedor_id_externo),
            KEY proveedor_sku (proveedor, sku),
            KEY object_id (object_id),
            KEY estado_seleccion (estado_seleccion),
            KEY estado_sincronizacion (estado_sincronizacion),
            KEY last_seen_run_id (last_seen_run_id),
            KEY hash_producto (hash_producto)
        ) {$charset_collate};";

        foreach ($queries as $query) {
            dbDelta($query);
        }

        self::assert_required_tables_exist(
            $wpdb,
            [
                $relations,
                $nodes,
                $templates,
                $redirects,
                $dictionary,
                $attributes,
                $vocabulary,
                $object_vocabulary,
                $type_role_map,
                $operations,
                $operation_changes,
                $faq,
                $media_images,
                $media_usages,
                $provider_products,
            ]
        );
    }

    /**
     * Load modules that own additional schemas and run their idempotent
     * installers immediately. admin_init/plugins_loaded may already have fired
     * during a manual activation request, so waiting for those hooks is unsafe.
     */
    private static function install_module_schemas(wpdb $wpdb): void
    {
        self::load_schema_modules();
        self::prepare_module_repair_flags($wpdb);

        self::run_installer('seo_tm_ensure_schema');
        self::run_installer('seo_mail_ensure_schema');
        self::run_installer('seo_marketing_scan_ensure_tables');
        self::run_installer('seo_images_scan_install_schema');
        self::run_installer('seo_search_maybe_install_log_table');
        self::run_installer('seo_social_network_maybe_install_tables');
        self::run_installer('seo_google_install_tables', [true]);
        self::run_installer('seo_google_trends_maybe_install');
        self::run_installer('seo_landing_maybe_install');
        self::run_installer('seo_proveedores_asegurar_tabla_imagenes_externas');
        self::run_installer('seo_supplier_crawl_install_table');
        self::run_installer('seo_supplier_sync_ensure_schema');
    }

    private static function load_schema_modules(): void
    {
        $files = [
            'includes/template-manager.php',
            'includes/template-mail.php',
            'includes/seo-marketing.php',
            'includes/seo-image-scan.php',
            'includes/seo-search.php',
            'includes/seo-social-network.php',
            'includes/seo-google-info.php',
            'includes/seo-google-trends.php',
            'includes/seo-landing-pages.php',
            'includes/seo-import-suppliers.php',
        ];

        foreach ($files as $relative_file) {
            $file = SEO_SYSTEM_PATH . $relative_file;
            if (!is_readable($file)) {
                throw new RuntimeException('Missing schema module: ' . $relative_file);
            }
            require_once $file;
        }
    }

    /**
     * Some legacy module installers trust only their version option. If a table
     * is missing while that option says current, clear only the module schema
     * flag so its own installer can repair the missing table.
     */
    private static function prepare_module_repair_flags(wpdb $wpdb): void
    {
        if (!self::tables_exist($wpdb, [
            $wpdb->prefix . 'seo_sitemap_scans',
            $wpdb->prefix . 'seo_sitemap_scan_urls',
            $wpdb->prefix . 'seo_sitemap_inventory',
        ])) {
            delete_option('seo_marketing_scan_schema_version');
        }

        if (!self::table_exists($wpdb, $wpdb->prefix . 'seo_search_log')) {
            delete_option('seo_search_db_version');
        }

        if (!self::table_exists($wpdb, $wpdb->prefix . 'seo_social_publications')) {
            delete_option('seo_social_network_db_version');
        }

        if (!self::tables_exist($wpdb, [
            $wpdb->prefix . 'seo_landing_candidates',
            $wpdb->prefix . 'seo_landing_stats',
        ])) {
            delete_option('seo_landing_schema_version');
        }
    }

    private static function run_installer(string $function, array $arguments = []): void
    {
        if (!function_exists($function)) {
            throw new RuntimeException('Required installer function is missing: ' . $function);
        }

        $result = call_user_func_array($function, $arguments);
        if (is_wp_error($result)) {
            throw new RuntimeException($function . ': ' . $result->get_error_message());
        }
        if ($result === false) {
            throw new RuntimeException($function . ' returned false.');
        }
    }

    /**
     * Base data required for a useful clean installation.
     */
    private static function install_default_data(wpdb $wpdb): void
    {
        self::install_default_dictionary($wpdb);
        self::install_default_vocabulary($wpdb);
        self::install_default_templates($wpdb);

        if (function_exists('seo_tm_ensure_builtin_templates')) {
            seo_tm_ensure_builtin_templates();
        }
    }

    private static function install_default_dictionary(wpdb $wpdb): void
    {
        $table = $wpdb->prefix . 'seo_dictionari';
        $words = [
            'para', 'por', 'con', 'sin', 'sobre', 'entre', 'desde', 'hasta',
            'como', 'pero', 'porque', 'este', 'esta', 'estos', 'estas', 'ese',
            'esa', 'esos', 'esas', 'aquel', 'aquella', 'los', 'las', 'del',
            'una', 'uno', 'unos', 'unas', 'que', 'sus', 'mas', 'muy', 'son',
            'ser', 'uso', 'usar', 'puede', 'pueden', 'permite', 'ofrece',
            'ideal', 'sistema', 'sistemas', 'disenado', 'disenada', 'fabricado',
            'fabricada', 'incluye', 'alta', 'gran', 'mejor', 'tipo', 'modelo',
            'trabajo', 'trabajos', 'aplicaciones', 'caracteristicas', 'ventajas',
            'descripcion', 'categoria', 'categorias', 'hub', 'cluster', 'seo',
            'tambien', 'ademas', 'cada', 'todo', 'toda', 'todos', 'todas',
            'estan', 'tiene', 'tienen', 'forma', 'parte', 'nivel', 'principal',
        ];

        foreach (array_unique($words) as $word) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (palabra, puntuacion) VALUES (%s, %d)",
                    $word,
                    0
                )
            );
        }
    }

    /**
     * Roles are platform-level vocabulary needed by the current type -> role
     * model. Product types/applications/platforms are data, not installer data,
     * and are created/imported later.
     */
    private static function install_default_vocabulary(wpdb $wpdb): void
    {
        $table = $wpdb->prefix . 'seo_vocabulary';
        $roles = [
            'herramienta' => 'Herramienta',
            'equipamiento' => 'Equipamiento',
            'accesorio' => 'Accesorio',
            'consumible' => 'Consumible',
            'repuesto' => 'Repuesto',
        ];

        foreach ($roles as $slug => $label) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table} (semantic_group, slug, label, parent_id, source, active)
                     VALUES ('rol', %s, %s, NULL, 'installer', 1)
                     ON DUPLICATE KEY UPDATE label = VALUES(label), active = 1",
                    $slug,
                    $label
                )
            );
        }
    }

    private static function install_default_templates(wpdb $wpdb): void
    {
        $table = $wpdb->prefix . 'seo_templates';
        $templates = [
            ['template_key' => 'cluster', 'template_name' => 'Cluster', 'template_file' => 'template-cluster.php'],
            ['template_key' => 'hub_primary', 'template_name' => 'Hub primario', 'template_file' => 'template-hub-primary.php'],
            ['template_key' => 'hub_secondary', 'template_name' => 'Hub secundario', 'template_file' => 'template-hub-secondary.php'],
            ['template_key' => 'category', 'template_name' => 'Categoria', 'template_file' => 'template-category.php'],
            ['template_key' => 'product', 'template_name' => 'Producto', 'template_file' => 'template-product.php'],
            ['template_key' => 'search', 'template_name' => 'Busqueda', 'template_file' => 'template-search.php'],
            ['template_key' => 'cart', 'template_name' => 'Carrito', 'template_file' => 'template-cart.php'],
            ['template_key' => 'checkout', 'template_name' => 'Checkout', 'template_file' => 'template-checkout.php'],
            ['template_key' => '404', 'template_name' => '404', 'template_file' => 'template-404.php'],
        ];

        foreach ($templates as $template) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table} (template_key, template_name, template_file, is_active)
                     VALUES (%s, %s, %s, 1)
                     ON DUPLICATE KEY UPDATE
                        template_name = VALUES(template_name),
                        template_file = VALUES(template_file)",
                    $template['template_key'],
                    $template['template_name'],
                    $template['template_file']
                )
            );
        }
    }

    /**
     * All persistent custom tables expected from the current plugin package.
     */
    private static function required_tables(wpdb $wpdb): array
    {
        $names = [
            'seo_relations',
            'seo_nodes',
            'seo_templates',
            'seo_redirects',
            'seo_dictionari',
            'seo_attributes',
            'sql_atributos',
            'sql_atributos_terminos',
            'sql_atributos_aliases',
            'sql_product_atributos',
            'seo_vocabulary',
            'seo_object_vocabulary',
            'seo_type_role_map',
            'seo_operations',
            'seo_operation_changes',
            'seo_faq',
            'seo_media_imagenes',
            'seo_media_usos',
            'seo_proveedores_productos',
            'seo_mail_templates',
            'seo_sitemap_scans',
            'seo_sitemap_scan_urls',
            'seo_sitemap_inventory',
            'seo_image_scan_runs',
            'seo_image_scan_pages',
            'seo_image_scan_items',
            'seo_search_log',
            'seo_social_publications',
            'seo_google_sync_runs',
            'seo_google_search_data',
            'seo_google_trends',
            'seo_landing_candidates',
            'seo_landing_stats',
            'seo_supplier_images',
            'seo_supplier_crawl_queue',
            'seo_supplier_crawl_records',
        ];

        return array_map(
            static function (string $name) use ($wpdb): string {
                return $wpdb->prefix . $name;
            },
            $names
        );
    }

    private static function table_exists(wpdb $wpdb, string $table): bool
    {
        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        );
        return $found === $table;
    }

    private static function tables_exist(wpdb $wpdb, array $tables): bool
    {
        foreach ($tables as $table) {
            if (!self::table_exists($wpdb, (string) $table)) {
                return false;
            }
        }
        return true;
    }

    private static function assert_required_tables_exist(wpdb $wpdb, array $tables): void
    {
        $missing = [];
        foreach ($tables as $table) {
            if (!self::table_exists($wpdb, (string) $table)) {
                $missing[] = (string) $table;
            }
        }

        if ($missing) {
            throw new RuntimeException(
                'Required SEO Taxonomy tables were not created: ' . implode(', ', $missing)
            );
        }
    }
}
