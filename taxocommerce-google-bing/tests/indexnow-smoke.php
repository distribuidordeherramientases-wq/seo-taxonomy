<?php
/**
 * Offline IndexNow regression: no WordPress bootstrap and no HTTP calls.
 * Run: php tests/indexnow-smoke.php
 */
define('ABSPATH', __DIR__);
$GLOBALS['tc_in_options'] = array();
$GLOBALS['tc_in_enabled'] = false;
$GLOBALS['tc_in_remote_code'] = 202;
$GLOBALS['tc_in_requests'] = array();
$GLOBALS['tc_in_scheduled'] = array();

class WP_Post {
    public $post_type = 'product';
    public $post_status = 'publish';
    public $post_password = '';
    public $ID = 123;
}
class TaxoCommerce_Connections_Google_Bing {
    public static function bing_settings() {
        return array('indexnow_enabled' => $GLOBALS['tc_in_enabled']);
    }
}
function add_action($hook, $callback, $priority = 10, $args = 1) {}
function home_url($path = '') { return 'https://shop.example.test' . $path; }
function esc_url_raw($url) { return filter_var($url, FILTER_VALIDATE_URL) ? (string)$url : ''; }
function wp_parse_url($url, $part = -1) { return parse_url($url, $part); }
function wp_generate_password($length, $special = false, $extra = false) { return str_repeat('ab', (int)($length / 2)); }
function get_option($key, $fallback = false) { return $GLOBALS['tc_in_options'][$key] ?? $fallback; }
function update_option($key, $value, $autoload = false) { $GLOBALS['tc_in_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['tc_in_options'][$key]); }
function absint($value) { return abs((int)$value); }
function wp_next_scheduled($hook) { return $GLOBALS['tc_in_scheduled'][$hook] ?? false; }
function wp_schedule_single_event($timestamp, $hook) { $GLOBALS['tc_in_scheduled'][$hook] = $timestamp; return true; }
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['tc_in_scheduled'][$hook]); }
function get_post_type_object($type) { return (object)array('public'=>true); }
function is_post_type_viewable($type) { return !empty($type->public); }
function get_permalink($post) { return home_url('/producto/prueba/'); }
function is_wp_error($thing) { return $thing instanceof Exception; }
function wp_json_encode($value) { return json_encode($value); }
function sanitize_text_field($text) { return (string)$text; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_safe_remote_post($url, $args) {
    $GLOBALS['tc_in_requests'][] = array($url, $args);
    return array('response' => array('code' => $GLOBALS['tc_in_remote_code']));
}
function tci_assert($ok, $message) {
    if (!$ok) { fwrite(STDERR, 'FAIL IndexNow: ' . $message . PHP_EOL); exit(1); }
}
require dirname(__DIR__) . '/includes/class-taxocommerce-google-bing-indexnow.php';
$service = 'TaxoCommerce_Insights_IndexNow';

tci_assert(!$service::enqueue(home_url('/producto/prueba/')), 'disabled by default');
tci_assert(!get_option($service::KEY_OPTION), 'no key generated until enabled');

$GLOBALS['tc_in_enabled'] = true;
$key = $service::key();
tci_assert(32 === strlen($key), 'per-site key generated on opt-in');
tci_assert(false !== strpos($service::key_location(), 'indexnow-' . $key . '.txt'), 'keyLocation is public root URL');
tci_assert($service::enqueue(home_url('/producto/prueba/')), 'local canonical URL enqueued');
tci_assert($service::enqueue(home_url('/producto/prueba/')), 'repeat enqueue accepted');
tci_assert(1 === $service::status()['pending'], 'duplicate URLs collapsed');
tci_assert(!$service::enqueue('https://external.example.test/otro/'), 'external host excluded');
tci_assert(!$service::enqueue('ftp://shop.example.test/file'), 'non-http excluded');

$post = new WP_Post();
$service::post_change('publish','draft',$post);
tci_assert(1 === $service::status()['pending'], 'publish post deduplicated');
$post->post_status = 'draft';
$service::post_change('draft','draft',$post);
tci_assert(1 === $service::status()['pending'], 'unpublished post ignored');
$post->post_password = 'private';
$service::post_change('publish','draft',$post);
tci_assert(1 === $service::status()['pending'], 'password-protected post ignored');

$service::send_pending();
tci_assert(0 === $service::status()['pending'], '202 success drains queue');
$payload = json_decode($GLOBALS['tc_in_requests'][0][1]['body'], true);
tci_assert('shop.example.test' === $payload['host'], 'submission host');
tci_assert($key === $payload['key'], 'submission key');
tci_assert(1 === count($payload['urlList']), 'one URL submitted');
tci_assert(false !== strpos($payload['keyLocation'], 'indexnow-'), 'verification location submitted');

$service::enqueue(home_url('/nuevo/'));
$GLOBALS['tc_in_remote_code'] = 429;
unset($GLOBALS['tc_in_scheduled'][$service::CRON_HOOK]);
$service::send_pending();
tci_assert(1 === $service::status()['pending'], '429 keeps queue');
tci_assert(!empty($GLOBALS['tc_in_scheduled'][$service::CRON_HOOK]), '429 schedules delayed retry');
$GLOBALS['tc_in_remote_code'] = 202;
unset($GLOBALS['tc_in_scheduled'][$service::CRON_HOOK]);
$service::send_pending();
tci_assert(0 === $service::status()['pending'], 'retry drains queue');
$service::disabled();
tci_assert(!$service::status()['pending'], 'disabling clears pending URL notifications');
echo "TaxoCommerce Insights IndexNow smoke: PASS\n";
