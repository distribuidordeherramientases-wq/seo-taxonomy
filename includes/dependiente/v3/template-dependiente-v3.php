<?php
defined('ABSPATH') || exit;

$helpers = defined('SEO_SYSTEM_PATH')
    ? SEO_SYSTEM_PATH . 'seo-system/templates/template-helpers.php'
    : dirname(__DIR__, 3) . '/seo-system/templates/template-helpers.php';

if (is_readable($helpers)) {
    require_once $helpers;
}

$GLOBALS['dht_compact_dependiente_header'] = true;

if (function_exists('dht_template_render_header')) {
    dht_template_render_header();
} else {
    get_header();
}
?>
<main id="main" class="dependiente-v3-app__main">
    <?php while (have_posts()) : the_post(); the_content(); endwhile; ?>
</main>
<?php
if (function_exists('dht_template_render_footer')) {
    dht_template_render_footer();
} else {
    get_footer();
}
unset($GLOBALS['dht_compact_dependiente_header']);
