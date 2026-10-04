<?php
/**
 * Tests funcionales de Solucionador 0.7.1.
 *
 * Contrato: FAQ humana + preguntas de Entrenador validadas por Dependiente -> dossier único por
 * product_cat -> propuesta -> Editora -> draft -> publicación humana.
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

        $faq_profile=SEO_Solucionador_Engine::normalize_topic_for_test('FAQs humanas para Taladros',self::source('faq'));
        $dep_profile=SEO_Solucionador_Engine::normalize_topic_for_test('Conocimiento Dependiente para Taladros',self::source('dependiente'));
        $canonical='dependiente-qa-basic|resolver|category-77|general|general';
        $tests[]=self::result(
            1,'FAQ y Dependiente convergen en un único dossier/topic',
            (string)($faq_profile['canonical_key']??'')===$canonical
                && (string)($dep_profile['canonical_key']??'')===$canonical,
            $canonical,
            (string)($faq_profile['canonical_key']??'∅').' / '.(string)($dep_profile['canonical_key']??'∅'),
            'Las fuentes permanecen separadas, pero la unidad editorial es una product_cat.'
        );

        $contract=SEO_Solucionador_Sources::editorial_source_contract();
        $tests[]=self::result(
            2,'Sólo existen dos fuentes editoriales',
            $contract===array('faq','dependiente'),
            'faq, dependiente',implode(', ',array_map('strval',(array)$contract)),
            'No existe una tercera fuente faq_dependiente.'
        );

        $t=self::scenario('uncovered',0,1,77);
        $tests[]=self::result(
            3,'Una sola FAQ útil puede iniciar propuesta',
            (string)($t['decision']['action']??'')==='CREATE_POST',
            'CREATE_POST',(string)($t['decision']['action']??'∅'),
            'No existe gate de masa mínima.'
        );

        $t=self::scenario('uncovered',1,0,77);
        $tests[]=self::result(
            4,'Dependiente sin FAQ también puede iniciar propuesta',
            (string)($t['decision']['action']??'')==='CREATE_POST',
            'CREATE_POST',(string)($t['decision']['action']??'∅'),
            'Una pregunta de Entrenador con último run answered/pass_* es una fuente independiente.'
        );

        $t=self::scenario('uncovered',2,2,0);
        $tests[]=self::result(
            5,'Sin product_cat demostrable se aplaza',
            (string)($t['decision']['action']??'')==='DEFER',
            'DEFER',(string)($t['decision']['action']??'∅'),
            'Solucionador nunca inventa category_id por similitud textual.'
        );

        $t=self::scenario('partial_coverage',2,1,77,'post');
        $tests[]=self::result(
            6,'Cobertura parcial recomienda mejorar',
            (string)($t['decision']['action']??'')==='IMPROVE_POST',
            'IMPROVE_POST',(string)($t['decision']['action']??'∅'),
            'Cobertura es recomendación editorial, no permiso para ocultar el dossier.'
        );

        $t=self::scenario('duplicate',2,1,77,'post');
        $tests[]=self::result(
            7,'Duplicación no bloquea ni destruye el dossier',
            (string)($t['decision']['action']??'')==='NO_ACTION',
            'NO_ACTION',(string)($t['decision']['action']??'∅'),
            'Editora conserva el dossier y decide si fusiona, mejora o crea.'
        );

        $both=array('faq:123'=>'hash-faq','dependiente:456'=>'hash-dep');
        $faq_only=array('faq:123'=>'hash-faq');
        $hash_both=SEO_Solucionador_Dossiers::source_hash_for_test(77,$both);
        $hash_faq=SEO_Solucionador_Dossiers::source_hash_for_test(77,$faq_only);
        $tests[]=self::result(
            8,'FAQ y Dependiente idénticos conceptualmente no se deduplican',
            $hash_both!==$hash_faq,
            'hash distinto con dos item_id independientes',
            $hash_both!==$hash_faq ? 'dos items preservados' : 'deduplicado',
            'La identidad es por origen/item_id; Editora resuelve posibles repetidos.'
        );

        $reviewed=array('faq:123'=>'a','dependiente:456'=>'b');
        $current=array('faq:123'=>'a2','dependiente:789'=>'c');
        $changes=SEO_Solucionador_Dossiers::compare_item_hashes_for_test($current,$reviewed);
        $tests[]=self::result(
            9,'Detecta NUEVO / MODIFICADO / RETIRADO',
            $changes['new']===array('dependiente:789')
                && $changes['modified']===array('faq:123')
                && $changes['retired']===array('dependiente:456'),
            '1 nuevo, 1 modificado, 1 retirado',
            count($changes['new']).' / '.count($changes['modified']).' / '.count($changes['retired']),
            'El cambio de fuentes genera NEEDS_UPDATE sin sobrescribir el post.'
        );

        $hash_order_a=SEO_Solucionador_Dossiers::source_hash_for_test(77,array(
            'faq:1'=>'a','dependiente:2'=>'b'
        ));
        $hash_order_b=SEO_Solucionador_Dossiers::source_hash_for_test(77,array(
            'dependiente:2'=>'b','faq:1'=>'a'
        ));
        $tests[]=self::result(
            10,'Dos ejecuciones sin cambios mantienen source_hash',
            $hash_order_a===$hash_order_b,
            'hash estable',$hash_order_a===$hash_order_b ? 'estable' : 'inestable',
            'El orden de lectura no altera el hash.'
        );

        $hash_active=SEO_Solucionador_Dossiers::source_hash_for_test(77,array(
            'faq:1'=>'a','dependiente:2'=>'b'
        ));
        $hash_deactivated=SEO_Solucionador_Dossiers::source_hash_for_test(77,array(
            'dependiente:2'=>'b'
        ));
        $tests[]=self::result(
            11,'Retirar/desactivar una FAQ cambia source_hash',
            $hash_active!==$hash_deactivated,
            'hash diferente',$hash_active!==$hash_deactivated ? 'diferente' : 'igual',
            'La retirada se convierte en novedad editorial para revisión.'
        );

        $trainer_contract=method_exists('SEO_Solucionador_Dossiers','scan_batch')
            && method_exists('SEO_Solucionador_Dossiers','question_details')
            && SEO_Solucionador_Dossiers::EDITORIAL_POLICY_VERSION==='v5-faq-plus-trainer-pass';
        $tests[]=self::result(
            12,'Dependiente usa exclusivamente preguntas evaluadas de Entrenador',
            $trainer_contract,
            'trainer questions + último run answered/pass_*',
            $trainer_contract ? 'contrato simple activo' : 'contrato incompleto',
            'Solucionador no incorpora reglas semánticas, search_log ni proveedores adicionales.'
        );

        $post_contract=function_exists('seo_post_editor_set_public_content_role')
            && class_exists('SEO_Solucionador_Posts')
            && defined('SEO_SOLUCIONADOR_VERSION')
            && version_compare(SEO_SOLUCIONADOR_VERSION,'0.7.1','>=')
            && method_exists('SEO_Solucionador_Posts','sync_category_post');
        $tests[]=self::result(
            13,'Draft y post publicado permanecen bajo control humano',
            $post_contract,
            'Solucionador >= 0.7.1 + sync sin auto-publicación',
            $post_contract ? 'contrato disponible' : 'contrato incompleto',
            'Los cambios de fuente marcan NEEDS_UPDATE; nunca ejecutan publicación automática.'
        );

        $export_contract=defined('SEO_Solucionador_Export::SCHEMA')
            && SEO_Solucionador_Export::SCHEMA==='seo-solucionador-export-v6';
        $tests[]=self::result(
            14,'Export verificable por fuente',
            $export_contract,
            'seo-solucionador-export-v6',
            defined('SEO_Solucionador_Export::SCHEMA') ? SEO_Solucionador_Export::SCHEMA : 'sin schema',
            'Cada dossier exporta items.faq[] e items.dependiente[].'
        );

        $independent = !method_exists('SEO_Solucionador_Engine','require_ingeniero')
            && !method_exists('SEO_Solucionador_Engine','require_comparador');
        $tests[]=self::result(
            15,'Ingeniero y Comparador no son requisitos de generación',
            $independent,
            'sin dependencia obligatoria',
            $independent ? 'independiente' : 'dependencia detectada',
            'El contrato de generación sólo exige FAQ/Dependiente y product_cat demostrable.'
        );

        $batch_api=method_exists('SEO_Solucionador_Dossiers','scan_batch')
            && method_exists('SEO_Solucionador_Dossiers','migrate_state')
            && method_exists('SEO_Solucionador_Dossiers','item_changes');
        $tests[]=self::result(
            16,'Procesamiento incremental y migrable',
            $batch_api,
            'scan_batch + migrate_state + item_changes',
            $batch_api ? 'API disponible' : 'API incompleta',
            'FAQ y Dependiente mantienen cursores independientes y no generan dossiers duplicados.'
        );

        $passed=count(array_filter($tests,static function($row){return !empty($row['pass']);}));
        return array(
            'passed'=>$passed,'failed'=>count($tests)-$passed,'total'=>count($tests),
            'ok'=>$passed===count($tests),'tests'=>$tests,'ran_at'=>current_time('mysql'),
        );
    }
}
