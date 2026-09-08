<?php
/**
 * Integracion del Clonador para Academia con el subsistema de Procesos.
 *
 * @package SEOSystem
 * @subpackage Processes_Clonador
 * @since 2.5.3
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/process.php';
SEO_Clonador_Process::init();
