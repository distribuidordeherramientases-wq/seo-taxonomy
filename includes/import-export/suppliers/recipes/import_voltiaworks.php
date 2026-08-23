<?php
/**
 * Receta web VOLTIA WORKS para SEO System.
 * Genera su propio CSV estandar interno mediante crawler-queue.php.
 * Version 0.1.0.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/common-runtime.php';

add_filter( 'seo_supplier_crawl_recipes', static function ( $recipes ) {
    if ( ! is_array( $recipes ) ) { $recipes = []; }
    $recipes['voltiaworks'] = [
        'id' => 'voltiaworks',
        'label' => 'Voltia Works - catalogo web',
        'provider' => 'VOLTIA WORKS',
        'version' => '0.1.0',
        'base_url' => 'https://voltiaworks.com/',
        'description' => 'Descubrimiento web respetuoso. El resultado pasa a un CSV estandar interno y de ahi al importador comun.',
        'allowed_hosts' => [ 'voltiaworks.com', 'www.voltiaworks.com' ],
        'respect_robots' => true,
        'min_delay' => 45,
        'initial_delay' => 75,
        'max_delay' => 1800,
        'refresh_hours' => 72,
        'revisit_days' => 21,
        'max_attempts' => 4,
        'csv_flush_rows' => 25,
        'csv_flush_interval' => 900,
        'vat_percent' => 21,
        'seed_urls' => [ 'https://voltiaworks.com/2-inicio', 'https://voltiaworks.com/177-herramientas-electricas', 'https://voltiaworks.com/147-herramientas-manuales', 'https://voltiaworks.com/33-taladros' ],
        'keep_query' => [ 'page', 'order' ],
        'product_patterns' => [ '#/[0-9]+-[^/]+\.html$#i' ],
        'external_id_patterns' => [ '#/([0-9]+)-[^/]+\.html$#i' ],
        'category_patterns' => [ '#^/[0-9]+-[^/]+$#i' ],
        'sitewide_catalog' => false,
        'sitewide_prefixes' => [  ],
        'category_prefix_strict' => false,
        'keyword_category_prefixes' => [ '/' ],
        'category_keywords' => [ 'herramienta', 'taladro', 'atornillador', 'amoladora', 'radial', 'sierra', 'lijadora', 'fresadora', 'martillo', 'perforador', 'bateria', 'cargador', 'accesorio', 'broca', 'disco', 'corte', 'medicion', 'laser', 'nivel', 'soldadura', 'compresor', 'aspirador', 'clavadora', 'grapadora', 'llave', 'destornillador', 'alicate', 'tenaza', 'cutter', 'cuchillo', 'kit', 'maletin', 'caja', 'taller', 'maquina', 'maquinaria', 'pulidora', 'cepillo', 'multiherramienta', 'remachadora', 'pistola', 'mezcladora', 'decapador', 'motosierra', 'cortasetos', 'desbrozadora', 'hidrolimpiadora', 'generador', 'proteccion laboral', 'vestuario laboral', 'almacenaje' ],
        'product_card_heuristic' => true,
        'max_enqueue_per_page' => 400,
        'seller_required' => false,
        'canonicalize_callback' => [ 'SEO_Supplier_Web_Recipe_Runtime', 'canonicalize' ],
        'classify_url_callback' => [ 'SEO_Supplier_Web_Recipe_Runtime', 'classify' ],
        'parse_page_callback' => [ 'SEO_Supplier_Web_Recipe_Runtime', 'parse_page' ],
    ];
    return $recipes;
} );
