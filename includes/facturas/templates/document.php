<?php
/** @var array $snapshot */
defined('ABSPATH') || exit;

$document = $snapshot['document'] ?? array();
$seller = $snapshot['seller'] ?? array();
$order = $snapshot['order'] ?? array();
$billing = $snapshot['billing'] ?? array();
$shipping = $snapshot['shipping'] ?? array();
$items = $snapshot['items'] ?? array();
$tax_lines = $snapshot['tax_lines'] ?? array();
$fiscal = $snapshot['fiscal'] ?? array();
$totals = $snapshot['totals'] ?? array();
$currency = (string) ($order['currency'] ?? 'EUR');
$type = sanitize_key((string) ($document['type'] ?? ''));
$is_invoice = SEO_Facturas_Documents::TYPE_INVOICE === $type;
$title = trim((string) ($document['title'] ?? ($is_invoice ? 'FACTURA' : 'FACTURA PROFORMA')));
$show_order_reference = !array_key_exists('show_order_reference', $document) || !empty($document['show_order_reference']);
$show_payment_method = !array_key_exists('show_payment_method', $document) || !empty($document['show_payment_method']);
$show_sku = !array_key_exists('show_sku', $document) || !empty($document['show_sku']);

$money = static function ($amount) use ($currency) {
    $amount = (float) $amount;
    if (function_exists('wc_price')) {
        $formatted = wc_price($amount, array('currency' => $currency));
        return html_entity_decode(wp_strip_all_tags($formatted), ENT_QUOTES, 'UTF-8');
    }
    return number_format($amount, 2, ',', '.') . ' ' . $currency;
};

$person_name = static function ($address) {
    $company = trim((string) ($address['company'] ?? ''));
    if ('' !== $company) {
        return $company;
    }
    return trim((string) ($address['first_name'] ?? '') . ' ' . (string) ($address['last_name'] ?? ''));
};

$address_lines = static function ($address) {
    $lines = array();
    foreach (array(
        trim((string) ($address['address_1'] ?? '')),
        trim((string) ($address['address_2'] ?? '')),
        trim((string) ($address['postcode'] ?? '') . ' ' . (string) ($address['city'] ?? '')),
        trim((string) ($address['state_name'] ?? $address['state'] ?? '')),
        trim((string) ($address['country_name'] ?? $address['country'] ?? '')),
    ) as $line) {
        if ('' !== $line) {
            $lines[] = $line;
        }
    }
    return $lines;
};

$issued_ts = strtotime((string) ($document['issued_at'] ?? ''));
$issued_date = $issued_ts ? wp_date(get_option('date_format', 'd/m/Y'), $issued_ts) : (string) ($document['issued_at'] ?? '');
$created_ts = strtotime((string) ($order['created_at'] ?? ''));
$order_date = $created_ts ? wp_date(get_option('date_format', 'd/m/Y'), $created_ts) : (string) ($order['created_at'] ?? '');
$document_footer = trim((string) ($document['footer_text'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 26px 30px 34px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; line-height: 1.35; }
    .header { width: 100%; margin-bottom: 16px; }
    .header td { vertical-align: middle; }
    .logo { max-width: 105px; max-height: 52px; }
    .brand { text-align: center; font-size: 15px; font-weight: 700; }
    .brand small { display: block; font-size: 9px; font-weight: 400; margin-top: 3px; }
    .contact { text-align: right; font-size: 10px; }
    h1 { font-size: 20px; margin: 3px 0 12px; color: #244f61; }
    h2 { font-size: 14px; margin: 18px 0 7px; color: #244f61; }
    table { border-collapse: collapse; width: 100%; }
    .parties td { width: 50%; border: 1px solid #555; vertical-align: top; padding: 7px; }
    .parties .head { background: #f2f2f2; font-weight: 700; padding-bottom: 4px; }
    .details td { padding: 3px 0; vertical-align: top; }
    .details td:first-child { width: 170px; font-weight: 700; }
    .products th, .products td { border: 1px solid #555; padding: 5px; vertical-align: top; }
    .products th { background: #f2f2f2; font-size: 9px; text-align: center; }
    .products .num { text-align: right; white-space: nowrap; }
    .products .qty { text-align: center; width: 55px; }
    .products .ref { width: 120px; }
    .totals { width: 56%; margin-left: auto; margin-top: 14px; }
    .totals td { border: 1px solid #666; padding: 5px 7px; }
    .totals td:last-child { text-align: right; white-space: nowrap; }
    .totals .grand td { font-weight: 700; font-size: 11px; }
    .warning { border: 2px solid #a33; color: #8c1d1d; padding: 8px; text-align: center; font-weight: 700; margin-bottom: 12px; }
    .payment-box { margin-top: 18px; border: 1px solid #9aa7ad; background: #f7f9fa; padding: 9px 11px; }
    .payment-box strong { color: #244f61; }
    .fiscal-note { margin-top: 14px; border: 1px solid #d6b45e; background: #fffaf0; padding: 8px 10px; }
    .muted { color: #666; }
    .footer { margin-top: 26px; border-top: 1px solid #bbb; padding-top: 7px; text-align: center; font-size: 8.5px; color: #555; }
</style>
</head>
<body>

<table class="header">
    <tr>
        <td style="width:25%;">
            <?php if (!empty($seller['logo_data_uri'])) : ?>
                <img class="logo" src="<?php echo esc_attr($seller['logo_data_uri']); ?>" alt="">
            <?php endif; ?>
        </td>
        <td class="brand" style="width:50%;">
            <?php echo esc_html($seller['trade_name'] ?: ($seller['website'] ?? '')); ?>
            <small><?php echo esc_html($seller['email'] ?? ''); ?></small>
        </td>
        <td class="contact" style="width:25%;"><?php echo esc_html($seller['phone'] ?? ''); ?></td>
    </tr>
</table>

<h1><?php echo esc_html($title); ?></h1>

<?php if (!$is_invoice) : ?>
    <div class="warning">DOCUMENTO PROFORMA - SIN VALIDEZ FISCAL</div>
<?php endif; ?>

<table class="parties">
    <tr>
        <td>
            <div class="head">Vendido por:</div>
            <strong><?php echo esc_html($seller['name'] ?? ''); ?></strong><br>
            <?php if (!empty($seller['tax_id'])) : ?><?php echo esc_html($seller['tax_id']); ?><br><?php endif; ?>
            <?php if (!empty($seller['address'])) : ?><?php echo esc_html($seller['address']); ?><br><?php endif; ?>
            <?php if (trim((string) ($seller['postcode'] ?? '') . ' ' . (string) ($seller['city'] ?? ''))) : ?><?php echo esc_html(trim((string) ($seller['postcode'] ?? '') . ' ' . (string) ($seller['city'] ?? ''))); ?><br><?php endif; ?>
            <?php if (!empty($seller['region'])) : ?><?php echo esc_html($seller['region']); ?><br><?php endif; ?>
            <?php if (!empty($seller['country_name'] ?? $seller['country'])) : ?><?php echo esc_html($seller['country_name'] ?? $seller['country']); ?><br><?php endif; ?>
            <?php if (!empty($seller['phone'])) : ?>Tel.: <?php echo esc_html($seller['phone']); ?><br><?php endif; ?>
            <?php if (!empty($seller['email'])) : ?><?php echo esc_html($seller['email']); ?><?php endif; ?>
        </td>
        <td>
            <div class="head">Vendido a:</div>
            <strong><?php echo esc_html($person_name($billing)); ?></strong><br>
            <?php if (!empty($billing['tax_id'])) : ?><?php echo esc_html($billing['tax_id']); ?><br><?php endif; ?>
            <?php foreach ($address_lines($billing) as $line) : ?><?php echo esc_html($line); ?><br><?php endforeach; ?>
            <?php if (!empty($billing['email'])) : ?><?php echo esc_html($billing['email']); ?><br><?php endif; ?>
            <?php if (!empty($billing['phone'])) : ?><?php echo esc_html($billing['phone']); ?><?php endif; ?>
        </td>
    </tr>
</table>

<h2>Detalle del documento</h2>
<table class="details">
    <tr><td>Fecha de expedicion:</td><td><?php echo esc_html($issued_date); ?></td></tr>
    <tr><td>Numero:</td><td><strong><?php echo esc_html($document['number'] ?? ''); ?></strong></td></tr>
    <?php if ($show_order_reference) : ?>
        <tr><td>Pedido WooCommerce:</td><td>#<?php echo esc_html($order['number'] ?? $order['id'] ?? ''); ?></td></tr>
        <tr><td>Fecha del pedido:</td><td><?php echo esc_html($order_date); ?></td></tr>
    <?php endif; ?>
    <?php if ($show_payment_method) : ?>
        <tr><td>Forma de pago:</td><td><?php echo esc_html($order['payment_method_title'] ?? $order['payment_method'] ?? ''); ?></td></tr>
    <?php endif; ?>
    <tr><td>Base imponible total:</td><td><?php echo esc_html($money($totals['base_total'] ?? 0)); ?></td></tr>
    <tr><td>Impuestos:</td><td><?php echo esc_html($money($totals['total_tax'] ?? 0)); ?></td></tr>
    <tr><td>Total:</td><td><strong><?php echo esc_html($money($totals['total'] ?? 0)); ?></strong></td></tr>
</table>

<?php if (!empty(array_filter($shipping))) : ?>
<h2>Direccion de envio</h2>
<div>
    <strong><?php echo esc_html($person_name($shipping)); ?></strong><br>
    <?php foreach ($address_lines($shipping) as $line) : ?><?php echo esc_html($line); ?><br><?php endforeach; ?>
</div>
<?php endif; ?>

<h2>Detalle de los productos</h2>
<table class="products">
    <thead>
        <tr>
            <?php if ($show_sku) : ?><th class="ref">REFERENCIA</th><?php endif; ?>
            <th>CONCEPTO</th>
            <th class="qty">CANTIDAD</th>
            <th>PRECIO UNIDAD<br><span class="muted">sin impuestos</span></th>
            <th>SUBTOTAL<br><span class="muted">sin impuestos</span></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $item) : ?>
        <tr>
            <?php if ($show_sku) : ?><td class="ref"><?php echo esc_html($item['sku'] ?: ('ID ' . ($item['product_id'] ?? ''))); ?></td><?php endif; ?>
            <td><?php echo esc_html($item['name'] ?? ''); ?></td>
            <td class="qty"><?php echo esc_html($item['quantity'] ?? 0); ?></td>
            <td class="num"><?php echo esc_html($money($item['unit_net'] ?? 0)); ?></td>
            <td class="num"><?php echo esc_html($money($item['total'] ?? 0)); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal productos</td><td><?php echo esc_html($money($totals['subtotal_items'] ?? 0)); ?></td></tr>
    <?php if (!empty($totals['discount_total'])) : ?><tr><td>Descuentos</td><td>-<?php echo esc_html($money($totals['discount_total'])); ?></td></tr><?php endif; ?>
    <tr><td>Transporte</td><td><?php echo esc_html($money($totals['shipping_total'] ?? 0)); ?></td></tr>
    <?php if (!empty($totals['fee_total'])) : ?><tr><td>Otros cargos</td><td><?php echo esc_html($money($totals['fee_total'])); ?></td></tr><?php endif; ?>
    <tr><td>BASE IMPONIBLE</td><td><?php echo esc_html($money($totals['base_total'] ?? 0)); ?></td></tr>
    <?php if ($tax_lines) : ?>
        <?php foreach ($tax_lines as $tax) : ?>
            <?php $tax_amount = (float) ($tax['tax_total'] ?? 0) + (float) ($tax['shipping_tax_total'] ?? 0); ?>
            <?php
            $tax_label = (string) ($tax['label'] ?? 'IVA');
            $rate_percent = $tax['rate_percent'] ?? null;
            if (null !== $rate_percent && false === strpos($tax_label, '%')) {
                $tax_label .= ' ' . rtrim(rtrim(number_format((float) $rate_percent, 3, '.', ''), '0'), '.') . '%';
            }
            ?>
            <tr>
                <td><?php echo esc_html($tax_label); ?></td>
                <td><?php echo esc_html($money($tax_amount)); ?></td>
            </tr>
        <?php endforeach; ?>
    <?php else : ?>
        <?php $fallback_tax_label = !empty($fiscal['enabled']) && !empty($fiscal['label']) ? (string) $fiscal['label'] : 'IMPUESTOS'; ?>
        <tr><td><?php echo esc_html($fallback_tax_label); ?></td><td><?php echo esc_html($money($totals['total_tax'] ?? 0)); ?></td></tr>
    <?php endif; ?>
    <tr class="grand"><td><?php echo esc_html($is_invoice ? 'TOTAL FACTURA' : 'TOTAL PROFORMA'); ?></td><td><?php echo esc_html($money($totals['total'] ?? 0)); ?></td></tr>
</table>

<?php if (!empty($fiscal['enabled']) && !empty($fiscal['note'])) : ?>
    <div class="fiscal-note"><strong>Condiciones fiscales del destino:</strong><br><?php echo nl2br(esc_html($fiscal['note'])); ?></div>
<?php endif; ?>

<?php if (!$is_invoice && !empty($document['show_payment_info'])) : ?>
    <div class="payment-box">
        <strong>Informacion de pago</strong><br>
        <?php if (!empty($document['beneficiary'])) : ?>Beneficiario: <?php echo esc_html($document['beneficiary']); ?><br><?php endif; ?>
        <?php if (!empty($document['iban'])) : ?>IBAN: <?php echo esc_html($document['iban']); ?><br><?php endif; ?>
        <?php if (!empty($document['bizum'])) : ?>Bizum: <?php echo esc_html($document['bizum']); ?><br><?php endif; ?>
        <?php if (!empty($document['payment_instructions'])) : ?><br><?php echo nl2br(esc_html($document['payment_instructions'])); ?><?php endif; ?>
    </div>
<?php endif; ?>

<div class="footer">
    <?php if ('' !== $document_footer) : ?><?php echo nl2br(esc_html($document_footer)); ?><br><?php endif; ?>
    <?php if (!empty($seller['footer_text'])) : ?><?php echo nl2br(esc_html($seller['footer_text'])); ?><br><?php endif; ?>
    <?php echo esc_html($seller['website'] ?? ''); ?>
    <?php if (!empty($seller['phone'])) : ?> &nbsp;|&nbsp; <?php echo esc_html($seller['phone']); ?><?php endif; ?>
</div>

</body>
</html>
