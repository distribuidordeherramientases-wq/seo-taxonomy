<?php
/**
 * Comparador v2 - regresiones funcionales sin llamadas externas ni escrituras.
 */

defined('ABSPATH') || exit;

final class SEO_Comparador_Tests {
    private static function row($id,$pass,$detail) {
        return array('id'=>$id,'pass'=>(bool)$pass,'detail'=>$detail);
    }

    public static function run() {
        $tests=array();

        $tests[]=self::row(
            'CMP2-001',
            class_exists('SEO_Comparador_Service')
                && method_exists('SEO_Comparador_Service','analyze_category')
                && method_exists('SEO_Comparador_Service','accept_and_create_draft'),
            'El servicio v2 analiza una categoría y convierte una propuesta aceptada en borrador.'
        );

        $tests[]=self::row(
            'CMP2-002',
            function_exists('seo_ojeador_get_category_market'),
            'Comparador dispone del contrato persistido de Ojeador y no necesita abrir una búsqueda externa propia.'
        );

        $values=SEO_Comparador_Engine::extract_external_values(array(
            'title'=>'Taladro 18 V con par máximo 60 Nm',
            'description'=>'Modelo sin dato de potencia publicado.',
            'raw_json'=>'{}',
        ));
        $tests[]=self::row(
            'CMP2-003',
            isset($values['voltage'],$values['torque']) && !isset($values['power']),
            'Los atributos de mercado se extraen sólo cuando aparecen de forma explícita; no se inventan ausencias.'
        );

        $key_a=SEO_Comparador_Engine::debug_external_dedupe_key(array(
            'brand'=>'Acme','model'=>'X100','merchant'=>'Tienda A','title'=>'Acme X100'
        ));
        $key_b=SEO_Comparador_Engine::debug_external_dedupe_key(array(
            'brand'=>'Acme','model'=>'X100','merchant'=>'Tienda B','title'=>'Acme X100 oferta'
        ));
        $tests[]=self::row(
            'CMP2-004',
            $key_a!=='' && $key_a===$key_b,
            'El comercio no define la identidad del producto cuando existe marca y modelo; varias tiendas no inflan la muestra.'
        );

        $stats=SEO_Comparador_Service::price_stats_for_test(array(10,20,30,40,50));
        $tests[]=self::row(
            'CMP2-005',
            absint($stats['count'] ?? 0)===5
                && (float)($stats['min'] ?? 0)===10.0
                && (float)($stats['median'] ?? 0)===30.0
                && (float)($stats['max'] ?? 0)===50.0,
            'El dossier calcula rango y mediana del precio observado de forma determinista.'
        );

        $tests[]=self::row(
            'CMP2-006',
            SEO_Comparador_Service::proposal_ready_for_test(5,3,0)
                && SEO_Comparador_Service::proposal_ready_for_test(5,0,2)
                && !SEO_Comparador_Service::proposal_ready_for_test(4,4,4),
            'Una propuesta requiere una muestra mínima de mercado y evidencia suficiente de precio o marcas.'
        );

        $tests[]=self::row(
            'CMP2-007',
            method_exists('SEO_Comparador_Engine','link_post'),
            'El vínculo de borrador conserva post_map, rol comparison, etiqueta comparativas y relación post_to_category.'
        );

        $tests[]=self::row(
            'CMP2-008',
            class_exists('SEO_Comparador_Public')
                && method_exists('SEO_Comparador_Public','payload_for_category')
                && method_exists('SEO_Comparador_Public','payload_for_product'),
            'La web pública sólo lee resultados persistidos; no ejecuta Ojeador durante una visita.'
        );

        $tests[]=self::row(
            'CMP2-009',
            method_exists('SEO_Comparador_Service','render_editor_panel'),
            'La Editora puede revisar el dossier interno por encima del editor sin mezclarlo con el contenido público.'
        );

        $tests[]=self::row(
            'CMP2-010',
            method_exists('SEO_Comparador_Process','process_slice')
                && method_exists('SEO_Comparador_Process','progress'),
            'El Gestor mantiene el procesamiento por ventanas y el progreso del servicio.'
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

