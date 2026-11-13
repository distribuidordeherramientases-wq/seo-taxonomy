<?php
/**
 * Offline regression of native Core Code Check, including manual WordPress.org
 * findings from November 2026. Included by autocheck-json-smoke.php (defines
 * check() and loads the installed Core inspector).
 *
 * @package TaxoCommerceCore
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$tc_code_root = dirname( __DIR__, 3 ) . '/taxocommerce-core';
$tc_rules = static function ( $relative, $group ) use ( $tc_code_root ) {
	$report = TaxoCommerce_Core_Autotest_Code::analyze(
		$relative
	);
	return array_column( $report[ $group ]['evidence'], 'rule' );
};

$tc_core_php = $tc_rules( 'includes/class-taxocommerce-core.php', 'wordpress' );
check( in_array( 'review_plugin_installation', $tc_core_php, true ), 'Core ZIP installation is flagged for review' );
check( in_array( 'review_plugin_lifecycle_activate_plugin', $tc_core_php, true ), 'Core activate_plugin() is reported' );
check( in_array( 'review_plugin_lifecycle_deactivate_plugins', $tc_core_php, true ), 'Core deactivate_plugins() is reported' );

$tc_core_security = $tc_rules( 'includes/class-taxocommerce-core.php', 'security' );
check( in_array( 'review_file_upload', $tc_core_security, true ), 'WordPress.org upload input concern is reported' );
check( in_array( 'review_upload_tmp_name', $tc_core_security, true ), 'WordPress.org temporary upload path concern is reported' );
check( in_array( 'review_dynamic_inline_style', $tc_core_security, true ), 'WordPress.org dynamic inline CSS concern is reported' );

$tc_hierarchy = $tc_rules( 'includes/class-taxocommerce-core-hierarchy.php', 'security' );
check( in_array( 'review_request_nonce', $tc_hierarchy, true ), 'Hierarchy country_request() POST requires contextual nonce review' );

$tc_hooks = $tc_rules( 'includes/class-taxocommerce-core-anomalies.php', 'wordpress' );
check( in_array( 'review_nonprefixed_hook', $tc_hooks, true ), 'Three unprefixed seo_reports_* hooks are found' );

$tc_buffer = $tc_rules( 'includes/class-taxocommerce-core-anomalies-full-json.php', 'wordpress' );
check( ! in_array( 'review_unclosed_output_buffer', $tc_buffer, true ), 'Balanced ob_start in try/finally is not a confirmed leak' );

$tc_root = realpath( $tc_code_root );
$tc_analyze = static function ( $source, $group ) use ( $tc_root ) {
	$report = TaxoCommerce_Core_Autotest_Code::analyze_source( $source, 'includes/fixture.php', $tc_root );
	return array_column( $report[ $group ]['evidence'], 'rule' );
};

$tc_unclosed = $tc_analyze( "<?php defined('ABSPATH') || exit; function taxocommerce_core_unclosed() { ob_start(); echo 'hello'; }", 'wordpress' );
check( in_array( 'review_unclosed_output_buffer', $tc_unclosed, true ), 'Unclosed output buffer is detected inside its function' );

$tc_balanced = $tc_analyze( "<?php defined('ABSPATH') || exit; function taxocommerce_core_balanced() { ob_start(); try { echo 'hello'; } finally { ob_end_clean(); } }", 'wordpress' );
check( ! in_array( 'review_unclosed_output_buffer', $tc_balanced, true ), 'try/finally closes its output buffer' );

$tc_write = $tc_analyze( <<<'PHP'
<?php
defined('ABSPATH') || exit;
function taxocommerce_core_one() {
    $data = $_POST['data'];
    return $data;
}
function taxocommerce_core_other() {
    check_admin_referer('action');
    current_user_can('manage_options');
    return sanitize_text_field('x');
}
PHP
, 'security' );
check( in_array( 'review_request_nonce', $tc_write, true ), 'Nonce in another function cannot authorize this POST' );
check( in_array( 'review_request_capability', $tc_write, true ), 'Capability in another function cannot authorize this POST' );
check( in_array( 'review_request_sanitization', $tc_write, true ), 'Sanitization in another function cannot sanitize this POST' );

$tc_protected = $tc_analyze( <<<'PHP'
<?php
defined('ABSPATH') || exit;
function taxocommerce_core_verified() {
    if (!current_user_can('manage_options')) { return; }
    check_admin_referer('action');
    $data = sanitize_text_field(wp_unslash($_POST['data']));
    return $data;
}
PHP
, 'security' );
check( ! in_array( 'review_request_nonce', $tc_protected, true ), 'Same-handler nonce does not raise a false positive' );
check( ! in_array( 'review_request_capability', $tc_protected, true ), 'Same-handler permission does not raise a false positive' );

$tc_sql = $tc_analyze( "<?php defined('ABSPATH') || exit; function taxocommerce_core_query(\$wpdb, \$sql) { return \$wpdb->get_results(\$sql); }", 'security' );
check( in_array( 'review_dynamic_sql_query', $tc_sql, true ), 'Raw dynamic SQL call gets reviewed' );

$tc_sessions = $tc_analyze( "<?php defined('ABSPATH') || exit; function taxocommerce_core_session() { session_start(); \$_SESSION['a'] = 1; }", 'php' );
check( in_array( 'review_php_session', $tc_sessions, true ), 'PHP session use is detected' );

$tc_names = $tc_analyze( "<?php defined('ABSPATH') || exit; class GenericClass {} function generic_callback() {} define('GENERIC_CONST', 1); apply_filters('seo_reports_is_non_editorial_post', false);", 'wordpress' );
check( in_array( 'review_generic_class_name', $tc_names, true ), 'Non-prefixed global class is reported' );
check( in_array( 'review_generic_function_name', $tc_names, true ), 'Non-prefixed global function is reported' );
check( in_array( 'review_generic_constant_name', $tc_names, true ), 'Non-prefixed global constant is reported' );
check( in_array( 'review_nonprefixed_hook', $tc_names, true ), 'Non-prefixed custom filter is reported' );

$tc_strings = $tc_analyze( "<?php defined('ABSPATH') || exit; // activate_plugin() and ob_start() only in comments.\n\$msg = 'session_start()'; apply_filters('taxocommerce_core_safe', true);", 'wordpress' );
check( ! in_array( 'review_plugin_lifecycle_activate_plugin', $tc_strings, true ), 'Comments are not parsed as plugin activation calls' );
check( ! in_array( 'review_unclosed_output_buffer', $tc_strings, true ), 'Comments are not parsed as output buffering' );
check( ! in_array( 'review_nonprefixed_hook', $tc_strings, true ), 'Own-prefixed hooks are allowed' );

$tc_output = $tc_analyze( "<?php defined('ABSPATH') || exit; function taxocommerce_core_print(\$value) { echo \$value; }", 'security' );
check( in_array( 'review_unescaped_output', $tc_output, true ), 'Direct HTML variable output needs contextual escaping' );

$tc_arch = TaxoCommerce_Core_Autotest_Code::analyze(
	'includes/class-taxocommerce-core-architecture-report.php'
);
$tc_declarations = array_column( $tc_arch['declarations'], 'name' );
check( ! in_array( 'render', $tc_declarations, true ), 'A class method after interpolated SQL is not a generic global render function' );
check( ! in_array( 'summarize', $tc_declarations, true ), 'Interpolated strings do not close class scope in source analysis' );

// Each result is local, includes its rule and line, and does not claim the
// official WordPress Plugin Check was executed.
$tc_details = TaxoCommerce_Core_Autotest_Code::analyze(
	'includes/class-taxocommerce-core.php'
);
$tc_found_evidence = array_filter( $tc_details['security']['evidence'], static function ( $entry ) {
	return 'review_dynamic_inline_style' === $entry['rule'];
} );
$tc_found_evidence = array_values( $tc_found_evidence );
check( ! empty( $tc_found_evidence ) && ! empty( $tc_found_evidence[0]['line'] ), 'Findings include source line numbers' );
check( 'local_static_review' === $tc_found_evidence[0]['origin'], 'Findings identify local analyzer origin' );
check( 'review' === $tc_found_evidence[0]['severity'], 'Context-sensitive issue remains a review warning' );
