<?php
/**
 * Read-only L10 provenance regression; runs without WordPress or a database.
 * php taxocommerce-dependiente/tests/l10-evaluator-provenance.php
 */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}
require_once dirname( __DIR__ ) . '/includes/class-tcd-intelligence.php';

function tcd_assert_provenance( $predicate, $description ) {
    if ( ! $predicate ) {
        fwrite( STDERR, "FAILED: " . $description . PHP_EOL );
        exit( 1 );
    }
}
$old = array(
    'evaluated'=>180,
    'pass_top8'=>160,
    'failed'=>20,
    'failures'=>array( array(
        'original_lesson'=>9,
        'need_scope'=>'legacy',
        'query'=>'Necesito Motosierras para Motosierras, Herramienta',
    ) ),
);
tcd_assert_provenance( TCD_Intelligence::quality_report_is_stale( 10, $old ), 'old L10 report is stale' );
tcd_assert_provenance( TCD_Intelligence::quality_report_is_stale( 9, $old ), 'old L9 report is stale' );
tcd_assert_provenance( ! TCD_Intelligence::quality_report_is_stale( 8, $old ), 'L8 is not falsely invalidated' );
$current = array(
    'quality_engine_revision'=>TCD_Intelligence::QUALITY_EVALUATOR_REVISION,
    'evaluated_plugin_version'=>'0.3.13',
    'query_origins'=>array( 'snapshot_facts'=>20 ),
);
tcd_assert_provenance( ! TCD_Intelligence::quality_report_is_stale( 10, $current ), 'versioned L10 is current' );
tcd_assert_provenance( ! TCD_Intelligence::quality_report_is_stale( 9, $current ), 'versioned L9 is current' );
echo "L10 evaluator provenance regression passed.\n";
