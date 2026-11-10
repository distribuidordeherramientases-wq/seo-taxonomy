<?php
/**
 * Compact executive reading of already downloaded provider reports.
 *
 * No outbound requests, no new copies of snapshots and no recalculation of
 * the Analista action portfolio occur when rendering this screen.
 *
 * @package TaxoCommerceInsights
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_Executive {
    /** Allowed report sources and their existing admin tabs. */
    private const SOURCES = array(
        'search'    => 'google_search',
        'analytics' => 'google_analytics',
        'bing'      => 'bing',
    );

    /** Read only the provider's saved reports, never its download method. */
    public static function snapshot( $source ) {
        if ( ! isset( self::SOURCES[ $source ] ) || ! class_exists( 'TaxoCommerce_Google_Bing_Reports' ) ) {
            return null;
        }
        $reports = TaxoCommerce_Google_Bing_Reports::saved_reports( $source );
        $latest = null;
        foreach ( (array) $reports as $item ) {
            if ( ! is_array( $item ) || ! isset( $item['data'], $item['current']['start'], $item['current']['end'], $item['previous']['start'], $item['previous']['end'] ) ) {
                continue;
            }
            if ( null === $latest || (int) ( $item['downloaded_at'] ?? 0 ) > (int) ( $latest['downloaded_at'] ?? 0 ) ) {
                $latest = $item;
            }
        }
        return $latest;
    }

    /** Neutral until both valid, comparable numbers are present. */
    public static function movement( $current, $previous, $minimum = 10 ) {
        if ( ! is_numeric( $current ) || ! is_numeric( $previous ) || (float) $previous < $minimum ) {
            return null;
        }
        $change = ( (float) $current - (float) $previous ) / (float) $previous * 100;
        return array( 'value' => round( $change, 1 ), 'trend' => abs( $change ) < 5 ? 'stable' : ( $change > 0 ? 'up' : 'down' ) );
    }

    private static function link( $tab ) {
        return add_query_arg( array( 'page' => 'taxocommerce-google-bing', 'tgb_tab' => $tab ), admin_url( 'admin.php' ) );
    }

    /** A valid source does not become zero merely because a metric is missing. */
    private static function metric( $data, $path ) {
        foreach ( $path as $key ) {
            if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
                return null;
            }
            $data = $data[ $key ];
        }
        return is_numeric( $data ) ? (float) $data : null;
    }

    public static function prepare( $snapshots, $history = array() ) {
        $sources = array(
            'search'    => array( 'label' => 'Google Search Console', 'tab' => 'google_search', 'available' => false ),
            'analytics' => array( 'label' => 'Google Analytics 4', 'tab' => 'google_analytics', 'available' => false ),
            'bing'      => array( 'label' => 'Bing Webmaster', 'tab' => 'bing', 'available' => false ),
        );
        $signals = array();
        $periods = array();
        $map = array(
            'search'    => array( 'clicks', 'impressions' ),
            'analytics' => array( 'sessions', 'ecommercePurchases' ),
            'bing'      => array( 'clicks', 'impressions' ),
        );
        foreach ( $sources as $source => &$entry ) {
            $snap = $snapshots[ $source ] ?? null;
            if ( ! is_array( $snap ) ) {
                continue;
            }
            $age = isset( $snap['downloaded_at'] ) && is_numeric( $snap['downloaded_at'] )
                ? max( 0, time() - (int) $snap['downloaded_at'] ) : null;
            $entry['available'] = true;
            $entry['age_days'] = null === $age ? null : (int) floor( $age / DAY_IN_SECONDS );
            $entry['stale'] = null === $age || $age > 7 * DAY_IN_SECONDS;
            $entry['start'] = (string) ( $snap['current']['start'] ?? '' );
            $entry['end'] = (string) ( $snap['current']['end'] ?? '' );
            $periods[ $source ] = $entry['start'] . '|' . $entry['end'];
            $data = (array) ( $snap['data'] ?? array() );
            foreach ( $map[ $source ] as $field ) {
                $now = self::metric( $data, array( 'summary', 'current', $field ) );
                $before = self::metric( $data, array( 'summary', 'previous', $field ) );
                if ( 'bing' === $source ) {
                    $now = self::metric( $data, array( 'traffic', 'current', $field ) );
                    $before = self::metric( $data, array( 'traffic', 'previous', $field ) );
                }
                $motion = self::movement( $now, $before, 'ecommercePurchases' === $field ? 5 : 20 );
                if ( null === $motion || $entry['stale'] ) {
                    continue;
                }
                if ( 'stable' !== $motion['trend'] ) {
                    $signals[] = array(
                        'source' => $source,
                        'field' => $field,
                        'change' => $motion['value'],
                        'trend' => $motion['trend'],
                    );
                }
            }
        }
        unset( $entry );

        // Avoid presenting periods from different providers as if directly comparable.
        $periods = array_values( array_unique( array_filter( $periods ) ) );
        $aligned = count( $periods ) <= 1;
        $tasks = array();
        foreach ( (array) $history as $record ) {
            if ( ! is_array( $record ) || ! isset( $record['last_task'] ) || ! is_array( $record['last_task'] ) ) {
                continue;
            }
            if ( in_array( (string) ( $record['status'] ?? '' ), array( 'dismissed', 'executed' ), true ) ) {
                continue;
            }
            $task = $record['last_task'];
            if ( empty( $task['task_id'] ) ) {
                continue;
            }
            $tasks[ (string) $task['task_id'] ] = array(
                'title' => (string) ( $task['topic'] ?? $task['title'] ?? $task['task_id'] ),
                'status' => (string) ( $record['status'] ?? 'proposed' ),
                'priority' => (int) ( $task['priority'] ?? 0 ),
                'bucket' => (string) ( $task['work_bucket'] ?? 'SIN_ACCION' ),
                'last_seen' => (string) ( $record['last_seen'] ?? '' ),
            );
        }
        uasort( $tasks, static function ( $a, $b ) {
            $rank = array( 'HACER_AHORA' => 0, 'INVESTIGAR' => 1, 'HACER_DESPUES' => 2, 'VIGILAR' => 3, 'ESPERAR_DATOS' => 4 );
            $one = $rank[ $a['bucket'] ] ?? 5;
            $two = $rank[ $b['bucket'] ] ?? 5;
            return $one === $two ? $b['priority'] <=> $a['priority'] : $one <=> $two;
        } );
        return array( 'sources' => $sources, 'signals' => array_slice( $signals, 0, 5 ), 'aligned' => $aligned, 'tasks' => array_slice( array_values( $tasks ), 0, 5 ) );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', 'taxocommerce-google-bing' ) );
        }
        wp_enqueue_style( 'tci-executive', TAXOCOMMERCE_GOOGLE_BING_URL . 'assets/executive.css', array(), TAXOCOMMERCE_GOOGLE_BING_VERSION );
        $snapshots = array();
        foreach ( array_keys( self::SOURCES ) as $source ) {
            $snapshots[ $source ] = self::snapshot( $source );
        }
        $history = get_option( 'seo_analista_task_history_381', array() );
        $model = self::prepare( $snapshots, is_array( $history ) ? $history : array() );
        echo '<div class="tci-executive"><h2>' . esc_html__( 'Informes ejecutivos', 'taxocommerce-google-bing' ) . '</h2>';
        echo '<p>' . esc_html__( 'Lectura de dirección basada exclusivamente en informes ya descargados. No genera consultas externas, copias de estadísticas ni tareas nuevas.', 'taxocommerce-google-bing' ) . '</p>';
        echo '<div class="tci-executive-grid">';
        foreach ( $model['sources'] as $source => $entry ) {
            $label = esc_html( $entry['label'] );
            $link = esc_url( self::link( $entry['tab'] ) );
            echo '<section class="tci-executive-card"><h3>' . $label . '</h3>';
            if ( ! $entry['available'] ) {
                echo '<p>' . esc_html__( 'Sin informe guardado. Actualiza la fuente desde su pestaña técnica.', 'taxocommerce-google-bing' ) . '</p>';
            } else {
                echo '<p>' . esc_html( $entry['start'] . ' — ' . $entry['end'] ) . '</p>';
                if ( $entry['stale'] ) {
                    echo '<p class="tci-executive-warning">' . esc_html__( 'Datos antiguos o fecha de descarga desconocida: no se deducen tendencias.', 'taxocommerce-google-bing' ) . '</p>';
                } else {
                    echo '<p>' . esc_html__( 'Datos descargados recientemente.', 'taxocommerce-google-bing' ) . '</p>';
                }
            }
            echo '<p><a href="' . $link . '">' . esc_html__( 'Ver datos técnicos', 'taxocommerce-google-bing' ) . '</a></p></section>';
        }
        echo '</div>';
        if ( ! $model['aligned'] ) {
            echo '<p class="tci-executive-warning">' . esc_html__( 'Las fuentes cubren fechas diferentes: no se comparan directamente entre sí.', 'taxocommerce-google-bing' ) . '</p>';
        }
        echo '<section class="tci-executive-card"><h3>' . esc_html__( 'Qué está cambiando', 'taxocommerce-google-bing' ) . '</h3>';
        if ( ! $model['signals'] ) {
            echo '<p>' . esc_html__( 'No hay variaciones suficientemente respaldadas por informes recientes y comparables. No se crean alertas artificiales.', 'taxocommerce-google-bing' ) . '</p>';
        } else {
            echo '<ul>';
            foreach ( $model['signals'] as $signal ) {
                $source = $model['sources'][ $signal['source'] ];
                $names = array( 'clicks' => __( 'clics', 'taxocommerce-google-bing' ), 'impressions' => __( 'impresiones', 'taxocommerce-google-bing' ), 'sessions' => __( 'sesiones', 'taxocommerce-google-bing' ), 'ecommercePurchases' => __( 'compras registradas', 'taxocommerce-google-bing' ) );
                echo '<li><strong>' . esc_html( $source['label'] ) . ':</strong> ' . esc_html( $names[ $signal['field'] ] ?? $signal['field'] ) . ' ' . esc_html( sprintf( '%+.1f%%', $signal['change'] ) ) . '. ';
                echo '<a href="' . esc_url( self::link( $source['tab'] ) ) . '">' . esc_html__( 'Examinar causa', 'taxocommerce-google-bing' ) . '</a></li>';
            }
            echo '</ul><p class="description">' . esc_html__( 'Una variación no demuestra su causa. Confirmar estacionalidad y cobertura antes de intervenir.', 'taxocommerce-google-bing' ) . '</p>';
        }
        echo '</section><section class="tci-executive-card"><h3>' . esc_html__( 'Prioridades de trabajo ya registradas', 'taxocommerce-google-bing' ) . '</h3>';
        if ( ! $model['tasks'] ) {
            echo '<p>' . esc_html__( 'Sin tareas pendientes en el historial de Analista. Ejecuta el Plan de acción para generar propuestas verificadas.', 'taxocommerce-google-bing' ) . '</p>';
        } else {
            echo '<ol>';
            foreach ( $model['tasks'] as $task ) {
                echo '<li><strong>' . esc_html( $task['title'] ) . '</strong> — ' . esc_html( $task['bucket'] ) . ' (' . esc_html( $task['status'] ) . ')</li>';
            }
            echo '</ol>';
        }
        echo '<p><a class="button button-secondary" href="' . esc_url( self::link( 'analysis' ) ) . '">' . esc_html__( 'Abrir Analista y Plan de acción', 'taxocommerce-google-bing' ) . '</a></p></section>';
        echo '</div>';
    }
}
