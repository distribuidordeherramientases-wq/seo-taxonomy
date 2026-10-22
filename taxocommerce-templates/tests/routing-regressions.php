<?php
// Loaded by smoke.php; fake database deliberately returns rows out of priority order.
class TCT_Test_DB {
    public $rows = array();
    public $role = '';
    public function prepare($sql, ...$args) { return array($sql, $args); }
    public function get_var($query) { return $this->role; }
    public function get_results($query) { return array_values($this->rows); }
    public function get_row($query) {
        $key = $query[1][0];
        return isset($this->rows[$key]) ? $this->rows[$key] : null;
    }
}
function tct_test_row($key, $file, $active = 1, $variants = 0) {
    return (object)array('template_key'=>$key, 'template_file'=>$file,
        'is_active'=>$active, 'device_variants_enabled'=>$variants);
}
$wpdb = new TCT_Test_DB();
$GLOBALS['installed'] = true;
$GLOBALS['options'][TaxoCommerce_Templates::ENABLE_OPTION] = 1;
$dir = TCT_Router::library_dir();
mkdir($dir, 0700, true);
register_shutdown_function(function () use ($dir) {
    foreach (glob($dir . '*') as $file) { unlink($file); }
    if (is_file($dir . '.htaccess')) { unlink($dir . '.htaccess'); }
    rmdir($dir);
    rmdir(WP_CONTENT_DIR);
});
foreach (array('assigned.php','role.php','assigned-mobile.php') as $file) {
    file_put_contents($dir . $file, '<?php echo "fixture";');
}
$routes = array('page'=>'single_type_page', 'post'=>'single_post',
    'product'=>'single_product', 'category'=>'taxonomy_category',
    'product_cat'=>'taxonomy_product_cat', 'product_tag'=>'taxonomy_product_tag',
    'front'=>'front_page', 'home'=>'blog_index', 'cart'=>'cart',
    'checkout'=>'checkout', 'thankyou'=>'thankyou', 'shop'=>'shop',
    'account'=>'myaccount', 'search'=>'search', 'not_found'=>'error_404');
foreach ($routes as $scenario=>$key) {
    $GLOBALS['scenario'] = $scenario;
    $wpdb->rows = array($key=>tct_test_row($key, 'assigned.php'));
    tct_assert(TCT_Request_Registry::resolve('/theme/original.php') === $dir . 'assigned.php', 'Enabled route: ' . $scenario);
    $wpdb->rows[$key]->is_active = 0;
    tct_assert(TCT_Request_Registry::resolve('/theme/original.php') === '/theme/original.php', 'Inactive route: ' . $scenario);
    $wpdb->rows[$key] = tct_test_row($key, 'missing.php');
    tct_assert(TCT_Request_Registry::resolve('/theme/original.php') === '/theme/original.php', 'Missing file: ' . $scenario);
    $wpdb->rows = array();
    tct_assert(TCT_Request_Registry::resolve('/theme/original.php') === '/theme/original.php', 'Unassigned route: ' . $scenario);
}
foreach (array('page','post','product','category','product_cat') as $scenario) {
    $GLOBALS['scenario'] = $scenario;
    $wpdb->role = 'special_role';
    $wpdb->rows = array('index'=>tct_test_row('index','assigned.php'),
        'special_role'=>tct_test_row('special_role','role.php'));
    tct_assert(TCT_Request_Registry::resolve('/theme/original.php') === $dir . 'role.php', 'Role before generic: ' . $scenario);
}
$wpdb->role = '';
$GLOBALS['scenario'] = 'dependiente';
tct_assert(TCT_Request_Registry::resolve('/theme/original.php') === '/theme/original.php', 'Dependiente excluded');
$GLOBALS['scenario'] = 'product';
foreach (array('template-pilot-vevor.php','template-category-fr.php','template-product-fr.php') as $file) {
    tct_assert(TCT_Request_Registry::resolve('/legacy/' . $file) === '/legacy/' . $file, 'Pilot preserved');
}
$row = tct_test_row('product','assigned.php',1,1);
tct_assert(TCT_Router::plan($row,'mobile')['effective'] === $dir . 'assigned-mobile.php', 'Mobile variant');
tct_assert(TCT_Router::plan($row,'desktop')['effective'] === $dir . 'assigned.php', 'Missing desktop uses primary');
$row->device_variants_enabled = 0;
tct_assert(TCT_Router::plan($row,'mobile')['effective'] === $dir . 'assigned.php', 'Variants disabled');
file_put_contents($dir . 'assigned.php', "<?php // dht_template_device_variant_file(\necho 'complete';");
tct_assert(!TCT_Router::is_dispatcher($dir . 'assigned.php'), 'Comment is not dispatcher');
file_put_contents($dir . 'assigned.php', "<?php require_once (dht_template_device_variant_file ('test'));");
tct_assert(TCT_Router::is_dispatcher($dir . 'assigned.php'), 'Whitespace and parentheses dispatcher');
tct_assert(TCT_Router::plan($row,'desktop')['effective'] === $dir . 'assigned-mobile.php', 'Dispatcher opposite fallback');
unlink($dir . 'assigned-mobile.php');
tct_assert(TCT_Router::plan($row,'mobile')['effective'] === '', 'Incomplete dispatcher not executed');
file_put_contents($dir . 'assigned.php', '<?php echo "fixture";');
foreach (array('single-product/add-to-cart/variable.php', 'single-product/add-to-cart/variation.php', 'content-product.php') as $file) {
    $key = TCT_Request_Registry::woocommerce_key($file);
    $wpdb->rows = array($key=>tct_test_row($key,'assigned.php'));
    tct_assert(TCT_Request_Registry::resolve_woocommerce_partial('/woo/original.php',$file) === $dir . 'assigned.php', 'Woo fragment: ' . $file);
    $wpdb->rows = array();
    tct_assert(TCT_Request_Registry::resolve_woocommerce_partial('/woo/original.php',$file) === '/woo/original.php', 'Woo default: ' . $file);
}
$key = TCT_Request_Registry::woocommerce_key('content-product.php');
$wpdb->rows = array($key=>tct_test_row($key,'assigned.php'));
tct_assert(TCT_Request_Registry::resolve_woocommerce_part('/woo/original.php','content','product') === $dir . 'assigned.php', 'Woo template part');
$wpdb->rows = array('comments'=>tct_test_row('comments','assigned.php'));
tct_assert(TCT_Request_Registry::resolve_comments('/theme/comments.php') === $dir . 'assigned.php', 'Comments');
$GLOBALS['options'][TaxoCommerce_Templates::ENABLE_OPTION] = 0;
tct_assert(TCT_Request_Registry::resolve_comments('/theme/comments.php') === '/theme/comments.php', 'Comments disabled');
tct_assert(TCT_Request_Registry::resolve_woocommerce_part('/woo/original.php','content','product') === '/woo/original.php', 'Woo disabled');
