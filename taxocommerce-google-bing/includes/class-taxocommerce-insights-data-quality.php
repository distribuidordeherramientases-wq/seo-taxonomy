<?php
/**
 * Local collection evidence and an independent, read-only quality report.
 *
 * @package TaxoCommerce_Google_Bing
 */
defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_Insights_Data_Quality {
    const PREFIX = 'tgb_capture_evidence_';
    const MAX_AGE = 604800;

    public static function date( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) {
            return '';
        }
        $parts = explode( '-', $value );
        return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ? $value : '';
    }

    public static function window( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }
        $start = self::date( $value['start'] ?? '' );
        $end = self::date( $value['end'] ?? '' );
        return $start && $end && $start <= $end ? array( 'start' => $start, 'end' => $end ) : array();
    }

    private static function number( $value ) {
        return is_numeric( $value ) && is_finite( (float) $value ) && (float) $value >= 0 ? (float) $value : null;
    }

    /** Do not let malformed API responses become plausible zero metrics. */
    public static function ga4_response_valid( $response, array $metrics, array $dimensions = array() ) {
        if ( ! is_array( $response ) || ! is_array( $response['metricHeaders'] ?? null )
            || array_column( $response['metricHeaders'], 'name' ) !== $metrics
            || array_column( (array) ( $response['dimensionHeaders'] ?? array() ), 'name' ) !== $dimensions
            || ( isset( $response['rows'] ) && ! is_array( $response['rows'] ) ) ) {
            return false;
        }
        foreach ( (array) ( $response['rows'] ?? array() ) as $row ) {
            if ( ! is_array( $row ) || ! is_array( $row['metricValues'] ?? null )
                || count( $row['metricValues'] ) !== count( $metrics )
                || count( (array) ( $row['dimensionValues'] ?? array() ) ) !== count( $dimensions ) ) {
                return false;
            }
            foreach ( $row['metricValues'] as $metric ) {
                if ( ! is_numeric( $metric['value'] ?? null ) || ! is_finite( (float) $metric['value'] ) ) {
                    return false;
                }
            }
            foreach ( (array) ( $row['dimensionValues'] ?? array() ) as $dimension ) {
                if ( ! is_string( $dimension['value'] ?? null ) ) {
                    return false;
                }
            }
        }
        return true;
    }

    public static function validate_ga4( $response, array $metrics, array $dimensions = array() ) {
        if ( is_wp_error( $response ) || self::ga4_response_valid( $response, $metrics, $dimensions ) ) {
            return $response;
        }
        return new WP_Error( 'tgb_ga4_invalid_columns', __( 'GA4 devolvió columnas ausentes o una respuesta incompleta.', 'taxocommerce-google-bing' ) );
    }

    private static function store( $key, array $record ) {
        $record['observed_at'] = time();
        update_option( self::PREFIX . $key, $record, false );
    }

    public static function evidence( $key ) {
        $value = get_option( self::PREFIX . $key, array() );
        return is_array( $value ) ? $value : array();
    }

    public static function record_manual( $source, array $range, $success, $persisted = false, $partial = false ) {
        if ( ! in_array( $source, array( 'analytics', 'search', 'bing', 'chatgpt' ), true ) ) {
            return;
        }
        self::store( 'manual_' . $source, array(
            'period' => self::window( $range ),
            'state' => ! $success || ! $persisted ? 'error' : ( $partial ? 'partial' : 'ok' ),
            'persisted' => (bool) $persisted,
        ) );
    }

    public static function record_ga4( array $snapshot ) {
        $days = (int) ( $snapshot['period']['days'] ?? 0 );
        if ( ! in_array( $days, array( 28, 60, 90 ), true ) ) {
            return;
        }
        $errors = array_intersect( array( 'latest_date', 'events', 'sources', 'countries', 'not_found', 'chatgpt' ), array_keys( (array) ( $snapshot['errors'] ?? array() ) ) );
        $available = ! empty( $snapshot['available'] );
        $metrics = array();
        foreach ( array( 'sessions', 'purchases' ) as $name ) {
            $metrics[$name] = $available ? self::number( $snapshot[$name] ?? null ) : null;
        }
        $funnel = array();
        foreach ( array( 'view_item', 'add_to_cart', 'view_cart', 'begin_checkout', 'add_shipping_info', 'add_payment_info', 'purchase', 'view_search_results' ) as $name ) {
            $funnel[$name] = $available && ! in_array( 'events', $errors, true ) ? self::number( $snapshot['funnel'][$name] ?? null ) : null;
        }
        $chatgpt = array();
        foreach ( array( 'sessions', 'purchases' ) as $name ) {
            $chatgpt[$name] = self::number( $snapshot['chatgpt'][$name] ?? null );
        }
        $revenue = $snapshot['chatgpt']['revenue'] ?? null;
        $chatgpt['revenue'] = is_numeric( $revenue ) && is_finite( (float) $revenue ) ? (float) $revenue : null;
        $currency = (string) ( $snapshot['chatgpt']['currency'] ?? '' );
        $chatgpt['currency'] = preg_match( '/^[A-Z]{3}$/D', $currency ) ? $currency : '';
        $channels = null;
        if ( $available && ! in_array( 'sources', $errors, true ) && empty( $snapshot['source_rows_limited'] ) ) {
            $channels = array_sum( (array) ( $snapshot['channels'] ?? array() ) );
        }
        $timezone = (string) ( $snapshot['timezone'] ?? '' );
        self::store( 'ga4_' . $days, array(
            'state' => ! $available ? 'error' : ( $errors ? 'partial' : 'ok' ),
            'period' => self::window( $snapshot['period'] ?? array() ),
            'latest_date' => self::date( $snapshot['period']['latest_date'] ?? '' ),
            'timezone' => in_array( $timezone, DateTimeZone::listIdentifiers( DateTimeZone::ALL_WITH_BC ), true ) ? $timezone : '',
            'errors' => array_values( $errors ), 'metrics' => $metrics, 'funnel' => $funnel,
            'chatgpt' => $chatgpt, 'channel_sessions' => $channels,
        ) );
    }

    public static function record_bing( array $snapshot ) {
        self::store( 'bing_analyst', array(
            'state' => empty( $snapshot['enabled'] ) || empty( $snapshot['configured'] ) ? 'unavailable'
                : ( empty( $snapshot['connected'] ) ? 'error' : ( ! empty( $snapshot['errors'] ) ? 'partial' : ( ! empty( $snapshot['available'] ) ? 'ok' : 'unavailable' ) ) ),
            'latest_date' => self::date( $snapshot['latest_date'] ?? '' ),
            'errors' => array_values( array_intersect( array( 'traffic', 'crawl', 'queries', 'pages' ), array_keys( (array) ( $snapshot['errors'] ?? array() ) ) ) ),
        ) );
    }

    public static function assessment( array $record, $now = null ) {
        $now = null === $now ? time() : (int) $now;
        $timestamp = (int) ( $record['observed_at'] ?? 0 );
        if ( ! $timestamp ) {
            return array( 'status' => 'skip', 'message' => __( 'Sin evidencia de recopilación desde esta versión. Actualiza la fuente y vuelve a comprobar.', 'taxocommerce-google-bing' ) );
        }
        $state = (string) ( $record['state'] ?? '' );
        $period = self::window( $record['period'] ?? array() );
        $detail = gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC';
        if ( $period ) {
            $detail .= ' · ' . $period['start'] . ' / ' . $period['end'];
        }
        if ( ! empty( $record['latest_date'] ) ) {
            $detail .= ' · ' . $record['latest_date'];
        }
        if ( ! empty( $record['errors'] ) ) {
            $detail .= ' · ' . implode( ', ', (array) $record['errors'] );
        }
        if ( 'error' === $state || 'partial' === $state ) {
            return array( 'status' => 'fail', 'message' => __( 'Última recopilación fallida o parcial:', 'taxocommerce-google-bing' ) . ' ' . $detail );
        }
        if ( 'ok' !== $state || $timestamp > $now || $now - $timestamp > self::MAX_AGE ) {
            return array( 'status' => 'skip', 'message' => __( 'Fuente no disponible o evidencia antigua; no acredita una conexión actual.', 'taxocommerce-google-bing' ) . ' ' . $detail );
        }
        // A successful historical query does not prove that recent data exist.
        $date = $record['latest_date'] ?? ( $period['end'] ?? '' );
        if ( $date && strtotime( $date . ' 23:59:59 UTC' ) < $now - self::MAX_AGE ) {
            return array( 'status' => 'skip', 'message' => __( 'Recopilación histórica correcta, sin evidencia reciente de datos.', 'taxocommerce-google-bing' ) . ' ' . $detail );
        }
        return array( 'status' => 'pass', 'message' => __( 'Última recopilación observada correcta (no es una prueba HTTP en directo):', 'taxocommerce-google-bing' ) . ' ' . $detail );
    }

    public static function manual_check( $source ) {
        $record = self::evidence( 'manual_' . $source );
        if ( 'ok' === ( $record['state'] ?? '' ) ) {
            $period = self::window( $record['period'] ?? array() );
            $saved = 'chatgpt' === $source
                ? TaxoCommerce_Insights_ChatGPT_Search::latest()
                : ( $period ? TaxoCommerce_Google_Bing_Reports::get_saved_snapshot( $source, $period['start'], $period['end'] ) : null );
            if ( ! $period || ! is_array( $saved ) ) {
                return array( 'status' => 'fail', 'message' => __( 'El informe de la última descarga no puede recuperarse del almacén.', 'taxocommerce-google-bing' ) );
            }
        }
        return self::assessment( $record );
    }

    /** Read-only, bounded WooCommerce CRUD query. No order/customer IDs exported. */
    public static function paid_orders( array $period, $timezone ) {
        if ( ! self::window( $period ) || ! $timezone || ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
            return array( 'available' => false );
        }
        try {
            $tz = new DateTimeZone( $timezone );
            $start = new DateTimeImmutable( $period['start'] . ' 00:00:00', $tz );
            $end = ( new DateTimeImmutable( $period['end'] . ' 00:00:00', $tz ) )->modify( '+1 day' )->modify( '-1 second' );
            $orders = wc_get_orders( array(
                'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ),
                'date_paid' => $start->getTimestamp() . '...' . $end->getTimestamp(),
                'limit' => 501, 'return' => 'objects',
            ) );
            if ( ! is_array( $orders ) || count( $orders ) > 500 ) {
                return array( 'available' => false, 'limit_reached' => true );
            }
            $paid = 0;
            $other = 0;
            foreach ( $orders as $order ) {
                $date = $order->get_date_paid();
                if ( ! $date || $date->getTimestamp() < $start->getTimestamp() || $date->getTimestamp() > $end->getTimestamp() ) {
                    return array( 'available' => false );
                }
                if ( $order->is_paid() ) {
                    ++$paid;
                } else {
                    ++$other;
                }
            }
            return array( 'available' => true, 'paid_status' => $paid, 'other_status_with_paid_date' => $other );
        } catch ( Throwable $error ) {
            return array( 'available' => false );
        }
    }

    private static function finding( $code, $state, $message, array $values = array() ) {
        return array( 'code' => $code, 'state' => $state, 'message' => $message, 'values' => $values );
    }

    public static function compare( array $ga4, array $gsc, array $orders ) {
        $period = self::window( $ga4['period'] ?? array() );
        if ( ! $period || 'error' === ( $ga4['state'] ?? '' ) ) {
            return array( self::finding( 'ga4_missing', 'unavailable', __( 'No hay evidencia utilizable de GA4 para este periodo. Ejecuta el informe de Resumen.', 'taxocommerce-google-bing' ) ) );
        }
        $findings = array();
        $same = $period === self::window( $gsc );
        $findings[] = self::finding( 'periods', $same ? 'ok' : 'unavailable',
            $same ? __( 'GA4 y Search Console cubren las mismas fechas; clics y sesiones siguen siendo métricas distintas.', 'taxocommerce-google-bing' )
                : __( 'Las ventanas no coinciden o falta una fuente. No comparar directamente clics y sesiones.', 'taxocommerce-google-bing' ),
            array( 'ga4' => $period, 'gsc' => self::window( $gsc ) ) );
        $metrics = (array) ( $ga4['metrics'] ?? array() );
        $f = (array) ( $ga4['funnel'] ?? array() );
        $sessions = self::number( $metrics['sessions'] ?? null );
        $purchases = self::number( $metrics['purchases'] ?? null );
        $channels = self::number( $ga4['channel_sessions'] ?? null );
        $findings[] = self::finding( 'session_totals', null === $sessions || null === $channels ? 'unavailable' : ( $sessions === $channels ? 'ok' : 'review' ),
            __( 'Comparar sesiones totales con suma de canales completos; límites de filas y umbrales pueden explicar diferencias.', 'taxocommerce-google-bing' ),
            array( 'sessions' => $sessions, 'channels' => $channels ) );
        $checkout = self::number( $f['begin_checkout'] ?? null );
        $shipping = self::number( $f['add_shipping_info'] ?? null );
        $payment = self::number( $f['add_payment_info'] ?? null );
        $findings[] = self::finding( 'funnel_events', null === $checkout || null === $shipping || null === $payment ? 'unavailable' : ( $checkout > 0 && ( 0.0 === $shipping || 0.0 === $payment ) ? 'review' : 'ok' ),
            __( 'Revisar eventos de envío y pago cuando hay inicios de compra. No todas las compras requieren ambos pasos; los eventos no son usuarios únicos.', 'taxocommerce-google-bing' ), $f );
        $event_purchases = self::number( $f['purchase'] ?? null );
        $findings[] = self::finding( 'purchase_events', null === $purchases || null === $event_purchases ? 'unavailable' : ( $purchases === $event_purchases ? 'ok' : 'review' ),
            __( 'Contrastar ecommercePurchases y eventos purchase. No sustituir una métrica ausente por la otra.', 'taxocommerce-google-bing' ),
            array( 'ecommerce_purchases' => $purchases, 'purchase_events' => $event_purchases ) );
        $paid = ! empty( $orders['available'] ) ? self::number( $orders['paid_status'] ?? null ) : null;
        $findings[] = self::finding( 'woocommerce', null === $paid || null === $purchases ? 'unavailable' : ( $paid === $purchases ? 'ok' : 'review' ),
            __( 'WooCommerce: pedidos con fecha de pago y estado pagado frente a compras GA4. Consentimiento, atribución, cancelaciones y estados pueden explicar diferencias; no verifica liquidaciones bancarias.', 'taxocommerce-google-bing' ),
            array( 'ga4_purchases' => $purchases, 'woocommerce_paid_status' => $paid, 'other_status_with_paid_date' => $orders['other_status_with_paid_date'] ?? null ) );
        $chat = (array) ( $ga4['chatgpt'] ?? array() );
        $chat_sessions = self::number( $chat['sessions'] ?? null );
        $chat_purchases = self::number( $chat['purchases'] ?? null );
        $findings[] = self::finding( 'chatgpt_attribution', null === $chat_sessions || null === $chat_purchases || null === $sessions || null === $purchases ? 'unavailable' : ( $chat_sessions > $sessions || $chat_purchases > $purchases ? 'review' : 'ok' ),
            __( 'Tráfico y compras atribuidos por sessionSource a chatgpt.com/chat.openai.com. No equivale a impresiones, clics orgánicos ni atribución causal de OpenAI.', 'taxocommerce-google-bing' ), $chat );
        return $findings;
    }

    public static function build( $days ) {
        $days = in_array( (int) $days, array( 28, 60, 90 ), true ) ? (int) $days : 28;
        $ga4 = self::evidence( 'ga4_' . $days );
        $gsc = array();
        if ( function_exists( 'seo_analista_resolve_property_id' ) && function_exists( 'seo_analista_period' ) ) {
            $property = seo_analista_resolve_property_id();
            if ( $property ) {
                $period = (array) seo_analista_period( $property, $days );
                $gsc = self::window( array( 'start' => $period['date_from'] ?? '', 'end' => $period['date_to'] ?? '' ) );
            }
        }
        $timestamp = (int) ( $ga4['observed_at'] ?? 0 );
        $fresh = $timestamp > 0 && $timestamp <= time() && time() - $timestamp <= self::MAX_AGE;
        $orders = $fresh && 'error' !== ( $ga4['state'] ?? '' )
            ? self::paid_orders( self::window( $ga4['period'] ?? array() ), $ga4['timezone'] ?? '' ) : array( 'available' => false );
        $captures = array( 'ga4_analyst' => self::assessment( $ga4 ), 'bing_analyst' => self::assessment( self::evidence( 'bing_analyst' ) ) );
        foreach ( array( 'search', 'analytics', 'bing', 'chatgpt' ) as $source ) {
            $captures[$source] = self::manual_check( $source );
        }
        return array( 'schema' => 1, 'generated_at' => gmdate( 'c' ), 'days' => $days, 'capture' => $captures,
            'ga4' => $ga4, 'gsc_period' => $gsc, 'orders' => $orders, 'findings' => self::compare( $fresh ? $ga4 : array(), $gsc, $orders ) );
    }

    public static function render( array $report ) {
        echo '<h2>' . esc_html__( 'Calidad y coherencia de datos', 'taxocommerce-google-bing' ) . '</h2><p>'
            . esc_html__( 'Contrastes sobre datos recibidos. Una discrepancia requiere revisión; no demuestra un fallo comercial. Los datos ausentes no se consideran cero.', 'taxocommerce-google-bing' ) . '</p>';
        $labels = array( 'ok' => __( 'Coherente', 'taxocommerce-google-bing' ), 'review' => __( 'Revisar', 'taxocommerce-google-bing' ), 'unavailable' => __( 'No evaluable', 'taxocommerce-google-bing' ) );
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Comprobación', 'taxocommerce-google-bing' ) . '</th><th>' . esc_html__( 'Estado', 'taxocommerce-google-bing' ) . '</th><th>' . esc_html__( 'Evidencia', 'taxocommerce-google-bing' ) . '</th></tr></thead><tbody>';
        foreach ( $report['findings'] as $row ) {
            echo '<tr><td>' . esc_html( $row['message'] ) . '</td><td><strong>' . esc_html( $labels[$row['state']] ?? $row['state'] ) . '</strong></td><td><code>' . esc_html( wp_json_encode( $row['values'], JSON_UNESCAPED_UNICODE ) ) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }
}
