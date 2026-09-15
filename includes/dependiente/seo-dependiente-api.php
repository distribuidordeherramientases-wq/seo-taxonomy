<?php

defined('ABSPATH') || exit;

// Carga autosuficiente de la capa semantica.
$seo_dependiente_semantics_file = __DIR__ . '/seo-dependiente-semantics.php';
if (!class_exists('SEO_Dependiente_Semantics') && is_readable($seo_dependiente_semantics_file)) {
    require_once $seo_dependiente_semantics_file;
}

// Carga autosuficiente del registro de busquedas.
// Permite instalar solo este API + seo-dependiente-search-log.php.
$seo_dependiente_search_log_file = __DIR__ . '/seo-dependiente-search-log.php';
if (!class_exists('SEO_Dependiente_Search_Log') && is_readable($seo_dependiente_search_log_file)) {
    require_once $seo_dependiente_search_log_file;
}

// Motor de aprendizaje supervisado: convierte reformulaciones y clics en
// candidatos inactivos dentro de wp_seo_dependiente_semantics.
$seo_dependiente_learning_file = __DIR__ . '/seo-dependiente-learning.php';
if (!class_exists('SEO_Dependiente_Learning') && is_readable($seo_dependiente_learning_file)) {
    require_once $seo_dependiente_learning_file;
}

// Amazon del Dependiente. Se ejecuta como fuente 1C complementaria en toda
// búsqueda de primera página; Creators API es opcional y el modo Afiliados
// funciona únicamente con Partner Tag.
$seo_dependiente_amazon_file = __DIR__ . '/seo-dependiente-amazon.php';
if (!class_exists('SEO_Dependiente_Amazon') && is_readable($seo_dependiente_amazon_file)) {
    require_once $seo_dependiente_amazon_file;
}

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

        register_rest_route('seo-taxonomy/v1', '/search-feedback', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'search_feedback'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('seo-taxonomy/v1', '/help-request', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array(__CLASS__, 'help_request'),
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
        $started_at = microtime(true);
        $ready = self::woocommerce_ready();
        if (is_wp_error($ready)) {
            return $ready;
        }

        $params = self::request_params($request);
        // Keep a wider copy only to resolve an explicitly mentioned canonical owner.
        // The ordinary catalog search remains capped to the historical 180 chars.
        $raw_query = isset($params['q']) ? sanitize_text_field((string) $params['q']) : '';
        if (function_exists('mb_substr')) {
            $raw_query = mb_substr($raw_query, 0, 1000, 'UTF-8');
        } else {
            $raw_query = substr($raw_query, 0, 1000);
        }
        $explicit_faq_owner = self::resolve_explicit_faq_owner($raw_query, $params);

        // Intérprete actúa como puente lingüístico, no como buscador. Limpia la
        // frase y añade señales canónicas aprendidas por Lingüista. Dependiente
        // sigue siendo el único que consulta catálogo, calcula facetas y decide
        // qué productos mostrar.
        $semantic_hints = array();
        $semantic_hint = array();
        $interpreter = array();
        $interpreter_debug = array();
        $raw_search_query = $raw_query;
        $query_for_dependiente = $raw_query;

        $interpreter_enabled = defined('SEO_DEPENDIENTE_INTERPRETER_ASSIST')
            && SEO_DEPENDIENTE_INTERPRETER_ASSIST
            && class_exists('SEO_Dependiente_Interprete')
            && '' !== trim($raw_query);
        if ($interpreter_enabled) {
            $interpreter = SEO_Dependiente_Interprete::interpret($raw_query);
            if (is_array($interpreter)) {
                $candidate = SEO_Dependiente_Interprete::refine_search_query($interpreter);
                if ('' !== trim((string) $candidate)) {
                    $query_for_dependiente = (string) $candidate;
                }
                $language = isset($interpreter['language']) && is_array($interpreter['language']) ? $interpreter['language'] : array();
                $structured = isset($interpreter['structured']) && is_array($interpreter['structured']) ? $interpreter['structured'] : array();
                $interpreter_debug = array(
                    'enabled'          => true,
                    'mode'             => 'assist',
                    'version'          => sanitize_text_field((string) ($interpreter['version'] ?? '')),
                    'raw_query'        => sanitize_text_field((string) $raw_query),
                    'normalized'       => sanitize_text_field((string) ($interpreter['normalized'] ?? '')),
                    'filtered_query'   => sanitize_text_field((string) ($interpreter['search_query'] ?? '')),
                    'assist_terms'     => array_values(array_slice(array_map('sanitize_text_field', (array) ($interpreter['assist_terms'] ?? array())), 0, 12)),
                    'structured'       => $structured,
                    'removed_tokens'   => array_values(array_slice(array_map('sanitize_text_field', (array) ($language['removed_tokens'] ?? array())), 0, 30)),
                    'transformations'  => array_values(array_slice(array_map('sanitize_text_field', (array) ($interpreter['transformations'] ?? array())), 0, 30)),
                    'confidence'       => (float) ($interpreter['confidence'] ?? 0),
                    'affects_search'   => true,
                );
            }
        }

        if (function_exists('mb_substr')) {
            $query = mb_substr($query_for_dependiente, 0, 240, 'UTF-8');
            $raw_search_query = mb_substr($raw_search_query, 0, 240, 'UTF-8');
        } else {
            $query = substr($query_for_dependiente, 0, 240);
            $raw_search_query = substr($raw_search_query, 0, 240);
        }
        if ($interpreter_debug) {
            $interpreter_debug['dependiente_query'] = sanitize_text_field((string) $query);
        }
        // Perfil estructurado del Intérprete. Dependiente no acepta una bolsa plana
        // de palabras como si todas significaran lo mismo: distingue conceptos de
        // identidad (p.ej. taladro), vocabulario de catálogo (TIPO/ROL/etc.),
        // acciones y contexto. Esto evita que "pared" o "madera" desplacen a
        // una señal comercial mucho más fuerte como "taladro".
        $assist_profile = self::interpreter_assist_profile($interpreter);
        $mode = isset($params['mode']) ? sanitize_key((string) $params['mode']) : 'need';
        if (!in_array($mode, array('need', 'product', 'tool', 'compare'), true)) {
            $mode = 'need';
        }
        $page = max(1, absint(isset($params['page']) ? $params['page'] : 1));
        $per_page = min(48, max(6, absint(isset($params['per_page']) ? $params['per_page'] : SEO_Dependiente_Plugin::option('results_per_page', 18))));
        $solution_role = self::sanitize_solution_role($params['solution_role'] ?? '');
        $request_filters = self::sanitize_filters(isset($params['filters']) && is_array($params['filters']) ? $params['filters'] : array());
        $filters = $request_filters;
        if ($solution_role) {
            // El selector principal es una decisión explícita del cliente. Se
            // aplica como filtro fuerte del vocabulario ROL, separado de los
            // filtros secundarios para que la primera petición siga siendo una
            // búsqueda y pueda lanzar preguntas de aclaración si hacen falta.
            $filters['vocabulary']['rol'] = array($solution_role);
        }
        $orderby = isset($params['orderby']) ? sanitize_key((string) $params['orderby']) : 'relevance';
        if (!in_array($orderby, array('relevance', 'price_asc', 'price_desc', 'newest', 'title'), true)) {
            $orderby = 'relevance';
        }
        $session_id = isset($params['session_id']) ? sanitize_text_field((string) $params['session_id']) : '';

        // Si el frontend no envia session_id, intentamos mantener una sesion anonima
        // para detectar reformulaciones consecutivas sin guardar IP ni datos personales.
        if ('' === $session_id) {
            $cookie_name = 'seo_dependiente_sid';
            if (!empty($_COOKIE[$cookie_name])) {
                $session_id = sanitize_text_field(wp_unslash((string) $_COOKIE[$cookie_name]));
            } elseif (function_exists('WC') && WC() && isset(WC()->session) && WC()->session) {
                $wc_customer_id = (string) WC()->session->get_customer_id();
                if ('' !== $wc_customer_id) {
                    $session_id = 'wc:' . $wc_customer_id;
                }
            }

            if ('' === $session_id) {
                $session_id = wp_generate_uuid4();
                if (!headers_sent()) {
                    $secure = is_ssl();
                    $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
                    setcookie($cookie_name, $session_id, time() + DAY_IN_SECONDS, $path, '', $secure, true);
                    $_COOKIE[$cookie_name] = $session_id;
                }
            }
        }

        if (function_exists('mb_substr')) {
            $session_id = mb_substr($session_id, 0, 100, 'UTF-8');
        } else {
            $session_id = substr($session_id, 0, 100);
        }
        $request_kind = $page > 1
            ? 'paginate'
            : (($semantic_hints || self::has_active_filters($request_filters) || 'relevance' !== $orderby) ? 'refine' : 'search');

        $semantic_rules_active = 0;
        if (class_exists('SEO_Dependiente_Semantics')) {
            if (method_exists('SEO_Dependiente_Semantics', 'ensure_ready')) {
                $semantic_rules_active = SEO_Dependiente_Semantics::ensure_ready();
            }
            $analysis_query = $query;
            $semantic = SEO_Dependiente_Semantics::analyze($analysis_query);
        } else {
            $semantic = array();
        }
        // 1A. Productos directos: primera pasada solo sobre campos de identidad y
        // clasificación del producto. No abre todavía descripciones largas ni rutas
        // semánticas extensivas.
        $tokens = !empty($semantic['groups']) && class_exists('SEO_Dependiente_Semantics')
            ? SEO_Dependiente_Semantics::group_variants($semantic)
            : self::query_token_groups($query);

        // Diagnóstico visible en staging: muestra qué conceptos está usando
        // realmente Dependiente para recuperar productos. No altera la búsqueda.
        $dependiente_debug_groups = self::debug_search_groups($semantic, $tokens);
        $dependiente_primary_groups = self::primary_search_groups($query, $semantic, $assist_profile);

        $primary_diagnostic = array();
        $primary_rows = self::primary_candidate_rows($query, $semantic, $assist_profile, $primary_diagnostic);
        $primary_documents = array();
        $primary_matched = self::score_candidate_rows(
            $primary_rows,
            $query,
            $tokens,
            $filters,
            $mode,
            $semantic,
            $assist_profile,
            $primary_documents
        );
        self::sort_documents($primary_matched, $orderby);
        // El índice puede conservar temporalmente filas que WooCommerce ya no
        // considera publicables. No dejamos que esas filas decidan si la primera
        // pasada es suficiente ni que inflen facetas, totales o paginación.
        $primary_matched = self::filter_searchable_documents($primary_matched);
        $primary_matched = self::identity_coherent_documents($primary_matched, $assist_profile);

        // 1B. Conocimiento editorial directo. Las FAQs ya no hacen una
        // búsqueda textual global en esta fase: se resolverán después, cuando
        // conozcamos los productos/categorías candidatos y podamos seguir su owner.
        $direct_related = self::direct_content_search($query, 18, true, $semantic);

        // 2. La búsqueda extensiva se abre cuando la señal local de PRODUCTOS
        // es insuficiente. El contenido editorial puede complementar una respuesta,
        // pero nunca sustituir la recuperación del catálogo cuando no hay productos.
        $primary_product_count = count($primary_matched);
        $local_sufficient = self::local_search_sufficient($primary_matched, $direct_related);
        $has_catalog_semantic_route = self::has_catalog_semantic_route($semantic);
        $run_extended = !$local_sufficient;
        $extended_reasons = array();
        if (!$local_sufficient) {
            $extended_reasons[] = 'local_product_signal_insufficient';
        }
        if (0 === $primary_product_count) {
            $run_extended = true;
            $extended_reasons[] = 'no_primary_products';
        }
        // Una ruta semántica TIPO/ROL/etc. es una señal explícita de catálogo.
        // Si la primera pasada todavía no ofrece una respuesta amplia, abrimos la
        // capa semántica aunque existan landings, posts o FAQs relacionados.
        if ($has_catalog_semantic_route && $primary_product_count < 6) {
            $run_extended = true;
            $extended_reasons[] = 'catalog_semantic_route';
        }
        // Cuando el cliente ha elegido un ROL concreto, no dejamos que varias
        // guías editoriales oculten la segunda pasada del catálogo si todavía
        // hay pocos productos de ese rol. Es especialmente importante en frases
        // de problema como "se me ha roto un grifo" + Herramienta.
        if ($solution_role && $primary_product_count < 3) {
            $run_extended = true;
            $extended_reasons[] = 'solution_role';
        }
        $search_diagnostic = $primary_diagnostic;
        $search_diagnostic['solution_role'] = $solution_role;
        $search_diagnostic['primary_product_count'] = $primary_product_count;
        $search_diagnostic['direct_knowledge_count'] = count($direct_related);
        $search_diagnostic['extended_search'] = $run_extended ? 'executed' : 'skipped';
        $search_diagnostic['extended_reasons'] = array_values(array_unique($extended_reasons));
        $search_diagnostic['semantic_catalog_route'] = $has_catalog_semantic_route ? 1 : 0;
        $search_diagnostic['semantic_rules_active'] = (int) $semantic_rules_active;
        $search_diagnostic['interpreter_assist_mode'] = $interpreter_debug ? 'linguistic_bridge' : 'disabled';
        $search_diagnostic['raw_query'] = sanitize_text_field((string) $raw_search_query);
        $search_diagnostic['interpreted_query'] = sanitize_text_field((string) $query);
        if ($interpreter) {
            $search_diagnostic['interpreter_version'] = sanitize_text_field((string) ($interpreter['version'] ?? ''));
            $search_diagnostic['interpreter_changed'] = !empty($interpreter['changed']) ? 1 : 0;
            $search_diagnostic['interpreter_rule'] = sanitize_key((string) ($interpreter['rule'] ?? ''));
            $search_diagnostic['interpreter_lesson'] = sanitize_key((string) ($interpreter['lesson_key'] ?? ''));
            $search_diagnostic['interpreter_query'] = sanitize_text_field((string) ($interpreter['search_query'] ?? ''));
            $search_diagnostic['interpreter_confidence'] = (float) ($interpreter['confidence'] ?? 0);
            $search_diagnostic['interpreter_transformations'] = array_values(array_slice(array_map('sanitize_text_field', (array) ($interpreter['transformations'] ?? array())), 0, 20));
        }
        if ($semantic_hints) {
            $search_diagnostic['semantic_hint'] = $semantic_hint;
            $search_diagnostic['semantic_hints'] = $semantic_hints;
            $search_diagnostic['clarification_answers'] = count($semantic_hints);
        }

        if ($run_extended) {
            $extended_diagnostic = array();
            $extended_rows = self::candidate_rows($query, $semantic, $extended_diagnostic);
            $candidate_rows = self::merge_candidate_rows($primary_rows, $extended_rows);
            $search_diagnostic = array_merge($search_diagnostic, $extended_diagnostic);
            $search_diagnostic['primary_product_count'] = count($primary_matched);
            $search_diagnostic['direct_knowledge_count'] = count($direct_related);
            $search_diagnostic['extended_search'] = 'executed';
            $search_diagnostic['primary_strategy'] = (string) ($primary_diagnostic['strategy'] ?? 'primary_direct');
        } else {
            $candidate_rows = $primary_rows;
            $search_diagnostic['strategy'] = (string) ($primary_diagnostic['strategy'] ?? 'primary_direct');
        }

        $documents = array();
        $matched = self::score_candidate_rows(
            $candidate_rows,
            $query,
            $tokens,
            $filters,
            $mode,
            $semantic,
            $assist_profile,
            $documents
        );
        $primary_id_map = array();
        foreach ($primary_matched as $primary_document) {
            $primary_id_map[absint($primary_document['product_id'] ?? 0)] = true;
        }
        foreach ($matched as &$matched_document) {
            $matched_document['_search_tier'] = isset($primary_id_map[absint($matched_document['product_id'] ?? 0)])
                ? 'direct'
                : 'extended';
        }
        unset($matched_document);
        self::sort_documents($matched, $orderby);
        // Solo los productos realmente publicables pueden participar desde aquí.
        // Esto mantiene sincronizados tarjetas, facetas, total y paginación.
        $matched = self::filter_searchable_documents($matched);
        $matched = self::identity_coherent_documents($matched, $assist_profile);
        self::sort_documents($matched, $orderby);
        // Los filtros visibles deben describir exactamente los productos que siguen
        // vivos tras ranking/filtros, no todo el conjunto candidato recuperado.
        $facets = self::build_facets($matched);

        if ($interpreter_debug) {
            $top_debug_matches = array();
            foreach (array_slice($matched, 0, 5) as $debug_document) {
                $debug_product = wc_get_product(absint($debug_document['product_id'] ?? 0));
                $debug_title = $debug_product instanceof WC_Product
                    ? $debug_product->get_name()
                    : (string) ($debug_document['normalized_title'] ?? '');
                $top_debug_matches[] = array(
                    'id'       => absint($debug_document['product_id'] ?? 0),
                    'title'    => sanitize_text_field((string) $debug_title),
                    'score'    => (float) ($debug_document['_score'] ?? 0),
                    'coverage' => absint($debug_document['_coverage'] ?? 0),
                    'identity_hits' => absint($debug_document['_assist_identity_hits'] ?? 0),
                    'identity_sources' => array_values(array_slice(array_map('sanitize_text_field', (array) ($debug_document['_assist_identity_sources'] ?? array())), 0, 6)),
                    'vocabulary_hits' => absint($debug_document['_assist_vocabulary_hits'] ?? 0),
                    'action_hits' => absint($debug_document['_assist_action_hits'] ?? 0),
                    'reasons'  => array_values(array_slice(array_map('sanitize_text_field', (array) ($debug_document['_reasons'] ?? array())), 0, 4)),
                    'tier'     => sanitize_key((string) ($debug_document['_search_tier'] ?? '')),
                );
            }

            $interpreter_debug['dependiente'] = array(
                'query'             => sanitize_text_field((string) $query),
                'groups'            => $dependiente_debug_groups,
                'primary_groups'    => self::debug_plain_groups($dependiente_primary_groups),
                'catalog_fields'    => array('título', 'categorías', 'etiquetas', 'Vocabulary TIPO/ROL/APLICACIÓN cuando hace falta'),
                'strategy'          => sanitize_key((string) ($search_diagnostic['strategy'] ?? 'primary_direct')),
                'primary_strategy'  => sanitize_key((string) ($search_diagnostic['primary_strategy'] ?? ($primary_diagnostic['strategy'] ?? 'primary_direct'))),
                'primary_rows'      => absint($search_diagnostic['primary_rows'] ?? ($primary_diagnostic['primary_rows'] ?? 0)),
                'candidate_rows'    => count($candidate_rows),
                'matched_rows'      => count($matched),
                'extended_search'   => sanitize_key((string) ($search_diagnostic['extended_search'] ?? 'skipped')),
                'semantic_routes'   => self::debug_semantic_routes($semantic),
                'assist_profile'    => $assist_profile,
                'top_matches'       => $top_debug_matches,
            );
        }

        // Las preguntas se calculan con el conjunto que realmente sigue vivo.
        // Si la búsqueda queda a cero, usamos los candidatos recuperados para que
        // el Dependiente pueda proponer una bifurcación real del catálogo sin
        // inventar opciones ni consultar otra fuente desde el Intérprete.
        $clarification_facets = self::build_facets($matched ? $matched : $documents);

        // v0.2.12: contenido editorial y FAQ son ramas paralelas. Una FAQ solo
        // hereda de su owner producto/categoria y nunca compite semanticamente con
        // posts o paginas. Se conservan dos carriles independientes en la respuesta.
        $faq_related_internal = self::faq_content_search($query, 18, $matched ? $matched : $documents, true, $explicit_faq_owner);
        $faq_related = self::rank_related_items($faq_related_internal, 12, true);

        $editorial_direct = self::rank_related_items($direct_related, 18, true);
        $editorial_category = $run_extended
            ? self::category_related_content($matched ? $matched : $documents, $query, 18)
            : array();
        $editorial_related = self::merge_related_items($editorial_direct, $editorial_category, 12);

        // `related` se mantiene por compatibilidad con el frontend, pero se forma
        // preservando ambos carriles para que un tipo de conocimiento no expulse al otro.
        $related = self::merge_related_lanes($editorial_related, $faq_related, $query, 6);
        $search_diagnostic['editorial_related_count'] = count($editorial_related);
        $search_diagnostic['faq_related_count'] = count($faq_related);
        if (!empty($explicit_faq_owner['object_id'])) {
            $search_diagnostic['faq_owner_resolution'] = sanitize_key((string) ($explicit_faq_owner['source'] ?? 'explicit'));
            $search_diagnostic['faq_owner_type'] = absint($explicit_faq_owner['object_type'] ?? 0);
            $search_diagnostic['faq_owner_id'] = absint($explicit_faq_owner['object_id'] ?? 0);
        }

        $total = count($matched);
        $pages = $total ? (int) ceil($total / $per_page) : 0;
        if ($pages && $page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $per_page;
        // $matched ya contiene únicamente productos publicables, por lo que la
        // ventana de página y el contador usan exactamente el mismo universo.
        $page_documents = array_slice($matched, $offset, $per_page);
        $results = array();
        foreach ($page_documents as $document) {
            $serialized = self::serialize_result($document, $query, $filters);
            if ($serialized) {
                $results[] = $serialized;
            }
        }

        // La zona principal reutiliza la misma inteligencia que alimenta los
        // filtros laterales: categorías reales y productos representativos del
        // conjunto encontrado por Dependiente. No interviene Intérprete aquí.
        $discovery = self::build_search_discovery($facets, $matched, $results);

        $public_semantic = class_exists('SEO_Dependiente_Semantics')
            ? SEO_Dependiente_Semantics::public_analysis($semantic)
            : array();
        if ($semantic_hints) {
            $public_semantic['confirmed_hint'] = $semantic_hint;
            $public_semantic['confirmed_hints'] = $semantic_hints;
        }
        // Intérprete desactivado: no hay preguntas ni aclaraciones paralelas.
        $clarification = array();
        // Amazon es una tercera fuente complementaria, no un fallback condicionado.
        // Se prepara en toda busqueda que tenga consulta. El frontend la carga aparte
        // para no bloquear productos ni guias si Amazon tarda o falla.
        // Contexto visual para Amazon sin API. Se resuelve con el mismo helper
        // que usa la plantilla de producto/categoría en producción; de este modo
        // no dependemos de que el JSON de resultados traiga ya una URL de imagen.
        // Las imágenes siguen siendo orientativas del catálogo propio, no fichas
        // ni fotografías atribuidas a un producto concreto de Amazon.
        $amazon_context_images = self::amazon_context_images($results, $related, $matched);

        $amazon_fallback = self::amazon_fallback($query, $semantic, array(
            'page'            => $page,
            'solution_role'   => $solution_role,
            'total'           => $total,
            'strategy'        => (string) ($search_diagnostic['strategy'] ?? 'strict'),
            'top_object_hits' => !empty($matched) ? absint($matched[0]['_object_hits'] ?? 0) : 0,
            'context_images'  => $amazon_context_images,
        ));

        $execution_ms = (microtime(true) - $started_at) * 1000;
        $search_id = '';
        $should_log_search = (bool) apply_filters(
            'seo_dependiente_should_log_search',
            true,
            $query,
            $request_kind,
            $request
        );
        if ($should_log_search && class_exists('SEO_Dependiente_Search_Log') && '' !== trim($query)) {
            // El diagnostico guardado incorpora el estado de la peticion para poder
            // reconstruir despues la ruta real: filtros, orden, pagina y aclaracion.
            $log_diagnostic = $search_diagnostic;
            $log_diagnostic['request_context'] = array(
                'page'          => $page,
                'orderby'       => $orderby,
                'filters'       => $filters,
                'solution_role' => $solution_role,
                'semantic_hint'  => $semantic_hint,
                'semantic_hints' => $semantic_hints,
            );
            $log_diagnostic['knowledge_results'] = count($related);
            $log_diagnostic['external_search'] = array(
                'provider'    => (string) ($amazon_fallback['provider'] ?? 'amazon'),
                'should_load' => !empty($amazon_fallback['should_load']),
                'reason'      => (string) ($amazon_fallback['reason'] ?? ''),
                'status'      => (string) ($amazon_fallback['status'] ?? ''),
            );
            $search_id = SEO_Dependiente_Search_Log::record_search(array(
                'query'           => $query,
                'session_id'      => $session_id,
                'request_kind'    => $request_kind,
                'mode'            => $mode,
                'semantic'        => $semantic,
                'search_strategy' => (string) ($search_diagnostic['strategy'] ?? 'strict'),
            'semantic_rules_active' => (int) $semantic_rules_active,
                'strategy_detail' => $log_diagnostic,
                'candidate_count' => count($documents),
                'result_count'    => $total,
                'results'         => $results,
                'execution_ms'    => $execution_ms,
            ));
        }

        $response_payload = array(
            'query'           => $raw_query,
            'interpreted_query' => $interpreter ? sanitize_text_field((string) ($interpreter['search_query'] ?? '')) : '',
            'dependiente_query' => sanitize_text_field((string) $query),
            'interpreter_changed' => $interpreter ? !empty($interpreter['changed']) : false,
            'interpreter_lesson' => $interpreter ? sanitize_key((string) ($interpreter['lesson_key'] ?? '')) : '',
            'interpreter_confidence' => $interpreter ? (float) ($interpreter['confidence'] ?? 0) : 0,
            'interpreter_debug' => $interpreter_debug,
            'semantic_hints'  => $semantic_hints,
            'mode'            => $mode,
            'solution_role'   => $solution_role,
            'page'            => $page,
            'per_page'        => $per_page,
            'pages'           => $pages,
            'total'           => $total,
            'candidate_total' => count($documents),
            'truncated'       => count($candidate_rows) >= self::CANDIDATE_LIMIT,
            'results'         => $results,
            'related'         => $related,
            // Carriles canonicos separados. FAQ: solo owner producto/categoria.
            // Editorial: posts/paginas por Vocabulary/relaciones editoriales.
            'related_editorial' => $editorial_related,
            'related_faq'       => $faq_related,
            'facets'          => $facets,
            'discovery'       => $discovery,
            'filters'         => $filters,
            'semantic'        => $public_semantic,
            'search_id'       => $search_id,
            'search_strategy' => (string) ($search_diagnostic['strategy'] ?? 'strict'),
            'semantic_rules_active' => (int) $semantic_rules_active,
            'clarification'    => $clarification,
            'external_fallback' => $amazon_fallback,
        );
        if ((bool) apply_filters('seo_dependiente_expose_search_diagnostic', false, $request, $semantic)) {
            $response_payload['search_diagnostic'] = self::public_search_diagnostic($search_diagnostic);
        }
        return rest_ensure_response($response_payload);
    }


    /**
     * Caller de Amazon (fuente 1C) desde el flujo principal del Dependiente.
     * La busqueda interna nunca depende de Amazon ni de Creators API.
     */
    /**
     * Resuelve imágenes de apoyo para las búsquedas afiliadas Amazon reutilizando
     * el mismo criterio visual de las plantillas DHT. Prioriza thumbnails locales
     * de productos/categorías y después acepta las imágenes ya serializadas por el
     * Dependiente. Nunca obtiene ni scrapea imágenes de Amazon en el modo sin API.
     */
    private static function amazon_context_images($results, $related, $matched = array()) {
        $images = array();
        $seen = array();

        $append = static function($candidate) use (&$images, &$seen) {
            $candidate = esc_url_raw((string) $candidate);
            if ('' === $candidate || isset($seen[$candidate])) {
                return false;
            }
            $seen[$candidate] = true;
            $images[] = $candidate;
            return count($images) >= 12;
        };

        /*
         * La plantilla compartida ya conoce todas las fuentes visuales reales:
         * Media de WooCommerce, wp_seo_supplier_images y Supplier Sync. La cargamos
         * para no limitar Amazon a _thumbnail_id, que era la causa de que algunas
         * tarjetas quedasen sin imagen aunque el producto tuviese fotos de proveedor.
         */
        if (!function_exists('dht_shared_product_image_candidates')) {
            $helpers = dirname(__DIR__, 2) . '/seo-system/templates/template-helpers.php';
            if (is_readable($helpers)) {
                require_once $helpers;
            }
        }

        if (!function_exists('dht_amazon_context_image')) {
            $category_template = dirname(__DIR__, 2) . '/seo-system/templates/template-amazon-category.php';
            if (is_readable($category_template)) {
                require_once $category_template;
            }
        }
        if (!function_exists('dht_amazon_product_deepest_category')) {
            $product_template = dirname(__DIR__, 2) . '/seo-system/templates/template-amazon-product.php';
            if (is_readable($product_template)) {
                require_once $product_template;
            }
        }

        $add_product_images = static function($product_id) use ($append) {
            $product_id = absint($product_id);
            if (!$product_id) {
                return false;
            }

            /*
             * Primero usamos el resolvedor del propio indice, que tambien contempla
             * catalogos de proveedor aun no materializados en seo_supplier_images.
             */
            if (class_exists('SEO_Dependiente_Index')) {
                $primary = SEO_Dependiente_Index::product_image_url($product_id);
                if ($append($primary)) {
                    return true;
                }
            }

            /*
             * Despues recogemos varias candidatas. Esto es importante porque una URL
             * remota concreta puede haber caducado aunque la siguiente siga activa.
             * El logo no se mezcla aqui: se reserva para el ultimo fallback visual.
             */
            if (function_exists('dht_shared_product_image_candidates')) {
                foreach ((array) dht_shared_product_image_candidates($product_id, 'woocommerce_thumbnail', 5, false) as $candidate) {
                    if ($append($candidate['url'] ?? '')) {
                        return true;
                    }
                }
            }

            if (function_exists('dht_amazon_context_image')) {
                $image = dht_amazon_context_image('product', $product_id, array('type' => 'direct'));
                if ($append($image)) {
                    return true;
                }
            }

            return false;
        };

        /*
         * 1) Resultados visibles, en el mismo orden que ve el usuario.
         */
        foreach ((array) $results as $item) {
            if ($add_product_images($item['id'] ?? $item['product_id'] ?? 0)) {
                return array_slice($images, 0, 12);
            }
        }

        /*
         * 2) Candidatos internos adicionales. Aportan variedad cuando los primeros
         * resultados comparten una misma imagen o alguno tiene una URL remota rota.
         */
        foreach (array_slice((array) $matched, 0, 20) as $document) {
            if ($add_product_images($document['product_id'] ?? $document['id'] ?? 0)) {
                return array_slice($images, 0, 12);
            }
        }

        /*
         * 3) Productos de las categorias encontradas. No exigimos _thumbnail_id:
         * un producto puede tener solamente imagen externa de proveedor.
         */
        $category_ids = array();
        foreach ((array) $matched as $document) {
            foreach ((array) ($document['categories'] ?? array()) as $category) {
                $term_id = is_array($category)
                    ? absint($category['id'] ?? $category['term_id'] ?? 0)
                    : 0;
                if ($term_id) {
                    $category_ids[$term_id] = true;
                }
            }
            if (count($category_ids) >= 8) {
                break;
            }
        }

        foreach (array_keys($category_ids) as $term_id) {
            $ids = get_posts(array(
                'post_type'           => 'product',
                'post_status'         => 'publish',
                'posts_per_page'      => 6,
                'fields'              => 'ids',
                'orderby'             => 'menu_order date',
                'order'               => 'DESC',
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
                'tax_query'           => array(array(
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => array((int) $term_id),
                    'include_children' => true,
                )),
            ));

            foreach ((array) $ids as $product_id) {
                if ($add_product_images($product_id)) {
                    return array_slice($images, 0, 12);
                }
            }
        }

        /*
         * 4) Ultimo apoyo: imagenes ya serializadas por productos, posts o landings.
         */
        foreach (array_merge((array) $results, (array) $related) as $item) {
            if ($append($item['image'] ?? '')) {
                break;
            }
        }

        return array_slice($images, 0, 12);
    }

    private static function amazon_fallback($query, $semantic, $context = array()) {
        $empty = array(
            'provider'    => 'amazon',
            'should_load' => false,
            'reason'      => '',
            'query'       => '',
            'token'       => '',
            'bucket'      => 0,
        );

        if (!class_exists('SEO_Dependiente_Amazon')) {
            return $empty;
        }

        $fallback = SEO_Dependiente_Amazon::fallback_descriptor(
            (string) $query,
            is_array($semantic) ? $semantic : array(),
            is_array($context) ? $context : array()
        );

        return is_array($fallback) ? wp_parse_args($fallback, $empty) : $empty;
    }


    public static function search_feedback(WP_REST_Request $request) {
        $params = self::request_params($request);
        $search_id = isset($params['search_id']) ? sanitize_text_field((string) $params['search_id']) : '';
        $event = isset($params['event']) ? sanitize_key((string) $params['event']) : '';
        if (!$search_id || !in_array($event, array('click', 'helpful', 'clarification_shown', 'clarify'), true)) {
            return new WP_Error('seo_dependiente_feedback_invalid', 'Datos de feedback incompletos.', array('status' => 400));
        }
        if (!class_exists('SEO_Dependiente_Search_Log')) {
            return new WP_Error('seo_dependiente_feedback_unavailable', 'El registro de busquedas no esta disponible.', array('status' => 503));
        }

        $ok = SEO_Dependiente_Search_Log::record_feedback($search_id, $event, array(
            'product_id'   => absint($params['product_id'] ?? 0),
            'position'     => absint($params['position'] ?? 0),
            'value'        => 'clarify' === $event
                ? sanitize_text_field((string) ($params['choice_value'] ?? $params['value'] ?? ''))
                : (int) ($params['value'] ?? 0),
            'reason'       => sanitize_text_field((string) ($params['reason'] ?? '')),
            'question'     => sanitize_text_field((string) ($params['question'] ?? '')),
            'options'      => isset($params['options']) && is_array($params['options']) ? $params['options'] : array(),
            'role'         => sanitize_key((string) ($params['role'] ?? '')),
            'label'        => sanitize_text_field((string) ($params['label'] ?? '')),
            'source'       => sanitize_key((string) ($params['source'] ?? '')),
            'source_group' => sanitize_key((string) ($params['source_group'] ?? '')),
            'source_slug'  => sanitize_title((string) ($params['source_slug'] ?? '')),
            'is_other'     => !empty($params['is_other']) ? 1 : 0,
        ));
        if (!$ok) {
            return new WP_Error('seo_dependiente_feedback_failed', 'No se pudo registrar el feedback.', array('status' => 404));
        }
        return rest_ensure_response(array('ok' => true));
    }

    public static function help_request(WP_REST_Request $request) {
        if (!class_exists('SEO_Dependiente_Help')) {
            return new WP_Error('seo_dependiente_help_unavailable', 'La asistencia no esta disponible.', array('status' => 503));
        }

        $params = self::request_params($request);
        $result = SEO_Dependiente_Help::submit(array(
            'search_id'     => sanitize_text_field((string) ($params['search_id'] ?? '')),
            'email'         => sanitize_email((string) ($params['email'] ?? '')),
            'note'          => sanitize_textarea_field((string) ($params['note'] ?? '')),
            'query'         => sanitize_text_field((string) ($params['query'] ?? '')),
            'mode'          => sanitize_key((string) ($params['mode'] ?? 'need')),
            'solution_role' => self::sanitize_solution_role($params['solution_role'] ?? ''),
            'context_label' => sanitize_text_field((string) ($params['context_label'] ?? '')),
            'page_url'      => esc_url_raw((string) ($params['page_url'] ?? '')),
            'filters'       => isset($params['filters']) && is_array($params['filters']) ? $params['filters'] : array(),
            'semantic_hint' => isset($params['semantic_hint']) && is_array($params['semantic_hint']) ? $params['semantic_hint'] : array(),
            'orderby'       => sanitize_key((string) ($params['orderby'] ?? 'relevance')),
            'compare_ids'   => isset($params['compare_ids']) && is_array($params['compare_ids']) ? $params['compare_ids'] : array(),
            'website'       => sanitize_text_field((string) ($params['website'] ?? '')),
        ));

        return is_wp_error($result) ? $result : rest_ensure_response($result);
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
                'image'       => self::product_image_or_logo(absint($document['product_id'] ?? 0)),
                'price'       => self::comparison_price_text($product),
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

    private static function sanitize_semantic_hint($hint) {
        if (!is_array($hint)) {
            return array();
        }
        $role = sanitize_key((string) ($hint['role'] ?? ''));
        if (!in_array($role, array('intent','object','context','state','term','material'), true)) {
            return array();
        }
        $value = class_exists('SEO_Dependiente_Semantics')
            ? SEO_Dependiente_Semantics::normalize((string) ($hint['value'] ?? ''))
            : SEO_Dependiente_Index::normalize((string) ($hint['value'] ?? ''));
        if (!$value) {
            return array();
        }
        return array(
            'role'         => $role,
            'value'        => $value,
            'label'        => sanitize_text_field((string) ($hint['label'] ?? $value)),
            'source'       => sanitize_key((string) ($hint['source'] ?? 'clarification')) ?: 'clarification',
            'source_group' => sanitize_key((string) ($hint['source_group'] ?? '')),
            'source_slug'  => sanitize_title((string) ($hint['source_slug'] ?? '')),
        );
    }

    private static function sanitize_semantic_hints($hints) {
        $clean = array();
        if (!is_array($hints)) {
            return $clean;
        }
        foreach ($hints as $hint) {
            $hint = self::sanitize_semantic_hint($hint);
            if (!$hint) {
                continue;
            }
            $duplicate = false;
            foreach ($clean as $known) {
                if (($known['role'] ?? '') === ($hint['role'] ?? '')
                    && ($known['value'] ?? '') === ($hint['value'] ?? '')
                    && ($known['source_group'] ?? '') === ($hint['source_group'] ?? '')) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $clean[] = $hint;
            }
            if (count($clean) >= 2) {
                break;
            }
        }
        return $clean;
    }

    /**
     * El Dependiente ya no redacta preguntas. Entrega únicamente orientación
     * agregada del catálogo al Intérprete, que decide si preguntar, qué eje
     * divide mejor los candidatos y qué opciones cerradas mostrar.
     */
    private static function build_clarification($query, $mode, $semantic, $facets, $total, $diagnostic, $semantic_hints, $request_kind = 'search', $resolved_owner = array(), $interpreter = array()) {
        $empty = array(
            'should_ask'          => false,
            'question'            => '',
            'role'                => '',
            'reason'              => '',
            'delay_ms'            => 0,
            'step'                => 0,
            'max_steps'           => 2,
            'strategy'            => 'interpreter_information_gain',
            'axis'                => '',
            'estimated_reduction' => 0,
            'options'             => array(),
        );
        if (!class_exists('SEO_Dependiente_Interprete') || !method_exists('SEO_Dependiente_Interprete', 'plan_clarification')) {
            return $empty;
        }
        // El Intérprete no interrumpe una búsqueda que el Dependiente ya ha resuelto.
        // Por ahora solo puede pedir contexto cuando no hay ningún producto utilizable.
        if (absint($total) > 0) {
            return $empty;
        }

        $clarification = SEO_Dependiente_Interprete::plan_clarification(array(
            'query'           => (string) $query,
            'mode'            => (string) $mode,
            'semantic'        => is_array($semantic) ? $semantic : array(),
            'facets'          => is_array($facets) ? $facets : array(),
            'total'           => absint($total),
            'diagnostic'      => is_array($diagnostic) ? $diagnostic : array(),
            'confirmed_hints' => is_array($semantic_hints) ? $semantic_hints : array(),
            'request_kind'    => (string) $request_kind,
            'resolved_owner'  => is_array($resolved_owner) ? $resolved_owner : array(),
            'interpretation'  => is_array($interpreter) ? $interpreter : array(),
            'dependiente_needs_help' => absint($total) === 0,
        ));

        return is_array($clarification) ? wp_parse_args($clarification, $empty) : $empty;
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

    /** Convierte los grupos semánticos/tokens en un formato seguro de diagnóstico. */
    private static function debug_search_groups($semantic, $token_groups) {
        $out = array();
        $semantic_groups = isset($semantic['groups']) && is_array($semantic['groups']) ? $semantic['groups'] : array();
        if ($semantic_groups) {
            foreach (array_slice($semantic_groups, 0, 12) as $group) {
                $variants = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) ($group['variants'] ?? array())))));
                if (!$variants) {
                    continue;
                }
                $out[] = array(
                    'role'      => sanitize_key((string) ($group['role'] ?? 'term')) ?: 'term',
                    'canonical' => sanitize_text_field((string) ($group['canonical'] ?? ($variants[0] ?? ''))),
                    'variants'  => array_slice($variants, 0, 8),
                );
            }
            return $out;
        }
        foreach (array_slice((array) $token_groups, 0, 12) as $variants) {
            $variants = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $variants))));
            if ($variants) {
                $out[] = array('role' => 'term', 'canonical' => (string) $variants[0], 'variants' => array_slice($variants, 0, 8));
            }
        }
        return $out;
    }

    /** Formato compacto para los grupos usados en la primera consulta al índice. */
    private static function debug_plain_groups($groups) {
        $out = array();
        foreach (array_slice((array) $groups, 0, 12) as $variants) {
            $variants = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $variants))));
            if ($variants) {
                $out[] = array_slice($variants, 0, 8);
            }
        }
        return $out;
    }

    /** Rutas TIPO/ROL/APLICACION/etc. activadas por la semántica de Dependiente. */
    private static function debug_semantic_routes($semantic) {
        $out = array();
        foreach (array_slice((array) ($semantic['routes'] ?? array()), 0, 12) as $route) {
            $target = sanitize_text_field((string) ($route['target_slug'] ?? ''));
            if ('' === $target) {
                continue;
            }
            $out[] = array(
                'group' => sanitize_key((string) ($route['target_group'] ?? '')),
                'term'  => $target,
                'role'  => sanitize_key((string) ($route['result_role'] ?? '')),
            );
        }
        return $out;
    }

    /**
     * 1A. Recupera candidatos solo desde campos que describen directamente la
     * identidad/clasificacion del producto. Las descripciones largas quedan para
     * la fase 2 extensiva.
     */
    /**
     * Construye el perfil de ayuda lingüística que Dependiente puede utilizar
     * sin delegar en Intérprete ninguna decisión de catálogo.
     *
     * - identity_terms: conceptos comerciales relacionados (taladro, broca...) y
     *   categoría/etiqueta explícitamente reconocidas.
     * - vocabulary_terms: TIPO, ROL, APLICACIÓN, PLATAFORMA y SUBTIPO.
     * - action_terms: acciones canónicas (taladrar, cortar, lijar...).
     * - context_terms: palabras conservadas como contexto (pared, madera...).
     */
    private static function interpreter_assist_profile($interpreter) {
        $profile = array(
            'concept_terms'    => array(),
            'identity_terms'   => array(),
            'vocabulary_terms' => array(),
            'action_terms'     => array(),
            'context_terms'    => array(),
        );
        if (!is_array($interpreter) || empty($interpreter['structured']) || !is_array($interpreter['structured'])) {
            return $profile;
        }

        $structured = $interpreter['structured'];
        foreach ((array) ($structured['actions'] ?? array()) as $action) {
            $canonical = SEO_Dependiente_Index::normalize((string) ($action['canonical_action'] ?? $action['lemma'] ?? ''));
            $related = SEO_Dependiente_Index::normalize((string) ($action['related_concept'] ?? ''));
            if ($canonical) {
                $profile['action_terms'][] = $canonical;
            }
            if ($related) {
                $profile['concept_terms'][] = $related;
            }
        }
        foreach ((array) ($structured['related_concepts'] ?? array()) as $item) {
            $term = SEO_Dependiente_Index::normalize((string) ($item['term'] ?? ''));
            if ($term) {
                $profile['concept_terms'][] = $term;
            }
        }

        $semantic_groups = isset($structured['semantic_groups']) && is_array($structured['semantic_groups'])
            ? $structured['semantic_groups']
            : array();
        foreach (array('category','tag') as $group) {
            foreach ((array) ($semantic_groups[$group] ?? array()) as $item) {
                $term = SEO_Dependiente_Index::normalize((string) ($item['target'] ?? $item['term'] ?? ''));
                if ($term) {
                    $profile['identity_terms'][] = $term;
                }
            }
        }
        foreach (array('rol','tipo','aplicacion','plataforma','subtipo') as $group) {
            foreach ((array) ($semantic_groups[$group] ?? array()) as $item) {
                $term = SEO_Dependiente_Index::normalize((string) ($item['target'] ?? $item['term'] ?? ''));
                if ($term) {
                    $profile['vocabulary_terms'][] = $term;
                }
            }
        }
        foreach ((array) ($structured['context'] ?? array()) as $term) {
            $term = SEO_Dependiente_Index::normalize((string) $term);
            if ($term) {
                $profile['context_terms'][] = $term;
            }
        }

        // Si Lingüista ha identificado un concepto relacionado concreto (p.ej.
        // agujerear -> taladro), ese concepto es el ancla principal. Etiquetas
        // genéricas como madera/pared siguen viajando como contexto, pero no abren
        // por sí solas la familia de productos mientras exista una ancla mejor.
        if ($profile['concept_terms']) {
            $profile['context_terms'] = array_merge($profile['context_terms'], $profile['identity_terms']);
            $profile['identity_terms'] = $profile['concept_terms'];
        }

        foreach ($profile as $key => $terms) {
            $profile[$key] = array_slice(array_values(array_unique(array_filter($terms))), 0, 12);
        }

        // La identidad comercial se verifica también contra WooCommerce vivo.
        // El índice puede quedarse desfasado tras importaciones/clonados; una fila
        // antigua nunca debe convertir un producto distinto en "taladro".
        $profile['_live_identity_ids'] = $profile['identity_terms']
            ? self::live_identity_product_ids($profile['identity_terms'], 500)
            : array();

        return $profile;
    }

    /**
     * Devuelve variantes morfológicas conservadoras para una ancla de identidad.
     * No intenta sinonimia: eso pertenece a Intérprete/Lingüista.
     */
    private static function identity_forms($term) {
        $term = SEO_Dependiente_Index::normalize((string) $term);
        if ('' === $term) {
            return array();
        }
        $forms = array($term);
        $parts = preg_split('/\s+/', $term);
        if (1 === count($parts)) {
            $last = $parts[0];
            if (preg_match('/[aeiou]$/', $last)) {
                $forms[] = $last . 's';
            } elseif (preg_match('/[rlndz]$/', $last)) {
                $forms[] = $last . 'es';
            } else {
                $forms[] = $last . 's';
            }
            if (substr($last, -1) === 's' && strlen($last) > 3) {
                $forms[] = substr($last, 0, -1);
            }
        }
        return array_values(array_unique(array_filter($forms)));
    }

    /** Coincidencia por palabra/frase completa; evita falsos positivos por substring. */
    private static function identity_text_matches($text, $term) {
        $text = ' ' . SEO_Dependiente_Index::normalize((string) $text) . ' ';
        foreach (self::identity_forms($term) as $form) {
            if (false !== strpos($text, ' ' . $form . ' ')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Busca IDs directamente en WooCommerce vivo por título o categoría.
     * Esta vía no usa el índice semántico y sirve de guardia frente a filas viejas.
     */
    private static function live_identity_product_ids($terms, $limit = 500) {
        global $wpdb;
        $limit = min(800, max(20, absint($limit)));
        $ids = array();
        foreach (array_slice((array) $terms, 0, 6) as $term) {
            $term = SEO_Dependiente_Index::normalize((string) $term);
            if ('' === $term) {
                continue;
            }
            foreach (self::identity_forms($term) as $form) {
                $like = '%' . $wpdb->esc_like($form) . '%';
                $sql = $wpdb->prepare(
                    "SELECT DISTINCT p.ID
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                     LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
                     LEFT JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                     WHERE p.post_type = 'product'
                       AND p.post_status = 'publish'
                       AND (p.post_title LIKE %s OR t.name LIKE %s OR t.slug LIKE %s)
                     LIMIT %d",
                    $like,
                    $like,
                    '%' . $wpdb->esc_like(sanitize_title($form)) . '%',
                    $limit
                );
                foreach ((array) $wpdb->get_col($sql) as $product_id) {
                    $ids[absint($product_id)] = true;
                    if (count($ids) >= $limit) {
                        break 3;
                    }
                }
            }
        }
        return array_values(array_filter(array_map('absint', array_keys($ids))));
    }

    /** Obtiene filas del índice por ID, sin volver a decidir identidad desde el índice. */
    private static function index_rows_by_product_ids($product_ids, $limit = 500) {
        global $wpdb;
        if (!SEO_Dependiente_Index::table_exists()) {
            return array();
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('absint', (array) $product_ids)))), 0, absint($limit));
        if (!$ids) {
            return array();
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = $wpdb->prepare(
            'SELECT * FROM `' . esc_sql(SEO_Dependiente_Index::table()) . "` WHERE product_id IN ({$placeholders})",
            $ids
        );
        return (array) $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Comprueba la identidad contra el producto vivo. Las etiquetas NO cuentan
     * como identidad fuerte: pueden describir uso/contexto y estaban provocando
     * falsos positivos como Marcadores de Entrada => taladro.
     */
    private static function live_identity_evidence($document, $terms, $live_ids = array()) {
        $product_id = absint($document['product_id'] ?? 0);
        if (!$product_id || !$terms) {
            return array('hits' => 0, 'sources' => array());
        }
        $live_map = array_fill_keys(array_map('absint', (array) $live_ids), true);
        if ($live_map && !isset($live_map[$product_id])) {
            return array('hits' => 0, 'sources' => array());
        }
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product) {
            return array('hits' => 0, 'sources' => array());
        }
        $title = (string) $product->get_name();
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
        if (is_wp_error($categories)) {
            $categories = array();
        }
        $sources = array();
        $hits = 0;
        foreach ((array) $terms as $term) {
            $term = SEO_Dependiente_Index::normalize((string) $term);
            if ('' === $term) {
                continue;
            }
            if (self::identity_text_matches($title, $term)) {
                $hits++;
                $sources[] = 'titulo:' . $term;
                continue;
            }
            foreach ((array) $categories as $category_name) {
                if (self::identity_text_matches((string) $category_name, $term)) {
                    $hits++;
                    $sources[] = 'categoria:' . SEO_Dependiente_Index::normalize((string) $category_name);
                    break;
                }
            }
        }
        return array(
            'hits' => $hits,
            'sources' => array_values(array_unique(array_filter($sources))),
        );
    }

    /**
     * Si existen suficientes productos con identidad viva real, elimina del
     * conjunto los candidatos que solo entraron por contexto/rutas secundarias.
     */
    private static function identity_coherent_documents($documents, $assist_profile) {
        $documents = array_values((array) $documents);
        if (empty($assist_profile['identity_terms'])) {
            return $documents;
        }
        $strong = array_values(array_filter($documents, static function ($document) {
            return absint($document['_assist_identity_hits'] ?? 0) > 0;
        }));
        if (count($strong) < 3) {
            return $documents;
        }
        return $strong;
    }

    /**
     * 1A. Recuperación de identidad. Si Intérprete ha reconocido un concepto
     * comercial, este manda en la primera pasada. El contexto sirve después para
     * ordenar, pero no para decidir por sí solo qué familia de producto entra.
     */
    private static function primary_candidate_rows($query, $semantic = array(), $assist_profile = array(), &$diagnostic = array()) {
        $diagnostic = array(
            'strategy'            => 'primary_direct',
            'primary_rows'        => 0,
            'primary_group_count' => 0,
        );
        if (!SEO_Dependiente_Index::table_exists()) {
            $diagnostic['strategy'] = 'index_unavailable';
            return array();
        }

        $identity_terms = array_values(array_filter((array) ($assist_profile['identity_terms'] ?? array())));
        $vocabulary_terms = array_values(array_filter((array) ($assist_profile['vocabulary_terms'] ?? array())));
        $action_terms = array_values(array_filter((array) ($assist_profile['action_terms'] ?? array())));
        $rows = array();

        // 1. Un concepto relacionado como "taladro" busca primero donde un
        // producto define realmente su identidad. No se miran descripción ni
        // atributos, evitando coincidencias accidentales.
        if ($identity_terms) {
            $identity_groups = array_map(static function ($term) { return array($term); }, array_slice($identity_terms, 0, 8));
            $rows = self::query_primary_index(
                $identity_groups,
                false,
                self::CANDIDATE_LIMIT,
                array('normalized_title','categories_json','tags_json')
            );
            // Añadimos además los productos que WooCommerce vivo identifica
            // por título/categoría. Esto protege la búsqueda frente a un índice
            // desfasado y evita que un ID reutilizado herede la identidad antigua.
            $live_identity_rows = self::index_rows_by_product_ids(
                (array) ($assist_profile['_live_identity_ids'] ?? array()),
                500
            );
            $rows = self::merge_candidate_rows($rows, $live_identity_rows);
            if ($rows) {
                $diagnostic['strategy'] = 'primary_interpreter_identity';
            }
            $diagnostic['live_identity_rows'] = count($live_identity_rows);
        }

        // 2. TIPO/ROL/APLICACIÓN/etc. sí viven en Vocabulary; se añaden como
        // señales de clasificación, sin abrir todavía el texto descriptivo.
        if ($vocabulary_terms && count($rows) < 6) {
            $vocabulary_groups = array_map(static function ($term) { return array($term); }, array_slice($vocabulary_terms, 0, 8));
            $vocab_rows = self::query_primary_index(
                $vocabulary_groups,
                false,
                self::CANDIDATE_LIMIT,
                array('normalized_title','categories_json','tags_json','vocabulary_json')
            );
            $rows = self::merge_candidate_rows($rows, $vocab_rows);
            if ($vocab_rows && 'primary_interpreter_identity' !== $diagnostic['strategy']) {
                $diagnostic['strategy'] = 'primary_interpreter_vocabulary';
            }
        }

        // 3. La acción canónica es un respaldo. Si ya tenemos una familia clara
        // no dejamos que una palabra contextual más genérica contamine la primera
        // pasada; si faltan candidatos, la acción amplía de forma controlada.
        if ($action_terms && count($rows) < 6) {
            $action_groups = array_map(static function ($term) { return array($term); }, array_slice($action_terms, 0, 6));
            $action_rows = self::query_primary_index(
                $action_groups,
                false,
                self::CANDIDATE_LIMIT,
                array('normalized_title','categories_json','tags_json','vocabulary_json')
            );
            $rows = self::merge_candidate_rows($rows, $action_rows);
            if ($action_rows && 'primary_direct' === $diagnostic['strategy']) {
                $diagnostic['strategy'] = 'primary_interpreter_action';
            }
        }

        // Sin señal estructurada, Dependiente conserva su búsqueda normal, pero ya
        // no colapsa a un único "objeto" y no exige que todos los términos estén
        // simultáneamente en la identidad del producto.
        if (!$rows) {
            $groups = self::primary_search_groups($query, $semantic, $assist_profile);
            $diagnostic['primary_group_count'] = count($groups);
            if (!$groups) {
                $diagnostic['strategy'] = 'primary_empty';
                return array();
            }
            $rows = self::query_primary_index(
                $groups,
                false,
                self::CANDIDATE_LIMIT,
                array('normalized_title','sku','brand_name','categories_json','tags_json')
            );
        } else {
            $diagnostic['primary_group_count'] = count($identity_terms) + count($vocabulary_terms) + count($action_terms);
        }

        $diagnostic['primary_rows'] = count($rows);
        return $rows;
    }

    private static function primary_search_groups($query, $semantic = array(), $assist_profile = array()) {
        $groups = array();

        // Para diagnóstico y fallback, las señales fuertes del Intérprete se
        // muestran primero. El contexto no se convierte en ancla de catálogo.
        foreach (array('identity_terms','vocabulary_terms','action_terms') as $key) {
            foreach ((array) ($assist_profile[$key] ?? array()) as $term) {
                $term = SEO_Dependiente_Index::normalize((string) $term);
                if ($term) {
                    $groups[] = array($term);
                }
            }
        }
        if ($groups) {
            return array_slice($groups, 0, 12);
        }

        if (!empty($semantic['groups'])) {
            foreach ((array) $semantic['groups'] as $group) {
                $role = sanitize_key((string) ($group['role'] ?? 'term'));
                if (in_array($role, array('intent', 'state'), true)) {
                    continue;
                }
                $variants = array_values(array_unique(array_filter(array_map(
                    array('SEO_Dependiente_Index', 'normalize'),
                    (array) ($group['variants'] ?? array())
                ))));
                if ($variants) {
                    $groups[] = array_slice($variants, 0, 8);
                }
            }
        }
        if (!$groups) {
            $groups = self::query_token_groups($query);
        }
        return array_slice($groups, 0, 10);
    }

    private static function query_primary_index($groups, $require_all, $limit, $fields = array()) {
        global $wpdb;

        if (!$fields) {
            $fields = array(
                'normalized_title',
                'sku',
                'brand_name',
                'categories_json',
                'tags_json',
            );
        }
        $allowed_fields = array('normalized_title','sku','brand_name','categories_json','tags_json','vocabulary_json','attributes_json');
        $fields = array_values(array_intersect($allowed_fields, (array) $fields));
        if (!$fields) {
            return array();
        }

        $clauses = array();
        $params = array();
        foreach ((array) $groups as $variants) {
            $variant_clauses = array();
            foreach (array_slice((array) $variants, 0, 8) as $variant) {
                $variant = SEO_Dependiente_Index::normalize((string) $variant);
                if ('' === $variant) {
                    continue;
                }
                foreach ($fields as $field) {
                    $variant_clauses[] = '`' . $field . '` LIKE %s';
                    $params[] = '%' . $wpdb->esc_like($variant) . '%';
                }
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

    /** Puntua una lista de filas con el ranking comun del Dependiente. */
    private static function score_candidate_rows($rows, $query, $tokens, $filters, $mode, $semantic, $assist_profile = array(), &$documents = array()) {
        $documents = array_map(array('SEO_Dependiente_Index', 'decode_row'), (array) $rows);
        $matched = array();
        foreach ($documents as $document) {
            if (!self::matches_filters($document, $filters)) {
                continue;
            }
            $score = self::score_document($document, $query, $tokens, $filters, $mode, $semantic, $assist_profile);
            if (isset($score['eligible']) && !$score['eligible']) {
                continue;
            }
            $document['_score'] = $score['score'];
            $document['_reasons'] = $score['reasons'];
            $document['_object_hits'] = absint($score['object_hits'] ?? 0);
            $document['_route_hits'] = absint($score['route_hits'] ?? 0);
            $document['_coverage'] = absint($score['coverage'] ?? 0);
            $document['_assist_identity_hits'] = absint($score['assist_identity_hits'] ?? 0);
            $document['_assist_identity_sources'] = array_values((array) ($score['assist_identity_sources'] ?? array()));
            $document['_assist_vocabulary_hits'] = absint($score['assist_vocabulary_hits'] ?? 0);
            $document['_assist_action_hits'] = absint($score['assist_action_hits'] ?? 0);
            $matched[] = $document;
        }
        return $matched;
    }

    /** Fusiona filas del índice sin duplicar productos; el segundo conjunto gana. */
    private static function merge_candidate_rows($first, $second) {
        $map = array();
        foreach (array_merge((array) $first, (array) $second) as $row) {
            $product_id = absint($row['product_id'] ?? 0);
            if ($product_id) {
                $map[$product_id] = $row;
            }
            if (count($map) >= self::CANDIDATE_LIMIT) {
                break;
            }
        }
        return array_values($map);
    }

    /**
     * Umbral de "hay bastante informacion" para decidir si merece la pena abrir
     * la fase 2. Se puede ajustar sin cambiar el orden de las fuentes.
     */
    private static function local_search_sufficient($primary_products, $direct_knowledge) {
        $products = count((array) $primary_products);
        $knowledge = count((array) $direct_knowledge);

        // El conocimiento editorial complementa productos; no puede declarar por
        // sí solo que la búsqueda de catálogo es suficiente. Con cero o un producto
        // siempre queda abierta la posibilidad de recuperar más catálogo.
        $sufficient = $products >= 6
            || ($products >= 3 && $knowledge >= 1)
            || ($products >= 2 && $knowledge >= 2);

        return (bool) apply_filters(
            'seo_dependiente_local_search_sufficient',
            $sufficient,
            $products,
            $knowledge
        );
    }

    private static function has_catalog_semantic_route($semantic) {
        foreach ((array) ($semantic['routes'] ?? array()) as $route) {
            $group = sanitize_key((string) ($route['target_group'] ?? ''));
            if (!in_array($group, array('rol', 'tipo', 'aplicacion', 'plataforma', 'subtipo'), true)) {
                continue;
            }
            if (absint($route['target_vocabulary_id'] ?? 0) > 0
                || '' !== trim((string) ($route['target_slug'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    private static function public_search_diagnostic($diagnostic) {
        $diagnostic = is_array($diagnostic) ? $diagnostic : array();
        $out = array();
        foreach (array(
            'strategy', 'primary_strategy', 'extended_search', 'solution_role', 'faq_owner_resolution'
        ) as $key) {
            if (isset($diagnostic[$key])) {
                $out[$key] = sanitize_key((string) $diagnostic[$key]);
            }
        }
        foreach (array(
            'primary_rows', 'primary_group_count', 'primary_product_count', 'live_identity_rows',
            'direct_knowledge_count', 'strict_count', 'semantic_product_ids',
            'semantic_route_rows', 'object_anchor_rows', 'broad_fallback_rows',
            'semantic_catalog_route', 'semantic_rules_active',
            'editorial_related_count', 'faq_related_count',
            'faq_owner_type', 'faq_owner_id'
        ) as $key) {
            if (isset($diagnostic[$key])) {
                $out[$key] = absint($diagnostic[$key]);
            }
        }
        $out['extended_reasons'] = array_values(array_slice(array_filter(array_map(
            'sanitize_key',
            (array) ($diagnostic['extended_reasons'] ?? array())
        )), 0, 8));
        return $out;
    }

    private static function candidate_rows($query, $semantic = array(), &$diagnostic = array()) {
        global $wpdb;

        $diagnostic = array(
            'strategy'             => 'strict',
            'strict_count'         => 0,
            'semantic_product_ids' => 0,
            'semantic_route_rows'  => 0,
            'object_anchor_rows'   => 0,
            'broad_fallback_rows'  => 0,
        );
        if (!SEO_Dependiente_Index::table_exists()) {
            $diagnostic['strategy'] = 'index_unavailable';
            return array();
        }
        $groups = !empty($semantic['groups']) && class_exists('SEO_Dependiente_Semantics')
            ? SEO_Dependiente_Semantics::group_variants($semantic)
            : self::query_token_groups($query);
        if (!$groups) {
            $diagnostic['strategy'] = 'catalog_fallback';
            return SEO_Dependiente_Index::get_rows(self::CANDIDATE_LIMIT);
        }

        $strict = self::query_index($groups, true, self::CANDIDATE_LIMIT);
        $diagnostic['strict_count'] = count($strict);
        $has_semantic_routes = !empty($semantic['routes']);
        if ((count($strict) >= 12 || count($groups) <= 1) && !$has_semantic_routes) {
            return $strict;
        }

        $merged = array();
        foreach ($strict as $row) {
            $merged[absint($row['product_id'])] = $row;
            if (count($merged) >= self::CANDIDATE_LIMIT) {
                break;
            }
        }

        /*
         * Capa semantica: antes de abrir la consulta con OR se buscan productos
         * ligados a las rutas intencion+objeto y terminos sugeridos por esas rutas.
         */
        if (class_exists('SEO_Dependiente_Semantics') && $semantic) {
            $semantic_ids = SEO_Dependiente_Semantics::vocabulary_candidate_product_ids($semantic, 700);
            $diagnostic['semantic_product_ids'] = count($semantic_ids);
            if ($semantic_ids) {
                $diagnostic['strategy'] = 'semantic_routes';
                foreach (SEO_Dependiente_Index::get_rows_by_ids($semantic_ids, 700) as $row) {
                    $merged[absint($row['product_id'])] = $row;
                    if (count($merged) >= self::CANDIDATE_LIMIT) {
                        break;
                    }
                }
            }

            $route_groups = SEO_Dependiente_Semantics::route_search_groups($semantic);
            if ($route_groups && count($merged) < self::CANDIDATE_LIMIT) {
                $route_rows = self::query_index($route_groups, false, self::CANDIDATE_LIMIT);
                $diagnostic['semantic_route_rows'] = count($route_rows);
                if ($route_rows) {
                    $diagnostic['strategy'] = 'semantic_routes';
                }
                foreach ($route_rows as $row) {
                    $merged[absint($row['product_id'])] = $row;
                    if (count($merged) >= self::CANDIDATE_LIMIT) {
                        break;
                    }
                }
            }

            /*
             * Si hay objeto reconocido, el fallback queda anclado al objeto.
             * Evita el comportamiento legacy: "cambiar OR grifo".
             */
            $object_groups = SEO_Dependiente_Semantics::group_variants($semantic, array('object'));
            if ($object_groups && count($merged) < self::CANDIDATE_LIMIT) {
                $anchor_rows = self::query_index($object_groups, true, self::CANDIDATE_LIMIT);
                $diagnostic['object_anchor_rows'] = count($anchor_rows);
                if ($anchor_rows && 'semantic_routes' !== $diagnostic['strategy']) {
                    $diagnostic['strategy'] = 'object_anchor';
                }
                foreach ($anchor_rows as $row) {
                    $merged[absint($row['product_id'])] = $row;
                    if (count($merged) >= self::CANDIDATE_LIMIT) {
                        break;
                    }
                }
            }
        }

        /*
         * Suelo léxico obligatorio. Una interpretación semántica nunca puede
         * impedir que entren productos que coinciden literalmente con alguno
         * de los conceptos enviados por el Intérprete. La semántica reordena y
         * refuerza; no sustituye el buscador textual del catálogo.
         */
        if (count($groups) > 1 && count($merged) < self::CANDIDATE_LIMIT) {
            $broad_limit = min(900, self::CANDIDATE_LIMIT);
            $broad = self::query_index($groups, false, $broad_limit);
            $diagnostic['broad_fallback_rows'] = count($broad);
            if ($broad && 'semantic_routes' !== $diagnostic['strategy']) {
                $diagnostic['strategy'] = 'broad_fallback';
            }
            foreach ($broad as $row) {
                $merged[absint($row['product_id'])] = $row;
                if (count($merged) >= self::CANDIDATE_LIMIT) {
                    break;
                }
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
        if (class_exists('SEO_Dependiente_Semantics') && SEO_Dependiente_Semantics::table_exists()) {
            $analysis = SEO_Dependiente_Semantics::analyze($query);
            $semantic_groups = SEO_Dependiente_Semantics::group_variants($analysis);
            if ($semantic_groups) {
                return $semantic_groups;
            }
        }

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

        if (strlen($token) > 4 && !preg_match('/(?:ar|er|ir)$/', $token)) {
            if ('s' === substr($token, -1)) {
                $variants[] = substr($token, 0, -1);
            } elseif ('z' === substr($token, -1)) {
                $variants[] = substr($token, 0, -1) . 'ces';
            } elseif (preg_match('/[aeiou]$/', $token)) {
                $variants[] = $token . 's';
            } else {
                $variants[] = $token . 'es';
            }
        }

        return array_values(array_unique(array_filter(array_map(array('SEO_Dependiente_Index', 'normalize'), $variants))));
    }

    private static function sanitize_solution_role($role) {
        $role = sanitize_key((string) $role);
        return in_array($role, array('herramienta', 'repuesto', 'accesorio', 'equipamiento'), true)
            ? $role
            : '';
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

    private static function has_active_filters($filters) {
        foreach (array('categories', 'tags', 'brands', 'stock') as $key) {
            if (!empty($filters[$key])) {
                return true;
            }
        }
        return !empty($filters['vocabulary']) || !empty($filters['attributes']) || !empty($filters['ranges']);
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

    private static function score_document($document, $query, $token_groups, $filters, $mode = 'need', $semantic = array(), $assist_profile = array()) {
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
            $group_hits = array_fill_keys(array_keys($field_hits), false);
            $vocabulary_hit = false;
            foreach ($variants as $variant) {
                if (!$token_found && false !== strpos($search_text, $variant)) {
                    $token_found = true;
                    $coverage++;
                }
                if (false !== strpos($title, $variant)) {
                    $group_hits['title'] = true;
                }
                if (false !== strpos($applications, $variant)) {
                    $group_hits['application'] = true;
                }
                if (false !== strpos($platforms, $variant)) {
                    $group_hits['platform'] = true;
                }
                if (false !== strpos($brand, $variant)) {
                    $group_hits['brand'] = true;
                }
                if (false !== strpos($categories, $variant)) {
                    $group_hits['category'] = true;
                }
                if (false !== strpos($tags, $variant)) {
                    $group_hits['tag'] = true;
                }
                if (false !== strpos($attributes, $variant)) {
                    $group_hits['attribute'] = true;
                }
                if (false !== strpos($excerpt, $variant)) {
                    $group_hits['excerpt'] = true;
                }
                if (false !== strpos($vocabulary, $variant)) {
                    $vocabulary_hit = true;
                }
            }
            foreach ($group_hits as $field => $hit) {
                if ($hit) {
                    $field_hits[$field]++;
                }
            }
            if ($vocabulary_hit) {
                $score += 8;
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

        // Jerarquía lingüística: las señales que Intérprete identifica como
        // concepto/TIPO/ROL pesan mucho más que el contexto. Así "taladro" manda
        // sobre "pared" o "madera", sin que Intérprete decida ningún producto.
        $assist_identity_hits = 0;
        $assist_identity_sources = array();
        $assist_vocabulary_hits = 0;
        $assist_action_hits = 0;

        // IDENTIDAD FUERTE = evidencia en el producto vivo, no una coincidencia
        // accidental de etiqueta/descripcion del índice. Título y categoría son
        // las fuentes de identidad; las etiquetas siguen ayudando al ranking
        // normal, pero no pueden declarar por sí solas que algo ES un taladro.
        $identity_evidence = self::live_identity_evidence(
            $document,
            (array) ($assist_profile['identity_terms'] ?? array()),
            (array) ($assist_profile['_live_identity_ids'] ?? array())
        );
        $assist_identity_hits = absint($identity_evidence['hits'] ?? 0);
        $assist_identity_sources = array_values((array) ($identity_evidence['sources'] ?? array()));
        foreach ($assist_identity_sources as $source) {
            if (0 === strpos((string) $source, 'titulo:')) {
                $score += 620;
            } elseif (0 === strpos((string) $source, 'categoria:')) {
                $score += 520;
            }
        }
        foreach ((array) ($assist_profile['vocabulary_terms'] ?? array()) as $term) {
            $term = SEO_Dependiente_Index::normalize((string) $term);
            if (!$term) {
                continue;
            }
            if (false !== strpos($vocabulary, $term)) {
                $score += 280;
                $assist_vocabulary_hits++;
            } elseif (false !== strpos($title, $term) || false !== strpos($categories, $term) || false !== strpos($tags, $term)) {
                $score += 340;
                $assist_vocabulary_hits++;
            }
        }
        foreach ((array) ($assist_profile['action_terms'] ?? array()) as $term) {
            $term = SEO_Dependiente_Index::normalize((string) $term);
            if (!$term) {
                continue;
            }
            if (false !== strpos($applications, $term)
                || false !== strpos($title, $term)
                || false !== strpos($categories, $term)
                || false !== strpos($tags, $term)) {
                $score += 190;
                $assist_action_hits++;
            }
        }
        if ($assist_identity_hits) {
            $reasons[] = 'Coincide con el concepto principal';
        } elseif ($assist_vocabulary_hits) {
            $reasons[] = 'Coincide con la clasificación interpretada';
        } elseif ($assist_action_hits) {
            $reasons[] = 'Coincide con la acción interpretada';
        }

        $semantic_score = array('bonus' => 0, 'reasons' => array(), 'route_hits' => 0, 'object_hits' => 0);
        if ($semantic && class_exists('SEO_Dependiente_Semantics')) {
            $semantic_score = SEO_Dependiente_Semantics::score_document($document, $semantic);
            $score += (float) ($semantic_score['bonus'] ?? 0);
            $reasons = array_merge($reasons, (array) ($semantic_score['reasons'] ?? array()));
        }

        // Todo candidato recuperado por coincidencia léxica sigue siendo
        // elegible. La capa semántica aporta bonus de ranking, pero no puede
        // eliminar un producto que el índice textual ha encontrado.
        $eligible = true;

        return array(
            'score'   => round($score, 4),
            'reasons' => array_slice(array_values(array_unique(array_filter($reasons))), 0, 4),
            'eligible'=> (bool) $eligible,
            'object_hits' => absint($semantic_score['object_hits'] ?? 0),
            'route_hits'  => absint($semantic_score['route_hits'] ?? 0),
            'coverage'    => absint($coverage),
            'assist_identity_hits' => absint($assist_identity_hits),
            'assist_identity_sources' => array_values(array_unique(array_filter($assist_identity_sources))),
            'assist_vocabulary_hits' => absint($assist_vocabulary_hits),
            'assist_action_hits' => absint($assist_action_hits),
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
            // Las señales lingüísticas estructuradas mandan sobre la procedencia
            // técnica del candidato. Un Taladro encontrado por el concepto "taladro"
            // debe ir antes que un producto genérico que entró por contexto/ruta.
            $a_identity = absint($a['_assist_identity_hits'] ?? 0);
            $b_identity = absint($b['_assist_identity_hits'] ?? 0);
            if ($a_identity !== $b_identity) {
                return $b_identity <=> $a_identity;
            }
            $a_vocab = absint($a['_assist_vocabulary_hits'] ?? 0);
            $b_vocab = absint($b['_assist_vocabulary_hits'] ?? 0);
            if ($a_vocab !== $b_vocab) {
                return $b_vocab <=> $a_vocab;
            }
            $a_action = absint($a['_assist_action_hits'] ?? 0);
            $b_action = absint($b['_assist_action_hits'] ?? 0);
            if ($a_action !== $b_action) {
                return $b_action <=> $a_action;
            }
            $a_tier = (string) ($a['_search_tier'] ?? 'direct');
            $b_tier = (string) ($b['_search_tier'] ?? 'direct');
            if ($a_tier !== $b_tier) {
                return 'direct' === $a_tier ? -1 : 1;
            }
            $score_compare = ((float) $b['_score']) <=> ((float) $a['_score']);
            if (0 !== $score_compare) {
                return $score_compare;
            }
            return absint($b['product_id']) <=> absint($a['product_id']);
        });
    }

    /**
     * Determina si un producto del índice puede mostrarse en Dependiente.
     *
     * No usamos WC_Product::is_visible() porque puede incorporar filtros de la
     * plantilla/contexto y producir el estado imposible que vimos en producción:
     * cientos de facetas y páginas, pero cero tarjetas. El índice ya excluye
     * productos ocultos; aquí repetimos únicamente las garantías esenciales.
     */
    private static function product_is_searchable($product) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return false;
        }
        $product_id = absint($product->get_id());
        if (!$product_id || 'publish' !== get_post_status($product_id)) {
            return false;
        }
        if ('hidden' === (string) $product->get_catalog_visibility()) {
            return false;
        }
        return (bool) apply_filters('seo_dependiente_product_is_searchable', true, $product);
    }

    /**
     * Filtra documentos antes de construir facetas/total/paginación.
     */
    private static function filter_searchable_documents($documents) {
        $out = array();
        foreach ((array) $documents as $document) {
            $product_id = absint($document['product_id'] ?? 0);
            if (!$product_id) {
                continue;
            }
            $product = wc_get_product($product_id);
            if (!self::product_is_searchable($product)) {
                continue;
            }
            $out[] = $document;
        }
        return $out;
    }

    /**
     * Construye una navegación visual desde las MISMAS facetas y productos que
     * Dependiente ya ha calculado para la columna izquierda.
     */
    private static function build_search_discovery($facets, $documents, $results) {
        $facets = is_array($facets) ? $facets : array();
        $documents = array_values((array) $documents);
        $categories = self::make_cards(
            array_slice((array) ($facets['categories'] ?? array()), 0, 6),
            'categories',
            '',
            $documents
        );

        $quick = array();
        $vocabulary = (array) ($facets['vocabulary'] ?? array());
        if (!empty($vocabulary['aplicacion'])) {
            $quick = self::make_cards(array_slice((array) $vocabulary['aplicacion'], 0, 6), 'vocabulary', 'aplicacion', $documents);
        } elseif (!empty($facets['tags'])) {
            $quick = self::make_cards(array_slice((array) $facets['tags'], 0, 6), 'tags', '', $documents);
        }

        return array(
            'categories' => $categories,
            'quick_filters' => $quick,
            'products' => array_slice(array_values((array) $results), 0, 4),
        );
    }

    private static function serialize_result($document, $query, $filters) {
        $product = wc_get_product(absint($document['product_id']));
        if (!self::product_is_searchable($product)) {
            return null;
        }

        return array(
            'id'            => $product->get_id(),
            'title'         => $product->get_name(),
            'url'           => get_permalink($product->get_id()),
            'image'         => self::product_image_or_logo($product->get_id()),
            'price_html'    => wp_kses_post($product->get_price_html()),
            'price'         => '' !== (string) $document['price'] ? (float) $document['price'] : null,
            'brand'         => (string) $document['brand_name'],
            'sku'           => (string) $product->get_sku(),
            'stock_status'  => (string) $document['stock_status'],
            'stock_label'   => self::stock_label($document['stock_status']),
            'excerpt'       => wp_trim_words(wp_strip_all_tags((string) $document['excerpt']), 25, '…'),
            'reasons'       => (array) $document['_reasons'],
            'score'         => (float) $document['_score'],
            'search_tier'   => sanitize_key((string) ($document['_search_tier'] ?? 'direct')),
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
            $slug = sanitize_title((string) ($item['slug'] ?? ''));
            $label = trim(wp_strip_all_tags((string) ($item['label'] ?? '')));

            if (!$slug || !$label) {
                continue;
            }

            $visual = self::resolve_card_image($item, $type, $group, $documents);

            $cards[] = array(
                'label'        => $label,
                'slug'         => $slug,
                'count'        => absint($item['count'] ?? 0),
                'image'        => (string) ($visual['url'] ?? ''),
                'image_kind'   => (string) ($visual['kind'] ?? 'product'),
                'image_source' => (string) ($visual['source'] ?? ''),
                'filter'       => array('type' => $type, 'group' => $group, 'slug' => $slug),
            );
        }

        return $cards;
    }

    /**
     * Resuelve la imagen de una tarjeta a partir de las relaciones reales
     * del catalogo. No busca adjuntos por nombre ni asocia imagenes a
     * etiquetas, aplicaciones o atributos.
     *
     * Prioridad:
     * 1) Si la tarjeta ES una categoria, usa la imagen de esa categoria.
     * 2) Para etiquetas/vocabulario/atributos, localiza los productos que
     *    cumplen el concepto y busca una categoria relacionada con imagen.
     * 3) Si no hay categoria visual, usa un producto representativo.
     * 4) Si no existe ninguna imagen relacionada, usa el logo de la empresa.
     */
    private static function resolve_card_image($item, $type, $group, $documents) {
        $slug = sanitize_title((string) ($item['slug'] ?? ''));

        if ('categories' === $type && !empty($item['image'])) {
            return array(
                'url'    => esc_url_raw((string) $item['image']),
                'kind'   => 'category',
                'source' => $slug,
            );
        }

        $matches = self::matching_documents($documents, $type, $group, $slug);

        // Para conceptos que no son categorias, la mejor representacion
        // visual suele ser una categoria real compartida por sus productos.
        if ('categories' !== $type) {
            $category = self::related_category_image($matches);
            if (!empty($category['url'])) {
                return $category;
            }
        }

        $product = self::representative_product_image($matches);
        if (!empty($product['url'])) {
            return $product;
        }

        return array(
            'url'    => self::company_logo_url(),
            'kind'   => 'logo',
            'source' => 'company-logo',
        );
    }

    /**
     * Devuelve exclusivamente los documentos/productos relacionados con
     * la opcion visual actual. Esta funcion es la base de la relacion
     * etiqueta/aplicacion/atributo -> productos -> categorias.
     */
    private static function matching_documents($documents, $type, $group, $slug) {
        $slug = sanitize_title((string) $slug);
        if (!$slug) {
            return array();
        }

        $matches = array();

        foreach ((array) $documents as $document) {
            if (self::document_matches_card($document, $type, $group, $slug)) {
                $matches[] = $document;
            }
        }

        return $matches;
    }

    private static function document_matches_card($document, $type, $group, $slug) {
        if ('categories' === $type) {
            return self::document_has_term_slugs((array) ($document['categories'] ?? array()), array($slug));
        }

        if ('tags' === $type) {
            return self::document_has_term_slugs((array) ($document['tags'] ?? array()), array($slug));
        }

        if ('vocabulary' === $type) {
            $slugs = array_map(
                'sanitize_title',
                wp_list_pluck((array) ($document['vocabulary'][$group] ?? array()), 'slug')
            );
            return in_array($slug, $slugs, true);
        }

        if ('attributes' === $type) {
            $map = self::attributes_slug_map((array) ($document['attributes'] ?? array()));
            $group = sanitize_title((string) $group);
            return !empty($map[$group]) && in_array($slug, $map[$group], true);
        }

        return false;
    }

    /**
     * Entre los productos relacionados, calcula que categoria con imagen
     * representa mejor el concepto. Se favorecen categorias especificas
     * (mas profundas) siempre que tengan una presencia significativa.
     */
    private static function related_category_image($documents) {
        $categories = array();

        foreach ((array) $documents as $document) {
            foreach ((array) ($document['categories'] ?? array()) as $term) {
                $term_id = absint($term['id'] ?? 0);
                $slug = sanitize_title((string) ($term['slug'] ?? ''));
                $image = esc_url_raw((string) ($term['image'] ?? ''));

                if (!$image || (!$term_id && !$slug)) {
                    continue;
                }

                $key = $term_id ? 'id:' . $term_id : 'slug:' . $slug;
                if (!isset($categories[$key])) {
                    $categories[$key] = array(
                        'id'    => $term_id,
                        'slug'  => $slug,
                        'url'   => $image,
                        'count' => 0,
                        'depth' => self::product_category_depth($term_id),
                    );
                }

                $categories[$key]['count']++;
            }
        }

        if (!$categories) {
            return array();
        }

        $max_count = max(array_map(static function ($item) {
            return absint($item['count'] ?? 0);
        }, $categories));

        // Una categoria muy especifica solo se considera representativa si
        // aparece al menos en el 35% del soporte de la categoria dominante.
        $minimum_support = max(1, (int) ceil($max_count * 0.35));
        $candidates = array_values(array_filter($categories, static function ($item) use ($minimum_support) {
            return absint($item['count'] ?? 0) >= $minimum_support;
        }));

        usort($candidates, static function ($a, $b) {
            $depth_compare = absint($b['depth'] ?? 0) <=> absint($a['depth'] ?? 0);
            if (0 !== $depth_compare) {
                return $depth_compare;
            }

            $count_compare = absint($b['count'] ?? 0) <=> absint($a['count'] ?? 0);
            if (0 !== $count_compare) {
                return $count_compare;
            }

            return strnatcasecmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? ''));
        });

        $winner = reset($candidates);
        if (!$winner || empty($winner['url'])) {
            return array();
        }

        return array(
            'url'    => (string) $winner['url'],
            'kind'   => 'category',
            'source' => (string) ($winner['slug'] ?? ''),
        );
    }

    private static function product_category_depth($term_id) {
        static $cache = array();

        $term_id = absint($term_id);
        if (!$term_id) {
            return 0;
        }

        if (isset($cache[$term_id])) {
            return $cache[$term_id];
        }

        $ancestors = get_ancestors($term_id, 'product_cat', 'taxonomy');
        $cache[$term_id] = is_array($ancestors) ? count($ancestors) : 0;

        return $cache[$term_id];
    }

    /**
     * Seleccion estable de un producto representativo. Se priorizan los
     * productos en stock y, a igualdad, se usa el ID para que la imagen no
     * cambie de forma aleatoria entre peticiones.
     */
    private static function representative_product_image($documents) {
        $candidates = array();

        foreach ((array) $documents as $document) {
            $product_id = absint($document['product_id'] ?? 0);
            if (!$product_id) {
                continue;
            }

            $score = 0;
            if ('instock' === (string) ($document['stock_status'] ?? '')) {
                $score += 100;
            } elseif ('onbackorder' === (string) ($document['stock_status'] ?? '')) {
                $score += 40;
            }

            if (isset($document['price']) && '' !== (string) $document['price']) {
                $score += 5;
            }

            if (!empty($document['featured'])) {
                $score += 10;
            }

            $candidates[] = array(
                'id'    => $product_id,
                'score' => $score,
            );
        }

        if (!$candidates) {
            return array();
        }

        usort($candidates, static function ($a, $b) {
            $score_compare = (int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0);
            if (0 !== $score_compare) {
                return $score_compare;
            }
            return absint($a['id'] ?? 0) <=> absint($b['id'] ?? 0);
        });

        // No hacemos una consulta de imagen para todos los productos de una
        // accion. Probamos los mejores candidatos en orden hasta encontrar la
        // primera imagen real (Media o proveedor).
        foreach (array_slice($candidates, 0, 40) as $candidate) {
            $product_id = absint($candidate['id'] ?? 0);
            $image = $product_id ? SEO_Dependiente_Index::product_image_url($product_id) : '';
            if (!$image) {
                continue;
            }

            return array(
                'url'    => esc_url_raw((string) $image),
                'kind'   => 'product',
                'source' => 'product:' . $product_id,
            );
        }

        return array();
    }

    /**
     * Imagen real de producto para resultados y comparador. Se resuelve en
     * tiempo real para que staging pueda utilizar inmediatamente una URL de
     * wp_seo_proveedores_productos aunque el indice aun conserve una imagen
     * antigua. Solo si no existe imagen relacional se usa el logo.
     */
    private static function product_image_or_logo($product_id) {
        $product_id = absint($product_id);
        if ($product_id) {
            $url = SEO_Dependiente_Index::product_image_url($product_id);
            if ($url) {
                return esc_url_raw((string) $url);
            }
        }
        return self::company_logo_url();
    }

    /**
     * Ultimo fallback visual: logo configurado por el tema. Se deja un
     * filtro para poder fijar el logo corporativo sin tocar este modulo.
     */
    private static function company_logo_url() {
        static $logo = null;

        if (null !== $logo) {
            return $logo;
        }

        $logo = '';
        $custom_logo_id = absint(get_theme_mod('custom_logo'));

        if ($custom_logo_id) {
            $custom_logo = wp_get_attachment_image_url($custom_logo_id, 'full');
            if ($custom_logo) {
                $logo = esc_url_raw($custom_logo);
            }
        }

        if (!$logo) {
            $site_icon = get_site_icon_url(512);
            if ($site_icon) {
                $logo = esc_url_raw($site_icon);
            }
        }

        if (!$logo && function_exists('wc_placeholder_img_src')) {
            $logo = esc_url_raw((string) wc_placeholder_img_src('woocommerce_thumbnail'));
        }

        $logo = esc_url_raw((string) apply_filters('seo_dependiente_company_logo_url', $logo));
        return $logo;
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

    /**
     * Convierte el HTML de precio de WooCommerce a texto limpio para el
     * comparador. get_price_html() incluye textos auxiliares para lectores de
     * pantalla; si se eliminan las etiquetas sin retirarlos antes, esos textos
     * se duplican y las entidades como &euro; quedan visibles literalmente.
     */
    private static function comparison_price_text($product) {
        if (!$product || !is_a($product, 'WC_Product')) {
            return '';
        }

        $html = (string) $product->get_price_html();
        if ('' === trim($html)) {
            return '';
        }

        // WooCommerce añade descripciones duplicadas solo para accesibilidad.
        $html = preg_replace(
            '#<span[^>]*class=(["\'])[^"\']*\bscreen-reader-text\b[^"\']*\1[^>]*>.*?</span>#is',
            '',
            $html
        );

        // Al pasar a texto plano, separa claramente precio anterior y actual.
        $html = preg_replace('#</del>\s*<ins\b#i', '</del> → <ins', $html);

        $text = wp_strip_all_tags((string) $html, true);
        $charset = (string) get_bloginfo('charset');
        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            $charset ?: 'UTF-8'
        );

        // Evita espacios no separables y saltos raros dentro de la celda.
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

        return trim((string) $text);
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

    /**
     * Capa de conocimiento del Dependiente.
     *
     * 1. Busca la consulta original directamente en posts y paginas/landings.
     * 2. Completa con guias vinculadas a las categorias de los productos encontrados.
     *
     * De este modo el contenido puede responder aunque el catalogo no tenga un
     * producto que aporte previamente una categoria util.
     */
    private static function merge_related_items($first, $second, $limit = 8) {
        $limit = max(1, absint($limit));
        $items = array();
        $seen = array();
        foreach (array_merge((array) $first, (array) $second) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = sanitize_key((string) ($item['type'] ?? 'post')) . ':' . absint($item['id'] ?? 0);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $item;
            if (count($items) >= $limit) {
                break;
            }
        }
        return $items;
    }

    /**
     * Mezcla carriles sin convertirlos en relaciones entre si. Para una consulta
     * que pide expresamente contenido/guia se prioriza editorial; en el resto se
     * alternan FAQ owner-first y editorial para preservar ambos contextos.
     */
    private static function merge_related_lanes($editorial, $faqs, $query, $limit = 6) {
        $limit = max(1, absint($limit));
        $editorial = array_values((array) $editorial);
        $faqs = array_values((array) $faqs);
        $normalized = SEO_Dependiente_Index::normalize((string) $query);
        $editorial_intent = (bool) preg_match('/\b(contenido|contenidos|guia|guias|articulo|articulos|pagina|paginas|manual|tutorial|documentacion)\b/u', $normalized);

        $out = array();
        $seen = array();
        $push = static function ($item) use (&$out, &$seen, $limit) {
            if (!is_array($item) || count($out) >= $limit) return;
            $key = sanitize_key((string) ($item['type'] ?? '')) . ':' . absint($item['id'] ?? 0);
            if (!$key || isset($seen[$key])) return;
            $seen[$key] = true;
            $out[] = $item;
        };

        if ($editorial_intent) {
            foreach (array_slice($editorial, 0, max(1, min(4, $limit))) as $item) $push($item);
            foreach ($faqs as $item) $push($item);
            foreach ($editorial as $item) $push($item);
            return $out;
        }

        $max = max(count($editorial), count($faqs));
        for ($i = 0; $i < $max && count($out) < $limit; $i++) {
            if (isset($faqs[$i])) $push($faqs[$i]);
            if (isset($editorial[$i])) $push($editorial[$i]);
        }
        return $out;
    }

    /**
     * Ordena y deduplica elementos dentro de un mismo carril de conocimiento.
     * Desde v0.2.12 FAQ y editorial se ordenan por separado y ya no compiten entre si.
     */
    private static function rank_related_items($source_items, $limit = 8, $strip_internal = true) {
        $limit = max(1, absint($limit));
        $seen = array();
        $items = array();
        foreach ((array) $source_items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = sanitize_key((string) ($item['type'] ?? 'post')) . ':' . absint($item['id'] ?? 0);
            if (!$key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $item;
        }
        usort($items, static function ($a, $b) {
            if (($a['_score'] ?? 0) !== ($b['_score'] ?? 0)) {
                return ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
            }
            return ($b['_modified'] ?? 0) <=> ($a['_modified'] ?? 0);
        });
        $items = array_slice($items, 0, $limit);
        if ($strip_internal) {
            foreach ($items as &$item) {
                unset($item['_score'], $item['_modified']);
            }
            unset($item);
        }
        return $items;
    }

    private static function related_content($documents, $query, $limit = 8) {
        $limit = max(1, absint($limit));
        $items = array();
        $seen = array();

        $direct = self::direct_content_search($query, max(12, $limit * 3));
        foreach ($direct as $item) {
            $key = sanitize_key((string) ($item['type'] ?? 'post')) . ':' . absint($item['id'] ?? 0);
            if (!$item || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $item;
            if (count($items) >= $limit) {
                return $items;
            }
        }

        $category = self::category_related_content($documents, $query, max(12, $limit * 3));
        foreach ($category as $item) {
            $key = sanitize_key((string) ($item['type'] ?? 'post')) . ':' . absint($item['id'] ?? 0);
            if (!$item || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $items[] = $item;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Busca directamente la frase del cliente en contenido editorial publicado.
     * WordPress limita primero los candidatos con su buscador nativo y aqui se
     * vuelve a puntuar para priorizar frase completa, titulo y coincidencias utiles.
     */
    /**
     * Busca conocimiento editorial directo. Las FAQs se resuelven aparte mediante
     * sus propietarios, una vez conocidos los candidatos del catálogo.
     */
    private static function direct_content_search($query, $limit = 18, $keep_internal = false, $semantic = array()) {
        $query = trim(sanitize_text_field((string) $query));
        if ('' === $query) {
            return array();
        }

        $limit = max(1, absint($limit));
        $candidate_limit = min(120, max(18, $limit * 5));
        $search = new WP_Query(array(
            'post_type'           => array('post', 'page'),
            'post_status'         => 'publish',
            's'                   => $query,
            'posts_per_page'      => $candidate_limit,
            'orderby'             => 'relevance',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        ));

        $candidate_posts = $search->have_posts() ? (array) $search->posts : array();

        // Primero, candidatos por rutas semanticas exactas ya resueltas por el parser.
        // Esto evita que palabras del envoltorio de la pregunta ("contenido", "web",
        // "relacionado") pesen mas que TIPO/ROL/APLICACION realmente detectados.
        $route_semantic_ids = self::semantic_content_ids_for_routes($semantic, array('post', 'page'), $candidate_limit);
        $semantic_ids = array_values(array_unique(array_merge(
            $route_semantic_ids,
            self::semantic_content_ids_for_query($query, array('post', 'page'), $candidate_limit)
        )));
        $candidate_ids = array();
        foreach ($candidate_posts as $candidate_post) {
            if ($candidate_post instanceof WP_Post) {
                $candidate_ids[(int) $candidate_post->ID] = true;
            }
        }
        foreach ($semantic_ids as $semantic_id) {
            $semantic_id = absint($semantic_id);
            if (!$semantic_id || isset($candidate_ids[$semantic_id])) {
                continue;
            }
            $semantic_post = get_post($semantic_id);
            if ($semantic_post instanceof WP_Post && in_array($semantic_post->post_type, array('post', 'page'), true) && 'publish' === $semantic_post->post_status) {
                $candidate_posts[] = $semantic_post;
                $candidate_ids[$semantic_id] = true;
            }
        }

        $normalized_query = SEO_Dependiente_Index::normalize($query);
        $query_tokens = array_values(array_unique(array_filter(
            preg_split('/\\s+/u', $normalized_query),
            static function ($token) {
                $length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
                return $length >= 3;
            }
        )));

        $structural_ids = array();
        if (function_exists('wc_get_page_id')) {
            foreach (array('shop', 'cart', 'checkout', 'myaccount') as $page_key) {
                $page_id = absint(wc_get_page_id($page_key));
                if ($page_id) {
                    $structural_ids[$page_id] = true;
                }
            }
        }

        $route_targets = self::semantic_route_targets($semantic);
        $route_target_count = count($route_targets);

        $items = array();
        foreach ($candidate_posts as $post) {
            if (!$post instanceof WP_Post || isset($structural_ids[(int) $post->ID])) {
                continue;
            }
            if ('page' === $post->post_type && (
                has_shortcode((string) $post->post_content, 'dependiente') ||
                has_shortcode((string) $post->post_content, 'dependiente_productos')
            )) {
                continue;
            }

            $title = wp_strip_all_tags((string) $post->post_title);
            $excerpt = trim(wp_strip_all_tags((string) $post->post_excerpt));
            $plain_content = trim(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)));
            $summary_text = $excerpt !== '' ? $excerpt : $plain_content;

            $title_norm = SEO_Dependiente_Index::normalize($title);
            $excerpt_norm = SEO_Dependiente_Index::normalize($excerpt);
            $content_norm = SEO_Dependiente_Index::normalize($plain_content);
            $vocabulary_terms = function_exists('seo_content_vocab_get_flat_terms')
                ? seo_content_vocab_get_flat_terms($post->post_type, (int) $post->ID)
                : self::object_vocabulary_terms($post->post_type, (int) $post->ID);
            $vocabulary_parts = array();
            $post_route_keys = array();
            $post_vocabulary_ids = array();
            foreach ($vocabulary_terms as $vocabulary_term) {
                $vocabulary_parts[] = (string) ($vocabulary_term['label'] ?? '');
                $vocabulary_parts[] = str_replace('_', ' ', (string) ($vocabulary_term['slug'] ?? ''));
                $term_id = absint($vocabulary_term['id'] ?? 0);
                if ($term_id) {
                    $post_vocabulary_ids[$term_id] = true;
                }
                $term_group = sanitize_key((string) ($vocabulary_term['semantic_group'] ?? $vocabulary_term['group'] ?? ''));
                $term_slug = SEO_Dependiente_Index::normalize((string) ($vocabulary_term['slug'] ?? ''));
                if ($term_group && $term_slug) {
                    $post_route_keys[$term_group . ':' . $term_slug] = true;
                }
            }
            $vocabulary_norm = SEO_Dependiente_Index::normalize(implode(' ', array_filter($vocabulary_parts)));
            $all_norm = trim($title_norm . ' ' . $excerpt_norm . ' ' . $content_norm . ' ' . $vocabulary_norm);

            $score = 0;
            $hits = 0;
            $exact_route_hits = 0;
            foreach ($route_targets as $target) {
                $target_id = absint($target['id'] ?? 0);
                $target_key = sanitize_key((string) ($target['group'] ?? '')) . ':' . (string) ($target['slug'] ?? '');
                if (($target_id && isset($post_vocabulary_ids[$target_id])) || (!$target_id && isset($post_route_keys[$target_key]))) {
                    $exact_route_hits++;
                }
            }
            if ($exact_route_hits > 0) {
                $score += 480 * $exact_route_hits;
                $hits += 2 * $exact_route_hits;
                if ($route_target_count > 1 && $exact_route_hits === $route_target_count) {
                    $score += 720;
                }
            }
            if ($normalized_query && false !== strpos($title_norm, $normalized_query)) {
                $score += 360;
            } elseif ($normalized_query && false !== strpos($all_norm, $normalized_query)) {
                $score += 240;
            }
            if ($normalized_query && $vocabulary_norm && false !== strpos($vocabulary_norm, $normalized_query)) {
                $score += 210;
                $hits++;
            }
            foreach ($query_tokens as $token) {
                if (false !== strpos($title_norm, $token)) {
                    $score += 72;
                    $hits++;
                } elseif ($vocabulary_norm && false !== strpos($vocabulary_norm, $token)) {
                    $score += 58;
                    $hits++;
                } elseif (false !== strpos($excerpt_norm, $token)) {
                    $score += 42;
                    $hits++;
                } elseif (false !== strpos($content_norm, $token)) {
                    $score += 18;
                    $hits++;
                }
            }
            if (!$score || (!$hits && (!$normalized_query || false === strpos($all_norm, $normalized_query)))) {
                continue;
            }

            $url = get_permalink((int) $post->ID);
            if (!$url) {
                continue;
            }
            $image = get_the_post_thumbnail_url((int) $post->ID, 'medium_large');
            $items[] = array(
                'id'         => (int) $post->ID,
                'type'       => 'page' === $post->post_type ? 'landing' : 'post',
                'type_label' => 'page' === $post->post_type ? 'Pagina' : 'Guia',
                'title'      => $title,
                'excerpt'    => wp_trim_words($summary_text, 24, '…'),
                'url'        => esc_url_raw($url),
                'image'      => $image ? esc_url_raw($image) : '',
                '_score'     => $score,
                '_modified'  => strtotime((string) $post->post_modified) ?: 0,
            );
        }

        return self::rank_related_items($items, $limit, !$keep_internal);
    }

    /**
     * Rutas Vocabulary exactas detectadas por el parser: grupo:slug.
     * Se deduplican porque una misma ruta puede aparecer mas de una vez al
     * combinar reglas canonicas y reglas temporales de Academia.
     */
    private static function semantic_route_targets($semantic) {
        $targets = array();
        foreach ((array) ($semantic['routes'] ?? array()) as $route) {
            $group = sanitize_key((string) ($route['target_group'] ?? ''));
            $slug = SEO_Dependiente_Index::normalize((string) ($route['target_slug'] ?? ''));
            $id = absint($route['target_vocabulary_id'] ?? 0);
            if (!in_array($group, array('rol','tipo','aplicacion','plataforma','subtipo'), true) || (!$id && !$slug)) {
                continue;
            }
            $key = $id ? 'id:' . $id : $group . ':' . $slug;
            $targets[$key] = array('id'=>$id,'group'=>$group,'slug'=>$slug);
        }
        return array_values($targets);
    }

    /**
     * Recupera contenido editorial por coincidencia exacta con las rutas
     * Vocabulary ya interpretadas. Prioriza target_vocabulary_id canonico para
     * no depender de si el slug usa espacios, guiones o guiones bajos.
     */
    private static function semantic_content_ids_for_routes($semantic, $object_types, $limit = 80) {
        global $wpdb;
        $targets = self::semantic_route_targets($semantic);
        $object_types = array_values(array_intersect(array('post', 'page'), array_map('sanitize_key', (array) $object_types)));
        if (!$targets || !$object_types) {
            return array();
        }
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Dependiente_Index::table_exists($objects) || !SEO_Dependiente_Index::table_exists($vocabulary)) {
            return array();
        }

        $ids = array();
        $fallback_targets = array();
        foreach ($targets as $target) {
            $id = absint($target['id'] ?? 0);
            if ($id) {
                $ids[$id] = true;
            } else {
                $fallback_targets[] = $target;
            }
        }

        $conditions = array();
        $params = array();
        if ($ids) {
            $conditions[] = 'v.id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $params = array_merge($params, array_keys($ids));
        }
        foreach ($fallback_targets as $target) {
            $group = sanitize_key((string) ($target['group'] ?? ''));
            $slug = SEO_Dependiente_Index::normalize((string) ($target['slug'] ?? ''));
            if (!$group || !$slug) continue;
            $dash_slug = sanitize_title($slug);
            $underscore_slug = str_replace('-', '_', $dash_slug);
            $conditions[] = "(v.semantic_group=%s AND (v.slug=%s OR v.slug=%s OR REPLACE(REPLACE(LOWER(v.slug), '_', ' '), '-', ' ')=%s OR LOWER(v.label)=%s))";
            array_push($params, $group, $dash_slug, $underscore_slug, $slug, $slug);
        }
        if (!$conditions) {
            return array();
        }

        $type_sql = "'" . implode("','", array_map('esc_sql', $object_types)) . "'";
        $limit = min(250, max(1, absint($limit)));
        $sql = "SELECT ov.object_id, COUNT(DISTINCT v.id) exact_hits
                FROM {$objects} ov
                INNER JOIN {$vocabulary} v ON v.id=ov.vocabulary_id AND v.active=1
                INNER JOIN {$wpdb->posts} p ON p.ID=ov.object_id AND p.post_status='publish'
                WHERE ov.object_type IN ({$type_sql})
                  AND p.post_type=ov.object_type
                  AND ov.status=1
                  AND (" . implode(' OR ', $conditions) . ")
                GROUP BY ov.object_id
                ORDER BY exact_hits DESC, ov.object_id DESC
                LIMIT {$limit}";
        return array_values(array_filter(array_map('absint', (array) $wpdb->get_col($wpdb->prepare($sql, $params)))));
    }

    /**
     * Devuelve IDs de posts/paginas cuya clasificacion canonica coincide con la
     * consulta. Evita depender del helper historico que solo contemplaba posts.
     */
    private static function semantic_content_ids_for_query($query, $object_types, $limit = 80) {
        global $wpdb;
        $query = SEO_Dependiente_Index::normalize((string) $query);
        $tokens = array_values(array_unique(array_filter(preg_split('/\\s+/u', $query), static function ($token) {
            return strlen((string) $token) >= 3;
        })));
        $tokens = array_slice($tokens, 0, 8);
        $object_types = array_values(array_intersect(array('post', 'page'), array_map('sanitize_key', (array) $object_types)));
        if (!$tokens || !$object_types) {
            return array();
        }
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Dependiente_Index::table_exists($objects) || !SEO_Dependiente_Index::table_exists($vocabulary)) {
            return array();
        }
        $conditions = array();
        $params = array();
        foreach ($tokens as $token) {
            $like = '%' . $wpdb->esc_like($token) . '%';
            $conditions[] = '(LOWER(v.label) LIKE %s OR LOWER(v.slug) LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        $type_sql = "'" . implode("','", array_map('esc_sql', $object_types)) . "'";
        $limit = min(250, max(1, absint($limit)));
        $sql = "SELECT ov.object_id, COUNT(DISTINCT v.id) semantic_hits
                FROM {$objects} ov
                INNER JOIN {$vocabulary} v ON v.id=ov.vocabulary_id AND v.active=1
                INNER JOIN {$wpdb->posts} p ON p.ID=ov.object_id AND p.post_status='publish'
                WHERE ov.object_type IN ({$type_sql})
                  AND p.post_type=ov.object_type
                  AND ov.status=1
                  AND (" . implode(' OR ', $conditions) . ")
                GROUP BY ov.object_id
                ORDER BY semantic_hits DESC, ov.object_id DESC
                LIMIT {$limit}";
        return array_values(array_filter(array_map('absint', (array) $wpdb->get_col($wpdb->prepare($sql, $params)))));
    }

    /**
     * Resolve a canonical FAQ owner before FAQ retrieval.
     *
     * Important: FAQ relations are NEVER resolved by title. Titles/labels are only
     * a natural-language bridge used to identify the canonical object ID when the
     * caller did not already provide one. From that point onward the authoritative
     * key is object_type + object_id.
     */
    private static function resolve_explicit_faq_owner($query, $params = array()) {
        global $wpdb;

        $params = is_array($params) ? $params : array();
        $context = isset($params['owner_context']) && is_array($params['owner_context'])
            ? $params['owner_context']
            : array();
        $context_type = $context['object_type'] ?? ($params['context_object_type'] ?? ($params['owner_type'] ?? ''));
        $context_id = absint($context['object_id'] ?? ($params['context_object_id'] ?? ($params['owner_id'] ?? 0)));
        $validated = self::validate_faq_owner($context_type, $context_id);
        if ($validated) {
            $validated['source'] = 'request_context';
            $validated['label'] = '';
            return $validated;
        }

        $query = trim((string) $query);
        if ('' === $query) {
            return array();
        }
        $plain = function_exists('remove_accents') ? remove_accents($query) : $query;
        $plain = strtolower((string) $plain);
        $hint_type = 0;
        if (preg_match('/\bproducto\b/u', $plain)) {
            $hint_type = 3;
        } elseif (preg_match('/\bcategoria\b/u', $plain)) {
            $hint_type = 2;
        }
        if (!$hint_type) {
            return array();
        }

        $label = '';
        if (preg_match('/["\x{00AB}\x{201C}](.{2,700})["\x{00BB}\x{201D}]\s*:\s*/us', $query, $match)) {
            $label = trim(wp_strip_all_tags((string) ($match[1] ?? '')));
        }
        if ('' === $label) {
            return array();
        }

        if (3 === $hint_type) {
            $normalized = SEO_Dependiente_Index::normalize($label);
            if ($normalized && SEO_Dependiente_Index::table_exists()) {
                $ids = array_values(array_unique(array_filter(array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
                    'SELECT product_id FROM `' . esc_sql(SEO_Dependiente_Index::table()) . '` WHERE normalized_title=%s LIMIT 2',
                    $normalized
                ))))));
                if (1 === count($ids)) {
                    $owner = self::validate_faq_owner(3, $ids[0]);
                    if ($owner) {
                        $owner['source'] = 'explicit_query';
                        $owner['label'] = $label;
                        return $owner;
                    }
                }
            }

            // Exact canonical WordPress title as a safe fallback if the Dependiente
            // index is stale. It only resolves the product ID; FAQ lookup still uses ID.
            $ids = array_values(array_unique(array_filter(array_map('absint', (array) $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND post_title=%s ORDER BY ID ASC LIMIT 2",
                $label
            ))))));
            if (1 === count($ids)) {
                $owner = self::validate_faq_owner(3, $ids[0]);
                if ($owner) {
                    $owner['source'] = 'explicit_query';
                    $owner['label'] = $label;
                    return $owner;
                }
            }
            return array();
        }

        $term = get_term_by('slug', sanitize_title($label), 'product_cat');
        if (!$term || is_wp_error($term)) {
            $term = get_term_by('name', $label, 'product_cat');
        }
        if ($term && !is_wp_error($term)) {
            $owner = self::validate_faq_owner(2, absint($term->term_id));
            if ($owner) {
                $owner['source'] = 'explicit_query';
                $owner['label'] = $label;
                return $owner;
            }
        }
        return array();
    }

    private static function validate_faq_owner($object_type, $object_id) {
        $object_id = absint($object_id);
        if (!$object_id) {
            return array();
        }
        if (is_string($object_type)) {
            $type = sanitize_key($object_type);
            if (in_array($type, array('product','producto'), true)) {
                $object_type = 3;
            } elseif (in_array($type, array('product_cat','category','categoria'), true)) {
                $object_type = 2;
            }
        }
        $object_type = absint($object_type);
        if (3 === $object_type) {
            $post = get_post($object_id);
            if (!$post instanceof WP_Post || 'product' !== $post->post_type || 'publish' !== $post->post_status) {
                return array();
            }
            return array('object_type'=>3, 'object_id'=>$object_id, 'type'=>'product');
        }
        if (2 === $object_type) {
            $term = get_term($object_id, 'product_cat');
            if (!$term || is_wp_error($term)) {
                return array();
            }
            return array('object_type'=>2, 'object_id'=>$object_id, 'type'=>'product_cat');
        }
        return array();
    }

    /** Remove the explicit owner prefix so FAQ ranking uses the actual question. */
    private static function faq_query_without_owner_context($query) {
        $query = trim((string) $query);
        if (preg_match('/["\x{00AB}\x{201C}].{2,700}["\x{00BB}\x{201D}]\s*:\s*(.+)$/us', $query, $match)) {
            $tail = trim((string) ($match[1] ?? ''));
            if ('' !== $tail) {
                return $tail;
            }
        }
        return $query;
    }

    private static function object_vocabulary_terms($object_type, $object_id) {
        global $wpdb;
        $object_type = sanitize_key((string) $object_type);
        $object_id = absint($object_id);
        if (!$object_id) {
            return array();
        }
        $objects = $wpdb->prefix . 'seo_object_vocabulary';
        $vocabulary = $wpdb->prefix . 'seo_vocabulary';
        if (!SEO_Dependiente_Index::table_exists($objects) || !SEO_Dependiente_Index::table_exists($vocabulary)) {
            return array();
        }
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT v.id,v.semantic_group,v.slug,v.label
             FROM {$objects} ov INNER JOIN {$vocabulary} v ON v.id=ov.vocabulary_id AND v.active=1
             WHERE ov.object_type=%s AND ov.object_id=%d AND ov.status=1
             ORDER BY v.semantic_group,v.label",
            $object_type, $object_id
        ), ARRAY_A);
    }

    /**
     * Busca FAQs siguiendo primero el enlace estructural del propietario.
     *
     * Ruta principal:
     *   consulta -> productos/categorías candidatos -> object_type/object_id -> FAQs
     *
     * La ruta canonica es exclusivamente owner-first. Si el catalogo no identifica
     * un producto/categoria propietario, no se inventa una relacion FAQ por texto.
     * Un integrador puede reactivar el fallback de forma explicita mediante filtro,
     * pero Dependiente lo mantiene desactivado por defecto.
     */
    private static function faq_content_search($query, $limit = 30, $documents = array(), $keep_internal = false, $resolved_owner = array()) {
        global $wpdb;
        $faq_table = $wpdb->prefix . 'seo_faq';
        if (!SEO_Dependiente_Index::table_exists($faq_table)) {
            return array();
        }

        $validated_owner = self::validate_faq_owner($resolved_owner['object_type'] ?? 0, $resolved_owner['object_id'] ?? 0);
        $resolved_owner = $validated_owner ? array_merge($resolved_owner, $validated_owner) : array();
        $faq_query = $resolved_owner ? self::faq_query_without_owner_context($query) : (string) $query;
        $normalized_query = SEO_Dependiente_Index::normalize($faq_query);
        $tokens = array_values(array_unique(array_filter(preg_split('/\s+/u', $normalized_query), static function ($token) {
            $length = function_exists('mb_strlen') ? mb_strlen((string) $token, 'UTF-8') : strlen((string) $token);
            return $length >= 3;
        })));
        $tokens = array_slice($tokens, 0, $resolved_owner ? 10 : 6);
        if (!$tokens) {
            return array();
        }

        $limit = max(1, absint($limit));
        $candidate_rows = array();
        $owner_scores = array();
        $product_ids = array();
        $category_scores = array();

        // If the question resolves to a canonical owner, lock FAQ retrieval to
        // that object_type/object_id. No other product/category can enter this lane.
        if ($resolved_owner) {
            $resolved_type = absint($resolved_owner['object_type'] ?? 0);
            $resolved_id = absint($resolved_owner['object_id'] ?? 0);
            if (3 === $resolved_type && $resolved_id) {
                $product_ids[$resolved_id] = true;
                $owner_scores['3:' . $resolved_id] = 1000;
            } elseif (2 === $resolved_type && $resolved_id) {
                $category_scores[$resolved_id] = 1000;
                $owner_scores['2:' . $resolved_id] = 1000;
            }
        } else {
            // Without an explicit owner, derive owners from ranked catalog documents.
            foreach (array_slice((array) $documents, 0, 24) as $rank => $document) {
                if (!is_array($document)) {
                    continue;
                }
                $product_id = absint($document['product_id'] ?? $document['id'] ?? 0);
                if ($product_id && !isset($product_ids[$product_id])) {
                    $product_ids[$product_id] = true;
                    $owner_scores['3:' . $product_id] = max(40, 190 - ((int) $rank * 6));
                }
                $rank_weight = max(4, 30 - (int) $rank);
                foreach ((array) ($document['categories'] ?? array()) as $category) {
                    $category_id = absint($category['id'] ?? 0);
                    if (!$category_id) {
                        continue;
                    }
                    if (!isset($category_scores[$category_id])) {
                        $category_scores[$category_id] = 0;
                    }
                    $category_scores[$category_id] += $rank_weight;
                }
            }
        }

        if ($category_scores) {
            arsort($category_scores, SORT_NUMERIC);
            $category_scores = array_slice($category_scores, 0, 12, true);
            foreach ($category_scores as $category_id => $score) {
                $key = '2:' . absint($category_id);
                if (!isset($owner_scores[$key])) {
                    $owner_scores[$key] = min(155, 55 + (int) $score);
                }
            }
        }

        $product_ids = array_slice(array_keys($product_ids), 0, 16);
        $category_ids = array_map('absint', array_keys($category_scores));
        $owner_conditions = array();
        $owner_params = array();
        if ($product_ids) {
            $owner_conditions[] = '(object_type=3 AND object_id IN (' . implode(',', array_fill(0, count($product_ids), '%d')) . '))';
            $owner_params = array_merge($owner_params, $product_ids);
        }
        if ($category_ids) {
            $owner_conditions[] = '(object_type=2 AND object_id IN (' . implode(',', array_fill(0, count($category_ids), '%d')) . '))';
            $owner_params = array_merge($owner_params, $category_ids);
        }

        if ($owner_conditions) {
            $owner_limit = min(180, max(30, $limit * 8));
            $owner_sql = "SELECT id,object_type,object_id,ambito,question,answer,updated_at
                          FROM {$faq_table}
                          WHERE active=1 AND (" . implode(' OR ', $owner_conditions) . ")
                          ORDER BY sort_order ASC,id ASC
                          LIMIT {$owner_limit}";
            foreach ((array) $wpdb->get_results($wpdb->prepare($owner_sql, $owner_params), ARRAY_A) as $row) {
                $faq_id = absint($row['id'] ?? 0);
                if (!$faq_id) {
                    continue;
                }
                $owner_key = absint($row['object_type'] ?? 0) . ':' . absint($row['object_id'] ?? 0);
                $row['_faq_route'] = 'owner';
                $row['_owner_score'] = (int) ($owner_scores[$owner_key] ?? 40);
                $candidate_rows[$faq_id] = $row;
            }
        }

        // Fallback textual solo por opt-in. La verdad canonica de FAQ es
        // object_type/object_id (producto o categoria), nunca similitud con posts,
        // paginas, etiquetas u otros contenidos.
        $allow_text_fallback = (bool) apply_filters(
            'seo_dependiente_faq_allow_text_fallback',
            false,
            $query,
            $documents
        );
        if (!$candidate_rows && !$resolved_owner && $allow_text_fallback) {
            $conditions = array();
            $params = array();
            foreach ($tokens as $token) {
                $like = '%' . $wpdb->esc_like($token) . '%';
                $conditions[] = '(LOWER(question) LIKE %s OR LOWER(COALESCE(ambito,\'\')) LIKE %s)';
                $params[] = $like;
                $params[] = $like;
            }
            $text_limit = min(100, max(24, $limit * 4));
            $sql = "SELECT id,object_type,object_id,ambito,question,answer,updated_at
                    FROM {$faq_table}
                    WHERE active=1 AND object_type IN (2,3) AND (" . implode(' OR ', $conditions) . ")
                    ORDER BY updated_at DESC,id DESC LIMIT {$text_limit}";
            foreach ((array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) as $row) {
                $faq_id = absint($row['id'] ?? 0);
                if (!$faq_id) {
                    continue;
                }
                $row['_faq_route'] = 'text_fallback';
                $row['_owner_score'] = 0;
                $candidate_rows[$faq_id] = $row;
            }
        }

        if (!$candidate_rows) {
            return array();
        }

        $items = array();
        $owner_context_cache = array();
        foreach ($candidate_rows as $row) {
            $faq_id = absint($row['id'] ?? 0);
            $object_type = absint($row['object_type'] ?? 0);
            $object_id = absint($row['object_id'] ?? 0);
            if (!$faq_id || !$object_id || !in_array($object_type, array(2,3), true)) {
                continue;
            }

            $owner_cache_key = $object_type . ':' . $object_id;
            if (!array_key_exists($owner_cache_key, $owner_context_cache)) {
                $owner_context_cache[$owner_cache_key] = self::faq_owner_context($object_type, $object_id);
            }
            $owner = $owner_context_cache[$owner_cache_key];
            if (!$owner) {
                continue;
            }

            $question = wp_strip_all_tags((string) ($row['question'] ?? ''));
            $answer = trim(wp_strip_all_tags((string) ($row['answer'] ?? '')));
            $ambito = trim(wp_strip_all_tags((string) ($row['ambito'] ?? '')));
            $question_norm = SEO_Dependiente_Index::normalize($question);
            $answer_norm = SEO_Dependiente_Index::normalize($answer);
            $ambito_norm = SEO_Dependiente_Index::normalize($ambito);
            $owner_norm = SEO_Dependiente_Index::normalize(($owner['title'] ?? '') . ' ' . implode(' ', (array) ($owner['vocabulary'] ?? array())));
            $all_norm = trim($question_norm . ' ' . $ambito_norm . ' ' . $answer_norm . ' ' . $owner_norm);
            $route = sanitize_key((string) ($row['_faq_route'] ?? 'owner'));
            $score = max(0, (int) ($row['_owner_score'] ?? 0));
            $hits = 0;

            if ('owner' === $route) {
                // Premio fijo por haber llegado a la FAQ mediante su propietario.
                $score += 145;
            }
            if ($normalized_query && false !== strpos($question_norm, $normalized_query)) {
                $score += 320;
                $hits++;
            } elseif ($normalized_query && false !== strpos($all_norm, $normalized_query)) {
                $score += 180;
                $hits++;
            }
            foreach ($tokens as $token) {
                if (false !== strpos($question_norm, $token)) {
                    $score += 74;
                    $hits++;
                } elseif ($ambito_norm && false !== strpos($ambito_norm, $token)) {
                    $score += 52;
                    $hits++;
                } elseif (false !== strpos($owner_norm, $token)) {
                    $score += 46;
                    $hits++;
                } elseif (false !== strpos($answer_norm, $token)) {
                    $score += 18;
                    $hits++;
                }
            }

            // En owner-first el vínculo estructural ya es señal suficiente para
            // mostrar la FAQ dentro del pequeño conjunto del propietario. En el
            // fallback textual sí exigimos coincidencia real con la consulta.
            if ('owner' !== $route && (!$score || 0 === $hits)) {
                continue;
            }

            $items[] = array(
                'id'          => $faq_id,
                'type'        => 'faq',
                'type_label'  => 'FAQ',
                'title'       => $question,
                'excerpt'     => wp_trim_words($answer, 28, '…'),
                'url'         => esc_url_raw((string) ($owner['url'] ?? '')),
                'image'       => '',
                'owner_type'  => (string) ($owner['type'] ?? ''),
                'owner_id'    => $object_id,
                'owner_title' => (string) ($owner['title'] ?? ''),
                'faq_route'   => $route,
                'ambito'      => $ambito,
                '_score'      => $score,
                '_modified'   => strtotime((string) ($row['updated_at'] ?? '')) ?: 0,
            );
        }

        return self::rank_related_items($items, $limit, !$keep_internal);
    }

    private static function faq_owner_context($object_type, $object_id) {
        $object_type = absint($object_type);
        $object_id = absint($object_id);
        if (1 === $object_type) {
            $post = get_post($object_id);
            if (!$post instanceof WP_Post || 'page' !== $post->post_type || 'publish' !== $post->post_status) {
                return array();
            }
            $terms = function_exists('seo_content_vocab_get_flat_terms') ? seo_content_vocab_get_flat_terms('page', $object_id) : self::object_vocabulary_terms('page', $object_id);
            return array('type'=>'page','title'=>get_the_title($object_id),'url'=>get_permalink($object_id),'vocabulary'=>wp_list_pluck($terms,'label'));
        }
        if (2 === $object_type) {
            $term = get_term($object_id, 'product_cat');
            if (!$term || is_wp_error($term)) {
                return array();
            }
            $url = get_term_link($term, 'product_cat');
            $terms = self::object_vocabulary_terms('product_cat', $object_id);
            return array('type'=>'product_cat','title'=>(string)$term->name,'url'=>is_wp_error($url)?'':$url,'vocabulary'=>wp_list_pluck($terms,'label'));
        }
        if (3 === $object_type) {
            $post = get_post($object_id);
            if (!$post instanceof WP_Post || 'product' !== $post->post_type || 'publish' !== $post->post_status) {
                return array();
            }
            $terms = self::object_vocabulary_terms('product', $object_id);
            return array('type'=>'product','title'=>get_the_title($object_id),'url'=>get_permalink($object_id),'vocabulary'=>wp_list_pluck($terms,'label'));
        }
        return array();
    }

    private static function category_related_content($documents, $query, $limit = 8) {
        global $wpdb;

        $relations = $wpdb->prefix . 'seo_relations';
        if (!$documents || !SEO_Dependiente_Index::table_exists($relations)) {
            return array();
        }

        $category_weights = array();
        foreach (array_slice((array) $documents, 0, 80) as $rank => $document) {
            $rank_weight = max(1, 80 - (int) $rank);
            foreach ((array) ($document['categories'] ?? array()) as $category) {
                $category_id = absint($category['id'] ?? 0);
                if (!$category_id) {
                    continue;
                }
                if (!isset($category_weights[$category_id])) {
                    $category_weights[$category_id] = 0;
                }
                $category_weights[$category_id] += $rank_weight;
            }
        }
        if (!$category_weights) {
            return array();
        }

        arsort($category_weights, SORT_NUMERIC);
        $category_ids = array_slice(array_keys($category_weights), 0, 12);
        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $sql = $wpdb->prepare(
            "SELECT r.source_type, r.source_id, r.target_id,
                    p.post_type, p.post_title, p.post_excerpt, p.post_content, p.post_modified
             FROM {$relations} r
             INNER JOIN {$wpdb->posts} p ON p.ID = r.source_id
             WHERE r.target_type = 'product_cat'
               AND r.target_id IN ({$placeholders})
               AND p.post_status = 'publish'
               AND (
                    (r.source_type = 'post' AND r.relation_type = 'post_to_category' AND p.post_type = 'post')
                    OR
                    (r.source_type = 'landing' AND r.relation_type = 'landing_to_category' AND p.post_type = 'page')
               )
             ORDER BY p.post_modified DESC
             LIMIT 400",
            $category_ids
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!$rows) {
            return array();
        }

        $grouped = array();
        foreach ($rows as $row) {
            $source_id = absint($row['source_id'] ?? 0);
            $source_type = sanitize_key((string) ($row['source_type'] ?? ''));
            if (!$source_id || !in_array($source_type, array('post', 'landing'), true)) {
                continue;
            }
            $key = $source_type . ':' . $source_id;
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    'id'          => $source_id,
                    'source_type' => $source_type,
                    'title'       => (string) ($row['post_title'] ?? ''),
                    'excerpt'     => (string) ($row['post_excerpt'] ?? ''),
                    'content'     => (string) ($row['post_content'] ?? ''),
                    'modified'    => (string) ($row['post_modified'] ?? ''),
                    'categories'  => array(),
                );
            }
            $target_id = absint($row['target_id'] ?? 0);
            if ($target_id) {
                $grouped[$key]['categories'][$target_id] = true;
            }
        }

        $normalized_query = SEO_Dependiente_Index::normalize((string) $query);
        $query_tokens = array_values(array_filter(preg_split('/\s+/u', $normalized_query), static function ($token) {
            return function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') >= 3 : strlen($token) >= 3;
        }));

        $items = array();
        foreach ($grouped as $item) {
            $category_score = 0;
            foreach (array_keys($item['categories']) as $category_id) {
                $category_score += (int) ($category_weights[$category_id] ?? 0);
            }

            $plain_text = trim((string) $item['excerpt']);
            if ($plain_text === '') {
                $plain_text = wp_strip_all_tags(strip_shortcodes((string) $item['content']));
            }
            $semantic_text = '';
            if (in_array($item['source_type'], array('post','landing'), true) && function_exists('seo_content_vocab_get_flat_terms')) {
                $semantic_parts = array();
                $semantic_object_type = 'landing' === $item['source_type'] ? 'page' : 'post';
                foreach (seo_content_vocab_get_flat_terms($semantic_object_type, (int) $item['id']) as $semantic_term) {
                    $semantic_parts[] = (string) ($semantic_term['label'] ?? '');
                    $semantic_parts[] = str_replace('_', ' ', (string) ($semantic_term['slug'] ?? ''));
                }
                $semantic_text = implode(' ', array_filter($semantic_parts));
            }
            $haystack = SEO_Dependiente_Index::normalize($item['title'] . ' ' . $plain_text . ' ' . $semantic_text);
            $score = $category_score;
            $phrase_match = false;
            $query_hits = 0;
            if ($normalized_query && false !== strpos($haystack, $normalized_query)) {
                $score += 220;
                $phrase_match = true;
            }
            foreach ($query_tokens as $token) {
                if (false !== strpos($haystack, $token)) {
                    $score += 28;
                    $query_hits++;
                }
            }

            // No rellenar el lateral con contenido remotamente relacionado.
            // Si el texto no comparte la consulta, exigimos que la relacion de
            // categoria proceda de productos situados suficientemente arriba.
            if (!$phrase_match && 0 === $query_hits && $category_score < 40) {
                continue;
            }

            $url = get_permalink((int) $item['id']);
            if (!$url) {
                continue;
            }
            $image = get_the_post_thumbnail_url((int) $item['id'], 'medium_large');
            $items[] = array(
                'id'         => (int) $item['id'],
                'type'       => $item['source_type'],
                'type_label' => 'landing' === $item['source_type'] ? 'Solución' : 'Guía',
                'title'      => wp_strip_all_tags((string) $item['title']),
                'excerpt'    => wp_trim_words($plain_text, 24, '…'),
                'url'        => esc_url_raw($url),
                'image'      => $image ? esc_url_raw($image) : '',
                '_score'     => $score,
                '_modified'  => strtotime((string) $item['modified']) ?: 0,
            );
        }

        usort($items, static function ($a, $b) {
            if ($a['_score'] !== $b['_score']) {
                return $b['_score'] <=> $a['_score'];
            }
            return $b['_modified'] <=> $a['_modified'];
        });

        $items = array_slice($items, 0, max(1, absint($limit)));
        foreach ($items as &$item) {
            unset($item['_score'], $item['_modified']);
        }
        unset($item);
        return $items;
    }

    private static function lower_first($text) {
        if (function_exists('mb_strtolower') && function_exists('mb_substr')) {
            return mb_strtolower(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
        }
        return strtolower(substr($text, 0, 1)) . substr($text, 1);
    }
}
