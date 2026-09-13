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

/**
 * Construye las migas de pan globales de las plantillas DHT.
 *
 * Se mantiene en la cabecera compartida para que productos, categorias,
 * paginas, entradas y archivos usen la misma jerarquia visual.
 *
 * @return array<int,array{label:string,url:string}>
 */
if (!function_exists('dht_header_get_breadcrumb_items')) {
    function dht_header_get_breadcrumb_items() {
        if (is_front_page()) {
            return array();
        }

        $items = array(
            array(
                'label' => 'Inicio',
                'url'   => home_url('/'),
            ),
        );

        $shop_url = function_exists('wc_get_page_permalink')
            ? wc_get_page_permalink('shop')
            : home_url('/tienda/');

        if (!$shop_url) {
            $shop_url = home_url('/tienda/');
        }

        $posts_page_id = (int) get_option('page_for_posts');
        $blog_url      = $posts_page_id > 0 ? get_permalink($posts_page_id) : home_url('/blog/');
        $blog_label    = $posts_page_id > 0 ? get_the_title($posts_page_id) : 'Blog';

        if ($blog_label === '') {
            $blog_label = 'Blog';
        }

        $append_term_ancestors = static function (&$target, $term, $taxonomy) {
            if (!$term || is_wp_error($term) || empty($term->term_id)) {
                return;
            }

            $ancestor_ids = array_reverse(
                get_ancestors((int) $term->term_id, $taxonomy, 'taxonomy')
            );

            foreach ($ancestor_ids as $ancestor_id) {
                $ancestor = get_term((int) $ancestor_id, $taxonomy);
                if (!$ancestor || is_wp_error($ancestor)) {
                    continue;
                }

                $url = get_term_link($ancestor);
                if (is_wp_error($url)) {
                    $url = '';
                }

                $target[] = array(
                    'label' => (string) $ancestor->name,
                    'url'   => (string) $url,
                );
            }
        };

        $add_current = static function (&$target, $label) {
            $label = trim(wp_strip_all_tags((string) $label));
            if ($label === '') {
                return;
            }

            $target[] = array(
                'label' => $label,
                'url'   => '',
            );
        };

        /* WooCommerce: tienda, categorias, productos y paginas de compra. */
        if (function_exists('is_shop') && is_shop()) {
            $add_current($items, 'Tienda');
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (function_exists('is_product_category') && is_product_category()) {
            $items[] = array('label' => 'Tienda', 'url' => $shop_url);
            $term = get_queried_object();
            $append_term_ancestors($items, $term, 'product_cat');
            $add_current($items, isset($term->name) ? $term->name : single_term_title('', false));
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (function_exists('is_product_tag') && is_product_tag()) {
            $items[] = array('label' => 'Tienda', 'url' => $shop_url);
            $term = get_queried_object();
            $add_current($items, isset($term->name) ? $term->name : single_term_title('', false));
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (function_exists('is_product') && is_product()) {
            $items[] = array('label' => 'Tienda', 'url' => $shop_url);

            $terms = wp_get_post_terms(get_the_ID(), 'product_cat');
            if (!is_wp_error($terms) && !empty($terms)) {
                usort(
                    $terms,
                    static function ($a, $b) {
                        $depth_a = count(get_ancestors((int) $a->term_id, 'product_cat', 'taxonomy'));
                        $depth_b = count(get_ancestors((int) $b->term_id, 'product_cat', 'taxonomy'));

                        if ($depth_a === $depth_b) {
                            return (int) $a->term_id <=> (int) $b->term_id;
                        }

                        return $depth_b <=> $depth_a;
                    }
                );

                $term = $terms[0];
                $append_term_ancestors($items, $term, 'product_cat');
                $term_url = get_term_link($term);
                $items[] = array(
                    'label' => (string) $term->name,
                    'url'   => is_wp_error($term_url) ? '' : (string) $term_url,
                );
            }

            $add_current($items, get_the_title());
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (function_exists('is_cart') && is_cart()) {
            $items[] = array('label' => 'Tienda', 'url' => $shop_url);
            $add_current($items, 'Carrito');
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (function_exists('is_checkout') && is_checkout()) {
            $items[] = array('label' => 'Tienda', 'url' => $shop_url);
            $add_current($items, get_the_title() ?: 'Finalizar compra');
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (function_exists('is_account_page') && is_account_page()) {
            $items[] = array('label' => 'Tienda', 'url' => $shop_url);
            $add_current($items, get_the_title() ?: 'Mi cuenta');
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        /* Blog y entradas. */
        if (is_home()) {
            $add_current($items, $blog_label);
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (is_singular('post')) {
            $items[] = array('label' => $blog_label, 'url' => $blog_url);

            $terms = get_the_category(get_the_ID());
            if (!empty($terms)) {
                usort(
                    $terms,
                    static function ($a, $b) {
                        $depth_a = count(get_ancestors((int) $a->term_id, 'category', 'taxonomy'));
                        $depth_b = count(get_ancestors((int) $b->term_id, 'category', 'taxonomy'));

                        if ($depth_a === $depth_b) {
                            return (int) $a->term_id <=> (int) $b->term_id;
                        }

                        return $depth_b <=> $depth_a;
                    }
                );

                $term = $terms[0];
                $append_term_ancestors($items, $term, 'category');
                $term_url = get_term_link($term);
                $items[] = array(
                    'label' => (string) $term->name,
                    'url'   => is_wp_error($term_url) ? '' : (string) $term_url,
                );
            }

            $add_current($items, get_the_title());
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (is_category()) {
            $items[] = array('label' => $blog_label, 'url' => $blog_url);
            $term = get_queried_object();
            $append_term_ancestors($items, $term, 'category');
            $add_current($items, isset($term->name) ? $term->name : single_cat_title('', false));
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (is_tag()) {
            $items[] = array('label' => $blog_label, 'url' => $blog_url);
            $add_current($items, single_tag_title('', false));
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (is_author() || is_date()) {
            $items[] = array('label' => $blog_label, 'url' => $blog_url);
            $add_current($items, get_the_archive_title());
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        /* Paginas jerarquicas. */
        if (is_page()) {
            $ancestor_ids = array_reverse(get_post_ancestors(get_the_ID()));
            foreach ($ancestor_ids as $ancestor_id) {
                $items[] = array(
                    'label' => get_the_title($ancestor_id),
                    'url'   => get_permalink($ancestor_id),
                );
            }

            $add_current($items, get_the_title());
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        /* Otros tipos de contenido y taxonomias del plugin. */
        if (is_singular()) {
            $post_type = get_post_type();
            $object = $post_type ? get_post_type_object($post_type) : null;

            if ($object && !empty($object->has_archive)) {
                $archive_url = get_post_type_archive_link($post_type);
                if ($archive_url) {
                    $items[] = array(
                        'label' => isset($object->labels->name) ? $object->labels->name : $object->label,
                        'url'   => $archive_url,
                    );
                }
            }

            $add_current($items, get_the_title());
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (is_tax()) {
            $term = get_queried_object();
            if ($term && !is_wp_error($term) && !empty($term->taxonomy)) {
                $append_term_ancestors($items, $term, $term->taxonomy);
                $add_current($items, $term->name);
            }
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (is_post_type_archive()) {
            $add_current($items, post_type_archive_title('', false));
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (is_search()) {
            $add_current($items, sprintf('Resultados para “%s”', get_search_query()));
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        if (is_404()) {
            $add_current($items, 'Pagina no encontrada');
            return apply_filters('dht_header_breadcrumb_items', $items);
        }

        return apply_filters('dht_header_breadcrumb_items', $items);
    }
}

if (!function_exists('dht_header_render_breadcrumbs')) {
    function dht_header_render_breadcrumbs() {
        $items = dht_header_get_breadcrumb_items();
        if (count($items) < 2) {
            return;
        }
        ?>
        <nav class="dht-header-breadcrumbs" aria-label="Migas de pan">
            <div class="dht-header-breadcrumbs__inner">
                <ol itemscope itemtype="https://schema.org/BreadcrumbList">
                    <?php foreach ($items as $index => $item) : ?>
                        <?php
                        $label   = isset($item['label']) ? trim((string) $item['label']) : '';
                        $url     = isset($item['url']) ? (string) $item['url'] : '';
                        $is_last = $index === count($items) - 1;

                        if ($label === '') {
                            continue;
                        }
                        ?>
                        <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                            <?php if (!$is_last && $url !== '') : ?>
                                <a itemprop="item" href="<?php echo esc_url($url); ?>">
                                    <span itemprop="name"><?php echo esc_html($label); ?></span>
                                </a>
                            <?php else : ?>
                                <span itemprop="name"<?php echo $is_last ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></span>
                            <?php endif; ?>
                            <meta itemprop="position" content="<?php echo esc_attr((string) ($index + 1)); ?>">
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </nav>
        <?php
    }
}
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

/* Migas de pan globales, debajo de la navegacion principal. */
if (function_exists('dht_header_render_breadcrumbs')) {
    dht_header_render_breadcrumbs();
}
?>