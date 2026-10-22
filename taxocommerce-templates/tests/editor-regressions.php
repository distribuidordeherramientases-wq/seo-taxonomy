<?php
// Real editor save path, filesystem and backups; only WordPress services are stubbed.
function current_user_can($capability) { return empty($GLOBALS['deny_edit']); }
function wp_is_file_mod_allowed($context) { return true; }
function check_admin_referer($action) {}
function sanitize_file_name($name) { return basename($name); }
function wp_unslash($value) { return $value; }
function get_current_user_id() { return 1; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
function wp_mkdir_p($path) { return mkdir($path, 0700, true); }
function wp_generate_password($length, $special = true, $extra = false) { return substr(uniqid(), -$length); }
function admin_url($path) { return '/admin/' . $path; }
function add_query_arg($key, $value, $url = '') {
    if (is_array($key)) { return $value . '?' . http_build_query($key); }
    return $url . '&' . urlencode($key) . '=' . urlencode($value);
}
function wp_safe_redirect($url) { throw new TCT_Test_Redirect($url); }
function wp_die($message) { throw new RuntimeException($message); }
class TCT_Test_Redirect extends RuntimeException {}
define('MINUTE_IN_SECONDS', 60);
require_once TCT_DIR . 'includes/class-tct-request-admin.php';
function tct_test_save($fields) {
    $_POST = $fields;
    try { TCT_Template_Editor::save(); }
    catch (TCT_Test_Redirect $redirect) { return $redirect->getMessage(); }
    throw new Exception('Expected redirect');
}
$first = '<?php echo "first";';
$second = '<?php echo "second";';
tct_test_save(array('filename'=>'edited.php', 'code'=>$first));
tct_assert(file_get_contents($dir . 'edited.php') === $first, 'Pasted PHP persisted');
tct_test_save(array('filename'=>'edited.php', 'code'=>$second));
tct_assert(file_get_contents($dir . 'edited.php') === $first, 'No overwrite without confirmation');
tct_test_save(array('filename'=>'edited.php', 'code'=>$second, 'confirm_replace'=>1));
tct_assert(file_get_contents($dir . 'edited.php') === $second, 'Confirmed edit persisted');
$backups = glob($dir . 'edited.php.*.bak');
tct_assert(count($backups) === 1 && file_get_contents($backups[0]) === $first, 'Original backed up');
tct_test_save(array('filename'=>'edited.php', 'code'=>'<?php function broken( {', 'confirm_replace'=>1));
tct_assert(file_get_contents($dir . 'edited.php') === $second, 'Invalid PHP preserves previous file');
tct_assert(isset($GLOBALS['transients']['tct_editor_pending_1']), 'Invalid draft retained');
$bundled = file_get_contents(TCT_DIR . 'templates/template-default.php');
tct_test_save(array('filename'=>'template-default.php', 'code'=>$first, 'confirm_replace'=>1));
tct_assert(file_get_contents(TCT_DIR . 'templates/template-default.php') === $bundled, 'Bundled template preserved');
tct_assert(!file_exists($dir . 'template-default.php'), 'Bundled template not shadowed');
$GLOBALS['deny_edit'] = true;
try {
    tct_test_save(array('filename'=>'forbidden.php','code'=>$first));
} catch (RuntimeException $error) {}
tct_assert(!file_exists($dir . 'forbidden.php'), 'Permissions required for writing PHP');
$GLOBALS['deny_edit'] = false;
