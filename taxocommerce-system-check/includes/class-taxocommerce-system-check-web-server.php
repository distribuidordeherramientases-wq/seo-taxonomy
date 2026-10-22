<?php
/**
 * Read-only web-server report based on real server activity.
 *
 * No external probes are generated. Data is taken from the runtime and, when
 * the hosting exposes one, from a readable access log.
 */

defined( 'ABSPATH' ) || exit;

final class TaxoCommerce_System_Check_Web_Server {
    const MAX_LINES = 5000;
    const MAX_BYTES = 4194304;

    public static function report() {
        $source = self::detect_access_log();
        $rows   = $source ? self::read_access_log( $source ) : array();

        return array(
            'generated_at' => gmdate( 'c' ),
            'server'       => self::server_identity(),
            'source'       => $source ? self::safe_source_label( $source ) : '',
            'sample'       => self::summarize( $rows ),
        );
    }

    public static function render() {
        $report = self::report();
        $sample = $report['sample'];
        $server = $report['server'];

        echo '<div class="wrap tcs-web-server">';
        echo '<h2>Servidor web</h2>';
        echo '<p>Actividad HTTP observada por el propio servidor. Esta vista no lanza peticiones externas ni depende de GitHub.</p>';

        echo '<div class="tcs-web-kpis">';
        self::kpi( 'Servidor', $server['software'] ?: 'No identificado', $server['protocol'] ?: 'Sin protocolo' );
        self::kpi( 'Peticiones', number_format_i18n( $sample['requests'] ), $sample['requests'] ? 'Muestra reciente del access log' : 'Sin access log legible' );
        self::kpi( 'Visitantes estimados', number_format_i18n( $sample['visitors'] ), $sample['visitors'] ? 'Estimación por IP anonimizada en la muestra' : 'No disponible' );
        self::kpi( 'Errores HTTP', number_format_i18n( $sample['errors'] ), $sample['requests'] ? number_format_i18n( $sample['error_rate'], 2 ) . '%' : 'No disponible' );
        self::kpi( 'Tiempo medio / P95', null !== $sample['avg_ms'] ? number_format_i18n( $sample['avg_ms'], 1 ) . ' ms' : 'No disponible', null !== $sample['p95_ms'] ? 'P95: ' . number_format_i18n( $sample['p95_ms'], 1 ) . ' ms' : 'El log no expone tiempo de respuesta' );
        echo '</div>';

        echo '<table class="widefat striped tcs-web-meta"><tbody>';
        echo '<tr><th>Software</th><td>' . esc_html( $server['software'] ?: 'No identificado' ) . '</td></tr>';
        echo '<tr><th>Dirección/puerto</th><td>' . esc_html( $server['endpoint'] ?: 'No disponible' ) . '</td></tr>';
        echo '<tr><th>Protocolo</th><td>' . esc_html( $server['protocol'] ?: 'No disponible' ) . '</td></tr>';
        echo '<tr><th>Fuente de actividad</th><td>' . esc_html( $report['source'] ?: 'No se ha encontrado un access log legible para PHP.' ) . '</td></tr>';
        echo '<tr><th>Última actividad de la muestra</th><td>' . esc_html( $sample['last_activity'] ?: 'No disponible' ) . '</td></tr>';
        echo '</tbody></table>';

        if ( ! $sample['requests'] ) {
            echo '<div class="notice notice-info inline"><p>El servidor responde a WordPress, pero PHP no tiene acceso de lectura a un access log reconocido. El plugin no generará tráfico artificial para rellenar esta pestaña. Si el hosting expone estadísticas o logs mediante una ruta/API legible, se podrá incorporar como adaptador.</p></div>';
            self::render_styles();
            echo '</div>';
            return;
        }

        echo '<div class="tcs-web-grid">';
        self::render_statuses( $sample['statuses'] );
        self::render_top_urls( $sample['top_urls'] );
        self::render_countries( $sample['countries'] );
        echo '</div>';

        echo '<p class="description">Los visitantes son una estimación dentro de la muestra mediante una huella irreversible de IP; no se muestran ni almacenan direcciones IP. Países y tiempos sólo aparecen cuando el formato del log los proporciona.</p>';
        self::render_styles();
        echo '</div>';
    }

    private static function server_identity() {
        $software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
        $protocol = isset( $_SERVER['SERVER_PROTOCOL'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) : '';
        $addr     = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
        $port     = isset( $_SERVER['SERVER_PORT'] ) ? absint( $_SERVER['SERVER_PORT'] ) : 0;

        return array(
            'software' => $software,
            'protocol' => $protocol,
            'endpoint' => trim( $addr . ( $port ? ':' . $port : '' ), ':' ),
        );
    }

    private static function detect_access_log() {
        $host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
        $host = preg_replace( '/[^a-z0-9.\-]/i', '', $host );
        $home = (string) getenv( 'HOME' );
        $doc  = isset( $_SERVER['DOCUMENT_ROOT'] ) ? (string) wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) : ABSPATH;

        $candidates = array_filter( array(
            $home && $host ? $home . '/access-logs/' . $host : '',
            $home && $host ? $home . '/logs/' . $host : '',
            $home && $host ? $home . '/logs/' . $host . '.log' : '',
            $home ? $home . '/logs/access.log' : '',
            $doc ? rtrim( $doc, '/\\' ) . '/access.log' : '',
            $doc ? dirname( rtrim( $doc, '/\\' ) ) . '/logs/access.log' : '',
            ABSPATH . 'access.log',
            '/var/log/nginx/access.log',
            '/var/log/apache2/access.log',
            '/var/log/httpd/access_log',
        ) );

        foreach ( array_unique( $candidates ) as $candidate ) {
            $real = realpath( $candidate );
            if ( $real && is_file( $real ) && is_readable( $real ) ) {
                return $real;
            }
        }
        return '';
    }

    private static function safe_source_label( $path ) {
        $base = basename( $path );
        $dir  = basename( dirname( $path ) );
        return $dir ? $dir . '/' . $base : $base;
    }

    private static function read_access_log( $path ) {
        $handle = @fopen( $path, 'rb' );
        if ( ! $handle ) {
            return array();
        }

        $size   = @filesize( $path );
        $offset = is_int( $size ) && $size > self::MAX_BYTES ? $size - self::MAX_BYTES : 0;
        if ( $offset > 0 ) {
            @fseek( $handle, $offset );
            fgets( $handle );
        }

        $lines = array();
        while ( ! feof( $handle ) ) {
            $line = fgets( $handle );
            if ( false === $line ) {
                break;
            }
            $line = trim( $line );
            if ( '' !== $line ) {
                $lines[] = $line;
                if ( count( $lines ) > self::MAX_LINES ) {
                    array_shift( $lines );
                }
            }
        }
        fclose( $handle );

        $rows = array();
        foreach ( $lines as $line ) {
            $row = self::parse_line( $line );
            if ( $row ) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private static function parse_line( $line ) {
        if ( '{' === substr( ltrim( $line ), 0, 1 ) ) {
            $json = json_decode( $line, true );
            if ( is_array( $json ) ) {
                return self::parse_json_line( $json );
            }
        }

        $pattern = '/^(\S+)\s+\S+\s+\S+\s+\[([^\]]+)\]\s+"(\S+)\s+([^"]*?)(?:\s+HTTP\/[^"]+)"\s+(\d{3})\s+(\S+)(?:\s+"([^"]*)"\s+"([^"]*)")?(.*)$/';
        if ( ! preg_match( $pattern, $line, $m ) ) {
            return null;
        }

        $tail = isset( $m[9] ) ? (string) $m[9] : '';
        $time = self::extract_time_ms( $tail );
        $country = self::extract_country( $tail );

        return array(
            'ip'       => $m[1],
            'time'     => self::parse_log_date( $m[2] ),
            'method'   => strtoupper( $m[3] ),
            'url'      => self::clean_url( $m[4] ),
            'status'   => (int) $m[5],
            'bytes'    => is_numeric( $m[6] ) ? (int) $m[6] : 0,
            'time_ms'  => $time,
            'country'  => $country,
        );
    }

    private static function parse_json_line( $json ) {
        $request = isset( $json['request'] ) ? (string) $json['request'] : '';
        $url = (string) ( $json['request_uri'] ?? $json['uri'] ?? $json['path'] ?? '' );
        $method = (string) ( $json['request_method'] ?? $json['method'] ?? '' );
        if ( $request && ( ! $url || ! $method ) && preg_match( '/^(\S+)\s+(\S+)/', $request, $m ) ) {
            $method = $method ?: $m[1];
            $url    = $url ?: $m[2];
        }

        $time_ms = null;
        if ( isset( $json['time_ms'] ) && is_numeric( $json['time_ms'] ) ) {
            $time_ms = (float) $json['time_ms'];
        } elseif ( isset( $json['request_time'] ) && is_numeric( $json['request_time'] ) ) {
            $time_ms = (float) $json['request_time'] * 1000;
        } elseif ( isset( $json['upstream_response_time'] ) && is_numeric( $json['upstream_response_time'] ) ) {
            $time_ms = (float) $json['upstream_response_time'] * 1000;
        }

        return array(
            'ip'      => (string) ( $json['remote_addr'] ?? $json['client_ip'] ?? $json['ip'] ?? '' ),
            'time'    => self::json_timestamp( $json ),
            'method'  => strtoupper( $method ),
            'url'     => self::clean_url( $url ),
            'status'  => (int) ( $json['status'] ?? $json['status_code'] ?? 0 ),
            'bytes'   => (int) ( $json['body_bytes_sent'] ?? $json['bytes_sent'] ?? 0 ),
            'time_ms' => $time_ms,
            'country' => strtoupper( substr( (string) ( $json['country_code'] ?? $json['country'] ?? $json['cf_ipcountry'] ?? '' ), 0, 2 ) ),
        );
    }

    private static function extract_time_ms( $tail ) {
        if ( preg_match( '/(?:request_time|rt|response_time)=([0-9.]+)\b/i', $tail, $m ) ) {
            $value = (float) $m[1];
            return $value < 1000 ? $value * 1000 : $value;
        }
        if ( preg_match( '/(?:time_ms|response_ms)=([0-9.]+)\b/i', $tail, $m ) ) {
            return (float) $m[1];
        }
        return null;
    }

    private static function extract_country( $tail ) {
        if ( preg_match( '/(?:country|country_code|cf-ipcountry)[=:]"?([A-Z]{2})"?/i', $tail, $m ) ) {
            return strtoupper( $m[1] );
        }
        return '';
    }

    private static function parse_log_date( $value ) {
        $dt = DateTime::createFromFormat( 'd/M/Y:H:i:s O', $value );
        return $dt ? $dt->getTimestamp() : 0;
    }

    private static function json_timestamp( $json ) {
        foreach ( array( 'timestamp', 'time', 'datetime', '@timestamp' ) as $key ) {
            if ( empty( $json[ $key ] ) ) {
                continue;
            }
            if ( is_numeric( $json[ $key ] ) ) {
                return (int) $json[ $key ];
            }
            $ts = strtotime( (string) $json[ $key ] );
            if ( $ts ) {
                return $ts;
            }
        }
        return 0;
    }

    private static function clean_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '/';
        }
        $path = wp_parse_url( $url, PHP_URL_PATH );
        return is_string( $path ) && '' !== $path ? $path : $url;
    }

    private static function summarize( $rows ) {
        $statuses = array( '2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0 );
        $visitors = array();
        $urls = array();
        $countries = array();
        $times = array();
        $last = 0;

        foreach ( $rows as $row ) {
            $status = (int) $row['status'];
            if ( $status >= 200 && $status < 300 ) {
                $statuses['2xx']++;
            } elseif ( $status >= 300 && $status < 400 ) {
                $statuses['3xx']++;
            } elseif ( $status >= 400 && $status < 500 ) {
                $statuses['4xx']++;
            } elseif ( $status >= 500 && $status < 600 ) {
                $statuses['5xx']++;
            } else {
                $statuses['other']++;
            }

            if ( ! empty( $row['ip'] ) ) {
                $visitors[ hash_hmac( 'sha256', (string) $row['ip'], wp_salt( 'auth' ) ) ] = true;
            }
            $url = (string) $row['url'];
            $urls[ $url ] = isset( $urls[ $url ] ) ? $urls[ $url ] + 1 : 1;

            if ( ! empty( $row['country'] ) && preg_match( '/^[A-Z]{2}$/', $row['country'] ) ) {
                $countries[ $row['country'] ] = isset( $countries[ $row['country'] ] ) ? $countries[ $row['country'] ] + 1 : 1;
            }
            if ( null !== $row['time_ms'] && is_numeric( $row['time_ms'] ) ) {
                $times[] = (float) $row['time_ms'];
            }
            $last = max( $last, (int) $row['time'] );
        }

        arsort( $urls );
        arsort( $countries );
        sort( $times, SORT_NUMERIC );

        $requests = count( $rows );
        $errors = $statuses['4xx'] + $statuses['5xx'];
        $avg = $times ? array_sum( $times ) / count( $times ) : null;
        $p95 = $times ? self::percentile( $times, 95 ) : null;

        return array(
            'requests'      => $requests,
            'visitors'      => count( $visitors ),
            'errors'        => $errors,
            'error_rate'    => $requests ? ( $errors / $requests ) * 100 : 0,
            'avg_ms'        => null === $avg ? null : round( $avg, 1 ),
            'p95_ms'        => null === $p95 ? null : round( $p95, 1 ),
            'statuses'      => $statuses,
            'top_urls'      => array_slice( $urls, 0, 10, true ),
            'countries'     => array_slice( $countries, 0, 10, true ),
            'last_activity' => $last ? date_i18n( 'Y-m-d H:i:s', $last ) : '',
        );
    }

    private static function percentile( $values, $percentile ) {
        if ( ! $values ) {
            return null;
        }
        $index = ( $percentile / 100 ) * ( count( $values ) - 1 );
        $lower = (int) floor( $index );
        $upper = (int) ceil( $index );
        if ( $lower === $upper ) {
            return $values[ $lower ];
        }
        $weight = $index - $lower;
        return $values[ $lower ] * ( 1 - $weight ) + $values[ $upper ] * $weight;
    }

    private static function kpi( $label, $value, $detail ) {
        echo '<div class="tcs-web-kpi"><strong>' . esc_html( $label ) . '</strong><div>' . esc_html( (string) $value ) . '</div><span>' . esc_html( $detail ) . '</span></div>';
    }

    private static function render_statuses( $statuses ) {
        echo '<section class="tcs-web-card"><h3>Códigos HTTP</h3><table class="widefat striped"><tbody>';
        foreach ( $statuses as $label => $count ) {
            echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( number_format_i18n( $count ) ) . '</td></tr>';
        }
        echo '</tbody></table></section>';
    }

    private static function render_top_urls( $urls ) {
        echo '<section class="tcs-web-card"><h3>URLs más solicitadas</h3>';
        if ( ! $urls ) {
            echo '<p>No disponible.</p>';
        } else {
            echo '<table class="widefat striped"><tbody>';
            foreach ( $urls as $url => $count ) {
                echo '<tr><td><code>' . esc_html( $url ) . '</code></td><td>' . esc_html( number_format_i18n( $count ) ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
    }

    private static function render_countries( $countries ) {
        echo '<section class="tcs-web-card"><h3>Países</h3>';
        if ( ! $countries ) {
            echo '<p>No disponible en el formato actual del log.</p>';
        } else {
            echo '<table class="widefat striped"><tbody>';
            foreach ( $countries as $country => $count ) {
                echo '<tr><th>' . esc_html( $country ) . '</th><td>' . esc_html( number_format_i18n( $count ) ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
    }

    private static function render_styles() {
        echo '<style>
        .tcs-web-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:10px;margin:18px 0}
        .tcs-web-kpi,.tcs-web-card{background:#fff;border:1px solid #dcdcde;border-radius:7px;padding:14px}
        .tcs-web-kpi strong{display:block;font-size:11px;text-transform:uppercase;color:#646970;margin-bottom:5px}
        .tcs-web-kpi div{font-size:22px;font-weight:700;line-height:1.2}
        .tcs-web-kpi span{display:block;color:#646970;font-size:12px;margin-top:5px}
        .tcs-web-meta{margin:0 0 18px}.tcs-web-meta th{width:190px}
        .tcs-web-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;margin-top:18px}
        .tcs-web-card h3{margin-top:0}.tcs-web-card code{word-break:break-all}
        </style>';
    }
}
