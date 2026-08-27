<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

$search_term = get_search_query();
$requested_post_type = get_query_var('post_type');
if (!$requested_post_type && isset($_GET['post_type']) && !is_array($_GET['post_type'])) {
    $requested_post_type = sanitize_key(wp_unslash($_GET['post_type']));
}

$is_product_search = ($requested_post_type === 'product');

/*
 * Las búsquedas de producto del header antiguo usan ?s=...&post_type=product.
 * Si SEO Search está cargado, reutilizamos aquí exactamente su motor, tarjetas,
 * ordenación y filtros en lugar de mostrar la lista simple de WordPress.
 */
if (
    $is_product_search
    && $search_term !== ''
    && function_exists('seo_search_query_products')
    && function_exists('seo_search_render_results_page')
) {
    $page = max(1, absint(isset($_GET['product-page']) ? $_GET['product-page'] : 1));
    $filters = function_exists('seo_search_get_active_filters')
        ? seo_search_get_active_filters()
        : array();

    $data = seo_search_query_products($search_term, $page, null, $filters);

    dht_template_render_header();
    echo seo_search_render_results_page($search_term, $data, $filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    dht_template_render_footer();
    return;
}

dht_template_render_header();
?>
<main class="dht-page dht-search-page">
    <section class="dht-section">
        <div class="dht-container">
            <div class="dht-section-header dht-section-header--left">
                <span class="dht-kicker">Búsqueda</span>
                <h1>Resultados para “<?php echo esc_html($search_term); ?>”</h1>
            </div>

            <?php get_search_form(); ?>

            <?php if (have_posts()) : ?>
                <div class="dht-search-results">
                    <?php while (have_posts()) : the_post(); ?>
                        <article class="dht-search-result">
                            <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                            <p><?php echo esc_html(wp_trim_words(wp_strip_all_tags(get_the_excerpt()), 30)); ?></p>
                        </article>
                    <?php endwhile; ?>
                </div>
                <?php the_posts_pagination(); ?>
            <?php else : ?>
                <div class="dht-empty-state">
                    <h2>Sin resultados</h2>
                    <p>Prueba con términos más generales o consulta el catálogo completo.</p>
                    <a class="dht-btn dht-btn-primary" href="<?php echo esc_url(dht_template_shop_url()); ?>">Ver tienda</a>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php dht_template_render_footer(); ?>
