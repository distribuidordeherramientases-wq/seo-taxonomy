<?php
/**
 * Reglas conservadoras del Clasificador.
 *
 * Ajustan puntuaciones o extraen evidencias. Nunca escriben vocabulario.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_classifier_group_thresholds')) {
    function seo_classifier_group_thresholds($group) {
        $map = [
            'tipo'       => ['safe'=>0.79, 'review'=>0.63, 'margin'=>0.055, 'min_matched'=>2],
            'aplicacion' => ['safe'=>0.82, 'review'=>0.64, 'margin'=>0.065, 'min_matched'=>2],
            'plataforma' => ['safe'=>0.90, 'review'=>0.72, 'margin'=>0.08,  'min_matched'=>2],
            'subtipo'    => ['safe'=>0.86, 'review'=>0.66, 'margin'=>0.08,  'min_matched'=>2],
        ];
        $defaults = ['safe'=>0.82, 'review'=>0.66, 'margin'=>0.08, 'min_matched'=>2];
        return apply_filters('seo_classifier_group_thresholds', $map[$group] ?? $defaults, $group);
    }
}

if (!function_exists('seo_classifier_adjust_label_metric')) {
    /**
     * Refuerza identidad y reglas técnicas inequívocas.
     */
    function seo_classifier_adjust_label_metric($group, array $term, array $context, array $metric) {
        $score = (float)($metric['score'] ?? 0.0);
        $reasons = (array)($metric['reasons'] ?? []);
        if ($score <= 0) return $metric;

        $term_seq = (array)($term['concepts'] ?? seo_classifier_concept_sequence((string)($term['label'] ?? '')));
        $identity_seq = seo_classifier_concept_sequence((string)($context['identity'] ?? ''));
        $identity_set = array_fill_keys($identity_seq, true);
        $title_norm = (string)($context['title'] ?? '');
        $identity_norm = (string)($context['identity'] ?? '');

        if ($group === 'tipo') {
            $identity_matches = 0;
            foreach (array_unique($term_seq) as $token) if (isset($identity_set[$token])) $identity_matches++;
            $identity_ratio = $term_seq ? $identity_matches / max(1, count(array_unique($term_seq))) : 0.0;
            if ($identity_matches >= 3 || $identity_ratio >= 0.70) {
                $score += 0.12;
                $reasons[] = 'identidad principal fuerte';
            } elseif ($identity_matches >= 2 || $identity_ratio >= 0.50) {
                $score += 0.07;
                $reasons[] = 'apoyo de identidad principal';
            } elseif ($identity_matches === 0) {
                $score -= 0.06;
                $reasons[] = 'sin apoyo en identidad principal';
            }

            $leading = $identity_seq[0] ?? '';
            if ($leading !== '' && in_array($leading, $term_seq, true)) {
                $score += 0.055;
                $reasons[] = 'coincide con el concepto inicial';
            }

            // Pantallas OBD/HUD: la identidad visual pesa más que una función de diagnosis.
            $is_obd_display = preg_match('/\b(pantalla|display|monitor|hud)\b/u', $identity_norm)
                && preg_match('/\bobd(?:2|ii)?\b/u', $title_norm);
            if ($is_obd_display) {
                $has_display = in_array('pantalla', $term_seq, true);
                $has_obd = in_array('obd', $term_seq, true);
                if ($has_display && $has_obd) {
                    $score += 0.18;
                    $reasons[] = 'regla de identidad HUD/pantalla OBD';
                } elseif (in_array('diagnostico', $term_seq, true) && !$has_display) {
                    $score -= 0.13;
                    $reasons[] = 'diagnóstico aparece como función, no como identidad';
                }
            }

            // El sustantivo broca/cincel debe decidir la familia antes que SDS/HSS.
            if (preg_match('/\bbroca\b/u', $identity_norm)) {
                if (in_array('broca', $term_seq, true)) $score += 0.10;
                if (in_array('cincel', $term_seq, true)) $score -= 0.20;
            }
            if (preg_match('/\bcincel\b/u', $identity_norm)) {
                if (in_array('cincel', $term_seq, true)) $score += 0.10;
                if (in_array('broca', $term_seq, true)) $score -= 0.18;
            }
        }

        // PLATAFORMA necesita marca + familia técnica/voltaje; una marca aislada no basta.
        if ($group === 'plataforma') {
            $technical = preg_match('/\b(10[.,]?8|12|14[.,]?4|18|20|24|36|40|54|60)\s*v\b/u', (string)($context['full'] ?? ''))
                || preg_match('/\b(m12|m18|lxt|xr|multivolt|ampshare|flexvolt)\b/u', (string)($context['full'] ?? ''));
            if (!$technical || (int)($metric['title_matched'] ?? 0) < 1) {
                $score -= 0.10;
                $reasons[] = 'plataforma sin señal técnica completa';
            }
        }

        $metric['score'] = round(max(0.0, min(1.0, $score)), 4);
        $metric['reasons'] = array_values(array_unique($reasons));
        return apply_filters('seo_classifier_adjust_label_metric', $metric, $group, $term, $context);
    }
}

if (!function_exists('seo_classifier_unit_pattern')) {
    function seo_classifier_unit_pattern() {
        return 'mm(?:2|²)?|cm(?:2|²)?|m(?:2|²)?|v|w|kw|wh|kwh|ah|mah|a|hz|khz|mhz|ghz|bar|psi|pa|mpa|kg|g|lb|t|l\/min|l|ml|min|rpm|n[·.\- ]?m|cfm|lm|db|awg|u|ud|uds|piezas|pieza|dientes|ºc|°c|°';
    }
}

if (!function_exists('seo_classifier_attribute_label_variants')) {
    function seo_classifier_attribute_label_variants(array $definition) {
        $slug = sanitize_key((string)($definition['slug'] ?? ''));
        $name = trim((string)($definition['nombre'] ?? ''));
        $variants = [$name, str_replace('_', ' ', $slug)];
        $extra = [
            'altura'=>['altura','alto'],
            'anchura'=>['anchura','ancho'],
            'diametro'=>['diámetro','diametro','ø','⌀'],
            'espesor'=>['espesor','grosor'],
            'longitud'=>['longitud','largo','largo total'],
            'longitud_corte'=>['longitud de corte','largo de corte','longitud útil','longitud util'],
            'numero_piezas'=>['número de piezas','numero de piezas','piezas','set de','juego de','kit de'],
            'capacidad_bateria'=>['capacidad de batería','capacidad bateria','capacidad'],
            'carga_maxima'=>['carga máxima','carga maxima','capacidad de carga','hasta'],
            'tension'=>['voltaje','tensión','tension','voltage'],
            'potencia'=>['potencia','power'],
            'corriente'=>['corriente','amperaje'],
            'frecuencia'=>['frecuencia'],
            'par'=>['par','par máximo','par maximo','torque'],
            'presion'=>['presión','presion'],
            'peso'=>['peso'],
            'caudal'=>['caudal','flujo'],
            'nivel_sonoro'=>['nivel sonoro','ruido'],
            'temperatura'=>['temperatura','rango de temperatura'],
            'medida_pantalla'=>['medida de pantalla','tamaño de pantalla','tamano de pantalla','pantalla'],
            'proteccion_ip'=>['protección ip','proteccion ip','grado ip'],
            'interfaz'=>['interfaz','conexión','conexion','puerto'],
            'materiales_compatibles'=>['material compatible','materiales compatibles','para'],
            'tecnologia_motor'=>['motor','sin escobillas','brushless'],
        ];
        if (isset($extra[$slug])) $variants = array_merge($variants, $extra[$slug]);
        $out = [];
        foreach ($variants as $variant) {
            $variant = trim((string)$variant);
            if ($variant !== '') $out[] = $variant;
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('seo_classifier_numeric_units_for_attribute')) {
    function seo_classifier_numeric_units_for_attribute(array $definition) {
        $slug = sanitize_key((string)($definition['slug'] ?? ''));
        $map = [
            'altura'=>['mm','cm','m'],'anchura'=>['mm','cm','m'],'diametro'=>['mm','cm','m'],
            'espesor'=>['mm','cm'],'longitud'=>['mm','cm','m'],'longitud_corte'=>['mm','cm','m'],
            'profundidad'=>['mm','cm','m'],'profundidad_trabajo'=>['mm','cm','m'],'medida_pantalla'=>['mm','cm'],
            'capacidad'=>['l','ml'],'capacidad_bateria'=>['ah','mah'],'carga_maxima'=>['kg','g','lb','t'],
            'tension'=>['v'],'potencia'=>['w','kw'],'potencia_equivalente'=>['w','kw'],'corriente'=>['a'],
            'frecuencia'=>['hz','khz','mhz','ghz'],'par'=>['nm','n·m','n-m'],'presion'=>['bar','psi','pa','mpa'],
            'peso'=>['kg','g','lb'],'caudal'=>['l/min','cfm'],'flujo_luminoso'=>['lm'],'nivel_sonoro'=>['db'],
            'numero_piezas'=>['ud','uds','piezas'],'numero_bandejas'=>['ud','uds'],'numero_canales'=>['ud','uds'],
            'calibre_awg'=>['awg'],'seccion_conductor'=>['mm2','mm²'],'temperatura'=>['ºc','°c'],
        ];
        $units = $map[$slug] ?? [];
        $base = strtolower(trim((string)($definition['unidad_base'] ?? '')));
        if ($base !== '') $units[] = $base;
        return array_values(array_unique($units));
    }
}
