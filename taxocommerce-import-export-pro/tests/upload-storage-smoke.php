<?php
/** Actual HTTP multi-file upload regression with isolated WordPress API doubles. */
if ( 'cli-server' === PHP_SAPI ) {
	define( 'ABSPATH', rtrim( $_SERVER['DOCUMENT_ROOT'], '/' ) . '/' );
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
	function __( $text, $domain = '' ) { return $text; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function current_user_can( $cap ) { return true; }
	function get_current_user_id() { return 1; }
	function get_current_blog_id() { return 1; }
	function wp_salt( $scheme ) { return 'isolated-upload-regression'; }
	function wp_unslash( $value ) { return $value; }
	function wp_normalize_path( $value ) { return str_replace( '\\', '/', $value ); }
	function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
	function wp_is_writable( $value ) { return is_writable( $value ); }
	function get_temp_dir() { return ABSPATH; }
	function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0700, true ); }
	function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
	function sanitize_file_name( $name ) { return basename( $name ); }
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
	function remove_accents( $value ) { return $value; }
	function get_registered_meta_keys( $object, $subtype = '' ) { return array(); }
	function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
	function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][ $key ] = $value; return true; }
	function delete_option( $key ) { unset( $GLOBALS['options'][ $key ] ); }
	function wp_next_scheduled( $hook, $args ) { return false; }
	function wp_schedule_single_event( $time, $hook, $args ) { return true; }
	function wp_clear_scheduled_hook( $hook, $args ) {}
	function wp_delete_file( $path ) { if ( is_file( $path ) ) unlink( $path ); }
	function wp_filesize( $path ) { return filesize( $path ); }
	class WP_Filesystem_Direct {
		public function __construct( $arg ) {}
		public function chmod( $path, $mode ) { return chmod( $path, $mode ); }
		public function copy( $source, $target, $overwrite = false, $mode = false ) {
			if ( ! $overwrite && file_exists( $target ) ) return false;
			return copy( $source, $target ) && ( ! $mode || chmod( $target, $mode ) );
		}
	}
	foreach ( array( 'native-fields', 'csv', 'load', 'batch' ) as $part ) {
		require dirname( __DIR__ ) . '/includes/class-' . $part . '.php';
	}
	$files = $_FILES['csv_files'] ?? array();
	$type = isset( $_GET['product'] ) ? 'product' : 'page';
	if ( isset( $_GET['fake'] ) ) $files['tmp_name'][0] = __FILE__;
	$result = \TaxoCommerce\ImportExportPro\Batch::create_import( $type, 3, $files );
	header( 'Content-Type: application/json' );
	if ( is_wp_error( $result ) ) {
		echo json_encode( array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() ) );
		return;
	}
	$root = \TaxoCommerce\ImportExportPro\Batch::root();
	$stored = array();
	foreach ( $result['files'] as $index => $file ) {
		$path = $root . '/' . $result['token'] . '/input-' . $index . '.csv';
		$stored[] = array( 'name' => $file['name'], 'bytes' => file_get_contents( $path ), 'mode' => fileperms( $path ) & 0777 );
	}
	\TaxoCommerce\ImportExportPro\Batch::command( 1, 'import', $type, $result['token'], 'pause' );
	\TaxoCommerce\ImportExportPro\Batch::command( 1, 'import', $type, $result['token'], 'clear' );
	echo json_encode( array( 'kind' => $result['type'], 'phase' => $result['phase'], 'files' => $stored, 'private' => realpath( dirname( $root ) ) === realpath( ini_get( 'upload_tmp_dir' ) ) ) );
	return;
}
if ( 'cli' !== PHP_SAPI ) exit( 1 );
function tciep_upload_assert( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
}
$root = sys_get_temp_dir() . '/tciep-http-' . bin2hex( random_bytes( 8 ) );
$public = $root . '/public';
$private = $root . '/private';
mkdir( $public . '/wp-content', 0700, true );
mkdir( $private, 0700, true );
$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
tciep_upload_assert( false !== $socket, 'Reserve local port' );
$address = stream_socket_get_name( $socket, false );
fclose( $socket );
$log = $root . '/server.log';
$server = proc_open( array( PHP_BINARY, '-d', 'sys_temp_dir=' . $public, '-d', 'upload_tmp_dir=' . $private, '-S', $address, '-t', $public, __FILE__ ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $log, 'a' ), 2 => array( 'file', $log, 'a' ) ), $pipes );
try {
	tciep_upload_assert( is_resource( $server ), 'Start native PHP HTTP server' );
	fclose( $pipes[0] );
	$ready = false;
	for ( $i = 0; $i < 50; ++$i ) {
		$connection = @stream_socket_client( 'tcp://' . $address, $errno, $errstr, 0.1 );
		if ( $connection ) { fclose( $connection ); $ready = true; break; }
		usleep( 50000 );
	}
	tciep_upload_assert( $ready, 'Server ready' );
	$send = static function ( $files, $query = '' ) use ( $address ) {
		$boundary = 'tciep-' . bin2hex( random_bytes( 8 ) );
		$body = '';
		foreach ( $files as $file ) {
			$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"csv_files[]\"; filename=\"{$file[0]}\"\r\nContent-Type: {$file[1]}\r\n\r\n{$file[2]}\r\n";
		}
		$body .= "--$boundary--\r\n";
		$context = stream_context_create( array( 'http' => array( 'method' => 'POST', 'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\nContent-Length: " . strlen( $body ) . "\r\n", 'content' => $body, 'timeout' => 5, 'ignore_errors' => true ) ) );
		$response = file_get_contents( 'http://' . $address . '/' . $query, false, $context );
		$data = json_decode( $response, true );
		tciep_upload_assert( is_array( $data ), 'JSON upload response: ' . $response );
		return $data;
	};
	$pages = array();
	for ( $i = 5; $i >= 1; --$i ) {
		$pages[] = array( $i . '.csv', $i % 2 ? 'text/plain' : 'application/octet-stream', "slug,title\npage-$i,Page $i\n" );
	}
	$result = $send( $pages );
	tciep_upload_assert( 'page' === ( $result['kind'] ?? '' ) && 'checking' === $result['phase'] && $result['private'], 'Five real HTTP CSV uploads received for page block into private fallback' );
	tciep_upload_assert( array( '1.csv', '2.csv', '3.csv', '4.csv', '5.csv' ) === array_column( $result['files'], 'name' ), 'Natural order preserved' );
	foreach ( $result['files'] as $i => $file ) {
		tciep_upload_assert( $file['bytes'] === $pages[4 - $i][2] && 0600 === $file['mode'], 'File bytes and privacy preserved' );
	}
	$products = array( array( 'products.csv', 'application/vnd.ms-excel', "sku,name\nA,Product A\n" ) );
	$result = $send( $products, '?product=1' );
	tciep_upload_assert( 'product' === ( $result['kind'] ?? '' ) && 'checking' === $result['phase'], 'Product block accepts actual CSV upload' );
	$result = $send( $products );
	tciep_upload_assert( 'csv' === ( $result['error'] ?? '' ) && false !== strpos( $result['message'], 'products.csv' ), 'Wrong block fails with filename rather than inferring type' );
	$result = $send( array( array( 'bad.zip', 'application/zip', 'not csv' ) ) );
	tciep_upload_assert( 'upload' === ( $result['error'] ?? '' ), 'Invalid extension rejected' );
	$result = $send( $pages, '?fake=1' );
	tciep_upload_assert( 'upload' === ( $result['error'] ?? '' ), 'Non-uploaded local file rejected' );
	$result = $send( array( array( 'broken.csv', 'text/plain', "slug,title\npage,Binary\0data\n" ) ) );
	tciep_upload_assert( 'csv' === ( $result['error'] ?? '' ), 'Binary input rejected during CSV inspection' );
	foreach ( glob( $private . '/tciep-*' ) as $spool ) {
		tciep_upload_assert( array( $spool . '/worker.lock' ) === glob( $spool . '/*' ), 'Only site lock remains, no abandoned job files' );
	}
	echo "Import-Export Pro: actual multipart upload of five CSVs, fixed type, private fallback and file diagnostics OK\n";
} finally {
	if ( is_resource( $server ) ) { proc_terminate( $server ); proc_close( $server ); }
	foreach ( glob( $private . '/tciep-*' ) as $spool ) {
		foreach ( glob( $spool . '/*' ) as $entry ) {
			if ( is_dir( $entry ) ) { foreach ( glob( $entry . '/*' ) as $file ) unlink( $file ); rmdir( $entry ); }
			else unlink( $entry );
		}
		rmdir( $spool );
	}
	if ( is_file( $log ) ) unlink( $log );
	rmdir( $private ); rmdir( $public . '/wp-content' ); rmdir( $public ); rmdir( $root );
}
