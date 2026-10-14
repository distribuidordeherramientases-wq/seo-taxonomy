<?php
/**
 * Editor: catálogo ligero de borradores y decisiones explícitas.
 *
 * Reutiliza posts, páginas y relaciones canónicas; no crea un motor editorial.
 */
defined('ABSPATH') || exit;

final class SEO_Editor_Core {
    const MAX_POSTS = 250;
    const MAX_LANDINGS = 125;
    const PAGE_SIZE = 30;

    public static function kind($post) {
        $post = get_post($post);
        if (!$post instanceof WP_Post) return '';
        if ($post->post_type === 'post') return 'post';
        if ($post->post_type !== 'page') return '';
        if (absint(get_post_meta($post->ID, '_seo_editorial_landing_candidate_id', true))) return 'landing';
        if (absint(get_post_meta($post->ID, '_seo_editor_external_imported', true))
            && get_post_meta($post->ID, '_seo_editor_external_kind', true) === 'landing') return 'landing';
        if (function_exists('seo_page_editor_get_node') && seo_page_editor_get_node($post->ID, 'landing')) return 'landing';
        return '';
    }

    public static function source($post) {
        $post = get_post($post);
        if (!$post instanceof WP_Post) return '';
        $id = $post->ID;
        if (absint(get_post_meta($id, '_seo_editor_external_imported', true))) return 'Externo';
        if ($post->post_type === 'page') {
            $dossier = get_post_meta($id, '_seo_editorial_landing_source_dossier', true);
            if (is_array($dossier) && !empty($dossier['services'])) {
                $services = array_intersect(array('solucionador','ingeniero'), array_keys((array)$dossier['services']));
                if ($services) return implode(' + ', array_map('ucfirst', $services));
            }
            return 'Landings';
        }
        if (absint(get_post_meta($id, '_seo_ingeniero_editorial_id', true))) return 'Ingeniero';
        if (absint(get_post_meta($id, '_seo_solucionador_topic_id', true))) return 'Solucionador';
        if (absint(get_post_meta($id, '_seo_comparador_profile_id', true))) return 'Comparador';
        if (self::is_news($id)) return 'Noticias';
        return 'Manual';
    }

    public static function is_news($id) {
        foreach ((array)get_the_category(absint($id)) as $term) {
            if (sanitize_title((string)($term->slug ?? '')) === 'noticias') return true;
        }
        return false;
    }

    public static function category_ids($post) {
        $post = get_post($post);
        if (!$post instanceof WP_Post) return array();
        if ($post->post_type === 'post' && function_exists('seo_post_editor_get_product_cat_ids')) {
            return array_values(array_map('absint', (array)seo_post_editor_get_product_cat_ids($post->ID)));
        }
        if ($post->post_type === 'page' && function_exists('seo_page_editor_get_landing_category_ids')) {
            return array_values(array_map('absint', (array)seo_page_editor_get_landing_category_ids($post->ID)));
        }
        return array();
    }

    public static function fingerprint($post) {
        $post = get_post($post);
        if (!$post instanceof WP_Post) return '';
        return hash('sha256', implode('|', array(
            $post->ID, $post->post_type, $post->post_status,
            $post->post_modified_gmt, $post->post_title,
            $post->post_excerpt, $post->post_content,
        )));
    }

    public static function drafts($filter = array()) {
        // Límites fijos: no leer toda wp_posts ni escanear productos.
        $ids = array_merge(
            (array)get_posts(array('post_type'=>'post','post_status'=>'draft','posts_per_page'=>self::MAX_POSTS,'fields'=>'ids','orderby'=>'modified','order'=>'DESC','suppress_filters'=>true)),
            (array)get_posts(array('post_type'=>'page','post_status'=>'draft','posts_per_page'=>self::MAX_LANDINGS,'fields'=>'ids','orderby'=>'modified','order'=>'DESC','suppress_filters'=>true))
        );
        $rows = array();
        $kind_filter = sanitize_key((string)($filter['tipo'] ?? ''));
        $source_filter = sanitize_key((string)($filter['origen'] ?? ''));
        $search = remove_accents(mb_strtolower(sanitize_text_field((string)($filter['buscar'] ?? ''))));
        foreach ($ids as $id) {
            $post = get_post(absint($id));
            if (!$post instanceof WP_Post || $post->post_status !== 'draft') continue;
            $kind = self::kind($post);
            if ($kind === '') continue;
            $source = self::source($post);
            if ($kind_filter !== '' && $kind !== $kind_filter) continue;
            if ($source_filter !== '' && sanitize_key($source) !== $source_filter) continue;
            if ($search !== '' && strpos(remove_accents(mb_strtolower($post->post_title)), $search) === false
                && strpos((string)$post->ID, $search) === false) continue;
            $rows[] = $post;
        }
        usort($rows, static function($a,$b) {
            return strcmp((string)$b->post_modified_gmt, (string)$a->post_modified_gmt);
        });
        return $rows;
    }

    public static function readiness($post, $title = null, $content = null) {
        $post = get_post($post);
        if (!$post instanceof WP_Post || $post->post_status !== 'draft') {
            return array('El contenido ya no es un borrador.');
        }
        $kind = self::kind($post);
        if (!$kind) return array('No es un post editorial ni una landing.');
        $title = $title === null ? (string)$post->post_title : (string)$title;
        $content = $content === null ? (string)$post->post_content : (string)$content;
        $text = trim(wp_strip_all_tags(strip_shortcodes($content)));
        $errors = array();
        if (mb_strlen(trim($title)) < 15) $errors[] = 'Título insuficiente.';
        if (mb_strlen($text) < 300) $errors[] = 'Contenido demasiado breve para publicación directa.';
        if (preg_match('/borrador editorial de ingeniero|dossier interno para editora|debe revisarse, contrastarse|material de trabajo para la editora|no publicar literalmente|pendiente de completar|lorem ipsum|\[por completar\]/iu', $text)) {
            $errors[] = 'Contiene instrucciones internas, material sin finalizar o marcadores de posición.';
        }
        $cats = self::category_ids($post);
        if ($kind === 'landing' && count($cats) < 2) {
            $errors[] = 'La landing no tiene dos categorías comerciales vinculadas.';
        }
        if ($kind === 'post' && !self::is_news($post->ID) && !$cats) {
            $errors[] = 'El post no tiene una relación comercial post_to_category.';
        }
        return $errors;
    }

    public static function apply_decision($post_id, $decision) {
        $post = get_post(absint($post_id));
        if (!$post instanceof WP_Post || $post->post_status !== 'draft' || !self::kind($post)) {
            return new WP_Error('editor_not_draft', 'La propuesta ya no es un borrador elegible.');
        }
        $decision = sanitize_key($decision);
        if ($decision === 'publish') {
            if (!current_user_can('publish_posts') || !current_user_can('edit_post', $post->ID)) {
                return new WP_Error('editor_publish_cap', 'Permisos insuficientes para publicar.');
            }
            if ($post->post_type === 'page' && !current_user_can('publish_pages')) {
                return new WP_Error('editor_publish_page', 'Permisos insuficientes para publicar landings.');
            }
            $reasons = self::readiness($post);
            if ($reasons) return new WP_Error('editor_not_ready', implode(' ', $reasons));
            $result = wp_update_post(wp_slash(array('ID'=>$post->ID,'post_status'=>'publish')), true);
            if (is_wp_error($result)) return $result;
            if (get_post_status($post->ID) !== 'publish') return new WP_Error('editor_publish_failed', 'WordPress no confirmó la publicación.');
        } elseif ($decision === 'trash') {
            if (!current_user_can('delete_post', $post->ID)) {
                return new WP_Error('editor_trash_cap', 'Permisos insuficientes para enviar a la papelera.');
            }
            if (!wp_trash_post($post->ID)) return new WP_Error('editor_trash_failed', 'No se pudo enviar a la papelera.');
        } else {
            return new WP_Error('editor_decision_invalid', 'Decisión desconocida.');
        }
        update_post_meta($post->ID, '_seo_editor_review_decision', $decision);
        update_post_meta($post->ID, '_seo_editor_reviewed_by', get_current_user_id());
        update_post_meta($post->ID, '_seo_editor_reviewed_at', current_time('mysql'));
        return true;
    }

    public static function validate_categories($raw) {
        $raw = (string)$raw;
        $ids = preg_split('/[\s,;|]+/', trim($raw));
        $ids = array_values(array_unique(array_filter(array_map('absint', (array)$ids))));
        foreach ($ids as $id) {
            $term = get_term($id, 'product_cat');
            if (!$term || is_wp_error($term)) return new WP_Error('editor_invalid_cat', 'La categoría product_cat #' . $id . ' no existe.');
        }
        return $ids;
    }

    public static function create_external(array $row) {
        $kind = sanitize_key((string)($row['tipo'] ?? ''));
        $section = sanitize_key((string)($row['seccion'] ?? 'guia'));
        $cats = self::validate_categories((string)($row['categoria_ids'] ?? ''));
        if (is_wp_error($cats)) return $cats;
        if (!in_array($section, array('guia','comparativa','noticia'), true)) return new WP_Error('editor_section', 'Sección editorial no reconocida.');
        if ($kind === 'post' && $section !== 'noticia' && count($cats) < 1) return new WP_Error('editor_cat_required', 'El nuevo post debe indicar product_cat.');
        if ($kind === 'landing' && count($cats) < 2) return new WP_Error('editor_landing_cats', 'La nueva landing necesita al menos dos product_cat.');
        if (!in_array($kind, array('post','landing'),true)) return new WP_Error('editor_kind', 'Tipo de contenido no válido.');
        $wp_category = array();
        if ($kind === 'post') {
            $term = get_category_by_slug($section === 'noticia' ? 'noticias' : 'guias');
            if (!$term) return new WP_Error('editor_wp_category', 'Falta la categoría editorial WordPress requerida: ' . ($section === 'noticia' ? 'Noticias' : 'Guías') . '.');
            $wp_category = array(absint($term->term_id));
        }
        $id = wp_insert_post(wp_slash(array(
            'post_type'=>$kind === 'post' ? 'post' : 'page',
            'post_status'=>'draft',
            'post_title'=>sanitize_text_field((string)$row['titulo']),
            'post_excerpt'=>sanitize_textarea_field((string)$row['extracto']),
            'post_content'=>wp_kses_post((string)$row['contenido']),
            'post_category'=>$wp_category,
        )), true);
        if (is_wp_error($id) || !absint($id)) return is_wp_error($id) ? $id : new WP_Error('editor_create', 'No se pudo crear el borrador.');
        $id = absint($id);
        if ($kind === 'landing') {
            if (!function_exists('seo_page_editor_save_node_role') || !function_exists('seo_page_editor_replace_landing_categories')) {
                wp_delete_post($id, true);
                return new WP_Error('editor_landing_api', 'No están disponibles las relaciones de landings.');
            }
            $role = seo_page_editor_save_node_role($id, 'landing');
            $relations = is_wp_error($role) ? $role : seo_page_editor_replace_landing_categories($id, $cats);
        } else {
            if ($cats && !function_exists('seo_post_editor_replace_product_cat_relations')) {
                wp_delete_post($id, true);
                return new WP_Error('editor_post_api', 'No están disponibles las relaciones comerciales de posts.');
            }
            $relations = $cats ? seo_post_editor_replace_product_cat_relations($id, $cats) : true;
            if (!is_wp_error($relations) && $section === 'comparativa') {
                wp_set_post_terms($id, array('comparativas'), 'post_tag', true);
            }
        }
        if (is_wp_error($relations)) {
            wp_delete_post($id, true);
            return $relations;
        }
        update_post_meta($id, '_seo_editor_external_imported', 1);
        update_post_meta($id, '_seo_editor_external_kind', $kind);
        update_post_meta($id, '_seo_editor_external_section', $section);
        return $id;
    }
}
