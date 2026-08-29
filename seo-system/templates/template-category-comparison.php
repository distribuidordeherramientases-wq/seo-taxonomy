<?php
/**
 * Bloque de comparativa estática de una categoría de producto.
 *
 * Lee wp_seo_category_comparisons por category_id y solo muestra
 * registros con status = published. El contenido editorial se guarda
 * en la tabla para poder regenerarlo sin modificar la plantilla.
 */

defined('ABSPATH') || exit;

global $wpdb;

$comparison_term = isset($term) && is_object($term) ? $term : get_queried_object();

if (
    !$comparison_term ||
    empty($comparison_term->term_id) ||
    empty($comparison_term->taxonomy) ||
    'product_cat' !== $comparison_term->taxonomy
) {
    return;
}

$comparison_table = $wpdb->prefix . 'seo_category_comparisons';

$comparison = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT id, category_id, title, content, product_count, analysis_snapshot, source_hash, updated_at
         FROM {$comparison_table}
         WHERE category_id = %d
           AND status = 'published'
         LIMIT 1",
        (int) $comparison_term->term_id
    )
);

if (!$comparison || '' === trim((string) $comparison->content)) {
    return;
}
?>

<section class="dht-section dht-category-comparison-section" aria-labelledby="dht-category-comparison-title-<?php echo esc_attr((string) $comparison_term->term_id); ?>">
    <div class="dht-container">
        <div class="dht-category-comparison-panel">
            <header class="dht-section-header dht-category-comparison-header">
                <span class="dht-kicker">Ayuda para elegir</span>
                <h2
                    id="dht-category-comparison-title-<?php echo esc_attr((string) $comparison_term->term_id); ?>"
                    class="dht-section-title"
                >
                    <?php echo esc_html((string) $comparison->title); ?>
                </h2>
            </header>

            <div class="dht-category-comparison-content">
                <?php echo wp_kses_post((string) $comparison->content); ?>
            </div>
        </div>
    </div>
</section>

<style id="dht-category-comparison-styles">
.dht-category-comparison-panel {
    padding: clamp(18px, 3vw, 32px);
    border: 1px solid var(--dht-border-color, #e5e7eb);
    border-radius: 16px;
    background: var(--dht-surface, #fff);
}
.dht-category-comparison-content > p:first-child {
    margin-top: 0;
}
.dht-category-comparison-table-wrap {
    width: 100%;
    margin: 20px 0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.dht-category-comparison-table {
    width: 100%;
    min-width: 720px;
    border-collapse: collapse;
}
.dht-category-comparison-table th,
.dht-category-comparison-table td {
    padding: 13px 14px;
    border-bottom: 1px solid var(--dht-border-color, #e5e7eb);
    text-align: left;
    vertical-align: top;
}
.dht-category-comparison-table thead th {
    font-weight: 700;
    background: var(--dht-surface-soft, #f8fafc);
}
.dht-category-comparison-table tbody th {
    width: 20%;
    font-weight: 700;
}
.dht-category-comparison-priority {
    margin-bottom: 0;
}
@media (max-width: 767px) {
    .dht-category-comparison-panel {
        padding: 16px;
        border-radius: 12px;
    }
    .dht-category-comparison-table th,
    .dht-category-comparison-table td {
        padding: 11px 12px;
    }
}
</style>
