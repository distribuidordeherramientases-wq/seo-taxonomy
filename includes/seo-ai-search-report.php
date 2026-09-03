<?php
/**
 * SEO System - Analisis para posicionamiento y busqueda con IA.
 *
 * Convierte senales reales de Search Console y, cuando existe, GA4 en una
 * cola de trabajo para decidir que URLs revisar primero. No intenta replicar
 * el ranking de Google, RAG ni query fan-out interno.
 */

defined('ABSPATH') || exit;

/**
 * Normaliza texto para el analisis de cobertura de consultas.
 * Se apoya en el normalizador de Landing Pages cuando esta disponible para
 * mantener el mismo criterio semantico ligero en todo el plugin.
 */
function seo_reports_ai_search_tokens($text) {
    if (function_exists('seo_landing_google_text_tokens')) {
        return array_values(array_unique((array) seo_landing_google_text_tokens($text)));
    }

    $text = wp_strip_all_tags(html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8'));
    if (function_exists('remove_accents')) {
        $text = remove_accents($text);
    }
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);
    $stop = array_fill_keys(array(
        'a','al','con','como','de','del','el','en','es','la','las','lo','los','para','por','que','sin','su','un','una','y'
    ), true);

    $tokens = array();
    foreach (preg_split('/\s+/', trim((string) $text)) as $token) {
        if ($token === '' || strlen($token) < 3 || isset($stop[$token])) {
            continue;
        }
        $tokens[$token] = true;
    }
    return array_keys($tokens);
}

/**
 * Analiza senales editoriales de una URL WordPress.
 *
 * El resultado NO es una puntuacion de Google. Resume claridad, profundidad,
 * estructura y cobertura de los terminos que ya aparecen en Search Console.
 */
function seo_reports_ai_search_content_signals($url, array $queries = array()) {
    $result = array(
        'available'      => false,
        'post_id'        => 0,
        'post_type'      => '',
        'score'          => null,
        'word_count'     => 0,
        'h2_count'       => 0,
        'internal_links' => 0,
        'freshness_days' => null,
        'query_coverage' => null,
        'advice'         => array(),
    );

    $post_id = url_to_postid((string) $url);
    if (!$post_id) {
        return $result;
    }

    $post = get_post($post_id);
    if (!$post || 'publish' !== $post->post_status) {
        return $result;
    }

    $raw_content = (string) $post->post_content;
    $plain       = trim(wp_strip_all_tags(strip_shortcodes($raw_content)));
    $words       = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
    $word_count  = is_array($words) ? count($words) : 0;
    $h2_count    = preg_match_all('/<h2\b[^>]*>/i', $raw_content, $unused_h2);
    $paragraphs  = preg_match_all('/<p\b[^>]*>/i', $raw_content, $unused_p);

    $internal_links = 0;
    if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $raw_content, $matches)) {
        $site_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        foreach ((array) $matches[1] as $href) {
            $href = trim((string) $href);
            if ($href === '' || strpos($href, '#') === 0 || strpos($href, 'mailto:') === 0 || strpos($href, 'tel:') === 0) {
                continue;
            }
            $host = (string) wp_parse_url($href, PHP_URL_HOST);
            if ($host === '' || $host === $site_host) {
                $internal_links++;
            }
        }
    }

    $title        = (string) get_the_title($post_id);
    $title_length = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
    $modified     = get_post_modified_time('U', true, $post);
    $now          = current_time('timestamp', true);
    $freshness    = $modified ? max(0, (int) floor(($now - $modified) / DAY_IN_SECONDS)) : null;

    $score = 0;
    if ($word_count >= 900) {
        $score += 27;
    } elseif ($word_count >= 600) {
        $score += 23;
    } elseif ($word_count >= 350) {
        $score += 17;
    } elseif ($word_count >= 180) {
        $score += 10;
    } else {
        $score += 4;
    }

    if ($h2_count >= 4) {
        $score += 20;
    } elseif ($h2_count >= 2) {
        $score += 16;
    } elseif ($h2_count === 1) {
        $score += 8;
    }

    if ($paragraphs >= 7) {
        $score += 10;
    } elseif ($paragraphs >= 4) {
        $score += 7;
    } elseif ($paragraphs >= 2) {
        $score += 4;
    }

    if ($title_length >= 28 && $title_length <= 70) {
        $score += 15;
    } elseif ($title_length >= 18 && $title_length <= 85) {
        $score += 10;
    } else {
        $score += 5;
    }

    if ($internal_links >= 4) {
        $score += 13;
    } elseif ($internal_links >= 2) {
        $score += 9;
    } elseif ($internal_links >= 1) {
        $score += 5;
    }

    if (null !== $freshness && $freshness <= 180) {
        $score += 10;
    } elseif (null !== $freshness && $freshness <= 365) {
        $score += 7;
    } elseif (null !== $freshness && $freshness <= 730) {
        $score += 3;
    }

    $query_tokens = array();
    foreach ($queries as $query) {
        $query_text = is_array($query) ? (string) ($query['query_text'] ?? '') : (string) $query;
        foreach (seo_reports_ai_search_tokens($query_text) as $token) {
            $query_tokens[$token] = true;
        }
    }

    $query_coverage = null;
    if ($query_tokens) {
        $document_tokens = array_fill_keys(seo_reports_ai_search_tokens($title . ' ' . $plain), true);
        $covered = 0;
        foreach (array_keys($query_tokens) as $token) {
            if (isset($document_tokens[$token])) {
                $covered++;
            }
        }
        $query_coverage = count($query_tokens) > 0 ? ($covered / count($query_tokens)) : 0;
        $score += (int) round(min(1, $query_coverage) * 5);
    }

    $advice = array();
    if ($word_count < 350) {
        $advice[] = 'Ampliar la respuesta: faltan detalles para cubrir mejor la intencion.';
    }
    if ($h2_count < 2) {
        $advice[] = 'Separar subtemas con H2 claros; facilita entender y recuperar cada respuesta.';
    }
    if ($internal_links < 2) {
        $advice[] = 'Anadir enlaces internos hacia categorias, productos o contenidos relacionados.';
    }
    if (null !== $freshness && $freshness > 365) {
        $advice[] = 'Revisar actualidad y ejemplos: lleva mas de un ano sin actualizarse.';
    }
    if ($title_length < 28 || $title_length > 70) {
        $advice[] = 'Revisar el titulo para que describa con precision el tema principal.';
    }
    if (null !== $query_coverage && $query_coverage < 0.55) {
        $advice[] = 'La pagina cubre poco vocabulario de las consultas reales asociadas; revisar subtemas y terminologia.';
    }
    if (!$advice) {
        $advice[] = 'La base editorial es razonable; priorizar mejoras segun demanda, CTR y posicion.';
    }

    $result['available']      = true;
    $result['post_id']        = (int) $post_id;
    $result['post_type']      = (string) $post->post_type;
    $result['score']          = max(0, min(100, (int) $score));
    $result['word_count']     = (int) $word_count;
    $result['h2_count']       = (int) $h2_count;
    $result['internal_links'] = (int) $internal_links;
    $result['freshness_days'] = $freshness;
    $result['query_coverage'] = $query_coverage;
    $result['advice']         = $advice;

    return $result;
}

/** Obtiene el cambio de clics/impresiones por pagina frente al periodo anterior. */
function seo_reports_ai_search_page_changes($property_id, array $period, array $page_hashes) {
    global $wpdb;

    if (!$page_hashes || !function_exists('seo_google_table')) {
        return array();
    }

    $table = seo_google_table('search_data');
    if (function_exists('seo_google_table_exists') && !seo_google_table_exists($table)) {
        return array();
    }

    $page_hashes = array_values(array_unique(array_filter(array_map('strval', $page_hashes))));
    if (!$page_hashes) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($page_hashes), '%s'));
    $args = array(
        $period['current_from'], $period['current_to'],
        $period['current_from'], $period['current_to'],
        $period['previous_from'], $period['previous_to'],
        $period['previous_from'], $period['previous_to'],
        hash('sha256', (string) $property_id),
        $period['previous_from'], $period['current_to'],
    );
    $args = array_merge($args, $page_hashes);

    $sql = "SELECT page_hash,
        SUM(CASE WHEN data_date BETWEEN %s AND %s THEN impressions ELSE 0 END) AS current_impressions,
        SUM(CASE WHEN data_date BETWEEN %s AND %s THEN clicks ELSE 0 END) AS current_clicks,
        SUM(CASE WHEN data_date BETWEEN %s AND %s THEN impressions ELSE 0 END) AS previous_impressions,
        SUM(CASE WHEN data_date BETWEEN %s AND %s THEN clicks ELSE 0 END) AS previous_clicks
        FROM {$table}
        WHERE property_hash = %s
          AND data_date BETWEEN %s AND %s
          AND page_hash IN ({$placeholders})
        GROUP BY page_hash";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    $out = array();
    foreach ((array) $rows as $row) {
        $out[(string) $row['page_hash']] = array(
            'current_impressions'  => (float) $row['current_impressions'],
            'current_clicks'       => (float) $row['current_clicks'],
            'previous_impressions' => (float) $row['previous_impressions'],
            'previous_clicks'      => (float) $row['previous_clicks'],
        );
    }
    return $out;
}

function seo_reports_ai_search_change_pct($current, $previous) {
    $current  = (float) $current;
    $previous = (float) $previous;
    if ($previous <= 0) {
        return null;
    }
    return (($current - $previous) / $previous) * 100;
}

/**
 * Construye el diagnostico orientado a decidir en que paginas trabajar primero.
 */
function seo_reports_ai_search_build($days = 28) {
    $empty = array(
        'available'      => false,
        'days'           => $days,
        'period'         => array(),
        'rows'           => array(),
        'ga4_available'  => false,
        'summary'        => array(),
        'reason'         => '',
    );

    $required = array(
        'seo_google_get_settings',
        'seo_google_connection_status',
        'seo_google_get_analysis_period',
        'seo_google_get_signal_pages',
    );
    foreach ($required as $function) {
        if (!function_exists($function)) {
            $empty['reason'] = 'Falta cargar el modulo de Google Intelligence.';
            return $empty;
        }
    }

    $settings = seo_google_get_settings();
    $property_id = (string) ($settings['property_id'] ?? '');
    if ('connected' !== seo_google_connection_status() || $property_id === '') {
        $empty['reason'] = 'Search Console no esta conectado completamente.';
        return $empty;
    }

    $period = seo_google_get_analysis_period($property_id, $days);
    if (!$period) {
        $empty['reason'] = 'Search Console esta conectado, pero todavia no hay un periodo sincronizado para analizar.';
        return $empty;
    }

    $pages = (array) seo_google_get_signal_pages(
        $property_id,
        $period['current_from'],
        $period['current_to'],
        30,
        1,
        ''
    );
    if (!$pages) {
        $empty['reason'] = 'No hay paginas con impresiones en el periodo seleccionado.';
        $empty['period'] = $period;
        return $empty;
    }

    $page_hashes = wp_list_pluck($pages, 'page_hash');
    $changes = seo_reports_ai_search_page_changes($property_id, $period, $page_hashes);

    $ga4_map = array();
    if (function_exists('seo_landing_google_analytics_page_map')) {
        $ga4_map = (array) seo_landing_google_analytics_page_map(false);
    }
    $ga4_available = !empty($ga4_map);

    $max_impressions = 1.0;
    foreach ($pages as $page) {
        $max_impressions = max($max_impressions, (float) ($page['impressions'] ?? 0));
    }

    // CTR de referencia calculado solo con los datos del propio sitio por tramo de posicion.
    $buckets = array();
    foreach ($pages as $page) {
        $position = (float) ($page['position'] ?? 0);
        if ($position <= 0) {
            $bucket = 'none';
        } elseif ($position <= 3) {
            $bucket = '1-3';
        } elseif ($position <= 10) {
            $bucket = '4-10';
        } elseif ($position <= 20) {
            $bucket = '11-20';
        } elseif ($position <= 50) {
            $bucket = '21-50';
        } else {
            $bucket = '51+';
        }
        if (!isset($buckets[$bucket])) {
            $buckets[$bucket] = array('clicks' => 0.0, 'impressions' => 0.0);
        }
        $buckets[$bucket]['clicks'] += (float) ($page['clicks'] ?? 0);
        $buckets[$bucket]['impressions'] += (float) ($page['impressions'] ?? 0);
    }

    $rows = array();
    foreach ($pages as $page) {
        $url         = (string) ($page['label'] ?? '');
        $page_hash   = (string) ($page['page_hash'] ?? '');
        $impressions = max(0, (float) ($page['impressions'] ?? 0));
        $clicks      = max(0, (float) ($page['clicks'] ?? 0));
        $ctr         = max(0, (float) ($page['ctr'] ?? 0));
        $position    = max(0, (float) ($page['position'] ?? 0));
        $queries     = max(0, (int) ($page['queries'] ?? 0));
        $evidence    = (array) ($page['evidence'] ?? array());

        if ($position <= 0) {
            $bucket = 'none';
        } elseif ($position <= 3) {
            $bucket = '1-3';
        } elseif ($position <= 10) {
            $bucket = '4-10';
        } elseif ($position <= 20) {
            $bucket = '11-20';
        } elseif ($position <= 50) {
            $bucket = '21-50';
        } else {
            $bucket = '51+';
        }
        $bucket_ctr = !empty($buckets[$bucket]['impressions'])
            ? ((float) $buckets[$bucket]['clicks'] / (float) $buckets[$bucket]['impressions'])
            : 0.0;
        $ctr_gap = $bucket_ctr > 0 ? (($ctr - $bucket_ctr) / $bucket_ctr) : 0.0;

        $change = (array) ($changes[$page_hash] ?? array());
        $growth = seo_reports_ai_search_change_pct(
            (float) ($change['current_impressions'] ?? $impressions),
            (float) ($change['previous_impressions'] ?? 0)
        );

        $path = function_exists('seo_landing_google_path_key')
            ? seo_landing_google_path_key($url)
            : untrailingslashit('/' . ltrim((string) wp_parse_url($url, PHP_URL_PATH), '/'));
        $ga4 = (array) ($ga4_map[$path] ?? array());

        $content = seo_reports_ai_search_content_signals($url, $evidence);
        $content_score = null !== $content['score'] ? (int) $content['score'] : 50;

        $demand_score = min(100, (log(1 + $impressions) / log(1 + $max_impressions)) * 100);
        if ($position > 0 && $position <= 3) {
            $position_opportunity = 30;
        } elseif ($position <= 10 && $position > 0) {
            $position_opportunity = 100;
        } elseif ($position <= 20 && $position > 0) {
            $position_opportunity = 92;
        } elseif ($position <= 35 && $position > 0) {
            $position_opportunity = 72;
        } elseif ($position <= 50 && $position > 0) {
            $position_opportunity = 52;
        } elseif ($position > 50) {
            $position_opportunity = 30;
        } else {
            $position_opportunity = 20;
        }

        $ctr_need = 45;
        if ($bucket_ctr > 0) {
            if ($ctr_gap <= -0.45) {
                $ctr_need = 100;
            } elseif ($ctr_gap <= -0.20) {
                $ctr_need = 80;
            } elseif ($ctr_gap < 0) {
                $ctr_need = 60;
            } else {
                $ctr_need = 25;
            }
        }

        if (null === $growth) {
            $movement_score = 50;
        } elseif ($growth >= 50) {
            $movement_score = 100;
        } elseif ($growth >= 20) {
            $movement_score = 85;
        } elseif ($growth >= 5) {
            $movement_score = 70;
        } elseif ($growth >= -10) {
            $movement_score = 55;
        } elseif ($growth >= -30) {
            $movement_score = 38;
        } else {
            $movement_score = 25;
        }

        $content_need = 100 - $content_score;
        $priority = (int) round(
            ($demand_score * 0.30)
            + ($position_opportunity * 0.24)
            + ($ctr_need * 0.14)
            + ($movement_score * 0.12)
            + ($content_need * 0.20)
        );

        $breadth_score = min(100, (int) round(sqrt(max(0, $queries)) * 23));
        $retrieval_score = (int) round(($content_score * 0.68) + ($breadth_score * 0.32));

        $actions = array();
        if ($position >= 4 && $position <= 20 && $impressions >= 20) {
            $actions[] = 'Prioridad SEO: ya esta cerca de la primera pagina o dentro de ella.';
        }
        if ($bucket_ctr > 0 && $ctr_gap <= -0.20 && $impressions >= 20) {
            $actions[] = 'Mejorar titulo/snippet: el CTR queda por debajo de otras URLs del sitio en posiciones parecidas.';
        }
        if (null !== $growth && $growth >= 20) {
            $actions[] = 'Demanda en crecimiento: reforzar ahora mientras aumenta la visibilidad.';
        } elseif (null !== $growth && $growth <= -25 && $impressions >= 20) {
            $actions[] = 'Visibilidad a la baja: revisar perdida de consultas, contenido y competencia interna.';
        }
        if (!empty($content['advice'])) {
            $actions[] = (string) $content['advice'][0];
        }
        if (!$actions) {
            $actions[] = 'Mantener y vigilar; no aparece una carencia dominante con las senales actuales.';
        }

        $rows[] = array(
            'url'             => $url,
            'title'           => $content['post_id'] ? (string) get_the_title($content['post_id']) : $url,
            'impressions'     => $impressions,
            'clicks'          => $clicks,
            'ctr'             => $ctr,
            'position'        => $position,
            'queries'         => $queries,
            'top_queries'     => $evidence,
            'growth'          => $growth,
            'sessions'        => (int) ($ga4['sessions'] ?? 0),
            'pageviews'       => (int) ($ga4['pageviews'] ?? 0),
            'content'         => $content,
            'priority'        => max(0, min(100, $priority)),
            'retrieval_score' => max(0, min(100, $retrieval_score)),
            'ctr_reference'   => $bucket_ctr,
            'ctr_gap'         => $ctr_gap,
            'action'          => $actions[0],
        );
    }

    usort($rows, static function($left, $right) {
        $priority_cmp = ((int) $right['priority']) <=> ((int) $left['priority']);
        if (0 !== $priority_cmp) {
            return $priority_cmp;
        }
        return ((float) $right['impressions']) <=> ((float) $left['impressions']);
    });

    $high = 0;
    $growing = 0;
    $ctr_low = 0;
    $retrieval_total = 0;
    $retrieval_count = 0;
    $sessions_total = 0;
    foreach ($rows as $row) {
        if ((int) $row['priority'] >= 70) {
            $high++;
        }
        if (null !== $row['growth'] && (float) $row['growth'] >= 20) {
            $growing++;
        }
        if ((float) $row['ctr_reference'] > 0 && (float) $row['ctr_gap'] <= -0.20) {
            $ctr_low++;
        }
        $retrieval_total += (int) $row['retrieval_score'];
        $retrieval_count++;
        $sessions_total += (int) $row['sessions'];
    }

    return array(
        'available'     => true,
        'days'          => $days,
        'period'        => $period,
        'rows'          => $rows,
        'ga4_available' => $ga4_available,
        'summary'       => array(
            'pages'            => count($rows),
            'high'             => $high,
            'growing'          => $growing,
            'ctr_low'          => $ctr_low,
            'retrieval_avg'    => $retrieval_count ? (int) round($retrieval_total / $retrieval_count) : 0,
            'sessions'         => $sessions_total,
        ),
        'reason' => '',
    );
}

/**
 * Informe de priorizacion inspirado en el flujo de recuperacion de informacion:
 * consulta -> URLs relacionadas -> senales de demanda -> calidad de la respuesta.
 * No intenta reproducir RAG, query fan-out ni el ranking interno de Google.
 */
function seo_reports_render_ai_search_readiness() {
    $days = 28;
    $report = seo_reports_ai_search_build($days);

    echo '<section class="seo-reports-ai-search" style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:22px 0;">';
    echo '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">';
    echo '<div style="max-width:900px;">';
    echo '<h2 style="margin:0 0 6px;">Análisis para posicionamiento y búsqueda con IA</h2>';
    echo '<p style="margin:0;color:#50575e;">Convierte tus datos de Search Console en una lista de trabajo: qué URLs reciben demanda, cuáles están ganando movimiento, cuántas consultas distintas recuperan la misma página y qué aspectos editoriales conviene reforzar.</p>';
    echo '</div>';
    if (function_exists('seo_google_admin_url')) {
        echo '<a class="button" href="' . esc_url(seo_google_admin_url('signals')) . '">Ver evidencia de Google</a>';
    }
    echo '</div>';

    echo '<div style="margin:14px 0 18px;padding:12px 14px;background:#f6f7f7;border-left:4px solid #2271b1;">';
    echo '<strong>Qué significa aquí “RAG / query fan-out”.</strong> Este informe no intenta copiar el algoritmo de Google ni crea una “puntuación de Google”. Usa un proxy práctico: parte de consultas reales, observa cuántas variantes llevan a cada URL y comprueba si la página está bien estructurada para responder esos subtemas. La prioridad combina demanda, posición, CTR, movimiento y necesidad editorial.';
    echo '</div>';

    if (empty($report['available'])) {
        echo '<div class="notice notice-info inline"><p><strong>Aún no se puede calcular la prioridad con datos de Google.</strong> ' . esc_html((string) ($report['reason'] ?? '')) . '</p></div>';
        if (function_exists('seo_google_admin_url')) {
            echo '<p><a class="button button-primary" href="' . esc_url(seo_google_admin_url('settings')) . '">Configurar Search Console</a> ';
            echo '<a class="button" href="' . esc_url(seo_google_admin_url('sync')) . '">Sincronizar datos</a></p>';
        }
        echo '<p class="description">Cuando haya datos, el panel mostrará automáticamente las URLs donde merece la pena trabajar primero. Analytics es opcional: si está conectado añade sesiones y vistas como señal de movimiento real de usuarios.</p>';
        echo '</section>';
        return;
    }

    $summary = (array) $report['summary'];
    $period  = (array) $report['period'];

    $cards = array(
        array('label' => 'Páginas analizadas', 'value' => number_format_i18n((int) ($summary['pages'] ?? 0)), 'help' => 'URLs con señal suficiente en Search Console.'),
        array('label' => 'Prioridad alta', 'value' => number_format_i18n((int) ($summary['high'] ?? 0)), 'help' => 'Puntuación de trabajo 70/100 o superior.'),
        array('label' => 'Demanda creciendo', 'value' => number_format_i18n((int) ($summary['growing'] ?? 0)), 'help' => 'Impresiones +20% o más frente al periodo anterior.'),
        array('label' => 'CTR mejorable', 'value' => number_format_i18n((int) ($summary['ctr_low'] ?? 0)), 'help' => 'CTR al menos 20% peor que URLs del sitio en posiciones parecidas.'),
        array('label' => 'Preparación media', 'value' => number_format_i18n((int) ($summary['retrieval_avg'] ?? 0)) . '/100', 'help' => 'Estructura editorial + amplitud de consultas asociadas.'),
    );
    if (!empty($report['ga4_available'])) {
        $cards[] = array('label' => 'Sesiones GA4', 'value' => number_format_i18n((int) ($summary['sessions'] ?? 0)), 'help' => 'Sesiones en las URLs analizadas durante el mapa GA4 disponible.');
    }

    echo '<p class="description" style="margin:0 0 12px;">Periodo Search Console: <code>' . esc_html((string) ($period['current_from'] ?? '')) . '</code> → <code>' . esc_html((string) ($period['current_to'] ?? '')) . '</code>, comparado con el periodo inmediatamente anterior.</p>';
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px;margin-bottom:18px;">';
    foreach ($cards as $card) {
        echo '<div style="border:1px solid #dcdcde;border-radius:8px;padding:14px;background:#fff;">';
        echo '<strong style="display:block;font-size:26px;line-height:1.1;">' . esc_html($card['value']) . '</strong>';
        echo '<span style="display:block;margin-top:6px;font-weight:600;">' . esc_html($card['label']) . '</span>';
        echo '<small style="display:block;margin-top:4px;color:#646970;">' . esc_html($card['help']) . '</small>';
        echo '</div>';
    }
    echo '</div>';

    echo '<h3 style="margin:6px 0 4px;">Dónde trabajar primero</h3>';
    echo '<p style="margin:0 0 12px;color:#646970;">Ordenado por oportunidad práctica, no por “nota SEO” genérica. Una página puede tener buen contenido y aun así ser prioritaria si Google ya le está dando muchas impresiones cerca de posiciones útiles.</p>';
    echo '<div style="overflow:auto;border:1px solid #dcdcde;border-radius:8px;">';
    echo '<table class="widefat striped" style="border:0;min-width:1120px;">';
    echo '<thead><tr><th>Prioridad</th><th>Página</th><th>Señal Google</th><th>Movimiento</th><th>Fan-out observado</th><th>Preparación</th><th>Qué mejorar</th></tr></thead><tbody>';

    foreach (array_slice((array) $report['rows'], 0, 15) as $row) {
        $priority = (int) $row['priority'];
        $priority_bg = $priority >= 75 ? '#f8d7da' : ($priority >= 60 ? '#fff3cd' : '#d1e7dd');
        $growth = $row['growth'];
        $growth_text = null === $growth
            ? 'Sin comparativa previa'
            : (((float) $growth > 0 ? '+' : '') . number_format_i18n((float) $growth, 0) . '% imp.');
        $query_labels = array();
        foreach (array_slice((array) $row['top_queries'], 0, 3) as $query) {
            if (!empty($query['query_text'])) {
                $query_labels[] = (string) $query['query_text'];
            }
        }
        $content = (array) $row['content'];
        $content_detail = !empty($content['available'])
            ? number_format_i18n((int) $content['word_count']) . ' palabras · ' . number_format_i18n((int) $content['h2_count']) . ' H2 · ' . number_format_i18n((int) $content['internal_links']) . ' enlaces internos'
            : 'URL no resuelta como contenido WordPress; solo se puntúan las señales de búsqueda.';

        echo '<tr>';
        echo '<td><span style="display:inline-block;min-width:54px;text-align:center;padding:5px 8px;border-radius:999px;background:' . esc_attr($priority_bg) . ';font-weight:700;">' . esc_html((string) $priority) . '/100</span></td>';
        echo '<td style="max-width:280px;"><a href="' . esc_url((string) $row['url']) . '" target="_blank" rel="noopener"><strong>' . esc_html((string) $row['title']) . '</strong></a><br><small style="word-break:break-all;color:#646970;">' . esc_html((string) wp_parse_url((string) $row['url'], PHP_URL_PATH)) . '</small></td>';
        echo '<td>' . esc_html(number_format_i18n((int) $row['impressions'])) . ' imp. · ' . esc_html(number_format_i18n((int) $row['clicks'])) . ' clics<br><small>CTR ' . esc_html(number_format_i18n(((float) $row['ctr']) * 100, 2)) . '% · pos. ' . esc_html($row['position'] > 0 ? number_format_i18n((float) $row['position'], 1) : '—') . '</small></td>';
        echo '<td><strong>' . esc_html($growth_text) . '</strong>';
        if (!empty($report['ga4_available'])) {
            echo '<br><small>' . esc_html(number_format_i18n((int) $row['sessions'])) . ' sesiones · ' . esc_html(number_format_i18n((int) $row['pageviews'])) . ' vistas GA4</small>';
        }
        echo '</td>';
        echo '<td><strong>' . esc_html(number_format_i18n((int) $row['queries'])) . ' consultas</strong>';
        if ($query_labels) {
            echo '<br><small>' . esc_html(implode(' · ', $query_labels)) . '</small>';
        }
        echo '</td>';
        echo '<td><strong>' . esc_html(number_format_i18n((int) $row['retrieval_score'])) . '/100</strong><br><small>' . esc_html($content_detail) . '</small></td>';
        echo '<td style="min-width:260px;">' . esc_html((string) $row['action']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    echo '<details style="margin-top:14px;"><summary><strong>Cómo calcula la prioridad y qué no significa</strong></summary>';
    echo '<div style="padding:10px 0 0;color:#50575e;line-height:1.6;">';
    echo '<p><strong>Demanda:</strong> impresiones reales de Search Console. <strong>Posición:</strong> da más oportunidad a URLs aproximadamente entre 4 y 20. <strong>CTR:</strong> se compara contra otras páginas de tu propia web en un tramo de posición parecido, no contra una tabla externa. <strong>Movimiento:</strong> compara impresiones con el periodo anterior. <strong>Preparación:</strong> revisa profundidad, H2, enlaces internos, frescura y cobertura del vocabulario de consultas reales.</p>';
    echo '<p><strong>Fan-out observado</strong> es el número de consultas distintas que Google ya relaciona con la URL. Sirve como aproximación a la amplitud de intención, pero no revela las consultas internas que Google pueda generar en sus experiencias de IA. La puntuación es una herramienta interna de priorización, no una métrica oficial ni una predicción de ranking.</p>';
    echo '</div></details>';
    echo '</section>';
}
