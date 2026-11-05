<?php
/**
 * Standalone local semantic extractor, adapted from the former classifier.
 *
 * @package TaxoCommerce_Vocabulary
 */
defined( 'ABSPATH' ) || exit;

final class TCV_Extractor {
    public static function normalize( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
        $text = remove_accents( $text );
        $text = mb_strtolower( $text, 'UTF-8' );
        $text = preg_replace( '/[^a-z0-9º°+\/._-]+/u', ' ', $text );
        return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
    }

    /** Source-specific normalized text and original text for numeric extraction. */
    public static function context( $values ) {
        $raw = array();
        $segments = array();
        foreach ( array( 'title', 'excerpt', 'content', 'categories', 'tags', 'brands', 'sku', 'supplier' ) as $field ) {
            $value = isset( $values[ $field ] ) ? (string) $values[ $field ] : '';
            $raw[ $field ] = mb_substr( $value, 0, 18000, 'UTF-8' );
            $segments[ $field ] = self::normalize( $raw[ $field ] );
        }
        $parts = preg_split( '/\s+[\x{2013}\x{2014}|-]\s+|:\s+/u', $raw['title'], 2 );
        $segments['identity'] = self::normalize( isset( $parts[0] ) ? $parts[0] : $raw['title'] );
        return array( 'raw' => $raw, 'segments' => $segments );
    }

    private static function concepts( $text ) {
        $stop = array_fill_keys( array( 'de', 'del', 'la', 'el', 'las', 'los', 'para',
            'por', 'con', 'sin', 'y', 'o', 'un', 'una', 'en', 'tipo', 'modelo',
            'producto', 'productos', 'profesional', 'nuevo', 'universal' ), true );
        $aliases = array(
            'coche' => 'vehiculo', 'coches' => 'vehiculo', 'automovil' => 'vehiculo',
            'vehiculos' => 'vehiculo', 'autos' => 'vehiculo', 'diagnosis' => 'diagnostico',
            'brocas' => 'broca', 'taladrado' => 'perforacion', 'taladrar' => 'perforacion',
            'perforar' => 'perforacion', 'concreto' => 'hormigon',
            'cristal' => 'vidrio', 'glass' => 'vidrio', 'radial' => 'amoladora',
            'esmeriladora' => 'amoladora', 'azulejos' => 'ceramica',
            'sds+' => 'sdsplus', 'sds-plus' => 'sdsplus', 'obd2' => 'obd',
            'obdii' => 'obd', 'voltaje' => 'tension', 'inóx' => 'inoxidable',
        );
        $text = str_replace( array( '/', '+', '.', '_' ), ' ', self::normalize( $text ) );
        $tokens = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        $out = array();
        foreach ( (array) $tokens as $token ) {
            if ( strlen( $token ) < 2 || isset( $stop[ $token ] ) ) {
                continue;
            }
            if ( isset( $aliases[ $token ] ) ) {
                $token = $aliases[ $token ];
            } elseif ( strlen( $token ) > 4 && preg_match( '/[aeiou]s$/', $token ) ) {
                $token = substr( $token, 0, -1 );
            }
            $out[] = $token;
        }
        return array_values( array_unique( $out ) );
    }

    /** Rank a canonical label; never create or assign a WordPress term. */
    public static function score( $label, $context ) {
        $needle = self::concepts( $label );
        if ( ! $needle ) {
            return array( 'score' => 0.0, 'evidence' => array() );
        }
        $weights = array( 'identity' => 1.0, 'title' => 1.0, 'brands' => 0.94,
            'supplier' => 0.9, 'tags' => 0.88, 'excerpt' => 0.8,
            'categories' => 0.76, 'content' => 0.64 );
        $segments = isset( $context['segments'] ) ? $context['segments'] : array();
        $sets = array();
        foreach ( $weights as $source => $weight ) {
            $sets[ $source ] = array_fill_keys( self::concepts( isset( $segments[ $source ] ) ? $segments[ $source ] : '' ), true );
        }
        $hits = 0;
        $sum = 0.0;
        $evidence = array();
        foreach ( $needle as $token ) {
            $best = 0.0;
            $source_name = '';
            foreach ( $weights as $source => $weight ) {
                if ( isset( $sets[ $source ][ $token ] ) && $weight > $best ) {
                    $best = $weight;
                    $source_name = $source;
                }
            }
            if ( $best > 0 ) {
                ++$hits;
                $sum += $best;
                $evidence[] = array( 'source' => $source_name, 'matched' => $token );
            }
        }
        if ( ! $hits ) {
            return array( 'score' => 0.0, 'evidence' => array() );
        }
        $exact = false;
        $phrase = self::normalize( $label );
        foreach ( $segments as $source => $text ) {
            if ( '' !== $phrase && false !== strpos( ' ' . $text . ' ', ' ' . $phrase . ' ' ) ) {
                $exact = true;
                $evidence[] = array( 'source' => $source, 'matched' => $phrase );
                break;
            }
        }
        $coverage = $sum / count( $needle );
        $title_hits = count( array_intersect( $needle, array_keys( $sets['title'] ) ) );
        $score = 0.08 + 0.68 * $coverage + 0.14 * ( $title_hits / count( $needle ) ) + ( $exact ? 0.10 : 0.0 );
        if ( $hits < count( $needle ) ) {
            $score *= $hits / count( $needle );
        }
        return array( 'score' => round( min( 1.0, $score ), 4 ), 'evidence' => $evidence );
    }
}
