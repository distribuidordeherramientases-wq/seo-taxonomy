<?php
/**
 * Read-only order-to-supplier purchasing details for Logistics.
 *
 * Purchasing data belongs to Suppliers. This view consumes its public API;
 * Logistics keeps working when the optional plugin is not active.
 *
 * @package TaxoCommerceLogistica
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Logistica_Purchasing {
    public static function render() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You are not allowed to view purchases.', 'taxocommerce-logistica' ) );
        }
        if ( ! class_exists( 'TCSUP_Purchasing' ) || ! is_callable( array( 'TCSUP_Purchasing', 'sources_for_line' ) ) ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Activate TaxoCommerce Suppliers to see supplier references and purchase links. Shipping works independently.', 'taxocommerce-logistica' ) . '</p></div>';
            return;
        }
        if ( ! function_exists( 'wc_get_orders' ) ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'WooCommerce must be active.', 'taxocommerce-logistica' ) . '</p></div>';
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only administrator order filter.
        $selected_order = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
        $orders = $selected_order
            ? array_filter( array( wc_get_order( $selected_order ) ) )
            : wc_get_orders( array(
                'status'  => array( 'pending', 'on-hold', 'processing' ),
                'limit'   => 100,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'objects',
            ) );
        if ( $selected_order && ! empty( $orders ) ) {
            $order = reset( $orders );
            if ( ! in_array( $order->get_status(), array( 'pending', 'on-hold', 'processing' ), true ) ) {
                $orders = array();
            }
        }

        echo '<div class="seo-transport-panel">';
        echo '<h2>' . esc_html__( 'Supplier purchases for WooCommerce orders', 'taxocommerce-logistica' ) . '</h2>';
        echo '<p>' . esc_html__( 'Open Purchase to visit the exact supplier product page. Supplier selection and purchase execution remain outside Logistics.', 'taxocommerce-logistica' ) . '</p>';
        if ( $selected_order ) {
            echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'taxocommerce-logistica', 'tc_logistica_tab' => 'purchasing' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'All active orders', 'taxocommerce-logistica' ) . '</a></p>';
        }

        $count = 0;
        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr>';
        foreach ( array(
            __( 'Order', 'taxocommerce-logistica' ),
            __( 'WooCommerce product', 'taxocommerce-logistica' ),
            __( 'Qty.', 'taxocommerce-logistica' ),
            __( 'Supplier', 'taxocommerce-logistica' ),
            __( 'Supplier ID', 'taxocommerce-logistica' ),
            __( 'Supplier SKU', 'taxocommerce-logistica' ),
            __( 'Supplier product', 'taxocommerce-logistica' ),
            __( 'Supplier price', 'taxocommerce-logistica' ),
            __( 'Purchase', 'taxocommerce-logistica' ),
        ) as $heading ) {
            echo '<th scope="col">' . esc_html( $heading ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $orders as $order ) {
            if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_items' ) ) ) {
                continue;
            }
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                $product_id = absint( $item->get_product_id() );
                $variation_id = absint( $item->get_variation_id() );
                $product = $item->get_product();
                $match = TCSUP_Purchasing::sources_for_line( array(
                    'object_id'  => $variation_id ?: $product_id,
                    'product_id' => $product_id,
                ) );
                $sources = ! empty( $match['rows'] ) ? $match['rows'] : array( null );
                foreach ( $sources as $source ) {
                    ++$count;
                    $edit_url = $order->get_edit_order_url();
                    $product_url = $product_id ? get_edit_post_link( $product_id ) : '';
                    $price = '';
                    $url = '';
                    if ( $source ) {
                        $price = '' !== (string) ( $source['precio_con_iva'] ?? '' ) ? (string) $source['precio_con_iva'] : (string) ( $source['precio_sin_iva'] ?? '' );
                        $url = (string) ( $source['url_origen'] ?: ( $source['url_canonica'] ?? '' ) );
                    }
                    echo '<tr>';
                    echo '<td><a href="' . esc_url( $edit_url ) . '">#' . esc_html( $order->get_id() ) . '</a></td>';
                    echo '<td>';
                    if ( $product_url ) {
                        echo '<a href="' . esc_url( $product_url ) . '">' . esc_html( $item->get_name() ) . '</a>';
                    } else {
                        echo esc_html( $item->get_name() );
                    }
                    if ( $product ) {
                        echo '<br><small>' . esc_html( $product->get_sku() ) . '</small>';
                    }
                    if ( 'parent' === ( $match['match_type'] ?? '' ) ) {
                        echo '<br><small>' . esc_html__( 'Supplier source inherited from parent product', 'taxocommerce-logistica' ) . '</small>';
                    }
                    echo '</td>';
                    echo '<td>' . esc_html( wc_format_decimal( $item->get_quantity(), 2 ) ) . '</td>';
                    if ( ! $source ) {
                        echo '<td colspan="6"><strong>' . esc_html__( 'No supplier source linked to this product.', 'taxocommerce-logistica' ) . '</strong></td>';
                    } else {
                        echo '<td>' . esc_html( (string) ( $source['proveedor'] ?? '' ) ) . '</td>';
                        echo '<td><code>' . esc_html( (string) ( $source['proveedor_id_externo'] ?? '' ) ) . '</code></td>';
                        echo '<td><code>' . esc_html( (string) ( $source['sku'] ?? '' ) ) . '</code></td>';
                        echo '<td>' . esc_html( (string) ( $source['nombre'] ?? '' ) ) . '</td>';
                        echo '<td>' . esc_html( $price ) . ' ' . esc_html( (string) ( $source['moneda'] ?? '' ) ) . '</td>';
                        echo '<td>';
                        if ( $url && wp_http_validate_url( $url ) ) {
                            echo '<a class="button button-primary" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Purchase', 'taxocommerce-logistica' ) . '</a>';
                        } else {
                            echo esc_html__( 'No supplier purchase link', 'taxocommerce-logistica' );
                        }
                        echo '</td>';
                    }
                    echo '</tr>';
                }
            }
        }
        if ( ! $count ) {
            echo '<tr><td colspan="9">' . esc_html__( 'No active order lines found.', 'taxocommerce-logistica' ) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
}
