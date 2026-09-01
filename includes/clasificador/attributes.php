<?php
/**
 * Clasificación de atributos canónicos de producto.
 *
 * Solo propone definiciones activas. Los atributos tipo término reutilizan
 * términos/aliases activos; los numéricos requieren evidencia explícita.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_phrase_in_text')) {
    function seo_classifier_phrase_in_text($phrase, $text) {
        $phrase = seo_classifier_normalize((string)$phrase);
        $text = seo_classifier_normalize((string)$text);
        if ($phrase === '' || $text === '') return false;
        return strpos(' ' . $text . ' ', ' ' . $phrase . ' ') !== false;
    }
}

if (!function_exists('seo_classifier_attribute_match_segments')) {
    function seo_classifier_attribute_match_segments(array $definition, array $context) {
        $segments = function_exists('seo_classifier_context_source_segments')
            ? seo_classifier_context_source_segments($context)
            : ['title'=>(string)($context['title']??''),'local'=>(string)($context['full']??'')];
        $scores = [
            'title'=>1.00,
            'identity'=>0.99,
            'supplier'=>0.97,
            'external_structured'=>0.96,
            'brands'=>0.96,
            'tags'=>0.94,
            'local'=>0.92,
            'external_meta'=>0.89,
            'categories'=>0.86,
            'external_body'=>0.79,
        ];
        $external_relevance = max(0.0, min(1.0, (float)($context['external']['relevance'] ?? 0.0)));
        if ($external_relevance > 0.0) {
            $factor = max(0.45, $external_relevance);
            $scores['external_structured'] *= $factor;
            $scores['external_meta'] *= $factor;
            $scores['external_body'] *= $factor;
        }
        $slug = sanitize_key((string)($definition['slug'] ?? ''));
        if ($slug !== 'marca') $scores['brands'] = 0.72;
        return [$segments, $scores];
    }
}

if (!function_exists('seo_classifier_controlled_attribute_candidates')) {
    function seo_classifier_controlled_attribute_candidates(array $definition, array $context) {
        $attribute_id = (int)($definition['id'] ?? 0);
        if ($attribute_id < 1) return [];
        $aliases = seo_classifier_attribute_alias_index();
        list($segments, $source_scores) = seo_classifier_attribute_match_segments($definition, $context);
        $matches = [];

        foreach ((array)($definition['terms'] ?? []) as $term) {
            $term_id = (int)($term['id'] ?? 0);
            $term_name = trim((string)($term['nombre'] ?? ''));
            if ($term_id < 1 || $term_name === '') continue;
            $variants = array_merge([$term_name], (array)($aliases[$attribute_id][$term_id] ?? []));
            foreach ($variants as $variant) {
                $variant = trim((string)$variant);
                if ($variant === '') continue;
                foreach ($source_scores as $source=>$score) {
                    $text = (string)($segments[$source] ?? '');
                    if ($text === '' || !seo_classifier_phrase_in_text($variant, $text)) continue;
                    $matches[] = [
                        'term_id'=>$term_id,
                        'value'=>$term_name,
                        'score'=>$score,
                        'matched'=>$variant,
                        'source'=>$source,
                        'reason'=>'término/alias exacto en ' . str_replace('_', ' ', $source),
                        'length'=>mb_strlen(seo_classifier_normalize($variant), 'UTF-8'),
                    ];
                }
            }
        }

        usort($matches, static function($a, $b) {
            $as = (float)($a['score'] ?? 0);
            $bs = (float)($b['score'] ?? 0);
            if ($as !== $bs) return $as > $bs ? -1 : 1;
            $al = (int)($a['length'] ?? 0);
            $bl = (int)($b['length'] ?? 0);
            return $al === $bl ? 0 : ($al > $bl ? -1 : 1);
        });

        $unique = [];
        foreach ($matches as $match) {
            $key = (int)$match['term_id'];
            if (!isset($unique[$key])) $unique[$key] = $match;
        }
        return array_values($unique);
    }
}

if (!function_exists('seo_classifier_format_number_unit')) {
    function seo_classifier_format_number_unit($number, $unit = '') {
        $number = str_replace(',', '.', trim((string)$number));
        $unit = trim((string)$unit);
        $unit_norm = strtolower(str_replace([' ', '.', '·'], '', $unit));
        $canonical = [
            'nm'=>'N·m','n-m'=>'N·m','n/m'=>'N·m','v'=>'V','w'=>'W','kw'=>'kW','a'=>'A','ah'=>'Ah','mah'=>'mAh',
            'hz'=>'Hz','khz'=>'kHz','mhz'=>'MHz','ghz'=>'GHz','rpm'=>'rpm','kg'=>'kg','g'=>'g','lb'=>'lb','t'=>'t',
            'mm'=>'mm','cm'=>'cm','m'=>'m','l'=>'L','ml'=>'ml','l/min'=>'L/min','bar'=>'bar','psi'=>'psi','db'=>'dB',
            'awg'=>'AWG','ud'=>'ud','uds'=>'ud','piezas'=>'ud','pieza'=>'ud','mm2'=>'mm²','mm²'=>'mm²','°c'=>'°C','ºc'=>'°C',
        ];
        if (isset($canonical[$unit_norm])) $unit = $canonical[$unit_norm];
        return trim($number . ($unit !== '' ? ' ' . $unit : ''));
    }
}

if (!function_exists('seo_classifier_numeric_source_texts')) {
    function seo_classifier_numeric_source_texts(array $context) {
        $external_relevance = max(0.0, min(1.0, (float)($context['external']['relevance'] ?? 0.0)));
        $factor = $external_relevance > 0.0 ? max(0.45, $external_relevance) : 1.0;
        return [
            ['name'=>'title','text'=>(string)($context['raw_title'] ?? ''),'score'=>1.00],
            ['name'=>'supplier','text'=>(string)($context['raw_supplier'] ?? ''),'score'=>0.97],
            ['name'=>'external_structured','text'=>(string)($context['raw_external_structured'] ?? ''),'score'=>0.96 * $factor],
            ['name'=>'local','text'=>(string)($context['raw_local'] ?? ''),'score'=>0.92],
            ['name'=>'external_meta','text'=>(string)($context['raw_external_meta'] ?? ''),'score'=>0.88 * $factor],
            ['name'=>'external_body','text'=>(string)($context['raw_external_body'] ?? ''),'score'=>0.78 * $factor],
        ];
    }
}

if (!function_exists('seo_classifier_numeric_unit_regex')) {
    function seo_classifier_numeric_unit_regex(array $definition) {
        $units = function_exists('seo_classifier_numeric_units_for_attribute')
            ? seo_classifier_numeric_units_for_attribute($definition)
            : [];
        if (!$units) return '';
        $quoted = [];
        foreach ($units as $unit) {
            $unit = trim((string)$unit);
            if ($unit === '') continue;
            $quoted[] = preg_quote($unit, '/');
        }
        usort($quoted, static function($a,$b){ return strlen($b) <=> strlen($a); });
        return implode('|', array_unique($quoted));
    }
}

if (!function_exists('seo_classifier_extract_numeric_attribute')) {
    function seo_classifier_extract_numeric_attribute(array $definition, array $context) {
        $slug = sanitize_key((string)($definition['slug'] ?? ''));
        if ($slug === '') return null;
        $base_unit = trim((string)($definition['unidad_base'] ?? ''));
        $sources = seo_classifier_numeric_source_texts($context);

        foreach ($sources as $source) {
            $text = (string)$source['text'];
            $source_score = (float)$source['score'];
            if ($text === '') continue;

            if ($slug === 'diametro' && preg_match('/(?:Ø|ø|⌀|diam(?:etro|ètre)?)[\s:]*([0-9]+(?:[,.][0-9]+)?)[\s]*(mm|cm|m)?/iu', $text, $m)) {
                return ['value'=>seo_classifier_format_number_unit($m[1], $m[2] ?? $base_unit),'score'=>$source_score,'reason'=>'diámetro explícito','source'=>$source['name']];
            }
            if ($slug === 'numero_piezas') {
                if (preg_match('/\b(?:set|juego|kit|pack)\s+(?:de\s+)?([0-9]{1,4})\b/iu', $text, $m)
                    || preg_match('/\b([0-9]{1,4})\s*(?:piezas|uds?|unidades)\b/iu', $text, $m)) {
                    return ['value'=>seo_classifier_format_number_unit($m[1], $base_unit ?: 'ud'),'score'=>min(1.0,$source_score + 0.01),'reason'=>'cantidad explícita de piezas','source'=>$source['name']];
                }
            }
            if ($slug === 'carga_maxima' && preg_match('/\b(?:hasta|carga(?:\s+máxima)?|capacidad(?:\s+de\s+carga)?)\s*[:=]?\s*([0-9]+(?:[,.][0-9]+)?)\s*(kg|g|lb|t)\b/iu', $text, $m)) {
                return ['value'=>seo_classifier_format_number_unit($m[1], $m[2]),'score'=>min(0.99,$source_score),'reason'=>'carga máxima explícita','source'=>$source['name']];
            }

            if (preg_match('/(?:Ø|ø|⌀)\s*([0-9]+(?:[,.][0-9]+)?)\s*[x×]\s*([0-9]+(?:[,.][0-9]+)?)\s*(mm|cm|m)\b/iu', $text, $m)) {
                if ($slug === 'diametro') {
                    return ['value'=>seo_classifier_format_number_unit($m[1], $m[3]),'score'=>$source_score,'reason'=>'primera medida tras Ø','source'=>$source['name']];
                }
                if ($slug === 'longitud' && preg_match('/\bbrocas?\b/iu', (string)($context['raw_full'] ?? ''))) {
                    return ['value'=>seo_classifier_format_number_unit($m[2], $m[3]),'score'=>max(0.80,$source_score - 0.03),'reason'=>'segunda medida de broca Ø x longitud','source'=>$source['name']];
                }
            }

            $normalized = seo_classifier_normalize($text);
            $unit_regex = seo_classifier_numeric_unit_regex($definition);
            foreach (seo_classifier_attribute_label_variants($definition) as $variant) {
                $label = seo_classifier_normalize($variant);
                if ($label === '' || in_array($label, ['ø','⌀','para'], true)) continue;
                $quoted = preg_quote($label, '/');
                $pattern_unit = $unit_regex !== '' ? '(' . $unit_regex . ')?' : '([a-z°º·.\/]+)?';
                if (preg_match('/(?:^|\s)' . $quoted . '(?:\s+[^0-9]{0,25})?\s*[:=]?\s*([0-9]+(?:[,.][0-9]+)?)\s*' . $pattern_unit . '(?:\s|$)/iu', $normalized, $m)) {
                    $unit = $m[2] ?? $base_unit;
                    return ['value'=>seo_classifier_format_number_unit($m[1], $unit),'score'=>max(0.78,$source_score - 0.01),'reason'=>'valor junto al nombre del atributo','source'=>$source['name']];
                }
                if (preg_match('/(?:^|\s)([0-9]+(?:[,.][0-9]+)?)\s*' . $pattern_unit . '\s+(?:[^a-z0-9]{0,8})' . $quoted . '(?:\s|$)/iu', $normalized, $m)) {
                    $unit = $m[2] ?? $base_unit;
                    return ['value'=>seo_classifier_format_number_unit($m[1], $unit),'score'=>max(0.77,$source_score - 0.02),'reason'=>'valor antes del nombre del atributo','source'=>$source['name']];
                }
            }

            // Unidades muy específicas permiten extraer aunque no se nombre el atributo.
            $specific = [
                'tension'=>'v','potencia'=>'(?:w|kw)','capacidad_bateria'=>'(?:ah|mah)','corriente'=>'a',
                'frecuencia'=>'(?:hz|khz|mhz|ghz)','par'=>'(?:n\s*[·.\-]?\s*m|nm)','presion'=>'(?:bar|psi|mpa)',
                'nivel_sonoro'=>'db','flujo_luminoso'=>'lm','calibre_awg'=>'awg','caudal'=>'(?:l\/min|cfm)',
            ];
            if (isset($specific[$slug]) && preg_match('/\b([0-9]+(?:[,.][0-9]+)?)\s*(' . $specific[$slug] . ')\b/iu', $text, $m)) {
                return ['value'=>seo_classifier_format_number_unit($m[1], $m[2]),'score'=>max(0.76,$source_score - 0.04),'reason'=>'unidad técnica inequívoca','source'=>$source['name']];
            }
        }
        return null;
    }
}

if (!function_exists('seo_classifier_attribute_gaps')) {
    /** Señales conocidas cuyo atributo/valor aún no existe en el maestro. */
    function seo_classifier_attribute_gaps(array $context, array $catalog) {
        $slugs = [];
        foreach ($catalog as $definition) $slugs[sanitize_key((string)($definition['slug'] ?? ''))] = true;
        $text = (string)($context['full'] ?? '');
        $gaps = [];
        if (preg_match('/\bsds\s*(?:\+|plus|max)\b/u', $text) && empty($slugs['sistema_insercion'])) {
            $gaps[] = [
                'kind'=>'new_attribute','slug'=>'sistema_insercion','name'=>'Sistema de inserción','type'=>'termino',
                'evidence'=>'Se detectó una referencia SDS, pero no existe el atributo canónico.',
            ];
        }
        if (preg_match('/\bhss(?:g|co|-g|-co)\b/u', $text) && empty($slugs['material_corte'])) {
            $gaps[] = [
                'kind'=>'new_attribute','slug'=>'material_corte','name'=>'Material de corte','type'=>'termino',
                'evidence'=>'Se detectó HSS-G/HSS-Co, pero no existe un atributo específico de material de corte.',
            ];
        }
        return $gaps;
    }
}

if (!function_exists('seo_classifier_classify_product_attributes')) {
    function seo_classifier_classify_product_attributes($product_id, $current = null, array $options = []) {
        $product_id = absint($product_id);
        $current = is_array($current) ? $current : seo_classifier_current_product_attributes($product_id);
        $context = !empty($options['context']) && is_array($options['context'])
            ? $options['context']
            : seo_classifier_build_product_context($product_id, $options);
        $catalog = seo_classifier_attribute_catalog();

        $proposal = [];
        $review = [];
        $evidence = [];
        $fields = [];

        foreach ($catalog as $definition) {
            $slug = sanitize_key((string)($definition['slug'] ?? ''));
            $attribute_id = (int)($definition['id'] ?? 0);
            $type = (string)($definition['tipo'] ?? '');
            if ($slug === '' || $attribute_id < 1) continue;

            if (!empty($current[$slug])) {
                $fields[$slug] = [
                    'status'=>'current','current'=>(array)$current[$slug],'proposal'=>[],
                    'confidence'=>1.0,'definition'=>$definition,
                ];
                continue;
            }

            if ($type === 'termino') {
                $matches = seo_classifier_controlled_attribute_candidates($definition, $context);
                if (!$matches) {
                    $fields[$slug] = ['status'=>'unresolved','current'=>[],'proposal'=>[],'confidence'=>0.0,'definition'=>$definition];
                    continue;
                }
                $multiple = !empty($definition['multiple']);
                $safe_matches = array_values(array_filter($matches, static function($row) {
                    return (float)($row['score'] ?? 0) >= 0.95;
                }));
                $review_matches = array_values(array_filter($matches, static function($row) {
                    $score = (float)($row['score'] ?? 0);
                    return $score >= 0.82 && $score < 0.95;
                }));
                if ($safe_matches) {
                    $selected = $multiple ? array_slice($safe_matches, 0, 5) : [$safe_matches[0]];
                    $values = array_values(array_unique(array_map(static function($row){ return (string)$row['value']; }, $selected)));
                    $proposal[$slug] = $values;
                    $evidence[$slug] = $selected;
                    $fields[$slug] = [
                        'status'=>'safe','current'=>[],'proposal'=>$values,
                        'confidence'=>round((float)($selected[0]['score'] ?? 0),2),'definition'=>$definition,'candidates'=>$selected,
                    ];
                } elseif ($review_matches) {
                    $selected = $multiple ? array_slice($review_matches, 0, 5) : [$review_matches[0]];
                    $values = array_values(array_unique(array_map(static function($row){ return (string)$row['value']; }, $selected)));
                    $review[$slug] = $values;
                    $evidence[$slug] = $selected;
                    $fields[$slug] = [
                        'status'=>'review','current'=>[],'proposal'=>$values,
                        'confidence'=>round((float)($selected[0]['score'] ?? 0),2),'definition'=>$definition,'candidates'=>$selected,
                    ];
                } else {
                    $fields[$slug] = ['status'=>'unresolved','current'=>[],'proposal'=>[],'confidence'=>0.0,'definition'=>$definition];
                }
                continue;
            }

            if ($type === 'numero' || $type === 'rango') {
                $match = seo_classifier_extract_numeric_attribute($definition, $context);
                if ($match && (float)($match['score'] ?? 0) >= 0.94) {
                    $proposal[$slug] = [(string)$match['value']];
                    $evidence[$slug] = [$match];
                    $fields[$slug] = [
                        'status'=>'safe','current'=>[],'proposal'=>[(string)$match['value']],
                        'confidence'=>round((float)$match['score'],2),'definition'=>$definition,'candidates'=>[$match],
                    ];
                } elseif ($match && (float)($match['score'] ?? 0) >= 0.76) {
                    $review[$slug] = [(string)$match['value']];
                    $evidence[$slug] = [$match];
                    $fields[$slug] = [
                        'status'=>'review','current'=>[],'proposal'=>[(string)$match['value']],
                        'confidence'=>round((float)$match['score'],2),'definition'=>$definition,'candidates'=>[$match],
                    ];
                } else {
                    $fields[$slug] = ['status'=>'unresolved','current'=>[],'proposal'=>[],'confidence'=>0.0,'definition'=>$definition];
                }
                continue;
            }

            $fields[$slug] = ['status'=>'unresolved','current'=>[],'proposal'=>[],'confidence'=>0.0,'definition'=>$definition];
        }

        $external = (array)($context['external'] ?? []);
        $result = [
            'object_type'=>'product',
            'object_id'=>$product_id,
            'engine'=>'classifier_attributes_v2',
            'values'=>$proposal,
            'review'=>$review,
            'evidence'=>$evidence,
            'fields'=>$fields,
            'gaps'=>seo_classifier_attribute_gaps($context, $catalog),
            'sources'=>[
                'supplier'=>trim((string)($context['supplier'] ?? '')) !== '',
                'external_status'=>(string)($external['status'] ?? 'unavailable'),
                'external_relevance'=>round((float)($external['relevance'] ?? 0.0), 4),
            ],
            'confidence'=>$proposal ? min(array_map(static function($slug) use ($fields){ return (float)($fields[$slug]['confidence'] ?? 0); }, array_keys($proposal))) : 0.0,
            'legacy_detector'=>false,
            'viable'=>!empty($proposal),
        ];
        return apply_filters('seo_classifier_product_attributes_result', $result, $product_id, $current, $context, $options);
    }
}
