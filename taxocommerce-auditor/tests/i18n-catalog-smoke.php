<?php
/**
 * Verify native WordPress GNU MO catalogs for TaxoCommerce Auditor.
 *
 * Offline regression: no WordPress bootstrap or network requests.
 * This file is deliberately excluded from the installable ZIP.
 */
$tc_auditor_root = dirname( __DIR__ );
$tc_auditor_domain = 'taxocommerce-auditor';
$tc_auditor_source = $tc_auditor_root . '/languages/' . $tc_auditor_domain . '.pot';
if ( ! is_readable( $tc_auditor_source ) ) {
    fwrite( STDERR, "Missing Auditor POT\n" );
    exit( 1 );
}
$tc_auditor_pot = file_get_contents( $tc_auditor_source );
$tc_auditor_entries = preg_match_all( '/^msgid /m', $tc_auditor_pot );
if ( $tc_auditor_entries < 150 ) {
    fwrite( STDERR, "Auditor POT is unexpectedly incomplete\n" );
    exit( 1 );
}

foreach ( array( 'es_ES', 'en_US', 'fr_FR', 'de_DE', 'nl_NL' ) as $locale ) {
    $stem = $tc_auditor_root . '/languages/' . $tc_auditor_domain . '-' . $locale;
    if ( ! is_readable( $stem . '.po' ) || ! is_readable( $stem . '.mo' ) ) {
        fwrite( STDERR, "Missing Auditor locale: {$locale}\n" );
        exit( 1 );
    }
    $po = file_get_contents( $stem . '.po' );
    if ( preg_match_all( '/^msgid /m', $po ) !== $tc_auditor_entries ) {
        fwrite( STDERR, "Stale Auditor PO: {$locale}\n" );
        exit( 1 );
    }
    $bytes = file_get_contents( $stem . '.mo' );
    if ( strlen( $bytes ) < 28 ) {
        fwrite( STDERR, "Truncated Auditor MO: {$locale}\n" );
        exit( 1 );
    }
    $magic = unpack( 'V', substr( $bytes, 0, 4 ) )[1];
    if ( 0x950412de !== $magic ) {
        fwrite( STDERR, "Invalid Auditor MO magic: {$locale}\n" );
        exit( 1 );
    }
    $count = unpack( 'V', substr( $bytes, 8, 4 ) )[1];
    $orig_table = unpack( 'V', substr( $bytes, 12, 4 ) )[1];
    $trans_table = unpack( 'V', substr( $bytes, 16, 4 ) )[1];
    if ( $count !== $tc_auditor_entries ||
        $orig_table + ( $count * 8 ) > strlen( $bytes ) ||
        $trans_table + ( $count * 8 ) > strlen( $bytes ) ) {
        fwrite( STDERR, "Invalid Auditor MO index: {$locale}\n" );
        exit( 1 );
    }
    $has_header = false;
    for ( $i = 0; $i < $count; $i++ ) {
        $origin = unpack( 'Vlength/Voffset', substr( $bytes, $orig_table + 8 * $i, 8 ) );
        $target = unpack( 'Vlength/Voffset', substr( $bytes, $trans_table + 8 * $i, 8 ) );
        if ( $origin['offset'] + $origin['length'] >= strlen( $bytes ) ||
            $target['offset'] + $target['length'] >= strlen( $bytes ) ) {
            fwrite( STDERR, "Invalid Auditor MO string pointer: {$locale}\n" );
            exit( 1 );
        }
        if ( 0 === $origin['length'] ) {
            $has_header = true;
        } elseif ( 0 === $target['length'] ) {
            fwrite( STDERR, "Untranslated Auditor MO entry: {$locale}\n" );
            exit( 1 );
        }
    }
    if ( ! $has_header ) {
        fwrite( STDERR, "Auditor MO header missing: {$locale}\n" );
        exit( 1 );
    }
}
echo "Auditor five-locale native gettext catalogs: OK\n";
