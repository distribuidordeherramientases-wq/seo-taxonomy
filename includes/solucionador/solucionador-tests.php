<?php
/**
 * Tests funcionales de Solucionador 0.7.x.
 *
 * Contrato: FAQ humana + conocimiento consolidado de Dependiente -> dossier
 * único por product_cat -> propuesta -> Editora -> draft -> publicación humana.
 *
 * No escriben en BD ni modifican contenido.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Tests {
    private static function result($id,$name,$pass,$expected,$actual,$detail='') {
        return array(
            'id'=>$id,'name'=>$name,'pass'=>(bool)$pass,
            'expected'=>$expected,'actual'=>$actual,'detail'=>$detail,
        );
    }

    private static function profile($category_id = 77) {
        return array(
            'intent'=>'dependiente_qa_basic','key_intent'=>'dependiente_qa_basic',
            'action'=>'resolver','object'=>'taladros','condition'=>'','context'=>'',
            'category_id'=>absint($category_id),
        );
    }

    private static function scenario($coverage,$dependiente=0,$faq=0,$category_id=77,$entity_type='') {
        return SEO_Solucionador_Engine::evaluate_scenario_for_test(array(
            'profile'=>self::profile($category_id),
            'stats'=>array(
                'total'=>absint($dependiente)+absint($faq),
                'dependiente'=>absint($dependiente),
                'faq'=>absint($faq),
            ),
            'coverage'=>array(
                'status'=>$coverage,'entity_type'=>$entity_type,
                'entity_id'=>$entity_type ? 321 : 0,
                'score'=>$coverage==='uncovered' ? 0 : 0.82,
            ),
            'risks'=>array('duplication_risk'=>95,'cannibalization_risk'=>85),
            'primary_category_id'=>$category_id,
        ));
    }

    private static function source($origin,$category_id=77) {
        return array(
            'source_type'=>$origin,
            'signal_type'=>'category_editorial_source',
            'category_id'=>absint($category_id),
            'category_name'=>'Taladros',
            'source_text'=>($origin==='faq' ? 'FAQs humanas' : 'Conocimiento Dependiente') . ' para Taladros',
            'hints'=>array(
                'intent'=>'dependiente_qa_basic','action'=>'resolver',
                'object'=>'Taladros','category_id'=>absint($category_id),
            ),
            'source_meta'=>array(
                'origin'=>$origin,'category_id'=>absint($category_id),
                'category_name'=>'Taladros',
            ),
        );
    }

    public static function run() {
        $tests=array();

        $faq_profile=SEO_Solucionador_Engine::normalize_topic_for_test(
            'FAQs humanas para Taladros',self::source('faq')
        );
        $dep_profile=SEO_Solucionador_Engine::normalize_topic_for_test(
            'Conocimiento Dependiente para Taladros',self::source('dependiente')
        );
        $canonical='dependiente-qa-basic|resolver|category-77|general|general';
        $tests[]=self::result(
            1,'FAQ y Dependiente convergen en un único dossier/topic',
            (string)($faq_profile['canonical_key']??'')===$canonical
                && (string)($dep_profile['canonical_key']??'')===$canonical,
            $canonical,
            (string)($faq_profile['canonical_key']??'∅').' / '.(string)($dep_profile['canonical_key']??'∅'),
            'Las dos fuentes permanecen separadas; la unidad editorial es product_cat.'
        );

        $contract=SEO_Solucionador_Sources::editorial_source_contract();
        $tests[]=self::result(
            2,'Sólo existen dos fuentes editoriales',
            $contract===array('faq','dependiente'),
            'faq, dependiente',implode(', ',array_map('strval',(array)$contract)),
            'No existe una tercera fuente editorial mixta.'
        );

        $t=self::scenario('uncovered',0,1,77);
        $tests[]=self::result(
            3,'Una sola FAQ útil puede iniciar propuesta',
            (string)($t['decision']['action']??'')==='CREATE_POST',
            'CREATE_POST',(string)($t['decision']['action']??'∅'),
            'FAQ no necesita pass_* ni masa mínima.'
        );

        $t=self::scenario('uncovered',1,0,77);
        $tests[]=self::result(
            4,'Dependiente sin FAQ también puede iniciar propuesta',
            (string)($t['decision']['action']??'')==='CREATE_POST',
            'CREATE_POST',(string)($t['decision']['action']??'∅'),
            'Dependiente es una fuente independiente.'
        );

        $t=self::scenario('uncovered',2,2,0);
        $tests[]=self::result(
            5,'Sin product_cat demostrable se aplaza',
            (string)($t['decision']['action']??'')==='DEFER',
            'DEFER',(string)($t['decision']['action']??'∅'),
            'Nunca se inventa category_id por similitud textual.'
        );

        $t=self::scenario('partial_coverage',2,1,77,'post');
        $tests[]=self::result(
            6,'Cobertura parcial recomienda mejorar',
            (string)($t['decision']['action']??'')==='IMPROVE_POST',
            'IMPROVE_POST',(string)($t['decision']['action']??'∅'),
            'Cobertura es recomendación, no permiso para ocultar el dossier.'
        );

        $t=self::scenario('duplicate',2,1,77,'post');
        $tests[]=self::result(
            7,'Duplicación no bloquea el dossier',
            (string)($t['decision']['action']??'')==='NO_ACTION',
            'NO_ACTION',(string)($t['decision']['action']??'∅'),
            'Editora mantiene acceso y decide si reutiliza, mejora o crea.'
        );

        $both=array(
            'faq:123'=>'hash-faq',
            'dependiente:trainer:456'=>'hash-dependiente',
        );
        $faq_only=array('faq:123'=>'hash-faq');
        $hash_both=SEO_Solucionador_Dossiers::source_hash_for_test(77,$both);
        $hash_faq=SEO_Solucionador_Dossiers::source_hash_for_test(77,$faq_only);
        $tests[]=self::result(
            8,'La misma materia puede existir en FAQ y Dependiente',
            $hash_both!==$hash_faq,
            'dos item_id independientes',
            $hash_both!==$hash_faq ? 'dos items preservados' : 'deduplicado',
            'No existe deduplicación destructiva entre orígenes.'
        );

        $reviewed=array(
            'faq:123'=>'a',
            'dependiente:trainer:456'=>'b',
        );
        $current=array(
            'faq:123'=>'a2',
            'dependiente:semantic:789'=>'c',
        );
        $changes=SEO_Solucionador_Dossiers::compare_item_hashes_for_test($current,$reviewed);
        $tests[]=self::result(
            9,'Detecta NUEVO / MODIFICADO / RETIRADO',
            $changes['new']===array('dependiente:semantic:789')
                && $changes['modified']===array('faq:123')
                && $changes['retired']===array('dependiente:trainer:456'),
            '1 nuevo, 1 modificado, 1 retirado',
            count($changes['new']).' / '.count($changes['modified']).' / '.count($changes['retired']),
            'La revisión de fuentes no sobrescribe el post.'
        );

        $hash_order_a=SEO_Solucionador_Dossiers::source_hash_for_test(77,array(
            'faq:1'=>'a','dependiente:trainer:2'=>'b','dependiente:semantic:3'=>'c'
        ));
        $hash_order_b=SEO_Solucionador_Dossiers::source_hash_for_test(77,array(
            'dependiente:semantic:3'=>'c','dependiente:trainer:2'=>'b','faq:1'=>'a'
        ));
        $tests[]=self::result(
            10,'Dos ejecuciones sin cambios mantienen source_hash',
            $hash_order_a===$hash_order_b,
            'hash estable',$hash_order_a===$hash_order_b ? 'estable' : 'inestable',
            'El orden de lectura no altera la huella.'
        );

        $legacy=SEO_Solucionador_Dossiers::source_hash_for_test(77,array(
            'dependiente:2'=>'b'
        ));
        $canonical_hash=SEO_Solucionador_Dossiers::source_hash_for_test(77,array(
            'dependiente:trainer:2'=>'b'
        ));
        $tests[]=self::result(
            11,'Migración de claves antiguas no genera falsas novedades',
            $legacy===$canonical_hash,
            'hash idéntico',$legacy===$canonical_hash ? 'compatible' : 'cambio falso',
            'dependiente:ID se normaliza a dependiente:trainer:ID.'
        );

        $hash_active=SEO_Solucionador_Dossiers::source_hash_for_test(77,array(
            'faq:1'=>'a','dependiente:trainer:2'=>'b'
        ));
        $hash_deactivated=SEO_Solucionador_Dossiers::source_hash_for_test(77,array(
            'dependiente:trainer:2'=>'b'
        ));
        $tests[]=self::result(
            12,'Desactivar una FAQ cambia source_hash',
            $hash_active!==$hash_deactivated,
            'hash diferente',$hash_active!==$hash_deactivated ? 'diferente' : 'igual',
            'La FAQ retirada debe producir NEEDS_UPDATE tras reconciliar el carril.'
        );

        $provider=class_exists('SEO_Dependiente_Editorial_Knowledge')
            && method_exists('SEO_Dependiente_Editorial_Knowledge','batch')
            && method_exists('SEO_Dependiente_Editorial_Knowledge','details')
            && method_exists('SEO_Dependiente_Editorial_Knowledge','signature')
            && method_exists('SEO_Dependiente_Editorial_Knowledge','inventory');
        $tests[]=self::result(
            13,'Dependiente tiene proveedor editorial consolidado',
            $provider,
            'batch + details + signature + inventory',
            $provider ? 'API disponible' : 'API incompleta',
            'Solucionador no necesita conocer cada tabla interna ni search_log.'
        );

        $policy=SEO_Solucionador_Dossiers::EDITORIAL_POLICY_VERSION;
        $tests[]=self::result(
            14,'La política incluye conocimiento consolidado de Dependiente',
            $policy==='v6-faq-plus-dependiente-provider',
            'v6-faq-plus-dependiente-provider',$policy,
            'Incluye trainer y reglas semánticas consolidadas cuando tienen categoría demostrable.'
        );

        $post_contract=function_exists('seo_post_editor_set_public_content_role')
            && class_exists('SEO_Solucionador_Posts')
            && method_exists('SEO_Solucionador_Posts','sync_category_post');
        $tests[]=self::result(
            15,'Draft y publicado permanecen bajo control humano',
            $post_contract,
            'sync sin publicación automática',
            $post_contract ? 'contrato disponible' : 'contrato incompleto',
            'Los cambios sólo generan NEEDS_UPDATE/revisión.'
        );

        $export_contract=defined('SEO_Solucionador_Export::SCHEMA')
            && SEO_Solucionador_Export::SCHEMA==='seo-solucionador-export-v6';
        $tests[]=self::result(
            16,'Export verificable por fuente',
            $export_contract,
            'seo-solucionador-export-v6',
            defined('SEO_Solucionador_Export::SCHEMA') ? SEO_Solucionador_Export::SCHEMA : 'sin schema',
            'Cada dossier expone items.faq[] e items.dependiente[].'
        );

        $independent=!method_exists('SEO_Solucionador_Engine','require_ingeniero')
            && !method_exists('SEO_Solucionador_Engine','require_comparador');
        $tests[]=self::result(
            17,'Ingeniero y Comparador no son requisitos',
            $independent,
            'sin dependencia obligatoria',
            $independent ? 'independiente' : 'dependencia detectada',
            'Las únicas fuentes de conocimiento son FAQ y Dependiente.'
        );

        $tests[]=self::result(
            18,'Una pasada sólo se cierra con firma estable',
            SEO_Solucionador_Dossiers::scan_pass_is_stable_for_test('firma-a','firma-a')
                && !SEO_Solucionador_Dossiers::scan_pass_is_stable_for_test('firma-a','firma-b'),
            'misma firma=true; firma distinta=false',
            SEO_Solucionador_Dossiers::scan_pass_is_stable_for_test('firma-a','firma-a') ? 'comparador activo' : 'comparador incorrecto',
            'Si FAQ o Dependiente cambian detrás del cursor, se repite sólo ese carril sin vaciar el snapshot vigente.'
        );

        $batch_api=method_exists('SEO_Solucionador_Dossiers','scan_batch')
            && method_exists('SEO_Solucionador_Dossiers','migrate_state')
            && method_exists('SEO_Solucionador_Dossiers','item_changes');
        $tests[]=self::result(
            19,'Procesamiento incremental y migrable',
            $batch_api,
            'scan_batch + migrate_state + item_changes',
            $batch_api ? 'API disponible' : 'API incompleta',
            'FAQ y Dependiente mantienen progreso independiente; los rescans conservan el último snapshot hasta una pasada estable.'
        );

        $passed=count(array_filter($tests,static function($row){return !empty($row['pass']);}));
        return array(
            'passed'=>$passed,'failed'=>count($tests)-$passed,'total'=>count($tests),
            'ok'=>$passed===count($tests),'tests'=>$tests,'ran_at'=>current_time('mysql'),
        );
    }
}
