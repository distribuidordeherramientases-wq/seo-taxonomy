<?php
/**
 * Descubrimiento de huecos de vocabulario a partir del conocimiento activo
 * de Ingeniero.
 *
 * Esta capa es deliberadamente de solo lectura: compara evidencia técnica
 * aprobada con los maestros canónicos y devuelve propuestas para revisión.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_engineer_vocab_normalize')) {
    function seo_classifier_engineer_vocab_normalize($text) {
        return function_exists('seo_classifier_normalize')
            ? seo_classifier_normalize((string) $text)
            : sanitize_title((string) $text);
    }
}

if (!function_exists('seo_classifier_engineer_vocab_attribute_rules')) {
    function seo_classifier_engineer_vocab_attribute_rules() {
        return [
            [
                'slug'=>'presion_maxima','name'=>'Presión máxima','type'=>'numero','unit'=>'bar','group'=>'rendimiento',
                'patterns'=>['/\bpresi[oó]n(?:\s+m[aá]xima|\s+de\s+trabajo)?\b/iu','/\b(?:bar|psi|mpa)\b/iu'],
            ],
            [
                'slug'=>'caudal','name'=>'Caudal','type'=>'numero','unit'=>'L/min','group'=>'rendimiento',
                'patterns'=>['/\bcaudal\b/iu','/\b(?:l\s*\/\s*min|litros?\s+por\s+minuto|cfm)\b/iu'],
            ],
            [
                'slug'=>'potencia','name'=>'Potencia','type'=>'numero','unit'=>'W','group'=>'rendimiento',
                'patterns'=>['/\bpotencia\b/iu','/\b\d+(?:[,.]\d+)?\s*(?:w|kw)\b/iu'],
            ],
            [
                'slug'=>'tension','name'=>'Tensión / voltaje','type'=>'numero','unit'=>'V','group'=>'electricidad',
                'patterns'=>['/\b(?:tensi[oó]n|voltaje)\b/iu','/\b(?:12|18|20|24|36|48|110|120|220|230|240)\s*v\b/iu'],
            ],
            [
                'slug'=>'fuente_alimentacion','name'=>'Fuente de alimentación','type'=>'termino','unit'=>'','group'=>'electricidad',
                'patterns'=>['/\b(?:alimentaci[oó]n|alimentado|alimentada)\b/iu','/\b(?:bater[ií]a|mechero|encendedor|enchufe|red\s+el[eé]ctrica|corriente\s+alterna)\b/iu'],
                'term_patterns'=>[
                    'Batería'=>'/\bbater[ií]a\b/iu',
                    'Red eléctrica'=>'/\b(?:red\s+el[eé]ctrica|enchufe|230\s*v|220\s*v|240\s*v)\b/iu',
                    '12 V vehículo'=>'/\b(?:12\s*v|mechero|encendedor)\b/iu',
                    'Conexión directa a batería'=>'/\b(?:directamente|conexi[oó]n\s+directa)\s+(?:a\s+)?(?:la\s+)?bater[ií]a\b/iu',
                ],
            ],
            [
                'slug'=>'capacidad_deposito','name'=>'Capacidad del depósito','type'=>'numero','unit'=>'L','group'=>'capacidad',
                'patterns'=>['/\b(?:dep[oó]sito|tanque|calder[ií]n)\b/iu','/\bcapacidad\s+(?:del|de\s+dep[oó]sito)\b/iu'],
            ],
            [
                'slug'=>'nivel_sonoro','name'=>'Nivel sonoro','type'=>'numero','unit'=>'dB','group'=>'uso',
                'patterns'=>['/\b(?:nivel\s+sonoro|ruido|sonoridad)\b/iu','/\b\d+(?:[,.]\d+)?\s*db\b/iu'],
            ],
            [
                'slug'=>'par','name'=>'Par','type'=>'numero','unit'=>'N·m','group'=>'rendimiento',
                'patterns'=>['/\bpar(?:\s+m[aá]ximo|\s+motor|\s+nominal)\b/iu','/\b\d+(?:[,.]\d+)?\s*(?:n\s*[·.\-]?\s*m|nm)\b/iu'],
            ],
            [
                'slug'=>'velocidad_rotacion','name'=>'Velocidad de rotación','type'=>'numero','unit'=>'rpm','group'=>'rendimiento',
                'patterns'=>['/\b(?:rpm|revoluciones?\s+por\s+minuto|velocidad\s+de\s+rotaci[oó]n)\b/iu'],
            ],
            [
                'slug'=>'diametro','name'=>'Diámetro','type'=>'numero','unit'=>'mm','group'=>'dimensiones',
                'patterns'=>['/\bdi[aá]metro\b/iu','/[Øø⌀]\s*\d/iu'],
            ],
            [
                'slug'=>'temperatura_trabajo','name'=>'Temperatura de trabajo','type'=>'numero','unit'=>'°C','group'=>'uso',
                'patterns'=>['/\btemperatura(?:\s+de\s+trabajo|\s+m[aá]xima|\s+m[ií]nima)?\b/iu','/\b\d+(?:[,.]\d+)?\s*[°º]\s*c\b/iu'],
            ],
        ];
    }
}

if (!function_exists('seo_classifier_engineer_vocab_semantic_rules')) {
    function seo_classifier_engineer_vocab_semantic_rules() {
        return [
            ['group'=>'aplicacion','label'=>'Inflado','patterns'=>['/\binflad(?:o|ar|or|ora|ores)\b/iu']],
            ['group'=>'aplicacion','label'=>'Herramientas neumáticas','patterns'=>['/\bherramientas?\s+neum[aá]ticas?\b/iu']],
            ['group'=>'aplicacion','label'=>'Pintura y pulverización','patterns'=>['/\b(?:pintura|pintar|pulverizaci[oó]n|aerograf[ií]a)\b/iu']],
            ['group'=>'aplicacion','label'=>'Limpieza','patterns'=>['/\blimpieza\b/iu']],
            ['group'=>'plataforma','label'=>'Automoción','patterns'=>['/\b(?:automoci[oó]n|autom[oó]vil|veh[ií]culo|coche)\b/iu']],
            ['group'=>'plataforma','label'=>'Bicicleta','patterns'=>['/\bbicicletas?\b/iu']],
            ['group'=>'subtipo','label'=>'Portátil','patterns'=>['/\bport[aá]til(?:es)?\b/iu']],
            ['group'=>'subtipo','label'=>'Silencioso','patterns'=>['/\bsilencios[oa]s?\b/iu']],
            ['group'=>'subtipo','label'=>'Sin aceite','patterns'=>['/\b(?:sin\s+aceite|oil[ -]?free)\b/iu']],
            ['group'=>'subtipo','label'=>'Doble cilindro','patterns'=>['/\bdoble\s+cilindro\b/iu']],
        ];
    }
}

if (!function_exists('seo_classifier_engineer_vocab_prepare_row')) {
    function seo_classifier_engineer_vocab_prepare_row(array $row) {
        $parts = [
            (string) ($row['summary'] ?? ''),
            implode(' ', (array) ($row['tags'] ?? [])),
        ];
        $evidence = [];
        foreach ((array) ($row['facts'] ?? []) as $fact) {
            $text = trim((string) ($fact['evidence'] ?? ''));
            if ($text === '') continue;
            $evidence[] = $text;
            $parts[] = $text;
        }
        return [
            'knowledge_type'=>sanitize_key((string) ($row['knowledge_type'] ?? '')),
            'status'=>sanitize_key((string) ($row['status'] ?? 'review')),
            'confidence'=>max(0.0, min(1.0, (float) ($row['confidence'] ?? 0))),
            'text'=>trim(implode(' ', $parts)),
            'summary'=>trim((string) ($row['summary'] ?? '')),
            'evidence'=>$evidence,
        ];
    }
}

if (!function_exists('seo_classifier_engineer_vocab_rows')) {
    function seo_classifier_engineer_vocab_rows($term_id) {
        $term_id = absint($term_id);
        if ($term_id < 1 || !class_exists('SEO_Ingeniero')) {
            return new WP_Error('classifier_engineer_unavailable', 'Ingeniero no está disponible.');
        }

        $knowledge = class_exists('SEO_Ingeniero_DB')
            ? SEO_Ingeniero_DB::knowledge_for_category($term_id, false, SEO_Ingeniero::LESSON_TECHNICAL)
            : [];
        $knowledge = array_values(array_filter((array) $knowledge, static function($row) {
            return in_array(sanitize_key((string) ($row['status'] ?? '')), ['active','review'], true);
        }));
        if (!$knowledge) {
            return new WP_Error('classifier_engineer_empty', 'La categoría no tiene conocimiento activo ni pendiente de revisión en Ingeniero.');
        }

        $rows = [];
        foreach ((array) $knowledge as $row) {
            $rows[] = seo_classifier_engineer_vocab_prepare_row((array) $row);
        }
        return $rows;
    }
}

if (!function_exists('seo_classifier_engineer_vocab_find_evidence')) {
    function seo_classifier_engineer_vocab_find_evidence(array $rows, array $patterns) {
        $matches = [];
        $best_confidence = 0.0;
        $status_counts = ['active'=>0,'review'=>0];
        foreach ($rows as $row) {
            $text = (string) ($row['text'] ?? '');
            $hit = false;
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $text)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) continue;
            $status = sanitize_key((string) ($row['status'] ?? 'review'));
            if (isset($status_counts[$status])) $status_counts[$status]++;
            $best_confidence = max($best_confidence, (float) ($row['confidence'] ?? 0));
            $snippet = '';
            foreach ((array) ($row['evidence'] ?? []) as $evidence) {
                foreach ($patterns as $pattern) {
                    if (@preg_match($pattern, $evidence)) {
                        $snippet = $evidence;
                        break 2;
                    }
                }
            }
            if ($snippet === '') $snippet = (string) ($row['summary'] ?? '');
            if ($snippet !== '') $matches[] = wp_trim_words(wp_strip_all_tags($snippet), 28, '…');
        }
        return [
            'matched'=>!empty($matches),
            'confidence'=>round($best_confidence, 4),
            'evidence'=>array_values(array_unique(array_slice($matches, 0, 3))),
            'evidence_status'=>$status_counts,
            'provisional'=>empty($status_counts['active']) && !empty($status_counts['review']),
        ];
    }
}

if (!function_exists('seo_classifier_engineer_vocab_similarity')) {
    function seo_classifier_engineer_vocab_similarity($a, $b) {
        $a = seo_classifier_engineer_vocab_normalize($a);
        $b = seo_classifier_engineer_vocab_normalize($b);
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        $ta = array_values(array_unique(array_filter(explode(' ', $a))));
        $tb = array_values(array_unique(array_filter(explode(' ', $b))));
        if (!$ta || !$tb) return 0.0;
        $intersection = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));
        return $union > 0 ? $intersection / $union : 0.0;
    }
}

if (!function_exists('seo_classifier_engineer_vocab_attribute_match')) {
    function seo_classifier_engineer_vocab_attribute_match(array $rule, array $catalog) {
        $best = null;
        $best_score = 0.0;
        foreach ($catalog as $definition) {
            $slug = sanitize_key((string) ($definition['slug'] ?? ''));
            $name = (string) ($definition['nombre'] ?? '');
            if ($slug === sanitize_key((string) $rule['slug'])) {
                return ['status'=>'covered','score'=>1.0,'definition'=>$definition];
            }
            $score = max(
                seo_classifier_engineer_vocab_similarity((string) $rule['name'], $name),
                seo_classifier_engineer_vocab_similarity((string) $rule['slug'], $slug)
            );
            if ($score > $best_score) {
                $best_score = $score;
                $best = $definition;
            }
        }
        if ($best && $best_score >= 0.50) {
            return ['status'=>'possible','score'=>round($best_score, 3),'definition'=>$best];
        }
        return ['status'=>'new','score'=>0.0,'definition'=>null];
    }
}

if (!function_exists('seo_classifier_engineer_vocab_label_match')) {
    function seo_classifier_engineer_vocab_label_match(array $rule, array $index) {
        $group = sanitize_key((string) ($rule['group'] ?? ''));
        $rows = (array) ($index[$group] ?? []);
        $best = null;
        $best_score = 0.0;
        foreach ($rows as $row) {
            $label = (string) ($row['label'] ?? '');
            $score = seo_classifier_engineer_vocab_similarity((string) $rule['label'], $label);
            if ($score >= 0.999) return ['status'=>'covered','score'=>1.0,'term'=>$row];
            if ($score > $best_score) {
                $best_score = $score;
                $best = $row;
            }
        }
        if ($best && $best_score >= 0.60) {
            return ['status'=>'possible','score'=>round($best_score, 3),'term'=>$best];
        }
        return ['status'=>'new','score'=>0.0,'term'=>null];
    }
}

if (!function_exists('seo_classifier_engineer_vocab_report')) {
    function seo_classifier_engineer_vocab_report($term_id, $prepared_rows = null, $attribute_catalog = null, $vocabulary_index = null, $prepared_term = null) {
        $term_id = absint($term_id);
        $term = $prepared_term ?: ($term_id ? get_term($term_id, 'product_cat') : null);
        if (!$term || is_wp_error($term)) {
            return new WP_Error('classifier_engineer_category', 'La categoría no existe.');
        }

        $rows = is_array($prepared_rows) ? $prepared_rows : seo_classifier_engineer_vocab_rows($term_id);
        if (is_wp_error($rows)) return $rows;

        if (!is_array($attribute_catalog)) {
            $attribute_catalog = function_exists('seo_classifier_attribute_catalog')
                ? (array) seo_classifier_attribute_catalog()
                : [];
        }
        if (!is_array($vocabulary_index)) {
            $vocabulary_index = function_exists('seo_classifier_vocabulary_index')
                ? (array) seo_classifier_vocabulary_index()
                : [];
        }

        $attribute_results = [];
        foreach (seo_classifier_engineer_vocab_attribute_rules() as $rule) {
            $signal = seo_classifier_engineer_vocab_find_evidence($rows, (array) $rule['patterns']);
            if (empty($signal['matched'])) continue;
            $match = seo_classifier_engineer_vocab_attribute_match($rule, $attribute_catalog);

            $terms = [];
            foreach ((array) ($rule['term_patterns'] ?? []) as $label => $pattern) {
                $term_signal = seo_classifier_engineer_vocab_find_evidence($rows, [$pattern]);
                if (!empty($term_signal['matched'])) $terms[] = $label;
            }

            $attribute_results[] = [
                'slug'=>$rule['slug'],
                'name'=>$rule['name'],
                'type'=>$rule['type'],
                'unit'=>$rule['unit'],
                'group'=>$rule['group'],
                'status'=>$match['status'],
                'match_score'=>$match['score'],
                'existing_id'=>absint($match['definition']['id'] ?? 0),
                'existing_name'=>(string) ($match['definition']['nombre'] ?? ''),
                'suggested_terms'=>$terms,
                'confidence'=>$signal['confidence'],
                'evidence'=>$signal['evidence'],
                'evidence_status'=>$signal['evidence_status'],
                'provisional'=>!empty($signal['provisional']),
            ];
        }

        $label_results = [];
        foreach (seo_classifier_engineer_vocab_semantic_rules() as $rule) {
            $signal = seo_classifier_engineer_vocab_find_evidence($rows, (array) $rule['patterns']);
            if (empty($signal['matched'])) continue;
            $match = seo_classifier_engineer_vocab_label_match($rule, $vocabulary_index);
            $label_results[] = [
                'group'=>$rule['group'],
                'label'=>$rule['label'],
                'status'=>$match['status'],
                'match_score'=>$match['score'],
                'existing_id'=>absint($match['term']['id'] ?? 0),
                'existing_label'=>(string) ($match['term']['label'] ?? ''),
                'confidence'=>$signal['confidence'],
                'evidence'=>$signal['evidence'],
                'evidence_status'=>$signal['evidence_status'],
                'provisional'=>!empty($signal['provisional']),
            ];
        }

        $stats = ['new'=>0,'possible'=>0,'covered'=>0];
        foreach (array_merge($attribute_results, $label_results) as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($stats[$status])) $stats[$status]++;
        }

        $knowledge_status = ['active'=>0,'review'=>0];
        foreach ($rows as $row) {
            $status = sanitize_key((string) ($row['status'] ?? 'review'));
            if (isset($knowledge_status[$status])) $knowledge_status[$status]++;
        }

        return [
            'schema'=>'seo-classifier-engineer-vocab-report',
            'schema_version'=>3,
            'generated_at'=>current_time('mysql'),
            'term_id'=>$term_id,
            'category'=>(string) $term->name,
            'knowledge_count'=>count($rows),
            'knowledge_status'=>$knowledge_status,
            'uses_provisional_knowledge'=>!empty($knowledge_status['review']),
            'attributes'=>$attribute_results,
            'labels'=>$label_results,
            'stats'=>$stats,
            'policy'=>'Analiza conocimiento active o review de Ingeniero. La aceptación explícita puede ampliar los maestros canónicos, pero no asigna automáticamente esos conceptos a productos ni contenido público.',
        ];
    }
}

if (!function_exists('seo_classifier_engineer_vocab_bulk_reports')) {
    function seo_classifier_engineer_vocab_bulk_reports() {
        if (!class_exists('SEO_Ingeniero') || !class_exists('SEO_Ingeniero_DB')) {
            return new WP_Error('classifier_engineer_unavailable', 'Ingeniero no está disponible.');
        }

        global $wpdb;
        $table = SEO_Ingeniero_DB::table('knowledge');
        $raw = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE lesson=%s AND status IN ('active','review')
             ORDER BY term_id ASC, knowledge_type ASC",
            SEO_Ingeniero::LESSON_TECHNICAL
        ), ARRAY_A);
        if (!$raw) return [];

        $by_term = [];
        foreach ($raw as $row) {
            $term_id = absint($row['term_id'] ?? 0);
            if ($term_id < 1) continue;
            $row['facts'] = json_decode((string) ($row['facts_json'] ?? ''), true);
            $row['tags'] = json_decode((string) ($row['tags_json'] ?? ''), true);
            $row['source_ids'] = json_decode((string) ($row['source_ids_json'] ?? ''), true);
            if (!is_array($row['facts'])) $row['facts'] = [];
            if (!is_array($row['tags'])) $row['tags'] = [];
            if (!is_array($row['source_ids'])) $row['source_ids'] = [];
            $by_term[$term_id][] = seo_classifier_engineer_vocab_prepare_row($row);
        }
        if (!$by_term) return [];

        $attribute_catalog = function_exists('seo_classifier_attribute_catalog')
            ? (array) seo_classifier_attribute_catalog()
            : [];
        $vocabulary_index = function_exists('seo_classifier_vocabulary_index')
            ? (array) seo_classifier_vocabulary_index()
            : [];

        $terms = get_terms([
            'taxonomy'=>'product_cat',
            'hide_empty'=>false,
            'include'=>array_keys($by_term),
            'orderby'=>'name',
            'order'=>'ASC',
        ]);
        if (is_wp_error($terms)) {
            return new WP_Error('classifier_engineer_terms', $terms->get_error_message());
        }

        $reports = [];
        foreach ((array) $terms as $term) {
            $term_id = absint($term->term_id ?? 0);
            if ($term_id < 1 || empty($by_term[$term_id])) continue;
            $report = seo_classifier_engineer_vocab_report(
                $term_id,
                $by_term[$term_id],
                $attribute_catalog,
                $vocabulary_index,
                $term
            );
            if (!is_wp_error($report)) $reports[] = $report;
        }
        return $reports;
    }
}

if (!function_exists('seo_classifier_engineer_vocab_flat_rows')) {
    function seo_classifier_engineer_vocab_flat_rows(array $reports) {
        $rows = [];
        foreach ($reports as $report) {
            $term_id = absint($report['term_id'] ?? 0);
            $category = (string) ($report['category'] ?? '');
            foreach ((array) ($report['attributes'] ?? []) as $item) {
                $rows[] = [
                    'term_id'=>$term_id,
                    'category'=>$category,
                    'kind'=>'attribute',
                    'key'=>sanitize_key((string) ($item['slug'] ?? '')),
                    'value'=>(string) ($item['name'] ?? ''),
                    'model'=>trim((string) ($item['type'] ?? '') . (!empty($item['unit']) ? ' · ' . $item['unit'] : '') . (!empty($item['group']) ? ' · ' . $item['group'] : '')),
                    'status'=>(string) ($item['status'] ?? 'new'),
                    'existing'=>(string) ($item['existing_name'] ?? ''),
                    'match_score'=>(float) ($item['match_score'] ?? 0),
                    'confidence'=>(float) ($item['confidence'] ?? 0),
                    'evidence'=>(array) ($item['evidence'] ?? []),
                    'evidence_status'=>(array) ($item['evidence_status'] ?? []),
                    'provisional'=>!empty($item['provisional']),
                    'suggested_terms'=>(array) ($item['suggested_terms'] ?? []),
                ];
            }
            foreach ((array) ($report['labels'] ?? []) as $item) {
                $group = sanitize_key((string) ($item['group'] ?? ''));
                $label = (string) ($item['label'] ?? '');
                $rows[] = [
                    'term_id'=>$term_id,
                    'category'=>$category,
                    'kind'=>'label',
                    'key'=>$group . ':' . sanitize_title($label),
                    'value'=>$label,
                    'model'=>strtoupper($group),
                    'status'=>(string) ($item['status'] ?? 'new'),
                    'existing'=>(string) ($item['existing_label'] ?? ''),
                    'match_score'=>(float) ($item['match_score'] ?? 0),
                    'confidence'=>(float) ($item['confidence'] ?? 0),
                    'evidence'=>(array) ($item['evidence'] ?? []),
                    'evidence_status'=>(array) ($item['evidence_status'] ?? []),
                    'provisional'=>!empty($item['provisional']),
                    'suggested_terms'=>[],
                ];
            }
        }

        $weight = ['new'=>0,'possible'=>1,'covered'=>2];
        usort($rows, static function($a,$b) use ($weight) {
            $wa = $weight[$a['status']] ?? 9;
            $wb = $weight[$b['status']] ?? 9;
            if ($wa !== $wb) return $wa <=> $wb;
            if ((float)$a['confidence'] !== (float)$b['confidence']) {
                return (float)$a['confidence'] > (float)$b['confidence'] ? -1 : 1;
            }
            $cmp = strcasecmp((string)$a['category'], (string)$b['category']);
            if ($cmp !== 0) return $cmp;
            return strcasecmp((string)$a['value'], (string)$b['value']);
        });
        return $rows;
    }
}

if (!function_exists('seo_classifier_engineer_vocab_find_candidate')) {
    function seo_classifier_engineer_vocab_find_candidate($term_id, $kind, $key) {
        $report = seo_classifier_engineer_vocab_report(absint($term_id));
        if (is_wp_error($report)) return $report;

        $kind = sanitize_key((string) $kind);
        $key = sanitize_text_field((string) $key);
        if ($kind === 'attribute') {
            foreach ((array) ($report['attributes'] ?? []) as $row) {
                if (sanitize_key((string) ($row['slug'] ?? '')) === sanitize_key($key)) {
                    return $row + ['kind'=>'attribute'];
                }
            }
        } elseif ($kind === 'label') {
            foreach ((array) ($report['labels'] ?? []) as $row) {
                $row_key = sanitize_key((string) ($row['group'] ?? '')) . ':' . sanitize_title((string) ($row['label'] ?? ''));
                if ($row_key === $key) return $row + ['kind'=>'label'];
            }
        }
        return new WP_Error('classifier_engineer_candidate', 'La propuesta ya no existe para esta categoría.');
    }
}

if (!function_exists('seo_classifier_engineer_vocab_accept_candidate')) {
    function seo_classifier_engineer_vocab_accept_candidate($term_id, $kind, $key) {
        global $wpdb;
        $candidate = seo_classifier_engineer_vocab_find_candidate($term_id, $kind, $key);
        if (is_wp_error($candidate)) return $candidate;
        if ((string) ($candidate['status'] ?? '') !== 'new') {
            return new WP_Error('classifier_engineer_not_new', 'La propuesta ya está cubierta o tiene un posible equivalente y no se creará automáticamente.');
        }

        $kind = sanitize_key((string) $kind);
        if ($kind === 'attribute') {
            if (!function_exists('seo_attributes_save_definition') || !function_exists('seo_attributes_get_definition')) {
                return new WP_Error('classifier_engineer_attributes', 'No está disponible la escritura canónica de atributos.');
            }
            $slug = sanitize_key((string) ($candidate['slug'] ?? ''));
            $existing = seo_attributes_get_definition($slug, false);
            $created = false;
            if (!$existing) {
                try {
                    seo_attributes_save_definition([
                        'slug'=>$slug,
                        'nombre'=>(string) ($candidate['name'] ?? $slug),
                        'grupo'=>(string) ($candidate['group'] ?? 'general'),
                        'tipo'=>(string) ($candidate['type'] ?? 'texto'),
                        'unidad_tipo'=>'',
                        'unidad_base'=>(string) ($candidate['unit'] ?? ''),
                        'multiple'=>false,
                        'filtrable'=>true,
                        'visible'=>true,
                        'seo'=>true,
                        'orden'=>900,
                        'activo'=>true,
                    ], 'classifier_engineer_vocab');
                    $existing = seo_attributes_get_definition($slug, false);
                    $created = true;
                } catch (Throwable $e) {
                    return new WP_Error('classifier_engineer_attribute_create', $e->getMessage());
                }
            }

            $attribute_id = absint($existing['id'] ?? 0);
            if ($attribute_id > 0 && (string) ($candidate['type'] ?? '') === 'termino' && function_exists('seo_attributes_save_term') && function_exists('seo_attributes_tables')) {
                $all_terms = [];
                $reports = seo_classifier_engineer_vocab_bulk_reports();
                if (!is_wp_error($reports)) {
                    foreach ((array) $reports as $report) {
                        foreach ((array) ($report['attributes'] ?? []) as $row) {
                            if (sanitize_key((string) ($row['slug'] ?? '')) !== $slug) continue;
                            foreach ((array) ($row['suggested_terms'] ?? []) as $term_name) {
                                $term_name = sanitize_text_field((string) $term_name);
                                if ($term_name !== '') $all_terms[$term_name] = true;
                            }
                        }
                    }
                }
                $tables = seo_attributes_tables();
                foreach (array_keys($all_terms) as $term_name) {
                    $term_slug = sanitize_title($term_name);
                    $exists = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$tables['terms']} WHERE atributo_id=%d AND slug=%s LIMIT 1",
                        $attribute_id,
                        $term_slug
                    ));
                    if ($exists > 0) continue;
                    try {
                        seo_attributes_save_term([
                            'atributo_id'=>$attribute_id,
                            'slug'=>$term_slug,
                            'nombre'=>$term_name,
                            'orden'=>0,
                            'activo'=>true,
                        ], 'classifier_engineer_vocab');
                    } catch (Throwable $e) {
                        return new WP_Error('classifier_engineer_attribute_term', $e->getMessage());
                    }
                }
            }

            return [
                'kind'=>'attribute',
                'created'=>$created,
                'label'=>(string) ($candidate['name'] ?? $slug),
                'id'=>$attribute_id,
            ];
        }

        if ($kind === 'label') {
            $group = sanitize_key((string) ($candidate['group'] ?? ''));
            $label = sanitize_text_field((string) ($candidate['label'] ?? ''));
            if (!in_array($group, ['aplicacion','plataforma','subtipo'], true) || $label === '') {
                return new WP_Error('classifier_engineer_label', 'La propuesta semántica no es válida.');
            }

            if (function_exists('seo_catalog_find_active_vocabulary_term')) {
                $active = seo_catalog_find_active_vocabulary_term($group, $label);
                if ($active) {
                    return ['kind'=>'label','created'=>false,'label'=>$label,'id'=>absint($active['id'] ?? 0)];
                }
            }

            $slug = sanitize_title($label);
            $table = $wpdb->prefix . 'seo_vocabulary';
            $same = $wpdb->get_row($wpdb->prepare(
                "SELECT id,active FROM {$table} WHERE semantic_group=%s AND slug=%s LIMIT 1",
                $group,
                $slug
            ), ARRAY_A);
            if (is_array($same) && !empty($same['id'])) {
                if ((int) ($same['active'] ?? 0) !== 1) {
                    return new WP_Error('classifier_engineer_label_inactive', 'Ya existe una etiqueta inactiva equivalente. Revísala antes de reutilizarla.');
                }
                return ['kind'=>'label','created'=>false,'label'=>$label,'id'=>absint($same['id'])];
            }

            $ok = $wpdb->insert(
                $table,
                [
                    'semantic_group'=>$group,
                    'slug'=>$slug,
                    'label'=>$label,
                    'source'=>'classifier_engineer',
                    'active'=>1,
                ],
                ['%s','%s','%s','%s','%d']
            );
            if (!$ok) {
                return new WP_Error('classifier_engineer_label_create', $wpdb->last_error ?: 'No se pudo crear la etiqueta canónica.');
            }
            return ['kind'=>'label','created'=>true,'label'=>$label,'id'=>absint($wpdb->insert_id)];
        }

        return new WP_Error('classifier_engineer_kind', 'Tipo de propuesta no válido.');
    }
}

if (!function_exists('seo_classifier_engineer_vocab_accept_all')) {
    function seo_classifier_engineer_vocab_accept_all() {
        $reports = seo_classifier_engineer_vocab_bulk_reports();
        if (is_wp_error($reports)) return $reports;
        $rows = seo_classifier_engineer_vocab_flat_rows((array) $reports);

        $unique = [];
        $skipped_provisional = 0;
        foreach ($rows as $row) {
            if ((string) ($row['status'] ?? '') !== 'new') continue;
            if (!empty($row['provisional'])) {
                $skipped_provisional++;
                continue;
            }
            $dedupe = (string) ($row['kind'] ?? '') . '|' . (string) ($row['key'] ?? '');
            if (!isset($unique[$dedupe]) || (float) $row['confidence'] > (float) $unique[$dedupe]['confidence']) {
                $unique[$dedupe] = $row;
            }
        }

        $created = 0;
        $reused = 0;
        $errors = [];
        foreach ($unique as $row) {
            $result = seo_classifier_engineer_vocab_accept_candidate(
                absint($row['term_id'] ?? 0),
                (string) ($row['kind'] ?? ''),
                (string) ($row['key'] ?? '')
            );
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
                continue;
            }
            if (!empty($result['created'])) $created++; else $reused++;
        }

        return [
            'created'=>$created,
            'reused'=>$reused,
            'errors'=>$errors,
            'skipped_provisional'=>$skipped_provisional,
            'candidates'=>count($unique),
        ];
    }
}

