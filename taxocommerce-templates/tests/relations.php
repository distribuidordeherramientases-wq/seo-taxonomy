<?php
// Exercise the real role setter: both historic landing encodings are protected.
define('ABSPATH', __DIR__ . '/');
function plugin_dir_path($file) { return dirname($file) . '/'; }
function plugin_dir_url($file) { return ''; }
function register_activation_hook($file, $callback) {}
function add_action($name, $callback, $priority = 10) {}
function absint($value) { return abs((int)$value); }
function sanitize_key($value) { return $value; }
function get_post_type($id) { return 'page'; }
class WP_Error {
    public $code;
    public function __construct($code, $message) { $this->code = $code; }
}
class TCT_Relation_DB {
    public $prefix = 'test_';
    public $type = 'landing';
    public function prepare($query, ...$args) { return array($query,$args); }
    public function esc_like($value) { return $value; }
    public function get_var($query) {
        if (strpos($query[0], 'SELECT seo_role') === 0) { return 'landing'; }
        if (strpos($query[0], 'SHOW TABLES') === 0) { return 'test_seo_relations'; }
        if (strpos($query[0], 'SELECT COUNT') === 0) {
            return strpos($query[0], "'" . $this->type . "'") !== false ? 1 : 0;
        }
        throw new Exception('Unexpected query');
    }
    public function __call($method, $args) { throw new Exception('Unexpected write: ' . $method); }
}
require dirname(__DIR__) . '/taxocommerce-templates.php';
$wpdb = new TCT_Relation_DB();
foreach (array('landing', 'landing_page') as $type) {
    $wpdb->type = $type;
    $result = TaxoCommerce_Templates::set_role(42, '');
    if (!($result instanceof WP_Error) || $result->code !== 'tct_hierarchy_linked') {
        throw new Exception('Linked role must remain intact: ' . $type);
    }
}
echo "OK: Linked landing relations preserved without writes\n";
