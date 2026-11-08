<?php
/**
 * Prevent broken gettext catalogs from rendering blank controls.
 *
 * @package TaxoCommerceImportExport
 */
defined( 'ABSPATH' ) || exit;

/**
 * The plugin may be served older WordPress language packs or a cached MO.
 * Never let an empty translation hide original interface text.
 */
final class TaxoCommerce_IE_Gettext_Fallback {
    const DOMAIN = 'taxocommerce-import-export';

    public static function register() {
        add_filter( 'gettext', array( __CLASS__, 'singular' ), PHP_INT_MAX, 3 );
        add_filter( 'gettext_with_context', array( __CLASS__, 'with_context' ), PHP_INT_MAX, 4 );
        add_filter( 'ngettext', array( __CLASS__, 'plural' ), PHP_INT_MAX, 5 );
        add_filter( 'ngettext_with_context', array( __CLASS__, 'plural_with_context' ), PHP_INT_MAX, 6 );
    }

    private static function missing( $translation, $domain ) {
        return self::DOMAIN === $domain
            && is_string( $translation )
            && '' === trim( $translation );
    }

    public static function singular( $translation, $original, $domain ) {
        return self::missing( $translation, $domain ) && is_string( $original ) && '' !== $original
            ? $original : $translation;
    }

    public static function with_context( $translation, $original, $context, $domain ) {
        return self::singular( $translation, $original, $domain );
    }

    public static function plural( $translation, $single, $plural, $number, $domain ) {
        if ( ! self::missing( $translation, $domain ) ) {
            return $translation;
        }
        return 1 === (int) $number ? $single : $plural;
    }

    public static function plural_with_context( $translation, $single, $plural, $number, $context, $domain ) {
        return self::plural( $translation, $single, $plural, $number, $domain );
    }
}
