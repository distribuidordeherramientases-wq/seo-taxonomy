<?php

defined( 'ABSPATH' ) || exit;

final class TCV_Classifier {
    const VERSION = '0.3.9';
    const GROUPS = array( 'tipo', 'rol', 'aplicacion', 'plataforma', 'subtipo' );

    private static function normalize( $text ) {
        $text = html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
        $text = wp_strip_all_tags( $text );
        $text = remove_accents( mb_strtolower( $text, 'UTF-8' ) );
        $text = preg_replace( '/[^a-z0-9º°+\/._-]+/u', ' ', $text );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    /**
     * Optional read-only access to the original canonical supplier catalogue.
     * Fresh WooCommerce stores work without the table or Suppliers plugin.
     */
    private static function supplier_source_context( $product_id ) {
        global $wpdb;
        static $table_exists = null;
        static $rows = array();

        $product_id = absint( $product_id );
        if ( array_key_exists( $product_id, $rows ) ) {
            return $rows[ $product_id ];
        }
        $table = $wpdb->prefix . 'seo_proveedores_productos';
        // WordPress migrations may still hold the canonical supplier table.
        // Live direct reads are necessary; this module never writes to it.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( null === $table_exists ) {
            $found = $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
            );
            $table_exists = $found === $table;
        }
        if ( ! $table_exists ) {
            return $rows[ $product_id ] = '';
        }
        $reference = absint( get_post_meta( $product_id, '_seo_proveedor_catalogo_id', true ) );
        if ( $reference ) {
            $row = $wpdb->get_row(
                $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d LIMIT 1', $table, $reference ),
                ARRAY_A
            );
        } else {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE object_id=%d ORDER BY id DESC LIMIT 1',
                    $table,
                    $product_id
                ),
                ARRAY_A
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( ! is_array( $row ) ) {
            return $rows[ $product_id ] = '';
        }
        $parts = array();
        foreach ( array( 'nombre', 'descripcion', 'categoria_proveedor', 'marca',
            'fabricante', 'mpn', 'sku' ) as $field ) {
            if ( isset( $row[ $field ] ) && is_scalar( $row[ $field ] ) ) {
                $parts[] = (string) $row[ $field ];
            }
        }
        return $rows[ $product_id ] = implode( ' ', $parts );
    }

    private static function product_context( $product_id ) {
        $post = get_post( $product_id );
        if ( ! $post || 'product' !== $post->post_type ) {
            return new WP_Error( 'tcv_product_missing', __( 'Product not found.', 'taxocommerce-vocabulary' ) );
        }
        $cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
        if ( is_wp_error( $cats ) ) {
            $cats = array();
        }
        $tags = array();
        if ( taxonomy_exists( 'product_tag' ) ) {
            $tags = wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'names' ) );
            if ( is_wp_error( $tags ) ) {
                $tags = array();
            }
        }
        $brands = array();
        // Use installed WordPress/WooCommerce brand taxonomies, without a
        // dependency on a brand or supplier plugin.
        foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'pa_marca', 'pa_brand' ) as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $values = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $values ) ) {
                $brands = array_merge( $brands, (array) $values );
            }
        }
        foreach ( array( '_brand', '_manufacturer', '_seo_fabricante' ) as $key ) {
            $value = get_post_meta( $product_id, $key, true );
            if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
                $brands[] = (string) $value;
            }
        }
        $supplier = array();
        foreach ( array( '_supplier_name', '_supplier_description', '_supplier_category',
            '_seo_proveedor', '_seo_marca_proveedor' ) as $key ) {
            $value = get_post_meta( $product_id, $key, true );
            if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
                $supplier[] = (string) $value;
            }
        }
        $supplier[] = self::supplier_source_context( $product_id );
        $values = array(
            'title' => $post->post_title,
            'excerpt' => $post->post_excerpt,
            'content' => $post->post_content,
            'categories' => implode( ' ', $cats ),
            'tags' => implode( ' ', $tags ),
            'brands' => implode( ' ', array_unique( $brands ) ),
            'sku' => (string) get_post_meta( $product_id, '_sku', true ),
            'supplier' => implode( ' ', $supplier ),
        );
        // Optional read-only integration for any supplier/catalog provider;
        // no vendor, plugin, or external service is required.
        $values = apply_filters( 'taxocommerce_vocabulary_classifier_sources', $values, $product_id );
        return TCV_Extractor::context( is_array( $values ) ? $values : array() );
    }

    // Classifier must read and update shared canonical vocabulary and proposal tables immediately; external writers cannot invalidate a local cache.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function current_groups( $product_id ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.semantic_group,v.id,v.label FROM %i ov INNER JOIN %i v ON v.id=ov.vocabulary_id WHERE ov.object_type='product' AND ov.object_id=%d AND ov.status=1 AND v.active=1", $t['objects'], $t['vocabulary'],
                $product_id
            ),
            ARRAY_A
        );
        $out = array_fill_keys( self::GROUPS, array() );
        foreach ( (array) $rows as $row ) {
            $g = sanitize_key( $row['semantic_group'] );
            if ( isset( $out[ $g ] ) ) {
                $out[ $g ][] = absint( $row['id'] );
            }
        }
        return $out;
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function score_term( $term, $ctx ) {
        $matched = TCV_Extractor::score( $term, $ctx );
        return (float) $matched['score'];
    }

    // Classifier must read and update shared canonical vocabulary and proposal tables immediately; external writers cannot invalidate a local cache.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function vocabulary_candidates( $group, $ctx, $limit = 4 ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,slug,label FROM %i WHERE semantic_group=%s AND active=1 ORDER BY label ASC", $t['vocabulary'],
                $group
            ),
            ARRAY_A
        );
        return TCV_Extractor::rank( $rows, $ctx, $limit );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    // Classifier must read and update shared canonical vocabulary and proposal tables immediately; external writers cannot invalidate a local cache.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function derive_role( $type_id ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT rv.id,rv.label FROM %i m INNER JOIN %i rv ON rv.id=m.role_vocabulary_id AND rv.semantic_group='rol' AND rv.active=1 WHERE m.type_vocabulary_id=%d AND m.active=1 LIMIT 1", $t['type_role'], $t['vocabulary'],
                $type_id
            ),
            ARRAY_A
        );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    // Classifier must read and update shared canonical vocabulary and proposal tables immediately; external writers cannot invalidate a local cache.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function attribute_candidates( $ctx ) {
        global $wpdb;
        $tables = TCV_Schema::tables();
        // Canonical, local tables: a live read is required to see the
        // vocabulary updated by other ordinary WordPress administrators.
        $attrs = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id,slug,nombre,tipo,unidad_base FROM %i WHERE activo=1 ORDER BY orden,nombre',
                $tables['attributes']
            ),
            ARRAY_A
        );
        $terms = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id,atributo_id,slug,nombre FROM %i WHERE activo=1',
                $tables['terms']
            ),
            ARRAY_A
        );
        $aliases = $wpdb->get_results(
            $wpdb->prepare( 'SELECT atributo_id,termino_id,alias FROM %i', $tables['aliases'] ),
            ARRAY_A
        );
        $term_map = array();
        $alias_map = array();
        foreach ( (array) $terms as $term ) {
            $term_map[ absint( $term['atributo_id'] ) ][] = array(
                'id' => absint( $term['id'] ),
                'label' => (string) $term['nombre'],
            );
        }
        foreach ( (array) $aliases as $alias ) {
            $id = absint( $alias['atributo_id'] );
            $term_id = absint( $alias['termino_id'] );
            if ( $id && $term_id && '' !== trim( (string) $alias['alias'] ) ) {
                $alias_map[ $id ][ $term_id ][] = (string) $alias['alias'];
            }
        }
        $found = array();
        foreach ( (array) $attrs as $attribute ) {
            $id = absint( $attribute['id'] );
            if ( 'termino' === $attribute['tipo'] ) {
                $proposal = TCV_Extractor::controlled(
                    $attribute,
                    isset( $term_map[ $id ] ) ? $term_map[ $id ] : array(),
                    isset( $alias_map[ $id ] ) ? $alias_map[ $id ] : array(),
                    $ctx
                );
            } elseif ( in_array( $attribute['tipo'], array( 'numero', 'rango' ), true ) ) {
                $proposal = TCV_Extractor::numeric( $attribute, $ctx );
            } else {
                $proposal = null;
            }
            if ( $proposal ) {
                $found[] = $proposal;
            }
        }
        return array_slice( $found, 0, 20 );
    }


    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    public static function analyze_product( $product_id ) {
        $product_id = absint( $product_id );
        $ctx = self::product_context( $product_id );
        if ( is_wp_error( $ctx ) ) {
            return $ctx;
        }
        $current = self::current_groups( $product_id );
        $result = array( 'product_id' => $product_id, 'labels' => array(), 'attributes' => array() );
        $groups = array( 'tipo', 'aplicacion', 'plataforma', 'subtipo' );
        $type_choice = null;
        foreach ( $groups as $group ) {
            if ( ! empty( $current[ $group ] ) ) {
                continue;
            }
            $candidates = self::vocabulary_candidates( $group, $ctx );
            if ( ! $candidates || (float) $candidates[0]['score'] < 0.52 ) {
                // Legacy discovery is review-only. It does not insert terms
                // into WooCommerce or the canonical vocabulary.
                $new_term = TCV_Extractor::discover( $group, $ctx, $candidates );
                if ( $new_term ) {
                    $result['labels'][] = $new_term;
                    self::persist_proposal( $product_id, $group, $new_term );
                }
                continue;
            }
            $top = $candidates[0];
            $second = isset( $candidates[1] ) ? (float) $candidates[1]['score'] : 0.0;
            $state = ( $top['score'] >= 0.82 && ( $top['score'] - $second ) >= 0.12 ) ? 'safe' : 'review';
            $proposal = array( 'group' => $group, 'vocabulary_id' => absint( $top['id'] ), 'label' => $top['label'], 'confidence' => (float) $top['score'], 'state' => $state, 'evidence' => $candidates );
            $result['labels'][] = $proposal;
            self::persist_proposal( $product_id, $group, $proposal );
            if ( 'tipo' === $group ) {
                $type_choice = $proposal;
            }
        }
        if ( empty( $current['rol'] ) && $type_choice ) {
            $role = self::derive_role( $type_choice['vocabulary_id'] );
            if ( $role ) {
                $proposal = array( 'group' => 'rol', 'vocabulary_id' => absint( $role['id'] ), 'label' => $role['label'], 'confidence' => $type_choice['confidence'], 'state' => 'derived', 'evidence' => array( 'derived_from_type' => $type_choice['label'] ) );
                $result['labels'][] = $proposal;
                self::persist_proposal( $product_id, 'rol', $proposal );
            }
        }

        $result['attributes'] = self::attribute_candidates( $ctx );
        foreach ( $result['attributes'] as $proposal ) {
            self::persist_proposal( $product_id, 'attribute:' . absint( $proposal['attribute_id'] ), array(
                'group' => 'attribute', 'vocabulary_id' => null, 'label' => $proposal['attribute'] . ': ' . $proposal['value'], 'confidence' => $proposal['confidence'], 'state' => 'review', 'evidence' => $proposal,
            ) );
        }
        return $result;
    }

    // Classifier must read and update shared canonical vocabulary and proposal tables immediately; external writers cannot invalidate a local cache.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function persist_proposal( $product_id, $group, $proposal ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        $payload = wp_json_encode( $proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO %i (product_id,semantic_group,state,vocabulary_id,label,confidence,payload_json,context_hash,classifier_version,active,updated_at) VALUES (%d,%s,%s,%d,%s,%f,%s,'',%s,1,%s) ON DUPLICATE KEY UPDATE state=VALUES(state),vocabulary_id=VALUES(vocabulary_id),label=VALUES(label),confidence=VALUES(confidence),payload_json=VALUES(payload_json),classifier_version=VALUES(classifier_version),active=1,updated_at=VALUES(updated_at)", $t['proposals'],
                $product_id,
                $group,
                sanitize_key( $proposal['state'] ),
                empty( $proposal['vocabulary_id'] ) ? 0 : absint( $proposal['vocabulary_id'] ),
                sanitize_text_field( $proposal['label'] ),
                (float) $proposal['confidence'],
                $payload,
                self::VERSION,
                current_time( 'mysql', true )
            )
        );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function category_context( $term_id ) {
        $term = get_term( $term_id, 'product_cat' );
        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'tcv_category_missing', 'Categoría no encontrada.' );
        }
        $ids = get_posts( array(
            'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids',
            'posts_per_page' => 50, 'orderby' => 'ID', 'order' => 'DESC',
            // Bounded to 50 IDs; the taxonomy relationship is required to analyse this category.
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            'tax_query' => array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( absint( $term_id ) ) ) ),
        ) );
        $sample = array();
        foreach ( $ids as $id ) {
            $p = get_post( $id );
            if ( $p ) {
                $sample[] = $p->post_title . ' ' . $p->post_excerpt;
            }
        }
        return TCV_Extractor::context( array(
            'title' => (string) $term->name,
            'excerpt' => (string) $term->description,
            'content' => implode( ' ', $sample ),
            'categories' => '',
        ) );
    }

    // Classifier must read and update shared canonical vocabulary and proposal tables immediately; external writers cannot invalidate a local cache.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    public static function analyze_category( $term_id ) {
        global $wpdb;
        $term_id = absint( $term_id );
        $ctx = self::category_context( $term_id );
        if ( is_wp_error( $ctx ) ) {
            return $ctx;
        }
        $t = TCV_Schema::tables();
        $current_rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT vocabulary_id FROM %i WHERE object_type='product_cat' AND object_id=%d AND status=1", $t['objects'],
            $term_id
        ) );
        $current = array_map( 'absint', (array) $current_rows );
        $result = array( 'term_id' => $term_id, 'labels' => array() );
        foreach ( array( 'tipo','aplicacion','plataforma','subtipo' ) as $group ) {
            $candidates = self::vocabulary_candidates( $group, $ctx );
            if ( ! $candidates ) {
                continue;
            }
            $top = $candidates[0];
            if ( in_array( absint( $top['id'] ), $current, true ) || (float) $top['score'] < 0.52 ) {
                continue;
            }
            $second = isset( $candidates[1] ) ? (float) $candidates[1]['score'] : 0.0;
            $state = ( $top['score'] >= 0.82 && ( $top['score'] - $second ) >= 0.12 ) ? 'safe' : 'review';
            $proposal = array( 'group'=>$group, 'vocabulary_id'=>absint($top['id']), 'label'=>$top['label'], 'confidence'=>(float)$top['score'], 'state'=>$state, 'evidence'=>$candidates );
            $result['labels'][] = $proposal;
            self::persist_category_proposal( $term_id, $group, $proposal );
            if ( 'tipo' === $group ) {
                $role = self::derive_role( absint( $top['id'] ) );
                if ( $role ) {
                    $rp = array( 'group'=>'rol', 'vocabulary_id'=>absint($role['id']), 'label'=>$role['label'], 'confidence'=>(float)$top['score'], 'state'=>'derived', 'evidence'=>array('derived_from_type'=>$top['label']) );
                    $result['labels'][] = $rp;
                    self::persist_category_proposal( $term_id, 'rol', $rp );
                }
            }
        }
        return $result;
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    // Classifier must read and update shared canonical vocabulary and proposal tables immediately; external writers cannot invalidate a local cache.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    private static function persist_category_proposal( $term_id, $group, $proposal ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO %i (term_id,semantic_group,state,vocabulary_id,label,confidence,payload_json,classifier_version,active,updated_at) VALUES (%d,%s,%s,%d,%s,%f,%s,%s,1,%s) ON DUPLICATE KEY UPDATE state=VALUES(state),vocabulary_id=VALUES(vocabulary_id),label=VALUES(label),confidence=VALUES(confidence),payload_json=VALUES(payload_json),classifier_version=VALUES(classifier_version),active=1,updated_at=VALUES(updated_at)", $t['cat_proposals'],
            $term_id,
            $group,
            sanitize_key( $proposal['state'] ),
            empty( $proposal['vocabulary_id'] ) ? 0 : absint( $proposal['vocabulary_id'] ),
            sanitize_text_field( $proposal['label'] ),
            (float) $proposal['confidence'],
            wp_json_encode( $proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            self::VERSION,
            current_time( 'mysql', true )
        ) );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    public static function sanitize_filters( $filters ) {
        $coverage_allowed = array( 'missing_any','without_any','without_type','without_role','without_application','without_platform','without_subtype' );
        $priority_allowed = array( 'all','p1','p2','p3','p4','p5' );
        $coverage = sanitize_key( (string) ( $filters['coverage'] ?? 'missing_any' ) );
        $priority = sanitize_key( (string) ( $filters['priority'] ?? 'all' ) );
        $rows = absint( $filters['rows'] ?? 25 );
        if ( ! in_array( $rows, array( 10,25,50,100 ), true ) ) {
            $rows = 25;
        }
        return array(
            'search'      => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
            'category_id' => absint( $filters['category_id'] ?? 0 ),
            'coverage'    => in_array( $coverage, $coverage_allowed, true ) ? $coverage : 'missing_any',
            'priority'    => in_array( $priority, $priority_allowed, true ) ? $priority : 'all',
            'rows'        => $rows,
        );
    }

    private static function missing_groups_for_product( $product_id ) {
        $current = self::current_groups( $product_id );
        $missing = array();
        foreach ( self::GROUPS as $group ) {
            if ( empty( $current[ $group ] ) ) {
                $missing[] = $group;
            }
        }
        return $missing;
    }

    private static function product_matches_filters( $product_id, $filters ) {
        $missing = self::missing_groups_for_product( $product_id );
        $coverage = $filters['coverage'];
        if ( 'missing_any' === $coverage && ! $missing ) {
            return false;
        }
        if ( 'without_any' === $coverage && count( $missing ) < count( self::GROUPS ) ) {
            return false;
        }
        $coverage_group = array(
            'without_type' => 'tipo',
            'without_role' => 'rol',
            'without_application' => 'aplicacion',
            'without_platform' => 'plataforma',
            'without_subtype' => 'subtipo',
        );
        if ( isset( $coverage_group[ $coverage ] ) && ! in_array( $coverage_group[ $coverage ], $missing, true ) ) {
            return false;
        }
        $priority_group = array( 'p1'=>'tipo','p2'=>'rol','p3'=>'aplicacion','p4'=>'subtipo','p5'=>'plataforma' );
        if ( isset( $priority_group[ $filters['priority'] ] ) && ! in_array( $priority_group[ $filters['priority'] ], $missing, true ) ) {
            return false;
        }
        return true;
    }

    public static function analyze_filtered_products( $filters ) {
        $filters = self::sanitize_filters( $filters );
        $query_args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => min( 500, max( $filters['rows'] * 8, 50 ) ),
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        );
        if ( '' !== $filters['search'] ) {
            $query_args['s'] = $filters['search'];
        }
        if ( $filters['category_id'] ) {
            // Bounded to at most 500 IDs; indexed product_cat relationship is intentional.
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => array( $filters['category_id'] ),
                ),
            );
        }
        $query = new WP_Query( $query_args );
        $results = array();
        foreach ( (array) $query->posts as $product_id ) {
            $product_id = absint( $product_id );
            if ( ! self::product_matches_filters( $product_id, $filters ) ) {
                continue;
            }
            $analysis = self::analyze_product( $product_id );
            if ( is_wp_error( $analysis ) ) {
                $results[] = array( 'product_id'=>$product_id, 'error'=>$analysis->get_error_message() );
            } else {
                $results[] = $analysis;
            }
            if ( count( $results ) >= $filters['rows'] ) {
                break;
            }
        }
        $report = array(
            'schema'       => 'taxocommerce_vocabulary_classifier_v1',
            'version'      => self::VERSION,
            'generated_gmt'=> current_time( 'mysql', true ),
            'filters'      => $filters,
            'processed'    => count( $results ),
            'results'      => $results,
        );
        update_option( 'taxocommerce_vocabulary_classifier_last_report', $report, false );
        return $report;
    }

    public static function last_report() {
        return (array) get_option( 'taxocommerce_vocabulary_classifier_last_report', array() );
    }

    // Classifier must read and update shared canonical vocabulary and proposal tables immediately; external writers cannot invalidate a local cache.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    public static function recent_category_proposals( $limit = 100 ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        $limit = max( 1, min( 500, absint( $limit ) ) );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE active=1 ORDER BY updated_at DESC LIMIT %d", $t['cat_proposals'], $limit ), ARRAY_A );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    // Classifier must read and update shared canonical vocabulary and proposal tables immediately; external writers cannot invalidate a local cache.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    public static function recent_proposals( $limit = 100 ) {
        global $wpdb;
        $t = TCV_Schema::tables();
        $limit = max( 1, min( 500, absint( $limit ) ) );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE active=1 ORDER BY updated_at DESC LIMIT %d", $t['proposals'], $limit ), ARRAY_A );
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
