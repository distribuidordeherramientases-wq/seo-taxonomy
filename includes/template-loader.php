<?php
if (!defined('ABSPATH')) exit;

add_filter('template_include', 'seo_template_loader', PHP_INT_MAX);

/**
 * Devuelve el nombre fisico esperado para una variante de dispositivo.
 *
 * La plantilla principal sigue siendo la unica registrada/asignable.
 * Las variantes se deducen por convencion:
 *   template-cluster.php -> template-cluster-mobile.php
 *                           template-cluster-desktop.php
 */
function seo_template_variant_filename($template_file, $variant) {
    $template_file = basename((string) $template_file);
    $variant       = sanitize_key((string) $variant);

    if (!in_array($variant, ['mobile', 'desktop'], true)) {
        return '';
    }

    if (strtolower(pathinfo($template_file, PATHINFO_EXTENSION)) !== 'php') {
        return '';
    }

    $stem = pathinfo($template_file, PATHINFO_FILENAME);

    /* Una variante nunca debe generar subvariantes. */
    if (preg_match('/-(mobile|desktop)$/i', $stem)) {
        return '';
    }

    return $stem . '-' . $variant . '.php';
}

/**
 * Detecta las plantillas principales legacy que solo actuan como dispatcher.
 *
 * Estas plantillas no son renderizables por si mismas, por lo que las
 * variantes se consideran obligatorias hasta que el archivo principal sea
 * sustituido por una plantilla completa.
 */
function seo_template_is_device_dispatcher($file_path) {
    if (!is_file($file_path) || !is_readable($file_path)) {
        return false;
    }

    $content = file_get_contents($file_path, false, null, 0, 8192);

    if ($content === false) {
        return false;
    }

    return preg_match(
        '/\brequire(?:_once)?\s+dht_template_device_variant_file\s*\(/',
        $content
    ) === 1;
}

/**
 * Calcula de forma explicita que archivo se usara para un dispositivo.
 *
 * Reglas:
 * - La plantilla principal es siempre la registrada y asignada.
 * - Si las variantes estan desactivadas, se usa la principal.
 * - Si estan activadas y existe la variante del dispositivo, se usa esa.
 * - Si falta una variante, una principal completa actua como fallback.
 * - Las principales legacy que son solo dispatcher fuerzan las variantes;
 *   si les falta una, se conserva temporalmente el fallback legacy a la
 *   variante opuesta para evitar una pagina en blanco/error 500.
 */
function seo_template_device_render_plan($tpl, $device, $base_path) {
    $device    = $device === 'mobile' ? 'mobile' : 'desktop';
    $base_path = trailingslashit((string) $base_path);

    $principal_file = isset($tpl->template_file) ? basename((string) $tpl->template_file) : '';
    $principal_path = $principal_file !== '' ? $base_path . $principal_file : '';
    $principal_ok   = $principal_path !== '' && is_file($principal_path) && is_readable($principal_path);

    $dispatcher     = $principal_ok && seo_template_is_device_dispatcher($principal_path);
    $stored_enabled = isset($tpl->device_variants_enabled) && (int) $tpl->device_variants_enabled === 1;
    $variant_file   = seo_template_variant_filename($principal_file, $device);
    $supports       = $variant_file !== '';
    $enabled        = $supports && ($stored_enabled || $dispatcher);

    $variant_path   = $variant_file !== '' ? $base_path . $variant_file : '';
    $variant_ok     = $variant_path !== '' && is_file($variant_path) && is_readable($variant_path);

    $effective_file = $principal_file;
    $effective_path = $principal_path;
    $source         = 'primary';
    $fallback       = '';

    if ($principal_ok && $enabled && $variant_ok) {
        $effective_file = $variant_file;
        $effective_path = $variant_path;
        $source         = $device;
    } elseif ($principal_ok && $enabled && $dispatcher) {
        $opposite      = $device === 'mobile' ? 'desktop' : 'mobile';
        $opposite_file = seo_template_variant_filename($principal_file, $opposite);
        $opposite_path = $opposite_file !== '' ? $base_path . $opposite_file : '';

        if ($opposite_path !== '' && is_file($opposite_path) && is_readable($opposite_path)) {
            $effective_file = $opposite_file;
            $effective_path = $opposite_path;
            $source         = $opposite;
            $fallback       = 'legacy_opposite';
        }
    }

    return [
        'device'                  => $device,
        'principal_file'          => $principal_file,
        'principal_path'          => $principal_path,
        'principal_exists'        => $principal_ok,
        'dispatcher'              => $dispatcher,
        'stored_variants_enabled' => $stored_enabled,
        'variants_enabled'        => $enabled,
        'variants_forced'         => $dispatcher && !$stored_enabled,
        'variant_file'            => $variant_file,
        'variant_path'            => $variant_path,
        'variant_exists'          => $variant_ok,
        'effective_file'          => $effective_file,
        'effective_path'          => $effective_path,
        'source'                  => $source,
        'fallback'                => $fallback,
    ];
}

/**
 * Resuelve una fila de wp_seo_templates a su archivo fisico efectivo.
 */
function seo_template_resolve_registered_file($tpl, $base_path) {
    if (!$tpl || (int) $tpl->is_active !== 1) {
        return '';
    }

    $device = wp_is_mobile() ? 'mobile' : 'desktop';
    $plan   = seo_template_device_render_plan($tpl, $device, $base_path);

    if (!$plan['principal_exists']) {
        return '';
    }

    if ($plan['effective_path'] === '' || !is_file($plan['effective_path'])) {
        return '';
    }

    /*
     * Variables de diagnostico disponibles para plantillas y herramientas
     * de depuracion. No participan en la asignacion.
     */
    $active_base = pathinfo($plan['principal_file'], PATHINFO_FILENAME);
    if (strpos($active_base, 'template-') === 0) {
        $active_base = substr($active_base, strlen('template-'));
    }

    $GLOBALS['dht_template_requested_variant'] = $device;
    $GLOBALS['dht_template_active_variant']    = $plan['source'];
    $GLOBALS['dht_template_active_base']       = $active_base;
    $GLOBALS['dht_template_render_plan']       = $plan;

    return $plan['effective_path'];
}

/**
 * Recupera solo las columnas necesarias para resolver una plantilla.
 *
 * En instalaciones antiguas la columna de variantes puede no existir todavía,
 * por lo que se consulta únicamente cuando la migración 2.1.0 ya terminó.
 */
function seo_template_get_registered_template($template_key) {
    global $wpdb;

    $template_key = sanitize_key((string) $template_key);
    if ($template_key === '') {
        return null;
    }

    $fields = 'template_file, is_active';

    if (version_compare((string) get_option('seo_tm_schema_version', '0.0.0'), '2.1.0', '>=')) {
        $fields .= ', device_variants_enabled';
    }

    return $wpdb->get_row($wpdb->prepare(
        "SELECT {$fields}
         FROM {$wpdb->prefix}seo_templates
         WHERE template_key = %s
         LIMIT 1",
        $template_key
    ));
}

function seo_template_loader($template) {

    global $wpdb;

    $base_path = SEO_SYSTEM_PATH . 'seo-system/templates/';

    /* =====================================================
       1. FRONT PAGE ESTATICA
    ===================================================== */
    if (is_front_page() && !is_home()) {

        $tpl = seo_template_get_registered_template('front_page');

        $file = seo_template_resolve_registered_file($tpl, $base_path);
        if ($file !== '') {
            return $file;
        }
    }

    /* =====================================================
       2. INDICE DEL BLOG / PAGINA DE ENTRADAS
    ===================================================== */
    if (is_home()) {

        $tpl = seo_template_get_registered_template('blog_index');

        $file = seo_template_resolve_registered_file($tpl, $base_path);
        return $file !== '' ? $file : $template;
    }

    /* =====================================================
       2B. CARRITO WOOCOMMERCE

       WooCommerce usa una pagina de WordPress para el carrito.
       Esta condicion debe resolverse antes de is_singular('page')
       para que el router consuma la template_key `cart`.
    ===================================================== */
    if (function_exists('is_cart') && is_cart()) {

        $tpl = seo_template_get_registered_template('cart');

        $file = seo_template_resolve_registered_file($tpl, $base_path);
        return $file !== '' ? $file : $template;
    }

    /* =====================================================
       3. CATEGORIAS PRODUCTO
    ===================================================== */
    if (is_tax('product_cat')) {

        $tpl = seo_template_get_registered_template('taxonomy_product_cat');

        $file = seo_template_resolve_registered_file($tpl, $base_path);
        return $file !== '' ? $file : $template;
    }

    /* =====================================================
       4. POSTS INDIVIDUALES
    ===================================================== */
    if (is_singular('post')) {

        $tpl = seo_template_get_registered_template('single_post');

        $file = seo_template_resolve_registered_file($tpl, $base_path);
        return $file !== '' ? $file : $template;
    }

    /* =====================================================
       5. PAGINAS (TODO POR ROLE)
    ===================================================== */
    if (is_singular('page')) {

        $object_id = get_queried_object_id();

        $role = $wpdb->get_var($wpdb->prepare(
            "SELECT seo_role
             FROM {$wpdb->prefix}seo_nodes
             WHERE object_id = %d
             LIMIT 1",
            $object_id
        ));

        if (!$role) {
            return $template;
        }

        $tpl = seo_template_get_registered_template($role);

        $file = seo_template_resolve_registered_file($tpl, $base_path);
        return $file !== '' ? $file : $template;
    }

    /* =====================================================
       6. PRODUCTO INDIVIDUAL WOOCOMMERCE
    ===================================================== */
    if (is_singular('product')) {

        $tpl = seo_template_get_registered_template('single_product');

        $file = seo_template_resolve_registered_file($tpl, $base_path);
        return $file !== '' ? $file : $template;
    }

    return $template;
}
