<?php
/**
 * Actions for TaxoCommerce Images.
 *
 * @package TaxoCommerceImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles image writes through WordPress APIs.
 */
final class TaxoCommerce_Images_Actions {

	const SETTINGS_OPTION = 'taxocommerce_images_settings';
	const BACKUP_META     = '_taxocommerce_images_backup_file';

	/**
	 * Register actions.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_taxocommerce_images_save_metadata', array( $this, 'save_metadata' ) );
		add_action( 'admin_post_taxocommerce_images_regenerate', array( $this, 'regenerate' ) );
		add_action( 'admin_post_taxocommerce_images_sideload', array( $this, 'sideload' ) );
		add_action( 'admin_post_taxocommerce_images_optimize', array( $this, 'optimize' ) );
		add_action( 'admin_post_taxocommerce_images_restore_backup', array( $this, 'restore_backup' ) );
		add_action( 'admin_post_taxocommerce_images_save_settings', array( $this, 'save_settings' ) );
		add_filter( 'wp_editor_set_quality', array( $this, 'filter_quality' ), 10, 2 );
		add_filter( 'big_image_size_threshold', array( $this, 'filter_big_image_threshold' ), 10, 4 );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'max_width'          => 2000,
			'max_height'         => 2000,
			'max_bytes'          => 1572864,
			'quality'            => 82,
			'enforce_upload_max' => 0,
			'enforce_quality'    => 0,
		);
	}

	/**
	 * Current settings.
	 *
	 * @return array
	 */
	public static function settings() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), self::default_settings() );
	}

	/**
	 * Save attachment title, ALT, caption and description.
	 *
	 * @return void
	 */
	public function save_metadata() {
		$this->guard_uploads();
		check_admin_referer( 'taxocommerce_images_save_metadata', 'taxocommerce_images_nonce' );

		$id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			$this->redirect( 'error', __( 'La imagen indicada no se puede editar.', 'taxocommerce-images' ), 'library' );
		}

		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$alt         = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
		$caption     = isset( $_POST['caption'] ) ? wp_kses_post( wp_unslash( $_POST['caption'] ) ) : '';
		$description = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';

		if ( '' === $title ) {
			$this->redirect( 'error', __( 'El título de la imagen no puede quedar vacío.', 'taxocommerce-images' ), 'library', $id );
		}

		$result = wp_update_post(
			array(
				'ID'           => $id,
				'post_title'   => $title,
				'post_excerpt' => $caption,
				'post_content' => $description,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'error', $result->get_error_message(), 'library', $id );
		}

		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		$this->redirect( 'ok', __( 'Metadatos de imagen actualizados.', 'taxocommerce-images' ), 'library', $id );
	}

	/**
	 * Regenerate WordPress attachment metadata and sub-sizes.
	 *
	 * @return void
	 */
	public function regenerate() {
		$this->guard_uploads();
		check_admin_referer( 'taxocommerce_images_regenerate', 'taxocommerce_images_nonce' );

		$id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $id || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			$this->redirect( 'error', __( 'La imagen indicada no se puede regenerar.', 'taxocommerce-images' ), 'thumbnails' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			$this->redirect( 'error', __( 'El archivo físico de la imagen no existe.', 'taxocommerce-images' ), 'thumbnails', $id );
		}

		$metadata = wp_generate_attachment_metadata( $id, $file );
		if ( empty( $metadata ) || is_wp_error( $metadata ) ) {
			$this->redirect( 'error', __( 'WordPress no pudo regenerar las miniaturas.', 'taxocommerce-images' ), 'thumbnails', $id );
		}

		wp_update_attachment_metadata( $id, $metadata );
		$this->redirect( 'ok', __( 'Miniaturas regeneradas correctamente.', 'taxocommerce-images' ), 'thumbnails', $id );
	}

	/**
	 * Download an external image into Media and optionally assign it to a product.
	 *
	 * @return void
	 */
	public function sideload() {
		$this->guard_uploads();
		check_admin_referer( 'taxocommerce_images_sideload', 'taxocommerce_images_nonce' );

		$url       = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';
		$parent_id = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;
		$mode      = isset( $_POST['assign_mode'] ) ? sanitize_key( wp_unslash( $_POST['assign_mode'] ) ) : 'media';
		$title     = isset( $_POST['image_title'] ) ? sanitize_text_field( wp_unslash( $_POST['image_title'] ) ) : '';

		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			$this->redirect( 'error', __( 'La URL de imagen no es válida.', 'taxocommerce-images' ), 'import' );
		}

		if ( $parent_id && ! get_post( $parent_id ) ) {
			$this->redirect( 'error', __( 'El objeto al que quieres asociar la imagen no existe.', 'taxocommerce-images' ), 'import' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $url, $parent_id, $title ?: null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			$this->redirect( 'error', $attachment_id->get_error_message(), 'import' );
		}

		$attachment_id = absint( $attachment_id );

		if ( $parent_id && 'featured' === $mode ) {
			set_post_thumbnail( $parent_id, $attachment_id );
		} elseif ( $parent_id && 'gallery' === $mode && 'product' === get_post_type( $parent_id ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $parent_id );
			if ( $product ) {
				$gallery = array_map( 'absint', $product->get_gallery_image_ids() );
				if ( ! in_array( $attachment_id, $gallery, true ) ) {
					$gallery[] = $attachment_id;
					$product->set_gallery_image_ids( $gallery );
					$product->save();
				}
			}
		}

		$this->redirect(
			'ok',
			sprintf(
				/* translators: %d: attachment ID. */
				__( 'Imagen descargada a Media correctamente. Attachment #%d.', 'taxocommerce-images' ),
				$attachment_id
			),
			'import',
			$attachment_id
		);
	}

	/**
	 * Reduce an oversized original with a reversible backup.
	 *
	 * @return void
	 */
	public function optimize() {
		$this->guard_uploads();
		check_admin_referer( 'taxocommerce_images_optimize', 'taxocommerce_images_nonce' );

		$id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		if ( ! $id || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			$this->redirect( 'error', __( 'La imagen indicada no se puede optimizar.', 'taxocommerce-images' ), 'library' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! wp_is_file_mod_allowed( 'taxocommerce-images' ) ) {
			$this->redirect( 'error', __( 'WordPress no permite modificar archivos en este entorno.', 'taxocommerce-images' ), 'library', $id );
		}

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			$this->redirect( 'error', __( 'El archivo físico de la imagen no existe.', 'taxocommerce-images' ), 'library', $id );
		}

		$settings = self::settings();
		$editor   = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			$this->redirect( 'error', $editor->get_error_message(), 'library', $id );
		}

		$size = $editor->get_size();
		if ( empty( $size['width'] ) || empty( $size['height'] ) ) {
			$this->redirect( 'error', __( 'No se pudieron leer las dimensiones de la imagen.', 'taxocommerce-images' ), 'library', $id );
		}

		$max_width  = max( 320, absint( $settings['max_width'] ) );
		$max_height = max( 320, absint( $settings['max_height'] ) );
		if ( $size['width'] <= $max_width && $size['height'] <= $max_height ) {
			$this->redirect( 'ok', __( 'La imagen ya está dentro de los límites configurados.', 'taxocommerce-images' ), 'library', $id );
		}

		$backup = get_post_meta( $id, self::BACKUP_META, true );
		if ( ! $backup || ! file_exists( $backup ) ) {
			$pathinfo = pathinfo( $file );
			$backup   = trailingslashit( $pathinfo['dirname'] ) . $pathinfo['filename'] . '.taxocommerce-backup.' . $pathinfo['extension'];
			if ( ! copy( $file, $backup ) ) {
				$this->redirect( 'error', __( 'No se pudo crear la copia de seguridad del original.', 'taxocommerce-images' ), 'library', $id );
			}
			update_post_meta( $id, self::BACKUP_META, $backup );
		}

		$editor->set_quality( max( 40, min( 95, absint( $settings['quality'] ) ) ) );
		$resize = $editor->resize( $max_width, $max_height, false );
		if ( is_wp_error( $resize ) ) {
			$this->redirect( 'error', $resize->get_error_message(), 'library', $id );
		}

		$saved = $editor->save( $file );
		if ( is_wp_error( $saved ) ) {
			$this->redirect( 'error', $saved->get_error_message(), 'library', $id );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $id, $file );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $id, $metadata );
		}

		$this->redirect( 'ok', __( 'Imagen reducida. Se ha conservado una copia de seguridad reversible.', 'taxocommerce-images' ), 'library', $id );
	}

	/**
	 * Restore the backup created before optimization.
	 *
	 * @return void
	 */
	public function restore_backup() {
		$this->guard_uploads();
		check_admin_referer( 'taxocommerce_images_restore_backup', 'taxocommerce_images_nonce' );

		$id     = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$file   = $id ? get_attached_file( $id ) : '';
		$backup = $id ? get_post_meta( $id, self::BACKUP_META, true ) : '';

		if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! $file || ! $backup || ! file_exists( $backup ) ) {
			$this->redirect( 'error', __( 'No existe una copia de seguridad válida para esta imagen.', 'taxocommerce-images' ), 'library', $id );
		}

		if ( ! copy( $backup, $file ) ) {
			$this->redirect( 'error', __( 'No se pudo restaurar el archivo original.', 'taxocommerce-images' ), 'library', $id );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $id, $file );
		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $id, $metadata );
		}

		wp_delete_file( $backup );
		delete_post_meta( $id, self::BACKUP_META );

		$this->redirect( 'ok', __( 'Imagen original restaurada.', 'taxocommerce-images' ), 'library', $id );
	}

	/**
	 * Save optimization thresholds.
	 *
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para cambiar estos ajustes.', 'taxocommerce-images' ) );
		}

		check_admin_referer( 'taxocommerce_images_save_settings', 'taxocommerce_images_nonce' );

		$settings = array(
			'max_width'          => max( 320, min( 8000, isset( $_POST['max_width'] ) ? absint( $_POST['max_width'] ) : 2000 ) ),
			'max_height'         => max( 320, min( 8000, isset( $_POST['max_height'] ) ? absint( $_POST['max_height'] ) : 2000 ) ),
			'max_bytes'          => max( 100000, min( 30000000, isset( $_POST['max_bytes'] ) ? absint( $_POST['max_bytes'] ) : 1572864 ) ),
			'quality'            => max( 40, min( 95, isset( $_POST['quality'] ) ? absint( $_POST['quality'] ) : 82 ) ),
			'enforce_upload_max' => isset( $_POST['enforce_upload_max'] ) ? 1 : 0,
			'enforce_quality'    => isset( $_POST['enforce_quality'] ) ? 1 : 0,
		);

		update_option( self::SETTINGS_OPTION, $settings, false );
		$this->redirect( 'ok', __( 'Ajustes guardados.', 'taxocommerce-images' ), 'settings' );
	}

	/**
	 * Apply configured image-editor quality when enabled.
	 *
	 * @param int    $quality   Current quality.
	 * @param string $mime_type MIME type.
	 * @return int
	 */
	public function filter_quality( $quality, $mime_type ) {
		$settings = self::settings();
		if ( empty( $settings['enforce_quality'] ) || ! in_array( $mime_type, array( 'image/jpeg', 'image/webp', 'image/avif' ), true ) ) {
			return $quality;
		}
		return max( 40, min( 95, absint( $settings['quality'] ) ) );
	}

	/**
	 * Apply WordPress big-image scaling threshold when enabled.
	 *
	 * @param int|false $threshold Threshold.
	 * @param array     $imagesize Image size.
	 * @param string    $file      File.
	 * @param int       $attachment_id Attachment ID.
	 * @return int|false
	 */
	public function filter_big_image_threshold( $threshold, $imagesize, $file, $attachment_id ) {
		unset( $imagesize, $file, $attachment_id );
		$settings = self::settings();
		if ( empty( $settings['enforce_upload_max'] ) ) {
			return $threshold;
		}
		return max( 320, max( absint( $settings['max_width'] ), absint( $settings['max_height'] ) ) );
	}

	/**
	 * Capability guard for media changes.
	 *
	 * @return void
	 */
	private function guard_uploads() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'No tienes permisos para gestionar imágenes.', 'taxocommerce-images' ) );
		}
	}

	/**
	 * Redirect helper.
	 *
	 * @param string $result Result.
	 * @param string $message Message.
	 * @param string $tab Tab.
	 * @param int    $image_id Image ID.
	 * @return void
	 */
	private function redirect( $result, $message, $tab, $image_id = 0 ) {
		$args = array(
			'page'       => 'taxocommerce-images',
			'tab'        => sanitize_key( $tab ),
			'tc_result'  => sanitize_key( $result ),
			'tc_message' => sanitize_text_field( $message ),
		);
		if ( $image_id ) {
			$args['image_id'] = absint( $image_id );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
