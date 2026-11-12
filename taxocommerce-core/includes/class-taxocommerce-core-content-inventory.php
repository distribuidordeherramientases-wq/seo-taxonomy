<?php
/**
 * Read-only, cached inventory of canonical WordPress / WooCommerce content.
 *
 * @package TaxoCommerceCore
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lightweight stock-taking. Does not replace the detailed Auditor module.
 */
final class TaxoCommerce_Core_Content_Inventory {
    const CACHE_KEY = 'taxocommerce_core_content_inventory_v1';
    const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

    public static function register_hooks() {
        add_action( 'admin_post_taxocommerce_core_refresh_inventory', array( __CLASS__, 'handle_refresh' ) );
    }

    /**
     * Only stable editorial statuses; WordPress auto-drafts are not inventory.
     *
     * @param string $post_type Native WordPress post type.
     * @return array|null Status breakdown or null if type is unavailable.
     */
    private static function post_counts( $post_type ) {
        if ( ! post_type_exists( $post_type ) ) {
            return null;
        }
        $counts = wp_count_posts( $post_type );
        $statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
        $row = array();
        foreach ( $statuses as $status ) {
            $row[ $status ] = isset( $counts->$status ) ? absint( $counts->$status ) : 0;
        }
        $row['total'] = array_sum( $row );
        return $row;
    }

    /**
     * @param string $taxonomy Registered WordPress taxonomy.
     * @return array|null Number of all/used/empty terms, or null if absent.
     */
    private static function term_counts( $taxonomy ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return null;
        }
        $all  = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
        $used = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
        if ( is_wp_error( $all ) || is_wp_error( $used ) ) {
            return null;
        }
        $all  = absint( $all );
        $used = min( $all, absint( $used ) );
        return array( 'total' => $all, 'used' => $used, 'empty' => max( 0, $all - $used ) );
    }

    /**
     * Cached dashboard data; standard WordPress count APIs and one optional FAQ query.
     *
     * @return array Snapshot for the inventory UI.
     */
    public static function snapshot() {
        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) && isset( $cached['generated_at'], $cached['types'] ) ) {
            return $cached;
        }
        $types = array();
        foreach ( array( 'products' => 'product', 'posts' => 'post', 'pages' => 'page' ) as $key => $post_type ) {
            $types[ $key ] = self::post_counts( $post_type );
        }
        $media_counts = post_type_exists( 'attachment' ) ? wp_count_posts( 'attachment' ) : null;
        $media_total  = is_object( $media_counts ) ? array_sum( array_map( 'absint', (array) $media_counts ) ) : 0;

        $faqs = null;
        if ( class_exists( 'TaxoCommerce_FAQs' ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'seo_faq';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live content inventory requires the current Core canonical table schema.
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( $table === $exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live content inventory requires the current Core canonical table schema.
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        'SELECT COUNT(*) AS total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active FROM %i',
                        $table
                    ),
                    ARRAY_A
                );
                if ( is_array( $row ) ) {
                    $faqs = array(
                        'total' => absint( $row['total'] ?? 0 ),
                        'active' => absint( $row['active'] ?? 0 ),
                    );
                }
            }
        }

        $data = array(
            'types'        => $types,
            'product_cats' => self::term_counts( 'product_cat' ),
            'blog_cats'    => self::term_counts( 'category' ),
            'media'        => $media_total,
            'faqs'         => $faqs,
            'generated_at' => time(),
        );
        set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
        return $data;
    }

    public static function handle_refresh() {
        if ( ! current_user_can( apply_filters( 'taxocommerce_core_manage_capability', 'manage_options' ) ) ) {
            wp_die( esc_html__( 'No tienes permisos para consultar el inventario.', 'taxocommerce-core' ) );
        }
        check_admin_referer( 'taxocommerce_core_refresh_inventory', 'tc_inventory_nonce' );
        delete_transient( self::CACHE_KEY );
        wp_safe_redirect( self::tab_url( 'summary' ) );
        exit;
    }

    private static function tab_url( $type ) {
        return add_query_arg(
            array( 'page' => TaxoCommerce_Core_Content_Manager::PAGE_SLUG, 'tc_type' => $type ),
            admin_url( 'admin.php' )
        );
    }

    private static function card( $label, $value, $detail, $url ) {
        echo '<div class="tc-inventory-card">';
        echo '<span>' . esc_html( $label ) . '</span>';
        echo '<strong>' . esc_html( number_format_i18n( absint( $value ) ) ) . '</strong>';
        echo '<small>' . esc_html( $detail ) . '</small>';
        echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ver contenidos', 'taxocommerce-core' ) . '</a>';
        echo '</div>';
    }

    /**
     * Render an accessible snapshot, separate from qualitative Auditor reports.
     */
    public static function render() {
        $data = self::snapshot();
        $types = $data['types'];

        echo '<section class="tc-content-inventory" aria-label="' . esc_attr__( 'Inventario de contenidos', 'taxocommerce-core' ) . '">';
        echo '<div class="tc-inventory-tools"><div><h2>' . esc_html__( 'Resumen general de contenidos', 'taxocommerce-core' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'Recuento de los datos reales de WordPress y WooCommerce. No ejecuta una auditoría completa ni modifica contenidos.', 'taxocommerce-core' ) . '</p>';
        echo '<p class="description">' . esc_html( sprintf(
            /* translators: %s: date and time of latest cached inventory. */
            __( 'Actualizado: %s · Caché de 15 minutos', 'taxocommerce-core' ),
            wp_date( 'd/m/Y H:i', (int) $data['generated_at'] )
        ) ) . '</p></div>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="taxocommerce_core_refresh_inventory">';
        wp_nonce_field( 'taxocommerce_core_refresh_inventory', 'tc_inventory_nonce' );
        echo '<button class="button" type="submit">' . esc_html__( 'Actualizar inventario', 'taxocommerce-core' ) . '</button></form></div>';

        echo '<div class="tc-inventory-grid">';
        foreach ( array(
            'products' => __( 'Productos', 'taxocommerce-core' ),
            'posts' => __( 'Entradas', 'taxocommerce-core' ),
            'pages' => __( 'Páginas', 'taxocommerce-core' ),
        ) as $key => $label ) {
            if ( ! is_array( $types[ $key ] ) ) {
                continue;
            }
            self::card( $label, $types[ $key ]['total'], sprintf(
                /* translators: %d: published content count. */
                __( '%d publicados', 'taxocommerce-core' ),
                $types[ $key ]['publish']
            ), self::tab_url( $key ) );
        }
        if ( is_array( $data['product_cats'] ) ) {
            self::card( __( 'Categorías de producto', 'taxocommerce-core' ), $data['product_cats']['total'], sprintf(
                /* translators: %d: empty product categories. */
                __( '%d sin productos', 'taxocommerce-core' ),
                $data['product_cats']['empty']
            ), self::tab_url( 'categories' ) );
        }
        if ( is_array( $data['blog_cats'] ) ) {
            self::card( __( 'Categorías de entradas', 'taxocommerce-core' ), $data['blog_cats']['total'], sprintf(
                /* translators: %d: categories with content. */
                __( '%d con entradas', 'taxocommerce-core' ),
                $data['blog_cats']['used']
            ), admin_url( 'edit-tags.php?taxonomy=category' ) );
        }
        self::card( __( 'Archivos multimedia', 'taxocommerce-core' ), $data['media'], __( 'Elementos en la biblioteca', 'taxocommerce-core' ), admin_url( 'upload.php' ) );
        if ( is_array( $data['faqs'] ) ) {
            self::card( __( 'FAQs', 'taxocommerce-core' ), $data['faqs']['total'], sprintf(
                /* translators: %d: enabled FAQ count. */
                __( '%d activas', 'taxocommerce-core' ),
                $data['faqs']['active']
            ), self::tab_url( 'faqs' ) );
        }
        echo '</div>';

        echo '<div class="tcc-family-panel"><h2>' . esc_html__( 'Estados editoriales', 'taxocommerce-core' ) . '</h2>';
        echo '<div class="tc-inventory-table-scroll"><table class="widefat striped tc-inventory-table"><thead><tr>';
        foreach ( array( 'Tipo', 'Publicados', 'Borradores', 'Pendientes', 'Privados', 'Programados', 'Papelera', 'Total' ) as $title ) {
            echo '<th scope="col">' . esc_html( $title ) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ( array( 'products' => 'Productos', 'posts' => 'Entradas', 'pages' => 'Páginas' ) as $type => $label ) {
            if ( ! is_array( $types[ $type ] ) ) {
                continue;
            }
            echo '<tr><th scope="row"><a href="' . esc_url( self::tab_url( $type ) ) . '">' . esc_html( $label ) . '</a></th>';
            foreach ( array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', 'total' ) as $status ) {
                echo '<td>' . esc_html( number_format_i18n( $types[ $type ][ $status ] ) ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div><p class="description">' . esc_html__( 'Los totales incluyen contenidos en papelera y excluyen los borradores automáticos de WordPress.', 'taxocommerce-core' ) . '</p></div>';

        echo '<div class="tc-inventory-actions"><a class="button" href="#tc-architecture-report">' . esc_html__( 'Arquitectura SEO', 'taxocommerce-core' ) . '</a>';
        echo '<a class="button" href="' . esc_url( self::tab_url( 'anomalies' ) ) . '">' . esc_html__( 'Revisar anomalías', 'taxocommerce-core' ) . '</a></div>';
        echo '<p class="description">' . esc_html__( 'Para calidad, gravedad de errores, recomendaciones y exportaciones completas utiliza TaxoCommerce Auditor.', 'taxocommerce-core' ) . '</p>';
        echo '</section>';
        // One canonical Content summary: inventory followed by SEO architecture.
        TaxoCommerce_Core_Architecture_Report::render();
    }
}
