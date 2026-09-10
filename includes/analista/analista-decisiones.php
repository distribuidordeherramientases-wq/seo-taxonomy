<?php
/** Motor ejecutivo: convierte señales dispersas en una lista corta de decisiones. */
defined('ABSPATH') || exit;

if (!function_exists('seo_analista_semrush_opportunities')) {
    function seo_analista_semrush_opportunities($limit = 20) {
        $snapshot = seo_analista_semrush_snapshot();
        $rows = (array)($snapshot['rows'] ?? array());
        if (!$rows) return array();
        $own = (string)($snapshot['own_domain'] ?? preg_replace('/^www\./','',strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST))));
        $by_keyword = array();
        foreach ($rows as $row) {
            $k = seo_analista_normalize_text($row['keyword'] ?? ''); if ($k==='') continue;
            if (!isset($by_keyword[$k])) $by_keyword[$k]=array('keyword'=>$row['keyword'],'volume'=>(float)($row['volume']??0),'difficulty'=>(float)($row['difficulty']??0),'own'=>0,'best_competitor'=>null);
            if (($row['domain']??'') === $own) $by_keyword[$k]['own'] = (float)($row['position']??0);
            else {
                $pos=(float)($row['position']??0);
                if ($pos>0 && (!$by_keyword[$k]['best_competitor'] || $pos < $by_keyword[$k]['best_competitor']['position'])) $by_keyword[$k]['best_competitor']=array('domain'=>$row['domain'],'position'=>$pos);
            }
        }
        $out=array();
        foreach ($by_keyword as $item) {
            if (!$item['best_competitor']) continue;
            $own=(float)$item['own']; $comp=(float)$item['best_competitor']['position']; $volume=(float)$item['volume']; $kd=(float)$item['difficulty'];
            if ($own>0 && $own<=70 && $comp<$own) {
                $priority=(int)min(100,45 + min(25,log(1+$volume)*4) + max(0,20-min(20,$kd/3)) + (($own<=30)?10:0));
                $out[]=array('priority'=>$priority,'action'=>'POTENCIAR_SEO','action_label'=>'Superar competidor','topic'=>$item['keyword'],'reason'=>'Ya aparecemos, pero '.$item['best_competitor']['domain'].' está en posición '.number_format_i18n($comp,0).'.','source'=>'SEMrush','detail'=>'Posición propia '.number_format_i18n($own,0).' · volumen '.number_format_i18n($volume,0).' · KD '.number_format_i18n($kd,0));
            } elseif ($own<=0 && $comp<=20 && $volume>=50) {
                $priority=(int)min(95,40 + min(30,log(1+$volume)*4) + max(0,20-min(20,$kd/3)));
                $out[]=array('priority'=>$priority,'action'=>'INVESTIGAR_CATALOGO','action_label'=>'Estudiar hueco frente a competidores','topic'=>$item['keyword'],'reason'=>$item['best_competitor']['domain'].' ya capta esta demanda y nosotros no figuramos en el CSV importado.','source'=>'SEMrush','detail'=>'Competidor pos. '.number_format_i18n($comp,0).' · volumen '.number_format_i18n($volume,0).' · KD '.number_format_i18n($kd,0));
            }
        }
        usort($out, static fn($a,$b)=>(int)$b['priority']<=>(int)$a['priority']);
        return array_slice($out,0,max(5,min(50,absint($limit))));
    }
}

if (!function_exists('seo_analista_decision_plan')) {
    function seo_analista_decision_plan($days = 28, $limit = 15) {
        $days=seo_analista_days($days); $all=array();
        if (function_exists('seo_google_opportunity_build')) {
            $payload=(array)seo_google_opportunity_build($days,false);
            foreach ((array)($payload['rows']??array()) as $row) {
                if ((int)($row['priority']??0)<45) continue;
                $all[]=array(
                    'priority'=>(int)($row['priority']??0),
                    'action'=>(string)($row['action']??''),
                    'action_label'=>(string)($row['action_label']??''),
                    'topic'=>(string)($row['topic']??''),
                    'reason'=>(string)($row['reason']??''),
                    'source'=>implode(' + ',(array)($row['sources']??array())),
                    'detail'=>implode(' · ',array_slice((array)($row['evidence']??array()),0,2)),
                );
            }
        }
        $search=seo_analista_internal_search_snapshot($days,30);
        foreach (array_slice((array)$search['gaps'],0,10) as $row) {
            $count=(int)($row['searches']??0); $zero=(int)($row['zero_count']??0);
            $priority=min(95,55 + min(25,$count*5) + min(15,$zero*4));
            $all[]=array('priority'=>$priority,'action'=>'INVESTIGAR_PRODUCTO','action_label'=>'Cubrir búsqueda interna','topic'=>(string)($row['search_term']??''),'reason'=>'Los visitantes lo buscan dentro de la tienda y encuentran pocos o ningún resultado.','source'=>'Búsqueda interna','detail'=>number_format_i18n($count).' búsquedas · '.number_format_i18n((float)($row['avg_results']??0),1).' resultados medios');
        }
        foreach (seo_analista_semrush_opportunities(15) as $row) $all[]=$row;
        $suppliers=seo_analista_supplier_snapshot(30);
        foreach (array_slice((array)$suppliers['issues'],0,5) as $issue) {
            $all[]=array('priority'=>60,'action'=>'REVISAR_PROVEEDOR','action_label'=>'Revisar proveedor','topic'=>(string)$issue['provider'],'reason'=>implode('; ',(array)$issue['problems']),'source'=>'Proveedores','detail'=>'La calidad/frescura del feed afecta al catálogo y a las decisiones del Analista.');
        }
        usort($all,static function($a,$b){$d=(int)$b['priority']<=>(int)$a['priority']; return $d?:strcmp((string)$a['topic'],(string)$b['topic']);});
        $dedup=array(); $seen=array();
        foreach ($all as $row) {
            $key=seo_analista_normalize_text(($row['action']??'').' '.($row['topic']??'')); if ($key===''||isset($seen[$key])) continue;
            $seen[$key]=true; $dedup[]=$row; if(count($dedup)>=max(5,min(50,absint($limit)))) break;
        }
        return $dedup;
    }
}

if (!function_exists('seo_analista_plan_summary')) {
    function seo_analista_plan_summary(array $plan) {
        $out=array('catalogo'=>0,'contenido'=>0,'seo'=>0,'proveedores'=>0,'competencia'=>0);
        foreach($plan as $row){$a=(string)($row['action']??''); $src=(string)($row['source']??'');
            if(strpos($a,'POST')!==false||strpos($a,'LANDING')!==false||strpos($a,'CONTENIDO')!==false) $out['contenido']++;
            elseif(strpos($a,'PRODUCT')!==false||strpos($a,'CATALOG')!==false||strpos($a,'CATEGORIA')!==false) $out['catalogo']++;
            elseif($a==='REVISAR_PROVEEDOR') $out['proveedores']++;
            else $out['seo']++;
            if(stripos($src,'semrush')!==false) $out['competencia']++;
        }
        return $out;
    }
}
