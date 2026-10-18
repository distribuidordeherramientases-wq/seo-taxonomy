<?php
/** Router de categoria VEVOR Francia: dispositivo movil/escritorio. */
defined( 'ABSPATH' ) || exit;
$dht_pilot_market = 'FR';
$dht_pilot_kind = 'category';
require __DIR__ . ( wp_is_mobile() ? '/template-category-fr-mobile.php' : '/template-category-fr-desktop.php' );
