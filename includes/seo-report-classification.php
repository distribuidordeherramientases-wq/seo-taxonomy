<?php
/*
Plugin Name: SEO Menu Manager
Plugin URI: https://www.distribuidordeherramientas.es/
Description: Generador de informes de correcta clasificacion de productos en su jerarquia
Version: 1.1.0
Requires PHP: 7.4
Requires at least: 5.8
Author: David Perez Martorell davidperezmartorell@gmail.com
Author URI: https://focazul.wordpress.com/
License: GPL2
Text Domain: seo-report-classification
*/

if (!defined('ABSPATH')) exit;

function seo_report_classification() {

    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';

    echo '<div class="wrap">';
    echo '<h1>Reclasificación inteligente del catálogo</h1>';

    echo '<div style="
        background:#eef6ff;
        border-left:4px solid #2271b1;
        padding:15px;
        margin:15px 0;
    ">';

    echo '<strong>Objetivo</strong><br><br>';

    echo 'Este informe analiza automáticamente todas las categorías utilizando el mismo motor de clasificación de productos que utiliza el sistema.';

    echo '<br><br>';

    echo 'Detecta:';
    echo '<ul>';
    echo '<li>Categorías contaminadas.</li>';
    echo '<li>Productos sospechosos.</li>';
    echo '<li>Categorías débiles.</li>';
    echo '<li>Categorías candidatas a división.</li>';
    echo '<li>Prioridades de reorganización.</li>';
    echo '</ul>';

    echo '</div>';

    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="seo-reports">';
    echo '<input type="hidden" name="tab" value="reclasificacion">';
    echo '<button class="button button-primary" name="run_report" value="1">';
    echo 'Generar informe';
    echo '</button>';
    echo '</form>';

    if (empty($_GET['run_report'])) {
        echo '</div>';
        return;
    }

    $report = [];

    $summary_total = 0;
    $summary_ok = 0;
    $summary_warning = 0;
    $summary_bad = 0;

    $clusters = $wpdb->get_col("
        SELECT DISTINCT source_id
        FROM $relations_table
        WHERE source_type='cluster'
    ");

    foreach ($clusters as $cluster_id) {

        $cluster_obj = get_post($cluster_id);
        if (!$cluster_obj) continue;

        $hp_ids = $wpdb->get_col($wpdb->prepare("
            SELECT target_id
            FROM $relations_table
            WHERE source_id=%d
            AND relation_type='cluster_to_primary'
        ", $cluster_id));

        foreach ($hp_ids as $hp_id) {

            $hp_obj = get_post($hp_id);
            if (!$hp_obj) continue;

            $hs_ids = $wpdb->get_col($wpdb->prepare("
                SELECT target_id
                FROM $relations_table
                WHERE source_id=%d
                AND relation_type='hub_primary_to_hub_secondary'
            ", $hp_id));

            foreach ($hs_ids as $hs_id) {

                $hs_obj = get_post($hs_id);
                if (!$hs_obj) continue;

                $cat_ids = $wpdb->get_col($wpdb->prepare("
                    SELECT target_id
                    FROM $relations_table
                    WHERE source_id=%d
                    AND relation_type='hub_secondary_to_category'
                ", $hs_id));

                foreach ($cat_ids as $cat_id) {

                    $term = get_term($cat_id,'product_cat');

                    if (!$term || is_wp_error($term)) {
                        continue;
                    }

                    $products = get_posts([
                        'post_type'      => 'product',
                        'posts_per_page' => -1,
                        'post_status'    => 'publish',
                        'tax_query'      => [[
                            'taxonomy' => 'product_cat',
                            'field'    => 'term_id',
                            'terms'    => $cat_id
                        ]]
                    ]);

                    if (!$products) {
                        continue;
                    }

                    $ok = 0;
                    $warning = 0;
                    $bad = 0;
                    $scores = [];

                    foreach ($products as $p) {

                        $score = seo_cls_score(
                            $p->ID,
                            $p,
                            $term,
                            $hs_obj,
                            $hp_obj,
                            $cluster_obj
                        );

                        $scores[] = $score;

                        if ($score >= 70) {

                            $ok++;

                        } elseif ($score >= 40) {

                            $warning++;

                        } else {

                            $bad++;

                        }
                    }

                    $total = count($products);

                    $avg_score = round(
                        array_sum($scores) / max(count($scores),1),
                        2
                    );

                    $contamination = round(
                        ($bad * 100) / max($total,1),
                        2
                    );

                    $summary_total += $total;
                    $summary_ok += $ok;
                    $summary_warning += $warning;
                    $summary_bad += $bad;

                    $report[] = [

                        'cluster' => $cluster_obj->post_title,
                        'hub_primary' => $hp_obj->post_title,
                        'hub_secondary' => $hs_obj->post_title,
                        'category' => $term->name,

                        'total' => $total,
                        'ok' => $ok,
                        'warning' => $warning,
                        'bad' => $bad,

                        'avg_score' => $avg_score,
                        'contamination' => $contamination

                    ];
                }
            }
        }
    }

    usort($report,function($a,$b){

        return $b['contamination'] <=> $a['contamination'];

    });

    echo '<h2>Resumen ejecutivo</h2>';

    echo '<ul>';
    echo '<li><strong>Productos analizados:</strong> '.$summary_total.'</li>';
    echo '<li><strong>✅ Correctos:</strong> '.$summary_ok.'</li>';
    echo '<li><strong>⚠ Revisables:</strong> '.$summary_warning.'</li>';
    echo '<li><strong>❌ Sospechosos:</strong> '.$summary_bad.'</li>';
    echo '</ul>';

    echo '<h2>Categorías más contaminadas</h2>';

    echo '<table class="widefat striped">';

    echo '<thead>';
    echo '<tr>';
    echo '<th>Cluster</th>';
    echo '<th>Hub primario</th>';
    echo '<th>Hub secundario</th>';
    echo '<th>Categoría</th>';
    echo '<th>Productos</th>';
    echo '<th>✅</th>';
    echo '<th>⚠️</th>';
    echo '<th>❌</th>';
    echo '<th>Contaminación</th>';
    echo '</tr>';
    echo '</thead>';

    echo '<tbody>';

    foreach ($report as $row) {

        echo '<tr>';

        echo '<td>'.esc_html($row['cluster']).'</td>';
        echo '<td>'.esc_html($row['hub_primary']).'</td>';
        echo '<td>'.esc_html($row['hub_secondary']).'</td>';
        echo '<td>'.esc_html($row['category']).'</td>';

        echo '<td>'.$row['total'].'</td>';
        echo '<td>'.$row['ok'].'</td>';
        echo '<td>'.$row['warning'].'</td>';
        echo '<td>'.$row['bad'].'</td>';

        echo '<td>'.$row['contamination'].'%</td>';

        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '</div>';
}