<?php
/** @var array $snapshot */
defined('ABSPATH') || exit;

$document = $snapshot['document'] ?? array();
$seller = $snapshot['seller'] ?? array();
$billing = $snapshot['billing'] ?? array();
$shipping = $snapshot['shipping'] ?? array();
$items = $snapshot['items'] ?? array();
$tax_lines = $snapshot['tax_lines'] ?? array();
$totals = $snapshot['totals'] ?? array();
$currency = (string) ($snapshot['cart']['currency'] ?? 'EUR');
$title = trim((string) ($document['title'] ?? 'PRESUPUESTO'));
$show_sku = !empty($document['show_sku']);
$show_tax = !empty($document['show_tax']);
$show_shipping = !empty($document['show_shipping']);
$show_discounts = !empty($document['show_discounts']);
$show_images = !empty($document['show_images']);
$warning_text = trim((string) ($document['warning_text'] ?? 'PRESUPUESTO COMERCIAL - SIN VALIDEZ FISCAL - NO RESERVA STOCK'));
$recipient_label = trim((string) ($document['recipient_label'] ?? 'Presupuesto para:'));
$detail_heading = trim((string) ($document['detail_heading'] ?? 'Detalle del presupuesto'));
$reference_label = trim((string) ($document['reference_label'] ?? 'Referencia:'));
$validity_label = trim((string) ($document['validity_label'] ?? 'Valido hasta:'));
$total_label = trim((string) ($document['total_label'] ?? 'TOTAL PRESUPUESTO'));

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
    $contact = trim((string) ($address['contact'] ?? ''));
    if ('' !== $contact) {
        return $contact;
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
$valid_ts = strtotime((string) ($document['valid_until'] ?? ''));
$issued_date = $issued_ts ? wp_date(get_option('date_format', 'd/m/Y'), $issued_ts) : '';
$valid_date = $valid_ts ? wp_date(get_option('date_format', 'd/m/Y'), $valid_ts) : '';
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
    .brand small { display:block; font-size:9px; font-weight:400; margin-top:3px; }
    .contact { text-align:right; font-size:10px; }
    h1 { font-size: 20px; margin: 3px 0 10px; color: #244f61; }
    h2 { font-size: 14px; margin: 18px 0 7px; color: #244f61; }
    table { border-collapse: collapse; width: 100%; }
    .warning { border: 2px solid #8b6f24; background:#fff9e8; color:#6b5417; padding:8px; text-align:center; font-weight:700; margin-bottom:12px; }
    .parties td { width:50%; border:1px solid #555; vertical-align:top; padding:7px; }
    .parties .head { background:#f2f2f2; font-weight:700; padding-bottom:4px; }
    .details td { padding:3px 0; vertical-align:top; }
    .details td:first-child { width:170px; font-weight:700; }
    .products th,.products td { border:1px solid #555; padding:5px; vertical-align:middle; }
    .products th { background:#f2f2f2; font-size:9px; text-align:center; }
    .products .num { text-align:right; white-space:nowrap; }
    .products .qty { text-align:center; width:55px; }
    .products .ref { width:100px; }
    .products .pic { width:45px; text-align:center; }
    .products .pic img { max-width:36px; max-height:36px; }
    .totals { width:56%; margin-left:auto; margin-top:14px; }
    .totals td { border:1px solid #666; padding:5px 7px; }
    .totals td:last-child { text-align:right; white-space:nowrap; }
    .totals .grand td { font-weight:700; font-size:11px; }
    .terms { margin-top:20px; padding:9px 11px; border:1px solid #c8c8c8; background:#fafafa; }
    .muted { color:#666; }
    .footer { margin-top:26px; border-top:1px solid #bbb; padding-top:7px; text-align:center; font-size:8.5px; color:#555; }
</style>
</head>
<body>

<table class="header">
<tr>
    <td style="width:25%;"><?php if (!empty($seller['logo_data_uri'])) : ?><img class="logo" src="<?php echo esc_attr($seller['logo_data_uri']); ?>" alt=""><?php endif; ?></td>
    <td class="brand" style="width:50%;">
        <?php echo esc_html($seller['trade_name'] ?: ($seller['website'] ?? '')); ?>
        <small><?php echo esc_html($seller['email'] ?? ''); ?></small>
    </td>
    <td class="contact" style="width:25%;"><?php echo esc_html($seller['phone'] ?? ''); ?></td>
</tr>
</table>

<h1><?php echo esc_html($title); ?></h1>
<div class="warning"><?php echo esc_html($warning_text); ?></div>

<table class="parties">
<tr>
    <td>
        <div class="head">Emitido por:</div>
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
        <div class="head"><?php echo esc_html($recipient_label); ?></div>
        <strong><?php echo esc_html($person_name($billing) ?: 'Cliente web'); ?></strong><br>
        <?php if (!empty($billing['contact']) && $billing['contact'] !== $person_name($billing)) : ?>Contacto: <?php echo esc_html($billing['contact']); ?><br><?php endif; ?>
        <?php if (!empty($billing['tax_id'])) : ?>NIF/CIF: <?php echo esc_html($billing['tax_id']); ?><br><?php endif; ?>
        <?php foreach ($address_lines($billing) as $line) : ?><?php echo esc_html($line); ?><br><?php endforeach; ?>
        <?php if (!empty($billing['email'])) : ?><?php echo esc_html($billing['email']); ?><?php endif; ?>
    </td>
</tr>
</table>

<h2><?php echo esc_html($detail_heading); ?></h2>
<table class="details">
    <tr><td><?php echo esc_html($reference_label); ?></td><td><strong><?php echo esc_html($document['number'] ?? ''); ?></strong></td></tr>
    <tr><td>Fecha:</td><td><?php echo esc_html($issued_date); ?></td></tr>
    <tr><td><?php echo esc_html($validity_label); ?></td><td><strong><?php echo esc_html($valid_date); ?></strong></td></tr>
    <?php if ($show_tax) : ?>
        <tr><td>Base imponible:</td><td><?php echo esc_html($money($totals['base_total'] ?? 0)); ?></td></tr>
        <tr><td>Impuestos:</td><td><?php echo esc_html($money($totals['total_tax'] ?? 0)); ?></td></tr>
    <?php endif; ?>
    <tr><td>Total:</td><td><strong><?php echo esc_html($money($totals['total'] ?? 0)); ?></strong></td></tr>
</table>

<?php if ($show_shipping && !empty(array_filter($shipping))) : ?>
<h2>Destino usado para el calculo</h2>
<div><?php foreach ($address_lines($shipping) as $line) : ?><?php echo esc_html($line); ?><br><?php endforeach; ?></div>
<?php endif; ?>

<h2>Detalle de los productos</h2>
<table class="products">
<thead>
<tr>
    <?php if ($show_images) : ?><th class="pic"></th><?php endif; ?>
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
    <?php if ($show_images) : ?><td class="pic"><?php if (!empty($item['image_data_uri'])) : ?><img src="<?php echo esc_attr($item['image_data_uri']); ?>" alt=""><?php endif; ?></td><?php endif; ?>
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
    <?php if ($show_discounts && !empty($totals['discount_total'])) : ?><tr><td>Descuentos</td><td>-<?php echo esc_html($money($totals['discount_total'])); ?></td></tr><?php endif; ?>
    <?php if ($show_shipping && !empty($totals['shipping_total'])) : ?><tr><td>Transporte</td><td><?php echo esc_html($money($totals['shipping_total'])); ?></td></tr><?php endif; ?>
    <?php if (!empty($totals['fee_total'])) : ?><tr><td>Otros cargos</td><td><?php echo esc_html($money($totals['fee_total'])); ?></td></tr><?php endif; ?>
    <?php if ($show_tax) : ?>
        <tr><td>BASE IMPONIBLE</td><td><?php echo esc_html($money($totals['base_total'] ?? 0)); ?></td></tr>
        <?php if ($tax_lines) : ?>
            <?php foreach ($tax_lines as $tax) : ?>
                <tr><td><?php echo esc_html($tax['label'] ?? 'Impuesto'); ?></td><td><?php echo esc_html($money($tax['tax_total'] ?? 0)); ?></td></tr>
            <?php endforeach; ?>
        <?php else : ?>
            <tr><td>IMPUESTOS</td><td><?php echo esc_html($money($totals['total_tax'] ?? 0)); ?></td></tr>
        <?php endif; ?>
    <?php endif; ?>
    <tr class="grand"><td><?php echo esc_html($total_label); ?></td><td><?php echo esc_html($money($totals['total'] ?? 0)); ?></td></tr>
</table>

<?php if (!empty($document['terms_text'])) : ?>
<div class="terms"><?php echo nl2br(esc_html($document['terms_text'])); ?></div>
<?php endif; ?>

<div class="footer">
    <?php if (!empty($document['footer_text'])) : ?><?php echo nl2br(esc_html($document['footer_text'])); ?><br><?php endif; ?>
    <?php if (!empty($seller['footer_text'])) : ?><?php echo nl2br(esc_html($seller['footer_text'])); ?><br><?php endif; ?>
    <?php echo esc_html($seller['website'] ?? ''); ?>
    <?php if (!empty($seller['phone'])) : ?> &nbsp;|&nbsp; <?php echo esc_html($seller['phone']); ?><?php endif; ?>
</div>

</body>
</html>
