<?php
$root=dirname(__DIR__);
$academy=file_get_contents($root.'/includes/class-tcd-academy.php');
$sources=file_get_contents($root.'/includes/class-tcd-sources.php');
$intelligence=file_get_contents($root.'/includes/class-tcd-intelligence.php');
$maintenance=file_get_contents($root.'/includes/class-tcd-maintenance.php');
$search=file_get_contents($root.'/includes/class-tcd-search.php');
$admin=file_get_contents($root.'/includes/class-tcd-admin.php');
$main=file_get_contents($root.'/taxocommerce-dependiente.php');
$header_match=array();
$constant_match=array();
preg_match('/\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/',$main,$header_match);
preg_match("/define\s*\(\s*'TAXOCOMMERCE_DEPENDIENTE_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'/",$main,$constant_match);

$checks=array(
    !empty($header_match[1]) && isset($constant_match[1]) && $header_match[1]===$constant_match[1],
    isset($header_match[1]) && version_compare($header_match[1],'0.3.0','>='),
    strpos($academy,'CREATE TABLE {$snapshots}')!==false,
    strpos($academy,'CREATE TABLE {$items}')!==false,
    strpos($academy,"'phase'=>'snapshot'")!==false,
    strpos($academy,'snapshot_sealed')!==false,
    strpos($academy,"const SCHEMA_VERSION='3.0.0'")!==false,
    strpos($academy,'self::maybe_upgrade();')!==false,
    strpos($academy,'self::clear_learning_tables();')!==false,
    strpos($academy,"add_filter('seo_process_supervisor_manager_targets'")!==false,
    strpos($academy,'manager_target_callback')!==false,
    strpos($academy,'TCD_Load::begin()')!==false,
    strpos($academy,'TCD_Load::batch_size()')!==false,
    strpos($academy,'TCD_Intelligence::learn_item')!==false,
    strpos($academy,'TCD_Intelligence::finalize_lesson')!==false,
    strpos($academy,'TCD_Intelligence::build_interpreter')!==false,
    strpos($academy,'TCD_Maintenance::activate')!==false,
    strpos($intelligence,'CREATE TABLE {$table}')!==false,
    strpos($intelligence,"'pass_top1'")!==false,
    strpos($intelligence,"'pass_top3'")!==false,
    strpos($intelligence,"'pass_top8'")!==false,
    strpos($intelligence,'pass_any_ratio')!==false,
    strpos($intelligence,'stage_l9_item')!==false,
    strpos($intelligence,"self::add_signal( $signals, 'need'")===false,
    strpos($intelligence,'public static function decision')!==false,
    strpos($intelligence,'suggestions_from_products')!==false,
    strpos($intelligence,'apply_routes')!==false,
    strpos($intelligence,'apply_vocabulary_direct')!==false,
    strpos($intelligence,'backfill_next_missing')!==false,
    strpos($maintenance,"const CRON_HOOK = 'tcd_dependiente_maintenance'")!==false,
    strpos($maintenance,'woocommerce_update_product')!==false,
    strpos($maintenance,'created_product_cat')!==false,
    strpos($maintenance,'refresh_recent_faqs')!==false,
    strpos($maintenance,'TCD_Intelligence::build_interpreter')!==false,
    strpos($search,'TCD_Intelligence::interpret')!==false,
    strpos($search,'TCD_Intelligence::decision')!==false,
    strpos($search,'TCD_Intelligence::search_products')!==false,
    strpos($sources,'public static function source_total')!==false,
    strpos($sources,'public static function product_item')!==false,
    strpos($sources,'public static function faq_item')!==false,
    strpos($sources,'attribute_labels')!==false,
    strpos($sources,". ': ' . $title")===false,
    strpos($sources,'TaxoCommerce FAQs no está disponible: falta la tabla seo_faq.')!==false,
    strpos($sources,"semantic_group IN ('tipo','rol')")!==false,
    strpos($sources,'validate_snapshot')!==false,
    strpos($admin,'Prioridad de Dependiente')!==false,
    strpos($admin,'type="range" min="1" max="5"')!==false,
    strpos($admin,'Límite máximo de CPU para Dependiente')===false,
    strpos($admin,'fuentes analizadas')!==false,
    strpos($academy,'export_json')!==false,
    strpos($admin,'Exportar aprendizaje completo · JSON')!==false,
    strpos($admin,'foto fija auditable')!==false,
    strpos($admin,'M11 · Intérprete')!==false,
    strpos($admin,'M12 · Actualización continua')!==false,
    strpos($admin,'TaxoCommerce Dependiente · Academia')===false,
    strpos($admin,'Entrenador')===false,
);
foreach($checks as $i=>$ok){
    if(!$ok){
        fwrite(STDERR,'FAIL Dependiente intelligence smoke '.($i+1).PHP_EOL);
        exit(1);
    }
}
echo "Dependiente intelligence guards passed.\n";
