<?php
/**
 * Canonical product identity and matching rules for Ojeador.
 *
 * @package SEOSystem
 * @subpackage Ojeador
 * @since 0.1.0
 */

defined('ABSPATH') || exit;

final class SEO_Ojeador_Identity {
    public static function from_product($product_id) {
        $product_id = absint($product_id);
        if ($product_id < 1 || !function_exists('wc_get_product')) {
            return new WP_Error('ojeador_identity_wc', 'WooCommerce no esta disponible o el producto no es valido.');
        }
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('ojeador_identity_product', 'No se encontro el producto WooCommerce.');
        }

        $name = (string) $product->get_name();
        $sku = (string) $product->get_sku();
        $gtin = self::first_meta($product_id, array('_global_unique_id', '_gtin', 'gtin', '_ean', 'ean', 'ean13', 'gtin13', '_barcode', 'barcode'));
        if (method_exists($product, 'get_global_unique_id')) {
            $native_gtin = trim((string) $product->get_global_unique_id());
            if ($native_gtin !== '') {
                $gtin = $native_gtin;
            }
        }
        $gtin = self::normalize_gtin($gtin);

        $mpn = self::first_meta($product_id, array('_seo_proveedor_mpn', '_mpn', 'mpn', '_manufacturer_part_number', 'manufacturer_part_number'));
        $brand = self::brand($product_id);
        $model = self::first_meta($product_id, array('_seo_modelo', '_model', 'model', '_modelo', 'modelo'));
        if ($model === '') {
            $model = self::model_from_title($name, $brand);
        }

        $source_provider = sanitize_text_field((string) get_post_meta($product_id, '_seo_proveedor', true));
        $status = ($gtin !== '' || $mpn !== '' || $model !== '' || $sku !== '') ? 'active' : 'weak_identity';

        return array(
            'object_id' => $product_id,
            'name' => sanitize_text_field($name),
            'normalized_name' => self::normalize_text($name),
            'sku' => sanitize_text_field($sku),
            'gtin' => $gtin,
            'mpn' => sanitize_text_field($mpn),
            'brand' => sanitize_text_field($brand),
            'model' => sanitize_text_field($model),
            'source_provider' => $source_provider,
            'status' => $status,
            'priority' => 5,
            'scan_interval_hours' => 168,
        );
    }

    public static function match($identity, $observed, $hint = array()) {
        $identity = is_array($identity) ? $identity : array();
        $observed = is_array($observed) ? $observed : array();
        $hint = is_array($hint) ? $hint : array();

        if (!empty($hint['object_id_exact'])) {
            return self::result('provider_object_id', 0.999, 'confirmed');
        }

        $a_gtin = self::normalize_gtin($identity['gtin'] ?? '');
        $b_gtin = self::normalize_gtin($observed['observed_gtin'] ?? $observed['gtin'] ?? '');
        if ($a_gtin !== '' && $b_gtin !== '' && hash_equals($a_gtin, $b_gtin)) {
            return self::result('gtin', 1.0, 'confirmed');
        }

        $a_mpn = self::normalize_code($identity['mpn'] ?? '');
        $b_mpn = self::normalize_code($observed['observed_mpn'] ?? $observed['mpn'] ?? '');
        $brand_ok = self::brand_compatible($identity['brand'] ?? '', $observed['observed_brand'] ?? $observed['brand'] ?? '');
        if ($a_mpn !== '' && $b_mpn !== '' && hash_equals($a_mpn, $b_mpn)) {
            return self::result('mpn', $brand_ok ? 0.995 : 0.965, $brand_ok ? 'confirmed' : 'probable');
        }

        $a_model = self::normalize_code($identity['model'] ?? '');
        $b_model = self::normalize_code($observed['observed_model'] ?? $observed['model'] ?? '');
        if ($a_model !== '' && $b_model !== '' && hash_equals($a_model, $b_model) && $brand_ok) {
            return self::result('brand_model', 0.955, 'probable');
        }

        $a_sku = self::normalize_code($identity['sku'] ?? '');
        $b_sku = self::normalize_code($observed['sku'] ?? '');
        if ($a_sku !== '' && $b_sku !== '' && hash_equals($a_sku, $b_sku) && $brand_ok) {
            return self::result('brand_sku', 0.920, 'probable');
        }

        $own_name = self::normalize_text($identity['name'] ?? $identity['canonical_name'] ?? '');
        $external_name = self::normalize_text($observed['observed_title'] ?? $observed['title'] ?? '');
        $score = self::title_similarity($own_name, $external_name);

        if ($brand_ok && $a_model !== '' && $external_name !== '' && false !== strpos(self::normalize_code($external_name), $a_model)) {
            $score = max($score, 0.90);
        }

        if ($score >= 0.92 && $brand_ok) {
            return self::result('title_brand', min(0.94, $score), 'probable');
        }
        if ($score >= 0.80) {
            return self::result('title_similarity', min(0.89, $score), 'review');
        }

        return self::result('no_match', $score, 'rejected');
    }

    private static function result($method, $confidence, $status) {
        return array(
            'method' => sanitize_key((string) $method),
            'confidence' => round(max(0, min(1, (float) $confidence)), 5),
            'status' => sanitize_key((string) $status),
        );
    }

    public static function normalize_text($value) {
        $value = wp_strip_all_tags((string) $value);
        $value = remove_accents($value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);
        return trim((string) $value);
    }

    public static function normalize_code($value) {
        $value = remove_accents((string) $value);
        $value = strtoupper($value);
        return preg_replace('/[^A-Z0-9]/', '', $value);
    }

    public static function normalize_gtin($value) {
        $value = preg_replace('/\D+/', '', (string) $value);
        if (strlen((string) $value) < 8 || strlen((string) $value) > 14) {
            return '';
        }
        return (string) $value;
    }

    private static function first_meta($product_id, $keys) {
        foreach ((array) $keys as $key) {
            $value = trim((string) get_post_meta($product_id, (string) $key, true));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function brand($product_id) {
        $brand = trim((string) get_post_meta($product_id, '_seo_marca_proveedor', true));
        if ($brand !== '') {
            return $brand;
        }

        $taxonomies = array();
        if (function_exists('seo_product_brand_taxonomy')) {
            $taxonomy = (string) seo_product_brand_taxonomy();
            if ($taxonomy !== '') {
                $taxonomies[] = $taxonomy;
            }
        }
        $taxonomies = array_values(array_unique(array_merge($taxonomies, array('product_brand', 'pwb-brand', 'yith_product_brand', 'pa_marca', 'pa_brand'))));
        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = wp_get_post_terms($product_id, $taxonomy, array('fields' => 'names'));
            if (!is_wp_error($terms) && !empty($terms[0])) {
                return sanitize_text_field((string) $terms[0]);
            }
        }
        return '';
    }

    private static function model_from_title($title, $brand = '') {
        $title = strtoupper(remove_accents((string) $title));
        $brand = strtoupper(remove_accents((string) $brand));
        if ($brand !== '') {
            $title = str_replace($brand, ' ', $title);
        }
        preg_match_all('/\b[A-Z]{1,6}[A-Z0-9-]*\d[A-Z0-9-]{1,15}\b/', $title, $matches);
        if (empty($matches[0])) {
            return '';
        }
        usort($matches[0], static function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        return sanitize_text_field((string) $matches[0][0]);
    }

    private static function brand_compatible($left, $right) {
        $left = self::normalize_text($left);
        $right = self::normalize_text($right);
        if ($left === '' || $right === '') {
            return true;
        }
        return $left === $right || false !== strpos($left, $right) || false !== strpos($right, $left);
    }

    private static function title_similarity($left, $right) {
        if ($left === '' || $right === '') {
            return 0.0;
        }
        $a = array_values(array_unique(array_filter(explode(' ', $left), static function ($t) { return strlen($t) > 1; })));
        $b = array_values(array_unique(array_filter(explode(' ', $right), static function ($t) { return strlen($t) > 1; })));
        if (!$a || !$b) {
            return 0.0;
        }
        $intersection = array_intersect($a, $b);
        $union = array_unique(array_merge($a, $b));
        $jaccard = count($union) ? count($intersection) / count($union) : 0.0;
        similar_text($left, $right, $percent);
        $char_score = max(0, min(1, ((float) $percent) / 100));
        return round(($jaccard * 0.65) + ($char_score * 0.35), 5);
    }
}
