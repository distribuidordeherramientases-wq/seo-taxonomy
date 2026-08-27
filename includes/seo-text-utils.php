<?php
if (!defined('ABSPATH')) exit;





// =========================
// SIMILITUD (FUNCIONES)
// =========================



if (!function_exists('seo_clean_text')) {

    function seo_clean_text($text) {

        $text = remove_accents(
            mb_strtolower(
                strip_tags($text)
            )
        );

        $text = preg_replace(
            '/[^a-z0-9áéíóúñü\s]/iu',
            ' ',
            $text
        );

        $text = preg_replace(
            '/\s+/',
            ' ',
            $text
        );

        return trim($text);
    }
}


if (!function_exists('seo_token_similarity')) {
function seo_token_similarity($a, $b) {
    $ta = array_unique(seo_cat_tokens($a));
    $tb = array_unique(seo_cat_tokens($b));

    if (empty($ta) || empty($tb)) {
        return 0;
    }

    $inter = array_intersect($ta, $tb);

    // Cobertura del primer texto
    return (count($inter) / max(count($ta),1)) * 100;
}
}