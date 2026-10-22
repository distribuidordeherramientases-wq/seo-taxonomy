<?php
/** Router de producto VEVOR Francia: solo afiliacion, sin carrito espanol. */
defined( 'ABSPATH' ) || exit;
$dht_pilot_market = 'FR';
$dht_pilot_kind = 'product';
require __DIR__ . ( wp_is_mobile() ? '/template-product-fr-mobile.php' : '/template-product-fr-desktop.php' );
