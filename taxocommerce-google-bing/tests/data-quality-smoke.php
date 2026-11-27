<?php
/** Standalone data collection and quality regressions; no WP, API or order writes. */
define( 'ABSPATH', __DIR__ );
function __( $text, $domain = '' ) { return $text; }
function get_option( $key, $fallback = array() ) { return $GLOBALS['quality_options'][$key] ?? $fallback; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['quality_options'][$key] = $value; return true; }
require dirname( __DIR__ ) . '/includes/class-taxocommerce-insights-data-quality.php';
function quality_assert( $condition, $label ) {
    if ( ! $condition ) throw new RuntimeException( $label );
}
$class = 'TaxoCommerce_Insights_Data_Quality';
quality_assert( '' === $class::date( '2026-02-30' ), 'invalid dates rejected' );
quality_assert( 'skip' === $class::assessment( array() )['status'], 'unknown does not pass' );
quality_assert( 'skip' === $class::assessment( array( 'state'=>'ok', 'observed_at'=>100 ), 1000000 )['status'], 'old evidence does not pass' );
quality_assert( 'fail' === $class::assessment( array( 'state'=>'partial', 'observed_at'=>100 ), 101 )['status'], 'partial is not pass' );
quality_assert( 'pass' === $class::assessment( array( 'state'=>'ok', 'observed_at'=>100 ), 101 )['status'], 'fresh evidence passes' );
quality_assert( ! $class::ga4_response_valid( array(), array('sessions') ), 'malformed response rejected' );
quality_assert( $class::ga4_response_valid( array('metricHeaders'=>array(array('name'=>'sessions'))), array('sessions') ), 'valid empty report is not malformed' );
quality_assert( ! $class::ga4_response_valid( array('metricHeaders'=>array(array('name'=>'sessions')), 'rows'=>array(array('metricValues'=>array()))), array('sessions') ), 'missing values rejected' );
$period = array('start'=>'2026-10-30','end'=>'2026-11-26');
$ga = array('state'=>'ok','period'=>$period,'metrics'=>array('sessions'=>310,'purchases'=>3),'channel_sessions'=>310,
    'funnel'=>array('begin_checkout'=>15,'add_shipping_info'=>0,'add_payment_info'=>3,'purchase'=>3),
    'chatgpt'=>array('sessions'=>151,'purchases'=>null));
$findings = array_column( $class::compare($ga,array('start'=>'2026-10-28','end'=>'2026-11-24'),array('available'=>true,'paid_status'=>2)), 'state', 'code' );
quality_assert( 'unavailable' === $findings['periods'], 'different periods not compared' );
quality_assert( 'ok' === $findings['session_totals'], 'sessions reconcile' );
quality_assert( 'review' === $findings['funnel_events'], 'missing shipping flagged without claiming a defect' );
quality_assert( 'review' === $findings['woocommerce'], 'paid-order discrepancy flagged' );
quality_assert( 'unavailable' === $findings['chatgpt_attribution'], 'missing conversion is not zero' );
$ga['chatgpt']['purchases'] = 0;
$findings = array_column($class::compare($ga,$period,array('available'=>false)), 'state', 'code');
quality_assert( 'ok' === $findings['chatgpt_attribution'] && 'unavailable' === $findings['woocommerce'], 'measured zero distinct from unavailable orders' );
$class::record_ga4(array('available'=>true,'period'=>array_merge($period,array('days'=>28)), 'sessions'=>310, 'purchases'=>3, 'funnel'=>array('purchase'=>3), 'errors'=>array('events'=>'SECRET'), 'chatgpt'=>array('sessions'=>151,'purchases'=>0)));
$evidence = $class::evidence('ga4_28');
quality_assert( 'partial' === $evidence['state'] && null === $evidence['funnel']['purchase'] && false === strpos(json_encode($evidence),'SECRET'), 'partial capture and no raw error leakage' );
echo "Insights data quality smoke: OK.\n";
