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
    }

    public static function reset_modern_generated() {
        global $wpdb;
        $rules = TCD_Knowledge_Transfer::rules_table();
        $l9 = TCD_Knowledge_Transfer::l9_table();
        $quality = self::quality_table();
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source=%s', $rules, 'modern_learning' ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE source_brain_id LIKE %s OR source_brain_id LIKE %s", $l9, 'modern:%', 'maintenance:%' ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $quality ) );
        delete_option( self::INTERPRETER_STATE );
    }

    public static function stage_id( $lesson, $snapshot_id ) {
        return 'modern:L' . absint( $lesson ) . ':S' . absint( $snapshot_id );
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
        $wpdb->replace( $table, $payload );
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

    private static function evaluate_lesson( $lesson, $snapshot_id ) {
        global $wpdb;
        $table = TCD_Academy::knowledge_table();
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT source_type,source_id,title,context_json,status FROM {$table} WHERE lesson=%d ORDER BY id ASC LIMIT 180",
            $lesson
        ), ARRAY_A );
        $result = array( 'evaluated'=>0, 'pass_top1'=>0, 'pass_top3'=>0, 'pass_top8'=>0, 'failed'=>0, 'pass_any_ratio'=>0, 'sample'=>array() );
        $stage = self::stage_id( $lesson, $snapshot_id );
        foreach ( $rows as $row ) {
            if ( 'learned' !== (string) ( $row['status'] ?? '' ) ) continue;
            $evaluation = self::evaluate_row( $lesson, $row, $stage );
            $result['evaluated']++;
            if ( ! empty( $evaluation['top1'] ) ) $result['pass_top1']++;
            if ( ! empty( $evaluation['top3'] ) ) $result['pass_top3']++;
            if ( ! empty( $evaluation['top8'] ) ) $result['pass_top8']++;
            if ( empty( $evaluation['top8'] ) ) $result['failed']++;
            if ( count( $result['sample'] ) < 12 ) $result['sample'][] = $evaluation;
        }
        $result['pass_any_ratio'] = $result['evaluated'] ? $result['pass_top8'] / $result['evaluated'] : 0;
        return $result;
    }

    private static function evaluate_row( $lesson, $row, $stage ) {
        $id = absint( $row['source_id'] ?? 0 );
        $title = trim( (string) ( $row['title'] ?? '' ) );
        $facts = json_decode( (string) ( $row['context_json'] ?? '' ), true );
        $facts = is_array( $facts ) ? $facts : array();
        $ids = array();
        $query = $title;

        if ( in_array( $lesson, array( 2, 4, 9 ), true ) ) {
            if ( 9 === $lesson && ! empty( $facts['customer_need'] ) ) $query = (string) $facts['customer_need'];
            foreach ( self::search_products( $query, 8, $stage ) as $item ) $ids[] = absint( $item['id'] ?? 0 );
        } elseif ( 1 === $lesson ) {
            foreach ( self::search_categories( $query, 8 ) as $item ) $ids[] = absint( $item['id'] ?? 0 );
        } elseif ( 5 === $lesson ) {
            foreach ( self::search_content( $query, 8 ) as $item ) $ids[] = absint( $item['id'] ?? 0 );
        } elseif ( 6 === $lesson ) {
            foreach ( self::search_faqs( $query, 8 ) as $item ) $ids[] = absint( $item['id'] ?? 0 );
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
        $rank = false === $pos ? 0 : $pos + 1;
        return array( 'source_id'=>$id, 'query'=>$query, 'rank'=>$rank, 'top1'=>1 === $rank, 'top3'=>$rank > 0 && $rank <= 3, 'top8'=>$rank > 0 && $rank <= 8 );
    }

    private static function activate_stage( $stage ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'UPDATE %i SET active=1,updated_at=%s WHERE source_brain_id=%s', TCD_Knowledge_Transfer::rules_table(), current_time( 'mysql' ), $stage ) );
        $wpdb->query( $wpdb->prepare( 'UPDATE %i SET active=1,updated_at=%s WHERE source_brain_id=%s', TCD_Knowledge_Transfer::l9_table(), current_time( 'mysql' ), $stage ) );
    }

    public static function quality_for_lesson( $lesson ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM %i WHERE lesson=%d ORDER BY id DESC LIMIT 1',
            self::quality_table(), absint( $lesson )
        ), ARRAY_A );
        return is_array( $row ) ? $row : array();
    }

    public static function interpret( $query, $include_stage = '' ) {
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
        $ngrams = self::ngrams( $normalized, 5 );
        if ( ! $ngrams ) return $result;
        $table = TCD_Knowledge_Transfer::rules_table();
        $placeholders = implode( ',', array_fill( 0, count( $ngrams ), '%s' ) );
        $params = $ngrams;
        $active = 'active=1';
        if ( $include_stage ) {
            $active = '(active=1 OR source_brain_id=%s)';
            array_unshift( $params, $include_stage );
        }
        $sql = "SELECT id,rule_type,expression,normalized_expression,canonical_expression,semantic_role,source_group,source_slug,target_group,target_slug,relation_type,result_role,weight,priority,confidence,source FROM {$table} WHERE {$active} AND language='es' AND normalized_expression IN ({$placeholders}) ORDER BY LENGTH(normalized_expression) DESC,priority ASC,weight DESC,id ASC LIMIT 300";
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
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
                );
            }
        }
        self::apply_vocabulary_direct( $result, $ngrams );
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
        $sql = "SELECT id,semantic_group,slug,label FROM {$table} WHERE active=1 AND slug IN ({$placeholders}) ORDER BY id ASC LIMIT 150";
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $slugs ), ARRAY_A );
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

    private static function apply_routes( &$result, $include_stage = '' ) {
        global $wpdb;
        if ( empty( $result['groups'] ) ) return;
        $table = TCD_Knowledge_Transfer::rules_table();
        $params = array();
        $active = 'active=1';
        if ( $include_stage ) {
            $active = '(active=1 OR source_brain_id=%s)';
            $params[] = $include_stage;
        }
        $sql = "SELECT id,source_group,source_slug,context_group,context_slug,target_group,target_slug,relation_type,result_role,weight,priority,confidence,source
                FROM {$table}
                WHERE {$active} AND rule_type='route' AND language='es'
                ORDER BY priority ASC,weight DESC,id ASC LIMIT 500";
        $rows = $params
            ? (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A )
            : (array) $wpdb->get_results( $sql, ARRAY_A );
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
            $count = absint( $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE lesson=%d AND status=%s',
                TCD_Academy::knowledge_table(), $lesson, 'learned'
            ) ) );
            if ( ! $count ) continue;
            $snapshot = TCD_Academy::snapshot_for_lesson( $lesson );
            $snapshot_id = absint( $snapshot['id'] ?? 0 );
            $last = 0;
            do {
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

    public static function search_products( $query, $limit = 8, $include_stage = '' ) {
        $limit = min( 36, max( 1, absint( $limit ) ) );
        $interpretation = self::interpret( $query, $include_stage );
        $candidates = array();

        foreach ( self::l9_matches( $query, $include_stage ) as $product_id => $match ) {
            self::candidate_add( $candidates, $product_id, 300 + absint( $match['score'] ?? 0 ), 'lesson9', $match );
        }

        foreach ( (array) ( $interpretation['groups'] ?? array() ) as $group ) {
            foreach ( self::products_for_vocabulary( (string) ( $group['group'] ?? '' ), (string) ( $group['slug'] ?? '' ), 160 ) as $product_id ) {
                self::candidate_add( $candidates, $product_id, 190 + absint( $group['weight'] ?? 0 ), 'semantic', $group );
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
            $coverage_a = count( (array) ( $a['semantic_groups'] ?? array() ) );
            $coverage_b = count( (array) ( $b['semantic_groups'] ?? array() ) );
            $cmp = $coverage_b <=> $coverage_a;
            if ( 0 !== $cmp ) return $cmp;
            $cmp = absint( $b['l9_matched'] ?? 0 ) <=> absint( $a['l9_matched'] ?? 0 );
            if ( 0 !== $cmp ) return $cmp;
            $cmp = (float) ( $b['score'] ?? 0 ) <=> (float) ( $a['score'] ?? 0 );
            if ( 0 !== $cmp ) return $cmp;
            return absint( $b['id'] ?? 0 ) <=> absint( $a['id'] ?? 0 );
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
        $has_l9 = in_array( 'lesson9', $sources, true );
        $text_only = ! $has_semantic && ! $has_l9;
        $has_action = ! empty( $interpretation['actions'] );

        $confidence = 15;
        if ( $has_semantic ) $confidence += 28;
        if ( $has_l9 ) $confidence += 32;
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

    private static function l9_matches( $query, $include_stage = '' ) {
        global $wpdb;
        $normalized = self::normalize( $query );
        if ( ! $normalized ) return array();
        $terms = self::ngrams( $normalized, 5 );
        $compact = self::without_stopwords( $normalized );
        if ( $compact ) $terms = array_merge( $terms, self::ngrams( $compact, 5 ) );
        foreach ( explode( ' ', $compact ?: $normalized ) as $token ) {
            if ( strlen( $token ) >= 3 ) $terms = array_merge( $terms, self::token_variants( $token ) );
        }
        $terms = array_slice( array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normalize' ), $terms ) ) ) ), 0, 120 );
        if ( ! $terms ) return array();

        $table = TCD_Knowledge_Transfer::l9_table();
        $placeholders = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
        $params = $terms;
        $active = 'active=1';
        if ( $include_stage ) {
            $active = '(active=1 OR source_brain_id=%s)';
            array_unshift( $params, $include_stage );
        }
        $sql = "SELECT product_id,signal_type,signal_text,normalized_signal,semantic_group,weight FROM {$table} WHERE {$active} AND normalized_signal IN ({$placeholders}) ORDER BY weight DESC LIMIT 8000";
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        $products = array();
        foreach ( $rows as $row ) {
            $id = absint( $row['product_id'] ?? 0 );
            if ( ! $id ) continue;
            if ( ! isset( $products[ $id ] ) ) $products[ $id ] = array( 'product_id'=>$id, 'score'=>0, 'concepts'=>array(), 'phrase_hits'=>0, 'matches'=>array() );
            $origin = self::normalize( (string) ( $row['signal_text'] ?? '' ) );
            $group = sanitize_key( (string) ( $row['semantic_group'] ?? $row['signal_type'] ?? '' ) );
            $concept = $group . '|' . $origin;
            if ( isset( $products[ $id ]['concepts'][ $concept ] ) ) continue;
            $products[ $id ]['concepts'][ $concept ] = absint( $row['weight'] ?? 0 );
            $products[ $id ]['score'] += absint( $row['weight'] ?? 0 );
            if ( false !== strpos( (string) ( $row['normalized_signal'] ?? '' ), ' ' ) ) $products[ $id ]['phrase_hits']++;
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

    private static function products_for_vocabulary( $group, $slug, $limit ) {
        global $wpdb;
        $group = sanitize_key( $group );
        $slug = sanitize_title( $slug );
        if ( ! $group || ! $slug ) return array();
        $voc = $wpdb->prefix . 'seo_vocabulary';
        $rel = $wpdb->prefix . 'seo_object_vocabulary';
        if ( ! self::table_exists( $voc ) || ! self::table_exists( $rel ) ) return array();
        $vocabulary_id = absint( $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$voc} WHERE active=1 AND semantic_group=%s AND slug IN (%s,%s) ORDER BY id ASC LIMIT 1",
            $group, $slug, str_replace( '-', '_', $slug )
        ) ) );
        if ( ! $vocabulary_id ) return array();
        return array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT object_id FROM {$rel} WHERE object_type='product' AND status=1 AND vocabulary_id=%d ORDER BY object_id DESC LIMIT %d",
            $vocabulary_id, absint( $limit )
        ) ) );
    }

    private static function search_categories( $query, $limit ) {
        $out = array();
        $exact = get_term_by( 'name', trim( (string) $query ), 'product_cat' );
        if ( $exact instanceof WP_Term ) $out[] = array( 'id'=>$exact->term_id, 'title'=>$exact->name );
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

    private static function search_content( $query, $limit ) {
        $rows = get_posts( array( 'post_type'=>array( 'post','page' ), 'post_status'=>'publish', 's'=>sanitize_text_field( $query ), 'posts_per_page'=>absint( $limit ), 'no_found_rows'=>true ) );
        $out = array();
        foreach ( $rows as $post ) if ( $post instanceof WP_Post ) $out[] = array( 'id'=>$post->ID, 'title'=>$post->post_title );
        return $out;
    }

    private static function search_faqs( $query, $limit ) {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_faq';
        if ( ! self::table_exists( $table ) ) return array();
        $like = '%' . $wpdb->esc_like( trim( (string) $query ) ) . '%';
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id,question FROM {$table} WHERE active=1 AND question LIKE %s ORDER BY CASE WHEN question=%s THEN 0 ELSE 1 END,id ASC LIMIT %d",
            $like, trim( (string) $query ), absint( $limit )
        ), ARRAY_A );
        return array_map( static function ( $row ) { return array( 'id'=>absint( $row['id'] ?? 0 ), 'title'=>(string) ( $row['question'] ?? '' ) ); }, $rows );
    }

    public static function build_interpreter() {
        global $wpdb;
        $knowledge = TCD_Academy::knowledge_table();
        $rows = (array) $wpdb->get_results(
            "SELECT source_id,title,context_json FROM {$knowledge} WHERE lesson=9 AND status='learned' ORDER BY id ASC LIMIT 100",
            ARRAY_A
        );
        $tested = 0;
        $passed = 0;
        foreach ( $rows as $row ) {
            $facts = json_decode( (string) ( $row['context_json'] ?? '' ), true );
            $facts = is_array( $facts ) ? $facts : array();
            $query = trim( (string) ( $facts['customer_need'] ?? $row['title'] ?? '' ) );
            if ( ! $query ) continue;
            $tested++;
            $ids = array_map( static function ( $v ) { return absint( $v['id'] ?? 0 ); }, self::search_products( $query, 8 ) );
            if ( in_array( absint( $row['source_id'] ?? 0 ), $ids, true ) ) $passed++;
        }
        $ratio = $tested ? $passed / $tested : 0;
        $stats = TCD_Knowledge_Transfer::stats();
        $state = array(
            'status'=>( $tested && $ratio >= 0.88 ) ? 'ready' : 'degraded',
            'built_at'=>current_time( 'mysql' ),
            'tested'=>$tested,
            'passed'=>$passed,
            'pass_any_ratio'=>$ratio,
            'rules'=>absint( $stats['portable_rules'] ?? 0 ),
            'l9_memory'=>absint( $stats['l9_memory'] ?? 0 ),
            'version'=>TAXOCOMMERCE_DEPENDIENTE_VERSION,
        );
        update_option( self::INTERPRETER_STATE, $state, false );
        if ( 'ready' !== $state['status'] ) {
            return new WP_Error( 'tcd_interpreter_regression', sprintf( 'El Intérprete no supera la regresión: %.1f%% de %.1f%% mínimo.', 100 * $ratio, 88 ) );
        }
        return $state;
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
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE product_id=%d AND (source_brain_id LIKE %s OR source_brain_id LIKE %s)",
            $product_id, 'modern:%', 'maintenance:%'
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
        return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === (string) $table;
    }

    private static function shorten( $value, $length ) {
        $value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, absint( $length ), 'UTF-8' ) : substr( $value, 0, absint( $length ) );
    }
}
