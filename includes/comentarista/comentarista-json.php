<?php
/**
 * Comentarista - indicadores JSON.
 *
 * Expone una estructura estable y reutilizable para que otros módulos,
 * incluido el propio producto, puedan consultar los indicadores principales
 * sin depender de la pantalla de administración.
 */

defined('ABSPATH') || exit;

/**
 * Devuelve los indicadores principales de Comentarista.
 *
 * Con $product_id = 0 devuelve indicadores globales. Con un producto válido,
 * limita todos los KPIs y distribuciones a ese producto.
 *
 * @param int $product_id
 * @return array
 */
function seo_comentarista_get_indicators($product_id = 0)
{
    global $wpdb;

    $product_id = absint($product_id);
    $table = seo_comentarista_table_name();

    $base = array(
        'service'      => 'comentarista',
        'version'      => defined('SEO_COMENTARISTA_VERSION') ? SEO_COMENTARISTA_VERSION : '2.1.0',
        'generated_at' => current_time('c'),
        'scope'        => array('type' => 'global'),
        'kpis'         => array(
            'records_total'        => 0,
            'published'            => 0,
            'draft'                => 0,
            'disabled'             => 0,
            'comments_total'       => 0,
            'comments_published'   => 0,
            'rated_comments'       => 0,
            'average_rating_5'     => null,
            'sources_distinct'     => 0,
            'latest_captured_at'   => null,
        ),
        'distribution' => array(
            'content_types' => array(),
            'statuses'      => array(),
            'sources'       => array(),
        ),
    );

    if (!seo_comentarista_table_exists()) {
        $base['status'] = 'table_missing';
        return $base;
    }

    if ($product_id) {
        if (!seo_comentarista_validate_product($product_id)) {
            $base['status'] = 'invalid_product';
            $base['scope'] = array('type' => 'product', 'product_id' => $product_id);
            return $base;
        }

        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $base['scope'] = array(
            'type'       => 'product',
            'product_id' => $product_id,
            'sku'        => $product ? (string) $product->get_sku() : '',
            'name'       => $product ? (string) $product->get_name() : (string) get_the_title($product_id),
        );
    }

    $where = '';
    $where_args = array();
    if ($product_id) {
        $where = ' WHERE product_id = %d';
        $where_args[] = $product_id;
    }

    $summary_sql = "SELECT
        COUNT(*) AS records_total,
        SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft,
        SUM(CASE WHEN status = 'disabled' THEN 1 ELSE 0 END) AS disabled,
        SUM(CASE WHEN content_type = 'comment' THEN 1 ELSE 0 END) AS comments_total,
        SUM(CASE WHEN content_type = 'comment' AND status = 'published' THEN 1 ELSE 0 END) AS comments_published,
        SUM(CASE WHEN content_type = 'comment' AND rating_value IS NOT NULL AND rating_scale IS NOT NULL AND rating_scale > 0 THEN 1 ELSE 0 END) AS rated_comments,
        AVG(CASE WHEN content_type = 'comment' AND rating_value IS NOT NULL AND rating_scale IS NOT NULL AND rating_scale > 0 THEN (rating_value / rating_scale) * 5 ELSE NULL END) AS average_rating_5,
        COUNT(DISTINCT CASE WHEN COALESCE(NULLIF(source_name, ''), NULLIF(source_platform, '')) IS NOT NULL THEN COALESCE(NULLIF(source_name, ''), NULLIF(source_platform, '')) END) AS sources_distinct,
        MAX(captured_at) AS latest_captured_at
        FROM {$table}{$where}";

    if ($where_args) {
        $summary_sql = $wpdb->prepare($summary_sql, $where_args);
    }
    $summary = (array) $wpdb->get_row($summary_sql, ARRAY_A);

    foreach (array('records_total', 'published', 'draft', 'disabled', 'comments_total', 'comments_published', 'rated_comments', 'sources_distinct') as $key) {
        $base['kpis'][$key] = (int) ($summary[$key] ?? 0);
    }
    $base['kpis']['average_rating_5'] = isset($summary['average_rating_5']) && $summary['average_rating_5'] !== null
        ? round((float) $summary['average_rating_5'], 2)
        : null;
    $base['kpis']['latest_captured_at'] = !empty($summary['latest_captured_at'])
        ? (string) $summary['latest_captured_at']
        : null;

    $distribution_specs = array(
        'content_types' => 'content_type',
        'statuses'      => 'status',
    );

    foreach ($distribution_specs as $target => $column) {
        $sql = "SELECT {$column} AS label, COUNT(*) AS total FROM {$table}{$where} GROUP BY {$column} ORDER BY total DESC, label ASC";
        if ($where_args) {
            $sql = $wpdb->prepare($sql, $where_args);
        }
        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        foreach ($rows as $row) {
            $base['distribution'][$target][(string) $row['label']] = (int) $row['total'];
        }
    }

    $source_sql = "SELECT
            COALESCE(NULLIF(source_name, ''), NULLIF(source_platform, ''), 'sin_fuente') AS source,
            COUNT(*) AS total,
            SUM(CASE WHEN content_type = 'comment' THEN 1 ELSE 0 END) AS comments
        FROM {$table}{$where}
        GROUP BY source
        ORDER BY total DESC, source ASC
        LIMIT 20";
    if ($where_args) {
        $source_sql = $wpdb->prepare($source_sql, $where_args);
    }
    $source_rows = (array) $wpdb->get_results($source_sql, ARRAY_A);
    foreach ($source_rows as $row) {
        $base['distribution']['sources'][] = array(
            'source'   => (string) $row['source'],
            'records'  => (int) $row['total'],
            'comments' => (int) $row['comments'],
        );
    }

    if (!$product_id && function_exists('seo_comentarista_coverage_stats')) {
        $base['coverage'] = seo_comentarista_coverage_stats();
    }

    $base['status'] = 'ok';
    return $base;
}

/**
 * Atajo para consumidores internos que necesiten JSON directamente.
 */
function seo_comentarista_get_indicators_json($product_id = 0, $pretty = false)
{
    $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) {
        $options |= JSON_PRETTY_PRINT;
    }
    return wp_json_encode(seo_comentarista_get_indicators($product_id), $options);
}

/**
 * Endpoint REST autenticado para integraciones internas.
 * GET /wp-json/seo-system/v1/comentarista/indicators?product_id=123
 */
function seo_comentarista_register_indicators_rest_route()
{
    register_rest_route('seo-system/v1', '/comentarista/indicators', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'seo_comentarista_rest_indicators',
        'permission_callback' => static function () {
            return current_user_can('manage_options');
        },
        'args'                => array(
            'product_id' => array(
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => 'absint',
            ),
        ),
    ));
}
add_action('rest_api_init', 'seo_comentarista_register_indicators_rest_route');

function seo_comentarista_rest_indicators(WP_REST_Request $request)
{
    return rest_ensure_response(
        seo_comentarista_get_indicators(absint($request->get_param('product_id')))
    );
}

/**
 * Descarga administrativa del JSON, global o por producto.
 */
function seo_comentarista_download_indicators_json()
{
    if (!isset($_GET['seo_comentarista_download_json'])) {
        return;
    }
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para exportar los indicadores.', 'seo-system'));
    }

    check_admin_referer('seo_comentarista_download_json');

    $product_id = absint($_GET['product_id'] ?? 0);
    $data = seo_comentarista_get_indicators($product_id);
    $filename = $product_id
        ? 'comentarista-indicadores-producto-' . $product_id . '-' . wp_date('Ymd_His') . '.json'
        : 'comentarista-indicadores-' . wp_date('Ymd_His') . '.json';

    nocache_headers();
    header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
    echo wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit;
}
add_action('admin_init', 'seo_comentarista_download_indicators_json');

/**
 * Pantalla administrativa de indicadores JSON.
 */
function seo_comentarista_json_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-system'));
    }

    $product_id = absint($_GET['product_id'] ?? 0);
    $data = seo_comentarista_get_indicators($product_id);
    $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    $download_url = wp_nonce_url(
        add_query_arg(
            array(
                'page'                          => 'seo-comentarista',
                'view'                          => 'json',
                'seo_comentarista_download_json'=> 1,
                'product_id'                    => $product_id,
            ),
            admin_url('admin.php')
        ),
        'seo_comentarista_download_json'
    );
    ?>
    <div class="wrap">
        <h1>Comentarista</h1>
        <?php if (function_exists('seo_comentarista_admin_tabs')) : ?>
            <?php seo_comentarista_admin_tabs('json'); ?>
        <?php endif; ?>

        <p>Indicadores principales en formato JSON. Puede consultarse el conjunto global o un producto concreto. Los datos son descriptivos de los registros almacenados; no presuponen que una opinión externa sea verificada.</p>

        <form method="get" style="margin:18px 0;display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
            <input type="hidden" name="page" value="seo-comentarista">
            <input type="hidden" name="view" value="json">
            <label><strong>ID de producto (opcional)</strong><br><input type="number" min="1" name="product_id" value="<?php echo $product_id ? esc_attr($product_id) : ''; ?>" placeholder="Ej. 12345"></label>
            <button type="submit" class="button">Consultar</button>
            <?php if ($product_id) : ?>
                <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'seo-comentarista', 'view' => 'json'), admin_url('admin.php'))); ?>">Ver global</a>
            <?php endif; ?>
            <a class="button button-primary" href="<?php echo esc_url($download_url); ?>">Descargar JSON</a>
        </form>

        <textarea readonly class="large-text code" rows="32" style="max-width:1200px;white-space:pre;"><?php echo esc_textarea((string) $json); ?></textarea>

        <p class="description">Uso interno desde PHP: <code>seo_comentarista_get_indicators($product_id)</code> o <code>seo_comentarista_get_indicators_json($product_id, true)</code>. REST autenticado: <code>/wp-json/seo-system/v1/comentarista/indicators?product_id=123</code>.</p>
    </div>
    <?php
}
