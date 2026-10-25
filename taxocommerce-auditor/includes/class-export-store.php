<?php
/**
 * Complete Auditor export storage.
 *
 * Full findings are written as JSON Lines under uploads so large catalog audits
 * do not turn a WordPress option into a multi-megabyte queue. The admin report
 * keeps only a bounded UI sample; JSON export streams the complete queue.
 */

namespace TaxoCommerce\Auditor;

defined( 'ABSPATH' ) || exit;

final class Export_Store {
    const DIR = 'taxocommerce-auditor';

    private static function directory() {
        $uploads = wp_upload_dir( null, false );
        if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
            return '';
        }
        $dir = trailingslashit( (string) $uploads['basedir'] ) . self::DIR;
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return '';
        }
        return $dir;
    }

    public static function begin( $scope ) {
        $dir = self::directory();
        if ( '' === $dir ) {
            return '';
        }
        $scope = sanitize_key( (string) $scope );
        $token = gmdate( 'Ymd-His' ) . '-' . substr( wp_generate_password( 12, false, false ), 0, 8 );
        $file = sanitize_file_name( $scope . '-' . $token . '.jsonl' );
        $path = trailingslashit( $dir ) . $file;
        $written = file_put_contents( $path, '' );
        return false === $written ? '' : $file;
    }

    public static function path( $file ) {
        $file = sanitize_file_name( basename( (string) $file ) );
        if ( '' === $file ) {
            return '';
        }
        $dir = self::directory();
        return '' === $dir ? '' : trailingslashit( $dir ) . $file;
    }

    public static function append( $file, $row ) {
        $path = self::path( $file );
        if ( '' === $path ) {
            return false;
        }
        $json = wp_json_encode(
            (array) $row,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ( ! is_string( $json ) ) {
            return false;
        }
        return false !== file_put_contents( $path, $json . "\n", FILE_APPEND | LOCK_EX );
    }

    public static function delete( $file ) {
        $path = self::path( $file );
        if ( '' !== $path && is_file( $path ) ) {
            @unlink( $path );
        }
    }

    public static function exists( $file ) {
        $path = self::path( $file );
        return '' !== $path && is_file( $path ) && is_readable( $path );
    }

    public static function rows( $file ) {
        $path = self::path( $file );
        if ( '' === $path || ! is_readable( $path ) ) {
            return;
        }
        $handle = fopen( $path, 'rb' );
        if ( false === $handle ) {
            return;
        }
        try {
            while ( false !== ( $line = fgets( $handle ) ) ) {
                $line = trim( $line );
                if ( '' === $line ) {
                    continue;
                }
                $row = json_decode( $line, true );
                if ( is_array( $row ) ) {
                    yield $row;
                }
            }
        } finally {
            fclose( $handle );
        }
    }

    private static function encode( $value ) {
        $json = wp_json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return is_string( $json ) ? $json : 'null';
    }

    private static function stream_rows( $file ) {
        $first = true;
        echo '[';
        foreach ( self::rows( $file ) as $row ) {
            if ( ! $first ) {
                echo ',';
            }
            echo "\n" . self::encode( $row );
            $first = false;
        }
        if ( ! $first ) {
            echo "\n";
        }
        echo ']';
    }

    public static function stream_report( $scope, $report ) {
        $report = is_array( $report ) ? $report : array();
        $file = (string) ( $report['full_findings_file'] ?? '' );
        if ( ! self::exists( $file ) ) {
            return false;
        }

        $finding_count = absint( $report['finding_count'] ?? 0 );
        $severity = (array) ( $report['severity_counts'] ?? array() );
        $priority = (array) ( $report['priority_counts'] ?? array() );
        $actions = (array) ( $report['action_class_counts'] ?? array() );
        $statuses = (array) ( $report['status_counts'] ?? array() );
        $sample_count = count( (array) ( $report['findings_sample'] ?? array() ) );
        $entities = absint( $report['entities_to_review'] ?? 0 );

        $priority_summary = array(
            'tasks'                     => $finding_count,
            'ready_now'                 => absint( $report['ready_now_count'] ?? 0 ),
            'needs_source_verification' => absint( $statuses['NEEDS_SOURCE_VERIFICATION'] ?? 0 ),
            'waiting_for_enrichment'    => absint( $statuses['WAITING_FOR_ENRICHMENT'] ?? 0 ),
            'migration_pending'         => absint( $statuses['MIGRATION_PENDING'] ?? 0 ),
            'review_required'           => absint( $statuses['REVIEW_REQUIRED'] ?? 0 ),
            'informational'             => absint( $statuses['NO_ACTION'] ?? 0 ),
            'by_priority'               => $priority,
            'by_action_class'           => $actions,
        );

        $meta = array(
            'schema' => array(
                'name'    => 'taxocommerce_auditor_scope',
                'version' => 3,
            ),
            'auditor_version' => Plugin::VERSION,
            'generated_at'     => (string) ( $report['completed_local'] ?? current_time( 'mysql' ) ),
            'generated_at_gmt' => (string) ( $report['completed_gmt'] ?? current_time( 'mysql', true ) ),
            'execution_seconds'=> absint( $report['duration_seconds'] ?? 0 ),
            'mode'             => (string) ( $report['run_mode'] ?? 'filtered' ),
            'audit_scope'      => sanitize_key( (string) $scope ),
            'audit_status'     => 'scope_completed',
            'summary'          => array(
                'findings'          => $finding_count,
                'critical'          => absint( $severity['critical'] ?? 0 ),
                'high'              => absint( $severity['high'] ?? 0 ),
                'medium'            => absint( $severity['medium'] ?? 0 ),
                'low'               => absint( $severity['low'] ?? 0 ),
                'info'              => absint( $severity['info'] ?? 0 ),
                'entities_to_review'=> $entities,
                'findings_included' => $finding_count,
                'findings_total'    => $finding_count,
                'findings_sampled'  => false,
            ),
            'inventory'         => (array) ( $report['inventory'] ?? array() ),
            'rule_counts'       => (array) ( $report['rule_counts'] ?? array() ),
            'priority_queue_version' => 3,
            'priority_summary'  => $priority_summary,
            'priority_queue_meta' => array(
                'canonical'               => true,
                'sampled'                 => false,
                'total_count'             => $finding_count,
                'included_count'          => $finding_count,
                'truncated'               => false,
                'source_findings_total'   => $finding_count,
                'source_findings_collected'=> $finding_count,
                'ui' => array(
                    'sampled'        => $finding_count > $sample_count,
                    'total_count'    => $finding_count,
                    'included_count' => $sample_count,
                    'truncated'      => $finding_count > $sample_count,
                ),
                'json_export_complete' => true,
            ),
        );

        echo "{\n";
        $first = true;
        foreach ( $meta as $key => $value ) {
            if ( ! $first ) {
                echo ",\n";
            }
            echo self::encode( (string) $key ) . ': ' . self::encode( $value );
            $first = false;
        }

        echo ",\n" . self::encode( 'priority_queue' ) . ': ';
        self::stream_rows( $file );

        $extras = array(
            'quality'              => (array) ( $report['quality'] ?? array() ),
            'category_rebalance'   => $report['category_rebalance'] ?? null,
            'editorial_comparison' => $report['editorial_comparison'] ?? null,
            'decision_context'     => $report['decision_context'] ?? null,
            'scope_kpis'           => (array) ( $report['scope_kpis'] ?? array() ),
            'findings_meta'        => array(
                'sampled'          => false,
                'total_count'      => $finding_count,
                'included_count'   => $finding_count,
                'truncated'        => false,
                'ui_sample_count'  => $sample_count,
                'rule_counts'      => (array) ( $report['rule_counts'] ?? array() ),
            ),
        );
        foreach ( $extras as $key => $value ) {
            echo ",\n" . self::encode( (string) $key ) . ': ' . self::encode( $value );
        }

        echo ",\n" . self::encode( 'findings' ) . ': ';
        self::stream_rows( $file );
        echo ",\n" . self::encode( 'notes' ) . ': ' . self::encode( (array) ( $report['notes'] ?? array() ) );
        echo "\n}\n";
        return true;
    }
}
