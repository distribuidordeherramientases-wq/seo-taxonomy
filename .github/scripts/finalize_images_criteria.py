"""Finalize the non-destructive delivery; never leave placeholder delete actions."""
from pathlib import Path
import re
p = Path('taxocommerce-images/includes/class-taxocommerce-images-maintenance.php')
s = p.read_text()
start = s.find('\tpublic static function resolve()')
end = s.find('\tpublic static function ajax()', start)
if start >= 0 and end > start:
    s = s[:start] + s[end:]
start = s.index('\tpublic static function ajax()')
end = s.index('\tpublic static function save_labels()', start)
s = s[:start] + r'''	public static function ajax() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 ); }
		check_ajax_referer( 'taxocommerce_images_maintenance', 'nonce' );
		$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( ! in_array( $mode, array( 'labels', 'cleanup' ), true ) || ! in_array( $operation, array( 'start', 'scan' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Operación no válida.' ), 400 );
		}
		if ( ! self::lock() ) { wp_send_json_error( array( 'message' => 'Otro lote está en curso.' ), 409 ); }
		try {
			if ( 'start' === $operation ) { self::begin( $mode ); }
			$state = self::scan( $mode );
			$result = is_wp_error( $state ) ? $state : array( 'done' => ( $state['status'] ?? '' ) === 'complete', 'state' => $state );
		} catch ( Throwable $error ) { $result = new WP_Error( 'exception', sanitize_text_field( $error->getMessage() ) ); }
		finally { self::unlock(); }
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		wp_send_json_success( $result );
	}
''' + s[end:]
assert 'TaxoCommerce_Images_Deduplicate' not in s
assert 'data-tci-operation="resolve"' not in s
p.write_text(s)
marker = Path('taxocommerce-images/includes/.criteria_applied')
if marker.exists():
    marker.unlink()
print('No destructive placeholder, unresolved callback or HTTP scanner remains in Images.')
