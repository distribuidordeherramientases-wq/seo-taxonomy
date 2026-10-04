<?php
/**
 * Tests funcionales de Solucionador v0.7.
 *
 * FAQ + Academia/Entrenador -> dossier mixto category-first ->
 * cobertura -> brief -> Editora -> post dependiente_qa_basic.
 *
 * No escriben en BD ni modifican contenido.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Tests {
    private static function result($id,$name,$pass,$expected,$actual,$detail='') {
        return array(
            'id'=>$id,
            'name'=>$name,
            'pass'=>(bool)$pass,
            'expected'=>$expected,
            'actual'=>$actual,
            'detail'=>$detail,
        );
    }

    private static function category_profile($category_id = 77) {
        return array(
            'intent'=>'dependiente_qa_basic',
            'key_intent'=>'dependiente_qa_basic',
            'action'=>'resolver',
            'object'=>'taladros',
            'condition'=>'',
            'context'=>'',
            'category_id'=>absint($category_id),
        );
    }

    private static function scenario($coverage,$dependiente=5,$faq=0,$category_id=77,$entity_type='',$risks=array()) {
        return SEO_Solucionador_Engine::evaluate_scenario_for_test(array(
            'profile'=>self::category_profile($category_id),
            'stats'=>array(
                'total'=>absint($dependiente)+absint($faq),
                'dependiente'=>absint($dependiente),
                'faq'=>absint($faq),
            ),
            'coverage'=>array(
                'status'=>$coverage,
                'entity_type'=>$entity_type,
                'entity_id'=>$entity_type ? 321 : 0,
                'score'=>$coverage==='uncovered' ? 0 : 0.82,
            ),
            'risks'=>wp_parse_args($risks,array('duplication_risk'=>10,'cannibalization_risk'=>10)),
            'primary_category_id'=>$category_id,
        ));
    }

    public static function run() {
        $tests=array();

        $category_id=77;
        $category_name='Taladros';
        $source=array(
            'source_type'=>'dependiente',
            'category_id'=>$category_id,
            'category_name'=>$category_name,
            'source_meta'=>array(
                'dependiente_channel'=>'faq_dependiente_dossier',
                'editorial_family'=>'dependiente_qa_basic',
                'category_id'=>$category_id,
                'question_count'=>17,
                'dependiente_count'=>7,
                'faq_count'=>10,
                'origins'=>array('dependiente'=>7,'faq'=>10),
            ),
            'hints'=>array(
                'intent'=>'dependiente_qa_basic',
                'action'=>'resolver',
                'object'=>$category_name,
                'category_id'=>$category_id,
            ),
        );
        $profile=SEO_Solucionador_Engine::normalize_topic_for_test(
            'Dossier editorial sobre Taladros: FAQs manuales y conocimiento aprendido por Dependiente.',
            $source
        );
        $tests[]=self::result(
            1,
            'Un dossier canónico por categoría con dos orígenes',
            (string)($profile['intent']??'')==='dependiente_qa_basic'
                && absint($profile['category_id']??0)===$category_id
                && (string)($profile['canonical_key']??'')==='dependiente-qa-basic|resolver|category-77|general|general',
            'dependiente_qa_basic · category 77 · canonical estable',
            (string)($profile['intent']??'∅').' · '.absint($profile['category_id']??0).' · '.(string)($profile['canonical_key']??'∅'),
            'FAQ y Dependiente convergen en el mismo dossier; no se crea un topic por fuente.'
        );

        $t2=self::scenario('uncovered',0,2,77);
        $tests[]=self::result(
            2,
            'FAQ válida sin aprendizaje de Dependiente',
            (string)($t2['decision']['action']??'')==='CREATE_POST',
            'CREATE_POST',
            (string)($t2['decision']['action']??'∅'),
            'Una FAQ manual puede sostener una propuesta editorial aunque Dependiente no la haya aprendido; la densidad es sólo indicador.'
        );

        $t3=self::scenario('uncovered',3,3,0);
        $tests[]=self::result(
            3,
            'Sin categoría demostrable',
            (string)($t3['decision']['action']??'')==='DEFER',
            'DEFER',
            (string)($t3['decision']['action']??'∅'),
            'Material sin product_cat demostrable no crea URL.'
        );

        $t4=self::scenario('uncovered',5,4,77);
        $tests[]=self::result(
            4,
            'Dossier mixto sin cobertura',
            (string)($t4['decision']['action']??'')==='CREATE_POST',
            'CREATE_POST',
            (string)($t4['decision']['action']??'∅'),
            'FAQ + Dependiente asociados a product_cat y sin cobertura permiten proponer un borrador.'
        );

        $t5=self::scenario('covered',4,4,77,'post');
        $tests[]=self::result(
            5,
            'Cobertura suficiente',
            (string)($t5['decision']['action']??'')==='NO_ACTION',
            'NO_ACTION',
            (string)($t5['decision']['action']??'∅'),
            'Nunca se crea un segundo post cuando la intención ya está cubierta.'
        );

        $t6=self::scenario('partial_coverage',4,4,77,'post');
        $tests[]=self::result(
            6,
            'Post existente con cobertura parcial',
            (string)($t6['decision']['action']??'')==='IMPROVE_POST',
            'IMPROVE_POST',
            (string)($t6['decision']['action']??'∅'),
            'La cobertura parcial de un post se amplía antes de crear otra URL.'
        );

        $t7=self::scenario('duplicate',4,4,77,'post');
        $tests[]=self::result(
            7,
            'Piezas solapadas',
            (string)($t7['decision']['action']??'')==='MERGE_CONTENT',
            'MERGE_CONTENT',
            (string)($t7['decision']['action']??'∅'),
            'Duplicidad editorial produce consolidación, no CREATE_POST.'
        );

        $contract=SEO_Solucionador_Sources::editorial_source_contract();
        $tests[]=self::result(
            8,
            'Dos orígenes editoriales independientes',
            $contract===array('faq','dependiente_academia'),
            'faq, dependiente_academia',
            implode(', ',array_map('strval',(array)$contract)),
            'FAQ manual y Dependiente/Academia pueden alimentar Solucionador sin depender entre sí.'
        );

        $batch_api=class_exists('SEO_Solucionador_Dossiers')
            && method_exists('SEO_Solucionador_Dossiers','scan_batch')
            && method_exists('SEO_Solucionador_Dossiers','state')
            && method_exists('SEO_Solucionador_Dossiers','question_details')
            && method_exists('SEO_Solucionador_Dossiers','changed_item_keys')
            && method_exists('SEO_Solucionador_Dossiers','review_item_keys');
        $tests[]=self::result(
            9,
            'Procesamiento reanudable y revisión por elemento',
            $batch_api,
            'scan_batch + question_details + changed/review item keys',
            $batch_api ? 'API disponible' : 'API incompleta',
            'FAQ y Dependiente mantienen cursores y hashes propios dentro del mismo dossier.'
        );

        $post_contract=function_exists('seo_post_editor_set_public_content_role')
            && class_exists('SEO_Solucionador_Posts')
            && defined('SEO_SOLUCIONADOR_VERSION')
            && version_compare(SEO_SOLUCIONADOR_VERSION,'0.7.0','>=');
        $tests[]=self::result(
            10,
            'Contrato de borrador humano',
            $post_contract,
            'API común de rol + Solucionador >= 0.7.0',
            $post_contract ? 'Contrato disponible' : 'Contrato incompleto',
            'CREATE_POST termina en draft; Solucionador nunca publica automáticamente.'
        );

        $trace_contract=defined('SEO_Solucionador_Posts::META_DOSSIER_CATEGORY_ID')
            && defined('SEO_Solucionador_Posts::META_QUESTION_IDS')
            && defined('SEO_Solucionador_Posts::META_FAQ_IDS')
            && defined('SEO_Solucionador_Posts::META_RUN_IDS')
            && defined('SEO_Solucionador_Posts::META_SOURCE_HASH')
            && defined('SEO_Solucionador_Posts::META_SOURCE_SNAPSHOT')
            && defined('SEO_Solucionador_Posts::META_PENDING_ITEM_KEYS');
        $tests[]=self::result(
            11,
            'Trazabilidad FAQ + Dependiente separada del texto editorial',
            $trace_contract,
            'category_id + faq_ids + question_ids + run_ids + source_hash + item_keys',
            $trace_contract ? 'Contrato de trazabilidad disponible' : 'Contrato incompleto',
            'Editar post_content no destruye el inventario interno que originó el borrador.'
        );

        $coverage_contract=class_exists('SEO_Editorial_Coverage')
            && is_subclass_of('SEO_Solucionador_Coverage','SEO_Editorial_Coverage');
        $tests[]=self::result(
            12,
            'Cobertura neutral compartida',
            $coverage_contract,
            'SEO_Editorial_Coverage + wrapper compatible',
            $coverage_contract ? 'API neutral disponible' : 'API neutral incompleta',
            'La cobertura sigue separada del origen del conocimiento.'
        );

        $passed=count(array_filter($tests,static function($row){return !empty($row['pass']);}));
        return array(
            'passed'=>$passed,
            'failed'=>count($tests)-$passed,
            'total'=>count($tests),
            'ok'=>$passed===count($tests),
            'tests'=>$tests,
            'ran_at'=>current_time('mysql'),
        );
    }
}
