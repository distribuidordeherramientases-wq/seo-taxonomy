<?php
/**
 * Keep native WordPress document titles concise without rewriting entity names.
 *
 * @package TaxoCommerceTemplates
 */

defined( 'ABSPATH' ) || exit;

final class TCT_SEO_Title {
    /**
     * On long default WordPress titles, omit the repeated site-name suffix.
     * This leaves the actual page, post or taxonomy title untouched.
     *
     * @param array $parts Native document title parts.
     * @return array
     */
    public static function concise_parts( $parts ) {
        if ( is_admin() || ! is_array( $parts ) || empty( $parts['site'] ) ) {
            return $parts;
        }
        $separator = (string) apply_filters( 'document_title_separator', '-' );
        $joined = implode( ' ' . $separator . ' ', array_filter( $parts, 'is_scalar' ) );
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $joined, 'UTF-8' ) : strlen( $joined );
        if ( $length > 69 && ! empty( $parts['title'] ) ) {
            unset( $parts['site'], $parts['tagline'] );
        }
        return $parts;
    }
}
