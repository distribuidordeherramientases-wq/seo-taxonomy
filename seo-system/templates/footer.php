<?php
/**
 * Pie público compartido por las plantillas del plugin.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/template-helpers.php';

$site_name    = get_bloginfo('name');
$shop_url     = dht_template_shop_url();
$contact_url  = dht_template_contact_url();
$privacy_url  = get_privacy_policy_url();
$logo_url     = 'https://www.distribuidordeherramientas.es/wp-content/uploads/2026/01/Logo2.webp';
$phone_label  = '+34 640 87 45 40';
$phone_href   = 'tel:+34640874540';
$email        = 'servicioacliente@distribuidordeherramientas.es';
$official_host = 'www.distribuidordeherramientas.es';

$resolve_page = static function (array $slugs, $fallback = '') {
    foreach ($slugs as $slug) {
        $page = get_page_by_path($slug);
        if ($page instanceof WP_Post) {
            $url = get_permalink($page);
            if ($url) {
                return $url;
            }
        }
    }

    return $fallback !== '' ? home_url('/' . trim($fallback, '/') . '/') : '';
};

$corporate_links = array(
    'Nosotros'              => $resolve_page(array('nosotros'), 'nosotros'),
    'Nuestro servicio'      => $resolve_page(array('nuestro-servicio'), 'nuestro-servicio'),
    'Proveedores asociados' => $resolve_page(array('proveedores-asociados', 'proveedores'), 'proveedores-asociados'),
    'Contacto'              => $contact_url,
);

$service_links = array(
    'Devoluciones y reembolsos' => $resolve_page(array('devoluciones-y-reembolsos', 'devoluciones'), 'devoluciones-y-reembolsos'),
    'Términos y condiciones'     => $resolve_page(array('terminos-y-condiciones'), 'terminos-y-condiciones'),
    'Privacidad de datos'        => $privacy_url ?: $resolve_page(array('privacidad-de-datos', 'politica-de-privacidad'), 'privacidad-de-datos'),
    'Tienda'                     => $shop_url,
);
?>
<footer class="site-footer" role="contentinfo">
    <div class="footer-container">
        <section class="footer-brand" aria-label="Información de Distribuidor de Herramientas">
            <a class="footer-logo" href="<?php echo esc_url(home_url('/')); ?>" rel="home" aria-label="<?php echo esc_attr($site_name); ?>">
                <img
                    src="<?php echo esc_url($logo_url); ?>"
                    alt="<?php echo esc_attr($site_name); ?>"
                    width="170"
                    height="85"
                    loading="lazy"
                    decoding="async"
                >
            </a>

            <strong><?php echo esc_html($site_name); ?></strong>
            <p class="footer-brand-copy">Herramientas, maquinaria y equipamiento técnico para particulares, profesionales y empresas en España.</p>

            <div class="footer-contact" aria-label="Datos de contacto">
                <a href="<?php echo esc_url($phone_href); ?>">☎ <?php echo esc_html($phone_label); ?></a>
                <a href="mailto:<?php echo esc_attr($email); ?>">✉ <?php echo esc_html($email); ?></a>
                <span class="footer-domain">● <?php echo esc_html($official_host); ?></span>
            </div>
        </section>

        <nav class="footer-column" aria-label="Empresa">
            <h2>Empresa</h2>
            <?php foreach ($corporate_links as $label => $url) : ?>
                <?php if ($url) : ?>
                    <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <nav class="footer-column" aria-label="Atención y condiciones">
            <h2>Atención y condiciones</h2>
            <?php foreach ($service_links as $label => $url) : ?>
                <?php if ($url) : ?>
                    <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <section class="footer-column footer-coverage" aria-label="Cobertura y dominio oficial">
            <h2>Servicio en España</h2>
            <p>Atendemos pedidos y consultas en <strong>España peninsular, Islas Baleares e Islas Canarias</strong>, de acuerdo con las condiciones de envío aplicables a cada producto y proveedor.</p>

            <div class="footer-official-domain">
                <span class="footer-official-label">Dominio oficial</span>
                <strong><?php echo esc_html($official_host); ?></strong>
                <small>Sitio independiente de distribuidordeherramientas.com.</small>
            </div>
        </section>

        <div class="footer-meta">
            <span>© <?php echo esc_html(wp_date('Y')); ?> <?php echo esc_html($site_name); ?></span>
            <span>Distribución y atención especializada en España, Baleares y Canarias.</span>
        </div>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
