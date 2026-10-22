<?php
$root=dirname(__DIR__);
$academy=file_get_contents($root.'/includes/class-tcd-academy.php');
$sources=file_get_contents($root.'/includes/class-tcd-sources.php');
$admin=file_get_contents($root.'/includes/class-tcd-admin.php');
$main=file_get_contents($root.'/taxocommerce-dependiente.php');

$checks=array(
    strpos($main,'Version: 0.2.0')!==false,
    strpos($academy,'CREATE TABLE {$snapshots}')!==false,
    strpos($academy,'CREATE TABLE {$items}')!==false,
    strpos($academy,"'phase'=>'snapshot'")!==false,
    strpos($academy,'snapshot_sealed')!==false,
    strpos($academy,'export_json')!==false,
    strpos($sources,'TaxoCommerce FAQs no está disponible: falta la tabla seo_faq.')!==false,
    strpos($sources,"semantic_group IN ('tipo','rol')")!==false,
    strpos($sources,'validate_snapshot')!==false,
    strpos($admin,'Exportar aprendizaje completo · JSON')!==false,
    strpos($admin,'foto fija auditable')!==false,
    strpos($admin,'TaxoCommerce Dependiente · Academia')===false,
);
foreach($checks as $i=>$ok){
    if(!$ok){
        fwrite(STDERR,'FAIL Dependiente learning smoke '.($i+1).PHP_EOL);
        exit(1);
    }
}
echo "Dependiente auditable learning guards passed.\n";
