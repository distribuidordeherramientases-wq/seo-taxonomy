<?php
/**
 * Spain-only hierarchical Google Trends explorer.
 *
 * The canonical TaxoCommerce Core maps determine membership. No Trends
 * comparison charts load automatically when the Trends tab opens, paced
 * sequentially in the browser. The embeds do not expose numerical interest series.
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


    /**
     * Exactly one comparison for clusters (up to Google's five-term limit)
     * and one comparison per cluster's primary hubs. More than five terms
     * produce separately normalized chunks, without implying comparability
     * between different charts. This list is always rebuilt from Core.
     *
     * @param array $tree Canonical Spanish-only hierarchy.
     * @return array
     */
    public static function comparison_groups( $tree ) {
        $clusters = array();
        $groups   = array();

        foreach ( (array) $tree as $cluster ) {
            $id = absint( $cluster['id'] ?? 0 );
            $name = trim( (string) ( $cluster['name'] ?? '' ) );
            if ( ! $id || '' === $name ) {
                continue;
            }
            $clusters[ $id ] = $name;
        }
        $slices = array_chunk( array_values( $clusters ), 5 );
        foreach ( $slices as $index => $terms ) {
            $groups[] = array(
                'kind' => 'cluster',
                'title' => count( $slices ) > 1
                    ? 'Tendencia comparada de clústeres · grupo ' . ( $index + 1 ) . '/' . count( $slices )
                    : 'Tendencia comparada de todos los clústeres',
                'terms' => $terms,
            );
        }

        foreach ( (array) $tree as $cluster ) {
            $primaries = array();
            foreach ( (array) ( $cluster['primaries'] ?? array() ) as $primary ) {
                $primary_id = absint( $primary['id'] ?? 0 );
                $name = trim( (string) ( $primary['name'] ?? '' ) );
                if ( $primary_id && '' !== $name ) {
                    $primaries[ $primary_id ] = $name;
                }
            }
            $slices = array_chunk( array_values( $primaries ), 5 );
            if ( ! $slices ) {
                $groups[] = array(
                    'kind' => 'hub_primary',
                    'title' => 'Hubs primarios · ' . (string) $cluster['name'],
                    'terms' => array(),
                );
                continue;
            }
            foreach ( $slices as $index => $terms ) {
                $groups[] = array(
                    'kind' => 'hub_primary',
                    'title' => 'Hubs primarios · ' . (string) $cluster['name']
                        . ( count( $slices ) > 1 ? ' · grupo ' . ( $index + 1 ) . '/' . count( $slices ) : '' ),
                    'terms' => $terms,
                );
            }
        }
        return $groups;
    }

    private static function render_comparisons( $tree ) {
        $groups = self::comparison_groups( $tree );
        echo '<section id="tgb-h-comparisons" class="tgb-h-comparisons" aria-label="Gráficos comparativos automáticos de España">';
        echo '<h3>' . esc_html__( 'Tendencias de clústeres y hubs primarios', 'taxocommerce-google-bing' ) . '</h3>';
        echo '<p>' . esc_html__( 'Los gráficos se solicitan automáticamente al abrir esta pestaña. Cada gráfico compara los nombres actuales de la', 'taxocommerce-google-bing' ) . ' ';
        echo esc_html__( 'jerarquía española, sin consultar hubs secundarios ni categorías. La carga es secuencial y puedes detenerla.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<p class="description">' . esc_html__( 'Google Trends expresa interés relativo (0–100), no volumen absoluto.', 'taxocommerce-google-bing' ) . ' ';
        echo esc_html__( 'Un gráfico vacío o que no se incrusta no demuestra falta de demanda, ni que el nombre esté mal definido.', 'taxocommerce-google-bing' ) . ' ';
        echo esc_html__( 'Revisa las consultas en Google Trends antes de cambiar nombres de SEO.', 'taxocommerce-google-bing' ) . '</p>';
        foreach ( $groups as $index => $group ) {
            $terms = array_values( (array) $group['terms'] );
            $json = wp_json_encode( $terms, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
            echo '<article class="tgb-h-compare-card" data-terms="' . esc_attr( $json ) . '" data-kind="'
                . esc_attr( $group['kind'] ) . '" data-index="' . absint( $index ) . '">';
            echo '<h4>' . esc_html( $group['title'] ) . '</h4>';
            if ( ! $terms ) {
                echo '<p class="description">' . esc_html__( 'Este clúster no tiene hubs primarios españoles publicados y relacionados.', 'taxocommerce-google-bing' ) . '</p>';
            } else {
                echo '<p class="tgb-h-compare-terms">Términos: ' . esc_html( implode( ' · ', $terms ) ) . '</p>';
                echo '<p class="tgb-h-compare-state" role="status">' . esc_html__( 'Pendiente de consultar', 'taxocommerce-google-bing' ) . '</p>';
                echo '<a href="https://trends.google.com/trends/explore?geo=ES" class="button button-secondary tgb-h-compare-link" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Abrir esta comparación en Google Trends', 'taxocommerce-google-bing' ) . '</a>';
                echo '<div class="tgb-h-compare-chart" aria-label="Gráfico de Google Trends pendiente"></div>';
            }
            echo '</article>';
        }
        if ( count( $clusters = array_filter( $groups, static function ( $group ) { return 'cluster' === $group['kind']; } ) ) > 1 ) {
            echo '<p class="description">' . esc_html__( 'Se han dividido los clústeres en varios gráficos por el límite de cinco términos de Google Trends.', 'taxocommerce-google-bing' ) . ' ';
            echo esc_html__( 'Las escalas 0–100 de gráficos distintos no son directamente comparables.', 'taxocommerce-google-bing' ) . '</p>';
        }
        echo '</section>';
    }

    private static function button( $node, $kind ) {
        echo '<span class="tgb-h-status" data-status="idle">' . esc_html__( 'Sin consultar', 'taxocommerce-google-bing' ) . '</span>' . ' ';
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
        echo '<h3>' . esc_html__( 'Radar de tendencias · jerarquía SEO (España)', 'taxocommerce-google-bing' ) . '</h3>';
        echo '<p>' . esc_html__( 'Informe automático de clústeres y hubs primarios españoles. Debajo encontrarás la exploración opcional', 'taxocommerce-google-bing' ) . ' ';
        echo esc_html__( 'de hubs secundarios y categorías, sin consultas automáticas de esos niveles.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<p class="description">' . '<strong>' . esc_html__( 'Límite de la fuente:', 'taxocommerce-google-bing' ) . '</strong>' . ' ' . esc_html__( 'Google facilita gráficos visuales, pero esta integración', 'taxocommerce-google-bing' ) . ' ';
        echo esc_html__( 'no dispone de series numéricas verificables. Por tanto no calcula crecimientos porcentuales ni clasifica oportunidades automáticamente.', 'taxocommerce-google-bing' ) . ' ';
        echo esc_html__( 'Que cargue un gráfico no prueba que Google haya devuelto datos válidos.', 'taxocommerce-google-bing' ) . '</p>';

        if ( ! $tree ) {
            echo '<div class="notice notice-warning inline">' . '<p>' . esc_html__( 'No se han encontrado clústeres españoles publicados en la jerarquía de Core.', 'taxocommerce-google-bing' ) . ' ';
            echo esc_html__( 'Comprueba que TaxoCommerce Core esté activo y que existan relaciones canónicas.', 'taxocommerce-google-bing' ) . '</p>' . '</div>';
            echo '</section>';
            return;
        }

        echo '<div class="tgb-h-toolbar">';
        echo '<label for="tgb-h-period">' . '<strong>' . esc_html__( 'Periodo', 'taxocommerce-google-bing' ) . '</strong>' . '</label>' . ' ';
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
        echo '</select>' . ' ' . '<strong>' . esc_html__( 'Región: España', 'taxocommerce-google-bing' ) . '</strong>';
        echo '<span class="tgb-h-counts">' . absint( $count['cluster'] ) . ' clústeres · '
            . absint( $count['hub_primary'] ) . ' hubs primarios · '
            . absint( $count['hub_secondary'] ) . ' hubs secundarios</span>';
        echo '</div>';

        echo '<div class="tgb-h-manager">';
        echo '<label for="tgb-h-speed">' . '<strong>' . esc_html__( 'Gestor de carga · velocidad', 'taxocommerce-google-bing' ) . ' ' . '<output id="tgb-h-speed-label">' . '3' . '</output>' . '/5' . '</strong>' . '</label>';
        echo '<input id="tgb-h-speed" type="range" min="1" max="5" value="3" step="1">';
        echo '<p class="description">' . esc_html__( 'Los gráficos se solicitan por orden, a un ritmo controlado. Las cinco velocidades regulan las pausas entre consultas del navegador;', 'taxocommerce-google-bing' ) . ' ';
        echo esc_html__( 'no son una medición del porcentaje real de CPU del servidor.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<div class="tgb-h-actions">';
        echo '<button class="button button-primary" id="tgb-h-start" type="button">' . esc_html__( 'Actualizar gráficos', 'taxocommerce-google-bing' ) . '</button>' . ' ';
        echo '<button class="button" id="tgb-h-pause" type="button" disabled>' . esc_html__( 'Pausar', 'taxocommerce-google-bing' ) . '</button>' . ' ';
        echo '<button class="button" id="tgb-h-resume" type="button" disabled>' . esc_html__( 'Reanudar', 'taxocommerce-google-bing' ) . '</button>' . ' ';
        echo '<button class="button" id="tgb-h-stop" type="button" disabled>' . esc_html__( 'Detener', 'taxocommerce-google-bing' ) . '</button>';
        echo '</div>';
        echo '<progress id="tgb-h-progress" max="100" value="0"></progress>';
        echo '<p id="tgb-h-state" role="status" aria-live="polite">' . esc_html__( 'Preparando comparaciones automáticas…', 'taxocommerce-google-bing' ) . '</p>';
        echo '<details>' . '<summary>' . esc_html__( 'Registro del proceso', 'taxocommerce-google-bing' ) . '</summary>' . '<ol id="tgb-h-log">' . '</ol>' . '</details>';
        echo '</div>';

        self::render_comparisons( $tree );

        echo '<details class="tgb-h-advanced">' . '<summary>' . '<strong>' . esc_html__( 'Exploración detallada opcional · hubs secundarios y categorías', 'taxocommerce-google-bing' ) . '</strong>' . '</summary>';
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
                        echo '<option value="">' . esc_html__( 'Selecciona una categoría', 'taxocommerce-google-bing' ) . '</option>';
                        foreach ( $secondary['categories'] as $cat ) {
                            echo '<option value="' . absint( $cat['id'] ) . '" data-query="' . esc_attr( $cat['name'] ) . '">'
                                . esc_html( $cat['name'] ) . '</option>';
                        }
                        echo '</select> ';
                        echo '<button class="button button-small tgb-h-cat-check" type="button">' . esc_html__( 'Consultar categoría', 'taxocommerce-google-bing' ) . '</button>';
                        echo '</div>';
                    } else {
                        echo '<p class="description">' . esc_html__( 'Sin categorías españolas asignadas.', 'taxocommerce-google-bing' ) . '</p>';
                    }
                    echo '</div></details>';
                }
                echo '</div></details>';
            }
            echo '</div></details>';
        }
        echo '</div>';

        echo '<section class="tgb-h-visual" aria-live="polite">';
        echo '<h4 id="tgb-h-chart-title">' . esc_html__( 'Selecciona una tendencia', 'taxocommerce-google-bing' ) . '</h4>';
        echo '<p class="description">' . esc_html__( 'El gráfico de Google se carga únicamente al consultar un elemento o iniciar el análisis.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<a id="tgb-h-explore" class="button" target="_blank" rel="noopener noreferrer" href="https://trends.google.com/trends/explore?geo=ES" hidden>' . esc_html__( 'Abrir en Google Trends', 'taxocommerce-google-bing' ) . '</a>';
        echo '<div id="tgb-h-chart"></div>';
        echo '<p class="description">' . esc_html__( 'Si Google impide insertar el gráfico, utiliza el enlace oficial.', 'taxocommerce-google-bing' ) . ' ';
        echo esc_html__( 'El estado «consultado» significa únicamente que se solicitó una visualización, no que exista un crecimiento confirmado.', 'taxocommerce-google-bing' ) . '</p>';
        echo '</section></div></details></section>';
    }
}
