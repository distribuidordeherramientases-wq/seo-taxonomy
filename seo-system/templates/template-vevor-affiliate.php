<?php
/**
 * Bloque de descubrimiento VEVOR para fichas de producto y categorias.
 *
 * Objetivo comercial:
 * - monetizar trafico mediante enlaces de afiliado VEVOR;
 * - NO ofrecer sustitutos directos del producto/familia que el cliente esta viendo;
 * - aprovechar cualquier fila VEVOR valida del catalogo intermedio, con
 *   independencia de estado_seleccion (descartado, aceptado, pendiente, revisar).
 *
 * El enlace de afiliado se resuelve de forma independiente de url_origen.
 * Esto evita confundir la URL limpia del proveedor con una URL de tracking.
 *
 * @version 0.1.1
 */

defined('ABSPATH') || exit;

if (!function_exists('dht_vevor_affiliate_normalize')) {
    function dht_vevor_affiliate_normalize($text)
    {
        $text = remove_accents(mb_strtolower(wp_strip_all_tags((string) $text)));
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}

if (!function_exists('dht_vevor_affiliate_stem')) {
    function dht_vevor_affiliate_stem($token)
    {
        $token = dht_vevor_affiliate_normalize($token);
        if ($token === '') {
            return '';
        }

        // Singularizacion deliberadamente conservadora para familias de producto.
        $length = strlen($token);
        if ($length > 6 && substr($token, -2) === 'es') {
            $token = substr($token, 0, -2);
        } elseif ($length > 5 && substr($token, -1) === 's') {
            $token = substr($token, 0, -1);
        }

        return $token;
    }
}

if (!function_exists('dht_vevor_affiliate_tokens')) {
    function dht_vevor_affiliate_tokens($text)
    {
        $stop = array_fill_keys(array(
            'vevor', 'producto', 'productos', 'herramienta', 'herramientas', 'profesional', 'profesionales',
            'equipo', 'equipos', 'maquina', 'maquinas', 'accesorio', 'accesorios', 'repuesto', 'repuestos',
            'recambio', 'recambios', 'juego', 'juegos', 'kit', 'kits', 'set', 'para', 'con', 'sin', 'por',
            'del', 'las', 'los', 'una', 'uno', 'unos', 'unas', 'este', 'esta', 'estos', 'estas', 'tipo',
            'modelo', 'modelos', 'color', 'medida', 'medidas', 'tamano', 'tamanos', 'uso', 'usos', 'nuevo',
            'nueva', 'calidad', 'alta', 'alto', 'bajo', 'baja', 'incluye', 'incluido', 'incluidos', 'incluidas',
            'pieza', 'piezas', 'unidad', 'unidades', 'marca', 'version', 'versiones', 'serie', 'sistema',
            'material', 'materiales', 'capacidad', 'potencia', 'electrico', 'electrica', 'manual', 'digital',
            'general', 'varios', 'otras', 'otros', 'espana', 'hogar', 'taller'
        ), true);

        $tokens = array();
        foreach (preg_split('/\s+/u', dht_vevor_affiliate_normalize($text)) as $token) {
            $token = dht_vevor_affiliate_stem($token);
            if ($token === '' || strlen($token) < 4 || isset($stop[$token])) {
                continue;
            }
            $tokens[$token] = true;
        }

        return array_keys($tokens);
    }
}

if (!function_exists('dht_vevor_affiliate_parse_images')) {
    function dht_vevor_affiliate_parse_images($raw, $limit = 4)
    {
        $limit = max(1, min(8, absint($limit)));
        $urls = array();
        $seen = array();

        $add = static function ($value) use (&$urls, &$seen, $limit) {
            if (count($urls) >= $limit) {
                return;
            }
            if (is_array($value)) {
                foreach (array('url', 'image_url', 'src', 'image') as $key) {
                    if (!empty($value[$key])) {
                        $value = $value[$key];
                        break;
                    }
                }
            }
            $url = esc_url_raw(trim((string) $value));
            if ($url === '' || isset($seen[$url])) {
                return;
            }
            $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, array('http', 'https'), true)) {
                return;
            }
            $seen[$url] = true;
            $urls[] = $url;
        };

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $value) {
                $add($value);
            }
        } else {
            foreach (preg_split('/[\r\n|,;]+/', (string) $raw) as $value) {
                $add($value);
            }
        }

        return $urls;
    }
}

if (!function_exists('dht_vevor_affiliate_destination_url')) {
    function dht_vevor_affiliate_destination_url($row)
    {
        foreach (array('url_canonica', 'url_origen') as $key) {
            $url = esc_url_raw(trim((string) ($row[$key] ?? '')));
            if ($url === '') {
                continue;
            }
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            if ($host !== '' && (strpos($host, 'vevor.') !== false || strpos($host, 'vevorstatic.') !== false)) {
                return $url;
            }
        }
        return '';
    }
}

if (!function_exists('dht_vevor_affiliate_settings')) {
    /**
     * Configuracion de tracking VEVOR.
     *
     * El programa utilizado por la tienda genera enlaces nativos de VEVOR con:
     *   utm_source=inhouse
     *   utm_medium=affiliate
     *   utm_campaign=<id de afiliado>
     *   shortkey=<id propio del enlace, cuando VEVOR lo ha generado>
     *
     * El campaign_id 53435399 se ha confirmado con enlaces generados desde la
     * cuenta de afiliados. shortkey NO se inventa ni se reutiliza entre productos.
     * Si una URL almacenada ya contiene shortkey, se conserva intacto.
     */
    function dht_vevor_affiliate_settings()
    {
        $saved = get_option('seo_vevor_affiliate_settings', array());
        $saved = is_array($saved) ? $saved : array();

        $settings = array(
            'network'           => sanitize_key((string) ($saved['network'] ?? 'vevor_inhouse')),
            'campaign_id'       => preg_replace('/[^0-9]/', '', (string) ($saved['campaign_id'] ?? '53435399')),
            'advertiser_id'     => sanitize_text_field((string) ($saved['advertiser_id'] ?? '')),
            'publisher_id'      => sanitize_text_field((string) ($saved['publisher_id'] ?? '')),
            'deeplink_template' => trim((string) ($saved['deeplink_template'] ?? '')),
            'allow_direct'      => !empty($saved['allow_direct']),
        );

        if (defined('SEO_VEVOR_AFFILIATE_NETWORK')) {
            $settings['network'] = sanitize_key((string) SEO_VEVOR_AFFILIATE_NETWORK);
        }
        if (defined('SEO_VEVOR_AFFILIATE_CAMPAIGN_ID')) {
            $settings['campaign_id'] = preg_replace('/[^0-9]/', '', (string) SEO_VEVOR_AFFILIATE_CAMPAIGN_ID);
        }
        if (defined('SEO_VEVOR_AFFILIATE_ADVERTISER_ID')) {
            $settings['advertiser_id'] = sanitize_text_field((string) SEO_VEVOR_AFFILIATE_ADVERTISER_ID);
        }
        if (defined('SEO_VEVOR_AFFILIATE_PUBLISHER_ID')) {
            $settings['publisher_id'] = sanitize_text_field((string) SEO_VEVOR_AFFILIATE_PUBLISHER_ID);
        }
        if (defined('SEO_VEVOR_AFFILIATE_DEEPLINK_TEMPLATE')) {
            $settings['deeplink_template'] = trim((string) SEO_VEVOR_AFFILIATE_DEEPLINK_TEMPLATE);
        }
        if (defined('SEO_VEVOR_AFFILIATE_ALLOW_DIRECT')) {
            $settings['allow_direct'] = (bool) SEO_VEVOR_AFFILIATE_ALLOW_DIRECT;
        }

        return apply_filters('dht_vevor_affiliate_settings', $settings);
    }
}

if (!function_exists('dht_vevor_affiliate_url_has_tracking')) {
    function dht_vevor_affiliate_url_has_tracking($url, $campaign_id = '')
    {
        $url = esc_url_raw((string) $url);
        if ($url === '') {
            return false;
        }
        $query = array();
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        $campaign = preg_replace('/[^0-9]/', '', (string) ($query['utm_campaign'] ?? ''));
        if ($campaign === '') {
            return false;
        }
        $campaign_id = preg_replace('/[^0-9]/', '', (string) $campaign_id);
        return $campaign_id === '' || hash_equals($campaign_id, $campaign);
    }
}

if (!function_exists('dht_vevor_affiliate_existing_url')) {
    /**
     * Recupera primero un deeplink VEVOR ya generado, porque su shortkey es
     * especifico del enlace y no debe copiarse a otro producto.
     */
    function dht_vevor_affiliate_existing_url($row, $campaign_id = '')
    {
        foreach (array('url_origen', 'url_canonica') as $key) {
            $candidate = esc_url_raw(trim((string) ($row[$key] ?? '')));
            if ($candidate !== '' && dht_vevor_affiliate_url_has_tracking($candidate, $campaign_id)) {
                return $candidate;
            }
        }

        $object_id = absint($row['object_id'] ?? 0);
        if ($object_id) {
            $content = (string) get_post_field('post_content', $object_id);
            if ($content !== '' && preg_match_all('~https?://[^\s<>"\']*vevor\.[^\s<>"\']+~i', html_entity_decode($content), $matches)) {
                foreach ((array) ($matches[0] ?? array()) as $candidate) {
                    $candidate = esc_url_raw(rtrim((string) $candidate, "'\".,;)>]"));
                    if ($candidate !== '' && dht_vevor_affiliate_url_has_tracking($candidate, $campaign_id)) {
                        return $candidate;
                    }
                }
            }
        }

        return '';
    }
}

if (!function_exists('dht_vevor_affiliate_url')) {
    function dht_vevor_affiliate_url($destination, $row = array(), $clickref = 'dht_vevor')
    {
        $destination = esc_url_raw((string) $destination);
        if ($destination === '') {
            return '';
        }

        // Permite sustituir el resolver desde otra parte del plugin.
        $filtered = apply_filters('dht_vevor_affiliate_url', '', $destination, $row, $clickref);
        $filtered = esc_url_raw((string) $filtered);
        if ($filtered !== '') {
            return $filtered;
        }

        $settings = dht_vevor_affiliate_settings();
        $network = sanitize_key((string) ($settings['network'] ?? 'vevor_inhouse'));
        $campaign_id = preg_replace('/[^0-9]/', '', (string) ($settings['campaign_id'] ?? ''));
        $publisher_id = preg_replace('/[^0-9]/', '', (string) ($settings['publisher_id'] ?? ''));
        $advertiser_id = preg_replace('/[^0-9]/', '', (string) ($settings['advertiser_id'] ?? ''));
        $clickref = sanitize_key((string) $clickref);

        if ($network === 'vevor_inhouse' && $campaign_id !== '') {
            // Si ya conocemos el enlace oficial generado por VEVOR, conserva su shortkey.
            $existing = dht_vevor_affiliate_existing_url($row, $campaign_id);
            if ($existing !== '') {
                return $existing;
            }

            // Para filas sin shortkey conocido se conserva el deeplink exacto del
            // producto y se aplican los tres parametros estables confirmados.
            // No se fabrica shortkey: VEVOR asigna uno distinto a cada enlace.
            return add_query_arg(
                array(
                    'utm_source'   => 'inhouse',
                    'utm_medium'   => 'affiliate',
                    'utm_campaign' => $campaign_id,
                ),
                remove_query_arg(array('utm_source', 'utm_medium', 'utm_campaign', 'utm_format_creative', 'shortkey'), $destination)
            );
        }

        // Compatibilidad con instalaciones que resuelvan el enlace mediante Awin.
        if ($network === 'awin' && $publisher_id !== '' && $advertiser_id !== '') {
            return add_query_arg(
                array(
                    'awinmid'   => $advertiser_id,
                    'awinaffid' => $publisher_id,
                    'clickref'  => $clickref,
                    'ued'       => $destination,
                ),
                'https://www.awin1.com/cread.php'
            );
        }

        $template = trim((string) ($settings['deeplink_template'] ?? ''));
        if ($template !== '') {
            $replacements = array(
                '{url}'         => $destination,
                '{url_encoded}' => rawurlencode($destination),
                '{id}'          => rawurlencode((string) ($row['proveedor_id_externo'] ?? '')),
                '{sku}'         => rawurlencode((string) ($row['sku'] ?? '')),
                '{clickref}'    => rawurlencode($clickref),
            );
            $url = esc_url_raw(strtr($template, $replacements));
            if ($url !== '') {
                return $url;
            }
        }

        if (!empty($settings['allow_direct'])) {
            return $destination;
        }

        return '';
    }
}

if (!function_exists('dht_vevor_affiliate_tracking_ready')) {
    function dht_vevor_affiliate_tracking_ready()
    {
        $settings = dht_vevor_affiliate_settings();
        if (sanitize_key((string) ($settings['network'] ?? 'vevor_inhouse')) === 'vevor_inhouse'
            && preg_replace('/[^0-9]/', '', (string) ($settings['campaign_id'] ?? '')) !== '') {
            return true;
        }
        if (!empty($settings['allow_direct'])) {
            return true;
        }
        if (trim((string) ($settings['deeplink_template'] ?? '')) !== '') {
            return true;
        }
        return sanitize_key((string) ($settings['network'] ?? '')) === 'awin'
            && preg_replace('/[^0-9]/', '', (string) ($settings['publisher_id'] ?? '')) !== ''
            && preg_replace('/[^0-9]/', '', (string) ($settings['advertiser_id'] ?? '')) !== '';
    }
}

if (!function_exists('dht_vevor_affiliate_context_product')) {
    function dht_vevor_affiliate_context_product($product)
    {
        if (!is_a($product, 'WC_Product')) {
            return array();
        }

        $product_id = $product->get_id();
        $parts = array($product->get_name(), $product->get_short_description());
        $blocked_terms = array();

        $categories = wp_get_post_terms($product_id, 'product_cat');
        if (!is_wp_error($categories)) {
            foreach ((array) $categories as $term) {
                $parts[] = $term->name;
                $blocked_terms[] = (int) $term->term_id;
                foreach (get_ancestors($term->term_id, 'product_cat') as $ancestor_id) {
                    $blocked_terms[] = (int) $ancestor_id;
                }
            }
        }

        $tags = wp_get_post_terms($product_id, 'product_tag', array('fields' => 'names'));
        if (!is_wp_error($tags)) {
            $parts = array_merge($parts, (array) $tags);
        }

        return array(
            'type'              => 'product',
            'object_id'         => $product_id,
            'label'             => $product->get_name(),
            'text'              => implode(' | ', array_filter($parts)),
            'blocked_term_ids'  => array_values(array_unique(array_map('absint', $blocked_terms))),
            'rotation_key'      => 'product:' . $product_id,
        );
    }
}

if (!function_exists('dht_vevor_affiliate_context_category')) {
    function dht_vevor_affiliate_context_category($term)
    {
        if (!$term instanceof WP_Term || $term->taxonomy !== 'product_cat') {
            return array();
        }

        $parts = array($term->name, $term->description);
        foreach (get_ancestors($term->term_id, 'product_cat') as $ancestor_id) {
            $ancestor = get_term((int) $ancestor_id, 'product_cat');
            if ($ancestor instanceof WP_Term && !is_wp_error($ancestor)) {
                $parts[] = $ancestor->name;
            }
        }

        $blocked_terms = array((int) $term->term_id);
        $children = get_term_children($term->term_id, 'product_cat');
        if (!is_wp_error($children)) {
            $blocked_terms = array_merge($blocked_terms, array_map('absint', (array) $children));
        }

        return array(
            'type'              => 'category',
            'object_id'         => (int) $term->term_id,
            'label'             => $term->name,
            'text'              => implode(' | ', array_filter($parts)),
            'blocked_term_ids'  => array_values(array_unique(array_map('absint', $blocked_terms))),
            'rotation_key'      => 'category:' . (int) $term->term_id,
        );
    }
}

if (!function_exists('dht_vevor_affiliate_candidate_rows')) {
    function dht_vevor_affiliate_candidate_rows($pool_limit = 600)
    {
        global $wpdb;
        static $cache = array();

        $pool_limit = max(80, min(1000, absint($pool_limit)));
        if (isset($cache[$pool_limit])) {
            return $cache[$pool_limit];
        }

        $table = $wpdb->prefix . 'seo_proveedores_productos';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        if ($exists !== $table) {
            return $cache[$pool_limit] = array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, proveedor_id_externo, sku, url_origen, url_canonica, nombre,
                        descripcion, categoria_proveedor, precio_con_iva, moneda,
                        stock_estado, stock_cantidad, stock_texto, imagenes, http_status,
                        estado_seleccion, estado_sincronizacion, object_id,
                        ultima_importacion, actualizado
                 FROM {$table}
                 WHERE proveedor = %s
                   AND nombre IS NOT NULL
                   AND TRIM(nombre) <> ''
                   AND imagenes IS NOT NULL
                   AND TRIM(imagenes) <> ''
                   AND (url_canonica IS NOT NULL OR url_origen IS NOT NULL)
                   AND (http_status IS NULL OR (http_status >= 200 AND http_status < 400))
                   AND (estado_sincronizacion IS NULL OR estado_sincronizacion NOT IN ('baja_pendiente','error','conflicto'))
                 ORDER BY ultima_importacion DESC, actualizado DESC, id DESC
                 LIMIT %d",
                'VEVOR',
                $pool_limit
            ),
            ARRAY_A
        );

        return $cache[$pool_limit] = is_array($rows) ? $rows : array();
    }
}

if (!function_exists('dht_vevor_affiliate_stock_ok')) {
    function dht_vevor_affiliate_stock_ok($row)
    {
        $state = dht_vevor_affiliate_normalize(
            (string) ($row['stock_estado'] ?? '') . ' ' . (string) ($row['stock_texto'] ?? '')
        );
        if ($state === '') {
            return true;
        }

        foreach (array('out of stock', 'outofstock', 'sin stock', 'agotado', 'agotada', 'no disponible', 'unavailable') as $needle) {
            if (strpos($state, $needle) !== false) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('dht_vevor_affiliate_term_map')) {
    function dht_vevor_affiliate_term_map($object_ids)
    {
        global $wpdb;
        $object_ids = array_values(array_unique(array_filter(array_map('absint', (array) $object_ids))));
        if (!$object_ids) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($object_ids), '%d'));
        $sql = "SELECT tr.object_id, tt.term_id
                FROM {$wpdb->term_relationships} tr
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tt.taxonomy = 'product_cat'
                  AND tr.object_id IN ({$placeholders})";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $object_ids), ARRAY_A);

        $map = array();
        foreach ((array) $rows as $row) {
            $object_id = absint($row['object_id'] ?? 0);
            $term_id = absint($row['term_id'] ?? 0);
            if ($object_id && $term_id) {
                $map[$object_id][$term_id] = true;
            }
        }
        return $map;
    }
}

if (!function_exists('dht_vevor_affiliate_top_category')) {
    function dht_vevor_affiliate_top_category($path)
    {
        $parts = preg_split('/\s*(?:>|\/|\||»|›)\s*/u', trim((string) $path));
        $first = trim((string) ($parts[0] ?? 'Otros'));
        return $first !== '' ? $first : 'Otros';
    }
}

if (!function_exists('dht_vevor_affiliate_pick')) {
    function dht_vevor_affiliate_pick($context, $limit = 4)
    {
        $limit = max(1, min(12, absint($limit)));
        if (!is_array($context) || empty($context['text'])) {
            return array();
        }

        $context_tokens = dht_vevor_affiliate_tokens((string) $context['text']);
        $context_set = array_fill_keys($context_tokens, true);
        $exclude_object_id = absint($context['type'] === 'product' ? ($context['object_id'] ?? 0) : 0);
        $blocked_term_ids = array_fill_keys(array_map('absint', (array) ($context['blocked_term_ids'] ?? array())), true);
        $rotation_key = sanitize_key((string) ($context['rotation_key'] ?? 'vevor'));

        $eligible = array();
        foreach (dht_vevor_affiliate_candidate_rows(700) as $row) {
            $row_id = absint($row['id'] ?? 0);
            $object_id = absint($row['object_id'] ?? 0);
            if (!$row_id || ($exclude_object_id && $object_id === $exclude_object_id)) {
                continue;
            }
            if (!dht_vevor_affiliate_stock_ok($row)) {
                continue;
            }

            $images = dht_vevor_affiliate_parse_images($row['imagenes'] ?? '', 4);
            if (!$images) {
                continue;
            }

            $destination = dht_vevor_affiliate_destination_url($row);
            if ($destination === '') {
                continue;
            }

            $candidate_text = implode(' | ', array_filter(array(
                $row['nombre'] ?? '',
                $row['categoria_proveedor'] ?? '',
                wp_trim_words(wp_strip_all_tags((string) ($row['descripcion'] ?? '')), 25, ''),
            )));
            $candidate_tokens = dht_vevor_affiliate_tokens($candidate_text);

            // Regla principal de no competencia: una coincidencia de termino fuerte basta.
            $common = array();
            foreach ($candidate_tokens as $token) {
                if (isset($context_set[$token])) {
                    $common[] = $token;
                }
            }
            if ($common) {
                continue;
            }

            $row['_images'] = $images;
            $row['_destination'] = $destination;
            $row['_candidate_tokens'] = $candidate_tokens;
            $eligible[] = $row;
        }

        // Si el producto VEVOR ya existe en WooCommerce, no usar candidatos de la
        // misma familia/categoria del contexto aunque el nombre no comparta palabras.
        $object_ids = array();
        foreach ($eligible as $row) {
            if (!empty($row['object_id'])) {
                $object_ids[] = absint($row['object_id']);
            }
        }
        $term_map = dht_vevor_affiliate_term_map($object_ids);

        $scored = array();
        foreach ($eligible as $row) {
            $object_id = absint($row['object_id'] ?? 0);
            if ($object_id && $blocked_term_ids && !empty($term_map[$object_id])) {
                $same_family = false;
                foreach ($term_map[$object_id] as $term_id => $_true) {
                    if (isset($blocked_term_ids[(int) $term_id])) {
                        $same_family = true;
                        break;
                    }
                }
                if ($same_family) {
                    continue;
                }
            }

            $clickref = 'dht_' . sanitize_key((string) ($context['type'] ?? 'page')) . '_' . absint($context['object_id'] ?? 0);
            $affiliate_url = dht_vevor_affiliate_url($row['_destination'], $row, $clickref);
            if ($affiliate_url === '') {
                continue;
            }

            $row['_affiliate_url'] = $affiliate_url;
            $row['_top_category'] = dht_vevor_affiliate_top_category($row['categoria_proveedor'] ?? '');

            $price = (float) ($row['precio_con_iva'] ?? 0);
            $score = 0;
            if (sanitize_key((string) ($row['estado_seleccion'] ?? '')) === 'descartado') {
                $score += 3; // Preferencia suave: no compite con el catalogo propio.
            }
            if ($price >= 20) {
                $score += 2;
            }
            if ($price >= 60) {
                $score += 1;
            }
            if ($price >= 150) {
                $score += 1;
            }
            if (count($row['_images']) > 1) {
                $score += 1;
            }

            // Rotacion diaria estable por contexto: evita RAND() en SQL y reparte exposicion.
            $hash = hexdec(substr(md5(gmdate('Y-m-d') . '|' . $rotation_key . '|' . ($row['id'] ?? '')), 0, 7));
            $row['_score'] = ($score * 100000000) + $hash;
            $scored[] = $row;
        }

        usort($scored, static function ($a, $b) {
            return ((int) ($b['_score'] ?? 0)) <=> ((int) ($a['_score'] ?? 0));
        });

        $picked = array();
        $category_counts = array();

        // Primera pasada: maxima diversidad, una tarjeta por gran familia VEVOR.
        foreach ($scored as $row) {
            $key = dht_vevor_affiliate_normalize((string) ($row['_top_category'] ?? 'otros'));
            if (($category_counts[$key] ?? 0) >= 1) {
                continue;
            }
            $picked[] = $row;
            $category_counts[$key] = ($category_counts[$key] ?? 0) + 1;
            if (count($picked) >= $limit) {
                return $picked;
            }
        }

        // Segunda pasada: completar hasta el limite permitiendo dos por familia.
        $picked_ids = array_fill_keys(array_map(static function ($row) {
            return absint($row['id'] ?? 0);
        }, $picked), true);

        foreach ($scored as $row) {
            $id = absint($row['id'] ?? 0);
            if (!$id || isset($picked_ids[$id])) {
                continue;
            }
            $key = dht_vevor_affiliate_normalize((string) ($row['_top_category'] ?? 'otros'));
            if (($category_counts[$key] ?? 0) >= 2) {
                continue;
            }
            $picked[] = $row;
            $picked_ids[$id] = true;
            $category_counts[$key] = ($category_counts[$key] ?? 0) + 1;
            if (count($picked) >= $limit) {
                break;
            }
        }

        return $picked;
    }
}

if (!function_exists('dht_vevor_affiliate_image_onerror')) {
    function dht_vevor_affiliate_image_onerror($fallback_urls)
    {
        $fallback_urls = array_values(array_filter(array_map('esc_url_raw', (array) $fallback_urls)));
        if (!$fallback_urls) {
            return 'this.onerror=null;this.closest(\'.dht-vevor-affiliate-media\').classList.add(\'is-image-missing\');this.remove();';
        }
        $json = wp_json_encode($fallback_urls, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return "var f={$json},i=parseInt(this.getAttribute('data-vevor-fallback-index')||'0',10);"
            . "if(i<f.length){this.setAttribute('data-vevor-fallback-index',String(i+1));this.src=f[i];}"
            . "else{this.onerror=null;this.closest('.dht-vevor-affiliate-media').classList.add('is-image-missing');this.remove();}";
    }
}

if (!function_exists('dht_vevor_affiliate_price_html')) {
    function dht_vevor_affiliate_price_html($row)
    {
        $price = isset($row['precio_con_iva']) ? (float) $row['precio_con_iva'] : 0;
        if ($price <= 0) {
            return '';
        }
        $currency = strtoupper(trim((string) ($row['moneda'] ?? 'EUR')));
        if ($currency === 'EUR' && function_exists('wc_price')) {
            return wc_price($price);
        }
        return number_format_i18n($price, 2) . ' ' . esc_html($currency ?: 'EUR');
    }
}

if (!function_exists('dht_vevor_affiliate_styles')) {
    function dht_vevor_affiliate_styles()
    {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;
        ?>
        <style>
        .dht-vevor-affiliate-section{margin-top:34px}.dht-vevor-affiliate-kicker{display:inline-block;margin-bottom:6px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.09em;color:#68717b}.dht-vevor-affiliate-intro{margin:0 0 18px;max-width:900px;color:#59636e;line-height:1.6}.dht-vevor-affiliate-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}.dht-vevor-affiliate-card{min-width:0;overflow:hidden;display:flex;flex-direction:column;border:1px solid #e2e5e9;border-radius:14px;background:#fff;box-shadow:0 4px 16px rgba(20,28,38,.05);transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}.dht-vevor-affiliate-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(20,28,38,.10);border-color:#c7ccd1}.dht-vevor-affiliate-media{position:relative;display:flex;align-items:center;justify-content:center;aspect-ratio:1/1;padding:16px;background:#f7f8f9;text-decoration:none;overflow:hidden}.dht-vevor-affiliate-media img{width:100%;height:100%;object-fit:contain;mix-blend-mode:multiply}.dht-vevor-affiliate-media.is-image-missing:after{content:'VEVOR';display:flex;align-items:center;justify-content:center;width:100%;height:100%;border-radius:10px;background:linear-gradient(145deg,#f5f6f7,#eceff1);font-size:24px;font-weight:850;letter-spacing:-.04em;color:#59636e}.dht-vevor-affiliate-badge{position:absolute;left:12px;top:12px;padding:5px 9px;border:1px solid #dfe4e8;border-radius:999px;background:rgba(255,255,255,.95);box-shadow:0 2px 8px rgba(20,28,38,.08);font-size:11px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#1f2933}.dht-vevor-affiliate-body{display:flex;flex:1;flex-direction:column;gap:8px;padding:15px 16px 17px}.dht-vevor-affiliate-category{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.055em;color:#6b7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.dht-vevor-affiliate-title{margin:0;font-size:15px;line-height:1.4;color:#17202a;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}.dht-vevor-affiliate-price{font-size:18px;font-weight:800;color:#17202a}.dht-vevor-affiliate-cta{display:inline-flex;align-items:center;justify-content:center;gap:7px;margin-top:auto;padding:10px 12px;border:1px solid #cf3d24;border-radius:8px;background:#e6452d;color:#fff!important;text-decoration:none;font-size:13px;font-weight:750;transition:background .15s ease,border-color .15s ease}.dht-vevor-affiliate-cta:hover{background:#c93721;border-color:#c93721}.dht-vevor-affiliate-disclosure{margin:13px 0 0;font-size:11px;color:#747c85}.dht-vevor-affiliate-debug{margin-top:14px;padding:12px 14px;border:1px dashed #d4aa00;border-radius:8px;background:#fffbe6;font-size:12px;color:#5f5600}@media(max-width:1050px){.dht-vevor-affiliate-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.dht-vevor-affiliate-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.dht-vevor-affiliate-body{padding:13px}.dht-vevor-affiliate-media{padding:10px}}@media(max-width:460px){.dht-vevor-affiliate-title{font-size:14px}.dht-vevor-affiliate-price{font-size:16px}.dht-vevor-affiliate-cta{padding:9px 8px;font-size:12px}}
        </style>
        <?php
    }
}

if (!function_exists('dht_render_vevor_affiliate_block')) {
    function dht_render_vevor_affiliate_block($context, $args = array())
    {
        $args = wp_parse_args($args, array(
            'limit'    => 4,
            'title'    => 'Descubre otros productos en VEVOR',
            'subtitle' => 'Una selección de otras familias y productos para descubrir opciones diferentes a lo que estás consultando ahora.',
        ));

        if (!is_array($context) || empty($context['text'])) {
            return;
        }

        $items = dht_vevor_affiliate_pick($context, $args['limit']);
        if (!$items) {
            if (current_user_can('manage_options') && !dht_vevor_affiliate_tracking_ready()) {
                dht_vevor_affiliate_styles();
                echo '<section class="dht-section dht-vevor-affiliate-section"><div class="dht-container">';
                echo '<div class="dht-vevor-affiliate-debug"><strong>VEVOR afiliados:</strong> el selector de productos esta preparado, pero falta configurar el enlace de tracking. Configura <code>SEO_VEVOR_AFFILIATE_CAMPAIGN_ID</code> o usa el filtro <code>dht_vevor_affiliate_url</code>. El modo nativo VEVOR InHouse utiliza el campaign ID de afiliado y conserva cualquier shortkey oficial ya existente.</div>';
                echo '</div></section>';
            }
            return;
        }

        dht_vevor_affiliate_styles();
        ?>
        <section class="dht-section dht-vevor-affiliate-section" data-vevor-affiliate-context="<?php echo esc_attr($context['type'] ?? 'page'); ?>" data-vevor-affiliate-object-id="<?php echo esc_attr(absint($context['object_id'] ?? 0)); ?>">
            <div class="dht-container">
                <header class="dht-section-header">
                    <span class="dht-vevor-affiliate-kicker">Selección externa · VEVOR</span>
                    <h2 class="dht-section-title"><?php echo esc_html($args['title']); ?></h2>
                    <p class="dht-section-subtitle"><?php echo esc_html($args['subtitle']); ?></p>
                </header>

                <div class="dht-vevor-affiliate-grid">
                    <?php foreach ($items as $row) : ?>
                        <?php
                        $images = (array) ($row['_images'] ?? array());
                        $image = array_shift($images);
                        $url = (string) ($row['_affiliate_url'] ?? '');
                        $title = trim((string) ($row['nombre'] ?? 'Producto VEVOR'));
                        $category = trim((string) ($row['_top_category'] ?? 'VEVOR'));
                        $price_html = dht_vevor_affiliate_price_html($row);
                        ?>
                        <article class="dht-vevor-affiliate-card" data-vevor-row-id="<?php echo esc_attr(absint($row['id'] ?? 0)); ?>">
                            <a class="dht-vevor-affiliate-media" href="<?php echo esc_url($url); ?>" target="_blank" rel="sponsored noopener" data-vevor-affiliate-click="product">
                                <?php if ($image) : ?>
                                    <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" decoding="async" data-vevor-fallback-index="0" onerror="<?php echo esc_attr(dht_vevor_affiliate_image_onerror($images)); ?>">
                                <?php endif; ?>
                                <span class="dht-vevor-affiliate-badge">VEVOR</span>
                            </a>
                            <div class="dht-vevor-affiliate-body">
                                <span class="dht-vevor-affiliate-category"><?php echo esc_html($category); ?></span>
                                <h3 class="dht-vevor-affiliate-title"><?php echo esc_html($title); ?></h3>
                                <?php if ($price_html !== '') : ?>
                                    <div class="dht-vevor-affiliate-price"><?php echo wp_kses_post($price_html); ?></div>
                                <?php endif; ?>
                                <a class="dht-vevor-affiliate-cta" href="<?php echo esc_url($url); ?>" target="_blank" rel="sponsored noopener" data-vevor-affiliate-click="cta">Ver en VEVOR <span aria-hidden="true">→</span></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <p class="dht-vevor-affiliate-disclosure">Enlaces de afiliado. Si realizas una compra en VEVOR desde estos enlaces podemos recibir una comisión, sin coste adicional para ti.</p>
            </div>
        </section>
        <?php
    }
}

if (!function_exists('dht_render_vevor_affiliate_product_block')) {
    function dht_render_vevor_affiliate_product_block($product, $args = array())
    {
        $context = dht_vevor_affiliate_context_product($product);
        if ($context) {
            dht_render_vevor_affiliate_block($context, wp_parse_args($args, array('limit' => 4)));
        }
    }
}

if (!function_exists('dht_render_vevor_affiliate_category_block')) {
    function dht_render_vevor_affiliate_category_block($term, $args = array())
    {
        $context = dht_vevor_affiliate_context_category($term);
        if ($context) {
            dht_render_vevor_affiliate_block($context, wp_parse_args($args, array('limit' => 8)));
        }
    }
}
