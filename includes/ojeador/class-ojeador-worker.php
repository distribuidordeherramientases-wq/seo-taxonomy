<?php
/**
 * Category-first Google Shopping worker.
 *
 * One WooCommerce category produces one Google Shopping query. Every result
 * returned by the API is stored; batch size only controls queries per pulse.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.6.0
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
        if (SEO_Ojeador_DB::count_due_categories() < 1) {
            return;
        }
        if (is_wp_error(SEO_Ojeador_Shopping::readiness())) {
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
            $ready = SEO_Ojeador_Shopping::readiness();
            if (is_wp_error($ready)) {
                SEO_Ojeador_DB::update_run($run_id, array(
                    'status' => 'completed',
                    'heartbeat_at' => SEO_Ojeador_DB::utc_now(),
                    'completed_at' => SEO_Ojeador_DB::utc_now(),
                    'last_error' => $ready->get_error_message(),
                ));
                return true;
            }

            $ids = SEO_Ojeador_DB::due_category_ids(absint($settings['batch_size']));
            if (!$ids) {
                SEO_Ojeador_DB::update_run($run_id, array(
                    'status' => 'completed',
                    'heartbeat_at' => SEO_Ojeador_DB::utc_now(),
                    'completed_at' => SEO_Ojeador_DB::utc_now(),
                ));
                update_option(self::OPTION_LAST_SYNC, time(), false);
                return true;
            }

            $processed = absint($run['processed_categories'] ?? 0);
            $with_results = absint($run['categories_with_results'] ?? 0);
            $without_results = absint($run['categories_without_results'] ?? 0);
            $results_seen = absint($run['results_seen'] ?? 0);
            $api_queries = absint($run['api_queries'] ?? 0);
            $errors = absint($run['errors_count'] ?? 0);
            $last_term_id = absint($run['last_term_id'] ?? 0);
            $last_error = '';

            foreach ($ids as $term_id) {
                // Stop exactly at the configured monthly ceiling.
                $ready = SEO_Ojeador_Shopping::readiness();
                if (is_wp_error($ready)) {
                    $last_error = $ready->get_error_message();
                    break;
                }

                $context = SEO_Ojeador_DB::category_context($term_id);
                if (is_wp_error($context)) {
                    $errors++;
                    $last_error = $context->get_error_message();
                    continue;
                }

                $processed++;
                $last_term_id = absint($term_id);
                $scan = SEO_Ojeador_Shopping::scan_category($context);

                if (is_wp_error($scan)) {
                    // At this point readiness/context already passed. Most errors
                    // therefore come from an attempted remote request.
                    $api_queries++;
                    SEO_Ojeador_DB::save_category_error($term_id, $scan, SEO_Ojeador_Shopping::build_category_query($context));
                    $errors++;
                    $last_error = $scan->get_error_message();
                    continue;
                }

                $api_queries += absint($scan['api_queries'] ?? 1);
                $saved = SEO_Ojeador_DB::save_category_scan($term_id, $scan, absint($settings['interval_hours']));
                if (is_wp_error($saved)) {
                    $errors++;
                    $last_error = $saved->get_error_message();
                    continue;
                }

                $count = absint($saved['results'] ?? 0);
                $results_seen += $count;
                if ($count > 0) {
                    $with_results++;
                } else {
                    $without_results++;
                }
            }

            $usage = SEO_Ojeador_Shopping::usage_month();
            $status = ($usage['limit'] > 0 && $usage['used'] >= $usage['limit']) ? 'completed' : 'running';
            SEO_Ojeador_DB::update_run($run_id, array(
                'status' => $status,
                'processed_categories' => $processed,
                'categories_with_results' => $with_results,
                'categories_without_results' => $without_results,
                'results_seen' => $results_seen,
                'api_queries' => $api_queries,
                'errors_count' => $errors,
                'last_term_id' => $last_term_id,
                'last_error' => $last_error,
                'heartbeat_at' => SEO_Ojeador_DB::utc_now(),
                'completed_at' => $status === 'completed' ? SEO_Ojeador_DB::utc_now() : null,
            ));
            return true;
        } finally {
            delete_transient(self::LOCK);
        }
    }
}
