<?php
/**
 * Receta web LEROY MERLIN para SEO System.
 * Genera su propio CSV estandar interno mediante crawler-queue.php.
 * Version 0.1.0.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/common-runtime.php';

add_filter( 'seo_supplier_crawl_recipes', static function ( $recipes ) {
    if ( ! is_array( $recipes ) ) { $recipes = []; }
    $recipes['leroymerlin'] = [
        'id' => 'leroymerlin',
        'label' => 'Leroy Merlin - solo venta directa',
        'provider' => 'LEROY MERLIN',
        'version' => '0.1.0',
        'base_url' => 'https://www.leroymerlin.es/',
        'description' => 'Descubrimiento web respetuoso. El resultado pasa a un CSV estandar interno y de ahi al importador comun.',
        'allowed_hosts' => [ 'www.leroymerlin.es', 'leroymerlin.es' ],
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
        'seed_urls' => [ 'https://www.leroymerlin.es/productos/herramientas/', 'https://www.leroymerlin.es/productos/herramientas/herramientas-electricas-portatiles/', 'https://www.leroymerlin.es/productos/herramientas/herramientas-de-mano/' ],
        'keep_query' => [ 'p' ],
        'product_patterns' => [ '#^/productos/.+-[0-9]{6,}\.html$#i' ],
        'external_id_patterns' => [ '#-([0-9]{6,})\.html$#i' ],
        'category_patterns' => [ '#^/productos/herramientas(?:/.*)?$#i' ],
        'sitewide_catalog' => false,
        'sitewide_prefixes' => [  ],
        'category_prefix_strict' => true,
        'keyword_category_prefixes' => [ '/productos/herramientas/' ],
        'category_keywords' => [ 'herramienta', 'taladro', 'atornillador', 'amoladora', 'radial', 'sierra', 'lijadora', 'fresadora', 'martillo', 'perforador', 'bateria', 'cargador', 'accesorio', 'broca', 'disco', 'corte', 'medicion', 'laser', 'nivel', 'soldadura', 'compresor', 'aspirador', 'clavadora', 'grapadora', 'llave', 'destornillador', 'alicate', 'tenaza', 'cutter', 'cuchillo', 'kit', 'maletin', 'caja', 'taller', 'maquina', 'maquinaria', 'pulidora', 'cepillo', 'multiherramienta', 'remachadora', 'pistola', 'mezcladora', 'decapador', 'motosierra', 'cortasetos', 'desbrozadora', 'hidrolimpiadora', 'generador', 'proteccion laboral', 'vestuario laboral', 'almacenaje' ],
        'product_card_heuristic' => true,
        'max_enqueue_per_page' => 400,
        'seller_required' => true,
        'seller_names' => [ 'LEROY MERLIN', 'Leroy Merlin' ],
        'canonicalize_callback' => [ 'SEO_Supplier_Web_Recipe_Runtime', 'canonicalize' ],
        'classify_url_callback' => [ 'SEO_Supplier_Web_Recipe_Runtime', 'classify' ],
        'parse_page_callback' => [ 'SEO_Supplier_Web_Recipe_Runtime', 'parse_page' ],
    ];
    return $recipes;
} );
