<?php
/**
 * Cobertura editorial neutral compartida.
 *
 * No depende de Solucionador. Trabaja sobre contenido WordPress persistido,
 * Vocabulary y relaciones post_to_category para que los servicios editoriales
 * compartan una lectura estable de cobertura sin recalcular fuentes externas.
 */

defined('ABSPATH') || exit;

final class SEO_Editorial_Coverage {
    private static function normalize($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = remove_accents($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/[^a-z0-9\s-]+/u', ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function tokens($value) {
        $stop = array_flip(array(
            'para','por','con','sin','del','las','los','una','uno','unos','unas','que','como',
            'guia','guias','comparativa','comparativas','comparar','mejor','mejores','elegir',
            'producto','productos','categoria','categorias','comprar','compra','opcion','opciones',
            'todo','sobre','entre','desde','hasta','este','esta','estos','estas'
        ));
        $out = array();
        foreach (preg_split('/\s+/u', self::normalize($value), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            if (strlen($token) < 3 || isset($stop[$token])) continue;
            $out[$token] = true;
        }
        return array_keys($out);
    }

    private static function similarity($left, $right) {
        $a = array_flip(self::tokens($left));
        $b = array_flip(self::tokens($right));
        if (!$a || !$b) return 0.0;
        $intersection = count(array_intersect_key($a, $b));
        $union = count($a + $b);
        return $union > 0 ? $intersection / $union : 0.0;
    }

    private static function table_exists($table) {
        global $wpdb;
        return $table !== '' && (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;
    }

    private static function category_post_ids($category_id) {
        global $wpdb;
        $relations = $wpdb->prefix . 'seo_relations';
        if (!self::table_exists($relations)) return array();

        return array_values(array_unique(array_filter(array_map('absint', (array) $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT source_id
                 FROM {$relations}
                 WHERE source_type='post'
                   AND target_type='product_cat'
                   AND relation_type='post_to_category'
                   AND target_id=%d
                 ORDER BY source_id ASC
                 LIMIT 500",
                absint($category_id)
            )
        )))));
    }

    private static function content_role($post_id) {
        return sanitize_key((string) get_post_meta(absint($post_id), '_seo_solucionador_content_role', true));
    }

    private static function is_comparison_post($post_id) {
        if (self::content_role($post_id) === 'comparison') return true;
        return has_term('comparativas', 'post_tag', absint($post_id));
    }

    /**
     * Cobertura de una familia comparable.
     *
     * Devuelve un contrato neutral que Comparador puede usar aunque Solucionador
     * no este cargado. No consulta Ojeador, Google Shopping ni servicios externos.
     */
    public static function comparison_category($category_id, $subject = '', $canonical_post_id = 0) {
        $category_id = absint($category_id);
        $canonical_post_id = absint($canonical_post_id);
        $term = get_term($category_id, 'product_cat');
        if (!$category_id || !$term || is_wp_error($term)) {
            return array(
                'status'=>'uncovered','score'=>0.0,'entity_type'=>'','entity_id'=>0,
                'post_id'=>0,'matches'=>array(),'fingerprint'=>'',
            );
        }

        $subject = trim((string) $subject);
        if ($subject === '') $subject = (string) $term->name;
        $fingerprint = hash('sha256', 'comparison|product_cat|' . $category_id . '|' . implode('|', self::tokens($subject)));

        $matches = array();
        foreach (self::category_post_ids($category_id) as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof WP_Post || $post->post_type !== 'post' || $post->post_status === 'trash') continue;

            $text = trim((string) $post->post_title . ' ' . (string) $post->post_excerpt);
            if ($text === '') $text = (string) $post->post_title;
            $similarity = self::similarity($subject, $text);
            $comparison = self::is_comparison_post($post_id);
            $role = self::content_role($post_id);

            // La relacion exacta con la misma categoria es la evidencia principal.
            // La similitud evita que una noticia incidental bloquee una comparativa.
            $score = min(1.0, ($comparison ? 0.60 : 0.28) + ($similarity * 0.40));
            if ($post_id === $canonical_post_id) $score = max($score, 0.98);
            if ($score < 0.42) continue;

            $matches[] = array(
                'entity_type'=>'post',
                'entity_id'=>$post_id,
                'post_id'=>$post_id,
                'status'=>(string) $post->post_status,
                'title'=>(string) $post->post_title,
                'url'=>(string) get_permalink($post_id),
                'comparison_role'=>$comparison ? 1 : 0,
                'content_role'=>$role,
                'score'=>round($score, 4),
            );
        }

        usort($matches, static function($a, $b) {
            return ((float) $b['score'] <=> (float) $a['score']);
        });
        $matches = array_slice($matches, 0, 20);

        if (!$matches) {
            return array(
                'status'=>'uncovered','score'=>0.0,'entity_type'=>'','entity_id'=>0,
                'post_id'=>0,'matches'=>array(),'fingerprint'=>$fingerprint,
            );
        }

        $strong = array_values(array_filter($matches, static function($row) {
            return (float) ($row['score'] ?? 0) >= 0.82;
        }));
        $best = $matches[0];
        $status = 'weak_coverage';

        if (count($strong) > 1) {
            $status = 'duplicate';
        } elseif ((float) $best['score'] >= 0.90 && !empty($best['comparison_role'])) {
            $status = 'covered';
        } elseif ((float) $best['score'] >= 0.68) {
            $status = 'partial_coverage';
        }

        return array(
            'status'=>$status,
            'score'=>round((float) $best['score'], 4),
            'entity_type'=>'post',
            'entity_id'=>absint($best['post_id']),
            'post_id'=>absint($best['post_id']),
            'matches'=>$matches,
            'fingerprint'=>$fingerprint,
        );
    }
}
