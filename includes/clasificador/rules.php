<?php
/**
 * Reglas conservadoras del Clasificador.
 *
 * Las reglas solo ajustan puntuaciones o extraen evidencias. Nunca escriben
 * vocabulario ni sustituyen datos actuales.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_group_thresholds')) {
    function seo_classifier_group_thresholds($group) {
        $map = [
            'tipo'       => ['safe'=>0.76, 'review'=>0.62, 'margin'=>0.055, 'min_matched'=>2],
            'aplicacion' => ['safe'=>0.69, 'review'=>0.58, 'margin'=>0.08,  'min_matched'=>2],
            'plataforma' => ['safe'=>0.87, 'review'=>0.70, 'margin'=>0.08,  'min_matched'=>2],
            'subtipo'    => ['safe'=>0.82, 'review'=>0.61, 'margin'=>0.09,  'min_matched'=>2],
        ];
        $defaults = ['safe'=>0.80, 'review'=>0.65, 'margin'=>0.08, 'min_matched'=>2];
        return apply_filters('seo_classifier_group_thresholds', $map[$group] ?? $defaults, $group);
    }
}

if (!function_exists('seo_classifier_adjust_label_metric')) {
    /**
     * Refuerza la identidad principal para TIPO. Esto evita que una función
     * secundaria al final del título secuestre la clasificación del producto.
     */
    function seo_classifier_adjust_label_metric($group, array $term, array $context, array $metric) {
        $score = (float) ($metric['score'] ?? 0.0);
        $reasons = (array) ($metric['reasons'] ?? []);
        if ($score <= 0) return $metric;

        if ($group === 'tipo') {
            $term_seq = (array) ($term['concepts'] ?? seo_classifier_concept_sequence((string) ($term['label'] ?? '')));
            $identity_seq = seo_classifier_concept_sequence((string) ($context['identity'] ?? ''));
            $identity_set = array_fill_keys($identity_seq, true);
            $identity_matches = 0;
            foreach (array_unique($term_seq) as $token) {
                if (isset($identity_set[$token])) $identity_matches++;
            }
            $identity_ratio = $term_seq ? $identity_matches / max(1, count(array_unique($term_seq))) : 0.0;

            if ($identity_matches >= 3 || $identity_ratio >= 0.70) {
                $score += 0.15;
                $reasons[] = 'identidad principal fuerte';
            } elseif ($identity_matches >= 2 || $identity_ratio >= 0.50) {
                $score += 0.09;
                $reasons[] = 'apoyo de identidad principal';
            } elseif ($identity_matches === 0) {
                $score -= 0.08;
                $reasons[] = 'sin apoyo en identidad principal';
            }

            $leading = $identity_seq[0] ?? '';
            if ($leading !== '' && in_array($leading, $term_seq, true)) {
                $score += 0.08;
                $reasons[] = 'coincide con el concepto inicial';
            }
        }

        // PLATAFORMA debe tener evidencia técnica explícita; una marca sola no basta.
        if ($group === 'plataforma' && (int) ($metric['title_matched'] ?? 0) < 2) {
            $score -= 0.08;
            $reasons[] = 'plataforma sin señal técnica suficiente';
        }

        $metric['score'] = round(max(0.0, min(1.0, $score)), 4);
        $metric['reasons'] = array_values(array_unique($reasons));
        return apply_filters('seo_classifier_adjust_label_metric', $metric, $group, $term, $context);
    }
}

if (!function_exists('seo_classifier_unit_pattern')) {
    function seo_classifier_unit_pattern() {
        return 'mm|cm|m|v|w|kw|wh|kwh|ah|mah|a|hz|khz|mhz|bar|psi|kg|g|lb|t|l|min|ml|rpm|nm|cfm|lm|db|awg|u|ud|uds|piezas|pieza|dientes|ºc|°c|°';
    }
}

if (!function_exists('seo_classifier_attribute_label_variants')) {
    function seo_classifier_attribute_label_variants(array $definition) {
        $slug = sanitize_key((string) ($definition['slug'] ?? ''));
        $name = trim((string) ($definition['nombre'] ?? ''));
        $variants = [$name, str_replace('_', ' ', $slug)];
        $extra = [
            'diametro'          => ['diámetro','diametro','ø'],
            'longitud'          => ['longitud','largo'],
            'longitud_corte'    => ['longitud de corte','largo de corte'],
            'numero_piezas'     => ['número de piezas','numero de piezas','piezas','set de','juego de'],
            'capacidad_bateria' => ['capacidad de batería','capacidad bateria'],
            'carga_maxima'      => ['carga máxima','carga maxima','hasta'],
            'voltaje'           => ['voltaje','tensión','tension'],
            'potencia'          => ['potencia'],
            'corriente'         => ['corriente','amperaje'],
            'frecuencia'        => ['frecuencia'],
        ];
        if (isset($extra[$slug])) $variants = array_merge($variants, $extra[$slug]);
        $out = [];
        foreach ($variants as $variant) {
            $variant = trim((string) $variant);
            if ($variant !== '') $out[] = $variant;
        }
        return array_values(array_unique($out));
    }
}
