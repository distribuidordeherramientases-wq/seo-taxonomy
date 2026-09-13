<?php
/**
 * Database updater for SEO Taxonomy.
 *
 * Historical migrations are executed first. The current idempotent installer
 * then reconciles the complete target schema so a missing migration file can
 * never result in merely bumping the database version without creating the
 * tables/columns used by the current code.
 */

defined('ABSPATH') || exit;

final class SEO_System_Updater
{
    private const DB_VERSION_OPTION = 'seo_system_db_version';
    private const LAST_ERROR_OPTION = 'seo_system_last_upgrade_error';
    private const LAST_STATUS_OPTION = 'seo_system_last_upgrade_status';

    public static function maybe_upgrade(): void
    {
        if (!defined('SEO_SYSTEM_DB_VERSION')) {
            return;
        }

        $installed_version = (string) get_option(self::DB_VERSION_OPTION, '0.0.0');
        if (version_compare($installed_version, SEO_SYSTEM_DB_VERSION, '>=')) {
            return;
        }

        try {
            self::run_pending_migrations($installed_version, SEO_SYSTEM_DB_VERSION);

            require_once SEO_SYSTEM_PATH . 'includes/class-seo-installer.php';
            SEO_System_Installer::install();

            self::register_success(SEO_SYSTEM_DB_VERSION);
        } catch (Throwable $exception) {
            self::register_failure(SEO_SYSTEM_DB_VERSION, $exception->getMessage());
            error_log('[SEO Taxonomy] Upgrade error: ' . $exception->getMessage());
        }
    }

    /**
     * Run all historical migrations newer than the installed version.
     *
     * If there are no migration files, this method intentionally does not mark
     * the upgrade as complete. Schema reconciliation by SEO_System_Installer
     * must succeed first.
     */
    private static function run_pending_migrations(
        string $installed_version,
        string $target_version
    ): void {
        $migrations = self::get_migrations();

        foreach ($migrations as $version => $file) {
            if (version_compare($version, $installed_version, '<=')) {
                continue;
            }
            if (version_compare($version, $target_version, '>')) {
                continue;
            }

            self::run_migration($version, $file);

            /*
             * Persist progress after each historical migration. If a later
             * migration or final schema reconciliation fails, completed
             * migrations are not repeated on the next request.
             */
            update_option(self::DB_VERSION_OPTION, $version, false);
            $installed_version = $version;
        }
    }

    /**
     * Locate migration files named includes/migrations/x.y.z.php.
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

    private static function run_migration(string $version, string $file): void
    {
        if (!is_readable($file)) {
            throw new RuntimeException('Migration file is not readable: ' . $file);
        }

        $migration = require $file;
        if (!is_callable($migration)) {
            throw new RuntimeException('Migration ' . $version . ' does not return a callable.');
        }

        $result = $migration();
        if ($result === false || is_wp_error($result)) {
            $message = is_wp_error($result)
                ? $result->get_error_message()
                : 'Migration returned false.';
            throw new RuntimeException('Migration ' . $version . ' failed: ' . $message);
        }
    }

    private static function register_success(string $version): void
    {
        update_option(self::DB_VERSION_OPTION, $version, false);
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

    private static function register_failure(string $version, string $message): void
    {
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
