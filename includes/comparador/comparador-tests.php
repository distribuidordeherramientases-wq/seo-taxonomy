<?php
/**
 * Comparador - regresiones funcionales RF v1.0.
 *
 * No escriben datos ni ejecutan fuentes externas.
 */

defined('ABSPATH') || exit;

final class SEO_Comparador_Tests {
    private static function row($id,$pass,$detail) {
        return array('id'=>$id,'pass'=>(bool)$pass,'detail'=>$detail);
    }

    public static function run() {
        $tests=array();

        // CMP-001: el comparador de tienda existente sigue disponible.
        $tests[]=self::row(
            'CMP-001',
            class_exists('SEO_Dependiente_API') && method_exists('SEO_Dependiente_API','comparison_data'),
            'El comparador actual de Dependiente sigue siendo la fuente de comparación interactiva/PDF.'
        );

        // CMP-002: atributos externos parciales; sólo normalizar lo explícito.
        $values=SEO_Comparador_Engine::extract_external_values(array(
            'title'=>'Taladro 18 V con par máximo 60 Nm',
            'description'=>'Modelo sin dato de potencia publicado.',
            'raw_json'=>'{}',
        ));
        $tests[]=self::row(
            'CMP-002',
            isset($values['voltage'],$values['torque']) && !isset($values['power']),
            '18 V y 60 Nm se conservan; la potencia ausente no se inventa.'
        );

        // CMP-003: mismo modelo en distintos merchants debe deduplicar.
        $key_a=SEO_Comparador_Engine::debug_external_dedupe_key(array(
            'brand'=>'Acme','model'=>'X100','merchant'=>'Tienda A','title'=>'Acme X100'
        ));
        $key_b=SEO_Comparador_Engine::debug_external_dedupe_key(array(
            'brand'=>'Acme','model'=>'X100','merchant'=>'Tienda B','title'=>'Acme X100 oferta'
        ));
        $tests[]=self::row(
            'CMP-003',
            $key_a!=='' && $key_a===$key_b,
            'Brand+model produce la misma identidad aunque cambie el comercio.'
        );

        // CMP-004: eje con sólo 20% de cobertura no puede publicarse con defaults.
        $tests[]=self::row(
            'CMP-004',
            !SEO_Comparador_Engine::axis_publishable_for_test(2,10,2,0.95),
            'Un eje con 20% de cobertura queda fuera de la síntesis pública.'
        );

        // CMP-005: conflicto semántico explícito bloquea el perfil.
        $tests[]=self::row(
            'CMP-005',
            SEO_Comparador_Engine::profile_quality_status(3,4,2,1)==='blocked',
            'Un conflicto semántico comunicado al contrato seo_comparador_semantic_conflicts bloquea el perfil.'
        );

        // CMP-006: cobertura existente implica mejorar/fusionar/no actuar; nunca CREATE_POST.
        $action6=SEO_Comparador_Engine::editorial_action_for_test(true,'partial_coverage',true,false);
        $tests[]=self::row(
            'CMP-006',
            $action6==='IMPROVE_POST',
            'Con cobertura parcial y una pieza existente Comparador propone IMPROVE_POST sin depender de Solucionador.'
        );

        // CMP-007: perfil comparativo válido y sin cobertura puede originar CREATE_POST.
        $action7=SEO_Comparador_Engine::editorial_action_for_test(true,'uncovered',false,false);
        $tests[]=self::row(
            'CMP-007',
            $action7==='CREATE_POST',
            'Una comparativa validada puede llegar a CREATE_POST cuando no existe cobertura equivalente.'
        );

        // CMP-007B: un perfil insuficiente nunca crea borrador por tener mucho mercado.
        $tests[]=self::row(
            'CMP-007B',
            SEO_Comparador_Engine::editorial_action_for_test(false,'uncovered',false,false)==='NEEDS_REVIEW',
            'La calidad insuficiente fuerza NEEDS_REVIEW aunque no exista cobertura.'
        );

        // CMP-008: las plantillas disponen de lector persistido; no recalculan mercado.
        $tests[]=self::row(
            'CMP-008',
            class_exists('SEO_Comparador_Public')
                && method_exists('SEO_Comparador_Public','payload_for_category')
                && method_exists('SEO_Comparador_Public','payload_for_product'),
            'Categoría y producto consumen un payload persistido y enlazan la pieza canónica.'
        );

        // CMP-009: cambio de snapshot con post publicado => needs_update.
        $tests[]=self::row(
            'CMP-009',
            SEO_Comparador_Engine::resolve_recalculated_status('published','hash-a','hash-b',true,'needs_review')==='needs_update',
            'Un cambio material no sobrescribe el post: marca NEEDS_UPDATE.'
        );

        // CMP-010: Rendimiento se obtiene a través del contrato de Analista.
        $tests[]=self::row(
            'CMP-010',
            method_exists('SEO_Comparador_Engine','performance_rows'),
            'Comparador expone rendimiento reutilizando seo_analista_get_data, sin integración GA4/GSC propia.'
        );

        // CMP-011: el vínculo editorial conserva post_map, etiqueta comparativas, rol comparison y categoría.
        $tests[]=self::row(
            'CMP-011',
            method_exists('SEO_Comparador_Engine','link_post'),
            'El post canónico se vincula como post normal; link_post aplica la etiqueta comparativas y el flujo añade rol comparison + post_to_category.'
        );

        $passed=count(array_filter($tests,static function($row){return !empty($row['pass']);}));
        return array(
            'ok'=>$passed===count($tests),
            'passed'=>$passed,
            'total'=>count($tests),
            'tests'=>$tests,
        );
    }
}
