<?php
/**
 * Descubrimiento conservador de huecos en el vocabulario semántico.
 *
 * El Clasificador puede detectar un concepto que no tenga equivalente canónico
 * suficientemente próximo. Este módulo SOLO lo propone: la creación y la
 * asignación siguen requiriendo una acción explícita del administrador.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_new_label_groups')) {
    function seo_classifier_new_label_groups() {
        return ['tipo', 'aplicacion', 'plataforma', 'subtipo'];
    }
}

if (!function_exists('seo_classifier_new_term_phrase')) {
    function seo_classifier_new_term_phrase($raw, array $context = []) {
        $text = html_entity_decode(wp_strip_all_tags((string) $raw), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim((string) $text));
        if ($text === '') return '';

        foreach ((array) ($context['brand_names'] ?? []) as $brand) {
            $brand = trim((string) $brand);
            if ($brand === '') continue;
            $text = preg_replace('/^' . preg_quote($brand, '/') . '\b[\s:–—-]*/iu', '', $text, 1);
        }

        $text = preg_replace('/^(?:set|juego|pack|lote)\s+de\s+\d+\s+/iu', '', $text);
        $text = preg_replace('/^(?:kit|set|juego|pack)\s+/iu', '', $text);
        $text = trim((string) $text, " \t\n\r\0\x0B,.;:|–—-_");

        // El primer complemento comercial/técnico suele marcar el final de la identidad.
        $parts = preg_split('/\s+(?:con|para|hasta|incluye|incluyendo)\s+/iu', $text, 2);
        $text = trim((string) ($parts[0] ?? $text));

        // Quita colas puramente métricas sin destruir designaciones como SDS Plus o M18.
        $text = preg_replace('/\s+\d+(?:[\.,]\d+)?\s*(?:mm|cm|kg|bar|psi|l\/min|lpm|v|w|kw|ah|nm|rpm)\b.*$/iu', '', $text);
        $text = preg_replace('/\s+/u', ' ', trim((string) $text));
        if ($text === '') return '';

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $word_count = count((array) $words);
        if ($word_count < 2 || $word_count > 7) return '';
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length < 7 || $length > 90) return '';

        $numeric = 0;
        foreach ((array) $words as $word) {
            if (preg_match('/^\d+(?:[\.,]\d+)?$/', (string) $word)) $numeric++;
        }
        if ($word_count > 0 && ($numeric / $word_count) > 0.34) return '';
        return $text;
    }
}

if (!function_exists('seo_classifier_new_term_similarity')) {
    function seo_classifier_new_term_similarity($a, $b) {
        $na = seo_classifier_normalize((string) $a);
        $nb = seo_classifier_normalize((string) $b);
        if ($na === '' || $nb === '') return 0.0;
        if ($na === $nb) return 1.0;
        if (strpos(' ' . $na . ' ', ' ' . $nb . ' ') !== false || strpos(' ' . $nb . ' ', ' ' . $na . ' ') !== false) {
            $len_a = max(1, strlen($na));
            $len_b = max(1, strlen($nb));
            $ratio = min($len_a, $len_b) / max($len_a, $len_b);
            if ($ratio >= 0.78) return min(0.96, 0.78 + ($ratio * 0.18));
        }
        $ca = array_values(array_unique(seo_classifier_concept_sequence($a)));
        $cb = array_values(array_unique(seo_classifier_concept_sequence($b)));
        if (!$ca || !$cb) return 0.0;
        $intersection = count(array_intersect($ca, $cb));
        $union = count(array_unique(array_merge($ca, $cb)));
        return $union > 0 ? ($intersection / $union) : 0.0;
    }
}

if (!function_exists('seo_classifier_new_term_nearest_existing')) {
    function seo_classifier_new_term_nearest_existing($group, $label) {
        $best = ['id'=>0, 'label'=>'', 'slug'=>'', 'similarity'=>0.0];
        foreach ((array) (seo_classifier_vocabulary_index()[$group] ?? []) as $row) {
            $candidate = trim((string) ($row['label'] ?? $row['slug'] ?? ''));
            if ($candidate === '') continue;
            $similarity = seo_classifier_new_term_similarity($label, $candidate);
            if ($similarity > (float) $best['similarity']) {
                $best = [
                    'id'=>absint($row['id'] ?? 0),
                    'label'=>(string) ($row['label'] ?? ''),
                    'slug'=>(string) ($row['slug'] ?? ''),
                    'similarity'=>round($similarity, 4),
                ];
            }
        }
        return $best;
    }
}

if (!function_exists('seo_classifier_new_application_candidate')) {
    function seo_classifier_new_application_candidate($raw) {
        $normalized = seo_classifier_normalize((string) $raw);
        if ($normalized === '') return false;
        $signals = [
            'perforacion','fijacion','corte','lijado','soldadura','diagnostico','mantenimiento','carpinteria',
            'fontaneria','pintura','limpieza','elevacion','transporte','jardineria','riego','mecanizado',
            'electricidad','medicion','climatizacion','inflado','demolicion','atornillado','pulido','aspiracion',
        ];
        foreach ($signals as $signal) {
            if (strpos(' ' . $normalized . ' ', ' ' . $signal . ' ') !== false) return true;
        }
        return false;
    }
}

if (!function_exists('seo_classifier_new_platform_candidates')) {
    function seo_classifier_new_platform_candidates(array $context) {
        $text = implode(' ', [
            (string) ($context['raw_title'] ?? ''),
            (string) ($context['raw_supplier'] ?? ''),
            (string) ($context['raw_external_structured'] ?? ''),
        ]);
        $patterns = [
            '/\bSDS\s*Plus\b/iu'=>'SDS Plus',
            '/\bSDS\s*Max\b/iu'=>'SDS Max',
            '/\bStarlock(?:\s*Plus|\s*Max)?\b/iu'=>null,
            '/\bX-?LOCK\b/iu'=>'X-LOCK',
            '/\bAMPShare\b/iu'=>'AMPShare',
            '/\bFLEXVOLT\b/iu'=>'FLEXVOLT',
            '/\bONE\+\b/iu'=>'ONE+',
            '/\b(?:M12|M18|XGT|LXT)\b/u'=>null,
        ];
        $out = [];
        foreach ($patterns as $pattern=>$canonical) {
            if (!preg_match_all($pattern, $text, $matches)) continue;
            foreach ((array) ($matches[0] ?? []) as $match) {
                $label = $canonical !== null ? $canonical : trim((string) $match);
                if ($label !== '') $out[$label] = true;
            }
        }
        return array_keys($out);
    }
}

if (!function_exists('seo_classifier_new_term_raw_candidates')) {
    function seo_classifier_new_term_raw_candidates($group, array $context) {
        $raw = [];
        $title = trim((string) ($context['raw_title'] ?? ''));
        $supplier = trim((string) (($context['source_data']['supplier_name'] ?? '') ?: ''));

        if (in_array($group, ['tipo','subtipo'], true)) {
            foreach ([['text'=>$title,'source'=>'título'], ['text'=>$supplier,'source'=>'proveedor']] as $source_row) {
                if (trim((string) $source_row['text']) === '') continue;
                $segments = preg_split('/\s*[;,|]\s*|\s+[–—]\s+/u', (string) $source_row['text']);
                foreach ((array) $segments as $i=>$segment) {
                    if ($group === 'tipo' && $i > 0) continue;
                    if ($group === 'subtipo' && $i > 1) continue;
                    $label = seo_classifier_new_term_phrase($segment, $context);
                    if ($label === '') continue;
                    $raw[] = [
                        'label'=>$label,
                        'source'=>$source_row['source'],
                        'position'=>(int) $i,
                        'base_score'=>$group === 'tipo' ? ($i === 0 ? 0.86 : 0.73) : ($i === 0 ? 0.76 : 0.86),
                    ];
                }
            }
        } elseif ($group === 'aplicacion') {
            foreach ((array) ($context['category_names'] ?? []) as $category) {
                if (!seo_classifier_new_application_candidate($category)) continue;
                $label = seo_classifier_new_term_phrase($category, $context);
                if ($label !== '') $raw[] = ['label'=>$label,'source'=>'categoría','position'=>0,'base_score'=>0.80];
            }
            $use_text = implode(' ', [$title, $supplier]);
            if (preg_match_all('/\b(?:para|uso\s+en|aplicaci[oó]n(?:es)?\s*:?)[\s]+([^,;.|]{5,70})/iu', $use_text, $matches)) {
                foreach ((array) ($matches[1] ?? []) as $match) {
                    if (!seo_classifier_new_application_candidate($match)) continue;
                    $label = seo_classifier_new_term_phrase($match, $context);
                    if ($label !== '') $raw[] = ['label'=>$label,'source'=>'uso declarado','position'=>0,'base_score'=>0.76];
                }
            }
        } elseif ($group === 'plataforma') {
            foreach (seo_classifier_new_platform_candidates($context) as $platform) {
                $raw[] = ['label'=>$platform,'source'=>'sistema técnico','position'=>0,'base_score'=>0.88];
            }
        }
        return $raw;
    }
}

if (!function_exists('seo_classifier_discover_new_label_terms')) {
    /**
     * Devuelve como máximo una propuesta nueva por dimensión para evitar ruido.
     */
    function seo_classifier_discover_new_label_terms($group, array $context, array $current = [], array $ranked = [], array $options = []) {
        $group = sanitize_key((string) $group);
        if (!in_array($group, seo_classifier_new_label_groups(), true) || !empty($current[$group])) return [];

        $aggregated = [];
        foreach (seo_classifier_new_term_raw_candidates($group, $context) as $candidate) {
            $label = trim((string) ($candidate['label'] ?? ''));
            if ($label === '') continue;
            $key = seo_classifier_normalize($label);
            if ($key === '') continue;
            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'label'=>$label,
                    'score'=>(float) ($candidate['base_score'] ?? 0.0),
                    'sources'=>[],
                    'reasons'=>[],
                ];
            } else {
                $aggregated[$key]['score'] = max((float)$aggregated[$key]['score'], (float)($candidate['base_score'] ?? 0.0));
            }
            $source = trim((string) ($candidate['source'] ?? 'contexto'));
            $aggregated[$key]['sources'][$source] = true;
        }

        $type_labels = seo_classifier_label_map(['tipo'=>(array)($current['tipo'] ?? [])]);
        $type_label = (string) (($type_labels['tipo'][0] ?? ''));
        $rows = [];
        foreach ($aggregated as $candidate) {
            $label = (string) $candidate['label'];
            $score = (float) $candidate['score'];
            $sources = array_keys((array) $candidate['sources']);
            if (count($sources) > 1) $score += min(0.08, 0.04 * (count($sources) - 1));

            if ($group === 'subtipo' && $type_label !== '' && seo_classifier_new_term_similarity($label, $type_label) >= 0.80) {
                continue;
            }

            $nearest = seo_classifier_new_term_nearest_existing($group, $label);
            // Una similitud alta significa que debe reutilizarse el inventario, no crear un duplicado.
            if ((float) ($nearest['similarity'] ?? 0.0) >= 0.72) continue;
            if ($score < 0.75) continue;

            $reasons = ['concepto explícito en ' . implode(' + ', $sources), 'sin equivalente canónico suficientemente próximo'];
            if (!empty($nearest['label'])) {
                $reasons[] = 'más cercano: ' . $nearest['label'] . ' (' . round(((float)$nearest['similarity']) * 100) . '%)';
            }
            $rows[] = [
                'group'=>$group,
                'label'=>$label,
                'score'=>round(min(0.95, $score), 2),
                'sources'=>$sources,
                'nearest_existing'=>$nearest,
                'reasons'=>$reasons,
            ];
        }

        usort($rows, static function($a, $b) {
            return ((float)($b['score'] ?? 0)) <=> ((float)($a['score'] ?? 0));
        });
        $rows = array_slice($rows, 0, 1);
        return apply_filters('seo_classifier_new_label_terms', $rows, $group, $context, $current, $ranked, $options);
    }
}
