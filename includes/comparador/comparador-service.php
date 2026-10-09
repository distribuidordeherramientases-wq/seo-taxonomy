<?php
/**
 * Comparador v2 - análisis de mercado y propuestas editoriales por categoría.
 *
 * Ojeador recopila el mercado. Comparador no consulta fuentes externas: consume
 * los snapshots ya persistidos, los normaliza por categoría, calcula un dossier
 * interno y propone un único informe público breve. La aceptación humana crea
 * un post WordPress en borrador para la Editora.
 */

defined('ABSPATH') || exit;

final class SEO_Comparador_Service {
    const VERSION = '2.0.0';
    const MIN_MARKET_ROWS = 5;
    const MIN_PRICED_ROWS = 3;
    const META_DOSSIER = '_seo_comparador_market_dossier';
    const META_SERVICE_VERSION = '_seo_comparador_service_version';

    public static function init() {
        add_action('init', array(__CLASS__, 'clear_legacy_cron'), 5);
        add_action('seo_post_editor_before_form', array(__CLASS__, 'render_editor_panel'), 25, 1);
    }

    public static function clear_legacy_cron() {
        static $done = false;
        if ($done) return;
        $done = true;
        if (class_exists('SEO_Comparador_Engine')) {
            wp_clear_scheduled_hook(SEO_Comparador_Engine::AUTO_REFRESH_HOOK);
            wp_clear_scheduled_hook(SEO_Comparador_Engine::AUTO_REFRESH_STEP_HOOK);
        }
    }

    public static function queue_refresh() {
        if (class_exists('SEO_Comparador_Engine')) {
            delete_option(SEO_Comparador_Engine::AUTO_STATE_OPTION);
        }
        if (class_exists('SEO_Comparador_Process') && method_exists('SEO_Comparador_Process','clear_dirty_queue')) {
            SEO_Comparador_Process::clear_dirty_queue();
        }
        if (function_exists('seo_process_supervisor_nudge')) {
            seo_process_supervisor_nudge(0, 'comparador');
        }
        if (function_exists('seo_process_supervisor_schedule_backup')) {
            seo_process_supervisor_schedule_backup();
        }
    }

    private static function now() {
        return current_time('mysql');
    }

    private static function normalize_text($value) {
        $value = remove_accents(wp_strip_all_tags((string) $value));
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function market_key(array $row) {
        $google_id = trim((string) ($row['google_product_id'] ?? ''));
        if ($google_id !== '') return 'google:' . $google_id;

        $brand = self::normalize_text($row['brand'] ?? '');
        $model = self::normalize_text($row['model'] ?? '');
        if ($brand !== '' && $model !== '') return 'brand-model:' . $brand . '|' . $model;

        $title = self::normalize_text($row['title'] ?? '');
        if ($title !== '') return 'title:' . $title;

        return 'row:' . sanitize_text_field((string) ($row['result_hash'] ?? $row['id'] ?? md5(wp_json_encode($row))));
    }

    private static function row_quality(array $row) {
        $score = 0;
        foreach (array('title','brand','model','description','price','google_product_id') as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') $score++;
        }
        return $score;
    }

    private static function market_rows($term_id) {
        if (!function_exists('seo_ojeador_get_category_market')) return array();
        $rows = (array) seo_ojeador_get_category_market(absint($term_id), 2000);
        $deduped = array();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = self::market_key($row);
            if (!isset($deduped[$key]) || self::row_quality($row) > self::row_quality($deduped[$key])) {
                $deduped[$key] = $row;
            }
        }
        return array_values($deduped);
    }

    private static function percentile(array $values, $fraction) {
        $values = array_values(array_filter($values, 'is_numeric'));
        if (!$values) return null;
        sort($values, SORT_NUMERIC);
        $n = count($values);
        if ($n === 1) return (float) $values[0];
        $fraction = max(0.0, min(1.0, (float) $fraction));
        $pos = ($n - 1) * $fraction;
        $low = (int) floor($pos);
        $high = (int) ceil($pos);
        if ($low === $high) return (float) $values[$low];
        $weight = $pos - $low;
        return ((float) $values[$low] * (1 - $weight)) + ((float) $values[$high] * $weight);
    }

    public static function price_stats_for_test(array $values) {
        return self::price_stats($values);
    }

    private static function price_stats(array $values) {
        $values = array_values(array_filter($values, static function($v) {
            return is_numeric($v) && (float) $v > 0;
        }));
        if (!$values) {
            return array('count'=>0,'min'=>null,'q1'=>null,'median'=>null,'q3'=>null,'max'=>null);
        }
        sort($values, SORT_NUMERIC);
        return array(
            'count'=>count($values),
            'min'=>(float) $values[0],
            'q1'=>self::percentile($values, 0.25),
            'median'=>self::percentile($values, 0.50),
            'q3'=>self::percentile($values, 0.75),
            'max'=>(float) $values[count($values)-1],
        );
    }

    private static function money($value, $currency = 'EUR') {
        if (!is_numeric($value)) return '';
        $currency = strtoupper(sanitize_text_field((string) $currency)) ?: 'EUR';
        $number = number_format_i18n((float) $value, ((float) $value < 100 ? 2 : 0));
        return $currency === 'EUR' ? $number . ' €' : $number . ' ' . $currency;
    }

    private static function axis_label($key) {
        $labels = array(
            'power'=>'Potencia', 'capacity'=>'Capacidad', 'pressure'=>'Presión', 'torque'=>'Par',
            'voltage'=>'Voltaje', 'frequency'=>'Frecuencia', 'load'=>'Carga máxima', 'weight'=>'Peso',
            'speed'=>'Velocidad', 'flow'=>'Caudal', 'diameter'=>'Diámetro', 'length'=>'Longitud',
            'width'=>'Ancho', 'height'=>'Altura', 'temperature'=>'Temperatura',
        );
        return $labels[$key] ?? ucwords(str_replace('-', ' ', sanitize_key((string) $key)));
    }

    private static function aggregate_axes(array $rows) {
        $total = count($rows);
        if (!$total || !class_exists('SEO_Comparador_Engine')) return array();
        $agg = array();
        foreach ($rows as $row) {
            $values = SEO_Comparador_Engine::extract_external_values((array) $row);
            foreach ($values as $key=>$value) {
                if ($key === 'price') continue;
                $normalized = trim((string) ($value['normalized_value'] ?? ''));
                if ($normalized === '') continue;
                if (!isset($agg[$key])) {
                    $agg[$key] = array('known'=>0,'values'=>array(),'unit'=>(string)($value['unit'] ?? ''));
                }
                $agg[$key]['known']++;
                $agg[$key]['values'][self::normalize_text($normalized . ' ' . ($value['unit'] ?? ''))] = true;
                if ($agg[$key]['unit'] === '') $agg[$key]['unit'] = (string) ($value['unit'] ?? '');
            }
        }

        $minimum_known = max(2, (int) ceil($total * 0.15));
        $out = array();
        foreach ($agg as $key=>$row) {
            $variation = count($row['values']);
            if ($row['known'] < $minimum_known || $variation < 2) continue;
            $out[] = array(
                'key'=>sanitize_key((string) $key),
                'label'=>self::axis_label($key),
                'coverage'=>round($row['known'] / $total, 4),
                'known'=>absint($row['known']),
                'variation'=>$variation,
                'unit'=>sanitize_text_field((string) $row['unit']),
            );
        }
        usort($out, static function($a,$b) {
            $coverage = ($b['coverage'] <=> $a['coverage']);
            return $coverage !== 0 ? $coverage : ($b['variation'] <=> $a['variation']);
        });
        return array_slice($out, 0, 6);
    }

    private static function market_analysis(array $rows) {
        $currencies = array();
        $brand_counts = array();
        $merchant_keys = array();
        foreach ($rows as $row) {
            $currency = strtoupper(sanitize_text_field((string) ($row['currency'] ?? 'EUR'))) ?: 'EUR';
            $price = $row['price'] ?? null;
            if (is_numeric($price) && (float) $price > 0) {
                if (!isset($currencies[$currency])) $currencies[$currency] = array();
                $currencies[$currency][] = (float) $price;
            }

            $brand = trim(sanitize_text_field((string) ($row['brand'] ?? '')));
            if ($brand !== '') {
                $brand_key = self::normalize_text($brand);
                if ($brand_key !== '') {
                    if (!isset($brand_counts[$brand_key])) $brand_counts[$brand_key] = array('name'=>$brand,'count'=>0);
                    $brand_counts[$brand_key]['count']++;
                }
            }

            $merchant = self::normalize_text($row['merchant'] ?? '');
            if ($merchant !== '') $merchant_keys[$merchant] = true;
        }

        $dominant_currency = 'EUR';
        $prices = array();
        if ($currencies) {
            uasort($currencies, static function($a,$b){ return count($b) <=> count($a); });
            $dominant_currency = (string) array_key_first($currencies);
            $prices = (array) reset($currencies);
        }

        uasort($brand_counts, static function($a,$b){
            $count = absint($b['count']) <=> absint($a['count']);
            return $count !== 0 ? $count : strcmp((string)$a['name'], (string)$b['name']);
        });
        $brands = array_values($brand_counts);

        return array(
            'market_count'=>count($rows),
            'currency'=>$dominant_currency,
            'price'=>self::price_stats($prices),
            'brands'=>array_slice($brands, 0, 8),
            'brand_count'=>count($brands),
            'merchant_count'=>count($merchant_keys),
            'axes'=>self::aggregate_axes($rows),
        );
    }

    private static function own_catalog_analysis($term_id) {
        $term = get_term(absint($term_id), 'product_cat');
        if (!$term || is_wp_error($term) || !function_exists('wc_get_products')) {
            return array('count'=>0,'price'=>self::price_stats(array()),'currency'=>function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR');
        }
        $products = wc_get_products(array(
            'status'=>'publish',
            'limit'=>500,
            'category'=>array($term->slug),
            'return'=>'objects',
            'orderby'=>'ID',
            'order'=>'ASC',
        ));
        $prices = array();
        $sample = array();
        foreach ((array) $products as $product) {
            if (!is_object($product) || !method_exists($product, 'get_id')) continue;
            $price = $product->get_price();
            if ($price !== '' && is_numeric($price) && (float)$price > 0) $prices[] = (float) $price;
            if (count($sample) < 12) {
                $sample[] = array(
                    'product_id'=>absint($product->get_id()),
                    'title'=>sanitize_text_field((string) $product->get_name()),
                    'price'=>is_numeric($price) ? (float) $price : null,
                    'sku'=>sanitize_text_field((string) $product->get_sku()),
                );
            }
        }
        return array(
            'count'=>count((array) $products),
            'currency'=>function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
            'price'=>self::price_stats($prices),
            'sample'=>$sample,
        );
    }

    private static function latest_observed_at(array $rows) {
        $latest = '';
        foreach ($rows as $row) {
            $candidate = sanitize_text_field((string) ($row['observed_at'] ?? $row['last_seen_at'] ?? ''));
            if ($candidate !== '' && ($latest === '' || $candidate > $latest)) $latest = $candidate;
        }
        return $latest;
    }

    private static function representative_rows(array $rows, $limit = 12) {
        $out = array();
        foreach ($rows as $row) {
            if (count($out) >= absint($limit)) break;
            $out[] = array(
                'title'=>sanitize_text_field((string) ($row['title'] ?? '')),
                'brand'=>sanitize_text_field((string) ($row['brand'] ?? '')),
                'model'=>sanitize_text_field((string) ($row['model'] ?? '')),
                'price'=>is_numeric($row['price'] ?? null) ? (float) $row['price'] : null,
                'currency'=>strtoupper(sanitize_text_field((string) ($row['currency'] ?? 'EUR'))) ?: 'EUR',
                'merchant'=>sanitize_text_field((string) ($row['merchant'] ?? '')),
                'merchant_url'=>esc_url_raw((string) ($row['merchant_url'] ?? '')),
                'product_url'=>esc_url_raw((string) ($row['product_url'] ?? '')),
                'observed_at'=>sanitize_text_field((string) ($row['observed_at'] ?? $row['last_seen_at'] ?? '')),
            );
        }
        return $out;
    }

    private static function public_report($category_name, array $market) {
        $category_name = sanitize_text_field((string) $category_name);
        $count = absint($market['market_count'] ?? 0);
        $parts = array();
        $parts[] = 'En la muestra de mercado observada para ' . $category_name . ' aparecen ' . number_format_i18n($count) . ' referencias comparables';

        $price = (array) ($market['price'] ?? array());
        $currency = (string) ($market['currency'] ?? 'EUR');
        if (absint($price['count'] ?? 0) >= self::MIN_PRICED_ROWS) {
            $parts[] = 'con precios entre ' . self::money($price['min'], $currency) . ' y ' . self::money($price['max'], $currency)
                . ', una mediana de ' . self::money($price['median'], $currency)
                . ' y una zona central aproximada entre ' . self::money($price['q1'], $currency) . ' y ' . self::money($price['q3'], $currency);
        }

        $brands = array_values((array) ($market['brands'] ?? array()));
        if ($brands) {
            $names = array_values(array_filter(array_map(static function($row) {
                return sanitize_text_field((string) ($row['name'] ?? ''));
            }, array_slice($brands, 0, 6))));
            if ($names) $parts[] = 'entre las marcas identificadas figuran ' . implode(', ', $names);
        }

        $axes = array_values((array) ($market['axes'] ?? array()));
        if ($axes) {
            $labels = array_values(array_filter(array_map(static function($row) {
                return sanitize_text_field((string) ($row['label'] ?? ''));
            }, array_slice($axes, 0, 5))));
            if ($labels) $parts[] = 'las diferencias mejor documentadas en las fichas se concentran en ' . implode(', ', $labels);
        }

        $text = implode('. ', array_map(static function($part){ return rtrim(trim((string)$part), '.;'); }, $parts));
        return trim($text, " .\t\n\r\0\x0B") . '.';
    }

    private static function own_position(array $own, array $market) {
        $own_median = $own['price']['median'] ?? null;
        $market_median = $market['price']['median'] ?? null;
        if (!is_numeric($own_median) || !is_numeric($market_median) || (float)$market_median <= 0) {
            return array('delta_pct'=>null,'signal'=>'sin_datos','text'=>'No hay medianas comparables suficientes para situar el precio del catálogo propio.');
        }
        $delta = (((float)$own_median - (float)$market_median) / (float)$market_median) * 100;
        if ($delta <= -10) $signal = 'por_debajo_del_mercado';
        elseif ($delta >= 10) $signal = 'por_encima_del_mercado';
        else $signal = 'alineado';
        $text = 'Mediana propia ' . self::money($own_median, $own['currency'] ?? 'EUR')
            . ' frente a mediana de mercado ' . self::money($market_median, $market['currency'] ?? 'EUR')
            . ' (' . number_format_i18n($delta, 1) . '%). Señal orientativa: ' . str_replace('_', ' ', $signal)
            . '. No implica una acción automática de precio porque la categoría puede contener gamas distintas.';
        return array('delta_pct'=>round($delta,1),'signal'=>$signal,'text'=>$text);
    }

    public static function proposal_ready_for_test($market_count, $priced_count, $brand_count) {
        return absint($market_count) >= self::MIN_MARKET_ROWS
            && (absint($priced_count) >= self::MIN_PRICED_ROWS || absint($brand_count) >= 2);
    }

    private static function save_editorial($profile_id, array $profile, array $dossier, $public_report) {
        global $wpdb;
        $profile_id = absint($profile_id);
        $table = SEO_Comparador_DB::table('editorial');
        $existing = SEO_Comparador_DB::editorial($profile_id);
        $axes = array_values(array_filter(array_map(static function($row) {
            return sanitize_text_field((string) ($row['label'] ?? ''));
        }, (array) ($dossier['market']['axes'] ?? array()))));

        $title = 'Mercado de ' . sanitize_text_field((string) ($profile['canonical_name'] ?? '')) . ': precios, marcas y diferencias de oferta';
        $excerpt = wp_html_excerpt(wp_strip_all_tags($public_report), 320, '…');
        $limits = array(
            'market_count'=>absint($dossier['market']['market_count'] ?? 0),
            'priced_count'=>absint($dossier['market']['price']['count'] ?? 0),
            'brand_count'=>absint($dossier['market']['brand_count'] ?? 0),
            'merchant_count'=>absint($dossier['market']['merchant_count'] ?? 0),
            'snapshot_at'=>(string) ($dossier['snapshot_at'] ?? ''),
            'trend_available'=>false,
        );
        $summary = 'Dossier de mercado: ' . number_format_i18n($limits['market_count']) . ' referencias deduplicadas, '
            . number_format_i18n($limits['priced_count']) . ' con precio y '
            . number_format_i18n($limits['brand_count']) . ' marcas identificadas. ' . (string) ($dossier['own_position']['text'] ?? '');
        $limitations = 'El informe describe una fotografía del mercado observada por Ojeador. No se publican tiendas ni enlaces externos y no se afirman tendencias temporales hasta disponer de histórico suficiente.';
        $row = array(
            'summary'=>sanitize_textarea_field($summary),
            'suggested_title'=>sanitize_text_field($title),
            'excerpt'=>sanitize_textarea_field($excerpt),
            'comparison_text'=>wp_kses_post('<p>' . esc_html($public_report) . '</p>'),
            'product_types'=>wp_json_encode(array(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'main_differences'=>wp_json_encode($axes, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'buying_criteria'=>wp_json_encode($axes, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'use_cases'=>wp_json_encode(array(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'market_overview'=>sanitize_textarea_field($public_report),
            'own_catalog_position'=>sanitize_textarea_field((string) ($dossier['own_position']['text'] ?? '')),
            'limits'=>wp_json_encode($limits, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'editorial_limitations'=>sanitize_textarea_field($limitations),
            'conclusion'=>'',
            'origin'=>'generated_v2',
            'source_hash_at_edit'=>(string) ($profile['source_hash'] ?? ''),
            'source_snapshot_at_edit'=>!empty($profile['source_snapshot_at']) ? (string) $profile['source_snapshot_at'] : null,
            'import_meta'=>wp_json_encode(array('dossier'=>$dossier), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'version'=>max(1, absint($existing['version'] ?? 0) + 1),
            'generated_at'=>self::now(),
            'updated_at'=>self::now(),
        );
        if ($existing) {
            $wpdb->update($table, $row, array('profile_id'=>$profile_id));
        } else {
            $row['profile_id'] = $profile_id;
            $wpdb->insert($table, $row);
        }
        return SEO_Comparador_DB::editorial($profile_id);
    }

    public static function analyze_category($term_id) {
        global $wpdb;
        $term_id = absint($term_id);
        if (!$term_id) return new WP_Error('comparador_category', 'Categoría no válida.');
        $term = get_term($term_id, 'product_cat');
        if (!$term || is_wp_error($term)) return new WP_Error('comparador_category', 'Categoría no válida.');

        SEO_Comparador_DB::maybe_install();
        $previous = SEO_Comparador_DB::profile_for_category($term_id);
        $previous_status = sanitize_key((string) ($previous['status'] ?? ''));
        $previous_hash = (string) ($previous['source_hash'] ?? '');

        $profile = SEO_Comparador_Engine::build_profile($term_id);
        if (is_wp_error($profile) || empty($profile['id'])) return $profile;
        $profile_id = absint($profile['id']);
        $profile = SEO_Comparador_DB::get_profile($profile_id);

        $market_rows = self::market_rows($term_id);
        $market = self::market_analysis($market_rows);
        $own = self::own_catalog_analysis($term_id);
        $own_position = self::own_position($own, $market);
        $snapshot_at = self::latest_observed_at($market_rows);
        $dossier = array(
            'schema'=>'seo-comparador-market-dossier-v2',
            'service_version'=>self::VERSION,
            'profile_id'=>$profile_id,
            'category'=>array('term_id'=>$term_id,'name'=>sanitize_text_field((string)$term->name),'slug'=>sanitize_title((string)$term->slug)),
            'snapshot_at'=>$snapshot_at,
            'market'=>$market,
            'own_catalog'=>$own,
            'own_position'=>$own_position,
            'representative_market'=>self::representative_rows($market_rows, 12),
            'generated_at'=>self::now(),
        );

        $public_report = $market_rows ? self::public_report((string) $term->name, $market) : '';
        self::save_editorial($profile_id, $profile, $dossier, $public_report);

        $map = SEO_Comparador_DB::post_map($profile_id);
        $post_id = absint($map['post_id'] ?? 0);
        $post_status = $post_id ? get_post_status($post_id) : '';
        $ready = self::proposal_ready_for_test(
            $market['market_count'] ?? 0,
            $market['price']['count'] ?? 0,
            $market['brand_count'] ?? 0
        );

        if (!$market_rows) {
            $target = 'waiting_ojeador';
            $action = 'WAIT_OJEADOR';
            $reason = 'Ojeador todavía no ha guardado referencias de mercado para esta categoría.';
        } elseif (!$ready) {
            $target = 'insufficient_market';
            $action = 'NEEDS_REVIEW';
            $reason = 'Hay datos de Ojeador, pero la muestra todavía es insuficiente para un informe de mercado publicable.';
        } elseif ($post_id && $post_status === 'publish') {
            $changed = $previous_hash !== '' && $previous_hash !== (string) ($profile['source_hash'] ?? '');
            $target = $changed ? 'needs_update' : 'published';
            $action = $changed ? 'IMPROVE_POST' : 'NO_ACTION';
            $reason = $changed ? 'El mercado observado ha cambiado desde la última versión publicada.' : 'El informe de mercado ya está publicado y sus fuentes no han cambiado.';
        } elseif ($post_id) {
            $changed = $previous_hash !== '' && $previous_hash !== (string) ($profile['source_hash'] ?? '');
            $target = $changed ? 'needs_update' : 'post_draft';
            $action = $changed ? 'IMPROVE_POST' : 'NO_ACTION';
            $reason = $changed ? 'El dossier del mercado ha cambiado y el borrador debe revisarse.' : 'El informe ya tiene un borrador vinculado.';
        } elseif ($previous_status === 'closed' && $previous_hash !== '' && $previous_hash === (string) ($profile['source_hash'] ?? '')) {
            $target = 'closed';
            $action = 'NO_ACTION';
            $reason = 'La propuesta fue descartada y las fuentes no han cambiado.';
        } else {
            $target = 'proposal';
            $action = 'CREATE_POST';
            $reason = 'Ojeador aporta una muestra suficiente y Comparador ha generado un dossier de mercado y una propuesta breve.';
        }

        $wpdb->update(SEO_Comparador_DB::table('profiles'), array(
            'recommended_action'=>$action,
            'decision_reason'=>$reason,
            'source_snapshot_at'=>$snapshot_at !== '' ? $snapshot_at : ($profile['source_snapshot_at'] ?? null),
            'updated_at'=>self::now(),
        ), array('id'=>$profile_id));

        if (sanitize_key((string) ($profile['status'] ?? '')) !== $target) {
            SEO_Comparador_DB::update_status($profile_id, $target, 'market_analysis_v2', $reason, 'comparador');
        }

        return array(
            'profile_id'=>$profile_id,
            'status'=>$target,
            'recommended_action'=>$action,
            'reason'=>$reason,
            'dossier'=>$dossier,
            'public_report'=>$public_report,
        );
    }

    public static function dossier($profile_id) {
        $editorial = SEO_Comparador_DB::editorial(absint($profile_id));
        $meta = SEO_Comparador_DB::decode_json($editorial['import_meta'] ?? '{}');
        return isset($meta['dossier']) && is_array($meta['dossier']) ? $meta['dossier'] : array();
    }

    public static function accept_and_create_draft($profile_id) {
        $profile_id = absint($profile_id);
        $profile = SEO_Comparador_DB::get_profile($profile_id);
        if (!$profile) return new WP_Error('comparador_profile', 'Perfil no encontrado.');
        $status = sanitize_key((string) ($profile['status'] ?? ''));
        if (!in_array($status, array('proposal','accepted'), true)) {
            return new WP_Error('comparador_not_proposal', 'La categoría no tiene una propuesta lista para aceptar.');
        }

        $map = SEO_Comparador_DB::post_map($profile_id);
        if (!empty($map['post_id']) && get_post(absint($map['post_id']))) return absint($map['post_id']);

        $editorial = SEO_Comparador_DB::editorial($profile_id);
        $title = sanitize_text_field((string) ($editorial['suggested_title'] ?? ''));
        $excerpt = sanitize_textarea_field((string) ($editorial['excerpt'] ?? ''));
        $content = wp_kses_post((string) ($editorial['comparison_text'] ?? ''));
        if ($title === '' || trim(wp_strip_all_tags($content)) === '') {
            return new WP_Error('comparador_empty_proposal', 'La propuesta no contiene título o informe público suficiente.');
        }

        SEO_Comparador_DB::update_status($profile_id, 'accepted', 'accept_proposal', 'La Editora ha aceptado la propuesta de mercado.', 'admin');
        $post_id = wp_insert_post(wp_slash(array(
            'post_type'=>'post',
            'post_status'=>'draft',
            'post_title'=>$title,
            'post_excerpt'=>$excerpt,
            'post_content'=>$content,
        )), true);
        if (is_wp_error($post_id) || !absint($post_id)) {
            SEO_Comparador_DB::update_status($profile_id, 'proposal', 'draft_error', 'No se pudo crear el borrador.', 'system');
            return is_wp_error($post_id) ? $post_id : new WP_Error('comparador_draft', 'No se pudo crear el borrador.');
        }

        $linked = SEO_Comparador_Engine::link_post($profile_id, absint($post_id));
        if (is_wp_error($linked)) {
            wp_delete_post(absint($post_id), true);
            SEO_Comparador_DB::update_status($profile_id, 'proposal', 'link_error', $linked->get_error_message(), 'system');
            return $linked;
        }

        update_post_meta(absint($post_id), self::META_DOSSIER, self::dossier($profile_id));
        update_post_meta(absint($post_id), self::META_SERVICE_VERSION, self::VERSION);
        update_post_meta(absint($post_id), '_seo_solucionador_content_role', 'comparison');
        return absint($post_id);
    }

    public static function reject($profile_id, $reason = '') {
        $profile_id = absint($profile_id);
        if (!SEO_Comparador_DB::get_profile($profile_id)) return false;
        return SEO_Comparador_DB::update_status(
            $profile_id,
            'closed',
            'reject_proposal',
            $reason !== '' ? sanitize_textarea_field($reason) : 'Propuesta descartada por la Editora.',
            'admin'
        );
    }

    public static function counts() {
        $out = array('total'=>0,'proposal'=>0,'waiting_ojeador'=>0,'insufficient_market'=>0,'post_draft'=>0,'published'=>0,'needs_update'=>0,'closed'=>0);
        foreach (SEO_Comparador_DB::list_profiles(2000) as $profile) {
            $out['total']++;
            $status = sanitize_key((string) ($profile['status'] ?? ''));
            if (isset($out[$status])) $out[$status]++;
        }
        return $out;
    }

    public static function render_editor_panel($post_id) {
        $post_id = absint($post_id);
        if (!$post_id) return;
        $profile_id = absint(get_post_meta($post_id, '_seo_comparador_profile_id', true));
        if (!$profile_id) return;
        // Mostrar siempre el dossier más reciente calculado por Comparador.
        // El metadato del post se conserva como fotografía de la propuesta
        // aceptada, pero no debe ocultar novedades posteriores de Ojeador.
        $dossier = self::dossier($profile_id);
        if (!$dossier) $dossier = get_post_meta($post_id, self::META_DOSSIER, true);
        if (!is_array($dossier) || !$dossier) return;

        $market = (array) ($dossier['market'] ?? array());
        $price = (array) ($market['price'] ?? array());
        $currency = (string) ($market['currency'] ?? 'EUR');
        $brands = array_values((array) ($market['brands'] ?? array()));
        $axes = array_values((array) ($market['axes'] ?? array()));
        $position = (array) ($dossier['own_position'] ?? array());
        $refs = array_values((array) ($dossier['representative_market'] ?? array()));

        echo '<div class="postbox" style="padding:16px;margin:12px 0;border-left:4px solid #2271b1">';
        echo '<h2 style="margin-top:0">Comparador · dossier interno de mercado</h2>';
        echo '<p class="description">Material de trabajo procedente de Ojeador. Este bloque no forma parte del contenido público del post.</p>';
        echo '<p><strong>Muestra:</strong> ' . esc_html(number_format_i18n(absint($market['market_count'] ?? 0))) . ' referencias';
        if (absint($price['count'] ?? 0)) {
            echo ' · <strong>Precios:</strong> ' . esc_html(self::money($price['min'] ?? null, $currency)) . ' – ' . esc_html(self::money($price['max'] ?? null, $currency));
            echo ' · <strong>Mediana:</strong> ' . esc_html(self::money($price['median'] ?? null, $currency));
        }
        echo '</p>';
        if ($brands) {
            $brand_names = array_values(array_filter(array_map(static function($row){ return sanitize_text_field((string)($row['name'] ?? '')); }, array_slice($brands,0,8))));
            if ($brand_names) echo '<p><strong>Marcas observadas:</strong> ' . esc_html(implode(', ', $brand_names)) . '</p>';
        }
        if ($axes) {
            $labels = array_values(array_filter(array_map(static function($row){ return sanitize_text_field((string)($row['label'] ?? '')); }, $axes)));
            if ($labels) echo '<p><strong>Diferencias documentadas:</strong> ' . esc_html(implode(', ', $labels)) . '</p>';
        }
        if (!empty($position['text'])) echo '<p><strong>Posición interna de precios:</strong> ' . esc_html((string)$position['text']) . '</p>';

        if ($refs) {
            echo '<details><summary><strong>Referencias representativas de Ojeador</strong></summary>';
            echo '<table class="widefat striped" style="margin-top:10px"><thead><tr><th>Producto</th><th>Marca</th><th>Precio</th><th>Fuente interna</th></tr></thead><tbody>';
            foreach (array_slice($refs,0,10) as $row) {
                echo '<tr><td>' . esc_html((string)($row['title'] ?? '')) . '</td><td>' . esc_html((string)($row['brand'] ?? '')) . '</td><td>';
                if (is_numeric($row['price'] ?? null)) echo esc_html(self::money($row['price'], $row['currency'] ?? 'EUR'));
                echo '</td><td>' . esc_html((string)($row['merchant'] ?? '')) . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }
        echo '</div>';
    }
}

