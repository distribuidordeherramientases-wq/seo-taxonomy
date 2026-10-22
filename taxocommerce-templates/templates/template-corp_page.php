<?php
/**
 * Plantilla de página corporativa.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

dht_template_render_header();
?>
<main id="primary" class="site-main page-corporate">
<?php while (have_posts()) : the_post(); ?>
    <header class="page-hero">
        <div class="page-container page-hero-grid<?php echo has_post_thumbnail() ? '' : ' page-hero-grid--text-only'; ?>">
            <div>
                <span class="dht-kicker">Empresa</span>
                <h1><?php the_title(); ?></h1>
                <?php if (has_excerpt()) : ?>
                    <p class="dht-lead"><?php echo esc_html(get_the_excerpt()); ?></p>
                <?php endif; ?>
            </div>

            <?php if (has_post_thumbnail()) : ?>
                <figure class="page-hero-media">
                    <?php the_post_thumbnail('large', array('loading' => 'eager', 'fetchpriority' => 'high')); ?>
                </figure>
            <?php endif; ?>
        </div>
    </header>

    <section class="page-content">
        <div class="page-container page-content-box dht-prose">
            <?php the_content(); ?>
        </div>
    </section>
<?php endwhile; ?>
</main>
<?php dht_template_render_footer(); ?>
