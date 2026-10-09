<?php
/**
 * Pruebas deterministas de contrato para Analista 3.8.1.
 *
 * No hacen llamadas externas ni modifican contenido. Se ejecutan solo cuando
 * la pantalla Fuentes/diagnóstico las solicita.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_381_test_row')) {
    function seo_analista_381_test_row($code, $passed, $detail) {
        return array(
            'code'=>(string) $code,
            'passed'=>(bool) $passed,
            'detail'=>(string) $detail,
        );
    }
}

if (!function_exists('seo_analista_381_self_tests')) {
    function seo_analista_381_self_tests() {
        $tests = array();

        // 1) Cámaras: volumen suficiente + destino concreto = candidata, no bloqueo por matching.
        $camera = array(
            'priority'=>66,
            'work_bucket'=>'HACER_DESPUES',
            'confidence'=>72,
            'topic'=>'Cámaras de inspección de tuberías',
            'query'=>'cámaras de inspección de tuberías',
            'entity'=>array(
                'type'=>'category','id'=>0,'title'=>'Cámaras de inspección de tuberías',
                'url'=>home_url('/categoria-producto/camaras-inspeccion-tuberias/')
            ),
            'target'=>array('title'=>'Cámaras de inspección de tuberías','url'=>home_url('/categoria-producto/camaras-inspeccion-tuberias/')),
            'metrics'=>array('impressions'=>33,'clicks'=>1,'ctr'=>0.0303,'position'=>37.8,'queries'=>3,'previous_impressions'=>24,'impressions_delta'=>9),
            'objective'=>array('primary'=>'sales','primary_label'=>'VENTAS','authority'=>55,'traffic'=>60,'sales'=>65),
            'catalog'=>array('products'=>8),
            'issues'=>array(),
            'recommended_changes'=>array(),
            'sources'=>array('Search Console','WordPress'),
        );
        $camera_result = seo_analista_validate_and_finalize_task($camera, 28);
        $tests[] = seo_analista_381_test_row(
            'ANA381-001',
            in_array((string) ($camera_result['work_bucket'] ?? ''), array('HACER_DESPUES','VIGILAR'), true)
                && (string) ($camera_result['match_type'] ?? '') !== 'unproven'
                && (string) ($camera_result['match_type'] ?? '') !== 'conflict',
            'Cámaras con 33 impresiones y destino coherente se conserva como candidata, no como asociación bloqueada.'
        );

        // 2) Bosch 18V-18 X vs 18V-20: conflicto de modelo.
        $bosch = array(
            'priority'=>82,'work_bucket'=>'HACER_AHORA','confidence'=>80,
            'topic'=>'Bosch GBH 18V-18 X',
            'query'=>'GBH 18V-20 Professional',
            'entity'=>array('type'=>'product','id'=>0,'title'=>'Bosch GBH 18V-18 X','url'=>home_url('/producto/bosch-gbh-18v-18-x/')),
            'target'=>array('url'=>home_url('/producto/bosch-gbh-18v-18-x/')),
            'metrics'=>array('impressions'=>45,'clicks'=>2,'position'=>14,'queries'=>2),
            'objective'=>array('primary'=>'traffic','authority'=>60,'traffic'=>72,'sales'=>50),
            'sources'=>array('Search Console','WordPress'),
        );
        $bosch_result = seo_analista_validate_and_finalize_task($bosch, 28);
        $tests[] = seo_analista_381_test_row(
            'ANA381-002',
            (string) ($bosch_result['match_type'] ?? '') === 'conflict'
                && (string) ($bosch_result['action_type'] ?? '') === 'CORREGIR_ASOCIACION'
                && (string) ($bosch_result['work_bucket'] ?? '') === 'INVESTIGAR',
            'Un modelo 18V-20 no puede ampliar automáticamente la ficha 18V-18 X.'
        );

        // 3) Taladros no absorbe una consulta específica de martillo/modelo.
        $drill = array(
            'priority'=>78,'work_bucket'=>'HACER_AHORA','confidence'=>75,
            'topic'=>'Taladros',
            'query'=>'Bosch GBH 18V-20 martillo perforador',
            'entity'=>array('type'=>'category','id'=>0,'title'=>'Taladros','url'=>home_url('/categoria-producto/taladros/')),
            'target'=>array('url'=>home_url('/categoria-producto/taladros/')),
            'metrics'=>array('impressions'=>40,'clicks'=>1,'position'=>22,'queries'=>4),
            'objective'=>array('primary'=>'traffic','authority'=>55,'traffic'=>70,'sales'=>55),
            'sources'=>array('Search Console','WordPress'),
        );
        $drill_result = seo_analista_validate_and_finalize_task($drill, 28);
        $tests[] = seo_analista_381_test_row(
            'ANA381-003',
            in_array((string) ($drill_result['work_bucket'] ?? ''), array('INVESTIGAR','VIGILAR'), true)
                && (string) ($drill_result['match_type'] ?? '') !== 'exact',
            'Una categoría genérica no recibe una consulta específica de modelo sin validación semántica.'
        );

        // 4) 1 impresión en posición 13 no es quick win fiable.
        $makita = array(
            'priority'=>84,'work_bucket'=>'HACER_AHORA','confidence'=>70,
            'topic'=>'Lijadora Makita',
            'query'=>'lijadora makita',
            'entity'=>array('type'=>'product','id'=>0,'title'=>'Lijadora Makita','url'=>home_url('/producto/lijadora-makita/')),
            'target'=>array('url'=>home_url('/producto/lijadora-makita/')),
            'metrics'=>array('impressions'=>1,'clicks'=>0,'position'=>13,'queries'=>1,'previous_impressions'=>0,'impressions_delta'=>1),
            'objective'=>array('primary'=>'traffic','authority'=>50,'traffic'=>76,'sales'=>48),
            'sources'=>array('Search Console','WordPress'),
            'issues'=>array(),
        );
        $makita_result = seo_analista_validate_and_finalize_task($makita, 28);
        $tests[] = seo_analista_381_test_row(
            'ANA381-004',
            (string) ($makita_result['work_bucket'] ?? '') === 'ESPERAR_DATOS',
            'Una sola impresión no entra en HACER AHORA por estar cerca de Top 10.'
        );

        // 5) Compresor sin destino: investigar cobertura, no optimizar URL inexistente.
        $compressor = array(
            'priority'=>74,'work_bucket'=>'HACER_AHORA','confidence'=>66,
            'topic'=>'Compresor sin aceite','query'=>'compresor sin aceite',
            'entity'=>array(),
            'target'=>array('title'=>'','url'=>''),
            'metrics'=>array('impressions'=>28,'clicks'=>1,'position'=>31,'queries'=>2),
            'objective'=>array('primary'=>'sales','authority'=>45,'traffic'=>58,'sales'=>70),
            'sources'=>array('Search Console'),
        );
        $compressor_result = seo_analista_validate_and_finalize_task($compressor, 28);
        $tests[] = seo_analista_381_test_row(
            'ANA381-005',
            (string) ($compressor_result['action_type'] ?? '') === 'INVESTIGAR_COBERTURA'
                && (string) ($compressor_result['work_bucket'] ?? '') === 'INVESTIGAR',
            'Sin URL objetivo no se emite una instrucción de editar/optimizar.'
        );

        // 6) Meta efectiva por plantilla/plugin no debe producir falso positivo.
        $meta_profile = seo_analista_content_issue_profile(
            'post',
            array('title'=>'Guía técnica de prueba','content'=>str_repeat('contenido útil ', 80),'excerpt'=>'','tag_count'=>1),
            array(
                'title'=>'[gestionado por plantilla del plugin SEO]',
                'description'=>'[gestionada por plantilla del plugin SEO]',
                'effective_checked'=>true
            ),
            1,
            3,
            0
        );
        $meta_text = implode(' ', (array) ($meta_profile['issues'] ?? array()));
        $tests[] = seo_analista_381_test_row(
            'ANA381-006',
            strpos($meta_text, 'SEO title') === false && strpos($meta_text, 'Meta description') === false,
            'Una plantilla SEO efectiva evita la anomalía meta inexistente.'
        );

        // 7) +3200% sobre base pequeña se marca inestable y reduce confianza.
        $growth = seo_analista_growth_signal(33, 1);
        $growth_row = array(
            'metrics'=>array('impressions'=>33,'clicks'=>0,'queries'=>1,'previous_impressions'=>1,'impressions_delta'=>32),
            'sources'=>array('Search Console')
        );
        $growth_evidence = seo_analista_evidence_profile($growth_row);
        $tests[] = seo_analista_381_test_row(
            'ANA381-007',
            !empty($growth_evidence['growth_unstable'])
                && (float) ($growth['raw_growth_pct'] ?? 0) >= 3000
                && absint($growth_evidence['priority_penalty'] ?? 0) >= 12,
            'El porcentaje extremo conserva valores absolutos y recibe penalización por base mínima.'
        );

        $passed = 0;
        foreach ($tests as $test) if (!empty($test['passed'])) $passed++;
        return array(
            'version'=>'3.8.1',
            'total'=>count($tests),
            'passed'=>$passed,
            'failed'=>count($tests) - $passed,
            'tests'=>$tests,
        );
    }
}
