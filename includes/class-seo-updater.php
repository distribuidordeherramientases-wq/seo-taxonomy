<?php
/**
 * Gestor de migraciones de SEO Taxonomy.
 *
 * Ejecuta en orden las migraciones pendientes de base de datos.
 */

defined('ABSPATH') || exit;

final class SEO_System_Updater
{
    /**
     * Nombre de la opción donde se guarda la versión de la base de datos.
     */
    private const DB_VERSION_OPTION = 'seo_system_db_version';

    /**
     * Último error de actualización.
     */
    private const LAST_ERROR_OPTION = 'seo_system_last_upgrade_error';

    /**
     * Fecha y resultado de la última actualización.
     */
    private const LAST_STATUS_OPTION = 'seo_system_last_upgrade_status';

    /**
     * Comprueba y ejecuta las migraciones pendientes.
     */
    public static function maybe_upgrade(): void
    {
        if (!defined('SEO_SYSTEM_DB_VERSION')) {
            return;
        }

        $installed_version = (string) get_option(
            self::DB_VERSION_OPTION,
            '0.0.0'
        );

        if (
            version_compare(
                $installed_version,
                SEO_SYSTEM_DB_VERSION,
                '>='
            )
        ) {
            return;
        }

        self::run_pending_migrations(
            $installed_version,
            SEO_SYSTEM_DB_VERSION
        );
    }

    /**
     * Ejecuta todas las migraciones posteriores a la versión instalada.
     */
    private static function run_pending_migrations(
        string $installed_version,
        string $target_version
    ): void {
        $migrations = self::get_migrations();

        if (empty($migrations)) {
            self::register_success($target_version);
            return;
        }

        foreach ($migrations as $version => $file) {
            if (version_compare($version, $installed_version, '<=')) {
                continue;
            }

            if (version_compare($version, $target_version, '>')) {
                continue;
            }

            try {
                self::run_migration($version, $file);

                /*
                 * Guardamos la versión después de cada migración.
                 * Si una migración posterior falla, no se repetirán
                 * las que ya terminaron correctamente.
                 */
                update_option(
                    self::DB_VERSION_OPTION,
                    $version,
                    false
                );

                $installed_version = $version;
            } catch (Throwable $exception) {
                self::register_failure(
                    $version,
                    $exception->getMessage()
                );

                error_log(
                    sprintf(
                        '[SEO Taxonomy] Error en migración %s: %s',
                        $version,
                        $exception->getMessage()
                    )
                );

                return;
            }
        }

        self::register_success($target_version);
    }

    /**
     * Localiza y ordena los archivos de migración.
     *
     * Formato esperado:
     * includes/migrations/2.1.0.php
     */
    private static function get_migrations(): array
    {
        $directory = SEO_SYSTEM_PATH . 'includes/migrations/';

        if (!is_dir($directory)) {
            return [];
        }

        $files = glob($directory . '*.php');

        if (!is_array($files)) {
            return [];
        }

        $migrations = [];

        foreach ($files as $file) {
            $version = basename($file, '.php');

            if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                continue;
            }

            $migrations[$version] = $file;
        }

        uksort(
            $migrations,
            static function (string $version_a, string $version_b): int {
                return version_compare($version_a, $version_b);
            }
        );

        return $migrations;
    }

    /**
     * Ejecuta una migración concreta.
     */
    private static function run_migration(
        string $version,
        string $file
    ): void {
        if (!is_readable($file)) {
            throw new RuntimeException(
                sprintf(
                    'El archivo de migración %s no es legible.',
                    $file
                )
            );
        }

        $migration = require $file;

        if (!is_callable($migration)) {
            throw new RuntimeException(
                sprintf(
                    'La migración %s no devuelve una función ejecutable.',
                    $version
                )
            );
        }

        $result = $migration();

        if ($result === false) {
            throw new RuntimeException(
                sprintf(
                    'La migración %s devolvió un resultado fallido.',
                    $version
                )
            );
        }
    }

    /**
     * Registra una actualización correcta.
     */
    private static function register_success(string $version): void
    {
        update_option(
            self::DB_VERSION_OPTION,
            $version,
            false
        );

        update_option(
            self::LAST_STATUS_OPTION,
            [
                'status'       => 'completed',
                'version'      => $version,
                'completed_at' => current_time('mysql'),
            ],
            false
        );

        delete_option(self::LAST_ERROR_OPTION);
    }

    /**
     * Registra una actualización fallida.
     */
    private static function register_failure(
        string $version,
        string $message
    ): void {
        update_option(
            self::LAST_STATUS_OPTION,
            [
                'status'    => 'failed',
                'version'   => $version,
                'failed_at' => current_time('mysql'),
            ],
            false
        );

        update_option(
            self::LAST_ERROR_OPTION,
            [
                'version' => $version,
                'message' => $message,
                'date'    => current_time('mysql'),
            ],
            false
        );
    }
}