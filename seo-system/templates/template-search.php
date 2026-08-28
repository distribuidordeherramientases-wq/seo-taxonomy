<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

dht_template_render_header();
?>
<main class="dht-page dht-search-page">
    <section class="dht-section">
        <div class="dht-container">
            <div class="dht-section-header dht-section-header--left">
                <span class="dht-kicker">Búsqueda</span>
                <h1>Resultados para “<?php echo esc_html(get_search_query()); ?>”</h1>
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
