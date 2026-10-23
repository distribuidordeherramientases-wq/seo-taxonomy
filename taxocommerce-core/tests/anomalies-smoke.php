<?php
/**
 * Static smoke test for the copied legacy anomalies screen.
 * Run: php taxocommerce-core/tests/anomalies-smoke.php
 */
$root = dirname(__DIR__);
$core = file_get_contents($root . '/includes/class-taxocommerce-core.php');
$bootstrap = file_get_contents($root . '/taxocommerce-core.php');
$report = file_get_contents($root . '/includes/class-taxocommerce-core-anomalies.php');
$json = file_get_contents($root . '/includes/class-taxocommerce-core-anomalies-json.php');
$original = file_get_contents(dirname($root) . '/includes/seo-reports.php');
$checks = array(
    strpos($core, "'taxocommerce-core-anomalies'") !== false,
    strpos($core, "'taxocommerce_core_anomalies_render'") !== false,
    strpos($bootstrap, "class-taxocommerce-core-anomalies.php") !== false,
    strpos($bootstrap, "class-taxocommerce-core-anomalies-json.php") !== false,
    strpos($report, 'Clusters sin hubs primarios') !== false,
    strpos($report, 'Hubs primarios sin cluster') !== false,
    strpos($report, 'Hubs secundarios sin categorías') !== false,
    strpos($report, 'Landing pages sin categoría de producto asociada') !== false,
    strpos($report, 'Posts editoriales sin categoría de producto relacionada') !== false,
    strpos($report, 'Entradas editoriales sin Vocabulary semántico activo') !== false,
    strpos($report, 'Categorías de producto con 0 productos') !== false,
    strpos($report, 'FAQs sin elemento relacionado') !== false,
    strpos($json, "'seo-anomalias-contenidos'") !== false,
    strpos($json, 'taxocommerce_core_anomalies_content_json_import_handler') !== false,
    strpos($original, 'function seo_render_anomalies_report()') !== false,
);
foreach ($checks as $index => $ok) {
    if (!$ok) { fwrite(STDERR, 'FAIL anomalies migration smoke ' . ($index + 1) . PHP_EOL); exit(1); }
}
echo "Anomalies migration source checks passed.\n";
