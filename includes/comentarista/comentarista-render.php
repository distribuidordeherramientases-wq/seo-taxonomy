<?php
/**
 * Comentarista - salida publica para la plantilla de producto.
 */

defined('ABSPATH') || exit;

/**
 * Recupera evidencias publicables. Solo se muestran fuentes verificadas como activas.
 *
 * @param int $product_id
 * @param int $limit
 * @return array
 */
function seo_comentarista_get_public_evidence($product_id, $limit = 8)
{
    global $wpdb;

    $product_id = absint($product_id);
    $limit = max(1, min(30, absint($limit)));

    if (!$product_id || !seo_comentarista_table_exists()) {
        return array();
    }

    return (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT *
             FROM " . seo_comentarista_table_name() . "
             WHERE product_id = %d
               AND status = 'published'
               AND source_status = 'active'
             ORDER BY is_featured DESC, display_order ASC, published_at DESC, id DESC
             LIMIT %d",
            $product_id,
            $limit
        ),
        ARRAY_A
    );
}

/**
 * Renderiza un iframe controlado para YouTube/TikTok.
 *
 * @param array $row
 * @return string
 */
function seo_comentarista_render_media($row)
{
    $platform = isset($row['source_platform']) ? (string) $row['source_platform'] : '';
    $embed_url = !empty($row['embed_url']) ? esc_url($row['embed_url']) : '';

    if ($embed_url === '' || !in_array($platform, array('youtube', 'tiktok'), true)) {
        return '';
    }

    $title = !empty($row['source_title'])
        ? $row['source_title']
        : 'Contenido externo sobre el producto';

    return sprintf(
        '<div class="seo-comentarista-media seo-comentarista-media--%1$s"><iframe src="%2$s" title="%3$s" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>',
        esc_attr($platform),
        $embed_url,
        esc_attr($title)
    );
}

/**
 * Genera el bloque completo para una ficha de producto.
 * No anade schema Review/AggregateRating.
 *
 * @param int   $product_id
 * @param array $args
 * @return string
 */
function seo_comentarista_render_product_block($product_id = 0, $args = array())
{
    $product_id = $product_id ? absint($product_id) : get_the_ID();
    if (!$product_id || get_post_type($product_id) !== 'product') {
        return '';
    }

    $args = wp_parse_args(
        $args,
        array(
            'limit' => 8,
            'title' => 'Opiniones y experiencias externas',
        )
    );

    $rows = seo_comentarista_get_public_evidence($product_id, (int) $args['limit']);
    if (!$rows) {
        return '';
    }

    $rows = array_values(array_filter($rows, static function ($row) {
        $copy = !empty($row['editorial_summary']) ? $row['editorial_summary'] : $row['source_excerpt'];
        return $copy !== '' || seo_comentarista_render_media($row) !== '';
    }));

    if (!$rows) {
        return '';
    }

    ob_start();
    ?>
    <section class="seo-comentarista" aria-labelledby="seo-comentarista-title-<?php echo esc_attr($product_id); ?>">
        <div class="seo-comentarista__header">
            <h2 id="seo-comentarista-title-<?php echo esc_attr($product_id); ?>"><?php echo esc_html($args['title']); ?></h2>
            <p>Experiencias publicadas por terceros sobre este producto. No son reseñas de clientes de esta tienda.</p>
        </div>

        <div class="seo-comentarista__items">
            <?php foreach ($rows as $row) :
                $source_url = !empty($row['source_content_url']) ? $row['source_content_url'] : $row['source_page_url'];
                $copy = !empty($row['editorial_summary']) ? $row['editorial_summary'] : $row['source_excerpt'];
                $media = seo_comentarista_render_media($row);

                if ($copy === '' && $media === '') {
                    continue;
                }
                ?>
                <article class="seo-comentarista__item">
                    <?php echo $media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iframe construido internamente. ?>

                    <?php if (!empty($row['source_title'])) : ?>
                        <h3><?php echo esc_html($row['source_title']); ?></h3>
                    <?php endif; ?>

                    <?php if ($copy !== '') : ?>
                        <p class="seo-comentarista__summary"><?php echo esc_html($copy); ?></p>
                    <?php endif; ?>

                    <?php if ($row['rating_value'] !== null && $row['rating_scale'] !== null) : ?>
                        <p class="seo-comentarista__rating">
                            Valoracion en la fuente:
                            <strong><?php echo esc_html(rtrim(rtrim(number_format((float) $row['rating_value'], 2, '.', ''), '0'), '.')); ?>/<?php echo esc_html(rtrim(rtrim(number_format((float) $row['rating_scale'], 2, '.', ''), '0'), '.')); ?></strong>
                        </p>
                    <?php endif; ?>

                    <p class="seo-comentarista__source">
                        Fuente externa:
                        <?php if (!empty($row['author_name'])) : ?>
                            <strong><?php echo esc_html($row['author_name']); ?></strong>
                            <?php echo !empty($row['source_name']) ? ' · ' : ''; ?>
                        <?php endif; ?>
                        <?php if (!empty($row['source_name'])) : ?>
                            <?php echo esc_html($row['source_name']); ?>
                        <?php endif; ?>
                        <?php if ($source_url) : ?>
                            · <a href="<?php echo esc_url($source_url); ?>" target="_blank" rel="nofollow noopener noreferrer external">Ver fuente original</a>
                        <?php endif; ?>
                    </p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php

    return trim((string) ob_get_clean());
}
