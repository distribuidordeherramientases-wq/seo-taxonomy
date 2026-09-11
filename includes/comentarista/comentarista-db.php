<?php
/**
 * Comentarista - almacenamiento y esquema.
 *
 * Guarda evidencias externas asociadas a productos WooCommerce sin mezclarlas
 * con wp_comments ni con las reseñas propias de la tienda.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_COMENTARISTA_DB_VERSION')) {
    define('SEO_COMENTARISTA_DB_VERSION', '1.0.0');
}

if (!defined('SEO_COMENTARISTA_DB_VERSION_OPTION')) {
    define('SEO_COMENTARISTA_DB_VERSION_OPTION', 'seo_comentarista_db_version');
}

/**
 * Nombre de la tabla principal.
 *
 * @return string
 */
function seo_comentarista_table_name()
{
    global $wpdb;
    return $wpdb->prefix . 'seo_comentarista';
}

/**
 * Comprueba si la tabla existe.
 *
 * @return bool
 */
function seo_comentarista_table_exists()
{
    global $wpdb;

    $table = seo_comentarista_table_name();
    $found = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
    );

    return $found === $table;
}

/**
 * Instala o repara el esquema del modulo.
 *
 * @param bool $force Fuerza dbDelta aunque la version ya figure instalada.
 * @return true|WP_Error
 */
function seo_comentarista_maybe_install_schema($force = false)
{
    $installed = (string) get_option(SEO_COMENTARISTA_DB_VERSION_OPTION, '0');

    if (!$force && version_compare($installed, SEO_COMENTARISTA_DB_VERSION, '>=') && seo_comentarista_table_exists()) {
        return true;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = seo_comentarista_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,

        content_type VARCHAR(32) NOT NULL DEFAULT 'other',
        source_platform VARCHAR(50) NOT NULL DEFAULT 'web',
        source_name VARCHAR(191) NOT NULL DEFAULT '',
        source_title VARCHAR(500) NULL,
        source_description TEXT NULL,

        source_page_url TEXT NOT NULL,
        source_content_url TEXT NULL,
        source_url_hash CHAR(64) NOT NULL,

        external_content_id VARCHAR(191) NULL,
        source_locator_type VARCHAR(32) NULL,
        source_locator_value VARCHAR(191) NULL,

        author_name VARCHAR(191) NULL,
        author_url TEXT NULL,
        source_published_at DATETIME NULL,
        thumbnail_url TEXT NULL,

        source_excerpt TEXT NULL,
        editorial_summary TEXT NULL,
        content_hash CHAR(64) NULL,

        rating_value DECIMAL(6,2) NULL,
        rating_scale DECIMAL(6,2) NULL,
        sentiment VARCHAR(20) NULL,
        pros LONGTEXT NULL,
        cons LONGTEXT NULL,

        embed_url TEXT NULL,

        product_match_type VARCHAR(32) NULL,
        product_match_value VARCHAR(191) NULL,
        product_match_score DECIMAL(5,2) NULL,

        capture_method VARCHAR(32) NOT NULL DEFAULT 'manual_url',
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        source_status VARCHAR(20) NOT NULL DEFAULT 'unchecked',
        http_status SMALLINT UNSIGNED NULL,
        redirected_url TEXT NULL,
        rejection_reason TEXT NULL,

        is_featured TINYINT(1) NOT NULL DEFAULT 0,
        display_order INT NOT NULL DEFAULT 0,

        evidence_hash CHAR(64) NOT NULL,

        discovered_at DATETIME NOT NULL,
        last_checked_at DATETIME NULL,
        reviewed_at DATETIME NULL,
        published_at DATETIME NULL,
        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,

        PRIMARY KEY  (id),
        UNIQUE KEY product_evidence (product_id, evidence_hash),
        KEY product_status (product_id, status, source_status),
        KEY source_platform (source_platform),
        KEY source_status (source_status),
        KEY source_url_hash (source_url_hash),
        KEY external_content_id (external_content_id),
        KEY content_hash (content_hash),
        KEY last_checked_at (last_checked_at),
        KEY featured_order (product_id, is_featured, display_order)
    ) {$charset_collate};";

    dbDelta($sql);

    if (!seo_comentarista_table_exists()) {
        return new WP_Error(
            'seo_comentarista_table_missing',
            'No se ha podido crear la tabla de Comentarista.'
        );
    }

    update_option(SEO_COMENTARISTA_DB_VERSION_OPTION, SEO_COMENTARISTA_DB_VERSION, false);
    return true;
}

/**
 * Registra la tabla en el Data Layer del sistema.
 *
 * @param array $tables
 * @return array
 */
function seo_comentarista_register_data_layer_table($tables)
{
    $tables = is_array($tables) ? $tables : array();

    $tables['comentarista'] = array(
        'table'       => seo_comentarista_table_name(),
        'primary_key' => array('id'),
        'entity_type' => 'external_product_evidence',
    );

    return $tables;
}
add_filter('seo_data_layer_tables', 'seo_comentarista_register_data_layer_table');

/**
 * Genera el hash que identifica una evidencia concreta dentro de un producto.
 * Permite varias opiniones en una misma pagina siempre que cambie el ID,
 * localizador o contenido seleccionado.
 *
 * @param array $data
 * @return string
 */
function seo_comentarista_build_evidence_hash($data)
{
    $parts = array(
        isset($data['source_url_hash']) ? (string) $data['source_url_hash'] : '',
        isset($data['external_content_id']) ? (string) $data['external_content_id'] : '',
        isset($data['source_locator_type']) ? (string) $data['source_locator_type'] : '',
        isset($data['source_locator_value']) ? (string) $data['source_locator_value'] : '',
        isset($data['content_hash']) ? (string) $data['content_hash'] : '',
    );

    return hash('sha256', implode('|', $parts));
}

/**
 * Inserta una evidencia ya normalizada.
 *
 * @param array $data
 * @return int|WP_Error ID insertado.
 */
function seo_comentarista_insert_evidence($data)
{
    global $wpdb;

    if (!seo_comentarista_table_exists()) {
        $installed = seo_comentarista_maybe_install_schema();
        if (is_wp_error($installed)) {
            return $installed;
        }
    }

    $now = current_time('mysql');

    $defaults = array(
        'content_type'            => 'other',
        'source_platform'         => 'web',
        'source_name'             => '',
        'source_title'            => null,
        'source_description'      => null,
        'source_content_url'      => null,
        'external_content_id'     => null,
        'source_locator_type'     => null,
        'source_locator_value'    => null,
        'author_name'             => null,
        'author_url'              => null,
        'source_published_at'     => null,
        'thumbnail_url'           => null,
        'source_excerpt'          => null,
        'editorial_summary'       => null,
        'content_hash'            => null,
        'rating_value'            => null,
        'rating_scale'            => null,
        'sentiment'               => null,
        'pros'                    => null,
        'cons'                    => null,
        'embed_url'               => null,
        'product_match_type'      => null,
        'product_match_value'     => null,
        'product_match_score'     => null,
        'capture_method'          => 'manual_url',
        'status'                  => 'pending',
        'source_status'           => 'unchecked',
        'http_status'             => null,
        'redirected_url'          => null,
        'rejection_reason'        => null,
        'is_featured'             => 0,
        'display_order'           => 0,
        'discovered_at'           => $now,
        'last_checked_at'         => null,
        'reviewed_at'             => null,
        'published_at'            => null,
        'created_by'              => get_current_user_id(),
        'updated_by'              => get_current_user_id(),
        'created_at'              => $now,
        'updated_at'              => $now,
    );

    $row = array_merge($defaults, is_array($data) ? $data : array());

    $product_id = isset($row['product_id']) ? absint($row['product_id']) : 0;
    if (!$product_id || get_post_type($product_id) !== 'product') {
        return new WP_Error('seo_comentarista_invalid_product', 'El producto indicado no es valido.');
    }

    if (empty($row['source_page_url']) || empty($row['source_url_hash'])) {
        return new WP_Error('seo_comentarista_missing_source', 'La fuente no contiene una URL valida.');
    }

    if (empty($row['evidence_hash'])) {
        $row['evidence_hash'] = seo_comentarista_build_evidence_hash($row);
    }

    $inserted = $wpdb->insert(
        seo_comentarista_table_name(),
        $row
    );

    if ($inserted === false) {
        if (stripos((string) $wpdb->last_error, 'Duplicate entry') !== false) {
            return new WP_Error('seo_comentarista_duplicate', 'Esta evidencia ya esta asociada al producto.');
        }

        return new WP_Error(
            'seo_comentarista_insert_failed',
            'No se pudo guardar la evidencia: ' . $wpdb->last_error
        );
    }

    return (int) $wpdb->insert_id;
}

/**
 * Recupera una evidencia por ID.
 *
 * @param int $id
 * @return array|null
 */
function seo_comentarista_get_evidence($id)
{
    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . seo_comentarista_table_name() . ' WHERE id = %d LIMIT 1',
            absint($id)
        ),
        ARRAY_A
    );

    return is_array($row) ? $row : null;
}

/**
 * Actualiza campos permitidos de una evidencia.
 *
 * @param int   $id
 * @param array $changes
 * @return bool|WP_Error
 */
function seo_comentarista_update_evidence($id, $changes)
{
    global $wpdb;

    $allowed = array(
        'content_type', 'source_platform', 'source_name', 'source_title',
        'source_description', 'source_page_url', 'source_content_url',
        'source_url_hash', 'external_content_id', 'source_locator_type',
        'source_locator_value', 'author_name', 'author_url',
        'source_published_at', 'thumbnail_url', 'source_excerpt',
        'editorial_summary', 'content_hash', 'rating_value', 'rating_scale',
        'sentiment', 'pros', 'cons', 'embed_url', 'product_match_type',
        'product_match_value', 'product_match_score', 'capture_method', 'status',
        'source_status', 'http_status', 'redirected_url', 'rejection_reason',
        'is_featured', 'display_order', 'evidence_hash', 'last_checked_at',
        'reviewed_at', 'published_at',
    );

    $clean = array();
    foreach ((array) $changes as $key => $value) {
        if (in_array($key, $allowed, true)) {
            $clean[$key] = $value;
        }
    }

    if (!$clean) {
        return true;
    }

    $clean['updated_by'] = get_current_user_id();
    $clean['updated_at'] = current_time('mysql');

    $updated = $wpdb->update(
        seo_comentarista_table_name(),
        $clean,
        array('id' => absint($id))
    );

    if ($updated === false) {
        return new WP_Error(
            'seo_comentarista_update_failed',
            'No se pudo actualizar la evidencia: ' . $wpdb->last_error
        );
    }

    return true;
}
