<?php
/**
 * Read-only distribution of the SEO hierarchy managed by TaxoCommerce Core.
 *
 * This report deliberately never loads or calls the legacy SEO plugin.
 * Product counts are based on published WooCommerce products and their
 * native product_cat assignments, deduplicated at each hierarchy level.
 *
 * @package TaxoCommerceCore
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Core_Architecture_Report {
    const CACHE_KEY = 'taxocommerce_core_architecture_report_v1';
    const CACHE_TTL = 15 * MINUTE_IN_SECONDS;
    const PAGE_TYPE = 'architecture';

    public static function register_hooks() {
        add_action( 'admin_post_taxocommerce_core_refresh_architecture', array( __CLASS__, 'refresh' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
    }

    public static function enqueue_styles( $hook ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin tab selector.
        $type = isset( $_GET['tc_type'] ) ? sanitize_key( wp_unslash( $_GET['tc_type'] ) ) : '';
        if ( self::PAGE_TYPE !== $type || false === strpos( (string) $hook, '_page_' . TaxoCommerce_Core_Content_Manager::PAGE_SLUG ) ) {
            return;
        }

        wp_enqueue_style(
            'taxocommerce-core-architecture',
            plugins_url( 'assets/architecture-report.css', TAXOCOMMERCE_CORE_FILE ),
            array( 'taxocommerce-core-admin' ),
            TAXOCOMMERCE_CORE_VERSION
        );
    }

    /**
     * POST-only manual refresh, with no scheduled jobs or remote requests.
     */
    public static function refresh() {
        if ( ! current_user_can( apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' ) ) ) {
            wp_die( esc_html__( 'No tienes permisos para actualizar este informe.', 'taxocommerce-core' ) );
        }
        check_admin_referer( 'taxocommerce_core_architecture_refresh', 'tc_arch_nonce' );
        delete_transient( self::CACHE_KEY );
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'    => TaxoCommerce_Core_Content_Manager::PAGE_SLUG,
                    'tc_type' => self::PAGE_TYPE,
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /**
     * Published role sets, excluding draft/private/trashed hierarchy pages.
     */
    private static function published_roles() {
        global $wpdb;
        $table = $wpdb->prefix . 'seo_nodes';

        // The table is maintained by Core; it can be absent on a new install.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        if ( $exists !== $table ) {
            return new WP_Error( 'tc_arch_no_nodes', 'Todavía no está disponible la tabla de roles SEO del Core.' );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WP-owned identifiers, fixed predicates; no user input.
        $rows = $wpdb->get_results(
            "SELECT DISTINCT n.object_id AS id, n.seo_role AS role
               FROM {$table} n
               INNER JOIN {$wpdb->posts} p ON p.ID = n.object_id
              WHERE n.object_type = 'page'
                AND n.status = 1
                AND p.post_type = 'page'
                AND p.post_status = 'publish'
                AND n.seo_role IN ('cluster','hub_primary','hub_secondary')",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) {
            return new WP_Error( 'tc_arch_query_nodes', 'No se han podido consultar los roles de las páginas SEO.' );
        }
        return $rows;
    }

    /**
     * Return relation maps without duplicate links.
     *
     * @param array $rows Hierarchy relation rows (relation_type, source_id, target_id).
     * @return array
     */
    private static function relation_maps( $rows ) {
        $allowed = array(
            'cluster_to_primary' => true,
            'hub_primary_to_hub_secondary' => true,
            'hub_secondary_to_category' => true,
        );
        $maps = array();
        foreach ( $rows as $row ) {
            $type = isset( $row['relation_type'] ) ? (string) $row['relation_type'] : '';
            $source = isset( $row['source_id'] ) ? absint( $row['source_id'] ) : 0;
            $target = isset( $row['target_id'] ) ? absint( $row['target_id'] ) : 0;
            if ( ! isset( $allowed[ $type ] ) || ! $source || ! $target ) {
                continue;
            }
            $maps[ $type ][ $source ][ $target ] = true;
        }
        return $maps;
    }

    /** Determine only categories reachable from published clusters. */
    public static function linked_category_ids( $roles, $relations ) {
        $maps = self::relation_maps( $relations );
        $categories = array();

        foreach ( $roles as $row ) {
            if ( 'cluster' !== (string) ( $row['role'] ?? '' ) ) {
                continue;
            }
            $cluster_id = absint( $row['id'] ?? 0 );
            foreach ( (array) ( $maps['cluster_to_primary'][ $cluster_id ] ?? array() ) as $primary_id => $unused ) {
                foreach ( (array) ( $maps['hub_primary_to_hub_secondary'][ $primary_id ] ?? array() ) as $secondary_id => $ignored ) {
                    foreach ( (array) ( $maps['hub_secondary_to_category'][ $secondary_id ] ?? array() ) as $cat_id => $flag ) {
                        $categories[ (int) $cat_id ] = true;
                    }
                }
            }
        }
        return array_keys( $categories );
    }

    /** One batched read of native product-to-category memberships; never one query per hub. */
    private static function published_product_categories( $category_ids ) {
        global $wpdb;
        $result = array();
        foreach ( array_chunk( (array) $category_ids, 200 ) as $batch ) {
            $ids = array_values( array_filter( array_map( 'absint', $batch ) ) );
            if ( ! $ids ) {
                continue;
            }
            $holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only WP table identifiers and generated %d placeholders.
            $query = $wpdb->prepare(
                "SELECT DISTINCT p.ID AS product_id, tt.term_id AS category_id
                   FROM {$wpdb->posts} p
                   INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                   INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                  WHERE p.post_type = 'product'
                    AND p.post_status = 'publish'
                    AND tt.taxonomy = 'product_cat'
                    AND tt.term_id IN ({$holders})",
                $ids
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is fully prepared with integer placeholders.
            $rows = $wpdb->get_results( $query, ARRAY_A );
            if ( ! is_array( $rows ) ) {
                return new WP_Error( 'tc_arch_query_products', 'No se han podido consultar las categorías de los productos.' );
            }
            foreach ( $rows as $row ) {
                $category_id = absint( $row['category_id'] ?? 0 );
                $product_id  = absint( $row['product_id'] ?? 0 );
                if ( $category_id && $product_id ) {
                    $result[ $category_id ][ $product_id ] = true;
                }
            }
        }
        return $result;
    }

    /**
     * Pure reducer for an auditable hierarchy distribution report.
     *
     * A product linked to multiple categories counts only once in each
     * hub/cluster/site total. The same product may appear in two different
     * hub rows, but is deduplicated again in the parent cluster.
     */
    public static function summarize( $roles, $relations, $products_by_category, $titles, $landings ) {
        $sets = array( 'cluster' => array(), 'hub_primary' => array(), 'hub_secondary' => array() );
        foreach ( (array) $roles as $role ) {
            $id = absint( $role['id'] ?? 0 );
            $name = (string) ( $role['role'] ?? '' );
            if ( $id && isset( $sets[ $name ] ) ) {
                $sets[ $name ][ $id ] = true;
            }
        }

        $maps = self::relation_maps( (array) $relations );
        $clusters = array();
        $global_categories = array();
        $global_products = array();

        foreach ( array_keys( $sets['cluster'] ) as $cluster_id ) {
            $primaries = (array) ( $maps['cluster_to_primary'][ $cluster_id ] ?? array() );
            $all_secondary = array();
            $cluster_categories = array();
            $cluster_products = array();
            $primary_rows = array();

            foreach ( array_keys( $primaries ) as $primary_id ) {
                $secondaries = (array) ( $maps['hub_primary_to_hub_secondary'][ $primary_id ] ?? array() );
                $categories = array();
                $products = array();

                foreach ( array_keys( $secondaries ) as $secondary_id ) {
                    $all_secondary[ $secondary_id ] = true;
                    $linked = (array) ( $maps['hub_secondary_to_category'][ $secondary_id ] ?? array() );
                    foreach ( array_keys( $linked ) as $cat_id ) {
                        $categories[ $cat_id ] = true;
                        foreach ( (array) ( $products_by_category[ $cat_id ] ?? array() ) as $product_id => $unused ) {
                            $products[ $product_id ] = true;
                        }
                    }
                }

                foreach ( $categories as $cat_id => $unused ) {
                    $cluster_categories[ $cat_id ] = true;
                }
                foreach ( $products as $product_id => $unused ) {
                    $cluster_products[ $product_id ] = true;
                }

                $primary_rows[] = array(
                    'id'          => (int) $primary_id,
                    'label'       => (string) ( $titles[ $primary_id ] ?? ( 'Hub #' . $primary_id ) ),
                    'secondaries' => count( $secondaries ),
                    'categories'  => count( $categories ),
                    'products'    => count( $products ),
                );
            }

            foreach ( $cluster_categories as $cat_id => $unused ) {
                $global_categories[ $cat_id ] = true;
            }
            foreach ( $cluster_products as $product_id => $unused ) {
                $global_products[ $product_id ] = true;
            }
            $cat_count = count( $cluster_categories );
            $product_count = count( $cluster_products );
            $clusters[] = array(
                'id'         => (int) $cluster_id,
                'title'      => (string) ( $titles[ $cluster_id ] ?? ( 'Cluster #' . $cluster_id ) ),
                'primary'    => count( $primaries ),
                'secondary'  => count( $all_secondary ),
                'categories' => $cat_count,
                'products'   => $product_count,
                'density'    => $cat_count ? round( $product_count / $cat_count, 1 ) : 0,
                'primaryRows' => $primary_rows,
            );
        }

        usort( $clusters, static function ( $a, $b ) {
            return $b['products'] <=> $a['products'];
        } );

        $landings = max( 0, (int) $landings );
        return array(
            'clusters_count'     => count( $sets['cluster'] ),
            'primary_count'      => count( $sets['hub_primary'] ),
            'secondary_count'    => count( $sets['hub_secondary'] ),
            'landings_count'     => $landings,
            'seo_pages_count'    => count( $sets['cluster'] ) + count( $sets['hub_primary'] ) + count( $sets['hub_secondary'] ) + $landings,
            'categories_count'   => count( $global_categories ),
            'products_count'     => count( $global_products ),
            'clusters'           => $clusters,
        );
    }

    /**
     * Read canonical Core tables and WooCommerce memberships.
     *
     * @return array|WP_Error
     */
    private static function calculate() {
        if ( ! TaxoCommerce_Core_Hierarchy_Classic::available() ) {
            return new WP_Error( 'tc_arch_no_relations', 'Todavía no está disponible la jerarquía SEO en TaxoCommerce Core.' );
        }
        $roles = self::published_roles();
        if ( is_wp_error( $roles ) ) {
            return $roles;
        }

        $relations = TaxoCommerce_Core_Hierarchy_Classic::rows();
        $categories = self::linked_category_ids( $roles, $relations );
        $products = self::published_product_categories( $categories );
        if ( is_wp_error( $products ) ) {
            return $products;
        }

        global $wpdb;
        $relations_table = $wpdb->prefix . 'seo_relations';
        // Match the historical panel: distinct linked landings, irrespective of page status.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal Core-managed identifier and fixed predicate.
        $landings = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT target_id) FROM {$relations_table} WHERE target_type = 'landing_page'"
        );

        $titles = array();
        foreach ( (array) $roles as $row ) {
            $id = absint( $row['id'] ?? 0 );
            if ( $id ) {
                $titles[ $id ] = get_the_title( $id );
            }
        }
        foreach ( (array) $relations as $relation ) {
            if ( 'cluster_to_primary' !== (string) ( $relation['relation_type'] ?? '' ) ) {
                continue;
            }
            $id = absint( $relation['target_id'] ?? 0 );
            if ( $id && ! isset( $titles[ $id ] ) ) {
                $title = get_the_title( $id );
                $titles[ $id ] = $title ? $title : 'Hub #' . $id;
            }
        }

        $summary = self::summarize( $roles, $relations, $products, $titles, $landings );
        $summary['generated_at'] = time();
        return $summary;
    }

    public static function report() {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) && isset( $cached['generated_at'], $cached['clusters'] ) ) {
            return $cached;
        }
        $result = self::calculate();
        if ( ! is_wp_error( $result ) ) {
            set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
        }
        return $result;
    }

    private static function amount( $number ) {
        return esc_html( number_format_i18n( (int) $number ) );
    }

    public static function render() {
        $report = self::report();
        echo '<div class="tc-architecture-report">';
        echo '<div class="tc-architecture-heading"><div><h2>Distribución de la arquitectura SEO</h2>';
        echo '<p>Lectura de páginas, categorías y productos a través de los clusters. Informe de solo lectura.</p></div>';
        echo '<div class="tc-architecture-tools">';
        if ( ! is_wp_error( $report ) ) {
            echo '<span class="description">Calculado ' . esc_html( wp_date( 'd/m/Y H:i', (int) $report['generated_at'] ) ) . ' · caché de 15 minutos</span>';
        }
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="taxocommerce_core_refresh_architecture">';
        wp_nonce_field( 'taxocommerce_core_architecture_refresh', 'tc_arch_nonce' );
        echo '<button class="button" type="submit">Actualizar informe</button>';
        echo '</form></div></div>';

        if ( is_wp_error( $report ) ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html( $report->get_error_message() ) . '</p></div>';
            echo '</div>';
            return;
        }

        $cards = array(
            array( 'Clusters', $report['clusters_count'], 'Estructuras principales', 'blue' ),
            array( 'Páginas SEO', $report['seo_pages_count'], self::amount( $report['primary_count'] ) . ' primarias · ' . self::amount( $report['secondary_count'] ) . ' secundarias · ' . self::amount( $report['landings_count'] ) . ' landings', 'violet' ),
            array( 'Categorías cubiertas', $report['categories_count'], 'Categorías únicas vinculadas', 'amber' ),
            array( 'Productos cubiertos', $report['products_count'], 'Productos publicados únicos', 'green' ),
        );
        echo '<div class="tc-architecture-kpis">';
        foreach ( $cards as $card ) {
            echo '<div class="tc-architecture-kpi tc-architecture-kpi--' . esc_attr( $card[3] ) . '">';
            echo '<span>' . esc_html( $card[0] ) . '</span><strong>' . self::amount( $card[1] ) . '</strong>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Card detail contains only numbers escaped by amount() and static text.
            echo '<small>' . $card[2] . '</small></div>';
        }
        echo '</div>';

        if ( empty( $report['clusters'] ) ) {
            echo '<div class="notice notice-info inline"><p>No existen clusters publicados con los que construir el informe.</p></div></div>';
            return;
        }

        echo '<section class="tc-architecture-section"><div class="tc-architecture-section-heading"><div>';
        echo '<span>Visión global</span><h3>Cómo se reparte la arquitectura</h3></div>';
        echo '<p>Clusters ordenados por número de productos, con recuentos únicos en cada nivel.</p></div>';
        echo '<div class="tc-architecture-panel"><h4>Resumen por cluster</h4><div class="tc-architecture-table-wrap">';
        echo '<table class="widefat striped"><thead><tr><th>Cluster</th><th>Hubs primarios</th><th>Hubs secundarios</th><th>Categorías</th><th>Productos</th><th>Prod./categoría</th></tr></thead><tbody>';
        foreach ( $report['clusters'] as $cluster ) {
            echo '<tr><th scope="row">' . esc_html( $cluster['title'] ) . '</th>';
            foreach ( array( 'primary', 'secondary', 'categories', 'products' ) as $column ) {
                echo '<td>' . self::amount( $cluster[ $column ] ) . '</td>';
            }
            echo '<td>' . esc_html( number_format_i18n( (float) $cluster['density'], 1 ) ) . '</td></tr>';
        }
        echo '</tbody></table></div></div></section>';

        echo '<section class="tc-architecture-section"><div class="tc-architecture-section-heading"><div>';
        echo '<span>Anatomía interna</span><h3>Detalle por cluster</h3></div>';
        echo '<p>Cada barra indica el peso de un hub primario dentro del cluster; los productos presentes en varios hubs pueden repetirse entre filas.</p></div>';

        foreach ( $report['clusters'] as $cluster ) {
            echo '<article class="tc-architecture-cluster">';
            echo '<div class="tc-architecture-cluster-head"><div><span>Cluster</span><h4>' . esc_html( $cluster['title'] ) . '</h4></div>';
            echo '<strong>' . self::amount( $cluster['products'] ) . ' <small>productos</small></strong></div>';
            echo '<div class="tc-architecture-cluster-stats">';
            foreach ( array(
                'primary' => 'Hubs primarios',
                'secondary' => 'Hubs secundarios',
                'categories' => 'Categorías',
            ) as $metric => $label ) {
                echo '<div><strong>' . self::amount( $cluster[ $metric ] ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
            }
            echo '<div><strong>' . esc_html( number_format_i18n( (float) $cluster['density'], 1 ) ) . '</strong><span>Prod./categoría</span></div>';
            echo '</div>';

            if ( ! empty( $cluster['primaryRows'] ) ) {
                echo '<div class="tc-architecture-cluster-detail">';
                echo '<div class="tc-architecture-bars"><h5>Reparto por hub primario</h5>';
                foreach ( $cluster['primaryRows'] as $row ) {
                    echo '<div class="tc-architecture-bar"><div><span>' . esc_html( $row['label'] ) . '</span><strong>' . self::amount( $row['products'] ) . '</strong></div>';
                    echo '<progress max="' . esc_attr( (string) max( 1, (int) $cluster['products'] ) ) . '" value="' . esc_attr( (string) $row['products'] ) . '"></progress></div>';
                }
                echo '</div><div class="tc-architecture-table-wrap"><table class="widefat striped">';
                echo '<thead><tr><th>Hub primario</th><th>Subhubs</th><th>Categorías</th><th>Productos</th></tr></thead><tbody>';
                foreach ( $cluster['primaryRows'] as $row ) {
                    echo '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th><td>' . self::amount( $row['secondaries'] ) . '</td><td>' . self::amount( $row['categories'] ) . '</td><td><strong>' . self::amount( $row['products'] ) . '</strong></td></tr>';
                }
                echo '</tbody></table></div></div>';
            } else {
                echo '<p class="tc-architecture-no-data">Este cluster no tiene hubs primarios vinculados.</p>';
            }
            echo '</article>';
        }
        echo '</section>';
        echo '</div>';
    }
}
