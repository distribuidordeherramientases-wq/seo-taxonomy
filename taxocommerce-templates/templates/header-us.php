<?php
/**
 * United States storefront header for US product and category templates.
 *
 * This header deliberately does not include the Spanish header.php, its
 * GeneratePress navigation location, shopping cart or Spanish support links.
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';
require_once __DIR__ . '/template-pilot-country-switcher.php';

$us_menu_id = 0;
if (class_exists('TaxoCommerce_Core_Menu', false) && is_callable(array('TaxoCommerce_Core_Menu', 'managed_menu_id'))) {
    $us_menu_id = absint(TaxoCommerce_Core_Menu::managed_menu_id('US', false));
}
if (!$us_menu_id) {
    $us_menu_by_name = wp_get_nav_menu_object('TaxoCommerce - US');
    if ($us_menu_by_name instanceof WP_Term) {
        $us_menu_id = (int) $us_menu_by_name->term_id;
    }
}

// Do not trust an old stored ID: the selected native menu MUST belong to US.
$us_menu_object = $us_menu_id ? wp_get_nav_menu_object($us_menu_id) : false;
if (!($us_menu_object instanceof WP_Term) || (string) $us_menu_object->name !== 'TaxoCommerce - US') {
    $us_menu_id = 0;
}

// Prefer the established US market landing; never link the US brand to ES home.
$us_country_urls = dht_pilot_country_switcher_urls();
$us_home_url = !empty($us_country_urls['US']) ? (string) $us_country_urls['US'] : '';
if ($us_home_url === '' && $us_menu_id) {
    $us_menu_items = wp_get_nav_menu_items($us_menu_id);
    foreach (is_array($us_menu_items) ? $us_menu_items : array() as $us_item) {
        if ((int) $us_item->menu_item_parent === 0 && !empty($us_item->url)) {
            $us_home_url = (string) $us_item->url;
            break;
        }
    }
}
if ($us_home_url === '' && is_tax('product_cat')) {
    $us_current_term_url = get_term_link(get_queried_object());
    if (!is_wp_error($us_current_term_url)) {
        $us_home_url = (string) $us_current_term_url;
    }
}
if ($us_home_url === '' && is_singular('product')) {
    $us_home_url = (string) get_permalink(get_queried_object_id());
}

// The existing US detail layouts need their shared product/category CSS.
wp_enqueue_style('dht-template-styles', TCT_URL . 'templates/styles-template.css', array(), TCT_VERSION);
wp_enqueue_style('tct-us-market', TCT_URL . 'templates/assets/market-us.css', array('dht-template-styles'), TCT_VERSION);

$us_nav_html = $us_menu_id ? wp_nav_menu(array(
    'menu'           => $us_menu_id,
    'theme_location' => '',
    'container'      => false,
    'menu_class'     => 'dht-us-nav-list',
    'fallback_cb'    => false,
    'echo'           => false,
    'depth'          => 0,
)) : '';
?>
<!doctype html>
<html lang="en-US">
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php dht_template_render_wp_head(); ?>
</head>
<body <?php body_class('dht-template-body dht-us-market'); ?>>
<?php wp_body_open(); ?>
<header class="dht-us-header" role="banner">
    <div class="dht-us-header-inner">
        <div class="dht-us-header-top">
            <div class="dht-us-brand">
                <?php if ($us_home_url !== '') : ?>
                    <a href="<?php echo esc_url($us_home_url); ?>" rel="home">Professional Tools &amp; Equipment</a>
                <?php else : ?>
                    <strong>Professional Tools &amp; Equipment</strong>
                <?php endif; ?>
                <span>Independent product guides · United States</span>
            </div>
            <div class="dht-us-market-actions">
                <span class="dht-us-market-label">United States</span>
                <?php dht_pilot_render_country_switcher('US'); ?>
            </div>
        </div>
        <?php if ($us_nav_html) : ?>
            <nav class="dht-us-nav" aria-label="United States product categories">
                <div class="dht-us-nav-heading">Browse categories</div>
                <?php echo $us_nav_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nav_menu builds escaped menu HTML. ?>
            </nav>
        <?php endif; ?>
    </div>
</header>
