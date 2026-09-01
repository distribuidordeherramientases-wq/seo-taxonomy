<?php
/**
 * Integracion Amazon exclusiva del modulo Dependiente.
 *
 * IMPORTANTE:
 * - Reutiliza la conexion/credenciales Amazon Creators API ya existentes.
 * - No modifica la receta Amazon compartida por categorias, importador u otros modulos.
 * - Toda la politica especifica del Dependiente (fallback, limites, pagina, orden,
 *   Search Index, cache y normalizacion visible) vive aqui.
 *
 * De este modo, si mas adelante el Dependiente necesita limitar resultados,
 * cambiar Search Index o aplicar reglas distintas de las categorias, basta con
 * modificar este archivo o usar el filtro `seo_dependiente_amazon_parameters`.
 */
defined('ABSPATH') || exit;

final class SEO_Dependiente_Amazon {
    const DEFAULT_ITEM_COUNT = 6;
    const MAX_ITEM_COUNT = 10;
    const DEFAULT_RESULT_LIMIT = 6;
    const DEFAULT_FEATURE_LIMIT = 3;
    const DEFAULT_SEARCH_INDEX = 'All';
    const DEFAULT_SORT_BY = 'Relevance';
    const CACHE_TTL = 15 * MINUTE_IN_SECONDS;
    const TOKEN_TTL_BUCKETS = 2;

    private static $initialized = false;

    /**
     * Registra solo los puntos de entrada que pertenecen a Amazon/Dependiente.
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    /**
     * El API REST de Amazon queda fuera de seo-dependiente-api.php para evitar
     * mezclar la busqueda del catalogo propio con proveedores externos.
     */
    public static function register_routes() {
        register_rest_route('seo-taxonomy/v1', '/amazon-search', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'rest_search'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Callback REST. Los limites se deciden en servidor mediante parameters();
     * no se aceptan directamente desde el navegador para que el cliente no pueda
     * ampliar arbitrariamente el numero de productos consultados/devueltos.
     */
    public static function rest_search(WP_REST_Request $request) {
        $params = self::request_params($request);
        $query = self::limit_text(sanitize_text_field((string) ($params['q'] ?? '')), 160);
        $token = sanitize_text_field((string) ($params['token'] ?? ''));
        $bucket = absint($params['bucket'] ?? 0);

        $result = self::search($query, $token, $bucket, array(), array(
            'source' => 'fallback',
        ));

        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    /**
     * Parametros propios de Amazon para Dependiente.
     *
     * Estos valores NO cambian la configuracion Amazon usada por categorias,
     * exploradores ni otros consumidores de la receta compartida.
     *
     * @param array $overrides Ajustes internos puntuales del Dependiente.
     * @param array $context   Contexto para reglas/filtros futuros.
     * @return array
     */
    public static function parameters($overrides = array(), $context = array()) {
        $defaults = array(
            // Cuantos items pide el Dependiente a SearchItems.
            'item_count'    => self::DEFAULT_ITEM_COUNT,
            // Cuantos items como maximo devuelve el Dependiente al navegador.
            'result_limit'  => self::DEFAULT_RESULT_LIMIT,
            'feature_limit' => self::DEFAULT_FEATURE_LIMIT,
            'search_index'  => self::DEFAULT_SEARCH_INDEX,
            'item_page'     => 1,
            'sort_by'       => self::DEFAULT_SORT_BY,
            'cache_ttl'     => self::CACHE_TTL,
        );

        $parameters = wp_parse_args(is_array($overrides) ? $overrides : array(), $defaults);

        /**
         * Permite configurar Amazon exclusivamente para Dependiente sin tocar
         * seo_supplier_recipe_amazon_settings(), que tambien usan otros modulos.
         *
         * Ejemplo futuro:
         * add_filter('seo_dependiente_amazon_parameters', function ($p, $context) {
         *     $p['result_limit'] = 4;
         *     $p['item_count'] = 8;
         *     return $p;
         * }, 10, 2);
         */
        $parameters = apply_filters(
            'seo_dependiente_amazon_parameters',
            $parameters,
            is_array($context) ? $context : array()
        );

        if (!is_array($parameters)) {
            $parameters = $defaults;
        }

        $parameters['item_count'] = max(1, min(self::MAX_ITEM_COUNT, absint($parameters['item_count'] ?? self::DEFAULT_ITEM_COUNT)));
        $parameters['result_limit'] = max(1, min(
            $parameters['item_count'],
            absint($parameters['result_limit'] ?? self::DEFAULT_RESULT_LIMIT)
        ));
        $parameters['feature_limit'] = max(0, min(8, absint($parameters['feature_limit'] ?? self::DEFAULT_FEATURE_LIMIT)));
        $parameters['item_page'] = max(1, min(10, absint($parameters['item_page'] ?? 1)));
        $parameters['search_index'] = self::limit_text(sanitize_text_field((string) ($parameters['search_index'] ?? self::DEFAULT_SEARCH_INDEX)), 80);
        $parameters['sort_by'] = self::limit_text(sanitize_text_field((string) ($parameters['sort_by'] ?? self::DEFAULT_SORT_BY)), 80);
        $parameters['cache_ttl'] = max(MINUTE_IN_SECONDS, min(DAY_IN_SECONDS, absint($parameters['cache_ttl'] ?? self::CACHE_TTL)));

        if ('' === $parameters['search_index']) {
            $parameters['search_index'] = self::DEFAULT_SEARCH_INDEX;
        }
        if ('' === $parameters['sort_by']) {
            $parameters['sort_by'] = self::DEFAULT_SORT_BY;
        }

        return $parameters;
    }

    /**
     * Decide si el Dependiente debe ofrecer Amazon como fallback.
     */
    public static function fallback_descriptor($query, $semantic, $context = array()) {
        $empty = array(
            'provider'    => 'amazon',
            'should_load' => false,
            'reason'      => '',
            'query'       => '',
            'token'       => '',
            'bucket'      => 0,
        );

        $query = self::limit_text(sanitize_text_field((string) $query), 180);
        if ('' === trim($query) || 1 !== max(1, absint($context['page'] ?? 1))) {
            return $empty;
        }

        $total = absint($context['total'] ?? 0);
        $strategy = sanitize_key((string) ($context['strategy'] ?? 'strict'));
        $top_object_hits = absint($context['top_object_hits'] ?? 0);
        $has_object = !empty($semantic['concepts']['object']);
        $weak_strategies = array('broad_fallback', 'catalog_fallback', 'index_unavailable');

        $reason = '';
        if (0 === $total) {
            $reason = 'no_catalog_results';
        } elseif (in_array($strategy, $weak_strategies, true)) {
            $reason = 'weak_catalog_match';
        } elseif ($has_object && 0 === $top_object_hits) {
            // Hay productos relacionados con la accion o contexto, pero ninguno
            // coincide directamente con el objeto que el cliente ha pedido.
            $reason = 'requested_object_not_found';
        }

        if ('' === $reason) {
            return $empty;
        }

        if (!self::ensure_recipe() || !self::credentials_ready()) {
            return $empty;
        }

        $amazon_query = self::build_search_query($query, $semantic);
        if ('' === $amazon_query) {
            return $empty;
        }

        $bucket = (int) floor(time() / HOUR_IN_SECONDS);

        return array(
            'provider'    => 'amazon',
            'should_load' => true,
            'reason'      => $reason,
            'query'       => $amazon_query,
            'token'       => self::make_token($amazon_query, $bucket),
            'bucket'      => $bucket,
        );
    }

    /**
     * Ejecuta SearchItems con una politica aislada para Dependiente.
     *
     * @param string $query
     * @param string $token
     * @param int    $bucket
     * @param array  $overrides Parametros internos opcionales.
     * @param array  $context   Contexto para filtros/reglas futuras.
     * @return array|WP_Error
     */
    public static function search($query, $token, $bucket, $overrides = array(), $context = array()) {
        $query = self::limit_text(sanitize_text_field((string) $query), 160);
        $bucket = (int) $bucket;

        if ('' === $query || !self::valid_token($query, (string) $token, $bucket)) {
            return new WP_Error('seo_dependiente_amazon_invalid_request', 'Solicitud externa no valida.', array('status' => 403));
        }
        if (!self::ensure_recipe() || !self::credentials_ready()) {
            return new WP_Error('seo_dependiente_amazon_unavailable', 'Amazon no esta disponible.', array('status' => 503));
        }

        $context = wp_parse_args(is_array($context) ? $context : array(), array(
            'source' => 'fallback',
            'query'  => $query,
        ));
        $parameters = self::parameters($overrides, $context);
        $settings = seo_supplier_recipe_amazon_settings();

        $cache_key = 'seo_dep_amz_' . md5(
            self::normalize($query) . '|' .
            (string) ($settings['marketplace'] ?? '') . '|' .
            (string) ($settings['partner_tag'] ?? '') . '|' .
            wp_json_encode(array(
                'search_index' => $parameters['search_index'],
                'item_count'   => $parameters['item_count'],
                'result_limit' => $parameters['result_limit'],
                'feature_limit'=> $parameters['feature_limit'],
                'item_page'    => $parameters['item_page'],
                'sort_by'      => $parameters['sort_by'],
            ))
        );
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        // Se pasan explicitamente los parametros del Dependiente. Asi un cambio
        // del item_count/search_index del explorador Amazon no altera este flujo.
        $response = seo_supplier_recipe_amazon_search_items($query, array(
            'search_index' => $parameters['search_index'],
            'item_count'   => $parameters['item_count'],
            'item_page'    => $parameters['item_page'],
            'sort_by'      => $parameters['sort_by'],
        ));
        if (is_wp_error($response)) {
            return new WP_Error('seo_dependiente_amazon_search_failed', 'No se han podido cargar alternativas de Amazon.', array('status' => 502));
        }

        $normalized = seo_supplier_recipe_amazon_normalize_preview($response);
        $items = array();
        foreach (array_slice((array) ($normalized['products'] ?? array()), 0, $parameters['result_limit']) as $product) {
            $url = esc_url_raw((string) ($product['url'] ?? ''));
            $title = sanitize_text_field((string) ($product['title'] ?? ''));
            if (!$url || !$title) {
                continue;
            }

            $features = array();
            foreach (array_slice((array) ($product['features'] ?? array()), 0, $parameters['feature_limit']) as $feature) {
                $feature = self::limit_text(sanitize_text_field((string) $feature), 180);
                if ('' !== $feature) {
                    $features[] = $feature;
                }
            }

            $items[] = array(
                'asin'     => sanitize_text_field((string) ($product['asin'] ?? '')),
                'title'    => $title,
                'brand'    => sanitize_text_field((string) ($product['brand'] ?? '')),
                'image'    => esc_url_raw((string) ($product['image_url'] ?? '')),
                'price'    => sanitize_text_field((string) ($product['price'] ?? '')),
                'url'      => $url,
                'features' => $features,
            );
        }

        $result = array(
            'provider' => 'amazon',
            'query'    => $query,
            'items'    => $items,
            'total'    => count($items),
            // Util para diagnostico y para futuros controles del Dependiente.
            'limits'   => array(
                'requested' => $parameters['item_count'],
                'returned'  => $parameters['result_limit'],
                'page'      => $parameters['item_page'],
            ),
        );
        set_transient($cache_key, $result, $parameters['cache_ttl']);

        return $result;
    }

    private static function build_search_query($query, $semantic) {
        $parts = array();

        foreach ((array) ($semantic['groups'] ?? array()) as $group) {
            $role = sanitize_key((string) ($group['role'] ?? 'term'));
            if (in_array($role, array('intent', 'state'), true)) {
                continue;
            }
            $canonical = self::normalize((string) ($group['canonical'] ?? ''));
            if ($canonical && !in_array($canonical, $parts, true)) {
                $parts[] = $canonical;
            }
        }

        // Si la capa semantica no ha podido extraer un objeto o terminos utiles,
        // Amazon recibe la frase original en lugar de perder contexto.
        $search = $parts ? implode(' ', array_slice($parts, 0, 10)) : self::normalize($query);
        return self::limit_text(trim($search), 160);
    }

    /**
     * Carga la receta Amazon compartida solo como transporte/normalizador.
     * No se cambian sus settings desde el Dependiente.
     */
    private static function ensure_recipe() {
        if (function_exists('seo_supplier_recipe_amazon_search_items') && function_exists('seo_supplier_recipe_amazon_normalize_preview')) {
            return true;
        }

        $path = defined('SEO_SYSTEM_PATH')
            ? SEO_SYSTEM_PATH . 'includes/import-export/suppliers/recipes/import_amazon.php'
            : dirname(__DIR__) . '/import-export/suppliers/recipes/import_amazon.php';

        if (is_readable($path)) {
            require_once $path;
        }

        return function_exists('seo_supplier_recipe_amazon_search_items')
            && function_exists('seo_supplier_recipe_amazon_normalize_preview');
    }

    private static function credentials_ready() {
        if (!function_exists('seo_supplier_recipe_amazon_settings')) {
            return false;
        }
        $settings = seo_supplier_recipe_amazon_settings();
        return !empty($settings['client_id'])
            && !empty($settings['client_secret'])
            && !empty($settings['partner_tag']);
    }

    private static function make_token($query, $bucket) {
        return hash_hmac('sha256', self::normalize($query) . '|' . (int) $bucket, wp_salt('auth'));
    }

    private static function valid_token($query, $token, $bucket) {
        if (!$bucket || '' === $token) {
            return false;
        }
        $current = (int) floor(time() / HOUR_IN_SECONDS);
        if ($bucket > $current || ($current - $bucket) >= self::TOKEN_TTL_BUCKETS) {
            return false;
        }
        return hash_equals(self::make_token($query, $bucket), $token);
    }

    private static function request_params(WP_REST_Request $request) {
        $json = $request->get_json_params();
        if (is_array($json)) {
            return $json;
        }
        $params = $request->get_params();
        return is_array($params) ? $params : array();
    }

    private static function normalize($value) {
        if (class_exists('SEO_Dependiente_Index')) {
            return SEO_Dependiente_Index::normalize((string) $value);
        }
        $value = remove_accents(wp_strip_all_tags((string) $value));
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        return trim((string) $value);
    }

    private static function limit_text($value, $length) {
        $value = (string) $value;
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }
}

SEO_Dependiente_Amazon::init();
