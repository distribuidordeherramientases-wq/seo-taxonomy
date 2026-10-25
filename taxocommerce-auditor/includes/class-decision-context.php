<?php
/**
 * Persisted decision context for each Auditor finding.
 *
 * This class only reads local state already stored by the TaxoCommerce family.
 * It never calls Google, Bing, Merchant or another remote provider.
 */

namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

final class Decision_Context {
    const SNAPSHOT_TRANSIENT = 'taxocommerce_auditor_context_snapshot_v2';
    const SNAPSHOT_TTL = 15 * MINUTE_IN_SECONDS;

    private static $snapshot = null;

    private static function normalize_type( $type ) {
        $type = sanitize_key( (string) $type );
        return 'product_cat' === $type ? 'category' : $type;
    }

    private static function entity_key( $type, $id ) {
        $type = self::normalize_type( $type );
        $id = absint( $id );
        return $type && $id ? $type . '|' . $id : '';
    }

    private static function target_key( $type, $id ) {
        $type = sanitize_key( (string) $type );
        if ( 'product_cat' === $type ) {
            $type = 'category';
        }
        return self::entity_key( $type, $id );
    }

    private static function state_reference( $available, $state, $detail ) {
        return array(
            'state'     => $available ? sanitize_key( (string) $state ) : 'unavailable',
            'available' => (bool) $available,
            'detail'    => (string) $detail,
        );
    }

    private static function build_snapshot() {
        $snapshot = array(
            'generated_at' => current_time( 'mysql' ),
            'insights' => self::state_reference( false, 'unavailable', 'TaxoCommerce Insights no está disponible.' ),
            'insights_targets' => array(),
            'market_categories' => array(),
            'dependiente' => self::dependiente_global(),
            'suppliers' => self::state_reference( class_exists( 'TCSUP_Schema' ), class_exists( 'TCSUP_Schema' ) ? 'fresh' : 'unavailable', class_exists( 'TCSUP_Schema' ) ? 'TaxoCommerce Suppliers disponible.' : 'TaxoCommerce Suppliers no está disponible.' ),
            'comparador' => self::state_reference( false, 'unavailable', 'No hay perfiles persistidos de Comparador disponibles.' ),
            'comparador_categories' => array(),
        );

        $comparador = self::comparador_snapshot();
        $snapshot['comparador'] = (array) ( $comparador['state'] ?? $snapshot['comparador'] );
        $snapshot['comparador_categories'] = (array) ( $comparador['categories'] ?? array() );

        if ( class_exists( 'TaxoCommerce_Google_Bing_Analyst' ) && method_exists( 'TaxoCommerce_Google_Bing_Analyst', 'report' ) ) {
            $report = (array) \TaxoCommerce_Google_Bing_Analyst::report();
            $snapshot['insights'] = self::state_reference(
                true,
                'fresh',
                'Snapshot local de TaxoCommerce Insights/Analyst; Auditor no descarga datos externos.'
            );

            foreach ( (array) ( $report['direction']['rows'] ?? array() ) as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $target = (array) ( $row['target'] ?? array() );
                $key = self::target_key( $target['type'] ?? '', $target['id'] ?? 0 );
                if ( '' === $key ) {
                    continue;
                }
                $candidate = array(
                    'state'            => 'fresh',
                    'available'        => true,
                    'priority'         => isset( $row['priority'] ) ? absint( $row['priority'] ) : null,
                    'bucket'           => (string) ( $row['bucket'] ?? '' ),
                    'action'           => (string) ( $row['action'] ?? '' ),
                    'action_label'     => (string) ( $row['action_label'] ?? '' ),
                    'channel'          => (string) ( $row['channel'] ?? '' ),
                    'reason'           => (string) ( $row['reason'] ?? '' ),
                    'confidence'       => (string) ( $row['confidence'] ?? '' ),
                    'confidence_score' => isset( $row['confidence_score'] ) ? absint( $row['confidence_score'] ) : null,
                    'metrics'          => (array) ( $row['metrics'] ?? array() ),
                    'sources'          => array_values( array_slice( (array) ( $row['sources'] ?? array() ), 0, 8 ) ),
                );
                if ( ! isset( $snapshot['insights_targets'][ $key ] )
                    || absint( $candidate['priority'] ) > absint( $snapshot['insights_targets'][ $key ]['priority'] ?? 0 ) ) {
                    $snapshot['insights_targets'][ $key ] = $candidate;
                }
            }

            foreach ( (array) ( $report['market']['categories'] ?? array() ) as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }
                $term_id = absint( $row['term_id'] ?? 0 );
                if ( ! $term_id ) {
                    continue;
                }
                $snapshot['market_categories'][ $term_id ] = array(
                    'state'             => 'fresh',
                    'available'         => true,
                    'term_id'           => $term_id,
                    'category'          => (string) ( $row['name'] ?? '' ),
                    'country'           => (string) ( $row['country'] ?? '' ),
                    'scan_status'       => (string) ( $row['scan_status'] ?? '' ),
                    'last_scanned_at'   => $row['last_scanned_at'] ?? null,
                    'evidence'          => absint( $row['evidence'] ?? 0 ),
                    'external_evidence' => absint( $row['external_evidence'] ?? 0 ),
                    'merchant_signals'  => absint( $row['merchant'] ?? 0 ),
                    'offers'            => absint( $row['offers'] ?? 0 ),
                    'providers'         => array_values( array_slice( (array) ( $row['providers'] ?? array() ), 0, 8 ) ),
                );
            }
        }

        return $snapshot;
    }

    private static function snapshot() {
        if ( is_array( self::$snapshot ) ) {
            return self::$snapshot;
        }
        $cached = get_transient( self::SNAPSHOT_TRANSIENT );
        if ( is_array( $cached ) && ! empty( $cached['generated_at'] ) ) {
            self::$snapshot = $cached;
            return self::$snapshot;
        }
        self::$snapshot = self::build_snapshot();
        set_transient( self::SNAPSHOT_TRANSIENT, self::$snapshot, self::SNAPSHOT_TTL );
        return self::$snapshot;
    }

    private static function category_ids( $type, $id ) {
        $type = self::normalize_type( $type );
        $id = absint( $id );
        if ( ! $id ) {
            return array();
        }
        if ( 'category' === $type ) {
            return array( $id );
        }
        if ( 'faq' === $type ) {
            $faq = FAQ_Source::get( $id );
            if ( $faq instanceof FAQ_Record && $faq->product_cat_id ) {
                return array( absint( $faq->product_cat_id ) );
            }
            return array();
        }
        if ( 'product' === $type ) {
            $ids = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
            return is_wp_error( $ids )
                ? array()
                : array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
        }

        $ids = apply_filters( 'taxocommerce_auditor_entity_category_ids', array(), $type, $id );
        return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
    }

    private static function insights_context( $snapshot, $type, $id, $category_ids ) {
        $key = self::entity_key( $type, $id );
        if ( $key && isset( $snapshot['insights_targets'][ $key ] ) ) {
            return $snapshot['insights_targets'][ $key ];
        }

        $rows = array();
        foreach ( (array) $category_ids as $term_id ) {
            $category_key = self::entity_key( 'category', $term_id );
            if ( isset( $snapshot['insights_targets'][ $category_key ] ) ) {
                $rows[] = $snapshot['insights_targets'][ $category_key ];
            }
        }
        if ( $rows ) {
            usort( $rows, static function ( $a, $b ) {
                return absint( $b['priority'] ?? 0 ) <=> absint( $a['priority'] ?? 0 );
            } );
            return array(
                'state'     => 'fresh',
                'available' => true,
                'source'    => 'category_context',
                'rows'      => array_slice( $rows, 0, 8 ),
                'priority'  => absint( $rows[0]['priority'] ?? 0 ),
            );
        }
        return (array) ( $snapshot['insights'] ?? self::state_reference( false, 'unavailable', '' ) );
    }

    private static function market_context( $snapshot, $category_ids ) {
        $rows = array();
        foreach ( (array) $category_ids as $term_id ) {
            if ( isset( $snapshot['market_categories'][ $term_id ] ) ) {
                $rows[] = $snapshot['market_categories'][ $term_id ];
            }
        }
        if ( $rows ) {
            return array(
                'state'     => 'fresh',
                'available' => true,
                'source'    => 'TaxoCommerce Insights Market',
                'rows'      => array_slice( $rows, 0, 8 ),
            );
        }
        return self::state_reference(
            ! empty( $snapshot['insights']['available'] ),
            ! empty( $snapshot['insights']['available'] ) ? 'partial' : 'unavailable',
            ! empty( $snapshot['insights']['available'] )
                ? 'Insights está disponible, pero no hay evidencia de mercado guardada para las categorías de esta entidad.'
                : 'TaxoCommerce Insights no está disponible.'
        );
    }

    private static function dependiente_global() {
        if ( ! class_exists( 'TCD_Feedback' ) || ! method_exists( 'TCD_Feedback', 'stats' ) ) {
            return self::state_reference( false, 'unavailable', 'TaxoCommerce Assistant/Dependiente no está disponible.' );
        }
        $stats = (array) \TCD_Feedback::stats();
        return array(
            'state'     => 'fresh',
            'available' => true,
            'detail'    => 'Feedback local persistido de TaxoCommerce Assistant/Dependiente; no ejecuta búsquedas.',
            'summary'   => $stats,
        );
    }

    private static function comparador_snapshot() {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_comparador_profiles';
        if ( ! self::table_exists( $table ) ) {
            return array(
                'state' => self::state_reference( false, 'unavailable', 'No existe la tabla persistida de perfiles de Comparador.' ),
                'categories' => array(),
            );
        }

        $table_sql = esc_sql( $table );
        // Read-only access to persisted derived evidence. Auditor never starts
        // Comparador jobs and never calls external providers.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- internal fixed table name.
        $rows = (array) $wpdb->get_results(
            "SELECT id,canonical_name,category_ids,primary_category_id,status,
                    own_products_count,external_products_seen,external_products_comparable,
                    comparison_axes_count,confidence,source_snapshot_at,
                    recommended_action,decision_reason,updated_at
             FROM {$table_sql}
             ORDER BY updated_at DESC,id DESC
             LIMIT 1000",
            ARRAY_A
        );

        $map = array();
        $fresh = 0;
        $stale = 0;
        foreach ( $rows as $row ) {
            $ids = array();
            $primary = absint( $row['primary_category_id'] ?? 0 );
            if ( $primary ) {
                $ids[] = $primary;
            }
            $decoded = json_decode( (string) ( $row['category_ids'] ?? '' ), true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $candidate ) {
                    $candidate = absint( $candidate );
                    if ( $candidate ) {
                        $ids[] = $candidate;
                    }
                }
            }
            $ids = array_values( array_unique( $ids ) );
            if ( ! $ids ) {
                continue;
            }

            $status = sanitize_key( (string) ( $row['status'] ?? '' ) );
            $row_state = in_array( $status, array( 'ready', 'ready_for_editorial', 'published' ), true ) ? 'fresh' : 'stale';
            if ( 'fresh' === $row_state ) {
                $fresh++;
            } else {
                $stale++;
            }
            $item = array(
                'state'                        => $row_state,
                'available'                    => true,
                'profile_id'                   => absint( $row['id'] ?? 0 ),
                'name'                         => (string) ( $row['canonical_name'] ?? '' ),
                'status'                       => (string) ( $row['status'] ?? '' ),
                'recommended_action'           => (string) ( $row['recommended_action'] ?? '' ),
                'decision_reason'              => (string) ( $row['decision_reason'] ?? '' ),
                'confidence'                   => (float) ( $row['confidence'] ?? 0 ),
                'own_products_count'           => absint( $row['own_products_count'] ?? 0 ),
                'external_products_seen'       => absint( $row['external_products_seen'] ?? 0 ),
                'external_products_comparable' => absint( $row['external_products_comparable'] ?? 0 ),
                'comparison_axes_count'         => absint( $row['comparison_axes_count'] ?? 0 ),
                'source_snapshot_at'            => $row['source_snapshot_at'] ?: null,
                'updated_at'                    => $row['updated_at'] ?: null,
            );
            foreach ( $ids as $term_id ) {
                if ( ! isset( $map[ $term_id ] ) ) {
                    $map[ $term_id ] = array();
                }
                if ( count( $map[ $term_id ] ) < 10 ) {
                    $map[ $term_id ][] = $item;
                }
            }
        }

        $available = ! empty( $rows );
        $state = ! $available ? 'unavailable' : ( $fresh > 0 ? ( $stale > 0 ? 'partial' : 'fresh' ) : 'stale' );
        return array(
            'state' => self::state_reference(
                $available,
                $state,
                $available
                    ? 'Perfiles persistidos de Comparador leídos directamente; Auditor no ejecuta Comparador.'
                    : 'La tabla existe pero no contiene perfiles de Comparador.'
            ),
            'categories' => $map,
        );
    }

    private static function comparador_context( $snapshot, $category_ids ) {
        $rows = array();
        foreach ( (array) $category_ids as $term_id ) {
            foreach ( (array) ( $snapshot['comparador_categories'][ absint( $term_id ) ] ?? array() ) as $row ) {
                $key = absint( $row['profile_id'] ?? 0 );
                if ( $key ) {
                    $rows[ $key ] = $row;
                }
            }
        }
        if ( $rows ) {
            return array(
                'state'     => 'fresh',
                'available' => true,
                'source'    => 'Comparador persistido',
                'rows'      => array_slice( array_values( $rows ), 0, 8 ),
            );
        }
        return (array) ( $snapshot['comparador'] ?? self::state_reference( false, 'unavailable', '' ) );
    }

    private static function table_exists( $table ) {
        global $wpdb;
        return (string) $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $table ) )
        ) === (string) $table;
    }

    private static function dependiente_entity( $type, $id, $category_ids, $global ) {
        if ( ! class_exists( 'TCD_Feedback' ) || ! method_exists( 'TCD_Feedback', 'events_table' ) ) {
            return $global;
        }
        global $wpdb;
        $events = \TCD_Feedback::events_table();
        if ( ! self::table_exists( $events ) ) {
            return $global;
        }

        $type = self::normalize_type( $type );
        $id = absint( $id );
        if ( 'product' === $type && $id ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) events,
                            SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) clicks,
                            COUNT(DISTINCT query_hash) queries
                     FROM {$events}
                     WHERE product_id=%d",
                    $id
                ),
                ARRAY_A
            );
            if ( is_array( $row ) && absint( $row['events'] ?? 0 ) ) {
                return array(
                    'state'     => 'fresh',
                    'available' => true,
                    'product_id'=> $id,
                    'events'    => absint( $row['events'] ?? 0 ),
                    'clicks'    => absint( $row['clicks'] ?? 0 ),
                    'queries'   => absint( $row['queries'] ?? 0 ),
                    'detail'    => 'Evidencia local asociada directamente al producto.',
                );
            }
        }

        if ( 'category' === $type && $id ) {
            $term = get_term( $id, 'product_cat' );
            if ( $term instanceof \WP_Term ) {
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT COUNT(*) events,
                                SUM(CASE WHEN e.event_type='click' THEN 1 ELSE 0 END) clicks,
                                COUNT(DISTINCT e.query_hash) queries
                         FROM {$events} e
                         INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id=e.product_id
                         INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
                         WHERE tt.taxonomy='product_cat' AND tt.term_id=%d",
                        $id
                    ),
                    ARRAY_A
                );
                if ( is_array( $row ) && absint( $row['events'] ?? 0 ) ) {
                    return array(
                        'state'      => 'fresh',
                        'available'  => true,
                        'term_id'    => $id,
                        'events'     => absint( $row['events'] ?? 0 ),
                        'clicks'     => absint( $row['clicks'] ?? 0 ),
                        'queries'    => absint( $row['queries'] ?? 0 ),
                        'detail'     => 'Evidencia local agregada de productos de esta categoría.',
                    );
                }
            }
        }

        if ( $category_ids ) {
            return array_merge(
                (array) $global,
                array( 'related_category_ids' => array_slice( array_values( $category_ids ), 0, 20 ) )
            );
        }
        return $global;
    }

    private static function supplier_context( $type, $id ) {
        if ( 'product' !== self::normalize_type( $type ) || ! $id || ! class_exists( 'TCSUP_Schema' ) || ! method_exists( 'TCSUP_Schema', 'catalog_table' ) ) {
            return self::state_reference( false, 'unavailable', 'No hay contexto de proveedor nativo aplicable a esta entidad.' );
        }
        global $wpdb;
        $table = \TCSUP_Schema::catalog_table();
        if ( ! self::table_exists( $table ) ) {
            return self::state_reference( false, 'unavailable', 'La tabla de TaxoCommerce Suppliers no está disponible.' );
        }
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT proveedor,proveedor_id_externo,sku,pais_origen,estado_seleccion,estado_sincronizacion,
                        ultima_importacion,ultima_sincronizacion,ultimo_error_sync,actualizado
                 FROM {$table}
                 WHERE object_id=%d
                 ORDER BY actualizado DESC,id DESC
                 LIMIT 1",
                absint( $id )
            ),
            ARRAY_A
        );
        if ( ! is_array( $row ) || ! $row ) {
            return self::state_reference( false, 'unavailable', 'Producto sin fila enlazada en TaxoCommerce Suppliers.' );
        }
        return array(
            'state'                  => empty( $row['ultimo_error_sync'] ) ? 'fresh' : 'partial',
            'available'              => true,
            'provider'               => (string) ( $row['proveedor'] ?? '' ),
            'external_id'            => (string) ( $row['proveedor_id_externo'] ?? '' ),
            'sku'                    => (string) ( $row['sku'] ?? '' ),
            'country'                => (string) ( $row['pais_origen'] ?? '' ),
            'selection_status'       => (string) ( $row['estado_seleccion'] ?? '' ),
            'sync_status'            => (string) ( $row['estado_sincronizacion'] ?? '' ),
            'last_import'            => $row['ultima_importacion'] ?? null,
            'last_sync'              => $row['ultima_sincronizacion'] ?? null,
            'last_error'             => (string) ( $row['ultimo_error_sync'] ?? '' ),
        );
    }

    public static function public_snapshot() {
        $snapshot = self::snapshot();
        return array(
            'generated_at' => (string) ( $snapshot['generated_at'] ?? '' ),
            'sources' => array(
                'insights'    => (array) ( $snapshot['insights'] ?? array() ),
                'market'      => self::state_reference(
                    ! empty( $snapshot['market_categories'] ),
                    ! empty( $snapshot['market_categories'] ) ? 'fresh' : 'partial',
                    ! empty( $snapshot['market_categories'] ) ? 'Mercado guardado en TaxoCommerce Insights.' : 'Sin categorías de mercado guardadas.'
                ),
                'dependiente' => (array) ( $snapshot['dependiente'] ?? array() ),
                'suppliers'   => (array) ( $snapshot['suppliers'] ?? array() ),
                'comparador'  => (array) ( $snapshot['comparador'] ?? array() ),
            ),
            'policy' => array(
                'no_external_api_calls' => true,
                'zero_is_not_missing'    => true,
                'priority_rule'          => 'El contexto sólo puede ordenar dentro de la misma P1-P5; no cambia la clase del hallazgo.',
            ),
        );
    }

    public static function decorate( $finding ) {
        $finding = is_array( $finding ) ? $finding : array();
        $type = self::normalize_type( $finding['entity_type'] ?? '' );
        $id = absint( $finding['entity_id'] ?? 0 );
        $category_ids = self::category_ids( $type, $id );
        $snapshot = self::snapshot();

        $insights = self::insights_context( $snapshot, $type, $id, $category_ids );
        $context = array(
            'entity' => array(
                'type'         => $type,
                'id'           => $id ?: null,
                'category_ids' => $category_ids,
            ),
            'insights'    => $insights,
            'market'      => self::market_context( $snapshot, $category_ids ),
            'dependiente' => self::dependiente_entity( $type, $id, $category_ids, (array) ( $snapshot['dependiente'] ?? array() ) ),
            'comparador'  => self::comparador_context( $snapshot, $category_ids ),
            'suppliers'   => self::supplier_context( $type, $id ),
        );

        $context = apply_filters( 'taxocommerce_auditor_entity_context', $context, $finding );
        $finding['contexto_de_decision'] = is_array( $context ) ? $context : array();

        $base = absint( $finding['priority_score'] ?? 0 );
        $priority = isset( $insights['priority'] ) && is_numeric( $insights['priority'] )
            ? max( 0, min( 100, (int) $insights['priority'] ) )
            : null;
        $boost = null === $priority ? 0 : (int) round( $priority * 0.20 );
        $finding['priority_score_base'] = $base;
        $finding['context_priority'] = array(
            'source'            => null === $priority ? null : 'insights',
            'insights_priority' => $priority,
            'source_state'      => (string) ( $insights['state'] ?? 'unavailable' ),
            'boost'             => $boost,
            'rule'              => 'Sólo ordena dentro de la prioridad P existente; no cambia la clase de Auditor.',
        );
        $finding['priority_score'] = $base + $boost;
        return $finding;
    }
}
