<?php
/**
 * SEO Taxonomy - Gestor propio de costes de transporte.
 *
 * WooCommerce conserva carrito, checkout, pedido e impuestos. Este modulo
 * reemplaza exclusivamente las tarifas de envio por reglas definidas aqui.
 */

defined('ABSPATH') || exit;

if (!class_exists('SEO_Transporte_Costes')) {
    final class SEO_Transporte_Costes {
        const OPTION = 'seo_transporte_costes_settings';
        const PAGE   = 'seo-transporte';

        public static function init() {
            add_action('admin_menu', array(__CLASS__, 'register_page'), 30);
            add_action('admin_post_seo_transporte_save', array(__CLASS__, 'save_settings'));
            add_filter('woocommerce_package_rates', array(__CLASS__, 'filter_package_rates'), 999, 2);
            add_filter('parent_file', array(__CLASS__, 'admin_parent_file'));
            add_filter('submenu_file', array(__CLASS__, 'admin_submenu_file'));
        }

        public static function defaults() {
            return array(
                'enabled'              => 0,
                'only_spain'           => 1,
                'default_region'       => 'peninsula',
                'rate_label'           => 'Transporte',
                'shipping_taxable'     => 1,
                'require_known_metrics'=> 1,
                'rules'                => array(),
            );
        }

        public static function settings() {
            $stored = get_option(self::OPTION, array());
            if (!is_array($stored)) {
                $stored = array();
            }
            $settings = wp_parse_args($stored, self::defaults());
            $settings['rules'] = isset($stored['rules']) && is_array($stored['rules']) ? $stored['rules'] : array();
            return $settings;
        }

        public static function register_page() {
            $capability = class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
            add_submenu_page(
                null,
                'Transporte',
                'Transporte',
                $capability,
                self::PAGE,
                array(__CLASS__, 'render_page')
            );
        }

        private static function is_page() {
            return is_admin() && isset($_GET['page']) && self::PAGE === sanitize_key(wp_unslash($_GET['page']));
        }

        public static function admin_parent_file($parent_file) {
            if (self::is_page()) {
                return 'seo-system';
            }
            return $parent_file;
        }

        public static function admin_submenu_file($submenu_file) {
            if (self::is_page()) {
                return 'seo-tools';
            }
            return $submenu_file;
        }

        private static function capability() {
            return class_exists('WooCommerce') ? 'manage_woocommerce' : 'manage_options';
        }

        private static function decimal($value, $allow_blank = true) {
            if (is_array($value) || is_object($value)) {
                return $allow_blank ? '' : 0.0;
            }
            $value = trim((string) $value);
            if ('' === $value && $allow_blank) {
                return '';
            }
            if (function_exists('wc_format_decimal')) {
                $value = wc_format_decimal($value);
            } else {
                $value = str_replace(',', '.', $value);
                $value = preg_replace('/[^0-9.\-]/', '', $value);
            }
            return max(0, (float) $value);
        }

        private static function sanitize_rule($rule, $index) {
            $zones = array('spain', 'peninsula', 'baleares', 'canarias', 'ceuta', 'melilla', 'foreign', 'all');
            $zone  = isset($rule['zone']) ? sanitize_key($rule['zone']) : 'peninsula';
            if (!in_array($zone, $zones, true)) {
                $zone = 'peninsula';
            }

            return array(
                'id'             => !empty($rule['id']) ? sanitize_key($rule['id']) : 'r' . ($index + 1) . '-' . wp_generate_password(5, false, false),
                'enabled'        => !empty($rule['enabled']) ? 1 : 0,
                'priority'       => isset($rule['priority']) ? max(1, absint($rule['priority'])) : (($index + 1) * 10),
                'name'           => isset($rule['name']) ? sanitize_text_field($rule['name']) : '',
                'zone'           => $zone,
                'min_subtotal'   => self::decimal($rule['min_subtotal'] ?? ''),
                'max_subtotal'   => self::decimal($rule['max_subtotal'] ?? ''),
                'min_weight'     => self::decimal($rule['min_weight'] ?? ''),
                'max_weight'     => self::decimal($rule['max_weight'] ?? ''),
                'max_length'     => self::decimal($rule['max_length'] ?? ''),
                'max_width'      => self::decimal($rule['max_width'] ?? ''),
                'max_height'     => self::decimal($rule['max_height'] ?? ''),
                'max_volume'     => self::decimal($rule['max_volume'] ?? ''),
                'fixed_cost'     => self::decimal($rule['fixed_cost'] ?? 0, false),
                'per_kg_cost'    => self::decimal($rule['per_kg_cost'] ?? 0, false),
                'per_unit_cost'  => self::decimal($rule['per_unit_cost'] ?? 0, false),
                'free_from'      => self::decimal($rule['free_from'] ?? ''),
            );
        }

        public static function save_settings() {
            if (!current_user_can(self::capability())) {
                wp_die(esc_html__('No tienes permisos para modificar el transporte.', 'seo-taxonomy'));
            }
            check_admin_referer('seo_transporte_save');

            $raw = isset($_POST['seo_transporte']) && is_array($_POST['seo_transporte'])
                ? wp_unslash($_POST['seo_transporte'])
                : array();

            $default_regions = array('peninsula', 'baleares', 'canarias', 'ceuta', 'melilla');
            $default_region  = isset($raw['default_region']) ? sanitize_key($raw['default_region']) : 'peninsula';
            if (!in_array($default_region, $default_regions, true)) {
                $default_region = 'peninsula';
            }

            $rules = array();
            foreach ((array) ($raw['rules'] ?? array()) as $index => $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $clean = self::sanitize_rule($rule, $index);
                // Las filas completamente vacias no se guardan.
                $has_content = !empty($clean['name'])
                    || !empty($clean['enabled'])
                    || 0.0 !== (float) $clean['fixed_cost']
                    || 0.0 !== (float) $clean['per_kg_cost']
                    || 0.0 !== (float) $clean['per_unit_cost']
                    || '' !== $clean['free_from']
                    || '' !== $clean['min_subtotal']
                    || '' !== $clean['max_subtotal']
                    || '' !== $clean['min_weight']
                    || '' !== $clean['max_weight']
                    || '' !== $clean['max_length']
                    || '' !== $clean['max_width']
                    || '' !== $clean['max_height']
                    || '' !== $clean['max_volume'];
                if ($has_content) {
                    $rules[] = $clean;
                }
            }

            usort($rules, static function ($a, $b) {
                return ((int) $a['priority']) <=> ((int) $b['priority']);
            });

            $enabled = !empty($raw['enabled']) ? 1 : 0;
            $active_rules = array_filter($rules, static function ($rule) {
                return !empty($rule['enabled']);
            });

            $status = 'saved';
            if ($enabled && empty($active_rules)) {
                $enabled = 0;
                $status  = 'no_rules';
            }

            $settings = array(
                'enabled'               => $enabled,
                'only_spain'            => !empty($raw['only_spain']) ? 1 : 0,
                'default_region'        => $default_region,
                'rate_label'            => !empty($raw['rate_label']) ? sanitize_text_field($raw['rate_label']) : 'Transporte',
                'shipping_taxable'      => !empty($raw['shipping_taxable']) ? 1 : 0,
                'require_known_metrics' => !empty($raw['require_known_metrics']) ? 1 : 0,
                'rules'                 => $rules,
            );

            update_option(self::OPTION, $settings, false);

            // Invalida las tarifas cacheadas de WooCommerce tras cambiar reglas.
            if (class_exists('WC_Cache_Helper') && is_callable(array('WC_Cache_Helper', 'get_transient_version'))) {
                WC_Cache_Helper::get_transient_version('shipping', true);
            }

            wp_safe_redirect(add_query_arg(array('page' => self::PAGE, 'seo_transport_status' => $status), admin_url('admin.php')));
            exit;
        }

        private static function region_labels() {
            return array(
                'spain'     => 'España - cualquier zona',
                'peninsula' => 'Península',
                'baleares'  => 'Islas Baleares',
                'canarias'  => 'Canarias',
                'ceuta'     => 'Ceuta',
                'melilla'   => 'Melilla',
                'foreign'   => 'Fuera de España',
                'all'       => 'Cualquier destino',
            );
        }

        private static function render_decimal_input($name, $value, $placeholder = '') {
            printf(
                '<input type="number" min="0" step="0.01" name="%s" value="%s" placeholder="%s" />',
                esc_attr($name),
                esc_attr((string) $value),
                esc_attr($placeholder)
            );
        }

        private static function blank_rule() {
            return array(
                'id' => '', 'enabled' => 0, 'priority' => 10, 'name' => '', 'zone' => 'peninsula',
                'min_subtotal' => '', 'max_subtotal' => '', 'min_weight' => '', 'max_weight' => '',
                'max_length' => '', 'max_width' => '', 'max_height' => '', 'max_volume' => '',
                'fixed_cost' => 0, 'per_kg_cost' => 0, 'per_unit_cost' => 0, 'free_from' => '',
            );
        }

        private static function render_rule($rule, $index) {
            $rule = wp_parse_args($rule, self::blank_rule());
            $safe_index = ('__INDEX__' === $index) ? '__INDEX__' : (string) absint($index);
            $base = 'seo_transporte[rules][' . $safe_index . ']';
            $zones = self::region_labels();
            ?>
            <section class="seo-transport-rule" data-rule-index="<?php echo esc_attr($safe_index); ?>">
                <input type="hidden" name="<?php echo esc_attr($base . '[id]'); ?>" value="<?php echo esc_attr((string) $rule['id']); ?>" />
                <div class="seo-transport-rule-head">
                    <label><input type="checkbox" name="<?php echo esc_attr($base . '[enabled]'); ?>" value="1" <?php checked(!empty($rule['enabled'])); ?> /> <strong>Regla activa</strong></label>
                    <button type="button" class="button-link-delete seo-transport-remove">Eliminar</button>
                </div>
                <div class="seo-transport-grid seo-transport-grid--identity">
                    <label><span>Prioridad</span><input type="number" min="1" step="1" name="<?php echo esc_attr($base . '[priority]'); ?>" value="<?php echo esc_attr((string) $rule['priority']); ?>" /></label>
                    <label><span>Nombre de la regla</span><input type="text" name="<?php echo esc_attr($base . '[name]'); ?>" value="<?php echo esc_attr((string) $rule['name']); ?>" placeholder="Ej.: Península estándar" /></label>
                    <label><span>Destino</span><select name="<?php echo esc_attr($base . '[zone]'); ?>">
                        <?php foreach ($zones as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($rule['zone'], $key); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select></label>
                </div>

                <h3>Cuándo se aplica</h3>
                <div class="seo-transport-grid">
                    <label><span>Subtotal mínimo (€)</span><?php self::render_decimal_input($base . '[min_subtotal]', $rule['min_subtotal']); ?></label>
                    <label><span>Subtotal máximo (€)</span><?php self::render_decimal_input($base . '[max_subtotal]', $rule['max_subtotal']); ?></label>
                    <label><span>Peso mínimo (kg)</span><?php self::render_decimal_input($base . '[min_weight]', $rule['min_weight']); ?></label>
                    <label><span>Peso máximo (kg)</span><?php self::render_decimal_input($base . '[max_weight]', $rule['max_weight']); ?></label>
                    <label><span>Largo máx. (cm)</span><?php self::render_decimal_input($base . '[max_length]', $rule['max_length']); ?></label>
                    <label><span>Ancho máx. (cm)</span><?php self::render_decimal_input($base . '[max_width]', $rule['max_width']); ?></label>
                    <label><span>Alto máx. (cm)</span><?php self::render_decimal_input($base . '[max_height]', $rule['max_height']); ?></label>
                    <label><span>Volumen total máx. (dm³)</span><?php self::render_decimal_input($base . '[max_volume]', $rule['max_volume']); ?></label>
                </div>

                <h3>Cómo calcula el coste</h3>
                <div class="seo-transport-grid">
                    <label><span>Coste fijo (€)</span><?php self::render_decimal_input($base . '[fixed_cost]', $rule['fixed_cost'], '0'); ?></label>
                    <label><span>+ coste por kg (€)</span><?php self::render_decimal_input($base . '[per_kg_cost]', $rule['per_kg_cost'], '0'); ?></label>
                    <label><span>+ coste por unidad (€)</span><?php self::render_decimal_input($base . '[per_unit_cost]', $rule['per_unit_cost'], '0'); ?></label>
                    <label><span>Gratis desde subtotal (€)</span><?php self::render_decimal_input($base . '[free_from]', $rule['free_from'], 'Opcional'); ?></label>
                </div>
                <p class="description">Coste = fijo + (kg × coste/kg) + (unidades × coste/unidad). Si se alcanza “Gratis desde”, el coste final es 0 €. La primera regla activa que coincida por prioridad es la que se utiliza.</p>
            </section>
            <?php
        }

        public static function render_page() {
            if (!current_user_can(self::capability())) {
                wp_die(esc_html__('No tienes permisos para acceder a esta página.', 'seo-taxonomy'));
            }

            $settings = self::settings();
            $rules = $settings['rules'];
            if (empty($rules)) {
                $rules = array(self::blank_rule());
            }
            $status = isset($_GET['seo_transport_status']) ? sanitize_key(wp_unslash($_GET['seo_transport_status'])) : '';
            ?>
            <div class="wrap seo-transporte-wrap">
                <h1>Logística</h1>
                <nav class="nav-tab-wrapper" aria-label="Logística">
                    <a class="nav-tab" href="<?php echo esc_url(admin_url('admin.php?page=seo-logistica')); ?>">Gestión de pedidos</a>
                    <a class="nav-tab nav-tab-active" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE)); ?>">Transporte</a>
                </nav>

                <?php if ('saved' === $status) : ?>
                    <div class="notice notice-success is-dismissible"><p>Reglas de transporte guardadas. WooCommerce recalculará las tarifas con esta configuración.</p></div>
                <?php elseif ('no_rules' === $status) : ?>
                    <div class="notice notice-warning"><p><strong>El gestor no se ha activado.</strong> Debe existir al menos una regla activa para evitar bloquear el checkout.</p></div>
                <?php endif; ?>

                <p class="seo-transport-intro">Una sola pantalla controla el coste que verá el cliente en carrito y checkout. Cuando el gestor está activo, las tarifas configuradas en WooCommerce se ignoran y este motor devuelve una única tarifa según destino, subtotal, peso y dimensiones.</p>

                <?php if (!class_exists('WooCommerce')) : ?>
                    <div class="notice notice-error inline"><p>WooCommerce debe estar activo para calcular el transporte.</p></div>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="seo_transporte_save" />
                    <?php wp_nonce_field('seo_transporte_save'); ?>

                    <div class="seo-transport-panel">
                        <h2>Configuración general</h2>
                        <div class="seo-transport-settings-grid">
                            <label class="seo-transport-toggle"><input type="checkbox" name="seo_transporte[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?> /> <span><strong>Activar gestor propio de transporte</strong><small>Al activarlo, sustituye las tarifas de WooCommerce por las reglas de esta pantalla.</small></span></label>
                            <label class="seo-transport-toggle"><input type="checkbox" name="seo_transporte[only_spain]" value="1" <?php checked(!empty($settings['only_spain'])); ?> /> <span><strong>Solo España</strong><small>Fuera de España no se ofrecerá tarifa de envío.</small></span></label>
                            <label class="seo-transport-toggle"><input type="checkbox" name="seo_transporte[shipping_taxable]" value="1" <?php checked(!empty($settings['shipping_taxable'])); ?> /> <span><strong>Transporte sujeto a impuestos</strong><small>El impuesto se obtiene de la fiscalidad activa de WooCommerce/Facturación; aquí no se define el porcentaje.</small></span></label>
                            <label class="seo-transport-toggle"><input type="checkbox" name="seo_transporte[require_known_metrics]" value="1" <?php checked(!empty($settings['require_known_metrics'])); ?> /> <span><strong>No usar reglas avanzadas si faltan peso o medidas</strong><small>Evita aplicar por error una tarifa barata a productos sin datos logísticos.</small></span></label>
                        </div>
                        <div class="seo-transport-grid seo-transport-grid--general">
                            <label><span>Nombre que verá el cliente</span><input type="text" name="seo_transporte[rate_label]" value="<?php echo esc_attr((string) $settings['rate_label']); ?>" /></label>
                            <label><span>Zona provisional si aún no indicó provincia</span><select name="seo_transporte[default_region]">
                                <?php foreach (array('peninsula' => 'Península', 'baleares' => 'Baleares', 'canarias' => 'Canarias', 'ceuta' => 'Ceuta', 'melilla' => 'Melilla') as $key => $label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['default_region'], $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select></label>
                        </div>
                    </div>

                    <div class="seo-transport-panel">
                        <div class="seo-transport-title-row">
                            <div><h2>Reglas de coste</h2><p>Ordena mediante “Prioridad”: 10 se evalúa antes que 20. Puedes crear reglas simples o combinar importe, peso y dimensiones.</p></div>
                            <button type="button" class="button button-secondary" id="seo-transport-add-rule">Añadir regla</button>
                        </div>
                        <div id="seo-transport-rules">
                            <?php foreach ($rules as $index => $rule) { self::render_rule($rule, $index); } ?>
                        </div>
                        <template id="seo-transport-rule-template">
                            <?php self::render_rule(self::blank_rule(), '__INDEX__'); ?>
                        </template>
                    </div>

                    <div class="seo-transport-panel seo-transport-help">
                        <h2>Qué datos utiliza</h2>
                        <p><strong>Destino:</strong> Península, Baleares, Canarias, Ceuta o Melilla a partir de país, provincia y código postal del cliente. <strong>Subtotal:</strong> productos después de descuentos y antes de impuestos. <strong>Peso:</strong> suma de pesos. <strong>Largo/Ancho/Alto:</strong> mayor medida individual encontrada. <strong>Volumen:</strong> suma aproximada del volumen de las unidades.</p>
                        <p class="description">Las dimensiones se leen de cada producto WooCommerce y se convierten internamente a cm/kg. No es un algoritmo de embalaje: es un motor de reglas predecible para tarifar. Más adelante puede sustituirse la tarifa calculada por una API de transportista sin cambiar carrito, checkout ni documentos.</p>
                    </div>

                    <?php submit_button('Guardar transporte'); ?>
                </form>
            </div>
            <style>
                .seo-transporte-wrap .nav-tab-wrapper{margin-bottom:16px}.seo-transport-intro{max-width:1100px;font-size:14px}.seo-transport-panel{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:18px 20px;margin:18px 0;max-width:1180px}.seo-transport-panel h2{margin-top:0}.seo-transport-settings-grid{display:grid;grid-template-columns:repeat(2,minmax(280px,1fr));gap:10px 18px}.seo-transport-toggle{display:flex;gap:9px;align-items:flex-start;padding:10px;border:1px solid #e2e4e7;border-radius:4px}.seo-transport-toggle input{margin-top:3px}.seo-transport-toggle small{display:block;color:#646970;margin-top:3px}.seo-transport-grid{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px 14px}.seo-transport-grid--identity{grid-template-columns:120px 2fr 1fr}.seo-transport-grid--general{grid-template-columns:1fr 1fr;margin-top:16px}.seo-transport-grid label>span{display:block;font-weight:600;margin-bottom:4px}.seo-transport-grid input,.seo-transport-grid select{width:100%}.seo-transport-rule{border:1px solid #c3c4c7;border-left:4px solid #4f73c9;border-radius:4px;padding:16px;margin:14px 0;background:#fcfcfc}.seo-transport-rule-head,.seo-transport-title-row{display:flex;justify-content:space-between;gap:16px;align-items:center}.seo-transport-rule h3{margin:18px 0 8px;font-size:14px}.seo-transport-title-row p{margin:4px 0 0}.seo-transport-help p{max-width:1050px}.seo-transport-remove{cursor:pointer}@media(max-width:900px){.seo-transport-settings-grid,.seo-transport-grid,.seo-transport-grid--identity,.seo-transport-grid--general{grid-template-columns:1fr 1fr}}@media(max-width:600px){.seo-transport-settings-grid,.seo-transport-grid,.seo-transport-grid--identity,.seo-transport-grid--general{grid-template-columns:1fr}}
            </style>
            <script>
            (function(){
                var wrap=document.getElementById('seo-transport-rules');
                var add=document.getElementById('seo-transport-add-rule');
                var tpl=document.getElementById('seo-transport-rule-template');
                if(!wrap||!add||!tpl){return;}
                function bindRemove(root){
                    (root||document).querySelectorAll('.seo-transport-remove').forEach(function(btn){
                        if(btn.dataset.bound){return;} btn.dataset.bound='1';
                        btn.addEventListener('click',function(){var rule=btn.closest('.seo-transport-rule');if(rule){rule.remove();}});
                    });
                }
                bindRemove(document);
                add.addEventListener('click',function(){
                    var index=Date.now();
                    var html=tpl.innerHTML.replace(/__INDEX__/g,String(index));
                    var box=document.createElement('div'); box.innerHTML=html;
                    while(box.firstElementChild){wrap.appendChild(box.firstElementChild);}
                    bindRemove(wrap);
                });
            }());
            </script>
            <?php
        }

        private static function classify_destination($destination, $settings) {
            $country  = strtoupper(trim((string) ($destination['country'] ?? '')));
            $state    = strtoupper(trim((string) ($destination['state'] ?? '')));
            $postcode = preg_replace('/\s+/', '', strtoupper(trim((string) ($destination['postcode'] ?? ''))));

            if (class_exists('SEO_Facturas_Tax') && is_callable(array('SEO_Facturas_Tax', 'classify_location'))) {
                $tax_region = SEO_Facturas_Tax::classify_location($country ?: 'ES', $state, $postcode);
                if ('other' !== $tax_region) {
                    if ('' === $state && '' === $postcode) {
                        return $settings['default_region'];
                    }
                    return $tax_region;
                }
                return 'foreign';
            }

            if ('' !== $country && 'ES' !== $country) {
                return 'foreign';
            }

            if ('PM' === $state || 0 === strpos($postcode, '07')) {
                return 'baleares';
            }
            if (in_array($state, array('GC', 'TF'), true) || 0 === strpos($postcode, '35') || 0 === strpos($postcode, '38')) {
                return 'canarias';
            }
            if ('CE' === $state || 0 === strpos($postcode, '51')) {
                return 'ceuta';
            }
            if ('ML' === $state || 0 === strpos($postcode, '52')) {
                return 'melilla';
            }
            if ('' === $state && '' === $postcode) {
                return $settings['default_region'];
            }
            return 'peninsula';
        }

        private static function package_metrics($package) {
            $subtotal = isset($package['contents_cost']) ? max(0, (float) $package['contents_cost']) : 0.0;
            $weight = 0.0;
            $units = 0.0;
            $max_length = 0.0;
            $max_width = 0.0;
            $max_height = 0.0;
            $volume_cm3 = 0.0;
            $missing_weight = false;
            $missing_dimensions = false;

            foreach ((array) ($package['contents'] ?? array()) as $item) {
                $product = $item['data'] ?? null;
                $qty = max(0, (float) ($item['quantity'] ?? 0));
                if (!$product || !is_object($product) || $qty <= 0) {
                    continue;
                }
                $units += $qty;

                $raw_weight = is_callable(array($product, 'get_weight')) ? (string) $product->get_weight() : '';
                if ('' === $raw_weight) {
                    $missing_weight = true;
                } else {
                    $kg = function_exists('wc_get_weight') ? (float) wc_get_weight((float) $raw_weight, 'kg') : (float) $raw_weight;
                    $weight += max(0, $kg) * $qty;
                }

                $raw_l = is_callable(array($product, 'get_length')) ? (string) $product->get_length() : '';
                $raw_w = is_callable(array($product, 'get_width')) ? (string) $product->get_width() : '';
                $raw_h = is_callable(array($product, 'get_height')) ? (string) $product->get_height() : '';

                if ('' === $raw_l || '' === $raw_w || '' === $raw_h) {
                    $missing_dimensions = true;
                    continue;
                }

                $l = function_exists('wc_get_dimension') ? (float) wc_get_dimension((float) $raw_l, 'cm') : (float) $raw_l;
                $w = function_exists('wc_get_dimension') ? (float) wc_get_dimension((float) $raw_w, 'cm') : (float) $raw_w;
                $h = function_exists('wc_get_dimension') ? (float) wc_get_dimension((float) $raw_h, 'cm') : (float) $raw_h;
                $l = max(0, $l); $w = max(0, $w); $h = max(0, $h);
                $max_length = max($max_length, $l);
                $max_width  = max($max_width, $w);
                $max_height = max($max_height, $h);
                $volume_cm3 += ($l * $w * $h * $qty);
            }

            return array(
                'subtotal'           => $subtotal,
                'weight'             => $weight,
                'units'              => $units,
                'max_length'         => $max_length,
                'max_width'          => $max_width,
                'max_height'         => $max_height,
                'volume_dm3'         => $volume_cm3 / 1000,
                'missing_weight'     => $missing_weight,
                'missing_dimensions' => $missing_dimensions,
            );
        }

        private static function rule_zone_matches($zone, $region) {
            if ('all' === $zone) {
                return true;
            }
            if ('spain' === $zone) {
                return 'foreign' !== $region;
            }
            return $zone === $region;
        }

        private static function number_set($value) {
            return '' !== $value && null !== $value;
        }

        private static function rule_matches($rule, $region, $m, $settings) {
            if (empty($rule['enabled']) || !self::rule_zone_matches($rule['zone'], $region)) {
                return false;
            }

            if (self::number_set($rule['min_subtotal']) && $m['subtotal'] < (float) $rule['min_subtotal']) return false;
            if (self::number_set($rule['max_subtotal']) && $m['subtotal'] > (float) $rule['max_subtotal']) return false;

            $uses_weight = self::number_set($rule['min_weight']) || self::number_set($rule['max_weight']) || (float) $rule['per_kg_cost'] > 0;
            $uses_dimensions = self::number_set($rule['max_length']) || self::number_set($rule['max_width']) || self::number_set($rule['max_height']) || self::number_set($rule['max_volume']);

            if (!empty($settings['require_known_metrics'])) {
                if ($uses_weight && $m['missing_weight']) return false;
                if ($uses_dimensions && $m['missing_dimensions']) return false;
            }

            if (self::number_set($rule['min_weight']) && $m['weight'] < (float) $rule['min_weight']) return false;
            if (self::number_set($rule['max_weight']) && $m['weight'] > (float) $rule['max_weight']) return false;
            if (self::number_set($rule['max_length']) && $m['max_length'] > (float) $rule['max_length']) return false;
            if (self::number_set($rule['max_width']) && $m['max_width'] > (float) $rule['max_width']) return false;
            if (self::number_set($rule['max_height']) && $m['max_height'] > (float) $rule['max_height']) return false;
            if (self::number_set($rule['max_volume']) && $m['volume_dm3'] > (float) $rule['max_volume']) return false;

            return true;
        }

        private static function calculate_rule_cost($rule, $m) {
            if (self::number_set($rule['free_from']) && $m['subtotal'] >= (float) $rule['free_from']) {
                return 0.0;
            }
            $cost  = (float) $rule['fixed_cost'];
            $cost += (float) $rule['per_kg_cost'] * $m['weight'];
            $cost += (float) $rule['per_unit_cost'] * $m['units'];
            return max(0, round($cost, function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2));
        }

        private static function rate_taxes($cost, $settings) {
            if (empty($settings['shipping_taxable']) || $cost <= 0 || !class_exists('WC_Tax')) {
                return array();
            }
            $customer = function_exists('WC') && WC() ? WC()->customer : null;
            $rates = WC_Tax::get_shipping_tax_rates(null, $customer);
            if (empty($rates)) {
                return array();
            }
            return WC_Tax::calc_shipping_tax($cost, $rates);
        }

        public static function filter_package_rates($rates, $package) {
            $settings = self::settings();
            if (empty($settings['enabled']) || !class_exists('WC_Shipping_Rate')) {
                return $rates;
            }

            $destination = isset($package['destination']) && is_array($package['destination']) ? $package['destination'] : array();
            $region = self::classify_destination($destination, $settings);

            if (!empty($settings['only_spain']) && 'foreign' === $region) {
                return array();
            }

            $metrics = self::package_metrics($package);
            $rules = array_values(array_filter((array) $settings['rules'], static function ($rule) {
                return is_array($rule) && !empty($rule['enabled']);
            }));
            usort($rules, static function ($a, $b) {
                return ((int) ($a['priority'] ?? 9999)) <=> ((int) ($b['priority'] ?? 9999));
            });

            foreach ($rules as $rule) {
                $rule = wp_parse_args($rule, self::blank_rule());
                if (!self::rule_matches($rule, $region, $metrics, $settings)) {
                    continue;
                }

                $cost = self::calculate_rule_cost($rule, $metrics);
                $label = trim((string) $settings['rate_label']);
                if ('' === $label) {
                    $label = 'Transporte';
                }
                $taxes = self::rate_taxes($cost, $settings);
                $rate_id = 'seo_transporte:' . sanitize_key((string) $rule['id']);
                $rate = new WC_Shipping_Rate($rate_id, $label, $cost, $taxes, 'seo_transporte');

                /**
                 * Permite enriquecer o sustituir la tarifa final sin duplicar el motor.
                 */
                $rate = apply_filters('seo_transporte_shipping_rate', $rate, $rule, $metrics, $region, $package);
                return $rate instanceof WC_Shipping_Rate ? array($rate_id => $rate) : array();
            }

            // Sin coincidencia no inventamos un coste: WooCommerce bloqueara el envio hasta que exista una regla valida.
            return array();
        }
    }

    SEO_Transporte_Costes::init();
}
