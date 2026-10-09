<?php
/**
 * Ingeniero: conocimiento externo por categoría, clasificado por capa.
 *
 * 0.4:
 * - L1 Documentación técnica: manuales, fabricantes, normativa y fichas.
 * - L2 Experiencia práctica: foros, comunidades, dudas y problemas de uso.
 * - L3 Actualidad sectorial: noticias, lanzamientos y cambios tecnológicos.
 * - Cada fuente y evidencia conserva su capa y subtipo desde la recopilación.
 * - No modifica catálogo, índice público ni respuestas de Dependiente.
 */
defined('ABSPATH') || exit;

final class SEO_Ingeniero {
    const VERSION = '0.5.0';
    const STATE_OPTION = 'seo_ingeniero_state_v1';
    const CATEGORY_STATE_OPTION = 'seo_ingeniero_category_state_v1';
    const LESSON_TECHNICAL = 'l1_technical';
    const LESSON_PRACTICAL = 'l2_practical';
    const LESSON_CURRENT = 'l3_current';
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
                'enabled'=>true,
                'description'=>'Foros, comunidades, preguntas, problemas, comparaciones y experiencia de uso. Se conserva como señal práctica/opinión, no como hecho técnico.',
            ),
            self::LESSON_CURRENT => array(
                'label'=>'L3 · Actualidad sectorial',
                'enabled'=>true,
                'description'=>'Noticias, lanzamientos, novedades de fabricante, ferias y cambios tecnológicos. La fecha y vigencia forman parte de la trazabilidad.',
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

        $technical_stats = $only_missing ? SEO_Ingeniero_DB::category_stats_map(self::LESSON_TECHNICAL) : array();
        $practical_stats = $only_missing ? SEO_Ingeniero_DB::category_stats_map(self::LESSON_PRACTICAL) : array();
        $current_stats = $only_missing ? SEO_Ingeniero_DB::category_stats_map(self::LESSON_CURRENT) : array();
        $category_states = $only_missing ? self::category_states() : array();
        $rows = array();
        foreach ((array) $terms as $term) {
            $term_id = absint($term->term_id ?? 0);
            if (!$term_id) continue;
            if ($only_missing) {
                $checked = isset($category_states[$term_id]['layers_checked']) && is_array($category_states[$term_id]['layers_checked'])
                    ? array_map('sanitize_key', $category_states[$term_id]['layers_checked'])
                    : array();
                $technical_done = absint($technical_stats[$term_id]['active'] ?? 0) >= self::TARGET_ACTIVE_KNOWLEDGE_PER_CATEGORY;
                $practical_done = absint($practical_stats[$term_id]['sources'] ?? 0) > 0 || in_array(self::LESSON_PRACTICAL, $checked, true);
                $current_done = absint($current_stats[$term_id]['sources'] ?? 0) > 0 || in_array(self::LESSON_CURRENT, $checked, true);
                if ($technical_done && $practical_done && $current_done) continue;
            }
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
            ? sprintf('Investigación L1/L2/L3 preparada con %d categorías por investigar/completar.',count($queue))
            : 'No hay categorías pendientes de investigación externa.';
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

        // El presupuesto sigue siendo por categoría. Desde 0.4 se reparte entre
        // las tres capas, garantizando al menos una consulta por L1/L2/L3.
        $query_budget = max(3, min(9, absint($settings['queries_per_category'] ?? 3)));
        $plan = self::query_plan($name, $query_budget);
        $fetch_pages = max(0, absint($settings['fetch_pages'] ?? 4));
        $fetch_per_layer = $fetch_pages > 0 ? max(1, (int) ceil($fetch_pages / 3)) : 0;

        $api_queries = 0;
        $total_sources = 0;
        $total_knowledge = 0;
        $total_active = 0;
        $total_review = 0;
        $technical_review = 0;
        $layer_counts = array();
        $layers_checked = array();

        foreach ($plan as $lesson=>$queries) {
            $results = array();
            $seen = array();

            foreach ((array) $queries as $query_type=>$query) {
                $response = $provider->search($query, array(
                    'term_id'=>$term_id,
                    'lesson'=>$lesson,
                    'query_type'=>$query_type,
                    'information_type'=>self::lesson_information_type($lesson),
                ));
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

            $layers_checked[] = $lesson;
            if (!$results) {
                $layer_counts[$lesson] = array('sources'=>0,'knowledge'=>0,'active'=>0,'review'=>0);
                continue;
            }

            usort($results, static function($a, $b) use ($lesson) {
                return self::compare_result_priority_for_lesson($a, $b, $lesson);
            });

            $source_rows = array();
            $fetched = 0;
            foreach ($results as $row) {
                if (count($source_rows) >= 8) break;

                $classification = self::classify_source((string) ($row['url'] ?? ''), (string) ($row['title'] ?? ''));

                // Frontera estricta: L1 no toma comunidades como hechos técnicos.
                if ($lesson === self::LESSON_TECHNICAL && $classification['source_type'] === 'community') continue;
                // L2 se reserva a conversación/experiencia. Evita que una ficha
                // comercial aparezca etiquetada como señal práctica.
                if ($lesson === self::LESSON_PRACTICAL && $classification['source_type'] !== 'community') continue;

                $is_pdf = !empty($classification['pdf']);
                $page = array('status'=>0,'text'=>'','content_hash'=>'');
                if (!$is_pdf && $fetched < $fetch_per_layer) {
                    $page = self::fetch_page_text((string) $row['url']);
                    $fetched++;
                }

                $status = $is_pdf ? 'pdf_pending' : (!empty($page['text']) ? 'fetched' : 'search_only');
                $information_type = self::lesson_information_type($lesson);
                $source_id = SEO_Ingeniero_DB::upsert_source(array(
                    'term_id'=>$term_id,
                    'lesson'=>$lesson,
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
                        'information_type'=>$information_type,
                        'lesson_label'=>self::lesson_label($lesson),
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
                    'published_at'=>sanitize_text_field((string) ($row['date'] ?? '')),
                    'information_type'=>$information_type,
                    'lesson'=>$lesson,
                    'pdf'=>$is_pdf,
                );
            }

            if ($lesson === self::LESSON_TECHNICAL) {
                $knowledge = self::build_knowledge($term_id, $name, $source_rows);
            } elseif ($lesson === self::LESSON_PRACTICAL) {
                $knowledge = self::build_practical_knowledge($term_id, $name, $source_rows);
            } else {
                $knowledge = self::build_current_knowledge($term_id, $name, $source_rows);
            }

            $active = 0;
            $review = 0;
            foreach ($knowledge as $item) {
                $saved = SEO_Ingeniero_DB::upsert_knowledge($item);
                if (is_wp_error($saved)) continue;
                if ('active' === $item['status']) $active++;
                else $review++;
            }

            if ($lesson === self::LESSON_TECHNICAL) $technical_review = $review;
            $layer_counts[$lesson] = array(
                'sources'=>count($source_rows),
                'knowledge'=>count($knowledge),
                'active'=>$active,
                'review'=>$review,
            );
            $total_sources += count($source_rows);
            $total_knowledge += count($knowledge);
            $total_active += $active;
            $total_review += $review;
        }

        if ($total_sources < 1) {
            return new WP_Error('ingeniero_no_results', 'No se han encontrado fuentes externas útiles para esta categoría.');
        }

        return array(
            'term_id'=>$term_id,
            'category'=>$name,
            'sources'=>$total_sources,
            'knowledge'=>$total_knowledge,
            'active'=>$total_active,
            'review'=>$total_review,
            'technical_review'=>$technical_review,
            'api_queries'=>$api_queries,
            'layers'=>$layer_counts,
            'layers_checked'=>array_values(array_unique($layers_checked)),
        );
    }

    private static function query_plan($name, $budget) {
        $budget = max(3, min(9, absint($budget)));
        $sets = array(
            self::LESSON_TECHNICAL => self::technical_queries($name),
            self::LESSON_PRACTICAL => self::practical_queries($name),
            self::LESSON_CURRENT => self::current_queries($name),
        );
        $plan = array();
        $offsets = array();

        foreach ($sets as $lesson=>$queries) {
            $plan[$lesson] = array_slice($queries, 0, 1, true);
            $offsets[$lesson] = 1;
        }

        $remaining = $budget - 3;
        // Si hay presupuesto extra, L2 recibe el primer refuerzo porque la
        // ampliación 0.4 busca deliberadamente más señal de foros/comunidades.
        $order = array(
            self::LESSON_PRACTICAL,
            self::LESSON_TECHNICAL,
            self::LESSON_CURRENT,
            self::LESSON_PRACTICAL,
            self::LESSON_TECHNICAL,
            self::LESSON_CURRENT,
        );
        foreach ($order as $lesson) {
            if ($remaining < 1) break;
            $pairs = array_slice($sets[$lesson], $offsets[$lesson], 1, true);
            if ($pairs) {
                $plan[$lesson] += $pairs;
                $offsets[$lesson]++;
                $remaining--;
            }
        }
        return $plan;
    }

    private static function technical_queries($name) {
        return array(
            'technical_definition' => '"' . $name . '" qué es funcionamiento tipos aplicaciones ficha técnica',
            'technical_safety' => '"' . $name . '" manual seguridad mantenimiento compatibilidad limitaciones',
            'technical_regulation' => '"' . $name . '" normativa seguridad especificaciones técnicas problemas habituales',
        );
    }

    private static function practical_queries($name) {
        return array(
            'practical_forums' => '"' . $name . '" foro problemas opiniones experiencia dudas',
            'practical_reddit' => '"' . $name . '" reddit problemas recomendaciones comparación',
            'practical_questions' => '"' . $name . '" "merece la pena" "qué problema" experiencia usuario',
        );
    }

    private static function current_queries($name) {
        $year = gmdate('Y');
        return array(
            'current_news' => '"' . $name . '" noticias novedades lanzamiento ' . $year,
            'current_manufacturer' => '"' . $name . '" fabricante nueva gama actualización nueva generación',
            'current_technology' => '"' . $name . '" tecnología innovación feria novedades sector',
        );
    }

    public static function lesson_label($lesson) {
        $lessons = self::lessons();
        return isset($lessons[$lesson]['label']) ? (string) $lessons[$lesson]['label'] : (string) $lesson;
    }

    public static function lesson_information_type($lesson) {
        $lesson = sanitize_key((string) $lesson);
        if ($lesson === self::LESSON_PRACTICAL) return 'practical';
        if ($lesson === self::LESSON_CURRENT) return 'current';
        return 'technical';
    }

    private static function compare_result_priority_for_lesson($a, $b, $lesson) {
        $ca = self::classify_source((string) ($a['url'] ?? ''), (string) ($a['title'] ?? ''));
        $cb = self::classify_source((string) ($b['url'] ?? ''), (string) ($b['title'] ?? ''));
        $weights = array('high'=>40,'medium_high'=>30,'medium'=>20,'low'=>10);
        $wa = $weights[$ca['trust_level']] ?? 0;
        $wb = $weights[$cb['trust_level']] ?? 0;

        if ($lesson === self::LESSON_PRACTICAL) {
            if ($ca['source_type'] === 'community') $wa += 50;
            if ($cb['source_type'] === 'community') $wb += 50;
        } elseif ($lesson === self::LESSON_CURRENT) {
            if ($ca['source_type'] === 'industry_news') $wa += 40;
            if ($cb['source_type'] === 'industry_news') $wb += 40;
        } else {
            if (in_array($ca['source_type'], array('official_body','standards_body','technical_document'), true)) $wa += 40;
            if (in_array($cb['source_type'], array('official_body','standards_body','technical_document'), true)) $wb += 40;
        }

        if ($wa !== $wb) return $wb <=> $wa;
        return absint($a['position'] ?? 999) <=> absint($b['position'] ?? 999);
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
        if (preg_match('/(foro|forum|reddit|quora|facebook|youtube|tiktok|community|comunidad)/', $haystack)) {
            return array('domain'=>$host,'source_type'=>'community','trust_level'=>'low','pdf'=>$pdf);
        }
        if (preg_match('/(news|noticia|actualidad|press|prensa|lanzamiento|launch|presenta|anuncia|nueva-gama|new-generation|feria|expo)/', $haystack)) {
            return array('domain'=>$host,'source_type'=>'industry_news','trust_level'=>'medium','pdf'=>$pdf);
        }
        if (preg_match('/(blog|magazine|revista|academy|institut|universit|ingenier|tecnic)/', $haystack)) {
            return array('domain'=>$host,'source_type'=>'technical_specialist','trust_level'=>'medium_high','pdf'=>$pdf);
        }
        return array('domain'=>$host,'source_type'=>'specialized_web','trust_level'=>'medium','pdf'=>$pdf);
    }

    public static function ingest_raw_source($term_id, $lesson, $url, $title = '', $published_at = '') {
        $term_id = absint($term_id);
        $lesson = sanitize_key((string)$lesson);
        $url = esc_url_raw((string)$url);
        if (!$term_id || $url === '') return new WP_Error('ingeniero_raw_source_invalid','Fuente cruda sin categoría o URL válida.');
        if (!in_array($lesson,array(self::LESSON_TECHNICAL,self::LESSON_PRACTICAL,self::LESSON_CURRENT),true)) {
            $lesson = self::LESSON_TECHNICAL;
        }

        $term = get_term($term_id,'product_cat');
        if (!$term || is_wp_error($term)) return new WP_Error('ingeniero_raw_source_category','La categoría de la fuente cruda no existe.');

        $classification = self::classify_source($url,$title);
        if ($lesson === self::LESSON_TECHNICAL && $classification['source_type'] === 'community') {
            return new WP_Error('ingeniero_raw_source_layer','Una comunidad no puede importarse como L1 técnico.');
        }

        $page = !empty($classification['pdf'])
            ? array('status'=>0,'text'=>'','content_hash'=>'')
            : self::fetch_page_text($url);

        $source_id = SEO_Ingeniero_DB::upsert_source(array(
            'term_id'=>$term_id,
            'lesson'=>$lesson,
            'url'=>$url,
            'domain'=>$classification['domain'],
            'title'=>sanitize_text_field((string)$title),
            'source_type'=>$classification['source_type'],
            'trust_level'=>$classification['trust_level'],
            'published_at'=>$published_at,
            'retrieved_at'=>gmdate('Y-m-d H:i:s'),
            'http_status'=>absint($page['status'] ?? 0),
            'content_hash'=>(string)($page['content_hash'] ?? ''),
            'status'=>!empty($classification['pdf']) ? 'pdf_pending' : (!empty($page['text']) ? 'fetched' : 'search_only'),
            'metadata'=>array('import_origin'=>'raw_source','information_type'=>self::lesson_information_type($lesson)),
        ));
        if (is_wp_error($source_id)) return $source_id;

        $source = array(
            'id'=>absint($source_id),
            'url'=>$url,
            'title'=>sanitize_text_field((string)$title),
            'snippet'=>'',
            'text'=>(string)($page['text'] ?? ''),
            'source_type'=>$classification['source_type'],
            'trust_level'=>$classification['trust_level'],
            'published_at'=>sanitize_text_field((string)$published_at),
            'lesson'=>$lesson,
            'pdf'=>!empty($classification['pdf']),
        );

        if ($lesson === self::LESSON_PRACTICAL) $knowledge = self::build_practical_knowledge($term_id,(string)$term->name,array($source));
        elseif ($lesson === self::LESSON_CURRENT) $knowledge = self::build_current_knowledge($term_id,(string)$term->name,array($source));
        else $knowledge = self::build_knowledge($term_id,(string)$term->name,array($source));

        $saved = 0;
        foreach ($knowledge as $item) {
            // Fuentes importadas nunca activan conocimiento automáticamente.
            $item['status'] = 'review';
            $result = SEO_Ingeniero_DB::upsert_knowledge($item);
            if (!is_wp_error($result)) $saved++;
        }

        return array('source_id'=>absint($source_id),'knowledge'=>$saved,'lesson'=>$lesson);
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

    private static function build_practical_knowledge($term_id, $category_name, $sources) {
        $definitions = array(
            'practical_question'=>array('cómo','como','qué','que','merece la pena','recomienda','duda','pregunta'),
            'practical_problem'=>array('problema','fallo','no funciona','se rompe','atasca','avería','averia','difícil','dificil'),
            'practical_comparison'=>array(' vs ','frente a','comparar','comparación','comparacion','diferencia','mejor que'),
            'practical_compatibility'=>array('compatible','sirve para','vale para','encaja','adaptador','medida'),
            'practical_use_case'=>array('uso','utilizo','trabajo','tarea','caso','experiencia','profesional'),
        );
        $labels = array(
            'practical_question'=>'preguntas y dudas de usuarios',
            'practical_problem'=>'problemas y dificultades de uso',
            'practical_comparison'=>'comparaciones recurrentes',
            'practical_compatibility'=>'dudas de compatibilidad',
            'practical_use_case'=>'casos de uso y experiencia práctica',
        );
        return self::build_signal_knowledge(
            $term_id,
            $category_name,
            $sources,
            self::LESSON_PRACTICAL,
            'practical',
            $definitions,
            $labels
        );
    }

    private static function build_current_knowledge($term_id, $category_name, $sources) {
        $definitions = array(
            'current_launch'=>array('lanza','lanzamiento','presenta','anuncia','nuevo modelo','nueva gama','nueva generación','nueva generacion'),
            'current_technology'=>array('tecnología','tecnologia','innovación','innovacion','nuevo sistema','plataforma','patente'),
            'current_manufacturer'=>array('fabricante','actualiza','actualización','actualizacion','catálogo','catalogo','serie'),
            'current_industry'=>array('noticia','actualidad','sector','feria','evento','mercado','tendencia'),
        );
        $labels = array(
            'current_launch'=>'lanzamientos y nuevas gamas',
            'current_technology'=>'cambios e innovaciones tecnológicas',
            'current_manufacturer'=>'actualizaciones de fabricantes',
            'current_industry'=>'actualidad sectorial',
        );
        return self::build_signal_knowledge(
            $term_id,
            $category_name,
            $sources,
            self::LESSON_CURRENT,
            'current',
            $definitions,
            $labels
        );
    }

    private static function build_signal_knowledge($term_id, $category_name, $sources, $lesson, $information_type, $definitions, $labels) {
        $out = array();

        foreach ((array) $definitions as $type=>$keywords) {
            $evidence = array();
            $source_ids = array();
            $domains = array();

            foreach ((array) $sources as $source) {
                if (!empty($source['pdf'])) continue;
                $pool = trim((string) ($source['snippet'] ?? '') . ' ' . (string) ($source['text'] ?? ''));
                if ($pool === '') continue;
                $sentence = self::best_sentence($pool, $keywords);
                if ($sentence === '') continue;

                $domain = strtolower((string) wp_parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST));
                if ($domain !== '') $domains[] = $domain;
                $source_ids[] = absint($source['id'] ?? 0);
                $evidence[] = array(
                    'source_id'=>absint($source['id'] ?? 0),
                    'source_url'=>esc_url_raw((string) ($source['url'] ?? '')),
                    'source_title'=>sanitize_text_field((string) ($source['title'] ?? '')),
                    'source_type'=>sanitize_key((string) ($source['source_type'] ?? '')),
                    'trust_level'=>sanitize_key((string) ($source['trust_level'] ?? 'medium')),
                    'published_at'=>sanitize_text_field((string) ($source['published_at'] ?? '')),
                    'information_type'=>$information_type,
                    'evidence_type'=>sanitize_key((string) $type),
                    'evidence'=>self::limit_words($sentence, 24),
                );
                if (count($evidence) >= 5) break;
            }

            if (!$evidence) continue;
            $domains = array_values(array_unique(array_filter($domains)));
            $source_ids = array_values(array_unique(array_filter($source_ids)));
            $repeated = count($domains) >= 2 && count($evidence) >= 2;
            $confidence = min(0.85, 0.30 + (count($evidence) * 0.07) + (count($domains) * 0.06));
            $label = $labels[$type] ?? str_replace('_',' ',(string) $type);

            if ($information_type === 'practical') {
                $summary = ($repeated ? 'Se repiten señales' : 'Se ha detectado una señal')
                    . ' en comunidades sobre ' . $label . ' en ' . $category_name . '. '
                    . 'Esta capa representa preguntas, experiencia u opinión de usuarios y no confirma por sí sola un hecho técnico.';
            } else {
                $summary = ($repeated ? 'Varias fuentes recientes aportan señales' : 'Se ha detectado una señal de actualidad')
                    . ' sobre ' . $label . ' en ' . $category_name . '. '
                    . 'La fecha, vigencia y fuente deben verificarse antes de convertirla en una afirmación editorial.';
            }

            $out[] = array(
                'term_id'=>$term_id,
                'lesson'=>$lesson,
                'knowledge_type'=>$type,
                'concept'=>$category_name,
                'summary'=>self::limit_text($summary, 700),
                'facts'=>$evidence,
                'tags'=>array($category_name,$information_type,$type),
                'source_ids'=>$source_ids,
                'confidence'=>round($confidence,5),
                // L2/L3 se recopilan y clasifican, pero no contaminan el dossier
                // técnico actual hasta que Editora decida cómo utilizarlos.
                'status'=>'review',
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

    /**
     * Material disponible para detectar temas editoriales.
     * L1 exige conocimiento activo; L2/L3 pueden participar en revisión para
     * generar propuestas sin elevar opiniones o novedades a hecho técnico.
     */
    public static function editorial_knowledge($term_id) {
        $term_id = absint($term_id);
        $rows = array();

        foreach (array(self::LESSON_TECHNICAL,self::LESSON_PRACTICAL,self::LESSON_CURRENT) as $lesson) {
            foreach (SEO_Ingeniero_DB::knowledge_for_category($term_id, false, $lesson) as $row) {
                $status = sanitize_key((string) ($row['status'] ?? 'review'));
                if (in_array($status,array('rejected','superseded'),true)) continue;
                if ($lesson === self::LESSON_TECHNICAL && $status !== 'active') continue;
                $row['lesson'] = $lesson;
                $rows[] = $row;
            }
        }
        return $rows;
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

        $knowledge = self::editorial_knowledge($term_id);
        if (!$knowledge) {
            return array('term_id'=>$term_id,'proposals'=>array(),'skipped'=>'no_editorial_knowledge');
        }

        $groups = self::editorial_groups($knowledge);
        $proposals = array();
        $current_topic_keys = array();

        foreach ($groups as $topic_key=>$rows) {
            $topic_key = sanitize_key((string) $topic_key);
            if ($topic_key === '') continue;
            $current_topic_keys[] = $topic_key;

            $meta = self::topic_metadata($term_id, $topic_key, $rows);
            $source_hash = self::editorial_source_hash($rows, $meta['source_ids']);
            $existing = SEO_Ingeniero_DB::editorial_get_by_topic($term_id, $topic_key);

            if (!$force && $existing && (string)($existing['source_hash'] ?? '') === $source_hash) {
                $proposals[] = array_merge($existing,array('unchanged'=>true));
                continue;
            }

            $title = self::editorial_title((string)$term->name,$topic_key);
            $summary = self::limit_text(implode(' ',$meta['summary_parts']),3000);
            $coverage = class_exists('SEO_Editorial_Coverage')
                ? SEO_Editorial_Coverage::find_technical($term_id,$title,$summary)
                : array('status'=>'uncovered','post_id'=>0,'matches'=>array());

            $action = self::editorial_action_v2($coverage,$meta);
            $status = $existing ? sanitize_key((string)($existing['status'] ?? 'candidate')) : 'candidate';

            if (in_array($action,array('WATCH','DISCARD'),true) && !in_array($status,array('draft','published','needs_update'),true)) {
                $status = 'review';
            }
            if (!empty($existing['post_id']) && in_array($action,array('CREATE_POST','CREATE_FAQ'),true)) {
                $action = 'IMPROVE_EXISTING_POST';
            }

            $id = SEO_Ingeniero_DB::upsert_editorial(array(
                'term_id'=>$term_id,
                'topic_key'=>$topic_key,
                'knowledge_ids'=>$meta['knowledge_ids'],
                'source_ids'=>$meta['source_ids'],
                'product_ids'=>$meta['product_ids'],
                'source_hash'=>$source_hash,
                'primary_layer'=>$meta['primary_layer'],
                'content_type'=>$meta['content_type'],
                'evidence_count'=>$meta['evidence_count'],
                'domain_count'=>$meta['domain_count'],
                'confidence'=>$meta['confidence'],
                'freshness'=>$meta['freshness'],
                'topic_meta'=>array(
                    'layers'=>$meta['layers'],
                    'knowledge_types'=>$meta['knowledge_types'],
                    'product_matches'=>$meta['product_matches'],
                    'newest_source_at'=>$meta['newest_source_at'],
                    'oldest_source_at'=>$meta['oldest_source_at'],
                ),
                'suggested_title'=>$title,
                'coverage_status'=>(string)($coverage['status'] ?? 'uncovered'),
                'recommended_action'=>$action,
                'coverage'=>$coverage,
                'status'=>$status,
            ));

            if (is_wp_error($id)) {
                $proposals[] = array('error'=>$id->get_error_message(),'topic_key'=>$topic_key);
                continue;
            }

            $saved = SEO_Ingeniero_DB::editorial_get($id);
            if (!empty($saved['post_id']) && class_exists('SEO_Ingeniero_Posts') && method_exists('SEO_Ingeniero_Posts','sync_pending_update')) {
                SEO_Ingeniero_Posts::sync_pending_update($id);
                $saved = SEO_Ingeniero_DB::editorial_get($id);
            }
            $proposals[] = $saved;
        }

        $existing_rows = SEO_Ingeniero_DB::editorial_rows(array('term_id'=>$term_id,'page'=>1,'per_page'=>100));
        foreach ((array)($existing_rows['rows'] ?? array()) as $row) {
            $topic_key = sanitize_key((string)($row['topic_key'] ?? ''));
            if ($topic_key === '' || in_array($topic_key,$current_topic_keys,true)) continue;
            SEO_Ingeniero_DB::update_editorial(absint($row['id'] ?? 0),array(
                'status'=>!empty($row['post_id']) ? 'needs_update' : 'closed',
                'recommended_action'=>!empty($row['post_id']) ? 'IMPROVE_EXISTING_POST' : 'DISCARD',
            ));
        }

        return array('term_id'=>$term_id,'proposals'=>$proposals);
    }

    public static function editorial_brief($editorial_id) {
        $dossier = SEO_Ingeniero_DB::editorial_get(absint($editorial_id));
        if (!$dossier) return new WP_Error('ingeniero_editorial_missing', 'No existe el dossier editorial.');

        $term_id = absint($dossier['term_id'] ?? 0);
        $all_knowledge = self::editorial_knowledge($term_id);
        $wanted = array_fill_keys(array_map('absint', (array) ($dossier['knowledge_ids'] ?? array())), true);
        $knowledge = array();
        $must_cover = array();
        foreach ($all_knowledge as $row) {
            $id = absint($row['id'] ?? 0);
            if (!$id || !isset($wanted[$id])) continue;
            $knowledge[] = array(
                'id'=>$id,
                'lesson'=>(string) ($row['lesson'] ?? self::LESSON_TECHNICAL),
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
            'topic'=>array(
                'primary_layer'=>(string) ($dossier['primary_layer'] ?? self::LESSON_TECHNICAL),
                'content_type'=>(string) ($dossier['content_type'] ?? 'technical'),
                'product_ids'=>(array) ($dossier['product_ids'] ?? array()),
                'confidence'=>(float) ($dossier['confidence'] ?? 0),
                'freshness'=>(string) ($dossier['freshness'] ?? 'unknown'),
                'evidence_count'=>absint($dossier['evidence_count'] ?? 0),
                'domain_count'=>absint($dossier['domain_count'] ?? 0),
                'meta'=>(array) ($dossier['topic_meta'] ?? array()),
            ),
            'verification_warning'=>'L1 puede respaldar hechos técnicos; L2 representa experiencia/opinión; L3 exige revisar fecha y vigencia. Nada se publica automáticamente.',
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
        $groups = array();

        foreach ($knowledge as $row) {
            $lesson = sanitize_key((string)($row['lesson'] ?? self::LESSON_TECHNICAL));
            $type = sanitize_key((string)($row['knowledge_type'] ?? ''));

            if ($lesson === self::LESSON_PRACTICAL) {
                if ($type === 'practical_problem') $key = 'practical-problems';
                elseif ($type === 'practical_comparison') $key = 'practical-comparison';
                elseif ($type === 'practical_question') $key = 'practical-faq';
                else $key = 'practical-selection';
            } elseif ($lesson === self::LESSON_CURRENT) {
                if ($type === 'current_technology') $key = 'current-technology';
                elseif ($type === 'current_industry') $key = 'current-industry';
                else $key = 'current-launches';
            } else {
                if (in_array($type,array('compatibility','limitation'),true)) $key = 'technical-selection';
                elseif (in_array($type,array('maintenance','problem'),true)) $key = 'technical-maintenance';
                elseif (in_array($type,array('safety','regulation'),true)) $key = 'technical-safety';
                else $key = 'technical-foundations';
            }
            $groups[$key][] = $row;
        }

        if (!empty($groups['technical-selection']) && (!empty($groups['practical-selection']) || !empty($groups['practical-comparison']))) {
            $groups['mixed-selection'] = array_merge(
                $groups['technical-selection'],
                (array)($groups['practical-selection'] ?? array()),
                (array)($groups['practical-comparison'] ?? array())
            );
            unset($groups['technical-selection'],$groups['practical-selection'],$groups['practical-comparison']);
        }

        if (!empty($groups['technical-maintenance']) && !empty($groups['practical-problems'])) {
            $groups['mixed-problems'] = array_merge($groups['technical-maintenance'],$groups['practical-problems']);
            unset($groups['technical-maintenance'],$groups['practical-problems']);
        }

        return $groups;
    }

    private static function editorial_title($category_name, $topic_key) {
        $category_name = sanitize_text_field((string)$category_name);
        $map = array(
            'technical-foundations'=>'Guía técnica de %s: funcionamiento, tipos y criterios',
            'technical-selection'=>'Cómo elegir %s: compatibilidad, límites y criterios técnicos',
            'technical-maintenance'=>'Mantenimiento y problemas técnicos de %s',
            'technical-safety'=>'Seguridad y normativa de %s',
            'practical-problems'=>'Problemas frecuentes con %s: qué revisar y cómo interpretarlos',
            'practical-selection'=>'Cómo elegir %s según dudas y usos reales',
            'practical-comparison'=>'%s: criterios que conviene comparar antes de elegir',
            'practical-faq'=>'Preguntas frecuentes sobre %s',
            'current-launches'=>'Novedades y nuevas gamas de %s',
            'current-technology'=>'Nuevas tecnologías aplicadas a %s',
            'current-industry'=>'Actualidad y tendencias en %s',
            'mixed-selection'=>'Cómo elegir %s: criterios técnicos y dudas reales',
            'mixed-problems'=>'Problemas y mantenimiento de %s: técnica y experiencia de uso',
        );
        return sprintf($map[$topic_key] ?? '%s: información especializada',$category_name);
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
        // Incluye la versión del contrato editorial: al desplegar 0.3.3
        // los dossiers/posts 0.3.2 se marcan para revisión aunque el knowledge
        // técnico no haya cambiado, porque antes podían omitir bloques o nacer vacíos.
        return hash(
            'sha256',
            'topic-dossier-v2::' . implode('||',$parts) . '::' . implode(',',$source_ids)
        );
    }

    private static function editorial_action(array $coverage, $knowledge_count, $source_count, $avg_confidence) {
        $meta = array(
            'knowledge_count'=>absint($knowledge_count),
            'source_count'=>absint($source_count),
            'evidence_count'=>absint($knowledge_count),
            'domain_count'=>absint($source_count),
            'confidence'=>(float)$avg_confidence,
            'primary_layer'=>self::LESSON_TECHNICAL,
            'content_type'=>'technical',
            'freshness'=>'not_applicable',
        );
        return self::editorial_action_v2($coverage,$meta);
    }

    private static function editorial_action_v2(array $coverage, array $meta) {
        $coverage_status = sanitize_key((string)($coverage['status'] ?? 'uncovered'));
        $post_id = absint($coverage['post_id'] ?? 0);
        $layer = sanitize_key((string)($meta['primary_layer'] ?? self::LESSON_TECHNICAL));
        $content_type = sanitize_key((string)($meta['content_type'] ?? 'technical'));
        $evidence_count = absint($meta['evidence_count'] ?? 0);
        $domain_count = absint($meta['domain_count'] ?? 0);
        $confidence = (float)($meta['confidence'] ?? 0);
        $freshness = sanitize_key((string)($meta['freshness'] ?? 'unknown'));

        if ($evidence_count < 1) return 'DISCARD';
        if ($post_id && in_array($coverage_status,array('covered','partial_coverage','weak_coverage','duplicate','conflict'),true)) {
            return 'IMPROVE_EXISTING_POST';
        }
        if (!$post_id && in_array($coverage_status,array('covered','duplicate','conflict'),true)) {
            return 'DISCARD';
        }
        if ($layer === self::LESSON_CURRENT && in_array($freshness,array('stale','unknown'),true)) return 'WATCH';
        if ($layer === self::LESSON_PRACTICAL && ($evidence_count < 2 || $domain_count < 2 || $confidence < 0.45)) return 'WATCH';
        if ($layer === self::LESSON_CURRENT && $confidence < 0.40) return 'WATCH';
        if ($content_type === 'faq') return 'CREATE_FAQ';
        return 'CREATE_POST';
    }

    private static function topic_metadata($term_id, $topic_key, array $rows) {
        $knowledge_ids = array();
        $source_ids = array();
        $layers = array();
        $types = array();
        $confidence = array();
        $summary_parts = array();
        $evidence_count = 0;

        foreach ($rows as $row) {
            $knowledge_ids[] = absint($row['id'] ?? 0);
            $source_ids = array_merge($source_ids,(array)($row['source_ids'] ?? array()));
            $layers[] = sanitize_key((string)($row['lesson'] ?? self::LESSON_TECHNICAL));
            $types[] = sanitize_key((string)($row['knowledge_type'] ?? ''));
            $confidence[] = (float)($row['confidence'] ?? 0);
            if (!empty($row['summary'])) $summary_parts[] = (string)$row['summary'];
            $evidence_count += max(1,count((array)($row['facts'] ?? array())));
        }

        $knowledge_ids = array_values(array_unique(array_filter(array_map('absint',$knowledge_ids))));
        $source_ids = array_values(array_unique(array_filter(array_map('absint',$source_ids))));
        $layers = array_values(array_unique(array_filter($layers)));
        $types = array_values(array_unique(array_filter($types)));

        $sources = SEO_Ingeniero_DB::sources_by_ids($source_ids);
        $domains = array();
        $dates = array();
        foreach ($sources as $source) {
            $domain = strtolower(trim((string)($source['domain'] ?? '')));
            if ($domain !== '') $domains[] = $domain;
            $date = trim((string)($source['published_at'] ?? ''));
            if ($date === '') $date = trim((string)($source['retrieved_at'] ?? ''));
            $ts = $date !== '' ? strtotime($date) : false;
            if ($ts) $dates[] = $ts;
        }
        $domains = array_values(array_unique($domains));
        rsort($dates,SORT_NUMERIC);

        $primary_layer = self::topic_primary_layer($layers,$topic_key);
        $content_type = self::topic_content_type($topic_key,$layers);
        $freshness = self::topic_freshness($primary_layer,$dates);
        $product_matches = self::related_products_for_topic($term_id,$rows);
        $product_ids = array_values(array_unique(array_filter(array_map('absint',array_column($product_matches,'product_id')))));

        return array(
            'knowledge_ids'=>$knowledge_ids,
            'knowledge_count'=>count($knowledge_ids),
            'source_ids'=>$source_ids,
            'source_count'=>count($source_ids),
            'layers'=>$layers,
            'knowledge_types'=>$types,
            'primary_layer'=>$primary_layer,
            'content_type'=>$content_type,
            'evidence_count'=>$evidence_count,
            'domain_count'=>count($domains),
            'confidence'=>$confidence ? round(array_sum($confidence)/count($confidence),5) : 0,
            'freshness'=>$freshness,
            'summary_parts'=>$summary_parts,
            'product_matches'=>$product_matches,
            'product_ids'=>$product_ids,
            'newest_source_at'=>$dates ? gmdate('Y-m-d H:i:s',$dates[0]) : '',
            'oldest_source_at'=>$dates ? gmdate('Y-m-d H:i:s',$dates[count($dates)-1]) : '',
        );
    }

    private static function topic_primary_layer(array $layers, $topic_key) {
        if (strpos($topic_key,'mixed-') === 0) return 'mixed';
        if (in_array(self::LESSON_CURRENT,$layers,true)) return self::LESSON_CURRENT;
        if (in_array(self::LESSON_PRACTICAL,$layers,true)) return self::LESSON_PRACTICAL;
        return self::LESSON_TECHNICAL;
    }

    private static function topic_content_type($topic_key, array $layers) {
        if ($topic_key === 'practical-faq') return 'faq';
        if (strpos($topic_key,'current-') === 0) return 'current';
        if (strpos($topic_key,'mixed-') === 0) return 'mixed';
        if ($topic_key === 'practical-comparison') return 'comparison';
        if (strpos($topic_key,'practical-') === 0) return 'practical';
        return 'technical';
    }

    private static function topic_freshness($primary_layer, array $dates) {
        if ($primary_layer !== self::LESSON_CURRENT) return 'not_applicable';
        if (!$dates) return 'unknown';
        $age_days = max(0,(time()-max($dates))/DAY_IN_SECONDS);
        if ($age_days <= 90) return 'fresh';
        if ($age_days <= 365) return 'recent';
        return 'stale';
    }

    private static function related_products_for_topic($term_id, array $rows) {
        $term_id = absint($term_id);
        if (!$term_id) return array();

        $texts = array();
        foreach ($rows as $row) {
            $texts[] = (string)($row['summary'] ?? '');
            foreach ((array)($row['facts'] ?? array()) as $fact) {
                $texts[] = (string)($fact['source_title'] ?? '');
                $texts[] = (string)($fact['evidence'] ?? '');
            }
        }
        $haystack = self::normalize_signal_text(implode(' ',$texts));
        if (trim($haystack) === '') return array();

        $ids = get_posts(array(
            'post_type'=>'product',
            'post_status'=>'publish',
            'posts_per_page'=>120,
            'fields'=>'ids',
            'no_found_rows'=>true,
            'tax_query'=>array(array(
                'taxonomy'=>'product_cat',
                'field'=>'term_id',
                'terms'=>array($term_id),
            )),
        ));

        $matches = array();
        foreach ((array)$ids as $product_id) {
            $product_id = absint($product_id);
            if (!$product_id) continue;
            $title = (string)get_the_title($product_id);
            $sku = sanitize_text_field((string)get_post_meta($product_id,'_sku',true));
            $title_norm = self::normalize_signal_text($title);

            $matched = $sku !== '' && strpos($haystack,self::normalize_signal_text($sku)) !== false;
            if (!$matched) {
                $tokens = array_values(array_unique(array_filter(
                    preg_split('/\s+/',$title_norm),
                    static function($token){ return strlen($token) >= 4; }
                )));
                $hits = 0;
                foreach ($tokens as $token) {
                    if (strpos($haystack,$token) !== false) $hits++;
                }
                $matched = $hits >= 2;
            }
            if (!$matched) continue;

            $brand = '';
            $model = '';
            if (function_exists('wc_get_product')) {
                $product = wc_get_product($product_id);
                if ($product) {
                    foreach (array('pa_marca','marca','brand') as $attribute) {
                        $value = trim((string)$product->get_attribute($attribute));
                        if ($value !== '') { $brand = $value; break; }
                    }
                    foreach (array('pa_modelo','modelo','model') as $attribute) {
                        $value = trim((string)$product->get_attribute($attribute));
                        if ($value !== '') { $model = $value; break; }
                    }
                }
            }

            $matches[] = array(
                'product_id'=>$product_id,
                'brand'=>sanitize_text_field($brand),
                'model'=>sanitize_text_field($model),
                'sku'=>$sku,
                'title'=>sanitize_text_field($title),
            );
            if (count($matches) >= 12) break;
        }
        return $matches;
    }

    public static function editorial_question_for_knowledge

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
