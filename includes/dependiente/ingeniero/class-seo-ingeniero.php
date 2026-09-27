<?php
/**
 * Ingeniero: conocimiento externo técnico por categoría.
 *
 * V1:
 * - L1 Documentación técnica activa.
 * - L2 Experiencia práctica preparada pero desactivada.
 * - No modifica catálogo, índice público ni respuestas de Dependiente.
 */
defined('ABSPATH') || exit;

final class SEO_Ingeniero {
    const VERSION = '0.1.1';
    const STATE_OPTION = 'seo_ingeniero_state_v1';
    const CATEGORY_STATE_OPTION = 'seo_ingeniero_category_state_v1';
    const LESSON_TECHNICAL = 'l1_technical';
    const LESSON_PRACTICAL = 'l2_practical';

    private static $provider = null;

    public static function init() {
        SEO_Ingeniero_DB::maybe_install();
        add_action('init', array(__CLASS__, 'maybe_install'), 7);
    }

    public static function maybe_install() {
        SEO_Ingeniero_DB::maybe_install();
    }

    public static function lessons() {
        return array(
            self::LESSON_TECHNICAL => array(
                'label'=>'L1 · Documentación técnica',
                'enabled'=>true,
                'description'=>'Documentación oficial/técnica, manuales, fichas, organismos y normativa pertinente.',
            ),
            self::LESSON_PRACTICAL => array(
                'label'=>'L2 · Experiencia práctica',
                'enabled'=>false,
                'description'=>'Foros, comunidades y experiencia profesional. Preparada para una fase posterior.',
            ),
        );
    }

    public static function default_state() {
        return array(
            'version'=>self::VERSION,
            'lesson'=>self::LESSON_TECHNICAL,
            'status'=>'stopped',
            'enabled'=>0,
            'queue'=>array(),
            'cursor'=>0,
            'processed'=>0,
            'learned'=>0,
            'review'=>0,
            'errors'=>0,
            'batch_size'=>1,
            'last_duration'=>0.0,
            'last_term_id'=>0,
            'last_activity_at'=>0,
            'started_at'=>0,
            'completed_at'=>0,
            'not_before'=>0,
            'last_message'=>'',
            'last_error'=>'',
        );
    }

    public static function state() {
        $stored = get_option(self::STATE_OPTION, array());
        $state = wp_parse_args(is_array($stored) ? $stored : array(), self::default_state());
        $state['queue'] = isset($state['queue']) && is_array($state['queue']) ? array_values(array_filter(array_map('absint', $state['queue']))) : array();
        $state['cursor'] = min(absint($state['cursor']), count($state['queue']));
        return $state;
    }

    public static function save_state($changes) {
        $state = self::state();
        foreach ((array) $changes as $key=>$value) {
            if (array_key_exists($key, $state)) $state[$key] = $value;
        }
        $state['version'] = self::VERSION;
        update_option(self::STATE_OPTION, $state, false);
        return $state;
    }

    public static function category_states() {
        $rows = get_option(self::CATEGORY_STATE_OPTION, array());
        return is_array($rows) ? $rows : array();
    }

    public static function set_category_state($term_id, $status, $changes = array()) {
        $term_id = absint($term_id);
        if (!$term_id) return array();
        $allowed = array('pendiente','investigando','aprendido','revisar','error');
        $status = sanitize_key((string) $status);
        if (!in_array($status, $allowed, true)) $status = 'pendiente';
        $all = self::category_states();
        $current = isset($all[$term_id]) && is_array($all[$term_id]) ? $all[$term_id] : array();
        $all[$term_id] = array_merge($current, array(
            'term_id'=>$term_id,
            'status'=>$status,
            'updated_at'=>time(),
        ), (array) $changes);
        update_option(self::CATEGORY_STATE_OPTION, $all, false);
        return $all[$term_id];
    }

    public static function category_state($term_id) {
        $term_id = absint($term_id);
        $all = self::category_states();
        if (isset($all[$term_id]) && is_array($all[$term_id])) return $all[$term_id];
        return array('term_id'=>$term_id,'status'=>'pendiente','updated_at'=>0,'last_error'=>'');
    }

    public static function provider() {
        if (self::$provider instanceof SEO_Ingeniero_Search_Provider) return self::$provider;
        $provider = apply_filters('seo_ingeniero_search_provider', new SEO_Ingeniero_SerpApi_Provider());
        if (!($provider instanceof SEO_Ingeniero_Search_Provider)) {
            return new WP_Error('ingeniero_provider_invalid', 'El provider de búsqueda de Ingeniero no implementa la interfaz requerida.');
        }
        self::$provider = $provider;
        return self::$provider;
    }

    public static function category_candidates($limit = 20) {
        global $wpdb;
        $limit = max(1, min(100, absint($limit)));
        $excluded = array_filter(array_map('absint', (array) apply_filters(
            'seo_ingeniero_excluded_term_ids',
            array(absint(get_option('default_product_cat', 0)))
        )));
        $where_excluded = $excluded ? ' AND t.term_id NOT IN (' . implode(',', $excluded) . ')' : '';

        $rows = (array) $wpdb->get_results(
            "SELECT t.term_id,t.name,tt.count
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id AND tt.taxonomy='product_cat'
             WHERE tt.count>0 {$where_excluded}
             ORDER BY tt.count DESC,t.name ASC
             LIMIT {$limit}",
            ARRAY_A
        );
        return $rows;
    }

    public static function prepare_lesson($limit = 20, $only_missing = true) {
        $candidates = self::category_candidates($limit);
        $stats = SEO_Ingeniero_DB::category_stats_map(self::LESSON_TECHNICAL);
        $queue = array();
        foreach ($candidates as $row) {
            $term_id = absint($row['term_id'] ?? 0);
            if (!$term_id) continue;
            if ($only_missing && !empty($stats[$term_id]['knowledge'])) continue;
            $queue[] = $term_id;
        }
        if (!$queue && $only_missing) {
            foreach ($candidates as $row) {
                $term_id = absint($row['term_id'] ?? 0);
                if ($term_id) $queue[] = $term_id;
            }
        }

        $state = self::default_state();
        $state['queue'] = $queue;
        $state['status'] = $queue ? 'prepared' : 'stopped';
        $state['last_message'] = $queue
            ? sprintf('L1 preparada con %d categorías piloto.', count($queue))
            : 'No hay categorías elegibles para preparar.';
        foreach ($queue as $term_id) {
            self::set_category_state($term_id, 'pendiente', array('last_error'=>''));
        }
        update_option(self::STATE_OPTION, $state, false);
        return $state;
    }

    public static function start() {
        $state = self::state();
        if (!$state['queue'] || absint($state['cursor'] ?? 0) >= count((array) $state['queue'])) {
            $state = self::prepare_lesson(20, true);
        }
        if (!$state['queue']) return new WP_Error('ingeniero_empty_queue', 'No hay categorías preparadas.');

        $state = self::save_state(array(
            'enabled'=>1,
            'status'=>'running',
            'started_at'=>$state['started_at'] ?: time(),
            'completed_at'=>0,
            'not_before'=>0,
            'last_error'=>'',
            'last_message'=>'Investigación iniciada. El Gestor de procesos continuará por categorías.',
        ));
        self::dispatch(0);
        return $state;
    }

    public static function stop() {
        self::clear_fallback();
        return self::save_state(array(
            'enabled'=>0,
            'status'=>'stopped',
            'not_before'=>0,
            'last_message'=>'Investigación detenida manualmente.',
        ));
    }

    public static function is_pending() {
        $state = self::state();
        return !empty($state['enabled'])
            && 'running' === sanitize_key((string) ($state['status'] ?? ''))
            && absint($state['cursor'] ?? 0) < count((array) $state['queue']);
    }

    public static function progress() {
        $state = self::state();
        $total = count((array) $state['queue']);
        $processed = min($total, absint($state['cursor']));
        return array(
            'total'=>$total,
            'processed'=>$processed,
            'pending'=>max(0,$total-$processed),
            'percentage'=>$total ? round(($processed/$total)*100,1) : 0,
        );
    }

    public static function next_term_id() {
        $state = self::state();
        $cursor = absint($state['cursor']);
        return isset($state['queue'][$cursor]) ? absint($state['queue'][$cursor]) : 0;
    }

    public static function reinvestigate_category($term_id) {
        $term_id = absint($term_id);
        $term = $term_id ? get_term($term_id, 'product_cat') : null;
        if (!$term || is_wp_error($term)) return new WP_Error('ingeniero_term_missing', 'La categoría no existe.');

        $state = self::state();
        $remaining = array_slice((array) $state['queue'], absint($state['cursor'] ?? 0));
        $remaining = array_values(array_diff(array_map('absint', $remaining), array($term_id)));
        array_unshift($remaining, $term_id);

        $state = self::default_state();
        $state['queue'] = $remaining;
        $state['enabled'] = 1;
        $state['status'] = 'running';
        $state['started_at'] = time();
        $state['last_message'] = 'Reinvestigación encolada para ' . (string) $term->name . '.';
        update_option(self::STATE_OPTION, $state, false);

        self::set_category_state($term_id, 'pendiente', array('last_error'=>'','reinvestigate'=>1));
        self::dispatch(0);
        return array('queued'=>true,'term_id'=>$term_id,'category'=>(string)$term->name);
    }

    public static function research_category($term_id, $force = false) {
        $term_id = absint($term_id);
        $term = $term_id ? get_term($term_id, 'product_cat') : null;
        if (!$term || is_wp_error($term)) return new WP_Error('ingeniero_term_missing', 'La categoría no existe.');

        $name = trim((string) $term->name);
        if ($name === '') return new WP_Error('ingeniero_term_name', 'La categoría no tiene nombre.');

        $settings = SEO_Ingeniero_SerpApi_Provider::settings();
        $provider = self::provider();
        if (is_wp_error($provider)) return $provider;

        $queries = self::technical_queries($name);
        $queries = array_slice($queries, 0, max(1, absint($settings['queries_per_category'] ?? 3)));
        $results = array();
        $seen = array();
        $api_queries = 0;

        foreach ($queries as $query_type=>$query) {
            $response = $provider->search($query, array('term_id'=>$term_id,'lesson'=>self::LESSON_TECHNICAL,'query_type'=>$query_type));
            if (is_wp_error($response)) return $response;
            $api_queries++;
            foreach ((array) ($response['results'] ?? array()) as $row) {
                $url = esc_url_raw((string) ($row['url'] ?? ''));
                if ($url === '') continue;
                $key = strtolower($url);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $row['query_type'] = $query_type;
                $results[] = $row;
            }
        }

        if (!$results) {
            return new WP_Error('ingeniero_no_results', 'No se han encontrado fuentes externas útiles para esta categoría.');
        }

        usort($results, array(__CLASS__, 'compare_result_priority'));
        $fetch_limit = max(0, absint($settings['fetch_pages'] ?? 4));
        $source_rows = array();
        $fetched = 0;

        foreach ($results as $row) {
            if (count($source_rows) >= 12) break;
            $classification = self::classify_source((string) ($row['url'] ?? ''), (string) ($row['title'] ?? ''));
            $is_pdf = !empty($classification['pdf']);
            $page = array('status'=>0,'text'=>'','content_hash'=>'');
            if (!$is_pdf && $fetched < $fetch_limit) {
                $page = self::fetch_page_text((string) $row['url']);
                $fetched++;
            }

            $status = $is_pdf ? 'pdf_pending' : (!empty($page['text']) ? 'fetched' : 'search_only');
            $source_id = SEO_Ingeniero_DB::upsert_source(array(
                'term_id'=>$term_id,
                'lesson'=>self::LESSON_TECHNICAL,
                'url'=>$row['url'],
                'domain'=>$classification['domain'],
                'title'=>$row['title'],
                'source_type'=>$classification['source_type'],
                'trust_level'=>$classification['trust_level'],
                'published_at'=>$row['date'] ?? '',
                'retrieved_at'=>gmdate('Y-m-d H:i:s'),
                'http_status'=>$page['status'] ?? 0,
                'content_hash'=>$page['content_hash'] ?? '',
                'status'=>$status,
                'metadata'=>array(
                    'position'=>absint($row['position'] ?? 0),
                    'query_type'=>sanitize_key((string) ($row['query_type'] ?? '')),
                    'snippet'=>self::limit_words((string) ($row['snippet'] ?? ''), 24),
                    'pdf_pending'=>$is_pdf ? 1 : 0,
                ),
            ));
            if (is_wp_error($source_id)) continue;

            $source_rows[] = array(
                'id'=>absint($source_id),
                'url'=>(string) $row['url'],
                'title'=>(string) $row['title'],
                'snippet'=>(string) ($row['snippet'] ?? ''),
                'text'=>(string) ($page['text'] ?? ''),
                'source_type'=>$classification['source_type'],
                'trust_level'=>$classification['trust_level'],
                'query_type'=>sanitize_key((string) ($row['query_type'] ?? '')),
                'pdf'=>$is_pdf,
            );
        }

        if (!$source_rows) {
            return new WP_Error('ingeniero_sources_save', 'No se pudo guardar ninguna fuente útil.');
        }

        $knowledge = self::build_knowledge($term_id, $name, $source_rows);
        $active = 0;
        $review = 0;
        foreach ($knowledge as $item) {
            $saved = SEO_Ingeniero_DB::upsert_knowledge($item);
            if (is_wp_error($saved)) continue;
            if ('active' === $item['status']) $active++;
            else $review++;
        }

        return array(
            'term_id'=>$term_id,
            'category'=>$name,
            'sources'=>count($source_rows),
            'knowledge'=>count($knowledge),
            'active'=>$active,
            'review'=>$review,
            'api_queries'=>$api_queries,
        );
    }

    private static function technical_queries($name) {
        return array(
            'definition' => '"' . $name . '" qué es funcionamiento tipos aplicaciones ficha técnica',
            'safety' => '"' . $name . '" manual seguridad mantenimiento compatibilidad limitaciones',
            'regulation' => '"' . $name . '" normativa seguridad especificaciones técnicas problemas habituales',
        );
    }

    private static function compare_result_priority($a, $b) {
        $ca = self::classify_source((string) ($a['url'] ?? ''), (string) ($a['title'] ?? ''));
        $cb = self::classify_source((string) ($b['url'] ?? ''), (string) ($b['title'] ?? ''));
        $weights = array('high'=>4,'medium_high'=>3,'medium'=>2,'low'=>1);
        $wa = $weights[$ca['trust_level']] ?? 0;
        $wb = $weights[$cb['trust_level']] ?? 0;
        if ($wa !== $wb) return $wb <=> $wa;
        return absint($a['position'] ?? 999) <=> absint($b['position'] ?? 999);
    }

    public static function classify_source($url, $title = '') {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
        $haystack = strtolower($host . ' ' . $path . ' ' . $title);
        $pdf = (bool) preg_match('/\.pdf$/i', $path);

        $government = preg_match('/(\.gob\.es$|\.gov$|\.gov\.|boe\.es$|europa\.eu$|eur-lex\.europa\.eu$|insst\.es$)/', $host);
        $standards = preg_match('/(iso\.org$|iec\.ch$|une\.org$|aenor\.com$)/', $host);
        $manual = $pdf || preg_match('/\b(manual|datasheet|ficha-tecnica|technical-data|documentation|documentacion)\b/', $haystack);

        if ($government || $standards) {
            return array('domain'=>$host,'source_type'=>$government?'official_body':'standards_body','trust_level'=>'high','pdf'=>$pdf);
        }
        if ($manual) {
            return array('domain'=>$host,'source_type'=>'technical_document','trust_level'=>'medium_high','pdf'=>$pdf);
        }
        if (preg_match('/(foro|forum|reddit|quora|facebook|youtube|tiktok)/', $haystack)) {
            return array('domain'=>$host,'source_type'=>'community','trust_level'=>'low','pdf'=>$pdf);
        }
        if (preg_match('/(blog|magazine|revista|academy|institut|universit|ingenier|tecnic)/', $haystack)) {
            return array('domain'=>$host,'source_type'=>'technical_specialist','trust_level'=>'medium_high','pdf'=>$pdf);
        }
        return array('domain'=>$host,'source_type'=>'specialized_web','trust_level'=>'medium','pdf'=>$pdf);
    }

    private static function fetch_page_text($url) {
        $response = wp_safe_remote_get($url, array(
            'timeout'=>18,
            'redirection'=>3,
            'limit_response_size'=>400000,
            'headers'=>array('User-Agent'=>'SEO-Taxonomy-Ingeniero/0.1.0'),
        ));
        if (is_wp_error($response)) return array('status'=>0,'text'=>'','content_hash'=>'');
        $status = absint(wp_remote_retrieve_response_code($response));
        $type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if ($status < 200 || $status >= 300 || (strpos($type,'text/html') === false && strpos($type,'text/plain') === false)) {
            return array('status'=>$status,'text'=>'','content_hash'=>'');
        }
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') return array('status'=>$status,'text'=>'','content_hash'=>'');
        $hash = hash('sha256', $body);
        $body = preg_replace('#<(script|style|noscript|svg|nav|footer|header)[^>]*>.*?</\1>#is', ' ', $body);
        $text = html_entity_decode(wp_strip_all_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim((string) $text);
        return array(
            'status'=>$status,
            'text'=>self::limit_text($text, 12000),
            'content_hash'=>$hash,
        );
    }

    private static function build_knowledge($term_id, $category_name, $sources) {
        $definitions = array(
            'definition'=>array('qué es','que es','definición','definicion','se utiliza','es un','es una'),
            'function'=>array('funciona','funcionamiento','función','funcion','principio'),
            'application'=>array('aplicación','aplicacion','uso','utiliza','emplea','taller','industria'),
            'type'=>array('tipos','tipo','variante','modelos','clases'),
            'compatibility'=>array('compatible','compatibilidad','adecuado','admite','capacidad','fluido'),
            'limitation'=>array('limitación','limitacion','no debe','no se recomienda','restricción','restriccion'),
            'maintenance'=>array('mantenimiento','limpieza','limpiar','revisión','revision','conservar'),
            'safety'=>array('seguridad','riesgo','protección','proteccion','precaución','precaucion','advertencia'),
            'problem'=>array('problema','fallo','avería','averia','obstrucción','obstruccion','fuga'),
            'terminology'=>array('denomina','también llamado','tambien llamado','término','termino'),
            'regulation'=>array('normativa','norma','reglamento','directiva','UNE','ISO','CE '),
        );
        $weights = array('high'=>0.92,'medium_high'=>0.76,'medium'=>0.60,'low'=>0.35);
        $out = array();

        foreach ($definitions as $type=>$keywords) {
            $evidence = array();
            $source_ids = array();
            $domains = array();
            $scores = array();
            $has_high = false;

            foreach ($sources as $source) {
                if (!empty($source['pdf'])) continue;
                $pool = trim((string) ($source['snippet'] ?? '') . ' ' . (string) ($source['text'] ?? ''));
                if ($pool === '') continue;
                $sentence = self::best_sentence($pool, $keywords);
                if ($sentence === '') continue;

                $trust = sanitize_key((string) ($source['trust_level'] ?? 'medium'));
                $score = $weights[$trust] ?? 0.5;
                $has_high = $has_high || $trust === 'high';
                $source_ids[] = absint($source['id'] ?? 0);
                $domains[] = strtolower((string) wp_parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST));
                $scores[] = $score;
                $evidence[] = array(
                    'source_id'=>absint($source['id'] ?? 0),
                    'source_url'=>esc_url_raw((string) ($source['url'] ?? '')),
                    'source_title'=>sanitize_text_field((string) ($source['title'] ?? '')),
                    'source_type'=>sanitize_key((string) ($source['source_type'] ?? '')),
                    'trust_level'=>$trust,
                    // Evidencia breve y trazable; no se usa como contenido editorial.
                    'evidence'=>self::limit_words($sentence, 24),
                );
                if (count($evidence) >= 4) break;
            }

            if (!$evidence) continue;
            $domains = array_values(array_unique(array_filter($domains)));
            $avg = $scores ? array_sum($scores)/count($scores) : 0;
            $confirmed = $has_high || (count($domains) >= 2 && count($evidence) >= 2 && $avg >= 0.60);
            $status = $confirmed ? 'active' : 'review';
            $confidence = min(0.98, max(0.20, $avg + (count($domains)>=2 ? 0.06 : 0) + ($has_high ? 0.05 : 0)));

            // El resumen es una síntesis propia. No concatena ni copia frases de
            // las fuentes: las citas breves quedan separadas en "facts" con URL.
            $summary = self::original_summary($category_name, $type, $evidence, count($domains), $has_high);

            $out[] = array(
                'term_id'=>$term_id,
                'lesson'=>self::LESSON_TECHNICAL,
                'knowledge_type'=>$type,
                'concept'=>$category_name,
                'summary'=>$summary,
                'facts'=>$evidence,
                'tags'=>array($category_name,$type),
                'source_ids'=>array_values(array_unique(array_filter($source_ids))),
                'confidence'=>round($confidence,5),
                'status'=>$status,
            );
        }

        return $out;
    }

    private static function original_summary($category_name, $type, $evidence, $domain_count, $has_high) {
        $labels = array(
            'definition'=>'definición y alcance técnico',
            'function'=>'principio de funcionamiento',
            'application'=>'aplicaciones y usos habituales',
            'type'=>'tipos y variantes',
            'compatibility'=>'compatibilidades y condiciones de uso',
            'limitation'=>'limitaciones y restricciones',
            'maintenance'=>'mantenimiento y conservación',
            'safety'=>'seguridad y precauciones',
            'problem'=>'problemas y fallos habituales',
            'terminology'=>'terminología técnica',
            'regulation'=>'normativa y referencias técnicas',
        );
        $label = $labels[$type] ?? str_replace('_',' ',(string)$type);

        $signals = array();
        foreach ((array) $evidence as $row) {
            $text = self::normalize_signal_text((string) ($row['evidence'] ?? ''));
            foreach (self::technical_signal_terms($text) as $term) {
                $signals[$term] = true;
                if (count($signals) >= 6) break 2;
            }
        }

        $summary = 'La revisión externa de ' . $category_name . ' aporta contexto sobre ' . $label . '. ';
        if ($signals) {
            $summary .= 'Las fuentes coinciden en conceptos como ' . implode(', ', array_keys($signals)) . '. ';
        }
        $summary .= 'La síntesis se ha elaborado a partir de ' . max(1, absint($domain_count)) . ' dominio(s) independiente(s)';
        if ($has_high) {
            $summary .= ', incluyendo al menos una fuente oficial o normativa';
        }
        $summary .= '. Las afirmaciones concretas deben verificarse en las referencias enlazadas antes de publicarse como contenido editorial.';
        return self::limit_text($summary, 700);
    }

    private static function normalize_signal_text($text) {
        $text = function_exists('remove_accents') ? remove_accents((string)$text) : (string)$text;
        $text = function_exists('mb_strtolower') ? mb_strtolower($text,'UTF-8') : strtolower($text);
        return preg_replace('/[^a-z0-9%+\-\. ]+/',' ', $text);
    }

    private static function technical_signal_terms($text) {
        $dictionary = array(
            'seguridad','mantenimiento','compatibilidad','presion','temperatura','potencia',
            'capacidad','caudal','velocidad','precision','tolerancia','material','diametro',
            'voltaje','corriente','bateria','motor','lubricacion','limpieza','proteccion',
            'norma','iso','une','ce','fluido','aceite','agua','aire','par','rpm',
        );
        $found = array();
        foreach ($dictionary as $term) {
            if (preg_match('/\b' . preg_quote($term,'/') . '\b/', $text)) $found[] = $term;
        }
        return $found;
    }

    private static function best_sentence($text, $keywords) {
        $text = preg_replace('/\s+/u', ' ', (string) $text);
        $sentences = preg_split('/(?<=[\.\!\?])\s+/u', trim($text));
        $best = '';
        $best_score = 0;
        foreach ((array) $sentences as $sentence) {
            $sentence = trim((string) $sentence);
            $len = function_exists('mb_strlen') ? mb_strlen($sentence,'UTF-8') : strlen($sentence);
            if ($len < 35 || $len > 520) continue;
            $hay = function_exists('mb_strtolower') ? mb_strtolower($sentence,'UTF-8') : strtolower($sentence);
            $score = 0;
            foreach ($keywords as $keyword) {
                $needle = function_exists('mb_strtolower') ? mb_strtolower($keyword,'UTF-8') : strtolower($keyword);
                if ($needle !== '' && strpos($hay,$needle) !== false) $score++;
            }
            if ($score > $best_score) {
                $best_score = $score;
                $best = $sentence;
            }
        }
        return $best_score > 0 ? self::limit_text($best, 500) : '';
    }

    public static function active_knowledge($term_id, $types = array()) {
        $rows = SEO_Ingeniero_DB::knowledge_for_category(absint($term_id), true, self::LESSON_TECHNICAL);
        $types = array_values(array_filter(array_map('sanitize_key', (array) $types)));
        if (!$types) return $rows;
        return array_values(array_filter($rows, static function($row) use ($types) {
            return in_array(sanitize_key((string) ($row['knowledge_type'] ?? '')), $types, true);
        }));
    }

    public static function category_confidence($term_id) {
        $stats = SEO_Ingeniero_DB::category_stats_map(self::LESSON_TECHNICAL);
        return round((float) ($stats[absint($term_id)]['avg_confidence'] ?? 0), 4);
    }

    public static function category_status($term_id) {
        return sanitize_key((string) (self::category_state(absint($term_id))['status'] ?? 'pendiente'));
    }

    public static function dispatch($delay = 0) {
        if (!self::is_pending()) return false;
        $delay = max(0, absint($delay));
        if (function_exists('seo_process_supervisor_settings')) {
            $manager = (array) seo_process_supervisor_settings();
            if (!empty($manager['enabled']) && !empty($manager['ingeniero'])) {
                self::clear_fallback();
                if (function_exists('seo_process_supervisor_nudge')) seo_process_supervisor_nudge($delay, 'ingeniero');
                if (function_exists('seo_process_supervisor_schedule_backup')) seo_process_supervisor_schedule_backup();
                return true;
            }
        }
        return self::schedule_fallback(max(1, $delay));
    }

    public static function schedule_fallback($delay = 30) {
        if (!self::is_pending()) return false;
        $when = time() + max(1, absint($delay));
        if (function_exists('as_schedule_single_action')) {
            $pending = function_exists('as_next_scheduled_action') ? as_next_scheduled_action('seo_ingeniero_worker_tick', array(), 'seo-ingeniero') : false;
            if (!$pending) {
                $id = as_schedule_single_action($when, 'seo_ingeniero_worker_tick', array(), 'seo-ingeniero', true, 20);
                if (absint($id) > 0) return true;
            } else {
                return true;
            }
        }
        if (false === wp_next_scheduled('seo_ingeniero_worker_tick')) {
            return (bool) wp_schedule_single_event($when, 'seo_ingeniero_worker_tick');
        }
        return true;
    }

    public static function clear_fallback() {
        if (function_exists('as_unschedule_all_actions')) as_unschedule_all_actions('seo_ingeniero_worker_tick', array(), 'seo-ingeniero');
        wp_clear_scheduled_hook('seo_ingeniero_worker_tick');
    }

    public static function limit_words($text, $limit = 24) {
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)));
        $limit = max(1, absint($limit));
        if ($text === '') return '';
        $words = preg_split('/\s+/u', $text);
        if (count($words) <= $limit) return $text;
        return implode(' ', array_slice($words, 0, $limit)) . '…';
    }

    public static function limit_text($text, $limit) {
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)));
        $limit = max(1, absint($limit));
        if (function_exists('mb_strlen') && mb_strlen($text,'UTF-8') > $limit) return rtrim(mb_substr($text,0,$limit,'UTF-8')) . '…';
        if (strlen($text) > $limit) return rtrim(substr($text,0,$limit)) . '…';
        return $text;
    }
}
