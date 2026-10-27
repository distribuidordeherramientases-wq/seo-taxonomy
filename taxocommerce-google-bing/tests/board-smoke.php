<?php
/** Standalone smoke tests for concise board reports. */
define('ABSPATH', __DIR__);
function sanitize_key($x){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$x));}
function sanitize_text_field($x){return strip_tags((string)$x);}
function absint($x){return abs((int)$x);}
function esc_url_raw($x){return (string)$x;}
function number_format_i18n($x,$d=0){return number_format($x,$d,',','.');}
require dirname(__DIR__).'/includes/class-taxocommerce-google-bing-board.php';

$metadata=array('search'=>array('available'=>true,'current'=>array('start'=>'2026-09-01','end'=>'2026-09-30'),'previous'=>array('start'=>'2026-08-01','end'=>'2026-08-31')),'analytics'=>array('available'=>true,'current'=>array('start'=>'2026-09-01','end'=>'2026-09-30'),'previous'=>array('start'=>'2026-08-01','end'=>'2026-08-31')));
$prepared=array('prepared_at'=>12345,'sources'=>$metadata);
$analyst=array(
'where_we_are'=>array('overview'=>array('google_search'=>array('available'=>true,'clicks'=>123,'impressions'=>9000,'position'=>11,'previous'=>array('clicks'=>100,'impressions'=>8000)),'analytics'=>array('available'=>true,'sessions'=>1000,'purchases'=>12,'previous'=>array('sessions'=>900,'purchases'=>9)))),
'market'=>array('available'=>true,'summary'=>array('categories'=>20,'analyzed_categories'=>10,'external_evidence'=>30,'merchant_signals'=>12),'global_rollup'=>array('coverage'=>array('total'=>20,'complete'=>10,'partial'=>0),'catalog_gaps'=>array(array('category'=>'Taladros','product_signals'=>5)))),
'direction'=>array('rows'=>array(array('bucket'=>'NOW','priority'=>91,'action_label'=>'Mejorar landing','topic'=>'Landing taladros','reason'=>'CTR medido','confidence'=>'HIGH','demand_backed'=>true,'execution_ready'=>true,'evidence'=>array('GSC 300 impresiones'),'sources'=>array('GSC'),'target'=>array('url'=>'https://example.test/taladros/')))));
function assert_board($ok,$message){if(!$ok){fwrite(STDERR,$message."\n");exit(1);}}
$a=TaxoCommerce_Google_Bing_Board::from_data('situacion',$analyst,$prepared);
$b=TaxoCommerce_Google_Bing_Board::from_data('mercado',$analyst,$prepared);
$c=TaxoCommerce_Google_Bing_Board::from_data('estrategia',$analyst,$prepared);
assert_board($a['status']==='available' && $a['metrics'][0]['value']===123.0,'Own performance failed');
assert_board($b['metrics'][1]['value']===50.0,'Market coverage failed');
assert_board(count($c['recommendations'])===1 && $c['recommendations'][0]['bucket']==='NOW','Strategy missing');
assert_board(count($a['metrics'])<=5 && count($c['recommendations'])<=5,'Executive summary too long');
$missing=$prepared;$missing['sources']['search']=array('available'=>false);
$d=TaxoCommerce_Google_Bing_Board::from_data('estrategia',$analyst,$missing);
assert_board($d['recommendations'][0]['bucket']!=='NOW','Unverified NOW without Search Console');
$e=TaxoCommerce_Google_Bing_Board::from_data('situacion',array('where_we_are'=>array('overview'=>array('google_search'=>array('available'=>false),'analytics'=>array('available'=>false)))),array('sources'=>array('search'=>array('available'=>false),'analytics'=>array('available'=>false))));
assert_board($e['status']==='unavailable' && $e['metrics'][0]['value']===null,'Missing data incorrectly treated as zero');
echo "Executive board smoke tests: OK\n";
