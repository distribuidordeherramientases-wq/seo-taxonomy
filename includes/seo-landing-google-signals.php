<?php
/**
 * SEO System - Adaptador Google para Landing Pages.
 *
 * Une fuentes existentes sin duplicar conexiones:
 * - Search Console: Google Intelligence (OAuth + datos locales sincronizados).
 * - Trends: modulo seo-google-trends.php.
 * - Analytics: Google Analytics Data API (cuenta de servicio).
 */

defined( 'ABSPATH' ) || exit;

/*
 * Asegura que el servicio de Google Analytics esté disponible aunque estos
 * archivos se hayan instalado en la raíz del módulo o dentro de /includes.
 * No contiene credenciales: solo localiza y carga la capa de servicio que lee
 * la configuración ya guardada en WordPress.
 */
if ( ! function_exists( 'seo_landing_google_load_analytics_service' ) ) {
    function seo_landing_google_load_analytics_service() {
        if ( function_exists( 'seo_google_search_settings' ) && function_exists( 'seo_google_analytics_run_report' ) ) {
            return [ 'loaded' => true, 'path' => 'already-loaded', 'checked' => [] ];
        }

        $candidates = [
            __DIR__ . '/import-export/suppliers/google-search.php',
            dirname( __DIR__ ) . '/import-export/suppliers/google-search.php',
            __DIR__ . '/google-search.php',
        ];

        if ( defined( 'SEO_SYSTEM_DIR' ) ) {
            $candidates[] = rtrim( (string) SEO_SYSTEM_DIR, '/\\' ) . '/import-export/suppliers/google-search.php';
        }
        if ( defined( 'SEO_SYSTEM_PATH' ) ) {
            $candidates[] = rtrim( (string) SEO_SYSTEM_PATH, '/\\' ) . '/import-export/suppliers/google-search.php';
        }

        $checked = [];
        foreach ( array_unique( $candidates ) as $candidate ) {
            $candidate = wp_normalize_path( (string) $candidate );
            $checked[] = $candidate;
            if ( ! is_readable( $candidate ) ) {
                continue;
            }

            require_once $candidate;
            if ( function_exists( 'seo_google_search_settings' ) && function_exists( 'seo_google_analytics_run_report' ) ) {
                return [ 'loaded' => true, 'path' => $candidate, 'checked' => $checked ];
            }
        }

        return [ 'loaded' => false, 'path' => '', 'checked' => $checked ];
    }
}

$seo_landing_analytics_service_state = seo_landing_google_load_analytics_service();

/** Normaliza una ruta para cruzar GA4/GSC con permalinks WordPress. */
function seo_landing_google_path_key( $url_or_path ) {
    $path = (string) wp_parse_url( (string) $url_or_path, PHP_URL_PATH );
    if ( '' === $path ) {
        $path = (string) $url_or_path;
    }
    $path = '/' . ltrim( $path, '/' );
    return untrailingslashit( $path ) ?: '/';
}

/**
 * Normalizacion ligera para comparar consultas, landings y senales de Trends.
 * No pretende sustituir un modelo semantico: reduce plurales/sinonimos frecuentes
 * y elimina modificadores informativos que no cambian la entidad principal.
 */
function seo_landing_google_text_normalize( $text ) {
    $text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) );
    if ( function_exists( 'remove_accents' ) ) {
        $text = remove_accents( $text );
    }
    $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );

    $aliases = [
        '/\\bcoches?\\b/'          => 'vehiculo',
        '/\\bvehiculos?\\b/'       => 'vehiculo',
        '/\\bautomoviles?\\b/'     => 'vehiculo',
        '/\\bautos?\\b/'           => 'vehiculo',
        '/\\bequipos?\\b/'         => 'equipo',
        '/\\bherramientas?\\b/'    => 'herramienta',
        '/\\baccesorios?\\b/'      => 'accesorio',
        '/\\btransportes?\\b/'     => 'transporte',
        '/\\bprofesionales?\\b/'   => 'profesional',
        '/\\bindustriales?\\b/'    => 'industrial',
        '/\\bremolques?\\b/'       => 'remolque',
        '/\\bneumaticos?\\b/'      => 'neumatico',
        '/\\bbaterias?\\b/'        => 'bateria',
        '/\\btuberias?\\b/'        => 'tuberia',
        '/\\bmaquinas?\\b/'        => 'maquina',
        '/\\bsistemas?\\b/'        => 'sistema',
        '/\\bcargas?\\b/'          => 'carga',
        '/\\bempresas?\\b/'        => 'empresa',
        '/\\bsonido\\b/'           => 'audio',
    ];
    foreach ( $aliases as $pattern => $replacement ) {
        $text = preg_replace( $pattern, $replacement, $text );
    }

    $text = preg_replace( '/[^a-z0-9]+/u', ' ', $text );
    $text = preg_replace( '/\\s+/', ' ', trim( (string) $text ) );
    return (string) $text;
}

/** Tokens de intencion: descarta conectores y modificadores de pregunta. */
function seo_landing_google_text_tokens( $text ) {
    $normalized = seo_landing_google_text_normalize( $text );
    if ( '' === $normalized ) {
        return [];
    }

    $stop = array_fill_keys( [
        'a','al','con','como','cual','cuales','de','del','desde','el','en','entre','es','esta','este','hacer',
        'la','las','lo','los','montar','necesita','necesitas','necesito','obligatorio','online','para','por','que',
        'registrar','segun','sin','su','sus','un','una','unas','unos','vs','y'
    ], true );

    $tokens = [];
    foreach ( preg_split( '/\\s+/', $normalized ) as $token ) {
        $token = trim( (string) $token );
        if ( '' === $token || isset( $stop[$token] ) || strlen( $token ) < 2 ) {
            continue;
        }
        $tokens[$token] = true;
    }
    return array_keys( $tokens );
}

/** Similitud 0..1 orientada a detectar intenciones/entidades equivalentes. */
function seo_landing_google_similarity( $left, $right ) {
    $a = seo_landing_google_text_normalize( $left );
    $b = seo_landing_google_text_normalize( $right );
    if ( '' === $a || '' === $b ) {
        return 0.0;
    }
    if ( $a === $b ) {
        return 1.0;
    }

    $ta = seo_landing_google_text_tokens( $a );
    $tb = seo_landing_google_text_tokens( $b );
    if ( empty( $ta ) || empty( $tb ) ) {
        return 0.0;
    }

    $intersection = array_values( array_intersect( $ta, $tb ) );
    $shared = count( $intersection );
    if ( 0 === $shared ) {
        return 0.0;
    }

    $union = count( array_unique( array_merge( $ta, $tb ) ) );
    $jaccard = $union > 0 ? $shared / $union : 0;
    $coverage_a = $shared / count( $ta );
    $coverage_b = $shared / count( $tb );
    similar_text( $a, $b, $sequence_percent );
    $sequence = max( 0, min( 1, (float) $sequence_percent / 100 ) );

    $score = ( $jaccard * 0.50 ) + ( max( $coverage_a, $coverage_b ) * 0.35 ) + ( $sequence * 0.15 );

    // Una expresion corta totalmente contenida en otra suele ser la misma entidad.
    $smaller_count = min( count( $ta ), count( $tb ) );
    $smaller_coverage = count( $ta ) <= count( $tb ) ? $coverage_a : $coverage_b;
    if ( $smaller_count >= 2 && $smaller_coverage >= 0.999 ) {
        $score = max( $score, 0.92 );
    } elseif ( $shared >= 2 && $smaller_coverage >= 0.66 ) {
        $score = max( $score, 0.84 );
    }

    return round( min( 1, $score ), 4 );
}

/** Firma estable para deduplicar variaciones de orden y sinonimos basicos. */
function seo_landing_google_cluster_key( $text ) {
    $tokens = seo_landing_google_text_tokens( $text );
    sort( $tokens, SORT_STRING );
    return implode( '|', $tokens );
}

/** Busca una landing ya existente que probablemente resuelva la misma intencion. */
function seo_landing_google_existing_destination_match( $label ) {
    if ( ! function_exists( 'seo_landing_get_existing' ) ) {
        return [];
    }

    static $existing_landings = null;
    if ( null === $existing_landings ) {
        $existing_landings = (array) seo_landing_get_existing();
    }

    $best = [];
    foreach ( $existing_landings as $landing ) {
        $title = (string) ( $landing->post_title ?? '' );
        if ( '' === $title ) {
            continue;
        }
        $similarity = seo_landing_google_similarity( $label, $title );
        if ( empty( $best ) || $similarity > (float) ( $best['similarity'] ?? 0 ) ) {
            $best = [
                'id'         => absint( $landing->ID ?? 0 ),
                'title'      => $title,
                'similarity' => $similarity,
            ];
        }
    }

    return ! empty( $best ) && (float) $best['similarity'] >= 0.84 ? $best : [];
}

/** Lee el primer contador numerico disponible sin asumir el esquema del proveedor. */
function seo_landing_google_first_numeric( $item, $keys ) {
    foreach ( (array) $keys as $key ) {
        if ( isset( $item[$key] ) && is_numeric( $item[$key] ) ) {
            return (float) $item[$key];
        }
    }
    return null;
}

/** Evidencia de catalogo solo cuando el proveedor aporta contadores reconocibles. */
function seo_landing_google_catalog_evidence( $item ) {
    $products = seo_landing_google_first_numeric( $item, [ 'product_count', 'products_count', 'catalog_products', 'matched_products', 'products' ] );
    $categories = seo_landing_google_first_numeric( $item, [ 'category_count', 'categories_count', 'catalog_categories', 'matched_categories', 'categories' ] );

    $score = 0;
    if ( null !== $products ) {
        if ( $products >= 100 ) {
            $score = 15;
        } elseif ( $products >= 40 ) {
            $score = 13;
        } elseif ( $products >= 20 ) {
            $score = 11;
        } elseif ( $products >= 8 ) {
            $score = 8;
        } elseif ( $products >= 4 ) {
            $score = 5;
        } elseif ( $products > 0 ) {
            $score = 2;
        }
    }

    return [
        'products'         => $products,
        'categories'       => $categories,
        'score'            => $score,
        'unites_catalog'   => null !== $categories && $categories >= 2 ? 1 : -1,
        'enough_coverage'  => null !== $products && $products >= 8 ? 1 : -1,
    ];
}

/** Mejor coincidencia Trends: exacta primero y despues por similitud semantica ligera. */
function seo_landing_google_trend_match( $label, $trend_rows ) {
    $needle = seo_landing_google_text_normalize( $label );
    $best = [];
    foreach ( (array) $trend_rows as $trend ) {
        $query = (string) ( $trend['query'] ?? '' );
        if ( '' === $query ) {
            continue;
        }
        $normalized = function_exists( 'seo_google_trends_normalize' )
            ? (string) seo_google_trends_normalize( $query )
            : seo_landing_google_text_normalize( $query );
        $similarity = $needle === seo_landing_google_text_normalize( $normalized )
            ? 1.0
            : seo_landing_google_similarity( $label, $query );
        if ( empty( $best ) || $similarity > (float) ( $best['similarity'] ?? 0 ) ) {
            $best = [ 'row' => $trend, 'similarity' => $similarity ];
        }
    }
    return ! empty( $best ) && (float) $best['similarity'] >= 0.82 ? $best : [];
}

/** Devuelve estado resumido de las tres fuentes Google. */
function seo_landing_google_source_status() {
    $status = [
        'search_console' => [ 'connected' => false, 'detail' => 'No disponible' ],
        'analytics'      => [ 'connected' => false, 'detail' => 'No disponible' ],
        'trends'         => [ 'connected' => false, 'detail' => 'Sin datos importados' ],
    ];

    if ( function_exists( 'seo_google_connection_status' ) && function_exists( 'seo_google_get_settings' ) ) {
        $gsc_settings = seo_google_get_settings();
        $connected = 'connected' === seo_google_connection_status();
        $latest = ( $connected && ! empty( $gsc_settings['property_id'] ) && function_exists( 'seo_google_latest_data_date' ) )
            ? seo_google_latest_data_date( $gsc_settings['property_id'] )
            : '';
        $status['search_console'] = [
            'connected' => $connected && ! empty( $latest ),
            'detail'    => $connected
                ? ( $latest ? 'Sincronizado hasta ' . $latest : 'Conectado; falta sincronizar datos' )
                : 'Google Intelligence no esta conectado completamente',
        ];
    }

    $analytics_service = function_exists( 'seo_landing_google_load_analytics_service' )
        ? seo_landing_google_load_analytics_service()
        : [ 'loaded' => false, 'path' => '', 'checked' => [] ];

    if ( ! empty( $analytics_service['loaded'] ) && function_exists( 'seo_google_search_settings' ) ) {
        $settings = seo_google_search_settings();
        $configured = ! empty( $settings['analytics_property_id'] ) && ! empty( $settings['service_account_json'] );

        if ( $configured && function_exists( 'seo_google_analytics_diagnostic' ) ) {
            $diagnostic = seo_google_analytics_diagnostic();
            $status['analytics'] = [
                'connected' => ! empty( $diagnostic['ok'] ),
                'detail'    => (string) ( $diagnostic['message'] ?? 'Analytics Data API configurada' ),
            ];
        } elseif ( $configured && function_exists( 'seo_google_analytics_run_report' ) ) {
            // Compatibilidad si el servicio instalado aún no expone el helper de diagnóstico.
            $probe = seo_google_analytics_run_report(
                [
                    'dateRanges' => [ [ 'startDate' => '7daysAgo', 'endDate' => 'today' ] ],
                    'metrics'    => [ [ 'name' => 'sessions' ] ],
                    'limit'      => 1,
                ]
            );

            if ( is_wp_error( $probe ) ) {
                $status['analytics'] = [
                    'connected' => false,
                    'detail'    => 'Error Analytics: ' . $probe->get_error_message(),
                ];
            } else {
                $sessions = 0;
                if ( ! empty( $probe['rows'][0]['metricValues'][0]['value'] ) ) {
                    $sessions = (int) $probe['rows'][0]['metricValues'][0]['value'];
                }
                $status['analytics'] = [
                    'connected' => true,
                    'detail'    => 'Analytics responde correctamente; sesiones ultimos 7 dias: ' . number_format_i18n( $sessions ),
                ];
            }
        } else {
            $status['analytics'] = [
                'connected' => false,
                'detail'    => $configured ? 'Servicio Analytics no cargado en esta pantalla' : 'Falta Property ID o cuenta de servicio',
            ];
        }
    } else {
        $checked = ! empty( $analytics_service['checked'] ) && is_array( $analytics_service['checked'] )
            ? implode( ' | ', array_map( 'basename', $analytics_service['checked'] ) )
            : 'sin rutas disponibles';
        $status['analytics'] = [
            'connected' => false,
            'detail'    => 'Servicio google-search.php no cargado. Revisar instalación del paquete (rutas comprobadas: ' . $checked . ').',
        ];
    }

    if ( function_exists( 'seo_google_trends_provider_status' ) ) {
        $trends_status = (array) seo_google_trends_provider_status();
        $radar = is_array( $trends_status['radar'] ?? null ) ? $trends_status['radar'] : [];
        $market = is_array( $trends_status['market'] ?? null ) ? $trends_status['market'] : [];
        $radar_ok = ! empty( $radar['connected'] );

        $status['trends'] = [
            // Indica si la descarga automática actual está operativa. Los CSV
            // almacenados se detallan aparte y no ocultan un fallo de conexión.
            'connected' => $radar_ok,
            'detail'    => 'Radar automático: ' . (string) ( $radar['detail'] ?? 'sin diagnóstico' )
                . ' Explore/mercado: ' . (string) ( $market['detail'] ?? 'sin diagnóstico' ),
        ];
    } elseif ( function_exists( 'seo_google_trends_get_signals' ) ) {
        // Compatibilidad con instalaciones que todavía carguen el proveedor anterior.
        $rows = (array) seo_google_trends_get_signals( 5 );
        $status['trends'] = [
            'connected' => ! empty( $rows ),
            'detail'    => ! empty( $rows )
                ? 'Datos de Trends disponibles mediante el módulo anterior.'
                : 'Módulo de Trends cargado, pero sin señales ni diagnóstico estructurado.',
        ];
    } else {
        $status['trends'] = [
            'connected' => false,
            'detail'    => 'Módulo seo-google-trends.php no cargado; el sistema continuará con Search Console + catálogo.',
        ];
    }

    return $status;
}

/**
 * Reporte GA4 agregado por pagePath, cacheado para no golpear la API en cada carga.
 */
function seo_landing_google_analytics_page_map( $force = false ) {
    $cache_key = 'seo_landing_ga4_page_map_v1';
    if ( ! $force ) {
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
    }

    if ( ! function_exists( 'seo_google_analytics_run_report' ) ) {
        return [];
    }

    $report = seo_google_analytics_run_report(
        [
            'dateRanges' => [ [ 'startDate' => '30daysAgo', 'endDate' => 'today' ] ],
            'dimensions' => [ [ 'name' => 'pagePath' ] ],
            'metrics'    => [
                [ 'name' => 'sessions' ],
                [ 'name' => 'activeUsers' ],
                [ 'name' => 'screenPageViews' ],
            ],
            'limit' => 10000,
        ]
    );

    if ( is_wp_error( $report ) ) {
        set_transient( 'seo_landing_ga4_last_error_v1', $report->get_error_message(), 10 * MINUTE_IN_SECONDS );
        return [];
    }

    if ( empty( $report['rows'] ) || ! is_array( $report['rows'] ) ) {
        set_transient( 'seo_landing_ga4_last_error_v1', 'Analytics no devolvio filas para la dimension pagePath.', 10 * MINUTE_IN_SECONDS );
        return [];
    }

    delete_transient( 'seo_landing_ga4_last_error_v1' );

    $map = [];
    foreach ( $report['rows'] as $row ) {
        $path = $row['dimensionValues'][0]['value'] ?? '';
        if ( '' === $path ) {
            continue;
        }
        $key = seo_landing_google_path_key( $path );
        $map[$key] = [
            'sessions'   => (int) ( $row['metricValues'][0]['value'] ?? 0 ),
            'users'      => (int) ( $row['metricValues'][1]['value'] ?? 0 ),
            'pageviews'  => (int) ( $row['metricValues'][2]['value'] ?? 0 ),
        ];
    }

    set_transient( $cache_key, $map, 15 * MINUTE_IN_SECONDS );
    return $map;
}

/** Search Console ya sincronizado, agregado por URL para 28 dias. */
function seo_landing_google_search_console_page_map() {
    if (
        ! function_exists( 'seo_google_connection_status' )
        || 'connected' !== seo_google_connection_status()
        || ! function_exists( 'seo_google_get_settings' )
        || ! function_exists( 'seo_google_get_analysis_period' )
        || ! function_exists( 'seo_google_get_all_page_metrics' )
    ) {
        return [];
    }

    $settings = seo_google_get_settings();
    if ( empty( $settings['property_id'] ) ) {
        return [];
    }

    $period = seo_google_get_analysis_period( $settings['property_id'], 28 );
    if ( empty( $period['current_from'] ) || empty( $period['current_to'] ) ) {
        return [];
    }

    $rows = seo_google_get_all_page_metrics(
        $settings['property_id'],
        $period['current_from'],
        $period['current_to'],
        10000
    );

    $map = [];
    foreach ( (array) $rows as $row ) {
        $key = seo_landing_google_path_key( $row['page_url'] ?? '' );
        $map[$key] = [
            'clicks'      => (int) ( $row['clicks'] ?? 0 ),
            'impressions' => (int) ( $row['impressions'] ?? 0 ),
            'queries'     => (int) ( $row['queries'] ?? 0 ),
            'position'    => (float) ( $row['position'] ?? 0 ),
        ];
    }

    return $map;
}

/** Metricas externas para una landing concreta. */
function seo_landing_google_metrics_for_page( $page_id ) {
    $url = get_permalink( absint( $page_id ) );
    if ( ! $url ) {
        return [ 'ga4' => [], 'gsc' => [] ];
    }

    $key = seo_landing_google_path_key( $url );
    $ga4 = seo_landing_google_analytics_page_map();
    $gsc = seo_landing_google_search_console_page_map();

    return [
        'ga4' => $ga4[$key] ?? [],
        'gsc' => $gsc[$key] ?? [],
    ];
}

/**
 * Convierte las decisiones de landing del motor V2 al inventario editorial.
 * Una landing existente se registra como solapamiento: nunca autoriza otra URL.
 */
function seo_landing_google_candidate_signals_v2( $signals ) {
    $signals = is_array( $signals ) ? $signals : [];
    $payload = (array) seo_google_opportunity_build( 60, false );

    foreach ( (array) ( $payload['rows'] ?? [] ) as $row ) {
        $action = (string) ( $row['action'] ?? '' );
        if ( ! in_array( $action, [ 'POTENCIAR_LANDING', 'ESTUDIAR_LANDING' ], true ) ) {
            continue;
        }

        $title = sanitize_text_field( $row['topic'] ?? '' );
        if ( '' === $title ) {
            continue;
        }

        $target = is_array( $row['target'] ?? null ) ? $row['target'] : [];
        $catalog = is_array( $row['catalog'] ?? null ) ? $row['catalog'] : [];
        $metrics = is_array( $row['metrics'] ?? null ) ? $row['metrics'] : [];
        $market = is_array( $row['market'] ?? null ) ? $row['market'] : [];
        $sources = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $row['sources'] ?? [] ) ) ) );

        $products = max( 0, (int) ( $catalog['products'] ?? 0 ) );
        $priority = max( 0, min( 100, (float) ( $row['priority'] ?? 0 ) ) );
        $search_score = max( 0, min( 100, (float) ( $metrics['search_score'] ?? 0 ) ) );
        $market_score = max( 0, min( 100, (float) ( $market['score'] ?? $metrics['market_score'] ?? 0 ) ) );
        $impressions = max( 0, (float) ( $metrics['impressions'] ?? 0 ) );
        $position = max( 0, (float) ( $metrics['position'] ?? 0 ) );
        $has_target = ! empty( $target['id'] ) || ! empty( $target['url'] ) || ! empty( $target['title'] );
        $has_catalog_context = $products > 0 || ! empty( $catalog['category'] ) || ! empty( $catalog['term_id'] );
        $has_search_evidence = $search_score >= 35 || $impressions >= 5 || in_array( 'Search Console', $sources, true );
        $has_market_evidence = $market_score >= 55 || in_array( 'Google Trends', $sources, true );

        $visibility_score = 0.0;
        if ( $impressions > 0 ) {
            $visibility_score = min( 7, log( 1 + $impressions ) * 1.15 );
        }
        if ( $position >= 11 ) {
            $visibility_score = min( 10, $visibility_score + 3 );
        }

        $existing_text = '';
        if ( $has_target ) {
            $existing_text = trim(
                ( ! empty( $target['id'] ) ? '#' . absint( $target['id'] ) . ' ' : '' )
                . (string) ( $target['title'] ?? '' )
                . ( ! empty( $target['similarity'] ) ? ' (similitud ' . round( (float) $target['similarity'] * 100 ) . '%)' : '' )
            );
        }

        $evidence = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $row['evidence'] ?? [] ) ) ) );
        $intent_text = trim( (string) ( $row['reason'] ?? '' ) );
        if ( $evidence ) {
            $intent_text .= ( '' !== $intent_text ? ' ' : '' ) . 'Evidencias: ' . implode( ', ', array_slice( $evidence, 0, 4 ) ) . '.';
        }

        $signals[] = [
            'title'        => $title,
            'intent'       => $intent_text,
            'landing_type' => 'need',
            'source'       => 'Motor de oportunidades V2' . ( $sources ? ' · ' . implode( ' + ', $sources ) : '' ),
            // Mantiene el prefijo histórico para que la limpieza de señales
            // obsoletas y la compatibilidad con candidatas anteriores funcionen.
            'external_key' => 'google_landing_v2_' . md5( seo_landing_google_text_normalize( $title ) ),
            'requirements' => [
                'differentiated_intent' => ( 'POTENCIAR_LANDING' === $action || $has_target ) ? 0 : 1,
                'unites_catalog'        => $has_catalog_context ? 1 : -1,
                'enough_coverage'       => $products >= 8 ? 1 : -1,
                'purchase_utility'      => ( $has_search_evidence || $has_market_evidence ) ? 1 : -1,
                'stable_destination'    => ( $has_target || ( $has_catalog_context && $products >= 8 ) ) ? 1 : -1,
            ],
            'scores' => [
                'commercial' => round( min( 30, $priority * 0.30 ), 1 ),
                'organic'    => round( min( 25, $search_score > 0 ? $search_score * 0.25 : $priority * 0.18 ), 1 ),
                'demand'     => round( min( 15, $market_score > 0 ? $market_score * 0.15 : log( 1 + $impressions ) * 1.7 ), 1 ),
                'catalog'    => round( min( 15, $products > 0 ? log( 1 + $products ) * 4.1 : ( $has_catalog_context ? 4 : 0 ) ), 1 ),
                'visibility' => round( $visibility_score, 1 ),
                'editorial'  => 3,
            ],
            'existing_destination'   => $existing_text,
            'differentiation_reason' => 'POTENCIAR_LANDING' === $action
                ? 'Reforzar la URL existente; no crear otra landing para la misma intención.'
                : 'Validar manualmente que la intención, el destino y la cobertura justifican una URL independiente.',
        ];
    }

    return $signals;
}

/**
 * Convierte las oportunidades ya calculadas por Demanda x Catalogo en senales
 * para el gestor de landings. Se mantienen como detectadas hasta revision
 * manual; Google no puede demostrar por si solo los cinco requisitos editoriales.
 */
function seo_landing_google_candidate_signals( $signals ) {
    $signals = is_array( $signals ) ? $signals : [];

    if ( function_exists( 'seo_google_opportunity_build' ) ) {
        return seo_landing_google_candidate_signals_v2( $signals );
    }

    if (
        ! function_exists( 'seo_google_get_settings' )
        || ! function_exists( 'seo_google_demand_get_catalog_guidance' )
        || ! function_exists( 'seo_google_connection_status' )
        || 'connected' !== seo_google_connection_status()
    ) {
        return $signals;
    }

    $settings = seo_google_get_settings();
    if ( empty( $settings['property_id'] ) ) {
        return $signals;
    }

    $guidance = seo_google_demand_get_catalog_guidance( $settings['property_id'], 60, 2, 30 );
    $items = is_array( $guidance['items'] ?? null ) ? $guidance['items'] : [];

    $trend_rows = function_exists( 'seo_google_trends_market_summary' )
        ? (array) seo_google_trends_market_summary( 250 )
        : [];
    $has_trends = ! empty( $trend_rows );

    // Se agrupan variaciones equivalentes antes de exponerlas al inventario.
    $generated = [];

    foreach ( $items as $item ) {
        if ( 'Intencion' !== ( $item['kind'] ?? '' ) ) {
            continue;
        }
        $decision = (string) ( $item['decision'] ?? '' );
        if ( ! in_array( $decision, [ 'OPORTUNIDAD ESTRUCTURAL', 'REFORZAR / MAPEAR', 'POTENCIAR SEO' ], true ) ) {
            continue;
        }

        $label = sanitize_text_field( $item['label'] ?? '' );
        if ( '' === $label ) {
            continue;
        }

        $trend_score = 0;
        $trend_match = $has_trends ? seo_landing_google_trend_match( $label, $trend_rows ) : [];
        if ( ! empty( $trend_match['row'] ) ) {
            $trend_score = min( 15, max( 0, (float) ( $trend_match['row']['score'] ?? 0 ) * 0.15 ) );
        }

        $organic_score = min( 25, max( 0, (float) ( $item['score'] ?? 0 ) * 0.25 ) );
        $actionable = (float) ( $item['actionable'] ?? 0 );
        $commercial_score = min( 30, max( 0, log( 1 + max( 0, $actionable ) ) * 5 ) );
        $catalog = seo_landing_google_catalog_evidence( $item );

        $impressions = seo_landing_google_first_numeric( $item, [ 'impressions', 'current_impressions', 'gsc_impressions' ] );
        $visibility_score = 0;
        if ( null !== $impressions ) {
            $visibility_score = min( 10, max( 0, log( 1 + max( 0, $impressions ) ) * 1.35 ) );
        }

        $tokens = seo_landing_google_text_tokens( $label );
        $editorial_score = count( $tokens ) >= 4 ? 4 : ( count( $tokens ) >= 2 ? 3 : 1 );

        $existing = seo_landing_google_existing_destination_match( $label );
        $has_existing = ! empty( $existing );
        $existing_text = $has_existing
            ? sprintf( '#%d %s (similitud %d%%)', (int) $existing['id'], (string) $existing['title'], round( (float) $existing['similarity'] * 100 ) )
            : '';

        // -1 = pendiente/no demostrable automaticamente; 0 = evidencia de que falla; 1 = evidencia favorable.
        $requirements = [
            'differentiated_intent' => $has_existing
                ? 0
                : ( 'OPORTUNIDAD ESTRUCTURAL' === $decision ? 1 : -1 ),
            'unites_catalog'        => (int) $catalog['unites_catalog'],
            'enough_coverage'       => (int) $catalog['enough_coverage'],
            'purchase_utility'      => $actionable >= 5 ? 1 : -1,
            'stable_destination'    => 1,
        ];

        $reason = $has_existing
            ? 'SOLAPAMIENTO PROBABLE: reforzar/ampliar la landing existente antes de crear una nueva.'
            : sanitize_textarea_field( $decision );

        $cluster_signature = seo_landing_google_cluster_key( $label );
        if ( '' === $cluster_signature ) {
            $cluster_signature = seo_landing_google_text_normalize( $label );
        }

        $candidate = [
            'title'        => $label,
            'intent'       => sanitize_textarea_field( $item['note'] ?? '' ),
            'landing_type' => 'need',
            'source'       => $has_trends ? 'Google Intelligence + Trends' : 'Google Intelligence / Search Console',
            'external_key' => 'google_landing_' . md5( $cluster_signature ),
            'requirements' => $requirements,
            'scores' => [
                'commercial' => round( $commercial_score, 1 ),
                'organic'    => round( $organic_score, 1 ),
                'demand'     => round( $trend_score, 1 ),
                'catalog'    => round( (float) $catalog['score'], 1 ),
                'visibility' => round( $visibility_score, 1 ),
                'editorial'  => round( min( 5, $editorial_score ), 1 ),
            ],
            'existing_destination'   => $existing_text,
            'differentiation_reason' => $reason,
            '_rank'                   => (float) ( $item['score'] ?? 0 ) + $actionable,
            '_variants'               => [ $label ],
            '_existing_similarity'    => (float) ( $existing['similarity'] ?? 0 ),
        ];

        $merged_index = null;
        foreach ( $generated as $index => $previous ) {
            if ( seo_landing_google_similarity( $candidate['title'], $previous['title'] ) >= 0.84 ) {
                $merged_index = $index;
                break;
            }
        }

        if ( null === $merged_index ) {
            $generated[] = $candidate;
            continue;
        }

        $previous = $generated[$merged_index];
        $variants = array_values( array_unique( array_merge( (array) $previous['_variants'], [ $label ] ) ) );

        // Conserva como titulo la variante con mayor evidencia, pero una clave estable entre variantes.
        if ( (float) $candidate['_rank'] > (float) $previous['_rank'] ) {
            $previous['title'] = $candidate['title'];
            $previous['intent'] = $candidate['intent'];
            $previous['_rank'] = $candidate['_rank'];
        }
        $previous['external_key'] = strcmp( $previous['external_key'], $candidate['external_key'] ) <= 0
            ? $previous['external_key']
            : $candidate['external_key'];
        $previous['source'] = ( 'Google Intelligence + Trends' === $candidate['source'] || 'Google Intelligence + Trends' === $previous['source'] )
            ? 'Google Intelligence + Trends'
            : 'Google Intelligence / Search Console';

        foreach ( $previous['scores'] as $score_key => $score_value ) {
            $previous['scores'][$score_key] = max( (float) $score_value, (float) ( $candidate['scores'][$score_key] ?? 0 ) );
        }
        foreach ( $previous['requirements'] as $req_key => $req_value ) {
            $other = (int) ( $candidate['requirements'][$req_key] ?? -1 );
            // Un NO por solapamiento tiene prioridad; si no, una evidencia positiva prevalece sobre pendiente.
            if ( 0 === (int) $req_value || 0 === $other ) {
                $previous['requirements'][$req_key] = 0;
            } elseif ( 1 === (int) $req_value || 1 === $other ) {
                $previous['requirements'][$req_key] = 1;
            } else {
                $previous['requirements'][$req_key] = -1;
            }
        }

        if ( (float) $candidate['_existing_similarity'] > (float) $previous['_existing_similarity'] ) {
            $previous['existing_destination'] = $candidate['existing_destination'];
            $previous['differentiation_reason'] = $candidate['differentiation_reason'];
            $previous['_existing_similarity'] = $candidate['_existing_similarity'];
        }
        $previous['_variants'] = $variants;
        $generated[$merged_index] = $previous;
    }

    foreach ( $generated as $candidate ) {
        if ( count( $candidate['_variants'] ) > 1 ) {
            $variant_text = 'Variantes agrupadas: ' . implode( ', ', $candidate['_variants'] ) . '.';
            $candidate['intent'] = trim( $candidate['intent'] . ' ' . $variant_text );
        }
        unset( $candidate['_rank'], $candidate['_variants'], $candidate['_existing_similarity'] );
        $signals[] = $candidate;
    }

    return $signals;
}
add_filter( 'seo_landing_candidate_signals', 'seo_landing_google_candidate_signals', 20 );
