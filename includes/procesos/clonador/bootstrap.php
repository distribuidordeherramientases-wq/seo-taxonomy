<?php
/**
 * Integracion del Clonador para Academia con el Gestor de procesos nativo.
 *
 * @package SEOSystem
 * @subpackage Processes_Clonador
 * @since 2.5.6
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/process.php';
SEO_Clonador_Process::init();
