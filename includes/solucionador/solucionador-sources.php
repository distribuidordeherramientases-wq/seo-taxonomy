<?php
/**
 * Solucionador - adaptador de fuentes editoriales.
 *
 * Contrato 0.7.1: sólo FAQ y Dependiente pueden originar material editorial.
 * La implementación interna de Dependiente queda encapsulada en
 * SEO_Dependiente_Editorial_Knowledge y no se replica aquí.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Sources {
    const SNAPSHOT_OPTION = 'seo_solucionador_academia_snapshot';

    public static function editorial_source_contract() {
        return array('faq','dependiente');
    }

    /**
     * Fuente canónica para el motor editorial: señales ligeras ya agrupadas por
     * dossier/product_cat. FAQ y Dependiente permanecen como evidencias separadas.
     */
    public static function all($days = 180,$limit = 100,$after_dossier_id = 0) {
        unset($days);
        if (!class_exists('SEO_Solucionador_Dossiers')) return array();
        return array_values((array)SEO_Solucionador_Dossiers::signals(
            min(500,max(1,absint($limit))),
            absint($after_dossier_id)
        ));
    }

    /**
     * Compatibilidad 0.6/0.7 para informes antiguos.
     * El nombre histórico "academia" no define ya el origen editorial.
     */
    public static function dependiente_academia_snapshot() {
        return class_exists('SEO_Solucionador_Dossiers')
            ? (array)SEO_Solucionador_Dossiers::snapshot()
            : array();
    }

    /**
     * Compatibilidad: devuelve únicamente evidencias originadas en Dependiente.
     * No consulta search_log ni otros servicios.
     */
    public static function dependiente($days = 180,$limit = 1600) {
        unset($days);
        $rows = self::all(180,$limit,0);
        return array_values(array_filter($rows,static function($row){
            return sanitize_key((string)($row['source_type'] ?? '')) === 'dependiente';
        }));
    }

    /**
     * Compatibilidad con integraciones antiguas que solicitaban un lote de
     * Academia. La lectura real se delega al proveedor editorial de Dependiente.
     */
    public static function academia_batch($cursor = 0,$limit = 200) {
        if (!class_exists('SEO_Dependiente_Editorial_Knowledge')) {
            return array(
                'sources'=>array(),'next_cursor'=>absint($cursor),'complete'=>true,
                'seen'=>0,'with_category'=>0,'without_category'=>0,
            );
        }
        $batch = SEO_Dependiente_Editorial_Knowledge::batch(
            array('trainer'=>absint($cursor),'semantic'=>0),
            $limit
        );
        $sources = array();
        foreach ((array)($batch['items'] ?? array()) as $item) {
            if (!is_array($item) || empty($item['editorial_candidate'])) continue;
            foreach ((array)($item['category_ids'] ?? array()) as $category_id) {
                $term = get_term(absint($category_id),'product_cat');
                if (!$term || is_wp_error($term)) continue;
                $sources[] = array(
                    'source_type'=>'dependiente',
                    'source_id'=>(string)($item['item_id'] ?? ''),
                    'signal_type'=>'category_editorial_source',
                    'entity_type'=>'product_cat',
                    'entity_id'=>absint($category_id),
                    'category_id'=>absint($category_id),
                    'category_name'=>(string)$term->name,
                    'source_text'=>(string)($item['question'] ?? ''),
                    'hints'=>array(
                        'intent'=>'dependiente_qa_basic','action'=>'resolver',
                        'object'=>(string)$term->name,'category_id'=>absint($category_id),
                    ),
                    'occurrences'=>1,
                    'confidence'=>(float)($item['confidence'] ?? 0.75),
                    'evidence_score'=>1.0,
                    'observed_at'=>(string)($item['last_seen_at'] ?? ''),
                    'source_meta'=>array(
                        'origin'=>'dependiente',
                        'dependiente_source'=>(string)($item['dependiente_source'] ?? ''),
                        'item_id'=>(string)($item['item_id'] ?? ''),
                    ),
                );
            }
        }
        $stats=(array)($batch['stats'] ?? array());
        return array(
            'sources'=>$sources,
            'next_cursor'=>absint($batch['cursor']['trainer'] ?? $cursor),
            'complete'=>!empty($batch['trainer_complete']),
            'seen'=>absint($stats['processed'] ?? 0),
            'with_category'=>absint($stats['with_category'] ?? 0),
            'without_category'=>absint($stats['without_category'] ?? 0),
        );
    }

    public static function academia_question_details(array $question_ids) {
        if (!class_exists('SEO_Dependiente_Editorial_Knowledge')) return array();
        $keys = array();
        foreach (array_values(array_unique(array_filter(array_map('absint',$question_ids)))) as $id) {
            $keys[]='dependiente:trainer:' . $id;
        }
        return array_values(SEO_Dependiente_Editorial_Knowledge::details($keys));
    }

    /**
     * Marketing dejó de ser origen o refuerzo de Solucionador.
     */
    public static function marketing($limit = 120) {
        unset($limit);
        return array();
    }

    /**
     * Snapshot histórico: se conserva para consumidores antiguos, pero el dato
     * canónico reside en SEO_Solucionador_Dossiers.
     */
    public static function save_academia_snapshot(array $stats) {
        $snapshot = class_exists('SEO_Solucionador_Dossiers')
            ? (array)SEO_Solucionador_Dossiers::snapshot()
            : $stats;
        update_option(self::SNAPSHOT_OPTION,$snapshot,false);
        return $snapshot;
    }
}
