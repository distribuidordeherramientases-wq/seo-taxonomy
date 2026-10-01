<?php
/**
 * Tests funcionales de regresion de Requisitos Funcionales Solucionador v1.0.
 *
 * No escriben en BD ni modifican contenido. Son escenarios puros destinados a
 * detectar regresiones antes de confiar en una nueva regla editorial.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Tests {
    private static function result($id,$name,$pass,$expected,$actual,$detail='') {
        return array(
            'id'=>$id,
            'name'=>$name,
            'pass'=>(bool) $pass,
            'expected'=>$expected,
            'actual'=>$actual,
            'detail'=>$detail,
        );
    }

    public static function run() {
        $tests = array();

        $p1 = SEO_Solucionador_Normalizer::profile('Guía de Compra: Cómo Elegir un Compresor de Aire.');
        $tests[] = self::result(
            1,
            'Guía de compra de compresor de aire',
            (string)($p1['object'] ?? '') === 'compresor de aire',
            'object = compresor de aire',
            'object = ' . (string)($p1['object'] ?? '∅'),
            'La palabra editorial "compra" nunca debe ser el objeto principal.'
        );

        $p2 = SEO_Solucionador_Normalizer::profile('Errores comunes al usar un compresor de aire.');
        $tests[] = self::result(
            2,
            'Errores comunes al usar compresor de aire',
            (string)($p2['object'] ?? '') === 'compresor de aire'
                && in_array(sanitize_key((string)($p2['intent'] ?? '')),array('problem','need'),true),
            'object = compresor de aire; intent = problem/need',
            'object = ' . (string)($p2['object'] ?? '∅') . '; intent = ' . (string)($p2['intent'] ?? '∅'),
            'Los modificadores editoriales no deben desplazar a la entidad técnica.'
        );

        $t3 = SEO_Solucionador_Engine::evaluate_scenario_for_test(array(
            'profile'=>array('intent'=>'procedure','key_intent'=>'task','action'=>'usar','object'=>'compresor de aire','condition'=>'','context'=>'','category_id'=>10),
            'stats'=>array('total'=>3,'dependiente'=>2,'analista'=>1,'ingeniero'=>1),
            'coverage'=>array('status'=>'covered','entity_type'=>'post','entity_id'=>321,'score'=>0.96),
            'knowledge'=>array('status'=>'sufficient','count'=>4,'confidence'=>0.8),
            'primary_category_id'=>10,
        ));
        $tests[] = self::result(
            3,
            'Misma intención ya cubierta',
            in_array((string)($t3['decision']['action'] ?? ''),array('IMPROVE_POST','NO_ACTION'),true),
            'IMPROVE_POST o NO_ACTION',
            (string)($t3['decision']['action'] ?? '∅'),
            'Nunca CREATE_POST sin justificación adicional cuando ya existe una URL adecuada.'
        );

        $base_source = array(
            'category_id'=>77,
            'category_name'=>'Taladros',
            'hints'=>array('category_id'=>77,'object'=>'taladro','action'=>'elegir','intent'=>'decision'),
        );
        $q1 = SEO_Solucionador_Engine::normalize_topic_for_test('¿Qué taladro necesito para hormigón?',$base_source);
        $q2 = SEO_Solucionador_Engine::normalize_topic_for_test('¿Qué potencia necesito?',$base_source);
        $q3 = SEO_Solucionador_Engine::normalize_topic_for_test('¿Taladro con cable o batería?',$base_source);
        $keys = array_unique(array_filter(array(
            (string)($q1['canonical_key'] ?? ''),
            (string)($q2['canonical_key'] ?? ''),
            (string)($q3['canonical_key'] ?? ''),
        )));
        $tests[] = self::result(
            4,
            'Agrupar preguntas similares de Dependiente',
            count($keys) === 1,
            '1 canonical_topic',
            count($keys) . ' canonical_topic(s): ' . implode(' | ',$keys),
            'Category-first agrupa dudas de elección de la misma familia en una única necesidad editorial.'
        );

        $t5 = SEO_Solucionador_Engine::evaluate_scenario_for_test(array(
            'profile'=>array('intent'=>'decision','key_intent'=>'decision','action'=>'elegir','object'=>'taladro','condition'=>'','context'=>'','category_id'=>77),
            'stats'=>array('total'=>3,'dependiente'=>2,'analista'=>1,'ingeniero'=>1),
            'coverage'=>array('status'=>'uncovered','score'=>0),
            'knowledge'=>array('status'=>'sufficient','count'=>5,'confidence'=>0.8),
            'primary_category_id'=>77,
            'landing'=>array('id'=>9,'requirements_pass'=>true,'score'=>80),
        ));
        $tests[] = self::result(
            5,
            'Categoría antes que landing',
            (string)($t5['decision']['action'] ?? '') === 'IMPROVE_CATEGORY',
            'IMPROVE_CATEGORY',
            (string)($t5['decision']['action'] ?? '∅'),
            'Una landing no debe competir con la familia de producto que ya representa la intención principal.'
        );

        $t6 = SEO_Solucionador_Engine::evaluate_scenario_for_test(array(
            'profile'=>array('intent'=>'decision','key_intent'=>'decision','action'=>'elegir','object'=>'proteccion individual','condition'=>'varias_familias','context'=>'trabajo','category_id'=>88),
            'stats'=>array('total'=>3,'dependiente'=>1,'analista'=>1,'ojeador'=>1,'ingeniero'=>1),
            'coverage'=>array('status'=>'uncovered','score'=>0),
            'knowledge'=>array('status'=>'sufficient','count'=>6,'confidence'=>0.85),
            'risks'=>array('duplication_risk'=>10,'cannibalization_risk'=>12),
            'primary_category_id'=>88,
            'landing'=>array('id'=>12,'requirements_pass'=>true,'score'=>78,'status'=>'approved'),
        ));
        $tests[] = self::result(
            6,
            'Intención transversal válida para landing',
            (string)($t6['decision']['action'] ?? '') === 'CREATE_LANDING',
            'CREATE_LANDING',
            (string)($t6['decision']['action'] ?? '∅'),
            'CREATE_LANDING exige evidencia, conocimiento, cobertura libre, bajo riesgo y candidata de landing válida.'
        );

        $t7 = SEO_Solucionador_Engine::evaluate_scenario_for_test(array(
            'profile'=>array('intent'=>'procedure','key_intent'=>'task','action'=>'reparar','object'=>'compresor de aire','condition'=>'','context'=>'','category_id'=>10),
            'stats'=>array('total'=>3,'dependiente'=>2,'analista'=>1),
            'coverage'=>array('status'=>'uncovered','score'=>0),
            'knowledge'=>array('status'=>'insufficient','count'=>0,'confidence'=>0),
            'primary_category_id'=>10,
        ));
        $tests[] = self::result(
            7,
            'Conocimiento técnico insuficiente',
            (string)($t7['decision']['action'] ?? '') === 'INVESTIGATE',
            'INVESTIGATE',
            (string)($t7['decision']['action'] ?? '∅'),
            'Un tema técnico sin conocimiento validado no pasa directamente a Editora.'
        );

        $passed = count(array_filter($tests,static function($row){ return !empty($row['pass']); }));
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
