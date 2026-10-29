<?php
/** User Guide contract smoke: no WordPress or network required. */
$root = dirname( __DIR__ );
$main = file_get_contents( $root . '/taxocommerce-google-bing.php' );
$guide = file_get_contents( $root . '/includes/class-taxocommerce-google-bing-user-guide.php' );
$css = file_get_contents( $root . '/assets/user-guide.css' );
$checks = array(
 'main loads guide' => strpos($main,'class-taxocommerce-google-bing-user-guide.php')!==false,
 'registers guide' => strpos($main,'TaxoCommerce_Google_Bing_User_Guide::register()')!==false,
 'allowlisted tab' => strpos($main,"'google_trends', 'user_guide'")!==false,
 'tab navigation' => strpos($main,"'user_guide'       => 'User Guide'")!==false,
 'renders guide' => strpos($main,'TaxoCommerce_Google_Bing_User_Guide::render()')!==false,
 'two manuals' => strpos($guide,'User Manual')!==false && strpos($guide,'Technical Manual')!==false,
 'permission check' => strpos($guide,"current_user_can( 'manage_options' )")!==false,
 'read-only documentation' => strpos($guide,'<form')===false && strpos($guide,'wp_remote_get(')===false && strpos($guide,'update_option(')===false,
 'semantic headings and index' => strpos($guide,'<h2>')!==false && strpos($guide,'<h3>')!==false && strpos($guide,'tgb-guide__index')!==false,
 'active analysis blocks' => strpos($guide,'once informes')!==false,
 'responsive styles' => strpos($css,'@media(max-width:600px)')!==false,
);
foreach ($checks as $label=>$ok) { if (!$ok) {print( 'FAIL: '.$label.PHP_EOL);exit(1);} }
echo "Insights User Guide smoke passed.\n";
