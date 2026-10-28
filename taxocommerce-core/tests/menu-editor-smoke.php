<?php
/** Isolated regression tests; deliberately no production DB or HTTP calls. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public $message; public function __construct( $code, $message ) { $this->message = $message; } public function get_error_message() { return $this->message; } }
class WP_Post { public $ID; public $post_type = 'page'; public $post_status = 'publish'; public $post_password = ''; public $post_title; public $post_name = ''; public function __construct( $id, $title ) { $this->ID=$id; $this->post_title=$title; } }
class TaxoCommerce_Core_Logger { public function info( $a, $b ) {} }
class TaxoCommerce_Core_Hierarchy_Classic { public static function map( $kind ) { return array(); } public static function role_pages( $role ) { return array(); } }
class WP_Query { public $posts; public function __construct( $args ) { $GLOBALS['last_query']=$args; $rows=array_values( array_filter( $GLOBALS['posts'], static function( $post ) use ( $args ) { return $post->post_type===$args['post_type'] && 'publish'===$post->post_status && ''===$post->post_password && ( ''===$args['s'] || false!==stripos( $post->post_title, $args['s'] ) ); } ) ); $this->posts=array_slice( $rows, $args['offset'], $args['posts_per_page'] ); } }
$GLOBALS['hooks']=array(); $GLOBALS['options']=array('taxocommerce_core_menu_id'=>99,'taxocommerce_core_menu_location_backup'=>array('locations'=>array('primary'=>1)));
$GLOBALS['menus']=array(99=>(object)array('term_id'=>99)); $GLOBALS['items']=array(); $GLOBALS['meta']=array(); $GLOBALS['writes']=0; $GLOBALS['allowed']=true;
$GLOBALS['posts']=array(); for($i=1;$i<=130;$i++){ $GLOBALS['posts'][$i]=new WP_Post($i,'Example page '.$i); }
$GLOBALS['posts'][131]=new WP_Post(131,'Draft'); $GLOBALS['posts'][131]->post_status='draft';
function is_wp_error($x){return $x instanceof WP_Error;} function absint($x){return abs((int)$x);} function sanitize_key($x){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$x));} function sanitize_text_field($x){return trim(strip_tags((string)$x));}
function __($x,$d=''){return $x;} function esc_html__($x,$d=''){return esc_html($x);} function esc_html_e($x,$d=''){echo esc_html($x);} function esc_html($x){return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');} function esc_attr($x){return esc_html($x);} function esc_url($x){return esc_html($x);}
function esc_url_raw($x,$allowed=array()){return preg_match('/^javascript:/i',$x)?'':$x;} function wp_specialchars_decode($x,$flags){return html_entity_decode($x,$flags,'UTF-8');} function wp_slash($x){return addslashes($x);} function wp_unslash($x){return is_array($x)?array_map('wp_unslash',$x):stripslashes($x);}
function selected($a,$b=true,$echo=true){$s=(string)$a===(string)$b?' selected="selected"':'';if($echo)echo $s;return $s;}
function taxonomy_exists($x){return in_array($x,array('category','product_cat'),true);} function admin_url($x=''){return 'https://example.test/wp-admin/'.$x;} function wp_create_nonce($a){return 'nonce-'.$a;}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;} function update_option($k,$v,$autoload=false){$GLOBALS['writes']++;$GLOBALS['options'][$k]=$v;return true;}
function get_theme_mod($k,$d=array()){return array('primary'=>99);} function get_registered_nav_menus(){return array('primary'=>'Menu principal','footer'=>'Pie');}
function wp_get_nav_menu_object($id){return 'TaxoCommerce'===$id?($GLOBALS['menus'][99]??false):($GLOBALS['menus'][$id]??false);} function wp_create_nav_menu($name){$GLOBALS['writes']++;$GLOBALS['menus'][99]=(object)array('term_id'=>99);return 99;}
function wp_get_nav_menu_items($id,$args=array()){return array_values(array_filter($GLOBALS['items'],static function($item)use($id){return $item->menu_id===$id;}));}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';} function update_post_meta($id,$key,$value){$GLOBALS['writes']++;$GLOBALS['meta'][$id][$key]=$value;if('_menu_item_menu_item_parent'===$key && isset($GLOBALS['items'][$id])){$GLOBALS['items'][$id]->menu_item_parent=$value;}return true;}
function get_post($id){return $GLOBALS['posts'][$id]??null;} function get_the_title($post){return is_object($post)?$post->post_title:($GLOBALS['posts'][$post]->post_title??'');}
function get_ancestors($id,$taxonomy,$type){return array();} function get_terms($args){return array((object)array('term_id'=>71,'name'=>'Tools'));} function get_term($id,$tax){return $id===71?(object)array('term_id'=>71,'name'=>'Tools'):null;}
function wp_update_nav_menu_item($menu_id,$id,$data){$GLOBALS['writes']++;$id=$id?:1000+count($GLOBALS['items']);$GLOBALS['items'][$id]=(object)array('ID'=>$id,'menu_id'=>$menu_id,'menu_item_parent'=>$data['menu-item-parent-id'],'title'=>stripslashes($data['menu-item-title']),'url'=>$data['menu-item-url']??'https://example.test/item','object'=>$data['menu-item-object']??'custom','data'=>$data);return $id;}
function wp_delete_post($id,$force){$GLOBALS['writes']++;$old=$GLOBALS['items'][$id]??false;unset($GLOBALS['items'][$id]);return $old;}
function add_filter($hook,$cb,$priority=10,$args=1){$GLOBALS['hooks'][$hook][]=$cb;} function add_action($hook,$cb,$priority=10,$args=1){$GLOBALS['hooks'][$hook][]=$cb;} function apply_filters($name,$value){return $value;} function current_user_can($cap){return $GLOBALS['allowed'];} function wp_die($text){throw new RuntimeException($text);}
function check_admin_referer($action,$field){if(($_POST[$field]??'')!=='nonce-'.$action)throw new RuntimeException('nonce');} function check_ajax_referer($action,$field){check_admin_referer($action,$field);}
require dirname(__DIR__).'/includes/class-taxocommerce-core-country.php';
require dirname(__DIR__).'/includes/class-taxocommerce-core-menu.php';
function verify($condition,$message){if(!$condition){fwrite(STDERR,'FAIL: '.$message."\n");exit(1);} $GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
$core=new TaxoCommerce_Core_Menu(new TaxoCommerce_Core_Logger()); $core->register_hooks();
foreach($GLOBALS['hooks'] as $callbacks){foreach($callbacks as $cb){verify(is_callable($cb),'public hook callback');}}
$gen=wp_update_nav_menu_item(99,0,array('menu-item-title'=>'Automatic','menu-item-parent-id'=>0));
update_post_meta($gen,TaxoCommerce_Core_Menu::GENERATED_META,1);
$manual=wp_update_nav_menu_item(99,0,array('menu-item-title'=>'Manual <safe>','menu-item-parent-id'=>$gen));
$before=$GLOBALS['writes'];ob_start();$core->render();$html=ob_get_clean();
verify($GLOBALS['writes']===$before,'view never mutates menu');
verify(substr_count($html,'value="taxocommerce_core_sync_menu"')===2,'sync top and bottom');
verify(substr_count($html,'value="taxocommerce_core_activate_menu"')===2,'activate top and bottom');
verify(substr_count($html,'value="taxocommerce_core_restore_menu"')===2,'restore top and bottom');
verify(substr_count($html,'nav-menus.php?action=edit')===2,'native editor top and bottom');
verify(strpos($html,'data-position="top"')<strpos($html,'class="tc-menu-editor"'),'actions before editor');
verify(strpos($html,'data-position="bottom"')>strpos($html,'class="tc-menu-editor"'),'actions after editor');
preg_match_all('/\bid="([^"]+)"/',$html,$matches);verify(count($matches[1])===count(array_unique($matches[1])),'unique DOM IDs');
verify(substr_count($html,'class="tc-menu-box"')===5,'four original panels plus product categories');
verify(strpos($html,'Manual &lt;safe&gt;')!==false,'escaped titles');
if(getenv('TC_MENU_HTML')){file_put_contents(getenv('TC_MENU_HTML'),$html);}
$before=$GLOBALS['writes'];verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::add_objects('page',array(131))),'draft rejected');verify($before===$GLOBALS['writes'],'invalid add has no writes');
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::add_objects('attachment',array(1))),'type whitelist');
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::add_objects('page',range(1,51))),'selection limit');
verify(TaxoCommerce_Core_Menu_Editor::add_objects('page',array(1,1,2))===2,'deduplicate selected IDs');
verify(TaxoCommerce_Core_Menu_Editor::add_objects('category',array(71))===1,'native taxonomy item');
$id=TaxoCommerce_Core_Menu_Editor::add_link("O'Reilly",'https://example.test/about');verify(!is_wp_error($id),'custom link created');verify($GLOBALS['items'][$id]->title==="O'Reilly",'slashed title roundtrip');
verify(!TaxoCommerce_Core_Menu_Editor::generated($id),'manual link unmarked');
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::add_link('bad','javascript:alert(1)')),'unsafe link blocked');
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::remove_item($gen)),'generated item protected');
$foreign=wp_update_nav_menu_item(100,0,array('menu-item-title'=>'Foreign','menu-item-parent-id'=>0));verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::remove_item($foreign)),'other menu protected');
verify(TaxoCommerce_Core_Menu_Editor::remove_item($id)===true,'remove manual item');
$method=new ReflectionMethod($core,'delete_generated_items');$method->setAccessible(true);$method->invoke($core,99);
verify(!isset($GLOBALS['items'][$gen]),'sync deletes generated only');verify(isset($GLOBALS['items'][$manual]),'sync preserves manual item');verify($GLOBALS['items'][$manual]->menu_item_parent===0,'sync preserves orphaned manual child');
$result=TaxoCommerce_Core_Menu_Editor::objects('page','all',2);verify(count($result['items'])===25 && $result['more'],'bounded pagination');verify($GLOBALS['last_query']['offset']===25,'pagination offset');
$result=TaxoCommerce_Core_Menu_Editor::objects('page','search',1,'130');verify(count($result['items'])===1 && $result['items'][0]['id']===130,'search beyond first hundred');
$GLOBALS['allowed']=false;$before=$GLOBALS['writes'];try{TaxoCommerce_Core_Menu_Editor::handle_action();verify(false,'permissions must deny');}catch(RuntimeException $e){verify($before===$GLOBALS['writes'],'no writes on permission failure');}
$GLOBALS['allowed']=true;$_POST=array('task'=>'add','object_type'=>'page','object_ids'=>array(1));try{TaxoCommerce_Core_Menu_Editor::handle_action();verify(false,'nonce must deny');}catch(RuntimeException $e){verify($e->getMessage()==='nonce','nonce gate');}
unset($GLOBALS['menus'][99]);$GLOBALS['options']['taxocommerce_core_menu_id']=0;$GLOBALS['options']['taxocommerce_core_menu_location_backup']=false;$before=$GLOBALS['writes'];ob_start();$core->render();$empty=ob_get_clean();verify($GLOBALS['writes']===$before,'empty view never creates menu');verify(substr_count($empty,'value="taxocommerce_core_activate_menu"')===0,'no activate control before menu exists');
echo 'PASS: '.$GLOBALS['checks']." menu checks. Isolated WP stubs; not a live WordPress test.\n";
