<?php
defined( 'ABSPATH' ) || exit;

/**
 * Intelligence layer for TaxoCommerce Dependiente.
 *
 * Converts the auditable L1-L10 snapshots into runtime semantic rules and
 * weighted product-language memory. It also evaluates each lesson before its
 * staged intelligence is promoted to the public runtime.
 */
final class TCD_Intelligence {
    const DB_VERSION = '1.0.0';
    const DB_OPTION = 'tcd_intelligence_db_version';
    const INTERPRETER_STATE = 'tcd_interpreter_state';
    const INTERPRETER_PROGRESS = 'tcd_interpreter_regression_progress';
    const INTERPRETER_EVALUATOR_REVISION = 'm11-l9-runtime-v1';
    const QUALITY_PROGRESS = 'tcd_quality_eval_progress';
    const QUALITY_PROGRESS_VERSION = 5;
    const QUALITY_EVALUATOR_REVISION = 'l9-snapshot-facts-v2';
    const L2_IDENTITY_BACKFILL = 'tcd_l2_identity_backfill';
    const MAX_SIGNALS_PER_PRODUCT = 32;

    public static function quality_table() {
        global $wpdb;
        return $wpdb->prefix . 'tcd_quality_checks';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::quality_table();
        $charset = $wpdb->get_charset_collate();
        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                snapshot_id bigint(20) unsigned NOT NULL,
                lesson smallint(5) unsigned NOT NULL,
                evaluated int unsigned NOT NULL DEFAULT 0,
                pass_top1 int unsigned NOT NULL DEFAULT 0,
                pass_top3 int unsigned NOT NULL DEFAULT 0,
                pass_top8 int unsigned NOT NULL DEFAULT 0,
                failed int unsigned NOT NULL DEFAULT 0,
                pass_any_ratio decimal(8,4) NOT NULL DEFAULT 0,
                threshold decimal(8,4) NOT NULL DEFAULT 0,
                status varchar(20) NOT NULL DEFAULT 'pending',
                report_json longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY snapshot_lesson (snapshot_id,lesson),
                KEY lesson_status (lesson,status)
            ) {$charset};"
        );
        update_option( self::DB_OPTION, self::DB_VERSION, false );
    }

    public static function boot() {
        if ( self::DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
            self::install();
        }
        add_action( 'admin_post_tcd_quality_csv', array( __CLASS__, 'download_quality_csv' ) );
        add_action( 'admin_post_tcd_quality_data_csv', array( __CLASS__, 'download_quality_data_csv' ) );
        add_action( 'admin_post_tcd_quality_audit_json', array( __CLASS__, 'download_quality_audit_json' ) );
        add_action( 'admin_post_tcd_interpreter_audit_json', array( __CLASS__, 'download_interpreter_audit_json' ) );
    }

    public static function reset_modern_generated() {
        global $wpdb;
        $rules = TCD_Knowledge_Transfer::rules_table();
        $l9 = TCD_Knowledge_Transfer::l9_table();
        $quality = self::quality_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source=%s', $rules, 'modern_learning' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE source_brain_id LIKE %s OR source_brain_id LIKE %s", $l9, 'modern:%', 'maintenance:%' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $quality ) );
        delete_option( self::INTERPRETER_STATE );
        delete_option( self::INTERPRETER_PROGRESS );
        delete_option( self::QUALITY_PROGRESS );
        delete_option( self::L2_IDENTITY_BACKFILL );
    }

    public static function stage_id( $lesson, $snapshot_id ) {
        return 'modern:L' . absint( $lesson ) . ':S' . absint( $snapshot_id );
    }

    /**
     * Backfill exact L2 identity for snapshots learned before v2_l2_identity
     * existed. It reads only the frozen Dependiente knowledge table; it never
     * rereads the live WooCommerce catalogue.
     */
    public static function l2_identity_backfill_step( $snapshot_id ) {
        global $wpdb;

        $snapshot_id = absint( $snapshot_id );
        if ( ! $snapshot_id ) {
            return new WP_Error( 'tcd_l2_identity_backfill_invalid', 'Falta la foto fija de L2.' );
        }

        $stage = self::stage_id( 2, $snapshot_id );
        $saved = get_option( self::L2_IDENTITY_BACKFILL, array() );
        if (
            is_array( $saved )
            && absint( $saved['snapshot_id'] ?? 0 ) === $snapshot_id
            && ! empty( $saved['done'] )
        ) {
            return $saved;
        }

        $knowledge = TCD_Academy::knowledge_table();
        $memory = TCD_Knowledge_Transfer::l9_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $total = absint( $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE lesson=2 AND status='learned'", $knowledge )
        ) );

        if ( ! $total ) {
            $result = array(
                'snapshot_id'=>$snapshot_id,
                'done'=>true,
                'processed'=>0,
                'total'=>0,
                'remaining'=>0,
                'written'=>0,
            );
            update_option( self::L2_IDENTITY_BACKFILL, $result, false );
            return $result;
        }

        $covered_count = static function () use ( $wpdb, $knowledge, $memory, $stage ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            return absint( $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT k.source_id)
                     FROM %i k
                     INNER JOIN %i m
                        ON m.product_id=k.source_id
                       AND m.lesson_key='v2_l2_identity'
                       AND m.signal_type='identity_title'
                       AND (m.active=1 OR m.source_brain_id=%s)
                     WHERE k.lesson=2 AND k.status='learned'",
                    $knowledge, $memory, $stage
                )
            ) );
        };
        $covered_before = $covered_count();
        if ( $covered_before >= $total ) {
            $result = array(
                'snapshot_id'=>$snapshot_id,
                'done'=>true,
                'processed'=>$total,
                'total'=>$total,
                'remaining'=>0,
                'written'=>0,
            );
            update_option( self::L2_IDENTITY_BACKFILL, $result, false );
            return $result;
        }

        $priority = class_exists( 'TCD_Load', false ) ? absint( TCD_Load::priority() ) : 3;
        $batch_sizes = array( 1=>40, 2=>70, 3=>120, 4=>180, 5=>250 );
        $limit = absint( $batch_sizes[ $priority ] ?? 120 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT k.source_type,k.source_id,k.title,k.context_json
                 FROM %i k
                 WHERE k.lesson=2
                   AND k.status='learned'
                   AND NOT EXISTS (
                       SELECT 1
                       FROM %i m
                       WHERE m.product_id=k.source_id
                         AND m.lesson_key='v2_l2_identity'
                         AND m.signal_type='identity_title'
                         AND (m.active=1 OR m.source_brain_id=%s)
                   )
                 ORDER BY k.id ASC
                 LIMIT %d",
                $knowledge,
                $memory,
                $stage,
                $limit
            ),
            ARRAY_A
        );

        $written = 0;
        foreach ( $rows as $row ) {
            $facts = json_decode( (string) ( $row['context_json'] ?? '' ), true );
            $item = array(
                'source_type'=>sanitize_key( (string) ( $row['source_type'] ?? 'product' ) ),
                'source_id'=>absint( $row['source_id'] ?? 0 ),
                'title'=>(string) ( $row['title'] ?? '' ),
                'facts'=>is_array( $facts ) ? $facts : array(),
            );
            $written += self::stage_l2_exact_identity( $item, $stage );
        }

        $covered_after = $covered_count();
        $remaining = max( 0, $total - $covered_after );
        $done = 0 === $remaining || ! $rows;

        $result = array(
            'snapshot_id'=>$snapshot_id,
            'done'=>$done,
            'processed'=>$covered_after,
            'total'=>$total,
            'remaining'=>$remaining,
            'batch'=>count( $rows ),
            'written'=>$written,
        );
        update_option( self::L2_IDENTITY_BACKFILL, $result, false );
        return $result;
    }

    private static function stage_l2_exact_identity( $item, $stage ) {
        global $wpdb;

        $product_id = absint( $item['source_id'] ?? 0 );
        if ( ! $product_id ) return 0;

        $facts = (array) ( $item['facts'] ?? array() );
        $signals = array();

        $title = trim( (string) ( $item['title'] ?? '' ) );
        $normalized_title = self::normalize( $title );
        if ( $normalized_title ) {
            $signals[] = array(
                'signal_type'=>'identity_title',
                'signal_text'=>$title,
                'normalized_signal'=>$normalized_title,
                'weight'=>320,
            );
        }

        $sku = trim( (string) ( $facts['sku'] ?? '' ) );
        if ( self::valid_identity_value( $sku ) ) {
            $normalized_sku = self::normalize( $sku );
            if ( $normalized_sku ) {
                $signals[] = array(
                    'signal_type'=>'identity_sku',
                    'signal_text'=>$sku,
                    'normalized_signal'=>$normalized_sku,
                    'weight'=>420,
                );
            }
        }

        $table = TCD_Knowledge_Transfer::l9_table();
        $written = 0;
        foreach ( $signals as $signal ) {
            $normalized = self::normalize( (string) ( $signal['normalized_signal'] ?? '' ) );
            if ( ! $normalized ) continue;
            $signal_type = sanitize_key( (string) ( $signal['signal_type'] ?? 'identity' ) );
            $hash = hash( 'sha256', implode( '|', array(
                'v2_l2_identity',
                $product_id,
                $signal_type,
                $normalized,
            ) ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $ok = $wpdb->replace( $table, array(
                'lesson_key'=>'v2_l2_identity',
                'product_id'=>$product_id,
                'signal_type'=>$signal_type,
                'signal_text'=>self::shorten( (string) ( $signal['signal_text'] ?? $normalized ), 250 ),
                'normalized_signal'=>self::shorten( $normalized, 250 ),
                'semantic_group'=>'identity',
                'vocabulary_id'=>null,
                'weight'=>min( 500, max( 1, absint( $signal['weight'] ?? 100 ) ) ),
                'source_hash'=>$hash,
                'metadata'=>wp_json_encode( array(
                    'origin'=>'l2_identity_backfill',
                    'lesson'=>2,
                    'purpose'=>'product_identity',
                    'stage'=>$stage,
                ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'active'=>0,
                'source_brain_id'=>$stage,
                'updated_at'=>current_time( 'mysql' ),
            ) );
            if ( false !== $ok ) $written++;
        }

        return $written;
    }

    public static function learn_item( $lesson, $item, $snapshot_id ) {
        $lesson = absint( $lesson );
        if ( ! is_array( $item ) || $lesson < 1 || $lesson > 10 ) {
            return;
        }
        $stage = self::stage_id( $lesson, $snapshot_id );
        if ( in_array( $lesson, array( 1, 3, 4, 5, 6, 7 ), true ) ) {
            foreach ( self::rules_from_item( $lesson, $item ) as $rule ) {
                self::stage_rule( $lesson, $item, $rule, $stage );
            }
        }
        if ( 2 === $lesson ) {
            self::stage_l2_identity( $item, $stage );
        }
        if ( 9 === $lesson ) {
            self::stage_l9_item( $item, $stage );
        }
    }

    private static function rules_from_item( $lesson, $item ) {
        $facts = (array) ( $item['facts'] ?? array() );
        $title = trim( (string) ( $item['title'] ?? '' ) );
        $terms = array();

        foreach ( (array) ( $facts['vocabulary'] ?? array() ) as $group => $rows ) {
            foreach ( (array) $rows as $row ) {
                if ( ! is_array( $row ) ) continue;
                $label = trim( (string) ( $row['label'] ?? $row['name'] ?? '' ) );
                $slug = sanitize_title( (string) ( $row['slug'] ?? $label ) );
                if ( $label && $slug ) {
                    $terms[] = array( 'group'=>sanitize_key( (string) $group ), 'slug'=>$slug, 'label'=>$label );
                }
            }
        }

        if ( ! empty( $facts['group'] ) && ! empty( $facts['slug'] ) ) {
            $terms[] = array(
                'group'=>sanitize_key( (string) $facts['group'] ),
                'slug'=>sanitize_title( (string) $facts['slug'] ),
                'label'=>$title ?: str_replace( '-', ' ', (string) $facts['slug'] ),
            );
        }

        $rules = array();
        $seen = array();
        $weights = array( 'tipo'=>190, 'aplicacion'=>180, 'subtipo'=>160, 'plataforma'=>145, 'rol'=>115 );
        foreach ( $terms as $term ) {
            $group = sanitize_key( (string) ( $term['group'] ?? '' ) );
            $slug = sanitize_title( (string) ( $term['slug'] ?? '' ) );
            $label = trim( (string) ( $term['label'] ?? '' ) );
            if ( ! $group || ! $slug || ! $label ) continue;
            $expressions = array( $label, str_replace( array( '-', '_' ), ' ', $slug ) );
            if ( in_array( $lesson, array( 1, 3, 6, 7 ), true ) && $title ) {
                $expressions[] = $title;
            }
            foreach ( array_values( array_unique( $expressions ) ) as $expression ) {
                $normalized = self::normalize( $expression );
                if ( ! $normalized ) continue;
                $key = $group . '|' . $slug . '|' . $normalized;
                if ( isset( $seen[ $key ] ) ) continue;
                $seen[ $key ] = true;
                $rules[] = array(
                    'rule_type'=>'alias',
                    'expression'=>$expression,
                    'normalized_expression'=>$normalized,
                    'canonical_expression'=>$label,
                    'match_type'=>false !== strpos( $normalized, ' ' ) ? 'phrase' : 'token',
                    'semantic_role'=>$group,
                    'source_group'=>$group,
                    'source_slug'=>$slug,
                    'target_group'=>$group,
                    'target_slug'=>$slug,
                    'relation_type'=>'synonym',
                    'result_role'=>$group,
                    'weight'=>absint( $weights[ $group ] ?? 120 ),
                    'priority'=>2,
                    'confidence'=>0.95,
                );
            }
        }
        return $rules;
    }

    private static function stage_rule( $lesson, $item, $rule, $stage ) {
        global $wpdb;
        $table = TCD_Knowledge_Transfer::rules_table();
        $source_type = sanitize_key( (string) ( $item['source_type'] ?? 'source' ) );
        $source_id = absint( $item['source_id'] ?? 0 );
        $hash_payload = array(
            $rule['rule_type'] ?? '', $rule['normalized_expression'] ?? '',
            $rule['source_group'] ?? '', $rule['source_slug'] ?? '',
            $rule['target_group'] ?? '', $rule['target_slug'] ?? '',
            $rule['relation_type'] ?? '',
        );
        $rule_hash = hash( 'sha256', wp_json_encode( $hash_payload ) );
        // The semantic rule is global knowledge, not product-specific memory.
        // Deduplicate identical concepts across thousands of source products.
        $rule_key = sanitize_key( 'modern_l' . absint( $lesson ) . '_' . substr( $rule_hash, 0, 40 ) );
        $now = current_time( 'mysql' );
        $payload = array(
            'rule_key'=>$rule_key,
            'rule_type'=>sanitize_key( (string) ( $rule['rule_type'] ?? 'alias' ) ),
            'expression'=>self::shorten( (string) ( $rule['expression'] ?? '' ), 255 ),
            'normalized_expression'=>self::shorten( (string) ( $rule['normalized_expression'] ?? '' ), 255 ),
            'canonical_expression'=>self::shorten( (string) ( $rule['canonical_expression'] ?? '' ), 255 ),
            'match_type'=>sanitize_key( (string) ( $rule['match_type'] ?? 'token' ) ),
            'semantic_role'=>sanitize_key( (string) ( $rule['semantic_role'] ?? '' ) ) ?: null,
            'source_group'=>sanitize_key( (string) ( $rule['source_group'] ?? '' ) ) ?: null,
            'source_slug'=>sanitize_title( (string) ( $rule['source_slug'] ?? '' ) ) ?: null,
            'context_group'=>null,
            'context_slug'=>null,
            'target_group'=>sanitize_key( (string) ( $rule['target_group'] ?? '' ) ) ?: null,
            'target_slug'=>sanitize_title( (string) ( $rule['target_slug'] ?? '' ) ) ?: null,
            'relation_type'=>sanitize_key( (string) ( $rule['relation_type'] ?? 'synonym' ) ),
            'result_role'=>sanitize_key( (string) ( $rule['result_role'] ?? '' ) ) ?: null,
            'weight'=>min( 500, max( 1, absint( $rule['weight'] ?? 100 ) ) ),
            'priority'=>min( 20, max( 1, absint( $rule['priority'] ?? 5 ) ) ),
            'confidence'=>max( 0, min( 1, (float) ( $rule['confidence'] ?? 0.9 ) ) ),
            'language'=>'es',
            'source'=>'modern_learning',
            'metadata'=>wp_json_encode( array(
                'lesson'=>absint( $lesson ),
                'source_type'=>$source_type,
                'source_id'=>$source_id,
                'snapshot_stage'=>$stage,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'active'=>0,
            'rule_hash'=>$rule_hash,
            'source_brain_id'=>$stage,
            'created_at'=>$now,
            'updated_at'=>$now,
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $wpdb->replace( $table, $payload );
    }

    private static function stage_l2_identity( $item, $stage ) {
        global $wpdb;
        $table = TCD_Knowledge_Transfer::l9_table();
        $product_id = absint( $item['source_id'] ?? 0 );
        if ( ! $product_id ) return;

        $facts = (array) ( $item['facts'] ?? array() );
        $title = trim( (string) ( $item['title'] ?? '' ) );
        $normalized_title = self::normalize( $title );
        $compact = self::without_stopwords( $normalized_title );
        $signals = array();

        if ( $normalized_title ) {
            self::put_signal( $signals, 'identity_title', $title, $normalized_title, 'identity', 0, 320 );
            if ( $compact && $compact !== $normalized_title ) {
                self::put_signal( $signals, 'identity_title', $title, $compact, 'identity', 0, 300 );
            }
            $identity_ngrams = self::ngrams( $compact ?: $normalized_title, 5 );
            foreach ( array_slice( $identity_ngrams, 0, 18 ) as $phrase ) {
                $words = count( array_filter( explode( ' ', $phrase ) ) );
                if ( $words < 2 && strlen( $phrase ) < 5 ) continue;
                $weight = $words >= 4 ? 260 : ( $words >= 2 ? 220 : 170 );
                self::put_signal( $signals, 'identity_title', $title, $phrase, 'identity', 0, $weight );
            }
        }

        $sku = trim( (string) ( $facts['sku'] ?? '' ) );
        if ( self::valid_identity_value( $sku ) ) {
            $normalized_sku = self::normalize( $sku );
            if ( $normalized_sku ) self::put_signal( $signals, 'identity_sku', $sku, $normalized_sku, 'identity', 0, 420 );
        }

        foreach ( array_slice( array_values( $signals ), 0, 20 ) as $signal ) {
            $normalized = self::normalize( (string) ( $signal['normalized_signal'] ?? '' ) );
            if ( ! $normalized ) continue;
            $hash = hash( 'sha256', implode( '|', array(
                'v2_l2_identity',
                $product_id,
                sanitize_key( (string) ( $signal['signal_type'] ?? 'identity' ) ),
                $normalized,
            ) ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $wpdb->replace( $table, array(
                'lesson_key'=>'v2_l2_identity',
                'product_id'=>$product_id,
                'signal_type'=>sanitize_key( (string) ( $signal['signal_type'] ?? 'identity' ) ),
                'signal_text'=>self::shorten( (string) ( $signal['signal_text'] ?? $normalized ), 250 ),
                'normalized_signal'=>self::shorten( $normalized, 250 ),
                'semantic_group'=>'identity',
                'vocabulary_id'=>null,
                'weight'=>min( 500, max( 1, absint( $signal['weight'] ?? 100 ) ) ),
                'source_hash'=>$hash,
                'metadata'=>wp_json_encode( array(
                    'origin'=>'modern_learning',
                    'lesson'=>2,
                    'purpose'=>'product_identity',
                    'stage'=>$stage,
                ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'active'=>0,
                'source_brain_id'=>$stage,
                'updated_at'=>current_time( 'mysql' ),
            ) );
        }
    }

    private static function valid_identity_value( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) return false;
        $normalized = strtoupper( preg_replace( '/\s+/u', '', $value ) );
        return ! in_array( $normalized, array( '#¡VALOR!', '#VALOR!', '#VALUE!', 'N/A', 'NA', 'NULL', 'NONE', '-' ), true );
    }

    private static function stage_l9_item( $item, $stage ) {
        global $wpdb;
        $table = TCD_Knowledge_Transfer::l9_table();
        $product_id = absint( $item['source_id'] ?? 0 );
        if ( ! $product_id ) return;
        $signals = self::signals_from_item( $item );
        foreach ( $signals as $signal ) {
            $normalized = self::normalize( (string) ( $signal['normalized_signal'] ?? '' ) );
            if ( ! $normalized ) continue;
            $hash = hash( 'sha256', implode( '|', array(
                'v2_l9_product_language', $product_id,
                sanitize_key( (string) ( $signal['signal_type'] ?? 'term' ) ),
                $normalized,
                sanitize_key( (string) ( $signal['semantic_group'] ?? '' ) ),
                absint( $signal['vocabulary_id'] ?? 0 ),
            ) ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $wpdb->replace( $table, array(
                'lesson_key'=>'v2_l9_product_language',
                'product_id'=>$product_id,
                'signal_type'=>sanitize_key( (string) ( $signal['signal_type'] ?? 'term' ) ),
                'signal_text'=>self::shorten( (string) ( $signal['signal_text'] ?? $normalized ), 250 ),
                'normalized_signal'=>self::shorten( $normalized, 250 ),
                'semantic_group'=>sanitize_key( (string) ( $signal['semantic_group'] ?? '' ) ) ?: null,
                'vocabulary_id'=>absint( $signal['vocabulary_id'] ?? 0 ) ?: null,
                'weight'=>min( 500, max( 1, absint( $signal['weight'] ?? 50 ) ) ),
                'source_hash'=>$hash,
                'metadata'=>wp_json_encode( array( 'origin'=>'modern_learning', 'stage'=>$stage ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'active'=>0,
                'source_brain_id'=>$stage,
                'updated_at'=>current_time( 'mysql' ),
            ) );
        }
    }

    private static function signals_from_item( $item ) {
        $facts = (array) ( $item['facts'] ?? array() );
        $signals = array();
        self::add_signal( $signals, 'title', (string) ( $item['title'] ?? '' ), 240, 'title', 0, true );
        // customer_need is an independent evaluation query. Never store it as
        // product memory or M11 would be testing a sentence L9 memorised verbatim.

        foreach ( (array) ( $facts['vocabulary'] ?? array() ) as $group => $rows ) {
            $weight = array( 'tipo'=>195, 'aplicacion'=>185, 'subtipo'=>165, 'plataforma'=>145, 'rol'=>75 )[ sanitize_key( (string) $group ) ] ?? 130;
            foreach ( array_slice( (array) $rows, 0, 3 ) as $row ) {
                if ( ! is_array( $row ) ) continue;
                self::add_signal(
                    $signals,
                    sanitize_key( (string) $group ),
                    (string) ( $row['label'] ?? $row['name'] ?? '' ),
                    $weight,
                    sanitize_key( (string) $group ),
                    absint( $row['id'] ?? 0 ),
                    true
                );
            }
        }

        foreach ( array_slice( (array) ( $facts['categories_detail'] ?? array() ), 0, 4 ) as $row ) {
            if ( is_array( $row ) ) self::add_signal( $signals, 'category', (string) ( $row['name'] ?? '' ), 115, 'category', 0, true );
        }
        foreach ( array_slice( (array) ( $facts['brands_detail'] ?? array() ), 0, 2 ) as $row ) {
            if ( is_array( $row ) ) self::add_signal( $signals, 'brand', (string) ( $row['name'] ?? '' ), 65, 'brand', 0, false );
        }
        foreach ( array_slice( (array) ( $facts['tags_detail'] ?? array() ), 0, 6 ) as $row ) {
            if ( is_array( $row ) ) self::add_signal( $signals, 'tag', (string) ( $row['name'] ?? '' ), 140, 'tag', 0, true );
        }

        $features = (array) ( $facts['features'] ?? array() );
        foreach ( (array) ( $features['attribute_labels'] ?? array() ) as $name => $values ) {
            foreach ( array_slice( (array) $values, 0, 3 ) as $value ) {
                self::add_signal( $signals, 'attribute', trim( (string) $name . ' ' . (string) $value ), 155, 'attribute', 0, true );
            }
        }

        foreach ( self::description_phrases( (string) ( $facts['short_description'] ?? '' ) ) as $phrase ) {
            self::add_signal( $signals, 'description', $phrase, 125, 'description', 0, true );
        }

        uasort( $signals, static function ( $a, $b ) {
            return absint( $b['weight'] ?? 0 ) <=> absint( $a['weight'] ?? 0 );
        } );
        return array_slice( array_values( $signals ), 0, self::MAX_SIGNALS_PER_PRODUCT );
    }

    private static function add_signal( &$signals, $type, $text, $weight, $group, $vocabulary_id, $tokenize ) {
        $text = trim( wp_strip_all_tags( (string) $text ) );
        $normalized = self::normalize( $text );
        if ( strlen( $normalized ) < 2 ) return;
        self::put_signal( $signals, $type, $text, $normalized, $group, $vocabulary_id, $weight );
        $compact = self::without_stopwords( $normalized );
        if ( $compact && $compact !== $normalized ) {
            self::put_signal( $signals, $type, $text, $compact, $group, $vocabulary_id, (int) round( $weight * 0.92 ) );
        }
        if ( $tokenize ) {
            foreach ( array_slice( array_filter( explode( ' ', $compact ?: $normalized ), static function ( $v ) { return strlen( $v ) >= 3; } ), 0, 5 ) as $token ) {
                foreach ( self::token_variants( $token ) as $variant ) {
                    self::put_signal( $signals, $type, $text, $variant, $group, $vocabulary_id, max( 30, (int) round( $weight * 0.48 ) ) );
                }
            }
        }
    }

    private static function put_signal( &$signals, $type, $text, $normalized, $group, $vocabulary_id, $weight ) {
        $key = sanitize_key( (string) $type ) . '|' . $normalized;
        $candidate = array(
            'signal_type'=>sanitize_key( (string) $type ),
            'signal_text'=>self::shorten( $text, 250 ),
            'normalized_signal'=>self::shorten( $normalized, 250 ),
            'semantic_group'=>sanitize_key( (string) $group ),
            'vocabulary_id'=>absint( $vocabulary_id ),
            'weight'=>min( 500, max( 1, absint( $weight ) ) ),
        );
        if ( ! isset( $signals[ $key ] ) || $candidate['weight'] > absint( $signals[ $key ]['weight'] ?? 0 ) ) {
            $signals[ $key ] = $candidate;
        }
    }

    public static function finalize_lesson( $lesson, $snapshot_id ) {
        global $wpdb;
        $lesson = absint( $lesson );
        $snapshot_id = absint( $snapshot_id );
        $report = self::evaluate_lesson( $lesson, $snapshot_id );
        $threshold = self::threshold( $lesson );
        $ratio = (float) ( $report['pass_any_ratio'] ?? 0 );
        $passed = $ratio >= $threshold;
        $report['threshold'] = $threshold;
        $report['passed'] = $passed;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $wpdb->replace( self::quality_table(), array(
            'snapshot_id'=>$snapshot_id,
            'lesson'=>$lesson,
            'evaluated'=>absint( $report['evaluated'] ?? 0 ),
            'pass_top1'=>absint( $report['pass_top1'] ?? 0 ),
            'pass_top3'=>absint( $report['pass_top3'] ?? 0 ),
            'pass_top8'=>absint( $report['pass_top8'] ?? 0 ),
            'failed'=>absint( $report['failed'] ?? 0 ),
            'pass_any_ratio'=>$ratio,
            'threshold'=>$threshold,
            'status'=>$passed ? 'passed' : 'failed',
            'report_json'=>wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'created_at'=>current_time( 'mysql' ),
        ) );
        if ( ! $passed ) {
            return new WP_Error(
                'tcd_quality_gate',
                sprintf( 'L%d no supera el control de calidad: %.1f%%, mínimo %.1f%%.', $lesson, 100 * $ratio, 100 * $threshold ),
                $report
            );
        }
        self::activate_stage( self::stage_id( $lesson, $snapshot_id ) );
        return $report;
    }

    private static function threshold( $lesson ) {
        $thresholds = array( 1=>0.94, 2=>0.88, 3=>0.88, 4=>0.86, 5=>0.85, 6=>0.92, 7=>0.87, 8=>0.90, 9=>0.88, 10=>0.90 );
        return (float) ( $thresholds[ absint( $lesson ) ] ?? 0.88 );
    }

    public static function reset_quality_progress( $lesson = 0, $snapshot_id = 0 ) {
        $state = get_option( self::QUALITY_PROGRESS, array() );
        if ( ! is_array( $state ) || ! $state ) return;
        if ( $lesson && absint( $state['lesson'] ?? 0 ) !== absint( $lesson ) ) return;
        if ( $snapshot_id && absint( $state['snapshot_id'] ?? 0 ) !== absint( $snapshot_id ) ) return;
        delete_option( self::QUALITY_PROGRESS );
    }

    public static function quality_progress( $lesson = 0, $snapshot_id = 0 ) {
        $state = get_option( self::QUALITY_PROGRESS, array() );
        if ( ! is_array( $state ) || ! $state ) return array();
        if ( $lesson && absint( $state['lesson'] ?? 0 ) !== absint( $lesson ) ) return array();
        if ( $snapshot_id && absint( $state['snapshot_id'] ?? 0 ) !== absint( $snapshot_id ) ) return array();

        $done = absint( $state['cursor'] ?? 0 );
        $total = absint( $state['total'] ?? 0 );
        $report = (array) ( $state['report'] ?? array() );
        $state['done'] = $done;
        $state['percent'] = $total ? min( 100, round( 100 * $done / $total, 1 ) ) : 0;
        $state['pass_top1'] = absint( $report['pass_top1'] ?? 0 );
        $state['pass_top3'] = absint( $report['pass_top3'] ?? 0 );
        $state['pass_top8'] = absint( $report['pass_top8'] ?? 0 );
        $state['failed'] = absint( $report['failed'] ?? 0 );
        return $state;
    }

    private static function quality_batch_size() {
        $priority = class_exists( 'TCD_Load', false ) ? absint( TCD_Load::priority() ) : 3;
        $sizes = array( 1=>4, 2=>6, 3=>10, 4=>15, 5=>20 );
        return absint( $sizes[ $priority ] ?? 10 );
    }

    /**
     * Incremental quality evaluation.
     *
     * The old evaluator processed the complete 180-case sample inside one
     * request. This method persists the deterministic sample and report between
     * ticks so the UI can show real progress and the process can be stopped
     * safely between batches.
     */
    public static function quality_step( $lesson, $snapshot_id ) {
        global $wpdb;

        $lesson = absint( $lesson );
        $snapshot_id = absint( $snapshot_id );
        if ( $lesson < 1 || $lesson > 10 || ! $snapshot_id ) {
            return new WP_Error( 'tcd_quality_progress_invalid', 'No se puede iniciar la evaluación de calidad.' );
        }

        $state = get_option( self::QUALITY_PROGRESS, array() );
        if (
            ! is_array( $state )
            || absint( $state['version'] ?? 0 ) !== self::QUALITY_PROGRESS_VERSION
            || absint( $state['lesson'] ?? 0 ) !== $lesson
            || absint( $state['snapshot_id'] ?? 0 ) !== $snapshot_id
        ) {
            $table = TCD_Academy::knowledge_table();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $all_ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM %i WHERE lesson=%d AND status='learned' ORDER BY source_type ASC,source_id ASC",
                $table, $lesson
            ) ) );
            if ( ! $all_ids ) {
                return new WP_Error( 'tcd_quality_progress_empty', 'La lección no tiene casos aprendidos para evaluar.' );
            }

            $sample_rows = self::representative_sample(
                array_map( static function ( $id ) { return array( 'id'=>absint( $id ) ); }, $all_ids ),
                180
            );
            $sample_ids = array_values( array_filter( array_map( static function ( $row ) {
                return absint( $row['id'] ?? 0 );
            }, $sample_rows ) ) );

            $state = array(
                'version'=>self::QUALITY_PROGRESS_VERSION,
                'lesson'=>$lesson,
                'snapshot_id'=>$snapshot_id,
                'cursor'=>0,
                'total'=>count( $sample_ids ),
                'population'=>count( $all_ids ),
                'sample_ids'=>$sample_ids,
                'started_at'=>current_time( 'mysql' ),
                'updated_at'=>current_time( 'mysql' ),
                'report'=>array(
                    'evaluated'=>0,
                    'pass_top1'=>0,
                    'pass_top3'=>0,
                    'pass_top8'=>0,
                    'failed'=>0,
                    'pass_any_ratio'=>0,
                    'sample'=>array(),
                    'failures'=>array(),
                    'failure_origins'=>array(
                        'datos_fuente'=>0,
                        'ambiguedad'=>0,
                        'dependiente'=>0,
                        'sin_diagnostico'=>0,
                    ),
                    'data_issue_counts'=>array(),
                    'recommendations'=>array(),
                    'sample_method'=>'distributed_deterministic',
                    'population'=>count( $all_ids ),
                    'quality_engine_revision'=>self::QUALITY_EVALUATOR_REVISION,
                    'evaluated_plugin_version'=>defined( 'TAXOCOMMERCE_DEPENDIENTE_VERSION' ) ? TAXOCOMMERCE_DEPENDIENTE_VERSION : 'unknown',
                    'query_origins'=>array(),
                ),
            );
            update_option( self::QUALITY_PROGRESS, $state, false );
        }

        $sample_ids = array_values( array_map( 'absint', (array) ( $state['sample_ids'] ?? array() ) ) );
        $cursor = min( count( $sample_ids ), absint( $state['cursor'] ?? 0 ) );
        $batch_ids = array_slice( $sample_ids, $cursor, self::quality_batch_size() );
        $report = (array) ( $state['report'] ?? array() );
        $stage = self::stage_id( $lesson, $snapshot_id );
        $batch_evaluated = 0;

        if ( $batch_ids ) {
            $table = TCD_Academy::knowledge_table();
            $placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list contains only generated %d/%s placeholders and all identifiers/values are bound through $wpdb->prepare().
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,source_type,source_id,title,context_json,status FROM %i WHERE id IN ({$placeholders})",
                    array_merge( array( $table ), $batch_ids )
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $by_id = array();
            foreach ( $rows as $row ) $by_id[ absint( $row['id'] ?? 0 ) ] = $row;

            foreach ( $batch_ids as $knowledge_id ) {
                if ( empty( $by_id[ $knowledge_id ] ) ) continue;
                $row = $by_id[ $knowledge_id ];
                $evaluation = self::evaluate_row( $lesson, $row, $stage );
                $evaluation['source_type'] = sanitize_key( (string) ( $evaluation['source_type'] ?? $row['source_type'] ?? '' ) );
                $evaluation['source_id'] = absint( $evaluation['source_id'] ?? $row['source_id'] ?? 0 );
                $evaluation['title'] = (string) ( $evaluation['title'] ?? $row['title'] ?? '' );
                $evaluation['diagnosis'] = self::diagnose_evaluation( $lesson, $row, $evaluation );
                // L10 recursively evaluates its archived L9 rows. Keep
                // provenance for these regressions, not just direct L9 exams.
                if ( 9 === $lesson || ( 10 === $lesson && 9 === absint( $evaluation['original_lesson'] ?? 0 ) ) ) {
                    $query_origin = sanitize_key( (string) ( $evaluation['query_origin'] ?? 'unknown' ) );
                    $report['query_origins'][ $query_origin ] = absint( $report['query_origins'][ $query_origin ] ?? 0 ) + 1;
                }

                $report['evaluated'] = absint( $report['evaluated'] ?? 0 ) + 1;
                if ( ! empty( $evaluation['top1'] ) ) $report['pass_top1'] = absint( $report['pass_top1'] ?? 0 ) + 1;
                if ( ! empty( $evaluation['top3'] ) ) $report['pass_top3'] = absint( $report['pass_top3'] ?? 0 ) + 1;
                if ( ! empty( $evaluation['top8'] ) ) $report['pass_top8'] = absint( $report['pass_top8'] ?? 0 ) + 1;
                if ( empty( $evaluation['top8'] ) ) {
                    $report['failed'] = absint( $report['failed'] ?? 0 ) + 1;
                    $report['failures'][] = $evaluation;
                    $origin = sanitize_key( (string) ( $evaluation['diagnosis']['origin'] ?? 'sin_diagnostico' ) );
                    if ( ! isset( $report['failure_origins'][ $origin ] ) ) $origin = 'sin_diagnostico';
                    $report['failure_origins'][ $origin ] = absint( $report['failure_origins'][ $origin ] ?? 0 ) + 1;
                    foreach ( (array) ( $evaluation['diagnosis']['data_issues'] ?? array() ) as $issue ) {
                        $code = sanitize_key( (string) ( $issue['code'] ?? '' ) );
                        if ( ! $code ) continue;
                        $report['data_issue_counts'][ $code ] = absint( $report['data_issue_counts'][ $code ] ?? 0 ) + 1;
                    }
                }
                if ( count( (array) ( $report['sample'] ?? array() ) ) < 12 ) $report['sample'][] = $evaluation;
            }

            $batch_evaluated = count( $batch_ids );
            $cursor += $batch_evaluated;
            $report['pass_any_ratio'] = absint( $report['evaluated'] ?? 0 )
                ? absint( $report['pass_top8'] ?? 0 ) / absint( $report['evaluated'] )
                : 0;
            $state['cursor'] = $cursor;
            $state['report'] = $report;
            $state['updated_at'] = current_time( 'mysql' );
            update_option( self::QUALITY_PROGRESS, $state, false );
        }

        $total = count( $sample_ids );
        if ( $cursor < $total ) {
            return array(
                'done'=>false,
                'lesson'=>$lesson,
                'snapshot_id'=>$snapshot_id,
                'evaluated'=>$cursor,
                'total'=>$total,
                'percent'=>$total ? round( 100 * $cursor / $total, 1 ) : 0,
                'batch_evaluated'=>$batch_evaluated,
                'pass_top1'=>absint( $report['pass_top1'] ?? 0 ),
                'pass_top3'=>absint( $report['pass_top3'] ?? 0 ),
                'pass_top8'=>absint( $report['pass_top8'] ?? 0 ),
                'failed'=>absint( $report['failed'] ?? 0 ),
            );
        }

        $threshold = self::threshold( $lesson );
        $ratio = (float) ( $report['pass_any_ratio'] ?? 0 );
        $passed = $ratio >= $threshold;
        $report['threshold'] = $threshold;
        $report['passed'] = $passed;
        $report['recommendations'] = self::quality_recommendations( $lesson, $report );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $wpdb->replace( self::quality_table(), array(
            'snapshot_id'=>$snapshot_id,
            'lesson'=>$lesson,
            'evaluated'=>absint( $report['evaluated'] ?? 0 ),
            'pass_top1'=>absint( $report['pass_top1'] ?? 0 ),
            'pass_top3'=>absint( $report['pass_top3'] ?? 0 ),
            'pass_top8'=>absint( $report['pass_top8'] ?? 0 ),
            'failed'=>absint( $report['failed'] ?? 0 ),
            'pass_any_ratio'=>$ratio,
            'threshold'=>$threshold,
            'status'=>$passed ? 'passed' : 'failed',
            'report_json'=>wp_json_encode( $report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'created_at'=>current_time( 'mysql' ),
        ) );

        if ( $passed ) self::activate_stage( self::stage_id( $lesson, $snapshot_id ) );
        delete_option( self::QUALITY_PROGRESS );

        return array(
            'done'=>true,
            'passed'=>$passed,
            'lesson'=>$lesson,
            'snapshot_id'=>$snapshot_id,
            'evaluated'=>absint( $report['evaluated'] ?? 0 ),
            'total'=>$total,
            'percent'=>100,
            'batch_evaluated'=>$batch_evaluated,
            'report'=>$report,
        );
    }

    private static function evaluate_lesson( $lesson, $snapshot_id ) {
        global $wpdb;
        $table = TCD_Academy::knowledge_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $all_rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id,source_type,source_id,title,context_json,status FROM %i WHERE lesson=%d AND status='learned' ORDER BY source_type ASC,source_id ASC",
            $table, $lesson
        ), ARRAY_A );
        $rows = self::representative_sample( $all_rows, 180 );
        $result = array(
            'evaluated'=>0,
            'pass_top1'=>0,
            'pass_top3'=>0,
            'pass_top8'=>0,
            'failed'=>0,
            'pass_any_ratio'=>0,
            'sample'=>array(),
            'failures'=>array(),
            'failure_origins'=>array(
                'datos_fuente'=>0,
                'ambiguedad'=>0,
                'dependiente'=>0,
                'sin_diagnostico'=>0,
            ),
            'data_issue_counts'=>array(),
            'recommendations'=>array(),
            'sample_method'=>'distributed_deterministic',
            'population'=>count( $all_rows ),
            'quality_engine_revision'=>self::QUALITY_EVALUATOR_REVISION,
            'evaluated_plugin_version'=>defined( 'TAXOCOMMERCE_DEPENDIENTE_VERSION' ) ? TAXOCOMMERCE_DEPENDIENTE_VERSION : 'unknown',
            'query_origins'=>array(),
        );
        $stage = self::stage_id( $lesson, $snapshot_id );

        foreach ( $rows as $row ) {
            $evaluation = self::evaluate_row( $lesson, $row, $stage );
            $evaluation['source_type'] = sanitize_key( (string) ( $evaluation['source_type'] ?? $row['source_type'] ?? '' ) );
            $evaluation['source_id'] = absint( $evaluation['source_id'] ?? $row['source_id'] ?? 0 );
            $evaluation['title'] = (string) ( $evaluation['title'] ?? $row['title'] ?? '' );
            $evaluation['diagnosis'] = self::diagnose_evaluation( $lesson, $row, $evaluation );
            if ( 9 === $lesson || ( 10 === $lesson && 9 === absint( $evaluation['original_lesson'] ?? 0 ) ) ) {
                $query_origin = sanitize_key( (string) ( $evaluation['query_origin'] ?? 'unknown' ) );
                $result['query_origins'][ $query_origin ] = absint( $result['query_origins'][ $query_origin ] ?? 0 ) + 1;
            }
            $result['evaluated']++;
            if ( ! empty( $evaluation['top1'] ) ) $result['pass_top1']++;
            if ( ! empty( $evaluation['top3'] ) ) $result['pass_top3']++;
            if ( ! empty( $evaluation['top8'] ) ) $result['pass_top8']++;
            if ( empty( $evaluation['top8'] ) ) {
                $result['failed']++;
                $result['failures'][] = $evaluation;
                $origin = sanitize_key( (string) ( $evaluation['diagnosis']['origin'] ?? 'sin_diagnostico' ) );
                if ( ! isset( $result['failure_origins'][ $origin ] ) ) $origin = 'sin_diagnostico';
                $result['failure_origins'][ $origin ]++;
                foreach ( (array) ( $evaluation['diagnosis']['data_issues'] ?? array() ) as $issue ) {
                    $code = sanitize_key( (string) ( $issue['code'] ?? '' ) );
                    if ( ! $code ) continue;
                    $result['data_issue_counts'][ $code ] = absint( $result['data_issue_counts'][ $code ] ?? 0 ) + 1;
                }
            }
            if ( count( $result['sample'] ) < 12 ) $result['sample'][] = $evaluation;
        }

        $result['pass_any_ratio'] = $result['evaluated'] ? $result['pass_top8'] / $result['evaluated'] : 0;
        $result['recommendations'] = self::quality_recommendations( $lesson, $result );
        return $result;
    }

    private static function representative_sample( $rows, $limit ) {
        $rows = array_values( (array) $rows );
        $count = count( $rows );
        $limit = min( max( 1, absint( $limit ) ), $count ?: 1 );
        if ( $count <= $limit ) return $rows;

        $out = array();
        $seen = array();
        for ( $i = 0; $i < $limit; $i++ ) {
            $index = (int) floor( ( $i + 0.5 ) * $count / $limit );
            $index = min( $count - 1, max( 0, $index ) );
            if ( isset( $seen[ $index ] ) ) continue;
            $seen[ $index ] = true;
            $out[] = $rows[ $index ];
        }
        return $out;
    }

    private static function evaluate_row( $lesson, $row, $stage ) {
        $id = absint( $row['source_id'] ?? 0 );
        $title = trim( (string) ( $row['title'] ?? '' ) );
        $facts = json_decode( (string) ( $row['context_json'] ?? '' ), true );
        $facts = is_array( $facts ) ? $facts : array();
        $ids = array();
        $query = $title;
        $query_origin = 'identity';
        $observed_evidence = array();

        if ( 9 === $lesson && '' !== $title && $facts ) {
            // The snapshot keeps the original facts, but its saved customer
            // language was generated by an obsolete algorithm. Rebuild it at
            // evaluation time for *every* sampled row, not only failed rows.
            // This is not training on the test answer: no product ID is used.
            $need = TCD_Sources::customer_need_from_facts( $facts, $title );
            $query = (string) ( $need['query'] ?? '' );
            $facts['customer_need_scope'] = (string) ( $need['scope'] ?? 'family' );
            $query_origin = 'snapshot_facts';
        } elseif ( 9 === $lesson && ! empty( $facts['customer_need'] ) ) {
            $query = (string) $facts['customer_need'];
            $query_origin = 'legacy_missing_facts';
        }

        if ( in_array( $lesson, array( 2, 4, 9 ), true ) ) {
            foreach ( self::search_products( $query, 8, $stage ) as $item ) {
                $ids[] = absint( $item['id'] ?? 0 );
                if ( 9 === $lesson ) {
                    $observed_evidence[] = array(
                        'id'=>absint( $item['id'] ?? 0 ),
                        'score'=>(float) ( $item['score'] ?? 0 ),
                        'l9_strength'=>absint( $item['l9_strength'] ?? 0 ),
                        'l9_matched'=>absint( $item['l9_matched'] ?? 0 ),
                        'semantic_groups'=>array_slice( (array) ( $item['semantic_groups'] ?? array() ), 0, 6 ),
                        'sources'=>array_slice( (array) ( $item['sources'] ?? array() ), 0, 6 ),
                    );
                }
            }
        } elseif ( 1 === $lesson ) {
            foreach ( self::search_categories( $query, 8, $stage ) as $item ) $ids[] = absint( $item['id'] ?? 0 );
        } elseif ( 5 === $lesson ) {
            foreach ( self::search_content( $query, 8, $stage ) as $item ) $ids[] = absint( $item['id'] ?? 0 );
        } elseif ( 6 === $lesson ) {
            foreach ( self::search_faqs( $query, 8, $stage ) as $item ) $ids[] = absint( $item['id'] ?? 0 );
        } elseif ( in_array( $lesson, array( 8, 10 ), true ) ) {
            $ok = 'learned' === (string) ( $facts['previous_status'] ?? '' );
            $original_lesson = absint( $facts['original_lesson'] ?? 0 );
            $original_context = (array) ( $facts['original_context'] ?? array() );
            if ( $ok && in_array( $original_lesson, array( 1, 2, 3, 4, 5, 6, 7, 9 ), true ) ) {
                $regression = self::evaluate_row(
                    $original_lesson,
                    array(
                        'source_type'=>sanitize_key( (string) ( $facts['original_type'] ?? '' ) ),
                        'source_id'=>absint( $facts['original_id'] ?? 0 ),
                        'title'=>$title,
                        'context_json'=>wp_json_encode( $original_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                        'status'=>'learned',
                    ),
                    $stage
                );
                $regression['review_source_id'] = $id;
                $regression['original_lesson'] = $original_lesson;
                return $regression;
            }
            return array(
                'source_id'=>$id,
                'query'=>$query,
                'original_lesson'=>$original_lesson,
                'top1'=>$ok,
                'top3'=>$ok,
                'top8'=>$ok,
            );
        } else {
            $interpretation = self::interpret( $query, $stage );
            $hits = (array) ( $interpretation['semantic_hits'] ?? array() );
            $target_slug = sanitize_title( (string) ( $facts['slug'] ?? '' ) );
            $positions = array();
            foreach ( $hits as $index => $hit ) {
                if ( ! $target_slug || $target_slug === sanitize_title( (string) ( $hit['target_slug'] ?? $hit['source_slug'] ?? '' ) ) ) $positions[] = $index + 1;
            }
            $pos = $positions ? min( $positions ) : 0;
            return array( 'source_id'=>$id, 'query'=>$query, 'top1'=>1 === $pos, 'top3'=>$pos > 0 && $pos <= 3, 'top8'=>$pos > 0 && $pos <= 8 );
        }

        $pos = array_search( $id, $ids, true );
        $exact_rank = false === $pos ? 0 : $pos + 1;
        $rank = $exact_rank;
        $family_rank = 0;
        // Family language cannot identify one arbitrarily selected SKU.
        // Report the strict product rank as a diagnostic, and evaluate family
        // suitability independently from the product-specific quality gate.
        if ( 9 === $lesson && 'family' === (string) ( $facts['customer_need_scope'] ?? '' ) ) {
            $family_rank = self::l9_family_rank( $ids, $facts, $stage );
            if ( $family_rank > 0 ) $rank = $family_rank;
        }
        return array(
            'source_type'=>sanitize_key( (string) ( $row['source_type'] ?? '' ) ),
            'source_id'=>$id,
            'title'=>$title,
            'query'=>$query,
            'rank'=>$rank,
            'exact_rank'=>$exact_rank,
            'family_rank'=>$family_rank,
            'need_scope'=>9 === $lesson ? (string) ( $facts['customer_need_scope'] ?? 'legacy' ) : 'identity',
            'query_origin'=>$query_origin,
            'observed_evidence'=>$observed_evidence,
            'top1'=>1 === $rank,
            'top3'=>$rank > 0 && $rank <= 3,
            'top8'=>$rank > 0 && $rank <= 8,
            'observed_ids'=>array_values( array_slice( $ids, 0, 8 ) ),
            'observed'=>self::observed_labels( (string) ( $row['source_type'] ?? '' ), $ids ),
        );
    }

    private static function l9_family_rank( $ids, $facts, $stage ) {
        $family = (array) ( $facts['vocabulary'] ?? array() );
        $groups = array();
        foreach ( array( 'subtipo', 'tipo' ) as $group ) {
            foreach ( array_slice( (array) ( $family[ $group ] ?? array() ), 0, 1 ) as $row ) {
                if ( ! is_array( $row ) ) continue;
                $slug = sanitize_title( (string) ( $row['slug'] ?? $row['label'] ?? '' ) );
                if ( $slug ) $groups[] = array( 'group'=>$group, 'slug'=>$slug );
            }
        }
        if ( ! $groups ) return 0;
        $candidate_ids = array_slice( array_values( array_filter( array_map( 'absint', (array) $ids ) ) ), 0, 8 );
        if ( ! $candidate_ids ) return 0;
        $matched = array();
        // One bounded membership query per semantic group, not per result.
        foreach ( $groups as $group ) {
            $members = self::products_for_vocabulary( $group['group'], $group['slug'], 8, $candidate_ids );
            foreach ( $members as $member ) $matched[ absint( $member ) ] = true;
        }
        foreach ( $candidate_ids as $offset => $product_id ) {
            if ( isset( $matched[ $product_id ] ) ) return $offset + 1;
        }
        return 0;
    }

    private static function observed_labels( $source_type, $ids ) {
        $out = array();
        foreach ( array_slice( array_values( array_unique( array_map( 'absint', (array) $ids ) ) ), 0, 8 ) as $id ) {
            if ( ! $id ) continue;
            $label = '';
            if ( in_array( $source_type, array( 'product','post','page','seo_faq' ), true ) ) {
                $label = (string) get_the_title( $id );
            } elseif ( false !== strpos( $source_type, 'term' ) || 'product_cat' === $source_type ) {
                $term = get_term( $id );
                if ( $term instanceof WP_Term ) $label = (string) $term->name;
            }
            $out[] = array( 'id'=>$id, 'title'=>$label );
        }
        return $out;
    }

    private static function diagnose_evaluation( $lesson, $row, $evaluation ) {
        global $wpdb;
        $facts = json_decode( (string) ( $row['context_json'] ?? '' ), true );
        $facts = is_array( $facts ) ? $facts : array();
        $source_type = sanitize_key( (string) ( $row['source_type'] ?? '' ) );
        $source_id = absint( $row['source_id'] ?? 0 );
        $title = trim( (string) ( $row['title'] ?? '' ) );
        $issues = self::data_issues_for_row( $lesson, $row );

        $origin = 'dependiente';
        $reason = 'Los datos básicos están presentes; el producto/contenido no quedó dentro del Top-8. Debe mejorarse memoria, interpretación o ranking.';
        $action = 'No modificar el contenido solo para hacer pasar el examen. Revisar la evidencia que utilizó Dependiente y reforzar su aprendizaje/ranking.';
        $correctable = false;

        if ( '' === $title || empty( $facts ) ) {
            $origin = 'datos_fuente';
            $reason = 'Faltan datos estructurales necesarios para identificar este elemento.';
            $action = 'Corregir los campos indicados en WordPress y volver a evaluar.';
            $correctable = true;
        } else {
            $normalized_title = self::normalize( $title );
            $duplicates = 0;
            if ( $normalized_title && in_array( $source_type, array( 'product','post','page' ), true ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
                $duplicates = absint( $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_status='publish' AND post_title=%s",
                    $source_type,
                    $title
                ) ) );
            }
            if ( $duplicates > 1 ) {
                $origin = 'ambiguedad';
                $reason = 'Hay varios elementos publicados con el mismo título exacto; el título por sí solo no distingue la referencia.';
                $action = 'Revisar duplicados y reforzar identidad con SKU/referencia/atributos diferenciadores.';
                $correctable = true;
            } elseif ( $issues ) {
                $hard = array_filter( $issues, static function ( $issue ) {
                    return self::is_hard_data_issue( (string) ( $issue['code'] ?? '' ) );
                } );
                if ( $hard ) {
                    $origin = 'datos_fuente';
                    $reason = 'El examen ha fallado y además existen carencias estructurales en los datos de origen.';
                    $action = 'Corregir primero los campos señalados y después reevaluar. Si sigue fallando, revisar Dependiente.';
                    $correctable = true;
                }
            }
        }

        return array(
            'origin'=>$origin,
            'correctable'=>$correctable,
            'reason'=>$reason,
            'action'=>$action,
            'data_issues'=>array_values( $issues ),
        );
    }

    private static function is_hard_data_issue( $code ) {
        $code = sanitize_key( (string) $code );
        $base = preg_replace( '/^l\d+_/', '', $code );
        return in_array( $base, array(
            'titulo_vacio',
            'titulo_demasiado_corto',
            'categoria_sin_slug',
            'categoria_con_productos_sin_vocabulary',
            'categoria_sin_tipo_aplicacion',
            'sin_categoria_producto',
            'sin_tipo',
            'sin_rol',
            'vocabulary_sin_grupo',
            'vocabulary_sin_slug',
            'sin_caracteristicas_producto',
            'sin_senales_diferenciadoras',
            'editorial_sin_vocabulary',
            'faq_sin_respuesta',
            'faq_sin_propietario',
            'relacion_cruzada_sin_grupo',
            'relacion_cruzada_sin_slug',
            'relacion_cruzada_sin_producto',
            'relacion_cruzada_sin_editorial',
            'sin_necesidad_cliente',
            'regresion_sin_origen',
            'deuda_previa_no_aprendida',
            'no_aprendido',
        ), true );
    }

    private static function data_issues_for_row( $lesson, $row ) {
        $lesson = absint( $lesson );
        $facts = json_decode( (string) ( $row['context_json'] ?? '' ), true );
        $facts = is_array( $facts ) ? $facts : array();
        $source_type = sanitize_key( (string) ( $row['source_type'] ?? '' ) );
        $title = trim( (string) ( $row['title'] ?? '' ) );
        $issues = array();

        if ( '' === $title ) {
            $issues[] = self::data_issue( 'titulo_vacio', 'title', '', 'El contenido no tiene título identificable.', 'Completar el título antes de volver a evaluar.' );
        } elseif ( strlen( self::normalize( $title ) ) < 4 ) {
            $issues[] = self::data_issue( 'titulo_demasiado_corto', 'title', $title, 'El título aporta muy poca identidad.', 'Revisar si el título identifica realmente el elemento.' );
        }

        if ( 1 === $lesson || 0 === strpos( $source_type, 'termproduct_cat' ) ) {
            $slug = trim( (string) ( $facts['slug'] ?? '' ) );
            if ( ! $slug ) $issues[] = self::data_issue( 'categoria_sin_slug', 'slug', '', 'La categoría no tiene slug utilizable.', 'Revisar el slug de la categoría.' );
            $products = absint( $facts['products'] ?? 0 );
            $vocabulary = (array) ( $facts['vocabulary'] ?? array() );
            if ( $products > 0 && ! $vocabulary ) {
                $issues[] = self::data_issue( 'categoria_con_productos_sin_vocabulary', 'vocabulary', '', 'La categoría tiene productos pero no tiene clasificación Vocabulary.', 'Revisar la clasificación semántica de la categoría.' );
            }
            if ( $products > 0 && empty( $vocabulary['tipo'] ) && empty( $vocabulary['aplicacion'] ) ) {
                $issues[] = self::data_issue( 'categoria_sin_tipo_aplicacion', 'vocabulary', '', 'La categoría con productos no tiene TIPO ni APLICACIÓN.', 'Asignar al menos el concepto semántico que describa correctamente la familia.' );
            }
        }

        if ( in_array( $lesson, array( 2, 4, 9 ), true ) || 'product' === $source_type ) {
            $sku = trim( (string) ( $facts['sku'] ?? '' ) );
            if ( ! self::valid_identity_value( $sku ) ) {
                $issues[] = self::data_issue( 'sku_invalido_o_vacio', 'sku', $sku, 'SKU vacío o con un valor de error/no utilizable.', 'Asignar un SKU válido si el producto debe tenerlo.' );
            } elseif ( preg_match( '/^(?:product|producto|item|sku)[-_ ]*\d*$/iu', $sku ) ) {
                $issues[] = self::data_issue(
                    'sku_generico_sospechoso',
                    'sku',
                    $sku,
                    'El SKU parece un identificador genérico y puede no distinguir correctamente la referencia.',
                    'Comprobar si es el SKU real del proveedor; corregirlo solo si es un placeholder.'
                );
            }
            $categories = array_values( array_filter( array_map( 'absint', (array) ( $facts['categories']['product_cat'] ?? array() ) ) ) );
            if ( ! $categories ) {
                $issues[] = self::data_issue( 'sin_categoria_producto', 'product_cat', '', 'El producto no tiene categoría de producto en la foto fija.', 'Asignar la categoría correcta.' );
            }
            $vocabulary = (array) ( $facts['vocabulary'] ?? array() );
            if ( empty( $vocabulary['tipo'] ) ) {
                $issues[] = self::data_issue( 'sin_tipo', 'vocabulary.tipo', '', 'Falta TIPO canónico.', 'Revisar/asignar TIPO en Vocabulary.' );
            }
            if ( empty( $vocabulary['rol'] ) ) {
                $issues[] = self::data_issue( 'sin_rol', 'vocabulary.rol', '', 'Falta ROL canónico.', 'Revisar/asignar ROL en Vocabulary.' );
            }
            if ( empty( $facts['short_description'] ) ) {
                $issues[] = self::data_issue( 'sin_resumen', 'short_description', '', 'Falta resumen/short description.', 'Completar un resumen específico del producto.' );
            }
            $brand_ids = array();
            foreach ( (array) ( $facts['brands'] ?? array() ) as $ids ) {
                $brand_ids = array_merge( $brand_ids, array_map( 'absint', (array) $ids ) );
            }
            if ( ! array_filter( $brand_ids ) && preg_match( '/\bVEVOR\b/ui', $title ) ) {
                $issues[] = self::data_issue( 'vevor_sin_marca_asignada', 'product_brand', '', 'El título identifica VEVOR pero no aparece una marca asignada en la foto fija.', 'Revisar la taxonomía de marca del producto.' );
            }
        }

        if ( 4 === $lesson && 'product' === $source_type ) {
            $features = (array) ( $facts['features'] ?? array() );
            $attribute_labels = (array) ( $features['attribute_labels'] ?? array() );
            $attribute_values = (array) ( $features['attributes'] ?? array() );
            $product_tags = (array) ( $features['product_tag'] ?? array() );
            $vocabulary = (array) ( $facts['vocabulary'] ?? array() );

            if ( ! $features ) {
                $issues[] = self::data_issue(
                    'sin_caracteristicas_producto',
                    'features',
                    '',
                    'L4 no dispone de características, atributos ni etiquetas del producto.',
                    'Completar atributos/etiquetas útiles o revisar la clasificación semántica del producto.'
                );
            }

            $has_specific_semantics = ! empty( $vocabulary['aplicacion'] )
                || ! empty( $vocabulary['plataforma'] )
                || ! empty( $vocabulary['subtipo'] );
            $has_specific_features = ! empty( $attribute_labels )
                || ! empty( $attribute_values )
                || ! empty( $product_tags );

            if ( ! $has_specific_semantics && ! $has_specific_features ) {
                $issues[] = self::data_issue(
                    'sin_senales_diferenciadoras',
                    'features/vocabulary',
                    '',
                    'El producto no aporta aplicación, plataforma, subtipo ni características que permitan diferenciar su necesidad.',
                    'Añadir únicamente atributos o conceptos semánticos reales que ayuden a distinguir para qué sirve o con qué es compatible.'
                );
            }

            if ( $attribute_values && ! $attribute_labels ) {
                $issues[] = self::data_issue(
                    'atributos_sin_etiqueta_legible',
                    'features.attribute_labels',
                    '',
                    'Hay atributos almacenados pero no se han podido resolver a etiquetas legibles para el cliente.',
                    'Revisar nombres/opciones de atributos y sus términos para que Dependiente pueda interpretarlos.'
                );
            }
        }

        if ( 7 === $lesson || 'cross' === $source_type ) {
            if ( empty( $facts['group'] ) ) {
                $issues[] = self::data_issue( 'relacion_cruzada_sin_grupo', 'semantic_group', '', 'La relación cruzada no tiene grupo semántico.', 'Revisar el término Vocabulary que conecta catálogo y contenido.' );
            }
            if ( empty( $facts['slug'] ) ) {
                $issues[] = self::data_issue( 'relacion_cruzada_sin_slug', 'slug', '', 'La relación cruzada no tiene concepto identificable.', 'Revisar el término Vocabulary y su slug.' );
            }
            if ( ! absint( $facts['product_uses'] ?? 0 ) ) {
                $issues[] = self::data_issue( 'relacion_cruzada_sin_producto', 'product_uses', '0', 'El concepto no tiene uso activo en productos.', 'Revisar las asignaciones Vocabulary a productos.' );
            }
            if ( ! absint( $facts['editorial_uses'] ?? 0 ) ) {
                $issues[] = self::data_issue( 'relacion_cruzada_sin_editorial', 'editorial_uses', '0', 'El concepto no tiene uso activo en posts o páginas.', 'Revisar las asignaciones Vocabulary al contenido editorial.' );
            }
        }

        if ( 9 === $lesson && 'product' === $source_type ) {
            $need = trim( (string) ( $facts['customer_need'] ?? '' ) );
            if ( '' === $need ) {
                $issues[] = self::data_issue(
                    'sin_necesidad_cliente',
                    'customer_need',
                    '',
                    'L9 no dispone de una necesidad natural independiente para evaluar el producto.',
                    'Revisar TIPO, ROL, aplicación y características que permiten construir una necesidad útil.'
                );
            }
        }

        if ( 3 === $lesson || 'vocabulary' === $source_type ) {
            if ( empty( $facts['group'] ) ) $issues[] = self::data_issue( 'vocabulary_sin_grupo', 'semantic_group', '', 'El término Vocabulary no tiene grupo semántico.', 'Asignar el grupo canónico correcto.' );
            if ( empty( $facts['slug'] ) ) $issues[] = self::data_issue( 'vocabulary_sin_slug', 'slug', '', 'El término Vocabulary no tiene slug.', 'Revisar el término Vocabulary.' );
            if ( 0 === absint( $facts['product_count'] ?? 0 ) ) {
                $issues[] = self::data_issue(
                    'vocabulary_sin_uso_actual',
                    'product_ids',
                    '',
                    'El término Vocabulary no tiene productos asociados actualmente. Esto no implica que sea incorrecto: puede ser un concepto válido sin uso temporal, un agrupador o un término preparado para catálogo futuro.',
                    'Mantener el término si sigue siendo semánticamente válido. Revisarlo sólo si existen indicios adicionales de obsolescencia, duplicidad o clasificación incorrecta.'
                );
            }
        }

        if ( 5 === $lesson && in_array( $source_type, array( 'post','page' ), true ) ) {
            if ( empty( $facts['vocabulary'] ) ) {
                $issues[] = self::data_issue( 'editorial_sin_vocabulary', 'vocabulary', '', 'El contenido editorial no tiene Vocabulary común con catálogo.', 'Asignar Vocabulary solo si existe una relación semántica real con el catálogo.' );
            }
        }

        if ( 6 === $lesson || 'faq' === $source_type ) {
            if ( '' === trim( (string) ( $facts['answer'] ?? '' ) ) ) {
                $issues[] = self::data_issue( 'faq_sin_respuesta', 'answer', '', 'La FAQ no tiene respuesta utilizable.', 'Completar la respuesta de la FAQ.' );
            }
            if ( ! absint( $facts['owner_id'] ?? 0 ) ) {
                $issues[] = self::data_issue( 'faq_sin_propietario', 'owner_id', '', 'La FAQ no está vinculada a una categoría o producto propietario.', 'Asignar el propietario/contexto correcto.' );
            }
        }

        if ( in_array( $lesson, array( 8, 10 ), true ) && 'review' === $source_type ) {
            $original_lesson = absint( $facts['original_lesson'] ?? 0 );
            $original_type = sanitize_key( (string) ( $facts['original_type'] ?? '' ) );
            $original_id = absint( $facts['original_id'] ?? 0 );
            $previous_status = sanitize_key( (string) ( $facts['previous_status'] ?? '' ) );
            $original_context = (array) ( $facts['original_context'] ?? array() );

            if ( ! $original_lesson || ! $original_type || ! $original_id ) {
                $issues[] = self::data_issue(
                    'regresion_sin_origen',
                    'review_origin',
                    '',
                    'La regresión no conserva una referencia completa a la fuente original.',
                    'Reconstruir la muestra de revisión desde el conocimiento de la lección original.'
                );
            } else {
                if ( 'learned' !== $previous_status ) {
                    $issues[] = self::data_issue(
                        'deuda_previa_no_aprendida',
                        'previous_status',
                        $previous_status,
                        'La fuente revisada ya arrastraba deuda: no estaba aprendida en L' . $original_lesson . '.',
                        'Corregir la causa en L' . $original_lesson . ' y volver a ejecutar la regresión.'
                    );
                }

                $original_row = array(
                    'source_type'=>$original_type,
                    'source_id'=>$original_id,
                    'title'=>$title,
                    'context_json'=>wp_json_encode( $original_context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                    'status'=>$previous_status,
                );
                foreach ( self::data_issues_for_row( $original_lesson, $original_row ) as $inherited ) {
                    $code = sanitize_key( 'l' . $original_lesson . '_' . (string) ( $inherited['code'] ?? 'deuda' ) );
                    $issues[] = self::data_issue(
                        $code,
                        'L' . $original_lesson . '.' . (string) ( $inherited['field'] ?? '' ),
                        (string) ( $inherited['current'] ?? '' ),
                        'Deuda heredada de L' . $original_lesson . ': ' . (string) ( $inherited['reason'] ?? '' ),
                        (string) ( $inherited['action'] ?? 'Corregir la fuente original y reevaluar.' )
                    );
                }
            }
        }

        return array_values( $issues );
    }

    private static function data_issue( $code, $field, $current, $reason, $action ) {
        return array(
            'code'=>sanitize_key( $code ),
            'field'=>(string) $field,
            'current'=>self::shorten( (string) $current, 500 ),
            'reason'=>(string) $reason,
            'action'=>(string) $action,
        );
    }

    private static function quality_recommendations( $lesson, $report ) {
        $recommendations = array();
        $origins = (array) ( $report['failure_origins'] ?? array() );
        if ( absint( $origins['datos_fuente'] ?? 0 ) ) {
            $recommendations[] = 'Corregir primero las incidencias clasificadas como datos_fuente y reevaluar la misma foto fija.';
        }
        if ( absint( $origins['ambiguedad'] ?? 0 ) ) {
            $recommendations[] = 'Revisar títulos/identificadores duplicados o demasiado similares; no eliminar variantes válidas solo para mejorar el porcentaje.';
        }
        if ( absint( $origins['dependiente'] ?? 0 ) ) {
            $recommendations[] = 'Los casos dependiente no deben corregirse en WordPress: hay que reforzar memoria, señales de identidad o ranking.';
        }
        switch ( absint( $lesson ) ) {
            case 1:
                $recommendations[] = 'L1 debe asegurar jerarquía, slug y clasificación semántica suficiente en categorías con productos.';
                break;
            case 2:
                $recommendations[] = 'L2 debe demostrar identidad de producto mediante título/SKU/categoría; L9 seguirá aprendiendo necesidades y lenguaje del cliente más adelante.';
                break;
            case 3:
                $recommendations[] = 'L3 debe mantener TIPO/ROL canónicos y slug válidos. Un término Vocabulary sin productos asociados actualmente es informativo y no debe bloquear la lección por sí solo.';
                break;
            case 4:
                $recommendations[] = 'L4 debe aportar señales diferenciadoras reales: aplicación, plataforma, subtipo, atributos o etiquetas útiles.';
                break;
            case 5:
                $recommendations[] = 'L5 solo debe conectar contenido editorial y catálogo cuando exista Vocabulary compartido real.';
                break;
            case 6:
                $recommendations[] = 'L6 exige FAQs con respuesta y propietario canónico (producto o categoría).';
                break;
            case 7:
                $recommendations[] = 'L7 exige que cada concepto cruzado tenga uso real tanto en catálogo como en contenido editorial.';
                break;
            case 8:
                $recommendations[] = 'L8 no debe ocultar deuda previa: cada fallo de regresión debe señalar la lección original que debe corregirse.';
                break;
            case 9:
                $recommendations[] = 'L9 debe construir una necesidad natural independiente y relacionarla con señales semánticas/producto.';
                break;
            case 10:
                $recommendations[] = 'L10 debe consolidar la deuda de L1-L9 y conservar el origen exacto de cada fallo de regresión.';
                break;
        }
        return array_values( array_unique( $recommendations ) );
    }

    public static function quality_csv_url( $lesson ) {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action'=>'tcd_quality_csv',
                    'lesson'=>absint( $lesson ),
                ),
                admin_url( 'admin-post.php' )
            ),
            'tcd_quality_csv_' . absint( $lesson )
        );
    }

    public static function download_quality_csv() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso.' );
        $lesson = absint( $_GET['lesson'] ?? 0 );
        check_admin_referer( 'tcd_quality_csv_' . $lesson );
        $quality = self::quality_for_lesson( $lesson );
        $report = json_decode( (string) ( $quality['report_json'] ?? '' ), true );
        $report = is_array( $report ) ? $report : array();
        if ( ! isset( $report['failures'], $report['failure_origins'] ) ) {
            $snapshot = TCD_Academy::snapshot_for_lesson( $lesson );
            $snapshot_id = absint( $snapshot['id'] ?? $quality['snapshot_id'] ?? 0 );
            if ( $snapshot_id ) $report = self::evaluate_lesson( $lesson, $snapshot_id );
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="dependiente_L' . $lesson . '_incidencias_' . gmdate( 'Ymd_His' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Authorized php://output CSV streaming requires direct stream writes and closure.
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, array(
            'lesson','source_type','source_id','title','query','rank','top8','origin','correctable',
            'issue_codes','fields','current_values','reason','recommended_action','observed_ids','observed_titles'
        ), ';', '"', '' );

        foreach ( (array) ( $report['failures'] ?? array() ) as $failure ) {
            $diagnosis = (array) ( $failure['diagnosis'] ?? array() );
            $issues = (array) ( $diagnosis['data_issues'] ?? array() );
            $codes = array();
            $fields = array();
            $values = array();
            foreach ( $issues as $issue ) {
                $codes[] = (string) ( $issue['code'] ?? '' );
                $fields[] = (string) ( $issue['field'] ?? '' );
                $values[] = (string) ( $issue['current'] ?? '' );
            }
            $observed = (array) ( $failure['observed'] ?? array() );
            fputcsv( $out, array(
                $lesson,
                (string) ( $failure['source_type'] ?? '' ),
                absint( $failure['source_id'] ?? 0 ),
                (string) ( $failure['title'] ?? '' ),
                (string) ( $failure['query'] ?? '' ),
                absint( $failure['rank'] ?? 0 ),
                ! empty( $failure['top8'] ) ? 1 : 0,
                (string) ( $diagnosis['origin'] ?? 'sin_diagnostico' ),
                ! empty( $diagnosis['correctable'] ) ? 1 : 0,
                implode( '|', array_filter( $codes ) ),
                implode( '|', array_filter( $fields ) ),
                implode( ' || ', $values ),
                (string) ( $diagnosis['reason'] ?? '' ),
                (string) ( $diagnosis['action'] ?? '' ),
                implode( '|', array_map( static function ( $item ) { return absint( $item['id'] ?? 0 ); }, $observed ) ),
                implode( ' || ', array_map( static function ( $item ) { return (string) ( $item['title'] ?? '' ); }, $observed ) )
            ), ';', '"', '' );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Authorized php://output CSV streaming requires direct stream writes and closure.
        fclose( $out );
        exit;
    }

    public static function quality_data_csv_url( $lesson ) {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action'=>'tcd_quality_data_csv',
                    'lesson'=>absint( $lesson ),
                ),
                admin_url( 'admin-post.php' )
            ),
            'tcd_quality_data_csv_' . absint( $lesson )
        );
    }

    public static function download_quality_data_csv() {
        global $wpdb;
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso.' );
        $lesson = absint( $_GET['lesson'] ?? 0 );
        check_admin_referer( 'tcd_quality_data_csv_' . $lesson );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="dependiente_L' . $lesson . '_datos_a_revisar_' . gmdate( 'Ymd_His' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Authorized php://output CSV streaming requires direct stream writes and closure.
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, array(
            'lesson','source_type','source_id','title','learning_status','learning_reason',
            'issue_code','field','current_value','reason','recommended_action'
        ), ';', '"', '' );

        $table = TCD_Academy::knowledge_table();
        $last = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT id,source_type,source_id,title,context_json,status,reason
                 FROM %i
                 WHERE lesson=%d AND id>%d
                 ORDER BY id ASC LIMIT 250",
                $table, $lesson,
                $last
            ), ARRAY_A );
            foreach ( $rows as $row ) {
                $last = absint( $row['id'] ?? 0 );
                $issues = self::data_issues_for_row( $lesson, $row );
                if ( 'unlearned' === (string) ( $row['status'] ?? '' ) ) {
                    $issues[] = self::data_issue(
                        'no_aprendido',
                        'learning',
                        (string) ( $row['reason'] ?? '' ),
                        (string) ( $row['reason'] ?? 'Dependiente no pudo aprender esta fuente.' ),
                        'Corregir la causa indicada y volver a revisar esta fuente.'
                    );
                }
                foreach ( $issues as $issue ) {
                    fputcsv( $out, array(
                        $lesson,
                        (string) ( $row['source_type'] ?? '' ),
                        absint( $row['source_id'] ?? 0 ),
                        (string) ( $row['title'] ?? '' ),
                        (string) ( $row['status'] ?? '' ),
                        (string) ( $row['reason'] ?? '' ),
                        (string) ( $issue['code'] ?? '' ),
                        (string) ( $issue['field'] ?? '' ),
                        (string) ( $issue['current'] ?? '' ),
                        (string) ( $issue['reason'] ?? '' ),
                        (string) ( $issue['action'] ?? '' )
                    ), ';', '"', '' );
                }
            }
        } while ( count( $rows ) === 250 );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Authorized php://output CSV streaming requires direct stream writes and closure.
        fclose( $out );
        exit;
    }

    public static function quality_audit_json_url( $lesson ) {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action'=>'tcd_quality_audit_json',
                    'lesson'=>absint( $lesson ),
                ),
                admin_url( 'admin-post.php' )
            ),
            'tcd_quality_audit_json_' . absint( $lesson )
        );
    }

    public static function download_quality_audit_json() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permiso.' );
        }

        $lesson = absint( $_GET['lesson'] ?? 0 );
        if ( $lesson < 1 || $lesson > 10 ) wp_die( 'Lección inválida.' );
        check_admin_referer( 'tcd_quality_audit_json_' . $lesson );

        $payload = self::quality_audit_payload( $lesson );

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="dependiente_L' . $lesson . '_auditoria_correccion_' . gmdate( 'Ymd_His' ) . '.json"' );
        echo wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON admin download.
        exit;
    }

    public static function quality_audit_payload( $lesson ) {
        global $wpdb;

        $lesson = absint( $lesson );
        $quality = self::quality_for_lesson( $lesson );
        $report = json_decode( (string) ( $quality['report_json'] ?? '' ), true );
        $report = is_array( $report ) ? $report : array();

        // Existing runs may have been evaluated before diagnostics were added.
        // Rebuild the report in memory so the current failed L2 can be audited
        // without repeating the full lesson.
        if ( ! isset( $report['failures'], $report['failure_origins'] ) ) {
            $snapshot = TCD_Academy::snapshot_for_lesson( $lesson );
            $snapshot_id = absint( $snapshot['id'] ?? $quality['snapshot_id'] ?? 0 );
            if ( $snapshot_id ) {
                $report = self::evaluate_lesson( $lesson, $snapshot_id );
                $report['threshold'] = self::threshold( $lesson );
                $report['passed'] = (float) ( $report['pass_any_ratio'] ?? 0 ) >= (float) $report['threshold'];
            }
        }

        $failures = array_values( (array) ( $report['failures'] ?? array() ) );
        $correctable = array();
        $internal = array();
        $ambiguous = array();

        foreach ( $failures as $failure ) {
            $diagnosis = (array) ( $failure['diagnosis'] ?? array() );
            $origin = sanitize_key( (string) ( $diagnosis['origin'] ?? 'sin_diagnostico' ) );
            if ( 'dependiente' === $origin ) {
                $internal[] = $failure;
            } elseif ( 'ambiguedad' === $origin ) {
                $ambiguous[] = $failure;
                if ( ! empty( $diagnosis['correctable'] ) ) $correctable[] = $failure;
            } elseif ( 'datos_fuente' === $origin || ! empty( $diagnosis['correctable'] ) ) {
                $correctable[] = $failure;
            }
        }

        $data_audit = array();
        $issue_counts = array();
        $entity_counts = array();
        $table = TCD_Academy::knowledge_table();
        $last = 0;

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT id,source_type,source_id,title,context_json,status,reason
                 FROM %i
                 WHERE lesson=%d AND id>%d
                 ORDER BY id ASC LIMIT 250",
                $table, $lesson,
                $last
            ), ARRAY_A );

            foreach ( $rows as $row ) {
                $last = absint( $row['id'] ?? 0 );
                $issues = self::data_issues_for_row( $lesson, $row );

                if ( 'unlearned' === (string) ( $row['status'] ?? '' ) ) {
                    $issues[] = self::data_issue(
                        'no_aprendido',
                        'learning',
                        (string) ( $row['reason'] ?? '' ),
                        (string) ( $row['reason'] ?? 'Dependiente no pudo aprender esta fuente.' ),
                        'Corregir la causa indicada y volver a revisar esta fuente.'
                    );
                }

                if ( ! $issues ) continue;

                $source_type = sanitize_key( (string) ( $row['source_type'] ?? '' ) );
                $entity_type = self::audit_entity_type( $source_type, $lesson );
                $entity_counts[ $entity_type ] = absint( $entity_counts[ $entity_type ] ?? 0 ) + 1;

                foreach ( $issues as $issue ) {
                    $code = sanitize_key( (string) ( $issue['code'] ?? '' ) );
                    if ( $code ) $issue_counts[ $code ] = absint( $issue_counts[ $code ] ?? 0 ) + 1;
                }

                $data_audit[] = array(
                    'lesson'=>$lesson,
                    'entity_type'=>$entity_type,
                    'source_type'=>$source_type,
                    'source_id'=>absint( $row['source_id'] ?? 0 ),
                    'title'=>(string) ( $row['title'] ?? '' ),
                    'learning_status'=>(string) ( $row['status'] ?? '' ),
                    'learning_reason'=>(string) ( $row['reason'] ?? '' ),
                    'issues'=>array_values( $issues ),
                );
            }
        } while ( count( $rows ) === 250 );

        $syllabus = TCD_Sources::syllabus();
        $lesson_meta = (array) ( $syllabus[ $lesson ] ?? array() );

        return array(
            'schema'=>array(
                'name'=>'taxocommerce_dependiente_quality_audit',
                'version'=>2,
            ),
            'exported_at'=>gmdate( 'c' ),
            'lesson'=>$lesson,
            'lesson_meta'=>array(
                'key'=>(string) ( $lesson_meta['key'] ?? '' ),
                'name'=>(string) ( $lesson_meta['name'] ?? '' ),
                'source'=>(string) ( $lesson_meta['source'] ?? '' ),
                'objective'=>(string) ( $lesson_meta['objective'] ?? '' ),
            ),
            'quality'=>array(
                'status'=>(string) ( $quality['status'] ?? ( ! empty( $report['passed'] ) ? 'passed' : 'failed' ) ),
                'evaluated'=>absint( $quality['evaluated'] ?? $report['evaluated'] ?? 0 ),
                'pass_top1'=>absint( $report['pass_top1'] ?? $quality['pass_top1'] ?? 0 ),
                'pass_top3'=>absint( $report['pass_top3'] ?? $quality['pass_top3'] ?? 0 ),
                'pass_top8'=>absint( $report['pass_top8'] ?? $quality['pass_top8'] ?? 0 ),
                'failed'=>absint( $report['failed'] ?? $quality['failed'] ?? 0 ),
                'ratio'=>(float) ( $report['pass_any_ratio'] ?? $quality['pass_any_ratio'] ?? 0 ),
                'threshold'=>(float) ( $report['threshold'] ?? $quality['threshold'] ?? self::threshold( $lesson ) ),
                'population'=>absint( $report['population'] ?? 0 ),
                'sample_method'=>(string) ( $report['sample_method'] ?? '' ),
                'evaluated_plugin_version'=>(string) ( $report['evaluated_plugin_version'] ?? 'unknown' ),
                'active_plugin_version'=>defined( 'TAXOCOMMERCE_DEPENDIENTE_VERSION' ) ? TAXOCOMMERCE_DEPENDIENTE_VERSION : 'unknown',
                'quality_engine_revision'=>(string) ( $report['quality_engine_revision'] ?? 'legacy_unknown' ),
                'quality_engine_stale'=>self::quality_report_is_stale( $lesson, $report ),
                'query_origins'=>(array) ( $report['query_origins'] ?? array() ),
            ),
            'summary'=>array(
                'failure_origins'=>(array) ( $report['failure_origins'] ?? array() ),
                'failure_data_issue_counts'=>(array) ( $report['data_issue_counts'] ?? array() ),
                'audit_issue_counts'=>$issue_counts,
                'audit_entities_with_issues'=>$entity_counts,
                'correctable_failures'=>count( $correctable ),
                'internal_dependiente_failures'=>count( $internal ),
                'ambiguous_failures'=>count( $ambiguous ),
                'data_audit_items'=>count( $data_audit ),
            ),
            'correction_policy'=>array(
                'datos_fuente'=>'Corregir el contenido o campo indicado en WordPress y después reevaluar la lección.',
                'ambiguedad'=>'Revisar solo si existe un problema real de identidad o duplicado. No eliminar variantes válidas para mejorar el porcentaje.',
                'dependiente'=>'NO modificar el contenido para forzar el examen. Este caso debe corregirse en memoria, señales o ranking de Dependiente.',
            ),
            'recommendations'=>array_values( (array) ( $report['recommendations'] ?? array() ) ),
            'quality_failures'=>$failures,
            'correction_queue'=>$correctable,
            'dependiente_internal_queue'=>$internal,
            'ambiguity_queue'=>$ambiguous,
            'data_audit'=>$data_audit,
        );
    }

    private static function audit_entity_type( $source_type, $lesson ) {
        $source_type = sanitize_key( (string) $source_type );
        if ( 'product' === $source_type || in_array( absint( $lesson ), array( 2, 4, 9 ), true ) ) return 'product';
        if ( 'post' === $source_type ) return 'post';
        if ( 'page' === $source_type ) return 'page';
        if ( in_array( $source_type, array( 'faq','seo_faq' ), true ) || 6 === absint( $lesson ) ) return 'faq';
        if ( 'vocabulary' === $source_type || 3 === absint( $lesson ) ) return 'vocabulary';
        if ( 'cross' === $source_type || 7 === absint( $lesson ) ) return 'cross_relation';
        if ( false !== strpos( $source_type, 'product_cat' ) || 1 === absint( $lesson ) ) return 'category';
        return $source_type ?: 'unknown';
    }

    public static function prepare_quality_retry( $lesson, $snapshot_id ) {
        global $wpdb;

        $lesson = absint( $lesson );
        $snapshot_id = absint( $snapshot_id );
        self::reset_quality_progress( $lesson, $snapshot_id );
        $quality = self::quality_for_lesson( $lesson );
        $report = json_decode( (string) ( $quality['report_json'] ?? '' ), true );
        $report = is_array( $report ) ? $report : array();

        if ( ! isset( $report['failures'] ) ) {
            $report = self::evaluate_lesson( $lesson, $snapshot_id );
        }

        $failures = array_values( (array) ( $report['failures'] ?? array() ) );
        if ( ! $failures ) {
            return array( 'refreshed'=>0, 'relearned'=>0, 'unavailable'=>0, 'failures'=>0 );
        }

        $stage = self::stage_id( $lesson, $snapshot_id );
        $refreshed = 0;
        $relearned = 0;
        $reinforced = 0;
        $unavailable = 0;
        $knowledge = TCD_Academy::knowledge_table();
        $l9 = TCD_Knowledge_Transfer::l9_table();

        foreach ( $failures as $failure ) {
            $source_type = sanitize_key( (string) ( $failure['source_type'] ?? '' ) );
            $source_id = absint( $failure['source_id'] ?? 0 );

            // L8/L10 expose the original object to the auditor, but their own
            // knowledge row is a review item. Refresh that review row so the
            // regression re-reads the current original source.
            if ( in_array( $lesson, array( 8, 10 ), true ) && absint( $failure['review_source_id'] ?? 0 ) ) {
                $source_type = 'review';
                $source_id = absint( $failure['review_source_id'] );
            }

            if ( ! $source_id ) {
                $unavailable++;
                continue;
            }

            $fresh = self::fresh_source_item( $lesson, $source_type, $source_id );
            if ( is_array( $fresh ) ) {
                TCD_Academy::upsert_maintenance_item( $lesson, $fresh );
                $item = $fresh;
                $refreshed++;
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
                $stored = $wpdb->get_row( $wpdb->prepare(
                    "SELECT source_type,source_id,title,context_json
                     FROM %i
                     WHERE lesson=%d AND source_type=%s AND source_id=%d LIMIT 1",
                    $knowledge, $lesson,
                    $source_type,
                    $source_id
                ), ARRAY_A );
                if ( ! $stored ) {
                    $unavailable++;
                    continue;
                }
                $facts = json_decode( (string) ( $stored['context_json'] ?? '' ), true );
                $item = array(
                    'lesson'=>$lesson,
                    'source_type'=>(string) ( $stored['source_type'] ?? $source_type ),
                    'source_id'=>$source_id,
                    'title'=>(string) ( $stored['title'] ?? '' ),
                    'facts'=>is_array( $facts ) ? $facts : array(),
                );
            }

            if ( 2 === $lesson ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
                $wpdb->delete( $l9, array(
                    'lesson_key'=>'v2_l2_identity',
                    'product_id'=>$source_id,
                    'source_brain_id'=>$stage,
                ) );
            } elseif ( 9 === $lesson ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
                $wpdb->delete( $l9, array(
                    'lesson_key'=>'v2_l9_product_language',
                    'product_id'=>$source_id,
                    'source_brain_id'=>$stage,
                ) );
            }

            self::learn_item( $lesson, $item, $snapshot_id );
            $relearned++;

            $diagnosis = (array) ( $failure['diagnosis'] ?? array() );
            if ( 'dependiente' === sanitize_key( (string) ( $diagnosis['origin'] ?? '' ) ) ) {
                $reinforced += self::reinforce_quality_failure( $lesson, $item, $failure, $stage );
            }
        }

        if ( class_exists( 'TCD_Learning_Log', false ) ) {
            TCD_Learning_Log::write( 'INFO', 'quality_retry_prepared', array(
                'lesson'=>$lesson,
                'snapshot_id'=>$snapshot_id,
                'failures'=>count( $failures ),
                'refreshed'=>$refreshed,
                'relearned'=>$relearned,
                'reinforced'=>$reinforced,
                'unavailable'=>$unavailable,
            ) );
        }

        return array(
            'refreshed'=>$refreshed,
            'relearned'=>$relearned,
            'reinforced'=>$reinforced,
            'unavailable'=>$unavailable,
            'failures'=>count( $failures ),
        );
    }

    /**
     * Turn an internal quality failure into supervised learning evidence.
     *
     * Re-reading a failed source is not enough when the source data was already
     * correct: the same memory would simply be written again. This method adds
     * a narrowly scoped reinforcement derived from the failed evaluation while
     * keeping ambiguity and source-data problems out of the self-learning loop.
     */
    private static function reinforce_quality_failure( $lesson, $item, $failure, $stage ) {
        $lesson = absint( $lesson );
        $source_lesson = in_array( $lesson, array( 8, 10 ), true )
            ? absint( $failure['original_lesson'] ?? 0 )
            : $lesson;
        if ( $source_lesson < 1 || $source_lesson > 9 || 8 === $source_lesson ) return 0;

        $source_type = sanitize_key( (string) ( $failure['source_type'] ?? $item['source_type'] ?? '' ) );
        $source_id = absint( $failure['source_id'] ?? $item['source_id'] ?? 0 );
        $source_item = $item;

        if ( in_array( $lesson, array( 8, 10 ), true ) && $source_id ) {
            $fresh = self::fresh_source_item( $source_lesson, $source_type, $source_id );
            if ( is_array( $fresh ) ) $source_item = $fresh;
        }

        if ( in_array( $source_lesson, array( 2, 4, 9 ), true ) || 'product' === $source_type ) {
            return self::stage_quality_product_repair( $source_lesson, $source_item, $failure, $stage );
        }

        if ( in_array( $source_lesson, array( 3, 7 ), true ) ) {
            return self::stage_quality_semantic_repair( $source_lesson, $source_item, $failure, $stage );
        }

        if ( in_array( $source_lesson, array( 1, 5, 6 ), true ) ) {
            return self::stage_quality_target_repair( $source_lesson, $source_item, $failure, $stage );
        }

        return 0;
    }

    private static function stage_quality_product_repair( $source_lesson, $item, $failure, $stage ) {
        $product_id = absint( $failure['source_id'] ?? $item['source_id'] ?? 0 );
        if ( ! $product_id ) return 0;

        $query = trim( (string) ( $failure['query'] ?? '' ) );
        $normalized_query = self::normalize( $query );
        if ( ! $normalized_query ) return 0;

        $source_lesson = absint( $source_lesson );
        $written = 0;

        // L2/L4 are evaluated by product identity. An exact title association is
        // valid identity knowledge, not an exam-only shortcut.
        if ( in_array( $source_lesson, array( 2, 4 ), true ) ) {
            $title = trim( (string) ( $item['title'] ?? '' ) );
            if ( $title && $normalized_query === self::normalize( $title ) ) {
                $written += self::write_quality_product_signal(
                    $source_lesson,
                    $product_id,
                    'quality_repair_identity',
                    $title,
                    $normalized_query,
                    'identity',
                    500,
                    $stage
                );
            }
            return $written;
        }

        // L9 deliberately evaluates an independent customer need. Never memorise
        // that sentence verbatim. Reinforce only genuine source signals that
        // overlap with the failed query.
        if ( 9 === $source_lesson ) {
            $terms = self::ngrams( $normalized_query, 5 );
            $compact = self::without_stopwords( $normalized_query );
            if ( $compact ) $terms = array_merge( $terms, self::ngrams( $compact, 5 ) );
            foreach ( explode( ' ', $compact ?: $normalized_query ) as $token ) {
                if ( strlen( $token ) >= 3 ) $terms = array_merge( $terms, self::token_variants( $token ) );
            }
            $terms = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize' ), $terms ) ) ) );

            foreach ( self::signals_from_item( $item ) as $signal ) {
                if ( $written >= 12 ) break;
                $normalized = self::normalize( (string) ( $signal['normalized_signal'] ?? '' ) );
                if ( ! $normalized ) continue;
                $overlap = in_array( $normalized, $terms, true )
                    || ( strlen( $normalized ) >= 4 && false !== strpos( ' ' . $normalized_query . ' ', ' ' . $normalized . ' ' ) );
                if ( ! $overlap ) continue;

                $written += self::write_quality_product_signal(
                    9,
                    $product_id,
                    'quality_repair_' . sanitize_key( (string) ( $signal['signal_type'] ?? 'term' ) ),
                    (string) ( $signal['signal_text'] ?? $normalized ),
                    $normalized,
                    sanitize_key( (string) ( $signal['semantic_group'] ?? $signal['signal_type'] ?? 'term' ) ),
                    min( 500, max( 320, absint( $signal['weight'] ?? 100 ) + 160 ) ),
                    $stage
                );
            }
        }

        return $written;
    }

    private static function write_quality_product_signal( $source_lesson, $product_id, $signal_type, $signal_text, $normalized, $group, $weight, $stage ) {
        global $wpdb;
        $table = TCD_Knowledge_Transfer::l9_table();
        $normalized = self::normalize( $normalized );
        if ( ! $normalized ) return 0;

        $lesson_key = 'quality_repair_l' . absint( $source_lesson );
        $signal_type = sanitize_key( $signal_type );
        $hash = hash( 'sha256', implode( '|', array(
            $lesson_key, absint( $product_id ), $signal_type, $normalized, (string) $stage,
        ) ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $ok = $wpdb->replace( $table, array(
            'lesson_key'=>$lesson_key,
            'product_id'=>absint( $product_id ),
            'signal_type'=>$signal_type,
            'signal_text'=>self::shorten( (string) $signal_text, 250 ),
            'normalized_signal'=>self::shorten( $normalized, 250 ),
            'semantic_group'=>sanitize_key( (string) $group ) ?: 'quality_repair',
            'vocabulary_id'=>null,
            'weight'=>min( 500, max( 1, absint( $weight ) ) ),
            'source_hash'=>$hash,
            'metadata'=>wp_json_encode( array(
                'origin'=>'quality_retry',
                'source_lesson'=>absint( $source_lesson ),
                'stage'=>$stage,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'active'=>0,
            'source_brain_id'=>$stage,
            'updated_at'=>current_time( 'mysql' ),
        ) );

        return false === $ok ? 0 : 1;
    }

    private static function stage_quality_semantic_repair( $source_lesson, $item, $failure, $stage ) {
        global $wpdb;
        $facts = (array) ( $item['facts'] ?? array() );
        $group = sanitize_key( (string) ( $facts['group'] ?? '' ) );
        $slug = sanitize_title( (string) ( $facts['slug'] ?? '' ) );
        $query = trim( (string) ( $failure['query'] ?? $item['title'] ?? '' ) );
        $normalized = self::normalize( $query );
        if ( ! $group || ! $slug || ! $normalized ) return 0;

        $hash = hash( 'sha256', implode( '|', array(
            'quality_semantic', absint( $source_lesson ), $normalized, $group, $slug, (string) $stage,
        ) ) );
        $now = current_time( 'mysql' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $ok = $wpdb->replace( TCD_Knowledge_Transfer::rules_table(), array(
            'rule_key'=>sanitize_key( 'quality_repair_' . substr( $hash, 0, 40 ) ),
            'rule_type'=>'alias',
            'expression'=>self::shorten( $query, 255 ),
            'normalized_expression'=>self::shorten( $normalized, 255 ),
            'canonical_expression'=>self::shorten( (string) ( $item['title'] ?? $query ), 255 ),
            'match_type'=>false !== strpos( $normalized, ' ' ) ? 'phrase' : 'token',
            'semantic_role'=>$group,
            'source_group'=>$group,
            'source_slug'=>$slug,
            'context_group'=>null,
            'context_slug'=>null,
            'target_group'=>$group,
            'target_slug'=>$slug,
            'relation_type'=>'quality_repair',
            'result_role'=>$group,
            'weight'=>500,
            'priority'=>1,
            'confidence'=>1,
            'language'=>'es',
            'source'=>'modern_quality_repair',
            'metadata'=>wp_json_encode( array(
                'origin'=>'quality_retry',
                'source_lesson'=>absint( $source_lesson ),
                'source_type'=>sanitize_key( (string) ( $item['source_type'] ?? '' ) ),
                'source_id'=>absint( $item['source_id'] ?? 0 ),
                'stage'=>$stage,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'active'=>0,
            'rule_hash'=>$hash,
            'source_brain_id'=>$stage,
            'created_at'=>$now,
            'updated_at'=>$now,
        ) );

        return false === $ok ? 0 : 1;
    }

    private static function stage_quality_target_repair( $source_lesson, $item, $failure, $stage ) {
        global $wpdb;
        $query = trim( (string) ( $failure['query'] ?? $item['title'] ?? '' ) );
        $normalized = self::normalize( $query );
        $target_id = absint( $failure['source_id'] ?? $item['source_id'] ?? 0 );
        if ( ! $normalized || ! $target_id ) return 0;

        $source_lesson = absint( $source_lesson );
        $hash = hash( 'sha256', implode( '|', array(
            'quality_target', $source_lesson, $normalized, $target_id, (string) $stage,
        ) ) );
        $now = current_time( 'mysql' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $ok = $wpdb->replace( TCD_Knowledge_Transfer::rules_table(), array(
            'rule_key'=>sanitize_key( 'quality_target_' . substr( $hash, 0, 40 ) ),
            'rule_type'=>'quality_target',
            'expression'=>self::shorten( $query, 255 ),
            'normalized_expression'=>self::shorten( $normalized, 255 ),
            'canonical_expression'=>self::shorten( (string) ( $item['title'] ?? $query ), 255 ),
            'match_type'=>'phrase',
            'semantic_role'=>'quality_target',
            'source_group'=>null,
            'source_slug'=>null,
            'context_group'=>null,
            'context_slug'=>null,
            'target_group'=>null,
            'target_slug'=>null,
            'relation_type'=>'quality_repair',
            'result_role'=>'quality_target',
            'weight'=>500,
            'priority'=>1,
            'confidence'=>1,
            'language'=>'es',
            'source'=>'modern_quality_repair',
            'metadata'=>wp_json_encode( array(
                'origin'=>'quality_retry',
                'source_lesson'=>$source_lesson,
                'target_source_type'=>sanitize_key( (string) ( $failure['source_type'] ?? $item['source_type'] ?? '' ) ),
                'target_source_id'=>$target_id,
                'stage'=>$stage,
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'active'=>0,
            'rule_hash'=>$hash,
            'source_brain_id'=>$stage,
            'created_at'=>$now,
            'updated_at'=>$now,
        ) );

        return false === $ok ? 0 : 1;
    }

    private static function quality_target_hints( $query, $source_lesson, $stage = '' ) {
        global $wpdb;
        $normalized = self::normalize( $query );
        if ( ! $normalized ) return array();

        $table = TCD_Knowledge_Transfer::rules_table();
        if ( $stage ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT metadata FROM %i
                    WHERE rule_type='quality_target'
                      AND normalized_expression=%s
                      AND (active=1 OR source_brain_id=%s)
                    ORDER BY weight DESC,id DESC LIMIT 20",
                    $table,
                    $normalized,
                    $stage
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT metadata FROM %i
                    WHERE rule_type='quality_target'
                      AND normalized_expression=%s
                      AND active=1
                    ORDER BY weight DESC,id DESC LIMIT 20",
                    $table,
                    $normalized
                )
            );
        }
        $out = array();
        foreach ( $rows as $metadata_json ) {
            $metadata = json_decode( (string) $metadata_json, true );
            if ( ! is_array( $metadata ) || absint( $metadata['source_lesson'] ?? 0 ) !== absint( $source_lesson ) ) continue;
            $id = absint( $metadata['target_source_id'] ?? 0 );
            if ( $id ) $out[] = $id;
        }
        return array_values( array_unique( $out ) );
    }

    private static function fresh_source_item( $lesson, $source_type, $source_id ) {
        $lesson = absint( $lesson );
        $source_type = sanitize_key( (string) $source_type );
        $source_id = absint( $source_id );

        if ( in_array( $lesson, array( 2, 4, 9 ), true ) || 'product' === $source_type ) {
            return TCD_Sources::product_item( $lesson, $source_id );
        }
        if ( 1 === $lesson || false !== strpos( $source_type, 'product_cat' ) ) {
            return TCD_Sources::category_item( $source_id );
        }
        if ( 3 === $lesson || 'vocabulary' === $source_type ) {
            return TCD_Sources::vocabulary_item( $source_id );
        }
        if ( 5 === $lesson && in_array( $source_type, array( 'post','page' ), true ) ) {
            return TCD_Sources::content_item( $source_id );
        }
        if ( 6 === $lesson || in_array( $source_type, array( 'faq','seo_faq' ), true ) ) {
            return TCD_Sources::faq_item( $source_id );
        }
        if ( 7 === $lesson || 'cross' === $source_type ) {
            return TCD_Sources::cross_item( $source_id );
        }
        if ( in_array( $lesson, array( 8, 10 ), true ) || 'review' === $source_type ) {
            return TCD_Sources::review_item( $lesson, $source_id );
        }
        return null;
    }

    private static function activate_stage( $stage ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $wpdb->query( $wpdb->prepare( 'UPDATE %i SET active=1,updated_at=%s WHERE source_brain_id=%s', TCD_Knowledge_Transfer::rules_table(), current_time( 'mysql' ), $stage ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $wpdb->query( $wpdb->prepare( 'UPDATE %i SET active=1,updated_at=%s WHERE source_brain_id=%s', TCD_Knowledge_Transfer::l9_table(), current_time( 'mysql' ), $stage ) );
    }

    /**
     * Old persisted reports must not be presented as if recalculated by the
     * current L9/L10 evaluator. This check is read-only: no automatic
     * 180-case scan from the admin page or JSON download.
     */
    public static function quality_report_is_stale( $lesson, $report ) {
        $lesson = absint( $lesson );
        if ( ! in_array( $lesson, array( 9, 10 ), true ) ) return false;
        $report = is_array( $report ) ? $report : array();
        return (string) ( $report['quality_engine_revision'] ?? '' ) !== self::QUALITY_EVALUATOR_REVISION;
    }

    public static function quality_for_lesson( $lesson ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM %i WHERE lesson=%d ORDER BY id DESC LIMIT 1',
            self::quality_table(), absint( $lesson )
        ), ARRAY_A );
        return is_array( $row ) ? $row : array();
    }

    public static function interpret( $query, $include_stage = '', $runtime_hints = array() ) {
        global $wpdb;
        $normalized = self::normalize( $query );
        $result = array(
            'raw'=>(string) $query,
            'normalized'=>$normalized,
            'groups'=>array(),
            'semantic_hits'=>array(),
            'actions'=>self::detect_actions( $normalized ),
            'ignored'=>array(),
        );
        if ( ! $normalized ) return $result;
        $terms = array( $normalized );
        $compact = self::without_stopwords( $normalized );
        if ( $compact && $compact !== $normalized ) $terms[] = $compact;
        foreach ( self::ngrams( $normalized, 5 ) as $phrase ) $terms[] = $phrase;
        if ( $compact && $compact !== $normalized ) {
            foreach ( self::ngrams( $compact, 5 ) as $phrase ) $terms[] = $phrase;
        }
        $ngrams = array_slice( array_values( array_unique( array_filter( $terms ) ) ), 0, 140 );
        if ( ! $ngrams ) return $result;
        $table = TCD_Knowledge_Transfer::rules_table();
        $placeholders = implode( ',', array_fill( 0, count( $ngrams ), '%s' ) );
        if ( $include_stage ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list contains only generated %d/%s placeholders and all identifiers/values are bound through $wpdb->prepare().
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,rule_type,expression,normalized_expression,canonical_expression,semantic_role,source_group,source_slug,target_group,target_slug,relation_type,result_role,weight,priority,confidence,source FROM %i WHERE (active=1 OR source_brain_id=%s) AND language='es' AND normalized_expression IN ({$placeholders}) ORDER BY LENGTH(normalized_expression) DESC,priority ASC,weight DESC,id ASC LIMIT 300",
                    array_merge( array( $table, $include_stage ), $ngrams )
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        } else {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list contains only generated %d/%s placeholders and all identifiers/values are bound through $wpdb->prepare().
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,rule_type,expression,normalized_expression,canonical_expression,semantic_role,source_group,source_slug,target_group,target_slug,relation_type,result_role,weight,priority,confidence,source FROM %i WHERE active=1 AND language='es' AND normalized_expression IN ({$placeholders}) ORDER BY LENGTH(normalized_expression) DESC,priority ASC,weight DESC,id ASC LIMIT 300",
                    array_merge( array( $table ), $ngrams )
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        }
        foreach ( $rows as $row ) {
            $hit = array(
                'id'=>absint( $row['id'] ?? 0 ),
                'expression'=>(string) ( $row['expression'] ?? '' ),
                'canonical'=>(string) ( $row['canonical_expression'] ?? '' ),
                'role'=>sanitize_key( (string) ( $row['semantic_role'] ?? '' ) ),
                'source_group'=>sanitize_key( (string) ( $row['source_group'] ?? '' ) ),
                'source_slug'=>sanitize_title( (string) ( $row['source_slug'] ?? '' ) ),
                'target_group'=>sanitize_key( (string) ( $row['target_group'] ?? '' ) ),
                'target_slug'=>sanitize_title( (string) ( $row['target_slug'] ?? '' ) ),
                'weight'=>absint( $row['weight'] ?? 0 ),
                'source'=>(string) ( $row['source'] ?? '' ),
            );
            $result['semantic_hits'][] = $hit;
            $group = $hit['target_group'] ?: $hit['source_group'] ?: $hit['role'];
            $slug = $hit['target_slug'] ?: $hit['source_slug'];
            if ( ! $slug && $group ) {
                $slug = sanitize_title( (string) ( $hit['canonical'] ?: $hit['expression'] ) );
            }
            if ( $group && $slug ) {
                $key = $group . ':' . $slug;
                $result['groups'][ $key ] = array(
                    'group'=>$group,
                    'slug'=>$slug,
                    'label'=>$hit['canonical'] ?: $hit['expression'],
                    'weight'=>$hit['weight'],
                    'via'=>(string) ( $hit['source'] ?? '' ),
                );
            }
        }
        self::apply_vocabulary_direct( $result, $ngrams );
        self::apply_runtime_hints( $result, (array) $runtime_hints );
        self::apply_routes( $result, $include_stage );
        $result['groups'] = array_values( $result['groups'] );
        return $result;
    }

    private static function apply_vocabulary_direct( &$result, $ngrams ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_vocabulary';
        if ( ! self::table_exists( $table ) ) return;
        $slugs = array();
        foreach ( (array) $ngrams as $phrase ) {
            $slug = sanitize_title( $phrase );
            if ( $slug ) {
                $slugs[] = $slug;
                $slugs[] = str_replace( '-', '_', $slug );
            }
        }
        $slugs = array_slice( array_values( array_unique( array_filter( $slugs ) ) ), 0, 120 );
        if ( ! $slugs ) return;
        $placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
        
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list contains only generated %d/%s placeholders and all identifiers/values are bound through $wpdb->prepare().
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,semantic_group,slug,label FROM %i WHERE active=1 AND slug IN ({$placeholders}) ORDER BY id ASC LIMIT 150",
                array_merge( array( $table ), $slugs )
            ), ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        foreach ( $rows as $row ) {
            $group = sanitize_key( (string) ( $row['semantic_group'] ?? '' ) );
            $slug = sanitize_title( (string) ( $row['slug'] ?? '' ) );
            if ( ! $group || ! $slug ) continue;
            $key = $group . ':' . $slug;
            if ( ! isset( $result['groups'][ $key ] ) ) {
                $result['groups'][ $key ] = array(
                    'group'=>$group,
                    'slug'=>$slug,
                    'label'=>(string) ( $row['label'] ?? '' ),
                    'weight'=>130,
                );
            }
            $result['semantic_hits'][] = array(
                'id'=>absint( $row['id'] ?? 0 ),
                'expression'=>(string) ( $row['label'] ?? '' ),
                'canonical'=>(string) ( $row['label'] ?? '' ),
                'role'=>$group,
                'source_group'=>$group,
                'source_slug'=>$slug,
                'target_group'=>$group,
                'target_slug'=>$slug,
                'weight'=>130,
                'source'=>'vocabulary',
            );
        }
    }

    private static function apply_runtime_hints( &$result, $hints ) {
        foreach ( (array) $hints as $hint ) {
            if ( ! is_array( $hint ) ) continue;
            $group = sanitize_key( (string) ( $hint['group'] ?? '' ) );
            $slug = sanitize_title( (string) ( $hint['slug'] ?? '' ) );
            if ( ! $group || ! $slug ) continue;
            $label = trim( (string) ( $hint['label'] ?? str_replace( '-', ' ', $slug ) ) );
            $weight = absint( $hint['weight'] ?? 220 );
            $key = $group . ':' . $slug;
            $result['groups'][ $key ] = array(
                'group'=>$group,
                'slug'=>$slug,
                'label'=>$label,
                'weight'=>$weight,
                'via'=>(string) ( $hint['source'] ?? 'runtime_hint' ),
            );
            $result['semantic_hits'][] = array(
                'id'=>0,
                'expression'=>$label,
                'canonical'=>$label,
                'role'=>$group,
                'source_group'=>$group,
                'source_slug'=>$slug,
                'target_group'=>$group,
                'target_slug'=>$slug,
                'weight'=>$weight,
                'source'=>(string) ( $hint['source'] ?? 'runtime_hint' ),
            );
        }
    }

    private static function apply_routes( &$result, $include_stage = '' ) {
        global $wpdb;
        if ( empty( $result['groups'] ) ) return;
        $table = TCD_Knowledge_Transfer::rules_table();
        if ( $include_stage ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,source_group,source_slug,context_group,context_slug,target_group,target_slug,relation_type,result_role,weight,priority,confidence,source
                    FROM %i
                    WHERE (active=1 OR source_brain_id=%s) AND rule_type='route' AND language='es'
                    ORDER BY priority ASC,weight DESC,id ASC LIMIT 500",
                    $table,
                    $include_stage
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,source_group,source_slug,context_group,context_slug,target_group,target_slug,relation_type,result_role,weight,priority,confidence,source
                    FROM %i
                    WHERE active=1 AND rule_type='route' AND language='es'
                    ORDER BY priority ASC,weight DESC,id ASC LIMIT 500",
                    $table
                ),
                ARRAY_A
            );
        }
        if ( ! $rows ) return;

        $known = array();
        foreach ( (array) $result['groups'] as $group ) {
            $known[ sanitize_key( (string) ( $group['group'] ?? '' ) ) . ':' . sanitize_title( (string) ( $group['slug'] ?? '' ) ) ] = true;
        }
        foreach ( $rows as $row ) {
            $source_key = sanitize_key( (string) ( $row['source_group'] ?? '' ) ) . ':' . sanitize_title( (string) ( $row['source_slug'] ?? '' ) );
            if ( empty( $known[ $source_key ] ) ) continue;
            $context_group = sanitize_key( (string) ( $row['context_group'] ?? '' ) );
            $context_slug = sanitize_title( (string) ( $row['context_slug'] ?? '' ) );
            if ( $context_group && $context_slug && empty( $known[ $context_group . ':' . $context_slug ] ) ) continue;
            $target_group = sanitize_key( (string) ( $row['target_group'] ?? '' ) );
            $target_slug = sanitize_title( (string) ( $row['target_slug'] ?? '' ) );
            if ( ! $target_group || ! $target_slug ) continue;
            $target_key = $target_group . ':' . $target_slug;
            if ( ! isset( $result['groups'][ $target_key ] ) ) {
                $result['groups'][ $target_key ] = array(
                    'group'=>$target_group,
                    'slug'=>$target_slug,
                    'label'=>str_replace( '-', ' ', $target_slug ),
                    'weight'=>absint( $row['weight'] ?? 100 ),
                    'via'=>'route',
                );
                $known[ $target_key ] = true;
            }
            $result['semantic_hits'][] = array(
                'id'=>absint( $row['id'] ?? 0 ),
                'expression'=>$source_key,
                'canonical'=>$target_key,
                'role'=>sanitize_key( (string) ( $row['result_role'] ?? $target_group ) ),
                'source_group'=>sanitize_key( (string) ( $row['source_group'] ?? '' ) ),
                'source_slug'=>sanitize_title( (string) ( $row['source_slug'] ?? '' ) ),
                'target_group'=>$target_group,
                'target_slug'=>$target_slug,
                'weight'=>absint( $row['weight'] ?? 0 ),
                'source'=>(string) ( $row['source'] ?? '' ),
            );
        }
    }

    public static function backfill_next_missing( $current_lesson ) {
        global $wpdb;
        $current_lesson = min( 10, max( 1, absint( $current_lesson ) ) );
        for ( $lesson = 1; $lesson < $current_lesson; $lesson++ ) {
            if ( self::quality_for_lesson( $lesson ) ) continue;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $count = absint( $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE lesson=%d AND status=%s',
                TCD_Academy::knowledge_table(), $lesson, 'learned'
            ) ) );
            if ( ! $count ) continue;
            $snapshot = TCD_Academy::snapshot_for_lesson( $lesson );
            $snapshot_id = absint( $snapshot['id'] ?? 0 );
            $last = 0;
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
                $rows = (array) $wpdb->get_results( $wpdb->prepare(
                    'SELECT id,source_type,source_id,title,context_json FROM %i WHERE lesson=%d AND status=%s AND id>%d ORDER BY id ASC LIMIT 200',
                    TCD_Academy::knowledge_table(), $lesson, 'learned', $last
                ), ARRAY_A );
                foreach ( $rows as $row ) {
                    $last = absint( $row['id'] ?? 0 );
                    $facts = json_decode( (string) ( $row['context_json'] ?? '' ), true );
                    self::learn_item( $lesson, array(
                        'lesson'=>$lesson,
                        'source_type'=>(string) ( $row['source_type'] ?? '' ),
                        'source_id'=>absint( $row['source_id'] ?? 0 ),
                        'title'=>(string) ( $row['title'] ?? '' ),
                        'facts'=>is_array( $facts ) ? $facts : array(),
                    ), $snapshot_id );
                }
            } while ( count( $rows ) === 200 );
            $quality = self::finalize_lesson( $lesson, $snapshot_id );
            if ( is_wp_error( $quality ) ) return $quality;
            return array( 'lesson'=>$lesson, 'count'=>$count, 'quality'=>$quality );
        }
        return false;
    }

    public static function search_products( $query, $limit = 8, $include_stage = '', $runtime_hints = array() ) {
        $limit = min( 36, max( 1, absint( $limit ) ) );
        $interpretation = self::interpret( $query, $include_stage, $runtime_hints );
        $candidates = array();

        // Product identity is a separate retrieval channel from semantic/L9
        // language matching. Exact title/SKU associations learned by L2 must
        // never be discarded by fuzzy-language thresholds.
        foreach ( self::identity_matches( $query, $include_stage ) as $product_id => $match ) {
            self::candidate_add(
                $candidates,
                $product_id,
                2200 + absint( $match['weight'] ?? 0 ),
                'identity_exact',
                $match
            );
        }

        foreach ( self::l9_matches( $query, $include_stage ) as $product_id => $match ) {
            if ( ! empty( $match['identity_exact'] ) ) {
                $source = 'identity_exact';
                $base_score = 1200;
            } elseif ( ! empty( $match['quality_repair'] ) ) {
                // Repairs are diagnostic evidence, not a blanket ranking
                // priority. Single generic tokens previously overrode
                // relevant product subtype, capacity and compatibility.
                $source = 'quality_repair';
                $base_score = 300;
            } else {
                $source = ! empty( $match['customer_feedback'] ) ? 'feedback_click' : 'lesson9';
                $base_score = 300;
            }
            self::candidate_add( $candidates, $product_id, $base_score + absint( $match['score'] ?? 0 ), $source, $match );
        }

        foreach ( (array) ( $interpretation['groups'] ?? array() ) as $group ) {
            $group_name = sanitize_key( (string) ( $group['group'] ?? '' ) );
            $slug = sanitize_title( (string) ( $group['slug'] ?? '' ) );
            $ids = 'product_cat' === $group_name
                ? self::products_for_category( $slug, 160 )
                : self::products_for_vocabulary( $group_name, $slug, 160 );
            $source = 'customer_feedback' === (string) ( $group['via'] ?? '' ) ? 'feedback_semantic' : 'semantic';
            foreach ( $ids as $product_id ) {
                self::candidate_add( $candidates, $product_id, 190 + absint( $group['weight'] ?? 0 ), $source, $group );
            }
        }

        $query_posts = get_posts( array(
            'post_type'=>'product', 'post_status'=>'publish', 's'=>sanitize_text_field( (string) $query ),
            'posts_per_page'=>40, 'no_found_rows'=>true, 'suppress_filters'=>false,
        ) );
        $normalized = self::normalize( $query );
        $words = array_values( array_filter( explode( ' ', self::without_stopwords( $normalized ) ) ) );
        foreach ( $query_posts as $post ) {
            if ( ! $post instanceof WP_Post ) continue;
            $title = self::normalize( $post->post_title );
            $score = 110;
            if ( $normalized && false !== strpos( $title, $normalized ) ) $score += 180;
            foreach ( $words as $word ) if ( strlen( $word ) >= 3 && false !== strpos( $title, $word ) ) $score += 25;
            self::candidate_add( $candidates, $post->ID, $score, 'catalog_text', array() );
        }

        uasort( $candidates, static function ( $a, $b ) {
            $cmp = absint( $b['identity_exact'] ?? 0 ) <=> absint( $a['identity_exact'] ?? 0 );
            if ( 0 !== $cmp ) return $cmp;
            $feedback_a = (float) ( $a['feedback_confidence'] ?? 0 );
            $feedback_b = (float) ( $b['feedback_confidence'] ?? 0 );
            $cmp = $feedback_b <=> $feedback_a;
            if ( 0 !== $cmp ) return $cmp;
            // A prior failed exam is not proof of greater relevance.
            // Keep quality_repair in returned evidence, not a sort override.
            $coverage_a = count( (array) ( $a['semantic_groups'] ?? array() ) );
            $coverage_b = count( (array) ( $b['semantic_groups'] ?? array() ) );
            $cmp = absint( $b['l9_strength'] ?? 0 ) <=> absint( $a['l9_strength'] ?? 0 );
            if ( 0 !== $cmp ) return $cmp;
            $cmp = (float) ( $b['score'] ?? 0 ) <=> (float) ( $a['score'] ?? 0 );
            if ( 0 !== $cmp ) return $cmp;
            $cmp = $coverage_b <=> $coverage_a;
            if ( 0 !== $cmp ) return $cmp;
            $cmp = absint( $b['l9_matched'] ?? 0 ) <=> absint( $a['l9_matched'] ?? 0 );
            if ( 0 !== $cmp ) return $cmp;
            $cmp = (float) ( $b['score'] ?? 0 ) <=> (float) ( $a['score'] ?? 0 );
            if ( 0 !== $cmp ) return $cmp;
            return absint( $a['id'] ?? 0 ) <=> absint( $b['id'] ?? 0 );
        } );

        $out = array();
        foreach ( $candidates as $candidate ) {
            if ( count( $out ) >= $limit ) break;
            $product = function_exists( 'wc_get_product' ) ? wc_get_product( absint( $candidate['id'] ?? 0 ) ) : false;
            if ( ! $product || 'publish' !== get_post_status( $product->get_id() ) ) continue;
            $out[] = array(
                'kind'=>'product',
                'id'=>$product->get_id(),
                'title'=>$product->get_name(),
                'url'=>get_permalink( $product->get_id() ),
                'score'=>round( (float) ( $candidate['score'] ?? 0 ), 2 ),
                'coverage'=>count( (array) ( $candidate['semantic_groups'] ?? array() ) ),
                'semantic_groups'=>array_keys( (array) ( $candidate['semantic_groups'] ?? array() ) ),
                'l9_strength'=>absint( $candidate['l9_strength'] ?? 0 ),
                'l9_matched'=>absint( $candidate['l9_matched'] ?? 0 ),
                'feedback_confidence'=>round( (float) ( $candidate['feedback_confidence'] ?? 0 ), 4 ),
                'identity_exact'=>! empty( $candidate['identity_exact'] ) ? 1 : 0,
                'quality_repair'=>! empty( $candidate['quality_repair'] ) ? 1 : 0,
                'sources'=>array_values( array_unique( (array) ( $candidate['sources'] ?? array() ) ) ),
                'evidence'=>(array) ( $candidate['evidence'] ?? array() ),
            );
        }
        return $out;
    }

    private static function candidate_add( &$candidates, $id, $score, $source, $evidence ) {
        $id = absint( $id );
        if ( ! $id ) return;
        if ( ! isset( $candidates[ $id ] ) ) {
            $candidates[ $id ] = array(
                'id'=>$id,
                'score'=>0,
                'sources'=>array(),
                'evidence'=>array(),
                'semantic_groups'=>array(),
                'l9_strength'=>0,
                'l9_matched'=>0,
                'feedback_confidence'=>0,
                'identity_exact'=>0,
                'quality_repair'=>0,
            );
        }
        $candidates[ $id ]['score'] += (float) $score;
        $source = sanitize_key( $source );
        $candidates[ $id ]['sources'][] = $source;
        if ( 'semantic' === $source && is_array( $evidence ) ) {
            $group = sanitize_key( (string) ( $evidence['group'] ?? '' ) );
            $slug = sanitize_title( (string) ( $evidence['slug'] ?? '' ) );
            if ( $group && $slug ) $candidates[ $id ]['semantic_groups'][ $group . ':' . $slug ] = true;
        }
        if ( 'lesson9' === $source && is_array( $evidence ) ) {
            $candidates[ $id ]['l9_strength'] = max( absint( $candidates[ $id ]['l9_strength'] ), absint( $evidence['score'] ?? 0 ) );
            $candidates[ $id ]['l9_matched'] = max( absint( $candidates[ $id ]['l9_matched'] ), absint( $evidence['matched_count'] ?? 0 ) );
        }
        if ( 'feedback_click' === $source && is_array( $evidence ) ) {
            $candidates[ $id ]['feedback_confidence'] = max(
                (float) ( $candidates[ $id ]['feedback_confidence'] ?? 0 ),
                (float) ( $evidence['confidence'] ?? 0 )
            );
        }
        if ( 'identity_exact' === $source ) $candidates[ $id ]['identity_exact'] = 1;
        if ( 'quality_repair' === $source ) $candidates[ $id ]['quality_repair'] = 1;
        if ( $evidence ) $candidates[ $id ]['evidence'][] = $evidence;
    }

    public static function decision( $query, $interpretation, $results ) {
        $results = array_values( (array) $results );
        if ( ! $results ) {
            return array(
                'confidence'=>0,
                'level'=>'low',
                'show_products'=>false,
                'needs_choice'=>true,
                'reason'=>'no_candidates',
                'message'=>'No tengo evidencia suficiente para elegir productos. Describe con algo más de detalle qué necesitas hacer o qué equipo tienes.',
            );
        }

        $top = (array) $results[0];
        $second = isset( $results[1] ) ? (array) $results[1] : array();
        $sources = array_values( array_unique( (array) ( $top['sources'] ?? array() ) ) );
        $required_groups = array( 'tipo','rol','aplicacion','plataforma','subtipo' );
        $group_total = 0;
        foreach ( (array) ( $interpretation['groups'] ?? array() ) as $group ) {
            if ( in_array( sanitize_key( (string) ( $group['group'] ?? '' ) ), $required_groups, true ) ) {
                $group_total++;
            }
        }
        $coverage = absint( $top['coverage'] ?? 0 );
        $l9_strength = absint( $top['l9_strength'] ?? 0 );
        $l9_matched = absint( $top['l9_matched'] ?? 0 );
        $top_score = (float) ( $top['score'] ?? 0 );
        $second_score = (float) ( $second['score'] ?? 0 );
        $margin = $top_score > 0 ? max( 0, ( $top_score - $second_score ) / $top_score ) : 0;
        $has_semantic = in_array( 'semantic', $sources, true );
        $has_l9 = in_array( 'lesson9', $sources, true )
            || in_array( 'quality_repair', $sources, true )
            || in_array( 'identity_exact', $sources, true );
        $has_feedback = in_array( 'feedback_click', $sources, true ) || in_array( 'feedback_semantic', $sources, true );
        $text_only = ! $has_semantic && ! $has_l9 && ! $has_feedback;
        $has_action = ! empty( $interpretation['actions'] );

        $confidence = 15;
        if ( $has_semantic ) $confidence += 28;
        if ( $has_l9 ) $confidence += 32;
        if ( $has_feedback ) $confidence += 22;
        if ( $group_total > 0 && $coverage >= $group_total ) $confidence += 18;
        elseif ( $coverage >= 2 ) $confidence += 12;
        elseif ( $coverage >= 1 ) $confidence += 7;
        if ( $l9_matched >= 3 ) $confidence += 12;
        elseif ( $l9_matched >= 2 ) $confidence += 8;
        if ( $l9_strength >= 300 ) $confidence += 8;
        if ( $margin >= 0.20 ) $confidence += 10;
        elseif ( $margin >= 0.08 ) $confidence += 5;
        if ( $top_score >= 700 ) $confidence += 8;
        elseif ( $top_score >= 450 ) $confidence += 4;

        if ( $text_only ) $confidence = min( $confidence, 52 );
        if ( $has_action && ! $has_l9 && $coverage < 2 ) $confidence = min( $confidence, 58 );
        if ( $group_total >= 2 && $coverage > 0 && $coverage < $group_total && ! $has_l9 ) $confidence = min( $confidence, 62 );

        $confidence = max( 0, min( 100, (int) round( $confidence ) ) );
        $show = $confidence >= 68;
        $reason = $show ? 'strong_evidence' : ( $has_action ? 'task_needs_confirmation' : 'ambiguous_match' );
        $message = $show
            ? 'He encontrado evidencia semántica suficiente para ordenar estas opciones.'
            : ( $has_action
                ? 'Entiendo parte de la tarea, pero no tengo evidencia suficiente para elegir el producto por ti. Elige una familia o concreta un poco más.'
                : 'Hay varias interpretaciones posibles. Elige la familia que mejor encaja o concreta un poco más.' );

        return array(
            'confidence'=>$confidence,
            'level'=>$show ? 'high' : ( $confidence >= 50 ? 'medium' : 'low' ),
            'show_products'=>$show,
            'needs_choice'=>! $show,
            'reason'=>$reason,
            'message'=>$message,
            'signals'=>array(
                'semantic_groups'=>$group_total,
                'coverage'=>$coverage,
                'l9_strength'=>$l9_strength,
                'l9_matched'=>$l9_matched,
                'margin'=>round( $margin, 3 ),
                'actions'=>(array) ( $interpretation['actions'] ?? array() ),
                'sources'=>$sources,
                'customer_feedback'=>$has_feedback ? 1 : 0,
            ),
        );
    }

    public static function suggestions_from_products( $results, $limit = 8 ) {
        $limit = min( 8, max( 1, absint( $limit ) ) );
        $map = array();
        foreach ( array_slice( (array) $results, 0, 24 ) as $position => $result ) {
            $id = absint( $result['id'] ?? 0 );
            if ( ! $id ) continue;
            $terms = wp_get_post_terms( $id, 'product_cat' );
            if ( is_wp_error( $terms ) ) continue;
            foreach ( (array) $terms as $term ) {
                if ( ! $term instanceof WP_Term ) continue;
                $slug = sanitize_title( $term->slug ?: $term->name );
                if ( ! $slug ) continue;
                if ( ! isset( $map[ $slug ] ) ) {
                    $map[ $slug ] = array(
                        'kind'=>'category',
                        'id'=>absint( $term->term_id ),
                        'name'=>(string) $term->name,
                        'slug'=>$slug,
                        'count'=>0,
                        'first_rank'=>absint( $position ),
                    );
                }
                $map[ $slug ]['count']++;
                $map[ $slug ]['first_rank'] = min( absint( $map[ $slug ]['first_rank'] ), absint( $position ) );
            }
        }
        $items = array_values( $map );
        usort( $items, static function ( $a, $b ) {
            $cmp = absint( $a['first_rank'] ?? 0 ) <=> absint( $b['first_rank'] ?? 0 );
            if ( 0 !== $cmp ) return $cmp;
            return absint( $b['count'] ?? 0 ) <=> absint( $a['count'] ?? 0 );
        } );
        return array_slice( $items, 0, $limit );
    }

    private static function detect_actions( $normalized ) {
        $actions = array(
            'reparar','cortar','soldar','taladrar','perforar','lijar','pulir','pintar','elevar','levantar',
            'aflojar','apretar','desmontar','montar','limpiar','medir','diagnosticar','cargar','arrancar',
            'transportar','fijar','doblar','curvar','prensar','sellar','calentar','enfriar','extraer'
        );
        $found = array();
        $words = array_values( array_filter( explode( ' ', self::normalize( $normalized ) ) ) );
        foreach ( $words as $word ) {
            if ( in_array( $word, $actions, true ) ) $found[] = $word;
        }
        return array_values( array_unique( $found ) );
    }

    /**
     * Resolve exact product identity directly from L2 memory.
     *
     * This intentionally bypasses L9 fuzzy thresholds. The query still has to
     * match a signal that Dependiente previously learned, so this does not
     * consult the live product catalogue as an exam shortcut.
     */
    private static function identity_matches( $query, $include_stage = '' ) {
        global $wpdb;

        $normalized = self::normalize( $query );
        if ( ! $normalized ) return array();

        $compact = self::without_stopwords( $normalized );
        $terms = array(
            self::shorten( $normalized, 250 ),
        );
        if ( $compact ) $terms[] = self::shorten( $compact, 250 );
        $terms = array_values( array_unique( array_filter( $terms ) ) );
        if ( ! $terms ) return array();

        $table = TCD_Knowledge_Transfer::l9_table();
        $placeholders = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
        $full_normalized = self::shorten( $normalized, 250 );

        if ( $include_stage ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list contains only generated %d/%s placeholders and all identifiers/values are bound through $wpdb->prepare().
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT product_id,lesson_key,signal_type,signal_text,normalized_signal,weight,source_brain_id
                    FROM %i
                    WHERE (active=1 OR source_brain_id=%s)
                      AND lesson_key IN ('v2_l2_identity','quality_repair_l2')
                      AND signal_type IN ('identity_title','identity_sku','quality_repair_identity')
                      AND normalized_signal IN ({$placeholders})
                    ORDER BY CASE WHEN normalized_signal=%s THEN 0 ELSE 1 END,weight DESC,id ASC
                    LIMIT 100",
                    array_merge( array( $table, $include_stage ), $terms, array( $full_normalized ) )
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        } else {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list contains only generated %d/%s placeholders and all identifiers/values are bound through $wpdb->prepare().
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT product_id,lesson_key,signal_type,signal_text,normalized_signal,weight,source_brain_id
                    FROM %i
                    WHERE active=1
                      AND lesson_key IN ('v2_l2_identity','quality_repair_l2')
                      AND signal_type IN ('identity_title','identity_sku','quality_repair_identity')
                      AND normalized_signal IN ({$placeholders})
                    ORDER BY CASE WHEN normalized_signal=%s THEN 0 ELSE 1 END,weight DESC,id ASC
                    LIMIT 100",
                    array_merge( array( $table ), $terms, array( $full_normalized ) )
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        }
        if ( ! $rows ) return array();

        $out = array();
        foreach ( $rows as $row ) {
            $product_id = absint( $row['product_id'] ?? 0 );
            if ( ! $product_id ) continue;
            $signal_normalized = self::normalize( (string) ( $row['normalized_signal'] ?? '' ) );
            $exact = self::shorten( $normalized, 250 ) === $signal_normalized;
            $score = absint( $row['weight'] ?? 0 ) + ( $exact ? 500 : 250 );

            if ( ! isset( $out[ $product_id ] ) || $score > absint( $out[ $product_id ]['score'] ?? 0 ) ) {
                $out[ $product_id ] = array(
                    'product_id'=>$product_id,
                    'score'=>$score,
                    'weight'=>absint( $row['weight'] ?? 0 ),
                    'identity_exact'=>$exact ? 1 : 0,
                    'quality_repair'=>0 === strpos( sanitize_key( (string) ( $row['lesson_key'] ?? '' ) ), 'quality_repair_' ) ? 1 : 0,
                    'signal_type'=>sanitize_key( (string) ( $row['signal_type'] ?? '' ) ),
                    'signal'=>(string) ( $row['signal_text'] ?? '' ),
                    'source_brain_id'=>(string) ( $row['source_brain_id'] ?? '' ),
                );
            }
        }

        return $out;
    }

    private static function l9_matches( $query, $include_stage = '' ) {
        global $wpdb;
        $normalized = self::normalize( $query );
        if ( ! $normalized ) return array();
        // Always query the complete normalized expression first. Identity
        // learning stores the full title/SKU, and quality repair may store the
        // complete failed query. Limiting lookup to <=5-word n-grams makes
        // those exact long signals unreachable even though they were learned.
        $terms = array( $normalized );
        $terms = array_merge( $terms, self::ngrams( $normalized, 5 ) );
        $compact = self::without_stopwords( $normalized );
        if ( $compact ) {
            $terms[] = $compact;
            $terms = array_merge( $terms, self::ngrams( $compact, 5 ) );
        }
        foreach ( explode( ' ', $compact ?: $normalized ) as $token ) {
            if ( strlen( $token ) >= 3 ) $terms = array_merge( $terms, self::token_variants( $token ) );
        }
        $terms = array_slice( array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize' ), $terms ) ) ) ), 0, 120 );
        if ( ! $terms ) return array();

        $table = TCD_Knowledge_Transfer::l9_table();
        $placeholders = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
        if ( $include_stage ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list contains only generated %d/%s placeholders and all identifiers/values are bound through $wpdb->prepare().
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT lesson_key,product_id,signal_type,signal_text,normalized_signal,semantic_group,weight FROM %i WHERE (active=1 OR source_brain_id=%s) AND normalized_signal IN ({$placeholders}) ORDER BY weight DESC LIMIT 8000",
                    array_merge( array( $table, $include_stage ), $terms )
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        } else {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list contains only generated %d/%s placeholders and all identifiers/values are bound through $wpdb->prepare().
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT lesson_key,product_id,signal_type,signal_text,normalized_signal,semantic_group,weight FROM %i WHERE active=1 AND normalized_signal IN ({$placeholders}) ORDER BY weight DESC LIMIT 8000",
                    array_merge( array( $table ), $terms )
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        }
        $products = array();
        foreach ( $rows as $row ) {
            $id = absint( $row['product_id'] ?? 0 );
            if ( ! $id ) continue;
            if ( ! isset( $products[ $id ] ) ) $products[ $id ] = array(
                'product_id'=>$id,
                'score'=>0,
                'concepts'=>array(),
                'phrase_hits'=>0,
                'matches'=>array(),
                'customer_feedback'=>false,
                'identity_exact'=>false,
                'quality_repair'=>false,
            );
            $origin = self::normalize( (string) ( $row['signal_text'] ?? '' ) );
            $group = sanitize_key( (string) ( $row['semantic_group'] ?? $row['signal_type'] ?? '' ) );
            $signal_type = sanitize_key( (string) ( $row['signal_type'] ?? '' ) );
            $signal_normalized = self::normalize( (string) ( $row['normalized_signal'] ?? '' ) );
            $lesson_key = sanitize_key( (string) ( $row['lesson_key'] ?? '' ) );
            if ( in_array( $signal_type, array( 'identity_title','identity_sku','quality_repair_identity' ), true )
                && $signal_normalized === $normalized ) {
                $products[ $id ]['identity_exact'] = true;
            }
            if ( 0 === strpos( $lesson_key, 'quality_repair_' ) || 0 === strpos( $signal_type, 'quality_repair_' ) ) {
                $products[ $id ]['quality_repair'] = true;
            }
            $concept = $group . '|' . $origin;
            if ( isset( $products[ $id ]['concepts'][ $concept ] ) ) continue;
            $weight = absint( $row['weight'] ?? 0 );
            // Retries must never turn one generic token (e.g. herramienta)
            // into a global 500-point preference across unrelated queries.
            if ( 0 === strpos( $signal_type, 'quality_repair_' )
                && false === strpos( $signal_normalized, ' ' ) ) {
                $weight = min( 80, $weight );
            }
            $products[ $id ]['concepts'][ $concept ] = $weight;
            $products[ $id ]['score'] += $weight;
            if ( false !== strpos( (string) ( $row['normalized_signal'] ?? '' ), ' ' ) ) $products[ $id ]['phrase_hits']++;
            if ( 'customer_query' === sanitize_key( (string) ( $row['signal_type'] ?? '' ) ) || 'customer_feedback' === sanitize_key( (string) ( $row['semantic_group'] ?? '' ) ) ) {
                $products[ $id ]['customer_feedback'] = true;
            }
            if ( count( $products[ $id ]['matches'] ) < 10 ) $products[ $id ]['matches'][] = array(
                'type'=>sanitize_key( (string) ( $row['signal_type'] ?? '' ) ),
                'signal'=>(string) ( $row['signal_text'] ?? '' ),
                'weight'=>absint( $row['weight'] ?? 0 ),
            );
        }
        foreach ( $products as $id => &$item ) {
            $item['matched_count'] = count( $item['concepts'] );
            if ( $item['matched_count'] >= 2 ) $item['score'] += min( 320, 75 * ( $item['matched_count'] - 1 ) );
            unset( $item['concepts'] );
            if ( !( ( $item['matched_count'] >= 2 && $item['score'] >= 190 ) || ( $item['phrase_hits'] >= 1 && $item['score'] >= 230 ) ) ) unset( $products[ $id ] );
        }
        unset( $item );
        return $products;
    }

    private static function products_for_category( $slug, $limit ) {
        $slug = sanitize_title( $slug );
        if ( ! $slug ) return array();
        $term = get_term_by( 'slug', $slug, 'product_cat' );
        if ( ! $term instanceof WP_Term ) return array();
        return array_map( 'absint', get_posts( array(
            'post_type'=>'product',
            'post_status'=>'publish',
            'posts_per_page'=>min( 200, max( 1, absint( $limit ) ) ),
            'fields'=>'ids',
            'no_found_rows'=>true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Es un filtro funcional por taxonomía de productos; solo recupera IDs con límite 200 y no_found_rows.
            'tax_query'=>array(
                array(
                    'taxonomy'=>'product_cat',
                    'field'=>'term_id',
                    'terms'=>array( absint( $term->term_id ) ),
                    'include_children'=>true,
                ),
            ),
        ) ) );
    }

    private static function products_for_vocabulary( $group, $slug, $limit, $specific_ids = array() ) {
        global $wpdb;
        $group = sanitize_key( $group );
        $slug = sanitize_title( $slug );
        if ( ! $group || ! $slug ) return array();
        $voc = $wpdb->prefix . 'seo_vocabulary';
        $rel = $wpdb->prefix . 'seo_object_vocabulary';
        if ( ! self::table_exists( $voc ) || ! self::table_exists( $rel ) ) return array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $vocabulary_id = absint( $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM %i WHERE active=1 AND semantic_group=%s AND slug IN (%s,%s) ORDER BY id ASC LIMIT 1",
            $voc, $group, $slug, str_replace( '-', '_', $slug )
        ) ) );
        if ( ! $vocabulary_id ) return array();
        if ( $specific_ids ) {
            $specific_ids = array_values( array_filter( array_unique( array_map( 'absint', $specific_ids ) ) ) );
            if ( ! $specific_ids ) return array();
            $placeholders = implode( ',', array_fill( 0, count( $specific_ids ), '%d' ) );
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list contains only generated %d/%s placeholders and all identifiers/values are bound through $wpdb->prepare().
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT object_id FROM %i WHERE object_type='product' AND status=1 AND vocabulary_id=%d AND object_id IN ({$placeholders}) LIMIT %d",
                array_merge( array( $rel, $vocabulary_id ), $specific_ids, array( absint( $limit ) ) )
            ) ) );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT object_id FROM %i WHERE object_type='product' AND status=1 AND vocabulary_id=%d ORDER BY object_id ASC LIMIT %d",
            $rel, $vocabulary_id, absint( $limit )
        ) ) );
    }

    private static function search_categories( $query, $limit, $stage = '' ) {
        $out = array();
        foreach ( self::quality_target_hints( $query, 1, $stage ) as $id ) {
            $term = get_term( $id, 'product_cat' );
            if ( $term instanceof WP_Term ) $out[] = array( 'id'=>$term->term_id, 'title'=>$term->name );
        }
        $exact = get_term_by( 'name', trim( (string) $query ), 'product_cat' );
        if ( $exact instanceof WP_Term ) {
            $exists = false;
            foreach ( $out as $item ) if ( absint( $item['id'] ?? 0 ) === absint( $exact->term_id ) ) { $exists = true; break; }
            if ( ! $exists ) $out[] = array( 'id'=>$exact->term_id, 'title'=>$exact->name );
        }
        $rows = get_terms( array( 'taxonomy'=>'product_cat', 'hide_empty'=>false, 'search'=>sanitize_text_field( $query ), 'number'=>absint( $limit ) ) );
        if ( is_wp_error( $rows ) ) return $out;
        foreach ( $rows as $term ) {
            if ( ! $term instanceof WP_Term ) continue;
            $exists = false;
            foreach ( $out as $item ) if ( absint( $item['id'] ?? 0 ) === absint( $term->term_id ) ) { $exists = true; break; }
            if ( ! $exists ) $out[] = array( 'id'=>$term->term_id, 'title'=>$term->name );
            if ( count( $out ) >= absint( $limit ) ) break;
        }
        return $out;
    }

    private static function search_content( $query, $limit, $stage = '' ) {
        $out = array();
        foreach ( self::quality_target_hints( $query, 5, $stage ) as $id ) {
            $post = get_post( $id );
            if ( $post instanceof WP_Post && in_array( $post->post_type, array( 'post','page' ), true ) && 'publish' === $post->post_status ) {
                $out[] = array( 'id'=>$post->ID, 'title'=>$post->post_title );
            }
        }

        $exact_rows = get_posts( array(
            'post_type'=>array( 'post','page' ),
            'post_status'=>'publish',
            'title'=>sanitize_text_field( (string) $query ),
            'posts_per_page'=>absint( $limit ),
            'no_found_rows'=>true,
            'suppress_filters'=>false,
        ) );
        $rows = array_merge( $exact_rows, get_posts( array(
            'post_type'=>array( 'post','page' ),
            'post_status'=>'publish',
            's'=>sanitize_text_field( (string) $query ),
            'posts_per_page'=>absint( $limit ),
            'no_found_rows'=>true,
            'suppress_filters'=>false,
        ) ) );

        foreach ( $rows as $post ) {
            if ( ! $post instanceof WP_Post ) continue;
            $exists = false;
            foreach ( $out as $item ) if ( absint( $item['id'] ?? 0 ) === absint( $post->ID ) ) { $exists = true; break; }
            if ( ! $exists ) $out[] = array( 'id'=>$post->ID, 'title'=>$post->post_title );
            if ( count( $out ) >= absint( $limit ) ) break;
        }
        return $out;
    }

    private static function search_faqs( $query, $limit, $stage = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_faq';
        if ( ! self::table_exists( $table ) ) return array();
        $out = array();
        foreach ( self::quality_target_hints( $query, 6, $stage ) as $id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id,question FROM %i WHERE active=1 AND id=%d LIMIT 1",
                $table, $id
            ), ARRAY_A );
            if ( $row ) $out[] = array( 'id'=>absint( $row['id'] ?? 0 ), 'title'=>(string) ( $row['question'] ?? '' ) );
        }

        $like = '%' . $wpdb->esc_like( trim( (string) $query ) ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id,question FROM %i WHERE active=1 AND question LIKE %s ORDER BY CASE WHEN question=%s THEN 0 ELSE 1 END,id ASC LIMIT %d",
            $table, $like, trim( (string) $query ), absint( $limit )
        ), ARRAY_A );
        foreach ( $rows as $row ) {
            $id = absint( $row['id'] ?? 0 );
            if ( ! $id ) continue;
            $exists = false;
            foreach ( $out as $item ) if ( absint( $item['id'] ?? 0 ) === $id ) { $exists = true; break; }
            if ( ! $exists ) $out[] = array( 'id'=>$id, 'title'=>(string) ( $row['question'] ?? '' ) );
            if ( count( $out ) >= absint( $limit ) ) break;
        }
        return $out;
    }

    /**
     * M11 independently tests the published runtime after L9 is activated.
     * Use exactly the same L9 need generator and family/product quality
     * semantics as the course, rather than the pre-0.3.12 legacy question.
     *
     * The 180 deterministic samples are distributed over the sealed L9
     * population. Work is resumed in small batches, honouring the CPU load
     * manager and the user's Stop button. No L1-L10 knowledge is modified.
     */
    public static function build_interpreter() {
        global $wpdb;
        $knowledge = TCD_Academy::knowledge_table();
        $snapshot = TCD_Academy::snapshot_for_lesson( 9 );
        $snapshot_id = absint( $snapshot['id'] ?? 0 );
        if ( ! $snapshot_id || 'completed' !== (string) ( $snapshot['status'] ?? '' ) ) {
            return new WP_Error( 'tcd_interpreter_l9_unavailable', 'M11 requiere una L9 terminada y validada.' );
        }

        $progress = get_option( self::INTERPRETER_PROGRESS, array() );
        if ( ! is_array( $progress )
            || (string) ( $progress['revision'] ?? '' ) !== self::INTERPRETER_EVALUATOR_REVISION
            || absint( $progress['l9_snapshot_id'] ?? 0 ) !== $snapshot_id
        ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $all_ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM %i WHERE lesson=%d AND status=%s ORDER BY source_type ASC,source_id ASC",
                $knowledge, 9, 'learned'
            ) ) );
            if ( ! $all_ids ) {
                return new WP_Error( 'tcd_interpreter_no_sources', 'M11 no encuentra fuentes L9 aprendidas.' );
            }
            // Read the sizeable active memory counters once per run; do not
            // repeat six COUNT queries on every short M11 validation batch.
            $memory_stats = TCD_Knowledge_Transfer::stats();
            $sample = self::representative_sample(
                array_map( static function ( $id ) { return array( 'id'=>absint( $id ) ); }, $all_ids ),
                180
            );
            $progress = array(
                'revision'=>self::INTERPRETER_EVALUATOR_REVISION,
                'l9_snapshot_id'=>$snapshot_id,
                'sample_method'=>'distributed_deterministic',
                'population'=>count( $all_ids ),
                'rules'=>absint( $memory_stats['portable_rules'] ?? 0 ),
                'l9_memory'=>absint( $memory_stats['l9_memory'] ?? 0 ),
                'ids'=>array_values( array_map( static function ( $row ) { return absint( $row['id'] ); }, $sample ) ),
                'cursor'=>0,
                'passed'=>0,
                'top1'=>0,
                'top3'=>0,
                'query_origins'=>array(),
                'sample'=>array(),
                'failures'=>array(),
                'started_at'=>current_time( 'mysql' ),
            );
            update_option( self::INTERPRETER_PROGRESS, $progress, false );
        }

        $ids = (array) ( $progress['ids'] ?? array() );
        $total = count( $ids );
        if ( ! $total ) return new WP_Error( 'tcd_interpreter_empty_sample', 'La muestra M11 está vacía.' );
        $cursor = min( $total, absint( $progress['cursor'] ?? 0 ) );
        $limit = min( 8, max( 1, TCD_Load::batch_size() ) );
        $batch = array_slice( $ids, $cursor, $limit );
        $batch_processed = 0;

        if ( $batch ) {
            $placeholders = implode( ',', array_fill( 0, count( $batch ), '%d' ) );
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list contains only generated %d/%s placeholders and all identifiers/values are bound through $wpdb->prepare().
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
            $rows = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT id,source_type,source_id,title,context_json,status FROM %i WHERE lesson=9 AND id IN ({$placeholders})",
                array_merge( array( $knowledge ), $batch )
            ), ARRAY_A );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $by_id = array();
            foreach ( $rows as $row ) $by_id[ absint( $row['id'] ?? 0 ) ] = $row;
            $started = microtime( true );
            foreach ( $batch as $knowledge_id ) {
                $row = (array) ( $by_id[ absint( $knowledge_id ) ] ?? array() );
                if ( $row ) {
                    // Empty stage: this is a true production-facing runtime
                    // lookup, not an exam with staged/inactive test signals.
                    $result = self::evaluate_row( 9, $row, '' );
                } else {
                    $result = array(
                        'source_id'=>0,
                        'query'=>'',
                        'top1'=>false,
                        'top3'=>false,
                        'top8'=>false,
                        'query_origin'=>'missing_source',
                        'need_scope'=>'unknown',
                        'rank'=>0,
                        'reason'=>'knowledge_source_missing',
                    );
                }
                if ( ! empty( $result['top1'] ) ) $progress['top1']++;
                if ( ! empty( $result['top3'] ) ) $progress['top3']++;
                if ( ! empty( $result['top8'] ) ) $progress['passed']++;
                $origin = sanitize_key( (string) ( $result['query_origin'] ?? 'unknown' ) );
                $progress['query_origins'][ $origin ] = absint( $progress['query_origins'][ $origin ] ?? 0 ) + 1;
                if ( empty( $result['top8'] ) ) {
                    // Keep enough evidence to correct genuine ranking faults.
                    $progress['failures'][] = $result;
                }
                if ( count( $progress['sample'] ) < 12 ) $progress['sample'][] = $result;
                $batch_processed++;
                if ( microtime( true ) - $started >= 12 ) break;
            }
            $cursor += $batch_processed;
            $progress['cursor'] = $cursor;
            update_option( self::INTERPRETER_PROGRESS, $progress, false );
        }

        $tested = $cursor;
        $passed = absint( $progress['passed'] ?? 0 );
        $ratio = $tested ? $passed / $tested : 0;
        $done = $cursor >= $total;
        $state = array(
            'status'=>$done ? ( $ratio >= 0.88 ? 'ready' : 'degraded' ) : 'building',
            'built_at'=>$done ? current_time( 'mysql' ) : '',
            'started_at'=>(string) ( $progress['started_at'] ?? '' ),
            'tested'=>$tested,
            'total'=>$total,
            'passed'=>$passed,
            'failed'=>$tested - $passed,
            'top1'=>absint( $progress['top1'] ?? 0 ),
            'top3'=>absint( $progress['top3'] ?? 0 ),
            'pass_any_ratio'=>$ratio,
            'threshold'=>0.88,
            'rules'=>absint( $progress['rules'] ?? 0 ),
            'l9_memory'=>absint( $progress['l9_memory'] ?? 0 ),
            'version'=>TAXOCOMMERCE_DEPENDIENTE_VERSION,
            'evaluator_revision'=>self::INTERPRETER_EVALUATOR_REVISION,
            'l9_snapshot_id'=>$snapshot_id,
            'sample_method'=>(string) ( $progress['sample_method'] ?? '' ),
            'population'=>absint( $progress['population'] ?? 0 ),
            'query_origins'=>(array) ( $progress['query_origins'] ?? array() ),
            'sample'=>(array) ( $progress['sample'] ?? array() ),
            'failures'=>(array) ( $progress['failures'] ?? array() ),
            'done'=>$done,
            'batch_evaluated'=>$batch_processed,
        );
        update_option( self::INTERPRETER_STATE, $state, false );

        if ( ! $done ) return $state;
        delete_option( self::INTERPRETER_PROGRESS );
        if ( 'ready' !== $state['status'] ) {
            return new WP_Error(
                'tcd_interpreter_regression',
                sprintf( 'El Intérprete no supera la regresión: %.1f%% de %.1f%% mínimo (%d/%d).', 100 * $ratio, 88, $passed, $tested )
            );
        }
        return $state;
    }

    public static function interpreter_audit_url() {
        return wp_nonce_url(
            add_query_arg(
                array( 'action'=>'tcd_interpreter_audit_json' ),
                admin_url( 'admin-post.php' )
            ),
            'tcd_interpreter_audit_json'
        );
    }

    public static function download_interpreter_audit_json() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permiso.' );
        }
        check_admin_referer( 'tcd_interpreter_audit_json' );
        $state = self::interpreter_state();
        if ( ! $state ) wp_die( 'Todavía no hay informe del intérprete.' );
        $payload = array(
            'schema'=>array( 'name'=>'taxocommerce_dependiente_m11_regression', 'version'=>1 ),
            'exported_at'=>gmdate( 'c' ),
            'plugin_version'=>TAXOCOMMERCE_DEPENDIENTE_VERSION,
            'engine_revision'=>self::INTERPRETER_EVALUATOR_REVISION,
            'report_is_stale'=>(string) ( $state['evaluator_revision'] ?? '' ) !== self::INTERPRETER_EVALUATOR_REVISION,
            'regression'=>$state,
        );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="dependiente_M11_regresion_' . gmdate( 'Ymd_His' ) . '.json"' );
        echo wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON admin download.
        exit;
    }

    public static function interpreter_state() {
        $state = get_option( self::INTERPRETER_STATE, array() );
        return is_array( $state ) ? $state : array();
    }

    public static function refresh_product( $product_id ) {
        global $wpdb;
        $product_id = absint( $product_id );
        if ( ! $product_id || ! method_exists( 'TCD_Sources', 'product_item' ) ) return;
        $item = TCD_Sources::product_item( 9, $product_id );
        if ( ! is_array( $item ) ) return;
        $table = TCD_Knowledge_Transfer::l9_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM %i WHERE product_id=%d AND (source_brain_id LIKE %s OR source_brain_id LIKE %s)",
            $table, $product_id, 'modern:%', 'maintenance:%'
        ) );
        $stage = 'maintenance:product:' . $product_id;
        self::stage_l9_item( $item, $stage );
        self::activate_stage( $stage );
    }

    public static function refresh_category( $term_id ) {
        $term_id = absint( $term_id );
        if ( ! $term_id || ! method_exists( 'TCD_Sources', 'category_item' ) ) return;
        $item = TCD_Sources::category_item( $term_id );
        if ( ! is_array( $item ) ) return;
        // Previous names remain useful aliases; the current category adds or
        // refreshes its canonical semantic rules without deleting history.
        self::refresh_semantic_item( 1, $item, 'category:' . $term_id );
    }

    public static function refresh_semantic_item( $lesson, $item, $maintenance_key ) {
        $lesson = absint( $lesson );
        if ( ! is_array( $item ) || ! in_array( $lesson, array( 1, 3, 4, 5, 6, 7 ), true ) ) return 0;
        $stage = 'maintenance:' . sanitize_key( (string) $maintenance_key );
        $count = 0;
        foreach ( self::rules_from_item( $lesson, $item ) as $rule ) {
            self::stage_rule( $lesson, $item, $rule, $stage );
            $count++;
        }
        if ( $count ) self::activate_stage( $stage );
        return $count;
    }

    public static function normalize( $value ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' );
        $value = remove_accents( $value );
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        $value = preg_replace( '/(?<=\d)(?=[a-z])|(?<=[a-z])(?=\d)/u', ' ', $value );
        $value = preg_replace( '/[^a-z0-9]+/u', ' ', $value );
        return trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
    }

    private static function ngrams( $text, $max ) {
        $words = array_values( array_filter( explode( ' ', self::normalize( $text ) ) ) );
        $out = array();
        $count = count( $words );
        for ( $size = min( absint( $max ), $count ); $size >= 1; $size-- ) {
            for ( $i = 0; $i + $size <= $count; $i++ ) $out[] = implode( ' ', array_slice( $words, $i, $size ) );
        }
        return array_values( array_unique( $out ) );
    }

    private static function token_variants( $token ) {
        $token = self::normalize( $token );
        if ( ! $token ) return array();
        $out = array( $token );
        if ( false === strpos( $token, ' ' ) && strlen( $token ) >= 4 ) {
            $last = substr( $token, -1 );
            $last2 = substr( $token, -2 );
            if ( 'z' === $last && strlen( $token ) > 4 ) {
                $out[] = substr( $token, 0, -1 ) . 'ces';
            } elseif ( preg_match( '/[aeiou]$/', $token ) ) {
                $out[] = $token . 's';
            } elseif ( 's' === $last && strlen( $token ) > 4 ) {
                $out[] = substr( $token, 0, -1 );
                if ( 'es' === $last2 && strlen( $token ) > 5 ) $out[] = substr( $token, 0, -2 );
            } else {
                $out[] = $token . 'es';
            }
        }
        return array_values( array_unique( array_filter( $out ) ) );
    }

    private static function without_stopwords( $text ) {
        $stop = array(
            'a','al','algo','algun','alguna','algunas','algunos','ante','bajo','cada','como','con','contra','cual','cuando',
            'de','del','desde','donde','e','el','ella','ellas','ellos','en','entre','era','es','esa','esas','ese','eso','esos',
            'esta','estas','este','esto','estos','ha','hacia','hasta','hay','la','las','le','les','lo','los','me','mi','mis',
            'muy','nos','o','para','pero','por','porque','que','se','sin','sobre','su','sus','te','tu','tus','un','una','unas',
            'uno','unos','y','ya','yo','quiero','queremos','necesito','necesitamos','busco','buscar','dame','mostrar','muestra',
            'producto','productos'
        );
        return implode( ' ', array_values( array_filter( explode( ' ', self::normalize( $text ) ), static function ( $word ) use ( $stop ) {
            return ! in_array( $word, $stop, true );
        } ) ) );
    }

    private static function description_phrases( $text ) {
        $tokens = array_values( array_filter( explode( ' ', self::without_stopwords( $text ) ), static function ( $word ) {
            return strlen( $word ) >= 4 && ! in_array( $word, array( 'precio','oferta','envio','comprar','producto','referencia','comprobar','revisar' ), true );
        } ) );
        $out = array();
        for ( $i = 0; $i + 2 < count( $tokens ) && count( $out ) < 3; $i += 3 ) $out[] = implode( ' ', array_slice( $tokens, $i, 4 ) );
        return $out;
    }

    private static function table_exists( $table ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Interpreter learning and M11 assessment require exact live plugin-table reads and writes; results must not be cached.
        return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === (string) $table;
    }

    private static function shorten( $value, $length ) {
        $value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, absint( $length ), 'UTF-8' ) : substr( $value, 0, absint( $length ) );
    }
}
