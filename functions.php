<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================
   SEO BASE: IDIOMA + ESPAÑA
========================================================= */
add_filter('language_attributes', function($output) {
    return 'lang="es-ES" ' . (string) $output;
});

add_action('wp_head', function () {
    echo '<link rel="alternate" hreflang="es-ES" href="' . esc_url(home_url('/')) . '" />' . "\n";
}, 1);


/* =========================================================
   TRACKING
   Las balizas de terceros no se cargan automaticamente desde el plugin.
   Las integraciones de analitica deben activarse/configurarse expresamente
   desde sus modulos y respetar el consentimiento aplicable.
========================================================= */

/* =========================================================
   DESACTIVAR HEADER DEFAULT GENERATEPRESS
========================================================= */
/* =========================================================
   LIMPIAR HEADER + CONTENEDORES GP
========================================================= */
add_action('after_setup_theme', function () {

    remove_action('generate_before_header', 'generate_construct_header');
    remove_action('generate_after_header', 'generate_construct_header');

}, 20);

add_action('wp', function () {

    // Evita estructura de contenedores de GP en header
    add_filter('generate_show_title', '__return_false');
    add_filter('generate_show_site_header', '__return_false');

});



/* =========================================================
   WOOCOMMERCE: GALERÍA PRODUCTO (MEJORADO)
========================================================= */
add_filter('woocommerce_product_get_gallery_image_ids', function($gallery, $product) {

    $raw = get_post_meta($product->get_id(), 'Purchase note', true);

    if (empty($raw)) return $gallery;

    $urls = array_filter(array_map('trim', explode(',', $raw)));
    $image_ids = [];

    foreach ($urls as $url) {
        $attachment_id = attachment_url_to_postid($url);
        $image_ids[] = $attachment_id ? $attachment_id : $url;
    }

    return $image_ids;

}, 10, 2);


/* =========================================================
   LIMPIEZA WOOCOMMERCE (CUPONES + WRAPPERS)
========================================================= */
remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);

remove_action('woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10);
remove_action('woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10);
add_filter('woocommerce_coupons_enabled', '__return_false');


/* =========================================================
   CSS WOOCOMMERCE (CARGA CONTROLADA)
========================================================= */
add_action('wp_enqueue_scripts', function () {

    if (is_admin()) return;

    $css = get_stylesheet_directory() . '/asset/css/woocommerce-products.css';

    if (file_exists($css)) {
        wp_enqueue_style(
            'dht-woocommerce-products',
            get_stylesheet_directory_uri() . '/asset/css/woocommerce-products.css',
            [],
            filemtime($css)
        );
    }

}, 99);


/* =========================================================
   CATEGORÍAS EN PÁGINAS
========================================================= */
function dht_add_categories_to_pages() {
    register_taxonomy_for_object_type('category', 'page');
}
add_action('init', 'dht_add_categories_to_pages');



/* =========================================================
   IMÁGENES OPTIMIZADAS (IMPORTANTE PARA LCP)
========================================================= */
add_image_size('category_thumb', 160, 160, true);
add_image_size('category_hero', 300, 300, true);



/* =========================================================
   ACTIVA PLANTILLAS EN LAS PAGINAS PARA VER TIPOS
========================================================= */

add_filter('manage_pages_columns', function($columns) {
    $columns['page_template'] = 'Plantilla';
    return $columns;
});

add_action('manage_pages_custom_column', function($column, $post_id) {
    if ($column === 'page_template') {
        $template = get_page_template_slug($post_id);

        if ($template) {
            echo esc_html($template);
        } else {
            echo 'default';
        }
    }
}, 10, 2);

/* =========================================================
   QUITA EL READMORE DE OS CONTENIDOS
========================================================= */
remove_filter('the_content', 'wpautop');
add_filter('excerpt_more', '__return_empty_string');
add_filter('the_excerpt', function($excerpt) {

    $excerpt = (string) $excerpt;

    return wp_trim_words(
        wp_strip_all_tags($excerpt),
        30,
        ''
    );

});

/* =========================================================
   NECESARIO PARA LAS BUSQUEDAS CON PLUGION
========================================================= */
add_action('wp_enqueue_scripts', function () {
    if (class_exists('AWS_Main')) {
        do_action('aws_enqueue_scripts');
    }
}, 20);

// Desactivar Gutenberg en la edición de categorías y taxonomías
add_filter('wp_edit_term_use_block_editor', '__return_false', 10);

// Desactivar Gutenberg en el resto de la web por completo
add_filter('use_block_editor_for_post', '__return_false', 10);


/* =========================================================
   AGREGA EXCERPT DE LAS PAGINAS
========================================================= */
// Activar la caja de extracto (excerpt) en las páginas de WordPress
add_action('init', function() {
    add_post_type_support('page', 'excerpt');
});



function interceptar_redireccion_antes_de_wordpress() {

    if (is_admin()) {
        return;
    }

    global $wpdb;

    $url_solicitada = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';

    if (($pos = strpos($url_solicitada, '?')) !== false) {
        $url_solicitada = substr($url_solicitada, 0, $pos);
    }
    if (
        preg_match(
            '/\.(jpg|jpeg|png|gif|ico|css|js|webp|svg|woff|woff2)$/i',
            $url_solicitada
        )
    ) {
        return;
    }

    $url_solicitada = '/' . ltrim(
        $url_solicitada,
        '/'
    );

    $url_alternativa =
        (substr($url_solicitada, -1) === '/')
        ? rtrim($url_solicitada, '/')
        : $url_solicitada . '/';
    if (
        strpos(
            $url_solicitada,
            'logistica-embalaje-preparacion-de-pedidos'
        ) !== false
    ) {
        return;
    }

    $tabla = $wpdb->prefix . 'seo_redirects';

    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Internal redirects table; both URL variants are bound through prepare().
    $redireccion = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id,target_url,hits
             FROM {$tabla}
             WHERE origin_url=%s
                OR origin_url=%s
             LIMIT 1",
            $url_solicitada,
            $url_alternativa
        )
    );
    if ($redireccion) {

        $wpdb->update(
            $tabla,
            [
                'hits' => intval($redireccion->hits) + 1,
                'last_hit' => current_time('mysql')
            ],
            [
                'id' => $redireccion->id
            ]
        );

        header(
            "Cache-Control: no-store, no-cache, must-revalidate, max-age=0"
        );

        header("Pragma: no-cache");
        wp_redirect(
            $redireccion->target_url,
            301
        );

        exit;
    }
}

add_action(
    'send_headers',
    'interceptar_redireccion_antes_de_wordpress',
    1
);

/* =========================================================
   SEO SYSTEM: LOG PRIVADO CON ROTACION SEMANAL
   - No usa wp-content/debug.log.
   - Crea el log fuera del document root publico.
   - Mantiene como maximo 7 slots, uno por dia de la semana.
   - El log del dia actual queda abierto en .log.
   - Al cambiar de dia, el log cerrado se comprime a .log.gz.
   - Al volver al mismo dia una semana despues, su slot anterior
     se elimina/sobrescribe: nunca se crea un octavo log.
========================================================= */
add_action('init', 'seo_system_private_log_bootstrap', 1);

/**
 * Devuelve el document root publico normalizado.
 */
function seo_system_private_log_document_root() {
    $uploads = wp_upload_dir(null, false);
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return '';
    }
    return untrailingslashit(wp_normalize_path((string) $uploads['basedir']));
}

/**
 * Compatibilidad con el nombre historico: valida que la ruta pertenezca
 * al directorio privado permitido dentro de uploads/seo-taxonomy/private-logs.
 */
function seo_system_private_log_is_outside_public_root($path) {
    $uploads = wp_upload_dir(null, false);
    if (!empty($uploads['error']) || empty($uploads['basedir']) || $path === '') {
        return false;
    }

    $allowed = trailingslashit(wp_normalize_path((string) $uploads['basedir']))
        . 'seo-taxonomy/private-logs/';
    $normalized = wp_normalize_path((string) $path);

    return strpos($normalized, $allowed) === 0;
}

/**
 * Slugs fijos de los siete slots semanales.
 */
function seo_system_private_log_weekday_slugs() {

    return array(
        1 => 'lunes',
        2 => 'martes',
        3 => 'miercoles',
        4 => 'jueves',
        5 => 'viernes',
        6 => 'sabado',
        7 => 'domingo',
    );
}

/**
 * Devuelve la fecha y el slot correspondientes al dia actual de WordPress.
 */
function seo_system_private_log_current_day() {

    $weekday = (int) current_time('N');
    $slugs = seo_system_private_log_weekday_slugs();

    if (!isset($slugs[$weekday])) {
        $weekday = 1;
    }

    return array(
        'date'    => (string) current_time('Y-m-d'),
        'weekday' => $weekday,
        'slug'    => $slugs[$weekday],
    );
}

/**
 * Devuelve el directorio privado. Si ya habia una ruta guardada fuera
 * del document root, conserva su directorio para no cambiar de ubicacion.
 */
function seo_system_get_private_log_directory() {
    $uploads = wp_upload_dir(null, false);
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return '';
    }

    $salt = substr(hash('sha256', wp_salt('auth') . '|seo-taxonomy-private-logs'), 0, 16);
    $private_dir = trailingslashit(wp_normalize_path((string) $uploads['basedir']))
        . 'seo-taxonomy/private-logs-' . $salt;

    return wp_normalize_path($private_dir);
}

/**
 * Devuelve la ruta del log activo del dia actual.
 */
function seo_system_get_private_log_path() {

    $private_dir = seo_system_get_private_log_directory();

    if ($private_dir === '') {
        return '';
    }

    $day = seo_system_private_log_current_day();
    $log_file = trailingslashit($private_dir) . 'seo-system-' . $day['slug'] . '.log';

    return wp_normalize_path($log_file);
}

/**
 * Rutas del slot de un dia: activo sin comprimir y archivo cerrado gzip.
 */
function seo_system_private_log_slot_paths($private_dir, $slug) {

    $base = trailingslashit($private_dir) . 'seo-system-' . $slug . '.log';

    return array(
        'active'  => wp_normalize_path($base),
        'archive' => wp_normalize_path($base . '.gz'),
    );
}

/**
 * Convierte el mtime de un archivo a fecha local de WordPress.
 */
function seo_system_private_log_file_date($path) {

    $mtime = @filemtime($path);

    if ($mtime === false) {
        return '';
    }

    return wp_date('Y-m-d', $mtime, wp_timezone());
}

/**
 * Convierte el mtime de un archivo a dia ISO de WordPress (1=lunes, 7=domingo).
 */
function seo_system_private_log_file_weekday($path) {

    $mtime = @filemtime($path);

    if ($mtime === false) {
        return 0;
    }

    return (int) wp_date('N', $mtime, wp_timezone());
}

/**
 * Elimina un archivo mediante WordPress y conserva un booleano fiable también
 * en versiones anteriores a WordPress 6.7, donde wp_delete_file() no devolvía
 * todavía el resultado de unlink().
 */
function seo_system_private_log_delete_file($path) {

    $result = wp_delete_file($path);

    if (is_bool($result)) {
        return $result;
    }

    return !file_exists($path);
}

/**
 * Abre un recurso local para el log privado.
 *
 * El flujo usa flock() y lectura/escritura incremental; WP_Filesystem no
 * expone un recurso equivalente sin alterar la semántica de concurrencia.
 *
 * @param string $path Ruta local.
 * @param string $mode Modo de apertura.
 * @return resource|false
 */
function seo_system_private_log_stream_open($path, $mode) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Private log locking requires a native PHP stream resource.
    return @fopen($path, $mode);
}

/**
 * Lee un bloque del recurso del log privado.
 *
 * @param resource $stream Recurso abierto.
 * @param int      $length Bytes máximos.
 * @return string|false
 */
function seo_system_private_log_stream_read($stream, $length) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Incremental gzip copy requires bounded reads from the locked stream.
    return fread($stream, $length);
}

/**
 * Escribe en el recurso del log privado.
 *
 * @param resource $stream Recurso abierto.
 * @param string   $data   Datos.
 * @return int|false
 */
function seo_system_private_log_stream_write($stream, $data) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Locked log initialization requires direct stream write.
    return @fwrite($stream, $data);
}

/**
 * Cierra un recurso del log privado.
 *
 * @param resource $stream Recurso abierto.
 * @return bool
 */
function seo_system_private_log_stream_close($stream) {
    if (!is_resource($stream)) {
        return false;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with seo_system_private_log_stream_open().
    return @fclose($stream);
}

/**
 * Renombra archivos locales preservando la rotación atómica.
 *
 * @param string $source Origen.
 * @param string $target Destino.
 * @return bool
 */
function seo_system_private_log_atomic_rename($source, $target) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Private log rotation requires local atomic rename semantics.
    return @rename($source, $target);
}

/**
 * Aplica permisos restrictivos al directorio o fichero del log privado.
 *
 * @param string $path Ruta local.
 * @param int    $mode Permisos POSIX.
 * @return bool
 */
function seo_system_private_log_set_permissions($path, $mode) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Private logs intentionally enforce 0600/0700 permissions.
    return @chmod($path, $mode);
}

/**
 * Copia un archivo a gzip y elimina el original solo cuando el gzip
 * ha quedado creado correctamente.
 */
function seo_system_private_log_compress_file($source, $destination) {

    if (
        !function_exists('gzopen') ||
        !function_exists('gzwrite') ||
        !is_file($source) ||
        !is_readable($source)
    ) {
        return false;
    }

    $input = seo_system_private_log_stream_open($source, 'rb');

    if ($input === false) {
        return false;
    }

    if (!@flock($input, LOCK_EX)) {
        seo_system_private_log_stream_close($input);
        return false;
    }

    $temporary = @tempnam(dirname($destination), '.seo-log-');

    if ($temporary === false) {
        @flock($input, LOCK_UN);
        seo_system_private_log_stream_close($input);
        return false;
    }

    $output = @gzopen($temporary, 'wb9');

    if ($output === false) {
        seo_system_private_log_delete_file($temporary);
        @flock($input, LOCK_UN);
        seo_system_private_log_stream_close($input);
        return false;
    }

    $ok = true;

    while (!feof($input)) {
        $chunk = seo_system_private_log_stream_read($input, 1024 * 1024);

        if ($chunk === false) {
            $ok = false;
            break;
        }

        if ($chunk === '') {
            continue;
        }

        $length = strlen($chunk);
        $offset = 0;

        while ($offset < $length) {
            $written = @gzwrite($output, substr($chunk, $offset));

            if ($written === false || $written === 0) {
                $ok = false;
                break 2;
            }

            $offset += $written;
        }
    }

    @gzclose($output);
    @flock($input, LOCK_UN);
    seo_system_private_log_stream_close($input);

    if (!$ok) {
        seo_system_private_log_delete_file($temporary);
        return false;
    }

    seo_system_private_log_set_permissions($temporary, 0600);

    if (is_file($destination) && !seo_system_private_log_delete_file($destination)) {
        seo_system_private_log_delete_file($temporary);
        return false;
    }

    if (!seo_system_private_log_atomic_rename($temporary, $destination)) {
        seo_system_private_log_delete_file($temporary);
        return false;
    }

    seo_system_private_log_set_permissions($destination, 0600);

    if (!seo_system_private_log_delete_file($source)) {
        // Conserva el original si no se puede completar la rotacion.
        seo_system_private_log_delete_file($destination);
        return false;
    }

    return true;
}

/**
 * Migra el antiguo seo-system.log a la politica semanal sin crear un
 * archivo adicional permanente.
 */
function seo_system_private_log_migrate_legacy($private_dir, array $current_day) {

    $legacy = trailingslashit($private_dir) . 'seo-system.log';

    if (!is_file($legacy)) {
        return true;
    }

    $current_paths = seo_system_private_log_slot_paths($private_dir, $current_day['slug']);
    $legacy_date = seo_system_private_log_file_date($legacy);

    // Si el log legacy se estaba usando hoy, pasa a ser el log activo de hoy.
    if ($legacy_date === $current_day['date'] && !file_exists($current_paths['active'])) {
        if (!seo_system_private_log_atomic_rename($legacy, $current_paths['active'])) {
            return false;
        }

        seo_system_private_log_set_permissions($current_paths['active'], 0600);
        return true;
    }

    $weekday = seo_system_private_log_file_weekday($legacy);
    $slugs = seo_system_private_log_weekday_slugs();

    if (!isset($slugs[$weekday])) {
        return seo_system_private_log_delete_file($legacy);
    }

    $legacy_slug = $slugs[$weekday];

    // Si pertenece al mismo slot que hoy pero ya es antiguo, se descarta:
    // hoy debe sobrescribir ese slot semanal.
    if ($legacy_slug === $current_day['slug']) {
        return seo_system_private_log_delete_file($legacy);
    }

    $legacy_paths = seo_system_private_log_slot_paths($private_dir, $legacy_slug);

    // No pisa un slot ya migrado/rotado. El legacy es solo compatibilidad.
    if (is_file($legacy_paths['active']) || is_file($legacy_paths['archive'])) {
        return seo_system_private_log_delete_file($legacy);
    }

    return seo_system_private_log_compress_file($legacy, $legacy_paths['archive']);
}

/**
 * Rota los siete slots. El slot actual queda en .log y los dias cerrados
 * quedan en .log.gz. El archivo de la misma jornada de la semana anterior
 * se elimina antes de crear el nuevo.
 */
function seo_system_private_log_rotate($private_dir, array $current_day) {

    if (!seo_system_private_log_migrate_legacy($private_dir, $current_day)) {
        return false;
    }

    $slugs = seo_system_private_log_weekday_slugs();

    foreach ($slugs as $slug) {
        $paths = seo_system_private_log_slot_paths($private_dir, $slug);

        if ($slug === $current_day['slug']) {
            // El gzip de este mismo dia corresponde, como minimo, a la semana
            // anterior y debe dejar paso al slot actual.
            if (is_file($paths['archive']) && !seo_system_private_log_delete_file($paths['archive'])) {
                return false;
            }

            if (is_file($paths['active'])) {
                $active_date = seo_system_private_log_file_date($paths['active']);

                // Mismo nombre de weekday, pero de otra semana: sobrescribir.
                if ($active_date !== $current_day['date'] && !seo_system_private_log_delete_file($paths['active'])) {
                    return false;
                }
            }

            continue;
        }

        // Cualquier .log de otro weekday es un dia ya cerrado.
        if (is_file($paths['active'])) {
            if (!seo_system_private_log_compress_file($paths['active'], $paths['archive'])) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Crea, si hace falta, el directorio y el archivo activo del día.
 * Los logs se guardan bajo uploads/seo-taxonomy en un subdirectorio no
 * predecible y protegido; nunca se escriben en core, themes o plugins.
 */
function seo_system_private_log_bootstrap() {

    static $ready_date = '';
    static $ready_path = '';

    $current_day = seo_system_private_log_current_day();

    if (
        $ready_date === $current_day['date'] &&
        $ready_path !== '' &&
        is_file($ready_path) &&
        wp_is_writable($ready_path)
    ) {
        return $ready_path;
    }

    $private_dir = seo_system_get_private_log_directory();

    if ($private_dir === '') {
        return false;
    }

    if (!is_dir($private_dir)) {
        if (!wp_mkdir_p($private_dir)) {
            return false;
        }
        seo_system_private_log_set_permissions($private_dir, 0700);

        // Defensa adicional contra acceso web directo en servidores Apache.
        $htaccess = trailingslashit($private_dir) . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nDeny from all\n", LOCK_EX);
        }
        $web_config = trailingslashit($private_dir) . 'web.config';
        if (!file_exists($web_config)) {
            @file_put_contents(
                $web_config,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>",
                LOCK_EX
            );
        }
        $index = trailingslashit($private_dir) . 'index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n", LOCK_EX);
        }
    }

    if (!wp_is_writable($private_dir)) {
        return false;
    }

    if (!seo_system_private_log_rotate($private_dir, $current_day)) {
        return false;
    }

    $paths = seo_system_private_log_slot_paths($private_dir, $current_day['slug']);
    $log_file = $paths['active'];
    $created = false;

    if (!file_exists($log_file)) {
        // 'x' evita que dos peticiones simultaneas trunquen el mismo log.
        $handle = seo_system_private_log_stream_open($log_file, 'x');

        if ($handle !== false) {
            $created = true;
            seo_system_private_log_set_permissions($log_file, 0600);

            $line = sprintf(
                "[%s] [INFO] SEO System: log diario inicializado (%s).%s",
                current_time('mysql'),
                $current_day['date'],
                PHP_EOL
            );

            @flock($handle, LOCK_EX);
            seo_system_private_log_stream_write($handle, $line);
            @fflush($handle);
            @flock($handle, LOCK_UN);
            seo_system_private_log_stream_close($handle);
        }
    }

    if (!is_file($log_file) || !wp_is_writable($log_file)) {
        return false;
    }

    update_option('seo_system_private_log_path', $log_file, false);

    // Si otro proceso creo el archivo entre la comprobacion y fopen('x'),
    // simplemente se reutiliza. La cabecera informativa no es obligatoria.
    if ($created) {
        clearstatcache(true, $log_file);
    }

    $ready_date = $current_day['date'];
    $ready_path = $log_file;

    return $log_file;
}

/**
 * Oculta valores sensibles del contexto antes de escribirlos.
 */
function seo_system_private_log_redact($value, $key = '') {

    if (
        $key !== '' &&
        preg_match('/password|passwd|secret|token|nonce|cookie|session|authorization|api[_-]?key|private[_-]?key/i', (string) $key)
    ) {
        return '[REDACTED]';
    }

    if (is_array($value)) {
        $clean = array();
        foreach ($value as $child_key => $child_value) {
            $clean[$child_key] = seo_system_private_log_redact($child_value, (string) $child_key);
        }
        return $clean;
    }

    if (is_object($value)) {
        return seo_system_private_log_redact((array) $value, $key);
    }

    return $value;
}

/**
 * Logger propio de SEO System.
 *
 * Ejemplo:
 * seo_system_private_log('error', 'Fallo al actualizar', array('id' => 123));
 */
function seo_system_private_log($level, $message, array $context = array()) {

    $log_file = seo_system_private_log_bootstrap();

    if (!$log_file) {
        return false;
    }

    $allowed_levels = array('debug', 'info', 'warning', 'error', 'critical');
    $level = strtolower((string) $level);

    if (!in_array($level, $allowed_levels, true)) {
        $level = 'info';
    }

    $line = sprintf(
        '[%s] [%s] %s',
        current_time('mysql'),
        strtoupper($level),
        (string) $message
    );

    if (!empty($context)) {
        $safe_context = seo_system_private_log_redact($context);
        $encoded = wp_json_encode(
            $safe_context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (is_string($encoded) && $encoded !== '') {
            $line .= ' ' . $encoded;
        }
    }

    $line .= PHP_EOL;

    return @file_put_contents(
        $log_file,
        $line,
        FILE_APPEND | LOCK_EX
    ) !== false;
}
