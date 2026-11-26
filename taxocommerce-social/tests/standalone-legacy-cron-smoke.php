<?php
/**
 * Regression: Social owns future legacy events without a monolith dependency.
 *
 * Run: php taxocommerce-social/tests/standalone-legacy-cron-smoke.php
 *
 * @package TaxoCommerce_Social
 */
define('ABSPATH', __DIR__ . '/');

$GLOBALS['tcsm_actions'] = array();
$GLOBALS['tcsm_filters'] = array();
$GLOBALS['tcsm_events'] = array();
$GLOBALS['tcsm_meta'] = array();
$GLOBALS['tcsm_schedules'] = 0;
$GLOBALS['tcsm_fail_schedule'] = false;
$GLOBALS['tcsm_legacy_active'] = false;
$GLOBALS['tcsm_get_post_calls'] = 0;

class WP_Error {
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function __($text, $domain = '') { return $text; }
function wp_timezone() { return new DateTimeZone('Europe/Madrid'); }
function wp_date($format, $time, $timezone = null) { return (new DateTimeImmutable('@' . (int) $time))->setTimezone($timezone ?: wp_timezone())->format($format); }
function current_time($type) { return wp_date('Y-m-d H:i:s', time(), wp_timezone()); }
function do_action($hook, ...$args) {}
function update_post_meta($id, $key, $value) { $GLOBALS['tcsm_meta'][absint($id)][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['tcsm_meta'][absint($id)][$key]); return true; }
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['tcsm_actions'][$hook][] = $callback;
}
function has_action($hook, $callback = false) {
    if ('seo_social_network_publish_manual_scheduled' === $hook) {
        return $GLOBALS['tcsm_legacy_active'];
    }
    return !empty($GLOBALS['tcsm_actions'][$hook]);
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['tcsm_filters'][$hook][] = $callback;
}
function apply_filters($hook, $value) {
    foreach ($GLOBALS['tcsm_filters'][$hook] ?? array() as $callback) {
        $value = call_user_func($callback, $value);
    }
    return $value;
}
function absint($v) { return abs((int) $v); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $v)); }
function get_option($name, $default = false) { return $default; }
function get_post_meta($id, $key, $single = false) {
    return $GLOBALS['tcsm_meta'][absint($id)][$key] ?? '';
}
function get_post($id) {
    ++$GLOBALS['tcsm_get_post_calls'];
    return null; // Never send a real post to a provider in a test.
}
function esc_sql($value) { return $value; }

function tcsm_event_key($hook, $args) {
    return $hook . ':' . implode(':', (array) $args);
}
function wp_next_scheduled($hook, $args = array()) {
    return $GLOBALS['tcsm_events'][tcsm_event_key($hook, $args)] ?? false;
}
function wp_schedule_single_event($timestamp, $hook, $args = array(), $wp_error = false) {
    ++$GLOBALS['tcsm_schedules'];
    if ($GLOBALS['tcsm_fail_schedule']) {
        return $wp_error ? new WP_Error('cron_failed', 'Mock rejected scheduling') : false;
    }
    $GLOBALS['tcsm_events'][tcsm_event_key($hook, $args)] = (int) $timestamp;
    return true;
}
function wp_clear_scheduled_hook($hook, $args = array()) {
    $key = tcsm_event_key($hook, $args);
    $existed = isset($GLOBALS['tcsm_events'][$key]);
    unset($GLOBALS['tcsm_events'][$key]);
    return $existed ? 1 : 0;
}

class TCSM_Test_DB {
    public $postmeta = 'wp_postmeta';
    public $options = 'wp_options';
    public $last_error = '';
    public $reservations = array();
    public $history = array();
    public $prepared_args = array();
    public $fail_history = false;
    public $fail_reservation = false;
    public $job = null;
    public $job_update = array();
    public $posts = 'wp_posts';
    public $prefix = 'wp_';
    public $rows = array();
    public $last_publication = null;
    public function esc_like($value) { return $value; }
    public function prepare($query, ...$args) { $this->prepared_args = $args; return $query; }
    public function get_var($sql) {
        $this->last_error = $this->fail_history ? 'Mock unavailable history' : '';
        if ($this->fail_history) return null;
        foreach ($this->history as $row) {
            if ($row['provider'] !== $this->prepared_args[1]) continue;
            foreach (array('created_at', 'published_at') as $column) {
                $date = $row[$column] ?? '';
                if ($date >= $this->prepared_args[2] && $date < $this->prepared_args[3]) return 1;
            }
        }
        return null;
    }
    public function query($sql) {
        if (false === strpos($sql, 'INSERT IGNORE INTO')) throw new RuntimeException('Reservation must use a unique atomic insert.');
        $this->last_error = $this->fail_reservation ? 'Mock reservation failure' : '';
        if ($this->fail_reservation) return false;
        $key = $this->prepared_args[1];
        if (isset($this->reservations[$key])) return 0;
        $this->reservations[$key] = true;
        return 1;
    }
    public function update($table, $data, $where, $format = array(), $where_format = array()) { $this->job_update = array_merge($this->job_update, $data); return 1; }
    public function get_results($sql) {
        if (false === strpos($sql, 'CAST(pm.meta_value AS UNSIGNED) > %d')) {
            throw new RuntimeException('The fixture expects a future-only SQL query.');
        }
        // Simulate WordPress' future-only SQL predicate, not just its row store.
        return array_values(array_filter($this->rows, static function($row) {
            return absint($row->meta_value) > time() + 30;
        }));
    }
    public function get_row($sql) {
        return false !== strpos($sql, 'WHERE id = %d LIMIT 1') ? $this->job : $this->last_publication;
    }
}

function tcsm_assert($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}
function tcsm_reset() {
    global $wpdb;
    $wpdb->rows = array();
    $wpdb->last_publication = null;
    $wpdb->last_error = '';
    $wpdb->reservations = array();
    $wpdb->history = array();
    $wpdb->fail_history = false;
    $wpdb->fail_reservation = false;
    $wpdb->job = null;
    $wpdb->job_update = array();
    $GLOBALS['tcsm_events'] = array();
    $GLOBALS['tcsm_meta'] = array();
    $GLOBALS['tcsm_schedules'] = 0;
    $GLOBALS['tcsm_fail_schedule'] = false;
    $GLOBALS['tcsm_legacy_active'] = false;
    $GLOBALS['tcsm_get_post_calls'] = 0;
}
function tcsm_schedule_row($id, $provider, $timestamp) {
    global $wpdb;
    $wpdb->rows[] = (object) array(
        'post_id' => $id,
        'meta_key' => '_seo_social_schedule_' . $provider,
        'meta_value' => (string) $timestamp,
    );
    $GLOBALS['tcsm_meta'][$id]['_seo_social_schedule_' . $provider] = (string) $timestamp;
}

$wpdb = new TCSM_Test_DB();
require_once dirname(__DIR__) . '/includes/core.php';
add_filter('tcsocial_network_providers', static function($providers) {
    $providers['facebook'] = array('label' => 'Facebook', 'publish_callback' => 'unreachable');
    $providers['linkedin'] = array('label' => 'LinkedIn', 'publish_callback' => 'unreachable');
    return $providers;
});
tcsm_assert(has_action('tcsocial_network_publish_manual_scheduled'), 'Own cron handler registered without monolith.');
tcsm_assert(has_action('init'), 'Own reconciliation registered without monolith.');

$future = time() + 7200;
$old_hook = 'seo_social_network_publish_manual_scheduled';
$own_hook = 'tcsocial_network_publish_manual_scheduled';

tcsm_reset();
tcsm_schedule_row(101, 'facebook', $future);
$GLOBALS['tcsm_events'][tcsm_event_key($old_hook, array(101, 'facebook'))] = $future;
tcsocial_network_reconcile_future_events();
tcsm_assert($future === wp_next_scheduled($own_hook, array(101, 'facebook')), 'Orphan future legacy job adopted by Social.');
tcsm_assert(false === wp_next_scheduled($old_hook, array(101, 'facebook')), 'Old job removed after successful takeover.');
tcsm_assert('' !== get_post_meta(101, '_seo_social_schedule_facebook', true), 'Shared schedule meta preserved.');
tcsocial_network_reconcile_future_events();
tcsm_assert(1 === $GLOBALS['tcsm_schedules'], 'Repeated reconciliation never duplicates a job.');

tcsm_reset();
tcsm_schedule_row(102, 'linkedin', $future);
$GLOBALS['tcsm_events'][tcsm_event_key($old_hook, array(102, 'linkedin'))] = $future;
$GLOBALS['tcsm_fail_schedule'] = true;
tcsocial_network_reconcile_future_events();
tcsm_assert(false === wp_next_scheduled($own_hook, array(102, 'linkedin')), 'Failed takeover does not claim success.');
tcsm_assert($future === wp_next_scheduled($old_hook, array(102, 'linkedin')), 'Failed takeover preserves the old event.');

tcsm_reset();
tcsm_schedule_row(103, 'facebook', $future);
$GLOBALS['tcsm_events'][tcsm_event_key($old_hook, array(103, 'facebook'))] = $future;
$GLOBALS['tcsm_legacy_active'] = true;
tcsocial_network_reconcile_future_events();
tcsm_assert(false === wp_next_scheduled($own_hook, array(103, 'facebook')), 'Coexisting active legacy hook never duplicated.');
tcsm_assert($future === wp_next_scheduled($old_hook, array(103, 'facebook')), 'Coexisting legacy job preserved.');

tcsm_reset();
tcsm_schedule_row(104, 'facebook', $future);
$GLOBALS['tcsm_events'][tcsm_event_key($old_hook, array(104, 'facebook'))] = $future;
$GLOBALS['tcsm_meta'][104]['_tcsocial_schedule_state_facebook'] = 'pausada';
tcsocial_network_reconcile_future_events();
tcsm_assert(false === wp_next_scheduled($own_hook, array(104, 'facebook')), 'Paused job never adopted.');

tcsm_reset();
tcsm_schedule_row(105, 'facebook', $future);
$wpdb->last_publication = (object) array('status' => 'pending');
tcsocial_network_reconcile_future_events();
tcsm_assert(0 === $GLOBALS['tcsm_schedules'], 'Pending remote request never re-sent.');
$wpdb->last_publication = (object) array('status' => 'published');
tcsocial_network_reconcile_future_events();
tcsm_assert(0 === $GLOBALS['tcsm_schedules'], 'Already published remote request never re-sent.');

tcsm_reset();
tcsm_schedule_row(106, 'facebook', time() - 7200);
tcsocial_network_reconcile_future_events();
tcsm_assert(0 === $GLOBALS['tcsm_schedules'], 'Expired tasks never automatically replayed.');

tcsm_reset();
tcsocial_network_publish_manual_scheduled(107, 'facebook');
tcsm_assert(0 === $GLOBALS['tcsm_get_post_calls'], 'Cancelled job cannot publish without schedule meta.');
tcsm_schedule_row(108, 'facebook', time() + 7200);
tcsocial_network_publish_manual_scheduled(108, 'facebook');
tcsm_assert(0 === $GLOBALS['tcsm_get_post_calls'], 'Stale cron cannot publish an event rescheduled into the future.');
$GLOBALS['tcsm_meta'][108]['_tcsocial_schedule_state_facebook'] = 'pausada';
tcsocial_network_publish_manual_scheduled(108, 'facebook');
tcsm_assert(0 === $GLOBALS['tcsm_get_post_calls'], 'Paused cron cannot publish.');
unset($GLOBALS['tcsm_meta'][108]['_tcsocial_schedule_state_facebook']);
$GLOBALS['tcsm_meta'][108]['_seo_social_schedule_facebook'] = (string) time();
tcsocial_network_publish_manual_scheduled(108, 'facebook');
tcsm_assert(1 === $GLOBALS['tcsm_get_post_calls'], 'Valid due cron reaches publication function (test post safely rejected).');

// An entire overdue backlog must be paused, not sent during a single cron recovery.
tcsm_reset();
for ($id = 200; $id < 215; ++$id) {
    tcsm_schedule_row($id, 'facebook', time() - 2 * 86400);
    tcsocial_network_publish_manual_scheduled($id, 'facebook');
    tcsm_assert('pausada' === tcsocial_network_get_schedule_state($id, 'facebook'), 'Old schedule remains editable and paused.');
    tcsm_assert(tcsocial_network_get_scheduled_timestamp($id, 'facebook') > 0, 'Expired schedule retained.');
}
tcsm_assert(0 === $GLOBALS['tcsm_get_post_calls'], 'Fifteen overdue entries make no publication attempt.');
tcsocial_network_publish_scheduled(999, 'facebook');
tcsm_assert(0 === $GLOBALS['tcsm_get_post_calls'], 'Legacy direct event without schedule never publishes.');

tcsm_reset();
tcsm_schedule_row(220, 'facebook', time());
$wpdb->last_publication = (object) array('status' => 'pending');
tcsocial_network_publish_manual_scheduled(220, 'facebook');
tcsm_assert('pausada' === tcsocial_network_get_schedule_state(220, 'facebook'), 'Unconfirmed delivery is not retried.');
tcsm_assert(0 === $GLOBALS['tcsm_get_post_calls'], 'No call for unconfirmed delivery.');

tcsm_reset();
tcsm_schedule_row(221, 'facebook', time());
$wpdb->last_publication = (object) array('status' => 'published', 'published_at' => wp_date('Y-m-d H:i:s', time() + 30, wp_timezone()));
tcsocial_network_publish_manual_scheduled(221, 'facebook');
tcsm_assert('pausada' === tcsocial_network_get_schedule_state(221, 'facebook'), 'Confirmed delivery is not duplicated.');
tcsm_assert(0 === $GLOBALS['tcsm_get_post_calls'], 'No duplicate provider call.');

tcsm_reset();
tcsm_assert(true === tcsocial_network_reserve_daily_delivery('facebook'), 'First daily reservation is allowed.');
$duplicate = tcsocial_network_reserve_daily_delivery('facebook');
tcsm_assert(is_wp_error($duplicate) && 'social_daily_limit' === $duplicate->get_error_code(), 'Concurrent/later reservation on same network is blocked.');
tcsm_assert(true === tcsocial_network_reserve_daily_delivery('linkedin'), 'Another network has an independent daily quota.');
$wpdb->history[] = array('provider' => 'pinterest', 'created_at' => current_time('mysql'), 'published_at' => '');
$already = tcsocial_network_reserve_daily_delivery('pinterest');
tcsm_assert(is_wp_error($already) && 'social_daily_limit' === $already->get_error_code(), 'Historical attempt from today blocks a new request after upgrade.');
$wpdb->fail_history = true;
$unavailable = tcsocial_network_reserve_daily_delivery('x');
tcsm_assert(is_wp_error($unavailable) && 'social_daily_guard_failed' === $unavailable->get_error_code(), 'Unavailable history fails closed.');
$wpdb->fail_history = false;
$wpdb->fail_reservation = true;
$unavailable = tcsocial_network_reserve_daily_delivery('x');
tcsm_assert(is_wp_error($unavailable) && 'social_daily_guard_failed' === $unavailable->get_error_code(), 'Unavailable atomic reservation fails closed.');

tcsm_reset();
require_once dirname(__DIR__) . '/includes/programador.php';
$wpdb->job = (object) array('id' => 1, 'status' => 'scheduled', 'content_id' => 230, 'provider' => 'facebook', 'scheduled_at' => wp_date('Y-m-d H:i:s', time() - 2 * 86400, wp_timezone()), 'template' => '');
tcsocial_programador_run_job(1);
tcsm_assert('failed' === ($wpdb->job_update['status'] ?? ''), 'Overdue independent scheduler is preserved as a retryable failed task.');
tcsm_assert(0 === $GLOBALS['tcsm_get_post_calls'], 'Independent scheduler never publishes old days.');

echo "OK: independent Social owner, legacy takeover, daily reservation, previous-day backlog, no duplicate or uncertain sends.\n";
