<?php
/**
 * Dynamic Amazon block for US DHT categories.
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
            'https://www.amazon.com/s'
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
                'label' => 'More ' . $name . ' options',
                'query' => $name,
            ),
            array(
                'type'  => 'complementary',
                'label' => 'Accessories and add-ons',
                'query' => 'accesorios ' . $name,
            ),
        );

        $ancestors = array_reverse(get_ancestors($term->term_id, 'product_cat'));
        if (!empty($ancestors)) {
            $parent = get_term((int)end($ancestors), 'product_cat');
            if ($parent instanceof WP_Term && !is_wp_error($parent)) {
                $intents[] = array(
                    'type'  => 'context',
                    'label' => 'Options in ' . $parent->name,
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
            'inspection-cameras' => array(
                array('label'=>'Inspection cameras with locator','query'=>'pipe inspection camera locator 512 Hz','description'=>'Find inspection options with probe locating when knowing the camera head position matters.'),
                array('label'=>'Longer inspection cables','query'=>'pipe inspection camera 100 ft 165 ft cable','description'=>'Compare equipment by cable length when you need to cover longer runs.'),
                array('label'=>'Professional inspection cameras and borescopes','query'=>'professional pipe inspection camera borescope','description'=>'Explore equipment intended for frequent inspection, maintenance and professional work.'),
                array('label'=>'Inspection accessories and probes','query'=>'pipe inspection camera accessories probe','description'=>'Find accessories for inspection, guidance, protection and probe use.'),
            ),
            'screwdriver' => array(
                array('label'=>'VDE insulated screwdrivers','query'=>'VDE insulated screwdrivers','description'=>'Options for electrical work where insulation, size and tip type matter.'),
                array('label'=>'Precision screwdrivers','query'=>'precision screwdrivers','description'=>'Small formats for electronics, mechanisms, mobile devices and small fasteners.'),
                array('label'=>'Ratcheting screwdrivers','query'=>'ratcheting screwdrivers','description'=>'Options for repetitive assembly and tight spaces where reduced hand movement helps.'),
                array('label'=>'Screwdriver sets','query'=>'professional screwdriver set','description'=>'Sets with multiple tips and sizes for broader coverage in one purchase.'),
            ),
            'hydraulic-jacks' => array(
                array('label'=>'Toe jacks','query'=>'hydraulic toe jack','description'=>'For lifting machinery from low or side lifting points, with different load capacities.'),
                array('label'=>'Industrial hydraulic jacks','query'=>'industrial hydraulic machinery jack','description'=>'Models for machinery, industrial maintenance and heavier loads.'),
                array('label'=>'Low-profile jacks','query'=>'low profile hydraulic machinery jack','description'=>'Options designed for low initial clearance.'),
                array('label'=>'Lifting supports and accessories','query'=>'hydraulic machinery jack lifting accessories','description'=>'Accessories for support, stabilization and work around lifting equipment.'),
            ),
        );
        if (false !== strpos($key, 'camara') && (false !== strpos($key, 'tuber') || false !== strpos($key, 'inspeccion'))) {
            return $sets['inspection-cameras'];
        }
        foreach ($sets as $needle => $items) {
            if (false !== strpos($key, $needle)) return $items;
        }
        return array(
            array('label'=>'Professional options','query'=>$name.' professional','description'=>'Explore options for frequent, workshop or professional use.'),
            array('label'=>'Different sizes and capacities','query'=>$name.' sizes capacities','description'=>'Compare sizes, capacities and configurations that may not be available in the current catalog.'),
            array('label'=>'Accessories and add-ons','query'=>'accessories '.$name,'description'=>'Compatible accessories and add-ons that expand how this product family can be used.'),
            array('label'=>'Kits and sets','query'=>'kit '.$name,'description'=>'Sets that combine multiple sizes, parts or accessories for a wider range of applications.'),
        );
    }
}

if (!function_exists('dht_amazon_compare_points')) {
    function dht_amazon_compare_points($name) {
        $key = sanitize_title(remove_accents(mb_strtolower((string)$name)));
        if ((false !== strpos($key, 'camera') || false !== strpos($key, 'borescope')) && (false !== strpos($key, 'pipe') || false !== strpos($key, 'inspection'))) return array('Cable length','Camera head diameter and access','Resolution, display and recording','Water resistance and working conditions','Locator, accessories and frequency of use');
        if (false !== strpos($key, 'screwdriver')) return array('Tip type and geometry','Included sizes','Insulation or VDE certification when relevant','Ergonomics, grip and length','General, precision, impact or ratcheting use');
        if (false !== strpos($key, 'jack')) return array('Rated load capacity','Minimum entry height','Maximum lift and travel','Drive type and stability','Application: vehicle, workshop or industrial machinery');
        return array('Intended use and compatibility','Size, capacity or working range','Materials and construction','Included accessories','Frequency of use and professional level');
    }
}

if (!function_exists('dht_amazon_enriched_intents')) {
    function dht_amazon_enriched_intents($name, $base_intents = array(), $limit = 6) {
        $items = array();
        foreach (dht_amazon_family_variants($name) as $v) {
            $items[] = array('type'=>'gap','label'=>$v['label'],'query'=>$v['query'],'description'=>$v['description']);
        }
        foreach ((array)$base_intents as $i) {
            if (!isset($i['description'])) $i['description'] = 'Explore more alternatives related to this product family and compare available market options.';
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
            return array('products' => array(), 'query' => '', 'error' => 'Empty query.');
        }

        if (!function_exists('seo_supplier_recipe_amazon_search_items')) {
            return array(
                'products' => array(),
                'query'    => $query,
                'error'    => 'Creators API is not available yet.',
            );
        }

        $settings = dht_amazon_settings();
        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            return array(
                'products' => array(),
                'query'    => $query,
                'error'    => 'Creators API is not available yet.',
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
                'error'    => 'Amazon normalizer is missing.',
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
                <a class="dht-amazon-card-cta" href="<?php echo esc_url($url); ?>" target="_blank" rel="sponsored noopener" data-amazon-click="product">View on Amazon</a>
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
            echo '<span class="dht-amazon-media-note">Related search</span>';
            echo '</a>';
            echo '<div class="dht-amazon-intent-body">';
            echo '<span class="dht-amazon-intent-type">' . esc_html($is_direct ? 'More options' : 'Related') . '</span>';
            echo '<h3>' . esc_html($intent['label'] ?? $query) . '</h3>';
            echo '<p>' . esc_html((string)($intent['description'] ?? ('Explore products and variants for "' . $query . '" available on Amazon.'))) . '</p>';
            echo '<a class="dht-amazon-intent-cta" href="' . esc_url($url) . '" target="_blank" rel="sponsored noopener" data-amazon-click="search" data-amazon-context="' . esc_attr($context_type) . '" data-amazon-object-id="' . esc_attr($object_id) . '" data-amazon-intent="' . esc_attr($intent['type'] ?? 'direct') . '" data-amazon-query="' . esc_attr($query) . '">View options on Amazon <span aria-hidden="true">→</span></a>';
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
            'title' => 'Products you may be interested in',
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
                    <span class="dht-amazon-kicker">More market options</span>
                    <h2 class="dht-section-title"><?php echo esc_html($args['title']); ?></h2>
                    <p class="dht-section-subtitle">Explore alternatives and related accessories for <?php echo esc_html($term->name); ?>.</p>
                </header>

                <div class="dht-amazon-market-copy">
                    <p>In addition to the products available in our catalog, this category may include alternatives with different sizes, capacities, configurations and applications. This selection broadens your search for options that may not yet be available directly in our store.</p>
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
