<?php
/**
 * Ojeador star products layer.
 *
 * Crosses our WooCommerce products and supplier costs with Google Shopping
 * comparable products plus Analista visibility. It is an advisory layer only:
 * it never changes prices, promotions or supplier data.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.9.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Stars {
    const MARKUP = 0.20;
    const CACHE_KEY = 'seo_ojeador_star_candidates_v090';
    const CACHE_TTL = 600;

    /**
     * Filtered/paginated star-product dashboard.
     *
     * @param array $args Filters.
     * @return array
     */
    public static function dashboard($args = array()) {
        $args = wp_parse_args($args, array(
            'q' => '',
            'provider' => '',
            'term_id' => 0,
            'class' => '',
            'min_score' => '',
            'min_impressions' => '',
            'max_supplier_discount' => '',
            'sort' => 'score',
            'page' => 1,
            'per_page' => 100,
            'refresh' => 0,
        ));

        if (!empty($args['refresh'])) {
            delete_transient(self::CACHE_KEY);
        }

        $payload = get_transient(self::CACHE_KEY);
        if (!is_array($payload) || empty($payload['products']) || empty($payload['generated_at_utc'])) {
            $payload = self::build_candidates();
            if (!empty($payload['products'])) {
                set_transient(self::CACHE_KEY, $payload, self::CACHE_TTL);
            }
        }

        $all = (array) ($payload['products'] ?? array());
        $filtered = self::apply_filters($all, $args);
        self::sort_rows($filtered, (string) $args['sort']);

        $total = count($filtered);
        $per_page = max(25, min(250, absint($args['per_page'])));
        $page = max(1, absint($args['page']));
        $pages = max(1, (int) ceil($total / $per_page));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $per_page;
        $rows = array_slice($filtered, $offset, $per_page);

        return array(
            'generated_at_utc' => (string) ($payload['generated_at_utc'] ?? gmdate('c')),
            'kpis' => (array) ($payload['kpis'] ?? array()),
            'thresholds' => (array) ($payload['thresholds'] ?? array()),
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $per_page,
            'filters' => $args,
            'provider_options' => self::provider_options($all),
            'category_options' => self::category_options($all),
            'class_options' => self::class_options(),
            'notes' => (array) ($payload['notes'] ?? array()),
        );
    }

    /**
     * Full compact export without pagination.
     *
     * @param bool $refresh Force recalculation.
     * @return array
     */
    public static function export_data($refresh = false) {
        if ($refresh) delete_transient(self::CACHE_KEY);
        $payload = get_transient(self::CACHE_KEY);
        if (!is_array($payload) || empty($payload['products'])) {
            $payload = self::build_candidates();
            if (!empty($payload['products'])) set_transient(self::CACHE_KEY, $payload, self::CACHE_TTL);
        }
        return $payload;
    }

    /** @return array */
    private static function build_candidates() {
        global $wpdb;

        $supplier_table = $wpdb->prefix . 'seo_proveedores_productos';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($supplier_table))) !== $supplier_table) {
            return self::empty_payload('No existe la tabla de catálogo de proveedores.');
        }

        $own_rows = self::own_product_rows($supplier_table);
        if (!$own_rows) {
            return self::empty_payload('No hay productos publicados enlazados con coste de proveedor y categorías con mercado válido.');
        }

        $market_by_term = self::market_results_by_term();
        $category_metrics = self::category_metrics();
        $product_metrics = self::product_metrics($own_rows);

        $best_by_product = array();
        foreach ($own_rows as $own) {
            $product_id = absint($own['object_id'] ?? 0);
            $term_id = absint($own['term_id'] ?? 0);
            if (!$product_id || !$term_id || empty($market_by_term[$term_id])) continue;

            $evaluated = self::evaluate_product_category(
                $own,
                (array) $market_by_term[$term_id],
                (array) ($category_metrics[$term_id] ?? array()),
                (array) ($product_metrics[$product_id] ?? array())
            );
            if (!$evaluated) continue;

            if (!isset($best_by_product[$product_id])) {
                $best_by_product[$product_id] = $evaluated;
                continue;
            }

            $current = $best_by_product[$product_id];
            $new_rank = self::preclassification_rank($evaluated);
            $old_rank = self::preclassification_rank($current);
            if ($new_rank > $old_rank || ($new_rank === $old_rank && (float) ($evaluated['match_confidence_pct'] ?? 0) > (float) ($current['match_confidence_pct'] ?? 0))) {
                $best_by_product[$product_id] = $evaluated;
            }
        }

        $products = array_values($best_by_product);
        $thresholds = self::visibility_thresholds($products);
        foreach ($products as &$row) {
            self::classify($row, $thresholds);
        }
        unset($row);

        // Keep only rows that can lead to a concrete commercial action.
        $products = array_values(array_filter($products, static function ($row) {
            return !empty($row['star_class']);
        }));
        self::sort_rows($products, 'score');

        return array(
            'generated_at_utc' => gmdate('c'),
            'kpis' => self::summary($products),
            'thresholds' => $thresholds,
            'products' => $products,
            'notes' => array(
                'Precio objetivo +20% = coste de proveedor almacenado x 1,20. No se modifica el precio WooCommerce.',
                'El margen comercial real debe revisar IVA, portes, comisiones de pago, devoluciones y cualquier coste no incluido en el precio del proveedor.',
                'El comparable de mercado se obtiene solo cuando Ojeador encuentra títulos suficientemente compatibles dentro de la misma categoría. La confianza de coincidencia se muestra siempre.',
                'Para VEVOR se admite como potencial una negociación de proveedor de hasta el 20%; para otros proveedores el umbral orientativo es del 12%.',
                'La visibilidad de producto procede de Analista/Search Console cuando la URL del producto está disponible; la visibilidad de categoría se usa como señal secundaria.',
            ),
        );
    }

    /** @return array */
    private static function empty_payload($reason) {
        return array(
            'generated_at_utc' => gmdate('c'),
            'kpis' => array(
                'candidates_total'=>0,'stars'=>0,'good_price'=>0,'offer_recommended'=>0,'high_visibility'=>0,'discount_potential'=>0,'vevor_candidates'=>0,
            ),
            'thresholds' => array(),
            'products' => array(),
            'notes' => array((string) $reason),
        );
    }

    /**
     * Published own products linked to supplier data and a scanned category.
     * One row per product/category.
     *
     * @param string $supplier_table Table name.
     * @return array
     */
    private static function own_product_rows($supplier_table) {
        global $wpdb;
        $categories = SEO_Ojeador_DB::table('categories');
        $sql = "SELECT DISTINCT
                    p.ID AS object_id,p.post_title,p.post_name,tt.term_id,t.name AS category_name,
                    pm_price.meta_value AS current_price,pm_regular.meta_value AS regular_price,
                    pm_stock.meta_value AS stock_status,pm_sku.meta_value AS sku,
                    pm_provider.meta_value AS provider_meta,pm_mpn.meta_value AS provider_mpn_meta,pm_brand.meta_value AS provider_brand_meta,
                    pm_cost.meta_value AS provider_cost_meta,pm_catalog.meta_value AS provider_catalog_id,
                    sp.proveedor,sp.proveedor_id_externo,sp.sku AS supplier_sku,sp.mpn AS supplier_mpn,
                    sp.nombre AS supplier_name,sp.marca AS supplier_brand,sp.precio_con_iva,sp.precio_sin_iva,
                    sp.iva_porcentaje,sp.moneda,sp.stock_estado AS supplier_stock
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=p.ID
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id AND tt.taxonomy='product_cat'
                INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
                INNER JOIN {$categories} oc ON oc.term_id=tt.term_id AND oc.status='ok'
                LEFT JOIN {$wpdb->postmeta} pm_price ON pm_price.post_id=p.ID AND pm_price.meta_key='_price'
                LEFT JOIN {$wpdb->postmeta} pm_regular ON pm_regular.post_id=p.ID AND pm_regular.meta_key='_regular_price'
                LEFT JOIN {$wpdb->postmeta} pm_stock ON pm_stock.post_id=p.ID AND pm_stock.meta_key='_stock_status'
                LEFT JOIN {$wpdb->postmeta} pm_sku ON pm_sku.post_id=p.ID AND pm_sku.meta_key='_sku'
                LEFT JOIN {$wpdb->postmeta} pm_provider ON pm_provider.post_id=p.ID AND pm_provider.meta_key='_seo_proveedor'
                LEFT JOIN {$wpdb->postmeta} pm_mpn ON pm_mpn.post_id=p.ID AND pm_mpn.meta_key='_seo_proveedor_mpn'
                LEFT JOIN {$wpdb->postmeta} pm_brand ON pm_brand.post_id=p.ID AND pm_brand.meta_key='_seo_marca_proveedor'
                LEFT JOIN {$wpdb->postmeta} pm_cost ON pm_cost.post_id=p.ID AND pm_cost.meta_key='_seo_precio_proveedor'
                LEFT JOIN {$wpdb->postmeta} pm_catalog ON pm_catalog.post_id=p.ID AND pm_catalog.meta_key='_seo_proveedor_catalogo_id'
                LEFT JOIN {$supplier_table} sp ON sp.id=CAST(pm_catalog.meta_value AS UNSIGNED)
                WHERE p.post_type='product' AND p.post_status='publish'
                  AND COALESCE(pm_stock.meta_value,'instock')<>'outofstock'
                  AND (
                      CAST(COALESCE(sp.precio_con_iva,0) AS DECIMAL(18,6))>0
                      OR CAST(COALESCE(pm_cost.meta_value,0) AS DECIMAL(18,6))>0
                      OR CAST(COALESCE(sp.precio_sin_iva,0) AS DECIMAL(18,6))>0
                  )
                ORDER BY p.ID ASC,tt.term_id ASC";

        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        foreach ($rows as &$row) {
            $cost = self::first_positive(array($row['precio_con_iva'] ?? null, $row['provider_cost_meta'] ?? null, $row['precio_sin_iva'] ?? null));
            $row['supplier_cost'] = $cost;
            $row['supplier_cost_source'] = self::positive($row['precio_con_iva'] ?? null) ? 'precio_con_iva' : (self::positive($row['provider_cost_meta'] ?? null) ? '_seo_precio_proveedor' : 'precio_sin_iva');
            $row['provider'] = trim((string) ($row['proveedor'] ?? '')) ?: trim((string) ($row['provider_meta'] ?? ''));
            $row['supplier_mpn_final'] = trim((string) ($row['supplier_mpn'] ?? '')) ?: trim((string) ($row['provider_mpn_meta'] ?? ''));
            $row['supplier_brand'] = trim((string) ($row['supplier_brand'] ?? '')) ?: trim((string) ($row['provider_brand_meta'] ?? ''));
            $row['sku_final'] = trim((string) ($row['supplier_sku'] ?? '')) ?: trim((string) ($row['sku'] ?? ''));
            $row['current_price'] = self::first_positive(array($row['current_price'] ?? null, $row['regular_price'] ?? null));
            $row['currency'] = trim((string) ($row['moneda'] ?? 'EUR')) ?: 'EUR';
        }
        unset($row);
        return $rows;
    }

    /** @return array */
    private static function market_results_by_term() {
        global $wpdb;
        $table = SEO_Ojeador_DB::table('category_results');
        $rows = (array) $wpdb->get_results(
            "SELECT id,term_id,google_product_id,gtin,mpn,brand,model,title,merchant,merchant_key,price,old_price,currency,rating,reviews,result_position,product_url,image_url,observed_at
             FROM {$table}
             WHERE active=1
             ORDER BY term_id ASC,result_position ASC,id ASC",
            ARRAY_A
        );
        $out = array();
        foreach ($rows as $row) {
            $term_id = absint($row['term_id'] ?? 0);
            if (!$term_id) continue;
            $out[$term_id][] = $row;
        }
        return $out;
    }

    /** @return array */
    private static function category_metrics() {
        $rows = SEO_Ojeador_DB::list_market_categories(array('limit'=>2000,'search'=>''));
        $out = array();
        foreach ((array) $rows as $row) {
            $term_id = absint($row['term_id'] ?? 0);
            if (!$term_id) continue;
            $out[$term_id] = array(
                'clicks' => (float) ($row['analista_clicks'] ?? 0),
                'impressions' => (float) ($row['analista_impressions'] ?? 0),
                'position' => (float) ($row['analista_position'] ?? 0),
            );
        }
        return $out;
    }

    /**
     * Map Analista metrics to product IDs by the final URL slug.
     *
     * @param array $own_rows Own rows.
     * @return array
     */
    private static function product_metrics($own_rows) {
        $out = array();
        if (!function_exists('seo_analista_get_data')) return $out;
        $data = seo_analista_get_data(28);
        if (!is_array($data) || empty($data['ready'])) return $out;

        $slug_map = array();
        foreach ((array) $own_rows as $row) {
            $id = absint($row['object_id'] ?? 0);
            $slug = sanitize_title((string) ($row['post_name'] ?? ''));
            if (!$id || $slug === '') continue;
            $slug_map[$slug][$id] = true;
        }

        foreach ((array) ($data['pages'] ?? array()) as $page) {
            if (!is_array($page)) continue;
            $url = (string) ($page['page_url'] ?? '');
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            $path = trim($path, '/');
            if ($path === '') continue;
            $parts = explode('/', $path);
            $slug = sanitize_title((string) end($parts));
            if ($slug === '' || empty($slug_map[$slug])) continue;
            foreach (array_keys($slug_map[$slug]) as $id) {
                $candidate = array(
                    'clicks' => max(0.0, (float) ($page['clicks'] ?? 0)),
                    'impressions' => max(0.0, (float) ($page['impressions'] ?? 0)),
                    'position' => max(0.0, (float) ($page['position'] ?? 0)),
                );
                if (!isset($out[$id]) || $candidate['impressions'] > (float) ($out[$id]['impressions'] ?? 0)) {
                    $out[$id] = $candidate;
                }
            }
        }
        return $out;
    }

    /** @return array|null */
    private static function evaluate_product_category($own, $market_rows, $category_metric, $product_metric) {
        $cost = self::float_or_null($own['supplier_cost'] ?? null);
        if ($cost === null || $cost <= 0) return null;

        $provider = trim((string) ($own['provider'] ?? ''));
        $brand = trim((string) ($own['supplier_brand'] ?? ''));
        $supplier_name = trim((string) ($own['supplier_name'] ?? ''));
        $post_title = trim((string) ($own['post_title'] ?? ''));
        $base_name = $supplier_name !== '' ? $supplier_name : $post_title;
        $identity = array(
            'name' => $base_name,
            'sku' => (string) ($own['sku_final'] ?? ''),
            'gtin' => '',
            'mpn' => (string) ($own['supplier_mpn_final'] ?? ''),
            'brand' => $brand,
            'model' => '',
        );
        $post_identity = $identity;
        $post_identity['name'] = $post_title;

        $focused = self::focus_market_rows($market_rows, $provider, $brand);
        $matches = array();
        foreach ($focused as $market) {
            $observed = array(
                'gtin' => (string) ($market['gtin'] ?? ''),
                'mpn' => (string) ($market['mpn'] ?? ''),
                'brand' => (string) ($market['brand'] ?? ''),
                'model' => (string) ($market['model'] ?? ''),
                'title' => (string) ($market['title'] ?? ''),
            );
            $m1 = SEO_Ojeador_Identity::match($identity, $observed);
            $m2 = SEO_Ojeador_Identity::match($post_identity, $observed);
            $confidence = max((float) ($m1['confidence'] ?? 0), (float) ($m2['confidence'] ?? 0));
            $method = ((float) ($m2['confidence'] ?? 0) > (float) ($m1['confidence'] ?? 0)) ? (string) ($m2['method'] ?? '') : (string) ($m1['method'] ?? '');

            $number_score = self::numeric_compatibility($base_name . ' ' . $post_title, (string) ($market['title'] ?? ''));
            $provider_affinity = self::provider_affinity($provider, $brand, (string) ($market['title'] ?? ''), (string) ($market['merchant'] ?? ''));
            if ($provider_affinity > 0) $confidence = min(0.99, $confidence + ($provider_affinity * 0.06));
            if ($number_score >= 0.75) $confidence = min(0.99, $confidence + 0.04);

            if ($confidence < 0.80) continue;
            if ($number_score >= 0 && $number_score < 0.40 && $confidence < 0.92) continue;

            $market['match_confidence'] = $confidence;
            $market['match_method'] = $method;
            $market['numeric_compatibility'] = $number_score;
            $market['supplier_direct'] = self::is_supplier_direct($provider, (string) ($market['merchant'] ?? ''), (string) ($market['title'] ?? '')) ? 1 : 0;
            $matches[] = $market;
        }

        usort($matches, static function ($a, $b) {
            $c = (float) ($b['match_confidence'] ?? 0) <=> (float) ($a['match_confidence'] ?? 0);
            if ($c !== 0) return $c;
            return absint($b['reviews'] ?? 0) <=> absint($a['reviews'] ?? 0);
        });

        $strong = array_values(array_filter($matches, static function ($m) {
            return (float) ($m['match_confidence'] ?? 0) >= 0.82;
        }));

        $competitor_prices = array();
        $all_prices = array();
        foreach ($strong as $m) {
            $price = self::float_or_null($m['price'] ?? null);
            if ($price === null || $price <= 0) continue;
            $all_prices[] = $price;
            if (empty($m['supplier_direct'])) $competitor_prices[] = $price;
        }
        $benchmark_source = '';
        if ($competitor_prices) {
            $benchmark = self::median($competitor_prices);
            $benchmark_source = 'competidores';
        } elseif ($all_prices) {
            $benchmark = self::median($all_prices);
            $benchmark_source = 'mercado_incluye_proveedor';
        } else {
            $benchmark = null;
        }

        $best = !empty($strong[0]) ? $strong[0] : (!empty($matches[0]) ? $matches[0] : array());
        $match_conf = (float) ($best['match_confidence'] ?? 0);
        $target = round($cost * (1 + self::MARKUP), 2);
        $current = self::float_or_null($own['current_price'] ?? null);
        $advantage_target = ($benchmark !== null && $benchmark > 0) ? (($benchmark - $target) / $benchmark) * 100 : null;
        $advantage_current = ($benchmark !== null && $benchmark > 0 && $current !== null && $current > 0) ? (($benchmark - $current) / $benchmark) * 100 : null;
        $discount_parity = ($benchmark !== null && $benchmark > 0 && $target > 0) ? max(0.0, (1 - ($benchmark / $target)) * 100) : null;
        $discount_below5 = ($benchmark !== null && $benchmark > 0 && $target > 0) ? max(0.0, (1 - (($benchmark * 0.95) / $target)) * 100) : null;

        return array(
            'object_id' => absint($own['object_id'] ?? 0),
            'product_name' => $post_title,
            'supplier_name' => $supplier_name,
            'provider' => $provider,
            'supplier_brand' => $brand,
            'supplier_sku' => (string) ($own['sku_final'] ?? ''),
            'supplier_mpn' => (string) ($own['supplier_mpn_final'] ?? ''),
            'term_id' => absint($own['term_id'] ?? 0),
            'category_name' => (string) ($own['category_name'] ?? ''),
            'stock_status' => (string) ($own['stock_status'] ?? 'instock'),
            'supplier_cost' => round($cost, 2),
            'supplier_cost_source' => (string) ($own['supplier_cost_source'] ?? ''),
            'currency' => (string) ($own['currency'] ?? 'EUR'),
            'current_price' => $current !== null ? round($current, 2) : null,
            'target_price_20' => $target,
            'supplier_discount_scenarios' => array(
                '0_pct' => $target,
                '5_pct' => round($cost * 0.95 * (1 + self::MARKUP), 2),
                '10_pct' => round($cost * 0.90 * (1 + self::MARKUP), 2),
                '15_pct' => round($cost * 0.85 * (1 + self::MARKUP), 2),
                '20_pct' => round($cost * 0.80 * (1 + self::MARKUP), 2),
            ),
            'market_benchmark_price' => $benchmark !== null ? round($benchmark, 2) : null,
            'benchmark_source' => $benchmark_source,
            'comparable_count' => count($strong),
            'competitor_comparable_count' => count($competitor_prices),
            'price_advantage_target_pct' => $advantage_target !== null ? round($advantage_target, 1) : null,
            'price_advantage_current_pct' => $advantage_current !== null ? round($advantage_current, 1) : null,
            'supplier_discount_to_parity_pct' => $discount_parity !== null ? round($discount_parity, 1) : null,
            'supplier_discount_to_5pct_below_pct' => $discount_below5 !== null ? round($discount_below5, 1) : null,
            'match_confidence_pct' => round($match_conf * 100, 1),
            'match_method' => (string) ($best['match_method'] ?? ''),
            'market_title' => (string) ($best['title'] ?? ''),
            'market_merchant' => (string) ($best['merchant'] ?? ''),
            'market_price' => self::float_or_null($best['price'] ?? null),
            'market_rating' => self::float_or_null($best['rating'] ?? null),
            'market_reviews' => absint($best['reviews'] ?? 0),
            'market_position' => absint($best['result_position'] ?? 0),
            'market_product_url' => (string) ($best['product_url'] ?? ''),
            'market_image_url' => (string) ($best['image_url'] ?? ''),
            'product_clicks' => (float) ($product_metric['clicks'] ?? 0),
            'product_impressions' => (float) ($product_metric['impressions'] ?? 0),
            'product_position' => (float) ($product_metric['position'] ?? 0),
            'category_clicks' => (float) ($category_metric['clicks'] ?? 0),
            'category_impressions' => (float) ($category_metric['impressions'] ?? 0),
            'category_position' => (float) ($category_metric['position'] ?? 0),
            'star_class' => '',
            'star_label' => '',
            'star_score' => 0,
            'recommendation' => '',
            'reason' => '',
        );
    }

    /** @return array */
    private static function focus_market_rows($rows, $provider, $brand) {
        $provider_key = self::keyword($provider);
        $brand_key = self::keyword($brand);
        if ($provider_key === '' && $brand_key === '') return $rows;
        $focused = array();
        foreach ((array) $rows as $row) {
            $hay = SEO_Ojeador_Identity::normalize_text((string) ($row['title'] ?? '') . ' ' . (string) ($row['merchant'] ?? ''));
            if (($provider_key !== '' && strpos($hay, $provider_key) !== false) || ($brand_key !== '' && strpos($hay, $brand_key) !== false)) {
                $focused[] = $row;
            }
        }
        return $focused ? $focused : $rows;
    }

    private static function provider_affinity($provider, $brand, $title, $merchant) {
        $hay = SEO_Ojeador_Identity::normalize_text($title . ' ' . $merchant);
        $provider_key = self::keyword($provider);
        $brand_key = self::keyword($brand);
        $score = 0.0;
        if ($provider_key !== '' && strpos($hay, $provider_key) !== false) $score = max($score, 1.0);
        if ($brand_key !== '' && strpos($hay, $brand_key) !== false) $score = max($score, 0.9);
        return $score;
    }

    private static function is_supplier_direct($provider, $merchant, $title) {
        $provider_key = self::keyword($provider);
        if ($provider_key === '') return false;
        $merchant_key = SEO_Ojeador_Identity::normalize_text($merchant);
        if ($merchant_key !== '' && strpos($merchant_key, $provider_key) !== false) return true;
        // If merchant is missing, a supplier-branded title is useful as a public supplier reference.
        if ($merchant_key === '' && strpos(SEO_Ojeador_Identity::normalize_text($title), $provider_key) !== false) return true;
        return false;
    }

    private static function keyword($value) {
        $value = SEO_Ojeador_Identity::normalize_text($value);
        if ($value === '') return '';
        $parts = array_values(array_filter(explode(' ', $value), static function ($v) { return strlen($v) >= 4; }));
        if (!$parts) return '';
        $key = (string) $parts[0];
        // Known commercial alias in our supplier inventory.
        if ($key === 'todotaladros') return 'totherramienta';
        return $key;
    }

    /**
     * -1 means no useful numeric evidence. Otherwise 0..1 compatibility.
     */
    private static function numeric_compatibility($left, $right) {
        preg_match_all('/\d+(?:[\.,]\d+)?/', remove_accents(strtolower((string) $left)), $ma);
        preg_match_all('/\d+(?:[\.,]\d+)?/', remove_accents(strtolower((string) $right)), $mb);
        $a = array_values(array_unique(array_map(static function ($v) { return str_replace(',', '.', $v); }, $ma[0] ?? array())));
        $b = array_values(array_unique(array_map(static function ($v) { return str_replace(',', '.', $v); }, $mb[0] ?? array())));
        if (count($a) < 2 || count($b) < 1) return -1.0;
        $intersection = array_intersect($a, $b);
        return count($a) ? count($intersection) / count($a) : -1.0;
    }

    /** @return array */
    private static function visibility_thresholds($rows) {
        $product_imp = array();
        $category_imp = array();
        foreach ((array) $rows as $row) {
            $p = (float) ($row['product_impressions'] ?? 0);
            $c = (float) ($row['category_impressions'] ?? 0);
            if ($p > 0) $product_imp[] = $p;
            if ($c > 0) $category_imp[] = $c;
        }
        return array(
            'product_impressions' => self::percentiles($product_imp),
            'category_impressions' => self::percentiles($category_imp),
        );
    }

    private static function classify(&$row, $thresholds) {
        $benchmark = self::float_or_null($row['market_benchmark_price'] ?? null);
        $confidence = (float) ($row['match_confidence_pct'] ?? 0);
        $advantage = self::float_or_null($row['price_advantage_target_pct'] ?? null);
        $discount_needed = self::float_or_null($row['supplier_discount_to_5pct_below_pct'] ?? null);
        $provider = strtolower((string) ($row['provider'] ?? ''));
        $is_vevor = strpos($provider, 'vevor') !== false;
        $negotiation_limit = $is_vevor ? 20.0 : 12.0;

        $pimp = (float) ($row['product_impressions'] ?? 0);
        $cimp = (float) ($row['category_impressions'] ?? 0);
        $p75 = (float) ($thresholds['product_impressions']['p75'] ?? 0);
        $c75 = (float) ($thresholds['category_impressions']['p75'] ?? 0);
        $product_high = $pimp > 0 && ($p75 <= 0 || $pimp >= $p75);
        $category_high = $cimp > 0 && ($c75 <= 0 || $cimp >= $c75);
        $high_visibility = $product_high || $category_high;

        $price_score = 0.0;
        if ($advantage !== null) {
            $price_score = max(0.0, min(100.0, 50.0 + ($advantage * 3.0)));
        }
        $vis_product = self::visibility_index($pimp, (array) ($thresholds['product_impressions'] ?? array()));
        $vis_category = self::visibility_index($cimp, (array) ($thresholds['category_impressions'] ?? array())) * 0.70;
        $visibility_score = max($vis_product, $vis_category);
        $reviews = absint($row['market_reviews'] ?? 0);
        $rating = self::float_or_null($row['market_rating'] ?? null);
        $proof = min(100.0, (log10($reviews + 1) / 4.0) * 100.0);
        if ($rating !== null && $rating > 0) $proof *= min(1.0, max(0.4, $rating / 5.0));
        $match_score = min(100.0, max(0.0, $confidence));

        if ($benchmark !== null && $confidence >= 82) {
            $score = ($price_score * 0.45) + ($visibility_score * 0.25) + ($proof * 0.15) + ($match_score * 0.15);
        } else {
            $score = ($visibility_score * 0.50) + ($proof * 0.25) + ($match_score * 0.25);
        }
        $row['star_score'] = round($score, 1);

        if ($benchmark !== null && $confidence >= 82 && $advantage !== null && $advantage >= 8) {
            $row['star_class'] = 'estrella';
            $row['star_label'] = 'Estrella';
            $row['recommendation'] = 'Destacar y probar promoción: el precio objetivo con +20% ya queda claramente por debajo del comparable.';
            $row['reason'] = sprintf('Ventaja estimada %.1f%% con +20%% sobre coste; %d imp. producto y %d imp. categoría.', $advantage, (int) $pimp, (int) $cimp);
            return;
        }
        if ($benchmark !== null && $confidence >= 82 && $advantage !== null && $advantage >= 0) {
            $row['star_class'] = 'buen_precio';
            $row['star_label'] = 'Buen precio';
            $row['recommendation'] = 'Mantener en vigilancia comercial: podemos competir sin exigir descuento adicional al proveedor.';
            $row['reason'] = sprintf('Precio objetivo competitivo (%.1f%% frente al comparable) con confianza %.0f%%.', $advantage, $confidence);
            return;
        }
        if ($high_visibility && $benchmark !== null && $confidence >= 82 && $discount_needed !== null && $discount_needed <= $negotiation_limit) {
            $row['star_class'] = 'oferta_recomendada';
            $row['star_label'] = 'Oferta recomendada';
            $row['recommendation'] = $is_vevor
                ? 'Intentar descuento VEVOR y lanzar una oferta: hay visibilidad y el ajuste necesario entra en un rango negociable.'
                : 'Negociar proveedor y probar una oferta: hay visibilidad y el ajuste necesario es moderado.';
            $row['reason'] = sprintf('Necesita aprox. %.1f%% de mejora de compra para quedar 5%% por debajo del comparable; %d imp. producto / %d categoría.', $discount_needed, (int) $pimp, (int) $cimp);
            return;
        }
        if ($benchmark !== null && $confidence >= 82 && $discount_needed !== null && $discount_needed <= $negotiation_limit && ($reviews >= 50 || $category_high)) {
            $row['star_class'] = 'potencial_descuento';
            $row['star_label'] = 'Potencial con descuento';
            $row['recommendation'] = $is_vevor
                ? 'Pedir descuento VEVOR: con una mejora razonable de compra podría convertirse en producto promocionable.'
                : 'Revisar condiciones de proveedor: con una mejora moderada de compra puede entrar en precio.';
            $row['reason'] = sprintf('Descuento de compra estimado para objetivo: %.1f%%; comparable con %d reviews.', $discount_needed, $reviews);
            return;
        }
        if ($product_high) {
            $row['star_class'] = 'alta_visibilidad';
            $row['star_label'] = 'Alta visibilidad';
            $row['recommendation'] = 'Revisar precio/oferta manualmente: este producto ya está recibiendo visibilidad propia aunque Ojeador no tenga un comparable suficientemente sólido.';
            $row['reason'] = sprintf('%d impresiones del producto en 28 días.', (int) $pimp);
            return;
        }

        $row['star_class'] = '';
        $row['star_label'] = '';
    }

    private static function preclassification_rank($row) {
        $confidence = (float) ($row['match_confidence_pct'] ?? 0);
        $has_benchmark = self::float_or_null($row['market_benchmark_price'] ?? null) !== null ? 1000 : 0;
        $adv = self::float_or_null($row['price_advantage_target_pct'] ?? null);
        $adv_rank = $adv !== null ? max(-100, min(100, $adv)) : -100;
        $vis = (float) ($row['product_impressions'] ?? 0) + ((float) ($row['category_impressions'] ?? 0) * 0.1);
        return $has_benchmark + ($confidence * 3) + $adv_rank + min(100, $vis);
    }

    /** @return array */
    private static function summary($rows) {
        $out = array(
            'candidates_total'=>count((array) $rows),'stars'=>0,'good_price'=>0,'offer_recommended'=>0,'high_visibility'=>0,'discount_potential'=>0,'vevor_candidates'=>0,
        );
        foreach ((array) $rows as $row) {
            $class = (string) ($row['star_class'] ?? '');
            if ($class === 'estrella') $out['stars']++;
            elseif ($class === 'buen_precio') $out['good_price']++;
            elseif ($class === 'oferta_recomendada') $out['offer_recommended']++;
            elseif ($class === 'alta_visibilidad') $out['high_visibility']++;
            elseif ($class === 'potencial_descuento') $out['discount_potential']++;
            if (stripos((string) ($row['provider'] ?? ''), 'vevor') !== false) $out['vevor_candidates']++;
        }
        return $out;
    }

    /** @return array */
    private static function apply_filters($rows, $args) {
        $q = SEO_Ojeador_Identity::normalize_text((string) ($args['q'] ?? ''));
        $provider = SEO_Ojeador_Identity::normalize_text((string) ($args['provider'] ?? ''));
        $term_id = absint($args['term_id'] ?? 0);
        $class = sanitize_key((string) ($args['class'] ?? ''));
        $min_score = ($args['min_score'] !== '' && is_numeric($args['min_score'])) ? (float) $args['min_score'] : null;
        $min_imp = ($args['min_impressions'] !== '' && is_numeric($args['min_impressions'])) ? (float) $args['min_impressions'] : null;
        $max_discount = ($args['max_supplier_discount'] !== '' && is_numeric($args['max_supplier_discount'])) ? (float) $args['max_supplier_discount'] : null;

        return array_values(array_filter((array) $rows, static function ($row) use ($q,$provider,$term_id,$class,$min_score,$min_imp,$max_discount) {
            if ($term_id && absint($row['term_id'] ?? 0) !== $term_id) return false;
            if ($class !== '' && sanitize_key((string) ($row['star_class'] ?? '')) !== $class) return false;
            if ($provider !== '' && SEO_Ojeador_Identity::normalize_text((string) ($row['provider'] ?? '')) !== $provider) return false;
            if ($q !== '') {
                $hay = SEO_Ojeador_Identity::normalize_text((string) ($row['product_name'] ?? '') . ' ' . (string) ($row['supplier_name'] ?? '') . ' ' . (string) ($row['market_title'] ?? '') . ' ' . (string) ($row['supplier_sku'] ?? ''));
                if (strpos($hay, $q) === false) return false;
            }
            if ($min_score !== null && (float) ($row['star_score'] ?? 0) < $min_score) return false;
            if ($min_imp !== null && max((float) ($row['product_impressions'] ?? 0), (float) ($row['category_impressions'] ?? 0)) < $min_imp) return false;
            if ($max_discount !== null) {
                $d = self::float_or_null($row['supplier_discount_to_5pct_below_pct'] ?? null);
                if ($d === null || $d > $max_discount) return false;
            }
            return true;
        }));
    }

    private static function sort_rows(&$rows, $sort) {
        $sort = sanitize_key((string) $sort);
        usort($rows, static function ($a, $b) use ($sort) {
            switch ($sort) {
                case 'advantage':
                    return (float) ($b['price_advantage_target_pct'] ?? -999) <=> (float) ($a['price_advantage_target_pct'] ?? -999);
                case 'impressions':
                    $ai = max((float) ($a['product_impressions'] ?? 0), (float) ($a['category_impressions'] ?? 0));
                    $bi = max((float) ($b['product_impressions'] ?? 0), (float) ($b['category_impressions'] ?? 0));
                    return $bi <=> $ai;
                case 'reviews':
                    return absint($b['market_reviews'] ?? 0) <=> absint($a['market_reviews'] ?? 0);
                case 'discount_needed':
                    $ad = self::float_or_null($a['supplier_discount_to_5pct_below_pct'] ?? null); if ($ad === null) $ad = 999;
                    $bd = self::float_or_null($b['supplier_discount_to_5pct_below_pct'] ?? null); if ($bd === null) $bd = 999;
                    return $ad <=> $bd;
                case 'provider':
                    return strcasecmp((string) ($a['provider'] ?? ''), (string) ($b['provider'] ?? ''));
                case 'score':
                default:
                    $c = (float) ($b['star_score'] ?? 0) <=> (float) ($a['star_score'] ?? 0);
                    if ($c !== 0) return $c;
                    return (float) ($b['match_confidence_pct'] ?? 0) <=> (float) ($a['match_confidence_pct'] ?? 0);
            }
        });
    }

    /** @return array */
    private static function provider_options($rows) {
        $counts = array();
        foreach ((array) $rows as $row) {
            $name = trim((string) ($row['provider'] ?? ''));
            if ($name === '') $name = 'Sin proveedor';
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
        $out = array();
        foreach ($counts as $name=>$count) $out[] = array('provider'=>$name,'count'=>$count);
        return $out;
    }

    /** @return array */
    private static function category_options($rows) {
        $items = array();
        foreach ((array) $rows as $row) {
            $id = absint($row['term_id'] ?? 0);
            if (!$id) continue;
            if (!isset($items[$id])) $items[$id] = array('term_id'=>$id,'category_name'=>(string) ($row['category_name'] ?? ''),'count'=>0);
            $items[$id]['count']++;
        }
        $items = array_values($items);
        usort($items, static function ($a,$b) { return strcasecmp((string) $a['category_name'], (string) $b['category_name']); });
        return $items;
    }

    /** @return array */
    public static function class_options() {
        return array(
            'estrella' => 'Estrella',
            'buen_precio' => 'Buen precio',
            'oferta_recomendada' => 'Oferta recomendada',
            'potencial_descuento' => 'Potencial con descuento',
            'alta_visibilidad' => 'Alta visibilidad',
        );
    }

    private static function visibility_index($value, $q) {
        $value = max(0.0, (float) $value);
        if ($value <= 0) return 0.0;
        $p50 = max(0.0, (float) ($q['p50'] ?? 0));
        $p75 = max($p50, (float) ($q['p75'] ?? $p50));
        $p90 = max($p75, (float) ($q['p90'] ?? $p75));
        if ($p50 <= 0) return $p90 > 0 ? min(100, ($value/$p90)*100) : 0;
        if ($value <= $p50) return min(50, ($value/$p50)*50);
        if ($value <= $p75 && $p75>$p50) return 50+(($value-$p50)/($p75-$p50))*25;
        if ($value <= $p90 && $p90>$p75) return 75+(($value-$p75)/($p90-$p75))*25;
        return 100;
    }

    private static function percentiles($values) {
        $values = array_values(array_filter(array_map('floatval', (array) $values), static function ($v) { return $v > 0; }));
        return array('n'=>count($values),'p50'=>self::percentile($values,0.50),'p75'=>self::percentile($values,0.75),'p90'=>self::percentile($values,0.90));
    }

    private static function percentile($values, $p) {
        if (!$values) return 0.0;
        sort($values, SORT_NUMERIC);
        $idx = $p * (count($values)-1);
        $lo = (int) floor($idx); $hi = (int) ceil($idx);
        if ($lo === $hi) return (float) $values[$lo];
        $w = $idx-$lo;
        return ((float) $values[$lo]*(1-$w))+((float) $values[$hi]*$w);
    }

    private static function median($values) {
        $values = array_values(array_filter(array_map('floatval', (array) $values), static function ($v) { return $v > 0; }));
        if (!$values) return null;
        sort($values, SORT_NUMERIC);
        $n=count($values); $mid=intdiv($n,2);
        return $n%2 ? (float) $values[$mid] : (((float) $values[$mid-1]+(float) $values[$mid])/2);
    }

    private static function first_positive($values) {
        foreach ((array) $values as $value) {
            if (self::positive($value)) return (float) $value;
        }
        return null;
    }

    private static function positive($value) {
        return $value !== null && $value !== '' && is_numeric($value) && (float) $value > 0;
    }

    private static function float_or_null($value) {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (float) $value;
    }
}
