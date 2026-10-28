<?php
/**
 * Spain-only hierarchical Google Trends explorer.
 *
 * The canonical TaxoCommerce Core maps determine membership. No Trends
 * requests are made on page load; external charts are opened by explicit
 * browser actions. The embeds do not expose numerical interest series.
 *
 * @package TaxoCommerceInsights
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_Trends_Hierarchy {

    /**
     * Do not treat translated categories as synonyms of Spanish terms.
     * Older Spanish terms may have no locale/country metadata.
     */
    public static function is_spanish( $locale, $country = '' ) {
        $country = strtoupper( trim( (string) $country ) );
        if ( '' !== $country && 'ES' !== $country ) {
            return false;
        }
        $locale = strtolower( str_replace( '_', '-', trim( (string) $locale ) ) );
        return '' === $locale || 'es' === $locale || 0 === strpos( $locale, 'es-' );
    }

    private static function page( $id ) {
        $id = absint( $id );
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
            return null;
        }
        if ( ! self::is_spanish(
            get_post_meta( $id, '_seo_locale', true ),
            get_post_meta( $id, '_seo_pais_origen', true )
        ) ) {
            return null;
        }
        return array( 'id' => $id, 'name' => (string) $post->post_title );
    }

    private static function category( $id ) {
        $id = absint( $id );
        $term = $id ? get_term( $id, 'product_cat' ) : null;
        if ( ! $term || is_wp_error( $term ) || ! isset( $term->term_id ) ) {
            return null;
        }
        if ( ! self::is_spanish(
            get_term_meta( $id, '_seo_locale', true ),
            get_term_meta( $id, '_seo_pais_origen', true )
        ) ) {
            return null;
        }
        return array( 'id' => $id, 'name' => (string) $term->name );
    }

    private static function by_name( &$rows ) {
        usort( $rows, static function ( $left, $right ) {
            return strcasecmp( $left['name'], $right['name'] );
        } );
    }

    /**
     * Build from real Core relations, never from matching names.
     *
     * @return array Tree of published Spanish pages and Spanish categories.
     */
    public static function tree() {
        if ( ! class_exists( 'TaxoCommerce_Core_Hierarchy_Classic' )
            || ! TaxoCommerce_Core_Hierarchy_Classic::available() ) {
            return array();
        }

        $clusters   = TaxoCommerce_Core_Hierarchy_Classic::role_pages( 'cluster' );
        $cp         = TaxoCommerce_Core_Hierarchy_Classic::map( 'cluster_to_primary' );
        $ps         = TaxoCommerce_Core_Hierarchy_Classic::map( 'hub_primary_to_hub_secondary' );
        $sc         = TaxoCommerce_Core_Hierarchy_Classic::map( 'hub_secondary_to_category' );
        $tree       = array();
        $page_cache = array();
        $term_cache = array();

        $get_page = static function ( $id ) use ( &$page_cache ) {
            $id = absint( $id );
            if ( ! array_key_exists( $id, $page_cache ) ) {
                $page_cache[ $id ] = self::page( $id );
            }
            return $page_cache[ $id ];
        };
        $get_category = static function ( $id ) use ( &$term_cache ) {
            $id = absint( $id );
            if ( ! array_key_exists( $id, $term_cache ) ) {
                $term_cache[ $id ] = self::category( $id );
            }
            return $term_cache[ $id ];
        };

        foreach ( (array) $clusters as $cluster_post ) {
            $cluster = $get_page( $cluster_post->ID );
            if ( ! $cluster ) {
                continue;
            }
            $cluster['primaries'] = array();
            foreach ( array_keys( (array) ( $cp[ $cluster['id'] ] ?? array() ) ) as $primary_id ) {
                $primary = $get_page( $primary_id );
                if ( ! $primary ) {
                    continue;
                }
                $primary['secondaries'] = array();
                foreach ( array_keys( (array) ( $ps[ $primary['id'] ] ?? array() ) ) as $secondary_id ) {
                    $secondary = $get_page( $secondary_id );
                    if ( ! $secondary ) {
                        continue;
                    }
                    $secondary['categories'] = array();
                    foreach ( array_keys( (array) ( $sc[ $secondary['id'] ] ?? array() ) ) as $term_id ) {
                        $category = $get_category( $term_id );
                        if ( $category ) {
                            $secondary['categories'][] = $category;
                        }
                    }
                    self::by_name( $secondary['categories'] );
                    $primary['secondaries'][] = $secondary;
                }
                self::by_name( $primary['secondaries'] );
                $cluster['primaries'][] = $primary;
            }
            self::by_name( $cluster['primaries'] );
            $tree[] = $cluster;
        }
        self::by_name( $tree );
        return $tree;
    }

    private static function button( $node, $kind ) {
        echo '<span class="tgb-h-status" data-status="idle">Sin consultar</span> ';
        echo '<button type="button" class="button button-small tgb-h-check"'
            . ' data-kind="' . esc_attr( $kind ) . '"'
            . ' data-id="' . esc_attr( (string) $node['id'] ) . '"'
            . ' data-query="' . esc_attr( $node['name'] ) . '">Ver tendencia</button>';
    }

    /**
     * The user can inspect the tree without launching any external request.
     */
    public static function render() {
        wp_enqueue_script(
            'taxocommerce-trends-hierarchy',
            TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/trends-hierarchy.js',
            array(),
            TAXOCOMMERCE_GOOGLE_BING_VERSION,
            true
        );
        wp_enqueue_style(
            'taxocommerce-trends-hierarchy',
            TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/trends-hierarchy.css',
            array(),
            TAXOCOMMERCE_GOOGLE_BING_VERSION
        );

        $tree = self::tree();
        $count = array( 'cluster' => 0, 'hub_primary' => 0, 'hub_secondary' => 0 );
        $unique = array();
        foreach ( $tree as $cluster ) {
            $unique['cluster:' . $cluster['id']] = true;
            foreach ( $cluster['primaries'] as $primary ) {
                $unique['hub_primary:' . $primary['id']] = true;
                foreach ( $primary['secondaries'] as $secondary ) {
                    $unique['hub_secondary:' . $secondary['id']] = true;
                }
            }
        }
        foreach ( array_keys( $unique ) as $key ) {
            $parts = explode( ':', $key );
            ++$count[ $parts[0] ];
        }

        echo '<section id="tgb-hierarchy" class="tgb-hierarchy" aria-label="Tendencias de la jerarquía SEO española">';
        echo '<h3>Radar de tendencias · jerarquía SEO (España)</h3>';
        echo '<p>Clúster → Hub primario → Hub secundario. El análisis general recorre únicamente estos tres niveles. ';
        echo 'Las categorías se consultan de una en una, bajo su hub secundario. Los datos de otros países están excluidos.</p>';
        echo '<p class="description"><strong>Límite de la fuente:</strong> Google facilita gráficos visuales, pero esta integración ';
        echo 'no dispone de series numéricas verificables. Por tanto no calcula crecimientos porcentuales ni clasifica oportunidades automáticamente. ';
        echo 'Que cargue un gráfico no prueba que Google haya devuelto datos válidos.</p>';

        if ( ! $tree ) {
            echo '<div class="notice notice-warning inline"><p>No se han encontrado clústeres españoles publicados en la jerarquía de Core. ';
            echo 'Comprueba que TaxoCommerce Core esté activo y que existan relaciones canónicas.</p></div>';
            echo '</section>';
            return;
        }

        echo '<div class="tgb-h-toolbar">';
        echo '<label for="tgb-h-period"><strong>Periodo</strong></label> ';
        echo '<select id="tgb-h-period">';
        foreach ( array(
            'today 1-m' => 'Últimos 30 días',
            'today 3-m' => 'Últimos 90 días',
            'today 12-m' => 'Últimos 12 meses',
            'today 5-y' => 'Últimos 5 años'
        ) as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, 'today 12-m', false ) . '>'
                . esc_html( $label ) . '</option>';
        }
        echo '</select> <strong>Región: España</strong>';
        echo '<span class="tgb-h-counts">' . absint( $count['cluster'] ) . ' clústeres · '
            . absint( $count['hub_primary'] ) . ' hubs primarios · '
            . absint( $count['hub_secondary'] ) . ' hubs secundarios</span>';
        echo '</div>';

        echo '<div class="tgb-h-manager">';
        echo '<label for="tgb-h-speed"><strong>Gestor de carga · velocidad <output id="tgb-h-speed-label">2</output>/5</strong></label>';
        echo '<input id="tgb-h-speed" type="range" min="1" max="5" value="2" step="1">';
        echo '<p class="description">Una consulta visual simultánea como máximo. Las cinco velocidades regulan las pausas entre consultas del navegador; ';
        echo 'no son una medición del porcentaje real de CPU del servidor.</p>';
        echo '<div class="tgb-h-actions">';
        echo '<button class="button button-primary" id="tgb-h-start" type="button">Iniciar análisis por niveles</button> ';
        echo '<button class="button" id="tgb-h-pause" type="button" disabled>Pausar</button> ';
        echo '<button class="button" id="tgb-h-resume" type="button" disabled>Reanudar</button> ';
        echo '<button class="button" id="tgb-h-stop" type="button" disabled>Detener</button>';
        echo '</div>';
        echo '<progress id="tgb-h-progress" max="100" value="0"></progress>';
        echo '<p id="tgb-h-state" role="status" aria-live="polite">Sin iniciar · 0 consultas</p>';
        echo '<details><summary>Registro del proceso</summary><ol id="tgb-h-log"></ol></details>';
        echo '</div>';

        echo '<div class="tgb-h-layout"><div class="tgb-h-tree" aria-label="Árbol SEO">';
        foreach ( $tree as $cluster ) {
            echo '<details class="tgb-h-cluster"><summary><strong>' . esc_html( $cluster['name'] ) . '</strong> · Clúster</summary>';
            echo '<div class="tgb-h-node" data-node="cluster:' . absint( $cluster['id'] ) . '">';
            self::button( $cluster, 'cluster' );
            foreach ( $cluster['primaries'] as $primary ) {
                echo '<details class="tgb-h-primary"><summary><strong>' . esc_html( $primary['name'] ) . '</strong> · Hub primario</summary>';
                echo '<div class="tgb-h-node" data-node="hub_primary:' . absint( $primary['id'] ) . '">';
                self::button( $primary, 'hub_primary' );
                foreach ( $primary['secondaries'] as $secondary ) {
                    echo '<details class="tgb-h-secondary"><summary><strong>' . esc_html( $secondary['name'] ) . '</strong> · Hub secundario</summary>';
                    echo '<div class="tgb-h-node" data-node="hub_secondary:' . absint( $secondary['id'] ) . '">';
                    self::button( $secondary, 'hub_secondary' );
                    if ( $secondary['categories'] ) {
                        echo '<div class="tgb-h-category">';
                        echo '<label for="tgb-h-cat-' . absint( $secondary['id'] ) . '">Consultar categoría (manual): </label>';
                        echo '<select id="tgb-h-cat-' . absint( $secondary['id'] ) . '" class="tgb-h-cat-select">';
                        echo '<option value="">Selecciona una categoría</option>';
                        foreach ( $secondary['categories'] as $cat ) {
                            echo '<option value="' . absint( $cat['id'] ) . '" data-query="' . esc_attr( $cat['name'] ) . '">'
                                . esc_html( $cat['name'] ) . '</option>';
                        }
                        echo '</select> ';
                        echo '<button class="button button-small tgb-h-cat-check" type="button">Consultar categoría</button>';
                        echo '</div>';
                    } else {
                        echo '<p class="description">Sin categorías españolas asignadas.</p>';
                    }
                    echo '</div></details>';
                }
                echo '</div></details>';
            }
            echo '</div></details>';
        }
        echo '</div>';

        echo '<section class="tgb-h-visual" aria-live="polite">';
        echo '<h4 id="tgb-h-chart-title">Selecciona una tendencia</h4>';
        echo '<p class="description">El gráfico de Google se carga únicamente al consultar un elemento o iniciar el análisis.</p>';
        echo '<a id="tgb-h-explore" class="button" target="_blank" rel="noopener noreferrer" href="https://trends.google.com/trends/explore?geo=ES" hidden>Abrir en Google Trends</a>';
        echo '<div id="tgb-h-chart"></div>';
        echo '<p class="description">Si Google impide insertar el gráfico, utiliza el enlace oficial. ';
        echo 'El estado «consultado» significa únicamente que se solicitó una visualización, no que exista un crecimiento confirmado.</p>';
        echo '</section></div></section>';
    }
}
