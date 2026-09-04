<?php
/**
 * SEO System - Google Analytics 4 ecommerce for WooCommerce.
 *
 * Envia los eventos recomendados de comercio electronico de GA4 usando la
 * misma Measurement ID ya configurada en Google Search / Analytics.
 * No requiere credenciales, API keys ni cambios en Search Console/Analytics.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SEO_GOOGLE_ECOMMERCE_VERSION' ) ) {
    define( 'SEO_GOOGLE_ECOMMERCE_VERSION', '1.0.0' );
}

if ( ! defined( 'SEO_GOOGLE_ECOMMERCE_QUEUE_KEY' ) ) {
    define( 'SEO_GOOGLE_ECOMMERCE_QUEUE_KEY', 'seo_google_ga4_ecommerce_queue_v1' );
}

if ( ! function_exists( 'seo_google_ecommerce_tracking_active' ) ) {
    function seo_google_ecommerce_tracking_active() {
        $doing_ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
        if ( is_admin() && ! $doing_ajax ) {
            return false;
        }
        if ( current_user_can( 'manage_options' ) ) {
            return false;
        }

        if ( ! function_exists( 'seo_google_search_settings' ) || ! function_exists( 'seo_google_search_measurement_id' ) ) {
            return false;
        }

        $settings = seo_google_search_settings();
        if ( empty( $settings['tracking_enabled'] ) || '' === seo_google_search_measurement_id() ) {
            return false;
        }

        if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_product' ) ) {
            return false;
        }

        if ( ! apply_filters( 'seo_google_tracking_allowed', true ) ) {
            return false;
        }

        return (bool) apply_filters( 'seo_google_ecommerce_tracking_allowed', true );
    }
}


if ( ! function_exists( 'seo_google_ecommerce_wc_runtime' ) ) {
    function seo_google_ecommerce_wc_runtime() {
        if ( ! function_exists( 'WC' ) ) {
            return null;
        }
        $wc = WC();
        if ( $wc && empty( $wc->session ) && function_exists( 'wc_load_cart' ) && did_action( 'woocommerce_init' ) ) {
            wc_load_cart();
            $wc = WC();
        }
        return $wc;
    }
}

if ( ! function_exists( 'seo_google_ecommerce_currency' ) ) {
    function seo_google_ecommerce_currency() {
        return function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
    }
}

if ( ! function_exists( 'seo_google_ecommerce_round' ) ) {
    function seo_google_ecommerce_round( $value ) {
        $decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
        return round( (float) $value, max( 0, (int) $decimals ) );
    }
}

if ( ! function_exists( 'seo_google_ecommerce_product_base' ) ) {
    function seo_google_ecommerce_product_base( $product ) {
        if ( ! is_object( $product ) || ! is_a( $product, 'WC_Product' ) ) {
            return null;
        }

        if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
            $parent_id = absint( $product->get_parent_id() );
            if ( $parent_id > 0 ) {
                $parent = wc_get_product( $parent_id );
                if ( $parent ) {
                    return $parent;
                }
            }
        }

        return $product;
    }
}

if ( ! function_exists( 'seo_google_ecommerce_category_path' ) ) {
    function seo_google_ecommerce_category_path( $product ) {
        $base = seo_google_ecommerce_product_base( $product );
        if ( ! $base ) {
            return [];
        }

        $terms = get_the_terms( $base->get_id(), 'product_cat' );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        $best = null;
        $best_depth = -1;
        foreach ( $terms as $term ) {
            $ancestors = array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
            $depth = count( $ancestors );
            if ( $depth > $best_depth ) {
                $best = $term;
                $best_depth = $depth;
            }
        }

        if ( ! $best ) {
            return [];
        }

        $ids = array_reverse( get_ancestors( $best->term_id, 'product_cat', 'taxonomy' ) );
        $ids[] = $best->term_id;
        $names = [];
        foreach ( array_slice( $ids, 0, 5 ) as $term_id ) {
            $term = get_term( $term_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) && '' !== trim( (string) $term->name ) ) {
                $names[] = (string) $term->name;
            }
        }

        return $names;
    }
}

if ( ! function_exists( 'seo_google_ecommerce_brand' ) ) {
    function seo_google_ecommerce_brand( $product ) {
        $base = seo_google_ecommerce_product_base( $product );
        if ( ! $base ) {
            return '';
        }

        foreach ( [ 'product_brand', 'pa_marca', 'pa_brand', 'pa_fabricante' ] as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $terms = get_the_terms( $base->get_id(), $taxonomy );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }
            $name = trim( (string) reset( $terms )->name );
            if ( '' !== $name ) {
                return $name;
            }
        }

        foreach ( [ '_brand', 'brand', 'marca', 'Brand', 'Marca' ] as $meta_key ) {
            $value = trim( wp_strip_all_tags( (string) get_post_meta( $base->get_id(), $meta_key, true ) ) );
            if ( '' !== $value ) {
                return $value;
            }
        }

        return '';
    }
}

if ( ! function_exists( 'seo_google_ecommerce_variant' ) ) {
    function seo_google_ecommerce_variant( $product ) {
        if ( ! is_object( $product ) || ! is_a( $product, 'WC_Product_Variation' ) ) {
            return '';
        }

        $parts = [];
        foreach ( (array) $product->get_variation_attributes() as $key => $value ) {
            $value = trim( (string) $value );
            if ( '' === $value ) {
                continue;
            }
            $taxonomy = str_replace( 'attribute_', '', (string) $key );
            if ( taxonomy_exists( $taxonomy ) ) {
                $term = get_term_by( 'slug', $value, $taxonomy );
                if ( $term && ! is_wp_error( $term ) ) {
                    $value = (string) $term->name;
                }
            }
            $parts[] = $value;
        }

        return implode( ' / ', array_values( array_unique( $parts ) ) );
    }
}

if ( ! function_exists( 'seo_google_ecommerce_item' ) ) {
    function seo_google_ecommerce_item( $product, $quantity = 1, $unit_price = null, $unit_discount = 0.0, $index = null ) {
        if ( ! is_object( $product ) || ! is_a( $product, 'WC_Product' ) ) {
            return [];
        }

        $quantity = max( 1, (float) $quantity );
        if ( null === $unit_price ) {
            if ( function_exists( 'wc_get_price_excluding_tax' ) ) {
                $unit_price = wc_get_price_excluding_tax( $product, [ 'qty' => 1 ] );
            } else {
                $unit_price = (float) $product->get_price();
            }
        }

        $item = [
            'item_id'   => (string) $product->get_id(),
            'item_name' => (string) $product->get_name(),
            'price'     => seo_google_ecommerce_round( max( 0, (float) $unit_price ) ),
            'quantity'  => $quantity,
        ];

        if ( null !== $index ) {
            $item['index'] = max( 0, (int) $index );
        }

        $unit_discount = max( 0, (float) $unit_discount );
        if ( $unit_discount > 0 ) {
            $item['discount'] = seo_google_ecommerce_round( $unit_discount );
        }

        $sku = trim( (string) $product->get_sku() );
        if ( '' !== $sku ) {
            $item['sku'] = $sku;
        }

        $brand = seo_google_ecommerce_brand( $product );
        if ( '' !== $brand ) {
            $item['item_brand'] = $brand;
        }

        $categories = seo_google_ecommerce_category_path( $product );
        foreach ( array_slice( $categories, 0, 5 ) as $offset => $category ) {
            $key = 0 === $offset ? 'item_category' : 'item_category' . ( $offset + 1 );
            $item[ $key ] = (string) $category;
        }

        $variant = seo_google_ecommerce_variant( $product );
        if ( '' !== $variant ) {
            $item['item_variant'] = $variant;
        }

        return $item;
    }
}

if ( ! function_exists( 'seo_google_ecommerce_cart_payload' ) ) {
    function seo_google_ecommerce_cart_payload() {
        $payload = [
            'currency' => seo_google_ecommerce_currency(),
            'value'    => 0.0,
            'items'    => [],
        ];

        $wc = seo_google_ecommerce_wc_runtime();
        $cart = $wc && ! empty( $wc->cart ) ? $wc->cart : null;
        if ( ! $cart || ! method_exists( $cart, 'get_cart' ) ) {
            return $payload;
        }

        $value = 0.0;
        $index = 0;
        foreach ( (array) $cart->get_cart() as $cart_item ) {
            $product = ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? $cart_item['data'] : null;
            $quantity = max( 1, (float) ( $cart_item['quantity'] ?? 1 ) );
            if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
                continue;
            }

            $line_total = isset( $cart_item['line_total'] ) ? max( 0, (float) $cart_item['line_total'] ) : 0.0;
            $line_subtotal = isset( $cart_item['line_subtotal'] ) ? max( 0, (float) $cart_item['line_subtotal'] ) : $line_total;
            $unit_price = $quantity > 0 ? ( $line_total / $quantity ) : 0.0;
            $unit_discount = $quantity > 0 ? max( 0, ( $line_subtotal - $line_total ) / $quantity ) : 0.0;

            $item = seo_google_ecommerce_item( $product, $quantity, $unit_price, $unit_discount, $index );
            if ( $item ) {
                $payload['items'][] = $item;
                $value += max( 0, $unit_price ) * $quantity;
                $index++;
            }
        }

        $payload['value'] = seo_google_ecommerce_round( $value );
        if ( method_exists( $cart, 'get_applied_coupons' ) ) {
            $coupons = array_values( array_filter( array_map( 'strval', (array) $cart->get_applied_coupons() ) ) );
            if ( $coupons ) {
                $payload['coupon'] = (string) reset( $coupons );
            }
        }

        return $payload;
    }
}

if ( ! function_exists( 'seo_google_ecommerce_order_payload' ) ) {
    function seo_google_ecommerce_order_payload( $order ) {
        if ( ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) ) {
            return [];
        }

        $currency = (string) $order->get_currency();
        $items = [];
        $value = 0.0;
        $index = 0;
        foreach ( $order->get_items( 'line_item' ) as $order_item ) {
            $product = $order_item->get_product();
            if ( ! $product ) {
                continue;
            }
            $quantity = max( 1, (float) $order_item->get_quantity() );
            $line_total = max( 0, (float) $order_item->get_total() );
            $line_subtotal = max( 0, (float) $order_item->get_subtotal() );
            $unit_price = $quantity > 0 ? ( $line_total / $quantity ) : 0.0;
            $unit_discount = $quantity > 0 ? max( 0, ( $line_subtotal - $line_total ) / $quantity ) : 0.0;
            $item = seo_google_ecommerce_item( $product, $quantity, $unit_price, $unit_discount, $index );
            if ( $item ) {
                // Mantiene el nombre que figuraba en el pedido aunque el producto cambie despues.
                $order_name = trim( (string) $order_item->get_name() );
                if ( '' !== $order_name ) {
                    $item['item_name'] = $order_name;
                }
                $items[] = $item;
                $value += $unit_price * $quantity;
                $index++;
            }
        }

        if ( ! $items ) {
            return [];
        }

        $payload = [
            'transaction_id' => (string) $order->get_order_number(),
            'value'          => seo_google_ecommerce_round( $value ),
            'tax'            => seo_google_ecommerce_round( max( 0, (float) $order->get_total_tax() ) ),
            'shipping'       => seo_google_ecommerce_round( max( 0, (float) $order->get_shipping_total() ) ),
            'currency'       => $currency,
            'items'          => $items,
        ];

        $coupons = method_exists( $order, 'get_coupon_codes' ) ? (array) $order->get_coupon_codes() : [];
        $coupons = array_values( array_filter( array_map( 'strval', $coupons ) ) );
        if ( $coupons ) {
            $payload['coupon'] = (string) reset( $coupons );
        }

        return $payload;
    }
}

if ( ! function_exists( 'seo_google_ecommerce_queue_event' ) ) {
    function seo_google_ecommerce_queue_event( $event_name, array $params ) {
        $wc = seo_google_ecommerce_wc_runtime();
        if ( ! $wc || empty( $wc->session ) ) {
            return;
        }

        $event_name = sanitize_key( (string) $event_name );
        if ( '' === $event_name || empty( $params['items'] ) ) {
            return;
        }

        $queue = $wc->session->get( SEO_GOOGLE_ECOMMERCE_QUEUE_KEY, [] );
        $queue = is_array( $queue ) ? $queue : [];

        $fingerprint = md5( $event_name . '|' . wp_json_encode( $params ) );
        $now = time();
        foreach ( array_reverse( $queue ) as $queued ) {
            if ( (string) ( $queued['fingerprint'] ?? '' ) === $fingerprint && ( $now - absint( $queued['queued_at'] ?? 0 ) ) < 3 ) {
                return;
            }
        }

        $queue[] = [
            'event'       => $event_name,
            'params'      => $params,
            'queued_at'   => $now,
            'fingerprint' => $fingerprint,
        ];
        if ( count( $queue ) > 20 ) {
            $queue = array_slice( $queue, -20 );
        }

        $wc->session->set( SEO_GOOGLE_ECOMMERCE_QUEUE_KEY, $queue );
    }
}

if ( ! function_exists( 'seo_google_ecommerce_pop_queue' ) ) {
    function seo_google_ecommerce_pop_queue() {
        $wc = seo_google_ecommerce_wc_runtime();
        if ( ! $wc || empty( $wc->session ) ) {
            return [];
        }

        $queue = $wc->session->get( SEO_GOOGLE_ECOMMERCE_QUEUE_KEY, [] );
        $queue = is_array( $queue ) ? $queue : [];
        $wc->session->set( SEO_GOOGLE_ECOMMERCE_QUEUE_KEY, [] );

        $events = [];
        foreach ( $queue as $queued ) {
            $event = sanitize_key( (string) ( $queued['event'] ?? '' ) );
            $params = isset( $queued['params'] ) && is_array( $queued['params'] ) ? $queued['params'] : [];
            if ( '' !== $event && ! empty( $params['items'] ) ) {
                $events[] = [ 'event' => $event, 'params' => $params ];
            }
        }
        return $events;
    }
}

if ( ! function_exists( 'seo_google_ecommerce_queue_add_to_cart' ) ) {
    function seo_google_ecommerce_queue_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = [], $cart_item_data = [] ) {
        unset( $cart_item_key, $variation, $cart_item_data );
        if ( ! seo_google_ecommerce_tracking_active() ) {
            return;
        }
        $product = wc_get_product( absint( $variation_id ) ?: absint( $product_id ) );
        if ( ! $product ) {
            return;
        }
        $quantity = max( 1, (float) $quantity );
        $price = function_exists( 'wc_get_price_excluding_tax' ) ? wc_get_price_excluding_tax( $product, [ 'qty' => 1 ] ) : (float) $product->get_price();
        $item = seo_google_ecommerce_item( $product, $quantity, $price );
        if ( ! $item ) {
            return;
        }
        seo_google_ecommerce_queue_event( 'add_to_cart', [
            'currency' => seo_google_ecommerce_currency(),
            'value'    => seo_google_ecommerce_round( (float) $item['price'] * $quantity ),
            'items'    => [ $item ],
        ] );
    }
    add_action( 'woocommerce_add_to_cart', 'seo_google_ecommerce_queue_add_to_cart', 20, 6 );
}

if ( ! function_exists( 'seo_google_ecommerce_queue_removed_item' ) ) {
    function seo_google_ecommerce_queue_removed_item( $cart_item_key, $cart ) {
        if ( ! seo_google_ecommerce_tracking_active() || ! is_object( $cart ) ) {
            return;
        }
        $removed = isset( $cart->removed_cart_contents[ $cart_item_key ] ) ? $cart->removed_cart_contents[ $cart_item_key ] : null;
        if ( ! is_array( $removed ) || empty( $removed['data'] ) || ! is_object( $removed['data'] ) ) {
            return;
        }
        $product = $removed['data'];
        $quantity = max( 1, (float) ( $removed['quantity'] ?? 1 ) );
        $line_total = isset( $removed['line_total'] ) ? max( 0, (float) $removed['line_total'] ) : 0.0;
        $line_subtotal = isset( $removed['line_subtotal'] ) ? max( 0, (float) $removed['line_subtotal'] ) : $line_total;
        $price = $quantity > 0 ? $line_total / $quantity : 0.0;
        $discount = $quantity > 0 ? max( 0, ( $line_subtotal - $line_total ) / $quantity ) : 0.0;
        $item = seo_google_ecommerce_item( $product, $quantity, $price, $discount );
        if ( ! $item ) {
            return;
        }
        seo_google_ecommerce_queue_event( 'remove_from_cart', [
            'currency' => seo_google_ecommerce_currency(),
            'value'    => seo_google_ecommerce_round( $price * $quantity ),
            'items'    => [ $item ],
        ] );
    }
    add_action( 'woocommerce_cart_item_removed', 'seo_google_ecommerce_queue_removed_item', 20, 2 );
}

if ( ! function_exists( 'seo_google_ecommerce_queue_quantity_change' ) ) {
    function seo_google_ecommerce_queue_quantity_change( $cart_item_key, $quantity, $old_quantity, $cart ) {
        if ( ! seo_google_ecommerce_tracking_active() || ! is_object( $cart ) ) {
            return;
        }
        $quantity = (float) $quantity;
        $old_quantity = (float) $old_quantity;
        $delta = $quantity - $old_quantity;
        if ( 0.0 === $delta ) {
            return;
        }
        $cart_contents = method_exists( $cart, 'get_cart' ) ? $cart->get_cart() : [];
        $row = isset( $cart_contents[ $cart_item_key ] ) ? $cart_contents[ $cart_item_key ] : null;
        if ( ! is_array( $row ) || empty( $row['data'] ) || ! is_object( $row['data'] ) ) {
            return;
        }
        $product = $row['data'];
        $event_qty = abs( $delta );
        $price = function_exists( 'wc_get_price_excluding_tax' ) ? wc_get_price_excluding_tax( $product, [ 'qty' => 1 ] ) : (float) $product->get_price();
        $item = seo_google_ecommerce_item( $product, $event_qty, $price );
        if ( ! $item ) {
            return;
        }
        seo_google_ecommerce_queue_event( $delta > 0 ? 'add_to_cart' : 'remove_from_cart', [
            'currency' => seo_google_ecommerce_currency(),
            'value'    => seo_google_ecommerce_round( (float) $item['price'] * $event_qty ),
            'items'    => [ $item ],
        ] );
    }
    add_action( 'woocommerce_after_cart_item_quantity_update', 'seo_google_ecommerce_queue_quantity_change', 20, 4 );
}

if ( ! function_exists( 'seo_google_ecommerce_page_events' ) ) {
    function seo_google_ecommerce_page_events() {
        $events = [];

        if ( function_exists( 'is_product' ) && is_product() ) {
            global $post;
            $product = $post ? wc_get_product( $post->ID ) : null;
            if ( $product ) {
                $item = seo_google_ecommerce_item( $product, 1 );
                if ( $item ) {
                    $events[] = [
                        'event'  => 'view_item',
                        'params' => [
                            'currency' => seo_google_ecommerce_currency(),
                            'value'    => (float) $item['price'],
                            'items'    => [ $item ],
                        ],
                    ];
                }
            }
        }

        if ( function_exists( 'is_cart' ) && is_cart() ) {
            $payload = seo_google_ecommerce_cart_payload();
            if ( ! empty( $payload['items'] ) ) {
                $events[] = [ 'event' => 'view_cart', 'params' => $payload ];
            }
        }

        $is_order_received = function_exists( 'is_order_received_page' ) && is_order_received_page();
        if ( function_exists( 'is_checkout' ) && is_checkout() && ! $is_order_received ) {
            $payload = seo_google_ecommerce_cart_payload();
            if ( ! empty( $payload['items'] ) ) {
                $events[] = [ 'event' => 'begin_checkout', 'params' => $payload ];
            }
        }

        if ( $is_order_received || get_query_var( 'order-received' ) || isset( $GLOBALS['wp']->query_vars['order-received'] ) ) {
            $order_id = absint( get_query_var( 'order-received' ) );
            if ( ! $order_id && isset( $GLOBALS['wp']->query_vars['order-received'] ) ) {
                $order_id = absint( $GLOBALS['wp']->query_vars['order-received'] );
            }
            if ( $order_id > 0 ) {
                $order = wc_get_order( $order_id );
                $purchase_statuses = $order
                    ? (array) apply_filters( 'seo_google_ecommerce_purchase_statuses', [ 'processing', 'completed', 'on-hold' ], $order )
                    : [];
                if ( $order && $purchase_statuses && $order->has_status( $purchase_statuses ) ) {
                    $payload = seo_google_ecommerce_order_payload( $order );
                    if ( ! empty( $payload['transaction_id'] ) && ! empty( $payload['items'] ) ) {
                        $events[] = [ 'event' => 'purchase', 'params' => $payload ];
                    }
                }
            }
        }

        return $events;
    }
}

if ( ! function_exists( 'seo_google_ecommerce_ajax_pop_queue' ) ) {
    function seo_google_ecommerce_ajax_pop_queue() {
        if ( ! check_ajax_referer( 'seo_google_ecommerce_pop', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Nonce no valido.' ], 403 );
        }
        if ( ! seo_google_ecommerce_tracking_active() ) {
            wp_send_json_success( [ 'events' => [] ] );
        }
        wp_send_json_success( [ 'events' => seo_google_ecommerce_pop_queue() ] );
    }
    add_action( 'wp_ajax_seo_google_ecommerce_pop_queue', 'seo_google_ecommerce_ajax_pop_queue' );
    add_action( 'wp_ajax_nopriv_seo_google_ecommerce_pop_queue', 'seo_google_ecommerce_ajax_pop_queue' );
}

if ( ! function_exists( 'seo_google_ecommerce_frontend_events' ) ) {
    function seo_google_ecommerce_frontend_events() {
        if ( ! seo_google_ecommerce_tracking_active() ) {
            return;
        }

        $page_events = array_merge( seo_google_ecommerce_pop_queue(), seo_google_ecommerce_page_events() );
        $checkout = seo_google_ecommerce_cart_payload();
        $ajax_url = admin_url( 'admin-ajax.php' );
        $nonce = wp_create_nonce( 'seo_google_ecommerce_pop' );
        ?>
        <!-- SEO System: GA4 ecommerce v<?php echo esc_html( SEO_GOOGLE_ECOMMERCE_VERSION ); ?> -->
        <script>
        (function(){
            'use strict';
            var pageEvents = <?php echo wp_json_encode( $page_events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?> || [];
            var checkoutPayload = <?php echo wp_json_encode( $checkout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?> || {};
            var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
            var ajaxNonce = <?php echo wp_json_encode( $nonce ); ?>;

            function canSend(){ return typeof window.gtag === 'function'; }
            function eventKey(name, params){
                if(name === 'purchase' && params && params.transaction_id){ return 'purchase:' + params.transaction_id; }
                return '';
            }
            function alreadySent(key){
                if(!key) return false;
                try { return window.sessionStorage.getItem('seo_ga4_' + key) === '1'; } catch(e) { return false; }
            }
            function markSent(key){
                if(!key) return;
                try { window.sessionStorage.setItem('seo_ga4_' + key, '1'); } catch(e) {}
            }
            function send(name, params, key){
                if(!name || !params || !canSend()) return false;
                key = key || eventKey(name, params);
                if(alreadySent(key)) return true;
                window.gtag('event', name, params);
                markSent(key);
                return true;
            }
            function flush(events){
                (events || []).forEach(function(row){
                    if(row && row.event && row.params) send(row.event, row.params);
                });
            }
            function flushServerQueue(){
                if(!window.fetch) return;
                var body = new URLSearchParams();
                body.set('action', 'seo_google_ecommerce_pop_queue');
                body.set('nonce', ajaxNonce);
                window.fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                }).then(function(response){ return response.json(); })
                  .then(function(json){ if(json && json.success && json.data) flush(json.data.events || []); })
                  .catch(function(){});
            }

            function checkoutStep(name, extra){
                if(!checkoutPayload || !checkoutPayload.items || !checkoutPayload.items.length) return;
                var params = Object.assign({}, checkoutPayload, extra || {});
                var extraValue = '';
                if(extra && extra.shipping_tier) extraValue = String(extra.shipping_tier);
                if(extra && extra.payment_type) extraValue = String(extra.payment_type);
                var cartKey = (checkoutPayload.items || []).map(function(item){ return String(item.item_id) + 'x' + String(item.quantity || 1); }).join('-');
                send(name, params, name + ':' + cartKey + ':' + extraValue);
            }

            function selectedShipping(){
                var el = document.querySelector('input[name^="shipping_method"]:checked, select[name^="shipping_method"]');
                if(!el) return '';
                var label = '';
                if(el.id){
                    var lab = document.querySelector('label[for="' + CSS.escape(el.id) + '"]');
                    if(lab) label = lab.textContent || '';
                }
                return String(label || el.value || '').trim();
            }
            function selectedPayment(){
                var el = document.querySelector('input[name="payment_method"]:checked');
                if(!el) return '';
                var label = '';
                if(el.id){
                    var lab = document.querySelector('label[for="' + CSS.escape(el.id) + '"]');
                    if(lab) label = lab.textContent || '';
                }
                return String(label || el.value || '').replace(/\s+/g,' ').trim();
            }
            function sendCheckoutSteps(){
                var shipping = selectedShipping();
                if(shipping) checkoutStep('add_shipping_info', {shipping_tier: shipping});
                var payment = selectedPayment();
                if(payment) checkoutStep('add_payment_info', {payment_type: payment});
            }

            function start(){
                flush(pageEvents);

                document.addEventListener('submit', function(ev){
                    var form = ev.target;
                    if(form && form.matches && form.matches('form.checkout, form.woocommerce-checkout')){
                        sendCheckoutSteps();
                    }
                }, true);

                if(window.jQuery){
                    window.jQuery(function($){
                        $(document.body).on('added_to_cart removed_from_cart', function(){
                            window.setTimeout(flushServerQueue, 50);
                        });
                        $('form.checkout').on('checkout_place_order', function(){
                            sendCheckoutSteps();
                            return true;
                        });
                    });
                }
            }

            if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once:true});
            else start();
        })();
        </script>
        <?php
    }
    add_action( 'wp_footer', 'seo_google_ecommerce_frontend_events', 50 );
}
