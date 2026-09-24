<?php
/**
 * Ojeador market analysis layer.
 *
 * Converts Google Shopping category snapshots into actionable KPIs, internal
 * prioritisation indices and review recommendations. It never changes prices,
 * categories or products automatically.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.8.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Analysis {

    /**
     * Build the analysis payload used by the admin screen and compact export.
     *
     * @param int $recommendation_limit Maximum commercial recommendations.
     * @return array
     */
    public static function dashboard($recommendation_limit = 60) {
        $rows = SEO_Ojeador_DB::list_market_categories(array('limit'=>2000, 'search'=>''));
        $market = self::market_aggregates();
        $merchant_shares = self::top_merchant_shares();

        $analysed = array();
        foreach ($rows as $row) {
            $term_id = absint($row['term_id'] ?? 0);
            $agg = isset($market[$term_id]) ? $market[$term_id] : self::empty_market_row();
            $total_results = absint($agg['result_count'] ?? 0);
            $top_share = isset($merchant_shares[$term_id]) ? (float) $merchant_shares[$term_id] : 0.0;
            $price_count = absint($agg['price_count'] ?? 0);
            $discounted = absint($agg['discounted_count'] ?? 0);
            $rated_count = absint($agg['rated_count'] ?? 0);

            $analysed[] = array_merge($row, array(
                'market_result_count' => $total_results,
                'merchant_count' => absint($agg['merchant_count'] ?? 0),
                'price_count' => $price_count,
                'price_min' => self::nullable_float($agg['price_min'] ?? null),
                'currency' => (string) ($agg['currency'] ?? 'EUR'),
                'price_avg' => self::nullable_float($agg['price_avg'] ?? null),
                'price_max' => self::nullable_float($agg['price_max'] ?? null),
                'discounted_count' => $discounted,
                'discount_share_pct' => $price_count > 0 ? ($discounted / $price_count) * 100 : null,
                'avg_rating' => self::nullable_float($agg['avg_rating'] ?? null),
                'rated_count' => $rated_count,
                'rating_data_ratio_pct' => $total_results > 0 ? ($rated_count / $total_results) * 100 : null,
                'total_reviews' => absint($agg['total_reviews'] ?? 0),
                'top_merchant_share_pct' => $top_share * 100,
                'merchant_data_ratio_pct' => $total_results > 0 ? (absint($agg['merchant_rows'] ?? 0) / $total_results) * 100 : null,
                'price_data_ratio_pct' => $total_results > 0 ? ($price_count / $total_results) * 100 : null,
            ));
        }

        $thresholds = self::thresholds($analysed);
        foreach ($analysed as &$row) {
            self::attach_indices($row, $thresholds);
            self::attach_recommendations($row, $thresholds);
        }
        unset($row);

        $summary = self::summary($analysed, $thresholds);
        $recommendations = self::commercial_recommendations($analysed, $recommendation_limit);
        $operational = self::operational_recommendations($summary);

        return array(
            'generated_at_utc' => gmdate('c'),
            'kpis' => $summary,
            'thresholds' => $thresholds,
            'recommendations' => $recommendations,
            'operational_recommendations' => $operational,
            'categories' => $analysed,
            'merchants' => self::merchant_ranking(30),
            'notes' => array(
                'Los resultados de Google Shopping son una muestra de la consulta y no equivalen al tamaño total del mercado.',
                'El número de resultados suele estar limitado por la respuesta del proveedor; por eso no se usa como índice de demanda.',
                'El índice de oportunidad es una prioridad interna: combina profundidad relativa de nuestro catálogo, amplitud de comercios, visibilidad de Analista y volumen de reseñas. No es una estimación de ventas.',
                'La dispersión min–max de precios se muestra como contexto, pero no genera recomendaciones por sí sola porque una categoría puede mezclar productos y gamas diferentes.',
                'Las recomendaciones son de revisión. Ojeador no modifica precios, catálogo ni taxonomía automáticamente.',
            ),
        );
    }

    /**
     * Paginated product comparison table over active Shopping results.
     *
     * @param array $args Filters and pagination.
     * @return array
     */
    public static function comparison_products($args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array(
            'term_id' => 0,
            'q' => '',
            'merchant' => '',
            'min_price' => '',
            'max_price' => '',
            'min_rating' => '',
            'min_reviews' => '',
            'discounted' => 0,
            'sort' => 'position',
            'page' => 1,
            'per_page' => 100,
        ));

        $table = SEO_Ojeador_DB::table('category_results');
        $categories = SEO_Ojeador_DB::table('categories');
        $where = array("r.active=1", "c.status='ok'");
        $params = array();

        $term_id = absint($args['term_id']);
        if ($term_id) {
            $where[] = 'r.term_id=%d';
            $params[] = $term_id;
        }

        $q = trim((string) $args['q']);
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(r.title LIKE %s OR r.brand LIKE %s OR r.model LIKE %s OR r.merchant LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }

        $merchant = trim((string) $args['merchant']);
        if ($merchant !== '') {
            $where[] = 'r.merchant LIKE %s';
            $params[] = '%' . $wpdb->esc_like($merchant) . '%';
        }

        foreach (array('min_price','max_price','min_rating') as $numeric_key) {
            if ($args[$numeric_key] !== '' && is_numeric($args[$numeric_key])) {
                $value = (float) $args[$numeric_key];
                if ($numeric_key === 'min_price') {
                    $where[] = 'r.price >= %f';
                } elseif ($numeric_key === 'max_price') {
                    $where[] = 'r.price <= %f';
                } else {
                    $where[] = 'r.rating >= %f';
                }
                $params[] = $value;
            }
        }

        if ($args['min_reviews'] !== '' && is_numeric($args['min_reviews'])) {
            $where[] = 'r.reviews >= %d';
            $params[] = max(0, absint($args['min_reviews']));
        }
        if (!empty($args['discounted'])) {
            $where[] = 'r.price IS NOT NULL AND r.price>0 AND r.old_price IS NOT NULL AND r.old_price>r.price';
        }

        $order_map = array(
            'position' => 'r.term_id ASC,r.result_position ASC,r.id ASC',
            'price_asc' => 'r.price IS NULL ASC,r.price ASC,r.id ASC',
            'price_desc' => 'r.price IS NULL ASC,r.price DESC,r.id ASC',
            'rating_desc' => 'r.rating IS NULL ASC,r.rating DESC,r.reviews DESC,r.id ASC',
            'reviews_desc' => 'r.reviews DESC,r.rating DESC,r.id ASC',
            'discount_desc' => 'CASE WHEN r.old_price>r.price AND r.old_price>0 THEN ((r.old_price-r.price)/r.old_price) ELSE 0 END DESC,r.id ASC',
            'merchant' => 'r.merchant ASC,r.title ASC',
            'title' => 'r.title ASC,r.merchant ASC',
            'recent' => 'r.observed_at DESC,r.id DESC',
        );
        $sort = sanitize_key((string) $args['sort']);
        $order_by = isset($order_map[$sort]) ? $order_map[$sort] : $order_map['position'];

        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$table} r LEFT JOIN {$categories} c ON c.term_id=r.term_id WHERE {$where_sql}";
        if ($params) {
            $count_sql = $wpdb->prepare($count_sql, $params);
        }
        $total = absint($wpdb->get_var($count_sql));

        $per_page = max(20, min(200, absint($args['per_page'])));
        $page = max(1, absint($args['page']));
        $pages = max(1, (int) ceil($total / $per_page));
        if ($page > $pages) $page = $pages;
        $offset = ($page - 1) * $per_page;

        $select_sql = "SELECT r.id,r.term_id,
                              COALESCE(NULLIF(c.category_name,''),t.name) AS category_name,
                              c.query_text,
                              r.google_product_id,r.gtin,r.mpn,r.brand,r.model,r.title,
                              r.merchant,r.merchant_key,r.price,r.old_price,r.currency,r.delivery,
                              r.rating,r.reviews,r.image_url,r.merchant_url,r.product_url,
                              r.result_position,r.first_seen_at,r.last_seen_at,r.observed_at
                       FROM {$table} r
                       LEFT JOIN {$categories} c ON c.term_id=r.term_id
                       LEFT JOIN {$wpdb->terms} t ON t.term_id=r.term_id
                       WHERE {$where_sql}
                       ORDER BY {$order_by}
                       LIMIT %d OFFSET %d";
        $select_params = $params;
        $select_params[] = $per_page;
        $select_params[] = $offset;
        $select_sql = $wpdb->prepare($select_sql, $select_params);
        $rows = (array) $wpdb->get_results($select_sql, ARRAY_A);

        foreach ($rows as &$row) {
            $price = self::nullable_float($row['price'] ?? null);
            $old = self::nullable_float($row['old_price'] ?? null);
            $row['discount_pct'] = ($price !== null && $old !== null && $old > $price && $old > 0)
                ? (($old - $price) / $old) * 100
                : null;
        }
        unset($row);

        return array(
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $per_page,
            'filters' => $args,
        );
    }

    /** @return array */
    public static function comparison_category_options() {
        global $wpdb;
        $table = SEO_Ojeador_DB::table('category_results');
        $categories = SEO_Ojeador_DB::table('categories');
        return (array) $wpdb->get_results(
            "SELECT r.term_id,COALESCE(NULLIF(c.category_name,''),t.name) AS category_name,COUNT(*) AS products_found
             FROM {$table} r
             LEFT JOIN {$categories} c ON c.term_id=r.term_id
             LEFT JOIN {$wpdb->terms} t ON t.term_id=r.term_id
             WHERE r.active=1 AND c.status='ok'
             GROUP BY r.term_id,category_name
             ORDER BY category_name ASC",
            ARRAY_A
        );
    }

    /** @return array */
    private static function market_aggregates() {
        global $wpdb;
        $table = SEO_Ojeador_DB::table('category_results');
        $sql = "SELECT term_id,
                       COUNT(*) AS result_count,
                       COUNT(DISTINCT NULLIF(merchant_key,'')) AS merchant_count,
                       SUM(CASE WHEN merchant_key<>'' THEN 1 ELSE 0 END) AS merchant_rows,
                       SUM(CASE WHEN price IS NOT NULL AND price>0 THEN 1 ELSE 0 END) AS price_count,
                       MIN(CASE WHEN price>0 THEN price ELSE NULL END) AS price_min,
                       AVG(CASE WHEN price>0 THEN price ELSE NULL END) AS price_avg,
                       MAX(CASE WHEN price>0 THEN price ELSE NULL END) AS price_max,
                       MAX(currency) AS currency,
                       SUM(CASE WHEN price>0 AND old_price>price THEN 1 ELSE 0 END) AS discounted_count,
                       AVG(CASE WHEN rating IS NOT NULL AND rating>0 THEN rating ELSE NULL END) AS avg_rating,
                       SUM(CASE WHEN rating IS NOT NULL AND rating>0 THEN 1 ELSE 0 END) AS rated_count,
                       SUM(reviews) AS total_reviews
                FROM {$table}
                WHERE active=1
                GROUP BY term_id";
        $out = array();
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
            $out[absint($row['term_id'] ?? 0)] = $row;
        }
        return $out;
    }

    /** @return array */
    private static function top_merchant_shares() {
        global $wpdb;
        $table = SEO_Ojeador_DB::table('category_results');
        $rows = (array) $wpdb->get_results(
            "SELECT term_id,merchant_key,COUNT(*) AS n
             FROM {$table}
             WHERE active=1 AND merchant_key<>''
             GROUP BY term_id,merchant_key
             ORDER BY term_id ASC,n DESC",
            ARRAY_A
        );
        $totals = array();
        $max = array();
        foreach ($rows as $row) {
            $term_id = absint($row['term_id'] ?? 0);
            $n = absint($row['n'] ?? 0);
            if (!$term_id || !$n) continue;
            $totals[$term_id] = ($totals[$term_id] ?? 0) + $n;
            $max[$term_id] = max($max[$term_id] ?? 0, $n);
        }
        $shares = array();
        foreach ($totals as $term_id => $total) {
            $shares[$term_id] = $total > 0 ? (($max[$term_id] ?? 0) / $total) : 0.0;
        }
        return $shares;
    }

    /** @return array */
    private static function merchant_ranking($limit = 30) {
        global $wpdb;
        $limit = max(1, min(100, absint($limit)));
        $table = SEO_Ojeador_DB::table('category_results');
        return (array) $wpdb->get_results(
            "SELECT merchant,merchant_key,COUNT(*) AS appearances,COUNT(DISTINCT term_id) AS categories,
                    SUM(CASE WHEN price IS NOT NULL AND price>0 THEN 1 ELSE 0 END) AS rows_with_price,
                    SUM(CASE WHEN old_price>price AND price>0 THEN 1 ELSE 0 END) AS discounted_rows
             FROM {$table}
             WHERE active=1 AND merchant_key<>''
             GROUP BY merchant_key,merchant
             ORDER BY appearances DESC,categories DESC
             LIMIT {$limit}",
            ARRAY_A
        );
    }

    private static function empty_market_row() {
        return array(
            'result_count'=>0,'merchant_count'=>0,'merchant_rows'=>0,'price_count'=>0,
            'price_min'=>null,'price_avg'=>null,'price_max'=>null,'currency'=>'EUR','discounted_count'=>0,
            'avg_rating'=>null,'rated_count'=>0,'total_reviews'=>0,
        );
    }

    private static function nullable_float($value) {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (float) $value;
    }

    /** @return array */
    private static function thresholds($rows) {
        $valid = array_values(array_filter($rows, static function ($row) {
            return !empty($row['ojeador_eligible'])
                && (string) ($row['market_status'] ?? '') === 'ok'
                && absint($row['market_result_count'] ?? 0) > 0;
        }));

        return array(
            'catalog_products' => self::quartiles(array_column($valid, 'woo_product_count'), false),
            'merchants' => self::quartiles(array_column($valid, 'merchant_count'), false),
            'impressions_positive' => self::quartiles(array_column($valid, 'analista_impressions'), true),
            'reviews_positive' => self::quartiles(array_column($valid, 'total_reviews'), true),
            'promo_share' => self::quartiles(array_column($valid, 'discount_share_pct'), false),
            'merchant_concentration' => self::quartiles(array_column($valid, 'top_merchant_share_pct'), false),
            'market_result_count' => self::quartiles(array_column($valid, 'market_result_count'), false),
        );
    }

    /** @return array */
    private static function quartiles($values, $positive_only = false) {
        $clean = array();
        foreach ((array) $values as $value) {
            if ($value === null || $value === '' || !is_numeric($value)) continue;
            $v = (float) $value;
            if ($positive_only && $v <= 0) continue;
            if (!$positive_only && $v < 0) continue;
            $clean[] = $v;
        }
        return array(
            'n' => count($clean),
            'p25' => self::percentile($clean, 0.25),
            'p50' => self::percentile($clean, 0.50),
            'p75' => self::percentile($clean, 0.75),
            'p90' => self::percentile($clean, 0.90),
        );
    }

    private static function percentile($values, $p) {
        $clean = array_values(array_map('floatval', (array) $values));
        if (!$clean) return 0.0;
        sort($clean, SORT_NUMERIC);
        $index = ($p * (count($clean) - 1));
        $lo = (int) floor($index);
        $hi = (int) ceil($index);
        if ($lo === $hi) return (float) $clean[$lo];
        $weight = $index - $lo;
        return ((float) $clean[$lo] * (1 - $weight)) + ((float) $clean[$hi] * $weight);
    }

    private static function piecewise_index($value, $q) {
        $value = max(0.0, (float) $value);
        if ($value <= 0) return 0.0;
        $p25 = max(0.0, (float) ($q['p25'] ?? 0));
        $p50 = max($p25, (float) ($q['p50'] ?? $p25));
        $p75 = max($p50, (float) ($q['p75'] ?? $p50));
        $p90 = max($p75, (float) ($q['p90'] ?? $p75));

        if ($p25 <= 0) {
            if ($p90 <= 0) return 0.0;
            return min(100.0, ($value / $p90) * 100.0);
        }
        if ($value <= $p25) return min(25.0, ($value / $p25) * 25.0);
        if ($value <= $p50 && $p50 > $p25) return 25.0 + (($value-$p25)/($p50-$p25))*25.0;
        if ($value <= $p75 && $p75 > $p50) return 50.0 + (($value-$p50)/($p75-$p50))*25.0;
        if ($value <= $p90 && $p90 > $p75) return 75.0 + (($value-$p75)/($p90-$p75))*25.0;
        return 100.0;
    }

    private static function attach_indices(&$row, $t) {
        $valid = !empty($row['ojeador_eligible'])
            && (string) ($row['market_status'] ?? '') === 'ok'
            && absint($row['market_result_count'] ?? 0) > 0;

        if (!$valid) {
            foreach (array('catalog_depth_index','catalog_gap_index','competition_index','visibility_index','review_index','promo_index','concentration_index','data_quality_index','opportunity_index') as $key) {
                $row[$key] = null;
            }
            return;
        }

        $catalog_depth = self::piecewise_index((float) ($row['woo_product_count'] ?? 0), (array) ($t['catalog_products'] ?? array()));
        $competition = self::piecewise_index((float) ($row['merchant_count'] ?? 0), (array) ($t['merchants'] ?? array()));
        $visibility = self::piecewise_index((float) ($row['analista_impressions'] ?? 0), (array) ($t['impressions_positive'] ?? array()));
        $reviews = self::piecewise_index((float) ($row['total_reviews'] ?? 0), (array) ($t['reviews_positive'] ?? array()));
        $promo = self::piecewise_index((float) ($row['discount_share_pct'] ?? 0), (array) ($t['promo_share'] ?? array()));
        $concentration = self::piecewise_index((float) ($row['top_merchant_share_pct'] ?? 0), (array) ($t['merchant_concentration'] ?? array()));

        $price_quality = (float) ($row['price_data_ratio_pct'] ?? 0);
        $merchant_quality = (float) ($row['merchant_data_ratio_pct'] ?? 0);
        $rating_quality = (float) ($row['rating_data_ratio_pct'] ?? 0);
        $data_quality = min(100.0, max(0.0, ($price_quality * 0.4) + ($merchant_quality * 0.4) + ($rating_quality * 0.2)));
        $gap = 100.0 - $catalog_depth;
        $opportunity = ($gap * 0.35) + ($competition * 0.25) + ($visibility * 0.25) + ($reviews * 0.15);

        $row['catalog_depth_index'] = round($catalog_depth, 1);
        $row['catalog_gap_index'] = round($gap, 1);
        $row['competition_index'] = round($competition, 1);
        $row['visibility_index'] = round($visibility, 1);
        $row['review_index'] = round($reviews, 1);
        $row['promo_index'] = round($promo, 1);
        $row['concentration_index'] = round($concentration, 1);
        $row['data_quality_index'] = round($data_quality, 1);
        $row['opportunity_index'] = round($opportunity, 1);
    }

    private static function attach_recommendations(&$row, $t) {
        $items = array();
        $eligible = !empty($row['ojeador_eligible']);
        $status = sanitize_key((string) ($row['market_status'] ?? ''));

        if (!$eligible) {
            $row['analysis_recommendations'] = array();
            self::set_primary($row, 'Fuera de cobertura', 'No actuar desde Ojeador.', 'La categoría no tiene Google Esquema aprobado con shopping_query.', 0, 'muted');
            return;
        }
        if (!empty($row['needs_trusted_scan'])) {
            $row['analysis_recommendations'] = array();
            self::set_primary($row, 'Pendiente de mercado', 'Completar la primera consulta aprobada.', 'Todavía no existe una instantánea válida.', 0, 'warning');
            return;
        }
        if ($status === 'error') {
            $row['analysis_recommendations'] = array();
            self::set_primary($row, 'Error de captura', 'Revisar la consulta o la incidencia técnica.', (string) ($row['last_error'] ?? ''), 0, 'danger');
            return;
        }
        if ($status === 'no_results') {
            $row['analysis_recommendations'] = array();
            self::set_primary($row, 'Sin resultados', 'Revisar shopping_query antes de concluir que no existe mercado.', 'La consulta aprobada no devolvió resultados.', 0, 'warning');
            return;
        }
        if ($status !== 'ok' || absint($row['market_result_count'] ?? 0) < 1) {
            $row['analysis_recommendations'] = array();
            self::set_primary($row, 'Sin datos utilizables', 'Completar la consulta.', 'No existe una instantánea de mercado utilizable.', 0, 'warning');
            return;
        }

        $gap = (float) ($row['catalog_gap_index'] ?? 0);
        $competition = (float) ($row['competition_index'] ?? 0);
        $visibility = (float) ($row['visibility_index'] ?? 0);
        $reviews = (float) ($row['review_index'] ?? 0);
        $promo = (float) ($row['promo_index'] ?? 0);
        $concentration = (float) ($row['top_merchant_share_pct'] ?? 0);
        $quality = (float) ($row['data_quality_index'] ?? 0);
        $opp = (float) ($row['opportunity_index'] ?? 0);
        $products = absint($row['woo_product_count'] ?? 0);
        $merchants = absint($row['merchant_count'] ?? 0);
        $impressions = (float) ($row['analista_impressions'] ?? 0);

        if ($quality < 75) {
            $items[] = self::rec('REVISAR_DATOS', 'Revisar calidad de datos', 'No tomar decisiones de precio todavía.', 'La cobertura de precio/comercio/rating es incompleta.', 55, 'warning');
        }
        if ($gap >= 65 && $competition >= 65 && ($visibility >= 25 || $reviews >= 75)) {
            $items[] = self::rec(
                'AMPLIAR_SURTIDO',
                'Posible hueco de catálogo',
                'Revisar si conviene ampliar o reorganizar el surtido.',
                sprintf('%d productos propios, %d comercios observados y %.0f impresiones en Analista.', $products, $merchants, $impressions),
                max(70, min(99, (int) round($opp))),
                'opportunity'
            );
        }
        if ($visibility >= 75 && $gap >= 45) {
            $items[] = self::rec(
                'PRIORIZAR_CATEGORIA',
                'Visibilidad interna con catálogo mejorable',
                'Priorizar contenido, enlaces y revisión de surtido de esta categoría.',
                sprintf('Analista registra %.0f impresiones y la profundidad relativa del catálogo es baja/media.', $impressions),
                max(72, min(96, (int) round(($visibility + $gap) / 2))),
                'opportunity'
            );
        }
        if ($promo >= 75 && (float) ($row['price_data_ratio_pct'] ?? 0) >= 90) {
            $items[] = self::rec(
                'REVISAR_PROMOCIONES',
                'Presión promocional alta',
                'Comparar productos equivalentes antes de ajustar precio o promoción.',
                sprintf('El %.1f%% de los resultados con precio muestran precio anterior superior.', (float) ($row['discount_share_pct'] ?? 0)),
                68,
                'info'
            );
        }
        if ($concentration >= max(40.0, (float) (($t['merchant_concentration']['p90'] ?? 40)))) {
            $items[] = self::rec(
                'REVISAR_CONCENTRACION',
                'Oferta concentrada',
                'Identificar al comercio dominante y comprobar si sesga la referencia de mercado.',
                sprintf('El comercio más visible concentra aproximadamente el %.1f%% de la muestra con comercio identificado.', $concentration),
                62,
                'info'
            );
        }

        usort($items, static function ($a, $b) {
            return absint($b['priority'] ?? 0) <=> absint($a['priority'] ?? 0);
        });
        $row['analysis_recommendations'] = $items;

        if ($items) {
            $first = $items[0];
            self::set_primary($row, $first['signal'], $first['action'], $first['reason'], $first['priority'], $first['tone']);
        } else {
            self::set_primary($row, 'Seguimiento', 'No requiere una acción comercial prioritaria.', 'La instantánea actual no activa ninguna regla de actuación.', 20, 'ok');
        }
    }

    private static function rec($code, $signal, $action, $reason, $priority, $tone) {
        return array(
            'code' => sanitize_key($code),
            'signal' => (string) $signal,
            'action' => (string) $action,
            'reason' => (string) $reason,
            'priority' => absint($priority),
            'tone' => sanitize_key($tone),
        );
    }

    private static function set_primary(&$row, $signal, $action, $reason, $priority, $tone) {
        $row['analysis_signal'] = (string) $signal;
        $row['analysis_action'] = (string) $action;
        $row['analysis_reason'] = (string) $reason;
        $row['analysis_priority'] = absint($priority);
        $row['analysis_tone'] = sanitize_key($tone);
    }

    /** @return array */
    private static function commercial_recommendations($rows, $limit) {
        $items = array();
        foreach ($rows as $row) {
            foreach ((array) ($row['analysis_recommendations'] ?? array()) as $rec) {
                $items[] = array_merge(array(
                    'term_id' => absint($row['term_id'] ?? 0),
                    'category_name' => (string) (($row['category_name'] ?? '') ?: ($row['woo_category'] ?? '')),
                    'query_text' => (string) (($row['query_text'] ?? '') ?: ($row['trusted_query'] ?? '')),
                    'woo_product_count' => absint($row['woo_product_count'] ?? 0),
                    'merchant_count' => absint($row['merchant_count'] ?? 0),
                    'analista_impressions' => (float) ($row['analista_impressions'] ?? 0),
                    'analista_clicks' => (float) ($row['analista_clicks'] ?? 0),
                    'discount_share_pct' => self::nullable_float($row['discount_share_pct'] ?? null),
                    'top_merchant_share_pct' => self::nullable_float($row['top_merchant_share_pct'] ?? null),
                    'opportunity_index' => self::nullable_float($row['opportunity_index'] ?? null),
                    'competition_index' => self::nullable_float($row['competition_index'] ?? null),
                    'catalog_gap_index' => self::nullable_float($row['catalog_gap_index'] ?? null),
                    'visibility_index' => self::nullable_float($row['visibility_index'] ?? null),
                ), $rec);
            }
        }
        usort($items, static function ($a, $b) {
            $p = absint($b['priority'] ?? 0) <=> absint($a['priority'] ?? 0);
            if ($p !== 0) return $p;
            $o = (float) ($b['opportunity_index'] ?? 0) <=> (float) ($a['opportunity_index'] ?? 0);
            if ($o !== 0) return $o;
            return strcasecmp((string) ($a['category_name'] ?? ''), (string) ($b['category_name'] ?? ''));
        });
        return array_slice($items, 0, max(1, min(500, absint($limit))));
    }

    /** @return array */
    private static function summary($rows, $thresholds) {
        $out = array(
            'categories_total' => count($rows),
            'categories_eligible' => 0,
            'categories_with_market' => 0,
            'categories_pending_first_scan' => 0,
            'categories_error' => 0,
            'categories_no_results' => 0,
            'valid_market_coverage_pct' => 0,
            'active_results' => 0,
            'unique_merchants' => 0,
            'median_merchants_per_category' => round((float) (($thresholds['merchants']['p50'] ?? 0)), 1),
            'catalog_gap_candidates' => 0,
            'high_visibility_categories' => 0,
            'high_promo_categories' => 0,
            'concentrated_categories' => 0,
            'avg_price_data_coverage_pct' => 0,
            'avg_merchant_data_coverage_pct' => 0,
        );

        $price_ratios = array();
        $merchant_ratios = array();
        $catalog_p25 = (float) (($thresholds['catalog_products']['p25'] ?? 0));
        $merchants_p75 = (float) (($thresholds['merchants']['p75'] ?? 0));
        $impressions_p75 = (float) (($thresholds['impressions_positive']['p75'] ?? 0));
        $promo_p75 = (float) (($thresholds['promo_share']['p75'] ?? 0));
        $concentration_p90 = max(40.0, (float) (($thresholds['merchant_concentration']['p90'] ?? 40)));

        foreach ($rows as $row) {
            if (empty($row['ojeador_eligible'])) continue;
            $out['categories_eligible']++;
            $status = sanitize_key((string) ($row['market_status'] ?? ''));
            if (!empty($row['needs_trusted_scan'])) $out['categories_pending_first_scan']++;
            if ($status === 'error') $out['categories_error']++;
            if ($status === 'no_results') $out['categories_no_results']++;
            if ($status === 'ok' && absint($row['market_result_count'] ?? 0) > 0) {
                $out['categories_with_market']++;
                $out['active_results'] += absint($row['market_result_count'] ?? 0);
                if ($row['price_data_ratio_pct'] !== null) $price_ratios[] = (float) $row['price_data_ratio_pct'];
                if ($row['merchant_data_ratio_pct'] !== null) $merchant_ratios[] = (float) $row['merchant_data_ratio_pct'];
                if ((float) ($row['woo_product_count'] ?? 0) <= $catalog_p25 && (float) ($row['merchant_count'] ?? 0) >= $merchants_p75) $out['catalog_gap_candidates']++;
                if ($impressions_p75 > 0 && (float) ($row['analista_impressions'] ?? 0) >= $impressions_p75) $out['high_visibility_categories']++;
                if ((float) ($row['discount_share_pct'] ?? 0) >= $promo_p75 && absint($row['price_count'] ?? 0) >= 20) $out['high_promo_categories']++;
                if ((float) ($row['top_merchant_share_pct'] ?? 0) >= $concentration_p90) $out['concentrated_categories']++;
            }
        }

        if ($out['categories_eligible'] > 0) {
            $out['valid_market_coverage_pct'] = round(($out['categories_with_market'] / $out['categories_eligible']) * 100, 1);
        }
        $out['avg_price_data_coverage_pct'] = $price_ratios ? round(array_sum($price_ratios) / count($price_ratios), 1) : 0;
        $out['avg_merchant_data_coverage_pct'] = $merchant_ratios ? round(array_sum($merchant_ratios) / count($merchant_ratios), 1) : 0;

        global $wpdb;
        $table = SEO_Ojeador_DB::table('category_results');
        $out['unique_merchants'] = absint($wpdb->get_var("SELECT COUNT(DISTINCT merchant_key) FROM {$table} WHERE active=1 AND merchant_key<>''"));

        $usage = SEO_Ojeador_Shopping::usage_month();
        $out['provider_used'] = absint($usage['used'] ?? 0);
        $out['provider_limit'] = absint($usage['limit'] ?? 0);
        $out['provider_remaining'] = absint($usage['remaining'] ?? 0);
        $out['provider_source'] = (string) ($usage['source'] ?? '');
        $out['provider_renewal_date'] = (string) (($usage['provider']['plan_renewal_date'] ?? '') ?: '');

        return $out;
    }

    /** @return array */
    private static function operational_recommendations($summary) {
        $out = array();
        $remaining = absint($summary['provider_remaining'] ?? 0);
        $limit = absint($summary['provider_limit'] ?? 0);
        $pending = absint($summary['categories_pending_first_scan'] ?? 0);
        $errors = absint($summary['categories_error'] ?? 0);

        if ($limit > 0 && $remaining < 1 && $pending > 0) {
            $out[] = array(
                'code' => 'BUDGET_EXHAUSTED',
                'priority' => 100,
                'signal' => 'Límite de consultas agotado',
                'action' => 'Esperar la renovación o ampliar el plan antes de intentar cerrar la cobertura pendiente.',
                'reason' => sprintf('Quedan %d categorías sin primera instantánea válida y el proveedor informa de 0 consultas disponibles.', $pending),
                'tone' => 'danger',
            );
        }
        if ($errors > 0) {
            $out[] = array(
                'code' => 'FIX_ERRORS',
                'priority' => 90,
                'signal' => 'Consultas con error',
                'action' => 'Revisar primero los errores 401 y después las queries que devuelven error de resultados.',
                'reason' => sprintf('%d categorías tienen la última consulta en estado de error.', $errors),
                'tone' => 'warning',
            );
        }
        if ($pending > 0 && $remaining > 0) {
            $out[] = array(
                'code' => 'COMPLETE_COVERAGE',
                'priority' => 80,
                'signal' => 'Cobertura inicial incompleta',
                'action' => 'Usar las consultas disponibles en categorías todavía no observadas antes de refrescar categorías ya cubiertas.',
                'reason' => sprintf('%d categorías aptas siguen sin una primera instantánea válida.', $pending),
                'tone' => 'info',
            );
        }
        return $out;
    }
}
