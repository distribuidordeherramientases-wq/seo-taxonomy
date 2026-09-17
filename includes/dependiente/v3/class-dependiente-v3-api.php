<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_V3_API {
    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route('seo-taxonomy/v3', '/search', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'search'),
            'permission_callback' => '__return_true',
            'args' => array(
                'q' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field'),
                'page' => array('required' => false, 'sanitize_callback' => 'absint'),
                'category' => array('required' => false, 'sanitize_callback' => 'sanitize_title'),
            ),
        ));

        register_rest_route('seo-taxonomy/v3', '/health', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'health'),
            'permission_callback' => static function () {
                return current_user_can('manage_options');
            },
        ));
    }

    public static function search(WP_REST_Request $request) {
        $started = microtime(true);
        $query = trim((string) $request->get_param('q'));
        if ('' === $query) {
            return new WP_Error('dependiente_v3_empty_query', 'Escribe qué necesitas buscar.', array('status' => 400));
        }
        if (function_exists('mb_substr')) {
            $query = mb_substr($query, 0, 180, 'UTF-8');
        } else {
            $query = substr($query, 0, 180);
        }

        $interpretation = SEO_Dependiente_V3_Interpreter::interpret($query);
        $catalog = SEO_Dependiente_V3_Catalog::search($interpretation, array(
            'page' => max(1, absint($request->get_param('page'))),
            'category' => sanitize_title((string) $request->get_param('category')),
            'per_page' => 18,
        ));
        if (is_wp_error($catalog)) {
            return $catalog;
        }

        $debug = $catalog['debug'];
        $debug['request_ms'] = round((microtime(true) - $started) * 1000, 1);
        $debug['tables'] = SEO_Dependiente_V3_DB::health();

        $public_interpretation = array(
            'raw' => $interpretation['raw'],
            'normalized' => $interpretation['normalized'],
            'filtered' => $interpretation['filtered'],
            'groups' => array_map(static function ($group) {
                return array(
                    'canonical' => (string) ($group['canonical'] ?? ''),
                    'variants' => array_values((array) ($group['variants'] ?? array())),
                    'role' => (string) ($group['role'] ?? 'term'),
                    'source' => (string) ($group['source'] ?? ''),
                );
            }, (array) $interpretation['groups']),
            'actions' => array_values((array) $interpretation['actions']),
            'concepts' => (array) $interpretation['concepts'],
            'routes' => array_values((array) $interpretation['routes']),
            'ignored' => array_values((array) $interpretation['ignored']),
            'semantic_hits' => array_values((array) $interpretation['semantic_hits']),
            'lexicon_hits' => array_values((array) $interpretation['lexicon_hits']),
            'vocabulary_hits' => array_values((array) $interpretation['vocabulary_hits']),
        );

        return rest_ensure_response(array(
            'version' => SEO_DEPENDIENTE_VERSION,
            'build' => '3.1.0-visual-guidance',
            'query' => $query,
            'interpretation' => $public_interpretation,
            // categories se conserva para no romper consumidores V3 anteriores.
            'categories' => array_values((array) ($catalog['categories'] ?? array())),
            'suggestions' => array_values((array) ($catalog['suggestions'] ?? $catalog['categories'] ?? array())),
            'decision' => (array) ($catalog['decision'] ?? array()),
            'products' => array_values((array) ($catalog['products'] ?? array())),
            'pagination' => (array) ($catalog['pagination'] ?? array()),
            'debug' => $debug,
        ));
    }

    public static function health() {
        return rest_ensure_response(array(
            'version' => SEO_DEPENDIENTE_VERSION,
            'tables' => SEO_Dependiente_V3_DB::health(),
            'woocommerce' => class_exists('WooCommerce'),
        ));
    }
}
