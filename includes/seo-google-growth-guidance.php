<?php
if (!defined('ABSPATH')) { exit; }
if (!defined('SEO_GOOGLE_GROWTH_GUIDANCE_VERSION')) define('SEO_GOOGLE_GROWTH_GUIDANCE_VERSION','1.0.0');

function seo_google_growth_tokens($text) {
    $n=function_exists('seo_google_trends_normalize')?seo_google_trends_normalize($text):sanitize_title($text);
    $stop=array('de','del','la','las','el','los','para','y','en','con','sin','profesional','profesionales','industrial','industriales');
    return array_values(array_filter(explode(' ',$n),fn($t)=>strlen($t)>2&&!in_array($t,$stop,true)));
}
function seo_google_growth_similarity($a,$b) {
    $ta=seo_google_growth_tokens($a); $tb=seo_google_growth_tokens($b); if(!$ta||!$tb)return 0;
    $i=count(array_intersect($ta,$tb)); return $i/max(1,min(count($ta),count($tb)));
}
function seo_google_growth_trends_match($label,array $market) {
    $best=null;$bestSim=0;
    foreach($market as $m){$sim=seo_google_growth_similarity($label,$m['query']); foreach((array)$m['seeds'] as $seed)$sim=max($sim,seo_google_growth_similarity($label,$seed)*0.92); if($sim>$bestSim){$bestSim=$sim;$best=$m;}}
    if($bestSim<0.45)return null; $best['similarity']=$bestSim; return $best;
}
function seo_google_growth_action($item,$trend) {
    $strategy=$item['strategy']['primary']??'VALIDAR'; $products=(int)($item['products']??0); $position=(float)($item['position']??0);
    $marketScore=$trend?(float)$trend['score']:0; $scScore=(float)($item['score']??0);
    $catalogGap = $products<=0?90:($products<=5?80:($products<=15?60:($products<=50?35:15)));
    if (($item['kind']??'')==='Intencion' && $strategy==='NUEVA_FAMILIA') $catalogGap=90;
    $seoNeed = $position>=11 ? min(100,35+min(60,$position)) : ($position>0?25:45);
    $marketWeight=$trend?0.30:0.08; $priority=(int)round($scScore*0.38+$marketScore*$marketWeight+$catalogGap*0.22+$seoNeed*0.10+($trend?0:22));
    $priority=max(1,min(100,$priority));
    if(!$trend) $confidence='MEDIA'; elseif(($trend['breakout']??false)||$trend['score']>=75) $confidence='ALTA'; else $confidence='MEDIA-ALTA';
    if($strategy==='SEO' || ($products>40 && $catalogGap<40)) $action='POTENCIAR SEO, NO SURTIDO';
    elseif($strategy==='PROFUNDIDAD') $action='AÑADIR VARIANTES / PROFUNDIDAD';
    elseif($strategy==='AMPLITUD') $action='AMPLIAR CATEGORÍAS CERCANAS';
    elseif($strategy==='NUEVA_FAMILIA') $action='ESTUDIAR NUEVA CATEGORÍA';
    elseif($strategy==='MAPEO') $action='MAPEAR Y DESPUÉS AMPLIAR SI FALTA';
    else $action='VALIDAR OPORTUNIDAD';
    return array('priority'=>$priority,'action'=>$action,'confidence'=>$confidence,'catalog_gap'=>$catalogGap,'seo_need'=>$seoNeed,'market_score'=>(int)round($marketScore),'search_score'=>(int)round($scScore));
}
function seo_google_growth_get_guidance($days=60,$limit=40) {
    $settings=function_exists('seo_google_get_settings')?seo_google_get_settings():array(); if(empty($settings['property_id'])||!function_exists('seo_google_demand_get_catalog_guidance'))return array();
    $base=seo_google_demand_get_catalog_guidance($settings['property_id'],$days,2,max(50,$limit)); $market=function_exists('seo_google_trends_market_summary')?seo_google_trends_market_summary(500):array();
    $rows=array(); foreach((array)($base['items']??array()) as $item){ if(($item['catalog_relevance']??'catalog')==='corporate')continue; $trend=seo_google_growth_trends_match($item['label'],$market); $decision=seo_google_growth_action($item,$trend); $rows[]=array('item'=>$item,'trend'=>$trend,'decision'=>$decision); }
    // Trends signals that do not match current guidance become discovery candidates.
    foreach($market as $m){$matched=false;foreach($rows as $r){if($r['trend']&&$r['trend']['query']===$m['query']){$matched=true;break;}}if($matched)continue; if($m['score']<55)continue; $rows[]=array('item'=>array('kind'=>'Mercado','label'=>$m['query'],'score'=>20,'strategy'=>array('primary'=>'NUEVA_FAMILIA'),'products'=>0,'position'=>0,'dimension_labels'=>array(),'evidence'=>array()),'trend'=>$m,'decision'=>array('priority'=>(int)min(88,35+$m['score']*0.55),'action'=>'DESCUBRIMIENTO: COMPARAR CON CATÁLOGO','confidence'=>$m['score']>=75?'MEDIA-ALTA':'MEDIA','catalog_gap'=>95,'seo_need'=>40,'market_score'=>(int)$m['score'],'search_score'=>0));}
    usort($rows,fn($a,$b)=>$b['decision']['priority']<=>$a['decision']['priority']); return array_slice($rows,0,max(10,min(100,$limit)));
}

function seo_google_render_growth_guidance() {
    if (!function_exists('seo_google_analysis_ready') || !seo_google_analysis_ready()) return;
    $days=isset($_GET['growth_days'])?absint($_GET['growth_days']):60; $days=in_array($days,array(28,60,90),true)?$days:60; $rows=seo_google_growth_get_guidance($days,50); $trendCount=function_exists('seo_google_trends_get_signals')?count(seo_google_trends_get_signals(5000)):0;
    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:20px;border-radius:6px;margin-bottom:20px;"><h3 style="margin-top:0;">Qué potenciar</h3><p>Este es el informe de decisión. Cruza <strong>catálogo actual + Search Console + Google Trends</strong> y separa ampliar surtido, ampliar categorías cercanas, crear una categoría nueva o trabajar SEO.</p><p><code>V'.esc_html(SEO_GOOGLE_GROWTH_GUIDANCE_VERSION).'</code> · Señales Trends almacenadas: <strong>'.number_format_i18n($trendCount).'</strong>.</p><form method="get"><input type="hidden" name="page" value="seo-reports"><input type="hidden" name="tab" value="google_intelligence"><input type="hidden" name="google_view" value="growth_guidance"><label><strong>Horizonte Search Console</strong> <select name="growth_days">'; foreach(array(28,60,90) as $d) echo '<option value="'.$d.'" '.selected($days,$d,false).'>'.$d.' días</option>'; echo '</select></label> '; submit_button('Actualizar','secondary','submit',false); echo '</form></div>';
    if(!$trendCount) echo '<div class="notice notice-warning inline"><p><strong>Aún no hay datos de Trends.</strong> El ranking se apoya sobre Search Console + catálogo. Importa Trends en <a href="'.esc_url(seo_google_admin_url('trends_market')).'">Mercado Google (Trends)</a> para añadir la visión externa.</p></div>';
    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;border-radius:6px;overflow:auto;"><h3 style="margin-top:0;">Lista priorizada</h3><table class="widefat striped"><thead><tr><th>Prioridad</th><th>Nivel / área</th><th>Qué hacer</th><th>Search Console</th><th>Trends</th><th>Catálogo</th><th>Dirección</th><th>Confianza</th></tr></thead><tbody>';
    foreach($rows as $r){$i=$r['item'];$d=$r['decision'];$t=$r['trend']; echo '<tr><td><strong style="font-size:18px;">'.absint($d['priority']).'</strong>/100</td><td><small>'.esc_html($i['kind']).'</small><br><strong>'.esc_html($i['label']).'</strong></td><td><strong>'.esc_html($d['action']).'</strong><br><small>Estrategia base: '.esc_html($i['strategy']['primary']??'VALIDAR').'</small></td><td>'.absint($d['search_score']).'/100'; if(isset($i['impressions']))echo '<br><small>'.number_format_i18n((float)$i['impressions'],0).' imp. · pos. '.($i['position']?number_format_i18n((float)$i['position'],1):'—').'</small>'; echo '</td><td>'.absint($d['market_score']).'/100'; if($t)echo '<br><small>'.esc_html($t['query']).($t['breakout']?' · BREAKOUT':(' · +'.number_format_i18n((float)$t['max_growth'],0).'%')).'</small>';else echo '<br><small>Sin señal importada</small>'; echo '</td><td>Hueco '.absint($d['catalog_gap']).'/100'; if(isset($i['products'])&&$i['products']!==null)echo '<br><small>'.number_format_i18n((int)$i['products']).' productos</small>'; echo '</td><td>'; $dirs=(array)($i['dimension_labels']??array()); echo $dirs?esc_html(implode(' · ',$dirs)):'Descomponer consultas / familias cercanas'; echo '</td><td>'.esc_html($d['confidence']).'</td></tr>'; }
    echo '</tbody></table></div>';
    echo '<div style="background:#f6f7f7;border-left:4px solid #2271b1;padding:14px;margin-top:20px;"><strong>Regla de lectura</strong><br>Demanda externa fuerte + Search Console acercándose + poco catálogo → ampliar. Demanda fuerte + catálogo profundo + posición mala → SEO. Demanda externa fuerte sin categoría → estudiar nueva categoría; varias categorías coherentes bajo el mismo tema pueden justificar un nuevo hub secundario/primario, pero esa promoción jerárquica debe exigir varias señales antes de automatizarse.</div>';
}
