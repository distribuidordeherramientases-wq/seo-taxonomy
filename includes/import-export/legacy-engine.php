<?php
/**
 * SEO System — Motor compatible de Import/Export.
 *
 * Responsabilidad:
 * Mantener los importadores y exportadores historicos de productos,
 * categorias, paginas, entradas, FAQs y redirects durante la migracion modular.
 *
 * Este archivo registra los hooks publicos que ya utiliza el plugin y conserva
 * los formatos CSV existentes. La cola y los proveedores se cargan desde sus
 * propios modulos. Las nuevas entidades deben declararse en core/registry.php.
 *
 * No debe iniciarse una importacion al cargar este archivo. Toda escritura debe
 * proceder de una accion administrativa validada o de un trabajo ya programado.
 *
 * Plan de evolucion:
 * Extraer progresivamente cada entidad a entities/ sin cambiar los hooks ni
 * las cabeceras CSV publicas.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @author David Perez Martorell
 * @license GPL-2.0-or-later
 * @since 2.0.0
 * @version 2026-09-02
 * Build: 037
 */

defined( 'ABSPATH' ) || exit;


/*
 * El motor de proveedores se carga a traves de un puente estable. La ruta
 * historica suppliers/importer.php ya no forma parte del contrato interno.
 */
$seo_import_suppliers_bridge = dirname( __DIR__ ) . '/seo-import-suppliers.php';
if ( is_readable( $seo_import_suppliers_bridge ) ) {
    require_once $seo_import_suppliers_bridge;
}
unset( $seo_import_suppliers_bridge );

/*
 * Supplier Import / Sync V2. Se carga despues del importador compatible para
 * reutilizar sus recetas, CSV estandar, precios e imagenes sin perder datos.
 */
$seo_supplier_v2_file = __DIR__ . '/suppliers-v2/bootstrap.php';
if ( is_readable( $seo_supplier_v2_file ) ) {
    require_once $seo_supplier_v2_file;
}
unset( $seo_supplier_v2_file );

/*
 * Servicio Google dinamico para GA4, Search Console y Analytics.
 * La configuracion pertenece a cada instalacion y nunca se define en el codigo.
 */
$seo_google_search_file = __DIR__ . '/suppliers/google-search.php';
if ( is_readable( $seo_google_search_file ) ) {
    require_once $seo_google_search_file;
}
unset( $seo_google_search_file );

/*
 * Conexiones API de proveedores y servicios externos.
 * Este modulo solo gestiona credenciales y pruebas de conexion; no importa catalogo.
 */
$seo_supplier_connections_file = __DIR__ . '/suppliers/connections.php';

if ( is_readable( $seo_supplier_connections_file ) ) {
    require_once $seo_supplier_connections_file;
}
unset( $seo_supplier_connections_file );

/*
 * La cola multientidad vive en un modulo independiente para mantener
 * seo-export.php centrado en los importadores y exportadores individuales.
 */
$seo_import_batch_file = __DIR__ . '/queue/batch.php';

if ( is_readable( $seo_import_batch_file ) ) {
    require_once $seo_import_batch_file;
} else {
    add_action(
        'admin_notices',
        static function () {
            if ( current_user_can( 'manage_options' ) ) {
                echo '<div class="notice notice-error"><p>'
                    . esc_html__( 'SEO System no encuentra includes/import-export/queue/batch.php. La importacion por lotes no estara disponible.', 'seo-system' )
                    . '</p></div>';
            }
        }
    );
}
unset( $seo_import_batch_file );


// Procesa las operaciones antes de que WordPress imprima HTML.
add_action( 'admin_init', 'seo_export_categories_csv' );
add_action( 'admin_init', 'seo_export_products_csv' );
add_action( 'admin_init', 'seo_export_required_catalogs_csv' );
// La importacion individual de categorias ya no se registra en admin_init.
// El motor seo_import_categories_csv() se conserva exclusivamente como backend
// de la cola automatica, que valida/audita el archivo antes de ejecutarlo.
// La importacion manual de productos esta deshabilitada. El motor de productos
// se conserva exclusivamente como backend de la cola automatica por cabeceras.
add_action( 'seo_ie_process_product_import_batch', 'seo_ie_product_import_background_worker', 10, 2 );
add_action( 'seo_ie_product_import_watchdog', 'seo_ie_product_import_watchdog_worker', 10, 2 );
add_action( 'admin_init', 'seo_export_pages_csv' );
add_action( 'admin_init', 'seo_import_pages_csv' );
add_action( 'admin_init', 'seo_export_posts_csv' );
add_action( 'admin_init', 'seo_import_posts_csv' );
add_action( 'admin_init', 'seo_export_faqs_csv' );
add_action( 'admin_init', 'seo_import_faqs_csv' );
add_action( 'admin_init', 'seo_export_redirects_csv' );
add_action( 'admin_init', 'seo_import_redirects_csv' );

/**
 * Devuelve los ámbitos admitidos por SEO System.
 *
 * @since 2.0.0
 *
 * @return string[]
 */
function seo_ie_allowed_ambitos() {

    if ( function_exists( 'seo_catalog_allowed_roles' ) ) {
        return seo_catalog_allowed_roles();
    }

    return [
        'accesorio',
        'herramienta',
        'repuesto',
        'equipamiento',
        'consumible',
    ];
}

/**
 * Normaliza un ámbito y unifica variantes habituales.
 *
 * El ámbito de categorías y productos queda restringido a:
 * accesorio, herramienta, repuesto, equipamiento y consumible.
 *
 * @since 2.0.0
 *
 * @param string $ambito Valor recibido.
 * @return string Ámbito normalizado o cadena vacía.
 */
function seo_ie_normalize_ambito( $ambito ) {

    $ambito = seo_ie_csv_to_utf8( (string) $ambito );
    $ambito = sanitize_key( remove_accents( mb_strtolower( trim( $ambito ) ) ) );

    $aliases = [
        'accesorios'    => 'accesorio',
        'herramientas'  => 'herramienta',
        'recambio'      => 'repuesto',
        'recambios'     => 'repuesto',
        'repuestos'     => 'repuesto',
        'equipamientos' => 'equipamiento',
        'consumibles'    => 'consumible',
    ];

    if ( isset( $aliases[ $ambito ] ) ) {
        $ambito = $aliases[ $ambito ];
    }

    return in_array( $ambito, seo_ie_allowed_ambitos(), true )
        ? $ambito
        : '';
}

/**
 * Convierte una cadena CSV a UTF-8.
 *
 * Admite archivos UTF-8, Windows-1252 e ISO-8859-1 para conservar
 * correctamente ñ, tildes, símbolos monetarios y otros caracteres.
 *
 * @since 2.0.0
 *
 * @param mixed $value Valor procedente del CSV.
 * @return mixed
 */
function seo_ie_csv_to_utf8( $value ) {

    if ( ! is_string( $value ) ) {
        return $value;
    }

    // Elimina el BOM UTF-8 cuando aparece en la primera cabecera.
    $value = preg_replace( '/^\xEF\xBB\xBF/', '', $value );

    if ( function_exists( 'mb_detect_encoding' ) ) {

        $encoding = mb_detect_encoding(
            $value,
            [ 'UTF-8', 'Windows-1252', 'ISO-8859-1' ],
            true
        );

        if ( $encoding && 'UTF-8' !== $encoding ) {
            $value = mb_convert_encoding( $value, 'UTF-8', $encoding );
        }
    }

    return $value;
}

/**
 * Normaliza las cabeceras de los CSV de categorías, productos, páginas, FAQs o redirects.
 *
 * Acepta varios alias para mantener compatibilidad con exportaciones
 * antiguas y con modificaciones manuales realizadas en Excel.
 *
 * @since 2.0.0
 *
 * @param array  $header Cabecera original.
 * @param string $entity category, product, page, post, faq o redirect.
 * @return array
 */
function seo_ie_normalize_csv_header( $header, $entity ) {

    $common_aliases = [
        'nombre'              => 'titulo',
        'name'                => 'titulo',
        'title'               => 'titulo',
        'descripcion'         => 'description',
        'description_html'    => 'description',
        'resumen'             => 'excerpt',
        'short_description'   => 'excerpt',
        'scope'               => 'ambito',
        'attributes'          => 'atributos_seo',
        'attributes_seo'      => 'atributos_seo',
        'image'               => 'imagen_destacada',
        'image_url'           => 'imagen_destacada',
        'image_id'            => 'imagen_destacada_id',
        'category_ids'        => 'categorias_ids',
        'categories_ids'      => 'categorias_ids',
        'category'            => 'categorias',
        'categories'          => 'categorias',
    ];

    $category_aliases = [
        'id'                 => 'category_id',
        'object_id'          => 'category_id',
        'term_id'            => 'category_id',
        'parent'             => 'parent_id',
        'hub_secondary'      => 'hub_secondary_id',
        'secondary_id'       => 'hub_secondary_id',
        'hub_secundario_id'  => 'hub_secondary_id',
        'thumbnail_id'       => 'imagen_destacada_id',
        'thumbnail'          => 'imagen_destacada',
        'thumbnail_url'      => 'imagen_destacada',
        'category_image_id'  => 'imagen_destacada_id',
        'category_image'     => 'imagen_destacada',
        'category_image_url' => 'imagen_destacada',
    ];

    $product_aliases = [
        'id'                       => 'product_id',
        'object_id'                => 'product_id',
        'post_id'                  => 'product_id',
        'product_type'             => 'tipo_producto',
        'type'                     => 'tipo_producto',
        'status'                   => 'estado',
        'post_status'              => 'estado',
        'featured'                 => 'destacado',
        'catalog_visibility'       => 'visibilidad_catalogo',
        'product_url'              => 'url',
        'permalink'                => 'url',
        'product_tags'             => 'etiquetas_wc',
        'product_tag_ids'          => 'etiquetas_wc_ids',
        'wc_tags'                  => 'etiquetas_wc',
        'brand'                    => 'marca',
        'brand_ids'                => 'marca_ids',
        'brand_taxonomy'           => 'marca_taxonomia',
        'manufacturer'             => 'fabricante',
        'supplier'                 => 'proveedor',
        'supplier_external_id'     => 'proveedor_id_externo',
        'supplier_catalog_id'      => 'proveedor_catalogo_id',
        'supplier_category'        => 'categoria_proveedor',
        'supplier_price'           => 'precio_proveedor',
        'regular_price'            => 'precio_normal',
        'sale_price'               => 'precio_rebajado',
        'current_price'            => 'precio_actual',
        'currency'                 => 'moneda',
        'tax_status'               => 'estado_impuesto',
        'tax_class'                => 'clase_impuesto',
        'manage_stock'             => 'gestionar_stock',
        'stock_quantity'           => 'cantidad_stock',
        'stock_status'             => 'estado_stock',
        'backorders'               => 'pedidos_pendientes',
        'sold_individually'        => 'vendido_individualmente',
        'weight'                   => 'peso',
        'length'                   => 'longitud',
        'width'                    => 'anchura',
        'height'                   => 'altura',
        'virtual'                  => 'virtual',
        'downloadable'             => 'descargable',
        'shipping_class_id'        => 'clase_envio_id',
        'shipping_class'           => 'clase_envio',
        'gallery_ids'              => 'galeria_ids',
        'gallery_urls'             => 'galeria_urls',
        'gallery_images'           => 'galeria_urls',
        'wc_attributes'            => 'atributos_wc_json',
        'woocommerce_attributes'   => 'atributos_wc_json',
        'seo_attributes_json'      => 'atributos_seo_json',
        'semantic_type'            => 'tipo_semantico',
        'semantic_role'            => 'rol',
        'application'              => 'aplicacion',
        'platform'                 => 'plataforma',
        'subtype'                  => 'subtipo',
        'date_created'             => 'fecha_creacion',
        'date_modified'            => 'fecha_modificacion',
    ];

    $page_aliases = [
        'id'                    => 'page_id',
        'object_id'             => 'page_id',
        'post_id'               => 'page_id',
        'status'                => 'estado',
        'post_status'           => 'estado',
        'content'               => 'description',
        'contenido'             => 'description',
        'post_content'          => 'description',
        'post_excerpt'          => 'excerpt',
        'post_title'            => 'titulo',
        'post_name'             => 'slug',
        'path'                  => 'ruta',
        'page_path'             => 'ruta',
        'uri'                   => 'ruta',
        'permalink'             => 'url',
        'page_url'              => 'url',
        'parent'                => 'parent_id',
        'post_parent'           => 'parent_id',
        'parent_page_id'        => 'parent_id',
        'parent_path'           => 'parent_ruta',
        'parent_uri'            => 'parent_ruta',
        'parent_name'           => 'parent_slug',
        'order'                 => 'menu_order',
        'post_order'            => 'menu_order',
        'author_id'             => 'autor_id',
        'post_author'           => 'autor_id',
        'author'                => 'autor_login',
        'author_login'          => 'autor_login',
        'date'                  => 'fecha',
        'post_date'             => 'fecha',
        'date_gmt'              => 'fecha_gmt',
        'post_date_gmt'         => 'fecha_gmt',
        'modified'              => 'fecha_modificada',
        'post_modified'         => 'fecha_modificada',
        'modified_gmt'          => 'fecha_modificada_gmt',
        'post_modified_gmt'     => 'fecha_modificada_gmt',
        'comment_status'        => 'comentarios',
        'ping_status'           => 'pings',
        'featured_image_id'     => 'imagen_destacada_id',
        'featured_image'        => 'imagen_destacada',
        'seo_meta'              => 'meta_seo',
        'seo_meta_json'         => 'meta_seo',
        'custom_meta'           => 'meta_personalizados',
        'custom_fields'         => 'meta_personalizados',
        'meta_json'             => 'meta_personalizados',
    ];

    $post_aliases = [
        'id'                    => 'post_id',
        'object_id'             => 'post_id',
        'status'                => 'estado',
        'post_status'           => 'estado',
        'content'               => 'description',
        'contenido'             => 'description',
        'post_content'          => 'description',
        'post_excerpt'          => 'excerpt',
        'post_title'            => 'titulo',
        'post_name'             => 'slug',
        'permalink'             => 'url',
        'post_url'              => 'url',
        'author_id'             => 'autor_id',
        'post_author'           => 'autor_id',
        'author'                => 'autor_login',
        'author_login'          => 'autor_login',
        'date'                  => 'fecha',
        'post_date'             => 'fecha',
        'date_gmt'              => 'fecha_gmt',
        'post_date_gmt'         => 'fecha_gmt',
        'modified'              => 'fecha_modificada',
        'post_modified'         => 'fecha_modificada',
        'modified_gmt'          => 'fecha_modificada_gmt',
        'post_modified_gmt'     => 'fecha_modificada_gmt',
        'comment_status'        => 'comentarios',
        'ping_status'           => 'pings',
        'category_ids'          => 'categorias_ids',
        'categories_ids'        => 'categorias_ids',
        'category_slugs'        => 'categorias_slugs',
        'categories_slugs'      => 'categorias_slugs',
        'category_names'        => 'categorias_nombres',
        'categories'            => 'categorias_nombres',
        'tag_ids'               => 'etiquetas_ids',
        'tags_ids'              => 'etiquetas_ids',
        'tag_slugs'             => 'etiquetas_slugs',
        'tags_slugs'            => 'etiquetas_slugs',
        'tag_names'             => 'etiquetas_nombres',
        'tags'                  => 'etiquetas_nombres',
        'post_format'           => 'formato',
        'format'                => 'formato',
        'is_sticky'             => 'sticky',
        'featured_image_id'     => 'imagen_destacada_id',
        'featured_image'        => 'imagen_destacada',
        'seo_meta'              => 'meta_seo',
        'seo_meta_json'         => 'meta_seo',
        'custom_meta'           => 'meta_personalizados',
        'custom_fields'         => 'meta_personalizados',
        'meta_json'             => 'meta_personalizados',
    ];

    $faq_aliases = [
        'id'          => 'faq_id',
        'faq'         => 'faq_id',
        'pregunta'    => 'question',
        'respuesta'   => 'answer',
        'orden'       => 'sort_order',
        'activo'      => 'active',
        'cargas'      => 'load_count',
        'aperturas'   => 'open_count',
        'creado'      => 'created_at',
        'actualizado' => 'updated_at',
    ];

    $redirect_aliases = [
        'id'              => 'redirect_id',
        'redirect_id'     => 'redirect_id',
        'origin'          => 'origin_url',
        'origen'          => 'origin_url',
        'source'          => 'origin_url',
        'source_url'      => 'origin_url',
        'from'            => 'origin_url',
        'from_url'        => 'origin_url',
        'target'          => 'target_url',
        'destino'         => 'target_url',
        'destination'     => 'target_url',
        'destination_url' => 'target_url',
        'to'              => 'target_url',
        'to_url'          => 'target_url',
        'status'          => 'status_code',
        'code'            => 'status_code',
        'http_code'       => 'status_code',
        'hit_count'       => 'hits',
        'visitas'         => 'hits',
        'last_access'     => 'last_hit',
        'ultimo_acceso'   => 'last_hit',
    ];

    if ( 'category' === $entity ) {
        $entity_aliases = $category_aliases;
    } elseif ( 'product' === $entity ) {
        $entity_aliases = $product_aliases;
    } elseif ( 'page' === $entity ) {
        $entity_aliases = $page_aliases;
    } elseif ( 'post' === $entity ) {
        $entity_aliases = $post_aliases;
    } elseif ( 'redirect' === $entity ) {
        $entity_aliases = $redirect_aliases;
    } else {
        $entity_aliases = $faq_aliases;
    }

    $aliases = array_merge( $common_aliases, $entity_aliases );

    return array_map(
        static function ( $column ) use ( $aliases ) {

            $column = seo_ie_csv_to_utf8( $column );
            $column = sanitize_key( trim( (string) $column ) );

            return $aliases[ $column ] ?? $column;
        },
        (array) $header
    );
}

/**
 * Construye una fila asociativa a partir de cabecera y valores CSV.
 *
 * No usa array_combine para tolerar filas con columnas ausentes o extra.
 *
 * @since 2.0.0
 *
 * @param array $header  Cabecera normalizada.
 * @param array $csv_row Valores de la fila.
 * @return array
 */
function seo_ie_build_csv_row( $header, $csv_row ) {

    $row = [];

    foreach ( $header as $index => $column_name ) {
        $row[ $column_name ] = isset( $csv_row[ $index ] )
            ? seo_ie_csv_to_utf8( $csv_row[ $index ] )
            : '';
    }

    return $row;
}

/**
 * Inserta o actualiza un único valor de wp_seo_nodes.
 *
 * Mantiene una sola fila por pareja objeto/rol y elimina duplicados
 * antiguos. Si el valor queda vacío, elimina únicamente ese rol.
 *
 * @since 2.0.0
 *
 * @param string $object_type category o product.
 * @param int    $object_id   ID real de WordPress.
 * @param string $seo_role    ambito, excerpt o description, según el objeto.
 * @param string $keywords    Valor que se almacenará.
 * @return bool
 */
function seo_ie_upsert_node_value( $object_type, $object_id, $seo_role, $keywords ) {

    global $wpdb;

    $object_type = sanitize_key( $object_type );
    $object_id   = absint( $object_id );
    $seo_role    = sanitize_key( $seo_role );
    $keywords    = seo_ie_csv_to_utf8( (string) $keywords );
    $table       = $wpdb->prefix . 'seo_nodes';

    $valid_roles = [
        'category' => [ 'ambito', 'excerpt', 'description' ],
        'product'  => [ 'ambito' ],
    ];

    if (
        0 >= $object_id
        || ! isset( $valid_roles[ $object_type ] )
        || ! in_array( $seo_role, $valid_roles[ $object_type ], true )
    ) {
        return false;
    }

    if ( in_array( $seo_role, [ 'excerpt', 'description' ], true ) ) {
        $keywords = wp_kses_post(
            html_entity_decode(
                $keywords,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );
    } else {
        $keywords = sanitize_text_field( $keywords );
    }

    $existing_ids = $wpdb->get_col(
        $wpdb->prepare(
            "
            SELECT id
            FROM {$table}
            WHERE object_type = %s
              AND object_id = %d
              AND seo_role = %s
            ORDER BY updated_at DESC, id DESC
            ",
            $object_type,
            $object_id,
            $seo_role
        )
    );

    if ( '' === trim( wp_strip_all_tags( $keywords ) ) ) {

        $deleted = $wpdb->delete(
            $table,
            [
                'object_type' => $object_type,
                'object_id'   => $object_id,
                'seo_role'    => $seo_role,
            ],
            [ '%s', '%d', '%s' ]
        );

        return false !== $deleted;
    }

    if ( ! empty( $existing_ids ) ) {

        $primary_id = absint( array_shift( $existing_ids ) );

        $updated = $wpdb->update(
            $table,
            [
                'keywords'   => $keywords,
                'status'     => 1,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $primary_id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );

        foreach ( $existing_ids as $duplicate_id ) {
            $wpdb->delete(
                $table,
                [ 'id' => absint( $duplicate_id ) ],
                [ '%d' ]
            );
        }

        return false !== $updated;
    }

    return false !== $wpdb->insert(
        $table,
        [
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'seo_role'    => $seo_role,
            'keywords'    => $keywords,
            'status'      => 1,
            'created_at'  => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
        ],
        [ '%s', '%d', '%s', '%s', '%d', '%s', '%s' ]
    );
}

/**
 * Guarda el último log de importación o exportación para el usuario actual.
 *
 * @since 2.0.0
 *
 * @param array $log Datos del proceso.
 * @return void
 */
function seo_ie_store_log( $log ) {

    $log['fecha'] = current_time( 'mysql' );

    update_user_meta(
        get_current_user_id(),
        'seo_import_export_last_log',
        $log
    );

    if ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal() ) {
        $GLOBALS['seo_ie_batch_last_log'] = $log;
    }
}

/**
 * Devuelve el último log del usuario actual.
 *
 * @since 2.0.0
 *
 * @return array
 */
function seo_ie_get_last_log() {

    $log = get_user_meta(
        get_current_user_id(),
        'seo_import_export_last_log',
        true
    );

    return is_array( $log ) ? $log : [];
}

/**
 * Añade una línea al detalle del log sin permitir que crezca sin límite.
 *
 * @since 2.0.0
 *
 * @param array  $log     Log por referencia.
 * @param string $message Mensaje.
 * @return void
 */
function seo_ie_add_log_detail( &$log, $message ) {

    if ( ! isset( $log['detalles'] ) || ! is_array( $log['detalles'] ) ) {
        $log['detalles'] = [];
    }

    if ( 200 > count( $log['detalles'] ) ) {
        $log['detalles'][] = sanitize_text_field( $message );
    } else {
        $log['truncado'] = true;
    }
}

/**
 * Prepara una descarga CSV compatible con Excel y LibreOffice.
 *
 * @since 2.0.0
 *
 * @param string $filename Nombre del archivo.
 * @return resource
 */
function seo_ie_open_csv_download( $filename ) {

    while ( ob_get_level() ) {
        ob_end_clean();
    }

    nocache_headers();

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

    $output = fopen( 'php://output', 'w' );

    if ( false === $output ) {
        wp_die( esc_html__( 'No se pudo generar el archivo CSV.', 'seo-system' ) );
    }

    // BOM UTF-8 para que Excel interprete correctamente ñ y tildes.
    fwrite( $output, "\xEF\xBB\xBF" );

    return $output;
}

/**
 * Escribe una fila CSV usando punto y coma como separador.
 *
 * @since 2.0.0
 *
 * @param resource $output Recurso abierto.
 * @param array    $fields Campos de la fila.
 * @return void
 */
function seo_ie_write_csv_row( $output, $fields ) {

    fputcsv( $output, $fields, ';', '"', '' );
}

/**
 * Exporta en un unico CSV los catalogos maestros que deben respetarse al
 * crear o actualizar productos/categorias desde sistemas externos.
 *
 * El archivo NO exporta asignaciones actuales de objetos/productos. Por tanto,
 * seo_object_vocabulary y sql_product_atributos quedan fuera deliberadamente:
 * son tablas destino de asignacion, no diccionarios de valores permitidos.
 *
 * Catalogos incluidos:
 * - seo_vocabulary: etiquetas semanticas activas.
 * - seo_type_role_map: relacion canonica TIPO -> ROL activa.
 * - sql_atributos: definiciones activas de atributos tecnicos.
 * - sql_atributos_terminos: terminos activos permitidos.
 * - sql_atributos_aliases: aliases utilizables para atributos activos.
 * - product_tag: etiquetas WooCommerce existentes que el importador de productos puede asignar.
 *
 * @since 2.0.0
 *
 * @return void
 */
function seo_export_required_catalogs_csv() {

    if ( ! isset( $_POST['seo_export_required_catalogs'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para exportar los catalogos obligatorios.', 'seo-system' ) );
    }

    check_admin_referer(
        'seo_export_required_catalogs_csv',
        'seo_export_required_catalogs_nonce'
    );

    global $wpdb;

    $tables = [
        'seo_vocabulary'         => $wpdb->prefix . 'seo_vocabulary',
        'seo_type_role_map'      => $wpdb->prefix . 'seo_type_role_map',
        'sql_atributos'          => $wpdb->prefix . 'sql_atributos',
        'sql_atributos_terminos' => $wpdb->prefix . 'sql_atributos_terminos',
        'sql_atributos_aliases'  => $wpdb->prefix . 'sql_atributos_aliases',
    ];

    $missing = [];

    foreach ( $tables as $logical_name => $physical_name ) {
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like( $physical_name )
            )
        );

        if ( $physical_name !== $found ) {
            $missing[] = $logical_name;
        }
    }

    if ( $missing ) {
        wp_die(
            esc_html(
                sprintf(
                    'No se puede generar el CSV maestro porque faltan estas tablas: %s.',
                    implode( ', ', $missing )
                )
            ),
            esc_html__( 'Catalogos SEO incompletos', 'seo-system' )
        );
    }

    $filename = 'seo_catalogos_obligatorios_' . wp_date( 'Ymd_His' ) . '.csv';
    $output   = seo_ie_open_csv_download( $filename );

    $headers = [
        'tabla',
        'tabla_fisica',
        'tipo_registro',
        'uso',
        'id',
        'semantic_group',
        'slug',
        'label',
        'parent_id',
        'source',
        'active',
        'type_vocabulary_id',
        'type_slug',
        'type_label',
        'role_vocabulary_id',
        'role_slug',
        'role_label',
        'confidence',
        'attribute_id',
        'attribute_slug',
        'attribute_name',
        'attribute_group',
        'attribute_type',
        'unit_type',
        'base_unit',
        'multiple',
        'filterable',
        'visible',
        'seo',
        'sort_order',
        'term_id',
        'term_slug',
        'term_name',
        'alias',
    ];

    seo_ie_write_csv_row( $output, $headers );

    $exported = 0;

    $write_record = static function ( $data ) use ( $output, $headers, &$exported ) {
        $row = [];

        foreach ( $headers as $header ) {
            $row[] = array_key_exists( $header, $data ) ? $data[ $header ] : '';
        }

        seo_ie_write_csv_row( $output, $row );
        $exported++;
    };

    // 1) Vocabulario semantico activo: ROL, TIPO, APLICACION, PLATAFORMA,
    // SUBTIPO y cualquier otro grupo canonico habilitado en la instalacion.
    $vocabulary_rows = $wpdb->get_results(
        "SELECT id, semantic_group, slug, label, parent_id, source, active
         FROM {$tables['seo_vocabulary']}
         WHERE active = 1
         ORDER BY semantic_group ASC, label ASC, id ASC",
        ARRAY_A
    );

    foreach ( (array) $vocabulary_rows as $row ) {
        $write_record(
            [
                'tabla'          => 'seo_vocabulary',
                'tabla_fisica'   => $tables['seo_vocabulary'],
                'tipo_registro'  => 'vocabulary',
                'uso'            => 'Etiqueta semantica permitida',
                'id'             => $row['id'],
                'semantic_group' => $row['semantic_group'],
                'slug'           => $row['slug'],
                'label'          => $row['label'],
                'parent_id'      => $row['parent_id'],
                'source'         => $row['source'],
                'active'         => $row['active'],
            ]
        );
    }

    // 2) Mapeo TIPO -> ROL: solo relaciones activas cuyos dos extremos estan
    // tambien activos y pertenecen a los grupos semanticos correctos.
    $type_role_rows = $wpdb->get_results(
        "SELECT
            m.id,
            m.type_vocabulary_id,
            tv.slug AS type_slug,
            tv.label AS type_label,
            m.role_vocabulary_id,
            rv.slug AS role_slug,
            rv.label AS role_label,
            m.confidence,
            m.source,
            m.active
         FROM {$tables['seo_type_role_map']} m
         INNER JOIN {$tables['seo_vocabulary']} tv
            ON tv.id = m.type_vocabulary_id
           AND tv.active = 1
           AND tv.semantic_group = 'tipo'
         INNER JOIN {$tables['seo_vocabulary']} rv
            ON rv.id = m.role_vocabulary_id
           AND rv.active = 1
           AND rv.semantic_group = 'rol'
         WHERE m.active = 1
         ORDER BY tv.label ASC, rv.label ASC, m.id ASC",
        ARRAY_A
    );

    foreach ( (array) $type_role_rows as $row ) {
        $write_record(
            [
                'tabla'              => 'seo_type_role_map',
                'tabla_fisica'       => $tables['seo_type_role_map'],
                'tipo_registro'      => 'type_role_map',
                'uso'                => 'Relacion obligatoria TIPO -> ROL',
                'id'                 => $row['id'],
                'source'             => $row['source'],
                'active'             => $row['active'],
                'type_vocabulary_id' => $row['type_vocabulary_id'],
                'type_slug'          => $row['type_slug'],
                'type_label'         => $row['type_label'],
                'role_vocabulary_id' => $row['role_vocabulary_id'],
                'role_slug'          => $row['role_slug'],
                'role_label'         => $row['role_label'],
                'confidence'         => $row['confidence'],
            ]
        );
    }

    // 3) Definiciones activas de atributos tecnicos.
    $attribute_rows = $wpdb->get_results(
        "SELECT
            id, slug, nombre, grupo, tipo, unidad_tipo, unidad_base,
            multiple, filtrable, visible, seo, orden, activo
         FROM {$tables['sql_atributos']}
         WHERE activo = 1
         ORDER BY orden ASC, nombre ASC, id ASC",
        ARRAY_A
    );

    foreach ( (array) $attribute_rows as $row ) {
        $write_record(
            [
                'tabla'           => 'sql_atributos',
                'tabla_fisica'    => $tables['sql_atributos'],
                'tipo_registro'   => 'attribute',
                'uso'             => 'Definicion de atributo permitida',
                'id'              => $row['id'],
                'active'          => $row['activo'],
                'attribute_id'    => $row['id'],
                'attribute_slug'  => $row['slug'],
                'attribute_name'  => $row['nombre'],
                'attribute_group' => $row['grupo'],
                'attribute_type'  => $row['tipo'],
                'unit_type'       => $row['unidad_tipo'],
                'base_unit'       => $row['unidad_base'],
                'multiple'        => $row['multiple'],
                'filterable'      => $row['filtrable'],
                'visible'         => $row['visible'],
                'seo'             => $row['seo'],
                'sort_order'      => $row['orden'],
            ]
        );
    }

    // 4) Terminos controlados activos de atributos activos.
    $term_rows = $wpdb->get_results(
        "SELECT
            t.id AS term_id,
            t.atributo_id AS attribute_id,
            a.slug AS attribute_slug,
            a.nombre AS attribute_name,
            a.grupo AS attribute_group,
            a.tipo AS attribute_type,
            t.slug AS term_slug,
            t.nombre AS term_name,
            t.orden AS sort_order,
            t.activo AS active
         FROM {$tables['sql_atributos_terminos']} t
         INNER JOIN {$tables['sql_atributos']} a
            ON a.id = t.atributo_id
           AND a.activo = 1
         WHERE t.activo = 1
         ORDER BY a.orden ASC, a.nombre ASC, t.orden ASC, t.nombre ASC, t.id ASC",
        ARRAY_A
    );

    foreach ( (array) $term_rows as $row ) {
        $write_record(
            [
                'tabla'           => 'sql_atributos_terminos',
                'tabla_fisica'    => $tables['sql_atributos_terminos'],
                'tipo_registro'   => 'attribute_term',
                'uso'             => 'Valor controlado permitido para atributo tipo termino',
                'id'              => $row['term_id'],
                'active'          => $row['active'],
                'attribute_id'    => $row['attribute_id'],
                'attribute_slug'  => $row['attribute_slug'],
                'attribute_name'  => $row['attribute_name'],
                'attribute_group' => $row['attribute_group'],
                'attribute_type'  => $row['attribute_type'],
                'sort_order'      => $row['sort_order'],
                'term_id'         => $row['term_id'],
                'term_slug'       => $row['term_slug'],
                'term_name'       => $row['term_name'],
            ]
        );
    }

    // 5) Aliases reconocidos. Si el alias apunta a un termino, solo se incluye
    // cuando dicho termino esta activo; los aliases sin termino siguen siendo
    // validos para atributos activos que los utilicen como normalizacion libre.
    $alias_rows = $wpdb->get_results(
        "SELECT
            al.id,
            al.atributo_id AS attribute_id,
            a.slug AS attribute_slug,
            a.nombre AS attribute_name,
            a.grupo AS attribute_group,
            a.tipo AS attribute_type,
            al.termino_id AS term_id,
            t.slug AS term_slug,
            t.nombre AS term_name,
            al.alias
         FROM {$tables['sql_atributos_aliases']} al
         INNER JOIN {$tables['sql_atributos']} a
            ON a.id = al.atributo_id
           AND a.activo = 1
         LEFT JOIN {$tables['sql_atributos_terminos']} t
            ON t.id = al.termino_id
           AND t.activo = 1
         WHERE al.termino_id IS NULL OR t.id IS NOT NULL
         ORDER BY a.orden ASC, a.nombre ASC, al.alias ASC, al.id ASC",
        ARRAY_A
    );

    foreach ( (array) $alias_rows as $row ) {
        $write_record(
            [
                'tabla'           => 'sql_atributos_aliases',
                'tabla_fisica'    => $tables['sql_atributos_aliases'],
                'tipo_registro'   => 'attribute_alias',
                'uso'             => 'Alias aceptado para normalizar un valor existente',
                'id'              => $row['id'],
                'attribute_id'    => $row['attribute_id'],
                'attribute_slug'  => $row['attribute_slug'],
                'attribute_name'  => $row['attribute_name'],
                'attribute_group' => $row['attribute_group'],
                'attribute_type'  => $row['attribute_type'],
                'term_id'         => $row['term_id'],
                'term_slug'       => $row['term_slug'],
                'term_name'       => $row['term_name'],
                'alias'           => $row['alias'],
            ]
        );
    }

    // 6) Etiquetas WooCommerce existentes. El importador de productos en modo
    // seguro nunca crea product_tag implícitamente; por eso forman parte del
    // catálogo permitido y, si faltan, se dan de alta previamente con el
    // importador explícito de vocabulario.
    if ( taxonomy_exists( 'product_tag' ) ) {
        $product_tags = get_terms(
            [
                'taxonomy'   => 'product_tag',
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]
        );

        if ( ! is_wp_error( $product_tags ) ) {
            foreach ( (array) $product_tags as $term ) {
                $write_record(
                    [
                        'tabla'         => 'woocommerce_product_tag',
                        'tabla_fisica'  => $wpdb->terms . ' + ' . $wpdb->term_taxonomy,
                        'tipo_registro' => 'product_tag',
                        'uso'           => 'Etiqueta WooCommerce permitida',
                        'id'            => (int) $term->term_id,
                        'slug'          => (string) $term->slug,
                        'label'         => (string) $term->name,
                        'active'        => 1,
                    ]
                );
            }
        }
    }

    seo_ie_store_log(
        [
            'operacion'  => 'Exportacion de catalogos obligatorios',
            'archivo'    => $filename,
            'procesados' => $exported,
            'correctos'  => $exported,
            'errores'    => 0,
            'detalles'   => [
                'CSV unico con valores activos de seo_vocabulary, seo_type_role_map, sql_atributos, sql_atributos_terminos, sql_atributos_aliases y product_tag.',
                'seo_object_vocabulary y sql_product_atributos no se exportan porque contienen asignaciones, no catalogos de valores permitidos.',
            ],
        ]
    );

    fclose( $output );
    exit;
}

/**
 * Lee una fila CSV usando punto y coma como separador.
 *
 * @since 2.0.0
 *
 * @param resource $handle Recurso abierto.
 * @return array|false
 */
function seo_ie_read_csv_row( $handle ) {

    return fgetcsv( $handle, 0, ';', '"', '' );
}

/**
 * Serializa los atributos de un producto para almacenarlos en una celda CSV.
 *
 * Formato:
 * herramienta:potencia=1500 W | herramienta:peso=8 kg
 *
 * @since 2.0.0
 *
 * @param array  $rows           Filas canónicas de atributos de producto.
 * @param string $product_scope  Ámbito del producto como valor de respaldo.
 * @return string
 */
function seo_ie_serialize_attributes( $rows, $product_scope ) {

    $serialized = [];

    foreach ( (array) $rows as $row ) {

        // El ámbito ya no pertenece al atributo técnico. Se conserva
        // en el formato CSV únicamente por compatibilidad y se toma del ROL
        // canónico del producto.
        $ambito = trim( (string) $product_scope );
        if ( '' === $ambito ) {
            $ambito = 'global';
        }

        $attribute_type  = trim( (string) $row->attribute_type );
        $attribute_value = trim( (string) $row->attribute_value );

        if ( '' === $attribute_type || '' === $attribute_value ) {
            continue;
        }

        $serialized[] =
            $ambito
            . ':'
            . $attribute_type
            . '='
            . $attribute_value;
    }

    return implode( ' | ', $serialized );
}

/**
 * Convierte la columna atributos_seo del CSV en filas normalizadas.
 *
 * Admite el formato actual:
 * herramienta:potencia=1500 W
 *
 * También admite el formato antiguo:
 * potencia=1500 W
 *
 * En el formato antiguo usa el ámbito general del producto.
 *
 * @since 2.0.0
 *
 * @param string $attributes_text Texto del CSV.
 * @param string $product_scope   Ámbito del producto.
 * @return array {
 *     @type array $rows   Filas válidas.
 *     @type array $errors Errores encontrados.
 * }
 */
function seo_ie_parse_attributes( $attributes_text, $product_scope ) {

    $result = [
        'rows'   => [],
        'errors' => [],
    ];

    $attributes_text = trim( seo_ie_csv_to_utf8( (string) $attributes_text ) );

    if ( '' === $attributes_text ) {
        return $result;
    }

    $items = explode( '|', $attributes_text );

    foreach ( $items as $position => $item ) {

        $item = trim( $item );

        if ( '' === $item ) {
            continue;
        }

        if ( false === strpos( $item, '=' ) ) {
            $result['errors'][] = sprintf(
                'Atributo %d sin separador "=".',
                $position + 1
            );
            continue;
        }

        [ $left, $attribute_value ] = explode( '=', $item, 2 );

        $left            = trim( $left );
        $attribute_value = sanitize_text_field( trim( $attribute_value ) );
        $ambito          = $product_scope;
        $attribute_type  = $left;

        if ( false !== strpos( $left, ':' ) ) {
            [ $ambito, $attribute_type ] = explode( ':', $left, 2 );
        }

        $ambito = sanitize_key( remove_accents( mb_strtolower( trim( $ambito ) ) ) );

        // En atributos se admite global para conservar modelos o CSV antiguos.
        if (
            'global' !== $ambito
            && ! in_array( $ambito, seo_ie_allowed_ambitos(), true )
        ) {
            $ambito = $product_scope;
        }

        $attribute_type = sanitize_key(
            remove_accents(
                mb_strtolower(
                    trim( $attribute_type )
                )
            )
        );

        if (
            '' === $ambito
            || '' === $attribute_type
            || '' === $attribute_value
        ) {
            $result['errors'][] = sprintf(
                'Atributo %d incompleto.',
                $position + 1
            );
            continue;
        }

        $result['rows'][] = [
            'ambito'          => $ambito,
            'attribute_type'  => $attribute_type,
            'attribute_value' => $attribute_value,
        ];
    }

    return $result;
}

/**
 * Importa o elimina la imagen asociada a una categoría WooCommerce.
 *
 * WooCommerce guarda la imagen de product_cat en el term meta thumbnail_id.
 * Se acepta un attachment ID existente o una URL. Si el CSV contiene columnas
 * de imagen pero no aporta ID ni URL, se elimina la imagen actual.
 *
 * @param int   $category_id ID de categoría.
 * @param array $row         Fila CSV normalizada.
 * @param int   $line        Línea del CSV.
 * @param array $log         Log por referencia.
 * @return void
 */
function seo_ie_import_category_thumbnail( $category_id, $row, $line, &$log ) {
    $has_id_column  = array_key_exists( 'imagen_destacada_id', $row );
    $has_url_column = array_key_exists( 'imagen_destacada', $row );

    if ( ! $has_id_column && ! $has_url_column ) {
        return;
    }

    $category_id   = absint( $category_id );
    $attachment_id = absint( $row['imagen_destacada_id'] ?? 0 );
    $image_url     = esc_url_raw( trim( (string) ( $row['imagen_destacada'] ?? '' ) ) );

    if ( 0 === $attachment_id && '' === $image_url ) {
        delete_term_meta( $category_id, 'thumbnail_id' );
        return;
    }

    $attachment_is_valid = 0 < $attachment_id && 'attachment' === get_post_type( $attachment_id );

    /*
     * Si el CSV trae URL, esa URL es la identidad portable de la imagen.
     * El ID solo es fiable dentro de la misma biblioteca de medios: en otra
     * instalación el mismo número puede apuntar a un adjunto distinto.
     */
    if ( '' !== $image_url ) {
        $resolved_id = attachment_url_to_postid( $image_url );

        if ( 0 < $resolved_id && 'attachment' === get_post_type( $resolved_id ) ) {
            update_term_meta( $category_id, 'thumbnail_id', $resolved_id );
            return;
        }

        if ( $attachment_is_valid ) {
            $attachment_url  = (string) wp_get_attachment_url( $attachment_id );
            $csv_url_parts   = wp_parse_url( $image_url );
            $media_url_parts = wp_parse_url( $attachment_url );
            $csv_path        = isset( $csv_url_parts['path'] ) ? rawurldecode( (string) $csv_url_parts['path'] ) : '';
            $media_path      = isset( $media_url_parts['path'] ) ? rawurldecode( (string) $media_url_parts['path'] ) : '';

            // Ignora dominio/protocolo para tolerar CDN o cambio de dominio,
            // pero exige que el archivo/ruta del adjunto coincida.
            if ( '' !== $csv_path && '' !== $media_path && $csv_path === $media_path ) {
                update_term_meta( $category_id, 'thumbnail_id', $attachment_id );
                return;
            }

            seo_ie_add_log_warning(
                $log,
                sprintf(
                    'Fila %d, categoría %d: el adjunto %d existe, pero no coincide con la URL del CSV; se prioriza la URL para evitar asignar una imagen incorrecta.',
                    $line,
                    $category_id,
                    $attachment_id
                )
            );
        } elseif ( 0 < $attachment_id ) {
            seo_ie_add_log_warning(
                $log,
                sprintf(
                    'Fila %d, categoría %d: el adjunto %d no existe en esta instalación; se resolverá la URL.',
                    $line,
                    $category_id,
                    $attachment_id
                )
            );
        }

        if ( ! wp_http_validate_url( $image_url ) ) {
            seo_ie_add_log_warning(
                $log,
                sprintf(
                    'Fila %d, categoría %d: la URL de imagen no es válida; no se cambia la imagen actual.',
                    $line,
                    $category_id
                )
            );
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $sideloaded_id = media_sideload_image( $image_url, 0, null, 'id' );

        if ( is_wp_error( $sideloaded_id ) ) {
            seo_ie_add_log_warning(
                $log,
                sprintf(
                    'Fila %d, categoría %d: no se pudo importar la imagen desde la URL: %s',
                    $line,
                    $category_id,
                    $sideloaded_id->get_error_message()
                )
            );
            return;
        }

        update_term_meta( $category_id, 'thumbnail_id', absint( $sideloaded_id ) );
        return;
    }

    // Sin URL solo se puede confiar en un ID de adjunto local existente.
    if ( $attachment_is_valid ) {
        update_term_meta( $category_id, 'thumbnail_id', $attachment_id );
        return;
    }

    if ( 0 < $attachment_id ) {
        seo_ie_add_log_warning(
            $log,
            sprintf(
                'Fila %d, categoría %d: el adjunto %d no existe y el CSV no contiene URL; no se cambia la imagen actual.',
                $line,
                $category_id,
                $attachment_id
            )
        );
    }
}

/**
 * Exporta todas las categorías WooCommerce a CSV.
 *
 * Orígenes:
 * - WordPress/WooCommerce: ID, nombre, slug, padre e imagen de categoría.
 * - wp_seo_nodes: ámbito, excerpt y description.
 * - wp_seo_relations: hub secundario estructural de la categoría cuando es único.
 *
 * @since 2.0.0
 *
 * @return void
 */
function seo_export_categories_csv() {

    if ( ! isset( $_POST['seo_export_categories'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para exportar categorías.', 'seo-system' ) );
    }

    check_admin_referer(
        'seo_export_categories_csv',
        'seo_export_categories_nonce'
    );

    global $wpdb;

    $nodes = $wpdb->get_results(
        "
        SELECT object_id, seo_role, keywords
        FROM {$wpdb->prefix}seo_nodes
        WHERE object_type = 'category'
          AND seo_role IN ('ambito', 'excerpt', 'description')
          AND status = 1
        ORDER BY object_id ASC, seo_role ASC, updated_at DESC, id DESC
        "
    );

    $nodes_by_category = [];

    foreach ( $nodes as $node ) {

        $category_id = absint( $node->object_id );
        $seo_role    = (string) $node->seo_role;

        if ( ! isset( $nodes_by_category[ $category_id ] ) ) {
            $nodes_by_category[ $category_id ] = [
                'ambito'      => '',
                'excerpt'     => '',
                'description' => '',
            ];
        }

        // La consulta está ordenada por la fila activa más reciente.
        if ( 'ambito' === $seo_role && '' === $nodes_by_category[ $category_id ]['ambito'] ) {
            $nodes_by_category[ $category_id ]['ambito'] =
                seo_ie_normalize_ambito( $node->keywords );
        } elseif ( 'excerpt' === $seo_role && '' === $nodes_by_category[ $category_id ]['excerpt'] ) {
            $nodes_by_category[ $category_id ]['excerpt'] = (string) $node->keywords;
        } elseif ( 'description' === $seo_role && '' === $nodes_by_category[ $category_id ]['description'] ) {
            $nodes_by_category[ $category_id ]['description'] = (string) $node->keywords;
        }
    }

    $categories = get_terms(
        [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'term_id',
            'order'      => 'ASC',
        ]
    );

    if ( is_wp_error( $categories ) ) {
        wp_die(
            esc_html( $categories->get_error_message() ),
            esc_html__( 'Error exportando categorías', 'seo-system' )
        );
    }

    // La jerarquía SEO no depende del parent de WordPress. Se exporta aparte.
    $category_to_secondary = [];
    $relation_rows = $wpdb->get_results(
        "SELECT source_id, target_id
         FROM {$wpdb->prefix}seo_relations
         WHERE source_type = 'hub_secondary'
           AND target_type = 'product_cat'
           AND relation_type = 'hub_secondary_to_category'
           AND source_id > 0
           AND target_id > 0
         ORDER BY target_id ASC, source_id ASC"
    );

    foreach ( (array) $relation_rows as $relation ) {
        $category_id  = absint( $relation->target_id );
        $secondary_id = absint( $relation->source_id );

        if ( ! isset( $category_to_secondary[ $category_id ] ) ) {
            $category_to_secondary[ $category_id ] = [];
        }

        $category_to_secondary[ $category_id ][] = $secondary_id;
    }

    foreach ( $category_to_secondary as $category_id => $secondary_ids ) {
        $category_to_secondary[ $category_id ] = array_values(
            array_unique( array_filter( array_map( 'absint', $secondary_ids ) ) )
        );
    }

    $filename = 'seo_categories_' . wp_date( 'Ymd_His' ) . '.csv';

    seo_ie_store_log(
        [
            'operacion'  => 'Exportación de categorías',
            'archivo'    => $filename,
            'procesados' => count( $categories ),
            'correctos'  => count( $categories ),
            'errores'    => 0,
            'detalles'   => [
                'WordPress/WooCommerce aporta ID, nombre, slug, padre e imagen (attachment ID + URL). seo_nodes aporta ámbito, excerpt y description. seo_relations aporta hub_secondary_id cuando la asignación es única.',
            ],
        ]
    );

    $output = seo_ie_open_csv_download( $filename );

    seo_ie_write_csv_row(
        $output,
        [
            'category_id',
            'parent_id',
            'hub_secondary_id',
            'titulo',
            'slug',
            'imagen_destacada_id',
            'imagen_destacada',
            'description',
            'excerpt',
            'ambito',
        ]
    );

    foreach ( $categories as $category ) {

        $category_id   = absint( $category->term_id );
        $thumbnail_id  = absint( get_term_meta( $category_id, 'thumbnail_id', true ) );
        $thumbnail_url = 0 < $thumbnail_id ? ( wp_get_attachment_url( $thumbnail_id ) ?: '' ) : '';
        $node_data     = $nodes_by_category[ $category_id ] ?? [
            'ambito'      => '',
            'excerpt'     => '',
            'description' => '',
        ];

        seo_ie_write_csv_row(
            $output,
            [
                $category_id,
                absint( $category->parent ),
                ( isset( $category_to_secondary[ $category_id ] ) && 1 === count( $category_to_secondary[ $category_id ] ) )
                    ? absint( $category_to_secondary[ $category_id ][0] )
                    : '',
                $category->name,
                $category->slug,
                $thumbnail_id ?: '',
                $thumbnail_url,
                $node_data['description'],
                $node_data['excerpt'],
                $node_data['ambito'],
            ]
        );
    }

    fclose( $output );
    exit;
}

/**
 * Backend interno de importación de categorías para la cola automatizada.
 *
 * No se registra en admin_init ni dispone de formulario de importación manual.
 * La cola auditada prepara el contexto interno y llama directamente a esta función.
 *
 * Actualiza o crea categorías, manteniendo ID/nombre/slug/padre e imagen en WordPress
 * y ámbito/excerpt/description en wp_seo_nodes.
 * Si hub_secondary_id está informado, sincroniza la relación estructural única
 * hub_secondary_to_category. Si category_id está vacío, reutiliza primero una
 * categoría existente por slug/nombre y solo crea una nueva si no existe.
 * No elimina categorías.
 *
 * @since 2.0.0
 *
 * @return void
 */
function seo_import_categories_csv() {

    global $wpdb;

    // La importación de categorías queda reservada a la cola automática.
    // Esto evita que un POST manual pueda saltarse la auditoría previa.
    if ( function_exists( 'seo_ie_batch_is_internal' ) && ! seo_ie_batch_is_internal( 'category' ) ) {
        return;
    }

    if ( ! isset( $_POST['seo_import_categories'] ) ) {
        return;
    }

    if ( function_exists( 'seo_ie_batch_guard_manual_import' ) ) {
        seo_ie_batch_guard_manual_import( 'category' );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para importar categorías.', 'seo-system' ) );
    }

    check_admin_referer(
        'seo_import_categories_csv',
        'seo_import_categories_nonce'
    );

    if (
        empty( $_FILES['categories_csv']['tmp_name'] )
        || (
            ! ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'category' ) )
            && ! is_uploaded_file( $_FILES['categories_csv']['tmp_name'] )
        )
    ) {
        wp_die( esc_html__( 'No se ha recibido un CSV de categorías válido.', 'seo-system' ) );
    }

    $handle = fopen( $_FILES['categories_csv']['tmp_name'], 'r' );

    if ( false === $handle ) {
        wp_die( esc_html__( 'No se pudo abrir el CSV de categorías.', 'seo-system' ) );
    }

    $header = seo_ie_read_csv_row( $handle );

    if ( false === $header ) {
        fclose( $handle );
        wp_die( esc_html__( 'El CSV de categorías está vacío.', 'seo-system' ) );
    }

    $header = seo_ie_normalize_csv_header( $header, 'category' );

    if ( ! in_array( 'category_id', $header, true ) ) {
        fclose( $handle );
        wp_die( esc_html__( 'Falta la columna category_id.', 'seo-system' ) );
    }

    $log = [
        'operacion'  => 'Importación de categorías',
        'archivo'    => sanitize_file_name( $_FILES['categories_csv']['name'] ),
        'procesados' => 0,
        'correctos'  => 0,
        'errores'    => 0,
        'detalles'   => [],
    ];

    $line = 1;


//Importacion por lotes
$batch_processed = 0;
$batch_size      = PHP_INT_MAX;


    while ( $batch_processed < $batch_size && false !== ( $csv_row = seo_ie_read_csv_row( $handle ) ) ) {

        $batch_processed++;
        $line++;
        $log['procesados']++;

        $row         = seo_ie_build_csv_row( $header, $csv_row );
 
 $category_id = absint( $row['category_id'] ?? 0 );

/*
 * Si category_id viene vacío se crea una categoría nueva.
 * Si existe, se actualiza.
 */
if ( $category_id > 0 ) {

    $category = get_term( $category_id, 'product_cat' );

    if ( ! $category || is_wp_error( $category ) ) {
        $log['errores']++;
        seo_ie_add_log_detail(
            $log,
            sprintf(
                'Fila %d: categoría %d inexistente.',
                $line,
                $category_id
            )
        );
        continue;
    }

} else {

    $title = sanitize_text_field( $row['titulo'] ?? '' );
    $slug  = sanitize_title( $row['slug'] ?? '' );

    if ( '' === $title && '' === $slug ) {
        $log['errores']++;
        seo_ie_add_log_detail(
            $log,
            sprintf( 'Fila %d: category_id vacío requiere al menos titulo o slug.', $line )
        );
        continue;
    }

    // Reimportación idempotente: primero reutiliza una categoría ya creada.
    $existing = '' !== $slug ? get_term_by( 'slug', $slug, 'product_cat' ) : false;

    if ( ! $existing && '' !== $title ) {
        $existing = get_term_by( 'name', $title, 'product_cat' );
    }

    if ( $existing && ! is_wp_error( $existing ) ) {
        $category_id = absint( $existing->term_id );
        seo_ie_add_log_detail(
            $log,
            sprintf(
                'Fila %d: category_id vacío; reutilizada categoría %d (%s).',
                $line,
                $category_id,
                $existing->name
            )
        );
    } else {
        $insert_args = [];

        if ( '' !== $slug ) {
            $insert_args['slug'] = $slug;
        }

        $new_term = wp_insert_term(
            '' !== $title ? $title : $slug,
            'product_cat',
            $insert_args
        );

        if ( is_wp_error( $new_term ) ) {

            $log['errores']++;

            seo_ie_add_log_detail(
                $log,
                sprintf(
                    'Fila %d: %s',
                    $line,
                    $new_term->get_error_message()
                )
            );

            continue;
        }

        $category_id = absint( $new_term['term_id'] );
        seo_ie_add_log_detail(
            $log,
            sprintf(
                'Fila %d: creada categoría %d (%s).',
                $line,
                $category_id,
                '' !== $title ? $title : $slug
            )
        );
    }
}

 

        $term_data = [];

        if ( array_key_exists( 'titulo', $row ) && '' !== trim( $row['titulo'] ) ) {
            $term_data['name'] = sanitize_text_field( $row['titulo'] );
        }

        if ( array_key_exists( 'slug', $row ) && '' !== trim( $row['slug'] ) ) {
            $term_data['slug'] = sanitize_title( $row['slug'] );
        }

        if ( array_key_exists( 'parent_id', $row ) ) {

            $parent_id = absint( $row['parent_id'] );

            if ( $parent_id === $category_id ) {
                $log['errores']++;
                seo_ie_add_log_detail(
                    $log,
                    sprintf(
                        'Fila %d: la categoría %d no puede ser su propio padre.',
                        $line,
                        $category_id
                    )
                );
                continue;
            }

            if ( 0 < $parent_id ) {

                $parent = get_term( $parent_id, 'product_cat' );

                if ( ! $parent || is_wp_error( $parent ) ) {
                    $log['errores']++;
                    seo_ie_add_log_detail(
                        $log,
                        sprintf(
                            'Fila %d: categoría padre %d inexistente.',
                            $line,
                            $parent_id
                        )
                    );
                    continue;
                }

                if ( term_is_ancestor_of( $category_id, $parent_id, 'product_cat' ) ) {
                    $log['errores']++;
                    seo_ie_add_log_detail(
                        $log,
                        sprintf(
                            'Fila %d: la relación padre produciría un ciclo.',
                            $line
                        )
                    );
                    continue;
                }
            }

            $term_data['parent'] = $parent_id;
        }


if ( ! empty( $term_data ) ) {
            $result = wp_update_term(
                $category_id,
                'product_cat',
                $term_data
            );

            if ( is_wp_error( $result ) ) {
                $log['errores']++;
                seo_ie_add_log_detail(
                    $log,
                    sprintf(
                        'Fila %d, categoría %d: %s',
                        $line,
                        $category_id,
                        $result->get_error_message()
                    )
                );
                continue;
            }
        }

        seo_ie_import_category_thumbnail( $category_id, $row, $line, $log );

        if ( array_key_exists( 'excerpt', $row ) ) {
            $saved_excerpt = seo_ie_upsert_node_value(
                'category',
                $category_id,
                'excerpt',
                $row['excerpt']
            );

            if ( false === $saved_excerpt ) {
                $log['errores']++;
                seo_ie_add_log_detail(
                    $log,
                    sprintf(
                        'Fila %d, categoría %d: no se pudo guardar el excerpt en seo_nodes.',
                        $line,
                        $category_id
                    )
                );
                continue;
            }
        }

        if ( array_key_exists( 'description', $row ) ) {
            $saved_description = seo_ie_upsert_node_value(
                'category',
                $category_id,
                'description',
                $row['description']
            );

            if ( false === $saved_description ) {
                $log['errores']++;
                seo_ie_add_log_detail(
                    $log,
                    sprintf(
                        'Fila %d, categoría %d: no se pudo guardar la description en seo_nodes.',
                        $line,
                        $category_id
                    )
                );
                continue;
            }
        }

        if ( array_key_exists( 'ambito', $row ) ) {

            $raw_ambito = trim( (string) $row['ambito'] );
            $ambito     = seo_ie_normalize_ambito( $raw_ambito );

            if ( '' !== $raw_ambito && '' === $ambito ) {
                $log['errores']++;
                seo_ie_add_log_detail(
                    $log,
                    sprintf(
                        'Fila %d, categoría %d: ámbito no válido «%s».',
                        $line,
                        $category_id,
                        $raw_ambito
                    )
                );
                continue;
            }

            seo_ie_upsert_node_value(
                'category',
                $category_id,
                'ambito',
                $ambito
            );
        }

        /*
         * La relación SEO es independiente del parent de product_cat.
         * Una celda vacía conserva la asignación existente. Un ID positivo
         * sustituye cualquier hub secundario anterior por el indicado.
         */
        if ( array_key_exists( 'hub_secondary_id', $row ) && '' !== trim( (string) $row['hub_secondary_id'] ) ) {

            $hub_secondary_id = absint( $row['hub_secondary_id'] );

            if ( 0 === $hub_secondary_id ) {
                $log['errores']++;
                seo_ie_add_log_detail(
                    $log,
                    sprintf(
                        'Fila %d, categoría %d: hub_secondary_id no válido.',
                        $line,
                        $category_id
                    )
                );
                continue;
            }

            $hub_is_valid = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$wpdb->prefix}seo_nodes n
                     INNER JOIN {$wpdb->posts} p ON p.ID = n.object_id
                     WHERE n.object_id = %d
                       AND n.object_type = 'page'
                       AND n.seo_role = 'hub_secondary'
                       AND n.status = 1
                       AND p.post_type = 'page'",
                    $hub_secondary_id
                )
            );

            if ( 0 === $hub_is_valid ) {
                $log['errores']++;
                seo_ie_add_log_detail(
                    $log,
                    sprintf(
                        'Fila %d, categoría %d: el hub secundario %d no existe o no está activo.',
                        $line,
                        $category_id,
                        $hub_secondary_id
                    )
                );
                continue;
            }

            $relations_table = $wpdb->prefix . 'seo_relations';

            // Primero garantiza el destino nuevo; solo después retira relaciones antiguas.
            $inserted = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$relations_table}
                        (source_type, source_id, target_type, target_id, relation_type)
                     VALUES ('hub_secondary', %d, 'product_cat', %d, 'hub_secondary_to_category')",
                    $hub_secondary_id,
                    $category_id
                )
            );

            if ( false === $inserted ) {
                $log['errores']++;
                seo_ie_add_log_detail(
                    $log,
                    sprintf(
                        'Fila %d, categoría %d: no se pudo garantizar la asignación al hub secundario %d.',
                        $line,
                        $category_id,
                        $hub_secondary_id
                    )
                );
                continue;
            }

            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$relations_table}
                     WHERE target_type = 'product_cat'
                       AND target_id = %d
                       AND relation_type = 'hub_secondary_to_category'
                       AND NOT (source_type = 'hub_secondary' AND source_id = %d)",
                    $category_id,
                    $hub_secondary_id
                )
            );

            if ( false === $deleted ) {
                $log['errores']++;
                seo_ie_add_log_detail(
                    $log,
                    sprintf(
                        'Fila %d, categoría %d: el destino nuevo existe, pero no se pudieron retirar asignaciones estructurales antiguas.',
                        $line,
                        $category_id
                    )
                );
                continue;
            }

            seo_ie_add_log_detail(
                $log,
                sprintf(
                    'Fila %d: categoría %d asignada al hub secundario %d.',
                    $line,
                    $category_id,
                    $hub_secondary_id
                )
            );
        }

        $log['correctos']++;
    }

    fclose( $handle );

    seo_ie_store_log( $log );

    if ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'category' ) ) {
        return $log;
    }

    wp_safe_redirect(
        add_query_arg(
            'seo_ie_imported',
            'categories',
            admin_url( 'admin.php?page=seo-import-export' )
        )
    );
    exit;
}


/**
 * Comprueba que WooCommerce esté disponible para el catálogo V2.
 *
 * @return bool
 */
function seo_ie_product_v2_wc_ready() {
    return function_exists( 'wc_get_product' )
        && function_exists( 'wc_get_product_id_by_sku' )
        && class_exists( 'WC_Product' )
        && class_exists( 'WC_Product_Simple' );
}

/**
 * Convierte un valor CSV en booleano. Devuelve null si no es reconocible.
 *
 * @param mixed $value Valor recibido.
 * @return bool|null
 */
function seo_ie_product_v2_bool( $value ) {
    if ( is_bool( $value ) ) {
        return $value;
    }

    $value = remove_accents( mb_strtolower( trim( (string) $value ) ) );

    if ( '' === $value ) {
        return null;
    }

    if ( in_array( $value, [ '1', 'true', 'yes', 'si', 'sí', 'on', 'activo' ], true ) ) {
        return true;
    }

    if ( in_array( $value, [ '0', 'false', 'no', 'off', 'inactivo' ], true ) ) {
        return false;
    }

    return null;
}

/**
 * Normaliza un decimal de CSV admitiendo coma decimal y separadores de miles.
 *
 * @param mixed     $value Valor recibido.
 * @param bool|null $valid Resultado de validación por referencia.
 * @return string
 */
function seo_ie_product_v2_decimal( $value, &$valid = null ) {
    $valid = true;
    $value = trim( seo_ie_csv_to_utf8( (string) $value ) );

    if ( '' === $value ) {
        return '';
    }

    $value = str_replace( [ "\xc2\xa0", ' ', '€', '$', '£' ], '', $value );
    $last_comma = strrpos( $value, ',' );
    $last_dot   = strrpos( $value, '.' );

    if ( false !== $last_comma && false !== $last_dot ) {
        if ( $last_comma > $last_dot ) {
            $value = str_replace( '.', '', $value );
            $value = str_replace( ',', '.', $value );
        } else {
            $value = str_replace( ',', '', $value );
        }
    } elseif ( false !== $last_comma ) {
        $value = str_replace( ',', '.', $value );
    }

    if ( ! is_numeric( $value ) ) {
        $valid = false;
        return '';
    }

    $number = (float) $value;

    if ( $number < 0 ) {
        $valid = false;
        return '';
    }

    return function_exists( 'wc_format_decimal' )
        ? wc_format_decimal( $number )
        : (string) $number;
}

/**
 * Convierte una lista de IDs o nombres. El separador recomendado es |.
 * También acepta comas y arrays JSON.
 *
 * @param mixed $value Valor recibido.
 * @return string[]
 */
function seo_ie_product_v2_list( $value ) {
    if ( is_array( $value ) ) {
        $items = $value;
    } else {
        $value = trim( seo_ie_csv_to_utf8( (string) $value ) );

        if ( '' === $value ) {
            return [];
        }

        $decoded = null;

        if ( '[' === substr( $value, 0, 1 ) ) {
            $decoded = json_decode( $value, true );
        }

        if ( is_array( $decoded ) ) {
            $items = $decoded;
        } else {
            $items = preg_split( '/\s*[|,]\s*/u', $value );
        }
    }

    $clean = [];

    foreach ( (array) $items as $item ) {
        if ( is_scalar( $item ) ) {
            $item = trim( seo_ie_csv_to_utf8( (string) $item ) );

            if ( '' !== $item ) {
                $clean[ mb_strtolower( $item ) ] = $item;
            }
        }
    }

    return array_values( $clean );
}

/**
 * Convierte una lista de nombres sin interpretar las comas internas como
 * separadores. El exportador oficial usa | entre términos; también se acepta
 * un array JSON para nombres que necesiten una representación inequívoca.
 *
 * @param mixed $value Valor recibido.
 * @return string[]
 */
function seo_ie_product_v2_name_list( $value ) {
    if ( is_array( $value ) ) {
        $items = $value;
    } else {
        $value = trim( seo_ie_csv_to_utf8( (string) $value ) );

        if ( '' === $value ) {
            return [];
        }

        $decoded = null;

        if ( '[' === substr( $value, 0, 1 ) ) {
            $decoded = json_decode( $value, true );
        }

        $items = is_array( $decoded )
            ? $decoded
            : preg_split( '/\s*\|\s*/u', $value );
    }

    $clean = [];

    foreach ( (array) $items as $item ) {
        if ( is_scalar( $item ) ) {
            $item = trim( seo_ie_csv_to_utf8( (string) $item ) );

            if ( '' !== $item ) {
                $clean[ mb_strtolower( $item ) ] = $item;
            }
        }
    }

    return array_values( $clean );
}

/**
 * Codifica JSON estable para una celda CSV.
 *
 * @param mixed $value Valor.
 * @return string
 */
function seo_ie_product_v2_json( $value ) {
    $json = wp_json_encode(
        $value,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    return is_string( $json ) ? $json : '[]';
}

/**
 * Localiza la taxonomía de marcas sin obligar a instalar un plugin concreto.
 *
 * @return string
 */
function seo_ie_product_v2_brand_taxonomy() {
    if ( function_exists( 'seo_proveedores_taxonomia_marca' ) ) {
        return (string) seo_proveedores_taxonomia_marca();
    }

    foreach ( [ 'product_brand', 'pwb-brand', 'yith_product_brand', 'pa_marca', 'pa_brand' ] as $taxonomy ) {
        if ( taxonomy_exists( $taxonomy ) ) {
            return $taxonomy;
        }
    }

    return '';
}

/**
 * Obtiene marca y taxonomía de un producto.
 *
 * @param int $product_id ID del producto.
 * @return array
 */
function seo_ie_product_v2_brand_data( $product_id ) {
    $taxonomy = seo_ie_product_v2_brand_taxonomy();
    $ids      = [];
    $names    = [];

    if ( '' !== $taxonomy ) {
        $terms = wp_get_post_terms( absint( $product_id ), $taxonomy );

        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $ids[]   = absint( $term->term_id );
                $names[] = $term->name;
            }
        }
    }

    if ( empty( $names ) ) {
        $fallback = sanitize_text_field(
            (string) get_post_meta( absint( $product_id ), '_seo_marca_proveedor', true )
        );

        if ( '' !== $fallback ) {
            $names[] = $fallback;
        }
    }

    return [
        'taxonomy' => $taxonomy,
        'ids'      => array_values( array_unique( $ids ) ),
        'names'    => array_values( array_unique( $names ) ),
    ];
}

/**
 * Serializa atributos WooCommerce como JSON estructurado.
 *
 * @param WC_Product $product Producto.
 * @return string
 */
function seo_ie_product_v2_wc_attributes_json( $product ) {
    $payload = [];

    foreach ( (array) $product->get_attributes() as $attribute ) {
        if ( ! $attribute instanceof WC_Product_Attribute ) {
            continue;
        }

        $is_taxonomy = $attribute->is_taxonomy();
        $name        = (string) $attribute->get_name();
        $options     = [];

        if ( $is_taxonomy ) {
            foreach ( (array) $attribute->get_options() as $term_id ) {
                $term = get_term( absint( $term_id ), $name );

                if ( $term && ! is_wp_error( $term ) ) {
                    $options[] = $term->name;
                }
            }
        } else {
            foreach ( (array) $attribute->get_options() as $option ) {
                $option = sanitize_text_field( (string) $option );

                if ( '' !== $option ) {
                    $options[] = $option;
                }
            }
        }

        $payload[] = [
            'name'        => $is_taxonomy && function_exists( 'wc_attribute_label' )
                ? wc_attribute_label( $name, $product )
                : $name,
            'taxonomy'    => $is_taxonomy ? $name : '',
            'is_taxonomy' => $is_taxonomy ? 1 : 0,
            'options'     => array_values( array_unique( $options ) ),
            'visible'     => $attribute->get_visible() ? 1 : 0,
            'variation'   => $attribute->get_variation() ? 1 : 0,
            'position'    => absint( $attribute->get_position() ),
        ];
    }

    return seo_ie_product_v2_json( $payload );
}

/**
 * Valida el JSON de atributos WooCommerce.
 *
 * @param string $encoded JSON.
 * @return array
 */
function seo_ie_product_v2_parse_wc_attributes( $encoded ) {
    $result = [ 'rows' => [], 'errors' => [] ];
    $encoded = trim( seo_ie_csv_to_utf8( (string) $encoded ) );

    if ( '' === $encoded || '[]' === $encoded ) {
        return $result;
    }

    $decoded = json_decode( $encoded, true );

    if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
        $result['errors'][] = 'atributos_wc_json no contiene JSON válido.';
        return $result;
    }

    if ( 100 < count( $decoded ) ) {
        $result['errors'][] = 'atributos_wc_json supera el límite de 100 atributos.';
        return $result;
    }

    foreach ( $decoded as $index => $row ) {
        if ( ! is_array( $row ) ) {
            $result['errors'][] = sprintf( 'Atributo WooCommerce %d no válido.', $index + 1 );
            continue;
        }

        $is_taxonomy = ! empty( $row['is_taxonomy'] );
        $taxonomy    = sanitize_key( $row['taxonomy'] ?? '' );
        $name        = sanitize_text_field( trim( (string) ( $row['name'] ?? '' ) ) );
        $options     = [];

        foreach ( (array) ( $row['options'] ?? [] ) as $option ) {
            $option = sanitize_text_field( trim( (string) $option ) );

            if ( '' !== $option ) {
                $options[ mb_strtolower( $option ) ] = $option;
            }
        }

        if ( $is_taxonomy && ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) ) {
            $result['errors'][] = sprintf(
                'Atributo WooCommerce %d: la taxonomía «%s» no existe.',
                $index + 1,
                $taxonomy
            );
            continue;
        }

        if ( ! $is_taxonomy && '' === $name ) {
            $result['errors'][] = sprintf( 'Atributo WooCommerce %d sin nombre.', $index + 1 );
            continue;
        }

        $result['rows'][] = [
            'name'        => $name,
            'taxonomy'    => $taxonomy,
            'is_taxonomy' => $is_taxonomy,
            'options'     => array_values( $options ),
            'visible'     => ! empty( $row['visible'] ),
            'variation'   => ! empty( $row['variation'] ),
            'position'    => absint( $row['position'] ?? $index ),
        ];
    }

    return $result;
}

/**
 * Construye objetos WC_Product_Attribute a partir de JSON validado.
 *
 * @param array $rows    Filas validadas.
 * @param bool  $dry_run Simulación.
 * @return array|WP_Error
 */
function seo_ie_product_v2_build_wc_attributes( $rows, $dry_run = false ) {
    $attributes = [];

    foreach ( (array) $rows as $row ) {
        $attribute = new WC_Product_Attribute();

        if ( ! empty( $row['is_taxonomy'] ) ) {
            $taxonomy = (string) $row['taxonomy'];
            $term_ids = [];

            foreach ( (array) $row['options'] as $option_name ) {
                $term = get_term_by( 'name', $option_name, $taxonomy );

                if ( ! $term ) {
                    $term = get_term_by( 'slug', sanitize_title( $option_name ), $taxonomy );
                }

                if ( ! $term && ! $dry_run ) {
                    $inserted = wp_insert_term( $option_name, $taxonomy );

                    if ( is_wp_error( $inserted ) ) {
                        return $inserted;
                    }

                    $term = get_term( absint( $inserted['term_id'] ), $taxonomy );
                }

                if ( $term && ! is_wp_error( $term ) ) {
                    $term_ids[] = absint( $term->term_id );
                }
            }

            $attribute_id = function_exists( 'wc_attribute_taxonomy_id_by_name' )
                ? absint( wc_attribute_taxonomy_id_by_name( $taxonomy ) )
                : 0;

            $attribute->set_id( $attribute_id );
            $attribute->set_name( $taxonomy );
            $attribute->set_options( array_values( array_unique( $term_ids ) ) );
        } else {
            $attribute->set_id( 0 );
            $attribute->set_name( $row['name'] );
            $attribute->set_options( array_values( array_unique( (array) $row['options'] ) ) );
        }

        $attribute->set_position( absint( $row['position'] ) );
        $attribute->set_visible( ! empty( $row['visible'] ) );
        $attribute->set_variation( ! empty( $row['variation'] ) );
        $attributes[] = $attribute;
    }

    return $attributes;
}


/**
 * Serializa los atributos SEO en JSON, manteniendo también la columna antigua.
 *
 * @param array  $rows          Filas SQL.
 * @param string $product_scope Ámbito general.
 * @return string
 */
function seo_ie_product_v2_seo_attributes_json( $rows, $product_scope ) {
    $payload = [];

    foreach ( (array) $rows as $row ) {
        // Campo conservado en JSON por compatibilidad con versiones
        // anteriores; la clasificación procede del producto, no del atributo.
        $ambito = seo_ie_normalize_ambito( $product_scope );
        if ( '' === $ambito ) {
            $ambito = 'global';
        }

        $type  = sanitize_key( $row->attribute_type ?? '' );
        $value = sanitize_text_field( (string) ( $row->attribute_value ?? '' ) );

        if ( '' === $type || '' === $value ) {
            continue;
        }

        $payload[] = [
            'ambito' => $ambito,
            'tipo'   => $type,
            'valor'  => $value,
        ];
    }

    return seo_ie_product_v2_json( $payload );
}

/**
 * Valida atributos SEO JSON.
 *
 * @param string $encoded       JSON.
 * @param string $product_scope Ámbito de respaldo.
 * @return array
 */
function seo_ie_product_v2_parse_seo_attributes_json( $encoded, $product_scope ) {
    $result  = [ 'rows' => [], 'errors' => [] ];
    $encoded = trim( seo_ie_csv_to_utf8( (string) $encoded ) );

    if ( '' === $encoded || '[]' === $encoded ) {
        return $result;
    }

    $decoded = json_decode( $encoded, true );

    if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
        $result['errors'][] = 'atributos_seo_json no contiene JSON válido.';
        return $result;
    }

    foreach ( $decoded as $index => $row ) {
        if ( ! is_array( $row ) ) {
            $result['errors'][] = sprintf( 'Atributo SEO %d no válido.', $index + 1 );
            continue;
        }

        $raw_ambito = sanitize_key( remove_accents( mb_strtolower( trim( (string) ( $row['ambito'] ?? $product_scope ) ) ) ) );
        $ambito = 'global' === $raw_ambito ? 'global' : seo_ie_normalize_ambito( $raw_ambito );
        $type   = sanitize_key( $row['tipo'] ?? $row['attribute_type'] ?? '' );
        $value  = sanitize_text_field( trim( (string) ( $row['valor'] ?? $row['attribute_value'] ?? '' ) ) );

        if ( '' === $ambito || '' === $type || '' === $value ) {
            $result['errors'][] = sprintf( 'Atributo SEO %d incompleto.', $index + 1 );
            continue;
        }

        $result['rows'][] = [
            'ambito'          => $ambito,
            'attribute_type'  => $type,
            'attribute_value' => $value,
        ];
    }

    return $result;
}

/**
 * Valida atributos SEO contra el maestro canónico sin escribir datos.
 *
 * Los atributos de tipo término deben existir como término o alias. Para tipos
 * numéricos/rango/boolean se aplican comprobaciones mínimas de forma para evitar
 * que texto arbitrario termine en un campo estructurado.
 *
 * @param array $rows Filas normalizadas del parser de atributos.
 * @return array<int,array<string,string>> Incidencias para el CSV de rechazados.
 */
function seo_ie_product_v2_validate_seo_attributes( $rows ) {
    $issues = [];

    if ( ! function_exists( 'seo_attributes_get_definition' ) ) {
        throw new RuntimeException( 'El servicio canónico de atributos no está disponible.' );
    }

    $counts = [];

    foreach ( (array) $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }

        $type  = sanitize_key( (string) ( $row['attribute_type'] ?? $row['slug'] ?? '' ) );
        $value = sanitize_textarea_field( trim( (string) ( $row['attribute_value'] ?? $row['value'] ?? '' ) ) );

        if ( '' === $type || '' === $value ) {
            $issues[] = [
                'domain' => 'attributes',
                'field'  => $type ?: 'atributo',
                'value'  => $value,
                'reason' => 'El atributo está incompleto.',
            ];
            continue;
        }

        $definition = seo_attributes_get_definition( $type, true );
        if ( ! is_array( $definition ) ) {
            $issues[] = [
                'domain' => 'attributes',
                'field'  => $type,
                'value'  => $value,
                'reason' => sprintf( 'El atributo «%s» no existe o está inactivo en el vocabulario canónico.', $type ),
            ];
            continue;
        }

        $attribute_id = absint( $definition['id'] ?? 0 );
        $counts[ $attribute_id ] = absint( $counts[ $attribute_id ] ?? 0 ) + 1;

        $data_type = sanitize_key( (string) ( $definition['tipo'] ?? 'texto' ) );

        if ( 'termino' === $data_type ) {
            $term = function_exists( 'seo_attributes_resolve_term' )
                ? seo_attributes_resolve_term( $attribute_id, $value )
                : null;

            if ( ! $term ) {
                $issues[] = [
                    'domain' => 'attributes',
                    'field'  => $type,
                    'value'  => $value,
                    'reason' => sprintf( 'El valor «%s» no existe como término o alias activo de «%s».', $value, $type ),
                ];
            }
            continue;
        }

        if ( in_array( $data_type, [ 'numero', 'rango' ], true ) ) {
            if ( ! preg_match( '/[-+]?\d+(?:[\.,]\d+)?/u', $value ) ) {
                $issues[] = [
                    'domain' => 'attributes',
                    'field'  => $type,
                    'value'  => $value,
                    'reason' => sprintf( '«%s» requiere un valor numérico o rango reconocible.', $type ),
                ];
            }
            continue;
        }

        if ( 'boolean' === $data_type ) {
            $normalized_bool = trim( $value );
            $normalized_bool = function_exists( 'mb_strtolower' )
                ? mb_strtolower( $normalized_bool, 'UTF-8' )
                : strtolower( $normalized_bool );
            $normalized_bool = remove_accents( $normalized_bool );
            if ( ! in_array( $normalized_bool, [ '1', '0', 'si', 'no', 'true', 'false', 'yes' ], true ) ) {
                $issues[] = [
                    'domain' => 'attributes',
                    'field'  => $type,
                    'value'  => $value,
                    'reason' => sprintf( '«%s» requiere un valor booleano reconocible.', $type ),
                ];
            }
        }
    }

    /* Un atributo no múltiple no puede recibir varias filas en la misma carga. */
    foreach ( $counts as $attribute_id => $count ) {
        if ( $count < 2 ) {
            continue;
        }

        $definition = null;
        foreach ( (array) $rows as $row ) {
            $candidate = sanitize_key( (string) ( $row['attribute_type'] ?? $row['slug'] ?? '' ) );
            if ( '' === $candidate ) {
                continue;
            }
            $candidate_def = seo_attributes_get_definition( $candidate, true );
            if ( is_array( $candidate_def ) && absint( $candidate_def['id'] ?? 0 ) === absint( $attribute_id ) ) {
                $definition = $candidate_def;
                break;
            }
        }

        if ( is_array( $definition ) && empty( $definition['multiple'] ) ) {
            $issues[] = [
                'domain' => 'attributes',
                'field'  => sanitize_key( (string) ( $definition['slug'] ?? 'atributo' ) ),
                'value'  => (string) $count,
                'reason' => 'El atributo no admite múltiples valores en el maestro canónico.',
            ];
        }
    }

    return $issues;
}

/**
 * Resuelve etiquetas semánticas de producto exclusivamente contra el vocabulario
 * canónico activo. Nunca crea vocabulario.
 *
 * @param array $row Fila CSV normalizada.
 * @return array{groups:array,issues:array,has_values:bool}
 */
function seo_ie_product_v2_resolve_semantic_labels( $row ) {
    $result = [
        'groups'     => [],
        'issues'     => [],
        'has_values' => false,
    ];

    if ( ! function_exists( 'seo_catalog_find_active_vocabulary_term' ) ) {
        throw new RuntimeException( 'El vocabulario semántico canónico no está disponible.' );
    }

    $columns = [
        'tipo'       => [ 'tipo_semantico', 'tipo' ],
        'aplicacion' => [ 'aplicacion' ],
        'plataforma' => [ 'plataforma' ],
        'subtipo'    => [ 'subtipo' ],
    ];

    foreach ( $columns as $group => $candidates ) {
        $raw = null;
        foreach ( $candidates as $column ) {
            if ( array_key_exists( $column, $row ) ) {
                $raw = $row[ $column ];
                break;
            }
        }

        if ( null === $raw || '' === trim( (string) $raw ) ) {
            continue;
        }

        $result['has_values'] = true;
        $values = seo_ie_product_v2_name_list( $raw );

        if ( 'tipo' === $group && 1 !== count( $values ) ) {
            $result['issues'][] = [
                'domain' => 'semantic_labels',
                'field'  => $group,
                'value'  => is_scalar( $raw ) ? (string) $raw : wp_json_encode( $raw ),
                'reason' => 'TIPO debe contener exactamente un valor canónico.',
            ];
            continue;
        }

        $ids = [];
        foreach ( $values as $value ) {
            $term = seo_catalog_find_active_vocabulary_term( $group, $value );
            if ( ! is_array( $term ) ) {
                $result['issues'][] = [
                    'domain' => 'semantic_labels',
                    'field'  => $group,
                    'value'  => $value,
                    'reason' => sprintf( '«%s» no existe o está inactivo en el vocabulario %s.', $value, strtoupper( $group ) ),
                ];
                continue;
            }
            $ids[] = absint( $term['id'] ?? 0 );
        }

        if ( count( $ids ) === count( $values ) ) {
            $result['groups'][ $group ] = array_values( array_unique( array_filter( $ids ) ) );
        }
    }

    $derived_role_from_type = null;
    if ( ! empty( $result['groups']['tipo'] ) ) {
        if ( ! function_exists( 'seo_catalog_get_role_for_type_vocabulary' ) ) {
            throw new RuntimeException( 'No está disponible el mapa canónico TIPO → ROL.' );
        }

        $derived_role_from_type = seo_catalog_get_role_for_type_vocabulary( (int) $result['groups']['tipo'][0] );
        if ( ! is_array( $derived_role_from_type ) || absint( $derived_role_from_type['id'] ?? 0 ) < 1 ) {
            $result['issues'][] = [
                'domain' => 'semantic_labels',
                'field'  => 'tipo',
                'value'  => (string) ( $row['tipo_semantico'] ?? $row['tipo'] ?? '' ),
                'reason' => 'El TIPO existe, pero no tiene un ROL activo asociado en el mapa canónico.',
            ];
            unset( $result['groups']['tipo'] );
        }
    }

    /* ROL es de solo validación: siempre se materializa desde TIPO. */
    if ( array_key_exists( 'rol', $row ) && '' !== trim( (string) $row['rol'] ) ) {
        $result['has_values'] = true;
        $role_values = seo_ie_product_v2_name_list( $row['rol'] );

        if ( 1 !== count( $role_values ) ) {
            $result['issues'][] = [
                'domain' => 'semantic_labels',
                'field'  => 'rol',
                'value'  => (string) $row['rol'],
                'reason' => 'ROL debe contener un único valor y no se importa directamente.',
            ];
        } else {
            $role_term = seo_catalog_find_active_vocabulary_term( 'rol', $role_values[0] );
            if ( ! is_array( $role_term ) ) {
                $result['issues'][] = [
                    'domain' => 'semantic_labels',
                    'field'  => 'rol',
                    'value'  => $role_values[0],
                    'reason' => 'El ROL indicado no existe o está inactivo.',
                ];
            } elseif ( empty( $result['groups']['tipo'] ) ) {
                $result['issues'][] = [
                    'domain' => 'semantic_labels',
                    'field'  => 'rol',
                    'value'  => $role_values[0],
                    'reason' => 'ROL no se importa directamente. Debe venir acompañado de un TIPO válido para poder derivarlo.',
                ];
            } elseif ( ! empty( $result['groups']['tipo'] ) && function_exists( 'seo_catalog_get_role_for_type_vocabulary' ) ) {
                $derived = is_array( $derived_role_from_type )
                    ? $derived_role_from_type
                    : seo_catalog_get_role_for_type_vocabulary( (int) $result['groups']['tipo'][0] );
                if ( ! is_array( $derived ) || absint( $derived['id'] ?? 0 ) !== absint( $role_term['id'] ?? 0 ) ) {
                    $result['issues'][] = [
                        'domain' => 'semantic_labels',
                        'field'  => 'rol',
                        'value'  => $role_values[0],
                        'reason' => 'El ROL indicado no coincide con el ROL canónico derivado del TIPO.',
                    ];
                }
            }
        }
    }

    return $result;
}

/**
 * Resuelve términos existentes de una taxonomía sin crear ninguno.
 *
 * @param string $taxonomy Taxonomía de WordPress.
 * @param mixed  $ids_value IDs recibidos.
 * @param mixed  $names_value Nombres recibidos.
 * @return array{ids:array,issues:array,has_values:bool}
 */
function seo_ie_product_v2_resolve_existing_terms( $taxonomy, $ids_value, $names_value ) {
    $result = [ 'ids' => [], 'issues' => [], 'has_values' => false ];

    foreach ( seo_ie_product_v2_list( $ids_value ) as $raw_id ) {
        $result['has_values'] = true;
        $term_id = absint( $raw_id );
        $term    = $term_id ? get_term( $term_id, $taxonomy ) : null;

        if ( $term && ! is_wp_error( $term ) ) {
            $result['ids'][] = $term_id;
        } else {
            $result['issues'][] = [
                'domain' => 'wc_tags',
                'field'  => $taxonomy,
                'value'  => (string) $raw_id,
                'reason' => sprintf( 'El término ID %d no existe en %s.', $term_id, $taxonomy ),
            ];
        }
    }

    foreach ( seo_ie_product_v2_name_list( $names_value ) as $name ) {
        $result['has_values'] = true;
        $term = get_term_by( 'name', $name, $taxonomy );
        if ( ! $term ) {
            $term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
        }

        if ( $term && ! is_wp_error( $term ) ) {
            $result['ids'][] = absint( $term->term_id );
        } else {
            $result['issues'][] = [
                'domain' => 'wc_tags',
                'field'  => $taxonomy,
                'value'  => $name,
                'reason' => sprintf( '«%s» no existe en %s. El importador seguro no crea términos nuevos.', $name, $taxonomy ),
            ];
        }
    }

    $result['ids'] = array_values( array_unique( array_filter( array_map( 'absint', $result['ids'] ) ) ) );
    return $result;
}

/**
 * Localiza un producto por ID, SKU o slug y detecta identidades en conflicto.
 *
 * @param array $row Fila CSV.
 * @return array
 */
function seo_ie_product_v2_locate( $row ) {

    $result = [
        'product_id' => 0,
        'method'     => '',
        'error'      => '',
        'warnings'   => [],
    ];

    $source_id = absint( $row['product_id'] ?? 0 );

    $sku = function_exists( 'wc_clean' )
        ? wc_clean( (string) ( $row['sku'] ?? '' ) )
        : sanitize_text_field( (string) ( $row['sku'] ?? '' ) );

    $sku = trim( (string) $sku );

    // SKU "0" no se considera un identificador válido.
    if ( '0' === $sku ) {
        $sku = '';
    }

    $slug = sanitize_title( $row['slug'] ?? '' );

    /*
     * 1. product_id tiene prioridad absoluta si existe
     *    y corresponde a un producto.
     */
    if ( 0 < $source_id ) {

        $post = get_post( $source_id );

        if (
            $post instanceof WP_Post
            && 'product' === $post->post_type
        ) {
            $result['product_id'] = $source_id;
            $result['method']     = 'ID';

            /*
             * SKU y slug solo se comprueban como avisos.
             * Nunca bloquean un product_id válido.
             */
            if ( '' !== $sku ) {
                $sku_id = absint(
                    wc_get_product_id_by_sku( $sku )
                );

                if (
                    0 < $sku_id
                    && $sku_id !== $source_id
                ) {
                    $result['warnings'][] = sprintf(
                        'El SKU «%s» pertenece al producto %d, pero se prioriza product_id %d.',
                        $sku,
                        $sku_id,
                        $source_id
                    );
                }
            }

            if ( '' !== $slug ) {
                $slug_ids = get_posts(
                    [
                        'post_type'              => 'product',
                        'post_status'            => 'any',
                        'name'                   => $slug,
                        'posts_per_page'         => 2,
                        'fields'                 => 'ids',
                        'no_found_rows'          => true,
                        'cache_results'          => false,
                        'update_post_meta_cache' => false,
                        'update_post_term_cache' => false,
                    ]
                );

                if (
                    1 === count( $slug_ids )
                    && absint( $slug_ids[0] ) !== $source_id
                ) {
                    $result['warnings'][] = sprintf(
                        'El slug «%s» pertenece al producto %d, pero se prioriza product_id %d.',
                        $slug,
                        absint( $slug_ids[0] ),
                        $source_id
                    );
                } elseif ( 1 < count( $slug_ids ) ) {
                    $result['warnings'][] = sprintf(
                        'El slug «%s» no es único; se prioriza product_id %d.',
                        $slug,
                        $source_id
                    );
                }
            }

            return $result;
        }

        if ( $post instanceof WP_Post ) {
            $result['warnings'][] = sprintf(
                'El ID %d existe, pero no es un producto.',
                $source_id
            );
        } else {
            $result['warnings'][] = sprintf(
                'El product_id %d no existe.',
                $source_id
            );
        }
    }

    /*
     * 2. Si no hay product_id válido, se intenta por SKU.
     */
    if ( '' !== $sku ) {

        $sku_id = absint(
            wc_get_product_id_by_sku( $sku )
        );

        if ( 0 < $sku_id ) {
            $result['product_id'] = $sku_id;
            $result['method']     = 'SKU';

            return $result;
        }
    }

    /*
     * 3. Como último recurso, se intenta por slug único.
     */
    if ( '' !== $slug ) {

        $slug_ids = get_posts(
            [
                'post_type'              => 'product',
                'post_status'            => 'any',
                'name'                   => $slug,
                'posts_per_page'         => 2,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'cache_results'          => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );

        if ( 1 === count( $slug_ids ) ) {
            $result['product_id'] = absint( $slug_ids[0] );
            $result['method']     = 'slug';

            return $result;
        }

        if ( 1 < count( $slug_ids ) ) {
            $result['error'] = sprintf(
                'El slug «%s» no es único.',
                $slug
            );
        }
    }

    return $result;
}

/**
 * Resuelve términos de taxonomía a partir de IDs o nombres.
 *
 * @param string $taxonomy      Taxonomía.
 * @param mixed  $ids_value     IDs.
 * @param mixed  $names_value   Nombres.
 * @param bool   $create_missing Crear términos ausentes.
 * @param bool   $dry_run       Simulación.
 * @return array
 */
function seo_ie_product_v2_resolve_terms( $taxonomy, $ids_value, $names_value, $create_missing, $dry_run ) {
    $result = [ 'ids' => [], 'errors' => [], 'warnings' => [] ];

    foreach ( seo_ie_product_v2_list( $ids_value ) as $raw_id ) {
        $term_id = absint( $raw_id );
        $term    = $term_id ? get_term( $term_id, $taxonomy ) : null;

        if ( $term && ! is_wp_error( $term ) ) {
            $result['ids'][] = $term_id;
        } elseif ( 0 < $term_id ) {
            $result['errors'][] = sprintf( 'El término %d no existe en %s.', $term_id, $taxonomy );
        }
    }

    if ( empty( $result['ids'] ) ) {
        foreach ( seo_ie_product_v2_name_list( $names_value ) as $name ) {
            $term = get_term_by( 'name', $name, $taxonomy );

            if ( ! $term ) {
                $term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
            }

            if ( ! $term && $create_missing && ! $dry_run ) {
                $inserted = wp_insert_term( $name, $taxonomy );

                if ( is_wp_error( $inserted ) ) {
                    $result['errors'][] = $inserted->get_error_message();
                    continue;
                }

                $term = get_term( absint( $inserted['term_id'] ), $taxonomy );
            } elseif ( ! $term && $create_missing && $dry_run ) {
                $result['warnings'][] = sprintf( 'El término «%s» se creará durante la importación en %s.', $name, $taxonomy );
                continue;
            }

            if ( $term && ! is_wp_error( $term ) ) {
                $result['ids'][] = absint( $term->term_id );
            } elseif ( ! $create_missing ) {
                $result['errors'][] = sprintf( 'No se encontró «%s» en %s.', $name, $taxonomy );
            }
        }
    }

    $result['ids'] = array_values( array_unique( array_filter( array_map( 'absint', $result['ids'] ) ) ) );
    return $result;
}

/**
 * Resuelve o importa un adjunto por ID o URL.
 *
 * @param int    $attachment_id ID.
 * @param string $url           URL.
 * @param int    $product_id    Producto padre.
 * @param bool   $dry_run       Simulación.
 * @return int|WP_Error
 */
function seo_ie_product_v2_attachment( $attachment_id, $url, $product_id, $dry_run = false ) {
    $attachment_id = absint( $attachment_id );
    $url           = esc_url_raw( trim( (string) $url ) );

    if ( 0 < $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
        return $attachment_id;
    }

    if ( '' === $url ) {
        return 0;
    }

    $existing = attachment_url_to_postid( $url );

    if ( 0 < $existing ) {
        return absint( $existing );
    }

    if ( ! wp_http_validate_url( $url ) ) {
        return new WP_Error( 'seo_product_invalid_image_url', 'La URL de imagen no es válida.' );
    }

    if ( $dry_run ) {
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    return media_sideload_image( $url, absint( $product_id ), null, 'id' );
}

/**
 * Actualiza o elimina un metadato según la política de celdas vacías.
 *
 * @param int    $product_id  Producto.
 * @param string $meta_key    Clave.
 * @param mixed  $value       Valor.
 * @param bool   $empty_clears Vacíos eliminan.
 * @return void
 */
function seo_ie_product_v2_set_meta( $product_id, $meta_key, $value, $empty_clears ) {
    $value = is_scalar( $value ) ? trim( (string) $value ) : $value;

    if ( '' === $value ) {
        if ( $empty_clears ) {
            delete_post_meta( absint( $product_id ), $meta_key );
        }
        return;
    }

    update_post_meta( absint( $product_id ), $meta_key, $value );
}

/**
 * Exporta todos los productos a CSV.
 *
 * Orígenes:
 *
 * 1. WordPress / WooCommerce:
 *    ID, título, slug, estado, categorías, excerpt, descripción e imagen.
 * 2. Catálogo SEO canónico:
 *    ámbito/rol vigente del producto.
 * 3. Vocabulario canónico de atributos:
 *    wp_sql_atributos + wp_sql_atributos_terminos + wp_sql_product_atributos.
 *
 * Los atributos se serializan así:
 * herramienta:potencia=1500 W | herramienta:peso=8 kg
 *
 * @since 2.0.0
 *
 * @return void
 */
/**
 * Exporta productos a CSV con filtros opcionales.
 *
 * Filtros admitidos:
 * - cluster
 * - hub primario
 * - hub secundario
 * - categoría WooCommerce
 * - estado del producto
 *
 * @since 2.0.0
 *
 * @return void
 */
function seo_export_products_csv() {

    if ( ! isset( $_POST['seo_export_products'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para exportar productos.', 'seo-system' ) );
    }

    check_admin_referer( 'seo_export_products_csv', 'seo_export_products_nonce' );

    if ( ! seo_ie_product_v2_wc_ready() ) {
        wp_die( esc_html__( 'WooCommerce debe estar activo para exportar el catálogo.', 'seo-system' ) );
    }

    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';
    $allowed_statuses = [ 'publish', 'draft', 'pending', 'private' ];
    $selected_statuses = seo_ie_sanitize_text_array(
        $_POST['export_statuses'] ?? $_POST['seo_export_product_statuses'] ?? []
    );
    $selected_statuses = array_values( array_intersect( $selected_statuses, $allowed_statuses ) );

    if ( empty( $selected_statuses ) ) {
        $selected_statuses = $allowed_statuses;
    }

    $selected_cluster   = absint( $_POST['export_cluster'] ?? 0 );
    $selected_primary   = absint( $_POST['export_hub_primary'] ?? 0 );
    $selected_secondary = absint( $_POST['export_hub_secondary'] ?? 0 );
    $selected_categories = seo_ie_sanitize_absint_array(
        $_POST['export_categories'] ?? $_POST['seo_export_product_categories'] ?? []
    );

    $relation_rows = $wpdb->get_results(
        "
        SELECT source_id, target_id, relation_type
        FROM {$relations_table}
        WHERE relation_type IN (
            'cluster_to_primary',
            'hub_primary_to_hub_secondary',
            'hub_secondary_to_category'
        )
          AND source_id > 0
          AND target_id > 0
        ORDER BY relation_type ASC, source_id ASC, target_id ASC
        "
    );

    $cluster_to_primary    = [];
    $primary_to_cluster    = [];
    $primary_to_secondary  = [];
    $secondary_to_primary  = [];
    $secondary_to_category = [];
    $category_to_secondary = [];

    foreach ( $relation_rows as $relation ) {
        $source_id = absint( $relation->source_id );
        $target_id = absint( $relation->target_id );

        if ( 'cluster_to_primary' === $relation->relation_type ) {
            $cluster_to_primary[ $source_id ][] = $target_id;
            $primary_to_cluster[ $target_id ][] = $source_id;
        } elseif ( 'hub_primary_to_hub_secondary' === $relation->relation_type ) {
            $primary_to_secondary[ $source_id ][] = $target_id;
            $secondary_to_primary[ $target_id ][] = $source_id;
        } elseif ( 'hub_secondary_to_category' === $relation->relation_type ) {
            $secondary_to_category[ $source_id ][] = $target_id;
            $category_to_secondary[ $target_id ][] = $source_id;
        }
    }

    foreach ( [ &$cluster_to_primary, &$primary_to_cluster, &$primary_to_secondary, &$secondary_to_primary, &$secondary_to_category, &$category_to_secondary ] as &$map ) {
        foreach ( $map as $key => $values ) {
            $map[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', (array) $values ) ) ) );
        }
    }
    unset( $map );

    $hierarchy_was_selected = 0 < $selected_cluster || 0 < $selected_primary || 0 < $selected_secondary;
    $hierarchy_categories   = [];

    if ( 0 < $selected_secondary ) {
        $hierarchy_categories = $secondary_to_category[ $selected_secondary ] ?? [];
    } elseif ( 0 < $selected_primary ) {
        foreach ( $primary_to_secondary[ $selected_primary ] ?? [] as $secondary_id ) {
            $hierarchy_categories = array_merge( $hierarchy_categories, $secondary_to_category[ $secondary_id ] ?? [] );
        }
    } elseif ( 0 < $selected_cluster ) {
        foreach ( $cluster_to_primary[ $selected_cluster ] ?? [] as $primary_id ) {
            foreach ( $primary_to_secondary[ $primary_id ] ?? [] as $secondary_id ) {
                $hierarchy_categories = array_merge( $hierarchy_categories, $secondary_to_category[ $secondary_id ] ?? [] );
            }
        }
    }

    $hierarchy_categories = array_values( array_unique( array_filter( array_map( 'absint', $hierarchy_categories ) ) ) );

    if ( $hierarchy_was_selected && ! empty( $selected_categories ) ) {
        $category_filter = array_values( array_intersect( $hierarchy_categories, $selected_categories ) );
    } elseif ( $hierarchy_was_selected ) {
        $category_filter = $hierarchy_categories;
    } else {
        $category_filter = $selected_categories;
    }

    $query_args = [
        'post_type'              => 'product',
        'posts_per_page'         => -1,
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'cache_results'          => false,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => true,
        'post_status'            => $selected_statuses,
    ];

    if ( $hierarchy_was_selected && empty( $category_filter ) ) {
        $query_args['post__in'] = [ 0 ];
    } elseif ( ! empty( $category_filter ) ) {
        $query_args['tax_query'] = [
            [
                'taxonomy'         => 'product_cat',
                'field'            => 'term_id',
                'terms'            => $category_filter,
                'include_children' => true,
                'operator'         => 'IN',
            ],
        ];
    }

    $posts = get_posts( $query_args );

    /*
     * El CSV conserva la cabecera pública "ambito", pero para productos su
     * valor sale del ROL canónico (TIPO -> ROL) y no del nodo legacy.
     */
    $canonical_roles_by_product = [];
    if ( function_exists( 'seo_catalog_get_product_roles' ) ) {
        $canonical_roles_by_product = seo_catalog_get_product_roles(
            array_map( 'absint', wp_list_pluck( $posts, 'ID' ) )
        );
    }

    $attribute_rows = function_exists('seo_attributes_get_rows_for_products')
        ? seo_attributes_get_rows_for_products(null)
        : [];
    $attributes_by_product = [];

    foreach ( $attribute_rows as $attribute_row ) {
        $attributes_by_product[ absint( $attribute_row->product_id ) ][] = $attribute_row;
    }

    $classification_titles = [];
    $get_title = static function ( $post_id, $fallback ) use ( &$classification_titles ) {
        $post_id = absint( $post_id );

        if ( isset( $classification_titles[ $post_id ] ) ) {
            return $classification_titles[ $post_id ];
        }

        $post = get_post( $post_id );
        $classification_titles[ $post_id ] = $post instanceof WP_Post
            ? $post->post_title
            : sprintf( '%s #%d', $fallback, $post_id );

        return $classification_titles[ $post_id ];
    };

    $columns = [
        'schema_version', 'product_id', 'sku', 'tipo_producto', 'titulo', 'slug', 'url', 'estado',
        'destacado', 'visibilidad_catalogo', 'cluster', 'hub_primario', 'hub_secundario',
        'categorias_ids', 'categorias', 'etiquetas_wc_ids', 'etiquetas_wc',
        'marca_taxonomia', 'marca_ids', 'marca', 'fabricante', 'proveedor',
        'proveedor_id_externo', 'proveedor_catalogo_id', 'categoria_proveedor', 'precio_proveedor',
        'ambito', 'atributos_seo_json', 'atributos_seo', 'atributos_wc_json',
        'excerpt', 'description', 'precio_normal', 'precio_rebajado', 'precio_actual', 'moneda',
        'estado_impuesto', 'clase_impuesto', 'gestionar_stock', 'cantidad_stock', 'estado_stock',
        'pedidos_pendientes', 'vendido_individualmente', 'peso', 'longitud', 'anchura', 'altura',
        'virtual', 'descargable', 'clase_envio_id', 'clase_envio', 'imagen_destacada_id',
        'imagen_destacada', 'galeria_ids', 'galeria_urls', 'variaciones_total', 'variaciones_ids',
        'fecha_creacion', 'fecha_modificacion',
    ];

    $filename = 'seo_products_v2_' . wp_date( 'Ymd_His' ) . '.csv';
    seo_ie_store_log(
        [
            'operacion'    => 'Exportación de productos V2',
            'archivo'      => $filename,
            'procesados'   => count( $posts ),
            'correctos'    => count( $posts ),
            'errores'      => 0,
            'advertencias' => 0,
            'detalles'     => [
                'Incluye datos editoriales, comerciales, stock, marca, proveedor, imágenes, etiquetas WooCommerce y atributos.',
                'La columna atributos_seo se conserva para compatibilidad; atributos_seo_json es el formato recomendado.',
                'Las variaciones se listan como inventario, pero no se exportan como filas independientes.',
            ],
        ]
    );

    $output = seo_ie_open_csv_download( $filename );
    seo_ie_write_csv_row( $output, $columns );

    foreach ( $posts as $post ) {
        $product_id = absint( $post->ID );
        $product    = wc_get_product( $product_id );

        if ( ! $product instanceof WC_Product ) {
            continue;
        }

        $category_terms = wp_get_post_terms( $product_id, 'product_cat' );
        $category_ids   = [];
        $category_names = [];
        $cluster_ids    = [];
        $primary_ids    = [];
        $secondary_ids  = [];

        if ( ! is_wp_error( $category_terms ) ) {
            foreach ( $category_terms as $term ) {
                $term_id = absint( $term->term_id );
                $category_ids[]   = $term_id;
                $category_names[] = $term->name;
                $classification_ids = array_merge( [ $term_id ], get_ancestors( $term_id, 'product_cat', 'taxonomy' ) );

                foreach ( $classification_ids as $classification_id ) {
                    foreach ( $category_to_secondary[ absint( $classification_id ) ] ?? [] as $secondary_id ) {
                        $secondary_ids[] = $secondary_id;

                        foreach ( $secondary_to_primary[ $secondary_id ] ?? [] as $primary_id ) {
                            $primary_ids[] = $primary_id;

                            foreach ( $primary_to_cluster[ $primary_id ] ?? [] as $cluster_id ) {
                                $cluster_ids[] = $cluster_id;
                            }
                        }
                    }
                }
            }
        }

        $cluster_ids   = array_values( array_unique( array_filter( array_map( 'absint', $cluster_ids ) ) ) );
        $primary_ids   = array_values( array_unique( array_filter( array_map( 'absint', $primary_ids ) ) ) );
        $secondary_ids = array_values( array_unique( array_filter( array_map( 'absint', $secondary_ids ) ) ) );
        $cluster_names   = array_map( static fn( $id ) => $get_title( $id, 'Cluster' ), $cluster_ids );
        $primary_names   = array_map( static fn( $id ) => $get_title( $id, 'Hub primario' ), $primary_ids );
        $secondary_names = array_map( static fn( $id ) => $get_title( $id, 'Hub secundario' ), $secondary_ids );

        $tag_terms = wp_get_post_terms( $product_id, 'product_tag' );
        $tag_ids   = [];
        $tag_names = [];

        if ( ! is_wp_error( $tag_terms ) ) {
            foreach ( $tag_terms as $term ) {
                $tag_ids[]   = absint( $term->term_id );
                $tag_names[] = $term->name;
            }
        }

        $brand = seo_ie_product_v2_brand_data( $product_id );
        $scope = $canonical_roles_by_product[ $product_id ] ?? '';
        if ( '' === $scope && function_exists( 'seo_catalog_get_product_legacy_ambito' ) ) {
            $scope = seo_catalog_get_product_legacy_ambito( $product_id );
        }
        $seo_attribute_rows = $attributes_by_product[ $product_id ] ?? [];
        $thumbnail_id = absint( get_post_thumbnail_id( $product_id ) );
        $gallery_ids  = array_values( array_unique( array_filter( array_map( 'absint', $product->get_gallery_image_ids() ) ) ) );
        $gallery_urls = [];

        foreach ( $gallery_ids as $gallery_id ) {
            $gallery_url = wp_get_attachment_url( $gallery_id );

            if ( $gallery_url ) {
                $gallery_urls[] = $gallery_url;
            }
        }

        $children = $product->is_type( 'variable' ) ? array_map( 'absint', $product->get_children() ) : [];
        $created  = $product->get_date_created();
        $modified = $product->get_date_modified();

        $row = [
            'schema_version'             => '2.0',
            'product_id'                 => $product_id,
            'sku'                        => $product->get_sku( 'edit' ),
            'tipo_producto'              => $product->get_type(),
            'titulo'                     => $product->get_name( 'edit' ),
            'slug'                       => $post->post_name,
            'url'                        => get_permalink( $product_id ),
            'estado'                     => $product->get_status( 'edit' ),
            'destacado'                  => $product->get_featured( 'edit' ) ? 1 : 0,
            'visibilidad_catalogo'       => $product->get_catalog_visibility( 'edit' ),
            'cluster'                    => implode( ' | ', array_unique( $cluster_names ) ),
            'hub_primario'               => implode( ' | ', array_unique( $primary_names ) ),
            'hub_secundario'             => implode( ' | ', array_unique( $secondary_names ) ),
            'categorias_ids'             => implode( ',', array_unique( $category_ids ) ),
            'categorias'                 => implode( ' | ', array_unique( $category_names ) ),
            'etiquetas_wc_ids'           => implode( ',', array_unique( $tag_ids ) ),
            'etiquetas_wc'               => implode( ' | ', array_unique( $tag_names ) ),
            'marca_taxonomia'            => $brand['taxonomy'],
            'marca_ids'                  => implode( ',', $brand['ids'] ),
            'marca'                      => implode( ' | ', $brand['names'] ),
            'fabricante'                 => get_post_meta( $product_id, '_seo_fabricante', true ),
            'proveedor'                  => get_post_meta( $product_id, '_seo_proveedor', true ),
            'proveedor_id_externo'       => get_post_meta( $product_id, '_seo_proveedor_id_externo', true ),
            'proveedor_catalogo_id'      => get_post_meta( $product_id, '_seo_proveedor_catalogo_id', true ),
            'categoria_proveedor'        => get_post_meta( $product_id, '_seo_categoria_proveedor', true ),
            'precio_proveedor'           => get_post_meta( $product_id, '_seo_precio_proveedor', true ),
            'ambito'                     => $scope,
            'atributos_seo_json'         => seo_ie_product_v2_seo_attributes_json( $seo_attribute_rows, $scope ),
            'atributos_seo'              => seo_ie_serialize_attributes( $seo_attribute_rows, $scope ),
            'atributos_wc_json'          => seo_ie_product_v2_wc_attributes_json( $product ),
            'excerpt'                    => $product->get_short_description( 'edit' ),
            'description'                => $product->get_description( 'edit' ),
            'precio_normal'              => $product->get_regular_price( 'edit' ),
            'precio_rebajado'            => $product->get_sale_price( 'edit' ),
            'precio_actual'              => $product->get_price( 'edit' ),
            'moneda'                     => get_woocommerce_currency(),
            'estado_impuesto'            => $product->get_tax_status( 'edit' ),
            'clase_impuesto'             => $product->get_tax_class( 'edit' ),
            'gestionar_stock'            => $product->get_manage_stock( 'edit' ) ? 1 : 0,
            'cantidad_stock'             => null === $product->get_stock_quantity( 'edit' ) ? '' : $product->get_stock_quantity( 'edit' ),
            'estado_stock'               => $product->get_stock_status( 'edit' ),
            'pedidos_pendientes'         => $product->get_backorders( 'edit' ),
            'vendido_individualmente'    => $product->get_sold_individually( 'edit' ) ? 1 : 0,
            'peso'                       => $product->get_weight( 'edit' ),
            'longitud'                   => $product->get_length( 'edit' ),
            'anchura'                    => $product->get_width( 'edit' ),
            'altura'                     => $product->get_height( 'edit' ),
            'virtual'                    => $product->get_virtual( 'edit' ) ? 1 : 0,
            'descargable'                => $product->get_downloadable( 'edit' ) ? 1 : 0,
            'clase_envio_id'             => $product->get_shipping_class_id( 'edit' ),
            'clase_envio'                => $product->get_shipping_class(),
            'imagen_destacada_id'        => $thumbnail_id ?: '',
            'imagen_destacada'           => $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '',
            'galeria_ids'                => implode( ',', $gallery_ids ),
            'galeria_urls'               => implode( ' | ', $gallery_urls ),
            'variaciones_total'          => count( $children ),
            'variaciones_ids'            => implode( ',', $children ),
            'fecha_creacion'             => $created ? $created->date( 'Y-m-d H:i:s' ) : '',
            'fecha_modificacion'         => $modified ? $modified->date( 'Y-m-d H:i:s' ) : '',
        ];

        seo_ie_write_csv_row(
            $output,
            array_map(
                static function ( $column ) use ( $row ) {
                    return $row[ $column ] ?? '';
                },
                $columns
            )
        );
    }

    fclose( $output );
    exit;
}

/**
 * Indica si la importación de productos se está ejecutando mediante AJAX.
 *
 * @return bool
 */
function seo_ie_product_import_is_ajax() {
    return wp_doing_ajax()
        && 'seo_ie_product_import' === sanitize_key( $_REQUEST['action'] ?? '' );
}

/**
 * Construye la clave del estado temporal de una importación de productos.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @return string
 */
function seo_ie_product_import_state_key( $user_id, $token ) {
    return 'seo_ie_product_import_' . absint( $user_id ) . '_' . sanitize_key( $token );
}

/**
 * Construye la clave del bloqueo breve que evita procesar el mismo lote dos veces.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @return string
 */
function seo_ie_product_import_lock_key( $user_id, $token ) {
    return 'seo_ie_product_import_lock_' . absint( $user_id ) . '_' . sanitize_key( $token );
}


/**
 * Construye la clave que comunica una solicitud de detención a cualquier
 * proceso ya reclamado por Action Scheduler o WP-Cron.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @return string
 */
function seo_ie_product_import_cancel_key( $user_id, $token ) {
    return 'seo_ie_product_import_cancel_' . absint( $user_id ) . '_' . sanitize_key( $token );
}

/**
 * Indica si se ha solicitado detener una importación.
 *
 * La bandera es independiente del estado principal para que un worker que ya
 * hubiese cargado una copia anterior no pueda volver a programar otro lote.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @return bool
 */
function seo_ie_product_import_is_cancel_requested( $user_id, $token ) {
    return (bool) get_transient( seo_ie_product_import_cancel_key( $user_id, $token ) );
}

/**
 * Cancela todos los lotes todavía pendientes de una importación.
 *
 * Una acción que ya esté ejecutándose no se mata a mitad de una escritura.
 * La bandera de cancelación hará que termine la fila actual y no programe
 * ningún lote posterior.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @return void
 */
function seo_ie_product_import_unschedule( $user_id, $token ) {
    $args  = [ absint( $user_id ), sanitize_key( $token ) ];
    $group = 'seo-system-product-import';
    $hooks = [
        'seo_ie_process_product_import_batch',
        'seo_ie_product_import_watchdog',
    ];

    foreach ( $hooks as $hook ) {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( $hook, $args, $group );
        }

        wp_clear_scheduled_hook( $hook, $args );
    }
}

/**
 * Cierra una importación detenida, conserva el log y libera sus bloqueos.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @param array  $state   Último estado conocido.
 * @param string $message Mensaje final.
 * @return array Estado final almacenado como resultado reciente.
 */
function seo_ie_product_import_finalize_stopped( $user_id, $token, $state = [], $message = '' ) {
    $user_id = absint( $user_id );
    $token   = sanitize_key( $token );
    $latest  = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );

    if ( is_array( $latest ) ) {
        $latest_processed = absint( $latest['log']['procesados'] ?? 0 );
        $state_processed  = absint( $state['log']['procesados'] ?? 0 );

        if ( ! is_array( $state ) || $latest_processed >= $state_processed ) {
            $state = $latest;
        }
    }

    if ( ! is_array( $state ) ) {
        $state = [];
    }

    $log = (array) ( $state['log'] ?? [] );

    if ( empty( $state['stop_logged'] ) ) {
        seo_ie_add_log_detail(
            $log,
            'Importación detenida manualmente. Los productos ya procesados se conservan y no se ejecutarán más lotes.'
        );
        $state['stop_logged'] = 1;
    }

    $state['progress']   = isset( $state['progress'] )
        ? max( 0, min( 99, absint( $state['progress'] ) ) )
        : seo_ie_product_import_progress( $state );
    $state['log']        = $log;
    $state['status']     = 'stopped';
    $state['updated_at'] = time();
    $state['message']    = '' !== $message
        ? sanitize_text_field( $message )
        : 'Importación detenida. Los cambios ya aplicados se conservan.';
    seo_ie_product_import_add_transaction(
        $state,
        'stopped',
        $state['message'],
        [ 'processed' => absint( $log['procesados'] ?? 0 ) ]
    );

    if ( function_exists( 'seo_ie_batch_handle_product_stopped' ) ) {
        seo_ie_batch_handle_product_stopped( $user_id, $state, $log );
        $state['log'] = $log;
    }

    seo_ie_product_import_unschedule( $user_id, $token );
    seo_ie_store_log( $log );
    set_transient(
        seo_ie_product_import_result_key( $user_id, $token ),
        $state,
        HOUR_IN_SECONDS
    );

    delete_transient( seo_ie_product_import_state_key( $user_id, $token ) );
    delete_transient( seo_ie_product_import_lock_key( $user_id, $token ) );
    seo_ie_product_import_clear_active( $user_id, $token );

    if ( ! empty( $state['path'] ) && is_file( $state['path'] ) ) {
        @unlink( $state['path'] );
    }

    // Se mantiene como lápida durante una hora para bloquear workers antiguos
    // que ya hubiesen cargado el estado antes de pulsar Detener.
    set_transient(
        seo_ie_product_import_cancel_key( $user_id, $token ),
        time(),
        HOUR_IN_SECONDS
    );

    return $state;
}

/**
 * Guarda la importación activa del usuario para poder reanudarla al volver a la pantalla.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @param array  $state   Estado persistido.
 * @return void
 */
function seo_ie_product_import_set_active( $user_id, $token, $state ) {
    update_user_meta(
        absint( $user_id ),
        'seo_ie_active_product_import',
        [
            'token'      => sanitize_key( $token ),
            'archivo'    => sanitize_file_name( $state['filename'] ?? '' ),
            'actualizado'=> current_time( 'mysql' ),
        ]
    );
}

/**
 * Elimina la referencia de importación activa si corresponde al token indicado.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @return void
 */
function seo_ie_product_import_clear_active( $user_id, $token ) {
    $active = get_user_meta( absint( $user_id ), 'seo_ie_active_product_import', true );

    if ( is_array( $active ) && sanitize_key( $active['token'] ?? '' ) === sanitize_key( $token ) ) {
        delete_user_meta( absint( $user_id ), 'seo_ie_active_product_import' );
    }
}

/**
 * Devuelve una importación activa válida para el usuario actual.
 *
 * @param int $user_id ID del usuario.
 * @return array
 */
function seo_ie_product_import_get_active( $user_id ) {
    $active = get_user_meta( absint( $user_id ), 'seo_ie_active_product_import', true );

    if ( ! is_array( $active ) || empty( $active['token'] ) ) {
        return [];
    }

    $token = sanitize_key( $active['token'] );
    $state = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );

    if ( ! is_array( $state ) || empty( $state['path'] ) || ! is_file( $state['path'] ) ) {
        seo_ie_product_import_clear_active( $user_id, $token );
        return [];
    }

    /*
     * Esta funcion es deliberadamente de solo lectura. Abrir la pantalla o
     * consultar el estado nunca debe iniciar, reanudar ni reprogramar una
     * importacion. Esas operaciones requieren una accion explicita del usuario.
     */

    return [
        'token'    => $token,
        'nonce'    => wp_create_nonce( 'seo_import_products_batch' ),
        'archivo'  => sanitize_file_name( $state['filename'] ?? $active['archivo'] ?? '' ),
        'log'      => (array) ( $state['log'] ?? [] ),
        'status'       => sanitize_key( $state['status'] ?? 'processing' ),
        'progreso'     => seo_ie_product_import_progress( $state ),
        'diagnostics'  => seo_ie_product_import_diagnostics( $user_id, $token, $state ),
        'transactions' => array_slice( (array) ( $state['transactions'] ?? [] ), -20 ),
    ];
}

/**
 * Calcula un porcentaje aproximado mediante el desplazamiento en bytes del CSV.
 *
 * @param array $state Estado de importación.
 * @return int
 */
function seo_ie_product_import_progress( $state ) {
    $path   = (string) ( $state['path'] ?? '' );
    $offset = absint( $state['offset'] ?? 0 );
    $size   = is_file( $path ) ? (int) filesize( $path ) : 0;

    if ( 0 >= $size ) {
        return 0;
    }

    return max( 0, min( 99, (int) floor( ( $offset / $size ) * 100 ) ) );
}

/**
 * Devuelve el resultado de un lote al navegador sin recargar la pantalla.
 *
 * @param string $status Estado: processing, busy o completed.
 * @param string $token  Token de importación.
 * @param array  $state  Estado persistido.
 * @param array  $extra  Datos adicionales.
 * @return void
 */
function seo_ie_product_import_ajax_response( $status, $token, $state, $extra = [] ) {
    $payload = array_merge(
        [
            'status'    => sanitize_key( $status ),
            'token'     => sanitize_key( $token ),
            'nonce'     => wp_create_nonce( 'seo_import_products_batch' ),
            'log'       => (array) ( $state['log'] ?? [] ),
            'progress'  => 'completed' === $status
                ? 100
                : ( isset( $state['progress'] )
                    ? max( 0, min( 99, absint( $state['progress'] ) ) )
                    : seo_ie_product_import_progress( $state ) ),
            'processed' => absint( $state['log']['procesados'] ?? 0 ),
            'correctos' => absint( $state['log']['correctos'] ?? 0 ),
            'errores'      => absint( $state['log']['errores'] ?? 0 ),
            'diagnostics'  => seo_ie_product_import_diagnostics(
                absint( $state['user_id'] ?? get_current_user_id() ),
                $token,
                $state
            ),
            'transactions' => array_slice( (array) ( $state['transactions'] ?? [] ), -20 ),
        ],
        (array) $extra
    );

    wp_send_json_success( $payload );
}


/**
 * Construye la clave del resultado reciente de una importación.
 *
 * Permite que la pantalla confirme la finalización aunque el estado activo
 * ya se haya eliminado y el archivo temporal se haya borrado.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @return string
 */
function seo_ie_product_import_result_key( $user_id, $token ) {
    return 'seo_ie_product_import_result_' . absint( $user_id ) . '_' . sanitize_key( $token );
}


/**
 * Añade una entrada compacta al historial técnico de una importación.
 *
 * Se conservan las últimas 60 entradas para poder diagnosticar la cadena de
 * lotes sin hacer crecer indefinidamente los transients ni el usermeta.
 *
 * @param array  $state   Estado por referencia.
 * @param string $event   Código del evento.
 * @param string $message Descripción legible.
 * @param array  $context Datos escalares adicionales.
 * @return void
 */
function seo_ie_product_import_add_transaction( &$state, $event, $message = '', $context = [] ) {
    if ( ! is_array( $state ) ) {
        $state = [];
    }

    $transactions = (array) ( $state['transactions'] ?? [] );
    $entry = [
        'time'    => time(),
        'event'   => sanitize_key( $event ),
        'message' => sanitize_text_field( $message ),
    ];

    foreach ( (array) $context as $key => $value ) {
        if ( is_scalar( $value ) || null === $value ) {
            $entry[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
        }
    }

    $transactions[] = $entry;

    if ( 60 < count( $transactions ) ) {
        $transactions = array_slice( $transactions, -60 );
    }

    $state['transactions'] = $transactions;
}

/**
 * Guarda un estado técnico sin alterar el desplazamiento ni los contadores.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @param array  $state   Estado completo.
 * @return void
 */
function seo_ie_product_import_store_state( $user_id, $token, $state ) {
    if ( ! is_array( $state ) ) {
        return;
    }

    set_transient(
        seo_ie_product_import_state_key( $user_id, $token ),
        $state,
        DAY_IN_SECONDS
    );
    seo_ie_product_import_set_active( $user_id, $token, $state );
}

/**
 * Devuelve información verificable sobre el estado y la cola de una importación.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @param array  $state   Estado persistido.
 * @return array
 */
function seo_ie_product_import_diagnostics( $user_id, $token, $state ) {
    $user_id = absint( $user_id );
    $token   = sanitize_key( $token );
    $state   = is_array( $state ) ? $state : [];
    $hook    = 'seo_ie_process_product_import_batch';
    $args    = [ $user_id, $token ];
    $group   = 'seo-system-product-import';
    $path    = (string) ( $state['path'] ?? '' );
    $lock_at = absint( get_transient( seo_ie_product_import_lock_key( $user_id, $token ) ) );
    $now     = time();
    $next    = false;

    if ( function_exists( 'as_next_scheduled_action' ) ) {
        $next = as_next_scheduled_action( $hook, $args, $group );
    }

    $wp_cron_next = wp_next_scheduled( $hook, $args );
    $action_ids   = [];

    if ( function_exists( 'as_get_scheduled_actions' ) ) {
        $action_ids = as_get_scheduled_actions(
            [
                'hook'     => $hook,
                'args'     => $args,
                'group'    => $group,
                'per_page' => 10,
                'orderby'  => 'date',
                'order'    => 'DESC',
            ],
            'ids'
        );
        $action_ids = array_values( array_filter( array_map( 'absint', (array) $action_ids ) ) );
    }

    $last_activity = max(
        absint( $state['updated_at'] ?? 0 ),
        absint( $state['last_batch_started_at'] ?? 0 ),
        absint( $state['last_batch_finished_at'] ?? 0 ),
        absint( $state['started_at'] ?? 0 )
    );

    if ( true === $next ) {
        $next_label = 'asíncrona o en ejecución';
    } elseif ( is_numeric( $next ) && 0 < (int) $next ) {
        $next_label = wp_date( 'Y-m-d H:i:s', (int) $next );
    } elseif ( is_numeric( $wp_cron_next ) && 0 < (int) $wp_cron_next ) {
        $next_label = wp_date( 'Y-m-d H:i:s', (int) $wp_cron_next ) . ' (WP-Cron)';
    } else {
        $next_label = 'ninguna';
    }

    return [
        'token'                     => $token,
        'status'                    => sanitize_key( $state['status'] ?? 'unknown' ),
        'file'                      => '' !== $path ? basename( $path ) : '',
        'file_exists'               => '' !== $path && is_file( $path ),
        'file_size'                 => is_file( $path ) ? (int) filesize( $path ) : 0,
        'offset'                    => absint( $state['offset'] ?? 0 ),
        'line'                      => absint( $state['line'] ?? 0 ),
        'progress'                  => seo_ie_product_import_progress( $state ),
        'processed'                 => absint( $state['log']['procesados'] ?? 0 ),
        'batch_number'              => absint( $state['batch_number'] ?? 0 ),
        'last_batch_rows'           => absint( $state['last_batch_rows'] ?? 0 ),
        'last_product_id'           => absint( $state['last_product_id'] ?? 0 ),
        'last_batch_started_at'     => absint( $state['last_batch_started_at'] ?? 0 ),
        'last_batch_finished_at'    => absint( $state['last_batch_finished_at'] ?? 0 ),
        'last_batch_duration'       => (float) ( $state['last_batch_duration'] ?? 0 ),
        'last_batch_target_rows'    => absint( $state['last_batch_target_rows'] ?? 0 ),
        'last_batch_seconds_per_row'=> (float) ( $state['last_batch_seconds_per_row'] ?? 0 ),
        'last_batch_memory_bytes'   => absint( $state['last_batch_memory_bytes'] ?? 0 ),
        'last_batch_memory_ratio'   => (float) ( $state['last_batch_memory_ratio'] ?? 0 ),
        'last_batch_query_count'    => absint( $state['last_batch_query_count'] ?? 0 ),
        'last_batch_time_cutoff'    => ! empty( $state['last_batch_time_budget_reached'] ),
        'last_batch_memory_cutoff'  => ! empty( $state['last_batch_memory_budget_reached'] ),
        'adaptive_next_batch_size'  => absint( $state['adaptive_next_batch_size'] ?? seo_ie_product_import_adaptive_config()['initial_rows'] ),
        'adaptive_next_delay'       => absint( $state['adaptive_next_delay'] ?? 0 ),
        'adaptive_reason'           => sanitize_text_field( $state['adaptive_next_reason'] ?? $state['adaptive_reason'] ?? '' ),
        'adaptive_pressure'         => sanitize_key( $state['adaptive_next_pressure'] ?? $state['adaptive_pressure'] ?? 'baja' ),
        'last_activity_at'          => $last_activity,
        'idle_seconds'              => 0 < $last_activity ? max( 0, $now - $last_activity ) : 0,
        'lock_active'               => 0 < $lock_at,
        'lock_started_at'           => $lock_at,
        'lock_age_seconds'          => 0 < $lock_at ? max( 0, $now - $lock_at ) : 0,
        'callback_count'            => (int) has_action( $hook, 'seo_ie_product_import_background_worker' ),
        'watchdog_callback_count'   => (int) has_action( 'seo_ie_product_import_watchdog', 'seo_ie_product_import_watchdog_worker' ),
        'action_scheduler_ready'    => function_exists( 'as_enqueue_async_action' )
            && (
                did_action( 'action_scheduler_init' )
                || (
                    class_exists( 'Action_Scheduler' )
                    && is_callable( [ 'Action_Scheduler', 'is_initialized' ] )
                    && Action_Scheduler::is_initialized()
                )
            ),
        'scheduled'                 => seo_ie_product_import_is_scheduled( $user_id, $token ),
        'next_scheduled'            => $next_label,
        'known_action_ids'          => $action_ids,
        'last_action_id'            => absint( $state['last_action_id'] ?? 0 ),
        'last_schedule_backend'     => sanitize_key( $state['last_schedule_backend'] ?? '' ),
        'last_schedule_result'      => (string) ( $state['last_schedule_result'] ?? '' ),
        'last_schedule_attempt_at'  => absint( $state['last_schedule_attempt_at'] ?? 0 ),
        'last_schedule_error'       => sanitize_text_field( $state['last_schedule_error'] ?? '' ),
        'last_error'                => sanitize_text_field( $state['last_error'] ?? '' ),
        'last_watchdog_at'          => absint( $state['last_watchdog_at'] ?? 0 ),
        'wp_cron_disabled'          => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
        'php_pid'                   => absint( $state['last_worker_pid'] ?? 0 ),
    ];
}

/**
 * Configuracion del regulador adaptativo para importaciones de productos.
 *
 * El objetivo no es medir la carga global del servidor con un dato poco fiable,
 * sino observar el coste real de los lotes que acabamos de ejecutar. Si el
 * servidor, la base de datos o WooCommerce se ralentizan, el tiempo por fila
 * aumenta y el siguiente lote se reduce automaticamente.
 *
 * @return array<string,int|float>
 */
function seo_ie_product_import_adaptive_config() {
    $config = [
        'min_rows'                  => 2,
        'initial_rows'              => 10,
        'max_rows'                  => 2000,
        'target_seconds'            => 35.0,
        'hard_seconds'              => 100.0,
        'min_rows_before_cutoff'    => 1,
        'memory_soft_ratio'         => 0.72,
        'memory_hard_ratio'         => 0.84,
        'growth_factor'             => 1.60,
        'heavy_delay_seconds'       => 5,
        'critical_delay_seconds'    => 15,
    ];

    $filtered = apply_filters( 'seo_ie_product_import_adaptive_config', $config );
    if ( is_array( $filtered ) ) {
        $config = array_merge( $config, $filtered );
    }

    $config['min_rows']               = max( 1, absint( $config['min_rows'] ) );
    $config['initial_rows']           = max( $config['min_rows'], absint( $config['initial_rows'] ) );
    $config['max_rows']               = max( $config['initial_rows'], absint( $config['max_rows'] ) );
    $config['target_seconds']         = max( 5.0, (float) $config['target_seconds'] );
    $config['hard_seconds']           = max( $config['target_seconds'] + 5.0, (float) $config['hard_seconds'] );
    $config['min_rows_before_cutoff'] = max( 1, absint( $config['min_rows_before_cutoff'] ) );
    $config['memory_soft_ratio']      = min( 0.95, max( 0.20, (float) $config['memory_soft_ratio'] ) );
    $config['memory_hard_ratio']      = min( 0.98, max( $config['memory_soft_ratio'] + 0.05, (float) $config['memory_hard_ratio'] ) );
    $config['growth_factor']          = min( 2.0, max( 1.10, (float) $config['growth_factor'] ) );
    $config['heavy_delay_seconds']    = max( 0, absint( $config['heavy_delay_seconds'] ) );
    $config['critical_delay_seconds'] = max( $config['heavy_delay_seconds'], absint( $config['critical_delay_seconds'] ) );

    return $config;
}

/**
 * Devuelve el limite PHP de memoria en bytes. Cero significa ilimitado o no
 * resoluble y desactiva unicamente la senal de memoria del regulador.
 *
 * @return int
 */
function seo_ie_product_import_memory_limit_bytes() {
    $raw = trim( (string) ini_get( 'memory_limit' ) );
    if ( '' === $raw || '-1' === $raw ) {
        return 0;
    }

    if ( function_exists( 'wp_convert_hr_to_bytes' ) ) {
        $bytes = (int) wp_convert_hr_to_bytes( $raw );
        return 0 < $bytes ? $bytes : 0;
    }

    if ( is_numeric( $raw ) ) {
        return max( 0, (int) $raw );
    }

    $unit  = strtolower( substr( $raw, -1 ) );
    $value = (float) $raw;
    if ( 'g' === $unit ) {
        $value *= 1024;
        $unit = 'm';
    }
    if ( 'm' === $unit ) {
        $value *= 1024;
        $unit = 'k';
    }
    if ( 'k' === $unit ) {
        $value *= 1024;
    }

    return max( 0, (int) $value );
}

/**
 * Proporcion usada de memoria PHP por el proceso actual.
 *
 * @param bool $peak Usa el pico de memoria si esta disponible.
 * @return float
 */
function seo_ie_product_import_memory_ratio( $peak = false ) {
    $limit = seo_ie_product_import_memory_limit_bytes();
    if ( 0 >= $limit ) {
        return 0.0;
    }

    $usage = $peak && function_exists( 'memory_get_peak_usage' )
        ? memory_get_peak_usage( true )
        : memory_get_usage( true );

    return max( 0.0, (float) $usage / (float) $limit );
}

/**
 * Decide cuantas filas debe intentar el siguiente worker.
 *
 * Empieza en 10. A partir de ahi usa el tiempo real por fila del ultimo lote
 * para aproximarse a unos 22 segundos de trabajo, con un maximo de 100 filas.
 * El presupuesto duro de tiempo del worker impide que un lote grande se vuelva
 * peligroso si de repente una fila resulta mucho mas cara.
 *
 * @param array $state Estado persistido.
 * @return array{batch_size:int,time_budget:float,reason:string,pressure:string,seconds_per_row:float}
 */
function seo_ie_product_import_adaptive_plan( $state ) {
    $state  = is_array( $state ) ? $state : [];
    $config = seo_ie_product_import_adaptive_config();

    $previous_target = absint( $state['last_batch_target_rows'] ?? 0 );
    if ( 0 === $previous_target ) {
        $previous_target = $config['initial_rows'];
    }

    $previous_rows     = absint( $state['last_batch_rows'] ?? 0 );
    $duration          = max( 0.0, (float) ( $state['last_batch_duration'] ?? 0 ) );
    $memory_ratio      = max( 0.0, (float) ( $state['last_batch_memory_ratio'] ?? 0 ) );
    $budget_reached    = ! empty( $state['last_batch_time_budget_reached'] );
    $seconds_per_row   = 0.0;
    $next              = $config['initial_rows'];
    $reason            = 'arranque conservador';
    $pressure          = 'baja';

    if ( 0 < $previous_rows && 0.0 < $duration ) {
        $seconds_per_row = $duration / max( 1, $previous_rows );
        $ideal            = (int) floor( $config['target_seconds'] / max( 0.001, $seconds_per_row ) );
        $ideal            = max( $config['min_rows'], min( $config['max_rows'], $ideal ) );
        $next             = $previous_target;

        if ( $memory_ratio >= $config['memory_hard_ratio'] ) {
            $next     = max( $config['min_rows'], min( $ideal, (int) floor( $previous_target * 0.50 ) ) );
            $reason   = 'memoria alta: se reduce el lote';
            $pressure = 'alta';
        } elseif ( $budget_reached || $duration >= $config['hard_seconds'] ) {
            $next     = max( $config['min_rows'], min( $ideal, (int) floor( $previous_target * 0.70 ) ) );
            $reason   = 'lote largo: se reduce al coste observado';
            $pressure = 'alta';
        } elseif ( $memory_ratio >= $config['memory_soft_ratio'] ) {
            $next     = max( $config['min_rows'], min( $previous_target, $ideal ) );
            $reason   = 'memoria en zona preventiva: no se acelera';
            $pressure = 'media';
        } elseif ( $duration > ( $config['target_seconds'] * 1.25 ) ) {
            $next     = max( $config['min_rows'], min( $previous_target, $ideal ) );
            $reason   = 'el lote supera el objetivo: se ajusta a la baja';
            $pressure = 'media';
        } elseif ( $ideal > $previous_target ) {
            $growth_cap = max( $previous_target + 2, (int) ceil( $previous_target * $config['growth_factor'] ) );
            $next       = min( $config['max_rows'], $ideal, $growth_cap );
            $reason     = 'servidor respondiendo bien: se amplia el lote';
        } elseif ( $ideal < $previous_target ) {
            $next     = max( $config['min_rows'], $ideal );
            $reason   = 'se ajusta el lote al tiempo real por producto';
            $pressure = 'media';
        } else {
            $next   = $previous_target;
            $reason = 'ritmo estable';
        }
    }

    return [
        'batch_size'      => max( $config['min_rows'], min( $config['max_rows'], absint( $next ) ) ),
        'time_budget'     => (float) $config['hard_seconds'],
        'reason'          => $reason,
        'pressure'        => $pressure,
        'seconds_per_row' => round( $seconds_per_row, 4 ),
    ];
}

/**
 * Pausa breve solo cuando el lote anterior ya muestra presion. En condiciones
 * normales se encadena de inmediato para no depender del intervalo del cron.
 *
 * @param array $state Estado persistido tras el lote.
 * @return int Segundos antes del siguiente worker.
 */
function seo_ie_product_import_adaptive_delay( $state ) {
    $config       = seo_ie_product_import_adaptive_config();
    $duration     = max( 0.0, (float) ( $state['last_batch_duration'] ?? 0 ) );
    $memory_ratio = max( 0.0, (float) ( $state['last_batch_memory_ratio'] ?? 0 ) );

    if ( $memory_ratio >= $config['memory_hard_ratio'] || $duration >= ( $config['hard_seconds'] * 1.15 ) ) {
        return $config['critical_delay_seconds'];
    }

    if (
        ! empty( $state['last_batch_time_budget_reached'] )
        || $memory_ratio >= $config['memory_soft_ratio']
        || $duration > ( $config['target_seconds'] * 1.35 )
    ) {
        return $config['heavy_delay_seconds'];
    }

    return 0;
}

/**
 * Comprueba si ya existe un lote pendiente o en ejecución.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @return bool
 */
function seo_ie_product_import_is_scheduled( $user_id, $token ) {
    $hook  = 'seo_ie_process_product_import_batch';
    $args  = [ absint( $user_id ), sanitize_key( $token ) ];
    $group = 'seo-system-product-import';

    if ( function_exists( 'as_has_scheduled_action' ) ) {
        return (bool) as_has_scheduled_action( $hook, $args, $group );
    }

    if ( function_exists( 'as_next_scheduled_action' ) ) {
        return false !== as_next_scheduled_action( $hook, $args, $group );
    }

    return false !== wp_next_scheduled( $hook, $args );
}



/**
 * Despierta WP-Cron sin bloquear la peticion actual.
 *
 * @return bool
 */
/**if ( ! function_exists( 'seo_ie_product_import_kick_cron' ) ) {
    function seo_ie_product_import_kick_cron() {
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            return false;
        }

        if ( function_exists( 'spawn_cron' ) ) {
            return (bool) spawn_cron( time() );
        }

        return false;
    }
}
*/




/**
 * Programa un evento WP-Cron de respaldo para una accion gestionada
 * principalmente por Action Scheduler.
 *
 * El bloqueo propio del importador impide que dos workers escriban la misma
 * fila simultaneamente si ambos motores llegan a activarse cerca en el tiempo.
 *
 * @param string $hook  Hook que debe ejecutarse.
 * @param array  $args  Argumentos del hook.
 * @param int    $delay Retraso minimo en segundos.
 * @return bool
 */
/**if ( ! function_exists( 'seo_ie_product_import_schedule_wp_fallback' ) ) {
    function seo_ie_product_import_schedule_wp_fallback( $hook, $args, $delay = 60 ) {
        $hook  = sanitize_key( $hook );
        $args  = array_values( (array) $args );
        $delay = max( 30, absint( $delay ) );

        if ( '' === $hook ) {
            return false;
        }

        if ( false !== wp_next_scheduled( $hook, $args ) ) {
            return true;
        }

        $scheduled = wp_schedule_single_event(
            time() + $delay,
            $hook,
            $args,
            true
        );

        if ( is_wp_error( $scheduled ) || true !== $scheduled ) {
            return false;
        }

        seo_ie_product_import_kick_cron();
        return true;
    }
}
*/



/**
 * Programa el siguiente lote en el servidor.
 *
 * Se usa Action Scheduler cuando está disponible en WooCommerce. Como
 * respaldo se utiliza WP-Cron, por lo que es recomendable mantener un cron
 * real del servidor que invoque wp-cron.php periódicamente.
 *
 * @param int    $user_id ID del usuario.
 * @param string $token   Token de importación.
 * @param int    $delay   Retraso en segundos.
 * @param bool   $force   Programa una nueva acción aunque la actual siga ejecutándose.
 * @return bool
 */
function seo_ie_product_import_schedule( $user_id, $token, $delay = 0, $force = false ) {
    $user_id = absint( $user_id );
    $token   = sanitize_key( $token );
    $delay   = max( 0, absint( $delay ) );
    $state   = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );

    if ( is_array( $state ) ) {
        $state['last_schedule_attempt_at'] = time();
        $state['last_schedule_error']      = '';
        seo_ie_product_import_add_transaction(
            $state,
            'schedule_attempt',
            'Se intenta programar el siguiente lote.',
            [ 'delay' => $delay, 'force' => $force ? 1 : 0 ]
        );
    }

    $finish = static function ( $result, $backend, $action_id, $error = '' ) use ( $user_id, $token, &$state ) {
        if ( is_array( $state ) ) {
            $state['last_schedule_backend'] = sanitize_key( $backend );
            $state['last_schedule_result']  = $result ? 'ok' : 'error';
            $state['last_action_id']        = absint( $action_id );
            $state['last_schedule_error']   = sanitize_text_field( $error );
            seo_ie_product_import_add_transaction(
                $state,
                $result ? 'schedule_ok' : 'schedule_error',
                $result ? 'El siguiente lote quedó programado.' : 'No se pudo programar el siguiente lote.',
                [
                    'backend'   => $backend,
                    'action_id' => absint( $action_id ),
                    'error'     => $error,
                ]
            );
            seo_ie_product_import_store_state( $user_id, $token, $state );
        }

        return (bool) $result;
    };

    if ( 0 === $user_id || '' === $token ) {
        return $finish( false, 'none', 0, 'Usuario o token no válidos.' );
    }

    if ( 0 === (int) has_action( 'seo_ie_process_product_import_batch', 'seo_ie_product_import_background_worker' ) ) {
        return $finish( false, 'none', 0, 'El callback seo_ie_product_import_background_worker no está registrado.' );
    }

    if ( seo_ie_product_import_is_cancel_requested( $user_id, $token ) ) {
        return $finish( false, 'none', 0, 'Existe una solicitud de cancelación.' );
    }

    if (
        is_array( $state )
        && in_array( sanitize_key( $state['status'] ?? '' ), [ 'stopping', 'stopped' ], true )
    ) {
        return $finish( false, 'none', 0, 'La importación está detenida o deteniéndose.' );
    }

    if ( ! $force && seo_ie_product_import_is_scheduled( $user_id, $token ) ) {
        return $finish( true, 'existing', absint( $state['last_action_id'] ?? 0 ) );
    }

    $hook  = 'seo_ie_process_product_import_batch';
    $args  = [ $user_id, $token ];
    $group = 'seo-system-product-import';

    $action_scheduler_ready = function_exists( 'as_enqueue_async_action' )
        && (
            did_action( 'action_scheduler_init' )
            || (
                class_exists( 'Action_Scheduler' )
                && is_callable( [ 'Action_Scheduler', 'is_initialized' ] )
                && Action_Scheduler::is_initialized()
            )
        );

    if ( $action_scheduler_ready ) {
        $unique = ! $force;
        $action_id = 0 < $delay && function_exists( 'as_schedule_single_action' )
            ? as_schedule_single_action( time() + $delay, $hook, $args, $group, $unique, 10 )
            : as_enqueue_async_action( $hook, $args, $group, $unique, 10 );

        if ( 0 < absint( $action_id ) ) {
            if ( function_exists( 'seo_ie_product_import_schedule_wp_fallback' ) ) {
                seo_ie_product_import_schedule_wp_fallback( $hook, $args, $delay + 60 );
            }
            return $finish( true, 'action_scheduler', $action_id );
        }

        return $finish( false, 'action_scheduler', 0, 'Action Scheduler devolvió un ID de acción igual a cero.' );
    }

    if ( ! $force && false !== wp_next_scheduled( $hook, $args ) ) {
        return $finish( true, 'wp_cron_existing', 0 );
    }

    $scheduled = wp_schedule_single_event(
        time() + max( 1, $delay ),
        $hook,
        $args,
        true
    );

    if ( is_wp_error( $scheduled ) ) {
        return $finish( false, 'wp_cron', 0, $scheduled->get_error_message() );
    }

    seo_ie_product_import_kick_cron();
    return $finish( true === $scheduled, 'wp_cron', 0, true === $scheduled ? '' : 'WP-Cron no confirmó la programación.' );
}

/**
 * Comprueba si existe un watchdog pendiente para esta importación.
 *
 * @param int    $user_id ID de usuario.
 * @param string $token   Token.
 * @return bool
 */
function seo_ie_product_import_watchdog_is_scheduled( $user_id, $token ) {
    $hook  = 'seo_ie_product_import_watchdog';
    $args  = [ absint( $user_id ), sanitize_key( $token ) ];
    $group = 'seo-system-product-import';

    if ( function_exists( 'as_has_scheduled_action' ) ) {
        return (bool) as_has_scheduled_action( $hook, $args, $group );
    }

    if ( function_exists( 'as_next_scheduled_action' ) ) {
        return false !== as_next_scheduled_action( $hook, $args, $group );
    }

    return false !== wp_next_scheduled( $hook, $args );
}

/**
 * Programa una comprobación independiente que recupera cadenas de lotes perdidas.
 *
 * @param int    $user_id ID de usuario.
 * @param string $token   Token.
 * @param int    $delay   Segundos.
 * @return bool
 */
function seo_ie_product_import_schedule_watchdog( $user_id, $token, $delay = 60, $force = false ) {
    $user_id = absint( $user_id );
    $token   = sanitize_key( $token );
    $delay   = max( 15, absint( $delay ) );

    if ( 0 === $user_id || '' === $token ) {
        return false;
    }

    if ( ! $force && seo_ie_product_import_watchdog_is_scheduled( $user_id, $token ) ) {
        return true;
    }

    $hook  = 'seo_ie_product_import_watchdog';
    $args  = [ $user_id, $token ];
    $group = 'seo-system-product-import';

    if (
        function_exists( 'as_schedule_single_action' )
        && (
            did_action( 'action_scheduler_init' )
            || (
                class_exists( 'Action_Scheduler' )
                && is_callable( [ 'Action_Scheduler', 'is_initialized' ] )
                && Action_Scheduler::is_initialized()
            )
        )
    ) {
        $action_id = absint(
            as_schedule_single_action(
                time() + $delay,
                $hook,
                $args,
                $group,
                ! $force,
                20
            )
        );

        if ( 0 < $action_id ) {
            if ( function_exists( 'seo_ie_product_import_schedule_wp_fallback' ) ) {
                seo_ie_product_import_schedule_wp_fallback( $hook, $args, $delay + 60 );
            }
            return true;
        }

        return false;
    }

    $scheduled = wp_schedule_single_event( time() + $delay, $hook, $args, true );
    seo_ie_product_import_kick_cron();
    return ! is_wp_error( $scheduled ) && true === $scheduled;
}

/**
 * Watchdog del servidor: si la importación sigue activa pero ha perdido su
 * siguiente acción, vuelve a programarla sin depender del navegador.
 *
 * @param int    $user_id ID de usuario.
 * @param string $token   Token.
 * @return void
 */
function seo_ie_product_import_watchdog_worker( $user_id, $token ) {
    $user_id     = absint( $user_id );
    $token       = sanitize_key( $token );
    $previous_id = get_current_user_id();

    if ( 0 < $user_id ) {
        wp_set_current_user( $user_id );
    }

    try {
        $state = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );

        if ( ! is_array( $state ) ) {
            return;
        }

        $status = sanitize_key( $state['status'] ?? 'processing' );

        if (
            seo_ie_product_import_is_cancel_requested( $user_id, $token )
            || in_array( $status, [ 'completed', 'stopping', 'stopped' ], true )
        ) {
            return;
        }

        $now          = time();
        $lock_key     = seo_ie_product_import_lock_key( $user_id, $token );
        $lock_started = absint( get_transient( $lock_key ) );
        $lock_stale   = 0 < $lock_started && ( $now - $lock_started ) > ( 6 * MINUTE_IN_SECONDS );

        if ( $lock_stale ) {
            delete_transient( $lock_key );
            seo_ie_product_import_add_transaction(
                $state,
                'stale_lock_removed',
                'El watchdog eliminó un bloqueo abandonado.',
                [ 'lock_age' => $now - $lock_started ]
            );
        }

        $scheduled = seo_ie_product_import_is_scheduled( $user_id, $token );
        $state['last_watchdog_at'] = $now;

        if ( ! $scheduled && ! get_transient( $lock_key ) ) {
            $requeued = seo_ie_product_import_schedule( $user_id, $token, 0, true );
            $state    = get_transient( seo_ie_product_import_state_key( $user_id, $token ) ) ?: $state;
            seo_ie_product_import_add_transaction(
                $state,
                $requeued ? 'watchdog_requeued' : 'watchdog_requeue_failed',
                $requeued
                    ? 'El watchdog recuperó una cadena sin siguiente lote.'
                    : 'El watchdog no pudo recuperar la cadena de lotes.'
            );
        } else {
            seo_ie_product_import_add_transaction(
                $state,
                'watchdog_ok',
                $scheduled
                    ? 'El watchdog confirmó que existe una acción pendiente o en ejecución.'
                    : 'El watchdog detectó un lote todavía bloqueado en ejecución.'
            );
        }

        $state['last_watchdog_at'] = time();
        seo_ie_product_import_store_state( $user_id, $token, $state );
        seo_ie_product_import_schedule_watchdog( $user_id, $token, 60, true );
    } finally {
        wp_set_current_user( $previous_id );
    }
}


/**
 * Si la pantalla permanece abierta y la cola no inicia ningún worker, procesa
 * un lote adaptativo en la propia petición de estado.
 *
 * @param int    $user_id Usuario.
 * @param string $token   Token.
 * @param array  $state   Estado.
 * @return array Estado actualizado o resultado final.
 */
function seo_ie_product_import_nudge_stalled( $user_id, $token, $state ) {
    /*
     * Compatibilidad con builds anteriores. La consulta de estado es de solo
     * lectura y nunca ejecuta un worker desde el navegador.
     */
    return $state;
}

/**
 * Guarda un fallo de infraestructura y reintenta el lote de forma limitada.
 *
 * @param int       $user_id ID del usuario.
 * @param string    $token   Token de importación.
 * @param Throwable $error   Error capturado.
 * @return void
 */
function seo_ie_product_import_background_failure( $user_id, $token, $error ) {
    $user_id = absint( $user_id );
    $token   = sanitize_key( $token );
    $state   = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );

    delete_transient( seo_ie_product_import_lock_key( $user_id, $token ) );

    if ( ! is_array( $state ) ) {
        return;
    }

    if (
        seo_ie_product_import_is_cancel_requested( $user_id, $token )
        || in_array( sanitize_key( $state['status'] ?? '' ), [ 'stopping', 'stopped' ], true )
    ) {
        seo_ie_product_import_finalize_stopped( $user_id, $token, $state );
        return;
    }

    $state['retries']    = absint( $state['retries'] ?? 0 ) + 1;
    $state['updated_at'] = time();
    $state['last_error'] = sanitize_text_field( $error->getMessage() );
    $log                 = (array) ( $state['log'] ?? [] );
    $message             = $state['last_error'];
    seo_ie_product_import_add_transaction(
        $state,
        'worker_error',
        $message,
        [ 'retry' => $state['retries'] ]
    );

    seo_ie_add_log_detail(
        $log,
        sprintf(
            'Error temporal del proceso en segundo plano, intento %d: %s',
            $state['retries'],
            $message
        )
    );

    $state['log'] = $log;

    if ( 3 >= $state['retries'] ) {
        $state['status'] = 'retrying';
        set_transient( seo_ie_product_import_state_key( $user_id, $token ), $state, DAY_IN_SECONDS );
        seo_ie_product_import_set_active( $user_id, $token, $state );
        seo_ie_store_log( $log );
        seo_ie_product_import_schedule( $user_id, $token, 30 * $state['retries'], true );
        return;
    }

    $state['status'] = 'failed';
    $log['errores']  = absint( $log['errores'] ?? 0 ) + 1;
    $state['log']    = $log;

    set_transient( seo_ie_product_import_state_key( $user_id, $token ), $state, DAY_IN_SECONDS );
    seo_ie_product_import_set_active( $user_id, $token, $state );
    seo_ie_store_log( $log );

    if ( ! empty( $state['batch_queue_mode'] ) && function_exists( 'seo_ie_batch_store_status' ) ) {
        seo_ie_batch_store_status(
            [
                'enabled'       => false,
                'status'        => 'failed',
                'user_id'       => $user_id,
                'current_file'  => sanitize_file_name( $state['queue_filename'] ?? '' ),
                'entity'        => 'product',
                'message'       => 'El worker de productos fallo tras agotar los reintentos. Libera la importacion activa y reintenta el archivo.',
                'history_event' => true,
            ]
        );
    }
}

/**
 * Ejecuta un lote desde Action Scheduler o WP-Cron.
 *
 * @param int    $user_id ID del usuario que inició la importación.
 * @param string $token   Token de importación.
 * @return void
 */
function seo_ie_product_import_background_worker( $user_id, $token ) {
    $user_id      = absint( $user_id );
    $previous_id  = get_current_user_id();

    // Conserva el contexto del administrador que inició la tarea para logs,
    // auditoría y hooks de terceros ejecutados durante WC_Product::save().
    if ( 0 < $user_id ) {
        wp_set_current_user( $user_id );
    }

    try {
        $token = sanitize_key( $token );
        $state = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );

        if ( is_array( $state ) ) {
            $state['last_worker_started_at'] = time();
            $state['last_worker_pid']        = function_exists( 'getmypid' ) ? absint( getmypid() ) : 0;
            seo_ie_product_import_add_transaction(
                $state,
                'worker_started',
                'Action Scheduler o WP-Cron ha iniciado un worker.',
                [ 'pid' => $state['last_worker_pid'] ]
            );
            seo_ie_product_import_store_state( $user_id, $token, $state );
        }

        if ( seo_ie_product_import_is_cancel_requested( $user_id, $token ) ) {
            $state = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );

            if ( is_array( $state ) ) {
                seo_ie_product_import_finalize_stopped( $user_id, $token, $state );
            }

            return;
        }

        seo_import_products_csv( $user_id, $token, true );
    } catch ( Throwable $error ) {
        seo_ie_product_import_background_failure( $user_id, $token, $error );
    } finally {
        wp_set_current_user( $previous_id );
    }
}

/**
 * Importa productos desde el CSV generado por SEO System.
 *
 * El proceso puede actualizar:
 *
 * - WordPress: título, slug, estado, excerpt y descripción.
 * - WooCommerce: categorías e imagen destacada.
 * - wp_seo_nodes: ámbito legacy cuando procede.
 * - Vocabulario canónico: definiciones/términos/asignaciones de atributos de producto.
 *
 * @since 2.0.0
 *
 * @return void
 */
function seo_import_products_csv( $background_user_id = 0, $background_token = '', $background_mode = false ) {

    $is_background = (bool) $background_mode;

    // Contrato actual: este motor solo puede ejecutarse desde la cola interna.
    // La importacion manual fue retirada para separar producto base de enriquecimiento.
    if ( ! $is_background ) {
        return;
    }
    $is_initial    = ! $is_background && isset( $_POST['seo_import_products'] );
    $is_continue   = ! $is_background && isset( $_POST['seo_import_products_continue'] );
    $is_status     = ! $is_background && isset( $_POST['seo_import_products_status'] );
    $is_resume     = ! $is_background && isset( $_POST['seo_import_products_resume'] );
    $is_stop       = ! $is_background && isset( $_POST['seo_import_products_stop'] );
    $is_reset      = ! $is_background && isset( $_POST['seo_import_products_reset'] );

    if ( ! $is_background && ! $is_initial && ! $is_continue && ! $is_status && ! $is_resume && ! $is_stop && ! $is_reset ) {
        return;
    }

    $user_id = $is_background ? absint( $background_user_id ) : get_current_user_id();

    if ( $is_background ) {
        if ( 0 === $user_id || ! user_can( $user_id, 'manage_options' ) ) {
            throw new RuntimeException( 'El usuario que inició la importación ya no tiene permisos suficientes.' );
        }
    } elseif ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para importar productos.', 'seo-system' ) );
    }

    if ( ! seo_ie_product_v2_wc_ready() ) {
        if ( $is_background ) {
            throw new RuntimeException( 'WooCommerce no está disponible para continuar la importación.' );
        }

        wp_die( esc_html__( 'WooCommerce debe estar activo para importar productos.', 'seo-system' ) );
    }

    $batch_size = absint( seo_ie_product_import_adaptive_config()['initial_rows'] ); // Fallback; se recalcula antes de cada lote.
    $token      = $is_background ? sanitize_key( $background_token ) : '';
    $state      = [];
    $is_ajax    = seo_ie_product_import_is_ajax();
    $lock_key   = '';

    if ( $is_status || $is_continue || $is_resume || $is_stop || $is_reset ) {
        if ( $is_continue ) {
            check_admin_referer( 'seo_import_products_batch', 'seo_import_products_batch_nonce' );
        } else {
            check_admin_referer( 'seo_import_products_csv', 'seo_import_products_nonce' );
        }

        $token  = sanitize_key( $_POST['seo_import_token'] ?? '' );
        $state  = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );
        $result = get_transient( seo_ie_product_import_result_key( $user_id, $token ) );

        /*
         * Recuperación de emergencia para estados bloqueados.
         * Cancela las acciones pendientes y libera inmediatamente el estado
         * si no hay un worker activo. Si hay una fila guardándose, deja la
         * bandera de cancelación y espera a que termine de forma segura.
         */
        if ( $is_reset ) {
            set_transient(
                seo_ie_product_import_cancel_key( $user_id, $token ),
                time(),
                HOUR_IN_SECONDS
            );
            seo_ie_product_import_unschedule( $user_id, $token );

            $lock_key_reset = seo_ie_product_import_lock_key( $user_id, $token );
            $lock_started   = absint( get_transient( $lock_key_reset ) );
            $lock_is_stale  = 0 < $lock_started && ( time() - $lock_started ) > ( 6 * MINUTE_IN_SECONDS );

            if ( $lock_is_stale ) {
                delete_transient( $lock_key_reset );
            }

            if ( ! is_array( $state ) ) {
                $state = is_array( $result ) ? $result : [
                    'user_id' => $user_id,
                    'log'     => [],
                    'status'  => 'stopped',
                ];
            }

            $state['status']     = 'stopping';
            $state['updated_at'] = time();

            if ( empty( $state['log'] ) || ! is_array( $state['log'] ) ) {
                $state['log'] = [];
            }

            seo_ie_add_log_detail(
                $state['log'],
                'Se ha solicitado liberar una importación bloqueada. Se cancelan las acciones pendientes y se conserva todo lo ya importado.'
            );

            set_transient(
                seo_ie_product_import_state_key( $user_id, $token ),
                $state,
                DAY_IN_SECONDS
            );
            seo_ie_product_import_set_active( $user_id, $token, $state );
            seo_ie_store_log( $state['log'] );

            if ( ! get_transient( $lock_key_reset ) ) {
                $state = seo_ie_product_import_finalize_stopped(
                    $user_id,
                    $token,
                    $state,
                    'Estado anterior liberado. Ya puedes iniciar una nueva importación.'
                );

                seo_ie_product_import_ajax_response(
                    'stopped',
                    $token,
                    $state,
                    [ 'message' => $state['message'] ]
                );
            }

            seo_ie_product_import_ajax_response(
                'stopping',
                $token,
                $state,
                [ 'message' => 'Se ha cancelado la cola. La liberación terminará cuando concluya la fila que ya estaba en ejecución.' ]
            );
        }

        if ( $is_stop ) {
            set_transient(
                seo_ie_product_import_cancel_key( $user_id, $token ),
                time(),
                HOUR_IN_SECONDS
            );
            seo_ie_product_import_unschedule( $user_id, $token );

            if ( ! is_array( $state ) ) {
                $state = is_array( $result ) ? $result : [
                    'user_id' => $user_id,
                    'log'     => [],
                    'status'  => 'stopped',
                ];

                $state = seo_ie_product_import_finalize_stopped(
                    $user_id,
                    $token,
                    $state,
                    'La importación ya no estaba ejecutando un lote y se ha cerrado su estado pendiente.'
                );

                seo_ie_product_import_ajax_response(
                    'stopped',
                    $token,
                    $state,
                    [ 'message' => $state['message'] ]
                );
            }

            $log = (array) ( $state['log'] ?? [] );

            if ( empty( $state['stop_requested_logged'] ) ) {
                seo_ie_add_log_detail(
                    $log,
                    'Se ha solicitado detener la importación. Se cancelan los lotes pendientes; el lote en ejecución terminará de forma segura.'
                );
                $state['stop_requested_logged'] = 1;
            }

            $state['log']        = $log;
            $state['status']     = 'stopping';
            $state['updated_at'] = time();
            set_transient( seo_ie_product_import_state_key( $user_id, $token ), $state, DAY_IN_SECONDS );
            seo_ie_product_import_set_active( $user_id, $token, $state );
            seo_ie_store_log( $log );

            if ( ! get_transient( seo_ie_product_import_lock_key( $user_id, $token ) ) ) {
                $state = seo_ie_product_import_finalize_stopped( $user_id, $token, $state );

                seo_ie_product_import_ajax_response(
                    'stopped',
                    $token,
                    $state,
                    [ 'message' => $state['message'] ]
                );
            }

            seo_ie_product_import_ajax_response(
                'stopping',
                $token,
                $state,
                [ 'message' => 'Detención solicitada. Se está terminando de forma segura la fila que ya estaba en ejecución.' ]
            );
        }

        if ( ! is_array( $state ) ) {
            if ( is_array( $result ) ) {
                seo_ie_product_import_ajax_response(
                    sanitize_key( $result['status'] ?? 'completed' ),
                    $token,
                    $result,
                    [ 'message' => (string) ( $result['message'] ?? 'Importación completada.' ) ]
                );
            }

            if ( $is_ajax ) {
                wp_send_json_error( [ 'message' => 'No existe una importación recuperable con ese token.' ], 404 );
            }

            wp_die( esc_html__( 'No existe una importación recuperable con ese token.', 'seo-system' ) );
        }

        if ( absint( $state['user_id'] ?? 0 ) !== $user_id ) {
            if ( $is_ajax ) {
                wp_send_json_error( [ 'message' => 'La importación no pertenece al usuario actual.' ], 403 );
            }

            wp_die( esc_html__( 'La importación no pertenece al usuario actual.', 'seo-system' ) );
        }

        if ( empty( $state['path'] ) || ! is_file( $state['path'] ) ) {
            $state = seo_ie_product_import_finalize_stopped(
                $user_id,
                $token,
                $state,
                'El archivo temporal anterior ya no existe. Se ha liberado el bloqueo y puedes iniciar una nueva importación.'
            );

            if ( $is_ajax ) {
                seo_ie_product_import_ajax_response(
                    'stopped',
                    $token,
                    $state,
                    [ 'message' => $state['message'] ]
                );
            }

            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'       => 'seo-import-export',
                        'seo_ie_tab' => 'wordpress',
                    ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        if (
            $is_status
            && 'stopping' === sanitize_key( $state['status'] ?? '' )
            && ! get_transient( seo_ie_product_import_lock_key( $user_id, $token ) )
        ) {
            $state = seo_ie_product_import_finalize_stopped( $user_id, $token, $state );

            seo_ie_product_import_ajax_response(
                'stopped',
                $token,
                $state,
                [ 'message' => $state['message'] ]
            );
        }

        if ( $is_status && 'completed' === sanitize_key( $state['status'] ?? '' ) ) {
            seo_ie_product_import_ajax_response(
                'completed',
                $token,
                $state,
                [ 'message' => (string) ( $state['message'] ?? 'Importación completada.' ) ]
            );
        }

        if ( $is_resume ) {
            delete_transient( seo_ie_product_import_cancel_key( $user_id, $token ) );
            $state['status']     = 'processing';
            $state['retries']    = 0;
            $state['last_error'] = '';
            seo_ie_product_import_add_transaction(
                $state,
                'manual_resume',
                'El usuario ha solicitado reanudar la importacion.'
            );
            set_transient( seo_ie_product_import_state_key( $user_id, $token ), $state, DAY_IN_SECONDS );
            seo_ie_product_import_set_active( $user_id, $token, $state );

            $scheduled = seo_ie_product_import_schedule( $user_id, $token, 1, true );
            if ( $scheduled ) {
                seo_ie_product_import_schedule_watchdog( $user_id, $token, 60, true );
            } else {
                $state['status']     = 'failed';
                $state['last_error'] = 'No se pudo programar la reanudacion solicitada.';
                seo_ie_product_import_store_state( $user_id, $token, $state );
            }

            $state = get_transient( seo_ie_product_import_state_key( $user_id, $token ) ) ?: $state;
        }

        /* Consultar el estado nunca modifica ni reprograma la cola. */
        $state  = get_transient( seo_ie_product_import_state_key( $user_id, $token ) ) ?: $state;
        $status = sanitize_key( $state['status'] ?? 'processing' );

        if ( ! in_array( $status, [ 'failed', 'stopping', 'stopped' ], true ) ) {
            $status = 'processing';
        }

        $messages = [
            'failed'     => 'La cola se ha detenido después de varios reintentos. Puedes reintentarla o cancelarla.',
            'stopping'   => 'Detención solicitada. Se está terminando de forma segura la fila que ya estaba en ejecución.',
            'stopped'    => 'Importación detenida. Los cambios ya aplicados se conservan.',
            'processing' => 'La importación continúa en el servidor; puedes cerrar esta pestaña.',
        ];

        seo_ie_product_import_ajax_response(
            $status,
            $token,
            $state,
            [ 'message' => $messages[ $status ] ]
        );
    }

    if ( $is_initial ) {
        check_admin_referer( 'seo_import_products_csv', 'seo_import_products_nonce' );

        if ( function_exists( 'seo_ie_batch_guard_manual_import' ) ) {
            seo_ie_batch_guard_manual_import( 'product' );
        }

        $active_import = seo_ie_product_import_get_active( $user_id );

        if ( ! empty( $active_import ) ) {
            $message = esc_html__( 'Ya existe una importación de productos en curso. Espera a que termine o reanúdala desde esta misma pantalla.', 'seo-system' );

            if ( $is_ajax ) {
                wp_send_json_error( [ 'message' => $message ], 409 );
            }

            wp_die( $message );
        }

        if ( empty( $_FILES['products_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['products_csv']['tmp_name'] ) ) {
            wp_die( esc_html__( 'No se ha recibido un CSV de productos válido.', 'seo-system' ) );
        }

        $upload_dir = wp_upload_dir();
        $temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'seo-import-temp';
        wp_mkdir_p( $temp_dir );

        $client_token = sanitize_key( $_POST['seo_import_client_token'] ?? '' );
        $token = 1 === preg_match( '/^[a-z0-9]{20,64}$/', $client_token )
            ? $client_token
            : strtolower( wp_generate_password( 24, false, false ) );
        $path  = trailingslashit( $temp_dir ) . 'products-v2-' . $user_id . '-' . sanitize_file_name( $token ) . '.csv';

        if ( ! move_uploaded_file( $_FILES['products_csv']['tmp_name'], $path ) ) {
            wp_die( esc_html__( 'No se pudo guardar temporalmente el CSV.', 'seo-system' ) );
        }

        $handle = fopen( $path, 'r' );

        if ( false === $handle ) {
            @unlink( $path );
            wp_die( esc_html__( 'No se pudo abrir el CSV de productos.', 'seo-system' ) );
        }

        $header = seo_ie_read_csv_row( $handle );

        if ( false === $header ) {
            fclose( $handle );
            @unlink( $path );
            wp_die( esc_html__( 'El CSV de productos está vacío.', 'seo-system' ) );
        }

        $header = seo_ie_normalize_csv_header( $header, 'product' );
        $counts = array_count_values( array_filter( $header, static fn( $value ) => '' !== (string) $value ) );
        $duplicates = array_keys( array_filter( $counts, static fn( $count ) => 1 < $count ) );

        if ( ! empty( $duplicates ) ) {
            fclose( $handle );
            @unlink( $path );
            wp_die(
                sprintf(
                    esc_html__( 'El CSV contiene cabeceras duplicadas después de normalizarlas: %s.', 'seo-system' ),
                    esc_html( implode( ', ', $duplicates ) )
                )
            );
        }

        if ( empty( array_intersect( [ 'product_id', 'sku', 'slug' ], $header ) ) && ! in_array( 'titulo', $header, true ) ) {
            fclose( $handle );
            @unlink( $path );
            wp_die( esc_html__( 'El CSV necesita product_id, SKU, slug o título para identificar o crear productos.', 'seo-system' ) );
        }

        $options = [
            'core'          => ! empty( $_POST['import_product_core'] ),
            'commerce'      => ! empty( $_POST['import_product_commerce'] ),
            'categories'    => ! empty( $_POST['import_product_categories'] ),
            'wc_tags'       => ! empty( $_POST['import_product_wc_tags'] ),
            'brand_provider'=> ! empty( $_POST['import_product_brand_provider'] ),
            'scope'         => ! empty( $_POST['import_product_scope'] ),
            'seo_attributes'=> ! empty( $_POST['import_product_attributes'] ),
            'wc_attributes' => ! empty( $_POST['import_product_wc_attributes'] ),
            'images'        => ! empty( $_POST['import_product_image'] ),
            'create'        => ! empty( $_POST['create_if_missing'] ),
            'force_draft'   => ! empty( $_POST['created_products_as_draft'] ),
            'empty_clears'  => ! empty( $_POST['product_empty_clears'] ),
            'dry_run'       => ! empty( $_POST['product_import_dry_run'] ),
        ];

        $selected_blocks = array_intersect_key(
            $options,
            array_flip( [ 'core', 'commerce', 'categories', 'wc_tags', 'brand_provider', 'scope', 'seo_attributes', 'wc_attributes', 'images' ] )
        );

        if ( ! in_array( true, $selected_blocks, true ) ) {
            fclose( $handle );
            @unlink( $path );
            wp_die( esc_html__( 'Selecciona al menos un bloque de datos para importar.', 'seo-system' ) );
        }

        $state = [
            'path'     => $path,
            'filename' => sanitize_file_name( $_FILES['products_csv']['name'] ),
            'header'   => $header,
            'offset'   => ftell( $handle ),
            'line'     => 1,
            'user_id'  => $user_id,
            'options'  => $options,
            'status'       => 'queued',
            'retries'      => 0,
            'started_at'   => time(),
            'updated_at'   => time(),
            'batch_number' => 0,
            'transactions' => [],
            'log'          => [
                'operacion'    => $options['dry_run'] ? 'Simulación de importación de productos V2' : 'Importación de productos V2',
                'archivo'      => sanitize_file_name( $_FILES['products_csv']['name'] ),
                'procesados'   => 0,
                'correctos'    => 0,
                'creados'      => 0,
                'actualizados' => 0,
                'omitidos'     => 0,
                'errores'      => 0,
                'advertencias' => 0,
                'simulacion'   => $options['dry_run'] ? 1 : 0,
                'detalles'     => [],
            ],
        ];

        seo_ie_product_import_add_transaction(
            $state,
            'upload_validated',
            'El CSV se guardó y sus cabeceras fueron validadas.',
            [
                'file'   => $state['filename'],
                'offset' => $state['offset'],
            ]
        );
        delete_transient( seo_ie_product_import_cancel_key( $user_id, $token ) );
        $state['status'] = 'processing';
        set_transient(
            seo_ie_product_import_state_key( $user_id, $token ),
            $state,
            DAY_IN_SECONDS
        );
        delete_transient( seo_ie_product_import_result_key( $user_id, $token ) );
        seo_ie_product_import_set_active( $user_id, $token, $state );
        fclose( $handle );

        if ( ! seo_ie_product_import_schedule( $user_id, $token, 0 ) ) {
            delete_transient( seo_ie_product_import_state_key( $user_id, $token ) );
            seo_ie_product_import_clear_active( $user_id, $token );
            @unlink( $path );

            if ( $is_ajax ) {
                wp_send_json_error( [ 'message' => 'No se pudo iniciar la cola de importación del servidor.' ], 500 );
            }

            wp_die( esc_html__( 'No se pudo iniciar la cola de importación del servidor.', 'seo-system' ) );
        }

        seo_ie_product_import_schedule_watchdog( $user_id, $token, 60 );
        $state = get_transient( seo_ie_product_import_state_key( $user_id, $token ) ) ?: $state;

        if ( $is_ajax ) {
            seo_ie_product_import_ajax_response(
                'processing',
                $token,
                $state,
                [ 'message' => 'El CSV se ha validado y la importación continúa en el servidor. Puedes cerrar esta pestaña.' ]
            );
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'       => 'seo-import-export',
                    'seo_ie_tab' => 'wordpress',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    } else {
        $state = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );

        if ( ! is_array( $state ) || absint( $state['user_id'] ?? 0 ) !== $user_id || empty( $state['path'] ) || ! is_file( $state['path'] ) ) {
            if ( $is_background ) {
                return;
            }

            wp_die( esc_html__( 'La importación ha caducado o no se puede continuar.', 'seo-system' ) );
        }

        $handle = fopen( $state['path'], 'r' );

        if ( false === $handle ) {
            if ( $is_background ) {
                throw new RuntimeException( 'No se pudo reabrir el CSV de productos.' );
            }

            wp_die( esc_html__( 'No se pudo reabrir el CSV de productos.', 'seo-system' ) );
        }

        fseek( $handle, absint( $state['offset'] ?? 0 ) );
        $header = (array) $state['header'];
    }

    $lock_key = seo_ie_product_import_lock_key( $user_id, $token );

    if ( get_transient( $lock_key ) ) {
        fclose( $handle );

        if ( $is_background ) {
            seo_ie_product_import_schedule( $user_id, $token, 15, true );
            return;
        }

        if ( $is_ajax ) {
            seo_ie_product_import_ajax_response(
                'busy',
                $token,
                $state,
                [ 'message' => 'El servidor todavía está terminando el lote anterior.' ]
            );
        }

        return;
    }

    set_transient( $lock_key, time(), 5 * MINUTE_IN_SECONDS );

    $options                = (array) $state['options'];
    $log                    = (array) $state['log'];
    $adaptive_plan          = seo_ie_product_import_adaptive_plan( $state );
    $batch_size             = absint( $adaptive_plan['batch_size'] );
    $batch_time_budget      = (float) $adaptive_plan['time_budget'];
    $adaptive_config        = seo_ie_product_import_adaptive_config();
    $line                   = absint( $state['line'] ?? 1 );
    $batch_processed        = 0;
    $dry_run                = ! empty( $options['dry_run'] );
    $empty_clears           = ! empty( $options['empty_clears'] );
    $stop_requested         = false;
    $batch_started_at       = microtime( true );
    $batch_number           = absint( $state['batch_number'] ?? 0 ) + 1;
    $last_product_reference = 0;
    $last_row_error         = '';
    $time_budget_reached    = false;
    $memory_budget_reached  = false;
    $queries_before_batch   = function_exists( 'get_num_queries' ) ? absint( get_num_queries() ) : 0;

    $state['status']                 = 'processing';
    $state['last_batch_target_rows'] = $batch_size;
    $state['adaptive_reason']        = sanitize_text_field( $adaptive_plan['reason'] );
    $state['adaptive_pressure']      = sanitize_key( $adaptive_plan['pressure'] );
    $state['batch_number']          = $batch_number;
    $state['last_batch_started_at'] = time();
    $state['last_batch_start_line'] = $line + 1;
    $state['last_worker_pid']       = function_exists( 'getmypid' ) ? absint( getmypid() ) : 0;
    seo_ie_product_import_add_transaction(
        $state,
        'batch_started',
        sprintf( 'Comienza el lote %d con objetivo adaptativo de %d filas.', $batch_number, $batch_size ),
        [
            'line'        => $line + 1,
            'offset'      => absint( $state['offset'] ?? 0 ),
            'pid'         => $state['last_worker_pid'],
            'target_rows' => $batch_size,
            'time_budget' => $batch_time_budget,
            'adaptive'    => 1,
        ]
    );
    seo_ie_product_import_store_state( $user_id, $token, $state );

    while ( $batch_processed < $batch_size ) {
        if ( $batch_processed >= $adaptive_config['min_rows_before_cutoff'] ) {
            $elapsed          = microtime( true ) - $batch_started_at;
            $seconds_per_row  = $elapsed / max( 1, $batch_processed );
            $projected_finish = $elapsed + $seconds_per_row;
            if ( $elapsed >= $batch_time_budget || $projected_finish >= $batch_time_budget ) {
                $time_budget_reached = true;
                break;
            }

            if ( seo_ie_product_import_memory_ratio() >= $adaptive_config['memory_hard_ratio'] ) {
                $memory_budget_reached = true;
                break;
            }
        }

        if ( seo_ie_product_import_is_cancel_requested( $user_id, $token ) ) {
            $stop_requested = true;
            break;
        }

        $csv_row = seo_ie_read_csv_row( $handle );
        if ( false === $csv_row ) {
            break;
        }

        $batch_processed++;
        $line++;

        if ( empty( array_filter( $csv_row, static fn( $value ) => '' !== trim( (string) $value ) ) ) ) {
            continue;
        }

        $log['procesados']++;
        $row = seo_ie_build_csv_row( $header, $csv_row );
        $last_product_reference = absint( $row['product_id'] ?? 0 );

        try {
            $rejection_issues = [];
            $semantic_labels  = null;

            $located = seo_ie_product_v2_locate( $row );

            foreach ( $located['warnings'] as $warning ) {
                seo_ie_add_log_warning( $log, sprintf( 'Fila %d: %s', $line, $warning ) );
            }

            if ( '' !== $located['error'] ) {
                throw new RuntimeException( $located['error'] );
            }

            $product_id = absint( $located['product_id'] );
            $last_product_reference = $product_id ?: $last_product_reference;
            $creating   = 0 === $product_id;

            if ( $creating && empty( $options['create'] ) ) {
                $log['omitidos']++;
                seo_ie_add_log_detail( $log, sprintf( 'Fila %d: omitida; no se encontró el producto y no está activada su creación.', $line ) );
                continue;
            }

            $title = sanitize_text_field( trim( (string) ( $row['titulo'] ?? '' ) ) );

            if ( $creating && '' === $title ) {
                throw new RuntimeException( 'No se puede crear un producto sin título.' );
            }

            $product = $creating ? new WC_Product_Simple() : wc_get_product( $product_id );

            if ( ! $product instanceof WC_Product ) {
                throw new RuntimeException( 'WooCommerce no pudo cargar el producto.' );
            }

            $csv_type = sanitize_key( $row['tipo_producto'] ?? '' );

            if ( $creating && '' !== $csv_type && 'simple' !== $csv_type ) {
                throw new RuntimeException( sprintf( 'La creación automática solo admite productos simples; se recibió «%s».', $csv_type ) );
            }

            if ( ! $creating && '' !== $csv_type && $csv_type !== $product->get_type() ) {
                seo_ie_add_log_warning(
                    $log,
                    sprintf( 'Fila %d, producto %d: el tipo «%s» es informativo; no se cambiará el tipo actual «%s».', $line, $product_id, $csv_type, $product->get_type() )
                );
            }

            $category_resolution = null;
            if ( ! empty( $options['categories'] ) && ( array_key_exists( 'categorias_ids', $row ) || array_key_exists( 'categorias', $row ) ) ) {
                $category_resolution = seo_ie_product_v2_resolve_terms(
                    'product_cat',
                    $row['categorias_ids'] ?? '',
                    $row['categorias'] ?? '',
                    false,
                    $dry_run
                );

                if ( ! empty( $category_resolution['errors'] ) ) {
                    throw new RuntimeException( 'Categorías: ' . implode( ' | ', $category_resolution['errors'] ) );
                }
            }

            $tag_resolution = null;
            if ( ! empty( $options['wc_tags'] ) && ( array_key_exists( 'etiquetas_wc_ids', $row ) || array_key_exists( 'etiquetas_wc', $row ) ) ) {
                if ( ! empty( $state['batch_queue_mode'] ) ) {
                    $tag_resolution = seo_ie_product_v2_resolve_existing_terms(
                        'product_tag',
                        $row['etiquetas_wc_ids'] ?? '',
                        $row['etiquetas_wc'] ?? ''
                    );

                    if ( ! empty( $tag_resolution['issues'] ) ) {
                        $rejection_issues = array_merge( $rejection_issues, (array) $tag_resolution['issues'] );
                        $tag_resolution = null;
                    }
                } else {
                    /* Compatibilidad con el importador histórico fuera de la cola automática. */
                    $tag_resolution = seo_ie_product_v2_resolve_terms(
                        'product_tag',
                        $row['etiquetas_wc_ids'] ?? '',
                        $row['etiquetas_wc'] ?? '',
                        true,
                        true
                    );

                    if ( ! empty( $tag_resolution['errors'] ) ) {
                        throw new RuntimeException( 'Etiquetas WooCommerce: ' . implode( ' | ', $tag_resolution['errors'] ) );
                    }

                    foreach ( $tag_resolution['warnings'] as $warning ) {
                        seo_ie_add_log_warning( $log, sprintf( 'Fila %d: %s', $line, $warning ) );
                    }
                }
            }

            if ( ! empty( $options['labels'] ) || ! empty( $options['vocabulary'] ) ) {
                $semantic_labels = seo_ie_product_v2_resolve_semantic_labels( $row );
                if ( ! empty( $semantic_labels['issues'] ) ) {
                    $rejection_issues = array_merge( $rejection_issues, (array) $semantic_labels['issues'] );
                    // La clasificación semántica se trata como una unidad: si un
                    // valor no es canónico, no se modifica ningún grupo de la fila.
                    $semantic_labels['groups'] = [];
                }
            }

            $brand_resolution = null;
            $brand_taxonomy   = sanitize_key( $row['marca_taxonomia'] ?? '' );

            if ( '' === $brand_taxonomy ) {
                $brand_taxonomy = seo_ie_product_v2_brand_taxonomy();
            }

            if ( ! empty( $options['brand_provider'] ) && '' !== $brand_taxonomy && ( array_key_exists( 'marca_ids', $row ) || array_key_exists( 'marca', $row ) ) ) {
                $brand_resolution = seo_ie_product_v2_resolve_terms(
                    $brand_taxonomy,
                    $row['marca_ids'] ?? '',
                    $row['marca'] ?? '',
                    true,
                    true
                );

                if ( ! empty( $brand_resolution['errors'] ) ) {
                    throw new RuntimeException( 'Marca: ' . implode( ' | ', $brand_resolution['errors'] ) );
                }

                foreach ( $brand_resolution['warnings'] as $warning ) {
                    seo_ie_add_log_warning( $log, sprintf( 'Fila %d: %s', $line, $warning ) );
                }
            }

            $scope = '';
            if ( array_key_exists( 'ambito', $row ) ) {
                $raw_scope = trim( (string) $row['ambito'] );
                $scope     = seo_ie_normalize_ambito( $raw_scope );

                if ( ! empty( $options['scope'] ) && '' !== $raw_scope && '' === $scope ) {
                    throw new RuntimeException( sprintf( 'Ámbito no válido «%s».', $raw_scope ) );
                }
            }

            /*
             * Para productos existentes el ROL canónico prevalece sobre el
             * campo legacy del CSV y sobre seo_nodes/ambito. De este modo una
             * importación antigua no puede romper TIPO -> ROL.
             */
            if ( 0 < $product_id && function_exists( 'seo_catalog_get_product_role' ) ) {
                $canonical_scope = seo_catalog_get_product_role( $product_id, true );

                if ( '' !== $canonical_scope ) {
                    if (
                        '' !== $scope
                        && $scope !== $canonical_scope
                        && ! empty( $options['scope'] )
                    ) {
                        seo_ie_add_log_warning(
                            $log,
                            sprintf(
                                'Fila %d: el ámbito CSV «%s» no coincide con el ROL canónico «%s»; se conserva el ROL canónico.',
                                $line,
                                $scope,
                                $canonical_scope
                            )
                        );
                    }

                    $scope = $canonical_scope;
                }
            }

            if (
                '' === $scope
                && ! empty( $options['seo_attributes'] )
                && (
                    array_key_exists( 'atributos_seo_json', $row )
                    || array_key_exists( 'atributos_seo', $row )
                )
                && 0 < $product_id
                && function_exists( 'seo_catalog_get_product_legacy_ambito' )
            ) {
                $scope = seo_catalog_get_product_legacy_ambito( $product_id );
            }

            $seo_attributes = null;
            if ( ! empty( $options['seo_attributes'] ) ) {
                if ( array_key_exists( 'atributos_seo_json', $row ) ) {
                    $seo_attributes = seo_ie_product_v2_parse_seo_attributes_json( $row['atributos_seo_json'], $scope );
                } elseif ( array_key_exists( 'atributos_seo', $row ) ) {
                    $seo_attributes = seo_ie_parse_attributes( $row['atributos_seo'], $scope );
                }

                if ( is_array( $seo_attributes ) && ! empty( $seo_attributes['errors'] ) ) {
                    if ( ! empty( $state['batch_queue_mode'] ) ) {
                        foreach ( (array) $seo_attributes['errors'] as $attribute_error ) {
                            $rejection_issues[] = [
                                'domain' => 'attributes',
                                'field'  => array_key_exists( 'atributos_seo_json', $row ) ? 'atributos_seo_json' : 'atributos_seo',
                                'value'  => (string) ( $row['atributos_seo_json'] ?? $row['atributos_seo'] ?? '' ),
                                'reason' => sanitize_text_field( (string) $attribute_error ),
                            ];
                        }
                        $seo_attributes = null;
                    } else {
                        throw new RuntimeException( 'Atributos SEO: ' . implode( ' | ', $seo_attributes['errors'] ) );
                    }
                } elseif ( is_array( $seo_attributes ) && ! empty( $state['batch_queue_mode'] ) ) {
                    $attribute_issues = seo_ie_product_v2_validate_seo_attributes( (array) ( $seo_attributes['rows'] ?? [] ) );
                    if ( ! empty( $attribute_issues ) ) {
                        $rejection_issues = array_merge( $rejection_issues, $attribute_issues );
                        // replace_product() sustituye el conjunto completo. Si una
                        // fila contiene un atributo inválido, rechazamos la fila
                        // completa antes de realizar ninguna escritura.
                        $seo_attributes = null;
                    }
                }
            }

            $wc_attributes = null;
            if ( ! empty( $options['wc_attributes'] ) && array_key_exists( 'atributos_wc_json', $row ) ) {
                $parsed_wc_attributes = seo_ie_product_v2_parse_wc_attributes( $row['atributos_wc_json'] );

                if ( ! empty( $parsed_wc_attributes['errors'] ) ) {
                    throw new RuntimeException( 'Atributos WooCommerce: ' . implode( ' | ', $parsed_wc_attributes['errors'] ) );
                }

                $wc_attributes = seo_ie_product_v2_build_wc_attributes( $parsed_wc_attributes['rows'], true );

                if ( is_wp_error( $wc_attributes ) ) {
                    throw new RuntimeException( $wc_attributes->get_error_message() );
                }
            }

            if ( ! empty( $options['commerce'] ) ) {
                if ( array_key_exists( 'sku', $row ) ) {
                    $sku = function_exists( 'wc_clean' ) ? wc_clean( $row['sku'] ) : sanitize_text_field( $row['sku'] );

                    if ( '' !== $sku ) {
                        $sku_owner = absint( wc_get_product_id_by_sku( $sku ) );

                        if ( 0 < $sku_owner && $sku_owner !== $product_id ) {
                            throw new RuntimeException( sprintf( 'El SKU «%s» ya pertenece al producto %d.', $sku, $sku_owner ) );
                        }
                    }
                }

                foreach ( [ 'precio_normal', 'precio_rebajado', 'precio_proveedor', 'peso', 'longitud', 'anchura', 'altura', 'cantidad_stock' ] as $decimal_field ) {
                    if ( array_key_exists( $decimal_field, $row ) && '' !== trim( (string) $row[ $decimal_field ] ) ) {
                        $valid = null;
                        seo_ie_product_v2_decimal( $row[ $decimal_field ], $valid );

                        if ( ! $valid ) {
                            throw new RuntimeException( sprintf( 'El campo %s no contiene un número válido.', $decimal_field ) );
                        }
                    }
                }

                $regular_valid = null;
                $sale_valid    = null;
                $regular       = array_key_exists( 'precio_normal', $row ) ? seo_ie_product_v2_decimal( $row['precio_normal'], $regular_valid ) : '';
                $sale          = array_key_exists( 'precio_rebajado', $row ) ? seo_ie_product_v2_decimal( $row['precio_rebajado'], $sale_valid ) : '';

                if ( '' !== $sale && '' !== $regular && (float) $sale > (float) $regular ) {
                    throw new RuntimeException( 'El precio rebajado no puede ser mayor que el precio normal.' );
                }
            }

            if ( ! empty( $rejection_issues ) ) {
                $written_rejections = function_exists( 'seo_ie_batch_product_record_rejections' )
                    ? seo_ie_batch_product_record_rejections( $state, $line, $row, $rejection_issues, $product_id )
                    : 0;

                if ( $written_rejections > 0 ) {
                    $log['advertencias'] = absint( $log['advertencias'] ?? 0 ) + 1;
                    $log['omitidos']     = absint( $log['omitidos'] ?? 0 ) + 1;
                    $log['rechazados_enriquecimiento'] = absint( $state['rejected_count'] ?? 0 );
                    $log['incidencias_enriquecimiento'] = absint( $state['rejected_issue_count'] ?? 0 );

                    /*
                     * La fila completa se deja intacta. Así el CSV de rechazados
                     * puede corregirse y reimportarse después de dar de alta el
                     * vocabulario necesario, sin haber aplicado cambios parciales.
                     */
                    continue;
                } elseif ( ! empty( $state['batch_queue_mode'] ) ) {
                    throw new RuntimeException( 'Se detectó enriquecimiento no válido, pero no se pudo escribir el CSV de rechazados.' );
                } else {
                    $rejection_messages = array_values(
                        array_filter(
                            array_map(
                                static fn( $issue ) => is_array( $issue ) ? sanitize_text_field( (string) ( $issue['reason'] ?? '' ) ) : '',
                                $rejection_issues
                            )
                        )
                    );
                    throw new RuntimeException( 'Enriquecimiento no válido: ' . implode( ' | ', $rejection_messages ) );
                }
            }

            if ( $dry_run && ! empty( $options['images'] ) ) {
                if (
                    array_key_exists( 'imagen_destacada', $row )
                    && '' !== trim( (string) $row['imagen_destacada'] )
                ) {
                    $preview_attachment = seo_ie_product_v2_attachment(
                        absint( $row['imagen_destacada_id'] ?? 0 ),
                        $row['imagen_destacada'],
                        $product_id,
                        true
                    );

                    if ( is_wp_error( $preview_attachment ) ) {
                        throw new RuntimeException( 'Imagen principal: ' . $preview_attachment->get_error_message() );
                    }
                }

                foreach ( seo_ie_product_v2_list( $row['galeria_urls'] ?? '' ) as $gallery_url ) {
                    $preview_attachment = seo_ie_product_v2_attachment( 0, $gallery_url, $product_id, true );

                    if ( is_wp_error( $preview_attachment ) ) {
                        throw new RuntimeException( 'Galería: ' . $preview_attachment->get_error_message() );
                    }
                }
            }

            if ( $dry_run ) {
                if ( $creating ) {
                    $log['creados']++;
                } else {
                    $log['actualizados']++;
                }
                $log['correctos']++;
                continue;
            }

            if ( $creating ) {
                $product->set_name( $title );
                $product->set_status( 'draft' );
            }

            if ( ! empty( $options['core'] ) ) {
                if ( '' !== $title ) {
                    $product->set_name( $title );
                }

                if ( array_key_exists( 'slug', $row ) && '' !== trim( (string) $row['slug'] ) ) {
                    $product->set_slug( sanitize_title( $row['slug'] ) );
                }

                if ( array_key_exists( 'estado', $row ) ) {
                    $status = sanitize_key( $row['estado'] );

                    if ( in_array( $status, [ 'publish', 'draft', 'pending', 'private' ], true ) ) {
                        if ( ! ( $creating && ! empty( $options['force_draft'] ) ) ) {
                            $product->set_status( $status );
                        }
                    } elseif ( '' !== $status ) {
                        seo_ie_add_log_warning( $log, sprintf( 'Fila %d: estado «%s» no válido; se conserva el actual.', $line, $status ) );
                    }
                }

                if ( array_key_exists( 'excerpt', $row ) && ( '' !== trim( (string) $row['excerpt'] ) || $empty_clears ) ) {
                    $product->set_short_description( wp_kses_post( html_entity_decode( (string) $row['excerpt'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
                }

                if ( array_key_exists( 'description', $row ) && ( '' !== trim( (string) $row['description'] ) || $empty_clears ) ) {
                    $product->set_description( wp_kses_post( html_entity_decode( (string) $row['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
                }

                if ( array_key_exists( 'destacado', $row ) ) {
                    $featured = seo_ie_product_v2_bool( $row['destacado'] );

                    if ( null !== $featured ) {
                        $product->set_featured( $featured );
                    }
                }

                if ( array_key_exists( 'visibilidad_catalogo', $row ) ) {
                    $visibility = sanitize_key( $row['visibilidad_catalogo'] );

                    if ( in_array( $visibility, [ 'visible', 'catalog', 'search', 'hidden' ], true ) ) {
                        $product->set_catalog_visibility( $visibility );
                    }
                }
            }

            if ( ! empty( $options['commerce'] ) ) {
                if ( array_key_exists( 'sku', $row ) && ( '' !== trim( (string) $row['sku'] ) || $empty_clears ) ) {
                    $product->set_sku( function_exists( 'wc_clean' ) ? wc_clean( $row['sku'] ) : sanitize_text_field( $row['sku'] ) );
                }

                if ( array_key_exists( 'precio_normal', $row ) && ( '' !== trim( (string) $row['precio_normal'] ) || $empty_clears ) ) {
                    $valid = null;
                    $product->set_regular_price( seo_ie_product_v2_decimal( $row['precio_normal'], $valid ) );
                }

                if ( array_key_exists( 'precio_rebajado', $row ) && ( '' !== trim( (string) $row['precio_rebajado'] ) || $empty_clears ) ) {
                    $valid = null;
                    $product->set_sale_price( seo_ie_product_v2_decimal( $row['precio_rebajado'], $valid ) );
                }

                if ( array_key_exists( 'estado_impuesto', $row ) ) {
                    $tax_status = sanitize_key( $row['estado_impuesto'] );

                    if ( in_array( $tax_status, [ 'taxable', 'shipping', 'none' ], true ) ) {
                        $product->set_tax_status( $tax_status );
                    }
                }

                if ( array_key_exists( 'clase_impuesto', $row ) && ( '' !== trim( (string) $row['clase_impuesto'] ) || $empty_clears ) ) {
                    $product->set_tax_class( sanitize_title( $row['clase_impuesto'] ) );
                }

                if ( array_key_exists( 'gestionar_stock', $row ) ) {
                    $manage_stock = seo_ie_product_v2_bool( $row['gestionar_stock'] );

                    if ( null !== $manage_stock ) {
                        $product->set_manage_stock( $manage_stock );
                    }
                }

                if ( array_key_exists( 'cantidad_stock', $row ) && ( '' !== trim( (string) $row['cantidad_stock'] ) || $empty_clears ) ) {
                    if ( '' === trim( (string) $row['cantidad_stock'] ) ) {
                        $product->set_stock_quantity( null );
                    } else {
                        $valid = null;
                        $qty   = seo_ie_product_v2_decimal( $row['cantidad_stock'], $valid );
                        $product->set_stock_quantity( (float) $qty );
                    }
                }

                if ( array_key_exists( 'estado_stock', $row ) ) {
                    $stock_status = sanitize_key( $row['estado_stock'] );

                    if ( in_array( $stock_status, [ 'instock', 'outofstock', 'onbackorder' ], true ) ) {
                        $product->set_stock_status( $stock_status );
                    }
                }

                if ( array_key_exists( 'pedidos_pendientes', $row ) ) {
                    $backorders = sanitize_key( $row['pedidos_pendientes'] );

                    if ( in_array( $backorders, [ 'no', 'notify', 'yes' ], true ) ) {
                        $product->set_backorders( $backorders );
                    }
                }

                if ( array_key_exists( 'vendido_individualmente', $row ) ) {
                    $single = seo_ie_product_v2_bool( $row['vendido_individualmente'] );
                    if ( null !== $single ) {
                        $product->set_sold_individually( $single );
                    }
                }

                foreach ( [ 'peso' => 'set_weight', 'longitud' => 'set_length', 'anchura' => 'set_width', 'altura' => 'set_height' ] as $field => $setter ) {
                    if ( array_key_exists( $field, $row ) && ( '' !== trim( (string) $row[ $field ] ) || $empty_clears ) ) {
                        $valid = null;
                        $product->{$setter}( seo_ie_product_v2_decimal( $row[ $field ], $valid ) );
                    }
                }

                if ( array_key_exists( 'virtual', $row ) ) {
                    $virtual = seo_ie_product_v2_bool( $row['virtual'] );
                    if ( null !== $virtual ) {
                        $product->set_virtual( $virtual );
                    }
                }

                if ( array_key_exists( 'descargable', $row ) ) {
                    $downloadable = seo_ie_product_v2_bool( $row['descargable'] );
                    if ( null !== $downloadable ) {
                        $product->set_downloadable( $downloadable );
                    }
                }

                if ( array_key_exists( 'clase_envio_id', $row ) || array_key_exists( 'clase_envio', $row ) ) {
                    $shipping_id = absint( $row['clase_envio_id'] ?? 0 );

                    if ( 0 === $shipping_id && '' !== trim( (string) ( $row['clase_envio'] ?? '' ) ) ) {
                        $shipping_term = get_term_by( 'name', $row['clase_envio'], 'product_shipping_class' );
                        if ( ! $shipping_term ) {
                            $shipping_term = get_term_by( 'slug', sanitize_title( $row['clase_envio'] ), 'product_shipping_class' );
                        }
                        $shipping_id = $shipping_term ? absint( $shipping_term->term_id ) : 0;
                    }

                    if ( 0 < $shipping_id || $empty_clears ) {
                        $product->set_shipping_class_id( $shipping_id );
                    }
                }
            }

            if ( is_array( $tag_resolution ) && empty( $state['batch_queue_mode'] ) ) {
                $tag_resolution = seo_ie_product_v2_resolve_terms(
                    'product_tag',
                    $row['etiquetas_wc_ids'] ?? '',
                    $row['etiquetas_wc'] ?? '',
                    true,
                    false
                );

                if ( ! empty( $tag_resolution['errors'] ) ) {
                    throw new RuntimeException( 'Etiquetas WooCommerce: ' . implode( ' | ', $tag_resolution['errors'] ) );
                }
            }

            /* En la cola automática tag_resolution ya contiene solo IDs existentes. */
            if ( is_array( $brand_resolution ) && '' !== $brand_taxonomy ) {
                $brand_resolution = seo_ie_product_v2_resolve_terms(
                    $brand_taxonomy,
                    $row['marca_ids'] ?? '',
                    $row['marca'] ?? '',
                    true,
                    false
                );

                if ( ! empty( $brand_resolution['errors'] ) ) {
                    throw new RuntimeException( 'Marca: ' . implode( ' | ', $brand_resolution['errors'] ) );
                }
            }

            if ( is_array( $wc_attributes ) ) {
                $wc_attributes = seo_ie_product_v2_build_wc_attributes( $parsed_wc_attributes['rows'], false );

                if ( is_wp_error( $wc_attributes ) ) {
                    throw new RuntimeException( $wc_attributes->get_error_message() );
                }
            }

            if ( is_array( $category_resolution ) ) {
                $has_category_values = '' !== trim( (string) ( $row['categorias_ids'] ?? '' ) ) || '' !== trim( (string) ( $row['categorias'] ?? '' ) );
                if ( $has_category_values || $empty_clears ) {
                    $product->set_category_ids( $category_resolution['ids'] );
                }
            }

            if ( is_array( $wc_attributes ) ) {
                $has_attributes = '' !== trim( (string) ( $row['atributos_wc_json'] ?? '' ) );
                if ( $has_attributes || $empty_clears ) {
                    $product->set_attributes( $wc_attributes );
                }
            }

            $product_id = absint( $product->save() );

            if ( 0 === $product_id ) {
                throw new RuntimeException( 'WooCommerce no devolvió un ID al guardar el producto.' );
            }

            if ( is_array( $tag_resolution ) ) {
                $has_tag_values = '' !== trim( (string) ( $row['etiquetas_wc_ids'] ?? '' ) ) || '' !== trim( (string) ( $row['etiquetas_wc'] ?? '' ) );
                if ( $has_tag_values || $empty_clears ) {
                    $assigned = wp_set_object_terms( $product_id, $tag_resolution['ids'], 'product_tag', false );
                    if ( is_wp_error( $assigned ) ) {
                        throw new RuntimeException( $assigned->get_error_message() );
                    }
                }
            }

            if (
                is_array( $semantic_labels )
                && ! empty( $semantic_labels['has_values'] )
                && ! empty( $semantic_labels['groups'] )
            ) {
                if ( ! function_exists( 'seo_catalog_apply_product_vocabulary_changes' ) ) {
                    throw new RuntimeException( 'El servicio de asignación del vocabulario semántico no está disponible.' );
                }

                $semantic_result = seo_catalog_apply_product_vocabulary_changes(
                    $product_id,
                    (array) $semantic_labels['groups'],
                    'import_export_product_v2'
                );

                if ( empty( $semantic_result['ok'] ) ) {
                    throw new RuntimeException(
                        'Etiquetas semánticas: ' . (string) ( $semantic_result['message'] ?? 'No se pudieron aplicar.' )
                    );
                }
            }

            if ( ! empty( $options['brand_provider'] ) ) {
                if ( is_array( $brand_resolution ) && '' !== $brand_taxonomy ) {
                    $has_brand_values = '' !== trim( (string) ( $row['marca_ids'] ?? '' ) ) || '' !== trim( (string) ( $row['marca'] ?? '' ) );
                    if ( $has_brand_values || $empty_clears ) {
                        $assigned = wp_set_object_terms( $product_id, $brand_resolution['ids'], $brand_taxonomy, false );
                        if ( is_wp_error( $assigned ) ) {
                            throw new RuntimeException( $assigned->get_error_message() );
                        }
                    }
                }

                foreach ( [
                    'marca'                   => '_seo_marca_proveedor',
                    'fabricante'              => '_seo_fabricante',
                    'proveedor'               => '_seo_proveedor',
                    'proveedor_id_externo'    => '_seo_proveedor_id_externo',
                    'proveedor_catalogo_id'   => '_seo_proveedor_catalogo_id',
                    'categoria_proveedor'     => '_seo_categoria_proveedor',
                    'precio_proveedor'        => '_seo_precio_proveedor',
                ] as $field => $meta_key ) {
                    if ( array_key_exists( $field, $row ) ) {
                        $value = $row[ $field ];
                        if ( 'precio_proveedor' === $field && '' !== trim( (string) $value ) ) {
                            $valid = null;
                            $value = seo_ie_product_v2_decimal( $value, $valid );
                        }
                        seo_ie_product_v2_set_meta( $product_id, $meta_key, sanitize_text_field( (string) $value ), $empty_clears );
                    }
                }

                if ( '' !== $brand_taxonomy ) {
                    update_post_meta( $product_id, '_seo_taxonomia_marca', $brand_taxonomy );
                }
            }

            if ( ! empty( $options['scope'] ) && array_key_exists( 'ambito', $row ) ) {
                if ( '' !== trim( (string) $row['ambito'] ) || $empty_clears ) {
                    $effective_scope = $scope;

                    if ( function_exists( 'seo_catalog_get_product_role' ) ) {
                        $resolved_scope = seo_catalog_get_product_role( $product_id, false );
                        if ( '' !== $resolved_scope ) {
                            $effective_scope = $resolved_scope;
                        }
                    }

                    if ( '' !== $effective_scope ) {
                        seo_ie_upsert_node_value( 'product', $product_id, 'ambito', $effective_scope );

                        if (
                            function_exists( 'seo_catalog_get_product_role' )
                            && '' === seo_catalog_get_product_role( $product_id, false )
                            && function_exists( 'seo_catalog_assign_provisional_product_role' )
                        ) {
                            seo_catalog_assign_provisional_product_role(
                                $product_id,
                                $effective_scope,
                                'legacy_import_bridge',
                                0.6000
                            );
                        }
                    } elseif ( $empty_clears ) {
                        seo_ie_upsert_node_value( 'product', $product_id, 'ambito', '' );
                    }

                    $scope = $effective_scope;
                }
            }

            if ( is_array( $seo_attributes ) ) {
                $has_seo_attributes = '' !== trim( (string) ( $row['atributos_seo_json'] ?? $row['atributos_seo'] ?? '' ) );

                if ( $has_seo_attributes || $empty_clears ) {
                    if ( ! function_exists( 'seo_attributes_replace_product' ) ) {
                        throw new RuntimeException( 'El servicio canónico de atributos no está disponible.' );
                    }

                    // El parser conserva `ambito` por compatibilidad con CSV antiguos,
                    // pero el nuevo modelo de atributos es exclusivamente técnico.
                    // La clasificación del producto se resuelve mediante TIPO/ROL.
                    seo_attributes_replace_product(
                        $product_id,
                        (array) $seo_attributes['rows'],
                        'import_export_product_v2'
                    );
                }
            }

            if ( ! empty( $options['images'] ) ) {
                $has_featured_columns = array_key_exists( 'imagen_destacada_id', $row ) || array_key_exists( 'imagen_destacada', $row );
                $featured_has_value = 0 < absint( $row['imagen_destacada_id'] ?? 0 ) || '' !== trim( (string) ( $row['imagen_destacada'] ?? '' ) );

                if ( $has_featured_columns && ( $featured_has_value || $empty_clears ) ) {
                    if ( ! $featured_has_value ) {
                        delete_post_thumbnail( $product_id );
                    } else {
                        $attachment = seo_ie_product_v2_attachment(
                            absint( $row['imagen_destacada_id'] ?? 0 ),
                            $row['imagen_destacada'] ?? '',
                            $product_id,
                            false
                        );

                        if ( is_wp_error( $attachment ) ) {
                            seo_ie_add_log_warning( $log, sprintf( 'Fila %d, producto %d: imagen principal: %s', $line, $product_id, $attachment->get_error_message() ) );
                        } elseif ( 0 < absint( $attachment ) ) {
                            set_post_thumbnail( $product_id, absint( $attachment ) );
                        }
                    }
                }

                $has_gallery_columns = array_key_exists( 'galeria_ids', $row ) || array_key_exists( 'galeria_urls', $row );
                $gallery_has_value   = '' !== trim( (string) ( $row['galeria_ids'] ?? '' ) ) || '' !== trim( (string) ( $row['galeria_urls'] ?? '' ) );

                if ( $has_gallery_columns && ( $gallery_has_value || $empty_clears ) ) {
                    $gallery_ids = [];
                    $raw_ids     = seo_ie_product_v2_list( $row['galeria_ids'] ?? '' );
                    $raw_urls    = seo_ie_product_v2_list( $row['galeria_urls'] ?? '' );
                    $max_items   = max( count( $raw_ids ), count( $raw_urls ) );

                    for ( $i = 0; $i < $max_items; $i++ ) {
                        $attachment = seo_ie_product_v2_attachment(
                            absint( $raw_ids[ $i ] ?? 0 ),
                            $raw_urls[ $i ] ?? '',
                            $product_id,
                            false
                        );

                        if ( is_wp_error( $attachment ) ) {
                            seo_ie_add_log_warning( $log, sprintf( 'Fila %d, producto %d: galería %d: %s', $line, $product_id, $i + 1, $attachment->get_error_message() ) );
                        } elseif ( 0 < absint( $attachment ) ) {
                            $gallery_ids[] = absint( $attachment );
                        }
                    }

                    $gallery_ids = array_values( array_unique( array_diff( $gallery_ids, [ absint( get_post_thumbnail_id( $product_id ) ) ] ) ) );
                    $product = wc_get_product( $product_id );
                    $product->set_gallery_image_ids( $gallery_ids );
                    $product->save();
                }
            }

            clean_post_cache( $product_id );
            wc_delete_product_transients( $product_id );

            if ( $creating ) {
                $log['creados']++;
            } else {
                $log['actualizados']++;
            }

            $log['correctos']++;
        } catch ( Throwable $exception ) {
            $log['errores']++;
            $last_row_error = sanitize_text_field( $exception->getMessage() );
            seo_ie_add_log_detail( $log, sprintf( 'Fila %d: %s', $line, $last_row_error ) );
        }
    }

    $next_offset = ftell( $handle );
    $finished    = feof( $handle );
    fclose( $handle );
    delete_transient( $lock_key );

    $state['offset']                 = $next_offset;
    $state['line']                   = $line;
    $state['log']                    = $log;
    $state['batch_number']           = $batch_number;
    $state['last_batch_rows']        = $batch_processed;
    $state['last_product_id']        = $last_product_reference;
    $state['last_batch_end_line']    = $line;
    $state['last_batch_finished_at']      = time();
    $state['last_batch_duration']         = round( microtime( true ) - $batch_started_at, 4 );
    $state['last_batch_seconds_per_row']  = 0 < $batch_processed ? round( $state['last_batch_duration'] / $batch_processed, 4 ) : 0.0;
    $state['last_batch_memory_bytes']     = function_exists( 'memory_get_peak_usage' ) ? (int) memory_get_peak_usage( true ) : (int) memory_get_usage( true );
    $state['last_batch_memory_ratio']     = round( seo_ie_product_import_memory_ratio( true ), 4 );
    $state['last_batch_query_count']      = function_exists( 'get_num_queries' ) ? max( 0, absint( get_num_queries() ) - $queries_before_batch ) : 0;
    $state['last_batch_time_budget_reached'] = $time_budget_reached ? 1 : 0;
    $state['last_batch_memory_budget_reached'] = $memory_budget_reached ? 1 : 0;
    $state['updated_at']                  = time();

    $next_adaptive_plan = seo_ie_product_import_adaptive_plan( $state );
    $state['adaptive_next_batch_size'] = absint( $next_adaptive_plan['batch_size'] );
    $state['adaptive_next_reason']     = sanitize_text_field( $next_adaptive_plan['reason'] );
    $state['adaptive_next_pressure']   = sanitize_key( $next_adaptive_plan['pressure'] );
    $state['adaptive_next_delay']      = seo_ie_product_import_adaptive_delay( $state );

    if ( '' !== $last_row_error ) {
        $state['last_error'] = $last_row_error;
    }

    seo_ie_product_import_add_transaction(
        $state,
        'batch_finished',
        sprintf( 'Finaliza el lote %d con %d filas leídas.', $batch_number, $batch_processed ),
        [
            'line'       => $line,
            'offset'     => $next_offset,
            'product_id' => $last_product_reference,
            'duration'         => $state['last_batch_duration'],
            'seconds_per_row'  => $state['last_batch_seconds_per_row'],
            'target_rows'      => $batch_size,
            'next_batch_rows'  => $state['adaptive_next_batch_size'],
            'next_delay'       => $state['adaptive_next_delay'],
            'memory_ratio'     => $state['last_batch_memory_ratio'],
            'query_count'      => $state['last_batch_query_count'],
            'time_cutoff'      => $time_budget_reached ? 1 : 0,
            'memory_cutoff'    => $memory_budget_reached ? 1 : 0,
            'errors'           => absint( $log['errores'] ?? 0 ),
        ]
    );

    if ( $stop_requested || seo_ie_product_import_is_cancel_requested( $user_id, $token ) ) {
        $state['offset']     = $next_offset;
        $state['line']       = $line;
        $state['log']        = $log;
        $state['updated_at'] = time();
        $state = seo_ie_product_import_finalize_stopped( $user_id, $token, $state );

        if ( $is_background ) {
            return;
        }

        if ( $is_ajax ) {
            seo_ie_product_import_ajax_response(
                'stopped',
                $token,
                $state,
                [ 'message' => $state['message'] ]
            );
        }

        return;
    }

    if ( ! $finished ) {
        $state['offset']     = $next_offset;
        $state['line']       = $line;
        $state['log']        = $log;
        $state['status']     = 'processing';
        $state['retries']    = 0;
        $state['updated_at'] = time();
        set_transient(
            seo_ie_product_import_state_key( $user_id, $token ),
            $state,
            DAY_IN_SECONDS
        );
        seo_ie_product_import_set_active( $user_id, $token, $state );
        seo_ie_store_log( $log );

        $next_delay = absint( $state['adaptive_next_delay'] ?? 0 );
        if ( ! seo_ie_product_import_schedule( $user_id, $token, $next_delay, true ) ) {
            throw new RuntimeException( 'No se pudo programar el siguiente lote de importación.' );
        }

        seo_ie_product_import_schedule_watchdog( $user_id, $token, 60 );

        if ( $is_background ) {
            return;
        }

        if ( $is_ajax ) {
            seo_ie_product_import_ajax_response(
                'processing',
                $token,
                $state,
                [ 'message' => 'La importación continúa en la cola del servidor.' ]
            );
        }

        return;
    }

    seo_ie_product_import_unschedule( $user_id, $token );
    delete_transient( seo_ie_product_import_state_key( $user_id, $token ) );
    seo_ie_product_import_clear_active( $user_id, $token );

    if ( ! empty( $state['path'] ) ) {
        @unlink( $state['path'] );
    }

    if ( $dry_run ) {
        seo_ie_add_log_detail( $log, 'Simulación completada: no se modificó ningún producto.' );
    } else {
        seo_ie_add_log_detail( $log, 'Importación completada mediante lotes adaptativos regulados por tiempo y memoria.' );
    }

    $queue_finalized = function_exists( 'seo_ie_batch_finalize_product_file' )
        ? seo_ie_batch_finalize_product_file( $user_id, $state, $log )
        : true;

    $state['offset']     = $next_offset;
    $state['line']       = $line;
    $state['log']        = $log;
    $state['status']     = 'completed';
    $state['updated_at'] = time();
    $state['message']    = $dry_run ? 'Simulación completada.' : 'Importación completada.';

    if ( ! empty( $state['queue_mode'] ) && ! $queue_finalized ) {
        $state['message'] = 'Importacion terminada, pero el archivo no pudo salir de processing. Revisa la pestana Importacion por lotes.';
    }
    seo_ie_product_import_add_transaction(
        $state,
        'completed',
        $state['message'],
        [ 'processed' => absint( $log['procesados'] ?? 0 ) ]
    );
    seo_ie_store_log( $log );
    set_transient(
        seo_ie_product_import_result_key( $user_id, $token ),
        $state,
        HOUR_IN_SECONDS
    );

    if ( $is_background ) {
        return;
    }

    if ( $is_ajax ) {
        seo_ie_product_import_ajax_response(
            'completed',
            $token,
            $state,
            [ 'message' => $state['message'] ]
        );
    }

    wp_safe_redirect(
        add_query_arg(
            [
                'page'            => 'seo-import-export',
                'seo_ie_tab'      => 'wordpress',
                'seo_ie_imported' => 'products',
            ],
            admin_url( 'admin.php' )
        )
    );
    exit;

}


/**
 * Devuelve los estados de página admitidos en importación y exportación.
 *
 * Se excluyen estados internos como auto-draft, inherit y trash.
 *
 * @since 2.1.0
 *
 * @return string[]
 */
function seo_ie_page_allowed_statuses() {

    return [
        'publish',
        'future',
        'draft',
        'pending',
        'private',
    ];
}

/**
 * Valida una fecha MySQL y comprueba que el calendario sea real.
 *
 * @since 2.1.0
 *
 * @param string $value Fecha recibida.
 * @return bool
 */
function seo_ie_is_valid_page_datetime( $value ) {

    $value = trim( (string) $value );

    if ( ! seo_ie_is_mysql_datetime( $value ) ) {
        return false;
    }

    $date = DateTime::createFromFormat( '!Y-m-d H:i:s', $value );

    return $date instanceof DateTime
        && $date->format( 'Y-m-d H:i:s' ) === $value;
}

/**
 * Normaliza una ruta jerárquica de página.
 *
 * Admite tanto rutas relativas como URLs completas y devuelve una ruta
 * formada únicamente por slugs, sin barras iniciales ni finales.
 *
 * @since 2.1.0
 *
 * @param string $path Ruta o URL recibida.
 * @return string
 */
function seo_ie_normalize_page_path( $path ) {

    $path = trim( seo_ie_csv_to_utf8( (string) $path ) );

    if ( '' === $path ) {
        return '';
    }

    if ( 1 === preg_match( '#^https?://#i', $path ) ) {
        $url_path = wp_parse_url( $path, PHP_URL_PATH );

        if ( is_string( $url_path ) ) {
            $path = $url_path;
        }
    }

    $path = rawurldecode( $path );
    $path = trim( $path, " \t\n\r\0\x0B/" );

    $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
    $home_path = is_string( $home_path )
        ? trim( rawurldecode( $home_path ), '/' )
        : '';

    if ( '' !== $home_path ) {
        if ( $path === $home_path ) {
            $path = '';
        } elseif ( 0 === strpos( $path, $home_path . '/' ) ) {
            $path = substr( $path, strlen( $home_path ) + 1 );
        }
    }

    $parts = array_filter(
        explode( '/', $path ),
        static function ( $part ) {
            return '' !== trim( (string) $part );
        }
    );

    $parts = array_map(
        static function ( $part ) {
            return sanitize_title( $part );
        },
        $parts
    );

    $parts = array_values( array_filter( $parts ) );

    return implode( '/', $parts );
}

/**
 * Localiza una página exclusivamente por su ruta jerárquica.
 *
 * Se pasa el tipo como array para impedir que get_page_by_path() incluya
 * adjuntos con la misma ruta.
 *
 * @since 2.1.0
 *
 * @param string $path Ruta normalizada.
 * @return WP_Post|null
 */
function seo_ie_get_page_by_path( $path ) {

    $path = seo_ie_normalize_page_path( $path );

    if ( '' === $path ) {
        return null;
    }

    $page = get_page_by_path( $path, OBJECT, [ 'page' ] );

    return $page instanceof WP_Post ? $page : null;
}

/**
 * Devuelve hasta tres páginas que comparten un slug.
 *
 * Más de una coincidencia se considera ambigua y no se actualiza ninguna.
 *
 * @since 2.1.0
 *
 * @param string $slug Slug recibido.
 * @return int[]
 */
function seo_ie_find_page_ids_by_slug( $slug ) {

    $slug = sanitize_title( $slug );

    if ( '' === $slug ) {
        return [];
    }

    return array_values(
        array_filter(
            array_map(
                'absint',
                get_posts(
                    [
                        'post_type'              => 'page',
                        'post_status'            => 'any',
                        'name'                   => $slug,
                        'posts_per_page'         => 3,
                        'orderby'                => 'ID',
                        'order'                  => 'ASC',
                        'fields'                 => 'ids',
                        'no_found_rows'          => true,
                        'cache_results'          => false,
                        'update_post_meta_cache' => false,
                        'update_post_term_cache' => false,
                    ]
                )
            )
        )
    );
}

/**
 * Busca la página destino de una fila antes de modificar WordPress.
 *
 * Orden de identificación:
 * 1. ID si corresponde a una página.
 * 2. Ruta jerárquica completa.
 * 3. Slug, únicamente cuando es único.
 *
 * @since 2.1.0
 *
 * @param int    $source_id   ID del CSV.
 * @param string $source_path Ruta del CSV.
 * @param string $source_slug Slug del CSV.
 * @return array {
 *     @type int      $page_id  ID encontrado o cero.
 *     @type string   $method   Método de identificación.
 *     @type string   $error    Error bloqueante.
 *     @type string[] $warnings Avisos no bloqueantes.
 * }
 */
function seo_ie_locate_existing_page( $source_id, $source_path, $source_slug ) {

    $result = [
        'page_id'  => 0,
        'method'   => '',
        'error'    => '',
        'warnings' => [],
    ];

    $source_id   = absint( $source_id );
    $source_path = seo_ie_normalize_page_path( $source_path );
    $source_slug = sanitize_title( $source_slug );

    if ( 0 < $source_id ) {
        $post = get_post( $source_id );

        if ( $post instanceof WP_Post && 'page' === $post->post_type ) {
            if ( '' !== $source_path ) {
                $path_page = seo_ie_get_page_by_path( $source_path );

                if (
                    $path_page instanceof WP_Post
                    && absint( $path_page->ID ) !== $source_id
                ) {
                    $result['error'] = sprintf(
                        'El page_id %d y la ruta «%s» identifican páginas diferentes.',
                        $source_id,
                        $source_path
                    );

                    return $result;
                }

                $current_path = seo_ie_normalize_page_path(
                    get_page_uri( $source_id )
                );

                if (
                    ! ( $path_page instanceof WP_Post )
                    && '' !== $current_path
                    && $current_path !== $source_path
                ) {
                    $result['warnings'][] = sprintf(
                        'El page_id %d existe, pero su ruta actual es «%s» y no «%s»; se priorizará el ID.',
                        $source_id,
                        $current_path,
                        $source_path
                    );
                }
            }

            $result['page_id'] = $source_id;
            $result['method']  = 'ID';

            return $result;
        }

        if ( $post instanceof WP_Post ) {
            $result['warnings'][] = sprintf(
                'El ID %d pertenece al tipo «%s» y no se utilizará como página.',
                $source_id,
                $post->post_type
            );
        } else {
            $result['warnings'][] = sprintf(
                'El ID %d no existe; se intentará localizar por ruta o slug.',
                $source_id
            );
        }
    }

    if ( '' !== $source_path ) {
        $page = seo_ie_get_page_by_path( $source_path );

        if ( $page instanceof WP_Post ) {
            $result['page_id'] = absint( $page->ID );
            $result['method']  = 'ruta';

            return $result;
        }
    }

    if ( '' !== $source_slug ) {
        $page_ids = seo_ie_find_page_ids_by_slug( $source_slug );

        if ( 1 === count( $page_ids ) ) {
            $result['page_id'] = absint( $page_ids[0] );
            $result['method']  = 'slug';

            return $result;
        }

        if ( 1 < count( $page_ids ) ) {
            $result['error'] = sprintf(
                'El slug «%s» coincide con varias páginas; utiliza page_id o ruta.',
                $source_slug
            );
        }
    }

    return $result;
}

/**
 * Añade un aviso al log de importación.
 *
 * @since 2.1.0
 *
 * @param array  $log     Log por referencia.
 * @param string $message Mensaje.
 * @return void
 */
function seo_ie_add_log_warning( &$log, $message ) {

    if ( ! isset( $log['advertencias'] ) ) {
        $log['advertencias'] = 0;
    }

    $log['advertencias']++;
    seo_ie_add_log_detail( $log, 'Aviso: ' . $message );
}

/**
 * Indica si una clave meta no debe viajar en el CSV de páginas.
 *
 * Se excluyen bloqueos de edición, datos de papelera y campos que ya tienen
 * columnas propias en el archivo.
 *
 * @since 2.1.0
 *
 * @param string $meta_key Clave meta.
 * @return bool
 */
function seo_ie_page_meta_is_excluded( $meta_key ) {

    $meta_key = (string) $meta_key;

    $excluded = [
        '_edit_lock',
        '_edit_last',
        '_thumbnail_id',
        '_wp_page_template',
        '_wp_old_slug',
        '_wp_old_date',
        '_wp_desired_post_slug',
        '_pingme',
        '_encloseme',
    ];

    if ( in_array( $meta_key, $excluded, true ) ) {
        return true;
    }

    return 0 === strpos( $meta_key, '_wp_trash_meta_' );
}

/**
 * Clasifica una clave meta como SEO.
 *
 * Incluye prefijos habituales de Yoast, Rank Math, SEOPress, AIOSEO y del
 * propio sistema, sin depender de que esos plugins estén activos.
 *
 * @since 2.1.0
 *
 * @param string $meta_key Clave meta.
 * @return bool
 */
function seo_ie_page_meta_is_seo( $meta_key ) {

    $meta_key = (string) $meta_key;

    $prefixes = [
        '_yoast_wpseo_',
        'rank_math_',
        '_rank_math_',
        '_seopress_',
        'seopress_',
        '_aioseo_',
        'aioseo_',
        '_seo_',
        'seo_',
        '_genesis_',
    ];

    foreach ( $prefixes as $prefix ) {
        if ( 0 === strpos( $meta_key, $prefix ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Obtiene los metadatos exportables de una página, separados por finalidad.
 *
 * Cada clave conserva todos sus valores. Los arrays almacenados por plugins
 * se mantienen como arrays para que WordPress vuelva a serializarlos.
 *
 * @since 2.1.0
 *
 * @param int $page_id ID de página.
 * @return array {
 *     @type array $seo    Metadatos SEO.
 *     @type array $custom Otros metadatos.
 * }
 */
function seo_ie_get_page_meta_payload( $page_id ) {

    $payload = [
        'seo'    => [],
        'custom' => [],
    ];

    $all_meta = get_post_meta( absint( $page_id ) );

    foreach ( (array) $all_meta as $meta_key => $unused_values ) {
        if ( seo_ie_page_meta_is_excluded( $meta_key ) ) {
            continue;
        }

        $bucket = seo_ie_page_meta_is_seo( $meta_key )
            ? 'seo'
            : 'custom';

        $payload[ $bucket ][ $meta_key ] = array_values(
            (array) get_post_meta( absint( $page_id ), $meta_key, false )
        );
    }

    ksort( $payload['seo'] );
    ksort( $payload['custom'] );

    return $payload;
}

/**
 * Codifica un bloque de metadatos para una celda CSV.
 *
 * @since 2.1.0
 *
 * @param array $payload Metadatos.
 * @return string
 */
function seo_ie_encode_page_meta_payload( $payload ) {

    if ( empty( $payload ) ) {
        return '{}';
    }

    $encoded = wp_json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    return is_string( $encoded ) ? $encoded : '{}';
}

/**
 * Decodifica y valida un bloque JSON de metadatos de página.
 *
 * @since 2.1.0
 *
 * @param string $encoded JSON recibido.
 * @return array {
 *     @type array    $data   Metadatos normalizados.
 *     @type string[] $errors Errores encontrados.
 * }
 */
function seo_ie_decode_page_meta_payload( $encoded ) {

    $result = [
        'data'   => [],
        'errors' => [],
    ];

    $encoded = trim( seo_ie_csv_to_utf8( (string) $encoded ) );

    if ( '' === $encoded || '{}' === $encoded ) {
        return $result;
    }

    $decoded = json_decode( $encoded, true, 512, JSON_BIGINT_AS_STRING );

    if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
        $result['errors'][] = 'El bloque de metadatos no contiene JSON válido.';

        return $result;
    }

    foreach ( $decoded as $meta_key => $values ) {
        $meta_key = (string) $meta_key;

        if (
            '' === $meta_key
            || 191 < strlen( $meta_key )
            || 1 !== preg_match( '/^[A-Za-z0-9_.:\-]+$/', $meta_key )
            || seo_ie_page_meta_is_excluded( $meta_key )
        ) {
            $result['errors'][] = sprintf(
                'La clave meta «%s» no es válida o está protegida.',
                $meta_key
            );
            continue;
        }

        if ( ! is_array( $values ) ) {
            $values = [ $values ];
        } elseif ( ! empty( $values ) ) {
            $keys = array_keys( $values );

            if ( $keys !== range( 0, count( $values ) - 1 ) ) {
                // Un array asociativo es un único valor meta, no una lista.
                $values = [ $values ];
            }
        }

        $result['data'][ $meta_key ] = array_values( $values );
    }

    return $result;
}

/**
 * Sustituye los valores de las claves meta presentes en un bloque importado.
 *
 * Las claves ausentes no se eliminan. Una clave con lista vacía sí se borra,
 * lo que permite limpiar un dato de forma explícita desde el CSV.
 *
 * @since 2.1.0
 *
 * @param int    $page_id ID de página.
 * @param array  $payload Metadatos validados.
 * @param array  $log     Log por referencia.
 * @param int    $line    Línea del CSV.
 * @param string $label   Nombre del bloque.
 * @return void
 */
function seo_ie_apply_page_meta_payload( $page_id, $payload, &$log, $line, $label ) {

    foreach ( (array) $payload as $meta_key => $values ) {
        delete_post_meta( $page_id, $meta_key );

        foreach ( (array) $values as $value ) {
            if ( null === $value ) {
                $value = '';
            }

            $inserted = add_post_meta(
                $page_id,
                $meta_key,
                wp_slash( $value ),
                false
            );

            if ( false === $inserted ) {
                $log['errores']++;
                seo_ie_add_log_detail(
                    $log,
                    sprintf(
                        'Fila %d, página %d: no se pudo guardar %s «%s».',
                        $line,
                        $page_id,
                        $label,
                        $meta_key
                    )
                );
            }
        }
    }
}

/**
 * Resuelve el autor de una fila por ID y, como respaldo, por login.
 *
 * @since 2.1.0
 *
 * @param array $row Fila normalizada.
 * @return int ID de usuario o cero.
 */
function seo_ie_resolve_page_author( $row ) {

    $author_id = absint( $row['autor_id'] ?? 0 );

    if ( 0 < $author_id && get_userdata( $author_id ) ) {
        return $author_id;
    }

    $author_login = sanitize_user(
        trim( (string) ( $row['autor_login'] ?? '' ) ),
        true
    );

    if ( '' !== $author_login ) {
        $user = get_user_by( 'login', $author_login );

        if ( $user instanceof WP_User ) {
            return absint( $user->ID );
        }
    }

    return 0;
}

/**
 * Comprueba si una nueva relación padre-hijo produciría un ciclo.
 *
 * @since 2.1.0
 *
 * @param int $page_id   Página que se actualizará.
 * @param int $parent_id Padre propuesto.
 * @return bool
 */
function seo_ie_page_parent_would_cycle( $page_id, $parent_id ) {

    $page_id   = absint( $page_id );
    $parent_id = absint( $parent_id );

    if ( 0 === $page_id || 0 === $parent_id ) {
        return false;
    }

    if ( $page_id === $parent_id ) {
        return true;
    }

    return in_array(
        $page_id,
        array_map( 'absint', get_post_ancestors( $parent_id ) ),
        true
    );
}

/**
 * Importa o elimina la imagen destacada descrita en una fila.
 *
 * @since 2.1.0
 *
 * @param int   $page_id ID de página.
 * @param array $row     Fila normalizada.
 * @param int   $line    Línea del CSV.
 * @param array $log     Log por referencia.
 * @return void
 */
function seo_ie_import_page_thumbnail( $page_id, $row, $line, &$log ) {

    $has_id_column  = array_key_exists( 'imagen_destacada_id', $row );
    $has_url_column = array_key_exists( 'imagen_destacada', $row );

    if ( ! $has_id_column && ! $has_url_column ) {
        return;
    }

    $attachment_id = absint( $row['imagen_destacada_id'] ?? 0 );
    $image_url     = esc_url_raw(
        trim( (string) ( $row['imagen_destacada'] ?? '' ) )
    );

    if ( 0 === $attachment_id && '' === $image_url ) {
        delete_post_thumbnail( $page_id );
        return;
    }

    if (
        0 < $attachment_id
        && 'attachment' === get_post_type( $attachment_id )
    ) {
        set_post_thumbnail( $page_id, $attachment_id );
        return;
    }

    if ( 0 < $attachment_id ) {
        seo_ie_add_log_warning(
            $log,
            sprintf(
                'Fila %d, página %d: el adjunto %d no existe; se probará la URL.',
                $line,
                $page_id,
                $attachment_id
            )
        );
    }

    if ( '' === $image_url ) {
        return;
    }

    $attachment_id = attachment_url_to_postid( $image_url );

    if ( 0 < $attachment_id ) {
        set_post_thumbnail( $page_id, $attachment_id );
        return;
    }

    if ( ! wp_http_validate_url( $image_url ) ) {
        seo_ie_add_log_warning(
            $log,
            sprintf(
                'Fila %d, página %d: la URL de imagen no es válida.',
                $line,
                $page_id
            )
        );
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $sideloaded_id = media_sideload_image(
        $image_url,
        $page_id,
        null,
        'id'
    );

    if ( is_wp_error( $sideloaded_id ) ) {
        seo_ie_add_log_warning(
            $log,
            sprintf(
                'Fila %d, página %d: no se pudo importar la imagen: %s',
                $line,
                $page_id,
                $sideloaded_id->get_error_message()
            )
        );
        return;
    }

    set_post_thumbnail( $page_id, absint( $sideloaded_id ) );
}

/**
 * Devuelve el rol SEO activo de una pagina dentro de wp_seo_nodes.
 *
 * Se utiliza solo para enriquecer el export. No crea ni modifica relaciones.
 *
 * @param int $page_id ID de la pagina.
 * @return string
 */
function seo_ie_get_page_seo_role_for_export( $page_id ) {

    global $wpdb;

    $page_id = absint( $page_id );

    if ( 0 >= $page_id ) {
        return '';
    }

    $nodes_table = $wpdb->prefix . 'seo_nodes';

    static $table_exists = null;

    if ( null === $table_exists ) {
        $table_exists = ( $nodes_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $nodes_table ) ) );
    }

    if ( ! $table_exists ) {
        return '';
    }

    return sanitize_key(
        (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT seo_role
                 FROM {$nodes_table}
                 WHERE object_type = 'page'
                   AND object_id = %d
                   AND seo_role IN (
                       'cluster',
                       'hub_primary',
                       'hub_secondary',
                       'landing',
                       'landing_comparative',
                       'corporate_page'
                   )
                   AND status = 1
                 ORDER BY id DESC
                 LIMIT 1",
                $page_id
            )
        )
    );
}

/**
 * Devuelve las categorias WooCommerce relacionadas semanticamente con una
 * entrada o landing mediante wp_seo_relations.
 *
 * Esta relacion es independiente de la taxonomia editorial `category` de
 * WordPress. El export conserva ambas capas por separado.
 *
 * @param string $source_type `post` o `landing`.
 * @param int    $source_id   ID real del post o pagina.
 * @return array{ids:array<int>,slugs:array<string>,names:array<string>}
 */
function seo_ie_get_product_cat_relation_payload_for_export( $source_type, $source_id ) {

    global $wpdb;

    $source_type = sanitize_key( $source_type );
    $source_id   = absint( $source_id );

    $relation_types = [
        'post'    => 'post_to_category',
        'landing' => 'landing_to_category',
    ];

    $empty = [
        'ids'   => [],
        'slugs' => [],
        'names' => [],
    ];

    if ( 0 >= $source_id || ! isset( $relation_types[ $source_type ] ) ) {
        return $empty;
    }

    $relations_table = $wpdb->prefix . 'seo_relations';

    static $table_exists = null;

    if ( null === $table_exists ) {
        $table_exists = ( $relations_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $relations_table ) ) );
    }

    if ( ! $table_exists ) {
        return $empty;
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT r.target_id AS term_id, t.slug, t.name
             FROM {$relations_table} r
             INNER JOIN {$wpdb->terms} t
                ON t.term_id = r.target_id
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = r.target_id
               AND tt.taxonomy = 'product_cat'
             WHERE r.source_type = %s
               AND r.source_id = %d
               AND r.target_type = 'product_cat'
               AND r.relation_type = %s
             ORDER BY t.name ASC, r.target_id ASC",
            $source_type,
            $source_id,
            $relation_types[ $source_type ]
        )
    );

    if ( empty( $rows ) ) {
        return $empty;
    }

    $payload = $empty;

    foreach ( $rows as $row ) {
        $term_id = absint( $row->term_id ?? 0 );

        if ( 0 >= $term_id ) {
            continue;
        }

        $payload['ids'][]   = $term_id;
        $payload['slugs'][] = sanitize_title( (string) ( $row->slug ?? '' ) );
        $payload['names'][] = sanitize_text_field( (string) ( $row->name ?? '' ) );
    }

    return $payload;
}


/**
 * Roles estructurales de página que puede transportar el import/export legacy.
 *
 * No incluye roles auxiliares como excerpt o description: esos registros de
 * seo_nodes pertenecen a otros bloques de contenido y no deben alterarse al
 * importar la clasificación estructural de una página.
 *
 * @return string[]
 */
function seo_ie_page_structural_roles() {
    return [
        'cluster',
        'hub_primary',
        'hub_secondary',
        'landing',
        'landing_comparative',
        'corporate_page',
    ];
}

/**
 * Indica si una fila CSV define explícitamente la relación comercial con
 * product_cat. Si las columnas existen pero están vacías, la intención es
 * dejar el objeto sin relación comercial.
 *
 * @param array $row Fila CSV normalizada.
 * @return bool
 */
function seo_ie_product_cat_relation_is_defined( $row ) {
    foreach ( [ 'product_cat_relacion_ids', 'product_cat_relacion_slugs', 'product_cat_relacion_nombres' ] as $key ) {
        if ( array_key_exists( $key, (array) $row ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Registra una incidencia de relación sin convertir la fila completa en fallo.
 *
 * El contenido de WordPress puede haberse importado correctamente aunque la
 * categoría comercial no exista. Estas incidencias se contabilizan como
 * advertencias y quedan marcadas como ERROR RELACIÓN para su revisión.
 *
 * @param array  $log     Log por referencia.
 * @param string $message Mensaje.
 * @return void
 */
function seo_ie_add_relation_issue( &$log, $message ) {
    if ( ! isset( $log['advertencias'] ) ) {
        $log['advertencias'] = 0;
    }
    if ( ! isset( $log['relaciones_no_resueltas'] ) ) {
        $log['relaciones_no_resueltas'] = 0;
    }

    $log['advertencias']++;
    $log['relaciones_no_resueltas']++;
    seo_ie_add_log_detail( $log, 'ERROR RELACIÓN: ' . $message );
}

/**
 * Resuelve la relación comercial product_cat descrita en una fila.
 *
 * Orden de resolución: slug -> nombre -> ID (solo si no hay slug ni nombre). Nunca crea términos nuevos.
 * Si una sola referencia solicitada no puede resolverse, la relación completa
 * se considera inválida para evitar asociaciones parciales.
 *
 * @param array $row  Fila CSV normalizada.
 * @param int   $line Línea del CSV.
 * @param array $log  Log por referencia.
 * @return array{defined:bool,valid:bool,term_ids:int[],requested_count:int,unresolved:string[]}
 */
function seo_ie_resolve_product_cat_relation_for_import( $row, $line, &$log ) {
    $result = [
        'defined'         => seo_ie_product_cat_relation_is_defined( $row ),
        'valid'           => true,
        'term_ids'        => [],
        'requested_count' => 0,
        'unresolved'      => [],
    ];

    if ( ! $result['defined'] ) {
        return $result;
    }

    $ids   = seo_ie_decode_post_list( $row['product_cat_relacion_ids'] ?? '' );
    $slugs = seo_ie_decode_post_list( $row['product_cat_relacion_slugs'] ?? '' );
    $names = seo_ie_decode_post_list( $row['product_cat_relacion_nombres'] ?? '' );
    $count = max( count( $ids ), count( $slugs ), count( $names ) );

    $result['requested_count'] = $count;

    for ( $i = 0; $i < $count; $i++ ) {
        $term_id = absint( $ids[ $i ] ?? 0 );
        $slug    = sanitize_title( $slugs[ $i ] ?? '' );
        $name    = sanitize_text_field( $names[ $i ] ?? '' );
        $term    = false;

        if ( '' !== $slug ) {
            $term = get_term_by( 'slug', $slug, 'product_cat' );
        }

        if ( ! $term && '' !== $name ) {
            $term = get_term_by( 'name', $name, 'product_cat' );
        }

        /*
         * El ID solo es fallback cuando el CSV no aporta slug ni nombre.
         * Así evitamos que un ID reciclado en otra instalación relacione el
         * contenido con una categoría distinta a la descrita por el CSV.
         */
        if ( ! $term && '' === $slug && '' === $name && 0 < $term_id ) {
            $candidate = get_term( $term_id, 'product_cat' );
            if ( $candidate && ! is_wp_error( $candidate ) ) {
                $term = $candidate;
            }
        }

        if ( $term && ! is_wp_error( $term ) ) {
            $result['term_ids'][] = absint( $term->term_id );
            continue;
        }

        $reference = '' !== $slug
            ? $slug
            : ( '' !== $name ? $name : ( 0 < $term_id ? (string) $term_id : 'referencia vacía' ) );
        $result['unresolved'][] = $reference;
    }

    $result['term_ids'] = array_values(
        array_unique(
            array_filter(
                array_map( 'absint', $result['term_ids'] )
            )
        )
    );

    if ( ! empty( $result['unresolved'] ) ) {
        $result['valid']    = false;
        $result['term_ids'] = [];

        seo_ie_add_relation_issue(
            $log,
            sprintf(
                'Fila %d: no existe o no se pudo resolver product_cat «%s». El contenido se importará, pero el objeto quedará sin categoría comercial asociada.',
                absint( $line ),
                implode( ', ', $result['unresolved'] )
            )
        );
    }

    return $result;
}

/**
 * Sustituye de forma transaccional las relaciones product_cat de un post o
 * landing. Un array vacío elimina la relación comercial existente.
 *
 * @param string $source_type post o landing.
 * @param int    $source_id   ID real de WordPress.
 * @param int[]  $term_ids    Categorías WooCommerce válidas.
 * @return true|WP_Error
 */
function seo_ie_replace_product_cat_relations( $source_type, $source_id, $term_ids ) {
    global $wpdb;

    $source_type = sanitize_key( $source_type );
    $source_id   = absint( $source_id );
    $term_ids    = array_values( array_unique( array_filter( array_map( 'absint', (array) $term_ids ) ) ) );

    $relation_types = [
        'post'    => 'post_to_category',
        'landing' => 'landing_to_category',
    ];

    if ( 0 >= $source_id || ! isset( $relation_types[ $source_type ] ) ) {
        return new WP_Error( 'seo_ie_relation_source', 'Origen de relación comercial no válido.' );
    }

    foreach ( $term_ids as $term_id ) {
        $term = get_term( $term_id, 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error(
                'seo_ie_relation_target',
                sprintf( 'La categoría de producto %d no existe.', $term_id )
            );
        }
    }

    $table = $wpdb->prefix . 'seo_relations';
    if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        return new WP_Error( 'seo_ie_relations_table', 'No existe la tabla seo_relations.' );
    }

    $wpdb->query( 'START TRANSACTION' );

    $deleted = $wpdb->delete(
        $table,
        [
            'source_type'   => $source_type,
            'source_id'     => $source_id,
            'target_type'   => 'product_cat',
            'relation_type' => $relation_types[ $source_type ],
        ],
        [ '%s', '%d', '%s', '%s' ]
    );

    if ( false === $deleted ) {
        $wpdb->query( 'ROLLBACK' );
        return new WP_Error( 'seo_ie_relation_delete', 'No se pudieron sustituir las relaciones comerciales existentes.' );
    }

    foreach ( $term_ids as $term_id ) {
        $inserted = $wpdb->insert(
            $table,
            [
                'source_type'   => $source_type,
                'source_id'     => $source_id,
                'target_type'   => 'product_cat',
                'target_id'     => $term_id,
                'relation_type' => $relation_types[ $source_type ],
                'created_at'    => current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%s', '%d', '%s', '%s' ]
        );

        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error(
                'seo_ie_relation_insert',
                sprintf( 'No se pudo guardar la relación con product_cat %d.', $term_id )
            );
        }
    }

    $wpdb->query( 'COMMIT' );
    return true;
}

/**
 * Aplica o elimina el rol estructural de una página en wp_seo_nodes.
 *
 * Solo toca roles estructurales; no modifica filas auxiliares de contenido.
 * Una cadena vacía elimina cualquier rol estructural previo.
 *
 * @param int    $page_id  ID de página.
 * @param string $seo_role Rol solicitado o cadena vacía.
 * @return true|WP_Error
 */
function seo_ie_apply_page_seo_role_for_import( $page_id, $seo_role ) {
    global $wpdb;

    $page_id  = absint( $page_id );
    $seo_role = sanitize_key( $seo_role );
    $roles    = seo_ie_page_structural_roles();

    if ( 0 >= $page_id ) {
        return new WP_Error( 'seo_ie_page_role_id', 'ID de página no válido para seo_nodes.' );
    }

    if ( '' !== $seo_role && ! in_array( $seo_role, $roles, true ) ) {
        return new WP_Error(
            'seo_ie_page_role_invalid',
            sprintf( 'El rol SEO «%s» no es un rol estructural admitido.', $seo_role )
        );
    }

    $table = $wpdb->prefix . 'seo_nodes';
    if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
        return new WP_Error( 'seo_ie_nodes_table', 'No existe la tabla seo_nodes.' );
    }

    foreach ( $roles as $role ) {
        if ( $role === $seo_role ) {
            continue;
        }

        $deleted = $wpdb->delete(
            $table,
            [
                'object_type' => 'page',
                'object_id'   => $page_id,
                'seo_role'    => $role,
            ],
            [ '%s', '%d', '%s' ]
        );

        if ( false === $deleted ) {
            return new WP_Error( 'seo_ie_page_role_delete', 'No se pudo limpiar el rol SEO estructural anterior.' );
        }
    }

    if ( '' === $seo_role ) {
        return true;
    }

    $existing_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id
             FROM {$table}
             WHERE object_type = 'page'
               AND object_id = %d
               AND seo_role = %s
             ORDER BY updated_at DESC, id DESC",
            $page_id,
            $seo_role
        )
    );

    if ( ! empty( $existing_ids ) ) {
        $primary_id = absint( array_shift( $existing_ids ) );
        $updated = $wpdb->update(
            $table,
            [
                'status'     => 1,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $primary_id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        if ( false === $updated ) {
            $detail = trim( (string) $wpdb->last_error );
            return new WP_Error(
                'seo_ie_page_role_update',
                'No se pudo actualizar el rol SEO de la página.' . ( $detail !== '' ? ' SQL: ' . $detail : '' )
            );
        }

        foreach ( $existing_ids as $duplicate_id ) {
            $wpdb->delete( $table, [ 'id' => absint( $duplicate_id ) ], [ '%d' ] );
        }

        return true;
    }

    $inserted = $wpdb->insert(
        $table,
        [
            'object_type' => 'page',
            'object_id'   => $page_id,
            'seo_role'    => $seo_role,
            'keywords'    => '',
            'status'      => 1,
            'created_at'  => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
        ],
        [ '%s', '%d', '%s', '%s', '%d', '%s', '%s' ]
    );

    if ( false === $inserted ) {
        $detail = trim( (string) $wpdb->last_error );
        return new WP_Error(
            'seo_ie_page_role_insert',
            'No se pudo crear el rol SEO de la página.' . ( $detail !== '' ? ' SQL: ' . $detail : '' )
        );
    }

    return true;
}

/**
 * Aplica la relación comercial resuelta. Cuando la resolución es inválida,
 * elimina cualquier relación previa y deja el objeto deliberadamente huérfano
 * para que el informe de anomalías lo detecte después.
 *
 * @param string $source_type     post o landing.
 * @param int    $source_id       ID real de WordPress.
 * @param array  $relation_result Resultado de resolución.
 * @param int    $line            Línea CSV.
 * @param array  $log             Log por referencia.
 * @return void
 */
function seo_ie_apply_product_cat_relation_import( $source_type, $source_id, $relation_result, $line, &$log ) {
    if ( empty( $relation_result['defined'] ) ) {
        return;
    }

    $term_ids = ! empty( $relation_result['valid'] )
        ? (array) ( $relation_result['term_ids'] ?? [] )
        : [];

    $saved = seo_ie_replace_product_cat_relations( $source_type, $source_id, $term_ids );

    if ( is_wp_error( $saved ) ) {
        seo_ie_add_relation_issue(
            $log,
            sprintf(
                'Fila %d, objeto %d: no se pudo guardar la relación comercial: %s',
                absint( $line ),
                absint( $source_id ),
                $saved->get_error_message()
            )
        );
        return;
    }

    if ( empty( $relation_result['valid'] ) ) {
        seo_ie_add_log_detail(
            $log,
            sprintf(
                'Fila %d, objeto %d: se eliminaron las relaciones comerciales existentes porque la product_cat solicitada no pudo resolverse.',
                absint( $line ),
                absint( $source_id )
            )
        );
    }
}

/**
 * Exporta las páginas de WordPress a CSV.
 *
 * Incluye contenido, jerarquía, ajustes de página, autor, fechas, imagen
 * destacada y metadatos agrupados en SEO y personalizados.
 *
 * @since 2.1.0
 *
 * @return void
 */
function seo_export_pages_csv() {

    if ( ! isset( $_POST['seo_export_pages'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die(
            esc_html__(
                'No tienes permisos para exportar páginas.',
                'seo-system'
            )
        );
    }

    check_admin_referer(
        'seo_export_pages_csv',
        'seo_export_pages_nonce'
    );

    $allowed_statuses = seo_ie_page_allowed_statuses();
    $selected_statuses = array_values(
        array_intersect(
            array_map(
                'sanitize_key',
                (array) ( $_POST['export_page_statuses'] ?? [] )
            ),
            $allowed_statuses
        )
    );

    if ( empty( $selected_statuses ) ) {
        $selected_statuses = $allowed_statuses;
    }

    $pages = get_posts(
        [
            'post_type'              => 'page',
            'post_status'            => $selected_statuses,
            'posts_per_page'         => -1,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'cache_results'          => false,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]
    );

    $filename = 'seo_pages_' . wp_date( 'Ymd_His' ) . '.csv';

    seo_ie_store_log(
        [
            'operacion'    => 'Exportación de páginas',
            'archivo'      => $filename,
            'procesados'   => count( $pages ),
            'correctos'    => count( $pages ),
            'errores'      => 0,
            'advertencias' => 0,
            'detalles'     => [
                'Se exportaron contenido, jerarquía, rol SEO, relación comercial con product_cat, imagen y metadatos de página.',
                'No se exportaron bloqueos de edición, datos de papelera ni contraseñas de acceso a páginas.',
            ],
        ]
    );

    $output = seo_ie_open_csv_download( $filename );

    seo_ie_write_csv_row(
        $output,
        [
            'page_id',
            'titulo',
            'slug',
            'ruta',
            'url',
            'estado',
            'seo_role',
            'parent_id',
            'parent_slug',
            'parent_ruta',
            'menu_order',
            'autor_id',
            'autor_login',
            'fecha',
            'fecha_gmt',
            'fecha_modificada',
            'fecha_modificada_gmt',
            'comentarios',
            'pings',
            'excerpt',
            'description',
            'product_cat_relacion_ids',
            'product_cat_relacion_slugs',
            'product_cat_relacion_nombres',
            'imagen_destacada_id',
            'imagen_destacada',
            'meta_seo',
            'meta_personalizados',
        ]
    );

    foreach ( $pages as $page ) {
        $page_id   = absint( $page->ID );
        $parent_id = absint( $page->post_parent );
        $parent    = 0 < $parent_id ? get_post( $parent_id ) : null;
        $author    = get_userdata( absint( $page->post_author ) );
        $image_id     = get_post_thumbnail_id( $page_id );
        $meta         = seo_ie_get_page_meta_payload( $page_id );
        $seo_role     = seo_ie_get_page_seo_role_for_export( $page_id );
        $product_cats = 'landing' === $seo_role
            ? seo_ie_get_product_cat_relation_payload_for_export( 'landing', $page_id )
            : [ 'ids' => [], 'slugs' => [], 'names' => [] ];

        seo_ie_write_csv_row(
            $output,
            [
                $page_id,
                $page->post_title,
                $page->post_name,
                get_page_uri( $page_id ),
                get_permalink( $page_id ),
                $page->post_status,
                $seo_role,
                $parent_id,
                $parent instanceof WP_Post ? $parent->post_name : '',
                $parent instanceof WP_Post ? get_page_uri( $parent_id ) : '',
                intval( $page->menu_order ),
                absint( $page->post_author ),
                $author instanceof WP_User ? $author->user_login : '',
                $page->post_date,
                $page->post_date_gmt,
                $page->post_modified,
                $page->post_modified_gmt,
                $page->comment_status,
                $page->ping_status,
                $page->post_excerpt,
                $page->post_content,
                seo_ie_encode_post_list( $product_cats['ids'] ),
                seo_ie_encode_post_list( $product_cats['slugs'] ),
                seo_ie_encode_post_list( $product_cats['names'] ),
                absint( $image_id ),
                0 < $image_id ? wp_get_attachment_url( $image_id ) : '',
                seo_ie_encode_page_meta_payload( $meta['seo'] ),
                seo_ie_encode_page_meta_payload( $meta['custom'] ),
            ]
        );
    }

    fclose( $output );
    exit;
}

/**
 * Importa páginas desde el CSV generado por SEO System.
 *
 * La jerarquía se analiza antes de escribir y se procesa en orden padre-hijo.
 * Puede crear, actualizar o simular el resultado sin modificar WordPress.
 * Nunca elimina páginas.
 *
 * @since 2.1.0
 *
 * @return void
 */
function seo_import_pages_csv() {

    if ( ! isset( $_POST['seo_import_pages'] ) ) {
        return;
    }

    if ( function_exists( 'seo_ie_batch_guard_manual_import' ) ) {
        seo_ie_batch_guard_manual_import( 'page' );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die(
            esc_html__(
                'No tienes permisos para importar páginas.',
                'seo-system'
            )
        );
    }

    check_admin_referer(
        'seo_import_pages_csv',
        'seo_import_pages_nonce'
    );

    if (
        empty( $_FILES['pages_csv']['tmp_name'] )
        || (
            ! ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'page' ) )
            && ! is_uploaded_file( $_FILES['pages_csv']['tmp_name'] )
        )
    ) {
        wp_die(
            esc_html__(
                'No se ha recibido un CSV de páginas válido.',
                'seo-system'
            )
        );
    }

    $mode = sanitize_key( $_POST['page_import_mode'] ?? 'create_update' );

    if ( ! in_array( $mode, [ 'create_update', 'create_only', 'update_only' ], true ) ) {
        $mode = 'create_update';
    }

    $dry_run            = ! empty( $_POST['page_import_dry_run'] );
    $import_core        = ! empty( $_POST['import_page_core'] );
    $import_structure   = ! empty( $_POST['import_page_structure'] );
    $import_author_date = ! empty( $_POST['import_page_author_date'] );
    $import_seo_meta    = ! empty( $_POST['import_page_seo_meta'] );
    $import_custom_meta = ! empty( $_POST['import_page_custom_meta'] );
    $import_image       = ! empty( $_POST['import_page_image'] );
    $import_relations   = ! empty( $_POST['import_page_relations'] );

    if (
        ! $import_core
        && ! $import_structure
        && ! $import_author_date
        && ! $import_seo_meta
        && ! $import_custom_meta
        && ! $import_image
        && ! $import_relations
    ) {
        wp_die(
            esc_html__(
                'Selecciona al menos un bloque de datos para importar.',
                'seo-system'
            )
        );
    }

    $handle = fopen( $_FILES['pages_csv']['tmp_name'], 'r' );

    if ( false === $handle ) {
        wp_die(
            esc_html__(
                'No se pudo abrir el CSV de páginas.',
                'seo-system'
            )
        );
    }

    $header = seo_ie_read_csv_row( $handle );

    if ( false === $header ) {
        fclose( $handle );
        wp_die(
            esc_html__(
                'El CSV de páginas está vacío.',
                'seo-system'
            )
        );
    }

    $header = seo_ie_normalize_csv_header( $header, 'page' );

    $header_counts = array_count_values(
        array_values(
            array_filter(
                $header,
                static function ( $column ) {
                    return '' !== (string) $column;
                }
            )
        )
    );
    $duplicate_headers = array_keys(
        array_filter(
            $header_counts,
            static function ( $count ) {
                return 1 < $count;
            }
        )
    );

    if ( ! empty( $duplicate_headers ) ) {
        fclose( $handle );
        wp_die(
            sprintf(
                esc_html__(
                    'El CSV contiene cabeceras duplicadas después de normalizarlas: %s.',
                    'seo-system'
                ),
                esc_html( implode( ', ', $duplicate_headers ) )
            )
        );
    }

    $identity_columns = array_intersect(
        [ 'page_id', 'ruta', 'url', 'slug' ],
        $header
    );

    if (
        empty( $identity_columns )
        && (
            'update_only' === $mode
            || ! in_array( 'titulo', $header, true )
        )
    ) {
        fclose( $handle );
        wp_die(
            esc_html__(
                'El CSV necesita page_id, ruta, url o slug. El título solo permite crear páginas nuevas.',
                'seo-system'
            )
        );
    }

    $log = [
        'operacion'    => $dry_run
            ? 'Simulación de importación de páginas'
            : 'Importación de páginas',
        'archivo'      => sanitize_file_name( $_FILES['pages_csv']['name'] ),
        'procesados'   => 0,
        'correctos'    => 0,
        'creados'      => 0,
        'actualizados' => 0,
        'omitidos'     => 0,
        'errores'                  => 0,
        'advertencias'             => 0,
        'relaciones_no_resueltas'  => 0,
        'simulacion'               => $dry_run ? 1 : 0,
        'detalles'                 => [],
    ];

    $items = [];
    $line  = 1;

    while ( false !== ( $csv_row = seo_ie_read_csv_row( $handle ) ) ) {
        $line++;

        $has_content = false;

        foreach ( (array) $csv_row as $cell ) {
            if ( '' !== trim( (string) $cell ) ) {
                $has_content = true;
                break;
            }
        }

        if ( ! $has_content ) {
            continue;
        }

        if ( 20000 <= count( $items ) ) {
            fclose( $handle );
            wp_die(
                esc_html__(
                    'El CSV supera el límite de 20.000 páginas por proceso.',
                    'seo-system'
                )
            );
        }

        $row = seo_ie_build_csv_row( $header, $csv_row );

        $source_id   = absint( $row['page_id'] ?? 0 );
        $source_slug = sanitize_title( $row['slug'] ?? '' );
        $source_path = seo_ie_normalize_page_path( $row['ruta'] ?? '' );

        if ( '' === $source_path && array_key_exists( 'url', $row ) ) {
            $source_path = seo_ie_normalize_page_path( $row['url'] );
        }
        $parent_id   = absint( $row['parent_id'] ?? 0 );
        $parent_path = seo_ie_normalize_page_path( $row['parent_ruta'] ?? '' );
        $parent_slug = sanitize_title( $row['parent_slug'] ?? '' );

        if ( '' === $source_slug && '' !== $source_path ) {
            $path_parts  = explode( '/', $source_path );
            $source_slug = sanitize_title( end( $path_parts ) );
        }

        if ( '' === $parent_slug && '' !== $parent_path ) {
            $path_parts  = explode( '/', $parent_path );
            $parent_slug = sanitize_title( end( $path_parts ) );
        }

        if ( '' === $source_path && '' !== $source_slug ) {
            $source_path = '' !== $parent_path
                ? $parent_path . '/' . $source_slug
                : $source_slug;
        }

        $item = [
            'row'               => $row,
            'line'              => $line,
            'source_id'         => $source_id,
            'source_slug'       => $source_slug,
            'source_path'       => $source_path,
            'parent_source_id'  => $parent_id,
            'parent_slug'       => $parent_slug,
            'parent_path'       => $parent_path,
            'parent_defined'    => ! empty(
                array_intersect(
                    [ 'parent_id', 'parent_slug', 'parent_ruta' ],
                    $header
                )
            ),
            'dependency'        => null,
            'existing_id'       => 0,
            'existing_method'   => '',
            'invalid'           => false,
            'pre_errors'        => [],
            'seo_meta_payload'  => [],
            'custom_meta_payload' => [],
        ];

        if (
            0 === $source_id
            && '' === $source_path
            && '' === $source_slug
            && '' === trim( (string) ( $row['titulo'] ?? '' ) )
        ) {
            $item['pre_errors'][] = 'No contiene ID, ruta, slug ni título.';
        }

        if ( $import_seo_meta && array_key_exists( 'meta_seo', $row ) ) {
            $decoded = seo_ie_decode_page_meta_payload( $row['meta_seo'] );
            $item['seo_meta_payload'] = $decoded['data'];

            foreach ( $decoded['errors'] as $error ) {
                $item['pre_errors'][] = 'Metadatos SEO: ' . $error;
            }
        }

        if (
            $import_custom_meta
            && array_key_exists( 'meta_personalizados', $row )
        ) {
            $decoded = seo_ie_decode_page_meta_payload(
                $row['meta_personalizados']
            );
            $item['custom_meta_payload'] = $decoded['data'];

            foreach ( $decoded['errors'] as $error ) {
                $item['pre_errors'][] = 'Metadatos personalizados: ' . $error;
            }
        }

        $items[] = $item;
    }

    fclose( $handle );

    $log['procesados'] = count( $items );

    if ( empty( $items ) ) {
        seo_ie_add_log_detail( $log, 'El archivo no contiene filas de datos.' );
        seo_ie_store_log( $log );

        if ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'page' ) ) {
            return $log;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'            => 'seo-import-export',
                    'seo_ie_tab'      => 'wordpress',
                    'seo_ie_imported' => 'pages',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    $mark_invalid = static function ( $index, $message ) use ( &$items, &$log ) {
        if ( ! isset( $items[ $index ] ) ) {
            return;
        }

        if ( ! $items[ $index ]['invalid'] ) {
            $items[ $index ]['invalid'] = true;
            $log['errores']++;
        }

        seo_ie_add_log_detail(
            $log,
            sprintf(
                'Fila %d: %s',
                $items[ $index ]['line'],
                $message
            )
        );
    };

    foreach ( $items as $index => $item ) {
        foreach ( $item['pre_errors'] as $error ) {
            $mark_invalid( $index, $error );
        }
    }

    /*
     * Índices del propio CSV. Los duplicados se bloquean para impedir que una
     * relación padre-hijo apunte a una fila arbitraria.
     */
    $source_id_rows   = [];
    $source_path_rows = [];
    $source_slug_rows = [];

    foreach ( $items as $index => $item ) {
        if ( 0 < $item['source_id'] ) {
            $source_id_rows[ $item['source_id'] ][] = $index;
        }

        if ( '' !== $item['source_path'] ) {
            $source_path_rows[ $item['source_path'] ][] = $index;
        }

        if ( '' !== $item['source_slug'] ) {
            $source_slug_rows[ $item['source_slug'] ][] = $index;
        }
    }

    foreach ( $source_id_rows as $source_id => $indexes ) {
        if ( 1 < count( $indexes ) ) {
            foreach ( $indexes as $index ) {
                $mark_invalid(
                    $index,
                    sprintf( 'page_id %d aparece repetido en el CSV.', $source_id )
                );
            }
        }
    }

    foreach ( $source_path_rows as $source_path => $indexes ) {
        if ( 1 < count( $indexes ) ) {
            foreach ( $indexes as $index ) {
                $mark_invalid(
                    $index,
                    sprintf( 'La ruta «%s» aparece repetida en el CSV.', $source_path )
                );
            }
        }
    }

    $source_id_index   = [];
    $source_path_index = [];
    $source_slug_index = [];

    foreach ( $items as $index => $item ) {
        if (
            0 < $item['source_id']
            && 1 === count( $source_id_rows[ $item['source_id'] ] ?? [] )
        ) {
            $source_id_index[ $item['source_id'] ] = $index;
        }

        if (
            '' !== $item['source_path']
            && 1 === count( $source_path_rows[ $item['source_path'] ] ?? [] )
        ) {
            $source_path_index[ $item['source_path'] ] = $index;
        }

        if (
            '' !== $item['source_slug']
            && 1 === count( $source_slug_rows[ $item['source_slug'] ] ?? [] )
        ) {
            $source_slug_index[ $item['source_slug'] ] = $index;
        }
    }

    /*
     * Localizamos todas las páginas existentes antes de modificar ninguna.
     * Así, un cambio de slug en un padre no impide encontrar después al hijo.
     */
    $target_rows = [];

    foreach ( $items as $index => $item ) {
        if ( $item['invalid'] ) {
            continue;
        }

        $located = seo_ie_locate_existing_page(
            $item['source_id'],
            $item['source_path'],
            $item['source_slug']
        );

        foreach ( $located['warnings'] as $warning ) {
            seo_ie_add_log_warning(
                $log,
                sprintf( 'Fila %d: %s', $item['line'], $warning )
            );
        }

        if ( '' !== $located['error'] ) {
            $mark_invalid( $index, $located['error'] );
            continue;
        }

        $items[ $index ]['existing_id']     = absint( $located['page_id'] );
        $items[ $index ]['existing_method'] = $located['method'];

        if ( 0 < $located['page_id'] ) {
            $target_rows[ absint( $located['page_id'] ) ][] = $index;
        }
    }

    foreach ( $target_rows as $target_id => $indexes ) {
        if ( 1 < count( $indexes ) ) {
            foreach ( $indexes as $index ) {
                $mark_invalid(
                    $index,
                    sprintf(
                        'Varias filas apuntan a la misma página destino %d.',
                        $target_id
                    )
                );
            }
        }
    }

    /*
     * Resolvemos dependencias internas por ID de origen o por ruta.
     */
    foreach ( $items as $index => $item ) {
        if (
            ! $import_structure
            || $item['invalid']
            || ! $item['parent_defined']
        ) {
            continue;
        }

        $dependencies = [];

        if (
            0 < $item['parent_source_id']
            && isset( $source_id_index[ $item['parent_source_id'] ] )
        ) {
            $dependencies[] = $source_id_index[ $item['parent_source_id'] ];
        }

        if (
            '' !== $item['parent_path']
            && isset( $source_path_index[ $item['parent_path'] ] )
        ) {
            $dependencies[] = $source_path_index[ $item['parent_path'] ];
        }

        if (
            '' !== $item['parent_slug']
            && isset( $source_slug_index[ $item['parent_slug'] ] )
        ) {
            $dependencies[] = $source_slug_index[ $item['parent_slug'] ];
        }

        $dependencies = array_values( array_unique( $dependencies ) );

        if ( 1 < count( $dependencies ) ) {
            $mark_invalid(
                $index,
                'parent_id y parent_ruta apuntan a filas diferentes.'
            );
            continue;
        }

        if ( 1 === count( $dependencies ) ) {
            if ( $dependencies[0] === $index ) {
                $mark_invalid( $index, 'Una página no puede ser su propio padre.' );
                continue;
            }

            $items[ $index ]['dependency'] = $dependencies[0];
        }
    }

    // Una fila que depende de otra fila inválida tampoco puede importarse.
    do {
        $changed = false;

        foreach ( $items as $index => $item ) {
            if (
                $item['invalid']
                || null === $item['dependency']
                || ! $items[ $item['dependency'] ]['invalid']
            ) {
                continue;
            }

            $mark_invalid(
                $index,
                sprintf(
                    'La página padre de la fila %d contiene errores.',
                    $items[ $item['dependency'] ]['line']
                )
            );
            $changed = true;
        }
    } while ( $changed );

    /*
     * Orden topológico para crear o actualizar siempre antes los padres.
     */
    $indegree  = [];
    $children  = [];
    $queue     = [];
    $order     = [];

    foreach ( $items as $index => $item ) {
        if ( $item['invalid'] ) {
            continue;
        }

        $indegree[ $index ] = 0;

        if ( null !== $item['dependency'] ) {
            $indegree[ $index ] = 1;
            $children[ $item['dependency'] ][] = $index;
        }
    }

    foreach ( $indegree as $index => $degree ) {
        if ( 0 === $degree ) {
            $queue[] = $index;
        }
    }

    sort( $queue, SORT_NUMERIC );

    while ( ! empty( $queue ) ) {
        $index = array_shift( $queue );
        $order[] = $index;

        foreach ( $children[ $index ] ?? [] as $child_index ) {
            $indegree[ $child_index ]--;

            if ( 0 === $indegree[ $child_index ] ) {
                $queue[] = $child_index;
                sort( $queue, SORT_NUMERIC );
            }
        }
    }

    foreach ( $indegree as $index => $degree ) {
        if ( 0 < $degree ) {
            $mark_invalid(
                $index,
                'La jerarquía contiene un ciclo o depende de una relación cíclica.'
            );
        }
    }

    $row_to_target       = [];
    $source_id_to_target = [];
    $path_to_target      = [];

    foreach ( $order as $index ) {
        $item = $items[ $index ];

        if ( $item['invalid'] ) {
            continue;
        }

        $row         = $item['row'];
        $existing_id = absint( $item['existing_id'] );
        $is_existing = 0 < $existing_id;

        if ( 'create_only' === $mode && $is_existing ) {
            $log['omitidos']++;
            $row_to_target[ $index ] = $existing_id;

            if ( 0 < $item['source_id'] ) {
                $source_id_to_target[ $item['source_id'] ] = $existing_id;
            }

            if ( '' !== $item['source_path'] ) {
                $path_to_target[ $item['source_path'] ] = $existing_id;
            }

            seo_ie_add_log_detail(
                $log,
                sprintf(
                    'Fila %d: omitida; la página %d ya existe (%s).',
                    $item['line'],
                    $existing_id,
                    $item['existing_method']
                )
            );
            continue;
        }

        if ( 'update_only' === $mode && ! $is_existing ) {
            $log['omitidos']++;
            seo_ie_add_log_detail(
                $log,
                sprintf(
                    'Fila %d: omitida; no existe una página que actualizar.',
                    $item['line']
                )
            );
            continue;
        }

        $creating = ! $is_existing;
        $page_id  = $is_existing ? $existing_id : 0;

        $title = sanitize_text_field( $row['titulo'] ?? '' );

        if ( $creating && '' === $title ) {
            $mark_invalid(
                $index,
                'No se puede crear una página sin título.'
            );
            continue;
        }

        $parent_target = null;

        if ( $import_structure ) {
            if ( ! $item['parent_defined'] ) {
                $parent_target = $creating ? 0 : null;
            } elseif (
                0 === $item['parent_source_id']
                && '' === $item['parent_path']
                && '' === $item['parent_slug']
            ) {
                $parent_target = 0;
            } elseif ( null !== $item['dependency'] ) {
                if ( isset( $row_to_target[ $item['dependency'] ] ) ) {
                    $parent_target = $row_to_target[ $item['dependency'] ];
                }
            } else {
                if (
                    0 < $item['parent_source_id']
                    && isset(
                        $source_id_to_target[ $item['parent_source_id'] ]
                    )
                ) {
                    $parent_target = $source_id_to_target[
                        $item['parent_source_id']
                    ];
                }

                if (
                    null === $parent_target
                    && '' !== $item['parent_path']
                    && isset( $path_to_target[ $item['parent_path'] ] )
                ) {
                    $parent_target = $path_to_target[ $item['parent_path'] ];
                }

                if (
                    null === $parent_target
                    && '' !== $item['parent_path']
                ) {
                    $parent = seo_ie_get_page_by_path( $item['parent_path'] );

                    if ( $parent instanceof WP_Post ) {
                        $parent_target = absint( $parent->ID );
                    }
                }

                /*
                 * El ID externo se usa después de la ruta porque los IDs
                 * pueden cambiar entre instalaciones de WordPress.
                 */
                if ( null === $parent_target && 0 < $item['parent_source_id'] ) {
                    $parent = get_post( $item['parent_source_id'] );

                    if (
                        $parent instanceof WP_Post
                        && 'page' === $parent->post_type
                    ) {
                        $parent_target = absint( $parent->ID );
                    }
                }

                if (
                    null === $parent_target
                    && '' !== $item['parent_slug']
                ) {
                    $parent_ids = seo_ie_find_page_ids_by_slug(
                        $item['parent_slug']
                    );

                    if ( 1 === count( $parent_ids ) ) {
                        $parent_target = absint( $parent_ids[0] );
                    } elseif ( 1 < count( $parent_ids ) ) {
                        $mark_invalid(
                            $index,
                            sprintf(
                                'El slug del padre «%s» es ambiguo.',
                                $item['parent_slug']
                            )
                        );
                        continue;
                    }
                }
            }

            if ( null === $parent_target && $item['parent_defined'] ) {
                $mark_invalid(
                    $index,
                    'No se pudo resolver la página padre.'
                );
                continue;
            }

            if (
                0 < $page_id
                && 0 < intval( $parent_target )
                && seo_ie_page_parent_would_cycle(
                    $page_id,
                    absint( $parent_target )
                )
            ) {
                $mark_invalid(
                    $index,
                    'La relación padre propuesta produciría un ciclo.'
                );
                continue;
            }
        }

        $status = sanitize_key( $row['estado'] ?? '' );

        if (
            '' !== $status
            && ! in_array( $status, seo_ie_page_allowed_statuses(), true )
        ) {
            seo_ie_add_log_warning(
                $log,
                sprintf(
                    'Fila %d: estado «%s» no válido; se conservará el actual o se usará draft.',
                    $item['line'],
                    $status
                )
            );
            $status = '';
        }

        if (
            $creating
            && 'future' === $status
            && (
                ! array_key_exists( 'fecha', $row )
                || ! seo_ie_is_valid_page_datetime( $row['fecha'] )
            )
        ) {
            seo_ie_add_log_warning(
                $log,
                sprintf(
                    'Fila %d: una página future necesita fecha válida; se creará como draft.',
                    $item['line']
                )
            );
            $status = 'draft';
        }

        $author_id = $import_author_date
            ? seo_ie_resolve_page_author( $row )
            : 0;

        if (
            $import_author_date
            && (
                0 < absint( $row['autor_id'] ?? 0 )
                || '' !== trim( (string) ( $row['autor_login'] ?? '' ) )
            )
            && 0 === $author_id
        ) {
            seo_ie_add_log_warning(
                $log,
                sprintf(
                    'Fila %d: no se encontró el autor indicado.',
                    $item['line']
                )
            );
        }

        $page_role_defined = $import_relations && array_key_exists( 'seo_role', $row );
        $page_role         = $page_role_defined ? sanitize_key( $row['seo_role'] ) : '';
        $page_role_valid   = true;

        if (
            $page_role_defined
            && '' !== $page_role
            && ! in_array( $page_role, seo_ie_page_structural_roles(), true )
        ) {
            $page_role_valid = false;
            seo_ie_add_log_warning(
                $log,
                sprintf(
                    'Fila %d: seo_role «%s» no reconocido; el contenido se importará, pero no se modificará el rol SEO.',
                    $item['line'],
                    $page_role
                )
            );
        }

        $product_relation = [
            'defined'         => false,
            'valid'           => true,
            'term_ids'        => [],
            'requested_count' => 0,
            'unresolved'      => [],
        ];

        if ( $import_relations ) {
            $product_relation = seo_ie_resolve_product_cat_relation_for_import(
                $row,
                $item['line'],
                $log
            );
        }

        $effective_page_role = '';
        if ( $page_role_defined && $page_role_valid ) {
            $effective_page_role = $page_role;
        } elseif ( ! $page_role_defined && ! $creating && 0 < $page_id ) {
            $effective_page_role = seo_ie_get_page_seo_role_for_export( $page_id );
        }

        if (
            $import_relations
            && ! empty( $product_relation['defined'] )
            && 0 < absint( $product_relation['requested_count'] ?? 0 )
            && 'landing' !== $effective_page_role
        ) {
            $product_relation['valid']    = false;
            $product_relation['term_ids'] = [];
            seo_ie_add_relation_issue(
                $log,
                sprintf(
                    'Fila %d: se han indicado product_cat, pero la página no tiene seo_role=landing. El contenido se importará y la relación comercial landing_to_category quedará vacía.',
                    $item['line']
                )
            );
        }

        if ( $dry_run && $import_image ) {
            $preview_image_id = absint(
                $row['imagen_destacada_id'] ?? 0
            );
            $preview_image_url = esc_url_raw(
                trim( (string) ( $row['imagen_destacada'] ?? '' ) )
            );

            if (
                0 < $preview_image_id
                && 'attachment' !== get_post_type( $preview_image_id )
                && '' === $preview_image_url
            ) {
                seo_ie_add_log_warning(
                    $log,
                    sprintf(
                        'Fila %d: el ID de imagen %d no corresponde a un adjunto.',
                        $item['line'],
                        $preview_image_id
                    )
                );
            }

            if (
                '' !== $preview_image_url
                && ! wp_http_validate_url( $preview_image_url )
            ) {
                seo_ie_add_log_warning(
                    $log,
                    sprintf(
                        'Fila %d: la URL de imagen no es válida.',
                        $item['line']
                    )
                );
            }
        }

        if ( $dry_run ) {
            $target_id = $creating ? -( $index + 1 ) : $page_id;
            $row_to_target[ $index ] = $target_id;

            if ( 0 < $item['source_id'] ) {
                $source_id_to_target[ $item['source_id'] ] = $target_id;
            }

            if ( '' !== $item['source_path'] ) {
                $path_to_target[ $item['source_path'] ] = $target_id;
            }

            if ( $creating ) {
                $log['creados']++;
            } else {
                $log['actualizados']++;
            }

            $log['correctos']++;
            continue;
        }

        $post_data = [
            'post_type' => 'page',
        ];

        if ( ! $creating ) {
            $post_data['ID'] = $page_id;
        }

        if ( $creating ) {
            $post_data['post_title']  = $title;
            $post_data['post_status'] = '' !== $status ? $status : 'draft';
        }

        if ( $import_core ) {
            if ( '' !== $title ) {
                $post_data['post_title'] = $title;
            }

            if (
                array_key_exists( 'slug', $row )
                && '' !== trim( (string) $row['slug'] )
            ) {
                $post_data['post_name'] = sanitize_title( $row['slug'] );
            }

            if ( '' !== $status ) {
                $post_data['post_status'] = $status;
            }

            if ( array_key_exists( 'excerpt', $row ) ) {
                $post_data['post_excerpt'] = seo_ie_csv_to_utf8(
                    (string) $row['excerpt']
                );
            }

            if ( array_key_exists( 'description', $row ) ) {
                $post_data['post_content'] = seo_ie_csv_to_utf8(
                    (string) $row['description']
                );
            }
        }

        if ( $import_structure ) {
            if ( null !== $parent_target ) {
                $post_data['post_parent'] = max( 0, intval( $parent_target ) );
            }

            if ( array_key_exists( 'menu_order', $row ) ) {
                $post_data['menu_order'] = intval( $row['menu_order'] );
            }

            if ( array_key_exists( 'comentarios', $row ) ) {
                $comment_status = sanitize_key( $row['comentarios'] );

                if ( in_array( $comment_status, [ 'open', 'closed' ], true ) ) {
                    $post_data['comment_status'] = $comment_status;
                }
            }

            if ( array_key_exists( 'pings', $row ) ) {
                $ping_status = sanitize_key( $row['pings'] );

                if ( in_array( $ping_status, [ 'open', 'closed' ], true ) ) {
                    $post_data['ping_status'] = $ping_status;
                }
            }
        }

        if ( $import_author_date ) {
            if ( 0 < $author_id ) {
                $post_data['post_author'] = $author_id;
            }

            if (
                array_key_exists( 'fecha', $row )
                && seo_ie_is_valid_page_datetime( $row['fecha'] )
            ) {
                $post_data['post_date'] = trim( (string) $row['fecha'] );
                $post_data['edit_date'] = true;
            } elseif (
                array_key_exists( 'fecha', $row )
                && '' !== trim( (string) $row['fecha'] )
            ) {
                seo_ie_add_log_warning(
                    $log,
                    sprintf(
                        'Fila %d: fecha local no válida.',
                        $item['line']
                    )
                );
            }

            if (
                array_key_exists( 'fecha_gmt', $row )
                && seo_ie_is_valid_page_datetime( $row['fecha_gmt'] )
            ) {
                $post_data['post_date_gmt'] = trim(
                    (string) $row['fecha_gmt']
                );
            } elseif (
                array_key_exists( 'fecha_gmt', $row )
                && '' !== trim( (string) $row['fecha_gmt'] )
            ) {
                seo_ie_add_log_warning(
                    $log,
                    sprintf(
                        'Fila %d: fecha GMT no válida.',
                        $item['line']
                    )
                );
            }
        }

        /*
         * Las plantillas publicas de SEO System se resuelven por seo_nodes +
         * seo_templates + template-loader.php. Nunca deben viajar mediante
         * _wp_page_template, porque sus archivos viven en el plugin y no en
         * el tema activo. Un valor heredado en ese meta puede hacer que
         * WordPress rechace wp_update_post() con "Invalid page template".
         */
        if ( ! $creating && 0 < $page_id ) {
            delete_post_meta( $page_id, '_wp_page_template' );
            clean_post_cache( $page_id );
        }

        $needs_post_write = $creating || 2 < count( $post_data );

        if ( $needs_post_write ) {
            $saved_id = $creating
                ? wp_insert_post( wp_slash( $post_data ), true )
                : wp_update_post( wp_slash( $post_data ), true );
        } else {
            $saved_id = $page_id;
        }

        if ( is_wp_error( $saved_id ) || 0 === absint( $saved_id ) ) {
            $log['errores']++;
            seo_ie_add_log_detail(
                $log,
                sprintf(
                    'Fila %d: no se pudo %s la página: %s',
                    $item['line'],
                    $creating ? 'crear' : 'actualizar',
                    is_wp_error( $saved_id )
                        ? $saved_id->get_error_message()
                        : 'WordPress devolvió un ID vacío.'
                )
            );

            if ( ! $creating && 0 < $page_id ) {
                $row_to_target[ $index ] = $page_id;

                if ( 0 < $item['source_id'] ) {
                    $source_id_to_target[ $item['source_id'] ] = $page_id;
                }

                if ( '' !== $item['source_path'] ) {
                    $path_to_target[ $item['source_path'] ] = $page_id;
                }
            }
            continue;
        }

        $page_id = absint( $saved_id );
        $row_to_target[ $index ] = $page_id;

        if ( 0 < $item['source_id'] ) {
            $source_id_to_target[ $item['source_id'] ] = $page_id;
        }

        if ( '' !== $item['source_path'] ) {
            $path_to_target[ $item['source_path'] ] = $page_id;
        }

        $page_role_saved = ! ( $page_role_defined && ! $page_role_valid );

        if ( $import_relations && $page_role_defined && $page_role_valid ) {
            $role_result = seo_ie_apply_page_seo_role_for_import( $page_id, $page_role );
            if ( is_wp_error( $role_result ) ) {
                $page_role_saved = false;
                seo_ie_add_log_warning(
                    $log,
                    sprintf(
                        'Fila %d, página %d: no se pudo guardar seo_role: %s',
                        $item['line'],
                        $page_id,
                        $role_result->get_error_message()
                    )
                );
            }
        }

        if ( $import_relations && $page_role_saved ) {
            $clear_landing_relation = $page_role_defined
                && $page_role_valid
                && 'landing' !== $page_role;

            if ( ! empty( $product_relation['defined'] ) ) {
                if ( 'landing' === $effective_page_role ) {
                    seo_ie_apply_product_cat_relation_import(
                        'landing',
                        $page_id,
                        $product_relation,
                        $item['line'],
                        $log
                    );
                } else {
                    $cleared = seo_ie_replace_product_cat_relations( 'landing', $page_id, [] );
                    if ( is_wp_error( $cleared ) ) {
                        seo_ie_add_relation_issue(
                            $log,
                            sprintf(
                                'Fila %d, página %d: no se pudo dejar vacía la relación landing_to_category: %s',
                                $item['line'],
                                $page_id,
                                $cleared->get_error_message()
                            )
                        );
                    }
                }
            } elseif ( $clear_landing_relation ) {
                $cleared = seo_ie_replace_product_cat_relations( 'landing', $page_id, [] );
                if ( is_wp_error( $cleared ) ) {
                    seo_ie_add_relation_issue(
                        $log,
                        sprintf(
                            'Fila %d, página %d: el rol dejó de ser landing, pero no se pudo limpiar landing_to_category: %s',
                            $item['line'],
                            $page_id,
                            $cleared->get_error_message()
                        )
                    );
                }
            }
        }

        if ( $import_seo_meta && array_key_exists( 'meta_seo', $row ) ) {
            seo_ie_apply_page_meta_payload(
                $page_id,
                $item['seo_meta_payload'],
                $log,
                $item['line'],
                'el metadato SEO'
            );
        }

        if (
            $import_custom_meta
            && array_key_exists( 'meta_personalizados', $row )
        ) {
            seo_ie_apply_page_meta_payload(
                $page_id,
                $item['custom_meta_payload'],
                $log,
                $item['line'],
                'el metadato personalizado'
            );
        }

        if ( $import_image ) {
            seo_ie_import_page_thumbnail(
                $page_id,
                $row,
                $item['line'],
                $log
            );
        }

        clean_post_cache( $page_id );

        if ( $creating ) {
            $log['creados']++;
        } else {
            $log['actualizados']++;
        }

        $log['correctos']++;
    }

    if ( 0 < absint( $log['relaciones_no_resueltas'] ?? 0 ) ) {
        seo_ie_add_log_detail(
            $log,
            sprintf(
                'Relaciones comerciales no resueltas: %d. Revisa después el informe de páginas/posts sin product_cat.',
                absint( $log['relaciones_no_resueltas'] )
            )
        );
    }

    if ( $dry_run ) {
        seo_ie_add_log_detail(
            $log,
            'Simulación completada: no se ha escrito ningún dato en WordPress.'
        );
    } else {
        seo_ie_add_log_detail(
            $log,
            'La importación no elimina páginas ni revisiones existentes.'
        );
    }

    seo_ie_store_log( $log );

    if ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'page' ) ) {
        return $log;
    }

    wp_safe_redirect(
        add_query_arg(
            [
                'page'            => 'seo-import-export',
                'seo_ie_tab'      => 'wordpress',
                'seo_ie_imported' => 'pages',
            ],
            admin_url( 'admin.php' )
        )
    );
    exit;
}



/**
 * Devuelve hasta tres entradas que comparten un slug.
 *
 * @param string $slug Slug recibido.
 * @return int[]
 */
function seo_ie_find_post_ids_by_slug( $slug ) {
    $slug = sanitize_title( $slug );

    if ( '' === $slug ) {
        return [];
    }

    return array_values(
        array_filter(
            array_map(
                'absint',
                get_posts(
                    [
                        'post_type'              => 'post',
                        'post_status'            => 'any',
                        'name'                   => $slug,
                        'posts_per_page'         => 3,
                        'orderby'                => 'ID',
                        'order'                  => 'ASC',
                        'fields'                 => 'ids',
                        'no_found_rows'          => true,
                        'cache_results'          => false,
                        'update_post_meta_cache' => false,
                        'update_post_term_cache' => false,
                    ]
                )
            )
        )
    );
}

/**
 * Extrae un slug de una URL de entrada.
 *
 * @param string $url URL o ruta.
 * @return string
 */
function seo_ie_post_slug_from_url( $url ) {
    $url = trim( seo_ie_csv_to_utf8( (string) $url ) );

    if ( '' === $url ) {
        return '';
    }

    $path = wp_parse_url( $url, PHP_URL_PATH );
    $path = is_string( $path ) ? trim( rawurldecode( $path ), '/' ) : trim( $url, '/' );

    if ( '' === $path ) {
        return '';
    }

    $parts = array_values( array_filter( explode( '/', $path ) ) );

    return empty( $parts ) ? '' : sanitize_title( end( $parts ) );
}

/**
 * Busca la entrada destino de una fila por ID y, como respaldo, por slug.
 *
 * @param int    $source_id   ID del CSV.
 * @param string $source_slug Slug del CSV.
 * @param string $source_url  URL del CSV.
 * @return array
 */
function seo_ie_locate_existing_post( $source_id, $source_slug, $source_url = '' ) {
    $result = [
        'post_id'  => 0,
        'method'   => '',
        'error'    => '',
        'warnings' => [],
    ];

    $source_id   = absint( $source_id );
    $source_slug = sanitize_title( $source_slug );
    $url_slug    = seo_ie_post_slug_from_url( $source_url );

    if ( '' === $source_slug ) {
        $source_slug = $url_slug;
    } elseif ( '' !== $url_slug && $url_slug !== $source_slug ) {
        $result['warnings'][] = sprintf(
            'El slug «%s» y el slug extraído de la URL «%s» no coinciden; se priorizará la columna slug.',
            $source_slug,
            $url_slug
        );
    }

    if ( 0 < $source_id ) {
        $post = get_post( $source_id );

        if ( $post instanceof WP_Post && 'post' === $post->post_type ) {
            if ( '' !== $source_slug && $post->post_name !== $source_slug ) {
                $slug_ids = seo_ie_find_post_ids_by_slug( $source_slug );

                if ( ! empty( $slug_ids ) && ! in_array( $source_id, $slug_ids, true ) ) {
                    $result['error'] = sprintf(
                        'El post_id %d y el slug «%s» identifican entradas diferentes.',
                        $source_id,
                        $source_slug
                    );
                    return $result;
                }

                $result['warnings'][] = sprintf(
                    'El post_id %d existe con slug «%s» y no «%s»; se priorizará el ID.',
                    $source_id,
                    $post->post_name,
                    $source_slug
                );
            }

            $result['post_id'] = $source_id;
            $result['method']  = 'ID';
            return $result;
        }

        if ( $post instanceof WP_Post ) {
            $result['warnings'][] = sprintf(
                'El ID %d pertenece al tipo «%s» y no se utilizará como entrada.',
                $source_id,
                $post->post_type
            );
        } else {
            $result['warnings'][] = sprintf(
                'El ID %d no existe; se intentará localizar por slug.',
                $source_id
            );
        }
    }

    if ( '' !== $source_slug ) {
        $post_ids = seo_ie_find_post_ids_by_slug( $source_slug );

        if ( 1 === count( $post_ids ) ) {
            $result['post_id'] = absint( $post_ids[0] );
            $result['method']  = 'slug';
            return $result;
        }

        if ( 1 < count( $post_ids ) ) {
            $result['error'] = sprintf(
                'El slug «%s» coincide con varias entradas; utiliza post_id.',
                $source_slug
            );
        }
    }

    return $result;
}

/**
 * Codifica una lista sencilla para una celda CSV.
 *
 * @param array $values Valores.
 * @return string
 */
function seo_ie_encode_post_list( $values ) {
    $values = array_values( (array) $values );

    if ( empty( $values ) ) {
        return '[]';
    }

    $encoded = wp_json_encode(
        $values,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    return is_string( $encoded ) ? $encoded : '[]';
}

/**
 * Decodifica listas JSON y admite como respaldo valores separados por | o coma.
 *
 * @param mixed $value Valor CSV.
 * @return array
 */
function seo_ie_decode_post_list( $value ) {
    $value = trim( seo_ie_csv_to_utf8( (string) $value ) );

    if ( '' === $value || '[]' === $value ) {
        return [];
    }

    if ( '[' === substr( $value, 0, 1 ) ) {
        $decoded = json_decode( $value, true );
        if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
            return array_values( $decoded );
        }
    }

    $separator = false !== strpos( $value, '|' ) ? '|' : ',';

    return array_values(
        array_filter(
            array_map( 'trim', explode( $separator, $value ) ),
            static function ( $item ) {
                return '' !== (string) $item;
            }
        )
    );
}

/**
 * Resuelve las categorías o etiquetas descritas por una fila.
 * Devuelve null cuando el CSV no contiene columnas de esa taxonomía.
 *
 * @param string $taxonomy Taxonomía.
 * @param array  $row      Fila.
 * @param int    $line     Línea.
 * @param array  $log      Log.
 * @return int[]|null
 */
function seo_ie_resolve_post_terms( $taxonomy, $row, $line, &$log ) {
    $is_category = 'category' === $taxonomy;
    $ids_key     = $is_category ? 'categorias_ids' : 'etiquetas_ids';
    $slugs_key   = $is_category ? 'categorias_slugs' : 'etiquetas_slugs';
    $names_key   = $is_category ? 'categorias_nombres' : 'etiquetas_nombres';
    $label       = $is_category ? 'categoría' : 'etiqueta';

    if (
        ! array_key_exists( $ids_key, $row )
        && ! array_key_exists( $slugs_key, $row )
        && ! array_key_exists( $names_key, $row )
    ) {
        return null;
    }

    $ids   = seo_ie_decode_post_list( $row[ $ids_key ] ?? '' );
    $slugs = seo_ie_decode_post_list( $row[ $slugs_key ] ?? '' );
    $names = seo_ie_decode_post_list( $row[ $names_key ] ?? '' );
    $count = max( count( $ids ), count( $slugs ), count( $names ) );
    $term_ids = [];

    for ( $i = 0; $i < $count; $i++ ) {
        $term_id = absint( $ids[ $i ] ?? 0 );
        $slug    = sanitize_title( $slugs[ $i ] ?? '' );
        $name    = sanitize_text_field( $names[ $i ] ?? '' );
        $term    = null;

        if ( '' !== $slug ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
        }

        if ( ! $term && '' !== $name ) {
            $term = get_term_by( 'name', $name, $taxonomy );
        }

        if ( ! $term && 0 < $term_id ) {
            $candidate = get_term( $term_id, $taxonomy );
            if ( $candidate && ! is_wp_error( $candidate ) ) {
                $term = $candidate;
            }
        }

        if ( ! $term && ( '' !== $name || '' !== $slug ) ) {
            $inserted = wp_insert_term(
                '' !== $name ? $name : $slug,
                $taxonomy,
                '' !== $slug ? [ 'slug' => $slug ] : []
            );

            if ( is_wp_error( $inserted ) ) {
                seo_ie_add_log_warning(
                    $log,
                    sprintf(
                        'Fila %d: no se pudo crear la %s «%s»: %s',
                        $line,
                        $label,
                        '' !== $name ? $name : $slug,
                        $inserted->get_error_message()
                    )
                );
                continue;
            }

            $term = get_term( absint( $inserted['term_id'] ), $taxonomy );
        }

        if ( $term && ! is_wp_error( $term ) ) {
            $term_ids[] = absint( $term->term_id );
        } elseif ( 0 < $term_id || '' !== $slug || '' !== $name ) {
            seo_ie_add_log_warning(
                $log,
                sprintf(
                    'Fila %d: no se pudo resolver la %s «%s».',
                    $line,
                    $label,
                    '' !== $name ? $name : ( '' !== $slug ? $slug : (string) $term_id )
                )
            );
        }
    }

    return array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
}

/**
 * Convierte valores habituales de checkbox a booleano.
 *
 * @param mixed $value Valor.
 * @return bool|null
 */
function seo_ie_post_bool_value( $value ) {
    if ( is_bool( $value ) ) {
        return $value;
    }

    $value = strtolower( trim( (string) $value ) );

    if ( in_array( $value, [ '1', 'yes', 'si', 'sí', 'true', 'on' ], true ) ) {
        return true;
    }

    if ( in_array( $value, [ '0', 'no', 'false', 'off', '' ], true ) ) {
        return false;
    }

    return null;
}

/**
 * Importa o elimina la imagen destacada de una entrada.
 *
 * @param int   $post_id ID de entrada.
 * @param array $row     Fila.
 * @param int   $line    Línea.
 * @param array $log     Log.
 * @return void
 */
function seo_ie_import_post_thumbnail( $post_id, $row, $line, &$log ) {
    $has_id_column  = array_key_exists( 'imagen_destacada_id', $row );
    $has_url_column = array_key_exists( 'imagen_destacada', $row );

    if ( ! $has_id_column && ! $has_url_column ) {
        return;
    }

    $attachment_id = absint( $row['imagen_destacada_id'] ?? 0 );
    $image_url     = esc_url_raw( trim( (string) ( $row['imagen_destacada'] ?? '' ) ) );

    if ( 0 === $attachment_id && '' === $image_url ) {
        delete_post_thumbnail( $post_id );
        return;
    }

    if ( 0 < $attachment_id && 'attachment' === get_post_type( $attachment_id ) ) {
        set_post_thumbnail( $post_id, $attachment_id );
        return;
    }

    if ( 0 < $attachment_id ) {
        seo_ie_add_log_warning(
            $log,
            sprintf(
                'Fila %d, entrada %d: el adjunto %d no existe; se probará la URL.',
                $line,
                $post_id,
                $attachment_id
            )
        );
    }

    if ( '' === $image_url ) {
        return;
    }

    $attachment_id = attachment_url_to_postid( $image_url );

    if ( 0 < $attachment_id ) {
        set_post_thumbnail( $post_id, $attachment_id );
        return;
    }

    if ( ! wp_http_validate_url( $image_url ) ) {
        seo_ie_add_log_warning(
            $log,
            sprintf( 'Fila %d, entrada %d: la URL de imagen no es válida.', $line, $post_id )
        );
        return;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $sideloaded_id = media_sideload_image( $image_url, $post_id, null, 'id' );

    if ( is_wp_error( $sideloaded_id ) ) {
        seo_ie_add_log_warning(
            $log,
            sprintf(
                'Fila %d, entrada %d: no se pudo importar la imagen: %s',
                $line,
                $post_id,
                $sideloaded_id->get_error_message()
            )
        );
        return;
    }

    set_post_thumbnail( $post_id, absint( $sideloaded_id ) );
}

/**
 * Exporta las entradas de WordPress a CSV.
 *
 * @return void
 */
function seo_export_posts_csv() {
    if ( ! isset( $_POST['seo_export_posts'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para exportar entradas.', 'seo-system' ) );
    }

    check_admin_referer( 'seo_export_posts_csv', 'seo_export_posts_nonce' );

    $allowed_statuses  = seo_ie_page_allowed_statuses();
    $selected_statuses = array_values(
        array_intersect(
            array_map( 'sanitize_key', (array) ( $_POST['export_post_statuses'] ?? [] ) ),
            $allowed_statuses
        )
    );

    if ( empty( $selected_statuses ) ) {
        $selected_statuses = $allowed_statuses;
    }

    $posts = get_posts(
        [
            'post_type'              => 'post',
            'post_status'            => $selected_statuses,
            'posts_per_page'         => -1,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'cache_results'          => false,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => true,
        ]
    );

    $filename = 'seo_posts_' . wp_date( 'Ymd_His' ) . '.csv';

    seo_ie_store_log(
        [
            'operacion'    => 'Exportación de entradas',
            'archivo'      => $filename,
            'procesados'   => count( $posts ),
            'correctos'    => count( $posts ),
            'errores'      => 0,
            'advertencias' => 0,
            'detalles'     => [
                'Se exportaron contenido, categorías editoriales, relación comercial con product_cat, etiquetas, formato, autor, fechas, imagen y metadatos.',
                'No se exportaron revisiones, bloqueos de edición ni datos de papelera.',
            ],
        ]
    );

    $output = seo_ie_open_csv_download( $filename );

    seo_ie_write_csv_row(
        $output,
        [
            'post_id', 'titulo', 'slug', 'url', 'estado',
            'autor_id', 'autor_login', 'fecha', 'fecha_gmt',
            'fecha_modificada', 'fecha_modificada_gmt', 'comentarios', 'pings',
            'excerpt', 'description',
            'categorias_ids', 'categorias_slugs', 'categorias_nombres',
            'product_cat_relacion_ids', 'product_cat_relacion_slugs', 'product_cat_relacion_nombres',
            'etiquetas_ids', 'etiquetas_slugs', 'etiquetas_nombres',
            'formato', 'sticky',
            'imagen_destacada_id', 'imagen_destacada',
            'meta_seo', 'meta_personalizados',
        ]
    );

    foreach ( $posts as $post ) {
        $post_id    = absint( $post->ID );
        $author     = get_userdata( absint( $post->post_author ) );
        $image_id   = get_post_thumbnail_id( $post_id );
        $meta       = seo_ie_get_page_meta_payload( $post_id );
        $categories = wp_get_post_terms( $post_id, 'category' );
        $tags       = wp_get_post_terms( $post_id, 'post_tag' );
        $categories   = is_wp_error( $categories ) ? [] : $categories;
        $tags         = is_wp_error( $tags ) ? [] : $tags;
        $product_cats = seo_ie_get_product_cat_relation_payload_for_export( 'post', $post_id );
        $format       = get_post_format( $post_id );

        seo_ie_write_csv_row(
            $output,
            [
                $post_id,
                $post->post_title,
                $post->post_name,
                get_permalink( $post_id ),
                $post->post_status,
                absint( $post->post_author ),
                $author instanceof WP_User ? $author->user_login : '',
                $post->post_date,
                $post->post_date_gmt,
                $post->post_modified,
                $post->post_modified_gmt,
                $post->comment_status,
                $post->ping_status,
                $post->post_excerpt,
                $post->post_content,
                seo_ie_encode_post_list( wp_list_pluck( $categories, 'term_id' ) ),
                seo_ie_encode_post_list( wp_list_pluck( $categories, 'slug' ) ),
                seo_ie_encode_post_list( wp_list_pluck( $categories, 'name' ) ),
                seo_ie_encode_post_list( $product_cats['ids'] ),
                seo_ie_encode_post_list( $product_cats['slugs'] ),
                seo_ie_encode_post_list( $product_cats['names'] ),
                seo_ie_encode_post_list( wp_list_pluck( $tags, 'term_id' ) ),
                seo_ie_encode_post_list( wp_list_pluck( $tags, 'slug' ) ),
                seo_ie_encode_post_list( wp_list_pluck( $tags, 'name' ) ),
                $format ? $format : 'standard',
                is_sticky( $post_id ) ? 1 : 0,
                absint( $image_id ),
                0 < $image_id ? wp_get_attachment_url( $image_id ) : '',
                seo_ie_encode_page_meta_payload( $meta['seo'] ),
                seo_ie_encode_page_meta_payload( $meta['custom'] ),
            ]
        );
    }

    fclose( $output );
    exit;
}

/**
 * Importa entradas desde el CSV generado por SEO System.
 * Puede crear, actualizar o simular. Nunca elimina entradas.
 *
 * @return void|array
 */
function seo_import_posts_csv() {
    if ( ! isset( $_POST['seo_import_posts'] ) ) {
        return;
    }

    if ( function_exists( 'seo_ie_batch_guard_manual_import' ) ) {
        seo_ie_batch_guard_manual_import( 'post' );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para importar entradas.', 'seo-system' ) );
    }

    check_admin_referer( 'seo_import_posts_csv', 'seo_import_posts_nonce' );

    if (
        empty( $_FILES['posts_csv']['tmp_name'] )
        || (
            ! ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'post' ) )
            && ! is_uploaded_file( $_FILES['posts_csv']['tmp_name'] )
        )
    ) {
        wp_die( esc_html__( 'No se ha recibido un CSV de entradas válido.', 'seo-system' ) );
    }

    $mode = sanitize_key( $_POST['post_import_mode'] ?? 'create_update' );
    if ( ! in_array( $mode, [ 'create_update', 'create_only', 'update_only' ], true ) ) {
        $mode = 'create_update';
    }

    $dry_run            = ! empty( $_POST['post_import_dry_run'] );
    $import_core        = ! empty( $_POST['import_post_core'] );
    $import_taxonomies  = ! empty( $_POST['import_post_taxonomies'] );
    $import_author_date = ! empty( $_POST['import_post_author_date'] );
    $import_seo_meta    = ! empty( $_POST['import_post_seo_meta'] );
    $import_custom_meta = ! empty( $_POST['import_post_custom_meta'] );
    $import_image       = ! empty( $_POST['import_post_image'] );
    $import_relations   = ! empty( $_POST['import_post_relations'] );

    if (
        ! $import_core && ! $import_taxonomies && ! $import_author_date
        && ! $import_seo_meta && ! $import_custom_meta && ! $import_image
        && ! $import_relations
    ) {
        wp_die( esc_html__( 'Selecciona al menos un bloque de datos para importar.', 'seo-system' ) );
    }

    $handle = fopen( $_FILES['posts_csv']['tmp_name'], 'r' );
    if ( false === $handle ) {
        wp_die( esc_html__( 'No se pudo abrir el CSV de entradas.', 'seo-system' ) );
    }

    $header = seo_ie_read_csv_row( $handle );
    if ( false === $header ) {
        fclose( $handle );
        wp_die( esc_html__( 'El CSV de entradas está vacío.', 'seo-system' ) );
    }

    $header = seo_ie_normalize_csv_header( $header, 'post' );
    $header_counts = array_count_values(
        array_values(
            array_filter(
                $header,
                static function ( $column ) {
                    return '' !== (string) $column;
                }
            )
        )
    );
    $duplicate_headers = array_keys(
        array_filter(
            $header_counts,
            static function ( $count ) {
                return 1 < $count;
            }
        )
    );

    if ( ! empty( $duplicate_headers ) ) {
        fclose( $handle );
        wp_die(
            sprintf(
                esc_html__( 'El CSV contiene cabeceras duplicadas después de normalizarlas: %s.', 'seo-system' ),
                esc_html( implode( ', ', $duplicate_headers ) )
            )
        );
    }

    $identity_columns = array_intersect( [ 'post_id', 'slug', 'url' ], $header );
    if ( empty( $identity_columns ) && ( 'update_only' === $mode || ! in_array( 'titulo', $header, true ) ) ) {
        fclose( $handle );
        wp_die( esc_html__( 'El CSV necesita post_id, slug o url. El título solo permite crear entradas nuevas.', 'seo-system' ) );
    }

    $log = [
        'operacion'    => $dry_run ? 'Simulación de importación de entradas' : 'Importación de entradas',
        'archivo'      => sanitize_file_name( $_FILES['posts_csv']['name'] ),
        'procesados'   => 0,
        'correctos'    => 0,
        'creados'      => 0,
        'actualizados' => 0,
        'omitidos'     => 0,
        'errores'                  => 0,
        'advertencias'             => 0,
        'relaciones_no_resueltas'  => 0,
        'simulacion'               => $dry_run ? 1 : 0,
        'detalles'                 => [],
    ];

    $items = [];
    $line  = 1;

    while ( false !== ( $csv_row = seo_ie_read_csv_row( $handle ) ) ) {
        $line++;
        $has_content = false;

        foreach ( (array) $csv_row as $cell ) {
            if ( '' !== trim( (string) $cell ) ) {
                $has_content = true;
                break;
            }
        }

        if ( ! $has_content ) {
            continue;
        }

        if ( 20000 <= count( $items ) ) {
            fclose( $handle );
            wp_die( esc_html__( 'El CSV supera el límite de 20.000 entradas por proceso.', 'seo-system' ) );
        }

        $row         = seo_ie_build_csv_row( $header, $csv_row );
        $source_id   = absint( $row['post_id'] ?? 0 );
        $source_slug = sanitize_title( $row['slug'] ?? '' );
        $source_url  = trim( (string) ( $row['url'] ?? '' ) );

        if ( '' === $source_slug && '' !== $source_url ) {
            $source_slug = seo_ie_post_slug_from_url( $source_url );
        }

        $item = [
            'row'                 => $row,
            'line'                => $line,
            'source_id'           => $source_id,
            'source_slug'         => $source_slug,
            'source_url'          => $source_url,
            'existing_id'         => 0,
            'existing_method'     => '',
            'invalid'             => false,
            'pre_errors'          => [],
            'seo_meta_payload'    => [],
            'custom_meta_payload' => [],
        ];

        if ( 0 === $source_id && '' === $source_slug && '' === trim( (string) ( $row['titulo'] ?? '' ) ) ) {
            $item['pre_errors'][] = 'No contiene ID, slug, URL ni título.';
        }

        if ( $import_seo_meta && array_key_exists( 'meta_seo', $row ) ) {
            $decoded = seo_ie_decode_page_meta_payload( $row['meta_seo'] );
            $item['seo_meta_payload'] = $decoded['data'];
            foreach ( $decoded['errors'] as $error ) {
                $item['pre_errors'][] = 'Metadatos SEO: ' . $error;
            }
        }

        if ( $import_custom_meta && array_key_exists( 'meta_personalizados', $row ) ) {
            $decoded = seo_ie_decode_page_meta_payload( $row['meta_personalizados'] );
            $item['custom_meta_payload'] = $decoded['data'];
            foreach ( $decoded['errors'] as $error ) {
                $item['pre_errors'][] = 'Metadatos personalizados: ' . $error;
            }
        }

        $items[] = $item;
    }
    fclose( $handle );

    $log['procesados'] = count( $items );

    if ( empty( $items ) ) {
        seo_ie_add_log_detail( $log, 'El archivo no contiene filas de datos.' );
        seo_ie_store_log( $log );
        if ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'post' ) ) {
            return $log;
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'wordpress', 'seo_ie_imported' => 'posts' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    $mark_invalid = static function ( $index, $message ) use ( &$items, &$log ) {
        if ( ! isset( $items[ $index ] ) ) {
            return;
        }
        if ( ! $items[ $index ]['invalid'] ) {
            $items[ $index ]['invalid'] = true;
            $log['errores']++;
        }
        seo_ie_add_log_detail( $log, sprintf( 'Fila %d: %s', $items[ $index ]['line'], $message ) );
    };

    foreach ( $items as $index => $item ) {
        foreach ( $item['pre_errors'] as $error ) {
            $mark_invalid( $index, $error );
        }
    }

    $source_id_rows   = [];
    $source_slug_rows = [];

    foreach ( $items as $index => $item ) {
        if ( 0 < $item['source_id'] ) {
            $source_id_rows[ $item['source_id'] ][] = $index;
        }
        if ( '' !== $item['source_slug'] ) {
            $source_slug_rows[ $item['source_slug'] ][] = $index;
        }
    }

    foreach ( $source_id_rows as $source_id => $indexes ) {
        if ( 1 < count( $indexes ) ) {
            foreach ( $indexes as $index ) {
                $mark_invalid( $index, sprintf( 'post_id %d aparece repetido en el CSV.', $source_id ) );
            }
        }
    }

    foreach ( $source_slug_rows as $source_slug => $indexes ) {
        if ( 1 < count( $indexes ) ) {
            foreach ( $indexes as $index ) {
                $mark_invalid( $index, sprintf( 'El slug «%s» aparece repetido en el CSV.', $source_slug ) );
            }
        }
    }

    $target_rows = [];
    foreach ( $items as $index => $item ) {
        if ( $item['invalid'] ) {
            continue;
        }

        $located = seo_ie_locate_existing_post( $item['source_id'], $item['source_slug'], $item['source_url'] );
        foreach ( $located['warnings'] as $warning ) {
            seo_ie_add_log_warning( $log, sprintf( 'Fila %d: %s', $item['line'], $warning ) );
        }
        if ( '' !== $located['error'] ) {
            $mark_invalid( $index, $located['error'] );
            continue;
        }

        $items[ $index ]['existing_id']     = absint( $located['post_id'] );
        $items[ $index ]['existing_method'] = $located['method'];
        if ( 0 < $located['post_id'] ) {
            $target_rows[ absint( $located['post_id'] ) ][] = $index;
        }
    }

    foreach ( $target_rows as $target_id => $indexes ) {
        if ( 1 < count( $indexes ) ) {
            foreach ( $indexes as $index ) {
                $mark_invalid( $index, sprintf( 'Varias filas apuntan a la misma entrada destino %d.', $target_id ) );
            }
        }
    }

    $valid_formats = [ 'aside', 'chat', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio' ];

    foreach ( $items as $index => $item ) {
        if ( $item['invalid'] ) {
            continue;
        }

        $row         = $item['row'];
        $existing_id = absint( $item['existing_id'] );
        $is_existing = 0 < $existing_id;

        if ( 'create_only' === $mode && $is_existing ) {
            $log['omitidos']++;
            seo_ie_add_log_detail( $log, sprintf( 'Fila %d: omitida; la entrada %d ya existe (%s).', $item['line'], $existing_id, $item['existing_method'] ) );
            continue;
        }

        if ( 'update_only' === $mode && ! $is_existing ) {
            $log['omitidos']++;
            seo_ie_add_log_detail( $log, sprintf( 'Fila %d: omitida; no existe una entrada que actualizar.', $item['line'] ) );
            continue;
        }

        $creating = ! $is_existing;
        $post_id  = $is_existing ? $existing_id : 0;
        $title    = sanitize_text_field( $row['titulo'] ?? '' );

        if ( $creating && '' === $title ) {
            $mark_invalid( $index, 'No se puede crear una entrada sin título.' );
            continue;
        }

        $status = sanitize_key( $row['estado'] ?? '' );
        if ( '' !== $status && ! in_array( $status, seo_ie_page_allowed_statuses(), true ) ) {
            seo_ie_add_log_warning( $log, sprintf( 'Fila %d: estado «%s» no válido; se conservará el actual o se usará draft.', $item['line'], $status ) );
            $status = '';
        }

        if ( $creating && 'future' === $status && ( ! array_key_exists( 'fecha', $row ) || ! seo_ie_is_valid_page_datetime( $row['fecha'] ) ) ) {
            seo_ie_add_log_warning( $log, sprintf( 'Fila %d: una entrada future necesita fecha válida; se creará como draft.', $item['line'] ) );
            $status = 'draft';
        }

        $author_id = $import_author_date ? seo_ie_resolve_page_author( $row ) : 0;
        if ( $import_author_date && ( 0 < absint( $row['autor_id'] ?? 0 ) || '' !== trim( (string) ( $row['autor_login'] ?? '' ) ) ) && 0 === $author_id ) {
            seo_ie_add_log_warning( $log, sprintf( 'Fila %d: no se encontró el autor indicado.', $item['line'] ) );
        }

        $product_relation = [
            'defined'         => false,
            'valid'           => true,
            'term_ids'        => [],
            'requested_count' => 0,
            'unresolved'      => [],
        ];

        if ( $import_relations ) {
            $product_relation = seo_ie_resolve_product_cat_relation_for_import(
                $row,
                $item['line'],
                $log
            );
        }

        if ( $dry_run ) {
            if ( $import_image ) {
                $preview_image_id  = absint( $row['imagen_destacada_id'] ?? 0 );
                $preview_image_url = esc_url_raw( trim( (string) ( $row['imagen_destacada'] ?? '' ) ) );
                if ( 0 < $preview_image_id && 'attachment' !== get_post_type( $preview_image_id ) && '' === $preview_image_url ) {
                    seo_ie_add_log_warning( $log, sprintf( 'Fila %d: el ID de imagen %d no corresponde a un adjunto.', $item['line'], $preview_image_id ) );
                }
                if ( '' !== $preview_image_url && ! wp_http_validate_url( $preview_image_url ) ) {
                    seo_ie_add_log_warning( $log, sprintf( 'Fila %d: la URL de imagen no es válida.', $item['line'] ) );
                }
            }

            if ( $creating ) {
                $log['creados']++;
            } else {
                $log['actualizados']++;
            }
            $log['correctos']++;
            continue;
        }

        $post_data = [ 'post_type' => 'post' ];
        if ( ! $creating ) {
            $post_data['ID'] = $post_id;
        }
        if ( $creating ) {
            $post_data['post_title']  = $title;
            $post_data['post_status'] = '' !== $status ? $status : 'draft';
        }

        if ( $import_core ) {
            if ( '' !== $title ) {
                $post_data['post_title'] = $title;
            }
            if ( array_key_exists( 'slug', $row ) && '' !== trim( (string) $row['slug'] ) ) {
                $post_data['post_name'] = sanitize_title( $row['slug'] );
            }
            if ( '' !== $status ) {
                $post_data['post_status'] = $status;
            }
            if ( array_key_exists( 'excerpt', $row ) ) {
                $post_data['post_excerpt'] = seo_ie_csv_to_utf8( (string) $row['excerpt'] );
            }
            if ( array_key_exists( 'description', $row ) ) {
                $post_data['post_content'] = seo_ie_csv_to_utf8( (string) $row['description'] );
            }
        }

        if ( $import_taxonomies ) {
            if ( array_key_exists( 'comentarios', $row ) ) {
                $comment_status = sanitize_key( $row['comentarios'] );
                if ( in_array( $comment_status, [ 'open', 'closed' ], true ) ) {
                    $post_data['comment_status'] = $comment_status;
                }
            }
            if ( array_key_exists( 'pings', $row ) ) {
                $ping_status = sanitize_key( $row['pings'] );
                if ( in_array( $ping_status, [ 'open', 'closed' ], true ) ) {
                    $post_data['ping_status'] = $ping_status;
                }
            }
        }

        if ( $import_author_date ) {
            if ( 0 < $author_id ) {
                $post_data['post_author'] = $author_id;
            }
            if ( array_key_exists( 'fecha', $row ) && seo_ie_is_valid_page_datetime( $row['fecha'] ) ) {
                $post_data['post_date'] = trim( (string) $row['fecha'] );
                $post_data['edit_date'] = true;
            } elseif ( array_key_exists( 'fecha', $row ) && '' !== trim( (string) $row['fecha'] ) ) {
                seo_ie_add_log_warning( $log, sprintf( 'Fila %d: fecha local no válida.', $item['line'] ) );
            }
            if ( array_key_exists( 'fecha_gmt', $row ) && seo_ie_is_valid_page_datetime( $row['fecha_gmt'] ) ) {
                $post_data['post_date_gmt'] = trim( (string) $row['fecha_gmt'] );
            } elseif ( array_key_exists( 'fecha_gmt', $row ) && '' !== trim( (string) $row['fecha_gmt'] ) ) {
                seo_ie_add_log_warning( $log, sprintf( 'Fila %d: fecha GMT no válida.', $item['line'] ) );
            }
        }

        $needs_post_write = $creating || 2 < count( $post_data );
        $saved_id = $needs_post_write
            ? ( $creating ? wp_insert_post( wp_slash( $post_data ), true ) : wp_update_post( wp_slash( $post_data ), true ) )
            : $post_id;

        if ( is_wp_error( $saved_id ) || 0 === absint( $saved_id ) ) {
            $log['errores']++;
            seo_ie_add_log_detail(
                $log,
                sprintf(
                    'Fila %d: no se pudo %s la entrada: %s',
                    $item['line'],
                    $creating ? 'crear' : 'actualizar',
                    is_wp_error( $saved_id ) ? $saved_id->get_error_message() : 'WordPress devolvió un ID vacío.'
                )
            );
            continue;
        }

        $post_id = absint( $saved_id );

        if ( $import_taxonomies ) {
            $category_ids = seo_ie_resolve_post_terms( 'category', $row, $item['line'], $log );
            if ( null !== $category_ids ) {
                $term_result = wp_set_post_terms( $post_id, $category_ids, 'category', false );
                if ( is_wp_error( $term_result ) ) {
                    $log['errores']++;
                    seo_ie_add_log_detail( $log, sprintf( 'Fila %d, entrada %d: no se pudieron guardar las categorías: %s', $item['line'], $post_id, $term_result->get_error_message() ) );
                }
            }

            $tag_ids = seo_ie_resolve_post_terms( 'post_tag', $row, $item['line'], $log );
            if ( null !== $tag_ids ) {
                $term_result = wp_set_post_terms( $post_id, $tag_ids, 'post_tag', false );
                if ( is_wp_error( $term_result ) ) {
                    $log['errores']++;
                    seo_ie_add_log_detail( $log, sprintf( 'Fila %d, entrada %d: no se pudieron guardar las etiquetas: %s', $item['line'], $post_id, $term_result->get_error_message() ) );
                }
            }

            if ( array_key_exists( 'formato', $row ) ) {
                $format = sanitize_key( $row['formato'] );
                if ( '' === $format || 'standard' === $format ) {
                    set_post_format( $post_id, false );
                } elseif ( in_array( $format, $valid_formats, true ) ) {
                    set_post_format( $post_id, $format );
                } else {
                    seo_ie_add_log_warning( $log, sprintf( 'Fila %d: formato de entrada «%s» no válido; se conserva el actual.', $item['line'], $format ) );
                }
            }

            if ( array_key_exists( 'sticky', $row ) ) {
                $sticky = seo_ie_post_bool_value( $row['sticky'] );
                if ( null === $sticky ) {
                    seo_ie_add_log_warning( $log, sprintf( 'Fila %d: el valor sticky no es reconocible.', $item['line'] ) );
                } elseif ( $sticky ) {
                    stick_post( $post_id );
                } else {
                    unstick_post( $post_id );
                }
            }
        }

        if ( $import_relations && ! empty( $product_relation['defined'] ) ) {
            seo_ie_apply_product_cat_relation_import(
                'post',
                $post_id,
                $product_relation,
                $item['line'],
                $log
            );
        }

        if ( $import_seo_meta && array_key_exists( 'meta_seo', $row ) ) {
            seo_ie_apply_page_meta_payload( $post_id, $item['seo_meta_payload'], $log, $item['line'], 'el metadato SEO' );
        }
        if ( $import_custom_meta && array_key_exists( 'meta_personalizados', $row ) ) {
            seo_ie_apply_page_meta_payload( $post_id, $item['custom_meta_payload'], $log, $item['line'], 'el metadato personalizado' );
        }
        if ( $import_image ) {
            seo_ie_import_post_thumbnail( $post_id, $row, $item['line'], $log );
        }

        clean_post_cache( $post_id );
        if ( $creating ) {
            $log['creados']++;
        } else {
            $log['actualizados']++;
        }
        $log['correctos']++;
    }

    if ( 0 < absint( $log['relaciones_no_resueltas'] ?? 0 ) ) {
        seo_ie_add_log_detail(
            $log,
            sprintf(
                'Relaciones comerciales no resueltas: %d. Revisa después el informe de páginas/posts sin product_cat.',
                absint( $log['relaciones_no_resueltas'] )
            )
        );
    }

    if ( $dry_run ) {
        seo_ie_add_log_detail( $log, 'Simulación completada: no se ha escrito ningún dato en WordPress.' );
    } else {
        seo_ie_add_log_detail( $log, 'La importación no elimina entradas ni revisiones existentes.' );
    }

    seo_ie_store_log( $log );

    if ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'post' ) ) {
        return $log;
    }

    wp_safe_redirect(
        add_query_arg(
            [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'wordpress', 'seo_ie_imported' => 'posts' ],
            admin_url( 'admin.php' )
        )
    );
    exit;
}


/**
 * Exporta todas las FAQs de SEO System a CSV.
 *
 * @since 2.0.0
 *
 * @return void
 */
function seo_export_faqs_csv() {

    if ( ! isset( $_POST['seo_export_faqs'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para exportar FAQs.', 'seo-system' ) );
    }

    check_admin_referer(
        'seo_export_faqs_csv',
        'seo_export_faqs_nonce'
    );

    global $wpdb;

    $table = $wpdb->prefix . 'seo_faq';
    $faqs  = $wpdb->get_results(
        "
        SELECT
            id,
            object_type,
            object_id,
            ambito,
            question,
            answer,
            sort_order,
            active,
            load_count,
            open_count,
            created_at,
            updated_at
        FROM {$table}
        ORDER BY object_type ASC, object_id ASC, sort_order ASC, id ASC
        "
    );

    $filename = 'seo_faqs_' . wp_date( 'Ymd_His' ) . '.csv';

    seo_ie_store_log(
        [
            'operacion'  => 'Exportación de FAQs',
            'archivo'    => $filename,
            'procesados' => count( $faqs ),
            'correctos'  => count( $faqs ),
            'errores'    => 0,
            'detalles'   => [
                'Se exportaron todas las FAQs y sus métricas.',
            ],
        ]
    );

    $output = seo_ie_open_csv_download( $filename );

    seo_ie_write_csv_row(
        $output,
        [
            'faq_id',
            'object_type',
            'object_id',
            'ambito',
            'question',
            'answer',
            'sort_order',
            'active',
            'load_count',
            'open_count',
            'created_at',
            'updated_at',
        ]
    );

    foreach ( $faqs as $faq ) {
        seo_ie_write_csv_row(
            $output,
            [
                absint( $faq->id ),
                absint( $faq->object_type ),
                absint( $faq->object_id ),
                (string) $faq->ambito,
                (string) $faq->question,
                (string) $faq->answer,
                absint( $faq->sort_order ),
                absint( $faq->active ),
                absint( $faq->load_count ),
                absint( $faq->open_count ),
                (string) $faq->created_at,
                (string) $faq->updated_at,
            ]
        );
    }

    fclose( $output );
    exit;
}

/**
 * Comprueba si un valor tiene formato de fecha MySQL.
 *
 * @since 2.0.0
 *
 * @param string $value Fecha recibida.
 * @return bool
 */
function seo_ie_is_mysql_datetime( $value ) {

    return 1 === preg_match(
        '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
        trim( (string) $value )
    );
}

/**
 * Importa FAQs desde el CSV generado por SEO System.
 *
 * Si faq_id existe, actualiza esa fila. Si está vacío o no existe,
 * crea una FAQ nueva utilizando el AUTO_INCREMENT de la tabla.
 *
 * @since 2.0.0
 *
 * @return void
 */
function seo_import_faqs_csv() {

    if ( ! isset( $_POST['seo_import_faqs'] ) ) {
        return;
    }

    if ( function_exists( 'seo_ie_batch_guard_manual_import' ) ) {
        seo_ie_batch_guard_manual_import( 'faq' );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para importar FAQs.', 'seo-system' ) );
    }

    check_admin_referer(
        'seo_import_faqs_csv',
        'seo_import_faqs_nonce'
    );

    if (
        empty( $_FILES['faqs_csv']['tmp_name'] )
        || (
            ! ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'faq' ) )
            && ! is_uploaded_file( $_FILES['faqs_csv']['tmp_name'] )
        )
    ) {
        wp_die( esc_html__( 'No se ha recibido un CSV de FAQs válido.', 'seo-system' ) );
    }

    $handle = fopen( $_FILES['faqs_csv']['tmp_name'], 'r' );

    if ( false === $handle ) {
        wp_die( esc_html__( 'No se pudo abrir el CSV de FAQs.', 'seo-system' ) );
    }

    $header = seo_ie_read_csv_row( $handle );

    if ( false === $header ) {
        fclose( $handle );
        wp_die( esc_html__( 'El CSV de FAQs está vacío.', 'seo-system' ) );
    }

    $header = seo_ie_normalize_csv_header( $header, 'faq' );

    $required_columns = [
        'object_type',
        'object_id',
        'question',
        'answer',
    ];

    foreach ( $required_columns as $required_column ) {
        if ( ! in_array( $required_column, $header, true ) ) {
            fclose( $handle );
            wp_die(
                sprintf(
                    esc_html__( 'Falta la columna obligatoria %s.', 'seo-system' ),
                    esc_html( $required_column )
                )
            );
        }
    }

    global $wpdb;

    $table = $wpdb->prefix . 'seo_faq';

    $log = [
        'operacion'  => 'Importación de FAQs',
        'archivo'    => sanitize_file_name( $_FILES['faqs_csv']['name'] ),
        'procesados' => 0,
        'correctos'  => 0,
        'creados'    => 0,
        'actualizados' => 0,
        'errores'    => 0,
        'detalles'   => [],
    ];

    $line = 1;

    while ( false !== ( $csv_row = seo_ie_read_csv_row( $handle ) ) ) {

        $line++;

        if ( empty( array_filter( $csv_row, static fn( $value ) => '' !== trim( (string) $value ) ) ) ) {
            continue;
        }

        $log['procesados']++;

        $row         = seo_ie_build_csv_row( $header, $csv_row );
        $faq_id      = absint( $row['faq_id'] ?? 0 );
        $object_type = absint( $row['object_type'] ?? 0 );
        $object_id   = absint( $row['object_id'] ?? 0 );
        $question    = sanitize_text_field( trim( (string) ( $row['question'] ?? '' ) ) );
        $answer      = wp_kses_post(
            html_entity_decode(
                (string) ( $row['answer'] ?? '' ),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        if ( ! in_array( $object_type, [ 1, 2, 3 ], true ) ) {
            $log['errores']++;
            seo_ie_add_log_detail(
                $log,
                sprintf( 'Fila %d: object_type debe ser 1, 2 o 3.', $line )
            );
            continue;
        }

        if ( 0 >= $object_id ) {
            $log['errores']++;
            seo_ie_add_log_detail(
                $log,
                sprintf( 'Fila %d: object_id no válido.', $line )
            );
            continue;
        }

        if ( '' === $question || '' === trim( wp_strip_all_tags( $answer ) ) ) {
            $log['errores']++;
            seo_ie_add_log_detail(
                $log,
                sprintf( 'Fila %d: la pregunta y la respuesta son obligatorias.', $line )
            );
            continue;
        }

        $data = [
            'object_type' => $object_type,
            'object_id'   => $object_id,
            'ambito'      => array_key_exists( 'ambito', $row )
                ? sanitize_text_field( trim( (string) $row['ambito'] ) )
                : null,
            'question'    => $question,
            'answer'      => $answer,
            'sort_order'  => absint( $row['sort_order'] ?? 0 ),
            'active'      => empty( $row['active'] ) ? 0 : 1,
            'load_count'  => absint( $row['load_count'] ?? 0 ),
            'open_count'  => absint( $row['open_count'] ?? 0 ),
            'updated_at'  => current_time( 'mysql' ),
        ];

        $formats = [
            '%d',
            '%d',
            '%s',
            '%s',
            '%s',
            '%d',
            '%d',
            '%d',
            '%d',
            '%s',
        ];

        if (
            array_key_exists( 'updated_at', $row )
            && seo_ie_is_mysql_datetime( $row['updated_at'] )
        ) {
            $data['updated_at'] = trim( $row['updated_at'] );
        }

        $exists = $faq_id
            ? absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table} WHERE id = %d LIMIT 1",
                        $faq_id
                    )
                )
            )
            : 0;

        if ( $exists ) {

            $updated = $wpdb->update(
                $table,
                $data,
                [ 'id' => $faq_id ],
                $formats,
                [ '%d' ]
            );

        if ( false === $updated ) {
        
            $log['errores']++;
        
            seo_ie_add_log_detail(
                $log,
                sprintf(
                    'Fila %d, FAQ %d: error al actualizar en %s: %s',
                    $line,
                    $faq_id,
                    $table,
                    $wpdb->last_error ? $wpdb->last_error : 'Error SQL desconocido'
                )
            );
        
            continue;
        }

            $log['actualizados']++;
            $log['correctos']++;
            continue;
        }

        $data['created_at'] = (
            array_key_exists( 'created_at', $row )
            && seo_ie_is_mysql_datetime( $row['created_at'] )
        )
            ? trim( $row['created_at'] )
            : current_time( 'mysql' );

        $formats[] = '%s';

        $inserted = $wpdb->insert(
            $table,
            $data,
            $formats
        );

        if ( false === $inserted ) {
        
            $log['errores']++;
        
            seo_ie_add_log_detail(
                $log,
                sprintf(
                    'Fila %d: error al crear la FAQ en %s: %s',
                    $line,
                    $table,
                    $wpdb->last_error ? $wpdb->last_error : 'Error SQL desconocido'
                )
            );
        
            continue;
        }

        $log['creados']++;
        $log['correctos']++;
    }

    fclose( $handle );

    seo_ie_store_log( $log );

    if ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'faq' ) ) {
        return $log;
    }

    wp_safe_redirect(
        add_query_arg(
            'seo_ie_imported',
            'faqs',
            admin_url( 'admin.php?page=seo-import-export' )
        )
    );
    exit;
}


/**
 * Devuelve el nombre de la tabla de redirects.
 *
 * @return string
 */
function seo_ie_redirects_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'seo_redirects';
}

/**
 * Comprueba que la tabla de redirects exista.
 *
 * @return bool
 */
function seo_ie_redirects_table_exists() {
    global $wpdb;
    $table = seo_ie_redirects_table_name();
    $found = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $wpdb->esc_like( $table )
        )
    );

    return $table === (string) $found;
}

/**
 * Valida una URL absoluta HTTP(S) o una ruta relativa al dominio.
 *
 * No modifica barras finales ni query strings para que origin_url conserve
 * exactamente la clave unica utilizada por wp_seo_redirects.
 *
 * @param mixed  $value Valor CSV.
 * @param string $field Nombre legible.
 * @param bool   $origin Indica si es el origen.
 * @return string|WP_Error
 */
function seo_ie_redirect_clean_location( $value, $field, $origin = false ) {
    $value = trim( seo_ie_csv_to_utf8( (string) $value ) );
    $value = wp_strip_all_tags( $value );

    if ( '' === $value ) {
        return new WP_Error(
            'seo_redirect_empty_url',
            sprintf( '%s no puede estar vacio.', $field )
        );
    }

    if ( 4096 < strlen( $value ) ) {
        return new WP_Error(
            'seo_redirect_long_url',
            sprintf( '%s supera el limite de 4096 caracteres.', $field )
        );
    }

    if ( preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
        return new WP_Error(
            'seo_redirect_control_character',
            sprintf( '%s contiene caracteres de control.', $field )
        );
    }

    if ( $origin && false !== strpos( $value, '#' ) ) {
        return new WP_Error(
            'seo_redirect_origin_fragment',
            'origin_url no puede contener fragmentos (#), porque el navegador no los envia al servidor.'
        );
    }

    $is_relative = 0 === strpos( $value, '/' ) && 0 !== strpos( $value, '//' );
    $is_absolute = (bool) wp_http_validate_url( $value );

    if ( ! $is_relative && ! $is_absolute ) {
        return new WP_Error(
            'seo_redirect_invalid_url',
            sprintf( '%s debe ser una ruta que empiece por / o una URL HTTP(S) valida.', $field )
        );
    }

    return $value;
}

/**
 * Exporta wp_seo_redirects a CSV.
 *
 * @return void
 */
function seo_export_redirects_csv() {
    if ( ! isset( $_POST['seo_export_redirects'] ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para exportar redirects.', 'seo-system' ) );
    }

    check_admin_referer( 'seo_export_redirects_csv', 'seo_export_redirects_nonce' );

    if ( ! seo_ie_redirects_table_exists() ) {
        wp_die( esc_html__( 'No existe la tabla wp_seo_redirects.', 'seo-system' ) );
    }

    global $wpdb;
    $table = seo_ie_redirects_table_name();
    $rows  = $wpdb->get_results(
        "SELECT id, origin_url, target_url, status_code, hits, last_hit
         FROM {$table}
         ORDER BY id ASC"
    );

    $filename = 'seo_redirects_' . wp_date( 'Ymd_His' ) . '.csv';
    seo_ie_store_log(
        [
            'operacion'  => 'Exportacion de redirects',
            'archivo'    => $filename,
            'procesados' => count( $rows ),
            'correctos'  => count( $rows ),
            'errores'    => 0,
            'detalles'   => [
                'Incluye origin_url, target_url, status_code, hits y last_hit.',
            ],
        ]
    );

    $output = seo_ie_open_csv_download( $filename );
    seo_ie_write_csv_row(
        $output,
        [ 'redirect_id', 'origin_url', 'target_url', 'status_code', 'hits', 'last_hit' ]
    );

    foreach ( $rows as $row ) {
        seo_ie_write_csv_row(
            $output,
            [
                absint( $row->id ),
                (string) $row->origin_url,
                (string) $row->target_url,
                absint( $row->status_code ),
                absint( $row->hits ),
                null === $row->last_hit ? '' : (string) $row->last_hit,
            ]
        );
    }

    fclose( $output );
    exit;
}

/**
 * Importa redirects. origin_url es la clave funcional: si ya existe se
 * actualiza; si no existe se crea y MySQL asigna el id AUTO_INCREMENT.
 * No elimina redirects ausentes del CSV.
 *
 * @return array|void
 */
function seo_import_redirects_csv() {
    if ( ! isset( $_POST['seo_import_redirects'] ) ) {
        return;
    }

    if ( function_exists( 'seo_ie_batch_guard_manual_import' ) ) {
        seo_ie_batch_guard_manual_import( 'redirect' );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para importar redirects.', 'seo-system' ) );
    }

    check_admin_referer( 'seo_import_redirects_csv', 'seo_import_redirects_nonce' );

    if (
        empty( $_FILES['redirects_csv']['tmp_name'] )
        || (
            ! ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'redirect' ) )
            && ! is_uploaded_file( $_FILES['redirects_csv']['tmp_name'] )
        )
    ) {
        wp_die( esc_html__( 'No se ha recibido un CSV de redirects valido.', 'seo-system' ) );
    }

    if ( ! seo_ie_redirects_table_exists() ) {
        wp_die( esc_html__( 'No existe la tabla wp_seo_redirects.', 'seo-system' ) );
    }

    $handle = fopen( $_FILES['redirects_csv']['tmp_name'], 'r' );
    if ( false === $handle ) {
        wp_die( esc_html__( 'No se pudo abrir el CSV de redirects.', 'seo-system' ) );
    }

    $header = seo_ie_read_csv_row( $handle );
    if ( false === $header ) {
        fclose( $handle );
        wp_die( esc_html__( 'El CSV de redirects esta vacio.', 'seo-system' ) );
    }

    $header = seo_ie_normalize_csv_header( $header, 'redirect' );
    $counts = array_count_values( array_filter( $header, static fn( $value ) => '' !== (string) $value ) );
    $duplicates = array_keys( array_filter( $counts, static fn( $count ) => 1 < $count ) );

    if ( ! empty( $duplicates ) ) {
        fclose( $handle );
        wp_die(
            sprintf(
                esc_html__( 'El CSV contiene cabeceras duplicadas: %s.', 'seo-system' ),
                esc_html( implode( ', ', $duplicates ) )
            )
        );
    }

    foreach ( [ 'origin_url', 'target_url' ] as $required ) {
        if ( ! in_array( $required, $header, true ) ) {
            fclose( $handle );
            wp_die(
                sprintf(
                    esc_html__( 'Falta la columna obligatoria %s.', 'seo-system' ),
                    esc_html( $required )
                )
            );
        }
    }

    global $wpdb;
    $table = seo_ie_redirects_table_name();
    $log   = [
        'operacion'    => 'Importacion de redirects',
        'archivo'      => sanitize_file_name( $_FILES['redirects_csv']['name'] ),
        'procesados'   => 0,
        'correctos'    => 0,
        'creados'      => 0,
        'actualizados' => 0,
        'omitidos'     => 0,
        'errores'      => 0,
        'detalles'     => [],
    ];
    $line = 1;
    $seen = [];

    while ( false !== ( $csv_row = seo_ie_read_csv_row( $handle ) ) ) {
        $line++;

        if ( empty( array_filter( $csv_row, static fn( $value ) => '' !== trim( (string) $value ) ) ) ) {
            continue;
        }

        $log['procesados']++;
        $row = seo_ie_build_csv_row( $header, $csv_row );

        $origin = seo_ie_redirect_clean_location( $row['origin_url'] ?? '', 'origin_url', true );
        $target = seo_ie_redirect_clean_location( $row['target_url'] ?? '', 'target_url', false );

        if ( is_wp_error( $origin ) || is_wp_error( $target ) ) {
            $log['errores']++;
            seo_ie_add_log_detail(
                $log,
                sprintf(
                    'Fila %d: %s',
                    $line,
                    is_wp_error( $origin ) ? $origin->get_error_message() : $target->get_error_message()
                )
            );
            continue;
        }

        if ( isset( $seen[ $origin ] ) ) {
            $log['errores']++;
            seo_ie_add_log_detail(
                $log,
                sprintf( 'Fila %d: origin_url duplicado dentro del CSV: %s.', $line, $origin )
            );
            continue;
        }
        $seen[ $origin ] = true;

        $status_code = array_key_exists( 'status_code', $row ) && '' !== trim( (string) $row['status_code'] )
            ? absint( $row['status_code'] )
            : 301;

        if ( ! in_array( $status_code, [ 301, 302, 303, 307, 308 ], true ) ) {
            $log['errores']++;
            seo_ie_add_log_detail(
                $log,
                sprintf( 'Fila %d: status_code %d no admitido.', $line, $status_code )
            );
            continue;
        }

        $data = [
            'origin_url'  => $origin,
            'target_url'  => $target,
            'status_code' => $status_code,
        ];
        $formats = [ '%s', '%s', '%d' ];

        if ( array_key_exists( 'hits', $row ) ) {
            $raw_hits = trim( (string) $row['hits'] );
            if ( '' !== $raw_hits && ! ctype_digit( $raw_hits ) ) {
                $log['errores']++;
                seo_ie_add_log_detail( $log, sprintf( 'Fila %d: hits debe ser un entero positivo o cero.', $line ) );
                continue;
            }
            $data['hits'] = '' === $raw_hits ? 0 : absint( $raw_hits );
            $formats[] = '%d';
        }

        if ( array_key_exists( 'last_hit', $row ) ) {
            $raw_last_hit = trim( (string) $row['last_hit'] );
            if ( '' !== $raw_last_hit && ! seo_ie_is_mysql_datetime( $raw_last_hit ) ) {
                $log['errores']++;
                seo_ie_add_log_detail( $log, sprintf( 'Fila %d: last_hit no es una fecha MySQL valida.', $line ) );
                continue;
            }
            $data['last_hit'] = '' === $raw_last_hit ? null : $raw_last_hit;
            $formats[] = '%s';
        }

        $existing_id = absint(
            $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE origin_url = %s LIMIT 1",
                    $origin
                )
            )
        );

        if ( 0 < $existing_id ) {
            $updated = $wpdb->update(
                $table,
                $data,
                [ 'id' => $existing_id ],
                $formats,
                [ '%d' ]
            );

            if ( false === $updated ) {
                $log['errores']++;
                seo_ie_add_log_detail(
                    $log,
                    sprintf( 'Fila %d: error actualizando redirect %d: %s', $line, $existing_id, $wpdb->last_error ?: 'Error SQL desconocido' )
                );
                continue;
            }

            $log['actualizados']++;
            $log['correctos']++;
            continue;
        }

        if ( ! array_key_exists( 'hits', $data ) ) {
            $data['hits'] = 0;
            $formats[] = '%d';
        }
        if ( ! array_key_exists( 'last_hit', $data ) ) {
            $data['last_hit'] = null;
            $formats[] = '%s';
        }

        $inserted = $wpdb->insert( $table, $data, $formats );
        if ( false === $inserted ) {
            $log['errores']++;
            seo_ie_add_log_detail(
                $log,
                sprintf( 'Fila %d: error creando redirect: %s', $line, $wpdb->last_error ?: 'Error SQL desconocido' )
            );
            continue;
        }

        $log['creados']++;
        $log['correctos']++;
    }

    fclose( $handle );
    seo_ie_store_log( $log );

    if ( function_exists( 'seo_ie_batch_is_internal' ) && seo_ie_batch_is_internal( 'redirect' ) ) {
        return $log;
    }

    wp_safe_redirect(
        add_query_arg(
            [
                'page'            => 'seo-import-export',
                'seo_ie_tab'      => 'wordpress',
                'seo_ie_imported' => 'redirects',
            ],
            admin_url( 'admin.php' )
        )
    );
    exit;
}



/**
 * Normaliza un array recibido desde POST para filtros de texto.
 *
 * @param mixed $value Valor recibido.
 * @return string[]
 */
function seo_ie_sanitize_text_array( $value ) {

    if ( ! is_array( $value ) ) {
        $value = '' !== (string) $value ? [ $value ] : [];
    }

    $items = [];

    foreach ( $value as $item ) {
        $item = sanitize_text_field( wp_unslash( (string) $item ) );

        if ( '' !== $item ) {
            $items[] = $item;
        }
    }

    return array_values( array_unique( $items ) );
}

/**
 * Normaliza un array recibido desde POST para IDs enteros.
 *
 * @param mixed $value Valor recibido.
 * @return int[]
 */
function seo_ie_sanitize_absint_array( $value ) {

    if ( ! is_array( $value ) ) {
        $value = '' !== (string) $value ? [ $value ] : [];
    }

    $items = [];

    foreach ( $value as $item ) {
        $item = absint( $item );

        if ( 0 < $item ) {
            $items[] = $item;
        }
    }

    return array_values( array_unique( $items ) );
}


/**
 * Renderiza los filtros jerárquicos del exportador de productos.
 *
 * Jerarquía:
 * Cluster → Hub primario → Hub secundario → Categoría.
 *
 * Los selectores dependientes se filtran en el navegador para evitar
 * recargar la página o iniciar accidentalmente una exportación.
 *
 * @return void
 */
function seo_ie_render_product_export_filters() {

    global $wpdb;

    $relations_table = $wpdb->prefix . 'seo_relations';

    /*
     * Valores seleccionados.
     *
     * Se leen desde POST porque el formulario de exportación utiliza
     * method="post".
     */
    $selected_cluster = isset( $_POST['export_cluster'] )
        ? absint( $_POST['export_cluster'] )
        : 0;

    $selected_primary = isset( $_POST['export_hub_primary'] )
        ? absint( $_POST['export_hub_primary'] )
        : 0;

    $selected_secondary = isset( $_POST['export_hub_secondary'] )
        ? absint( $_POST['export_hub_secondary'] )
        : 0;

    $selected_categories = isset( $_POST['export_categories'] )
        ? array_map( 'absint', (array) $_POST['export_categories'] )
        : [];

    $selected_statuses = isset( $_POST['export_statuses'] )
        ? array_map( 'sanitize_key', (array) $_POST['export_statuses'] )
        : [];

    /*
     * Relaciones completas.
     *
     * Se cargan todas una sola vez y posteriormente JavaScript muestra
     * únicamente las opciones relacionadas con la selección anterior.
     */
    $cluster_primary_relations = $wpdb->get_results(
        "
        SELECT DISTINCT source_id, target_id
        FROM {$relations_table}
        WHERE source_type = 'cluster'
          AND relation_type = 'cluster_to_primary'
          AND source_id > 0
          AND target_id > 0
        ORDER BY source_id, target_id
        "
    );

    $primary_secondary_relations = $wpdb->get_results(
        "
        SELECT DISTINCT source_id, target_id
        FROM {$relations_table}
        WHERE relation_type = 'hub_primary_to_hub_secondary'
          AND source_id > 0
          AND target_id > 0
        ORDER BY source_id, target_id
        "
    );

    $secondary_category_relations = $wpdb->get_results(
        "
        SELECT DISTINCT source_id, target_id
        FROM {$relations_table}
        WHERE relation_type = 'hub_secondary_to_category'
          AND source_id > 0
          AND target_id > 0
        ORDER BY source_id, target_id
        "
    );

    /*
     * IDs únicos.
     */
    $cluster_ids   = [];
    $primary_ids   = [];
    $secondary_ids = [];
    $category_ids  = [];

    foreach ( $cluster_primary_relations as $relation ) {
        $cluster_ids[] = absint( $relation->source_id );
        $primary_ids[] = absint( $relation->target_id );
    }

    foreach ( $primary_secondary_relations as $relation ) {
        $primary_ids[]   = absint( $relation->source_id );
        $secondary_ids[] = absint( $relation->target_id );
    }

    foreach ( $secondary_category_relations as $relation ) {
        $secondary_ids[] = absint( $relation->source_id );
        $category_ids[]  = absint( $relation->target_id );
    }

    $cluster_ids   = array_values( array_unique( array_filter( $cluster_ids ) ) );
    $primary_ids   = array_values( array_unique( array_filter( $primary_ids ) ) );
    $secondary_ids = array_values( array_unique( array_filter( $secondary_ids ) ) );
    $category_ids  = array_values( array_unique( array_filter( $category_ids ) ) );

    /*
     * Resolver títulos de posts.
     */
    $get_post_title = static function ( $post_id, $fallback ) {

        $post = get_post( $post_id );

        if ( $post instanceof WP_Post ) {
            return $post->post_title;
        }

        return sprintf(
            '%s #%d',
            $fallback,
            absint( $post_id )
        );
    };

    /*
     * Mapas padre → hijo para los atributos data-*.
     */
    $primary_to_cluster = [];

    foreach ( $cluster_primary_relations as $relation ) {
        $primary_to_cluster[ absint( $relation->target_id ) ] =
            absint( $relation->source_id );
    }

    $secondary_to_primary = [];

    foreach ( $primary_secondary_relations as $relation ) {
        $secondary_to_primary[ absint( $relation->target_id ) ] =
            absint( $relation->source_id );
    }

    $category_to_secondary = [];

    foreach ( $secondary_category_relations as $relation ) {

        $category_id  = absint( $relation->target_id );
        $secondary_id = absint( $relation->source_id );

        if ( ! isset( $category_to_secondary[ $category_id ] ) ) {
            $category_to_secondary[ $category_id ] = [];
        }

        $category_to_secondary[ $category_id ][] = $secondary_id;
    }

    /*
     * Categorías WooCommerce.
     */
    $categories = [];

    if ( ! empty( $category_ids ) ) {
        $categories = get_terms(
            [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'include'    => $category_ids,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]
        );

        if ( is_wp_error( $categories ) ) {
            $categories = [];
        }
    }

    /*
     * Estados disponibles.
     */
    $statuses = [
        'publish' => __( 'Publicado', 'seo-system' ),
        'draft'   => __( 'Borrador', 'seo-system' ),
        'pending' => __( 'Pendiente de revisión', 'seo-system' ),
        'private' => __( 'Privado', 'seo-system' ),
    ];

    $filter_id = 'seo-product-export-filters';
    ?>

    <div
        id="<?php echo esc_attr( $filter_id ); ?>"
        style="margin:18px 0;padding:16px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:6px;"
    >
        <h3 style="margin-top:0;">
            <?php esc_html_e( 'Filtros de exportación', 'seo-system' ); ?>
        </h3>

        <p style="margin-top:0;color:#646970;">
            <?php
            esc_html_e(
                'Puedes combinar cluster, hubs, categorías y estados. Si no seleccionas un filtro, se exportarán todos sus valores.',
                'seo-system'
            );
            ?>
        </p>

        <div
            style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px;"
        >
            <div>
                <label for="seo-export-cluster">
                    <strong><?php esc_html_e( 'Cluster', 'seo-system' ); ?></strong>
                </label>

                <select
                    id="seo-export-cluster"
                    name="export_cluster"
                    style="width:100%;margin-top:5px;"
                >
                    <option value="0">
                        <?php esc_html_e( 'Todos los clusters', 'seo-system' ); ?>
                    </option>

                    <?php foreach ( $cluster_ids as $cluster_id ) : ?>
                        <option
                            value="<?php echo esc_attr( $cluster_id ); ?>"
                            <?php selected( $selected_cluster, $cluster_id ); ?>
                        >
                            <?php
                            echo esc_html(
                                $get_post_title( $cluster_id, 'Cluster' )
                            );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="seo-export-primary">
                    <strong><?php esc_html_e( 'Hub primario', 'seo-system' ); ?></strong>
                </label>

                <select
                    id="seo-export-primary"
                    name="export_hub_primary"
                    style="width:100%;margin-top:5px;"
                >
                    <option value="0">
                        <?php esc_html_e( 'Todos los hubs primarios', 'seo-system' ); ?>
                    </option>

                    <?php foreach ( $primary_ids as $primary_id ) : ?>
                        <option
                            value="<?php echo esc_attr( $primary_id ); ?>"
                            data-cluster="<?php
                                echo esc_attr(
                                    $primary_to_cluster[ $primary_id ] ?? 0
                                );
                            ?>"
                            <?php selected( $selected_primary, $primary_id ); ?>
                        >
                            <?php
                            echo esc_html(
                                $get_post_title( $primary_id, 'Hub primario' )
                            );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="seo-export-secondary">
                    <strong><?php esc_html_e( 'Hub secundario', 'seo-system' ); ?></strong>
                </label>

                <select
                    id="seo-export-secondary"
                    name="export_hub_secondary"
                    style="width:100%;margin-top:5px;"
                >
                    <option value="0">
                        <?php esc_html_e( 'Todos los hubs secundarios', 'seo-system' ); ?>
                    </option>

                    <?php foreach ( $secondary_ids as $secondary_id ) : ?>
                        <option
                            value="<?php echo esc_attr( $secondary_id ); ?>"
                            data-primary="<?php
                                echo esc_attr(
                                    $secondary_to_primary[ $secondary_id ] ?? 0
                                );
                            ?>"
                            <?php selected( $selected_secondary, $secondary_id ); ?>
                        >
                            <?php
                            echo esc_html(
                                $get_post_title( $secondary_id, 'Hub secundario' )
                            );
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="seo-export-categories">
                    <strong><?php esc_html_e( 'Categorías', 'seo-system' ); ?></strong>
                </label>

                <select
                    id="seo-export-categories"
                    name="export_categories[]"
                    multiple
                    size="7"
                    style="width:100%;margin-top:5px;"
                >
                    <?php foreach ( $categories as $category ) : ?>
                        <?php
                        $secondary_values = $category_to_secondary[
                            $category->term_id
                        ] ?? [];
                        ?>
                        <option
                            value="<?php echo esc_attr( $category->term_id ); ?>"
                            data-secondaries="<?php
                                echo esc_attr(
                                    implode(
                                        ',',
                                        array_map(
                                            'absint',
                                            $secondary_values
                                        )
                                    )
                                );
                            ?>"
                            <?php
                            selected(
                                in_array(
                                    absint( $category->term_id ),
                                    $selected_categories,
                                    true
                                )
                            );
                            ?>
                        >
                            <?php echo esc_html( $category->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <small style="display:block;margin-top:5px;color:#646970;">
                    <?php
                    esc_html_e(
                        'Mantén Ctrl o Cmd para seleccionar varias.',
                        'seo-system'
                    );
                    ?>
                </small>
            </div>
        </div>

        <div style="margin-top:16px;">
            <strong><?php esc_html_e( 'Estado', 'seo-system' ); ?></strong>

            <div
                style="display:flex;flex-wrap:wrap;gap:14px;margin-top:7px;"
            >
                <?php foreach ( $statuses as $status => $label ) : ?>
                    <label>
                        <input
                            type="checkbox"
                            name="export_statuses[]"
                            value="<?php echo esc_attr( $status ); ?>"
                            <?php
                            checked(
                                in_array(
                                    $status,
                                    $selected_statuses,
                                    true
                                )
                            );
                            ?>
                        >
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const wrapper = document.getElementById(
            <?php echo wp_json_encode( $filter_id ); ?>
        );

        if (!wrapper) {
            return;
        }

        const clusterSelect = wrapper.querySelector(
            '#seo-export-cluster'
        );

        const primarySelect = wrapper.querySelector(
            '#seo-export-primary'
        );

        const secondarySelect = wrapper.querySelector(
            '#seo-export-secondary'
        );

        const categorySelect = wrapper.querySelector(
            '#seo-export-categories'
        );

        function optionIsVisible(option) {
            return option.style.display !== 'none';
        }

        function resetHiddenSelection(select) {
            const selectedOption = select.options[
                select.selectedIndex
            ];

            if (
                selectedOption &&
                !optionIsVisible(selectedOption)
            ) {
                select.value = '0';
            }
        }

        function filterPrimaryHubs() {
            const clusterId = clusterSelect.value;

            Array.from(primarySelect.options).forEach(function (option) {
                if (option.value === '0') {
                    option.style.display = '';
                    return;
                }

                const belongsToCluster =
                    clusterId === '0' ||
                    option.dataset.cluster === clusterId;

                option.style.display = belongsToCluster ? '' : 'none';
            });

            resetHiddenSelection(primarySelect);
        }

        function filterSecondaryHubs() {
            const primaryId = primarySelect.value;

            Array.from(secondarySelect.options).forEach(function (option) {
                if (option.value === '0') {
                    option.style.display = '';
                    return;
                }

                const belongsToPrimary =
                    primaryId === '0' ||
                    option.dataset.primary === primaryId;

                option.style.display = belongsToPrimary ? '' : 'none';
            });

            resetHiddenSelection(secondarySelect);
        }

        function filterCategories() {
            const secondaryId = secondarySelect.value;

            Array.from(categorySelect.options).forEach(function (option) {
                const secondaries = (
                    option.dataset.secondaries || ''
                )
                    .split(',')
                    .filter(Boolean);

                const belongsToSecondary =
                    secondaryId === '0' ||
                    secondaries.includes(secondaryId);

                option.style.display =
                    belongsToSecondary ? '' : 'none';

                if (!belongsToSecondary) {
                    option.selected = false;
                }
            });
        }

        clusterSelect.addEventListener('change', function () {
            primarySelect.value = '0';
            secondarySelect.value = '0';

            filterPrimaryHubs();
            filterSecondaryHubs();
            filterCategories();
        });

        primarySelect.addEventListener('change', function () {
            secondarySelect.value = '0';

            filterSecondaryHubs();
            filterCategories();
        });

        secondarySelect.addEventListener(
            'change',
            filterCategories
        );

        filterPrimaryHubs();
        filterSecondaryHubs();
        filterCategories();
    });
    </script>

    <?php
}



/**
 * Página principal del menú seo-import-export.
 *
 * Mantiene en una única pantalla las operaciones de categorías, productos,
 * páginas y FAQs, además de los flujos del catálogo de proveedores.
 *
 * @since 2.0.0
 *
 * @return void
 */
function seo_import_export_page() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'seo-system' ) );
    }

    $allowed_tabs = [ 'wordpress', 'import-batch', 'importar-proveedor', 'importar-amazon', 'conexiones-proveedores', 'catalogo-proveedores', 'sincronizacion-proveedores' ];
    $tab = sanitize_key( $_GET['seo_ie_tab'] ?? 'wordpress' );
    if ( ! in_array( $tab, $allowed_tabs, true ) ) {
        $tab = 'wordpress';
    }
    $last_log = seo_ie_get_last_log();
    $base = add_query_arg( [ 'page' => 'seo-import-export' ], admin_url( 'admin.php' ) );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'Importar / Exportar SEO System', 'seo-system' ); ?></h1>
        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <a href="<?php echo esc_url( add_query_arg( 'seo_ie_tab', 'wordpress', $base ) ); ?>" class="nav-tab <?php echo 'wordpress' === $tab ? 'nav-tab-active' : ''; ?>">Importacion individual</a>
            <a href="<?php echo esc_url( add_query_arg( 'seo_ie_tab', 'import-batch', $base ) ); ?>" class="nav-tab <?php echo 'import-batch' === $tab ? 'nav-tab-active' : ''; ?>">Importacion por lotes</a>
            <a href="<?php echo esc_url( add_query_arg( 'seo_ie_tab', 'importar-proveedor', $base ) ); ?>" class="nav-tab <?php echo 'importar-proveedor' === $tab ? 'nav-tab-active' : ''; ?>">Importar proveedor</a>
            <a href="<?php echo esc_url( add_query_arg( 'seo_ie_tab', 'importar-amazon', $base ) ); ?>" class="nav-tab <?php echo 'importar-amazon' === $tab ? 'nav-tab-active' : ''; ?>">Importar Amazon</a>
            <a href="<?php echo esc_url( add_query_arg( 'seo_ie_tab', 'conexiones-proveedores', $base ) ); ?>" class="nav-tab <?php echo 'conexiones-proveedores' === $tab ? 'nav-tab-active' : ''; ?>">Conexiones con proveedores</a>
            <a href="<?php echo esc_url( add_query_arg( 'seo_ie_tab', 'catalogo-proveedores', $base ) ); ?>" class="nav-tab <?php echo 'catalogo-proveedores' === $tab ? 'nav-tab-active' : ''; ?>">Catálogo de proveedores</a>
            <a href="<?php echo esc_url( add_query_arg( 'seo_ie_tab', 'sincronizacion-proveedores', $base ) ); ?>" class="nav-tab <?php echo 'sincronizacion-proveedores' === $tab ? 'nav-tab-active' : ''; ?>">Sincronización V2</a>
        </nav>

        <?php if ( 'wordpress' === $tab ) : ?>
            <p><?php echo esc_html__( 'Los CSV se generan en UTF-8 y usan punto y coma como separador.', 'seo-system' ); ?></p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;max-width:1200px;">

            <!-- Pantalla de exportación de productos -->
            <div class="card" style="max-width:none;padding:20px;">
            
                <h2>Exportar productos</h2>
            
                <p>
                    Exporta un catálogo V2 completo: contenido, SKU, precios, stock, marca, proveedor, taxonomías, atributos e imágenes.
                    La importación manual de productos está deshabilitada; las altas y actualizaciones base se realizan desde <strong>Importación por lotes</strong>.
                </p>
            
                <form method="post">
            
                    <?php wp_nonce_field(
                        'seo_export_products_csv',
                        'seo_export_products_nonce'
                    ); ?>

                    <?php wp_nonce_field(
                        'seo_export_required_catalogs_csv',
                        'seo_export_required_catalogs_nonce'
                    ); ?>

                    <div style="margin:16px 0;padding:14px 16px;border:1px solid #c3a57a;border-radius:4px;background:#fffaf3;">
                        <strong>Valores obligatorios para altas y actualizaciones</strong>
                        <p style="margin:6px 0 12px;">
                            Las etiquetas semánticas y los atributos enviados por un sistema externo deben usar valores ya existentes y activos.
                            Este CSV único reúne <code>seo_vocabulary</code>, <code>seo_type_role_map</code>, <code>sql_atributos</code>,
                            <code>sql_atributos_terminos</code>, <code>sql_atributos_aliases</code> y las <code>product_tag</code> de WooCommerce. Si un valor no existe, debe darse de alta previamente; no debe inventarse durante el alta del producto.
                        </p>
                        <button
                            type="submit"
                            name="seo_export_required_catalogs"
                            value="1"
                            class="button button-secondary"
                        >
                            Descargar catálogos obligatorios CSV
                        </button>
                    </div>
            
                    <?php seo_ie_render_product_export_filters(); ?>

                    <p>
                        <button
                            type="submit"
                            name="seo_export_products"
                            value="1"
                            class="button button-primary"
                        >
                            Exportar productos
                        </button>
                    </p>
            
                </form>
            
            </div>

                <?php if ( function_exists( 'seo_ie_render_required_catalogs_import_card' ) ) { seo_ie_render_required_catalogs_import_card(); } ?>

                <div class="card" style="max-width:none;padding:20px;">
                    <h2>Exportar categorías</h2>
                    <p>Exporta la estructura WooCommerce y sus datos SEO.</p>
                    <form method="post">
                        <?php wp_nonce_field( 'seo_export_categories_csv', 'seo_export_categories_nonce' ); ?>
                        <?php wp_nonce_field( 'seo_export_required_catalogs_csv', 'seo_export_required_catalogs_nonce' ); ?>

                        <p>
                            <button type="submit" name="seo_export_categories" value="1" class="button button-primary">Exportar categorías</button>
                        </p>

                        <div style="margin:16px 0 0;padding:14px 16px;border:1px solid #c3a57a;border-radius:4px;background:#fffaf3;">
                            <strong>Valores obligatorios para altas y actualizaciones</strong>
                            <p style="margin:6px 0 12px;">
                                La clasificación debe utilizar vocabulario existente y activo. El mismo CSV maestro usado para productos contiene el vocabulario semántico, la relación <strong>TIPO → ROL</strong> y los catálogos SQL de atributos/valores permitidos.
                                Si falta un valor, debe darse de alta en su maestro antes de asignarlo.
                            </p>
                            <button type="submit" name="seo_export_required_catalogs" value="1" class="button button-secondary">
                                Descargar catálogos obligatorios CSV
                            </button>
                        </div>
                    </form>
                </div>


                <div class="card" style="max-width:none;padding:20px;">
                    <h2>Exportar páginas</h2>
                    <p>Exporta contenido, jerarquía, autor, fechas, imagen y metadatos. La plantilla se gestiona por SEO System y no viaja en el CSV.</p>
                    <form method="post">
                        <?php wp_nonce_field( 'seo_export_pages_csv', 'seo_export_pages_nonce' ); ?>
                        <fieldset style="margin:12px 0;">
                            <legend><strong>Estados incluidos</strong></legend>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="export_page_statuses[]" value="publish" checked> Publicadas</label>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="export_page_statuses[]" value="future" checked> Programadas</label>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="export_page_statuses[]" value="draft" checked> Borradores</label>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="export_page_statuses[]" value="pending" checked> Pendientes</label>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="export_page_statuses[]" value="private" checked> Privadas</label>
                        </fieldset>
                        <button type="submit" name="seo_export_pages" value="1" class="button button-primary">Exportar páginas</button>
                    </form>
                </div>

                <div class="card" style="max-width:none;padding:20px;">
                    <h2>Importar páginas</h2>
                    <p>Crea o actualiza el contenido en WordPress y, opcionalmente, sincroniza seo_role y la relación comercial landing → product_cat en SEO Relations. Una product_cat inexistente no detiene la importación: la página queda sin relación comercial y se registra ERROR RELACIÓN.</p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'seo_import_pages_csv', 'seo_import_pages_nonce' ); ?>
                        <input type="file" name="pages_csv" accept=".csv,text/csv" required>

                        <p style="margin-top:14px;">
                            <label for="seo-page-import-mode"><strong>Modo de importación</strong></label><br>
                            <select id="seo-page-import-mode" name="page_import_mode">
                                <option value="create_update">Crear y actualizar</option>
                                <option value="create_only">Solo crear</option>
                                <option value="update_only">Solo actualizar</option>
                            </select>
                        </p>

                        <h3>Datos que se importarán</h3>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_page_core" value="1" checked> Título, slug, estado, excerpt y contenido</label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_page_structure" value="1" checked> Jerarquía, orden, comentarios y pings</label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_page_author_date" value="1" checked> Autor y fecha de publicación</label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_page_seo_meta" value="1" checked> Metadatos SEO</label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_page_custom_meta" value="1" checked> Metadatos personalizados y de maquetadores</label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_page_image" value="1" checked> Imagen destacada</label>
                        <label style="display:block;margin-bottom:10px;"><input type="checkbox" name="import_page_relations" value="1" checked> Rol SEO y relación comercial con product_cat (seo_nodes + seo_relations)</label>
                        <label style="display:block;margin:12px 0;padding:10px;border-left:4px solid #72aee6;background:#f0f6fc;"><input type="checkbox" name="page_import_dry_run" value="1" checked> <strong>Simular primero</strong>: validar y mostrar el resultado sin escribir datos.</label>

                        <button type="submit" name="seo_import_pages" value="1" class="button button-primary">Procesar páginas</button>
                    </form>
                </div>
                <div class="card" style="max-width:none;padding:20px;">
                    <h2>Exportar entradas (posts)</h2>
                    <p>Exporta contenido, autor, fechas, categorías, etiquetas, formato, sticky, imagen destacada y metadatos.</p>
                    <form method="post">
                        <?php wp_nonce_field( 'seo_export_posts_csv', 'seo_export_posts_nonce' ); ?>
                        <fieldset style="margin:12px 0;">
                            <legend><strong>Estados incluidos</strong></legend>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="export_post_statuses[]" value="publish" checked> Publicadas</label>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="export_post_statuses[]" value="future" checked> Programadas</label>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="export_post_statuses[]" value="draft" checked> Borradores</label>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="export_post_statuses[]" value="pending" checked> Pendientes</label>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" name="export_post_statuses[]" value="private" checked> Privadas</label>
                        </fieldset>
                        <button type="submit" name="seo_export_posts" value="1" class="button button-primary">Exportar entradas</button>
                    </form>
                </div>

                <div class="card" style="max-width:none;padding:20px;">
                    <h2>Importar entradas (posts)</h2>
                    <p>Crea o actualiza el contenido en WordPress. Las categorías editoriales siguen siendo independientes de la relación comercial post → product_cat, que se guarda en SEO Relations. Una product_cat inexistente no detiene la importación: el post queda sin relación comercial y se registra ERROR RELACIÓN.</p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'seo_import_posts_csv', 'seo_import_posts_nonce' ); ?>
                        <input type="file" name="posts_csv" accept=".csv,text/csv" required>

                        <p style="margin-top:14px;">
                            <label for="seo-post-import-mode"><strong>Modo de importación</strong></label><br>
                            <select id="seo-post-import-mode" name="post_import_mode">
                                <option value="create_update">Crear y actualizar</option>
                                <option value="create_only">Solo crear</option>
                                <option value="update_only">Solo actualizar</option>
                            </select>
                        </p>

                        <h3>Datos que se importarán</h3>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_post_core" value="1" checked> Título, slug, estado, excerpt y contenido</label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_post_taxonomies" value="1" checked> Categorías, etiquetas, formato, sticky, comentarios y pings</label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_post_author_date" value="1" checked> Autor y fecha de publicación</label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_post_seo_meta" value="1" checked> Metadatos SEO</label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_post_custom_meta" value="1" checked> Metadatos personalizados y de maquetadores</label>
                        <label style="display:block;margin-bottom:6px;"><input type="checkbox" name="import_post_image" value="1" checked> Imagen destacada</label>
                        <label style="display:block;margin-bottom:10px;"><input type="checkbox" name="import_post_relations" value="1" checked> Relación comercial con product_cat (seo_relations)</label>
                        <label style="display:block;margin:12px 0;padding:10px;border-left:4px solid #72aee6;background:#f0f6fc;"><input type="checkbox" name="post_import_dry_run" value="1" checked> <strong>Simular primero</strong>: validar y mostrar el resultado sin escribir datos.</label>

                        <button type="submit" name="seo_import_posts" value="1" class="button button-primary">Procesar entradas</button>
                    </form>
                </div>

                <div class="card" style="max-width:none;padding:20px;"><h2>Exportar FAQs</h2><p>Exporta preguntas, respuestas, ámbito, orden, estado y métricas.</p><form method="post"><?php wp_nonce_field( 'seo_export_faqs_csv', 'seo_export_faqs_nonce' ); ?><button type="submit" name="seo_export_faqs" value="1" class="button button-primary">Exportar FAQs</button></form></div>
                <div class="card" style="max-width:none;padding:20px;"><h2>Importar FAQs</h2><p>Actualiza por faq_id o crea una FAQ nueva cuando el ID no exista.</p><form method="post" enctype="multipart/form-data"><?php wp_nonce_field( 'seo_import_faqs_csv', 'seo_import_faqs_nonce' ); ?><input type="file" name="faqs_csv" accept=".csv,text/csv" required><p><button type="submit" name="seo_import_faqs" value="1" class="button button-primary">Importar FAQs</button></p></form></div>

                <div class="card" style="max-width:none;padding:20px;">
                    <h2>Exportar redirects</h2>
                    <p>Exporta la tabla <code>wp_seo_redirects</code>, incluidas las métricas <code>hits</code> y <code>last_hit</code>.</p>
                    <form method="post">
                        <?php wp_nonce_field( 'seo_export_redirects_csv', 'seo_export_redirects_nonce' ); ?>
                        <button type="submit" name="seo_export_redirects" value="1" class="button button-primary">Exportar redirects</button>
                    </form>
                </div>

                <div class="card" style="max-width:none;padding:20px;">
                    <h2>Importar redirects</h2>
                    <p>Crea o actualiza por <code>origin_url</code>. No elimina redirects que no aparezcan en el CSV y deja el ID al AUTO_INCREMENT.</p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'seo_import_redirects_csv', 'seo_import_redirects_nonce' ); ?>
                        <input type="file" name="redirects_csv" accept=".csv,text/csv" required>
                        <p class="description">Columnas obligatorias: origin_url y target_url. Opcionales: redirect_id, status_code, hits y last_hit.</p>
                        <p><button type="submit" name="seo_import_redirects" value="1" class="button button-primary">Importar redirects</button></p>
                    </form>
                </div>
            </div>
            <?php seo_ie_render_log( $last_log ); ?>
        <?php elseif ( 'import-batch' === $tab ) : ?>
            <?php if ( function_exists( 'seo_ie_batch_render_page' ) ) { seo_ie_batch_render_page(); } else { echo '<div class="notice notice-error inline"><p>Falta el modulo seo-import-batch.php.</p></div>'; } ?>
        <?php elseif ( 'importar-proveedor' === $tab ) : ?>
            <?php if ( function_exists( 'seo_proveedores_render_importador' ) ) { seo_proveedores_render_importador(); } else { echo '<div class="notice notice-error inline"><p>No se ha podido cargar el motor de importación de proveedores.</p></div>'; } ?>
            <?php if ( ! empty( $last_log ) && 'Importación de catálogo de proveedor' === ( $last_log['operacion'] ?? '' ) ) { seo_ie_render_log( $last_log ); } ?>
        <?php elseif ( 'importar-amazon' === $tab ) : ?>
            <?php if ( function_exists( 'seo_supplier_recipe_amazon_render_explorer' ) ) { seo_supplier_recipe_amazon_render_explorer(); } else { echo '<div class="notice notice-error inline"><p>No se ha podido cargar el módulo de importación Amazon. Comprueba suppliers/recipes/import_amazon.php.</p></div>'; } ?>
        <?php elseif ( 'conexiones-proveedores' === $tab ) : ?>
            <?php if ( function_exists( 'seo_proveedores_render_conexiones' ) ) { seo_proveedores_render_conexiones(); } else { echo '<div class="notice notice-error inline"><p>No se ha podido cargar el módulo de conexiones con proveedores.</p></div>'; } ?>
        <?php elseif ( 'sincronizacion-proveedores' === $tab ) : ?>
            <?php if ( function_exists( 'seo_supplier_v2_render_admin' ) ) { seo_supplier_v2_render_admin(); } else { echo '<div class="notice notice-error inline"><p>No se ha podido cargar Supplier Sync V2.</p></div>'; } ?>
        <?php else : ?>
            <?php if ( function_exists( 'seo_proveedores_render_catalogo' ) ) { seo_proveedores_render_catalogo(); } else { echo '<div class="notice notice-error inline"><p>No se ha podido cargar el motor de importación de proveedores.</p></div>'; } ?>
        <?php endif; ?>
    </div>
    <?php
}