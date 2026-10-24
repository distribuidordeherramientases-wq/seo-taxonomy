<?php
/**
 * Read-only database discovery, snapshots and operational event metadata.
 * All SQL identifiers originate in the current WordPress database inventory.
 */
defined( 'ABSPATH' ) || exit;

final class TCDB_Storage {
    private static $table_cache = null;

    const AUDIT_OPTION   = 'tcdb_last_audit_v1';
    const HISTORY_OPTION = 'tcdb_audit_history_v1';
    const EVENT_OPTION   = 'tcdb_operations_v1';

    public static function authorize( $nonce_action, $nonce_field = '_wpnonce' ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para gestionar la base de datos.', 'taxocommerce-database' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( $nonce_action, $nonce_field );
    }

    public static function url( $tab = 'mysql', $args = array() ) {
        return add_query_arg(
            array_merge( array( 'page' => 'taxocommerce-database', 'tcdb_tab' => $tab ), $args ),
            admin_url( 'admin.php' )
        );
    }

    public static function redirect( $tab, $notice = '' ) {
        $args = $notice ? array( 'tcdb_notice' => sanitize_key( $notice ) ) : array();
        wp_safe_redirect( self::url( $tab, $args ) );
        exit;
    }

    /**
     * Safe identifier syntax. Table names are ALWAYS cross-checked against
     * actual site tables before they enter any SQL identifier position.
     */
    public static function identifier( $name ) {
        return is_string( $name ) && (bool) preg_match( '/^[A-Za-z0-9_]+$/D', $name );
    }

    public static function family_table_name( $table ) {
        global $wpdb;
        if ( ! self::identifier( $table ) || 0 !== strpos( $table, $wpdb->prefix ) ) {
            return false;
        }
        $short = substr( $table, strlen( $wpdb->prefix ) );
        return (bool) preg_match( '/^(seo_|tcd_|tcv_|tcf_|tct_|tgb_|tci_|tcr_|tca_|taxocommerce_)/i', $short );
    }

    private static function current_site_name( $table ) {
        global $wpdb;
        if ( ! self::identifier( $table ) || 0 !== strpos( $table, $wpdb->prefix ) ) {
            return false;
        }
        // On multisite main site, do not expose tables for other blog IDs.
        if ( isset( $wpdb->blogid, $wpdb->base_prefix ) && 1 === (int) $wpdb->blogid && $wpdb->prefix === $wpdb->base_prefix ) {
            $relative = substr( $table, strlen( $wpdb->prefix ) );
            if ( preg_match( '/^[0-9]+_/', $relative ) ) {
                return false;
            }
        }
        return true;
    }

    /** A single metadata query, never SELECT COUNT(*) on every table. */
    public static function tables() {
        if ( is_array( self::$table_cache ) ) {
            return self::$table_cache;
        }
        global $wpdb;
        $like = $wpdb->esc_like( $wpdb->prefix ) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE, TABLE_COLLATION
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s AND TABLE_TYPE = 'BASE TABLE'
                  ORDER BY TABLE_NAME ASC',
                $like
            ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) {
            self::$table_cache = array();
            return array();
        }

        $tables = array();
        foreach ( $rows as $row ) {
            $name = isset( $row['TABLE_NAME'] ) ? (string) $row['TABLE_NAME'] : '';
            if ( ! self::current_site_name( $name ) ) {
                continue;
            }
            $tables[ $name ] = array(
                'name'        => $name,
                'family'      => self::family_table_name( $name ),
                'rows'        => max( 0, (int) ( $row['TABLE_ROWS'] ?? 0 ) ),
                'data'        => max( 0, (int) ( $row['DATA_LENGTH'] ?? 0 ) ),
                'indexes'     => max( 0, (int) ( $row['INDEX_LENGTH'] ?? 0 ) ),
                'free'        => max( 0, (int) ( $row['DATA_FREE'] ?? 0 ) ),
                'engine'      => (string) ( $row['ENGINE'] ?? '' ),
                'collation'   => (string) ( $row['TABLE_COLLATION'] ?? '' ),
            );
        }
        self::$table_cache = $tables;
        return $tables;
    }

    public static function table_allowed( $name, $family_only = true ) {
        $tables = self::tables();
        return is_string( $name ) && isset( $tables[ $name ] ) && ( ! $family_only || $tables[ $name ]['family'] );
    }

    public static function columns( $table ) {
        if ( ! self::table_allowed( $table, false ) ) {
            return array();
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier was verified against the current database inventory.
        $rows = $wpdb->get_results( "SHOW COLUMNS FROM \`{$table}\`", ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    public static function primary_key( $table ) {
        foreach ( self::columns( $table ) as $column ) {
            if ( 'PRI' === (string) ( $column['Key'] ?? '' ) ) {
                return (string) $column['Field'];
            }
        }
        return '';
    }

    /** Manual server and SEO integrity audit. It does not modify any tables. */
    public static function audit() {
        global $wpdb;
        $tables = self::tables();
        $global = array();
        foreach ( (array) $wpdb->get_results(
            "SHOW GLOBAL STATUS WHERE Variable_name IN (
             'Threads_connected','Max_used_connections','Aborted_connects',
             'Created_tmp_disk_tables','Created_tmp_tables','Slow_queries',
             'Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests',
             'Select_full_join','Sort_merge_passes')",
            ARRAY_A
        ) as $row ) {
            if ( isset( $row['Variable_name'] ) ) {
                $global[ $row['Variable_name'] ] = is_numeric( $row['Value'] ?? null ) ? (float) $row['Value'] : null;
            }
        }

        $version = (string) $wpdb->get_var( 'SELECT VERSION()' );
        $total = 0;
        $large = array();
        $no_primary = array();
        foreach ( $tables as $meta ) {
            $size = $meta['data'] + $meta['indexes'];
            $total += $size;
            if ( $size >= 5 * 1024 * 1024 ) {
                $large[] = array( 'table' => $meta['name'], 'bytes' => $size );
            }
        }
        usort( $large, static function ( $a, $b ) { return $b['bytes'] <=> $a['bytes']; } );
        $large = array_slice( $large, 0, 12 );

        // Index inventory for plugin tables only, limited to small metadata.
        $index_rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT TABLE_NAME, INDEX_NAME
                   FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE %s AND INDEX_NAME='PRIMARY'
                  GROUP BY TABLE_NAME,INDEX_NAME",
                $wpdb->esc_like( $wpdb->prefix ) . '%'
            ),
            ARRAY_A
        );
        $has_primary = array();
        foreach ( $index_rows as $index ) {
            $has_primary[ (string) ( $index['TABLE_NAME'] ?? '' ) ] = true;
        }
        foreach ( $tables as $meta ) {
            if ( $meta['family'] && ! isset( $has_primary[ $meta['name'] ] ) && $meta['rows'] > 1000 ) {
                $no_primary[] = $meta['name'];
            }
        }

        $integrity = array();
        $nodes = $wpdb->prefix . 'seo_nodes';
        if ( isset( $tables[ $nodes ] ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is computed from current-prefix table list.
            $integrity['orphan_page_nodes'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM \`{$nodes}\` n
                  LEFT JOIN {$wpdb->posts} p ON p.ID=n.object_id
                 WHERE n.object_type='page' AND p.ID IS NULL"
            );
        }
        $relations = $wpdb->prefix . 'seo_relations';
        if ( isset( $tables[ $relations ] ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only an existing Core relation table is addressed.
            $integrity['duplicate_relations'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM (
                    SELECT source_type,source_id,target_type,target_id,relation_type
                    FROM \`{$relations}\`
                    GROUP BY source_type,source_id,target_type,target_id,relation_type
                    HAVING COUNT(*) > 1
                ) d"
            );
        }
        $scheduler = $wpdb->prefix . 'actionscheduler_actions';
        if ( isset( $tables[ $scheduler ] ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Inventory-validated native Action Scheduler table.
            $integrity['as_overdue_pending'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM \`{$scheduler}\`
                 WHERE status='pending' AND scheduled_date_gmt < UTC_TIMESTAMP()"
            );
        }

        return array(
            'at'         => current_time( 'mysql' ),
            'version'    => $version,
            'tables'     => count( $tables ),
            'family'     => count( array_filter( $tables, static function ( $row ) { return $row['family']; } ) ),
            'bytes'      => $total,
            'status'     => $global,
            'large'      => $large,
            'no_primary' => $no_primary,
            'integrity'  => $integrity,
            'note'       => 'Las estadísticas GLOBAL STATUS corresponden a todo MySQL, no exclusivamente a WordPress. TABLE_ROWS puede ser una estimación.',
        );
    }

    public static function handle_audit() {
        self::authorize( 'tcdb_audit' );
        $audit = self::audit();
        update_option( self::AUDIT_OPTION, $audit, false );
        $history = get_option( self::HISTORY_OPTION, array() );
        $history = is_array( $history ) ? $history : array();
        array_unshift( $history, array(
            'at' => $audit['at'], 'tables' => $audit['tables'], 'family' => $audit['family'],
            'bytes' => $audit['bytes'], 'as_overdue_pending' => $audit['integrity']['as_overdue_pending'] ?? null,
        ) );
        update_option( self::HISTORY_OPTION, array_slice( $history, 0, 20 ), false );
        self::log( 'audit', array( 'tables' => $audit['tables'] ) );
        self::redirect( 'mysql', 'audit_ok' );
    }

    public static function log( $action, $details = array() ) {
        $events = get_option( self::EVENT_OPTION, array() );
        $events = is_array( $events ) ? $events : array();
        array_unshift( $events, array(
            'at' => current_time( 'mysql' ),
            'action' => sanitize_key( $action ),
            'user' => (int) get_current_user_id(),
            'details' => $details,
        ) );
        update_option( self::EVENT_OPTION, array_slice( $events, 0, 40 ), false );
    }
}
