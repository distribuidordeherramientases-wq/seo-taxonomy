<?php
/**
 * Receta web BRICO DEPOT para SEO System.
 * Genera su propio CSV estandar interno mediante crawler-queue.php.
 * Version 0.1.0.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/common-runtime.php';

add_filter( 'seo_supplier_crawl_recipes', static function ( $recipes ) {
    if ( ! is_array( $recipes ) ) { $recipes = []; }
    $recipes['bricodepot'] = [
        'id' => 'bricodepot',
        'label' => 'Brico Depot - solo venta directa',
        'provider' => 'BRICO DEPOT',
        'version' => '0.1.0',
        'base_url' => 'https://www.bricodepot.es/',
        'description' => 'Descubrimiento web respetuoso. El resultado pasa a un CSV estandar interno y de ahi al importador comun.',
        'allowed_hosts' => [ 'www.bricodepot.es', 'bricodepot.es' ],
        'respect_robots' => true,
        'min_delay' => 60,
        'initial_delay' => 90,
        'max_delay' => 3600,
        'refresh_hours' => 72,
        'revisit_days' => 21,
        'max_attempts' => 4,
        'csv_flush_rows' => 25,
        'csv_flush_interval' => 900,
        'vat_percent' => 21,
        'seed_urls' => [ 'https://www.bricodepot.es/herramientas', 'https://www.bricodepot.es/herramientas/electricas', 'https://www.bricodepot.es/herramientas/herramientas-de-taller', 'https://www.bricodepot.es/herramientas/accesorios-herramientas-electricas', 'https://www.bricodepot.es/catalogo/herramientas' ],
        'keep_query' => [ 'p' ],
        'product_patterns' => [ '#-(?:[0-9]{8,14}|[a-f0-9]{8})$#i' ],
        'external_id_patterns' => [ '#-([0-9]{8,14}|[a-f0-9]{8})$#i' ],
        'category_patterns' => [ '#^/herramientas(?:/.*)?$#i', '#^/catalogo/herramientas(?:/.*)?$#i', '#^/v/[^/]+$#i' ],
        'sitewide_catalog' => false,
        'sitewide_prefixes' => [  ],
        'category_prefix_strict' => true,
        'keyword_category_prefixes' => [ '/herramientas', '/catalogo/herramientas', '/v/' ],
        'category_keywords' => [ 'herramienta', 'taladro', 'atornillador', 'amoladora', 'radial', 'sierra', 'lijadora', 'fresadora', 'martillo', 'perforador', 'bateria', 'cargador', 'accesorio', 'broca', 'disco', 'corte', 'medicion', 'laser', 'nivel', 'soldadura', 'compresor', 'aspirador', 'clavadora', 'grapadora', 'llave', 'destornillador', 'alicate', 'tenaza', 'cutter', 'cuchillo', 'kit', 'maletin', 'caja', 'taller', 'maquina', 'maquinaria', 'pulidora', 'cepillo', 'multiherramienta', 'remachadora', 'pistola', 'mezcladora', 'decapador', 'motosierra', 'cortasetos', 'desbrozadora', 'hidrolimpiadora', 'generador', 'proteccion laboral', 'vestuario laboral', 'almacenaje' ],
        'product_card_heuristic' => true,
        'max_enqueue_per_page' => 400,
        'seller_required' => true,
        'seller_names' => [ 'Brico Depot' ],
        'canonicalize_callback' => [ 'SEO_Supplier_Web_Recipe_Runtime', 'canonicalize' ],
        'classify_url_callback' => [ 'SEO_Supplier_Web_Recipe_Runtime', 'classify' ],
        'parse_page_callback' => [ 'SEO_Supplier_Web_Recipe_Runtime', 'parse_page' ],
    ];
    return $recipes;
} );
