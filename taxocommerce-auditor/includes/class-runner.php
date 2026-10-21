<?php
/**
 * Bounded background runner for the five content audits.
 *
 * All catalogue reads use WP_Query, term APIs and WooCommerce CRUD. No seo_*
 * tables, legacy callbacks, or bulk catalogue mutations are used.
 */

namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

final class Runner {
    const HOOK       = 'taxocommerce_auditor_process_batch';
    const BATCH_SIZE = 40;
    const MAX_STORED_FINDINGS = 400;
    const MAX_QUALITY_ROWS    = 100;
    const MAX_INDEXED_TITLES  = 25000;
    const MAX_REBALANCE_PROPOSALS = 150;

    public static function scopes() {
        return array(
            'products'   => 'Productos · contenido e identidad',
            'categories' => 'Categorías y arquitectura',
            'posts'      => 'Posts',
            'pages'      => 'Páginas y landings',
            'faqs'       => 'FAQs',
        );
    }

    public static function valid_scope( $scope ) {
        return is_string( $scope ) && isset( self::scopes()[ $scope ] );
    }

    public static function faq_post_type() {
        $types = apply_filters( 'taxocommerce_auditor_faq_post_types', array( 'tc_faq', 'taxocommerce_faq', 'faq' ) );
        foreach ( (array) $types as $type ) {
            $type = sanitize_key( (string) $type );
            if ( post_type_exists( $type ) ) {
                return $type;
            }
        }
        return '';
    }

    private static function option( $scope, $kind ) {
        return 'taxocommerce_auditor_' . $kind . '_' . $scope;
    }

    public static function state( $scope ) {
        return self::valid_scope( $scope ) ? (array) get_option( self::option( $scope, 'state' ), array() ) : array();
    }

    public static function report( $scope ) {
        return self::valid_scope( $scope ) ? (array) get_option( self::option( $scope, 'report' ), array() ) : array();
    }

    public static function register() {
        add_action( self::HOOK, array( __CLASS__, 'run_batch' ), 10, 1 );
        add_action( 'admin_init', array( __CLASS__, 'ensure_scheduled' ) );
    }

    public static function deactivate() {
        foreach ( array_keys( self::scopes() ) as $scope ) {
            wp_clear_scheduled_hook( self::HOOK, array( $scope ) );
        }
    }

    private static function enqueue( $scope ) {
        if ( ! wp_next_scheduled( self::HOOK, array( $scope ) ) ) {
            wp_schedule_single_event( time() + 12, self::HOOK, array( $scope ) );
        }
    }

    /**
     * Repair a lost WP-Cron pulse without resetting a partially completed run.
     */
    public static function ensure_scheduled() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        foreach ( array_keys( self::scopes() ) as $scope ) {
            $state = self::state( $scope );
            if ( in_array( (string) ( $state['status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
                self::enqueue( $scope );
            }
        }
    }

    private static function count_objects( $scope ) {
        if ( 'categories' === $scope ) {
            $count = wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
            return is_wp_error( $count ) ? null : absint( $count );
        }

        $post_type = 'faqs' === $scope ? self::faq_post_type() : ( 'products' === $scope ? 'product' : ( 'pages' === $scope ? 'page' : 'post' ) );
        $counts = wp_count_posts( $post_type );
        return $counts && isset( $counts->publish ) ? absint( $counts->publish ) : null;
    }

    private static function unavailable_reason( $scope ) {
        if ( 'products' === $scope && ( ! function_exists( 'wc_get_product' ) || ! post_type_exists( 'product' ) ) ) {
            return 'WooCommerce no está disponible. No se interpreta la ausencia como cero productos.';
        }
        if ( 'categories' === $scope && ! taxonomy_exists( 'product_cat' ) ) {
            return 'No existe la taxonomía product_cat.';
        }
        if ( 'faqs' === $scope && ! self::faq_post_type() ) {
            return 'WordPress no incluye un CPT FAQ de serie. Instala el módulo FAQ o registra un tipo mediante taxocommerce_auditor_faq_post_types.';
        }
        return '';
    }

    public static function start( $scope ) {
        if ( ! self::valid_scope( $scope ) ) {
            return false;
        }
        // Do not reset a run while a worker still owns this scope.
        if ( get_transient( 'taxocommerce_auditor_lock_' . $scope ) ) {
            return false;
        }
        wp_clear_scheduled_hook( self::HOOK, array( $scope ) );

        $unavailable = self::unavailable_reason( $scope );
        $state = array(
            'status'           => $unavailable ? 'unavailable' : 'queued',
            'reason'           => $unavailable,
            'started_gmt'      => current_time( 'mysql', true ),
            'started_timestamp' => time(),
            'completed_gmt'    => null,
            'processed'        => 0,
            'excluded'         => 0,
            'total'            => $unavailable ? null : self::count_objects( $scope ),
            'cursor'           => 0,
            'finding_count'    => 0,
            'severity_counts'  => array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0 ),
            'rule_counts'      => array(),
            'findings'         => array(),
            'quality_total'    => 0,
            'quality_scored'   => 0,
            'quality_bands'    => array( 'fuerte' => 0, 'correcta' => 0, 'mejorable' => 0, 'debil' => 0 ),
            'quality_worst'    => array(),
            'seen_titles'      => array(),
            'seen_skus'        => array(),
            'seen_templates'   => array(),
            'seen_faq_questions' => array(),
            'priority_counts'  => array( 'P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0, 'P5' => 0 ),
            'rebalance_summary' => array(
                'review_split' => 0,
                'split_proposals' => 0,
                'review_merge' => 0,
                'merge_proposals' => 0,
                'approved' => 0,
            ),
            'rebalance_proposals' => array(),
            'editorial_map'   => ! $unavailable && in_array( $scope, array( 'posts', 'pages' ), true )
                ? Editorial_Specialist::build_map()
                : array(),
            'notes'            => array(
                'Auditoria de solo lectura: no altera contenido, terminos ni relaciones.',
                'La puntuacion de calidad es interna, no una metrica ni una prediccion de Google.',
            ),
        );
        if ( ! empty( $state['editorial_map']['partial'] ) ) {
            $state['notes'][] = 'El mapa editorial se ha limitado a ' . Editorial_Specialist::MAP_LIMIT . ' objetos; la cobertura comparativa es parcial.';
        }
        if ( 'categories' === $scope ) {
            $state['notes'][] = 'Referencia histórica para revisar tamaños: 5-10 productos. Las propuestas requieren evidencia semántica y no autorizan cambios automáticos.';
        }
        if ( 'faqs' === $scope && ! $unavailable ) {
            $state['notes'][] = 'Validacion del owner solo cuando otro modulo ofrece el filtro taxocommerce_auditor_faq_owner.';
        }
        update_option( self::option( $scope, 'state' ), $state, false );
        if ( ! $unavailable ) {
            self::enqueue( $scope );
        }
        return true;
    }

    public static function pause( $scope ) {
        if ( ! self::valid_scope( $scope ) ) {
            return;
        }
        $state = self::state( $scope );
        if ( in_array( (string) ( $state['status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
            $state['status'] = 'paused';
            update_option( self::option( $scope, 'state' ), $state, false );
        }
        wp_clear_scheduled_hook( self::HOOK, array( $scope ) );
    }

    public static function resume( $scope ) {
        if ( ! self::valid_scope( $scope ) ) {
            return;
        }
        $state = self::state( $scope );
        if ( 'paused' === (string) ( $state['status'] ?? '' ) ) {
            $state['status'] = 'queued';
            update_option( self::option( $scope, 'state' ), $state, false );
            self::enqueue( $scope );
        }
    }

    /**
     * Seek by post ID to avoid large OFFSET queries for 10k+ products.
     * The filter applies exclusively to this one WP_Query instance.
     */
    private static function batch( $scope, $cursor ) {
        if ( 'categories' === $scope ) {
            $terms = get_terms(
                array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                    'number'     => self::BATCH_SIZE,
                    'offset'     => absint( $cursor ),
                    'orderby'    => 'term_id',
                    'order'      => 'ASC',
                )
            );
            return is_wp_error( $terms ) ? array() : (array) $terms;
        }

        $post_type = 'faqs' === $scope ? self::faq_post_type() : ( 'products' === $scope ? 'product' : ( 'pages' === $scope ? 'page' : 'post' ) );
        $seek = absint( $cursor );
        $filter = static function ( $where, $query ) use ( $seek ) {
            if ( 1 !== absint( $query->get( 'taxocommerce_auditor_seek' ) ) ) {
                return $where;
            }
            global $wpdb;
            return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $seek );
        };
        add_filter( 'posts_where', $filter, 10, 2 );
        try {
            $query = new \WP_Query(
                array(
                    'post_type'              => $post_type,
                    'post_status'            => 'publish',
                    'fields'                 => 'ids',
                    'posts_per_page'         => self::BATCH_SIZE,
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'no_found_rows'          => true,
                    'suppress_filters'       => false,
                    'taxocommerce_auditor_seek' => 1,
                    'update_post_meta_cache' => true,
                    'update_post_term_cache' => true,
                )
            );
        } finally {
            remove_filter( 'posts_where', $filter, 10 );
        }
        $objects = array();
        foreach ( (array) $query->posts as $id ) {
            $post = get_post( absint( $id ) );
            if ( $post instanceof \WP_Post ) {
                $objects[] = $post;
            }
        }
        return $objects;
    }

    private static function add_finding( &$state, $row ) {
        $row = Priority::decorate( (array) $row );
        $severity = $row['severity'];
        $code = $row['code'];
        $priority = $row['priority'];
        $row['evidence'] = mb_substr( (string) ( $row['evidence'] ?? '' ), 0, 350, 'UTF-8' );

        $state['finding_count']++;
        $state['severity_counts'][ $severity ]++;
        $state['rule_counts'][ $code ] = absint( $state['rule_counts'][ $code ] ?? 0 ) + 1;
        $state['priority_counts'][ $priority ] = absint( $state['priority_counts'][ $priority ] ?? 0 ) + 1;
        $state['findings'][] = $row;
    }

    private static function append_quality( &$state, $scope, $object, $quality ) {
        if ( ! is_array( $quality ) || ! isset( $quality['score'] ) ) {
            return;
        }
        $score = absint( $quality['score'] );
        $state['quality_total'] += $score;
        $state['quality_scored']++;
        $band = Rules::band( $score );
        $state['quality_bands'][ $band ] = (int) ( $state['quality_bands'][ $band ] ?? 0 ) + 1;
        $is_term = $object instanceof \WP_Term;
        $state['quality_worst'][] = array(
            'entity_type' => $is_term ? 'product_cat' : ( 'faqs' === $scope ? 'faq' : ( 'products' === $scope ? 'product' : ( 'pages' === $scope ? 'page' : 'post' ) ) ),
            'entity_id'   => $is_term ? absint( $object->term_id ) : absint( $object->ID ),
            'title'       => $is_term ? (string) $object->name : (string) $object->post_title,
            'score'       => $score,
            'band'        => $band,
            'dimensions'  => $quality['dimensions'],
        );
    }

    private static function trim_samples( &$state ) {
        usort( $state['findings'], static function ( $a, $b ) {
            return (int) ( $b['priority_score'] ?? 0 ) <=> (int) ( $a['priority_score'] ?? 0 );
        } );
        $state['findings'] = array_slice( $state['findings'], 0, self::MAX_STORED_FINDINGS );
        usort( $state['quality_worst'], static function ( $a, $b ) {
            return (int) ( $a['score'] ?? 0 ) <=> (int) ( $b['score'] ?? 0 );
        } );
        $state['quality_worst'] = array_slice( $state['quality_worst'], 0, self::MAX_QUALITY_ROWS );
    }

    private static function complete( $scope, &$state ) {
        $state['status'] = 'completed';
        $state['completed_gmt'] = current_time( 'mysql', true );
        self::trim_samples( $state );

        $report = array(
            'schema'      => 'taxocommerce_auditor_native_v1',
            'version'     => Plugin::VERSION,
            'scope'       => $scope,
            'started_gmt' => $state['started_gmt'],
            'completed_gmt' => $state['completed_gmt'],
            'processed'   => $state['processed'],
            'excluded'    => $state['excluded'],
            'expected'    => $state['total'],
            'finding_count' => $state['finding_count'],
            'severity_counts' => $state['severity_counts'],
            'rule_counts'     => $state['rule_counts'],
            'quality' => array(
                'not_google_score' => true,
                'dimensions' => Rules::DIMENSIONS,
                'scored' => $state['quality_scored'],
                'average' => $state['quality_scored'] ? round( $state['quality_total'] / $state['quality_scored'], 1 ) : null,
                'bands'  => $state['quality_bands'],
                'lowest_scored_sample' => $state['quality_worst'],
            ),
            'findings_sample' => $state['findings'],
            'findings_sample_truncated' => $state['finding_count'] > count( $state['findings'] ),
            'notes' => $state['notes'],
        );
        update_option( self::option( $scope, 'report' ), $report, false );
        unset( $state['seen_titles'], $state['canonical_owners'] );
        update_option( self::option( $scope, 'state' ), $state, false );
        wp_clear_scheduled_hook( self::HOOK, array( $scope ) );
    }

    public static function run_batch( $scope ) {
        if ( ! self::valid_scope( $scope ) ) {
            return;
        }
        $state = self::state( $scope );
        if ( ! in_array( (string) ( $state['status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
            return;
        }
        $lock = 'taxocommerce_auditor_lock_' . $scope;
        if ( get_transient( $lock ) ) {
            return;
        }
        set_transient( $lock, 1, 120 );
        try {
            $state['status'] = 'running';
            $objects = self::batch( $scope, absint( $state['cursor'] ) );
            foreach ( $objects as $object ) {
                $analysis = Rules::inspect( $scope, $object );
                $state['processed']++;
                $id = $object instanceof \WP_Term ? absint( $object->term_id ) : absint( $object->ID );
                if ( 'categories' !== $scope ) {
                    $state['cursor'] = max( absint( $state['cursor'] ), $id );
                }
                if ( ! empty( $analysis['excluded'] ) ) {
                    $state['excluded']++;
                    continue;
                }
                $type = $object instanceof \WP_Term ? 'product_cat' : ( 'products' === $scope ? 'product' : ( 'faqs' === $scope ? 'faq' : ( 'pages' === $scope ? 'page' : 'post' ) ) );
                $title = $object instanceof \WP_Term ? (string) $object->name : (string) $object->post_title;
                $key = (string) ( $analysis['title_key'] ?? '' );
                $duplicate_title = false;
                $canonical_overlap = false;
                if ( '' !== $key ) {
                    $identity_hash = md5( $key ); // Bounded exact-title index, not used for security.
                    if ( isset( $state['seen_titles'][ $identity_hash ] ) && absint( $state['seen_titles'][ $identity_hash ] ) !== $id ) {
                        $duplicate_title = true;
                        self::add_finding( $state, array(
                            'code' => 'duplicate_title_candidate',
                            'severity' => 'medium',
                            'entity_type' => $type,
                            'entity_id' => $id,
                            'title' => $title,
                            'evidence' => 'Título normalizado igual al objeto #' . absint( $state['seen_titles'][ $identity_hash ] ) . ' del mismo bloque.',
                            'recommendation' => 'Verificar si son variantes legítimas o duplicados que compiten por la misma intención.',
                        ) );
                    } elseif ( count( $state['seen_titles'] ) < self::MAX_INDEXED_TITLES ) {
                        $state['seen_titles'][ $identity_hash ] = $id;
                    }
                    if ( in_array( $scope, array( 'posts', 'pages' ), true ) && isset( $state['canonical_owners'][ $key ] ) ) {
                        $owner = $state['canonical_owners'][ $key ];
                        if ( ! ( 'page' === $type && 'page' === $owner['entity_type'] && $id === absint( $owner['entity_id'] ) ) ) {
                            $canonical_overlap = true;
                            self::add_finding( $state, array(
                                'code' => 'editorial_canonical_overlap_candidate',
                                'severity' => 'high',
                                'entity_type' => $type,
                                'entity_id' => $id,
                                'title' => $title,
                                'evidence' => 'Mismo título normalizado que ' . $owner['entity_type'] . ' #' . absint( $owner['entity_id'] ) . '.',
                                'recommendation' => 'Comprobar intención, propietario canónico y diferenciación; la coincidencia no demuestra canibalización.',
                            ) );
                        }
                    }
                }
                foreach ( (array) ( $analysis['findings'] ?? array() ) as $finding ) {
                    self::add_finding( $state, $finding );
                }
                $quality = $analysis['quality'] ?? null;
                if ( is_array( $quality ) && ( $duplicate_title || $canonical_overlap ) ) {
                    $quality['dimensions']['differentiation'] = $duplicate_title ? 5 : 10;
                    $quality['score'] = array_sum( $quality['dimensions'] );
                    $quality['band'] = Rules::band( $quality['score'] );
                }
                self::append_quality( $state, $scope, $object, $quality );
            }
            if ( 'categories' === $scope ) {
                $state['cursor'] = absint( $state['cursor'] ) + count( $objects );
            }
            self::trim_samples( $state );
            if ( count( $objects ) < self::BATCH_SIZE ) {
                self::complete( $scope, $state );
            } else {
                update_option( self::option( $scope, 'state' ), $state, false );
                self::enqueue( $scope );
            }
        } catch ( \Throwable $e ) {
            $state['status'] = 'error';
            $state['reason'] = sanitize_text_field( $e->getMessage() );
            update_option( self::option( $scope, 'state' ), $state, false );
            wp_clear_scheduled_hook( self::HOOK, array( $scope ) );
        } finally {
            delete_transient( $lock );
        }
    }
}
