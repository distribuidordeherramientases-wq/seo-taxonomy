<?php
/**
 * Nucleo de datos del Analista.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_ANALISTA_OPTION_SETTINGS')) {
    define('SEO_ANALISTA_OPTION_SETTINGS', 'seo_analista_settings');
}

if (!function_exists('seo_analista_days')) {
    function seo_analista_days($days) {
        $days = absint($days);
        return in_array($days, array(28, 60, 90), true) ? $days : 28;
    }
}

if (!function_exists('seo_analista_admin_url')) {
    function seo_analista_admin_url(array $args = array()) {
        $url = add_query_arg(
            array(
                'page' => 'seo-reports',
                'tab'  => 'analista',
            ),
            admin_url('admin.php')
        );
        return $args ? add_query_arg($args, $url) : $url;
    }
}

if (!function_exists('seo_analista_normalize_text')) {
    function seo_analista_normalize_text($text) {
        $text = wp_strip_all_tags((string) $text);
        $text = remove_accents($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', (string) $text));
    }
}


if (!function_exists('seo_analista_clean_query')) {
    function seo_analista_clean_query($query) {
        $query = sanitize_text_field((string) $query);
        if ($query === '') return '';

        // Algunos datos historicos incorporan metadatos internos despues de
        // ",," (por ejemplo i|c,-,,,-...). Esa cola no forma parte de la
        // consulta real y no debe contaminar rankings, intenciones ni clusters.
        $query = preg_replace('/,{2,}.*$/u', '', $query);
        $query = preg_replace('/\s+/u', ' ', (string) $query);
        return trim((string) $query, " \t\n\r\0\x0B,;|-");
    }
}

if (!function_exists('seo_analista_merge_clean_query_rows')) {
    function seo_analista_merge_clean_query_rows(array $rows) {
        $merged = array();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $query = seo_analista_clean_query($row['query_text'] ?? '');
            $key = seo_analista_normalize_text($query);
            if ($query === '' || $key === '') continue;

            $impressions = max(0.0, (float) ($row['impressions'] ?? 0));
            $clicks = max(0.0, (float) ($row['clicks'] ?? 0));
            $position = max(0.0, (float) ($row['position'] ?? 0));
            $pages = max(0, (int) ($row['pages'] ?? 0));

            if (!isset($merged[$key])) {
                $merged[$key] = array(
                    'query_hash' => hash('sha256', $key),
                    'query_text' => $query,
                    'clicks' => 0.0,
                    'impressions' => 0.0,
                    'pages' => 0,
                    '_position_weight' => 0.0,
                );
            }
            $merged[$key]['clicks'] += $clicks;
            $merged[$key]['impressions'] += $impressions;
            $merged[$key]['pages'] = max($merged[$key]['pages'], $pages);
            if ($position > 0 && $impressions > 0) {
                $merged[$key]['_position_weight'] += $position * $impressions;
            }
        }

        foreach ($merged as &$row) {
            $impressions = max(0.0, (float) $row['impressions']);
            $row['ctr'] = $impressions > 0 ? ((float) $row['clicks'] / $impressions) : 0.0;
            $row['position'] = $impressions > 0 ? ((float) $row['_position_weight'] / $impressions) : 0.0;
            unset($row['_position_weight']);
        }
        unset($row);

        $merged = array_values($merged);
        usort($merged, static function($a, $b) {
            if ((float) $a['impressions'] === (float) $b['impressions']) {
                return (float) $b['clicks'] <=> (float) $a['clicks'];
            }
            return (float) $b['impressions'] <=> (float) $a['impressions'];
        });
        return $merged;
    }
}

if (!function_exists('seo_analista_query_is_actionable')) {
    function seo_analista_query_is_actionable($query) {
        $query = seo_analista_clean_query($query);
        $normalized = seo_analista_normalize_text($query);
        if ($normalized === '') return false;
        if (preg_match('/^(site|cache|related|info|inurl|intitle|allintitle|allinurl)\s+/', $normalized)) return false;
        if (strpos($normalized, 'site www distribuidordeherramientas es') === 0) return false;
        if (preg_match('/\b(site|cache|related|info|inurl|intitle):/i', $query)) return false;
        return true;
    }
}

if (!function_exists('seo_analista_get_settings')) {
    function seo_analista_get_settings() {
        $defaults = array(
            'competitors' => array(
                'herramientastalavera.es',
                'maquinasyherramientasonline.com',
                'suinbasa.com',
                'brikum.com',
                'leroymerlin.es',
            ),
            'tracked_keywords' => array(),
        );

        $settings = get_option(SEO_ANALISTA_OPTION_SETTINGS, array());
        $settings = wp_parse_args(is_array($settings) ? $settings : array(), $defaults);
        $settings['competitors'] = seo_analista_sanitize_domains((array) $settings['competitors']);
        $settings['tracked_keywords'] = seo_analista_sanitize_keywords((array) $settings['tracked_keywords']);
        return $settings;
    }
}

if (!function_exists('seo_analista_sanitize_domains')) {
    function seo_analista_sanitize_domains(array $domains) {
        $clean = array();
        $own_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $own_host = strtolower((string) $own_host);
        $own_host = preg_replace('/^www\./', '', $own_host);

        foreach ($domains as $domain) {
            $domain = trim(strtolower((string) $domain));
            if ($domain === '') continue;
            $domain = preg_replace('#^https?://#', '', $domain);
            $domain = preg_replace('#/.*$#', '', $domain);
            $domain = preg_replace('/^www\./', '', $domain);
            $domain = preg_replace('/[^a-z0-9.-]/', '', $domain);
            if ($domain === '' || strpos($domain, '.') === false || $domain === $own_host) continue;
            $clean[] = $domain;
        }

        return array_slice(array_values(array_unique($clean)), 0, 10);
    }
}

if (!function_exists('seo_analista_sanitize_keywords')) {
    function seo_analista_sanitize_keywords(array $keywords) {
        $clean = array();
        $seen = array();
        foreach ($keywords as $keyword) {
            $keyword = seo_analista_clean_query($keyword);
            $key = seo_analista_normalize_text($keyword);
            if ($keyword === '' || $key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $clean[] = $keyword;
            if (count($clean) >= 50) break;
        }
        return $clean;
    }
}

if (!function_exists('seo_analista_save_settings_handler')) {
    function seo_analista_save_settings_handler() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para configurar Analista.', 'seo-system'));
        }

        check_admin_referer('seo_analista_save_settings', 'seo_analista_nonce');

        $raw = isset($_POST['competitors']) ? (string) wp_unslash($_POST['competitors']) : '';
        $domains = preg_split('/[\r\n,;]+/', $raw);
        $domains = seo_analista_sanitize_domains(is_array($domains) ? $domains : array());

        $raw_keywords = isset($_POST['tracked_keywords']) ? (string) wp_unslash($_POST['tracked_keywords']) : '';
        $keywords = preg_split('/[\r\n]+/', $raw_keywords);
        $keywords = seo_analista_sanitize_keywords(is_array($keywords) ? $keywords : array());

        update_option(
            SEO_ANALISTA_OPTION_SETTINGS,
            array(
                'competitors' => $domains,
                'tracked_keywords' => $keywords,
            ),
            false
        );

        wp_safe_redirect(seo_analista_admin_url(array('analista_view' => 'comparacion', 'analista_notice' => 'settings_saved')));
        exit;
    }
}

if (!function_exists('seo_analista_resolve_property_id')) {
    function seo_analista_resolve_property_id() {
        if (function_exists('seo_google_get_settings')) {
            $settings = (array) seo_google_get_settings();
            if (!empty($settings['property_id'])) return (string) $settings['property_id'];
        }
        if (!function_exists('seo_google_table')) return '';
        global $wpdb;
        $table = seo_google_table('search_data');
        if (function_exists('seo_analista_table_exists') && !seo_analista_table_exists($table)) return '';
        return (string) $wpdb->get_var("SELECT property_id FROM {$table} WHERE property_id<>'' ORDER BY data_date DESC, id DESC LIMIT 1");
    }
}

if (!function_exists('seo_analista_is_ready')) {
    function seo_analista_is_ready() {
        if (!function_exists('seo_google_table')) return false;
        $property_id = seo_analista_resolve_property_id();
        if ($property_id === '') return false;
        if (function_exists('seo_google_connection_status') && 'connected' === seo_google_connection_status()) return true;
        // En staging puede analizar un clon de los datos ya sincronizados en PRO.
        global $wpdb;
        $table = seo_google_table('search_data');
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE property_hash=%s", hash('sha256', $property_id)));
        return $count > 0;
    }
}

if (!function_exists('seo_analista_period')) {
    function seo_analista_period($property_id, $days = 28) {
        $days = seo_analista_days($days);
        if (!$property_id || !function_exists('seo_google_latest_data_date')) return array();

        $latest = seo_google_latest_data_date($property_id);
        if (!$latest) return array();

        try {
            $end = new DateTimeImmutable($latest);
            $start = $end->modify('-' . ($days - 1) . ' days');
            $previous_end = $start->modify('-1 day');
            $previous_start = $previous_end->modify('-' . ($days - 1) . ' days');
        } catch (Exception $exception) {
            return array();
        }

        return array(
            'days'                => $days,
            'date_from'           => $start->format('Y-m-d'),
            'date_to'             => $end->format('Y-m-d'),
            'previous_date_from'  => $previous_start->format('Y-m-d'),
            'previous_date_to'    => $previous_end->format('Y-m-d'),
        );
    }
}

if (!function_exists('seo_analista_period_metrics')) {
    function seo_analista_period_metrics($property_id, $date_from, $date_to) {
        global $wpdb;
        if (!$property_id || !function_exists('seo_google_table')) return array();
        $table = seo_google_table('search_data');

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COALESCE(SUM(clicks),0) AS clicks,
                    COALESCE(SUM(impressions),0) AS impressions,
                    COUNT(DISTINCT query_hash) AS queries,
                    COUNT(DISTINCT page_hash) AS pages,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) ELSE 0 END AS ctr,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions) / SUM(impressions) ELSE 0 END AS position
                 FROM {$table}
                 WHERE property_hash = %s AND data_date BETWEEN %s AND %s",
                hash('sha256', $property_id),
                $date_from,
                $date_to
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : array();
    }
}

if (!function_exists('seo_analista_query_rows')) {
    function seo_analista_query_rows($property_id, $date_from, $date_to, $limit = 5000) {
        global $wpdb;
        if (!$property_id || !function_exists('seo_google_table')) return array();
        $table = seo_google_table('search_data');
        $limit = max(100, min(10000, absint($limit)));

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    query_hash,
                    MAX(query_text) AS query_text,
                    SUM(clicks) AS clicks,
                    SUM(impressions) AS impressions,
                    COUNT(DISTINCT page_hash) AS pages,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) ELSE 0 END AS ctr,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions) / SUM(impressions) ELSE 0 END AS position
                 FROM {$table}
                 WHERE property_hash = %s
                   AND data_date BETWEEN %s AND %s
                 GROUP BY query_hash
                 HAVING SUM(impressions) > 0
                 ORDER BY impressions DESC, clicks DESC
                 LIMIT %d",
                hash('sha256', $property_id),
                $date_from,
                $date_to,
                $limit
            ),
            ARRAY_A
        );

        return seo_analista_merge_clean_query_rows($rows);
    }
}

if (!function_exists('seo_analista_page_rows')) {
    function seo_analista_page_rows($property_id, $date_from, $date_to, $limit = 1000) {
        global $wpdb;
        if (!$property_id || !function_exists('seo_google_table')) return array();
        $table = seo_google_table('search_data');
        $limit = max(50, min(5000, absint($limit)));

        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    page_hash,
                    MAX(page_url) AS page_url,
                    SUM(clicks) AS clicks,
                    SUM(impressions) AS impressions,
                    COUNT(DISTINCT query_hash) AS queries,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(clicks) / SUM(impressions) ELSE 0 END AS ctr,
                    CASE WHEN SUM(impressions) > 0 THEN SUM(position * impressions) / SUM(impressions) ELSE 0 END AS position
                 FROM {$table}
                 WHERE property_hash = %s
                   AND data_date BETWEEN %s AND %s
                 GROUP BY page_hash
                 HAVING SUM(impressions) > 0
                 ORDER BY impressions DESC, clicks DESC
                 LIMIT %d",
                hash('sha256', $property_id),
                $date_from,
                $date_to,
                $limit
            ),
            ARRAY_A
        );
    }
}

if (!function_exists('seo_analista_rows_map')) {
    function seo_analista_rows_map(array $rows, $key) {
        $map = array();
        foreach ($rows as $row) {
            if (!isset($row[$key])) continue;
            $map[(string) $row[$key]] = $row;
        }
        return $map;
    }
}

if (!function_exists('seo_analista_position_bucket')) {
    function seo_analista_position_bucket($position) {
        $position = (float) $position;
        if ($position <= 0) return 'unknown';
        if ($position <= 3) return 'top3';
        if ($position <= 10) return 'top10';
        if ($position <= 20) return 'top20';
        if ($position <= 50) return 'top50';
        if ($position <= 100) return 'top100';
        return 'outside';
    }
}

if (!function_exists('seo_analista_distribution')) {
    function seo_analista_distribution(array $rows) {
        $out = array(
            'top3' => 0,
            'top10' => 0,
            'top20' => 0,
            'top50' => 0,
            'top100' => 0,
            'outside' => 0,
        );
        foreach ($rows as $row) {
            $bucket = seo_analista_position_bucket($row['position'] ?? 0);
            if (isset($out[$bucket])) $out[$bucket]++;
        }
        return $out;
    }
}

if (!function_exists('seo_analista_visibility_index')) {
    function seo_analista_visibility_index(array $distribution) {
        $total = array_sum($distribution);
        if ($total < 1) return 0.0;
        $weighted = ((int) $distribution['top3'] * 100)
            + ((int) $distribution['top10'] * 72)
            + ((int) $distribution['top20'] * 45)
            + ((int) $distribution['top50'] * 18)
            + ((int) $distribution['top100'] * 5);
        return round($weighted / $total, 1);
    }
}

if (!function_exists('seo_analista_intent')) {
    function seo_analista_intent($query) {
        $q = seo_analista_normalize_text($query);
        if ($q === '') return 'informativa';

        $brand_terms = array('distribuidor de herramientas', 'distribuidordeherramientas', 'distribuidor herramientas');
        foreach ($brand_terms as $term) {
            if (strpos($q, $term) !== false) return 'navegacion';
        }

        if (preg_match('/\b(comprar|precio|precios|oferta|ofertas|tienda|venta|envio|barato|barata|stock|pedido)\b/', $q)) {
            return 'transaccional';
        }
        if (preg_match('/\b(mejor|mejores|profesional|profesionales|comparativa|comparar|opiniones|opinion|vs|recomendado|recomendada|para taller|industrial)\b/', $q)) {
            return 'comercial';
        }
        if (preg_match('/^(que|como|cuando|donde|por que|cual|cuanto|quien)\b|\b(guia|manual|tutorial|funciona|sirve|significa|noticias|obligatorio|obligatoria|normativa|requisitos|diferencia|diferencias|problema|problemas|solucion|soluciones|mantenimiento|instalar|instalacion|usar|uso)\b|^(es obligatorio|es obligatoria|hay que|tengo que|debo|puedo|se puede|merece la pena|para que sirve)\b/', $q)) {
            return 'informativa';
        }

        // Una consulta de producto sin verbo informativo suele estar mas cerca
        // de evaluacion comercial que de una pregunta puramente informativa.
        return 'comercial';
    }
}

if (!function_exists('seo_analista_intent_distribution')) {
    function seo_analista_intent_distribution(array $rows) {
        $out = array(
            'informativa' => 0,
            'comercial' => 0,
            'transaccional' => 0,
            'navegacion' => 0,
        );
        foreach ($rows as $row) {
            $intent = seo_analista_intent($row['query_text'] ?? '');
            $out[$intent] = isset($out[$intent]) ? $out[$intent] + 1 : 1;
        }
        return $out;
    }
}

if (!function_exists('seo_analista_page_type')) {
    function seo_analista_page_type($url) {
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        if ($path === '' || $path === '/') return 'Portada';
        if (strpos($path, '/producto/') === 0) return 'Producto';
        if (strpos($path, '/tienda/') === 0 || strpos($path, '/categoria-producto/') === 0) return 'Categoría';
        if (strpos($path, '/noticias/') === 0) return 'Entrada';
        if (strpos($path, '/marca/') === 0) return 'Marca';
        return 'Landing / página';
    }
}

if (!function_exists('seo_analista_page_type_summary')) {
    function seo_analista_page_type_summary(array $rows) {
        $out = array();
        foreach ($rows as $row) {
            $type = seo_analista_page_type($row['page_url'] ?? '');
            if (!isset($out[$type])) {
                $out[$type] = array('pages' => 0, 'clicks' => 0.0, 'impressions' => 0.0, 'queries' => 0);
            }
            $out[$type]['pages']++;
            $out[$type]['clicks'] += (float) ($row['clicks'] ?? 0);
            $out[$type]['impressions'] += (float) ($row['impressions'] ?? 0);
            $out[$type]['queries'] += (int) ($row['queries'] ?? 0);
        }
        uasort($out, static function ($a, $b) {
            return (float) $b['impressions'] <=> (float) $a['impressions'];
        });
        return $out;
    }
}

if (!function_exists('seo_analista_percent_change')) {
    function seo_analista_percent_change($current, $previous) {
        $current = (float) $current;
        $previous = (float) $previous;
        if ($previous == 0.0) return $current > 0 ? null : 0.0;
        return (($current - $previous) / abs($previous)) * 100;
    }
}

if (!function_exists('seo_analista_opportunity_score')) {
    function seo_analista_opportunity_score(array $row, array $previous = array()) {
        $position = (float) ($row['position'] ?? 0);
        $impressions = max(0.0, (float) ($row['impressions'] ?? 0));
        $clicks = max(0.0, (float) ($row['clicks'] ?? 0));
        $ctr = max(0.0, (float) ($row['ctr'] ?? 0));
        $intent = seo_analista_intent($row['query_text'] ?? '');

        if ($position >= 11 && $position <= 20) {
            $rank_score = 45;
        } elseif ($position > 20 && $position <= 50) {
            $rank_score = 38;
        } elseif ($position > 50 && $position <= 70) {
            $rank_score = 25;
        } elseif ($position > 70 && $position <= 100) {
            $rank_score = 14;
        } elseif ($position > 3 && $position <= 10 && $ctr < 0.03) {
            $rank_score = 30; // oportunidad de CTR / snippet.
        } else {
            $rank_score = 5;
        }

        $demand_score = min(28, log(1 + $impressions) * 4.4);
        $intent_score = 'transaccional' === $intent ? 16 : ('comercial' === $intent ? 11 : 4);
        $ctr_score = ($position <= 20 && $impressions >= 10 && $ctr < 0.02) ? 8 : 0;

        $trend_score = 0;
        if ($previous) {
            $previous_position = (float) ($previous['position'] ?? 0);
            if ($previous_position > 0 && $position > 0) {
                $movement = $previous_position - $position; // positivo = mejora.
                $trend_score = max(-6, min(6, $movement * 0.45));
            }
        }

        $click_penalty = ($clicks > 10 && $position <= 10) ? 6 : 0;
        return max(0, min(100, (int) round($rank_score + $demand_score + $intent_score + $ctr_score + $trend_score - $click_penalty)));
    }
}

if (!function_exists('seo_analista_top_opportunities')) {
    function seo_analista_top_opportunities(array $current_rows, array $previous_rows, $limit = 30) {
        $previous_map = seo_analista_rows_map($previous_rows, 'query_hash');
        $rows = array();

        foreach ($current_rows as $row) {
            if (!seo_analista_query_is_actionable($row['query_text'] ?? '')) continue;
            $impressions = (float) ($row['impressions'] ?? 0);
            $position = (float) ($row['position'] ?? 0);
            if ($impressions < 2 || $position <= 0 || $position > 100) continue;

            $previous = isset($previous_map[$row['query_hash']]) ? $previous_map[$row['query_hash']] : array();
            $row['intent'] = seo_analista_intent($row['query_text'] ?? '');
            $row['score'] = seo_analista_opportunity_score($row, $previous);
            $row['previous_position'] = isset($previous['position']) ? (float) $previous['position'] : 0.0;
            $row['position_change'] = $row['previous_position'] > 0
                ? $row['previous_position'] - (float) $row['position']
                : null;
            $rows[] = $row;
        }

        usort($rows, static function ($a, $b) {
            if ((int) $a['score'] === (int) $b['score']) {
                return (float) $b['impressions'] <=> (float) $a['impressions'];
            }
            return (int) $b['score'] <=> (int) $a['score'];
        });

        $rows = array_slice($rows, 0, max(5, min(100, absint($limit))));

        // El contexto de catalogo es costoso; solo se calcula sobre el shortlist.
        foreach ($rows as &$row) {
            $row['catalog'] = function_exists('seo_google_opportunity_catalog_context')
                ? (array) seo_google_opportunity_catalog_context($row['query_text'] ?? '')
                : array();
            if (!empty($row['catalog']['product_count'])) {
                $row['score'] = min(100, (int) $row['score'] + min(8, (int) ceil(log(1 + (int) $row['catalog']['product_count']) * 2)));
            }
        }
        unset($row);

        usort($rows, static function ($a, $b) {
            if ((int) $a['score'] === (int) $b['score']) {
                return (float) $b['impressions'] <=> (float) $a['impressions'];
            }
            return (int) $b['score'] <=> (int) $a['score'];
        });

        return $rows;
    }
}

if (!function_exists('seo_analista_get_data')) {
    function seo_analista_get_data($days = 28) {
        $days = seo_analista_days($days);
        $empty = array(
            'ready' => false,
            'days' => $days,
            'settings' => array(),
            'period' => array(),
            'current' => array(),
            'previous' => array(),
            'queries' => array(),
            'previous_queries' => array(),
            'pages' => array(),
            'previous_pages' => array(),
            'distribution' => array(),
            'previous_distribution' => array(),
            'intent_distribution' => array(),
            'page_types' => array(),
            'opportunities' => array(),
        );

        if (!seo_analista_is_ready()) return $empty;

        $google_settings = function_exists('seo_google_get_settings') ? (array) seo_google_get_settings() : array();
        $property_id = seo_analista_resolve_property_id();
        $period = seo_analista_period($property_id, $days);
        if (!$period) return $empty;

        $queries = seo_analista_query_rows($property_id, $period['date_from'], $period['date_to'], 10000);
        $previous_queries = seo_analista_query_rows($property_id, $period['previous_date_from'], $period['previous_date_to'], 10000);
        $pages = seo_analista_page_rows($property_id, $period['date_from'], $period['date_to'], 3000);
        $previous_pages = seo_analista_page_rows($property_id, $period['previous_date_from'], $period['previous_date_to'], 3000);

        $distribution = seo_analista_distribution($queries);
        $previous_distribution = seo_analista_distribution($previous_queries);

        $current_metrics = seo_analista_period_metrics($property_id, $period['date_from'], $period['date_to']);
        $previous_metrics = seo_analista_period_metrics($property_id, $period['previous_date_from'], $period['previous_date_to']);
        // El contador visible usa consultas ya saneadas y fusionadas para que
        // las colas de metadatos historicas no inflen artificialmente el KPI.
        $current_metrics['queries'] = count($queries);
        $previous_metrics['queries'] = count($previous_queries);

        return array(
            'ready' => true,
            'days' => $days,
            'settings' => seo_analista_get_settings(),
            'period' => $period,
            'current' => $current_metrics,
            'previous' => $previous_metrics,
            'queries' => $queries,
            'previous_queries' => $previous_queries,
            'pages' => $pages,
            'previous_pages' => $previous_pages,
            'distribution' => $distribution,
            'previous_distribution' => $previous_distribution,
            'intent_distribution' => seo_analista_intent_distribution($queries),
            'page_types' => seo_analista_page_type_summary($pages),
            'opportunities' => seo_analista_top_opportunities($queries, $previous_queries, 40),
            'visibility_index' => seo_analista_visibility_index($distribution),
            'previous_visibility_index' => seo_analista_visibility_index($previous_distribution),
        );
    }
}
