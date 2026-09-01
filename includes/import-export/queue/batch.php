<?php
/**
 * SEO System — Cola secuencial multientidad.
 *
 * Responsabilidad:
 * Administrar CSV pendientes, en proceso, importados y fallidos; detectar su
 * entidad por cabecera y garantizar que solo un archivo se procese a la vez.
 *
 * Productos se procesan en bloques adaptativos. El primer bloque se ejecuta tras
 * una accion explicita. Los siguientes usan Action Scheduler y WP-Cron; cuando
 * el servidor no ejecuta esas colas, la pestana abierta puede continuar un
 * unico bloque cada vez, pero nunca inicia una cola idle por si sola.
 *
 * Este archivo no contiene reglas de negocio de productos ni recetas de
 * proveedores. Reutiliza los motores registrados por legacy-engine.php.
 *
 * @package SEOSystem
 * @subpackage ImportExport
 * @since 2.2.0
 * @version 2026-09-01
 * Build: 034
 */

defined( 'ABSPATH' ) || exit;

/*
 * Base defensiva heredada del Build 027.
 *
 * seo-import-batch.php se carga al principio de seo-export.php. Definir aqui
 * estas funciones con guardas evita un error fatal si el servidor conserva por
 * error una copia anterior de seo-export.php que las llama pero no las incluye.
 */
if ( ! function_exists( 'seo_ie_product_import_kick_cron' ) ) {
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

if ( ! function_exists( 'seo_ie_product_import_schedule_wp_fallback' ) ) {
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

add_action( 'admin_init', 'seo_ie_batch_admin_action', 5 );
add_action( 'seo_ie_process_import_batch_queue', 'seo_ie_batch_queue_worker', 10, 1 );
add_action( 'wp_ajax_seo_ie_batch_tick', 'seo_ie_batch_ajax_tick' );

/**
 * Rutas de la cola.
 *
 * @return array<string,string>
 */
function seo_ie_batch_paths() {
    $base = trailingslashit( dirname( __DIR__ ) ) . 'migrations';

    return [
        'base'       => $base,
        'pending'    => trailingslashit( $base ) . 'pending',
        'processing' => trailingslashit( $base ) . 'processing',
        'imported'   => trailingslashit( $base ) . 'imported',
        'failed'     => trailingslashit( $base ) . 'failed',
    ];
}

/**
 * Crea las carpetas y bloquea el acceso web directo.
 *
 * @return string[]
 */
function seo_ie_batch_prepare_directories() {
    $paths  = seo_ie_batch_paths();
    $errors = [];

    foreach ( $paths as $key => $directory ) {
        if ( 'base' !== $key && ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
            $errors[] = sprintf( 'No se pudo crear la carpeta %s.', $directory );
        }
    }

    if ( ! is_dir( $paths['base'] ) && ! wp_mkdir_p( $paths['base'] ) ) {
        $errors[] = sprintf( 'No se pudo crear la carpeta %s.', $paths['base'] );
    }

    if ( empty( $errors ) ) {
        foreach ( $paths as $directory ) {
            $index = trailingslashit( $directory ) . 'index.php';
            if ( ! file_exists( $index ) ) {
                @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
            }

            $htaccess = trailingslashit( $directory ) . '.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                @file_put_contents(
                    $htaccess,
                    "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
                );
            }
        }
    }

    return array_values( array_unique( $errors ) );
}

/**
 * Lista CSV de una carpeta en orden natural.
 *
 * @param string $directory Carpeta.
 * @return string[]
 */
function seo_ie_batch_files( $directory ) {
    if ( ! is_dir( $directory ) ) {
        return [];
    }

    $files = [];
    foreach ( (array) scandir( $directory ) as $name ) {
        if ( '.' === $name || '..' === $name || 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
            continue;
        }

        $path = trailingslashit( $directory ) . $name;
        if ( is_file( $path ) ) {
            $files[] = $path;
        }
    }

    natcasesort( $files );
    return array_values( $files );
}

/**
 * Estado global de la cola.
 *
 * @return array
 */
function seo_ie_batch_status() {
    $status = get_option( 'seo_ie_batch_queue_status', [] );
    return is_array( $status ) ? $status : [];
}

/**
 * Guarda estado e historial resumido.
 *
 * @param array $status Estado.
 * @return void
 */
function seo_ie_batch_store_status( $status ) {
    $previous = seo_ie_batch_status();
    $status   = is_array( $status ) ? $status : [];

    if ( ! isset( $status['enabled'] ) && isset( $previous['enabled'] ) ) {
        $status['enabled'] = (bool) $previous['enabled'];
    }

    $status['updated_at'] = current_time( 'mysql' );
    $history = (array) ( $previous['history'] ?? [] );

    if ( ! empty( $status['history_event'] ) ) {
        $history[] = [
            'fecha'   => $status['updated_at'],
            'estado'  => sanitize_key( $status['status'] ?? '' ),
            'archivo' => sanitize_file_name( $status['current_file'] ?? $status['last_file'] ?? '' ),
            'tipo'    => sanitize_key( $status['entity'] ?? '' ),
            'mensaje' => sanitize_text_field( $status['message'] ?? '' ),
        ];
        unset( $status['history_event'] );
    }

    if ( 50 < count( $history ) ) {
        $history = array_slice( $history, -50 );
    }

    $status['history'] = $history;
    update_option( 'seo_ie_batch_queue_status', $status, false );
}

/**
 * Indica si el codigo se esta ejecutando como importacion interna de la cola.
 *
 * @param string $entity Tipo opcional.
 * @return bool
 */
function seo_ie_batch_is_internal( $entity = '' ) {
    $context = $GLOBALS['seo_ie_batch_context'] ?? null;

    if ( ! is_array( $context ) || empty( $context['internal'] ) ) {
        return false;
    }

    return '' === $entity || sanitize_key( $context['entity'] ?? '' ) === sanitize_key( $entity );
}

/**
 * Bloquea una importacion manual mientras la cola escribe otro archivo.
 *
 * @return bool
 */
function seo_ie_batch_is_running() {
    $status = seo_ie_batch_status();
    $state  = sanitize_key( $status['status'] ?? '' );

    return in_array( $state, [ 'starting', 'processing', 'waiting_next', 'stopping' ], true )
        || ! empty( seo_ie_batch_files( seo_ie_batch_paths()['processing'] ) );
}

/**
 * Mensaje reutilizable para impedir importaciones simultaneas.
 *
 * @param string $entity Tipo solicitado.
 * @return void
 */
function seo_ie_batch_guard_manual_import( $entity ) {
    if ( seo_ie_batch_is_internal( $entity ) ) {
        return;
    }

    if ( seo_ie_batch_is_running() ) {
        wp_die(
            esc_html__(
                'Hay una importacion por lotes en curso. Espera a que termine o deten la cola antes de iniciar una importacion individual.',
                'seo-system'
            )
        );
    }
}

/**
 * Normaliza las cabeceras para evaluar cada esquema.
 *
 * @param array $raw_header Cabecera original.
 * @return array<string,array>
 */
function seo_ie_batch_normalized_headers( $raw_header ) {
    return [
        'product'  => seo_ie_normalize_csv_header( $raw_header, 'product' ),
        'category' => seo_ie_normalize_csv_header( $raw_header, 'category' ),
        'page'     => seo_ie_normalize_csv_header( $raw_header, 'page' ),
        'post'     => seo_ie_normalize_csv_header( $raw_header, 'post' ),
        'faq'      => seo_ie_normalize_csv_header( $raw_header, 'faq' ),
        'redirect' => seo_ie_normalize_csv_header( $raw_header, 'redirect' ),
    ];
}

/**
 * Detecta el importador por las cabeceras del CSV.
 *
 * @param string $path Ruta.
 * @return array|WP_Error
 */
function seo_ie_batch_detect_entity( $path ) {
    if ( ! is_file( $path ) || 'csv' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
        return new WP_Error( 'seo_batch_file', 'El archivo no existe o no es CSV.' );
    }

    $handle = fopen( $path, 'r' );
    if ( false === $handle ) {
        return new WP_Error( 'seo_batch_open', 'No se pudo abrir el CSV.' );
    }

    $raw = seo_ie_read_csv_row( $handle );
    fclose( $handle );

    if ( false === $raw || empty( array_filter( (array) $raw, static fn( $value ) => '' !== trim( (string) $value ) ) ) ) {
        return new WP_Error( 'seo_batch_empty', 'El CSV esta vacio o no contiene cabecera.' );
    }

    $sets = seo_ie_batch_normalized_headers( $raw );
    $has  = static fn( $entity, $column ) => in_array( $column, $sets[ $entity ], true );

    // Conserva tambien las claves originales normalizadas. Un encabezado generico
    // como `id` se convierte en category_id, product_id, page_id y post_id segun
    // el esquema evaluado, por lo que no puede considerarse una identidad exclusiva.
    $raw_keys = array_map(
        static function ( $column ) {
            return sanitize_key( trim( (string) seo_ie_csv_to_utf8( $column ) ) );
        },
        (array) $raw
    );

    $filename = strtolower( basename( $path ) );
    $filename_hint = '';
    $filename_prefixes = [
        'category' => [ 'seo_categories_', 'categories_', 'categorias_', 'category_' ],
        'product'  => [ 'seo_products_', 'products_', 'productos_', 'product_' ],
        'page'     => [ 'seo_pages_', 'pages_', 'paginas_', 'page_' ],
        'post'     => [ 'seo_posts_', 'posts_', 'entradas_', 'post_' ],
        'faq'      => [ 'seo_faqs_', 'faqs_', 'faq_' ],
        'redirect' => [ 'seo_redirects_', 'redirects_', 'redirect_' ],
    ];

    foreach ( $filename_prefixes as $hint_entity => $prefixes ) {
        foreach ( $prefixes as $prefix ) {
            if ( 0 === strpos( $filename, $prefix ) ) {
                $filename_hint = $hint_entity;
                break 2;
            }
        }
    }

    $redirect_required = [ 'origin_url', 'target_url' ];
    if ( empty( array_diff( $redirect_required, $sets['redirect'] ) ) ) {
        return [ 'entity' => 'redirect', 'header' => $sets['redirect'], 'raw_header' => $raw, 'confidence' => 100 ];
    }

    $faq_required = [ 'object_type', 'object_id', 'question', 'answer' ];
    if ( empty( array_diff( $faq_required, $sets['faq'] ) ) ) {
        return [ 'entity' => 'faq', 'header' => $sets['faq'], 'raw_header' => $raw, 'confidence' => 100 ];
    }

    $category_has_explicit_identity = ! empty( array_intersect( [ 'category_id', 'term_id' ], $raw_keys ) );
    $category_specific_markers = [
        'hub_secondary_id', 'hub_secundario_id', 'secondary_id',
        'thumbnail_id', 'thumbnail', 'thumbnail_url',
        'category_image_id', 'category_image', 'category_image_url',
    ];
    $category_specific_score = count( array_intersect( $category_specific_markers, $raw_keys ) );

    if (
        $has( 'category', 'category_id' )
        && (
            $category_has_explicit_identity
            || 0 < $category_specific_score
            || 'category' === $filename_hint
        )
    ) {
        return [
            'entity'     => 'category',
            'header'     => $sets['category'],
            'raw_header' => $raw,
            'confidence' => $category_has_explicit_identity ? 100 : 92,
        ];
    }

    // Las entradas comparten muchas columnas con paginas y productos. Se detectan
    // antes que ambos usando marcadores propios de post: taxonomias de WordPress,
    // formato y sticky. Esto evita que post_id se normalice como product_id/page_id
    // y desvie un CSV de entradas al importador equivocado.
    $post_markers = [
        'categorias_slugs', 'categorias_nombres',
        'etiquetas_ids', 'etiquetas_slugs', 'etiquetas_nombres',
        'formato', 'sticky',
    ];
    $post_score = count( array_intersect( $post_markers, $sets['post'] ) );

    /*
     * Las relaciones comerciales post/page -> product_cat son columnas
     * compartidas por ambos esquemas y, por si solas, no distinguen una
     * entrada de una pagina. Si aparecen junto a post_id, en cambio, son un
     * marcador inequívoco de un CSV de entradas y deben evaluarse antes de
     * que el alias post_id -> page_id pueda desviarlo al importador de paginas.
     */
    $product_cat_relation_markers = [
        'product_cat_relacion_ids',
        'product_cat_relacion_slugs',
        'product_cat_relacion_nombres',
    ];
    $post_relation_score = count( array_intersect( $product_cat_relation_markers, $sets['post'] ) );

    $post_has_explicit_identity = in_array( 'post_id', $raw_keys, true ) || 'post' === $filename_hint;

    if (
        (
            1 <= $post_score
            && (
                $post_has_explicit_identity
                || 2 <= $post_score
            )
        )
        || ( 1 <= $post_relation_score && $post_has_explicit_identity )
    ) {
        return [
            'entity'     => 'post',
            'header'     => $sets['post'],
            'raw_header' => $raw,
            'confidence' => min( 100, 82 + ( 3 * $post_score ) + ( 4 * $post_relation_score ) ),
        ];
    }

    $product_markers = [
        'product_id', 'sku', 'tipo_producto', 'visibilidad_catalogo', 'categorias_ids',
        'precio_normal', 'precio_actual', 'estado_stock', 'proveedor_id_externo',
        'atributos_wc_json', 'atributos_seo_json', 'galeria_urls',
        'tipo_semantico', 'rol', 'ambito', 'aplicacion', 'plataforma', 'subtipo',
    ];
    $product_score = count( array_intersect( $product_markers, $sets['product'] ) );

    if (
        (
            2 <= $product_score
            && (
                $has( 'product', 'product_id' )
                || $has( 'product', 'sku' )
                || $has( 'product', 'proveedor_id_externo' )
            )
        )
        || ( 'product' === $filename_hint && $has( 'product', 'product_id' ) )
    ) {
        return [ 'entity' => 'product', 'header' => $sets['product'], 'raw_header' => $raw, 'confidence' => min( 100, 70 + ( 3 * max( 1, $product_score ) ) ) ];
    }

    $page_markers = [
        'page_id', 'ruta', 'parent_ruta', 'parent_slug', 'menu_order', 'plantilla',
        'seo_role', 'autor_id', 'fecha_gmt', 'meta_seo', 'meta_personalizados', 'comentarios', 'pings',
    ];
    $page_score = count( array_intersect( $page_markers, $sets['page'] ) );

    $page_has_explicit_identity = ! empty( array_intersect( [ 'page_id', 'post_id' ], $raw_keys ) ) || 'page' === $filename_hint;

    if ( 1 <= $page_score && ( $page_has_explicit_identity || $has( 'page', 'ruta' ) || 2 <= $page_score ) ) {
        return [ 'entity' => 'page', 'header' => $sets['page'], 'raw_header' => $raw, 'confidence' => min( 100, 70 + ( 4 * $page_score ) ) ];
    }

    return new WP_Error(
        'seo_batch_unknown_schema',
        'No se pudo identificar el destino por la cabecera. Usa un CSV exportado por SEO System o un prefijo products_, categories_, pages_, posts_, faqs_ o redirects_.'
    );
}

/**
 * Nombre legible de un tipo.
 *
 * @param string $entity Tipo.
 * @return string
 */
function seo_ie_batch_entity_label( $entity ) {
    $entity = sanitize_key( $entity );

    if ( '' === $entity ) {
        return '—';
    }

    return function_exists( 'seo_ie_entity_label' )
        ? seo_ie_entity_label( $entity )
        : ucfirst( $entity );
}

/**
 * Devuelve un destino unico.
 *
 * @param string $directory Carpeta.
 * @param string $filename  Nombre.
 * @return string
 */
function seo_ie_batch_unique_path( $directory, $filename ) {
    $filename = sanitize_file_name( $filename );
    $target   = trailingslashit( $directory ) . $filename;

    if ( ! file_exists( $target ) ) {
        return $target;
    }

    $info = pathinfo( $filename );
    $name = sanitize_file_name( $info['filename'] ?? 'importacion' );
    $ext  = sanitize_key( $info['extension'] ?? 'csv' );
    $base = trailingslashit( $directory ) . $name . '-' . wp_date( 'Ymd-His' );
    $n    = 0;

    do {
        $suffix = 0 === $n ? '' : '-' . $n;
        $target = $base . $suffix . '.' . $ext;
        $n++;
    } while ( file_exists( $target ) && 10000 > $n );

    return $target;
}

/**
 * Mueve un archivo con respaldo copy/unlink.
 *
 * @param string $source Origen.
 * @param string $target Destino.
 * @return bool
 */
function seo_ie_batch_move_file( $source, $target ) {
    if ( ! is_file( $source ) ) {
        return false;
    }

    wp_mkdir_p( dirname( $target ) );

    if ( @rename( $source, $target ) ) {
        return true;
    }

    return @copy( $source, $target ) && @unlink( $source );
}

/**
 * Escribe un log JSON junto al CSV finalizado.
 *
 * @param string $csv_path CSV final.
 * @param array  $log      Log.
 * @param array  $extra    Datos extra.
 * @return void
 */
function seo_ie_batch_write_sidecar_log( $csv_path, $log, $extra = [] ) {
    if ( '' === $csv_path ) {
        return;
    }

    $payload = array_merge(
        [
            'archivo' => basename( $csv_path ),
            'fecha'   => current_time( 'mysql' ),
            'log'     => is_array( $log ) ? $log : [],
        ],
        (array) $extra
    );

    @file_put_contents(
        $csv_path . '.log.json',
        wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
    );
}

/**
 * Opciones automaticas para productos.
 *
 * @return array
 */
function seo_ie_batch_product_options( $header = [] ) {
    $header = array_values( array_filter( array_map( 'sanitize_key', (array) $header ) ) );
    $has_any = static function ( $columns ) use ( $header ) {
        return ! empty( array_intersect( (array) $columns, $header ) );
    };

    return [
        'core' => $has_any( [
            'titulo', 'slug', 'estado', 'excerpt', 'description',
            'destacado', 'visibilidad_catalogo',
        ] ),
        'commerce' => $has_any( [
            'sku', 'precio_normal', 'precio_rebajado', 'precio_proveedor',
            'estado_impuesto', 'clase_impuesto', 'gestionar_stock',
            'cantidad_stock', 'estado_stock', 'pedidos_pendientes',
            'vendido_individualmente', 'peso', 'longitud', 'anchura', 'altura',
            'virtual', 'descargable', 'clase_envio_id', 'clase_envio',
        ] ),
        'categories'     => $has_any( [ 'categorias_ids', 'categorias' ] ),

        /*
         * El importador automatico por cabeceras solo mantiene el producto base.
         * El enriquecimiento semantico se procesa en un flujo independiente para
         * poder resolver el vocabulario canonico antes de escribir. Por tanto,
         * aunque estas columnas existan en el CSV, aqui se ignoran expresamente.
         */
        'wc_tags'        => false,
        'scope'          => false,
        'labels'         => false,
        'vocabulary'     => false,
        'seo_attributes' => false,
        'wc_attributes'  => false,

        'brand_provider' => $has_any( [
            'marca', 'marca_ids', 'marca_taxonomia', 'fabricante',
            'proveedor', 'proveedor_id_externo', 'proveedor_catalogo_id',
            'categoria_proveedor', 'precio_proveedor',
        ] ),
        'images'         => false,
        'create'         => false,
        'force_draft'    => false,
        'empty_clears'   => false,
        'dry_run'        => false,
    ];
}

/**
 * Bloqueo global breve para impedir dos workers de archivo simultaneos.
 *
 * @return string Token o cadena vacia si ya esta ocupado.
 */
function seo_ie_batch_acquire_lock() {
    $key = 'seo_ie_batch_queue_lock';

    if ( get_transient( $key ) ) {
        return '';
    }

    $token = strtolower( wp_generate_password( 24, false, false ) );
    set_transient( $key, $token, 10 * MINUTE_IN_SECONDS );

    return get_transient( $key ) === $token ? $token : '';
}

/**
 * Libera el bloqueo si pertenece a esta ejecucion.
 *
 * @param string $token Token.
 * @return void
 */
function seo_ie_batch_release_lock( $token ) {
    if ( '' !== $token && get_transient( 'seo_ie_batch_queue_lock' ) === $token ) {
        delete_transient( 'seo_ie_batch_queue_lock' );
    }
}

/**
 * Programa el siguiente archivo con Action Scheduler y WP-Cron de respaldo.
 *
 * @param int $user_id Usuario.
 * @param int $delay Retraso.
 * @return bool
 */
function seo_ie_batch_schedule( $user_id, $delay = 3 ) {
    $user_id = absint( $user_id );
    $delay   = max( 1, absint( $delay ) );
    $hook    = 'seo_ie_process_import_batch_queue';
    $args    = [ $user_id ];
    $group   = 'seo-system-import-batch';
    $ok      = false;

    if ( 0 === $user_id ) {
        return false;
    }

    if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, $group ) ) {
        $ok = true;
    } elseif ( function_exists( 'as_schedule_single_action' ) ) {
        $ok = 0 < absint( as_schedule_single_action( time() + $delay, $hook, $args, $group, true, 10 ) );
    }

    if ( false === wp_next_scheduled( $hook, $args ) ) {
        $cron = wp_schedule_single_event( time() + max( 30, $delay + 20 ), $hook, $args, true );
        if ( true === $cron ) {
            $ok = true;
        }
    }

    if ( function_exists( 'spawn_cron' ) ) {
        spawn_cron( time() );
    }

    return $ok;
}

/**
 * Cancela acciones pendientes de la cola general.
 *
 * @param int $user_id Usuario.
 * @return void
 */
function seo_ie_batch_unschedule( $user_id ) {
    $args  = [ absint( $user_id ) ];
    $hook  = 'seo_ie_process_import_batch_queue';
    $group = 'seo-system-import-batch';

    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( $hook, $args, $group );
    }

    wp_clear_scheduled_hook( $hook, $args );
}

/**
 * Inicia un CSV de productos usando el motor por lotes existente.
 *
 * @param int    $user_id Usuario.
 * @param string $processing_path Archivo en processing.
 * @param array  $detected Deteccion.
 * @return string|WP_Error
 */
function seo_ie_batch_start_product( $user_id, $processing_path, $detected ) {
    if ( ! empty( seo_ie_product_import_get_active( $user_id ) ) ) {
        return new WP_Error( 'seo_batch_product_busy', 'Ya existe una importacion de productos activa.' );
    }

    $upload_dir = wp_upload_dir();
    $temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'seo-import-temp';
    wp_mkdir_p( $temp_dir );

    $token = strtolower( wp_generate_password( 32, false, false ) );
    $path  = trailingslashit( $temp_dir ) . 'products-v2-' . absint( $user_id ) . '-' . sanitize_file_name( $token ) . '.csv';

    if ( ! copy( $processing_path, $path ) ) {
        return new WP_Error( 'seo_batch_product_copy', 'No se pudo copiar el CSV de productos a la carpeta temporal.' );
    }

    $handle = fopen( $path, 'r' );
    if ( false === $handle ) {
        @unlink( $path );
        return new WP_Error( 'seo_batch_product_open', 'No se pudo abrir el CSV temporal de productos.' );
    }

    $raw_header = seo_ie_read_csv_row( $handle );
    $header     = seo_ie_normalize_csv_header( $raw_header, 'product' );
    $counts     = array_count_values( array_filter( $header, static fn( $value ) => '' !== (string) $value ) );
    $duplicates = array_keys( array_filter( $counts, static fn( $count ) => 1 < $count ) );

    if ( ! empty( $duplicates ) ) {
        fclose( $handle );
        @unlink( $path );
        return new WP_Error( 'seo_batch_product_headers', sprintf( 'Cabeceras duplicadas: %s.', implode( ', ', $duplicates ) ) );
    }

    $paths    = seo_ie_batch_paths();
    $filename = sanitize_file_name( basename( $processing_path ) );
    $state    = [
        'path'                 => $path,
        'filename'             => $filename,
        'header'               => $header,
        'offset'               => ftell( $handle ),
        'line'                 => 1,
        'user_id'              => absint( $user_id ),
        'options'              => seo_ie_batch_product_options( $header ),
        'status'               => 'processing',
        'retries'              => 0,
        'started_at'           => time(),
        'updated_at'           => time(),
        'batch_number'         => 0,
        'transactions'         => [],
        'queue_mode'           => 1,
        'batch_queue_mode'     => 1,
        'queue_source_path'    => $processing_path,
        'queue_imported_path'  => seo_ie_batch_unique_path( $paths['imported'], $filename ),
        'queue_failed_path'    => seo_ie_batch_unique_path( $paths['failed'], $filename ),
        'queue_pending_path'   => seo_ie_batch_unique_path( $paths['pending'], $filename ),
        'queue_filename'       => $filename,
        'queue_entity'         => 'product',
        'log'                  => [
            'operacion'    => 'Importacion por lotes: productos V2',
            'archivo'      => $filename,
            'procesados'   => 0,
            'correctos'    => 0,
            'creados'      => 0,
            'actualizados' => 0,
            'omitidos'     => 0,
            'errores'      => 0,
            'advertencias' => 0,
            'simulacion'   => 0,
            'detalles'     => [
                'Modo producto base: se ignoran etiquetas WooCommerce, semantica/ambito y atributos SEO/WooCommerce aunque esas columnas existan en el CSV.',
            ],
        ],
    ];

    fclose( $handle );
    seo_ie_product_import_add_transaction(
        $state,
        'batch_file_validated',
        'El CSV de productos fue detectado por cabecera y preparado para la cola general.',
        [ 'file' => $filename, 'offset' => $state['offset'] ]
    );

    delete_transient( seo_ie_product_import_cancel_key( $user_id, $token ) );
    set_transient( seo_ie_product_import_state_key( $user_id, $token ), $state, DAY_IN_SECONDS );
    delete_transient( seo_ie_product_import_result_key( $user_id, $token ) );
    seo_ie_product_import_set_active( $user_id, $token, $state );

    seo_ie_batch_store_status(
        [
            'enabled'       => true,
            'status'        => 'processing',
            'user_id'       => absint( $user_id ),
            'current_file'  => $filename,
            'entity'        => 'product',
            'message'       => 'Importando productos con lotes adaptativos regulados por tiempo y memoria.',
            'history_event' => true,
        ]
    );

    // Primer lote directo: evita depender del primer worker de Action Scheduler.
    seo_ie_product_import_background_worker( $user_id, $token );

    $active = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );
    $result = get_transient( seo_ie_product_import_result_key( $user_id, $token ) );

    if ( ! is_array( $active ) && ! is_array( $result ) ) {
        seo_ie_product_import_clear_active( $user_id, $token );
        @unlink( $path );
        return new WP_Error( 'seo_batch_product_bootstrap', 'El primer lote de productos no dejo un estado recuperable.' );
    }

    return $token;
}

/**
 * Prepara superglobales para reutilizar importadores individuales.
 *
 * @param string $entity Tipo.
 * @param string $path Archivo.
 * @return void
 */
function seo_ie_batch_prepare_internal_request( $entity, $path ) {
    $_POST    = [];
    $_FILES   = [];
    $_REQUEST = [];

    if ( 'category' === $entity ) {
        $_POST['seo_import_categories']       = '1';
        $_POST['seo_import_categories_nonce'] = wp_create_nonce( 'seo_import_categories_csv' );
        $_FILES['categories_csv'] = [
            'name' => basename( $path ), 'tmp_name' => $path, 'error' => 0,
            'size' => filesize( $path ), 'type' => 'text/csv',
        ];
    } elseif ( 'page' === $entity ) {
        $_POST = [
            'seo_import_pages'         => '1',
            'seo_import_pages_nonce'   => wp_create_nonce( 'seo_import_pages_csv' ),
            'page_import_mode'         => 'create_update',
            'import_page_core'         => '1',
            'import_page_structure'    => '1',
            'import_page_author_date'  => '1',
            'import_page_seo_meta'     => '1',
            'import_page_custom_meta'  => '1',
            'import_page_image'        => '1',
            'import_page_relations'    => '1',
        ];
        $_FILES['pages_csv'] = [
            'name' => basename( $path ), 'tmp_name' => $path, 'error' => 0,
            'size' => filesize( $path ), 'type' => 'text/csv',
        ];
    } elseif ( 'post' === $entity ) {
        $_POST = [
            'seo_import_posts'           => '1',
            'seo_import_posts_nonce'     => wp_create_nonce( 'seo_import_posts_csv' ),
            'post_import_mode'           => 'create_update',
            'import_post_core'           => '1',
            'import_post_taxonomies'     => '1',
            'import_post_author_date'    => '1',
            'import_post_seo_meta'       => '1',
            'import_post_custom_meta'    => '1',
            'import_post_image'          => '1',
            'import_post_relations'      => '1',
        ];
        $_FILES['posts_csv'] = [
            'name' => basename( $path ), 'tmp_name' => $path, 'error' => 0,
            'size' => filesize( $path ), 'type' => 'text/csv',
        ];
    } elseif ( 'faq' === $entity ) {
        $_POST['seo_import_faqs']       = '1';
        $_POST['seo_import_faqs_nonce'] = wp_create_nonce( 'seo_import_faqs_csv' );
        $_FILES['faqs_csv'] = [
            'name' => basename( $path ), 'tmp_name' => $path, 'error' => 0,
            'size' => filesize( $path ), 'type' => 'text/csv',
        ];
    } elseif ( 'redirect' === $entity ) {
        $_POST['seo_import_redirects']       = '1';
        $_POST['seo_import_redirects_nonce'] = wp_create_nonce( 'seo_import_redirects_csv' );
        $_FILES['redirects_csv'] = [
            'name' => basename( $path ), 'tmp_name' => $path, 'error' => 0,
            'size' => filesize( $path ), 'type' => 'text/csv',
        ];
    }

    // check_admin_referer() consulta $_REQUEST, que no se actualiza al cambiar
    // $_POST durante la misma peticion.
    $_REQUEST = $_POST;
}

/**
 * Ejecuta categorias, paginas, entradas, FAQs o redirects reutilizando sus motores actuales.
 *
 * @param int    $user_id Usuario.
 * @param string $entity Tipo.
 * @param string $processing_path Archivo.
 * @return array|WP_Error
 */
function seo_ie_batch_run_nonproduct( $user_id, $entity, $processing_path ) {
    $previous_user = get_current_user_id();
    $old_post      = $_POST;
    $old_files     = $_FILES;
    $old_request   = $_REQUEST;
    $paths         = seo_ie_batch_paths();
    $filename      = sanitize_file_name( basename( $processing_path ) );

    if ( 0 < $user_id ) {
        wp_set_current_user( $user_id );
    }

    $GLOBALS['seo_ie_batch_last_log'] = null;
    $GLOBALS['seo_ie_batch_context']  = [
        'internal'   => true,
        'entity'     => $entity,
        'user_id'    => absint( $user_id ),
        'source_path'=> $processing_path,
        'filename'   => $filename,
    ];

    seo_ie_batch_prepare_internal_request( $entity, $processing_path );

    try {
        if ( 'category' === $entity ) {
            $returned = seo_import_categories_csv();
        } elseif ( 'page' === $entity ) {
            $returned = seo_import_pages_csv();
        } elseif ( 'post' === $entity ) {
            $returned = seo_import_posts_csv();
        } elseif ( 'faq' === $entity ) {
            $returned = seo_import_faqs_csv();
        } elseif ( 'redirect' === $entity ) {
            $returned = seo_import_redirects_csv();
        } else {
            return new WP_Error( 'seo_batch_entity', 'Tipo de importacion no compatible.' );
        }

        $log = is_array( $returned ) ? $returned : ( $GLOBALS['seo_ie_batch_last_log'] ?? [] );

        if ( ! is_array( $log ) || empty( $log['operacion'] ) ) {
            return new WP_Error( 'seo_batch_no_log', 'El importador termino sin devolver un log verificable.' );
        }

        $errors = absint( $log['errores'] ?? 0 );
        $target = seo_ie_batch_unique_path( 0 < $errors ? $paths['failed'] : $paths['imported'], $filename );

        if ( ! seo_ie_batch_move_file( $processing_path, $target ) ) {
            return new WP_Error( 'seo_batch_move', 'La importacion termino, pero no se pudo mover el CSV fuera de processing.' );
        }

        seo_ie_batch_write_sidecar_log( $target, $log, [ 'entity' => $entity, 'result' => 0 < $errors ? 'failed' : 'imported' ] );

        if ( 0 < $errors ) {
            seo_ie_batch_store_status(
                [
                    'enabled'       => false,
                    'status'        => 'failed',
                    'user_id'       => absint( $user_id ),
                    'current_file'  => '',
                    'last_file'     => basename( $target ),
                    'entity'        => $entity,
                    'message'       => sprintf( 'El archivo termino con %d errores y se movio a failed.', $errors ),
                    'history_event' => true,
                ]
            );
        } else {
            $continue = ! empty( seo_ie_batch_status()['enabled'] );
            seo_ie_batch_store_status(
                [
                    'enabled'       => $continue,
                    'status'        => $continue ? 'waiting_next' : 'paused',
                    'user_id'       => absint( $user_id ),
                    'current_file'  => '',
                    'last_file'     => basename( $target ),
                    'entity'        => $entity,
                    'message'       => $continue
                        ? 'Archivo importado correctamente; se prepara el siguiente.'
                        : 'Archivo importado correctamente; la cola permanece pausada.',
                    'history_event' => true,
                ]
            );
            if ( $continue ) {
                seo_ie_batch_schedule( $user_id, 2 );
            }
        }

        return $log;
    } catch ( Throwable $error ) {
        $target = seo_ie_batch_unique_path( $paths['failed'], $filename );
        seo_ie_batch_move_file( $processing_path, $target );
        $log = [
            'operacion'  => 'Importacion por lotes fallida',
            'archivo'    => $filename,
            'procesados' => 0,
            'correctos'  => 0,
            'errores'    => 1,
            'detalles'   => [ $error->getMessage() ],
        ];
        seo_ie_batch_write_sidecar_log( $target, $log, [ 'entity' => $entity, 'result' => 'exception' ] );
        seo_ie_batch_store_status(
            [
                'enabled'       => false,
                'status'        => 'failed',
                'user_id'       => absint( $user_id ),
                'current_file'  => '',
                'last_file'     => basename( $target ),
                'entity'        => $entity,
                'message'       => 'La importacion produjo una excepcion: ' . $error->getMessage(),
                'history_event' => true,
            ]
        );
        return new WP_Error( 'seo_batch_exception', $error->getMessage() );
    } finally {
        $_POST    = $old_post;
        $_FILES   = $old_files;
        $_REQUEST = $old_request;
        unset( $GLOBALS['seo_ie_batch_context'], $GLOBALS['seo_ie_batch_last_log'] );
        wp_set_current_user( $previous_user );
    }
}

/**
 * Toma el siguiente archivo pendiente. Nunca inicia dos a la vez.
 *
 * @param int  $user_id Usuario.
 * @param bool $direct Permite ejecutar directamente el primer archivo no producto.
 * @return string|array|WP_Error
 */
function seo_ie_batch_start_next( $user_id, $direct = false ) {
    $user_id = absint( $user_id );
    $errors  = seo_ie_batch_prepare_directories();
    $paths   = seo_ie_batch_paths();
    $status  = seo_ie_batch_status();

    if ( ! empty( $errors ) ) {
        return new WP_Error( 'seo_batch_dirs', implode( ' ', $errors ) );
    }

    if ( ! empty( seo_ie_product_import_get_active( $user_id ) ) ) {
        return new WP_Error( 'seo_batch_product_active', 'Hay una importacion de productos activa.' );
    }

    $processing_files = seo_ie_batch_files( $paths['processing'] );
    if ( ! empty( $processing_files ) ) {
        $detected_processing = seo_ie_batch_detect_entity( $processing_files[0] );

        if ( is_wp_error( $detected_processing ) ) {
            return $detected_processing;
        }

        if ( 'product' === $detected_processing['entity'] ) {
            return seo_ie_batch_start_product( $user_id, $processing_files[0], $detected_processing );
        }

        return $direct
            ? seo_ie_batch_run_nonproduct( $user_id, $detected_processing['entity'], $processing_files[0] )
            : ( seo_ie_batch_schedule( $user_id, 1 ) ? 'scheduled' : new WP_Error( 'seo_batch_schedule', 'No se pudo reprogramar el archivo de processing.' ) );
    }

    $files = seo_ie_batch_files( $paths['pending'] );
    if ( empty( $files ) ) {
        seo_ie_batch_store_status(
            [
                'enabled'       => false,
                'status'        => 'completed',
                'user_id'       => $user_id,
                'current_file'  => '',
                'message'       => 'No quedan archivos pendientes.',
                'history_event' => true,
            ]
        );
        return 'completed';
    }

    $source   = $files[0];
    $detected = seo_ie_batch_detect_entity( $source );

    if ( is_wp_error( $detected ) ) {
        $failed = seo_ie_batch_unique_path( $paths['failed'], basename( $source ) );
        seo_ie_batch_move_file( $source, $failed );
        seo_ie_batch_write_sidecar_log(
            $failed,
            [ 'errores' => 1, 'detalles' => [ $detected->get_error_message() ] ],
            [ 'entity' => 'unknown', 'result' => 'invalid_header' ]
        );
        seo_ie_batch_store_status(
            [
                'enabled'       => false,
                'status'        => 'failed',
                'user_id'       => $user_id,
                'current_file'  => '',
                'last_file'     => basename( $failed ),
                'entity'        => 'unknown',
                'message'       => $detected->get_error_message(),
                'history_event' => true,
            ]
        );
        return $detected;
    }

    $processing = seo_ie_batch_unique_path( $paths['processing'], basename( $source ) );
    if ( ! seo_ie_batch_move_file( $source, $processing ) ) {
        return new WP_Error( 'seo_batch_to_processing', 'No se pudo mover el archivo de pending a processing.' );
    }

    $entity = sanitize_key( $detected['entity'] );
    seo_ie_batch_store_status(
        [
            'enabled'       => true,
            'status'        => 'starting',
            'user_id'       => $user_id,
            'current_file'  => basename( $processing ),
            'entity'        => $entity,
            'message'       => sprintf( 'Preparando importacion de %s.', strtolower( seo_ie_batch_entity_label( $entity ) ) ),
            'history_event' => true,
        ]
    );

    if ( 'product' === $entity ) {
        $result = seo_ie_batch_start_product( $user_id, $processing, $detected );
    } elseif ( $direct ) {
        $result = seo_ie_batch_run_nonproduct( $user_id, $entity, $processing );
    } else {
        seo_ie_batch_store_status(
            [
                'enabled'      => true,
                'status'       => 'processing',
                'user_id'      => $user_id,
                'current_file' => basename( $processing ),
                'entity'       => $entity,
                'message'      => 'Archivo preparado y programado en la cola del servidor.',
            ]
        );
        $result = seo_ie_batch_schedule( $user_id, 1 ) ? 'scheduled' : new WP_Error( 'seo_batch_schedule', 'No se pudo programar el worker de la cola.' );
    }

    if ( is_wp_error( $result ) ) {
        $failed = seo_ie_batch_unique_path( $paths['failed'], basename( $processing ) );
        seo_ie_batch_move_file( $processing, $failed );
        seo_ie_batch_write_sidecar_log( $failed, [ 'errores' => 1, 'detalles' => [ $result->get_error_message() ] ], [ 'entity' => $entity ] );
        seo_ie_batch_store_status(
            [
                'enabled'       => false,
                'status'        => 'failed',
                'user_id'       => $user_id,
                'current_file'  => '',
                'last_file'     => basename( $failed ),
                'entity'        => $entity,
                'message'       => $result->get_error_message(),
                'history_event' => true,
            ]
        );
    }

    return $result;
}

/**
 * Worker general. Si hay un archivo processing no producto, lo ejecuta;
 * en otro caso toma el siguiente pending.
 *
 * @param int $user_id Usuario.
 * @return void
 */
function seo_ie_batch_queue_worker( $user_id ) {
    $user_id      = absint( $user_id );
    $previous_id  = get_current_user_id();
    $paths        = seo_ie_batch_paths();
    $status       = seo_ie_batch_status();
    $queue_lock   = seo_ie_batch_acquire_lock();

    if ( '' === $queue_lock ) {
        return;
    }

    if ( 0 < $user_id ) {
        wp_set_current_user( $user_id );
    }

    try {
        if ( empty( $status['enabled'] ) ) {
            return;
        }

        if ( ! empty( seo_ie_product_import_get_active( $user_id ) ) ) {
            return;
        }

        $processing = seo_ie_batch_files( $paths['processing'] );
        if ( ! empty( $processing ) ) {
            $detected = seo_ie_batch_detect_entity( $processing[0] );
            if ( is_wp_error( $detected ) ) {
                $failed = seo_ie_batch_unique_path( $paths['failed'], basename( $processing[0] ) );
                seo_ie_batch_move_file( $processing[0], $failed );
                seo_ie_batch_store_status(
                    [
                        'enabled'       => false,
                        'status'        => 'failed',
                        'user_id'       => $user_id,
                        'last_file'     => basename( $failed ),
                        'entity'        => 'unknown',
                        'message'       => $detected->get_error_message(),
                        'history_event' => true,
                    ]
                );
                return;
            }

            if ( 'product' === $detected['entity'] ) {
                seo_ie_batch_start_product( $user_id, $processing[0], $detected );
            } else {
                seo_ie_batch_run_nonproduct( $user_id, $detected['entity'], $processing[0] );
            }
            return;
        }

        seo_ie_batch_start_next( $user_id, true );
    } finally {
        seo_ie_batch_release_lock( $queue_lock );
        wp_set_current_user( $previous_id );
    }
}

/**
 * Finaliza un archivo de productos desde el motor V2.
 *
 * @param int   $user_id Usuario.
 * @param array $state Estado de producto.
 * @param array $log Log por referencia.
 * @return bool
 */
function seo_ie_batch_finalize_product_file( $user_id, &$state, &$log ) {
    if ( empty( $state['batch_queue_mode'] ) ) {
        return true;
    }

    $paths  = seo_ie_batch_paths();
    $source = (string) ( $state['queue_source_path'] ?? '' );
    $file   = sanitize_file_name( $state['queue_filename'] ?? basename( $source ) );
    $errors = absint( $log['errores'] ?? 0 );
    $target = (string) ( 0 < $errors ? ( $state['queue_failed_path'] ?? '' ) : ( $state['queue_imported_path'] ?? '' ) );

    if ( '' === $target ) {
        $target = seo_ie_batch_unique_path( 0 < $errors ? $paths['failed'] : $paths['imported'], $file );
    }

    if ( ! seo_ie_batch_move_file( $source, $target ) ) {
        seo_ie_add_log_detail( $log, 'No se pudo mover el CSV de productos fuera de processing.' );
        seo_ie_batch_store_status(
            [
                'enabled'       => false,
                'status'        => 'failed',
                'user_id'       => absint( $user_id ),
                'current_file'  => $file,
                'entity'        => 'product',
                'message'       => 'La importacion termino, pero no se pudo mover el archivo.',
                'history_event' => true,
            ]
        );
        return false;
    }

    seo_ie_batch_write_sidecar_log( $target, $log, [ 'entity' => 'product', 'result' => 0 < $errors ? 'failed' : 'imported' ] );

    if ( 0 < $errors ) {
        seo_ie_add_log_detail( $log, sprintf( 'El archivo se movio a failed porque termino con %d errores.', $errors ) );
        seo_ie_batch_store_status(
            [
                'enabled'       => false,
                'status'        => 'failed',
                'user_id'       => absint( $user_id ),
                'current_file'  => '',
                'last_file'     => basename( $target ),
                'entity'        => 'product',
                'message'       => sprintf( 'Productos: %d errores. La cola se ha detenido.', $errors ),
                'history_event' => true,
            ]
        );
        return false;
    }

    seo_ie_add_log_detail( $log, sprintf( 'Archivo movido a imported: %s.', basename( $target ) ) );
    $continue = ! empty( seo_ie_batch_status()['enabled'] );
    seo_ie_batch_store_status(
        [
            'enabled'       => $continue,
            'status'        => $continue ? 'waiting_next' : 'paused',
            'user_id'       => absint( $user_id ),
            'current_file'  => '',
            'last_file'     => basename( $target ),
            'entity'        => 'product',
            'message'       => $continue
                ? 'Productos importados; se programa el siguiente archivo.'
                : 'Productos importados; la cola permanece pausada.',
            'history_event' => true,
        ]
    );
    if ( $continue ) {
        seo_ie_batch_schedule( $user_id, 2 );
    }

    return true;
}

/**
 * Devuelve a pending un producto detenido manualmente.
 *
 * @param int   $user_id Usuario.
 * @param array $state Estado.
 * @param array $log Log.
 * @return void
 */
function seo_ie_batch_handle_product_stopped( $user_id, &$state, &$log ) {
    if ( empty( $state['batch_queue_mode'] ) ) {
        return;
    }

    $source = (string) ( $state['queue_source_path'] ?? '' );
    $target = (string) ( $state['queue_pending_path'] ?? '' );
    $file   = sanitize_file_name( $state['queue_filename'] ?? basename( $source ) );

    if ( '' !== $source && '' !== $target && is_file( $source ) ) {
        seo_ie_batch_move_file( $source, $target );
    }

    seo_ie_add_log_detail( $log, 'La cola se pauso y el CSV en curso volvio a pending.' );
    seo_ie_batch_store_status(
        [
            'enabled'       => false,
            'status'        => 'paused',
            'user_id'       => absint( $user_id ),
            'current_file'  => '',
            'last_file'     => $file,
            'entity'        => 'product',
            'message'       => 'Importacion detenida; el archivo volvio a pending.',
            'history_event' => true,
        ]
    );
}

/**
 * Instantanea para la interfaz.
 *
 * @return array
 */
function seo_ie_batch_snapshot() {
    $errors = seo_ie_batch_prepare_directories();
    $paths  = seo_ie_batch_paths();
    $data   = [
        'errors' => $errors,
        'paths'  => $paths,
        'status' => seo_ie_batch_status(),
        'files'  => [],
    ];

    foreach ( [ 'pending', 'processing', 'imported', 'failed' ] as $bucket ) {
        $data['files'][ $bucket ] = [];
        foreach ( seo_ie_batch_files( $paths[ $bucket ] ) as $path ) {
            $detected = seo_ie_batch_detect_entity( $path );
            $data['files'][ $bucket ][] = [
                'path'     => $path,
                'name'     => basename( $path ),
                'size'     => filesize( $path ),
                'modified' => filemtime( $path ),
                'entity'   => is_wp_error( $detected ) ? 'unknown' : $detected['entity'],
                'error'    => is_wp_error( $detected ) ? $detected->get_error_message() : '',
            ];
        }
    }

    $active = seo_ie_product_import_get_active( get_current_user_id() );
    $data['active_product'] = is_array( $active ) ? $active : [];

    return $data;
}

/**
 * Resuelve un CSV administrado sin permitir salir de las carpetas de la cola.
 *
 * @param string $bucket Carpeta logica.
 * @param string $filename Nombre base.
 * @param bool   $allow_processing Permite consultar processing.
 * @return string|WP_Error
 */
function seo_ie_batch_resolve_managed_file( $bucket, $filename, $allow_processing = false ) {
    $bucket   = sanitize_key( $bucket );
    $filename = sanitize_file_name( wp_unslash( (string) $filename ) );
    $allowed  = [ 'pending', 'imported', 'failed' ];

    if ( $allow_processing ) {
        $allowed[] = 'processing';
    }

    if ( ! in_array( $bucket, $allowed, true ) || '' === $filename || basename( $filename ) !== $filename ) {
        return new WP_Error( 'seo_batch_managed_file', 'Archivo o carpeta no validos.' );
    }

    if ( 'csv' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
        return new WP_Error( 'seo_batch_managed_extension', 'Solo se administran archivos CSV.' );
    }

    $paths = seo_ie_batch_paths();
    $base  = realpath( $paths[ $bucket ] ?? '' );
    $path  = realpath( trailingslashit( $paths[ $bucket ] ?? '' ) . $filename );

    if ( false === $base || false === $path || ! is_file( $path ) ) {
        return new WP_Error( 'seo_batch_managed_missing', 'El archivo ya no existe.' );
    }

    $base_normalized = trailingslashit( wp_normalize_path( $base ) );
    $path_normalized = wp_normalize_path( $path );

    if ( 0 !== strpos( $path_normalized, $base_normalized ) ) {
        return new WP_Error( 'seo_batch_managed_escape', 'La ruta solicitada no pertenece a la cola.' );
    }

    return $path;
}

/**
 * Construye una URL segura para descargar un CSV o su log.
 *
 * @param string $operation download o download_log.
 * @param string $bucket Carpeta.
 * @param string $filename Archivo.
 * @return string
 */
function seo_ie_batch_file_action_url( $operation, $bucket, $filename ) {
    $operation = sanitize_key( $operation );
    $bucket    = sanitize_key( $bucket );
    $filename  = sanitize_file_name( $filename );
    $action    = 'seo_ie_batch_file_' . $operation . '_' . $bucket . '_' . $filename;

    return wp_nonce_url(
        add_query_arg(
            [
                'page'                     => 'seo-import-export',
                'seo_ie_tab'               => 'import-batch',
                'seo_ie_batch_file_action' => $operation,
                'seo_ie_batch_bucket'      => $bucket,
                'seo_ie_batch_file'        => $filename,
            ],
            admin_url( 'admin.php' )
        ),
        $action,
        'seo_ie_batch_file_nonce'
    );
}

/**
 * Descarga un archivo administrado despues de validar permisos y nonce.
 *
 * @return void
 */
function seo_ie_batch_handle_file_download() {
    $operation = sanitize_key( $_GET['seo_ie_batch_file_action'] ?? '' );

    if ( ! in_array( $operation, [ 'download', 'download_log' ], true ) ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para descargar archivos de la cola.', 'seo-system' ) );
    }

    $bucket   = sanitize_key( $_GET['seo_ie_batch_bucket'] ?? '' );
    $filename = sanitize_file_name( wp_unslash( $_GET['seo_ie_batch_file'] ?? '' ) );
    $action   = 'seo_ie_batch_file_' . $operation . '_' . $bucket . '_' . $filename;

    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['seo_ie_batch_file_nonce'] ?? '' ) ), $action ) ) {
        wp_die( esc_html__( 'El enlace de descarga ha caducado.', 'seo-system' ) );
    }

    $path = seo_ie_batch_resolve_managed_file( $bucket, $filename, true );
    if ( is_wp_error( $path ) ) {
        wp_die( esc_html( $path->get_error_message() ) );
    }

    if ( 'download_log' === $operation ) {
        $path .= '.log.json';
        if ( ! is_file( $path ) ) {
            wp_die( esc_html__( 'Este archivo no tiene un log disponible.', 'seo-system' ) );
        }
    }

    while ( ob_get_level() ) {
        ob_end_clean();
    }

    nocache_headers();
    header( 'Content-Type: ' . ( 'download_log' === $operation ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $path ) ) . '"' );
    header( 'Content-Length: ' . (string) filesize( $path ) );
    readfile( $path );
    exit;
}

/**
 * Lee una vista previa limitada para no cargar CSV grandes en memoria.
 *
 * @param string $path CSV.
 * @param int    $max_rows Filas de datos.
 * @return array|WP_Error
 */
function seo_ie_batch_preview_file( $path, $max_rows = 10 ) {
    if ( ! is_file( $path ) ) {
        return new WP_Error( 'seo_batch_preview_missing', 'El archivo ya no existe.' );
    }

    $handle = fopen( $path, 'r' );
    if ( false === $handle ) {
        return new WP_Error( 'seo_batch_preview_open', 'No se pudo abrir el archivo.' );
    }

    $header = seo_ie_read_csv_row( $handle );
    if ( false === $header ) {
        fclose( $handle );
        return new WP_Error( 'seo_batch_preview_empty', 'El archivo esta vacio.' );
    }

    $rows = [];
    while ( count( $rows ) < max( 1, absint( $max_rows ) ) && false !== ( $row = seo_ie_read_csv_row( $handle ) ) ) {
        $has_content = false;
        foreach ( (array) $row as $cell ) {
            if ( '' !== trim( (string) $cell ) ) {
                $has_content = true;
                break;
            }
        }

        if ( ! $has_content ) {
            continue;
        }

        $rows[] = array_map(
            static function ( $value ) {
                $value  = seo_ie_csv_to_utf8( (string) $value );
                $length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

                if ( 500 < $length ) {
                    return ( function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 500 ) : substr( $value, 0, 500 ) ) . '...';
                }

                return $value;
            },
            (array) $row
        );
    }

    fclose( $handle );

    $log = [];
    if ( is_file( $path . '.log.json' ) ) {
        $decoded = json_decode( (string) file_get_contents( $path . '.log.json' ), true );
        if ( is_array( $decoded ) ) {
            $log = $decoded;
        }
    }

    return [
        'header' => array_map( 'strval', (array) $header ),
        'rows'   => $rows,
        'log'    => $log,
    ];
}

/**
 * Elimina un CSV administrado y su log lateral.
 *
 * @param string $bucket Carpeta.
 * @param string $filename Archivo.
 * @return true|WP_Error
 */
function seo_ie_batch_delete_managed_file( $bucket, $filename ) {
    if ( 'processing' === sanitize_key( $bucket ) ) {
        return new WP_Error( 'seo_batch_delete_processing', 'No se puede borrar un archivo que esta en processing.' );
    }

    $path = seo_ie_batch_resolve_managed_file( $bucket, $filename, false );
    if ( is_wp_error( $path ) ) {
        return $path;
    }

    $lock = seo_ie_batch_acquire_lock();
    if ( '' === $lock ) {
        return new WP_Error( 'seo_batch_delete_busy', 'La cola esta cambiando de archivo. Repite la operacion en unos segundos.' );
    }

    try {
        if ( ! @unlink( $path ) ) {
            return new WP_Error( 'seo_batch_delete_failed', 'No se pudo borrar el CSV.' );
        }

        if ( is_file( $path . '.log.json' ) ) {
            @unlink( $path . '.log.json' );
        }
    } finally {
        seo_ie_batch_release_lock( $lock );
    }

    return true;
}

/**
 * Acciones de la pestaña: subir, iniciar, pausar y reintentar fallidos.
 *
 * @return void
 */
/**
 * Traduce un codigo UPLOAD_ERR_* a un mensaje util para el administrador.
 *
 * @param int $error Codigo PHP de subida.
 * @return string
 */
function seo_ie_batch_upload_error_message( $error ) {
    $error = absint( $error );
    $messages = [
        UPLOAD_ERR_INI_SIZE   => 'supera upload_max_filesize del servidor',
        UPLOAD_ERR_FORM_SIZE  => 'supera el limite del formulario',
        UPLOAD_ERR_PARTIAL    => 'la subida llego incompleta',
        UPLOAD_ERR_NO_FILE    => 'PHP no recibio el archivo',
        UPLOAD_ERR_NO_TMP_DIR => 'falta la carpeta temporal de PHP',
        UPLOAD_ERR_CANT_WRITE => 'el servidor no pudo escribir el archivo temporal',
        UPLOAD_ERR_EXTENSION  => 'una extension de PHP detuvo la subida',
    ];

    return $messages[ $error ] ?? sprintf( 'error de subida PHP %d', $error );
}

function seo_ie_batch_admin_action() {
    seo_ie_batch_handle_file_download();

    $action = sanitize_key( $_POST['seo_ie_batch_action'] ?? '' );
    if ( '' === $action ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para gestionar la importacion por lotes.', 'seo-system' ) );
    }

    check_admin_referer( 'seo_ie_batch_admin', 'seo_ie_batch_nonce' );
    $paths  = seo_ie_batch_paths();
    $errors = seo_ie_batch_prepare_directories();
    $notice = [];

    if ( ! empty( $errors ) ) {
        $notice['seo_ie_batch_error'] = implode( ' ', $errors );
    } elseif ( 'upload' === $action || 'upload_start' === $action ) {
        $files    = $_FILES['seo_ie_batch_files'] ?? [];
        $names    = (array) ( $files['name'] ?? [] );
        $tmp      = (array) ( $files['tmp_name'] ?? [] );
        $statuses = (array) ( $files['error'] ?? [] );
        $added    = 0;
        $rejected = [];

        foreach ( $names as $index => $name ) {
            $name     = sanitize_file_name( wp_unslash( $name ) );
            $tmp_name = (string) ( $tmp[ $index ] ?? '' );
            $error    = absint( $statuses[ $index ] ?? UPLOAD_ERR_NO_FILE );

            if ( UPLOAD_ERR_OK !== $error ) {
                $rejected[] = sprintf(
                    '%s (%s)',
                    $name ?: sprintf( 'archivo %d', $index + 1 ),
                    seo_ie_batch_upload_error_message( $error )
                );
                continue;
            }

            if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
                $rejected[] = sprintf( '%s (archivo temporal no valido)', $name ?: sprintf( 'archivo %d', $index + 1 ) );
                continue;
            }

            if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
                $rejected[] = sprintf( '%s (extension distinta de .csv)', $name );
                continue;
            }

            $target = seo_ie_batch_unique_path( $paths['pending'], $name );
            if ( ! move_uploaded_file( $tmp_name, $target ) ) {
                $rejected[] = $name;
                continue;
            }

            $detected = seo_ie_batch_detect_entity( $target );
            if ( is_wp_error( $detected ) ) {
                $failed = seo_ie_batch_unique_path( $paths['failed'], basename( $target ) );
                seo_ie_batch_move_file( $target, $failed );
                seo_ie_batch_write_sidecar_log( $failed, [ 'errores' => 1, 'detalles' => [ $detected->get_error_message() ] ] );
                $rejected[] = $name;
                continue;
            }

            $added++;
        }

        $notice['seo_ie_batch_message'] = sprintf( 'Se anadieron %d archivos a pending.', $added );
        if ( ! empty( $rejected ) ) {
            $notice['seo_ie_batch_error'] = 'No se aceptaron: ' . implode( ', ', $rejected ) . '.';
        }

        if ( 'upload_start' === $action && 0 < $added ) {
            $status = seo_ie_batch_status();
            $status['enabled'] = true;
            seo_ie_batch_store_status( $status );
            $lock = seo_ie_batch_acquire_lock();
            if ( '' === $lock ) {
                $notice['seo_ie_batch_error'] = 'La cola ya esta iniciando otro archivo.';
            } else {
                try {
                    $result = seo_ie_batch_start_next( get_current_user_id(), true );
                    if ( is_wp_error( $result ) ) {
                        $notice['seo_ie_batch_error'] = $result->get_error_message();
                    }
                } finally {
                    seo_ie_batch_release_lock( $lock );
                }
            }
        }
    } elseif ( 'start' === $action ) {
        $status = seo_ie_batch_status();
        $status['enabled'] = true;
        seo_ie_batch_store_status( $status );
        $lock = seo_ie_batch_acquire_lock();
        if ( '' === $lock ) {
            $notice['seo_ie_batch_error'] = 'La cola ya esta iniciando otro archivo.';
        } else {
            try {
                $result = seo_ie_batch_start_next( get_current_user_id(), true );
                if ( is_wp_error( $result ) ) {
                    $notice['seo_ie_batch_error'] = $result->get_error_message();
                } else {
                    $notice['seo_ie_batch_message'] = 'La cola se ha iniciado o continuado.';
                }
            } finally {
                seo_ie_batch_release_lock( $lock );
            }
        }
    } elseif ( 'step_product' === $action ) {
        $status  = seo_ie_batch_status();
        $user_id = absint( $status['user_id'] ?? get_current_user_id() );
        $active  = seo_ie_product_import_get_active( $user_id );
        $token   = sanitize_key( $active['token'] ?? '' );

        if ( '' === $token ) {
            $notice['seo_ie_batch_error'] = 'No existe una importacion de productos activa.';
        } elseif ( get_transient( seo_ie_product_import_lock_key( $user_id, $token ) ) ) {
            $notice['seo_ie_batch_error'] = 'El servidor ya esta procesando un lote.';
        } else {
            $state = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );
            if ( ! is_array( $state ) || empty( $state['batch_queue_mode'] ) ) {
                $notice['seo_ie_batch_error'] = 'La importacion activa no pertenece a la cola por lotes.';
            } else {
                seo_ie_product_import_unschedule( $user_id, $token );
                seo_ie_product_import_background_worker( $user_id, $token );
                $notice['seo_ie_batch_message'] = 'Se proceso manualmente el siguiente bloque adaptativo de productos.';
            }
        }
    } elseif ( 'pause' === $action ) {
        $status = seo_ie_batch_status();
        $status['enabled'] = false;
        $status['status']  = 'paused';
        $status['message'] = 'La cola esta pausada. El archivo de productos ya iniciado terminara su lote actual; no se iniciara otro archivo.';
        seo_ie_batch_store_status( $status );
        seo_ie_batch_unschedule( get_current_user_id() );
        $notice['seo_ie_batch_message'] = 'Cola pausada.';
    } elseif ( 'retry_failed' === $action ) {
        $filename = sanitize_file_name( wp_unslash( $_POST['seo_ie_batch_failed_file'] ?? '' ) );
        $source   = seo_ie_batch_resolve_managed_file( 'failed', $filename, false );

        if ( is_wp_error( $source ) ) {
            $notice['seo_ie_batch_error'] = $source->get_error_message();
        } else {
            $lock = seo_ie_batch_acquire_lock();
            if ( '' === $lock ) {
                $notice['seo_ie_batch_error'] = 'La cola esta cambiando de archivo. Repite la operacion.';
            } else {
                try {
                    $target = seo_ie_batch_unique_path( $paths['pending'], basename( $source ) );
                    if ( seo_ie_batch_move_file( $source, $target ) ) {
                        @unlink( $source . '.log.json' );
                        $notice['seo_ie_batch_message'] = 'El archivo se devolvio a pending.';
                    } else {
                        $notice['seo_ie_batch_error'] = 'No se pudo devolver el archivo a pending.';
                    }
                } finally {
                    seo_ie_batch_release_lock( $lock );
                }
            }
        }
    } elseif ( 'delete_file' === $action ) {
        $bucket   = sanitize_key( $_POST['seo_ie_batch_bucket'] ?? '' );
        $filename = sanitize_file_name( wp_unslash( $_POST['seo_ie_batch_file'] ?? '' ) );
        $deleted  = seo_ie_batch_delete_managed_file( $bucket, $filename );

        if ( is_wp_error( $deleted ) ) {
            $notice['seo_ie_batch_error'] = $deleted->get_error_message();
        } else {
            $notice['seo_ie_batch_message'] = sprintf( 'Se borro %s de %s.', $filename, $bucket );
        }
    }

    $args = array_merge(
        [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'import-batch' ],
        array_map( 'rawurlencode', $notice )
    );
    wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
    exit;
}

/**
 * Continua un unico bloque de productos cuando la cola fue iniciada
 * explicitamente y el servidor no ejecuto la accion programada.
 *
 * La consulta de estado sigue siendo inocua cuando la cola esta idle o
 * pausada. Solo se permite la ayuda de navegador si:
 * - la cola tiene enabled=true por una accion previa del administrador;
 * - el archivo activo pertenece a la cola multientidad;
 * - han pasado al menos ocho segundos sin actividad;
 * - no existe un bloqueo de producto ni de cola.
 *
 * @return array{ran:bool,message:string}
 */
function seo_ie_batch_browser_continue_product() {
    $status = seo_ie_batch_status();

    if (
        empty( $status['enabled'] )
        || 'processing' !== sanitize_key( $status['status'] ?? '' )
        || 'product' !== sanitize_key( $status['entity'] ?? '' )
    ) {
        return [ 'ran' => false, 'message' => 'La cola no requiere continuacion.' ];
    }

    $user_id = absint( $status['user_id'] ?? 0 );
    if ( 0 === $user_id || ! user_can( $user_id, 'manage_options' ) ) {
        return [ 'ran' => false, 'message' => 'Usuario de la cola no valido.' ];
    }

    $active = seo_ie_product_import_get_active( $user_id );
    $token  = sanitize_key( $active['token'] ?? '' );
    if ( '' === $token ) {
        return [ 'ran' => false, 'message' => 'No hay un producto activo recuperable.' ];
    }

    $state = get_transient( seo_ie_product_import_state_key( $user_id, $token ) );
    if (
        ! is_array( $state )
        || empty( $state['batch_queue_mode'] )
        || in_array( sanitize_key( $state['status'] ?? '' ), [ 'stopping', 'stopped', 'completed', 'failed' ], true )
        || seo_ie_product_import_is_cancel_requested( $user_id, $token )
    ) {
        return [ 'ran' => false, 'message' => 'El trabajo no admite continuacion asistida.' ];
    }

    $last_activity = max(
        absint( $state['updated_at'] ?? 0 ),
        absint( $state['last_batch_finished_at'] ?? 0 ),
        absint( $state['last_worker_started_at'] ?? 0 )
    );
    $adaptive_delay = absint( $state['adaptive_next_delay'] ?? 0 );
    $minimum_idle   = max( 8, $adaptive_delay );

    if ( $minimum_idle > time() - $last_activity ) {
        return [
            'ran'     => false,
            'message' => 0 < $adaptive_delay
                ? sprintf( 'El regulador mantiene una pausa preventiva de %d segundos.', $adaptive_delay )
                : 'El ultimo lote es reciente.',
        ];
    }

    if ( get_transient( seo_ie_product_import_lock_key( $user_id, $token ) ) ) {
        return [ 'ran' => false, 'message' => 'Hay un worker activo.' ];
    }

    $queue_lock = seo_ie_batch_acquire_lock();
    if ( '' === $queue_lock ) {
        return [ 'ran' => false, 'message' => 'La cola esta ocupada.' ];
    }

    try {
        // Retira acciones antiguas para que no se acumulen workers pendientes.
        seo_ie_product_import_unschedule( $user_id, $token );
        seo_ie_product_import_add_transaction(
            $state,
            'batch_browser_continue',
            'La pestana de lotes ejecuta el siguiente bloque adaptativo autorizado.',
            [ 'idle_seconds' => max( 0, time() - $last_activity ) ]
        );
        seo_ie_product_import_store_state( $user_id, $token, $state );
        seo_ie_product_import_background_worker( $user_id, $token );
    } finally {
        seo_ie_batch_release_lock( $queue_lock );
    }

    return [ 'ran' => true, 'message' => 'Se ejecuto un bloque adaptativo de productos.' ];
}

/**
 * Pulso AJAX de recuperacion cuando la pestana permanece abierta.
 *
 * @return void
 */
function seo_ie_batch_ajax_tick() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
    }

    check_ajax_referer( 'seo_ie_batch_tick', 'nonce' );

    $continuation = seo_ie_batch_browser_continue_product();
    $snapshot     = seo_ie_batch_snapshot();
    $snapshot['browser_continuation'] = $continuation;

    wp_send_json_success( $snapshot );
}

/**
 * Renderiza la pestana independiente.
 *
 * @return void
 */
function seo_ie_batch_render_page() {
    $snapshot = seo_ie_batch_snapshot();
    $status   = (array) ( $snapshot['status'] ?? [] );
    $files    = (array) ( $snapshot['files'] ?? [] );
    $running  = seo_ie_batch_is_running() || ! empty( $status['enabled'] );

    $preview_bucket = sanitize_key( $_GET['seo_ie_batch_preview_bucket'] ?? '' );
    $preview_file   = sanitize_file_name( wp_unslash( $_GET['seo_ie_batch_preview_file'] ?? '' ) );
    $preview_path   = '';
    $preview        = null;

    if ( '' !== $preview_bucket && '' !== $preview_file ) {
        $preview_path = seo_ie_batch_resolve_managed_file( $preview_bucket, $preview_file, true );
        $preview = is_wp_error( $preview_path ) ? $preview_path : seo_ie_batch_preview_file( $preview_path, 10 );
    }
    ?>
    <div style="max-width:1300px;">
        <h2>Importacion por lotes <small style="font-size:13px;font-weight:400;color:#646970;">Build 034</small></h2>
        <p>
            Cola secuencial para CSV de WordPress y SEO System. El destino se detecta por la cabecera:
            productos, categorias, paginas, entradas (posts), FAQs o redirects. El catalogo bruto de proveedores se importa desde su pestana independiente.
        </p>

        <?php if ( ! empty( $_GET['seo_ie_batch_message'] ) ) : ?>
            <div class="notice notice-success inline"><p><?php echo esc_html( rawurldecode( wp_unslash( $_GET['seo_ie_batch_message'] ) ) ); ?></p></div>
        <?php endif; ?>
        <?php if ( ! empty( $_GET['seo_ie_batch_error'] ) ) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html( rawurldecode( wp_unslash( $_GET['seo_ie_batch_error'] ) ) ); ?></p></div>
        <?php endif; ?>
        <?php if ( ! empty( $snapshot['errors'] ) ) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html( implode( ' ', $snapshot['errors'] ) ); ?></p></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px;">
            <div class="card" style="max-width:none;padding:20px;">
                <h3>1. Subir trabajos</h3>
                <p>Selecciona uno o varios CSV. Se guardan en <code>includes/import-export/migrations/pending</code>.</p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'seo_ie_batch_admin', 'seo_ie_batch_nonce' ); ?>
                    <input type="file" name="seo_ie_batch_files[]" accept=".csv,text/csv" multiple required>
                    <p>
                        <button type="submit" name="seo_ie_batch_action" value="upload" class="button">Anadir a pendientes</button>
                        <button type="submit" name="seo_ie_batch_action" value="upload_start" class="button button-primary">Anadir e iniciar</button>
                    </p>
                </form>
                <?php $max_batch_uploads = max( 1, intval( ini_get( 'max_file_uploads' ) ?: 20 ) ); ?>
                <p class="description">
                    Los nombres controlan el orden: <code>001_...</code>, <code>002_...</code>, etc.
                    Limite PHP por seleccion: <strong><?php echo esc_html( $max_batch_uploads ); ?></strong> archivos.
                    Si necesitas mas, subelos en varias selecciones; los ya pendientes se conservan.
                </p>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const input = document.querySelector('input[name="seo_ie_batch_files[]"]');
                    if (!input) return;
                    input.addEventListener('change', function () {
                        const maxFiles = <?php echo (int) $max_batch_uploads; ?>;
                        if (this.files && this.files.length > maxFiles) {
                            window.alert('El servidor admite como maximo ' + maxFiles + ' archivos por seleccion. Divide la subida en varios grupos.');
                            this.value = '';
                        }
                    });
                });
                </script>
            </div>

            <div class="card" style="max-width:none;padding:20px;">
                <h3>2. Estado y controles</h3>
                <table class="widefat striped">
                    <tbody>
                        <tr><th style="width:170px;">Estado</th><td><?php echo esc_html( $status['status'] ?? 'idle' ); ?></td></tr>
                        <tr><th>Archivo actual</th><td><?php echo esc_html( $status['current_file'] ?? '---' ); ?></td></tr>
                        <tr><th>Tipo</th><td><?php echo esc_html( seo_ie_batch_entity_label( $status['entity'] ?? '' ) ); ?></td></tr>
                        <tr><th>Mensaje</th><td><?php echo esc_html( $status['message'] ?? 'Cola preparada.' ); ?></td></tr>
                        <tr><th>Actualizacion</th><td><?php echo esc_html( $status['updated_at'] ?? '---' ); ?></td></tr>
                        <?php $active_product = (array) ( $snapshot['active_product'] ?? [] ); ?>
                        <?php if ( ! empty( $active_product ) ) : ?>
                            <tr><th>Progreso productos</th><td><?php echo esc_html( absint( $active_product['progreso'] ?? 0 ) ); ?>% - <?php echo esc_html( absint( $active_product['log']['procesados'] ?? 0 ) ); ?> procesados - <?php echo esc_html( absint( $active_product['log']['errores'] ?? 0 ) ); ?> errores</td></tr>
                            <?php $product_diag = (array) ( $active_product['diagnostics'] ?? [] ); ?>
                            <tr><th>Ritmo adaptativo</th><td>Ultimo lote: <?php echo esc_html( absint( $product_diag['last_batch_rows'] ?? 0 ) ); ?> filas en <?php echo esc_html( (string) ( $product_diag['last_batch_duration'] ?? 0 ) ); ?> s. Siguiente objetivo: <strong><?php echo esc_html( absint( $product_diag['adaptive_next_batch_size'] ?? 10 ) ); ?></strong> filas<?php if ( ! empty( $product_diag['adaptive_next_delay'] ) ) : ?>, pausa <?php echo esc_html( absint( $product_diag['adaptive_next_delay'] ) ); ?> s<?php endif; ?>. Presion: <?php echo esc_html( $product_diag['adaptive_pressure'] ?? 'baja' ); ?>.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <form method="post" style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                    <?php wp_nonce_field( 'seo_ie_batch_admin', 'seo_ie_batch_nonce' ); ?>
                    <button type="submit" name="seo_ie_batch_action" value="start" class="button button-primary">Iniciar / continuar</button>
                    <button type="submit" name="seo_ie_batch_action" value="pause" class="button">Pausar despues del archivo actual</button>
                    <?php if ( ! empty( $active_product ) ) : ?>
                        <button type="submit" name="seo_ie_batch_action" value="step_product" class="button">Procesar siguiente bloque adaptativo</button>
                    <?php endif; ?>
                </form>
                <p class="description">Sin simulacion. Respeta el estado del CSV. Los errores detienen la cola y envian el archivo a <code>failed</code>.</p>
            </div>
        </div>

        <?php if ( null !== $preview ) : ?>
            <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
                <h3>Vista previa: <code><?php echo esc_html( $preview_file ); ?></code></h3>
                <p>
                    Carpeta: <strong><?php echo esc_html( $preview_bucket ); ?></strong>.
                    Se muestran la cabecera y hasta 10 filas; el archivo no se modifica.
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'seo-import-export', 'seo_ie_tab' => 'import-batch' ], admin_url( 'admin.php' ) ) ); ?>">Cerrar vista previa</a>
                </p>

                <?php if ( is_wp_error( $preview ) ) : ?>
                    <div class="notice notice-error inline"><p><?php echo esc_html( $preview->get_error_message() ); ?></p></div>
                <?php else : ?>
                    <?php $preview_detected = seo_ie_batch_detect_entity( $preview_path ); ?>
                    <p>
                        Destino detectado: <strong><?php echo esc_html( is_wp_error( $preview_detected ) ? 'Desconocido' : seo_ie_batch_entity_label( $preview_detected['entity'] ) ); ?></strong>.
                        Tamano: <strong><?php echo esc_html( size_format( filesize( $preview_path ) ) ); ?></strong>.
                    </p>
                    <?php if ( ! empty( $preview['log']['log'] ) ) : ?>
                        <?php $preview_log = (array) $preview['log']['log']; ?>
                        <p><strong>Ultimo resultado:</strong>
                            <?php echo esc_html( absint( $preview_log['procesados'] ?? 0 ) ); ?> procesados,
                            <?php echo esc_html( absint( $preview_log['correctos'] ?? 0 ) ); ?> correctos,
                            <?php echo esc_html( absint( $preview_log['errores'] ?? 0 ) ); ?> errores.
                        </p>
                    <?php endif; ?>
                    <div style="max-width:100%;overflow:auto;border:1px solid #dcdcde;">
                        <table class="widefat striped" style="min-width:max-content;border:0;">
                            <thead><tr>
                                <?php foreach ( (array) $preview['header'] as $column ) : ?>
                                    <th style="white-space:nowrap;max-width:260px;"><?php echo esc_html( $column ); ?></th>
                                <?php endforeach; ?>
                            </tr></thead>
                            <tbody>
                                <?php if ( empty( $preview['rows'] ) ) : ?>
                                    <tr><td colspan="<?php echo esc_attr( max( 1, count( (array) $preview['header'] ) ) ); ?>">No hay filas de datos.</td></tr>
                                <?php else : ?>
                                    <?php foreach ( $preview['rows'] as $row ) : ?>
                                        <tr>
                                            <?php foreach ( array_keys( (array) $preview['header'] ) as $index ) : ?>
                                                <td style="max-width:320px;white-space:normal;overflow-wrap:anywhere;vertical-align:top;"><?php echo esc_html( $row[ $index ] ?? '' ); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
            <h3>3. Gestor de archivos</h3>
            <p>Los CSV pueden revisarse y administrarse desde WordPress. Los archivos de <code>processing</code> son de solo lectura.</p>
            <?php foreach ( [ 'pending' => 'Pendientes', 'processing' => 'En proceso', 'imported' => 'Importados', 'failed' => 'Fallidos' ] as $bucket => $label ) : ?>
                <?php $bucket_files = (array) ( $files[ $bucket ] ?? [] ); ?>
                <details style="margin:10px 0;" <?php echo in_array( $bucket, [ 'pending', 'processing', 'failed' ], true ) ? 'open' : ''; ?>>
                    <summary><strong><?php echo esc_html( $label ); ?> (<?php echo esc_html( count( $bucket_files ) ); ?>)</strong></summary>
                    <?php if ( empty( $bucket_files ) ) : ?>
                        <p class="description">No hay archivos.</p>
                    <?php else : ?>
                        <div style="overflow:auto;margin-top:8px;">
                            <table class="widefat striped">
                                <thead><tr><th>Archivo</th><th>Destino</th><th>Tamano</th><th>Modificado</th><th>Observacion</th><th>Acciones</th></tr></thead>
                                <tbody>
                                    <?php $visible_files = in_array( $bucket, [ 'pending', 'processing' ], true ) ? array_slice( $bucket_files, 0, 100 ) : array_slice( $bucket_files, -100 ); ?>
                                    <?php foreach ( $visible_files as $item ) : ?>
                                        <?php
                                        $preview_url = add_query_arg(
                                            [
                                                'page'                        => 'seo-import-export',
                                                'seo_ie_tab'                  => 'import-batch',
                                                'seo_ie_batch_preview_bucket' => $bucket,
                                                'seo_ie_batch_preview_file'   => $item['name'],
                                            ],
                                            admin_url( 'admin.php' )
                                        );
                                        $has_log = is_file( $item['path'] . '.log.json' );
                                        ?>
                                        <tr>
                                            <td><code><?php echo esc_html( $item['name'] ); ?></code></td>
                                            <td><?php echo esc_html( seo_ie_batch_entity_label( $item['entity'] ) ); ?></td>
                                            <td><?php echo esc_html( size_format( $item['size'] ) ); ?></td>
                                            <td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $item['modified'] ) ); ?></td>
                                            <td><?php echo esc_html( $item['error'] ?: '---' ); ?></td>
                                            <td style="min-width:260px;">
                                                <a class="button button-small" href="<?php echo esc_url( $preview_url ); ?>">Ver</a>
                                                <?php if ( 'processing' !== $bucket ) : ?>
                                                    <a class="button button-small" href="<?php echo esc_url( seo_ie_batch_file_action_url( 'download', $bucket, $item['name'] ) ); ?>">Descargar</a>
                                                <?php endif; ?>
                                                <?php if ( $has_log ) : ?>
                                                    <a class="button button-small" href="<?php echo esc_url( seo_ie_batch_file_action_url( 'download_log', $bucket, $item['name'] ) ); ?>">Log</a>
                                                <?php endif; ?>
                                                <?php if ( 'failed' === $bucket ) : ?>
                                                    <form method="post" style="display:inline;">
                                                        <?php wp_nonce_field( 'seo_ie_batch_admin', 'seo_ie_batch_nonce' ); ?>
                                                        <input type="hidden" name="seo_ie_batch_failed_file" value="<?php echo esc_attr( $item['name'] ); ?>">
                                                        <button type="submit" name="seo_ie_batch_action" value="retry_failed" class="button button-small">A pending</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ( 'processing' !== $bucket ) : ?>
                                                    <form method="post" style="display:inline;" onsubmit="return window.confirm('Borrar definitivamente este CSV y su log?');">
                                                        <?php wp_nonce_field( 'seo_ie_batch_admin', 'seo_ie_batch_nonce' ); ?>
                                                        <input type="hidden" name="seo_ie_batch_bucket" value="<?php echo esc_attr( $bucket ); ?>">
                                                        <input type="hidden" name="seo_ie_batch_file" value="<?php echo esc_attr( $item['name'] ); ?>">
                                                        <button type="submit" name="seo_ie_batch_action" value="delete_file" class="button button-small" style="color:#b32d2e;border-color:#b32d2e;">Borrar</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </div>

        <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
            <h3>Reglas automaticas</h3>
            <ul style="list-style:disc;margin-left:20px;">
                <li>Orden natural por nombre y un solo archivo en ejecucion.</li>
                <li><code>pending</code> -&gt; <code>processing</code> -&gt; <code>imported</code> o <code>failed</code>.</li>
                <li>Productos, categorias, paginas, entradas (posts), FAQs y redirects usan sus importadores de WordPress; no es la importacion del catalogo de proveedores.</li>
                <li>Las categorias reutilizan exactamente el importador individual: aceptan <code>imagen_destacada_id</code> e <code>imagen_destacada</code> (URL). Si hay URL, se usa como identidad portable de la imagen y puede descargarse a la biblioteca de Medios.</li>
                <li>Paginas/landings y entradas importan tambien su relacion comercial con <code>product_cat</code> mediante <code>seo_relations</code> cuando el CSV incluye las columnas <code>product_cat_relacion_*</code>.</li>
                <li>Los productos reutilizan el motor adaptativo, Action Scheduler, WP-Cron y continuacion asistida desde esta pestana tras un inicio explicito.</li>
                <li>En productos, esta cola importa solo el producto base. Las columnas de etiquetas WooCommerce, semantica/ambito y atributos SEO/WooCommerce se ignoran aunque existan en el CSV; su enriquecimiento se realizara en un flujo independiente.</li>
                <li>Un error detiene la cola para impedir que archivos dependientes se importen sobre datos incompletos.</li>
                <li>Borrar un archivo de <code>imported</code> solo elimina el CSV y su log; no deshace los cambios ya escritos en WordPress.</li>
            </ul>
        </div>
    </div>

    <?php if ( $running ) : ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const endpoint = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            const nonce = <?php echo wp_json_encode( wp_create_nonce( 'seo_ie_batch_tick' ) ); ?>;
            window.setInterval(function () {
                const body = new URLSearchParams();
                body.set('action', 'seo_ie_batch_tick');
                body.set('nonce', nonce);
                fetch(endpoint, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString()})
                    .then(function (response) { return response.json(); })
                    .then(function () { window.location.reload(); })
                    .catch(function () {});
            }, 10000);
        });
        </script>
    <?php endif; ?>
    <?php
}
