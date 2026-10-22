<?php
/**
 * Deterministic, database-free tests for the read-only comparator.
 * Run: php taxocommerce-system-check/tests/catalog-parity-smoke.php
 */
define('ABSPATH', __DIR__);
function sanitize_title($v) {
    return trim(preg_replace('/[^a-z0-9_-]+/', '-', strtolower((string)$v)), '-');
}
function wp_strip_all_tags($v) { return strip_tags((string)$v); }
require_once dirname(__DIR__) . '/includes/class-tcs-catalog-parity.php';

function expect($valid, $message) {
    if (!$valid) {
        fwrite(STDERR, "FAILED: " . $message . "\n");
        exit(1);
    }
}

$state = TCS_Catalog_Parity::initial_state();
expect($state['status'] === 'running' && $state['products_scanned'] === 0, 'Starts without changing data.');
expect($state['attribute_missing'] === 0 && $state['attribute_matches'] === 0, 'No fabricated matches.');

$source = array(
    'slug'=>'material',
    'nombre'=>'Material',
    'valor_original'=>'Acero',
    'valor_texto'=>null,
    'valor_numero'=>null,
    'unidad'=>null,
    'term_slug'=>'acero',
    'term_name'=>'Acero',
);
$method = new ReflectionMethod('TCS_Catalog_Parity', 'compare');
$method->setAccessible(true);
$native = array('material'=>array('name'=>'pa_material','taxonomy'=>true,'value'=>''));
$terms = array('pa_material'=>array(array('slug'=>'acero','name'=>'Acero')));
$ok = $method->invoke(null,$source,$native,$terms);
expect($ok['state']==='attribute_matches','Matches taxonomy attribute term.');
$missing = $method->invoke(null,$source,array(),array());
expect($missing['state']==='attribute_missing','Reports absent attribute.');
$term_missing = $method->invoke(null,$source,$native,array());
expect($term_missing['state']==='attribute_terms_missing','Reports missing native terms.');
$wrong = $method->invoke(null,$source,$native,array('pa_material'=>array(array('slug'=>'aluminio','name'=>'Aluminio'))));
expect($wrong['state']==='attribute_value_mismatch','Reports different value.');

$custom = array('material'=>array('name'=>'material','taxonomy'=>false,'value'=>'Acero | Aluminio'));
$custom_match = $method->invoke(null,$source,$custom,array());
expect($custom_match['state']==='attribute_matches','Matches custom non-taxonomy attribute.');

echo "OK: Product catalog parity comparator (read-only)\n";
