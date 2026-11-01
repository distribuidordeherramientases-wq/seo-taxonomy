<?php
/**
 * Source contract for System Check Plugin Check regressions.
 * This file runs offline, without WordPress, MySQL, or network access.
 */
$root = dirname(__DIR__);
$files = array(
    'billing' => $root . '/includes/system-check/seo-core-validation-billing.php',
    'core' => $root . '/includes/system-check/seo-core-validation.php',
    'health' => $root . '/includes/system-check/seo-health-scan.php',
    'semantic' => $root . '/includes/system-check/seo-core-validation-semantic.php',
    'url_audit' => $root . '/includes/class-taxocommerce-system-check-url-audit.php',
    'data_layer' => $root . '/includes/system-check/seo-core-validation-data-layer.php',
);
$data = array();
foreach ($files as $name => $file) {
    $content = file_get_contents($file);
    if (!is_string($content) || $content === '') {
        fwrite(STDERR, 'Cannot read diagnostic source: ' . $name . PHP_EOL);
        exit(1);
    }
    $data[$name] = $content;
}
$checks = array(
    array('billing', '$stats = $wpdb->get_row(', true),
    array('billing', '$wpdb->prepare(', true),
    array('billing', '$stats = $wpdb->get_row($sql', false),
    array('core', '$prepared_sql = $wpdb->prepare($query', false),
    array('core', 'return (array) $wpdb->get_results($prepared_sql', false),
    array('core', 'SELECT SUM(LENGTH(option_value)) FROM %i', true),
    array('core', 'implode(\' OR \', $where)', true),
    array('core', 'PluginCheck.Security.DirectDB.UnescapedDBParameter', true),
    array('health', "UPDATE %i SET queued_scan_id=%d,queued_at=%s", true),
    array('health', 'SELECT COUNT(*) FROM %i WHERE ', true),
    array('health', '$wpdb->prepare($count_sql', false),
    array('health', '$wpdb->prepare($rows_sql', false),
    array('semantic', 'WordPress.DB.PreparedSQL.NotPrepared', true),
    array('url_audit', 'Live URL-audit count', true),
    array('data_layer', 'FROM %i a', true),
    array('data_layer', 'LEFT JOIN %i g ON g.group_id = a.group_id', true),
    array('data_layer', 'FROM `{$table}` a', false),
    array('data_layer', 'LEFT JOIN `{$groups_table}` g', false),
);
foreach ($checks as $check) {
    list($area, $text, $present) = $check;
    $found = strpos($data[$area], $text) !== false;
    if ($found !== $present) {
        fwrite(STDERR, sprintf("Unexpected SQL contract: %s / %s\n", $area, $text));
        exit(1);
    }
}
$plugin = file_get_contents($root . '/taxocommerce-system-check.php');
$readme = file_get_contents($root . '/readme.txt');
if (strpos($plugin, 'Version: 0.2.13') === false || strpos($readme, 'Stable tag: 0.2.13') === false) {
    fwrite(STDERR, "Plugin/readme version mismatch.\n");
    exit(1);
}
echo "System Check 0.2.13 SQL and live-diagnostic source contracts validated.\n";
