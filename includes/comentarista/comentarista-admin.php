<?php
/**
 * Comentarista - administracion manual de evidencias externas.
 */

defined('ABSPATH') || exit;

/**
 * Registra la pantalla dentro de SEO Taxonomy.
 */
function seo_comentarista_register_admin_page()
{
    add_submenu_page(
        'seo-system',
        'Comentarista',
        'Comentarista',
        'manage_options',
        'seo-comentarista',
        'seo_comentarista_admin_page'
    );
}
add_action('admin_menu', 'seo_comentarista_register_admin_page', 35);

/**
 * Carga el buscador AJAX de productos de WooCommerce en la pantalla del modulo.
 *
 * @param string $hook
 */
function seo_comentarista_admin_assets($hook)
{
    if (empty($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== 'seo-comentarista') {
        return;
    }

    if (wp_script_is('wc-enhanced-select', 'registered')) {
        wp_enqueue_script('wc-enhanced-select');
    }
    if (wp_style_is('woocommerce_admin_styles', 'registered')) {
        wp_enqueue_style('woocommerce_admin_styles');
    }
}
add_action('admin_enqueue_scripts', 'seo_comentarista_admin_assets');

/**
 * Sanitiza listas de pros/contras, una linea por elemento.
 *
 * @param string $value
 * @return string|null JSON o null.
 */
function seo_comentarista_admin_json_lines($value)
{
    $value = sanitize_textarea_field((string) $value);
    if ($value === '') {
        return null;
    }

    $items = preg_split('/\r\n|\r|\n/', $value);
    $items = array_values(array_filter(array_map('sanitize_text_field', (array) $items)));

    return $items ? wp_json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
}

/**
 * Limita texto sin exigir la extension mbstring.
 *
 * @param string $value
 * @param int    $length
 * @return string
 */
function seo_comentarista_admin_limit_text($value, $length = 2000)
{
    $value = sanitize_textarea_field((string) $value);
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length);
    }
    return substr($value, 0, $length);
}

/**
 * Procesa las acciones POST de la pantalla.
 *
 * @return array Mensaje [type, text].
 */
function seo_comentarista_handle_admin_action()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['seo_comentarista_action'])) {
        return array();
    }

    if (!current_user_can('manage_options')) {
        return array('error', 'No tienes permisos para realizar esta accion.');
    }

    check_admin_referer('seo_comentarista_admin_action', 'seo_comentarista_nonce');

    $action = sanitize_key(wp_unslash($_POST['seo_comentarista_action']));

    if ($action === 'add') {
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $source_url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '';

        if (!$product_id || get_post_type($product_id) !== 'product') {
            return array('error', 'Selecciona un producto valido.');
        }
        if ($source_url === '') {
            return array('error', 'Indica la URL de la fuente externa.');
        }

        $normalized = seo_comentarista_normalize_url($source_url);
        if ($normalized === '') {
            return array('error', 'La URL indicada no es valida.');
        }

        $probe = seo_comentarista_probe_source($normalized);
        if (is_wp_error($probe)) {
            $probe = array(
                'source_platform'     => seo_comentarista_detect_platform($normalized),
                'source_status'       => 'error',
                'http_status'         => null,
                'external_content_id' => '',
                'embed_url'           => '',
                'source_name'         => '',
                'source_title'        => '',
                'source_description'  => '',
                'author_name'         => '',
                'author_url'          => '',
                'thumbnail_url'       => '',
            );
        }

        $content_type = isset($_POST['content_type']) ? sanitize_key(wp_unslash($_POST['content_type'])) : 'other';
        $allowed_types = array('review', 'comment', 'video', 'short_video', 'article', 'forum_post', 'test', 'comparison', 'other');
        if (!in_array($content_type, $allowed_types, true)) {
            $content_type = 'other';
        }

        if ($content_type === 'other') {
            if ($probe['source_platform'] === 'youtube') {
                $content_type = 'video';
            } elseif ($probe['source_platform'] === 'tiktok') {
                $content_type = 'short_video';
            }
        }

        $source_excerpt = isset($_POST['source_excerpt'])
            ? seo_comentarista_admin_limit_text(wp_unslash($_POST['source_excerpt']), 2000)
            : '';
        $editorial_summary = isset($_POST['editorial_summary'])
            ? seo_comentarista_admin_limit_text(wp_unslash($_POST['editorial_summary']), 2000)
            : '';

        $source_locator_type = isset($_POST['source_locator_type'])
            ? sanitize_key(wp_unslash($_POST['source_locator_type']))
            : '';
        $source_locator_value = isset($_POST['source_locator_value'])
            ? sanitize_text_field(wp_unslash($_POST['source_locator_value']))
            : '';

        if (!in_array($source_locator_type, array('', 'comment_id', 'review_id', 'content_hash', 'css_selector', 'other'), true)) {
            $source_locator_type = 'other';
        }

        if (in_array($content_type, array('review', 'comment', 'article', 'forum_post', 'test', 'comparison'), true)
            && $source_excerpt === ''
            && $editorial_summary === '') {
            return array('error', 'Para contenido escrito indica el comentario seleccionado o un resumen editorial.');
        }

        $source_name = isset($_POST['source_name']) ? sanitize_text_field(wp_unslash($_POST['source_name'])) : '';
        $author_name = isset($_POST['author_name']) ? sanitize_text_field(wp_unslash($_POST['author_name'])) : '';

        if ($source_name === '') {
            $source_name = isset($probe['source_name']) ? sanitize_text_field($probe['source_name']) : '';
        }
        if ($author_name === '') {
            $author_name = isset($probe['author_name']) ? sanitize_text_field($probe['author_name']) : '';
        }

        $status = !empty($_POST['publish_now']) && $probe['source_status'] === 'active'
            ? 'published'
            : 'pending';

        $rating_value = isset($_POST['rating_value']) && $_POST['rating_value'] !== ''
            ? (float) str_replace(',', '.', wp_unslash($_POST['rating_value']))
            : null;
        $rating_scale = isset($_POST['rating_scale']) && $_POST['rating_scale'] !== ''
            ? (float) str_replace(',', '.', wp_unslash($_POST['rating_scale']))
            : null;

        if ($rating_value !== null) {
            if ($rating_value < 0 || $rating_scale === null || $rating_scale <= 0 || $rating_value > $rating_scale) {
                return array('error', 'La valoracion externa no es valida. Comprueba valor y escala.');
            }
        } elseif ($rating_scale !== null && $rating_scale <= 0) {
            return array('error', 'La escala de valoracion debe ser mayor que cero.');
        }

        $sentiment = isset($_POST['sentiment']) ? sanitize_key(wp_unslash($_POST['sentiment'])) : '';
        if (!in_array($sentiment, array('', 'positive', 'mixed', 'neutral', 'negative'), true)) {
            $sentiment = '';
        }

        if ($source_excerpt !== '') {
            $normalized_excerpt = trim(preg_replace('/\s+/u', ' ', $source_excerpt));
            $normalized_excerpt = function_exists('mb_strtolower')
                ? mb_strtolower($normalized_excerpt)
                : strtolower($normalized_excerpt);
            $content_hash = hash('sha256', $normalized_excerpt);
        } else {
            $content_hash = null;
        }

        $now = current_time('mysql');
        $data = array(
            'product_id'             => $product_id,
            'content_type'           => $content_type,
            'source_platform'        => $probe['source_platform'],
            'source_name'            => $source_name,
            'source_title'           => !empty($probe['source_title']) ? $probe['source_title'] : null,
            'source_description'     => !empty($probe['source_description']) ? $probe['source_description'] : null,
            'source_page_url'        => $normalized,
            'source_content_url'     => $normalized,
            'source_url_hash'        => hash('sha256', $normalized),
            'external_content_id'    => !empty($probe['external_content_id']) ? $probe['external_content_id'] : null,
            'source_locator_type'    => $source_locator_type !== '' ? $source_locator_type : null,
            'source_locator_value'   => $source_locator_value !== '' ? $source_locator_value : null,
            'author_name'            => $author_name !== '' ? $author_name : null,
            'author_url'             => !empty($probe['author_url']) ? $probe['author_url'] : null,
            'thumbnail_url'          => !empty($probe['thumbnail_url']) ? $probe['thumbnail_url'] : null,
            'source_excerpt'         => $source_excerpt !== '' ? $source_excerpt : null,
            'editorial_summary'      => $editorial_summary !== '' ? $editorial_summary : null,
            'content_hash'           => $content_hash,
            'rating_value'           => $rating_value,
            'rating_scale'           => $rating_scale,
            'sentiment'              => $sentiment !== '' ? $sentiment : null,
            'pros'                   => isset($_POST['pros']) ? seo_comentarista_admin_json_lines(wp_unslash($_POST['pros'])) : null,
            'cons'                   => isset($_POST['cons']) ? seo_comentarista_admin_json_lines(wp_unslash($_POST['cons'])) : null,
            'embed_url'              => !empty($probe['embed_url']) ? $probe['embed_url'] : null,
            'capture_method'         => $source_excerpt !== '' ? 'manual_content' : 'manual_url',
            'status'                 => $status,
            'source_status'          => $probe['source_status'],
            'http_status'            => isset($probe['http_status']) ? $probe['http_status'] : null,
            'is_featured'            => !empty($_POST['is_featured']) ? 1 : 0,
            'display_order'          => isset($_POST['display_order']) ? intval($_POST['display_order']) : 0,
            'discovered_at'          => $now,
            'last_checked_at'        => $probe['source_status'] !== 'unchecked' ? $now : null,
            'reviewed_at'            => $status === 'published' ? $now : null,
            'published_at'           => $status === 'published' ? $now : null,
        );
        $data['evidence_hash'] = seo_comentarista_build_evidence_hash($data);

        $id = seo_comentarista_insert_evidence($data);
        if (is_wp_error($id)) {
            return array('error', $id->get_error_message());
        }

        $suffix = $status === 'published'
            ? ' y se ha publicado.'
            : '. Se ha guardado como pendiente.';

        return array('success', 'Evidencia #' . absint($id) . ' guardada' . $suffix);
    }

    $id = isset($_POST['evidence_id']) ? absint($_POST['evidence_id']) : 0;
    $row = $id ? seo_comentarista_get_evidence($id) : null;
    if (!$row) {
        return array('error', 'No se ha encontrado la evidencia indicada.');
    }

    if ($action === 'publish') {
        if ($row['source_status'] !== 'active') {
            return array('error', 'La fuente no esta activa. Compruebala antes de publicar.');
        }

        $result = seo_comentarista_update_evidence(
            $id,
            array(
                'status'       => 'published',
                'reviewed_at'  => current_time('mysql'),
                'published_at' => current_time('mysql'),
            )
        );
        return is_wp_error($result) ? array('error', $result->get_error_message()) : array('success', 'Evidencia publicada.');
    }

    if ($action === 'unpublish') {
        $result = seo_comentarista_update_evidence($id, array('status' => 'pending'));
        return is_wp_error($result) ? array('error', $result->get_error_message()) : array('success', 'Evidencia retirada de la ficha.');
    }

    if ($action === 'reject') {
        $result = seo_comentarista_update_evidence(
            $id,
            array(
                'status'           => 'rejected',
                'rejection_reason' => isset($_POST['rejection_reason']) ? sanitize_text_field(wp_unslash($_POST['rejection_reason'])) : 'Descartada manualmente',
                'reviewed_at'      => current_time('mysql'),
            )
        );
        return is_wp_error($result) ? array('error', $result->get_error_message()) : array('success', 'Evidencia descartada.');
    }

    if ($action === 'check') {
        $url = !empty($row['source_content_url']) ? $row['source_content_url'] : $row['source_page_url'];
        $check = seo_comentarista_check_source_url($url);
        $result = seo_comentarista_update_evidence(
            $id,
            array(
                'source_status'   => $check['source_status'],
                'http_status'     => $check['http_status'],
                'last_checked_at' => current_time('mysql'),
            )
        );
        return is_wp_error($result)
            ? array('error', $result->get_error_message())
            : array('success', 'Fuente comprobada: ' . $check['source_status'] . '.');
    }

    return array('error', 'Accion no reconocida.');
}

/**
 * Render de la pantalla administrativa.
 */
function seo_comentarista_admin_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $schema = seo_comentarista_maybe_install_schema();
    if (is_wp_error($schema)) {
        echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html($schema->get_error_message()) . '</p></div></div>';
        return;
    }

    $notice = seo_comentarista_handle_admin_action();

    global $wpdb;
    $table = seo_comentarista_table_name();
    $rows = $wpdb->get_results(
        "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100",
        ARRAY_A
    );
    ?>
    <div class="wrap">
        <h1>Comentarista</h1>
        <p>Fuentes externas asociadas a productos. No se guardan como comentarios de WooCommerce ni se convierten en AggregateRating.</p>

        <?php if ($notice) : ?>
            <div class="notice notice-<?php echo $notice[0] === 'success' ? 'success' : 'error'; ?> is-dismissible"><p><?php echo esc_html($notice[1]); ?></p></div>
        <?php endif; ?>

        <h2>Agregar evidencia manual</h2>
        <form method="post" style="max-width:1000px;background:#fff;border:1px solid #dcdcde;padding:20px;margin-bottom:24px;">
            <?php wp_nonce_field('seo_comentarista_admin_action', 'seo_comentarista_nonce'); ?>
            <input type="hidden" name="seo_comentarista_action" value="add">

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="seo-comentarista-product">Producto</label></th>
                    <td>
                        <select id="seo-comentarista-product" class="wc-product-search" style="width:100%;max-width:700px" name="product_id" data-placeholder="Buscar producto..." data-action="woocommerce_json_search_products_and_variations" required></select>
                        <p class="description">Busca por nombre, SKU o referencia usando el selector de WooCommerce.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="seo-comentarista-url">URL de la fuente</label></th>
                    <td><input id="seo-comentarista-url" class="regular-text code" style="width:100%;max-width:700px" type="url" name="source_url" required></td>
                </tr>
                <tr>
                    <th scope="row">Localizador del comentario</th>
                    <td>
                        <select name="source_locator_type">
                            <option value="">Sin localizador</option>
                            <option value="comment_id">ID de comentario</option>
                            <option value="review_id">ID de review</option>
                            <option value="content_hash">Hash / identificador de contenido</option>
                            <option value="css_selector">Selector CSS</option>
                            <option value="other">Otro</option>
                        </select>
                        <input class="regular-text" name="source_locator_value" placeholder="Opcional: ID o referencia concreta">
                        <p class="description">Util para identificar una opinion concreta cuando la URL apunta a una pagina con muchas reseñas.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="seo-comentarista-type">Tipo</label></th>
                    <td>
                        <select id="seo-comentarista-type" name="content_type">
                            <option value="other">Detectar / otro</option>
                            <option value="review">Review externa</option>
                            <option value="comment">Comentario</option>
                            <option value="video">Video</option>
                            <option value="short_video">Video corto</option>
                            <option value="article">Articulo</option>
                            <option value="forum_post">Foro</option>
                            <option value="test">Prueba</option>
                            <option value="comparison">Comparativa</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="seo-comentarista-source-name">Nombre de la fuente</label></th>
                    <td><input id="seo-comentarista-source-name" class="regular-text" name="source_name" placeholder="Opcional; se intenta detectar"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="seo-comentarista-author">Autor</label></th>
                    <td><input id="seo-comentarista-author" class="regular-text" name="author_name" placeholder="Opcional"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="seo-comentarista-excerpt">Comentario o fragmento seleccionado</label></th>
                    <td>
                        <textarea id="seo-comentarista-excerpt" name="source_excerpt" rows="5" style="width:100%;max-width:700px"></textarea>
                        <p class="description">Guarda solo la parte concreta que quieras documentar, no toda la pagina externa.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="seo-comentarista-summary">Resumen editorial</label></th>
                    <td><textarea id="seo-comentarista-summary" name="editorial_summary" rows="4" style="width:100%;max-width:700px"></textarea></td>
                </tr>
                <tr>
                    <th scope="row">Valoracion en la fuente</th>
                    <td>
                        <input type="number" name="rating_value" min="0" step="0.01" style="width:100px"> /
                        <input type="number" name="rating_scale" min="0.01" step="0.01" value="5" style="width:100px">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="seo-comentarista-sentiment">Sentimiento</label></th>
                    <td>
                        <select id="seo-comentarista-sentiment" name="sentiment">
                            <option value="">Sin clasificar</option>
                            <option value="positive">Positivo</option>
                            <option value="mixed">Mixto</option>
                            <option value="neutral">Neutral</option>
                            <option value="negative">Negativo</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="seo-comentarista-pros">Pros</label></th>
                    <td><textarea id="seo-comentarista-pros" name="pros" rows="3" style="width:100%;max-width:700px" placeholder="Una ventaja por linea"></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="seo-comentarista-cons">Contras</label></th>
                    <td><textarea id="seo-comentarista-cons" name="cons" rows="3" style="width:100%;max-width:700px" placeholder="Un inconveniente por linea"></textarea></td>
                </tr>
                <tr>
                    <th scope="row">Presentacion</th>
                    <td>
                        <label><input type="checkbox" name="is_featured" value="1"> Destacar</label><br>
                        <label>Orden <input type="number" name="display_order" value="0" style="width:90px"></label><br>
                        <label><input type="checkbox" name="publish_now" value="1"> Publicar al guardar si la fuente se puede verificar</label>
                    </td>
                </tr>
            </table>

            <?php submit_button('Guardar y analizar fuente'); ?>
        </form>

        <h2>Ultimas evidencias</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Producto</th>
                    <th>Tipo</th>
                    <th>Fuente</th>
                    <th>Estado editorial</th>
                    <th>Fuente disponible</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows) : ?>
                <tr><td colspan="7">Todavia no hay evidencias.</td></tr>
            <?php else : ?>
                <?php foreach ($rows as $row) :
                    $product_title = get_the_title((int) $row['product_id']);
                    $source_url = !empty($row['source_content_url']) ? $row['source_content_url'] : $row['source_page_url'];
                    ?>
                    <tr>
                        <td><?php echo esc_html($row['id']); ?></td>
                        <td>
                            <?php echo esc_html($product_title ? $product_title : 'Producto #' . $row['product_id']); ?><br>
                            <small>#<?php echo esc_html($row['product_id']); ?></small>
                        </td>
                        <td><?php echo esc_html($row['content_type']); ?></td>
                        <td>
                            <strong><?php echo esc_html($row['source_name'] ? $row['source_name'] : $row['source_platform']); ?></strong><br>
                            <a href="<?php echo esc_url($source_url); ?>" target="_blank" rel="noopener noreferrer">Abrir fuente</a>
                        </td>
                        <td><?php echo esc_html($row['status']); ?></td>
                        <td>
                            <?php echo esc_html($row['source_status']); ?>
                            <?php echo $row['http_status'] !== null ? ' · HTTP ' . esc_html($row['http_status']) : ''; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap">
                                <form method="post">
                                    <?php wp_nonce_field('seo_comentarista_admin_action', 'seo_comentarista_nonce'); ?>
                                    <input type="hidden" name="seo_comentarista_action" value="check">
                                    <input type="hidden" name="evidence_id" value="<?php echo esc_attr($row['id']); ?>">
                                    <button class="button button-small">Comprobar</button>
                                </form>

                                <?php if ($row['status'] !== 'published') : ?>
                                    <form method="post">
                                        <?php wp_nonce_field('seo_comentarista_admin_action', 'seo_comentarista_nonce'); ?>
                                        <input type="hidden" name="seo_comentarista_action" value="publish">
                                        <input type="hidden" name="evidence_id" value="<?php echo esc_attr($row['id']); ?>">
                                        <button class="button button-primary button-small">Publicar</button>
                                    </form>
                                <?php else : ?>
                                    <form method="post">
                                        <?php wp_nonce_field('seo_comentarista_admin_action', 'seo_comentarista_nonce'); ?>
                                        <input type="hidden" name="seo_comentarista_action" value="unpublish">
                                        <input type="hidden" name="evidence_id" value="<?php echo esc_attr($row['id']); ?>">
                                        <button class="button button-small">Retirar</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($row['status'] !== 'rejected') : ?>
                                    <form method="post">
                                        <?php wp_nonce_field('seo_comentarista_admin_action', 'seo_comentarista_nonce'); ?>
                                        <input type="hidden" name="seo_comentarista_action" value="reject">
                                        <input type="hidden" name="evidence_id" value="<?php echo esc_attr($row['id']); ?>">
                                        <button class="button button-small">Descartar</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
