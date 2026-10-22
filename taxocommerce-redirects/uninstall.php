<?php
/** Permanent cleanup. Deactivation never executes this file. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-lifecycle.php';
TaxoCommerce\Redirects\Lifecycle::uninstall();
