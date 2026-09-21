<?php
/**
 * Ojeador jobs, scheduler and Process Manager integration.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.2.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Worker {
    const OPTION_SETTINGS = 'seo_ojeador_settings';
    const CRON_HOOK = 'seo_ojeador_weekly_scan';
    const LOCK_NAME = 'seo_ojeador_worker_v02';

    public static function init() {
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        add_action('init', array(__CLASS__, 'ensure_schedule'), 120);
        add_action(self::CRON_HOOK, array(__CLASS__, 'scheduled_run'));

    }

    public static function defaults() {
        return array(
            'auto_enabled' => 0,
            'allow_non_production' => 0,
            'weekday' => 0,
            'hour' => 3,
            'minute' => 30,
            'max_products_per_run' => 100,
            'max_offers_per_product' => 8,
            'freshness_hours' => 168,
            'request_timeout' => 12,
            'include_provider_catalog' => 1,
            'refresh_existing_urls' => 1,
        );
    }

    public static function settings() {
        $raw = get_option(self::OPTION_SETTINGS, array());
        return self::sanitize_settings(wp_parse_args(is_array($raw) ? $raw : array(), self::defaults()));
    }

    public static function sanitize_settings($raw) {
        $raw = wp_parse_args(is_array($raw) ? $raw : array(), self::defaults());
        return array(
            'auto_enabled' => empty($raw['auto_enabled']) ? 0 : 1,
            'allow_non_production' => empty($raw['allow_non_production']) ? 0 : 1,
            'weekday' => max(0, min(6, absint($raw['weekday']))),
            'hour' => max(0, min(23, absint($raw['hour']))),
            'minute' => max(0, min(59, absint($raw['minute']))),
            'max_products_per_run' => max(1, min(10000, absint($raw['max_products_per_run']))),
            'max_offers_per_product' => max(1, min(20, absint($raw['max_offers_per_product']))),
            'freshness_hours' => max(1, min(720, absint($raw['freshness_hours']))),
            'request_timeout' => max(5, min(25, absint($raw['request_timeout']))),
            'include_provider_catalog' => empty($raw['include_provider_catalog']) ? 0 : 1,
            'refresh_existing_urls' => empty($raw['refresh_existing_urls']) ? 0 : 1,
        );
    }

    public static function save_settings($raw) {
        $settings = self::sanitize_settings($raw);
        update_option(self::OPTION_SETTINGS, $settings, false);
        self::reschedule();
        return $settings;
    }

    public static function cron_schedules($schedules) {
        if (!isset($schedules['seo_ojeador_weekly'])) {
            $schedules['seo_ojeador_weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display' => 'Ojeador semanal',
            );
        }
        return $schedules;
    }

    public static function ensure_schedule() {
        $settings = self::settings();
        if (empty($settings['auto_enabled']) || !self::environment_allowed($settings)) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(self::next_schedule_timestamp($settings), 'seo_ojeador_weekly', self::CRON_HOOK);
        }
    }

    public static function reschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        self::ensure_schedule();
    }

    public static function next_schedule_timestamp($settings = null) {
        $settings = is_array($settings) ? $settings : self::settings();
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $tz);
        $target = $now->setTime((int) $settings['hour'], (int) $settings['minute'], 0);
        for ($i = 0; $i <= 7; $i++) {
            $candidate = $target->modify('+' . $i . ' days');
            if ((int) $candidate->format('w') !== (int) $settings['weekday']) {
                continue;
            }
            if ($candidate->getTimestamp() <= $now->getTimestamp() + 60) {
                continue;
            }
            return $candidate->getTimestamp();
        }
        return time() + WEEK_IN_SECONDS;
    }

    private static function environment_allowed($settings) {
        if (!empty($settings['allow_non_production'])) {
            return true;
        }
        $type = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        if ($type !== 'production') {
            return false;
        }
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (false !== strpos($host, 'staging.') || false !== strpos($host, '.staging.') || false !== strpos($host, 'localhost')) {
            return false;
        }
        return true;
    }

    public static function scheduled_run() {
        $settings = self::settings();
        if (empty($settings['auto_enabled']) || !self::environment_allowed($settings)) {
            return;
        }
        if (!SEO_Ojeador_DB::active_run()) {
            self::start_run('weekly', absint($settings['max_products_per_run']), 0, 'cron');
        }
        self::nudge_supervisor();
    }

    public static function start_run($run_type = 'manual', $max_products = 0, $product_id = 0, $source = 'admin') {
        if (SEO_Ojeador_DB::active_run()) {
            return new WP_Error('ojeador_run_active', 'Ya existe un barrido de Ojeador en curso.');
        }
        $settings = self::settings();
        $max_products = $max_products > 0 ? absint($max_products) : absint($settings['max_products_per_run']);
        $product_id = absint($product_id);
        if ($product_id > 0) {
            $max_products = 1;
        }
        $total = $product_id > 0 ? 1 : self::published_product_count();
        if ($max_products > 0) {
            $total = min($total, $max_products);
        }
        $run_id = SEO_Ojeador_DB::create_run(array(
            'run_type' => $product_id > 0 ? 'single' : sanitize_key($run_type),
            'source' => sanitize_key($source),
            'started_by' => get_current_user_id(),
            'max_products' => max(1, $max_products),
            'total_candidates' => max(1, $total),
            'meta' => array('single_product_id' => $product_id),
        ));
        if (!$run_id) {
            return new WP_Error('ojeador_run_create', 'No se pudo crear el trabajo de Ojeador.');
        }
        self::nudge_supervisor();
        return array('run_id' => $run_id, 'message' => 'Barrido de Ojeador iniciado.');
    }

    public static function stop_run() {
        $run = SEO_Ojeador_DB::active_run();
        if (!$run) {
            return false;
        }
        SEO_Ojeador_DB::update_run(absint($run['id']), array(
            'status' => 'stopped',
            'completed_at' => SEO_Ojeador_DB::utc_now(),
        ));
        return true;
    }

    public static function is_pending() {
        return (bool) SEO_Ojeador_DB::active_run();
    }

    public static function process_manager_slice($budget_seconds = 20, $source = 'manager', $control = null) {
        global $wpdb;
        $run = SEO_Ojeador_DB::active_run();
        if (!$run) {
            return false;
        }
        $lock = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', self::LOCK_NAME));
        if ($lock !== 1) {
            return false;
        }

        $started = microtime(true);
        $settings = self::settings();
        $run_id = absint($run['id']);
        try {
            if ('pending' === (string) $run['status']) {
                SEO_Ojeador_DB::update_run($run_id, array(
                    'status' => 'running',
                    'started_at' => SEO_Ojeador_DB::utc_now(),
                    'heartbeat_at' => SEO_Ojeador_DB::utc_now(),
                ));
            }

            $processed_this_slice = 0;
            $batch_limit = class_exists('SEO_Ojeador_Process')
                ? SEO_Ojeador_Process::current_batch_size(is_array($control) ? $control : null)
                : 3;
            while ($processed_this_slice < $batch_limit && (microtime(true) - $started) < max(3, ((float) $budget_seconds - 1))) {
                $run = SEO_Ojeador_DB::run_row($run_id);
                if (!$run || !in_array((string) $run['status'], array('pending', 'running'), true)) {
                    break;
                }
                if (absint($run['processed_products']) >= absint($run['max_products'])) {
                    self::complete_run($run_id);
                    break;
                }

                $meta = json_decode((string) ($run['meta_json'] ?? ''), true);
                $single_product_id = is_array($meta) ? absint($meta['single_product_id'] ?? 0) : 0;
                if ($single_product_id > 0) {
                    if (absint($run['processed_products']) > 0) {
                        self::complete_run($run_id);
                        break;
                    }
                    $product_id = $single_product_id;
                } else {
                    $product_id = self::next_product_id(absint($run['cursor_object_id']));
                    if ($product_id < 1) {
                        self::complete_run($run_id);
                        break;
                    }
                }

                $result = self::process_product($product_id, $settings);
                $fresh = SEO_Ojeador_DB::run_row($run_id);
                $update = array(
                    'cursor_object_id' => max(absint($fresh['cursor_object_id'] ?? 0), $product_id),
                    'processed_products' => absint($fresh['processed_products'] ?? 0) + 1,
                    'heartbeat_at' => SEO_Ojeador_DB::utc_now(),
                );
                if (is_wp_error($result)) {
                    $update['errors_count'] = absint($fresh['errors_count'] ?? 0) + 1;
                    $update['last_error'] = $result->get_error_message();
                } else {
                    $update['offers_seen'] = absint($fresh['offers_seen'] ?? 0) + absint($result['seen'] ?? 0);
                    $update['offers_created'] = absint($fresh['offers_created'] ?? 0) + absint($result['created'] ?? 0);
                    $update['offers_updated'] = absint($fresh['offers_updated'] ?? 0) + absint($result['updated'] ?? 0);
                    $update['last_error'] = '';
                }
                SEO_Ojeador_DB::update_run($run_id, $update);
                $processed_this_slice++;
            }

            $run = SEO_Ojeador_DB::run_row($run_id);
            if ($run && in_array((string) $run['status'], array('pending', 'running'), true) && absint($run['processed_products']) >= absint($run['max_products'])) {
                self::complete_run($run_id);
            }
            return true;
        } catch (Throwable $e) {
            SEO_Ojeador_DB::update_run($run_id, array(
                'status' => 'failed',
                'heartbeat_at' => SEO_Ojeador_DB::utc_now(),
                'completed_at' => SEO_Ojeador_DB::utc_now(),
                'last_error' => sanitize_text_field($e->getMessage()),
            ));
            return false;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK_NAME));
        }
    }

    public static function process_product($product_id, $settings = null) {
        $settings = is_array($settings) ? $settings : self::settings();
        $identity = SEO_Ojeador_Identity::from_product($product_id);
        if (is_wp_error($identity)) {
            return $identity;
        }
        $ojeador_product_id = SEO_Ojeador_DB::upsert_product($identity);
        if (is_wp_error($ojeador_product_id)) {
            return $ojeador_product_id;
        }

        $candidates = SEO_Ojeador_Sources::candidates($identity, $settings);
        if (!empty($settings['refresh_existing_urls'])) {
            $candidates = array_merge($candidates, SEO_Ojeador_Sources::existing_offer_candidates($ojeador_product_id));
        }

        $seen_keys = array();
        $seen = 0;
        $created = 0;
        $updated = 0;
        $offer_seen = false;
        foreach ($candidates as $candidate) {
            if ($seen >= absint($settings['max_offers_per_product'])) {
                break;
            }
            if (!is_array($candidate)) {
                continue;
            }
            $key = (string) ($candidate['offer_key'] ?? '');
            if ($key !== '' && isset($seen_keys[$key])) {
                continue;
            }
            if ($key !== '') {
                $seen_keys[$key] = true;
            }
            $offer = self::candidate_to_offer($identity, $candidate, $settings);
            if (is_wp_error($offer)) {
                continue;
            }
            if (($offer['match_status'] ?? '') === 'rejected') {
                continue;
            }
            $save = SEO_Ojeador_DB::save_offer($ojeador_product_id, $offer);
            if (is_wp_error($save)) {
                continue;
            }
            $seen++;
            $offer_seen = true;
            if (!empty($save['created'])) {
                $created++;
            } elseif (!empty($save['changed'])) {
                $updated++;
            }
        }

        SEO_Ojeador_DB::touch_product_scan($ojeador_product_id, absint($settings['freshness_hours']), $offer_seen);
        return array('seen' => $seen, 'created' => $created, 'updated' => $updated);
    }

    public static function inspect_manual_url($product_id, $merchant_name, $url) {
        $settings = self::settings();
        $identity = SEO_Ojeador_Identity::from_product($product_id);
        if (is_wp_error($identity)) {
            return $identity;
        }
        $ojeador_product_id = SEO_Ojeador_DB::upsert_product($identity);
        if (is_wp_error($ojeador_product_id)) {
            return $ojeador_product_id;
        }
        $candidate = SEO_Ojeador_Sources::manual_candidate($merchant_name, $url);
        $offer = self::candidate_to_offer($identity, $candidate, $settings);
        if (is_wp_error($offer)) {
            return $offer;
        }
        return SEO_Ojeador_DB::save_offer($ojeador_product_id, $offer);
    }

    private static function candidate_to_offer($identity, $candidate, $settings) {
        $data = isset($candidate['data']) && is_array($candidate['data']) ? $candidate['data'] : array();
        if (empty($data) && !empty($candidate['url'])) {
            $data = SEO_Ojeador_Inspector::inspect($candidate['url'], absint($settings['request_timeout']));
            if (is_wp_error($data)) {
                return $data;
            }
        }
        if (!is_array($data)) {
            return new WP_Error('ojeador_candidate_data', 'La fuente no devolvio datos utilizables.');
        }

        $observed = array(
            'observed_title' => sanitize_text_field((string) ($data['title'] ?? '')),
            'observed_gtin' => SEO_Ojeador_Identity::normalize_gtin($data['gtin'] ?? ''),
            'observed_mpn' => sanitize_text_field((string) ($data['mpn'] ?? '')),
            'observed_brand' => sanitize_text_field((string) ($data['brand'] ?? '')),
            'observed_model' => sanitize_text_field((string) ($data['model'] ?? '')),
            'sku' => sanitize_text_field((string) ($data['sku'] ?? '')),
        );
        $match = SEO_Ojeador_Identity::match($identity, $observed, (array) ($candidate['match_hint'] ?? array()));
        $expires_at = gmdate('Y-m-d H:i:s', time() + (absint($settings['freshness_hours']) * HOUR_IN_SECONDS));

        return array(
            'source_key' => sanitize_key((string) ($candidate['source_key'] ?? 'external')),
            'source_type' => sanitize_key((string) ($candidate['source_type'] ?? 'external_url')) ?: 'external_url',
            'merchant_name' => sanitize_text_field((string) ($candidate['merchant_name'] ?? '')),
            'seller_name' => sanitize_text_field((string) ($data['seller_name'] ?? '')),
            'external_product_id' => sanitize_text_field((string) ($candidate['external_product_id'] ?? '')),
            'offer_key' => (string) ($candidate['offer_key'] ?? ''),
            'url' => esc_url_raw((string) ($candidate['url'] ?? ($data['url'] ?? ''))),
            'observed_title' => $observed['observed_title'],
            'observed_gtin' => $observed['observed_gtin'],
            'observed_mpn' => $observed['observed_mpn'],
            'observed_brand' => $observed['observed_brand'],
            'observed_model' => $observed['observed_model'],
            'price_raw' => $data['price_raw'] ?? null,
            'price_net' => $data['price_net'] ?? null,
            'price_gross' => $data['price_gross'] ?? null,
            'vat_rate' => $data['vat_rate'] ?? null,
            'vat_mode' => $data['vat_mode'] ?? 'unknown',
            'shipping_price' => $data['shipping_price'] ?? null,
            'shipping_mode' => $data['shipping_mode'] ?? 'unknown',
            'total_price' => $data['total_price'] ?? null,
            'currency' => $data['currency'] ?? 'EUR',
            'stock_status' => $data['stock_status'] ?? '',
            'stock_text' => $data['stock_text'] ?? '',
            'condition_label' => $data['condition_label'] ?? '',
            'match_method' => $match['method'],
            'match_confidence' => $match['confidence'],
            'match_status' => $match['status'],
            'extraction_method' => $data['extraction_method'] ?? '',
            'observed_at' => $data['observed_at'] ?? SEO_Ojeador_DB::utc_now(),
            'expires_at' => $expires_at,
            'active' => 1,
            'raw' => $data['raw'] ?? array(),
        );
    }

    private static function published_product_count() {
        global $wpdb;
        $watch = SEO_Ojeador_DB::table('products');
        return absint($wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->posts} p
             LEFT JOIN {$watch} o ON o.object_id=p.ID
             WHERE p.post_type='product'
               AND p.post_status='publish'
               AND (o.status IS NULL OR o.status<>'paused')"
        ));
    }

    private static function next_product_id($cursor) {
        global $wpdb;
        $watch = SEO_Ojeador_DB::table('products');
        return absint($wpdb->get_var($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$watch} o ON o.object_id=p.ID
             WHERE p.post_type='product'
               AND p.post_status='publish'
               AND p.ID>%d
               AND (o.status IS NULL OR o.status<>'paused')
             ORDER BY p.ID ASC
             LIMIT 1",
            absint($cursor)
        )));
    }

    private static function complete_run($run_id) {
        SEO_Ojeador_DB::update_run($run_id, array(
            'status' => 'completed',
            'heartbeat_at' => SEO_Ojeador_DB::utc_now(),
            'completed_at' => SEO_Ojeador_DB::utc_now(),
            'last_error' => '',
        ));
    }

    private static function nudge_supervisor() {
        if (function_exists('seo_process_supervisor_nudge')) {
            seo_process_supervisor_nudge(0, 'ojeador');
        }
        if (function_exists('seo_process_supervisor_schedule_backup')) {
            seo_process_supervisor_schedule_backup(true);
        }
    }

}
