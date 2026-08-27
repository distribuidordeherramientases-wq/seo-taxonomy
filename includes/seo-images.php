<?php
/**
 * Gestión centralizada de imágenes externas.
 *
 * Responsabilidades:
 * - Evitar descargas repetidas por URL.
 * - Evitar adjuntos duplicados por contenido binario.
 * - Reutilizar attachment_id existentes.
 * - Registrar qué objetos utilizan cada imagen.
 *
 * Tablas esperadas:
 * - {$wpdb->prefix}seo_media_imagenes
 * - {$wpdb->prefix}seo_media_usos
 *
 * Version: 2026-08-26
 * Build: 006-posts
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Devuelve el nombre de la tabla de imágenes.
 *
 * @return string
 */
function seo_images_table_images() {
    global $wpdb;

    return $wpdb->prefix . 'seo_media_imagenes';
}

/**
 * Devuelve el nombre de la tabla de usos.
 *
 * @return string
 */
function seo_images_table_usages() {
    global $wpdb;

    return $wpdb->prefix . 'seo_media_usos';
}

/**
 * Configuracion de etiquetas generadas para las imagenes de las plantillas.
 *
 * Se guarda como una unica opcion para poder incorporar mas tipos de contenido
 * (categorias, hubs, landings, etc.) sin multiplicar opciones independientes.
 * La integracion con las plantillas se realiza aparte: estas funciones solo
 * definen, guardan y resuelven la configuracion.
 *
 * @return array
 */
function seo_images_label_settings_defaults() {
    return array(
        'product' => array(
            'override_alt' => 0,
            'alt_template' => '{titulo}',
        ),
    );
}

/**
 * Devuelve la configuracion de etiquetas normalizada.
 *
 * @return array
 */
function seo_images_get_label_settings() {
    $defaults = seo_images_label_settings_defaults();
    $stored   = get_option( 'seo_images_label_settings', array() );

    if ( ! is_array( $stored ) ) {
        $stored = array();
    }

    $settings = array_replace_recursive( $defaults, $stored );
    $product  = isset( $settings['product'] ) && is_array( $settings['product'] )
        ? $settings['product']
        : $defaults['product'];

    $settings['product'] = array(
        'override_alt' => empty( $product['override_alt'] ) ? 0 : 1,
        'alt_template' => isset( $product['alt_template'] )
            ? sanitize_text_field( (string) $product['alt_template'] )
            : $defaults['product']['alt_template'],
    );

    return $settings;
}

/**
 * Guarda la regla ALT de las imagenes de producto sin sobrescribir futuras
 * configuraciones de otros tipos de contenido.
 *
 * @param bool   $override_alt Sustituir o conservar el ALT propio de la imagen.
 * @param string $alt_template Patron configurado por el usuario.
 * @return void
 */
function seo_images_update_product_label_settings( $override_alt, $alt_template ) {
    $settings = get_option( 'seo_images_label_settings', array() );

    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    $settings['product'] = array(
        'override_alt' => $override_alt ? 1 : 0,
        'alt_template' => sanitize_text_field( (string) $alt_template ),
    );

    update_option( 'seo_images_label_settings', $settings );
}

/**
 * Comodines admitidos actualmente por la regla de producto.
 *
 * {titulo} es el nombre recomendado en la interfaz; {title} se mantiene como
 * alias para facilitar integraciones tecnicas. El texto fijo se escribe
 * directamente en el patron.
 *
 * @return array
 */
function seo_images_label_allowed_tokens() {
    return array( '{titulo}', '{title}', '{slug}' );
}

/**
 * Devuelve comodines desconocidos presentes en un patron.
 *
 * @param string $template Patron a validar.
 * @return array
 */
function seo_images_label_unknown_tokens( $template ) {
    $template = (string) $template;
    $matches  = array();

    if ( ! preg_match_all( '/\{[^{}]+\}/u', $template, $matches ) ) {
        return array();
    }

    return array_values( array_diff( array_unique( $matches[0] ), seo_images_label_allowed_tokens() ) );
}

/**
 * Expande un patron de etiqueta con datos suministrados por la plantilla.
 *
 * Esta funcion queda preparada para la siguiente fase, cuando las plantillas
 * de producto llamen al sistema de etiquetas. No modifica por si sola el HTML.
 *
 * @param string $template Patron guardado.
 * @param array  $values   Valores disponibles: title/titulo y slug.
 * @return string
 */
function seo_images_expand_label_template( $template, array $values = array() ) {
    $title = '';

    if ( isset( $values['titulo'] ) ) {
        $title = (string) $values['titulo'];
    } elseif ( isset( $values['title'] ) ) {
        $title = (string) $values['title'];
    }

    $replacements = array(
        '{titulo}' => $title,
        '{title}'  => $title,
        '{slug}'   => isset( $values['slug'] ) ? (string) $values['slug'] : '',
    );

    $label = strtr( (string) $template, $replacements );
    $label = wp_strip_all_tags( $label );
    $label = preg_replace( '/\s+/u', ' ', $label );

    return trim( (string) $label );
}

/**
 * Normaliza una URL externa antes de calcular su hash.
 *
 * Mantiene la query porque algunos proveedores generan imágenes distintas
 * mediante parámetros. Solo elimina el fragmento (#...).
 *
 * @param string $url URL original.
 * @return string
 */
function seo_images_normalize_url( $url ) {
    $url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );

    if ( '' === $url ) {
        return '';
    }

    $parts = wp_parse_url( $url );

    if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
        return esc_url_raw( $url );
    }

    $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
    $host   = strtolower( $parts['host'] );
    $port   = isset( $parts['port'] ) ? ':' . absint( $parts['port'] ) : '';
    $path   = isset( $parts['path'] ) ? preg_replace( '#/+#', '/', $parts['path'] ) : '/';
    $query  = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

    return esc_url_raw( $scheme . '://' . $host . $port . $path . $query );
}

/**
 * Calcula el hash estable de una URL normalizada.
 *
 * @param string $url URL normalizada o sin normalizar.
 * @return string
 */
function seo_images_url_hash( $url ) {
    return hash( 'sha256', seo_images_normalize_url( $url ) );
}

/**
 * Comprueba que un attachment_id sigue siendo un adjunto válido.
 *
 * @param int $attachment_id ID del adjunto.
 * @return bool
 */
function seo_images_is_valid_attachment( $attachment_id ) {
    $attachment_id = absint( $attachment_id );

    return $attachment_id > 0
        && 'attachment' === get_post_type( $attachment_id )
        && wp_attachment_is_image( $attachment_id );
}

/**
 * Busca una imagen registrada por hash de URL.
 *
 * @param string $url_hash Hash SHA-256.
 * @return object|null
 */
function seo_images_find_by_url_hash( $url_hash ) {
    global $wpdb;

    $table = seo_images_table_images();

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE url_hash = %s LIMIT 1",
            $url_hash
        )
    );
}

/**
 * Busca una imagen registrada por hash de contenido.
 *
 * @param string $content_hash Hash SHA-256 del archivo.
 * @return object|null
 */
function seo_images_find_by_content_hash( $content_hash ) {
    global $wpdb;

    if ( '' === (string) $content_hash ) {
        return null;
    }

    $table = seo_images_table_images();

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE content_hash = %s AND attachment_id IS NOT NULL ORDER BY id ASC LIMIT 1",
            $content_hash
        )
    );
}

/**
 * Registra o actualiza el uso de una imagen por un objeto de WordPress.
 *
 * @param int    $attachment_id ID del adjunto.
 * @param int    $object_id     ID del producto, entrada, etc.
 * @param string $usage         featured, gallery o content.
 * @param string $object_type   Tipo lógico del objeto.
 * @return bool|WP_Error
 */
function seo_images_register_usage( $attachment_id, $object_id, $usage = 'featured', $object_type = 'product' ) {
    global $wpdb;

    $attachment_id = absint( $attachment_id );
    $object_id     = absint( $object_id );
    $usage         = sanitize_key( $usage );
    $object_type   = sanitize_key( $object_type );

    $allowed_usages = array( 'featured', 'gallery', 'content' );

    if ( ! in_array( $usage, $allowed_usages, true ) ) {
        return new WP_Error( 'seo_images_invalid_usage', 'El tipo de uso de la imagen no es válido.' );
    }

    if ( ! seo_images_is_valid_attachment( $attachment_id ) || $object_id < 1 ) {
        return new WP_Error( 'seo_images_invalid_relation', 'No se puede registrar la relación de la imagen.' );
    }

    $table = seo_images_table_usages();

    $result = $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$table} (attachment_id, object_id, object_type, tipo_uso, fecha)
             VALUES (%d, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE object_type = VALUES(object_type), fecha = VALUES(fecha)",
            $attachment_id,
            $object_id,
            $object_type,
            $usage,
            current_time( 'mysql' )
        )
    );

    if ( false === $result ) {
        return new WP_Error( 'seo_images_usage_db_error', $wpdb->last_error ?: 'No se pudo registrar el uso de la imagen.' );
    }

    return true;
}


/**
 * Guarda o actualiza la fila principal de una imagen.
 *
 * @param array $data Datos de la imagen.
 * @return bool|WP_Error
 */
function seo_images_store_image_record( array $data ) {
    global $wpdb;

    $table = seo_images_table_images();

    $defaults = array(
        'attachment_id'  => null,
        'proveedor'      => '',
        'url_origen'     => '',
        'url_hash'       => '',
        'content_hash'   => null,
        'nombre_archivo' => null,
        'estado'         => 'disponible',
        'fecha_creacion' => current_time( 'mysql' ),
        'ultima_revision'=> current_time( 'mysql' ),
    );

    $data = wp_parse_args( $data, $defaults );

    $result = $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$table}
                (attachment_id, proveedor, url_origen, url_hash, content_hash, nombre_archivo, estado, fecha_creacion, ultima_revision)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                attachment_id = VALUES(attachment_id),
                proveedor = VALUES(proveedor),
                url_origen = VALUES(url_origen),
                content_hash = VALUES(content_hash),
                nombre_archivo = VALUES(nombre_archivo),
                estado = VALUES(estado),
                ultima_revision = VALUES(ultima_revision)",
            absint( $data['attachment_id'] ),
            sanitize_text_field( $data['proveedor'] ),
            esc_url_raw( $data['url_origen'] ),
            sanitize_text_field( $data['url_hash'] ),
            $data['content_hash'] ? sanitize_text_field( $data['content_hash'] ) : '',
            $data['nombre_archivo'] ? sanitize_file_name( $data['nombre_archivo'] ) : '',
            sanitize_key( $data['estado'] ),
            $data['fecha_creacion'],
            $data['ultima_revision']
        )
    );

    if ( false === $result ) {
        return new WP_Error( 'seo_images_record_db_error', $wpdb->last_error ?: 'No se pudo guardar el índice de imágenes.' );
    }

    return true;
}

/**
 * Descarga una imagen externa. Primero usa el flujo nativo de WordPress y,
 * si el servidor remoto lo rechaza, repite con cabeceras de navegador.
 * Algunos CDN/proveedores permiten mostrar la imagen en el navegador pero
 * bloquean el User-Agent HTTP por defecto de WordPress.
 *
 * @param string $url URL externa validada.
 * @param int    $timeout Timeout en segundos.
 * @return string|WP_Error Ruta temporal o error.
 */
function seo_images_download_external_file( $url, $timeout = 120, $source_url = '' ) {
    $first_try = download_url( $url, $timeout );
    if ( ! is_wp_error( $first_try ) ) {
        return $first_try;
    }

    $url_parts = wp_parse_url( $url );
    $image_origin = '';
    if ( is_array( $url_parts ) && ! empty( $url_parts['host'] ) ) {
        $scheme       = ! empty( $url_parts['scheme'] ) ? $url_parts['scheme'] : 'https';
        $image_origin = $scheme . '://' . $url_parts['host'] . '/';
    }

    $source_url = seo_images_normalize_url( $source_url );
    if ( '' !== $source_url && ! wp_http_validate_url( $source_url ) ) {
        $source_url = '';
    }

    // Muchos CDN aplican anti-hotlink y esperan como Referer la pagina del
    // producto que originó la imagen, no el dominio del propio CDN.
    $referers = array();
    if ( $source_url ) {
        $referers[] = $source_url;

        $source_parts = wp_parse_url( $source_url );
        if ( is_array( $source_parts ) && ! empty( $source_parts['host'] ) ) {
            $source_scheme = ! empty( $source_parts['scheme'] ) ? $source_parts['scheme'] : 'https';
            $referers[] = $source_scheme . '://' . $source_parts['host'] . '/';
        }
    }
    if ( $image_origin ) {
        $referers[] = $image_origin;
    }
    // Ultimo intento sin Referer: algunos servidores bloquean hotlinks pero
    // permiten descargas directas anonimas.
    $referers[] = '';
    $referers = array_values( array_unique( $referers ) );

    $last_code  = 0;
    $last_error = '';

    foreach ( $referers as $referer ) {
        $tmp_file = wp_tempnam( $url );
        if ( ! $tmp_file ) {
            continue;
        }

        $headers = array(
            'Accept'          => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'Accept-Language' => 'es-ES,es;q=0.9,en;q=0.8',
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            'Cache-Control'   => 'no-cache',
            'Pragma'          => 'no-cache',
        );
        if ( $referer ) {
            $headers['Referer'] = $referer;
        }

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'     => $timeout,
                'redirection' => 5,
                'stream'      => true,
                'filename'    => $tmp_file,
                'headers'     => $headers,
            )
        );

        if ( is_wp_error( $response ) ) {
            $last_error = '[' . sanitize_key( $response->get_error_code() ) . '] ' . sanitize_text_field( $response->get_error_message() );
            @unlink( $tmp_file );
            continue;
        }

        $code      = (int) wp_remote_retrieve_response_code( $response );
        $last_code = $code;

        if ( $code >= 200 && $code < 300 && file_exists( $tmp_file ) && filesize( $tmp_file ) > 0 ) {
            return $tmp_file;
        }

        if ( $code >= 200 && $code < 300 ) {
            $last_error = 'El proveedor respondió con un archivo vacío.';
        } else {
            $last_error = 'HTTP ' . $code;
        }

        @unlink( $tmp_file );
    }

    if ( 403 === $last_code ) {
        return new WP_Error(
            'seo_images_download_http_403',
            'El proveedor sigue rechazando la descarga (HTTP 403) incluso usando la pagina de origen del producto como Referer. Probablemente aplica proteccion anti-bot/hotlink por IP, cookie o token.'
        );
    }

    if ( $last_code > 0 ) {
        return new WP_Error(
            'seo_images_download_http_' . $last_code,
            sprintf( 'El proveedor rechazó la descarga de la imagen (HTTP %d).', $last_code )
        );
    }

    return new WP_Error(
        'seo_images_download_failed',
        sprintf(
            'No se pudo descargar la imagen. WordPress: [%s] %s. Reintentos: %s',
            sanitize_key( $first_try->get_error_code() ),
            sanitize_text_field( $first_try->get_error_message() ),
            $last_error ?: 'sin respuesta valida'
        )
    );
}

/**
 * Importa o reutiliza una imagen externa y registra su uso.
 *
 * Devuelve un attachment_id o WP_Error.
 *
 * @param string $provider    Nombre del proveedor o fuente.
 * @param string $source_url  URL externa de la imagen.
 * @param int    $object_id   Producto, entrada u otro objeto que usará la imagen.
 * @param string $usage       featured, gallery o content.
 * @param string $object_type product, post, term, etc.
 * @return int|WP_Error
 */
function seo_images_get_or_import( $provider, $source_url, $object_id = 0, $usage = 'featured', $object_type = 'product', $referer_url = '' ) {
    global $wpdb;

    $provider       = sanitize_text_field( $provider );
    $normalized_url = seo_images_normalize_url( $source_url );
    $object_id      = absint( $object_id );

    if ( '' === $normalized_url || ! wp_http_validate_url( $normalized_url ) ) {
        return new WP_Error( 'seo_images_invalid_url', 'La URL de la imagen no es válida.' );
    }

    $url_hash = seo_images_url_hash( $normalized_url );
    $existing = seo_images_find_by_url_hash( $url_hash );

    if ( $existing && seo_images_is_valid_attachment( $existing->attachment_id ) ) {
        $attachment_id = absint( $existing->attachment_id );

        $wpdb->update(
            seo_images_table_images(),
            array(
                'estado'          => 'disponible',
                'ultima_revision' => current_time( 'mysql' ),
            ),
            array( 'id' => absint( $existing->id ) ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( $object_id ) {
            $usage_result = seo_images_register_usage( $attachment_id, $object_id, $usage, $object_type );
            if ( is_wp_error( $usage_result ) ) {
                return $usage_result;
            }
        }

        return $attachment_id;
    }

    // Dependencias necesarias para descargar y crear adjuntos desde el frontend o procesos internos.
    if ( ! function_exists( 'download_url' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if ( ! function_exists( 'media_handle_sideload' ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    // Marca la URL como en descarga. El índice único evita que dos procesos creen dos filas.
    seo_images_store_image_record(
        array(
            'attachment_id' => 0,
            'proveedor'     => $provider,
            'url_origen'    => $normalized_url,
            'url_hash'      => $url_hash,
            'estado'        => 'descargando',
        )
    );

    $tmp_file = seo_images_download_external_file( $normalized_url, 120, $referer_url );

    if ( is_wp_error( $tmp_file ) ) {
        seo_images_store_image_record(
            array(
                'attachment_id' => 0,
                'proveedor'     => $provider,
                'url_origen'    => $normalized_url,
                'url_hash'      => $url_hash,
                'estado'        => 'error',
            )
        );

        return $tmp_file;
    }

    $content_hash = hash_file( 'sha256', $tmp_file );
    $same_content = seo_images_find_by_content_hash( $content_hash );

    if ( $same_content && seo_images_is_valid_attachment( $same_content->attachment_id ) ) {
        $attachment_id = absint( $same_content->attachment_id );
        @unlink( $tmp_file );

        $stored = seo_images_store_image_record(
            array(
                'attachment_id' => $attachment_id,
                'proveedor'     => $provider,
                'url_origen'    => $normalized_url,
                'url_hash'      => $url_hash,
                'content_hash'  => $content_hash,
                'nombre_archivo'=> get_attached_file( $attachment_id ) ? wp_basename( get_attached_file( $attachment_id ) ) : '',
                'estado'        => 'disponible',
            )
        );

        if ( is_wp_error( $stored ) ) {
            error_log( '[SEO Images] No se pudo actualizar el índice al reutilizar contenido: ' . $stored->get_error_message() );
        }

        if ( $object_id ) {
            $usage_result = seo_images_register_usage( $attachment_id, $object_id, $usage, $object_type );
            if ( is_wp_error( $usage_result ) ) {
                return $usage_result;
            }
        }

        return $attachment_id;
    }

    // Asegura que el proveedor haya devuelto realmente una imagen y que el
    // nombre tenga una extensión que WordPress pueda reconocer al crear Media.
    $image_mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp_file ) : '';
    if ( ! is_string( $image_mime ) || 0 !== strpos( $image_mime, 'image/' ) ) {
        @unlink( $tmp_file );

        seo_images_store_image_record(
            array(
                'attachment_id' => 0,
                'proveedor'     => $provider,
                'url_origen'    => $normalized_url,
                'url_hash'      => $url_hash,
                'content_hash'  => $content_hash,
                'estado'        => 'error',
            )
        );

        return new WP_Error(
            'seo_images_download_not_image',
            'El proveedor respondió, pero el archivo descargado no es una imagen válida.'
        );
    }

    $url_path = wp_parse_url( $normalized_url, PHP_URL_PATH );
    $filename = sanitize_file_name( wp_basename( $url_path ) );

    $mime_extensions = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/bmp'  => 'bmp',
        'image/tiff' => 'tif',
    );

    $filetype = wp_check_filetype( $filename );
    $declared_mime = !empty( $filetype['type'] ) ? (string) $filetype['type'] : '';
    if (
        '' === $filename
        || empty( $filetype['ext'] )
        || '' === $declared_mime
        || $declared_mime !== $image_mime
    ) {
        $extension = $mime_extensions[ $image_mime ] ?? '';
        if ( '' === $extension ) {
            @unlink( $tmp_file );
            return new WP_Error(
                'seo_images_unsupported_image_type',
                'La imagen usa un formato no permitido por WordPress: ' . sanitize_text_field( $image_mime )
            );
        }

        $base = sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) );
        if ( '' === $base ) {
            $base = 'imagen-' . substr( $url_hash, 0, 12 );
        }
        $filename = $base . '.' . $extension;
    }

    $file_array = array(
        'name'     => $filename,
        'tmp_name' => $tmp_file,
    );

    $attachment_id = media_handle_sideload(
        $file_array,
        $object_id,
        sanitize_text_field( pathinfo( $filename, PATHINFO_FILENAME ) )
    );

    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $tmp_file );

        seo_images_store_image_record(
            array(
                'attachment_id' => 0,
                'proveedor'     => $provider,
                'url_origen'    => $normalized_url,
                'url_hash'      => $url_hash,
                'content_hash'  => $content_hash,
                'nombre_archivo'=> $filename,
                'estado'        => 'error',
            )
        );

        return $attachment_id;
    }

    $attachment_id = absint( $attachment_id );

    update_post_meta( $attachment_id, '_seo_proveedor', $provider );
    update_post_meta( $attachment_id, '_seo_url_origen', $normalized_url );
    update_post_meta( $attachment_id, '_seo_url_hash', $url_hash );
    update_post_meta( $attachment_id, '_seo_content_hash', $content_hash );

    $stored = seo_images_store_image_record(
        array(
            'attachment_id' => $attachment_id,
            'proveedor'     => $provider,
            'url_origen'    => $normalized_url,
            'url_hash'      => $url_hash,
            'content_hash'  => $content_hash,
            'nombre_archivo'=> get_attached_file( $attachment_id ) ? wp_basename( get_attached_file( $attachment_id ) ) : $filename,
            'estado'        => 'disponible',
        )
    );

    if ( is_wp_error( $stored ) ) {
        error_log( '[SEO Images] El attachment se creó, pero no se pudo guardar en el índice: ' . $stored->get_error_message() );
    }

    if ( $object_id ) {
        $usage_result = seo_images_register_usage( $attachment_id, $object_id, $usage, $object_type );
        if ( is_wp_error( $usage_result ) ) {
            return $usage_result;
        }
    }

    return $attachment_id;
}



/**
 * Convierte el campo de imágenes del proveedor en una lista de URLs únicas.
 *
 * Admite URLs separadas por saltos de línea, barra vertical, coma o punto y coma.
 *
 * @param string|array $raw_images Campo original de imágenes.
 * @return array
 */
function seo_images_parse_urls( $raw_images ) {
    if ( is_array( $raw_images ) ) {
        $parts = $raw_images;
    } else {
        $parts = preg_split( '/(?:[\r\n|;]+|,(?=\s*https?:\/\/))/', (string) $raw_images );
    }

    $urls = array();

    foreach ( (array) $parts as $part ) {
        $url = seo_images_normalize_url( $part );

        if ( '' === $url || ! wp_http_validate_url( $url ) ) {
            continue;
        }

        $urls[ $url ] = $url;
    }

    return array_values( $urls );
}

/**
 * Sincroniza todas las imágenes externas de un producto.
 *
 * La primera URL se asigna como imagen destacada y las demás forman la
 * galería de WooCommerce. También registra cada uso en seo_media_usos.
 *
 * @param string       $provider   Nombre del proveedor.
 * @param string|array $raw_images Campo de imágenes o lista de URLs.
 * @param int          $product_id ID del producto WooCommerce.
 * @return array|WP_Error
 */
function seo_images_sync_product_images( $provider, $raw_images, $product_id ) {
    $product_id = absint( $product_id );

    if ( $product_id < 1 || 'product' !== get_post_type( $product_id ) ) {
        return new WP_Error(
            'seo_images_invalid_product',
            'No se puede sincronizar imágenes porque el producto no es válido.'
        );
    }

    $urls = seo_images_parse_urls( $raw_images );

    if ( empty( $urls ) ) {
        return array(
            'featured_id' => 0,
            'gallery_ids' => array(),
            'errors'      => array(),
        );
    }

    $featured_id = 0;
    $gallery_ids = array();
    $errors      = array();

    foreach ( $urls as $position => $url ) {
        $usage = 0 === $position ? 'featured' : 'gallery';

        $attachment_id = seo_images_get_or_import(
            $provider,
            $url,
            $product_id,
            $usage,
            'product'
        );

        if ( is_wp_error( $attachment_id ) ) {
            $errors[] = $url . ': ' . $attachment_id->get_error_message();
            continue;
        }

        $attachment_id = absint( $attachment_id );

        if ( 0 === $position ) {
            if ( set_post_thumbnail( $product_id, $attachment_id ) ) {
                $featured_id = $attachment_id;
            } else {
                $errors[] = $url . ': la imagen se importó, pero no pudo asignarse como destacada.';
            }
        } else {
            $gallery_ids[] = $attachment_id;
        }
    }

    $gallery_ids = array_values( array_unique( array_filter( array_map( 'absint', $gallery_ids ) ) ) );

    if ( $featured_id || ! empty( $gallery_ids ) ) {
        update_post_meta(
            $product_id,
            '_product_image_gallery',
            implode( ',', $gallery_ids )
        );
    }

    if ( empty( $errors ) ) {
        delete_post_meta( $product_id, '_seo_imagen_import_error' );
    } else {
        update_post_meta(
            $product_id,
            '_seo_imagen_import_error',
            implode( "\n", $errors )
        );
    }

    return array(
        'featured_id' => $featured_id,
        'gallery_ids' => $gallery_ids,
        'errors'      => $errors,
    );
}

/**
 * Busca los usos registrados de imágenes para un objeto.
 *
 * Esta función permite hacer la consulta inversa de seo_media_usos: dado un
 * producto o término devuelve los attachment_id que ya están relacionados con
 * él, aunque WordPress no los tenga asignados como _thumbnail_id.
 *
 * @param int          $object_id    ID del objeto.
 * @param string|array $object_types Tipo o tipos lógicos: product, term, product_cat, etc.
 * @param int          $limit        Máximo de usos devueltos.
 * @return array
 */
function seo_images_get_registered_usages( $object_id, $object_types = 'product', $limit = 50 ) {
    global $wpdb;

    $object_id = absint( $object_id );
    $limit     = max( 1, min( 200, absint( $limit ) ) );

    if ( $object_id < 1 ) {
        return array();
    }

    $object_types = array_values(
        array_filter(
            array_unique(
                array_map( 'sanitize_key', (array) $object_types )
            )
        )
    );

    if ( empty( $object_types ) ) {
        return array();
    }

    $table        = seo_images_table_usages();
    $placeholders = implode( ',', array_fill( 0, count( $object_types ), '%s' ) );
    $params       = array_merge( array( $object_id ), $object_types, array( $limit ) );

    $sql = "SELECT attachment_id, object_id, object_type, tipo_uso, fecha
            FROM {$table}
            WHERE object_id = %d
              AND object_type IN ({$placeholders})
              AND attachment_id > 0
            ORDER BY
                CASE tipo_uso
                    WHEN 'featured' THEN 1
                    WHEN 'gallery' THEN 2
                    WHEN 'content' THEN 3
                    ELSE 4
                END,
                fecha DESC
            LIMIT %d";

    $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

    if ( empty( $rows ) ) {
        return array();
    }

    return array_values(
        array_filter(
            $rows,
            static function ( $row ) {
                return ! empty( $row->attachment_id ) && seo_images_is_valid_attachment( $row->attachment_id );
            }
        )
    );
}

/**
 * Devuelve los adjuntos que WordPress/WooCommerce tiene asignados directamente
 * a un producto (destacada + galería).
 *
 * @param int $product_id ID del producto.
 * @return array
 */
function seo_images_get_product_direct_attachments( $product_id ) {
    $product_id = absint( $product_id );

    if ( $product_id < 1 || 'product' !== get_post_type( $product_id ) ) {
        return array();
    }

    $ids      = array();
    $featured = absint( get_post_thumbnail_id( $product_id ) );

    if ( $featured && seo_images_is_valid_attachment( $featured ) ) {
        $ids[] = array(
            'attachment_id' => $featured,
            'usage'         => 'featured',
        );
    }

    $gallery_raw = (string) get_post_meta( $product_id, '_product_image_gallery', true );

    if ( '' !== $gallery_raw ) {
        foreach ( explode( ',', $gallery_raw ) as $gallery_id ) {
            $gallery_id = absint( $gallery_id );

            if ( $gallery_id && seo_images_is_valid_attachment( $gallery_id ) ) {
                $ids[] = array(
                    'attachment_id' => $gallery_id,
                    'usage'         => 'gallery',
                );
            }
        }
    }

    return $ids;
}

/**
 * Obtiene información del índice seo_media_imagenes para un attachment.
 *
 * Un mismo attachment puede haberse reutilizado desde varias URLs; se devuelve
 * el registro disponible revisado más recientemente.
 *
 * @param int $attachment_id ID del adjunto.
 * @return object|null
 */
function seo_images_find_record_by_attachment( $attachment_id ) {
    global $wpdb;

    $attachment_id = absint( $attachment_id );

    if ( $attachment_id < 1 ) {
        return null;
    }

    $table = seo_images_table_images();

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE attachment_id = %d
             ORDER BY (estado = 'disponible') DESC, ultima_revision DESC, id ASC
             LIMIT 1",
            $attachment_id
        )
    );
}

/**
 * Añade un candidato de imagen evitando duplicados y conservando la fuente de
 * mayor prioridad.
 *
 * @param array $candidates Lista actual indexada por attachment_id.
 * @param int   $attachment_id ID de adjunto.
 * @param array $data Datos de procedencia y prioridad.
 * @return array
 */
function seo_images_add_candidate( array $candidates, $attachment_id, array $data ) {
    $attachment_id = absint( $attachment_id );

    if ( ! seo_images_is_valid_attachment( $attachment_id ) ) {
        return $candidates;
    }

    $priority = isset( $data['priority'] ) ? (int) $data['priority'] : 0;

    if ( isset( $candidates[ $attachment_id ] ) && (int) $candidates[ $attachment_id ]['priority'] >= $priority ) {
        return $candidates;
    }

    $record = seo_images_find_record_by_attachment( $attachment_id );

    $candidates[ $attachment_id ] = array_merge(
        array(
            'attachment_id'    => $attachment_id,
            'priority'         => $priority,
            'source'           => '',
            'source_object_id' => 0,
            'category_id'      => 0,
            'usage'            => '',
            'provider'         => $record && isset( $record->proveedor ) ? (string) $record->proveedor : '',
            'source_url'       => $record && isset( $record->url_origen ) ? (string) $record->url_origen : '',
            'thumbnail_url'    => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ?: '',
            'full_url'         => wp_get_attachment_url( $attachment_id ) ?: '',
            'alt'              => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
            'title'            => get_the_title( $attachment_id ),
        ),
        $data
    );

    return $candidates;
}

/**
 * Busca imágenes relacionadas con un producto aunque este no tenga una imagen
 * destacada asignada directamente.
 *
 * Orden de búsqueda:
 * 1. Imágenes ya relacionadas con el propio producto en seo_media_usos.
 * 2. Imágenes directas del propio producto (destacada/galería).
 * 3. Imagen o usos registrados de sus categorías product_cat.
 * 4. Imágenes de otros productos de esas mismas categorías, incluyendo tanto
 *    WordPress/WooCommerce como seo_media_usos.
 *
 * El resultado se ordena por prioridad y está pensado para alimentar la ayuda
 * de asignación del informe "Sin imágenes asociadas".
 *
 * @param int $product_id         ID del producto sin imagen.
 * @param int $limit              Máximo de candidatos a devolver.
 * @param int $product_scan_limit Máximo de productos hermanos a inspeccionar.
 * @return array
 */
function seo_images_find_related_candidates( $product_id, $limit = 24, $product_scan_limit = 60 ) {
    $product_id         = absint( $product_id );
    $limit              = max( 1, min( 100, absint( $limit ) ) );
    $product_scan_limit = max( 1, min( 200, absint( $product_scan_limit ) ) );

    if ( $product_id < 1 || 'product' !== get_post_type( $product_id ) ) {
        return array();
    }

    $candidates = array();

    // 1) Lo más importante: el producto puede carecer de _thumbnail_id pero ya
    // tener imágenes registradas en la tabla externa de usos.
    foreach ( seo_images_get_registered_usages( $product_id, 'product', 100 ) as $usage ) {
        $usage_priority = 'featured' === $usage->tipo_uso ? 120 : ( 'gallery' === $usage->tipo_uso ? 116 : 112 );
        $candidates     = seo_images_add_candidate(
            $candidates,
            $usage->attachment_id,
            array(
                'priority'         => $usage_priority,
                'source'           => 'product_media_usage',
                'source_object_id' => $product_id,
                'usage'            => (string) $usage->tipo_uso,
            )
        );
    }

    // 2) Compatibilidad con asignaciones WordPress/WooCommerce que pudieran no
    // estar todavía reflejadas en seo_media_usos.
    foreach ( seo_images_get_product_direct_attachments( $product_id ) as $direct ) {
        $candidates = seo_images_add_candidate(
            $candidates,
            $direct['attachment_id'],
            array(
                'priority'         => 'featured' === $direct['usage'] ? 118 : 114,
                'source'           => 'product_direct',
                'source_object_id' => $product_id,
                'usage'            => $direct['usage'],
            )
        );
    }

    $terms = get_the_terms( $product_id, 'product_cat' );

    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        $terms = array();
    }

    $term_ids = array();

    // 3) Categorías: thumbnail nativo y relaciones guardadas en seo_media_usos.
    foreach ( $terms as $term ) {
        $term_id = absint( $term->term_id );

        if ( ! $term_id ) {
            continue;
        }

        $term_ids[] = $term_id;

        $term_thumbnail = absint( get_term_meta( $term_id, 'thumbnail_id', true ) );

        if ( $term_thumbnail ) {
            $candidates = seo_images_add_candidate(
                $candidates,
                $term_thumbnail,
                array(
                    'priority'         => 102,
                    'source'           => 'category_thumbnail',
                    'source_object_id' => $term_id,
                    'category_id'      => $term_id,
                    'usage'            => 'featured',
                )
            );
        }

        foreach ( seo_images_get_registered_usages( $term_id, array( 'term', 'product_cat', 'category' ), 50 ) as $usage ) {
            $candidates = seo_images_add_candidate(
                $candidates,
                $usage->attachment_id,
                array(
                    'priority'         => 'featured' === $usage->tipo_uso ? 100 : 96,
                    'source'           => 'category_media_usage',
                    'source_object_id' => $term_id,
                    'category_id'      => $term_id,
                    'usage'            => (string) $usage->tipo_uso,
                )
            );
        }
    }

    $term_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );

    // 4) Productos hermanos de las mismas categorías. No se limita a la imagen
    // destacada: también se consulta seo_media_usos, que es donde pueden vivir
    // las asociaciones externas que no aparecen en _thumbnail_id.
    if ( ! empty( $term_ids ) ) {
        $sibling_ids = get_posts(
            array(
                'post_type'              => 'product',
                'post_status'            => 'publish',
                'posts_per_page'         => $product_scan_limit,
                'fields'                 => 'ids',
                'post__not_in'           => array( $product_id ),
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'tax_query'              => array(
                    array(
                        'taxonomy'         => 'product_cat',
                        'field'            => 'term_id',
                        'terms'            => $term_ids,
                        'operator'         => 'IN',
                        'include_children' => false,
                    ),
                ),
            )
        );

        foreach ( (array) $sibling_ids as $sibling_id ) {
            $sibling_id = absint( $sibling_id );

            foreach ( seo_images_get_registered_usages( $sibling_id, 'product', 20 ) as $usage ) {
                $candidates = seo_images_add_candidate(
                    $candidates,
                    $usage->attachment_id,
                    array(
                        'priority'         => 'featured' === $usage->tipo_uso ? 88 : ( 'gallery' === $usage->tipo_uso ? 84 : 80 ),
                        'source'           => 'related_product_media_usage',
                        'source_object_id' => $sibling_id,
                        'usage'            => (string) $usage->tipo_uso,
                    )
                );
            }

            foreach ( seo_images_get_product_direct_attachments( $sibling_id ) as $direct ) {
                $candidates = seo_images_add_candidate(
                    $candidates,
                    $direct['attachment_id'],
                    array(
                        'priority'         => 'featured' === $direct['usage'] ? 86 : 82,
                        'source'           => 'related_product_direct',
                        'source_object_id' => $sibling_id,
                        'usage'            => $direct['usage'],
                    )
                );
            }
        }
    }

    $candidates = array_values( $candidates );

    usort(
        $candidates,
        static function ( $a, $b ) {
            if ( (int) $a['priority'] === (int) $b['priority'] ) {
                return (int) $a['attachment_id'] <=> (int) $b['attachment_id'];
            }

            return (int) $b['priority'] <=> (int) $a['priority'];
        }
    );

    return array_slice( $candidates, 0, $limit );
}

/**
 * Asigna un attachment existente a un producto y registra también la relación
 * en seo_media_usos para mantener sincronizados WordPress y el índice externo.
 *
 * @param int    $product_id    ID del producto.
 * @param int    $attachment_id ID de imagen elegido.
 * @param string $usage         featured o gallery.
 * @return bool|WP_Error
 */
function seo_images_assign_existing_attachment( $product_id, $attachment_id, $usage = 'featured' ) {
    $product_id    = absint( $product_id );
    $attachment_id = absint( $attachment_id );
    $usage         = sanitize_key( $usage );

    if ( $product_id < 1 || 'product' !== get_post_type( $product_id ) ) {
        return new WP_Error( 'seo_images_invalid_product', 'El producto no es válido.' );
    }

    if ( ! seo_images_is_valid_attachment( $attachment_id ) ) {
        return new WP_Error( 'seo_images_invalid_attachment', 'La imagen seleccionada no es un attachment válido.' );
    }

    if ( 'featured' === $usage ) {
        if ( ! set_post_thumbnail( $product_id, $attachment_id ) ) {
            return new WP_Error( 'seo_images_featured_assignment_failed', 'No se pudo asignar la imagen destacada.' );
        }
    } elseif ( 'gallery' === $usage ) {
        $gallery = array_filter(
            array_map(
                'absint',
                explode( ',', (string) get_post_meta( $product_id, '_product_image_gallery', true ) )
            )
        );

        $gallery[] = $attachment_id;
        $gallery   = array_values( array_unique( array_filter( $gallery ) ) );

        update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery ) );
    } else {
        return new WP_Error( 'seo_images_invalid_usage', 'Solo se puede asignar como imagen destacada o de galería.' );
    }

    $registered = seo_images_register_usage( $attachment_id, $product_id, $usage, 'product' );

    if ( is_wp_error( $registered ) ) {
        return $registered;
    }

    delete_post_meta( $product_id, '_seo_imagen_import_error' );

    return true;
}


/**
 * ============================================================================
 * CAPA UNIFICADA DE IMÁGENES (Media local + imágenes externas de proveedor)
 * ============================================================================
 *
 * La biblioteca de medios sigue siendo la fuente para attachments reales de
 * WordPress. Las galerías externas viven en seo_supplier_images. Esta capa
 * permite consultar ambas sin confundir sus responsabilidades.
 */

if (!function_exists('seo_images_table_supplier_images')) {
    function seo_images_table_supplier_images() {
        global $wpdb;
        return $wpdb->prefix . 'seo_supplier_images';
    }
}

if (!function_exists('seo_images_table_provider_products')) {
    function seo_images_table_provider_products() {
        global $wpdb;
        return $wpdb->prefix . 'seo_proveedores_productos';
    }
}

if (!function_exists('seo_images_table_relations')) {
    function seo_images_table_relations() {
        global $wpdb;
        return $wpdb->prefix . 'seo_relations';
    }
}

if (!function_exists('seo_images_table_nodes')) {
    function seo_images_table_nodes() {
        global $wpdb;
        return $wpdb->prefix . 'seo_nodes';
    }
}

if (!function_exists('seo_images_table_exists')) {
    function seo_images_table_exists($table) {
        global $wpdb;
        $table = (string) $table;
        if ($table === '') {
            return false;
        }
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }
}

/**
 * Obtiene las imágenes externas activas de un producto.
 */
if (!function_exists('seo_images_supplier_image_link_columns')) {
    /**
     * Campos que enlazan seo_supplier_images con el producto WooCommerce.
     *
     * Algunas importaciones historicas usan product_id y otras pueden conservar
     * object_id. Se detectan una sola vez por peticion para soportar ambos esquemas.
     */
    function seo_images_supplier_image_link_columns() {
        global $wpdb;
        static $columns = null;

        if (null !== $columns) {
            return $columns;
        }

        $columns = array();
        $table   = seo_images_table_supplier_images();

        if (!seo_images_table_exists($table)) {
            return $columns;
        }

        $fields = (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        foreach (array('product_id', 'object_id') as $candidate) {
            if (in_array($candidate, $fields, true)) {
                $columns[] = $candidate;
            }
        }

        return $columns;
    }
}

if (!function_exists('seo_images_get_external_product_images')) {
    function seo_images_get_external_product_images($product_id, $limit = 50) {
        global $wpdb;

        $product_id = absint($product_id);
        $limit      = max(1, min(200, absint($limit)));
        $table      = seo_images_table_supplier_images();

        if ($product_id < 1 || !seo_images_table_exists($table)) {
            return array();
        }

        $link_columns = seo_images_supplier_image_link_columns();
        if (empty($link_columns)) {
            return array();
        }

        $where_parts = array();
        $params      = array();
        foreach ($link_columns as $column) {
            // $column procede exclusivamente de la lista blanca anterior.
            $where_parts[] = "{$column} = %d";
            $params[]      = $product_id;
        }

        $params[] = $limit;
        $sql = "SELECT *
                FROM {$table}
                WHERE (" . implode(' OR ', $where_parts) . ")
                  AND status = 'active'
                  AND image_url IS NOT NULL
                  AND TRIM(image_url) <> ''
                ORDER BY is_primary DESC, position ASC, id ASC
                LIMIT %d";

        return (array) $wpdb->get_results(
            $wpdb->prepare($sql, $params),
            ARRAY_A
        );
    }
}

/**
 * Comprueba si un producto dispone de al menos una imagen utilizable, local o externa.
 */
/**
 * Devuelve la URL externa principal activa de un producto.
 *
 * Centraliza el acceso de las plantillas a seo_supplier_images y sustituye
 * el antiguo helper de Supplier Sync V2.
 *
 * @param int $product_id ID de producto WooCommerce.
 * @return string URL remota saneada o cadena vacia.
 */
if (!function_exists('seo_images_get_external_primary_url')) {
    function seo_images_get_external_primary_url($product_id) {
        $rows = seo_images_get_external_product_images(absint($product_id), 1);
        if (empty($rows) || empty($rows[0]['image_url'])) {
            return '';
        }

        $url = esc_url_raw((string) $rows[0]['image_url']);
        return (is_string($url) && preg_match('#^https?://#i', $url)) ? $url : '';
    }
}

if (!function_exists('seo_images_product_has_usable_image')) {
    function seo_images_product_has_usable_image($product_id) {
        $product_id = absint($product_id);

        if ($product_id < 1 || get_post_type($product_id) !== 'product') {
            return false;
        }

        foreach (seo_images_get_product_direct_attachments($product_id) as $direct) {
            if (!empty($direct['attachment_id']) && seo_images_is_valid_attachment($direct['attachment_id'])) {
                return true;
            }
        }

        $external = seo_images_get_external_product_images($product_id, 1);
        return !empty($external);
    }
}

/**
 * Clave estable para un candidato de imagen usado por formularios de asignación.
 */
if (!function_exists('seo_images_candidate_key')) {
    function seo_images_candidate_key($candidate) {
        $candidate = is_array($candidate) ? $candidate : array();
        $attachment_id = absint($candidate['attachment_id'] ?? 0);

        if ($attachment_id > 0) {
            return 'attachment:' . $attachment_id;
        }

        $url = seo_images_normalize_url($candidate['url'] ?? '');
        return $url !== '' ? 'external:' . hash('sha256', $url) : '';
    }
}

/**
 * Añade un candidato evitando duplicados.
 */
if (!function_exists('seo_images_candidate_add')) {
    function seo_images_candidate_add(&$candidates, $candidate) {
        if (!is_array($candidate)) {
            return;
        }

        $key = seo_images_candidate_key($candidate);
        if ($key === '' || isset($candidates[$key])) {
            return;
        }

        $candidate['key'] = $key;
        $candidates[$key] = $candidate;
    }
}

/**
 * Todos los candidatos utilizables de un producto.
 *
 * Importante: Media y proveedor NO son alternativas excluyentes. Un producto
 * puede aportar attachments locales y, a la vez, imagenes externas de
 * seo_supplier_images. La asignacion SEO decide despues cual materializar.
 */
if (!function_exists('seo_images_get_product_candidates')) {
    function seo_images_get_product_candidates($product_id, $limit = 12) {
        $product_id = absint($product_id);
        $limit      = max(1, min(50, absint($limit)));
        $candidates = array();

        if ($product_id < 1 || get_post_type($product_id) !== 'product') {
            return array();
        }

        foreach (seo_images_get_product_direct_attachments($product_id) as $row) {
            $attachment_id = absint($row['attachment_id'] ?? 0);
            if (!$attachment_id || !seo_images_is_valid_attachment($attachment_id)) {
                continue;
            }

            $record = seo_images_find_record_by_attachment($attachment_id);
            seo_images_candidate_add($candidates, array(
                'attachment_id' => $attachment_id,
                'url'           => wp_get_attachment_image_url($attachment_id, 'large') ?: wp_get_attachment_url($attachment_id),
                'provider'      => is_object($record) ? (string) ($record->proveedor ?? '') : '',
                'product_id'    => $product_id,
                'source'        => 'product_local',
                'source_label'  => 'Producto #' . $product_id . ' · Media',
            ));

            if (count($candidates) >= $limit) {
                return array_values($candidates);
            }
        }

        foreach (seo_images_get_external_product_images($product_id, $limit) as $row) {
            $url = seo_images_normalize_url($row['image_url'] ?? '');
            if ($url === '' || !wp_http_validate_url($url)) {
                continue;
            }

            $provider = sanitize_text_field($row['supplier'] ?? 'EXTERNAL');
            $source_url = seo_images_normalize_url($row['source_url'] ?? '');
            if ($source_url !== '' && !wp_http_validate_url($source_url)) {
                $source_url = '';
            }

            seo_images_candidate_add($candidates, array(
                'attachment_id' => 0,
                'url'           => $url,
                'provider'      => $provider,
                'product_id'    => $product_id,
                'source_url'    => $source_url,
                'source'        => 'product_external',
                'source_label'  => 'Producto #' . $product_id . ' · ' . ($provider ?: 'Externa'),
            ));

            if (count($candidates) >= $limit) {
                break;
            }
        }

        return array_values($candidates);
    }
}

/**
 * Compatibilidad con llamadas antiguas que esperan un unico candidato.
 */
if (!function_exists('seo_images_get_product_candidate')) {
    function seo_images_get_product_candidate($product_id) {
        $candidates = seo_images_get_product_candidates($product_id, 1);
        return !empty($candidates) ? $candidates[0] : null;
    }
}

/**
 * Convierte un candidato en attachment. Si ya es Media lo reutiliza; si es una
 * URL externa descarga únicamente esa imagen y la registra en el índice local.
 */
if (!function_exists('seo_images_materialize_candidate')) {
    function seo_images_materialize_candidate($candidate, $object_id = 0, $object_type = 'page') {
        if (!is_array($candidate)) {
            return new WP_Error('seo_images_invalid_candidate', 'El candidato de imagen no es válido.');
        }

        $attachment_id = absint($candidate['attachment_id'] ?? 0);
        if ($attachment_id && seo_images_is_valid_attachment($attachment_id)) {
            return $attachment_id;
        }

        $url         = seo_images_normalize_url($candidate['url'] ?? '');
        $provider    = sanitize_text_field($candidate['provider'] ?? 'EXTERNAL');
        $referer_url = seo_images_normalize_url($candidate['source_url'] ?? '');

        if ($url === '' || !wp_http_validate_url($url)) {
            return new WP_Error('seo_images_invalid_candidate_url', 'La imagen externa no tiene una URL válida.');
        }
        if ($referer_url !== '' && !wp_http_validate_url($referer_url)) {
            $referer_url = '';
        }

        return seo_images_get_or_import($provider ?: 'EXTERNAL', $url, 0, 'featured', $object_type, $referer_url);
    }
}

/**
 * Asigna un attachment como imagen representativa de un objeto SEO.
 */
if (!function_exists('seo_images_assign_attachment_to_object')) {
    function seo_images_assign_attachment_to_object($scope_type, $object_id, $attachment_id) {
        $scope_type    = sanitize_key($scope_type);
        $object_id     = absint($object_id);
        $attachment_id = absint($attachment_id);

        if (!$attachment_id || !seo_images_is_valid_attachment($attachment_id)) {
            return new WP_Error('seo_images_invalid_attachment', 'La imagen no es un attachment válido.');
        }

        if ($scope_type === 'product_cat') {
            $term = get_term($object_id, 'product_cat');
            if (!$term || is_wp_error($term)) {
                return new WP_Error('seo_images_invalid_category', 'La categoría no es válida.');
            }

            update_term_meta($object_id, 'thumbnail_id', $attachment_id);
            if (absint(get_term_meta($object_id, 'thumbnail_id', true)) !== $attachment_id) {
                return new WP_Error('seo_images_category_assignment_failed', 'WordPress no pudo guardar thumbnail_id en la categoría.');
            }

            $usage_result = seo_images_register_usage($attachment_id, $object_id, 'featured', 'product_cat');
            if (is_wp_error($usage_result)) {
                error_log('[SEO Images] La categoría recibió la imagen, pero no se pudo registrar el uso: ' . $usage_result->get_error_message());
            }
            return true;
        }

        if (!in_array($scope_type, array('landing', 'hub_secondary', 'hub_primary', 'cluster', 'page', 'post'), true)) {
            return new WP_Error('seo_images_invalid_scope', 'El tipo de objeto no admite asignación automática.');
        }

        if ($scope_type === 'post') {
            if (get_post_type($object_id) !== 'post') {
                return new WP_Error('seo_images_invalid_post', 'El post no es válido.');
            }
        } elseif (get_post_type($object_id) !== 'page') {
            return new WP_Error('seo_images_invalid_page', 'La página no es válida.');
        }

        $thumbnail_result = set_post_thumbnail($object_id, $attachment_id);
        if (!$thumbnail_result && absint(get_post_thumbnail_id($object_id)) !== $attachment_id) {
            return new WP_Error('seo_images_assignment_failed', 'No se pudo asignar la imagen destacada.');
        }

        $usage_result = seo_images_register_usage($attachment_id, $object_id, 'featured', $scope_type);
        if (is_wp_error($usage_result)) {
            $object_label = $scope_type === 'post' ? 'El post' : 'La página';
            error_log('[SEO Images] ' . $object_label . ' recibió la imagen, pero no se pudo registrar el uso: ' . $usage_result->get_error_message());
        }
        return true;
    }
}
