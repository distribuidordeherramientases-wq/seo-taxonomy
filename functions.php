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
   TRACKING AUXILIAR DEL TEMA
   - GA4 SE GESTIONA DESDE EL PLUGIN SEO SYSTEM
   - SOLO EN FRONTEND
========================================================= */
function dht_scripts_globales() {

    if ( is_admin() ) return;
    if ( current_user_can('manage_options') ) return; // evita medición interna

    ?>

    <!-- Cloudflare Insights -->
    <script defer src="https://static.cloudflareinsights.com/beacon.min.js"
    data-cf-beacon='{"token":"0f5c4f0eb18c4a88aed6898c030f742f"}'></script>

    <!-- Clarity SOLO en interacción inicial -->
    <script>
    (function(){
        function loadClarity(){
            if(window.__clarity_loaded) return;
            window.__clarity_loaded = true;

            (function(c,l,a,r,i,t,y){
                c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                t=l.createElement(r);t.async=1;
                t.src="https://www.clarity.ms/tag/"+i+"?ref=bwt";
                y=l.getElementsByTagName(r)[0];
                y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "ww0i0jxbs8");
        }

        window.addEventListener('scroll', loadClarity, {once:true});
        window.addEventListener('mousemove', loadClarity, {once:true});
        setTimeout(loadClarity, 5000);
    })();
    </script>
    <?php
}
add_action('wp_head', 'dht_scripts_globales', 999);


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
        strip_tags($excerpt),
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

    $url_solicitada = $_SERVER['REQUEST_URI'];

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

    $sql = $wpdb->prepare(
        "SELECT id,target_url,hits
         FROM $tabla
         WHERE origin_url=%s
            OR origin_url=%s
         LIMIT 1",
        $url_solicitada,
        $url_alternativa
    );

    $redireccion = $wpdb->get_row($sql);
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
   SEO SYSTEM: LOG PRIVADO
   - No usa wp-content/debug.log.
   - Crea el log fuera del document root publico.
   - Guarda la ruta absoluta en wp_options para que el plugin
     y el panel de diagnostico usen exactamente el mismo archivo.
========================================================= */
add_action('init', 'seo_system_private_log_bootstrap', 1);

/**
 * Devuelve el document root publico normalizado.
 */
function seo_system_private_log_document_root() {

    if (empty($_SERVER['DOCUMENT_ROOT'])) {
        return '';
    }

    $document_root = realpath((string) $_SERVER['DOCUMENT_ROOT']);

    if ($document_root === false) {
        return '';
    }

    return untrailingslashit(wp_normalize_path($document_root));
}

/**
 * Comprueba que una ruta no este dentro del document root publico.
 */
function seo_system_private_log_is_outside_public_root($path) {

    $document_root = seo_system_private_log_document_root();

    if ($document_root === '' || $path === '') {
        return false;
    }

    $normalized_path = wp_normalize_path($path);
    $normalized_root = trailingslashit($document_root);

    return strpos($normalized_path, $normalized_root) !== 0;
}

/**
 * Devuelve la ruta prevista/guardada para el log privado de SEO System.
 */
function seo_system_get_private_log_path() {

    $saved_path = get_option('seo_system_private_log_path', '');

    if (
        is_string($saved_path) &&
        $saved_path !== '' &&
        seo_system_private_log_is_outside_public_root($saved_path)
    ) {
        return wp_normalize_path($saved_path);
    }

    $document_root = seo_system_private_log_document_root();

    if ($document_root === '') {
        return '';
    }

    $private_dir = dirname($document_root) . '/seo-system-private';
    $log_file = trailingslashit($private_dir) . 'seo-system.log';

    if (!seo_system_private_log_is_outside_public_root($log_file)) {
        return '';
    }

    return wp_normalize_path($log_file);
}

/**
 * Crea, si hace falta, el directorio y archivo del log privado.
 * No hace fallback a wp-content: si no puede crearlo fuera del
 * directorio publico devuelve false.
 */
function seo_system_private_log_bootstrap() {

    $log_file = seo_system_get_private_log_path();

    if ($log_file === '' || !seo_system_private_log_is_outside_public_root($log_file)) {
        return false;
    }

    $private_dir = dirname($log_file);

    if (!is_dir($private_dir)) {
        if (!wp_mkdir_p($private_dir)) {
            return false;
        }
        @chmod($private_dir, 0700);
    }

    if (!is_writable($private_dir)) {
        return false;
    }

    $created = false;

    if (!file_exists($log_file)) {
        if (@file_put_contents($log_file, '', LOCK_EX) === false) {
            return false;
        }
        @chmod($log_file, 0600);
        $created = true;
    }

    if (!is_file($log_file) || !is_writable($log_file)) {
        return false;
    }

    update_option('seo_system_private_log_path', $log_file, false);

    if ($created) {
        $line = sprintf(
            "[%s] [INFO] SEO System: log privado inicializado.%s",
            current_time('mysql'),
            PHP_EOL
        );
        @file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
    }

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
