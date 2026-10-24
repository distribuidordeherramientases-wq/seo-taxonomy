<?php
$root = dirname(__DIR__, 2);

$search = file_get_contents($root . '/includes/seo-search.php');
$dep = file_get_contents($root . '/includes/dependiente/v3/class-dependiente-v3-frontend.php');

$templates = array(
    $root . '/seo-system/templates/template-shop-desktop.php',
    $root . '/seo-system/templates/template-shop-mobile.php',
    $root . '/taxocommerce-templates/templates/template-shop-desktop.php',
    $root . '/taxocommerce-templates/templates/template-shop-mobile.php',
);

$checks = array(
    strpos($search, "add_shortcode('taxocommerce_search'") !== false,
    strpos($search, "add_shortcode('taxocommerce_advanced_search'") !== false,
    strpos($dep, "add_shortcode('taxocommerce_dependiente_productos'") !== false,
    strpos($dep, "add_shortcode('taxocommerce_dependiente'") !== false,
    strpos($dep, "migrate_legacy_shortcode_markup") !== false,
    strpos($dep, "'[dependiente_productos'") !== false,
    strpos($dep, "'[taxocommerce_dependiente_productos'") !== false,
    strpos($dep, "self::repair_page_content($configured);") !== false,
);

foreach ($templates as $path) {
    $source = file_get_contents($path);
    $canonical_search = strpos($source, "shortcode_exists('taxocommerce_search')");
    $legacy_search = strpos($source, "shortcode_exists('seo_search')");
    $canonical_filters = strpos($source, "shortcode_exists('taxocommerce_advanced_search')");
    $legacy_filters = strpos($source, "shortcode_exists('seo_search_filters')");

    $checks[] = false !== $canonical_search;
    $checks[] = false !== $canonical_filters;
    $checks[] = false === $legacy_search || $canonical_search < $legacy_search;
    $checks[] = false === $legacy_filters || $canonical_filters < $legacy_filters;
    $checks[] = strpos($source, "[taxocommerce_search placeholder=") !== false;
    $checks[] = strpos($source, "[taxocommerce_advanced_search class=") !== false;
}

foreach ($checks as $index => $ok) {
    if (!$ok) {
        fwrite(STDERR, 'FAIL public shortcode compatibility guard ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo "Public shortcode compatibility guards passed.\n";
