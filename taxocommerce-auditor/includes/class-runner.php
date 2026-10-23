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

    public static function history( $scope ) {
        return self::valid_scope( $scope )
            ? (array) get_option( self::option( $scope, 'history' ), array() )
            : array();
    }

    private static function persisted_decision_context( $scope ) {
        // Optional integration; other services must pass an already-persisted
        // snapshot. Never initiate remote queries from a content audit.
        $context = apply_filters( 'taxocommerce_auditor_decision_context', null, $scope );
        return is_array( $context ) && $context ? array_slice( $context, 0, 10, true ) : null;
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

    public static function sanitize_filters( $scope, $filters ) {
        $rows = absint( $filters['rows'] ?? 25 );
        if ( ! in_array( $rows, array( 10,25,50,100,250 ), true ) ) {
            $rows = 25;
        }
        return array(
            'search'      => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
            'category_id' => 'products' === $scope ? absint( $filters['category_id'] ?? 0 ) : 0,
            'rows'        => $rows,
        );
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

    public static function start( $scope, $filters = array() ) {
        if ( ! self::valid_scope( $scope ) ) {
            return false;
        }
        // Do not reset a run while a worker still owns this scope.
        if ( get_transient( 'taxocommerce_auditor_lock_' . $scope ) ) {
            return false;
        }
        wp_clear_scheduled_hook( self::HOOK, array( $scope ) );

        $filters = self::sanitize_filters( $scope, $filters );
        $unavailable = self::unavailable_reason( $scope );
        $state = array(
            'status'           => $unavailable ? 'unavailable' : 'queued',
            'reason'           => $unavailable,
            'started_gmt'      => current_time( 'mysql', true ),
            'started_timestamp' => time(),
            'completed_gmt'    => null,
            'processed'        => 0,
            'excluded'         => 0,
            'total'            => $unavailable ? null : $filters['rows'],
            'filters'          => $filters,
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
                'La ejecucion esta limitada por filtros y numero maximo de entidades para evitar sobrecarga.',
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
    private static function batch( $scope, $cursor, $filters = array(), $remaining = null ) {
        $filters = self::sanitize_filters( $scope, $filters );
        $batch_size = min( self::BATCH_SIZE, null === $remaining ? self::BATCH_SIZE : max( 1, absint( $remaining ) ) );
        if ( 'categories' === $scope ) {
            $terms = get_terms(
                array(
                    'taxonomy'   => 'product_cat',
                    'hide_empty' => false,
                    'number'     => $batch_size,
                    'offset'     => absint( $cursor ),
                    'search'     => $filters['search'],
                    'orderby'    => 'term_id',
                    'order'      => 'ASC',
                )
            );
            if ( is_wp_error( $terms ) ) {
                throw new \RuntimeException( $terms->get_error_message() );
            }
            return (array) $terms;
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
            $query_args = array(
                    'post_type'              => $post_type,
                    'post_status'            => 'publish',
                    'fields'                 => 'ids',
                    'posts_per_page'         => $batch_size,
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'no_found_rows'          => true,
                    'suppress_filters'       => false,
                    'taxocommerce_auditor_seek' => 1,
                    'update_post_meta_cache' => true,
                    'update_post_term_cache' => true,
                    's'                       => $filters['search'],
                );
            if ( 'products' === $scope && $filters['category_id'] ) {
                $query_args['tax_query'] = array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => array( $filters['category_id'] ),
                    ),
                );
            }
            if ( ctype_digit( $filters['search'] ) ) {
                $query_args['p'] = absint( $filters['search'] );
                unset( $query_args['s'] );
            }
            $query = new \WP_Query( $query_args );
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

    /**
     * Four exact-match indexes must survive background batches. Compress them
     * in the native WP option instead of repeatedly writing multi-MB arrays
     * while auditing a large store. PHP zlib is optional; fall back safely.
     */
    private static function load_indexes( &$state ) {
        $keys = array( 'seen_titles', 'seen_skus', 'seen_templates', 'seen_faq_questions' );
        if ( isset( $state['indexes_compressed'] ) ) {
            if ( ! function_exists( 'gzuncompress' ) ) {
                throw new \RuntimeException( 'Falta zlib para recuperar los índices de este proceso.' );
            }
            $raw = base64_decode( (string) $state['indexes_compressed'], true );
            $decoded = is_string( $raw ) ? @gzuncompress( $raw ) : false;
            $maps = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
            if ( ! is_array( $maps ) ) {
                throw new \RuntimeException( 'No se pueden recuperar los índices del Auditor; iniciar una nueva auditoría.' );
            }
            foreach ( $keys as $key ) {
                $state[ $key ] = is_array( $maps[ $key ] ?? null ) ? $maps[ $key ] : array();
            }
            unset( $state['indexes_compressed'] );
        } else {
            foreach ( $keys as $key ) {
                $state[ $key ] = is_array( $state[ $key ] ?? null ) ? $state[ $key ] : array();
            }
        }
    }

    private static function save_indexes( &$state ) {
        if ( ! function_exists( 'gzcompress' ) ) {
            return;
        }
        $keys = array( 'seen_titles', 'seen_skus', 'seen_templates', 'seen_faq_questions' );
        $maps = array();
        foreach ( $keys as $key ) {
            $maps[ $key ] = (array) ( $state[ $key ] ?? array() );
        }
        $json = wp_json_encode( $maps );
        $compressed = is_string( $json ) ? gzcompress( $json, 4 ) : false;
        if ( ! is_string( $compressed ) ) {
            return;
        }
        $state['indexes_compressed'] = base64_encode( $compressed );
        foreach ( $keys as $key ) {
            unset( $state[ $key ] );
        }
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
            'schema'      => 'taxocommerce_auditor_native_v2',
            'version'     => Plugin::VERSION,
            'scope'       => $scope,
            'started_gmt' => $state['started_gmt'],
            'completed_gmt' => $state['completed_gmt'],
            'duration_seconds' => max( 0, time() - absint( $state['started_timestamp'] ?? time() ) ),
            'source_model' => 'WordPress post/term meta + WooCommerce CRUD + Vocabulary native taxonomies',
            'processed'   => $state['processed'],
            'excluded'    => $state['excluded'],
            'expected'    => $state['total'],
            'filters'     => (array) ( $state['filters'] ?? array() ),
            'finding_count' => $state['finding_count'],
            'severity_counts' => $state['severity_counts'],
            'rule_counts'     => $state['rule_counts'],
            'priority_counts' => $state['priority_counts'],
            'decision_context' => self::persisted_decision_context( $scope ),
            'category_rebalance' => 'categories' === $scope
                ? array(
                    'summary' => $state['rebalance_summary'],
                    'proposals' => $state['rebalance_proposals'],
                    'sample_truncated' => (
                        (int) $state['rebalance_summary']['split_proposals']
                        + (int) $state['rebalance_summary']['merge_proposals']
                    ) > count( $state['rebalance_proposals'] ),
                    'apply_actions_enabled' => false,
                    'note' => 'Solo propuestas de revisión. No se mueven ni eliminan categorías y no se crean redirecciones.',
                )
                : null,
            'editorial_comparison' => in_array( $scope, array( 'posts', 'pages' ), true )
                ? array(
                    'indexed_posts_pages' => absint( $state['editorial_map']['indexed_posts_pages'] ?? 0 ),
                    'partial' => ! empty( $state['editorial_map']['partial'] ),
                )
                : null,
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

        // Store a bounded list of completed runs, as in the legacy Auditor.
        $history = (array) get_option( self::option( $scope, 'history' ), array() );
        array_unshift(
            $history,
            array(
                'completed_gmt' => $report['completed_gmt'],
                'duration_seconds' => $report['duration_seconds'],
                'processed' => $report['processed'],
                'finding_count' => $report['finding_count'],
                'severity_counts' => $report['severity_counts'],
                'priority_counts' => $report['priority_counts'],
            )
        );
        update_option( self::option( $scope, 'history' ), array_slice( $history, 0, 12 ), false );

        unset(
            $state['seen_titles'],
            $state['seen_skus'],
            $state['seen_templates'],
            $state['seen_faq_questions'],
            $state['editorial_map'],
            $state['findings'],
            $state['quality_worst'],
            $state['rebalance_proposals']
        );
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
            // The current cron event has been consumed; do not lose the next pulse.
            self::enqueue( $scope );
            return;
        }
        set_transient( $lock, 1, 120 );
        try {
            self::load_indexes( $state );
            $state['status'] = 'running';
            $limit = absint( $state['filters']['rows'] ?? 25 );
            $remaining = max( 0, $limit - absint( $state['processed'] ) );
            if ( 0 === $remaining ) {
                self::complete( $scope, $state );
                return;
            }
            $objects = self::batch( $scope, absint( $state['cursor'] ), (array) ( $state['filters'] ?? array() ), $remaining );
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

                $type = $object instanceof \WP_Term ? 'product_cat'
                    : ( 'products' === $scope ? 'product'
                    : ( 'faqs' === $scope ? 'faq' : ( 'pages' === $scope ? 'page' : 'post' ) ) );
                $title = $object instanceof \WP_Term ? (string) $object->name : (string) $object->post_title;
                $key = (string) ( $analysis['title_key'] ?? '' );
                $identity_duplicate = false;
                $editorial_collision = false;

                if ( 'categories' === $scope && $object instanceof \WP_Term ) {
                    $review = Category_Review::inspect( $object );
                    $analysis['findings'] = array_merge(
                        (array) ( $analysis['findings'] ?? array() ),
                        (array) ( $review['findings'] ?? array() )
                    );
                    $inventory = (array) ( $review['inventory'] ?? array() );
                    if ( ! empty( $inventory['review_split'] ) ) {
                        $state['rebalance_summary']['review_split']++;
                    }
                    if ( ! empty( $inventory['review_merge'] ) ) {
                        $state['rebalance_summary']['review_merge']++;
                    }
                    if ( ! empty( $inventory['split_proposal'] ) ) {
                        $state['rebalance_summary']['split_proposals']++;
                    }
                    if ( ! empty( $inventory['merge_proposal'] ) ) {
                        $state['rebalance_summary']['merge_proposals']++;
                    }
                    if ( ( ! empty( $inventory['split_proposal'] ) || ! empty( $inventory['merge_proposal'] ) )
                        && count( $state['rebalance_proposals'] ) < self::MAX_REBALANCE_PROPOSALS ) {
                        $state['rebalance_proposals'][] = $inventory;
                    }
                }

                if ( in_array( $scope, array( 'posts', 'pages' ), true ) && $object instanceof \WP_Post ) {
                    $additional = Editorial_Specialist::inspect(
                        $object,
                        $scope,
                        (array) ( $state['editorial_map'] ?? array() )
                    );
                    $analysis['findings'] = array_merge(
                        (array) ( $analysis['findings'] ?? array() ),
                        (array) $additional
                    );
                    foreach ( $additional as $finding ) {
                        $code = (string) ( $finding['code'] ?? '' );
                        if ( false !== strpos( $code, 'cannibalization' )
                            || false !== strpos( $code, '_duplicate' )
                            || false !== strpos( $code, '_structural_overlap' ) ) {
                            $editorial_collision = true;
                        }
                    }
                }

                // Legacy FAQ duplicate rule is scoped to the same confirmed
                // owner; identical general questions across owners are valid.
                if ( 'faqs' === $scope ) {
                    $owner_key = (string) ( $analysis['owner_key'] ?? '' );
                    if ( '' !== $owner_key && '' !== $key ) {
                        $faq_key = md5( $owner_key . '|' . $key );
                        if ( isset( $state['seen_faq_questions'][ $faq_key ] ) ) {
                            self::add_finding(
                                $state,
                                array(
                                    'code'           => 'duplicate_faq_same_owner',
                                    'severity'       => 'medium',
                                    'entity_type'    => $type,
                                    'entity_id'      => $id,
                                    'title'          => $title,
                                    'evidence'       => 'Pregunta repetida para ' . $owner_key . '; primera FAQ #' . absint( $state['seen_faq_questions'][ $faq_key ] ),
                                    'recommendation' => 'Revisar si las respuestas son equivalentes y consolidar solo después de verificarlas.',
                                )
                            );
                        } elseif ( count( $state['seen_faq_questions'] ) < self::MAX_INDEXED_TITLES ) {
                            $state['seen_faq_questions'][ $faq_key ] = $id;
                        }
                    }
                } elseif ( '' !== $key ) {
                    $identity_hash = md5( $key );
                    if ( isset( $state['seen_titles'][ $identity_hash ] )
                        && absint( $state['seen_titles'][ $identity_hash ] ) !== $id ) {
                        $identity_duplicate = true;
                        $code = in_array( $scope, array( 'posts', 'pages' ), true )
                            ? 'duplicate_editorial_title' : 'duplicate_title_candidate';
                        self::add_finding(
                            $state,
                            array(
                                'code'           => $code,
                                'severity'       => 'medium',
                                'entity_type'    => $type,
                                'entity_id'      => $id,
                                'title'          => $title,
                                'evidence'       => 'Misma identidad de título que #' . absint( $state['seen_titles'][ $identity_hash ] ) . ' en este bloque.',
                                'recommendation' => 'Revisar variantes, localizaciones e intención antes de consolidar URLs.',
                            )
                        );
                    } elseif ( count( $state['seen_titles'] ) < self::MAX_INDEXED_TITLES ) {
                        $state['seen_titles'][ $identity_hash ] = $id;
                    }
                }

                // Legacy product-identity check. Provider/country scope may
                // be supplied explicitly by a native module; no country is
                // guessed from the language or title.
                $sku_key = (string) ( $analysis['sku_key'] ?? '' );
                if ( 'products' === $scope && '' !== $sku_key ) {
                    $identity_scope = apply_filters( 'taxocommerce_auditor_product_identity_scope', '', $id );
                    $identity_scope = sanitize_key( (string) $identity_scope );
                    $sku_hash = md5( $identity_scope . '|' . $sku_key );
                    if ( isset( $state['seen_skus'][ $sku_hash ] ) ) {
                        self::add_finding(
                            $state,
                            array(
                                'code'           => 'source_duplicate_sku',
                                'severity'       => 'medium',
                                'entity_type'    => 'product',
                                'entity_id'      => $id,
                                'title'          => $title,
                                'evidence'       => 'SKU repetido con producto #' . absint( $state['seen_skus'][ $sku_hash ] ) . '. Ámbito: ' . ( $identity_scope ?: 'no especificado' ) . '.',
                                'recommendation' => 'Contrastar proveedor y país: dos mercados pueden publicar legítimamente el mismo SKU.',
                            )
                        );
                        $identity_duplicate = true;
                    } elseif ( count( $state['seen_skus'] ) < self::MAX_INDEXED_TITLES ) {
                        $state['seen_skus'][ $sku_hash ] = $id;
                    }
                }

                $template = (string) ( $analysis['template_key'] ?? '' );
                if ( '' !== $template && 'faqs' !== $scope ) {
                    $template_hash = md5( $template );
                    if ( isset( $state['seen_templates'][ $template_hash ] )
                        && absint( $state['seen_templates'][ $template_hash ] ) !== $id ) {
                        self::add_finding(
                            $state,
                            array(
                                'code'           => 'duplicate_content_template_candidate',
                                'severity'       => 'low',
                                'entity_type'    => $type,
                                'entity_id'      => $id,
                                'title'          => $title,
                                'evidence'       => 'Patrón de cuerpo repetido; primer objeto #' . absint( $state['seen_templates'][ $template_hash ] ) . '.',
                                'recommendation' => 'Revisar contenido original y diferencias legítimas. No duplicar ni eliminar por una coincidencia de plantilla.',
                            )
                        );
                        $identity_duplicate = true;
                    } elseif ( count( $state['seen_templates'] ) < self::MAX_INDEXED_TITLES ) {
                        $state['seen_templates'][ $template_hash ] = $id;
                    }
                }

                foreach ( (array) ( $analysis['findings'] ?? array() ) as $finding ) {
                    self::add_finding( $state, $finding );
                }
                $quality = $analysis['quality'] ?? null;
                if ( is_array( $quality ) && ( $identity_duplicate || $editorial_collision ) ) {
                    $quality['dimensions']['differentiation'] = $identity_duplicate ? 5 : 10;
                    $quality['score'] = array_sum( $quality['dimensions'] );
                    $quality['band'] = Rules::band( $quality['score'] );
                }
                self::append_quality( $state, $scope, $object, $quality );
            }
            if ( 'categories' === $scope ) {
                $state['cursor'] = absint( $state['cursor'] ) + count( $objects );
            }
            // A user may pause/restart while this worker is running. Never
            // overwrite that explicit newer state with an old batch.
            $current_state = self::state( $scope );
            if ( ! in_array( (string) ( $current_state['status'] ?? '' ), array( 'queued', 'running' ), true )
                || (string) ( $current_state['started_gmt'] ?? '' ) !== (string) $state['started_gmt'] ) {
                return;
            }

            self::trim_samples( $state );
            $limit_reached = absint( $state['processed'] ) >= absint( $state['filters']['rows'] ?? 25 );
            $effective_batch = min( self::BATCH_SIZE, max( 1, absint( $state['filters']['rows'] ?? 25 ) - ( absint( $state['processed'] ) - count( $objects ) ) ) );
            if ( $limit_reached || count( $objects ) < $effective_batch ) {
                self::complete( $scope, $state );
            } else {
                self::save_indexes( $state );
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
