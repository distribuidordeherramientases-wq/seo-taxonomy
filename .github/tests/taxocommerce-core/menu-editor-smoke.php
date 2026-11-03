<?php
/** Isolated regression tests; deliberately no production DB or HTTP calls. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public $message; public function __construct( $code, $message ) { $this->message = $message; } public function get_error_message() { return $this->message; } }
class WP_Post { public $ID; public $post_type = 'page'; public $post_status = 'publish'; public $post_password = ''; public $post_title; public $post_name = ''; public function __construct( $id, $title ) { $this->ID=$id; $this->post_title=$title; } }
class TaxoCommerce_Core_Logger { public function info( $a, $b ) {} }
class TaxoCommerce_Core_Hierarchy_Classic { public static function map( $kind, $country = '' ) { return array(); } public static function role_pages( $role ) { return array(); } public static function country_pages( $role, $country ) { return array(); } }
class WP_Query { public $posts; public function __construct( $args ) { $GLOBALS['last_query']=$args; $rows=array_values( array_filter( $GLOBALS['posts'], static function( $post ) use ( $args ) { return $post->post_type===$args['post_type'] && 'publish'===$post->post_status && ''===$post->post_password && ( ''===$args['s'] || false!==stripos( $post->post_title, $args['s'] ) ); } ) ); $this->posts=array_slice( $rows, $args['offset'], $args['posts_per_page'] ); } }
$GLOBALS['hooks']=array(); $GLOBALS['options']=array('taxocommerce_core_menu_id'=>99,'taxocommerce_core_menu_location_backup'=>array('locations'=>array('primary'=>1)));
$GLOBALS['menus']=array(99=>(object)array('term_id'=>99,'name'=>'TaxoCommerce')); $GLOBALS['items']=array(); $GLOBALS['meta']=array(); $GLOBALS['writes']=0; $GLOBALS['allowed']=true;
$GLOBALS['woo_enabled']=true;
$GLOBALS['posts']=array(); for($i=1;$i<=130;$i++){ $GLOBALS['posts'][$i]=new WP_Post($i,'Example page '.$i); }
$GLOBALS['posts'][131]=new WP_Post(131,'Draft'); $GLOBALS['posts'][131]->post_status='draft';
function is_wp_error($x){return $x instanceof WP_Error;} function absint($x){return abs((int)$x);} function sanitize_key($x){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$x));} function sanitize_text_field($x){return trim(strip_tags((string)$x));}
function __($x,$d=''){return $x;} function esc_html__($x,$d=''){return esc_html($x);} function esc_attr__($x,$d=''){return esc_attr($x);} function esc_html_e($x,$d=''){echo esc_html($x);} function esc_html($x){return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');} function esc_attr($x){return esc_html($x);} function esc_url($x){return esc_html($x);}
function esc_url_raw($x,$allowed=array()){return preg_match('/^javascript:/i',$x)?'':$x;} function wp_specialchars_decode($x,$flags){return html_entity_decode($x,$flags,'UTF-8');} function wp_slash($x){return addslashes($x);} function wp_unslash($x){return is_array($x)?array_map('wp_unslash',$x):stripslashes($x);}
function add_query_arg($args,$url=''){return $url.'?'.http_build_query($args);}
function selected($a,$b=true,$echo=true){$s=(string)$a===(string)$b?' selected="selected"':'';if($echo)echo $s;return $s;}
function taxonomy_exists($x){return in_array($x,array('category','product_cat'),true) && ('product_cat'!==$x || !empty($GLOBALS['woo_enabled']));} function admin_url($x=''){return 'https://example.test/wp-admin/'.$x;} function wp_create_nonce($a){return 'nonce-'.$a;}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;} function update_option($k,$v,$autoload=false){$GLOBALS['writes']++;$GLOBALS['options'][$k]=$v;return true;}
function get_theme_mod($k,$d=array()){return array('primary'=>99);} function get_registered_nav_menus(){return array('primary'=>'Menu principal','footer'=>'Pie');}
function wp_get_nav_menu_object($id){if(is_numeric($id))return $GLOBALS['menus'][(int)$id]??false;foreach($GLOBALS['menus'] as $m){if($m->name===$id)return $m;}return false;}
function wp_get_nav_menus($args=array()){return array_values($GLOBALS['menus']);}
function wp_create_nav_menu($name){$GLOBALS['writes']++;$id=max(array_keys($GLOBALS['menus']) ?: array(98))+1;$GLOBALS['menus'][$id]=(object)array('term_id'=>$id,'name'=>$name);return $id;}
function wp_update_nav_menu_object($id,$data){$GLOBALS['writes']++;if(!isset($GLOBALS['menus'][$id]))return new WP_Error('bad_menu','Menu missing');$GLOBALS['menus'][$id]->name=$data['menu-name'];return $id;}
function wp_delete_nav_menu($id){if(!isset($GLOBALS['menus'][$id]))return false;$GLOBALS['writes']++;unset($GLOBALS['menus'][$id]);foreach($GLOBALS['items'] as $k=>$item){if($item->menu_id===$id)unset($GLOBALS['items'][$k]);}return true;}
function wp_get_nav_menu_items($id,$args=array()){return array_values(array_filter($GLOBALS['items'],static function($item)use($id){return $item->menu_id===$id;}));}
function get_term_meta($id,$key,$single=true){return ''; } function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';} function update_post_meta($id,$key,$value){$GLOBALS['writes']++;$GLOBALS['meta'][$id][$key]=$value;if('_menu_item_menu_item_parent'===$key && isset($GLOBALS['items'][$id])){$GLOBALS['items'][$id]->menu_item_parent=$value;}return true;}
function get_post($id){return $GLOBALS['posts'][$id]??null;} function get_the_title($post){return is_object($post)?$post->post_title:($GLOBALS['posts'][$post]->post_title??'');}
function get_ancestors($id,$taxonomy,$type){return array();} function get_terms($args){return array((object)array('term_id'=>71,'name'=>'Tools'));} function get_term($id,$tax){return $id===71?(object)array('term_id'=>71,'name'=>'Tools'):null;}
function wp_update_nav_menu_item($menu_id,$id,$data){$GLOBALS['writes']++;$id=$id?:max(1000,empty($GLOBALS['items'])?1000:(max(array_keys($GLOBALS['items']))+1));$old=$GLOBALS['items'][$id]??null;$GLOBALS['items'][$id]=(object)array('ID'=>$id,'menu_id'=>$menu_id,'menu_item_parent'=>$data['menu-item-parent-id'],'menu_order'=>!empty($data['menu-item-position'])?(int)$data['menu-item-position']:($old->menu_order??1),'title'=>stripslashes($data['menu-item-title']),'type'=>$data['menu-item-type']??($old->type??'custom'),'url'=>$data['menu-item-url']??($old->url??'https://example.test/item'),'object'=>$data['menu-item-object']??($old->object??'custom'),'object_id'=>$data['menu-item-object-id']??($old->object_id??0),'data'=>$data);return $id;}
function wp_delete_post($id,$force){$GLOBALS['writes']++;$old=$GLOBALS['items'][$id]??false;unset($GLOBALS['items'][$id]);return $old;}
function add_filter($hook,$cb,$priority=10,$args=1){$GLOBALS['hooks'][$hook][]=$cb;} function add_action($hook,$cb,$priority=10,$args=1){$GLOBALS['hooks'][$hook][]=$cb;} function apply_filters($name,$value){return $value;} function current_user_can($cap){return $GLOBALS['allowed'];} function wp_die($text){throw new RuntimeException($text);}
function check_admin_referer($action,$field){if(($_POST[$field]??'')!=='nonce-'.$action)throw new RuntimeException('nonce');} function check_ajax_referer($action,$field){check_admin_referer($action,$field);}
require ( dirname( __DIR__, 3 ) . '/taxocommerce-core' ).'/includes/class-taxocommerce-core-country.php';
require ( dirname( __DIR__, 3 ) . '/taxocommerce-core' ).'/includes/class-taxocommerce-core-menu.php';
function verify($condition,$message){if(!$condition){fwrite(STDERR,'FAIL: '.$message."\n");exit(1);} $GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
$core=new TaxoCommerce_Core_Menu(new TaxoCommerce_Core_Logger()); $core->register_hooks();
foreach($GLOBALS['hooks'] as $callbacks){foreach($callbacks as $cb){verify(is_callable($cb),'public hook callback');}}
$gen=wp_update_nav_menu_item(99,0,array('menu-item-title'=>'Automatic','menu-item-parent-id'=>0));
update_post_meta($gen,TaxoCommerce_Core_Menu::GENERATED_META,1);
$manual=wp_update_nav_menu_item(99,0,array('menu-item-title'=>'Manual <safe>','menu-item-parent-id'=>$gen));
$before=$GLOBALS['writes'];ob_start();$core->render();$html=ob_get_clean();
verify($GLOBALS['writes']===$before,'view never mutates menu');
verify(strpos($html,'Gestor de menús')!==false,'unified native menu heading visible');
verify(strpos($html,'id="tc-seo-country"')===false,'native menu editor does not invent country selectors');
verify(strpos($html,'<h2>TaxoCommerce</h2>')!==false,'real WordPress menu name disclosed');

verify(substr_count($html,'value="taxocommerce_core_sync_menu"')===0,'native menu must not synchronize country hierarchy');
verify(substr_count($html,'value="taxocommerce_core_activate_menu"')===2,'activate top and bottom');
verify(substr_count($html,'value="taxocommerce_core_restore_menu"')===2,'restore top and bottom');
verify(substr_count($html,'nav-menus.php?action=edit')===2,'native editor top and bottom');
verify(strpos($html,'data-position="top"')<strpos($html,'class="tc-menu-editor"'),'actions before editor');
verify(strpos($html,'data-position="bottom"')>strpos($html,'class="tc-menu-editor"'),'actions after editor');
preg_match_all('/\bid="([^"]+)"/',$html,$matches);verify(count($matches[1])===count(array_unique($matches[1])),'unique DOM IDs');
verify(substr_count($html,'class="tc-menu-box"')===5,'four original panels plus product categories');
verify(strpos($html,'Manual &lt;safe&gt;')!==false,'escaped titles');
if(getenv('TC_MENU_HTML')){file_put_contents(getenv('TC_MENU_HTML'),$html);}
// Native WordPress edits require an explicit menu ID on every write.
$_POST['tc_menu_id']='99'; $_POST['tc_tab']='wordpress';
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
// Regression: an old menu option must not let multiple countries share one menu.
$GLOBALS['menus'][99]=(object)array('term_id'=>99,'name'=>'TaxoCommerce');
$GLOBALS['options']['taxocommerce_core_menu_id']=99;
$GLOBALS['options']['taxocommerce_core_menu_id_us']=99; // Broken legacy mapping.
verify(TaxoCommerce_Core_Menu::managed_menu_id('ES',false)===99,'Spanish legacy menu remains readable');
verify(TaxoCommerce_Core_Menu::managed_menu_id('US',false)===0,'US never reads Spanish menu from reused ID');
$us_id=TaxoCommerce_Core_Menu::managed_menu_id('US',true);
verify($us_id!==99 && $GLOBALS['menus'][$us_id]->name==='TaxoCommerce - US','US has own named menu');
verify($GLOBALS['options']['taxocommerce_core_menu_id_us']===$us_id,'US stores independent menu ID');
$_GET['tc_country']='US';
$before=$GLOBALS['writes'];ob_start();$core->render();$us_html=ob_get_clean();
verify($GLOBALS['writes']===$before,'US menu identity view does not write');
verify(strpos($us_html,'<h2>TaxoCommerce</h2>')!==false,'native screen keeps the assigned WordPress menu');
verify(strpos($us_html,'id="tc-seo-country"')===false,'native screen does not activate US SEO menu');
verify(strpos($us_html,'TaxoCommerce - ES')===false,'US identity does not claim ES menu');
unset($_GET['tc_country']);

verify($GLOBALS['options']['taxocommerce_core_menu_id']===99,'Spanish ID unchanged');
$es_id=TaxoCommerce_Core_Menu::managed_menu_id('ES',true);
verify($es_id===99 && $GLOBALS['menus'][99]->name==='TaxoCommerce - ES','ES renamed in place without losing menu');
foreach(array('FR','DE','NL') as $country){
    $menu_id=TaxoCommerce_Core_Menu::managed_menu_id($country,true);
    verify($menu_id>0 && $menu_id!==$us_id && $menu_id!==$es_id, $country.' independent ID');
    verify($GLOBALS['menus'][$menu_id]->name==='TaxoCommerce - '.$country, $country.' country name');
}
$GLOBALS['options']['taxocommerce_core_menu_id_fr']=99;
verify(TaxoCommerce_Core_Menu::managed_menu_id('FR',false)!==99,'FR ignores incorrectly reused Spanish ID');
verify(TaxoCommerce_Core_Menu::managed_menu_id('FR',true)!==99,'FR restores its own menu ID');
verify(TaxoCommerce_Core_Menu::managed_menu_id('US',false)===$us_id,'US intact after other-country sync');


// Native WordPress menus are selectable without replacing Core's country menus.
$existing_id = wp_create_nav_menu( 'Menú cabecera existente' );
$external_parent = wp_update_nav_menu_item($existing_id,0,array('menu-item-title'=>'Portada WP','menu-item-url'=>'https://example.test/portada','menu-item-type'=>'custom','menu-item-parent-id'=>0,'menu-item-position'=>1));
$external_child = wp_update_nav_menu_item($existing_id,0,array('menu-item-title'=>'Contactar','menu-item-url'=>'https://example.test/contacto','menu-item-type'=>'custom','menu-item-parent-id'=>$external_parent,'menu-item-position'=>2));
$_GET['tc_country']='ES'; $_GET['tc_menu_id']=(string)$existing_id;
$before=$GLOBALS['writes']; ob_start(); $core->render(); $loaded=ob_get_clean();
verify($GLOBALS['writes']===$before,'loading native WordPress menu never mutates menus or country mapping');
verify(strpos($loaded,'Cargar un menú de WordPress')!==false,'native menu selector present');
verify(strpos($loaded,'Menú cabecera existente')!==false,'native menu name shown');
verify(strpos($loaded,'Portada WP')!==false,'existing native item appears in editor');
verify(strpos($loaded,'value="taxocommerce_core_sync_menu"')===false,'country hierarchy cannot accidentally overwrite loaded native menu');
verify(strpos($loaded,'name="tc_menu_id" value="'.$existing_id.'"')!==false,'menu editor persists selected native menu ID');
verify(strpos($loaded,'name="item_title"')!==false,'loaded native menu item editable');
verify($GLOBALS['options']['taxocommerce_core_menu_id']===$es_id,'country managed mapping unchanged after loading foreign menu');
$_POST=array('tc_country'=>'ES','tc_menu_id'=>(string)$existing_id);
$managed_item_count=count(wp_get_nav_menu_items($es_id));
verify(TaxoCommerce_Core_Menu_Editor::target_menu_id()===$existing_id,'native target selected by POST');
verify(TaxoCommerce_Core_Menu_Editor::add_link('Nuevo enlace','https://example.test/nuevo')>0,'add to selected existing menu');
verify(count(wp_get_nav_menu_items($es_id))===$managed_item_count,'native edit does not add to country managed menu');
verify(TaxoCommerce_Core_Menu_Editor::edit_item($external_child,'Contacto actualizado','https://example.test/contacto-nuevo',0,1)===$external_child,'edit existing WordPress menu title, URL, parent and position');
verify($GLOBALS['items'][$external_child]->title==='Contacto actualizado','native title updated');
verify($GLOBALS['items'][$external_child]->menu_item_parent===0,'native hierarchy updated');
verify($GLOBALS['items'][$external_child]->url==='https://example.test/contacto-nuevo','native URL updated');
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::edit_item($external_parent,'Circular','https://example.test/', $external_child,1))===false,'moving parent under sibling after unlink is valid');
// Force a cycle in an existing tree and require rejection without writes.
$GLOBALS['items'][$external_child]->menu_item_parent=$external_parent;
$before=$GLOBALS['writes'];verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::edit_item($external_parent,'Root','https://example.test/',$external_child,1)),'circular menu parents rejected');verify($before===$GLOBALS['writes'],'cycles must never write');
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::edit_item($gen,'Automatic','https://example.test/',0,1)),'editing item from another menu rejected');
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::edit_item($external_child,'Bad link','javascript:alert(1)',0,1)),'unsafe custom URL rejected');
$_POST['tc_menu_id']='999999';
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::target_menu_id(true)),'missing native menu does not silently overwrite country menu');
$_POST=array('tc_country'=>'ES','tc_menu_id'=>'0');
$new_id=TaxoCommerce_Core_Menu_Editor::create_menu('Nuevo menú independiente');
verify(!is_wp_error($new_id) && $new_id!==$existing_id && $new_id!==$es_id,'new native menu created independently');
verify(TaxoCommerce_Core_Menu::managed_menu_id('ES',false)===$es_id,'new native menu does not replace country menu');
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::create_menu('  ')),'invalid empty native menu name rejected');
unset($_GET['tc_menu_id']);unset($_GET['tc_country']);unset($_POST['tc_menu_id']);


// Verify isolated WordPress-only tab with WooCommerce entirely absent.
$GLOBALS['woo_enabled']=false;
$_GET=array('tc_tab'=>'wordpress');$_POST=array();
$before=$GLOBALS['writes'];ob_start();$core->render();$basic=ob_get_clean();
verify($GLOBALS['writes']===$before,'WordPress tab loads without any database changes');
verify(strpos($basic,'Gestor de menús')!==false,'unified WordPress menu heading');
verify(strpos($basic,'Selecciona un menú de WordPress')!==false,'explicit menu selection');
verify(strpos($basic,'País del menú')===false,'country picker is SEO-only');
verify(strpos($basic,'value="taxocommerce_core_sync_menu"')===false,'no SEO sync in WordPress tab');
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::objects('product_cat','recent',1,'','ES',true)),'Woo taxonomy excluded');
$pages=TaxoCommerce_Core_Menu_Editor::objects('page','all',2,'','FR',true);
verify(count($pages['items'])===25 && empty($GLOBALS['last_query']['meta_query']),'native pages are not filtered by country');
$native=TaxoCommerce_Core_Menu_Editor::create_menu('Nuevo menú estándar');
verify(!is_wp_error($native),'native menu created without WooCommerce');
$_GET['tc_menu_id']=(string)$native;
$before=$GLOBALS['writes'];ob_start();$core->render();$basic_loaded=ob_get_clean();
verify($GLOBALS['writes']===$before,'opening native menu never writes');
verify(strpos($basic_loaded,'Nuevo menú estándar')!==false,'selected WordPress menu shown');
verify(substr_count($basic_loaded,'class="tc-menu-box"')===4,'normal pages posts categories and links only');
verify(strpos($basic_loaded,'value="taxocommerce_core_sync_menu"')===false,'SEO sync unavailable on native tab');
verify(strpos($basic_loaded,'name="tc_tab" value="wordpress"')!==false,'tab propagated into edit forms');
$_POST=array('tc_tab'=>'wordpress','tc_menu_id'=>(string)$native);
verify(TaxoCommerce_Core_Menu_Editor::target_menu_id(true)===$native,'valid native menu targeted');
verify(TaxoCommerce_Core_Menu_Editor::add_objects('page',array(2))===1,'add native WordPress page');
verify(TaxoCommerce_Core_Menu_Editor::add_objects('category',array(71))===1,'add native WordPress category');
verify(TaxoCommerce_Core_Menu_Editor::add_link('Enlace','https://example.test/link')>0,'add custom link');
verify(count(wp_get_nav_menu_items($native))===3,'native menu contains three items');
verify(TaxoCommerce_Core_Menu::managed_menu_id('ES',false)===$es_id,'country menu preserved');
$_POST=array('tc_tab'=>'wordpress','tc_menu_id'=>'0');$before=$GLOBALS['writes'];
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::target_menu_id(true)),'no implicit creation of SEO menu');
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::add_link('Bad','https://example.test/bad')),'cannot write to missing native menu');
verify($before===$GLOBALS['writes'],'invalid native menu cannot write');
unset($_GET['tc_tab'],$_GET['tc_menu_id']);$_POST=array();$GLOBALS['woo_enabled']=true;


// Explicit Save / Delete native WordPress menus; SEO menus remain protected.
$_GET=array('tc_tab'=>'wordpress','tc_menu_id'=>(string)$native);
$_POST=array('tc_tab'=>'wordpress','tc_menu_id'=>(string)$native);
$before=$GLOBALS['writes'];ob_start();$core->render();$save_html=ob_get_clean();
verify($GLOBALS['writes']===$before,'opening Save/Delete screen never writes');
verify(substr_count($save_html,'>Guardar menú<')===2,'save menu button visible at top and bottom');
verify(strpos($save_html,'>Eliminar menú<')!==false,'delete button for regular native menu');
verify(strpos($save_html,'name="confirm_delete" value="1" required')!==false,'delete requires a checkbox');
verify(strpos($save_html,'name="task" value="save_menu"')!==false,'save submits explicit authenticated task');
verify(substr_count($save_html,'form="tc-menu-save-form"')>=6,'menu item controls associated to the global Save form');
$values=array();
foreach(wp_get_nav_menu_items($native) as $item) {
    $values[$item->ID]=array('title'=>$item->title,'parent'=>$item->menu_item_parent,'position'=>$item->menu_order);
    if('custom'===$item->type) $values[$item->ID]['url']=$item->url;
}
verify(count($values)===3,'save test native menu has exactly three items');
$ids=array_keys($values);
$original=$GLOBALS['items'][$ids[0]]->title;
$values[$ids[0]]['title']='Página guardada';
verify($GLOBALS['items'][$ids[0]]->title===$original,'editing browser form must not write until Save submitted');
$before=$GLOBALS['writes'];
verify(TaxoCommerce_Core_Menu_Editor::save_native_menu($values,'Menú guardado')===true,'Save Menu persists all fields');
verify($GLOBALS['menus'][$native]->name==='Menú guardado','Save Menu persists native menu name');
verify($GLOBALS['items'][$ids[0]]->title==='Página guardada','Save Menu persists edited item title');
verify($GLOBALS['writes']>$before,'Save Menu performed real WordPress writes');
$before=$GLOBALS['writes'];
verify(TaxoCommerce_Core_Menu_Editor::save_native_menu($values,'Menú guardado')===true,'saving unchanged menu accepted');
verify($before===$GLOBALS['writes'],'saving unchanged menu does not write');
$cycle=$values;$cycle[$ids[0]]['parent']=$ids[1];$cycle[$ids[1]]['parent']=$ids[0];
$before=$GLOBALS['writes'];
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::save_native_menu($cycle,'Menú guardado')),'cycle rejected before writes');
verify($before===$GLOBALS['writes'],'invalid hierarchy never saves partially');
$foreign=$values;$foreign[999999]=$values[$ids[0]];
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::save_native_menu($foreign,'Menú guardado')),'foreign menu IDs rejected');
verify($before===$GLOBALS['writes'],'foreign menu IDs cannot write');
verify(TaxoCommerce_Core_Menu_Editor::protected_menu($es_id),'country managed SEO menu is protected');
$stored_legacy=TaxoCommerce_Core_Menu_Editor::create_menu('Menú renombrado fuera de Core');
$GLOBALS['options']['taxocommerce_core_menu_id_fr']=$stored_legacy;
verify(TaxoCommerce_Core_Menu_Editor::protected_menu($stored_legacy),'renamed menu referenced by a country option is protected');
unset($GLOBALS['options']['taxocommerce_core_menu_id_fr']);
$_POST['tc_menu_id']=(string)$es_id;
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::delete_native_menu(true)),'cannot delete country SEO menu from native tab');
verify(isset($GLOBALS['menus'][$es_id]),'protected SEO menu still exists');
$_POST['tc_menu_id']=(string)$native;
verify(is_wp_error(TaxoCommerce_Core_Menu_Editor::delete_native_menu(false)),'delete requires explicit confirmation');
verify(isset($GLOBALS['menus'][$native]),'no deletion without confirmation');
verify(TaxoCommerce_Core_Menu_Editor::delete_native_menu(true)===true,'confirmed native menu deletion succeeds');
verify(!isset($GLOBALS['menus'][$native]),'deleted native menu no longer exists');
verify(isset($GLOBALS['menus'][$es_id]),'SEO managed menu remains after native deletion');
$_GET=array('tc_tab'=>'wordpress','tc_menu_id'=>(string)$es_id);
$before=$GLOBALS['writes'];ob_start();$core->render();$protected_html=ob_get_clean();
verify(strpos($protected_html,'>Eliminar menú<')===false,'SEO managed menu never exposes delete button');
verify($before===$GLOBALS['writes'],'protected menu view does not mutate SEO');
$_GET=array();$_POST=array();

echo 'PASS: '.$GLOBALS['checks']." menu checks. Isolated WP stubs; not a live WordPress test.\n";
