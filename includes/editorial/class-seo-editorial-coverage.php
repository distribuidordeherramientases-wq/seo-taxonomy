<?php
/**
 * API neutral de cobertura editorial.
 *
 * Punto común para servicios editoriales especializados. Reutiliza inicialmente
 * el índice de cobertura ya mantenido por Solucionador, pero evita que los
 * consumidores dependan de Solucionador como servicio de decisión.
 */

defined('ABSPATH') || exit;

final class SEO_Editorial_Coverage {
    public static function find(array $profile) {
        if (class_exists('SEO_Solucionador_Coverage')) {
            return SEO_Solucionador_Coverage::find($profile);
        }

        return array(
            'status'      => 'uncovered',
            'entity_type' => '',
            'entity_id'   => 0,
            'post_id'     => 0,
            'score'       => 0,
            'scope'       => '',
            'seo_role'    => '',
            'category_id' => absint($profile['category_id'] ?? 0),
            'title'       => '',
            'url'         => '',
            'matches'     => array(),
        );
    }

    public static function technical_profile($term_id, $title, $summary = '') {
        $term_id = absint($term_id);
        $title = sanitize_text_field((string) $title);
        $summary = sanitize_textarea_field((string) $summary);
        $term = $term_id ? get_term($term_id, 'product_cat') : null;
        $category_name = $term && !is_wp_error($term) ? (string) $term->name : '';

        if (class_exists('SEO_Solucionador_Normalizer')) {
            $profile = SEO_Solucionador_Normalizer::profile(
                trim($title . ' ' . $summary),
                array(
                    'category_id' => $term_id,
                    'object'      => $category_name,
                )
            );
            if (is_array($profile)) {
                $profile['category_id'] = $term_id;
                return $profile;
            }
        }

        return array(
            'intent'        => 'technical',
            'action'        => 'explicar',
            'object'        => $category_name,
            'condition'     => '',
            'context'       => 'technical',
            'canonical_key' => 'technical|' . $term_id . '|' . sanitize_title($title),
            'category_id'   => $term_id,
            'confidence'    => 0.7,
        );
    }

    public static function find_technical($term_id, $title, $summary = '') {
        return self::find(self::technical_profile($term_id, $title, $summary));
    }
}
