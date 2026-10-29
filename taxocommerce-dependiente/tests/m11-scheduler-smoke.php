<?php
/**
 * M11 scheduler and lock regression tests without a WordPress database.
 * Run with: php tests/m11-scheduler-smoke.php
 */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['tcd_options'] = array();
$GLOBALS['tcd_events'] = array();
$GLOBALS['tcd_force_race'] = false;

function absint($v) { return abs((int) $v); }
function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['tcd_options']) ? $GLOBALS['tcd_options'][$key] : $default;
}
function add_option($key, $value, $deprecated = '', $autoload = false) {
    if (array_key_exists($key, $GLOBALS['tcd_options'])) return false;
    $GLOBALS['tcd_options'][$key] = $value;
    return true;
}
function update_option($key, $value, $autoload = false) {
    $GLOBALS['tcd_options'][$key] = $value;
    return true;
}
function delete_option($key) {
    unset($GLOBALS['tcd_options'][$key]);
    return true;
}
function wp_cache_delete($key, $group) { return true; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_generate_uuid4() {
    static $n = 0;
    return 'm11-regression-' . ++$n;
}
function current_time($type) { return gmdate('Y-m-d H:i:s'); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$value)); }
function sanitize_text_field($value) { return (string)$value; }
function wp_json_encode($value, $options = 0) { return json_encode($value, $options); }
function wp_next_scheduled($hook) { return isset($GLOBALS['tcd_events'][$hook]) ? $GLOBALS['tcd_events'][$hook] : false; }
function wp_schedule_single_event($when, $hook) { $GLOBALS['tcd_events'][$hook] = $when; return true; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['tcd_events'][$hook]); return 1; }

final class TCD_Test_WPDB {
    public $options = 'wp_options';
    public function prepare($sql, ...$args) {
        if (strpos($sql, 'DELETE FROM %i WHERE option_name=%s AND option_value=%s') === false) {
            throw new RuntimeException('Unexpected SQL statement');
        }
        return json_encode($args);
    }
    public function query($sql) {
        $args = json_decode($sql, true);
        if (!is_array($args) || $args[0] !== $this->options) return false;
        $key = $args[1];
        // Concurrent worker acquired the lock after the first worker read it.
        if ($GLOBALS['tcd_force_race']) {
            $GLOBALS['tcd_force_race'] = false;
            $GLOBALS['tcd_options'][$key] = time() . ':successor';
        }
        if ((string)get_option($key, '') !== (string)$args[2]) return 0;
        delete_option($key);
        return 1;
    }
}
$GLOBALS['wpdb'] = new TCD_Test_WPDB();

final class TCD_Load {
    public static function delay() { return 1; }
    public static function priority() { return 3; }
}
final class TCD_Original_Linguista {
    public static $replace_lock = false;
    public static function state() {
        if (self::$replace_lock) {
            self::$replace_lock = false;
            $GLOBALS['tcd_options'][TCD_Academy::LOCK] = time() . ':successor';
        }
        return array('status' => 'completed');
    }
}

require_once dirname(__DIR__) . '/includes/class-tcd-academy.php';

function tcd_check($condition, $description) {
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $description . PHP_EOL);
        exit(1);
    }
    echo 'OK: ' . $description . PHP_EOL;
}
function tcd_reset() {
    $GLOBALS['tcd_options'] = array(
        TCD_Academy::STATE => array('run_id' => 'm11-keep-progress', 'lesson' => 11, 'phase' => 'interpreter', 'status' => 'running')
    );
    $GLOBALS['tcd_events'] = array();
    $GLOBALS['tcd_force_race'] = false;
    TCD_Original_Linguista::$replace_lock = false;
}
function tcd_queue($delay) {
    $method = new ReflectionMethod('TCD_Academy', 'queue');
    $method->setAccessible(true);
    $method->invoke(null, $delay);
}

// Expired WP-Cron events must not remain in the queue for hours.
tcd_reset();
$GLOBALS['tcd_events'][TCD_Academy::CRON_HOOK] = time() - 7200;
tcd_queue(3);
tcd_check(abs(wp_next_scheduled(TCD_Academy::CRON_HOOK) - (time() + 3)) < 3, 'Repairs overdue M11 cron');

// A pending, healthy event is not duplicated or delayed.
$due = time() + 30;
$GLOBALS['tcd_events'][TCD_Academy::CRON_HOOK] = $due;
tcd_queue(3);
tcd_check(wp_next_scheduled(TCD_Academy::CRON_HOOK) === $due, 'Preserves future cron');

// Worker lock below TTL is not stolen or released.
tcd_reset();
$active = (time() - 60) . ':active';
$GLOBALS['tcd_options'][TCD_Academy::LOCK] = $active;
TCD_Academy::tick();
tcd_check(get_option(TCD_Academy::LOCK) === $active, 'Preserves active worker lock');
tcd_check(TCD_Academy::state()['lesson'] === 11, 'No concurrent lesson progression');

// Legacy timestamp locks are recovered after TTL; completed M11 moves to M12.
tcd_reset();
$GLOBALS['tcd_options'][TCD_Academy::LOCK] = time() - 400;
TCD_Academy::tick();
tcd_check(TCD_Academy::state()['lesson'] === 12, 'M11 continues from preserved state');
tcd_check(get_option(TCD_Academy::LOCK, false) === false, 'Releases own worker lock');

// A concurrent successor must never be deleted during stale recovery.
tcd_reset();
$GLOBALS['tcd_options'][TCD_Academy::LOCK] = (time() - 400) . ':previous';
$GLOBALS['tcd_force_race'] = true;
TCD_Academy::tick();
tcd_check(substr((string)get_option(TCD_Academy::LOCK), -10) === ':successor', 'Cannot steal successor lock');
tcd_check(TCD_Academy::state()['lesson'] === 11, 'Waits instead of overlapping workers');

// A worker must not release a lock transferred during its processing.
tcd_reset();
TCD_Original_Linguista::$replace_lock = true;
TCD_Academy::tick();
tcd_check(substr((string)get_option(TCD_Academy::LOCK), -10) === ':successor', 'Cannot release successor lock');

echo "M11 scheduler/lock smoke passed.\n";
