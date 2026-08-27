<?php
/**
 * Disponibilidad del proveedor + formulario ligero de aviso de reposicion.
 *
 * Este archivo es autocontenido para poder reutilizar la misma logica en las
 * variantes desktop y mobile de la ficha de producto.
 */

defined('ABSPATH') || exit;

if (!function_exists('dht_supplier_product_stock_state')) {
    /**
     * Devuelve el estado agregado de stock informado por proveedores aceptados.
     *
     * Regla:
     * - Si al menos un proveedor informa explicitamente "in_stock"/"instock",
     *   no se fuerza fuera de stock.
     * - Si no hay ningun "in stock" y al menos un estado empieza por "out",
     *   se considera fuera de stock.
     * - El resto se considera desconocido y no altera WooCommerce.
     */
    function dht_supplier_product_stock_state($product_id)
    {
        static $cache = array();

        $product_id = absint($product_id);
        if ($product_id < 1) {
            return array(
                'state' => 'unknown',
                'is_out_of_stock' => false,
                'raw_states' => array(),
            );
        }

        if (isset($cache[$product_id])) {
            return $cache[$product_id];
        }

        global $wpdb;

        $table = $wpdb->prefix . 'seo_proveedores_productos';
        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;

        if (!$table_exists) {
            $cache[$product_id] = array(
                'state' => 'unknown',
                'is_out_of_stock' => false,
                'raw_states' => array(),
            );
            return $cache[$product_id];
        }

        $raw_states = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT stock_estado
                 FROM {$table}
                 WHERE object_id = %d
                   AND estado_seleccion = 'aceptado'
                   AND stock_estado IS NOT NULL
                   AND TRIM(stock_estado) <> ''",
                $product_id
            )
        );

        $has_in_stock = false;
        $has_out_of_stock = false;
        $clean_states = array();

        foreach ((array) $raw_states as $raw_state) {
            $raw_state = trim((string) $raw_state);
            if ($raw_state === '') {
                continue;
            }

            $clean_states[] = $raw_state;

            $compact = strtolower(remove_accents($raw_state));
            $compact = preg_replace('/[^a-z0-9]+/', '', $compact);

            if ($compact === 'instock') {
                $has_in_stock = true;
                continue;
            }

            if (strpos($compact, 'out') === 0) {
                $has_out_of_stock = true;
            }
        }

        $is_out_of_stock = $has_out_of_stock && !$has_in_stock;

        $cache[$product_id] = array(
            'state' => $is_out_of_stock ? 'out_of_stock' : ($has_in_stock ? 'in_stock' : 'unknown'),
            'is_out_of_stock' => $is_out_of_stock,
            'raw_states' => array_values(array_unique($clean_states)),
        );

        return $cache[$product_id];
    }
}

if (!function_exists('dht_stock_alert_feedback')) {
    function dht_stock_alert_feedback($type = '', $message = '')
    {
        static $feedback = null;

        if ($type !== '' || $message !== '') {
            $feedback = array(
                'type' => (string) $type,
                'message' => (string) $message,
            );
        }

        if ($feedback !== null) {
            return $feedback;
        }

        if (isset($_GET['stock_alert']) && sanitize_key(wp_unslash($_GET['stock_alert'])) === 'sent') {
            return array(
                'type' => 'success',
                'message' => 'Solicitud recibida. Te contactaremos cuando tengamos novedades sobre este producto.',
            );
        }

        return null;
    }
}

if (!function_exists('dht_handle_stock_alert_request')) {
    function dht_handle_stock_alert_request()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['dht_stock_alert_submit'])) {
            return;
        }

        $product_id = isset($_POST['dht_stock_alert_product_id'])
            ? absint($_POST['dht_stock_alert_product_id'])
            : 0;

        $current_product_id = absint(get_queried_object_id());
        $nonce = isset($_POST['dht_stock_alert_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['dht_stock_alert_nonce']))
            : '';

        if ($product_id < 1 || $product_id !== $current_product_id || !wp_verify_nonce($nonce, 'dht_stock_alert_' . $product_id)) {
            dht_stock_alert_feedback('error', 'No hemos podido validar la solicitud. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $supplier_stock = dht_supplier_product_stock_state($product_id);
        if (empty($supplier_stock['is_out_of_stock'])) {
            dht_stock_alert_feedback('error', 'Este producto ya no figura como fuera de stock. Recarga la página para ver su disponibilidad actual.');
            return;
        }

        // Honeypot basico: los usuarios reales dejan este campo vacio.
        $website = isset($_POST['dht_stock_alert_website'])
            ? trim((string) wp_unslash($_POST['dht_stock_alert_website']))
            : '';
        if ($website !== '') {
            return;
        }

        $contact = isset($_POST['dht_stock_alert_contact'])
            ? sanitize_text_field(wp_unslash($_POST['dht_stock_alert_contact']))
            : '';

        $email = is_email($contact);
        $phone_digits = preg_replace('/\D+/', '', $contact);
        $is_phone = !$email && strlen($phone_digits) >= 9 && strlen($phone_digits) <= 15;

        if (!$email && !$is_phone) {
            dht_stock_alert_feedback('error', 'Introduce un correo electrónico válido o un teléfono válido para poder avisarte.');
            return;
        }

        $marketing_consent = !empty($_POST['dht_marketing_consent']);
        $product_name = get_the_title($product_id);
        $product_url = get_permalink($product_id);
        $channel = $email ? 'Correo electrónico' : 'Teléfono / WhatsApp';
        $requested_at = current_time('mysql');

        $marketing_text = 'Quiero recibir además ofertas, novedades y recomendaciones de productos de Distribuidor de Herramientas por email o WhatsApp, según el dato de contacto facilitado. Puedo retirar mi consentimiento en cualquier momento.';

        $subject = sprintf('[Aviso de stock] %s', $product_name ?: ('Producto #' . $product_id));
        $body = implode("\n", array(
            'Nueva solicitud de aviso de disponibilidad.',
            '',
            'Producto: ' . ($product_name ?: ('#' . $product_id)),
            'ID producto: ' . $product_id,
            'URL: ' . $product_url,
            'Contacto: ' . $contact,
            'Canal: ' . $channel,
            'Fecha: ' . $requested_at,
            '',
            'Consentimiento comercial adicional: ' . ($marketing_consent ? 'SI' : 'NO'),
            'Texto mostrado: ' . $marketing_text,
        ));

        $headers = array('Content-Type: text/plain; charset=UTF-8');
        if ($email) {
            $headers[] = 'Reply-To: ' . $email;
        }

        $recipient = 'servicioacliente@distribuidordeherramientas.es';
        $sent = wp_mail($recipient, $subject, $body, $headers);

        /**
         * Permite conectar posteriormente CRM/newsletter sin cambiar la plantilla.
         */
        do_action('dht_stock_alert_submitted', array(
            'product_id' => $product_id,
            'product_name' => $product_name,
            'product_url' => $product_url,
            'contact' => $contact,
            'channel' => $channel,
            'marketing_consent' => $marketing_consent,
            'marketing_consent_text' => $marketing_text,
            'requested_at' => $requested_at,
            'mail_sent' => (bool) $sent,
        ));

        if (!$sent) {
            dht_stock_alert_feedback('error', 'No hemos podido enviar la solicitud. Puedes contactarnos directamente por teléfono o correo.');
            return;
        }

        $redirect_url = add_query_arg('stock_alert', 'sent', $product_url);
        $redirect_url .= '#dh-product-purchase';
        wp_safe_redirect($redirect_url);
        exit;
    }
}

if (!function_exists('dht_render_stock_alert_form')) {
    function dht_render_stock_alert_form($product_id)
    {
        $product_id = absint($product_id);
        $feedback = dht_stock_alert_feedback();
        $privacy_url = function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : '';
        ?>
        <div class="dh-supplier-stock-alert">
          <p class="stock out-of-stock dh-supplier-out-of-stock">Fuera de stock</p>
          <p class="dh-stock-alert-intro">¿Quieres que te avisemos cuando vuelva a estar disponible?</p>

          <?php if (is_array($feedback) && !empty($feedback['message'])) : ?>
            <div class="dh-stock-alert-feedback dh-stock-alert-feedback--<?php echo esc_attr($feedback['type']); ?>" role="status">
              <?php echo esc_html($feedback['message']); ?>
            </div>
          <?php endif; ?>

          <form class="dh-stock-alert-form" method="post" action="<?php echo esc_url(get_permalink($product_id)); ?>">
            <?php wp_nonce_field('dht_stock_alert_' . $product_id, 'dht_stock_alert_nonce'); ?>
            <input type="hidden" name="dht_stock_alert_product_id" value="<?php echo esc_attr($product_id); ?>">

            <div class="dh-stock-alert-honeypot" aria-hidden="true">
              <label>Web <input type="text" name="dht_stock_alert_website" value="" tabindex="-1" autocomplete="off"></label>
            </div>

            <label class="dh-stock-alert-field">
              <span>Correo electrónico o teléfono (WhatsApp)</span>
              <input
                type="text"
                name="dht_stock_alert_contact"
                value=""
                placeholder="tu@email.com o 600 000 000"
                required
                autocomplete="off"
              >
            </label>

            <p class="dh-stock-alert-privacy">
              Usaremos el dato facilitado para avisarte sobre la disponibilidad de este producto o proponerte una alternativa similar relacionada con tu solicitud.
              <?php if ($privacy_url) : ?>
                <a href="<?php echo esc_url($privacy_url); ?>" target="_blank" rel="noopener">Política de privacidad</a>.
              <?php endif; ?>
            </p>

            <label class="dh-stock-alert-consent">
              <input type="checkbox" name="dht_marketing_consent" value="1">
              <span>Quiero recibir además ofertas, novedades y recomendaciones de productos de Distribuidor de Herramientas por email o WhatsApp, según el dato de contacto facilitado. Puedo retirar mi consentimiento en cualquier momento.</span>
            </label>
            <p class="dh-stock-alert-optional">Esta casilla es opcional y no es necesaria para recibir el aviso de stock.</p>

            <button type="submit" name="dht_stock_alert_submit" value="1" class="button alt dh-stock-alert-submit">
              Infórmame cuando haya stock
            </button>
          </form>
        </div>
        <?php
    }
}

dht_handle_stock_alert_request();
