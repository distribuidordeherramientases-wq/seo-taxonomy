<?php
/**
 * Minimal resumable-process contract reused by the standalone Ingeniero service.
 *
 * The legacy monolith may have registered the same interface first. Do not
 * redeclare it when both plugins are active during the migration.
 */
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'SEO_Managed_Service_Process', false ) ) {
    interface SEO_Managed_Service_Process {
        public static function has_pending();
        public static function process_slice( $budget, $source = 'process_manager' );
        public static function progress();
        public static function health();
        public static function pause();
        public static function resume();
    }
}
