<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

dht_template_render_header();
?>
<main class="dht-page dht-status-page dht-404-page">
    <section class="dht-section">
        <div class="dht-content dht-status-card">
            <span class="dht-kicker">Error 404</span>
            <h1>Página no encontrada</h1>
            <p>La dirección puede haber cambiado o el contenido ya no está disponible. Prueba una búsqueda o vuelve al catálogo.</p>
            <?php get_search_form(); ?>
            <div class="dht-status-actions">
                <a class="dht-btn dht-btn-primary" href="<?php echo esc_url(home_url('/')); ?>">Ir al inicio</a>
                <a class="dht-btn dht-btn-blue" href="<?php echo esc_url(dht_template_shop_url()); ?>">Ver la tienda</a>
            </div>
        </div>
    </section>
</main>
<?php dht_template_render_footer(); ?>
