<?php
/**
 * Rectifying invoice by substitution, with immutable original-reference data.
 *
 * The original line-level net prices are deliberately not reproduced: a
 * historical order with zero VAT would show materially wrong unit net prices.
 *
 * @var array $snapshot Verified immutable replacement snapshot.
 */
defined( 'ABSPATH' ) || exit;
$doc = (array) ( $snapshot['document'] ?? array() );
$order = (array) ( $snapshot['order'] ?? array() );
$seller = (array) ( $snapshot['seller'] ?? array() );
$buyer = (array) ( $snapshot['billing'] ?? array() );
$rect = (array) ( $snapshot['rectification'] ?? array() );
$totals = (array) ( $snapshot['totals'] ?? array() );
$items = (array) ( $snapshot['items'] ?? array() );
$currency = (string) ( $order['currency'] ?? 'EUR' );
$money = static function ( $value ) use ( $currency ) {
    if ( function_exists( 'wc_price' ) ) {
        return html_entity_decode( wp_strip_all_tags( wc_price( (float) $value, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
    }
    return number_format( (float) $value, 2, ',', '.' ) . ' ' . $currency;
};
$buyer_name = trim( (string) ( $buyer['company'] ?? '' ) );
if ( '' === $buyer_name ) {
    $buyer_name = trim( (string) ( $buyer['first_name'] ?? '' ) . ' ' . (string) ( $buyer['last_name'] ?? '' ) );
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 34px 36px 42px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color:#222; line-height:1.5; }
    h1 { color:#244f61; font-size:19px; margin:0 0 8px; }
    h2 { color:#244f61; font-size:12px; margin:15px 0 5px; }
    .small { color:#555; font-size:9px; }
    .parties { width:100%; border-collapse:collapse; }
    .parties td { width:50%; vertical-align:top; border:1px solid #aaa; padding:9px; }
    .block { border:1px solid #aaa; padding:8px 11px; margin:12px 0; }
    table.amounts { width:100%; border-collapse:collapse; margin-top:12px; }
    .amounts th, .amounts td { border:1px solid #aaa; padding:7px; }
    .amounts th { text-align:left; background:#f0f4f5; }
    .amounts td.num { text-align:right; white-space:nowrap; }
    .note { border-left:3px solid #244f61; background:#f5f7f8; padding:10px; margin:12px 0; }
    .footer { margin-top:18px; border-top:1px solid #ccc; color:#555; font-size:9px; padding-top:6px; }
</style>
</head>
<body>
<h1><?php echo esc_html__( 'RECTIFYING INVOICE — BY SUBSTITUTION', 'taxocommerce-facturacion' ); ?></h1>
<p><strong><?php echo esc_html__( 'Rectifying invoice number', 'taxocommerce-facturacion' ); ?>:</strong> <?php echo esc_html( $doc['number'] ?? '' ); ?><br>
<strong><?php echo esc_html__( 'Issue date', 'taxocommerce-facturacion' ); ?>:</strong> <?php echo esc_html( $doc['issued_at'] ?? '' ); ?><br>
<strong><?php echo esc_html__( 'Corrected original invoice', 'taxocommerce-facturacion' ); ?>:</strong> <?php echo esc_html( $rect['original_number'] ?? '' ); ?><br>
<strong><?php echo esc_html__( 'Original invoice issue date', 'taxocommerce-facturacion' ); ?>:</strong> <?php echo esc_html( $rect['original_issued_at'] ?? '' ); ?><br>
<strong><?php echo esc_html__( 'WooCommerce order reference', 'taxocommerce-facturacion' ); ?>:</strong> #<?php echo esc_html( $order['number'] ?? $order['id'] ?? '' ); ?></p>

<table class="parties"><tr>
<td><strong><?php echo esc_html__( 'Seller', 'taxocommerce-facturacion' ); ?></strong><br>
<?php echo esc_html( $seller['name'] ?? '' ); ?><br>
<?php echo esc_html( $seller['tax_id'] ?? '' ); ?><br>
<?php echo esc_html( $seller['address'] ?? '' ); ?><br>
<?php echo esc_html( trim( (string) ( $seller['postcode'] ?? '' ) . ' ' . (string) ( $seller['city'] ?? '' ) ) ); ?><br>
<?php echo esc_html( $seller['country_name'] ?? $seller['country'] ?? '' ); ?></td>
<td><strong><?php echo esc_html__( 'Customer', 'taxocommerce-facturacion' ); ?></strong><br>
<?php echo esc_html( $buyer_name ); ?><br>
<?php echo esc_html( $buyer['tax_id'] ?? '' ); ?><br>
<?php echo esc_html( $buyer['address_1'] ?? '' ); ?><br>
<?php echo esc_html( trim( (string) ( $buyer['postcode'] ?? '' ) . ' ' . (string) ( $buyer['city'] ?? '' ) ) ); ?><br>
<?php echo esc_html( $buyer['country_name'] ?? $buyer['country'] ?? '' ); ?></td>
</tr></table>

<h2><?php echo esc_html__( 'Corrected transaction', 'taxocommerce-facturacion' ); ?></h2>
<p class="small">
<?php foreach ( $items as $item ) : ?>
    <?php echo esc_html( (string) ( $item['quantity'] ?? '' ) . ' × ' . (string) ( $item['name'] ?? '' ) ); ?><br>
<?php endforeach; ?>
</p>
<p class="small"><?php echo esc_html__( 'The original sales items and gross price are unchanged. The amounts below replace only the VAT breakdown.', 'taxocommerce-facturacion' ); ?></p>
<div class="note"><strong><?php echo esc_html__( 'Reason for correction', 'taxocommerce-facturacion' ); ?>:</strong>
<?php echo nl2br( esc_html( $rect['reason'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nl2br on escaped text. ?>
</div>
<table class="amounts">
<thead><tr>
<th><?php echo esc_html__( 'Amounts', 'taxocommerce-facturacion' ); ?></th>
<th><?php echo esc_html__( 'Original invoice', 'taxocommerce-facturacion' ); ?></th>
<th><?php echo esc_html__( 'Corrected invoice', 'taxocommerce-facturacion' ); ?></th>
<th><?php echo esc_html__( 'Difference', 'taxocommerce-facturacion' ); ?></th>
</tr></thead>
<tbody>
<?php foreach ( array(
    'base_total' => array( __( 'Taxable base', 'taxocommerce-facturacion' ), 'old_base' ),
    'total_tax' => array( __( 'VAT', 'taxocommerce-facturacion' ), 'old_tax' ),
    'total' => array( __( 'Total', 'taxocommerce-facturacion' ), 'old_total' ),
) as $key => $entry ) : ?>
    <?php $old = (float) ( $rect[ $entry[1] ] ?? 0 ); $new = (float) ( $totals[ $key ] ?? 0 ); ?>
    <tr><td><?php echo esc_html( $entry[0] ); ?></td>
    <td class="num"><?php echo esc_html( $money( $old ) ); ?></td>
    <td class="num"><?php echo esc_html( $money( $new ) ); ?></td>
    <td class="num"><?php echo esc_html( $money( $new - $old ) ); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<p><strong><?php echo esc_html__( 'Corrected VAT rate', 'taxocommerce-facturacion' ); ?>:</strong> <?php echo esc_html( number_format_i18n( (float) ( $rect['rate'] ?? 0 ), 2 ) ); ?> %</p>
<p><strong><?php echo esc_html__( 'Additional payment due from the customer', 'taxocommerce-facturacion' ); ?>:</strong> <?php echo esc_html( $money( 0 ) ); ?></p>
<p class="footer"><?php echo esc_html__( 'This rectifying invoice must be kept together with the original invoice. It does not modify the WooCommerce order or the original receipt.', 'taxocommerce-facturacion' ); ?></p>
</body></html>
