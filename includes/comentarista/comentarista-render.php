<?php
/**
 * Comentarista - render publico por tipo de evidencia.
 */

defined('ABSPATH') || exit;

function seo_comentarista_get_public_items($product_id, $limit = 12)
{
    return seo_comentarista_get_by_product($product_id, 'published', $limit);
}

function seo_comentarista_rating_text($row)
{
    if ($row['rating_value'] === null || $row['rating_scale'] === null) {
        return '';
    }

    $value = rtrim(rtrim(number_format((float) $row['rating_value'], 2, '.', ''), '0'), '.');
    $scale = rtrim(rtrim(number_format((float) $row['rating_scale'], 2, '.', ''), '0'), '.');
    return $value . '/' . $scale;
}

function seo_comentarista_render_embed($row)
{
    $platform = sanitize_key($row['source_platform'] ?? 'web');
    $embed_url = !empty($row['embed_url']) ? esc_url($row['embed_url']) : '';

    if ($embed_url === '' || !in_array($platform, array('youtube', 'tiktok', 'instagram'), true)) {
        return '';
    }

    $title = !empty($row['source_title'])
        ? (string) $row['source_title']
        : 'Contenido externo sobre el producto';

    return sprintf(
        '<div class="seo-comentarista__embed seo-comentarista__embed--%1$s"><iframe src="%2$s" title="%3$s" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>',
        esc_attr($platform),
        $embed_url,
        esc_attr($title)
    );
}

function seo_comentarista_render_source_meta($row)
{
    $parts = array();

    if (!empty($row['author_name'])) {
        $parts[] = '<strong>' . esc_html($row['author_name']) . '</strong>';
    }
    if (!empty($row['source_name'])) {
        $parts[] = esc_html($row['source_name']);
    }
    if (!empty($row['source_published_at'])) {
        $timestamp = strtotime((string) $row['source_published_at']);
        if ($timestamp) {
            $parts[] = esc_html(wp_date(get_option('date_format'), $timestamp));
        }
    }

    $meta = implode(' · ', $parts);
    if (!empty($row['source_url'])) {
        $link = '<a href="' . esc_url($row['source_url']) . '" target="_blank" rel="nofollow noopener noreferrer external">Ver fuente original</a>';
        $meta = $meta !== '' ? $meta . ' · ' . $link : $link;
    }

    return $meta;
}

/**
 * Renderiza una evidencia individual segun su tipo.
 */
function seo_comentarista_render_item($row)
{
    $type = sanitize_key($row['content_type'] ?? 'link');
    $platform = sanitize_key($row['source_platform'] ?? 'web');
    $meta = seo_comentarista_render_source_meta($row);
    $rating = seo_comentarista_rating_text($row);

    ob_start();
    ?>
    <article class="seo-comentarista__item seo-comentarista__item--<?php echo esc_attr($type); ?> seo-comentarista__platform--<?php echo esc_attr($platform); ?>">
        <?php if ($type === 'comment') : ?>
            <?php if ($rating !== '') : ?>
                <p class="seo-comentarista__rating">Valoración en la fuente: <strong><?php echo esc_html($rating); ?></strong></p>
            <?php endif; ?>

            <?php if (!empty($row['source_content'])) : ?>
                <blockquote class="seo-comentarista__comment"><?php echo wp_kses_post(wpautop($row['source_content'])); ?></blockquote>
            <?php elseif (!empty($row['editorial_summary'])) : ?>
                <p class="seo-comentarista__summary"><?php echo wp_kses_post($row['editorial_summary']); ?></p>
            <?php endif; ?>

            <?php if (!empty($row['editorial_summary']) && !empty($row['source_content'])) : ?>
                <p class="seo-comentarista__editorial"><strong>Resumen:</strong> <?php echo wp_kses_post($row['editorial_summary']); ?></p>
            <?php endif; ?>

        <?php elseif ($type === 'video' || $type === 'social_post') : ?>
            <?php echo seo_comentarista_render_embed($row); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <?php if (!empty($row['source_title'])) : ?>
                <h3><?php echo esc_html($row['source_title']); ?></h3>
            <?php endif; ?>

            <?php if (!empty($row['editorial_summary'])) : ?>
                <p class="seo-comentarista__summary"><?php echo wp_kses_post($row['editorial_summary']); ?></p>
            <?php elseif (!empty($row['source_content'])) : ?>
                <p class="seo-comentarista__summary"><?php echo wp_kses_post($row['source_content']); ?></p>
            <?php endif; ?>

            <?php if (empty($row['embed_url']) && !empty($row['source_url'])) : ?>
                <p><a class="seo-comentarista__external-link" href="<?php echo esc_url($row['source_url']); ?>" target="_blank" rel="nofollow noopener noreferrer external">Abrir contenido externo</a></p>
            <?php endif; ?>

        <?php else : ?>
            <?php if (!empty($row['source_title'])) : ?>
                <h3><?php echo esc_html($row['source_title']); ?></h3>
            <?php endif; ?>

            <?php if (!empty($row['editorial_summary'])) : ?>
                <p class="seo-comentarista__summary"><?php echo wp_kses_post($row['editorial_summary']); ?></p>
            <?php elseif (!empty($row['source_content'])) : ?>
                <p class="seo-comentarista__summary"><?php echo wp_kses_post($row['source_content']); ?></p>
            <?php endif; ?>

            <?php if (!empty($row['source_url'])) : ?>
                <p><a class="seo-comentarista__external-link" href="<?php echo esc_url($row['source_url']); ?>" target="_blank" rel="nofollow noopener noreferrer external">Ver contenido original</a></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($meta !== '') : ?>
            <p class="seo-comentarista__source">Fuente externa: <?php echo wp_kses($meta, array('strong' => array(), 'a' => array('href' => array(), 'target' => array(), 'rel' => array()))); ?></p>
        <?php endif; ?>
    </article>
    <?php
    return trim((string) ob_get_clean());
}

/**
 * Devuelve el bloque listo para insertar en template-product.php.
 * No genera Review/AggregateRating schema.
 */
function seo_comentarista_render_product_block($product_id = 0, $args = array())
{
    $product_id = $product_id ? absint($product_id) : get_the_ID();
    if (!seo_comentarista_validate_product($product_id)) {
        return '';
    }

    $args = wp_parse_args($args, array(
        'limit' => 12,
        'title' => 'Opiniones y experiencias externas',
    ));

    $rows = seo_comentarista_get_public_items($product_id, (int) $args['limit']);
    if (!$rows) {
        return '';
    }

    ob_start();
    ?>
    <section class="seo-comentarista" aria-labelledby="seo-comentarista-<?php echo esc_attr($product_id); ?>">
        <header class="seo-comentarista__header">
            <h2 id="seo-comentarista-<?php echo esc_attr($product_id); ?>"><?php echo esc_html($args['title']); ?></h2>
            <p>Contenido y experiencias publicados por terceros. No son opiniones de clientes de esta tienda.</p>
        </header>
        <div class="seo-comentarista__items">
            <?php foreach ($rows as $row) : ?>
                <?php echo seo_comentarista_render_item($row); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
    return trim((string) ob_get_clean());
}
