<?php

defined('ABSPATH') || exit;

final class SEO_Dependiente_API {
    const CANDIDATE_LIMIT = 1600;

    public static function register_routes() {
        register_rest_route('seo-taxonomy/v1', '/bootstrap', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array(__CLASS__, 'bootstrap'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('seo-taxonomy/v1', '/search', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'search'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('seo-taxonomy/v1', '/compare', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'compare'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function bootstrap() {
        $ready = self::woocommerce_ready();
        if (is_wp_error($ready)) {
            return $ready;
        }
        self::ensure_initial_index();

        $rows = SEO_Dependiente_Index::get_rows(1200);
        $documents = array_map(array('SEO_Dependiente_Index', 'decode_row'), $rows);
        $facets = self::build_facets($documents);
        $max_cards = min(12, max(4, absint(SEO_Dependiente_Plugin::option('menu_cards', 8))));

        $actions = isset($facets['vocabulary']['aplicacion']) ? $facets['vocabulary']['aplicacion'] : array();
        $action_type = 'vocabulary';
        $action_group = 'aplicacion';
        if (!$actions) {
            $actions = $facets['tags'];
            $action_type = 'tags';
            $action_group = '';
        }
        if (!$actions) {
            $actions = $facets['categories'];
            $action_type = 'categories';
        }

        $tools = isset($facets['vocabulary']['plataforma']) ? $facets['vocabulary']['plataforma'] : array();
        $tool_type = 'vocabulary';
        $tool_group = 'plataforma';
        if (!$tools) {
            $attribute_tools = self::tool_attribute_items($facets['attributes']);
            $tools = $attribute_tools['items'];
            $tool_type = 'attributes';
            $tool_group = $attribute_tools['key'];
        }
        if (!$tools) {
            $tools = isset($facets['vocabulary']['tipo']) ? $facets['vocabulary']['tipo'] : array();
            $tool_type = 'vocabulary';
            $tool_group = 'tipo';
        }

        $actions = self::make_cards(array_slice($actions, 0, $max_cards), $action_type, $action_group, $documents);
        $tools = self::make_cards(array_slice($tools, 0, $max_cards), $tool_type, $tool_group, $documents);

        $examples = array();
        if (!empty($actions[0]['label'])) {
            $examples[] = 'Necesito ' . self::lower_first($actions[0]['label']);
        }
        if (!empty($tools[0]['label'])) {
            $examples[] = 'Busco algo compatible con ' . $tools[0]['label'];
        }
        if (!empty($facets['categories'][0]['label'])) {
            $examples[] = 'Quiero comparar opciones de ' . self::lower_first($facets['categories'][0]['label']);
        }
        $examples[] = 'Producto en stock con una medida concreta';
        $examples = array_values(array_unique(array_slice($examples, 0, 4)));

        return rest_ensure_response(array(
            'actions'    => $actions,
            'tools'      => $tools,
            'categories' => array_slice($facets['categories'], 0, $max_cards),
            'examples'   => $examples,
            'status'     => SEO_Dependiente_Index::status(),
        ));
    }

    public static function search(WP_REST_Request $request) {
        $ready = self::woocommerce_ready();
        if (is_wp_error($ready)) {
            return $ready;
        }
        self::ensure_initial_index();

        $params = self::request_params($request);
        $query = isset($params['q']) ? sanitize_text_field((string) $params['q']) : '';
        if (function_exists('mb_substr')) {
            $query = mb_substr($query, 0, 180, 'UTF-8');
        } else {
            $query = substr($query, 0, 180);
        }
        $mode = isset($params['mode']) ? sanitize_key((string) $params['mode']) : 'need';
        if (!in_array($mode, array('need', 'product', 'tool', 'compare'), true)) {
            $mode = 'need';
        }
        $page = max(1, absint(isset($params['page']) ? $params['page'] : 1));
        $per_page = min(48, max(6, absint(isset($params['per_page']) ? $params['per_page'] : SEO_Dependiente_Plugin::option('results_per_page', 18))));
        $filters = self::sanitize_filters(isset($params['filters']) && is_array($params['filters']) ? $params['filters'] : array());
        $orderby = isset($params['orderby']) ? sanitize_key((string) $params['orderby']) : 'relevance';
        if (!in_array($orderby, array('relevance', 'price_asc', 'price_desc', 'newest', 'title'), true)) {
            $orderby = 'relevance';
        }

        $candidate_rows = self::candidate_rows($query);
        $documents = array_map(array('SEO_Dependiente_Index', 'decode_row'), $candidate_rows);
        $facets = self::build_facets($documents);

        $matched = array();
        $tokens = self::query_token_groups($query);
        foreach ($documents as $document) {
            if (!self::matches_filters($document, $filters)) {
                continue;
            }
            $score = self::score_document($document, $query, $tokens, $filters, $mode);
            $document['_score'] = $score['score'];
            $document['_reasons'] = $score['reasons'];
            $matched[] = $document;
        }

        self::sort_documents($matched, $orderby);

        $total = count($matched);
        $pages = $total ? (int) ceil($total / $per_page) : 0;
        if ($pages && $page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $per_page;
        $page_documents = array_slice($matched, $offset, $per_page);
        $results = array();
        foreach ($page_documents as $document) {
            $serialized = self::serialize_result($document, $query, $filters);
            if ($serialized) {
                $results[] = $serialized;
            }
        }

        return rest_ensure_response(array(
            'query'           => $query,
            'mode'            => $mode,
            'page'            => $page,
            'per_page'        => $per_page,
            'pages'           => $pages,
            'total'           => $total,
            'candidate_total' => count($documents),
            'truncated'       => count($candidate_rows) >= self::CANDIDATE_LIMIT,
            'results'         => $results,
            'facets'          => $facets,
            'filters'         => $filters,
        ));
    }

    public static function compare(WP_REST_Request $request) {
        $ready = self::woocommerce_ready();
        if (is_wp_error($ready)) {
            return $ready;
        }
        $params = self::request_params($request);
        $ids = isset($params['ids']) ? array_values(array_unique(array_filter(array_map('absint', (array) $params['ids'])))) : array();
        $ids = array_slice($ids, 0, 4);
        $rows = SEO_Dependiente_Index::get_rows_by_ids($ids);
        $documents = array_map(array('SEO_Dependiente_Index', 'decode_row'), $rows);

        $products = array();
        foreach ($documents as $document) {
            $product = wc_get_product(absint($document['product_id']));
            if (!$product || !$product->is_visible()) {
                continue;
            }
            $products[] = array(
                'id'          => $product->get_id(),
                'title'       => $product->get_name(),
                'url'         => get_permalink($product->get_id()),
                'image'       => (string) $document['image_url'],
                'price'       => wp_strip_all_tags($product->get_price_html()),
                'brand'       => (string) $document['brand_name'],
                'sku'         => (string) $product->get_sku(),
                'stock'       => self::stock_label($product->get_stock_status()),
                'weight'      => self::number_with_unit($document['weight'], get_option('woocommerce_weight_unit', 'kg')),
                'dimensions'  => self::dimensions_text($document),
                'categories'  => self::join_labels($document['categories'], 'name'),
                'application' => self::vocabulary_labels($document, 'aplicacion'),
                'platform'    => self::vocabulary_labels($document, 'plataforma'),
                'subtype'     => self::vocabulary_labels($document, 'subtipo'),
                'attributes'  => self::attributes_map($document['attributes']),
            );
        }

        $criteria = self::comparison_criteria($documents);
        $comparison_rows = self::comparison_rows($products, $criteria);

        return rest_ensure_response(array(
            'products' => $products,
            'criteria' => $criteria,
            'rows'     => $comparison_rows,
        ));
    }

    private static function ensure_initial_index() {
        if (class_exists('WooCommerce') && 0 === SEO_Dependiente_Index::count_indexed()) {
            SEO_Dependiente_Index::index_batch(1, 60);
        }
    }

    private static function woocommerce_ready() {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_product')) {
            return new WP_Error(
                'seo_dependiente_woocommerce_required',
                'Dependiente necesita WooCommerce activo.',
                array('status' => 503)
            );
        }
        return true;
    }

    private static function request_params(WP_REST_Request $request) {
        $json = $request->get_json_params();
        return is_array($json) ? $json : $request->get_params();
    }

    private static function candidate_rows($query) {
        global $wpdb;

        if (!SEO_Dependiente_Index::table_exists()) {
            return array();
        }
        $groups = self::query_token_groups($query);
        if (!$groups) {
            return SEO_Dependiente_Index::get_rows(self::CANDIDATE_LIMIT);
        }

        $strict = self::query_index($groups, true, self::CANDIDATE_LIMIT);
        if (count($strict) >= 12 || count($groups) <= 1) {
            return $strict;
        }

        $broad = self::query_index($groups, false, self::CANDIDATE_LIMIT);
        $merged = array();
        foreach (array_merge($strict, $broad) as $row) {
            $merged[absint($row['product_id'])] = $row;
            if (count($merged) >= self::CANDIDATE_LIMIT) {
                break;
            }
        }
        unset($wpdb);
        return array_values($merged);
    }

    private static function query_index($groups, $require_all, $limit) {
        global $wpdb;

        $clauses = array();
        $params = array();
        foreach ((array) $groups as $variants) {
            $variant_clauses = array();
            foreach ((array) $variants as $variant) {
                if ('' === $variant) {
                    continue;
                }
                $variant_clauses[] = 'search_text LIKE %s';
                $params[] = '%' . $wpdb->esc_like($variant) . '%';
            }
            if ($variant_clauses) {
                $clauses[] = '(' . implode(' OR ', $variant_clauses) . ')';
            }
        }
        if (!$clauses) {
            return array();
        }

        $where = implode($require_all ? ' AND ' : ' OR ', $clauses);
        $params[] = absint($limit);
        $sql = $wpdb->prepare(
            'SELECT * FROM `' . esc_sql(SEO_Dependiente_Index::table()) . "` WHERE {$where} ORDER BY featured DESC, updated_at DESC LIMIT %d",
            $params
        );
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    private static function query_token_groups($query) {
        $normalized = SEO_Dependiente_Index::normalize($query);
        if (!$normalized) {
            return array();
        }

        $stopwords = array(
            'a','al','algo','con','cual','cuales','de','del','el','en','esta','este','estos','hacer','la','las','lo','los',
            'me','mi','mis','necesito','para','por','producto','productos','que','quiero','se','sirva','sirve','su','un','una',
            'unas','unos','usar','y','ya','buscar','busco','encontrar','tengo','tiene','tener','puedo','podria','como'
        );
        $words = array_values(array_filter(explode(' ', $normalized)));
        $groups = array();
        foreach ($words as $word) {
            if (in_array($word, $stopwords, true)) {
                continue;
            }
            if (strlen($word) < 2 && !ctype_digit($word) && !in_array($word, array('v', 'w', 'm'), true)) {
                continue;
            }
            $variants = self::token_variants($word);
            if ($variants) {
                $groups[] = $variants;
            }
            if (count($groups) >= 10) {
                break;
            }
        }
        if (!$groups && $normalized) {
            $groups[] = array($normalized);
        }
        return $groups;
    }

    private static function token_variants($token) {
        static $custom_synonyms = null;
        if (null === $custom_synonyms) {
            $custom_synonyms = array();
            if (function_exists('seo_search_parse_synonyms') && function_exists('seo_search_get_option')) {
                $custom_synonyms = (array) seo_search_parse_synonyms((string) seo_search_get_option('synonyms', ''));
            }
        }

        $defaults = array(
            'montar'      => array('montaje', 'instalar', 'instalacion'),
            'instalar'    => array('instalacion', 'montar', 'montaje'),
            'arreglar'    => array('reparar', 'reparacion', 'recambio', 'repuesto'),
            'reparar'     => array('reparacion', 'arreglar', 'repuesto'),
            'cortar'      => array('corte', 'cortador', 'cortadora'),
            'taladrar'    => array('taladro', 'perforar', 'perforacion'),
            'atornillar'  => array('atornillador', 'atornillado', 'tornillo'),
            'lijar'       => array('lijado', 'lijadora', 'lija'),
            'soldar'      => array('soldadura', 'soldador'),
            'medir'       => array('medicion', 'medida', 'metro'),
            'pintar'      => array('pintura', 'pintado'),
            'amoladora'   => array('radial'),
            'radial'      => array('amoladora'),
            'bateria'     => array('baterias', 'acumulador'),
            'corredera'   => array('correderas', 'corredero'),
            'puerta'      => array('puertas'),
            'rueda'       => array('ruedas', 'rodillo', 'rodillos'),
            'freno'       => array('frenos', 'cierre'),
        );

        $variants = array($token);
        if (isset($defaults[$token])) {
            $variants = array_merge($variants, $defaults[$token]);
        }
        if (isset($custom_synonyms[$token])) {
            $variants = array_merge($variants, (array) $custom_synonyms[$token]);
        }
        foreach ($custom_synonyms as $source => $alternatives) {
            if (in_array($token, (array) $alternatives, true)) {
                $variants[] = $source;
                $variants = array_merge($variants, (array) $alternatives);
            }
        }

        if (strlen($token) > 4) {
            if ('s' === substr($token, -1)) {
                $variants[] = substr($token, 0, -1);
            } else {
                $variants[] = $token . 's';
            }
        }

        return array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_Index', 'normalize'), $variants))));
    }

    private static function sanitize_filters($filters) {
        $clean = array(
            'categories' => self::sanitize_slug_list(isset($filters['categories']) ? $filters['categories'] : array()),
            'tags'       => self::sanitize_slug_list(isset($filters['tags']) ? $filters['tags'] : array()),
            'brands'     => self::sanitize_slug_list(isset($filters['brands']) ? $filters['brands'] : array()),
            'stock'      => self::sanitize_slug_list(isset($filters['stock']) ? $filters['stock'] : array()),
            'vocabulary' => array(),
            'attributes' => array(),
            'ranges'     => array(),
        );
        $clean['stock'] = array_values(array_intersect($clean['stock'], array('instock', 'outofstock', 'onbackorder')));

        $allowed_groups = array('rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo');
        $vocabulary = isset($filters['vocabulary']) && is_array($filters['vocabulary']) ? $filters['vocabulary'] : array();
        foreach ($allowed_groups as $group) {
            $values = self::sanitize_slug_list(isset($vocabulary[$group]) ? $vocabulary[$group] : array());
            if ($values) {
                $clean['vocabulary'][$group] = $values;
            }
        }

        $attributes = isset($filters['attributes']) && is_array($filters['attributes']) ? $filters['attributes'] : array();
        foreach ($attributes as $key => $values) {
            $key = sanitize_title((string) $key);
            $values = self::sanitize_slug_list($values);
            if ($key && $values) {
                $clean['attributes'][$key] = $values;
            }
        }

        $ranges = isset($filters['ranges']) && is_array($filters['ranges']) ? $filters['ranges'] : array();
        foreach (array('price', 'weight', 'length', 'width', 'height') as $field) {
            $range = isset($ranges[$field]) && is_array($ranges[$field]) ? $ranges[$field] : array();
            $min = self::sanitize_number(isset($range['min']) ? $range['min'] : null);
            $max = self::sanitize_number(isset($range['max']) ? $range['max'] : null);
            if (null !== $min || null !== $max) {
                $clean['ranges'][$field] = array('min' => $min, 'max' => $max);
            }
        }
        return $clean;
    }

    private static function sanitize_slug_list($values) {
        $values = is_array($values) ? $values : array($values);
        return array_values(array_unique(array_filter(array_map('sanitize_title', $values))));
    }

    private static function sanitize_number($value) {
        if (null === $value || '' === (string) $value) {
            return null;
        }
        $value = str_replace(',', '.', (string) $value);
        return is_numeric($value) ? (float) $value : null;
    }

    private static function matches_filters($document, $filters) {
        if (!self::document_has_term_slugs($document['categories'], $filters['categories'])) {
            return false;
        }
        if (!self::document_has_term_slugs($document['tags'], $filters['tags'])) {
            return false;
        }
        if ($filters['brands'] && !in_array(sanitize_title((string) $document['brand_slug']), $filters['brands'], true)) {
            return false;
        }
        if ($filters['stock'] && !in_array(sanitize_title((string) $document['stock_status']), $filters['stock'], true)) {
            return false;
        }

        foreach ($filters['vocabulary'] as $group => $selected) {
            $available = array();
            foreach ((array) ($document['vocabulary'][$group] ?? array()) as $item) {
                $available[] = sanitize_title((string) ($item['slug'] ?? ''));
            }
            if (!array_intersect($selected, $available)) {
                return false;
            }
        }

        $attribute_map = self::attributes_slug_map($document['attributes']);
        foreach ($filters['attributes'] as $key => $selected) {
            if (empty($attribute_map[$key]) || !array_intersect($selected, $attribute_map[$key])) {
                return false;
            }
        }

        foreach ($filters['ranges'] as $field => $range) {
            $value = isset($document[$field]) && '' !== (string) $document[$field] ? (float) $document[$field] : null;
            if (null === $value) {
                return false;
            }
            if (null !== $range['min'] && $value < $range['min']) {
                return false;
            }
            if (null !== $range['max'] && $value > $range['max']) {
                return false;
            }
        }
        return true;
    }

    private static function document_has_term_slugs($items, $selected) {
        if (!$selected) {
            return true;
        }
        $available = array();
        foreach ((array) $items as $item) {
            $available[] = sanitize_title((string) ($item['slug'] ?? ''));
        }
        return (bool) array_intersect($selected, $available);
    }

    private static function score_document($document, $query, $token_groups, $filters, $mode = 'need') {
        $score = !empty($document['featured']) ? 8.0 : 0.0;
        if ('instock' === $document['stock_status']) {
            $score += 5.0;
        }
        $reasons = array();

        $title = (string) $document['normalized_title'];
        $sku = SEO_Dependiente_Index::normalize((string) $document['sku']);
        $brand = SEO_Dependiente_Index::normalize((string) $document['brand_name']);
        $categories = SEO_Dependiente_Index::normalize(self::join_labels($document['categories'], 'name'));
        $tags = SEO_Dependiente_Index::normalize(self::join_labels($document['tags'], 'name'));
        $vocabulary = SEO_Dependiente_Index::normalize(self::all_vocabulary_text($document['vocabulary']));
        $applications = SEO_Dependiente_Index::normalize(self::vocabulary_labels($document, 'aplicacion'));
        $platforms = SEO_Dependiente_Index::normalize(self::vocabulary_labels($document, 'plataforma'));
        $attributes = SEO_Dependiente_Index::normalize(self::all_attributes_text($document['attributes']));
        $excerpt = SEO_Dependiente_Index::normalize((string) $document['excerpt']);
        $search_text = (string) $document['search_text'];

        $core_tokens = array();
        foreach ($token_groups as $variants) {
            if (!empty($variants[0])) {
                $core_tokens[] = $variants[0];
            }
        }
        $core_phrase = implode(' ', $core_tokens);
        $normalized_query = SEO_Dependiente_Index::normalize($query);

        if ($normalized_query && $sku && $normalized_query === $sku) {
            $score += 900;
            $reasons[] = 'Referencia exacta';
        }
        if ($core_phrase && $title === $core_phrase) {
            $score += 650;
            $reasons[] = 'Nombre exacto';
        } elseif ($core_phrase && false !== strpos($title, $core_phrase)) {
            $score += 340;
            $reasons[] = 'Coincide en el nombre';
        }

        $coverage = 0;
        $field_hits = array('title' => 0, 'application' => 0, 'platform' => 0, 'brand' => 0, 'category' => 0, 'tag' => 0, 'attribute' => 0, 'excerpt' => 0);
        foreach ($token_groups as $variants) {
            $token_found = false;
            foreach ($variants as $variant) {
                if (!$token_found && false !== strpos($search_text, $variant)) {
                    $token_found = true;
                    $coverage++;
                }
                if (false !== strpos($title, $variant)) {
                    $field_hits['title']++;
                }
                if (false !== strpos($applications, $variant)) {
                    $field_hits['application']++;
                }
                if (false !== strpos($platforms, $variant)) {
                    $field_hits['platform']++;
                }
                if (false !== strpos($brand, $variant)) {
                    $field_hits['brand']++;
                }
                if (false !== strpos($categories, $variant)) {
                    $field_hits['category']++;
                }
                if (false !== strpos($tags, $variant)) {
                    $field_hits['tag']++;
                }
                if (false !== strpos($attributes, $variant)) {
                    $field_hits['attribute']++;
                }
                if (false !== strpos($excerpt, $variant)) {
                    $field_hits['excerpt']++;
                }
                if (false !== strpos($vocabulary, $variant)) {
                    $score += 8;
                }
            }
        }

        $weights = array(
            'title'       => 54,
            'application' => 52,
            'platform'    => 44,
            'brand'       => 36,
            'category'    => 36,
            'tag'         => 30,
            'attribute'   => 30,
            'excerpt'     => 12,
        );
        if ('product' === $mode) {
            $weights = array_merge($weights, array('title' => 76, 'brand' => 54, 'application' => 30, 'platform' => 32, 'category' => 30));
        } elseif ('tool' === $mode) {
            $weights = array_merge($weights, array('title' => 42, 'application' => 36, 'platform' => 74, 'attribute' => 46, 'brand' => 32));
        } elseif ('compare' === $mode) {
            $weights = array_merge($weights, array('title' => 58, 'category' => 48, 'attribute' => 38, 'application' => 42));
        }
        foreach ($field_hits as $field => $hits) {
            $score += $hits * (isset($weights[$field]) ? $weights[$field] : 0);
        }
        if ($token_groups) {
            $score += 180 * ($coverage / max(1, count($token_groups)));
        }

        if ($field_hits['application']) {
            $reasons[] = 'Encaja con la acción';
        }
        if ($field_hits['platform']) {
            $reasons[] = 'Compatible con la plataforma';
        }
        if ($field_hits['attribute']) {
            $reasons[] = 'Coincide en características';
        }
        if ($field_hits['brand']) {
            $reasons[] = 'Coincide en la marca';
        }
        if ($field_hits['category'] || $field_hits['tag']) {
            $reasons[] = 'Familia de producto adecuada';
        }
        if (!$reasons && $field_hits['excerpt']) {
            $reasons[] = 'Coincide en la descripción';
        }

        foreach ($filters['vocabulary'] as $group => $selected) {
            if ($selected) {
                $score += 80;
                $reasons[] = self::vocabulary_group_reason($group);
            }
        }
        if ($filters['attributes']) {
            $score += 60 * count($filters['attributes']);
            $reasons[] = 'Cumple los atributos elegidos';
        }
        if ($filters['brands']) {
            $score += 50;
            $reasons[] = 'Marca seleccionada';
        }
        if ($filters['ranges']) {
            $score += 40;
            $reasons[] = 'Dentro del rango indicado';
        }
        if ($filters['stock']) {
            $score += 30;
            $reasons[] = 'Disponibilidad solicitada';
        }

        return array(
            'score'   => round($score, 4),
            'reasons' => array_slice(array_values(array_unique(array_filter($reasons))), 0, 4),
        );
    }

    private static function vocabulary_group_reason($group) {
        $map = array(
            'aplicacion' => 'Aplicación seleccionada',
            'plataforma' => 'Plataforma seleccionada',
            'subtipo'    => 'Subtipo seleccionado',
            'tipo'       => 'Tipo de producto seleccionado',
            'rol'        => 'Clase de producto seleccionada',
        );
        return isset($map[$group]) ? $map[$group] : 'Clasificación seleccionada';
    }

    private static function sort_documents(&$documents, $orderby) {
        usort($documents, static function ($a, $b) use ($orderby) {
            if ('price_asc' === $orderby || 'price_desc' === $orderby) {
                $a_missing = null === $a['price'] || '' === (string) $a['price'];
                $b_missing = null === $b['price'] || '' === (string) $b['price'];
                if ($a_missing !== $b_missing) {
                    return $a_missing ? 1 : -1;
                }
                $ap = (float) $a['price'];
                $bp = (float) $b['price'];
                if ($ap === $bp) {
                    return ((float) $b['_score']) <=> ((float) $a['_score']);
                }
                return 'price_asc' === $orderby ? ($ap <=> $bp) : ($bp <=> $ap);
            }
            if ('newest' === $orderby) {
                return strcmp((string) $b['post_modified_gmt'], (string) $a['post_modified_gmt']);
            }
            if ('title' === $orderby) {
                return strnatcasecmp((string) $a['title'], (string) $b['title']);
            }
            $score_compare = ((float) $b['_score']) <=> ((float) $a['_score']);
            if (0 !== $score_compare) {
                return $score_compare;
            }
            return absint($b['product_id']) <=> absint($a['product_id']);
        });
    }

    private static function serialize_result($document, $query, $filters) {
        $product = wc_get_product(absint($document['product_id']));
        if (!$product || !$product->is_visible()) {
            return null;
        }

        return array(
            'id'            => $product->get_id(),
            'title'         => $product->get_name(),
            'url'           => get_permalink($product->get_id()),
            'image'         => (string) $document['image_url'],
            'price_html'    => wp_kses_post($product->get_price_html()),
            'price'         => '' !== (string) $document['price'] ? (float) $document['price'] : null,
            'brand'         => (string) $document['brand_name'],
            'sku'           => (string) $product->get_sku(),
            'stock_status'  => (string) $document['stock_status'],
            'stock_label'   => self::stock_label($document['stock_status']),
            'excerpt'       => wp_trim_words(wp_strip_all_tags((string) $document['excerpt']), 25, '…'),
            'reasons'       => (array) $document['_reasons'],
            'score'         => (float) $document['_score'],
            'key_specs'     => self::key_specs($document, $query, $filters),
            'applications'  => self::vocabulary_item_labels($document, 'aplicacion'),
            'platforms'     => self::vocabulary_item_labels($document, 'plataforma'),
            'categories'    => array_slice(array_values(array_filter(wp_list_pluck($document['categories'], 'name'))), 0, 2),
            'compare_label' => 'Añadir a comparación',
        );
    }

    private static function key_specs($document, $query, $filters) {
        $query_normalized = SEO_Dependiente_Index::normalize($query);
        $selected_keys = array_keys($filters['attributes']);
        $ranked = array();
        foreach ((array) $document['attributes'] as $attribute) {
            $key = sanitize_title((string) ($attribute['key'] ?? ''));
            $label = (string) ($attribute['label'] ?? '');
            $values = array_values(array_filter((array) ($attribute['values'] ?? array())));
            if (!$label || !$values) {
                continue;
            }
            $weight = 0;
            if (in_array($key, $selected_keys, true)) {
                $weight += 100;
            }
            $haystack = SEO_Dependiente_Index::normalize($label . ' ' . implode(' ', $values));
            if ($query_normalized && self::text_has_any_token($haystack, self::query_token_groups($query))) {
                $weight += 60;
            }
            if (preg_match('/compat|sistema|modelo|medida|diametro|capacidad|carga|material|tension|potencia|contenido/i', $key)) {
                $weight += 20;
            }
            $ranked[] = array(
                'label'  => $label,
                'value'  => implode(', ', array_slice($values, 0, 3)),
                'weight' => $weight,
            );
        }
        usort($ranked, static function ($a, $b) {
            return $b['weight'] <=> $a['weight'];
        });
        $ranked = array_slice($ranked, 0, 4);
        foreach ($ranked as &$item) {
            unset($item['weight']);
        }
        return $ranked;
    }

    private static function text_has_any_token($text, $groups) {
        foreach ($groups as $variants) {
            foreach ($variants as $variant) {
                if (false !== strpos($text, $variant)) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function build_facets($documents) {
        $categories = array();
        $tags = array();
        $brands = array();
        $vocabulary = array('rol' => array(), 'tipo' => array(), 'aplicacion' => array(), 'plataforma' => array(), 'subtipo' => array());
        $attributes = array();
        $ranges = array(
            'price'  => array('min' => null, 'max' => null),
            'weight' => array('min' => null, 'max' => null),
            'length' => array('min' => null, 'max' => null),
            'width'  => array('min' => null, 'max' => null),
            'height' => array('min' => null, 'max' => null),
        );
        $stock = array();

        foreach ((array) $documents as $document) {
            foreach ((array) $document['categories'] as $term) {
                self::facet_increment($categories, $term['slug'] ?? '', $term['name'] ?? '', $term['image'] ?? '');
            }
            foreach ((array) $document['tags'] as $term) {
                self::facet_increment($tags, $term['slug'] ?? '', $term['name'] ?? '', '');
            }
            if (!empty($document['brand_slug'])) {
                self::facet_increment($brands, $document['brand_slug'], $document['brand_name'], '');
            }
            foreach ($vocabulary as $group => $unused) {
                unset($unused);
                foreach ((array) ($document['vocabulary'][$group] ?? array()) as $item) {
                    self::facet_increment($vocabulary[$group], $item['slug'] ?? '', $item['label'] ?? '', '');
                }
            }
            foreach ((array) $document['attributes'] as $attribute) {
                $key = sanitize_title((string) ($attribute['key'] ?? ''));
                $label = trim((string) ($attribute['label'] ?? ''));
                if (!$key || !$label) {
                    continue;
                }
                if (!isset($attributes[$key])) {
                    $attributes[$key] = array('key' => $key, 'label' => $label, 'count' => 0, 'values_map' => array());
                }
                $attributes[$key]['count']++;
                foreach (array_unique((array) ($attribute['values'] ?? array())) as $value) {
                    self::facet_increment($attributes[$key]['values_map'], sanitize_title((string) $value), (string) $value, '');
                }
            }

            foreach ($ranges as $field => $unused) {
                unset($unused);
                if (isset($document[$field]) && '' !== (string) $document[$field] && null !== $document[$field]) {
                    $value = (float) $document[$field];
                    $ranges[$field]['min'] = null === $ranges[$field]['min'] ? $value : min($ranges[$field]['min'], $value);
                    $ranges[$field]['max'] = null === $ranges[$field]['max'] ? $value : max($ranges[$field]['max'], $value);
                }
            }
            self::facet_increment($stock, (string) $document['stock_status'], self::stock_label($document['stock_status']), '');
        }

        foreach (array('categories', 'tags', 'brands') as $name) {
            ${$name} = self::sort_facet_map(${$name}, 80);
        }
        foreach ($vocabulary as $group => $items) {
            $vocabulary[$group] = self::sort_facet_map($items, 80);
        }

        $attribute_list = array();
        foreach ($attributes as $attribute) {
            $attribute['values'] = self::sort_facet_map($attribute['values_map'], 60);
            unset($attribute['values_map']);
            if ($attribute['values']) {
                $attribute_list[] = $attribute;
            }
        }
        usort($attribute_list, static function ($a, $b) {
            if ($a['count'] === $b['count']) {
                return strnatcasecmp($a['label'], $b['label']);
            }
            return $b['count'] <=> $a['count'];
        });
        $attribute_list = array_slice($attribute_list, 0, 40);

        return array(
            'categories' => $categories,
            'tags'       => $tags,
            'brands'     => $brands,
            'vocabulary' => $vocabulary,
            'attributes' => $attribute_list,
            'ranges'     => $ranges,
            'stock'      => self::sort_facet_map($stock, 10),
        );
    }

    private static function facet_increment(&$map, $slug, $label, $image) {
        $slug = sanitize_title((string) $slug);
        $label = trim(wp_strip_all_tags((string) $label));
        if (!$slug || !$label) {
            return;
        }
        if (!isset($map[$slug])) {
            $map[$slug] = array('slug' => $slug, 'label' => $label, 'count' => 0, 'image' => esc_url_raw((string) $image));
        }
        $map[$slug]['count']++;
        if (!$map[$slug]['image'] && $image) {
            $map[$slug]['image'] = esc_url_raw((string) $image);
        }
    }

    private static function sort_facet_map($map, $limit) {
        $items = array_values((array) $map);
        usort($items, static function ($a, $b) {
            if ($a['count'] === $b['count']) {
                return strnatcasecmp($a['label'], $b['label']);
            }
            return $b['count'] <=> $a['count'];
        });
        return array_slice($items, 0, $limit);
    }

    private static function make_cards($items, $type, $group, $documents) {
        $cards = array();
        foreach ((array) $items as $item) {
            $image = !empty($item['image']) ? $item['image'] : self::representative_image($documents, $type, $group, $item['slug']);
            $cards[] = array(
                'label' => (string) $item['label'],
                'slug'  => (string) $item['slug'],
                'count' => absint($item['count']),
                'image' => (string) $image,
                'filter'=> array('type' => $type, 'group' => $group, 'slug' => (string) $item['slug']),
            );
        }
        return $cards;
    }

    private static function representative_image($documents, $type, $group, $slug) {
        foreach ((array) $documents as $document) {
            $match = false;
            if ('categories' === $type) {
                $match = self::document_has_term_slugs($document['categories'], array($slug));
            } elseif ('tags' === $type) {
                $match = self::document_has_term_slugs($document['tags'], array($slug));
            } elseif ('vocabulary' === $type) {
                $slugs = array_map('sanitize_title', wp_list_pluck((array) ($document['vocabulary'][$group] ?? array()), 'slug'));
                $match = in_array($slug, $slugs, true);
            } elseif ('attributes' === $type) {
                $map = self::attributes_slug_map($document['attributes']);
                $match = !empty($map[$group]) && in_array($slug, $map[$group], true);
            }
            if ($match && !empty($document['image_url'])) {
                return (string) $document['image_url'];
            }
        }
        return function_exists('wc_placeholder_img_src') ? (string) wc_placeholder_img_src('woocommerce_thumbnail') : '';
    }

    private static function tool_attribute_items($attributes) {
        $keywords = array('plataforma', 'sistema', 'modelo', 'herramienta', 'maquina', 'compatibilidad', 'serie', 'familia');
        foreach ((array) $attributes as $attribute) {
            $haystack = SEO_Dependiente_Index::normalize(($attribute['key'] ?? '') . ' ' . ($attribute['label'] ?? ''));
            foreach ($keywords as $keyword) {
                if (false !== strpos($haystack, $keyword) && !empty($attribute['values'])) {
                    return array('key' => (string) $attribute['key'], 'items' => (array) $attribute['values']);
                }
            }
        }
        return array('key' => '', 'items' => array());
    }

    private static function attributes_slug_map($attributes) {
        $map = array();
        foreach ((array) $attributes as $attribute) {
            $key = sanitize_title((string) ($attribute['key'] ?? ''));
            if (!$key) {
                continue;
            }
            $map[$key] = array_values(array_unique(array_filter(array_map('sanitize_title', (array) ($attribute['values'] ?? array())))));
        }
        return $map;
    }

    private static function all_vocabulary_text($vocabulary) {
        $chunks = array();
        foreach ((array) $vocabulary as $items) {
            foreach ((array) $items as $item) {
                $chunks[] = (string) ($item['label'] ?? '');
            }
        }
        return implode(' ', $chunks);
    }

    private static function all_attributes_text($attributes) {
        $chunks = array();
        foreach ((array) $attributes as $attribute) {
            $chunks[] = (string) ($attribute['label'] ?? '') . ' ' . implode(' ', (array) ($attribute['values'] ?? array()));
        }
        return implode(' ', $chunks);
    }

    private static function vocabulary_labels($document, $group) {
        return implode(', ', self::vocabulary_item_labels($document, $group));
    }

    private static function vocabulary_item_labels($document, $group) {
        return array_values(array_filter(wp_list_pluck((array) ($document['vocabulary'][$group] ?? array()), 'label')));
    }

    private static function join_labels($items, $field) {
        return implode(', ', array_values(array_filter(wp_list_pluck((array) $items, $field))));
    }

    private static function stock_label($stock_status) {
        $labels = array(
            'instock'     => 'En stock',
            'onbackorder' => 'Disponible bajo pedido',
            'outofstock'  => 'Agotado',
        );
        return isset($labels[$stock_status]) ? $labels[$stock_status] : ucfirst((string) $stock_status);
    }

    private static function number_with_unit($value, $unit) {
        if (null === $value || '' === (string) $value) {
            return '';
        }
        return wc_format_localized_decimal((float) $value) . ' ' . $unit;
    }

    private static function dimensions_text($document) {
        $parts = array();
        foreach (array('length' => 'L', 'width' => 'A', 'height' => 'H') as $key => $label) {
            if (isset($document[$key]) && '' !== (string) $document[$key]) {
                $parts[] = $label . ' ' . wc_format_localized_decimal((float) $document[$key]);
            }
        }
        return $parts ? implode(' × ', $parts) . ' ' . get_option('woocommerce_dimension_unit', 'cm') : '';
    }

    private static function attributes_map($attributes) {
        $map = array();
        foreach ((array) $attributes as $attribute) {
            $label = trim((string) ($attribute['label'] ?? ''));
            $values = array_values(array_filter((array) ($attribute['values'] ?? array())));
            if ($label && $values) {
                $map[$label] = implode(', ', $values);
            }
        }
        return $map;
    }

    private static function comparison_criteria($documents) {
        global $wpdb;

        $table = $wpdb->prefix . 'seo_category_comparisons';
        if (!SEO_Dependiente_Index::table_exists($table) || !$documents) {
            return array('title' => '', 'labels' => array());
        }

        $sets = array();
        foreach ($documents as $document) {
            $sets[] = array_values(array_filter(array_map('absint', wp_list_pluck((array) $document['categories'], 'id'))));
        }
        $category_ids = $sets ? array_shift($sets) : array();
        foreach ($sets as $set) {
            $category_ids = array_values(array_intersect($category_ids, $set));
        }
        if (!$category_ids) {
            foreach ($documents as $document) {
                $category_ids = array_merge($category_ids, array_map('absint', wp_list_pluck((array) $document['categories'], 'id')));
            }
            $category_ids = array_values(array_unique(array_filter($category_ids)));
        }
        if (!$category_ids) {
            return array('title' => '', 'labels' => array());
        }

        $category_ids = array_slice($category_ids, 0, 30);
        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT title, analysis_snapshot
                 FROM {$table}
                 WHERE category_id IN ({$placeholders})
                   AND status = 'published'
                 ORDER BY updated_at DESC
                 LIMIT 1",
                $category_ids
            ),
            ARRAY_A
        );
        if (!$row) {
            return array('title' => '', 'labels' => array());
        }

        $snapshot = json_decode((string) $row['analysis_snapshot'], true);
        $labels = array();
        if (is_array($snapshot) && !empty($snapshot['criteria'])) {
            foreach ((array) $snapshot['criteria'] as $criterion) {
                if (!empty($criterion['label'])) {
                    $labels[] = (string) $criterion['label'];
                }
            }
        }
        return array('title' => (string) $row['title'], 'labels' => array_slice(array_values(array_unique($labels)), 0, 8));
    }

    private static function comparison_rows($products, $criteria) {
        if (!$products) {
            return array();
        }
        $rows = array();
        $fixed = array(
            'Precio'         => 'price',
            'Marca'          => 'brand',
            'Referencia'     => 'sku',
            'Disponibilidad' => 'stock',
            'Peso'           => 'weight',
            'Dimensiones'    => 'dimensions',
            'Categorías'     => 'categories',
            'Aplicación'     => 'application',
            'Plataforma'     => 'platform',
            'Subtipo'        => 'subtype',
        );
        foreach ($fixed as $label => $field) {
            $values = array();
            foreach ($products as $product) {
                $values[(string) $product['id']] = (string) ($product[$field] ?? '');
            }
            if (array_filter($values, 'strlen')) {
                $rows[] = self::comparison_row($label, $values, self::criterion_matches_label((array) ($criteria['labels'] ?? array()), $label));
            }
        }

        $attribute_labels = array();
        foreach ($products as $product) {
            $attribute_labels = array_merge($attribute_labels, array_keys((array) $product['attributes']));
        }
        $attribute_labels = array_values(array_unique($attribute_labels));
        usort($attribute_labels, static function ($a, $b) use ($criteria) {
            $criteria_text = SEO_Dependiente_Index::normalize(implode(' ', (array) ($criteria['labels'] ?? array())));
            $a_priority = false !== strpos($criteria_text, SEO_Dependiente_Index::normalize($a)) ? 1 : 0;
            $b_priority = false !== strpos($criteria_text, SEO_Dependiente_Index::normalize($b)) ? 1 : 0;
            if ($a_priority !== $b_priority) {
                return $b_priority <=> $a_priority;
            }
            return strnatcasecmp($a, $b);
        });

        foreach (array_slice($attribute_labels, 0, 30) as $label) {
            $values = array();
            foreach ($products as $product) {
                $values[(string) $product['id']] = (string) ($product['attributes'][$label] ?? '');
            }
            $priority = self::criterion_matches_label((array) ($criteria['labels'] ?? array()), $label);
            $rows[] = self::comparison_row($label, $values, $priority);
        }

        usort($rows, static function ($a, $b) {
            if ($a['priority'] !== $b['priority']) {
                return $b['priority'] <=> $a['priority'];
            }
            if ($a['different'] !== $b['different']) {
                return $b['different'] <=> $a['different'];
            }
            return 0;
        });
        return $rows;
    }

    private static function comparison_row($label, $values, $priority) {
        $normalized = array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_Index', 'normalize'), array_values($values)))));
        return array(
            'label'     => $label,
            'values'    => $values,
            'different' => count($normalized) > 1,
            'priority'  => (bool) $priority,
        );
    }

    private static function criterion_matches_label($criteria, $label) {
        $label_normalized = SEO_Dependiente_Index::normalize($label);
        foreach ($criteria as $criterion) {
            $criterion_normalized = SEO_Dependiente_Index::normalize($criterion);
            if (false !== strpos($criterion_normalized, $label_normalized) || false !== strpos($label_normalized, $criterion_normalized)) {
                return true;
            }
        }
        return false;
    }

    private static function lower_first($text) {
        if (function_exists('mb_strtolower') && function_exists('mb_substr')) {
            return mb_strtolower(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
        }
        return strtolower(substr($text, 0, 1)) . substr($text, 1);
    }
}
