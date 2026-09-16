<?php

defined('ABSPATH') || exit;

$site_name = get_bloginfo('name') ?: 'DistribuidorDeHerramientas.es';
$home_url = home_url('/');
$shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/tienda/');
$cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/carrito/');
$logo_id = absint(get_theme_mod('custom_logo'));
$logo_html = $logo_id ? wp_get_attachment_image($logo_id, 'medium', false, array('class' => 'dependiente-v3-app__logo', 'loading' => 'eager', 'alt' => $site_name)) : '';
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('dependiente-v3-app-body'); ?>>
<?php wp_body_open(); ?>
<div class="dependiente-v3-app">
    <header class="dependiente-v3-app__header">
        <a href="<?php echo esc_url($home_url); ?>" class="dependiente-v3-app__brand">
            <?php if ($logo_html) : echo $logo_html; else : ?><strong><?php echo esc_html($site_name); ?></strong><?php endif; ?>
            <span>Dependiente</span>
        </a>
        <nav>
            <a href="<?php echo esc_url($home_url); ?>">Inicio</a>
            <a href="<?php echo esc_url($cart_url); ?>">Carrito</a>
            <a class="is-primary" href="<?php echo esc_url($shop_url); ?>">Ver tienda</a>
        </nav>
    </header>
    <main class="dependiente-v3-app__main">
        <?php while (have_posts()) : the_post(); the_content(); endwhile; ?>
    </main>
    <footer class="dependiente-v3-app__footer">
        <strong>Dependiente 3.0 · <?php echo esc_html($site_name); ?></strong>
        <span>Nuevo flujo de Intérprete → catálogo → categorías → productos.</span>
    </footer>
</div>
<?php wp_footer(); ?>
</body>
</html>
