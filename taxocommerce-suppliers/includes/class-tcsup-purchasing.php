<?php
/**
 * WooCommerce sales-to-supplier purchasing view.
 *
 * @package TaxoCommerceSuppliers
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Stable TCSUP_ public class names are an existing namespace/API contract; renaming would break extensions.
final class TCSUP_Purchasing {

	public static function active_order_statuses() {
		$statuses = array( 'pending', 'on-hold', 'processing' );
		return array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_key',
						(array) apply_filters( 'taxocommerce_suppliers_purchasing_order_statuses', $statuses )
					)
				)
			)
		);
	}

	private static function order_edit_url( $order ) {
		if ( is_object( $order ) && is_callable( array( $order, 'get_edit_order_url' ) ) ) {
			$url = $order->get_edit_order_url();
			if ( $url ) {
				return $url;
			}
		}

		$order_id = is_object( $order ) && is_callable( array( $order, 'get_id' ) )
			? absint( $order->get_id() )
			: 0;

		if ( ! $order_id ) {
			return '';
		}

		if (
			class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& is_callable( array( 'Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled' ) )
			&& Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}

		return admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	public static function collect_order_lines() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'orders' => array(),
				'lines'  => array(),
			);
		}

		$orders = wc_get_orders(
			array(
				'status'  => self::active_order_statuses(),
				'limit'   => -1,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'objects',
			)
		);

		$lines = array();

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_items' ) ) ) {
				continue;
			}

			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				if ( ! is_object( $item ) ) {
					continue;
				}

				$product_id   = absint( $item->get_product_id() );
				$variation_id = absint( $item->get_variation_id() );
				$object_id    = $variation_id ?: $product_id;
				if ( ! $object_id ) {
					continue;
				}

				$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : false;
				$sku     = $product && is_callable( array( $product, 'get_sku' ) ) ? (string) $product->get_sku() : '';

				$lines[] = array(
					'order'        => $order,
					'item_id'      => absint( $item_id ),
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'object_id'    => $object_id,
					'name'         => (string) $item->get_name(),
					'quantity'     => (float) $item->get_quantity(),
					'sku'          => $sku,
				);
			}
		}

		return array(
			'orders' => $orders,
			'lines'  => $lines,
		);
	}

	public static function sources_for_line( $line ) {
		$object_id  = absint( $line['object_id'] ?? 0 );
		$product_id = absint( $line['product_id'] ?? 0 );

		$rows = $object_id ? TCSUP_Products::sources_for_product( $object_id ) : array();
		if ( $rows ) {
			return array(
				'rows'       => $rows,
				'match_type' => 'exact',
				'matched_id' => $object_id,
			);
		}

		if ( $product_id && $product_id !== $object_id ) {
			$rows = TCSUP_Products::sources_for_product( $product_id );
			if ( $rows ) {
				return array(
					'rows'       => $rows,
					'match_type' => 'parent',
					'matched_id' => $product_id,
				);
			}
		}

		return array(
			'rows'       => array(),
			'match_type' => 'missing',
			'matched_id' => 0,
		);
	}

	private static function inventory_url( $row = array() ) {
		$args = array(
			'page' => 'taxocommerce-suppliers',
			'tab'  => 'inventory',
		);

		$needle = trim( (string) ( $row['sku'] ?? '' ) );
		if ( '' === $needle ) {
			$needle = trim( (string) ( $row['proveedor_id_externo'] ?? '' ) );
		}
		if ( '' !== $needle ) {
			$args['s'] = $needle;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function supplier_price( $row ) {
		$value = '';
		if ( isset( $row['precio_con_iva'] ) && '' !== (string) $row['precio_con_iva'] ) {
			$value = (string) $row['precio_con_iva'];
		} elseif ( isset( $row['precio_sin_iva'] ) && '' !== (string) $row['precio_sin_iva'] ) {
			$value = (string) $row['precio_sin_iva'];
		}
		if ( '' === $value ) {
			return '—';
		}
		return $value . ' ' . trim( (string) ( $row['moneda'] ?? '' ) );
	}

	private static function purchase_url( $row ) {
		$url = trim( (string) ( $row['url_origen'] ?? '' ) );
		if ( '' === $url ) {
			$url = trim( (string) ( $row['url_canonica'] ?? '' ) );
		}
		return $url;
	}

	public static function render() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_orders' ) ) {
			echo '<section class="tcsup-card"><div class="notice notice-error inline"><p>WooCommerce must be active to resolve sales into supplier purchases.</p></div></section>';
			return;
		}

		$dataset = self::collect_order_lines();
		$matched_lines = 0;
		$missing_lines = 0;

		foreach ( $dataset['lines'] as $line ) {
			$match = self::sources_for_line( $line );
			if ( empty( $match['rows'] ) ) {
				$missing_lines++;
			} else {
				$matched_lines++;
			}
		}
		?>
		<section class="tcsup-card">
			<div class="tcsup-card-head">
				<div>
					<h2>Purchasing</h2>
					<p>Active WooCommerce sales matched against accepted supplier sources. This is the purchasing/procurement layer; shipping weight and delivery cost remain in TaxoCommerce Logistics.</p>
				</div>
			</div>

			<div class="tcsup-purchasing-summary">
				<div><strong><?php echo esc_html( count( $dataset['orders'] ) ); ?></strong><span>Active sales</span></div>
				<div><strong><?php echo esc_html( count( $dataset['lines'] ) ); ?></strong><span>Order lines</span></div>
				<div><strong><?php echo esc_html( $matched_lines ); ?></strong><span>Supplier-linked</span></div>
				<div><strong><?php echo esc_html( $missing_lines ); ?></strong><span>Without source</span></div>
			</div>

			<?php if ( empty( $dataset['lines'] ) ) : ?>
				<div class="notice notice-info inline"><p>There are no pending, on-hold, or processing WooCommerce order lines to purchase.</p></div>
			<?php else : ?>
				<div class="tcsup-scroll">
					<table class="widefat striped">
						<thead>
							<tr>
								<th>Sale</th>
								<th>Status</th>
								<th>WooCommerce product</th>
								<th>Qty.</th>
								<th>Supplier</th>
								<th>Supplier ID</th>
								<th>Supplier SKU</th>
								<th>Supplier product</th>
								<th>Cost</th>
								<th>Purchase</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $dataset['lines'] as $line ) : ?>
							<?php
							$order       = $line['order'];
							$order_id    = absint( $order->get_id() );
							$order_url   = self::order_edit_url( $order );
							$order_date  = $order->get_date_created();
							$date_label  = $order_date ? wc_format_datetime( $order_date, 'd/m/Y H:i' ) : '';
							$status      = sanitize_key( (string) $order->get_status() );
							$status_name = function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status ) : $status;
							$match       = self::sources_for_line( $line );
							$rows        = ! empty( $match['rows'] ) ? $match['rows'] : array( null );
							?>
							<?php foreach ( $rows as $index => $source ) : ?>
								<tr class="<?php echo empty( $match['rows'] ) ? 'tcsup-purchasing-missing' : ''; ?>">
									<td>
										<?php if ( $order_url ) : ?><a href="<?php echo esc_url( $order_url ); ?>"><strong>#<?php echo esc_html( $order_id ); ?></strong></a><?php else : ?><strong>#<?php echo esc_html( $order_id ); ?></strong><?php endif; ?>
										<?php if ( $date_label ) : ?><br><small><?php echo esc_html( $date_label ); ?></small><?php endif; ?>
										<?php if ( $index > 0 ) : ?><br><small>Alternative source</small><?php endif; ?>
									</td>
									<td><?php echo esc_html( $status_name ); ?></td>
									<td>
										<?php $product_url = $line['product_id'] ? get_edit_post_link( absint( $line['product_id'] ), '' ) : ''; ?>
										<?php if ( $product_url ) : ?><a href="<?php echo esc_url( $product_url ); ?>"><strong><?php echo esc_html( $line['name'] ); ?></strong></a><?php else : ?><strong><?php echo esc_html( $line['name'] ); ?></strong><?php endif; ?>
										<?php if ( $line['sku'] ) : ?><br><small>Woo SKU: <code><?php echo esc_html( $line['sku'] ); ?></code></small><?php endif; ?>
										<?php if ( 'parent' === $match['match_type'] ) : ?><br><small>Source inherited from parent product #<?php echo esc_html( $match['matched_id'] ); ?></small><?php endif; ?>
									</td>
									<td><?php echo esc_html( $line['quantity'] ); ?></td>
									<?php if ( $source ) : ?>
										<td><strong><?php echo esc_html( $source['proveedor'] ?: '—' ); ?></strong></td>
										<td><?php echo esc_html( $source['proveedor_id_externo'] ?: '—' ); ?></td>
										<td><code><?php echo esc_html( $source['sku'] ?: '—' ); ?></code></td>
										<td><?php echo esc_html( $source['nombre'] ?: '—' ); ?></td>
										<td><?php echo esc_html( self::supplier_price( $source ) ); ?></td>
										<td>
											<?php $purchase_url = self::purchase_url( $source ); ?>
											<?php if ( $purchase_url ) : ?><a class="button button-primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( $purchase_url ); ?>">Purchase</a><?php endif; ?>
											<a class="button" href="<?php echo esc_url( self::inventory_url( $source ) ); ?>">Supplier row</a>
										</td>
									<?php else : ?>
										<td colspan="5"><strong>No supplier source linked.</strong> <a class="button" href="<?php echo esc_url( self::inventory_url() ); ?>">Open supplier inventory</a></td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}
}
