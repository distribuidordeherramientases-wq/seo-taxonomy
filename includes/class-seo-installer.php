<?php
/**
 * Instalador inicial de SEO Taxonomy.
 *
 * Responsabilidades:
 * - Crear el esquema actual necesario para una instalación nueva.
 * - Insertar únicamente datos base idempotentes.
 * - Registrar la versión instalada de la base de datos.
 *
 * Las actualizaciones de instalaciones existentes se gestionan mediante
 * SEO_System_Updater y los archivos de includes/migrations/.
 */

defined('ABSPATH') || exit;

final class SEO_System_Installer
{
    private const DB_VERSION_OPTION = 'seo_system_db_version';
    private const INSTALL_STATUS_OPTION = 'seo_system_install_status';
    private const INSTALL_ERROR_OPTION = 'seo_system_install_error';

    /**
     * Ejecuta la instalación inicial.
     *
     * Puede ejecutarse más de una vez: dbDelta() y los inserts controlados
     * evitan duplicar el esquema o los datos base.
     */
    public static function install(): void
    {
        global $wpdb;

        if (!defined('SEO_SYSTEM_DB_VERSION')) {
            throw new RuntimeException(
                'SEO_SYSTEM_DB_VERSION no está definida.'
            );
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
            self::create_schema($wpdb);
            self::install_default_data($wpdb);

            update_option(
                self::DB_VERSION_OPTION,
                SEO_SYSTEM_DB_VERSION,
                false
            );

            update_option(
                self::INSTALL_STATUS_OPTION,
                [
                    'status'       => 'completed',
                    'completed_at' => current_time('mysql'),
                    'version'      => SEO_SYSTEM_DB_VERSION,
                ],
                false
            );

            /**
             * Permite que otros módulos creen sus propios datos iniciales.
             */
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

            error_log(
                sprintf(
                    '[SEO Taxonomy] Error de instalación: %s',
                    $exception->getMessage()
                )
            );

            throw $exception;
        }
    }

    /**
     * Crea las tablas base actuales.
     */
    private static function create_schema(wpdb $wpdb): void
    {
        $charset_collate = $wpdb->get_charset_collate();

        $relations = $wpdb->prefix . 'seo_relations';
        $nodes = $wpdb->prefix . 'seo_nodes';
        $templates = $wpdb->prefix . 'seo_templates';
        $redirects = $wpdb->prefix . 'seo_redirects';
        $dictionary = $wpdb->prefix . 'seo_dictionari';
        $attributes = $wpdb->prefix . 'seo_attributes';
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $operations = $wpdb->prefix . 'seo_operations';
        $operation_changes = $wpdb->prefix . 'seo_operation_changes';

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

        /*
         * VARCHAR en lugar de ENUM:
         * permite evolucionar object_type y seo_role sin migrar el tipo SQL.
         */
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
            KEY is_active (is_active)
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
            attribute_type VARCHAR(191) NOT NULL,
            attribute_value LONGTEXT NOT NULL,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY attribute_type (attribute_type),
            KEY attr_lookup (attribute_type, product_id)
        ) {$charset_collate};";

        $queries[] = "CREATE TABLE {$vocabulary} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            term VARCHAR(255) NOT NULL,
            semantic_group VARCHAR(100) NOT NULL,
            related_terms LONGTEXT NULL,
            usage_type VARCHAR(50) NULL,
            weight INT NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_term (term),
            KEY semantic_group (semantic_group),
            KEY usage_type (usage_type),
            KEY active (active)
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
                $operations,
                $operation_changes,
            ]
        );
    }

    /**
     * Inserta los datos mínimos para que el plugin arranque.
     */
    private static function install_default_data(wpdb $wpdb): void
    {
        self::install_default_dictionary($wpdb);
        self::install_default_templates($wpdb);
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
            'ideal', 'sistema', 'sistemas', 'diseñado', 'diseñada', 'fabricado',
            'fabricada', 'incluye', 'alta', 'gran', 'mejor', 'tipo', 'modelo',
            'trabajo', 'trabajos', 'aplicaciones', 'caracteristicas', 'ventajas',
            'descripcion', 'categoria', 'categorias', 'hub', 'cluster', 'seo',
            'tambien', 'ademas', 'cada', 'todo', 'toda', 'todos', 'todas',
            'estan', 'tiene', 'tienen', 'forma', 'parte', 'nivel', 'principal',
        ];

        foreach (array_unique($words) as $word) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (palabra, puntuacion)
                     VALUES (%s, %d)",
                    $word,
                    0
                )
            );
        }
    }

    private static function install_default_templates(wpdb $wpdb): void
    {
        $table = $wpdb->prefix . 'seo_templates';

        $templates = [
            [
                'template_key'  => 'cluster',
                'template_name' => 'Cluster',
                'template_file' => 'template-cluster.php',
            ],
            [
                'template_key'  => 'hub_primary',
                'template_name' => 'Hub primario',
                'template_file' => 'template-hub-primary.php',
            ],
            [
                'template_key'  => 'hub_secondary',
                'template_name' => 'Hub secundario',
                'template_file' => 'template-hub-secondary.php',
            ],
            [
                'template_key'  => 'category',
                'template_name' => 'Categoría',
                'template_file' => 'template-category.php',
            ],
            [
                'template_key'  => 'product',
                'template_name' => 'Producto',
                'template_file' => 'template-product.php',
            ],
            [
                'template_key'  => 'search',
                'template_name' => 'Búsqueda',
                'template_file' => 'template-search.php',
            ],
            [
                'template_key'  => 'cart',
                'template_name' => 'Carrito',
                'template_file' => 'template-cart.php',
            ],
            [
                'template_key'  => 'checkout',
                'template_name' => 'Checkout',
                'template_file' => 'template-checkout.php',
            ],
            [
                'template_key'  => '404',
                'template_name' => '404',
                'template_file' => 'template-404.php',
            ],
        ];

        foreach ($templates as $template) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table}
                        (template_key, template_name, template_file, is_active)
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
     * Falla de forma explícita si dbDelta() no consiguió crear una tabla.
     */
    private static function assert_required_tables_exist(
        wpdb $wpdb,
        array $tables
    ): void {
        foreach ($tables as $table) {
            $found = $wpdb->get_var(
                $wpdb->prepare(
                    'SHOW TABLES LIKE %s',
                    $wpdb->esc_like($table)
                )
            );

            if ($found !== $table) {
                throw new RuntimeException(
                    sprintf(
                        'No se pudo crear o localizar la tabla %s.',
                        $table
                    )
                );
            }
        }
    }
}
