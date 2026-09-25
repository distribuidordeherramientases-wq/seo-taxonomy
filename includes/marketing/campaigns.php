<?php
/**
 * SEO Taxonomy - Marketing / Campañas.
 *
 * V1: creacion, organizacion temporal, recurrencia basica y productos con
 * precio de campaña. El diseno de banners y creatividades queda fuera de
 * esta version.
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
        series_key varchar(191) NOT NULL DEFAULT '',
        parent_campaign_id bigint(20) unsigned NULL,
        name varchar(191) NOT NULL,
        edition_label varchar(191) NOT NULL DEFAULT '',
        recurrence varchar(20) NOT NULL DEFAULT 'none',
        source varchar(30) NOT NULL DEFAULT 'manual',
        source_key varchar(191) NOT NULL DEFAULT '',
        source_meta longtext NULL,
        proposal_generated_at datetime NULL,
        start_at datetime NOT NULL,
        end_at datetime NOT NULL,
        is_enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
        created_by bigint(20) unsigned NOT NULL DEFAULT 0,
        updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY series_key (series_key),
        KEY source_key (source_key),
        KEY source (source),
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
        source varchar(30) NOT NULL DEFAULT 'manual',
        supplier_cost decimal(19,4) NULL,
        supplier_cost_source varchar(60) NOT NULL DEFAULT '',
        current_price_snapshot decimal(19,4) NULL,
        regular_price_snapshot decimal(19,4) NULL,
        market_benchmark_price decimal(19,4) NULL,
        market_price_min decimal(19,4) NULL,
        market_price_max decimal(19,4) NULL,
        match_confidence_pct decimal(7,3) NULL,
        gross_margin_amount decimal(19,4) NULL,
        gross_margin_pct decimal(9,4) NULL,
        markup_on_cost_pct decimal(9,4) NULL,
        price_vs_market_pct decimal(9,4) NULL,
        demand_searches int(10) unsigned NOT NULL DEFAULT 0,
        demand_clicks int(10) unsigned NOT NULL DEFAULT 0,
        demand_impressions decimal(19,4) NOT NULL DEFAULT 0,
        demand_score decimal(9,4) NOT NULL DEFAULT 0,
        source_meta longtext NULL,
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

    update_option(SEO_MARKETING_CAMPAIGNS_DB_OPTION, SEO_MARKETING_CAMPAIGNS_DB_VERSION, false);
}
add_action('admin_init', 'seo_marketing_campaigns_maybe_install_tables', 15);

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
 * Metricas comerciales simples sobre el coste almacenado del proveedor.
 *
 * No representan beneficio neto: no descuentan portes, comisiones,
 * devoluciones ni otros costes operativos.
 *
 * @param mixed $price
 * @param mixed $cost
 * @return array
 */
function seo_marketing_campaigns_margin_metrics($price, $cost)
{
    $price = is_numeric($price) ? (float) $price : 0.0;
    $cost = is_numeric($cost) ? (float) $cost : 0.0;
    if ($price <= 0 || $cost <= 0) {
        return array(
            'gross_amount' => null,
            'gross_margin_pct' => null,
            'markup_on_cost_pct' => null,
        );
    }
    $gross = $price - $cost;
    return array(
        'gross_amount' => round($gross, 4),
        'gross_margin_pct' => round(($gross / $price) * 100, 4),
        'markup_on_cost_pct' => round(($gross / $cost) * 100, 4),
    );
}

/**
 * Ultima campana creada desde una propuesta de Analista.
 *
 * @param string $source_key
 * @return object|null
 */
function seo_marketing_campaigns_get_by_source_key($source_key)
{
    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    $source_key = sanitize_key((string) $source_key);
    if ($source_key === '') return null;
    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$tables['campaigns']} WHERE source='analista' AND source_key=%s ORDER BY id DESC LIMIT 1",
            $source_key
        )
    );
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

    $data = array(
        'series_key'    => $series_key,
        'name'          => $name,
        'edition_label' => $edition_label,
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
            array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s'),
            array('%d')
        );
    } else {
        $data['created_by'] = get_current_user_id();
        $data['created_at'] = $now;
        $wpdb->insert(
            $tables['campaigns'],
            $data,
            array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s')
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
                $update_data = array(
                    'campaign_price' => $price,
                    'updated_at'     => seo_marketing_campaigns_now_mysql(),
                );
                if (isset($row->supplier_cost) && is_numeric($row->supplier_cost) && (float) $row->supplier_cost > 0) {
                    $margin = seo_marketing_campaigns_margin_metrics((float) $price, (float) $row->supplier_cost);
                    $update_data['gross_margin_amount'] = $margin['gross_amount'];
                    $update_data['gross_margin_pct'] = $margin['gross_margin_pct'];
                    $update_data['markup_on_cost_pct'] = $margin['markup_on_cost_pct'];
                    if (isset($row->market_benchmark_price) && is_numeric($row->market_benchmark_price) && (float) $row->market_benchmark_price > 0) {
                        $update_data['price_vs_market_pct'] = (((float) $price - (float) $row->market_benchmark_price) / (float) $row->market_benchmark_price) * 100;
                    }
                }
                $wpdb->update(
                    $tables['products'],
                    $update_data,
                    array('id' => absint($row->id)),
                    null,
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
            'series_key'         => $campaign->series_key,
            'parent_campaign_id' => $campaign_id,
            'name'               => $campaign->name,
            'edition_label'      => $new_start->format('Y'),
            'recurrence'         => $campaign->recurrence,
            'start_at'           => $new_start->format('Y-m-d H:i:s'),
            'end_at'             => $new_end->format('Y-m-d H:i:s'),
            'is_enabled'         => 1,
            'created_by'         => get_current_user_id(),
            'updated_by'         => get_current_user_id(),
            'created_at'         => $now,
            'updated_at'         => $now,
        ),
        array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s')
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
 * Activa una propuesta vigente de Analista y la convierte en una campana real.
 *
 * La propuesta se vuelve a calcular en servidor; no se confian precios ni
 * productos recibidos desde el formulario. Asi la activacion conserva el
 * snapshot real de Ojeador/Analista que existia en ese momento.
 */
function seo_marketing_campaigns_handle_activate_analista()
{
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para activar propuestas de Analista.');
    }
    check_admin_referer('seo_marketing_campaign_activate_analista');
    seo_marketing_campaigns_maybe_install_tables();

    $source_key = isset($_POST['source_key']) ? sanitize_key(wp_unslash($_POST['source_key'])) : '';
    $days = isset($_POST['analista_days']) ? absint($_POST['analista_days']) : 28;
    $days = function_exists('seo_analista_days') ? seo_analista_days($days) : max(7, min(90, $days));
    if ($source_key === '' || !function_exists('seo_analista_campaign_proposals')) {
        seo_marketing_campaigns_redirect_notice(
            'No se ha podido recuperar la propuesta de Analista.',
            'error',
            array('campaign_view' => 'analista')
        );
    }

    $existing_source = seo_marketing_campaigns_get_by_source_key($source_key);
    if ($existing_source) {
        seo_marketing_campaigns_redirect_notice(
            'Esta propuesta ya se convirtio en una campana. Abre la campana existente para revisarla.',
            'warning',
            array('campaign_id' => absint($existing_source->id))
        );
    }

    $payload = seo_analista_campaign_proposals($days, 30, true);
    $proposal = null;
    foreach ((array) ($payload['proposals'] ?? array()) as $item) {
        if (sanitize_key((string) ($item['key'] ?? '')) === $source_key) {
            $proposal = $item;
            break;
        }
    }
    if (!$proposal || empty($proposal['products'])) {
        seo_marketing_campaigns_redirect_notice(
            'La propuesta ya no esta disponible o ha perdido sus productos elegibles.',
            'warning',
            array('campaign_view' => 'analista')
        );
    }

    $start_at = seo_marketing_campaigns_parse_local_datetime(isset($_POST['start_at']) ? wp_unslash($_POST['start_at']) : '');
    $end_at = seo_marketing_campaigns_parse_local_datetime(isset($_POST['end_at']) ? wp_unslash($_POST['end_at']) : '');
    if ($start_at === '') $start_at = (string) ($proposal['start_at'] ?? '');
    if ($end_at === '') $end_at = (string) ($proposal['end_at'] ?? '');
    if ($start_at === '' || $end_at === '' || $end_at <= $start_at) {
        seo_marketing_campaigns_redirect_notice(
            'Las fechas de la propuesta no son validas.',
            'error',
            array('campaign_view' => 'analista')
        );
    }

    $valid_products = array();
    $skipped = array();
    foreach ((array) $proposal['products'] as $product_row) {
        $product_id = absint($product_row['product_id'] ?? 0);
        $campaign_price = is_numeric($product_row['recommended_campaign_price'] ?? null)
            ? (float) $product_row['recommended_campaign_price']
            : 0.0;
        $cost = is_numeric($product_row['supplier_cost'] ?? null) ? (float) $product_row['supplier_cost'] : 0.0;
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;

        if (!$product || $product->is_type('variable') || $campaign_price <= 0 || $cost <= 0) {
            $skipped[] = $product_id;
            continue;
        }
        $overlap = seo_marketing_campaigns_find_product_overlap($product_id, 0, $start_at, $end_at);
        if ($overlap) {
            $skipped[] = $product_id;
            continue;
        }
        $valid_products[] = array(
            'product' => $product,
            'row' => $product_row,
            'campaign_price' => $campaign_price,
            'supplier_cost' => $cost,
        );
    }

    if (!$valid_products) {
        seo_marketing_campaigns_redirect_notice(
            'No se ha activado la propuesta: todos sus productos estan solapados, no existen o no tienen precio/coste valido.',
            'warning',
            array('campaign_view' => 'analista')
        );
    }

    global $wpdb;
    $tables = seo_marketing_campaigns_tables();
    $now = seo_marketing_campaigns_now_mysql();
    $name = sanitize_text_field((string) ($proposal['name'] ?? 'Campana propuesta por Analista'));
    $series_key = sanitize_title('analista-' . $name);
    if ($series_key === '') $series_key = 'analista-' . wp_generate_uuid4();
    $source_meta = wp_json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    $inserted = $wpdb->insert(
        $tables['campaigns'],
        array(
            'series_key' => $series_key,
            'parent_campaign_id' => null,
            'name' => $name,
            'edition_label' => wp_date('Y-m-d', seo_marketing_campaigns_timestamp($start_at), wp_timezone()),
            'recurrence' => 'none',
            'source' => 'analista',
            'source_key' => $source_key,
            'source_meta' => $source_meta,
            'proposal_generated_at' => (string) ($payload['generated_at'] ?? $now),
            'start_at' => $start_at,
            'end_at' => $end_at,
            'is_enabled' => 1,
            'created_by' => get_current_user_id(),
            'updated_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        )
    );
    if (false === $inserted) {
        seo_marketing_campaigns_redirect_notice(
            'No se ha podido crear la campana desde la propuesta.',
            'error',
            array('campaign_view' => 'analista')
        );
    }
    $campaign_id = absint($wpdb->insert_id);
    $position = 0;
    $added = 0;

    foreach ($valid_products as $candidate) {
        $position++;
        $product = $candidate['product'];
        $row = (array) $candidate['row'];
        $campaign_price = (float) $candidate['campaign_price'];
        $cost = (float) $candidate['supplier_cost'];
        $margin = seo_marketing_campaigns_margin_metrics($campaign_price, $cost);
        $benchmark = is_numeric($row['market_benchmark_price'] ?? null) ? (float) $row['market_benchmark_price'] : null;
        $price_vs_market = ($benchmark && $benchmark > 0)
            ? (($campaign_price - $benchmark) / $benchmark) * 100
            : null;
        $demand = (array) ($row['demand'] ?? array());

        $ok = $wpdb->insert(
            $tables['products'],
            array(
                'campaign_id' => $campaign_id,
                'product_id' => $product->get_id(),
                'campaign_price' => $campaign_price,
                'source' => 'analista',
                'supplier_cost' => $cost,
                'supplier_cost_source' => sanitize_key((string) ($row['supplier_cost_source'] ?? '')),
                'current_price_snapshot' => is_numeric($row['current_price'] ?? null) ? (float) $row['current_price'] : null,
                'regular_price_snapshot' => is_numeric($product->get_regular_price('edit')) ? (float) $product->get_regular_price('edit') : null,
                'market_benchmark_price' => $benchmark,
                'market_price_min' => is_numeric($row['market_price_min'] ?? null) ? (float) $row['market_price_min'] : null,
                'market_price_max' => is_numeric($row['market_price_max'] ?? null) ? (float) $row['market_price_max'] : null,
                'match_confidence_pct' => is_numeric($row['match_confidence_pct'] ?? null) ? (float) $row['match_confidence_pct'] : null,
                'gross_margin_amount' => $margin['gross_amount'],
                'gross_margin_pct' => $margin['gross_margin_pct'],
                'markup_on_cost_pct' => $margin['markup_on_cost_pct'],
                'price_vs_market_pct' => $price_vs_market,
                'demand_searches' => absint($demand['dependiente_searches'] ?? 0),
                'demand_clicks' => absint($demand['dependiente_clicks'] ?? 0),
                'demand_impressions' => (float) ($demand['search_console_impressions'] ?? 0),
                'demand_score' => (float) ($demand['score'] ?? 0),
                'source_meta' => wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'position' => $position,
                'created_at' => $now,
                'updated_at' => $now,
            )
        );
        if (false !== $ok) $added++;
    }

    if (!$added) {
        $wpdb->delete($tables['campaigns'], array('id' => $campaign_id), array('%d'));
        seo_marketing_campaigns_redirect_notice(
            'No se ha podido anadir ningun producto a la campana.',
            'error',
            array('campaign_view' => 'analista')
        );
    }

    seo_marketing_campaigns_reschedule($campaign_id);
    $message = sprintf('Campana de Analista activada con %d producto(s).', $added);
    if ($skipped) {
        $message .= ' Se omitieron ' . count($skipped) . ' producto(s) por solapamiento o datos no validos.';
    }
    seo_marketing_campaigns_redirect_notice($message, $skipped ? 'warning' : 'success', array('campaign_id' => $campaign_id));
}
add_action('admin_post_seo_marketing_campaign_activate_analista', 'seo_marketing_campaigns_handle_activate_analista');

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
        .seo-campaigns-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;align-items:start;}
        .seo-campaigns-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.03);}
        .seo-campaigns-subnav{display:flex;gap:8px;margin:0 0 18px;flex-wrap:wrap;}
        .seo-campaigns-subnav a{padding:7px 12px;border:1px solid #c3c4c7;border-radius:999px;text-decoration:none;background:#fff;color:#2c3338;font-weight:600;}
        .seo-campaigns-subnav a.is-active{background:#2271b1;border-color:#2271b1;color:#fff;}
        .seo-analista-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 18px;}
        .seo-analista-kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;}
        .seo-analista-kpi strong{display:block;font-size:24px;line-height:1.1;margin-top:4px;}
        .seo-analista-proposal{margin-bottom:18px;}
        .seo-analista-proposal-head{display:flex;justify-content:space-between;gap:18px;align-items:flex-start;margin-bottom:12px;}
        .seo-analista-proposal-head h3{margin:0 0 4px;}
        .seo-analista-proposal table{width:100%;border-collapse:collapse;}
        .seo-analista-proposal th,.seo-analista-proposal td{padding:9px 8px;border-bottom:1px solid #f0f0f1;text-align:left;vertical-align:top;}
        .seo-analista-proposal th{font-size:11px;text-transform:uppercase;color:#50575e;}
        .seo-analista-price-good{color:#1d6b43;font-weight:700;}
        .seo-analista-price-market{color:#8a5a00;font-weight:700;}
        .seo-analista-price-bad{color:#b32d2e;font-weight:700;}
        .seo-analista-activate{display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end;margin-top:14px;padding-top:14px;border-top:1px solid #f0f0f1;}
        .seo-analista-activate label{font-weight:600;font-size:12px;}.seo-analista-activate input{width:100%;}
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
        .seo-campaign-status-current{background:#dff4e7;color:#1d6b43;}.seo-campaign-status-future{background:#e7f1ff;color:#135e96;}.seo-campaign-status-past{background:#f0f0f1;color:#50575e;}
        @media(max-width:1100px){.seo-campaigns-grid,.seo-campaign-editor{grid-template-columns:1fr;}.seo-analista-summary{grid-template-columns:repeat(2,minmax(0,1fr));}}
        @media(max-width:700px){.seo-campaign-form-grid{grid-template-columns:1fr;}.seo-campaign-field-full{grid-column:auto;}.seo-campaign-search{display:block;}.seo-campaign-search .button{margin-top:8px;}.seo-analista-summary{grid-template-columns:1fr;}.seo-analista-proposal-head,.seo-analista-activate{display:block;}.seo-analista-activate>*{margin-top:8px;}.seo-analista-proposal{overflow-x:auto;}}
    </style>';
}

/**
 * Navegacion secundaria dentro de Marketing > Campanas.
 *
 * @param string $current
 */
function seo_marketing_campaigns_render_subnav($current = 'campaigns')
{
    $items = array(
        'campaigns' => 'Campanas',
        'analista' => 'Propuestas de Analista',
    );
    echo '<nav class="seo-campaigns-subnav" aria-label="Vistas de campanas">';
    foreach ($items as $key => $label) {
        $url = seo_marketing_campaigns_admin_url($key === 'analista' ? array('campaign_view' => 'analista') : array());
        echo '<a class="' . ($current === $key ? 'is-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';
}

/**
 * Precio para tablas internas.
 *
 * @param mixed $value
 * @return string
 */
function seo_marketing_campaigns_price_html($value)
{
    if (!is_numeric($value) || (float) $value <= 0) return '-';
    if (function_exists('wc_price')) return wp_kses_post(wc_price((float) $value));
    return esc_html(number_format_i18n((float) $value, 2) . ' EUR');
}

/**
 * Margen bruto / markup para una opcion de precio.
 *
 * @param array $metrics
 * @return string
 */
function seo_marketing_campaigns_margin_html($metrics)
{
    $metrics = is_array($metrics) ? $metrics : array();
    if (!isset($metrics['gross_amount']) || !is_numeric($metrics['gross_amount'])) return '-';
    $gross = (float) $metrics['gross_amount'];
    $markup = is_numeric($metrics['markup_on_cost_pct'] ?? null) ? (float) $metrics['markup_on_cost_pct'] : null;
    $margin = is_numeric($metrics['margin_on_sale_pct'] ?? null) ? (float) $metrics['margin_on_sale_pct'] : null;
    $html = function_exists('wc_price')
        ? wp_kses_post(wc_price($gross))
        : esc_html(number_format_i18n($gross, 2) . ' EUR');
    if ($markup !== null) $html .= '<br><small>+' . esc_html(number_format_i18n($markup, 1)) . '% s/coste</small>';
    if ($margin !== null) $html .= '<br><small>' . esc_html(number_format_i18n($margin, 1)) . '% s/venta</small>';
    return $html;
}

/**
 * Propuestas comerciales calculadas por Analista con Ojeador.
 */
function seo_marketing_campaigns_render_analista_proposals()
{
    $days = isset($_GET['analista_days']) ? absint($_GET['analista_days']) : 28;
    $days = function_exists('seo_analista_days') ? seo_analista_days($days) : max(7, min(90, $days));

    echo '<div class="seo-campaigns-header"><div><h2>Propuestas de Analista</h2><p>Campanas que Analista activaria al cruzar demanda de clientes, Search Console y posicion de precio de Ojeador.</p></div></div>';

    if (!function_exists('seo_analista_campaign_proposals')) {
        echo '<div class="notice notice-warning inline"><p>Analista todavia no ha cargado el motor de propuestas de campanas.</p></div>';
        return;
    }

    $payload = seo_analista_campaign_proposals($days, 12, false);
    if (empty($payload['available'])) {
        echo '<div class="notice notice-info inline"><p>No hay datos suficientes de Ojeador para generar propuestas.</p></div>';
        return;
    }

    $summary = (array) ($payload['summary'] ?? array());
    echo '<div class="seo-analista-summary">';
    $kpis = array(
        'Propuestas' => (int) ($summary['proposal_count'] ?? 0),
        'Productos activables' => (int) ($summary['eligible_products'] ?? 0),
        'Frenados por precio' => (int) ($summary['blocked_by_price'] ?? 0),
        'Productos con senal Dependiente' => (int) ($summary['dependiente_products_with_signal'] ?? 0),
    );
    foreach ($kpis as $label => $value) {
        echo '<div class="seo-analista-kpi"><span>' . esc_html($label) . '</span><strong>' . esc_html(number_format_i18n($value)) . '</strong></div>';
    }
    echo '</div>';

    $notes = (array) ($payload['notes'] ?? array());
    if ($notes) {
        echo '<div class="notice notice-info inline"><p><strong>Como se calcula:</strong> ' . esc_html(implode(' ', array_slice($notes, 0, 3))) . '</p></div>';
    }

    $proposals = (array) ($payload['proposals'] ?? array());
    if (!$proposals) {
        echo '<section class="seo-campaigns-card"><p>No hay ninguna campana que Analista recomiende activar con los datos actuales.</p></section>';
    }

    foreach ($proposals as $proposal) {
        $source_key = sanitize_key((string) ($proposal['key'] ?? ''));
        $activated = $source_key !== '' ? seo_marketing_campaigns_get_by_source_key($source_key) : null;
        $products = (array) ($proposal['products'] ?? array());
        $summary_row = (array) ($proposal['summary'] ?? array());

        echo '<section class="seo-campaigns-card seo-analista-proposal">';
        echo '<div class="seo-analista-proposal-head"><div>';
        echo '<h3>' . esc_html((string) ($proposal['name'] ?? 'Propuesta')) . '</h3>';
        echo '<p style="margin:0;color:#646970;">' . esc_html((string) ($proposal['reason'] ?? '')) . '</p>';
        echo '</div><div style="text-align:right;white-space:nowrap;"><strong>Prioridad ' . esc_html(number_format_i18n((float) ($proposal['priority_score'] ?? 0), 1)) . '/100</strong><br><small>' . esc_html(count($products)) . ' producto(s)</small></div></div>';

        echo '<p class="seo-campaign-meta">';
        echo esc_html(number_format_i18n((int) ($summary_row['dependiente_searches'] ?? 0))) . ' busquedas Dependiente · ';
        echo esc_html(number_format_i18n((int) ($summary_row['dependiente_clicks'] ?? 0))) . ' clics internos · ';
        echo esc_html(number_format_i18n((int) ($summary_row['search_console_impressions'] ?? 0))) . ' impresiones Search Console';
        echo '</p>';

        echo '<div style="overflow-x:auto;"><table><thead><tr>';
        echo '<th>Producto</th><th>Demanda</th><th>Coste</th><th>Mercado</th><th>Igualar</th><th>Competir</th><th>Propuesta</th><th>Margen propuesta</th>';
        echo '</tr></thead><tbody>';
        foreach ($products as $product) {
            $demand = (array) ($product['demand'] ?? array());
            $price_status = (string) ($product['price_status'] ?? '');
            $status_class = $price_status === 'precio_bueno' ? 'seo-analista-price-good' : ($price_status === 'precio_mercado' ? 'seo-analista-price-market' : 'seo-analista-price-bad');
            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($product['product_name'] ?? '')) . '</strong><br><small>#' . esc_html((string) absint($product['product_id'] ?? 0)) . ' · ' . esc_html((string) ($product['category_name'] ?? '')) . '</small><br><span class="' . esc_attr($status_class) . '">' . esc_html((string) ($product['price_status_label'] ?? '')) . '</span></td>';
            echo '<td><strong>' . esc_html(number_format_i18n((float) ($demand['score'] ?? 0), 1)) . '/100</strong><br><small>' . esc_html(number_format_i18n((int) ($demand['dependiente_searches'] ?? 0))) . ' busq. · ' . esc_html(number_format_i18n((int) ($demand['dependiente_clicks'] ?? 0))) . ' clics<br>' . esc_html(number_format_i18n((int) ($demand['search_console_impressions'] ?? 0))) . ' imp. Google</small></td>';
            echo '<td>' . seo_marketing_campaigns_price_html($product['supplier_cost'] ?? null) . '<br><small>' . esc_html((string) ($product['supplier_cost_source'] ?? '')) . '</small></td>';
            echo '<td>Mediana ' . seo_marketing_campaigns_price_html($product['market_benchmark_price'] ?? null) . '<br><small>Min ' . wp_kses_post(seo_marketing_campaigns_price_html($product['market_price_min'] ?? null)) . ' · ' . esc_html(number_format_i18n((float) ($product['match_confidence_pct'] ?? 0), 1)) . '% confianza</small></td>';
            echo '<td>' . seo_marketing_campaigns_price_html($product['equal_market']['price'] ?? null) . '<br><small>margen:</small><br>' . seo_marketing_campaigns_margin_html((array) ($product['equal_market'] ?? array())) . '</td>';
            echo '<td>' . seo_marketing_campaigns_price_html($product['compete_market']['price'] ?? null) . '<br><small>margen:</small><br>' . seo_marketing_campaigns_margin_html((array) ($product['compete_market'] ?? array())) . '</td>';
            echo '<td><strong>' . seo_marketing_campaigns_price_html($product['recommended_campaign_price'] ?? null) . '</strong><br><small>Actual ' . wp_kses_post(seo_marketing_campaigns_price_html($product['current_price'] ?? null)) . '</small></td>';
            echo '<td>' . seo_marketing_campaigns_margin_html((array) ($product['recommended_margin'] ?? array())) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        if ($activated) {
            $open = seo_marketing_campaigns_admin_url(array('campaign_id' => absint($activated->id)));
            echo '<p><strong style="color:#1d6b43;">Ya activada.</strong> <a class="button" href="' . esc_url($open) . '">Abrir campana #' . esc_html((string) absint($activated->id)) . '</a></p>';
        } else {
            echo '<form class="seo-analista-activate" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="seo_marketing_campaign_activate_analista">';
            echo '<input type="hidden" name="source_key" value="' . esc_attr($source_key) . '">';
            echo '<input type="hidden" name="analista_days" value="' . esc_attr((string) $days) . '">';
            wp_nonce_field('seo_marketing_campaign_activate_analista');
            echo '<div><label>Inicio</label><input type="datetime-local" name="start_at" required value="' . esc_attr(seo_marketing_campaigns_datetime_local((string) ($proposal['start_at'] ?? ''))) . '"></div>';
            echo '<div><label>Fin</label><input type="datetime-local" name="end_at" required value="' . esc_attr(seo_marketing_campaigns_datetime_local((string) ($proposal['end_at'] ?? ''))) . '"></div>';
            echo '<div><button type="submit" class="button button-primary">Activar campana</button></div>';
            echo '</form>';
        }
        echo '</section>';
    }

    $blocked = (array) ($payload['blocked_by_price'] ?? array());
    if ($blocked) {
        echo '<section class="seo-campaigns-card seo-analista-proposal">';
        echo '<h3>Interes frenado por precio <span class="seo-campaign-count">' . esc_html((string) count($blocked)) . '</span></h3>';
        echo '<p class="description">Analista detecta demanda, pero Ojeador clasifica estos productos como precio muy malo. Se muestran para saber que margen exigiria igualar o competir, pero no se ofrece Activar.</p>';
        echo '<div style="overflow-x:auto;"><table><thead><tr><th>Producto</th><th>Demanda</th><th>Coste</th><th>Mercado</th><th>Igualar</th><th>Competir</th></tr></thead><tbody>';
        foreach ($blocked as $product) {
            $demand = (array) ($product['demand'] ?? array());
            echo '<tr>';
            echo '<td><strong>' . esc_html((string) ($product['product_name'] ?? '')) . '</strong><br><small>' . esc_html((string) ($product['category_name'] ?? '')) . '</small></td>';
            echo '<td>' . esc_html(number_format_i18n((float) ($demand['score'] ?? 0), 1)) . '/100<br><small>' . esc_html(number_format_i18n((int) ($demand['dependiente_searches'] ?? 0))) . ' busq. · ' . esc_html(number_format_i18n((int) ($demand['search_console_impressions'] ?? 0))) . ' imp.</small></td>';
            echo '<td>' . seo_marketing_campaigns_price_html($product['supplier_cost'] ?? null) . '</td>';
            echo '<td>' . seo_marketing_campaigns_price_html($product['market_benchmark_price'] ?? null) . '</td>';
            echo '<td>' . seo_marketing_campaigns_price_html($product['equal_market']['price'] ?? null) . '<br>' . seo_marketing_campaigns_margin_html((array) ($product['equal_market'] ?? array())) . '</td>';
            echo '<td>' . seo_marketing_campaigns_price_html($product['compete_market']['price'] ?? null) . '<br>' . seo_marketing_campaigns_margin_html((array) ($product['compete_market'] ?? array())) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></section>';
    }
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
    $status_labels = array('current' => 'En curso', 'future' => 'Proxima', 'past' => 'Finalizada');
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
        echo '<br>' . esc_html(number_format_i18n((int) $campaign->product_count)) . ' producto(s) · ' . esc_html(seo_marketing_campaigns_recurrence_label($campaign->recurrence));
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
         WHERE c.is_enabled = 1
         GROUP BY c.id
         ORDER BY c.start_at DESC, c.id DESC"
    );

    $buckets = array('current' => array(), 'future' => array(), 'past' => array());
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
    echo '</div>';
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

    echo '<table><thead><tr><th>Producto</th><th>Precio habitual</th><th>Coste</th><th>Mercado</th><th>Precio campana</th><th>Margen bruto</th><th>Demanda</th><th>Aplicado</th><th>Quitar</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $product = function_exists('wc_get_product') ? wc_get_product(absint($row->product_id)) : null;
        $regular = $product ? $product->get_regular_price('edit') : '';
        $sale = $product ? $product->get_sale_price('edit') : '';
        $source_meta = !empty($row->source_meta) ? json_decode((string) $row->source_meta, true) : array();
        if (!is_array($source_meta)) $source_meta = array();
        $margin = seo_marketing_campaigns_margin_metrics($row->campaign_price, $row->supplier_cost ?? null);
        echo '<tr>';
        echo '<td><strong>' . esc_html($row->post_title) . '</strong><br><small>#' . esc_html((string) $row->product_id) . ($row->sku ? ' · SKU ' . esc_html($row->sku) : '') . '</small></td>';
        echo '<td>' . ($regular !== '' ? wp_kses_post(wc_price($regular)) : '-') . ($sale !== '' ? '<br><small>Oferta actual ' . wp_kses_post(wc_price($sale)) . '</small>' : '') . '</td>';
        echo '<td>' . seo_marketing_campaigns_price_html($row->supplier_cost ?? null) . (!empty($row->supplier_cost_source) ? '<br><small>' . esc_html((string) $row->supplier_cost_source) . '</small>' : '') . '</td>';
        echo '<td>' . seo_marketing_campaigns_price_html($row->market_benchmark_price ?? null);
        if (is_numeric($row->market_price_min ?? null) || is_numeric($row->market_price_max ?? null)) {
            echo '<br><small>Rango ' . wp_kses_post(seo_marketing_campaigns_price_html($row->market_price_min ?? null)) . ' - ' . wp_kses_post(seo_marketing_campaigns_price_html($row->market_price_max ?? null)) . '</small>';
        }
        if (is_numeric($row->match_confidence_pct ?? null)) echo '<br><small>' . esc_html(number_format_i18n((float) $row->match_confidence_pct, 1)) . '% confianza</small>';
        if (!empty($source_meta['equal_market']['price'])) {
            echo '<br><small>Igualar ' . wp_kses_post(seo_marketing_campaigns_price_html($source_meta['equal_market']['price'])) . ' · ' . esc_html(number_format_i18n((float) ($source_meta['equal_market']['markup_on_cost_pct'] ?? 0), 1)) . '% s/coste</small>';
        }
        if (!empty($source_meta['compete_market']['price'])) {
            echo '<br><small>Competir ' . wp_kses_post(seo_marketing_campaigns_price_html($source_meta['compete_market']['price'])) . ' · ' . esc_html(number_format_i18n((float) ($source_meta['compete_market']['markup_on_cost_pct'] ?? 0), 1)) . '% s/coste</small>';
        }
        echo '</td>';
        echo '<td><input type="number" step="0.01" min="0.01" name="campaign_price[' . esc_attr((string) $row->product_id) . ']" value="' . esc_attr(wc_format_decimal($row->campaign_price, wc_get_price_decimals())) . '"></td>';
        echo '<td>' . seo_marketing_campaigns_margin_html(array(
            'gross_amount' => $margin['gross_amount'],
            'markup_on_cost_pct' => $margin['markup_on_cost_pct'],
            'margin_on_sale_pct' => $margin['gross_margin_pct'],
        )) . '</td>';
        echo '<td>';
        if ((int) ($row->demand_searches ?? 0) || (int) ($row->demand_clicks ?? 0) || (float) ($row->demand_impressions ?? 0) > 0) {
            echo '<strong>' . esc_html(number_format_i18n((float) ($row->demand_score ?? 0), 1)) . '/100</strong><br><small>' . esc_html(number_format_i18n((int) ($row->demand_searches ?? 0))) . ' busq. · ' . esc_html(number_format_i18n((int) ($row->demand_clicks ?? 0))) . ' clics · ' . esc_html(number_format_i18n((int) ($row->demand_impressions ?? 0))) . ' imp.</small>';
        } else {
            echo '-';
        }
        echo '</td>';
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
            'name'          => '',
            'edition_label' => $start->format('Y'),
            'recurrence'    => 'none',
            'start_at'      => $start->format('Y-m-d H:i:s'),
            'end_at'        => $end->format('Y-m-d H:i:s'),
            'is_enabled'    => 1,
        );
    }

    $back_url = seo_marketing_campaigns_admin_url();
    echo '<div class="seo-campaigns-header"><div><h2>' . ($campaign->id ? 'Editar campaña' : 'Nueva campaña') . '</h2><p>Primero define la campaña y sus fechas. Despues podras anadir todos los productos necesarios.</p></div><a class="button" href="' . esc_url($back_url) . '">Volver a campañas</a></div>';

    if ($campaign->id && isset($campaign->source) && (string) $campaign->source === 'analista') {
        $meta = !empty($campaign->source_meta) ? json_decode((string) $campaign->source_meta, true) : array();
        if (!is_array($meta)) $meta = array();
        echo '<div class="notice notice-info inline"><p><strong>Origen: propuesta de Analista.</strong>';
        if (!empty($meta['reason'])) echo ' ' . esc_html((string) $meta['reason']);
        echo ' Los precios y margenes mostrados abajo conservan el snapshot usado al activar la propuesta.</p></div>';
    }

    echo '<div class="seo-campaign-editor">';
    echo '<section class="seo-campaigns-card">';
    echo '<h3 style="margin-top:0;">Datos de la campaña</h3>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="seo_marketing_campaign_save"><input type="hidden" name="campaign_id" value="' . esc_attr((string) $campaign->id) . '">';
    wp_nonce_field('seo_marketing_campaign_save');
    echo '<div class="seo-campaign-form-grid">';
    echo '<div class="seo-campaign-field seo-campaign-field-full"><label>Nombre</label><input type="text" name="name" required value="' . esc_attr($campaign->name) . '" placeholder="Navidad"></div>';
    echo '<div class="seo-campaign-field"><label>Edicion</label><input type="text" name="edition_label" value="' . esc_attr($campaign->edition_label) . '" placeholder="2026"></div>';
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
    $campaign_view = isset($_GET['campaign_view']) ? sanitize_key(wp_unslash($_GET['campaign_view'])) : 'campaigns';
    if (!in_array($campaign_view, array('campaigns', 'analista'), true)) $campaign_view = 'campaigns';

    seo_marketing_campaigns_render_subnav(($campaign_id || $campaign_action === 'new') ? 'campaigns' : $campaign_view);

    if ($campaign_id || $campaign_action === 'new') {
        seo_marketing_campaigns_render_editor($campaign_id, $campaign_action === 'new');
        return;
    }

    if ($campaign_view === 'analista') {
        seo_marketing_campaigns_render_analista_proposals();
        return;
    }

    seo_marketing_campaigns_render_dashboard();
}
