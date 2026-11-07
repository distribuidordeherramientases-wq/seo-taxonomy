<?php
/**
 * Offline SEO-head regression. Run php tests/bing-meta-head-smoke.php.
 * No WordPress bootstrap, database or external requests.
 */
define('ABSPATH', __DIR__);
$GLOBALS['tct_head'] = '';
$GLOBALS['tct_scenario'] = 'front';
$GLOBALS['tct_canonical'] = 'https://example.test/authoritative/';
function dht_template_get_canonical_url() { return $GLOBALS['tct_canonical']; }
function wp_head() { echo $GLOBALS['tct_head']; }
function is_404() { return '404' === $GLOBALS['tct_scenario']; }
function is_search() { return 'search' === $GLOBALS['tct_scenario']; }
function is_singular() { return 'product' === $GLOBALS['tct_scenario']; }
function is_category() { return 'category' === $GLOBALS['tct_scenario']; }
function is_tag() { return false; }
function is_tax() { return false; }
function is_front_page() { return 'front' === $GLOBALS['tct_scenario']; }
function is_post_type_archive() { return false; }
function get_queried_object_id() { return 17; }
function absint($value) { return abs((int) $value); }
function get_post_field($field, $id) { return 'post_excerpt' === $field ? 'Taladro profesional potente y preciso.' : ''; }
function get_the_title($id) { return 'Taladro profesional'; }
function get_bloginfo($field) { return 'description' === $field ? 'Herramientas para taller y hogar' : 'Tienda de herramientas'; }
function strip_shortcodes($text) { return $text; }
function wp_strip_all_tags($text, $remove_breaks = false) { return strip_tags($text); }
function wp_html_excerpt($text, $length, $more = '') { return substr($text, 0, $length) . $more; }
function esc_attr($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function apply_filters($hook, $value) { return $value; }
function tct_bing_assert($ok, $name) {
    if (!$ok) { fwrite(STDERR, "FAIL: " . $name . PHP_EOL); exit(1); }
}
require dirname(__DIR__) . '/templates/template-helpers.php';
function tct_bing_output() {
    ob_start();
    dht_template_render_wp_head();
    return ob_get_clean();
}
$GLOBALS['tct_head'] = '<meta name="description" content="SEO principal">'
    . "<meta content='duplicada' name='description'>"
    . '<link rel="canonical" href="https://example.test/incorrecta/">';
$out = tct_bing_output();
tct_bing_assert(1 === substr_count($out, 'name="description"'), 'Preserves only first description');
tct_bing_assert(false === strpos($out, 'duplicada'), 'Removes duplicate description');
tct_bing_assert(1 === substr_count($out, 'rel="canonical"'), 'Preserves unique canonical');
tct_bing_assert(false !== strpos($out, 'https://example.test/authoritative/'), 'Canonical remains controlled by existing router');

$GLOBALS['tct_scenario'] = 'product';
$GLOBALS['tct_head'] = '<script>window.test = 1;</script>';
$out = tct_bing_output();
tct_bing_assert(1 === substr_count($out, 'name="description"'), 'Missing product description fallback');
tct_bing_assert(false !== strpos($out, 'Taladro profesional potente'), 'Product excerpt used');
tct_bing_assert(false !== strpos($out, '<script>window.test'), 'wp_head scripts are preserved');

$GLOBALS['tct_scenario'] = 'front';
$GLOBALS['tct_head'] = '';
$out = tct_bing_output();
tct_bing_assert(false !== strpos($out, 'Herramientas para taller'), 'Homepage has contextual description');

$GLOBALS['tct_scenario'] = '404';
$out = tct_bing_output();
tct_bing_assert(false === strpos($out, 'name="description"'), '404 does not get artificial description');

$GLOBALS['tct_scenario'] = 'front';
$GLOBALS['tct_canonical'] = '';
$GLOBALS['tct_head'] = '<meta name="description" content="Otro plugin SEO">'
    . '<link rel="canonical" href="https://example.test/another/">';
$out = tct_bing_output();
tct_bing_assert(false !== strpos($out, 'https://example.test/another/'), 'Existing canonical kept on no-router pages');
tct_bing_assert(1 === substr_count($out, 'name="description"'), 'No duplicate in non-canonical pages');

$header = file_get_contents(dirname(__DIR__) . '/templates/header.php');
tct_bing_assert(false === strpos($header, "meta name=\"description\" content=\"<?php echo esc_attr(get_bloginfo('description'))"), 'Generic header meta removed');
echo "Bing SEO head regression: PASS\n";
