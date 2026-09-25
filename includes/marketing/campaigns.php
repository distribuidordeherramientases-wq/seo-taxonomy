<?php
/**
 * SEO Taxonomy - Marketing / Campañas.
 *
 * V1.2: creacion, organizacion temporal, recurrencia basica, productos con
 * precio de campana e importacion/exportacion portable del calendario.
 * El intercambio de campanas NO incluye productos, precios ni creatividades.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_MARKETING_CAMPAIGNS_DB_VERSION')) {
    define('SEO_MARKETING_CAMPAIGNS_DB_VERSION', 2);
}
if (!defined('SEO_MARKETING_CAMPAIGNS_DB_OPTION')) {
    define('SEO_MARKETING_CAMPAIGNS_DB_OPTION', 'seo_marketing_campaigns_db_version');
}
if (!defined('SEO_MARKETING_CAMPAIGNS_ACTION_GROUP')) {
    define('SEO_MARKETING_CAMPAIGNS_ACTION_GROUP', 'seo-marketing-campaigns');
}

/**
 * Tablas del modulo.
 *
 * @return array{campaigns:string,products:string}
 */
function seo_marketing_campaigns_tables()
{
    global $wpdb;

    return array(
        'campaigns' => $wpdb->prefix . 'seo_marketing_campaigns',
        'products'  => $wpdb->prefix . 'seo_marketing_campaign_products',
    );
}

/**
 * Crea o actualiza las tablas del modulo.
 */
function seo_marketing_campaigns_maybe_install_tables()
{
    $installed = (int) get_option(SEO_MARKETING_CAMPAIGNS_DB_OPTION, 0);
    if ($installed >= SEO_MARKETING_CAMPAIGNS_DB_VERSION) {
        return;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $tables = seo_marketing_campaigns_tables();
    $charset_collate = $wpdb->get_charset_collate();

    $sql_campaigns = "CREATE TABLE {$tables['campaigns']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        campaign_key varchar(191) NOT NULL DEFAULT '',
        series_key varchar(191) NOT NULL DEFAULT '',
        parent_campaign_id bigint(20) unsigned NULL,
        name varchar(191) NOT NULL,
        edition_label varchar(191) NOT NULL DEFAULT '',
        campaign_type varchar(24) NOT NULL DEFAULT 'calendar',
        recurrence varchar(20) NOT NULL DEFAULT 'none',
        start_at datetime NOT NULL,
        end_at datetime NOT NULL,
        is_enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
        created_by bigint(20) unsigned NOT NULL DEFAULT 0,
        updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY campaign_key (campaign_key),
        KEY series_key (series_key),
        KEY campaign_type (campaign_type),
        KEY start_at (start_at),
        KEY end_at (end_at),
        KEY enabled_dates (is_enabled,start_at,end_at),
        KEY parent_campaign_id (parent_campaign_id)
    ) {$charset_collate};";

    $sql_products = "CREATE TABLE {$tables['products']} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        campaign_id bigint(20) unsigned NOT NULL,
        product_id bigint(20) unsigned NOT NULL,
        campaign_price decimal(19,4) NOT NULL DEFAULT 0,
        position int(10) unsigned NOT NULL DEFAULT 0,
        snapshot_taken tinyint(1) unsigned NOT NULL DEFAULT 0,
        original_sale_price varchar(32) NULL,
        original_sale_from datetime NULL,
        original_sale_to datetime NULL,
        applied tinyint(1) unsigned NOT NULL DEFAULT 0,
        applied_at datetime NULL,
        restored_at datetime NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY campaign_product (campaign_id,product_id),
        KEY product_id (product_id),
        KEY campaign_applied (campaign_id,applied)
    ) {$charset_collate};";

    dbDelta($sql_campaigns);
    dbDelta($sql_products);

    seo_marketing_campaigns_backfill_identity_columns();

    update_option(SEO_MARKETING_CAMPAIGNS_DB_OPTION, SEO_MARKETING_CAMPAIGNS_DB_VERSION, false);
}
add_action('admin_init', 'seo_marketing_campaigns_maybe_install_tables', 15);


/**
 * Tipos de campana admitidos.
 *
 * @return array<string,string>
 */
function seo_marketing_campaigns_types()
{
    return array(
        'calendar'     => 'Calendario comercial',
        'tactical'     => 'Tactica / puntual',
        'star_product' => 'Producto estrella',
    );
}

/**
 * Normaliza el tipo de campana.
 *
 * @param string $type
 * @return string
 */
function seo_marketing_campaigns_normalize_type($type)
{
    $type = sanitize_key((string) $type);
    return isset(seo_marketing_campaigns_types()[$type]) ? $type : 'calendar';
}

/**
 * Etiqueta del tipo de campana.
 *
 * @param string $type
 * @return string
 */
function seo_marketing_campaigns_type_label($type)
{
    $types = seo_marketing_campaigns_types();
    $type = seo_marketing_campaigns_normalize_type($type);
    return $types[$type];
}

/**
 * Construye una clave portable de campana.
 *
 * @param string $name
 * @param string $edition_label
 * @param string $start_at
 * @param string $series_key
 * @return string
 */
function seo_marketing_campaigns_build_key($name, $edition_label, $start_at, $series_key = '')
{
    $series_key = sanitize_title((string) $series_key);
    if ($series_key === '') {
        $series_key = sanitize_title((string) $name);
    }
    if ($series_key === '') {
        $series_key = 'campaign';
    }

    $edition_part = sanitize_title((string) $edition_label);
    if ($edition_part === '' && $start_at !== '') {
        $ts = seo_marketing_campaigns_timestamp($start_at);
        if ($ts > 0) {
            $edition_part = wp_date('Y', $ts, wp_timezone());
        }
    }
    if ($edition_part === '') {
        $edition_part = 'edition';
    }

    return sanitize_title($series_key . '-' . $edition_part);
}

/**
 * Garantiza una campaign_key unica dentro de la instalacion.
 *
 * @param string $base
 * @param int    $exclude_id
 * @return string
 */
function seo_marketing_campaigns_unique_key($base, $exclude_id = 0)
{
    global $wpdb;
    $tables = seo_marketing_campaigns_tables();

    $base = sanitize_title((string) $base);
    if ($base === '') {
        $base = 'campaign';
    }

    $candidate = $base;
    $suffix = 2;

    while (true) {
        if ($exclude_id > 0) {
            $found = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$tables['campaigns']} WHERE campaign_key = %s AND id <> %d LIMIT 1",
                    $candidate,
                    $exclude_id
                )
            );
        } else {
            $found = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$tables['campaigns']} WHERE campaign_key = %s LIMIT 1",
                    $candidate
                )
            );
        }

        if ($found <= 0) {
            return $candidate;
        }

        $candidate = $base . '-' . $suffix;
        $suffix++;
    }
}

/**
 * Completa identificadores de instalaciones creadas antes de V1.2.
 */
function seo_marketing_campaigns_backfill_identity_columns()
{
    global $wpdb;
    $tables = seo_marketing_campaigns_tables();

    $columns = (array) $wpdb->get_col("SHOW COLUMNS FROM {$tables['campaigns']}", 0);
    if (!in_array('campaign_key', $columns, true) || !in_array('campaign_type', $columns, true)) {
        return;
    }

    $wpdb->query(
        "UPDATE {$tables['campaigns']} SET campaign_type = 'calendar' WHERE campaign_type = '' OR campaign_type IS NULL"
    );

    $rows = (array) $wpdb->get_results(
        "SELECT id, campaign_key, series_key, name, edition_label, start_at FROM {$tables['campaigns']} ORDER BY id ASC"
    );

    foreach ($rows as $row) {
        $series_key = sanitize_title((string) $row->series_key);
        if ($series_key === '') {
            $series_key = sanitize_title((string) $row->name);
        }
        if ($series_key === '') {
            $series_key = 'campaign-' . absint($row->id);
        }

        $data = array();
        $formats = array();
        if ((string) $row->series_key !== $series_key) {
            $data['series_key'] = $series_key;
            $formats[] = '%s';
        }

        if ((string) $row->campaign_key === '') {
            $base = seo_marketing_campaigns_build_key(
                (string) $row->name,
                (string) $row->edition_label,
                (string) $row->start_at,
                $series_key
            );
            $data['campaign_key'] = seo_marketing_campaigns_unique_key($base, absint($row->id));
            $formats[] = '%s';
        }

        if ($data) {
            $wpdb->update(
                $tables['campaigns'],
                $data,
                array('id' => absint($row->id)),
                $formats,
                array('%d')
            );
        }
    }
}

/**
 * Expone las tablas al Data Layer para auditoria/consulta.
 *
 * @param array $tables
 * @return array
 */
function seo_marketing_campaigns_register_data_layer_tables($tables)
{
    $tables = is_array($tables) ? $tables : array();
    $campaign_tables = seo_marketing_campaigns_tables();

    $tables['marketing_campaigns'] = array(
        'table'       => $campaign_tables['campaigns'],
        'primary_key' => array('id'),
        'entity_type' => 'marketing_campaign',
    );
    $tables['marketing_campaign_products'] = array(
        'table'       => $campaign_tables['products'],
        'primary_key' => array('id'),
        'entity_type' => 'marketing_campaign_product',
    );

    return $tables;
}
add_filter('seo_data_layer_tables', 'seo_marketing_campaigns_register_data_layer_tables');


/**
 * Registra la franja publica de campanas en el Gestor de Plantillas.
 *
 * Se hace desde este modulo para que el gestor siga siendo generico: basta con
 * que la fila exista en wp_seo_templates para que aparezca en Archivos de
 * plantilla y Disponibilidad/activacion, incluidas sus variantes por dispositivo.
 */
function seo_marketing_campaigns_register_public_template()
{
    if (version_compare((string) get_option('seo_marketing_campaign_template_registry_version', '0'), '1', '>=')) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'seo_templates';

    $table_exists = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
    );
    if ($table_exists !== $table) {
        return;
    }

    $exists = (string) $wpdb->get_var(
        $wpdb->prepare("SELECT template_key FROM {$table} WHERE template_key = %s LIMIT 1", 'campaign_strip')
    );

    if ($exists === '') {
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (template_key, template_name, template_file, is_active) VALUES (%s, %s, %s, 1)",
                'campaign_strip',
                'Campañas promocionales',
                'template-campaign.php'
            )
        );
    }

    $columns = (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
    $data = array();
    $formats = array();

    $structural = array(
        'template_type'           => array('system', '%s'),
        'assignment_mode'         => array('automatic', '%s'),
        'is_assignable'           => array(0, '%d'),
        'device_variants_enabled' => array(1, '%d'),
        'description'             => array('Franja global de campañas activas mostrada bajo la navegación principal.', '%s'),
    );

    foreach ($structural as $column => $definition) {
        if (in_array($column, $columns, true)) {
            $data[$column] = $definition[0];
            $formats[] = $definition[1];
        }
    }

    if ($data) {
        $wpdb->update(
            $table,
            $data,
            array('template_key' => 'campaign_strip'),
            $formats,
            array('%s')
        );
    }

    $required_structural_columns = array_keys($structural);
    if (!array_diff($required_structural_columns, $columns)) {
        update_option('seo_marketing_campaign_template_registry_version', '1', false);
    }
}
add_action('admin_init', 'seo_marketing_campaigns_register_public_template', 16);


/**
 * Indica si la franja de campañas está habilitada en el Gestor de Plantillas.
 *
 * Si la tabla o el registro aún no existen, se mantiene el comportamiento
 * compatible y se permite renderizar. Si el registro existe y está inactivo,
 * el header no carga la franja.
 *
 * @return bool
 */
function seo_marketing_campaigns_public_template_is_enabled()
{
    global $wpdb;

    $table = $wpdb->prefix . 'seo_templates';
    $table_exists = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
    );

    if ($table_exists !== $table) {
        return true;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT is_active FROM {$table} WHERE template_key = %s LIMIT 1",
            'campaign_strip'
        )
    );

    if (!$row) {
        return true;
    }

    return (int) $row->is_active === 1;
}

/**
 * Devuelve las campanas activas y sus productos listos para la franja publica.
 *
 * @return array<int,array{campaign:object,products:array<int,array<string,mixed>>}>
 */
function seo_marketing_campaigns_get_public_active()
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = array();

    if (!function_exists('wc_get_product')) {
        return $cache;
    }

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();

    foreach (array('campaigns', 'products') as $required_table) {
        $table_name = $tables[$required_table];
        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))
        );
        if ($table_exists !== $table_name) {
            return $cache;
        }
    }

    $now = seo_marketing_campaigns_now_mysql();

    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                c.id AS campaign_id,
                c.series_key,
                c.name,
                c.edition_label,
                c.start_at,
                c.end_at,
                cp.product_id,
                cp.campaign_price,
                cp.position,
                cp.id AS campaign_product_id
             FROM {$tables['campaigns']} c
             INNER JOIN {$tables['products']} cp ON cp.campaign_id = c.id
             WHERE c.is_enabled = 1
               AND c.start_at <= %s
               AND c.end_at >= %s
             ORDER BY c.start_at ASC, c.id ASC, cp.position ASC, cp.id ASC",
            $now,
            $now
        )
    );

    if (!$rows) {
        return $cache;
    }

    $grouped = array();

    foreach ($rows as $row) {
        $campaign_id = absint($row->campaign_id);
        $product_id = absint($row->product_id);
        $product = $product_id > 0 ? wc_get_product($product_id) : null;

        if (!$product || !$product->is_visible() || !$product->is_in_stock()) {
            continue;
        }

        $campaign_price = (float) $row->campaign_price;
        if ($campaign_price <= 0) {
            continue;
        }

        if (!isset($grouped[$campaign_id])) {
            $grouped[$campaign_id] = array(
                'campaign' => (object) array(
                    'id'            => $campaign_id,
                    'series_key'    => sanitize_key((string) $row->series_key),
                    'name'          => (string) $row->name,
                    'edition_label' => (string) $row->edition_label,
                    'start_at'      => (string) $row->start_at,
                    'end_at'        => (string) $row->end_at,
                ),
                'products' => array(),
            );
        }

        $regular_raw = $product->get_regular_price('edit');
        $regular_raw = $regular_raw !== '' ? (float) $regular_raw : (float) $product->get_price('edit');

        $campaign_display = function_exists('wc_get_price_to_display')
            ? (float) wc_get_price_to_display($product, array('price' => $campaign_price))
            : $campaign_price;
        $regular_display = function_exists('wc_get_price_to_display')
            ? (float) wc_get_price_to_display($product, array('price' => $regular_raw))
            : $regular_raw;

        $discount = 0;
        if ($regular_display > 0 && $campaign_display > 0 && $campaign_display < $regular_display) {
            $discount = (int) round((1 - ($campaign_display / $regular_display)) * 100);
        }

        $grouped[$campaign_id]['products'][] = array(
            'id'                => $product_id,
            'product'           => $product,
            'name'              => $product->get_name(),
            'url'               => get_permalink($product_id),
            'campaign_price'    => $campaign_display,
            'regular_price'     => $regular_display,
            'discount_percent'  => max(0, $discount),
            'position'          => (int) $row->position,
        );
    }

    foreach ($grouped as $campaign_id => $item) {
        if (empty($item['products'])) {
            unset($grouped[$campaign_id]);
        }
    }

    $cache = array_values($grouped);
    return (array) apply_filters('seo_marketing_campaigns_public_active', $cache);
}

/**
 * Devuelve campanas habilitadas actuales o futuras con sus productos para
 * consumidores internos como Redes Sociales. A diferencia de la franja publica,
 * incluye campanas que todavia no han comenzado para poder preparar su agenda.
 *
 * @return array<int,array{campaign:object,products:array<int,array<string,mixed>>}>
 */
function seo_marketing_campaigns_get_social_catalog()
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = array();
    if (!function_exists('wc_get_product')) {
        return $cache;
    }

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    foreach (array('campaigns', 'products') as $required_table) {
        $table_name = $tables[$required_table];
        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table_name))
        );
        if ($table_exists !== $table_name) {
            return $cache;
        }
    }

    $now = seo_marketing_campaigns_now_mysql();
    $rows = (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                c.id AS campaign_id,
                c.campaign_key,
                c.series_key,
                c.name,
                c.edition_label,
                c.campaign_type,
                c.start_at,
                c.end_at,
                cp.product_id,
                cp.campaign_price,
                cp.position,
                cp.id AS campaign_product_id
             FROM {$tables['campaigns']} c
             INNER JOIN {$tables['products']} cp ON cp.campaign_id = c.id
             WHERE c.is_enabled = 1
               AND c.end_at >= %s
             ORDER BY c.start_at ASC, c.id ASC, cp.position ASC, cp.id ASC",
            $now
        )
    );

    if (!$rows) {
        return $cache;
    }

    $grouped = array();
    foreach ($rows as $row) {
        $campaign_id = absint($row->campaign_id);
        $product_id = absint($row->product_id);
        $product = $product_id > 0 ? wc_get_product($product_id) : null;

        // Solo planificamos productos publicos y disponibles. Se vuelve a validar
        // al ejecutar la publicacion por si cambia el stock o la visibilidad.
        if (!$product || !$product->is_visible() || !$product->is_in_stock()) {
            continue;
        }

        $campaign_price = (float) $row->campaign_price;
        if ($campaign_price <= 0) {
            continue;
        }

        if (!isset($grouped[$campaign_id])) {
            $state = ((string) $row->start_at <= $now) ? 'current' : 'future';
            $grouped[$campaign_id] = array(
                'campaign' => (object) array(
                    'id'            => $campaign_id,
                    'campaign_key'  => sanitize_title((string) $row->campaign_key),
                    'series_key'    => sanitize_title((string) $row->series_key),
                    'name'          => (string) $row->name,
                    'edition_label' => (string) $row->edition_label,
                    'campaign_type' => seo_marketing_campaigns_normalize_type((string) $row->campaign_type),
                    'start_at'      => (string) $row->start_at,
                    'end_at'        => (string) $row->end_at,
                    'state'         => $state,
                ),
                'products' => array(),
            );
        }

        $regular_raw = $product->get_regular_price('edit');
        $regular_raw = $regular_raw !== '' ? (float) $regular_raw : (float) $product->get_price('edit');
        $campaign_display = function_exists('wc_get_price_to_display')
            ? (float) wc_get_price_to_display($product, array('price' => $campaign_price))
            : $campaign_price;
        $regular_display = function_exists('wc_get_price_to_display')
            ? (float) wc_get_price_to_display($product, array('price' => $regular_raw))
            : $regular_raw;

        $discount = 0;
        if ($regular_display > 0 && $campaign_display > 0 && $campaign_display < $regular_display) {
            $discount = (int) round((1 - ($campaign_display / $regular_display)) * 100);
        }

        $grouped[$campaign_id]['products'][] = array(
            'id'               => $product_id,
            'product'          => $product,
            'name'             => $product->get_name(),
            'url'              => get_permalink($product_id),
            'image_url'        => wp_get_attachment_image_url($product->get_image_id(), 'full') ?: '',
            'campaign_price'   => $campaign_display,
            'regular_price'    => $regular_display,
            'discount_percent' => max(0, $discount),
            'position'         => (int) $row->position,
        );
    }

    foreach ($grouped as $campaign_id => $item) {
        if (empty($item['products'])) {
            unset($grouped[$campaign_id]);
        }
    }

    $cache = array_values($grouped);
    return (array) apply_filters('seo_marketing_campaigns_social_catalog', $cache);
}

/**
 * Recupera una campana del catalogo social por ID.
 *
 * @param int $campaign_id
 * @return array|null
 */
function seo_marketing_campaigns_get_social_campaign($campaign_id)
{
    $campaign_id = absint($campaign_id);
    if (!$campaign_id) {
        return null;
    }
    foreach (seo_marketing_campaigns_get_social_catalog() as $item) {
        if (!empty($item['campaign']->id) && absint($item['campaign']->id) === $campaign_id) {
            return $item;
        }
    }
    return null;
}

/**
 * URL de la pestana Campañas.
 *
 * @param array $args
 * @return string
 */
function seo_marketing_campaigns_admin_url($args = array())
{
    return add_query_arg(
        array_merge(
            array(
                'page' => 'seo-menu-marketing',
                'tab'  => 'campaigns',
            ),
            is_array($args) ? $args : array()
        ),
        admin_url('admin.php')
    );
}

/**
 * Fecha actual MySQL en la zona horaria de WordPress.
 *
 * @return string
 */
function seo_marketing_campaigns_now_mysql()
{
    return current_time('mysql');
}

/**
 * Convierte datetime-local a MySQL en la zona horaria de WordPress.
 *
 * @param string $value
 * @return string
 */
function seo_marketing_campaigns_parse_local_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $timezone = wp_timezone();
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $timezone);
    if (!$date) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s', $value, $timezone);
    }

    return $date ? $date->format('Y-m-d H:i:s') : '';
}


/**
 * Convierte fechas de importacion (ISO 8601, MySQL o datetime-local) a MySQL
 * en la zona horaria configurada en WordPress.
 *
 * @param string $value
 * @return string
 */
function seo_marketing_campaigns_parse_import_datetime($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $local = seo_marketing_campaigns_parse_local_datetime($value);
    if ($local !== '') {
        return $local;
    }

    $timezone = wp_timezone();
    $formats = array('!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d');
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);
        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    try {
        $date = new DateTimeImmutable($value, $timezone);
        return $date->setTimezone($timezone)->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return '';
    }
}

/**
 * Fecha portable ISO 8601 en la zona horaria de WordPress.
 *
 * @param string $mysql
 * @return string
 */
function seo_marketing_campaigns_datetime_iso($mysql)
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $mysql, wp_timezone());
    return $date ? $date->format(DATE_ATOM) : '';
}

/**
 * Convierte valores habituales de importacion a booleano entero.
 *
 * @param mixed $value
 * @return int
 */
function seo_marketing_campaigns_import_bool($value)
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (is_int($value) || is_float($value)) {
        return ((int) $value) !== 0 ? 1 : 0;
    }
    $value = strtolower(trim((string) $value));
    return in_array($value, array('1', 'true', 'yes', 'si', 'sí', 'on', 'enabled', 'activo'), true) ? 1 : 0;
}

/**
 * Convierte MySQL a datetime-local.
 *
 * @param string|null $value
 * @return string
 */
function seo_marketing_campaigns_datetime_local($value)
{
    if (!$value) {
        return '';
    }
    return str_replace(' ', 'T', substr((string) $value, 0, 16));
}

/**
 * Timestamp Unix de una fecha MySQL local.
 *
 * @param string $mysql
 * @return int
 */
function seo_marketing_campaigns_timestamp($mysql)
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $mysql, wp_timezone());
    return $date ? $date->getTimestamp() : 0;
}

/**
 * Estado temporal derivado.
 *
 * @param object $campaign
 * @return string current|future|past|disabled
 */
function seo_marketing_campaigns_temporal_state($campaign)
{
    if (empty($campaign->is_enabled)) {
        return 'disabled';
    }

    $now = seo_marketing_campaigns_now_mysql();
    if ((string) $campaign->start_at > $now) {
        return 'future';
    }
    if ((string) $campaign->end_at < $now) {
        return 'past';
    }
    return 'current';
}

/**
 * Etiqueta de recurrencia.
 *
 * @param string $recurrence
 * @return string
 */
function seo_marketing_campaigns_recurrence_label($recurrence)
{
    $labels = array(
        'none'   => 'No recurrente',
        'annual' => 'Anual',
        'manual' => 'Nueva edicion manual',
    );
    return isset($labels[$recurrence]) ? $labels[$recurrence] : 'No recurrente';
}

/**
 * Recupera una campaña.
 *
 * @param int $campaign_id
 * @return object|null
 */
function seo_marketing_campaigns_get($campaign_id)
{
    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    $campaign_id = absint($campaign_id);

    if (!$campaign_id) {
        return null;
    }

    return $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$tables['campaigns']} WHERE id = %d LIMIT 1", $campaign_id)
    );
}

/**
 * Busca solapamiento de un producto con otra campaña activa/habilitada.
 *
 * @param int    $product_id
 * @param int    $campaign_id
 * @param string $start_at
 * @param string $end_at
 * @return object|null
 */
function seo_marketing_campaigns_find_product_overlap($product_id, $campaign_id, $start_at, $end_at)
{
    global $wpdb;
    $tables = seo_marketing_campaigns_tables();

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT c.id, c.name, c.edition_label, c.start_at, c.end_at
             FROM {$tables['products']} cp
             INNER JOIN {$tables['campaigns']} c ON c.id = cp.campaign_id
             WHERE cp.product_id = %d
               AND c.id <> %d
               AND c.is_enabled = 1
               AND c.start_at <= %s
               AND c.end_at >= %s
             ORDER BY c.start_at ASC
             LIMIT 1",
            absint($product_id),
            absint($campaign_id),
            $end_at,
            $start_at
        )
    );
}

/**
 * Elimina acciones programadas de una campaña.
 *
 * @param int $campaign_id
 */
function seo_marketing_campaigns_unschedule($campaign_id)
{
    $campaign_id = absint($campaign_id);
    $args = array($campaign_id);

    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('seo_marketing_campaign_apply', $args, SEO_MARKETING_CAMPAIGNS_ACTION_GROUP);
        as_unschedule_all_actions('seo_marketing_campaign_restore', $args, SEO_MARKETING_CAMPAIGNS_ACTION_GROUP);
    }

    wp_clear_scheduled_hook('seo_marketing_campaign_apply', $args);
    wp_clear_scheduled_hook('seo_marketing_campaign_restore', $args);
}

/**
 * Programa aplicacion y restauracion de una campaña.
 *
 * @param int $campaign_id
 */
function seo_marketing_campaigns_reschedule($campaign_id)
{
    $campaign = seo_marketing_campaigns_get($campaign_id);
    if (!$campaign) {
        return;
    }

    seo_marketing_campaigns_unschedule($campaign_id);

    if (empty($campaign->is_enabled)) {
        seo_marketing_campaign_restore($campaign_id);
        return;
    }

    $now = time();
    $start = seo_marketing_campaigns_timestamp($campaign->start_at);
    $end = seo_marketing_campaigns_timestamp($campaign->end_at);

    if (!$start || !$end || $end <= $start) {
        return;
    }

    if ($end < $now) {
        seo_marketing_campaign_restore($campaign_id);
        return;
    }

    if ($start <= $now) {
        seo_marketing_campaign_apply($campaign_id);
    } else {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($start, 'seo_marketing_campaign_apply', array($campaign_id), SEO_MARKETING_CAMPAIGNS_ACTION_GROUP, true);
        } else {
            wp_schedule_single_event($start, 'seo_marketing_campaign_apply', array($campaign_id));
        }
    }

    if (function_exists('as_schedule_single_action')) {
        as_schedule_single_action($end + 60, 'seo_marketing_campaign_restore', array($campaign_id), SEO_MARKETING_CAMPAIGNS_ACTION_GROUP, true);
    } else {
        wp_schedule_single_event($end + 60, 'seo_marketing_campaign_restore', array($campaign_id));
    }
}

/**
 * Serializa una fecha de oferta WooCommerce.
 *
 * @param mixed $date
 * @return string|null
 */
function seo_marketing_campaigns_wc_date_to_mysql($date)
{
    if (!$date) {
        return null;
    }
    if (is_object($date) && method_exists($date, 'date')) {
        return $date->date('Y-m-d H:i:s');
    }
    return null;
}

/**
 * Aplica el precio y periodo de una campaña activa.
 *
 * @param int $campaign_id
 */
function seo_marketing_campaign_apply($campaign_id)
{
    if (!function_exists('wc_get_product')) {
        return;
    }

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    $campaign = seo_marketing_campaigns_get($campaign_id);

    if (!$campaign || empty($campaign->is_enabled)) {
        return;
    }

    $now = seo_marketing_campaigns_now_mysql();
    if ($campaign->start_at > $now || $campaign->end_at < $now) {
        return;
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$tables['products']} WHERE campaign_id = %d ORDER BY position ASC, id ASC",
            absint($campaign_id)
        )
    );

    foreach ((array) $rows as $row) {
        $product = wc_get_product(absint($row->product_id));
        if (!$product || $product->is_type('variable')) {
            continue;
        }

        if (empty($row->snapshot_taken)) {
            $wpdb->update(
                $tables['products'],
                array(
                    'snapshot_taken'    => 1,
                    'original_sale_price' => (string) $product->get_sale_price('edit'),
                    'original_sale_from'  => seo_marketing_campaigns_wc_date_to_mysql($product->get_date_on_sale_from('edit')),
                    'original_sale_to'    => seo_marketing_campaigns_wc_date_to_mysql($product->get_date_on_sale_to('edit')),
                    'updated_at'          => $now,
                ),
                array('id' => absint($row->id)),
                array('%d', '%s', '%s', '%s', '%s'),
                array('%d')
            );
        }

        $product->set_sale_price(wc_format_decimal($row->campaign_price));
        $product->set_date_on_sale_from((string) $campaign->start_at);
        $product->set_date_on_sale_to((string) $campaign->end_at);
        $product->save();

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product->get_id());
        }

        $wpdb->update(
            $tables['products'],
            array(
                'applied'     => 1,
                'applied_at'  => $now,
                'restored_at' => null,
                'updated_at'  => $now,
            ),
            array('id' => absint($row->id)),
            array('%d', '%s', '%s', '%s'),
            array('%d')
        );
    }
}
add_action('seo_marketing_campaign_apply', 'seo_marketing_campaign_apply', 10, 1);

/**
 * Restaura un producto concreto si todavia mantiene el precio de campaña.
 *
 * @param object $row
 * @param object $campaign
 */
function seo_marketing_campaigns_restore_product_row($row, $campaign)
{
    if (!function_exists('wc_get_product') || !$row || !$campaign) {
        return;
    }

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    $product = wc_get_product(absint($row->product_id));
    $now = seo_marketing_campaigns_now_mysql();

    if (!$product) {
        return;
    }

    $current_sale = (string) $product->get_sale_price('edit');
    $campaign_sale = function_exists('wc_format_decimal')
        ? (string) wc_format_decimal($row->campaign_price)
        : (string) $row->campaign_price;

    // Si alguien cambio manualmente el precio durante la campaña, no se pisa.
    if ($current_sale === $campaign_sale || (float) $current_sale === (float) $campaign_sale) {
        $product->set_sale_price((string) $row->original_sale_price);
        $product->set_date_on_sale_from($row->original_sale_from ? (string) $row->original_sale_from : null);
        $product->set_date_on_sale_to($row->original_sale_to ? (string) $row->original_sale_to : null);
        $product->save();

        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product->get_id());
        }
    }

    $wpdb->update(
        $tables['products'],
        array(
            'applied'     => 0,
            'restored_at' => $now,
            'updated_at'  => $now,
        ),
        array('id' => absint($row->id)),
        array('%d', '%s', '%s'),
        array('%d')
    );
}

/**
 * Restaura los precios anteriores de una campaña finalizada/deshabilitada.
 *
 * @param int $campaign_id
 */
function seo_marketing_campaign_restore($campaign_id)
{
    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    $campaign = seo_marketing_campaigns_get($campaign_id);

    if (!$campaign) {
        return;
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$tables['products']}
             WHERE campaign_id = %d AND snapshot_taken = 1 AND applied = 1",
            absint($campaign_id)
        )
    );

    foreach ((array) $rows as $row) {
        seo_marketing_campaigns_restore_product_row($row, $campaign);
    }
}
add_action('seo_marketing_campaign_restore', 'seo_marketing_campaign_restore', 10, 1);

/**
 * Redireccion con aviso.
 *
 * @param string $message
 * @param string $type
 * @param array  $args
 */
function seo_marketing_campaigns_redirect_notice($message, $type = 'success', $args = array())
{
    $args = array_merge(
        is_array($args) ? $args : array(),
        array(
            'campaign_notice'      => rawurlencode((string) $message),
            'campaign_notice_type' => sanitize_key($type),
        )
    );

    wp_safe_redirect(seo_marketing_campaigns_admin_url($args));
    exit;
}

/**
 * Guarda una campaña.
 */
function seo_marketing_campaigns_handle_save()
{
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para gestionar campañas.');
    }
    check_admin_referer('seo_marketing_campaign_save');

    seo_marketing_campaigns_maybe_install_tables();

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();

    $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
    $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $edition_label = isset($_POST['edition_label']) ? sanitize_text_field(wp_unslash($_POST['edition_label'])) : '';
    $campaign_type = isset($_POST['campaign_type']) ? seo_marketing_campaigns_normalize_type(wp_unslash($_POST['campaign_type'])) : 'calendar';
    $recurrence = isset($_POST['recurrence']) ? sanitize_key(wp_unslash($_POST['recurrence'])) : 'none';
    $start_at = seo_marketing_campaigns_parse_local_datetime(isset($_POST['start_at']) ? wp_unslash($_POST['start_at']) : '');
    $end_at = seo_marketing_campaigns_parse_local_datetime(isset($_POST['end_at']) ? wp_unslash($_POST['end_at']) : '');
    $is_enabled = !empty($_POST['is_enabled']) ? 1 : 0;

    if (!in_array($recurrence, array('none', 'annual', 'manual'), true)) {
        $recurrence = 'none';
    }

    if ($name === '' || $start_at === '' || $end_at === '') {
        seo_marketing_campaigns_redirect_notice('Nombre, inicio y fin son obligatorios.', 'error', array('campaign_id' => $campaign_id));
    }
    if ($end_at <= $start_at) {
        seo_marketing_campaigns_redirect_notice('La fecha de fin debe ser posterior al inicio.', 'error', array('campaign_id' => $campaign_id));
    }

    $existing = $campaign_id ? seo_marketing_campaigns_get($campaign_id) : null;
    if ($campaign_id && !$existing) {
        seo_marketing_campaigns_redirect_notice('La campaña indicada no existe.', 'error');
    }

    // Si se cambian fechas, impide solapar cualquiera de sus productos con otra campaña.
    if ($campaign_id) {
        $product_ids = $wpdb->get_col(
            $wpdb->prepare("SELECT product_id FROM {$tables['products']} WHERE campaign_id = %d", $campaign_id)
        );
        foreach ((array) $product_ids as $product_id) {
            $overlap = seo_marketing_campaigns_find_product_overlap($product_id, $campaign_id, $start_at, $end_at);
            if ($overlap) {
                $product = function_exists('wc_get_product') ? wc_get_product(absint($product_id)) : null;
                $product_name = $product ? $product->get_name() : ('Producto #' . absint($product_id));
                seo_marketing_campaigns_redirect_notice(
                    sprintf('No se pueden guardar las fechas: %s ya participa en la campaña "%s" durante un periodo solapado.', $product_name, $overlap->name),
                    'error',
                    array('campaign_id' => $campaign_id)
                );
            }
        }
    }

    $now = seo_marketing_campaigns_now_mysql();
    $series_key = $existing ? (string) $existing->series_key : sanitize_title($name);
    if ($series_key === '') {
        $series_key = 'campaign-' . wp_generate_uuid4();
    }
    if ($edition_label === '') {
        $edition_label = wp_date('Y', seo_marketing_campaigns_timestamp($start_at), wp_timezone());
    }

    if ($existing && !empty($existing->campaign_key)) {
        $campaign_key = (string) $existing->campaign_key;
    } else {
        $campaign_key = seo_marketing_campaigns_unique_key(
            seo_marketing_campaigns_build_key($name, $edition_label, $start_at, $series_key),
            $campaign_id
        );
    }

    $data = array(
        'campaign_key'  => $campaign_key,
        'series_key'    => $series_key,
        'name'          => $name,
        'edition_label' => $edition_label,
        'campaign_type' => $campaign_type,
        'recurrence'    => $recurrence,
        'start_at'      => $start_at,
        'end_at'        => $end_at,
        'is_enabled'    => $is_enabled,
        'updated_by'    => get_current_user_id(),
        'updated_at'    => $now,
    );

    if ($campaign_id) {
        $wpdb->update(
            $tables['campaigns'],
            $data,
            array('id' => $campaign_id),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s'),
            array('%d')
        );
    } else {
        $data['created_by'] = get_current_user_id();
        $data['created_at'] = $now;
        $wpdb->insert(
            $tables['campaigns'],
            $data,
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s')
        );
        $campaign_id = (int) $wpdb->insert_id;
    }

    seo_marketing_campaigns_reschedule($campaign_id);
    seo_marketing_campaigns_redirect_notice('Campaña guardada.', 'success', array('campaign_id' => $campaign_id));
}
add_action('admin_post_seo_marketing_campaign_save', 'seo_marketing_campaigns_handle_save');

/**
 * Anade productos seleccionados a una campaña.
 */
function seo_marketing_campaigns_handle_add_products()
{
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para gestionar campañas.');
    }
    check_admin_referer('seo_marketing_campaign_add_products');

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
    $campaign = seo_marketing_campaigns_get($campaign_id);
    if (!$campaign) {
        seo_marketing_campaigns_redirect_notice('La campaña no existe.', 'error');
    }

    $selected = isset($_POST['selected_products']) ? array_map('absint', (array) wp_unslash($_POST['selected_products'])) : array();
    $prices = isset($_POST['campaign_price']) ? (array) wp_unslash($_POST['campaign_price']) : array();

    if (empty($selected)) {
        seo_marketing_campaigns_redirect_notice('Selecciona al menos un producto.', 'warning', array('campaign_id' => $campaign_id));
    }

    $added = 0;
    $errors = array();
    $position = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COALESCE(MAX(position),0) FROM {$tables['products']} WHERE campaign_id = %d", $campaign_id)
    );

    foreach ($selected as $product_id) {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) {
            $errors[] = 'Producto #' . $product_id . ': no existe.';
            continue;
        }
        if ($product->is_type('variable')) {
            $errors[] = $product->get_name() . ': los productos variables se dejan fuera de la V1.';
            continue;
        }

        $raw_price = isset($prices[$product_id]) ? $prices[$product_id] : '';
        $campaign_price = function_exists('wc_format_decimal') ? wc_format_decimal($raw_price) : (float) $raw_price;
        if ((float) $campaign_price <= 0) {
            $errors[] = $product->get_name() . ': indica un precio de campaña valido.';
            continue;
        }

        $overlap = seo_marketing_campaigns_find_product_overlap($product_id, $campaign_id, $campaign->start_at, $campaign->end_at);
        if ($overlap) {
            $errors[] = $product->get_name() . ': coincide en fechas con "' . $overlap->name . '".';
            continue;
        }

        $position++;
        $now = seo_marketing_campaigns_now_mysql();
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$tables['products']}
                    (campaign_id,product_id,campaign_price,position,created_at,updated_at)
                 VALUES (%d,%d,%s,%d,%s,%s)
                 ON DUPLICATE KEY UPDATE campaign_price=VALUES(campaign_price), updated_at=VALUES(updated_at)",
                $campaign_id,
                $product_id,
                $campaign_price,
                $position,
                $now,
                $now
            )
        );
        if (false !== $inserted) {
            $added++;
        }
    }

    seo_marketing_campaigns_reschedule($campaign_id);

    $message = sprintf('%d producto(s) anadido(s) o actualizados.', $added);
    if ($errors) {
        $message .= ' Incidencias: ' . implode(' | ', array_slice($errors, 0, 5));
    }
    seo_marketing_campaigns_redirect_notice($message, $errors ? 'warning' : 'success', array('campaign_id' => $campaign_id));
}
add_action('admin_post_seo_marketing_campaign_add_products', 'seo_marketing_campaigns_handle_add_products');

/**
 * Actualiza precios o quita productos de una campaña.
 */
function seo_marketing_campaigns_handle_update_products()
{
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para gestionar campañas.');
    }
    check_admin_referer('seo_marketing_campaign_update_products');

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
    $campaign = seo_marketing_campaigns_get($campaign_id);
    if (!$campaign) {
        seo_marketing_campaigns_redirect_notice('La campaña no existe.', 'error');
    }

    $prices = isset($_POST['campaign_price']) ? (array) wp_unslash($_POST['campaign_price']) : array();
    $remove = isset($_POST['remove_product']) ? array_map('absint', (array) wp_unslash($_POST['remove_product'])) : array();
    $remove_map = array_fill_keys($remove, true);
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$tables['products']} WHERE campaign_id = %d", $campaign_id)
    );

    $updated = 0;
    $removed = 0;
    foreach ((array) $rows as $row) {
        $product_id = absint($row->product_id);
        if (isset($remove_map[$product_id])) {
            if (!empty($row->applied)) {
                seo_marketing_campaigns_restore_product_row($row, $campaign);
            }
            $wpdb->delete($tables['products'], array('id' => absint($row->id)), array('%d'));
            $removed++;
            continue;
        }

        if (isset($prices[$product_id])) {
            $price = function_exists('wc_format_decimal') ? wc_format_decimal($prices[$product_id]) : (float) $prices[$product_id];
            if ((float) $price > 0) {
                $wpdb->update(
                    $tables['products'],
                    array(
                        'campaign_price' => $price,
                        'updated_at'     => seo_marketing_campaigns_now_mysql(),
                    ),
                    array('id' => absint($row->id)),
                    array('%s', '%s'),
                    array('%d')
                );
                $updated++;
            }
        }
    }

    seo_marketing_campaigns_reschedule($campaign_id);
    seo_marketing_campaigns_redirect_notice(
        sprintf('Productos actualizados: %d. Eliminados de la campaña: %d.', $updated, $removed),
        'success',
        array('campaign_id' => $campaign_id)
    );
}
add_action('admin_post_seo_marketing_campaign_update_products', 'seo_marketing_campaigns_handle_update_products');

/**
 * Crea una nueva edicion de la misma serie, sin copiar productos.
 */
function seo_marketing_campaigns_handle_duplicate()
{
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para gestionar campañas.');
    }
    check_admin_referer('seo_marketing_campaign_duplicate');

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    $campaign_id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
    $campaign = seo_marketing_campaigns_get($campaign_id);
    if (!$campaign) {
        seo_marketing_campaigns_redirect_notice('La campaña no existe.', 'error');
    }

    $start = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $campaign->start_at, wp_timezone());
    $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $campaign->end_at, wp_timezone());
    if (!$start || !$end) {
        seo_marketing_campaigns_redirect_notice('No se pueden calcular las fechas de la nueva edicion.', 'error', array('campaign_id' => $campaign_id));
    }

    $new_start = $start->modify('+1 year');
    $new_end = $end->modify('+1 year');
    $now = seo_marketing_campaigns_now_mysql();

    $wpdb->insert(
        $tables['campaigns'],
        array(
            'campaign_key'       => seo_marketing_campaigns_unique_key(
                seo_marketing_campaigns_build_key(
                    (string) $campaign->name,
                    $new_start->format('Y'),
                    $new_start->format('Y-m-d H:i:s'),
                    (string) $campaign->series_key
                )
            ),
            'series_key'         => $campaign->series_key,
            'parent_campaign_id' => $campaign_id,
            'name'               => $campaign->name,
            'edition_label'      => $new_start->format('Y'),
            'campaign_type'      => isset($campaign->campaign_type) ? seo_marketing_campaigns_normalize_type($campaign->campaign_type) : 'calendar',
            'recurrence'         => $campaign->recurrence,
            'start_at'           => $new_start->format('Y-m-d H:i:s'),
            'end_at'             => $new_end->format('Y-m-d H:i:s'),
            'is_enabled'         => 1,
            'created_by'         => get_current_user_id(),
            'updated_by'         => get_current_user_id(),
            'created_at'         => $now,
            'updated_at'         => $now,
        ),
        array('%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s')
    );

    $new_id = (int) $wpdb->insert_id;
    seo_marketing_campaigns_reschedule($new_id);
    seo_marketing_campaigns_redirect_notice(
        'Nueva edicion creada sin productos. Ya puedes seleccionar los productos y precios de esta edicion.',
        'success',
        array('campaign_id' => $new_id)
    );
}
add_action('admin_post_seo_marketing_campaign_duplicate', 'seo_marketing_campaigns_handle_duplicate');


/**
 * Filas portables del calendario de campanas. No incluye productos ni precios.
 *
 * @return array<int,array<string,mixed>>
 */
function seo_marketing_campaigns_export_rows()
{
    global $wpdb;
    $tables = seo_marketing_campaigns_tables();

    $rows = (array) $wpdb->get_results(
        "SELECT campaign_key, series_key, name, edition_label, campaign_type, recurrence, start_at, end_at, is_enabled
         FROM {$tables['campaigns']}
         ORDER BY start_at ASC, id ASC"
    );

    $out = array();
    foreach ($rows as $row) {
        $out[] = array(
            'campaign_key'  => (string) $row->campaign_key,
            'series_key'    => (string) $row->series_key,
            'name'          => (string) $row->name,
            'edition'       => (string) $row->edition_label,
            'campaign_type' => seo_marketing_campaigns_normalize_type((string) $row->campaign_type),
            'start_at'      => seo_marketing_campaigns_datetime_iso((string) $row->start_at),
            'end_at'        => seo_marketing_campaigns_datetime_iso((string) $row->end_at),
            'recurrence'    => (string) $row->recurrence,
            'enabled'       => (int) $row->is_enabled === 1,
        );
    }

    return $out;
}

/**
 * Descarga JSON o CSV del calendario. Nunca exporta productos/precios.
 */
function seo_marketing_campaigns_handle_export()
{
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para exportar campañas.');
    }
    check_admin_referer('seo_marketing_campaigns_export');

    seo_marketing_campaigns_maybe_install_tables();

    $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : 'json';
    if (!in_array($format, array('json', 'csv'), true)) {
        $format = 'json';
    }

    $rows = seo_marketing_campaigns_export_rows();
    $stamp = wp_date('Ymd-His', time(), wp_timezone());
    $filename = 'seo-campaigns-' . $stamp . '.' . $format;

    nocache_headers();
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            wp_die('No se pudo abrir la salida CSV.');
        }
        fputcsv($out, array('campaign_key', 'series_key', 'name', 'edition', 'campaign_type', 'start_at', 'end_at', 'recurrence', 'enabled'), ';');
        foreach ($rows as $row) {
            fputcsv($out, array(
                $row['campaign_key'],
                $row['series_key'],
                $row['name'],
                $row['edition'],
                $row['campaign_type'],
                $row['start_at'],
                $row['end_at'],
                $row['recurrence'],
                $row['enabled'] ? '1' : '0',
            ), ';');
        }
        fclose($out);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    $payload = array(
        'schema_version' => '1.0',
        'kind'           => 'seo_marketing_campaign_calendar',
        'generated_at'   => wp_date(DATE_ATOM, time(), wp_timezone()),
        'timezone'       => wp_timezone_string(),
        'campaigns'      => $rows,
    );
    echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
add_action('admin_post_seo_marketing_campaigns_export', 'seo_marketing_campaigns_handle_export');

/**
 * Lee un CSV de campanas.
 *
 * @param string $path
 * @return array|WP_Error
 */
function seo_marketing_campaigns_import_read_csv($path)
{
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return new WP_Error('campaign_csv_open', 'No se pudo abrir el CSV.');
    }

    $first = fgets($handle);
    if ($first === false) {
        fclose($handle);
        return new WP_Error('campaign_csv_empty', 'El CSV esta vacio.');
    }

    $semicolon = substr_count($first, ';');
    $comma = substr_count($first, ',');
    $delimiter = $semicolon >= $comma ? ';' : ',';
    rewind($handle);

    $header = fgetcsv($handle, 0, $delimiter);
    if (!is_array($header)) {
        fclose($handle);
        return new WP_Error('campaign_csv_header', 'No se pudo leer la cabecera CSV.');
    }

    $header = array_map(static function ($value) {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);
        return sanitize_key(trim($value));
    }, $header);

    $rows = array();
    while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (!array_filter($values, static function ($value) { return trim((string) $value) !== ''; })) {
            continue;
        }
        $row = array();
        foreach ($header as $index => $key) {
            if ($key !== '') {
                $row[$key] = isset($values[$index]) ? $values[$index] : '';
            }
        }
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

/**
 * Lee JSON o CSV subido y devuelve las campanas declaradas.
 *
 * @param string $path
 * @param string $extension
 * @return array|WP_Error
 */
function seo_marketing_campaigns_import_read_file($path, $extension)
{
    if ($extension === 'csv') {
        return seo_marketing_campaigns_import_read_csv($path);
    }

    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return new WP_Error('campaign_json_empty', 'El JSON esta vacio o no se puede leer.');
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return new WP_Error('campaign_json_invalid', 'El JSON no es valido.');
    }

    if (isset($data['campaigns']) && is_array($data['campaigns'])) {
        return $data['campaigns'];
    }

    $is_list = empty($data) || array_keys($data) === range(0, count($data) - 1);
    if ($is_list) {
        return $data;
    }

    return new WP_Error('campaign_json_shape', 'El JSON debe contener un array "campaigns".');
}

/**
 * Comprueba que una actualizacion de fechas no provoque solapes en productos
 * que ya estan asociados localmente a esa campana.
 *
 * @param int    $campaign_id
 * @param string $start_at
 * @param string $end_at
 * @return WP_Error|true
 */
function seo_marketing_campaigns_import_validate_existing_products($campaign_id, $start_at, $end_at)
{
    global $wpdb;
    $tables = seo_marketing_campaigns_tables();

    if ($campaign_id <= 0) {
        return true;
    }

    $product_ids = (array) $wpdb->get_col(
        $wpdb->prepare("SELECT product_id FROM {$tables['products']} WHERE campaign_id = %d", $campaign_id)
    );

    foreach ($product_ids as $product_id) {
        $overlap = seo_marketing_campaigns_find_product_overlap(absint($product_id), $campaign_id, $start_at, $end_at);
        if ($overlap) {
            return new WP_Error(
                'campaign_import_overlap',
                sprintf(
                    'La campana no se actualiza porque uno de sus productos solaparia con "%s".',
                    (string) $overlap->name
                )
            );
        }
    }

    return true;
}

/**
 * Normaliza una fila portable.
 *
 * @param array $row
 * @return array|WP_Error
 */
function seo_marketing_campaigns_import_normalize_row($row)
{
    if (!is_array($row)) {
        return new WP_Error('campaign_import_row', 'Fila de campana invalida.');
    }

    $name = sanitize_text_field((string) ($row['name'] ?? ''));
    $edition = sanitize_text_field((string) ($row['edition'] ?? ($row['edition_label'] ?? '')));
    $start_at = seo_marketing_campaigns_parse_import_datetime((string) ($row['start_at'] ?? ''));
    $end_at = seo_marketing_campaigns_parse_import_datetime((string) ($row['end_at'] ?? ''));

    if ($name === '' || $start_at === '' || $end_at === '') {
        return new WP_Error('campaign_import_required', 'Nombre, inicio y fin son obligatorios.');
    }
    if ($end_at <= $start_at) {
        return new WP_Error('campaign_import_dates', 'La fecha de fin debe ser posterior al inicio.');
    }

    if ($edition === '') {
        $edition = wp_date('Y', seo_marketing_campaigns_timestamp($start_at), wp_timezone());
    }

    $series_key = sanitize_title((string) ($row['series_key'] ?? ''));
    if ($series_key === '') {
        $series_key = sanitize_title($name);
    }
    if ($series_key === '') {
        $series_key = 'campaign';
    }

    $campaign_key = sanitize_title((string) ($row['campaign_key'] ?? ''));
    if ($campaign_key === '') {
        $campaign_key = seo_marketing_campaigns_build_key($name, $edition, $start_at, $series_key);
    }

    $recurrence = sanitize_key((string) ($row['recurrence'] ?? 'none'));
    if (!in_array($recurrence, array('none', 'annual', 'manual'), true)) {
        $recurrence = 'none';
    }

    return array(
        'campaign_key'  => $campaign_key,
        'series_key'    => $series_key,
        'name'          => $name,
        'edition_label' => $edition,
        'campaign_type' => seo_marketing_campaigns_normalize_type((string) ($row['campaign_type'] ?? 'calendar')),
        'recurrence'    => $recurrence,
        'start_at'      => $start_at,
        'end_at'        => $end_at,
        'is_enabled'    => seo_marketing_campaigns_import_bool($row['enabled'] ?? ($row['is_enabled'] ?? 1)),
    );
}

/**
 * Importa el calendario. Upsert por campaign_key y nunca toca productos.
 */
function seo_marketing_campaigns_handle_import()
{
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para importar campañas.');
    }
    check_admin_referer('seo_marketing_campaigns_import');

    seo_marketing_campaigns_maybe_install_tables();

    $file = isset($_FILES['campaign_import_file']) ? $_FILES['campaign_import_file'] : array();
    if (empty($file['tmp_name']) || !isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        seo_marketing_campaigns_redirect_notice('No se pudo recibir el archivo de importacion.', 'error');
    }
    if (!empty($file['size']) && (int) $file['size'] > 2 * 1024 * 1024) {
        seo_marketing_campaigns_redirect_notice('El archivo supera el limite de 2 MB.', 'error');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, array('json', 'csv'), true)) {
        seo_marketing_campaigns_redirect_notice('Solo se admiten archivos JSON o CSV.', 'error');
    }

    $rows = seo_marketing_campaigns_import_read_file($file['tmp_name'], $extension);
    if (is_wp_error($rows)) {
        seo_marketing_campaigns_redirect_notice($rows->get_error_message(), 'error');
    }

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $errors = array();
    $seen_keys = array();
    $now = seo_marketing_campaigns_now_mysql();

    foreach ((array) $rows as $index => $raw_row) {
        $row = seo_marketing_campaigns_import_normalize_row($raw_row);
        if (is_wp_error($row)) {
            $skipped++;
            $errors[] = 'Fila ' . ((int) $index + 1) . ': ' . $row->get_error_message();
            continue;
        }

        $key = (string) $row['campaign_key'];
        if (isset($seen_keys[$key])) {
            $skipped++;
            $errors[] = 'Fila ' . ((int) $index + 1) . ': campaign_key duplicada dentro del archivo (' . $key . ').';
            continue;
        }
        $seen_keys[$key] = true;

        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$tables['campaigns']} WHERE campaign_key = %s LIMIT 1", $key)
        );

        if ($existing) {
            $validation = seo_marketing_campaigns_import_validate_existing_products(
                absint($existing->id),
                $row['start_at'],
                $row['end_at']
            );
            if (is_wp_error($validation)) {
                $skipped++;
                $errors[] = $key . ': ' . $validation->get_error_message();
                continue;
            }

            $data = $row;
            $data['updated_by'] = get_current_user_id();
            $data['updated_at'] = $now;

            $result = $wpdb->update(
                $tables['campaigns'],
                $data,
                array('id' => absint($existing->id)),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s'),
                array('%d')
            );
            if ($result === false) {
                $skipped++;
                $errors[] = $key . ': ' . $wpdb->last_error;
                continue;
            }
            seo_marketing_campaigns_reschedule(absint($existing->id));
            $updated++;
            continue;
        }

        $row['campaign_key'] = seo_marketing_campaigns_unique_key($key);
        $row['created_by'] = get_current_user_id();
        $row['updated_by'] = get_current_user_id();
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        $result = $wpdb->insert(
            $tables['campaigns'],
            $row,
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s')
        );
        if ($result === false) {
            $skipped++;
            $errors[] = $key . ': ' . $wpdb->last_error;
            continue;
        }
        $new_id = (int) $wpdb->insert_id;
        seo_marketing_campaigns_reschedule($new_id);
        $created++;
    }

    $message = sprintf('Importacion terminada: %d nuevas, %d actualizadas, %d omitidas. Productos y precios no se han modificado.', $created, $updated, $skipped);
    if ($errors) {
        $message .= ' Incidencias: ' . implode(' | ', array_slice($errors, 0, 4));
    }

    seo_marketing_campaigns_redirect_notice($message, $errors ? 'warning' : 'success');
}
add_action('admin_post_seo_marketing_campaigns_import', 'seo_marketing_campaigns_handle_import');

/**
 * Bloque de importacion/exportacion del calendario.
 */
function seo_marketing_campaigns_render_import_export()
{
    $json_url = wp_nonce_url(
        add_query_arg(
            array('action' => 'seo_marketing_campaigns_export', 'format' => 'json'),
            admin_url('admin-post.php')
        ),
        'seo_marketing_campaigns_export'
    );
    $csv_url = wp_nonce_url(
        add_query_arg(
            array('action' => 'seo_marketing_campaigns_export', 'format' => 'csv'),
            admin_url('admin-post.php')
        ),
        'seo_marketing_campaigns_export'
    );

    echo '<section class="seo-campaigns-card seo-campaign-import-export">';
    echo '<h3 style="margin-top:0;">Importar / Exportar calendario</h3>';
    echo '<p>Intercambia solo la estructura de las campanas: identificadores, nombre, edicion, tipo, recurrencia, inicio, fin y estado habilitado. <strong>No incluye ni modifica productos, precios, banners ni creatividades.</strong></p>';
    echo '<div class="seo-campaign-ie-grid">';
    echo '<div><h4>Exportar</h4><p class="description">JSON es el formato canonico. CSV facilita revisar o editar el calendario en una hoja de calculo.</p><p><a class="button button-primary" href="' . esc_url($json_url) . '">Exportar JSON</a> <a class="button" href="' . esc_url($csv_url) . '">Exportar CSV</a></p></div>';
    echo '<div><h4>Importar</h4><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
    echo '<input type="hidden" name="action" value="seo_marketing_campaigns_import">';
    wp_nonce_field('seo_marketing_campaigns_import');
    echo '<input type="file" name="campaign_import_file" accept=".json,.csv,application/json,text/csv" required>';
    echo '<p><button type="submit" class="button button-primary">Importar calendario</button></p>';
    echo '<p class="description">La importacion hace alta/actualizacion por <code>campaign_key</code>. Nunca borra campanas que no aparezcan en el archivo.</p>';
    echo '</form></div>';
    echo '</div></section>';
}

/**
 * Muestra avisos del modulo.
 */
function seo_marketing_campaigns_render_notice()
{
    if (empty($_GET['campaign_notice'])) {
        return;
    }

    $message = sanitize_text_field(wp_unslash(rawurldecode((string) $_GET['campaign_notice'])));
    $type = isset($_GET['campaign_notice_type']) ? sanitize_key(wp_unslash($_GET['campaign_notice_type'])) : 'success';
    if (!in_array($type, array('success', 'warning', 'error', 'info'), true)) {
        $type = 'info';
    }

    echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($message) . '</p></div>';
}

/**
 * Estilos de la V1 de Campañas.
 */
function seo_marketing_campaigns_render_styles()
{
    echo '<style>
        .seo-campaigns-header{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:18px;}
        .seo-campaigns-header h2{margin:0 0 4px;}.seo-campaigns-header p{margin:0;color:#646970;}
        .seo-campaigns-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;align-items:start;}
        .seo-campaigns-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.03);}
        .seo-campaigns-card h3{margin:0 0 12px;display:flex;justify-content:space-between;gap:12px;align-items:center;}
        .seo-campaign-count{display:inline-grid;place-items:center;min-width:28px;height:28px;border-radius:999px;background:#f0f0f1;font-size:12px;}
        .seo-campaign-row{padding:12px 0;border-top:1px solid #f0f0f1;}.seo-campaign-row:first-of-type{border-top:0;}
        .seo-campaign-row-title{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;}
        .seo-campaign-meta{margin-top:5px;color:#646970;font-size:12px;line-height:1.55;}
        .seo-campaign-editor{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.4fr);gap:18px;align-items:start;}
        .seo-campaign-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}.seo-campaign-field-full{grid-column:1/-1;}
        .seo-campaign-field label{display:block;font-weight:600;margin-bottom:5px;}.seo-campaign-field input,.seo-campaign-field select{width:100%;}
        .seo-campaign-products table{width:100%;border-collapse:collapse;}.seo-campaign-products th,.seo-campaign-products td{padding:9px 8px;border-bottom:1px solid #f0f0f1;text-align:left;vertical-align:middle;}
        .seo-campaign-products th{font-size:12px;text-transform:uppercase;color:#50575e;}.seo-campaign-products input[type=number]{width:110px;}
        .seo-campaign-search{display:flex;gap:8px;align-items:end;margin-bottom:14px;}.seo-campaign-search .seo-campaign-field{flex:1;}
        .seo-campaign-status{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;}
        .seo-campaign-status-current{background:#dff4e7;color:#1d6b43;}.seo-campaign-status-future{background:#e7f1ff;color:#135e96;}.seo-campaign-status-past{background:#f0f0f1;color:#50575e;}.seo-campaign-status-disabled{background:#fff4d6;color:#7a5200;}
        .seo-campaign-ie-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;}
        .seo-campaign-import-export{margin-top:18px;}
        @media(max-width:1100px){.seo-campaigns-grid,.seo-campaign-editor{grid-template-columns:1fr;}}
        @media(max-width:700px){.seo-campaign-form-grid{grid-template-columns:1fr;}.seo-campaign-field-full{grid-column:auto;}.seo-campaign-search{display:block;}.seo-campaign-search .button{margin-top:8px;}}
    </style>';
}

/**
 * Obtiene productos para el buscador sin cargar el catalogo completo.
 *
 * @param string $search
 * @return array
 */
function seo_marketing_campaigns_search_products($search)
{
    global $wpdb;
    $search = trim((string) $search);
    if ($search === '') {
        return array();
    }

    $like = '%' . $wpdb->esc_like($search) . '%';
    return (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, sku.meta_value AS sku
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} sku
               ON sku.post_id = p.ID AND sku.meta_key = '_sku'
             WHERE p.post_type = 'product'
               AND p.post_status NOT IN ('trash','auto-draft')
               AND (p.post_title LIKE %s OR sku.meta_value LIKE %s)
             ORDER BY p.post_title ASC
             LIMIT 40",
            $like,
            $like
        )
    );
}

/**
 * Renderiza lista resumida de una categoria temporal.
 *
 * @param string $title
 * @param array  $campaigns
 * @param string $state
 */
function seo_marketing_campaigns_render_bucket($title, $campaigns, $state)
{
    $status_labels = array('current' => 'En curso', 'future' => 'Proxima', 'past' => 'Finalizada', 'disabled' => 'Deshabilitada');
    echo '<section class="seo-campaigns-card">';
    echo '<h3><span>' . esc_html($title) . '</span><span class="seo-campaign-count">' . esc_html((string) count($campaigns)) . '</span></h3>';

    if (!$campaigns) {
        echo '<p style="color:#646970;">No hay campañas en este grupo.</p>';
    }

    foreach ($campaigns as $campaign) {
        $edit_url = seo_marketing_campaigns_admin_url(array('campaign_id' => absint($campaign->id)));
        echo '<div class="seo-campaign-row">';
        echo '<div class="seo-campaign-row-title"><div><strong>' . esc_html($campaign->name) . '</strong>';
        if ($campaign->edition_label !== '') {
            echo ' <span style="color:#646970;">' . esc_html($campaign->edition_label) . '</span>';
        }
        echo '</div><span class="seo-campaign-status seo-campaign-status-' . esc_attr($state) . '">' . esc_html($status_labels[$state]) . '</span></div>';
        echo '<div class="seo-campaign-meta">';
        echo esc_html(wp_date('d/m/Y H:i', seo_marketing_campaigns_timestamp($campaign->start_at), wp_timezone()));
        echo ' - ';
        echo esc_html(wp_date('d/m/Y H:i', seo_marketing_campaigns_timestamp($campaign->end_at), wp_timezone()));
        echo '<br>' . esc_html(number_format_i18n((int) $campaign->product_count)) . ' producto(s) · ' . esc_html(seo_marketing_campaigns_type_label(isset($campaign->campaign_type) ? $campaign->campaign_type : 'calendar')) . ' · ' . esc_html(seo_marketing_campaigns_recurrence_label($campaign->recurrence));
        echo '</div>';
        echo '<p style="margin:8px 0 0;"><a class="button button-small" href="' . esc_url($edit_url) . '">Abrir campaña</a></p>';
        echo '</div>';
    }

    echo '</section>';
}

/**
 * Pantalla de listado temporal.
 */
function seo_marketing_campaigns_render_dashboard()
{
    global $wpdb;
    $tables = seo_marketing_campaigns_tables();

    $rows = $wpdb->get_results(
        "SELECT c.*, COUNT(cp.id) AS product_count
         FROM {$tables['campaigns']} c
         LEFT JOIN {$tables['products']} cp ON cp.campaign_id = c.id
         GROUP BY c.id
         ORDER BY c.start_at DESC, c.id DESC"
    );

    $buckets = array('current' => array(), 'future' => array(), 'past' => array(), 'disabled' => array());
    foreach ((array) $rows as $row) {
        $state = seo_marketing_campaigns_temporal_state($row);
        if (isset($buckets[$state])) {
            $buckets[$state][] = $row;
        }
    }

    usort($buckets['future'], static function ($a, $b) {
        return strcmp($a->start_at, $b->start_at);
    });
    usort($buckets['current'], static function ($a, $b) {
        return strcmp($a->end_at, $b->end_at);
    });

    echo '<div class="seo-campaigns-header"><div><h2>Campañas</h2><p>Organiza las campañas realizadas, activas y futuras. El diseno de banners se incorporara en una fase posterior.</p></div>';
    echo '<a class="button button-primary" href="' . esc_url(seo_marketing_campaigns_admin_url(array('campaign_action' => 'new'))) . '">Nueva campaña</a></div>';

    echo '<div class="seo-campaigns-grid">';
    seo_marketing_campaigns_render_bucket('En curso', $buckets['current'], 'current');
    seo_marketing_campaigns_render_bucket('Proximas', $buckets['future'], 'future');
    seo_marketing_campaigns_render_bucket('Finalizadas', $buckets['past'], 'past');
    seo_marketing_campaigns_render_bucket('Deshabilitadas', $buckets['disabled'], 'disabled');
    echo '</div>';

    seo_marketing_campaigns_render_import_export();
}

/**
 * Tabla de productos ya incluidos.
 *
 * @param object $campaign
 */
function seo_marketing_campaigns_render_existing_products($campaign)
{
    global $wpdb;
    $tables = seo_marketing_campaigns_tables();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT cp.*, p.post_title, sku.meta_value AS sku
             FROM {$tables['products']} cp
             INNER JOIN {$wpdb->posts} p ON p.ID = cp.product_id
             LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
             WHERE cp.campaign_id = %d
             ORDER BY cp.position ASC, cp.id ASC",
            absint($campaign->id)
        )
    );

    echo '<section class="seo-campaigns-card seo-campaign-products">';
    echo '<h3 style="margin-top:0;">Productos de la campaña <span class="seo-campaign-count">' . esc_html((string) count($rows)) . '</span></h3>';

    if (!$rows) {
        echo '<p>No hay productos anadidos todavia.</p></section>';
        return;
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_marketing_campaign_update_products">';
    echo '<input type="hidden" name="campaign_id" value="' . esc_attr((string) $campaign->id) . '">';
    wp_nonce_field('seo_marketing_campaign_update_products');

    echo '<table><thead><tr><th>Producto</th><th>Precio habitual</th><th>Oferta actual</th><th>Precio campaña</th><th>Aplicado</th><th>Quitar</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $product = function_exists('wc_get_product') ? wc_get_product(absint($row->product_id)) : null;
        $regular = $product ? $product->get_regular_price('edit') : '';
        $sale = $product ? $product->get_sale_price('edit') : '';
        echo '<tr>';
        echo '<td><strong>' . esc_html($row->post_title) . '</strong><br><small>#' . esc_html((string) $row->product_id) . ($row->sku ? ' · SKU ' . esc_html($row->sku) : '') . '</small></td>';
        echo '<td>' . ($regular !== '' ? wp_kses_post(wc_price($regular)) : '-') . '</td>';
        echo '<td>' . ($sale !== '' ? wp_kses_post(wc_price($sale)) : '-') . '</td>';
        echo '<td><input type="number" step="0.01" min="0.01" name="campaign_price[' . esc_attr((string) $row->product_id) . ']" value="' . esc_attr(wc_format_decimal($row->campaign_price, wc_get_price_decimals())) . '"></td>';
        echo '<td>' . (!empty($row->applied) ? '<strong style="color:#1d6b43;">Si</strong>' : 'No') . '</td>';
        echo '<td><label><input type="checkbox" name="remove_product[]" value="' . esc_attr((string) $row->product_id) . '"> Quitar</label></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p><button type="submit" class="button button-primary">Guardar productos y precios</button></p>';
    echo '</form></section>';
}

/**
 * Buscador y alta multiple de productos.
 *
 * @param object $campaign
 */
function seo_marketing_campaigns_render_product_search($campaign)
{
    $search = isset($_GET['product_search']) ? sanitize_text_field(wp_unslash($_GET['product_search'])) : '';
    $results = seo_marketing_campaigns_search_products($search);

    echo '<section class="seo-campaigns-card seo-campaign-products">';
    echo '<h3 style="margin-top:0;">Anadir productos</h3>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="seo-campaign-search">';
    echo '<input type="hidden" name="page" value="seo-menu-marketing"><input type="hidden" name="tab" value="campaigns"><input type="hidden" name="campaign_id" value="' . esc_attr((string) $campaign->id) . '">';
    echo '<div class="seo-campaign-field"><label for="seo-campaign-product-search">Buscar por nombre o SKU</label><input id="seo-campaign-product-search" type="search" name="product_search" value="' . esc_attr($search) . '" placeholder="Ej.: Makita DTM52Z"></div>';
    echo '<button type="submit" class="button">Buscar productos</button></form>';

    if ($search === '') {
        echo '<p class="description">El catalogo no se carga completo. Busca por nombre o SKU y selecciona tantos productos como necesites.</p></section>';
        return;
    }

    if (!$results) {
        echo '<p>No se han encontrado productos.</p></section>';
        return;
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_marketing_campaign_add_products"><input type="hidden" name="campaign_id" value="' . esc_attr((string) $campaign->id) . '">';
    wp_nonce_field('seo_marketing_campaign_add_products');
    echo '<table><thead><tr><th>Seleccionar</th><th>Producto</th><th>Tipo</th><th>Precio habitual</th><th>Oferta actual</th><th>Precio campaña</th></tr></thead><tbody>';

    foreach ($results as $row) {
        $product = function_exists('wc_get_product') ? wc_get_product(absint($row->ID)) : null;
        if (!$product) {
            continue;
        }
        $regular = $product->get_regular_price('edit');
        $sale = $product->get_sale_price('edit');
        $suggested = $sale !== '' ? $sale : $regular;
        echo '<tr>';
        echo '<td><input type="checkbox" name="selected_products[]" value="' . esc_attr((string) $row->ID) . '"' . ($product->is_type('variable') ? ' disabled' : '') . '></td>';
        echo '<td><strong>' . esc_html($row->post_title) . '</strong><br><small>#' . esc_html((string) $row->ID) . ($row->sku ? ' · SKU ' . esc_html($row->sku) : '') . '</small></td>';
        echo '<td>' . esc_html($product->get_type()) . ($product->is_type('variable') ? '<br><small>Fuera de V1</small>' : '') . '</td>';
        echo '<td>' . ($regular !== '' ? wp_kses_post(wc_price($regular)) : '-') . '</td>';
        echo '<td>' . ($sale !== '' ? wp_kses_post(wc_price($sale)) : '-') . '</td>';
        echo '<td><input type="number" step="0.01" min="0.01" name="campaign_price[' . esc_attr((string) $row->ID) . ']" value="' . esc_attr($suggested !== '' ? wc_format_decimal($suggested, wc_get_price_decimals()) : '') . '"></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p><button type="submit" class="button button-primary">Anadir seleccionados</button></p>';
    echo '</form></section>';
}

/**
 * Editor de una campaña nueva o existente.
 *
 * @param int  $campaign_id
 * @param bool $is_new
 */
function seo_marketing_campaigns_render_editor($campaign_id, $is_new = false)
{
    $campaign = $campaign_id ? seo_marketing_campaigns_get($campaign_id) : null;
    if ($campaign_id && !$campaign) {
        echo '<div class="notice notice-error inline"><p>La campaña indicada no existe.</p></div>';
        return;
    }

    if (!$campaign) {
        $start = new DateTimeImmutable('now', wp_timezone());
        $end = $start->modify('+7 days');
        $campaign = (object) array(
            'id'            => 0,
            'campaign_key'  => '',
            'series_key'    => '',
            'name'          => '',
            'edition_label' => $start->format('Y'),
            'campaign_type' => 'calendar',
            'recurrence'    => 'none',
            'start_at'      => $start->format('Y-m-d H:i:s'),
            'end_at'        => $end->format('Y-m-d H:i:s'),
            'is_enabled'    => 1,
        );
    }

    $back_url = seo_marketing_campaigns_admin_url();
    echo '<div class="seo-campaigns-header"><div><h2>' . ($campaign->id ? 'Editar campaña' : 'Nueva campaña') . '</h2><p>Primero define la campaña y sus fechas. Despues podras anadir todos los productos necesarios.</p></div><a class="button" href="' . esc_url($back_url) . '">Volver a campañas</a></div>';

    echo '<div class="seo-campaign-editor">';
    echo '<section class="seo-campaigns-card">';
    echo '<h3 style="margin-top:0;">Datos de la campaña</h3>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_marketing_campaign_save"><input type="hidden" name="campaign_id" value="' . esc_attr((string) $campaign->id) . '">';
    wp_nonce_field('seo_marketing_campaign_save');
    echo '<div class="seo-campaign-form-grid">';
    echo '<div class="seo-campaign-field seo-campaign-field-full"><label>Nombre</label><input type="text" name="name" required value="' . esc_attr($campaign->name) . '" placeholder="Navidad"></div>';
    if (!empty($campaign->id)) {
        echo '<div class="seo-campaign-field"><label>Identificador</label><input type="text" readonly value="' . esc_attr((string) ($campaign->campaign_key ?? '')) . '"></div>';
        echo '<div class="seo-campaign-field"><label>Serie</label><input type="text" readonly value="' . esc_attr((string) ($campaign->series_key ?? '')) . '"></div>';
    }
    echo '<div class="seo-campaign-field"><label>Edicion</label><input type="text" name="edition_label" value="' . esc_attr($campaign->edition_label) . '" placeholder="2026"></div>';
    echo '<div class="seo-campaign-field"><label>Tipo</label><select name="campaign_type">';
    foreach (seo_marketing_campaigns_types() as $key => $label) {
        echo '<option value="' . esc_attr($key) . '" ' . selected(isset($campaign->campaign_type) ? $campaign->campaign_type : 'calendar', $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="seo-campaign-field"><label>Repeticion</label><select name="recurrence">';
    foreach (array('none' => 'No recurrente', 'annual' => 'Anual', 'manual' => 'Nueva edicion manual') as $key => $label) {
        echo '<option value="' . esc_attr($key) . '" ' . selected($campaign->recurrence, $key, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="seo-campaign-field"><label>Inicio</label><input type="datetime-local" name="start_at" required value="' . esc_attr(seo_marketing_campaigns_datetime_local($campaign->start_at)) . '"></div>';
    echo '<div class="seo-campaign-field"><label>Fin</label><input type="datetime-local" name="end_at" required value="' . esc_attr(seo_marketing_campaigns_datetime_local($campaign->end_at)) . '"></div>';
    echo '<div class="seo-campaign-field seo-campaign-field-full"><label><input type="checkbox" name="is_enabled" value="1" ' . checked(!empty($campaign->is_enabled), true, false) . '> Campaña habilitada</label><p class="description">El precio de campaña solo se aplica durante estas fechas. Al finalizar se intenta restaurar la oferta anterior del producto.</p></div>';
    echo '</div><p><button type="submit" class="button button-primary">Guardar campaña</button></p></form>';

    if ($campaign->id) {
        echo '<hr style="margin:18px 0;">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="seo_marketing_campaign_duplicate"><input type="hidden" name="campaign_id" value="' . esc_attr((string) $campaign->id) . '">';
        wp_nonce_field('seo_marketing_campaign_duplicate');
        echo '<button type="submit" class="button">Crear nueva edicion (+1 ano)</button>';
        echo '<p class="description">Conserva el nombre/serie y crea otro ID. No copia productos ni precios.</p>';
        echo '</form>';
    }
    echo '</section>';

    if ($campaign->id) {
        echo '<div>';
        seo_marketing_campaigns_render_existing_products($campaign);
        echo '<div style="height:18px"></div>';
        seo_marketing_campaigns_render_product_search($campaign);
        echo '</div>';
    } else {
        echo '<section class="seo-campaigns-card"><h3 style="margin-top:0;">Productos</h3><p>Guarda primero la campaña. Despues aparecera aqui el selector multiple de productos y el precio de oferta de cada uno.</p></section>';
    }

    echo '</div>';
}

/**
 * Entrada de la pestana Campañas desde SEO Marketing.
 */
function seo_marketing_campaigns_render_tab()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    seo_marketing_campaigns_maybe_install_tables();
    seo_marketing_campaigns_render_styles();
    seo_marketing_campaigns_render_notice();

    $campaign_id = isset($_GET['campaign_id']) ? absint($_GET['campaign_id']) : 0;
    $campaign_action = isset($_GET['campaign_action']) ? sanitize_key(wp_unslash($_GET['campaign_action'])) : '';

    if ($campaign_id || $campaign_action === 'new') {
        seo_marketing_campaigns_render_editor($campaign_id, $campaign_action === 'new');
        return;
    }

    seo_marketing_campaigns_render_dashboard();
}
