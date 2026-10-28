<?php
/**
 * Classic, source-first hierarchy editor backed by wp_seo_relations.
 *
 * Mirrors the original TaxoCommerce SEO workflow: selected children at the
 * top, free children beneath, checkboxes, and one Save per parent.
 *
 * @package TaxoCommerceCore
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Core_Hierarchy_Classic {
    const TABLE_SUFFIX = 'seo_relations';
    const NODES_SUFFIX = 'seo_nodes';
    private static $map_cache = array();
    private static $schema_verified = false;

    public static function definitions() {
        return array(
            'cluster_to_primary' => array(
                'source_type' => 'cluster', 'target_type' => 'hub_primary',
                'source_role' => 'cluster', 'target_role' => 'hub_primary',
                'title' => 'Hubs primarios', 'meta' => TaxoCommerce_Core_Hierarchy::PARENT_CLUSTER_META,
            ),
            'hub_primary_to_hub_secondary' => array(
                'source_type' => 'hub_primary', 'target_type' => 'hub_secondary',
                'source_role' => 'hub_primary', 'target_role' => 'hub_secondary',
                'title' => 'Hubs secundarios', 'meta' => TaxoCommerce_Core_Hierarchy::PARENT_PRIMARY_META,
            ),
            'hub_secondary_to_category' => array(
                'source_type' => 'hub_secondary', 'target_type' => 'product_cat',
                'source_role' => 'hub_secondary', 'target_role' => 'category',
                'title' => 'Categorías de productos', 'meta' => TaxoCommerce_Core_Hierarchy::PARENT_SECONDARY_TERM_META,
            ),
            'hub_secondary_to_landing' => array(
                'source_type' => 'hub_secondary', 'target_type' => 'landing_page',
                'source_role' => 'hub_secondary', 'target_role' => 'landing',
                'title' => 'Landings', 'meta' => '',
            ),
            'hub_secondary_to_post' => array(
                'source_type' => 'hub_secondary', 'target_type' => 'post',
                'source_role' => 'hub_secondary', 'target_role' => 'post',
                'title' => 'Entradas / posts', 'meta' => '',
            ),
        );
    }

    private static function nodes_table() {
        global $wpdb;
        return $wpdb->prefix . self::NODES_SUFFIX;
    }

    public static function role_node( $page_id ) {
        global $wpdb;
        $table = self::nodes_table();
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT seo_role FROM {$table} WHERE object_type = %s AND object_id = %d AND status = 1 ORDER BY id DESC LIMIT 1",
            'page', absint( $page_id )
        ) );
        return sanitize_key( (string) $row );
    }

    public static function save_role_node( $page_id, $role ) {
        global $wpdb;
        $page_id = absint( $page_id );
        $role = sanitize_key( (string) $role );
        $table = self::nodes_table();
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE object_type = %s AND object_id = %d AND seo_role = %s LIMIT 1",
            'page', $page_id, $role
        ) );
        $now = current_time( 'mysql' );
        $wpdb->query( 'START TRANSACTION' );
        $disabled = $wpdb->update( $table, array( 'status' => 0, 'updated_at' => $now ), array( 'object_type' => 'page', 'object_id' => $page_id ) );
        $saved = true;
        if ( false !== $disabled && '' !== $role ) {
            if ( $existing ) {
                $saved = false !== $wpdb->update( $table, array( 'status' => 1, 'updated_at' => $now ), array( 'id' => absint( $existing ) ) );
            } else {
                $saved = false !== $wpdb->insert( $table, array(
                    'object_type' => 'page', 'object_id' => $page_id,
                    'seo_role' => $role, 'status' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ) );
            }
        }
        if ( false === $disabled || ! $saved ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'taxocommerce_core_node_save_failed', 'No se pudo guardar la clasificación en seo_nodes.' );
        }
        $wpdb->query( 'COMMIT' );
        return true;
    }

    /** Copy earlier Core meta-only classifications into seo_nodes once. */
    public static function reconcile_role_nodes( $hierarchy ) {
        if ( get_option( 'taxocommerce_core_nodes_bridge_v1', false ) ) {
            return;
        }
        $pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
            'meta_key' => TaxoCommerce_Core_Hierarchy::ROLE_META ) );
        foreach ( $pages as $id ) {
            $id = absint( $id );
            $role = sanitize_key( (string) get_post_meta( $id, TaxoCommerce_Core_Hierarchy::ROLE_META, true ) );
            if ( $role && isset( $hierarchy->roles()[ $role ] ) && ! self::role_node( $id ) ) {
                $saved = self::save_role_node( $id, $role );
                if ( is_wp_error( $saved ) ) {
                    return;
                }
            }
        }
        update_option( 'taxocommerce_core_nodes_bridge_v1', 1, false );
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function available() {
        global $wpdb;
        $table = self::table();
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $collate = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type VARCHAR(50) NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_id BIGINT UNSIGNED NOT NULL,
            relation_type VARCHAR(80) NOT NULL,
            country_code CHAR(2) NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY source_lookup (source_type,source_id),
            KEY target_lookup (target_type,target_id),
            KEY relation_type (relation_type),
            KEY country_relation (country_code,relation_type),
            KEY relation_endpoints (source_id,target_id)
        ) {$collate};" );
        $nodes = self::nodes_table();
        dbDelta( "CREATE TABLE {$nodes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            object_type VARCHAR(50) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL,
            seo_role VARCHAR(80) NOT NULL,
            keywords LONGTEXT NULL,
            title VARCHAR(255) NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_object_role (object_type,object_id,seo_role),
            KEY object_lookup (object_type,object_id),
            KEY seo_role (seo_role),
            KEY status (status)
        ) {$collate};" );
    }

    /** Upgrade existing installations without backfilling ambiguous relations. */
    public static function ensure_country_schema() {
        global $wpdb;
        if ( self::$schema_verified || ! self::available() ) { return; }
        $table = self::table();
        $found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'country_code' ) );
        if ( ! $found ) { $wpdb->query( "ALTER TABLE {$table} ADD COLUMN country_code CHAR(2) NULL DEFAULT NULL AFTER relation_type" ); }
        self::$schema_verified = true;
    }

    /** Relationship snapshots for the tree, editor and menu generator. */
    public static function rows( $type = '' ) {
        global $wpdb;
        if ( ! self::available() ) {
            return array();
        }
        $defs = self::definitions();
        if ( $type && ! isset( $defs[ $type ] ) ) {
            return array();
        }
        $table = self::table();
        $field = (string) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'country_code' ) ) === 'country_code' ? 'country_code' : 'NULL AS country_code';
        if ( $type ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT source_type,source_id,target_type,target_id,relation_type,{$field} FROM {$table} WHERE relation_type = %s", $type ),
                ARRAY_A
            );
        } else {
            $types = array_keys( $defs );
            $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Whitelisted SQL placeholders and known table name.
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT source_type,source_id,target_type,target_id,relation_type,{$field} FROM {$table} WHERE relation_type IN ({$placeholders})", $types ),
                ARRAY_A
            );
        }
        return is_array( $rows ) ? $rows : array();
    }

    /** Source => target IDs, used without querying per category. */
    public static function map( $type, $country = '' ) {
        $country = TaxoCommerce_Core_Country::normalize( $country );
        $key = $type . ':' . $country;
        if ( isset( self::$map_cache[ $key ] ) ) { return self::$map_cache[ $key ]; }
        $map = array();
        foreach ( self::rows( $type ) as $row ) {
            $source = absint( $row['source_id'] );
            $target = absint( $row['target_id'] );
            if ( $country && TaxoCommerce_Core_Country::relation( $row ) !== $country ) { continue; }
            if ( $source && $target ) { $map[ $source ][ $target ] = true; }
        }
        self::$map_cache[ $key ] = $map;
        return $map;
    }

    private static function valid_source( $hierarchy, $def, $source ) {
        return $source > 0 && $hierarchy->get_page_role( $source ) === $def['source_role'];
    }

    private static function valid_target( $hierarchy, $def, $id ) {
        if ( ! $id ) {
            return false;
        }
        if ( 'category' === $def['target_role'] ) {
            $term = get_term( $id, 'product_cat' );
            return $term instanceof WP_Term;
        }
        if ( 'post' === $def['target_role'] ) {
            return 'post' === get_post_type( $id );
        }
        return 'page' === get_post_type( $id ) && $hierarchy->get_page_role( $id ) === $def['target_role'];
    }

    /**
     * Save a parent's complete checkbox selection. This changes only its own
     * canonical hierarchy relationship type, never commercial relations.
     */
    public static function replace( $hierarchy, $type, $source, $ids, $country = '' ) {
        global $wpdb;
        $defs = self::definitions();
        if ( ! isset( $defs[ $type ] ) || ! self::available() ) {
            return new WP_Error( 'tc_relations_unavailable', 'No está disponible seo_relations o el tipo de relación es incorrecto.' );
        }
        $def = $defs[ $type ];
        $country = TaxoCommerce_Core_Country::normalize( $country );
        $source = absint( $source );
        if ( $country && TaxoCommerce_Core_Country::post( $source ) !== $country ) {
            return new WP_Error( 'tc_market_source', 'El origen pertenece a otro país.' );
        }
        if ( ! self::valid_source( $hierarchy, $def, $source ) ) {
            return new WP_Error( 'tc_relations_source', 'El origen no tiene el rol SEO requerido.' );
        }
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
        foreach ( $ids as $id ) {
            $target_country = 'category' === $def['target_role'] ? TaxoCommerce_Core_Country::term( $id ) : TaxoCommerce_Core_Country::post( $id );
            if ( $country && $target_country !== $country ) {
                return new WP_Error( 'tc_market_target', 'No se permiten relaciones entre países distintos.' );
            }
            if ( ! self::valid_target( $hierarchy, $def, $id ) ) {
                return new WP_Error( 'tc_relations_target', 'Hay una página, entrada o categoría no válida en la selección.' );
            }
        }

        $table = self::table();
        // An eligible structural target can have only one structural parent.
        foreach ( $ids as $id ) {
            $other = $wpdb->get_var( $wpdb->prepare(
                "SELECT source_id FROM {$table} WHERE relation_type = %s AND target_type = %s AND target_id = %d AND source_id <> %d LIMIT 1",
                $type, $def['target_type'], $id, $source
            ) );
            if ( $other ) {
                return new WP_Error( 'tc_relations_taken', sprintf( 'El elemento #%d ya pertenece a otro padre. Desvincúlalo primero.', $id ) );
            }
        }
        $old = array_keys( self::map( $type, $country )[ $source ] ?? array() );
        $to_add = array_diff( $ids, $old );
        $to_remove = array_diff( $old, $ids );

        $wpdb->query( 'START TRANSACTION' );
        $ok = true;
        foreach ( $to_remove as $id ) {
            if ( false === $wpdb->delete( $table, array(
                'source_type' => $def['source_type'], 'source_id' => $source,
                'target_type' => $def['target_type'], 'target_id' => $id, 'relation_type' => $type,
            ) ) ) {
                $ok = false;
                break;
            }
        }
        if ( $ok ) {
            foreach ( $to_add as $id ) {
                if ( false === $wpdb->insert( $table, array(
                    'source_type' => $def['source_type'], 'source_id' => $source,
                    'target_type' => $def['target_type'], 'target_id' => $id,
                    'relation_type' => $type, 'country_code' => $country ?: TaxoCommerce_Core_Country::post( $source ), 'created_at' => current_time( 'mysql' ),
                ) ) ) {
                    $ok = false;
                    break;
                }
            }
        }
        if ( $ok ) {
            $wpdb->query( 'COMMIT' );
        } else {
            $wpdb->query( 'ROLLBACK' );
        }
        if ( $ok ) {
            self::$map_cache = array();
        }
        if ( ! $ok ) {
            return new WP_Error( 'tc_relations_save_failed', 'No se pudieron guardar las relaciones. No se han aplicado cambios parciales.' );
        }
        // Retain compatibility with Core's existing metadata consumers.
        foreach ( $to_remove as $id ) {
            self::mirror_meta( $def, $source, $id, false );
        }
        foreach ( $to_add as $id ) {
            self::mirror_meta( $def, $source, $id, true );
        }
        return true;
    }

    public static function add( $hierarchy, $type, $source, $target, $country = '' ) {
        if ( 'primary_to_secondary' === $type ) {
            $type = 'hub_primary_to_hub_secondary';
        } elseif ( 'secondary_to_category' === $type ) {
            $type = 'hub_secondary_to_category';
        }
        $selected = array_keys( self::map( $type, $country )[ absint( $source ) ] ?? array() );
        $selected[] = absint( $target );
        return self::replace( $hierarchy, $type, $source, $selected, $country );
    }

    public static function remove( $hierarchy, $type, $source, $target, $country = '' ) {
        if ( 'primary_to_secondary' === $type ) {
            $type = 'hub_primary_to_hub_secondary';
        } elseif ( 'secondary_to_category' === $type ) {
            $type = 'hub_secondary_to_category';
        }
        $selected = array_keys( self::map( $type )[ absint( $source ) ] ?? array() );
        return self::replace( $hierarchy, $type, $source, array_diff( $selected, array( absint( $target ) ) ), $country );
    }

    private static function mirror_meta( $def, $source, $target, $add ) {
        if ( empty( $def['meta'] ) ) {
            return;
        }
        if ( 'product_cat' === $def['target_type'] ) {
            if ( $add ) {
                if ( ! in_array( $source, array_map( 'absint', get_term_meta( $target, $def['meta'], false ) ), true ) ) {
                    add_term_meta( $target, $def['meta'], $source );
                }
            } else {
                delete_term_meta( $target, $def['meta'], $source );
            }
        } elseif ( $add ) {
            if ( ! in_array( $source, array_map( 'absint', get_post_meta( $target, $def['meta'], false ) ), true ) ) {
                add_post_meta( $target, $def['meta'], $source );
            }
        } else {
            delete_post_meta( $target, $def['meta'], $source );
        }
    }

    /** Import native Core links ONLY when canonical relations are missing. */
    public static function reconcile_native( $hierarchy ) {
        if ( ! self::available() || get_option( 'taxocommerce_core_relations_native_bridge_v1', false ) ) {
            return;
        }
        $defs = self::definitions();
        foreach ( $defs as $type => $def ) {
            if ( ! $def['meta'] ) {
                continue;
            }
            if ( 'product_cat' === $def['target_type'] ) {
                $targets = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) );
                $targets = is_wp_error( $targets ) ? array() : $targets;
            } else {
                $targets = get_posts( array(
                    'post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
                    'meta_key' => TaxoCommerce_Core_Hierarchy::ROLE_META, 'meta_value' => $def['target_role'],
                ) );
            }
            $existing = self::map( $type );
            $already_taken = array();
            foreach ( $existing as $parents ) {
                foreach ( $parents as $assigned_id => $_ ) {
                    $already_taken[ absint( $assigned_id ) ] = true;
                }
            }
            foreach ( (array) $targets as $target ) {
                $target = absint( $target );
                $parents = 'product_cat' === $def['target_type']
                    ? get_term_meta( $target, $def['meta'], false ) : get_post_meta( $target, $def['meta'], false );
                foreach ( array_unique( array_filter( array_map( 'absint', (array) $parents ) ) ) as $source ) {
                    if ( ! isset( $already_taken[ $target ] ) && self::valid_source( $hierarchy, $def, $source ) ) {
                        global $wpdb;
                        $wpdb->insert( self::table(), array(
                            'source_type' => $def['source_type'], 'source_id' => $source,
                            'target_type' => $def['target_type'], 'target_id' => $target,
                            'relation_type' => $type, 'country_code' => TaxoCommerce_Core_Country::post( $source ), 'created_at' => current_time( 'mysql' ),
                        ) );
                        $existing[ $source ][ $target ] = true;
                        $already_taken[ $target ] = true;
                    }
                }
            }
        }
        self::$map_cache = array();
        update_option( 'taxocommerce_core_relations_native_bridge_v1', 1, false );
    }

    public static function role_pages( $role ) {
        global $wpdb;
        $nodes = self::nodes_table();
        $ids = array_map(
            'absint',
            (array) $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID FROM %i p
                     INNER JOIN %i n ON n.object_type = %s AND n.object_id = p.ID
                     WHERE p.post_type = %s AND p.post_status IN ('publish','draft','pending','private','future')
                       AND n.seo_role = %s AND n.status = 1 ORDER BY p.post_title ASC",
                    $wpdb->posts,
                    $nodes,
                    'page',
                    'page',
                    sanitize_key( $role )
                )
            )
        );
        if ( ! $ids ) {
            return array();
        }
        return get_posts( array(
            'post_type' => 'page', 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
            'posts_per_page' => -1, 'post__in' => $ids, 'orderby' => 'title', 'order' => 'ASC',
        ) );
    }

    public static function country_pages( $role, $country ) {
        return array_values( array_filter( self::role_pages( $role ), static function ( $p ) use ( $country ) {
            return TaxoCommerce_Core_Country::post( $p->ID ) === $country;
        } ) );
    }

    private static function candidates( $target_role, $country = '' ) {
        static $cached = array();
        $key = $target_role . ':' . $country;
        if ( isset( $cached[ $key ] ) ) { return $cached[ $key ]; }
        if ( 'category' === $target_role ) {
            $terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'number' => 40 ) );
            $cached[ $key ] = array_values( array_filter( is_wp_error( $terms ) ? array() : $terms, static function ( $term ) use ( $country ) { return ! $country || TaxoCommerce_Core_Country::term( $term->term_id ) === $country; } ) );
            return $cached[ $key ];
        }
        if ( 'post' === $target_role ) {
            $cached[ $key ] = get_posts( array(
                'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 80,
                'orderby' => 'title', 'order' => 'ASC',
            ) );
        } else {
            $cached[ $key ] = self::role_pages( $target_role );
        }
        return array_values( array_filter( $cached[ $key ], static function ( $p ) use ( $country ) { return ! $country || TaxoCommerce_Core_Country::post( $p->ID ) === $country; } ) );
    }

    /**
     * Display exactly the existing hierarchy, not three unrelated flat lists.
     * The editor keeps the original parent-first checkbox workflow, while
     * collapsed branches prevent large trees from overwhelming the screen.
     */
    public static function render( $hierarchy, $country = 'ES' ) {
        $country = TaxoCommerce_Core_Country::normalize( $country ) ?: 'ES';
        if ( ! self::available() ) {
            echo '<div class="notice notice-error"><p>Falta la tabla seo_relations. Activa de nuevo TaxoCommerce Core para crearla sin borrar datos.</p></div>';
            return;
        }

        echo '<style>
          .tc-classic-tree{max-width:1100px}
          .tc-classic-node{border:1px solid #dcdcde;background:#fff;border-radius:8px;margin:10px 0;min-width:0}
          .tc-classic-node[open]{border-color:#b8c2cf}
          .tc-classic-node summary{cursor:pointer;display:flex;align-items:center;gap:9px;padding:13px 16px;min-height:32px;list-style:none}
          .tc-classic-node summary::-webkit-details-marker{display:none}
          .tc-classic-node summary:before{content:"▸";color:#646970;flex:none;font-size:15px}
          .tc-classic-node[open]>summary:before{content:"▾"}
          .tc-classic-node summary:hover{background:#f6f7f7}
          .tc-classic-node summary:focus-visible{outline:2px solid #2271b1;outline-offset:-3px}
          .tc-classic-node__label{font-weight:650;font-size:14px;min-width:0;overflow-wrap:anywhere}
          .tc-classic-node__role{display:inline-block;font-size:11px;text-transform:uppercase;letter-spacing:.045em;color:#50575e;background:#f0f0f1;padding:4px 7px;border-radius:5px;white-space:nowrap}
          .tc-classic-node__count{margin-left:auto;color:#646970;font-size:12px;white-space:nowrap}
          .tc-classic-node__body{padding:0 16px 16px;border-top:1px solid #f0f0f1;min-width:0}
          .tc-classic-node__meta{font-size:12px;color:#646970;margin:11px 0}
          .tc-classic-node--child{margin:11px 0 0 17px;border-left:3px solid #ccd5df}
          .tc-classic-node--secondary{border-left-color:#b6cabb}
          .tc-classic-groups{display:grid;grid-template-columns:repeat(auto-fit,minmax(255px,1fr));gap:12px}
          .tc-classic-group{border:1px solid #e0e0e0;border-radius:7px;background:#fafafa;padding:12px;min-width:0}
          .tc-classic-group h4{margin:0 0 10px;font-size:13px;color:#1d2327}
          .tc-classic-selected,.tc-classic-available{background:#fff;border:1px solid #dcdcde;border-radius:5px;padding:7px;max-height:178px;min-height:42px;overflow-y:auto}
          .tc-classic-selected{border-color:#b8d1ba;background:#f4faf4;max-height:140px}
          .tc-classic-check{display:flex;align-items:flex-start;gap:7px;padding:5px 4px;font-size:13px;line-height:1.4;border-radius:4px}
          .tc-classic-check:hover{background:#f0f4f8}
          .tc-classic-check input{margin-top:2px;flex:none}
          .tc-classic-check small{color:#646970;margin-left:auto;padding-left:8px;white-space:nowrap}
          .tc-classic-search{width:100%;max-width:none;margin:8px 0;min-height:36px}
          .tc-classic-none{display:block;font-size:12px;color:#646970;padding:8px 4px}
          .tc-classic-group .button-primary{margin-top:12px;min-height:34px}
          .tc-classic-section-title{display:flex;align-items:center;gap:8px;margin:18px 0 10px;font-size:16px}
          .tc-classic-orphans{border-top:1px solid #dcdcde;padding-top:15px;margin-top:20px}
          .tc-classic-orphans>details{border:1px solid #dcdcde;border-radius:6px;margin-bottom:9px;background:#f6f7f7}
          .tc-classic-orphans>details>summary{padding:12px;cursor:pointer;font-weight:650}
          .tc-classic-orphans__body{padding:6px 12px 12px}
          .tc-classic-children{margin-left:18px;border-left:2px solid #dcdcde;padding-left:12px}
          @media(max-width:782px){
            .tc-classic-node--child{margin-left:6px}
            .tc-classic-node__body{padding:0 8px 12px}
            .tc-classic-node summary{flex-wrap:wrap;padding:11px 10px}
            .tc-classic-node__count{margin-left:0}
            .tc-classic-groups{grid-template-columns:minmax(0,1fr)}
          }
        </style>';

        $focus      = isset( $_GET['tc_focus'] ) ? absint( $_GET['tc_focus'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only focus.
        $defs       = self::definitions();
        $clusters   = self::country_pages( 'cluster', $country );
        $primaries  = self::country_pages( 'hub_primary', $country );
        $secondaries= self::country_pages( 'hub_secondary', $country );
        $cluster_map= self::map( 'cluster_to_primary', $country );
        $primary_map= self::map( 'hub_primary_to_hub_secondary', $country );
        $p_by_id    = array();
        $s_by_id    = array();
        foreach ( $primaries as $page ) {
            $p_by_id[ (int) $page->ID ] = $page;
        }
        foreach ( $secondaries as $page ) {
            $s_by_id[ (int) $page->ID ] = $page;
        }

        echo '<div class="tc-classic-tree">';
        if ( ! $clusters ) {
            echo '<p>No hay clusters clasificados. Ve a <strong>Asignación de roles</strong> para crear el primero.</p>';
        } else {
            foreach ( $clusters as $index => $cluster ) {
                $open_cluster = 0 === $index && 0 === $focus;
                if ( $focus ) {
                    $open_cluster = $focus === (int) $cluster->ID;
                    foreach ( array_keys( $cluster_map[ $cluster->ID ] ?? array() ) as $linked_primary ) {
                        $open_cluster = $open_cluster || $focus === (int) $linked_primary
                            || isset( $primary_map[ $linked_primary ][ $focus ] );
                    }
                }
                self::node_open( $cluster, 'Cluster', count( $cluster_map[ $cluster->ID ] ?? array() ), 'cluster', $open_cluster );
                self::render_group( $cluster, array( 'cluster_to_primary' ), $defs, $country );
                foreach ( array_keys( $cluster_map[ $cluster->ID ] ?? array() ) as $primary_id ) {
                    if ( ! isset( $p_by_id[ $primary_id ] ) ) {
                        continue;
                    }
                    $primary = $p_by_id[ $primary_id ];
                    self::node_open( $primary, 'Hub primario', count( $primary_map[ $primary_id ] ?? array() ), 'child',
                        $focus === (int) $primary_id || isset( $primary_map[ $primary_id ][ $focus ] ) );
                    self::render_group( $primary, array( 'hub_primary_to_hub_secondary' ), $defs, $country );
                    foreach ( array_keys( $primary_map[ $primary_id ] ?? array() ) as $secondary_id ) {
                        if ( ! isset( $s_by_id[ $secondary_id ] ) ) {
                            continue;
                        }
                        $secondary = $s_by_id[ $secondary_id ];
                        $links = self::secondary_link_count( $secondary_id, $country );
                        self::node_open( $secondary, 'Hub secundario', $links, 'secondary', $focus === (int) $secondary_id );
                        self::render_group( $secondary, array(
                            'hub_secondary_to_category', 'hub_secondary_to_landing', 'hub_secondary_to_post',
                        ), $defs, $country );
                        self::node_close();
                    }
                    self::node_close();
                }
                self::node_close();
            }
        }

        $assigned_primaries = array();
        foreach ( $cluster_map as $links ) {
            foreach ( $links as $id => $_ ) {
                $assigned_primaries[ (int) $id ] = true;
            }
        }
        $assigned_secondaries = array();
        foreach ( $primary_map as $links ) {
            foreach ( $links as $id => $_ ) {
                $assigned_secondaries[ (int) $id ] = true;
            }
        }
        $loose_primaries = array_filter( $primaries, static function ( $page ) use ( $assigned_primaries ) {
            return ! isset( $assigned_primaries[ (int) $page->ID ] );
        } );
        $loose_secondaries = array_filter( $secondaries, static function ( $page ) use ( $assigned_secondaries ) {
            return ! isset( $assigned_secondaries[ (int) $page->ID ] );
        } );

        if ( $loose_primaries || $loose_secondaries ) {
            echo '<div class="tc-classic-orphans"><h3 class="tc-classic-section-title">Elementos pendientes de vincular</h3>';
            if ( $loose_primaries ) {
                $orphan_primary_focused = false;
                foreach ( $loose_primaries as $loose_primary ) {
                    if ( $focus === (int) $loose_primary->ID ) {
                        $orphan_primary_focused = true;
                        break;
                    }
                }
                echo '<details' . ( $orphan_primary_focused ? ' open' : '' ) . '><summary>Hubs primarios sin cluster ('
 . esc_html( (string) count( $loose_primaries ) ) . ')</summary><div class="tc-classic-orphans__body">';
                foreach ( $loose_primaries as $primary ) {
                    self::node_open( $primary, 'Hub primario', count( $primary_map[ $primary->ID ] ?? array() ), 'child', $focus === (int) $primary->ID );
                    self::render_group( $primary, array( 'hub_primary_to_hub_secondary' ), $defs, $country );
                    self::node_close();
                }
                echo '</div></details>';
            }
            if ( $loose_secondaries ) {
                $orphan_secondary_focused = false;
                foreach ( $loose_secondaries as $loose_secondary ) {
                    if ( $focus === (int) $loose_secondary->ID ) {
                        $orphan_secondary_focused = true;
                        break;
                    }
                }
                echo '<details' . ( $orphan_secondary_focused ? ' open' : '' ) . '><summary>Hubs secundarios sin hub primario ('
 . esc_html( (string) count( $loose_secondaries ) ) . ')</summary><div class="tc-classic-orphans__body">';
                foreach ( $loose_secondaries as $secondary ) {
                    self::node_open( $secondary, 'Hub secundario', self::secondary_link_count( $secondary->ID, $country ), 'secondary', $focus === (int) $secondary->ID );
                    self::render_group( $secondary, array(
                        'hub_secondary_to_category', 'hub_secondary_to_landing', 'hub_secondary_to_post',
                    ), $defs, $country );
                    self::node_close();
                }
                echo '</div></details>';
            }
            echo '</div>';
        }
        echo '</div>';

        self::enqueue_search_script();
    }

    private static function secondary_link_count( $secondary_id, $country = '' ) {
        $count = 0;
        foreach ( array( 'hub_secondary_to_category', 'hub_secondary_to_landing', 'hub_secondary_to_post' ) as $type ) {
            $count += count( self::map( $type, $country )[ $secondary_id ] ?? array() );
        }
        return $count;
    }

    private static function node_open( $page, $role, $count, $level, $open ) {
        echo '<details id="tc-source-' . esc_attr( (string) $page->ID ) . '" class="tc-classic-node tc-classic-node--' . esc_attr( $level ) . '"' . ( $open ? ' open' : '' ) . '>';
        echo '<summary><span class="tc-classic-node__role">' . esc_html( $role ) . '</span>';
        echo '<span class="tc-classic-node__label">' . esc_html( $page->post_title ) . '</span>';
        echo '<span class="tc-classic-node__count">' . esc_html( (string) $count ) . ' relaciones</span></summary>';
        echo '<div class="tc-classic-node__body"><div class="tc-classic-node__meta">ID '
            . esc_html( (string) $page->ID ) . ' · ' . esc_html( strtoupper( $page->post_status ) )
            . ' · Plantilla: ' . esc_html( (string) ( get_page_template_slug( $page->ID ) ?: 'predeterminada' ) ) . '</div>';
    }

    private static function render_group( $parent, $types, $definitions, $country = 'ES' ) {
        echo '<div class="tc-classic-groups">';
        foreach ( $types as $type ) {
            self::render_checkboxes( $parent, $type, $definitions[ $type ], $country );
        }
        echo '</div>';
    }

    private static function node_close() {
        echo '</div></details>';
    }

    private static function target_name( $object ) {
        return $object instanceof WP_Term ? (string) $object->name : (string) $object->post_title;
    }

    private static function target_id( $object ) {
        return $object instanceof WP_Term ? absint( $object->term_id ) : absint( $object->ID );
    }

    private static function check( $name, $id, $checked ) {
        echo '<label class="tc-classic-check"><input type="checkbox" name="targets[]" value="' . esc_attr( (string) $id ) . '" ' . checked( $checked, true, false ) . '> ' . esc_html( $name ) . ' <small>#' . esc_html( (string) $id ) . '</small></label>';
    }

    private static function render_checkboxes( $parent, $type, $def, $country = 'ES' ) {
        $map = self::map( $type, $country );
        $selected = array_keys( $map[ $parent->ID ] ?? array() );
        $taken = array();
        foreach ( self::map( $type ) as $source => $items ) {
            if ( (int) $source !== (int) $parent->ID ) {
                foreach ( $items as $target => $_ ) {
                    $taken[ (int) $target ] = true;
                }
            }
        }
        echo '<div class="tc-classic-group"><h4>' . esc_html( $def['title'] ) . '</h4>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="taxocommerce_core_replace_hierarchy_relations">';
        echo '<input type="hidden" name="source_id" value="' . esc_attr( (string) $parent->ID ) . '">';
        echo '<input type="hidden" name="relation_type" value="' . esc_attr( $type ) . '">';
        echo '<input type="hidden" name="tc_country" value="' . esc_attr( $country ) . '">';
        wp_nonce_field( 'taxocommerce_core_replace_hierarchy_' . $type . '_' . $parent->ID, 'taxocommerce_core_nonce' );
        echo '<strong>Asignados (' . esc_html( (string) count( $selected ) ) . ')</strong>';
        echo '<div class="tc-classic-selected">';
        foreach ( $selected as $id ) {
            $object = 'category' === $def['target_role'] ? get_term( $id, 'product_cat' ) : get_post( $id );
            if ( $object instanceof WP_Term || $object instanceof WP_Post ) {
                self::check( self::target_name( $object ), $id, true );
            } else {
                // Keep an orphan relation selected until the admin chooses to unlink it.
                self::check( 'Elemento no disponible', $id, true );
            }
        }
        if ( ! $selected ) {
            echo '<span class="tc-classic-none">Sin elementos asignados.</span>';
        }
        echo '</div><strong>Disponibles</strong>';
        echo '<input class="tc-classic-search" type="search" placeholder="Buscar por nombre o ID…" aria-label="Buscar elementos disponibles"';
        if ( 'category' === $def['target_role'] ) {
            echo ' data-tc-search="categories" data-tc-source="' . esc_attr( (string) $parent->ID ) . '"';
        } else {
            echo ' data-tc-search="local"';
        }
        echo '>';
        echo '<div class="tc-classic-available">';
        foreach ( self::candidates( $def['target_role'], $country ) as $candidate ) {
            $id = self::target_id( $candidate );
            if ( in_array( $id, $selected, true ) || isset( $taken[ $id ] ) ) {
                continue;
            }
            self::check( self::target_name( $candidate ), $id, false );
        }
        echo '</div>';
        if ( 'category' === $def['target_role'] ) {
            echo '<p class="description">Se muestran 40 categorías iniciales. Escribe para buscar cualquier categoría del catálogo sin cargar miles de opciones.</p>';
        }
        echo '<button class="button button-primary" type="submit">Guardar asignaciones</button>';
        echo '</form></div>';
    }

    public static function handle_search( $hierarchy ) {
        if ( ! current_user_can( apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' ) ) ) {
            wp_send_json_error( array( 'message' => 'Acceso denegado.' ), 403 );
        }
        check_ajax_referer( 'taxocommerce_core_search_categories', 'nonce' );
        $query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        $source = isset( $_GET['source'] ) ? absint( $_GET['source'] ) : 0;
        if ( ! $source || 'hub_secondary' !== $hierarchy->get_page_role( $source ) || strlen( $query ) < 2 ) {
            wp_send_json_success( array() );
        }
        $terms = get_terms( array(
            'taxonomy' => 'product_cat', 'hide_empty' => false,
            'search' => $query, 'orderby' => 'name', 'number' => 50,
        ) );
        $country = isset( $_GET['tc_country'] ) && is_string( $_GET['tc_country'] ) ? TaxoCommerce_Core_Country::normalize( wp_unslash( $_GET['tc_country'] ) ) : 'ES';
        $map = self::map( 'hub_secondary_to_category', $country );
        $taken = array();
        foreach ( $map as $parent => $items ) {
            if ( (int) $parent !== $source ) {
                foreach ( $items as $id => $_ ) {
                    $taken[ (int) $id ] = true;
                }
            }
        }
        $result = array();
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                if ( TaxoCommerce_Core_Country::term( $term->term_id ) === $country && ! isset( $taken[ (int) $term->term_id ] ) ) {
                    $result[] = array( 'id' => (int) $term->term_id, 'name' => (string) $term->name );
                }
            }
        }
        wp_send_json_success( $result );
    }

    private static function enqueue_search_script() {
        wp_register_script( 'taxocommerce-core-classic-search', false, array(), TAXOCOMMERCE_CORE_VERSION, true );
        wp_enqueue_script( 'taxocommerce-core-classic-search' );
        $config = wp_json_encode( array(
            'url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'taxocommerce_core_search_categories' ),
        ) );
        wp_add_inline_script( 'taxocommerce-core-classic-search', '(() => {
          const config = ' . $config . ';
          document.querySelectorAll(".tc-classic-search").forEach(input => {
            const list=input.nextElementSibling;
            if (!list) return;
            let timer=null;
            input.addEventListener("input", () => {
              const q=input.value.toLocaleLowerCase().trim();
              if(input.dataset.tcSearch==="local"){
                list.querySelectorAll(".tc-classic-check").forEach(item=>{
                  item.hidden=!item.textContent.toLocaleLowerCase().includes(q);
                });
                return;
              }
              if(q.length<2) return;
              clearTimeout(timer);
              timer=setTimeout(()=>{
                const args=new URLSearchParams({action:"taxocommerce_core_search_hierarchy_categories",nonce:config.nonce,
                  q:q,source:input.dataset.tcSource,tc_country:input.closest("form").querySelector("input[name=tc_country]").value});
                fetch(config.url+"?"+args,{credentials:"same-origin"}).then(r=>r.json()).then(json=>{
                  if(!json.success || input.value.toLocaleLowerCase().trim()!==q) return;
                  const present=new Set(Array.from(input.closest("form").querySelectorAll("input[name=\'targets[]\']")).map(x=>x.value));
                  (json.data||[]).forEach(item=>{
                    const id=String(item.id);
                    if(present.has(id)) return;
                    present.add(id);
                    const label=document.createElement("label");label.className="tc-classic-check";
                    const checkbox=document.createElement("input");checkbox.type="checkbox";checkbox.name="targets[]";checkbox.value=id;
                    label.append(checkbox,document.createTextNode(" "+item.name+" #"+id));
                    list.append(label);
                  });
                }).catch(()=>{});
              },200);
            });
          });
        })();' );
    }

    public static function render_tree( $hierarchy, $country = 'ES' ) {
        $country = TaxoCommerce_Core_Country::normalize( $country ) ?: 'ES';
        $maps = array();
        foreach ( array_keys( self::definitions() ) as $type ) {
            $maps[ $type ] = self::map( $type, $country );
        }
        $clusters = self::country_pages( 'cluster', $country );
        if ( ! $clusters ) {
            echo '<p>Sin clusters clasificados todavía.</p>';
            return;
        }
        echo '<div style="max-width:1120px">';
        foreach ( $clusters as $cluster ) {
            echo '<div class="card" style="max-width:none"><strong>' . esc_html( $cluster->post_title ) . '</strong>';
            foreach ( array_keys( $maps['cluster_to_primary'][ $cluster->ID ] ?? array() ) as $primary_id ) {
                $primary = get_post( $primary_id );
                if ( ! $primary ) continue;
                echo '<div class="tc-classic-children"><strong>' . esc_html( $primary->post_title ) . '</strong>';
                foreach ( array_keys( $maps['hub_primary_to_hub_secondary'][ $primary_id ] ?? array() ) as $secondary_id ) {
                    $secondary = get_post( $secondary_id );
                    if ( ! $secondary ) continue;
                    echo '<div class="tc-classic-children"><strong>' . esc_html( $secondary->post_title ) . '</strong><ul>';
                    foreach ( array( 'hub_secondary_to_category', 'hub_secondary_to_landing', 'hub_secondary_to_post' ) as $type ) {
                        foreach ( array_keys( $maps[ $type ][ $secondary_id ] ?? array() ) as $target ) {
                            $obj = 'hub_secondary_to_category' === $type ? get_term( $target, 'product_cat' ) : get_post( $target );
                            if ( $obj instanceof WP_Term || $obj instanceof WP_Post ) {
                                echo '<li>' . esc_html( self::target_name( $obj ) ) . '</li>';
                            }
                        }
                    }
                    echo '</ul></div>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}
