<?php
/**
 * Analista - propuestas comerciales para Marketing / Campanas.
 *
 * Analista no ejecuta promociones. Cruza demanda real, Search Console y la
 * posicion de precio calculada por Ojeador para proponer grupos de productos,
 * precios de campana y el margen bruto orientativo sobre el coste de proveedor.
 * Marketing es quien convierte una propuesta aprobada en una campana real.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_campaign_decode_json')) {
    function seo_analista_campaign_decode_json($value) {
        if (is_array($value)) return $value;
        if (!is_string($value) || trim($value) === '') return array();
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }
}

if (!function_exists('seo_analista_campaign_price_metrics')) {
    function seo_analista_campaign_price_metrics($price, $cost) {
        $price = is_numeric($price) ? (float) $price : 0.0;
        $cost = is_numeric($cost) ? (float) $cost : 0.0;
        if ($price <= 0 || $cost <= 0) {
            return array(
                'price' => $price > 0 ? round($price, 2) : null,
                'gross_amount' => null,
                'markup_on_cost_pct' => null,
                'margin_on_sale_pct' => null,
            );
        }
        $gross = $price - $cost;
        return array(
            'price' => round($price, 2),
            'gross_amount' => round($gross, 2),
            'markup_on_cost_pct' => round(($gross / $cost) * 100, 1),
            'margin_on_sale_pct' => round(($gross / $price) * 100, 1),
        );
    }
}

if (!function_exists('seo_analista_campaign_dependiente_demand')) {
    /**
     * Demanda de producto observada por Dependiente durante el periodo.
     *
     * No usa datos personales. Cuenta apariciones en los cinco primeros
     * resultados y clics confirmados sobre producto.
     *
     * @param int $days
     * @return array<int,array>
     */
    function seo_analista_campaign_dependiente_demand($days = 28) {
        global $wpdb;
        $days = function_exists('seo_analista_days') ? seo_analista_days($days) : max(7, min(90, absint($days)));
        $table = $wpdb->prefix . 'seo_dependiente_search_log';
        $exists = function_exists('seo_analista_table_exists')
            ? seo_analista_table_exists($table)
            : ($table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        if (!$exists) return array();

        $since = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($days * DAY_IN_SECONDS));
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,query_hash,query_normalized,top_results,clicked_product_id,created_at
                 FROM {$table}
                 WHERE created_at >= %s AND request_kind='search'
                 ORDER BY id DESC
                 LIMIT 20000",
                $since
            ),
            ARRAY_A
        );

        $out = array();
        foreach ($rows as $row) {
            $seen = array();
            $top = seo_analista_campaign_decode_json($row['top_results'] ?? '');
            foreach (array_slice($top, 0, 5) as $position => $result) {
                if (!is_array($result)) continue;
                $product_id = absint($result['id'] ?? 0);
                if (!$product_id || isset($seen[$product_id])) continue;
                $seen[$product_id] = true;
                if (!isset($out[$product_id])) {
                    $out[$product_id] = array(
                        'searches' => 0,
                        'weighted_searches' => 0.0,
                        'clicks' => 0,
                        'queries' => array(),
                    );
                }
                $rank = max(1, absint($result['position'] ?? ($position + 1)));
                $out[$product_id]['searches']++;
                $out[$product_id]['weighted_searches'] += 1 / $rank;
                $query_key = (string) ($row['query_hash'] ?? '');
                if ($query_key !== '') $out[$product_id]['queries'][$query_key] = true;
            }

            $clicked_id = absint($row['clicked_product_id'] ?? 0);
            if ($clicked_id) {
                if (!isset($out[$clicked_id])) {
                    $out[$clicked_id] = array(
                        'searches' => 0,
                        'weighted_searches' => 0.0,
                        'clicks' => 0,
                        'queries' => array(),
                    );
                }
                $out[$clicked_id]['clicks']++;
                $query_key = (string) ($row['query_hash'] ?? '');
                if ($query_key !== '') $out[$clicked_id]['queries'][$query_key] = true;
            }
        }

        foreach ($out as &$item) {
            $item['weighted_searches'] = round((float) $item['weighted_searches'], 3);
            $item['unique_queries'] = count((array) $item['queries']);
            unset($item['queries']);
        }
        unset($item);
        return $out;
    }
}

if (!function_exists('seo_analista_campaign_product_row')) {
    /**
     * Convierte una fila de Ojeador en una candidata comercial de Analista.
     */
    function seo_analista_campaign_product_row(array $row, array $demand) {
        $product_id = absint($row['object_id'] ?? 0);
        $cost = is_numeric($row['supplier_cost'] ?? null) ? (float) $row['supplier_cost'] : 0.0;
        $target = is_numeric($row['target_price_20'] ?? null) ? (float) $row['target_price_20'] : 0.0;
        $current = is_numeric($row['current_price'] ?? null) ? (float) $row['current_price'] : 0.0;
        $benchmark = is_numeric($row['market_benchmark_price'] ?? null) ? (float) $row['market_benchmark_price'] : 0.0;
        $market_min = is_numeric($row['market_price_min'] ?? null) ? (float) $row['market_price_min'] : 0.0;
        $market_max = is_numeric($row['market_price_max'] ?? null) ? (float) $row['market_price_max'] : 0.0;
        $confidence = (float) ($row['match_confidence_pct'] ?? 0);
        $competitors = absint($row['competitor_comparable_count'] ?? 0);
        $price_status = sanitize_key((string) ($row['price_status'] ?? ''));
        $stock = sanitize_key((string) ($row['stock_status'] ?? ''));

        if (!$product_id || $cost <= 0 || $benchmark <= 0 || $competitors < 1 || $confidence < 90) return null;
        if (in_array($stock, array('outofstock', 'out_of_stock', 'agotado'), true)) return null;

        $equal_price = $benchmark;
        $compete_price = $market_min > 0 ? ($market_min * 0.95) : ($benchmark * 0.95);
        $recommended = $target > 0 ? $target : $equal_price;
        if ($price_status === 'precio_mercado' && $equal_price > 0) {
            $recommended = min($recommended, $equal_price);
        }
        if ($current > 0) {
            $recommended = min($recommended, $current);
        }
        $recommended = round($recommended, 2);

        $dep_searches = absint($demand['searches'] ?? 0);
        $dep_weighted = (float) ($demand['weighted_searches'] ?? 0);
        $dep_clicks = absint($demand['clicks'] ?? 0);
        $unique_queries = absint($demand['unique_queries'] ?? 0);
        $product_impressions = max(0.0, (float) ($row['product_impressions'] ?? 0));
        $product_clicks = max(0.0, (float) ($row['product_clicks'] ?? 0));
        $category_impressions = max(0.0, (float) ($row['category_impressions'] ?? 0));
        $category_clicks = max(0.0, (float) ($row['category_clicks'] ?? 0));

        $direct_demand = $dep_searches + $dep_clicks + $product_impressions + $product_clicks;
        $demand_score = 0.0;
        $demand_score += min(35.0, log(1 + $dep_weighted) * 16.0);
        $demand_score += min(20.0, log(1 + $dep_clicks) * 14.0);
        $demand_score += min(22.0, log(1 + $product_impressions) * 3.2);
        $demand_score += min(12.0, log(1 + $product_clicks) * 6.0);
        $demand_score += min(6.0, log(1 + $category_impressions) * 0.7);
        $demand_score += ($price_status === 'precio_bueno') ? 12.0 : (($price_status === 'precio_mercado') ? 5.0 : 0.0);
        $demand_score = round(min(100.0, $demand_score), 1);

        $equal = seo_analista_campaign_price_metrics($equal_price, $cost);
        $compete = seo_analista_campaign_price_metrics($compete_price, $cost);
        $proposal = seo_analista_campaign_price_metrics($recommended, $cost);

        return array(
            'product_id' => $product_id,
            'product_name' => sanitize_text_field((string) ($row['product_name'] ?? '')),
            'provider' => sanitize_text_field((string) ($row['provider'] ?? '')),
            'supplier_sku' => sanitize_text_field((string) ($row['supplier_sku'] ?? '')),
            'term_id' => absint($row['term_id'] ?? 0),
            'category_name' => sanitize_text_field((string) ($row['category_name'] ?? '')),
            'stock_status' => $stock,
            'price_status' => $price_status,
            'price_status_label' => sanitize_text_field((string) ($row['price_status_label'] ?? '')),
            'supplier_cost' => round($cost, 2),
            'supplier_cost_source' => sanitize_key((string) ($row['supplier_cost_source'] ?? '')),
            'current_price' => $current > 0 ? round($current, 2) : null,
            'target_price_20' => $target > 0 ? round($target, 2) : null,
            'market_benchmark_price' => round($benchmark, 2),
            'market_price_min' => $market_min > 0 ? round($market_min, 2) : null,
            'market_price_max' => $market_max > 0 ? round($market_max, 2) : null,
            'match_confidence_pct' => round($confidence, 1),
            'competitor_comparable_count' => $competitors,
            'recommended_campaign_price' => $recommended,
            'recommended_margin' => $proposal,
            'equal_market' => $equal,
            'compete_market' => $compete,
            'demand' => array(
                'dependiente_searches' => $dep_searches,
                'dependiente_weighted_searches' => round($dep_weighted, 3),
                'dependiente_clicks' => $dep_clicks,
                'dependiente_unique_queries' => $unique_queries,
                'search_console_impressions' => $product_impressions,
                'search_console_clicks' => $product_clicks,
                'category_impressions' => $category_impressions,
                'category_clicks' => $category_clicks,
                'direct_demand' => $direct_demand,
                'score' => $demand_score,
            ),
            'ojeador' => array(
                'price_advantage_target_pct' => isset($row['price_advantage_target_pct']) ? (float) $row['price_advantage_target_pct'] : null,
                'price_vs_market_median_pct' => isset($row['price_vs_market_median_pct']) ? (float) $row['price_vs_market_median_pct'] : null,
                'supplier_discount_to_enter_market_pct' => isset($row['supplier_discount_to_enter_market_pct']) ? (float) $row['supplier_discount_to_enter_market_pct'] : null,
                'supplier_discount_to_green_pct' => isset($row['supplier_discount_to_green_pct']) ? (float) $row['supplier_discount_to_green_pct'] : null,
                'recommendation' => sanitize_text_field((string) ($row['recommendation'] ?? '')),
                'reason' => sanitize_text_field((string) ($row['reason'] ?? '')),
            ),
        );
    }
}

if (!function_exists('seo_analista_campaign_sort_products')) {
    function seo_analista_campaign_sort_products(&$rows) {
        usort($rows, static function ($a, $b) {
            $as = (float) ($a['demand']['score'] ?? 0);
            $bs = (float) ($b['demand']['score'] ?? 0);
            if ($as === $bs) {
                $ai = (float) ($a['demand']['search_console_impressions'] ?? 0);
                $bi = (float) ($b['demand']['search_console_impressions'] ?? 0);
                return $bi <=> $ai;
            }
            return $bs <=> $as;
        });
    }
}

if (!function_exists('seo_analista_campaign_proposal_summary')) {
    function seo_analista_campaign_proposal_summary(array $products) {
        $out = array(
            'products' => count($products),
            'dependiente_searches' => 0,
            'dependiente_clicks' => 0,
            'search_console_impressions' => 0.0,
            'search_console_clicks' => 0.0,
            'category_impressions' => 0.0,
            'avg_demand_score' => 0.0,
            'price_good' => 0,
            'price_market' => 0,
        );
        $score = 0.0;
        foreach ($products as $product) {
            $d = (array) ($product['demand'] ?? array());
            $out['dependiente_searches'] += absint($d['dependiente_searches'] ?? 0);
            $out['dependiente_clicks'] += absint($d['dependiente_clicks'] ?? 0);
            $out['search_console_impressions'] += (float) ($d['search_console_impressions'] ?? 0);
            $out['search_console_clicks'] += (float) ($d['search_console_clicks'] ?? 0);
            $out['category_impressions'] = max($out['category_impressions'], (float) ($d['category_impressions'] ?? 0));
            $score += (float) ($d['score'] ?? 0);
            if (($product['price_status'] ?? '') === 'precio_bueno') $out['price_good']++;
            if (($product['price_status'] ?? '') === 'precio_mercado') $out['price_market']++;
        }
        $out['avg_demand_score'] = $products ? round($score / count($products), 1) : 0.0;
        return $out;
    }
}

if (!function_exists('seo_analista_campaign_make_proposal')) {
    function seo_analista_campaign_make_proposal($key, $type, $name, $reason, array $products, $start, $end) {
        $summary = seo_analista_campaign_proposal_summary($products);
        $priority = (float) ($summary['avg_demand_score'] ?? 0);
        $priority += min(12.0, log(1 + (float) ($summary['dependiente_searches'] ?? 0)) * 5.0);
        $priority += min(8.0, log(1 + (float) ($summary['search_console_impressions'] ?? 0)) * 1.1);
        $priority = round(min(100.0, $priority), 1);
        return array(
            'key' => sanitize_key((string) $key),
            'type' => sanitize_key((string) $type),
            'name' => sanitize_text_field((string) $name),
            'reason' => sanitize_text_field((string) $reason),
            'start_at' => $start,
            'end_at' => $end,
            'duration_days' => 7,
            'priority_score' => $priority,
            'summary' => $summary,
            'products' => array_values($products),
        );
    }
}

if (!function_exists('seo_analista_campaign_proposals')) {
    /**
     * Propuestas de campana de Analista.
     *
     * - Solo propone productos con comparacion externa fiable de Ojeador.
     * - Precio bueno / de mercado pueden ser activables.
     * - Precio malo se conserva como bloqueo comercial, no como propuesta.
     * - La propuesta principal usa los productos con mayor demanda directa.
     * - Adicionalmente se crean propuestas por categoria con hasta 5 productos.
     */
    function seo_analista_campaign_proposals($days = 28, $limit = 12, $refresh = false) {
        $days = function_exists('seo_analista_days') ? seo_analista_days($days) : max(7, min(90, absint($days)));
        $limit = max(3, min(30, absint($limit)));
        $empty = array(
            'available' => false,
            'generated_at' => current_time('mysql'),
            'days' => $days,
            'summary' => array(),
            'proposals' => array(),
            'blocked_by_price' => array(),
            'notes' => array(),
        );
        if (!class_exists('SEO_Ojeador_Stars')) {
            $empty['notes'][] = 'Ojeador no esta disponible.';
            return $empty;
        }

        $market = SEO_Ojeador_Stars::export_data((bool) $refresh);
        $market_rows = (array) ($market['products'] ?? array());
        if (!$market_rows) {
            $empty['notes'][] = 'Ojeador no tiene productos comparables para proponer campanas.';
            return $empty;
        }

        $demand_map = seo_analista_campaign_dependiente_demand($days);
        $eligible = array();
        $blocked = array();
        foreach ($market_rows as $row) {
            if (!is_array($row)) continue;
            $product_id = absint($row['object_id'] ?? 0);
            $candidate = seo_analista_campaign_product_row($row, (array) ($demand_map[$product_id] ?? array()));
            if (!$candidate) continue;
            $status = (string) ($candidate['price_status'] ?? '');
            $has_signal = (float) ($candidate['demand']['direct_demand'] ?? 0) > 0
                || (float) ($candidate['demand']['category_impressions'] ?? 0) > 0;
            if (!$has_signal) continue;

            if ($status === 'precio_bueno' || $status === 'precio_mercado') {
                if ((float) ($candidate['recommended_campaign_price'] ?? 0) > 0
                    && (float) ($candidate['recommended_margin']['gross_amount'] ?? -1) >= 0) {
                    $eligible[] = $candidate;
                }
            } elseif ($status === 'precio_malo') {
                $blocked[] = $candidate;
            }
        }

        seo_analista_campaign_sort_products($eligible);
        seo_analista_campaign_sort_products($blocked);
        $blocked = array_slice($blocked, 0, 20);

        $now = new DateTimeImmutable('now', wp_timezone());
        $end = $now->modify('+7 days');
        $start_mysql = $now->format('Y-m-d H:i:s');
        $end_mysql = $end->format('Y-m-d H:i:s');
        $week_key = $now->format('o-W');
        $proposals = array();

        $direct = array_values(array_filter($eligible, static function ($row) {
            $d = (array) ($row['demand'] ?? array());
            return absint($d['dependiente_searches'] ?? 0) > 0
                || absint($d['dependiente_clicks'] ?? 0) > 0
                || (float) ($d['search_console_impressions'] ?? 0) > 0
                || (float) ($d['search_console_clicks'] ?? 0) > 0;
        }));
        seo_analista_campaign_sort_products($direct);
        if (count($direct) >= 2) {
            $top = array_slice($direct, 0, 5);
            $sum = seo_analista_campaign_proposal_summary($top);
            $reason = sprintf(
                'Los productos concentran %d busquedas de Dependiente, %d clics internos y %s impresiones de Search Console, con precio utilizable frente al mercado.',
                absint($sum['dependiente_searches'] ?? 0),
                absint($sum['dependiente_clicks'] ?? 0),
                number_format_i18n((int) ($sum['search_console_impressions'] ?? 0))
            );
            $proposals[] = seo_analista_campaign_make_proposal(
                'analista-top-demanda-' . $week_key,
                'top_demand',
                'Analista - productos mas buscados ahora',
                $reason,
                $top,
                $start_mysql,
                $end_mysql
            );
        }

        $groups = array();
        foreach ($eligible as $product) {
            $term_id = absint($product['term_id'] ?? 0);
            if (!$term_id) continue;
            if (!isset($groups[$term_id])) $groups[$term_id] = array();
            $groups[$term_id][] = $product;
        }

        foreach ($groups as $term_id => $products) {
            seo_analista_campaign_sort_products($products);
            $products = array_slice($products, 0, 5);
            $sum = seo_analista_campaign_proposal_summary($products);
            $direct_signals = absint($sum['dependiente_searches'] ?? 0) + (int) round((float) ($sum['search_console_impressions'] ?? 0));
            if ($direct_signals <= 0 && (float) ($sum['category_impressions'] ?? 0) <= 0) continue;
            $category = sanitize_text_field((string) ($products[0]['category_name'] ?? ('Categoria ' . $term_id)));
            $reason = sprintf(
                '%s combina demanda observada y %d producto(s) con precio bueno o de mercado. La comparacion procede de Ojeador y conserva el coste y el benchmark usados.',
                $category,
                count($products)
            );
            $proposals[] = seo_analista_campaign_make_proposal(
                'analista-categoria-' . absint($term_id) . '-' . $week_key,
                'category',
                'Analista - ' . $category,
                $reason,
                $products,
                $start_mysql,
                $end_mysql
            );
        }

        usort($proposals, static function ($a, $b) {
            if (($a['type'] ?? '') === 'top_demand' && ($b['type'] ?? '') !== 'top_demand') return -1;
            if (($b['type'] ?? '') === 'top_demand' && ($a['type'] ?? '') !== 'top_demand') return 1;
            return (float) ($b['priority_score'] ?? 0) <=> (float) ($a['priority_score'] ?? 0);
        });
        $proposals = array_slice($proposals, 0, $limit);

        return array(
            'available' => true,
            'generated_at' => current_time('mysql'),
            'days' => $days,
            'ojeador_generated_at_utc' => (string) ($market['generated_at_utc'] ?? ''),
            'summary' => array(
                'market_candidates' => count($market_rows),
                'eligible_products' => count($eligible),
                'blocked_by_price' => count($blocked),
                'proposal_count' => count($proposals),
                'dependiente_products_with_signal' => count($demand_map),
            ),
            'proposals' => $proposals,
            'blocked_by_price' => $blocked,
            'notes' => array(
                'El precio propuesto nunca sube el precio actual: parte del coste +20% de Ojeador y, si hace falta, se acerca al benchmark de mercado.',
                'Igualar mercado usa la mediana de competidores comparables. Competir usa el umbral de Ojeador: 5% por debajo del comparable mas barato.',
                'Margen bruto y markup se calculan sobre el coste de proveedor almacenado. No descuentan portes, comisiones, devoluciones ni otros costes operativos.',
                'La demanda directa combina busquedas/clics de Dependiente con impresiones/clics de Search Console del producto. La demanda de categoria solo actua como apoyo.',
            ),
        );
    }
}
