<?php
/**
 * Plantilla publica independiente de Dependiente.
 *
 * Mantiene el ciclo tecnico de WordPress (wp_head/wp_footer), por lo que
 * Analytics, consentimiento, scripts y hooks globales siguen funcionando,
 * pero evita la cabecera, navegacion y pie visuales de las plantillas SEO.
 */

defined('ABSPATH') || exit;

$site_name = get_bloginfo('name') ?: 'DistribuidorDeHerramientas.es';
$home_url  = home_url('/');
$page_url  = get_permalink() ?: home_url('/dependiente/');
$shop_url  = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/tienda/');
$cart_url  = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/carrito/');
$privacy_url = function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : '';
$cart_count = 0;
if (function_exists('WC') && WC() && WC()->cart) {
    $cart_count = (int) WC()->cart->get_cart_contents_count();
}

$logo_html = '';
$logo_id = absint(get_theme_mod('custom_logo'));
if ($logo_id) {
    $logo_html = wp_get_attachment_image(
        $logo_id,
        'medium',
        false,
        array(
            'class'    => 'seo-dependiente-app__logo-image',
            'loading'  => 'eager',
            'decoding' => 'async',
            'alt'      => $site_name,
        )
    );
}

$description = 'Dependiente digital para encontrar, filtrar y comparar herramientas y productos según el trabajo que necesitas realizar.';
$schema = array(
    '@context' => 'https://schema.org',
    '@graph'   => array(
        array(
            '@type'       => 'Organization',
            '@id'         => trailingslashit($home_url) . '#organization',
            'name'        => $site_name,
            'url'         => $home_url,
        ),
        array(
            '@type'       => 'WebSite',
            '@id'         => trailingslashit($home_url) . '#website',
            'url'         => $home_url,
            'name'        => $site_name,
            'publisher'   => array('@id' => trailingslashit($home_url) . '#organization'),
        ),
        array(
            '@type'       => 'WebPage',
            '@id'         => $page_url . '#webpage',
            'url'         => $page_url,
            'name'        => 'Dependiente - ' . $site_name,
            'description' => $description,
            'isPartOf'    => array('@id' => trailingslashit($home_url) . '#website'),
            'mainEntity'  => array('@id' => $page_url . '#application'),
        ),
        array(
            '@type'               => 'WebApplication',
            '@id'                 => $page_url . '#application',
            'name'                => 'Dependiente',
            'url'                 => $page_url,
            'applicationCategory' => 'ShoppingApplication',
            'operatingSystem'     => 'Web',
            'description'         => $description,
            'provider'            => array('@id' => trailingslashit($home_url) . '#organization'),
        ),
    ),
);
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?php echo esc_attr($description); ?>">
    <?php wp_head(); ?>
    <script type="application/ld+json" id="seo-dependiente-schema"><?php echo wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
</head>
<body <?php body_class('seo-dependiente-app-body'); ?>>
<?php wp_body_open(); ?>

<div class="seo-dependiente-app">
    <header class="seo-dependiente-app__header" role="banner">
        <div class="seo-dependiente-app__bar">
            <a class="seo-dependiente-app__brand" href="<?php echo esc_url($home_url); ?>" rel="home" aria-label="<?php echo esc_attr($site_name); ?>">
                <span class="seo-dependiente-app__brand-logo">
                    <?php if ($logo_html) : ?>
                        <?php echo $logo_html; ?>
                    <?php else : ?>
                        <span class="seo-dependiente-app__brand-name"><?php echo esc_html($site_name); ?></span>
                    <?php endif; ?>
                </span>
                <span class="seo-dependiente-app__product-name">Dependiente</span>
            </a>

            <nav class="seo-dependiente-app__actions" aria-label="Accesos de Dependiente">
                <a class="seo-dependiente-app__action seo-dependiente-app__action--secondary" href="<?php echo esc_url($home_url); ?>">Inicio</a>
                <a class="seo-dependiente-app__action seo-dependiente-app__action--secondary" href="<?php echo esc_url($cart_url); ?>">
                    Carrito
                    <?php if ($cart_count > 0) : ?>
                        <span class="seo-dependiente-app__cart-count" aria-label="<?php echo esc_attr($cart_count); ?> artículos"><?php echo esc_html($cart_count); ?></span>
                    <?php endif; ?>
                </a>
                <a class="seo-dependiente-app__action seo-dependiente-app__action--primary" href="<?php echo esc_url($shop_url); ?>">Ver tienda</a>
            </nav>
        </div>
    </header>

    <main id="main" class="seo-dependiente-app__main">
        <?php
        while (have_posts()) :
            the_post();
            the_content();
        endwhile;
        ?>
    </main>

    <footer class="seo-dependiente-app__footer" role="contentinfo">
        <div class="seo-dependiente-app__footer-inner">
            <div class="seo-dependiente-app__footer-copy">
                <strong>Dependiente · <?php echo esc_html($site_name); ?></strong>
                <p>Ayuda para buscar y comparar productos del catálogo. Comprueba siempre la ficha del producto antes de comprar.</p>
            </div>
            <nav class="seo-dependiente-app__footer-nav" aria-label="Información legal y navegación">
                <a href="<?php echo esc_url($home_url); ?>">DistribuidorDeHerramientas.es</a>
                <a href="<?php echo esc_url($shop_url); ?>">Tienda</a>
                <?php if ($privacy_url) : ?>
                    <a href="<?php echo esc_url($privacy_url); ?>">Privacidad</a>
                <?php endif; ?>
            </nav>
        </div>
    </footer>
</div>

<?php wp_footer(); ?>
</body>
</html>
