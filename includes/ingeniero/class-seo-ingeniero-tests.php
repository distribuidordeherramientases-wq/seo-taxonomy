<?php
/**
 * Pruebas funcionales deterministas del proceso editorial de Ingeniero.
 *
 * No crean posts, no modifican conocimiento y no llaman a servicios externos.
 */

defined('ABSPATH') || exit;

final class SEO_Ingeniero_Tests {
    public static function run() {
        $tests = array();

        $base = array(
            array('id'=>1,'knowledge_type'=>'definition','source_ids'=>array(11,12),'confidence'=>0.80,'updated_at'=>'2026-10-02 10:00:00','summary'=>'A'),
            array('id'=>2,'knowledge_type'=>'function','source_ids'=>array(11,12),'confidence'=>0.82,'updated_at'=>'2026-10-02 10:00:00','summary'=>'B'),
        );
        $groups = SEO_Ingeniero::editorial_groups_for_test($base);
        self::add($tests, 'ING-ED-001', count($groups) === 1 && isset($groups['technical-overview']), 'Una sola intención técnica mantiene una propuesta por categoría.');

        $split = array_merge($base, array(
            array('id'=>3,'knowledge_type'=>'safety','source_ids'=>array(21,22),'confidence'=>0.88,'updated_at'=>'2026-10-02 10:00:00','summary'=>'C'),
            array('id'=>4,'knowledge_type'=>'regulation','source_ids'=>array(21,22),'confidence'=>0.90,'updated_at'=>'2026-10-02 10:00:00','summary'=>'D'),
        ));
        $split_groups = SEO_Ingeniero::editorial_groups_for_test($split);
        self::add($tests, 'ING-ED-002', isset($split_groups['fundamentals'],$split_groups['safety']) && count($split_groups) === 2, 'Sólo divide cuando dos intenciones tienen evidencia suficiente.');

        $hash_a = SEO_Ingeniero::editorial_source_hash_for_test($base, array(11,12));
        $hash_b = SEO_Ingeniero::editorial_source_hash_for_test(array_reverse($base), array(12,11));
        self::add($tests, 'ING-ED-003', hash_equals($hash_a,$hash_b), 'El source_hash es estable ante cambios de orden.');

        $changed = $base;
        $changed[0]['summary'] = 'A revisada';
        $hash_c = SEO_Ingeniero::editorial_source_hash_for_test($changed, array(11,12));
        self::add($tests, 'ING-ED-004', !hash_equals($hash_a,$hash_c), 'Un cambio material del conocimiento modifica source_hash.');

        $actions = array(
            SEO_Ingeniero::editorial_action_for_test(array('status'=>'uncovered'),3,3,0.85) === 'CREATE_POST',
            SEO_Ingeniero::editorial_action_for_test(array('status'=>'partial_coverage','post_id'=>33),3,3,0.85) === 'IMPROVE_POST',
            SEO_Ingeniero::editorial_action_for_test(array('status'=>'duplicate'),3,3,0.85) === 'MERGE_CONTENT',
            SEO_Ingeniero::editorial_action_for_test(array('status'=>'covered'),3,3,0.85) === 'NO_ACTION',
            SEO_Ingeniero::editorial_action_for_test(array('status'=>'uncovered'),1,1,0.40) === 'NEEDS_REVIEW',
        );
        self::add($tests, 'ING-ED-005', !in_array(false,$actions,true), 'La matriz de cobertura produce las cinco acciones editoriales requeridas.');

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
        self::add($tests, 'ING-ED-006', is_wp_error($blocked) && true === $allowed, 'El borrador exige aprobación humana previa.');

        global $wpdb;
        $table = SEO_Ingeniero_DB::table('editorial');
        $indexes = (array) $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        $unique_columns = array();
        foreach ($indexes as $row) {
            if ((string) ($row['Key_name'] ?? '') !== 'term_topic' || absint($row['Non_unique'] ?? 1) !== 0) continue;
            $unique_columns[absint($row['Seq_in_index'] ?? 0)] = (string) ($row['Column_name'] ?? '');
        }
        ksort($unique_columns);
        self::add($tests, 'ING-ED-007', array_values($unique_columns) === array('term_id','topic_key'), 'La BD impide duplicar term_id + topic_key.');

        $roles = function_exists('seo_post_editor_public_content_roles') ? seo_post_editor_public_content_roles() : array();
        self::add($tests, 'ING-ED-008', isset($roles['ingeniero_qa_specialized']), 'El rol público estable de Ingeniero está disponible en Entradas.');

        return $tests;
    }

    private static function add(&$tests, $id, $pass, $detail) {
        $tests[] = array(
            'id'=>sanitize_key(strtolower($id)),
            'code'=>$id,
            'pass'=>(bool) $pass,
            'detail'=>(string) $detail,
        );
    }
}
