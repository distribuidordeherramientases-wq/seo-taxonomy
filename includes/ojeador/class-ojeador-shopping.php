<?php
/**
 * Google Shopping client for Ojeador via SerpApi.
 *
 * Ojeador does not scrape merchant sites. It asks a structured Google Shopping
 * results provider for matching products and their store offers.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.5.1
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Shopping {
    const OPTION_SETTINGS = 'seo_ojeador_shopping_settings';
    const API_URL = 'https://serpapi.com/search.json';

    public static function defaults() {
        return array(
            'api_key' => '',
            'auto_enabled' => 0,
            'interval_hours' => 168,
            'batch_size' => 4,
        );
    }

    public static function settings() {
        $stored = get_option(self::OPTION_SETTINGS, array());
        $settings = wp_parse_args(is_array($stored) ? $stored : array(), self::defaults());
        if (defined('SEO_OJEADOR_SERPAPI_KEY') && SEO_OJEADOR_SERPAPI_KEY) {
            $settings['api_key'] = (string) SEO_OJEADOR_SERPAPI_KEY;
        }
        return self::sanitize_settings($settings);
    }

    public static function sanitize_settings($raw) {
        $raw = wp_parse_args(is_array($raw) ? $raw : array(), self::defaults());
        return array(
            'api_key' => sanitize_text_field((string) $raw['api_key']),
            'auto_enabled' => empty($raw['auto_enabled']) ? 0 : 1,
            'interval_hours' => max(6, min(720, absint($raw['interval_hours']))),
            'batch_size' => max(1, min(20, absint($raw['batch_size']))),
        );
    }

    public static function save_settings($raw) {
        $current = self::settings();
        $raw = is_array($raw) ? $raw : array();
        if (empty($raw['api_key']) && !defined('SEO_OJEADOR_SERPAPI_KEY')) {
            $raw['api_key'] = (string) ($current['api_key'] ?? '');
        }
        $settings = self::sanitize_settings($raw);
        update_option(self::OPTION_SETTINGS, $settings, false);
        return $settings;
    }

    public static function readiness() {
        $s = self::settings();
        if ($s['api_key'] === '') {
            return new WP_Error('ojeador_shopping_key', 'Falta la API key de SerpApi para consultar Google Shopping.');
        }
        return true;
    }

    public static function build_query($identity) {
        $identity = is_array($identity) ? $identity : array();
        $gtin = SEO_Ojeador_Identity::normalize_gtin($identity['gtin'] ?? '');
        $mpn = trim((string) ($identity['mpn'] ?? ''));
        $brand = trim((string) ($identity['brand'] ?? ''));
        $model = trim((string) ($identity['model'] ?? ''));
        $name = trim((string) ($identity['name'] ?? ''));

        if ($gtin !== '') {
            return $gtin;
        }
        if ($brand !== '' && $mpn !== '') {
            return trim($brand . ' ' . $mpn);
        }
        if ($brand !== '' && $model !== '') {
            return trim($brand . ' ' . $model);
        }
        if ($mpn !== '') {
            return $mpn;
        }
        if ($model !== '') {
            return trim($brand . ' ' . $model);
        }
        return $name;
    }

    /**
     * Find one Google Shopping product and return its store offers.
     *
     * @param array $identity Canonical WooCommerce identity.
     * @return array|WP_Error
     */
    public static function scan($identity) {
        $ready = self::readiness();
        if (is_wp_error($ready)) {
            return $ready;
        }

        $query = self::build_query($identity);
        if ($query === '') {
            return new WP_Error('ojeador_identity_empty', 'El producto no tiene datos suficientes para consultar Google Shopping.');
        }

        $search = self::request(array(
            'engine' => 'google_shopping',
            'q' => $query,
            'google_domain' => 'google.es',
            'gl' => 'es',
            'hl' => 'es',
            'device' => 'desktop',
        ));
        if (is_wp_error($search)) {
            return $search;
        }

        $candidate = self::best_candidate((array) ($search['shopping_results'] ?? array()), $identity, $query);
        if (!$candidate) {
            return array(
                'status' => 'no_match',
                'query' => $query,
                'google_product_id' => '',
                'google_title' => '',
                'match_confidence' => 0,
                'offers' => array(),
                'raw_search' => self::compact_raw($search),
            );
        }

        $offers = array();
        $token = (string) ($candidate['immersive_product_page_token'] ?? '');
        if ($token !== '') {
            $details = self::request(array(
                'engine' => 'google_immersive_product',
                'page_token' => $token,
                'more_stores' => 'true',
                'google_domain' => 'google.es',
                'gl' => 'es',
                'hl' => 'es',
                'device' => 'desktop',
            ));
            if (!is_wp_error($details)) {
                $offers = self::extract_stores($details);
            }
        }

        // Fallback: Google Shopping results themselves already expose seller and
        // price. This keeps the system useful when immersive store details are
        // unavailable for a particular product.
        if (!$offers) {
            $offers = self::extract_search_offers((array) ($search['shopping_results'] ?? array()), $identity);
        }

        // No recortamos la respuesta: se conservan todas las ofertas que
        // Google Shopping/SerpApi haya devuelto para esta consulta.

        return array(
            'status' => $offers ? 'ok' : 'no_offers',
            'query' => $query,
            'google_product_id' => sanitize_text_field((string) ($candidate['product_id'] ?? '')),
            'google_title' => sanitize_text_field((string) ($candidate['title'] ?? '')),
            'match_confidence' => (float) ($candidate['_ojeador_score'] ?? 0),
            'offers' => $offers,
            'raw_search' => self::compact_raw($search),
        );
    }

    private static function request($args) {
        $s = self::settings();
        $args = array_merge((array) $args, array('api_key' => $s['api_key']));
        $url = add_query_arg($args, self::API_URL);
        $response = wp_safe_remote_get($url, array(
            'timeout' => 35,
            'redirection' => 3,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'SEO-System-Ojeador/' . (defined('SEO_OJEADOR_VERSION') ? SEO_OJEADOR_VERSION : '0.5.0'),
            ),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            return new WP_Error('ojeador_shopping_http', 'Google Shopping API HTTP ' . absint($code) . '.');
        }
        if (!empty($body['error'])) {
            $message = is_array($body['error']) ? (string) ($body['error']['message'] ?? 'Error de Google Shopping.') : (string) $body['error'];
            return new WP_Error('ojeador_shopping_api', sanitize_text_field($message));
        }
        return $body;
    }

    private static function best_candidate($rows, $identity, $query) {
        $best = null;
        $best_score = 0.0;
        $gtin = SEO_Ojeador_Identity::normalize_gtin($identity['gtin'] ?? '');
        $mpn = SEO_Ojeador_Identity::normalize_code($identity['mpn'] ?? '');
        $model = SEO_Ojeador_Identity::normalize_code($identity['model'] ?? '');
        $brand = SEO_Ojeador_Identity::normalize_text($identity['brand'] ?? '');
        $own_title = SEO_Ojeador_Identity::normalize_text($identity['name'] ?? '');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $title_code = SEO_Ojeador_Identity::normalize_code($title);
            $title_text = SEO_Ojeador_Identity::normalize_text($title);
            $score = 0.0;

            if ($gtin !== '' && preg_match('/^\d{8,14}$/', trim((string) $query))) {
                $score += 0.35;
            }
            if ($mpn !== '' && strpos($title_code, $mpn) !== false) {
                $score += 0.40;
            } elseif ($model !== '' && strpos($title_code, $model) !== false) {
                $score += 0.35;
            }
            if ($brand !== '' && strpos($title_text, $brand) !== false) {
                $score += 0.15;
            }
            if ($own_title !== '') {
                similar_text($own_title, $title_text, $pct);
                $score += min(0.15, max(0, ((float) $pct / 100) * 0.15));
            }
            if (!empty($row['product_id']) || !empty($row['immersive_product_page_token'])) {
                $score += 0.05;
            }
            $score = min(1.0, $score);
            if ($score > $best_score) {
                $row['_ojeador_score'] = $score;
                $best = $row;
                $best_score = $score;
            }
        }

        // Exact GTIN searches can still be useful when Google does not repeat the
        // manufacturer reference in the title. Non-GTIN searches require stronger
        // textual evidence.
        $threshold = $gtin !== '' ? 0.45 : 0.55;
        return ($best && $best_score >= $threshold) ? $best : null;
    }

    private static function extract_stores($details) {
        $stores = array();
        if (!empty($details['product_results']['stores']) && is_array($details['product_results']['stores'])) {
            $stores = $details['product_results']['stores'];
        } elseif (!empty($details['sellers_results']['online_sellers']) && is_array($details['sellers_results']['online_sellers'])) {
            $stores = $details['sellers_results']['online_sellers'];
        }

        $out = array();
        foreach ($stores as $store) {
            if (!is_array($store)) {
                continue;
            }
            $merchant = sanitize_text_field((string) ($store['name'] ?? $store['source'] ?? ''));
            $price = self::number($store['extracted_price'] ?? $store['base_price'] ?? $store['price'] ?? null);
            if ($merchant === '' || $price === null || $price <= 0) {
                continue;
            }
            $shipping = self::number($store['shipping_extracted'] ?? ($store['additional_price']['shipping'] ?? null));
            $total = self::number($store['extracted_total'] ?? $store['total_price'] ?? null);
            if ($total === null && $shipping !== null) {
                $total = $price + $shipping;
            }
            $url = esc_url_raw((string) ($store['direct_link'] ?? $store['link'] ?? ''));
            $out[] = array(
                'merchant' => $merchant,
                'url' => $url,
                'price' => $price,
                'shipping_price' => $shipping,
                'total_price' => $total,
                'currency' => self::currency_from_values($store),
                'stock_text' => self::stock_text($store),
                'condition_label' => sanitize_text_field((string) ($store['condition'] ?? '')),
                'position' => absint($store['position'] ?? 0),
                'source' => 'google_shopping',
                'raw' => self::compact_raw($store),
            );
        }
        return self::dedupe_offers($out);
    }

    private static function extract_search_offers($rows, $identity) {
        $out = array();
        $mpn = SEO_Ojeador_Identity::normalize_code($identity['mpn'] ?? '');
        $model = SEO_Ojeador_Identity::normalize_code($identity['model'] ?? '');
        $brand = SEO_Ojeador_Identity::normalize_text($identity['brand'] ?? '');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = (string) ($row['title'] ?? '');
            $title_code = SEO_Ojeador_Identity::normalize_code($title);
            $title_text = SEO_Ojeador_Identity::normalize_text($title);
            $strong = ($mpn !== '' && strpos($title_code, $mpn) !== false)
                || ($model !== '' && strpos($title_code, $model) !== false);
            if (!$strong && $brand !== '' && strpos($title_text, $brand) === false) {
                continue;
            }
            $merchant = sanitize_text_field((string) ($row['source'] ?? $row['seller'] ?? ''));
            $price = self::number($row['extracted_price'] ?? $row['price'] ?? null);
            if ($merchant === '' || $price === null || $price <= 0) {
                continue;
            }
            $out[] = array(
                'merchant' => $merchant,
                'url' => esc_url_raw((string) ($row['product_link'] ?? $row['link'] ?? '')),
                'price' => $price,
                'shipping_price' => null,
                'total_price' => null,
                'currency' => self::currency_from_values($row),
                'stock_text' => sanitize_text_field((string) ($row['delivery'] ?? '')),
                'condition_label' => sanitize_text_field((string) ($row['second_hand_condition'] ?? '')),
                'position' => absint($row['position'] ?? 0),
                'source' => 'google_shopping_search',
                'raw' => self::compact_raw($row),
            );
        }
        return self::dedupe_offers($out);
    }

    private static function dedupe_offers($offers) {
        $seen = array();
        $out = array();
        foreach ($offers as $offer) {
            $merchant_key = sanitize_title((string) ($offer['merchant'] ?? ''));
            $price = isset($offer['price']) ? number_format((float) $offer['price'], 4, '.', '') : '';
            $key = $merchant_key . '|' . $price . '|' . strtolower((string) ($offer['url'] ?? ''));
            if ($merchant_key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $offer;
        }
        usort($out, static function ($a, $b) {
            $ta = isset($a['total_price']) && $a['total_price'] !== null ? (float) $a['total_price'] : (float) ($a['price'] ?? PHP_FLOAT_MAX);
            $tb = isset($b['total_price']) && $b['total_price'] !== null ? (float) $b['total_price'] : (float) ($b['price'] ?? PHP_FLOAT_MAX);
            return $ta <=> $tb;
        });
        return $out;
    }

    private static function number($value) {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 6);
        }
        $value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/[^0-9,\.\-]/', '', $value);
        if ($value === '' || $value === '-') {
            return null;
        }
        if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif (strpos($value, ',') !== false) {
            $value = str_replace(',', '.', $value);
        }
        return is_numeric($value) ? round((float) $value, 6) : null;
    }

    private static function currency_from_values($row) {
        $currency = strtoupper(sanitize_text_field((string) ($row['currency'] ?? '')));
        if ($currency !== '') {
            return substr($currency, 0, 8);
        }
        $strings = array(
            (string) ($row['price'] ?? ''),
            (string) ($row['base_price'] ?? ''),
            (string) ($row['total'] ?? ''),
            (string) ($row['total_price'] ?? ''),
        );
        foreach ($strings as $text) {
            if (strpos($text, '€') !== false || stripos($text, 'EUR') !== false) {
                return 'EUR';
            }
            if (strpos($text, '$') !== false) {
                return 'USD';
            }
            if (strpos($text, '£') !== false) {
                return 'GBP';
            }
        }
        return 'EUR';
    }

    private static function stock_text($row) {
        $parts = array();
        foreach (array('delivery', 'availability', 'badge', 'tag') as $key) {
            if (!empty($row[$key]) && is_scalar($row[$key])) {
                $parts[] = sanitize_text_field((string) $row[$key]);
            }
        }
        if (!empty($row['details_and_offers']) && is_array($row['details_and_offers'])) {
            foreach ($row['details_and_offers'] as $item) {
                if (is_string($item)) {
                    $parts[] = sanitize_text_field($item);
                } elseif (is_array($item) && !empty($item['text'])) {
                    $parts[] = sanitize_text_field((string) $item['text']);
                }
                if (count($parts) >= 3) {
                    break;
                }
            }
        }
        return implode(' · ', array_values(array_unique(array_filter($parts))));
    }

    private static function compact_raw($value) {
        if (!is_array($value)) {
            return $value;
        }
        $copy = $value;
        foreach (array('raw_html_file', 'thumbnail', 'thumbnails', 'serpapi_thumbnail', 'serpapi_thumbnails', 'source_icon', 'logo', 'product_images', 'images') as $key) {
            unset($copy[$key]);
        }
        return $copy;
    }
}
