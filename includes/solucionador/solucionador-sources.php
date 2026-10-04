<?php
/**
 * Solucionador - adaptador mínimo de fuentes editoriales.
 *
 * Contrato 0.7.1:
 * - FAQ: lectura directa de seo_faq.
 * - Dependiente: conocimiento consolidado expuesto por
 *   SEO_Dependiente_Editorial_Knowledge.
 *
 * Solucionador sólo ve los dos orígenes editoriales faq|dependiente; no conoce
 * candidatos, rechazadas, search_log ni la topología interna del aprendizaje.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Sources {
    const SNAPSHOT_OPTION = 'seo_solucionador_academia_snapshot';

    public static function editorial_source_contract() {
        return array('faq','dependiente');
    }

    public static function all($days = 180,$limit = 100,$after_dossier_id = 0) {
        unset($days);
        if (!class_exists('SEO_Solucionador_Dossiers')) return array();
        return array_values((array)SEO_Solucionador_Dossiers::signals(
            min(500,max(1,absint($limit))),
            absint($after_dossier_id)
        ));
    }

    /**
     * Compatibilidad con informes antiguos.
     */
    public static function dependiente_academia_snapshot() {
        return class_exists('SEO_Solucionador_Dossiers')
            ? (array)SEO_Solucionador_Dossiers::snapshot()
            : array();
    }

    /**
     * Sólo señales originadas en Dependiente. Incluye cualquier conocimiento
     * consolidado que el proveedor haya incorporado al dossier, sin consultar
     * search_log, Marketing ni otros servicios.
     */
    public static function dependiente($days = 180,$limit = 1600) {
        unset($days);
        return array_values(array_filter(
            self::all(180,$limit,0),
            static function($row) {
                return sanitize_key((string)($row['source_type'] ?? '')) === 'dependiente';
            }
        ));
    }

    public static function marketing($limit = 120) {
        unset($limit);
        return array();
    }

    public static function save_academia_snapshot(array $stats) {
        $snapshot = class_exists('SEO_Solucionador_Dossiers')
            ? (array)SEO_Solucionador_Dossiers::snapshot()
            : $stats;
        update_option(self::SNAPSHOT_OPTION,$snapshot,false);
        return $snapshot;
    }
}
