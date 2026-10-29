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
require_once __DIR__ . '/template-pilot-navigation.php';
require_once __DIR__ . '/template-pilot-country-switcher.php';

$market = isset($dht_pilot_market) ? strtoupper((string) $dht_pilot_market) : strtoupper((string) ($GLOBALS['dht_pilot_market'] ?? ''));
$markets = array(
    'US' => array(
        'lang' => 'en-US', 'country' => 'United States', 'support' => 'https://www.vevor.com/pages/contact-us',
        'tagline' => 'Independent tool guides and VEVOR product selections',
        'brand_title' => 'Professional Tools & Equipment · United States', 'flag' => '🇺🇸',
        'all_categories' => 'All categories', 'image_placeholder' => 'Product image unavailable',
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
        'brand_title' => 'Professionelle Werkzeuge & Ausrüstung · Deutschland', 'flag' => '🇩🇪',
        'all_categories' => 'Alle Kategorien', 'image_placeholder' => 'Kein Produktbild verfügbar',
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
        'brand_title' => 'Professioneel gereedschap & uitrusting · Nederland', 'flag' => '🇳🇱',
        'all_categories' => 'Alle categorieën', 'image_placeholder' => 'Geen productafbeelding beschikbaar',
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
    'FR' => array(
        'lang' => 'fr-FR', 'country' => 'France', 'support' => 'https://www.vevor.fr/pages/contact-us',
        'tagline' => 'Guides indépendants et sélections de produits VEVOR',
        'brand_title' => 'Outils et équipements professionnels · France', 'flag' => '🇫🇷',
        'all_categories' => 'Toutes les catégories', 'image_placeholder' => 'Image du produit indisponible',
        'categories' => 'Catégories', 'browse' => 'Découvrir la sélection', 'overview' => 'Guide de catégorie',
        'products' => 'Produits dans cette catégorie', 'product' => 'Fiche produit',
        'view' => 'Voir le produit', 'buy' => 'Voir chez VEVOR', 'none' => 'Aucun produit disponible dans cette catégorie pour le moment.',
        'source' => 'Les images, caractéristiques et informations proviennent du fournisseur. Vérifiez le prix et la disponibilité sur VEVOR.',
        'details' => 'Détails techniques', 'sku' => 'Référence',
        'customer' => 'Achats, livraison et retours', 'support_label' => 'Service client VEVOR',
        'service' => 'Les commandes et paiements sont effectués directement chez VEVOR. La livraison, les retours, les remboursements et le service après-vente sont gérés par VEVOR selon ses conditions.',
        'affiliate' => 'Information sur les liens affiliés', 'disclosure' => 'Distribuidor de Herramientas est un partenaire affilié indépendant, pas le vendeur. Un achat via nos liens peut nous rapporter une commission sans coût supplémentaire pour vous.',
        'editorial' => 'Contacter notre site', 'privacy' => 'Notre politique de confidentialité', 'back' => 'Voir les catégories',
    ),
);

if (!isset($markets[$market])) {
    return;
}
$t = $markets[$market];
$GLOBALS['dht_pilot_market_active'] = $market;
$GLOBALS['dht_frontend_language_tag'] = $t['lang'];
$dht_current_url = (function_exists('is_product_category') && is_product_category())
    ? get_term_link(get_queried_object()) : get_permalink(get_queried_object_id());
if (!is_wp_error($dht_current_url) && $dht_current_url) {
    $GLOBALS['dht_frontend_hreflang_url'] = esc_url_raw((string) $dht_current_url);
}
$is_category = function_exists('is_product_category') && is_product_category();
$is_landing = is_singular('page');
$landing_page = $is_landing ? get_queried_object() : null;
$term = $is_category ? get_queried_object() : null;
$product = !$is_category && !$is_landing && function_exists('wc_get_product') ? wc_get_product(get_queried_object_id()) : null;

if ($is_category && !($term instanceof WP_Term)) {
    return;
}
if ($is_landing && !($landing_page instanceof WP_Post)) {
    return;
}
if (!$is_category && !$is_landing && !($product instanceof WC_Product)) {
    return;
}
$landing_labels = array('US' => 'Project guide', 'DE' => 'Ratgeber', 'NL' => 'Keuzegids', 'FR' => 'Guide pratique');

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
        $host_by_market = array('US' => 'vevor.com', 'DE' => 'vevor.de', 'NL' => 'vevor.nl', 'FR' => 'vevor.fr');
        $market_for_url = isset($GLOBALS['dht_pilot_market_active']) ? (string) $GLOBALS['dht_pilot_market_active'] : '';
        $required_host = isset($host_by_market[$market_for_url]) ? $host_by_market[$market_for_url] : '';
        if ($required_host === '' || !in_array($host, array($required_host, 'www.' . $required_host), true)) {
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

$landing_categories = array();
if ($is_landing) {
    global $wpdb;
    $table = $wpdb->prefix . 'seo_relations';
    $linked_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT target_id FROM {$table}
          WHERE source_type = 'landing' AND source_id = %d
          AND target_type = 'product_cat' AND relation_type = 'landing_to_category'
          ORDER BY target_id ASC",
        (int) $landing_page->ID
    ));
    foreach ((array) $linked_ids as $linked_id) {
        $linked_term = get_term(absint($linked_id), 'product_cat');
        if ($linked_term instanceof WP_Term && dht_pilot_country_for_term($linked_term) === $market) {
            $landing_categories[(int) $linked_term->term_id] = $linked_term;
        }
    }
    $landing_categories = array_values($landing_categories);
}
$primary_category = $is_landing ? ($landing_categories[0] ?? null) : $term;
if (!$is_category && !$is_landing) {
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

// One stable, country-specific navigation on all regional category, landing
// and product pages. Links always resolve to real WooCommerce regional terms.
// US uses the native menu generated by TaxoCommerce Core, not a category tree.
$us_managed_menu_id = 0;
if ('US' === $market && class_exists('TaxoCommerce_Core_Menu', false)) {
    $us_managed_menu_id = absint(TaxoCommerce_Core_Menu::managed_menu_id('US', false));
}
$us_managed_menu = $us_managed_menu_id ? wp_get_nav_menu_object($us_managed_menu_id) : false;
if (!$us_managed_menu instanceof WP_Term || (string) $us_managed_menu->name !== 'TaxoCommerce - US') {
    $us_managed_menu_id = 0;
}

$country_navigation = dht_pilot_country_navigation( $market );
$navigation = (array) $country_navigation['featured'];
$navigation_roots = (array) $country_navigation['roots'];
$navigation_children = (array) $country_navigation['children'];
$navigation_market_root = $country_navigation['market_root'];

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
        $category_description = (string) term_description($term->term_id);
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
        if ($affiliate_url($item) === '') {
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
} elseif ($is_landing) {
    $category_excerpt = (string) $landing_page->post_excerpt;
    $category_description = (string) $landing_page->post_content;
    $category_ids = array_map(static function ($item) { return (int) $item->term_id; }, $landing_categories);
    $featured_raw = (string) get_post_meta($landing_page->ID, 'dht_pilot_featured_products', true);
    $featured_ids = array_filter(array_unique(array_map('absint', preg_split('/[,|\s]+/', $featured_raw))));

    if (!$featured_ids && $category_ids) {
        $featured_query = new WP_Query(array(
            'post_type' => 'product', 'post_status' => 'publish',
            'posts_per_page' => 24, 'no_found_rows' => true, 'fields' => 'ids',
            'tax_query' => array(array(
                'taxonomy' => 'product_cat', 'field' => 'term_id',
                'terms' => $category_ids, 'include_children' => true,
            )),
        ));
        $featured_ids = array_map('absint', (array) $featured_query->posts);
    }
    foreach ($featured_ids as $featured_id) {
        $item = wc_get_product($featured_id);
        if (!($item instanceof WC_Product) || get_post_status($featured_id) !== 'publish' || !$item->is_visible()) {
            continue;
        }
        $item_terms = get_the_terms($featured_id, 'product_cat');
        $belongs = false;
        $market_terms = array();
        foreach (is_array($item_terms) ? $item_terms : array() as $item_term) {
            $term_market = dht_pilot_country_for_term($item_term);
            if ($term_market !== '') {
                $market_terms[$term_market] = true;
            }
            $term_lineage = array_merge(
                array((int) $item_term->term_id),
                get_ancestors($item_term->term_id, 'product_cat', 'taxonomy')
            );
            if (array_intersect($term_lineage, $category_ids)) {
                $belongs = true;
            }
        }
        // Una pagina internacional no debe mostrar productos ambiguamente
        // asignados a varios mercados.
        if (count($market_terms) !== 1 || !isset($market_terms[$market])) {
            $belongs = false;
        }
        if (!$belongs || $affiliate_url($item) === '') {
            continue;
        }
        $category_items[] = $item;
        if ($hero_image === '') {
            $item_images = $product_images($item);
            $hero_image = !empty($item_images[0]) ? (string) $item_images[0] : '';
        }
        if (count($category_items) >= 9) {
            break;
        }
    }
} else {
    $urls = $product_images($product);
    $hero_image = isset($urls[0]) ? (string) $urls[0] : '';
}
// Keep the actual publisher name in the legal footer, without the Spanish
// market-specific WordPress site title on international pages.
$site_title = 'Distribuidor de Herramientas';
$site_privacy_url = get_privacy_policy_url();
$editorial_email = 'servicioacliente@distribuidordeherramientas.es';

$country_home_term = $navigation_market_root instanceof WP_Term
    ? $navigation_market_root
    : ( $navigation_roots[0] ?? $primary_category );
$main_url = $country_home_term instanceof WP_Term
    ? get_term_link( $country_home_term )
    : '';
if ( is_wp_error( $main_url ) || ! $main_url ) {
    // Avoid redirecting country navigation to the Spanish home page.
    $main_url = ! is_wp_error( $dht_current_url ) && $dht_current_url
        ? (string) $dht_current_url
        : home_url( '/' );
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
.dht-pilot-market-actions{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap}
.dht-pilot-market-actions .dht-country-switcher{display:flex;align-items:center;gap:7px}
.dht-pilot-market-actions .dht-country-switcher__link{min-width:36px;min-height:36px;display:inline-flex;align-items:center;justify-content:center;padding:4px;background:#f7f9fc;border:1px solid #dfe5ee;border-radius:9px;font-size:24px;line-height:1;text-decoration:none}
.dht-pilot-market-actions .dht-country-switcher__link:hover,.dht-pilot-market-actions .dht-country-switcher__link:focus-visible{background:#eaf1fc;border-color:#9db9e7}
@media(max-width:760px){.dht-pilot-market-actions{gap:8px}.dht-pilot-market-actions .dht-country-switcher__link{font-size:22px;min-width:34px;min-height:34px}}
.dht-pilot-flag{font-size:24px;vertical-align:middle;line-height:1}
.dht-pilot-nav{padding:4px 0 18px;display:block}
.dht-pilot-nav-heading{display:flex;align-items:center;flex-wrap:wrap;gap:9px;margin:0 0 11px;font-size:14px;font-weight:700;color:#233956}
.dht-pilot-nav-flag{font-size:19px;line-height:1}
.dht-pilot-nav-home{font-size:12px;font-weight:600;text-decoration:none;color:#1c5aa4!important;margin-left:auto}
.dht-pilot-nav-home:hover{text-decoration:underline}
.dht-pilot-menu-list{list-style:none;margin:0;padding:0}
.dht-pilot-menu-list li{min-width:0}
.dht-pilot-menu-list a{display:block;padding:7px 9px;text-decoration:none;overflow-wrap:anywhere;color:#173454;font-size:13px;line-height:1.35;border-radius:7px}
.dht-pilot-menu-list a:hover,.dht-pilot-menu-list a:focus-visible{background:#eaf1fc;text-decoration:underline}
.dht-pilot-menu-list a[aria-current="page"]{background:#eaf1fc;font-weight:750}
.dht-pilot-menu-list--depth-0{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}
.dht-pilot-menu-list--depth-0>li{border:1px solid #dfe5ee;border-radius:9px;padding:4px;background:#f7f9fc}
.dht-pilot-menu-list--depth-0>li>a{font-weight:700}
.dht-pilot-menu-list:not(.dht-pilot-menu-list--depth-0){margin:0 3px 3px;padding-left:7px;border-left:2px solid #dce7f7}
.dht-pilot-menu-list:not(.dht-pilot-menu-list--depth-0) a{padding:5px 7px;font-weight:450;font-size:12px}
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
@media(max-width:760px){.dht-pilot-header-inner{align-items:flex-start}.dht-pilot-brand span{max-width:280px}.dht-pilot-hero{grid-template-columns:1fr;padding:20px;gap:20px}.dht-pilot-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.dht-pilot-section{padding:18px}.dht-pilot-footer-grid{grid-template-columns:1fr;gap:18px}.dht-pilot-menu-list--depth-0{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:480px){.dht-pilot-grid{grid-template-columns:1fr}.dht-pilot-market{white-space:normal}.dht-pilot-brand strong{font-size:20px}.dht-pilot-header-inner{gap:10px}.dht-pilot-menu-list--depth-0{grid-template-columns:1fr}}
</style>
</head>
<body <?php body_class('dht-pilot-body'); ?>>
<?php wp_body_open(); ?>
<div class="dht-pilot-page">
<header class="dht-pilot-header">
    <div class="dht-pilot-container">
        <div class="dht-pilot-header-inner">
            <a class="dht-pilot-brand" href="<?php echo esc_url($main_url); ?>">
                <strong><?php echo esc_html($t['brand_title']); ?></strong>
                <span><?php echo esc_html($t['tagline']); ?></span>
            </a>
            <div class="dht-pilot-market-actions">
                <span class="dht-pilot-market"><span class="dht-pilot-flag" aria-hidden="true"><?php echo esc_html($t['flag']); ?></span> <?php echo esc_html($t['country']); ?></span>
                <?php dht_pilot_render_country_switcher( $market ); ?>
            </div>
        </div>
        <?php if ($navigation_roots || $us_managed_menu_id) : ?>
        <!-- Fixed pilot navigation: real category URLs, identical across this country's pages. -->
        <nav class="dht-pilot-nav" aria-label="<?php echo esc_attr($t['categories'] . ' · ' . $t['country']); ?>">
            <div class="dht-pilot-nav-heading">
                <span class="dht-pilot-nav-flag" aria-hidden="true"><?php echo esc_html($t['flag']); ?></span>
                <span><?php echo esc_html($t['all_categories']); ?></span>
                <a class="dht-pilot-nav-home" href="<?php echo esc_url($main_url); ?>"><?php echo esc_html($t['country']); ?></a>
            </div>
            <?php if ('US' === $market && $us_managed_menu_id) : ?>
                <?php wp_nav_menu(array(
                    'menu' => $us_managed_menu_id,
                    'theme_location' => '',
                    'container' => false,
                    'menu_class' => 'dht-pilot-menu-list dht-pilot-menu-list--depth-0',
                    'fallback_cb' => false,
                    'depth' => 0,
                )); ?>
            <?php elseif ('US' !== $market) : ?>
                <?php dht_pilot_render_category_menu_tree($navigation_roots, $navigation_children); ?>
            <?php endif; ?>
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
            <span class="dht-pilot-kicker"><?php echo esc_html($is_landing ? $landing_labels[$market] : ($is_category ? $t['overview'] : $t['product'])); ?></span>
            <h1 id="dht-pilot-title"><?php echo esc_html($is_landing ? get_the_title($landing_page->ID) : ($is_category ? $term->name : $product->get_name())); ?></h1>
            <div class="dht-pilot-rich">
                <?php if (($is_category || $is_landing) && $category_excerpt !== '') : ?>
                    <?php echo wp_kses_post(wpautop($category_excerpt)); ?>
                <?php elseif (!$is_category && !$is_landing && $product->get_short_description() !== '') : ?>
                    <?php echo wp_kses_post(wpautop($product->get_short_description())); ?>
                <?php endif; ?>
            </div>
            <?php if (!$is_category && !$is_landing) :
                $link = $affiliate_url($product);
                if ($link !== '') : ?>
                    <a class="dht-pilot-button" href="<?php echo esc_url($link); ?>" target="_blank" rel="sponsored noopener noreferrer"><?php echo esc_html($t['buy']); ?> &rarr;</a>
                <?php endif;
            endif; ?>
        </div>
        <div class="dht-pilot-media">
            <?php if ($hero_image !== '') : ?>
                <img src="<?php echo esc_url($hero_image); ?>" alt="<?php echo esc_attr($is_landing ? get_the_title($landing_page->ID) : ($is_category ? $term->name : $product->get_name())); ?>" decoding="async" fetchpriority="high">
            <?php else : ?>
                <span class="dht-pilot-placeholder"><?php echo esc_html($t['image_placeholder']); ?></span>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($is_category || $is_landing) : ?>
        <?php if (trim(wp_strip_all_tags($category_description)) !== '') : ?>
        <section class="dht-pilot-section" aria-labelledby="dht-pilot-description">
            <h2 id="dht-pilot-description"><?php echo esc_html($is_landing ? $landing_labels[$market] : $t['overview']); ?></h2>
            <div class="dht-pilot-rich"><?php echo wp_kses_post(wpautop($category_description)); ?></div>
        </section>
        <?php endif; ?>

        <?php if ($is_landing && $landing_categories) : ?>
        <section class="dht-pilot-section" aria-labelledby="dht-pilot-landing-categories">
            <h2 id="dht-pilot-landing-categories"><?php echo esc_html($t['browse']); ?></h2>
            <div class="dht-pilot-grid">
                <?php foreach ($landing_categories as $related_term) :
                    $related_url = get_term_link($related_term);
                    if (is_wp_error($related_url)) { continue; } ?>
                <article class="dht-pilot-card">
                    <div class="dht-pilot-card-body">
                        <h3><?php echo esc_html($related_term->name); ?></h3>
                        <a class="dht-pilot-button" href="<?php echo esc_url($related_url); ?>"><?php echo esc_html($t['categories']); ?> &rarr;</a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
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
