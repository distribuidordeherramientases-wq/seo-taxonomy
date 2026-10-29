<?php
/** Isolated smoke for grouped Dependiente Auto Test; no WordPress, DB or network. */
define('ABSPATH',__DIR__);
define('TAXOCOMMERCE_DEPENDIENTE_VERSION','0.3.17');
class TCD_Academy { const CRON_HOOK='tcd_learning_tick'; }
function current_time($format){return '2026-10-29 12:00:00';}
function current_user_can($cap){return !empty($GLOBALS['allowed']);}
function esc_html__($text,$domain){return $text;}
function wp_die($text){throw new RuntimeException($text);}
function check_admin_referer($a,$b){throw new RuntimeException('Invalid nonce');}
function test_assert($ok,$message){if(!$ok){fwrite(STDERR,'FAIL: '.$message.PHP_EOL);exit(1);}}
require dirname(__DIR__).'/includes/class-tcd-auto-test.php';
$cases=TCD_Auto_Test::cases();
test_assert(count($cases)===51,'51 distinct checks');
test_assert(count(TCD_Auto_Test::groups())===10,'10 groups');
$report=TCD_Auto_Test::run_suite('write');
test_assert($report['totals']['skip']===1,'Writes are intentionally skipped');
test_assert($report['totals']['not_run']===50,'Other groups not falsely passed');
test_assert($report['totals']['pass']===0,'No accidental pass');
$fake=array('write_uat'=>$cases['write_uat'],'missing'=>array('write','Unimplemented'));
$report=TCD_Auto_Test::run_suite('all',$fake);
test_assert($report['totals']['fail']===1 && $report['totals']['skip']===1,'Failure isolation and missing tests');
$report=TCD_Auto_Test::run_suite('quality',array('quality_l2'=>$cases['quality_l2'],'write_uat'=>$cases['write_uat']));
test_assert($report['totals']['not_run']===1 && $report['totals']['fail']===1,'Group filtering, caught missing class');
$GLOBALS['allowed']=false;
try{TCD_Auto_Test::handle_run();test_assert(false,'permissions');}
catch(RuntimeException $e){test_assert(strpos($e->getMessage(),'permisos')!==false,'permission guard');}
$GLOBALS['allowed']=true;
try{TCD_Auto_Test::handle_run();test_assert(false,'nonce');}
catch(RuntimeException $e){test_assert($e->getMessage()==='Invalid nonce','nonce guard');}
echo "Dependiente grouped Auto Test smoke passed: 51 cases, 10 groups, isolation, skipped writes, permissions and nonce\n";
