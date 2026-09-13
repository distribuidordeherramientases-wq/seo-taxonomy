<?php
/**
 * Comentarista - esquema y acceso a datos.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_COMENTARISTA_DB_VERSION')) {
    define('SEO_COMENTARISTA_DB_VERSION', '2.0.0');
}

if (!defined('SEO_COMENTARISTA_DB_VERSION_OPTION')) {
    define('SEO_COMENTARISTA_DB_VERSION_OPTION', 'seo_comentarista_db_version');
}

function seo_comentarista_table_name()
{
    global $wpdb;
    return $wpdb->prefix . 'seo_comentarista';
}

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
 * Crea/reconcilia la tabla. No elimina datos existentes.
 * Para sustituir el esquema piloto debe ejecutarse el SQL de reset incluido
 * en el paquete antes de instalar esta version.
 *
 * @param bool $force
 * @return true|WP_Error
 */
function seo_comentarista_maybe_install_schema($force = false)
{
    $installed = (string) get_option(SEO_COMENTARISTA_DB_VERSION_OPTION, '0');

    if (
        !$force
        && version_compare($installed, SEO_COMENTARISTA_DB_VERSION, '>=')
        && seo_comentarista_table_exists()
    ) {
        return true;
    }

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = seo_comentarista_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,

        content_type VARCHAR(30) NOT NULL DEFAULT 'comment',
        source_platform VARCHAR(50) NOT NULL DEFAULT 'web',
        source_name VARCHAR(191) NULL,

        source_url TEXT NULL,
        external_id VARCHAR(191) NULL,
        source_title VARCHAR(500) NULL,

        author_name VARCHAR(191) NULL,
        author_url TEXT NULL,
        source_published_at DATETIME NULL,
        captured_at DATETIME NOT NULL,

        source_content LONGTEXT NULL,
        editorial_summary TEXT NULL,

        rating_value DECIMAL(6,2) NULL,
        rating_scale DECIMAL(6,2) NULL,

        embed_url TEXT NULL,
        thumbnail_url TEXT NULL,

        status VARCHAR(20) NOT NULL DEFAULT 'published',
        display_order INT NOT NULL DEFAULT 0,

        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,

        PRIMARY KEY  (id),
        KEY product_status (product_id, status),
        KEY product_order (product_id, display_order, id),
        KEY content_type (content_type),
        KEY source_platform (source_platform),
        KEY external_id (external_id)
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

function seo_comentarista_register_data_layer_table($tables)
{
    $tables = is_array($tables) ? $tables : array();
    $tables['comentarista'] = array(
        'table'       => seo_comentarista_table_name(),
        'primary_key' => array('id'),
        'entity_type' => 'external_product_content',
    );
    return $tables;
}
add_filter('seo_data_layer_tables', 'seo_comentarista_register_data_layer_table');

function seo_comentarista_allowed_content_types()
{
    return array(
        'comment'     => 'Comentario / reseña',
        'video'       => 'Vídeo',
        'social_post' => 'Publicación social',
        'article'     => 'Artículo / análisis',
        'link'        => 'Enlace externo',
    );
}

function seo_comentarista_allowed_statuses()
{
    return array(
        'published' => 'Publicado',
        'draft'     => 'Borrador',
        'disabled'  => 'Desactivado',
    );
}

function seo_comentarista_validate_product($product_id)
{
    $product_id = absint($product_id);
    return $product_id > 0 && get_post_type($product_id) === 'product';
}

/**
 * Normaliza un registro antes de insertarlo o actualizarlo.
 *
 * @param array $data
 * @param bool  $for_update
 * @return array|WP_Error
 */
function seo_comentarista_normalize_record($data, $for_update = false)
{
    $data = is_array($data) ? $data : array();

    $product_id = absint($data['product_id'] ?? 0);
    if (!seo_comentarista_validate_product($product_id)) {
        return new WP_Error('seo_comentarista_invalid_product', 'El producto indicado no es válido.');
    }

    $types = seo_comentarista_allowed_content_types();
    $type = sanitize_key($data['content_type'] ?? 'comment');
    if (!isset($types[$type])) {
        return new WP_Error('seo_comentarista_invalid_type', 'El tipo de contenido no es válido.');
    }

    $statuses = seo_comentarista_allowed_statuses();
    $status = sanitize_key($data['status'] ?? 'published');
    if (!isset($statuses[$status])) {
        $status = 'draft';
    }

    $source_url = trim((string) ($data['source_url'] ?? ''));
    if ($source_url !== '') {
        $source_url = esc_url_raw($source_url, array('http', 'https'));
        if ($source_url === '') {
            return new WP_Error('seo_comentarista_invalid_url', 'La URL de la fuente no es válida.');
        }
    }

    $source_content = isset($data['source_content'])
        ? wp_kses_post((string) $data['source_content'])
        : '';
    $editorial_summary = isset($data['editorial_summary'])
        ? wp_kses_post((string) $data['editorial_summary'])
        : '';

    if ($type === 'comment' && trim(wp_strip_all_tags($source_content . ' ' . $editorial_summary)) === '') {
        return new WP_Error(
            'seo_comentarista_comment_empty',
            'Un comentario debe contener el texto capturado o un resumen editorial.'
        );
    }

    if (in_array($type, array('video', 'social_post', 'article', 'link'), true) && $source_url === '') {
        return new WP_Error(
            'seo_comentarista_source_required',
            'Este tipo de contenido necesita una URL de origen.'
        );
    }

    $rating_value = null;
    if (array_key_exists('rating_value', $data) && $data['rating_value'] !== '' && $data['rating_value'] !== null) {
        $rating_value = (float) $data['rating_value'];
    }

    $rating_scale = null;
    if (array_key_exists('rating_scale', $data) && $data['rating_scale'] !== '' && $data['rating_scale'] !== null) {
        $rating_scale = (float) $data['rating_scale'];
    }

    if ($rating_value !== null && ($rating_scale === null || $rating_scale <= 0 || $rating_value < 0 || $rating_value > $rating_scale)) {
        return new WP_Error('seo_comentarista_invalid_rating', 'La valoración no es válida para la escala indicada.');
    }

    $source_published_at = null;
    if (!empty($data['source_published_at'])) {
        $candidate = trim((string) $data['source_published_at']);
        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $candidate)) {
            $source_published_at = strlen($candidate) === 10 ? $candidate . ' 00:00:00' : $candidate;
        }
    }

    $captured_at = !empty($data['captured_at']) ? trim((string) $data['captured_at']) : current_time('mysql');
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $captured_at)) {
        $captured_at = current_time('mysql');
    }

    $source_platform = sanitize_key($data['source_platform'] ?? 'web');
    $source_platform = $source_platform !== '' ? $source_platform : 'web';

    $normalized = array(
        'product_id'          => $product_id,
        'content_type'        => $type,
        'source_platform'     => $source_platform,
        'source_name'         => isset($data['source_name']) ? sanitize_text_field((string) $data['source_name']) : null,
        'source_url'          => $source_url !== '' ? $source_url : null,
        'external_id'         => isset($data['external_id']) ? sanitize_text_field((string) $data['external_id']) : null,
        'source_title'        => isset($data['source_title']) ? sanitize_text_field((string) $data['source_title']) : null,
        'author_name'         => isset($data['author_name']) ? sanitize_text_field((string) $data['author_name']) : null,
        'author_url'          => !empty($data['author_url']) ? esc_url_raw((string) $data['author_url'], array('http', 'https')) : null,
        'source_published_at' => $source_published_at,
        'captured_at'         => $captured_at,
        'source_content'      => $source_content !== '' ? $source_content : null,
        'editorial_summary'   => $editorial_summary !== '' ? $editorial_summary : null,
        'rating_value'        => $rating_value,
        'rating_scale'        => $rating_scale,
        'embed_url'           => !empty($data['embed_url']) ? esc_url_raw((string) $data['embed_url'], array('http', 'https')) : null,
        'thumbnail_url'       => !empty($data['thumbnail_url']) ? esc_url_raw((string) $data['thumbnail_url'], array('http', 'https')) : null,
        'status'              => $status,
        'display_order'       => intval($data['display_order'] ?? 0),
    );

    return $normalized;
}

/**
 * Inserta un registro.
 *
 * @param array $data
 * @return int|WP_Error
 */
function seo_comentarista_insert($data)
{
    global $wpdb;

    if (!seo_comentarista_table_exists()) {
        $result = seo_comentarista_maybe_install_schema();
        if (is_wp_error($result)) {
            return $result;
        }
    }

    $row = seo_comentarista_normalize_record($data, false);
    if (is_wp_error($row)) {
        return $row;
    }

    $now = current_time('mysql');
    $row['created_by'] = get_current_user_id();
    $row['updated_by'] = get_current_user_id();
    $row['created_at'] = $now;
    $row['updated_at'] = $now;

    if ($wpdb->insert(seo_comentarista_table_name(), $row) === false) {
        return new WP_Error('seo_comentarista_insert_failed', $wpdb->last_error ?: 'No se pudo guardar el registro.');
    }

    return (int) $wpdb->insert_id;
}

/**
 * Actualiza un registro existente.
 *
 * @param int   $id
 * @param array $data
 * @return true|WP_Error
 */
function seo_comentarista_update($id, $data)
{
    global $wpdb;

    $id = absint($id);
    if (!$id || !seo_comentarista_get($id)) {
        return new WP_Error('seo_comentarista_not_found', 'El registro no existe.');
    }

    $row = seo_comentarista_normalize_record($data, true);
    if (is_wp_error($row)) {
        return $row;
    }

    $row['updated_by'] = get_current_user_id();
    $row['updated_at'] = current_time('mysql');

    $updated = $wpdb->update(
        seo_comentarista_table_name(),
        $row,
        array('id' => $id)
    );

    if ($updated === false) {
        return new WP_Error('seo_comentarista_update_failed', $wpdb->last_error ?: 'No se pudo actualizar el registro.');
    }

    return true;
}

function seo_comentarista_delete($id)
{
    global $wpdb;
    $id = absint($id);
    if (!$id) {
        return false;
    }
    return $wpdb->delete(seo_comentarista_table_name(), array('id' => $id), array('%d')) !== false;
}

function seo_comentarista_get($id)
{
    global $wpdb;
    $id = absint($id);
    if (!$id || !seo_comentarista_table_exists()) {
        return null;
    }
    return $wpdb->get_row(
        $wpdb->prepare('SELECT * FROM ' . seo_comentarista_table_name() . ' WHERE id = %d LIMIT 1', $id),
        ARRAY_A
    );
}

/**
 * Recupera registros de un producto.
 *
 * @param int         $product_id
 * @param string|null $status
 * @param int         $limit
 * @return array
 */
function seo_comentarista_get_by_product($product_id, $status = null, $limit = 50)
{
    global $wpdb;

    $product_id = absint($product_id);
    $limit = max(1, min(200, absint($limit)));

    if (!$product_id || !seo_comentarista_table_exists()) {
        return array();
    }

    if ($status !== null && $status !== '') {
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . seo_comentarista_table_name() . ' WHERE product_id = %d AND status = %s ORDER BY display_order ASC, id DESC LIMIT %d',
                $product_id,
                sanitize_key($status),
                $limit
            ),
            ARRAY_A
        );
    }

    return (array) $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM ' . seo_comentarista_table_name() . ' WHERE product_id = %d ORDER BY display_order ASC, id DESC LIMIT %d',
            $product_id,
            $limit
        ),
        ARRAY_A
    );
}
