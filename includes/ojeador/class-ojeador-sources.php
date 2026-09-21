<?php
/**
 * Ojeador source registry and built-in provider catalogue bridge.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.1.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Sources {
    public static function candidates($identity, $settings = array()) {
        $candidates = array();
        if (!empty($settings['include_provider_catalog'])) {
            $candidates = array_merge($candidates, self::provider_catalog_candidates($identity));
        }

        /**
         * External discovery adapters can add candidate offers here.
         *
         * Each candidate should contain:
         * source_key, source_type, merchant_name, external_product_id, url,
         * data (optional already extracted fields), and match_hint (optional).
         */
        $candidates = apply_filters('seo_ojeador_offer_candidates', $candidates, $identity, $settings);
        return self::unique_candidates(is_array($candidates) ? $candidates : array());
    }

    public static function existing_offer_candidates($ojeador_product_id) {
        $rows = SEO_Ojeador_DB::offer_rows_for_product($ojeador_product_id, true);
        $out = array();
        foreach ($rows as $row) {
            if (empty($row['url'])) {
                continue;
            }
            if (in_array((string) ($row['source_type'] ?? ''), array('provider_catalog'), true)) {
                continue;
            }
            $out[] = array(
                'source_key' => sanitize_key((string) ($row['source_key'] ?? 'external_url')),
                'source_type' => sanitize_key((string) ($row['source_type'] ?? 'external_url')) ?: 'external_url',
                'merchant_name' => sanitize_text_field((string) ($row['merchant_name'] ?? '')),
                'external_product_id' => sanitize_text_field((string) ($row['external_product_id'] ?? '')),
                'url' => esc_url_raw((string) $row['url']),
                'offer_key' => (string) $row['offer_key'],
                'match_hint' => array(),
                'existing_offer_id' => absint($row['id']),
            );
        }
        return $out;
    }

    public static function manual_candidate($merchant_name, $url, $offer_key = '') {
        $url = esc_url_raw((string) $url);
        return array(
            'source_key' => 'manual',
            'source_type' => 'external_url',
            'merchant_name' => sanitize_text_field((string) $merchant_name),
            'external_product_id' => '',
            'url' => $url,
            'offer_key' => $offer_key ?: hash('sha256', 'manual|' . strtolower($url)),
            'match_hint' => array(),
        );
    }

    private static function provider_catalog_candidates($identity) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_proveedores_productos';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return array();
        }

        $product_id = absint($identity['object_id'] ?? 0);
        $mpn = trim((string) ($identity['mpn'] ?? ''));
        $sku = trim((string) ($identity['sku'] ?? ''));
        $brand = trim((string) ($identity['brand'] ?? ''));
        $rows = array();

        if ($product_id > 0) {
            $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE object_id=%d ORDER BY actualizado DESC,id DESC LIMIT 12",
                $product_id
            ), ARRAY_A));
        }
        if ($mpn !== '') {
            if ($brand !== '') {
                $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE mpn=%s AND LOWER(marca)=LOWER(%s) ORDER BY actualizado DESC,id DESC LIMIT 12",
                    $mpn,
                    $brand
                ), ARRAY_A));
            } else {
                $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE mpn=%s ORDER BY actualizado DESC,id DESC LIMIT 12",
                    $mpn
                ), ARRAY_A));
            }
        } elseif ($sku !== '' && $brand !== '') {
            $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE sku=%s AND LOWER(marca)=LOWER(%s) ORDER BY actualizado DESC,id DESC LIMIT 8",
                $sku,
                $brand
            ), ARRAY_A));
        }

        $seen = array();
        $out = array();
        foreach ($rows as $row) {
            $row_id = absint($row['id'] ?? 0);
            if (!$row_id || isset($seen[$row_id])) {
                continue;
            }
            $seen[$row_id] = true;
            $url = esc_url_raw((string) ($row['url_canonica'] ?: $row['url_origen']));
            $provider = sanitize_text_field((string) ($row['proveedor'] ?? ''));
            $external_id = sanitize_text_field((string) ($row['proveedor_id_externo'] ?? ''));
            $object_exact = $product_id > 0 && absint($row['object_id'] ?? 0) === $product_id;

            $out[] = array(
                'source_key' => 'supplier_' . sanitize_key($provider ?: 'catalog'),
                'source_type' => 'provider_catalog',
                'merchant_name' => $provider,
                'external_product_id' => $external_id,
                'url' => $url,
                'offer_key' => hash('sha256', 'supplier|' . $provider . '|' . $external_id . '|' . $row_id),
                'match_hint' => array('object_id_exact' => $object_exact),
                'data' => array(
                    'title' => (string) ($row['nombre'] ?? ''),
                    'mpn' => (string) ($row['mpn'] ?? ''),
                    'sku' => (string) ($row['sku'] ?? ''),
                    'brand' => (string) ($row['marca'] ?? ''),
                    'price_net' => $row['precio_sin_iva'] ?? null,
                    'price_gross' => $row['precio_con_iva'] ?? null,
                    'price_raw' => $row['precio_con_iva'] !== null && $row['precio_con_iva'] !== '' ? $row['precio_con_iva'] : ($row['precio_sin_iva'] ?? null),
                    'vat_rate' => $row['iva_porcentaje'] ?? null,
                    'vat_mode' => $row['precio_con_iva'] !== null && $row['precio_con_iva'] !== '' ? 'included' : ($row['precio_sin_iva'] !== null && $row['precio_sin_iva'] !== '' ? 'excluded' : 'unknown'),
                    'shipping_mode' => 'unknown',
                    'shipping_price' => null,
                    'total_price' => null,
                    'currency' => (string) ($row['moneda'] ?? 'EUR'),
                    'stock_status' => (string) ($row['stock_estado'] ?? ''),
                    'stock_text' => (string) ($row['stock_texto'] ?? ''),
                    'observed_at' => (string) ($row['ultima_importacion'] ?: $row['actualizado'] ?: SEO_Ojeador_DB::utc_now()),
                    'extraction_method' => 'provider_catalog',
                    'raw' => array('provider_catalog_row_id' => $row_id),
                ),
            );
        }
        return $out;
    }

    private static function unique_candidates($candidates) {
        $out = array();
        $seen = array();
        foreach ((array) $candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $url = esc_url_raw((string) ($candidate['url'] ?? ''));
            $key = (string) ($candidate['offer_key'] ?? '');
            if ($key === '') {
                $key = hash('sha256', implode('|', array(
                    (string) ($candidate['source_key'] ?? ''),
                    (string) ($candidate['merchant_name'] ?? ''),
                    (string) ($candidate['external_product_id'] ?? ''),
                    $url,
                )));
                $candidate['offer_key'] = $key;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $candidate['url'] = $url;
            $out[] = $candidate;
        }
        return $out;
    }
}
