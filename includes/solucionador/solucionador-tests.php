<?php
/**
 * Tests funcionales de Solucionador v0.5.
 *
 * Arquitectura separada: Academia/Entrenador -> dossier category-first ->
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

    private static function academy_profile($category_id = 77) {
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

    private static function scenario($coverage,$questions=5,$category_id=77,$entity_type='',$risks=array()) {
        return SEO_Solucionador_Engine::evaluate_scenario_for_test(array(
            'profile'=>self::academy_profile($category_id),
            'stats'=>array('total'=>$questions,'dependiente'=>$questions),
            'coverage'=>array(
                'status'=>$coverage,
                'entity_type'=>$entity_type,
                'entity_id'=>$entity_type ? 321 : 0,
                'score'=>$coverage==='uncovered' ? 0 : 0.82,
            ),
            'knowledge'=>array('status'=>'sufficient','count'=>$questions,'confidence'=>0.90),
            'risks'=>wp_parse_args($risks,array('duplication_risk'=>10,'cannibalization_risk'=>10)),
            'primary_category_id'=>$category_id,
        ));
    }

    public static function run() {
        $tests=array();

        $source=array(
            'source_type'=>'dependiente',
            'category_id'=>77,
            'category_name'=>'Taladros',
            'source_meta'=>array(
                'dependiente_channel'=>'academy_learned_dossier',
                'editorial_family'=>'dependiente_qa_basic',
                'category_id'=>77,
                'question_count'=>17,
            ),
            'hints'=>array(
                'intent'=>'dependiente_qa_basic',
                'action'=>'resolver',
                'object'=>'Taladros',
                'category_id'=>77,
            ),
        );
        $profile=SEO_Solucionador_Engine::normalize_topic_for_test(
            'Preguntas habituales sobre Taladros: conocimiento aprendido por Dependiente.',
            $source
        );
        $tests[]=self::result(
            1,
            'Un dossier canónico por categoría',
            (string)($profile['intent']??'')==='dependiente_qa_basic'
                && absint($profile['category_id']??0)===77
                && (string)($profile['canonical_key']??'')==='dependiente-qa-basic|resolver|category-77|general|general',
            'dependiente_qa_basic · category 77 · canonical estable',
            (string)($profile['intent']??'∅').' · '.absint($profile['category_id']??0).' · '.(string)($profile['canonical_key']??'∅'),
            'N preguntas de una categoría convergen en un dossier; no se crea una URL por pregunta.'
        );

        $t2=self::scenario('uncovered',2,77);
        $tests[]=self::result(
            2,
            'Masa crítica insuficiente',
            (string)($t2['decision']['action']??'')==='DEFER',
            'DEFER',
            (string)($t2['decision']['action']??'∅'),
            'Por defecto se requieren al menos 3 preguntas aprendidas pass_* antes de proponer CREATE_POST.'
        );

        $t3=self::scenario('uncovered',5,0);
        $tests[]=self::result(
            3,
            'Sin categoría demostrable',
            (string)($t3['decision']['action']??'')==='DEFER',
            'DEFER',
            (string)($t3['decision']['action']??'∅'),
            'Una pregunta/dossier sin product_cat demostrable no crea URL.'
        );

        $t4=self::scenario('uncovered',5,77);
        $tests[]=self::result(
            4,
            'Dossier suficiente sin cobertura',
            (string)($t4['decision']['action']??'')==='CREATE_POST',
            'CREATE_POST',
            (string)($t4['decision']['action']??'∅'),
            'Masa crítica + product_cat + cobertura libre + bajo riesgo permiten proponer un post básico.'
        );

        $t5=self::scenario('covered',8,77,'post');
        $tests[]=self::result(
            5,
            'Cobertura suficiente',
            (string)($t5['decision']['action']??'')==='NO_ACTION',
            'NO_ACTION',
            (string)($t5['decision']['action']??'∅'),
            'Nunca se crea un segundo post cuando la intención ya está cubierta.'
        );

        $t6=self::scenario('partial_coverage',8,77,'post');
        $tests[]=self::result(
            6,
            'Post existente con cobertura parcial',
            (string)($t6['decision']['action']??'')==='IMPROVE_POST',
            'IMPROVE_POST',
            (string)($t6['decision']['action']??'∅'),
            'La cobertura parcial de un post se amplía antes de crear otra URL.'
        );

        $t7=self::scenario('duplicate',8,77,'post');
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
            'Origen editorial único',
            $contract===array('dependiente_academia'),
            'dependiente_academia',
            implode(', ',array_map('strval',(array)$contract)),
            'Ingeniero, Ojeador, Comparador, Auditor, Marketing, Clasificador, Comentarista y Analista no originan temas.'
        );

        $batch_api=class_exists('SEO_Solucionador_Dossiers')
            && method_exists('SEO_Solucionador_Dossiers','scan_batch')
            && method_exists('SEO_Solucionador_Dossiers','state')
            && method_exists('SEO_Solucionador_Dossiers','question_details');
        $tests[]=self::result(
            9,
            'Procesamiento reanudable y detalle bajo demanda',
            $batch_api,
            'scan_batch + state + question_details',
            $batch_api ? 'API disponible' : 'API incompleta',
            'El escaneo debe continuar por cursor sin cargar todos los payloads pesados en una ejecución.'
        );

        $post_contract=function_exists('seo_post_editor_set_public_content_role')
            && class_exists('SEO_Solucionador_Posts')
            && defined('SEO_SOLUCIONADOR_VERSION')
            && version_compare(SEO_SOLUCIONADOR_VERSION,'0.5.0','>=');
        $tests[]=self::result(
            10,
            'Contrato de post dependiente_qa_basic',
            $post_contract,
            'API común de rol + Solucionador >= 0.5.0',
            $post_contract ? 'Contrato disponible' : 'Contrato incompleto',
            'CREATE_POST debe terminar en draft, rol estable y relación post_to_category; la publicación sigue siendo humana.'
        );

        $coverage_contract=class_exists('SEO_Editorial_Coverage')
            && is_subclass_of('SEO_Solucionador_Coverage','SEO_Editorial_Coverage');
        $tests[]=self::result(
            11,
            'Cobertura neutral compartida',
            $coverage_contract,
            'SEO_Editorial_Coverage + wrapper compatible',
            $coverage_contract ? 'API neutral disponible' : 'API neutral incompleta',
            'La cobertura deja de pertenecer conceptualmente a Solucionador y conserva wrapper para compatibilidad.'
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
