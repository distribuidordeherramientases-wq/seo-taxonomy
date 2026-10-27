<?php
/**
 * Three concise, evidence-backed reports for WordPress administrators.
 *
 * Prepared Intelligence and Analyst remain the source of truth. This
 * presentation layer makes no external requests or database writes.
 *
 * @package TaxoCommerceInsights
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Google_Bing_Board {

    const EXPORT_ACTION = 'tgb_export_board_report';

    public static function register() {
        add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function enqueue() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
        $page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
        $tab = isset( $_GET['tgb_tab'] ) && is_scalar( $_GET['tgb_tab'] ) ? sanitize_key( wp_unslash( $_GET['tgb_tab'] ) ) : 'informe';
        if ( 'taxocommerce-google-bing' !== $page || 'informe' !== $tab ) {
            return;
        }
        wp_enqueue_style( 'taxocommerce-insights-board', TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/board.css', array(), TAXOCOMMERCE_GOOGLE_BING_VERSION );
        wp_enqueue_script( 'taxocommerce-insights-board', TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/board.js', array(), TAXOCOMMERCE_GOOGLE_BING_VERSION, true );
    }

    public static function kinds() {
        return array(
            'situacion' => '1. Dónde estamos',
            'mercado'   => '2. Cómo está el mercado',
            'estrategia'=> '3. Hacia dónde debemos ir',
        );
    }

    private static function selection( $input ) {
        $value = sanitize_key( is_scalar( $input ) ? (string) $input : '' );
        return isset( self::kinds()[ $value ] ) ? $value : 'situacion';
    }

    /** Only reads previously saved local calculations. */
    public static function report( $kind = 'situacion' ) {
        $prepared = TaxoCommerce_Google_Bing_Prepared::current();
        $analyst  = TaxoCommerce_Google_Bing_Analyst::report();
        return self::from_data( $kind, $analyst, $prepared );
    }

    /**
     * Pure report formatter, public so evidence and missing-data rules can be
     * exercised without WordPress, a network connection or database fixtures.
     */
    public static function from_data( $kind, $analyst, $prepared = array() ) {
        $kind = self::selection( $kind );
        $analyst = (array) $analyst;
        $prepared = (array) $prepared;
        $sources = (array) ( $prepared['sources'] ?? array() );
        $where = (array) ( $analyst['where_we_are'] ?? array() );
        $overview = (array) ( $where['overview'] ?? array() );
        $market = (array) ( $analyst['market'] ?? array() );
        $direction = (array) ( $analyst['direction'] ?? array() );
        $commercial = (array) ( $analyst['commercial'] ?? array() );
        $diagnostic = (array) ( $analyst['diagnostic'] ?? array() );

        $context = array(
            'sources' => $sources,
            'overview' => $overview,
            'market' => $market,
            'direction' => $direction,
            'commercial' => $commercial,
            'diagnostic' => $diagnostic,
        );

        if ( 'mercado' === $kind ) {
            $body = self::market_report( $context );
        } elseif ( 'estrategia' === $kind ) {
            $body = self::strategy_report( $context );
        } else {
            $body = self::position_report( $context );
        }

        return array_merge(
            array(
                'schema' => array( 'name' => 'taxocommerce-insights-board', 'version' => 1 ),
                'kind' => $kind,
                'generated_at' => time(),
                'prepared_at' => absint( $prepared['prepared_at'] ?? $analyst['prepared_at'] ?? 0 ),
                'period' => self::period( $sources, $kind ),
                'sources' => self::source_receipts( $sources, $market ),
            ),
            $body
        );
    }

    private static function source_available( $data, $source, $fallback = false ) {
        if ( isset( $data['sources'][ $source ]['available'] ) ) {
            return ! empty( $data['sources'][ $source ]['available'] );
        }
        return (bool) $fallback;
    }

    private static function comparable( $data, $source ) {
        $previous = (array) ( $data['sources'][ $source ]['previous'] ?? array() );
        return ! empty( $previous['start'] ) && ! empty( $previous['end'] );
    }

    private static function metric( $name, $value, $previous = null, $format = 'integer' ) {
        return array(
            'label' => $name,
            'value' => null === $value ? null : (float) $value,
            'previous' => null === $previous ? null : (float) $previous,
            'format' => $format,
        );
    }

    private static function measured_metric( $data, $source_key, $label, $source, $key, $format = 'integer' ) {
        $available = self::source_available( $data, $source_key, ! empty( $source['available'] ) );
        $current = $available && isset( $source[ $key ] ) ? $source[ $key ] : null;
        $previous = self::comparable( $data, $source_key ) && isset( $source['previous'][ $key ] ) ? $source['previous'][ $key ] : null;
        return self::metric( $label, $current, $previous, $format );
    }

    private static function position_report( $data ) {
        $overview = $data['overview'];
        $gsc = (array) ( $overview['google_search'] ?? array() );
        $ga4 = (array) ( $overview['analytics'] ?? array() );
        $bing = (array) ( $overview['bing'] ?? array() );
        $gsc_ok = self::source_available( $data, 'search', ! empty( $gsc['available'] ) );
        $ga4_ok = self::source_available( $data, 'analytics', ! empty( $ga4['available'] ) );
        $bing_ok = self::source_available( $data, 'bing', ! empty( $bing['available'] ) );
        $metrics = array(
            self::measured_metric( $data, 'search', 'Clics orgánicos Google', $gsc, 'clicks' ),
            self::measured_metric( $data, 'search', 'Impresiones Google', $gsc, 'impressions' ),
            self::measured_metric( $data, 'analytics', 'Sesiones GA4', $ga4, 'sessions' ),
            self::measured_metric( $data, 'analytics', 'Compras registradas en GA4', $ga4, 'purchases' ),
            self::measured_metric( $data, 'bing', 'Clics Bing', $bing, 'clicks' ),
        );
        $insights = array();
        if ( $gsc_ok ) {
            $clicks = (int) ( $gsc['clicks'] ?? 0 );
            $prev = self::comparable( $data, 'search' ) ? ( $gsc['previous']['clicks'] ?? null ) : null;
            $insights[] = self::change_sentence( 'La captación orgánica en Google', $clicks, $prev );
            if ( isset( $gsc['position'] ) && (float) $gsc['position'] > 0 ) {
                $insights[] = 'La posición media observada es ' . number_format( (float) $gsc['position'], 1, ',', '.' ) . '. Es un promedio, no el puesto de todas las consultas.';
            }
        } else {
            $insights[] = 'No hay una descarga válida de Search Console: no se puede evaluar la evolución de visibilidad SEO.';
        }
        if ( $ga4_ok ) {
            $insights[] = 'GA4 registra ' . number_format( (int) ( $ga4['sessions'] ?? 0 ), 0, ',', '.' ) . ' sesiones y ' . number_format( (int) ( $ga4['purchases'] ?? 0 ), 0, ',', '.' ) . ' eventos de compra en el período guardado.';
        } else {
            $insights[] = 'No hay un informe GA4 válido para valorar sesiones y compras medidas.';
        }
        if ( $bing_ok && count( $insights ) < 3 ) {
            $insights[] = 'Bing aporta ' . number_format( (int) ( $bing['clicks'] ?? 0 ), 0, ',', '.' ) . ' clics medidos; se mantiene separado de Google.';
        }
        $status = $gsc_ok && $ga4_ok ? 'available' : ( $gsc_ok || $ga4_ok || $bing_ok ? 'partial' : 'unavailable' );
        return array(
            'title' => 'Dónde estamos',
            'subtitle' => 'Situación actual de visibilidad, visitas y actividad comercial medida.',
            'status' => $status,
            'assessment' => 'available' === $status
                ? 'Hay datos propios suficientes para una lectura inicial de captación y actividad. Compara cada KPI con su período anterior solo cuando esté guardado.'
                : ( 'partial' === $status
                    ? 'Diagnóstico parcial: faltan fuentes esenciales. Las cifras disponibles no justifican una conclusión global sobre el negocio.'
                    : 'No hay mediciones suficientes para diagnosticar la situación de la web.' ),
            'metrics' => $metrics,
            'insights' => array_slice( $insights, 0, 3 ),
            'recommendations' => array(),
            'caveats' => array(
                'Las compras de GA4 son eventos medidos; no prueban facturación neta ni ventas atribuidas a afiliación.',
                'Los resultados de España (tienda) y de países de afiliación deben contrastarse por separado en Analytics antes de atribuir conversiones.',
            ),
            'details' => array( 'analyst' => 'Diagnóstico completo', 'google_search' => 'Search Console', 'google_analytics' => 'Analytics', 'bing' => 'Bing' ),
        );
    }

    private static function change_sentence( $label, $current, $previous ) {
        if ( null === $previous ) {
            return $label . ': ' . number_format( $current, 0, ',', '.' ) . ' clics registrados, sin período anterior comparable.';
        }
        if ( (int) $current === (int) $previous ) {
            return $label . ' permanece estable respecto al período anterior (' . number_format( $current, 0, ',', '.' ) . ' clics).';
        }
        if ( 0 === (int) $previous ) {
            return $label . ': ' . number_format( $current, 0, ',', '.' ) . ' clics frente a cero observado anteriormente; el crecimiento porcentual no es calculable.';
        }
        $delta = round( 100 * ( $current - $previous ) / $previous, 1 );
        return $label . ( $delta > 0 ? ' sube un ' : ' baja un ' ) . number_format( abs( $delta ), 1, ',', '.' ) . '% respecto al período anterior.';
    }

    private static function market_report( $data ) {
        $market = $data['market'];
        $ready = ! empty( $market['available'] );
        $summary = (array) ( $market['summary'] ?? array() );
        $rollup = (array) ( $market['global_rollup'] ?? array() );
        $coverage = (array) ( $rollup['coverage'] ?? array() );
        $intelligence = (array) ( $rollup['product_intelligence'] ?? array() );
        $total = $ready ? absint( $coverage['total'] ?? $summary['categories'] ?? 0 ) : 0;
        $done = $ready ? absint( $coverage['complete'] ?? 0 ) + absint( $coverage['partial'] ?? 0 ) : 0;
        if ( $ready && 0 === $done ) {
            $done = absint( $summary['analyzed_categories'] ?? 0 );
        }
        $external = $ready ? absint( $summary['external_evidence'] ?? 0 ) : null;
        $merchant = $ready ? absint( $summary['merchant_signals'] ?? 0 ) : null;
        $gaps = array_values( (array) ( $rollup['catalog_gaps'] ?? array() ) );
        $domains = array_values( (array) ( $market['competitors'] ?? array() ) );
        $metrics = array(
            self::metric( 'Categorías investigadas', $ready ? $done : null ),
            self::metric( 'Cobertura de categorías', $total > 0 ? round( 100 * $done / $total, 1 ) : null, null, 'percent' ),
            self::metric( 'Señales externas guardadas', $external ),
            self::metric( 'Señales Merchant', $merchant ),
            self::metric( 'Productos / clusters observados', $ready ? absint( $intelligence['unique_products'] ?? 0 ) : null ),
        );
        $insights = array();
        if ( $ready ) {
            if ( $total > 0 ) {
                $insights[] = 'La investigación cubre ' . $done . ' de ' . $total . ' categorías registradas. Las categorías sin analizar no representan un mercado sin actividad.';
            }
            if ( $external > 0 ) {
                $insights[] = 'Se han registrado ' . number_format( $external, 0, ',', '.' ) . ' señales externas y ' . number_format( $merchant, 0, ',', '.' ) . ' observaciones Merchant, que reflejan evidencia recopilada, no ventas de terceros.';
            } else {
                $insights[] = 'Todavía no hay señales externas suficientes para describir la competencia con fundamento.';
            }
            if ( ! empty( $gaps[0]['category'] ) && absint( $gaps[0]['product_signals'] ?? 0 ) > 0 ) {
                $gap = $gaps[0];
                $insights[] = 'La categoría «' . sanitize_text_field( $gap['category'] ) . '» aparece como posible brecha de catálogo en el muestreo (' . absint( $gap['product_signals'] ) . ' señales de producto). Requiere validación comercial.';
            } elseif ( ! empty( $domains[0]['domain'] ) ) {
                $insights[] = 'El dominio ' . sanitize_text_field( $domains[0]['domain'] ) . ' aparece en ' . absint( $domains[0]['mentions'] ?? 0 ) . ' observaciones del conjunto investigado; no es un ranking competitivo.';
            }
        } else {
            $insights[] = 'Market no dispone de investigación válida guardada. No se puede determinar el estado del mercado ni de los competidores.';
        }
        $status = ! $ready ? 'unavailable' : ( $external > 0 ? 'available' : 'partial' );
        return array(
            'title' => 'Cómo está el mercado',
            'subtitle' => 'Tendencias, competencia observada y posibles huecos del catálogo.',
            'status' => $status,
            'assessment' => 'available' === $status
                ? 'Hay evidencia externa para identificar señales y áreas de investigación. No es una medición exhaustiva del mercado.'
                : ( 'partial' === $status ? 'El inventario de categorías existe, pero falta evidencia externa suficiente.' : 'Sin investigación Market guardada no es posible formular conclusiones competitivas.' ),
            'metrics' => $metrics,
            'insights' => array_slice( $insights, 0, 3 ),
            'recommendations' => array(),
            'caveats' => array(
                'Merchant Best Sellers muestra demanda relativa; no informa de unidades vendidas, precios equivalentes ni cuota de mercado.',
                'La presencia de dominios, marcas y vendedores depende del muestreo y fecha de las fuentes. No equivale a liderazgo de mercado.',
            ),
            'details' => array( 'mercado' => 'Investigación Market', 'analyst' => 'Lectura del Analista', 'google_trends' => 'Google Trends' ),
        );
    }

    private static function strategy_report( $data ) {
        $direction = $data['direction'];
        $market = $data['market'];
        $gsc = (array) ( $data['overview']['google_search'] ?? array() );
        $gsc_ok = self::source_available( $data, 'search', ! empty( $gsc['available'] ) );
        $rows = array();
        $seen = array();

        foreach ( (array) ( $direction['rows'] ?? array() ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $bucket = strtoupper( (string) ( $item['bucket'] ?? '' ) );
            if ( ! in_array( $bucket, array( 'NOW', 'NEXT', 'INVESTIGATE', 'WATCH' ), true ) ) {
                continue;
            }
            if ( 'NOW' === $bucket && ( ! $gsc_ok || empty( $item['demand_backed'] ) || empty( $item['execution_ready'] ) ) ) {
                $bucket = 'INVESTIGATE';
            }
            $title = sanitize_text_field( (string) ( $item['topic'] ?? '' ) );
            $action = sanitize_text_field( (string) ( $item['action_label'] ?? $item['action'] ?? '' ) );
            $key = strtolower( $title . '|' . $action );
            if ( '' === $title || isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $target = (array) ( $item['target'] ?? array() );
            $rows[] = array(
                'bucket' => $bucket,
                'priority' => min( 100, absint( $item['priority'] ?? 0 ) ),
                'action' => $action,
                'title' => $title,
                'reason' => sanitize_text_field( (string) ( $item['reason'] ?? '' ) ),
                'confidence' => sanitize_key( (string) ( $item['confidence'] ?? 'unspecified' ) ),
                'evidence' => array_slice( array_values( array_map( 'sanitize_text_field', (array) ( $item['evidence'] ?? array() ) ) ), 0, 2 ),
                'sources' => array_slice( array_values( array_map( 'sanitize_text_field', (array) ( $item['sources'] ?? array() ) ) ), 0, 3 ),
                'target' => array(
                    'title' => sanitize_text_field( (string) ( $target['title'] ?? '' ) ),
                    'url' => esc_url_raw( (string) ( $target['url'] ?? '' ) ),
                ),
            );
        }
        $order = array( 'NOW' => 0, 'NEXT' => 1, 'INVESTIGATE' => 2, 'WATCH' => 3 );
        usort( $rows, static function ( $a, $b ) use ( $order ) {
            $first = $order[ $a['bucket'] ] <=> $order[ $b['bucket'] ];
            return 0 !== $first ? $first : $b['priority'] <=> $a['priority'];
        } );
        $rows = array_slice( $rows, 0, 5 );
        $counts = array( 'NOW' => 0, 'NEXT' => 0, 'INVESTIGATE' => 0, 'WATCH' => 0 );
        foreach ( $rows as $row ) {
            $counts[ $row['bucket'] ]++;
        }
        $insights = array();
        if ( $rows ) {
            $insights[] = 'Hay ' . count( $rows ) . ' propuestas prioritarias en esta síntesis, ordenadas por evidencia y urgencia, no por una previsión garantizada de ingresos.';
            $insights[] = $counts['NOW'] . ' pueden revisarse ahora con evidencia directa; ' . ( $counts['NEXT'] + $counts['INVESTIGATE'] + $counts['WATCH'] ) . ' requieren programación, comprobación o seguimiento.';
        } else {
            $insights[] = 'No hay propuestas específicas suficientemente sustentadas en los análisis guardados. Es necesario completar las mediciones antes de priorizar cambios.';
        }
        if ( ! $gsc_ok ) {
            $insights[] = 'Sin Search Console válido no es posible dar prioridad inmediata a acciones SEO basadas en demanda propia.';
        } elseif ( empty( $market['available'] ) ) {
            $insights[] = 'Las propuestas con contexto competitivo necesitan contrastarse con Market antes de tomar decisiones de catálogo.';
        }
        $state = $rows ? ( $gsc_ok ? 'available' : 'partial' ) : 'unavailable';
        return array(
            'title' => 'Hacia dónde debemos ir',
            'subtitle' => 'Prioridades propuestas tras cruzar resultados propios y evidencia de mercado.',
            'status' => $state,
            'assessment' => $rows
                ? 'Este es un orden de revisión y decisión, no una cola de ejecución automática. Cada propuesta conserva su evidencia y su destino.'
                : 'No es responsable recomendar inversiones, publicaciones o cambios de catálogo sin señales suficientes.',
            'metrics' => array(
                self::metric( 'Propuestas seleccionadas', count( $rows ) ),
                self::metric( 'Para revisar ahora', $counts['NOW'] ),
                self::metric( 'Siguientes', $counts['NEXT'] ),
                self::metric( 'Por investigar', $counts['INVESTIGATE'] ),
                self::metric( 'En observación', $counts['WATCH'] ),
            ),
            'insights' => array_slice( $insights, 0, 3 ),
            'recommendations' => $rows,
            'caveats' => array(
                'La puntuación de prioridad es interna y el resultado esperado es una hipótesis, no una previsión de ventas ni rentabilidad.',
                'Un clic de afiliación no demuestra una compra. Ninguna recomendación publica, elimina o modifica contenidos automáticamente.',
            ),
            'details' => array( 'analyst' => 'Plan y evidencias del Analista', 'mercado' => 'Market', 'google_search' => 'Search Console' ),
        );
    }

    private static function period( $sources, $kind ) {
        $source = 'mercado' === $kind ? 'market' : 'search';
        $meta = (array) ( $sources[ $source ] ?? array() );
        $current = (array) ( $meta['current'] ?? array() );
        if ( ! empty( $current['start'] ) && ! empty( $current['end'] ) ) {
            return sanitize_text_field( (string) $current['start'] . ' a ' . (string) $current['end'] );
        }
        if ( 'mercado' === $kind && ! empty( $meta['updated_at'] ) ) {
            return 'Última evidencia: ' . sanitize_text_field( (string) $meta['updated_at'] );
        }
        return 'Período no disponible';
    }

    private static function source_receipts( $sources, $market ) {
        $receipts = array();
        foreach ( array( 'search' => 'Google Search', 'analytics' => 'GA4', 'bing' => 'Bing', 'trends' => 'Trends', 'market' => 'Market' ) as $key => $label ) {
            $meta = (array) ( $sources[ $key ] ?? array() );
            $available = ! empty( $meta['available'] );
            if ( 'market' === $key && ! $available ) {
                $available = ! empty( $market['available'] );
            }
            $receipts[] = array(
                'name' => $label,
                'available' => $available,
                'updated_at' => absint( $meta['downloaded_at'] ?? 0 ),
                'updated_label' => sanitize_text_field( (string) ( $meta['updated_at'] ?? '' ) ),
            );
        }
        return $receipts;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'taxocommerce-google-bing' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report selector.
        $selected = self::selection( $_GET['tgb_report'] ?? 'situacion' );
        $report = self::report( $selected );
        echo '<div class="tgb-board">';
        echo '<nav class="tgb-board-tabs" aria-label="Tres informes de dirección">';
        foreach ( self::kinds() as $kind => $title ) {
            $url = self::page_url( $kind );
            echo '<a href="' . esc_url( $url ) . '" class="' . ( $kind === $selected ? 'is-selected' : '' ) . '" ' . ( $kind === $selected ? 'aria-current="page"' : '' ) . '>' . esc_html( $title ) . '</a>';
        }
        echo '</nav>';

        $labels = array( 'available' => 'Datos disponibles', 'partial' => 'Información parcial', 'unavailable' => 'Sin datos suficientes' );
        echo '<article class="tgb-board-document">';
        echo '<header class="tgb-board-head"><div>';
        echo '<p class="tgb-board-overline">INFORME EJECUTIVO · ' . esc_html( strtoupper( $selected ) ) . '</p>';
        echo '<h2>' . esc_html( $report['title'] ) . '</h2><p>' . esc_html( $report['subtitle'] ) . '</p>';
        echo '<p class="tgb-board-period">Período: ' . esc_html( $report['period'] ) . ' · ' . esc_html( $labels[ $report['status'] ] ) . '</p>';
        echo '</div><div class="tgb-board-actions">';
        echo '<button type="button" class="button tgb-board-print">Imprimir / Guardar PDF</button>';
        echo '<a class="button button-primary" href="' . esc_url( self::export_url( $selected ) ) . '">Exportar informe JSON</a>';
        echo '</div></header>';

        echo '<section class="tgb-board-reading"><h3>Resumen ejecutivo</h3><p>' . esc_html( $report['assessment'] ) . '</p></section>';
        if ( ! empty( $report['metrics'] ) ) {
            echo '<section class="tgb-board-section"><h3>Indicadores clave</h3><div class="tgb-board-metrics">';
            foreach ( $report['metrics'] as $metric ) {
                $value = $metric['value'];
                echo '<div class="tgb-board-metric"><span>' . esc_html( $metric['label'] ) . '</span><strong>' . ( null === $value ? '&mdash;' : esc_html( self::format_metric( $value, $metric['format'] ) ) ) . '</strong>';
                if ( null !== $value && null !== $metric['previous'] ) {
                    echo '<small>Anterior: ' . esc_html( self::format_metric( $metric['previous'], $metric['format'] ) ) . '</small>';
                } elseif ( null === $value ) {
                    echo '<small>No disponible</small>';
                }
                echo '</div>';
            }
            echo '</div></section>';
        }

        echo '<section class="tgb-board-section"><h3>Conclusiones principales</h3><ol class="tgb-board-findings">';
        foreach ( $report['insights'] as $insight ) {
            echo '<li>' . esc_html( $insight ) . '</li>';
        }
        echo '</ol></section>';

        if ( 'estrategia' === $selected ) {
            echo '<section class="tgb-board-section"><h3>Decisiones propuestas</h3>';
            if ( ! $report['recommendations'] ) {
                echo '<p>Sin decisiones contrastadas por el momento. Revisa los informes de las fuentes.</p>';
            }
            foreach ( $report['recommendations'] as $item ) {
                echo '<div class="tgb-board-recommendation"><div class="tgb-board-reco-title"><strong>' . esc_html( $item['title'] ) . '</strong><span>' . esc_html( $item['bucket'] ) . ' · ' . esc_html( (string) $item['priority'] ) . '/100</span></div>';
                echo '<p><b>' . esc_html( $item['action'] ) . '</b> — ' . esc_html( $item['reason'] ) . '</p>';
                if ( $item['evidence'] ) {
                    echo '<small><b>Evidencia:</b> ' . esc_html( implode( ' · ', $item['evidence'] ) ) . '</small>';
                } else {
                    echo '<small>Evidencia específica no detallada; requiere comprobación.</small>';
                }
                if ( ! empty( $item['target']['url'] ) ) {
                    echo '<a href="' . esc_url( $item['target']['url'] ) . '" target="_blank" rel="noopener noreferrer">Ver destino</a>';
                }
                echo '</div>';
            }
            echo '</section>';
        }

        echo '<div class="tgb-board-footer">';
        echo '<details><summary>Fuentes, fecha y límites de la interpretación</summary>';
        echo '<p>Informe generado: ' . esc_html( wp_date( 'd/m/Y H:i', (int) $report['generated_at'] ) ) . '. Datos preparados: ' . ( $report['prepared_at'] ? esc_html( wp_date( 'd/m/Y H:i', (int) $report['prepared_at'] ) ) : 'no disponible' ) . '.</p>';
        echo '<ul>';
        foreach ( $report['sources'] as $source ) {
            $date = $source['updated_at'] ? wp_date( 'd/m/Y H:i', (int) $source['updated_at'] ) : $source['updated_label'];
            echo '<li>' . esc_html( $source['name'] ) . ': ' . ( $source['available'] ? 'datos guardados' : 'sin datos' ) . ( $date ? ' · ' . esc_html( $date ) : '' ) . '</li>';
        }
        foreach ( $report['caveats'] as $caveat ) {
            echo '<li>' . esc_html( $caveat ) . '</li>';
        }
        echo '</ul></details>';
        echo '<p class="tgb-board-detail-links"><b>Para profundizar:</b> ';
        foreach ( $report['details'] as $tab => $label ) {
            echo '<a href="' . esc_url( add_query_arg( array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => $tab ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a> ';
        }
        echo '<a href="' . esc_url( add_query_arg( array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'informe_datos' ), admin_url( 'admin.php' ) ) ) . '">Informe de datos completo</a></p>';
        echo '</div></article></div>';
    }

    private static function format_metric( $value, $format ) {
        if ( 'percent' === $format ) {
            return number_format_i18n( (float) $value, 1 ) . '%';
        }
        return number_format_i18n( (float) $value, 'decimal' === $format ? 1 : 0 );
    }

    private static function page_url( $kind ) {
        return add_query_arg(
            array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => 'informe', 'tgb_report' => $kind ),
            admin_url( 'admin.php' )
        );
    }

    private static function export_url( $kind ) {
        return wp_nonce_url(
            add_query_arg( array( 'action' => self::EXPORT_ACTION, 'tgb_report' => $kind ), admin_url( 'admin-post.php' ) ),
            'tgb_export_board_report_' . $kind
        );
    }

    public static function export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized access.', 'taxocommerce-google-bing' ) );
        }
        $kind = self::selection( $_GET['tgb_report'] ?? '' );
        check_admin_referer( 'tgb_export_board_report_' . $kind );
        $report = self::report( $kind );
        nocache_headers();
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="taxocommerce-direccion-' . $kind . '-' . gmdate( 'Ymd-His' ) . '.json"' );
        echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
        exit;
    }
}
