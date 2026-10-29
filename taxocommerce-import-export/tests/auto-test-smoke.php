<?php
/** Offline contract and comparison smoke for the Import Export Auto Test. */
define('ABSPATH', __DIR__);
define('TAXOCOMMERCE_IMPORT_EXPORT_VERSION', '0.4.10');
function home_url($p='/'){return 'https://example.test'.$p;}
function current_time($format){return '2026-10-29 12:00:00';}
function get_current_blog_id(){return 1;}
function absint($v){return abs((int)$v);}
function assert_ie($ok,$name){if(!$ok){echo 'FAIL '.$name.PHP_EOL;exit(1);}}
require dirname(__DIR__).'/includes/class-taxocommerce-import-export-auto-test.php';
$cases=TaxoCommerce_Import_Export_Auto_Test::cases();
assert_ie(count($cases)>=32,'full test coverage');
assert_ie(isset($cases['csv_countries'],$cases['queue_worker'],$cases['export_headers'],$cases['media_contract'],$cases['real_import']),'important cases');
$before=array('site_hash'=>'same','phase'=>'before','group'=>'all','rows'=>array(array('id'=>'a','status'=>'pass'),array('id'=>'b','status'=>'fail'),array('id'=>'c','status'=>'pass')));
$after=array('site_hash'=>'same','phase'=>'after','group'=>'all','rows'=>array(array('id'=>'a','status'=>'fail'),array('id'=>'b','status'=>'pass'),array('id'=>'c','status'=>'skip'),array('id'=>'new','status'=>'fail')));
$diff=TaxoCommerce_Import_Export_Auto_Test::compare($before,$after);
assert_ie(count($diff['new_failures'])===2,'new failures');
assert_ie(count($diff['resolved'])===1,'resolved');
assert_ie(count($diff['coverage_lost'])===1,'coverage lost');
assert_ie(count($diff['new_checks'])===1,'new checks');
$after['site_hash']='different';
assert_ie(null===TaxoCommerce_Import_Export_Auto_Test::compare($before,$after),'reject cross-site');
$after['site_hash']='same';
$after['phase']='check';
assert_ie(null===TaxoCommerce_Import_Export_Auto_Test::compare($before,$after),'reject non-after');
$main=file_get_contents(dirname(__DIR__).'/taxocommerce-import-export.php');
$admin=file_get_contents(dirname(__DIR__).'/includes/class-taxocommerce-import-export.php');
assert_ie(strpos($main,'class-taxocommerce-import-export-auto-test.php')!==false,'class loaded');
assert_ie(strpos($admin,'TaxoCommerce_Import_Export_Auto_Test::render()')!==false,'tab rendered');
echo 'Import Export Auto Test smoke passed.'.PHP_EOL;
