<?php
/**
 * Validates bundled GNU gettext MO catalogs without WordPress or write access.
 * This file is also included by the existing Logistics regression suite.
 *
 * @package TaxoCommerce_Logistica
 */
$tc_logistica_dir = dirname( __DIR__ ) . '/languages/';
$tc_logistica_examples = array(
    'es_ES' => 'No tienes permisos para acceder a esta página.',
    'en_US' => 'You do not have permission to access this page.',
    'fr_FR' => "Vous n'avez pas l'autorisation d'accéder à cette page.",
    'de_DE' => 'Du hast keine Berechtigung, diese Seite aufzurufen.',
    'nl_NL' => 'Je hebt geen toestemming om deze pagina te openen.',
);
foreach ( $tc_logistica_examples as $tc_locale => $tc_expected ) {
    $tc_mo_path = $tc_logistica_dir . 'taxocommerce-logistica-' . $tc_locale . '.mo';
    $tc_mo = file_get_contents( $tc_mo_path );
    if ( false === $tc_mo || strlen( $tc_mo ) < 28 ) {
        throw new RuntimeException( 'Missing or empty Logistics MO: ' . $tc_locale );
    }
    $tc_magic = unpack( 'V', substr( $tc_mo, 0, 4 ) )[1];
    $tc_meta = unpack( 'Vrevision/Vcount/Voriginal/Vtranslation', substr( $tc_mo, 4, 16 ) );
    if ( 0x950412de !== $tc_magic || 142 !== $tc_meta['count'] ) {
        throw new RuntimeException( 'Invalid Logistics GNU MO header: ' . $tc_locale );
    }
    $tc_found = false;
    for ( $tc_i = 0; $tc_i < $tc_meta['count']; ++$tc_i ) {
        $tc_original = unpack( 'Vlength/Voffset', substr( $tc_mo, $tc_meta['original'] + $tc_i * 8, 8 ) );
        $tc_translated = unpack( 'Vlength/Voffset', substr( $tc_mo, $tc_meta['translation'] + $tc_i * 8, 8 ) );
        if ( $tc_original['offset'] + $tc_original['length'] > strlen( $tc_mo ) ||
             $tc_translated['offset'] + $tc_translated['length'] > strlen( $tc_mo ) ) {
            throw new RuntimeException( 'Out-of-bounds Logistics MO: ' . $tc_locale );
        }
        $tc_key = substr( $tc_mo, $tc_original['offset'], $tc_original['length'] );
        $tc_value = substr( $tc_mo, $tc_translated['offset'], $tc_translated['length'] );
        if ( '' !== $tc_key && '' === $tc_value ) {
            throw new RuntimeException( 'Empty Logistics translation in ' . $tc_locale );
        }
        if ( 'No tienes permisos para acceder a esta página.' === $tc_key ) {
            $tc_found = $tc_expected === $tc_value;
        }
    }
    if ( ! $tc_found ) {
        throw new RuntimeException( 'Logistics permission message not translated for ' . $tc_locale );
    }
}
echo 'Logistics gettext catalogs validated.' . PHP_EOL;
