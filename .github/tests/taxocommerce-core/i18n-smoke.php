<?php
/**
 * Standalone, read-only validation of native Core translation catalogs.
 * Runs without WordPress and never touches the database.
 */
$root = dirname( __DIR__, 3 ) . '/taxocommerce-core';
$main = file_get_contents( $root . '/taxocommerce-core.php' );
if ( false === $main || false === strpos( $main, 'Text Domain: taxocommerce-core-catalog-content-management-for-woocommerce' ) || false === strpos( $main, 'Domain Path: /languages' ) ) {
    throw new RuntimeException( 'Core textdomain metadata missing.' );
}
if ( false === strpos( $main, "add_action( 'init', 'taxocommerce_core_load_textdomain', 1 );" ) ) {
    throw new RuntimeException( 'Core translations must be loaded on init, not plugins_loaded.' );
}
$pot = file_get_contents( $root . '/languages/taxocommerce-core-catalog-content-management-for-woocommerce.pot' );
if ( false === $pot || substr_count( $pot, 'msgid ' ) < 500 ) {
    throw new RuntimeException( 'Missing or incomplete gettext template.' );
}
/**
 * Read the GNU MO header and dictionary, using portable LE binary words.
 */
function tc_core_mo_strings( $path ) {
    $blob = file_get_contents( $path );
    if ( false === $blob || strlen( $blob ) < 28 ) {
        throw new RuntimeException( 'Translation catalog is missing: ' . $path );
    }
    $magic = unpack( 'V', substr( $blob, 0, 4 ) )[1];
    if ( 0x950412de !== $magic ) {
        throw new RuntimeException( 'Invalid GNU MO file: ' . $path );
    }
    $meta = unpack( 'Vrevision/Vcount/Voriginal/Vtranslation', substr( $blob, 4, 16 ) );
    if ( $meta['count'] > 2000 || $meta['count'] < 2 ) {
        throw new RuntimeException( 'Unexpected gettext catalog size.' );
    }
    $messages = array();
    for ( $i = 0; $i < $meta['count']; ++$i ) {
        $original = unpack( 'Vlength/Voffset', substr( $blob, $meta['original'] + $i * 8, 8 ) );
        $translated = unpack( 'Vlength/Voffset', substr( $blob, $meta['translation'] + $i * 8, 8 ) );
        $messages[ substr( $blob, $original['offset'], $original['length'] ) ] = substr( $blob, $translated['offset'], $translated['length'] );
    }
    return $messages;
}
$locales = array(
    'es_ES' => array( 'User Guide', 'Manual de usuario' ),
    'en_US' => array( 'Gestor de menús', 'Menu manager' ),
    'fr_FR' => array( 'User Guide', 'Guide utilisateur' ),
    'de_DE' => array( 'User Guide', 'Benutzerhandbuch' ),
    'nl_NL' => array( 'User Guide', 'Gebruikershandleiding' ),
);
foreach ( $locales as $locale => $sample ) {
    $base = $root . '/languages/taxocommerce-core-catalog-content-management-for-woocommerce-' . $locale;
    if ( ! is_file( $base . '.po' ) ) {
        throw new RuntimeException( 'PO is missing for ' . $locale );
    }
    $messages = tc_core_mo_strings( $base . '.mo' );
    if ( ( $messages[ $sample[0] ] ?? null ) !== $sample[1] ) {
        throw new RuntimeException( 'Translation not compiled for ' . $locale . ': ' . $sample[0] );
    }
    // Complete catalogs are mandatory for each of the five supported administration locales.
    if ( 792 !== count( $messages ) ) {
        throw new RuntimeException( 'Unexpected Core MO key count for ' . $locale );
    }
    foreach ( $messages as $original => $translated ) {
        if ( '' !== $original && '' === $translated ) {
            throw new RuntimeException( 'Empty compiled translation in ' . $locale . ': ' . $original );
        }
    }
    $required_labels = array(
        'fr_FR' => array(
            'Seleccionar menú' => 'Sélectionner un menu',
            'Instalar ZIP' => 'Installer le ZIP',
            'Políticas de spam y contenido masivo' => 'Politiques de lutte contre le spam et le contenu massif',
        ),
        'de_DE' => array(
            'Seleccionar menú' => 'Menü auswählen',
            'Instalar ZIP' => 'ZIP installieren',
            'Políticas de spam y contenido masivo' => 'Richtlinien zu Spam und massenhaft erstellten Inhalten',
        ),
        'nl_NL' => array(
            'Seleccionar menú' => 'Menu selecteren',
            'Instalar ZIP' => 'ZIP installeren',
            'Políticas de spam y contenido masivo' => 'Beleid voor spam en massaal geproduceerde inhoud',
        ),
    );
    foreach ( ( $required_labels[ $locale ] ?? array() ) as $original => $translated ) {
        if ( ( $messages[ $original ] ?? null ) !== $translated ) {
            throw new RuntimeException( 'Missing required interface translation in ' . $locale . ': ' . $original );
        }
    }
    echo $locale . ': ' . count( $messages ) . " entries OK\n";
}
echo "TaxoCommerce Core gettext QA passed.\n";
