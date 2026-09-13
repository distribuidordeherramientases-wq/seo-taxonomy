<?php
/**
 * Receta Amazon Creators API para el importador de proveedores de SEO System.
 *
 * Fase exploratoria: no importa en base de datos. Se registra como receta nativa
 * dentro de seo_proveedores_import_recipes para aparecer junto a SATKIT y VEVOR.
 * Su objetivo es probar conexion, seleccionar oportunidades desde "Que potenciar"
 * y previsualizar categorias/Browse Nodes y productos Amazon antes de crear tablas
 * virtuales.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 0.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SEO_AMAZON_RECIPE_VERSION' ) ) {
    define( 'SEO_AMAZON_RECIPE_VERSION', '0.4.0' );
}

if ( ! function_exists( 'seo_supplier_recipe_amazon_definition' ) ) {
    function seo_supplier_recipe_amazon_definition() {
        return [
            'id'          => 'amazon',
            'label'       => 'Amazon Espana - exploracion virtual',
            'provider'    => 'Amazon',
            'version'     => SEO_AMAZON_RECIPE_VERSION,
            'mode'        => 'remote_preview',
            'description' => 'Receta Amazon Creators API. No importa todavia: permite conectar, seleccionar oportunidades de Que potenciar y previsualizar categorias y productos antes de disenar el catalogo virtual.',
            'remote_source' => 'amazon_creators_api',
            'destination' => 'none',
            'creates'     => [],
            'preview_callback' => 'seo_supplier_recipe_amazon_render_explorer',
            'settings_callback' => 'seo_supplier_recipe_amazon_render_settings',
            'capabilities' => [
                'oauth_token',
                'search_items',
                'browse_nodes_preview',
                'growth_candidates',
            ],
        ];
    }
}

add_filter(
    'seo_proveedores_import_recipes',
    static function ( $recipes ) {
        if ( ! is_array( $recipes ) ) {
            $recipes = [];
        }
        $recipes['amazon'] = seo_supplier_recipe_amazon_definition();
        return $recipes;
    }
);

function seo_supplier_recipe_amazon_option_key() {
    return 'seo_amazon_creators_recipe_settings';
}

function seo_supplier_recipe_amazon_settings() {
    $saved = get_option( seo_supplier_recipe_amazon_option_key(), [] );
    $defaults = [
        'client_id'          => '',
        'client_secret'      => '',
        'credential_version' => '3.2',
        'partner_tag'        => '',
        'marketplace'        => 'www.amazon.es',
        'language'           => 'es_ES',
        'search_index'       => 'All',
        'item_count'         => 10,
    ];
    $settings = wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );

    if ( defined( 'SEO_AMAZON_CLIENT_ID' ) && SEO_AMAZON_CLIENT_ID ) {
        $settings['client_id'] = SEO_AMAZON_CLIENT_ID;
    }
    if ( defined( 'SEO_AMAZON_CLIENT_SECRET' ) && SEO_AMAZON_CLIENT_SECRET ) {
        $settings['client_secret'] = SEO_AMAZON_CLIENT_SECRET;
    }
    if ( defined( 'SEO_AMAZON_CREDENTIAL_VERSION' ) && SEO_AMAZON_CREDENTIAL_VERSION ) {
        $settings['credential_version'] = SEO_AMAZON_CREDENTIAL_VERSION;
    }
    if ( defined( 'SEO_AMAZON_PARTNER_TAG' ) && SEO_AMAZON_PARTNER_TAG ) {
        $settings['partner_tag'] = SEO_AMAZON_PARTNER_TAG;
    }

    return $settings;
}

/**
 * Estado basico de Amazon Afiliados.
 *
 * Para crear enlaces de busqueda afiliados NO hace falta Creators API: basta
 * con el Partner Tag. Esta es la capacidad que reutilizan las plantillas DHT y
 * el Dependiente cuando la cuenta aun no cumple los requisitos de Creators.
 */
function seo_supplier_recipe_amazon_affiliate_ready() {
    $s = seo_supplier_recipe_amazon_settings();
    return '' !== trim( (string) ( $s['partner_tag'] ?? '' ) );
}

/**
 * Creators API es una mejora opcional sobre la conexion de Afiliados.
 */
function seo_supplier_recipe_amazon_creators_ready() {
    $s = seo_supplier_recipe_amazon_settings();
    return '' !== trim( (string) ( $s['partner_tag'] ?? '' ) )
        && '' !== trim( (string) ( $s['client_id'] ?? '' ) )
        && '' !== trim( (string) ( $s['client_secret'] ?? '' ) );
}

/**
 * Genera una busqueda amazon.es con el Partner Tag, sin consultar ninguna API.
 */
function seo_supplier_recipe_amazon_affiliate_search_url( $query ) {
    $s = seo_supplier_recipe_amazon_settings();
    $query = trim( wp_strip_all_tags( (string) $query ) );
    $tag = sanitize_text_field( (string) ( $s['partner_tag'] ?? '' ) );

    if ( '' === $query || '' === $tag ) {
        return '';
    }

    $marketplace = trim( (string) ( $s['marketplace'] ?? 'www.amazon.es' ) );
    $marketplace = preg_replace( '#^https?://#i', '', $marketplace );
    $marketplace = trim( (string) $marketplace, "/ \t\n\r\0\x0B" );
    if ( '' === $marketplace ) {
        $marketplace = 'www.amazon.es';
    }

    return add_query_arg(
        [
            'k'   => $query,
            'tag' => $tag,
        ],
        'https://' . $marketplace . '/s'
    );
}

function seo_supplier_recipe_amazon_token_endpoint( $version ) {
    // Amazon Creators API credential version 3.2 uses the EU Login with Amazon endpoint.
    return 'https://api.amazon.co.uk/auth/o2/token';
}

function seo_supplier_recipe_amazon_token_cache_key( $settings ) {
    return 'seo_amz_token_' . md5(
        (string) ( $settings['client_id'] ?? '' ) . '|' .
        (string) ( $settings['credential_version'] ?? '' ) . '|' .
        (string) ( $settings['partner_tag'] ?? '' )
    );
}

function seo_supplier_recipe_amazon_get_access_token( $force = false ) {
    $s = seo_supplier_recipe_amazon_settings();

    if ( ! seo_supplier_recipe_amazon_creators_ready() ) {
        return new WP_Error( 'amazon_credentials_missing', 'Creators API no esta configurada o no esta disponible. El modo de enlaces afiliados puede seguir funcionando solo con Partner Tag.' );
    }

    $cache_key = seo_supplier_recipe_amazon_token_cache_key( $s );
    if ( ! $force ) {
        $cached = get_transient( $cache_key );
        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }
    }

    $response = wp_remote_post(
        seo_supplier_recipe_amazon_token_endpoint( '3.2' ),
        [
            'timeout' => 25,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode(
                [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $s['client_id'],
                    'client_secret' => $s['client_secret'],
                    'scope'         => 'creatorsapi::default',
                ]
            ),
        ]
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
        $msg = $data['error_description'] ?? ( $data['error'] ?? 'Amazon no devolvio un access_token.' );
        return new WP_Error( 'amazon_token_error', 'OAuth Amazon HTTP ' . $code . ': ' . sanitize_text_field( (string) $msg ) );
    }

    $ttl = ! empty( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 120 ) : 3300;
    set_transient( $cache_key, (string) $data['access_token'], $ttl );

    return (string) $data['access_token'];
}

function seo_supplier_recipe_amazon_api_post( $path, $payload ) {
    $s = seo_supplier_recipe_amazon_settings();

    if ( empty( $s['partner_tag'] ) ) {
        return new WP_Error( 'amazon_partner_tag_missing', 'Falta el Partner Tag de Amazon Associates para amazon.es.' );
    }

    $token = seo_supplier_recipe_amazon_get_access_token( false );
    if ( is_wp_error( $token ) ) {
        return $token;
    }

    $authorization = 'Bearer ' . $token;

    $payload['marketplace'] = $s['marketplace'];
    $payload['partnerTag']  = $s['partner_tag'];

    $response = wp_remote_post(
        'https://creatorsapi.amazon' . $path,
        [
            'timeout' => 35,
            'headers' => [
                'Authorization' => $authorization,
                'Content-Type'  => 'application/json',
                'x-marketplace' => $s['marketplace'],
            ],
            'body' => wp_json_encode( $payload ),
        ]
    );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( $code < 200 || $code >= 300 ) {
        $message = $data['errors'][0]['message'] ?? ( $data['message'] ?? 'Error de Creators API' );
        return new WP_Error( 'amazon_api_error', 'Amazon HTTP ' . $code . ': ' . sanitize_text_field( (string) $message ) );
    }

    return is_array( $data ) ? $data : [];
}

function seo_supplier_recipe_amazon_search_items( $keywords, $args = [] ) {
    $s = seo_supplier_recipe_amazon_settings();
    $args = wp_parse_args(
        $args,
        [
            'search_index' => $s['search_index'],
            'item_count'   => $s['item_count'],
            'item_page'    => 1,
            'sort_by'      => 'Relevance',
        ]
    );

    $keywords = trim( wp_strip_all_tags( (string) $keywords ) );
    if ( $keywords === '' ) {
        return new WP_Error( 'amazon_keywords_missing', 'Indica una busqueda para explorar Amazon.' );
    }

    $payload = [
        'keywords'              => $keywords,
        'searchIndex'           => sanitize_text_field( (string) $args['search_index'] ),
        'itemCount'             => max( 1, min( 10, (int) $args['item_count'] ) ),
        'itemPage'              => max( 1, min( 10, (int) $args['item_page'] ) ),
        'sortBy'                => sanitize_text_field( (string) $args['sort_by'] ),
        'languagesOfPreference' => [ $s['language'] ],
        'resources'             => [
            'images.primary.medium',
            'itemInfo.title',
            'itemInfo.features',
            'itemInfo.byLineInfo',
            'itemInfo.productInfo',
            'itemInfo.technicalInfo',
            'browseNodeInfo.browseNodes',
            'browseNodeInfo.browseNodes.ancestor',
            'browseNodeInfo.browseNodes.salesRank',
            'offersV2.listings.price',
            'parentASIN',
            'searchRefinements',
        ],
    ];

    return seo_supplier_recipe_amazon_api_post( '/catalog/v1/searchItems', $payload );
}

function seo_supplier_recipe_amazon_value( $array, $path, $default = '' ) {
    $cur = $array;
    foreach ( explode( '.', $path ) as $part ) {
        if ( ! is_array( $cur ) || ! array_key_exists( $part, $cur ) ) {
            return $default;
        }
        $cur = $cur[ $part ];
    }
    return $cur;
}

function seo_supplier_recipe_amazon_flatten_ancestor( $node ) {
    $names   = [];
    $current = $node['ancestor'] ?? null;
    $guard   = 0;

    while ( is_array( $current ) && $guard < 12 ) {
        if ( ! empty( $current['displayName'] ) ) {
            $names[] = $current['displayName'];
        }
        $current = $current['ancestor'] ?? null;
        $guard++;
    }

    return array_reverse( $names );
}

function seo_supplier_recipe_amazon_normalize_preview( $response ) {
    $items = isset( $response['searchResult']['items'] ) && is_array( $response['searchResult']['items'] )
        ? $response['searchResult']['items']
        : [];

    $out = [
        'products'           => [],
        'categories'         => [],
        'total_result_count' => (int) ( $response['searchResult']['totalResultCount'] ?? 0 ),
        'search_url'         => (string) ( $response['searchResult']['searchURL'] ?? '' ),
    ];

    foreach ( $items as $item ) {
        $asin     = (string) ( $item['asin'] ?? '' );
        $title    = seo_supplier_recipe_amazon_value( $item, 'itemInfo.title.displayValue', '' );
        $features = seo_supplier_recipe_amazon_value( $item, 'itemInfo.features.displayValues', [] );
        $image    = seo_supplier_recipe_amazon_value( $item, 'images.primary.medium.url', '' );
        $brand    = seo_supplier_recipe_amazon_value( $item, 'itemInfo.byLineInfo.brand.displayValue', '' );
        $price    = seo_supplier_recipe_amazon_value( $item, 'offersV2.listings.0.price.displayAmount', '' );
        $url      = (string) ( $item['detailPageURL'] ?? '' );
        $nodes    = seo_supplier_recipe_amazon_value( $item, 'browseNodeInfo.browseNodes', [] );

        if ( ! is_array( $features ) ) {
            $features = [];
        }
        if ( ! is_array( $nodes ) ) {
            $nodes = [];
        }

        $product_nodes = [];
        foreach ( $nodes as $node ) {
            if ( empty( $node['id'] ) || empty( $node['displayName'] ) ) {
                continue;
            }

            $path = array_merge(
                seo_supplier_recipe_amazon_flatten_ancestor( $node ),
                [ $node['displayName'] ]
            );

            $cat = [
                'amazon_node_id' => (string) $node['id'],
                'name'           => (string) $node['displayName'],
                'context_name'   => ! empty( $node['contextFreeName'] ) ? (string) $node['contextFreeName'] : (string) $node['displayName'],
                'path'           => implode( ' > ', array_filter( $path ) ),
                'sales_rank'     => isset( $node['salesRank'] ) ? (int) $node['salesRank'] : null,
            ];

            $product_nodes[] = $cat;
            $out['categories'][ $cat['amazon_node_id'] ] = $cat;
        }

        $out['products'][] = [
            'external_id' => $asin,
            'asin'        => $asin,
            'title'       => $title,
            'brand'       => $brand,
            'features'    => array_slice( array_values( array_filter( array_map( 'strval', $features ) ) ), 0, 8 ),
            'image_url'   => $image,
            'price'       => $price,
            'url'         => $url,
            'categories'  => $product_nodes,
            'parent_asin' => (string) ( $item['parentASIN'] ?? '' ),
        ];
    }

    $out['categories'] = array_values( $out['categories'] );
    return $out;
}

function seo_supplier_recipe_amazon_growth_candidates( $limit = 15 ) {
    $rows = [];

    if ( function_exists( 'seo_growth_exec_build_rows' ) ) {
        $data = seo_growth_exec_build_rows( 60, max( 20, (int) $limit ) );

        foreach ( (array) ( $data['rows'] ?? [] ) as $row ) {
            $item     = $row['item'] ?? [];
            $decision = $row['decision'] ?? [];
            $action   = $row['exec_action'] ?? [];
            $label    = trim( (string) ( $item['label'] ?? '' ) );

            if ( $label === '' ) {
                continue;
            }

            $rows[] = [
                'label'    => $label,
                'priority' => (int) ( $decision['priority'] ?? 0 ),
                'action'   => (string) ( $action['label'] ?? ( $item['strategy'] ?? 'Explorar' ) ),
            ];

            if ( count( $rows ) >= $limit ) {
                break;
            }
        }
    }

    return $rows;
}

function seo_supplier_recipe_amazon_save_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    check_admin_referer( 'seo_amazon_recipe_save', 'seo_amazon_recipe_nonce' );

    $current       = get_option( seo_supplier_recipe_amazon_option_key(), [] );
    $new_secret    = isset( $_POST['client_secret'] ) ? trim( (string) wp_unslash( $_POST['client_secret'] ) ) : '';
    $posted_tag    = isset( $_POST['partner_tag'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_tag'] ) ) : '';
    $current_tag   = sanitize_text_field( (string) ( $current['partner_tag'] ?? '' ) );
    $clear_tag     = ! empty( $_POST['clear_partner_tag'] );
    $effective_tag = $clear_tag ? '' : ( '' !== trim( $posted_tag ) ? $posted_tag : $current_tag );

    $settings = [
        'client_id'          => sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) ),
        'client_secret'      => $new_secret !== '' ? $new_secret : (string) ( $current['client_secret'] ?? '' ),
        'credential_version' => '3.2',
        'partner_tag'        => $effective_tag,
        'marketplace'        => 'www.amazon.es',
        'language'           => 'es_ES',
        'search_index'       => sanitize_text_field( wp_unslash( $_POST['search_index'] ?? 'All' ) ),
        'item_count'         => max( 1, min( 10, absint( $_POST['item_count'] ?? 10 ) ) ),
    ];

    update_option( seo_supplier_recipe_amazon_option_key(), $settings, false );
    delete_transient( seo_supplier_recipe_amazon_token_cache_key( $settings ) );

    wp_safe_redirect( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'conexiones-proveedores', 'amazon_saved' => 1 ], admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_seo_amazon_recipe_save', 'seo_supplier_recipe_amazon_save_settings' );

function seo_supplier_recipe_amazon_render_settings() {
    $s = seo_supplier_recipe_amazon_settings();

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;margin:18px 0;border-radius:6px;">';
    echo '<h2 style="margin-top:0;">Amazon Afiliados + Creators API opcional</h2>';
    echo '<p>El <strong>Partner Tag</strong> activa enlaces afiliados en amazon.es sin API. Credential ID y Secret solo son necesarios para enriquecer resultados mediante Creators API cuando la cuenta sea elegible.</p>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px;">';
    echo '<input type="hidden" name="action" value="seo_amazon_recipe_save">';
    wp_nonce_field( 'seo_amazon_recipe_save', 'seo_amazon_recipe_nonce' );
    echo '<label><strong>Partner Tag amazon.es (obligatorio)</strong><br><input type="text" name="partner_tag" value="' . esc_attr( $s['partner_tag'] ) . '" class="regular-text" style="width:100%;"><br><span class="description">Suficiente para enlaces y búsquedas afiliadas sin API. Si ya existe uno guardado, dejar este campo vacío lo conserva.</span></label>';
    if ( ! empty( $s['partner_tag'] ) ) {
        echo '<label style="grid-column:1/-1;"><input type="checkbox" name="clear_partner_tag" value="1"> Eliminar expresamente el Partner Tag guardado.</label>';
    }
    echo '<label><strong>Credential / Client ID (opcional)</strong><br><input type="text" name="client_id" value="' . esc_attr( $s['client_id'] ) . '" class="regular-text" style="width:100%;"></label>';
    echo '<label><strong>Secret Creators (opcional)</strong><br><input type="password" name="client_secret" value="" placeholder="' . ( ! empty( $s['client_secret'] ) ? 'Guardado; dejar vacio para conservar' : 'Solo si Creators API esta disponible' ) . '" class="regular-text" style="width:100%;"></label>';
    echo '<label><strong>Version credencial</strong><br><select name="credential_version" style="width:100%;"><option value="3.2" ' . selected( $s['credential_version'], '3.2', false ) . '>3.2</option><option value="2.2" ' . selected( $s['credential_version'], '2.2', false ) . '>2.2</option></select></label>';
    echo '<label><strong>Search Index</strong><br><input type="text" name="search_index" value="' . esc_attr( $s['search_index'] ) . '" class="regular-text" style="width:100%;"></label>';
    echo '<label><strong>Productos por llamada</strong><br><input type="number" min="1" max="10" name="item_count" value="' . (int) $s['item_count'] . '" style="width:100%;"></label>';
    echo '<div style="grid-column:1/-1;"><button class="button button-primary" type="submit">Guardar configuracion</button></div>';
    echo '</form>';
    echo '</div>';
}

function seo_supplier_recipe_amazon_render_explorer() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $s        = seo_supplier_recipe_amazon_settings();
    $preview  = null;
    $notice   = '';
    $error    = '';

    if ( isset( $_POST['seo_amazon_test_connection'] ) ) {
        check_admin_referer( 'seo_amazon_recipe_action', 'seo_amazon_action_nonce' );
        $token = seo_supplier_recipe_amazon_get_access_token( true );
        if ( is_wp_error( $token ) ) {
            $error = $token->get_error_message();
        } else {
            $notice = 'Conexion OAuth correcta. Amazon ha emitido un access token valido.';
        }
    }

    if ( isset( $_POST['seo_amazon_preview_search'] ) ) {
        check_admin_referer( 'seo_amazon_recipe_action', 'seo_amazon_action_nonce' );
        $keywords     = sanitize_text_field( wp_unslash( $_POST['keywords'] ?? '' ) );
        $search_index = sanitize_text_field( wp_unslash( $_POST['preview_search_index'] ?? $s['search_index'] ) );
        $page         = max( 1, min( 10, absint( $_POST['item_page'] ?? 1 ) ) );

        $result = seo_supplier_recipe_amazon_search_items(
            $keywords,
            [
                'search_index' => $search_index,
                'item_page'    => $page,
                'item_count'   => $s['item_count'],
            ]
        );

        if ( is_wp_error( $result ) ) {
            $error = $result->get_error_message();
        } else {
            $preview = seo_supplier_recipe_amazon_normalize_preview( $result );
        }
    }

    $candidates = seo_supplier_recipe_amazon_growth_candidates( 15 );

    echo '<div class="wrap">';
    echo '<h1>Amazon · Receta de exploracion</h1>';
    echo '<p><strong>V' . esc_html( SEO_AMAZON_RECIPE_VERSION ) . '</strong> · Receta nativa del importador. Solo preview; no crea tablas ni productos.</p>';

    if ( isset( $_GET['saved'] ) ) {
        echo '<div class="notice notice-success inline"><p>Configuracion guardada.</p></div>';
    }
    if ( $notice ) {
        echo '<div class="notice notice-success inline"><p>' . esc_html( $notice ) . '</p></div>';
    }
    if ( $error ) {
        echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
    }

    seo_supplier_recipe_amazon_render_settings();

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;margin:18px 0;border-radius:6px;">';
    echo '<h2 style="margin-top:0;">Oportunidades sugeridas por Que potenciar</h2>';
    if ( empty( $candidates ) ) {
        echo '<p>No se han podido cargar candidatos. Puedes escribir una busqueda manual.</p>';
    } else {
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
        foreach ( $candidates as $c ) {
            echo '<button type="button" class="button seo-amz-seed" data-seed="' . esc_attr( $c['label'] ) . '">' . esc_html( $c['label'] ) . ' · ' . (int) $c['priority'] . '/100</button>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;margin:18px 0;border-radius:6px;">';
    echo '<h2 style="margin-top:0;">Explorar Amazon</h2>';
    echo '<form method="post" style="display:grid;grid-template-columns:2fr 1fr 100px auto;gap:10px;align-items:end;">';
    wp_nonce_field( 'seo_amazon_recipe_action', 'seo_amazon_action_nonce' );
    echo '<label><strong>Busqueda</strong><br><input id="seo-amz-keywords" name="keywords" type="text" value="' . esc_attr( $_POST['keywords'] ?? '' ) . '" placeholder="Ej.: cintas transportadoras industriales" style="width:100%;"></label>';
    echo '<label><strong>Search Index</strong><br><input name="preview_search_index" type="text" value="' . esc_attr( $_POST['preview_search_index'] ?? $s['search_index'] ) . '" style="width:100%;"></label>';
    echo '<label><strong>Pagina</strong><br><input name="item_page" type="number" min="1" max="10" value="' . esc_attr( $_POST['item_page'] ?? 1 ) . '" style="width:100%;"></label>';
    echo '<button class="button button-primary" type="submit" name="seo_amazon_preview_search" value="1">Explorar</button>';
    echo '</form>';

    echo '<form method="post" style="margin-top:12px;">';
    wp_nonce_field( 'seo_amazon_recipe_action', 'seo_amazon_action_nonce' );
    echo '<button class="button" type="submit" name="seo_amazon_test_connection" value="1">Probar conexion OAuth</button>';
    echo '</form>';

    if ( is_array( $preview ) ) {
        echo '<hr style="margin:20px 0;">';
        echo '<h3>Resultado normalizado</h3>';
        echo '<p><strong>' . (int) $preview['total_result_count'] . '</strong> resultados aproximados · ' . count( $preview['products'] ) . ' productos mostrados.</p>';

        echo '<h4>Categorias / Browse Nodes detectados (' . count( $preview['categories'] ) . ')</h4>';
        if ( ! empty( $preview['categories'] ) ) {
            echo '<table class="widefat striped"><thead><tr><th>Node ID</th><th>Categoria Amazon</th><th>Jerarquia Amazon</th><th>Sales rank</th></tr></thead><tbody>';
            foreach ( $preview['categories'] as $cat ) {
                echo '<tr><td><code>' . esc_html( $cat['amazon_node_id'] ) . '</code></td><td><strong>' . esc_html( $cat['context_name'] ) . '</strong></td><td>' . esc_html( $cat['path'] ) . '</td><td>' . ( $cat['sales_rank'] !== null ? number_format_i18n( $cat['sales_rank'] ) : '—' ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h4 style="margin-top:22px;">Productos detectados</h4>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;">';
        foreach ( $preview['products'] as $p ) {
            echo '<div style="border:1px solid #dcdcde;border-radius:6px;padding:14px;background:#fff;">';
            if ( $p['image_url'] ) {
                echo '<img src="' . esc_url( $p['image_url'] ) . '" alt="" style="max-width:120px;max-height:120px;object-fit:contain;float:right;margin:0 0 8px 12px;">';
            }
            echo '<strong>' . esc_html( $p['title'] ?: $p['asin'] ) . '</strong><br>';
            echo '<code>ASIN ' . esc_html( $p['asin'] ) . '</code>';
            if ( $p['brand'] ) {
                echo '<br>Marca: ' . esc_html( $p['brand'] );
            }
            if ( $p['price'] ) {
                echo '<br>Precio: <strong>' . esc_html( $p['price'] ) . '</strong>';
            }
            if ( $p['categories'] ) {
                echo '<p><strong>Nodos:</strong><br>';
                foreach ( array_slice( $p['categories'], 0, 4 ) as $cat ) {
                    echo esc_html( $cat['context_name'] ) . '<br>';
                }
                echo '</p>';
            }
            if ( $p['features'] ) {
                echo '<details><summary>Features (' . count( $p['features'] ) . ')</summary><ul>';
                foreach ( $p['features'] as $feature ) {
                    echo '<li>' . esc_html( $feature ) . '</li>';
                }
                echo '</ul></details>';
            }
            if ( $p['url'] ) {
                echo '<p><a href="' . esc_url( $p['url'] ) . '" target="_blank" rel="noopener sponsored">Ver en Amazon</a></p>';
            }
            echo '<div style="clear:both;"></div></div>';
        }
        echo '</div>';
    }

    echo '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll(".seo-amz-seed").forEach(function(b){b.addEventListener("click",function(){var i=document.getElementById("seo-amz-keywords");if(i){i.value=b.getAttribute("data-seed")||"";i.focus();}});});});</script>';
    echo '</div>';
    echo '</div>';
}
