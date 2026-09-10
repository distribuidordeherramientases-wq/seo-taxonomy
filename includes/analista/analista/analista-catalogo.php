<?php
/**
 * Snapshot compacto de la arquitectura y catalogo propio para Analista.
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_analista_catalog_structure_snapshot')) {
    function seo_analista_catalog_structure_snapshot(array $gsc_pages = array()) {
        global $wpdb;
        $nodes_table = $wpdb->prefix . 'seo_nodes';
        $relations_table = $wpdb->prefix . 'seo_relations';
        $out = array(
            'available' => false,
            'clusters' => 0,
            'hub_primary' => 0,
            'hub_secondary' => 0,
            'categories' => 0,
            'products' => 0,
            'relations' => array(),
            'seo_role_visibility' => array(),
            'top_seo_pages' => array(),
        );

        if (!seo_analista_table_exists($nodes_table) || !seo_analista_table_exists($relations_table)) return $out;
        $out['available'] = true;

        $nodes = (array) $wpdb->get_results(
            "SELECT DISTINCT n.object_id, n.seo_role, p.post_title
             FROM {$nodes_table} n
             INNER JOIN {$wpdb->posts} p ON p.ID=n.object_id
             WHERE n.status=1 AND p.post_status='publish'
               AND n.seo_role IN ('cluster','hub_primary','hub_secondary')",
            ARRAY_A
        );

        foreach ($nodes as $node) {
            $role = (string) ($node['seo_role'] ?? '');
            if (isset($out[$role])) $out[$role]++;
        }

        $out['relations'] = array(
            'cluster_to_primary' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$relations_table} WHERE relation_type='cluster_to_primary'"),
            'primary_to_secondary' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$relations_table} WHERE relation_type='hub_primary_to_hub_secondary'"),
            'secondary_to_category' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$relations_table} WHERE source_type='hub_secondary' AND target_type='product_cat'"),
        );

        if (post_type_exists('product')) {
            $counts = wp_count_posts('product');
            $out['products'] = isset($counts->publish) ? (int) $counts->publish : 0;
        }
        if (taxonomy_exists('product_cat')) {
            $term_count = wp_count_terms(array('taxonomy' => 'product_cat', 'hide_empty' => false));
            $out['categories'] = is_wp_error($term_count) ? 0 : (int) $term_count;
        }

        $gsc_by_path = array();
        foreach ($gsc_pages as $page) {
            $url = (string) ($page['page_url'] ?? '');
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ($path === '') $path = '/';
            $gsc_by_path[untrailingslashit($path) ?: '/'] = $page;
        }

        $visibility = array(
            'cluster' => array('pages' => 0, 'impressions' => 0.0, 'clicks' => 0.0),
            'hub_primary' => array('pages' => 0, 'impressions' => 0.0, 'clicks' => 0.0),
            'hub_secondary' => array('pages' => 0, 'impressions' => 0.0, 'clicks' => 0.0),
        );
        $top = array();
        foreach ($nodes as $node) {
            $id = (int) ($node['object_id'] ?? 0);
            $role = (string) ($node['seo_role'] ?? '');
            if (!$id || !isset($visibility[$role])) continue;
            $url = get_permalink($id);
            if (!$url) continue;
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            $path = untrailingslashit($path) ?: '/';
            $gsc = (array) ($gsc_by_path[$path] ?? array());
            if (!$gsc) continue;
            $visibility[$role]['pages']++;
            $visibility[$role]['impressions'] += (float) ($gsc['impressions'] ?? 0);
            $visibility[$role]['clicks'] += (float) ($gsc['clicks'] ?? 0);
            $top[] = array(
                'id' => $id,
                'role' => $role,
                'title' => (string) ($node['post_title'] ?? ''),
                'url' => $url,
                'impressions' => (float) ($gsc['impressions'] ?? 0),
                'clicks' => (float) ($gsc['clicks'] ?? 0),
                'position' => (float) ($gsc['position'] ?? 0),
            );
        }
        usort($top, static function($a, $b) {
            return (float) $b['impressions'] <=> (float) $a['impressions'];
        });
        $out['seo_role_visibility'] = $visibility;
        $out['top_seo_pages'] = array_slice($top, 0, 20);
        return $out;
    }
}
