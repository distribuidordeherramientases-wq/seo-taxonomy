<?php
/**
 * Bloque Amazon dinámico para fichas de producto DHT.
 *
 * Construye intenciones a partir de la categoría más profunda y atributos del
 * producto. Con API muestra productos; sin API muestra búsquedas afiliadas.
 *
 * @version 0.5.0
 */

defined('ABSPATH') || exit;

$amazon_category_helper = __DIR__ . '/template-amazon-category.php';
if ((!function_exists('dht_amazon_safe_results') || !function_exists('dht_amazon_card_markup')) && is_readable($amazon_category_helper)) {
    require_once $amazon_category_helper;
}

if (!function_exists('dht_amazon_product_deepest_category')) {
    function dht_amazon_product_deepest_category($product) {
        if (!$product instanceof WC_Product) return null;

        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (is_wp_error($terms) || empty($terms)) return null;

        usort($terms, static function($a, $b) {
            return count(get_ancestors($b->term_id, 'product_cat')) <=> count(get_ancestors($a->term_id, 'product_cat'));
        });

        return reset($terms) ?: null;
    }
}

if (!function_exists('dht_amazon_product_attribute_tokens')) {
    function dht_amazon_product_attribute_tokens($product, $max = 2) {
        if (!$product instanceof WC_Product) return array();

        $tokens = array();
        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute instanceof WC_Product_Attribute || !$attribute->get_visible()) continue;

            $values = array();
            if ($attribute->is_taxonomy()) {
                $values = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
            } else {
                $values = $attribute->get_options();
            }

            foreach ((array)$values as $value) {
                $value = trim(wp_strip_all_tags((string)$value));
                if ($value === '' || mb_strlen($value) > 35) continue;
                $tokens[] = $value;
                if (count($tokens) >= $max) return array_values(array_unique($tokens));
            }
        }
        return array_values(array_unique($tokens));
    }
}

if (!function_exists('dht_amazon_product_intents')) {
    function dht_amazon_product_intents($product) {
        if (!$product instanceof WC_Product) return array();

        $category = dht_amazon_product_deepest_category($product);
        $family = $category instanceof WP_Term ? trim((string)$category->name) : trim((string)$product->get_name());
        $attributes = dht_amazon_product_attribute_tokens($product, 2);
        $precise = trim($family . ' ' . implode(' ', $attributes));

        $intents = array(
            array(
                'type'  => 'direct',
                'label' => 'Alternativas similares',
                'query' => $precise !== '' ? $precise : $family,
            ),
            array(
                'type'  => 'family',
                'label' => 'Más opciones de esta familia',
                'query' => $family,
            ),
            array(
                'type'  => 'complementary',
                'label' => 'Accesorios y complementos',
                'query' => 'accesorios ' . $family,
            ),
        );

        if ($category instanceof WP_Term) {
            $ancestors = get_ancestors($category->term_id, 'product_cat');
            if (!empty($ancestors)) {
                $parent = get_term((int)$ancestors[0], 'product_cat');
                if ($parent instanceof WP_Term && !is_wp_error($parent)) {
                    $intents[] = array(
                        'type'  => 'nearby',
                        'label' => 'Productos relacionados',
                        'query' => $parent->name . ' ' . $family,
                    );
                }
            }
        }

        return function_exists('dht_amazon_unique_intents')
            ? dht_amazon_unique_intents(apply_filters('dht_amazon_product_intents', $intents, $product), 4)
            : $intents;
    }
}

if (!function_exists('dht_render_amazon_product_block')) {
    function dht_render_amazon_product_block($product, $args = array()) {
        if (!$product instanceof WC_Product || !function_exists('dht_amazon_safe_results')) {
            return;
        }

        $args = wp_parse_args($args, array(
            'limit' => 6,
            'title' => 'Otras opciones que te pueden interesar',
        ));

        $base_intents = dht_amazon_product_intents($product);
        $family_term = dht_amazon_product_deepest_category($product);
        $family_name = $family_term instanceof WP_Term ? $family_term->name : $product->get_name();
        $intents = function_exists('dht_amazon_enriched_intents') ? dht_amazon_enriched_intents($family_name, $base_intents, 6) : $base_intents;
        if (empty($intents)) return;

        $primary_query = (string)($intents[0]['query'] ?? $product->get_name());
        $result = dht_amazon_safe_results($primary_query, $args['limit'], array(
            'type'      => 'product',
            'object_id' => (int)$product->get_id(),
        ));
        $products = (array)($result['products'] ?? array());

        if (function_exists('dht_amazon_styles')) dht_amazon_styles();
        ?>
        <section class="dht-section dht-amazon-section dht-amazon-product-section" data-amazon-context="product" data-amazon-object-id="<?php echo esc_attr($product->get_id()); ?>">
            <div class="dht-container">
            <header class="dht-section-header">
                <span class="dht-amazon-kicker">Más opciones del mercado</span>
                <h2><?php echo esc_html($args['title']); ?></h2>
                <p class="dht-section-subtitle">Alternativas y complementos relacionados con este producto.</p>
            </header>

            <?php if (function_exists('dht_amazon_compare_points')) : ?>
                <div class="dht-amazon-market-copy">
                    <p>Si este producto no encaja exactamente con la aplicación que necesitas, puedes explorar otras variantes de su misma familia. Las opciones siguientes amplían medidas, capacidades, formatos y accesorios sin sustituir a los productos disponibles directamente en nuestra tienda.</p>
                </div>
                <div class="dht-amazon-compare">
                    <h3>Qué conviene comparar antes de elegir</h3>
                    <ul><?php foreach (dht_amazon_compare_points($family_name) as $point) : ?><li><?php echo esc_html($point); ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($products)) : ?>
                <div class="dht-amazon-grid">
                    <?php foreach ($products as $product_item) dht_amazon_card_markup($product_item, array('type' => 'product', 'object_id' => $product->get_id())); ?>
                </div>
            <?php elseif (function_exists('dht_amazon_intent_links_markup')) : ?>
                <?php dht_amazon_intent_links_markup($intents, 'product', (int)$product->get_id()); ?>
                <?php if (current_user_can('manage_options')) : ?>
                    <details class="dht-amazon-debug" style="margin-top:12px;"><summary><strong>Diagnóstico Amazon</strong></summary><div style="margin-top:7px;">Modo sin API. Las tarjetas anteriores son búsquedas afiliadas dinámicas. Consulta principal preparada: <code><?php echo esc_html($primary_query); ?></code>.</div></details>
                <?php endif; ?>
            <?php endif; ?>

            </div>
        </section>
        <?php
    }
}
