<?php
/**
 * PHP-only regression for L9 query generation from sealed facts.
 * Run with: php taxocommerce-dependiente/tests/l9-query-regression.php
 *
 * These tests do not require WordPress, live catalog data or an HTTP request.
 */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $value ) {
        $value = mb_strtolower( (string) $value, 'UTF-8' );
        $value = strtr( $value, array(
            'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ñ'=>'n', 'ü'=>'u'
        ) );
        return trim( preg_replace( '/[^a-z0-9]+/u', '-', $value ), '-' );
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) {
        return trim( strip_tags( (string) $value ) );
    }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $value ) {
        return strip_tags( (string) $value );
    }
}
require_once dirname( __DIR__ ) . '/includes/class-tcd-sources.php';

function tcd_l9_assert( $condition, $name ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAILED: " . $name . PHP_EOL );
        exit( 1 );
    }
}

$archived = array(
    'customer_need'=>'Necesito Kit de herramientas a batería, Equipamiento',
    'categories_detail'=>array(
        array( 'name'=>'Nuevos productos', 'taxonomy'=>'product_cat' ),
        array( 'name'=>'Prensadoras de tuberías', 'taxonomy'=>'product_cat' ),
    ),
    'vocabulary'=>array(
        'subtipo'=>array( array( 'label'=>'Kit de herramientas a batería' ) ),
        'tipo'=>array( array( 'label'=>'Prensadoras de tuberías' ) ),
        'aplicacion'=>array( array( 'label'=>'Tuberías y drenaje' ) ),
    ),
    'features'=>array( 'attribute_labels'=>array(
        'Tensión'=>array( '18 V' ), 'Capacidad'=>array( '2,0 Ah' ),
    ) ),
);
$title = 'Kit para Engarzar Tuberías Eléctricas, 2 Baterías de 18 V 2,0 Ah';
$need = TCD_Sources::customer_need_from_facts( $archived, $title );
tcd_l9_assert( 0 === strpos( $need['query'], 'Busco ' ), 'legacy query replaced' );
tcd_l9_assert( false === stripos( $need['query'], 'Nuevos productos' ), 'temporary bucket ignored' );
tcd_l9_assert( false !== stripos( $need['query'], 'Prensadoras de tuberías' ), 'relevant family preferred to generic kit' );
tcd_l9_assert( false !== stripos( $need['query'], '18 V' ), 'specific voltage kept' );
tcd_l9_assert( 'product' === $need['scope'], 'specific needs require exact product' );

// A family request must never require a random product ID; red colour alone
// is not a model, dimension, capacity or product-unique discriminator.
$family = TCD_Sources::customer_need_from_facts( array(
    'customer_need'=>'Necesito zapatos genéricos para equipamiento',
    'categories_detail'=>array( array( 'name'=>'Calzado de seguridad', 'taxonomy'=>'product_cat' ) ),
    'vocabulary'=>array( 'tipo'=>array( array( 'label'=>'Calzado de seguridad' ) ) ),
    'features'=>array( 'attribute_labels'=>array( 'Color'=>array( 'rojo' ) ) ),
), 'Zapatos de trabajo rojos con puntera' );
tcd_l9_assert( 'family' === $family['scope'], 'colour-only is family-level' );
tcd_l9_assert( 0 === strpos( $family['query'], 'Busco ' ), 'family language rebuilt' );

$size = TCD_Sources::customer_need_from_facts( array(
    'vocabulary'=>array( 'tipo'=>array( array( 'label'=>'Cubiertas solares para piscinas' ) ) ),
    'features'=>array(),
), 'Cubierta solar piscina 5,49 m de diámetro' );
tcd_l9_assert( 'product' === $size['scope'], 'dimension makes a specific request' );
tcd_l9_assert( false !== stripos( $size['query'], '5,49 m' ), 'dimension extracted without memorising title' );

$no_product = TCD_Sources::customer_need_from_facts( array(
    'customer_need'=>'Necesito Herramienta genérica',
    'vocabulary'=>array( 'tipo'=>array( array( 'label'=>'Candados' ) ) ),
    'features'=>array(),
), 'Candado de cadena integrado para motocicleta' );
tcd_l9_assert( 'family' === $no_product['scope'], 'no identifier means family' );
tcd_l9_assert( false === stripos( $no_product['query'], 'Necesito Herramienta' ), 'obsolete stored need unused' );
echo "L9 query regression passed.\n";
