<?php
/**
 * Comparador v2 - integraciones con catalogo, Ojeador y WordPress.
 */

defined('ABSPATH') || exit;

final class SEO_Comparador_Integration {
    public static function init() {
        add_filter('seo_data_layer_tables', array('SEO_Comparador_DB','register_data_layer'), 30, 1);
        add_action('save_post_product', array(__CLASS__,'product_changed'), 20, 3);
        add_action('seo_ojeador_category_snapshot_saved', array(__CLASS__,'ojeador_snapshot_changed'), 10, 3);
        add_action('transition_post_status', array(__CLASS__,'post_status_changed'), 30, 3);
    }

    private static function invalidate_category($term_id,$reason,$origin) {
        $term_id = absint($term_id);
        if (!$term_id) return;

        $profile = SEO_Comparador_DB::profile_for_category($term_id);
        if (!$profile) {
            SEO_Comparador_Service::queue_refresh();
            return;
        }

        $profile_id = absint($profile['id'] ?? 0);
        if (!$profile_id) return;
        $map = SEO_Comparador_DB::post_map($profile_id);
        $post_id = absint($map['post_id'] ?? 0);
        $post_status = $post_id ? (string)get_post_status($post_id) : '';

        if ($post_id && $post_status === 'publish') {
            $target = 'needs_update';
        } elseif ($post_id && in_array($post_status,array('draft','pending','private','future'),true)) {
            $target = 'needs_update';
        } else {
            $target = 'detected';
        }

        SEO_Comparador_DB::update_status(
            $profile_id,
            $target,
            'sources_changed',
            $reason,
            $origin
        );
        SEO_Comparador_Service::queue_refresh();
    }

    public static function product_changed($post_id,$post,$update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!$post instanceof WP_Post || $post->post_type !== 'product') return;
        $terms = wp_get_post_terms(absint($post_id),'product_cat',array('fields'=>'ids'));
        if (is_wp_error($terms)) return;
        foreach ((array)$terms as $term_id) {
            self::invalidate_category(
                absint($term_id),
                'El catalogo propio de la categoria ha cambiado y debe recalcularse el analisis de mercado.',
                'catalog'
            );
        }
    }

    public static function ojeador_snapshot_changed($term_id,$summary,$scan) {
        self::invalidate_category(
            absint($term_id),
            'Ojeador ha guardado un nuevo snapshot de mercado para la categoria.',
            'ojeador'
        );
    }

    public static function post_status_changed($new_status,$old_status,$post) {
        if (!$post instanceof WP_Post || $post->post_type !== 'post' || $new_status === $old_status) return;

        global $wpdb;
        $post_map_table = esc_sql(SEO_Comparador_DB::table('post_map'));
        $maps = (array)$wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$post_map_table}` WHERE post_id=%d", absint($post->ID)),
            ARRAY_A
        );

        foreach ($maps as $map) {
            $profile_id = absint($map['profile_id'] ?? 0);
            if (!$profile_id) continue;

            if ($new_status === 'publish') {
                $wpdb->update(SEO_Comparador_DB::table('post_map'),array(
                    'status'=>'published',
                    'published_at'=>$post->post_date ?: current_time('mysql'),
                    'last_synced_at'=>current_time('mysql'),
                ),array('id'=>absint($map['id'])));
                SEO_Comparador_DB::update_status(
                    $profile_id,
                    'published',
                    'post_published',
                    'La Editora ha publicado el informe de mercado.',
                    'wordpress'
                );
                continue;
            }

            if ($new_status === 'trash') {
                $wpdb->update(SEO_Comparador_DB::table('post_map'),array(
                    'status'=>'unlinked',
                    'last_synced_at'=>current_time('mysql'),
                ),array('id'=>absint($map['id'])));
                SEO_Comparador_DB::update_status(
                    $profile_id,
                    'detected',
                    'post_trashed',
                    'El borrador o post vinculado se ha enviado a la papelera; la categoria vuelve a analisis.',
                    'wordpress'
                );
                SEO_Comparador_Service::queue_refresh();
                continue;
            }

            if (in_array($new_status,array('draft','pending','private','future'),true)) {
                $wpdb->update(SEO_Comparador_DB::table('post_map'),array(
                    'status'=>'linked',
                    'last_synced_at'=>current_time('mysql'),
                ),array('id'=>absint($map['id'])));
                SEO_Comparador_DB::update_status(
                    $profile_id,
                    'post_draft',
                    'post_status',
                    'El informe de mercado continua en el flujo editorial.',
                    'wordpress'
                );
            }
        }
    }
}

