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

if (!function_exists('seo_classifier_engineer_vocab_rows')) {
    function seo_classifier_engineer_vocab_rows($term_id) {
        $term_id = absint($term_id);
        if ($term_id < 1 || !class_exists('SEO_Ingeniero')) {
            return new WP_Error('classifier_engineer_unavailable', 'Ingeniero no está disponible.');
        }

        $knowledge = SEO_Ingeniero::active_knowledge($term_id);
        if (!$knowledge) {
            return new WP_Error('classifier_engineer_empty', 'La categoría no tiene conocimiento activo de Ingeniero.');
        }

        $rows = [];
        foreach ((array) $knowledge as $row) {
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
            $rows[] = [
                'knowledge_type'=>sanitize_key((string) ($row['knowledge_type'] ?? '')),
                'confidence'=>max(0.0, min(1.0, (float) ($row['confidence'] ?? 0))),
                'text'=>trim(implode(' ', $parts)),
                'summary'=>trim((string) ($row['summary'] ?? '')),
                'evidence'=>$evidence,
            ];
        }
        return $rows;
    }
}

if (!function_exists('seo_classifier_engineer_vocab_find_evidence')) {
    function seo_classifier_engineer_vocab_find_evidence(array $rows, array $patterns) {
        $matches = [];
        $best_confidence = 0.0;
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
    function seo_classifier_engineer_vocab_report($term_id) {
        $term_id = absint($term_id);
        $term = $term_id ? get_term($term_id, 'product_cat') : null;
        if (!$term || is_wp_error($term)) {
            return new WP_Error('classifier_engineer_category', 'La categoría no existe.');
        }

        $rows = seo_classifier_engineer_vocab_rows($term_id);
        if (is_wp_error($rows)) return $rows;

        $attribute_catalog = function_exists('seo_classifier_attribute_catalog')
            ? (array) seo_classifier_attribute_catalog()
            : [];
        $vocabulary_index = function_exists('seo_classifier_vocabulary_index')
            ? (array) seo_classifier_vocabulary_index()
            : [];

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
            ];
        }

        $stats = ['new'=>0,'possible'=>0,'covered'=>0];
        foreach (array_merge($attribute_results, $label_results) as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($stats[$status])) $stats[$status]++;
        }

        return [
            'schema'=>'seo-classifier-engineer-vocab-report',
            'schema_version'=>1,
            'generated_at'=>current_time('mysql'),
            'term_id'=>$term_id,
            'category'=>(string) $term->name,
            'knowledge_count'=>count($rows),
            'attributes'=>$attribute_results,
            'labels'=>$label_results,
            'stats'=>$stats,
            'policy'=>'Solo propone. No crea etiquetas, términos ni atributos y no modifica productos.',
        ];
    }
}
