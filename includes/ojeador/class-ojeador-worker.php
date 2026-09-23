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
            $halt_on_exception = false;

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
                try {
                    $scan = SEO_Ojeador_Shopping::scan_category($context, $run_id);
                } catch (\Throwable $e) {
                    $errors++;
                    $halt_on_exception = true;
                    $last_error = sprintf(
                        'Excepción interna al procesar categoría %d: %s',
                        absint($term_id),
                        sanitize_text_field($e->getMessage())
                    );

                    SEO_Ojeador_DB::create_query_log(array(
                        'run_id' => $run_id,
                        'context_type' => 'category',
                        'term_id' => absint($term_id),
                        'category_name' => (string) ($context['name'] ?? ''),
                        'query_text' => SEO_Ojeador_Shopping::build_category_query($context),
                        'engine' => 'google_shopping',
                        'event_status' => 'parse_error',
                        'request_attempted' => 0,
                        'error_code' => 'ojeador_worker_exception',
                        'error_message' => $last_error,
                        'metadata' => array(
                            'exception_class' => get_class($e),
                            'file' => basename((string) $e->getFile()),
                            'line' => absint($e->getLine()),
                        ),
                        'completed_at' => SEO_Ojeador_DB::utc_now(),
                    ));

                    SEO_Ojeador_DB::save_category_error(
                        $term_id,
                        new WP_Error('ojeador_worker_exception', $last_error),
                        SEO_Ojeador_Shopping::build_category_query($context)
                    );
                    break;
                }

                if (is_wp_error($scan)) {
                    $error_data = $scan->get_error_data();
                    $error_data = is_array($error_data) ? $error_data : array();
                    $api_queries += absint($error_data['ojeador_api_queries'] ?? 0);
                    $log_id = absint($error_data['ojeador_query_log_id'] ?? 0);

                    $saved_error = SEO_Ojeador_DB::save_category_error(
                        $term_id,
                        $scan,
                        SEO_Ojeador_Shopping::build_category_query($context)
                    );
                    if (is_wp_error($saved_error)) {
                        $last_error = $saved_error->get_error_message();
                        if ($log_id > 0) {
                            SEO_Ojeador_DB::update_query_log($log_id, array(
                                'event_status' => 'db_error',
                                'error_code' => $saved_error->get_error_code(),
                                'error_message' => $saved_error->get_error_message(),
                                'completed_at' => SEO_Ojeador_DB::utc_now(),
                            ));
                        }
                    } else {
                        $last_error = $scan->get_error_message();
                    }
                    $errors++;
                    continue;
                }

                $api_queries += absint($scan['api_queries'] ?? 0);
                $log_id = absint($scan['query_log_id'] ?? 0);
                $saved = SEO_Ojeador_DB::save_category_scan($term_id, $scan, absint($settings['interval_hours']));
                if (is_wp_error($saved)) {
                    $errors++;
                    $last_error = $saved->get_error_message();
                    if ($log_id > 0) {
                        SEO_Ojeador_DB::update_query_log($log_id, array(
                            'event_status' => 'db_error',
                            'error_code' => $saved->get_error_code(),
                            'error_message' => $saved->get_error_message(),
                            'saved_result_count' => 0,
                            'completed_at' => SEO_Ojeador_DB::utc_now(),
                        ));
                    }
                    continue;
                }

                $count = absint($saved['results'] ?? 0);
                if ($log_id > 0) {
                    SEO_Ojeador_DB::update_query_log($log_id, array(
                        'event_status' => !empty($scan['reused']) ? 'reused' : (string) ($saved['status'] ?? ($count > 0 ? 'ok' : 'no_results')),
                        'saved_result_count' => $count,
                        'error_message' => (string) ($saved['last_save_error'] ?? ''),
                        'completed_at' => SEO_Ojeador_DB::utc_now(),
                    ));
                }
                $results_seen += $count;
                if ($count > 0) {
                    $with_results++;
                } else {
                    $without_results++;
                }
            }

            $usage = SEO_Ojeador_Shopping::usage_month();
            $status = $halt_on_exception
                ? 'stopped'
                : (($usage['limit'] > 0 && $usage['used'] >= $usage['limit']) ? 'completed' : 'running');
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
