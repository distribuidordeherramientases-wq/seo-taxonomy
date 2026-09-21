<?php
/**
 * Ojeador external scan import/export.
 *
 * Exports WooCommerce products so an external/manual discovery pass can find
 * final competitor URLs. Imports those curated URLs and observed prices into
 * Ojeador without visiting the external websites during import.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.3.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Import_Export {
    const MAX_IMPORT_ROWS = 50000;

    public static function init() {
        add_action('admin_post_seo_ojeador_export_scan', array(__CLASS__, 'export_scan'));
        add_action('admin_post_seo_ojeador_export_offers', array(__CLASS__, 'export_offers'));
        add_action('admin_post_seo_ojeador_import_scan', array(__CLASS__, 'import_scan'));
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'seo-taxonomy'));
        }
        check_admin_referer($action);
        SEO_Ojeador_DB::maybe_install();
    }

    public static function render_admin() {
        $created = absint($_GET['created'] ?? 0);
        $updated = absint($_GET['updated'] ?? 0);
        $unchanged = absint($_GET['unchanged'] ?? 0);
        $skipped = absint($_GET['skipped'] ?? 0);
        $errors = absint($_GET['errors'] ?? 0);
        $imported = isset($_GET['imported']);
        ?>
        <?php if ($imported) : ?>
            <div class="notice notice-success inline"><p>
                <strong>Importación terminada.</strong>
                Nuevas: <?php echo esc_html(number_format_i18n($created)); ?> ·
                Actualizadas: <?php echo esc_html(number_format_i18n($updated)); ?> ·
                Sin cambios: <?php echo esc_html(number_format_i18n($unchanged)); ?> ·
                Omitidas: <?php echo esc_html(number_format_i18n($skipped)); ?> ·
                Errores: <?php echo esc_html(number_format_i18n($errors)); ?>.
            </p></div>
        <?php endif; ?>

        <section class="seo-ojeador-card">
            <div class="seo-ojeador-card-head">
                <div>
                    <h2>Escaneo externo · circuito inicial</h2>
                    <p>Exporta nuestros productos, localiza fuera de WordPress las fichas finales del mismo producto y vuelve a importar esas URLs con los datos observados. <strong>La importación no visita ninguna web externa.</strong></p>
                </div>
            </div>
            <div class="seo-ojeador-grid">
                <div>
                    <h3>1. Exportar productos para investigar</h3>
                    <p class="description">Genera un CSV con object_id, SKU, GTIN/EAN, MPN, marca, modelo, nombre, nuestro precio y una consulta sugerida.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="seo_ojeador_export_scan">
                        <?php wp_nonce_field('seo_ojeador_export_scan'); ?>
                        <p><label>Desde object_id <input type="number" name="from_id" min="0" value="0" style="width:130px"></label></p>
                        <p><label>Máximo <input type="number" name="limit" min="0" max="20000" value="1000" style="width:130px"> <small>0 = todos</small></label></p>
                        <p><label><input type="checkbox" name="without_market" value="1"> Solo productos que todavía no tienen oferta exterior activa</label></p>
                        <p><button class="button button-primary" type="submit">Exportar inventario para escaneo</button></p>
                    </form>
                </div>
                <div>
                    <h3>2. Importar resultados externos</h3>
                    <p class="description">Una fila por comercio/URL. El object_id es la clave común con WooCommerce. Las filas se guardan como mercado exterior y, por defecto, <code>import_only</code>.</p>
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="seo_ojeador_import_scan">
                        <?php wp_nonce_field('seo_ojeador_import_scan'); ?>
                        <p><input type="file" name="ojeador_csv" accept=".csv,text/csv,text/plain" required></p>
                        <p><button class="button button-primary" type="submit">Importar escaneo externo</button></p>
                    </form>
                </div>
                <div>
                    <h3>3. Exportar base actual de Ojeador</h3>
                    <p class="description">Sirve para revisar, completar precios manualmente y volver a importar. No exporta nuestra tienda como una oferta.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="seo_ojeador_export_offers">
                        <?php wp_nonce_field('seo_ojeador_export_offers'); ?>
                        <p><button class="button" type="submit">Exportar ofertas exteriores actuales</button></p>
                    </form>
                </div>
            </div>
        </section>

        <section class="seo-ojeador-card">
            <div class="seo-ojeador-card-head"><div><h2>Formato de importación</h2><p>Excel puede editar este CSV y guardarlo de nuevo como CSV UTF-8.</p></div></div>
            <div class="seo-ojeador-table-wrap">
                <table class="widefat striped">
                    <thead><tr><th>Campo</th><th>Necesario</th><th>Uso</th></tr></thead>
                    <tbody>
                        <tr><td><code>object_id</code></td><td>Sí</td><td>ID de nuestro producto WooCommerce. Es la clave que agrupa todas sus ofertas externas.</td></tr>
                        <tr><td><code>merchant</code></td><td>Sí</td><td>Nombre de tienda/proveedor exterior.</td></tr>
                        <tr><td><code>url</code></td><td>Sí</td><td>Ficha final del mismo producto en esa tienda.</td></tr>
                        <tr><td><code>price_gross</code></td><td>No</td><td>Precio público con IVA cuando sea conocido.</td></tr>
                        <tr><td><code>price_net</code></td><td>No</td><td>Precio sin IVA, si la fuente lo expresa así.</td></tr>
                        <tr><td><code>price_raw</code></td><td>No</td><td>Precio visible cuando no sabemos si incluye IVA.</td></tr>
                        <tr><td><code>vat_rate</code></td><td>No</td><td>IVA en porcentaje, por ejemplo 21.</td></tr>
                        <tr><td><code>shipping_price</code></td><td>No</td><td>Portes conocidos. 0 significa gratis.</td></tr>
                        <tr><td><code>total_price</code></td><td>No</td><td>Total comparable si ya se ha calculado fuera.</td></tr>
                        <tr><td><code>currency</code></td><td>No</td><td>EUR por defecto.</td></tr>
                        <tr><td><code>stock_status</code></td><td>No</td><td>disponible, agotado, bajo_pedido, etc.</td></tr>
                        <tr><td><code>observed_at</code></td><td>No</td><td>Fecha de observación. Si falta se usa el momento de importación.</td></tr>
                        <tr><td><code>refresh_mode</code></td><td>No</td><td><code>import_only</code> por defecto. <code>generic_web</code> permite futuros intentos de refresco web.</td></tr>
                        <tr><td><code>gtin / mpn / brand / model</code></td><td>No</td><td>Evidencia externa para auditoría de identidad.</td></tr>
                    </tbody>
                </table>
            </div>
            <p><strong>Cabecera mínima:</strong> <code>object_id,merchant,url</code></p>
            <p><strong>Cabecera recomendada:</strong> <code>object_id,merchant,url,price_gross,vat_rate,shipping_price,total_price,currency,stock_status,observed_at,refresh_mode,gtin,mpn,brand,model</code></p>
        </section>
        <?php
    }

    public static function export_scan() {
        self::guard('seo_ojeador_export_scan');
        global $wpdb;

        $from_id = absint($_POST['from_id'] ?? 0);
        $limit = absint($_POST['limit'] ?? 1000);
        if ($limit > 20000) {
            $limit = 20000;
        }
        $without_market = !empty($_POST['without_market']);

        $where = array("p.post_type='product'", "p.post_status='publish'");
        if ($from_id > 0) {
            $where[] = $wpdb->prepare('p.ID >= %d', $from_id);
        }
        if ($without_market) {
            $offers = SEO_Ojeador_DB::table('offers');
            $where[] = "NOT EXISTS (SELECT 1 FROM {$offers} o WHERE o.object_id=p.ID AND o.active=1 AND o.source_type<>'provider_catalog')";
        }
        $sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE " . implode(' AND ', $where) . ' ORDER BY p.ID ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . absint($limit);
        }
        $ids = (array) $wpdb->get_col($sql);

        self::csv_headers('ojeador-productos-para-escaneo-' . gmdate('Ymd-His') . '.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('object_id','sku','gtin','mpn','marca','modelo','nombre','precio_actual','moneda','consulta_sugerida'));

        foreach ($ids as $id) {
            $id = absint($id);
            $identity = SEO_Ojeador_Identity::from_product($id);
            if (is_wp_error($identity)) {
                continue;
            }
            $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
            $price = '';
            if ($product) {
                $price_value = function_exists('wc_get_price_including_tax')
                    ? wc_get_price_including_tax($product)
                    : (float) $product->get_price();
                $price = $price_value > 0 ? self::decimal_string($price_value) : '';
            }
            $query = self::search_query($identity);
            fputcsv($out, array(
                $id,
                (string) ($identity['sku'] ?? ''),
                (string) ($identity['gtin'] ?? ''),
                (string) ($identity['mpn'] ?? ''),
                (string) ($identity['brand'] ?? ''),
                (string) ($identity['model'] ?? ''),
                (string) ($identity['name'] ?? ''),
                $price,
                function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
                $query,
            ));
        }
        fclose($out);
        exit;
    }

    public static function export_offers() {
        self::guard('seo_ojeador_export_offers');
        global $wpdb;
        $table = SEO_Ojeador_DB::table('offers');
        $rows = (array) $wpdb->get_results(
            "SELECT * FROM {$table} WHERE source_type<>'provider_catalog' ORDER BY object_id ASC, merchant_name ASC, id ASC",
            ARRAY_A
        );

        self::csv_headers('ojeador-ofertas-externas-' . gmdate('Ymd-His') . '.csv');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $header = array('object_id','merchant','url','price_raw','price_net','price_gross','vat_rate','shipping_price','total_price','currency','stock_status','observed_at','refresh_mode','gtin','mpn','brand','model','active');
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, array(
                absint($row['object_id'] ?? 0),
                (string) ($row['merchant_name'] ?? ''),
                (string) ($row['url'] ?? ''),
                (string) ($row['price_raw'] ?? ''),
                (string) ($row['price_net'] ?? ''),
                (string) ($row['price_gross'] ?? ''),
                (string) ($row['vat_rate'] ?? ''),
                (string) ($row['shipping_price'] ?? ''),
                (string) ($row['total_price'] ?? ''),
                (string) ($row['currency'] ?? 'EUR'),
                (string) ($row['stock_status'] ?? ''),
                (string) ($row['observed_at'] ?? ''),
                (string) ($row['refresh_mode'] ?? 'import_only'),
                (string) ($row['observed_gtin'] ?? ''),
                (string) ($row['observed_mpn'] ?? ''),
                (string) ($row['observed_brand'] ?? ''),
                (string) ($row['observed_model'] ?? ''),
                empty($row['active']) ? 0 : 1,
            ));
        }
        fclose($out);
        exit;
    }

    public static function import_scan() {
        self::guard('seo_ojeador_import_scan');

        if (empty($_FILES['ojeador_csv']['tmp_name']) || !is_uploaded_file($_FILES['ojeador_csv']['tmp_name'])) {
            self::redirect_error('No se recibió un CSV válido.');
        }
        $path = $_FILES['ojeador_csv']['tmp_name'];
        $handle = fopen($path, 'r');
        if (!$handle) {
            self::redirect_error('No se pudo abrir el CSV.');
        }

        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);
            self::redirect_error('El CSV está vacío.');
        }
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($handle);
        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header) {
            fclose($handle);
            self::redirect_error('No se pudo leer la cabecera del CSV.');
        }
        $header = array_map(array(__CLASS__, 'normalize_header'), $header);
        $index = array_flip($header);

        if (!self::has_any($index, array('object_id','product_id','id_producto')) ||
            !self::has_any($index, array('merchant','merchant_name','comercio','proveedor','tienda')) ||
            !self::has_any($index, array('url','url_producto','enlace'))) {
            fclose($handle);
            self::redirect_error('Cabecera inválida. Son obligatorios object_id, merchant y url (se aceptan alias en español).');
        }

        $batch = 'csv-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
        $created = $updated = $unchanged = $skipped = $errors = 0;
        $line = 1;
        $settings = SEO_Ojeador_Worker::settings();
        $freshness = max(1, absint($settings['freshness_hours'] ?? 168));
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

        while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if ($line > self::MAX_IMPORT_ROWS + 1) {
                $errors++;
                break;
            }
            if (!array_filter($cells, static function ($v) { return trim((string) $v) !== ''; })) {
                continue;
            }
            $row = array();
            foreach ($header as $i => $key) {
                if ($key !== '') {
                    $row[$key] = isset($cells[$i]) ? trim((string) $cells[$i]) : '';
                }
            }

            $object_id = absint(self::pick($row, array('object_id','product_id','id_producto')));
            $merchant = sanitize_text_field(self::pick($row, array('merchant','merchant_name','comercio','proveedor','tienda')));
            $url = esc_url_raw(self::pick($row, array('url','url_producto','enlace')));
            if ($object_id < 1 || $merchant === '' || $url === '' || get_post_type($object_id) !== 'product') {
                $skipped++;
                continue;
            }
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            if ($host === '' || ($home_host !== '' && $host === $home_host)) {
                $skipped++;
                continue;
            }

            $identity = SEO_Ojeador_Identity::from_product($object_id);
            if (is_wp_error($identity)) {
                $errors++;
                continue;
            }
            $ojeador_product_id = SEO_Ojeador_DB::upsert_product($identity);
            if (is_wp_error($ojeador_product_id)) {
                $errors++;
                continue;
            }

            $price_raw = self::decimal(self::pick($row, array('price_raw','precio','price')));
            $price_net = self::decimal(self::pick($row, array('price_net','precio_sin_iva')));
            $price_gross = self::decimal(self::pick($row, array('price_gross','precio_con_iva')));
            $vat_rate = self::decimal(self::pick($row, array('vat_rate','iva','iva_porcentaje')));
            $shipping = self::decimal(self::pick($row, array('shipping_price','transporte','portes')));
            $total = self::decimal(self::pick($row, array('total_price','precio_total','total')));

            if (null === $price_gross && null !== $price_net && null !== $vat_rate) {
                $price_gross = round($price_net * (1 + ($vat_rate / 100)), 6);
            }
            if (null === $total && null !== $price_gross && null !== $shipping) {
                $total = $price_gross + $shipping;
            }
            if (null === $price_raw) {
                $price_raw = null !== $price_gross ? $price_gross : $price_net;
            }

            $observed_at = self::mysql_date(self::pick($row, array('observed_at','fecha','fecha_extraccion','fecha_captura')));
            if (!$observed_at) {
                $observed_at = SEO_Ojeador_DB::utc_now();
            }
            $expires_at = gmdate('Y-m-d H:i:s', strtotime($observed_at . ' UTC') + ($freshness * HOUR_IN_SECONDS));
            $refresh_mode = sanitize_key(self::pick($row, array('refresh_mode','modo_actualizacion')) ?: 'import_only');
            if (!in_array($refresh_mode, array('import_only','generic_web'), true)) {
                $refresh_mode = 'import_only';
            }

            $offer_key = hash('sha256', 'external_import|' . $object_id . '|' . strtolower($merchant) . '|' . strtolower($url));
            $offer = array(
                'object_id' => $object_id,
                'source_key' => 'external_import',
                'source_type' => 'external_market',
                'refresh_mode' => $refresh_mode,
                'import_batch' => $batch,
                'imported_at' => SEO_Ojeador_DB::utc_now(),
                'merchant_name' => $merchant,
                'seller_name' => sanitize_text_field(self::pick($row, array('seller','seller_name','vendedor'))),
                'external_product_id' => sanitize_text_field(self::pick($row, array('external_product_id','id_externo','external_id'))),
                'offer_key' => $offer_key,
                'url' => $url,
                'observed_title' => sanitize_text_field(self::pick($row, array('title','observed_title','nombre'))),
                'observed_gtin' => SEO_Ojeador_Identity::normalize_gtin(self::pick($row, array('gtin','ean','ean13'))),
                'observed_mpn' => sanitize_text_field(self::pick($row, array('mpn'))),
                'observed_brand' => sanitize_text_field(self::pick($row, array('brand','marca'))),
                'observed_model' => sanitize_text_field(self::pick($row, array('model','modelo'))),
                'price_raw' => $price_raw,
                'price_net' => $price_net,
                'price_gross' => $price_gross,
                'vat_rate' => $vat_rate,
                'vat_mode' => null !== $price_gross ? 'included' : (null !== $price_net ? 'excluded' : 'unknown'),
                'shipping_price' => $shipping,
                'shipping_mode' => null === $shipping ? 'unknown' : ($shipping <= 0 ? 'free' : 'separate'),
                'total_price' => $total,
                'currency' => strtoupper(substr(sanitize_text_field(self::pick($row, array('currency','moneda')) ?: 'EUR'), 0, 12)),
                'stock_status' => sanitize_key(self::pick($row, array('stock_status','stock','estado_stock'))),
                'stock_text' => sanitize_text_field(self::pick($row, array('stock_text','stock_texto'))),
                'condition_label' => sanitize_text_field(self::pick($row, array('condition','condition_label','condicion'))),
                'match_method' => 'manual_curated_import',
                'match_confidence' => 1,
                'match_status' => 'confirmed',
                'extraction_method' => 'external_import',
                'observed_at' => $observed_at,
                'expires_at' => $expires_at,
                'active' => self::bool_value(self::pick($row, array('active','activo')), true) ? 1 : 0,
                'raw' => array('import_batch' => $batch, 'import_line' => $line, 'import_row' => $row),
            );

            $saved = SEO_Ojeador_DB::save_offer($ojeador_product_id, $offer);
            if (is_wp_error($saved)) {
                $errors++;
                continue;
            }
            if (!empty($saved['created'])) {
                $created++;
            } elseif (!empty($saved['changed'])) {
                $updated++;
            } else {
                $unchanged++;
            }
        }
        fclose($handle);

        wp_safe_redirect(add_query_arg(array(
            'page' => SEO_Ojeador_Admin::PAGE,
            'tab' => 'escaneo-externo',
            'imported' => 1,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'errors' => $errors,
        ), admin_url('admin.php')));
        exit;
    }

    private static function csv_headers($filename) {
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('X-Content-Type-Options: nosniff');
    }

    private static function search_query($identity) {
        $gtin = trim((string) ($identity['gtin'] ?? ''));
        if ($gtin !== '') {
            return $gtin;
        }
        $brand = trim((string) ($identity['brand'] ?? ''));
        $mpn = trim((string) ($identity['mpn'] ?? ''));
        $model = trim((string) ($identity['model'] ?? ''));
        if ($mpn !== '') {
            return trim($brand . ' "' . $mpn . '"');
        }
        if ($model !== '') {
            return trim($brand . ' "' . $model . '"');
        }
        return trim((string) ($identity['name'] ?? ''));
    }

    private static function normalize_header($value) {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);
        $value = strtolower(trim($value));
        $value = remove_accents($value);
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value);
        return trim((string) $value, '_');
    }

    private static function has_any($index, $keys) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $index)) {
                return true;
            }
        }
        return false;
    }

    private static function pick($row, $keys) {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }
        return '';
    }

    private static function decimal($value) {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = preg_replace('/[^0-9,\.\-]/', '', (string) $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');
        if ($comma !== false && $dot !== false) {
            if ($comma > $dot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($comma !== false) {
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) ? (float) $value : null;
    }

    private static function decimal_string($value) {
        return number_format((float) $value, 6, '.', '');
    }

    private static function mysql_date($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return str_replace('T', ' ', strlen($value) === 16 ? $value . ':00' : $value);
        }
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : '';
    }

    private static function bool_value($value, $default = true) {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return (bool) $default;
        }
        if (in_array($value, array('0','false','no','off','inactivo'), true)) {
            return false;
        }
        return true;
    }

    private static function redirect_error($message) {
        wp_safe_redirect(add_query_arg(array(
            'page' => SEO_Ojeador_Admin::PAGE,
            'tab' => 'escaneo-externo',
            'ojeador_error' => rawurlencode((string) $message),
        ), admin_url('admin.php')));
        exit;
    }
}
