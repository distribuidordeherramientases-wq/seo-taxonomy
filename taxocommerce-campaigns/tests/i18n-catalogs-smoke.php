<?php
/**
 * Validate compiled gettext catalogues for all supported locales.
 *
 * Pure CLI test: no WordPress installation or DB writes.
 * @package TaxoCommerceCampaigns
 */
if ( 'cli' !== PHP_SAPI ) {
    exit( 1 );
}

$root = dirname( __DIR__ );
$labels = array(
    'es_ES' => 'Campañas de marketing',
    'en_US' => 'Marketing campaigns',
    'fr_FR' => 'Campagnes marketing',
    'de_DE' => 'Marketingkampagnen',
    'nl_NL' => 'Marketingcampagnes',
);
foreach ( $labels as $locale => $expected ) {
    $path = $root . '/languages/taxocommerce-campaigns-' . $locale . '.mo';
    $bytes = file_get_contents( $path );
    if ( false === $bytes || strlen( $bytes ) < 5000 ) {
        fwrite( STDERR, "FAIL: missing or empty MO for $locale\n" );
        exit( 1 );
    }
    $hdr = unpack( 'Vmagic/Vrevision/Vcount/Voriginal/Vtranslation/Vhash_size/Vhash_offset', substr( $bytes, 0, 28 ) );
    if ( 0x950412de !== $hdr['magic'] || $hdr['count'] < 151 ) {
        fwrite( STDERR, "FAIL: invalid MO header for $locale\n" );
        exit( 1 );
    }
    $strings = array();
    for ( $i = 0; $i < $hdr['count']; ++$i ) {
        $orig = unpack( 'Vlength/Voffset', substr( $bytes, $hdr['original'] + 8 * $i, 8 ) );
        $trans = unpack( 'Vlength/Voffset', substr( $bytes, $hdr['translation'] + 8 * $i, 8 ) );
        $key = substr( $bytes, $orig['offset'], $orig['length'] );
        $value = substr( $bytes, $trans['offset'], $trans['length'] );
        $strings[ $key ] = $value;
    }
    if ( ! isset( $strings['Marketing campaigns'] ) || $expected !== $strings['Marketing campaigns'] ) {
        fwrite( STDERR, "FAIL: wrong translated menu label for $locale\n" );
        exit( 1 );
    }
    foreach ( $strings as $key => $value ) {
        if ( '' !== $key && '' === $value ) {
            fwrite( STDERR, "FAIL: untranslated MO entry in $locale\n" );
            exit( 1 );
        }
    }
}
$main = file_get_contents( $root . '/taxocommerce-campaigns.php' );
$readme = file_get_contents( $root . '/readme.txt' );
preg_match( '/^ \* Version: (\d+\.\d+\.\d+)$/m', $main, $matches );
$version = $matches[1] ?? '';
if ( '' === $version ||
     false === strpos( $main, "define('TAXOCOMMERCE_CAMPAIGNS_VERSION','" . $version . "')" ) ||
     false === strpos( $readme, 'Stable tag: ' . $version ) ) {
    fwrite( STDERR, "FAIL: Campaigns release versions differ\n" );
    exit( 1 );
}
echo "PASS: Campaigns five gettext catalogues, marketing menu translations, versions\n";
