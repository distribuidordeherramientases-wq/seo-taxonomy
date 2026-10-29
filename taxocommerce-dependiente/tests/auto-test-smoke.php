<?php
/** Isolated smoke for grouped Dependiente Auto Test; no WordPress, DB or network. */
define('ABSPATH',__DIR__);
define('TAXOCOMMERCE_DEPENDIENTE_VERSION','0.3.18');
class TCD_Academy {
	const CRON_HOOK='tcd_learning_tick';
	public static function state(){return $GLOBALS['academy_state'];}
}
class TCD_Intelligence {
	public static function quality_for_lesson($lesson){return $GLOBALS['quality_report'];}
}
class TCD_Original_Linguista {
	public static function state(){return $GLOBALS['linguist_state'];}
	public static function course_progress(){return 62.0;}
	public static function process_control_start(){}
	public static function process_manager_slice(){}
	public static function is_pending(){return false;}
	public static function cron_batch(){}
}
class TCD_Maintenance {
	public static function state(){return $GLOBALS['m12_state'];}
}
function has_action($hook){return true;}
function absint($number){return abs((int)$number);}
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
$GLOBALS['quality_report']=array('status'=>'passed','evaluated'=>2,'pass_top1'=>5,'pass_top3'=>5,'pass_top8'=>5,'failed'=>0,'pass_any_ratio'=>0.9,'threshold'=>0.8);
$report=TCD_Auto_Test::run_suite('quality',array('quality_l2'=>$cases['quality_l2'],'write_uat'=>$cases['write_uat']));
test_assert($report['totals']['not_run']===1 && $report['totals']['fail']===1,'Group filtering and detection of inconsistent quality metrics');
// A failed examination is NOT a regression if its report is well-formed.
$GLOBALS['quality_report']=array(
	'status'=>'failed', 'evaluated'=>100, 'pass_top1'=>20, 'pass_top3'=>55,
	'pass_top8'=>87, 'failed'=>13, 'pass_any_ratio'=>0.872, 'threshold'=>0.88,
);
$report=TCD_Auto_Test::run_suite('quality',array('quality_l2'=>$cases['quality_l2']));
test_assert($report['totals']['pass']===1 && $report['totals']['fail']===0,
	'L2 87.2% vs 88% is a valid educational outcome, not a broken feature');
$GLOBALS['quality_report']['status']='passed';
$report=TCD_Auto_Test::run_suite('quality',array('quality_l2'=>$cases['quality_l2']));
test_assert($report['totals']['fail']===1,'Inconsistent passed grade below threshold must fail health');
$GLOBALS['quality_report']['status']='failed';
$GLOBALS['academy_state']=array('status'=>'error','lesson'=>11,'phase'=>'interpreter','last_error'=>'No alcanza el umbral del examen');
$report=TCD_Auto_Test::run_suite('learning',array('learning_state'=>$cases['learning_state']));
test_assert($report['totals']['pass']===1 && $report['totals']['fail']===0,
	'Academy exam error must be diagnostic, not technical failure');
$GLOBALS['linguist_state']=array('status'=>'error','last_error'=>'M11 result below gate');
$report=TCD_Auto_Test::run_suite('interpreter',array('linguist_state'=>$cases['linguist_state']));
test_assert($report['totals']['pass']===1 && $report['totals']['fail']===0,
	'M11 can start with worker but a failed course must be diagnostic only');
$GLOBALS['linguist_state']['status']='running';
$report=TCD_Auto_Test::run_suite('interpreter',array('linguist_state'=>$cases['linguist_state']));
test_assert($report['totals']['pass']===1,'M11 available controls and worker must pass');
$GLOBALS['m12_state']=array('status'=>'error','last_error'=>'Last batch error','products_updated'=>0);
$report=TCD_Auto_Test::run_suite('maintenance',array('maintenance_state'=>$cases['maintenance_state']));
test_assert($report['totals']['pass']===1 && $report['totals']['fail']===0,
	'Historical M12 error is not conflated with missing functionality');

$GLOBALS['allowed']=false;
try{TCD_Auto_Test::handle_run();test_assert(false,'permissions');}
catch(RuntimeException $e){test_assert(strpos($e->getMessage(),'permisos')!==false,'permission guard');}
$GLOBALS['allowed']=true;
try{TCD_Auto_Test::handle_run();test_assert(false,'nonce');}
catch(RuntimeException $e){test_assert($e->getMessage()==='Invalid nonce','nonce guard');}
echo "Dependiente grouped Auto Test smoke passed: 51 cases, 10 groups, isolation, passed/failed quality distinction, M11 worker readiness, permissions and nonce\n";
