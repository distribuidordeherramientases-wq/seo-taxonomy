<?php
/**
 * Amazon para el modulo Dependiente.
 *
 * Dos capacidades independientes:
 * - Amazon Afiliados (Partner Tag): siempre que este configurado permite crear
 *   busquedas afiliadas sin API.
 * - Creators API (opcional): si la cuenta es elegible y hay credenciales,
 *   enriquece el bloque con productos concretos. Si falla, se vuelve
 *   automaticamente al modo afiliado sin romper la busqueda principal.
 *
 * @version 0.1.22
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

    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route('seo-taxonomy/v1', '/amazon-search', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'rest_search'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function rest_search(WP_REST_Request $request) {
        $params = self::request_params($request);
        $query = self::limit_text(sanitize_text_field((string) ($params['q'] ?? '')), 160);
        $token = sanitize_text_field((string) ($params['token'] ?? ''));
        $bucket = absint($params['bucket'] ?? 0);

        $result = self::search($query, $token, $bucket, array(), array(
            'source' => 'complementary',
        ));

        return is_wp_error($result) ? $result : rest_ensure_response($result);
    }

    public static function parameters($overrides = array(), $context = array()) {
        $defaults = array(
            'item_count'    => self::DEFAULT_ITEM_COUNT,
            'result_limit'  => self::DEFAULT_RESULT_LIMIT,
            'feature_limit' => self::DEFAULT_FEATURE_LIMIT,
            'search_index'  => self::DEFAULT_SEARCH_INDEX,
            'item_page'     => 1,
            'sort_by'       => self::DEFAULT_SORT_BY,
            'cache_ttl'     => self::CACHE_TTL,
        );

        $parameters = wp_parse_args(is_array($overrides) ? $overrides : array(), $defaults);
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
     * Amazon es la fuente 1C y se prepara siempre en la primera pagina cuando
     * exista Partner Tag. No depende de cuantos resultados propios haya.
     */
    public static function fallback_descriptor($query, $semantic, $context = array()) {
        $empty = array(
            'provider'    => 'amazon',
            'should_load' => false,
            'available'   => false,
            'mode'        => 'affiliate',
            'reason'      => '',
            'status'      => 'inactive',
            'query'       => '',
            'token'       => '',
            'bucket'      => 0,
            'context_images' => array(),
        );

        $query = self::limit_text(sanitize_text_field((string) $query), 180);
        $context_images = array();
        foreach ((array) ($context['context_images'] ?? array()) as $context_image) {
            $context_image = esc_url_raw((string) $context_image);
            if ('' === $context_image || in_array($context_image, $context_images, true)) {
                continue;
            }
            $context_images[] = $context_image;
            if (count($context_images) >= 6) {
                break;
            }
        }
        $empty['context_images'] = $context_images;

        if ('' === trim($query)) {
            $empty['status'] = 'empty_query';
            return $empty;
        }

        $amazon_query = self::build_search_query($query, $semantic);
        if ('' === $amazon_query) {
            $empty['status'] = 'query_unusable';
            return $empty;
        }

        if (!self::ensure_recipe() || !self::affiliate_ready()) {
            $empty['query'] = $amazon_query;
            $empty['status'] = 'partner_tag_missing';
            return $empty;
        }

        $bucket = (int) floor(time() / HOUR_IN_SECONDS);
        $creators = self::creators_ready();

        return array(
            'provider'    => 'amazon',
            'should_load' => true,
            'available'   => true,
            'mode'        => $creators ? 'creators_or_affiliate' : 'affiliate',
            'reason'      => 'complementary_search',
            'status'      => $creators ? 'creators_optional' : 'affiliate_ready',
            'query'       => $amazon_query,
            'token'       => self::make_token($amazon_query, $bucket),
            'bucket'      => $bucket,
            'context_images' => $context_images,
        );
    }

    /**
     * Devuelve fichas Creators cuando sea posible y búsquedas afiliadas cuando
     * Creators no esté configurada, no sea elegible o responda con error.
     */
    public static function search($query, $token, $bucket, $overrides = array(), $context = array()) {
        $query = self::limit_text(sanitize_text_field((string) $query), 160);
        $bucket = (int) $bucket;

        if ('' === $query || !self::valid_token($query, (string) $token, $bucket)) {
            return new WP_Error('seo_dependiente_amazon_invalid_request', 'Solicitud externa no valida.', array('status' => 403));
        }
        if (!self::ensure_recipe() || !self::affiliate_ready()) {
            return new WP_Error('seo_dependiente_amazon_partner_tag_missing', 'Falta configurar el Partner Tag de Amazon Afiliados.', array('status' => 503));
        }

        $context = wp_parse_args(is_array($context) ? $context : array(), array(
            'source' => 'complementary',
            'query'  => $query,
        ));
        $parameters = self::parameters($overrides, $context);
        $settings = seo_supplier_recipe_amazon_settings();

        $cache_key = 'seo_dep_amz_019_' . md5(
            self::normalize($query) . '|' .
            (string) ($settings['marketplace'] ?? '') . '|' .
            (string) ($settings['partner_tag'] ?? '') . '|' .
            (self::creators_ready() ? 'creators' : 'affiliate') . '|' .
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

        $creators_error = '';
        if (self::creators_ready()
            && function_exists('seo_supplier_recipe_amazon_search_items')
            && function_exists('seo_supplier_recipe_amazon_normalize_preview')) {
            $response = seo_supplier_recipe_amazon_search_items($query, array(
                'search_index' => $parameters['search_index'],
                'item_count'   => $parameters['item_count'],
                'item_page'    => $parameters['item_page'],
                'sort_by'      => $parameters['sort_by'],
            ));

            if (!is_wp_error($response)) {
                $normalized = seo_supplier_recipe_amazon_normalize_preview($response);
                $items = self::normalize_creator_items(
                    (array) ($normalized['products'] ?? array()),
                    $parameters['result_limit'],
                    $parameters['feature_limit']
                );
                if ($items) {
                    $result = array(
                        'provider' => 'amazon',
                        'mode'     => 'creators',
                        'query'    => $query,
                        'items'    => $items,
                        'total'    => count($items),
                        'limits'   => array(
                            'requested' => $parameters['item_count'],
                            'returned'  => $parameters['result_limit'],
                            'page'      => $parameters['item_page'],
                        ),
                    );
                    set_transient($cache_key, $result, $parameters['cache_ttl']);
                    return $result;
                }
                $creators_error = 'empty_result';
            } else {
                $creators_error = sanitize_key((string) $response->get_error_code());
            }
        }

        // Ruta estable sin API: la misma idea que ya usan las plantillas DHT.
        $items = self::affiliate_items($query, $parameters['result_limit']);
        $result = array(
            'provider'       => 'amazon',
            'mode'           => 'affiliate',
            'query'          => $query,
            'items'          => $items,
            'total'          => count($items),
            'creators_error' => $creators_error,
            'limits'         => array(
                'requested' => count($items),
                'returned'  => count($items),
                'page'      => 1,
            ),
        );
        set_transient($cache_key, $result, $parameters['cache_ttl']);
        return $result;
    }

    private static function normalize_creator_items($products, $result_limit, $feature_limit) {
        $items = array();
        foreach (array_slice((array) $products, 0, absint($result_limit)) as $product) {
            $url = esc_url_raw((string) ($product['url'] ?? ''));
            $title = sanitize_text_field((string) ($product['title'] ?? ''));
            if (!$url || !$title) {
                continue;
            }

            $features = array();
            foreach (array_slice((array) ($product['features'] ?? array()), 0, absint($feature_limit)) as $feature) {
                $feature = self::limit_text(sanitize_text_field((string) $feature), 180);
                if ('' !== $feature) {
                    $features[] = $feature;
                }
            }

            $items[] = array(
                'type'     => 'product',
                'asin'     => sanitize_text_field((string) ($product['asin'] ?? '')),
                'title'    => $title,
                'brand'    => sanitize_text_field((string) ($product['brand'] ?? '')),
                'image'    => esc_url_raw((string) ($product['image_url'] ?? '')),
                'price'    => sanitize_text_field((string) ($product['price'] ?? '')),
                'url'      => $url,
                'features' => $features,
            );
        }
        return $items;
    }

    /**
     * Tarjetas de intencion sin API. No inventan fichas, precios ni imagenes de
     * Amazon: cada tarjeta abre una busqueda real de amazon.es con Partner Tag.
     */
    private static function affiliate_items($query, $limit) {
        $query = trim(self::normalize($query));
        if ('' === $query) {
            return array();
        }

        $intents = array(
            array(
                'kind'        => 'direct',
                'label'       => self::label_from_query($query),
                'query'       => $query,
                'description' => 'Explora en Amazon opciones que responden directamente a esta búsqueda.',
            ),
            array(
                'kind'        => 'professional',
                'label'       => 'Opciones profesionales',
                'query'       => $query . ' profesional',
                'description' => 'Alternativas orientadas a uso frecuente, taller o trabajo profesional.',
            ),
            array(
                'kind'        => 'variants',
                'label'       => 'Variantes y medidas',
                'query'       => $query . ' medidas capacidades',
                'description' => 'Compara formatos, medidas, capacidades y configuraciones relacionadas.',
            ),
            array(
                'kind'        => 'kit',
                'label'       => 'Kits y conjuntos',
                'query'       => 'kit ' . $query,
                'description' => 'Conjuntos que agrupan piezas, medidas o accesorios de la misma familia.',
            ),
            array(
                'kind'        => 'accessories',
                'label'       => 'Accesorios y complementos',
                'query'       => 'accesorios ' . $query,
                'description' => 'Complementos relacionados que pueden ampliar las opciones de uso.',
            ),
        );

        $seen = array();
        $items = array();
        foreach ($intents as $intent) {
            $intent_query = trim((string) $intent['query']);
            $key = sanitize_title(remove_accents($intent_query));
            if ('' === $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $url = self::affiliate_search_url($intent_query);
            if ('' === $url) {
                continue;
            }
            $items[] = array(
                'type'        => 'search',
                'kind'        => sanitize_key((string) $intent['kind']),
                'title'       => sanitize_text_field((string) $intent['label']),
                'query'       => sanitize_text_field($intent_query),
                'description' => sanitize_text_field((string) $intent['description']),
                'url'         => esc_url_raw($url),
                'brand'       => 'Amazon',
                'image'       => '',
                'price'       => '',
                'features'    => array(),
            );
            if (count($items) >= max(1, absint($limit))) {
                break;
            }
        }
        return $items;
    }

    private static function label_from_query($query) {
        $query = trim((string) $query);
        if ('' === $query) {
            return 'Ver opciones en Amazon';
        }
        if (function_exists('mb_convert_case')) {
            return mb_convert_case($query, MB_CASE_TITLE, 'UTF-8');
        }
        return ucwords($query);
    }

    private static function affiliate_search_url($query) {
        if (function_exists('seo_supplier_recipe_amazon_affiliate_search_url')) {
            return (string) seo_supplier_recipe_amazon_affiliate_search_url($query);
        }
        if (!function_exists('seo_supplier_recipe_amazon_settings')) {
            return '';
        }
        $settings = seo_supplier_recipe_amazon_settings();
        $tag = sanitize_text_field((string) ($settings['partner_tag'] ?? ''));
        if ('' === trim($query) || '' === $tag) {
            return '';
        }
        return add_query_arg(array('k' => $query, 'tag' => $tag), 'https://www.amazon.es/s');
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

        $search = $parts ? implode(' ', array_slice($parts, 0, 10)) : self::normalize($query);
        return self::limit_text(trim($search), 160);
    }

    private static function ensure_recipe() {
        if (function_exists('seo_supplier_recipe_amazon_settings')) {
            return true;
        }

        $path = defined('SEO_SYSTEM_PATH')
            ? SEO_SYSTEM_PATH . 'includes/import-export/suppliers/recipes/import_amazon.php'
            : dirname(__DIR__) . '/import-export/suppliers/recipes/import_amazon.php';

        if (is_readable($path)) {
            require_once $path;
        }

        return function_exists('seo_supplier_recipe_amazon_settings');
    }

    private static function affiliate_ready() {
        if (function_exists('seo_supplier_recipe_amazon_affiliate_ready')) {
            return (bool) seo_supplier_recipe_amazon_affiliate_ready();
        }
        if (!function_exists('seo_supplier_recipe_amazon_settings')) {
            return false;
        }
        $settings = seo_supplier_recipe_amazon_settings();
        return !empty($settings['partner_tag']);
    }

    private static function creators_ready() {
        if (function_exists('seo_supplier_recipe_amazon_creators_ready')) {
            return (bool) seo_supplier_recipe_amazon_creators_ready();
        }
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
