<?php
/**
 * Solucionador - creacion de borradores aprobados.
 */

defined('ABSPATH') || exit;

final class SEO_Solucionador_Posts {
    const META_TOPIC_ID = '_seo_solucionador_topic_id';
    const META_CANONICAL_KEY = '_seo_solucionador_canonical_key';

    public static function init() {
        add_action('transition_post_status', array(__CLASS__, 'transition_post_status'), 10, 3);
        add_action('before_delete_post', array(__CLASS__, 'before_delete_post'), 20, 1);
    }

    public static function edit_url($post_id) {
        $post_id = absint($post_id);
        if (!$post_id) return '';
        return add_query_arg(
            array('page'=>'seo-post-editor','post_id'=>$post_id),
            admin_url('edit.php')
        );
    }

    private static function category_ids(array $topic) {
        $ids = array();
        foreach (SEO_Solucionador_DB::proposed_categories($topic) as $row) {
            if (is_array($row) && !empty($row['id'])) $ids[] = absint($row['id']);
        }
        return array_values(array_unique(array_filter($ids)));
    }

    private static function vocabulary_ids_by_group(array $topic) {
        $proposal = SEO_Solucionador_DB::proposed_vocabulary($topic);
        $out = array();
        $groups = function_exists('seo_content_vocab_groups')
            ? array_keys(seo_content_vocab_groups())
            : array('rol','tipo','aplicacion','plataforma','subtipo');
        foreach ($groups as $group) {
            $out[$group] = array();
            foreach ((array) ($proposal[$group] ?? array()) as $row) {
                if (is_array($row) && !empty($row['id'])) $out[$group][] = absint($row['id']);
            }
            $out[$group] = array_values(array_unique(array_filter($out[$group])));
        }
        return $out;
    }

    private static function assign_vocabulary($post_id, array $topic) {
        if (!function_exists('seo_content_vocab_replace_manual_group')) {
            return new WP_Error('solucionador_vocab_api', 'No esta disponible la API canonica de Vocabulary para posts.');
        }
        foreach (self::vocabulary_ids_by_group($topic) as $group => $ids) {
            $result = seo_content_vocab_replace_manual_group('post', $post_id, $group, $ids);
            if (is_wp_error($result)) return $result;
        }
        return true;
    }

    private static function assign_categories($post_id, array $topic) {
        $ids = self::category_ids($topic);
        if (function_exists('seo_post_editor_replace_product_cat_relations')) {
            return seo_post_editor_replace_product_cat_relations($post_id, $ids);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'seo_relations';
        if (!SEO_Solucionador_DB::table_exists($table)) {
            return new WP_Error('solucionador_relations_table', 'No existe la tabla seo_relations.');
        }

        foreach ($ids as $term_id) {
            $term = get_term($term_id, 'product_cat');
            if (!$term || is_wp_error($term)) {
                return new WP_Error('solucionador_invalid_category', 'Una categoria propuesta ya no existe.');
            }
        }

        $wpdb->delete($table, array(
            'source_type' => 'post',
            'source_id' => $post_id,
            'target_type' => 'product_cat',
            'relation_type' => 'post_to_category',
        ));

        foreach ($ids as $term_id) {
            $ok = $wpdb->insert($table, array(
                'source_type' => 'post',
                'source_id' => $post_id,
                'target_type' => 'product_cat',
                'target_id' => $term_id,
                'relation_type' => 'post_to_category',
                'created_at' => current_time('mysql'),
            ));
            if (false === $ok) {
                return new WP_Error('solucionador_relation_write', 'No se pudo guardar la relacion post_to_category.');
            }
        }
        return true;
    }

    public static function create_draft($topic_id) {
        $topic_id = absint($topic_id);
        $topic = SEO_Solucionador_DB::get_topic($topic_id);
        if (!$topic) return new WP_Error('solucionador_topic_missing', 'La propuesta ya no existe.');

        $existing_draft = absint($topic['draft_post_id'] ?? 0);
        if ($existing_draft && get_post_type($existing_draft) === 'post' && get_post_status($existing_draft) !== 'trash') {
            return $existing_draft;
        }

        $title = sanitize_text_field((string) ($topic['suggested_title'] ?? ''));
        if ($title === '') return new WP_Error('solucionador_title_missing', 'La propuesta no tiene titulo.');
        if ((string) ($topic['recommended_action'] ?? '') !== 'create_post') {
            return new WP_Error('solucionador_not_new_post', 'Esta propuesta no requiere crear un post nuevo.');
        }

        $proposal = array(
            'categories' => SEO_Solucionador_DB::proposed_categories($topic),
            'vocabulary' => SEO_Solucionador_DB::proposed_vocabulary($topic),
        );
        if (!SEO_Solucionador_Catalog::proposal_ready($proposal)) {
            return new WP_Error(
                'solucionador_proposal_not_ready',
                'La propuesta no tiene aun categoria y Vocabulary suficientes para crear un borrador homogeneo.'
            );
        }

        $post_id = wp_insert_post(wp_slash(array(
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => $title,
            'post_content' => '',
            'post_excerpt' => '',
            'post_author' => get_current_user_id(),
        )), true);
        if (is_wp_error($post_id) || absint($post_id) <= 0) {
            return is_wp_error($post_id) ? $post_id : new WP_Error('solucionador_post_create', 'WordPress no pudo crear el borrador.');
        }
        $post_id = absint($post_id);

        update_post_meta($post_id, self::META_TOPIC_ID, $topic_id);
        update_post_meta($post_id, self::META_CANONICAL_KEY, (string) ($topic['canonical_key'] ?? ''));

        $vocab_result = self::assign_vocabulary($post_id, $topic);
        if (is_wp_error($vocab_result)) {
            wp_delete_post($post_id, true);
            return $vocab_result;
        }

        $relation_result = self::assign_categories($post_id, $topic);
        if (is_wp_error($relation_result)) {
            wp_delete_post($post_id, true);
            return $relation_result;
        }

        SEO_Solucionador_DB::update_topic($topic_id, array(
            'status' => 'draft_created',
            'coverage_status' => 'draft_pending',
            'draft_post_id' => $post_id,
            'approved_at' => current_time('mysql'),
            'draft_created_at' => current_time('mysql'),
        ));

        return $post_id;
    }

    public static function transition_post_status($new_status, $old_status, $post) {
        if (!$post instanceof WP_Post || $post->post_type !== 'post') return;
        $topic_id = absint(get_post_meta($post->ID, self::META_TOPIC_ID, true));
        if (!$topic_id) return;

        if ($new_status === 'publish') {
            SEO_Solucionador_DB::update_topic($topic_id, array(
                'status' => 'covered',
                'coverage_status' => 'covered_exact',
                'existing_post_id' => absint($post->ID),
                'draft_post_id' => absint($post->ID),
            ));
            return;
        }

        if ($new_status === 'trash') {
            self::release_topic($topic_id, $post->ID);
        }
    }

    public static function before_delete_post($post_id) {
        $post_id = absint($post_id);
        if (!$post_id || get_post_type($post_id) !== 'post') return;
        $topic_id = absint(get_post_meta($post_id, self::META_TOPIC_ID, true));
        if ($topic_id) self::release_topic($topic_id, $post_id);
    }

    private static function release_topic($topic_id, $post_id) {
        $topic = SEO_Solucionador_DB::get_topic($topic_id);
        if (!$topic) return;
        $changes = array(
            'status' => 'candidate',
            'coverage_status' => 'uncovered',
            'recommended_action' => 'create_post',
            'draft_post_id' => null,
        );
        if (absint($topic['existing_post_id'] ?? 0) === absint($post_id)) $changes['existing_post_id'] = null;
        SEO_Solucionador_DB::update_topic($topic_id, $changes);
    }
}
