<?php
/**
 * Clasificación de atributos canónicos de producto.
 *
 * Política: solo propone definiciones activas. Los atributos tipo término solo
 * reutilizan términos/aliases activos; los numéricos requieren evidencia explícita.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_phrase_in_text')) {
    function seo_classifier_phrase_in_text($phrase, $text) {
        $phrase = seo_classifier_normalize((string) $phrase);
        $text = seo_classifier_normalize((string) $text);
        if ($phrase === '' || $text === '') return false;
        return strpos(' ' . $text . ' ', ' ' . $phrase . ' ') !== false;
    }
}

if (!function_exists('seo_classifier_controlled_attribute_candidates')) {
    function seo_classifier_controlled_attribute_candidates(array $definition, array $context) {
        $attribute_id = (int) ($definition['id'] ?? 0);
        if ($attribute_id < 1) return [];
        $aliases = seo_classifier_attribute_alias_index();
        $matches = [];

        foreach ((array) ($definition['terms'] ?? []) as $term) {
            $term_id = (int) ($term['id'] ?? 0);
            $term_name = trim((string) ($term['nombre'] ?? ''));
            if ($term_id < 1 || $term_name === '') continue;
            $variants = array_merge([$term_name], (array) ($aliases[$attribute_id][$term_id] ?? []));
            foreach ($variants as $variant) {
                $variant = trim((string) $variant);
                if ($variant === '') continue;
                $score = 0.0;
                $reason = '';
                if (seo_classifier_phrase_in_text($variant, (string) ($context['title'] ?? ''))) {
                    $score = 1.0;
                    $reason = 'término/alias exacto en título';
                } elseif (seo_classifier_phrase_in_text($variant, (string) ($context['full'] ?? ''))) {
                    $score = 0.94;
                    $reason = 'término/alias exacto en contenido';
                }
                if ($score <= 0) continue;
                $matches[] = [
                    'term_id'=>$term_id,
                    'value'=>$term_name,
                    'score'=>$score,
                    'matched'=>$variant,
                    'reason'=>$reason,
                    'length'=>mb_strlen(seo_classifier_normalize($variant), 'UTF-8'),
                ];
            }
        }

        usort($matches, static function($a, $b) {
            $as = (float) ($a['score'] ?? 0);
            $bs = (float) ($b['score'] ?? 0);
            if ($as !== $bs) return $as > $bs ? -1 : 1;
            $al = (int) ($a['length'] ?? 0);
            $bl = (int) ($b['length'] ?? 0);
            return $al === $bl ? 0 : ($al > $bl ? -1 : 1);
        });

        $unique = [];
        foreach ($matches as $match) {
            $key = (int) $match['term_id'];
            if (!isset($unique[$key])) $unique[$key] = $match;
        }
        return array_values($unique);
    }
}

if (!function_exists('seo_classifier_format_number_unit')) {
    function seo_classifier_format_number_unit($number, $unit = '') {
        $number = str_replace(',', '.', trim((string) $number));
        $unit = trim((string) $unit);
        return trim($number . ($unit !== '' ? ' ' . $unit : ''));
    }
}

if (!function_exists('seo_classifier_extract_numeric_attribute')) {
    function seo_classifier_extract_numeric_attribute(array $definition, array $context) {
        $slug = sanitize_key((string) ($definition['slug'] ?? ''));
        if ($slug === '') return null;
        $raw_title = (string) ($context['raw_title'] ?? '');
        $raw_full = (string) ($context['raw_full'] ?? '');
        $base_unit = trim((string) ($definition['unidad_base'] ?? ''));
        $unit_pattern = seo_classifier_unit_pattern();

        // Reglas inequívocas frecuentes.
        if ($slug === 'diametro') {
            if (preg_match('/(?:Ø|ø|⌀|diam(?:etro|\x{00E9}tro)?)[\s:]*([0-9]+(?:[,.][0-9]+)?)[\s]*(mm|cm|m)?/iu', $raw_title, $m)) {
                return ['value'=>seo_classifier_format_number_unit($m[1], $m[2] ?? $base_unit), 'score'=>1.0, 'reason'=>'diámetro explícito en título'];
            }
        }

        if ($slug === 'numero_piezas') {
            if (preg_match('/\b(?:set|juego|kit|pack)\s+de\s+([0-9]{1,4})\b/iu', $raw_title, $m)) {
                return ['value'=>seo_classifier_format_number_unit($m[1], $base_unit ?: 'ud'), 'score'=>0.99, 'reason'=>'cantidad explícita en set/juego'];
            }
            if (preg_match('/\b([0-9]{1,4})\s*(?:piezas|uds?|unidades)\b/iu', $raw_title, $m)) {
                return ['value'=>seo_classifier_format_number_unit($m[1], $base_unit ?: 'ud'), 'score'=>0.99, 'reason'=>'cantidad explícita en título'];
            }
        }

        if ($slug === 'carga_maxima') {
            if (preg_match('/\bhasta\s+([0-9]+(?:[,.][0-9]+)?)\s*(kg|g|lb|t)\b/iu', $raw_title, $m)) {
                return ['value'=>seo_classifier_format_number_unit($m[1], $m[2]), 'score'=>0.98, 'reason'=>'carga máxima explícita con «hasta»'];
            }
        }

        // Dimensiones compactas: Ø16 x 178 mm. El diámetro es inequívoco;
        // la segunda medida se acepta como longitud solo si existe señal de broca.
        if (preg_match('/(?:Ø|ø|⌀)\s*([0-9]+(?:[,.][0-9]+)?)\s*[x×]\s*([0-9]+(?:[,.][0-9]+)?)\s*(mm|cm|m)\b/iu', $raw_title, $m)) {
            if ($slug === 'diametro') {
                return ['value'=>seo_classifier_format_number_unit($m[1], $m[3]), 'score'=>1.0, 'reason'=>'primera medida tras símbolo Ø'];
            }
            if ($slug === 'longitud' && preg_match('/\bbrocas?\b/iu', $raw_full)) {
                return ['value'=>seo_classifier_format_number_unit($m[2], $m[3]), 'score'=>0.95, 'reason'=>'segunda medida de broca Ø x longitud'];
            }
        }

        // Regla genérica: nombre del atributo y número/unidad deben estar próximos.
        $normalized = seo_classifier_normalize($raw_full);
        foreach (seo_classifier_attribute_label_variants($definition) as $variant) {
            $label = seo_classifier_normalize($variant);
            if ($label === '' || $label === 'ø') continue;
            $quoted = preg_quote($label, '/');
            if (preg_match('/(?:^|\s)' . $quoted . '(?:\s+[^0-9]{0,25})?\s+([0-9]+(?:[,.][0-9]+)?)\s*(' . $unit_pattern . ')?(?:\s|$)/iu', $normalized, $m)) {
                $unit = $m[2] ?? $base_unit;
                return ['value'=>seo_classifier_format_number_unit($m[1], $unit), 'score'=>0.97, 'reason'=>'valor numérico junto al nombre del atributo'];
            }
            if (preg_match('/(?:^|\s)([0-9]+(?:[,.][0-9]+)?)\s*(' . $unit_pattern . ')?\s+(?:[^a-z0-9]{0,8})' . $quoted . '(?:\s|$)/iu', $normalized, $m)) {
                $unit = $m[2] ?? $base_unit;
                return ['value'=>seo_classifier_format_number_unit($m[1], $unit), 'score'=>0.96, 'reason'=>'valor numérico antes del nombre del atributo'];
            }
        }
        return null;
    }
}

if (!function_exists('seo_classifier_classify_product_attributes')) {
    function seo_classifier_classify_product_attributes($product_id, $current = null) {
        $product_id = absint($product_id);
        $current = is_array($current) ? $current : seo_classifier_current_product_attributes($product_id);
        $context = seo_classifier_build_product_context($product_id);
        $catalog = seo_classifier_attribute_catalog();

        $proposal = [];
        $review = [];
        $evidence = [];
        $fields = [];

        foreach ($catalog as $definition) {
            $slug = sanitize_key((string) ($definition['slug'] ?? ''));
            $attribute_id = (int) ($definition['id'] ?? 0);
            $type = (string) ($definition['tipo'] ?? '');
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
                    return (float)($row['score'] ?? 0) >= 0.98;
                }));
                $review_matches = array_values(array_filter($matches, static function($row) {
                    $score = (float)($row['score'] ?? 0);
                    return $score >= 0.90 && $score < 0.98;
                }));
                if ($safe_matches) {
                    $selected = $multiple ? array_slice($safe_matches, 0, 5) : [$safe_matches[0]];
                    $values = array_values(array_unique(array_map(static function($row) { return (string)$row['value']; }, $selected)));
                    $proposal[$slug] = $values;
                    $evidence[$slug] = $selected;
                    $fields[$slug] = [
                        'status'=>'safe','current'=>[],'proposal'=>$values,
                        'confidence'=>round((float)($selected[0]['score'] ?? 0), 2),'definition'=>$definition,
                        'candidates'=>$selected,
                    ];
                } elseif ($review_matches) {
                    $selected = $multiple ? array_slice($review_matches, 0, 5) : [$review_matches[0]];
                    $values = array_values(array_unique(array_map(static function($row) { return (string)$row['value']; }, $selected)));
                    $review[$slug] = $values;
                    $evidence[$slug] = $selected;
                    $fields[$slug] = [
                        'status'=>'review','current'=>[],'proposal'=>$values,
                        'confidence'=>round((float)($selected[0]['score'] ?? 0), 2),'definition'=>$definition,
                        'candidates'=>$selected,
                    ];
                } else {
                    $fields[$slug] = ['status'=>'unresolved','current'=>[],'proposal'=>[],'confidence'=>0.0,'definition'=>$definition];
                }
                continue;
            }

            if ($type === 'numero' || $type === 'rango') {
                $match = seo_classifier_extract_numeric_attribute($definition, $context);
                if ($match && (float)($match['score'] ?? 0) >= 0.96) {
                    $proposal[$slug] = [(string)$match['value']];
                    $evidence[$slug] = [$match];
                    $fields[$slug] = [
                        'status'=>'safe','current'=>[],'proposal'=>[(string)$match['value']],
                        'confidence'=>round((float)$match['score'],2),'definition'=>$definition,'candidates'=>[$match],
                    ];
                } elseif ($match) {
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

            // Texto/boolean requieren extractores específicos; no inventamos valores.
            $fields[$slug] = ['status'=>'unresolved','current'=>[],'proposal'=>[],'confidence'=>0.0,'definition'=>$definition];
        }

        $result = [
            'object_type'=>'product',
            'object_id'=>$product_id,
            'engine'=>'classifier_attributes_v1',
            'values'=>$proposal,
            'review'=>$review,
            'evidence'=>$evidence,
            'fields'=>$fields,
            'confidence'=>$proposal ? 0.96 : 0.0,
            'legacy_detector'=>false,
            'viable'=>!empty($proposal),
        ];
        return apply_filters('seo_classifier_product_attributes_result', $result, $product_id, $current, $context);
    }
}
