<?php
/**
 * Validate all 5 native gettext MO files after IndexNow locale update.
 * Offline smoke; does not require WordPress, network or DB.
 */
$root = dirname(__DIR__) . '/languages/taxocommerce-google-bing-';
$expected = array(
    'es_ES' => 'Añadir a la cola IndexNow',
    'en_US' => 'Add to IndexNow queue',
    'fr_FR' => 'Ajouter à la file IndexNow',
    'de_DE' => 'Zur IndexNow-Warteschlange hinzufügen',
    'nl_NL' => 'Toevoegen aan IndexNow-wachtrij',
);
foreach ($expected as $locale => $translation) {
    $po = file_get_contents($root . $locale . '.po');
    $bytes = file_get_contents($root . $locale . '.mo');
    if (!$bytes || strlen($bytes) < 28 || !$po) {
        fwrite(STDERR, "FAIL missing language $locale\n");
        exit(1);
    }
    $header = unpack('Vmagic/Vrevision/Vcount/Vorig/Vtrans/Vhashsize/Vhash', substr($bytes, 0, 28));
    if (0x950412de !== $header['magic'] || !$header['count'] ||
        $header['orig'] + $header['count'] * 8 > strlen($bytes) ||
        $header['trans'] + $header['count'] * 8 > strlen($bytes)) {
        fwrite(STDERR, "FAIL invalid MO header $locale\n");
        exit(1);
    }
    if ($header['count'] !== preg_match_all('/^msgid "/m', $po)) {
        fwrite(STDERR, "FAIL PO and MO counts differ $locale\n");
        exit(1);
    }
    $catalog = array();
    for ($i = 0; $i < $header['count']; ++$i) {
        $orig = unpack('Vlength/Voffset', substr($bytes, $header['orig'] + $i * 8, 8));
        $trans = unpack('Vlength/Voffset', substr($bytes, $header['trans'] + $i * 8, 8));
        if ($orig['offset'] + $orig['length'] >= strlen($bytes) ||
            $trans['offset'] + $trans['length'] >= strlen($bytes)) {
            fwrite(STDERR, "FAIL out-of-range MO offset $locale\n");
            exit(1);
        }
        $catalog[substr($bytes, $orig['offset'], $orig['length'])] =
            substr($bytes, $trans['offset'], $trans['length']);
    }
    if (($catalog['Añadir a la cola IndexNow'] ?? null) !== $translation ||
        !isset($catalog['Activar IndexNow para avisar a Bing de URL creadas, modificadas o eliminadas. No actives esta opción si otro servicio ya envía estas notificaciones.']) ||
        '' === $catalog['Activar IndexNow para avisar a Bing de URL creadas, modificadas o eliminadas. No actives esta opción si otro servicio ya envía estas notificaciones.']) {
        fwrite(STDERR, "FAIL wrong IndexNow translation $locale\n");
        exit(1);
    }
}
echo "Insights IndexNow five-language MO catalogs: PASS\n";
