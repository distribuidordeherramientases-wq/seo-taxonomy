<?php
/**
 * Plantilla VEVOR dinámica.
 *
 * Esta plantilla se ejecuta directamente cuando se incluye desde una plantilla
 * de categoría. No necesita que otra plantilla llame a ninguna función.
 *
 * Fuente de datos:
 *   {$wpdb->prefix}seo_proveedores_productos
 *
 * Criterio:
 *   proveedor = vevor
 *   estado_seleccion = descartado
 *   estado_sincronizacion = ignorado
 *   8 productos aleatorios
 */

defined('ABSPATH') || exit;

global $wpdb;

$table = $wpdb->prefix . 'seo_proveedores_productos';
$limit = 8;
$category_context = isset($dht_vevor_category_term) && $dht_vevor_category_term instanceof WP_Term
    ? $dht_vevor_category_term
    : null;
$category_keywords = isset($dht_vevor_category_keywords)
    ? (string) $dht_vevor_category_keywords
    : '';

$items = array();

if ($category_context instanceof WP_Term && 'product_cat' === $category_context->taxonomy) {
    /*
     * En categorías dejamos de usar una selección aleatoria. Primero buscamos
     * categorías VEVOR realmente presentes en productos WooCommerce de esta
     * familia; después completamos por coincidencia textual con el nombre y las
     * keywords de la categoría. El bloque desaparece si no hay afinidad.
     */
    $product_ids = get_posts(array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 250,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => array(array(
            'taxonomy'         => 'product_cat',
            'field'            => 'term_id',
            'terms'            => array((int) $category_context->term_id),
            'include_children' => true,
        )),
    ));

    $provider_categories = array();
    if (!empty($product_ids)) {
        $id_placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT DISTINCT categoria_proveedor
             FROM {$table}
             WHERE proveedor = %s
               AND object_id IN ({$id_placeholders})
               AND categoria_proveedor IS NOT NULL
               AND categoria_proveedor <> ''
             LIMIT 20",
            ...array_merge(array('vevor'), array_map('absint', $product_ids))
        );
        $provider_categories = array_values(array_filter(array_map('trim', (array) $wpdb->get_col($sql))));
    }

    $raw_tokens = preg_split('/[^\\p{L}\\p{N}]+/u', remove_accents(mb_strtolower($category_context->name . ' ' . $category_keywords)));
    $stop = array('de','del','la','las','el','los','y','para','por','con','sin','una','uno','unos','unas','en','a','al','que','categoria','productos','producto');
    $tokens = array();
    foreach ((array) $raw_tokens as $token) {
        $token = trim((string) $token);
        if (mb_strlen($token) < 4 || in_array($token, $stop, true)) {
            continue;
        }
        $tokens[$token] = true;
        if (count($tokens) >= 8) {
            break;
        }
    }
    $tokens = array_keys($tokens);

    $where = array('proveedor = %s');
    $args = array('vevor');
    $relevance_parts = array();

    if ($provider_categories) {
        $cat_placeholders = implode(',', array_fill(0, count($provider_categories), '%s'));
        $where[] = "categoria_proveedor IN ({$cat_placeholders})";
        $args = array_merge($args, $provider_categories);
    } elseif ($tokens) {
        $token_where = array();
        foreach ($tokens as $token) {
            $like = '%' . $wpdb->esc_like($token) . '%';
            $token_where[] = '(nombre LIKE %s OR categoria_proveedor LIKE %s OR descripcion LIKE %s)';
            array_push($args, $like, $like, $like);
        }
        if ($token_where) {
            $where[] = '(' . implode(' OR ', $token_where) . ')';
        }
    }

    if (count($where) > 1) {
        $sql = "SELECT
                    id, proveedor_id_externo, sku, url_origen, url_canonica,
                    nombre, descripcion, categoria_proveedor, precio_con_iva,
                    moneda, imagenes, estado_seleccion, estado_sincronizacion,
                    object_id, actualizado
                FROM {$table}
                WHERE " . implode(' AND ', $where) . "
                  AND (url_canonica IS NOT NULL OR url_origen IS NOT NULL)
                ORDER BY actualizado DESC, id DESC
                LIMIT 80";
        $candidates = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);

        $category_keys = array_fill_keys(array_map(static function ($value) {
            return sanitize_title(remove_accents(mb_strtolower((string) $value)));
        }, $provider_categories), true);
        $current_product_ids = array_fill_keys(array_map('absint', (array) $product_ids), true);

        foreach ((array) $candidates as $candidate) {
            $score = 0;
            $candidate_category = sanitize_title(remove_accents(mb_strtolower((string) ($candidate['categoria_proveedor'] ?? ''))));
            if ($candidate_category !== '' && isset($category_keys[$candidate_category])) {
                $score += 100;
            }

            $name_haystack = remove_accents(mb_strtolower((string) ($candidate['nombre'] ?? '')));
            $cat_haystack  = remove_accents(mb_strtolower((string) ($candidate['categoria_proveedor'] ?? '')));
            $desc_haystack = remove_accents(mb_strtolower((string) ($candidate['descripcion'] ?? '')));
            foreach ($tokens as $token) {
                if (false !== mb_strpos($name_haystack, $token)) $score += 10;
                if (false !== mb_strpos($cat_haystack, $token)) $score += 12;
                if (false !== mb_strpos($desc_haystack, $token)) $score += 3;
            }

            $object_id = absint($candidate['object_id'] ?? 0);
            if ($object_id && isset($current_product_ids[$object_id])) {
                $score -= 10; // Puede aparecer, pero preferimos no duplicar el mismo producto propio.
            } else {
                $score += 5;
            }

            $candidate['_dht_relevance'] = $score;
            if ($score >= 10) {
                $items[] = $candidate;
            }
        }

        usort($items, static function ($a, $b) {
            $cmp = (int) ($b['_dht_relevance'] ?? 0) <=> (int) ($a['_dht_relevance'] ?? 0);
            if ($cmp !== 0) return $cmp;
            return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
        });

        $deduped = array();
        $seen = array();
        foreach ($items as $item) {
            $key = trim((string) ($item['proveedor_id_externo'] ?? ''));
            if ($key === '') $key = trim((string) ($item['sku'] ?? ''));
            if ($key === '') $key = trim((string) ($item['url_canonica'] ?? $item['url_origen'] ?? ''));
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            unset($item['_dht_relevance']);
            $deduped[] = $item;
            if (count($deduped) >= $limit) break;
        }
        $items = $deduped;
    }
} else {
    // En otros contextos conservamos el comportamiento anterior.
    $items = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT
                id, proveedor_id_externo, sku, url_origen, url_canonica,
                nombre, categoria_proveedor, precio_con_iva, moneda, imagenes,
                estado_seleccion, estado_sincronizacion
             FROM {$table}
             WHERE proveedor = %s
               AND estado_seleccion = %s
               AND estado_sincronizacion = %s
             ORDER BY RAND()
             LIMIT %d",
            'vevor', 'descartado', 'ignorado', $limit
        ),
        ARRAY_A
    );
}

if (!is_array($items)) {
    $items = array();
}

/* Extrae la primera URL de imagen válida de distintos formatos posibles. */
$vevor_first_image = static function ($raw) {
    if (is_array($raw)) {
        $candidates = $raw;
    } else {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $candidates = $decoded;
        } else {
            $candidates = preg_split('/[\r\n|,;]+/', $raw);
        }
    }

    $queue = array_values((array) $candidates);

    while ($queue) {
        $value = array_shift($queue);

        if (is_array($value)) {
            foreach (array('url', 'image_url', 'src', 'image') as $key) {
                if (!empty($value[$key])) {
                    array_unshift($queue, $value[$key]);
                }
            }
            continue;
        }

        $url = esc_url_raw(trim((string) $value));
        if ($url !== '') {
            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if (in_array($scheme, array('http', 'https'), true)) {
                return $url;
            }
        }
    }

    return '';
};

/* Convierte la URL del producto en enlace de afiliado VEVOR. */
$vevor_affiliate_url = static function ($row) {
    $url = trim((string) ($row['url_canonica'] ?? ''));
    if ($url === '') {
        $url = trim((string) ($row['url_origen'] ?? ''));
    }

    $url = esc_url_raw($url);
    if ($url === '') {
        return '';
    }

    return add_query_arg(
        array(
            'utm_source'   => 'inhouse',
            'utm_medium'   => 'affiliate',
            'utm_campaign' => '53435399',
        ),
        $url
    );
};

/* Si no hay filas, no mostramos un bloque vacío al visitante. */
if (!$items) {
    if (current_user_can('manage_options')) {
        $error = trim((string) $wpdb->last_error);
        echo '<!-- VEVOR: 0 productos encontrados en ' . esc_html($table) . '. SQL error: ' . esc_html($error) . ' -->';
    }
    return;
}
?>

<section class="dht-vevor-products" aria-labelledby="dht-vevor-products-title">
    <div class="dht-vevor-products__inner">
        <header class="dht-vevor-products__header">
            <span class="dht-vevor-products__kicker">Más opciones relacionadas</span>
            <h2 id="dht-vevor-products-title"><?php echo $category_context instanceof WP_Term ? 'También en VEVOR: ' . esc_html($category_context->name) : 'Productos destacados en VEVOR'; ?></h2>
            <p><?php echo $category_context instanceof WP_Term ? 'Opciones VEVOR seleccionadas por afinidad con esta categoría. La compra se completa en VEVOR.' : 'Una selección de productos disponibles en VEVOR.'; ?></p>
        </header>

        <div class="dht-vevor-products__grid">
            <?php foreach ($items as $item) :
                $title = trim((string) ($item['nombre'] ?? 'Producto VEVOR'));
                $category = trim((string) ($item['categoria_proveedor'] ?? 'VEVOR'));
                $image = $vevor_first_image($item['imagenes'] ?? '');
                $url = $vevor_affiliate_url($item);
                $price = isset($item['precio_con_iva']) ? (float) $item['precio_con_iva'] : 0.0;
                $currency = strtoupper(trim((string) ($item['moneda'] ?? 'EUR')));
            ?>
                <article class="dht-vevor-product" data-vevor-id="<?php echo esc_attr((string) ($item['id'] ?? '')); ?>">
                    <?php if ($url !== '') : ?>
                        <a class="dht-vevor-product__media" href="<?php echo esc_url($url); ?>" target="_blank" rel="sponsored noopener">
                    <?php else : ?>
                        <div class="dht-vevor-product__media">
                    <?php endif; ?>

                        <?php if ($image !== '') : ?>
                            <img
                                src="<?php echo esc_url($image); ?>"
                                alt="<?php echo esc_attr($title); ?>"
                                loading="lazy"
                                decoding="async"
                            >
                        <?php else : ?>
                            <span class="dht-vevor-product__no-image">VEVOR</span>
                        <?php endif; ?>

                    <?php if ($url !== '') : ?>
                        </a>
                    <?php else : ?>
                        </div>
                    <?php endif; ?>

                    <div class="dht-vevor-product__body">
                        <?php if ($category !== '') : ?>
                            <span class="dht-vevor-product__category"><?php echo esc_html($category); ?></span>
                        <?php endif; ?>

                        <h3><?php echo esc_html($title); ?></h3>

                        <?php if ($price > 0) : ?>
                            <div class="dht-vevor-product__price">
                                <?php
                                if ($currency === 'EUR' && function_exists('wc_price')) {
                                    echo wp_kses_post(wc_price($price));
                                } else {
                                    echo esc_html(number_format_i18n($price, 2) . ' ' . ($currency ?: 'EUR'));
                                }
                                ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($url !== '') : ?>
                            <a class="dht-vevor-product__button" href="<?php echo esc_url($url); ?>" target="_blank" rel="sponsored noopener">
                                Ver en VEVOR <span aria-hidden="true">→</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <p class="dht-vevor-products__notice">Enlaces de afiliado. Podemos recibir una comisión si realizas una compra, sin coste adicional para ti.</p>
    </div>
</section>

