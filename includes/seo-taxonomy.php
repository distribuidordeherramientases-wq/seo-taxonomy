<?php
/**
 * SEO Taxonomy - Vista jerárquica
 *
 * Archivo: seo-taxonomy.php
 * Descripción: Contiene la visualización jerárquica de la taxonomía SEO
 * (clusters, hubs primarios, hubs secundarios y categorías de producto).
 *
 * Este archivo se carga desde seo-core.php y no pertenece al sistema de informes.
 */

defined('ABSPATH') || exit;

/**
 * Renderiza la taxonomía jerárquica completa del sistema SEO.
 *
 * Esta vista pertenece al módulo de Taxonomía y se muestra desde seo-core.php.
 */
function seo_render_taxonomy_hierarchy($show_excerpt = false) {
    global $wpdb;
    
    $nodes = $wpdb->get_results("SELECT object_id, seo_role FROM wp_seo_nodes WHERE status=1");
    
    $map = [];
    foreach ($nodes as $n) {
        $map[$n->object_id] = $n->seo_role;
    }
    
    $clusters = [];
    foreach ($map as $id => $role) {
        if ($role === 'cluster') $clusters[] = $id;
    }
    
    echo '<h2>📊 Taxonomía del sistema</h2>';
    
    if (empty($clusters)) {
        echo '<p><strong>No hay clusters registrados.</strong></p>';
    }
    
    foreach ($clusters as $cluster_id) {
        $cluster_title = get_the_title($cluster_id);
        $cluster_url = get_permalink($cluster_id);
        
        echo "<div style='border:2px solid #2e7d32;padding:15px;margin-bottom:20px;background:#fff; border-radius: 4px;'>";
        echo "<h3 style='margin-top:0;'>CLUSTER: ".esc_html($cluster_title)." <span style='font-weight:normal; font-size:13px; color:#666;'>({$cluster_id})</span>";
        if ($cluster_url && !is_wp_error($cluster_url)) {
            echo " <a href='".esc_url($cluster_url)."' target='_blank' style='margin-left:10px; font-size:12px; color:#2e7d32; text-decoration:underline;'>[Ver Link]</a>";
        }
        echo "</h3>";
    
        $hubs_primary_rel = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT r.target_id FROM wp_seo_relations r
            WHERE r.source_type='cluster' AND r.source_id=%d AND r.relation_type='cluster_to_primary'
        ", $cluster_id));
    
        foreach ($hubs_primary_rel as $hp) {
            $hp_title = get_the_title($hp->target_id);
            $hp_url = get_permalink($hp->target_id);
            
            echo "<div style='margin-left:20px;padding:10px;border-left:3px solid #2196f3;margin-bottom:10px; background:#fcfdfe;'>";
            echo "<strong>HUB PRIMARY:</strong> ".esc_html($hp_title)." <span style='font-weight:normal; font-size:12px; color:#666;'>({$hp->target_id})</span>";
            if ($hp_url && !is_wp_error($hp_url)) {
                echo " <a href='".esc_url($hp_url)."' target='_blank' style='margin-left:8px; font-size:11px; color:#2196f3; text-decoration:underline;'>[Ver Link]</a>";
            }
    
            $hubs_secondary_rel = $wpdb->get_results($wpdb->prepare("
                SELECT DISTINCT r.target_id FROM wp_seo_relations r
                WHERE r.source_type='hub_primary' AND r.source_id=%d AND r.relation_type='hub_primary_to_hub_secondary'
            ", $hp->target_id));
    
            foreach ($hubs_secondary_rel as $hs) {
                $hs_title = get_the_title($hs->target_id);
                $hs_url = get_permalink($hs->target_id);
                
                echo "<div style='margin-left:20px;padding:6px; margin-top:5px; background:#f8fafc; border-radius:3px;'>";
                echo "- <strong>HUB SECONDARY:</strong> ".esc_html($hs_title)." <span style='font-weight:normal; font-size:11px; color:#666;'>({$hs->target_id})</span>";
                if ($hs_url && !is_wp_error($hs_url)) {
                    echo " <a href='".esc_url($hs_url)."' target='_blank' style='margin-left:8px; font-size:11px; color:#4a5568; text-decoration:underline;'>[Ver Link]</a>";
                }
    
                $categories = $wpdb->get_results($wpdb->prepare("
                    SELECT DISTINCT c.term_id, c.name
                    FROM {$wpdb->terms} c
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = c.term_id
                    INNER JOIN wp_seo_relations r ON r.target_id = c.term_id
                    WHERE r.source_type='hub_secondary' AND r.source_id=%d AND r.target_type='product_cat'
                    ORDER BY c.name ASC
                ", $hs->target_id));
                    
                if ($categories) {
                    echo "<div style='margin-left:40px; margin-top:8px; margin-bottom:5px;'>";
                    foreach ($categories as $cat) {
                        $cat_url = get_term_link($cat->term_id, 'product_cat');
                        
                        $product_count = (int) $wpdb->get_var($wpdb->prepare("
                            SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                            INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                            WHERE tt.term_id = %d AND p.post_type = 'product' AND p.post_status = 'publish'
                        ", $cat->term_id));
                    
                        echo "<div style='margin-bottom: 8px; line-height: 1.6;'>";
                        echo "📦 <strong>".esc_html($cat->name)."</strong> <span style='font-size:11px; color:#666;'>({$cat->term_id})</span> ";
                        echo "<strong style='color:#2e7d32;'>[{$product_count} productos]</strong>";
                        if ($cat_url && !is_wp_error($cat_url)) {
                            echo " <a href='".esc_url($cat_url)."' target='_blank' style='margin-left:8px; font-size:11px; color:#2e7d32; text-decoration:none; font-weight:500; background:#e6f4ea; padding:1px 6px; border-radius:3px;'>🌐 link clickable</a>";
                        }
                        echo "<br>";
                        
                        if ($show_excerpt) {
                            $excerpt = $wpdb->get_var($wpdb->prepare("
                                SELECT keywords
                                FROM {$wpdb->prefix}seo_nodes
                                WHERE object_id = %d
                                  AND seo_role = 'excerpt'
                                LIMIT 1
                            ", $cat->term_id));
                            
                            $excerpt = !empty($excerpt)
                                ? trim($excerpt)
                                : 'Sin descripción/excerpt configurado.';
                            echo "<div class='cat-excerpt-box' style='margin-left:25px; margin-top:4px; padding:6px 10px; background:#fff; border:1px solid #e2e8f0; border-left:2px solid #ff9800; font-size:12px; color:#555; max-width:90%; line-height:1.4; border-radius:0 3px 3px 0;'>";
                            echo wp_kses_post($excerpt);
                            echo "</div>";
                        }
                        echo "</div>";
                    }
                    echo "</div>";
                } else {
                    echo "<div style='margin-left:40px;color:#aaafb6; font-style:italic; font-size:12px;'>Sin categorías vinculadas</div>";
                }
                echo "</div>";
            }
            echo "</div>";
        }
        echo "</div>";
    }
}
