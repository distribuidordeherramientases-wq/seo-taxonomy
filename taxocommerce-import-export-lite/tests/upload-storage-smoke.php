<?php
/** Real multipart upload regression, with native PHP uploads and local WP API stubs. Test only. */
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
	function sanitize_file_name( $name ) { return basename( $name ); }
	function wp_unslash( $value ) { return stripslashes( $value ); }
	function wp_normalize_path( $value ) { return str_replace( '\\', '/', $value ); }
	function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
	function wp_is_writable( $path ) { return is_writable( $path ); }
	function get_temp_dir() { return ABSPATH; } // Public fallback must be refused.
	function wp_tempnam( $name, $directory ) { return tempnam( $directory, $name . '-' ); }
	function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
	function wp_delete_file( $path ) { unlink( $path ); }
	function wp_filesize( $path ) { return filesize( $path ); }
	require dirname( __DIR__ ) . '/includes/class-admin.php';
	$file = $_FILES['csv_file'] ?? array();
	if ( isset( $_GET['public_only'] ) ) {
		$_SERVER['DOCUMENT_ROOT'] = dirname( rtrim( ABSPATH, '/' ) );
	}
	if ( isset( $_GET['fake'] ) ) {
		$file['tmp_name'] = __FILE__;
	}
	$result = \TaxoCommerce\ImportExportLite\Admin::store_upload( $file );
	header( 'Content-Type: application/json' );
	if ( $result instanceof WP_Error ) {
		echo json_encode( array( 'error' => $result->get_error_code() ) );
	} else {
		$path = $result['path'];
		echo json_encode( array(
			'stored' => true,
			'name' => $result['name'],
			'content' => file_get_contents( $path ),
			'private' => realpath( dirname( $path ) ) === realpath( ini_get( 'upload_tmp_dir' ) ),
			'opaque' => false === strpos( basename( $path ), $result['name'] ),
			'permissions' => fileperms( $path ) & 0777,
		) );
		wp_delete_file( $path );
	}
	return;
}
if ( 'cli' !== PHP_SAPI ) exit( 1 );

function tciel_upload_assert( $condition, $message ) {
	if ( ! $condition ) throw new RuntimeException( $message );
}
$root = sys_get_temp_dir() . '/tciel-upload-smoke-' . bin2hex( random_bytes( 8 ) );
$public = $root . '/public';
$private = $root . '/private';
mkdir( $public . '/wp-content', 0700, true );
mkdir( $private, 0700, true );
$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
tciel_upload_assert( false !== $socket, 'Reserve local test port' );
$address = stream_socket_get_name( $socket, false );
fclose( $socket );
$log = $root . '/server.log';
$server = proc_open( array( PHP_BINARY, '-d', 'upload_tmp_dir=' . $private, '-S', $address, '-t', $public, __FILE__ ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $log, 'a' ), 2 => array( 'file', $log, 'a' ) ), $pipes );
try {
	tciel_upload_assert( is_resource( $server ), 'Start local PHP upload test server' );
	fclose( $pipes[0] );
	$ready = false;
	for ( $i = 0; $i < 50; ++$i ) {
		$connection = @stream_socket_client( 'tcp://' . $address, $errno, $errstr, 0.1 );
		if ( $connection ) {
			fclose( $connection );
			$ready = true;
			break;
		}
		usleep( 50000 );
	}
	tciel_upload_assert( $ready, 'Local HTTP server is ready' );
	$upload = static function ( $name, $mime, $csv, $query = '' ) use ( $address ) {
		$boundary = 'tciel-' . bin2hex( random_bytes( 8 ) );
		$body = "--$boundary\r\nContent-Disposition: form-data; name=\"csv_file\"; filename=\"$name\"\r\nContent-Type: $mime\r\n\r\n$csv\r\n--$boundary--\r\n";
		$context = stream_context_create( array( 'http' => array( 'method' => 'POST', 'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\nContent-Length: " . strlen( $body ) . "\r\n", 'content' => $body, 'timeout' => 5, 'ignore_errors' => true ) ) );
		$response = file_get_contents( 'http://' . $address . '/' . $query, false, $context );
		$data = json_decode( $response, true );
		tciel_upload_assert( is_array( $data ), 'Valid JSON HTTP upload response: ' . $response );
		return $data;
	};
	foreach ( array(
		array( 'paginas.csv', 'application/octet-stream', "slug,title,content\npagina-prueba,Página de prueba,Texto\n" ),
		array( 'productos.csv', 'text/plain', "sku,name,regular_price\nTEST-001,Producto de prueba,19.95\n" ),
	) as $case ) {
		$result = $upload( ...$case );
		tciel_upload_assert( ! empty( $result['stored'] ) && $result['private'] && $result['opaque'], 'CSV accepted in private PHP directory despite public WP fallback' );
		tciel_upload_assert( $result['name'] === $case[0] && $result['content'] === $case[2], 'Uploaded bytes and display name preserved' );
		tciel_upload_assert( 0600 === $result['permissions'], 'Stored CSV permissions are private' );
	}
	tciel_upload_assert( 'upload_invalid' === $upload( 'bad.zip', 'application/zip', 'not CSV' )['error'], 'Wrong extension rejected' );
	tciel_upload_assert( 'upload_invalid' === $upload( 'fake.csv', 'text/csv', 'slug,title' , '?fake=1' )['error'], 'Non-uploaded local file rejected' );
	tciel_upload_assert( 'upload_private_dir' === $upload( 'pages.csv', 'text/csv', "slug,title\np,Page\n", '?public_only=1' )['error'], 'Refuse storage when no private directory is available' );
	tciel_upload_assert( array() === glob( $private . '/*' ), 'No retained temporary files in regression' );
	echo "Import-Export Lite: actual HTTP uploads, mobile MIME, private fallback, byte integrity and rejection paths OK\n";
} finally {
	if ( is_resource( $server ) ) {
		proc_terminate( $server );
		proc_close( $server );
	}
	foreach ( glob( $private . '/*' ) as $path ) unlink( $path );
	if ( is_file( $log ) ) unlink( $log );
	rmdir( $private );
	rmdir( $public . '/wp-content' );
	rmdir( $public );
	rmdir( $root );
}
