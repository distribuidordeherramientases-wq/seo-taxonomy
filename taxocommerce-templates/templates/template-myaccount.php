<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

dht_template_render_header();
?>
<main class="dht-page dht-commerce-page">
    <section class="dht-section">
        <div class="dht-container">
            <h1>Mi cuenta</h1>
            <?php echo do_shortcode('[woocommerce_my_account]'); ?>
        </div>
    </section>
</main>
<?php dht_template_render_footer(); ?>
