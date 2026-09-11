<?php
/**
 * Comentarista - comprobacion periodica de fuentes.
 */

defined('ABSPATH') || exit;

if (!defined('SEO_COMENTARISTA_CRON_HOOK')) {
    define('SEO_COMENTARISTA_CRON_HOOK', 'seo_comentarista_source_healthcheck');
}

/**
 * Programa una comprobacion diaria si no existe.
 */
function seo_comentarista_schedule_healthcheck()
{
    if (!wp_next_scheduled(SEO_COMENTARISTA_CRON_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', SEO_COMENTARISTA_CRON_HOOK);
    }
}
add_action('init', 'seo_comentarista_schedule_healthcheck', 30);

/**
 * Revisa un lote pequeno de fuentes antiguas. Nunca elimina filas.
 *
 * @param int $limit
 * @return int Numero de fuentes comprobadas.
 */
function seo_comentarista_run_source_healthcheck($limit = 20)
{
    global $wpdb;

    if (!seo_comentarista_table_exists()) {
        return 0;
    }

    $limit = max(1, min(50, absint($limit)));
    $table = seo_comentarista_table_name();

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, source_page_url, source_content_url
             FROM {$table}
             WHERE status <> 'rejected'
             ORDER BY CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END ASC,
                      last_checked_at ASC,
                      id ASC
             LIMIT %d",
            $limit
        ),
        ARRAY_A
    );

    $checked = 0;
    foreach ((array) $rows as $row) {
        $url = !empty($row['source_content_url']) ? $row['source_content_url'] : $row['source_page_url'];
        $result = seo_comentarista_check_source_url($url);

        seo_comentarista_update_evidence(
            (int) $row['id'],
            array(
                'source_status'  => $result['source_status'],
                'http_status'    => $result['http_status'],
                'last_checked_at'=> current_time('mysql'),
            )
        );
        $checked++;
    }

    return $checked;
}
add_action(SEO_COMENTARISTA_CRON_HOOK, 'seo_comentarista_run_source_healthcheck');
