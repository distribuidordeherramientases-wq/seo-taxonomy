<?php
/**
 * Plugin Validation - mantenimiento y retencion de archivos de importacion.
 *
 * Este modulo es estrictamente de solo lectura: comprueba la politica y el
 * estado de la limpieza, pero nunca borra archivos durante una validacion.
 *
 * @package SEOSystem
 * @subpackage PluginValidation
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

if (!function_exists('seo_core_system_test_import_retention_checks')) {
    function seo_core_system_test_import_retention_checks() {
        $results = array();
        $cleanup_available = function_exists('seo_ie_batch_cleanup_old_files')
            && function_exists('seo_ie_batch_retention_report');

        $results[] = seo_core_system_test_result(
            'technical',
            '7.12 Retencion automatica de importaciones disponible',
            $cleanup_available,
            $cleanup_available
                ? 'La cola dispone de limpieza automatica y diagnostico de retencion.'
                : 'No se ha detectado la rutina de limpieza automatica de la cola de importacion.',
            $cleanup_available ? 'ok' : 'warning',
            array(
                'owner' => 'WP',
                'area' => 'maintenance',
                'confidence' => 100,
                'remediation' => array(
                    'kind' => 'Mantenimiento de importaciones',
                    'summary' => 'La retencion debe vivir en Import/Export y ejecutarse fuera de Plugin Validation.',
                    'steps' => array(
                        'Comprueba includes/import-export/queue/batch.php.',
                        'Verifica que seo_ie_batch_cleanup_old_files() y seo_ie_batch_retention_report() esten cargadas.',
                        'No actives WP-Cron solo para esta limpieza; el gestor de workers puede emitir el pulso de mantenimiento.',
                    ),
                ),
            )
        );

        if (!$cleanup_available) {
            return $results;
        }

        $report = seo_ie_batch_retention_report();
        $days = (int) ($report['retention_days'] ?? 0);
        $expired = (int) ($report['expired_files'] ?? 0);
        $buckets = isset($report['buckets']) && is_array($report['buckets']) ? $report['buckets'] : array();
        $bucket_parts = array();

        foreach (array('pending', 'processing', 'imported', 'failed') as $bucket) {
            $stats = isset($buckets[$bucket]) && is_array($buckets[$bucket]) ? $buckets[$bucket] : array();
            $bucket_parts[] = $bucket
                . ': ' . (int) ($stats['total'] ?? 0)
                . ' archivos, ' . (int) ($stats['expired'] ?? 0) . ' vencidos'
                . ((int) ($stats['protected_active'] ?? 0) > 0 ? ', ' . (int) $stats['protected_active'] . ' activo protegido' : '');
        }

        $results[] = seo_core_system_test_result(
            'technical',
            '7.13 Retencion de importaciones sin archivos vencidos',
            $expired === 0 && $days === 7,
            'Retencion: ' . $days . ' dias. ' . implode('; ', $bucket_parts) . '.',
            ($expired === 0 && $days === 7) ? 'ok' : 'warning',
            array(
                'owner' => 'WP',
                'area' => 'maintenance',
                'confidence' => 100,
                'evidence' => array(
                    'retention_days' => $days,
                    'expired_files' => $expired,
                    'total_files' => (int) ($report['total_files'] ?? 0),
                    'buckets' => $buckets,
                ),
                'remediation' => array(
                    'kind' => 'Retencion de archivos',
                    'summary' => 'Los CSV de pending, processing, imported y failed no deben superar siete dias salvo un processing realmente activo.',
                    'steps' => array(
                        'Comprueba que el gestor de workers emite seo_process_supervisor_periodic_pulse.',
                        'Revisa permisos de escritura/borrado sobre includes/import-export/migrations/.',
                        'Si processing contiene un archivo activo, dejalo terminar; el chequeo lo excluye de los vencidos borrables.',
                    ),
                ),
            )
        );

        $cleanup = isset($report['last_cleanup']) && is_array($report['last_cleanup']) ? $report['last_cleanup'] : array();
        $last_run = (int) ($cleanup['last_run'] ?? 0);
        $last_age = $last_run > 0 ? max(0, time() - $last_run) : 0;
        $recent = $last_run > 0 && $last_age <= 12 * HOUR_IN_SECONDS;

        if ($last_run <= 0) {
            $results[] = seo_core_system_test_result(
                'technical',
                '7.14 Ultima limpieza automatica de importaciones',
                false,
                'Todavia no hay una ejecucion de limpieza registrada. El primer pulso del gestor o una visita de administrador la registrara.',
                $expired > 0 ? 'warning' : 'info',
                array(
                    'owner' => 'WP',
                    'area' => 'maintenance',
                    'status' => $expired > 0 ? 'warning' : 'unknown',
                    'confidence' => 100,
                    'evidence' => array('last_run' => 0, 'expired_files' => $expired),
                )
            );
        } else {
            $detail = 'Ultima limpieza: ' . wp_date('Y-m-d H:i:s', $last_run)
                . '; escaneados: ' . (int) ($cleanup['scanned'] ?? 0)
                . '; borrados: ' . (int) ($cleanup['deleted'] ?? 0)
                . '; errores: ' . (int) ($cleanup['errors'] ?? 0) . '.';

            $results[] = seo_core_system_test_result(
                'technical',
                '7.14 Ultima limpieza automatica de importaciones',
                $recent || $expired === 0,
                $detail,
                ($recent || $expired === 0) ? 'ok' : 'warning',
                array(
                    'owner' => 'WP',
                    'area' => 'maintenance',
                    'confidence' => 100,
                    'evidence' => array(
                        'last_run' => $last_run,
                        'age_seconds' => $last_age,
                        'scanned' => (int) ($cleanup['scanned'] ?? 0),
                        'deleted' => (int) ($cleanup['deleted'] ?? 0),
                        'errors' => (int) ($cleanup['errors'] ?? 0),
                    ),
                )
            );
        }

        return $results;
    }
}
