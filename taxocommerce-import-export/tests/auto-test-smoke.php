<?php
/** Offline contract and comparison smoke for the Import Export Auto Test. */
define('ABSPATH', __DIR__);
define('TAXOCOMMERCE_IMPORT_EXPORT_VERSION', '0.4.9');
function home_url($p='/'){return 'https://example.test'.$p;}
function current_time($format){return '2026-10-29 12:00:00';}
function get_current_blog_id(){return 1;}
function absint($v){return abs((int)$v);}
function assert_ie($ok,$name){if(!$ok){echo 'FAIL '.$name.PHP_EOL;exit(1);}}
require dirname(__DIR__).'/includes/class-taxocommerce-import-export-auto-test.php';
$cases=TaxoCommerce_Import_Export_Auto_Test::cases();
assert_ie(count($cases)>=32,'full test coverage');
assert_ie(isset($cases['safe_queue_upgrade'],$cases['safe_export_upgrade']),'deployment safety cases');
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

/* Pre-deployment checks must not mutate the queue/export and must flag active jobs. */
class TaxoCommerce_Import_Export_Batch { const OPTION_STATE = 'tcie_test_queue'; }
class TaxoCommerce_Import_Export_Exporter { const STATE_PREFIX = 'tcie_test_export_'; }
function get_option($key,$default=array()){return $GLOBALS['tcie_test_queue']??$default;}
function get_transient($key){return $GLOBALS['tcie_test_export']??false;}
function get_current_user_id(){return 42;}
$queue=new ReflectionMethod('TaxoCommerce_Import_Export_Auto_Test','check_safe_queue_upgrade');
$queue->setAccessible(true);
$export=new ReflectionMethod('TaxoCommerce_Import_Export_Auto_Test','check_safe_export_upgrade');
$export->setAccessible(true);
$GLOBALS['tcie_test_queue']=array('status'=>'idle','enabled'=>0);
$GLOBALS['tcie_test_export']=false;
assert_ie('pass'===$queue->invoke(null)['status'],'idle queue accepts deployment');
assert_ie('pass'===$export->invoke(null)['status'],'idle export accepts deployment');
$GLOBALS['tcie_test_queue']=array('status'=>'waiting_next','enabled'=>1);
$GLOBALS['tcie_test_export']=array('status'=>'processing');
assert_ie('fail'===$queue->invoke(null)['status'],'active queue blocks deployment');
assert_ie('fail'===$export->invoke(null)['status'],'active export blocks deployment');
echo 'Import Export deployment safety smoke passed.'.PHP_EOL;
