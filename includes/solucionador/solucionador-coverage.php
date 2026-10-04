<?php
/**
 * Compatibilidad histórica de cobertura para Solucionador.
 *
 * La implementación vive en SEO_Editorial_Coverage. Este wrapper evita romper
 * llamadas antiguas durante la transición de los procesos editoriales.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Coverage {
    public static function rebuild_index($limit = 5000) {
        return SEO_Editorial_Coverage::rebuild_index($limit);
    }

    public static function rebuild_post_index($limit = 5000) {
        return SEO_Editorial_Coverage::rebuild_post_index($limit);
    }

    public static function find(array $profile) {
        global $wpdb;

        // Las comparativas comparten la misma API/fingerprint neutral que
        // Comparador. Solucionador conserva su índice para el resto de intenciones.
        $intent = sanitize_key((string) ($profile['intent'] ?? ''));
        $key_intent = sanitize_key((string) ($profile['key_intent'] ?? ''));
        $action = sanitize_key((string) ($profile['action'] ?? ''));
        $category_id = absint($profile['category_id'] ?? 0);
        if (
            class_exists('SEO_Editorial_Coverage')
            && $category_id
            && (
                strpos($intent,'compar') !== false
                || strpos($key_intent,'compar') !== false
                || $action === 'comparar'
            )
        ) {
            return SEO_Editorial_Coverage::comparison_category(
                $category_id,
                (string) ($profile['object'] ?? '')
            );
        }

        $table = SEO_Solucionador_DB::coverage_table();
        $key = sanitize_text_field((string) ($profile['canonical_key'] ?? ''));
        if ($key === '' || !SEO_Solucionador_DB::table_exists($table)) {
            return array('status'=>'uncovered','entity_type'=>'','entity_id'=>0,'post_id'=>0,'score'=>0,'scope'=>'','matches'=>array());
        }

        $object = sanitize_text_field((string) ($profile['object'] ?? ''));
        $action = sanitize_text_field((string) ($profile['action'] ?? ''));
        $category_id = absint($profile['category_id'] ?? 0);

        $table = esc_sql($table);
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE (
                    canonical_key=%s
                    OR (%s<>'' AND object_term=%s)
                    OR (%s<>'' AND action_term=%s)
                    OR (%d>0 AND category_id=%d)
                 )
                 ORDER BY CASE WHEN canonical_key=%s THEN 0 WHEN category_id=%d AND %d>0 THEN 1 ELSE 2 END,
                          CASE WHEN scope='title' THEN 0 WHEN scope='heading' THEN 1 ELSE 2 END,
                          confidence DESC,id ASC
                 LIMIT 700",
                $key,
                $object,
                $object,
                $action,
                $action,
                $category_id,
                $category_id,
                $key,
                $category_id,
                $category_id
            ),
            ARRAY_A
        );

        $ranked = array();
        foreach ($rows as $row) {
            $score = self::score_row($profile,$row);
            if ($score < 0.42) continue;
            $row['match_score'] = $score;
            $ranked[] = $row;
        }
        usort($ranked,static function($a,$b){ return ($b['match_score'] <=> $a['match_score']); });
        $ranked = array_slice($ranked,0,20);

        if (!$ranked) {
            return array('status'=>'uncovered','entity_type'=>'','entity_id'=>0,'post_id'=>0,'score'=>0,'scope'=>'','matches'=>array());
        }

        $best = $ranked[0];
        $best_score = (float) $best['match_score'];
        $strong = array_values(array_filter($ranked,static function($row){ return (float) ($row['match_score'] ?? 0) >= 0.82; }));
        $entities = array();
        foreach ($strong as $row) $entities[(string)$row['entity_type'] . ':' . absint($row['entity_id'])] = $row;

        $status = 'uncovered';
        if (count($entities) > 1) {
            $status = 'duplicate';
            $strong_values = array_values($entities);
            for ($i=0;$i<count($strong_values);$i++) {
                for ($j=$i+1;$j<count($strong_values);$j++) {
                    if (self::contradiction($strong_values[$i]['source_text'] ?? '',$strong_values[$j]['source_text'] ?? '')) {
                        $status = 'conflict';
                        break 2;
                    }
                }
            }
        } elseif ($best_score >= 0.90 && in_array((string) ($best['scope'] ?? ''),array('title','heading'),true)) {
            $status = 'covered';
        } elseif ($best_score >= 0.72) {
            $status = 'partial_coverage';
        } elseif ($best_score >= 0.52) {
            $status = 'weak_coverage';
        }

        $matches = array();
        foreach (array_slice($ranked,0,10) as $row) {
            $matches[] = array(
                'entity_type'=>(string) ($row['entity_type'] ?? ''),
                'entity_id'=>absint($row['entity_id'] ?? 0),
                'seo_role'=>(string) ($row['seo_role'] ?? ''),
                'category_id'=>absint($row['category_id'] ?? 0),
                'scope'=>(string) ($row['scope'] ?? ''),
                'title'=>(string) ($row['title'] ?? ''),
                'url'=>(string) ($row['url'] ?? ''),
                'source_text'=>(string) ($row['source_text'] ?? ''),
                'score'=>round((float) ($row['match_score'] ?? 0),4),
            );
        }

        $entity_type = (string) ($best['entity_type'] ?? '');
        $entity_id = absint($best['entity_id'] ?? 0);
        return array(
            'status'=>$status,
            'entity_type'=>$entity_type,
            'entity_id'=>$entity_id,
            'post_id'=>$entity_type === 'post' ? $entity_id : 0,
            'score'=>round($best_score,4),
            'scope'=>(string) ($best['scope'] ?? ''),
            'seo_role'=>(string) ($best['seo_role'] ?? ''),
            'category_id'=>absint($best['category_id'] ?? 0),
            'title'=>(string) ($best['title'] ?? ''),
            'url'=>(string) ($best['url'] ?? ''),
            'matches'=>$matches,
        );
    }
}
