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
    const VERSION = '0.3.3';
    const STATE_OPTION = 'seo_ingeniero_state_v1';
    const CATEGORY_STATE_OPTION = 'seo_ingeniero_category_state_v1';
    const LESSON_TECHNICAL = 'l1_technical';
    const LESSON_PRACTICAL = 'l2_practical';
    const TARGET_ACTIVE_KNOWLEDGE_PER_CATEGORY = 4;

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

    public static function category_candidates($limit = 20, $only_missing = false) {
        $limit = absint($limit);

        // Ingeniero trabaja sobre TODAS las product_cat. Tener 0 productos no
        // elimina una categoría del inventario técnico; simplemente quedará
        // pendiente o sin conocimiento hasta que pueda investigarse.
        $terms = get_terms(array(
            'taxonomy'=>'product_cat',
            'hide_empty'=>false,
            'orderby'=>'count',
            'order'=>'DESC',
        ));
        if (is_wp_error($terms)) return array();

        $stats = $only_missing ? SEO_Ingeniero_DB::category_stats_map(self::LESSON_TECHNICAL) : array();
        $rows = array();
        foreach ((array) $terms as $term) {
            $term_id = absint($term->term_id ?? 0);
            if (!$term_id) continue;
            if ($only_missing && absint($stats[$term_id]['active'] ?? 0) >= self::TARGET_ACTIVE_KNOWLEDGE_PER_CATEGORY) continue;
            $rows[] = array(
                'term_id'=>$term_id,
                'name'=>(string) ($term->name ?? ''),
                'count'=>absint($term->count ?? 0),
            );
        }

        usort($rows, static function($a, $b) {
            $count_cmp = absint($b['count'] ?? 0) <=> absint($a['count'] ?? 0);
            if (0 !== $count_cmp) return $count_cmp;
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    public static function prepare_lesson($limit = 20, $only_missing = true) {
        $limit = absint($limit);
        if ($limit > 0) $limit = max(1, min(1000, $limit));
        $candidates = self::category_candidates($limit, $only_missing);
        $queue = array();
        foreach ($candidates as $row) {
            $term_id = absint($row['term_id'] ?? 0);
            if ($term_id) $queue[] = $term_id;
        }

        $state = self::default_state();
        $state['queue'] = $queue;
        $state['status'] = $queue ? 'prepared' : 'stopped';
        $state['last_message'] = $queue
            ? sprintf('L1 preparada con %d categorías por investigar/completar.',count($queue))
            : 'No hay categorías por debajo del objetivo técnico.';
        foreach ($queue as $term_id) {
            self::set_category_state($term_id, 'pendiente', array('last_error'=>''));
        }
        update_option(self::STATE_OPTION, $state, false);
        return $state;
    }

    public static function start() {
        $state = self::state();
        if (!$state['queue'] || absint($state['cursor'] ?? 0) >= count((array) $state['queue'])) {
            // Al iniciar/reanudar se prepara todo lo pendiente de una vez.
            // El worker seguirá procesando una categoría por ciclo, pero no
            // obliga al usuario a preparar lotes manuales de 20.
            $state = self::prepare_lesson(0, true);
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

    public static function refresh_editorial_category($term_id, $force = false) {
        $term_id = absint($term_id);
        $term = $term_id ? get_term($term_id, 'product_cat') : null;
        if (!$term || is_wp_error($term)) {
            return new WP_Error('ingeniero_editorial_term', 'La categoría editorial no existe.');
        }

        $knowledge = self::active_knowledge($term_id);
        if (!$knowledge) {
            $existing = SEO_Ingeniero_DB::editorial_rows(array('term_id'=>$term_id,'page'=>1,'per_page'=>100));
            foreach ((array) ($existing['rows'] ?? array()) as $row) {
                SEO_Ingeniero_DB::update_editorial(absint($row['id'] ?? 0), array(
                    'status'=>!empty($row['post_id']) ? 'needs_update' : 'review',
                    'recommended_action'=>'NEEDS_REVIEW',
                ));
            }
            return array('term_id'=>$term_id,'proposals'=>array(),'skipped'=>'no_active_knowledge');
        }

        $groups = self::editorial_groups($knowledge);
        $proposals = array();
        $current_topic_keys = array();

        foreach ($groups as $topic_key=>$rows) {
            $current_topic_keys[] = sanitize_key((string) $topic_key);
            $knowledge_ids = array();
            $source_ids = array();
            $confidence = array();
            $summary_parts = array();
            foreach ((array) $rows as $row) {
                $knowledge_ids[] = absint($row['id'] ?? 0);
                $source_ids = array_merge($source_ids, (array) ($row['source_ids'] ?? array()));
                $confidence[] = (float) ($row['confidence'] ?? 0);
                if (!empty($row['summary'])) $summary_parts[] = (string) $row['summary'];
            }
            $knowledge_ids = array_values(array_unique(array_filter(array_map('absint', $knowledge_ids))));
            $source_ids = array_values(array_unique(array_filter(array_map('absint', $source_ids))));
            sort($knowledge_ids);
            sort($source_ids);

            $source_hash = self::editorial_source_hash($rows, $source_ids);
            $existing = SEO_Ingeniero_DB::editorial_get_by_topic($term_id, $topic_key);
            if (
                !$force
                && $existing
                && (string) ($existing['source_hash'] ?? '') === $source_hash
            ) {
                $proposals[] = array_merge($existing, array('unchanged'=>true));
                continue;
            }

            $title = self::editorial_title((string) $term->name, $topic_key);
            $summary = self::limit_text(implode(' ', $summary_parts), 3000);
            $coverage = class_exists('SEO_Editorial_Coverage')
                ? SEO_Editorial_Coverage::find_technical($term_id, $title, $summary)
                : array('status'=>'uncovered','post_id'=>0,'matches'=>array());

            $avg_confidence = $confidence ? array_sum($confidence) / count($confidence) : 0.0;
            $action = self::editorial_action($coverage, count($knowledge_ids), count($source_ids), $avg_confidence);
            $status = $existing ? (string) ($existing['status'] ?? 'candidate') : 'candidate';
            if ('NEEDS_REVIEW' === $action && !in_array($status,array('draft','published','needs_update'),true)) {
                $status = 'review';
            } elseif ('closed' === $status && !in_array($action,array('NO_ACTION'),true)) {
                // Un dossier cerrado manualmente sólo se reactiva si la
                // recomendación cambia materialmente.
                $status = 'review';
            }

            $id = SEO_Ingeniero_DB::upsert_editorial(array(
                'term_id'            => $term_id,
                'topic_key'          => $topic_key,
                'knowledge_ids'      => $knowledge_ids,
                'source_ids'         => $source_ids,
                'source_hash'        => $source_hash,
                'suggested_title'    => $title,
                'coverage_status'    => (string) ($coverage['status'] ?? 'uncovered'),
                'recommended_action' => $action,
                'coverage'           => $coverage,
                'status'             => $status,
            ));
            if (is_wp_error($id)) {
                $proposals[] = array('error'=>$id->get_error_message(),'topic_key'=>$topic_key);
                continue;
            }
            $saved_dossier = SEO_Ingeniero_DB::editorial_get($id);
            if (
                !empty($saved_dossier['post_id'])
                && class_exists('SEO_Ingeniero_Posts')
                && method_exists('SEO_Ingeniero_Posts','sync_pending_update')
            ) {
                SEO_Ingeniero_Posts::sync_pending_update($id);
                $saved_dossier = SEO_Ingeniero_DB::editorial_get($id);
            }
            $proposals[] = $saved_dossier;
        }

        $existing_rows = SEO_Ingeniero_DB::editorial_rows(array('term_id'=>$term_id,'page'=>1,'per_page'=>100));
        foreach ((array) ($existing_rows['rows'] ?? array()) as $row) {
            $topic_key = sanitize_key((string) ($row['topic_key'] ?? ''));
            if ($topic_key === '' || in_array($topic_key, $current_topic_keys, true)) continue;
            SEO_Ingeniero_DB::update_editorial(absint($row['id'] ?? 0),array(
                'status'=>!empty($row['post_id']) ? 'needs_update' : 'closed',
                'recommended_action'=>!empty($row['post_id']) ? 'IMPROVE_POST' : 'NO_ACTION',
            ));
        }

        return array('term_id'=>$term_id,'proposals'=>$proposals);
    }

    public static function editorial_brief($editorial_id) {
        $dossier = SEO_Ingeniero_DB::editorial_get(absint($editorial_id));
        if (!$dossier) return new WP_Error('ingeniero_editorial_missing', 'No existe el dossier editorial.');

        $term_id = absint($dossier['term_id'] ?? 0);
        $all_knowledge = self::active_knowledge($term_id);
        $wanted = array_fill_keys(array_map('absint', (array) ($dossier['knowledge_ids'] ?? array())), true);
        $knowledge = array();
        $must_cover = array();
        foreach ($all_knowledge as $row) {
            $id = absint($row['id'] ?? 0);
            if (!$id || !isset($wanted[$id])) continue;
            $knowledge[] = array(
                'id'=>$id,
                'knowledge_type'=>(string) ($row['knowledge_type'] ?? ''),
                'concept'=>(string) ($row['concept'] ?? ''),
                'summary'=>(string) ($row['summary'] ?? ''),
                'confidence'=>(float) ($row['confidence'] ?? 0),
                'status'=>(string) ($row['status'] ?? ''),
                'facts'=>(array) ($row['facts'] ?? array()),
                'source_ids'=>array_values(array_filter(array_map('absint', (array) ($row['source_ids'] ?? array())))),
            );
            $label = trim((string) ($row['concept'] ?? ''));
            if ($label === '') $label = str_replace('_',' ',(string) ($row['knowledge_type'] ?? ''));
            if ($label !== '') $must_cover[] = $label . ' · ' . str_replace('_',' ',(string) ($row['knowledge_type'] ?? ''));
        }

        $qa_items = array_map(array(__CLASS__,'editorial_qa_item'),$knowledge);
        $sources = SEO_Ingeniero_DB::sources_by_ids((array)($dossier['source_ids'] ?? array()));
        $term = $term_id ? get_term($term_id, 'product_cat') : null;
        $internal_links = self::editorial_internal_links($term_id);
        $post_id = absint($dossier['post_id'] ?? 0);
        $metrics = ($post_id && function_exists('seo_post_reports_get_summary'))
            ? (array) seo_post_reports_get_summary($post_id, 28)
            : array();

        return array(
            'dossier'=>$dossier,
            'category'=>array(
                'term_id'=>$term_id,
                'name'=>$term && !is_wp_error($term) ? (string) $term->name : '',
            ),
            'knowledge'=>$knowledge,
            'qa_items'=>$qa_items,
            'knowledge_count'=>count($knowledge),
            'qa_count'=>count($qa_items),
            'sources'=>array_map(static function($row) {
                return array(
                    'id'=>absint($row['id'] ?? 0),
                    'title'=>(string) ($row['title'] ?? ''),
                    'url'=>(string) ($row['url'] ?? ''),
                    'source_type'=>(string) ($row['source_type'] ?? ''),
                    'trust_level'=>(string) ($row['trust_level'] ?? ''),
                    'retrieved_at'=>(string) ($row['retrieved_at'] ?? ''),
                );
            }, $sources),
            'must_cover'=>array_values(array_unique($must_cover)),
            'internal_links'=>$internal_links,
            'metrics_28d'=>$metrics,
            'analista_url'=>function_exists('seo_analista_admin_url') ? seo_analista_admin_url(array('analista_view'=>'donde_estamos','analista_days'=>28)) : '',
            'coverage'=>(array) ($dossier['coverage'] ?? array()),
            'verification_warning'=>'La síntesis interna de Ingeniero no debe publicarse literalmente como afirmación si las fuentes enlazadas no la respaldan.',
        );
    }

    private static function editorial_internal_links($term_id) {
        $term_id = absint($term_id);
        if (!$term_id) return array('products'=>array(),'categories'=>array());

        $products = array();
        $ids = get_posts(array(
            'post_type'=>'product',
            'post_status'=>'publish',
            'posts_per_page'=>8,
            'fields'=>'ids',
            'orderby'=>'modified',
            'order'=>'DESC',
            'no_found_rows'=>true,
            'tax_query'=>array(array(
                'taxonomy'=>'product_cat',
                'field'=>'term_id',
                'terms'=>array($term_id),
            )),
        ));
        foreach ((array) $ids as $post_id) {
            $post_id = absint($post_id);
            if (!$post_id) continue;
            $products[] = array(
                'id'=>$post_id,
                'title'=>(string) get_the_title($post_id),
                'url'=>(string) get_permalink($post_id),
            );
        }

        $category_ids = array($term_id);
        $ancestors = array_map('absint', (array) get_ancestors($term_id, 'product_cat', 'taxonomy'));
        $category_ids = array_merge($category_ids, array_slice($ancestors, 0, 3));
        $children = get_terms(array(
            'taxonomy'=>'product_cat',
            'hide_empty'=>true,
            'parent'=>$term_id,
            'number'=>5,
            'orderby'=>'count',
            'order'=>'DESC',
        ));
        if (!is_wp_error($children)) {
            foreach ((array) $children as $child) $category_ids[] = absint($child->term_id ?? 0);
        }

        $categories = array();
        foreach (array_values(array_unique(array_filter($category_ids))) as $category_id) {
            $category = get_term($category_id, 'product_cat');
            if (!$category || is_wp_error($category)) continue;
            $categories[] = array(
                'term_id'=>$category_id,
                'name'=>(string) $category->name,
                'url'=>(string) get_term_link($category),
            );
        }

        return array(
            'products'=>$products,
            'categories'=>$categories,
        );
    }

    private static function editorial_groups(array $knowledge) {
        // 0.3.3: una product_cat = un dossier editorial. La investigación ya
        // decidió qué knowledge está active; la capa editorial no vuelve a
        // filtrar ni a dividir ese conocimiento por "masa" de familia.
        $rows = array_values(array_filter($knowledge,static function($row){
            return sanitize_key((string)($row['status'] ?? 'active')) === 'active';
        }));
        return $rows ? array('technical-overview'=>$rows) : array();
    }

    private static function editorial_title($category_name, $topic_key) {
        $category_name = sanitize_text_field((string) $category_name);
        $map = array(
            'technical-overview' => 'Guía técnica de %s: funcionamiento, elección y mantenimiento',
            'fundamentals'       => '%s: funcionamiento, tipos y conceptos técnicos',
            'selection'          => 'Cómo elegir %s: compatibilidad, aplicaciones y límites',
            'maintenance'        => 'Mantenimiento y problemas de %s: diagnóstico y cuidados',
            'safety'             => 'Seguridad y normativa de %s: requisitos y precauciones',
        );
        $format = $map[$topic_key] ?? '%s: guía técnica especializada';
        return sprintf($format, $category_name);
    }

    private static function editorial_source_hash(array $rows, array $source_ids) {
        $parts = array();
        foreach ($rows as $row) {
            $parts[] = implode('|', array(
                absint($row['id'] ?? 0),
                sanitize_key((string) ($row['knowledge_type'] ?? '')),
                (string) ($row['updated_at'] ?? ''),
                number_format((float) ($row['confidence'] ?? 0), 5, '.', ''),
                (string) ($row['summary'] ?? ''),
            ));
        }
        sort($parts);
        $source_ids = array_values(array_unique(array_filter(array_map('absint', $source_ids))));
        sort($source_ids);
        return hash('sha256', implode('||', $parts) . '::' . implode(',', $source_ids));
    }

    private static function editorial_action(array $coverage, $knowledge_count, $source_count, $avg_confidence) {
        $knowledge_count = absint($knowledge_count);
        if ($knowledge_count < 1) return 'NEEDS_REVIEW';

        // Fuentes/confianza son indicadores para Editora; nunca ocultan un
        // knowledge que Ingeniero ya considera active.
        $status = sanitize_key((string)($coverage['status'] ?? 'uncovered'));
        if (in_array($status,array('duplicate','conflict'),true)) return 'MERGE_CONTENT';
        if ($status === 'covered') return 'NO_ACTION';
        if (in_array($status,array('partial_coverage','weak_coverage'),true) && absint($coverage['post_id'] ?? 0)) {
            return 'IMPROVE_POST';
        }
        return 'CREATE_POST';
    }

    public static function editorial_question_for_knowledge(array $row) {
        $type = sanitize_key((string)($row['knowledge_type'] ?? ''));
        $concept = trim((string)($row['concept'] ?? ''));
        if ($concept === '') $concept = 'esta categoría';

        $templates = array(
            'definition'=>'¿Qué es %s y qué aspectos técnicos conviene conocer?',
            'function'=>'¿Cómo funciona %s?',
            'application'=>'¿Para qué aplicaciones se utiliza %s?',
            'type'=>'¿Qué tipos o variantes de %s existen?',
            'compatibility'=>'¿Qué compatibilidades o requisitos hay que comprobar en %s?',
            'limitation'=>'¿Qué limitaciones tiene %s?',
            'maintenance'=>'¿Qué mantenimiento requiere %s?',
            'safety'=>'¿Qué precauciones de seguridad deben tenerse en cuenta con %s?',
            'problem'=>'¿Qué problemas habituales pueden aparecer con %s?',
            'terminology'=>'¿Qué términos técnicos conviene conocer sobre %s?',
            'regulation'=>'¿Qué normativa o requisitos técnicos afectan a %s?',
            'selection'=>'¿Qué criterios ayudan a elegir %s?',
            'performance'=>'¿Qué prestaciones o rendimiento deben evaluarse en %s?',
            'capacity'=>'¿Qué capacidad debe considerarse en %s?',
            'maneuverability'=>'¿Qué aspectos de maniobrabilidad son importantes en %s?',
            'connectivity'=>'¿Qué conexiones o interfaces debe comprobarse en %s?',
            'design'=>'¿Qué aspectos de diseño son relevantes en %s?',
            'comparison'=>'¿Qué diferencias técnicas conviene comparar en %s?',
            'protection'=>'¿Qué requisitos de protección deben considerarse en %s?',
        );
        $template = $templates[$type] ?? '¿Qué información técnica relevante hay que conocer sobre %s?';
        return sprintf($template,$concept);
    }

    public static function editorial_qa_item(array $row) {
        $id = absint($row['id'] ?? 0);
        $source_ids = array_values(array_unique(array_filter(array_map('absint',(array)($row['source_ids'] ?? array())))));
        sort($source_ids,SORT_NUMERIC);
        $answer = trim((string)($row['summary'] ?? ''));
        $question = self::editorial_question_for_knowledge($row);
        $item_hash = hash('sha256',wp_json_encode(array(
            'id'=>$id,
            'knowledge_type'=>sanitize_key((string)($row['knowledge_type'] ?? '')),
            'concept'=>(string)($row['concept'] ?? ''),
            'question'=>$question,
            'answer'=>$answer,
            'confidence'=>round((float)($row['confidence'] ?? 0),5),
            'source_ids'=>$source_ids,
            'updated_at'=>(string)($row['updated_at'] ?? ''),
        ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

        return array(
            'item_id'=>'knowledge:' . $id,
            'knowledge_id'=>$id,
            'knowledge_type'=>sanitize_key((string)($row['knowledge_type'] ?? '')),
            'concept'=>(string)($row['concept'] ?? ''),
            'question'=>$question,
            'answer'=>$answer,
            'confidence'=>(float)($row['confidence'] ?? 0),
            'facts'=>(array)($row['facts'] ?? array()),
            'source_ids'=>$source_ids,
            'item_hash'=>$item_hash,
            'updated_at'=>(string)($row['updated_at'] ?? ''),
            'status'=>(string)($row['status'] ?? ''),
        );
    }

    /**
     * Helpers deterministas para la batería funcional de Ingeniero.
     * No escriben datos ni consultan servicios externos.
     */
    public static function editorial_groups_for_test(array $knowledge) {
        $groups = self::editorial_groups($knowledge);
        $out = array();
        foreach ($groups as $key=>$rows) $out[$key] = count((array)$rows);
        return $out;
    }

    public static function editorial_qa_for_test(array $row) {
        return self::editorial_qa_item($row);
    }

    public static function editorial_source_hash_for_test(array $rows, array $source_ids) {
        return self::editorial_source_hash($rows, $source_ids);
    }

    public static function editorial_action_for_test(array $coverage, $knowledge_count, $source_count, $avg_confidence) {
        return self::editorial_action($coverage, $knowledge_count, $source_count, $avg_confidence);
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
