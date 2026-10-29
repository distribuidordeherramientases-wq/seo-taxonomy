<?php
/** Static regression guards for M11 observability, no WordPress bootstrap needed. */
$root=dirname(__DIR__);
$academy=file_get_contents($root.'/includes/class-tcd-academy.php');
$linguist=file_get_contents($root.'/includes/interpreter/class-tcd-linguist.php');
$log=file_get_contents($root.'/includes/class-tcd-learning-log.php');
$admin=file_get_contents($root.'/includes/class-tcd-admin.php');
$checks=array(
  'M11 batch start'=>strpos($academy,"'m11_batch_started'")!==false,
  'M11 batch end'=>strpos($academy,"'m11_batch_finished'")!==false,
  'M11 catches logged'=>strpos($academy,"'m11_exception'")!==false,
  'Lingüista resume'=>strpos($academy,"if('stopped'===\$status && !TCD_Original_Linguista::is_pending())")!==false,
  'Lingüista captures errors'=>strpos($linguist,"'linguist_exception'")!==false,
  'Lingüista window stats'=>strpos($linguist,"'linguist_window_finished'")!==false,
  'Interpreter separate bounded file'=>strpos($log,"const INTERPRETER_FILE = 'interpreter.log'")!==false && strpos($log,"self::append_bounded( $interpreter_path, $line )")!==false,
  'Admin M11 diagnostics'=>strpos($admin,"Diagnóstico M11")!==false && strpos($admin,'interpreter_read()')!==false,
);
foreach($checks as $name=>$passed){if(!$passed){fwrite(STDERR,"FAIL: ".$name.PHP_EOL);exit(1);}}
echo "M11 observability guards passed.\n";
