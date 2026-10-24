<?php
/**
 * Production deployment status for TaxoCommerce sibling plugins.
 *
 * Exposes only the slugs of known TaxoCommerce plugins that WordPress reports
 * as active. No credentials, versions, paths or site data are returned.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return known TaxoCommerce sibling plugins that are active in WordPress.
 *
 * @return array
 */
function seo_taxocommerce_active_deploy_modules() {
	$known = array(
		'taxocommerce-core'          => 'taxocommerce-core/taxocommerce-core.php',
		'taxocommerce-images'        => 'taxocommerce-images/taxocommerce-images.php',
		'taxocommerce-import-export' => 'taxocommerce-import-export/taxocommerce-import-export.php',
		'taxocommerce-system-check'  => 'taxocommerce-system-check/taxocommerce-system-check.php',
		'taxocommerce-templates'     => 'taxocommerce-templates/taxocommerce-templates.php',
		'taxocommerce-dependiente'   => 'taxocommerce-dependiente/taxocommerce-dependiente.php',
		'taxocommerce-faqs'          => 'taxocommerce-faqs/taxocommerce-faqs.php',
		'taxocommerce-vocabulary'    => 'taxocommerce-vocabulary/taxocommerce-vocabulary.php',
		'taxocommerce-google-bing'   => 'taxocommerce-google-bing/taxocommerce-google-bing.php',
		'taxocommerce-auditor'       => 'taxocommerce-auditor/taxocommerce-auditor.php',
		'taxocommerce-redirects'     => 'taxocommerce-redirects/taxocommerce-redirects.php',
	);

	$active = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() ) {
		$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		$active  = array_merge( $active, $network );
	}

	$active = array_fill_keys( array_map( 'strval', $active ), true );
	$result = array();

	foreach ( $known as $slug => $plugin_file ) {
		if ( isset( $active[ $plugin_file ] ) ) {
			$result[] = $slug;
		}
	}

	return $result;
}

/**
 * Public read-only endpoint used by the production GitHub deployment.
 *
 * @return void
 */
function seo_taxocommerce_deploy_status_ajax() {
	nocache_headers();
	wp_send_json_success(
		array(
			'modules' => seo_taxocommerce_active_deploy_modules(),
		)
	);
}
add_action( 'wp_ajax_taxocommerce_deploy_active_modules', 'seo_taxocommerce_deploy_status_ajax' );
add_action( 'wp_ajax_nopriv_taxocommerce_deploy_active_modules', 'seo_taxocommerce_deploy_status_ajax' );
