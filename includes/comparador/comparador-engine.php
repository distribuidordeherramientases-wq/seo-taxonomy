<?php
/**
 * Comparador - motor de inteligencia comparativa v1.0.
 *
 * Trabaja exclusivamente con catálogo propio y datos ya persistidos por otros
 * servicios. No consulta Google Shopping, GA4, GSC, Bing ni sitios externos.
 */

defined('ABSPATH') || exit;

final class SEO_Comparador_Engine {
    const SETTINGS_OPTION = 'seo_comparador_settings';
    const AUTO_STATE_OPTION = 'seo_comparador_auto_refresh_state';
    const AUTO_REFRESH_HOOK = 'seo_comparador_auto_refresh';
    const AUTO_REFRESH_STEP_HOOK = 'seo_comparador_auto_refresh_step';
    const META_SOURCE_HASH = '_seo_comparador_source_hash';
    const META_REVIEW_BASELINE = '_seo_comparador_review_baseline';
    const META_PENDING_PAYLOAD = '_seo_comparador_pending_payload';
    const META_PENDING_DETECTED_AT = '_seo_comparador_pending_detected_at';

    public static function init() {
        add_action('init', array(__CLASS__, 'schedule_automatic_refresh'), 21);
        add_action(self::AUTO_REFRESH_HOOK, array(__CLASS__, 'automatic_refresh'));
        add_action(self::AUTO_REFRESH_STEP_HOOK, array(__CLASS__, 'automatic_refresh'));
        add_action('seo_post_editor_before_form', array(__CLASS__, 'render_pending_review_panel'), 30, 1);
        add_action('admin_post_seo_comparador_post_update_action', array(__CLASS__, 'handle_post_update_action'));
    }

    public static function schedule_automatic_refresh() {
        if (!wp_next_scheduled(self::AUTO_REFRESH_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::AUTO_REFRESH_HOOK);
        }
    }

    public static function kick_automatic_refresh() {
        if (!wp_next_scheduled(self::AUTO_REFRESH_STEP_HOOK)) {
            wp_schedule_single_event(time() + 15, self::AUTO_REFRESH_STEP_HOOK);
        }
    }

    private static function automatic_category_ids() {
        $ids = get_terms(array(
            'taxonomy'=>'product_cat',
            'hide_empty'=>false,
            'fields'=>'ids',
            'number'=>0,
            'orderby'=>'term_id',
            'order'=>'ASC',
        ));
        if (is_wp_error($ids)) return array();
        return array_values(array_unique(array_filter(array_map('absint',(array)$ids))));
    }

    public static function ensure_all_category_profiles() {
        global $wpdb;
        SEO_Comparador_DB::maybe_install();

        $terms = get_terms(array(
            'taxonomy'=>'product_cat',
            'hide_empty'=>false,
            'orderby'=>'term_id',
            'order'=>'ASC',
        ));
        if (is_wp_error($terms)) return 0;

        $profiles_table = SEO_Comparador_DB::table('profiles');
        $existing_rows = (array) $wpdb->get_results(
            $wpdb->prepare('SELECT primary_category_id FROM %i', $profiles_table),
            ARRAY_A
        );
        $existing = array_fill_keys(array_filter(array_map(
            'absint',
            wp_list_pluck($existing_rows, 'primary_category_id')
        )), true);

        $now = self::now();
        $added = 0;
        foreach ((array) $terms as $term) {
            if (!$term instanceof WP_Term) continue;
            $term_id = absint($term->term_id);
            if (!$term_id || isset($existing[$term_id])) continue;

            $inserted = $wpdb->insert($profiles_table, array(
                'canonical_key'=>self::profile_key($term_id),
                'canonical_name'=>sanitize_text_field((string) $term->name),
                'category_ids'=>wp_json_encode(array($term_id)),
                'primary_category_id'=>$term_id,
                'status'=>'detected',
                'own_products_count'=>0,
                'external_products_seen'=>0,
                'external_products_comparable'=>0,
                'comparison_axes_count'=>0,
                'confidence'=>0,
                'source_snapshot_at'=>null,
                'generated_at'=>null,
                'source_hash'=>'',
                'recommended_action'=>'',
                'decision_reason'=>'',
                'coverage_json'=>null,
                'editorial_decided_at'=>null,
                'last_error'=>null,
                'created_at'=>$now,
                'updated_at'=>$now,
            ));
            if (false !== $inserted) $added++;
        }

        // Si aparecen categorías que aún no tenían perfil, reinicia el cursor
        // automático para garantizar que todas sean calculadas.
        if ($added > 0) {
            delete_option(self::AUTO_STATE_OPTION);
        }

        return $added;
    }

    private static function fresh_auto_state() {
        return array(
            'cursor'=>0,
            'processed'=>0,
            'errors'=>0,
            'coverage_rebuilt'=>false,
            'complete'=>false,
            'started_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql'),
            'completed_at'=>'',
        );
    }

    public static function automatic_refresh() {
        $lock_key='seo_comparador_auto_refresh_lock';
        if (get_transient($lock_key)) return;
        set_transient($lock_key,1,2*MINUTE_IN_SECONDS);

        try {
            SEO_Comparador_DB::maybe_install();
            self::ensure_all_category_profiles();
            $state=get_option(self::AUTO_STATE_OPTION,array());
            if (!is_array($state) || !$state) $state=self::fresh_auto_state();

            if (!empty($state['complete'])) {
                $completed=!empty($state['completed_at']) ? strtotime((string)$state['completed_at']) : 0;
                if ($completed && (time()-$completed) < 6*HOUR_IN_SECONDS) return;
                $state=self::fresh_auto_state();
            }

            $ids=self::automatic_category_ids();
            if (!$ids) {
                $state['complete']=true;
                $state['completed_at']=current_time('mysql');
                $state['updated_at']=current_time('mysql');
                update_option(self::AUTO_STATE_OPTION,$state,false);
                return;
            }

            if (empty($state['coverage_rebuilt']) && class_exists('SEO_Editorial_Coverage')) {
                SEO_Editorial_Coverage::rebuild_index(7000);
                $state['coverage_rebuilt']=true;
            }

            $cursor=max(0,absint($state['cursor'] ?? 0));
            $slice=array_slice($ids,$cursor,10);

            foreach ($slice as $term_id) {
                $profile=self::build_profile($term_id);
                $state['processed']=absint($state['processed'] ?? 0)+1;
                if (is_wp_error($profile) || empty($profile['id'])) {
                    $state['errors']=absint($state['errors'] ?? 0)+1;
                    continue;
                }
                $decision=self::evaluate_editorial_decision(absint($profile['id']));
                if (is_wp_error($decision)) {
                    $state['errors']=absint($state['errors'] ?? 0)+1;
                }
            }

            $state['cursor']=$cursor+count($slice);
            $state['updated_at']=current_time('mysql');
            if ($state['cursor'] >= count($ids)) {
                $state['complete']=true;
                $state['completed_at']=current_time('mysql');
            }
            update_option(self::AUTO_STATE_OPTION,$state,false);

            if (empty($state['complete']) && !wp_next_scheduled(self::AUTO_REFRESH_STEP_HOOK)) {
                wp_schedule_single_event(time()+30,self::AUTO_REFRESH_STEP_HOOK);
            }
        } finally {
            delete_transient($lock_key);
        }
    }

    public static function defaults() {
        return array(
            'store_compare_max' => 6,
            'max_own_products' => 300,
            'max_external_products' => 500,
            'max_axes' => 20,
            'min_products' => 3,
            'min_axis_coverage' => 0.50,
            'min_axis_confidence' => 0.60,
            'max_representative_external' => 6,
        );
    }

    public static function settings() {
        $stored = get_option(self::SETTINGS_OPTION, array());
        return self::sanitize_settings(wp_parse_args(is_array($stored) ? $stored : array(), self::defaults()));
    }

    public static function sanitize_settings($raw) {
        $raw = wp_parse_args(is_array($raw) ? $raw : array(), self::defaults());
        return array(
            'store_compare_max' => max(2, min(6, absint($raw['store_compare_max']))),
            'max_own_products' => max(20, min(1000, absint($raw['max_own_products']))),
            'max_external_products' => max(20, min(2000, absint($raw['max_external_products']))),
            'max_axes' => max(3, min(40, absint($raw['max_axes']))),
            'min_products' => max(2, min(20, absint($raw['min_products']))),
            'min_axis_coverage' => max(0.10, min(1.00, (float) $raw['min_axis_coverage'])),
            'min_axis_confidence' => max(0.10, min(1.00, (float) $raw['min_axis_confidence'])),
            'max_representative_external' => max(0, min(20, absint($raw['max_representative_external']))),
        );
    }

    public static function save_settings($raw) {
        $settings = self::sanitize_settings($raw);
        update_option(self::SETTINGS_OPTION, $settings, false);
        return $settings;
    }

    private static function now() {
        return current_time('mysql');
    }

    public static function normalize_text($value) {
        $value = remove_accents(wp_strip_all_tags((string) $value));
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private static function canonical_axis_key($label) {
        $n = self::normalize_text($label);
        $map = array(
            'power' => array('potencia','power','watt','watts'),
            'capacity' => array('capacidad','capacity','volumen','volume'),
            'pressure' => array('presion','pressure'),
            'torque' => array('par','torque'),
            'voltage' => array('voltaje','voltage','tension'),
            'frequency' => array('frecuencia','frequency'),
            'load' => array('carga','carga maxima','max load','load capacity'),
            'weight' => array('peso','weight'),
            'speed' => array('velocidad','rpm','speed'),
            'flow' => array('caudal','flow'),
            'diameter' => array('diametro','diameter'),
            'length' => array('longitud','length'),
            'width' => array('ancho','width'),
            'height' => array('altura','height'),
            'temperature' => array('temperatura','temperature'),
        );
        foreach ($map as $key=>$aliases) {
            foreach ($aliases as $alias) {
                if ($n === $alias || strpos(' ' . $n . ' ', ' ' . $alias . ' ') !== false) return $key;
            }
        }
        $key = sanitize_title($n);
        return $key !== '' ? $key : 'attribute-' . substr(md5((string) $label), 0, 12);
    }

    private static function axis_label($key, $fallback = '') {
        $labels = array(
            'power'=>'Potencia','capacity'=>'Capacidad','pressure'=>'Presión','torque'=>'Par',
            'voltage'=>'Voltaje','frequency'=>'Frecuencia','load'=>'Carga máxima','weight'=>'Peso',
            'speed'=>'Velocidad','flow'=>'Caudal','diameter'=>'Diámetro','length'=>'Longitud',
            'width'=>'Ancho','height'=>'Altura','temperature'=>'Temperatura',
        );
        return isset($labels[$key]) ? $labels[$key] : ($fallback !== '' ? sanitize_text_field($fallback) : ucwords(str_replace('-', ' ', $key)));
    }

    private static function axis_unit($key) {
        $units = array(
            'power'=>'W','capacity'=>'L','pressure'=>'bar','torque'=>'Nm','voltage'=>'V',
            'frequency'=>'Hz','load'=>'kg','weight'=>'kg','speed'=>'rpm','flow'=>'L/min',
            'diameter'=>'mm','length'=>'mm','width'=>'mm','height'=>'mm','temperature'=>'°C',
        );
        return isset($units[$key]) ? $units[$key] : '';
    }

    private static function decimal_text($number) {
        $number = round((float) $number, 4);
        return rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    }

    private static function normalize_numeric_unit($key, $value, $unit) {
        $value = (float) str_replace(',', '.', (string) $value);
        $unit_n = strtolower(str_replace(array(' ', '·'), '', remove_accents((string) $unit)));
        switch ($key) {
            case 'power':
                if ($unit_n === 'kw') $value *= 1000;
                return array(self::decimal_text($value), 'W');
            case 'capacity':
                if ($unit_n === 'ml') $value /= 1000;
                return array(self::decimal_text($value), 'L');
            case 'pressure':
                if ($unit_n === 'psi') $value *= 0.0689476;
                elseif ($unit_n === 'mpa') $value *= 10;
                return array(self::decimal_text($value), 'bar');
            case 'torque':
                return array(self::decimal_text($value), 'Nm');
            case 'voltage':
                return array(self::decimal_text($value), 'V');
            case 'frequency':
                return array(self::decimal_text($value), 'Hz');
            case 'load':
            case 'weight':
                if ($unit_n === 'g') $value /= 1000;
                return array(self::decimal_text($value), 'kg');
            case 'speed':
                return array(self::decimal_text($value), 'rpm');
            case 'flow':
                return array(self::decimal_text($value), 'L/min');
            case 'diameter':
            case 'length':
            case 'width':
            case 'height':
                if ($unit_n === 'cm') $value *= 10;
                elseif ($unit_n === 'm') $value *= 1000;
                return array(self::decimal_text($value), 'mm');
            case 'temperature':
                return array(self::decimal_text($value), '°C');
        }
        return array(self::decimal_text($value), sanitize_text_field($unit));
    }

    private static function scalar_blob($value, &$parts, $depth = 0) {
        if ($depth > 5 || count($parts) > 300) return;
        if (is_scalar($value)) {
            $s = trim(wp_strip_all_tags((string) $value));
            if ($s !== '' && strlen($s) < 1000) $parts[] = $s;
            return;
        }
        if (!is_array($value)) return;
        foreach ($value as $key=>$child) {
            $key_n = self::normalize_text($key);
            if (preg_match('/url|link|token|thumbnail|image|serpapi|merchant_id/', $key_n)) continue;
            self::scalar_blob($child, $parts, $depth + 1);
        }
    }

    public static function extract_external_values(array $row) {
        $parts = array(
            (string) ($row['title'] ?? ''),
            (string) ($row['description'] ?? ''),
        );
        $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
        if (is_array($raw)) self::scalar_blob($raw, $parts);
        $text = implode(' | ', array_unique(array_filter($parts)));
        $n = remove_accents(function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text));

        $patterns = array(
            'power' => '/\b(\d+(?:[\.,]\d+)?)\s*(kw|w)\b/u',
            'capacity' => '/\b(\d+(?:[\.,]\d+)?)\s*(ml|l|litros?|litres?)\b/u',
            'pressure' => '/\b(\d+(?:[\.,]\d+)?)\s*(bar|psi|mpa)\b/u',
            'torque' => '/\b(\d+(?:[\.,]\d+)?)\s*(nm|n\s*[·\.]?\s*m)\b/u',
            'voltage' => '/\b(\d+(?:[\.,]\d+)?)\s*v\b/u',
            'frequency' => '/\b(\d+(?:[\.,]\d+)?)\s*hz\b/u',
            'speed' => '/\b(\d+(?:[\.,]\d+)?)\s*rpm\b/u',
            'flow' => '/\b(\d+(?:[\.,]\d+)?)\s*(l\s*\/\s*min|lpm)\b/u',
            'temperature' => '/\b(-?\d+(?:[\.,]\d+)?)\s*(?:°\s*)?c\b/u',
        );

        $out = array();
        foreach ($patterns as $key=>$pattern) {
            if (!preg_match($pattern, $n, $m)) continue;
            list($normalized, $unit) = self::normalize_numeric_unit($key, $m[1], $m[2] ?? self::axis_unit($key));
            $out[$key] = array(
                'raw_value'=>sanitize_text_field($m[0]),
                'normalized_value'=>$normalized,
                'unit'=>$unit,
                'confidence'=>0.92,
                'verification_status'=>'verified',
                'source'=>'ojeador_explicit',
            );
        }

        $context_patterns = array(
            'load' => '/\b(?:carga(?:\s+maxima)?|capacidad\s+de\s+carga|max(?:imum)?\s+load)[^0-9]{0,20}(\d+(?:[\.,]\d+)?)\s*(kg|g)\b/u',
            'weight' => '/\b(?:peso|weight)[^0-9]{0,15}(\d+(?:[\.,]\d+)?)\s*(kg|g)\b/u',
            'diameter' => '/\b(?:diametro|diameter)[^0-9]{0,15}(\d+(?:[\.,]\d+)?)\s*(mm|cm|m)\b/u',
            'height' => '/\b(?:altura|height)[^0-9]{0,15}(\d+(?:[\.,]\d+)?)\s*(mm|cm|m)\b/u',
            'width' => '/\b(?:ancho|width)[^0-9]{0,15}(\d+(?:[\.,]\d+)?)\s*(mm|cm|m)\b/u',
            'length' => '/\b(?:longitud|length)[^0-9]{0,15}(\d+(?:[\.,]\d+)?)\s*(mm|cm|m)\b/u',
        );
        foreach ($context_patterns as $key=>$pattern) {
            if (!preg_match($pattern, $n, $m)) continue;
            list($normalized, $unit) = self::normalize_numeric_unit($key, $m[1], $m[2]);
            $out[$key] = array(
                'raw_value'=>sanitize_text_field($m[0]),
                'normalized_value'=>$normalized,
                'unit'=>$unit,
                'confidence'=>0.90,
                'verification_status'=>'verified',
                'source'=>'ojeador_explicit',
            );
        }

        if (isset($row['price']) && is_numeric($row['price'])) {
            $out['price'] = array(
                'raw_value'=>(string) $row['price'],
                'normalized_value'=>self::decimal_text($row['price']),
                'unit'=>sanitize_text_field((string) ($row['currency'] ?? 'EUR')),
                'confidence'=>1.0,
                'verification_status'=>'verified',
                'source'=>'ojeador_price',
            );
        }
        return $out;
    }

    private static function product_brand($product) {
        foreach (array('pa_marca','marca','brand') as $key) {
            $value = trim((string) $product->get_attribute($key));
            if ($value !== '') return $value;
        }
        foreach (array('_brand','brand') as $key) {
            $value = trim((string) $product->get_meta($key, true));
            if ($value !== '') return $value;
        }
        return '';
    }

    private static function product_model($product) {
        foreach (array('pa_modelo','modelo','model') as $key) {
            $value = trim((string) $product->get_attribute($key));
            if ($value !== '') return $value;
        }
        return trim((string) $product->get_sku());
    }

    private static function own_product_record($product) {
        $values = array();
        foreach ((array) $product->get_attributes() as $attribute) {
            if (!is_object($attribute) || !method_exists($attribute, 'get_name')) continue;
            $name = (string) $attribute->get_name();
            $label = function_exists('wc_attribute_label') ? wc_attribute_label($name, $product) : $name;
            $value = trim((string) $product->get_attribute($name));
            if ($value === '') continue;
            $key = self::canonical_axis_key($label);
            $values[$key] = array(
                'label'=>self::axis_label($key, $label),
                'raw_value'=>$value,
                'normalized_value'=>sanitize_text_field($value),
                'unit'=>self::axis_unit($key),
                'confidence'=>1.0,
                'verification_status'=>'verified',
                'source'=>'woocommerce_attribute',
            );
        }
        $weight = $product->get_weight();
        if ($weight !== '' && is_numeric($weight)) {
            $unit = get_option('woocommerce_weight_unit', 'kg');
            list($normalized,$canonical_unit) = self::normalize_numeric_unit('weight',$weight,$unit);
            $values['weight'] = array(
                'label'=>'Peso','raw_value'=>(string)$weight,'normalized_value'=>$normalized,'unit'=>$canonical_unit,
                'confidence'=>1.0,'verification_status'=>'verified','source'=>'woocommerce',
            );
        }
        $price = $product->get_price();
        if ($price !== '' && is_numeric($price)) {
            $values['price'] = array(
                'label'=>'Precio','raw_value'=>(string)$price,'normalized_value'=>self::decimal_text($price),
                'unit'=>get_woocommerce_currency(),'confidence'=>1.0,'verification_status'=>'verified','source'=>'woocommerce',
            );
        }
        return array(
            'source_type'=>'own',
            'source_id'=>(string) $product->get_id(),
            'own_product_id'=>$product->get_id(),
            'brand'=>self::product_brand($product),
            'model'=>self::product_model($product),
            'title'=>$product->get_name(),
            'dedupe_key'=>'own-' . $product->get_id(),
            'observed_at'=>self::now(),
            'values'=>$values,
            'raw_meta'=>array('sku'=>$product->get_sku()),
        );
    }

    private static function external_dedupe_key(array $row) {
        $google_id = trim((string) ($row['google_product_id'] ?? ''));
        if ($google_id !== '') return 'google-' . substr(hash('sha256',$google_id),0,32);
        $brand = self::normalize_text($row['brand'] ?? '');
        $model = self::normalize_text($row['model'] ?? '');
        if ($brand !== '' && $model !== '') return 'brand-model-' . substr(hash('sha256',$brand.'|'.$model),0,32);
        $title = self::normalize_text($row['title'] ?? '');
        if ($title !== '') return 'title-' . substr(hash('sha256',$brand.'|'.$title),0,32);
        return 'result-' . substr(hash('sha256',(string)($row['result_hash'] ?? wp_json_encode($row))),0,32);
    }

    private static function external_records($term_id, $limit) {
        $rows = function_exists('seo_ojeador_get_category_market')
            ? (array) seo_ojeador_get_category_market(absint($term_id), absint($limit))
            : array();
        $deduped = array();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = self::external_dedupe_key($row);
            $values = self::extract_external_values($row);
            $record = array(
                'source_type'=>'external',
                'source_id'=>(string) ($row['id'] ?? $row['result_hash'] ?? $key),
                'own_product_id'=>0,
                'brand'=>sanitize_text_field((string) ($row['brand'] ?? '')),
                'model'=>sanitize_text_field((string) ($row['model'] ?? '')),
                'title'=>sanitize_text_field((string) ($row['title'] ?? '')),
                'dedupe_key'=>$key,
                'observed_at'=>sanitize_text_field((string) ($row['observed_at'] ?? $row['last_seen_at'] ?? self::now())),
                'values'=>$values,
                'raw_meta'=>array(
                    'google_product_id'=>sanitize_text_field((string) ($row['google_product_id'] ?? '')),
                    'result_hash'=>sanitize_text_field((string) ($row['result_hash'] ?? '')),
                    'source'=>'ojeador',
                ),
            );
            if (!isset($deduped[$key])) {
                $deduped[$key] = $record;
                continue;
            }
            if (count($values) > count((array) $deduped[$key]['values'])) {
                $deduped[$key] = $record;
            }
        }
        return array_values($deduped);
    }

    private static function legacy_priority_labels($term_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_category_comparisons';
        if (!SEO_Comparador_DB::table_exists($table)) return array();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT analysis_snapshot FROM {$table} WHERE category_id=%d ORDER BY updated_at DESC LIMIT 1",
            absint($term_id)
        ), ARRAY_A);
        $snapshot = is_array($row) ? json_decode((string) ($row['analysis_snapshot'] ?? ''), true) : array();
        $labels = array();
        foreach ((array) ($snapshot['criteria'] ?? array()) as $criterion) {
            $label = sanitize_text_field((string) ($criterion['label'] ?? ''));
            if ($label !== '') $labels[self::canonical_axis_key($label)] = $label;
        }
        return $labels;
    }

    public static function axis_publishable_for_test($known, $total, $variation, $avg_confidence) {
        $settings = self::settings();
        $total = max(1, absint($total));
        $coverage = absint($known) / $total;
        return absint($known) >= 2
            && absint($variation) >= 2
            && $coverage >= (float) $settings['min_axis_coverage']
            && (float) $avg_confidence >= (float) $settings['min_axis_confidence'];
    }

    private static function axis_statistics(array $records, array $settings, array $legacy_labels) {
        $total = count($records);
        if ($total < 1) return array();
        $candidate = array();
        foreach ($records as $record) {
            foreach ((array) ($record['values'] ?? array()) as $key=>$value) {
                if ($key === 'price') continue;
                if (!isset($candidate[$key])) $candidate[$key] = array('known'=>0,'confidence'=>0.0,'values'=>array(),'label'=>'','unit'=>'');
                $normalized = trim((string) ($value['normalized_value'] ?? ''));
                if ($normalized === '') continue;
                $candidate[$key]['known']++;
                $candidate[$key]['confidence'] += (float) ($value['confidence'] ?? 0);
                $candidate[$key]['values'][self::normalize_text($normalized . ' ' . ($value['unit'] ?? ''))] = true;
                if ($candidate[$key]['label'] === '') $candidate[$key]['label'] = sanitize_text_field((string) ($value['label'] ?? self::axis_label($key)));
                if ($candidate[$key]['unit'] === '') $candidate[$key]['unit'] = sanitize_text_field((string) ($value['unit'] ?? self::axis_unit($key)));
            }
        }
        foreach ($legacy_labels as $key=>$label) {
            if (!isset($candidate[$key])) $candidate[$key] = array('known'=>0,'confidence'=>0.0,'values'=>array(),'label'=>$label,'unit'=>self::axis_unit($key));
            elseif ($candidate[$key]['label'] === '') $candidate[$key]['label'] = $label;
        }
        $out = array();
        foreach ($candidate as $key=>$row) {
            $coverage = $total ? $row['known'] / $total : 0;
            $avg_conf = $row['known'] ? $row['confidence'] / $row['known'] : 0;
            $variation = count($row['values']);
            $legacy = isset($legacy_labels[$key]);
            $publishable = self::axis_publishable_for_test($row['known'],$total,$variation,$avg_conf);
            $priority = ($legacy ? 25 : 0) + min(50, (int) round($coverage * 50)) + min(25, max(0,$variation-1)*5);
            $out[] = array(
                'axis_key'=>$key,
                'label'=>$row['label'] !== '' ? $row['label'] : self::axis_label($key),
                'unit'=>$row['unit'] !== '' ? $row['unit'] : self::axis_unit($key),
                'priority'=>$priority,
                'coverage'=>round($coverage,4),
                'min_confidence'=>(float) $settings['min_axis_confidence'],
                'publishable'=>$publishable ? 1 : 0,
                'manual_override'=>0,
                'source'=>$legacy ? 'legacy+auto' : 'auto',
                'avg_confidence'=>round($avg_conf,4),
            );
        }
        usort($out, static function($a,$b){ return ($b['priority'] <=> $a['priority']) ?: strcmp($a['label'],$b['label']); });
        return array_slice($out,0,absint($settings['max_axes']));
    }

    private static function max_observed_at(array $records) {
        $max = '';
        foreach ($records as $row) {
            $at = sanitize_text_field((string) ($row['observed_at'] ?? ''));
            if ($at !== '' && ($max === '' || $at > $max)) $max = $at;
        }
        return $max !== '' ? $max : self::now();
    }

    private static function source_hash(array $records, array $axes) {
        $fingerprint = array();
        foreach ($records as $row) {
            $vals = array();
            foreach ((array) ($row['values'] ?? array()) as $key=>$value) {
                $vals[$key] = array(
                    (string) ($value['normalized_value'] ?? ''),
                    (string) ($value['unit'] ?? ''),
                    (float) ($value['confidence'] ?? 0),
                );
            }
            ksort($vals);
            $fingerprint[] = array(
                'source_type'=>(string)($row['source_type'] ?? ''),
                'dedupe_key'=>(string)($row['dedupe_key'] ?? ''),
                'values'=>$vals,
            );
        }
        usort($fingerprint, static function($a,$b){ return strcmp($a['source_type'].'|'.$a['dedupe_key'],$b['source_type'].'|'.$b['dedupe_key']); });
        return hash('sha256', wp_json_encode(array($fingerprint,$axes), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private static function profile_key($term_id) {
        return 'category:' . absint($term_id);
    }

    public static function debug_external_dedupe_key(array $row) {
        return self::external_dedupe_key($row);
    }

    public static function profile_quality_status($own_count, $external_count, $publishable_axes, $semantic_conflicts = 0) {
        $settings = self::settings();
        if (absint($semantic_conflicts) > 0) return 'blocked';
        if (absint($own_count) < 1) return 'blocked';
        if (absint($own_count) + absint($external_count) < absint($settings['min_products'])) return 'needs_review';
        if (absint($publishable_axes) < 1) return 'needs_review';
        return 'needs_review';
    }

    public static function resolve_recalculated_status($old_status, $old_hash, $new_hash, $has_published_post, $base_status) {
        $old_status = sanitize_key((string) $old_status);
        $base_status = sanitize_key((string) $base_status) ?: 'needs_review';
        if ($base_status === 'blocked') return 'blocked';
        if (in_array($old_status,array('ready_for_editorial','ready_for_solucionador'),true) && (string) $old_hash === (string) $new_hash) return 'ready_for_editorial';
        if ($has_published_post && (string) $old_hash !== '' && (string) $old_hash !== (string) $new_hash) return 'needs_update';
        if (in_array($old_status,array('approved','post_draft','published','monitoring'),true) && (string) $old_hash === (string) $new_hash) return $old_status;
        return 'needs_review';
    }

    public static function build_profile($term_id) {
        global $wpdb;
        SEO_Comparador_DB::maybe_install();
        $term_id = absint($term_id);
        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) return new WP_Error('comparador_category','Categoría de producto no válida.');

        $settings = self::settings();
        if (!function_exists('wc_get_products')) return new WP_Error('comparador_woocommerce','WooCommerce no está disponible.');

        $own_objects = wc_get_products(array(
            'status'=>'publish',
            'limit'=>absint($settings['max_own_products']),
            'category'=>array($term->slug),
            'return'=>'objects',
            'orderby'=>'ID',
            'order'=>'ASC',
        ));
        $own = array();
        foreach ((array) $own_objects as $product) {
            if (is_object($product) && method_exists($product,'get_id')) $own[] = self::own_product_record($product);
        }

        $external_seen = function_exists('seo_ojeador_get_category_market')
            ? count((array) seo_ojeador_get_category_market($term_id, absint($settings['max_external_products'])))
            : 0;
        $external = self::external_records($term_id, absint($settings['max_external_products']));
        $records = array_merge($own,$external);
        $legacy_labels = self::legacy_priority_labels($term_id);
        $axes = self::axis_statistics($records,$settings,$legacy_labels);
        $publishable_axes = array_values(array_filter($axes, static function($a){ return !empty($a['publishable']); }));
        $hash = self::source_hash($records,$axes);
        $now = self::now();

        $profiles_table = SEO_Comparador_DB::table('profiles');
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$profiles_table} WHERE canonical_key=%s LIMIT 1",
            self::profile_key($term_id)
        ), ARRAY_A);
        $old_hash = (string) ($existing['source_hash'] ?? '');
        $old_status = sanitize_key((string) ($existing['status'] ?? 'detected'));
        $post_map = $existing ? SEO_Comparador_DB::post_map(absint($existing['id'])) : array();

        $confidence_parts = array();
        foreach ($publishable_axes as $axis) $confidence_parts[] = (float) ($axis['avg_confidence'] ?? 0);
        $confidence = $confidence_parts ? array_sum($confidence_parts) / count($confidence_parts) : 0.0;

        $semantic_conflicts = (array) apply_filters('seo_comparador_semantic_conflicts', array(), $term_id, $own);
        $base_status = self::profile_quality_status(count($own), count($external), count($publishable_axes), count($semantic_conflicts));
        $status = self::resolve_recalculated_status(
            $old_status,
            $old_hash,
            $hash,
            !empty($post_map['post_id']) && get_post_status(absint($post_map['post_id'])) === 'publish',
            $base_status
        );

        $row = array(
            'canonical_key'=>self::profile_key($term_id),
            'canonical_name'=>sanitize_text_field((string) $term->name),
            'category_ids'=>wp_json_encode(array($term_id)),
            'primary_category_id'=>$term_id,
            'status'=>$status,
            'own_products_count'=>count($own),
            'external_products_seen'=>$external_seen,
            'external_products_comparable'=>count($external),
            'comparison_axes_count'=>count($publishable_axes),
            'confidence'=>round($confidence,4),
            'source_snapshot_at'=>self::max_observed_at($external ?: $records),
            'generated_at'=>$now,
            'source_hash'=>$hash,
            'last_error'=>null,
            'updated_at'=>$now,
        );

        // Una fuente nueva invalida la decisión editorial previa. No se reutiliza
        // CREATE/IMPROVE/MERGE/NO_ACTION calculado sobre otro hash.
        if ($existing && $old_hash !== '' && $old_hash !== $hash) {
            $row['recommended_action']='';
            $row['decision_reason']='';
            $row['coverage_json']=null;
            $row['editorial_decided_at']=null;
        }

        if ($existing) {
            $profile_id = absint($existing['id']);
            $wpdb->update(SEO_Comparador_DB::table('profiles'),$row,array('id'=>$profile_id));
        } else {
            $row['created_at']=$now;
            $wpdb->insert(SEO_Comparador_DB::table('profiles'),$row);
            $profile_id = absint($wpdb->insert_id);
        }
        if (!$profile_id) return new WP_Error('comparador_profile_save','No se pudo guardar el perfil comparativo.');

        $manual_axes = array();
        foreach (SEO_Comparador_DB::axes($profile_id) as $old_axis) {
            if (!empty($old_axis['manual_override'])) $manual_axes[(string)$old_axis['axis_key']]=$old_axis;
        }

        $wpdb->delete(SEO_Comparador_DB::table('axes'),array('profile_id'=>$profile_id));
        $wpdb->delete(SEO_Comparador_DB::table('values'),array('comparison_product_id'=>0)); // limpieza defensiva
        $products_table = SEO_Comparador_DB::table('products');
        $values_table = SEO_Comparador_DB::table('values');
        $product_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$products_table} WHERE profile_id=%d",$profile_id));
        if ($product_ids) {
            $placeholders=implode(',',array_fill(0,count($product_ids),'%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$values_table} WHERE comparison_product_id IN ({$placeholders})",$product_ids));
        }
        $wpdb->delete(SEO_Comparador_DB::table('products'),array('profile_id'=>$profile_id));

        foreach ($axes as $axis) {
            if (isset($manual_axes[$axis['axis_key']])) {
                $old=$manual_axes[$axis['axis_key']];
                $axis['label']=(string)$old['label'];
                $axis['unit']=(string)$old['unit'];
                $axis['priority']=absint($old['priority']);
                $axis['min_confidence']=(float)$old['min_confidence'];
                $axis['publishable']=absint($old['publishable']) ? 1 : 0;
                $axis['manual_override']=1;
                $axis['source']='manual';
            }
            unset($axis['avg_confidence']);
            $axis['profile_id']=$profile_id;
            $axis['updated_at']=$now;
            $wpdb->insert(SEO_Comparador_DB::table('axes'),$axis);
        }

        $axes_by_key=array();
        foreach (SEO_Comparador_DB::axes($profile_id) as $axis) $axes_by_key[$axis['axis_key']]=$axis;

        foreach ($records as $record) {
            $product_row=array(
                'profile_id'=>$profile_id,
                'source_type'=>sanitize_key((string)$record['source_type']),
                'source_id'=>sanitize_text_field((string)$record['source_id']),
                'own_product_id'=>absint($record['own_product_id'] ?? 0),
                'brand'=>sanitize_text_field((string)($record['brand'] ?? '')),
                'model'=>sanitize_text_field((string)($record['model'] ?? '')),
                'title'=>sanitize_text_field((string)($record['title'] ?? '')),
                'representative'=>0,
                'dedupe_key'=>sanitize_text_field((string)$record['dedupe_key']),
                'source_snapshot_at'=>sanitize_text_field((string)($record['observed_at'] ?? $now)),
                'raw_meta'=>wp_json_encode((array)($record['raw_meta'] ?? array()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'created_at'=>$now,
                'updated_at'=>$now,
            );
            $wpdb->insert(SEO_Comparador_DB::table('products'),$product_row);
            $comparison_product_id=absint($wpdb->insert_id);
            if (!$comparison_product_id) continue;

            foreach ($axes_by_key as $axis_key=>$axis) {
                $value=(array)($record['values'][$axis_key] ?? array());
                if (!$value) {
                    $value=array(
                        'raw_value'=>'','normalized_value'=>'','unit'=>(string)($axis['unit'] ?? ''),
                        'confidence'=>0,'verification_status'=>'unknown','source'=>'unknown',
                    );
                }
                $wpdb->insert(SEO_Comparador_DB::table('values'),array(
                    'comparison_product_id'=>$comparison_product_id,
                    'axis_key'=>$axis_key,
                    'raw_value'=>sanitize_text_field((string)($value['raw_value'] ?? '')),
                    'normalized_value'=>sanitize_text_field((string)($value['normalized_value'] ?? '')),
                    'unit'=>sanitize_text_field((string)($value['unit'] ?? $axis['unit'] ?? '')),
                    'confidence'=>max(0,min(1,(float)($value['confidence'] ?? 0))),
                    'verification_status'=>sanitize_key((string)($value['verification_status'] ?? 'unknown')) ?: 'unknown',
                    'source'=>sanitize_key((string)($value['source'] ?? 'unknown')) ?: 'unknown',
                    'source_ref'=>sanitize_text_field((string)($record['source_id'] ?? '')),
                    'observed_at'=>sanitize_text_field((string)($record['observed_at'] ?? $now)),
                    'updated_at'=>$now,
                ));
            }
        }

        self::mark_representatives($profile_id, absint($settings['max_representative_external']));
        self::generate_editorial($profile_id);
        self::sync_pending_update($profile_id);

        if ($existing && $old_status !== $status) {
            $wpdb->insert(SEO_Comparador_DB::table('workflow'),array(
                'profile_id'=>$profile_id,
                'from_state'=>$old_status,
                'to_state'=>$status,
                'action_code'=>'recalculate',
                'reason'=>'Perfil recalculado con las fuentes persistidas disponibles.',
                'user_id'=>get_current_user_id(),
                'origin'=>'engine',
                'created_at'=>$now,
            ));
        }
        return SEO_Comparador_DB::get_profile($profile_id);
    }

    private static function mark_representatives($profile_id,$limit) {
        global $wpdb;
        if ($limit < 1) return;
        $rows=SEO_Comparador_DB::products($profile_id,'external');
        $picked=0;
        foreach ($rows as $row) {
            if ($picked >= $limit) break;
            if (trim((string)$row['title'])==='') continue;
            $wpdb->update(SEO_Comparador_DB::table('products'),array('representative'=>1),array('id'=>absint($row['id'])));
            $picked++;
        }
    }

    public static function generate_editorial($profile_id) {
        global $wpdb;
        $profile=SEO_Comparador_DB::get_profile($profile_id);
        if (!$profile) return new WP_Error('comparador_profile','Perfil no encontrado.');
        $axes=array_values(array_filter(SEO_Comparador_DB::axes($profile_id),static function($a){return !empty($a['publishable']);}));
        $criteria=array();
        foreach ($axes as $axis) $criteria[]=sanitize_text_field((string)$axis['label']);
        $name=sanitize_text_field((string)$profile['canonical_name']);
        $own=absint($profile['own_products_count']);
        $external=absint($profile['external_products_comparable']);

        $summary='La comparación de ' . $name . ' reúne ' . number_format_i18n($own) . ' productos del catálogo propio';
        if ($external) $summary.=' y ' . number_format_i18n($external) . ' referencias externas deduplicadas observadas previamente por Ojeador';
        $summary.='. ';
        if ($criteria) {
            $summary.='Los ejes con cobertura y confianza suficientes son ' . implode(', ',array_slice($criteria,0,8)) . '. ';
            $summary.='Estos ejes describen diferencias observables entre configuraciones y sirven como base para explicar criterios de elección sin establecer rankings no demostrados.';
        } else {
            $summary.='Todavía no hay ejes con cobertura y confianza suficientes para una síntesis pública fiable.';
        }
        $summary.=' Los datos externos desconocidos permanecen como DESCONOCIDO y no se completan por inferencia.';

        $excerpt=$criteria
            ? 'Panorama comparativo de ' . $name . ': diferencias relevantes en ' . implode(', ',array_slice($criteria,0,4)) . ' y relación con nuestro catálogo.'
            : 'Perfil comparativo de ' . $name . ' pendiente de completar datos suficientes.';

        $limits=array(
            'unknown_values_are_not_inferred'=>true,
            'merchant_is_not_editorial_axis'=>true,
            'external_products_seen'=>absint($profile['external_products_seen']),
            'external_products_comparable'=>$external,
            'source_snapshot_at'=>(string)$profile['source_snapshot_at'],
        );
        $existing=SEO_Comparador_DB::editorial($profile_id);
        $manual_import=!empty($existing)
            && sanitize_key((string)($existing['origin'] ?? ''))==='manual_import'
            && trim((string)($existing['comparison_text'] ?? ''))!=='';

        if ($manual_import) {
            // Recalcular fuentes/ejes no debe pisar la comparativa editorial importada.
            // Se actualiza sólo el contexto generado por el sistema.
            $row=array(
                'summary'=>wp_kses_post($summary),
                'generated_at'=>self::now(),
                'updated_at'=>self::now(),
            );
        } else {
            $version=max(1,absint($existing['version'] ?? 0)+1);
            $row=array(
                'summary'=>wp_kses_post($summary),
                'excerpt'=>sanitize_textarea_field($excerpt),
                'buying_criteria'=>wp_json_encode($criteria,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'limits'=>wp_json_encode($limits,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'origin'=>'generated',
                'version'=>$version,
                'generated_at'=>self::now(),
                'updated_at'=>self::now(),
            );
        }
        if ($existing) $wpdb->update(SEO_Comparador_DB::table('editorial'),$row,array('profile_id'=>absint($profile_id)));
        else { $row['profile_id']=absint($profile_id); $wpdb->insert(SEO_Comparador_DB::table('editorial'),$row); }
        return SEO_Comparador_DB::editorial($profile_id);
    }

    private static function review_payload($profile_id) {
        $profile_id = absint($profile_id);
        $profile = SEO_Comparador_DB::get_profile($profile_id);
        if (!$profile) return array();

        $editorial = SEO_Comparador_DB::editorial($profile_id);
        $axes = array_values(array_filter(SEO_Comparador_DB::axes($profile_id), static function($axis) {
            return !empty($axis['publishable']);
        }));
        $axis_labels = array_values(array_filter(array_map(static function($axis) {
            return sanitize_text_field((string) ($axis['label'] ?? ''));
        }, $axes)));
        sort($axis_labels);

        return array(
            'profile_id'=>$profile_id,
            'source_hash'=>(string) ($profile['source_hash'] ?? ''),
            'snapshot_at'=>(string) ($profile['source_snapshot_at'] ?? ''),
            'own_products'=>absint($profile['own_products_count'] ?? 0),
            'external_products'=>absint($profile['external_products_comparable'] ?? 0),
            'axes'=>$axis_labels,
            'summary'=>wp_strip_all_tags((string) ($editorial['summary'] ?? '')),
            'buying_criteria'=>array_values(array_filter(array_map(
                'sanitize_text_field',
                (array) SEO_Comparador_DB::decode_json($editorial['buying_criteria'] ?? '[]')
            ))),
            'main_differences'=>(array) SEO_Comparador_DB::decode_json($editorial['main_differences'] ?? '[]'),
            'use_cases'=>(array) SEO_Comparador_DB::decode_json($editorial['use_cases'] ?? '[]'),
            'conclusion'=>wp_strip_all_tags((string) ($editorial['conclusion'] ?? '')),
        );
    }

    private static function baseline_payload($post_id) {
        $baseline = get_post_meta(absint($post_id), self::META_REVIEW_BASELINE, true);
        if (is_array($baseline) && $baseline) return $baseline;

        $axes = (array) get_post_meta(absint($post_id), '_seo_comparador_axes', true);
        return array(
            'profile_id'=>absint(get_post_meta(absint($post_id), '_seo_comparador_profile_id', true)),
            'source_hash'=>(string) get_post_meta(absint($post_id), self::META_SOURCE_HASH, true),
            'snapshot_at'=>(string) get_post_meta(absint($post_id), '_seo_comparador_snapshot_at', true),
            'own_products'=>null,
            'external_products'=>null,
            'axes'=>array_values(array_filter(array_map('sanitize_text_field', $axes))),
            'summary'=>'',
            'buying_criteria'=>array(),
            'main_differences'=>array(),
            'use_cases'=>array(),
            'conclusion'=>'',
        );
    }

    private static function payload_diff(array $baseline, array $current) {
        $old_axes = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) ($baseline['axes'] ?? array())))));
        $new_axes = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) ($current['axes'] ?? array())))));
        sort($old_axes);
        sort($new_axes);

        return array(
            'source_changed'=>!empty($baseline['source_hash'])
                && !empty($current['source_hash'])
                && (string) $baseline['source_hash'] !== (string) $current['source_hash'],
            'snapshot_changed'=>!empty($baseline['snapshot_at'])
                && !empty($current['snapshot_at'])
                && (string) $baseline['snapshot_at'] !== (string) $current['snapshot_at'],
            'added_axes'=>array_values(array_diff($new_axes, $old_axes)),
            'removed_axes'=>array_values(array_diff($old_axes, $new_axes)),
            'own_products_before'=>$baseline['own_products'] ?? null,
            'own_products_after'=>absint($current['own_products'] ?? 0),
            'external_products_before'=>$baseline['external_products'] ?? null,
            'external_products_after'=>absint($current['external_products'] ?? 0),
            'summary_changed'=>!empty($baseline['summary'])
                && (string) $baseline['summary'] !== (string) ($current['summary'] ?? ''),
        );
    }

    private static function has_material_diff(array $baseline, array $current, array $diff) {
        if (!empty($diff['source_changed']) || !empty($diff['snapshot_changed'])) return true;
        if (!empty($diff['added_axes']) || !empty($diff['removed_axes'])) return true;
        if ($diff['own_products_before'] !== null && absint($diff['own_products_before']) !== absint($diff['own_products_after'])) return true;
        if ($diff['external_products_before'] !== null && absint($diff['external_products_before']) !== absint($diff['external_products_after'])) return true;
        if (!empty($diff['summary_changed'])) return true;

        // Post antiguo sin línea base completa: si no tenemos ningún dato de
        // referencia, inicializamos sin crear una falsa novedad.
        return false;
    }

    public static function pending_count($post_id) {
        $payload = get_post_meta(absint($post_id), self::META_PENDING_PAYLOAD, true);
        if (!is_array($payload) || empty($payload['diff'])) return 0;
        $diff = (array) $payload['diff'];
        $count = count((array) ($diff['added_axes'] ?? array()))
            + count((array) ($diff['removed_axes'] ?? array()));
        foreach (array('source_changed','snapshot_changed','summary_changed') as $flag) {
            if (!empty($diff[$flag])) $count++;
        }
        if (
            ($diff['own_products_before'] ?? null) !== null
            && absint($diff['own_products_before']) !== absint($diff['own_products_after'] ?? 0)
        ) $count++;
        if (
            ($diff['external_products_before'] ?? null) !== null
            && absint($diff['external_products_before']) !== absint($diff['external_products_after'] ?? 0)
        ) $count++;
        return max(1, $count);
    }

    private static function clear_pending_update($post_id) {
        delete_post_meta($post_id, self::META_PENDING_PAYLOAD);
        delete_post_meta($post_id, self::META_PENDING_DETECTED_AT);
    }

    public static function sync_pending_update($profile_id) {
        global $wpdb;
        $profile_id = absint($profile_id);
        $map = SEO_Comparador_DB::post_map($profile_id);
        $post_id = absint($map['post_id'] ?? 0);
        if (!$post_id || get_post_type($post_id) !== 'post' || get_post_status($post_id) === 'trash') return 0;

        $current = self::review_payload($profile_id);
        if (!$current) return 0;
        $baseline = self::baseline_payload($post_id);

        $has_any_baseline = !empty($baseline['source_hash'])
            || !empty($baseline['snapshot_at'])
            || !empty($baseline['axes'])
            || $baseline['own_products'] !== null
            || $baseline['external_products'] !== null;

        if (!$has_any_baseline) {
            update_post_meta($post_id, self::META_REVIEW_BASELINE, $current);
            update_post_meta($post_id, self::META_SOURCE_HASH, (string) ($current['source_hash'] ?? ''));
            self::clear_pending_update($post_id);
            return 0;
        }

        $diff = self::payload_diff($baseline, $current);
        if (!self::has_material_diff($baseline, $current, $diff)) {
            self::clear_pending_update($post_id);
            return 0;
        }

        update_post_meta($post_id, self::META_PENDING_PAYLOAD, array(
            'current'=>$current,
            'baseline'=>$baseline,
            'diff'=>$diff,
        ));
        update_post_meta($post_id, self::META_PENDING_DETECTED_AT, current_time('mysql'));

        $wpdb->update(SEO_Comparador_DB::table('profiles'), array(
            'status'=>'needs_update',
            'recommended_action'=>'IMPROVE_POST',
            'decision_reason'=>'Comparador ha detectado cambios materiales respecto al snapshot revisado; el post mantiene su estado y queda pendiente de revisión editorial.',
            'updated_at'=>self::now(),
        ), array('id'=>$profile_id));

        return self::pending_count($post_id);
    }

    public static function refresh_pending_for_post($post_id) {
        $post_id = absint($post_id);
        $profile_id = absint(get_post_meta($post_id, '_seo_comparador_profile_id', true));
        if (!$post_id || !$profile_id || get_post_type($post_id) !== 'post') {
            return new WP_Error('comparador_post_invalid', 'El post no pertenece a Comparador.');
        }
        return self::sync_pending_update($profile_id);
    }

    public static function mark_pending_reviewed($post_id) {
        global $wpdb;
        $post_id = absint($post_id);
        $profile_id = absint(get_post_meta($post_id, '_seo_comparador_profile_id', true));
        if (!$post_id || !$profile_id || get_post_type($post_id) !== 'post') {
            return new WP_Error('comparador_post_invalid', 'El post no pertenece a Comparador.');
        }

        $current = self::review_payload($profile_id);
        if (!$current) return new WP_Error('comparador_profile', 'No existe el perfil comparativo.');

        update_post_meta($post_id, self::META_REVIEW_BASELINE, $current);
        update_post_meta($post_id, self::META_SOURCE_HASH, (string) ($current['source_hash'] ?? ''));
        update_post_meta($post_id, '_seo_comparador_axes', (array) ($current['axes'] ?? array()));
        update_post_meta($post_id, '_seo_comparador_snapshot_at', (string) ($current['snapshot_at'] ?? ''));
        self::clear_pending_update($post_id);

        $wpdb->update(SEO_Comparador_DB::table('profiles'), array(
            'status'=>get_post_status($post_id) === 'publish' ? 'monitoring' : 'post_draft',
            'recommended_action'=>'NO_ACTION',
            'decision_reason'=>'Las novedades comparativas fueron revisadas por la Editora.',
            'updated_at'=>self::now(),
        ), array('id'=>$profile_id));
        return true;
    }

    public static function handle_post_update_action() {
        if (!current_user_can('manage_options')) wp_die('No tienes permisos.');
        $post_id = absint($_POST['post_id'] ?? 0);
        $action = sanitize_key((string) ($_POST['update_action'] ?? ''));
        check_admin_referer('seo_comparador_post_update_' . $post_id);

        $result = $action === 'mark_reviewed'
            ? self::mark_pending_reviewed($post_id)
            : self::refresh_pending_for_post($post_id);

        $state = is_wp_error($result) ? 'error' : ($action === 'mark_reviewed' ? 'reviewed' : 'rescanned');
        if (is_wp_error($result)) {
            set_transient('seo_comparador_update_notice_' . get_current_user_id(), $result->get_error_message(), 90);
        }
        $url = add_query_arg(array('page'=>'seo-post-editor','post_id'=>$post_id,'comparador_update'=>$state), admin_url('edit.php'));
        wp_safe_redirect($url);
        exit;
    }

    public static function render_pending_review_panel($post_id) {
        $post_id = absint($post_id);
        $profile_id = absint(get_post_meta($post_id, '_seo_comparador_profile_id', true));
        if (!$post_id || !$profile_id || get_post_type($post_id) !== 'post') return;

        $pending = get_post_meta($post_id, self::META_PENDING_PAYLOAD, true);
        $count = self::pending_count($post_id);
        $state = sanitize_key((string) ($_GET['comparador_update'] ?? ''));

        echo '<div style="background:#fff;border:1px solid ' . ($count ? '#dba617' : '#c3c4c7') . ';border-left:4px solid ' . ($count ? '#dba617' : '#2271b1') . ';border-radius:6px;padding:16px 18px;margin:14px 0 18px">';
        echo '<h2 style="margin:0 0 6px">Comparador · novedades del mercado/perfil</h2>';

        if ($state === 'error') {
            $msg = get_transient('seo_comparador_update_notice_' . get_current_user_id());
            delete_transient('seo_comparador_update_notice_' . get_current_user_id());
            echo '<div class="notice notice-error inline"><p>' . esc_html($msg ?: 'No se pudo actualizar Comparador.') . '</p></div>';
        } elseif ($state === 'reviewed') {
            echo '<div class="notice notice-success inline"><p>Novedades de Comparador marcadas como revisadas.</p></div>';
        }

        if ($count && is_array($pending)) {
            $current = (array) ($pending['current'] ?? array());
            $diff = (array) ($pending['diff'] ?? array());
            echo '<p><strong>Hay cambios comparativos pendientes de revisión.</strong> El post mantiene su estado actual y la versión pública no se modifica automáticamente.</p>';
            echo '<ul>';
            if (!empty($diff['added_axes'])) echo '<li>Nuevos criterios/ejes: ' . esc_html(implode(', ', (array) $diff['added_axes'])) . '.</li>';
            if (!empty($diff['removed_axes'])) echo '<li>Ejes que ya no están disponibles: ' . esc_html(implode(', ', (array) $diff['removed_axes'])) . '.</li>';
            if (($diff['own_products_before'] ?? null) !== null && absint($diff['own_products_before']) !== absint($diff['own_products_after'] ?? 0)) {
                echo '<li>Productos propios comparados: ' . esc_html(absint($diff['own_products_before'])) . ' → ' . esc_html(absint($diff['own_products_after'])) . '.</li>';
            }
            if (($diff['external_products_before'] ?? null) !== null && absint($diff['external_products_before']) !== absint($diff['external_products_after'] ?? 0)) {
                echo '<li>Referencias externas comparables: ' . esc_html(absint($diff['external_products_before'])) . ' → ' . esc_html(absint($diff['external_products_after'])) . '.</li>';
            }
            if (!empty($diff['snapshot_changed'])) {
                echo '<li>Nuevo snapshot de mercado: ' . esc_html((string) ($current['snapshot_at'] ?? '')) . '.</li>';
            }
            echo '</ul>';

            $html = '<h2>Novedades de Comparador</h2>';
            if (!empty($current['summary'])) $html .= '<p>' . esc_html((string) $current['summary']) . '</p>';
            if (!empty($current['axes'])) $html .= '<h3>Criterios de comparación</h3><p>' . esc_html(implode(', ', (array) $current['axes'])) . '.</p>';
            if (!empty($current['conclusion'])) $html .= '<h3>Conclusión comparativa</h3><p>' . esc_html((string) $current['conclusion']) . '</p>';

            echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0">';
            if (function_exists('seo_post_editor_render_prepend_payload')) {
                seo_post_editor_render_prepend_payload(
                    'seo-comparador-pending-' . $post_id,
                    $html,
                    'Incorporar al contenido de trabajo'
                );
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0">';
            echo '<input type="hidden" name="action" value="seo_comparador_post_update_action"><input type="hidden" name="post_id" value="' . esc_attr($post_id) . '"><input type="hidden" name="update_action" value="mark_reviewed">';
            wp_nonce_field('seo_comparador_post_update_' . $post_id);
            echo '<button class="button" type="submit">Marcar novedades como revisadas</button></form>';
            echo '</div>';
        } else {
            echo '<p>No hay cambios comparativos pendientes.</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
        echo '<input type="hidden" name="action" value="seo_comparador_post_update_action"><input type="hidden" name="post_id" value="' . esc_attr($post_id) . '"><input type="hidden" name="update_action" value="rescan">';
        wp_nonce_field('seo_comparador_post_update_' . $post_id);
        echo '<button class="button" type="submit">Reescanear novedades</button></form>';
        echo '<p class="description">Incorporar sólo modifica el editor abierto. La página publicada permanece intacta hasta que la Editora guarde la actualización.</p>';
        echo '</div>';
    }

    public static function measured_axis_confidence($profile_id,$axis_key) {
        global $wpdb;
        $profile_id = absint($profile_id);
        $axis_key = sanitize_key((string) $axis_key);
        if (!$profile_id || $axis_key === '') return 0.0;
        $values_table = SEO_Comparador_DB::table('values');
        $products_table = SEO_Comparador_DB::table('products');
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(v.confidence)
             FROM {$values_table} v
             JOIN {$products_table} p ON p.id=v.comparison_product_id
             WHERE p.profile_id=%d
               AND v.axis_key=%s
               AND v.normalized_value<>''
               AND v.verification_status IN ('verified','probable','derived_safe')",
            $profile_id,
            $axis_key
        ));
        return is_numeric($value) ? max(0.0,min(1.0,(float)$value)) : 0.0;
    }

    public static function save_axes($profile_id,$rows) {
        global $wpdb;
        $profile_id=absint($profile_id);
        if (!SEO_Comparador_DB::get_profile($profile_id)) return new WP_Error('comparador_profile','Perfil no encontrado.');
        $settings=self::settings();
        $known=array();
        $blocked=array();
        foreach (SEO_Comparador_DB::axes($profile_id) as $row) $known[$row['axis_key']]=$row;
        foreach ((array)$rows as $key=>$raw) {
            $key=sanitize_key((string)$key);
            if (!isset($known[$key]) || !is_array($raw)) continue;
            $requested_min=max((float)$settings['min_axis_confidence'],max(0.1,min(1,(float)($raw['min_confidence'] ?? $known[$key]['min_confidence']))));
            $measured=self::measured_axis_confidence($profile_id,$key);
            $coverage=(float)($known[$key]['coverage'] ?? 0);
            $requested_publishable=!empty($raw['publishable']);
            $publishable=$requested_publishable
                && $coverage >= (float)$settings['min_axis_coverage']
                && $measured >= $requested_min;
            if ($requested_publishable && !$publishable) {
                $blocked[]=sanitize_text_field((string)($raw['label'] ?? $known[$key]['label']));
            }
            $wpdb->update(SEO_Comparador_DB::table('axes'),array(
                'label'=>sanitize_text_field((string)($raw['label'] ?? $known[$key]['label'])),
                'unit'=>sanitize_text_field((string)($raw['unit'] ?? $known[$key]['unit'])),
                'priority'=>max(0,min(100,absint($raw['priority'] ?? $known[$key]['priority']))),
                'min_confidence'=>$requested_min,
                'publishable'=>$publishable?1:0,
                'manual_override'=>1,
                'source'=>'manual',
                'updated_at'=>self::now(),
            ),array('profile_id'=>$profile_id,'axis_key'=>$key));
        }
        self::generate_editorial($profile_id);
        SEO_Comparador_DB::update_status($profile_id,'needs_review','axes_updated','Ejes comparativos revisados manualmente.','admin');
        return array('blocked_axes'=>array_values(array_unique($blocked)));
    }

    private static function editorial_is_stale(array $profile, array $editorial) {
        $edited_hash = (string) ($editorial['source_hash_at_edit'] ?? '');
        $current_hash = (string) ($profile['source_hash'] ?? '');
        $edited_snapshot = (string) ($editorial['source_snapshot_at_edit'] ?? '');
        $current_snapshot = (string) ($profile['source_snapshot_at'] ?? '');
        return ($edited_hash !== '' && $current_hash !== '' && $edited_hash !== $current_hash)
            || ($edited_snapshot !== '' && $current_snapshot !== '' && $edited_snapshot !== $current_snapshot);
    }

    public static function editorial_brief($profile_id) {
        $profile = SEO_Comparador_DB::get_profile(absint($profile_id));
        if (!$profile) return new WP_Error('comparador_profile','Perfil no encontrado.');

        $editorial = SEO_Comparador_DB::editorial(absint($profile_id));
        $axes = array_values(array_filter(SEO_Comparador_DB::axes(absint($profile_id)), static function($axis) {
            return !empty($axis['publishable']);
        }));
        $own = array_values(array_filter(SEO_Comparador_DB::products(absint($profile_id)), static function($product) {
            return ($product['source_type'] ?? '') === 'own';
        }));
        $external = array_values(array_filter(SEO_Comparador_DB::products(absint($profile_id),'external'), static function($product) {
            return !empty($product['representative']);
        }));
        $coverage = SEO_Comparador_DB::decode_json($profile['coverage_json'] ?? '{}');

        return array(
            'category_id'=>absint($profile['primary_category_id']),
            'category'=>(string) $profile['canonical_name'],
            'profile_id'=>absint($profile['id']),
            'source_hash'=>(string) $profile['source_hash'],
            'source_snapshot_at'=>(string) $profile['source_snapshot_at'],
            'own_products'=>array_slice($own,0,40),
            'external_references'=>array_slice($external,0,20),
            'publishable_axes'=>$axes,
            'product_types'=>SEO_Comparador_DB::decode_json($editorial['product_types'] ?? '[]'),
            'main_differences'=>SEO_Comparador_DB::decode_json($editorial['main_differences'] ?? '[]'),
            'buying_criteria'=>SEO_Comparador_DB::decode_json($editorial['buying_criteria'] ?? '[]'),
            'use_cases'=>SEO_Comparador_DB::decode_json($editorial['use_cases'] ?? '[]'),
            'market_overview'=>(string) ($editorial['market_overview'] ?? ''),
            'own_catalog_position'=>(string) ($editorial['own_catalog_position'] ?? ''),
            'limitations'=>(string) ($editorial['editorial_limitations'] ?? ''),
            'conclusion'=>(string) ($editorial['conclusion'] ?? ''),
            'coverage'=>$coverage,
            'recommended_action'=>(string) ($profile['recommended_action'] ?? ''),
            'decision_reason'=>(string) ($profile['decision_reason'] ?? ''),
        );
    }

    public static function editorial_action_for_test($profile_valid,$coverage_status,$has_existing_post=false,$stale=false) {
        if (!$profile_valid || $stale) return 'NEEDS_REVIEW';
        $coverage_status=sanitize_key((string)$coverage_status);
        if ($coverage_status==='duplicate') return 'MERGE_CONTENT';
        if ($coverage_status==='covered') return 'NO_ACTION';
        if (in_array($coverage_status,array('partial_coverage','weak_coverage'),true) && $has_existing_post) return 'IMPROVE_POST';
        return 'CREATE_POST';
    }

    public static function evaluate_editorial_decision($profile_id,$reason='') {
        global $wpdb;
        $profile_id = absint($profile_id);
        $profile = SEO_Comparador_DB::get_profile($profile_id);
        if (!$profile) return new WP_Error('comparador_profile','Perfil no encontrado.');

        $settings = self::settings();
        $axes = array_values(array_filter(SEO_Comparador_DB::axes($profile_id), static function($axis) {
            return !empty($axis['publishable']);
        }));
        $editorial = SEO_Comparador_DB::editorial($profile_id);
        $post_map = SEO_Comparador_DB::post_map($profile_id);
        $status = sanitize_key((string) ($profile['status'] ?? 'needs_review'));
        $comparable_count = absint($profile['own_products_count']) + absint($profile['external_products_comparable']);

        $coverage = class_exists('SEO_Editorial_Coverage')
            ? SEO_Editorial_Coverage::comparison_category(
                absint($profile['primary_category_id']),
                (string) $profile['canonical_name'],
                absint($post_map['post_id'] ?? 0)
            )
            : array('status'=>'uncovered','score'=>0,'post_id'=>0,'matches'=>array(),'fingerprint'=>'');

        $decision_reason = $reason !== '' ? sanitize_textarea_field($reason) : '';
        $stale = self::editorial_is_stale($profile,$editorial);
        $profile_valid = !in_array($status,array('blocked','archived'),true)
            && $comparable_count >= absint($settings['min_products'])
            && !empty($axes);

        $action = self::editorial_action_for_test(
            $profile_valid,
            sanitize_key((string) ($coverage['status'] ?? 'uncovered')),
            !empty($coverage['post_id']),
            $stale
        );

        if ($decision_reason === '') {
            if (in_array($status,array('blocked','archived'),true)) {
                $decision_reason = 'El perfil está bloqueado o archivado y no puede avanzar editorialmente.';
            } elseif ($comparable_count < absint($settings['min_products'])) {
                $decision_reason = 'No hay suficientes productos comparables para sostener una comparación editorial.';
            } elseif (!$axes) {
                $decision_reason = 'No existe ningún eje publicable con cobertura y confianza suficientes.';
            } elseif ($stale) {
                $decision_reason = 'La capa editorial se redactó con un snapshot/hash anterior y debe revisarse.';
            } elseif ($action === 'MERGE_CONTENT') {
                $decision_reason = 'Existen varias piezas editoriales solapadas para la misma familia comparable.';
            } elseif ($action === 'NO_ACTION') {
                $decision_reason = 'La comparación ya dispone de cobertura editorial equivalente.';
            } elseif ($action === 'IMPROVE_POST') {
                $decision_reason = 'Existe una pieza relacionada, pero el perfil aporta diferencias comparativas adicionales.';
            } else {
                $decision_reason = 'Perfil válido con ejes publicables y sin cobertura editorial equivalente.';
            }
        }

        $wpdb->update(SEO_Comparador_DB::table('profiles'),array(
            'recommended_action'=>$action,
            'decision_reason'=>$decision_reason,
            'coverage_json'=>wp_json_encode($coverage,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'editorial_decided_at'=>self::now(),
            'updated_at'=>self::now(),
        ),array('id'=>$profile_id));

        $target = $action === 'NEEDS_REVIEW' ? 'needs_review' : 'ready_for_editorial';
        SEO_Comparador_DB::update_status(
            $profile_id,
            $target,
            'evaluate_editorial',
            $decision_reason,
            'comparador'
        );

        return array(
            'action'=>$action,
            'reason'=>$decision_reason,
            'coverage'=>$coverage,
            'status'=>$target,
        );
    }

    public static function approve_editorial_action($profile_id,$action='',$reason='') {
        global $wpdb;
        $profile_id = absint($profile_id);
        $profile = SEO_Comparador_DB::get_profile($profile_id);
        if (!$profile) return new WP_Error('comparador_profile','Perfil no encontrado.');

        $allowed = array('CREATE_POST','IMPROVE_POST','MERGE_CONTENT','NO_ACTION');
        $action = strtoupper(sanitize_key((string) $action));
        if ($action === '') $action = strtoupper((string) ($profile['recommended_action'] ?? ''));
        if (!in_array($action,$allowed,true)) {
            return new WP_Error('comparador_action','La actuación editorial no puede aprobarse sin una decisión válida.');
        }
        if (
            $action === 'CREATE_POST'
            && strtoupper((string) ($profile['recommended_action'] ?? '')) !== 'CREATE_POST'
        ) {
            return new WP_Error(
                'comparador_create_not_recommended',
                'CREATE_POST sólo puede aprobarse cuando la evaluación de Comparador ha validado el perfil y lo recomienda.'
            );
        }

        $decision_reason = $reason !== ''
            ? sanitize_textarea_field($reason)
            : sanitize_textarea_field((string) ($profile['decision_reason'] ?? ''));

        $wpdb->update(SEO_Comparador_DB::table('profiles'),array(
            'recommended_action'=>$action,
            'decision_reason'=>$decision_reason,
            'editorial_decided_at'=>self::now(),
            'updated_at'=>self::now(),
        ),array('id'=>$profile_id));

        SEO_Comparador_DB::update_status(
            $profile_id,
            'approved',
            'approve_editorial',
            $decision_reason ?: 'Actuación editorial aprobada por la Editora.',
            'admin'
        );
        return array('action'=>$action,'status'=>'approved');
    }

    private static function assign_post_category($post_id,$category_id) {
        global $wpdb;
        $post_id = absint($post_id);
        $category_id = absint($category_id);
        if (!$post_id || !$category_id) return new WP_Error('comparador_relation','Post o categoría no válidos.');

        if (function_exists('seo_post_editor_replace_product_cat_relations')) {
            return seo_post_editor_replace_product_cat_relations($post_id,array($category_id));
        }

        $table = $wpdb->prefix . 'seo_relations';
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table))) === $table;
        if (!$exists) return new WP_Error('comparador_relation','No está disponible seo_relations.');

        $wpdb->delete($table,array(
            'source_type'=>'post','source_id'=>$post_id,
            'target_type'=>'product_cat','relation_type'=>'post_to_category',
        ),array('%s','%d','%s','%s'));

        $inserted = $wpdb->insert($table,array(
            'source_type'=>'post',
            'source_id'=>$post_id,
            'target_type'=>'product_cat',
            'target_id'=>$category_id,
            'relation_type'=>'post_to_category',
            'created_at'=>self::now(),
        ),array('%s','%d','%s','%d','%s','%s'));

        return false === $inserted
            ? new WP_Error('comparador_relation','No se pudo guardar post_to_category.')
            : true;
    }

    private static function draft_content(array $profile,array $editorial,array $axes) {
        $comparison_text = trim((string) ($editorial['comparison_text'] ?? ''));
        if ($comparison_text !== '') return wp_kses_post($comparison_text);

        $parts = array();
        $summary = trim((string) ($editorial['summary'] ?? ''));
        if ($summary !== '') $parts[] = '<p>' . esc_html($summary) . '</p>';

        $labels = array_values(array_filter(array_map(static function($axis) {
            return !empty($axis['publishable']) ? sanitize_text_field((string) ($axis['label'] ?? '')) : '';
        },$axes)));
        if ($labels) {
            $parts[] = '<h2>Criterios de comparación</h2><p>' . esc_html(implode(', ',array_slice($labels,0,10))) . '.</p>';
        }

        $limitations = trim((string) ($editorial['editorial_limitations'] ?? ''));
        if ($limitations !== '') {
            $parts[] = '<h2>Limitaciones de la comparativa</h2><p>' . esc_html($limitations) . '</p>';
        }

        $conclusion = trim((string) ($editorial['conclusion'] ?? ''));
        if ($conclusion !== '') {
            $parts[] = '<h2>Conclusión</h2><p>' . esc_html($conclusion) . '</p>';
        }

        return implode("\n\n",$parts);
    }

    public static function create_editorial_draft($profile_id) {
        $profile_id = absint($profile_id);
        $profile = SEO_Comparador_DB::get_profile($profile_id);
        if (!$profile) return new WP_Error('comparador_profile','Perfil no encontrado.');
        if (sanitize_key((string) ($profile['status'] ?? '')) !== 'approved') {
            return new WP_Error('comparador_not_approved','La Editora debe aprobar la actuación antes de preparar el borrador.');
        }
        if (strtoupper((string) ($profile['recommended_action'] ?? '')) !== 'CREATE_POST') {
            return new WP_Error('comparador_not_create','La decisión editorial actual no es CREATE_POST.');
        }

        $existing = SEO_Comparador_DB::post_map($profile_id);
        if (!empty($existing['post_id']) && get_post(absint($existing['post_id']))) {
            return new WP_Error('comparador_post_exists','El perfil ya tiene un post canónico vinculado.');
        }

        $editorial = SEO_Comparador_DB::editorial($profile_id);
        if (self::editorial_is_stale($profile,$editorial)) {
            return new WP_Error('comparador_stale','Las fuentes han cambiado; revisa la comparativa antes de crear el borrador.');
        }

        $title = trim((string) ($editorial['suggested_title'] ?? ''));
        if ($title === '') {
            $title = 'Comparativa de ' . sanitize_text_field((string) $profile['canonical_name']) . ': diferencias y criterios de elección';
        }
        $excerpt = sanitize_textarea_field((string) ($editorial['excerpt'] ?? ''));
        $axes = SEO_Comparador_DB::axes($profile_id);

        $post_id = wp_insert_post(wp_slash(array(
            'post_type'=>'post',
            'post_status'=>'draft',
            'post_title'=>sanitize_text_field($title),
            'post_excerpt'=>$excerpt,
            'post_content'=>self::draft_content($profile,$editorial,$axes),
        )),true);

        if (is_wp_error($post_id) || !absint($post_id)) {
            return is_wp_error($post_id) ? $post_id : new WP_Error('comparador_draft','No se pudo crear el borrador.');
        }

        $linked = self::link_post($profile_id,absint($post_id));
        if (is_wp_error($linked)) {
            wp_delete_post(absint($post_id),true);
            return $linked;
        }

        SEO_Comparador_DB::update_status(
            $profile_id,
            'post_draft',
            'create_draft',
            'La Editora ha aprobado CREATE_POST y Comparador ha preparado un borrador; no se publica automáticamente.',
            'admin'
        );
        return absint($post_id);
    }

    /**
     * Alias transitorio para integraciones antiguas. Ya no envia nada a
     * Solucionador: ejecuta la decisión editorial propia del Comparador.
     */
    public static function send_to_solucionador($profile_id,$reason='') {
        return self::evaluate_editorial_decision($profile_id,$reason);
    }

    public static function link_post($profile_id,$post_id) {
        global $wpdb;
        $profile_id=absint($profile_id);
        $post_id=absint($post_id);
        $profile=SEO_Comparador_DB::get_profile($profile_id);
        $post=get_post($post_id);
        if (!$profile || !$post || $post->post_type!=='post') return new WP_Error('comparador_post','Perfil o post no válido.');

        if (!term_exists('comparativas','post_tag')) wp_insert_term('comparativas','post_tag',array('slug'=>'comparativas'));
        wp_set_post_terms($post_id,array('comparativas'),'post_tag',true);
        update_post_meta($post_id,'_seo_solucionador_content_role','comparison');

        $relation=self::assign_post_category($post_id,absint($profile['primary_category_id']));
        if (is_wp_error($relation)) return $relation;

        $now=self::now();
        $post_map_table = SEO_Comparador_DB::table('post_map');
        $exists=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$post_map_table} WHERE profile_id=%d AND post_id=%d LIMIT 1",
            $profile_id,$post_id
        ));
        $row=array(
            'profile_id'=>$profile_id,
            'post_id'=>$post_id,
            'relationship_type'=>'canonical',
            'status'=>$post->post_status==='publish'?'published':'linked',
            'published_at'=>$post->post_status==='publish' ? ($post->post_date ?: $now) : null,
            'last_synced_at'=>$now,
        );
        if ($exists) $wpdb->update(SEO_Comparador_DB::table('post_map'),$row,array('id'=>absint($exists)));
        else { $row['created_at']=$now; $wpdb->insert(SEO_Comparador_DB::table('post_map'),$row); }
        $editorial=SEO_Comparador_DB::editorial($profile_id);
        $public_axes=array();
        foreach (SEO_Comparador_DB::axes($profile_id) as $axis) {
            if (!empty($axis['publishable'])) $public_axes[]=sanitize_text_field((string)$axis['label']);
            if (count($public_axes)>=5) break;
        }
        update_post_meta($post_id,'_seo_comparador_profile_id',$profile_id);
        update_post_meta($post_id,'_seo_comparador_excerpt',sanitize_text_field((string)($editorial['excerpt'] ?? '')));
        update_post_meta($post_id,'_seo_comparador_axes',$public_axes);
        update_post_meta($post_id,'_seo_comparador_snapshot_at',sanitize_text_field((string)($profile['source_snapshot_at'] ?? '')));
        update_post_meta($post_id,self::META_SOURCE_HASH,(string)($profile['source_hash'] ?? ''));
        update_post_meta($post_id,self::META_REVIEW_BASELINE,self::review_payload($profile_id));
        self::clear_pending_update($post_id);
        SEO_Comparador_DB::update_status($profile_id,$post->post_status==='publish'?'published':'post_draft','link_post','Post canónico vinculado al perfil.','admin');
        return true;
    }

    public static function mark_not_comparable($profile_id,$reason='') {
        return SEO_Comparador_DB::update_status(absint($profile_id),'archived','mark_not_comparable',$reason ?: 'Alcance marcado como no comparable.','admin');
    }

    public static function signals($signals=array(),$limit=240) {
        $signals=is_array($signals)?$signals:array();
        $profiles=SEO_Comparador_DB::list_profiles(max(20,min(500,absint($limit))));
        foreach ($profiles as $profile) {
            if (!in_array((string)$profile['status'],array('ready_for_solucionador','approved','published','monitoring','needs_update'),true)) continue;
            $editorial=SEO_Comparador_DB::editorial(absint($profile['id']));
            $editorial_text=trim((string)($editorial['comparison_text'] ?? ''));
            if ($editorial_text==='') $editorial_text=trim((string)($editorial['summary'] ?? ''));
            if (!$editorial || $editorial_text==='') continue;
            $editorial_text=wp_html_excerpt(wp_strip_all_tags($editorial_text),2400,'…');
            $axes=array_values(array_filter(SEO_Comparador_DB::axes(absint($profile['id'])),static function($a){return !empty($a['publishable']);}));
            $representative=array_values(array_filter(SEO_Comparador_DB::products(absint($profile['id']),'external'),static function($p){return !empty($p['representative']);}));
            $signals[]=array(
                'source_id'=>'profile:' . absint($profile['id']),
                'source_text'=>sanitize_textarea_field($editorial_text),
                'signal_type'=>'comparison_profile',
                'proposal_role'=>'origin',
                'category_id'=>absint($profile['primary_category_id']),
                'term_id'=>absint($profile['primary_category_id']),
                'intent'=>'comparison',
                'object'=>(string)$profile['canonical_name'],
                'confidence'=>(float)$profile['confidence'],
                'evidence_score'=>max(0.55,min(1.0,(float)$profile['confidence'])),
                'occurrences'=>max(1,absint($profile['own_products_count'])+absint($profile['external_products_comparable'])),
                'observed_at'=>(string)$profile['source_snapshot_at'],
                'decisive_features'=>array_values(wp_list_pluck($axes,'label')),
                'representative_refs'=>array_map(static function($p){
                    return array('brand'=>(string)$p['brand'],'model'=>(string)$p['model'],'title'=>(string)$p['title']);
                },array_slice($representative,0,6)),
                'metadata'=>array(
                    'profile_id'=>absint($profile['id']),
                    'profile_status'=>(string)$profile['status'],
                    'own_products_count'=>absint($profile['own_products_count']),
                    'external_products_seen'=>absint($profile['external_products_seen']),
                    'external_products_comparable'=>absint($profile['external_products_comparable']),
                    'source_snapshot_at'=>(string)$profile['source_snapshot_at'],
                    'editorial_version'=>absint($editorial['version'] ?? 0),
                    'editorial_origin'=>(string)($editorial['origin'] ?? 'generated'),
                    'suggested_title'=>(string)($editorial['suggested_title'] ?? ''),
                    'main_differences'=>SEO_Comparador_DB::decode_json($editorial['main_differences'] ?? '[]'),
                    'buying_criteria'=>SEO_Comparador_DB::decode_json($editorial['buying_criteria'] ?? '[]'),
                    'use_cases'=>SEO_Comparador_DB::decode_json($editorial['use_cases'] ?? '[]'),
                    'limits'=>SEO_Comparador_DB::decode_json($editorial['limits'] ?? '{}'),
                ),
            );
            if (count($signals)>=absint($limit)) break;
        }
        return $signals;
    }

    public static function performance_rows($days=28) {
        $rows=array();
        if (!function_exists('seo_analista_get_data')) return $rows;
        $data=seo_analista_get_data(max(7,min(365,absint($days))));
        if (!is_array($data) || empty($data['ready'])) return $rows;
        $pages=(array)($data['pages'] ?? array());
        $by_path=array();
        foreach ($pages as $page) {
            if (!is_array($page)) continue;
            $path=(string)wp_parse_url((string)($page['page_url'] ?? ''),PHP_URL_PATH);
            if ($path!=='') $by_path[untrailingslashit($path)]=$page;
        }
        foreach (SEO_Comparador_DB::list_profiles(1000) as $profile) {
            $map=SEO_Comparador_DB::post_map(absint($profile['id']));
            if (empty($map['post_id'])) continue;
            $url=get_permalink(absint($map['post_id']));
            $path=untrailingslashit((string)wp_parse_url((string)$url,PHP_URL_PATH));
            $metrics=(array)($by_path[$path] ?? array());
            $rows[]=array(
                'profile_id'=>absint($profile['id']),
                'category'=>(string)$profile['canonical_name'],
                'post_id'=>absint($map['post_id']),
                'post_title'=>(string)($map['post_title'] ?? get_the_title(absint($map['post_id']))),
                'url'=>$url,
                'status'=>(string)$profile['status'],
                'impressions'=>absint($metrics['impressions'] ?? 0),
                'clicks'=>absint($metrics['clicks'] ?? 0),
                'ctr'=>(float)($metrics['ctr'] ?? 0),
                'position'=>(float)($metrics['position'] ?? 0),
                'sessions'=>absint($metrics['sessions'] ?? 0),
                'pageviews'=>absint($metrics['pageviews'] ?? $metrics['views'] ?? 0),
                'last_data_at'=>(string)($data['generated_at'] ?? ''),
            );
        }
        return $rows;
    }
}
