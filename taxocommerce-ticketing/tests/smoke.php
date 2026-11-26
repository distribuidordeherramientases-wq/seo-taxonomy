<?php
/**
 * Static smoke checks for the standalone plugin bootstrap.
 * Run from repository root: php taxocommerce-ticketing/tests/smoke.php
 */
$base = dirname( __DIR__ );
$entry = file_get_contents( $base . '/taxocommerce-ticketing.php' );
$core  = file_get_contents( $base . '/includes/class-tctk-ticketing.php' );
$checks = array(
    'plugin header' => false !== strpos( $entry, 'Plugin Name: TaxoCommerce Ticketing' ),
    'separate plugin' => false === strpos( $entry, "require_once __DIR__ . '/../taxocommerce-tasks" ),
    'standalone shortcode' => false !== strpos( $core, "'taxocommerce_ticket_form'" ),
    'private downloads' => false !== strpos( $core, 'public static function download()' ),
    'customer token' => false !== strpos( $core, "hash_hmac( 'sha256'" ),
    'message history' => false !== strpos( $core, 'CREATE TABLE {$tables[\'messages\']}' ),
    'settings' => false !== strpos( $core, 'public static function save_settings()' ),
    'staff interface' => false !== strpos( $core, 'public static function admin()' ),
    'optional tasks hook' => false !== strpos( $core, "'tctk_ticket_admin_actions'" ),
);
foreach ( $checks as $label => $passes ) {
    if ( ! $passes ) {
        fwrite( STDERR, "FAIL: {$label}\n" );
        exit( 1 );
    }
}
echo "Ticketing static smoke: OK\n";

// Validate the bundled binary catalogs and key UI values without WordPress.
$expected = array(
    'es_ES' => array( 'Ajustes', 'En curso', 'Bandeja de tickets', 'Chequeo de funcionalidades' ),
    'en_US' => array( 'Settings', 'In progress', 'Ticket inbox', 'Functionality Check' ),
    'fr_FR' => array( 'Réglages', 'En cours', 'Boîte de réception des tickets', 'Contrôle des fonctionnalités' ),
    'de_DE' => array( 'Einstellungen', 'In Bearbeitung', 'Ticket-Eingang', 'Funktionsprüfung' ),
    'nl_NL' => array( 'Instellingen', 'In behandeling', 'Ticketinbox', 'Functiecontrole' ),
);
foreach ( $expected as $locale => $values ) {
    $prefix = $base . '/languages/taxocommerce-ticketing-' . $locale;
    $po = file_get_contents( $prefix . '.po' );
    $mo = file_get_contents( $prefix . '.mo' );
    if ( ! $po || ! $mo || strlen( $mo ) < 28 ) {
        throw new RuntimeException( 'Missing catalog: ' . $locale );
    }
    $h = unpack( 'Vmagic/Vrevision/Vcount/Vorig/Vtrans/Vhashsize/Vhash', substr( $mo, 0, 28 ) );
    if ( 0x950412de !== $h['magic'] || 165 !== $h['count'] || $h['orig'] + $h['count'] * 8 > strlen( $mo ) || $h['trans'] + $h['count'] * 8 > strlen( $mo ) ) {
        throw new RuntimeException( 'Invalid catalog header: ' . $locale );
    }
    $catalog = array();
    $previous = null;
    for ( $i = 0; $i < $h['count']; ++$i ) {
        $o = unpack( 'Vlen/Voff', substr( $mo, $h['orig'] + $i * 8, 8 ) );
        $t = unpack( 'Vlen/Voff', substr( $mo, $h['trans'] + $i * 8, 8 ) );
        if ( $o['off'] + $o['len'] >= strlen( $mo ) || $t['off'] + $t['len'] >= strlen( $mo ) || "\0" !== $mo[$o['off'] + $o['len']] || "\0" !== $mo[$t['off'] + $t['len']] ) {
            throw new RuntimeException( 'Invalid catalog offsets: ' . $locale );
        }
        $key = substr( $mo, $o['off'], $o['len'] );
        $value = substr( $mo, $t['off'], $t['len'] );
        if ( ( null !== $previous && strcmp( $previous, $key ) >= 0 ) || '' === $value ) {
            throw new RuntimeException( 'Unsorted or empty catalog: ' . $locale );
        }
        $catalog[$key] = $value;
        $previous = $key;
    }
    foreach ( array( 'Settings', 'In progress', 'Ticket inbox', 'Functionality Check' ) as $index => $key ) {
        if ( ( $catalog[$key] ?? null ) !== $values[$index] ) {
            throw new RuntimeException( 'Incorrect translation: ' . $locale . ' / ' . $key );
        }
    }
    if ( empty( $catalog['What Ticketing is for'] ) || empty( $catalog['Publish the customer form'] ) ) {
        throw new RuntimeException( 'Missing user manual: ' . $locale );
    }
    if ( false === strpos( $catalog['Ticket #%1$d received: %2$s'] ?? '', '%1$d' ) || false === strpos( $catalog['Ticket #%1$d received: %2$s'] ?? '', '%2$s' ) ) {
        throw new RuntimeException( 'Missing format placeholders: ' . $locale );
    }
}
echo "Ticketing five-language catalogs: OK\n";
