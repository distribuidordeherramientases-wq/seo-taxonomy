<?php
/**
 * Ojeador market analysis layer.
 *
 * Converts the raw Google Shopping category snapshots into descriptive market
 * metrics and review actions. It never changes prices, categories or products.
 * The signals are decision support, not automatic commercial decisions.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.7.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Analysis {

    /**
     * Build the complete analysis payload used by the admin screen and exports.
     *
     * @param int $action_limit Maximum number of priority actions returned.
     * @return array
     */
    public static function dashboard($action_limit = 30) {
        $rows = SEO_Ojeador_DB::list_market_categories(array('limit'=>2000, 'search'=>''));
        $market = self::market_aggregates();
        $merchant_shares = self::top_merchant_shares();

        $analysed = array();
        foreach ($rows as $row) {
            $term_id = absint($row['term_id'] ?? 0);
            $agg = isset($market[$term_id]) ? $market[$term_id] : self::empty_market_row();
            $total_results = absint($agg['result_count'] ?? 0);
            $top_share = isset($merchant_shares[$term_id]) ? (float) $merchant_shares[$term_id] : 0.0;

            $price_min = self::nullable_float($agg['price_min'] ?? null);
            $price_avg = self::nullable_float($agg['price_avg'] ?? null);
            $price_max = self::nullable_float($agg['price_max'] ?? null);
            $price_count = absint($agg['price_count'] ?? 0);
            $discounted = absint($agg['discounted_count'] ?? 0);

            $price_spread_pct = null;
            if ($price_avg !== null && $price_avg > 0 && $price_min !== null && $price_max !== null) {
                $price_spread_pct = (($price_max - $price_min) / $price_avg) * 100;
            }

            $analysed[] = array_merge($row, array(
                'market_result_count' => $total_results,
                'merchant_count' => absint($agg['merchant_count'] ?? 0),
                'price_count' => $price_count,
                'price_min' => $price_min,
                'currency' => (string) ($agg['currency'] ?? 'EUR'),
                'price_avg' => $price_avg,
                'price_max' => $price_max,
                'price_spread_pct' => $price_spread_pct,
                'discounted_count' => $discounted,
                'discount_share_pct' => $price_count > 0 ? ($discounted / $price_count) * 100 : null,
                'avg_rating' => self::nullable_float($agg['avg_rating'] ?? null),
                'rated_count' => absint($agg['rated_count'] ?? 0),
                'total_reviews' => absint($agg['total_reviews'] ?? 0),
                'top_merchant_share_pct' => $top_share * 100,
                'merchant_data_ratio_pct' => $total_results > 0 ? (absint($agg['merchant_rows'] ?? 0) / $total_results) * 100 : null,
                'price_data_ratio_pct' => $total_results > 0 ? ($price_count / $total_results) * 100 : null,
            ));
        }

        $thresholds = self::thresholds($analysed);
        foreach ($analysed as &$row) {
            $decision = self::decision_for_row($row, $thresholds);
            $row['analysis_signal'] = $decision['signal'];
            $row['analysis_action'] = $decision['action'];
            $row['analysis_reason'] = $decision['reason'];
            $row['analysis_priority'] = $decision['priority'];
            $row['analysis_tone'] = $decision['tone'];
        }
        unset($row);

        $actions = array_values(array_filter($analysed, static function ($row) {
            return absint($row['analysis_priority'] ?? 0) >= 50;
        }));
        usort($actions, static function ($a, $b) {
            $p = absint($b['analysis_priority'] ?? 0) <=> absint($a['analysis_priority'] ?? 0);
            if ($p !== 0) return $p;
            $c = (float) ($b['analista_clicks'] ?? 0) <=> (float) ($a['analista_clicks'] ?? 0);
            if ($c !== 0) return $c;
            return (float) ($b['analista_impressions'] ?? 0) <=> (float) ($a['analista_impressions'] ?? 0);
        });
        $actions = array_slice($actions, 0, max(1, min(200, absint($action_limit))));

        $summary = self::summary($analysed);

        return array(
            'generated_at_utc' => gmdate('c'),
            'summary' => $summary,
            'thresholds' => $thresholds,
            'actions' => $actions,
            'categories' => $analysed,
            'merchants' => self::merchant_ranking(20),
            'notes' => array(
                'Google Shopping devuelve una muestra de resultados para la consulta, no el tamaño total del mercado.',
                'Los clics e impresiones proceden de Analista (28 días) y se usan como señal interna de interés, no como demanda de mercado absoluta.',
                'Las acciones son reglas de revisión. Ojeador no modifica precios, catálogo ni taxonomía automáticamente.',
            ),
        );
    }

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

    private static function merchant_ranking($limit = 20) {
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

    private static function thresholds($rows) {
        $eligible_scanned = array_values(array_filter($rows, static function ($row) {
            return !empty($row['ojeador_eligible']) && empty($row['needs_trusted_scan']) && (string) ($row['market_status'] ?? '') === 'ok';
        }));
        return array(
            'clicks_p75' => self::percentile(array_column($eligible_scanned, 'analista_clicks'), 0.75),
            'impressions_p75' => self::percentile(array_column($eligible_scanned, 'analista_impressions'), 0.75),
            'catalog_products_p25' => self::percentile(array_column($eligible_scanned, 'woo_product_count'), 0.25),
            'market_results_p50' => self::percentile(array_column($eligible_scanned, 'market_result_count'), 0.50),
        );
    }

    private static function percentile($values, $p) {
        $clean = array_values(array_filter(array_map('floatval', (array) $values), static function ($v) { return $v >= 0; }));
        if (!$clean) return 0.0;
        sort($clean, SORT_NUMERIC);
        $index = ($p * (count($clean) - 1));
        $lo = (int) floor($index);
        $hi = (int) ceil($index);
        if ($lo === $hi) return (float) $clean[$lo];
        $weight = $index - $lo;
        return ((float) $clean[$lo] * (1 - $weight)) + ((float) $clean[$hi] * $weight);
    }

    private static function decision_for_row($row, $t) {
        $eligible = !empty($row['ojeador_eligible']);
        if (!$eligible) {
            return self::decision('Fuera de cobertura', 'Completar Google Esquema / shopping_query sólo si la categoría debe entrar en Ojeador.', 'La categoría no cumple todavía los criterios de consulta.', 10, 'muted');
        }
        if (!empty($row['needs_trusted_scan'])) {
            return self::decision('Sin instantánea válida', 'Consultar Google Shopping.', 'Todavía no existe una primera instantánea para la shopping_query aprobada.', 90, 'warning');
        }
        $status = sanitize_key((string) ($row['market_status'] ?? ''));
        if ($status === 'error') {
            return self::decision('Error de captura', 'Revisar el log y corregir la consulta o la incidencia técnica.', (string) ($row['last_error'] ?? 'La última consulta terminó con error.'), 100, 'danger');
        }
        if ($status === 'no_results') {
            return self::decision('Sin resultados', 'Revisar la shopping_query antes de interpretar ausencia de mercado.', 'Google Shopping no devolvió resultados para la consulta aprobada.', 95, 'warning');
        }
        if ($status !== 'ok') {
            return self::decision('Pendiente', 'Completar la consulta.', 'No hay una instantánea de mercado utilizable.', 85, 'warning');
        }

        $clicks = (float) ($row['analista_clicks'] ?? 0);
        $impressions = (float) ($row['analista_impressions'] ?? 0);
        $products = absint($row['woo_product_count'] ?? 0);
        $results = absint($row['market_result_count'] ?? 0);
        $price_count = absint($row['price_count'] ?? 0);
        $spread = self::nullable_float($row['price_spread_pct'] ?? null);
        $promo = self::nullable_float($row['discount_share_pct'] ?? null);
        $concentration = self::nullable_float($row['top_merchant_share_pct'] ?? null);

        $high_interest = ($clicks > 0 && $clicks >= (float) ($t['clicks_p75'] ?? 0))
            || ($impressions > 0 && $impressions >= (float) ($t['impressions_p75'] ?? 0));
        $low_catalog = $products <= max(1, (float) ($t['catalog_products_p25'] ?? 0));
        $enough_market = $results >= max(5, (float) ($t['market_results_p50'] ?? 0));

        if ($high_interest && $low_catalog && $enough_market) {
            return self::decision(
                'Interés interno + surtido reducido',
                'Revisar si conviene ampliar o reorganizar el surtido de esta categoría.',
                sprintf('Analista: %.0f clics / %.0f impresiones; catálogo: %d productos; Google Shopping: %d resultados.', $clicks, $impressions, $products, $results),
                85,
                'opportunity'
            );
        }
        if ($price_count >= 5 && $spread !== null && $spread >= 60) {
            return self::decision(
                'Alta dispersión de precios',
                'Separar gamas y revisar posicionamiento antes de comparar precios de productos concretos.',
                sprintf('La amplitud min–max equivale aproximadamente al %.0f%% del precio medio observado.', $spread),
                75,
                'info'
            );
        }
        if ($results >= 10 && $concentration !== null && $concentration >= 40) {
            return self::decision(
                'Oferta concentrada',
                'Revisar qué vendedor domina la muestra y si condiciona la referencia de mercado.',
                sprintf('El vendedor más visible concentra aproximadamente el %.0f%% de los resultados con comercio identificado.', $concentration),
                70,
                'info'
            );
        }
        if ($price_count >= 5 && $promo !== null && $promo >= 30) {
            return self::decision(
                'Presión promocional',
                'Revisar descuentos y estrategia de precio en los productos relevantes.',
                sprintf('Aproximadamente el %.0f%% de los resultados con precio muestran precio anterior superior.', $promo),
                65,
                'info'
            );
        }

        $price_ratio = self::nullable_float($row['price_data_ratio_pct'] ?? null);
        if ($results >= 5 && $price_ratio !== null && $price_ratio < 50) {
            return self::decision(
                'Datos de precio parciales',
                'Usar esta categoría para oferta/competencia, pero no extraer conclusiones fuertes de precio.',
                sprintf('Sólo el %.0f%% de los resultados guardados exponen un precio utilizable.', $price_ratio),
                55,
                'muted'
            );
        }

        return self::decision('Mercado observado', 'Mantener seguimiento y usar el detalle cuando se tome una decisión de categoría.', 'No se activa ninguna regla prioritaria con la instantánea actual.', 20, 'ok');
    }

    private static function decision($signal, $action, $reason, $priority, $tone) {
        return array(
            'signal' => $signal,
            'action' => $action,
            'reason' => $reason,
            'priority' => absint($priority),
            'tone' => sanitize_key($tone),
        );
    }

    private static function summary($rows) {
        $out = array(
            'categories_total' => count($rows),
            'categories_with_market' => 0,
            'active_results' => 0,
            'unique_merchants' => 0,
            'categories_no_results' => 0,
            'categories_error' => 0,
            'categories_priority_action' => 0,
            'categories_high_interest_low_catalog' => 0,
            'categories_price_dispersion' => 0,
            'categories_promo_pressure' => 0,
        );
        $merchant_keys = array();
        foreach ($rows as $row) {
            $status = sanitize_key((string) ($row['market_status'] ?? ''));
            if ($status === 'ok' && absint($row['market_result_count'] ?? 0) > 0) $out['categories_with_market']++;
            if ($status === 'no_results') $out['categories_no_results']++;
            if ($status === 'error') $out['categories_error']++;
            $out['active_results'] += absint($row['market_result_count'] ?? 0);
            if (absint($row['analysis_priority'] ?? 0) >= 50) $out['categories_priority_action']++;
            $signal = (string) ($row['analysis_signal'] ?? '');
            if ($signal === 'Interés interno + surtido reducido') $out['categories_high_interest_low_catalog']++;
            if ($signal === 'Alta dispersión de precios') $out['categories_price_dispersion']++;
            if ($signal === 'Presión promocional') $out['categories_promo_pressure']++;
        }

        global $wpdb;
        $table = SEO_Ojeador_DB::table('category_results');
        $out['unique_merchants'] = absint($wpdb->get_var("SELECT COUNT(DISTINCT merchant_key) FROM {$table} WHERE active=1 AND merchant_key<>''"));
        return $out;
    }
}
