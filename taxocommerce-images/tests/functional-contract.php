<?php
/** Isolated CI fixtures only. Never run against a live WordPress installation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'IMAGES_CONTRACT_TEST' ) || ! defined( 'DB_NAME' ) || 'images_test' !== DB_NAME || 'http://images.test' !== untrailingslashit( home_url() ) ) {
	return;
}

function taxocommerce_images_test_assert( $value, $message ) {
	if ( ! $value ) { WP_CLI::error( 'FAILED: ' . $message ); }
	WP_CLI::log( 'PASS: ' . $message );
}
function taxocommerce_images_test_image( $name, $seed, $bytes = null, $mime = 'image/png' ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	if ( null === $bytes ) {
		$image = imagecreatetruecolor( 400, 300 );
		for ( $y = 0; $y < 300; ++$y ) {
			$color = imagecolorallocate( $image, ( $y + $seed ) % 256, ( $y * 3 + $seed * 5 ) % 256, ( $y * 7 + $seed ) % 256 );
			imageline( $image, 0, $y, 399, $y, $color );
		}
		ob_start();
		if ( 'image/jpeg' === $mime ) { imagejpeg( $image, null, 97 ); } else { imagepng( $image ); }
		$bytes = ob_get_clean(); imagedestroy( $image );
	}
	$upload = wp_upload_bits( $name, null, $bytes );
	taxocommerce_images_test_assert( ! $upload['error'], 'real uploaded image file: ' . $name );
	$id = wp_insert_attachment( array( 'post_title' => 'Fotografía descriptiva ' . $seed, 'post_mime_type' => $mime, 'post_status' => 'inherit' ), $upload['file'] );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	return $id;
}

$admin = get_user_by( 'login', 'imagesadmin' );
wp_set_current_user( $admin->ID );

update_option( 'tcd_work_priority', 5, false );
$priority_result = TaxoCommerce_Images_Load::set_priority( 1 );
taxocommerce_images_test_assert( 1 === $priority_result && 1 === TaxoCommerce_Images_Load::priority(), 'Images stores its own workload priority' );
taxocommerce_images_test_assert( 5 === (int) get_option( 'tcd_work_priority' ), 'Images priority does not alter Dependiente settings' );
taxocommerce_images_test_assert( TaxoCommerce_Images_Load::batch_size( 'cleanup' ) < TaxoCommerce_Images_Load::batch_size( 'labels' ), 'file hashing uses a smaller batch than metadata inspection' );
TaxoCommerce_Images_Load::set_priority( 3 );


register_post_type( 'product', array( 'public' => true, 'supports' => array( 'title', 'thumbnail' ) ) );
register_taxonomy( 'product_cat', array( 'product', 'page', 'post' ), array( 'hierarchical' => true, 'public' => true ) );
add_theme_support( 'post-thumbnails' );
$cat = wp_insert_term( 'Taladros de prueba', 'product_cat' )['term_id'];
$cat_image = taxocommerce_images_test_image( 'category-image.png', 4 );
$other_image = taxocommerce_images_test_image( 'other-product.png', 9 );
$own_image = taxocommerce_images_test_image( 'own-product.png', 19 );
update_term_meta( $cat, 'thumbnail_id', $cat_image );
$other = wp_insert_post( array( 'post_type' => 'product', 'post_title' => 'Producto distinto', 'post_status' => 'publish' ) );
wp_set_object_terms( $other, array( (int) $cat ), 'product_cat' ); set_post_thumbnail( $other, $other_image );
$product = wp_insert_post( array( 'post_type' => 'product', 'post_title' => 'Producto sin imagen', 'post_status' => 'publish' ) );
wp_set_object_terms( $product, array( (int) $cat ), 'product_cat' );
taxocommerce_images_test_assert( array() === TaxoCommerce_Images_Assignment::candidates( 'product', $product ), 'a product cannot use a category or another product photograph' );
update_post_meta( $product, '_product_image_gallery', (string) $own_image );
$choices = wp_list_pluck( TaxoCommerce_Images_Assignment::candidates( 'product', $product ), 'id' );
taxocommerce_images_test_assert( array( $own_image ) === $choices, 'a product can reuse its own gallery only' );
taxocommerce_images_test_assert( ! TaxoCommerce_Images_Assignment::has_image( 'product', $product ), 'missing featured product image stays reported' );
$manual_image = taxocommerce_images_test_image( 'manual-media-choice.png', 27 );
$manual_result = TaxoCommerce_Images_Assignment::assign_from_media( 'product', $product, $manual_image );
taxocommerce_images_test_assert( true === $manual_result && $manual_image === (int) get_post_thumbnail_id( $product ), 'administrator can explicitly assign any valid existing Media image' );
$manual_second = TaxoCommerce_Images_Assignment::assign_from_media( 'product', $product, $cat_image );
taxocommerce_images_test_assert( is_wp_error( $manual_second ) && $manual_image === (int) get_post_thumbnail_id( $product ), 'manual Media assignment never overwrites an existing image' );

$baseline = TaxoCommerce_Images_Assignment::missing( 'page', 1, 1 )['total'];
$pages = array();
for ( $i = 0; $i < 100; ++$i ) {
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Página de prueba ' . $i, 'post_status' => 'publish' ) );
	$pages[] = $id;
	if ( $i >= 60 ) { wp_set_object_terms( $id, array( (int) $cat ), 'product_cat' ); }
}
$report = TaxoCommerce_Images_Assignment::missing( 'page' );
taxocommerce_images_test_assert( 25 === count( $report['items'] ), 'report displays 25 rows' );
taxocommerce_images_test_assert( $baseline + 100 === $report['total'], 'KPI counts all pages, not just the visible 25' );
$first = TaxoCommerce_Images_Assignment::process_assignment_batch( 'page', 0 );
taxocommerce_images_test_assert( 25 === $first['seen'] && 0 === $first['assigned'] && $first['cursor'] > 0, 'unresolvable first batch advances its cursor' );
$result = $first; $rounds = 0; $assigned = 0;
while ( ! $result['done'] && ++$rounds < 10 ) {
	$result = TaxoCommerce_Images_Assignment::process_assignment_batch( 'page', $result['cursor'] );
	$assigned += $result['assigned'];
}
taxocommerce_images_test_assert( $result['done'] && 40 === $assigned, 'later solvable pages are reached despite earlier unresolved pages' );
taxocommerce_images_test_assert( $baseline + 60 === $result['remaining'], 'KPI still includes every unresolved page' );
taxocommerce_images_test_assert( $cat_image === (int) get_post_thumbnail_id( $pages[99] ), 'page receives its linked category image' );
taxocommerce_images_test_assert( $other_image === (int) get_post_thumbnail_id( $other ), 'existing product image is never overwritten' );

$labels_image = taxocommerce_images_test_image( 'metadata-check.png', 33 );
$flags = TaxoCommerce_Images_Maintenance::label_flags( $labels_image );
taxocommerce_images_test_assert( in_array( 'missing_alt', $flags, true ), 'missing informative ALT is reported' );
update_post_meta( $labels_image, '_wp_attachment_image_alt', 'Taladro con batería de prueba' );
taxocommerce_images_test_assert( ! in_array( 'missing_alt', TaxoCommerce_Images_Maintenance::label_flags( $labels_image ), true ), 'an entered ALT resolves the missing attribute' );
update_post_meta( $labels_image, '_wp_attachment_image_alt', '' );
update_post_meta( $labels_image, '_taxocommerce_images_decorative', 1 );
taxocommerce_images_test_assert( ! in_array( 'missing_alt', TaxoCommerce_Images_Maintenance::label_flags( $labels_image ), true ), 'administrator-marked decorative image accepts empty ALT' );
taxocommerce_images_test_assert( ! in_array( 'missing_caption', $flags, true ) && ! in_array( 'missing_description', $flags, true ), 'caption and description are not mandatory errors' );
taxocommerce_images_test_assert( false === has_action( 'admin_post_taxocommerce_images_http_scan' ), 'HTTP scanner hook is removed' );
taxocommerce_images_test_assert( ! file_exists( TAXOCOMMERCE_IMAGES_PATH . 'includes/class-taxocommerce-images-http.php' ), 'HTTP scanner source is removed from Images' );

$unused = taxocommerce_images_test_image( 'unused-unique.png', 80 );
$duplicate = taxocommerce_images_test_image( 'same-bytes-copy.png', 81, file_get_contents( get_attached_file( $unused ) ) );
TaxoCommerce_Images_Maintenance::begin( 'cleanup' );
for ( $i = 0; $i < 30; ++$i ) { $scan = TaxoCommerce_Images_Maintenance::scan( 'cleanup' ); if ( 'complete' === $scan['status'] ) { break; } }
taxocommerce_images_test_assert( 'complete' === $scan['status'] && $scan['scanned'] === $scan['total'], 'cleanup inspection covers the entire snapshot' );
$row = get_post_meta( $duplicate, '_taxocommerce_images_cleanup_result', true );
taxocommerce_images_test_assert( 'duplicate' === $row['status'] && $unused === (int) $row['duplicate'], 'exact full-file duplicate is detected' );
taxocommerce_images_test_assert( absint( $scan['counts']['duplicate'] ?? 0 ) >= 1, 'cleanup report counts exact duplicate copies' );
taxocommerce_images_test_assert( absint( $scan['counts']['keeper'] ?? 0 ) >= 1, 'cleanup report keeps one canonical original for duplicate groups' );
taxocommerce_images_test_assert( false !== strpos( (string) ( $scan['message'] ?? '' ), 'Escaneo completo' ), 'cleanup state exposes a full-library completion summary' );
taxocommerce_images_test_assert( is_file( get_attached_file( $unused ) ) && is_file( get_attached_file( $duplicate ) ), 'inspection performs no unconfirmed deletion' );

TaxoCommerce_Images_Maintenance::begin( 'labels' );
for ( $i = 0; $i < 30; ++$i ) { $scan = TaxoCommerce_Images_Maintenance::scan( 'labels' ); if ( 'complete' === $scan['status'] ) { break; } }
taxocommerce_images_test_assert( 'complete' === $scan['status'] && $scan['scanned'] === $scan['total'], 'attribute inspection covers the entire library without HTTP' );
$subscriber = wp_create_user( 'limiteduser', 'local-test-password', 'limited@example.test' );
wp_set_current_user( $subscriber );
taxocommerce_images_test_assert( is_wp_error( TaxoCommerce_Images_Assignment::process_assignment_batch( 'page' ) ), 'non-administrator cannot run assignment' );
taxocommerce_images_test_assert( is_wp_error( TaxoCommerce_Images_Maintenance::begin( 'cleanup' ) ), 'non-administrator cannot start maintenance' );
wp_set_current_user( $admin->ID );

$jpeg = taxocommerce_images_test_image( 'conversion-original.jpg', 109, null, 'image/jpeg' );
$old_file = get_attached_file( $jpeg );
if ( tci_webp_supported() ) {
	$converted = tci_webp_process_attachment( $jpeg );
	taxocommerce_images_test_assert( 1 === (int) $converted['converted'], 'real JPEG converts to WebP' );
	taxocommerce_images_test_assert( 'image/webp' === get_post_mime_type( $jpeg ) && is_file( get_attached_file( $jpeg ) ), 'converted attachment remains valid' );
	taxocommerce_images_test_assert( ! is_file( $old_file ) && ! get_post_meta( $jpeg, '_tci_webp_leftover_backups', true ), 'successful conversion leaves no permanent JPEG backup' );
} else { WP_CLI::warning( 'WebP editor unavailable: conversion acceptance not verified.' ); }
WP_CLI::success( 'Images functional contract passed in isolated WordPress. Automatic deletion/consolidation is not enabled.' );
