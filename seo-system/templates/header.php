<?php
/**
 * Cabecera publica compartida por las plantillas del plugin.
 * Menu: navegacion nativa de GeneratePress.
 * Accesos: Blog, Carrito WooCommerce y WhatsApp.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

/*
 * La hoja se inserta inline de forma deliberada.
 * El servidor genera correctamente la URL/version del CSS, pero en el front
 * el navegador no esta aplicando ese recurso externo. Al imprimir el mismo
 * archivo dentro del <head> evitamos 404/MIME/proxy/CDN sin duplicar logica.
 */
$dht_css_path    = __DIR__ . '/styles-template.css';
$dht_css_inline  = is_readable($dht_css_path) ? file_get_contents($dht_css_path) : '';

/* Evita que quede encolada otra copia externa con el mismo handle. */
wp_dequeue_style('dht-template-styles');
wp_deregister_style('dht-template-styles');

$site_name    = get_bloginfo('name');
$site_tagline = get_bloginfo('description');
$logo_url     = 'https://www.distribuidordeherramientas.es/wp-content/uploads/2026/01/Logo2.webp';

$shop_url   = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/tienda/');
$cart_url   = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/carrito/');
$cart_count = 0;
if (function_exists('WC') && WC() && WC()->cart) {
    $cart_count = (int) WC()->cart->get_cart_contents_count();
}

$whatsapp_number = '34640874540';
$whatsapp_text   = rawurlencode('Hola, necesito informacion sobre un producto.');
$whatsapp_url    = 'https://wa.me/' . $whatsapp_number . '?text=' . $whatsapp_text;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <?php if (is_front_page() && get_bloginfo('description') !== '') : ?>
        <meta name="description" content="<?php echo esc_attr(get_bloginfo('description')); ?>">
    <?php endif; ?>
    <?php wp_head(); ?>

    <?php if ($dht_css_inline !== '') : ?>
        <style id="dht-template-styles-inline">
<?php echo $dht_css_inline; ?>
        </style>
    <?php endif; ?>

    <!-- Proteccion frente a valores visuales globales corruptos/inadecuados. -->
    <style id="dht-runtime-safe-vars">
        html:root {
            --dht-bg: #f7f8fa !important;
            --dht-bg-light: #fafbfc !important;
        }
    </style>

    <style id="dht-header-actions-css">
        .dht-header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            white-space: nowrap;
        }

        .dht-header-action {
            position: relative;
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 0 13px;
            border: 1px solid var(--dht-border, #dfe3e8);
            border-radius: 10px;
            background: #fff;
            color: var(--dht-text, #17212b);
            font-size: 13px;
            font-weight: 750;
            line-height: 1;
            text-decoration: none;
            transition: background-color .18s ease, border-color .18s ease, color .18s ease, transform .18s ease;
        }

        .dht-header-action:hover,
        .dht-header-action:focus-visible {
            border-color: var(--dht-primary, #007acc);
            background: var(--dht-bg-light, #f5f7f9);
            color: var(--dht-primary-dark, #005f9e);
            transform: translateY(-1px);
        }

        .dht-header-action svg {
            width: 19px;
            height: 19px;
            flex: 0 0 19px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.9;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .dht-header-action--whatsapp {
            border-color: #1fa855;
            background: #25d366;
            color: #0d3b22;
        }

        .dht-header-action--whatsapp:hover,
        .dht-header-action--whatsapp:focus-visible {
            border-color: #168844;
            background: #20bd5a;
            color: #092b18;
        }

        .dht-cart-count {
            display: inline-grid;
            min-width: 20px;
            height: 20px;
            place-items: center;
            padding: 0 5px;
            border-radius: 999px;
            background: var(--dht-primary, #007acc);
            color: #fff;
            font-size: 11px;
            font-weight: 800;
            line-height: 20px;
        }

        @media (min-width: 1024px) {
            .header-main-tools {
                grid-template-columns: minmax(280px, 1fr) auto !important;
                gap: 14px !important;
            }
        }

        @media (max-width: 767px) {
            .header-top {
                grid-template-columns: minmax(0, 1fr) auto !important;
                grid-template-areas:
                    "brand actions"
                    "search search" !important;
                gap: 10px !important;
            }

            .dht-header-actions {
                grid-area: actions;
                gap: 6px;
            }

            .dht-header-action {
                width: 42px;
                min-width: 42px;
                height: 42px;
                min-height: 42px;
                padding: 0;
                border-radius: 11px;
            }

            .dht-header-action-label {
                position: absolute !important;
                width: 1px !important;
                height: 1px !important;
                padding: 0 !important;
                margin: -1px !important;
                overflow: hidden !important;
                clip: rect(0, 0, 0, 0) !important;
                white-space: nowrap !important;
                border: 0 !important;
            }

            .dht-header-action svg {
                width: 20px;
                height: 20px;
                flex-basis: 20px;
            }

            .dht-cart-count {
                position: absolute;
                top: -6px;
                right: -6px;
                min-width: 18px;
                height: 18px;
                padding: 0 4px;
                border: 2px solid #fff;
                font-size: 10px;
                line-height: 14px;
            }
        }

        @media (max-width: 380px) {
            .custom-header .logo img {
                max-width: 138px !important;
            }

            .dht-header-action {
                width: 39px;
                min-width: 39px;
                height: 39px;
                min-height: 39px;
            }
        }
    </style>
</head>
<body <?php body_class('dht-template-body'); ?>>
<?php wp_body_open(); ?>

<header class="custom-header" role="banner">
    <div class="header-top">
        <div class="brand">
            <a class="logo" href="<?php echo esc_url(home_url('/')); ?>" rel="home" aria-label="<?php echo esc_attr($site_name); ?>">
                <img
                    src="<?php echo esc_url($logo_url); ?>"
                    alt="<?php echo esc_attr($site_name); ?>"
                    width="250"
                    height="94"
                    decoding="async"
                >
            </a>

            <?php if ($site_tagline !== '') : ?>
                <div class="brand-text">
                    <span class="site-subtitle"><?php echo esc_html($site_tagline); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="header-main-tools">
            <form class="search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                <label class="screen-reader-text" for="dht-header-search">Buscar productos</label>
                <input
                    id="dht-header-search"
                    type="search"
                    name="s"
                    placeholder="Buscar herramientas, marcas o referencias..."
                    value="<?php echo esc_attr(get_search_query()); ?>"
                    autocomplete="off"
                >
                <input type="hidden" name="post_type" value="product">
                <button type="submit" aria-label="Buscar productos">
                    <span aria-hidden="true">⌕</span>
                </button>
            </form>

            <nav class="dht-header-actions" aria-label="Accesos rapidos">
                <a class="dht-header-action dht-header-action--shop" href="<?php echo esc_url($shop_url); ?>" aria-label="Ir a la tienda">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M4 9h16l-1-4H5L4 9z"></path>
                        <path d="M5 9v10h14V9"></path>
                        <path d="M9 19v-5h6v5"></path>
                    </svg>
                    <span class="dht-header-action-label">Tienda</span>
                </a>

                <a class="dht-header-action dht-header-action--cart" href="<?php echo esc_url($cart_url); ?>" aria-label="Ver carrito, <?php echo esc_attr($cart_count); ?> articulos">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M3 4h2l2.1 10.1a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L20.2 8H6"></path>
                        <circle cx="9.5" cy="19" r="1"></circle>
                        <circle cx="17" cy="19" r="1"></circle>
                    </svg>
                    <span class="dht-header-action-label">Carrito</span>
                    <span class="dht-cart-count" aria-hidden="true"><?php echo esc_html($cart_count); ?></span>
                </a>

                <a class="dht-header-action dht-header-action--whatsapp" href="<?php echo esc_url($whatsapp_url); ?>" target="_blank" rel="noopener noreferrer" aria-label="Contactar por WhatsApp">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M20 11.6a8 8 0 0 1-11.8 7L4 20l1.4-4A8 8 0 1 1 20 11.6z"></path>
                        <path d="M8.6 8.3c.2-.4.4-.4.7-.4h.5c.2 0 .4.1.5.4l.8 1.9c.1.3 0 .5-.2.7l-.6.7c-.2.2-.2.4 0 .7.7 1.2 1.7 2.2 3 2.9.3.2.5.2.7 0l.8-.9c.2-.2.4-.3.7-.2l1.9.9c.3.1.4.3.4.5 0 .4-.2 1.2-.8 1.7-.6.5-1.4.7-2.1.6-1.3-.2-3.1-.8-4.9-2.4-1.5-1.3-2.5-2.9-3-4.2-.5-1.2-.1-2.3.3-2.9z"></path>
                    </svg>
                    <span class="dht-header-action-label">WhatsApp</span>
                </a>
            </nav>
        </div>
    </div>
</header>

<?php
/**
 * Navegacion nativa de GeneratePress.
 * GeneratePress controla menu horizontal, submenus y hamburguesa movil.
 */
if (function_exists('generate_navigation_position')) {
    generate_navigation_position();
}
?>