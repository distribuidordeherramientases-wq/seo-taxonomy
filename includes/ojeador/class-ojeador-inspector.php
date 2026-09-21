<?php
/**
 * Fetch and parse one external product URL.
 *
 * V0.1 only inspects known/direct product URLs. It does not scrape search engines.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.1.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Inspector {
    public static function inspect($url, $timeout = 12) {
        $url = esc_url_raw((string) $url);
        if ($url === '' || !wp_http_validate_url($url)) {
            return new WP_Error('ojeador_url', 'URL externa no valida.');
        }

        $response = wp_safe_remote_get($url, array(
            'timeout' => max(5, min(25, absint($timeout))),
            'redirection' => 3,
            'reject_unsafe_urls' => true,
            'headers' => array(
                'User-Agent' => 'SEO-System-Ojeador/0.1 (+https://www.distribuidordeherramientas.es/)',
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.7',
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) {
            return new WP_Error('ojeador_http', 'La URL externa respondio HTTP ' . $code . '.');
        }
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            return new WP_Error('ojeador_empty', 'La URL externa no devolvio contenido.');
        }

        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        if ($content_type !== '' && false === strpos($content_type, 'html') && false === strpos($content_type, 'json')) {
            return new WP_Error('ojeador_content_type', 'La URL no parece una pagina HTML de producto.');
        }

        $product = self::find_product_jsonld($body);
        $result = self::from_product_jsonld($product);
        $meta = self::html_meta($body);

        foreach (array('title', 'currency', 'price_raw', 'stock_status', 'condition_label', 'url') as $key) {
            if (empty($result[$key]) && !empty($meta[$key])) {
                $result[$key] = $meta[$key];
            }
        }
        foreach (array('gtin', 'mpn', 'sku', 'brand', 'model') as $key) {
            if (empty($result[$key]) && !empty($meta[$key])) {
                $result[$key] = $meta[$key];
            }
        }

        $plain = strtolower(remove_accents(wp_strip_all_tags($body)));
        $vat = self::detect_vat($plain, $product);
        $shipping = self::detect_shipping($plain, $product);
        $result['vat_mode'] = $vat['mode'];
        $result['vat_rate'] = $vat['rate'];
        $result['shipping_mode'] = $shipping['mode'];
        $result['shipping_price'] = $shipping['price'];

        $raw_price = self::decimal($result['price_raw'] ?? null);
        $result['price_raw'] = $raw_price;
        $result['price_net'] = null;
        $result['price_gross'] = null;
        if ($raw_price !== null) {
            if ('included' === $result['vat_mode']) {
                $result['price_gross'] = $raw_price;
                if (!empty($result['vat_rate'])) {
                    $result['price_net'] = round($raw_price / (1 + ((float) $result['vat_rate'] / 100)), 6);
                }
            } elseif ('excluded' === $result['vat_mode']) {
                $result['price_net'] = $raw_price;
                if (!empty($result['vat_rate'])) {
                    $result['price_gross'] = round($raw_price * (1 + ((float) $result['vat_rate'] / 100)), 6);
                }
            }
        }

        $gross = self::decimal($result['price_gross'] ?? null);
        $shipping_price = self::decimal($result['shipping_price'] ?? null);
        if ($gross !== null && in_array($result['shipping_mode'], array('included', 'free'), true)) {
            $result['total_price'] = $gross;
        } elseif ($gross !== null && 'separate' === $result['shipping_mode'] && $shipping_price !== null) {
            $result['total_price'] = round($gross + $shipping_price, 6);
        } else {
            $result['total_price'] = null;
        }

        $result['observed_at'] = gmdate('Y-m-d H:i:s');
        $result['extraction_method'] = $product ? 'jsonld' : 'html_meta';
        $result['raw'] = array(
            'http_code' => $code,
            'content_type' => $content_type,
            'jsonld_product' => $product,
            'meta' => $meta,
            'vat_detection' => $vat,
            'shipping_detection' => $shipping,
        );
        return $result;
    }

    private static function find_product_jsonld($html) {
        $found = array();
        if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', (string) $html, $matches)) {
            return array();
        }
        foreach ((array) $matches[1] as $raw) {
            $raw = trim(html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($raw === '') {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            self::walk_for_products($data, $found);
        }
        if (!$found) {
            return array();
        }
        usort($found, static function ($a, $b) {
            $a_score = !empty($a['offers']) ? 2 : 0;
            $b_score = !empty($b['offers']) ? 2 : 0;
            $a_score += !empty($a['gtin']) || !empty($a['gtin13']) || !empty($a['mpn']) ? 1 : 0;
            $b_score += !empty($b['gtin']) || !empty($b['gtin13']) || !empty($b['mpn']) ? 1 : 0;
            return $b_score <=> $a_score;
        });
        return (array) $found[0];
    }

    private static function walk_for_products($node, &$found) {
        if (!is_array($node)) {
            return;
        }
        $type = $node['@type'] ?? '';
        $types = is_array($type) ? $type : array($type);
        foreach ($types as $candidate) {
            if (strtolower((string) $candidate) === 'product') {
                $found[] = $node;
                break;
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::walk_for_products($value, $found);
            }
        }
    }

    private static function from_product_jsonld($product) {
        if (!is_array($product) || !$product) {
            return array();
        }
        $brand = $product['brand'] ?? '';
        if (is_array($brand)) {
            $brand = $brand['name'] ?? '';
        }
        $offers = self::first_offer($product['offers'] ?? array());
        $price = self::offer_price($offers);
        $availability = is_array($offers) ? (string) ($offers['availability'] ?? '') : '';
        $condition = is_array($offers) ? (string) ($offers['itemCondition'] ?? '') : '';

        return array(
            'title' => sanitize_text_field((string) ($product['name'] ?? '')),
            'gtin' => self::first_nonempty($product, array('gtin', 'gtin13', 'gtin12', 'gtin14', 'gtin8')),
            'mpn' => sanitize_text_field((string) ($product['mpn'] ?? '')),
            'sku' => sanitize_text_field((string) ($product['sku'] ?? '')),
            'brand' => sanitize_text_field((string) $brand),
            'model' => sanitize_text_field((string) ($product['model'] ?? '')),
            'price_raw' => $price,
            'currency' => strtoupper(sanitize_text_field((string) (is_array($offers) ? ($offers['priceCurrency'] ?? '') : ''))),
            'stock_status' => self::stock_status($availability),
            'stock_text' => sanitize_text_field($availability),
            'condition_label' => sanitize_text_field($condition),
            'url' => esc_url_raw((string) (is_array($offers) ? ($offers['url'] ?? '') : ($product['url'] ?? ''))),
        );
    }

    private static function first_offer($offers) {
        if (!is_array($offers)) {
            return array();
        }
        if (isset($offers['@type']) || isset($offers['price']) || isset($offers['lowPrice'])) {
            return $offers;
        }
        $best = array();
        $best_price = null;
        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }
            $price = self::offer_price($offer);
            if ($price !== null && ($best_price === null || $price < $best_price)) {
                $best = $offer;
                $best_price = $price;
            } elseif (!$best) {
                $best = $offer;
            }
        }
        return $best;
    }

    private static function offer_price($offer) {
        if (!is_array($offer)) {
            return null;
        }
        foreach (array('price', 'lowPrice') as $key) {
            $value = self::decimal($offer[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }
        if (!empty($offer['priceSpecification']) && is_array($offer['priceSpecification'])) {
            return self::decimal($offer['priceSpecification']['price'] ?? $offer['priceSpecification']['value'] ?? null);
        }
        return null;
    }

    private static function html_meta($html) {
        $out = array();
        $map = array(
            'price_raw' => array('product:price:amount', 'og:price:amount'),
            'currency' => array('product:price:currency', 'og:price:currency'),
            'title' => array('og:title', 'twitter:title'),
        );
        foreach ($map as $field => $properties) {
            foreach ($properties as $property) {
                $value = self::meta_value($html, $property);
                if ($value !== '') {
                    $out[$field] = 'price_raw' === $field ? self::decimal($value) : sanitize_text_field($value);
                    break;
                }
            }
        }
        if (empty($out['title']) && preg_match('#<title[^>]*>(.*?)</title>#is', (string) $html, $m)) {
            $out['title'] = sanitize_text_field(wp_strip_all_tags(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }
        foreach (array('sku', 'mpn', 'gtin', 'gtin13', 'brand', 'model') as $itemprop) {
            $value = self::itemprop_value($html, $itemprop);
            if ($value !== '') {
                $out['gtin13' === $itemprop ? 'gtin' : $itemprop] = sanitize_text_field($value);
            }
        }
        $availability = self::itemprop_value($html, 'availability');
        if ($availability !== '') {
            $out['stock_status'] = self::stock_status($availability);
        }
        return $out;
    }

    private static function meta_value($html, $property) {
        $quoted = preg_quote((string) $property, '#');
        $patterns = array(
            '#<meta[^>]+(?:property|name)=["\']' . $quoted . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>#is',
            '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $quoted . '["\'][^>]*>#is',
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, (string) $html, $m)) {
                return trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        return '';
    }

    private static function itemprop_value($html, $itemprop) {
        $quoted = preg_quote((string) $itemprop, '#');
        $patterns = array(
            '#<meta[^>]+itemprop=["\']' . $quoted . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>#is',
            '#<[^>]+itemprop=["\']' . $quoted . '["\'][^>]+content=["\']([^"\']+)["\'][^>]*>#is',
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, (string) $html, $m)) {
                return trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        return '';
    }

    private static function detect_vat($plain, $product) {
        $mode = 'unknown';
        $rate = null;
        if (is_array($product) && !empty($product['offers']) && is_array($product['offers'])) {
            $offer = self::first_offer($product['offers']);
            $spec = is_array($offer) ? ($offer['priceSpecification'] ?? array()) : array();
            if (is_array($spec) && array_key_exists('valueAddedTaxIncluded', $spec)) {
                $mode = !empty($spec['valueAddedTaxIncluded']) ? 'included' : 'excluded';
            }
        }
        if (preg_match('/(?:iva|vat)\s*(?:del\s*)?(\d{1,2}(?:[\.,]\d+)?)\s*%/', (string) $plain, $m)) {
            $rate = self::decimal($m[1]);
        }
        if (preg_match('/\b(?:iva|impuestos)\s+(?:incluido|incluidos|incl\.)\b/', (string) $plain)) {
            $mode = 'included';
        } elseif (preg_match('/\b(?:sin\s+iva|iva\s+no\s+incluido|impuestos\s+no\s+incluidos)\b/', (string) $plain)) {
            $mode = 'excluded';
        }
        return array('mode' => $mode, 'rate' => $rate);
    }

    private static function detect_shipping($plain, $product) {
        $mode = 'unknown';
        $price = null;
        if (is_array($product) && !empty($product['offers']) && is_array($product['offers'])) {
            $offer = self::first_offer($product['offers']);
            $details = is_array($offer) ? ($offer['shippingDetails'] ?? array()) : array();
            if (isset($details[0]) && is_array($details[0])) {
                $details = $details[0];
            }
            if (is_array($details) && !empty($details)) {
                $rate = $details['shippingRate'] ?? null;
                if (is_array($rate)) {
                    $price = self::decimal($rate['value'] ?? $rate['price'] ?? null);
                } else {
                    $price = self::decimal($rate);
                }
                if ($price !== null) {
                    $mode = $price <= 0 ? 'free' : 'separate';
                }
            }
        }
        if (preg_match('/\b(?:envio|portes|gastos\s+de\s+envio)\s+(?:gratis|gratuito|incluido|incluidos)\b/', (string) $plain)) {
            $mode = 'free';
            $price = 0.0;
        }
        return array('mode' => $mode, 'price' => $price);
    }

    private static function stock_status($value) {
        $value = strtolower(remove_accents((string) $value));
        if ($value === '') {
            return '';
        }
        if (false !== strpos($value, 'instock') || false !== strpos($value, 'in stock') || false !== strpos($value, 'disponible')) {
            return 'in_stock';
        }
        if (false !== strpos($value, 'outofstock') || false !== strpos($value, 'out of stock') || false !== strpos($value, 'agotado') || false !== strpos($value, 'no disponible')) {
            return 'out_of_stock';
        }
        if (false !== strpos($value, 'preorder') || false !== strpos($value, 'pre-order')) {
            return 'preorder';
        }
        return sanitize_key(substr($value, 0, 50));
    }

    private static function first_nonempty($array, $keys) {
        foreach ((array) $keys as $key) {
            if (isset($array[$key]) && trim((string) $array[$key]) !== '') {
                return sanitize_text_field((string) $array[$key]);
            }
        }
        return '';
    }

    private static function decimal($value) {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $value = preg_replace('/[^0-9,\.\-]/', '', $value);
            if (substr_count($value, ',') === 1 && substr_count($value, '.') === 0) {
                $value = str_replace(',', '.', $value);
            } elseif (substr_count($value, ',') >= 1 && substr_count($value, '.') >= 1) {
                if (strrpos($value, ',') > strrpos($value, '.')) {
                    $value = str_replace('.', '', $value);
                    $value = str_replace(',', '.', $value);
                } else {
                    $value = str_replace(',', '', $value);
                }
            }
        }
        return is_numeric($value) ? round((float) $value, 6) : null;
    }
}
