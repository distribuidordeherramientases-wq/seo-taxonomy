<?php
/**
 * TaxoCommerce SEO - Social Network.
 *
 * Capa comun para conexiones, plantillas, publicaciones, seguimiento de visitas
 * e informes de redes sociales. Los proveedores viven en archivos separados.
 */

defined('ABSPATH') || exit;

/**
 * Lee parámetros GET de navegación, avisos y referencias firmadas.
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function tcsocial_network_query_arg($key, $default = '')
{
    $key = sanitize_key((string) $key);
    if ('' === $key) {
        return $default;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navegación, avisos y referencias firmadas de solo lectura.
    if (!isset($_GET[$key])) {
        return $default;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navegación, avisos y referencias firmadas de solo lectura.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw query value is returned deliberately so each caller can apply the sanitizer appropriate to its expected type.
    return wp_unslash($_GET[$key]);
}


if (!defined('TCSOCIAL_NETWORK_DB_VERSION')) {
    define('TCSOCIAL_NETWORK_DB_VERSION', 2);
}

if (!defined('TCSOCIAL_NETWORK_SETTINGS_OPTION')) {
    define('TCSOCIAL_NETWORK_SETTINGS_OPTION', 'tcsocial_network_settings_v1');
}

if (!defined('TCSOCIAL_NETWORK_DB_VERSION_OPTION')) {
    define('TCSOCIAL_NETWORK_DB_VERSION_OPTION', 'tcsocial_network_db_version');
}

/**
 * Registro de proveedores. Cada conector anade sus callbacks mediante filtro.
 *
 * @return array
 */
function tcsocial_network_get_providers()
{
    $providers = apply_filters('tcsocial_network_providers', array());
    return is_array($providers) ? $providers : array();
}

/**
 * @param string $provider_key
 * @return array|null
 */
function tcsocial_network_get_provider($provider_key)
{
    $providers = tcsocial_network_get_providers();
    return isset($providers[$provider_key]) && is_array($providers[$provider_key])
        ? $providers[$provider_key]
        : null;
}

/**
 * Ajustes iniciales. La publicacion automatica queda apagada por seguridad.
 *
 * @return array
 */
function tcsocial_network_default_settings()
{
    return array(
        'auto_publish' => array(
            'post' => 0,
            'page' => 0,
        ),
        'auto_publish_providers' => array(
            'facebook'  => 1,
            'instagram' => 1,
            'linkedin'  => 1,
            'pinterest' => 1,
            'x'         => 1,
        ),
        'templates' => array(
            'facebook' => array(
                'post'     => "{titulo}\n\n{extracto}\n\n{url}",
                'page'     => "{titulo}\n\n{extracto}\n\n{url}",><strong>Cómo funciona:</strong> ofertas de campaña a las 18:00, Noticias a las 20:00 y el resto de entradas junto con páginas/landings a las 22:00. Las Noticias prioritarias adelantan a las Noticias normales. La cola de las 22:00 alterna entrada y página/landing cuando existen ambos tipos. WordPress ejecuta las tareas con WP-Cron, por lo que la ejecución real puede retrasarse hasta la siguiente petición al sitio.</p>';
    echo '</section>';
}


