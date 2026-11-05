<?php
/**
 * Offline regression check for TaxoCommerce Suppliers native WordPress gettext.
 *
 * Does not start WordPress, access a database, or mutate site data.
 */
$base = dirname( __DIR__ );
$locales = array( 'en_US', 'fr_FR', 'es_ES', 'de_DE' );
$manual = file_get_contents( $base . '/includes/class-tcsup-manual.php' );
$autotest = file_get_contents( $base . '/includes/class-tcsup-autotest.php' );
$admin  = file_get_contents( $base . '/includes/class-tcsup-admin.php' );

function tcsup_i18n_fail( $message ) {
    fwrite( STDERR, "FAIL i18n: {$message}\n" );
    exit( 1 );
}

function tcsup_i18n_parse_po( $file ) {
    $entries = array();
    $blocks  = preg_split( '/\R\s*\R/u', file_get_contents( $file ) );
    foreach ( $blocks as $block ) {
        $key   = null;
        $value = null;
        $mode  = '';
        foreach ( preg_split( '/\R/u', $block ) as $line ) {
            if ( 0 === strpos( $line, 'msgid ' ) ) {
                $key  = json_decode( substr( $line, 6 ), true );
                $mode = 'id';
            } elseif ( 0 === strpos( $line, 'msgstr ' ) ) {
                $value = json_decode( substr( $line, 7 ), true );
                $mode  = 'str';
            } elseif ( 0 === strpos( $line, '"' ) ) {
                $fragment = json_decode( $line, true );
                if ( null !== $fragment && 'id' === $mode ) {
                    $key .= $fragment;
                } elseif ( null !== $fragment && 'str' === $mode ) {
                    $value .= $fragment;
                }
            }
        }
        if ( null !== $key ) {
            if ( array_key_exists( $key, $entries ) ) {
                tcsup_i18n_fail( 'duplicate PO entry ' . $key );
            }
            $entries[ $key ] = $value;
        }
    }
    return $entries;
}

foreach ( array(
    '<p>Suppliers recibe catálogos CSV',
    '<th>Pestaña</th>',
    '<td>Consultar estas dos guías.</td>',
    '<option value="">All suppliers</option>',
    '<option value="">Bulk action</option>',
) as $literal ) {
    if ( false !== strpos( $manual . $admin, $literal ) ) {
        tcsup_i18n_fail( 'hardcoded UI: ' . $literal );
    }
}


// Technical identifiers are not translatable and must stay outside gettext.
if ( preg_match( "/__\(\s*'<code>[^']*<\/code>'\s*,\s*'taxocommerce-suppliers'/", $manual ) ) {
    tcsup_i18n_fail( 'code-only HTML wrongly wrapped in gettext' );
}
foreach ( array( 'Sistema', 'Configuración', 'Persistencia', 'Consulta de estados' ) as $diagnostic ) {
    if ( false === strpos( $autotest, "'$diagnostic', 'taxocommerce-suppliers'" ) ) {
        tcsup_i18n_fail( 'Autocheck diagnostic is not localized: ' . $diagnostic );
    }
}

$key_sets = null;
foreach ( $locales as $locale ) {
    $prefix = $base . '/languages/taxocommerce-suppliers-' . $locale;
    $po = tcsup_i18n_parse_po( $prefix . '.po' );
    if ( count( $po ) < 510 ) {
        tcsup_i18n_fail( $locale . ' catalog has too few entries: ' . count( $po ) );
    }
    foreach ( $po as $key => $value ) {
        if ( '' !== $key && ( ! is_string( $value ) || '' === $value ) ) {
            tcsup_i18n_fail( $locale . ' blank translation for ' . $key );
        }
    }
    $keys = array_keys( $po );
    sort( $keys );
    if ( null === $key_sets ) {
        $key_sets = $keys;
    } elseif ( $keys !== $key_sets ) {
        tcsup_i18n_fail( 'catalog key mismatch in ' . $locale );
    }
    $mo = file_get_contents( $prefix . '.mo' );
    if ( strlen( $mo ) < 28 ) {
        tcsup_i18n_fail( 'invalid MO length ' . $locale );
    }
    $header = unpack( 'Vmagic/Vrevision/Vcount/Voriginal/Vtranslation/Vhash_count/Vhash_table', substr( $mo, 0, 28 ) );
    if (
        0x950412de !== $header['magic']
        || 0 !== $header['revision']
        || $header['count'] !== count( $po )
        || $header['original'] < 28
        || $header['translation'] < $header['original'] + 8 * $header['count']
        || $header['hash_table'] < $header['translation'] + 8 * $header['count']
        || $header['hash_table'] > strlen( $mo )
    ) {
        tcsup_i18n_fail( 'malformed MO index table for ' . $locale );
    }
    if ( 'en_US' === $locale && '1.1 What it solves' !== ( $po['1.1 Qué resuelve'] ?? '' ) ) {
        tcsup_i18n_fail( 'English translation for manual heading is wrong' );
    }
}
echo "Suppliers i18n smoke passed: four complete catalogs, native gettext and manual UI\n";
