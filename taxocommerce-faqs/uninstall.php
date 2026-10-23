<?php
/** Permanent removal is explicit: deactivate alone never executes this file. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) || ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__ . '/includes/class-taxocommerce-faqs-lifecycle.php';
TaxoCommerce_FAQs_Lifecycle::uninstall();
