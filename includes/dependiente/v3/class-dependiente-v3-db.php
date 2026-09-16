<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_V3_DB {
    private static $exists = array();

    public static function table($suffix) {
        global $wpdb;
        return $wpdb->prefix . ltrim((string) $suffix, '_');
    }

    public static function exists($suffix) {
        global $wpdb;
        $table = self::table($suffix);
        if (isset(self::$exists[$table])) {
            return self::$exists[$table];
        }
        self::$exists[$table] = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;
        return self::$exists[$table];
    }

    public static function normalize($value) {
        $value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
        $value = remove_accents($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/(?<=\d)(?=[a-z])|(?<=[a-z])(?=\d)/u', ' ', $value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string) $value);
    }

    public static function token_variants($term) {
        $term = self::normalize($term);
        if ('' === $term) {
            return array();
        }
        $out = array($term);
        if (false === strpos($term, ' ') && strlen($term) >= 4) {
            if (substr($term, -2) === 'es' && strlen($term) > 5) {
                $out[] = substr($term, 0, -2);
            } elseif (substr($term, -1) === 's' && strlen($term) > 4) {
                $out[] = substr($term, 0, -1);
            } else {
                $out[] = $term . 's';
                $out[] = $term . 'es';
            }
        }
        return array_values(array_unique(array_filter($out)));
    }

    public static function contains_term($haystack, $needle) {
        $haystack = self::normalize($haystack);
        $needle = self::normalize($needle);
        if ('' === $haystack || '' === $needle) {
            return false;
        }
        return false !== strpos(' ' . $haystack . ' ', ' ' . $needle . ' ');
    }

    public static function contains_any($haystack, $variants) {
        foreach ((array) $variants as $variant) {
            if (self::contains_term($haystack, $variant)) {
                return true;
            }
        }
        return false;
    }

    public static function json_array($value) {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || '' === trim($value)) {
            return array();
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : array();
    }

    public static function flatten_strings($value) {
        $out = array();
        self::flatten_walk($value, $out);
        return array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize'), $out))));
    }

    private static function flatten_walk($value, &$out) {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $out[] = $key;
                }
                self::flatten_walk($item, $out);
            }
            return;
        }
        if (is_scalar($value)) {
            $out[] = (string) $value;
        }
    }

    public static function ngrams($normalized, $max_words = 5) {
        $words = array_values(array_filter(explode(' ', self::normalize($normalized))));
        $count = count($words);
        $ngrams = array();
        for ($size = min($max_words, $count); $size >= 1; $size--) {
            for ($i = 0; $i <= $count - $size; $i++) {
                $ngrams[] = implode(' ', array_slice($words, $i, $size));
            }
        }
        return array_values(array_unique($ngrams));
    }

    public static function placeholders($count, $placeholder = '%s') {
        return implode(',', array_fill(0, max(0, (int) $count), $placeholder));
    }

    public static function product_is_published($product_id) {
        $post = get_post(absint($product_id));
        return $post instanceof WP_Post && 'product' === $post->post_type && 'publish' === $post->post_status;
    }

    public static function health() {
        $tables = array(
            'seo_dependiente_index',
            'seo_interprete_lexicon',
            'seo_interprete_lexicon_evidence',
            'seo_dependiente_semantics',
            'seo_vocabulary',
            'seo_object_vocabulary',
            'seo_type_role_map',
        );
        $result = array();
        foreach ($tables as $table) {
            $result[$table] = self::exists($table);
        }
        return $result;
    }
}
