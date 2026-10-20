<?php
/**
 * Minimal independent WordPress template.
 * No dependencies on the legacy SEO Taxonomy PHP functions.
 */
defined('ABSPATH') || exit;
get_header();
echo '<main id="primary" class="site-main tct-template" role="main"><div class="tct-content" style="max-width:1100px;margin:2rem auto;padding:1rem">';
while(have_posts()) {
    the_post();
    echo '<article id="post-'.esc_attr(get_the_ID()).'">';
    echo '<h1>'.esc_html(get_the_title()).'</h1>';
    the_content();
    echo '</article>';
}
echo '</div></main>';
get_footer();
