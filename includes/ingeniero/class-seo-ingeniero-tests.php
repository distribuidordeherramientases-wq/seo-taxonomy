<?php
/**
 * Pruebas funcionales deterministas del proceso editorial de Ingeniero 0.3.3.
 *
 * No crean posts, no modifican conocimiento y no llaman a servicios externos.
 */

defined('ABSPATH') || exit;

final class SEO_Ingeniero_Tests {
    public static function run() {
        $tests = array();

        $base = array(
            array('id'=>1,'status'=>'active','knowledge_type'=>'definition','concept'=>'Taladros','source_ids'=>array(11),'confidence'=>0.80,'updated_at'=>'2026-10-02 10:00:00','summary'=>'Definición técnica.'),
            array('id'=>2,'status'=>'active','knowledge_type'=>'function','concept'=>'Taladros','source_ids'=>array(12),'confidence'=>0.82,'updated_at'=>'2026-10-02 10:00:00','summary'=>'Funcionamiento técnico.'),
            array('id'=>3,'status'=>'active','knowledge_type'=>'compatibility','concept'=>'Taladros','source_ids'=>array(13),'confidence'=>0.75,'updated_at'=>'2026-10-02 10:00:00','summary'=>'Compatibilidad técnica.'),
            array('id'=>4,'status'=>'active','knowledge_type'=>'safety','concept'=>'Taladros','source_ids'=>array(14),'confidence'=>0.88,'updated_at'=>'2026-10-02 10:00:00','summary'=>'Seguridad técnica.'),
        );

        $groups = SEO_Ingeniero::editorial_groups_for_test($base);
        self::add(
            $tests,
            'ING-ED-001',
            count($groups)===1 && absint($groups['technical-overview'] ?? 0)===4,
            'Una categoría produce un único dossier technical-overview con TODO el knowledge activo.'
        );

        $mixed = array_merge($base,array(
            array('id'=>5,'status'=>'active','knowledge_type'=>'maintenance','concept'=>'Taladros','source_ids'=>array(15),'confidence'=>0.70,'updated_at'=>'2026-10-02 10:00:00','summary'=>'Mantenimiento técnico.'),
            array('id'=>6,'status'=>'active','knowledge_type'=>'regulation','concept'=>'Taladros','source_ids'=>array(16),'confidence'=>0.90,'updated_at'=>'2026-10-02 10:00:00','summary'=>'Normativa técnica.'),
        ));
        $mixed_groups = SEO_Ingeniero::editorial_groups_for_test($mixed);
        self::add(
            $tests,
            'ING-ED-002',
            count($mixed_groups)===1 && absint($mixed_groups['technical-overview'] ?? 0)===6,
            'No se descartan familias con una sola pieza ni se divide el dossier por masa editorial.'
        );

        $hash_a = SEO_Ingeniero::editorial_source_hash_for_test($mixed,array(11,12,13,14,15,16));
        $hash_b = SEO_Ingeniero::editorial_source_hash_for_test(array_reverse($mixed),array(16,15,14,13,12,11));
        self::add($tests,'ING-ED-003',hash_equals($hash_a,$hash_b),'El source_hash es estable ante cambios de orden.');

        $changed=$mixed;
        $changed[0]['summary']='Definición técnica revisada.';
        $hash_c=SEO_Ingeniero::editorial_source_hash_for_test($changed,array(11,12,13,14,15,16));
        self::add($tests,'ING-ED-004',!hash_equals($hash_a,$hash_c),'Un cambio material del knowledge modifica source_hash.');

        $actions=array(
            SEO_Ingeniero::editorial_action_for_test(array('status'=>'uncovered'),1,1,0.40)==='CREATE_POST',
            SEO_Ingeniero::editorial_action_for_test(array('status'=>'partial_coverage','post_id'=>33),1,1,0.40)==='IMPROVE_POST',
            SEO_Ingeniero::editorial_action_for_test(array('status'=>'duplicate'),1,1,0.40)==='MERGE_CONTENT',
            SEO_Ingeniero::editorial_action_for_test(array('status'=>'covered'),1,1,0.40)==='NO_ACTION',
            SEO_Ingeniero::editorial_action_for_test(array('status'=>'uncovered'),0,0,0.00)==='NEEDS_REVIEW',
        );
        self::add(
            $tests,
            'ING-ED-005',
            !in_array(false,$actions,true),
            'Masa, número de fuentes y confianza son indicadores; un único knowledge activo no queda bloqueado.'
        );

        $qa_ok=true;
        foreach ($mixed as $row) {
            $qa=SEO_Ingeniero::editorial_qa_for_test($row);
            if (empty($qa['question']) || empty($qa['answer']) || empty($qa['item_hash'])) {
                $qa_ok=false;
                break;
            }
        }
        self::add(
            $tests,
            'ING-ED-006',
            $qa_ok,
            'Cada knowledge activo produce una pregunta-respuesta técnica trazable.'
        );

        $baseline=array(
            1=>array('item_hash'=>'a','question'=>'Q1','answer'=>'A1'),
            2=>array('item_hash'=>'b','question'=>'Q2','answer'=>'A2'),
        );
        $current=array(
            1=>array('item_hash'=>'a2','question'=>'Q1','answer'=>'A1 revisada'),
            3=>array('item_hash'=>'c','question'=>'Q3','answer'=>'A3'),
        );
        $changeset=SEO_Ingeniero_Posts::compare_snapshots_for_test($current,$baseline);
        self::add(
            $tests,
            'ING-ED-007',
            $changeset['new']===array(3)
                && $changeset['modified']===array(1)
                && $changeset['retired']===array(2),
            'Las novedades distinguen NUEVO, MODIFICADO y RETIRADO.'
        );

        $blocked = SEO_Ingeniero_Posts::can_create_draft(array(
            'recommended_action'=>'CREATE_POST',
            'status'=>'candidate',
            'term_id'=>10,
        ));
        $allowed = SEO_Ingeniero_Posts::can_create_draft(array(
            'recommended_action'=>'CREATE_POST',
            'status'=>'approved',
            'term_id'=>10,
        ));
        self::add(
            $tests,
            'ING-ED-008',
            is_wp_error($blocked) && true===$allowed,
            'El borrador sigue exigiendo aprobación humana previa.'
        );

        global $wpdb;
        $table=SEO_Ingeniero_DB::table('editorial');
        $indexes=(array)$wpdb->get_results("SHOW INDEX FROM `{$table}`",ARRAY_A);
        $unique_columns=array();
        foreach ($indexes as $row) {
            if ((string)($row['Key_name'] ?? '')!=='term_topic' || absint($row['Non_unique'] ?? 1)!==0) continue;
            $unique_columns[absint($row['Seq_in_index'] ?? 0)]=(string)($row['Column_name'] ?? '');
        }
        ksort($unique_columns);
        self::add(
            $tests,
            'ING-ED-009',
            array_values($unique_columns)===array('term_id','topic_key'),
            'La BD impide duplicar term_id + topic_key.'
        );

        $roles=function_exists('seo_post_editor_public_content_roles') ? seo_post_editor_public_content_roles() : array();
        self::add($tests,'ING-ED-010',isset($roles['ingeniero_qa_specialized']),'El rol público estable de Ingeniero está disponible en Entradas.');

        $one_category_per_cycle=class_exists('SEO_Ingeniero_Process')
            && defined('SEO_INGENIERO_VERSION')
            && SEO_Ingeniero_Process::CATEGORIES_PER_CYCLE===1
            && absint(SEO_Ingeniero::default_state()['batch_size'] ?? 0)===1;
        self::add(
            $tests,
            'ING-ED-011',
            $one_category_per_cycle,
            'El worker sigue limitando memoria a una categoría por ciclo.'
        );

        $export_contract=defined('SEO_Ingeniero_DB::EDITORIAL_EXPORT_CONTRACT')
            && SEO_Ingeniero_DB::EDITORIAL_EXPORT_CONTRACT==='full-category-dossier-v1'
            && method_exists('SEO_Ingeniero_DB','export_payload');
        self::add(
            $tests,
            'ING-ED-012',
            $export_contract,
            'El export declara el contrato full-category-dossier-v1 sin cargar el corpus completo durante la prueba.'
        );

        return $tests;
    }

    private static function add(&$tests,$id,$pass,$detail) {
        $tests[]=array(
            'id'=>sanitize_key(strtolower($id)),
            'code'=>$id,
            'pass'=>(bool)$pass,
            'detail'=>(string)$detail,
        );
    }
}
