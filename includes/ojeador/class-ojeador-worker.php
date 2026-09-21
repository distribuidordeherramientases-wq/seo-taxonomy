<?php
/**
 * Automatic product-by-product Google Shopping worker.
 *
 * Small internal batches are only a load-control detail. The user starts one
 * scan and the worker keeps advancing through all due WooCommerce products.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.5.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Worker {
    const CRON_HOOK = 'seo_ojeador_market_tick';
    const OPTION_LAST_SYNC = 'seo_ojeador_market_last_sync';
    const LOCK = 'seo_ojeador_market_worker_lock';

    public static function init() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'cron_tick'));
        add_action('init', array(__CLASS__, 'ensure_tick'), 50);
    }

    public static function ensure_tick() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 60, self::CRON_HOOK);
        }
    }

    public static function start_run($source = 'manual') {
        $ready = SEO_Ojeador_Shopping::readiness();
        if (is_wp_error($ready)) {
            return $ready;
        }
        $active = SEO_Ojeador_DB::active_run();
        if ($active) {
            return array('run_id'=>absint($active['id']), 'already_running'=>true);
        }
        $run_id = SEO_Ojeador_DB::create_run($source);
        if ($run_id < 1) {
            return new WP_Error('ojeador_run', 'No se pudo crear el proceso de Ojeador.');
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        }
        return array('run_id'=>$run_id, 'already_running'=>false);
    }

    public static function stop_run() {
        return SEO_Ojeador_DB::stop_active_run();
    }

    public static function is_pending() {
        return (bool) SEO_Ojeador_DB::active_run();
    }

    public static function cron_tick() {
        self::maybe_auto_start();
        if (self::is_pending()) {
            self::process_batch('cron');
        }
        if (self::is_pending()) {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_single_event(time() + 20, self::CRON_HOOK);
            }
        } elseif (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, self::CRON_HOOK);
        }
    }

    private static function maybe_auto_start() {
        if (self::is_pending()) {
            return;
        }
        $settings = SEO_Ojeador_Shopping::settings();
        if (empty($settings['auto_enabled'])) {
            return;
        }
        if (SEO_Ojeador_DB::count_due_products() < 1) {
            return;
        }
        $last = absint(get_option(self::OPTION_LAST_SYNC, 0));
        $interval = max(6, absint($settings['interval_hours'])) * HOUR_IN_SECONDS;
        if ($last < 1 || (time() - $last) >= min($interval, DAY_IN_SECONDS)) {
            self::start_run('auto');
        }
    }

    public static function process_batch($source = 'worker') {
        if (get_transient(self::LOCK)) {
            return false;
        }
        set_transient(self::LOCK, 1, 180);
        try {
            $run = SEO_Ojeador_DB::active_run();
            if (!$run) {
                return true;
            }
            $run_id = absint($run['id']);
            $now = SEO_Ojeador_DB::utc_now();
            SEO_Ojeador_DB::update_run($run_id, array(
                'status' => 'running',
                'started_at' => !empty($run['started_at']) ? (string) $run['started_at'] : $now,
                'heartbeat_at' => $now,
                'last_error' => '',
            ));

            $settings = SEO_Ojeador_Shopping::settings();
            $ids = SEO_Ojeador_DB::due_product_ids(absint($settings['batch_size']));
            if (!$ids) {
                SEO_Ojeador_DB::update_run($run_id, array(
                    'status' => 'completed',
                    'heartbeat_at' => SEO_Ojeador_DB::utc_now(),
                    'completed_at' => SEO_Ojeador_DB::utc_now(),
                ));
                update_option(self::OPTION_LAST_SYNC, time(), false);
                return true;
            }

            $processed = absint($run['processed_products'] ?? 0);
            $compared = absint($run['compared_products'] ?? 0);
            $no_match = absint($run['no_match_products'] ?? 0);
            $offers_seen = absint($run['offers_seen'] ?? 0);
            $errors = absint($run['errors_count'] ?? 0);
            $last_object_id = absint($run['last_object_id'] ?? 0);
            $last_error = '';

            foreach ($ids as $object_id) {
                $identity = SEO_Ojeador_Identity::from_product($object_id);
                $processed++;
                $last_object_id = absint($object_id);

                if (is_wp_error($identity)) {
                    $errors++;
                    $last_error = $identity->get_error_message();
                    continue;
                }

                $has_strong_identity = SEO_Ojeador_Identity::normalize_gtin($identity['gtin'] ?? '') !== ''
                    || trim((string) ($identity['mpn'] ?? '')) !== ''
                    || trim((string) ($identity['model'] ?? '')) !== '';
                if (!$has_strong_identity) {
                    SEO_Ojeador_DB::save_weak_identity($identity);
                    $no_match++;
                    continue;
                }

                $scan = SEO_Ojeador_Shopping::scan($identity);
                if (is_wp_error($scan)) {
                    SEO_Ojeador_DB::save_error($identity, $scan);
                    $errors++;
                    $last_error = $scan->get_error_message();
                    continue;
                }

                $saved = SEO_Ojeador_DB::save_scan($identity, $scan, absint($settings['interval_hours']));
                if (is_wp_error($saved)) {
                    $errors++;
                    $last_error = $saved->get_error_message();
                    continue;
                }

                $count = absint($saved['offers'] ?? 0);
                $offers_seen += $count;
                if ((string) ($saved['status'] ?? '') === 'ok' && $count > 0) {
                    $compared++;
                } else {
                    $no_match++;
                }
            }

            SEO_Ojeador_DB::update_run($run_id, array(
                'status' => 'running',
                'processed_products' => $processed,
                'compared_products' => $compared,
                'no_match_products' => $no_match,
                'offers_seen' => $offers_seen,
                'errors_count' => $errors,
                'last_object_id' => $last_object_id,
                'last_error' => $last_error,
                'heartbeat_at' => SEO_Ojeador_DB::utc_now(),
            ));
            return true;
        } finally {
            delete_transient(self::LOCK);
        }
    }
}
