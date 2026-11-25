<?php
/**
 * OpenAI product-feed preparation (organic discovery only).
 * Reference: https://developers.openai.com/commerce/specs/file-upload/products
 */
defined( 'ABSPATH' ) || exit;

/** Explicit allowlist: never export the canonical record wholesale. */
function tcdist_feed_openai_columns() {
    return [
        'is_eligible_search', 'is_eligible_checkout', 'is_ads_eligible',
        'item_id', 'gtin', 'mpn', 'title', 'description', 'url', 'brand',
        'condition', 'product_category', 'image_url', 'additional_image_urls',
        'price', 'sale_price', 'sale_price_start_date', 'sale_price_end_date',
        'availability', 'group_id', 'listing_has_variations', 'variant_dict',
        'seller_name', 'seller_url', 'target_countries', 'store_country',
    ];
}

function tcdist_feed_openai_url( $value ) {
    $url = trim( (string) $value );
    $parts = wp_parse_url( $url );
    if ( ! is_array( $parts ) || empty( $parts['host'] )
        || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true )
        || isset( $parts['user'] ) || isset( $parts['pass'] )
        || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
        return '';
    }
    return $url;
}

function tcdist_feed_openai_price( $value ) {
    $value = trim( (string) $value );
    return preg_match( '/^([0-9]+(?:\.[0-9]+)?) ([A-Z]{3})$/D', $value, $match ) && (float) $match[1] > 0
        ? [ (float) $match[1], $match[2] ] : [];
}

/** Preserve GTIN leading zeroes; omit malformed optional identifiers. */
function tcdist_feed_openai_gtin( $value ) {
    $value = (string) $value;
    if ( ! preg_match( '/^(?:[0-9]{8}|[0-9]{12}|[0-9]{13}|[0-9]{14})$/D', $value ) ) {
        return '';
    }
    $sum = 0;
    $weight = 3;
    for ( $i = strlen( $value ) - 2; $i >= 0; $i-- ) {
        $sum += (int) $value[$i] * $weight;
        $weight = 4 - $weight;
    }
    return ( 10 - $sum % 10 ) % 10 === (int) substr( $value, -1 ) ? $value : '';
}

/** Store/market context is snapshotted for the whole bounded build. */
function tcdist_feed_openai_context( array $settings ) {
    $base = tcdist_feed_default_settings();
    return [
        'seller_name' => tcdist_feed_plain_text( get_bloginfo( 'name' ), 70 ),
        'seller_url' => home_url( '/' ),
        'target_countries' => (string) ( $settings['country'] ?? '' ),
        'store_country' => (string) ( $base['country'] ?? '' ),
    ];
}

/**
 * Translate canonical public values without inventing missing brand, stock,
 * discounts or identifiers. WP_Error means this row is excluded only from
 * OpenAI, not from the other commercial channels.
 */
function tcdist_feed_openai_row( array $record, array $context ) {
    $id = trim( (string) ( $record['id'] ?? '' ) );
    $title = tcdist_feed_plain_text( $record['title'] ?? '', 150 );
    $description = tcdist_feed_plain_text( $record['description'] ?? '', 5000 );
    $brand = tcdist_feed_plain_text( $record['brand'] ?? '', 70 );
    $seller = tcdist_feed_plain_text( $context['seller_name'] ?? '', 70 );
    $country = strtoupper( (string) ( $context['target_countries'] ?? '' ) );
    $store_country = strtoupper( (string) ( $context['store_country'] ?? '' ) );
    $url = tcdist_feed_openai_url( $record['link'] ?? '' );
    $image = tcdist_feed_openai_url( $record['image_link'] ?? '' );
    $price = (string) ( $record['price'] ?? '' );
    $price_parts = tcdist_feed_openai_price( $price );
    $availability = (string) ( $record['availability'] ?? '' );
    $group = (string) ( $record['item_group_id'] ?? '' );

    if ( '' === $id || strlen( $id ) > 100 || '' === $title || '' === $description ) {
        return new WP_Error( 'missing_product_data', 'Missing required product data.' );
    }
    if ( '' === $brand || in_array( strtolower( $brand ), [ 'unknown', 'null', 'n/a' ], true ) ) {
        return new WP_Error( 'missing_brand', 'Missing real product brand.' );
    }
    if ( '' === tcdist_feed_openai_url( $context['seller_url'] ?? '' ) || ! preg_match( '/^[A-Z]{2}$/D', $store_country ) || '' === $seller || in_array( strtolower( $seller ), [ 'unknown', 'null', 'n/a' ], true ) || ! preg_match( '/^[A-Z]{2}$/D', $country ) ) {
        return new WP_Error( 'missing_merchant_data', 'Missing merchant or market data.' );
    }
    if ( '' === $url || '' === $image ) {
        return new WP_Error( 'invalid_public_url', 'Invalid public product or image URL.' );
    }
    if ( empty( $price_parts ) ) {
        return new WP_Error( 'invalid_price', 'Invalid product price.' );
    }
    if ( ! in_array( $availability, [ 'in_stock', 'out_of_stock', 'backorder' ], true ) ) {
        return new WP_Error( 'invalid_availability', 'Unsupported stock state.' );
    }

    $sale = trim( (string) ( $record['sale_price'] ?? '' ) );
    $sale_parts = tcdist_feed_openai_price( $sale );
    $start = '';
    $end = '';
    $period = (string) ( $record['sale_price_effective_date'] ?? '' );
    if ( ! empty( $sale_parts ) && $sale_parts[1] === $price_parts[1] && $sale_parts[0] < $price_parts[0] ) {
        if ( '' !== $period ) {
            $dates = explode( '/', $period, 2 );
            if ( 2 === count( $dates ) && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $dates[0] )
                && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $dates[1] )
                && strtotime( $dates[0] ) < strtotime( $dates[1] ) && strtotime( $dates[1] ) > time() ) {
                $start = $dates[0];
                $end = $dates[1];
            } else {
                $sale = '';
            }
        }
    } else {
        $sale = '';
    }

    $extra = [];
    foreach ( (array) ( $record['additional_images'] ?? [] ) as $candidate ) {
        $candidate = tcdist_feed_openai_url( $candidate );
        if ( '' !== $candidate ) {
            $extra[] = $candidate;
        }
    }

    return [
        'is_eligible_search' => 'in_stock' === $availability ? 'true' : 'false',
        'is_eligible_checkout' => 'false',
        'is_ads_eligible' => 'false',
        'item_id' => $id,
        'gtin' => tcdist_feed_openai_gtin( $record['gtin'] ?? '' ),
        'mpn' => tcdist_feed_plain_text( $record['mpn'] ?? '', 70 ),
        'title' => $title,
        'description' => $description,
        'url' => $url,
        'brand' => $brand,
        'condition' => in_array( $record['condition'] ?? '', [ 'new', 'used', 'refurbished' ], true ) ? $record['condition'] : '',
        'product_category' => tcdist_feed_plain_text( $record['product_type'] ?? '', 1000 ),
        'image_url' => $image,
        'additional_image_urls' => implode( ',', array_unique( $extra ) ),
        'price' => $price,
        'sale_price' => $sale,
        'sale_price_start_date' => $start,
        'sale_price_end_date' => $end,
        'availability' => $availability,
        'group_id' => '' !== $group ? $group : $id,
        'listing_has_variations' => '' !== $group ? 'true' : 'false',
        'variant_dict' => (string) ( $context['variant_dict'] ?? '' ),
        'seller_name' => $seller,
        'seller_url' => tcdist_feed_openai_url( $context['seller_url'] ?? '' ),
        'target_countries' => $country,
        'store_country' => preg_match( '/^[A-Z]{2}$/D', $store_country ) ? $store_country : '',
    ];
}

/** Read only actual public variant options; use public labels, not raw metadata. */
function tcdist_feed_openai_variant_dict( $product_id ) {
    $product = wc_get_product( $product_id );
    if ( ! $product || ! $product->is_type( 'variation' ) ) {
        return '';
    }
    $attributes = [];
    foreach ( $product->get_attributes() as $name => $value ) {
        if ( '' === (string) $value ) {
            continue;
        }
        $label = wc_attribute_label( $name, $product );
        if ( taxonomy_exists( $name ) ) {
            $term = get_term_by( 'slug', $value, $name );
            if ( $term && ! is_wp_error( $term ) ) {
                $value = $term->name;
            }
        }
        $label = tcdist_feed_plain_text( $label, 100 );
        $value = tcdist_feed_plain_text( $value, 200 );
        if ( '' !== $label && '' !== $value ) {
            $attributes[$label] = $value;
        }
    }
    return empty( $attributes ) ? '' : (string) wp_json_encode( (object) $attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}
