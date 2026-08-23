<?php
/**
 * SEO System - Cola respetuosa de descubrimiento web de proveedores.
 *
 * Descubre catalogos publicos de proveedores a baja frecuencia y guarda los
 * datos normalizados en una zona temporal de descubrimiento. Periodicamente crea
 * un CSV estandar interno y lo entrega al mismo importador comun usado por CSV/XLS.
 * No crea ni actualiza productos WooCommerce directamente.
 *
 * Las recetas web se registran mediante el filtro seo_supplier_crawl_recipes.
 * Cada receta puede aportar semillas, sitemap y un parser especifico.
 *
 * @package SEOSystem
 * @subpackage SupplierImports
 * @version 0.3.2
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SEO_SUPPLIER_CRAWL_VERSION' ) ) {
    define( 'SEO_SUPPLIER_CRAWL_VERSION', '0.3.2' );
}

add_action( 'init', 'seo_supplier_crawl_resume_started_recipes', 30 );
add_action( 'admin_init', 'seo_supplier_crawl_handle_reset_all', 5 );
add_action( 'admin_init', 'seo_supplier_crawl_handle_start_request', 20 );
add_action( 'seo_supplier_crawl_tick', 'seo_supplier_crawl_worker', 10, 1 );

/**
 * Registro de recetas web instaladas.
 *
 * @return array<string,array<string,mixed>>
 */
function seo_supplier_crawl_recipes() {
    $registered = apply_filters( 'seo_supplier_crawl_recipes', [] );
    $recipes    = [];

    foreach ( (array) $registered as $recipe_id => $recipe ) {
        if ( ! is_array( $recipe ) ) {
            continue;
        }

        $id       = sanitize_key( $recipe['id'] ?? $recipe_id );
        $provider = sanitize_text_field( trim( (string) ( $recipe['provider'] ?? '' ) ) );
        $label    = sanitize_text_field( trim( (string) ( $recipe['label'] ?? $provider ) ) );

        if ( '' === $id || '' === $provider || '' === $label ) {
            continue;
        }

        $recipe['id']             = $id;
        $recipe['provider']       = $provider;
        $recipe['label']          = $label;
        $recipe['auto_enabled']   = ! empty( $recipe['auto_enabled'] );
        $recipe['min_delay']      = max( 15, absint( $recipe['min_delay'] ?? $recipe['crawl_delay'] ?? 45 ) );
        $recipe['initial_delay']  = max( $recipe['min_delay'], absint( $recipe['initial_delay'] ?? $recipe['crawl_delay'] ?? 75 ) );
        $recipe['max_delay']      = max( $recipe['initial_delay'], min( 6 * HOUR_IN_SECONDS, absint( $recipe['max_delay'] ?? 1800 ) ) );
        $recipe['crawl_delay']    = $recipe['initial_delay']; // Compatibilidad con recetas 0.1.x.
        $recipe['refresh_hours']  = max( 6, absint( $recipe['refresh_hours'] ?? 72 ) );
        $recipe['revisit_days']   = max( 1, absint( $recipe['revisit_days'] ?? 30 ) );
        $recipe['max_attempts']   = max( 1, min( 6, absint( $recipe['max_attempts'] ?? 4 ) ) );
        $recipe['csv_flush_rows'] = max( 10, absint( $recipe['csv_flush_rows'] ?? 25 ) );
        $recipe['csv_flush_interval'] = max( 300, absint( $recipe['csv_flush_interval'] ?? 900 ) );
        $recipe['allowed_hosts']  = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn( $host ) => strtolower( trim( (string) $host ) ),
                        (array) ( $recipe['allowed_hosts'] ?? [] )
                    )
                )
            )
        );

        $recipes[ $id ] = $recipe;
    }

    uasort(
        $recipes,
        static fn( $left, $right ) => strcasecmp( (string) $left['label'], (string) $right['label'] )
    );

    return $recipes;
}

/**
 * Obtiene una receta web concreta.
 *
 * @param string $recipe_id ID.
 * @return array|null
 */
function seo_supplier_crawl_recipe( $recipe_id ) {
    $recipes   = seo_supplier_crawl_recipes();
    $recipe_id = sanitize_key( $recipe_id );
    return isset( $recipes[ $recipe_id ] ) ? $recipes[ $recipe_id ] : null;
}


/**
 * Registro de fuentes web ejecutadas fuera de WordPress.
 *
 * Estas fuentes aparecen en el mismo bloque "Obtener catalogo desde la web",
 * pero delegan el scraping en un runner externo (por ejemplo GitHub Actions).
 * El runner debe devolver un CSV estandar al importador comun.
 *
 * @return array<string,array<string,mixed>>
 */
function seo_supplier_external_web_recipes() {
    $registered = apply_filters( 'seo_supplier_external_web_recipes', [] );
    $recipes    = [];

    foreach ( (array) $registered as $recipe_id => $recipe ) {
        if ( ! is_array( $recipe ) ) {
            continue;
        }

        $id               = sanitize_key( $recipe['id'] ?? $recipe_id );
        $provider         = sanitize_text_field( trim( (string) ( $recipe['provider'] ?? '' ) ) );
        $label            = sanitize_text_field( trim( (string) ( $recipe['label'] ?? $provider ) ) );
        $runner           = sanitize_key( (string) ( $recipe['runner'] ?? '' ) );
        $import_recipe_id = sanitize_key( (string) ( $recipe['import_recipe_id'] ?? '' ) );

        if ( '' === $id || '' === $provider || '' === $label || '' === $runner || '' === $import_recipe_id ) {
            continue;
        }

        if ( ! in_array( $runner, [ 'github' ], true ) ) {
            continue;
        }

        $recipe['id']               = $id;
        $recipe['provider']         = $provider;
        $recipe['label']            = $label;
        $recipe['runner']           = $runner;
        $recipe['import_recipe_id'] = $import_recipe_id;
        $recipes[ $id ]             = $recipe;
    }

    uasort(
        $recipes,
        static fn( $left, $right ) => strcasecmp( (string) $left['label'], (string) $right['label'] )
    );

    return $recipes;
}

/**
 * Obtiene una fuente web externa concreta.
 *
 * @param string $recipe_id ID externo.
 * @return array|null
 */
function seo_supplier_external_web_recipe( $recipe_id ) {
    $recipes   = seo_supplier_external_web_recipes();
    $recipe_id = sanitize_key( $recipe_id );
    return isset( $recipes[ $recipe_id ] ) ? $recipes[ $recipe_id ] : null;
}

/**
 * Nombre de la tabla de cola.
 *
 * @return string
 */
function seo_supplier_crawl_table() {
    global $wpdb;
    return $wpdb->prefix . 'seo_supplier_crawl_queue';
}


/**
 * Tabla temporal de productos descubiertos antes de generar el CSV comun.
 *
 * @return string
 */
function seo_supplier_crawl_records_table() {
    global $wpdb;
    return $wpdb->prefix . 'seo_supplier_crawl_records';
}

/**
 * Crea/actualiza las tablas de cola y de registros descubiertos.
 *
 * @return void
 */
function seo_supplier_crawl_install_table() {
    global $wpdb;

    $queue_table   = seo_supplier_crawl_table();
    $records_table = seo_supplier_crawl_records_table();
    $installed     = (string) get_option( 'seo_supplier_crawl_db_version', '' );
    $queue_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) );
    $records_exists= $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $records_table ) );

    if ( SEO_SUPPLIER_CRAWL_VERSION === $installed && $queue_exists === $queue_table && $records_exists === $records_table ) {
        return;
    }

    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $queue_sql = "CREATE TABLE {$queue_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        recipe varchar(64) NOT NULL,
        provider varchar(120) NOT NULL,
        url longtext NOT NULL,
        url_hash char(64) NOT NULL,
        job_type varchar(24) NOT NULL DEFAULT 'page',
        status varchar(24) NOT NULL DEFAULT 'pending',
        priority int(11) NOT NULL DEFAULT 50,
        attempts smallint(5) unsigned NOT NULL DEFAULT 0,
        http_status smallint(5) unsigned NOT NULL DEFAULT 0,
        source varchar(255) NOT NULL DEFAULT '',
        last_error text NULL,
        next_attempt datetime NULL,
        fetched_at datetime NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY recipe_url (recipe,url_hash),
        KEY recipe_status (recipe,status,priority),
        KEY next_attempt (status,next_attempt)
    ) {$charset};";

    $records_sql = "CREATE TABLE {$records_table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        recipe varchar(64) NOT NULL,
        provider varchar(120) NOT NULL,
        external_id varchar(191) NOT NULL,
        external_hash char(64) NOT NULL,
        record_json longtext NOT NULL,
        record_hash char(64) NOT NULL,
        imported_hash char(64) NOT NULL DEFAULT '',
        first_seen datetime NOT NULL,
        last_seen datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY recipe_external (recipe,external_hash),
        KEY recipe_records (recipe),
        KEY provider (provider)
    ) {$charset};";

    dbDelta( $queue_sql );
    dbDelta( $records_sql );
    update_option( 'seo_supplier_crawl_db_version', SEO_SUPPLIER_CRAWL_VERSION, false );
}

/**
 * Estado ligero de una receta.
 *
 * @param string $recipe_id ID.
 * @return array
 */
function seo_supplier_crawl_state( $recipe_id ) {
    $state = get_option( 'seo_supplier_crawl_state_' . sanitize_key( $recipe_id ), [] );
    return is_array( $state ) ? $state : [];
}

/**
 * Guarda el estado ligero de una receta.
 *
 * @param string $recipe_id ID.
 * @param array  $state Estado.
 * @return void
 */
function seo_supplier_crawl_store_state( $recipe_id, $state ) {
    $state               = is_array( $state ) ? $state : [];
    $state['updated_at'] = current_time( 'mysql' );
    update_option( 'seo_supplier_crawl_state_' . sanitize_key( $recipe_id ), $state, false );
}


/**
 * Detiene por completo todos los rastreos y deja el staging del crawler vacio.
 *
 * NO toca wp_seo_proveedores_productos ni los CSV/importaciones comerciales.
 * Solo cancela el hook del crawler, vacia sus dos tablas temporales y elimina
 * estados/robots/ritmos guardados para que "Procesos iniciados" vuelva a cero.
 *
 * @return void
 */
function seo_supplier_crawl_handle_reset_all() {
    if ( empty( $_POST['seo_supplier_crawl_reset_all'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    check_admin_referer( 'seo_supplier_crawl_reset_all', 'seo_supplier_crawl_reset_nonce' );

    // Action Scheduler: cancela cualquier tick pendiente, tambien de recetas antiguas.
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'seo_supplier_crawl_tick' );
    }

    // WP-Cron: elimina todas las ejecuciones del hook, independientemente de argumentos.
    if ( function_exists( 'wp_unschedule_hook' ) ) {
        wp_unschedule_hook( 'seo_supplier_crawl_tick' );
    } else {
        foreach ( array_keys( seo_supplier_crawl_recipes() ) as $recipe_id ) {
            wp_clear_scheduled_hook( 'seo_supplier_crawl_tick', [ sanitize_key( $recipe_id ) ] );
        }
    }

    seo_supplier_crawl_install_table();
    global $wpdb;

    $queue_table   = seo_supplier_crawl_table();
    $records_table = seo_supplier_crawl_records_table();

    // TRUNCATE evita recorrer cientos de miles de filas. Si el hosting no lo permite,
    // se usa DELETE como alternativa.
    if ( false === $wpdb->query( "TRUNCATE TABLE {$queue_table}" ) ) {
        $wpdb->query( "DELETE FROM {$queue_table}" );
    }
    if ( false === $wpdb->query( "TRUNCATE TABLE {$records_table}" ) ) {
        $wpdb->query( "DELETE FROM {$records_table}" );
    }

    // Borra estados incluso de recetas que ya no esten instaladas.
    $like_state = $wpdb->esc_like( 'seo_supplier_crawl_state_' ) . '%';
    $like_robot = $wpdb->esc_like( 'seo_supplier_crawl_robots_' ) . '%';
    $like_last  = $wpdb->esc_like( 'seo_supplier_crawl_last_request_' ) . '%';
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $like_state,
            $like_robot,
            $like_last
        )
    );

    // Limpia locks de las recetas que estan instaladas ahora.
    foreach ( array_keys( seo_supplier_crawl_recipes() ) as $recipe_id ) {
        $recipe_id = sanitize_key( $recipe_id );
        delete_transient( 'seo_supplier_crawl_lock_' . $recipe_id );
        delete_transient( 'seo_supplier_crawl_csv_lock_' . $recipe_id );
    }

    wp_safe_redirect(
        add_query_arg(
            [
                'page'             => 'seo-import-export',
                'seo_ie_tab'       => 'importar-proveedor',
                'seo_crawl_notice' => 'reset',
            ],
            admin_url( 'admin.php' )
        )
    );
    exit;
}

/**
 * Canoniza una URL segun la receta y comprueba que pertenezca al proveedor.
 *
 * @param array  $recipe Receta.
 * @param string $url URL.
 * @return string
 */
function seo_supplier_crawl_prepare_url( $recipe, $url ) {
    $url = esc_url_raw( trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
    if ( ! preg_match( '#^https?://#i', $url ) ) {
        return '';
    }

    $callback = $recipe['canonicalize_callback'] ?? '';
    if ( is_callable( $callback ) ) {
        $url = esc_url_raw( (string) call_user_func( $callback, $url, $recipe ) );
    }

    if ( '' === $url ) {
        return '';
    }

    $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
    if ( '' === $host ) {
        return '';
    }

    if ( ! empty( $recipe['allowed_hosts'] ) && ! in_array( $host, $recipe['allowed_hosts'], true ) ) {
        return '';
    }

    return $url;
}

/**
 * Inserta una URL en la cola sin duplicarla.
 *
 * @param string $recipe_id ID de receta.
 * @param string $url URL.
 * @param string $job_type Tipo.
 * @param int    $priority Prioridad; menor se procesa antes.
 * @param string $source Origen del descubrimiento.
 * @param bool   $force Reabrir aunque ya estuviera terminada.
 * @return bool
 */
function seo_supplier_crawl_enqueue( $recipe_id, $url, $job_type = 'page', $priority = 50, $source = '', $force = false ) {
    seo_supplier_crawl_install_table();

    global $wpdb;
    $recipe = seo_supplier_crawl_recipe( $recipe_id );
    if ( ! is_array( $recipe ) ) {
        return false;
    }

    $url = seo_supplier_crawl_prepare_url( $recipe, $url );
    if ( '' === $url ) {
        return false;
    }

    $table    = seo_supplier_crawl_table();
    $hash     = hash( 'sha256', $url );
    $job_type = sanitize_key( $job_type );
    $job_type = in_array( $job_type, [ 'robots', 'sitemap', 'home', 'category', 'product', 'page' ], true ) ? $job_type : 'page';
    $priority = max( 1, min( 999, (int) $priority ) );
    $now      = current_time( 'mysql' );

    $existing = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id,status FROM {$table} WHERE recipe = %s AND url_hash = %s LIMIT 1",
            $recipe['id'],
            $hash
        ),
        ARRAY_A
    );

    if ( $existing ) {
        if ( $force ) {
            $wpdb->update(
                $table,
                [
                    'status'       => 'pending',
                    'priority'     => $priority,
                    'job_type'     => $job_type,
                    'source'       => sanitize_text_field( $source ),
                    'attempts'     => 0,
                    'http_status'  => 0,
                    'last_error'   => '',
                    'next_attempt' => null,
                    'updated_at'   => $now,
                ],
                [ 'id' => absint( $existing['id'] ) ]
            );
        }
        return true;
    }

    return false !== $wpdb->insert(
        $table,
        [
            'recipe'      => $recipe['id'],
            'provider'    => $recipe['provider'],
            'url'         => $url,
            'url_hash'    => $hash,
            'job_type'    => $job_type,
            'status'      => 'pending',
            'priority'    => $priority,
            'attempts'    => 0,
            'http_status' => 0,
            'source'      => sanitize_text_field( $source ),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]
    );
}

/**
 * Obtiene conteos de cola por estado.
 *
 * @param string $recipe_id ID.
 * @return array<string,int>
 */
function seo_supplier_crawl_counts( $recipe_id ) {
    seo_supplier_crawl_install_table();

    global $wpdb;
    $table = seo_supplier_crawl_table();
    $rows  = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT status,COUNT(*) total FROM {$table} WHERE recipe = %s GROUP BY status",
            sanitize_key( $recipe_id )
        ),
        ARRAY_A
    );

    $counts = [
        'pending'    => 0,
        'processing' => 0,
        'done'       => 0,
        'retry'      => 0,
        'blocked'    => 0,
        'failed'     => 0,
        'total'      => 0,
    ];

    foreach ( (array) $rows as $row ) {
        $status = sanitize_key( $row['status'] ?? '' );
        $total  = absint( $row['total'] ?? 0 );
        if ( isset( $counts[ $status ] ) ) {
            $counts[ $status ] = $total;
        }
        $counts['total'] += $total;
    }

    return $counts;
}

/**
 * Guarda el instante de la ultima peticion al proveedor.
 *
 * @param string $recipe_id ID.
 * @return int
 */
function seo_supplier_crawl_last_request( $recipe_id ) {
    return absint( get_option( 'seo_supplier_crawl_last_request_' . sanitize_key( $recipe_id ), 0 ) );
}

/**
 * Ritmo actual de una receta. El usuario no tiene que ajustarlo: el worker
 * aprende de respuestas correctas, errores temporales y limites del servidor.
 *
 * @param array $recipe Receta.
 * @param array $state Estado opcional.
 * @return int Segundos entre peticiones.
 */
function seo_supplier_crawl_effective_delay( $recipe, $state = [] ) {
    if ( empty( $state ) && ! empty( $recipe['id'] ) ) {
        $state = seo_supplier_crawl_state( $recipe['id'] );
    }

    $minimum = max( 15, absint( $recipe['min_delay'] ?? 45 ) );
    $maximum = max( $minimum, absint( $recipe['max_delay'] ?? 1800 ) );
    $initial = max( $minimum, absint( $recipe['initial_delay'] ?? $minimum ) );
    $current = absint( $state['adaptive_delay'] ?? $initial );

    return max( $minimum, min( $maximum, $current ) );
}

/**
 * Ajusta automaticamente la velocidad sin superar los limites de la receta.
 *
 * @param array  $recipe Receta.
 * @param array  $state Estado.
 * @param string $outcome success|throttle|server_error|network_error|forbidden.
 * @param int    $hint_delay Segundos sugeridos por Retry-After u otra fuente.
 * @return array Estado actualizado.
 */
function seo_supplier_crawl_adapt_delay( $recipe, $state, $outcome, $hint_delay = 0 ) {
    $minimum = max( 15, absint( $recipe['min_delay'] ?? 45 ) );
    $maximum = max( $minimum, absint( $recipe['max_delay'] ?? 1800 ) );
    $current = seo_supplier_crawl_effective_delay( $recipe, $state );
    $streak  = absint( $state['success_streak'] ?? 0 );

    if ( 'success' === $outcome ) {
        $streak++;
        // Solo acelera despues de varias respuestas buenas consecutivas y nunca
        // por debajo del minimo definido por la receta.
        if ( $streak >= 8 && $current > $minimum ) {
            $current = max( $minimum, $current - max( 5, (int) round( $current * 0.10 ) ) );
            $streak  = 0;
        }
    } elseif ( 'throttle' === $outcome ) {
        $current = max( $current * 3, absint( $hint_delay ), $minimum * 4 );
        $streak  = 0;
    } elseif ( 'server_error' === $outcome ) {
        $current = max( $current * 2, $minimum * 2 );
        $streak  = 0;
    } elseif ( 'network_error' === $outcome ) {
        $current = max( (int) ceil( $current * 1.5 ), $minimum * 2 );
        $streak  = 0;
    } elseif ( 'forbidden' === $outcome ) {
        $current = max( $current * 2, $minimum * 4 );
        $streak  = 0;
    }

    $state['adaptive_delay'] = max( $minimum, min( $maximum, absint( $current ) ) );
    $state['success_streak'] = $streak;
    $state['last_rate_event'] = sanitize_key( $outcome );

    return $state;
}

/**
 * Programa el siguiente paso sin crear tormentas de cron.
 *
 * @param string $recipe_id ID.
 * @param int    $delay Segundos.
 * @return bool
 */
function seo_supplier_crawl_schedule_next( $recipe_id, $delay = 0 ) {
    $recipe = seo_supplier_crawl_recipe( $recipe_id );
    if ( ! is_array( $recipe ) ) {
        return false;
    }

    $state = seo_supplier_crawl_state( $recipe_id );
    if ( empty( $state['enabled'] ) ) {
        return false;
    }

    if ( $delay > 0 ) {
        $delay = max( 2, absint( $delay ) );
    } else {
        $delay  = seo_supplier_crawl_effective_delay( $recipe, $state );
        $jitter = max( 1, (int) floor( $delay * 0.20 ) );
        $delay += wp_rand( 0, $jitter );
    }
    $args  = [ sanitize_key( $recipe_id ) ];

    if ( function_exists( 'as_next_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
        $next = as_next_scheduled_action( 'seo_supplier_crawl_tick', $args, 'seo-supplier-crawl' );
        if ( false === $next ) {
            as_schedule_single_action( time() + $delay, 'seo_supplier_crawl_tick', $args, 'seo-supplier-crawl' );
        }
        return true;
    }

    if ( false === wp_next_scheduled( 'seo_supplier_crawl_tick', $args ) ) {
        wp_schedule_single_event( time() + $delay, 'seo_supplier_crawl_tick', $args, true );
    }

    if ( function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
        spawn_cron( time() );
    }

    return true;
}

/**
 * Interpreta robots.txt para nuestro bot. Implementa las reglas comunes de
 * Allow/Disallow por prefijo y comodin; ante un bloqueo explicito no rastrea.
 *
 * @param string $body robots.txt.
 * @return array
 */
function seo_supplier_crawl_parse_robots( $body ) {
    $groups      = [];
    $group_index = -1;
    $sitemaps    = [];
    $last_was_ua = false;

    foreach ( preg_split( '/\\R/u', (string) $body ) as $line ) {
        $line = trim( preg_replace( '/\\s*#.*$/', '', $line ) );
        if ( '' === $line || false === strpos( $line, ':' ) ) {
            continue;
        }

        [ $key, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
        $key = strtolower( $key );

        if ( 'sitemap' === $key ) {
            if ( preg_match( '#^https?://#i', $value ) ) {
                $sitemaps[] = esc_url_raw( $value );
            }
            continue;
        }

        if ( 'user-agent' === $key ) {
            if ( ! $last_was_ua || $group_index < 0 ) {
                $groups[]    = [ 'agents' => [], 'rules' => [] ];
                $group_index = count( $groups ) - 1;
            }
            $groups[ $group_index ]['agents'][] = strtolower( $value );
            $last_was_ua = true;
            continue;
        }

        $last_was_ua = false;
        if ( ! in_array( $key, [ 'allow', 'disallow' ], true ) || $group_index < 0 ) {
            continue;
        }

        $groups[ $group_index ]['rules'][] = [ 'type' => $key, 'pattern' => $value ];
    }

    $selected = [];
    foreach ( $groups as $group ) {
        $agents = (array) ( $group['agents'] ?? [] );
        if ( in_array( 'seosystem-catalogdiscovery', $agents, true ) ) {
            $selected = (array) ( $group['rules'] ?? [] );
            break;
        }
    }
    if ( empty( $selected ) ) {
        foreach ( $groups as $group ) {
            if ( in_array( '*', (array) ( $group['agents'] ?? [] ), true ) ) {
                $selected = array_merge( $selected, (array) ( $group['rules'] ?? [] ) );
            }
        }
    }

    return [
        'rules'    => $selected,
        'sitemaps' => array_values( array_unique( $sitemaps ) ),
    ];
}

/**
 * Convierte una regla de robots en regex y evalua una ruta.
 *
 * @param string $pattern Regla.
 * @param string $target Ruta + query.
 * @return bool
 */
function seo_supplier_crawl_robots_pattern_matches( $pattern, $target ) {
    $pattern = trim( (string) $pattern );
    if ( '' === $pattern ) {
        return false;
    }

    $anchored = '$' === substr( $pattern, -1 );
    if ( $anchored ) {
        $pattern = substr( $pattern, 0, -1 );
    }

    $quoted = preg_quote( $pattern, '#' );
    $quoted = str_replace( '\*', '.*', $quoted );
    $regex  = '#^' . $quoted . ( $anchored ? '$' : '' ) . '#';

    return 1 === preg_match( $regex, $target );
}

/**
 * Comprueba el robots cacheado. Si aun no existe, obliga a procesar robots.
 *
 * @param array  $recipe Receta.
 * @param string $url URL.
 * @return true|WP_Error
 */
function seo_supplier_crawl_robots_allows( $recipe, $url ) {
    if ( empty( $recipe['respect_robots'] ) ) {
        return true;
    }

    $state = get_option( 'seo_supplier_crawl_robots_' . $recipe['id'], [] );
    if ( ! is_array( $state ) || empty( $state['checked'] ) ) {
        return new WP_Error( 'robots_pending', 'robots.txt todavia no se ha comprobado.' );
    }

    if ( ! empty( $state['blocked_all'] ) ) {
        return new WP_Error( 'robots_unavailable', 'robots.txt no permite establecer una politica de rastreo segura.' );
    }

    $path   = (string) wp_parse_url( $url, PHP_URL_PATH );
    $query  = (string) wp_parse_url( $url, PHP_URL_QUERY );
    $target = $path . ( '' !== $query ? '?' . $query : '' );
    $best   = null;

    foreach ( (array) ( $state['rules'] ?? [] ) as $rule ) {
        $pattern = (string) ( $rule['pattern'] ?? '' );
        if ( '' === $pattern || ! seo_supplier_crawl_robots_pattern_matches( $pattern, $target ) ) {
            continue;
        }

        $length = strlen( str_replace( '*', '', rtrim( $pattern, '$' ) ) );
        if ( null === $best || $length > $best['length'] || ( $length === $best['length'] && 'allow' === ( $rule['type'] ?? '' ) ) ) {
            $best = [ 'length' => $length, 'type' => sanitize_key( $rule['type'] ?? '' ) ];
        }
    }

    if ( is_array( $best ) && 'disallow' === $best['type'] ) {
        return new WP_Error( 'robots_disallow', 'URL excluida por robots.txt.' );
    }

    return true;
}

/**
 * Realiza una sola peticion identificada al proveedor.
 *
 * @param array  $recipe Receta.
 * @param string $url URL.
 * @param string $accept Accept.
 * @return array|WP_Error
 */
function seo_supplier_crawl_http_get( $recipe, $url, $accept = 'text/html,application/xhtml+xml' ) {
    $home = home_url( '/' );
    $ua   = 'SEOSystem-CatalogDiscovery/1.0 (+' . $home . ')';

    $response = wp_safe_remote_get(
        $url,
        [
            'timeout'     => 25,
            'redirection' => 5,
            'user-agent'  => $ua,
            'headers'     => [
                'Accept'          => $accept,
                'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.5',
                'Cache-Control'   => 'no-cache',
            ],
        ]
    );

    update_option( 'seo_supplier_crawl_last_request_' . $recipe['id'], time(), false );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    return [
        'status'      => (int) wp_remote_retrieve_response_code( $response ),
        'body'        => (string) wp_remote_retrieve_body( $response ),
        'content_type'=> (string) wp_remote_retrieve_header( $response, 'content-type' ),
        'retry_after' => (string) wp_remote_retrieve_header( $response, 'retry-after' ),
        'final_url'   => $url,
    ];
}

/**
 * Guarda/actualiza un producto descubierto en staging. Todavia no toca el
 * catalogo de proveedores: ese paso siempre pasa por un CSV estandar.
 *
 * @param array $recipe Receta.
 * @param array $row Datos normalizados.
 * @return string|WP_Error created|updated|unchanged.
 */
function seo_supplier_crawl_upsert_record( $recipe, $row ) {
    if ( ! function_exists( 'seo_proveedores_campos_importacion' ) ) {
        return new WP_Error( 'supplier_fields_unavailable', 'El esquema comun de proveedores no esta cargado.' );
    }

    global $wpdb;
    $fields = seo_proveedores_campos_importacion();
    $data   = [];

    foreach ( $fields as $field_key => $definition ) {
        $value = $row[ $field_key ] ?? '';
        $data[ $field_key ] = seo_proveedores_limpiar_valor( $value, $definition['type'] );
    }

    $external_id = trim( (string) ( $data['proveedor_id_externo'] ?? '' ) );
    $name        = trim( (string) ( $data['nombre'] ?? '' ) );
    if ( '' === $external_id || '' === $name ) {
        return new WP_Error( 'supplier_crawl_missing_fields', 'La fila descubierta no contiene ID externo y nombre.' );
    }

    $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    if ( ! is_string( $json ) || '' === $json ) {
        return new WP_Error( 'supplier_crawl_json_failed', 'No se pudo serializar el producto descubierto.' );
    }

    seo_supplier_crawl_install_table();
    $table         = seo_supplier_crawl_records_table();
    $external_hash = hash( 'sha256', $external_id );
    $record_hash   = hash( 'sha256', $json );
    $now           = current_time( 'mysql' );

    $existing = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id,record_hash FROM {$table} WHERE recipe = %s AND external_hash = %s LIMIT 1",
            $recipe['id'],
            $external_hash
        ),
        ARRAY_A
    );

    if ( $existing ) {
        if ( hash_equals( (string) $existing['record_hash'], $record_hash ) ) {
            $wpdb->update( $table, [ 'last_seen' => $now ], [ 'id' => absint( $existing['id'] ) ] );
            return 'unchanged';
        }

        $updated = $wpdb->update(
            $table,
            [
                'external_id' => $external_id,
                'record_json' => $json,
                'record_hash' => $record_hash,
                'last_seen'   => $now,
                'updated_at'  => $now,
            ],
            [ 'id' => absint( $existing['id'] ) ]
        );
        return false === $updated
            ? new WP_Error( 'supplier_crawl_record_update_failed', $wpdb->last_error ?: 'No se pudo actualizar staging.' )
            : 'updated';
    }

    $inserted = $wpdb->insert(
        $table,
        [
            'recipe'       => $recipe['id'],
            'provider'     => $recipe['provider'],
            'external_id'  => $external_id,
            'external_hash'=> $external_hash,
            'record_json'  => $json,
            'record_hash'  => $record_hash,
            'imported_hash'=> '',
            'first_seen'   => $now,
            'last_seen'    => $now,
            'updated_at'   => $now,
        ]
    );

    return false === $inserted
        ? new WP_Error( 'supplier_crawl_record_insert_failed', $wpdb->last_error ?: 'No se pudo guardar staging.' )
        : 'created';
}

/**
 * Procesa un sitemap XML.
 *
 * @param array  $recipe Receta.
 * @param string $body XML.
 * @param string $source_url URL sitemap.
 * @return array
 */
function seo_supplier_crawl_process_sitemap( $recipe, $body, $source_url ) {
    $result = [ 'queued' => 0, 'errors' => [] ];
    if ( ! function_exists( 'simplexml_load_string' ) ) {
        $result['errors'][] = 'SimpleXML no esta disponible.';
        return $result;
    }

    $previous = libxml_use_internal_errors( true );
    $xml      = simplexml_load_string( (string) $body );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );

    if ( ! $xml ) {
        $result['errors'][] = 'XML de sitemap no valido.';
        return $result;
    }

    $name = strtolower( $xml->getName() );
    if ( 'sitemapindex' === $name ) {
        foreach ( $xml->sitemap as $item ) {
            $loc = trim( (string) $item->loc );
            if ( seo_supplier_crawl_enqueue( $recipe['id'], $loc, 'sitemap', 4, $source_url ) ) {
                $result['queued']++;
            }
        }
        return $result;
    }

    foreach ( $xml->url as $item ) {
        $loc = trim( (string) $item->loc );
        if ( '' === $loc ) {
            continue;
        }

        $type = 'page';
        $classify = $recipe['classify_url_callback'] ?? '';
        if ( is_callable( $classify ) ) {
            $type = sanitize_key( (string) call_user_func( $classify, $loc, $recipe ) );
        }
        if ( 'ignore' === $type ) {
            continue;
        }
        if ( ! in_array( $type, [ 'home', 'category', 'product', 'page' ], true ) ) {
            $type = 'page';
        }

        if ( seo_supplier_crawl_enqueue( $recipe['id'], $loc, $type, 'product' === $type ? 50 : 20, $source_url ) ) {
            $result['queued']++;
        }
    }

    return $result;
}

/**
 * Marca una fila y reintenta mas tarde.
 *
 * @param array  $job Trabajo.
 * @param string $message Mensaje.
 * @param int    $delay Segundos.
 * @param int    $http_status Estado HTTP.
 * @return void
 */
function seo_supplier_crawl_retry_job( $job, $message, $delay, $http_status = 0 ) {
    global $wpdb;
    $table = seo_supplier_crawl_table();
    $wpdb->update(
        $table,
        [
            'status'       => 'pending',
            'http_status'  => absint( $http_status ),
            'last_error'   => sanitize_text_field( $message ),
            'next_attempt' => wp_date( 'Y-m-d H:i:s', time() + max( 60, absint( $delay ) ), wp_timezone() ),
            'updated_at'   => current_time( 'mysql' ),
        ],
        [ 'id' => absint( $job['id'] ) ]
    );
}

/**
 * Worker: procesa como maximo una URL por ejecucion.
 *
 * @param string $recipe_id Receta.
 * @return void
 */
function seo_supplier_crawl_worker( $recipe_id ) {
    $recipe_id = sanitize_key( $recipe_id );
    $recipe    = seo_supplier_crawl_recipe( $recipe_id );
    if ( ! is_array( $recipe ) ) {
        return;
    }

    $state = seo_supplier_crawl_state( $recipe_id );
    if ( empty( $state['enabled'] ) ) {
        return;
    }

    $lock_key = 'seo_supplier_crawl_lock_' . $recipe_id;
    if ( get_transient( $lock_key ) ) {
        return;
    }
    set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

    seo_supplier_crawl_install_table();
    global $wpdb;
    $table = seo_supplier_crawl_table();
    $now   = current_time( 'mysql' );

    // Recupera trabajos abandonados por un timeout o cierre inesperado de PHP.
    $stale_processing = wp_date( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS, wp_timezone() );
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table} SET status = 'pending',last_error = %s,updated_at = %s
             WHERE recipe = %s AND status = 'processing' AND updated_at < %s",
            'Trabajo recuperado tras quedar interrumpido.',
            $now,
            $recipe_id,
            $stale_processing
        )
    );

    $job = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE recipe = %s AND status = 'pending'
               AND (next_attempt IS NULL OR next_attempt <= %s)
             ORDER BY priority ASC,id ASC LIMIT 1",
            $recipe_id,
            $now
        ),
        ARRAY_A
    );

    if ( ! is_array( $job ) ) {
        seo_supplier_crawl_maybe_import_csv( $recipe_id, true );
        // La importacion del CSV puede actualizar el estado; se relee para no
        // perder sus datos al guardar a continuacion el estado del ciclo.
        $state = seo_supplier_crawl_state( $recipe_id );
        if ( ! empty( $recipe['auto_enabled'] ) && empty( $state['hard_blocked'] ) ) {
            $next_refresh = absint( $state['next_refresh_at'] ?? 0 );
            if ( 0 === $next_refresh || $next_refresh <= time() ) {
                seo_supplier_crawl_refresh_recipe( $recipe );
                $state['cycle']           = absint( $state['cycle'] ?? 0 ) + 1;
                $state['cycle_started_at']= current_time( 'mysql' );
                $state['next_refresh_at'] = time() + absint( $recipe['refresh_hours'] ) * HOUR_IN_SECONDS;
                $state['last_message']    = 'Ciclo automatico de revision iniciado.';
                seo_supplier_crawl_store_state( $recipe_id, $state );
                delete_transient( $lock_key );
                seo_supplier_crawl_schedule_next( $recipe_id, 5 );
                return;
            }

            $state['last_message'] = 'Catalogo al dia. El sistema esperara hasta la proxima revision automatica.';
            seo_supplier_crawl_store_state( $recipe_id, $state );
            delete_transient( $lock_key );
            seo_supplier_crawl_schedule_next( $recipe_id, max( 60, $next_refresh - time() ) );
            return;
        }

        $state['last_message'] = 'No quedan URLs pendientes en este momento.';
        seo_supplier_crawl_store_state( $recipe_id, $state );
        delete_transient( $lock_key );
        return;
    }

    $claimed = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table} SET status = 'processing',attempts = attempts + 1,updated_at = %s WHERE id = %d AND status = 'pending'",
            $now,
            absint( $job['id'] )
        )
    );
    if ( 1 !== (int) $claimed ) {
        delete_transient( $lock_key );
        seo_supplier_crawl_schedule_next( $recipe_id, 5 );
        return;
    }

    $job['attempts'] = absint( $job['attempts'] ) + 1;
    $url             = (string) $job['url'];
    $job_type        = sanitize_key( $job['job_type'] );

    $current_delay = seo_supplier_crawl_effective_delay( $recipe, $state );
    $elapsed       = time() - seo_supplier_crawl_last_request( $recipe_id );
    if ( $elapsed < $current_delay ) {
        $wait = $current_delay - $elapsed + wp_rand( 1, max( 2, (int) floor( $current_delay * 0.15 ) ) );
        seo_supplier_crawl_retry_job( $job, 'Espera de cortesia entre peticiones.', $wait );
        delete_transient( $lock_key );
        seo_supplier_crawl_schedule_next( $recipe_id, $wait );
        return;
    }

    if ( 'robots' !== $job_type ) {
        $allowed = seo_supplier_crawl_robots_allows( $recipe, $url );
        if ( is_wp_error( $allowed ) ) {
            if ( 'robots_pending' === $allowed->get_error_code() ) {
                seo_supplier_crawl_retry_job( $job, $allowed->get_error_message(), 90 );
                delete_transient( $lock_key );
                seo_supplier_crawl_schedule_next( $recipe_id, 5 );
                return;
            }

            $wpdb->update(
                $table,
                [
                    'status'      => 'blocked',
                    'last_error'  => $allowed->get_error_message(),
                    'updated_at'  => current_time( 'mysql' ),
                ],
                [ 'id' => absint( $job['id'] ) ]
            );
            $state['last_message'] = $allowed->get_error_message();
            seo_supplier_crawl_store_state( $recipe_id, $state );
            delete_transient( $lock_key );
            seo_supplier_crawl_schedule_next( $recipe_id );
            return;
        }
    }

    $accept   = in_array( $job_type, [ 'robots', 'sitemap' ], true ) ? 'text/plain,text/xml,application/xml,*/*;q=0.5' : 'text/html,application/xhtml+xml';
    $response = seo_supplier_crawl_http_get( $recipe, $url, $accept );

    if ( is_wp_error( $response ) ) {
        $delay = min( DAY_IN_SECONDS, 15 * MINUTE_IN_SECONDS * max( 1, $job['attempts'] ) );
        if ( $job['attempts'] >= $recipe['max_attempts'] ) {
            $wpdb->update(
                $table,
                [ 'status' => 'failed', 'last_error' => $response->get_error_message(), 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => absint( $job['id'] ) ]
            );
        } else {
            seo_supplier_crawl_retry_job( $job, $response->get_error_message(), $delay );
        }
        $state = seo_supplier_crawl_adapt_delay( $recipe, $state, 'network_error' );
        seo_supplier_crawl_store_state( $recipe_id, $state );
        delete_transient( $lock_key );
        seo_supplier_crawl_schedule_next( $recipe_id );
        return;
    }

    $status = absint( $response['status'] ?? 0 );
    $body   = (string) ( $response['body'] ?? '' );

    if ( in_array( $status, [ 401, 403 ], true ) ) {
        if ( 'robots' === $job_type ) {
            update_option(
                'seo_supplier_crawl_robots_' . $recipe_id,
                [ 'checked' => true, 'blocked_all' => true, 'status' => $status, 'rules' => [], 'sitemaps' => [], 'checked_at' => current_time( 'mysql' ) ],
                false
            );
            // Si ni siquiera podemos conocer robots.txt por un 401/403, se
            // detiene esta receta: no intentamos adivinar ni saltar la politica.
            $state['enabled']      = false;
            $state['hard_blocked'] = true;
        }
        $wpdb->update(
            $table,
            [ 'status' => 'blocked', 'http_status' => $status, 'last_error' => 'HTTP ' . $status . ': acceso rechazado; no se reintenta automaticamente.', 'fetched_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => absint( $job['id'] ) ]
        );
        $state = seo_supplier_crawl_adapt_delay( $recipe, $state, 'forbidden' );
        $state['last_message'] = 'HTTP ' . $status . ' en ' . $url . '. Se ha respetado el bloqueo y se ha reducido automaticamente el ritmo.';
        seo_supplier_crawl_store_state( $recipe_id, $state );
        delete_transient( $lock_key );
        seo_supplier_crawl_schedule_next( $recipe_id );
        return;
    }

    if ( 429 === $status || $status >= 500 ) {
        $retry_after = absint( $response['retry_after'] ?? 0 );
        $delay       = $retry_after ?: min( DAY_IN_SECONDS, 30 * MINUTE_IN_SECONDS * max( 1, $job['attempts'] ) );
        if ( $job['attempts'] >= $recipe['max_attempts'] ) {
            $wpdb->update(
                $table,
                [ 'status' => 'failed', 'http_status' => $status, 'last_error' => 'HTTP ' . $status, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => absint( $job['id'] ) ]
            );
        } else {
            seo_supplier_crawl_retry_job( $job, 'HTTP ' . $status . ': espera progresiva.', $delay, $status );
        }
        $state = seo_supplier_crawl_adapt_delay(
            $recipe,
            $state,
            429 === $status ? 'throttle' : 'server_error',
            $retry_after
        );
        seo_supplier_crawl_store_state( $recipe_id, $state );
        delete_transient( $lock_key );
        seo_supplier_crawl_schedule_next( $recipe_id, max( $delay, seo_supplier_crawl_effective_delay( $recipe, $state ) ) );
        return;
    }

    if ( $status < 200 || $status >= 400 || '' === trim( $body ) ) {
        $wpdb->update(
            $table,
            [ 'status' => 'failed', 'http_status' => $status, 'last_error' => 'HTTP ' . $status, 'fetched_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => absint( $job['id'] ) ]
        );
        delete_transient( $lock_key );
        seo_supplier_crawl_schedule_next( $recipe_id );
        return;
    }

    $message = '';
    $created = 0;
    $updated = 0;
    $queued  = 0;

    if ( 'robots' === $job_type ) {
        $parsed = seo_supplier_crawl_parse_robots( $body );
        update_option(
            'seo_supplier_crawl_robots_' . $recipe_id,
            [
                'checked'     => true,
                'blocked_all' => false,
                'status'      => $status,
                'rules'       => (array) ( $parsed['rules'] ?? [] ),
                'sitemaps'    => (array) ( $parsed['sitemaps'] ?? [] ),
                'checked_at'  => current_time( 'mysql' ),
            ],
            false
        );
        foreach ( (array) ( $parsed['sitemaps'] ?? [] ) as $sitemap_url ) {
            if ( seo_supplier_crawl_enqueue( $recipe_id, $sitemap_url, 'sitemap', 4, 'robots.txt' ) ) {
                $queued++;
            }
        }
        $message = 'robots.txt comprobado.';
    } elseif ( 'sitemap' === $job_type ) {
        $parsed  = seo_supplier_crawl_process_sitemap( $recipe, $body, $url );
        $queued += absint( $parsed['queued'] ?? 0 );
        $message = empty( $parsed['errors'] ) ? 'Sitemap procesado.' : implode( ' ', (array) $parsed['errors'] );
    } else {
        $callback = $recipe['parse_page_callback'] ?? '';
        if ( ! is_callable( $callback ) ) {
            $wpdb->update(
                $table,
                [ 'status' => 'failed', 'http_status' => $status, 'last_error' => 'La receta no tiene parse_page_callback valido.', 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => absint( $job['id'] ) ]
            );
            delete_transient( $lock_key );
            return;
        }

        try {
            $parsed = call_user_func( $callback, $body, $url, $recipe, $job );
        } catch ( Throwable $exception ) {
            $parsed = new WP_Error( 'supplier_crawl_parser_exception', $exception->getMessage() );
        }

        if ( is_wp_error( $parsed ) ) {
            $wpdb->update(
                $table,
                [ 'status' => 'failed', 'http_status' => $status, 'last_error' => $parsed->get_error_message(), 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => absint( $job['id'] ) ]
            );
            delete_transient( $lock_key );
            seo_supplier_crawl_schedule_next( $recipe_id );
            return;
        }

        foreach ( (array) ( $parsed['records'] ?? [] ) as $record ) {
            $saved = seo_supplier_crawl_upsert_record( $recipe, $record );
            if ( is_wp_error( $saved ) ) {
                continue;
            }
            if ( 'created' === $saved ) {
                $created++;
            } elseif ( 'updated' === $saved ) {
                $updated++;
            }
        }

        foreach ( (array) ( $parsed['enqueue'] ?? [] ) as $next ) {
            if ( ! is_array( $next ) || empty( $next['url'] ) ) {
                continue;
            }
            if ( seo_supplier_crawl_enqueue(
                $recipe_id,
                $next['url'],
                $next['type'] ?? 'page',
                $next['priority'] ?? 50,
                $next['source'] ?? $url
            ) ) {
                $queued++;
            }
        }

        $message = sanitize_text_field( (string) ( $parsed['message'] ?? '' ) );
    }

    $wpdb->update(
        $table,
        [
            'status'       => 'done',
            'http_status'  => $status,
            'last_error'   => '',
            'next_attempt' => null,
            'fetched_at'   => current_time( 'mysql' ),
            'updated_at'   => current_time( 'mysql' ),
        ],
        [ 'id' => absint( $job['id'] ) ]
    );

    $state = seo_supplier_crawl_adapt_delay( $recipe, $state, 'success' );
    $state['last_url']     = $url;
    $state['last_message'] = trim( $message . ' Nuevos: ' . $created . ' · Actualizados: ' . $updated . ' · URLs en cola: ' . $queued );
    $state['last_http']    = $status;
    seo_supplier_crawl_store_state( $recipe_id, $state );
    seo_supplier_crawl_maybe_import_csv( $recipe_id, false );

    delete_transient( $lock_key );
    seo_supplier_crawl_schedule_next( $recipe_id );
}

/**
 * Semillas iniciales de una receta.
 *
 * @param array $recipe Receta.
 * @param bool  $force Fuerza reapertura.
 * @return int
 */
function seo_supplier_crawl_seed_recipe( $recipe, $force = false ) {
    $queued = 0;

    $base_url = (string) ( $recipe['base_url'] ?? '' );
    if ( '' !== $base_url ) {
        $parts = wp_parse_url( $base_url );
        if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
            $robots = $parts['scheme'] . '://' . $parts['host'] . '/robots.txt';
            if ( seo_supplier_crawl_enqueue( $recipe['id'], $robots, 'robots', 1, 'inicio', $force ) ) {
                $queued++;
            }
        }
    }

    foreach ( (array) ( $recipe['sitemap_urls'] ?? [] ) as $url ) {
        if ( seo_supplier_crawl_enqueue( $recipe['id'], $url, 'sitemap', 5, 'receta', $force ) ) {
            $queued++;
        }
    }

    foreach ( (array) ( $recipe['seed_urls'] ?? [] ) as $index => $url ) {
        $type = 0 === $index ? 'home' : 'category';
        if ( seo_supplier_crawl_enqueue( $recipe['id'], $url, $type, 10 + (int) $index, 'receta', $force ) ) {
            $queued++;
        }
    }

    return $queued;
}

/**
 * Nueva lectura: reabre semillas y productos antiguos sin borrar historico.
 *
 * @param array $recipe Receta.
 * @return void
 */
function seo_supplier_crawl_refresh_recipe( $recipe ) {
    seo_supplier_crawl_install_table();
    global $wpdb;
    $table     = seo_supplier_crawl_table();
    $threshold = wp_date( 'Y-m-d H:i:s', time() - $recipe['revisit_days'] * DAY_IN_SECONDS, wp_timezone() );

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table}
             SET status = 'pending',attempts = 0,http_status = 0,last_error = '',next_attempt = NULL,updated_at = %s
             WHERE recipe = %s AND status IN ('done','failed','blocked')
               AND (job_type IN ('robots','sitemap','home','category') OR fetched_at IS NULL OR fetched_at < %s)",
            current_time( 'mysql' ),
            $recipe['id'],
            $threshold
        )
    );

    delete_option( 'seo_supplier_crawl_robots_' . $recipe['id'] );
    seo_supplier_crawl_seed_recipe( $recipe, true );
}

/**
 * Reanuda solo las recetas que el administrador inicio expresamente.
 *
 * Cargar WordPress nunca inicia por si solo un proveedor nuevo. Una vez que el
 * administrador pulsa "Iniciar descubrimiento", la receta queda habilitada y
 * este hook se limita a mantener viva su programacion automatica.
 *
 * @return void
 */
function seo_supplier_crawl_resume_started_recipes() {
    if ( wp_installing() ) {
        return;
    }

    foreach ( seo_supplier_crawl_recipes() as $recipe_id => $recipe ) {
        $state = seo_supplier_crawl_state( $recipe_id );

        // Migracion desde v0.3.0: las recetas que arrancaron solas quedan
        // detenidas hasta que el administrador las confirme manualmente.
        if ( empty( $state['manual_started'] ) ) {
            if ( ! empty( $state['enabled'] ) ) {
                $state['enabled']      = false;
                $state['last_message'] = 'Pendiente de inicio manual desde Importar proveedor.';
                seo_supplier_crawl_store_state( $recipe_id, $state );
            }
            continue;
        }

        if ( empty( $state['enabled'] ) || ! empty( $state['hard_blocked'] ) ) {
            continue;
        }

        seo_supplier_crawl_install_table();
        $counts = seo_supplier_crawl_counts( $recipe_id );

        if ( 0 < $counts['pending'] ) {
            seo_supplier_crawl_schedule_next( $recipe_id, 5 );
            continue;
        }

        // Si el ciclo termino, el worker se ocupa de refrescar cuando llegue
        // next_refresh_at. Solo garantizamos que exista un tick futuro.
        $next_refresh = absint( $state['next_refresh_at'] ?? 0 );
        if ( ! $next_refresh ) {
            $next_refresh = time() + absint( $recipe['refresh_hours'] ) * HOUR_IN_SECONDS;
            $state['next_refresh_at'] = $next_refresh;
            seo_supplier_crawl_store_state( $recipe_id, $state );
        }
        seo_supplier_crawl_schedule_next( $recipe_id, max( 60, $next_refresh - time() ) );
    }
}

/**
 * Inicia manualmente una receta web y deja el resto del trabajo a la cola.
 *
 * @return void
 */
function seo_supplier_crawl_handle_start_request() {
    if ( empty( $_POST['seo_supplier_crawl_start'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    check_admin_referer( 'seo_supplier_crawl_start', 'seo_supplier_crawl_nonce' );

    $source_value = sanitize_text_field( wp_unslash( $_POST['seo_supplier_crawl_recipe'] ?? '' ) );
    $source_type  = 'crawl';
    $recipe_id    = $source_value;

    if ( false !== strpos( $source_value, ':' ) ) {
        [ $source_type, $recipe_id ] = array_pad( explode( ':', $source_value, 2 ), 2, '' );
    }

    $source_type = sanitize_key( $source_type );
    $recipe_id   = sanitize_key( $recipe_id );

    // Scrapers externos: WordPress solo dispara el runner y espera el CSV.
    if ( 'external' === $source_type ) {
        $external = seo_supplier_external_web_recipe( $recipe_id );
        if ( ! is_array( $external ) ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'             => 'seo-import-export',
                        'seo_ie_tab'       => 'importar-proveedor',
                        'seo_crawl_notice' => 'recipe_missing',
                    ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        $catalog_complete = ! empty( $_POST['seo_supplier_catalog_complete'] );
        $result = null;

        if ( 'github' === ( $external['runner'] ?? '' ) ) {
            if ( ! function_exists( 'seo_github_python_runner_start' ) ) {
                $result = new WP_Error( 'seo_supplier_external_runner_missing', 'GitHub Actions Python Runner no esta cargado.' );
            } else {
                $result = seo_github_python_runner_start( $external['import_recipe_id'], $catalog_complete );
            }
        }

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'              => 'seo-import-export',
                        'seo_ie_tab'        => 'importar-proveedor',
                        'seo_crawl_notice'  => 'external_error',
                        'seo_crawl_recipe'  => $recipe_id,
                        'seo_crawl_message' => $result->get_error_message(),
                    ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'              => 'seo-import-export',
                    'seo_ie_tab'        => 'importar-proveedor',
                    'seo_crawl_notice'  => 'external_started',
                    'seo_crawl_recipe'  => $recipe_id,
                    'seo_crawl_run'     => sanitize_text_field( (string) ( $result['remote_run_id'] ?? '' ) ),
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    $recipe = seo_supplier_crawl_recipe( $recipe_id );

    if ( ! is_array( $recipe ) ) {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'             => 'seo-import-export',
                    'seo_ie_tab'       => 'importar-proveedor',
                    'seo_crawl_notice' => 'recipe_missing',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    seo_supplier_crawl_install_table();
    $state = seo_supplier_crawl_state( $recipe_id );

    if ( ! empty( $state['hard_blocked'] ) ) {
        $state['enabled']      = false;
        $state['last_message'] = 'La receta esta detenida porque el proveedor rechazo el acceso a robots.txt. No se fuerza el rastreo.';
        seo_supplier_crawl_store_state( $recipe_id, $state );
    } else {
        $state['manual_started'] = true;
        $state['enabled']        = true;
        $state['started_at']     = $state['started_at'] ?? current_time( 'mysql' );
        $state['adaptive_delay'] = absint( $state['adaptive_delay'] ?? $recipe['initial_delay'] );
        $state['next_refresh_at']= time() + absint( $recipe['refresh_hours'] ) * HOUR_IN_SECONDS;

        $counts = seo_supplier_crawl_counts( $recipe_id );
        if ( 0 === $counts['total'] ) {
            seo_supplier_crawl_seed_recipe( $recipe, false );
            $state['cycle']            = max( 1, absint( $state['cycle'] ?? 0 ) );
            $state['cycle_started_at'] = current_time( 'mysql' );
            $state['last_message']     = 'Descubrimiento iniciado manualmente. A partir de ahora la cola continuara sola.';
        } elseif ( 0 === $counts['pending'] ) {
            seo_supplier_crawl_refresh_recipe( $recipe );
            $state['cycle']            = absint( $state['cycle'] ?? 0 ) + 1;
            $state['cycle_started_at'] = current_time( 'mysql' );
            $state['last_message']     = 'Nueva lectura iniciada. A partir de ahora la cola continuara sola.';
        } else {
            $state['last_message'] = 'Descubrimiento reanudado. A partir de ahora la cola continuara sola.';
        }

        seo_supplier_crawl_store_state( $recipe_id, $state );
        seo_supplier_crawl_schedule_next( $recipe_id, 5 );
    }

    wp_safe_redirect(
        add_query_arg(
            [
                'page'             => 'seo-import-export',
                'seo_ie_tab'       => 'importar-proveedor',
                'seo_crawl_notice' => 'started',
                'seo_crawl_recipe' => $recipe_id,
            ],
            admin_url( 'admin.php' )
        )
    );
    exit;
}

/**
 * Conteo de productos descubiertos en staging y pendientes de pasar por CSV.
 *
 * @param string $recipe_id Receta.
 * @return array{total:int,dirty:int}
 */
function seo_supplier_crawl_record_counts( $recipe_id ) {
    seo_supplier_crawl_install_table();
    global $wpdb;
    $table = seo_supplier_crawl_records_table();
    $row   = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COUNT(*) total,SUM(CASE WHEN imported_hash = '' OR imported_hash <> record_hash THEN 1 ELSE 0 END) dirty FROM {$table} WHERE recipe = %s",
            sanitize_key( $recipe_id )
        ),
        ARRAY_A
    );
    return [
        'total' => absint( $row['total'] ?? 0 ),
        'dirty' => absint( $row['dirty'] ?? 0 ),
    ];
}

/**
 * Crea el CSV estandar interno con el snapshot completo descubierto.
 *
 * @param array $recipe Receta web.
 * @return array|WP_Error Estado compatible con seo_proveedores_importar_csv_estandar().
 */
function seo_supplier_crawl_build_standard_csv( $recipe ) {
    if ( ! function_exists( 'seo_proveedores_storage_receta' ) || ! function_exists( 'seo_proveedores_cabecera_estandar' ) || ! function_exists( 'seo_proveedores_analizar_csv' ) ) {
        return new WP_Error( 'supplier_pipeline_unavailable', 'El importador comun de proveedores no esta cargado.' );
    }

    $storage = seo_proveedores_storage_receta( 'prepared', $recipe['id'] );
    if ( is_wp_error( $storage ) ) {
        return $storage;
    }

    $filename = wp_unique_filename(
        $storage['dir'],
        'auto_' . sanitize_key( $recipe['id'] ) . '_' . wp_date( 'Ymd_His' ) . '.csv'
    );
    $path = trailingslashit( $storage['dir'] ) . $filename;
    $out  = fopen( $path, 'w' );
    if ( false === $out ) {
        return new WP_Error( 'supplier_auto_csv_open', 'No se pudo crear el CSV automatico.' );
    }

    $columns = seo_proveedores_cabecera_estandar();
    fwrite( $out, "\xEF\xBB\xBF" );
    fputcsv( $out, $columns, ';', '"', '' );

    global $wpdb;
    $table   = seo_supplier_crawl_records_table();
    $last_id = 0;
    $written = 0;

    do {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,record_json FROM {$table} WHERE recipe = %s AND id > %d ORDER BY id ASC LIMIT 500",
                $recipe['id'],
                $last_id
            ),
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            $last_id = max( $last_id, absint( $row['id'] ?? 0 ) );
            $record  = json_decode( (string) ( $row['record_json'] ?? '' ), true );
            if ( ! is_array( $record ) ) {
                continue;
            }
            $csv_row = [];
            foreach ( $columns as $key ) {
                $value = $record[ $key ] ?? '';
                $value = is_scalar( $value ) ? (string) $value : wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                if ( preg_match( '/^[=+@]/', (string) $value ) ) {
                    $value = "'" . $value;
                }
                $csv_row[] = $value;
            }
            fputcsv( $out, $csv_row, ';', '"', '' );
            $written++;
        }
    } while ( count( (array) $rows ) === 500 );

    fclose( $out );

    if ( 0 === $written ) {
        @unlink( $path );
        return new WP_Error( 'supplier_auto_csv_empty', 'Todavia no hay productos descubiertos para preparar el CSV.' );
    }

    $analysis = seo_proveedores_analizar_csv( $path );
    if ( is_wp_error( $analysis ) ) {
        return $analysis;
    }

    return [
        'state' => array_merge(
            $analysis,
            [
                'recipe_id'         => $recipe['id'],
                'recipe_label'      => $recipe['label'],
                'recipe_version'    => (string) ( $recipe['version'] ?? '' ),
                'proveedor'         => $recipe['provider'],
                'filename'          => $filename,
                'path'              => wp_normalize_path( $path ),
                'url'               => trailingslashit( $storage['url'] ) . rawurlencode( $filename ),
                'original_filename' => 'descubrimiento-web-' . $recipe['id'],
                'original_path'     => '',
                'created'           => time(),
                'automatic_source'  => true,
            ]
        ),
        'log' => [
            'procesados' => $written,
            'preparados' => $written,
            'omitidos'   => 0,
            'errores'    => 0,
            'detalles'   => [ 'CSV estandar generado automaticamente desde el descubrimiento web.' ],
        ],
    ];
}

/**
 * Entrega automaticamente el CSV al importador comun. Se hace por lotes de
 * cambios para que el catalogo vaya recibiendo datos sin esperar al final de
 * un rastreo grande y se fuerza al cerrar cada ciclo.
 *
 * @param string $recipe_id Receta.
 * @param bool   $force Forzar aunque no se alcance el umbral.
 * @return array|WP_Error|null
 */
function seo_supplier_crawl_maybe_import_csv( $recipe_id, $force = false ) {
    $recipe = seo_supplier_crawl_recipe( $recipe_id );
    if ( ! is_array( $recipe ) || ! function_exists( 'seo_proveedores_importar_csv_estandar' ) ) {
        return null;
    }

    $counts = seo_supplier_crawl_record_counts( $recipe_id );
    if ( 0 === $counts['dirty'] ) {
        return null;
    }

    $state      = seo_supplier_crawl_state( $recipe_id );
    $last_flush = absint( $state['last_csv_import_ts'] ?? 0 );
    $interval   = absint( $recipe['csv_flush_interval'] ?? 900 );
    $threshold  = absint( $recipe['csv_flush_rows'] ?? 25 );

    if ( ! $force && ( $counts['dirty'] < $threshold || ( time() - $last_flush ) < $interval ) ) {
        return null;
    }

    $lock_key = 'seo_supplier_crawl_csv_lock_' . sanitize_key( $recipe_id );
    if ( get_transient( $lock_key ) ) {
        return null;
    }
    set_transient( $lock_key, 1, 10 * MINUTE_IN_SECONDS );

    $prepared = seo_supplier_crawl_build_standard_csv( $recipe );
    if ( is_wp_error( $prepared ) ) {
        delete_transient( $lock_key );
        return $prepared;
    }

    $result = seo_proveedores_importar_csv_estandar( $prepared['state'], $prepared['log'] );
    if ( is_wp_error( $result ) ) {
        $state['last_pipeline_error'] = $result->get_error_message();
        seo_supplier_crawl_store_state( $recipe_id, $state );
        delete_transient( $lock_key );
        return $result;
    }

    global $wpdb;
    $table = seo_supplier_crawl_records_table();
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table} SET imported_hash = record_hash WHERE recipe = %s",
            $recipe_id
        )
    );

    $state['last_csv_import_ts']   = time();
    $state['last_csv_import_at']   = current_time( 'mysql' );
    $state['last_csv_filename']    = $prepared['state']['filename'];
    $state['last_csv_rows']        = absint( $prepared['state']['rows_total'] ?? 0 );
    $state['last_pipeline_error']  = '';
    $state['last_import_created']  = absint( $result['creados'] ?? 0 );
    $state['last_import_updated']  = absint( $result['actualizados'] ?? 0 );
    $state['last_import_unchanged']= absint( $result['sin_cambios'] ?? 0 );
    seo_supplier_crawl_store_state( $recipe_id, $state );
    delete_transient( $lock_key );

    return $result;
}

/**
 * Numero de productos ya importados por el flujo comun para un proveedor.
 *
 * @param string $provider Proveedor.
 * @return int
 */
function seo_supplier_crawl_catalog_count( $provider ) {
    if ( ! function_exists( 'seo_proveedores_tabla_productos' ) ) {
        return 0;
    }
    global $wpdb;
    $table = seo_proveedores_tabla_productos();
    return absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE proveedor = %s", sanitize_text_field( $provider ) ) ) );
}

/**
 * Ultimas filas de cola para diagnostico.
 *
 * @param string $recipe_id ID.
 * @param int    $limit Limite.
 * @return array
 */
function seo_supplier_crawl_recent_jobs( $recipe_id, $limit = 20 ) {
    seo_supplier_crawl_install_table();
    global $wpdb;
    $table = seo_supplier_crawl_table();
    $limit = max( 1, min( 100, absint( $limit ) ) );

    return (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id,job_type,status,http_status,url,source,last_error,fetched_at,updated_at
             FROM {$table} WHERE recipe = %s ORDER BY id DESC LIMIT %d",
            sanitize_key( $recipe_id ),
            $limit
        ),
        ARRAY_A
    );
}

/**
 * Selector e informacion del descubrimiento web dentro de Importar proveedor.
 *
 * El administrador elige una receta y pulsa una sola vez Iniciar. Desde ese
 * momento la cola gestiona sola velocidad, reintentos, CSV e importacion comun.
 *
 * @return void
 */
function seo_supplier_crawler_render_inline() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $recipes          = seo_supplier_crawl_recipes();
    $external_recipes = seo_supplier_external_web_recipes();
    if ( empty( $recipes ) && empty( $external_recipes ) ) {
        return;
    }

    $notice         = sanitize_key( wp_unslash( $_GET['seo_crawl_notice'] ?? '' ) );
    $notice_recipe  = sanitize_key( wp_unslash( $_GET['seo_crawl_recipe'] ?? '' ) );
    $notice_message = sanitize_text_field( wp_unslash( $_GET['seo_crawl_message'] ?? '' ) );
    $notice_run     = sanitize_text_field( wp_unslash( $_GET['seo_crawl_run'] ?? '' ) );
    ?>
    <div style="border-top:1px solid #dcdcde;margin-top:24px;padding-top:20px;">
        <h3 style="margin-top:0;">Obtener catalogo desde la web</h3>
        <p>Elige el proveedor y pulsa <strong>Iniciar obtencion</strong>. Las recetas sencillas pueden usar la cola PHP de WordPress; las recetas Python se ejecutan fuera del hosting (por ejemplo, en GitHub Actions). En ambos casos el resultado termina como <strong>CSV estandar</strong> y entra por el mismo importador comun.</p>

        <?php if ( 'reset' === $notice ) : ?>
            <div class="notice notice-success inline"><p><strong>Rastreos PHP reiniciados.</strong> Tareas canceladas, cola y staging vacios. El catalogo comun y los CSV existentes no se han tocado.</p></div>
        <?php elseif ( 'started' === $notice && isset( $recipes[ $notice_recipe ] ) ) : ?>
            <div class="notice notice-success inline"><p><?php echo esc_html( $recipes[ $notice_recipe ]['label'] ); ?>: inicio solicitado. La cola continuara automaticamente.</p></div>
        <?php elseif ( 'external_started' === $notice && isset( $external_recipes[ $notice_recipe ] ) ) : ?>
            <div class="notice notice-success inline"><p><strong><?php echo esc_html( $external_recipes[ $notice_recipe ]['label'] ); ?>:</strong> scraper externo iniciado en GitHub Actions. Puedes cerrar esta pagina; el CSV volvera automaticamente a WordPress.<?php echo '' !== $notice_run ? ' ID remoto: ' . esc_html( $notice_run ) . '.' : ''; ?></p></div>
        <?php elseif ( 'external_error' === $notice ) : ?>
            <div class="notice notice-error inline"><p><strong>No se pudo iniciar el scraper externo.</strong> <?php echo esc_html( $notice_message ); ?></p></div>
        <?php elseif ( 'recipe_missing' === $notice ) : ?>
            <div class="notice notice-error inline"><p>No se encontro la receta web seleccionada.</p></div>
        <?php endif; ?>

        <form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin:14px 0 18px;">
            <?php wp_nonce_field( 'seo_supplier_crawl_start', 'seo_supplier_crawl_nonce' ); ?>
            <label>
                <strong>Proveedor / receta web</strong><br>
                <select name="seo_supplier_crawl_recipe" required style="min-width:360px;">
                    <option value="">Selecciona un proveedor</option>
                    <?php if ( ! empty( $external_recipes ) ) : ?>
                        <optgroup label="Scraper externo">
                            <?php foreach ( $external_recipes as $recipe_id => $recipe ) : ?>
                                <option value="<?php echo esc_attr( 'external:' . $recipe_id ); ?>">
                                    <?php echo esc_html( $recipe['label'] ); ?><?php echo ! empty( $recipe['version'] ) ? ' - v' . esc_html( $recipe['version'] ) : ''; ?> - <?php echo esc_html( strtoupper( (string) $recipe['runner'] ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if ( ! empty( $recipes ) ) : ?>
                        <optgroup label="Rastreo PHP en WordPress">
                            <?php foreach ( $recipes as $recipe_id => $recipe ) : ?>
                                <?php $state = seo_supplier_crawl_state( $recipe_id ); ?>
                                <option value="<?php echo esc_attr( 'crawl:' . $recipe_id ); ?>">
                                    <?php echo esc_html( $recipe['label'] ); ?><?php echo ! empty( $recipe['version'] ) ? ' - v' . esc_html( $recipe['version'] ) : ''; ?><?php echo ! empty( $state['manual_started'] ) ? ' - iniciado' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </label>
            <label>
                <input type="checkbox" name="seo_supplier_catalog_complete" value="1"> Catalogo completo
            </label>
            <button type="submit" name="seo_supplier_crawl_start" value="1" class="button button-primary">Iniciar obtencion</button>
        </form>

        <p class="description">Nada empieza por abrir esta pagina. El proceso solo se activa al elegir una receta y pulsar el boton. En scrapers externos puedes cerrar el navegador: GitHub trabaja por su cuenta y devuelve el CSV al terminar. Deja <strong>Catalogo completo</strong> desmarcado en las pruebas cortas.</p>

        <form method="post" style="margin:12px 0 18px;">
            <?php wp_nonce_field( 'seo_supplier_crawl_reset_all', 'seo_supplier_crawl_reset_nonce' ); ?>
            <button type="submit" name="seo_supplier_crawl_reset_all" value="1" class="button" onclick="return confirm('Esto detendra todos los rastreos y vaciara solo la cola y staging del crawler. No borrara el catalogo comun. ¿Continuar?');">Detener todos y vaciar rastreos</button>
            <span class="description" style="margin-left:8px;">No borra productos del catalogo comun ni CSV ya importados.</span>
        </form>

        <?php
        $started = [];
        foreach ( $recipes as $recipe_id => $recipe ) {
            $state = seo_supplier_crawl_state( $recipe_id );
            if ( ! empty( $state['manual_started'] ) ) {
                $started[ $recipe_id ] = $recipe;
            }
        }
        ?>

        <?php if ( empty( $started ) ) : ?>
            <div style="border:1px solid #dcdcde;border-radius:6px;padding:14px;background:#fff;margin-top:12px;">
                <strong>Ningun proveedor web iniciado.</strong>
                <p class="description" style="margin-bottom:0;">Selecciona una receta arriba para comenzar el primer descubrimiento.</p>
            </div>
        <?php else : ?>
            <h4>Procesos iniciados</h4>
            <?php foreach ( $started as $recipe_id => $recipe ) : ?>
                <?php
                $state         = seo_supplier_crawl_state( $recipe_id );
                $queue_counts  = seo_supplier_crawl_counts( $recipe_id );
                $record_counts = seo_supplier_crawl_record_counts( $recipe_id );
                $catalog_rows  = seo_supplier_crawl_catalog_count( $recipe['provider'] );
                $delay         = seo_supplier_crawl_effective_delay( $recipe, $state );
                $running       = ! empty( $state['enabled'] ) && empty( $state['hard_blocked'] );
                ?>
                <div style="border:1px solid #dcdcde;border-radius:6px;padding:14px;margin-top:12px;background:#fff;">
                    <p style="margin-top:0;">
                        <strong><?php echo esc_html( $recipe['label'] ); ?></strong>
                        - <span style="color:<?php echo $running ? '#008a20' : '#996800'; ?>;"><?php echo esc_html( $running ? 'Trabajando automaticamente' : 'Detenido' ); ?></span>
                    </p>
                    <p>
                        Descubiertos: <strong><?php echo number_format_i18n( $record_counts['total'] ); ?></strong>
                        - Pendientes de CSV: <strong><?php echo number_format_i18n( $record_counts['dirty'] ); ?></strong>
                        - Ya en catalogo comun: <strong><?php echo number_format_i18n( $catalog_rows ); ?></strong>
                        - URLs pendientes: <strong><?php echo number_format_i18n( $queue_counts['pending'] ); ?></strong>
                    </p>
                    <p class="description">
                        Receta: <code><?php echo esc_html( $recipe_id ); ?></code>
                        - Flujo: web publica -> CSV estandar interno -> importador comun -> Catalogo de proveedores.
                        Ritmo actual: <?php echo number_format_i18n( $delay ); ?> s/peticion, gestionado automaticamente.
                    </p>
                    <?php if ( ! empty( $state['last_csv_import_at'] ) ) : ?>
                        <p class="description">Ultimo CSV interno procesado: <code><?php echo esc_html( $state['last_csv_filename'] ?? '' ); ?></code> - <?php echo esc_html( $state['last_csv_import_at'] ); ?> - <?php echo number_format_i18n( absint( $state['last_csv_rows'] ?? 0 ) ); ?> filas.</p>
                    <?php endif; ?>
                    <?php if ( ! empty( $state['last_pipeline_error'] ) ) : ?>
                        <div class="notice notice-warning inline"><p><?php echo esc_html( $state['last_pipeline_error'] ); ?></p></div>
                    <?php elseif ( ! empty( $state['last_message'] ) ) : ?>
                        <p class="description"><?php echo esc_html( $state['last_message'] ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Compatibilidad con enlaces antiguos de la pestaña de rastreo.
 *
 * @return void
 */
function seo_supplier_crawler_render_page() {
    seo_supplier_crawler_render_inline();
}

