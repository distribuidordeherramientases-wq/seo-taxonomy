<?php
defined( 'ABSPATH' ) || exit;

/**
 * Anonymous customer-feedback loop for Dependiente.
 *
 * Two evidence channels:
 * - implicit: a customer clicks a recommended product;
 * - explicit: a customer resolves an ambiguity through a closed clarification.
 *
 * No IP address or personal identifier is stored. The browser sends a random
 * session id and only its SHA-256 hash is persisted.
 */
final class TCD_Feedback {
    const DB_VERSION = '1.0.0';
    const DB_OPTION = 'tcd_feedback_db_version';
    const RETENTION_DAYS = 180;
    const MAX_EVENTS_PER_SESSION_HOUR = 120;

    public static function events_table() {
        global $wpdb;
        return $wpdb->prefix . 'tcd_feedback_events';
    }

    public static function learning_table() {
        global $wpdb;
        return $wpdb->prefix . 'tcd_feedback_learning';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $events = self::events_table();
        $learning = self::learning_table();

        dbDelta(
            "CREATE TABLE {$events} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_hash char(64) NOT NULL,
                session_hash char(64) NOT NULL,
                query_hash char(64) NOT NULL,
                query_text varchar(255) NOT NULL,
                event_type varchar(32) NOT NULL,
                product_id bigint(20) unsigned NULL,
                semantic_group varchar(50) NULL,
                semantic_slug varchar(190) NULL,
                semantic_label varchar(255) NULL,
                depth tinyint(3) unsigned NOT NULL DEFAULT 0,
                confidence decimal(6,2) NOT NULL DEFAULT 0,
                context_json longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY event_hash (event_hash),
                KEY query_event (query_hash,event_type),
                KEY product_id (product_id),
                KEY semantic_target (semantic_group,semantic_slug),
                KEY session_hash (session_hash),
                KEY created_at (created_at)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$learning} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                query_hash char(64) NOT NULL,
                normalized_query varchar(255) NOT NULL,
                target_type varchar(20) NOT NULL,
                target_key char(64) NOT NULL,
                product_id bigint(20) unsigned NULL,
                semantic_group varchar(50) NULL,
                semantic_slug varchar(190) NULL,
                semantic_label varchar(255) NULL,
                click_sessions int unsigned NOT NULL DEFAULT 0,
                clarification_sessions int unsigned NOT NULL DEFAULT 0,
                weighted_score int unsigned NOT NULL DEFAULT 0,
                evidence_share decimal(7,4) NOT NULL DEFAULT 0,
                confidence decimal(7,4) NOT NULL DEFAULT 0,
                status varchar(20) NOT NULL DEFAULT 'staged',
                first_seen datetime NOT NULL,
                last_seen datetime NOT NULL,
                promoted_at datetime NULL,
                PRIMARY KEY (id),
                UNIQUE KEY query_target (query_hash,target_key),
                KEY query_status (query_hash,status),
                KEY status_last (status,last_seen),
                KEY product_id (product_id),
                KEY semantic_target (semantic_group,semantic_slug)
            ) {$charset};"
        );

        update_option( self::DB_OPTION, self::DB_VERSION, false );
    }

    public static function boot() {
        if ( self::DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
            self::install();
        }
    }

    public static function normalize_query( $query ) {
        $query = trim( sanitize_text_field( (string) $query ) );
        $normalized = class_exists( 'TCD_Intelligence', false )
            ? TCD_Intelligence::normalize( $query )
            : sanitize_title( $query );
        return substr( trim( (string) $normalized ), 0, 255 );
    }

    public static function query_hash( $query ) {
        return hash( 'sha256', self::normalize_query( $query ) );
    }

    public static function session_hash( $session_id ) {
        $session_id = preg_replace( '/[^a-zA-Z0-9._:-]/', '', (string) $session_id );
        if ( strlen( $session_id ) < 8 || strlen( $session_id ) > 96 ) {
            return '';
        }
        return hash( 'sha256', 'tcd-feedback|' . $session_id );
    }

    public static function record_search( $query, $session_id, $depth, $decision, $ranked, $clarification = array() ) {
        $context = array(
            'decision'=>(array) $decision,
            'ranked_ids'=>array_values( array_filter( array_map( static function ( $row ) {
                return absint( is_array( $row ) ? ( $row['id'] ?? 0 ) : 0 );
            }, array_slice( (array) $ranked, 0, 12 ) ) ) ),
            'clarification'=>is_array( $clarification ) ? $clarification : array(),
        );

        return self::record_event( array(
            'query'=>$query,
            'session_id'=>$session_id,
            'event_type'=>'search',
            'depth'=>$depth,
            'confidence'=>(float) ( $decision['confidence'] ?? 0 ),
            'context'=>$context,
        ) );
    }

    public static function record_click( $query, $session_id, $product_id, $depth = 0, $confidence = 0 ) {
        $product_id = absint( $product_id );
        if ( ! $product_id || 'publish' !== get_post_status( $product_id ) || 'product' !== get_post_type( $product_id ) ) {
            return new WP_Error( 'tcd_feedback_product', 'El producto indicado no es válido.' );
        }

        return self::record_event( array(
            'query'=>$query,
            'session_id'=>$session_id,
            'event_type'=>'click',
            'product_id'=>$product_id,
            'depth'=>$depth,
            'confidence'=>$confidence,
        ) );
    }

    public static function record_clarification( $query, $session_id, $option, $depth = 1, $confidence = 0 ) {
        $option = is_array( $option ) ? $option : array();
        $group = sanitize_key( (string) ( $option['group'] ?? '' ) );
        $slug = sanitize_title( (string) ( $option['slug'] ?? '' ) );
        $label = trim( sanitize_text_field( (string) ( $option['label'] ?? '' ) ) );

        if ( ! $group || ! $slug || ! $label ) {
            return new WP_Error( 'tcd_feedback_clarification', 'La opción de aclaración no es válida.' );
        }

        return self::record_event( array(
            'query'=>$query,
            'session_id'=>$session_id,
            'event_type'=>'clarification',
            'semantic_group'=>$group,
            'semantic_slug'=>$slug,
            'semantic_label'=>$label,
            'depth'=>$depth,
            'confidence'=>$confidence,
        ) );
    }

    public static function record_other( $query, $session_id, $text, $depth = 1 ) {
        $text = trim( sanitize_text_field( (string) $text ) );
        if ( strlen( $text ) < 2 ) {
            return new WP_Error( 'tcd_feedback_other', 'La aclaración está vacía.' );
        }

        return self::record_event( array(
            'query'=>$query,
            'session_id'=>$session_id,
            'event_type'=>'clarification_other',
            'semantic_label'=>substr( $text, 0, 255 ),
            'depth'=>$depth,
            'context'=>array( 'free_text'=>$text ),
        ) );
    }

    private static function record_event( $args ) {
        global $wpdb;

        $args = wp_parse_args( (array) $args, array(
            'query'=>'',
            'session_id'=>'',
            'event_type'=>'',
            'product_id'=>0,
            'semantic_group'=>'',
            'semantic_slug'=>'',
            'semantic_label'=>'',
            'depth'=>0,
            'confidence'=>0,
            'context'=>array(),
        ) );

        $query = trim( sanitize_text_field( (string) $args['query'] ) );
        if ( ! $query || mb_strlen( $query ) > 180 ) {
            return new WP_Error( 'tcd_feedback_query', 'La consulta no es válida.' );
        }

        $session_hash = self::session_hash( $args['session_id'] );
        if ( ! $session_hash ) {
            return new WP_Error( 'tcd_feedback_session', 'La sesión de feedback no es válida.' );
        }

        $event_type = sanitize_key( (string) $args['event_type'] );
        if ( ! in_array( $event_type, array( 'search','click','clarification','clarification_other' ), true ) ) {
            return new WP_Error( 'tcd_feedback_event', 'El tipo de feedback no es válido.' );
        }

        if ( ! self::allow_event( $session_hash ) ) {
            return new WP_Error( 'tcd_feedback_rate', 'Demasiados eventos de feedback en esta sesión.', array( 'status'=>429 ) );
        }

        $normalized = self::normalize_query( $query );
        $query_hash = hash( 'sha256', $normalized );
        $product_id = absint( $args['product_id'] );
        $semantic_group = sanitize_key( (string) $args['semantic_group'] );
        $semantic_slug = sanitize_title( (string) $args['semantic_slug'] );
        $semantic_label = substr( trim( sanitize_text_field( (string) $args['semantic_label'] ) ), 0, 255 );
        $depth = min( 2, absint( $args['depth'] ) );
        $confidence = max( 0, min( 100, (float) $args['confidence'] ) );

        $target_raw = 'none';
        if ( $product_id ) {
            $target_raw = 'product:' . $product_id;
        } elseif ( $semantic_group && $semantic_slug ) {
            $target_raw = 'semantic:' . $semantic_group . ':' . $semantic_slug;
        } elseif ( $semantic_label ) {
            $target_raw = 'text:' . self::normalize_query( $semantic_label );
        }
        $target_key = hash( 'sha256', $target_raw );

        // Same session + same query + same target + same event/depth counts once.
        $event_hash = hash( 'sha256', implode( '|', array(
            $session_hash, $query_hash, $event_type, $target_key, $depth,
        ) ) );

        $table = self::events_table();
        $sql = $wpdb->prepare(
            "INSERT IGNORE INTO {$table}
            (event_hash,session_hash,query_hash,query_text,event_type,product_id,semantic_group,semantic_slug,semantic_label,depth,confidence,context_json,created_at)
            VALUES (%s,%s,%s,%s,%s,%d,%s,%s,%s,%d,%f,%s,%s)",
            $event_hash,
            $session_hash,
            $query_hash,
            substr( $query, 0, 255 ),
            $event_type,
            $product_id,
            $semantic_group,
            $semantic_slug,
            $semantic_label,
            $depth,
            $confidence,
            wp_json_encode( (array) $args['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            current_time( 'mysql' )
        );

        $inserted = $wpdb->query( $sql );
        if ( false === $inserted ) {
            self::trace( 'ERROR', 'feedback_db_error', array(
                'event_type'=>$event_type,
                'error'=>substr( (string) $wpdb->last_error, 0, 1000 ),
            ) );
            return new WP_Error( 'tcd_feedback_db', 'No se pudo guardar el feedback.' );
        }

        if ( 0 === $inserted ) {
            return array( 'stored'=>false, 'duplicate'=>true );
        }

        self::trace( 'INFO', 'feedback_event', array(
            'event_type'=>$event_type,
            'query'=>$normalized,
            'product_id'=>$product_id,
            'semantic_group'=>$semantic_group,
            'semantic_slug'=>$semantic_slug,
            'depth'=>$depth,
        ) );

        $aggregate = array();
        if ( in_array( $event_type, array( 'click','clarification' ), true ) ) {
            $aggregate = self::recalculate_target( array(
                'query_hash'=>$query_hash,
                'normalized_query'=>$normalized,
                'target_type'=>$product_id ? 'product' : 'semantic',
                'target_key'=>$target_key,
                'product_id'=>$product_id,
                'semantic_group'=>$semantic_group,
                'semantic_slug'=>$semantic_slug,
                'semantic_label'=>$semantic_label,
            ) );
            self::recalculate_competitors( $query_hash, $target_key );
        }

        return array(
            'stored'=>true,
            'duplicate'=>false,
            'aggregate'=>$aggregate,
        );
    }

    private static function recalculate_target( $target ) {
        global $wpdb;

        $events = self::events_table();
        $learning = self::learning_table();
        $query_hash = (string) ( $target['query_hash'] ?? '' );
        $target_type = sanitize_key( (string) ( $target['target_type'] ?? '' ) );
        $product_id = absint( $target['product_id'] ?? 0 );
        $group = sanitize_key( (string) ( $target['semantic_group'] ?? '' ) );
        $slug = sanitize_title( (string) ( $target['semantic_slug'] ?? '' ) );
        $target_key = substr( (string) ( $target['target_key'] ?? '' ), 0, 191 );

        if ( 'product' === $target_type ) {
            $click_sessions = absint( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT session_hash) FROM {$events}
                 WHERE query_hash=%s AND event_type='click' AND product_id=%d",
                $query_hash, $product_id
            ) ) );
            $clarification_sessions = 0;
            $total_sessions = absint( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$events}
                 WHERE query_hash=%s AND event_type='click'",
                $query_hash
            ) ) );
        } else {
            $click_sessions = 0;
            $clarification_sessions = absint( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT session_hash) FROM {$events}
                 WHERE query_hash=%s AND event_type='clarification'
                   AND semantic_group=%s AND semantic_slug=%s",
                $query_hash, $group, $slug
            ) ) );
            $total_sessions = absint( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$events}
                 WHERE query_hash=%s AND event_type='clarification'",
                $query_hash
            ) ) );
        }

        $share = $total_sessions ? ( $click_sessions + $clarification_sessions ) / $total_sessions : 0;
        $weighted = $click_sessions + ( 3 * $clarification_sessions );

        $status = 'staged';
        if ( $clarification_sessions >= 2 && $share >= 0.60 ) {
            $status = 'active';
        } elseif ( $click_sessions >= 4 && $share >= 0.65 ) {
            $status = 'active';
        } elseif ( $weighted >= 6 && ( $click_sessions + $clarification_sessions ) >= 3 && $share >= 0.60 ) {
            $status = 'active';
        } elseif ( $clarification_sessions >= 1 || $click_sessions >= 2 ) {
            $status = 'validated';
        }

        $confidence = min(
            0.98,
            max(
                0.10,
                0.30 + ( 0.06 * $click_sessions ) + ( 0.14 * $clarification_sessions ) + ( 0.30 * min( 1, $share ) )
            )
        );

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id,status,promoted_at,first_seen FROM {$learning} WHERE query_hash=%s AND target_key=%s LIMIT 1",
            $query_hash, $target_key
        ), ARRAY_A );

        $now = current_time( 'mysql' );
        $previous_status = (string) ( $existing['status'] ?? '' );
        $newly_promoted = 'active' === $status && 'active' !== $previous_status;
        $promoted_at = (string) ( $existing['promoted_at'] ?? '' );
        if ( $newly_promoted ) {
            if ( ! $promoted_at ) $promoted_at = $now;
            self::trace( 'INFO', 'feedback_promoted', array(
                'query'=>(string) ( $target['normalized_query'] ?? '' ),
                'target_type'=>$target_type,
                'target_key'=>$target_key,
                'click_sessions'=>$click_sessions,
                'clarification_sessions'=>$clarification_sessions,
                'share'=>round( $share, 4 ),
                'confidence'=>round( $confidence, 4 ),
            ) );
        }

        $row = array(
            'query_hash'=>$query_hash,
            'normalized_query'=>substr( (string) ( $target['normalized_query'] ?? '' ), 0, 255 ),
            'target_type'=>$target_type,
            'target_key'=>$target_key,
            'product_id'=>$product_id ?: null,
            'semantic_group'=>$group ?: null,
            'semantic_slug'=>$slug ?: null,
            'semantic_label'=>substr( (string) ( $target['semantic_label'] ?? '' ), 0, 255 ) ?: null,
            'click_sessions'=>$click_sessions,
            'clarification_sessions'=>$clarification_sessions,
            'weighted_score'=>$weighted,
            'evidence_share'=>$share,
            'confidence'=>$confidence,
            'status'=>$status,
            'first_seen'=>(string) ( $existing['first_seen'] ?? $now ),
            'last_seen'=>$now,
            'promoted_at'=>$promoted_at ?: null,
        );

        $wpdb->replace( $learning, $row );

        return array(
            'status'=>$status,
            'click_sessions'=>$click_sessions,
            'clarification_sessions'=>$clarification_sessions,
            'weighted_score'=>$weighted,
            'share'=>round( $share, 4 ),
            'confidence'=>round( $confidence, 4 ),
            'newly_promoted'=>$newly_promoted,
            'previous_status'=>$previous_status,
        );
    }

    private static function recalculate_competitors( $query_hash, $except_target_key ) {
        global $wpdb;
        $table = self::learning_table();
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE query_hash=%s AND target_key<>%s
             ORDER BY id ASC LIMIT 50",
            (string) $query_hash,
            (string) $except_target_key
        ), ARRAY_A );

        foreach ( $rows as $row ) {
            self::recalculate_target( array(
                'query_hash'=>(string) ( $row['query_hash'] ?? '' ),
                'normalized_query'=>(string) ( $row['normalized_query'] ?? '' ),
                'target_type'=>(string) ( $row['target_type'] ?? '' ),
                'target_key'=>(string) ( $row['target_key'] ?? '' ),
                'product_id'=>absint( $row['product_id'] ?? 0 ),
                'semantic_group'=>(string) ( $row['semantic_group'] ?? '' ),
                'semantic_slug'=>(string) ( $row['semantic_slug'] ?? '' ),
                'semantic_label'=>(string) ( $row['semantic_label'] ?? '' ),
            ) );
        }
    }

    public static function active_product_scores( $query ) {
        global $wpdb;
        $hash = self::query_hash( $query );
        $table = self::learning_table();
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT product_id,click_sessions,evidence_share,confidence
             FROM {$table}
             WHERE query_hash=%s AND target_type='product' AND status='active'
             ORDER BY confidence DESC,click_sessions DESC,id ASC LIMIT 20",
            $hash
        ), ARRAY_A );

        $out = array();
        foreach ( $rows as $row ) {
            $id = absint( $row['product_id'] ?? 0 );
            if ( ! $id ) continue;
            $out[ $id ] = array(
                'score'=>420 + (int) round( 260 * (float) ( $row['confidence'] ?? 0 ) ),
                'confidence'=>(float) ( $row['confidence'] ?? 0 ),
                'sessions'=>absint( $row['click_sessions'] ?? 0 ),
                'share'=>(float) ( $row['evidence_share'] ?? 0 ),
            );
        }
        return $out;
    }

    public static function active_semantic_hints( $query ) {
        global $wpdb;
        $hash = self::query_hash( $query );
        $table = self::learning_table();
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT semantic_group,semantic_slug,semantic_label,clarification_sessions,evidence_share,confidence
             FROM {$table}
             WHERE query_hash=%s AND target_type='semantic' AND status='active'
             ORDER BY confidence DESC,clarification_sessions DESC,id ASC LIMIT 12",
            $hash
        ), ARRAY_A );

        $out = array();
        foreach ( $rows as $row ) {
            $group = sanitize_key( (string) ( $row['semantic_group'] ?? '' ) );
            $slug = sanitize_title( (string) ( $row['semantic_slug'] ?? '' ) );
            if ( ! $group || ! $slug ) continue;
            $out[] = array(
                'group'=>$group,
                'slug'=>$slug,
                'label'=>(string) ( $row['semantic_label'] ?? '' ),
                'weight'=>190 + (int) round( 110 * (float) ( $row['confidence'] ?? 0 ) ),
                'source'=>'customer_feedback',
            );
        }
        return $out;
    }

    public static function clarification_options( $query, $ranked, $interpretation, $limit = 5 ) {
        global $wpdb;

        $limit = min( 5, max( 3, absint( $limit ) ) );
        $ids = array_values( array_unique( array_filter( array_map( static function ( $row ) {
            return absint( is_array( $row ) ? ( $row['id'] ?? 0 ) : 0 );
        }, array_slice( (array) $ranked, 0, 24 ) ) ) );

        if ( ! $ids ) return array();

        $known = array();
        foreach ( (array) ( $interpretation['groups'] ?? array() ) as $group ) {
            $key = sanitize_key( (string) ( $group['group'] ?? '' ) ) . ':' . sanitize_title( (string) ( $group['slug'] ?? '' ) );
            $known[ $key ] = true;
        }

        $rel = $wpdb->prefix . 'seo_object_vocabulary';
        $voc = $wpdb->prefix . 'seo_vocabulary';
        if ( self::table_exists( $rel ) && self::table_exists( $voc ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $sql = "SELECT r.object_id,v.semantic_group,v.slug,v.label
                    FROM {$rel} r
                    INNER JOIN {$voc} v ON v.id=r.vocabulary_id
                    WHERE r.object_type='product' AND r.status=1 AND v.active=1
                      AND r.object_id IN ({$placeholders})
                      AND v.semantic_group IN ('aplicacion','tipo','subtipo','plataforma','rol')
                    LIMIT 800";
            $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );

            $rank_map = array();
            foreach ( $ids as $position => $id ) $rank_map[ $id ] = $position;

            $groups = array();
            foreach ( $rows as $row ) {
                $group = sanitize_key( (string) ( $row['semantic_group'] ?? '' ) );
                $slug = sanitize_title( (string) ( $row['slug'] ?? '' ) );
                $label = trim( (string) ( $row['label'] ?? '' ) );
                if ( ! $group || ! $slug || ! $label || isset( $known[ $group . ':' . $slug ] ) ) continue;
                if ( ! isset( $groups[ $group ][ $slug ] ) ) {
                    $groups[ $group ][ $slug ] = array(
                        'group'=>$group,
                        'slug'=>$slug,
                        'label'=>$label,
                        'count'=>0,
                        'rank'=>999,
                    );
                }
                $groups[ $group ][ $slug ]['count']++;
                $product_id = absint( $row['object_id'] ?? 0 );
                $groups[ $group ][ $slug ]['rank'] = min(
                    absint( $groups[ $group ][ $slug ]['rank'] ),
                    absint( $rank_map[ $product_id ] ?? 999 )
                );
            }

            $preference = array( 'aplicacion'=>5, 'tipo'=>4, 'subtipo'=>3, 'plataforma'=>2, 'rol'=>1 );
            $best_group = '';
            $best_score = -1;
            foreach ( $groups as $group => $options ) {
                $distinct = count( $options );
                if ( $distinct < 2 ) continue;
                $coverage = 0;
                foreach ( $options as $option ) $coverage += absint( $option['count'] ?? 0 );
                $score = min( 5, $distinct ) * 100 + min( 99, $coverage ) + ( 10 * absint( $preference[ $group ] ?? 0 ) );
                if ( $score > $best_score ) {
                    $best_group = $group;
                    $best_score = $score;
                }
            }

            if ( $best_group && ! empty( $groups[ $best_group ] ) ) {
                $options = array_values( $groups[ $best_group ] );
                usort( $options, static function ( $a, $b ) {
                    $cmp = absint( $b['count'] ?? 0 ) <=> absint( $a['count'] ?? 0 );
                    if ( 0 !== $cmp ) return $cmp;
                    $cmp = absint( $a['rank'] ?? 999 ) <=> absint( $b['rank'] ?? 999 );
                    if ( 0 !== $cmp ) return $cmp;
                    return strcmp( (string) ( $a['label'] ?? '' ), (string) ( $b['label'] ?? '' ) );
                } );

                return array(
                    'prompt'=>self::prompt_for_group( $best_group ),
                    'group'=>$best_group,
                    'options'=>array_slice( $options, 0, $limit ),
                );
            }
        }

        $categories = class_exists( 'TCD_Intelligence', false )
            ? TCD_Intelligence::suggestions_from_products( $ranked, $limit )
            : array();

        if ( count( $categories ) >= 2 ) {
            $options = array();
            foreach ( $categories as $category ) {
                $options[] = array(
                    'group'=>'product_cat',
                    'slug'=>sanitize_title( (string) ( $category['slug'] ?? '' ) ),
                    'label'=>(string) ( $category['name'] ?? '' ),
                    'count'=>absint( $category['count'] ?? 0 ),
                    'rank'=>absint( $category['first_rank'] ?? 999 ),
                );
            }
            return array(
                'prompt'=>'¿Qué familia se acerca más a lo que necesitas?',
                'group'=>'product_cat',
                'options'=>$options,
            );
        }

        return array();
    }

    public static function consolidate( $limit = 200 ) {
        global $wpdb;
        $table = self::learning_table();
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status IN ('staged','validated','active')
                 ORDER BY last_seen ASC,id ASC LIMIT %d",
                min( 500, max( 1, absint( $limit ) ) )
            ),
            ARRAY_A
        );

        $promoted = 0;
        $checked = 0;
        foreach ( $rows as $row ) {
            $checked++;
            $result = self::recalculate_target( array(
                'query_hash'=>(string) ( $row['query_hash'] ?? '' ),
                'normalized_query'=>(string) ( $row['normalized_query'] ?? '' ),
                'target_type'=>(string) ( $row['target_type'] ?? '' ),
                'target_key'=>(string) ( $row['target_key'] ?? '' ),
                'product_id'=>absint( $row['product_id'] ?? 0 ),
                'semantic_group'=>(string) ( $row['semantic_group'] ?? '' ),
                'semantic_slug'=>(string) ( $row['semantic_slug'] ?? '' ),
                'semantic_label'=>(string) ( $row['semantic_label'] ?? '' ),
            ) );
            if ( ! empty( $result['newly_promoted'] ) ) $promoted++;
        }

        self::prune();

        return array(
            'checked'=>$checked,
            'promoted'=>$promoted,
            'stats'=>self::stats(),
        );
    }

    public static function stats() {
        global $wpdb;
        $events = self::events_table();
        $learning = self::learning_table();

        $stats = array(
            'events'=>0,
            'searches'=>0,
            'clicks'=>0,
            'clarifications'=>0,
            'other'=>0,
            'staged'=>0,
            'validated'=>0,
            'active'=>0,
            'active_products'=>0,
            'active_semantic'=>0,
        );

        foreach ( (array) $wpdb->get_results(
            "SELECT event_type,COUNT(*) total FROM {$events} GROUP BY event_type",
            ARRAY_A
        ) as $row ) {
            $total = absint( $row['total'] ?? 0 );
            $stats['events'] += $total;
            $type = sanitize_key( (string) ( $row['event_type'] ?? '' ) );
            if ( 'search' === $type ) $stats['searches'] = $total;
            elseif ( 'click' === $type ) $stats['clicks'] = $total;
            elseif ( 'clarification' === $type ) $stats['clarifications'] = $total;
            elseif ( 'clarification_other' === $type ) $stats['other'] = $total;
        }

        foreach ( (array) $wpdb->get_results(
            "SELECT status,target_type,COUNT(*) total FROM {$learning} GROUP BY status,target_type",
            ARRAY_A
        ) as $row ) {
            $status = sanitize_key( (string) ( $row['status'] ?? '' ) );
            $target_type = sanitize_key( (string) ( $row['target_type'] ?? '' ) );
            $total = absint( $row['total'] ?? 0 );
            if ( isset( $stats[ $status ] ) ) $stats[ $status ] += $total;
            if ( 'active' === $status && 'product' === $target_type ) $stats['active_products'] += $total;
            if ( 'active' === $status && 'semantic' === $target_type ) $stats['active_semantic'] += $total;
        }

        return $stats;
    }

    private static function prune() {
        global $wpdb;
        $last = absint( get_option( 'tcd_feedback_last_prune', 0 ) );
        if ( $last && time() - $last < DAY_IN_SECONDS ) return;

        $events = self::events_table();
        $learning = self::learning_table();
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );

        $wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE created_at<%s", $cutoff ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$learning} WHERE status<>'active' AND last_seen<%s",
            $cutoff
        ) );

        update_option( 'tcd_feedback_last_prune', time(), false );
    }

    private static function allow_event( $session_hash ) {
        $key = 'tcd_fb_rate_' . substr( $session_hash, 0, 24 );
        $count = absint( get_transient( $key ) );
        if ( $count >= self::MAX_EVENTS_PER_SESSION_HOUR ) return false;
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
        return true;
    }

    private static function prompt_for_group( $group ) {
        $prompts = array(
            'aplicacion'=>'¿Para qué aplicación lo necesitas?',
            'tipo'=>'¿Qué tipo de solución encaja mejor?',
            'subtipo'=>'¿Qué variante necesitas?',
            'plataforma'=>'¿Para qué equipo o plataforma lo necesitas?',
            'rol'=>'¿Qué función debe cumplir?',
        );
        return (string) ( $prompts[ sanitize_key( $group ) ] ?? '¿Cuál de estas opciones encaja mejor?' );
    }

    private static function table_exists( $table ) {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === (string) $table;
    }

    private static function trace( $level, $event, $context = array() ) {
        if ( class_exists( 'TCD_Learning_Log', false ) ) {
            TCD_Learning_Log::write( $level, $event, $context );
        }
    }
}
