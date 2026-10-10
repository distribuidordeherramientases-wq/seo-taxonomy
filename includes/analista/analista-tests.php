<?php
/**
 * Pruebas deterministas de contrato para Analista 3.8.1/3.8.2/3.8.3.
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
        $bosch_safe_resolution = !empty($bosch_result['auto_resolution']['resolved'])
            && in_array((string) ($bosch_result['match_type'] ?? ''), array('exact','partial'), true)
            && empty($bosch_result['execution_gate']['hard']);
        $bosch_blocked = (string) ($bosch_result['match_type'] ?? '') === 'conflict'
            && (string) ($bosch_result['action_type'] ?? '') === 'CORREGIR_ASOCIACION'
            && (string) ($bosch_result['work_bucket'] ?? '') === 'INVESTIGAR';
        $tests[] = seo_analista_381_test_row(
            'ANA381-002',
            $bosch_safe_resolution || $bosch_blocked,
            'Un 18V-20 nunca amplía silenciosamente la ficha 18V-18 X: o se resuelve contra otra entidad válida o se bloquea por conflicto.'
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
        $drill_same_generic = (string) ($drill_result['entity']['title'] ?? '') === 'Taladros';
        $drill_safe = !$drill_same_generic
            ? in_array((string) ($drill_result['match_type'] ?? ''), array('exact','partial'), true)
            : ((string) ($drill_result['work_bucket'] ?? '') === 'INVESTIGAR' && (string) ($drill_result['match_type'] ?? '') !== 'exact');
        $tests[] = seo_analista_381_test_row(
            'ANA381-003',
            $drill_safe,
            'Taladros no absorbe una búsqueda específica de martillo/modelo salvo que el resolver encuentre otra entidad local validada.'
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
        $compressor_resolved = !empty($compressor_result['auto_resolution']['resolved'])
            && !empty($compressor_result['target_resolved'])
            && in_array((string) ($compressor_result['match_type'] ?? ''), array('exact','partial'), true);
        $compressor_investigated = (string) ($compressor_result['action_type'] ?? '') === 'INVESTIGAR_COBERTURA'
            && (string) ($compressor_result['work_bucket'] ?? '') === 'INVESTIGAR'
            && !empty($compressor_result['investigation_steps']);
        $tests[] = seo_analista_381_test_row(
            'ANA381-005',
            $compressor_resolved || $compressor_investigated,
            'Compresor sin aceite intenta resolver primero una URL local; si no puede, INVESTIGAR incluye pasos concretos.'
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


if (!function_exists('seo_analista_382_self_tests')) {
    function seo_analista_382_self_tests() {
        $base = seo_analista_381_self_tests();
        $tests = (array) ($base['tests'] ?? array());

        // 8) Bloqueo comercial: conserva el score y declara cómo volver a HACER_AHORA.
        $commercial = array(
            'priority'=>84,'work_bucket'=>'HACER_AHORA','confidence'=>82,
            'topic'=>'Cámaras de inspección de tuberías',
            'query'=>'cámaras de inspección de tuberías',
            'entity'=>array('type'=>'category','id'=>0,'title'=>'Cámaras de inspección de tuberías','url'=>home_url('/categoria-producto/camaras-inspeccion-tuberias/')),
            'target'=>array('title'=>'Cámaras de inspección de tuberías','url'=>home_url('/categoria-producto/camaras-inspeccion-tuberias/')),
            'metrics'=>array('impressions'=>33,'clicks'=>2,'ctr'=>0.0606,'position'=>37.8,'queries'=>3,'previous_impressions'=>24,'impressions_delta'=>9),
            'objective'=>array('primary'=>'sales','primary_label'=>'VENTAS','authority'=>55,'traffic'=>68,'sales'=>82),
            'catalog'=>array('products'=>8),
            'issues'=>array(),
            'sources'=>array('Search Console','WordPress'),
        );
        $commercial_result = seo_analista_validate_and_finalize_task($commercial, 28);
        $tests[] = seo_analista_381_test_row(
            'ANA382-008',
            (string) ($commercial_result['blocker_type'] ?? '') === 'commercial'
                && (string) ($commercial_result['work_bucket'] ?? '') === 'HACER_DESPUES'
                && (string) ($commercial_result['bucket_if_unblocked'] ?? '') === 'HACER_AHORA'
                && absint($commercial_result['priority_score'] ?? 0) === 84
                && !empty($commercial_result['investigation_steps']),
            'Una oportunidad SEO válida bloqueada por comercialidad baja a HACER DESPUÉS, conserva score y declara el desbloqueo.'
        );

        // 9) INVESTIGAR nunca puede ser una etiqueta vacía: debe traer check/current/unlock/owner.
        $unknown = array(
            'priority'=>79,'work_bucket'=>'HACER_AHORA','confidence'=>76,
            'topic'=>'Entidad imposible de prueba zzzxqv',
            'query'=>'zzzxqv modelo 9988 inexistente',
            'entity'=>array(),
            'target'=>array(),
            'metrics'=>array('impressions'=>35,'clicks'=>1,'position'=>29,'queries'=>2),
            'objective'=>array('primary'=>'traffic','authority'=>50,'traffic'=>74,'sales'=>40),
            'sources'=>array('Search Console'),
        );
        $unknown_result = seo_analista_validate_and_finalize_task($unknown, 28);
        $first_step = (array) (($unknown_result['investigation_steps'][0] ?? array()));
        $tests[] = seo_analista_381_test_row(
            'ANA382-009',
            (string) ($unknown_result['work_bucket'] ?? '') === 'INVESTIGAR'
                && !empty($first_step['check'])
                && !empty($first_step['current'])
                && !empty($first_step['unlock'])
                && !empty($first_step['owner'])
                && absint($unknown_result['priority_score'] ?? 0) === 79,
            'INVESTIGAR incluye una comprobación auditable y no reduce artificialmente el score.'
        );

        // 10) El diagnóstico revela oportunidades de score alto retenidas por gates.
        $gate_summary = seo_analista_action_gate_summary(array($commercial_result, $unknown_result));
        $tests[] = seo_analista_381_test_row(
            'ANA382-010',
            absint($gate_summary['blocked_commercial'] ?? 0) >= 1
                && absint($gate_summary['blocked_entity'] ?? 0) >= 1
                && absint($gate_summary['high_value_blocked'] ?? 0) >= 2,
            'El diagnóstico separa bloqueos comerciales/de entidad y contabiliza oportunidades valiosas retenidas.'
        );

        $passed = 0;
        foreach ($tests as $test) if (!empty($test['passed'])) $passed++;
        return array(
            'version'=>'3.8.2',
            'total'=>count($tests),
            'passed'=>$passed,
            'failed'=>count($tests) - $passed,
            'tests'=>$tests,
        );
    }
}


if (!function_exists('seo_analista_383_self_tests')) {
    function seo_analista_383_self_tests() {
        $base = seo_analista_382_self_tests();
        $tests = (array) ($base['tests'] ?? array());

        // T01 · Cámaras no puede estar en HACER AHORA si la acción final es solo observar.
        $camera = array(
            'priority'=>72,'work_bucket'=>'HACER_AHORA','confidence'=>78,
            'topic'=>'Cámaras de inspección de tuberías',
            'query'=>'cámaras de inspección de tuberías',
            'entity'=>array(
                'type'=>'category','id'=>0,'title'=>'Cámaras de inspección de tuberías',
                'url'=>home_url('/categoria-producto/camaras-inspeccion-tuberias/')
            ),
            'target'=>array(
                'title'=>'Cámaras de inspección de tuberías',
                'url'=>home_url('/categoria-producto/camaras-inspeccion-tuberias/')
            ),
            'metrics'=>array(
                'impressions'=>33,'clicks'=>1,'ctr'=>0.0303,'position'=>37.8,
                'queries'=>3,'previous_impressions'=>24,'impressions_delta'=>9
            ),
            'objective'=>array('primary'=>'traffic','primary_label'=>'VISITAS','authority'=>55,'traffic'=>70,'sales'=>45),
            'issues'=>array(),
            'sources'=>array('Search Console','WordPress'),
        );
        $camera_result = seo_analista_validate_and_finalize_task($camera, 28);
        $tests[] = seo_analista_381_test_row(
            'ANA383-T01',
            (string) ($camera_result['work_bucket'] ?? '') !== 'HACER_AHORA'
                && (string) ($camera_result['action_type'] ?? '') === 'VIGILAR'
                && empty($camera_result['task_decision']['execution_ready']),
            'Cámaras no permanece en HACER AHORA cuando Qué hacer equivale a observar/mantener línea base.'
        );

        // T02 · Toda tarea HACER AHORA tiene una única acción estructurada y ejecutable.
        $executable = array(
            'priority'=>84,'work_bucket'=>'HACER_AHORA','confidence'=>86,
            'topic'=>'Llaves dinamométricas prueba contrato 383',
            'query'=>'llaves dinamométricas prueba contrato 383',
            'entity'=>array(
                'type'=>'category','id'=>0,'title'=>'Llaves dinamométricas prueba contrato 383',
                'url'=>home_url('/categoria-producto/llaves-dinamometricas-prueba-contrato-383/')
            ),
            'target'=>array(
                'title'=>'Llaves dinamométricas prueba contrato 383',
                'url'=>home_url('/categoria-producto/llaves-dinamometricas-prueba-contrato-383/')
            ),
            'metrics'=>array(
                'impressions'=>80,'clicks'=>5,'ctr'=>0.0625,'position'=>14.5,
                'queries'=>7,'previous_impressions'=>60,'impressions_delta'=>20
            ),
            'objective'=>array('primary'=>'traffic','primary_label'=>'VISITAS','authority'=>60,'traffic'=>82,'sales'=>55),
            'issues'=>array('Cobertura textual baja'),
            'sources'=>array('Search Console','WordPress'),
        );
        $executable_result = seo_analista_validate_and_finalize_task($executable, 28);
        $primary = (array) ($executable_result['primary_action'] ?? array());
        $consistency = seo_analista_plan_consistency_report(array($executable_result));
        $tests[] = seo_analista_381_test_row(
            'ANA383-T02',
            (string) ($executable_result['work_bucket'] ?? '') === 'HACER_AHORA'
                && !empty($primary['executable'])
                && !empty($primary['verb'])
                && !empty($primary['object'])
                && !empty($primary['destination'])
                && !empty($primary['owner'])
                && count((array) ($executable_result['recommended_changes'] ?? array())) === 1
                && !empty($consistency['ok']),
            'HACER AHORA exige verbo, objeto, destino, responsable y una sola acción primaria ejecutable.'
        );

        // T03 · Estado de señal y prioridad de trabajo son conceptos independientes.
        $tests[] = seo_analista_381_test_row(
            'ANA383-T03',
            isset($executable_result['task_decision']['signal_state']['label'])
                && isset($executable_result['task_decision']['work_bucket'])
                && isset($executable_result['task_decision']['intervention_type'])
                && isset($executable_result['task_decision']['blocker']['label'])
                && (string) ($executable_result['task_decision']['work_bucket'] ?? '') === (string) ($executable_result['work_bucket'] ?? ''),
            'La decisión separa Estado de la señal, Prioridad de trabajo, Tipo de intervención y Bloqueo.'
        );

        // T04 · Contadores, contrato y tarjeta usan el mismo bucket final.
        $unknown = array(
            'priority'=>79,'work_bucket'=>'HACER_AHORA','confidence'=>76,
            'topic'=>'Entidad prueba qxz 887766',
            'query'=>'qxz 887766 pieza inexistente',
            'entity'=>array(),'target'=>array(),
            'metrics'=>array('impressions'=>35,'clicks'=>1,'position'=>29,'queries'=>2),
            'objective'=>array('primary'=>'traffic','authority'=>50,'traffic'=>74,'sales'=>40),
            'sources'=>array('Search Console'),
        );
        $unknown_result = seo_analista_validate_and_finalize_task($unknown, 28);
        $plan = array($camera_result, $executable_result, $unknown_result);
        $summary = function_exists('seo_analista_strategy_summary') ? seo_analista_strategy_summary($plan) : array();
        $contracts = array_map('seo_analista_task_contract', $plan);
        $contract_buckets_match = true;
        foreach ($plan as $idx => $plan_row) {
            if ((string) ($plan_row['work_bucket'] ?? '') !== (string) ($contracts[$idx]['final_bucket'] ?? '')) {
                $contract_buckets_match = false;
                break;
            }
        }
        $counted = absint($summary['hacer_ahora'] ?? 0)
            + absint($summary['hacer_despues'] ?? 0)
            + absint($summary['investigar'] ?? 0)
            + absint($summary['vigilar'] ?? 0)
            + absint($summary['esperar_datos'] ?? 0)
            + absint($summary['sin_accion'] ?? 0);
        $tests[] = seo_analista_381_test_row(
            'ANA383-T04',
            absint($summary['total'] ?? 0) === count($plan)
                && $counted === count($plan)
                && $contract_buckets_match
                && !empty(seo_analista_plan_consistency_report($plan)['ok']),
            'El total, contadores y contratos coinciden con las tarjetas después de la reclasificación.'
        );

        // T05 · Resolver un bloqueo conserva un ID de trazabilidad estable.
        $trace_base = array(
            'priority'=>76,'work_bucket'=>'HACER_AHORA','confidence'=>74,
            'topic'=>'Familia trazabilidad qxz 554433',
            'query'=>'familia trazabilidad qxz 554433',
            'metrics'=>array('impressions'=>42,'clicks'=>2,'position'=>24,'queries'=>2),
            'objective'=>array('primary'=>'traffic','authority'=>50,'traffic'=>72,'sales'=>42),
            'sources'=>array('Search Console'),
        );
        $blocked_trace = seo_analista_validate_and_finalize_task(array_merge($trace_base, array(
            'entity'=>array(),'target'=>array()
        )), 28);
        $resolved_trace = seo_analista_validate_and_finalize_task(array_merge($trace_base, array(
            'entity'=>array(
                'type'=>'category','id'=>0,'title'=>'Familia trazabilidad qxz 554433',
                'url'=>home_url('/categoria-producto/familia-trazabilidad-qxz-554433/')
            ),
            'target'=>array(
                'title'=>'Familia trazabilidad qxz 554433',
                'url'=>home_url('/categoria-producto/familia-trazabilidad-qxz-554433/')
            ),
            'issues'=>array('Cobertura textual baja')
        )), 28);
        $tests[] = seo_analista_381_test_row(
            'ANA383-T05',
            (string) ($blocked_trace['task_id'] ?? '') !== ''
                && (string) ($blocked_trace['task_id'] ?? '') === (string) ($resolved_trace['task_id'] ?? '')
                && count(array_unique(array(
                    (string) ($blocked_trace['trace_id'] ?? ''),
                    (string) ($resolved_trace['trace_id'] ?? '')
                ))) === 1,
            'Al resolver un bloqueo la tarea conserva identidad/trazabilidad y no nace como una tarea distinta.'
        );

        // T06 · Casos de regresión: evidencia, URL, modelo, ejecutada y ejecutable.
        $low_evidence = seo_analista_validate_and_finalize_task(array(
            'priority'=>85,'work_bucket'=>'HACER_AHORA','confidence'=>80,
            'topic'=>'Caso evidencia mínima','query'=>'caso evidencia mínima',
            'entity'=>array('type'=>'category','id'=>0,'title'=>'Caso evidencia mínima','url'=>home_url('/categoria-producto/caso-evidencia-minima/')),
            'target'=>array('url'=>home_url('/categoria-producto/caso-evidencia-minima/')),
            'metrics'=>array('impressions'=>1,'clicks'=>0,'position'=>12,'queries'=>1),
            'objective'=>array('primary'=>'traffic','authority'=>55,'traffic'=>80,'sales'=>45),
            'sources'=>array('Search Console')
        ), 28);

        $bad_url = seo_analista_validate_and_finalize_task(array(
            'priority'=>80,'work_bucket'=>'HACER_AHORA','confidence'=>80,
            'topic'=>'Destino ausente qxz 221100','query'=>'destino ausente qxz 221100',
            'entity'=>array(),'target'=>array(),
            'metrics'=>array('impressions'=>30,'clicks'=>1,'position'=>26,'queries'=>2),
            'objective'=>array('primary'=>'traffic','authority'=>50,'traffic'=>75,'sales'=>40),
            'sources'=>array('Search Console')
        ), 28);

        $model_conflict = seo_analista_validate_and_finalize_task(array(
            'priority'=>82,'work_bucket'=>'HACER_AHORA','confidence'=>80,
            'topic'=>'Bosch GBH 18V-18 X','query'=>'GBH 18V-20 Professional',
            'entity'=>array('type'=>'product','id'=>0,'title'=>'Bosch GBH 18V-18 X','url'=>home_url('/producto/bosch-gbh-18v-18-x/')),
            'target'=>array('url'=>home_url('/producto/bosch-gbh-18v-18-x/')),
            'metrics'=>array('impressions'=>45,'clicks'=>2,'position'=>14,'queries'=>2),
            'objective'=>array('primary'=>'traffic','authority'=>60,'traffic'=>72,'sales'=>50),
            'sources'=>array('Search Console','WordPress')
        ), 28);

        $executed = seo_analista_apply_history_state(
            $executable_result,
            array('status'=>'executed','action_executed'=>(string) ($executable_result['action_type'] ?? ''))
        );

        $model_safe = (
            (string) ($model_conflict['match_type'] ?? '') === 'conflict'
            && (string) ($model_conflict['work_bucket'] ?? '') === 'INVESTIGAR'
        ) || (
            !empty($model_conflict['auto_resolution']['resolved'])
            && in_array((string) ($model_conflict['match_type'] ?? ''), array('exact','partial'), true)
            && (string) ($model_conflict['target_url'] ?? '') !== home_url('/producto/bosch-gbh-18v-18-x/')
        );

        $tests[] = seo_analista_381_test_row(
            'ANA383-T06',
            (string) ($low_evidence['work_bucket'] ?? '') === 'ESPERAR_DATOS'
                && (string) ($bad_url['work_bucket'] ?? '') === 'INVESTIGAR'
                && $model_safe
                && (string) ($executed['work_bucket'] ?? '') === 'SIN_ACCION'
                && (string) ($executable_result['work_bucket'] ?? '') === 'HACER_AHORA'
                && !empty($executable_result['task_decision']['execution_ready']),
            'Regresión conjunta: evidencia insuficiente, URL no validada, modelo conflictivo, tarea ya realizada y oportunidad ejecutable.'
        );

        $passed = 0;
        foreach ($tests as $test) if (!empty($test['passed'])) $passed++;
        return array(
            'version'=>'3.8.3',
            'total'=>count($tests),
            'passed'=>$passed,
            'failed'=>count($tests) - $passed,
            'tests'=>$tests,
        );
    }
}
