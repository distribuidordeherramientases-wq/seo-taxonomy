<?php
/**
 * Market: category-first evidence collection and storage.
 *
 * Standalone by design. It never requires TaxoCommerce Core or the legacy
 * Ojeador/Comparador database. No network traffic while rendering screens.
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_Market {
    const SCHEMA_VERSION = 1;
    const BATCH_SIZE = 150;
    const DAILY_SCAN_LIMIT = 10;
    const SCHEMA_OPTION = 'tgb_market_schema_version';
    const CURSOR_OPTION = 'tgb_market_category_cursor';
    const BOOTSTRAP_OPTION = 'tgb_market_bootstrap';
    const QUEUE_OPTION = 'tgb_market_queue_active';
    const BUDGET_OPTION = 'tgb_market_daily_budget';

    public static function tables() {
        global $wpdb;
        return array(
            'categories' => $wpdb->prefix . 'tgb_market_categories',
            'sources'    => $wpdb->prefix . 'tgb_market_sources',
            'runs'       => $wpdb->prefix . 'tgb_market_runs',
        );
    }

    public static function activate() {
        self::install();
        update_option( self::BOOTSTRAP_OPTION, 1, false );
    }

    public static function deactivate() {
        update_option( self::QUEUE_OPTION, 0, false );
        wp_clear_scheduled_hook( 'tgb_market_queue_tick' );
    }

    public static function register() {
        add_action( 'admin_init', array( __CLASS__, 'maybe_bootstrap' ), 30 );
        add_action( 'admin_post_tgb_market_sync', array( __CLASS__, 'handle_sync' ) );
        add_action( 'admin_post_tgb_market_scan', array( __CLASS__, 'handle_scan' ) );
        add_action( 'admin_post_tgb_market_queue_start', array( __CLASS__, 'handle_queue_start' ) );
        add_action( 'admin_post_tgb_market_queue_stop', array( __CLASS__, 'handle_queue_stop' ) );
        add_action( 'admin_post_tgb_market_mapping', array( __CLASS__, 'handle_mapping' ) );
        add_action( 'admin_post_tgb_market_export', array( __CLASS__, 'handle_export' ) );
        add_action( 'created_product_cat', array( __CLASS__, 'sync_term' ), 10, 1 );
        add_action( 'edited_product_cat', array( __CLASS__, 'sync_term' ), 10, 1 );
        add_action( 'delete_product_cat', array( __CLASS__, 'archive_term' ), 10, 1 );
        add_action( 'tgb_market_queue_tick', array( __CLASS__, 'queue_tick' ) );
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::tables();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$t['categories']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            term_id bigint(20) unsigned NOT NULL,
            name varchar(255) NOT NULL,
            slug varchar(200) NOT NULL,
            parent_term_id bigint(20) unsigned NOT NULL DEFAULT 0,
            country char(2) NOT NULL DEFAULT '',
            locale varchar(12) NOT NULL DEFAULT '',
            google_category_id bigint(20) unsigned NOT NULL DEFAULT 0,
            scan_status varchar(24) NOT NULL DEFAULT 'pending',
            last_scanned_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY term_id (term_id),
            KEY scan_status (scan_status)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$t['sources']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            category_id bigint(20) unsigned NOT NULL,
            source_type varchar(30) NOT NULL,
            provider varchar(30) NOT NULL,
            source_hash char(64) NOT NULL,
            source_url text NOT NULL,
            title varchar(500) NOT NULL,
            snippet text NULL,
            evidence_text longtext NULL,
            evidence_level varchar(24) NOT NULL DEFAULT 'discovered',
            metadata_json longtext NULL,
            observed_at datetime NULL,
            collected_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY category_source (category_id,source_hash),
            KEY category_type (category_id,source_type),
            KEY collected_at (collected_at)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$t['runs']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            category_id bigint(20) unsigned NOT NULL,
            run_status varchar(24) NOT NULL,
            trigger_source varchar(16) NOT NULL,
            collected_count int(10) unsigned NOT NULL DEFAULT 0,
            provider_report longtext NULL,
            error_message text NULL,
            started_at datetime NOT NULL,
            finished_at datetime NULL,
            PRIMARY KEY  (id),
            KEY category_started (category_id,started_at)
        ) $charset;" );
        foreach ( $t as $table ) {
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( $found !== $table ) {
                return false;
            }
        }
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
        return true;
    }

    public static function maybe_bootstrap() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( (int) get_option( self::SCHEMA_OPTION, 0 ) !== self::SCHEMA_VERSION ) {
            if ( ! self::install() ) {
                return;
            }
            // Also run after automatic plugin upgrades, when activation does not fire.
            update_option( self::BOOTSTRAP_OPTION, 1, false );
        }
        self::purge_expired_youtube_data();
        if ( get_option( self::BOOTSTRAP_OPTION, 0 ) && taxonomy_exists( 'product_cat' ) ) {
            $result = self::sync_categories_batch();
            if ( ! is_wp_error( $result ) && ! empty( $result['complete'] ) ) {
                update_option( self::BOOTSTRAP_OPTION, 0, false );
            }
        }
    }

    /**
     * YouTube API-derived data must be refreshed or removed within 30 days.
     * Retain only the latest 28 days, purge on an infrequent local admin load.
     */
    private static function purge_expired_youtube_data() {
        global $wpdb;
        $last = absint( get_option( 'tgb_market_last_youtube_purge', 0 ) );
        if ( time() - $last < DAY_IN_SECONDS ) {
            return;
        }
        $t = self::tables();
        $expiry = gmdate( 'Y-m-d H:i:s', time() - 28 * DAY_IN_SECONDS );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$t['sources']} WHERE provider = %s AND updated_at < %s",
            'youtube', $expiry
        ) );
        update_option( 'tgb_market_last_youtube_purge', time(), false );
    }

    private static function get_locale_country( $term_id ) {
        $country = '';
        $locale = '';
        $walker = absint( $term_id );
        $visited = array();
        while ( $walker && ! isset( $visited[ $walker ] ) && count( $visited ) < 15 ) {
            $visited[ $walker ] = true;
            if ( '' === $country ) {
                $candidate = strtoupper( trim( (string) get_term_meta( $walker, '_seo_pais_origen', true ) ) );
                if ( preg_match( '/^[A-Z]{2}$/', $candidate ) ) {
                    $country = $candidate;
                }
            }
            if ( '' === $locale ) {
                $candidate = trim( (string) get_term_meta( $walker, '_seo_locale', true ) );
                if ( preg_match( '/^[a-z]{2}[-_][A-Za-z]{2}$/', $candidate ) ) {
                    $locale = str_replace( '_', '-', $candidate );
                }
            }
            $parent = get_term( $walker, 'product_cat' );
            $walker = ( $parent && ! is_wp_error( $parent ) ) ? absint( $parent->parent ) : 0;
        }
        if ( '' === $locale ) {
            // Prefer explicit term locale. For unambiguous pilot countries,
            // use a country-appropriate search language rather than ES site locale.
            $country_locales = array(
                'ES' => 'es-ES', 'FR' => 'fr-FR', 'DE' => 'de-DE',
                'NL' => 'nl-NL', 'US' => 'en-US',
            );
            $locale = $country && isset( $country_locales[ $country ] )
                ? $country_locales[ $country ] : str_replace( '_', '-', get_locale() );
        }
        if ( '' === $country && preg_match( '/-([A-Za-z]{2})$/', $locale, $matches ) ) {
            $country = strtoupper( $matches[1] );
        }
        if ( '' === $country ) {
            $store = strtoupper( (string) get_option( 'woocommerce_default_country', 'ES' ) );
            $country = preg_match( '/^[A-Z]{2}/', $store, $matches ) ? $matches[0] : 'ES';
        }
        return array( $country, $locale );
    }

    public static function sync_term( $term_id ) {
        global $wpdb;
        if ( (int) get_option( self::SCHEMA_OPTION, 0 ) !== self::SCHEMA_VERSION ) {
            return;
        }
        $term = get_term( absint( $term_id ), 'product_cat' );
        if ( ! $term || is_wp_error( $term ) || 'product_cat' !== $term->taxonomy ) {
            return;
        }
        list( $country, $locale ) = self::get_locale_country( $term->term_id );
        $now = current_time( 'mysql', true );
        $data = array(
            'name'           => sanitize_text_field( $term->name ),
            'slug'           => sanitize_title( $term->slug ),
            'parent_term_id' => absint( $term->parent ),
            'country'        => $country,
            'locale'         => $locale,
            'updated_at'     => $now,
        );
        $t = self::tables();
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id,name,slug,parent_term_id,country,locale,scan_status FROM {$t['categories']} WHERE term_id = %d",
            absint( $term_id )
        ), ARRAY_A );
        if ( $existing ) {
            $changed = false;
            foreach ( array( 'name', 'slug', 'parent_term_id', 'country', 'locale' ) as $field ) {
                if ( (string) $data[ $field ] !== (string) $existing[ $field ] ) {
                    $changed = true;
                    break;
                }
            }
            $id = absint( $existing['id'] );
            $data['scan_status'] = ( $changed || 'archived' === $existing['scan_status'] )
                ? 'pending' : $existing['scan_status'];
            $wpdb->update( $t['categories'], $data, array( 'id' => $id ) );
            if ( $changed ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$t['sources']} SET evidence_level = 'stale' WHERE category_id = %d", $id
                ) );
            }
        } else {
            $data['term_id']    = absint( $term_id );
            $data['created_at'] = $now;
            $data['scan_status'] = 'pending';
            $wpdb->insert( $t['categories'], $data );
        }
    }

    public static function archive_term( $term_id ) {
        global $wpdb;
        if ( (int) get_option( self::SCHEMA_OPTION, 0 ) !== self::SCHEMA_VERSION ) {
            return;
        }
        $t = self::tables();
        $wpdb->update(
            $t['categories'],
            array( 'scan_status' => 'archived', 'updated_at' => current_time( 'mysql', true ) ),
            array( 'term_id' => absint( $term_id ) )
        );
    }

    public static function sync_categories_batch() {
        if ( ! taxonomy_exists( 'product_cat' ) ) {
            return new WP_Error( 'market_no_taxonomy', 'WooCommerce is not active: product_cat does not exist.' );
        }
        $offset = absint( get_option( self::CURSOR_OPTION, 0 ) );
        $terms = get_terms( array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'fields' => 'ids',
            'orderby' => 'id',
            'order' => 'ASC',
            'number' => self::BATCH_SIZE,
            'offset' => $offset,
        ) );
        if ( is_wp_error( $terms ) ) {
            return $terms;
        }
        foreach ( $terms as $term_id ) {
            self::sync_term( $term_id );
        }
        $complete = count( $terms ) < self::BATCH_SIZE;
        update_option( self::CURSOR_OPTION, $complete ? 0 : $offset + count( $terms ), false );
        if ( $complete ) {
            update_option( self::BOOTSTRAP_OPTION, 0, false );
            update_option( 'tgb_market_last_full_sync', time(), false );
        }
        return array( 'processed' => count( $terms ), 'complete' => $complete, 'offset' => $offset );
    }

    private static function category( $id ) {
        global $wpdb;
        $t = self::tables();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t['categories']} WHERE id = %d", absint( $id )
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    private static function today_budget( $consume = false ) {
        $today = gmdate( 'Y-m-d' );
        $stored = get_option( self::BUDGET_OPTION, array() );
        $spent = is_array( $stored ) && ( $stored['day'] ?? '' ) === $today
            ? absint( $stored['spent'] ?? 0 ) : 0;
        if ( $consume && $spent < self::DAILY_SCAN_LIMIT ) {
            ++$spent;
            update_option( self::BUDGET_OPTION, array( 'day' => $today, 'spent' => $spent ), false );
        }
        return max( 0, self::DAILY_SCAN_LIMIT - $spent );
    }

    public static function save_source( $category_id, $source ) {
        global $wpdb;
        $t = self::tables();
        $provider = sanitize_key( (string) ( $source['provider'] ?? '' ) );
        $kind = sanitize_key( (string) ( $source['type'] ?? '' ) );
        $url = esc_url_raw( (string) ( $source['url'] ?? '' ) );
        $key = sanitize_text_field( (string) ( $source['key'] ?? $url ) );
        if ( '' === $provider || '' === $kind || '' === $key ) {
            return false;
        }
        if ( '' !== $url && ! preg_match( '~^https://~i', $url ) ) {
            return false;
        }
        $hash = hash( 'sha256', $provider . '|' . $kind . '|' . $key );
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$t['sources']} WHERE category_id = %d AND source_hash = %s",
            absint( $category_id ), $hash
        ) );
        $now = current_time( 'mysql', true );
        $data = array(
            'source_type'   => $kind,
            'provider'      => $provider,
            'source_hash'   => $hash,
            'source_url'    => $url,
            'title'         => mb_substr( sanitize_text_field( (string) ( $source['title'] ?? '' ) ), 0, 500 ),
            'snippet'       => sanitize_textarea_field( (string) ( $source['snippet'] ?? '' ) ),
            'evidence_text' => sanitize_textarea_field( (string) ( $source['evidence'] ?? '' ) ),
            'evidence_level'=> sanitize_key( (string) ( $source['level'] ?? 'discovered' ) ),
            'metadata_json' => wp_json_encode( (array) ( $source['metadata'] ?? array() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'observed_at'   => ! empty( $source['observed_at'] ) ? gmdate( 'Y-m-d H:i:s', absint( $source['observed_at'] ) ) : null,
            'updated_at'    => $now,
        );
        if ( $existing ) {
            return false !== $wpdb->update( $t['sources'], $data, array( 'id' => absint( $existing ) ) );
        }
        $data['category_id'] = absint( $category_id );
        $data['collected_at'] = $now;
        return false !== $wpdb->insert( $t['sources'], $data );
    }

    public static function scan_category( $id, $trigger = 'manual' ) {
        global $wpdb;
        $cat = self::category( $id );
        if ( ! $cat || 'archived' === $cat['scan_status'] ) {
            return new WP_Error( 'market_no_category', 'Category does not exist or is archived.' );
        }
        if ( ! class_exists( 'TaxoCommerce_Google_Bing_Market_Sources' ) ) {
            return new WP_Error( 'market_missing_sources', 'Market collector is missing.' );
        }
        // An absent provider is not a fatal error: collect() records every
        // optional provider as skipped and runs whichever connection is usable.
        if ( 0 === self::today_budget() ) {
            return new WP_Error( 'market_daily_budget', 'Daily safety limit of 10 externally queried categories reached.' );
        }
        $lock = 'tgb_market_scan_lock_' . absint( $id );
        if ( get_transient( $lock ) ) {
            return new WP_Error( 'market_locked', 'This category is already being analyzed.' );
        }
        set_transient( $lock, 1, 4 * MINUTE_IN_SECONDS );
        $t = self::tables();
        $wpdb->insert( $t['runs'], array(
            'category_id' => absint( $id ),
            'run_status' => 'running',
            'trigger_source' => sanitize_key( $trigger ),
            'started_at' => current_time( 'mysql', true ),
        ) );
        $run_id = absint( $wpdb->insert_id );
        self::today_budget( true );
        try {
            $result = TaxoCommerce_Google_Bing_Market_Sources::collect( $cat );
            $created = 0;
            foreach ( $result['items'] as $source ) {
                if ( self::save_source( $id, $source ) ) {
                    ++$created;
                }
            }
            $problems = (array) ( $result['errors'] ?? array() );
            $status = $problems ? ( $created ? 'partial' : 'error' ) : ( $created ? 'complete' : 'empty' );
            $wpdb->update( $t['runs'], array(
                'run_status' => $status,
                'collected_count' => $created,
                'provider_report' => wp_json_encode( $result['providers'] ),
                'error_message' => implode( ' | ', array_map( 'sanitize_text_field', $problems ) ),
                'finished_at' => current_time( 'mysql', true ),
            ), array( 'id' => $run_id ) );
            $wpdb->update( $t['categories'], array(
                'scan_status' => $status,
                'last_scanned_at' => current_time( 'mysql', true ),
            ), array( 'id' => absint( $id ) ) );
            return array( 'status' => $status, 'received' => $created, 'errors' => $problems );
        } finally {
            delete_transient( $lock );
        }
    }

    private static function next_pending_category() {
        global $wpdb;
        $t = self::tables();
        return (int) $wpdb->get_var(
            "SELECT id FROM {$t['categories']} WHERE scan_status = 'pending' ORDER BY id ASC LIMIT 1"
        );
    }

    private static function queue_schedule( $delay = 90 ) {
        if ( ! wp_next_scheduled( 'tgb_market_queue_tick' ) ) {
            wp_schedule_single_event( time() + absint( $delay ), 'tgb_market_queue_tick' );
        }
    }

    public static function queue_tick() {
        if ( ! get_option( self::QUEUE_OPTION, 0 ) ) {
            return;
        }
        if ( ! self::today_budget() ) {
            self::queue_schedule( DAY_IN_SECONDS );
            return;
        }
        $id = self::next_pending_category();
        if ( ! $id ) {
            update_option( self::QUEUE_OPTION, 0, false );
            return;
        }
        $result = self::scan_category( $id, 'queue' );
        if ( is_wp_error( $result ) ) {
            if ( 'market_daily_budget' === $result->get_error_code() ) {
                self::queue_schedule( DAY_IN_SECONDS );
                return;
            }
            // Avoid a failing category blocking the queue forever.
            global $wpdb;
            $t = self::tables();
            $wpdb->update( $t['categories'], array( 'scan_status' => 'error' ), array( 'id' => $id ) );
        }
        if ( get_option( self::QUEUE_OPTION, 0 ) ) {
            self::queue_schedule( 120 );
        }
    }

    private static function require_admin( $nonce ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Acceso no autorizado.', 'taxocommerce-google-bing' ) );
        }
        check_admin_referer( $nonce );
    }

    private static function redirect( $message, $error = false, $term_id = 0 ) {
        set_transient( 'tgb_market_notice_' . get_current_user_id(), array(
            'message' => sanitize_text_field( $message ),
            'error' => (bool) $error,
        ), 120 );
        $args = array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'mercado' );
        if ( $term_id ) {
            $args['market_term'] = absint( $term_id );
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_sync() {
        self::require_admin( 'tgb_market_sync' );
        $result = self::sync_categories_batch();
        self::redirect( is_wp_error( $result ) ? $result->get_error_message()
            : sprintf( '%d categories reviewed; %s.', $result['processed'],
                $result['complete'] ? 'catalog synchronized' : 'more batches remain' ), is_wp_error( $result ) );
    }

    public static function handle_mapping() {
        self::require_admin( 'tgb_market_mapping' );
        global $wpdb;
        $id = absint( $_POST['market_id'] ?? 0 );
        $cat = self::category( $id );
        if ( ! $cat ) {
            self::redirect( 'Category not found.', true );
        }
        $value = absint( $_POST['google_category_id'] ?? 0 );
        $t = self::tables();
        if ( $value !== absint( $cat['google_category_id'] ) ) {
            $wpdb->update( $t['categories'],
                array( 'google_category_id' => $value, 'scan_status' => 'pending' ),
                array( 'id' => $id ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$t['sources']} SET evidence_level = 'stale' WHERE category_id = %d AND provider = %s",
                $id, 'merchant'
            ) );
        }
        self::redirect( 'Google category saved. The mapping must be verified against the official taxonomy.', false, $id );
    }

    public static function handle_scan() {
        self::require_admin( 'tgb_market_scan' );
        $id = absint( $_POST['market_id'] ?? 0 );
        $result = self::scan_category( $id );
        self::redirect( is_wp_error( $result ) ? $result->get_error_message()
            : 'Escaneo finalizado: ' . $result['status'] . ' · ' . $result['received'] . ' evidencias guardadas o actualizadas.',
            is_wp_error( $result ), $id );
    }

    public static function handle_queue_start() {
        self::require_admin( 'tgb_market_queue_start' );
        // Queued categories can be processed without optional provider keys.
        update_option( self::QUEUE_OPTION, 1, false );
        self::queue_schedule( 60 );
        self::redirect( 'Market queue started. It processes one category per WP-Cron run, with a maximum of 10 categories per day.' );
    }

    public static function handle_queue_stop() {
        self::require_admin( 'tgb_market_queue_stop' );
        update_option( self::QUEUE_OPTION, 0, false );
        wp_clear_scheduled_hook( 'tgb_market_queue_tick' );
        self::redirect( 'Cola detenida sin borrar resultados.' );
    }

    public static function handle_export() {
        self::require_admin( 'tgb_market_export' );
        global $wpdb;
        $id = absint( $_POST['market_id'] ?? 0 );
        $cat = self::category( $id );
        if ( ! $cat ) {
            wp_die( 'Category not found.' );
        }
        $t = self::tables();
        $sources = $wpdb->get_results( $wpdb->prepare(
            "SELECT provider,source_type,source_url,title,snippet,evidence_text,evidence_level,metadata_json,observed_at,collected_at FROM {$t['sources']} WHERE category_id = %d ORDER BY id DESC LIMIT 1000", $id
        ), ARRAY_A );
        $runs = $wpdb->get_results( $wpdb->prepare(
            "SELECT run_status,trigger_source,collected_count,provider_report,error_message,started_at,finished_at FROM {$t['runs']} WHERE category_id = %d ORDER BY id DESC LIMIT 30", $id
        ), ARRAY_A );
        foreach ( $sources as &$source ) {
            $source['metadata'] = json_decode( (string) $source['metadata_json'], true );
            unset( $source['metadata_json'] );
        }
        unset( $source );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="market_category_' . $cat['term_id'] . '.json"' );
        echo wp_json_encode( array(
            'schema' => 'tgb-market-category-v1',
            'exported_at_utc' => gmdate( 'c' ),
            'category' => $cat,
            'sources' => $sources,
            'runs' => $runs,
            'purpose' => 'Evidence dossier for editorial review; it is not a published post.',
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Explicit JSON download.
        exit;
    }

    private static function action_form( $action, $nonce, $label, $fields = array(), $primary = false ) {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:3px 4px 3px 0">';
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
        foreach ( $fields as $field => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $field ) . '" value="' . esc_attr( (string) $value ) . '">';
        }
        wp_nonce_field( $nonce );
        echo '<button class="button ' . ( $primary ? 'button-primary' : '' ) . '" type="submit">' . esc_html( $label ) . '</button></form>';
    }

    public static function render() {
        global $wpdb;
        $t = self::tables();
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['categories']} WHERE scan_status != 'archived'" );
        $complete = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['categories']} WHERE scan_status = 'complete'" );
        $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['categories']} WHERE scan_status = 'pending'" );
        $evidence = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['sources']}" );
        $notice = get_transient( 'tgb_market_notice_' . get_current_user_id() );
        if ( is_array( $notice ) ) {
            echo '<div class="notice ' . ( ! empty( $notice['error'] ) ? 'notice-error' : 'notice-success' ) . ' inline"><p>' . esc_html( $notice['message'] ) . '</p></div>';
            delete_transient( 'tgb_market_notice_' . get_current_user_id() );
        }
        echo '<h2>Market · Category Research</h2>';
        echo '<p>Works with real WooCommerce categories without depending on SKU, EAN, or GTIN. Results are stored only in plugin-owned tables and do not create drafts or publications.</p>';
        echo '<p><strong>Categories:</strong> ' . esc_html( $total )
            . ' · <strong>Analizadas:</strong> ' . esc_html( $complete )
            . ' · <strong>Pending:</strong> ' . esc_html( $pending )
            . ' · <strong>Sources guardadas:</strong> ' . esc_html( $evidence )
            . ' · <strong>Queries allowed today:</strong> ' . esc_html( self::today_budget() ) . '</p>';
        if ( ! taxonomy_exists( 'product_cat' ) ) {
            echo '<div class="notice notice-warning inline"><p>The product_cat taxonomy does not exist: install or activate WooCommerce to discover categories.</p></div>';
        }
        self::action_form( 'tgb_market_sync', 'tgb_market_sync', 'Sync next category batch', array(), true );
        if ( get_option( self::QUEUE_OPTION, 0 ) ) {
            self::action_form( 'tgb_market_queue_stop', 'tgb_market_queue_stop', 'Stop category analysis' );
        } else {
            self::action_form( 'tgb_market_queue_start', 'tgb_market_queue_start', 'Analyze queued pending categories' );
        }
        echo '<p class="description">Local synchronization reviews ' . esc_html( self::BATCH_SIZE ) . ' categories per batch; after installation it continues from the admin. The queue performs at most 10 external scans per day and requires site traffic or server cron to progress. Opening this screen never queries external APIs.</p>';

        $youtube = '' !== (string) get_option( 'tgb_market_youtube_key', '' );
        $serpapi = '' !== (string) get_option( 'tgb_market_serpapi_key', '' );
        $merchant = TaxoCommerce_Google_Bing_Connections::merchant_account();
        $google = TaxoCommerce_Google_Bing_Market_Sources::google_available();
        echo '<h3>Research sources</h3><p>Google Search Console: ' . esc_html( $google ? 'configured' : 'no configured access' ) . ' · YouTube: ' . esc_html( $youtube ? 'configured' : 'no API key' )
            . ' · SerpApi (web and offers): ' . esc_html( $serpapi ? 'configured' : 'no API key' )
            . ' · Merchant: ' . esc_html( $merchant ? 'ID configured; requires authorization and a Google category' : 'no account' ) . '.</p>';
        $connections_url = add_query_arg( array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'connections' ), admin_url( 'admin.php' ) ) . '#tgb-market-providers';
        echo '<p><a class="button" href="' . esc_url( $connections_url ) . '">Configure providers in Connections</a></p>';
        echo '<p class="description">YouTube and SerpApi are optional. YouTube requires its own Google API key; SerpApi is an external provider. No APIs are queried when opening this screen.</p>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only listing pagination.
        $page = max( 1, absint( $_GET['market_page'] ?? 1 ) );
        $offset = ( $page - 1 ) * 40;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id,term_id,name,country,locale,google_category_id,scan_status,last_scanned_at FROM {$t['categories']} WHERE scan_status != 'archived' ORDER BY id ASC LIMIT 40 OFFSET %d",
            $offset
        ), ARRAY_A );
        echo '<h3>WooCommerce Categories</h3><div style="overflow-x:auto"><table class="widefat striped">';
        echo '<thead><tr><th>Term ID</th><th>Category</th><th>Country / language</th><th>Google Category ID</th><th>Status</th><th>Last search</th><th>Actions</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $link = add_query_arg( array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'mercado', 'market_term' => $row['id'] ), admin_url( 'admin.php' ) );
            echo '<tr><td>' . esc_html( $row['term_id'] ) . '</td><td><a href="' . esc_url( $link ) . '">' . esc_html( $row['name'] ) . '</a></td><td>'
                . esc_html( $row['country'] . ' / ' . $row['locale'] ) . '</td><td>' . esc_html( $row['google_category_id'] ?: 'Sin asignar' )
                . '</td><td>' . esc_html( $row['scan_status'] ) . '</td><td>' . esc_html( $row['last_scanned_at'] ?: '-' ) . '</td><td>';
            self::action_form( 'tgb_market_scan', 'tgb_market_scan', 'Analizar', array( 'market_id' => $row['id'] ) );
            echo ' <a class="button" href="' . esc_url( $link ) . '">Sources</a></td></tr>';
        }
        if ( ! $rows ) {
            echo '<tr><td colspan="7">No categories are registered. Use category synchronization.</td></tr>';
        }
        echo '</tbody></table></div>';
        $pages = (int) ceil( $total / 40 );
        if ( $pages > 1 ) {
            echo '<p>Page ' . esc_html( $page ) . ' / ' . esc_html( $pages ) . ' ';
            foreach ( array( 'Previous' => max( 1, $page - 1 ), 'Next' => min( $pages, $page + 1 ) ) as $label => $target ) {
                $link = add_query_arg( array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'mercado', 'market_page' => $target ), admin_url( 'admin.php' ) );
                echo '<a class="button" href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a> ';
            }
            echo '</p>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only category dossier selector.
        $selected = absint( $_GET['market_term'] ?? 0 );
        if ( $selected ) {
            self::render_dossier( $selected );
        }
    }

    private static function render_dossier( $id ) {
        global $wpdb;
        $cat = self::category( $id );
        if ( ! $cat ) {
            return;
        }
        $t = self::tables();
        $sources = $wpdb->get_results( $wpdb->prepare(
            "SELECT provider,source_type,source_url,title,snippet,evidence_text,evidence_level,metadata_json,collected_at FROM {$t['sources']} WHERE category_id = %d ORDER BY id DESC LIMIT 100", $id
        ), ARRAY_A );
        $runs = $wpdb->get_results( $wpdb->prepare(
            "SELECT run_status,collected_count,provider_report,error_message,started_at FROM {$t['runs']} WHERE category_id = %d ORDER BY id DESC LIMIT 5", $id
        ), ARRAY_A );
        echo '<hr><h2>Expediente: ' . esc_html( $cat['name'] ) . '</h2>';
        echo '<p>Country ' . esc_html( $cat['country'] ) . ', language ' . esc_html( $cat['locale'] ) . '. Links to discovered pages do not equal verified reviews; Merchant shows popularity signals, not individual competitor prices.</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tgb_market_mapping"><input type="hidden" name="market_id" value="' . esc_attr( $id ) . '">';
        wp_nonce_field( 'tgb_market_mapping' );
        echo '<label><strong>Official Google category ID:</strong> <input type="number" min="0" name="google_category_id" value="' . esc_attr( $cat['google_category_id'] ) . '"></label>';
        echo ' <button type="submit" class="button">Save mapping</button></form>';
        echo '<p class="description">The mapping must be verified before using Merchant Best Sellers. It is not inferred from title similarity.</p>';
        self::action_form( 'tgb_market_scan', 'tgb_market_scan', 'Analyze category again', array( 'market_id' => $id ), true );
        self::action_form( 'tgb_market_export', 'tgb_market_export', 'Export dossier JSON', array( 'market_id' => $id ) );
        echo '<h3>Latest scans</h3>';
        foreach ( $runs as $run ) {
            echo '<p>' . esc_html( $run['started_at'] . ' · ' . $run['run_status'] . ' · ' . $run['collected_count'] . ' sources' );
            if ( $run['error_message'] ) {
                echo '<br><span class="description">' . esc_html( $run['error_message'] ) . '</span>';
            }
            echo '</p>';
        }
        echo '<h3>Sources obtenidas (' . esc_html( count( $sources ) ) . ' mostradas)</h3>';
        echo '<div style="overflow-x:auto"><table class="widefat striped"><thead><tr><th>Type / provider</th><th>Source</th><th>Excerpt</th><th>Status</th><th>Collected</th></tr></thead><tbody>';
        foreach ( $sources as $row ) {
            echo '<tr><td>' . esc_html( $row['source_type'] . ' / ' . $row['provider'] ) . '</td><td>';
            if ( $row['source_url'] ) {
                echo '<a href="' . esc_url( $row['source_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $row['title'] ?: $row['source_url'] ) . '</a>';
            } else {
                echo esc_html( $row['title'] );
            }
            echo '</td><td>' . esc_html( mb_substr( (string) ( $row['evidence_text'] ?: $row['snippet'] ), 0, 350 ) ) . '</td><td>'
                . esc_html( $row['evidence_level'] ) . '</td><td>' . esc_html( $row['collected_at'] ) . '</td></tr>';
        }
        if ( ! $sources ) {
            echo '<tr><td colspan="5">No evidence. Configure sources and analyze the category.</td></tr>';
        }
        echo '</tbody></table></div><p class="description">Export intended for the future Editor. Market does not create publications or automatically copy reviews into WordPress.</p>';
    }
}
