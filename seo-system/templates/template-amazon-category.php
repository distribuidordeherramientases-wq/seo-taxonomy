<?php
/**
 * Bloque Amazon dinámico para categorías DHT.
 *
 * - Con Creators API: muestra productos concretos.
 * - Sin Creators API: muestra búsquedas afiliadas dinámicas basadas en el
 *   contexto de la categoría, de forma que el bloque ya sea utilizable.
 * - No crea productos ni categorías WordPress/WooCommerce.
 *
 * @version 0.5.0
 */

defined('ABSPATH') || exit;

if (!function_exists('dht_amazon_settings')) {
    function dht_amazon_settings() {
        if (function_exists('seo_supplier_recipe_amazon_settings')) {
            $settings = seo_supplier_recipe_amazon_settings();
            return is_array($settings) ? $settings : array();
        }

        $saved = get_option('seo_amazon_creators_recipe_settings', array());
        return is_array($saved) ? $saved : array();
    }
}

if (!function_exists('dht_amazon_partner_tag')) {
    function dht_amazon_partner_tag() {
        $settings = dht_amazon_settings();
        return sanitize_text_field((string)($settings['partner_tag'] ?? ''));
    }
}

if (!function_exists('dht_amazon_search_url')) {
    function dht_amazon_search_url($query) {
        $query = trim(wp_strip_all_tags((string)$query));
        $tag   = dht_amazon_partner_tag();

        if ($query === '' || $tag === '') {
            return '';
        }

        return add_query_arg(
            array(
                'k'   => $query,
                'tag' => $tag,
            ),
            'https://www.amazon.es/s'
        );
    }
}

if (!function_exists('dht_amazon_unique_intents')) {
    function dht_amazon_unique_intents($intents, $limit = 4) {
        $unique = array();

        foreach ((array)$intents as $intent) {
            if (!is_array($intent)) {
                continue;
            }

            $query = trim(wp_strip_all_tags((string)($intent['query'] ?? '')));
            if ($query === '') {
                continue;
            }

            $key = sanitize_title(remove_accents(mb_strtolower($query)));
            if ($key === '' || isset($unique[$key])) {
                continue;
            }

            $intent['query'] = $query;
            $unique[$key] = $intent;

            if (count($unique) >= $limit) {
                break;
            }
        }

        return array_values($unique);
    }
}

if (!function_exists('dht_amazon_category_intents')) {
    function dht_amazon_category_intents($term) {
        if (!$term instanceof WP_Term || $term->taxonomy !== 'product_cat') {
            return array();
        }

        $name = trim((string)$term->name);
        $intents = array(
            array(
                'type'  => 'direct',
                'label' => 'Más opciones de ' . $name,
                'query' => $name,
            ),
            array(
                'type'  => 'complementary',
                'label' => 'Accesorios y complementos',
                'query' => 'accesorios ' . $name,
            ),
        );

        $ancestors = array_reverse(get_ancestors($term->term_id, 'product_cat'));
        if (!empty($ancestors)) {
            $parent = get_term((int)end($ancestors), 'product_cat');
            if ($parent instanceof WP_Term && !is_wp_error($parent)) {
                $intents[] = array(
                    'type'  => 'context',
                    'label' => 'Opciones dentro de ' . $parent->name,
                    'query' => $parent->name . ' ' . $name,
                );
            }
        }

        $children = get_terms(array(
            'taxonomy'   => 'product_cat',
            'parent'     => $term->term_id,
            'hide_empty' => false,
            'number'     => 2,
            'orderby'    => 'count',
            'order'      => 'DESC',
        ));

        if (!is_wp_error($children)) {
            foreach ($children as $child) {
                $intents[] = array(
                    'type'  => 'subfamily',
                    'label' => $child->name,
                    'query' => $child->name,
                );
            }
        }

        return dht_amazon_unique_intents(
            apply_filters('dht_amazon_category_intents', $intents, $term),
            4
        );
    }
}



if (!function_exists('dht_amazon_family_variants')) {
    function dht_amazon_family_variants($name) {
        $name = trim(wp_strip_all_tags((string)$name));
        $key  = sanitize_title(remove_accents(mb_strtolower($name)));
        $sets = array(
            'camaras-inspeccion-tuberias' => array(
                array('label'=>'Cámaras con localizador','query'=>'cámara inspección tuberías localizador 512 Hz','description'=>'Busca opciones de inspección con localización de sonda para trabajos donde importa conocer la posición del cabezal.'),
                array('label'=>'Sondas de mayor longitud','query'=>'cámara inspección tuberías sonda 30m 50m','description'=>'Compara equipos por longitud de cable o sonda cuando necesitas cubrir recorridos más largos.'),
                array('label'=>'Cámaras y endoscopios profesionales','query'=>'cámara endoscopio inspección tuberías profesional','description'=>'Explora equipos orientados a inspección frecuente, mantenimiento y trabajos profesionales.'),
                array('label'=>'Accesorios y sondas','query'=>'accesorios sonda cámara inspección tuberías','description'=>'Localiza complementos relacionados con inspección, guiado, protección y uso de la sonda.'),
            ),
            'destornillador' => array(
                array('label'=>'Destornilladores aislados VDE','query'=>'destornilladores aislados VDE','description'=>'Opciones para trabajos eléctricos donde importan el aislamiento, la medida y el tipo de punta.'),
                array('label'=>'Destornilladores de precisión','query'=>'destornilladores de precisión','description'=>'Formatos pequeños para electrónica, mecanismos, móviles y tornillería de reducido tamaño.'),
                array('label'=>'Destornilladores con carraca','query'=>'destornilladores con carraca','description'=>'Alternativas orientadas a montaje repetitivo y espacios donde conviene reducir el movimiento de la mano.'),
                array('label'=>'Juegos de destornilladores','query'=>'juego de destornilladores profesional','description'=>'Conjuntos con distintas puntas y medidas para ampliar cobertura con una sola compra.'),
            ),
            'gatos-de-pie' => array(
                array('label'=>'Gatos hidráulicos de uña','query'=>'gato hidráulico de uña','description'=>'Para elevar maquinaria desde puntos de apoyo bajos o laterales, con diferentes capacidades de carga.'),
                array('label'=>'Gatos hidráulicos industriales','query'=>'gato hidráulico industrial maquinaria','description'=>'Modelos destinados a maquinaria, mantenimiento industrial y cargas superiores a las de automoción ligera.'),
                array('label'=>'Gatos de baja altura','query'=>'gato hidráulico baja altura maquinaria','description'=>'Opciones pensadas para introducir el punto de elevación cuando el espacio libre inicial es reducido.'),
                array('label'=>'Soportes y accesorios de elevación','query'=>'accesorios soporte gato hidráulico maquinaria','description'=>'Complementos para apoyo, estabilización y trabajo alrededor de equipos de elevación.'),
            ),
        );
        if (false !== strpos($key, 'camara') && (false !== strpos($key, 'tuber') || false !== strpos($key, 'inspeccion'))) {
            return $sets['camaras-inspeccion-tuberias'];
        }
        foreach ($sets as $needle => $items) {
            if (false !== strpos($key, $needle)) return $items;
        }
        return array(
            array('label'=>'Versiones profesionales','query'=>$name.' profesional','description'=>'Explora variantes orientadas a uso frecuente, taller o trabajo profesional.'),
            array('label'=>'Diferentes medidas y capacidades','query'=>$name.' medidas capacidades','description'=>'Compara tamaños, capacidades y configuraciones que pueden no estar presentes en el catálogo actual.'),
            array('label'=>'Accesorios y complementos','query'=>'accesorios '.$name,'description'=>'Elementos compatibles y complementarios que amplían las posibilidades de uso de esta familia.'),
            array('label'=>'Kits y conjuntos','query'=>'kit '.$name,'description'=>'Conjuntos que agrupan varias medidas, piezas o accesorios para cubrir más aplicaciones.'),
        );
    }
}

if (!function_exists('dht_amazon_compare_points')) {
    function dht_amazon_compare_points($name) {
        $key = sanitize_title(remove_accents(mb_strtolower((string)$name)));
        if (false !== strpos($key, 'camara') && (false !== strpos($key, 'tuber') || false !== strpos($key, 'inspeccion'))) return array('Longitud de sonda o cable','Diámetro del cabezal y acceso','Resolución, pantalla y grabación','Impermeabilidad y condiciones de trabajo','Localizador, accesorios y frecuencia de uso');
        if (false !== strpos($key, 'destornill')) return array('Tipo y geometría de la punta','Medidas incluidas','Aislamiento o certificación VDE cuando corresponda','Ergonomía, agarre y longitud','Uso general, precisión, impacto o carraca');
        if (false !== strpos($key, 'gato')) return array('Capacidad nominal de carga','Altura mínima de entrada','Altura máxima y recorrido','Tipo de accionamiento y estabilidad','Aplicación: vehículo, taller o maquinaria industrial');
        return array('Tipo de uso y compatibilidad','Medidas, capacidad o rango de trabajo','Materiales y construcción','Accesorios incluidos','Frecuencia de uso y nivel profesional');
    }
}

if (!function_exists('dht_amazon_enriched_intents')) {
    function dht_amazon_enriched_intents($name, $base_intents = array(), $limit = 6) {
        $items = array();
        foreach (dht_amazon_family_variants($name) as $v) {
            $items[] = array('type'=>'gap','label'=>$v['label'],'query'=>$v['query'],'description'=>$v['description']);
        }
        foreach ((array)$base_intents as $i) {
            if (!isset($i['description'])) $i['description'] = 'Explora más alternativas relacionadas con esta familia y compara opciones disponibles en el mercado.';
            $items[]=$i;
        }
        return dht_amazon_unique_intents($items, $limit);
    }
}

if (!function_exists('dht_amazon_safe_results')) {
    function dht_amazon_safe_results($query, $limit = 8, $context = array()) {
        $query = trim(wp_strip_all_tags((string)$query));
        $limit = max(1, min(10, absint($limit)));

        if ($query === '') {
            return array('products' => array(), 'query' => '', 'error' => 'Consulta vacía.');
        }

        if (!function_exists('seo_supplier_recipe_amazon_search_items')) {
            return array(
                'products' => array(),
                'query'    => $query,
                'error'    => 'Creators API todavía no disponible.',
            );
        }

        $settings = dht_amazon_settings();
        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            return array(
                'products' => array(),
                'query'    => $query,
                'error'    => 'Creators API todavía no disponible.',
            );
        }

        $cache_key = 'dht_amz_' . md5(wp_json_encode(array(
            'q'       => $query,
            'limit'   => $limit,
            'context' => $context,
        )));

        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $response = seo_supplier_recipe_amazon_search_items($query, array(
            'item_count' => $limit,
            'item_page'  => 1,
            'sort_by'    => 'Relevance',
        ));

        if (is_wp_error($response)) {
            return array(
                'products' => array(),
                'query'    => $query,
                'error'    => $response->get_error_message(),
            );
        }

        if (!function_exists('seo_supplier_recipe_amazon_normalize_preview')) {
            return array(
                'products' => array(),
                'query'    => $query,
                'error'    => 'Falta el normalizador Amazon.',
            );
        }

        $normalized = seo_supplier_recipe_amazon_normalize_preview($response);
        $result = array(
            'products' => array_slice((array)($normalized['products'] ?? array()), 0, $limit),
            'query'    => $query,
            'error'    => '',
        );

        set_transient($cache_key, $result, 45 * MINUTE_IN_SECONDS);
        return $result;
    }
}

if (!function_exists('dht_amazon_card_markup')) {
    function dht_amazon_card_markup($item, $context = array()) {
        $title = trim((string)($item['title'] ?? ''));
        $url   = trim((string)($item['url'] ?? ''));
        $image = trim((string)($item['image_url'] ?? ''));
        $price = trim((string)($item['price'] ?? ''));
        $brand = trim((string)($item['brand'] ?? ''));
        $asin  = trim((string)($item['asin'] ?? ''));

        if ($title === '' || $url === '') {
            return;
        }
        ?>
        <article class="dht-amazon-card" data-amazon-asin="<?php echo esc_attr($asin); ?>">
            <a class="dht-amazon-card-media" href="<?php echo esc_url($url); ?>" target="_blank" rel="sponsored noopener" data-amazon-click="product">
                <?php if ($image !== '') : ?>
                    <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy">
                <?php else : ?>
                    <span class="dht-amazon-card-placeholder" aria-hidden="true"></span>
                <?php endif; ?>
            </a>
            <div class="dht-amazon-card-body">
                <?php if ($brand !== '') : ?><span class="dht-amazon-card-brand"><?php echo esc_html($brand); ?></span><?php endif; ?>
                <h3 class="dht-amazon-card-title"><a href="<?php echo esc_url($url); ?>" target="_blank" rel="sponsored noopener" data-amazon-click="product"><?php echo esc_html($title); ?></a></h3>
                <?php if ($price !== '') : ?><div class="dht-amazon-card-price"><?php echo esc_html($price); ?></div><?php endif; ?>
                <a class="dht-amazon-card-cta" href="<?php echo esc_url($url); ?>" target="_blank" rel="sponsored noopener" data-amazon-click="product">Ver en Amazon</a>
            </div>
        </article>
        <?php
    }
}

if (!function_exists('dht_amazon_context_image')) {
    function dht_amazon_context_image($context_type, $object_id, $intent = array()) {
        $url = '';

        if ('category' === $context_type) {
            $term = get_term(absint($object_id), 'product_cat');
            if ($term instanceof WP_Term && !is_wp_error($term)) {
                $thumbnail_id = absint(get_term_meta($term->term_id, 'thumbnail_id', true));
                if ($thumbnail_id) {
                    $url = wp_get_attachment_image_url($thumbnail_id, 'woocommerce_thumbnail');
                }

                // Muchas categorías del sitio no tienen thumbnail propio. En ese
                // caso usamos una imagen de producto real de la propia categoría
                // como apoyo visual de la búsqueda, sin presentarla como un
                // producto concreto de Amazon.
                if (!$url) {
                    $offset = 0;
                    $intent_type = sanitize_key((string)($intent['type'] ?? 'direct'));
                    $offsets = array(
                        'direct'        => 0,
                        'complementary' => 1,
                        'context'       => 2,
                        'subfamily'     => 3,
                    );
                    if (isset($offsets[$intent_type])) {
                        $offset = $offsets[$intent_type];
                    }

                    $ids = get_posts(array(
                        'post_type'              => 'product',
                        'post_status'            => 'publish',
                        'posts_per_page'         => 1,
                        'offset'                 => $offset,
                        'fields'                 => 'ids',
                        'orderby'                => 'menu_order date',
                        'order'                  => 'DESC',
                        'no_found_rows'          => true,
                        'ignore_sticky_posts'    => true,
                        'tax_query'              => array(array(
                            'taxonomy'         => 'product_cat',
                            'field'            => 'term_id',
                            'terms'            => array((int)$term->term_id),
                            'include_children' => true,
                        )),
                        'meta_query'             => array(array(
                            'key'     => '_thumbnail_id',
                            'compare' => 'EXISTS',
                        )),
                    ));

                    if (!empty($ids)) {
                        $image_id = get_post_thumbnail_id((int)$ids[0]);
                        if ($image_id) {
                            $url = wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail');
                        }
                    }
                }
            }
        } elseif ('product' === $context_type && function_exists('wc_get_product')) {
            $product = wc_get_product(absint($object_id));
            if ($product instanceof WC_Product) {
                $image_id = $product->get_image_id();
                if ($image_id) {
                    $url = wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail');
                }

                if (!$url && function_exists('dht_amazon_product_deepest_category')) {
                    $term = dht_amazon_product_deepest_category($product);
                    if ($term instanceof WP_Term) {
                        $thumbnail_id = absint(get_term_meta($term->term_id, 'thumbnail_id', true));
                        if ($thumbnail_id) {
                            $url = wp_get_attachment_image_url($thumbnail_id, 'woocommerce_thumbnail');
                        }
                    }
                }
            }
        }

        return $url ? esc_url_raw($url) : '';
    }
}

if (!function_exists('dht_amazon_intent_links_markup')) {
    function dht_amazon_intent_links_markup($intents, $context_type, $object_id) {
        $tag = dht_amazon_partner_tag();
        if ($tag === '') {
            if (current_user_can('manage_options')) {
                echo '<div class="dht-amazon-debug"><strong>Amazon:</strong> falta el Partner Tag.</div>';
            }
            return;
        }

        echo '<div class="dht-amazon-intent-grid">';
        foreach ((array)$intents as $intent) {
            $query = trim((string)($intent['query'] ?? ''));
            $url = dht_amazon_search_url($query);
            if ($url === '') {
                continue;
            }

            $image = '';
            $is_direct = (($intent['type'] ?? '') === 'direct');

            echo '<article class="dht-amazon-intent-card">';
            echo '<a class="dht-amazon-intent-media" href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener" data-amazon-click="search" data-amazon-context="' . esc_attr($context_type) . '" data-amazon-object-id="' . esc_attr($object_id) . '" data-amazon-intent="' . esc_attr($intent['type'] ?? 'direct') . '" data-amazon-query="' . esc_attr($query) . '">';
            echo '<span class="dht-amazon-intent-placeholder" aria-hidden="true"><span class="dht-amazon-placeholder-brand">Amazon</span><span class="dht-amazon-placeholder-query">' . esc_html($query) . '</span></span>';
            echo '<span class="dht-amazon-source-badge">Amazon</span>';
            echo '<span class="dht-amazon-media-note">Búsqueda relacionada</span>';
            echo '</a>';
            echo '<div class="dht-amazon-intent-body">';
            echo '<span class="dht-amazon-intent-type">' . esc_html($is_direct ? 'Más opciones' : 'Relacionado') . '</span>';
            echo '<h3>' . esc_html($intent['label'] ?? $query) . '</h3>';
            echo '<p>' . esc_html((string)($intent['description'] ?? ('Explora productos y variantes de «' . $query . '» disponibles en Amazon.'))) . '</p>';
            echo '<a class="dht-amazon-intent-cta" href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener" data-amazon-click="search" data-amazon-context="' . esc_attr($context_type) . '" data-amazon-object-id="' . esc_attr($object_id) . '" data-amazon-intent="' . esc_attr($intent['type'] ?? 'direct') . '" data-amazon-query="' . esc_attr($query) . '">Ver opciones en Amazon <span aria-hidden="true">→</span></a>';
            echo '</div>';
            echo '</article>';
        }
        echo '</div>';
    }
}

if (!function_exists('dht_amazon_styles')) {
    function dht_amazon_styles() {
        // Estilos centralizados en styles-template.css.
    }
}

if (!function_exists('dht_render_amazon_category_block')) {
    function dht_render_amazon_category_block($term, $args = array()) {
        if (!$term instanceof WP_Term || $term->taxonomy !== 'product_cat') {
            return;
        }

        $args = wp_parse_args($args, array(
            'limit' => 8,
            'title' => 'Productos que te pueden interesar',
        ));

        $intents = dht_amazon_enriched_intents($term->name, dht_amazon_category_intents($term), 6);
        if (empty($intents)) {
            return;
        }

        $primary_query = (string)($intents[0]['query'] ?? $term->name);
        $result = dht_amazon_safe_results($primary_query, $args['limit'], array(
            'type'      => 'category',
            'object_id' => (int)$term->term_id,
        ));
        $products = (array)($result['products'] ?? array());

        dht_amazon_styles();
        ?>
        <section class="dht-section dht-amazon-section" data-amazon-context="category" data-amazon-object-id="<?php echo esc_attr($term->term_id); ?>">
            <div class="dht-container">
                <header class="dht-section-header">
                    <span class="dht-amazon-kicker">Más opciones del mercado</span>
                    <h2 class="dht-section-title"><?php echo esc_html($args['title']); ?></h2>
                    <p class="dht-section-subtitle">Explora alternativas y complementos relacionados con <?php echo esc_html($term->name); ?>.</p>
                </header>

                <div class="dht-amazon-market-copy">
                    <p>Además de los productos disponibles en nuestro catálogo, esta familia puede incluir variantes con distintas medidas, capacidades, configuraciones y aplicaciones. Esta selección amplía la exploración para ayudarte a localizar opciones que todavía no estén disponibles directamente en nuestra tienda.</p>
                </div>

                <div class="dht-amazon-compare">
                    <h3>Qué conviene comparar en <?php echo esc_html($term->name); ?></h3>
                    <ul>
                        <?php foreach (dht_amazon_compare_points($term->name) as $point) : ?><li><?php echo esc_html($point); ?></li><?php endforeach; ?>
                    </ul>
                </div>

                <?php if (!empty($products)) : ?>
                    <div class="dht-amazon-grid">
                        <?php foreach ($products as $product_item) dht_amazon_card_markup($product_item, array('type' => 'category', 'object_id' => $term->term_id)); ?>
                    </div>
                <?php else : ?>
                    <?php dht_amazon_intent_links_markup($intents, 'category', (int)$term->term_id); ?>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }
}
