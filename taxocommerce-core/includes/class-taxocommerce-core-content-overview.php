<?php
/**
 * Fast, read-only inventory for Core > Content.
 *
 * Uses WordPress-native counters and an existing cached SEO architecture
 * snapshot. This screen never recalculates the catalogue hierarchy.
 *
 * @package TaxoCommerceCore
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Core_Content_Overview {

    /**
     * Summarise one native post type without querying its rows.
     *
     * @param string $post_type WordPress post type.
     * @return array<string,int|bool>
     */
    public static function post_counts( $post_type ) {
        $available = post_type_exists( $post_type );
        $counts    = $available ? wp_count_posts( $post_type ) : null;

        $get = static function ( $status ) use ( $counts ) {
            return is_object( $counts ) && isset( $counts->{$status} )
                ? absint( $counts->{$status} )
                : 0;
        };

        $published   = $get( 'publish' );
        $unpublished = $get( 'draft' ) + $get( 'pending' ) + $get( 'future' ) + $get( 'private' );
        $trashed     = $get( 'trash' );

        return array(
            'available'   => $available,
            'published'   => $published,
            'unpublished' => $unpublished,
            'trash'       => $trashed,
            'total'       => $published + $unpublished + $trashed,
        );
    }

    /**
     * Only counters are read here; no product loop and no JOIN across metadata.
     *
     * @return array<string,mixed>
     */
    public static function collect() {
        $products = self::post_counts( 'product' );
        $posts    = self::post_counts( 'post' );
        $pages    = self::post_counts( 'page' );

        $categories = array( 'available' => taxonomy_exists( 'product_cat' ), 'total' => 0, 'with_products' => 0, 'without_products' => 0 );
        if ( $categories['available'] ) {
            $total = wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
            $used  = wp_count_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
            $categories['total']            = is_wp_error( $total ) ? 0 : absint( $total );
            $categories['with_products']    = is_wp_error( $used ) ? 0 : absint( $used );
            $categories['without_products'] = max( 0, $categories['total'] - $categories['with_products'] );
        }

        // Existing architecture snapshots are limited to 15 minutes. Do not call
        // Architecture_Report::report() from here: a cold report can scan products.
        $architecture = get_transient( TaxoCommerce_Core_Architecture_Report::CACHE_KEY );
        if ( ! is_array( $architecture ) || ! isset( $architecture['generated_at'], $architecture['clusters'] ) ) {
            $architecture = null;
        }

        return array(
            'products'     => $products,
            'categories'   => $categories,
            'posts'        => $posts,
            'pages'        => $pages,
            'architecture' => $architecture,
        );
    }

    /**
     * Link to an existing Core Content tab.
     *
     * @param string $type Tab slug.
     * @return string
     */
    private static function tab_url( $type ) {
        return add_query_arg(
            array(
                'page'    => TaxoCommerce_Core_Content_Manager::PAGE_SLUG,
                'tc_type' => $type,
            ),
            admin_url( 'admin.php' )
        );
    }

    /** Format a WordPress count for display. */
    private static function amount( $number ) {
        return esc_html( number_format_i18n( absint( $number ) ) );
    }

    /**
     * Render global inventory plus a cached hierarchy snapshot if available.
     * Never initiates an audit or writes data.
     *
     * @return void
     */
    public static function render() {
        $inventory = self::collect();

        $groups = array(
            'products'   => array( 'Productos', 'Productos publicados' ),
            'categories' => array( 'Categorías de producto', 'Categorías registradas' ),
            'pages'      => array( 'Páginas', 'Páginas publicadas' ),
            'posts'      => array( 'Entradas', 'Entradas publicadas' ),
        );

        echo '<section class="tcc-inventory" aria-labelledby="tcc-inventory-title">';
        echo '<div class="tcc-inventory-heading"><h2 id="tcc-inventory-title">Inventario general de contenidos</h2>';
        echo '<p>Recuentos nativos de WordPress y WooCommerce. Esta pantalla no ejecuta auditorías, recorridos de productos ni consultas de clasificación internacional.</p></div>';
        echo '<div class="tcc-inventory-cards">';

        foreach ( $groups as $slug => $labels ) {
            $record = $inventory[ $slug ];
            $value = 'categories' === $slug ? $record['total'] : $record['published'];
            $detail = 'categories' === $slug
                ? self::amount( $record['with_products'] ) . ' con productos · ' . self::amount( $record['without_products'] ) . ' sin productos'
                : self::amount( $record['unpublished'] ) . ' sin publicar · ' . self::amount( $record['trash'] ) . ' en papelera';

            echo '<article class="tcc-inventory-card"><span>' . esc_html( $labels[1] ) . '</span>';
            if ( $record['available'] ) {
                echo '<strong>' . self::amount( $value ) . '</strong><small>' . wp_kses_post( $detail ) . '</small>';
            } else {
                echo '<strong>—</strong><small>Tipo de contenido no disponible.</small>';
            }
            echo '<a href="' . esc_url( self::tab_url( $slug ) ) . '">Ver ' . esc_html( $labels[0] ) . '</a></article>';
        }
        echo '</div>';

        echo '<div class="tcc-inventory-panels">';
        echo '<section class="tcc-inventory-panel" aria-labelledby="tcc-inventory-status"><h3 id="tcc-inventory-status">Estado del contenido</h3>';
        echo '<div class="tcc-inventory-table-wrap"><table class="widefat striped"><thead><tr>';
        echo '<th scope="col">Tipo</th><th scope="col">Total</th><th scope="col">Publicados</th><th scope="col">Sin publicar</th><th scope="col">Papelera</th></tr></thead><tbody>';
        foreach ( array( 'products', 'pages', 'posts' ) as $slug ) {
            $record = $inventory[ $slug ];
            echo '<tr><th scope="row"><a href="' . esc_url( self::tab_url( $slug ) ) . '">' . esc_html( $groups[ $slug ][0] ) . '</a></th>';
            foreach ( array( 'total', 'published', 'unpublished', 'trash' ) as $field ) {
                echo '<td>' . ( $record['available'] ? self::amount( $record[ $field ] ) : '—' ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div><p class="description">Total: publicados, borradores, pendientes, privados, programados y papelera. Se excluyen revisiones y borradores automáticos.</p></section>';

        echo '<section class="tcc-inventory-panel" aria-labelledby="tcc-inventory-architecture"><h3 id="tcc-inventory-architecture">Cobertura de la arquitectura SEO</h3>';
        $architecture = $inventory['architecture'];
        if ( $architecture ) {
            echo '<p class="description">Instantánea existente, calculada ' . esc_html( wp_date( 'd/m/Y H:i', absint( $architecture['generated_at'] ) ) ) . '.</p>';
            echo '<div class="tcc-inventory-snapshot">';
            foreach ( array(
                'clusters_count'   => 'Clústeres',
                'primary_count'    => 'Hubs primarios',
                'secondary_count'  => 'Hubs secundarios',
                'categories_count' => 'Categorías cubiertas',
                'products_count'   => 'Productos cubiertos',
            ) as $key => $label ) {
                echo '<div><strong>' . self::amount( $architecture[ $key ] ?? 0 ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
            }
            echo '</div>';
            if ( ! empty( $architecture['clusters'] ) ) {
                echo '<div class="tcc-inventory-table-wrap"><table class="widefat striped"><thead><tr><th scope="col">Clúster</th><th scope="col">Categorías</th><th scope="col">Productos</th></tr></thead><tbody>';
                foreach ( $architecture['clusters'] as $cluster ) {
                    echo '<tr><th scope="row">' . esc_html( (string) ( $cluster['title'] ?? '' ) ) . '</th>';
                    echo '<td>' . self::amount( $cluster['categories'] ?? 0 ) . '</td><td>' . self::amount( $cluster['products'] ?? 0 ) . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '<p class="description">La cobertura SEO no es el inventario total. Los productos pueden aparecer en varias categorías o clústeres; no sumes sus filas como si fueran productos únicos.</p>';
        } else {
            echo '<p>Sin instantánea de arquitectura en caché. El informe se calcula únicamente al abrir «Arquitectura SEO», para no ralentizar este resumen.</p>';
        }
        echo '<p><a class="button button-secondary" href="' . esc_url( self::tab_url( 'architecture' ) ) . '">Ver distribución SEO detallada</a></p>';
        echo '</section></div>';

        echo '<nav class="tcc-inventory-links" aria-label="Informes y revisiones de contenido">';
        echo '<a href="' . esc_url( self::tab_url( 'anomalies' ) ) . '">Consultar anomalías de contenidos</a>';
        if ( class_exists( 'TaxoCommerce\\Auditor\\Plugin', false ) ) {
            echo '<a href="' . esc_url( admin_url( 'admin.php?page=taxocommerce-auditor' ) ) . '">Abrir Auditor de calidad</a>';
        }
        echo '</nav></section>';
    }
}
