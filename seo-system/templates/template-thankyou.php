<?php
defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

dht_template_render_header();
?>
<main class="dht-page dht-status-page">
    <section class="dht-section">
        <div class="dht-content dht-status-card">
            <span class="dht-kicker">Pedido recibido</span>
            <h1>Gracias por tu compra</h1>
            <p>El pedido se ha recibido correctamente. Recibirás la información de seguimiento cuando esté disponible.</p>
            <a class="dht-btn dht-btn-primary" href="<?php echo esc_url(dht_template_shop_url()); ?>">Volver a la tienda</a>
        </div>
    </section>
</main>
<?php dht_template_render_footer(); ?>
