<?php
/**
 * Piloto internacional VEVOR: pagina publica completa y responsive.
 *
 * Cabecera, navegacion, contenido, servicios y pie estan en este archivo.
 * No se carga el menu espanol, ni el carrito, ni las plantillas de venta ES.
 * El router proporciona $dht_pilot_market (US, DE o NL).
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/template-pilot-market.php';

$market = isset($dht_pilot_market) ? strtoupper((string) $dht_pilot_market) : '';
$markets = array(
    'US' => array(
        'lang' => 'en-US', 'country' => 'United States', 'support' => 'https://www.vevor.com/pages/contact-us',
        'tagline' => 'Independent tool guides and VEVOR product selections',
        'categories' => 'Categories', 'browse' => 'Explore the collection', 'overview' => 'Category guide',
        'products' => 'Products in this category', 'product' => 'Product details',
        'view' => 'View product', 'buy' => 'View on VEVOR', 'none' => 'No products available in this pilot category yet.',
        'source' => 'Products, images and specifications are provided by the supplier. Check current availability and prices at VEVOR.',
        'details' => 'Technical details', 'sku' => 'Reference',
        'customer' => 'Purchases, shipping and returns', 'support_label' => 'VEVOR customer service',
        'service' => 'Orders and payments are completed directly on VEVOR. Delivery, returns, refunds and after-sales support for purchases made there are managed by VEVOR under its applicable terms.',
        'affiliate' => 'Affiliate disclosure', 'disclosure' => 'Distribuidor de Herramientas is an independent affiliate, not the seller. We may earn a commission if you purchase through our links, at no additional cost to you.',
        'editorial' => 'Website enquiries', 'privacy' => 'Our privacy policy', 'back' => 'Browse categories',
    ),
    'DE' => array(
        'lang' => 'de-DE', 'country' => 'Deutschland', 'support' => 'https://www.vevor.de/pages/contact-us',
        'tagline' => 'Unabhängige Ratgeber und VEVOR-Produktempfehlungen',
        'categories' => 'Kategorien', 'browse' => 'Auswahl entdecken', 'overview' => 'Ratgeber zur Kategorie',
        'products' => 'Produkte in dieser Kategorie', 'product' => 'Produktinformationen',
        'view' => 'Produkt ansehen', 'buy' => 'Bei VEVOR ansehen', 'none' => 'In dieser Pilotkategorie sind derzeit keine Produkte verfügbar.',
        'source' => 'Produktbilder, technische Angaben und Produktinformationen stammen vom Anbieter. Aktuelle Preise und Verfügbarkeit bitte bei VEVOR prüfen.',
        'details' => 'Technische Daten', 'sku' => 'Artikelnummer',
        'customer' => 'Kauf, Versand und Rückgabe', 'support_label' => 'VEVOR-Kundenservice',
        'service' => 'Bestellung und Bezahlung erfolgen direkt bei VEVOR. Lieferung, Rücksendungen, Erstattungen und Kundenservice für dort getätigte Käufe bearbeitet VEVOR gemäß den geltenden Bedingungen.',
        'affiliate' => 'Affiliate-Hinweis', 'disclosure' => 'Distribuidor de Herramientas ist ein unabhängiger Affiliate und nicht der Verkäufer. Bei einem Kauf über unsere Links erhalten wir möglicherweise eine Provision, ohne Mehrkosten für Sie.',
        'editorial' => 'Kontakt zur Website', 'privacy' => 'Unsere Datenschutzerklärung', 'back' => 'Kategorien ansehen',
    ),
    'NL' => array(
        'lang' => 'nl-NL', 'country' => 'Nederland', 'support' => 'https://www.vevor.nl/pages/contact-us',
        'tagline' => 'Onafhankelijke keuzehulpen en VEVOR-productselecties',
        'categories' => 'Categorieën', 'browse' => 'Bekijk het assortiment', 'overview' => 'Categorieadvies',
        'products' => 'Producten in deze categorie', 'product' => 'Productinformatie',
        'view' => 'Bekijk product', 'buy' => 'Bekijk bij VEVOR', 'none' => 'Er zijn nog geen producten beschikbaar in deze pilotcategorie.',
        'source' => 'Productafbeeldingen en specificaties zijn afkomstig van de leverancier. Controleer actuele prijzen en beschikbaarheid bij VEVOR.',
        'details' => 'Specificaties', 'sku' => 'Artikelnummer',
        'customer' => 'Bestellen, verzenden en retourneren', 'support_label' => 'VEVOR-klantenservice',
        'service' => 'Bestellingen en betalingen verlopen rechtstreeks via VEVOR. Levering, retourzendingen, terugbetalingen en klantenservice voor aankopen daar worden door VEVOR volgens de toepasselijke voorwaarden afgehandeld.',
        'affiliate' => 'Affiliatevermelding', 'disclosure' => 'Distribuidor de Herramientas is een onafhankelijke affiliate en niet de verkoper. Bij aankopen via onze links kunnen wij een commissie ontvangen, zonder extra kosten voor u.',
        'editorial' => 'Contact over deze website', 'privacy' => 'Ons privacybeleid', 'back' => 'Bekijk categorieën',
    ),
);

if (!isset($markets[$market])) {
    return;
}
$t = $markets[$market];
$GLOBALS['dht_frontend_language_tag'] = $t['lang'];
$is_category = function_exists('is_product_category') && is_product_category();
$term = $is_category ? get_queried_object() : null;
$product = !$is_category && function_exists('wc_get_product') ? wc_get_product(get_queried_object_id()) : null;

if ($is_category && !($term instanceof WP_Term)) {
    return;
}
if (!$is_category && !($product instanceof WC_Product)) {
    return;
}

$affiliate_url = static function ($item) {
    if (!($item instanceof WC_Product)) {
        return '';
    }
    $id = $item->get_id();
    $candidates = array(
        get_post_meta($id, '_seo_proveedor_url_canonica', true),
        get_post_meta($id, '_seo_proveedor_url_origen', true),
    );
    if ($item->is_type('external')) {
        array_unshift($candidates, $item->get_product_url());
    }
    foreach ($candidates as $candidate) {
        $url = esc_url_raw(trim((string) $candidate));
        if ($url === '' || !in_array(strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)), array('http', 'https'), true)) {
            continue;
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        // No enviar al visitante a tiendas ajenas ni al carrito de Espana.
        if (!preg_match('/(^|\.)vevor\.(?:com|de|nl)$/', $host)) {
            continue;
        }
        // Mismo etiquetado de afiliacion usado por el bloque VEVOR del plugin.
        return add_query_arg(array(
            'utm_source' => 'inhouse',
            'utm_medium' => 'affiliate',
            'utm_campaign' => '53435399',
        ), $url);
    }
    return '';
};

$product_images = static function ($item) {
    if (!($item instanceof WC_Product)) {
        return array();
    }
    if (function_exists('seo_images_get_public_product_urls')) {
        return (array) seo_images_get_public_product_urls($item, 4, false);
    }
    $image_id = absint($item->get_image_id());
    $url = $image_id ? wp_get_attachment_image_url($image_id, 'large') : '';
    return $url ? array($url) : array();
};

$primary_category = $term;
if (!$is_category) {
    $terms = get_the_terms($product->get_id(), 'product_cat');
    if (is_array($terms)) {
        $depth = -1;
        foreach ($terms as $candidate) {
            if (dht_pilot_country_for_term($candidate) !== $market) {
                continue;
            }
            $current_depth = count(get_ancestors($candidate->term_id, 'product_cat', 'taxonomy'));
            if ($current_depth > $depth) {
                $primary_category = $candidate;
                $depth = $current_depth;
            }
        }
    }
}

// Menu de como maximo tres categorias del pais: hijos de su raiz,
// o categorias hermanas etiquetadas con el pais cuando no hay raiz.
$navigation = array();
if ($primary_category instanceof WP_Term) {
    $lineage = array_merge(
        array((int) $primary_category->term_id),
        get_ancestors($primary_category->term_id, 'product_cat', 'taxonomy')
    );
    $country_roots = array(
        'US' => array('us', 'usa', 'eua', 'eeuu', 'estados-unidos', 'united-states'),
        'DE' => array('de', 'alemania', 'deutschland', 'germany'),
        'NL' => array('nl', 'holanda', 'paises-bajos', 'nederland', 'netherlands'),
    );
    $root = null;
    foreach ($lineage as $ancestor_id) {
        $ancestor = get_term((int) $ancestor_id, 'product_cat');
        if ($ancestor instanceof WP_Term && in_array($ancestor->slug, $country_roots[$market], true)) {
            $root = $ancestor;
            break;
        }
    }
    $parent = $root instanceof WP_Term ? (int) $root->term_id : (int) $primary_category->parent;
    $choices = get_terms(array(
        'taxonomy' => 'product_cat', 'parent' => $parent, 'hide_empty' => false,
        'orderby' => 'name', 'order' => 'ASC', 'number' => 100,
    ));
    if (is_array($choices)) {
        foreach ($choices as $choice) {
            if (!($choice instanceof WP_Term) || dht_pilot_country_for_term($choice) !== $market) {
                continue;
            }
            $navigation[] = $choice;
            if (count($navigation) === 3) {
                break;
            }
        }
    }
    if (!$navigation && dht_pilot_country_for_term($primary_category) === $market) {
        $navigation[] = $primary_category;
    }
}

$category_items = array();
$category_excerpt = '';
$category_description = '';
$hero_image = '';
if ($is_category) {
    global $wpdb;
    $seo_table = $wpdb->prefix . 'seo_nodes';
    $category_excerpt = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT keywords FROM {$seo_table} WHERE object_type = 'category' AND object_id = %d AND seo_role = 'excerpt' AND status = 1 ORDER BY updated_at DESC, id DESC LIMIT 1",
        (int) $term->term_id
    ));
    if (trim(wp_strip_all_tags($category_excerpt)) === '') {
        $category_excerpt = (string) get_term_meta($term->term_id, 'seo_excerpt', true);
    }
    $category_description = (string) $wpdb->get_var($wpdb->prepare(
        "SELECT keywords FROM {$seo_table} WHERE object_type = 'category' AND object_id = %d AND seo_role = 'description' AND status = 1 ORDER BY updated_at DESC, id DESC LIMIT 1",
        (int) $term->term_id
    ));
    if (trim(wp_strip_all_tags($category_description)) === '') {
        $category_description = (string) term_description($term->term_id, 'product_cat');
    }

    $thumb_id = absint(get_term_meta($term->term_id, 'thumbnail_id', true));
    if ($thumb_id && wp_attachment_is_image($thumb_id)) {
        $hero_image = (string) wp_get_attachment_image_url($thumb_id, 'large');
    }

    $paged = max(1, absint(get_query_var('paged')));
    $query = new WP_Query(array(
        'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 24,
        'paged' => $paged, 'no_found_rows' => false, 'fields' => 'ids',
        'tax_query' => array(array(
            'taxonomy' => 'product_cat', 'field' => 'term_id',
            'terms' => array((int) $term->term_id), 'include_children' => true,
        )),
    ));
    foreach ((array) $query->posts as $product_id) {
        $item = wc_get_product(absint($product_id));
        if (!($item instanceof WC_Product)) {
            continue;
        }
        $provider = strtolower(trim((string) get_post_meta($item->get_id(), '_seo_proveedor', true)));
        if ('vevor' !== $provider && $affiliate_url($item) === '') {
            continue;
        }
        $category_items[] = $item;
        if ($hero_image === '') {
            $urls = $product_images($item);
            if (!empty($urls[0])) {
                $hero_image = (string) $urls[0];
            }
        }
        if (count($category_items) >= 12) {
            break;
        }
    }
} else {
    $urls = $product_images($product);
    $hero_image = isset($urls[0]) ? (string) $urls[0] : '';
}
$site_title = (string) get_bloginfo('name');
$site_privacy_url = get_privacy_policy_url();
$editorial_email = 'servicioacliente@distribuidordeherramientas.es';
$main_url = $navigation ? get_term_link($navigation[0]) : ($primary_category instanceof WP_Term ? get_term_link($primary_category) : home_url('/'));
if (is_wp_error($main_url)) {
    $main_url = home_url('/');
}
?>
<!doctype html>
<html lang="<?php echo esc_attr($t['lang']); ?>">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
if (function_exists('dht_template_render_wp_head')) {
    dht_template_render_wp_head();
} else {
    wp_head();
}
?>
<style>
/* Diseno autocontenido: sin menus, widgets ni pie de venta espanoles. */
body.dht-pilot-body{margin:0;background:#f6f8fb;color:#172336;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.dht-pilot-page,.dht-pilot-page *{box-sizing:border-box}
.dht-pilot-page a{color:inherit}
.dht-pilot-page a:focus-visible{outline:3px solid #2368e7;outline-offset:3px}
.dht-pilot-page{min-height:100vh;font-size:16px;line-height:1.55}
.dht-pilot-container{width:min(1160px,calc(100% - 32px));margin-inline:auto}
.dht-pilot-header{background:#fff;border-bottom:1px solid #e4e9f1}
.dht-pilot-header-inner{padding:20px 0 12px;display:flex;align-items:center;justify-content:space-between;gap:20px}
.dht-pilot-brand{display:flex;flex-direction:column;text-decoration:none;min-width:0}
.dht-pilot-brand strong{font-size:clamp(20px,2.5vw,26px);letter-spacing:-.035em}
.dht-pilot-brand span{font-size:12px;color:#526273}
.dht-pilot-market{font-size:12px;white-space:nowrap;padding:6px 12px;border:1px solid #d6dfeb;border-radius:999px;color:#475467}
.dht-pilot-nav{display:flex;flex-wrap:wrap;gap:10px;padding:0 0 16px}
.dht-pilot-nav a{padding:8px 14px;border-radius:8px;border:1px solid #dfe5ee;text-decoration:none;font-weight:650;font-size:13px;background:#f7f9fc}
.dht-pilot-nav a:hover{background:#eaf1fc}
.dht-pilot-breadcrumb{padding:24px 0 10px;font-size:13px;color:#66758a}
.dht-pilot-breadcrumb a{text-decoration:none}
.dht-pilot-hero{display:grid;grid-template-columns:1.3fr 1fr;gap:30px;align-items:center;margin:12px 0 28px;padding:34px;background:#fff;border:1px solid #e7edf4;border-radius:18px}
.dht-pilot-kicker{text-transform:uppercase;letter-spacing:.07em;font-size:11px;font-weight:750;color:#657a92}
.dht-pilot-hero h1{font-size:clamp(28px,4vw,44px);line-height:1.16;letter-spacing:-.035em;margin:10px 0 16px}
.dht-pilot-rich{overflow-wrap:anywhere;color:#3e4d60}
.dht-pilot-rich p{margin:0 0 14px}
.dht-pilot-rich a{color:#0c51bc}
.dht-pilot-media{aspect-ratio:4/3;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#f4f7fb;border-radius:14px}
.dht-pilot-media img{display:block;width:100%;height:100%;object-fit:contain}
.dht-pilot-placeholder{font-size:14px;color:#8593a5;text-align:center;padding:16px}
.dht-pilot-section{background:#fff;border:1px solid #e7edf4;border-radius:16px;padding:28px;margin-bottom:28px}
.dht-pilot-section h2{font-size:clamp(22px,2.4vw,28px);letter-spacing:-.02em;line-height:1.25;margin:0 0 18px}
.dht-pilot-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px}
.dht-pilot-card{display:flex;min-width:0;flex-direction:column;border:1px solid #e4e9f1;border-radius:12px;overflow:hidden;background:#fff}
.dht-pilot-card .dht-pilot-media{border-radius:0;aspect-ratio:5/4}
.dht-pilot-card-body{display:flex;flex:1;flex-direction:column;gap:14px;padding:18px}
.dht-pilot-card h3{font-size:17px;line-height:1.35;margin:0}
.dht-pilot-button{display:inline-flex;align-items:center;justify-content:center;border-radius:9px;padding:11px 18px;font-size:14px;font-weight:700;text-decoration:none!important;background:#183e75;color:#fff!important}
.dht-pilot-card .dht-pilot-button{margin-top:auto}
.dht-pilot-button:hover{background:#0a2854}
.dht-pilot-note{font-size:13px;color:#65748a;margin:18px 0 0}
.dht-pilot-specs{display:grid;grid-template-columns:minmax(125px,1fr) 2fr;gap:8px 24px}
.dht-pilot-specs dt{font-weight:700;color:#46566a}
.dht-pilot-specs dd{margin:0;overflow-wrap:anywhere}
.dht-pilot-footer{background:#142238;color:#e5eef9;margin-top:40px;padding:34px 0 24px}
.dht-pilot-footer-grid{display:grid;grid-template-columns:1fr 1fr;gap:30px}
.dht-pilot-footer h2{font-size:16px;margin:0 0 12px;color:#fff}
.dht-pilot-footer p{font-size:13px;line-height:1.7;color:#d5e0ee}
.dht-pilot-footer a{color:#e3efff}
.dht-pilot-copyright{border-top:1px solid #40536c;margin-top:20px;padding-top:15px;font-size:12px;color:#c2cedd}
@media(max-width:760px){.dht-pilot-header-inner{align-items:flex-start}.dht-pilot-brand span{max-width:280px}.dht-pilot-hero{grid-template-columns:1fr;padding:20px;gap:20px}.dht-pilot-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.dht-pilot-section{padding:18px}.dht-pilot-footer-grid{grid-template-columns:1fr;gap:18px}}
@media(max-width:480px){.dht-pilot-grid{grid-template-columns:1fr}.dht-pilot-market{white-space:normal}.dht-pilot-brand strong{font-size:20px}.dht-pilot-header-inner{gap:10px}}
</style>
</head>
<body <?php body_class('dht-pilot-body'); ?>>
<?php wp_body_open(); ?>
<div class="dht-pilot-page">
<header class="dht-pilot-header">
    <div class="dht-pilot-container">
        <div class="dht-pilot-header-inner">
            <a class="dht-pilot-brand" href="<?php echo esc_url($main_url); ?>">
                <strong><?php echo esc_html($site_title); ?></strong>
                <span><?php echo esc_html($t['tagline']); ?></span>
            </a>
            <span class="dht-pilot-market"><?php echo esc_html($t['country']); ?></span>
        </div>
        <?php if ($navigation) : ?>
        <nav class="dht-pilot-nav" aria-label="<?php echo esc_attr($t['categories']); ?>">
            <?php foreach ($navigation as $nav_term) :
                $url = get_term_link($nav_term);
                if (is_wp_error($url)) { continue; }
                ?>
                <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($nav_term->name); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
    </div>
</header>

<main class="dht-pilot-container" id="main">
    <nav class="dht-pilot-breadcrumb" aria-label="<?php echo esc_attr($t['categories']); ?>">
        <a href="<?php echo esc_url($main_url); ?>"><?php echo esc_html($t['categories']); ?></a>
        <?php if ($primary_category instanceof WP_Term) : ?>
            <span aria-hidden="true"> / </span>
            <?php if (!$is_category) :
                $category_url = get_term_link($primary_category);
                if (!is_wp_error($category_url)) : ?>
                    <a href="<?php echo esc_url($category_url); ?>"><?php echo esc_html($primary_category->name); ?></a>
                <?php endif;
            else : ?>
                <span><?php echo esc_html($primary_category->name); ?></span>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <section class="dht-pilot-hero" aria-labelledby="dht-pilot-title">
        <div>
            <span class="dht-pilot-kicker"><?php echo esc_html($is_category ? $t['overview'] : $t['product']); ?></span>
            <h1 id="dht-pilot-title"><?php echo esc_html($is_category ? $term->name : $product->get_name()); ?></h1>
            <div class="dht-pilot-rich">
                <?php if ($is_category && $category_excerpt !== '') : ?>
                    <?php echo wp_kses_post(wpautop($category_excerpt)); ?>
                <?php elseif (!$is_category && $product->get_short_description() !== '') : ?>
                    <?php echo wp_kses_post(wpautop($product->get_short_description())); ?>
                <?php endif; ?>
            </div>
            <?php if (!$is_category) :
                $link = $affiliate_url($product);
                if ($link !== '') : ?>
                    <a class="dht-pilot-button" href="<?php echo esc_url($link); ?>" target="_blank" rel="sponsored noopener noreferrer"><?php echo esc_html($t['buy']); ?> &rarr;</a>
                <?php endif;
            endif; ?>
        </div>
        <div class="dht-pilot-media">
            <?php if ($hero_image !== '') : ?>
                <img src="<?php echo esc_url($hero_image); ?>" alt="<?php echo esc_attr($is_category ? $term->name : $product->get_name()); ?>" decoding="async" fetchpriority="high">
            <?php else : ?>
                <span class="dht-pilot-placeholder"><?php echo esc_html($site_title); ?></span>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($is_category) : ?>
        <?php if (trim(wp_strip_all_tags($category_description)) !== '') : ?>
        <section class="dht-pilot-section" aria-labelledby="dht-pilot-description">
            <h2 id="dht-pilot-description"><?php echo esc_html($t['overview']); ?></h2>
            <div class="dht-pilot-rich"><?php echo wp_kses_post(wpautop($category_description)); ?></div>
        </section>
        <?php endif; ?>

        <section class="dht-pilot-section" aria-labelledby="dht-pilot-products">
            <h2 id="dht-pilot-products"><?php echo esc_html($t['products']); ?></h2>
            <?php if ($category_items) : ?>
                <div class="dht-pilot-grid">
                    <?php foreach ($category_items as $item) :
                        $images = $product_images($item);
                        $image = $images ? (string) $images[0] : '';
                    ?>
                    <article class="dht-pilot-card">
                        <a class="dht-pilot-media" href="<?php echo esc_url(get_permalink($item->get_id())); ?>">
                            <?php if ($image !== '') : ?>
                                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($item->get_name()); ?>" loading="lazy" decoding="async">
                            <?php else : ?>
                                <span class="dht-pilot-placeholder">VEVOR</span>
                            <?php endif; ?>
                        </a>
                        <div class="dht-pilot-card-body">
                            <h3><?php echo esc_html($item->get_name()); ?></h3>
                            <a class="dht-pilot-button" href="<?php echo esc_url(get_permalink($item->get_id())); ?>"><?php echo esc_html($t['view']); ?> &rarr;</a>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p><?php echo esc_html($t['none']); ?></p>
            <?php endif; ?>
            <p class="dht-pilot-note"><?php echo esc_html($t['source']); ?></p>
        </section>
    <?php else : ?>
        <?php if (trim(wp_strip_all_tags($product->get_description())) !== '') : ?>
        <section class="dht-pilot-section" aria-labelledby="dht-pilot-product-description">
            <h2 id="dht-pilot-product-description"><?php echo esc_html($t['product']); ?></h2>
            <div class="dht-pilot-rich"><?php echo wp_kses_post(wpautop($product->get_description())); ?></div>
        </section>
        <?php endif; ?>

        <?php
        $attributes = $product->get_attributes();
        $specs = array();
        foreach ($attributes as $attr) {
            if (!($attr instanceof WC_Product_Attribute)) { continue; }
            $label = wc_attribute_label($attr->get_name());
            $value = $attr->is_taxonomy()
                ? implode(', ', wc_get_product_terms($product->get_id(), $attr->get_name(), array('fields' => 'names')))
                : implode(', ', $attr->get_options());
            if ($value !== '') { $specs[$label] = $value; }
            if (count($specs) >= 12) { break; }
        }
        ?>
        <?php if ($specs || $product->get_sku()) : ?>
        <section class="dht-pilot-section" aria-labelledby="dht-pilot-specs">
            <h2 id="dht-pilot-specs"><?php echo esc_html($t['details']); ?></h2>
            <dl class="dht-pilot-specs">
                <?php if ($product->get_sku()) : ?>
                    <dt><?php echo esc_html($t['sku']); ?></dt><dd><?php echo esc_html($product->get_sku()); ?></dd>
                <?php endif; ?>
                <?php foreach ($specs as $label => $value) : ?>
                    <dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($value); ?></dd>
                <?php endforeach; ?>
            </dl>
        </section>
        <?php endif; ?>
        <p class="dht-pilot-note"><?php echo esc_html($t['source']); ?></p>
    <?php endif; ?>
</main>

<footer class="dht-pilot-footer">
    <div class="dht-pilot-container">
        <div class="dht-pilot-footer-grid">
            <section>
                <h2><?php echo esc_html($t['customer']); ?></h2>
                <p><?php echo esc_html($t['service']); ?></p>
                <p><a href="<?php echo esc_url($t['support']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($t['support_label']); ?> &rarr;</a></p>
            </section>
            <section>
                <h2><?php echo esc_html($t['affiliate']); ?></h2>
                <p><?php echo esc_html($t['disclosure']); ?></p>
                <p><a href="mailto:<?php echo esc_attr($editorial_email); ?>"><?php echo esc_html($t['editorial']); ?></a>
                <?php if ($site_privacy_url) : ?>
                    &nbsp;·&nbsp; <a href="<?php echo esc_url($site_privacy_url); ?>"><?php echo esc_html($t['privacy']); ?></a>
                <?php endif; ?></p>
            </section>
        </div>
        <div class="dht-pilot-copyright">&copy; <?php echo esc_html(wp_date('Y')); ?> <?php echo esc_html($site_title); ?> · <?php echo esc_html($t['country']); ?></div>
    </div>
</footer>
</div>
<?php wp_footer(); ?>
</body>
</html>
