<?php
/**
 * Comparador - integraciones desacopladas con catálogo, Ojeador, WordPress y Analista.
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
        $profile=SEO_Comparador_DB::profile_for_category(absint($term_id));
        if (!$profile) return;
        $status=sanitize_key((string)($profile['status'] ?? 'detected'));
        if (in_array($status,array('blocked','archived','needs_update','needs_review'),true)) return;
        $map=SEO_Comparador_DB::post_map(absint($profile['id']));
        $target=!empty($map['post_id']) && get_post_status(absint($map['post_id']))==='publish'
            ? 'needs_update'
            : 'needs_review';
        SEO_Comparador_DB::update_status(absint($profile['id']),$target,'sources_changed',$reason,$origin);
    }

    public static function product_changed($post_id,$post,$update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!$post instanceof WP_Post || $post->post_type!=='product') return;
        $terms=wp_get_post_terms(absint($post_id),'product_cat',array('fields'=>'ids'));
        if (is_wp_error($terms)) return;
        foreach ((array)$terms as $term_id) {
            self::invalidate_category(absint($term_id),'El catálogo propio de la categoría ha cambiado.','catalog');
        }
    }

    public static function ojeador_snapshot_changed($term_id,$summary,$scan) {
        self::invalidate_category(absint($term_id),'Ojeador ha guardado un nuevo snapshot de mercado.','ojeador');
    }

    public static function post_status_changed($new_status,$old_status,$post) {
        if (!$post instanceof WP_Post || $post->post_type!=='post' || $new_status===$old_status) return;
        global $wpdb;
        $post_map_table = esc_sql(SEO_Comparador_DB::table('post_map'));
        $maps = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$post_map_table}` WHERE post_id=%d",
                absint($post->ID)
            ),
            ARRAY_A
        );
        foreach ($maps as $map) {
            $profile_id=absint($map['profile_id'] ?? 0);
            if (!$profile_id) continue;
            if ($new_status==='publish') {
                $wpdb->update(SEO_Comparador_DB::table('post_map'),array(
                    'status'=>'published',
                    'published_at'=>$post->post_date ?: current_time('mysql'),
                    'last_synced_at'=>current_time('mysql'),
                ),array('id'=>absint($map['id'])));
                SEO_Comparador_DB::update_status($profile_id,'published','post_published','La Editora ha publicado la comparativa canónica.','wordpress');
            } elseif ($new_status==='trash') {
                $wpdb->update(SEO_Comparador_DB::table('post_map'),array(
                    'status'=>'unlinked',
                    'last_synced_at'=>current_time('mysql'),
                ),array('id'=>absint($map['id'])));
                SEO_Comparador_DB::update_status($profile_id,'needs_review','post_trashed','El post canónico vinculado se ha enviado a la papelera.','wordpress');
            } elseif (in_array($new_status,array('draft','pending','private','future'),true)) {
                $wpdb->update(SEO_Comparador_DB::table('post_map'),array(
                    'status'=>'linked',
                    'last_synced_at'=>current_time('mysql'),
                ),array('id'=>absint($map['id'])));
                SEO_Comparador_DB::update_status($profile_id,'post_draft','post_status','La comparativa canónica continúa en flujo editorial.','wordpress');
            }
        }
    }
}
