<?php
/**
 * Contrato minimo para servicios reanudables gestionados por el supervisor.
 */
defined('ABSPATH') || exit;

interface SEO_Managed_Service_Process {
    public static function has_pending();
    public static function process_slice($budget, $source = 'process_manager');
    public static function progress();
    public static function health();
    public static function pause();
    public static function resume();
}
