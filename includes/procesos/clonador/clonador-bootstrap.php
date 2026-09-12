<?php
/**
 * Integracion del Clonador para Academia con el Gestor de procesos nativo.
 *
 * No ejecuta una clonacion por cargar WordPress. Solo registra el proceso y
 * continua trabajos que ya fueron iniciados expresamente por un administrador.
 *
 * @package SEOSystem
 * @subpackage Processes_Clonador
 * @since 2.5.10
 */

defined( 'ABSPATH' ) || exit;

$seo_clonador_process_file = __DIR__ . '/process.php';

if ( ! is_readable( $seo_clonador_process_file ) ) {
    error_log( '[SEO System Clonador] Falta includes/procesos/clonador/process.php.' );

    add_action(
        'admin_notices',
        static function () {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            echo '<div class="notice notice-error"><p><strong>SEO System - Clonador:</strong> '
                . 'No se pudo cargar la integracion con el Gestor de procesos porque falta process.php.'
                . '</p></div>';
        }
    );

    return;
}

require_once $seo_clonador_process_file;

if ( ! class_exists( 'SEO_Clonador_Process', false ) ) {
    error_log( '[SEO System Clonador] process.php fue cargado pero SEO_Clonador_Process no existe.' );

    add_action(
        'admin_notices',
        static function () {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            echo '<div class="notice notice-error"><p><strong>SEO System - Clonador:</strong> '
                . 'La clase SEO_Clonador_Process no esta disponible. Revisa process.php.'
                . '</p></div>';
        }
    );

    return;
}

SEO_Clonador_Process::init();
