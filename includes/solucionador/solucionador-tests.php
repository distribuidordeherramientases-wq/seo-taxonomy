<?php
/**
 * Solucionador - regresiones de arquitectura separada v0.5.
 *
 * No escriben en BD, no crean posts y no consultan servicios externos.
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

    private static function academy_source($category_id,$category_name,$question_id) {
        return array(
            'source_type'=>'dependiente',
            'category_id'=>absint($category_id),
            'category_name'=>(string)$category_name,
            'source_meta'=>array(
                'dependiente_channel'=>'academy_learned',
                'editorial_family'=>'dependiente_qa_basic',
                'question_id'=>absint($question_id),
                'category_id'=>absint($category_id),
                'category_name'=>(string)$category_name,
                'evaluation_status'=>'pass_exact',
                'evaluation_score'=>0.95,
            ),
            'hints'=>array(
                'intent'=>'dependiente_qa_basic',
                'action'=>'resolver',
                'object'=>(string)$category_name,
                'category_id'=>absint($category_id),
            ),
        );
    }

    private static function scenario($questions,$coverage='uncovered',$entity_type='',$category_id=77) {
        return SEO_Solucionador_Engine::evaluate_scenario_for_test(array(
            'profile'=>array(
                'intent'=>'dependiente_qa_basic',
                'key_intent'=>'dependiente_qa_basic',
                'action'=>'resolver',
                'object'=>'taladros',
                'condition'=>'',
                'context'=>'',
                'category_id'=>absint($category_id),
            ),
            'stats'=>array(
                'total'=>absint($questions),
                'dependiente'=>absint($questions),
                'academy_questions'=>absint($questions),
                'search_demand'=>0,
            ),
            'coverage'=>array(
                'status'=>$coverage,
                'entity_type'=>$entity_type,
                'entity_id'=>$entity_type ? 321 : 0,
                'score'=>$coverage==='uncovered'?0:0.75,
            ),
            'risks'=>array('duplication_risk'=>10,'cannibalization_risk'=>10),
            'primary_category_id'=>absint($category_id),
        ));
    }

    public static function run() {
        $tests=array();

        $s1=self::academy_source(77,'Taladros',101);
        $s2=self::academy_source(77,'Taladros',102);
        $p1=SEO_Solucionador_Engine::normalize_topic_for_test('¿Qué taladro necesito para hormigón?',$s1);
        $p2=SEO_Solucionador_Engine::normalize_topic_for_test('¿Taladro con cable o batería?',$s2);
        $key1=(string)($p1['canonical_key']??'');
        $key2=(string)($p2['canonical_key']??'');
        $tests[]=self::result(
            'SOL-A01','Un dossier por categoría',
            $key1!=='' && $key1===$key2 && $key1==='dependiente-qa-basic|resolver|category-77|general|general',
            'mismo canonical_key category-77',
            $key1 . ' | ' . $key2,
            'Preguntas diferentes de la misma product_cat convergen en un dossier; no se crea una URL por pregunta.'
        );

        $t2=self::scenario(2);
        $tests[]=self::result(
            'SOL-A02','Masa crítica insuficiente',
            (string)($t2['decision']['action']??'')==='DEFER',
            'DEFER',
            (string)($t2['decision']['action']??'∅'),
            'Con menos preguntas que el umbral configurado el dossier queda en revisión.'
        );

        $t3=self::scenario(3);
        $tests[]=self::result(
            'SOL-A03','Academia suficiente sin cobertura',
            (string)($t3['decision']['action']??'')==='CREATE_POST',
            'CREATE_POST',
            (string)($t3['decision']['action']??'∅'),
            'No requiere conocimiento de Ingeniero ni perfil de Comparador.'
        );

        $t4=self::scenario(8,'covered','post');
        $tests[]=self::result(
            'SOL-A04','Dossier ya cubierto',
            (string)($t4['decision']['action']??'')==='NO_ACTION',
            'NO_ACTION',
            (string)($t4['decision']['action']??'∅'),
            'Una URL suficientemente equivalente impide crear un post duplicado.'
        );

        $t5=self::scenario(8,'partial_coverage','post');
        $tests[]=self::result(
            'SOL-A05','Post con cobertura parcial',
            (string)($t5['decision']['action']??'')==='IMPROVE_POST',
            'IMPROVE_POST',
            (string)($t5['decision']['action']??'∅'),
            'La mejora del post existente tiene prioridad sobre una URL nueva.'
        );

        $t6=self::scenario(8,'duplicate','post');
        $tests[]=self::result(
            'SOL-A06','Contenido solapado',
            (string)($t6['decision']['action']??'')==='MERGE_CONTENT',
            'MERGE_CONTENT',
            (string)($t6['decision']['action']??'∅'),
            'Varias piezas equivalentes deben consolidarse.'
        );

        $t7=self::scenario(8,'uncovered','',0);
        $tests[]=self::result(
            'SOL-A07','Sin product_cat demostrable',
            (string)($t7['decision']['action']??'')==='DEFER',
            'DEFER',
            (string)($t7['decision']['action']??'∅'),
            'Sin categoría demostrable no se crea dossier publicable ni URL.'
        );

        $allowed=array('CREATE_POST','IMPROVE_POST','MERGE_CONTENT','NO_ACTION','DEFER');
        $actual=array(
            (string)($t2['decision']['action']??''),
            (string)($t3['decision']['action']??''),
            (string)($t4['decision']['action']??''),
            (string)($t5['decision']['action']??''),
            (string)($t6['decision']['action']??''),
            (string)($t7['decision']['action']??''),
        );
        $unexpected=array_values(array_diff($actual,$allowed));
        $tests[]=self::result(
            'SOL-A08','Decisiones dentro del alcance reducido',
            !$unexpected,
            implode(', ',$allowed),
            $unexpected?implode(', ',$unexpected):'sin acciones fuera de alcance',
            'Solucionador no propone landings, categorías, páginas ni investigación técnica.'
        );

        $roles=function_exists('seo_post_editor_public_content_roles')
            ? (array)seo_post_editor_public_content_roles()
            : array();
        $role_ok=array_key_exists('dependiente_qa_basic',$roles)
            && function_exists('seo_post_editor_set_public_content_role');
        $tests[]=self::result(
            'SOL-A09','Contrato de rol editorial',
            $role_ok,
            'dependiente_qa_basic + API común',
            $role_ok?'disponible':'incompleto',
            'Los borradores usan el rol estable que consumen las plantillas.'
        );

        $batch_ok=method_exists('SEO_Solucionador_Sources','academia_batch')
            && method_exists('SEO_Solucionador_Sources','academia_question_details')
            && SEO_Solucionador_Engine::BATCH_SIZE>0
            && SEO_Solucionador_Engine::BATCH_SIZE<=500
            && SEO_Solucionador_Engine::MAX_BATCHES_PER_REQUEST>0;
        $tests[]=self::result(
            'SOL-A10','Procesamiento ligero y detalles bajo demanda',
            $batch_ok,
            'batch <= 500 + detail API',
            $batch_ok?'disponible':'incompleto',
            'El scan no necesita cargar top_results/response_meta de todas las preguntas en memoria.'
        );

        $source_methods_absent=!method_exists('SEO_Solucionador_Sources','ingeniero')
            && !method_exists('SEO_Solucionador_Sources','ojeador')
            && !method_exists('SEO_Solucionador_Sources','comparador')
            && !method_exists('SEO_Solucionador_Sources','analista');
        $tests[]=self::result(
            'SOL-A11','Independencia de otros procesos editoriales',
            $source_methods_absent,
            'Ingeniero/Ojeador/Comparador/Analista fuera de Sources',
            $source_methods_absent?'desacoplados':'queda acoplamiento',
            'Solucionador debe funcionar aunque esos servicios estén desactivados.'
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
