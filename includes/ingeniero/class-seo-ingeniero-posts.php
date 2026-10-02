<?php
/**
 * Creación y seguimiento de posts del proceso editorial propio de Ingeniero.
 */

defined('ABSPATH') || exit;

final class SEO_Ingeniero_Posts {
    const META_EDITORIAL_ID = '_seo_ingeniero_editorial_id';
    const META_TOPIC_KEY = '_seo_ingeniero_topic_key';
    const META_SOURCE_HASH = '_seo_ingeniero_source_hash';
    const META_CONTENT_ROLE = '_seo_solucionador_content_role';

    public static function init() {
        add_action('transition_post_status', array(__CLASS__, 'transition_post_status'), 20, 3);
        add_action('before_delete_post', array(__CLASS__, 'before_delete_post'), 30, 1);
    }

    public static function edit_url($post_id) {
        $post_id = absint($post_id);
        return $post_id
            ? add_query_arg(array('page'=>'seo-post-editor','post_id'=>$post_id), admin_url('edit.php'))
            : '';
    }

    public static function can_create_draft(array $dossier) {
        if ('CREATE_POST' !== strtoupper((string) ($dossier['recommended_action'] ?? ''))) {
            return new WP_Error('ingeniero_editorial_action', 'Esta propuesta no requiere crear un post nuevo.');
        }
        if ('approved' !== sanitize_key((string) ($dossier['status'] ?? ''))) {
            return new WP_Error('ingeniero_editorial_not_approved', 'La propuesta debe aprobarse antes de crear el borrador.');
        }
        if (absint($dossier['term_id'] ?? 0) < 1) {
            return new WP_Error('ingeniero_editorial_category', 'La propuesta no tiene categoría válida.');
        }
        return true;
    }

    public static function create_draft($editorial_id) {
        $editorial_id = absint($editorial_id);
        $dossier = SEO_Ingeniero_DB::editorial_get($editorial_id);
        if (!$dossier) return new WP_Error('ingeniero_editorial_missing', 'La propuesta editorial no existe.');

        $post_id = absint($dossier['post_id'] ?? 0);
        if ($post_id && 'post' === get_post_type($post_id) && 'trash' !== get_post_status($post_id)) {
            return $post_id;
        }

        $allowed = self::can_create_draft($dossier);
        if (is_wp_error($allowed)) return $allowed;

        $term_id = absint($dossier['term_id'] ?? 0);
        $term = $term_id ? get_term($term_id, 'product_cat') : null;
        if (!$term || is_wp_error($term)) {
            return new WP_Error('ingeniero_editorial_category', 'La categoría del dossier ya no existe.');
        }

        $title = sanitize_text_field((string) ($dossier['suggested_title'] ?? ''));
        if ('' === $title) return new WP_Error('ingeniero_editorial_title', 'La propuesta no tiene título.');

        $post_id = wp_insert_post(wp_slash(array(
            'post_type'    => 'post',
            'post_status'  => 'draft',
            'post_title'   => $title,
            'post_content' => '',
            'post_excerpt' => '',
            'post_author'  => get_current_user_id(),
        )), true);
        if (is_wp_error($post_id) || absint($post_id) < 1) {
            return is_wp_error($post_id) ? $post_id : new WP_Error('ingeniero_editorial_post', 'WordPress no pudo crear el borrador.');
        }
        $post_id = absint($post_id);

        update_post_meta($post_id, self::META_EDITORIAL_ID, $editorial_id);
        update_post_meta($post_id, self::META_TOPIC_KEY, (string) ($dossier['topic_key'] ?? ''));
        update_post_meta($post_id, self::META_SOURCE_HASH, (string) ($dossier['source_hash'] ?? ''));
        update_post_meta($post_id, self::META_CONTENT_ROLE, 'ingeniero_qa_specialized');

        $relation = self::assign_category($post_id, $term_id);
        if (is_wp_error($relation)) {
            wp_delete_post($post_id, true);
            return $relation;
        }

        $vocabulary = self::assign_category_vocabulary($post_id, $term_id);
        if (is_wp_error($vocabulary)) {
            wp_delete_post($post_id, true);
            return $vocabulary;
        }

        SEO_Ingeniero_DB::update_editorial($editorial_id, array(
            'status'  => 'draft',
            'post_id' => $post_id,
        ));

        return $post_id;
    }

    private static function assign_category($post_id, $term_id) {
        if (function_exists('seo_post_editor_replace_product_cat_relations')) {
            return seo_post_editor_replace_product_cat_relations($post_id, array($term_id));
        }
        return new WP_Error('ingeniero_relations_api', 'No está disponible la API canónica post_to_category.');
    }

    private static function assign_category_vocabulary($post_id, $term_id) {
        if (!function_exists('seo_content_vocab_replace_manual_group') || !function_exists('seo_content_vocab_groups')) {
            return new WP_Error('ingeniero_vocab_api', 'No está disponible la API canónica de Vocabulary.');
        }

        global $wpdb;
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id,v.semantic_group
                 FROM {$objects} ov
                 INNER JOIN {$vocabulary} v
                    ON v.id=ov.vocabulary_id
                   AND v.active=1
                 WHERE ov.object_type='product_cat'
                   AND ov.object_id=%d
                   AND ov.status=1
                   AND v.semantic_group IN ('rol','tipo','aplicacion','plataforma','subtipo')
                 ORDER BY v.semantic_group,v.id",
                absint($term_id)
            ),
            ARRAY_A
        );

        $by_group = array();
        foreach ($rows as $row) {
            $group = sanitize_key((string) ($row['semantic_group'] ?? ''));
            if (!isset(seo_content_vocab_groups()[$group])) continue;
            $by_group[$group][] = absint($row['id'] ?? 0);
        }

        foreach (array_keys(seo_content_vocab_groups()) as $group) {
            $result = seo_content_vocab_replace_manual_group(
                'post',
                $post_id,
                $group,
                array_values(array_unique(array_filter((array) ($by_group[$group] ?? array()))))
            );
            if (is_wp_error($result)) return $result;
        }

        return true;
    }

    public static function transition_post_status($new_status, $old_status, $post) {
        if (!$post instanceof WP_Post || 'post' !== $post->post_type) return;
        $editorial_id = absint(get_post_meta($post->ID, self::META_EDITORIAL_ID, true));
        if (!$editorial_id) return;

        if ('publish' === $new_status) {
            SEO_Ingeniero_DB::update_editorial($editorial_id, array(
                'status'  => 'published',
                'post_id' => absint($post->ID),
            ));
            return;
        }

        if (in_array($new_status, array('draft','pending','private','future'), true)) {
            SEO_Ingeniero_DB::update_editorial($editorial_id, array(
                'status'  => 'draft',
                'post_id' => absint($post->ID),
            ));
        }
    }

    public static function before_delete_post($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || 'post' !== get_post_type($post_id)) return;
        $editorial_id = absint(get_post_meta($post_id, self::META_EDITORIAL_ID, true));
        if (!$editorial_id) return;

        $dossier = SEO_Ingeniero_DB::editorial_get($editorial_id);
        SEO_Ingeniero_DB::update_editorial($editorial_id, array(
            'status'  => !empty($dossier['source_hash']) ? 'approved' : 'candidate',
            'post_id' => 0,
        ));
    }
}

SEO_Ingeniero_Posts::init();
